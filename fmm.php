<?php
declare(strict_types=1);
session_start();

/* ==========================
   AUTH 
========================== */
if (!isset($_SESSION['id'])) {
    // exit('Not authorized');
}

/* ==========================
   CONFIG
========================== */
define('BASE_DIR', realpath(__DIR__)); // sandbox root 
// define('BASE_DIR', realpath(dirname(__DIR__))); // sandbox root of upper level
define('MAX_SEARCH_FILE_SIZE', 1024 * 1024 * 10); // 10 MB
define('MAX_SEARCH_RESULTS', 200);


/* ==========================
   HELPERS
========================== */

/**
 * Возвращает абсолютный безопасный путь
 * или выбрасывает исключение
 */
function safe_path(string $relative = '.'): string
{
    $relative = trim($relative);
    if ($relative === '') {
        $relative = '.';
    }

    $path = realpath(BASE_DIR . '/' . $relative);

    if ($path === false) {
        throw new RuntimeException('Path not found');
    }

    if (strpos($path, BASE_DIR) !== 0) {
        throw new RuntimeException('Access denied');
    }

    return $path;
}

function safe_new_path(string $relative): string
{
    $relative = trim($relative, '/');

    $parent = dirname($relative);
    $name   = basename($relative);

    $parentAbs = safe_path($parent === '.' ? '.' : $parent);

    return $parentAbs . DIRECTORY_SEPARATOR . $name;
}


/**
 * Абсолютный → относительный путь
 */
function rel_path(string $abs): string
{
    return ltrim(str_replace(BASE_DIR, '', $abs), DIRECTORY_SEPARATOR);
}

/**
 * Breadcrumbs (хлебные крошки)
 */
function breadcrumbs(string $relDir): array
{
    if ($relDir === '') {
        return [];
    }

    $parts = explode('/', trim($relDir, '/'));
    $path  = '';

    $crumbs = [];

    foreach ($parts as $part) {
        $path .= ($path === '' ? '' : '/') . $part;
        $crumbs[] = [
            'name' => $part,
            'path' => $path
        ];
    }

    return $crumbs;
}

/**
 * Запрет опасных имён (
  * ..
  * / \
  * : * ? " < > |
  * пустые имена
  * имена с управляющими символами
  *)
 */
function validate_name(string $name): void
{
    if ($name === '' || $name === '.' || $name === '..') {
        throw new RuntimeException('Invalid name');
    }

    // запрещённые символы (Windows + traversal)
    if (preg_match('/[\/\\\\:*?"<>|]/', $name)) {
        throw new RuntimeException('Invalid characters in name');
    }

    // управляющие символы
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
        throw new RuntimeException('Invalid control characters');
    }
}

function is_text_file(string $path): bool
{
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return false;
    }

    $chunk = fread($fh, 512);
    fclose($fh);

    // бинарный файл содержит \0
    return strpos($chunk, "\0") === false;
}


/* ==========================
   FILE MANAGER
========================== */
final class FileManager
{
    public function list(string $dir): array
    {
        $path = safe_path($dir);

        $items = array_diff(scandir($path), ['.', '..']);

        usort($items, function ($a, $b) use ($path) {
            return is_dir("$path/$b") <=> is_dir("$path/$a")
                ?: strnatcasecmp($a, $b);
        });

        return $items;
    }

    public function read(string $file): string
    {
        $path = safe_path($file);
        if (is_dir($path)) {
            throw new RuntimeException('Is directory');
        }
        return htmlspecialchars(file_get_contents($path));
    }

    public function save(string $file, string $content): void
    {
        $path = safe_path($file);
        if (is_dir($path)) {
            throw new RuntimeException('Is directory');
        }
        file_put_contents($path, $content);
    }

    public function delete(string $file): void
    {
        $path = safe_path($file);

        if (is_dir($path)) {
            $this->deleteDir($path);
        } else {
            unlink($path);
        }
    }

    private function deleteDir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = "$dir/$item";
            is_dir($p) ? $this->deleteDir($p) : unlink($p);
        }
        rmdir($dir);
    }

    public function createFile(string $dir, string $name): void
    {
		validate_name($name);
        $path = safe_new_path($dir . '/' . $name);
        if (file_exists($path)) {
            throw new RuntimeException('File exists');
        }
        file_put_contents($path, '');
    }

    public function createDir(string $dir, string $name): void
    {
		validate_name($name);
        $path = safe_new_path($dir . '/' . $name);
        if (!mkdir($path, 0777, true)) {
            throw new RuntimeException('Cannot create directory');
        }
    }
	
	public function rename(string $oldRelative, string $newName): void
	{
		validate_name($newName);
		// существующий объект
		$oldPath = safe_path($oldRelative);

		if ($newName === '' || $newName === '.' || $newName === '..') {
			throw new RuntimeException('Invalid name');
		}

		// родитель остаётся тем же
		$parentRel = dirname($oldRelative);
		$newPath   = safe_new_path($parentRel . '/' . $newName);

		if (file_exists($newPath)) {
			throw new RuntimeException('Target already exists');
		}

		rename($oldPath, $newPath);
	}
	
	
	public function searchRecursive(string $dir, string $query): array
	{
		$base = safe_path($dir);
		$query = mb_strtolower($query);

		$result = [];

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$base,
				FilesystemIterator::SKIP_DOTS
				| FilesystemIterator::CURRENT_AS_FILEINFO
				| FilesystemIterator::KEY_AS_PATHNAME
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $path => $info) {

			// защита от symlink
			if ($info->isLink()) {
				continue;
			}

			if (mb_strpos(mb_strtolower($info->getFilename()), $query) !== false) {
				$result[] = rel_path($path);
			}
		}

		return $result;
	}
	
	public function searchContent(string $dir, string $query): array
	{
		$base  = safe_path($dir);
		$query = mb_strtolower($query);

		$results = [];
		$count   = 0;

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$base,
				FilesystemIterator::SKIP_DOTS
			)
		);

		foreach ($it as $path => $info) {

			if ($count >= MAX_SEARCH_RESULTS) {
				break;
			}

			// только обычные файлы
			if (!$info->isFile() || $info->isLink()) {
				continue;
			}

			// ограничение размера
			if ($info->getSize() > MAX_SEARCH_FILE_SIZE) {
				continue;
			}

			// текстовый?
			if (!is_text_file($path)) {
				continue;
			}

			// построчное чтение (экономит память)
			$fh = fopen($path, 'r');
			if (!$fh) {
				continue;
			}

			$lineNo = 0;
			while (($line = fgets($fh)) !== false) {
				$lineNo++;

				if (mb_stripos($line, $query) !== false) {
					$results[] = [
						'file' => rel_path($path),
						'line' => $lineNo,
						'text' => trim($line)
					];
					$count++;
					break; // одно совпадение на файл
				}
			}

			fclose($fh);
		}

		return $results;
	}

}

/* ==========================
   ROUTING
========================== */
$fm = new FileManager();

try {
    if (isset($_GET['dir'])) {
        $currentDir = safe_path($_GET['dir']);
    } elseif (isset($_GET['open'])) {
        $currentDir = safe_path(dirname($_GET['open']));
    } else {
        $currentDir = BASE_DIR;
    }
} catch (Throwable $e) {
    $currentDir = BASE_DIR;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save'])) {
        $fm->save($_POST['file'], $_POST['content']);
        header('Location: ?dir=' . urlencode(dirname($_POST['file'])));
        exit;
    }

    if (isset($_POST['new_file'])) {
        $fm->createFile($_POST['dir'], $_POST['name']);
        header('Location: ?dir=' . urlencode($_POST['dir']));
        exit;
    }

    if (isset($_POST['new_dir'])) {
        $fm->createDir($_POST['dir'], $_POST['name']);
        header('Location: ?dir=' . urlencode($_POST['dir']));
        exit;
    }
	
	if (isset($_POST['rename'])) {
		$fm->rename($_POST['old'], $_POST['new']);
		header('Location: ?dir=' . urlencode(dirname($_POST['old'])));
		exit;
	}
}

if (isset($_GET['delete'])) {
    $fm->delete($_GET['delete']);
    header('Location: ?dir=' . urlencode(dirname($_GET['delete'])));
    exit;
}



/* ==========================
   VIEW
========================== */
$relDir = rel_path($currentDir);
$items  = $fm->list($relDir);
$openedFile = $_GET['open'] ?? null;

$searchQuery = $_GET['search'] ?? '';
$searchMode  = $_GET['mode'] ?? 'name';

$searchResults    = [];
$contentResults = [];

if ($searchQuery !== '') {
    if ($searchMode === 'content') {
        $contentResults = $fm->searchContent($relDir, $searchQuery);
    } else {
        $searchResults = $fm->searchRecursive($relDir, $searchQuery);
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>File Manager</title>

<style>
body { font-family: sans-serif; background:#111; color:#eee }
a { color:#6cf; text-decoration:none }
table { width:100% }
td { padding:6px }
tr.active { background:#223a4a; }
.rawcontentresult{color:#d75;}
</style>
</head>
<body>

<h2>Directory: /<?= htmlspecialchars($relDir ?: '') ?></h2>

<nav style="margin-bottom:10px">
    <a href="?dir=">🏠 Home</a>
    <?php foreach (breadcrumbs($relDir) as $b): ?>
        / <a href="?dir=<?= urlencode($b['path']) ?>">
            <?= htmlspecialchars($b['name']) ?>
          </a>
    <?php endforeach; ?>
	<?php 	
	if ($openedFile) {
		echo ' / <strong>' . htmlspecialchars(basename($openedFile)) . '</strong>';
	}
	?>
</nav>
<hr />

<?php
$parent = dirname($currentDir);
if (strpos($parent, BASE_DIR) === 0 && $parent !== $currentDir):
?>
<a href="?dir=<?= urlencode(rel_path($parent)) ?>">⬆️ Up</a>
<?php endif; ?>


<form method="get" style="margin:10px 0">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($relDir) ?>">

    <input type="text" name="search"
           placeholder="Search…"
           value="<?= htmlspecialchars($searchQuery) ?>">

    <select name="mode">
        <option value="name" <?= $searchMode === 'name' ? 'selected' : '' ?>>
            By name
        </option>
        <option value="content" <?= $searchMode === 'content' ? 'selected' : '' ?>>
            By content
        </option>
    </select>

    <button>🔍</button>

    <?php if ($searchQuery !== ''): ?>
        <a href="?dir=<?= urlencode($relDir) ?>">✖ reset</a>
    <?php endif; ?>
</form>

<?php if ($searchQuery !== '' && $searchMode === 'content'): ?>
<h3>Content search results</h3>

<?php if (empty($contentResults)): ?>
    <p>No matches found</p>
<?php else: ?>
    <ul>
    <?php foreach ($contentResults as $r): ?>
        <li>
            📄
            <a href="?open=<?= urlencode($r['file']) ?>">
                <?= htmlspecialchars($r['file']) ?>
            </a>
            <small>(line <?= $r['line'] ?>)</small>
            <br>
            <code class="rawcontentresult"><?= htmlspecialchars($r['text']) ?></code>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

<hr>
<?php endif; ?>


<?php if ($searchQuery !== '' && $searchMode === 'name'): ?>
    <h3>Search results for "<?= htmlspecialchars($searchQuery) ?>"</h3>

    <?php if (empty($searchResults)): ?>
        <p>No matches found</p>
    <?php else: ?>
        <ul>
        <?php foreach ($searchResults as $found): ?>
            <li>
                <?php
                    $abs = safe_path($found);
                    $isDir = is_dir($abs);
                ?>
                <?= $isDir ? '📁' : '📄' ?>
                <a href="?<?= $isDir ? 'dir' : 'open' ?>=<?= urlencode($found) ?>">
                    <?= htmlspecialchars($found) ?>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <hr>
<?php endif; ?>



<table>
<?php foreach ($items as $item):
    $abs = "$currentDir/$item";
    $rel = rel_path($abs);
	$isActive = ($openedFile !== null && $openedFile === $rel);
?>
<tr class="<?= $isActive ? 'active' : '' ?>">
	<td>
	<?php if (is_dir($abs)): ?>
	📁 <a href="?dir=<?= urlencode($rel) ?>"><?= htmlspecialchars($item) ?></a>
	<?php else: ?>
	📄 <a href="?open=<?= urlencode($rel) ?>"><?= htmlspecialchars($item) ?></a>
	<?php endif; ?>
	</td>

	<td>
	<a href="?delete=<?= urlencode($rel) ?>" onclick="return confirm('Delete?')">❌</a>

	<form method="post" style="display:inline">
		<input type="hidden" name="old" value="<?= htmlspecialchars($rel) ?>">
		<input type="text" name="new" value="<?= htmlspecialchars($item) ?>" size="10">
		<button name="rename">✏️</button>
	</form>
	</td>
</tr>

<?php endforeach; ?>
</table>

<hr />

<form method="post">
<input type="hidden" name="dir" value="<?= htmlspecialchars($relDir) ?>">
<input name="name" placeholder="New file">
<button name="new_file">Create file</button>
</form>

<form method="post">
<input type="hidden" name="dir" value="<?= htmlspecialchars($relDir) ?>">
<input name="name" placeholder="New folder">
<button name="new_dir">Create folder</button>
</form>

<?php if (isset($_GET['open'])):
    $file = $_GET['open'];
?>
<hr>
<form method="post">
<input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
<textarea name="content" style="width:100%;height:300px"><?= $fm->read($file) ?></textarea>
<button name="save">Save</button>
</form>
<?php endif; ?>

</body>
</html>

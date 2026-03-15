<?php
declare(strict_types=1);
session_start();


/* ==========================
   AUTH 
========================== */
if (!isset($_SESSION['id'])) {
	require_once 'auth.php';
	exit('<center>Not authorized!</center>');
}

/* ==========================
   CONFIG
========================== */
define('BASE_DIR', realpath(__DIR__)); // sandbox root or parent folder: define('BASE_DIR', realpath(dirname(__DIR__))); 
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

// функция определения типа файла 
function file_type(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $images = ['jpg','jpeg','png','gif','webp','bmp','svg'];
    $audio  = ['mp3','wav','ogg','m4a'];
    $video  = ['mp4','webm','ogg','mov'];

    if (in_array($ext, $images)) return 'image';
    if (in_array($ext, $audio))  return 'audio';
    if (in_array($ext, $video))  return 'video';

    return 'text';
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
		
		if ($_POST['old'] !== $_POST['new']) $fm->rename($_POST['old'], $_POST['new']);
		header('Location: ?dir=' . urlencode(dirname($_POST['old'])));
		exit;
	}
	
	
	
	if (isset($_FILES['upload'])) {

		$dir = $_POST['dir'] ?? '';
		$targetDir = safe_path($dir);

		$name = basename($_FILES['upload']['name']);
		validate_name($name);

		$target = $targetDir . DIRECTORY_SEPARATOR . $name;

		if (!move_uploaded_file($_FILES['upload']['tmp_name'], $target)) {
			http_response_code(500);
			echo "Upload failed";
			exit;
		}

		echo "OK";
		exit;
	}
}

if (isset($_GET['delete'])) {
    $fm->delete($_GET['delete']);
    header('Location: ?dir=' . urlencode(dirname($_GET['delete'])));
    exit;
}

if (isset($_GET['view'])) {
    $path = safe_path($_GET['view']);

    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $mime = mime_content_type($path);
    header("Content-Type: $mime");
    header("Content-Length: " . filesize($path));
    readfile($path);
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

#dropzone{
border:2px dashed #555;
padding:30px;
text-align:center;
margin-top:20px;
cursor:pointer;
}
#dropzone.drag{
border-color:#6cf;
background:#1a2a35;
}
.preview{
width:40px;
height:40px;
object-fit:cover;
}
.progress{
width:100%;
background:#222;
}
.progress-bar{
height:8px;
width:0%;
background:#6cf;
}
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
    $abs  = safe_path($file);
    $type = file_type($abs);
?>
<hr>

<?php if ($type === 'image'): ?>

<img src="?view=<?= urlencode($file) ?>" style="max-width:100%">

<?php elseif ($type === 'audio'): ?>

<audio controls style="width:100%">
    <source src="?view=<?= urlencode($file) ?>">
</audio>

<?php elseif ($type === 'video'): ?>

<video controls style="max-width:100%">
    <source src="?view=<?= urlencode($file) ?>">
</video>

<?php endif; ?>


<hr>

<?php if ($type === 'text'): ?>
<form method="post">
<input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
<textarea name="content" style="width:100%;height:300px"><?= $fm->read($file) ?></textarea>
<button name="save">Save</button>
</form>
<?php endif; ?>


<?php endif; ?>


<hr>
<div id="dropzone">
    📂 Drag files or folders here or click to select
    <input type="file" id="fileInput" multiple off_webkitdirectory style="display:none" />
</div>

<div id="uploadPanel" style="display:none">

    <table id="uploadTable" style="width:100%">
        <thead>
            <tr>
                <th>Preview</th>
                <th>Name</th>
                <th>Progress</th>
                <th></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <button id="uploadBtn">⬆ Upload files</button>

</div>

<script>
const dropzone = document.getElementById("dropzone");
const fileInput = document.getElementById("fileInput");
const uploadPanel = document.getElementById("uploadPanel");
const uploadBtn = document.getElementById("uploadBtn");
const tableBody = document.querySelector("#uploadTable tbody");

let queue = [];

/* click select */
dropzone.addEventListener("click",()=>fileInput.click());

fileInput.addEventListener("change",e=>{
    addFiles([...e.target.files]);
});


/* drag highlight */
["dragenter","dragover"].forEach(ev=>{
    dropzone.addEventListener(ev,e=>{
        e.preventDefault();
        dropzone.classList.add("drag");
    });
});

["dragleave","drop"].forEach(ev=>{
    dropzone.addEventListener(ev,e=>{
        e.preventDefault();
        dropzone.classList.remove("drag");
    });
});


/* drop */
dropzone.addEventListener("drop",e=>{
    addFiles([...e.dataTransfer.files]);
});


/* add files to queue */
function addFiles(files){

    uploadPanel.style.display="block";

    files.forEach(file=>{

        const id = Math.random().toString(36).substr(2,9);

        queue.push({id,file});

        const row = document.createElement("tr");
        row.dataset.id=id;

        let preview="📄";

        if(file.type.startsWith("image")){
            preview=`<img class="preview">`;
        }

        row.innerHTML=`
        <td>${preview}</td>
        <td>${file.name}</td>
        <td>
            <div class="progress">
                <div class="progress-bar"></div>
            </div>
        </td>
        <td>
            <button class="remove">❌</button>
        </td>
        `;

        tableBody.appendChild(row);

        /* image preview */
        if(file.type.startsWith("image")){
            const img=row.querySelector("img");
            const reader=new FileReader();
            reader.onload=e=>img.src=e.target.result;
            reader.readAsDataURL(file);
        }

    });

}


/* remove file */
tableBody.addEventListener("click",e=>{

    if(!e.target.classList.contains("remove")) return;

    const row=e.target.closest("tr");
    const id=row.dataset.id;

    queue=queue.filter(f=>f.id!==id);

    row.remove();
});


/* upload */
uploadBtn.addEventListener("click",()=>{

    queue.forEach(item=>uploadFile(item));

});


/* upload single file */
function uploadFile(item){

    const row=document.querySelector(`tr[data-id="${item.id}"]`);
    const bar=row.querySelector(".progress-bar");

    const form=new FormData();
    form.append("upload",item.file);
    form.append("dir","<?= htmlspecialchars($relDir) ?>");

    const xhr=new XMLHttpRequest();

    xhr.upload.addEventListener("progress",e=>{

        if(e.lengthComputable){
            const percent=(e.loaded/e.total)*100;
            bar.style.width=percent+"%";
        }

    });

    xhr.onload=()=>{
        bar.style.width="100%";
    };

    xhr.open("POST","");
    xhr.send(form);

}
</script>
</body>
</html>

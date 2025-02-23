<?php
session_start();

// Debug log setup with toggle
define('DEBUG', false);
$debug_log = '/var/www/html/selfhostedgdrive/debug.log';
function log_debug($message) {
    if (DEBUG) {
        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
    }
}

// Ensure debug log exists and has correct permissions (run once during setup)
if (!file_exists($debug_log)) {
    file_put_contents($debug_log, "Debug log initialized\n");
    chown($debug_log, 'www-data');
    chmod($debug_log, 0666);
}

// Log request basics
log_debug("=== New Request ===");
log_debug("Session ID: " . session_id());
log_debug("Loggedin: " . (isset($_SESSION['loggedin']) ? var_export($_SESSION['loggedin'], true) : "Not set"));
log_debug("Username: " . (isset($_SESSION['username']) ? $_SESSION['username'] : "Not set"));
log_debug("GET params: " . var_export($_GET, true));

// Optimized file serving with range support
if (isset($_GET['action']) && $_GET['action'] === 'serve' && isset($_GET['file'])) {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
        log_debug("Unauthorized file request, redirecting to index.php");
        header("Location: /selfhostedgdrive/index.php", true, 302);
        exit;
    }

    $username = $_SESSION['username'];
    $baseDir = realpath("/var/www/html/webdav/users/$username/Home");
    if ($baseDir === false) {
        log_debug("Base directory not found for user: $username");
        header("HTTP/1.1 500 Internal Server Error");
        echo "Server configuration error.";
        exit;
    }

    $requestedFile = urldecode($_GET['file']);
    if (strpos($requestedFile, 'Home/') === 0) {
        $requestedFile = substr($requestedFile, 5);
    }
    $filePath = realpath($baseDir . '/' . $requestedFile);

    if ($filePath === false || strpos($filePath, $baseDir) !== 0 || !file_exists($filePath)) {
        log_debug("File not found or access denied: " . ($filePath ?: "Invalid path") . " (Requested: " . $_GET['file'] . ")");
        header("HTTP/1.1 404 Not Found");
        echo "File not found.";
        exit;
    }

    $fileSize = filesize($filePath);
    $fileName = basename($filePath);

    $mime_types = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'heic' => 'image/heic',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
    ];
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mime = $mime_types[$ext] ?? mime_content_type($filePath) ?? 'application/octet-stream';

    header("Content-Type: $mime");
    header("Accept-Ranges: bytes");
    header("Content-Length: $fileSize");
    header("Cache-Control: private, max-age=31536000");
    header("X-Content-Type-Options: nosniff");

    $fp = fopen($filePath, 'rb');
    if ($fp === false) {
        log_debug("Failed to open file: $filePath");
        header("HTTP/1.1 500 Internal Server Error");
        echo "Unable to serve file.";
        exit;
    }

    ob_clean();

    if (isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d+)-(\d*)?/', $range, $matches)) {
            $start = (int)$matches[1];
            $end = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

            if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
                log_debug("Invalid range request: $range for file size $fileSize");
                header("HTTP/1.1 416 Range Not Satisfiable");
                header("Content-Range: bytes */$fileSize");
                fclose($fp);
                exit;
            }

            $length = $end - $start + 1;
            header("HTTP/1.1 206 Partial Content");
            header("Content-Length: $length");
            header("Content-Range: bytes $start-$end/$fileSize");

            fseek($fp, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $chunk = min($remaining, 8192);
                echo fread($fp, $chunk);
                flush();
                $remaining -= $chunk;
            }
        } else {
            log_debug("Malformed range header: $range");
            header("HTTP/1.1 416 Range Not Satisfiable");
            header("Content-Range: bytes */$fileSize");
            fclose($fp);
            exit;
        }
    } else {
        while (!feof($fp) && !connection_aborted()) {
            echo fread($fp, 8192);
            flush();
        }
    }

    fclose($fp);
    log_debug("Successfully served file: $filePath");
    exit;
}

// Check login for page access
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['username'])) {
    log_debug("Redirecting to index.php due to no login");
    header("Location: /selfhostedgdrive/index.php", true, 302);
    exit;
}

/************************************************
 * 1. Define the "Home" directory as base
 ************************************************/
$username = $_SESSION['username'];
$homeDirPath = "/var/www/html/webdav/users/$username/Home";
if (!is_dir($homeDirPath)) {
    if (!mkdir($homeDirPath, 0777, true)) {
        log_debug("Failed to create home directory: $homeDirPath");
        header("HTTP/1.1 500 Internal Server Error");
        echo "Server configuration error.";
        exit;
    }
    chown($homeDirPath, 'www-data');
    chgrp($homeDirPath, 'www-data');
}
$baseDir = realpath($homeDirPath);
if ($baseDir === false) {
    log_debug("Base directory resolution failed for: $homeDirPath");
    header("HTTP/1.1 500 Internal Server Error");
    echo "Server configuration error.";
    exit;
}
log_debug("BaseDir: $baseDir (User: $username)");

// Redirect to Home if no folder specified
if (!isset($_GET['folder'])) {
    log_debug("No folder specified, redirecting to Home");
    header("Location: /selfhostedgdrive/explorer.php?folder=Home", true, 302);
    exit;
}

/************************************************
 * 2. Determine current folder
 ************************************************/
$currentRel = isset($_GET['folder']) ? trim(str_replace('..', '', $_GET['folder']), '/') : 'Home';
$currentDir = realpath($baseDir . '/' . $currentRel);
log_debug("CurrentRel: $currentRel");
log_debug("CurrentDir: " . ($currentDir ? $currentDir : "Not resolved"));

if ($currentDir === false || strpos($currentDir, $baseDir) !== 0) {
    log_debug("Invalid folder, resetting to Home");
    $currentDir = $baseDir;
    $currentRel = 'Home';
}

/************************************************
 * Calculate Storage Usage
 ************************************************/
function getDirSize($dir) {
    $size = 0;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $size += getDirSize($path);
        } else {
            $size += filesize($path);
        }
    }
    return $size;
}

$totalStorage = 10 * 1024 * 1024 * 1024; // 10 GB in bytes (configurable)
$usedStorage = getDirSize($baseDir);
$usedStorageGB = round($usedStorage / (1024 * 1024 * 1024), 2);
$totalStorageGB = round($totalStorage / (1024 * 1024 * 1024), 2);
$storagePercentage = round(($usedStorage / $totalStorage) * 100, 2);

/************************************************
 * 3. Create Folder
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder'])) {
    $folderName = trim($_POST['folder_name'] ?? '');
    if ($folderName !== '') {
        $targetPath = $currentDir . '/' . $folderName;
        if (!file_exists($targetPath)) {
            if (!mkdir($targetPath, 0777)) {
                log_debug("Failed to create folder: $targetPath");
                $_SESSION['error'] = "Failed to create folder.";
            } else {
                chown($targetPath, 'www-data');
                chgrp($targetPath, 'www-data');
                log_debug("Created folder: $targetPath");
            }
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 4. Upload Files
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_files'])) {
    foreach ($_FILES['upload_files']['name'] as $i => $fname) {
        if ($_FILES['upload_files']['error'][$i] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['upload_files']['tmp_name'][$i];
            $originalName = $_POST['file_name'] ?? basename($fname);
            $dest = $currentDir . '/' . $originalName;
            $chunkStart = (int)($_POST['chunk_start'] ?? 0);
            $totalSize = (int)($_POST['total_size'] ?? filesize($tmpPath));

            $output = fopen($dest, $chunkStart === 0 ? 'wb' : 'ab');
            if (!$output) {
                log_debug("Failed to open destination file: $dest");
                $_SESSION['error'] = "Failed to open file for writing: $originalName";
                continue;
            }

            $input = fopen($tmpPath, 'rb');
            if (!$input) {
                log_debug("Failed to open temp file: $tmpPath");
                $_SESSION['error'] = "Failed to read uploaded file: $originalName";
                fclose($output);
                continue;
            }

            if ($chunkStart > 0) {
                fseek($output, $chunkStart);
            }

            while (!feof($input)) {
                $data = fread($input, 8192);
                if ($data === false || fwrite($output, $data) === false) {
                    log_debug("Failed to write chunk for $originalName at offset $chunkStart");
                    $_SESSION['error'] = "Failed to write chunk for $originalName";
                    fclose($input);
                    fclose($output);
                    unlink($dest);
                    continue 2;
                }
            }

            fclose($input);
            fclose($output);

            chown($dest, 'www-data');
            chgrp($dest, 'www-data');
            chmod($dest, 0664);
            log_debug("Uploaded chunk for: $dest at offset $chunkStart");

            if (filesize($dest) >= $totalSize) {
                log_debug("Completed upload for: $dest");
            }
        } else {
            $errorMsg = match ($_FILES['upload_files']['error'][$i]) {
                UPLOAD_ERR_INI_SIZE => "File too large (exceeds upload_max_filesize)",
                UPLOAD_ERR_FORM_SIZE => "File too large (exceeds form max size)",
                UPLOAD_ERR_PARTIAL => "File upload was partial",
                UPLOAD_ERR_NO_FILE => "No file was uploaded",
                UPLOAD_ERR_NO_TMP_DIR => "Missing temporary directory",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
                UPLOAD_ERR_EXTENSION => "File upload stopped by extension",
                default => "Unknown upload error"
            };
            log_debug("Upload error for $fname: $errorMsg");
            $_SESSION['error'] = "Upload error for $fname: $errorMsg";
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 5. Delete an item (folder or file)
 ************************************************/
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemToDelete = $_GET['delete'];
    $targetPath = realpath($currentDir . '/' . $itemToDelete);

    if ($targetPath && strpos($targetPath, $currentDir) === 0) {
        if (is_dir($targetPath)) {
            deleteRecursive($targetPath);
            log_debug("Deleted folder: $targetPath");
        } elseif (unlink($targetPath)) {
            log_debug("Deleted file: $targetPath");
        } else {
            log_debug("Failed to delete item: $targetPath");
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 6. Recursively delete a folder
 ************************************************/
function deleteRecursive($dirPath) {
    $items = scandir($dirPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $dirPath . '/' . $item;
        if (is_dir($full)) {
            deleteRecursive($full);
        } else {
            unlink($full);
        }
    }
    rmdir($dirPath);
}

/************************************************
 * 7. Rename a folder
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_folder'])) {
    $oldFolderName = $_POST['old_folder_name'] ?? '';
    $newFolderName = $_POST['new_folder_name'] ?? '';
    $oldPath = realpath($currentDir . '/' . $oldFolderName);

    if ($oldPath && is_dir($oldPath)) {
        $newPath = $currentDir . '/' . $newFolderName;
        if (!file_exists($newPath) && rename($oldPath, $newPath)) {
            log_debug("Renamed folder: $oldPath to $newPath");
        } else {
            log_debug("Failed to rename folder: $oldPath to $newPath");
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 8. Rename a file (prevent extension change)
 ************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_file'])) {
    $oldFileName = $_POST['old_file_name'] ?? '';
    $newFileName = $_POST['new_file_name'] ?? '';
    $oldFilePath = realpath($currentDir . '/' . $oldFileName);

    if ($oldFilePath && is_file($oldFilePath)) {
        $oldExt = strtolower(pathinfo($oldFileName, PATHINFO_EXTENSION));
        $newExt = strtolower(pathinfo($newFileName, PATHINFO_EXTENSION));
        if ($oldExt !== $newExt) {
            $_SESSION['error'] = "Modification of file extension is not allowed.";
        } else {
            $newFilePath = $currentDir . '/' . $newFileName;
            if (!file_exists($newFilePath) && rename($oldFilePath, $newFilePath)) {
                log_debug("Renamed file: $oldFilePath to $newFilePath");
            } else {
                log_debug("Failed to rename file: $oldFilePath to $newFilePath");
            }
        }
    }
    header("Location: /selfhostedgdrive/explorer.php?folder=" . urlencode($currentRel), true, 302);
    exit;
}

/************************************************
 * 9. Gather folders & files
 ************************************************/
$folders = [];
$files = [];
if (is_dir($currentDir)) {
    $all = scandir($currentDir);
    if ($all !== false) {
        foreach ($all as $one) {
            if ($one === '.' || $one === '..') continue;
            $path = $currentDir . '/' . $one;
            if (is_dir($path)) {
                $folders[] = $one;
            } else {
                $files[] = $one;
            }
        }
    }
}
sort($folders);
sort($files);
log_debug("Folders: " . implode(", ", $folders));
log_debug("Files: " . implode(", ", $files));

/************************************************
 * 10. "Back" link if not at Home
 ************************************************/
$parentLink = '';
if ($currentDir !== $baseDir) {
    $parts = explode('/', $currentRel);
    array_pop($parts);
    $parentRel = implode('/', $parts);
    $parentLink = '/selfhostedgdrive/explorer.php?folder=' . urlencode($parentRel);
}

/************************************************
 * 11. Helper: Decide which FA icon to show
 ************************************************/
function getIconClass($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'heic'])) return 'fas fa-file-image';
    if (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'])) return 'fas fa-file-video';
    if ($ext === 'pdf') return 'fas fa-file-pdf';
    if ($ext === 'exe') return 'fas fa-file-exclamation';
    return 'fas fa-file';
}

/************************************************
 * 12. Helper: Check if file is "previewable"
 ************************************************/
function isImage($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'heic']);
}
function isVideo($fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv']);
}

/************************************************
 * 13. Generate Shareable Link for Files (New Feature)
 ************************************************/
function generateShareLink($fileName, $username, $currentRel) {
    $token = bin2hex(random_bytes(16)); // Generate a random 32-character token
    $expiry = time() + (24 * 3600); // 24 hours from now
    $shareData = [
        'username' => $username,
        'file' => $currentRel . '/' . $fileName,
        'token' => $token,
        'expiry' => $expiry
    ];
    $sharesFile = '/var/www/html/selfhostedgdrive/shares.json';
    if (!file_exists($sharesFile)) {
        file_put_contents($sharesFile, json_encode([]));
        chown($sharesFile, 'www-data');
        chmod($sharesFile, 0666);
    }
    $shares = json_decode(file_get_contents($sharesFile), true) ?: [];
    $shares[$token] = $shareData;
    file_put_contents($sharesFile, json_encode($shares, JSON_PRETTY_PRINT));
    return "http://34.47.127.51/selfhostedgdrive/share.php?token=$token";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Explorer with Previews</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="/selfhostedgdrive/styles.css">
</head>
<body>
  <div class="app-container">
    <div class="sidebar" id="sidebar">
      <div class="folders-container">
        <div class="top-row">
          <h2>Folders</h2>
          <?php if ($parentLink): ?>
            <a class="btn-back" href="<?php echo htmlspecialchars($parentLink); ?>" title="Back">
              <i class="fas fa-arrow-left"></i>
            </a>
          <?php endif; ?>
          <button type="button" class="btn" title="Create New Folder" onclick="createFolder()">
            <i class="fas fa-folder-plus"></i>
          </button>
          <button type="button" class="btn" id="btnDeleteFolder" title="Delete selected folder" style="display:none;">
            <i class="fas fa-trash"></i>
          </button>
          <button type="button" class="btn" id="btnRenameFolder" title="Rename selected folder" style="display:none;">
            <i class="fas fa-edit"></i>
          </button>
          <a href="/selfhostedgdrive/logout.php" class="btn logout-btn" title="Logout">
            <i class="fa fa-sign-out" aria-hidden="true"></i>
          </a>
        </div>
        <ul class="folder-list" id="folderList">
          <?php foreach ($folders as $folderName): ?>
            <?php $folderPath = ($currentRel === 'Home' ? '' : $currentRel . '/') . $folderName; 
                  log_debug("Folder path for $folderName: $folderPath"); ?>
            <li class="folder-item"
                ondblclick="openFolder('<?php echo urlencode($folderPath); ?>')"
                onclick="selectFolder(this, '<?php echo addslashes($folderName); ?>'); event.stopPropagation();"
                data-name="<?php echo htmlspecialchars($folderName); ?>">
              <i class="fas fa-folder"></i> <?php echo htmlspecialchars($folderName); ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <!-- Storage Indicator at Bottom of Sidebar -->
        <div class="storage-indicator">
          <p><?php echo "$usedStorageGB GB used of $totalStorageGB GB"; ?></p>
          <div class="storage-bar">
            <div class="storage-progress" style="width: <?php echo $storagePercentage; ?>%;"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="main-content">
      <div class="header-area">
        <div class="header-title">
          <button class="hamburger" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
          </button>
          <h1><?php echo ($currentRel === 'Home') ? 'Home' : htmlspecialchars($currentRel); ?></h1>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
          <input type="text" class="search-bar" id="searchInput" placeholder="Search files/folders..." onkeyup="filterItems()">
          <form id="uploadForm" method="POST" enctype="multipart/form-data" action="/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>">
            <input type="file" name="upload_files[]" multiple id="fileInput" style="display:none;" />
            <button type="button" class="btn" id="uploadBtn" title="Upload" style="width:36px; height:36px;">
              <i class="fas fa-cloud-upload-alt"></i>
            </button>
          </form>
          <button type="button" class="btn" id="gridToggleBtn" title="Toggle Grid View" style="width:36px; height:36px;">
            <i class="fas fa-th"></i>
          </button>
          <button type="button" class="btn theme-toggle-btn" id="themeToggleBtn" title="Toggle Theme" style="width:36px; height:36px;">
            <i class="fas fa-moon"></i>
          </button>
          <div id="uploadProgressContainer">
            <div style="background:var(--border-color); width:100%; height:20px; border-radius:4px; overflow:hidden;">
              <div id="uploadProgressBar"></div>
            </div>
            <div id="uploadProgressPercent">0.0%</div>
            <button class="cancel-upload-btn" id="cancelUploadBtn">Cancel</button>
          </div>
        </div>
      </div>
      <div class="content-inner">
        <div id="dropZone">Drop files here to upload</div>
        <div class="file-list" id="fileList">
          <?php foreach ($files as $fileName): ?>
            <?php 
              $relativePath = $currentRel . '/' . $fileName;
              $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
              $iconClass = getIconClass($fileName);
              $canPreview = (isImage($fileName) || isVideo($fileName));
              $isImageFile = isImage($fileName);
              $isVideoFile = isVideo($fileName);
              log_debug("File URL for $fileName: $fileURL");
              $shareLink = generateShareLink($fileName, $username, $currentRel);
            ?>
            <div class="file-row" onclick="openPreviewModal('<?php echo htmlspecialchars($fileURL); ?>', '<?php echo addslashes($fileName); ?>')"
                 data-name="<?php echo htmlspecialchars($fileName); ?>">
              <i class="<?php echo $iconClass; ?> file-icon<?php echo $isImageFile || $isVideoFile ? '' : ' no-preview'; ?>"></i>
              <?php if ($isImageFile): ?>
                <img src="<?php echo htmlspecialchars($fileURL); ?>" alt="<?php echo htmlspecialchars($fileName); ?>" class="file-preview" loading="lazy">
              <?php elseif ($isVideoFile): ?>
                <!-- No preview in list view, just icon -->
              <?php endif; ?>
              <div class="file-name"
                   title="<?php echo htmlspecialchars($fileName); ?>">
                <?php echo htmlspecialchars($fileName); ?>
              </div>
              <div class="file-actions">
                <button type="button" class="btn" onclick="downloadFile('<?php echo $fileURL; ?>')" title="Download">
                  <i class="fas fa-download"></i>
                </button>
                <button type="button" class="btn" title="Share File" onclick="copyShareLink('<?php echo htmlspecialchars($shareLink); ?>', '<?php echo htmlspecialchars($fileName); ?>')">
                  <i class="fas fa-share-alt"></i>
                </button>
                <button type="button" class="btn" title="Rename File" onclick="renameFilePrompt('<?php echo addslashes($fileName); ?>')">
                  <i class="fas fa-edit"></i>
                </button>
                <button type="button" class="btn" title="Delete File" onclick="confirmFileDelete('<?php echo addslashes($fileName); ?>')">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div id="previewModal">
    <div id="previewContent">
      <div id="previewNav">
        <button id="prevBtn" onclick="navigatePreview(-1)"><i class="fas fa-arrow-left"></i></button>
        <button id="nextBtn" onclick="navigatePreview(1)"><i class="fas fa-arrow-right"></i></button>
      </div>
      <span id="previewClose" onclick="closePreviewModal()"><i class="fas fa-times"></i></span>
      <div id="videoPlayerContainer" style="display: none;">
        <video id="videoPlayer" preload="auto"></video>
        <div id="videoPlayerControls">
          <button id="playPauseBtn" class="player-btn"><i class="fas fa-play"></i></button>
          <span id="currentTime">0:00</span>
          <input type="range" id="seekBar" value="0" min="0" step="0.1" class="seek-slider">
          <span id="duration">0:00</span>
          <button id="muteBtn" class="player-btn"><i class="fas fa-volume-up"></i></button>
          <input type="range" id="volumeBar" value="1" min="0" max="1" step="0.01" class="volume-slider">
          <button id="fullscreenBtn" class="player-btn"><i class="fas fa-expand"></i></button>
        </div>
      </div>
      <div id="imagePreviewContainer" style="display: none;"></div>
      <div id="iconPreviewContainer" style="display: none;"></div>
    </div>
  </div>

  <div id="dialogModal">
    <div class="dialog-content">
      <div class="dialog-message" id="dialogMessage"></div>
      <div class="dialog-buttons" id="dialogButtons"></div>
    </div>
  </div>

  <script>
  let selectedFolder = null;
  let currentXhr = null;
  let previewFiles = []; // Array to store previewable files
  let currentPreviewIndex = -1;
  let isLoadingImage = false; // Flag to prevent overlapping image loads

  function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sb.classList.toggle('open');
    overlay.classList.toggle('show');
  }
  document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);

  function selectFolder(element, folderName) {
    document.querySelectorAll('.folder-item.selected').forEach(item => item.classList.remove('selected'));
    element.classList.add('selected');
    selectedFolder = folderName;
    document.getElementById('btnDeleteFolder').style.display = 'flex';
    document.getElementById('btnRenameFolder').style.display = 'flex';
  }
  function openFolder(folderPath) {
    console.log("Opening folder: " + folderPath);
    window.location.href = '/selfhostedgdrive/explorer.php?folder=' + folderPath;
  }

  function showPrompt(message, defaultValue, callback) {
    const dialogModal = document.getElementById('dialogModal');
    const dialogMessage = document.getElementById('dialogMessage');
    const dialogButtons = document.getElementById('dialogButtons');
    dialogMessage.innerHTML = '';
    dialogButtons.innerHTML = '';
    const msgEl = document.createElement('div');
    msgEl.textContent = message;
    msgEl.style.marginBottom = '10px';
    dialogMessage.appendChild(msgEl);
    const inputField = document.createElement('input');
    inputField.type = 'text';
    inputField.value = defaultValue || '';
    inputField.style.width = '100%';
    inputField.style.padding = '8px';
    inputField.style.border = '1px solid #555';
    inputField.style.borderRadius = '4px';
    inputField.style.background = '#2a2a2a';
    inputField.style.color = '#fff';
    inputField.style.marginBottom = '15px';
    dialogMessage.appendChild(inputField);
    const okBtn = document.createElement('button');
    okBtn.className = 'dialog-button';
    okBtn.textContent = 'OK';
    okBtn.onclick = () => { closeDialog(); if (callback) callback(inputField.value); };
    dialogButtons.appendChild(okBtn);
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'dialog-button';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = () => { closeDialog(); if (callback) callback(null); };
    dialogButtons.appendChild(cancelBtn);
    dialogModal.classList.add('show');
  }
  function closeDialog() { document.getElementById('dialogModal').classList.remove('show'); }
  function showAlert(message, callback) {
    const dialogModal = document.getElementById('dialogModal');
    const dialogMessage = document.getElementById('dialogMessage');
    const dialogButtons = document.getElementById('dialogButtons');
    dialogMessage.textContent = message;
    dialogButtons.innerHTML = '';
    const okBtn = document.createElement('button');
    okBtn.className = 'dialog-button';
    okBtn.textContent = 'OK';
    okBtn.onclick = () => { closeDialog(); if (callback) callback(); };
    dialogButtons.appendChild(okBtn);
    dialogModal.classList.add('show');
  }
  function showConfirm(message, onYes, onNo) {
    const dialogModal = document.getElementById('dialogModal');
    const dialogMessage = document.getElementById('dialogMessage');
    const dialogButtons = document.getElementById('dialogButtons');
    dialogMessage.textContent = message;
    dialogButtons.innerHTML = '';
    const yesBtn = document.createElement('button');
    yesBtn.className = 'dialog-button';
    yesBtn.textContent = 'Yes';
    yesBtn.onclick = () => { closeDialog(); if (onYes) onYes(); };
    dialogButtons.appendChild(yesBtn);
    const noBtn = document.createElement('button');
    noBtn.className = 'dialog-button';
    noBtn.textContent = 'No';
    noBtn.onclick = () => { closeDialog(); if (onNo) onNo(); };
    dialogButtons.appendChild(noBtn);
    dialogModal.classList.add('show');
  }

  function createFolder() {
    showPrompt("Enter new folder name:", "", function(folderName) {
      if (folderName && folderName.trim() !== "") {
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
        let inputCreate = document.createElement('input');
        inputCreate.type = 'hidden';
        inputCreate.name = 'create_folder';
        inputCreate.value = '1';
        form.appendChild(inputCreate);
        let inputName = document.createElement('input');
        inputName.type = 'hidden';
        inputName.name = 'folder_name';
        inputName.value = folderName.trim();
        form.appendChild(inputName);
        document.body.appendChild(form);
        form.submit();
      }
    });
  }

  document.getElementById('btnRenameFolder').addEventListener('click', function() {
    if (!selectedFolder) return;
    showPrompt("Enter new folder name:", selectedFolder, function(newName) {
      if (newName && newName.trim() !== "" && newName !== selectedFolder) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
        let inputAction = document.createElement('input');
        inputAction.type = 'hidden';
        inputAction.name = 'rename_folder';
        inputAction.value = '1';
        form.appendChild(inputAction);
        let inputOld = document.createElement('input');
        inputOld.type = 'hidden';
        inputOld.name = 'old_folder_name';
        inputOld.value = selectedFolder;
        form.appendChild(inputOld);
        let inputNew = document.createElement('input');
        inputNew.type = 'hidden';
        inputNew.name = 'new_folder_name';
        inputNew.value = newName.trim();
        form.appendChild(inputNew);
        document.body.appendChild(form);
        form.submit();
      }
    });
  });

  document.getElementById('btnDeleteFolder').addEventListener('click', function() {
    if (!selectedFolder) return;
    showConfirm(`Delete folder "${selectedFolder}"?`, () => {
      let form = document.createElement('form');
      form.method = 'POST';
      form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>&delete=' + encodeURIComponent(selectedFolder);
      document.body.appendChild(form);
      form.submit();
    });
  });

  function renameFilePrompt(fileName) {
    let dotIndex = fileName.lastIndexOf(".");
    let baseName = fileName;
    let ext = "";
    if (dotIndex > 0) {
      baseName = fileName.substring(0, dotIndex);
      ext = fileName.substring(dotIndex);
    }
    showPrompt("Enter new file name:", baseName, function(newBase) {
      if (newBase && newBase.trim() !== "" && newBase.trim() !== baseName) {
        let finalName = newBase.trim() + ext;
        let form = document.createElement('form');
        form.method = 'POST';
        form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>';
        let inputAction = document.createElement('input');
        inputAction.type = 'hidden';
        inputAction.name = 'rename_file';
        inputAction.value = '1';
        form.appendChild(inputAction);
        let inputOld = document.createElement('input');
        inputOld.type = 'hidden';
        inputOld.name = 'old_file_name';
        inputOld.value = fileName;
        form.appendChild(inputOld);
        let inputNew = document.createElement('input');
        inputNew.type = 'hidden';
        inputNew.name = 'new_file_name';
        inputNew.value = finalName;
        form.appendChild(inputNew);
        document.body.appendChild(form);
        form.submit();
      }
    });
  }

  function confirmFileDelete(fileName) {
    showConfirm(`Delete file "${fileName}"?`, () => {
      let form = document.createElement('form');
      form.method = 'POST';
      form.action = '/selfhostedgdrive/explorer.php?folder=<?php echo urlencode($currentRel); ?>&delete=' + encodeURIComponent(fileName);
      document.body.appendChild(form);
      form.submit();
    });
  }

  function downloadFile(fileURL) {
    console.log("Downloading: " + fileURL);
    const a = document.createElement('a');
    a.href = fileURL;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  function copyShareLink(shareLink, fileName) {
    navigator.clipboard.writeText(shareLink).then(() => {
      showAlert(`Share link for "${fileName}" copied to clipboard! Link expires in 24 hours.`);
    }).catch(err => {
      showAlert(`Failed to copy share link for "${fileName}". Please try again.`);
    });
  }

  // Collect all previewable files for navigation
  <?php
  $previewableFiles = [];
  foreach ($files as $fileName) {
      $relativePath = $currentRel . '/' . $fileName;
      $fileURL = "/selfhostedgdrive/explorer.php?action=serve&file=" . urlencode($relativePath);
      $iconClass = getIconClass($fileName);
      $previewableFiles[] = ['name' => $fileName, 'url' => $fileURL, 'type' => isImage($fileName) ? 'image' : (isVideo($fileName) ? 'video' : 'other'), 'icon' => $iconClass];
  }
  ?>

  function openPreviewModal(fileURL, fileName) {
    if (isLoadingImage) return; // Prevent overlapping loads
    console.log("Previewing: " + fileURL);
    const previewModal = document.getElementById('previewModal');
    const videoContainer = document.getElementById('videoPlayerContainer');
    const imageContainer = document.getElementById('imagePreviewContainer');
    const iconContainer = document.getElementById('iconPreviewContainer');
    const previewContent = document.getElementById('previewContent');
    const videoPlayer = document.getElementById('videoPlayer');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const previewClose = document.getElementById('previewClose');

    // Clear all preview containers
    videoContainer.style.display = 'none';
    imageContainer.style.display = 'none';
    imageContainer.innerHTML = ''; // Clear previous images
    iconContainer.style.display = 'none';
    iconContainer.innerHTML = ''; // Clear icon content
    videoPlayer.pause();
    videoPlayer.src = ''; // Reset video source
    previewContent.classList.remove('image-preview'); // Reset class

    // Populate previewable files
    previewFiles = <?php echo json_encode($previewableFiles); ?>;
    currentPreviewIndex = previewFiles.findIndex(file => file.name === fileName);

    let file = previewFiles.find(f => f.name === fileName);
    if (file.type === 'image') {
      isLoadingImage = true;
      fetch(fileURL)
        .then(response => {
          if (!response.ok) throw new Error('Preview failed: ' + response.status);
          return response.blob();
        })
        .then(blob => {
          const img = document.createElement('img');
          img.src = URL.createObjectURL(blob);
          imageContainer.appendChild(img);
          imageContainer.style.display = 'flex';
          previewClose.style.display = 'none'; // Hide close button for images
          previewContent.classList.add('image-preview'); // Remove box styling
        })
        .catch(error => showAlert('Preview error: ' + error.message))
        .finally(() => {
          isLoadingImage = false; // Reset flag after loading
        });
    } else if (file.type === 'video') {
      videoPlayer.src = fileURL;
      videoContainer.style.display = 'block';
      previewClose.style.display = 'block'; // Show close button for videos
      setupVideoPlayer(fileURL, fileName);
    } else if (file.type === 'other') {
      const icon = document.createElement('i');
      icon.className = file.icon;
      iconContainer.appendChild(icon);
      iconContainer.style.display = 'flex';
      previewClose.style.display = 'block'; // Show close button for others
    } else {
      downloadFile(fileURL);
      return;
    }

    previewModal.style.display = 'flex';
    updateNavigationButtons();

    // Add click-outside-to-close functionality for images
    previewModal.onclick = function(e) {
      if (e.target === previewModal && file.type === 'image') {
        closePreviewModal();
      }
    };
  }
  window.openPreviewModal = openPreviewModal;

  function setupVideoPlayer(fileURL, fileName) {
    const video = document.getElementById('videoPlayer');
    const playPauseBtn = document.getElementById('playPauseBtn');
    const seekBar = document.getElementById('seekBar');
    const currentTime = document.getElementById('currentTime');
    const duration = document.getElementById('duration');
    const muteBtn = document.getElementById('muteBtn');
    const volumeBar = document.getElementById('volumeBar');
    const fullscreenBtn = document.getElementById('fullscreenBtn');
    const previewModal = document.getElementById('previewModal');

    video.src = fileURL;
    video.preload = 'auto';
    video.load();

    const videoKey = `video_position_${fileName}`;
    const savedTime = localStorage.getItem(videoKey);
    if (savedTime) video.currentTime = parseFloat(savedTime);

    video.onloadedmetadata = () => {
      seekBar.max = video.duration;
      duration.textContent = formatTime(video.duration);
    };

    video.ontimeupdate = () => {
      seekBar.value = video.currentTime;
      currentTime.textContent = formatTime(video.currentTime);
      localStorage.setItem(videoKey, video.currentTime);
    };

    playPauseBtn.onclick = () => {
      if (video.paused) {
        video.play();
        playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
      } else {
        video.pause();
        playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
      }
    };

    seekBar.oninput = () => {
      video.currentTime = seekBar.value;
    };

    muteBtn.onclick = () => {
      video.muted = !video.muted;
      muteBtn.innerHTML = video.muted ? '<i class="fas fa-volume-mute"></i>' : '<i class="fas fa-volume-up"></i>';
      volumeBar.value = video.muted ? 0 : video.volume;
    };

    volumeBar.oninput = () => {
      video.volume = volumeBar.value;
      video.muted = (volumeBar.value == 0);
      muteBtn.innerHTML = video.muted ? '<i class="fas fa-volume-mute"></i>' : '<i class="fas fa-volume-up"></i>';
    };

    fullscreenBtn.onclick = () => {
      if (!document.fullscreenElement) {
        previewModal.classList.add('fullscreen');
        previewModal.requestFullscreen();
      } else {
        document.exitFullscreen();
        previewModal.classList.remove('fullscreen');
      }
    };

    video.onclick = () => playPauseBtn.click();

    video.addEventListener('touchstart', (e) => {
      e.preventDefault();
      playPauseBtn.click();
    });
  }

  function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
  }

  function closePreviewModal() {
    const video = document.getElementById('videoPlayer');
    video.pause();
    video.src = '';
    document.getElementById('previewModal').style.display = 'none';
    if (document.fullscreenElement) document.exitFullscreen();
    document.getElementById('previewModal').classList.remove('fullscreen');
    document.getElementById('previewModal').onclick = null; // Remove event listener
    document.getElementById('previewClose').style.display = 'block'; // Reset close button visibility
    document.getElementById('previewContent').classList.remove('image-preview'); // Reset class
    isLoadingImage = false; // Reset loading flag
    previewFiles = [];
    currentPreviewIndex = -1;
  }
  window.closePreviewModal = closePreviewModal;

  function navigatePreview(direction) {
    if (previewFiles.length === 0 || currentPreviewIndex === -1 || isLoadingImage) return;

    currentPreviewIndex += direction;
    if (currentPreviewIndex < 0) currentPreviewIndex = previewFiles.length - 1;
    if (currentPreviewIndex >= previewFiles.length) currentPreviewIndex = 0;

    const file = previewFiles[currentPreviewIndex];
    openPreviewModal(file.url, file.name);
  }

  function updateNavigationButtons() {
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    prevBtn.disabled = previewFiles.length <= 1;
    nextBtn.disabled = previewFiles.length <= 1;
  }

  function filterItems() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const folderItems = document.querySelectorAll('#folderList .folder-item');
    const fileItems = document.querySelectorAll('#fileList .file-row');

    folderItems.forEach(item => {
      const name = item.getAttribute('data-name').toLowerCase();
      item.style.display = name.includes(searchTerm) ? '' : 'none';
    });

    fileItems.forEach(item => {
      const name = item.getAttribute('data-name').toLowerCase();
      item.style.display = name.includes(searchTerm) ? '' : 'none';
    });
  }

  const uploadForm = document.getElementById('uploadForm');
  const fileInput = document.getElementById('fileInput');
  const uploadBtn = document.getElementById('uploadBtn');
  const uploadProgressContainer = document.getElementById('uploadProgressContainer');
  const uploadProgressBar = document.getElementById('uploadProgressBar');
  const uploadProgressPercent = document.getElementById('uploadProgressPercent');
  const cancelUploadBtn = document.getElementById('cancelUploadBtn');
  const dropZone = document.getElementById('dropZone');
  const mainContent = document.querySelector('.main-content');
  const fileList = document.getElementById('fileList');
  const gridToggleBtn = document.getElementById('gridToggleBtn');

  uploadBtn.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => {
    if (fileInput.files.length) startUpload(fileInput.files);
  });

  mainContent.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('active');
  });
  mainContent.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dropZone.classList.remove('active');
  });
  mainContent.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('active');
    const files = e.dataTransfer.files;
    if (files.length > 0) startUpload(files);
  });

  function startUpload(fileList) {
    for (let file of fileList) {
      let totalUploaded = 0; // Track total bytes uploaded for this file
      uploadChunk(file, 0, file.name, totalUploaded);
    }
  }

  function uploadChunk(file, startByte, fileName, totalUploaded) {
    const chunkSize = 10 * 1024 * 1024; // 10 MB chunks
    const endByte = Math.min(startByte + chunkSize, file.size);
    const chunk = file.slice(startByte, endByte);

    const formData = new FormData();
    formData.append('upload_files[]', chunk, file.name);
    formData.append('file_name', file.name);
    formData.append('chunk_start', startByte);
    formData.append('chunk_end', endByte - 1);
    formData.append('total_size', file.size);

    uploadProgressContainer.style.display = 'block';
    uploadProgressPercent.textContent = `0.0% - Uploading ${fileName}`;
    let attempts = 0;
    const maxAttempts = 3;

    function attemptUpload() {
      const xhr = new XMLHttpRequest();
      currentXhr = xhr;
      xhr.open('POST', uploadForm.action, true);
      xhr.timeout = 3600000;
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          const chunkUploaded = e.loaded; // Bytes uploaded in this chunk
          const totalBytesUploaded = totalUploaded + chunkUploaded;
          const totalPercent = Math.round((totalBytesUploaded / file.size) * 100 * 10) / 10; // One decimal place
          uploadProgressBar.style.width = totalPercent + '%';
          uploadProgressPercent.textContent = `${totalPercent}% - Uploading ${fileName}`;
        }
      };
      xhr.onload = () => {
        if (xhr.status === 200) {
          totalUploaded += (endByte - startByte); // Update total bytes uploaded
          if (endByte < file.size) {
            uploadChunk(file, endByte, fileName, totalUploaded);
          } else {
            showAlert('Upload completed successfully.');
            uploadProgressContainer.style.display = 'none';
            location.reload();
          }
        } else {
          handleUploadError(xhr, attempts, maxAttempts);
        }
      };
      xhr.onerror = () => handleUploadError(xhr, attempts, maxAttempts);
      xhr.ontimeout = () => handleUploadError(xhr, attempts, maxAttempts);
      xhr.send(formData);
    }

    function handleUploadError(xhr, attempts, maxAttempts) {
      attempts++;
      if (attempts < maxAttempts) {
        showAlert(`Upload failed for ${fileName} (Attempt ${attempts}). Retrying in 5 seconds... Status: ${xhr.status} - ${xhr.statusText}`);
        setTimeout(attemptUpload, 5000);
      } else {
        showAlert(`Upload failed for ${fileName} after ${maxAttempts} attempts. Status: ${xhr.status} - ${xhr.statusText}. Please check server logs or network connection.`);
        uploadProgressContainer.style.display = 'none';
      }
    }

    attemptUpload();
  }

  cancelUploadBtn.addEventListener('click', () => {
    if (currentXhr) {
      currentXhr.abort();
      uploadProgressContainer.style.display = 'none';
      fileInput.value = "";
      showAlert('Upload canceled.');
    }
  });

  const themeToggleBtn = document.getElementById('themeToggleBtn');
  const body = document.body;
  const savedTheme = localStorage.getItem('theme') || 'dark';
  if (savedTheme === 'light') {
    body.classList.add('light-mode');
    themeToggleBtn.querySelector('i').classList.replace('fa-moon', 'fa-sun');
  } else {
    body.classList.remove('light-mode');
    themeToggleBtn.querySelector('i').classList.replace('fa-sun', 'fa-moon');
  }
  themeToggleBtn.addEventListener('click', () => {
    body.classList.toggle('light-mode');
    const isLightMode = body.classList.contains('light-mode');
    themeToggleBtn.querySelector('i').classList.toggle('fa-moon', !isLightMode);
    themeToggleBtn.querySelector('i').classList.toggle('fa-sun', isLightMode);
    localStorage.setItem('theme', isLightMode ? 'light' : 'dark');
  });

  // Grid View Toggle with Persistence
  let isGridView = localStorage.getItem('gridView') === 'true';
  function updateGridView() {
    fileList.classList.toggle('grid-view', isGridView);
    gridToggleBtn.querySelector('i').classList.toggle('fa-th', isGridView);
    gridToggleBtn.querySelector('i').classList.toggle('fa-list', !isGridView);
    gridToggleBtn.title = isGridView ? 'Switch to List View' : 'Switch to Grid View';
  }
  // Initialize grid view state
  updateGridView();

  gridToggleBtn.addEventListener('click', () => {
    isGridView = !isGridView;
    localStorage.setItem('gridView', isGridView);
    updateGridView();
  });
</script>
</body>
</html>

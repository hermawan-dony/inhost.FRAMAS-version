<?php
session_start();

$passHash = hash('sha256', '1782');
$uploadDir = '/source/share/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: upload.php');
    exit;
}

// Login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash('sha256', (string)$_POST['password']) === $passHash) {
        $_SESSION['auth_share'] = true;
        header('Location: upload.php');
        exit;
    } else {
        $error = 'Password salah!';
    }
}

// Auth check
$isAuth = !empty($_SESSION['auth_share']);

// Download file
if ($isAuth && isset($_GET['download'])) {
    $f = basename($_GET['download']);
    $p = $uploadDir . $f;
    if (file_exists($p) && is_file($p)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($p));
        readfile($p);
        exit;
    }
}

// Delete file
$msg = '';
if ($isAuth && isset($_GET['delete'])) {
    $f = basename($_GET['delete']);
    $p = $uploadDir . $f;
    if (file_exists($p) && is_file($p)) {
        unlink($p);
        $msg = 'File ' . htmlspecialchars($f) . ' berhasil dihapus.';
    }
}

// Upload file
if ($isAuth && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    $count = count($_FILES['files']['name']);
    $uploaded = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
            $orig = basename($_FILES['files']['name'][$i]);
            $target = $uploadDir . $orig;
            if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $target)) {
                $uploaded++;
                // Auto extract zip if requested
                if (!empty($_POST['unzip']) && strtolower(pathinfo($orig, PATHINFO_EXTENSION)) === 'zip') {
                    $zip = new ZipArchive;
                    if ($zip->open($target) === TRUE) {
                        $zip->extractTo($uploadDir);
                        $zip->close();
                    }
                }
            }
        }
    }
    $msg = "Berhasil mengupload {$uploaded} file ke folder share!";
}

// List files
$files = [];
if ($isAuth) {
    $items = scandir($uploadDir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $uploadDir . $item;
        $files[] = [
            'name' => $item,
            'is_dir' => is_dir($full),
            'size' => is_file($full) ? filesize($full) : 0,
            'time' => filemtime($full),
        ];
    }
    usort($files, function($a, $b) { return $b['time'] - $a['time']; });
}

function formatBytes($bytes) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CEISA 4.0 - Secure Share Uploader</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f1f5f9; padding: 30px 20px; }
        .container { max-width: 800px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 28px; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; padding-bottom: 16px; margin-bottom: 24px; }
        h1 { font-size: 20px; color: #38bdf8; }
        .dropzone { border: 2px dashed #475569; border-radius: 10px; padding: 40px 20px; text-align: center; background: #0f172a; cursor: pointer; transition: border-color .2s; }
        .dropzone:hover, .dropzone.dragover { border-color: #38bdf8; background: #1e293b; }
        .dropzone p { color: #94a3b8; margin-top: 8px; font-size: 14px; }
        .btn { display: inline-block; background: #0284c7; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; border: none; cursor: pointer; font-weight: 600; font-size: 14px; }
        .btn:hover { background: #0369a1; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #065f46; color: #6ee7b7; border: 1px solid #059669; }
        .alert-danger { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 14px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-weight: 600; background: #0f172a; }
        tr:hover td { background: #0f172a; }
        .login-box { max-width: 380px; margin: 40px auto; background: #1e293b; border-radius: 12px; padding: 32px; border: 1px solid #334155; text-align: center; }
        .login-box input[type="password"] { width: 100%; padding: 10px 14px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; font-size: 16px; margin: 16px 0; }
        .checkbox-label { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 14px; font-size: 13px; color: #94a3b8; }
    </style>
</head>
<body>

<?php if (!$isAuth): ?>
    <div class="login-box">
        <h2 style="color:#38bdf8; margin-bottom: 8px;">CEISA 4.0 Share</h2>
        <p style="color:#94a3b8; font-size:14px;">Masukkan password untuk upload file ke folder share:</p>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-top:14px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Password" autofocus required>
            <button type="submit" class="btn" style="width:100%;">Masuk</button>
        </form>
    </div>
<?php else: ?>
    <div class="container">
        <div class="header">
            <div>
                <h1>CEISA 4.0 - Secure Share Uploader</h1>
                <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Target: <code>C:\dp\g-ceisa\share</code> &bull; Server: <code>/source/share/</code></div>
            </div>
            <a href="?logout=1" class="btn btn-sm btn-danger">Logout</a>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form id="uploadForm" method="POST" enctype="multipart/form-data">
            <div class="dropzone" id="dropArea" onclick="document.getElementById('fileInput').click()">
                <svg style="width:40px;height:40px;color:#38bdf8;margin:0 auto;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                <div style="font-size:16px; font-weight:600; color:#e2e8f0; margin-top:8px;">Klik untuk memilih file atau Drag & Drop ke sini</div>
                <p>Mendukung upload banyak file sekaligus (source code, .zip, .sql, dokumen, dll.)</p>
                <input type="file" name="files[]" id="fileInput" multiple style="display:none;" onchange="this.form.submit()">
            </div>
            <div class="checkbox-label">
                <input type="checkbox" name="unzip" id="unzip" value="1" checked>
                <label for="unzip">Otomatis ekstrak jika upload file <code>.zip</code></label>
            </div>
        </form>

        <h3 style="margin-top:32px; font-size:16px; color:#94a3b8;">Daftar File di Folder Share (<?= count($files) ?> item)</h3>
        <table>
            <thead>
                <tr>
                    <th>Nama File</th>
                    <th>Ukuran</th>
                    <th>Waktu Upload</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($files)): ?>
                    <tr><td colspan="4" style="text-align:center; color:#64748b; padding:24px;">Belum ada file di folder share.</td></tr>
                <?php else: ?>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($f['name']) ?></strong></td>
                            <td style="color:#94a3b8; font-family:monospace;"><?= $f['is_dir'] ? '[FOLDER]' : formatBytes($f['size']) ?></td>
                            <td style="color:#94a3b8; font-size:13px;"><?= date('Y-m-d H:i:s', $f['time']) ?></td>
                            <td style="text-align:right;">
                                <?php if (!$f['is_dir']): ?>
                                    <a href="?download=<?= urlencode($f['name']) ?>" class="btn btn-sm">Download</a>
                                <?php endif; ?>
                                <a href="?delete=<?= urlencode($f['name']) ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus file ini?')">Hapus</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        const drop = document.getElementById('dropArea');
        const form = document.getElementById('uploadForm');
        const input = document.getElementById('fileInput');

        ['dragenter', 'dragover'].forEach(e => {
            drop.addEventListener(e, (ev) => { ev.preventDefault(); drop.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(e => {
            drop.addEventListener(e, (ev) => { ev.preventDefault(); drop.classList.remove('dragover'); });
        });
        drop.addEventListener('drop', (ev) => {
            if (ev.dataTransfer.files.length > 0) {
                input.files = ev.dataTransfer.files;
                form.submit();
            }
        });
    </script>
<?php endif; ?>
</body>
</html>
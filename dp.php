<?php
session_start();

$passHash = hash('sha256', '1782');
$uploadDir = '/source/share/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash('sha256', (string)$_POST['password']) === $passHash) {
        $_SESSION['dp_auth'] = true;
        header('Location: ./');
        exit;
    } else {
        $error = 'Password salah!';
    }
}

$isAuth = !empty($_SESSION['dp_auth']);

// Database connection
function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $host = 'rm-d9j4584f890612j7vro.mysql.ap-southeast-5.rds.aliyuncs.com';
        $port = 63306;
        $db = 'ceisa0400db';
        $user = 'ceisarwuser';
        $pass = 'CiT8T2#h4Jd#3wer$9d36hd';
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
    }
    return $pdo;
}

if ($isAuth) {
    if (isset($_GET['download'])) {
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

    $msg = '';
    if (isset($_GET['delete'])) {
        $f = basename($_GET['delete']);
        $p = $uploadDir . $f;
        if (file_exists($p) && is_file($p)) {
            unlink($p);
            $msg = 'File ' . htmlspecialchars($f) . ' berhasil dihapus.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
        $count = count($_FILES['files']['name']);
        $uploaded = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                $orig = basename($_FILES['files']['name'][$i]);
                $target = $uploadDir . $orig;
                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $target)) {
                    $uploaded++;
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

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'files';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DP Portal - CEISA 4.0 Interface</title>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1120; color: #f1f5f9; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .navbar { background: #1e293b; border-bottom: 1px solid #334155; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .nav-brand { font-size: 18px; font-weight: 700; color: #38bdf8; display: flex; align-items: center; gap: 8px; }
        .nav-tabs { display: flex; gap: 4px; background: #0f172a; padding: 4px; border-radius: 8px; border: 1px solid #334155; }
        .nav-tab { padding: 8px 18px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; color: #94a3b8; transition: all .2s; }
        .nav-tab:hover { color: #f1f5f9; background: #1e293b; }
        .nav-tab.active { color: #fff; background: #0284c7; }
        .main-content { flex: 1; overflow: auto; padding: 24px; display: flex; flex-direction: column; }
        .container { max-width: 1000px; width: 100%; margin: 0 auto; }
        .dropzone { border: 2px dashed #475569; border-radius: 10px; padding: 36px 20px; text-align: center; background: #1e293b; cursor: pointer; transition: all .2s; }
        .dropzone:hover, .dropzone.dragover { border-color: #38bdf8; background: #0f172a; }
        .btn { display: inline-block; background: #0284c7; color: #fff; padding: 8px 16px; border-radius: 6px; text-decoration: none; border: none; cursor: pointer; font-weight: 600; font-size: 13px; }
        .btn:hover { background: #0369a1; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-success { background: #16a34a; }
        .btn-success:hover { background: #15803d; }
        .alert { padding: 10px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #065f46; color: #6ee7b7; border: 1px solid #059669; }
        .alert-danger { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }
        table.tbl { width: 100%; border-collapse: collapse; font-size: 13px; background: #1e293b; }
        table.tbl th, table.tbl td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #334155; white-space: nowrap; }
        table.tbl th { color: #94a3b8; font-weight: 600; background: #0f172a; position: sticky; top: 0; }
        table.tbl tr:hover td { background: #0f172a; }
        .login-box { max-width: 360px; margin: 80px auto; background: #1e293b; border-radius: 12px; padding: 32px; border: 1px solid #334155; text-align: center; }
        .login-box input[type="password"] { width: 100%; padding: 12px 14px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; font-size: 16px; margin: 16px 0; }
        
        /* Database View Layout */
        .db-layout { display: flex; flex: 1; height: 100%; overflow: hidden; background: #0f172a; border-radius: 8px; border: 1px solid #334155; }
        .db-sidebar { width: 280px; border-right: 1px solid #334155; background: #1e293b; display: flex; flex-direction: column; flex-shrink: 0; }
        .db-search { padding: 12px; border-bottom: 1px solid #334155; }
        .db-search input { width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; font-size: 13px; }
        .db-tables { flex: 1; overflow-y: auto; padding: 8px 0; }
        .db-table-item { display: flex; justify-content: space-between; padding: 8px 16px; color: #cbd5e1; text-decoration: none; font-size: 13px; border-left: 3px solid transparent; }
        .db-table-item:hover { background: #0f172a; color: #38bdf8; }
        .db-table-item.active { background: #0f172a; border-left-color: #38bdf8; color: #38bdf8; font-weight: 600; }
        .db-count { font-size: 11px; color: #64748b; background: #0b1120; padding: 2px 6px; border-radius: 4px; }
        .db-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #0f172a; }
        .db-topbar { padding: 12px 16px; border-bottom: 1px solid #334155; background: #1e293b; display: flex; justify-content: space-between; align-items: center; }
        .db-query-area { padding: 12px 16px; border-bottom: 1px solid #334155; background: #111827; }
        .db-query-area textarea { width: 100%; height: 75px; padding: 8px; border-radius: 6px; background: #0b1120; border: 1px solid #334155; color: #4ade80; font-family: monospace; font-size: 13px; resize: vertical; }
        .db-data-view { flex: 1; overflow: auto; }
    </style>
</head>
<body>

<?php if (!$isAuth): ?>
    <div class="login-box">
        <h2 style="color:#38bdf8; margin-bottom: 8px;">DP Portal</h2>
        <p style="color:#94a3b8; font-size:14px;">Masukkan Password:</p>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-top:14px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Password" autofocus required>
            <button type="submit" class="btn" style="width:100%; padding:12px;">Masuk</button>
        </form>
    </div>
<?php else: ?>
    <div class="navbar">
        <div class="nav-brand">
            <svg style="width:24px;height:24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            CEISA 4.0 Developer Portal
        </div>
        <div class="nav-tabs">
            <a href="?tab=files" class="nav-tab <?= $tab === 'files' ? 'active' : '' ?>">📁 File Share</a>
            <a href="?tab=db" class="nav-tab <?= $tab === 'db' ? 'active' : '' ?>">🗄️ MySQL Database (68 Tables)</a>
            <a href="?tab=import" class="nav-tab <?= $tab === 'import' ? 'active' : '' ?>">📥 Import Excel</a>
            <a href="?tab=editor" class="nav-tab <?= $tab === 'editor' ? 'active' : '' ?>">✏️ Form Editor AJU</a>
            <a href="?tab=transfer" class="nav-tab <?= $tab === 'transfer' ? 'active' : '' ?>" style="color:#38bdf8; font-weight:700;">🚀 Transfer CEISA</a>
            <a href="?tab=docs" class="nav-tab <?= $tab === 'docs' ? 'active' : '' ?>">📖 Dokumentasi API</a>
            <a href="?tab=status" class="nav-tab <?= $tab === 'status' ? 'active' : '' ?>">⚡ API Status</a>
        </div>
        <div>
            <a href="?logout=1" class="btn btn-danger btn-sm">Logout</a>
        </div>
    </div>

    <?php if ($tab === 'files'): ?>
        <div class="main-content">
            <div class="container">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h2 style="font-size:18px; color:#f1f5f9;">File Manager & Share Uploader</h2>
                    <span style="font-size:12px; color:#94a3b8;">Sync ke: <code>C:\dp\g-ceisa\share\</code></span>
                </div>

                <?php if ($msg): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <div class="dropzone" id="dropArea" onclick="document.getElementById('fileInput').click()">
                        <svg style="width:40px;height:40px;color:#38bdf8;margin:0 auto;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                        <div style="font-size:16px; font-weight:600; color:#e2e8f0; margin-top:8px;">Klik untuk memilih file atau Drag & Drop ke sini</div>
                        <p style="color:#94a3b8; font-size:13px; margin-top:4px;">Upload script PHP, .zip, .sql, atau berkas lainnya</p>
                        <input type="file" name="files[]" id="fileInput" multiple style="display:none;" onchange="this.form.submit()">
                    </div>
                    <div style="display:flex; justify-content:center; align-items:center; gap:8px; margin-top:12px; font-size:13px; color:#94a3b8;">
                        <input type="checkbox" name="unzip" id="unzip" value="1" checked>
                        <label for="unzip">Otomatis ekstrak jika upload file <code>.zip</code></label>
                    </div>
                </form>

                <h3 style="margin-top:28px; font-size:15px; color:#94a3b8;">Daftar File di Folder Share (<?= count($files) ?> item)</h3>
                <table class="tbl" style="margin-top:12px; border-radius:8px; overflow:hidden;">
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
        </div>
        <script>
            const drop = document.getElementById('dropArea');
            const form = document.getElementById('uploadForm');
            const input = document.getElementById('fileInput');
            ['dragenter', 'dragover'].forEach(e => drop.addEventListener(e, (ev) => { ev.preventDefault(); drop.classList.add('dragover'); }));
            ['dragleave', 'drop'].forEach(e => drop.addEventListener(e, (ev) => { ev.preventDefault(); drop.classList.remove('dragover'); }));
            drop.addEventListener('drop', (ev) => { if (ev.dataTransfer.files.length > 0) { input.files = ev.dataTransfer.files; form.submit(); } });
        </script>

    <?php elseif ($tab === 'db'): ?>
        <?php
        $pdo = getDb();
        $selectedTable = isset($_GET['tbl']) ? $_GET['tbl'] : 'bc_ceisa_company';
        $sqlQuery = isset($_POST['sql']) ? trim($_POST['sql']) : '';
        $queryError = '';
        $queryResult = null;
        $queryCols = [];
        $affectedRows = 0;

        // Fetch tables list
        $tblStmt = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA='ceisa0400db' ORDER BY TABLE_NAME ASC");
        $allTables = $tblStmt->fetchAll();

        // Custom SQL Execution
        if (!empty($sqlQuery)) {
            try {
                $qStmt = $pdo->query($sqlQuery);
                if ($qStmt->columnCount() > 0) {
                    $colCount = $qStmt->columnCount();
                    for ($i = 0; $i < $colCount; $i++) {
                        $meta = $qStmt->getColumnMeta($i);
                        if ($meta && isset($meta['name'])) {
                            $queryCols[] = $meta['name'];
                        }
                    }
                    $queryResult = $qStmt->fetchAll();
                    if (empty($queryCols) && !empty($queryResult)) {
                        $queryCols = array_keys($queryResult[0]);
                    }
                } else {
                    $affectedRows = $qStmt->rowCount();
                }
            } catch (Exception $e) {
                $queryError = $e->getMessage();
            }
        } else {
            // Default: browse selected table
            try {
                $page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
                $limit = 50;
                $offset = ($page - 1) * $limit;
                $qStmt = $pdo->query("SELECT * FROM `{$selectedTable}` LIMIT {$offset}, {$limit}");
                $colCount = $qStmt->columnCount();
                for ($i = 0; $i < $colCount; $i++) {
                    $meta = $qStmt->getColumnMeta($i);
                    if ($meta && isset($meta['name'])) {
                        $queryCols[] = $meta['name'];
                    }
                }
                $queryResult = $qStmt->fetchAll();
                if (empty($queryCols) && !empty($queryResult)) {
                    $queryCols = array_keys($queryResult[0]);
                }
                // Fallback for empty table
                if (empty($queryCols) && !empty($selectedTable)) {
                    $cStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA='ceisa0400db' AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
                    $cStmt->execute([$selectedTable]);
                    $queryCols = $cStmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (Exception $e) {
                $queryError = $e->getMessage();
            }
        }
        ?>
        <div style="flex:1; display:flex; padding:12px; overflow:hidden;">
            <div class="db-layout">
                <!-- Sidebar Tables -->
                <div class="db-sidebar">
                    <div class="db-search">
                        <input type="text" id="filterTbl" placeholder="Cari nama tabel..." onkeyup="filterTables()">
                    </div>
                    <div class="db-tables" id="tablesList">
                        <?php foreach ($allTables as $t): ?>
                            <a href="?tab=db&tbl=<?= urlencode($t['TABLE_NAME']) ?>" class="db-table-item <?= $selectedTable === $t['TABLE_NAME'] && empty($sqlQuery) ? 'active' : '' ?>" data-name="<?= strtolower($t['TABLE_NAME']) ?>">
                                <span><?= htmlspecialchars($t['TABLE_NAME']) ?></span>
                                <span class="db-count"><?= number_format($t['TABLE_ROWS']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Main Query and Data Area -->
                <div class="db-main">
                    <div class="db-topbar">
                        <div>
                            Tabel Aktif: <strong style="color:#38bdf8; font-size:15px;"><?= htmlspecialchars($selectedTable) ?></strong>
                            <span style="color:#64748b; font-size:12px; margin-left:8px;">(Database: ceisa0400db @ MySQL RDS)</span>
                            <?php if (!empty($queryCols)): ?>
                                <span style="background:#0f172a; color:#38bdf8; font-size:12px; font-weight:600; padding:2px 8px; border-radius:4px; border:1px solid #334155; margin-left:8px;"><?= count($queryCols) ?> Kolom</span>
                                <span style="background:#0f172a; color:#94a3b8; font-size:12px; padding:2px 8px; border-radius:4px; border:1px solid #334155; margin-left:4px;"><?= !empty($queryResult) ? count($queryResult) : 0 ?> Baris</span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <a href="?tab=db&tbl=<?= urlencode($selectedTable) ?>" class="btn btn-sm">Refresh Data</a>
                        </div>
                    </div>

                    <!-- SQL Runner Area -->
                    <form method="POST" action="?tab=db&tbl=<?= urlencode($selectedTable) ?>" class="db-query-area">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:12px; color:#94a3b8; font-weight:600;">Jalankan Query SQL Bebas (SELECT / INSERT / UPDATE / ALTER):</span>
                            <button type="submit" class="btn btn-sm btn-success">Jalankan Query (Execute)</button>
                        </div>
                        <textarea name="sql" placeholder="SELECT * FROM `<?= htmlspecialchars($selectedTable) ?>` LIMIT 50;"><?= htmlspecialchars($sqlQuery ?: "SELECT * FROM `{$selectedTable}` LIMIT 50;") ?></textarea>
                    </form>

                    <!-- Results / Errors -->
                    <div class="db-data-view">
                        <?php if ($queryError): ?>
                            <div style="padding:16px;">
                                <div class="alert alert-danger"><strong>SQL Error:</strong> <?= htmlspecialchars($queryError) ?></div>
                            </div>
                        <?php elseif ($sqlQuery && empty($queryCols)): ?>
                            <div style="padding:16px;">
                                <div class="alert alert-success">Query berhasil dieksekusi! (Baris terpengaruh: <?= $affectedRows ?>)</div>
                            </div>
                        <?php elseif (!empty($queryCols)): ?>
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th style="width:40px; color:#64748b; text-align:center;">#</th>
                                        <?php foreach ($queryCols as $col): ?>
                                            <th style="<?= $col === 'cmp_id' ? 'color:#38bdf8; font-weight:700;' : '' ?>"><?= htmlspecialchars($col) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($queryResult)): ?>
                                        <tr>
                                            <td colspan="<?= count($queryCols) + 1 ?>" style="padding:48px 20px; text-align:center; color:#64748b;">
                                                <svg style="width:36px;height:36px;margin:0 auto 10px;color:#475569;display:block;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7h16m-5 4h.01M9 11h.01"></path></svg>
                                                <div style="font-size:14px; font-weight:600; color:#94a3b8;">Tabel ini belum memiliki data baris.</div>
                                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Struktur tabel memiliki <?= count($queryCols) ?> kolom.</div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($queryResult as $idx => $row): ?>
                                            <tr>
                                                <td style="color:#64748b; text-align:center; font-family:monospace;"><?= $idx + 1 ?></td>
                                                <?php foreach ($queryCols as $col): ?>
                                                    <td style="<?= $col === 'cmp_id' ? 'font-weight:700; color:#38bdf8;' : '' ?>"><?= htmlspecialchars((string)($row[$col] ?? '')) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="padding:40px; text-align:center; color:#64748b;">
                                <div>Tidak ada hasil query.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <script>
            function filterTables() {
                var input = document.getElementById('filterTbl').value.toLowerCase();
                var items = document.getElementsByClassName('db-table-item');
                for (var i = 0; i < items.length; i++) {
                    var name = items[i].getAttribute('data-name');
                    if (name.indexOf(input) > -1) {
                        items[i].style.display = 'flex';
                    } else {
                        items[i].style.display = 'none';
                    }
                }
            }
        </script>

    <?php elseif ($tab === 'import'): ?>
        <div class="main-content" style="padding:0; flex:1;">
            <iframe src="/import_excel.php" style="width:100%; height:100%; border:none;"></iframe>
        </div>

    <?php elseif ($tab === 'editor'): ?>
        <div class="main-content" style="padding:0; flex:1;">
            <iframe src="/editor.php" style="width:100%; height:100%; border:none;"></iframe>
        </div>

    <?php elseif ($tab === 'transfer'): ?>
        <div class="main-content" style="padding:0; flex:1;">
            <iframe src="/transfer.php" style="width:100%; height:100%; border:none;"></iframe>
        </div>

    <?php elseif ($tab === 'docs'): ?>
        <div class="main-content" style="padding:0; flex:1;">
            <iframe src="/docs.php" style="width:100%; height:100%; border:none;"></iframe>
        </div>

    <?php elseif ($tab === 'status'): ?>
        <div class="main-content">
            <div class="container">
                <iframe src="/index.php" style="width:100%; height:650px; border:none; border-radius:8px;"></iframe>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>

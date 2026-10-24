<?php
/**
 * CEISA 4.0 - Standalone Single-Page Database Browser (db.php)
 * Mengadopsi Layout & Desain Resmi Portal CEISA 4.0 (Compact 10.5px, Full Theme Light & Dark)
 * Terhubung langsung ke database Alibaba Cloud RDS (ceisa0400db) tanpa perlu login 'dp'.
 */
session_start();
require_once __DIR__ . '/koneksi.php';

// Validasi Akses: Khusus Super Administrator (DP)
$isSuperAdmin = !empty($_SESSION['is_super_admin']) || (!empty($_SESSION['is_admin']) && empty($_SESSION['is_company_admin']));
$isAdmin = $isSuperAdmin;
if (!$isAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403 Forbidden</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f0f2f5;color:#262626;} .box{text-align:center;background:#fff;padding:40px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.06);max-width:400px;} h2{color:#ff4d4f;margin-bottom:12px;} p{color:#8c8c8c;font-size:12px;line-height:1.6;}</style></head><body><div class="box"><h2>403 - Akses Ditolak</h2><p>Halaman Database Browser hanya dapat diakses oleh Super Administrator (DP).</p></div></body></html>';
    exit;
}

// Validasi & Ambil Daftar Tabel
$currentDbName = !empty($database) ? $database : 'ceisa0400db';
$tablesRes = mysqli_query($koneksi, "
    SELECT TABLE_NAME, TABLE_ROWS, (DATA_LENGTH + INDEX_LENGTH) AS TABLE_SIZE 
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = '$currentDbName' 
    ORDER BY TABLE_NAME ASC
");

$allTables = [];
$totalDatabaseRows = 0;
while ($r = mysqli_fetch_assoc($tablesRes)) {
    $allTables[] = $r;
    $totalDatabaseRows += intval($r['TABLE_ROWS'] ?? 0);
}

// Tabel Aktif
$defaultTable = !empty($allTables[0]['TABLE_NAME']) ? $allTables[0]['TABLE_NAME'] : 'bc_ceisa_header';
$selectedTable = trim($_GET['tbl'] ?? $defaultTable);
$selectedTable = preg_replace('/[^a-zA-Z0-9_]/', '', $selectedTable);

$validTable = false;
foreach ($allTables as $t) {
    if ($t['TABLE_NAME'] === $selectedTable) {
        $validTable = true;
        break;
    }
}
if (!$validTable && !empty($allTables)) {
    $selectedTable = $allTables[0]['TABLE_NAME'];
}

$action = trim($_GET['action'] ?? '');

// -------------------------------------------------------------
// 1. ACTION: EXPORT CSV
// -------------------------------------------------------------
if ($action === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $selectedTable . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    $dataRes = mysqli_query($koneksi, "SELECT * FROM `{$selectedTable}` LIMIT 5000");
    if ($dataRes) {
        $first = true;
        while ($row = mysqli_fetch_assoc($dataRes)) {
            if ($first) {
                fputcsv($out, array_keys($row));
                $first = false;
            }
            fputcsv($out, array_values($row));
        }
    }
    fclose($out);
    exit;
}

// -------------------------------------------------------------
// 2. QUERY / DATA FETCHING
// -------------------------------------------------------------
$sqlQuery = trim($_POST['sql'] ?? '');
$queryError = '';
$queryCols = [];
$queryResult = [];
$affectedRows = 0;
$executionTime = 0;

$page = max(1, intval($_GET['p'] ?? 1));
$limit = intval($_GET['limit'] ?? 50);
if (!in_array($limit, [25, 50, 100, 250])) $limit = 50;
$offset = ($page - 1) * $limit;
$searchTerm = trim($_GET['q'] ?? '');

$startTime = microtime(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($sqlQuery)) {
    // Custom SQL Execution
    $res = @mysqli_query($koneksi, $sqlQuery);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    if ($res === false) {
        $queryError = mysqli_error($koneksi);
    } elseif ($res === true) {
        $affectedRows = mysqli_affected_rows($koneksi);
    } else {
        $finfo = mysqli_fetch_fields($res);
        foreach ($finfo as $fi) {
            $queryCols[] = $fi->name;
        }
        while ($row = mysqli_fetch_assoc($res)) {
            $queryResult[] = $row;
        }
        mysqli_free_result($res);
    }
} else {
    // Default Table Browse
    $descRes = mysqli_query($koneksi, "DESCRIBE `{$selectedTable}`");
    if ($descRes) {
        while ($col = mysqli_fetch_assoc($descRes)) {
            $queryCols[] = $col['Field'];
        }
    }

    $whereSql = "";
    if (!empty($searchTerm)) {
        $likeClauses = [];
        $escapedSearch = mysqli_real_escape_string($koneksi, $searchTerm);
        foreach ($queryCols as $col) {
            $likeClauses[] = "`$col` LIKE '%$escapedSearch%'";
        }
        if (!empty($likeClauses)) {
            $whereSql = "WHERE " . implode(' OR ', $likeClauses);
        }
    }

    // Count Rows
    $countSql = "SELECT COUNT(1) AS cnt FROM `{$selectedTable}` $whereSql";
    $cRes = mysqli_query($koneksi, $countSql);
    $totalTableRows = ($cRes && $cr = mysqli_fetch_assoc($cRes)) ? intval($cr['cnt']) : 0;

    // Fetch Rows
    $dataSql = "SELECT * FROM `{$selectedTable}` $whereSql LIMIT {$limit} OFFSET {$offset}";
    $dRes = mysqli_query($koneksi, $dataSql);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    if ($dRes) {
        while ($row = mysqli_fetch_assoc($dRes)) {
            $queryResult[] = $row;
        }
    } else {
        $queryError = mysqli_error($koneksi);
    }
}

$totalPages = max(1, ceil(($totalTableRows ?? count($queryResult)) / $limit));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Browser (ceisa0400db) - CEISA 4.0</title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
        const isEmbeddedMode = (window.self !== window.top) || (new URLSearchParams(window.location.search).get('embedded') === '1');
        if (isEmbeddedMode) {
            document.documentElement.classList.add('is-embedded');
        }
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-sidebar: #ffffff;
            --bg-input: #ffffff;
            --border: #e8e8e8;
            --border-light: #f0f0f0;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-light: #e6f7ff;
            --accent: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --code-bg: #fafafa;
            --table-head-bg: #fafafa;
            color-scheme: light;
        }

        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-sidebar: #1f1f1f;
            --bg-input: #141414;
            --border: #303030;
            --border-light: #262626;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --primary-light: #111d2c;
            --accent: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --code-bg: #141414;
            --table-head-bg: #1d1d1d;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 10.5px;
            line-height: 1.45;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Top Bar */
        .db-header {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 4px 12px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            gap: 10px;
        }
        .db-header-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .db-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .db-header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Main Container 2-Column */
        .db-layout {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        /* Sidebar Tables (Left) */
        .db-sidebar {
            width: 230px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .db-search-box {
            padding: 6px 8px;
            border-bottom: 1px solid var(--border);
        }
        .db-search-input {
            width: 100%;
            height: 24px;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 0 6px;
            font-size: 10px;
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .db-search-input:focus { border-color: var(--primary); }
        .db-tables-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px;
        }
        .db-table-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 8px;
            border-radius: 2px;
            color: var(--text-main);
            text-decoration: none;
            font-size: 10px;
            margin-bottom: 2px;
            transition: all 0.15s;
        }
        .db-table-item:hover {
            background: rgba(24, 144, 255, 0.08);
            color: var(--primary);
        }
        .db-table-item.active {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
        }
        .db-table-item.active .badge-count {
            background: rgba(255, 255, 255, 0.25);
            color: #fff;
        }
        .badge-count {
            font-size: 9px;
            padding: 1px 5px;
            border-radius: 10px;
            background: var(--code-bg);
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-family: monospace;
        }

        /* Content Area (Right) */
        .db-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--bg-base);
            padding: 8px 10px;
            gap: 6px;
        }

        /* Action & Filter Strip */
        .db-strip {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 5px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px;
            flex-shrink: 0;
        }
        .db-strip-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tbl-badge {
            font-size: 12px;
            font-weight: 700;
            color: var(--primary);
            font-family: monospace;
        }
        .chip-info {
            font-size: 9.5px;
            padding: 1px 6px;
            border-radius: 2px;
            background: var(--code-bg);
            border: 1px solid var(--border);
            color: var(--text-muted);
        }

        /* Buttons */
        .btn-act {
            height: 24px;
            padding: 0 8px;
            font-size: 10px;
            font-weight: 600;
            border-radius: 2px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            transition: all 0.2s;
            font-family: inherit;
        }
        .btn-act:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
        .btn-act-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .btn-act-primary:hover {
            background: var(--primary-hover);
            color: #fff;
        }
        .btn-act-success {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        /* SQL Runner Drawer / Box */
        .sql-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 6px 8px;
            display: none;
            flex-shrink: 0;
        }
        .sql-textarea {
            width: 100%;
            height: 60px;
            background: var(--code-bg);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 6px;
            font-family: 'Consolas', monospace;
            font-size: 10px;
            color: var(--text-main);
            outline: none;
            resize: vertical;
        }
        .sql-textarea:focus { border-color: var(--primary); }

        /* Datagrid Table Area */
        .db-grid-container {
            flex: 1;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            overflow: auto;
            position: relative;
        }
        .db-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
            white-space: nowrap;
        }
        .db-table th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            padding: 4px 8px;
            font-weight: 600;
            text-align: left;
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            z-index: 2;
        }
        .db-table td {
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border-light);
            padding: 3.5px 8px;
            color: var(--text-main);
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .db-table tbody tr:hover td {
            background: rgba(24, 144, 255, 0.05);
        }
        .col-idx {
            width: 35px;
            text-align: center;
            font-family: monospace;
            color: var(--text-muted);
            background: var(--table-head-bg);
        }
        .val-null {
            color: var(--text-muted);
            font-style: italic;
            font-size: 9.5px;
        }

        /* Footer Pagination */
        .db-footer {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 4px 8px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            font-size: 10px;
        }
        .pagination-btns {
            display: flex;
            gap: 4px;
            align-items: center;
        }
    </style>
</head>
<body data-theme="light">

    <!-- Top Header -->
    <header class="db-header">
        <div class="db-header-left">
            <span style="font-size:16px;">🗄️</span>
            <div class="db-title">Database Browser &middot; <code>ceisa0400db</code></div>
            <span class="chip-info"><?= count($allTables) ?> Tabel Terdaftar</span>
            <span class="chip-info"><?= number_format($totalDatabaseRows) ?> Total Baris</span>
        </div>
        <div class="db-header-actions">
            <button type="button" class="btn-act" onclick="toggleSqlBox()">💻 SQL Query Runner</button>
            <a href="db.php?tbl=<?= urlencode($selectedTable) ?>&action=export" class="btn-act" title="Export tabel ini ke format CSV">📥 Export CSV</a>
            <a href="db.php?tbl=<?= urlencode($selectedTable) ?>" class="btn-act" title="Muat ulang data tabel">🔄 Refresh</a>
        </div>
    </header>

    <!-- Main 2-Column Layout -->
    <div class="db-layout">

        <!-- Sidebar Tables (Left) -->
        <aside class="db-sidebar">
            <div class="db-search-box">
                <input type="text" id="filterTbl" class="db-search-input" placeholder="🔍 Cari nama tabel..." onkeyup="filterTables()">
            </div>
            <div class="db-tables-list" id="tablesList">
                <?php foreach ($allTables as $t): ?>
                    <a href="db.php?tbl=<?= urlencode($t['TABLE_NAME']) ?>" 
                       class="db-table-item <?= ($selectedTable === $t['TABLE_NAME'] && empty($sqlQuery)) ? 'active' : '' ?>"
                       data-name="<?= strtolower($t['TABLE_NAME']) ?>">
                        <span><?= htmlspecialchars($t['TABLE_NAME']) ?></span>
                        <span class="badge-count"><?= number_format(intval($t['TABLE_ROWS'] ?? 0)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <!-- Content Area (Right) -->
        <main class="db-content">

            <!-- Strip Informasi & Filter -->
            <div class="db-strip">
                <div class="db-strip-left">
                    <span class="tbl-badge"><?= htmlspecialchars($selectedTable) ?></span>
                    <span class="chip-info"><?= count($queryCols) ?> Kolom</span>
                    <span class="chip-info"><?= !empty($queryResult) ? number_format(count($queryResult)) : 0 ?> Data Dimuat</span>
                    <span class="chip-info">⚡ <?= $executionTime ?> ms</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <form method="GET" action="db.php" style="display:flex; gap:4px; align-items:center;">
                        <input type="hidden" name="tbl" value="<?= htmlspecialchars($selectedTable) ?>">
                        <input type="text" name="q" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Filter di tabel ini..." class="db-search-input" style="width:180px;">
                        <select name="limit" onchange="this.form.submit()" class="db-search-input" style="width:75px;">
                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25 / hal</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 / hal</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100 / hal</option>
                            <option value="250" <?= $limit == 250 ? 'selected' : '' ?>>250 / hal</option>
                        </select>
                        <button type="submit" class="btn-act">Cari</button>
                        <?php if (!empty($searchTerm)): ?>
                            <a href="db.php?tbl=<?= urlencode($selectedTable) ?>" class="btn-act">Reset</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- SQL Query Box (Collapsible) -->
            <div class="sql-box" id="sqlBox">
                <form method="POST" action="db.php?tbl=<?= urlencode($selectedTable) ?>">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <span style="font-size:10px; font-weight:600; color:var(--text-muted);">SQL Query Console (SELECT / SHOW / DESCRIBE):</span>
                        <div style="display:flex; gap:4px;">
                            <button type="button" class="btn-act" onclick="setPreset('SELECT * FROM `<?= htmlspecialchars($selectedTable) ?>` LIMIT 50;')">SELECT 50</button>
                            <button type="button" class="btn-act" onclick="setPreset('DESCRIBE `<?= htmlspecialchars($selectedTable) ?>`;')">DESCRIBE</button>
                            <button type="submit" class="btn-act btn-act-primary">▶ Jalankan Query</button>
                        </div>
                    </div>
                    <textarea name="sql" id="sqlEditor" class="sql-textarea"><?= htmlspecialchars($sqlQuery ?: "SELECT * FROM `{$selectedTable}` LIMIT 50;") ?></textarea>
                </form>
            </div>

            <!-- Error Banner -->
            <?php if (!empty($queryError)): ?>
                <div style="background:rgba(255, 77, 79, 0.15); border:1px solid #ff4d4f; border-radius:3px; padding:6px 10px; color:#ff4d4f; font-size:10px;">
                    <strong>SQL Error:</strong> <?= htmlspecialchars($queryError) ?>
                </div>
            <?php endif; ?>

            <!-- Affected Rows Banner -->
            <?php if ($sqlQuery && empty($queryCols) && empty($queryError)): ?>
                <div style="background:rgba(82, 196, 26, 0.15); border:1px solid #52c41a; border-radius:3px; padding:6px 10px; color:#52c41a; font-size:10px;">
                    <strong>Query Berhasil!</strong> Baris terpengaruh: <?= $affectedRows ?> baris.
                </div>
            <?php endif; ?>

            <!-- Datagrid Table Area -->
            <div class="db-grid-container">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th class="col-idx">#</th>
                            <?php foreach ($queryCols as $col): ?>
                                <th style="<?= $col === 'cmp_id' || $col === 'nomor_aju' ? 'color:var(--primary);' : '' ?>">
                                    <?= htmlspecialchars($col) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($queryResult)): ?>
                            <tr>
                                <td colspan="<?= count($queryCols) + 1 ?>" style="text-align:center; padding:40px; color:var(--text-muted);">
                                    <div style="font-size:18px; margin-bottom:6px;">📭</div>
                                    <div>Tabel atau hasil query ini tidak memiliki baris data.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($queryResult as $idx => $row): ?>
                                <tr>
                                    <td class="col-idx"><?= $offset + $idx + 1 ?></td>
                                    <?php foreach ($queryCols as $col): ?>
                                        <?php 
                                            $val = $row[$col] ?? null; 
                                            $isKey = ($col === 'nomor_aju' || $col === 'cmp_id');
                                        ?>
                                        <td style="<?= $isKey ? 'font-weight:600; color:var(--primary);' : '' ?>" title="<?= htmlspecialchars((string)($val ?? '')) ?>">
                                            <?php if ($val === null): ?>
                                                <span class="val-null">NULL</span>
                                            <?php elseif ($val === ''): ?>
                                                <span class="val-null">""</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars((string)$val) ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <footer class="db-footer">
                <div style="color:var(--text-muted);">
                    Menampilkan baris <strong><?= count($queryResult) > 0 ? $offset + 1 : 0 ?></strong> - <strong><?= $offset + count($queryResult) ?></strong>
                    <?php if (isset($totalTableRows)): ?>
                        dari <strong><?= number_format($totalTableRows) ?></strong> total baris
                    <?php endif; ?>
                </div>
                <div class="pagination-btns">
                    <?php if ($page > 1): ?>
                        <a href="db.php?tbl=<?= urlencode($selectedTable) ?>&p=<?= $page - 1 ?>&limit=<?= $limit ?>&q=<?= urlencode($searchTerm) ?>" class="btn-act">‹ Prev</a>
                    <?php endif; ?>
                    <span style="font-size:10px; color:var(--text-muted); padding:0 4px;">Halaman <?= $page ?> / <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="db.php?tbl=<?= urlencode($selectedTable) ?>&p=<?= $page + 1 ?>&limit=<?= $limit ?>&q=<?= urlencode($searchTerm) ?>" class="btn-act">Next ›</a>
                    <?php endif; ?>
                </div>
            </footer>

        </main>
    </div>

    <script>
        function filterTables() {
            const q = document.getElementById('filterTbl').value.toLowerCase().trim();
            document.querySelectorAll('#tablesList .db-table-item').forEach(item => {
                const name = item.getAttribute('data-name');
                item.style.display = name.includes(q) ? 'flex' : 'none';
            });
        }

        function toggleSqlBox() {
            const b = document.getElementById('sqlBox');
            b.style.display = (b.style.display === 'none' || b.style.display === '') ? 'block' : 'none';
            if (b.style.display === 'block') {
                document.getElementById('sqlEditor').focus();
            }
        }

        function setPreset(sql) {
            document.getElementById('sqlEditor').value = sql;
        }

        // Theme Sync Engine
        function setTheme(theme) {
            if (theme !== 'light' && theme !== 'dark') theme = 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.body.setAttribute('data-theme', theme);
            localStorage.setItem('ceisa_theme', theme);
        }
        let savedTheme = localStorage.getItem('ceisa_theme');
        if (savedTheme !== 'light' && savedTheme !== 'dark') savedTheme = 'light';
        setTheme(savedTheme);

        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                setTheme(e.data.theme);
            }
        });
    </script>
</body>
</html>

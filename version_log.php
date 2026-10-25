<?php
/**
 * CEISA 4.0 - Log Update Versi Aplikasi (Version Changelog & History)
 * Menampilkan dan mengelola riwayat pembaruan, perbaikan bug, dan optimasi sistem.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';

// Pastikan sesi login aktif
if (empty($_SESSION['username'])) {
    if (isset($_GET['action']) || isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'Sesi login telah berakhir. Silakan login kembali.']);
        exit;
    }
    header('Location: index.php');
    exit;
}

$sessionUser    = $_SESSION['username'] ?? '';
$sessionRole    = $_SESSION['role'] ?? 'user';
$isSuperAdmin   = !empty($_SESSION['is_super_admin']) || (!empty($_SESSION['is_admin']) && empty($_SESSION['role']));
$isAdmin        = $isSuperAdmin;

$themeParam     = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// Pastikan tabel bc_app_version_log selalu ada
$sqlCreateTable = "CREATE TABLE IF NOT EXISTS `bc_app_version_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `version` VARCHAR(30) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'Update',
    `release_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `changes` TEXT NOT NULL,
    `files_modified` VARCHAR(500) NULL,
    `author` VARCHAR(50) NOT NULL DEFAULT 'Super Administrator',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_version` (`version`),
    KEY `idx_rel_date` (`release_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$koneksi->query($sqlCreateTable);

// -------------------------------------------------------------
// BACKEND API HANDLERS
// -------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $q        = trim($_GET['q'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $sort     = trim($_GET['sort'] ?? 'desc'); // desc / asc

    $where = [];
    if (!empty($q)) {
        $escQ = $koneksi->real_escape_string($q);
        $where[] = "(version LIKE '%$escQ%' OR title LIKE '%$escQ%' OR changes LIKE '%$escQ%' OR files_modified LIKE '%$escQ%' OR author LIKE '%$escQ%')";
    }
    if (!empty($category)) {
        $escCat = $koneksi->real_escape_string($category);
        $where[] = "category = '$escCat'";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderDir = (strtolower($sort) === 'asc') ? 'ASC' : 'DESC';

    $sql = "SELECT id, version, title, category, release_date, changes, files_modified, author, created_at 
            FROM bc_app_version_log 
            $whereSql 
            ORDER BY release_date $orderDir, id $orderDir";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }

    $activeVersion = getAppUpdateVersion($koneksi);
    $latestRelease = !empty($data) ? $data[0]['release_date'] : date('Y-m-d H:i:s');

    echo json_encode([
        'status'         => 'OK',
        'data'           => $data,
        'count'          => count($data),
        'active_version' => $activeVersion,
        'latest_release' => $latestRelease,
        'can_edit'       => $isAdmin
    ]);
    exit;
}

if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Super Administrator yang berhak mencatat atau mengubah riwayat versi.']);
        exit;
    }

    $id            = intval($_POST['id'] ?? 0);
    $version       = trim($_POST['version'] ?? '');
    $title         = trim($_POST['title'] ?? '');
    $category      = trim($_POST['category'] ?? 'Update');
    $release_date  = trim($_POST['release_date'] ?? date('Y-m-d H:i:s'));
    $changes       = trim($_POST['changes'] ?? '');
    $files_modified= trim($_POST['files_modified'] ?? '');
    $author        = trim($_POST['author'] ?? ($sessionUser ?: 'Super Administrator'));
    $set_active    = !empty($_POST['set_active']);

    if (empty($version)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor versi wajib diisi (contoh: ' . date('Ymd.Hi') . ').']);
        exit;
    }
    if (empty($title)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Judul pembaruan wajib diisi.']);
        exit;
    }
    if (empty($changes)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Rincian perubahan atau solusi wajib diisi.']);
        exit;
    }

    $escVer   = $koneksi->real_escape_string($version);
    $escTitle = $koneksi->real_escape_string($title);
    $escCat   = $koneksi->real_escape_string($category);
    $escRel   = $koneksi->real_escape_string($release_date);
    $escChg   = $koneksi->real_escape_string($changes);
    $escFiles = $koneksi->real_escape_string($files_modified);
    $escAuth  = $koneksi->real_escape_string($author);

    if ($id > 0) {
        // Edit Existing
        $sql = "UPDATE bc_app_version_log SET 
                    version = '$escVer',
                    title = '$escTitle',
                    category = '$escCat',
                    release_date = '$escRel',
                    changes = '$escChg',
                    files_modified = '$escFiles',
                    author = '$escAuth'
                WHERE id = $id LIMIT 1";
        $ok = $koneksi->query($sql);
        if (!$ok) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui log versi: ' . $koneksi->error]);
            exit;
        }
    } else {
        // Insert New
        $sql = "INSERT INTO bc_app_version_log (version, title, category, release_date, changes, files_modified, author, created_at)
                VALUES ('$escVer', '$escTitle', '$escCat', '$escRel', '$escChg', '$escFiles', '$escAuth', NOW())
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title),
                    category = VALUES(category),
                    release_date = VALUES(release_date),
                    changes = VALUES(changes),
                    files_modified = VALUES(files_modified),
                    author = VALUES(author)";
        $ok = $koneksi->query($sql);
        if (!$ok) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan log versi: ' . $koneksi->error]);
            exit;
        }
    }

    // Set sebagai versi aktif jika dicentang atau jika ini versi terbaru
    if ($set_active) {
        $vf = __DIR__ . '/version.txt';
        @file_put_contents($vf, $version);
    }

    echo json_encode([
        'status'  => 'OK',
        'message' => "Versi $version berhasil disimpan dan diperbarui!",
        'version' => $version
    ]);
    exit;
}

if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Super Administrator yang berhak menghapus log versi.']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID log versi tidak valid.']);
        exit;
    }

    $koneksi->query("DELETE FROM bc_app_version_log WHERE id = $id LIMIT 1");
    
    // Perbarui version.txt dengan versi terakhir yang tersisa
    $resLatest = $koneksi->query("SELECT version FROM bc_app_version_log ORDER BY release_date DESC, id DESC LIMIT 1");
    if ($resLatest && $rowLatest = $resLatest->fetch_assoc()) {
        $latestVer = trim($rowLatest['version']);
        if (!empty($latestVer)) {
            @file_put_contents(__DIR__ . '/version.txt', $latestVer);
        }
    }

    echo json_encode(['status' => 'OK', 'message' => 'Log versi berhasil dihapus.']);
    exit;
}

$currentSystemVersion = getAppUpdateVersion($koneksi);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Update Versi Aplikasi - CEISA 4.0</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-active: #096dd9;
            --bg-body: <?= ($themeParam === 'dark') ? '#141414' : '#f0f2f5' ?>;
            --bg-card: <?= ($themeParam === 'dark') ? '#1f1f1f' : '#ffffff' ?>;
            --bg-card-alt: <?= ($themeParam === 'dark') ? '#262626' : '#fafafa' ?>;
            --border-color: <?= ($themeParam === 'dark') ? '#303030' : '#e8e8e8' ?>;
            --border-color-light: <?= ($themeParam === 'dark') ? '#262626' : '#f0f0f0' ?>;
            --text-heading: <?= ($themeParam === 'dark') ? '#e6e6e6' : '#262626' ?>;
            --text-body: <?= ($themeParam === 'dark') ? '#d9d9d9' : '#595959' ?>;
            --text-muted: <?= ($themeParam === 'dark') ? '#8c8c8c' : '#8c8c8c' ?>;
            --input-bg: <?= ($themeParam === 'dark') ? '#262626' : '#ffffff' ?>;
            --input-border: <?= ($themeParam === 'dark') ? '#434343' : '#d9d9d9' ?>;
            --badge-bg: <?= ($themeParam === 'dark') ? '#111b26' : '#e6f7ff' ?>;
            --badge-border: <?= ($themeParam === 'dark') ? '#15395b' : '#91d5ff' ?>;
            --badge-text: <?= ($themeParam === 'dark') ? '#1890ff' : '#096dd9' ?>;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-body);
            color: var(--text-body);
            font-size: 13px;
            line-height: 1.5;
            padding: 14px;
        }

        .container {
            max-width: 1180px;
            margin: 0 auto;
        }

        /* CARD HEADER & BANNER */
        .version-header-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 16px 20px;
            margin-bottom: 14px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .version-header-info h2 {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .version-header-info p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
        }

        .version-chips {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .version-chip {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 4px 10px;
            font-size: 11.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .version-chip.active {
            background: var(--badge-bg);
            border-color: var(--badge-border);
            color: var(--badge-text);
            font-weight: 700;
        }

        .version-chip .chip-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        /* CONTROLS & FILTER TOOLBAR */
        .toolbar-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-input {
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 4px;
            padding: 5px 10px;
            font-size: 12px;
            color: var(--text-heading);
            min-width: 240px;
            outline: none;
            transition: all 0.2s;
        }

        .filter-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }

        .filter-select {
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 4px;
            padding: 5px 10px;
            font-size: 12px;
            color: var(--text-heading);
            outline: none;
            cursor: pointer;
        }

        .filter-select:focus {
            border-color: var(--primary);
        }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        .btn-default {
            background: var(--bg-card);
            border-color: var(--input-border);
            color: var(--text-heading);
        }
        .btn-default:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-danger-outline {
            background: transparent;
            border-color: #ff4d4f;
            color: #ff4d4f;
        }
        .btn-danger-outline:hover {
            background: #ff4d4f;
            color: #ffffff;
        }

        .btn-sm {
            padding: 3px 8px;
            font-size: 11px;
            border-radius: 3px;
        }

        /* VIEW TOGGLE (TIMELINE VS TABLE) */
        .view-toggle {
            display: inline-flex;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        .view-toggle-btn {
            background: var(--bg-card);
            border: none;
            padding: 5px 10px;
            font-size: 11px;
            cursor: pointer;
            color: var(--text-muted);
            font-weight: 600;
        }
        .view-toggle-btn.active {
            background: var(--primary);
            color: #ffffff;
        }

        /* TIMELINE CARDS */
        .timeline-container {
            position: relative;
            padding-left: 28px;
            margin-top: 10px;
        }

        .timeline-container::before {
            content: '';
            position: absolute;
            left: 11px;
            top: 10px;
            bottom: 20px;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }

        .timeline-node {
            position: absolute;
            left: -28px;
            top: 14px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--bg-card);
            border: 2px solid var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--primary);
            box-shadow: 0 0 0 3px var(--bg-body);
            z-index: 2;
        }

        .timeline-item.is-current .timeline-node {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.25);
        }

        .version-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .version-card:hover {
            border-color: var(--primary-hover);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .version-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--border-color);
            margin-bottom: 12px;
        }

        .card-head-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .version-badge {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
            font-size: 13px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            background: var(--badge-bg);
            border: 1px solid var(--badge-border);
            color: var(--badge-text);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .category-tag {
            font-size: 10.5px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .category-feature     { background: rgba(82, 196, 26, 0.15); color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.3); }
        .category-bugfix      { background: rgba(255, 77, 79, 0.15); color: #ff4d4f; border: 1px solid rgba(255, 77, 79, 0.3); }
        .category-performance { background: rgba(114, 46, 209, 0.15); color: #722ed1; border: 1px solid rgba(114, 46, 209, 0.3); }
        .category-uiux        { background: rgba(19, 194, 194, 0.15); color: #13c2c2; border: 1px solid rgba(19, 194, 194, 0.3); }
        .category-update      { background: rgba(24, 144, 255, 0.15); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.3); }
        .category-security    { background: rgba(250, 140, 22, 0.15); color: #fa8c16; border: 1px solid rgba(250, 140, 22, 0.3); }

        .version-title {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-heading);
            margin-top: 4px;
        }

        .card-head-right {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 11.5px;
            color: var(--text-muted);
        }

        .version-card-body {
            font-size: 12.5px;
            color: var(--text-body);
            line-height: 1.6;
        }

        .version-changes-box {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color-light);
            border-radius: 4px;
            padding: 10px 14px;
            margin-top: 8px;
            white-space: pre-wrap;
            font-family: inherit;
        }

        .version-card-footer {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .file-chips-wrap {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .file-chip {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 1px 6px;
            font-size: 10.5px;
            font-family: 'SFMono-Regular', Consolas, monospace;
            color: var(--text-heading);
        }

        /* TABLE VIEW */
        .table-responsive {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow-x: auto;
            display: none;
        }

        .version-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .version-table th {
            background: var(--bg-card-alt);
            padding: 10px 12px;
            text-align: left;
            font-weight: 700;
            color: var(--text-heading);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .version-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color-light);
            vertical-align: top;
        }

        .version-table tr:hover td {
            background: var(--bg-card-alt);
        }

        /* MODAL STYLES */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-dialog {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            width: 100%;
            max-width: 650px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            max-height: 90vh;
            animation: modalFadeIn 0.2s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-heading);
            margin: 0;
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 16px;
            color: var(--text-muted);
            cursor: pointer;
        }
        .modal-close:hover {
            color: var(--text-heading);
        }

        .modal-body {
            padding: 16px 18px;
            overflow-y: auto;
            flex: 1;
        }

        .form-row {
            margin-bottom: 12px;
        }

        .form-row-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 12px;
            color: var(--text-heading);
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }

        textarea.form-control {
            min-height: 110px;
            resize: vertical;
            font-family: inherit;
        }

        .modal-footer {
            padding: 12px 18px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: var(--bg-card-alt);
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
        }
        .empty-state i {
            font-size: 36px;
            margin-bottom: 10px;
            color: var(--border-color);
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($themeParam) ?>">

<div class="container">

    <!-- 1. HEADER BANNER -->
    <div class="version-header-card">
        <div class="version-header-info">
            <h2>
                <span>📜 Log Update Versi Aplikasi</span>
            </h2>
            <p>Catatan riwayat pembaruan, perbaikan bug, optimasi performa, dan rilis fitur CEISA 4.0</p>
        </div>
        <div class="version-chips">
            <div class="version-chip active" title="Versi Aktif Sistem yang sedang berjalan">
                <span class="chip-label">Versi Aktif:</span>
                <span id="headerActiveVersion"><strong><?= htmlspecialchars($currentSystemVersion) ?></strong></span>
            </div>
            <div class="version-chip">
                <span class="chip-label">Total Rilis:</span>
                <strong id="headerTotalRelease">0</strong>
            </div>
            <div class="version-chip">
                <span class="chip-label">Terakhir:</span>
                <span id="headerLatestDate">-</span>
            </div>
        </div>
    </div>

    <!-- 2. TOOLBAR & CONTROLS -->
    <div class="toolbar-panel">
        <div class="filter-group">
            <input type="text" id="searchFilter" class="filter-input" placeholder="🔍 Cari versi, judul, berkas, atau solusi..." oninput="debounceSearch()">
            
            <select id="categoryFilter" class="filter-select" onchange="loadVersionLogs()">
                <option value="">Semua Kategori</option>
                <option value="Feature">Feature (Fitur Baru)</option>
                <option value="Bug Fix">Bug Fix (Perbaikan)</option>
                <option value="Performance">Performance (Optimasi)</option>
                <option value="UI/UX">UI/UX (Tampilan)</option>
                <option value="Update">Update (Umum)</option>
                <option value="Security">Security (Keamanan)</option>
            </select>

            <select id="sortFilter" class="filter-select" onchange="loadVersionLogs()">
                <option value="desc">Terbaru Dahulu</option>
                <option value="asc">Terlama Dahulu</option>
            </select>

            <button type="button" class="btn btn-default btn-sm" onclick="loadVersionLogs()" title="Muat Ulang Data">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>

        <div style="display:flex; align-items:center; gap:8px;">
            <!-- View Toggle -->
            <div class="view-toggle">
                <button type="button" class="view-toggle-btn active" id="btnViewTimeline" onclick="setViewMode('timeline')" title="Tampilan Kartu Timeline">
                    <i class="fa-solid fa-timeline"></i> Timeline
                </button>
                <button type="button" class="view-toggle-btn" id="btnViewTable" onclick="setViewMode('table')" title="Tampilan Tabel">
                    <i class="fa-solid fa-table-list"></i> Tabel
                </button>
            </div>

            <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openAddVersionModal()">
                <i class="fa-solid fa-plus"></i> Catat Versi Baru
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3. VIEW 1: TIMELINE CARDS VIEW -->
    <div class="timeline-container" id="timelineView">
        <!-- Populated via JS -->
    </div>

    <!-- 4. VIEW 2: TABLE VIEW -->
    <div class="table-responsive" id="tableView">
        <table class="version-table">
            <thead>
                <tr>
                    <th style="width: 50px; text-align: center;">#</th>
                    <th style="width: 130px;">Versi</th>
                    <th style="width: 140px;">Rilis</th>
                    <th style="width: 110px;">Kategori</th>
                    <th>Judul Pembaruan & Rincian Yang Dirubah / Disolve</th>
                    <th style="width: 180px;">Berkas Dimodifikasi</th>
                    <th style="width: 120px;">Author</th>
                    <?php if ($isAdmin): ?>
                    <th style="width: 90px; text-align: center;">Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="tableBody">
                <!-- Populated via JS -->
            </tbody>
        </table>
    </div>

    <div class="empty-state" id="emptyState" style="display:none;">
        <i class="fa-regular fa-clipboard"></i>
        <div style="font-weight:600; font-size:14px; color:var(--text-heading); margin-bottom:4px;">Tidak Ada Log Versi Ditemukan</div>
        <div style="font-size:12px;">Coba ubah kata kunci pencarian atau filter kategori Anda.</div>
    </div>

</div>

<!-- 5. MODAL FORM: TAMBAH / EDIT VERSI (ADMIN ONLY) -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="versionModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 id="modalTitle">➕ Catat Pembaruan Versi Baru</h3>
            <button type="button" class="modal-close" onclick="closeVersionModal()">&times;</button>
        </div>
        <form id="versionForm" onsubmit="saveVersion(event)">
            <input type="hidden" id="formId" value="0">
            <div class="modal-body">
                <div class="form-row-grid">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                            <label class="form-label" style="margin:0;">Nomor Versi:</label>
                            <a href="javascript:void(0)" onclick="generateNewVersionNumber()" style="font-size:10.5px; color:var(--primary); text-decoration:none; font-weight:600;" title="Generate nomor versi sesuai timestamp saat ini">
                                ⚡ Timestamp Ini
                            </a>
                        </div>
                        <input type="text" id="formVersion" class="form-control" required placeholder="YYYYMMDD.HHmm (misal: <?= date('Ymd.Hi') ?>)">
                    </div>
                    <div>
                        <label class="form-label">Kategori Pembaruan:</label>
                        <select id="formCategory" class="form-control">
                            <option value="Update">Update (Pembaruan Umum)</option>
                            <option value="Feature">Feature (Rilis Fitur Baru)</option>
                            <option value="Bug Fix">Bug Fix (Perbaikan Kendala / Error)</option>
                            <option value="Performance">Performance (Optimasi Kecepatan)</option>
                            <option value="UI/UX">UI/UX (Penyempurnaan Tampilan)</option>
                            <option value="Security">Security (Keamanan & Autentikasi)</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-grid">
                    <div>
                        <label class="form-label">Waktu Rilis:</label>
                        <input type="datetime-local" id="formReleaseDate" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Author / Pembuat:</label>
                        <input type="text" id="formAuthor" class="form-control" value="Super Administrator" required>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Judul Pembaruan (Ringkasan):</label>
                    <input type="text" id="formTitleText" class="form-control" required placeholder="Contoh: Pindahkan Tombol Set H2H ke Menu Klik Kanan & Urutkan Dokumen">
                </div>

                <div class="form-row">
                    <label class="form-label">Rincian Perubahan / Yang Disolve (Mendukung Bullet Point):</label>
                    <textarea id="formChanges" class="form-control" required placeholder="• Masalah yang diselesaikan...&#10;• Fitur yang ditambahkan...&#10;• Perilaku baru yang disesuaikan..."></textarea>
                </div>

                <div class="form-row">
                    <label class="form-label">Berkas yang Dimodifikasi (Pisahkan dengan koma):</label>
                    <input type="text" id="formFiles" class="form-control" placeholder="Contoh: transfer.php, editor.php, daftar_dokumen.php">
                </div>

                <div class="form-row" style="margin-top:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:600; color:var(--text-heading);">
                        <input type="checkbox" id="formSetActive" checked>
                        <span>Jadikan sebagai versi aktif sistem saat ini (perbarui version.txt)</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeVersionModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitForm">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Catatan Versi
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    let rawVersionData = [];
    let activeSystemVersion = '<?= htmlspecialchars($currentSystemVersion) ?>';
    let currentViewMode = localStorage.getItem('ceisa_version_view_mode') || 'timeline';
    let searchTimeout = null;

    document.addEventListener('DOMContentLoaded', () => {
        setViewMode(currentViewMode, false);
        loadVersionLogs();
    });

    function setViewMode(mode, savePref = true) {
        currentViewMode = mode;
        if (savePref) {
            localStorage.setItem('ceisa_version_view_mode', mode);
        }
        const tlBtn = document.getElementById('btnViewTimeline');
        const tbBtn = document.getElementById('btnViewTable');
        const tlView = document.getElementById('timelineView');
        const tbView = document.getElementById('tableView');

        if (mode === 'table') {
            tlBtn.classList.remove('active');
            tbBtn.classList.add('active');
            tlView.style.display = 'none';
            tbView.style.display = 'block';
        } else {
            tbBtn.classList.remove('active');
            tlBtn.classList.add('active');
            tbView.style.display = 'none';
            tlView.style.display = 'block';
        }
    }

    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            loadVersionLogs();
        }, 300);
    }

    async function loadVersionLogs() {
        const q = document.getElementById('searchFilter').value.trim();
        const cat = document.getElementById('categoryFilter').value;
        const sort = document.getElementById('sortFilter').value;

        const tlView = document.getElementById('timelineView');
        const tbBody = document.getElementById('tableBody');
        const emptyState = document.getElementById('emptyState');

        try {
            const res = await fetch(`version_log.php?action=list&q=${encodeURIComponent(q)}&category=${encodeURIComponent(cat)}&sort=${encodeURIComponent(sort)}&_t=${Date.now()}`);
            const json = await res.json();

            if (json.status !== 'OK') {
                throw new Error(json.message || 'Gagal mengambil data versi');
            }

            rawVersionData = json.data || [];
            activeSystemVersion = json.active_version || activeSystemVersion;

            // Update stats
            document.getElementById('headerActiveVersion').innerHTML = `<strong>${escapeHtml(activeSystemVersion)}</strong>`;
            document.getElementById('headerTotalRelease').innerText = json.count || 0;
            if (json.latest_release) {
                document.getElementById('headerLatestDate').innerText = formatDateTime(json.latest_release);
            }

            if (rawVersionData.length === 0) {
                tlView.innerHTML = '';
                tbBody.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            renderTimelineView(rawVersionData);
            renderTableView(rawVersionData);

        } catch (err) {
            console.error(err);
            tlView.innerHTML = `<div style="color:#ff4d4f; padding:20px; text-align:center;">Gagal memuat log versi: ${escapeHtml(err.message)}</div>`;
        }
    }

    function getCategoryClass(cat) {
        const c = String(cat || '').toLowerCase().replace(/[^a-z]/g, '');
        if (c.includes('bug')) return 'category-bugfix';
        if (c.includes('perf')) return 'category-performance';
        if (c.includes('feat')) return 'category-feature';
        if (c.includes('ui') || c.includes('ux')) return 'category-uiux';
        if (c.includes('sec')) return 'category-security';
        return 'category-update';
    }

    function renderTimelineView(list) {
        const container = document.getElementById('timelineView');
        container.innerHTML = '';

        list.forEach((item, idx) => {
            const isCurrent = (item.version === activeSystemVersion) || (idx === 0 && !activeSystemVersion);
            const catClass = getCategoryClass(item.category);

            // Parse file chips
            const files = (item.files_modified || '')
                .split(',')
                .map(f => f.trim())
                .filter(f => f !== '');
            const filesHtml = files.length > 0 
                ? files.map(f => `<span class="file-chip"><i class="fa-regular fa-file-code"></i> ${escapeHtml(f)}</span>`).join('') 
                : '<span style="color:var(--text-muted); font-style:italic;">-</span>';

            const itemEl = document.createElement('div');
            itemEl.className = `timeline-item ${isCurrent ? 'is-current' : ''}`;
            itemEl.innerHTML = `
                <div class="timeline-node">
                    <i class="fa-solid ${isCurrent ? 'fa-rocket' : 'fa-check'}"></i>
                </div>
                <div class="version-card">
                    <div class="version-card-head">
                        <div>
                            <div class="card-head-left">
                                <span class="version-badge">
                                    <i class="fa-solid fa-code-branch"></i> v${escapeHtml(item.version)}
                                </span>
                                <span class="category-tag ${catClass}">${escapeHtml(item.category)}</span>
                                ${isCurrent ? '<span style="background:#52c41a; color:#fff; font-size:10px; font-weight:700; padding:1px 6px; border-radius:3px;">VERSI AKTIF</span>' : ''}
                            </div>
                            <div class="version-title">${escapeHtml(item.title)}</div>
                        </div>
                        <div class="card-head-right">
                            <span title="Waktu Rilis"><i class="fa-regular fa-calendar-check"></i> ${formatDateTime(item.release_date)}</span>
                            <span title="Author"><i class="fa-regular fa-user"></i> ${escapeHtml(item.author)}</span>
                            <?php if ($isAdmin): ?>
                            <div style="display:flex; gap:4px; margin-left:6px;">
                                <button type="button" class="btn btn-default btn-sm" onclick="openEditVersionModal(${item.id})" title="Edit Log Versi">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <button type="button" class="btn btn-danger-outline btn-sm" onclick="confirmDeleteVersion(${item.id}, '${escapeHtml(item.version)}')" title="Hapus Log Versi">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="version-card-body">
                        <div style="font-weight:600; font-size:11.5px; color:var(--text-heading); margin-bottom:4px;">
                            <i class="fa-solid fa-list-check" style="color:var(--primary); margin-right:4px;"></i> Rincian Perubahan & Solusi Masalah:
                        </div>
                        <div class="version-changes-box">${escapeHtml(item.changes)}</div>
                    </div>

                    <div class="version-card-footer">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-weight:600;"><i class="fa-solid fa-folder-tree"></i> Berkas Dimodifikasi:</span>
                            <div class="file-chips-wrap">${filesHtml}</div>
                        </div>
                        <div>
                            <button type="button" class="btn btn-default btn-sm" onclick="copyVersionSummary(${item.id})" title="Salin Ringkasan Versi Ini">
                                <i class="fa-regular fa-copy"></i> Salin Log
                            </button>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(itemEl);
        });
    }

    function renderTableView(list) {
        const tbody = document.getElementById('tableBody');
        tbody.innerHTML = '';

        list.forEach((item, idx) => {
            const isCurrent = (item.version === activeSystemVersion);
            const catClass = getCategoryClass(item.category);

            const files = (item.files_modified || '')
                .split(',')
                .map(f => f.trim())
                .filter(f => f !== '');
            const filesHtml = files.length > 0 
                ? files.map(f => `<span class="file-chip" style="margin-bottom:2px; display:inline-block;">${escapeHtml(f)}</span>`).join(' ') 
                : '-';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="text-align:center; font-weight:700; color:var(--text-muted);">${idx + 1}</td>
                <td>
                    <span class="version-badge" style="font-size:11.5px;">v${escapeHtml(item.version)}</span>
                    ${isCurrent ? '<div style="color:#52c41a; font-size:9.5px; font-weight:700; margin-top:2px;">● AKTIF</div>' : ''}
                </td>
                <td style="white-space:nowrap; font-size:11.5px;">
                    <div>${formatDate(item.release_date)}</div>
                    <small style="color:var(--text-muted);">${formatTime(item.release_date)}</small>
                </td>
                <td>
                    <span class="category-tag ${catClass}">${escapeHtml(item.category)}</span>
                </td>
                <td>
                    <div style="font-weight:700; color:var(--text-heading); margin-bottom:4px;">${escapeHtml(item.title)}</div>
                    <div style="font-size:11.5px; color:var(--text-body); white-space:pre-wrap; max-height:100px; overflow-y:auto; background:var(--bg-card-alt); padding:4px 8px; border-radius:3px;">${escapeHtml(item.changes)}</div>
                </td>
                <td>${filesHtml}</td>
                <td style="white-space:nowrap; font-size:11.5px;">${escapeHtml(item.author)}</td>
                <?php if ($isAdmin): ?>
                <td style="text-align:center; white-space:nowrap;">
                    <button type="button" class="btn btn-default btn-sm" onclick="openEditVersionModal(${item.id})" title="Edit">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button type="button" class="btn btn-danger-outline btn-sm" onclick="confirmDeleteVersion(${item.id}, '${escapeHtml(item.version)}')" title="Hapus">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </td>
                <?php endif; ?>
            `;
            tbody.appendChild(tr);
        });
    }

    function formatDateTime(str) {
        if (!str) return '-';
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString('id-ID', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatDate(str) {
        if (!str) return '-';
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString('id-ID', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function formatTime(str) {
        if (!str) return '';
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function generateNewVersionNumber() {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const verStr = `${y}${m}${d}.${hh}${mm}`;
        document.getElementById('formVersion').value = verStr;
    }

    <?php if ($isAdmin): ?>
    function openAddVersionModal() {
        document.getElementById('modalTitle').innerHTML = '➕ Catat Pembaruan Versi Baru';
        document.getElementById('formId').value = '0';
        generateNewVersionNumber();
        document.getElementById('formCategory').value = 'Update';
        
        const now = new Date();
        const localIso = new Date(now.getTime() - (now.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
        document.getElementById('formReleaseDate').value = localIso;

        document.getElementById('formTitleText').value = '';
        document.getElementById('formChanges').value = "• \n• ";
        document.getElementById('formFiles').value = '';
        document.getElementById('formAuthor').value = 'Super Administrator';
        document.getElementById('formSetActive').checked = true;

        document.getElementById('versionModal').style.display = 'flex';
        document.getElementById('formTitleText').focus();
    }

    function openEditVersionModal(id) {
        const item = rawVersionData.find(v => parseInt(v.id, 10) === parseInt(id, 10));
        if (!item) return;

        document.getElementById('modalTitle').innerHTML = `✏️ Edit Catatan Versi v${escapeHtml(item.version)}`;
        document.getElementById('formId').value = item.id;
        document.getElementById('formVersion').value = item.version;
        document.getElementById('formCategory').value = item.category || 'Update';

        let relDate = item.release_date || '';
        if (relDate.length >= 16) {
            relDate = relDate.replace(' ', 'T').slice(0, 16);
        }
        document.getElementById('formReleaseDate').value = relDate;

        document.getElementById('formTitleText').value = item.title || '';
        document.getElementById('formChanges').value = item.changes || '';
        document.getElementById('formFiles').value = item.files_modified || '';
        document.getElementById('formAuthor').value = item.author || 'Super Administrator';
        document.getElementById('formSetActive').checked = (item.version === activeSystemVersion);

        document.getElementById('versionModal').style.display = 'flex';
    }

    function closeVersionModal() {
        document.getElementById('versionModal').style.display = 'none';
    }

    async function saveVersion(e) {
        e.preventDefault();

        const btn = document.getElementById('btnSubmitForm');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';

        const id = document.getElementById('formId').value;
        const version = document.getElementById('formVersion').value.trim();
        const category = document.getElementById('formCategory').value;
        const release_date = document.getElementById('formReleaseDate').value;
        const title = document.getElementById('formTitleText').value.trim();
        const changes = document.getElementById('formChanges').value.trim();
        const files_modified = document.getElementById('formFiles').value.trim();
        const author = document.getElementById('formAuthor').value.trim();
        const set_active = document.getElementById('formSetActive').checked ? 1 : 0;

        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', id);
        fd.append('version', version);
        fd.append('category', category);
        fd.append('release_date', release_date.replace('T', ' '));
        fd.append('title', title);
        fd.append('changes', changes);
        fd.append('files_modified', files_modified);
        fd.append('author', author);
        fd.append('set_active', set_active);

        try {
            const res = await fetch('version_log.php', { method: 'POST', body: fd });
            const json = await res.json();
            btn.disabled = false;
            btn.innerHTML = origText;

            if (json.status === 'OK') {
                closeVersionModal();
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: json.message,
                    timer: 2000,
                    showConfirmButton: false
                });
                loadVersionLogs();

                // Notifikasi parent window jika ada untuk update footer versi
                if (window.parent && window.parent.document) {
                    const footVer = window.parent.document.getElementById('sidebarAppVersion');
                    if (footVer && set_active) {
                        footVer.innerText = version;
                    }
                }
            } else {
                Swal.fire('Gagal!', json.message || 'Terjadi kesalahan sistem', 'error');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = origText;
            Swal.fire('Error!', err.message, 'error');
        }
    }

    async function confirmDeleteVersion(id, version) {
        const result = await Swal.fire({
            title: `Hapus Log Versi ${version}?`,
            text: 'Catatan pembaruan untuk versi ini akan dihapus dari riwayat sistem.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ff4d4f',
            cancelButtonColor: '#8c8c8c',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        });

        if (!result.isConfirmed) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);

        try {
            const res = await fetch('version_log.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire({
                    icon: 'success',
                    title: 'Terhapus!',
                    text: json.message,
                    timer: 1500,
                    showConfirmButton: false
                });
                loadVersionLogs();
            } else {
                Swal.fire('Gagal!', json.message, 'error');
            }
        } catch (err) {
            Swal.fire('Error!', err.message, 'error');
        }
    }
    <?php endif; ?>

    function copyVersionSummary(id) {
        const item = rawVersionData.find(v => parseInt(v.id, 10) === parseInt(id, 10));
        if (!item) return;

        const text = `[Log Update CEISA 4.0] v${item.version} (${item.category})\n` +
                     `Judul: ${item.title}\n` +
                     `Rilis: ${item.release_date}\n` +
                     `Rincian Perubahan:\n${item.changes}\n` +
                     `Berkas: ${item.files_modified || '-'}\n` +
                     `Author: ${item.author}`;

        navigator.clipboard.writeText(text).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Tersalin!',
                text: `Ringkasan log versi v${item.version} berhasil disalin ke clipboard.`,
                timer: 1500,
                showConfirmButton: false
            });
        }).catch(err => {
            prompt('Salin teks log versi:', text);
        });
    }
</script>

</body>
</html>

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

// Validasi Sesi Login Sesuai Standar index.php
$sessionUserId   = $_SESSION['user_id'] ?? '';
$sessionUserName = $_SESSION['user_name'] ?? ($_SESSION['username'] ?? '');
$sessionUser     = $sessionUserName ?: ($sessionUserId ?: 'Super Administrator');
$sessionRole     = $_SESSION['user_role'] ?? ($_SESSION['role'] ?? 'user');
$isSuperAdmin    = !empty($_SESSION['is_super_admin']) || (!empty($_SESSION['is_admin']) && empty($_SESSION['is_company_admin']));
$isAdmin         = $isSuperAdmin;

// Pastikan sesi login aktif
if (empty($sessionUserId) && empty($sessionUserName) && empty($_SESSION['is_admin']) && !isset($_GET['embedded'])) {
    if (isset($_GET['action']) || isset($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'Sesi login telah berakhir. Silakan login kembali.']);
        exit;
    }
    header('Location: index.php');
    exit;
}

$themeParam = $_GET['theme'] ?? 'light';
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
    if (!empty($category) && $category !== 'Semua') {
        if ($category === 'Feature & Bugfix') {
            $where[] = "(category = 'Feature' OR category = 'Bug Fix' OR category = 'Bugfix')";
        } elseif ($category === 'Bugfix' || $category === 'Bug Fix') {
            $where[] = "(category = 'Bugfix' OR category = 'Bug Fix')";
        } elseif ($category === 'UI/UX Refactor' || $category === 'UI/UX') {
            $where[] = "(category = 'UI/UX' OR category = 'UI/UX Refactor')";
        } elseif ($category === 'Enhancement') {
            $where[] = "(category = 'Enhancement' OR category = 'Performance')";
        } else {
            $escCat = $koneksi->real_escape_string($category);
            $where[] = "(category = '$escCat' OR category LIKE '%$escCat%')";
        }
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderDir = (strtolower($sort) === 'asc') ? 'ASC' : 'DESC';

    $sql = "SELECT id, version, title, category, release_date, changes, files_modified, author, created_at 
            FROM bc_app_version_log 
            $whereSql 
            ORDER BY version $orderDir, release_date $orderDir, id $orderDir";

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
    
    $resLatest = $koneksi->query("SELECT version FROM bc_app_version_log ORDER BY version DESC, release_date DESC LIMIT 1");
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
    <title>Log Update Versi Sistem - CEISA 4.0</title>
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
            --text-body: <?= ($themeParam === 'dark') ? '#bfbfbf' : '#595959' ?>;
            --text-muted: <?= ($themeParam === 'dark') ? '#8c8c8c' : '#8c8c8c' ?>;
            --input-bg: <?= ($themeParam === 'dark') ? '#262626' : '#ffffff' ?>;
            --input-border: <?= ($themeParam === 'dark') ? '#434343' : '#d9d9d9' ?>;
            --badge-bg: <?= ($themeParam === 'dark') ? '#111b26' : '#e6f7ff' ?>;
            --badge-border: <?= ($themeParam === 'dark') ? '#15395b' : '#91d5ff' ?>;
            --badge-text: <?= ($themeParam === 'dark') ? '#177ddc' : '#1890ff' ?>;
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
            padding: 16px;
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
        }

        /* 1. HEADER CARD (MIRRORING SCREENSHOT 3) */
        .version-header-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 18px 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .version-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            background: #e6f7ff;
            color: #1890ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }
        body[data-theme='dark'] .header-icon-box {
            background: #111b26;
            color: #177ddc;
        }

        .version-header-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .version-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .version-header-info h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-heading);
            margin: 0;
        }

        .badge-active-version {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            color: #1890ff;
            font-size: 12px;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        body[data-theme='dark'] .badge-active-version {
            background: #111b26;
            border-color: #15395b;
            color: #177ddc;
        }
        .dot-green {
            color: #52c41a;
            font-size: 10px;
            line-height: 1;
        }

        .version-header-info p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
        }

        .version-header-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* BUTTONS */
        .btn-primary-release {
            background: #1890ff;
            color: #ffffff;
            border: 1px solid #1890ff;
            border-radius: 4px;
            padding: 7px 16px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary-release:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }

        .btn-default-refresh {
            background: #ffffff;
            color: rgba(0, 0, 0, 0.85);
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            padding: 7px 16px;
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-default-refresh:hover {
            color: #1890ff;
            border-color: #1890ff;
        }
        body[data-theme='dark'] .btn-default-refresh {
            background: #1f1f1f;
            border-color: #434343;
            color: #d9d9d9;
        }

        /* 2. FILTER & SEARCH TOOLBAR (SCREENSHOT 3) */
        .toolbar-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        .search-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            width: 360px;
            max-width: 100%;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            color: #bfbfbf;
            font-size: 12px;
            pointer-events: none;
        }

        .search-input {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 4px;
            padding: 7px 12px 7px 32px;
            font-size: 12px;
            color: var(--text-heading);
            outline: none;
            transition: all 0.2s;
        }

        .search-input:focus {
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }

        .filter-pills-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .filter-pill {
            background: transparent;
            border: none;
            color: var(--text-body);
            font-size: 12px;
            font-weight: 500;
            padding: 5px 14px;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .filter-pill:hover {
            color: #1890ff;
        }

        .filter-pill.active {
            background: #1890ff;
            color: #ffffff;
            font-weight: 600;
        }

        /* 3. TIMELINE LAYOUT (SCREENSHOT 3) */
        .timeline-container {
            position: relative;
            padding-left: 36px;
            margin-top: 14px;
        }

        .timeline-container::before {
            content: '';
            position: absolute;
            left: 14px;
            top: 24px;
            bottom: 30px;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 22px;
        }

        .timeline-node {
            position: absolute;
            left: -36px;
            top: 18px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--bg-card);
            border: 2px solid #1890ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #1890ff;
            box-shadow: 0 0 0 4px var(--bg-body);
            z-index: 2;
        }

        .timeline-item.is-current .timeline-node {
            border-color: #52c41a;
            background: #f6ffed;
            color: #52c41a;
            box-shadow: 0 0 0 4px var(--bg-body);
        }

        /* CARD */
        .version-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 18px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .version-card:hover {
            border-color: #91d5ff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .version-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .card-head-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .badge-version-green {
            background: #52c41a;
            color: #ffffff;
            font-weight: 700;
            font-size: 11.5px;
            padding: 2px 9px;
            border-radius: 4px;
            font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
            letter-spacing: 0.3px;
        }

        .badge-version-blue {
            background: #1890ff;
            color: #ffffff;
            font-weight: 700;
            font-size: 11.5px;
            padding: 2px 9px;
            border-radius: 4px;
            font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
            letter-spacing: 0.3px;
        }

        .tag-cat {
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .tag-cat-enhancement { background: #f6ffed; border: 1px solid #b7eb8f; color: #52c41a; }
        .tag-cat-feature     { background: #e6f7ff; border: 1px solid #91d5ff; color: #1890ff; }
        .tag-cat-bugfix      { background: #fff1f0; border: 1px solid #ffa39e; color: #f5222d; }
        .tag-cat-uiux        { background: #e6fffb; border: 1px solid #87e8de; color: #13c2c2; }
        .tag-cat-performance { background: #f9f0ff; border: 1px solid #d3adf7; color: #722ed1; }
        .tag-cat-update      { background: #e6f7ff; border: 1px solid #91d5ff; color: #1890ff; }
        .tag-cat-security    { background: #fff7e6; border: 1px solid #ffd591; color: #fa8c16; }

        .tag-active-status {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .card-head-right {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .card-item-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-heading);
            margin: 10px 0 12px 0;
        }

        .changes-list {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 14px;
        }

        .change-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 12.5px;
            color: var(--text-body);
            line-height: 1.6;
        }

        .change-check {
            color: #52c41a;
            font-weight: 700;
            font-size: 13px;
            line-height: 1.6;
            flex-shrink: 0;
        }

        .card-footer-row {
            border-top: 1px solid var(--border-color-light);
            padding-top: 10px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11.5px;
            color: var(--text-muted);
            flex-wrap: wrap;
            gap: 8px;
        }

        .footer-left {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .file-tag {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 2px 7px;
            font-size: 10.5px;
            font-family: 'SFMono-Regular', Consolas, monospace;
            color: var(--text-heading);
        }

        .footer-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-icon {
            cursor: pointer;
            color: var(--text-muted);
            font-size: 12px;
            transition: color 0.2s;
        }
        .action-icon:hover {
            color: #1890ff;
        }
        .action-icon.trash:hover {
            color: #ff4d4f;
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
            font-size: 18px;
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
            padding: 7px 10px;
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
            border-radius: 8px;
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

    <!-- 1. HEADER CARD (MIRRORING SCREENSHOT 3) -->
    <div class="version-header-card">
        <div class="version-header-left">
            <div class="header-icon-box" title="Log Update Versi">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div class="version-header-info">
                <div class="version-title-row">
                    <h2>Log Update Versi Sistem</h2>
                    <span class="badge-active-version" title="Versi aktif yang sedang berjalan">
                        <span class="dot-green">●</span> Versi Aktif : <span id="headerActiveVersion"><?= htmlspecialchars($currentSystemVersion) ?></span>
                    </span>
                </div>
                <p>Catatan riwayat rilis pembaruan, bugfix, dan perbaikan fitur CEISA 4.0</p>
            </div>
        </div>

        <div class="version-header-right">
            <?php if ($isAdmin): ?>
            <button type="button" class="btn-primary-release" onclick="openAddVersionModal()">
                <i class="fa-solid fa-plus"></i> Tambah Catatan Rilis
            </button>
            <?php endif; ?>
            <button type="button" class="btn-default-refresh" onclick="loadVersionLogs()" title="Muat Ulang Data">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>
    </div>

    <!-- 2. TOOLBAR: SEARCH & CATEGORY PILLS (SCREENSHOT 3) -->
    <div class="toolbar-panel">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" id="searchFilter" class="search-input" placeholder="Cari versi, perbaikan, judul, atau file.." oninput="debounceSearch()">
        </div>

        <div class="filter-pills-wrap" id="categoryPillsWrap">
            <button type="button" class="filter-pill active" data-category="" onclick="setFilterCategory('', this)">Semua</button>
            <button type="button" class="filter-pill" data-category="Feature & Bugfix" onclick="setFilterCategory('Feature & Bugfix', this)">Feature & Bugfix</button>
            <button type="button" class="filter-pill" data-category="Bugfix" onclick="setFilterCategory('Bugfix', this)">Bugfix</button>
            <button type="button" class="filter-pill" data-category="Feature" onclick="setFilterCategory('Feature', this)">Feature</button>
            <button type="button" class="filter-pill" data-category="Enhancement" onclick="setFilterCategory('Enhancement', this)">Enhancement</button>
            <button type="button" class="filter-pill" data-category="UI/UX Refactor" onclick="setFilterCategory('UI/UX Refactor', this)">UI/UX Refactor</button>
        </div>
    </div>

    <!-- 3. TIMELINE CARDS LIST (SCREENSHOT 3) -->
    <div class="timeline-container" id="timelineView">
        <!-- Populated via JS -->
    </div>

    <div class="empty-state" id="emptyState" style="display:none;">
        <i class="fa-regular fa-clipboard"></i>
        <div style="font-weight:600; font-size:14px; color:var(--text-heading); margin-bottom:4px;">Tidak Ada Log Versi Ditemukan</div>
        <div style="font-size:12px;">Coba ubah kata kunci pencarian atau filter kategori Anda.</div>
    </div>

</div>

<!-- 4. MODAL FORM: TAMBAH / EDIT VERSI (ADMIN ONLY) -->
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
                            <option value="Enhancement">Enhancement (Penyempurnaan)</option>
                            <option value="Feature">Feature (Rilis Fitur Baru)</option>
                            <option value="Bugfix">Bugfix (Perbaikan Kendala / Error)</option>
                            <option value="UI/UX Refactor">UI/UX Refactor (Penyempurnaan Tampilan)</option>
                            <option value="Performance">Performance (Optimasi Kecepatan)</option>
                            <option value="Update">Update (Pembaruan Umum)</option>
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
                        <input type="text" id="formAuthor" class="form-control" value="Super Administrator (DP)" required>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Judul Pembaruan (Ringkasan):</label>
                    <input type="text" id="formTitleText" class="form-control" required placeholder="Contoh: Pengurutan Descending Log Update Versi Sistem">
                </div>

                <div class="form-row">
                    <label class="form-label">Rincian Perubahan / Yang Disolve (Pisahkan dengan baris baru):</label>
                    <textarea id="formChanges" class="form-control" required placeholder="Memperbaiki sorting log rilis menjadi descending...&#10;Versi rilis terbaru selalu tampil paling atas...&#10;Menyelaraskan timestamp release_date..."></textarea>
                </div>

                <div class="form-row">
                    <label class="form-label">Berkas yang Dimodifikasi (Pisahkan dengan koma):</label>
                    <input type="text" id="formFiles" class="form-control" placeholder="Contoh: version_log.php, version.txt">
                </div>

                <div class="form-row" style="margin-top:10px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:600; color:var(--text-heading);">
                        <input type="checkbox" id="formSetActive" checked>
                        <span>Jadikan sebagai versi aktif sistem saat ini (perbarui version.txt)</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-default-refresh" onclick="closeVersionModal()" style="padding:6px 14px;">Batal</button>
                <button type="submit" class="btn-primary-release" id="btnSubmitForm" style="padding:6px 14px;">
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
    let currentCategoryFilter = '';
    let searchTimeout = null;

    document.addEventListener('DOMContentLoaded', () => {
        loadVersionLogs();
    });

    function setFilterCategory(cat, el) {
        currentCategoryFilter = cat;
        const pills = document.querySelectorAll('.filter-pill');
        pills.forEach(p => p.classList.remove('active'));
        if (el) {
            el.classList.add('active');
        }
        loadVersionLogs();
    }

    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            loadVersionLogs();
        }, 300);
    }

    async function loadVersionLogs() {
        const q = document.getElementById('searchFilter').value.trim();
        const cat = currentCategoryFilter;
        const tlView = document.getElementById('timelineView');
        const emptyState = document.getElementById('emptyState');

        try {
            const res = await fetch(`version_log.php?action=list&q=${encodeURIComponent(q)}&category=${encodeURIComponent(cat)}&sort=desc&_t=${Date.now()}`);
            const json = await res.json();

            if (json.status !== 'OK') {
                throw new Error(json.message || 'Gagal mengambil data versi');
            }

            rawVersionData = json.data || [];
            activeSystemVersion = json.active_version || activeSystemVersion;

            // Update stats & chip
            const headerActiveEl = document.getElementById('headerActiveVersion');
            if (headerActiveEl) {
                headerActiveEl.innerText = activeSystemVersion;
            }

            if (rawVersionData.length === 0) {
                tlView.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            renderTimelineView(rawVersionData);

        } catch (err) {
            console.error(err);
            tlView.innerHTML = `<div style="color:#ff4d4f; padding:20px; text-align:center;">Gagal memuat log versi: ${escapeHtml(err.message)}</div>`;
        }
    }

    function getCategoryTagHtml(cat) {
        const c = String(cat || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        let cls = 'tag-cat-update';
        let label = cat || 'UPDATE';
        if (c.includes('enhanc')) {
            cls = 'tag-cat-enhancement';
            label = 'ENHANCEMENT';
        } else if (c.includes('feat')) {
            cls = 'tag-cat-feature';
            label = 'FEATURE';
        } else if (c.includes('bug')) {
            cls = 'tag-cat-bugfix';
            label = 'BUGFIX';
        } else if (c.includes('ui') || c.includes('ux') || c.includes('refactor')) {
            cls = 'tag-cat-uiux';
            label = 'UI/UX REFACTOR';
        } else if (c.includes('perf')) {
            cls = 'tag-cat-performance';
            label = 'PERFORMANCE';
        } else if (c.includes('sec')) {
            cls = 'tag-cat-security';
            label = 'SECURITY';
        }
        return `<span class="tag-cat ${cls}">${escapeHtml(label)}</span>`;
    }

    function renderTimelineView(list) {
        const container = document.getElementById('timelineView');
        container.innerHTML = '';

        list.forEach((item, idx) => {
            const isCurrent = (item.version === activeSystemVersion) || (idx === 0 && !activeSystemVersion);
            const badgeClass = isCurrent ? 'badge-version-green' : 'badge-version-blue';
            const catTagHtml = getCategoryTagHtml(item.category);

            // Parse file tags
            const files = (item.files_modified || '')
                .split(',')
                .map(f => f.trim())
                .filter(f => f !== '');
            const filesHtml = files.length > 0 
                ? files.map(f => `<span class="file-tag">${escapeHtml(f)}</span>`).join(' ') 
                : '<span style="color:var(--text-muted); font-style:italic;">-</span>';

            // Parse lines of changes and render with green checkmark
            const lines = String(item.changes || '')
                .split('\n')
                .map(l => l.trim().replace(/^([•\-\*✓✔\d\.\)]\s*)+/, '').trim())
                .filter(l => l !== '');

            const changesHtml = lines.map(line => `
                <div class="change-row">
                    <span class="change-check">✓</span>
                    <span class="change-text">${escapeHtml(line)}</span>
                </div>
            `).join('');

            const itemEl = document.createElement('div');
            itemEl.className = `timeline-item ${isCurrent ? 'is-current' : ''}`;
            itemEl.innerHTML = `
                <div class="timeline-node">
                    ${isCurrent ? '<i class="fa-solid fa-check"></i>' : '<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#1890ff;"></span>'}
                </div>
                <div class="version-card">
                    <div class="version-card-head">
                        <div class="card-head-left">
                            <span class="${badgeClass}">v${escapeHtml(item.version)}</span>
                            ${catTagHtml}
                            ${isCurrent ? '<span class="tag-active-status"><span class="dot-green">●</span> Versi Aktif</span>' : ''}
                        </div>
                        <div class="card-head-right">
                            <i class="fa-regular fa-calendar"></i>
                            <span>${formatReleaseDate(item.release_date)}</span>
                        </div>
                    </div>

                    <div class="card-item-title">${escapeHtml(item.title)}</div>

                    <div class="changes-list">
                        ${changesHtml}
                    </div>

                    <div class="card-footer-row">
                        <div class="footer-left">
                            <span>Berkas:</span>
                            ${filesHtml}
                        </div>
                        <div class="footer-right">
                            <i class="fa-regular fa-user"></i>
                            <span>${escapeHtml(item.author || 'Super Admin')}</span>
                            <?php if ($isAdmin): ?>
                            <i class="fa-solid fa-pencil action-icon" onclick="openEditVersionModal(${item.id})" title="Edit Log Versi"></i>
                            <i class="fa-regular fa-trash-can action-icon trash" onclick="confirmDeleteVersion(${item.id}, '${escapeHtml(item.version)}')" title="Hapus Log Versi"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            `;
            container.appendChild(itemEl);
        });
    }

    function formatReleaseDate(str) {
        if (!str) return '-';
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        
        const day = d.getDate();
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        const month = months[d.getMonth()] || '';
        const year = d.getFullYear();
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');

        return `${day} ${month} ${year}, ${hours}:${minutes} WIB`;
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
        document.getElementById('modalTitle').innerHTML = '➕ Tambah Catatan Rilis Versi';
        document.getElementById('formId').value = '0';
        generateNewVersionNumber();
        document.getElementById('formCategory').value = 'Enhancement';
        
        const now = new Date();
        const localIso = new Date(now.getTime() - (now.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
        document.getElementById('formReleaseDate').value = localIso;

        document.getElementById('formAuthor').value = 'Super Administrator (DP)';
        document.getElementById('formTitleText').value = '';
        document.getElementById('formChanges').value = '';
        document.getElementById('formFiles').value = '';
        document.getElementById('formSetActive').checked = true;

        document.getElementById('versionModal').style.display = 'flex';
    }

    function openEditVersionModal(id) {
        const item = rawVersionData.find(x => parseInt(x.id) === parseInt(id));
        if (!item) return;

        document.getElementById('modalTitle').innerHTML = `✏️ Edit Catatan Rilis: v${escapeHtml(item.version)}`;
        document.getElementById('formId').value = item.id;
        document.getElementById('formVersion').value = item.version;
        document.getElementById('formCategory').value = item.category || 'Enhancement';
        
        if (item.release_date) {
            const d = new Date(item.release_date.replace(' ', 'T'));
            if (!isNaN(d.getTime())) {
                const localIso = new Date(d.getTime() - (d.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
                document.getElementById('formReleaseDate').value = localIso;
            }
        }

        document.getElementById('formAuthor').value = item.author || 'Super Administrator (DP)';
        document.getElementById('formTitleText').value = item.title;
        document.getElementById('formChanges').value = item.changes;
        document.getElementById('formFiles').value = item.files_modified || '';
        document.getElementById('formSetActive').checked = (item.version === activeSystemVersion);

        document.getElementById('versionModal').style.display = 'flex';
    }

    function closeVersionModal() {
        document.getElementById('versionModal').style.display = 'none';
    }

    async function saveVersion(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmitForm');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';

        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', document.getElementById('formId').value);
        fd.append('version', document.getElementById('formVersion').value.trim());
        fd.append('category', document.getElementById('formCategory').value);
        fd.append('release_date', document.getElementById('formReleaseDate').value);
        fd.append('author', document.getElementById('formAuthor').value.trim());
        fd.append('title', document.getElementById('formTitleText').value.trim());
        fd.append('changes', document.getElementById('formChanges').value.trim());
        fd.append('files_modified', document.getElementById('formFiles').value.trim());
        if (document.getElementById('formSetActive').checked) {
            fd.append('set_active', '1');
        }

        try {
            const res = await fetch('version_log.php', { method: 'POST', body: fd });
            const json = await res.json();

            if (json.status !== 'OK') {
                throw new Error(json.message || 'Gagal menyimpan versi');
            }

            closeVersionModal();
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: json.message,
                timer: 1600,
                showConfirmButton: false
            });

            // Update parent sidebar version label if present
            try {
                if (window.parent && window.parent.document) {
                    const parentVerLabel = window.parent.document.getElementById('sidebarAppVersion');
                    if (parentVerLabel) {
                        parentVerLabel.innerText = json.version;
                    }
                }
            } catch(e) {}

            loadVersionLogs();

        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Gagal', text: err.message });
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Catatan Versi';
        }
    }

    function confirmDeleteVersion(id, ver) {
        Swal.fire({
            title: `Hapus Versi v${ver}?`,
            text: "Riwayat log versi ini akan dihapus permanen dari sistem.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ff4d4f',
            cancelButtonColor: '#d9d9d9',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then(async (result) => {
            if (result.isConfirmed) {
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', id);

                try {
                    const res = await fetch('version_log.php', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.status !== 'OK') {
                        throw new Error(json.message || 'Gagal menghapus versi');
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Terhapus!',
                        text: json.message,
                        timer: 1400,
                        showConfirmButton: false
                    });
                    loadVersionLogs();
                } catch(err) {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: err.message });
                }
            }
        });
    }
    <?php endif; ?>
</script>

</body>
</html>

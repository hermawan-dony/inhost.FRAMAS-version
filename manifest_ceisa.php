<?php
/**
 * CEISA 4.0 - Browse Data NVOCC (Dashboard Ceisa 4.0 - Manifes)
 * Mereplikasi 100% Tampilan & Fungsi Resmi Portal CEISA 4.0 (portal.beacukai.go.id/manifes)
 * Menu: SINGLE CORE > Manifes Online > NVOCC > Browse Data NVOCC
 */
session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$isAdmin = !empty($_SESSION['is_admin']) || (!empty($_GET['is_admin']) && $_GET['is_admin'] == '1');
$sessionCmpId = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : ($cmp_id ?: ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '')));
$rawNpwp = preg_replace('/\D/', '', $npwp ?: ($_SESSION['company_npwp'] ?? ''));
$sessionNpwp = $rawNpwp;
if (strlen($sessionNpwp) === 16 && substr($sessionNpwp, 0, 1) === '0') {
    $sessionNpwp = substr($sessionNpwp, 1);
} elseif (strlen($sessionNpwp) > 15) {
    $sessionNpwp = substr($sessionNpwp, 0, 15);
}
$sessionKodeKantor = $kode_kantor ?: ($_SESSION['company_kode_kantor'] ?? '070100');

// Ambil token MFA (sama persis seperti Dashboard Ceisa 4.0)
$cachedToken = $_SESSION["mfatoken_" . $sessionCmpId] ?? ($_SESSION["mfatoken"] ?? ($_SESSION["portal_token_" . $sessionCmpId] ?? ''));
if (empty($cachedToken)) {
    $mfaCacheFile = sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionCmpId) . '.json';
    if (file_exists($mfaCacheFile)) {
        $cTok = @json_decode(@file_get_contents($mfaCacheFile), true);
        if (!empty($cTok['token'])) {
            $cachedToken = $cTok['token'];
        }
    }
}
if (empty($cachedToken)) {
    $qC = $koneksi->query("SELECT token_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($sessionCmpId) . "' LIMIT 1");
    if ($qC && $rC = $qC->fetch_assoc()) {
        $cachedToken = $rC['token_ceisa'] ?? '';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manifest Ceisa 4.0 - Browse Data NVOCC</title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border: #e8e8e8;
            --border-card: #e8e8e8;
            --border-table: #e8e8e8;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-heading: #262626;
            --text-main: #333333;
            --text-muted: #8c8c8c;
            --table-head-bg: #fafafa;
            --table-row-hover: #f5f9ff;
            --dropdown-bg: #ffffff;
            --dropdown-shadow: 0 4px 14px rgba(0,0,0,0.12);
            color-scheme: light;
        }

        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --border: #303030;
            --border-card: #303030;
            --border-table: #2a2a2a;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-heading: rgba(255, 255, 255, 0.95);
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --table-head-bg: #1a1a1a;
            --table-row-hover: #262626;
            --dropdown-bg: #1f1f1f;
            --dropdown-shadow: 0 4px 16px rgba(0,0,0,0.45);
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            padding: 10px 14px;
            font-size: 10px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Breadcrumb */
        .portal-breadcrumb {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
            font-weight: 500;
        }

        /* Portal Main Container */
        .ceisa-container {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            padding: 12px 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            min-height: calc(100vh - 65px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Header Row: Title & Action Button */
        .portal-header-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .portal-title-block {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .portal-folder-icon {
            font-size: 15px;
            color: #1890ff;
        }
        .portal-title-text {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-heading);
            letter-spacing: -0.2px;
        }

        /* Official CEISA Ant Design Blue Button */
        .ant-btn-ceisa-primary {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #1890ff;
            color: #ffffff;
            border: 1px solid #1890ff;
            height: 24px;
            padding: 0 10px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 3px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(24, 144, 255, 0.2);
            transition: all 0.2s;
            font-family: inherit;
        }
        .ant-btn-ceisa-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }

        /* Actions Toolbar Under Title */
        .portal-toolbar {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .portal-tool-btn {
            background: transparent;
            border: none;
            color: #1890ff;
            font-size: 10px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 4px;
            border-radius: 2px;
            transition: all 0.15s;
            font-family: inherit;
        }
        .portal-tool-btn:hover {
            color: #40a9ff;
            background: rgba(24, 144, 255, 0.06);
        }
        .portal-tool-btn.live-sync {
            color: #52c41a;
            font-weight: 600;
        }
        .portal-tool-btn.live-sync:hover {
            color: #73d13d;
            background: rgba(82, 196, 26, 0.08);
        }

        /* Search & Filter Inline Row */
        .filter-drawer {
            display: none;
            background: var(--bg-base);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 8px 10px;
            margin-bottom: 10px;
            gap: 8px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-drawer.open {
            display: flex;
        }
        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .filter-item label {
            font-size: 8.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .filter-input {
            height: 22px;
            padding: 1px 6px;
            font-size: 9.5px;
            border: 1px solid var(--border);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .filter-input:focus {
            border-color: #1890ff;
        }

        /* Table Styling matching Official CEISA 4.0 */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border-table);
            border-radius: 3px;
        }
        .ceisa-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            text-align: left;
            background: var(--bg-card);
        }
        .ceisa-table thead tr th {
            background: var(--table-head-bg);
            color: var(--text-heading);
            font-weight: 600;
            padding: 6px 8px;
            border-bottom: 1px solid var(--border-table);
            white-space: nowrap;
            user-select: none;
        }
        .th-content {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .sort-icon {
            color: #bfbfbf;
            font-size: 8px;
            cursor: pointer;
        }
        .search-icon-btn {
            color: #8c8c8c;
            cursor: pointer;
            font-size: 9px;
            transition: color 0.15s;
        }
        .search-icon-btn:hover {
            color: #1890ff;
        }
        .ceisa-table tbody tr {
            border-bottom: 1px solid var(--border-table);
            transition: background 0.15s;
        }
        .ceisa-table tbody tr:hover {
            background: var(--table-row-hover);
        }
        .ceisa-table tbody tr td {
            padding: 5px 8px;
            white-space: nowrap;
            vertical-align: middle;
            color: var(--text-main);
        }

        /* Nomor Pengajuan Link */
        .aju-link {
            color: var(--text-heading);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.15s;
        }
        .aju-link:hover {
            color: #1890ff;
            text-decoration: underline;
        }

        /* Status Badges (Replicating Official CEISA 4.0 Screenshot) */
        .status-badge {
            display: inline-block;
            padding: 1px 7px;
            font-size: 8.5px;
            font-weight: 700;
            border-radius: 2px;
            text-align: center;
            letter-spacing: 0.3px;
            line-height: 14px;
            text-transform: uppercase;
        }
        .status-draft {
            background: #d9d9d9;
            color: #595959;
            border: 1px solid #bfbfbf;
        }
        .status-ready {
            background: #52c41a;
            color: #ffffff;
            border: 1px solid #389e0d;
        }
        .status-penerimaan {
            background: #237804;
            color: #ffffff;
            border: 1px solid #135200;
        }
        .status-rekon, .status-rikon {
            background: #fa8c16;
            color: #ffffff;
            border: 1px solid #d46b08;
        }
        .status-persetujuan, .status-persetujuan_perbaikan {
            background: #5b8c00;
            color: #ffffff;
            border: 1px solid #3f6600;
        }
        .status-reject, .status-ditolak {
            background: #cf1322;
            color: #ffffff;
            border: 1px solid #a8071a;
        }
        .status-terkirim, .status-kirim {
            background: #1890ff;
            color: #ffffff;
            border: 1px solid #096dd9;
        }

        /* Action Dropdown Menu Button */
        .action-menu-trigger {
            color: #1890ff;
            background: transparent;
            border: none;
            font-size: 9.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 1px 4px;
            border-radius: 2px;
            transition: all 0.15s;
            font-family: inherit;
        }
        .action-menu-trigger:hover {
            color: #40a9ff;
            background: rgba(24, 144, 255, 0.08);
        }

        /* Floating Dropdown Menu */
        .dropdown-menu {
            position: fixed;
            background: var(--dropdown-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            box-shadow: var(--dropdown-shadow);
            z-index: 9999;
            display: none;
            min-width: 170px;
            padding: 4px 0;
        }
        .dropdown-item {
            padding: 5px 12px;
            font-size: 9.5px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .dropdown-item:hover {
            background: rgba(24, 144, 255, 0.08);
            color: #1890ff;
        }
        .dropdown-item.danger:hover {
            background: rgba(255, 77, 79, 0.1);
            color: #ff4d4f;
        }
        .dropdown-divider {
            height: 1px;
            background: var(--border);
            margin: 3px 0;
        }

        /* Pagination */
        .portal-pagination-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            font-size: 9.5px;
        }
        .page-btn {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-main);
            min-width: 20px;
            height: 20px;
            padding: 0 4px;
            border-radius: 2px;
            font-size: 9.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .page-btn.active {
            background: #1890ff;
            color: #fff;
            border-color: #1890ff;
            font-weight: 600;
        }
        .page-btn:hover:not(.active) {
            border-color: #1890ff;
            color: #1890ff;
        }

        /* Spinner & Empty State */
        .table-loading {
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
        }
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(24, 144, 255, 0.2);
            border-top-color: #1890ff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        /* Detail View Styles (Replicating Dokumen Header & Master BL from CEISA 4.0 Portal) */
        .detail-view-container {
            display: none;
            width: 100%;
        }
        .detail-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }
        .detail-title-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-back-browse {
            background: none;
            border: none;
            font-size: 15px;
            color: var(--primary);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            transition: background 0.15s;
        }
        .btn-back-browse:hover {
            background: rgba(24, 144, 255, 0.1);
        }
        .detail-title-main {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .detail-title-sub {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .btn-cek-rekon {
            background: #ff4d4f;
            color: #ffffff;
            border: none;
            border-radius: 3px;
            padding: 5px 16px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s;
        }
        .btn-cek-rekon:hover {
            background: #ff7875;
        }
        
        /* Grid Table for Dokumen Header */
        .detail-grid-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
        }
        .detail-grid-table td {
            border: 1px solid var(--border-table);
            padding: 7px 10px;
        }
        .detail-grid-label {
            background: var(--table-head-bg);
            color: var(--text-muted);
            font-weight: 500;
            width: 135px;
        }
        .detail-grid-val {
            color: var(--text-heading);
            font-weight: 500;
        }
        .detail-form-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 12px;
        }
        .detail-form-label {
            width: 160px;
            font-size: 10px;
            color: var(--text-main);
            font-weight: 400;
        }
        .detail-input-readonly {
            background: var(--table-head-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 4px 8px;
            font-size: 10px;
            color: var(--text-main);
            width: 250px;
            outline: none;
        }

        /* Data Master BL Card */
        .master-bl-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            margin-top: 14px;
            overflow: hidden;
        }
        .master-bl-card-header {
            background: var(--table-head-bg);
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }
        .btn-card-add {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-main);
            border-radius: 2px;
            padding: 1px 7px;
            font-size: 11px;
            cursor: pointer;
        }
        .btn-card-add:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
    </style>
</head>
<body data-theme="light">

    <!-- Top Breadcrumb -->
    <div class="portal-breadcrumb" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <div>
            <span>CEISA 4.0</span> <span style="margin:0 4px; color:var(--text-muted);">/</span>
            <span>Manifest Ceisa 4.0</span> <span style="margin:0 4px; color:var(--text-muted);">/</span>
            <span style="color:var(--text-heading); font-weight:600;">Browse Data NVOCC</span>
        </div>
        <div style="font-size:9px; color:var(--text-muted);">
            Sumber: <code style="background:rgba(24,144,255,0.08); color:#1890ff; padding:1px 6px; border-radius:3px; font-weight:500;">prosesNPWP-NVOCC/<?= htmlspecialchars($sessionNpwp) ?></code>
        </div>
    </div>

    <!-- Main White Card Container -->
    <div class="ceisa-container">
        <!-- Section 1: Browse Data NVOCC -->
        <div id="viewBrowseSection">
            <!-- Header Row: Title & Button -->
            <div class="portal-header-row">
                <div class="portal-title-block">
                    <span class="portal-folder-icon">📁</span>
                    <span class="portal-title-text">Browse Data NVOCC</span>
                </div>
                <button type="button" class="ant-btn-ceisa-primary" onclick="openHostToHostEditor()">
                    <span>➕</span>
                    <span>Pembuatan Dokumen Baru</span>
                </button>
            </div>

            <!-- Actions Toolbar -->
            <div class="portal-toolbar">
                <button type="button" class="portal-tool-btn" onclick="openRestoreDataModal()">
                    <span>📥</span>
                    <span>Restore Data</span>
                </button>
                <button type="button" class="portal-tool-btn" onclick="toggleFilterDrawer()">
                    <span>⚡</span>
                    <span>Filter Data</span>
                </button>
                <button type="button" class="portal-tool-btn" onclick="loadPortalData(true)">
                    <span>🔄</span>
                    <span>Perbaharui Data</span>
                </button>
                <button type="button" class="portal-tool-btn live-sync" onclick="openSyncPortalModal()" title="Tarik langsung seluruh dokumen Manifes NVOCC dari Gateway Portal CEISA 4.0">
                    <span>🌐</span>
                    <span>Tarik dari CEISA 4.0 (Live API)</span>
                </button>
            </div>

            <!-- Filter Drawer Panel -->
            <div class="filter-drawer" id="filterDrawer">
                <div class="filter-item">
                    <label>Pencarian Bebas</label>
                    <input type="text" id="filterSearch" class="filter-input" placeholder="Cari No Aju / Voyage / Kapal..." onkeyup="if(event.key==='Enter') loadPortalData();" style="width:190px;">
                </div>
                <div class="filter-item">
                    <label>Jenis Dokumen</label>
                    <select id="filterJenis" class="filter-input" onchange="loadPortalData()">
                        <option value="">Semua (Inward & Outward)</option>
                        <option value="12">Outward (BC 1.1)</option>
                        <option value="11">Inward (BC 1.1)</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Status</label>
                    <select id="filterStatus" class="filter-input" onchange="loadPortalData()">
                        <option value="">Semua Status</option>
                        <option value="Draft">DRAFT</option>
                        <option value="Ready">READY</option>
                        <option value="Penerimaan">PENERIMAAN</option>
                        <option value="Rekon">REKON</option>
                        <option value="Persetujuan Perbaikan">PERSETUJUAN PERBAIKAN</option>
                        <option value="Reject">REJECT / DITOLAK</option>
                    </select>
                </div>
                <button type="button" class="ant-btn-ceisa-primary" style="height:22px;" onclick="loadPortalData()">
                    <span>Terapkan</span>
                </button>
                <button type="button" class="portal-tool-btn" style="color:var(--text-muted);" onclick="resetFilterDrawer()">
                    <span>Reset</span>
                </button>
            </div>

            <!-- Data Table (10 Columns exact match) -->
            <div class="table-responsive">
                <table class="ceisa-table" id="portalTable">
                    <thead>
                        <tr>
                            <th onclick="toggleSort('nomor_aju')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Nomor Pengajuan</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('kode_kantor')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Kode Kantor</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('nomor_voyage')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Nomor Voyage / Flight</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('jenis_manifes')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Jenis Dokumen</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('nomor_bc11')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Nomor Daftar</span>
                                    <span class="sort-icon">⇅</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('tanggal_bc11')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Tanggal Daftar</span>
                                    <span class="sort-icon">⇅</span>
                                    <span>📅</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('status')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Status</span>
                                    <span class="sort-icon">⇅</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('nama_sarana_pengangkut')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Sarana Angkut</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('tanggal_berangkat')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Tanggal</span>
                                    <span class="sort-icon">⇅</span>
                                    <span>📅</span>
                                </div>
                            </th>
                            <th style="text-align:center; width:65px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="portalTableBody">
                        <tr>
                            <td colspan="10" class="table-loading">
                                <span class="spinner"></span> Memuat data manifes NVOCC...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="portal-pagination-bar" id="paginationBar">
                <span id="pageInfo" style="color:var(--text-muted); margin-right:8px;">Menampilkan 0 data</span>
                <button type="button" class="page-btn" id="btnPrevPage" onclick="changePage(currentPage - 1)">‹</button>
                <div id="pageNumbers" style="display:inline-flex; gap:3px;"></div>
                <button type="button" class="page-btn" id="btnNextPage" onclick="changePage(currentPage + 1)">›</button>
                <select id="pageSizeSelect" class="filter-input" style="height:20px; font-size:9px;" onchange="changePageSize(this.value)">
                    <option value="10">10 / hal</option>
                    <option value="25" selected>25 / hal</option>
                    <option value="50">50 / hal</option>
                </select>
            </div>
        </div>

        <!-- Section 2: Detail View Dokumen Header & Data Master BL (100% Identik Portal CEISA 4.0) -->
        <div id="viewDetailSection" class="detail-view-container">
            <!-- Top Bar: Back & Cek Rekon -->
            <div class="detail-header-bar">
                <div class="detail-title-group">
                    <button type="button" class="btn-back-browse" onclick="closeDetailDoc()" title="Kembali ke Browse Data NVOCC">
                        <span>←</span>
                    </button>
                    <span class="detail-title-main">Dokumen Header</span>
                    <span class="detail-title-sub" id="detSubTitle">Outward (BC 1.1) - LAUT NVOCC</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button type="button" class="btn-cek-rekon" onclick="doCekRekon()" title="Periksa status rekonsiliasi manifes di CEISA 4.0">
                        <span>Cek Rekon</span>
                    </button>
                    <button type="button" class="ant-btn-ceisa-primary" style="height:24px; font-size:9.5px;" onclick="openHostToHostEditor(currentDetailAju)">
                        <span>✏️ Buka di Host to Host</span>
                    </button>
                </div>
            </div>

            <!-- Card Grid Dokumen Header -->
            <table class="detail-grid-table">
                <tr>
                    <td class="detail-grid-label">Nama Perusahaan</td>
                    <td class="detail-grid-val" id="detNamaPerusahaan" style="width:35%;">—</td>
                    <td class="detail-grid-label">Alamat Perusahaan</td>
                    <td class="detail-grid-val" id="detAlamatPerusahaan">—</td>
                </tr>
                <tr>
                    <td class="detail-grid-label">Nomor Pengajuan</td>
                    <td class="detail-grid-val" id="detNomorPengajuan" style="font-weight:600; color:#1890ff;">—</td>
                    <td class="detail-grid-label">Nomor Daftar</td>
                    <td class="detail-grid-val">
                        <span id="detNomorDaftar">—</span>
                        <span style="margin:0 12px; color:var(--text-muted);">Tanggal Daftar:</span>
                        <span id="detTanggalDaftar" style="font-weight:500;">—</span>
                    </td>
                </tr>
                <tr>
                    <td class="detail-grid-label">Kantor Pelayanan</td>
                    <td class="detail-grid-val" colspan="3">
                        <div style="background:var(--bg-input); border:1px solid var(--border); border-radius:3px; padding:4px 10px; font-size:10px; display:flex; justify-content:space-between; align-items:center; max-width:550px;">
                            <span id="detKantorPelayanan">—</span>
                            <span style="color:var(--text-muted); font-size:9px;">⌵</span>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Detail Form Inputs -->
            <div style="margin-bottom:16px; padding:0 4px;">
                <div class="detail-form-row">
                    <div class="detail-form-label">Nama Sarana Pengangkut</div>
                    <input type="text" id="detSarana" class="detail-input-readonly" readonly value="—">
                </div>
                <div class="detail-form-row">
                    <div class="detail-form-label">Nomor Voyage</div>
                    <input type="text" id="detVoyage" class="detail-input-readonly" readonly value="—">
                </div>
                <div class="detail-form-row">
                    <div class="detail-form-label">Nomor IMO / Registrasi</div>
                    <input type="text" id="detImo" class="detail-input-readonly" readonly value="—">
                </div>
                <div class="detail-form-row">
                    <div class="detail-form-label" id="detLabelTglDoc">Tanggal Berangkat</div>
                    <div style="position:relative; width:250px;">
                        <input type="text" id="detTanggalDoc" class="detail-input-readonly" style="width:100%; padding-right:24px;" readonly value="—">
                        <span style="position:absolute; right:8px; top:5px; color:var(--text-muted); font-size:11px;">📅</span>
                    </div>
                </div>
            </div>

            <!-- Card: Data Master BL -->
            <div class="master-bl-card">
                <div class="master-bl-card-header">
                    <span>Data Master BL</span>
                    <button type="button" class="btn-card-add" onclick="openHostToHostEditor(currentDetailAju)" title="Tambah / Edit Master BL di Host to Host">＋</button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="portal-table" style="margin:0;">
                        <thead>
                            <tr>
                                <th style="min-width:200px;">Kel. Pos</th>
                                <th style="min-width:120px;">
                                    <div class="th-content">
                                        <span>No Master</span>
                                        <span>🔍</span>
                                    </div>
                                </th>
                                <th style="min-width:100px;">
                                    <div class="th-content">
                                        <span>Tgl. Master</span>
                                        <span>📅</span>
                                    </div>
                                </th>
                                <th style="min-width:80px;">
                                    <div class="th-content">
                                        <span>Jml Pos</span>
                                        <span>🔍</span>
                                    </div>
                                </th>
                                <th style="min-width:85px;">POS BC11</th>
                                <th style="min-width:90px;">Respon</th>
                                <th style="min-width:70px;">STATUS</th>
                                <th style="min-width:85px;">Pos Status</th>
                                <th style="text-align:center; width:65px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="detMasterBlBody">
                            <tr>
                                <td colspan="9" style="text-align:center; padding:15px; color:var(--text-muted);">
                                    Memuat Data Master BL...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="padding:6px 12px; display:flex; justify-content:flex-end; border-top:1px solid var(--border); font-size:9.5px; color:var(--text-muted);">
                    <div style="display:inline-flex; align-items:center; gap:4px;">
                        <button type="button" class="page-btn" disabled>‹</button>
                        <button type="button" class="page-btn active">1</button>
                        <button type="button" class="page-btn" disabled>›</button>
                    </div>
                </div>
            </div>

            <!-- Bottom Action Navigation -->
            <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <button type="button" class="portal-tool-btn" onclick="closeDetailDoc()">
                    <span>← Kembali ke Browse Data NVOCC</span>
                </button>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="portal-tool-btn" onclick="refreshDetailDoc()">
                        <span>🔄 Refresh Data Dokumen</span>
                    </button>
                    <button type="button" class="ant-btn-ceisa-primary" onclick="openHostToHostEditor(currentDetailAju)">
                        <span>✏️ Buka di Editor Host to Host</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Dropdown Menu (Dinamis Sesuai Status Dokumen Portal CEISA 4.0) -->
    <div id="rowDropdownMenu" class="dropdown-menu"></div>

    <script>
        const activeCmpId = <?= json_encode($sessionCmpId) ?>;
        const activeCmpNpwp = <?= json_encode($sessionNpwp) ?>;
        let cachedPortalToken = <?= json_encode($cachedToken) ?>;

        let tableData = [];
        let currentPage = 1;
        let pageSize = 25;
        let totalRecords = 0;
        let sortField = 'updated_at';
        let sortAsc = false;

        let activeTargetAju = '';
        let activeTargetRow = null;

        // Inisialisasi Tema
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                document.documentElement.setAttribute('data-theme', e.data.theme);
                document.body.setAttribute('data-theme', e.data.theme);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadPortalData();

            // Tutup dropdown menu saat klik di luar
            document.addEventListener('click', function(e) {
                const drop = document.getElementById('rowDropdownMenu');
                if (drop && !drop.contains(e.target) && !e.target.classList.contains('action-menu-trigger')) {
                    drop.style.display = 'none';
                }
            });
        });

        // 1. Load Data Table
        let hasAutoSynced = false;
        function loadPortalData(forceLiveSync = false) {
            const tbody = document.getElementById('portalTableBody');
            tbody.innerHTML = `<tr><td colspan="10" class="table-loading"><span class="spinner"></span> Memuat data dokumen NVOCC (Manifest Ceisa 4.0)...</td></tr>`;

            const search = document.getElementById('filterSearch').value.trim();
            const jenis = document.getElementById('filterJenis').value;
            const status = document.getElementById('filterStatus').value;

            // Jika force live sync atau token tersimpan & load pertama kali tanpa filter
            if ((forceLiveSync || (!hasAutoSynced && cachedPortalToken)) && !search && !jenis && !status) {
                hasAutoSynced = true;
                const fd = new FormData();
                fd.append('portal_token', cachedPortalToken);
                fetch(`manifest_api.php?action=sync_ceisa_portal&cmp_id=${encodeURIComponent(activeCmpId)}`, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        fetchLocalList(search, jenis, status);
                    })
                    .catch(() => {
                        fetchLocalList(search, jenis, status);
                    });
                return;
            }

            fetchLocalList(search, jenis, status);
        }

        function fetchLocalList(search, jenis, status) {
            const tbody = document.getElementById('portalTableBody');
            const url = `manifest_api.php?action=get_list&source=portal&cmp_id=${encodeURIComponent(activeCmpId)}&page=${currentPage}&limit=${pageSize}&search=${encodeURIComponent(search || '')}&jenis_manifes=${encodeURIComponent(jenis || '')}&status=${encodeURIComponent(status || '')}&_t=${Date.now()}`;

            fetch(url)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        tableData = res.data || [];
                        totalRecords = (res.pagination && res.pagination.total) ? res.pagination.total : tableData.length;
                        renderTable();
                        renderPagination();
                    } else {
                        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:#cf1322;">⚠️ ${res.message || 'Gagal memuat data.'}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:#cf1322;">⚠️ Terjadi kesalahan koneksi server: ${err.message}</td></tr>`;
                });
        }

        // 2. Render Table Rows
        function renderTable() {
            const tbody = document.getElementById('portalTableBody');
            if (tableData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:35px 20px; color:var(--text-muted);">
                    <div style="font-size:24px; margin-bottom:6px;">📭</div>
                    <div style="font-size:11px; font-weight:600; color:var(--text-heading);">Belum ada data dokumen NVOCC yang tersinkronisasi.</div>
                    <div style="font-size:9.5px; margin-top:4px;">Endpoint Sumber: <code>https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataProses/prosesNPWP-NVOCC/${activeCmpNpwp || ''}</code></div>
                    <div style="margin-top:12px; display:flex; gap:8px; justify-content:center;">
                        <button type="button" class="ant-btn-ceisa-primary" onclick="openSyncPortalModal()">
                            <span>🌐 Tarik dari CEISA 4.0 (Live API)</span>
                        </button>
                        <button type="button" class="ant-btn-ceisa-primary" style="background:#52c41a; border-color:#52c41a;" onclick="openHostToHostEditor()">
                            <span>➕ Pembuatan Dokumen Baru</span>
                        </button>
                    </div>
                </td></tr>`;
                return;
            }

            let html = '';
            tableData.forEach((row, idx) => {
                const aju = row.nomor_aju || '-';
                const ktr = row.kode_kantor || '-';
                const voy = row.nomor_voyage || '-';
                
                // Format Jenis Dokumen
                let jnsLabel = 'Inward (BC 1.1)';
                if (row.jenis_manifes === '12' || (row.jenis_manifes && String(row.jenis_manifes).toLowerCase().includes('outward'))) {
                    jnsLabel = 'Outward (BC 1.1)';
                }

                const noDaftar = row.nomor_bc11 || '';
                let tglDaftar = '';
                if (row.tanggal_bc11 && row.tanggal_bc11 !== '0000-00-00') {
                    const d = new Date(row.tanggal_bc11);
                    tglDaftar = String(d.getDate()).padStart(2, '0') + '-' + String(d.getMonth()+1).padStart(2, '0') + '-' + d.getFullYear();
                }

                // Format Tanggal Berangkat / Tiba
                let tglDoc = '';
                const rawTgl = row.tanggal_berangkat || row.tanggal_tiba || '';
                if (rawTgl && rawTgl !== '0000-00-00 00:00:00') {
                    const dt = new Date(rawTgl);
                    tglDoc = String(dt.getDate()).padStart(2, '0') + '-' + String(dt.getMonth()+1).padStart(2, '0') + '-' + dt.getFullYear();
                }

                const sarana = row.nama_sarana_pengangkut || '-';
                const status = (row.status || 'Draft').trim();
                const badgeClass = getStatusBadgeClass(status);

                html += `<tr>
                    <td><a class="aju-link" onclick="openDetailDocByAju('${aju}')" title="Klik untuk melihat Dokumen Header (CEISA 4.0)">${aju}</a></td>
                    <td>${ktr}</td>
                    <td><strong>${voy}</strong></td>
                    <td>${jnsLabel}</td>
                    <td>${noDaftar}</td>
                    <td>${tglDaftar}</td>
                    <td><span class="status-badge ${badgeClass}">${status}</span></td>
                    <td>${sarana}</td>
                    <td>${tglDoc}</td>
                    <td style="text-align:center;">
                        <button type="button" class="action-menu-trigger" onclick="openRowMenu(event, ${idx})">
                            <span>Menu</span>
                            <span style="font-size:8px;">▾</span>
                        </button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        // 3. Status Badge Mapper
        function getStatusBadgeClass(status) {
            const s = status.toLowerCase();
            if (s.includes('selesai') || s.includes('ready')) return 'status-ready';
            if (s.includes('penerimaan')) return 'status-penerimaan';
            if (s.includes('rekon') || s.includes('rikon')) return 'status-rekon';
            if (s.includes('persetujuan') || s.includes('perbaikan')) return 'status-persetujuan';
            if (s.includes('reject') || s.includes('tolak')) return 'status-reject';
            if (s.includes('kirim') || s.includes('terkirim')) return 'status-terkirim';
            return 'status-draft';
        }

        // 4. Open Action Dropdown Menu (Dinamis 100% Mengikuti Portal Resmi CEISA 4.0)
        function openRowMenu(e, idx) {
            e.stopPropagation();
            const row = tableData[idx];
            if (!row) return;

            activeTargetRow = row;
            activeTargetAju = row.nomor_aju || '';

            const status = (row.status || '').trim().toUpperCase();
            const menu = document.getElementById('rowDropdownMenu');

            let itemsHtml = '';

            // 1. Opsi Standar CEISA 4.0: Riwayat & Cetak (*.pdf)
            itemsHtml += `
                <div class="dropdown-item" onclick="handleMenuAction('riwayat')">
                    <span style="font-size:11px;">🔄</span>
                    <span>Riwayat</span>
                </div>
                <div class="dropdown-item" onclick="handleMenuAction('cetak')">
                    <span style="font-size:11px;">🖨️</span>
                    <span>Cetak (*.pdf)</span>
                </div>
            `;

            // 2. Jika status DRAFT / READY: muncul Periksa Kelengkapan
            if (status.includes('DRAFT') || status.includes('READY')) {
                itemsHtml += `
                    <div class="dropdown-item" onclick="handleMenuAction('cek_lengkap')">
                        <span style="font-size:11px;">📋</span>
                        <span>Periksa Kelengkapan</span>
                    </div>
                `;
            }

            // 3. Back-Up (Unduh backup JSON resmi dokumen)
            itemsHtml += `
                <div class="dropdown-item" onclick="handleMenuAction('backup')">
                    <span style="font-size:11px;">📥</span>
                    <span>Back-Up</span>
                </div>
            `;

            // 4. Lihat (Membuka Dokumen Header & Master BL)
            itemsHtml += `
                <div class="dropdown-item" onclick="handleMenuAction('lihat')" style="color:#1890ff; font-weight:600;">
                    <span style="font-size:11px;">👁️</span>
                    <span>Lihat</span>
                </div>
            `;

            // 5. Jika status PENERIMAAN atau PERSETUJUAN PERBAIKAN: Muncul Ajukan Redress
            if (status.includes('PENERIMAAN') || status.includes('PERBAIKAN') || status.includes('PERSETUJUAN')) {
                itemsHtml += `
                    <div class="dropdown-item" onclick="handleMenuAction('redress')">
                        <span style="font-size:11px;">📝</span>
                        <span>Ajukan Redress</span>
                    </div>
                `;
            }

            // 6. Buka di Host to Host (Opsi bantuan untuk melihat/mengedit di modul Host to Host)
            itemsHtml += `
                <div class="dropdown-divider"></div>
                <div class="dropdown-item" onclick="handleMenuAction('edit')" title="Buka data dokumen ini di modul Manifest Host to Host">
                    <span style="font-size:11px;">✏️</span>
                    <span>Buka di Host to Host</span>
                </div>
            `;

            menu.innerHTML = itemsHtml;
            menu.style.display = 'block';

            const rect = e.currentTarget.getBoundingClientRect();
            let top = rect.bottom + window.scrollY;
            let left = rect.left + window.scrollX - 110;

            if (left + 170 > window.innerWidth) {
                left = window.innerWidth - 180;
            }
            if (top + 220 > window.innerHeight) {
                top = rect.top + window.scrollY - 220;
            }

            menu.style.top = `${top}px`;
            menu.style.left = `${left}px`;
        }

        // 5. Handle Menu Actions
        function handleMenuAction(action) {
            const menu = document.getElementById('rowDropdownMenu');
            if (menu) menu.style.display = 'none';

            const aju = activeTargetAju;
            if (!aju) return;

            if (action === 'lihat') {
                openDetailDoc(activeTargetRow);
            } else if (action === 'riwayat') {
                showRiwayatStatus(activeTargetRow);
            } else if (action === 'cetak') {
                const idNvocc = activeTargetRow ? (activeTargetRow.id_nvocc_header || '') : '';
                cetakPdf(aju, idNvocc);
            } else if (action === 'backup') {
                backupJson(aju);
            } else if (action === 'cek_lengkap') {
                checkKelengkapan(aju);
            } else if (action === 'redress') {
                ajukanRedress(activeTargetRow);
            } else if (action === 'edit') {
                openHostToHostEditor(aju);
            }
        }

        function ajukanRedress(row) {
            const aju = row ? row.nomor_aju : activeTargetAju;
            Swal.fire({
                title: 'Ajukan Redress Manifes',
                html: `Pengajuan perbaikan (Redress) dilakukan melalui modul <strong>Manifest Host to Host</strong>.<br><br>Nomor AJU: <strong>${aju}</strong><br><br>Apakah Anda ingin membuka dokumen ini di Host to Host sekarang?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '📝 Ya, Buka Host to Host',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    openHostToHostEditor(aju);
                }
            });
        }

        // Detail Document State & Handlers (Dokumen Header & Master BL)
        let currentDetailAju = '';
        let currentDetailIdNvocc = '';
        let currentDetailData = null;

        function openDetailDocByAju(aju) {
            const row = tableData.find(r => r.nomor_aju === aju) || { nomor_aju: aju };
            openDetailDoc(row);
        }

        function openDetailDoc(row) {
            if (!row) return;
            currentDetailAju = row.nomor_aju || '';
            currentDetailIdNvocc = row.id_nvocc_header || '';
            currentDetailData = row;

            // Update Header labels from row cache
            let jnsLabel = (row.jenis_manifes === '12' || (row.jenis_manifes && String(row.jenis_manifes).toLowerCase().includes('outward'))) ? 'Outward' : 'Inward';
            document.getElementById('detSubTitle').innerText = `${jnsLabel} (BC 1.1) - LAUT NVOCC`;
            document.getElementById('detNamaPerusahaan').innerText = row.nama_perusahaan || 'TVL GLOBAL LOGISTIK';
            document.getElementById('detAlamatPerusahaan').innerText = row.alamat_perusahaan || '—';
            document.getElementById('detNomorPengajuan').innerText = row.nomor_aju || '—';
            document.getElementById('detNomorDaftar').innerText = row.nomor_bc11 || '—';
            
            let tglDaftar = '—';
            if (row.tanggal_bc11 && row.tanggal_bc11 !== '0000-00-00') {
                const d = new Date(row.tanggal_bc11);
                tglDaftar = String(d.getDate()).padStart(2, '0') + '-' + String(d.getMonth()+1).padStart(2, '0') + '-' + d.getFullYear();
            }
            document.getElementById('detTanggalDaftar').innerText = tglDaftar;
            document.getElementById('detKantorPelayanan').innerText = row.kode_kantor ? (row.kode_kantor + ' - KPPBC TMP TANJUNG PERAK') : '—';

            document.getElementById('detSarana').value = row.nama_sarana_pengangkut || '—';
            document.getElementById('detVoyage').value = row.nomor_voyage || '—';
            document.getElementById('detImo').value = row.imo_number || '—';
            
            let tglDoc = '—';
            const rawTgl = row.tanggal_berangkat || row.tanggal_tiba || '';
            if (rawTgl && rawTgl !== '0000-00-00 00:00:00') {
                tglDoc = rawTgl;
            }
            document.getElementById('detTanggalDoc').value = tglDoc;

            // Switch view
            document.getElementById('viewBrowseSection').style.display = 'none';
            document.getElementById('viewDetailSection').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Fetch Live / Complete Detail from API GetOneNVOCCHeader
            refreshDetailDoc();
        }

        function closeDetailDoc() {
            document.getElementById('viewDetailSection').style.display = 'none';
            document.getElementById('viewBrowseSection').style.display = 'block';
        }

        function refreshDetailDoc() {
            const tbody = document.getElementById('detMasterBlBody');
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);"><span class="spinner"></span> Menghubungi Gateway CEISA 4.0 untuk detail dokumen & Master BL...</td></tr>`;

            const url = `manifest_api.php?action=get_detail_nvocc&id_nvocc_header=${encodeURIComponent(currentDetailIdNvocc)}&nomor_aju=${encodeURIComponent(currentDetailAju)}&cmp_id=${encodeURIComponent(activeCmpId)}&_t=${Date.now()}`;
            
            fetch(url)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK' && res.data) {
                        const d = res.data;
                        currentDetailData = d;
                        if (d.idNvoccHeader) currentDetailIdNvocc = d.idNvoccHeader;

                        // Perbarui data form
                        if (d.namaPerusahaan) document.getElementById('detNamaPerusahaan').innerText = d.namaPerusahaan;
                        if (d.alamatPerusahaan) document.getElementById('detAlamatPerusahaan').innerText = d.alamatPerusahaan;
                        if (d.nomorAju) document.getElementById('detNomorPengajuan').innerText = d.nomorAju;
                        if (d.nomorDaftar) document.getElementById('detNomorDaftar').innerText = d.nomorDaftar;
                        if (d.tanggalDaftar) document.getElementById('detTanggalDaftar').innerText = d.tanggalDaftar;
                        if (d.uraianKantor) document.getElementById('detKantorPelayanan').innerText = d.uraianKantor;
                        if (d.namaSaranaPengangkut) document.getElementById('detSarana').value = d.namaSaranaPengangkut;
                        if (d.nomorVoyage) document.getElementById('detVoyage').value = d.nomorVoyage;
                        if (d.imoNumber) document.getElementById('detImo').value = d.imoNumber;
                        if (d.tanggalBerangkat) document.getElementById('detTanggalDoc').value = d.tanggalBerangkat;
                        else if (d.tanggalTiba) document.getElementById('detTanggalDoc').value = d.tanggalTiba;

                        // Render Master BL List
                        const mbls = d.tdNVOCCMasters || [];
                        if (mbls.length === 0) {
                            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:18px; color:var(--text-muted);">Tidak ada data Master BL untuk dokumen ini.</td></tr>`;
                            return;
                        }

                        let mblHtml = '';
                        mbls.forEach((m, idx) => {
                            const uraianKel = m.uraianKelompokPos || (m.kelompokPos ? `Kelompok Pos ${m.kelompokPos}` : '-');
                            const mblNo = m.masterBl || '-';
                            const tglBl = m.tanggalBl || '-';
                            const jmlPos = m.jumlahPos ?? '-';
                            const posBc11 = m.posBc11 || '-';
                            const respon = m.respon || 'REKON';
                            const status = m.status || '—';
                            const posStatus = m.posStatus || '-';

                            mblHtml += `<tr>
                                <td>${uraianKel}</td>
                                <td><strong>${mblNo}</strong></td>
                                <td>${tglBl}</td>
                                <td>${jmlPos}</td>
                                <td><strong>${posBc11}</strong></td>
                                <td><span class="status-badge status-rekon">${respon}</span></td>
                                <td>${status}</td>
                                <td>${posStatus}</td>
                                <td style="text-align:center;">
                                    <button type="button" class="action-menu-trigger" onclick="openMasterBlAction(${idx})">
                                        <span>Aksi</span>
                                        <span style="font-size:8px;">▾</span>
                                    </button>
                                </td>
                            </tr>`;
                        });
                        tbody.innerHTML = mblHtml;
                    } else {
                        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:18px; color:#cf1322;">⚠️ ${res.message || 'Gagal memuat detail dari CEISA 4.0.'}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:18px; color:#cf1322;">⚠️ Terjadi kesalahan koneksi server: ${err.message}</td></tr>`;
                });
        }

        function doCekRekon() {
            Swal.fire({
                title: 'Mengecek Rekonsiliasi CEISA 4.0...',
                html: `Menghubungi endpoint Gateway Bea Cukai untuk memeriksa status Rekon AJU:<br><strong>${currentDetailAju}</strong>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch(`manifest_api.php?action=cek_rekon&id_nvocc_header=${encodeURIComponent(currentDetailIdNvocc)}&nomor_aju=${encodeURIComponent(currentDetailAju)}&cmp_id=${encodeURIComponent(activeCmpId)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        Swal.fire({
                            title: 'Status Rekon Terkonfirmasi',
                            html: `<div style="text-align:center; font-size:11px; line-height:1.6;">
                                <div style="font-size:32px; margin-bottom:8px;">🔄</div>
                                <strong>${res.message}</strong><br>
                                <span style="color:var(--text-muted); font-size:9.5px;">Waktu Respon: ${res.waktu_rekon || new Date().toLocaleString()}</span>
                            </div>`,
                            icon: 'success',
                            confirmButtonColor: '#1890ff'
                        });
                        refreshDetailDoc();
                    } else {
                        Swal.fire({
                            title: 'Informasi Rekonsiliasi',
                            html: res.message || 'Belum ada update respon rekon terbaru.',
                            icon: 'info',
                            confirmButtonColor: '#1890ff'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({ title: 'Error Koneksi', text: err.message, icon: 'error' });
                });
        }

        function openMasterBlAction(idx) {
            if (!currentDetailData || !currentDetailData.tdNVOCCMasters || !currentDetailData.tdNVOCCMasters[idx]) return;
            const m = currentDetailData.tdNVOCCMasters[idx];
            Swal.fire({
                title: `Master B/L: ${m.masterBl}`,
                html: `
                    <div style="text-align:left; font-size:10.5px; line-height:1.7; background:var(--bg-input); padding:12px; border-radius:4px; border:1px solid var(--border);">
                        <strong>Kelompok Pos:</strong> ${m.uraianKelompokPos || m.kelompokPos}<br>
                        <strong>Tanggal B/L:</strong> ${m.tanggalBl || '-'}<br>
                        <strong>Jumlah Pos:</strong> ${m.jumlahPos || 1}<br>
                        <strong>Jumlah Kontainer:</strong> ${m.jumlahContainer || 0}<br>
                        <strong>POS BC 1.1:</strong> ${m.posBc11 || '-'}<br>
                        <strong>Respon CEISA:</strong> <span class="status-badge status-rekon">${m.respon || 'REKON'}</span><br>
                        <strong>Waktu Rekon:</strong> ${m.waktuRekon || '-'}<br>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: '✏️ Kelola di Host to Host',
                cancelButtonText: 'Tutup'
            }).then(r => {
                if (r.isConfirmed) {
                    openHostToHostEditor(currentDetailAju);
                }
            });
        }

        function showRiwayatStatus(row) {
            const aju = row ? row.nomor_aju : activeTargetAju;
            Swal.fire({
                title: '📜 Riwayat Respon CEISA 4.0',
                html: `
                    <div style="text-align:left; font-size:10.5px; line-height:1.7;">
                        <strong>Nomor AJU:</strong> ${aju}<br>
                        <strong>Status Saat Ini:</strong> <span class="status-badge status-ready">${row ? (row.status || 'Draft') : 'Draft'}</span><br>
                        <strong>Nomor Daftar (BC 1.1):</strong> ${row ? (row.nomor_bc11 || '-') : '-'}<br>
                        <strong>Tanggal Daftar:</strong> ${row ? (row.tanggal_bc11 || '-') : '-'}<br>
                        <strong>Respon Terakhir:</strong> ${row ? (row.respon_terakhir || 'Sinkronisasi Berhasil') : '-'}<br>
                    </div>
                `,
                confirmButtonColor: '#1890ff',
                confirmButtonText: 'Tutup'
            });
        }

        function cetakPdf(aju, idNvocc = '') {
            const url = `pdf_manifest.php?nomor_aju=${encodeURIComponent(aju || '')}&id_nvocc_header=${encodeURIComponent(idNvocc || '')}&cmp_id=${encodeURIComponent(activeCmpId)}`;
            window.open(url, '_blank');
        }

        function backupJson(aju) {
            window.location.href = `manifest_api.php?action=generate_json&download=1&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(activeCmpId)}`;
        }

        // 6. Navigation to Host to Host Editor
        function openHostToHostEditor(nomorAju) {
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('manifest', nomorAju || '');
            } else {
                window.location.href = `manifest.php?cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju || '')}`;
            }
        }

        // 7. Cek Status CEISA
        function checkStatusCeisa(nomorAju) {
            Swal.fire({
                title: 'Mengecek Status CEISA...',
                html: `Menghubungi server Bea Cukai untuk Nomor AJU:<br><strong>${nomorAju}</strong>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch(`manifest_api.php?action=api_status&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        const d = res.data;
                        let detailHtml = `<div style="text-align:left; font-size:11px; line-height:1.6; background:var(--bg-input); padding:10px; border-radius:4px; border:1px solid var(--border);">`;
                        detailHtml += `<strong>Nomor AJU:</strong> ${nomorAju}<br>`;
                        if (typeof d === 'object') {
                            for (const [k, v] of Object.entries(d)) {
                                if (typeof v !== 'object') {
                                    detailHtml += `<strong>${k}:</strong> ${v}<br>`;
                                }
                            }
                        } else {
                            detailHtml += d;
                        }
                        detailHtml += `</div>`;

                        Swal.fire({
                            title: 'Status Respon CEISA 4.0',
                            html: detailHtml,
                            icon: 'info',
                            confirmButtonColor: '#1890ff'
                        });
                        loadPortalData();
                    } else if (res.status === 'WARNING') {
                        Swal.fire({
                            title: 'Informasi Status',
                            html: res.message,
                            icon: 'warning',
                            confirmButtonColor: '#1890ff'
                        });
                    } else {
                        Swal.fire({
                            title: 'Respon Cek Status',
                            html: res.message || 'Gagal memeriksa status respon CEISA.',
                            icon: 'info',
                            confirmButtonColor: '#1890ff'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({
                        title: 'Error Koneksi',
                        text: err.message,
                        icon: 'error',
                        confirmButtonColor: '#ff4d4f'
                    });
                });
        }

        // 8. Cek Kelengkapan
        function checkKelengkapan(nomorAju) {
            Swal.fire({
                title: 'Memvalidasi Kelengkapan Dokumen...',
                html: `Memeriksa header, kelompok pos, House B/L, dan kontainer untuk AJU:<br><strong>${nomorAju}</strong>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch(`manifest_api.php?action=api_cek_lengkap&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        Swal.fire({
                            title: 'Status Lengkap (READY)',
                            html: res.message,
                            icon: 'success',
                            confirmButtonColor: '#52c41a'
                        });
                        loadPortalData();
                    } else {
                        Swal.fire({
                            title: 'Kendala Kelengkapan Data',
                            html: res.message || 'Data manifes belum lengkap.',
                            icon: 'warning',
                            confirmButtonColor: '#fa8c16'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({ title: 'Error', text: err.message, icon: 'error' });
                });
        }

        // 9. Kirim ke Bea Cukai
        function kirimCeisa(nomorAju) {
            Swal.fire({
                title: 'Kirim Manifes ke Bea Cukai?',
                html: `Anda akan mengirim data Manifes NVOCC dengan Nomor AJU:<br><strong>${nomorAju}</strong><br><br><small style="color:var(--text-muted);">Pastikan data telah berstatus READY dan telah dicek kelengkapannya.</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🚀 Ya, Kirim Sekarang',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    Swal.fire({
                        title: 'Mengirim Manifes ke CEISA 4.0...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    fetch(`manifest_api.php?action=api_kirim&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`, { method: 'POST' })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({
                                    title: 'Berhasil Terkirim!',
                                    html: res.message,
                                    icon: 'success',
                                    confirmButtonColor: '#52c41a'
                                });
                                loadPortalData();
                            } else {
                                Swal.fire({
                                    title: 'Gagal Kirim',
                                    html: res.message,
                                    icon: 'error',
                                    confirmButtonColor: '#ff4d4f'
                                });
                            }
                        })
                        .catch(err => Swal.fire({ title: 'Error', text: err.message, icon: 'error' }));
                }
            });
        }

        // 10. Rekon Manifes
        function rekonCeisa(nomorAju) {
            Swal.fire({
                title: 'Proses Rekonsiliasi (Pecah Pos)?',
                html: `Kirim perintah rekonsiliasi pemecahan pos Manifes NVOCC untuk AJU:<br><strong>${nomorAju}</strong>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#fa8c16',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🔄 Rekon Sekarang',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    Swal.fire({ title: 'Memproses Rekonsiliasi...', didOpen: () => Swal.showLoading() });
                    fetch(`manifest_api.php?action=api_rekon&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`, { method: 'POST' })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({ title: 'Rekon Berhasil', html: res.message, icon: 'success' });
                                loadPortalData();
                            } else {
                                Swal.fire({ title: 'Gagal Rekon', html: res.message, icon: 'error' });
                            }
                        });
                }
            });
        }

        // 11. View JSON Payload
        function viewJsonPayload(nomorAju) {
            Swal.fire({ title: 'Menyiapkan JSON Payload...', didOpen: () => Swal.showLoading() });
            fetch(`manifest_api.php?action=generate_json&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        const jsonPretty = JSON.stringify(res.payload, null, 2);
                        Swal.fire({
                            title: `Payload JSON - ${nomorAju}`,
                            html: `
                                <div style="text-align:right; margin-bottom:6px;">
                                    <button class="ant-btn-ceisa-primary" style="height:20px; font-size:9px;" onclick="navigator.clipboard.writeText(document.getElementById('txtJsonPayload').value); Swal.showValidationMessage('✅ JSON berhasil disalin!');">📋 Salin JSON</button>
                                </div>
                                <textarea id="txtJsonPayload" readonly style="width:100%; height:280px; font-family:monospace; font-size:10px; background:var(--bg-input); color:var(--text-main); border:1px solid var(--border); padding:8px; border-radius:3px; outline:none; resize:vertical;">${jsonPretty}</textarea>
                            `,
                            width: 680,
                            confirmButtonColor: '#1890ff',
                            confirmButtonText: 'Tutup'
                        });
                    } else {
                        Swal.fire({ title: 'Gagal', html: res.message, icon: 'error' });
                    }
                });
        }

        // 12. Copy AJU
        function copyAju(nomorAju) {
            Swal.fire({
                title: 'Duplikasi / Copy AJU Manifes',
                html: `Duplikasi dokumen AJU: <strong>${nomorAju}</strong> beserta seluruh House B/L, Petikemas, dan HS barang ke AJU baru.<br><br>
                <div style="text-align:left; font-size:10px; margin-bottom:4px; font-weight:600; color:var(--text-muted);">NOMOR AJU BARU (Biarkan kosong untuk generate otomatis 26 digit):</div>
                <input type="text" id="swalTargetAju" class="filter-input" placeholder="Generate otomatis..." style="width:100%; height:26px;">`,
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: '📑 Duplikasi Sekarang',
                cancelButtonText: 'Batal',
                preConfirm: () => {
                    return document.getElementById('swalTargetAju').value.trim();
                }
            }).then(r => {
                if (r.isConfirmed) {
                    const targetAju = r.value || '';
                    Swal.fire({ title: 'Menduplikasi Manifes...', didOpen: () => Swal.showLoading() });

                    const fd = new FormData();
                    fd.append('source_nomor_aju', nomorAju);
                    fd.append('target_nomor_aju', targetAju);
                    fd.append('target_cmp_id', activeCmpId);

                    fetch(`manifest_api.php?action=copy_aju&cmp_id=${encodeURIComponent(activeCmpId)}`, { method: 'POST', body: fd })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({ title: 'Duplikasi Berhasil!', html: res.message, icon: 'success' });
                                loadPortalData();
                            } else {
                                Swal.fire({ title: 'Gagal Duplikasi', html: res.message, icon: 'error' });
                            }
                        });
                }
            });
        }

        // 13. Hapus Dokumen
        function deleteDokumen(nomorAju) {
            Swal.fire({
                title: 'Hapus Dokumen Manifes?',
                html: `Apakah Anda yakin ingin menghapus dokumen Manifes AJU:<br><strong>${nomorAju}</strong>?<br><br><span style="color:#cf1322;">Seluruh data House B/L, kemasan, dan petikemas terkait akan dihapus secara permanen.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#cf1322',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🗑️ Ya, Hapus',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    Swal.fire({ title: 'Menghapus Manifes...', didOpen: () => Swal.showLoading() });
                    fetch(`manifest_api.php?action=delete_manifes&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`, { method: 'POST' })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({ title: 'Terhapus', text: res.message, icon: 'success' });
                                loadPortalData();
                            } else {
                                Swal.fire({ title: 'Gagal', html: res.message, icon: 'error' });
                            }
                        });
                }
            });
        }

        // 14. Live Tarik Data dari Portal CEISA 4.0
        function openSyncPortalModal() {
            Swal.fire({
                title: '🌐 Tarik Data dari Portal CEISA 4.0',
                html: `
                    <div style="text-align:left; font-size:10.5px; line-height:1.5; margin-bottom:12px;">
                        Fitur ini menarik langsung daftar dokumen Manifes NVOCC yang aktif dari server API Portal CEISA 4.0 (<code>/ManifesSchema/v1/DataProses/prosesNPWP-NVOCC/${activeCmpNpwp || ''}</code>).
                    </div>
                    <div style="text-align:left; margin-bottom:6px;">
                        <label style="font-weight:600; font-size:9.5px; color:var(--text-muted); text-transform:uppercase;">Portal Bearer JWT Token (MFA Token)</label>
                        <textarea id="swalPortalToken" placeholder="Tempel token Bearer dari portal.beacukai.go.id (jika sudah ada tersimpan akan otomatis digunakan)..." style="width:100%; height:75px; font-family:monospace; font-size:9px; background:var(--bg-input); color:var(--text-main); border:1px solid var(--border); border-radius:3px; padding:6px; outline:none; resize:vertical;">${cachedPortalToken || ''}</textarea>
                    </div>
                    <div style="text-align:left;">
                        <label style="font-weight:600; font-size:9.5px; color:var(--text-muted); text-transform:uppercase;">Portal API Key</label>
                        <input type="text" id="swalApiKey" value="6222a75e-1dbb-493e-9461-27f721097e9c" class="filter-input" style="width:100%; height:24px;">
                    </div>
                `,
                width: 540,
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🔄 Mulai Sinkronisasi',
                cancelButtonText: 'Batal',
                preConfirm: () => {
                    const token = document.getElementById('swalPortalToken').value.trim();
                    const apiKey = document.getElementById('swalApiKey').value.trim();
                    if (!token) {
                        Swal.showValidationMessage('Portal Bearer Token wajib diisi.');
                        return false;
                    }
                    return { token, apiKey };
                }
            }).then(r => {
                if (r.isConfirmed) {
                    const { token, apiKey } = r.value;
                    cachedPortalToken = token;

                    Swal.fire({
                        title: 'Menyinkronkan dengan CEISA 4.0...',
                        html: 'Menghubungi endpoint Gateway Bea Cukai...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    const fd = new FormData();
                    fd.append('portal_token', token);
                    fd.append('api_key', apiKey);

                    fetch(`manifest_api.php?action=sync_ceisa_portal&cmp_id=${encodeURIComponent(activeCmpId)}`, {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire({
                                title: 'Sinkronisasi Selesai!',
                                html: res.message,
                                icon: 'success',
                                confirmButtonColor: '#52c41a'
                            });
                            loadPortalData();
                        } else {
                            Swal.fire({
                                title: 'Gagal Sinkronisasi',
                                html: res.message || 'Terjadi kesalahan saat menghubungi API Bea Cukai.',
                                icon: 'error',
                                confirmButtonColor: '#ff4d4f'
                            });
                        }
                    })
                    .catch(err => {
                        Swal.fire({ title: 'Error', text: err.message, icon: 'error' });
                    });
                }
            });
        }

        // 15. Restore Data Modal
        function openRestoreDataModal() {
            Swal.fire({
                title: '📥 Restore Data Manifes',
                html: `
                    <div style="text-align:left; font-size:10.5px; line-height:1.5;">
                        Pilih file Excel Backup Manifes (.xlsx) atau file JSON Dokumen untuk mengembalikan (restore) data lengkap termasuk House B/L, HS, dan petikemas ke sistem.
                    </div>
                    <div style="margin-top:14px; text-align:left;">
                        <input type="file" id="restoreFile" accept=".xlsx,.xls,.json" style="font-size:10px;">
                    </div>
                `,
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: 'Unggah & Restore',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    const fileInput = document.getElementById('restoreFile');
                    if (!fileInput.files || fileInput.files.length === 0) {
                        Swal.fire({ title: 'Peringatan', text: 'Silakan pilih file terlebih dahulu.', icon: 'warning' });
                        return;
                    }
                    // Buka import di Host to Host
                    openHostToHostEditor();
                }
            });
        }

        // 16. Filter Drawer Toggle
        function toggleFilterDrawer() {
            const drw = document.getElementById('filterDrawer');
            drw.classList.toggle('open');
        }

        function resetFilterDrawer() {
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterJenis').value = '';
            document.getElementById('filterStatus').value = '';
            loadPortalData();
        }

        // 17. Pagination Handlers
        function changePage(p) {
            const maxPage = Math.max(1, Math.ceil(totalRecords / pageSize));
            if (p < 1 || p > maxPage) return;
            currentPage = p;
            loadPortalData();
        }

        function changePageSize(sz) {
            pageSize = parseInt(sz, 10);
            currentPage = 1;
            loadPortalData();
        }

        function renderPagination() {
            const maxPage = Math.max(1, Math.ceil(totalRecords / pageSize));
            document.getElementById('pageInfo').innerText = `Menampilkan ${tableData.length} dari total ${totalRecords} data`;
            document.getElementById('btnPrevPage').disabled = (currentPage <= 1);
            document.getElementById('btnNextPage').disabled = (currentPage >= maxPage);

            const numContainer = document.getElementById('pageNumbers');
            numContainer.innerHTML = '';

            for (let i = 1; i <= maxPage; i++) {
                if (i === 1 || i === maxPage || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `page-btn ${i === currentPage ? 'active' : ''}`;
                    btn.innerText = i;
                    btn.onclick = () => changePage(i);
                    numContainer.appendChild(btn);
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    const dots = document.createElement('span');
                    dots.innerText = '...';
                    dots.style.padding = '0 2px';
                    dots.style.color = 'var(--text-muted)';
                    numContainer.appendChild(dots);
                }
            }
        }

        function toggleSort(field) {
            if (sortField === field) {
                sortAsc = !sortAsc;
            } else {
                sortField = field;
                sortAsc = true;
            }
            tableData.sort((a, b) => {
                let vA = (a[field] || '').toString().toLowerCase();
                let vB = (b[field] || '').toString().toLowerCase();
                return sortAsc ? vA.localeCompare(vB) : vB.localeCompare(vA);
            });
            renderTable();
        }
    </script>
</body>
</html>

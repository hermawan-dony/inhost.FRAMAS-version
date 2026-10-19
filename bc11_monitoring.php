<?php
/**
 * CEISA 4.0 - BC 1.1 Full Monitoring Dashboard & Manifest Hub (bc11_monitoring.php)
 * Monitoring terpadu: Sarana Pengangkut (Data Umum), Peti Kemas (Container), dan Bill of Lading (B/L)
 * Dilengkapi REST & TSV API Services untuk Integrasi PowerBuilder (DataWindow ImportString).
 */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$activeCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_REQUEST['cmp']) ? trim($_REQUEST['cmp']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : (!empty($cmp_id) ? trim($cmp_id) : 'TVL'))));
if (empty($activeCmp) || $activeCmp === 'default') {
    $activeCmp = 'TVL';
}

$initialAju = trim($_GET['nomor_aju'] ?? '');
$initialBl  = trim($_GET['no_bl'] ?? ($_GET['nomor_host_bl'] ?? ''));
$initialTab = trim($_GET['tab'] ?? 'vessel'); // 'vessel' | 'container' | 'bl'

// Ambil daftar perusahaan aktif
$companies = [];
$resCmp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, kode_kantor, subdomain FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_name ASC");
if ($resCmp) {
    while ($r = $resCmp->fetch_assoc()) {
        $companies[] = $r;
    }
}

// Default kode kantor
$defaultKantor = '070100'; // Default Tanjung Perak
$activeCmpName = 'PT TVL GLOBAL LOGISTIK';
$activeCmpNpwp = '0033093287048000';
foreach ($companies as $c) {
    if ($c['cmp_id'] === $activeCmp || strcasecmp($c['cmp_id'], $activeCmp) === 0 || (!empty($c['subdomain']) && strcasecmp($c['subdomain'], $activeCmp) === 0) || $c['npwp'] === $activeCmp) {
        $activeCmp = $c['cmp_id'];
        if (!empty($c['kode_kantor'])) $defaultKantor = $c['kode_kantor'];
        if (!empty($c['cmp_name'])) $activeCmpName = $c['cmp_name'];
        if (!empty($c['npwp'])) $activeCmpNpwp = $c['npwp'];
        break;
    }
}

// 1. Ambil daftar unik Sarana Pengangkut (Nama Kapal) dari Data Manifest CEISA 4.0
$manifestVessels = [];
$qVessel = $koneksi->query("SELECT DISTINCT TRIM(nama_sarana_pengangkut) AS vessel 
                            FROM bc_ceisa_manifes_header 
                            WHERE nama_sarana_pengangkut IS NOT NULL AND TRIM(nama_sarana_pengangkut) != '' 
                            ORDER BY vessel ASC");
if ($qVessel) {
    while ($r = $qVessel->fetch_assoc()) {
        $vVal = strtoupper(trim($r['vessel']));
        if (!empty($vVal) && !in_array($vVal, $manifestVessels)) {
            $manifestVessels[] = $vVal;
        }
    }
}
if (empty($manifestVessels)) {
    $manifestVessels = ['SINAR SUNDA', 'MTT SANDAKAN', 'KOTA MANIS', 'SINAR JAWA', 'WAN HAI 351', 'INTERASIA TRIUMPH', 'BEAR MOUNTAIN BRIDGE'];
}

// 2. Ambil daftar unik Kode Kantor dari Data Manifest CEISA 4.0
$manifestKantors = [];
$qKantor = $koneksi->query("SELECT DISTINCT TRIM(kode_kantor) AS kantor 
                            FROM bc_ceisa_manifes_header 
                            WHERE kode_kantor IS NOT NULL AND TRIM(kode_kantor) != '' 
                            ORDER BY kantor ASC");
if ($qKantor) {
    while ($r = $qKantor->fetch_assoc()) {
        $kVal = trim($r['kantor']);
        if (!empty($kVal) && !in_array($kVal, $manifestKantors)) {
            $manifestKantors[] = $kVal;
        }
    }
}
if (!in_array('070100', $manifestKantors)) $manifestKantors[] = '070100';
if (!in_array('040300', $manifestKantors)) $manifestKantors[] = '040300';
sort($manifestKantors);

$kantorNames = [
    '070100' => 'KPU Tanjung Perak (Surabaya)',
    '040300' => 'KPU Tanjung Priok (Jakarta)',
    '050100' => 'KPU Tanjung Priok',
    '100500' => 'KPPBC Panjang (Lampung)',
    '060100' => 'KPPBC Tanjung Emas (Semarang)',
    '030700' => 'KPPBC Belawan (Medan)',
    '020400' => 'KPU Batam',
    '010700' => 'KPPBC Dumai (Riau)',
    '050900' => 'KPPBC Cikarang'
];

// 3. Ambil daftar Peti Kemas (Container) dari Data Manifest
$manifestContainers = [];
$qCont = $koneksi->query("SELECT DISTINCT TRIM(nomor_container) AS cont 
                          FROM bc_ceisa_manifes_bl_petikemas 
                          WHERE nomor_container IS NOT NULL AND TRIM(nomor_container) != '' 
                          ORDER BY cont ASC");
if ($qCont) {
    while ($r = $qCont->fetch_assoc()) {
        $cVal = strtoupper(trim($r['cont']));
        if (!empty($cVal) && !in_array($cVal, $manifestContainers)) {
            $manifestContainers[] = $cVal;
        }
    }
}
if (empty($manifestContainers)) {
    $manifestContainers = ['NYKU3933307', 'KKTU8104447', 'BMOU2795232', 'ONEU2187200', 'NYKU8125687'];
}

// 4. Ambil daftar B/L (Host BL & Master BL) dari Data Manifest
$manifestBls = [];
$qBl = $koneksi->query("SELECT b.nomor_host_bl, b.nomor_bl, b.nomor_aju, h.nama_sarana_pengangkut, h.nomor_voyage 
                       FROM bc_ceisa_manifes_bl b 
                       LEFT JOIN bc_ceisa_manifes_header h ON h.nomor_aju = b.nomor_aju 
                       WHERE b.nomor_host_bl IS NOT NULL AND TRIM(b.nomor_host_bl) != '' 
                       ORDER BY b.id DESC LIMIT 100");
if ($qBl) {
    while ($r = $qBl->fetch_assoc()) {
        $manifestBls[] = [
            'hbl' => strtoupper(trim($r['nomor_host_bl'])),
            'mbl' => strtoupper(trim($r['nomor_bl'] ?? '')),
            'aju' => trim($r['nomor_aju'] ?? ''),
            'vessel' => trim(($r['nama_sarana_pengangkut'] ?? '') . ' ' . ($r['nomor_voyage'] ?? ''))
        ];
    }
}
if (empty($manifestBls)) {
    $manifestBls = [
        ['hbl' => 'SUB600398100', 'mbl' => '', 'aju' => '', 'vessel' => 'KOTA MANIS 0629N'],
        ['hbl' => 'TVL2610120FKAT', 'mbl' => 'ONEYSUBG30948300', 'aju' => '07010047031520261019808094', 'vessel' => 'SINAR JAWA 246N'],
        ['hbl' => 'TVL2610121FKAT', 'mbl' => 'ONEYSUBG30948300', 'aju' => '07010047031520261019808094', 'vessel' => 'SINAR JAWA 246N']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Manifes BC 1.1 & Sarana Pengangkut - CEISA 4.0</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-main: #f0f3f8;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #0891b2;
            --purple: #7c3aed;
        }

        body[data-theme="dark"] {
            --bg-main: #0f172a;
            --bg-card: #1e293b;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --border: #334155;
            --primary: #38bdf8;
            --primary-hover: #0284c7;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            height: 100vh;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-main);
            display: flex;
            flex-direction: column;
            padding: 6px 10px;
            box-sizing: border-box;
            font-size: 10px;
        }

        /* Top Header */
        .top-navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            padding: 5px 12px;
            border-radius: 5px;
            border: 1px solid var(--border);
            margin-bottom: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            flex-shrink: 0;
            flex-wrap: wrap;
            gap: 8px;
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-icon {
            width: 26px;
            height: 26px;
            border-radius: 5px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            box-shadow: 0 1px 3px rgba(37,99,235,0.3);
        }

        .header-text h1 {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: -0.2px;
        }

        .header-text p {
            margin: 1px 0 0;
            font-size: 9px;
            color: var(--text-muted);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Nav Tabs */
        .nav-tabs-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 3px 6px;
            margin-bottom: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            flex-shrink: 0;
            flex-wrap: wrap;
            gap: 6px;
        }

        .nav-tabs-group {
            display: flex;
            gap: 4px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            outline: none;
            padding: 4px 10px;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .tab-btn:hover {
            color: var(--text-main);
            background: rgba(0,0,0,0.03);
        }

        .tab-btn.active {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 1px 4px rgba(37,99,235,0.25);
        }

        .tab-badge {
            background: rgba(255,255,255,0.25);
            padding: 1px 5px;
            border-radius: 8px;
            font-size: 8.5px;
            font-weight: 700;
        }

        .tab-btn:not(.active) .tab-badge {
            background: rgba(0,0,0,0.08);
            color: var(--text-muted);
        }

        /* Tab Content Panes (Full Height Flex) */
        .tab-content-pane {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        /* Filter Panel */
        .filter-panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 6px 12px;
            margin-bottom: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            flex-shrink: 0;
        }

        .form-row {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .form-group label {
            font-size: 9.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .form-control {
            height: 25px;
            padding: 2px 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 10px;
            outline: none;
            transition: border-color 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18);
        }

        /* Combobox Searchable Dropdown */
        .combobox-wrapper {
            position: relative;
            width: 100%;
        }
        .combobox-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .combobox-input-wrap .form-control {
            width: 100%;
            padding-right: 26px;
        }
        .btn-combobox-toggle {
            position: absolute;
            right: 2px;
            top: 2px;
            bottom: 2px;
            width: 22px;
            background: transparent;
            border: none;
            outline: none;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9.5px;
            border-radius: 3px;
            transition: all 0.15s;
        }
        .btn-combobox-toggle:hover {
            color: var(--primary);
            background: rgba(37, 99, 235, 0.08);
        }
        .combobox-dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 5px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            z-index: 1050;
            padding: 3px;
        }
        .combobox-dropdown-menu.show {
            display: block;
        }
        .combobox-opt-item {
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-main);
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background 0.12s;
        }
        .combobox-opt-item:hover, .combobox-opt-item.active {
            background: rgba(37, 99, 235, 0.08);
            color: var(--primary);
        }
        .combobox-opt-sub {
            font-size: 8.5px;
            font-weight: 400;
            color: var(--text-muted);
        }
        .combobox-no-match {
            padding: 8px 10px;
            font-size: 9.5px;
            color: var(--text-muted);
            text-align: center;
        }

        /* Buttons */
        .btn {
            height: 25px;
            padding: 0 10px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 10px;
            cursor: pointer;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-primary { background: #2563eb; color: #fff; border-color: #1d4ed8; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #059669; color: #fff; border-color: #047857; }
        .btn-success:hover { background: #047857; }
        .btn-info { background: #0891b2; color: #fff; border-color: #0e7490; }
        .btn-info:hover { background: #0e7490; }
        .btn-purple { background: #7c3aed; color: #fff; border-color: #6d28d9; }
        .btn-purple:hover { background: #6d28d9; }
        .btn-secondary { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; }
        body[data-theme="dark"] .btn-secondary { background: #334155; color: #f1f5f9; border-color: #475569; }

        .btn-chip {
            height: 20px;
            padding: 0 8px;
            font-size: 9px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-muted);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.15s;
        }
        .btn-chip:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(37, 99, 235, 0.05);
        }

        /* Data Grid Table (Full Height Flex) */
        .grid-card {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            margin-bottom: 0;
        }

        .grid-header {
            padding: 5px 10px;
            background: rgba(0,0,0,0.015);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            flex-wrap: wrap;
            gap: 6px;
        }

        .grid-title {
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .table-responsive {
            width: 100%;
            flex: 1;
            min-height: 0;
            max-height: none !important;
            overflow-y: auto;
            overflow-x: auto;
        }

        .ceisa-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            text-align: left;
            white-space: nowrap;
        }

        .ceisa-table th {
            position: sticky;
            top: 0;
            background: var(--bg-card);
            color: var(--text-muted);
            font-weight: 600;
            padding: 5px 8px;
            border-bottom: 1px solid var(--border);
            z-index: 5;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .ceisa-table td {
            padding: 4px 8px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text-main);
        }

        .ceisa-table tbody tr:hover {
            background: rgba(37, 99, 235, 0.04);
        }

        .ceisa-table tbody tr.selected {
            background: rgba(37, 99, 235, 0.1) !important;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 8.5px;
            font-weight: 600;
        }

        .badge-inward { background: rgba(37, 99, 235, 0.12); color: #1d4ed8; border: 1px solid rgba(37,99,235,0.25); }
        .badge-outward { background: rgba(245, 158, 11, 0.12); color: #b45309; border: 1px solid rgba(245,158,11,0.25); }
        .badge-pos { background: #dbeafe; color: #1e40af; font-weight: 700; padding: 1px 5px; border-radius: 3px; font-size: 8.5px; }
        .badge-kantor { background: #f1f5f9; color: #475569; font-weight: 600; padding: 1px 5px; border-radius: 3px; font-size: 8.5px; }
        .badge-kade { background: #fef3c7; color: #92400e; font-weight: 600; padding: 1px 5px; border-radius: 3px; font-size: 8.5px; }

        /* Spinner */
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: currentColor;
            animation: spin 0.8s ease-in-out infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-box {
            background: var(--bg-card);
            border-radius: 12px;
            max-width: 820px;
            width: 100%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            border: 1px solid var(--border);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-10px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 8px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0,0,0,0.015);
        }
        .modal-title {
            font-size: 11.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .modal-body {
            padding: 10px 14px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 8px 14px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: rgba(0,0,0,0.015);
        }

        /* Key-Value Details */
        .kv-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 6px;
        }
        .kv-item {
            background: var(--bg-main);
            padding: 5px 8px;
            border-radius: 4px;
            border: 1px solid var(--border);
        }
        .kv-label {
            font-size: 8.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }
        .kv-val {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-main);
            word-break: break-all;
        }

        /* PowerBuilder Code Box */
        .code-box {
            background: #0f172a;
            color: #f8fafc;
            padding: 12px 16px;
            border-radius: 8px;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            overflow-x: auto;
            position: relative;
            margin: 8px 0;
            line-height: 1.5;
        }
        .code-box .btn-copy-code {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #334155;
            color: #fff;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
        }
        .code-box .btn-copy-code:hover { background: #475569; }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($_GET['theme'] ?? 'light') ?>">

    <!-- Top Navigation Bar -->
    <div class="top-navbar">
        <div class="header-title-box">
            <div class="header-icon">
                <i class="fa-solid fa-ship"></i>
            </div>
            <div class="header-text">
                <h1>CEISA 4.0 - BC 1.1 Monitoring & Manifest Hub</h1>
                <p>Dashboard Monitoring Realtime Kedatangan Sarana Pengangkut, Kontainer & Pos Bill of Lading (Inward / Outward BC 1.1)</p>
            </div>
        </div>
        <input type="hidden" id="selectCompany" value="<?= htmlspecialchars($activeCmp) ?>">
    </div>

    <!-- Tab Navigation (3 Tab Utama Portal CEISA 4.0) -->
    <div class="nav-tabs-wrapper">
        <div class="nav-tabs-group">
            <button type="button" id="tabBtnVessel" class="tab-btn <?= ($initialTab === 'vessel') ? 'active' : '' ?>" onclick="switchTab('vessel')">
                <i class="fa-solid fa-ship"></i> 1. Data Umum (Sarana Pengangkut)
                <span id="badgeVesselCount" class="tab-badge">-</span>
            </button>
            <button type="button" id="tabBtnContainer" class="tab-btn <?= ($initialTab === 'container') ? 'active' : '' ?>" onclick="switchTab('container')">
                <i class="fa-solid fa-boxes-stacked"></i> 2. Peti Kemas (Container)
                <span id="badgeContainerCount" class="tab-badge">-</span>
            </button>
            <button type="button" id="tabBtnBl" class="tab-btn <?= ($initialTab === 'bl') ? 'active' : '' ?>" onclick="switchTab('bl')">
                <i class="fa-solid fa-file-invoice"></i> 3. Bill Of Lading (B/L)
                <span id="badgeBlCount" class="tab-badge">-</span>
            </button>
        </div>
        <div style="font-size: 9.5px; color: var(--text-muted);">
            <i class="fa-solid fa-bolt" style="color: var(--success);"></i> Live API Gateway Bea Cukai CEISA 4.0
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- TAB 1: DATA UMUM (SARANA PENGANGKUT / VESSEL) -->
    <!-- ========================================================================= -->
    <div id="tabContentVessel" class="tab-content-pane" style="<?= ($initialTab === 'vessel') ? '' : 'display:none;' ?>">
        <div class="filter-panel">
            <form id="formVessel" onsubmit="event.preventDefault(); triggerGetVessel();">
                <div class="form-row">
                    <div class="form-group" style="flex: 2; min-width: 260px;">
                        <label for="inputVesselName"><i class="fa-solid fa-ship"></i> Nama Sarana Pengangkut (Nama Kapal)</label>
                        <div class="combobox-wrapper" id="wrapVessel">
                            <div class="combobox-input-wrap">
                                <input type="text" id="inputVesselName" list="vesselDatalist" class="form-control" style="font-weight: 700; text-transform: uppercase;" value="SINAR SUNDA" required autocomplete="off" onfocus="openCombobox('wrapVessel')" oninput="filterComboboxOptions('wrapVessel', this.value)">
                                <button type="button" class="btn-combobox-toggle" onclick="toggleCombobox('wrapVessel')" title="Tampilkan Pilihan Sarana Pengangkut">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="combobox-dropdown-menu" id="menuVessel">
                                <?php foreach ($manifestVessels as $vName): ?>
                                    <div class="combobox-opt-item" data-val="<?= htmlspecialchars($vName) ?>" onclick="selectComboboxOption('wrapVessel', 'inputVesselName', '<?= htmlspecialchars(addslashes($vName)) ?>', true)">
                                        <span>🚢 <?= htmlspecialchars($vName) ?></span>
                                        <span class="combobox-opt-sub">Pilih</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <datalist id="vesselDatalist">
                                <?php foreach ($manifestVessels as $vName): ?>
                                    <option value="<?= htmlspecialchars($vName) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="form-group" style="min-width: 230px;">
                        <label for="inputVesselKantor"><i class="fa-solid fa-building-columns"></i> Filter Kantor</label>
                        <select id="inputVesselKantor" class="form-control" onchange="triggerGetVessel()">
                            <option value="">Semua Kantor</option>
                            <?php foreach ($manifestKantors as $kCode): 
                                $kName = $kantorNames[$kCode] ?? "Kantor Pabean {$kCode}";
                            ?>
                                <option value="<?= htmlspecialchars($kCode) ?>" <?= ($kCode === $defaultKantor) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kCode) ?> - <?= htmlspecialchars($kName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn btn-primary" id="btnGetVessel">
                            <i class="fa-solid fa-magnifying-glass"></i> Cari Manifes Kapal
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="copyTableToTsv('tableVesselVoyages', 'jadwal_kapal.tsv')" title="Salin seluruh baris jadwal kapal ke Clipboard (TSV / Excel)">
                            <i class="fa-regular fa-copy"></i> Salin List (TSV)
                        </button>
                    </div>
                </div>
                <div style="margin-top: 6px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                    <span style="font-size: 9px; color: var(--text-muted); font-weight: 600;">Saran Cepat:</span>
                    <?php 
                    $quickChips = array_slice($manifestVessels, 0, 6);
                    foreach ($quickChips as $qc): ?>
                        <button type="button" class="btn-chip" onclick="quickVessel('<?= htmlspecialchars(addslashes($qc)) ?>')"><?= htmlspecialchars($qc) ?></button>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>

        <!-- Grid Tabel Sarana Pengangkut (Persis Portal CEISA 4.0 Tab Data Umum) -->
        <div class="grid-card">
            <div class="grid-header">
                <div class="grid-title">
                    <i class="fa-solid fa-ship" style="color: var(--primary);"></i>
                    <span id="titleVesselGrid">Daftar Jadwal & Manifes Kedatangan Kapal (CEISA 4.0 MapController)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span id="lblVesselTotal" class="badge badge-inward" style="font-size: 8.5px;">0 Data Ditemukan</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="ceisa-table" id="tableVesselVoyages">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">No</th>
                            <th>Nama Sarana Pengangkut & IMO</th>
                            <th>Tanggal Tiba / Berangkat</th>
                            <th>Kode Kantor</th>
                            <th>Nomor Voyage</th>
                            <th>Nomor IMO</th>
                            <th>Nomor Daftar (BC 1.1)</th>
                            <th>Jenis Manifes</th>
                            <th>Tanggal Daftar</th>
                            <th>Pel. Asal & Pel. Bongkar</th>
                            <th>Kade / TPS</th>
                            <th>Pos / Cont</th>
                            <th style="text-align: right; width: 140px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyVesselVoyages">
                        <tr><td colspan="13" style="text-align: center; color: var(--text-muted); padding: 30px;">Ketikkan Nama Kapal lalu klik Cari.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- TAB 2: PETI KEMAS (CONTAINER) -->
    <!-- ========================================================================= -->
    <div id="tabContentContainer" class="tab-content-pane" style="<?= ($initialTab === 'container') ? '' : 'display:none;' ?>">
        <div class="filter-panel">
            <form id="formContainer" onsubmit="event.preventDefault(); triggerGetContainer();">
                <div class="form-row">
                    <div class="form-group" style="flex: 2; min-width: 260px;">
                        <label for="inputContainerNo"><i class="fa-solid fa-box"></i> Nomor Peti Kemas (Container No) / Host BL / No BC 1.1</label>
                        <div class="combobox-wrapper" id="wrapContainer">
                            <div class="combobox-input-wrap">
                                <input type="text" id="inputContainerNo" list="containerDatalist" class="form-control" style="font-weight: 700; text-transform: uppercase;" autocomplete="off" onfocus="openCombobox('wrapContainer')" oninput="filterComboboxOptions('wrapContainer', this.value)">
                                <button type="button" class="btn-combobox-toggle" onclick="toggleCombobox('wrapContainer')" title="Tampilkan Pilihan Petikemas">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="combobox-dropdown-menu" id="menuContainer">
                                <?php foreach ($manifestContainers as $cNo): ?>
                                    <div class="combobox-opt-item" data-val="<?= htmlspecialchars($cNo) ?>" onclick="selectComboboxOption('wrapContainer', 'inputContainerNo', '<?= htmlspecialchars(addslashes($cNo)) ?>', true)">
                                        <span>📦 <?= htmlspecialchars($cNo) ?></span>
                                        <span class="combobox-opt-sub">Pilih</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <datalist id="containerDatalist">
                                <?php foreach ($manifestContainers as $cNo): ?>
                                    <option value="<?= htmlspecialchars($cNo) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="form-group" style="min-width: 230px;">
                        <label for="inputContainerKantor"><i class="fa-solid fa-building-columns"></i> Filter Kantor</label>
                        <select id="inputContainerKantor" class="form-control" onchange="triggerGetContainer()">
                            <option value="">Semua Kantor</option>
                            <?php foreach ($manifestKantors as $kCode): 
                                $kName = $kantorNames[$kCode] ?? "Kantor Pabean {$kCode}";
                            ?>
                                <option value="<?= htmlspecialchars($kCode) ?>" <?= ($kCode === $defaultKantor) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kCode) ?> - <?= htmlspecialchars($kName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn btn-primary" id="btnGetContainer">
                            <i class="fa-solid fa-magnifying-glass"></i> Cari Peti Kemas
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="copyTableToTsv('tableContainers', 'peti_kemas.tsv')" title="Salin seluruh baris kontainer ke Clipboard (TSV / Excel)">
                            <i class="fa-regular fa-copy"></i> Salin List (TSV)
                        </button>
                    </div>
                </div>
                <div style="margin-top: 6px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                    <span style="font-size: 9px; color: var(--text-muted); font-weight: 600;">Sampel Kontainer Aktif:</span>
                    <?php 
                    $contChips = array_slice($manifestContainers, 0, 6);
                    foreach ($contChips as $cc): ?>
                        <button type="button" class="btn-chip" onclick="quickContainer('<?= htmlspecialchars(addslashes($cc)) ?>')"><?= htmlspecialchars($cc) ?></button>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>

        <!-- Grid Tabel Peti Kemas (Persis Portal CEISA 4.0 Tab Peti Kemas) -->
        <div class="grid-card">
            <div class="grid-header">
                <div class="grid-title">
                    <i class="fa-solid fa-boxes-stacked" style="color: var(--primary);"></i>
                    <span id="titleContainerGrid">Daftar Inkuiri Peti Kemas (Container Database & Manifes)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span id="lblContainerTotal" class="badge badge-inward" style="font-size: 8.5px;">0 Petikemas</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="ceisa-table" id="tableContainers">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">No</th>
                            <th>No Peti Kemas</th>
                            <th>No Host BL</th>
                            <th>No BC 1.1</th>
                            <th>Tgl BC 1.1</th>
                            <th>Jenis Manifest</th>
                            <th>Tgl Host BL</th>
                            <th>Nama Pengirim</th>
                            <th>Alamat Pengirim</th>
                            <th>Nama Penerima</th>
                            <th>Alamat Penerima</th>
                            <th>Sarana Pengangkut & Voyage</th>
                            <th style="text-align: right; width: 100px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyContainers">
                        <tr><td colspan="13" style="text-align: center; color: var(--text-muted); padding: 30px;">Ketikkan Nomor Kontainer atau klik Cari Peti Kemas.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- TAB 3: BILL OF LADING (B/L & HOST B/L) -->
    <!-- ========================================================================= -->
    <div id="tabContentBl" class="tab-content-pane" style="<?= ($initialTab === 'bl') ? '' : 'display:none;' ?>">
        <div class="filter-panel">
            <form id="formBl" onsubmit="event.preventDefault(); triggerGetBl();">
                <div class="form-row">
                    <div class="form-group" style="flex: 2; min-width: 260px;">
                        <label for="inputHostBl"><i class="fa-solid fa-barcode"></i> Nomor Host BL / Master BL (Kode Dok 740 / 705)</label>
                        <div class="combobox-wrapper" id="wrapBl">
                            <div class="combobox-input-wrap">
                                <input type="text" id="inputHostBl" list="blDatalist" class="form-control" style="font-weight: 700; text-transform: uppercase;" value="<?= htmlspecialchars($initialBl ?: 'SUB600398100') ?>" required autocomplete="off" onfocus="openCombobox('wrapBl')" oninput="filterComboboxOptions('wrapBl', this.value)">
                                <button type="button" class="btn-combobox-toggle" onclick="toggleCombobox('wrapBl')" title="Tampilkan Pilihan BL Manifes">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="combobox-dropdown-menu" id="menuBl">
                                <?php foreach ($manifestBls as $bItem): ?>
                                    <div class="combobox-opt-item" data-val="<?= htmlspecialchars($bItem['hbl']) ?>" data-aju="<?= htmlspecialchars($bItem['aju']) ?>" onclick="selectBlComboboxOption('<?= htmlspecialchars(addslashes($bItem['hbl'])) ?>', '<?= htmlspecialchars(addslashes($bItem['aju'])) ?>')">
                                        <div>
                                            <span style="color:var(--primary); font-weight:700;">📄 <?= htmlspecialchars($bItem['hbl']) ?></span>
                                            <?php if (!empty($bItem['mbl'])): ?>
                                                <span style="font-size:8.5px; color:var(--text-muted); margin-left:5px;">(MBL: <?= htmlspecialchars($bItem['mbl']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="combobox-opt-sub"><?= htmlspecialchars($bItem['vessel'] ?: ($bItem['aju'] ? 'AJU: ' . substr($bItem['aju'], -6) : 'Pilih')) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <datalist id="blDatalist">
                                <?php foreach ($manifestBls as $bItem): ?>
                                    <option value="<?= htmlspecialchars($bItem['hbl']) ?>"><?= htmlspecialchars($bItem['mbl'] ? "MBL: {$bItem['mbl']}" : '') ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="form-group" style="width: 140px;">
                        <label for="inputTglHostBl"><i class="fa-regular fa-calendar"></i> Tanggal Host BL</label>
                        <input type="date" id="inputTglHostBl" class="form-control">
                    </div>
                    <div class="form-group" style="min-width: 230px;">
                        <label for="inputKodeKantorBl"><i class="fa-solid fa-building-columns"></i> Kantor Bongkar</label>
                        <select id="inputKodeKantorBl" class="form-control" onchange="triggerGetBl()">
                            <option value="">Semua Kantor</option>
                            <?php foreach ($manifestKantors as $kCode): 
                                $kName = $kantorNames[$kCode] ?? "Kantor Pabean {$kCode}";
                            ?>
                                <option value="<?= htmlspecialchars($kCode) ?>" <?= ($kCode === $defaultKantor) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kCode) ?> - <?= htmlspecialchars($kName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn btn-primary" id="btnGetBl">
                            <i class="fa-solid fa-magnifying-glass"></i> Cari Bill of Lading (BC 1.1)
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="copyTableToTsv('tableBlResults', 'bill_of_lading.tsv')" title="Salin seluruh baris B/L ke Clipboard (TSV / Excel)">
                            <i class="fa-regular fa-copy"></i> Salin List (TSV)
                        </button>
                        <button type="button" class="btn btn-success" id="btnSyncManifest" onclick="syncToManifestH2H()" style="display: none;">
                            <i class="fa-solid fa-arrows-rotate"></i> Sinkron ke Manifest H2H
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid Tabel Bill Of Lading (Persis Portal CEISA 4.0 Tab Bill of Lading) -->
        <div class="grid-card">
            <div class="grid-header">
                <div class="grid-title">
                    <i class="fa-solid fa-file-invoice" style="color: var(--primary);"></i>
                    <span id="titleBlGrid">Daftar Inkuiri Bill of Lading & Pos Manifes (CEISA 4.0 getByNoHostBlTanggalAndKodeKantor)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span id="lblBlTotal" class="badge badge-inward" style="font-size: 8.5px;">0 BL Ditemukan</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="ceisa-table" id="tableBlResults">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">No</th>
                            <th>No POS / Subpos</th>
                            <th>Kode Kantor</th>
                            <th>Jenis Manifest</th>
                            <th>No Daftar (BC 1.1)</th>
                            <th>Sarana Pengangkut & Voyage</th>
                            <th>Tgl Daftar</th>
                            <th>Kelompok POS</th>
                            <th>MASTER (No BL)</th>
                            <th>HBL / AWB</th>
                            <th>Pengirim & Penerima</th>
                            <th>Bruto / Kemasan</th>
                            <th style="text-align: right; width: 120px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyBlResults">
                        <tr><td colspan="13" style="text-align: center; color: var(--text-muted); padding: 30px;">Ketikkan Nomor Host BL lalu klik Cari Bill of Lading.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>



    <!-- ========================================================================= -->
    <!-- MODAL: 🔍 DETAIL SARANA PENGANGKUT (DatamanifesHeader) -->
    <!-- ========================================================================= -->
    <div id="modalVesselDetail" class="modal-overlay" onclick="if(event.target===this) closeVesselDetailModal()">
        <div class="modal-box" style="max-width: 840px;">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fa-solid fa-ship" style="color: var(--primary);"></i>
                    <span>Rincian Manifes Kedatangan Sarana Pengangkut</span>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 28px; width: 28px; padding: 0; border-radius: 50%;" onclick="closeVesselDetailModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" id="modalVesselDetailBody">
                <div style="text-align: center; padding: 30px;"><span class="spinner" style="border-top-color: var(--primary);"></span> Memuat rincian header dari Bea Cukai...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeVesselDetailModal()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL: 📄 DETAIL POS BILL OF LADING -->
    <!-- ========================================================================= -->
    <div id="modalBlDetail" class="modal-overlay" onclick="if(event.target===this) closeBlDetailModal()">
        <div class="modal-box" style="max-width: 780px;">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fa-solid fa-file-invoice" style="color: var(--primary);"></i>
                    <span>Rincian Pos Bill of Lading (BC 1.1)</span>
                </div>
                <button type="button" class="btn btn-secondary" style="height: 28px; width: 28px; padding: 0; border-radius: 50%;" onclick="closeBlDetailModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBlDetailBody">
                <!-- Diisi secara dinamis -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="copyActiveBlDetail()">
                    <i class="fa-regular fa-copy"></i> Salin Rincian
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeBlDetailModal()">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        // State Global
        let currentTab = '<?= htmlspecialchars($initialTab) ?>';
        let vesselVoyagesList = [];
        let containersList = [];
        let blResultsList = [];
        let activeBlItem = null;
        let linkedAju = '<?= htmlspecialchars($initialAju) ?>';

        // Inisialisasi Saat Dokumen Siap
        document.addEventListener('DOMContentLoaded', () => {
            if (currentTab === 'vessel') {
                triggerGetVessel();
            } else if (currentTab === 'container') {
                triggerGetContainer();
            } else if (currentTab === 'bl') {
                triggerGetBl();
            }
        });

        // =========================================================================
        // COMBOBOX DROPDOWN CONTROLLER
        // =========================================================================
        function openCombobox(wrapId) {
            closeAllComboboxes();
            const wrap = document.getElementById(wrapId);
            if (!wrap) return;
            const menu = wrap.querySelector('.combobox-dropdown-menu');
            if (menu) menu.classList.add('show');
        }

        function toggleCombobox(wrapId) {
            const wrap = document.getElementById(wrapId);
            if (!wrap) return;
            const menu = wrap.querySelector('.combobox-dropdown-menu');
            if (menu) {
                const isOpen = menu.classList.contains('show');
                closeAllComboboxes();
                if (!isOpen) menu.classList.add('show');
            }
        }

        function closeAllComboboxes() {
            document.querySelectorAll('.combobox-dropdown-menu.show').forEach(m => m.classList.remove('show'));
        }

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.combobox-wrapper')) {
                closeAllComboboxes();
            }
        });

        function filterComboboxOptions(wrapId, val) {
            const wrap = document.getElementById(wrapId);
            if (!wrap) return;
            const menu = wrap.querySelector('.combobox-dropdown-menu');
            if (!menu) return;
            menu.classList.add('show');

            const query = (val || '').toLowerCase().trim();
            const items = menu.querySelectorAll('.combobox-opt-item');
            let hasVisible = false;

            items.forEach(it => {
                const text = it.innerText.toLowerCase();
                if (!query || text.includes(query)) {
                    it.style.display = 'flex';
                    hasVisible = true;
                } else {
                    it.style.display = 'none';
                }
            });

            let noMatch = menu.querySelector('.combobox-no-match');
            if (!hasVisible) {
                if (!noMatch) {
                    noMatch = document.createElement('div');
                    noMatch.className = 'combobox-no-match';
                    noMatch.innerText = 'Tidak ada pilihan dalam daftar. Anda dapat mengetik bebas.';
                    menu.appendChild(noMatch);
                }
                noMatch.style.display = 'block';
            } else if (noMatch) {
                noMatch.style.display = 'none';
            }
        }

        function selectComboboxOption(wrapId, inputId, val, autoSubmit = false) {
            const input = document.getElementById(inputId);
            if (input) {
                input.value = val;
            }
            closeAllComboboxes();
            if (autoSubmit) {
                if (inputId === 'inputVesselName') triggerGetVessel();
                else if (inputId === 'inputContainerNo') triggerGetContainer();
                else if (inputId === 'inputHostBl') triggerGetBl();
            }
        }

        function selectBlComboboxOption(hbl, aju) {
            const input = document.getElementById('inputHostBl');
            if (input) input.value = hbl;
            linkedAju = aju || '';
            closeAllComboboxes();
            triggerGetBl();
        }

        // Tab Switching
        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tabBtnVessel').className = 'tab-btn' + (tab === 'vessel' ? ' active' : '');
            document.getElementById('tabBtnContainer').className = 'tab-btn' + (tab === 'container' ? ' active' : '');
            document.getElementById('tabBtnBl').className = 'tab-btn' + (tab === 'bl' ? ' active' : '');

            document.getElementById('tabContentVessel').style.display = (tab === 'vessel') ? 'flex' : 'none';
            document.getElementById('tabContentContainer').style.display = (tab === 'container') ? 'flex' : 'none';
            document.getElementById('tabContentBl').style.display = (tab === 'bl') ? 'flex' : 'none';

            // Auto fetch jika tab masih kosong
            if (tab === 'vessel' && vesselVoyagesList.length === 0) {
                triggerGetVessel();
            } else if (tab === 'container' && containersList.length === 0) {
                triggerGetContainer();
            } else if (tab === 'bl' && blResultsList.length === 0) {
                const bVal = document.getElementById('inputHostBl').value.trim();
                if (bVal) triggerGetBl();
            }
        }

        function onCompanyChange() {
            const cmpId = document.getElementById('selectCompany').value;
            const url = new URL(window.location);
            url.searchParams.set('cmp_id', cmpId);
            window.location.href = url.toString();
        }

        function toggleTheme() {
            const cur = document.body.getAttribute('data-theme') || 'light';
            const next = (cur === 'light') ? 'dark' : 'light';
            document.body.setAttribute('data-theme', next);
            const url = new URL(window.location);
            url.searchParams.set('theme', next);
            window.history.replaceState({}, '', url.toString());
        }

        // =========================================================================
        // TAB 1: LOGIKA SARANA PENGANGKUT (VESSEL)
        // =========================================================================
        function quickVessel(name) {
            closeAllComboboxes();
            document.getElementById('inputVesselName').value = name;
            triggerGetVessel();
        }

        async function triggerGetVessel() {
            const vessel = document.getElementById('inputVesselName').value.trim();
            const kantor = document.getElementById('inputVesselKantor').value.trim();
            const cmpId  = document.getElementById('selectCompany').value;
            const btn    = document.getElementById('btnGetVessel');
            const tbody  = document.getElementById('tbodyVesselVoyages');
            const badge  = document.getElementById('badgeVesselCount');
            const lblTot = document.getElementById('lblVesselTotal');

            if (!vessel) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Nama Sarana Pengangkut / Kapal wajib diisi!' });
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Mencari...';
            tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; color: var(--text-muted); padding: 30px;"><div class="spinner" style="border-top-color: var(--primary);"></div> Menghubungi API Gateway CEISA 4.0 MapController...</td></tr>';

            try {
                const res = await fetch(`getbl.php?action=search_vessel&vessel=${encodeURIComponent(vessel)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data) && json.data.length > 0) {
                    let list = json.data;
                    if (kantor) {
                        list = list.filter(item => String(item.kodeKantor || '').includes(kantor));
                    }
                    vesselVoyagesList = list;
                    badge.innerText = `${list.length}`;
                    lblTot.innerText = `${list.length} Data Ditemukan`;
                    document.getElementById('titleVesselGrid').innerText = `Daftar Jadwal & Manifes Kedatangan: ${vessel.toUpperCase()} (${list.length} Jadwal)`;
                    renderVesselVoyages(list);
                } else {
                    vesselVoyagesList = [];
                    badge.innerText = '0';
                    lblTot.innerText = '0 Data';
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--danger); padding: 30px;">${json.message || 'Data manifes kedatangan kapal tidak ditemukan.'}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--danger); padding: 30px;">Gagal menghubungi server: ${err.message}</td></tr>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Cari Manifes Kapal';
            }
        }

        function renderVesselVoyages(list) {
            const tbody = document.getElementById('tbodyVesselVoyages');
            tbody.innerHTML = '';

            list.forEach((r, idx) => {
                const tr = document.createElement('tr');
                const asal = r.namaPelabuhanAsal || r.kodePelabuhanAsal || '-';
                const bongkar = r.namaPelabuhanBongkar || r.kodePelabuhanBongkar || '-';
                const kade = r.kade || '-';
                const tglDoc = r.tanggalTiba || r.tanggalBerangkat || '-';
                const isOutward = String(r.jenisManifes || '').toLowerCase().includes('outward') || (!r.tanggalTiba && r.tanggalBerangkat);
                const jenisBadge = isOutward 
                    ? '<span class="badge badge-outward">Outward (BC 1.1)</span>'
                    : '<span class="badge badge-inward">Inward (BC 1.1)</span>';

                tr.innerHTML = `
                    <td style="text-align: center; color: var(--text-muted);">${idx + 1}</td>
                    <td>
                        <strong style="color: var(--text-main); font-size: 10px;">${escapeHtml(r.namaSaranaPengangkut || '-')}</strong>
                        <div style="font-size: 8.5px; color: var(--text-muted);">IMO: ${escapeHtml(r.imoNumber || '-')} | CS: ${escapeHtml(r.callSign || '-')} (${escapeHtml(r.kodeNegara || '-')})</div>
                    </td>
                    <td>
                        <strong style="color: #059669; font-size: 9.5px;">${escapeHtml(tglDoc)}</strong>
                    </td>
                    <td>
                        <span class="badge-kantor">${escapeHtml(r.kodeKantor || '-')}</span>
                    </td>
                    <td>
                        <span class="badge-pos">${escapeHtml(r.nomorVoyage || '-')}</span>
                    </td>
                    <td>
                        <span>${escapeHtml(r.imoNumber || '-')}</span>
                    </td>
                    <td>
                        <strong style="color: var(--primary); font-size: 10px;">${escapeHtml(r.nomorDaftar || '-')}</strong>
                    </td>
                    <td>
                        ${jenisBadge}
                    </td>
                    <td>
                        <span style="font-size: 9.5px;">${escapeHtml(r.tanggalDaftar || '-')}</span>
                    </td>
                    <td>
                        <div style="font-size: 9px;">🚢 ${escapeHtml(asal)}</div>
                        <div style="font-size: 9px; color: var(--primary);">⚓ ${escapeHtml(bongkar)}</div>
                    </td>
                    <td>
                        <span class="badge-kade">${escapeHtml(kade)}</span>
                    </td>
                    <td>
                        <div><strong>${r.jumlahPos || 0}</strong> Pos</div>
                        <div style="font-size: 8.5px; color: var(--text-muted);">${r.jumlahContainer || 0} Cont</div>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn btn-secondary" style="height: 20px; padding: 0 6px; font-size: 9px;" onclick="viewVesselDetail('${r.idHeader}')" title="Lihat detail manifes header">
                            <i class="fa-solid fa-eye"></i> Detail
                        </button>
                        <button type="button" class="btn btn-primary" style="height: 20px; padding: 0 6px; font-size: 9px;" onclick="searchBlForVessel('${escapeHtml(r.namaSaranaPengangkut)}', '${escapeHtml(r.nomorVoyage)}')" title="Cari B/L untuk kapal ini">
                            <i class="fa-solid fa-file-invoice"></i> Pos BL
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        async function viewVesselDetail(idHeader) {
            if (!idHeader || idHeader === 'undefined') {
                Swal.fire({ icon: 'info', title: 'Info', text: 'idHeader tidak tersedia untuk jadwal ini.' });
                return;
            }
            const modal = document.getElementById('modalVesselDetail');
            const body  = document.getElementById('modalVesselDetailBody');
            modal.style.display = 'flex';
            body.innerHTML = '<div style="text-align: center; padding: 25px;"><span class="spinner" style="border-top-color: var(--primary);"></span> Memuat rincian header dari Bea Cukai...</div>';

            try {
                const cmpId = document.getElementById('selectCompany').value;
                const res = await fetch(`getbl.php?action=manifest_detail&id_header=${encodeURIComponent(idHeader)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();

                if (json.status === 'OK' && json.data) {
                    const d = json.data;
                    body.innerHTML = `
                        <div class="kv-grid">
                            <div class="kv-item"><div class="kv-label">Nama Sarana Pengangkut</div><div class="kv-val" style="color:var(--primary); font-size:11px;">${escapeHtml(d.namaSaranaPengangkut || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Nomor Voyage</div><div class="kv-val">${escapeHtml(d.nomorVoyage || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Nomor Daftar (BC 1.1)</div><div class="kv-val" style="color:#059669; font-size:11px;">${escapeHtml(d.nomorDaftar || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Tanggal Daftar (BC 1.1)</div><div class="kv-val">${escapeHtml(d.tanggalDaftar || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Kode Kantor Pabean</div><div class="kv-val">${escapeHtml(d.kodeKantor || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Jenis Manifes</div><div class="kv-val">${escapeHtml(d.jenisManifes || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Nomor IMO / Call Sign</div><div class="kv-val">${escapeHtml(d.imoNumber || '-')} / ${escapeHtml(d.callSign || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Bendera / Kode Negara</div><div class="kv-val">${escapeHtml(d.kodeNegara || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Tanggal Tiba / Berangkat</div><div class="kv-val">${escapeHtml(d.tanggalTiba || d.tanggalBerangkat || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Nama Nahkoda</div><div class="kv-val">${escapeHtml(d.nahkoda || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Pengangkut / Shipper</div><div class="kv-val">${escapeHtml(d.namaShipper || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">NPWP Shipper</div><div class="kv-val">${escapeHtml(d.npwpShipper || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Pelabuhan Asal & Bongkar</div><div class="kv-val">${escapeHtml(d.kodePelabuhanAsal || '-')} ➔ ${escapeHtml(d.kodePelabuhanBongkar || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Kade / TPS</div><div class="kv-val">${escapeHtml(d.kade || '-')}</div></div>
                            <div class="kv-item"><div class="kv-label">Jumlah Pos / Petikemas</div><div class="kv-val">${d.jumlahPos || 0} Pos / ${d.jumlahContainer || 0} Petikemas</div></div>
                            <div class="kv-item"><div class="kv-label">Berat Bruto (KG)</div><div class="kv-val">${Number(d.berat || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})} KG</div></div>
                        </div>
                    `;
                } else {
                    body.innerHTML = `<div style="text-align: center; color: var(--danger); padding: 20px;">${json.message || 'Gagal memuat rincian header.'}</div>`;
                }
            } catch (err) {
                body.innerHTML = `<div style="text-align: center; color: var(--danger); padding: 20px;">Error: ${err.message}</div>`;
            }
        }

        function closeVesselDetailModal() {
            document.getElementById('modalVesselDetail').style.display = 'none';
        }

        function searchBlForVessel(vessel, voyage) {
            switchTab('bl');
            document.getElementById('inputHostBl').value = voyage || vessel;
            triggerGetBl();
        }

        // =========================================================================
        // TAB 2: LOGIKA PETI KEMAS (CONTAINER)
        // =========================================================================
        function quickContainer(no) {
            closeAllComboboxes();
            document.getElementById('inputContainerNo').value = no;
            triggerGetContainer();
        }

        async function triggerGetContainer() {
            const noCont = document.getElementById('inputContainerNo').value.trim();
            const kantor = document.getElementById('inputContainerKantor').value.trim();
            const cmpId  = document.getElementById('selectCompany').value;
            const btn    = document.getElementById('btnGetContainer');
            const tbody  = document.getElementById('tbodyContainers');
            const badge  = document.getElementById('badgeContainerCount');
            const lblTot = document.getElementById('lblContainerTotal');

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Mencari...';
            tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; color: var(--text-muted); padding: 30px;"><div class="spinner" style="border-top-color: var(--primary);"></div> Mencari data petikemas...</td></tr>';

            try {
                const res = await fetch(`getbl.php?action=search_container&nomor_container=${encodeURIComponent(noCont)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data) && json.data.length > 0) {
                    let list = json.data;
                    if (kantor) {
                        list = list.filter(item => !item.kode_kantor || String(item.kode_kantor).includes(kantor));
                    }
                    containersList = list;
                    badge.innerText = `${list.length}`;
                    lblTot.innerText = `${list.length} Petikemas`;
                    renderContainers(list);
                } else {
                    containersList = [];
                    badge.innerText = '0';
                    lblTot.innerText = '0 Petikemas';
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--text-muted); padding: 30px;">Tidak ada petikemas yang cocok dengan filter pencarian.</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--danger); padding: 30px;">Gagal memuat data: ${err.message}</td></tr>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Cari Peti Kemas';
            }
        }

        function renderContainers(list) {
            const tbody = document.getElementById('tbodyContainers');
            tbody.innerHTML = '';

            list.forEach((c, idx) => {
                const tr = document.createElement('tr');
                const isOut = (c.jenis_manifes === '12');
                const jBadge = isOut ? '<span class="badge badge-outward">Outward (BC 1.1)</span>' : '<span class="badge badge-inward">Inward (BC 1.1)</span>';

                tr.innerHTML = `
                    <td style="text-align: center; color: var(--text-muted);">${idx + 1}</td>
                    <td>
                        <strong style="color: var(--primary); font-size: 10px;">${escapeHtml(c.nomor_container || '-')}</strong>
                        <div style="font-size: 8.5px; color: var(--text-muted);">${escapeHtml(c.ukuran_container || '20')}ft | Segel: ${escapeHtml(c.nomor_segel || '-')}</div>
                    </td>
                    <td>
                        <strong style="color: var(--text-main); font-size: 9.5px;">${escapeHtml(c.nomor_host_bl || '-')}</strong>
                    </td>
                    <td>
                        <strong style="color: #059669; font-size: 10px;">${escapeHtml(c.nomor_bc11 || '-')}</strong>
                    </td>
                    <td>${escapeHtml(c.tanggal_bc11 || '-')}</td>
                    <td>${jBadge}</td>
                    <td>${escapeHtml(c.tanggal_host_bl || '-')}</td>
                    <td>
                        <div style="font-weight: 600; max-width: 180px; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(c.nama_pengirim || '-')}</div>
                    </td>
                    <td>
                        <div style="font-size: 8.5px; color: var(--text-muted); max-width: 200px; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(c.alamat_pengirim || '-')}</div>
                    </td>
                    <td>
                        <div style="font-weight: 600; max-width: 180px; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(c.nama_penerima || '-')}</div>
                    </td>
                    <td>
                        <div style="font-size: 8.5px; color: var(--text-muted); max-width: 200px; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(c.alamat_penerima || '-')}</div>
                    </td>
                    <td>
                        <div><strong>${escapeHtml(c.nama_sarana_pengangkut || '-')}</strong></div>
                        <div style="font-size: 8.5px; color: var(--text-muted);">Voy: ${escapeHtml(c.nomor_voyage || '-')}</div>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn btn-secondary" style="height: 20px; padding: 0 6px; font-size: 9px;" onclick="copyRowData(${idx}, 'container')" title="Salin data baris ini">
                            <i class="fa-regular fa-copy"></i> Salin
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // =========================================================================
        // TAB 3: LOGIKA BILL OF LADING (B/L & HOST B/L)
        // =========================================================================
        async function triggerGetBl() {
            const hostBl = document.getElementById('inputHostBl').value.trim();
            const kantor = document.getElementById('inputKodeKantorBl').value.trim();
            const cmpId  = document.getElementById('selectCompany').value;
            const btn    = document.getElementById('btnGetBl');
            const tbody  = document.getElementById('tbodyBlResults');
            const badge  = document.getElementById('badgeBlCount');
            const lblTot = document.getElementById('lblBlTotal');
            const btnSync = document.getElementById('btnSyncManifest');

            if (!hostBl) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Nomor Host BL / Master BL wajib diisi!' });
                document.getElementById('inputHostBl').focus();
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Mencari...';
            tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; color: var(--text-muted); padding: 30px;"><div class="spinner" style="border-top-color: var(--primary);"></div> Menghubungi API Gateway Bea Cukai (getByNoHostBlTanggalAndKodeKantor)...</td></tr>';

            try {
                const res = await fetch(`getbl.php?a=${encodeURIComponent(hostBl)}&format=json&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();

                if (json.status === 'OK' && (json.items || json.data)) {
                    let list = json.items || [json.data];
                    if (kantor) {
                        list = list.filter(item => !item.kodeKantor || String(item.kodeKantor).includes(kantor));
                    }
                    blResultsList = list;
                    badge.innerText = `${list.length}`;
                    lblTot.innerText = `${list.length} BL Ditemukan`;

                    if (linkedAju) {
                        btnSync.style.display = 'inline-flex';
                    }

                    renderBlResults(list);
                } else {
                    blResultsList = [];
                    badge.innerText = '0';
                    lblTot.innerText = '0 BL';
                    btnSync.style.display = 'none';
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--danger); padding: 30px;">${json.message || 'Manifest BC 1.1 belum terbit atau nomor BL tidak ditemukan.'}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--danger); padding: 30px;">Error: ${err.message}</td></tr>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Cari Bill of Lading (BC 1.1)';
            }
        }

        function renderBlResults(list) {
            const tbody = document.getElementById('tbodyBlResults');
            tbody.innerHTML = '';

            list.forEach((b, idx) => {
                const tr = document.createElement('tr');
                const isOut = String(b.jenisManifes || '').toLowerCase().includes('outward');
                const jBadge = isOut ? '<span class="badge badge-outward">Outward (BC 1.1)</span>' : '<span class="badge badge-inward">Inward (BC 1.1)</span>';
                const posStr = `${b.nomorPosHeader || '-'}/${b.nomorSubPos || '-'}`;

                tr.innerHTML = `
                    <td style="text-align: center; color: var(--text-muted);">${idx + 1}</td>
                    <td>
                        <span class="badge-pos">${escapeHtml(posStr)}</span>
                        <div style="font-size: 8.5px; color: var(--text-muted); margin-top: 2px;">${escapeHtml(b.nomorPos || '-')}</div>
                    </td>
                    <td><span class="badge-kantor">${escapeHtml(b.kodeKantor || '-')}</span></td>
                    <td>${jBadge}</td>
                    <td><strong style="color: var(--primary); font-size: 10px;">${escapeHtml(b.nomorDaftar || '-')}</strong></td>
                    <td>
                        <div><strong style="color: #0284c7; font-size: 10px;">${escapeHtml(b.motherVessel || '-')}</strong></div>
                        <div style="font-size: 8.5px; color: var(--text-muted);">Voy: ${escapeHtml(b.nomorVoyage || '-')}</div>
                    </td>
                    <td>${escapeHtml(b.tanggalDaftar || '-')}</td>
                    <td><span class="badge-kade">${escapeHtml(b.kelompokPos || 'POS 1')}</span></td>
                    <td><strong>${escapeHtml(b.nomorBl || '-')}</strong></td>
                    <td><strong style="color: var(--primary);">${escapeHtml(b.nomorHostBl || '-')}</strong></td>
                    <td>
                        <div style="font-weight: 600; max-width: 170px; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(b.namaPengirim || '-')}</div>
                        <div style="font-size: 8.5px; color: var(--text-muted); max-width: 170px; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(b.alamatPengirim || '-')}</div>
                    </td>
                    <td>
                        <div><strong>${Number(b.berat || 0).toLocaleString('id-ID')}</strong> KG</div>
                        <div style="font-size: 8.5px; color: var(--text-muted);">${b.jumlahKemasan || 0} ${escapeHtml(b.jenisKemasan || '-')}</div>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button type="button" class="btn btn-secondary" style="height: 20px; padding: 0 6px; font-size: 9px;" onclick="viewBlDetail(${idx})" title="Lihat detail lengkap pos manifes">
                            <i class="fa-solid fa-eye"></i> Detail Pos
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function viewBlDetail(idx) {
            const b = blResultsList[idx];
            if (!b) return;
            activeBlItem = b;

            const modal = document.getElementById('modalBlDetail');
            const body  = document.getElementById('modalBlDetailBody');
            modal.style.display = 'flex';

            body.innerHTML = `
                <div class="kv-grid">
                    <div class="kv-item"><div class="kv-label">Nomor Daftar (BC 1.1)</div><div class="kv-val" style="color:var(--primary); font-size:12px;">${escapeHtml(b.nomorDaftar || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Tanggal Daftar (BC 1.1)</div><div class="kv-val">${escapeHtml(b.tanggalDaftar || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Nomor Pos / Subpos</div><div class="kv-val"><span class="badge-pos">${escapeHtml(b.nomorPosHeader || '-')}/${escapeHtml(b.nomorSubPos || '-')}</span> (Raw: ${escapeHtml(b.nomorPos || '-')})</div></div>
                    <div class="kv-item"><div class="kv-label">Kode Kantor Pabean</div><div class="kv-val">${escapeHtml(b.kodeKantor || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Nomor Host BL</div><div class="kv-val" style="color:var(--primary); font-weight:700;">${escapeHtml(b.nomorHostBl || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Tanggal Host BL</div><div class="kv-val">${escapeHtml(b.tanggalHostBl || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Nomor Master BL</div><div class="kv-val">${escapeHtml(b.nomorBl || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Tanggal Master BL</div><div class="kv-val">${escapeHtml(b.tanggalBl || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Sarana Pengangkut (Mother Vessel)</div><div class="kv-val" style="color:#0284c7;">${escapeHtml(b.motherVessel || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Nomor Voyage</div><div class="kv-val">${escapeHtml(b.nomorVoyage || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Pelabuhan Asal & Bongkar</div><div class="kv-val">${escapeHtml(b.kodePelabuhanAsal || '-')} ➔ ${escapeHtml(b.kodePelabuhanBongkar || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Pelabuhan Transit & Akhir</div><div class="kv-val">${escapeHtml(b.kodePelabuhanTransit || '-')} ➔ ${escapeHtml(b.kodePelabuhanAkhir || '-')}</div></div>
                    <div class="kv-item"><div class="kv-label">Berat Bruto (KG)</div><div class="kv-val">${Number(b.berat || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})} KG</div></div>
                    <div class="kv-item"><div class="kv-label">Jumlah & Jenis Kemasan</div><div class="kv-val">${b.jumlahKemasan || 0} (${escapeHtml(b.jenisKemasan || '-')})</div></div>
                    <div class="kv-item"><div class="kv-label">Jumlah Kontainer</div><div class="kv-val">${b.jumlahContainer || 0} Petikemas</div></div>
                    <div class="kv-item"><div class="kv-label">Kelompok Pos</div><div class="kv-val">${escapeHtml(b.kelompokPos || '-')}</div></div>
                </div>
                <div style="margin-top: 10px; background: var(--bg-main); padding: 8px 10px; border-radius: 6px; border: 1px solid var(--border);">
                    <div class="kv-label">Nama & Alamat Pengirim (Shipper)</div>
                    <div style="font-weight: 700; color: var(--text-main); font-size: 10px;">${escapeHtml(b.namaPengirim || '-')}</div>
                    <div style="font-size: 9px; color: var(--text-muted); margin-top: 2px;">${escapeHtml(b.alamatPengirim || '-')}</div>
                </div>
            `;
        }

        function closeBlDetailModal() {
            document.getElementById('modalBlDetail').style.display = 'none';
        }

        function copyActiveBlDetail() {
            if (!activeBlItem) return;
            let text = '';
            for (const [k, v] of Object.entries(activeBlItem)) {
                if (typeof v !== 'object') text += `${k}\t${v}\n`;
            }
            navigator.clipboard.writeText(text).then(() => {
                Swal.fire({ icon: 'success', title: 'Disalin!', text: 'Rincian Pos BL disalin ke Clipboard.', timer: 1200, showConfirmButton: false });
            });
        }

        async function syncToManifestH2H() {
            if (!blResultsList || blResultsList.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Tidak ada data BC 1.1 untuk disinkronkan!' });
                return;
            }

            const item = blResultsList[0];
            const fd = new FormData();
            fd.append('action', 'sync_to_manifest');
            fd.append('nomor_aju', linkedAju);
            fd.append('nomor_host_bl', item.nomorHostBl || '');
            fd.append('nomor_bc11', item.nomorDaftar || '');
            fd.append('tanggal_bc11', item.tanggalDaftar || '');
            fd.append('nomor_pos', item.nomorPos || '');

            try {
                const res = await fetch('getbl.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Disimpan!',
                        text: `Data BC 1.1 (${item.nomorDaftar} Pos: ${item.nomorPos}) berhasil disinkronkan ke Manifest Host to Host!`
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message || 'Terjadi kesalahan saat menyimpan.' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        // =========================================================================
        // UTILITY FUNCTIONS & EXPORT
        // =========================================================================
        function copyTableToTsv(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;

            let tsv = '';
            const rows = table.querySelectorAll('tr');
            rows.forEach(r => {
                const cols = r.querySelectorAll('th, td');
                const rowData = [];
                // Abaikan kolom aksi terakhir
                for (let i = 0; i < cols.length - 1; i++) {
                    let text = cols[i].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\t/g, ' ').trim();
                    rowData.push(text);
                }
                tsv += rowData.join('\t') + '\n';
            });

            navigator.clipboard.writeText(tsv).then(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'Disalin ke Clipboard!',
                    text: `Data tabel berhasil disalin dalam format TSV (Siap di-paste ke Excel atau DataWindow PowerBuilder).`,
                    timer: 1600,
                    showConfirmButton: false
                });
            });
        }

        function copyRowData(idx, type) {
            let rowObj = (type === 'container') ? containersList[idx] : blResultsList[idx];
            if (!rowObj) return;

            let text = '';
            for (const [k, v] of Object.entries(rowObj)) {
                if (typeof v !== 'object') text += `${k}\t${v}\n`;
            }

            navigator.clipboard.writeText(text).then(() => {
                Swal.fire({ icon: 'success', title: 'Disalin!', text: 'Data baris disalin ke Clipboard.', timer: 1200, showConfirmButton: false });
            });
        }

        function copyText(elemId) {
            const el = document.getElementById(elemId);
            if (!el) return;
            navigator.clipboard.writeText(el.innerText).then(() => {
                Swal.fire({ icon: 'success', title: 'Disalin!', text: 'Teks disalin ke Clipboard.', timer: 1200, showConfirmButton: false });
            });
        }

        function openPbApiModal() {
            document.getElementById('modalPbApi').style.display = 'flex';
        }

        function closePbApiModal() {
            document.getElementById('modalPbApi').style.display = 'none';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

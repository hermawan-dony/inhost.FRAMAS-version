<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Jurnal Umum & Jurnal Memorial
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_act_jurnal_memo.srw & w_act_jurnal_umum.srw)
 * DataWindows: d_act_memo.srd, d_act_umum.srd, dw_act_umum_unbalance.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - Mendukung Mode Memorial (?mode=memo) & Mode Jurnal Umum / Import (?mode=import)
 * - 4 KPI Metrics Real-Time (Jurnal Memorial, Jurnal Umum, Total Nilai Debet, Status Keseimbangan GL)
 * - Tab 1: Form Entri Multi-Row Jurnal Penyesuaian / Memorial (Live Balance Debet = Kredit)
 * - Tab 2: Buku Jurnal Umum & Batch Entry / Import Excel (bc_act_umum)
 * - DataGrid Enterprise Resizable (.col-resizer) & Right-Click Context Menu
 * - SweetAlert2 Compact Desktop (330px width, font 9.5px, button 21px)
 */

session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (empty($sessionCmpId)) {
    $sessionCmpId = 'SNU';
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$userId  = $_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? 'ADMIN');

session_write_close();

$action = $_REQUEST['action'] ?? '';
$initialMode = $_GET['mode'] ?? 'memo';

// Ensure tables
function ensureGeneralJournalTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_memo` (
        `tanggal` date DEFAULT NULL,
        `kode_pembantu` varchar(50) DEFAULT NULL,
        `valid_kode` varchar(10) DEFAULT NULL,
        `no_bukti` varchar(30) DEFAULT NULL,
        `uraian` varchar(255) DEFAULT NULL,
        `akun_db` varchar(15) DEFAULT NULL,
        `akun_cr` varchar(15) DEFAULT NULL,
        `valid_akun` varchar(10) DEFAULT NULL,
        `keterangan_akun` varchar(200) DEFAULT NULL,
        `amt_db` decimal(18,2) DEFAULT '0.00',
        `amt_cr` decimal(18,2) DEFAULT '0.00',
        `voucher` varchar(30) DEFAULT NULL,
        `dept` varchar(10) DEFAULT NULL,
        `id` varchar(50) NOT NULL DEFAULT '',
        `seq` int(11) DEFAULT '1',
        `kurs` varchar(5) DEFAULT 'IDR',
        `rate` decimal(9,0) DEFAULT '1',
        `tipe` varchar(10) DEFAULT 'MEMO',
        `amt_prime` decimal(18,2) DEFAULT '0.00',
        `set_by` varchar(60) DEFAULT NULL,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_no_bukti` (`no_bukti`),
        KEY `idx_tgl` (`tanggal`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_umum` (
        `tanggal` date DEFAULT NULL,
        `kode_pembantu` varchar(50) DEFAULT NULL,
        `valid_kode` varchar(10) DEFAULT NULL,
        `no_bukti` varchar(30) DEFAULT NULL,
        `uraian` varchar(100) DEFAULT NULL,
        `akun_db` varchar(15) DEFAULT NULL,
        `akun_cr` varchar(15) DEFAULT NULL,
        `valid_akun` varchar(10) DEFAULT NULL,
        `keterangan_akun` varchar(80) DEFAULT NULL,
        `amt_db` decimal(18,2) DEFAULT '0.00',
        `amt_cr` decimal(18,2) DEFAULT '0.00',
        `voucher` varchar(15) DEFAULT NULL,
        `dept` varchar(10) DEFAULT NULL,
        `id` varchar(50) NOT NULL DEFAULT '',
        `seq` int(11) DEFAULT '1',
        `kurs` varchar(5) DEFAULT 'IDR',
        `rate` decimal(9,0) DEFAULT '1',
        `tipe` varchar(10) DEFAULT 'UMUM',
        `amt_prime` decimal(18,2) DEFAULT '0.00',
        `set_by` varchar(60) DEFAULT NULL,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_no_bukti` (`no_bukti`),
        KEY `idx_tgl` (`tanggal`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureGeneralJournalTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $currYm = date('Y-m');

    $sqlMemo = "SELECT COUNT(DISTINCT no_bukti) as memo_cnt, COALESCE(SUM(amt_db), 0) as total_memo_db 
                FROM bc_act_memo WHERE $cmpClause AND DATE_FORMAT(tanggal, '%Y-%m') = '$currYm'";
    $resMemo = $koneksi->query($sqlMemo);
    $rowMemo = $resMemo ? $resMemo->fetch_assoc() : [];

    $sqlUmum = "SELECT COUNT(DISTINCT no_bukti) as umum_cnt, COALESCE(SUM(amt_db), 0) as total_umum_db 
                FROM bc_act_umum WHERE $cmpClause AND DATE_FORMAT(tanggal, '%Y-%m') = '$currYm'";
    $resUmum = $koneksi->query($sqlUmum);
    $rowUmum = $resUmum ? $resUmum->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'memo_cnt'      => (int)($rowMemo['memo_cnt'] ?? 0),
            'total_memo_db' => (float)($rowMemo['total_memo_db'] ?? 0),
            'umum_cnt'      => (int)($rowUmum['umum_cnt'] ?? 0),
            'total_umum_db' => (float)($rowUmum['total_umum_db'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Save Memorial Journal Entry (Tab 1)
// =========================================================================
if ($action === 'save_memo') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);

    if (!$req || empty($req['items'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data rincian jurnal tidak valid']);
        exit;
    }

    $tgl     = $req['tanggal'] ?? date('Y-m-d');
    $noBukti = trim($req['no_bukti'] ?? '');
    $note    = trim($req['keterangan'] ?? '');
    $items   = (array)$req['items'];

    if (empty($noBukti)) {
        $ym = date('ym', strtotime($tgl));
        $noBukti = "MEMO/$ym/" . strtoupper(substr(uniqid(), -5));
    }

    // Validasi keseimbangan Debet = Kredit
    $totDb = 0;
    $totCr = 0;
    foreach ($items as $it) {
        $totDb += (float)($it['amt_db'] ?? 0);
        $totCr += (float)($it['amt_cr'] ?? 0);
    }
    if (abs($totDb - $totCr) > 0.01) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Total Debet dan Kredit tidak seimbang (Selisih: ' . number_format(abs($totDb - $totCr), 2) . ')']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        $escTgl     = $koneksi->real_escape_string($tgl);
        $escNoBukti = $koneksi->real_escape_string($noBukti);
        $escNote    = $koneksi->real_escape_string($note);

        // Hapus entri lama jika no_bukti sama
        $koneksi->query("DELETE FROM bc_act_memo WHERE no_bukti = '$escNoBukti' AND (cmp_id = '$escCmp' OR cmp_id = '')");

        $seq = 1;
        foreach ($items as $it) {
            $akunDb = $koneksi->real_escape_string(trim($it['akun_db'] ?? ''));
            $akunCr = $koneksi->real_escape_string(trim($it['akun_cr'] ?? ''));
            $kdBantu = $koneksi->real_escape_string(trim($it['kode_bantu'] ?? ''));
            $uraianRow = $koneksi->real_escape_string(trim($it['uraian'] ?? $note));
            $amtDb = (float)($it['amt_db'] ?? 0);
            $amtCr = (float)($it['amt_cr'] ?? 0);
            $rowId = "MEMO-" . $escNoBukti . "-" . $seq;

            $sql = "INSERT INTO bc_act_memo 
                    (id, tanggal, no_bukti, seq, akun_db, akun_cr, kode_pembantu, uraian, amt_db, amt_cr, set_by, cmp_id)
                    VALUES ('$rowId', '$escTgl', '$escNoBukti', $seq, '$akunDb', '$akunCr', '$kdBantu', '$uraianRow', $amtDb, $amtCr, '$userId', '$escCmp')";
            $koneksi->query($sql);
            $seq++;
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Jurnal Memorial $noBukti berhasil disimpan"]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan memorial: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Memorial Entries (Tab 1 History)
// =========================================================================
if ($action === 'list_memo') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $dt1 = $_GET['dt1'] ?? date('Y-m-01');
    $dt2 = $_GET['dt2'] ?? date('Y-m-d');
    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $sql = "SELECT a.*, 
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_db AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_db,
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_cr AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_cr
            FROM bc_act_memo a
            WHERE $cmpClause AND a.tanggal BETWEEN '$escDt1' AND '$escDt2'
            ORDER BY a.tanggal DESC, a.no_bukti DESC, a.seq ASC
            LIMIT 1000";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List General Journal Entries (Tab 2)
// =========================================================================
if ($action === 'list_umum') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $dt1 = $_GET['dt1'] ?? date('Y-m-01');
    $dt2 = $_GET['dt2'] ?? date('Y-m-d');
    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $sql = "SELECT a.*, 
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_db AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_db,
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_cr AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_cr
            FROM bc_act_umum a
            WHERE $cmpClause AND a.tanggal BETWEEN '$escDt1' AND '$escDt2'
            ORDER BY a.tanggal DESC, a.no_bukti DESC, a.seq ASC
            LIMIT 1000";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List COA Accounts
// =========================================================================
if ($action === 'get_accounts') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $sql = "SELECT kode, nama_akun as nama, tipe FROM bc_act_akun WHERE $cmpClause ORDER BY kode ASC";
    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jurnal Umum & Memorial - CEISA 4.0 & ERP Hub</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <link rel="stylesheet" href="../assets/ant_grid.css">
    <script src="../assets/ant_grid.js"></script>

    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --app-font-size: 9.5px;
            --form-font-size: 9.5px;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --danger: #f5222d;
            --warning: #faad14;
            --info: #0ea5e9;
            --bg-body: #f4f6f9;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --border-light: #d9d9d9;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
        }
        
        body.theme-dark {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --text-main: #d9d9d9;
            --text-muted: #8c8c8c;
            --border-light: #434343;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --row-hover: #262638;
            --row-selected: #111d2c;
        }

        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--app-font);
            font-size: var(--app-font-size);
            line-height: 1.4;
            height: 100%;
            overflow-x: hidden;
        }

        .main-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 8px 12px;
            gap: 8px;
        }

        /* Top KPI Cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            flex-shrink: 0;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .kpi-icon {
            width: 32px; height: 32px;
            border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .kpi-icon.blue { background: #e6f7ff; color: #1890ff; }
        .kpi-icon.amber { background: #fffbe6; color: #faad14; }
        .kpi-icon.green { background: #f6ffed; color: #52c41a; }
        .kpi-icon.purple { background: #f9f0ff; color: #722ed1; }
        .kpi-data { display: flex; flex-direction: column; min-width: 0; }
        .kpi-label { font-size: 8.5px; color: var(--text-muted); text-transform: uppercase; font-weight: 500; }
        .kpi-val { font-size: 13px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Tabs & Card Container */
        .card-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .card-header-tabs {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-light);
            padding: 0 8px;
            height: 32px;
            flex-shrink: 0;
        }
        .tabs-nav {
            display: flex;
            gap: 4px;
            height: 100%;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            height: 100%;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-muted);
            font-family: inherit;
            font-size: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
            background: var(--bg-card);
        }

        /* Toolbar Filter & Header */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-table);
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .toolbar label { font-size: 9.5px; font-weight: 500; color: var(--text-muted); }
        .ant-input, .ant-select {
            height: 21px;
            padding: 0 6px;
            font-family: inherit;
            font-size: 9.5px;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .ant-input:focus, .ant-select:focus { border-color: var(--primary); }
        .ant-btn {
            height: 21px;
            padding: 0 8px;
            font-family: inherit;
            font-size: 9.5px;
            font-weight: 500;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: #fff;
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff !important;
        }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
        .ant-btn-success {
            background: var(--success);
            border-color: var(--success);
            color: #fff !important;
        }

        /* Grid Table */
        .grid-wrapper {
            flex: 1;
            overflow: auto;
            position: relative;
            min-height: 0;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.ant-table th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border: 1px solid var(--border-table);
            padding: 3px 6px;
            text-align: left;
            font-weight: 600;
            color: var(--text-main);
            z-index: 2;
            user-select: none;
        }
        table.ant-table td {
            border: 1px solid var(--border-table);
            padding: 2px 6px;
            height: 22px;
            vertical-align: middle;
            color: var(--text-main);
        }
        table.ant-table tbody tr:hover { background-color: var(--row-hover); }
        table.ant-table tbody tr.selected { background-color: var(--row-selected); }

        .col-resizer {
            position: absolute;
            top: 0; right: 0;
            width: 4px;
            cursor: col-resize;
            user-select: none;
            height: 100%;
        }

        /* Bottom Status & Summary Bar */
        .footer-bar {
            height: 26px;
            background: var(--table-head-bg);
            border-top: 1px solid var(--border-table);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;
            font-size: 9.5px;
            flex-shrink: 0;
        }

        /* SweetAlert Compact Desktop */
        .swal2-popup.compact-swal {
            width: 330px !important;
            padding: 12px !important;
            font-size: 9.5px !important;
            border-radius: 4px !important;
            font-family: var(--app-font) !important;
        }
        .swal2-popup.compact-swal .swal2-title {
            font-size: 13px !important;
            margin: 0 0 6px 0 !important;
        }
        .swal2-popup.compact-swal .swal2-html-container {
            font-size: 10px !important;
            margin: 0 0 10px 0 !important;
        }
        .swal2-popup.compact-swal .swal2-actions {
            margin-top: 6px !important;
            gap: 6px !important;
        }
        .swal2-popup.compact-swal .swal2-actions button {
            height: 21px !important;
            padding: 0 10px !important;
            font-size: 9.5px !important;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 4 Top KPI Cards -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fa-solid fa-file-pen"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Jurnal Memorial (Bulan Ini)</span>
                    <span class="kpi-val" id="kpi-memo-cnt">0 Voucher</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fa-solid fa-book"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Jurnal Umum / Batch Entry</span>
                    <span class="kpi-val" id="kpi-umum-cnt">0 Voucher</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fa-solid fa-coins"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Nilai Debet Memorial</span>
                    <span class="kpi-val" id="kpi-total-memo-db">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="fa-solid fa-scale-balanced"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Status Keseimbangan GL</span>
                    <span class="kpi-val" id="balance-badge" style="color:var(--success); font-size:11px;">
                        <i class="fa-solid fa-check-circle"></i> BALANCED (Rp 0)
                    </span>
                </div>
            </div>
        </div>

        <!-- Main Card Panel with Tabs -->
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn <?= $initialMode === 'memo' ? 'active' : '' ?>" id="tab-btn-memo" onclick="switchTab('memo')">
                        <i class="fa-solid fa-pen-to-square"></i> 1. Entri Jurnal Memorial / Penyesuaian (bc_act_memo)
                    </button>
                    <button type="button" class="tab-btn <?= $initialMode === 'import' ? 'active' : '' ?>" id="tab-btn-umum" onclick="switchTab('umum')">
                        <i class="fa-solid fa-table-list"></i> 2. Buku Jurnal Umum 2 &amp; Batch Import (bc_act_umum)
                    </button>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="ant-btn" onclick="refreshCurrentTab()" title="Muat Ulang Data">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul & SOP">
                        <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i>
                    </button>
                </div>
            </div>

            <!-- TAB 1: FORM ENTRI JURNAL MEMORIAL -->
            <div id="panel-memo" style="display:<?= $initialMode === 'memo' ? 'flex' : 'none' ?>; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Tgl Transaksi:</label>
                    <input type="date" id="memo-tgl" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <label style="margin-left:6px;">No Bukti Memo:</label>
                    <input type="text" id="memo-no-bukti" class="ant-input" placeholder="Otomatis (atau ketik)..." style="width:140px;">

                    <label style="margin-left:6px;">Keterangan Memo:</label>
                    <input type="text" id="memo-keterangan" class="ant-input" placeholder="Penyesuaian akhir periode..." style="width:220px;">

                    <div style="flex:1;"></div>

                    <button type="button" class="ant-btn" onclick="addMemoRow()">
                        <i class="fa-solid fa-plus"></i> Tambah Baris
                    </button>

                    <button type="button" class="ant-btn ant-btn-success" onclick="saveMemorialJournal()" id="btn-save-memo">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Jurnal Memorial
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-memo-entry">
                        <thead>
                            <tr>
                                <th style="width: 35px; text-align:center;">#</th>
                                <th style="width: 220px;">Akun GL Debet / Kredit <span style="color:var(--danger)">*</span></th>
                                <th style="width: 140px;">Kode Pembantu / Rekanan</th>
                                <th>Keterangan / Uraian Baris</th>
                                <th style="width: 120px; text-align:right;">Debet (Rp)</th>
                                <th style="width: 120px; text-align:right;">Kredit (Rp)</th>
                                <th style="width: 45px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-memo-rows"></tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="memo-status-info">Rincian jurnal memorial (minimal 2 baris berimbang)</span>
                    </div>
                    <div style="display:flex; gap:16px;">
                        <span>Total Debet: <strong id="lbl-total-db">Rp 0</strong></span>
                        <span>Total Kredit: <strong id="lbl-total-cr">Rp 0</strong></span>
                        <span>Selisih: <strong id="lbl-selisih" style="color:var(--success);">Rp 0</strong></span>
                    </div>
                </div>
            </div>

            <!-- TAB 2: BUKU JURNAL UMUM & IMPORT (bc_act_umum) -->
            <div id="panel-umum" style="display:<?= $initialMode === 'import' ? 'flex' : 'none' ?>; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode:</label>
                    <input type="date" id="umum-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="umum-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadUmumJournal()">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>

                    <div style="flex:1;"></div>

                    <button type="button" class="ant-btn" onclick="exportUmumToExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Export Excel
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-umum">
                        <thead>
                            <tr>
                                <th style="width: 75px; text-align:center;">Tanggal</th>
                                <th style="width: 120px;">No Bukti</th>
                                <th style="width: 130px;">Kode Pembantu</th>
                                <th style="width: 160px;">Akun Debet</th>
                                <th style="width: 160px;">Akun Kredit</th>
                                <th>Keterangan / Uraian</th>
                                <th style="width: 100px; text-align:right;">Debet (Rp)</th>
                                <th style="width: 100px; text-align:right;">Kredit (Rp)</th>
                                <th style="width: 80px;">User</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-umum">
                            <tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat buku jurnal umum...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="umum-summary-text">0 transaksi jurnal umum</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let coaAccounts = [];
        let memoRows = [];
        let umumJournals = [];

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadKPI();
            loadAccounts();

            if (window.AntGrid) {
                AntGrid.initTableResizing('#table-memo-entry');
                AntGrid.initTableResizing('#table-umum');
            }

            // Init 2 initial rows for memorial journal
            addMemoRow();
            addMemoRow();
        });

        function initTheme() {
            const savedTheme = localStorage.getItem('ceisa_theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('theme-dark');
            }
            window.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'THEME_CHANGE') {
                    if (e.data.theme === 'dark') document.body.classList.add('theme-dark');
                    else document.body.classList.remove('theme-dark');
                }
            });
        }

        function switchTab(tab) {
            document.getElementById('tab-btn-memo').classList.toggle('active', tab === 'memo');
            document.getElementById('tab-btn-umum').classList.toggle('active', tab === 'umum');
            document.getElementById('panel-memo').style.display = tab === 'memo' ? 'flex' : 'none';
            document.getElementById('panel-umum').style.display = tab === 'umum' ? 'flex' : 'none';

            if (tab === 'umum' && umumJournals.length === 0) {
                loadUmumJournal();
            }
        }

        function refreshCurrentTab() {
            loadKPI();
            if (document.getElementById('tab-btn-memo').classList.contains('active')) {
                // reset or reload
            } else {
                loadUmumJournal();
            }
        }

        async function loadKPI() {
            try {
                const res = await fetch('general_journal.php?action=kpi&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-memo-cnt').textContent = json.kpi.memo_cnt + ' Voucher';
                    document.getElementById('kpi-umum-cnt').textContent = json.kpi.umum_cnt + ' Voucher';
                    document.getElementById('kpi-total-memo-db').textContent = formatRupiah(json.kpi.total_memo_db);
                }
            } catch (e) {
                console.error("Gagal load KPI:", e);
            }
        }

        async function loadAccounts() {
            try {
                const res = await fetch('general_journal.php?action=get_accounts&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && Array.isArray(json.data)) {
                    coaAccounts = json.data;
                    updateAllAccountDropdowns();
                }
            } catch (e) {
                console.error("Gagal load akun:", e);
            }
        }

        function buildAccountOptionsHtml() {
            let opts = '<option value="">-- Pilih Akun GL --</option>';
            coaAccounts.forEach(a => {
                opts += `<option value="${escapeHtml(a.kode)}">${escapeHtml(a.kode)} - ${escapeHtml(a.nama)}</option>`;
            });
            return opts;
        }

        function updateAllAccountDropdowns() {
            const selects = document.querySelectorAll('.sel-row-akun');
            const opts = buildAccountOptionsHtml();
            selects.forEach(sel => {
                const val = sel.value;
                sel.innerHTML = opts;
                if (val) sel.value = val;
            });
        }

        // =========================================================================
        // TAB 1: MEMORIAL JOURNAL MULTI-ROW ENTRY
        // =========================================================================
        function addMemoRow() {
            const tbody = document.getElementById('tbody-memo-rows');
            const rowIdx = tbody.children.length;
            const tr = document.createElement('tr');
            tr.id = `memo-row-${rowIdx}`;

            tr.innerHTML = `
                <td style="text-align:center;">${rowIdx + 1}</td>
                <td>
                    <select class="ant-select sel-row-akun" style="width:100%;" onchange="calculateMemoBalance()">
                        ${buildAccountOptionsHtml()}
                    </select>
                </td>
                <td>
                    <input type="text" class="ant-input input-row-bantu" placeholder="Kode pembantu..." style="width:100%;">
                </td>
                <td>
                    <input type="text" class="ant-input input-row-uraian" placeholder="Uraian baris..." style="width:100%;">
                </td>
                <td>
                    <input type="number" step="0.01" class="ant-input input-row-db" value="0.00" style="width:100%; text-align:right;" oninput="onDbInput(this)">
                </td>
                <td>
                    <input type="number" step="0.01" class="ant-input input-row-cr" value="0.00" style="width:100%; text-align:right;" oninput="onCrInput(this)">
                </td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-danger" style="height:19px; padding:0 5px;" onclick="removeMemoRow(this)" title="Hapus Baris">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </td>
            `;

            tbody.appendChild(tr);
            calculateMemoBalance();
        }

        function removeMemoRow(btn) {
            const tr = btn.closest('tr');
            const tbody = document.getElementById('tbody-memo-rows');
            if (tbody.children.length <= 2) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Minimal 2 Baris',
                    text: 'Jurnal memorial membutuhkan minimal 2 baris (Debet dan Kredit)',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }
            tr.remove();
            Array.from(tbody.children).forEach((r, idx) => {
                r.children[0].textContent = idx + 1;
            });
            calculateMemoBalance();
        }

        function onDbInput(input) {
            const tr = input.closest('tr');
            const crInput = tr.querySelector('.input-row-cr');
            if (parseFloat(input.value) > 0) {
                crInput.value = '0.00';
            }
            calculateMemoBalance();
        }

        function onCrInput(input) {
            const tr = input.closest('tr');
            const dbInput = tr.querySelector('.input-row-db');
            if (parseFloat(input.value) > 0) {
                dbInput.value = '0.00';
            }
            calculateMemoBalance();
        }

        function calculateMemoBalance() {
            let totalDb = 0;
            let totalCr = 0;

            const tbody = document.getElementById('tbody-memo-rows');
            Array.from(tbody.children).forEach(tr => {
                const db = parseFloat(tr.querySelector('.input-row-db').value) || 0;
                const cr = parseFloat(tr.querySelector('.input-row-cr').value) || 0;
                totalDb += db;
                totalCr += cr;
            });

            const diff = Math.abs(totalDb - totalCr);
            document.getElementById('lbl-total-db').textContent = formatRupiah(totalDb);
            document.getElementById('lbl-total-cr').textContent = formatRupiah(totalCr);
            document.getElementById('lbl-selisih').textContent = formatRupiah(diff);

            const badge = document.getElementById('balance-badge');
            if (diff < 0.01 && totalDb > 0) {
                badge.style.color = 'var(--success)';
                badge.innerHTML = '<i class="fa-solid fa-check-circle"></i> BALANCED (Rp 0)';
                document.getElementById('lbl-selisih').style.color = 'var(--success)';
            } else {
                badge.style.color = 'var(--danger)';
                badge.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> SELISIH ${formatRupiah(diff)}`;
                document.getElementById('lbl-selisih').style.color = 'var(--danger)';
            }
        }

        async function saveMemorialJournal() {
            const tgl = document.getElementById('memo-tgl').value;
            const noBukti = document.getElementById('memo-no-bukti').value;
            const note = document.getElementById('memo-keterangan').value;

            const tbody = document.getElementById('tbody-memo-rows');
            const items = [];
            let totalDb = 0;
            let totalCr = 0;

            for (let tr of tbody.children) {
                const akun = tr.querySelector('.sel-row-akun').value;
                const kdBantu = tr.querySelector('.input-row-bantu').value;
                const uraian = tr.querySelector('.input-row-uraian').value;
                const db = parseFloat(tr.querySelector('.input-row-db').value) || 0;
                const cr = parseFloat(tr.querySelector('.input-row-cr').value) || 0;

                if (!akun) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Akun Belum Dipilih',
                        text: 'Silakan pilih Akun GL pada setiap baris jurnal',
                        customClass: { popup: 'compact-swal' }
                    });
                    return;
                }

                if (db === 0 && cr === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Nilai Kosong',
                        text: 'Setiap baris wajib memiliki nilai Debet atau Kredit',
                        customClass: { popup: 'compact-swal' }
                    });
                    return;
                }

                totalDb += db;
                totalCr += cr;

                items.push({
                    akun_db: db > 0 ? akun : '',
                    akun_cr: cr > 0 ? akun : '',
                    kode_bantu: kdBantu,
                    uraian: uraian || note,
                    amt_db: db,
                    amt_cr: cr
                });
            }

            if (Math.abs(totalDb - totalCr) > 0.01) {
                Swal.fire({
                    icon: 'error',
                    title: 'Jurnal Tidak Seimbang',
                    text: `Total Debet (${formatRupiah(totalDb)}) dan Kredit (${formatRupiah(totalCr)}) belum balance`,
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const btn = document.getElementById('btn-save-memo');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';

            try {
                const res = await fetch('general_journal.php?action=save_memo&cmp_id=<?= urlencode($sessionCmpId) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tanggal: tgl,
                        no_bukti: noBukti,
                        keterangan: note,
                        items
                    })
                });
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Jurnal Memorial';

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Jurnal Memorial Tersimpan',
                        text: json.message,
                        timer: 2000,
                        showConfirmButton: false,
                        customClass: { popup: 'compact-swal' }
                    });
                    loadKPI();
                    document.getElementById('memo-no-bukti').value = '';
                    document.getElementById('memo-keterangan').value = '';
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menyimpan',
                        text: json.message || 'Terjadi kesalahan sistem',
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Jurnal Memorial';
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        // =========================================================================
        // TAB 2: GENERAL JOURNAL 2 (bc_act_umum)
        // =========================================================================
        async function loadUmumJournal() {
            const dt1 = document.getElementById('umum-dt1').value;
            const dt2 = document.getElementById('umum-dt2').value;
            const tbody = document.getElementById('tbody-umum');

            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat buku jurnal umum...</td></tr>';

            try {
                const res = await fetch(`general_journal.php?action=list_umum&cmp_id=<?= urlencode($sessionCmpId) ?>&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    umumJournals = json.data;
                    renderUmumTable();
                } else {
                    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat jurnal umum</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderUmumTable() {
            const tbody = document.getElementById('tbody-umum');
            if (umumJournals.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada transaksi jurnal umum pada periode ini.</td></tr>';
                document.getElementById('umum-summary-text').textContent = '0 transaksi';
                return;
            }

            let html = '';
            umumJournals.forEach(r => {
                html += `
                <tr>
                    <td style="text-align:center;">${escapeHtml(r.tanggal)}</td>
                    <td><strong>${escapeHtml(r.no_bukti)}</strong></td>
                    <td>${escapeHtml(r.kode_pembantu || '-')}</td>
                    <td title="${escapeHtml(r.nama_akun_db)}"><code>${escapeHtml(r.akun_db || '')}</code> <span style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.nama_akun_db || '')}</span></td>
                    <td title="${escapeHtml(r.nama_akun_cr)}"><code>${escapeHtml(r.akun_cr || '')}</code> <span style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.nama_akun_cr || '')}</span></td>
                    <td>${escapeHtml(r.uraian || '')}</td>
                    <td style="text-align:right;">${formatNumber(r.amt_db)}</td>
                    <td style="text-align:right;">${formatNumber(r.amt_cr)}</td>
                    <td style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '')}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
            document.getElementById('umum-summary-text').textContent = `${umumJournals.length} baris jurnal umum`;
        }

        function exportUmumToExcel() {
            if (umumJournals.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Data',
                    text: 'Tidak ada data jurnal untuk diekspor',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const exportData = umumJournals.map(r => ({
                'Tanggal': r.tanggal,
                'No Bukti': r.no_bukti,
                'Kode Pembantu': r.kode_pembantu,
                'Akun Debet': r.akun_db,
                'Akun Kredit': r.akun_cr,
                'Uraian': r.uraian,
                'Debet Rp': parseFloat(r.amt_db),
                'Kredit Rp': parseFloat(r.amt_cr),
                'User': r.set_by
            }));

            const ws = XLSX.utils.json_to_sheet(exportData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Jurnal Umum");
            XLSX.writeFile(wb, `Jurnal_Umum_${document.getElementById('umum-dt1').value}_${document.getElementById('umum-dt2').value}.xlsx`);
        }

        function openModuleGuide() {
            Swal.fire({
                icon: 'info',
                title: 'Panduan Modul Jurnal Umum & Memorial',
                html: `
                    <div style="text-align:left; font-size:9.5px; line-height:1.6;">
                        <p><strong>Fungsi Jurnal Memorial &amp; Jurnal Umum:</strong></p>
                        <ol style="padding-left:16px; margin:0;">
                            <li><strong>Jurnal Memorial (Tab 1):</strong> Digunakan untuk membukukan penyesuaian akhir periode akuntansi, amortisasi biaya dibayar di muka, akrual beban, dan revaluasi selisih kurs.</li>
                            <li><strong>Validasi Seimbang:</strong> Sistem secara otomatis menghitung selisih dan mengunci penyimpanan bila total Debet tidak sama dengan Kredit (0 toleransi ketidakseimbangan).</li>
                            <li><strong>Jurnal Umum 2 (Tab 2):</strong> Menampung transaksi jurnal umum manual dan entri batch dengan nomor referensi transaksi.</li>
                        </ol>
                    </div>
                `,
                customClass: { popup: 'compact-swal' },
                confirmButtonText: 'Tutup'
            });
        }

        // Helpers
        function formatNumber(num) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function formatRupiah(num) {
            return 'Rp ' + formatNumber(num);
        }
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

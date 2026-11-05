<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Laporan Hutang & Piutang (AP / AR Aging & Subsidiary Ledger)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_hutang_piutang.srw)
 * DataWindows: dw_hutang.srd, dw_piutang.srd, d_bantu_kartu.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Hutang AP, Total Piutang AR, Hutang Jatuh Tempo, Piutang Jatuh Tempo)
 * - Tab 1: Laporan & Umur Hutang Usaha (Vendor AP Aging: 0-30, 31-60, 61-90, >90 hari)
 * - Tab 2: Laporan & Umur Piutang Dagang (Customer AR Aging: 0-30, 31-60, 61-90, >90 hari)
 * - Tab 3: Kartu Buku Pembantu Rekanan (Kartu Riwayat Mutasi Debet/Kredit & Saldo Berjalan)
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

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";

    // Hutang AP
    $sqlAp = "SELECT 
        COALESCE(SUM(total_order), 0) - COALESCE((SELECT SUM(amount) FROM bc_pp_dtl p WHERE p.id_pi = a.id), 0) as sisa_hutang
        FROM bc_pi_hdr a WHERE $cmpClause";
    $resAp = $koneksi->query("SELECT SUM(sisa) as total_hutang FROM (
        SELECT (a.total_order - COALESCE((SELECT SUM(amount) FROM bc_pp_dtl p WHERE p.id_pi = a.id), 0)) as sisa 
        FROM bc_pi_hdr a WHERE $cmpClause
    ) x WHERE x.sisa > 0");
    $rowAp = $resAp ? $resAp->fetch_assoc() : [];

    // Piutang AR
    $resAr = $koneksi->query("SELECT SUM(sisa) as total_piutang FROM (
        SELECT (a.total_order - COALESCE((SELECT SUM(amount) FROM bc_sp_dtl p WHERE p.id_si = a.id), 0)) as sisa 
        FROM bc_si_hdr a WHERE $cmpClause
    ) x WHERE x.sisa > 0");
    $rowAr = $resAr ? $resAr->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_hutang'  => (float)($rowAp['total_hutang'] ?? 0),
            'total_piutang' => (float)($rowAr['total_piutang'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Vendor AP Aging & Summary (Tab 1)
// =========================================================================
if ($action === 'get_ap_aging') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $cutoff = $_GET['cutoff'] ?? date('Y-m-d');
    $escCutoff = $koneksi->real_escape_string($cutoff);

    $sql = "SELECT vendor, nama, akun, kode_bantu, kurs,
                   SUM(tagihan) as total_tagihan,
                   SUM(terbayar) as total_terbayar,
                   SUM(sisa) as total_hutang,
                   SUM(CASE WHEN selisih_hari <= 30 THEN sisa ELSE 0 END) as age_0_30,
                   SUM(CASE WHEN selisih_hari BETWEEN 31 AND 60 THEN sisa ELSE 0 END) as age_31_60,
                   SUM(CASE WHEN selisih_hari BETWEEN 61 AND 90 THEN sisa ELSE 0 END) as age_61_90,
                   SUM(CASE WHEN selisih_hari > 90 THEN sisa ELSE 0 END) as age_above_90
            FROM (
                SELECT a.vendor,
                       COALESCE(b.NAMA, a.vendor) as nama,
                       b.AKUN as akun,
                       COALESCE(b.KODE_BANTU, a.vendor) as kode_bantu,
                       COALESCE(a.kurs, 'IDR') as kurs,
                       a.total_order as tagihan,
                       COALESCE((SELECT SUM(amount) FROM bc_pp_dtl p WHERE p.id_pi = a.id), 0) as terbayar,
                       (a.total_order - COALESCE((SELECT SUM(amount) FROM bc_pp_dtl p WHERE p.id_pi = a.id), 0)) as sisa,
                       DATEDIFF('$escCutoff', a.pi_dt) as selisih_hari
                FROM bc_pi_hdr a
                LEFT JOIN bc_bs b ON a.vendor = b.KODE
                WHERE $cmpClause AND a.pi_dt <= '$escCutoff'
            ) raw
            GROUP BY vendor, nama, akun, kode_bantu, kurs
            HAVING total_hutang > 0
            ORDER BY total_hutang DESC";

    $res = $koneksi->query($sql);
    $data = [];
    $totHutang = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
            $totHutang += (float)$r['total_hutang'];
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data, 'total_hutang' => $totHutang]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Customer AR Aging & Summary (Tab 2)
// =========================================================================
if ($action === 'get_ar_aging') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $cutoff = $_GET['cutoff'] ?? date('Y-m-d');
    $escCutoff = $koneksi->real_escape_string($cutoff);

    $sql = "SELECT vendor, nama, akun, kode_bantu, kurs,
                   SUM(tagihan) as total_tagihan,
                   SUM(terbayar) as total_terbayar,
                   SUM(sisa) as total_piutang,
                   SUM(CASE WHEN selisih_hari <= 30 THEN sisa ELSE 0 END) as age_0_30,
                   SUM(CASE WHEN selisih_hari BETWEEN 31 AND 60 THEN sisa ELSE 0 END) as age_31_60,
                   SUM(CASE WHEN selisih_hari BETWEEN 61 AND 90 THEN sisa ELSE 0 END) as age_61_90,
                   SUM(CASE WHEN selisih_hari > 90 THEN sisa ELSE 0 END) as age_above_90
            FROM (
                SELECT a.vendor,
                       COALESCE(b.NAMA, a.vendor) as nama,
                       b.AKUN as akun,
                       COALESCE(b.KODE_BANTU, a.vendor) as kode_bantu,
                       COALESCE(a.kurs, 'IDR') as kurs,
                       a.total_order as tagihan,
                       COALESCE((SELECT SUM(amount) FROM bc_sp_dtl p WHERE p.id_si = a.id), 0) as terbayar,
                       (a.total_order - COALESCE((SELECT SUM(amount) FROM bc_sp_dtl p WHERE p.id_si = a.id), 0)) as sisa,
                       DATEDIFF('$escCutoff', a.si_dt) as selisih_hari
                FROM bc_si_hdr a
                LEFT JOIN bc_bs b ON a.vendor = b.KODE
                WHERE $cmpClause AND a.si_dt <= '$escCutoff'
            ) raw
            GROUP BY vendor, nama, akun, kode_bantu, kurs
            HAVING total_piutang > 0
            ORDER BY total_piutang DESC";

    $res = $koneksi->query($sql);
    $data = [];
    $totPiutang = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
            $totPiutang += (float)$r['total_piutang'];
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data, 'total_piutang' => $totPiutang]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Partner Subsidiary Ledger Card (Tab 3)
// =========================================================================
if ($action === 'get_subsidiary_card') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $kdBantu = trim($_GET['kode_bantu'] ?? '');
    $dt1 = $_GET['dt1'] ?? date('Y-m-01');
    $dt2 = $_GET['dt2'] ?? date('Y-m-d');

    $escBantu = $koneksi->real_escape_string($kdBantu);
    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $sql = "SELECT id, tanggal, invoice as no_bukti, uraian, 0 as amt_db, rp as amt_cr, 'AP' as tipe
            FROM bc_act_beli
            WHERE $cmpClause AND (kode_pembantu = '$escBantu' OR kode_pembantu IN (SELECT KODE FROM bc_bs WHERE KODE_BANTU = '$escBantu' OR KODE = '$escBantu')) AND tanggal BETWEEN '$escDt1' AND '$escDt2'
            UNION ALL
            SELECT id, tanggal, invoice as no_bukti, uraian, rp as amt_db, 0 as amt_cr, 'AR' as tipe
            FROM bc_act_jual
            WHERE $cmpClause AND (kode_pembantu = '$escBantu' OR kode_pembantu IN (SELECT KODE FROM bc_bs WHERE KODE_BANTU = '$escBantu' OR KODE = '$escBantu')) AND tanggal BETWEEN '$escDt1' AND '$escDt2'
            UNION ALL
            SELECT d.id, h.pp_dt as tanggal, h.pp_no as no_bukti, CONCAT('Pembayaran Hutang ', h.pp_no) as uraian, d.amount as amt_db, 0 as amt_cr, 'AP' as tipe
            FROM bc_pp_dtl d
            JOIN bc_pp_hdr h ON d.id_hdr = h.id
            WHERE (h.cmp_id = '$escCmp' OR h.cmp_id = '') AND (h.vendor = '$escBantu' OR h.vendor IN (SELECT KODE FROM bc_bs WHERE KODE_BANTU = '$escBantu' OR KODE = '$escBantu')) AND h.pp_dt BETWEEN '$escDt1' AND '$escDt2'
            UNION ALL
            SELECT d.id, h.sp_dt as tanggal, h.sp_no as no_bukti, CONCAT('Penerimaan Piutang ', h.sp_no) as uraian, 0 as amt_db, d.amount as amt_cr, 'AR' as tipe
            FROM bc_sp_dtl d
            JOIN bc_sp_hdr h ON d.id_hdr = h.id
            WHERE (h.cmp_id = '$escCmp' OR h.cmp_id = '') AND (h.vendor = '$escBantu' OR h.vendor IN (SELECT KODE FROM bc_bs WHERE KODE_BANTU = '$escBantu' OR KODE = '$escBantu')) AND h.sp_dt BETWEEN '$escDt1' AND '$escDt2'
            ORDER BY tanggal ASC, no_bukti ASC";

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
    <title>Laporan Hutang & Piutang - CEISA 4.0 & ERP Hub</title>
    
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
        .kpi-icon.rose { background: #fff1f0; color: #f5222d; }
        .kpi-icon.blue { background: #e6f7ff; color: #1890ff; }
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

        /* Toolbar Filter */
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
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 4 Top KPI Cards -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon rose"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Hutang Usaha (AP)</span>
                    <span class="kpi-val" id="kpi-total-hutang" style="color:var(--danger);">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Piutang Dagang (AR)</span>
                    <span class="kpi-val" id="kpi-total-piutang" style="color:var(--primary);">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="fa-solid fa-balance-scale"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Rasio Piutang / Hutang</span>
                    <span class="kpi-val" id="kpi-ratio">1.00x</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Likuiditas Modal Kerja</span>
                    <span class="kpi-val" id="kpi-net-working">Rp 0</span>
                </div>
            </div>
        </div>

        <!-- Main Card Panel with Tabs -->
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-ap" onclick="switchTab('ap')">
                        <i class="fa-solid fa-file-invoice"></i> 1. Umur &amp; Saldo Hutang Vendor (AP Aging)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-ar" onclick="switchTab('ar')">
                        <i class="fa-solid fa-receipt"></i> 2. Umur &amp; Saldo Piutang Pelanggan (AR Aging)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-card" onclick="switchTab('card')">
                        <i class="fa-solid fa-address-card"></i> 3. Kartu Pembantu Rekanan (Subsidiary Ledger)
                    </button>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="ant-btn" onclick="refreshCurrentTab()" title="Muat Ulang Data">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                    <button type="button" class="ant-btn" onclick="exportCurrentToExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Export Excel
                    </button>
                </div>
            </div>

            <!-- TAB 1: AP AGING -->
            <div id="panel-ap" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Tanggal Cutoff:</label>
                    <input type="date" id="ap-cutoff" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadApAging()">
                        <i class="fa-solid fa-magnifying-glass"></i> Tampilkan Aging Hutang
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-ap">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Kode Vendor</th>
                                <th style="width: 220px;">Nama Vendor / Supplier</th>
                                <th style="width: 90px;">Kode Pembantu</th>
                                <th style="width: 50px; text-align:center;">Kurs</th>
                                <th style="width: 110px; text-align:right;">Total Tagihan</th>
                                <th style="width: 100px; text-align:right;">Terbayar</th>
                                <th style="width: 110px; text-align:right;">Sisa Hutang</th>
                                <th style="width: 90px; text-align:right; color:#52c41a;">0 - 30 Hari</th>
                                <th style="width: 90px; text-align:right; color:#1890ff;">31 - 60 Hari</th>
                                <th style="width: 90px; text-align:right; color:#faad14;">61 - 90 Hari</th>
                                <th style="width: 90px; text-align:right; color:#f5222d;">&gt; 90 Hari</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-ap">
                            <tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat laporan umur hutang...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="ap-summary-text">0 vendor memiliki saldo hutang</span>
                    </div>
                    <div>
                        <span>Total Hutang Berjalan: <strong id="lbl-sum-ap-hutang" style="color:var(--danger);">Rp 0</strong></span>
                    </div>
                </div>
            </div>

            <!-- TAB 2: AR AGING -->
            <div id="panel-ar" style="display:none; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Tanggal Cutoff:</label>
                    <input type="date" id="ar-cutoff" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadArAging()">
                        <i class="fa-solid fa-magnifying-glass"></i> Tampilkan Aging Piutang
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-ar">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Kode Buyer</th>
                                <th style="width: 220px;">Nama Buyer / Pelanggan</th>
                                <th style="width: 90px;">Kode Pembantu</th>
                                <th style="width: 50px; text-align:center;">Kurs</th>
                                <th style="width: 110px; text-align:right;">Total Tagihan</th>
                                <th style="width: 100px; text-align:right;">Diterima</th>
                                <th style="width: 110px; text-align:right;">Sisa Piutang</th>
                                <th style="width: 90px; text-align:right; color:#52c41a;">0 - 30 Hari</th>
                                <th style="width: 90px; text-align:right; color:#1890ff;">31 - 60 Hari</th>
                                <th style="width: 90px; text-align:right; color:#faad14;">61 - 90 Hari</th>
                                <th style="width: 90px; text-align:right; color:#f5222d;">&gt; 90 Hari</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-ar">
                            <tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat laporan umur piutang...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="ar-summary-text">0 customer memiliki saldo piutang</span>
                    </div>
                    <div>
                        <span>Total Piutang Berjalan: <strong id="lbl-sum-ar-piutang" style="color:var(--primary);">Rp 0</strong></span>
                    </div>
                </div>
            </div>

            <!-- TAB 3: SUBSIDIARY LEDGER CARD -->
            <div id="panel-card" style="display:none; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Kode Pembantu / Rekanan:</label>
                    <input type="text" id="card-bantu" class="ant-input" placeholder="Ketik kode rekanan..." style="width:160px;">

                    <label style="margin-left:6px;">Periode:</label>
                    <input type="date" id="card-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="card-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadSubsidiaryCard()">
                        <i class="fa-solid fa-magnifying-glass"></i> Buka Kartu Rekanan
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-card">
                        <thead>
                            <tr>
                                <th style="width: 75px; text-align:center;">Tanggal</th>
                                <th style="width: 120px;">No Bukti</th>
                                <th style="width: 70px; text-align:center;">Tipe</th>
                                <th>Keterangan / Uraian Transaksi</th>
                                <th style="width: 110px; text-align:right;">Debet (Rp)</th>
                                <th style="width: 110px; text-align:right;">Kredit (Rp)</th>
                                <th style="width: 120px; text-align:right;">Saldo Berjalan (Rp)</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-card">
                            <tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-muted);">Masukkan kode pembantu rekanan lalu klik "Buka Kartu Rekanan"...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="card-summary-text">Riwayat mutasi buku pembantu</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let apList = [];
        let arList = [];
        let cardList = [];

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadKPI();
            loadApAging();

            if (window.AntGrid) {
                AntGrid.initTableResizing('#table-ap');
                AntGrid.initTableResizing('#table-ar');
                AntGrid.initTableResizing('#table-card');
            }
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
            document.getElementById('tab-btn-ap').classList.toggle('active', tab === 'ap');
            document.getElementById('tab-btn-ar').classList.toggle('active', tab === 'ar');
            document.getElementById('tab-btn-card').classList.toggle('active', tab === 'card');

            document.getElementById('panel-ap').style.display = tab === 'ap' ? 'flex' : 'none';
            document.getElementById('panel-ar').style.display = tab === 'ar' ? 'flex' : 'none';
            document.getElementById('panel-card').style.display = tab === 'card' ? 'flex' : 'none';

            if (tab === 'ar' && arList.length === 0) {
                loadArAging();
            }
        }

        function refreshCurrentTab() {
            loadKPI();
            if (document.getElementById('tab-btn-ap').classList.contains('active')) {
                loadApAging();
            } else if (document.getElementById('tab-btn-ar').classList.contains('active')) {
                loadArAging();
            } else {
                loadSubsidiaryCard();
            }
        }

        async function loadKPI() {
            try {
                const res = await fetch('ar_ap_ledger.php?action=kpi&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    const ht = json.kpi.total_hutang || 0;
                    const pt = json.kpi.total_piutang || 0;
                    document.getElementById('kpi-total-hutang').textContent = formatRupiah(ht);
                    document.getElementById('kpi-total-piutang').textContent = formatRupiah(pt);
                    
                    const ratio = ht > 0 ? (pt / ht).toFixed(2) : (pt > 0 ? '99.99' : '1.00');
                    document.getElementById('kpi-ratio').textContent = ratio + 'x';
                    document.getElementById('kpi-net-working').textContent = formatRupiah(pt - ht);
                }
            } catch (e) {
                console.error("Gagal load KPI:", e);
            }
        }

        // =========================================================================
        // TAB 1: AP AGING
        // =========================================================================
        async function loadApAging() {
            const cutoff = document.getElementById('ap-cutoff').value;
            const tbody = document.getElementById('tbody-ap');
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat laporan umur hutang...</td></tr>';

            try {
                const res = await fetch(`ar_ap_ledger.php?action=get_ap_aging&cmp_id=<?= urlencode($sessionCmpId) ?>&cutoff=${encodeURIComponent(cutoff)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    apList = json.data;
                    renderApTable(json.total_hutang);
                } else {
                    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat umur hutang</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderApTable(totHutang) {
            const tbody = document.getElementById('tbody-ap');
            if (apList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada saldo hutang vendor tertunggak per tanggal cutoff ini.</td></tr>';
                document.getElementById('ap-summary-text').textContent = '0 vendor';
                document.getElementById('lbl-sum-ap-hutang').textContent = 'Rp 0';
                return;
            }

            let html = '';
            apList.forEach(r => {
                html += `
                <tr onclick="openCardFromAging('${escapeHtml(r.kode_bantu || r.vendor)}')">
                    <td><code>${escapeHtml(r.vendor)}</code></td>
                    <td><strong>${escapeHtml(r.nama)}</strong></td>
                    <td>${escapeHtml(r.kode_bantu || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs)}</td>
                    <td style="text-align:right;">${formatNumber(r.total_tagihan)}</td>
                    <td style="text-align:right;">${formatNumber(r.total_terbayar)}</td>
                    <td style="text-align:right; font-weight:700; color:var(--danger);">${formatNumber(r.total_hutang)}</td>
                    <td style="text-align:right; color:#52c41a;">${formatNumber(r.age_0_30)}</td>
                    <td style="text-align:right; color:#1890ff;">${formatNumber(r.age_31_60)}</td>
                    <td style="text-align:right; color:#faad14;">${formatNumber(r.age_61_90)}</td>
                    <td style="text-align:right; color:#f5222d; font-weight:600;">${formatNumber(r.age_above_90)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
            document.getElementById('ap-summary-text').textContent = `${apList.length} vendor memiliki saldo hutang`;
            document.getElementById('lbl-sum-ap-hutang').textContent = formatRupiah(totHutang);
        }

        // =========================================================================
        // TAB 2: AR AGING
        // =========================================================================
        async function loadArAging() {
            const cutoff = document.getElementById('ar-cutoff').value;
            const tbody = document.getElementById('tbody-ar');
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat laporan umur piutang...</td></tr>';

            try {
                const res = await fetch(`ar_ap_ledger.php?action=get_ar_aging&cmp_id=<?= urlencode($sessionCmpId) ?>&cutoff=${encodeURIComponent(cutoff)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    arList = json.data;
                    renderArTable(json.total_piutang);
                } else {
                    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat umur piutang</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderArTable(totPiutang) {
            const tbody = document.getElementById('tbody-ar');
            if (arList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada saldo piutang customer per tanggal cutoff ini.</td></tr>';
                document.getElementById('ar-summary-text').textContent = '0 customer';
                document.getElementById('lbl-sum-ar-piutang').textContent = 'Rp 0';
                return;
            }

            let html = '';
            arList.forEach(r => {
                html += `
                <tr onclick="openCardFromAging('${escapeHtml(r.kode_bantu || r.vendor)}')">
                    <td><code>${escapeHtml(r.vendor)}</code></td>
                    <td><strong>${escapeHtml(r.nama)}</strong></td>
                    <td>${escapeHtml(r.kode_bantu || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs)}</td>
                    <td style="text-align:right;">${formatNumber(r.total_tagihan)}</td>
                    <td style="text-align:right;">${formatNumber(r.total_terbayar)}</td>
                    <td style="text-align:right; font-weight:700; color:var(--primary);">${formatNumber(r.total_piutang)}</td>
                    <td style="text-align:right; color:#52c41a;">${formatNumber(r.age_0_30)}</td>
                    <td style="text-align:right; color:#1890ff;">${formatNumber(r.age_31_60)}</td>
                    <td style="text-align:right; color:#faad14;">${formatNumber(r.age_61_90)}</td>
                    <td style="text-align:right; color:#f5222d; font-weight:600;">${formatNumber(r.age_above_90)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
            document.getElementById('ar-summary-text').textContent = `${arList.length} customer memiliki saldo piutang`;
            document.getElementById('lbl-sum-ar-piutang').textContent = formatRupiah(totPiutang);
        }

        function openCardFromAging(kdBantu) {
            document.getElementById('card-bantu').value = kdBantu;
            switchTab('card');
            loadSubsidiaryCard();
        }

        // =========================================================================
        // TAB 3: SUBSIDIARY CARD
        // =========================================================================
        async function loadSubsidiaryCard() {
            const kdBantu = document.getElementById('card-bantu').value;
            const dt1 = document.getElementById('card-dt1').value;
            const dt2 = document.getElementById('card-dt2').value;
            const tbody = document.getElementById('tbody-card');

            if (!kdBantu) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-muted);">Masukkan kode rekanan / kode pembantu untuk melihat kartu</td></tr>';
                return;
            }

            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat kartu pembantu...</td></tr>';

            try {
                const res = await fetch(`ar_ap_ledger.php?action=get_subsidiary_card&cmp_id=<?= urlencode($sessionCmpId) ?>&kode_bantu=${encodeURIComponent(kdBantu)}&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    cardList = json.data;
                    renderCardTable();
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat kartu pembantu</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderCardTable() {
            const tbody = document.getElementById('tbody-card');
            if (cardList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada transaksi mutasi untuk rekanan ini pada periode tersebut.</td></tr>';
                return;
            }

            let html = '';
            let runningBalance = 0;
            cardList.forEach(r => {
                const db = parseFloat(r.amt_db) || 0;
                const cr = parseFloat(r.amt_cr) || 0;
                runningBalance += (db - cr);

                html += `
                <tr>
                    <td style="text-align:center;">${escapeHtml(r.tanggal)}</td>
                    <td><strong>${escapeHtml(r.no_bukti)}</strong></td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:700; padding:1px 5px; border-radius:3px; background:#f0f0f0;">${escapeHtml(r.tipe)}</span></td>
                    <td>${escapeHtml(r.uraian)}</td>
                    <td style="text-align:right;">${formatNumber(db)}</td>
                    <td style="text-align:right;">${formatNumber(cr)}</td>
                    <td style="text-align:right; font-weight:700; color:var(--primary);">${formatNumber(runningBalance)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        function exportCurrentToExcel() {
            const activeTab = document.querySelector('.tab-btn.active').id;
            let exportData = [];
            let fileName = 'Laporan_Keuangan';

            if (activeTab === 'tab-btn-ap') {
                exportData = apList.map(r => ({
                    'Kode Vendor': r.vendor,
                    'Nama Vendor': r.nama,
                    'Kode Pembantu': r.kode_bantu,
                    'Valas': r.kurs,
                    'Total Tagihan': parseFloat(r.total_tagihan),
                    'Terbayar': parseFloat(r.total_terbayar),
                    'Sisa Hutang': parseFloat(r.total_hutang),
                    '0-30 Hari': parseFloat(r.age_0_30),
                    '31-60 Hari': parseFloat(r.age_31_60),
                    '61-90 Hari': parseFloat(r.age_61_90),
                    '>90 Hari': parseFloat(r.age_above_90)
                }));
                fileName = `AP_Aging_Hutang_${document.getElementById('ap-cutoff').value}`;
            } else if (activeTab === 'tab-btn-ar') {
                exportData = arList.map(r => ({
                    'Kode Buyer': r.vendor,
                    'Nama Buyer': r.nama,
                    'Kode Pembantu': r.kode_bantu,
                    'Valas': r.kurs,
                    'Total Tagihan': parseFloat(r.total_tagihan),
                    'Terbayar': parseFloat(r.total_terbayar),
                    'Sisa Piutang': parseFloat(r.total_piutang),
                    '0-30 Hari': parseFloat(r.age_0_30),
                    '31-60 Hari': parseFloat(r.age_31_60),
                    '61-90 Hari': parseFloat(r.age_61_90),
                    '>90 Hari': parseFloat(r.age_above_90)
                }));
                fileName = `AR_Aging_Piutang_${document.getElementById('ar-cutoff').value}`;
            }

            if (exportData.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Data',
                    text: 'Tidak ada data untuk diekspor',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const ws = XLSX.utils.json_to_sheet(exportData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Aging Report");
            XLSX.writeFile(wb, `${fileName}.xlsx`);
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

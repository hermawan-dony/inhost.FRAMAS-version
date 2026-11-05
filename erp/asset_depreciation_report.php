<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Laporan Depresiasi Asset & Posting Beban ke GL
 * Modernisasi Modul Fixed Assets & Depresiasi Aktiva Tetap
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Harga Perolehan, Akumulasi Depresiasi, Beban Bulan Ini, Nilai Buku Bersih)
 * - Tab 1: Jadwal Rincian Depresiasi Aktiva Tetap (Metode Garis Lurus / Straight-Line)
 * - Tab 2: Riwayat Posting Jurnal Depresiasi Bulanan (bc_act_memo)
 * - Fitur Otomatis 1-Klik: "Posting Beban Depresiasi Bulan Ini ke GL"
 * - DataGrid Enterprise Resizable (.col-resizer) & Right-Click Context Menu
 * - Export ke Microsoft Excel (.xlsx) Lengkap
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

// Ensure tables
function ensureDepreciationTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_fa_depresiasi_log` (
        `id` varchar(50) NOT NULL,
        `periode` varchar(7) NOT NULL,
        `tanggal` date NOT NULL,
        `no_bukti` varchar(40) NOT NULL,
        `total_beban` decimal(18,2) DEFAULT '0.00',
        `asset_count` int(11) DEFAULT '0',
        `created_by` varchar(40) DEFAULT '',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_periode` (`periode`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureDepreciationTables($koneksi, $escCmp);

// Helper where clause for Fixed Assets (bc_material)
function getFaWhereClause($sessionCmpId, $escCmp) {
    $cmp = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)" : "1=1";
    return "$cmp AND (IFNULL(a.UNIT_ASET, 0) > 0 OR a.KLAS IN (SELECT tipe FROM bc_tipe WHERE grp IN ('CG', 'FA')) OR a.KLAS IN ('Factory Equipment', 'Machine', 'Office Equipments', 'Part Machine', 'Vehicles', 'Buildings', 'Land', 'Tools', 'Mold', 'Capital Goods'))";
}

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $whereFa = getFaWhereClause($sessionCmpId, $escCmp);

    $sql = "SELECT a.KODE_BARANG, a.URAIAN, a.KLAS, a.TGL_ASET, a.TAHUN_ASET, a.UNIT_PRICE, a.UNIT_ASET
            FROM bc_material a WHERE $whereFa";
    $res = $koneksi->query($sql);

    $totalPerolehan = 0;
    $totalAkumulasi = 0;
    $totalNbv = 0;
    $bebanBulan = 0;
    $now = new DateTime();

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cost = floatval($r['UNIT_PRICE']) * max(1, intval($r['UNIT_ASET']));
            $years = floatval($r['TAHUN_ASET']) > 0 ? floatval($r['TAHUN_ASET']) : 4;
            $tgl = (!empty($r['TGL_ASET']) && $r['TGL_ASET'] !== '0000-00-00') ? $r['TGL_ASET'] : date('Y-01-01');
            $tglDt = new DateTime($tgl);
            $diffMonths = max(0, (($now->format('Y') - $tglDt->format('Y')) * 12) + ($now->format('m') - $tglDt->format('m')));

            $totalMonths = $years * 12;
            $depPerMonth = $cost / $totalMonths;
            $accum = min($cost, $depPerMonth * $diffMonths);
            $nbv = max(0, $cost - $accum);

            $totalPerolehan += $cost;
            $totalAkumulasi += $accum;
            $totalNbv += $nbv;
            $bebanBulan += $depPerMonth;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_perolehan' => (float)$totalPerolehan,
            'total_nbv'       => (float)$totalNbv,
            'total_akumulasi' => (float)$totalAkumulasi,
            'beban_bulan'     => (float)$bebanBulan
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Asset Schedule (Tab 1)
// =========================================================================
if ($action === 'get_schedule') {
    header('Content-Type: application/json; charset=utf-8');
    $whereFa = getFaWhereClause($sessionCmpId, $escCmp);
    $kategori = trim($_GET['kategori'] ?? '');

    if (!empty($kategori)) {
        $escK = $koneksi->real_escape_string($kategori);
        $whereFa .= " AND a.KLAS = '$escK'";
    }

    $sql = "SELECT a.KODE_BARANG as kode_asset, a.URAIAN as nama_asset, a.KLAS as kategori,
                   a.TGL_ASET, a.TAHUN_ASET, a.UNIT_PRICE, a.UNIT_ASET,
                   t.akun_aktiva as akun_aset, t.akun_saldo_akhir as akun_akumulasi, t.akun_depre as akun_beban
            FROM bc_material a
            LEFT JOIN bc_act_tipe t ON a.KLAS = t.tipe
            WHERE $whereFa
            ORDER BY a.KLAS ASC, a.KODE_BARANG ASC";

    $res = $koneksi->query($sql);
    $data = [];
    $now = new DateTime();

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cost = floatval($r['UNIT_PRICE']) * max(1, intval($r['UNIT_ASET']));
            $years = floatval($r['TAHUN_ASET']) > 0 ? floatval($r['TAHUN_ASET']) : 4;
            $rate = round(100 / $years, 2);
            $tgl = (!empty($r['TGL_ASET']) && $r['TGL_ASET'] !== '0000-00-00') ? $r['TGL_ASET'] : date('Y-01-01');
            $tglDt = new DateTime($tgl);
            $diffMonths = max(0, (($now->format('Y') - $tglDt->format('Y')) * 12) + ($now->format('m') - $tglDt->format('m')));

            $totalMonths = $years * 12;
            $deprePerMonth = round($cost / $totalMonths, 2);
            $accum = min($cost, round($deprePerMonth * $diffMonths, 2));
            $nbv = max(0, round($cost - $accum, 2));

            $data[] = [
                'kode_asset'           => $r['kode_asset'],
                'nama_asset'           => $r['nama_asset'],
                'kategori'             => $r['kategori'],
                'tgl_perolehan'        => $tgl,
                'masa_manfaat_tahun'   => $years,
                'tarif_persen'         => $rate,
                'harga_perolehan'      => $cost,
                'akumulasi_depresiasi' => $accum,
                'beban_bulanan'        => $deprePerMonth,
                'nilai_buku'           => $nbv,
                'akun_aset'            => $r['akun_aset'] ?: '120-01-003',
                'akun_akumulasi'       => $r['akun_akumulasi'] ?: '121-01-003',
                'akun_beban'           => $r['akun_beban'] ?: '514-01-001'
            ];
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Post Monthly Depreciation to GL (Memorial)
// =========================================================================
if ($action === 'post_depreciation') {
    header('Content-Type: application/json; charset=utf-8');
    $periode = $_GET['periode'] ?? date('Y-m');
    $escYm = $koneksi->real_escape_string($periode);
    $whereFa = getFaWhereClause($sessionCmpId, $escCmp);

    $tglPost = date('Y-m-t', strtotime("$periode-01"));
    $noBukti = "DEPRE/$escYm/" . strtoupper(substr(uniqid(), -4));

    $koneksi->begin_transaction();
    try {
        $sql = "SELECT a.KODE_BARANG as kode_asset, a.URAIAN as nama_asset, a.KLAS as kategori,
                       a.TGL_ASET, a.TAHUN_ASET, a.UNIT_PRICE, a.UNIT_ASET,
                       t.akun_aktiva as akun_aset, t.akun_saldo_akhir as akun_akumulasi, t.akun_depre as akun_beban
                FROM bc_material a
                LEFT JOIN bc_act_tipe t ON a.KLAS = t.tipe
                WHERE $whereFa
                ORDER BY a.KLAS ASC, a.KODE_BARANG ASC";
        $resAssets = $koneksi->query($sql);

        $totBeban = 0;
        $cnt = 0;
        $seq = 1;
        $targetDt = new DateTime($tglPost);

        if ($resAssets) {
            while ($a = $resAssets->fetch_assoc()) {
                $cost = floatval($a['UNIT_PRICE']) * max(1, intval($a['UNIT_ASET']));
                $years = floatval($a['TAHUN_ASET']) > 0 ? floatval($a['TAHUN_ASET']) : 4;
                $tgl = (!empty($a['TGL_ASET']) && $a['TGL_ASET'] !== '0000-00-00') ? $a['TGL_ASET'] : date('Y-01-01');
                $tglDt = new DateTime($tgl);
                $diffMonths = max(0, (($targetDt->format('Y') - $tglDt->format('Y')) * 12) + ($targetDt->format('m') - $tglDt->format('m')));

                $totalMonths = $years * 12;
                $beban = round($cost / $totalMonths, 2);
                if ($beban <= 0) continue;

                $accum = min($cost, round($beban * $diffMonths, 2));
                $nbv = max(0, round($cost - $accum, 2));

                if ($nbv <= 0) continue; // Sudah habis masa manfaatnya
                if ($beban > $nbv) {
                    $beban = $nbv;
                }

                $akunDbBeban = !empty($a['akun_beban']) ? $a['akun_beban'] : '514-01-001'; // Beban Depresiasi
                $akunCrAkum  = !empty($a['akun_akumulasi']) ? $a['akun_akumulasi'] : '121-01-003'; // Akumulasi Depresiasi

                $uraianDb = "Beban Depresiasi {$a['nama_asset']} ($periode)";
                $uraianCr = "Akumulasi Depresiasi {$a['nama_asset']} ($periode)";

                // Insert Debet ke bc_act_memo
                $idDb = "DEPR-$escYm-" . $a['kode_asset'] . "-DB";
                $koneksi->query("REPLACE INTO bc_act_memo 
                    (id, tanggal, no_bukti, seq, akun_db, akun_cr, kode_pembantu, uraian, amt_db, amt_cr, set_by, cmp_id)
                    VALUES ('$idDb', '$tglPost', '$noBukti', $seq, '$akunDbBeban', '', '{$a['kode_asset']}', '$uraianDb', $beban, 0, '$userId', '$escCmp')");
                $seq++;

                // Insert Kredit ke bc_act_memo
                $idCr = "DEPR-$escYm-" . $a['kode_asset'] . "-CR";
                $koneksi->query("REPLACE INTO bc_act_memo 
                    (id, tanggal, no_bukti, seq, akun_db, akun_cr, kode_pembantu, uraian, amt_db, amt_cr, set_by, cmp_id)
                    VALUES ('$idCr', '$tglPost', '$noBukti', $seq, '', '$akunCrAkum', '{$a['kode_asset']}', '$uraianCr', 0, $beban, '$userId', '$escCmp')");
                $seq++;

                $totBeban += $beban;
                $cnt++;
            }
        }

        // Simpan log posting
        $logId = "LOG-DEPR-" . $escYm;
        $koneksi->query("REPLACE INTO bc_fa_depresiasi_log 
            (id, periode, tanggal, no_bukti, total_beban, asset_count, created_by, cmp_id)
            VALUES ('$logId', '$escYm', '$tglPost', '$noBukti', $totBeban, $cnt, '$userId', '$escCmp')");

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK',
            'message' => "Berhasil memposting beban depresiasi periode $periode untuk $cnt aset ke Jurnal Memorial (Total: Rp " . number_format($totBeban, 2) . ")"
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal posting depresiasi: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Depreciation Logs (Tab 2)
// =========================================================================
if ($action === 'get_logs') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";

    $sql = "SELECT * FROM bc_fa_depresiasi_log WHERE $cmpClause ORDER BY periode DESC";
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
    <title>Laporan Depresiasi Asset - CEISA 4.0 & ERP Hub</title>
    
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
        .kpi-icon.rose { background: #fff1f0; color: #f5222d; }
        .kpi-icon.amber { background: #fffbe6; color: #faad14; }
        .kpi-icon.green { background: #f6ffed; color: #52c41a; }
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
        .ant-input {
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

        .col-resizer {
            position: absolute;
            top: 0; right: 0;
            width: 4px;
            cursor: col-resize;
            user-select: none;
            height: 100%;
        }

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
                <div class="kpi-icon blue"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Harga Perolehan Aset</span>
                    <span class="kpi-val" id="kpi-perolehan">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon rose"><i class="fa-solid fa-arrow-trend-down"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Akumulasi Depresiasi</span>
                    <span class="kpi-val" id="kpi-akumulasi" style="color:var(--danger);">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fa-solid fa-receipt"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Beban Depresiasi Bulan Berjalan</span>
                    <span class="kpi-val" id="kpi-beban-bulan">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Nilai Buku Bersih (NBV)</span>
                    <span class="kpi-val" id="kpi-nbv" style="color:var(--success);">Rp 0</span>
                </div>
            </div>
        </div>

        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-schedule" onclick="switchTab('schedule')">
                        <i class="fa-solid fa-calendar-days"></i> 1. Jadwal &amp; Rincian Depresiasi Aset Tetap
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-logs" onclick="switchTab('logs')">
                        <i class="fa-solid fa-clock-rotate-left"></i> 2. Riwayat Posting Jurnal Depresiasi
                    </button>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="ant-btn ant-btn-success" onclick="postDepreciationToGL()" id="btn-post-depr">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Posting Beban Depresiasi Bulan Ini ke GL
                    </button>
                    <button type="button" class="ant-btn" onclick="exportToExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Export Excel
                    </button>
                </div>
            </div>

            <!-- TAB 1: SCHEDULE -->
            <div id="panel-schedule" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode Posting:</label>
                    <input type="month" id="sel-depr-month" class="ant-input" value="<?= date('Y-m') ?>">

                    <label style="margin-left:6px;">Kategori Golongan:</label>
                    <input type="text" id="sel-kategori" class="ant-input" placeholder="Cari golongan..." style="width:140px;">

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadSchedule()">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-schedule">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Kode Asset</th>
                                <th style="width: 180px;">Nama Aset Tetap</th>
                                <th style="width: 100px;">Kategori</th>
                                <th style="width: 75px; text-align:center;">Tgl Perolehan</th>
                                <th style="width: 60px; text-align:right;">Umur (Thn)</th>
                                <th style="width: 55px; text-align:right;">Tarif %</th>
                                <th style="width: 110px; text-align:right;">Harga Perolehan</th>
                                <th style="width: 110px; text-align:right;">Akumulasi Depresiasi</th>
                                <th style="width: 100px; text-align:right; color:#faad14;">Beban Bulan Ini</th>
                                <th style="width: 110px; text-align:right; font-weight:700; color:var(--primary);">Nilai Buku (NBV)</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-schedule">
                            <tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat daftar depresiasi aset...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="schedule-summary-text">0 unit aset terdaftar</span>
                    </div>
                </div>
            </div>

            <!-- TAB 2: LOGS -->
            <div id="panel-logs" style="display:none; flex-direction:column; flex:1; min-height:0;">
                <div class="grid-wrapper">
                    <table class="ant-table" id="table-logs">
                        <thead>
                            <tr>
                                <th style="width: 75px; text-align:center;">Periode</th>
                                <th style="width: 80px; text-align:center;">Tgl Posting</th>
                                <th style="width: 140px;">No Bukti Jurnal</th>
                                <th style="width: 80px; text-align:right;">Jumlah Aset</th>
                                <th style="width: 120px; text-align:right;">Total Beban Depresiasi</th>
                                <th style="width: 100px;">Diposting Oleh</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-logs">
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat riwayat posting...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        let assetList = [];
        let logList = [];

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadKPI();
            loadSchedule();

            if (window.AntGrid) {
                AntGrid.initTableResizing('#table-schedule');
                AntGrid.initTableResizing('#table-logs');
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
            document.getElementById('tab-btn-schedule').classList.toggle('active', tab === 'schedule');
            document.getElementById('tab-btn-logs').classList.toggle('active', tab === 'logs');
            document.getElementById('panel-schedule').style.display = tab === 'schedule' ? 'flex' : 'none';
            document.getElementById('panel-logs').style.display = tab === 'logs' ? 'flex' : 'none';

            if (tab === 'logs' && logList.length === 0) {
                loadLogs();
            }
        }

        async function loadKPI() {
            try {
                const res = await fetch('asset_depreciation_report.php?action=kpi&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-perolehan').textContent = formatRupiah(json.kpi.total_perolehan);
                    document.getElementById('kpi-akumulasi').textContent = formatRupiah(json.kpi.total_akumulasi);
                    document.getElementById('kpi-beban-bulan').textContent = formatRupiah(json.kpi.beban_bulan);
                    document.getElementById('kpi-nbv').textContent = formatRupiah(json.kpi.total_nbv);
                }
            } catch (e) {
                console.error("Gagal load KPI:", e);
            }
        }

        async function loadSchedule() {
            const kat = document.getElementById('sel-kategori').value;
            const tbody = document.getElementById('tbody-schedule');
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat jadwal depresiasi...</td></tr>';

            try {
                const res = await fetch(`asset_depreciation_report.php?action=get_schedule&cmp_id=<?= urlencode($sessionCmpId) ?>&kategori=${encodeURIComponent(kat)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    assetList = json.data;
                    renderScheduleTable();
                } else {
                    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat jadwal</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderScheduleTable() {
            const tbody = document.getElementById('tbody-schedule');
            if (assetList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada aktiva tetap yang terdaftar di master asset.</td></tr>';
                document.getElementById('schedule-summary-text').textContent = '0 unit aset';
                return;
            }

            let html = '';
            assetList.forEach(r => {
                html += `
                <tr>
                    <td><code>${escapeHtml(r.kode_asset)}</code></td>
                    <td><strong>${escapeHtml(r.nama_asset)}</strong></td>
                    <td>${escapeHtml(r.kategori || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.tgl_perolehan || '-')}</td>
                    <td style="text-align:right;">${formatNumber(r.masa_manfaat_tahun)}</td>
                    <td style="text-align:right;">${formatNumber(r.tarif_persen)}%</td>
                    <td style="text-align:right;">${formatNumber(r.harga_perolehan)}</td>
                    <td style="text-align:right; color:var(--danger);">${formatNumber(r.akumulasi_depresiasi)}</td>
                    <td style="text-align:right; color:#faad14; font-weight:600;">${formatNumber(r.beban_bulanan)}</td>
                    <td style="text-align:right; font-weight:700; color:var(--primary);">${formatNumber(r.nilai_buku)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
            document.getElementById('schedule-summary-text').textContent = `${assetList.length} unit aset terdaftar`;
        }

        async function loadLogs() {
            const tbody = document.getElementById('tbody-logs');
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat riwayat posting...</td></tr>';

            try {
                const res = await fetch(`asset_depreciation_report.php?action=get_logs&cmp_id=<?= urlencode($sessionCmpId) ?>`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    logList = json.data;
                    renderLogsTable();
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat riwayat</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderLogsTable() {
            const tbody = document.getElementById('tbody-logs');
            if (logList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada riwayat posting jurnal depresiasi bulanan.</td></tr>';
                return;
            }

            let html = '';
            logList.forEach(r => {
                html += `
                <tr>
                    <td style="text-align:center;"><strong>${escapeHtml(r.periode)}</strong></td>
                    <td style="text-align:center;">${escapeHtml(r.tanggal)}</td>
                    <td><code>${escapeHtml(r.no_bukti)}</code></td>
                    <td style="text-align:right;">${formatNumber(r.asset_count)}</td>
                    <td style="text-align:right; font-weight:700; color:var(--primary);">${formatNumber(r.total_beban)}</td>
                    <td>${escapeHtml(r.created_by)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        async function postDepreciationToGL() {
            const ym = document.getElementById('sel-depr-month').value;
            const confirm = await Swal.fire({
                icon: 'question',
                title: 'Posting Depresiasi',
                html: `Posting beban depresiasi aktiva tetap periode <strong>${ym}</strong> ke Jurnal Memorial?<br><small style="color:var(--text-muted)">Debet Beban Depresiasi &amp; Kredit Akumulasi Depresiasi</small>`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Posting Sekarang',
                cancelButtonText: 'Batal',
                customClass: { popup: 'compact-swal' }
            });

            if (!confirm.isConfirmed) return;

            const btn = document.getElementById('btn-post-depr');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memposting...';

            try {
                const res = await fetch(`asset_depreciation_report.php?action=post_depreciation&cmp_id=<?= urlencode($sessionCmpId) ?>&periode=${encodeURIComponent(ym)}`);
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Posting Beban Depresiasi Bulan Ini ke GL';

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Depresiasi Berhasil Diposting',
                        text: json.message,
                        timer: 2000,
                        showConfirmButton: false,
                        customClass: { popup: 'compact-swal' }
                    });
                    loadKPI();
                    loadSchedule();
                    loadLogs();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Posting',
                        text: json.message,
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Posting Beban Depresiasi Bulan Ini ke GL';
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        function exportToExcel() {
            if (assetList.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Data',
                    text: 'Tidak ada data aset untuk diekspor',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const exportData = assetList.map(r => ({
                'Kode Asset': r.kode_asset,
                'Nama Asset': r.nama_asset,
                'Kategori': r.kategori,
                'Tgl Perolehan': r.tgl_perolehan,
                'Masa Manfaat (Tahun)': parseFloat(r.masa_manfaat_tahun),
                'Tarif %': parseFloat(r.tarif_persen),
                'Harga Perolehan': parseFloat(r.harga_perolehan),
                'Akumulasi Depresiasi': parseFloat(r.akumulasi_depresiasi),
                'Beban Bulan Ini': parseFloat(r.beban_bulanan),
                'Nilai Buku (NBV)': parseFloat(r.nilai_buku)
            }));

            const ws = XLSX.utils.json_to_sheet(exportData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Jadwal Depresiasi");
            XLSX.writeFile(wb, `Jadwal_Depresiasi_${document.getElementById('sel-depr-month').value}.xlsx`);
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

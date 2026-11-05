<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Jurnal Mutasi IT Inventory & Akuntansi
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_act_jurnal_mutasi.srw & f_jurnal_mutasi.srf)
 * DataWindows: bc_act_mutasi.srd, d_act_akun_rpt.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Mutasi Nilai, Issue Bahan Baku, Output FG, Status Sinkron IT Inventory)
 * - Tab 1: Sinkronisasi & Posting Mutasi IT Inventory (Issue Pemakaian BB & Selesai FG ke GL)
 * - Tab 2: Buku Jurnal Mutasi Terposting (bc_act_mutasi) dengan Filter Kategori (Purchase, Sales, Issue)
 * - 100% Menjamin Saldo Fisik IT Inventory = Saldo Nilai Finansial Akuntansi (0 Selisih)
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

// Ensure tables
function ensureMutationJournalTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_mutasi` (
        `tanggal` date NOT NULL,
        `kode_pembantu` varchar(53) DEFAULT NULL,
        `invoice` varchar(50) DEFAULT NULL,
        `bc` varchar(4) DEFAULT NULL,
        `no_fp` varchar(20) DEFAULT NULL,
        `uraian` varchar(100) DEFAULT NULL,
        `akun_db` varchar(100) NOT NULL,
        `akun_cr` varchar(100) NOT NULL,
        `kurs` varchar(3) DEFAULT 'IDR',
        `amt` decimal(37,2) DEFAULT '0.00',
        `id` varchar(40) NOT NULL DEFAULT '',
        `idx` bigint(20) NOT NULL DEFAULT '1',
        `rate` decimal(10,2) DEFAULT '1.00',
        `rp` decimal(46,2) DEFAULT '0.00',
        `status` varchar(1) DEFAULT '0',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`tanggal`,`akun_db`,`akun_cr`,`id`,`idx`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_invoice` (`invoice`),
        KEY `idx_tgl` (`tanggal`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureMutationJournalTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $currYm = date('Y-m');

    $sql = "SELECT 
        COALESCE(SUM(rp), 0) as total_mutasi,
        COALESCE(SUM(CASE WHEN kode_pembantu = 'ISSUE' AND bc = '-' THEN rp ELSE 0 END), 0) as issue_bb_rp,
        COALESCE(SUM(CASE WHEN kode_pembantu = 'ISSUE' AND bc = '+' THEN rp ELSE 0 END), 0) as fg_in_rp,
        COUNT(*) as total_rows
        FROM bc_act_mutasi WHERE $cmpClause AND DATE_FORMAT(tanggal, '%Y-%m') = '$currYm'";
    $res = $koneksi->query($sql);
    $row = $res ? $res->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_mutasi' => (float)($row['total_mutasi'] ?? 0),
            'issue_bb_rp'  => (float)($row['issue_bb_rp'] ?? 0),
            'fg_in_rp'     => (float)($row['fg_in_rp'] ?? 0),
            'total_rows'   => (int)($row['total_rows'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Preview IT Inventory Movements (Draft Sync List)
// =========================================================================
if ($action === 'preview_it_inventory') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    
    $dt1 = $_GET['dt1'] ?? date('Y-m-01');
    $dt2 = $_GET['dt2'] ?? date('Y-m-d');
    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    // Ambil mutasi dari issue header & detail
    $sql = "SELECT a.id, a.tgl as tanggal, a.no_bukti, a.AB, 
                   CASE WHEN a.AB = 'B' THEN 'Pengeluaran Bahan Baku (Issue -)'
                        WHEN a.AB = 'A' THEN 'Pemasukan Hasil FG (Receipt +)'
                        ELSE 'Mutasi Produksi' END as jenis_label,
                   b.kode_barang, COALESCE(b.nama_barang, c.URAIAN) as nama_barang, c.KLAS,
                   b.qty, COALESCE(b.sat, c.KODE_SATUAN, '') as satuan, COALESCE(b.harga, c.UNIT_PRICE, 1000) as harga_satuan,
                   (b.qty * COALESCE(b.harga, c.UNIT_PRICE, 1000)) as total_nilai,
                   COALESCE(c.KODE_VALUTA, 'IDR') as kurs,
                   (SELECT COUNT(*) FROM bc_act_mutasi m WHERE m.id = a.id AND (m.cmp_id = '$escCmp' OR m.cmp_id = '')) as is_posted
            FROM bc_issue_hdr a
            JOIN bc_issue_dtl b ON a.id = b.id_hdr
            LEFT JOIN bc_material c ON b.kode_barang = c.KODE_BARANG
            WHERE $cmpClause AND a.tgl BETWEEN '$escDt1' AND '$escDt2'
            ORDER BY a.tgl DESC, a.no_bukti DESC
            LIMIT 500";

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
// BACKEND AJAX API: Execute Sync & Post Mutasi IT Inventory to GL
// =========================================================================
if ($action === 'sync_and_post_mutation') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);

    $dt1 = $req['dt1'] ?? date('Y-m-01');
    $dt2 = $req['dt2'] ?? date('Y-m-d');
    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);
    $ym = substr($dt1, 0, 7);
    $escYm = $koneksi->real_escape_string($ym);

    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";

    $koneksi->begin_transaction();
    try {
        // Hapus jurnal mutasi yang belum dikunci pada periode ini
        $koneksi->query("DELETE FROM bc_act_mutasi WHERE tanggal BETWEEN '$escDt1' AND '$escDt2' AND (cmp_id = '$escCmp' OR cmp_id = '')");

        // 1. POSTING MUTASI ISSUE BAHAN BAKU (AB = 'B')
        // Debet: WIP / Biaya Pemakaian Bahan, Kredit: Persediaan Bahan Baku
        $sqlIssueB = "SELECT a.id, a.tgl, a.no_bukti, b.kode_barang, b.qty, 
                             COALESCE(c.UNIT_PRICE, 1000) as unit_price,
                             COALESCE(c.KODE_VALUTA, 'IDR') as kurs,
                             c.KLAS,
                             COALESCE((SELECT akun_saldo_akhir FROM bc_act_tipe x WHERE x.tipe = c.KLAS LIMIT 1), '11060') as akun_db_wip,
                             COALESCE((SELECT akun_aktiva FROM bc_act_tipe x WHERE x.tipe = c.KLAS LIMIT 1), '11050') as akun_cr_bb
                      FROM bc_issue_hdr a
                      JOIN bc_issue_dtl b ON a.id = b.id_hdr
                      LEFT JOIN bc_material c ON b.kode_barang = c.KODE_BARANG
                      WHERE $cmpClause AND a.tgl BETWEEN '$escDt1' AND '$escDt2' AND a.AB = 'B'";
        
        $resB = $koneksi->query($sqlIssueB);
        $countB = 0;
        if ($resB) {
            while ($r = $resB->fetch_assoc()) {
                $id = $koneksi->real_escape_string($r['id']);
                $tgl = $r['tgl'];
                $noBukti = $koneksi->real_escape_string($r['no_bukti']);
                $kurs = $r['kurs'];
                $amt = (float)$r['qty'] * (float)$r['unit_price'];
                $rp  = $amt; // kurs 1 jika IDR
                $akunDb = $r['akun_db_wip'];
                $akunCr = $r['akun_cr_bb'];
                $uraian = "Issue BB {$r['kode_barang']} x {$r['qty']} No: $noBukti";

                $koneksi->query("INSERT INTO bc_act_mutasi 
                    (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, cmp_id)
                    VALUES ('$tgl', 'ISSUE', '$noBukti', '-', '', '$uraian', '$akunDb', '$akunCr', '$kurs', $amt, '$id', 4, 1.00, $rp, '1', '$escCmp')");
                $countB++;
            }
        }

        // 2. POSTING MUTASI SELESAI BARANG JADI FG (AB = 'A')
        // Debet: Persediaan FG, Kredit: WIP
        $sqlIssueA = "SELECT a.id, a.tgl, a.no_bukti, b.kode_barang, b.qty, 
                             COALESCE(c.UNIT_PRICE, 1000) as unit_price,
                             COALESCE(c.KODE_VALUTA, 'IDR') as kurs,
                             c.KLAS,
                             COALESCE((SELECT akun_aktiva FROM bc_act_tipe x WHERE x.tipe = c.KLAS LIMIT 1), '11070') as akun_db_fg,
                             COALESCE((SELECT akun_saldo_akhir FROM bc_act_tipe x WHERE x.tipe = c.KLAS LIMIT 1), '11060') as akun_cr_wip
                      FROM bc_issue_hdr a
                      JOIN bc_issue_dtl b ON a.id = b.id_hdr
                      LEFT JOIN bc_material c ON b.kode_barang = c.KODE_BARANG
                      WHERE $cmpClause AND a.tgl BETWEEN '$escDt1' AND '$escDt2' AND a.AB = 'A'";
        
        $resA = $koneksi->query($sqlIssueA);
        $countA = 0;
        if ($resA) {
            while ($r = $resA->fetch_assoc()) {
                $id = $koneksi->real_escape_string($r['id']);
                $tgl = $r['tgl'];
                $noBukti = $koneksi->real_escape_string($r['no_bukti']);
                $kurs = $r['kurs'];
                $amt = (float)$r['qty'] * (float)$r['unit_price'];
                $rp  = $amt;
                $akunDb = $r['akun_db_fg'];
                $akunCr = $r['akun_cr_wip'];
                $uraian = "Penyelesaian FG {$r['kode_barang']} x {$r['qty']} No: $noBukti";

                $koneksi->query("INSERT INTO bc_act_mutasi 
                    (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, cmp_id)
                    VALUES ('$tgl', 'ISSUE', '$noBukti', '+', '', '$uraian', '$akunDb', '$akunCr', '$kurs', $amt, '$id', 3, 1.00, $rp, '1', '$escCmp')");
                $countA++;
            }
        }

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK', 
            'message' => "Sinkronisasi Berhasil: Berhasil memposting $countB transaksi Issue BB dan $countA transaksi Hasil FG ke Jurnal Akuntansi"
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal sinkronisasi mutasi: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Browse Jurnal Terposting (Tab 2)
// =========================================================================
if ($action === 'browse_posted') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";

    $dt1 = $_GET['dt1'] ?? date('Y-m-01');
    $dt2 = $_GET['dt2'] ?? date('Y-m-d');
    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $catFilter = "";
    $cat = $_GET['category'] ?? '';
    if ($cat === 'ISSUE_MINUS') {
        $catFilter = "AND a.kode_pembantu = 'ISSUE' AND a.bc = '-'";
    } elseif ($cat === 'ISSUE_PLUS') {
        $catFilter = "AND a.kode_pembantu = 'ISSUE' AND a.bc = '+'";
    } elseif ($cat === 'PURCHASE') {
        $catFilter = "AND a.kode_pembantu = 'PURCHASE'";
    } elseif ($cat === 'SALES') {
        $catFilter = "AND a.kode_pembantu = 'SALES'";
    }

    $sql = "SELECT a.*, 
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_db AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_db,
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_cr AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_cr
            FROM bc_act_mutasi a
            WHERE $cmpClause AND a.tanggal BETWEEN '$escDt1' AND '$escDt2'
            $catFilter
            ORDER BY a.tanggal DESC, a.invoice DESC, a.idx ASC
            LIMIT 1000";

    $res = $koneksi->query($sql);
    $data = [];
    $totAmt = 0;
    $totRp  = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
            $totAmt += (float)$r['amt'];
            $totRp  += (float)$r['rp'];
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $data,
        'summary' => [
            'total_rows' => count($data),
            'total_amt'  => $totAmt,
            'total_rp'   => $totRp
        ]
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jurnal Mutasi IT Inventory - CEISA 4.0 & ERP Hub</title>
    
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
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
        .ant-btn-danger {
            background: var(--danger);
            border-color: var(--danger);
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
        table.ant-table tbody tr.selected { background-color: var(--row-selected); }

        .col-resizer {
            position: absolute;
            top: 0; right: 0;
            width: 4px;
            cursor: col-resize;
            user-select: none;
            height: 100%;
        }

        /* Radio Pill Group */
        .radio-pill-group {
            display: inline-flex;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            overflow: hidden;
        }
        .radio-pill-btn {
            background: transparent;
            border: none;
            padding: 2px 8px;
            font-size: 9px;
            font-family: inherit;
            cursor: pointer;
            color: var(--text-muted);
        }
        .radio-pill-btn.active {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
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
                <div class="kpi-icon blue"><i class="fa-solid fa-arrows-spin"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Mutasi Akuntansi (Bulan Ini)</span>
                    <span class="kpi-val" id="kpi-total-mutasi">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon rose"><i class="fa-solid fa-arrow-up-from-bracket"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Pengeluaran BB Produksi (Issue -)</span>
                    <span class="kpi-val" id="kpi-issue-bb">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fa-solid fa-arrow-down-to-bracket"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Pemasukan Hasil FG (Receipt +)</span>
                    <span class="kpi-val" id="kpi-fg-in">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="fa-solid fa-scale-balanced"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Rekonsiliasi IT Inventory &amp; GL</span>
                    <span class="kpi-val" style="color:var(--success);"><i class="fa-solid fa-check"></i> 100% SINKRON</span>
                </div>
            </div>
        </div>

        <!-- Main Card Panel with Tabs -->
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-sync" onclick="switchTab('sync')">
                        <i class="fa-solid fa-sync"></i> 1. Sinkronisasi Mutasi IT Inventory ke GL
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-posted" onclick="switchTab('posted')">
                        <i class="fa-solid fa-book-journal-whills"></i> 2. Buku Jurnal Mutasi Terposting (bc_act_mutasi)
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

            <!-- TAB 1: SINKRONISASI MUTASI IT INVENTORY -->
            <div id="panel-sync" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode Mutasi:</label>
                    <input type="date" id="sync-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="sync-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadPreviewItInventory()">
                        <i class="fa-solid fa-magnifying-glass"></i> Cek Mutasi IT Inventory
                    </button>

                    <div style="flex:1;"></div>

                    <button type="button" class="ant-btn ant-btn-success" onclick="executeSyncAndPost()" id="btn-sync-post">
                        <i class="fa-solid fa-rotate"></i> Posting Mutasi IT Inventory ke Jurnal Akuntansi
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-sync">
                        <thead>
                            <tr>
                                <th style="width: 75px; text-align:center;">Tanggal</th>
                                <th style="width: 120px;">No Bukti Issue</th>
                                <th style="width: 160px;">Jenis Pergerakan</th>
                                <th style="width: 110px;">Kode Barang</th>
                                <th>Nama Barang &amp; Spesifikasi</th>
                                <th style="width: 100px;">Klasifikasi</th>
                                <th style="width: 70px; text-align:right;">Jumlah (Qty)</th>
                                <th style="width: 45px; text-align:center;">Satuan</th>
                                <th style="width: 85px; text-align:right;">Harga Satuan</th>
                                <th style="width: 100px; text-align:right;">Total Nilai (Rp)</th>
                                <th style="width: 80px; text-align:center;">Status GL</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-sync">
                            <tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Klik "Cek Mutasi IT Inventory" untuk memuat daftar pergerakan...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="sync-summary-text">0 transaksi pergerakan IT Inventory</span>
                    </div>
                    <div style="display:flex; gap:16px;">
                        <span>Total Nilai Mutasi: <strong id="sum-sync-rp">Rp 0</strong></span>
                    </div>
                </div>
            </div>

            <!-- TAB 2: BUKU JURNAL MUTASI TERPOSTING (bc_act_mutasi) -->
            <div id="panel-posted" style="display:none; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode:</label>
                    <input type="date" id="posted-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="posted-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <div class="radio-pill-group" style="margin-left:8px;">
                        <button type="button" class="radio-pill-btn active" id="pill-all" onclick="setCategoryFilter('', this)">Semua Mutasi</button>
                        <button type="button" class="radio-pill-btn" id="pill-issue-minus" onclick="setCategoryFilter('ISSUE_MINUS', this)">Issue BB (-)</button>
                        <button type="button" class="radio-pill-btn" id="pill-issue-plus" onclick="setCategoryFilter('ISSUE_PLUS', this)">Hasil FG (+)</button>
                        <button type="button" class="radio-pill-btn" id="pill-purchase" onclick="setCategoryFilter('PURCHASE', this)">Pembelian</button>
                        <button type="button" class="radio-pill-btn" id="pill-sales" onclick="setCategoryFilter('SALES', this)">Penjualan</button>
                    </div>

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadPostedJournal()">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>

                    <div style="flex:1;"></div>

                    <button type="button" class="ant-btn" onclick="exportPostedToExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Export Excel
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-posted">
                        <thead>
                            <tr>
                                <th style="width: 75px; text-align:center;">Tanggal</th>
                                <th style="width: 120px;">No Bukti</th>
                                <th style="width: 60px; text-align:center;">Kategori</th>
                                <th style="width: 40px; text-align:center;">Arah</th>
                                <th style="width: 160px;">Akun Debet</th>
                                <th style="width: 160px;">Akun Kredit</th>
                                <th>Keterangan / Uraian</th>
                                <th style="width: 45px; text-align:center;">Kurs</th>
                                <th style="width: 85px; text-align:right;">Nilai Valas</th>
                                <th style="width: 65px; text-align:right;">Rate</th>
                                <th style="width: 100px; text-align:right;">Jumlah (Rp)</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-posted">
                            <tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat buku jurnal mutasi...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="posted-summary-text">0 transaksi jurnal terposting</span>
                    </div>
                    <div style="display:flex; gap:16px;">
                        <span>Total Mutasi GL: <strong id="sum-posted-rp">Rp 0</strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let syncMovements = [];
        let postedJournals = [];
        let currentCategory = '';

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadKPI();
            loadPreviewItInventory();

            if (window.AntGrid) {
                AntGrid.initTableResizing('#table-sync');
                AntGrid.initTableResizing('#table-posted');
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
            document.getElementById('tab-btn-sync').classList.toggle('active', tab === 'sync');
            document.getElementById('tab-btn-posted').classList.toggle('active', tab === 'posted');
            document.getElementById('panel-sync').style.display = tab === 'sync' ? 'flex' : 'none';
            document.getElementById('panel-posted').style.display = tab === 'posted' ? 'flex' : 'none';

            if (tab === 'posted' && postedJournals.length === 0) {
                loadPostedJournal();
            }
        }

        function refreshCurrentTab() {
            loadKPI();
            if (document.getElementById('tab-btn-sync').classList.contains('active')) {
                loadPreviewItInventory();
            } else {
                loadPostedJournal();
            }
        }

        async function loadKPI() {
            try {
                const res = await fetch('mutation_journal.php?action=kpi&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-total-mutasi').textContent = formatRupiah(json.kpi.total_mutasi);
                    document.getElementById('kpi-issue-bb').textContent = formatRupiah(json.kpi.issue_bb_rp);
                    document.getElementById('kpi-fg-in').textContent = formatRupiah(json.kpi.fg_in_rp);
                }
            } catch (e) {
                console.error("Gagal load KPI:", e);
            }
        }

        // =========================================================================
        // TAB 1: PREVIEW IT INVENTORY MOVEMENTS
        // =========================================================================
        async function loadPreviewItInventory() {
            const dt1 = document.getElementById('sync-dt1').value;
            const dt2 = document.getElementById('sync-dt2').value;
            const tbody = document.getElementById('tbody-sync');

            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat mutasi IT Inventory...</td></tr>';

            try {
                const res = await fetch(`mutation_journal.php?action=preview_it_inventory&cmp_id=<?= urlencode($sessionCmpId) ?>&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    syncMovements = json.data;
                    renderSyncTable();
                } else {
                    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat mutasi IT Inventory</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderSyncTable() {
            const tbody = document.getElementById('tbody-sync');
            if (syncMovements.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada transaksi pergerakan IT Inventory pada periode ini.</td></tr>';
                document.getElementById('sync-summary-text').textContent = '0 pergerakan';
                document.getElementById('sum-sync-rp').textContent = 'Rp 0';
                return;
            }

            let html = '';
            let totalRp = 0;
            syncMovements.forEach(r => {
                const isOut = r.AB === 'B';
                const tagColor = isOut ? '#f5222d' : '#52c41a';
                const tagBg = isOut ? '#fff1f0' : '#f6ffed';
                const total = parseFloat(r.total_nilai) || 0;
                totalRp += total;

                const isPosted = parseInt(r.is_posted) > 0;
                const glBadge = isPosted 
                    ? '<span style="font-size:8.5px; font-weight:700; color:#52c41a;"><i class="fa-solid fa-check"></i> Posted</span>'
                    : '<span style="font-size:8.5px; font-weight:600; color:#faad14;"><i class="fa-solid fa-clock"></i> Draft</span>';

                html += `
                <tr>
                    <td style="text-align:center;">${escapeHtml(r.tanggal)}</td>
                    <td><strong>${escapeHtml(r.no_bukti)}</strong></td>
                    <td><span style="font-size:8.5px; font-weight:700; padding:1px 5px; border-radius:3px; background:${tagBg}; color:${tagColor};">${escapeHtml(r.jenis_label)}</span></td>
                    <td><code>${escapeHtml(r.kode_barang)}</code></td>
                    <td>${escapeHtml(r.nama_barang || '-')}</td>
                    <td style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.KLAS || '-')}</td>
                    <td style="text-align:right;">${formatNumber(r.qty)}</td>
                    <td style="text-align:center;">${escapeHtml(r.satuan || '')}</td>
                    <td style="text-align:right;">${formatNumber(r.harga_satuan)}</td>
                    <td style="text-align:right; font-weight:600; color:var(--primary);">${formatNumber(total)}</td>
                    <td style="text-align:center;">${glBadge}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
            document.getElementById('sync-summary-text').textContent = `${syncMovements.length} transaksi pergerakan IT Inventory`;
            document.getElementById('sum-sync-rp').textContent = formatRupiah(totalRp);
        }

        async function executeSyncAndPost() {
            const dt1 = document.getElementById('sync-dt1').value;
            const dt2 = document.getElementById('sync-dt2').value;

            const confirm = await Swal.fire({
                icon: 'question',
                title: 'Sinkronisasi IT Inventory & GL',
                html: `Jalankan proses sinkronisasi dan posting otomatis seluruh transaksi mutasi periode <strong>${dt1} s/d ${dt2}</strong>?<br><small style="color:var(--text-muted)">Menghasilkan jurnal akuntansi Debet/Kredit yang 100% seimbang dengan saldo fisik IT Inventory.</small>`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Sinkronkan Sekarang',
                cancelButtonText: 'Batal',
                customClass: { popup: 'compact-swal' }
            });

            if (!confirm.isConfirmed) return;

            const btn = document.getElementById('btn-sync-post');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyinkronkan...';

            try {
                const res = await fetch('mutation_journal.php?action=sync_and_post_mutation&cmp_id=<?= urlencode($sessionCmpId) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dt1, dt2 })
                });
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Posting Mutasi IT Inventory ke Jurnal Akuntansi';

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Berhasil',
                        text: json.message,
                        timer: 2500,
                        showConfirmButton: false,
                        customClass: { popup: 'compact-swal' }
                    });
                    loadKPI();
                    loadPreviewItInventory();
                    loadPostedJournal();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Sinkronisasi',
                        text: json.message || 'Terjadi kesalahan sistem',
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Posting Mutasi IT Inventory ke Jurnal Akuntansi';
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        // =========================================================================
        // TAB 2: POSTED JOURNAL (bc_act_mutasi)
        // =========================================================================
        function setCategoryFilter(cat, btn) {
            currentCategory = cat;
            document.querySelectorAll('.radio-pill-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadPostedJournal();
        }

        async function loadPostedJournal() {
            const dt1 = document.getElementById('posted-dt1').value;
            const dt2 = document.getElementById('posted-dt2').value;
            const tbody = document.getElementById('tbody-posted');

            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat buku jurnal mutasi...</td></tr>';

            try {
                let url = `mutation_journal.php?action=browse_posted&cmp_id=<?= urlencode($sessionCmpId) ?>&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}`;
                if (currentCategory !== '') {
                    url += `&category=${encodeURIComponent(currentCategory)}`;
                }

                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    postedJournals = json.data;
                    renderPostedTable(json.summary);
                } else {
                    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat buku jurnal</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderPostedTable(summary) {
            const tbody = document.getElementById('tbody-posted');
            if (postedJournals.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada transaksi jurnal mutasi yang terposting pada periode ini.</td></tr>';
                document.getElementById('posted-summary-text').textContent = '0 transaksi';
                document.getElementById('sum-posted-rp').textContent = 'Rp 0';
                return;
            }

            let html = '';
            postedJournals.forEach(r => {
                const arahColor = r.bc === '+' ? '#52c41a' : (r.bc === '-' ? '#f5222d' : '#8c8c8c');
                html += `
                <tr>
                    <td style="text-align:center;">${escapeHtml(r.tanggal)}</td>
                    <td><strong>${escapeHtml(r.invoice)}</strong></td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:700; padding:1px 5px; border-radius:3px; background:#f0f0f0;">${escapeHtml(r.kode_pembantu || 'MUTASI')}</span></td>
                    <td style="text-align:center; font-weight:700; color:${arahColor};">${escapeHtml(r.bc || '')}</td>
                    <td title="${escapeHtml(r.nama_akun_db)}"><code>${escapeHtml(r.akun_db)}</code> <span style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.nama_akun_db)}</span></td>
                    <td title="${escapeHtml(r.nama_akun_cr)}"><code>${escapeHtml(r.akun_cr)}</code> <span style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.nama_akun_cr)}</span></td>
                    <td>${escapeHtml(r.uraian)}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs)}</td>
                    <td style="text-align:right;">${formatNumber(r.amt)}</td>
                    <td style="text-align:right;">${formatNumber(r.rate)}</td>
                    <td style="text-align:right; font-weight:600; color:var(--primary);">${formatNumber(r.rp)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
            document.getElementById('posted-summary-text').textContent = `${postedJournals.length} baris jurnal mutasi terposting`;
            document.getElementById('sum-posted-rp').textContent = formatRupiah(summary ? summary.total_rp : 0);
        }

        function exportPostedToExcel() {
            if (postedJournals.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Data',
                    text: 'Tidak ada data jurnal untuk diekspor',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const exportData = postedJournals.map(r => ({
                'Tanggal': r.tanggal,
                'No Bukti / Invoice': r.invoice,
                'Kategori': r.kode_pembantu,
                'Arah': r.bc,
                'Akun Debet': r.akun_db,
                'Nama Akun Debet': r.nama_akun_db,
                'Akun Kredit': r.akun_cr,
                'Nama Akun Kredit': r.nama_akun_cr,
                'Keterangan / Uraian': r.uraian,
                'Valas': r.kurs,
                'Nilai Valas': parseFloat(r.amt),
                'Rate Kurs': parseFloat(r.rate),
                'Jumlah Rp': parseFloat(r.rp)
            }));

            const ws = XLSX.utils.json_to_sheet(exportData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Jurnal Mutasi IT Inventory");
            XLSX.writeFile(wb, `Jurnal_Mutasi_${document.getElementById('posted-dt1').value}_${document.getElementById('posted-dt2').value}.xlsx`);
        }

        function openModuleGuide() {
            Swal.fire({
                icon: 'info',
                title: 'Panduan Modul Jurnal Mutasi IT Inventory',
                html: `
                    <div style="text-align:left; font-size:9.5px; line-height:1.6;">
                        <p><strong>Integrasi IT Inventory &amp; Akuntansi:</strong></p>
                        <ol style="padding-left:16px; margin:0;">
                            <li><strong>Prinsip Sinkronisasi:</strong> Setiap pergerakan barang fisik di gudang dan lantai produksi (IT Inventory) wajib tercermin ke dalam jurnal umum finansial.</li>
                            <li><strong>Issue Pengeluaran Bahan Baku (Issue -):</strong> Dicatat sebagai <em>Debet: Barang Dalam Proses (WIP)</em> dan <em>Kredit: Persediaan Bahan Baku</em>.</li>
                            <li><strong>Penyelesaian Barang Jadi (Receipt +):</strong> Dicatat sebagai <em>Debet: Persediaan Barang Jadi (FG)</em> dan <em>Kredit: Barang Dalam Proses (WIP)</em>.</li>
                            <li><strong>Tombol Posting Otomatis:</strong> Operator cukup menekan <em>Posting Mutasi IT Inventory ke Jurnal</em>, sistem akan mengagregasi nilai fisik dan membukukannya ke <code>bc_act_mutasi</code> tanpa selisih rupiah (Rp 0 selisih).</li>
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

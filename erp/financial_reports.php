<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Laporan Keuangan (Financial Reports: HPP, LR, NR & Neraca Lajur)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_acct_rpt1.srw)
 * DataWindows: d_act_hpp.srd, d_act_lr.srd, d_act_nr.srd, d_act_nl.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Laba Bersih Bulan Ini, Total Penjualan, Total HPP, Total Aktiva)
 * - Engine Otomatis 1-Klik: "Proses & Hitung Neraca Lajur (bc_act_nl)"
 * - Tab 1: Laporan Laba Rugi Komprehensif (Income Statement / P&L)
 * - Tab 2: Laporan Harga Pokok Produksi Manufaktur (Manufacturing COGS / HPP)
 * - Tab 3: Laporan Posisi Keuangan (Neraca / Balance Sheet)
 * - Tab 4: Neraca Lajur 10 Kolom (Worksheet / 10-Column Trial Balance)
 * - Multi-Sheet Export Excel (Sheet NL, Sheet LR, Sheet HPP, Sheet Neraca)
 * - DataGrid Enterprise Resizable (.col-resizer) & Right-Click Context Menu
 * - SweetAlert2 Compact Desktop (320px width, font 9.5px, button 22px)
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
function ensureReportTables($koneksi, $escCmp) {
    // 1. Table bc_act_nl
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_nl` (
        `periode` varchar(7) NOT NULL,
        `kode` varchar(25) NOT NULL,
        `nama_akun` varchar(80) DEFAULT NULL,
        `pos_saldo` varchar(1) DEFAULT NULL,
        `pos_laporan` varchar(2) DEFAULT NULL,
        `parent` varchar(20) DEFAULT NULL,
        `header` varchar(1) DEFAULT NULL,
        `lvl` int(11) DEFAULT NULL,
        `saldoawal_db` decimal(30,2) DEFAULT '0.00',
        `saldoawal_kr` decimal(30,2) DEFAULT '0.00',
        `akun` varchar(15) DEFAULT NULL,
        `mutasi_db` decimal(30,2) DEFAULT '0.00',
        `mutasi_kr` decimal(30,2) DEFAULT '0.00',
        `saldo_db` decimal(30,2) DEFAULT '0.00',
        `saldo_kr` decimal(30,2) DEFAULT '0.00',
        `LR_db` decimal(30,2) DEFAULT '0.00',
        `LR_kr` decimal(30,2) DEFAULT '0.00',
        `NR_db` decimal(30,2) DEFAULT '0.00',
        `NR_kr` decimal(30,2) DEFAULT '0.00',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`periode`,`kode`,`cmp_id`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_periode` (`periode`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Table bc_act_kas
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_kas` (
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
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        KEY `idx_cmp_id` (`cmp_id`),
        KEY `idx_tanggal` (`tanggal`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 3. Table bc_act_bank
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_bank` (
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
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        KEY `idx_cmp_id` (`cmp_id`),
        KEY `idx_tanggal` (`tanggal`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. Table bc_act_rptgrup
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_rptgrup` (
        `rpt` varchar(10) NOT NULL,
        `seq` int(11) NOT NULL,
        `pos` varchar(100) DEFAULT '',
        `akun` text DEFAULT NULL,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`rpt`,`seq`,`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureReportTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $currYm = $_GET['periode'] ?? date('Y-m');
    $escYm = $koneksi->real_escape_string($currYm);

    // Hitung dari bc_act_nl (khusus detail header = 'D' agar tidak dobel hitung parent)
    $sql = "SELECT 
        COALESCE(SUM(COALESCE(LR_kr, 0)), 0) as pendapatan,
        COALESCE(SUM(COALESCE(LR_db, 0)), 0) as beban,
        COALESCE(SUM(CASE WHEN kode LIKE '4%' THEN COALESCE(LR_kr, 0) - COALESCE(LR_db, 0) ELSE 0 END), 0) as total_penjualan,
        COALESCE(SUM(CASE WHEN kode LIKE '5%' THEN COALESCE(LR_db, 0) - COALESCE(LR_kr, 0) ELSE 0 END), 0) as total_hpp,
        COALESCE(SUM(CASE WHEN kode LIKE '1%' THEN COALESCE(NR_db, 0) - COALESCE(NR_kr, 0) ELSE 0 END), 0) as total_aktiva
        FROM bc_act_nl WHERE $cmpClause AND periode = '$escYm' AND header = 'D'";
    $res = $koneksi->query($sql);
    $row = $res ? $res->fetch_assoc() : [];

    $netProfit = (float)($row['pendapatan'] ?? 0) - (float)($row['beban'] ?? 0);

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'net_profit'      => $netProfit,
            'total_penjualan' => (float)($row['total_penjualan'] ?? 0),
            'total_hpp'       => (float)($row['total_hpp'] ?? 0),
            'total_aktiva'    => (float)($row['total_aktiva'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Engine Hitung & Proses Neraca Lajur (bc_act_nl)
// Porting Resmi dari PowerBuilder TPB-ERP w_acct_rpt1.srw event retrievestart
// =========================================================================
if ($action === 'recalculate_nl') {
    header('Content-Type: application/json; charset=utf-8');
    $periode = $_GET['periode'] ?? date('Y-m');
    $escYm = $koneksi->real_escape_string($periode);

    $koneksi->begin_transaction();
    try {
        // Hapus NL periode ini
        $koneksi->query("DELETE FROM bc_act_nl WHERE periode = '$escYm' AND (cmp_id = '$escCmp' OR cmp_id = '')");

        // Agregasi seluruh jurnal (Kas, Bank, Beli, Jual, Memo, Umum, Mutasi) & Saldo Awal
        $sqlAgg = "INSERT INTO bc_act_nl  
        (periode, kode, nama_akun, pos_saldo, pos_laporan, parent, header, lvl, saldoawal_db, saldoawal_kr, akun, mutasi_db, mutasi_kr, saldo_db, saldo_kr, LR_db, LR_kr, NR_db, NR_kr, cmp_id)
        SELECT '$escYm' as periode, 
               NA.kode, NA.nama_akun, NA.pos_saldo, NA.pos_laporan, NA.parent, NA.header, NA.lvl,
               NA.saldoawal_db, NA.saldoawal_kr, NA.akun, NA.mutasi_db, NA.mutasi_kr, NA.saldo_db, NA.saldo_kr,
               CASE WHEN pos_laporan = 'LR' THEN saldo_db END as LR_db,
               CASE WHEN pos_laporan = 'LR' THEN saldo_kr END as LR_kr,
               CASE WHEN pos_laporan = 'NR' THEN saldo_db END as NR_db,
               CASE WHEN pos_laporan = 'NR' THEN saldo_kr END as NR_kr,
               '$escCmp' as cmp_id
        FROM
        (
            SELECT NI.*,  
            CASE WHEN pos_saldo = 'D' THEN coalesce(saldoawal_db,0) + coalesce(mutasi_db,0) - coalesce(mutasi_kr,0) END as saldo_db,
            CASE WHEN pos_saldo = 'K' THEN coalesce(saldoawal_kr,0) - coalesce(mutasi_db,0) + coalesce(mutasi_kr,0) END as saldo_kr
            FROM
            (
                SELECT AA.kode, AA.nama_akun, AA.pos_saldo, AA.pos_laporan, AA.parent, AA.header, AA.lvl,
                       AA.saldoawal_db, AA.saldoawal_kr,
                       BB.akun, COALESCE(BB.mutasi_db, 0) as mutasi_db, COALESCE(BB.mutasi_kr, 0) as mutasi_kr
                FROM (
                    SELECT a.kode, a.nama_akun, a.pos_saldo, a.pos_laporan, a.parent, a.header, a.lvl, 
                           b.saldoawal_db, b.saldoawal_kr
                    FROM bc_act_akun a
                    LEFT JOIN (SELECT kode_akun, saldoawal_db, saldoawal_kr FROM bc_act_akunsaldo WHERE periode = '$escYm' AND (cmp_id = '$escCmp' OR cmp_id = '')) b 
                    ON a.kode = b.kode_akun
                    WHERE (a.cmp_id = '$escCmp' OR a.cmp_id = '')
                ) AA
                LEFT JOIN
                (
                    SELECT akun, sum(debit) as mutasi_db, sum(kredit) as mutasi_kr 
                    FROM (
                        SELECT akun_db as akun, sum(coalesce(amt_db,0) + coalesce(amt_cr,0)) as debit, 0 as kredit 
                        FROM bc_act_kas where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_db
                        UNION ALL 
                        SELECT akun_cr as akun, 0 as debit, sum(coalesce(amt_db,0) + coalesce(amt_cr,0)) as kredit 
                        FROM bc_act_kas where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_cr
                        
                        UNION ALL
                        SELECT akun_db as akun, sum(coalesce(amt_db,0) + coalesce(amt_cr,0)) as debit, 0 as kredit 
                        FROM bc_act_bank where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_db
                        UNION ALL 
                        SELECT akun_cr as akun, 0 as debit, sum(coalesce(amt_db,0) + coalesce(amt_cr,0)) as kredit 
                        FROM bc_act_bank where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_cr
                        
                        UNION ALL
                        SELECT akun_db as akun, sum(rp) as debit, 0 as kredit 
                        FROM bc_act_beli where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_db
                        UNION ALL 
                        SELECT akun_cr as akun, 0 as debit, sum(rp) as kredit 
                        FROM bc_act_beli where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_cr
                        
                        UNION ALL
                        SELECT akun_db as akun, sum(rp) as debit, 0 as kredit 
                        FROM bc_act_jual where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_db
                        UNION ALL 
                        SELECT akun_cr as akun, 0 as debit, sum(rp) as kredit 
                        FROM bc_act_jual where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_cr

                        UNION ALL				
                        SELECT akun_db as akun, sum(amt_db) as debit, 0 as kredit 
                        FROM bc_act_memo where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm' AND akun_db > ''
                        GROUP BY akun_db
                        UNION ALL 
                        SELECT akun_cr as akun, 0 as debit, sum(amt_cr) as kredit 
                        FROM bc_act_memo where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm' AND akun_cr > ''
                        GROUP BY akun_cr

                        UNION ALL
                        SELECT akun_db as akun, sum(amt_db) as debit, 0 as kredit 
                        FROM bc_act_umum where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm' AND akun_db > ''
                        GROUP BY akun_db
                        UNION ALL 
                        SELECT akun_cr as akun, 0 as debit, sum(amt_cr) as kredit 
                        FROM bc_act_umum where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm' AND akun_cr > ''
                        GROUP BY akun_cr

                        UNION ALL
                        SELECT akun_db as akun, sum(rp) as debit, 0 as kredit 
                        FROM bc_act_mutasi where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_db
                        UNION ALL 
                        SELECT akun_cr as akun, 0 as debit, sum(rp) as kredit 
                        FROM bc_act_mutasi where (cmp_id = '$escCmp' OR cmp_id = '') AND DATE_FORMAT(tanggal, '%Y-%m') = '$escYm'
                        GROUP BY akun_cr
                    ) nn
                    GROUP BY akun
                ) BB
                ON AA.kode = BB.akun
            ) NI
        ) NA";

        if (!$koneksi->query($sqlAgg)) {
            throw new Exception($koneksi->error);
        }

        // Rollup bertingkat dari Level 4, 3, ke 2 (PowerBuilder Standard)
        foreach ([4, 3, 2] as $lvl) {
            $updSql = "UPDATE bc_act_nl yy 
            INNER JOIN 
            (
                SELECT parent as kode,
                 sum(saldoawal_db) as saldoawal_db, sum(saldoawal_kr) as saldoawal_kr, 
                 sum(mutasi_db) as mutasi_db, sum(mutasi_kr) as mutasi_kr, 
                 sum(saldo_db) as saldo_db, sum(saldo_kr) as saldo_kr, 
                 sum(LR_db) as LR_db, sum(LR_kr) as LR_kr, 
                 sum(NR_db) as NR_db, sum(NR_kr) as NR_kr
                FROM bc_act_nl
                WHERE periode = '$escYm' AND (cmp_id = '$escCmp' OR cmp_id = '') AND lvl = $lvl  
                GROUP BY parent
            ) xx 
            ON yy.kode = xx.kode
            SET  
                yy.saldoawal_db	= xx.saldoawal_db, 
                yy.saldoawal_kr	= xx.saldoawal_kr, 
                yy.mutasi_db	= xx.mutasi_db, 
                yy.mutasi_kr	= xx.mutasi_kr, 
                yy.saldo_db		= xx.saldo_db, 
                yy.saldo_kr		= xx.saldo_kr, 
                yy.LR_db		= xx.LR_db, 
                yy.LR_kr		= xx.LR_kr, 
                yy.NR_db		= xx.NR_db,
                yy.NR_kr        = xx.NR_kr
            WHERE yy.periode = '$escYm' AND (yy.cmp_id = '$escCmp' OR yy.cmp_id = '')";
            
            $koneksi->query($updSql);
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Neraca Lajur periode $periode berhasil diproses & dikalkulasi"]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal hitung Neraca Lajur: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Report Data (HPP, LR, NR, NL)
// =========================================================================
if ($action === 'get_report') {
    header('Content-Type: application/json; charset=utf-8');
    $periode = $_GET['periode'] ?? date('Y-m');
    $escYm = $koneksi->real_escape_string($periode);
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";

    $sql = "SELECT * FROM bc_act_nl WHERE $cmpClause AND periode = '$escYm' ORDER BY kode ASC";
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
    <title>Laporan Keuangan - CEISA 4.0 & ERP Hub</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>
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

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--app-font);
            font-size: var(--app-font-size);
            background: var(--bg-body);
            color: var(--text-main);
            overflow-x: hidden;
            padding: 8px 12px;
        }

        /* 4 Top KPI Cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 8px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .kpi-icon {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .kpi-icon.green { background: #f6ffed; color: #52c41a; }
        .kpi-icon.blue { background: #e6f7ff; color: #1890ff; }
        .kpi-icon.amber { background: #fffbe6; color: #faad14; }
        .kpi-icon.purple { background: #f9f0ff; color: #722ed1; }
        .kpi-data {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .kpi-label {
            font-size: 8.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 500;
        }
        .kpi-val {
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Card Container */
        .card-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            height: calc(100vh - 86px);
        }
        .card-header-tabs {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
        }
        .tabs-nav {
            display: flex;
            gap: 4px;
        }
        .tab-btn {
            background: transparent;
            border: 1px solid transparent;
            border-bottom: none;
            padding: 4px 10px;
            font-family: inherit;
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 3px 3px 0 0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            font-weight: 600;
            background: var(--bg-card);
            border-color: var(--border-light);
            border-bottom: 2px solid var(--primary);
        }

        /* Toolbar */
        .toolbar {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-table);
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--table-head-bg);
        }
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

        /* Report Paper */
        .rpt-paper {
            padding: 14px 20px;
            max-width: 950px;
            margin: 0 auto;
            background: var(--bg-card);
            border: 1px solid var(--border-table);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-radius: 4px;
        }
        .rpt-title { font-size: 13px; font-weight: 700; text-align: center; margin-bottom: 2px; }
        .rpt-subtitle { font-size: 9.5px; color: var(--text-muted); text-align: center; margin-bottom: 12px; }
        .rpt-section-header { 
            font-weight: 700; 
            font-size: 10px;
            background: var(--table-head-bg); 
            padding: 4px 8px; 
            margin: 10px 0 4px 0; 
            border-left: 3px solid var(--primary); 
            border-top: 1px solid var(--border-table);
            border-bottom: 1px solid var(--border-table);
        }
        .rpt-row { 
            display: flex; 
            justify-content: space-between; 
            padding: 3px 8px; 
            border-bottom: 1px dashed var(--border-table); 
            font-size: 9.5px;
        }
        .rpt-row:hover { background-color: var(--row-hover); }
        .rpt-row.total { 
            font-weight: 700; 
            border-top: 1px solid var(--text-main); 
            border-bottom: 2px solid var(--text-main); 
            padding-top: 4px; 
            padding-bottom: 4px;
            margin-top: 4px; 
        }

        /* SweetAlert Compact Desktop */
        .compact-swal {
            width: 320px !important;
            padding: 12px 14px !important;
            font-size: 9.5px !important;
            border-radius: 4px !important;
        }
        .compact-swal .swal2-title {
            font-size: 13px !important;
            margin-bottom: 6px !important;
            font-weight: 600 !important;
        }
        .compact-swal .swal2-html-container {
            font-size: 9.5px !important;
            margin: 6px 0 !important;
            line-height: 1.4 !important;
        }
        .compact-swal .swal2-actions {
            margin-top: 10px !important;
            gap: 6px !important;
        }
        .compact-swal .swal2-styled {
            font-size: 9.5px !important;
            height: 22px !important;
            padding: 0 12px !important;
            line-height: 20px !important;
            border-radius: 2px !important;
            margin: 0 !important;
        }
        .compact-swal .swal2-icon {
            width: 42px !important;
            height: 42px !important;
            margin: 4px auto 8px auto !important;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 4 Top KPI Cards -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fa-solid fa-trophy"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Laba Bersih Usaha (P&amp;L)</span>
                    <span class="kpi-val" id="kpi-net-profit" style="color:var(--success);">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fa-solid fa-chart-line"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Penjualan Produk</span>
                    <span class="kpi-val" id="kpi-penjualan">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fa-solid fa-industry"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Harga Pokok Produksi (HPP)</span>
                    <span class="kpi-val" id="kpi-hpp">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="fa-solid fa-building-columns"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Aktiva (Aset Perusahaan)</span>
                    <span class="kpi-val" id="kpi-aktiva">Rp 0</span>
                </div>
            </div>
        </div>

        <!-- Main Card Panel with Tabs -->
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-lr" onclick="switchTab('lr')">
                        <i class="fa-solid fa-chart-pie"></i> 1. Laporan Laba Rugi (P&amp;L)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-hpp" onclick="switchTab('hpp')">
                        <i class="fa-solid fa-industry"></i> 2. Harga Pokok Produksi (HPP)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-nr" onclick="switchTab('nr')">
                        <i class="fa-solid fa-scale-balanced"></i> 3. Laporan Neraca (Balance Sheet)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-nl" onclick="switchTab('nl')">
                        <i class="fa-solid fa-table-cells"></i> 4. Neraca Lajur 10 Kolom (Worksheet)
                    </button>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="ant-btn ant-btn-success" onclick="recalculateNeracaLajur()" id="btn-recalc-nl">
                        <i class="fa-solid fa-calculator"></i> Proses &amp; Hitung Neraca Lajur
                    </button>
                    <button type="button" class="ant-btn" onclick="exportActiveToExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Export Excel
                    </button>
                </div>
            </div>

            <!-- TOOLBAR PERIODE -->
            <div class="toolbar">
                <label style="font-weight:600;">Periode Akuntansi:</label>
                <input type="month" id="sel-periode" class="ant-input" value="2026-09">

                <button type="button" class="ant-btn ant-btn-primary" onclick="loadCurrentReport()">
                    <i class="fa-solid fa-magnifying-glass"></i> Tampilkan Laporan
                </button>
                <span id="load-status" style="font-size:9px; color:var(--text-muted); margin-left:6px;"></span>
            </div>

            <!-- TAB 1: LABA RUGI -->
            <div id="panel-lr" style="display:flex; flex-direction:column; flex:1; min-height:0; overflow:auto; padding:10px;">
                <div class="rpt-paper" id="paper-lr">
                    <div class="rpt-title">LAPORAN LABA RUGI KOMPREHENSIF</div>
                    <div class="rpt-subtitle" id="subtitle-lr">Periode: September 2026</div>

                    <div class="rpt-section-header">A. PENDAPATAN USAHA (REVENUE)</div>
                    <div id="container-lr-revenue"></div>
                    <div class="rpt-row total"><span>TOTAL PENDAPATAN USAHA:</span><span id="tot-lr-revenue">Rp 0</span></div>

                    <div class="rpt-section-header" style="margin-top:14px;">B. HARGA POKOK PENJUALAN (COGS / HPP)</div>
                    <div id="container-lr-hpp"></div>
                    <div class="rpt-row total"><span>TOTAL HPP:</span><span id="tot-lr-hpp">Rp 0</span></div>

                    <div class="rpt-row total" style="background:#f6ffed; margin-top:8px;">
                        <span>LABA KOTOR PENJUALAN (GROSS PROFIT):</span><span id="tot-lr-gross">Rp 0</span>
                    </div>

                    <div class="rpt-section-header" style="margin-top:14px;">C. BEBAN OPERASIONAL &amp; USAHA</div>
                    <div id="container-lr-expense"></div>
                    <div class="rpt-row total"><span>TOTAL BEBAN OPERASIONAL:</span><span id="tot-lr-expense">Rp 0</span></div>

                    <div class="rpt-section-header" style="margin-top:14px;">D. PENDAPATAN &amp; BEBAN LAIN-LAIN</div>
                    <div id="container-lr-other"></div>
                    <div class="rpt-row total"><span>TOTAL PENDAPATAN/(BEBAN) LAIN:</span><span id="tot-lr-other">Rp 0</span></div>

                    <div class="rpt-row total" style="background:#e6f7ff; margin-top:10px; font-size:11px;">
                        <span>LABA BERSIH USAHA SEBELUM PAJAK:</span><span id="tot-lr-net">Rp 0</span>
                    </div>
                </div>
            </div>

            <!-- TAB 2: HPP MANUFAKTUR -->
            <div id="panel-hpp" style="display:none; flex-direction:column; flex:1; min-height:0; overflow:auto; padding:10px;">
                <div class="rpt-paper" id="paper-hpp">
                    <div class="rpt-title">LAPORAN HARGA POKOK PRODUKSI (MANUFACTURING COGS)</div>
                    <div class="rpt-subtitle" id="subtitle-hpp">Periode: September 2026</div>

                    <div class="rpt-section-header">1. PEMAKAIAN BAHAN BAKU LANGSUNG (RAW MATERIAL)</div>
                    <div id="container-hpp-bb"></div>
                    <div class="rpt-row total"><span>TOTAL BAHAN BAKU DIGUNAKAN:</span><span id="tot-hpp-bb">Rp 0</span></div>

                    <div class="rpt-section-header" style="margin-top:14px;">2. BEBAN TENAGA KERJA LANGSUNG &amp; BIAYA OVERHEAD PABRIK (BTKL &amp; BOP)</div>
                    <div id="container-hpp-bop"></div>
                    <div class="rpt-row total"><span>TOTAL BIAYA PRODUKSI PABRIK:</span><span id="tot-hpp-bop">Rp 0</span></div>

                    <div class="rpt-section-header" style="margin-top:14px;">3. PERSEDIAAN BARANG DALAM PROSES (WIP)</div>
                    <div id="container-hpp-wip"></div>

                    <div class="rpt-row total" style="background:#fffbe6; margin-top:10px; font-size:11px;">
                        <span>JUMLAH HARGA POKOK PRODUKSI BARANG JADI (COGM):</span><span id="tot-hpp-fg">Rp 0</span>
                    </div>
                </div>
            </div>

            <!-- TAB 3: NERACA (BALANCE SHEET) -->
            <div id="panel-nr" style="display:none; flex-direction:column; flex:1; min-height:0; overflow:auto; padding:10px;">
                <div class="rpt-paper" id="paper-nr">
                    <div class="rpt-title">LAPORAN POSISI KEUANGAN (NERACA)</div>
                    <div class="rpt-subtitle" id="subtitle-nr">Per Akhir Periode</div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div>
                            <div class="rpt-section-header">AKTIVA LANCAR (CURRENT ASSETS)</div>
                            <div id="container-nr-aktiva-lancar"></div>
                            <div class="rpt-row total"><span>TOTAL AKTIVA LANCAR:</span><span id="tot-nr-aktiva-lancar">Rp 0</span></div>

                            <div class="rpt-section-header" style="margin-top:14px;">AKTIVA TETAP (FIXED ASSETS)</div>
                            <div id="container-nr-aktiva-tetap"></div>
                            <div class="rpt-row total"><span>TOTAL AKTIVA TETAP:</span><span id="tot-nr-aktiva-tetap">Rp 0</span></div>

                            <div class="rpt-row total" style="background:#e6f7ff; margin-top:10px;">
                                <span>TOTAL AKTIVA (ASET):</span><span id="tot-nr-aktiva">Rp 0</span>
                            </div>
                        </div>

                        <div>
                            <div class="rpt-section-header">KEWAJIBAN LANCAR (LIABILITIES)</div>
                            <div id="container-nr-kewajiban"></div>
                            <div class="rpt-row total"><span>TOTAL KEWAJIBAN:</span><span id="tot-nr-kewajiban">Rp 0</span></div>

                            <div class="rpt-section-header" style="margin-top:14px;">EKUITAS / MODAL (EQUITY)</div>
                            <div id="container-nr-modal"></div>
                            <div class="rpt-row total"><span>TOTAL MODAL &amp; EKUITAS:</span><span id="tot-nr-modal">Rp 0</span></div>

                            <div class="rpt-row total" style="background:#e6f7ff; margin-top:10px;">
                                <span>TOTAL KEWAJIBAN &amp; EKUITAS:</span><span id="tot-nr-pasiva">Rp 0</span>
                            </div>
                        </div>
                    </div>
                    <div id="balance-check" style="text-align:center; margin-top:12px; font-weight:600; font-size:10px;"></div>
                </div>
            </div>

            <!-- TAB 4: NERACA LAJUR 10 KOLOM -->
            <div id="panel-nl" style="display:none; flex-direction:column; flex:1; min-height:0;">
                <div class="grid-wrapper">
                    <table class="ant-table" id="table-nl">
                        <thead>
                            <tr>
                                <th rowspan="2" style="width: 85px;">Kode</th>
                                <th rowspan="2" style="width: 220px;">Nama Akun GL</th>
                                <th colspan="2" style="text-align:center; background:#fafafa;">Saldo Awal</th>
                                <th colspan="2" style="text-align:center; background:#f0f5ff;">Mutasi Transaksi</th>
                                <th colspan="2" style="text-align:center; background:#f6ffed;">Saldo Disesuaikan</th>
                                <th colspan="2" style="text-align:center; background:#fffbe6;">Laba Rugi</th>
                                <th colspan="2" style="text-align:center; background:#f9f0ff;">Neraca</th>
                            </tr>
                            <tr>
                                <th style="width: 85px; text-align:right;">Debet</th>
                                <th style="width: 85px; text-align:right;">Kredit</th>
                                <th style="width: 85px; text-align:right;">Debet</th>
                                <th style="width: 85px; text-align:right;">Kredit</th>
                                <th style="width: 85px; text-align:right;">Debet</th>
                                <th style="width: 85px; text-align:right;">Kredit</th>
                                <th style="width: 85px; text-align:right;">Debet</th>
                                <th style="width: 85px; text-align:right;">Kredit</th>
                                <th style="width: 85px; text-align:right;">Debet</th>
                                <th style="width: 85px; text-align:right;">Kredit</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-nl">
                            <tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat Neraca Lajur 10 Kolom...</td></tr>
                        </tbody>
                        <tfoot id="tfoot-nl" style="font-weight:700; background:var(--table-head-bg);"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        let reportData = [];
        let currentTab = 'lr';

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadCurrentReport();

            if (window.AntGrid) {
                AntGrid.initTableResizing('#table-nl');
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
            currentTab = tab;
            document.getElementById('tab-btn-lr').classList.toggle('active', tab === 'lr');
            document.getElementById('tab-btn-hpp').classList.toggle('active', tab === 'hpp');
            document.getElementById('tab-btn-nr').classList.toggle('active', tab === 'nr');
            document.getElementById('tab-btn-nl').classList.toggle('active', tab === 'nl');

            document.getElementById('panel-lr').style.display = tab === 'lr' ? 'flex' : 'none';
            document.getElementById('panel-hpp').style.display = tab === 'hpp' ? 'flex' : 'none';
            document.getElementById('panel-nr').style.display = tab === 'nr' ? 'flex' : 'none';
            document.getElementById('panel-nl').style.display = tab === 'nl' ? 'flex' : 'none';
        }

        async function loadKPI() {
            const ym = document.getElementById('sel-periode').value;
            try {
                const res = await fetch(`financial_reports.php?action=kpi&cmp_id=<?= urlencode($sessionCmpId) ?>&periode=${encodeURIComponent(ym)}`);
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-net-profit').textContent = formatRupiah(json.kpi.net_profit);
                    document.getElementById('kpi-penjualan').textContent = formatRupiah(json.kpi.total_penjualan);
                    document.getElementById('kpi-hpp').textContent = formatRupiah(json.kpi.total_hpp);
                    document.getElementById('kpi-aktiva').textContent = formatRupiah(json.kpi.total_aktiva);
                }
            } catch (e) {
                console.error("Gagal load KPI:", e);
            }
        }

        async function loadCurrentReport() {
            const ym = document.getElementById('sel-periode').value;
            document.getElementById('subtitle-lr').textContent = 'Periode: ' + ym;
            document.getElementById('subtitle-hpp').textContent = 'Periode: ' + ym;
            document.getElementById('subtitle-nr').textContent = 'Per Periode: ' + ym;
            document.getElementById('load-status').textContent = 'Memuat data...';

            loadKPI();

            try {
                const res = await fetch(`financial_reports.php?action=get_report&cmp_id=<?= urlencode($sessionCmpId) ?>&periode=${encodeURIComponent(ym)}`);
                const json = await res.json();
                document.getElementById('load-status').textContent = '';

                if (json.status === 'OK') {
                    reportData = json.data || [];
                    renderIncomeStatement();
                    renderHppReport();
                    renderBalanceSheet();
                    renderNeracaLajurTable();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Memuat',
                        text: json.message || 'Gagal memuat laporan',
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (e) {
                document.getElementById('load-status').textContent = '';
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        let computedNetProfit = 0;

        function renderIncomeStatement() {
            let revHtml = '', hppHtml = '', expHtml = '', otherHtml = '';
            let totRev = 0, totHpp = 0, totExp = 0, totOther = 0;

            reportData.forEach(r => {
                // Hanya akun detail agar tidak dobel hitung
                if (r.header === 'H') return;

                const kode = r.kode;
                const lrDb = parseFloat(r.LR_db) || 0;
                const lrKr = parseFloat(r.LR_kr) || 0;

                // 1. PENDAPATAN USAHA (REVENUE) -> 4...
                if (kode.startsWith('4')) {
                    const val = lrKr - lrDb;
                    if (val !== 0) {
                        totRev += val;
                        revHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
                // 2. HARGA POKOK PENJUALAN (HPP) -> 5...
                else if (kode.startsWith('5')) {
                    const val = lrDb - lrKr;
                    if (val !== 0) {
                        totHpp += val;
                        hppHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
                // 3. BEBAN OPERASIONAL & USAHA -> 6...
                else if (kode.startsWith('6')) {
                    const val = lrDb - lrKr;
                    if (val !== 0) {
                        totExp += val;
                        expHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
                // 4. PENDAPATAN & BEBAN LAIN-LAIN -> 7..., 8..., 9...
                else if (kode.startsWith('7') || kode.startsWith('8') || kode.startsWith('9')) {
                    const val = lrKr - lrDb;
                    if (val !== 0) {
                        totOther += val;
                        otherHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
            });

            const grossProfit = totRev - totHpp;
            computedNetProfit = grossProfit - totExp + totOther;

            document.getElementById('container-lr-revenue').innerHTML = revHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Tidak ada transaksi pendapatan -</div>';
            document.getElementById('container-lr-hpp').innerHTML = hppHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Nihil HPP -</div>';
            document.getElementById('container-lr-expense').innerHTML = expHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Tidak ada beban operasional -</div>';
            document.getElementById('container-lr-other').innerHTML = otherHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Nihil -</div>';

            document.getElementById('tot-lr-revenue').textContent = formatRupiah(totRev);
            document.getElementById('tot-lr-hpp').textContent = formatRupiah(totHpp);
            document.getElementById('tot-lr-gross').textContent = formatRupiah(grossProfit);
            document.getElementById('tot-lr-expense').textContent = formatRupiah(totExp);
            document.getElementById('tot-lr-other').textContent = formatRupiah(totOther);
            document.getElementById('tot-lr-net').textContent = formatRupiah(computedNetProfit);
        }

        function renderHppReport() {
            let bbHtml = '', bopHtml = '', wipHtml = '';
            let totBb = 0, totBop = 0, totWip = 0;

            reportData.forEach(r => {
                if (r.header === 'H') return;
                const kode = r.kode;
                const db = parseFloat(r.LR_db) || parseFloat(r.saldo_db) || 0;
                const kr = parseFloat(r.LR_kr) || parseFloat(r.saldo_kr) || 0;
                const val = db - kr;

                // Bahan Baku Langsung
                if (kode.startsWith('511') || kode.startsWith('515-01-001') || kode.startsWith('515-01-002') || kode.startsWith('515-01-003')) {
                    if (val !== 0) {
                        totBb += val;
                        bbHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
                // Tenaga Kerja Langsung & Biaya Overhead Pabrik (BOP)
                else if (kode.startsWith('512') || kode.startsWith('513') || kode.startsWith('514') || kode.startsWith('521')) {
                    if (val !== 0) {
                        totBop += val;
                        bopHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
                // Persediaan WIP
                else if (kode.startsWith('515-01-004') || kode.startsWith('515-01-005') || kode === '113-01-005') {
                    if (val !== 0) {
                        totWip += val;
                        wipHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
            });

            document.getElementById('container-hpp-bb').innerHTML = bbHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Tidak ada pemakaian bahan -</div>';
            document.getElementById('container-hpp-bop').innerHTML = bopHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Tidak ada biaya pabrikasi / BOP -</div>';
            document.getElementById('container-hpp-wip').innerHTML = wipHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Persediaan WIP Nihil -</div>';

            document.getElementById('tot-hpp-bb').textContent = formatRupiah(totBb);
            document.getElementById('tot-hpp-bop').textContent = formatRupiah(totBop);
            document.getElementById('tot-hpp-fg').textContent = formatRupiah(totBb + totBop + totWip);
        }

        function renderBalanceSheet() {
            let actLancarHtml = '', actTetapHtml = '', kewaHtml = '', modHtml = '';
            let totActLancar = 0, totActTetap = 0, totKewa = 0, totMod = 0;

            reportData.forEach(r => {
                if (r.header === 'H') return;
                const kode = r.kode;
                const nrDb = parseFloat(r.NR_db) || 0;
                const nrKr = parseFloat(r.NR_kr) || 0;

                // AKTIVA LANCAR: Kas/Bank (111), Piutang (112), Persediaan (113), Pajak Dimuka (114)
                if (kode.startsWith('11')) {
                    const val = nrDb - nrKr;
                    if (val !== 0) {
                        totActLancar += val;
                        actLancarHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                } 
                // AKTIVA TETAP: 12..., 13...
                else if (kode.startsWith('12') || kode.startsWith('13')) {
                    const val = nrDb - nrKr;
                    if (val !== 0) {
                        totActTetap += val;
                        actTetapHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                } 
                // KEWAJIBAN / HUTANG: 2...
                else if (kode.startsWith('2')) {
                    const val = nrKr - nrDb;
                    if (val !== 0) {
                        totKewa += val;
                        kewaHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                } 
                // EKUITAS / MODAL: 3...
                else if (kode.startsWith('3')) {
                    const val = nrKr - nrDb;
                    if (val !== 0) {
                        totMod += val;
                        modHtml += `<div class="rpt-row"><span>${escapeHtml(r.kode)} - ${escapeHtml(r.nama_akun)}</span><span>${formatRupiah(val)}</span></div>`;
                    }
                }
            });

            // Tambahkan baris Laba Periode Berjalan dari Laba Rugi ke Ekuitas
            if (computedNetProfit !== 0) {
                totMod += computedNetProfit;
                modHtml += `<div class="rpt-row" style="color:var(--primary); font-weight:600;"><span>Laba Periode Berjalan (P&amp;L)</span><span>${formatRupiah(computedNetProfit)}</span></div>`;
            }

            const totalAktiva = totActLancar + totActTetap;
            const totalPasiva = totKewa + totMod;

            document.getElementById('container-nr-aktiva-lancar').innerHTML = actLancarHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Nihil -</div>';
            document.getElementById('container-nr-aktiva-tetap').innerHTML = actTetapHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Nihil -</div>';
            document.getElementById('container-nr-kewajiban').innerHTML = kewaHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Nihil -</div>';
            document.getElementById('container-nr-modal').innerHTML = modHtml || '<div class="rpt-row" style="color:var(--text-muted)">- Nihil -</div>';

            document.getElementById('tot-nr-aktiva-lancar').textContent = formatRupiah(totActLancar);
            document.getElementById('tot-nr-aktiva-tetap').textContent = formatRupiah(totActTetap);
            document.getElementById('tot-nr-aktiva').textContent = formatRupiah(totalAktiva);

            document.getElementById('tot-nr-kewajiban').textContent = formatRupiah(totKewa);
            document.getElementById('tot-nr-modal').textContent = formatRupiah(totMod);
            document.getElementById('tot-nr-pasiva').textContent = formatRupiah(totalPasiva);

            const diff = Math.abs(totalAktiva - totalPasiva);
            const checkEl = document.getElementById('balance-check');
            if (diff < 1) {
                checkEl.innerHTML = '<span style="color:var(--success); background:#f6ffed; padding:3px 12px; border-radius:3px; border:1px solid #b7eb8f;"><i class="fa-solid fa-circle-check"></i> POSISI KEUANGAN SEIMBANG (BALANCED)</span>';
            } else {
                checkEl.innerHTML = `<span style="color:var(--warning); background:#fffbe6; padding:3px 12px; border-radius:3px; border:1px solid #ffe58f;"><i class="fa-solid fa-triangle-exclamation"></i> Selisih Neraca: ${formatRupiah(diff)}</span>`;
            }
        }

        function renderNeracaLajurTable() {
            const tbody = document.getElementById('tbody-nl');
            const tfoot = document.getElementById('tfoot-nl');

            if (reportData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-muted);">Neraca Lajur belum diproses. Klik tombol "Proses &amp; Hitung Neraca Lajur".</td></tr>';
                tfoot.innerHTML = '';
                return;
            }

            let html = '';
            let sumSaDb = 0, sumSaKr = 0;
            let sumMuDb = 0, sumMuKr = 0;
            let sumSalDb = 0, sumSalKr = 0;
            let sumLrDb = 0, sumLrKr = 0;
            let sumNrDb = 0, sumNrKr = 0;

            reportData.forEach(r => {
                const isHeader = r.header === 'H';
                const rowStyle = isHeader ? 'font-weight:600; background:var(--table-head-bg);' : '';
                const codeIndent = isHeader ? '' : 'padding-left:14px;';

                if (!isHeader) {
                    sumSaDb += parseFloat(r.saldoawal_db) || 0;
                    sumSaKr += parseFloat(r.saldoawal_kr) || 0;
                    sumMuDb += parseFloat(r.mutasi_db) || 0;
                    sumMuKr += parseFloat(r.mutasi_kr) || 0;
                    sumSalDb += parseFloat(r.saldo_db) || 0;
                    sumSalKr += parseFloat(r.saldo_kr) || 0;
                    sumLrDb += parseFloat(r.LR_db) || 0;
                    sumLrKr += parseFloat(r.LR_kr) || 0;
                    sumNrDb += parseFloat(r.NR_db) || 0;
                    sumNrKr += parseFloat(r.NR_kr) || 0;
                }

                html += `
                <tr style="${rowStyle}">
                    <td style="${codeIndent}"><code>${escapeHtml(r.kode)}</code></td>
                    <td>${escapeHtml(r.nama_akun)}</td>
                    <td style="text-align:right;">${formatNumber(r.saldoawal_db)}</td>
                    <td style="text-align:right;">${formatNumber(r.saldoawal_kr)}</td>
                    <td style="text-align:right;">${formatNumber(r.mutasi_db)}</td>
                    <td style="text-align:right;">${formatNumber(r.mutasi_kr)}</td>
                    <td style="text-align:right;">${formatNumber(r.saldo_db)}</td>
                    <td style="text-align:right;">${formatNumber(r.saldo_kr)}</td>
                    <td style="text-align:right;">${formatNumber(r.LR_db)}</td>
                    <td style="text-align:right;">${formatNumber(r.LR_kr)}</td>
                    <td style="text-align:right;">${formatNumber(r.NR_db)}</td>
                    <td style="text-align:right;">${formatNumber(r.NR_kr)}</td>
                </tr>`;
            });

            tbody.innerHTML = html;

            // Baris Total, Selisih Laba/Rugi, dan Baris Keseimbangan
            const netLr = sumLrKr - sumLrDb;
            const netNr = sumNrDb - sumNrKr;

            let footHtml = `
            <tr>
                <td colspan="2" style="text-align:center;">TOTAL MUTASI &amp; SALDO:</td>
                <td style="text-align:right;">${formatNumber(sumSaDb)}</td>
                <td style="text-align:right;">${formatNumber(sumSaKr)}</td>
                <td style="text-align:right;">${formatNumber(sumMuDb)}</td>
                <td style="text-align:right;">${formatNumber(sumMuKr)}</td>
                <td style="text-align:right;">${formatNumber(sumSalDb)}</td>
                <td style="text-align:right;">${formatNumber(sumSalKr)}</td>
                <td style="text-align:right;">${formatNumber(sumLrDb)}</td>
                <td style="text-align:right;">${formatNumber(sumLrKr)}</td>
                <td style="text-align:right;">${formatNumber(sumNrDb)}</td>
                <td style="text-align:right;">${formatNumber(sumNrKr)}</td>
            </tr>
            <tr style="background:#e6f7ff; color:var(--primary);">
                <td colspan="2" style="text-align:center;">LABA / (RUGI) BERSIH:</td>
                <td colspan="6" style="text-align:center; font-style:italic;">-- Ditransfer ke Laporan Ekuitas --</td>
                <td style="text-align:right;">${netLr >= 0 ? formatNumber(netLr) : '0,00'}</td>
                <td style="text-align:right;">${netLr < 0 ? formatNumber(Math.abs(netLr)) : '0,00'}</td>
                <td style="text-align:right;">${netNr < 0 ? formatNumber(Math.abs(netNr)) : '0,00'}</td>
                <td style="text-align:right;">${netNr >= 0 ? formatNumber(netNr) : '0,00'}</td>
            </tr>
            <tr style="background:#f6ffed; color:#389e0d; border-top:2px solid #52c41a;">
                <td colspan="2" style="text-align:center;">KESEIMBANGAN AKHIR:</td>
                <td style="text-align:right;">${formatNumber(sumSaDb)}</td>
                <td style="text-align:right;">${formatNumber(sumSaKr)}</td>
                <td style="text-align:right;">${formatNumber(sumMuDb)}</td>
                <td style="text-align:right;">${formatNumber(sumMuKr)}</td>
                <td style="text-align:right;">${formatNumber(sumSalDb)}</td>
                <td style="text-align:right;">${formatNumber(sumSalKr)}</td>
                <td style="text-align:right;">${formatNumber(Math.max(sumLrDb, sumLrKr))}</td>
                <td style="text-align:right;">${formatNumber(Math.max(sumLrDb, sumLrKr))}</td>
                <td style="text-align:right;">${formatNumber(Math.max(sumNrDb, sumNrKr))}</td>
                <td style="text-align:right;">${formatNumber(Math.max(sumNrDb, sumNrKr))}</td>
            </tr>`;

            tfoot.innerHTML = footHtml;
        }

        async function recalculateNeracaLajur() {
            const ym = document.getElementById('sel-periode').value;
            const btn = document.getElementById('btn-recalc-nl');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses...';

            try {
                const res = await fetch(`financial_reports.php?action=recalculate_nl&cmp_id=<?= urlencode($sessionCmpId) ?>&periode=${encodeURIComponent(ym)}`);
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-calculator"></i> Proses &amp; Hitung Neraca Lajur';

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Kalkulasi Berhasil',
                        text: json.message,
                        timer: 2000,
                        showConfirmButton: false,
                        customClass: { popup: 'compact-swal' }
                    });
                    loadCurrentReport();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Kalkulasi',
                        text: json.message,
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-calculator"></i> Proses &amp; Hitung Neraca Lajur';
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        // Multi-Sheet Export Excel: Sheet NL, Sheet LR, Sheet HPP, Sheet Neraca
        function exportActiveToExcel() {
            if (reportData.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Data',
                    text: 'Silakan klik tombol "Proses & Hitung Neraca Lajur" terlebih dahulu.',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const ym = document.getElementById('sel-periode').value;
            const wb = XLSX.utils.book_new();

            // 1. SHEET 1: NERACA LAJUR 10 KOLOM
            const nlRows = [];
            nlRows.push(['LAPORAN NERACA LAJUR 10 KOLOM (WORKSHEET)']);
            nlRows.push(['Periode: ' + ym, '', 'Perusahaan: <?= addslashes($namaperusahaan ?: $sessionCmpId) ?>']);
            nlRows.push([]);
            nlRows.push([
                'Kode Akun', 'Nama Akun GL', 'Tipe Header',
                'Saldo Awal (Debet)', 'Saldo Awal (Kredit)',
                'Mutasi Transaksi (Debet)', 'Mutasi Transaksi (Kredit)',
                'Saldo Akhir (Debet)', 'Saldo Akhir (Kredit)',
                'Laba Rugi (Debet)', 'Laba Rugi (Kredit)',
                'Neraca (Debet)', 'Neraca (Kredit)'
            ]);

            reportData.forEach(r => {
                nlRows.push([
                    r.kode,
                    r.nama_akun,
                    r.header === 'H' ? 'Header' : 'Detail',
                    parseFloat(r.saldoawal_db) || 0,
                    parseFloat(r.saldoawal_kr) || 0,
                    parseFloat(r.mutasi_db) || 0,
                    parseFloat(r.mutasi_kr) || 0,
                    parseFloat(r.saldo_db) || 0,
                    parseFloat(r.saldo_kr) || 0,
                    parseFloat(r.LR_db) || 0,
                    parseFloat(r.LR_kr) || 0,
                    parseFloat(r.NR_db) || 0,
                    parseFloat(r.NR_kr) || 0
                ]);
            });

            const wsNL = XLSX.utils.aoa_to_sheet(nlRows);
            XLSX.utils.book_append_sheet(wb, wsNL, "Neraca Lajur 10 Kolom");

            // 2. SHEET 2: LABA RUGI (P&L)
            const lrRows = [];
            lrRows.push(['LAPORAN LABA RUGI KOMPREHENSIF']);
            lrRows.push(['Periode: ' + ym, '', 'Perusahaan: <?= addslashes($namaperusahaan ?: $sessionCmpId) ?>']);
            lrRows.push([]);
            lrRows.push(['Kode Akun', 'Uraian Akun', 'Jumlah (Rp)']);

            let totRev = 0, totHpp = 0, totExp = 0, totOther = 0;

            lrRows.push(['A. PENDAPATAN USAHA (REVENUE)', '', '']);
            reportData.filter(r => r.header !== 'H' && r.kode.startsWith('4')).forEach(r => {
                const val = (parseFloat(r.LR_kr) || 0) - (parseFloat(r.LR_db) || 0);
                if (val !== 0) {
                    totRev += val;
                    lrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            lrRows.push(['TOTAL PENDAPATAN USAHA', '', totRev]);
            lrRows.push([]);

            lrRows.push(['B. HARGA POKOK PENJUALAN (COGS / HPP)', '', '']);
            reportData.filter(r => r.header !== 'H' && r.kode.startsWith('5')).forEach(r => {
                const val = (parseFloat(r.LR_db) || 0) - (parseFloat(r.LR_kr) || 0);
                if (val !== 0) {
                    totHpp += val;
                    lrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            lrRows.push(['TOTAL HPP', '', totHpp]);
            lrRows.push(['LABA KOTOR PENJUALAN (GROSS PROFIT)', '', totRev - totHpp]);
            lrRows.push([]);

            lrRows.push(['C. BEBAN OPERASIONAL & USAHA', '', '']);
            reportData.filter(r => r.header !== 'H' && r.kode.startsWith('6')).forEach(r => {
                const val = (parseFloat(r.LR_db) || 0) - (parseFloat(r.LR_kr) || 0);
                if (val !== 0) {
                    totExp += val;
                    lrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            lrRows.push(['TOTAL BEBAN OPERASIONAL', '', totExp]);
            lrRows.push([]);

            lrRows.push(['D. PENDAPATAN & BEBAN LAIN-LAIN', '', '']);
            reportData.filter(r => r.header !== 'H' && (r.kode.startsWith('7') || r.kode.startsWith('8') || r.kode.startsWith('9'))).forEach(r => {
                const val = (parseFloat(r.LR_kr) || 0) - (parseFloat(r.LR_db) || 0);
                if (val !== 0) {
                    totOther += val;
                    lrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            lrRows.push(['TOTAL PENDAPATAN/(BEBAN) LAIN', '', totOther]);
            lrRows.push([]);
            lrRows.push(['LABA BERSIH USAHA SEBELUM PAJAK', '', (totRev - totHpp) - totExp + totOther]);

            const wsLR = XLSX.utils.aoa_to_sheet(lrRows);
            XLSX.utils.book_append_sheet(wb, wsLR, "Laba Rugi (P&L)");

            // 3. SHEET 3: HARGA POKOK PRODUKSI (HPP MANUFAKTUR)
            const hppRows = [];
            hppRows.push(['LAPORAN HARGA POKOK PRODUKSI (MANUFACTURING COGS)']);
            hppRows.push(['Periode: ' + ym, '', 'Perusahaan: <?= addslashes($namaperusahaan ?: $sessionCmpId) ?>']);
            hppRows.push([]);
            hppRows.push(['Kode Akun', 'Komponen Biaya Produksi', 'Jumlah (Rp)']);

            let totBb = 0, totBop = 0, totWip = 0;
            hppRows.push(['1. PEMAKAIAN BAHAN BAKU LANGSUNG', '', '']);
            reportData.filter(r => r.header !== 'H' && (r.kode.startsWith('511') || r.kode.startsWith('515-01-001') || r.kode.startsWith('515-01-002') || r.kode.startsWith('515-01-003'))).forEach(r => {
                const val = (parseFloat(r.LR_db) || parseFloat(r.saldo_db) || 0) - (parseFloat(r.LR_kr) || parseFloat(r.saldo_kr) || 0);
                if (val !== 0) {
                    totBb += val;
                    hppRows.push([r.kode, r.nama_akun, val]);
                }
            });
            hppRows.push(['TOTAL BAHAN BAKU DIGUNAKAN', '', totBb]);
            hppRows.push([]);

            hppRows.push(['2. BEBAN TENAGA KERJA & BIAYA OVERHEAD PABRIK (BTKL & BOP)', '', '']);
            reportData.filter(r => r.header !== 'H' && (r.kode.startsWith('512') || r.kode.startsWith('513') || r.kode.startsWith('514') || r.kode.startsWith('521'))).forEach(r => {
                const val = (parseFloat(r.LR_db) || 0) - (parseFloat(r.LR_kr) || 0);
                if (val !== 0) {
                    totBop += val;
                    hppRows.push([r.kode, r.nama_akun, val]);
                }
            });
            hppRows.push(['TOTAL BIAYA PRODUKSI PABRIK', '', totBop]);
            hppRows.push([]);

            hppRows.push(['3. PERSEDIAAN BARANG DALAM PROSES (WIP)', '', '']);
            reportData.filter(r => r.header !== 'H' && (r.kode.startsWith('515-01-004') || r.kode.startsWith('515-01-005') || r.kode === '113-01-005')).forEach(r => {
                const val = (parseFloat(r.LR_db) || parseFloat(r.saldo_db) || 0) - (parseFloat(r.LR_kr) || parseFloat(r.saldo_kr) || 0);
                if (val !== 0) {
                    totWip += val;
                    hppRows.push([r.kode, r.nama_akun, val]);
                }
            });
            hppRows.push(['TOTAL PERSEDIAAN WIP', '', totWip]);
            hppRows.push([]);
            hppRows.push(['JUMLAH HARGA POKOK PRODUKSI BARANG JADI (COGM)', '', totBb + totBop + totWip]);

            const wsHPP = XLSX.utils.aoa_to_sheet(hppRows);
            XLSX.utils.book_append_sheet(wb, wsHPP, "Harga Pokok Produksi (HPP)");

            // 4. SHEET 4: NERACA (BALANCE SHEET)
            const nrRows = [];
            nrRows.push(['LAPORAN POSISI KEUANGAN (NERACA)']);
            nrRows.push(['Periode: ' + ym, '', 'Perusahaan: <?= addslashes($namaperusahaan ?: $sessionCmpId) ?>']);
            nrRows.push([]);
            nrRows.push(['Kode Akun', 'Posisi Keuangan', 'Jumlah (Rp)']);

            let totActL = 0, totActT = 0, totKew = 0, totMod = 0;
            nrRows.push(['AKTIVA LANCAR', '', '']);
            reportData.filter(r => r.header !== 'H' && r.kode.startsWith('11')).forEach(r => {
                const val = (parseFloat(r.NR_db) || 0) - (parseFloat(r.NR_kr) || 0);
                if (val !== 0) {
                    totActL += val;
                    nrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            nrRows.push(['TOTAL AKTIVA LANCAR', '', totActL]);
            nrRows.push([]);

            nrRows.push(['AKTIVA TETAP', '', '']);
            reportData.filter(r => r.header !== 'H' && (r.kode.startsWith('12') || r.kode.startsWith('13'))).forEach(r => {
                const val = (parseFloat(r.NR_db) || 0) - (parseFloat(r.NR_kr) || 0);
                if (val !== 0) {
                    totActT += val;
                    nrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            nrRows.push(['TOTAL AKTIVA TETAP', '', totActT]);
            nrRows.push(['TOTAL AKTIVA (ASET)', '', totActL + totActT]);
            nrRows.push([]);

            nrRows.push(['KEWAJIBAN LANCAR (HUTANG)', '', '']);
            reportData.filter(r => r.header !== 'H' && r.kode.startsWith('2')).forEach(r => {
                const val = (parseFloat(r.NR_kr) || 0) - (parseFloat(r.NR_db) || 0);
                if (val !== 0) {
                    totKew += val;
                    nrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            nrRows.push(['TOTAL KEWAJIBAN', '', totKew]);
            nrRows.push([]);

            nrRows.push(['EKUITAS / MODAL', '', '']);
            reportData.filter(r => r.header !== 'H' && r.kode.startsWith('3')).forEach(r => {
                const val = (parseFloat(r.NR_kr) || 0) - (parseFloat(r.NR_db) || 0);
                if (val !== 0) {
                    totMod += val;
                    nrRows.push([r.kode, r.nama_akun, val]);
                }
            });
            const netProf = (totRev - totHpp) - totExp + totOther;
            if (netProf !== 0) {
                totMod += netProf;
                nrRows.push(['', 'Laba Periode Berjalan (P&L)', netProf]);
            }
            nrRows.push(['TOTAL MODAL & EKUITAS', '', totMod]);
            nrRows.push(['TOTAL KEWAJIBAN & EKUITAS', '', totKew + totMod]);

            const wsNR = XLSX.utils.aoa_to_sheet(nrRows);
            XLSX.utils.book_append_sheet(wb, wsNR, "Neraca (Balance Sheet)");

            // Unduh file
            XLSX.writeFile(wb, `Laporan_Keuangan_Lengkap_${ym}.xlsx`);
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

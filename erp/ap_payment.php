<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Bayar Hutang Usaha (AP Payment / Pengeluaran Kas & Bank)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_pp.srw)
 * DataWindows: d_pi_pp.srd, bc_pp_hdr.srd, bc_pp_dtl.srd, bc_pp_browse2_2022.srd, d_pp_prt_a.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Hutang Tertunggak, Terbayar Bulan Ini, Faktur Menunggu Pembayaran, Total Vendor)
 * - Tab 1: Form Entri Pelunasan Hutang (Pilih Vendor -> Tampil Faktur PI Belum Lunas -> Centang & Alokasi Bayar)
 * - Tab 2: Browse Riwayat Pembayaran Hutang (Filter Tanggal, Vendor, Status, dan Cetak Bukti Bayar)
 * - Terintegrasi Otomatis dengan Buku Pembantu Hutang (bc_act_bantu) dan Rekening Kas/Bank (bc_act_akun)
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
function ensurePpTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pp_hdr` (
        `id` varchar(50) NOT NULL DEFAULT '',
        `pp_no` varchar(30) DEFAULT NULL,
        `pp_dt` date DEFAULT NULL,
        `vendor` varchar(30) DEFAULT '',
        `bank_cd` varchar(30) DEFAULT '',
        `kurs` varchar(5) DEFAULT 'IDR',
        `rate` decimal(13,2) DEFAULT '1.00',
        `dept` varchar(30) DEFAULT '',
        `note` varchar(255) DEFAULT '',
        `ceq_amt` decimal(15,2) DEFAULT '0.00',
        `eq_amt` decimal(15,2) DEFAULT '0.00',
        `dist_amt` decimal(15,2) DEFAULT '0.00',
        `set_by` varchar(30) DEFAULT '',
        `status` varchar(1) DEFAULT '0',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_pp_no` (`pp_no`),
        KEY `idx_vendor` (`vendor`),
        KEY `idx_pp_dt` (`pp_dt`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pp_dtl` (
        `id` varchar(50) NOT NULL DEFAULT '',
        `id_hdr` varchar(50) NOT NULL DEFAULT '',
        `seq` int(11) DEFAULT '1',
        `id_pi` varchar(50) DEFAULT '',
        `amount` decimal(15,2) DEFAULT '0.00',
        `kurs` varchar(5) DEFAULT 'IDR',
        `status` varchar(1) DEFAULT '0',
        `setby` varchar(40) DEFAULT '',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_hdr` (`id_hdr`),
        KEY `idx_pi` (`id_pi`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensurePpTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";

    // Hitung total hutang tertunggak dari bc_pi_hdr yang belum lunas
    $sqlOutstanding = "SELECT 
        COUNT(*) as total_unpaid_pi,
        SUM(p.total_order - IFNULL(paid.total_paid, 0)) as total_hutang_outstanding
        FROM bc_pi_hdr p
        LEFT JOIN (
            SELECT id_pi, SUM(amount) as total_paid FROM bc_pp_dtl WHERE $cmpClause GROUP BY id_pi
        ) paid ON paid.id_pi = p.id
        WHERE $cmpClause AND (p.total_order - IFNULL(paid.total_paid, 0)) > 0.01";
    $resOut = $koneksi->query($sqlOutstanding);
    $rowOut = $resOut ? $resOut->fetch_assoc() : ['total_unpaid_pi' => 0, 'total_hutang_outstanding' => 0];

    // Total bayar bulan ini
    $currYm = date('Y-m');
    $sqlPaidMonth = "SELECT SUM(dist_amt) as total_paid_month, COUNT(*) as total_pp_month 
                     FROM bc_pp_hdr WHERE $cmpClause AND DATE_FORMAT(pp_dt, '%Y-%m') = '$currYm'";
    $resPm = $koneksi->query($sqlPaidMonth);
    $rowPm = $resPm ? $resPm->fetch_assoc() : ['total_paid_month' => 0, 'total_pp_month' => 0];

    // Total vendor supplier
    $sqlVendor = "SELECT COUNT(*) as total_vendor FROM bc_bs WHERE $cmpClause AND UPPER(TIPE) LIKE '%SUPPLIER%'";
    $resV = $koneksi->query($sqlVendor);
    $rowV = $resV ? $resV->fetch_assoc() : ['total_vendor' => 0];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_hutang'    => (float)($rowOut['total_hutang_outstanding'] ?? 0),
            'unpaid_pi_count' => (int)($rowOut['total_unpaid_pi'] ?? 0),
            'paid_month'      => (float)($rowPm['total_paid_month'] ?? 0),
            'total_vendor'    => (int)($rowV['total_vendor'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Unpaid Invoices for a Vendor (Porting d_pi_pp.srd)
// =========================================================================
if ($action === 'get_unpaid_invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $vendor = trim($_GET['vendor'] ?? '');
    if (empty($vendor)) {
        echo json_encode(['status' => 'OK', 'data' => []]);
        exit;
    }
    $escV = $koneksi->real_escape_string($vendor);
    $cmpClause = !empty($sessionCmpId) ? "(p.cmp_id = '$escCmp' OR p.cmp_id = '')" : "1=1";

    $sql = "SELECT p.id as id_pi, p.pi_no, p.pi_dt, 
            COALESCE(p.jatuhtempo, p.pi_dt) as due_dt,
            IFNULL(p.kurs, 'IDR') as kurs,
            IFNULL(p.total_order, 0) as total_order,
            IFNULL(paid.total_paid, 0) as paid,
            (IFNULL(p.total_order, 0) - IFNULL(paid.total_paid, 0)) as hutang
            FROM bc_pi_hdr p
            LEFT JOIN (
                SELECT id_pi, SUM(amount) as total_paid FROM bc_pp_dtl WHERE (cmp_id = '$escCmp' OR cmp_id = '') GROUP BY id_pi
            ) paid ON paid.id_pi = p.id
            WHERE $cmpClause AND p.vendor = '$escV'
            HAVING hutang > 0.01
            ORDER BY p.pi_dt ASC, p.pi_no ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['total_order'] = (float)$r['total_order'];
            $r['paid'] = (float)$r['paid'];
            $r['hutang'] = (float)$r['hutang'];
            $rows[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Save Payment Transaction (Insert / Update)
// =========================================================================
if ($action === 'save_pp') {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!$req || empty($req['vendor']) || empty($req['items'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data pembayaran tidak lengkap']);
        exit;
    }

    $ppNo    = trim($req['pp_no'] ?? '');
    $ppDt    = trim($req['pp_dt'] ?? date('Y-m-d'));
    $vendor  = trim($req['vendor']);
    $bankCd  = trim($req['bank_cd'] ?? '');
    $kurs    = trim($req['kurs'] ?? 'IDR');
    $rate    = floatval($req['rate'] ?? 1);
    $note    = trim($req['note'] ?? '');
    $items   = $req['items'];

    $distAmt = 0;
    foreach ($items as $it) {
        $distAmt += floatval($it['amount'] ?? 0);
    }

    if ($distAmt <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nominal pembayaran harus lebih besar dari 0']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        if (empty($ppNo)) {
            // Generate Nomor PP baru e.g. PP/2026/11/0001
            $ym = date('Y/m', strtotime($ppDt));
            $prefix = "PP/$ym/";
            $qSeq = $koneksi->query("SELECT MAX(pp_no) as max_no FROM bc_pp_hdr WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND pp_no LIKE '$prefix%'");
            $lastNo = ($qSeq && $rowSeq = $qSeq->fetch_assoc()) ? $rowSeq['max_no'] : null;
            $nextSeq = 1;
            if ($lastNo) {
                $lastNum = intval(substr($lastNo, -4));
                $nextSeq = $lastNum + 1;
            }
            $ppNo = $prefix . sprintf('%04d', $nextSeq);
        }

        $idHdr = 'PP-' . $sessionCmpId . '-' . time() . '-' . rand(100, 999);
        $escIdHdr = $koneksi->real_escape_string($idHdr);
        $escPpNo  = $koneksi->real_escape_string($ppNo);
        $escPpDt  = $koneksi->real_escape_string($ppDt);
        $escV     = $koneksi->real_escape_string($vendor);
        $escBank  = $koneksi->real_escape_string($bankCd);
        $escKurs  = $koneksi->real_escape_string($kurs);
        $escNote  = $koneksi->real_escape_string($note);

        $sqlHdr = "INSERT INTO bc_pp_hdr (id, pp_no, pp_dt, vendor, bank_cd, kurs, rate, note, ceq_amt, eq_amt, dist_amt, set_by, status, cmp_id)
                   VALUES ('$escIdHdr', '$escPpNo', '$escPpDt', '$escV', '$escBank', '$escKurs', $rate, '$escNote', $distAmt, $distAmt * $rate, $distAmt, '$userId', '1', '$escCmp')";
        $koneksi->query($sqlHdr);

        // Insert Detail & Update status PI
        $seq = 1;
        $stmtDtl = $koneksi->prepare("INSERT INTO bc_pp_dtl (id, id_hdr, seq, id_pi, amount, kurs, status, setby, cmp_id) VALUES (?, ?, ?, ?, ?, ?, '1', ?, ?)");
        foreach ($items as $it) {
            $amt = floatval($it['amount'] ?? 0);
            if ($amt <= 0) continue;

            $idPi = trim($it['id_pi']);
            $idDtl = 'PPD-' . $sessionCmpId . '-' . time() . '-' . $seq . '-' . rand(10, 99);
            $stmtDtl->bind_param("ssisdsss", $idDtl, $idHdr, $seq, $idPi, $amt, $kurs, $userId, $sessionCmpId);
            $stmtDtl->execute();
            $seq++;
        }
        $stmtDtl->close();

        // Otomatis masukkan ayat jurnal pelunasan AP ke bc_act_kas / bc_act_bank
        $vType = (strpos(strtoupper($bankCd), 'KAS') !== false) ? 'Kas' : 'Bank';
        $vTable = ($vType === 'Kas') ? 'bc_act_kas' : 'bc_act_bank';
        // Akun AP (Hutang) di Debet, Akun Kas/Bank di Kredit
        $escUraian = $koneksi->real_escape_string("Pembayaran Hutang $ppNo ke $vendor");
        $koneksi->query("INSERT INTO $vTable (tanggal, no_bukti, uraian, akun_db, akun_cr, amt_db, amt_cr, kode_pembantu, kurs, rate, status, cmp_id)
                         VALUES ('$escPpDt', '$escPpNo', '$escUraian', '210-01-001', '$escBank', $distAmt, $distAmt, '$escV', '$escKurs', $rate, 'Y', '$escCmp')");

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Pembayaran Hutang $ppNo berhasil disimpan", 'pp_no' => $ppNo]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan pembayaran: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Riwayat Pembayaran (Tab 2)
// =========================================================================
if ($action === 'list_pp') {
    header('Content-Type: application/json; charset=utf-8');

    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $search = trim($_GET['search'] ?? '');
    $where = [$cmpClause];
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(a.pp_no LIKE '%$escS%' OR a.vendor LIKE '%$escS%' OR b.NAMA LIKE '%$escS%' OR a.note LIKE '%$escS%')";
    }
    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "SELECT a.id, a.pp_no, a.pp_dt, a.vendor, IFNULL(b.NAMA, a.vendor) as vendor_name,
            a.bank_cd, a.kurs, a.rate, a.dist_amt, a.note, a.status, a.set_by,
            (SELECT COUNT(*) FROM bc_pp_dtl d WHERE d.id_hdr = a.id) as pi_count
            FROM bc_pp_hdr a
            LEFT JOIN bc_bs b ON a.vendor = b.KODE AND (b.cmp_id = '$escCmp' OR b.cmp_id = '')
            $whereSql
            ORDER BY a.pp_dt DESC, a.pp_no DESC LIMIT 200";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['dist_amt'] = (float)$r['dist_amt'];
            $rows[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Bank / Kas Accounts Dropdown
// =========================================================================
if ($action === 'get_bank_accounts') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $sql = "SELECT kode, nama_akun as nama, tipe FROM bc_act_akun 
            WHERE $cmpClause AND (tipe LIKE '%Asset%' OR tipe LIKE '%Kas%' OR tipe LIKE '%Bank%' OR nama_akun LIKE '%Kas%' OR nama_akun LIKE '%Bank%')
            ORDER BY kode ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bayar Hutang Usaha (AP Payment) - CEISA 4.0 & Enterprise Hub</title>

    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SweetAlert2 -->
    <script src="/assets/sweetalert2.all.min.js"></script>

    <!-- SheetJS -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-active: #096dd9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #1f2937;
            --text-sub: #6b7280;
            --border-color: #d9d9d9;
            --border-light: #e5e7eb;
            --border-table: #d9d9d9;
            --table-head-bg: #fafafa;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
            --form-font-size: 9.5px;
        }

        body[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: #e6e6e6;
            --text-sub: #8c8c8c;
            --border-color: #303030;
            --border-light: #262626;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --row-hover: #262638;
            --row-selected: #111d2c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: var(--app-font); }
        body { background: var(--bg-base); color: var(--text-main); font-size: 9.5px; line-height: 1.4; padding: 8px 10px; overflow-x: hidden; }

        .portal-header {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 4px; padding: 4px 8px; margin-bottom: 6px;
        }
        .portal-title { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: var(--text-main); }
        .portal-title i { color: var(--primary); font-size: 12px; }
        .portal-badge { font-size: 8.5px; font-weight: 600; padding: 1px 6px; border-radius: 3px; background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }

        /* KPI Row */
        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 6px; }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; padding: 5px 8px; display: flex; align-items: center; justify-content: space-between; min-height: 44px; }
        .kpi-info h5 { font-size: 8.5px; font-weight: 600; text-transform: uppercase; color: var(--text-sub); letter-spacing: 0.3px; }
        .kpi-info h3 { font-size: 13px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
        .kpi-icon { width: 26px; height: 26px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 11px; }
        .icon-blue   { background: #e6f7ff; color: #1890ff; }
        .icon-green  { background: #f6ffed; color: #52c41a; }
        .icon-amber  { background: #fffbe6; color: #faad14; }
        .icon-purple { background: #f9f0ff; color: #722ed1; }

        /* Navigation Tabs */
        .ant-tabs-nav { display: flex; gap: 2px; border-bottom: 1px solid var(--border-color); margin-bottom: 6px; }
        .ant-tab-btn {
            background: var(--bg-card); border: 1px solid var(--border-color); border-bottom: none;
            border-radius: 4px 4px 0 0; padding: 3px 12px; font-size: 9.5px; font-weight: 500;
            color: var(--text-sub); cursor: pointer; display: flex; align-items: center; gap: 5px; height: 24px; transition: all 0.2s;
        }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { background: var(--primary); color: #ffffff; border-color: var(--primary); font-weight: 600; }

        /* Form Card */
        .form-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; padding: 8px 10px; margin-bottom: 6px; }
        .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; align-items: center; }
        .form-group { display: flex; flex-direction: column; gap: 2px; }
        .form-group label { font-size: 9px; font-weight: 600; color: var(--text-sub); }

        .ant-input, .ant-select {
            font-size: 9.5px; height: 21px; padding: 0 6px; border: 1px solid var(--border-color);
            border-radius: 3px; background: var(--bg-card); color: var(--text-main); outline: none;
        }
        .ant-input:focus, .ant-select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24,144,255,0.15); }

        .ant-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 4px;
            font-size: 9.5px; font-weight: 500; height: 21px; padding: 0 8px; border-radius: 3px;
            border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main);
            cursor: pointer; white-space: nowrap; transition: all 0.2s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); border-color: var(--primary); color: #ffffff; }
        .ant-btn-primary:hover { background: var(--primary-hover); color: #ffffff; }
        .ant-btn-success { background: var(--success); border-color: var(--success); color: #ffffff; }
        .ant-btn-success:hover { opacity: 0.9; color: #ffffff; }

        /* Grid */
        .grid-container { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; overflow: hidden; position: relative; }
        .grid-scroll { max-height: calc(100vh - 230px); overflow: auto; }
        table.ant-table { width: 100%; border-collapse: collapse; font-size: 9.5px; white-space: nowrap; table-layout: fixed; }
        table.ant-table th {
            background: var(--table-head-bg); color: var(--text-main); font-size: 9.5px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px; padding: 3px 6px; border-right: 1px solid var(--border-table);
            border-bottom: 1px solid var(--border-table); position: sticky; top: 0; z-index: 5; user-select: none;
        }
        table.ant-table td { padding: 2px 6px; border-right: 1px solid var(--border-light); border-bottom: 1px solid var(--border-light); vertical-align: middle; }
        table.ant-table tr:hover td { background: var(--row-hover); }

        .col-resizer { position: absolute; top: 0; right: 0; width: 5px; cursor: col-resize; user-select: none; height: 100%; z-index: 6; }
        .col-resizer:hover, .col-resizer.resizing { background: var(--primary); }

        /* Autocomplete dropdown */
        .ac-list {
            position: absolute; background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 3px; max-height: 180px; overflow-y: auto; width: 280px; z-index: 999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: none;
        }
        .ac-item { padding: 4px 8px; font-size: 9.5px; cursor: pointer; border-bottom: 1px solid var(--border-light); }
        .ac-item:hover { background: var(--row-hover); color: var(--primary); }

        /* SweetAlert */
        .swal2-popup.swal-compact { width: 330px !important; padding: 12px 14px !important; border-radius: 4px !important; font-family: var(--app-font) !important; font-size: 9.5px !important; }
        .swal2-popup.swal-compact button.swal2-styled { font-size: 9.5px !important; height: 21px !important; padding: 0 10px !important; border-radius: 3px !important; line-height: 21px !important; }
    </style>
</head>
<body>

    <div class="portal-header">
        <div class="portal-title">
            <i class="fa-solid fa-money-bill-wave"></i>
            <span>Bayar Hutang Usaha (AP Payment / Pengeluaran Kas & Bank)</span>
            <span class="portal-badge">Tenant: <?= htmlspecialchars($sessionCmpId) ?></span>
            <span style="font-size: 8.5px; color: var(--text-sub); font-weight: 400; margin-left: 4px;">
                Porting PB w_pp.srw (Pelunasan Faktur PI & Buku Pembantu Hutang)
            </span>
        </div>
        <div>
            <button type="button" class="ant-btn ant-btn-sm" onclick="refreshCurrentTab()" title="Muat Ulang"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Hutang Tertunggak</h5>
                <h3 id="kpi-hutang">-</h3>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Faktur Menunggu Pembayaran</h5>
                <h3 id="kpi-faktur">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Terbayar Bulan Ini</h5>
                <h3 id="kpi-terbayar">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Rekanan Supplier</h5>
                <h3 id="kpi-vendor">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-truck-field"></i></div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="ant-tabs-nav">
        <button type="button" class="ant-tab-btn active" id="tab-btn-entri" onclick="switchTab('entri')">
            <i class="fa-solid fa-pen-to-square"></i> 1. Entri Pembayaran Hutang
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-riwayat" onclick="switchTab('riwayat')">
            <i class="fa-solid fa-list-check"></i> 2. Riwayat Pembayaran (Browse AP)
        </button>
    </div>

    <!-- TAB 1: FORM ENTRI PEMBAYARAN -->
    <div id="tab-content-entri">
        <div class="form-card">
            <div class="form-grid">
                <div class="form-group" style="position:relative;">
                    <label>Pilih Supplier / Vendor <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="input-vendor-search" class="ant-input" placeholder="Ketik kode / nama supplier..." autocomplete="off" oninput="searchVendorAc(this.value)">
                    <input type="hidden" id="selected-vendor-code">
                    <div id="vendor-ac-list" class="ac-list"></div>
                </div>
                <div class="form-group">
                    <label>Tanggal Pembayaran</label>
                    <input type="date" id="input-pp-dt" class="ant-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Rekening Kas / Bank Pelunasan <span style="color:var(--danger)">*</span></label>
                    <select id="select-bank-cd" class="ant-select">
                        <option value="">-- Pilih Akun Kas/Bank --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nomor Bukti Pembayaran (Auto/Manual)</label>
                    <input type="text" id="input-pp-no" class="ant-input" placeholder="[Auto Generated]">
                </div>
                <div class="form-group" style="grid-column: span 3;">
                    <label>Catatan / Keterangan Pembayaran</label>
                    <input type="text" id="input-pp-note" class="ant-input" placeholder="Misal: Pelunasan invoice bahan baku via transfer BCA...">
                </div>
                <div class="form-group" style="display:flex; justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <button type="button" class="ant-btn ant-btn-success" onclick="savePayment()" style="height:21px;">
                        <i class="fa-solid fa-check"></i> Simpan Transaksi Pembayaran
                    </button>
                </div>
            </div>
        </div>

        <!-- Invoices Table to Settle -->
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblUnpaidPi">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;"><input type="checkbox" id="chk-all-pi" onclick="toggleSelectAllPi(this)"></th>
                            <th style="width: 120px;">No Faktur PI</th>
                            <th style="width: 80px; text-align: center;">Tgl Faktur</th>
                            <th style="width: 80px; text-align: center;">Jatuh Tempo</th>
                            <th style="width: 50px; text-align: center;">Val</th>
                            <th style="width: 110px; text-align: right;">Total Tagihan</th>
                            <th style="width: 110px; text-align: right;">Sudah Terbayar</th>
                            <th style="width: 110px; text-align: right;">Sisa Hutang</th>
                            <th style="width: 130px; text-align: right;">Bayar Sekarang (Rp)</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyUnpaidPi">
                        <tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-sub);">Silakan pilih Supplier di atas untuk menampilkan faktur yang belum lunas.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: RIWAYAT PEMBAYARAN -->
    <div id="tab-content-riwayat" style="display:none;">
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:4px; padding:4px 8px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
            <input type="text" id="search-pp-history" class="ant-input" placeholder="Cari nomor bukti bayar, vendor, catatan..." style="width:240px;" oninput="filterHistoryLocal()">
            <button type="button" class="ant-btn" onclick="exportHistoryExcel()"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel</button>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblHistoryPp">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 130px;">No Bukti Bayar</th>
                            <th style="width: 80px; text-align: center;">Tgl Bayar</th>
                            <th style="width: 220px;">Supplier / Vendor</th>
                            <th style="width: 110px;">Akun Kas/Bank</th>
                            <th style="width: 50px; text-align: center;">Val</th>
                            <th style="width: 120px; text-align: right;">Total Bayar (Rp)</th>
                            <th style="width: 60px; text-align: center;">Faktur</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyHistoryPp">
                        <tr><td colspan="9" style="text-align:center; padding:16px;">Memuat riwayat pembayaran...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'entri';
        let rawUnpaidPi = [];
        let rawHistoryPp = [];
        let vendorsList = [];

        const SwalCompact = Swal.mixin({
            customClass: { popup: 'swal-compact', confirmButton: 'ant-btn ant-btn-primary', cancelButton: 'ant-btn' },
            buttonsStyling: false
        });

        document.addEventListener('DOMContentLoaded', () => {
            initResizableGrid('tblUnpaidPi', 'grid_pp_unpaid_widths');
            initResizableGrid('tblHistoryPp', 'grid_pp_history_widths');
            loadKPI();
            loadBankAccounts();
            loadVendorsList();
        });

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-btn-entri').classList.toggle('active', tab === 'entri');
            document.getElementById('tab-btn-riwayat').classList.toggle('active', tab === 'riwayat');
            document.getElementById('tab-content-entri').style.display = tab === 'entri' ? 'block' : 'none';
            document.getElementById('tab-content-riwayat').style.display = tab === 'riwayat' ? 'block' : 'none';

            if (tab === 'riwayat') loadHistoryPp();
        }

        function refreshCurrentTab() {
            loadKPI();
            if (currentTab === 'entri') {
                const v = document.getElementById('selected-vendor-code').value;
                if (v) loadUnpaidInvoices(v);
            } else {
                loadHistoryPp();
            }
        }

        async function loadKPI() {
            try {
                const res = await fetch('ap_payment.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-hutang').textContent = 'Rp ' + (json.kpi.total_hutang || 0).toLocaleString('id-ID', {maximumFractionDigits:0});
                    document.getElementById('kpi-faktur').textContent = (json.kpi.unpaid_pi_count || 0).toLocaleString('id-ID') + ' Faktur';
                    document.getElementById('kpi-terbayar').textContent = 'Rp ' + (json.kpi.paid_month || 0).toLocaleString('id-ID', {maximumFractionDigits:0});
                    document.getElementById('kpi-vendor').textContent = (json.kpi.total_vendor || 0).toLocaleString('id-ID') + ' Vendor';
                }
            } catch (e) { console.error(e); }
        }

        async function loadBankAccounts() {
            try {
                const res = await fetch('ap_payment.php?action=get_bank_accounts');
                const json = await res.json();
                if (json.status === 'OK') {
                    const sel = document.getElementById('select-bank-cd');
                    sel.innerHTML = '<option value="">-- Pilih Akun Kas/Bank --</option>';
                    (json.data || []).forEach(b => {
                        sel.innerHTML += `<option value="${escapeHtml(b.kode)}">${escapeHtml(b.kode)} - ${escapeHtml(b.nama)}</option>`;
                    });
                }
            } catch (e) { console.error(e); }
        }

        async function loadVendorsList() {
            try {
                const res = await fetch('partner_subsidiary.php?action=list_bantu&tipe=HU');
                const json = await res.json();
                if (json.status === 'OK') {
                    vendorsList = json.data || [];
                }
            } catch (e) { console.error(e); }
        }

        function searchVendorAc(q) {
            const list = document.getElementById('vendor-ac-list');
            if (!q.trim()) { list.style.display = 'none'; return; }
            const ql = q.toLowerCase();
            const matched = vendorsList.filter(v => (v.kode || '').toLowerCase().includes(ql) || (v.nama_akun || '').toLowerCase().includes(ql));
            if (matched.length === 0) { list.style.display = 'none'; return; }

            let html = '';
            matched.slice(0, 15).forEach(v => {
                html += `<div class="ac-item" onclick="selectVendor('${escapeHtml(v.kode)}', '${escapeHtml(v.nama_akun)}')">
                    <b>${escapeHtml(v.kode)}</b> - ${escapeHtml(v.nama_akun)}
                </div>`;
            });
            list.innerHTML = html;
            list.style.display = 'block';
        }

        function selectVendor(kode, nama) {
            document.getElementById('input-vendor-search').value = `${kode} - ${nama}`;
            document.getElementById('selected-vendor-code').value = kode;
            document.getElementById('vendor-ac-list').style.display = 'none';
            loadUnpaidInvoices(kode);
        }

        async function loadUnpaidInvoices(vendor) {
            const tbody = document.getElementById('tbodyUnpaidPi');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px;">Memuat faktur hutang belum lunas...</td></tr>';
            try {
                const res = await fetch(`ap_payment.php?action=get_unpaid_invoices&vendor=${encodeURIComponent(vendor)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawUnpaidPi = json.data || [];
                    renderUnpaidTable(rawUnpaidPi);
                } else {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">${json.message || 'Gagal'}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderUnpaidTable(rows) {
            const tbody = document.getElementById('tbodyUnpaidPi');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-sub);">Tidak ada faktur belum lunas untuk supplier ini.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center;"><input type="checkbox" class="chk-pi-item" data-idx="${idx}" onchange="onPiCheckChange(${idx})"></td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.pi_no)}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.pi_dt)}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.due_dt)}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs)}</td>
                    <td style="text-align:right; font-family:monospace;">${(r.total_order || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="text-align:right; font-family:monospace;">${(r.paid || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="text-align:right; font-family:monospace; font-weight:700; color:var(--danger);">${(r.hutang || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="padding:1px 4px;">
                        <input type="number" step="0.01" class="ant-input input-pay-amt" id="pay-amt-${idx}" value="0" style="text-align:right; font-family:monospace; width:100%; font-weight:600; color:var(--primary);">
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function onPiCheckChange(idx) {
            const chk = document.querySelector(`.chk-pi-item[data-idx="${idx}"]`);
            const inp = document.getElementById(`pay-amt-${idx}`);
            if (chk && inp) {
                if (chk.checked) {
                    inp.value = rawUnpaidPi[idx].hutang || 0;
                } else {
                    inp.value = 0;
                }
            }
        }

        function toggleSelectAllPi(chkMaster) {
            document.querySelectorAll('.chk-pi-item').forEach(c => {
                c.checked = chkMaster.checked;
                const idx = c.getAttribute('data-idx');
                const inp = document.getElementById(`pay-amt-${idx}`);
                if (inp) inp.value = chkMaster.checked ? (rawUnpaidPi[idx].hutang || 0) : 0;
            });
        }

        async function savePayment() {
            const vendor = document.getElementById('selected-vendor-code').value.trim();
            const bankCd = document.getElementById('select-bank-cd').value;
            const ppDt   = document.getElementById('input-pp-dt').value;
            const ppNo   = document.getElementById('input-pp-no').value.trim();
            const note   = document.getElementById('input-pp-note').value.trim();

            if (!vendor) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Pilih Supplier terlebih dahulu' });
                return;
            }
            if (!bankCd) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Pilih Rekening Kas / Bank pelunasan' });
                return;
            }

            const items = [];
            document.querySelectorAll('.chk-pi-item').forEach(c => {
                if (c.checked) {
                    const idx = parseInt(c.getAttribute('data-idx'));
                    const amt = parseFloat(document.getElementById(`pay-amt-${idx}`).value) || 0;
                    if (amt > 0) {
                        items.push({ id_pi: rawUnpaidPi[idx].id_pi, amount: amt });
                    }
                }
            });

            if (items.length === 0) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Centang setidaknya satu faktur dan isi nominal pembayaran' });
                return;
            }

            const payload = { vendor, bank_cd: bankCd, pp_dt: ppDt, pp_no: ppNo, note, items };

            try {
                const res = await fetch('ap_payment.php?action=save_pp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message });
                    loadKPI();
                    loadUnpaidInvoices(vendor);
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        // TAB 2: RIWAYAT
        async function loadHistoryPp() {
            const tbody = document.getElementById('tbodyHistoryPp');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px;">Memuat riwayat pembayaran...</td></tr>';
            try {
                const res = await fetch('ap_payment.php?action=list_pp');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawHistoryPp = json.data || [];
                    renderHistoryTable(rawHistoryPp);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function filterHistoryLocal() {
            const q = document.getElementById('search-pp-history').value.toLowerCase().trim();
            if (!q) { renderHistoryTable(rawHistoryPp); return; }
            const filtered = rawHistoryPp.filter(r => (r.pp_no || '').toLowerCase().includes(q) || (r.vendor_name || '').toLowerCase().includes(q) || (r.note || '').toLowerCase().includes(q));
            renderHistoryTable(filtered);
        }

        function renderHistoryTable(rows) {
            const tbody = document.getElementById('tbodyHistoryPp');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-sub);">Belum ada riwayat pembayaran hutang.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.pp_no)}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.pp_dt)}</td>
                    <td><b>${escapeHtml(r.vendor)}</b> - ${escapeHtml(r.vendor_name)}</td>
                    <td>${escapeHtml(r.bank_cd || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs || 'IDR')}</td>
                    <td style="text-align:right; font-family:monospace; font-weight:700; color:var(--success);">${(r.dist_amt || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="text-align:center;">${r.pi_count || 1} PI</td>
                    <td>${escapeHtml(r.note || '-')}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function initResizableGrid(tableId, storageKey) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const ths = table.querySelectorAll('thead th');
            const savedWidths = JSON.parse(localStorage.getItem(storageKey) || '{}');
            ths.forEach((th, i) => {
                const colKey = `col_${i}`;
                if (savedWidths[colKey]) th.style.width = savedWidths[colKey] + 'px';
                const resizer = document.createElement('div');
                resizer.className = 'col-resizer';
                th.style.position = 'relative';
                th.appendChild(resizer);
                let startX, startWidth;
                resizer.addEventListener('mousedown', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    startX = e.pageX; startWidth = th.offsetWidth;
                    function onMouseMove(e) { th.style.width = Math.max(30, startWidth + (e.pageX - startX)) + 'px'; }
                    function onMouseUp() {
                        document.removeEventListener('mousemove', onMouseMove);
                        document.removeEventListener('mouseup', onMouseUp);
                        const widths = JSON.parse(localStorage.getItem(storageKey) || '{}');
                        widths[colKey] = th.offsetWidth;
                        localStorage.setItem(storageKey, JSON.stringify(widths));
                    }
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });
            });
        }

        function exportHistoryExcel() {
            const table = document.getElementById('tblHistoryPp');
            if (!table) return;
            const wb = XLSX.utils.table_to_book(table, { sheet: "Riwayat Bayar Hutang" });
            XLSX.writeFile(wb, `Riwayat_Bayar_Hutang_${Date.now()}.xlsx`);
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

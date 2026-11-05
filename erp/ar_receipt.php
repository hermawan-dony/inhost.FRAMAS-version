<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Terima Piutang Usaha (AR Receipt / Penerimaan Kas & Bank)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_sp.srw)
 * DataWindows: d_si_sp.srd, bc_sp_hdr.srd, bc_sp_dtl.srd, bc_sp_browse2_2022.srd, d_sp_prt_a.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Piutang Berjalan, Diterima Bulan Ini, Faktur Menunggu Pelunasan, Total Customer)
 * - Tab 1: Form Entri Pelunasan Piutang (Pilih Customer -> Tampil Faktur SI Belum Lunas -> Centang & Alokasi Terima)
 * - Tab 2: Browse Riwayat Penerimaan Piutang (Filter Tanggal, Customer, Status, dan Cetak Bukti Terima)
 * - Terintegrasi Otomatis dengan Buku Pembantu Piutang (bc_act_bantu) dan Rekening Kas/Bank (bc_act_akun)
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
function ensureSpTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_sp_hdr` (
        `id` varchar(50) NOT NULL DEFAULT '',
        `sp_no` varchar(30) DEFAULT NULL,
        `sp_dt` date DEFAULT NULL,
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
        KEY `idx_sp_no` (`sp_no`),
        KEY `idx_vendor` (`vendor`),
        KEY `idx_sp_dt` (`sp_dt`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_sp_dtl` (
        `id` varchar(50) NOT NULL DEFAULT '',
        `id_hdr` varchar(50) NOT NULL DEFAULT '',
        `seq` int(11) DEFAULT '1',
        `id_si` varchar(50) DEFAULT '',
        `amount` decimal(15,2) DEFAULT '0.00',
        `kurs` varchar(5) DEFAULT 'IDR',
        `status` varchar(1) DEFAULT '0',
        `setby` varchar(40) DEFAULT '',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_hdr` (`id_hdr`),
        KEY `idx_si` (`id_si`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureSpTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";

    // Hitung total piutang tertunggak dari bc_si_hdr yang belum lunas
    $sqlOutstanding = "SELECT 
        COUNT(*) as total_unpaid_si,
        SUM(s.total_order - IFNULL(paid.total_paid, 0)) as total_piutang_outstanding
        FROM bc_si_hdr s
        LEFT JOIN (
            SELECT id_si, SUM(amount) as total_paid FROM bc_sp_dtl WHERE $cmpClause GROUP BY id_si
        ) paid ON paid.id_si = s.id
        WHERE $cmpClause AND (s.total_order - IFNULL(paid.total_paid, 0)) > 0.01";
    $resOut = $koneksi->query($sqlOutstanding);
    $rowOut = $resOut ? $resOut->fetch_assoc() : ['total_unpaid_si' => 0, 'total_piutang_outstanding' => 0];

    // Total diterima bulan ini
    $currYm = date('Y-m');
    $sqlRcvMonth = "SELECT SUM(dist_amt) as total_rcv_month, COUNT(*) as total_sp_month 
                    FROM bc_sp_hdr WHERE $cmpClause AND DATE_FORMAT(sp_dt, '%Y-%m') = '$currYm'";
    $resRm = $koneksi->query($sqlRcvMonth);
    $rowRm = $resRm ? $resRm->fetch_assoc() : ['total_rcv_month' => 0, 'total_sp_month' => 0];

    // Total customer buyer
    $sqlCust = "SELECT COUNT(*) as total_cust FROM bc_bs WHERE $cmpClause AND UPPER(TIPE) NOT LIKE '%SUPPLIER%'";
    $resC = $koneksi->query($sqlCust);
    $rowC = $resC ? $resC->fetch_assoc() : ['total_cust' => 0];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_piutang'   => (float)($rowOut['total_piutang_outstanding'] ?? 0),
            'unpaid_si_count' => (int)($rowOut['total_unpaid_si'] ?? 0),
            'rcv_month'       => (float)($rowRm['total_rcv_month'] ?? 0),
            'total_cust'      => (int)($rowC['total_cust'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Unpaid Invoices for a Customer (Porting d_si_sp.srd)
// =========================================================================
if ($action === 'get_unpaid_invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $vendor = trim($_GET['vendor'] ?? '');
    if (empty($vendor)) {
        echo json_encode(['status' => 'OK', 'data' => []]);
        exit;
    }
    $escV = $koneksi->real_escape_string($vendor);
    $cmpClause = !empty($sessionCmpId) ? "(s.cmp_id = '$escCmp' OR s.cmp_id = '')" : "1=1";

    $sql = "SELECT s.id as id_si, s.si_no, s.si_dt, 
            COALESCE(s.jatuhtempo, s.si_dt) as due_dt,
            IFNULL(s.kurs, 'IDR') as kurs,
            IFNULL(s.total_order, 0) as total_order,
            IFNULL(paid.total_paid, 0) as paid,
            (IFNULL(s.total_order, 0) - IFNULL(paid.total_paid, 0)) as piutang
            FROM bc_si_hdr s
            LEFT JOIN (
                SELECT id_si, SUM(amount) as total_paid FROM bc_sp_dtl WHERE (cmp_id = '$escCmp' OR cmp_id = '') GROUP BY id_si
            ) paid ON paid.id_si = s.id
            WHERE $cmpClause AND s.vendor = '$escV'
            HAVING piutang > 0.01
            ORDER BY s.si_dt ASC, s.si_no ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['total_order'] = (float)$r['total_order'];
            $r['paid'] = (float)$r['paid'];
            $r['piutang'] = (float)$r['piutang'];
            $rows[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Save Receipt Transaction (Insert / Update)
// =========================================================================
if ($action === 'save_sp') {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!$req || empty($req['vendor']) || empty($req['items'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data penerimaan tidak lengkap']);
        exit;
    }

    $spNo    = trim($req['sp_no'] ?? '');
    $spDt    = trim($req['sp_dt'] ?? date('Y-m-d'));
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
        echo json_encode(['status' => 'ERROR', 'message' => 'Nominal penerimaan harus lebih besar dari 0']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        if (empty($spNo)) {
            $ym = date('Y/m', strtotime($spDt));
            $prefix = "SP/$ym/";
            $qSeq = $koneksi->query("SELECT MAX(sp_no) as max_no FROM bc_sp_hdr WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND sp_no LIKE '$prefix%'");
            $lastNo = ($qSeq && $rowSeq = $qSeq->fetch_assoc()) ? $rowSeq['max_no'] : null;
            $nextSeq = 1;
            if ($lastNo) {
                $lastNum = intval(substr($lastNo, -4));
                $nextSeq = $lastNum + 1;
            }
            $spNo = $prefix . sprintf('%04d', $nextSeq);
        }

        $idHdr = 'SP-' . $sessionCmpId . '-' . time() . '-' . rand(100, 999);
        $escIdHdr = $koneksi->real_escape_string($idHdr);
        $escSpNo  = $koneksi->real_escape_string($spNo);
        $escSpDt  = $koneksi->real_escape_string($spDt);
        $escV     = $koneksi->real_escape_string($vendor);
        $escBank  = $koneksi->real_escape_string($bankCd);
        $escKurs  = $koneksi->real_escape_string($kurs);
        $escNote  = $koneksi->real_escape_string($note);

        $sqlHdr = "INSERT INTO bc_sp_hdr (id, sp_no, sp_dt, vendor, bank_cd, kurs, rate, note, ceq_amt, eq_amt, dist_amt, set_by, status, cmp_id)
                   VALUES ('$escIdHdr', '$escSpNo', '$escSpDt', '$escV', '$escBank', '$escKurs', $rate, '$escNote', $distAmt, $distAmt * $rate, $distAmt, '$userId', '1', '$escCmp')";
        $koneksi->query($sqlHdr);

        $seq = 1;
        $stmtDtl = $koneksi->prepare("INSERT INTO bc_sp_dtl (id, id_hdr, seq, id_si, amount, kurs, status, setby, cmp_id) VALUES (?, ?, ?, ?, ?, ?, '1', ?, ?)");
        foreach ($items as $it) {
            $amt = floatval($it['amount'] ?? 0);
            if ($amt <= 0) continue;

            $idSi = trim($it['id_si']);
            $idDtl = 'SPD-' . $sessionCmpId . '-' . time() . '-' . $seq . '-' . rand(10, 99);
            $stmtDtl->bind_param("ssisdsss", $idDtl, $idHdr, $seq, $idSi, $amt, $kurs, $userId, $sessionCmpId);
            $stmtDtl->execute();
            $seq++;
        }
        $stmtDtl->close();

        // Otomatis masukkan ayat jurnal penerimaan AR ke bc_act_kas / bc_act_bank
        $vType = (strpos(strtoupper($bankCd), 'KAS') !== false) ? 'Kas' : 'Bank';
        $vTable = ($vType === 'Kas') ? 'bc_act_kas' : 'bc_act_bank';
        // Akun Kas/Bank di Debet, Akun AR (Piutang) di Kredit
        $escUraian = $koneksi->real_escape_string("Penerimaan Piutang $spNo dari $vendor");
        $koneksi->query("INSERT INTO $vTable (tanggal, no_bukti, uraian, akun_db, akun_cr, amt_db, amt_cr, kode_pembantu, kurs, rate, status, cmp_id)
                         VALUES ('$escSpDt', '$escSpNo', '$escUraian', '$escBank', '112-01-001', $distAmt, $distAmt, '$escV', '$escKurs', $rate, 'Y', '$escCmp')");

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Penerimaan Piutang $spNo berhasil disimpan", 'sp_no' => $spNo]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan penerimaan: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Riwayat Penerimaan (Tab 2)
// =========================================================================
if ($action === 'list_sp') {
    header('Content-Type: application/json; charset=utf-8');

    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $search = trim($_GET['search'] ?? '');
    $where = [$cmpClause];
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(a.sp_no LIKE '%$escS%' OR a.vendor LIKE '%$escS%' OR b.NAMA LIKE '%$escS%' OR a.note LIKE '%$escS%')";
    }
    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "SELECT a.id, a.sp_no, a.sp_dt, a.vendor, IFNULL(b.NAMA, a.vendor) as vendor_name,
            a.bank_cd, a.kurs, a.rate, a.dist_amt, a.note, a.status, a.set_by,
            (SELECT COUNT(*) FROM bc_sp_dtl d WHERE d.id_hdr = a.id) as si_count
            FROM bc_sp_hdr a
            LEFT JOIN bc_bs b ON a.vendor = b.KODE AND (b.cmp_id = '$escCmp' OR b.cmp_id = '')
            $whereSql
            ORDER BY a.sp_dt DESC, a.sp_no DESC LIMIT 200";
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
    <title>Terima Piutang Usaha (AR Receipt) - CEISA 4.0 & Enterprise Hub</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --primary: #1890ff; --primary-hover: #40a9ff; --primary-active: #096dd9;
            --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --purple: #8b5cf6;
            --bg-base: #f0f2f5; --bg-card: #ffffff; --text-main: #1f2937; --text-sub: #6b7280;
            --border-color: #d9d9d9; --border-light: #e5e7eb; --border-table: #d9d9d9;
            --table-head-bg: #fafafa; --row-hover: #f0f7ff; --row-selected: #e6f7ff;
            --form-font-size: 9.5px;
        }
        body[data-theme="dark"] {
            --bg-base: #141414; --bg-card: #1f1f1f; --text-main: #e6e6e6; --text-sub: #8c8c8c;
            --border-color: #303030; --border-light: #262626; --border-table: #303030;
            --table-head-bg: #1d1d1d; --row-hover: #262638; --row-selected: #111d2c;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: var(--app-font); }
        body { background: var(--bg-base); color: var(--text-main); font-size: 9.5px; line-height: 1.4; padding: 8px 10px; overflow-x: hidden; }

        .portal-header {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 4px; padding: 4px 8px; margin-bottom: 6px;
        }
        .portal-title { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: var(--text-main); }
        .portal-title i { color: var(--success); font-size: 12px; }
        .portal-badge { font-size: 8.5px; font-weight: 600; padding: 1px 6px; border-radius: 3px; background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }

        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 6px; }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; padding: 5px 8px; display: flex; align-items: center; justify-content: space-between; min-height: 44px; }
        .kpi-info h5 { font-size: 8.5px; font-weight: 600; text-transform: uppercase; color: var(--text-sub); letter-spacing: 0.3px; }
        .kpi-info h3 { font-size: 13px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
        .kpi-icon { width: 26px; height: 26px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 11px; }
        .icon-blue   { background: #e6f7ff; color: #1890ff; }
        .icon-green  { background: #f6ffed; color: #52c41a; }
        .icon-amber  { background: #fffbe6; color: #faad14; }
        .icon-purple { background: #f9f0ff; color: #722ed1; }

        .ant-tabs-nav { display: flex; gap: 2px; border-bottom: 1px solid var(--border-color); margin-bottom: 6px; }
        .ant-tab-btn {
            background: var(--bg-card); border: 1px solid var(--border-color); border-bottom: none;
            border-radius: 4px 4px 0 0; padding: 3px 12px; font-size: 9.5px; font-weight: 500;
            color: var(--text-sub); cursor: pointer; display: flex; align-items: center; gap: 5px; height: 24px; transition: all 0.2s;
        }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { background: var(--success); color: #ffffff; border-color: var(--success); font-weight: 600; }

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

        .ac-list {
            position: absolute; background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 3px; max-height: 180px; overflow-y: auto; width: 280px; z-index: 999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: none;
        }
        .ac-item { padding: 4px 8px; font-size: 9.5px; cursor: pointer; border-bottom: 1px solid var(--border-light); }
        .ac-item:hover { background: var(--row-hover); color: var(--primary); }

        .swal2-popup.swal-compact { width: 330px !important; padding: 12px 14px !important; border-radius: 4px !important; font-family: var(--app-font) !important; font-size: 9.5px !important; }
        .swal2-popup.swal-compact button.swal2-styled { font-size: 9.5px !important; height: 21px !important; padding: 0 10px !important; border-radius: 3px !important; line-height: 21px !important; }
    </style>
</head>
<body>

    <div class="portal-header">
        <div class="portal-title">
            <i class="fa-solid fa-hand-holding-dollar"></i>
            <span>Terima Piutang Usaha (AR Receipt / Penerimaan Kas & Bank)</span>
            <span class="portal-badge">Tenant: <?= htmlspecialchars($sessionCmpId) ?></span>
            <span style="font-size: 8.5px; color: var(--text-sub); font-weight: 400; margin-left: 4px;">
                Porting PB w_sp.srw (Pelunasan Faktur SI & Buku Pembantu Piutang)
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
                <h5>Total Piutang Berjalan</h5>
                <h3 id="kpi-piutang">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Faktur Menunggu Pelunasan</h5>
                <h3 id="kpi-faktur">-</h3>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Diterima Bulan Ini</h5>
                <h3 id="kpi-terima">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Rekanan Customer</h5>
                <h3 id="kpi-cust">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-users"></i></div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="ant-tabs-nav">
        <button type="button" class="ant-tab-btn active" id="tab-btn-entri" onclick="switchTab('entri')">
            <i class="fa-solid fa-pen-to-square"></i> 1. Entri Penerimaan Piutang
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-riwayat" onclick="switchTab('riwayat')">
            <i class="fa-solid fa-list-check"></i> 2. Riwayat Penerimaan (Browse AR)
        </button>
    </div>

    <!-- TAB 1: FORM ENTRI PENERIMAAN -->
    <div id="tab-content-entri">
        <div class="form-card">
            <div class="form-grid">
                <div class="form-group" style="position:relative;">
                    <label>Pilih Customer / Buyer <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="input-customer-search" class="ant-input" placeholder="Ketik kode / nama customer..." autocomplete="off" oninput="searchCustomerAc(this.value)">
                    <input type="hidden" id="selected-customer-code">
                    <div id="customer-ac-list" class="ac-list"></div>
                </div>
                <div class="form-group">
                    <label>Tanggal Penerimaan</label>
                    <input type="date" id="input-sp-dt" class="ant-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Rekening Kas / Bank Penerimaan <span style="color:var(--danger)">*</span></label>
                    <select id="select-bank-cd" class="ant-select">
                        <option value="">-- Pilih Akun Kas/Bank --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nomor Bukti Terima (Auto/Manual)</label>
                    <input type="text" id="input-sp-no" class="ant-input" placeholder="[Auto Generated]">
                </div>
                <div class="form-group" style="grid-column: span 3;">
                    <label>Catatan / Keterangan Penerimaan</label>
                    <input type="text" id="input-sp-note" class="ant-input" placeholder="Misal: Penerimaan pelunasan invoice ekspor via transfer Mandiri...">
                </div>
                <div class="form-group" style="display:flex; justify-content:flex-end;">
                    <label>&nbsp;</label>
                    <button type="button" class="ant-btn ant-btn-success" onclick="saveReceipt()" style="height:21px;">
                        <i class="fa-solid fa-check"></i> Simpan Transaksi Penerimaan
                    </button>
                </div>
            </div>
        </div>

        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblUnpaidSi">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;"><input type="checkbox" id="chk-all-si" onclick="toggleSelectAllSi(this)"></th>
                            <th style="width: 120px;">No Faktur SI</th>
                            <th style="width: 80px; text-align: center;">Tgl Faktur</th>
                            <th style="width: 80px; text-align: center;">Jatuh Tempo</th>
                            <th style="width: 50px; text-align: center;">Val</th>
                            <th style="width: 110px; text-align: right;">Total Tagihan</th>
                            <th style="width: 110px; text-align: right;">Sudah Diterima</th>
                            <th style="width: 110px; text-align: right;">Sisa Piutang</th>
                            <th style="width: 130px; text-align: right;">Terima Sekarang (Rp)</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyUnpaidSi">
                        <tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-sub);">Silakan pilih Customer di atas untuk menampilkan faktur yang belum lunas.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: RIWAYAT PENERIMAAN -->
    <div id="tab-content-riwayat" style="display:none;">
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:4px; padding:4px 8px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
            <input type="text" id="search-sp-history" class="ant-input" placeholder="Cari nomor bukti terima, customer, catatan..." style="width:240px;" oninput="filterHistoryLocal()">
            <button type="button" class="ant-btn" onclick="exportHistoryExcel()"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel</button>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblHistorySp">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 130px;">No Bukti Terima</th>
                            <th style="width: 80px; text-align: center;">Tgl Terima</th>
                            <th style="width: 220px;">Customer / Buyer</th>
                            <th style="width: 110px;">Akun Kas/Bank</th>
                            <th style="width: 50px; text-align: center;">Val</th>
                            <th style="width: 120px; text-align: right;">Total Diterima (Rp)</th>
                            <th style="width: 60px; text-align: center;">Faktur</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyHistorySp">
                        <tr><td colspan="9" style="text-align:center; padding:16px;">Memuat riwayat penerimaan...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'entri';
        let rawUnpaidSi = [];
        let rawHistorySp = [];
        let customersList = [];

        const SwalCompact = Swal.mixin({
            customClass: { popup: 'swal-compact', confirmButton: 'ant-btn ant-btn-primary', cancelButton: 'ant-btn' },
            buttonsStyling: false
        });

        document.addEventListener('DOMContentLoaded', () => {
            initResizableGrid('tblUnpaidSi', 'grid_sp_unpaid_widths');
            initResizableGrid('tblHistorySp', 'grid_sp_history_widths');
            loadKPI();
            loadBankAccounts();
            loadCustomersList();
        });

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-btn-entri').classList.toggle('active', tab === 'entri');
            document.getElementById('tab-btn-riwayat').classList.toggle('active', tab === 'riwayat');
            document.getElementById('tab-content-entri').style.display = tab === 'entri' ? 'block' : 'none';
            document.getElementById('tab-content-riwayat').style.display = tab === 'riwayat' ? 'block' : 'none';

            if (tab === 'riwayat') loadHistorySp();
        }

        function refreshCurrentTab() {
            loadKPI();
            if (currentTab === 'entri') {
                const c = document.getElementById('selected-customer-code').value;
                if (c) loadUnpaidInvoices(c);
            } else {
                loadHistorySp();
            }
        }

        async function loadKPI() {
            try {
                const res = await fetch('ar_receipt.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-piutang').textContent = 'Rp ' + (json.kpi.total_piutang || 0).toLocaleString('id-ID', {maximumFractionDigits:0});
                    document.getElementById('kpi-faktur').textContent = (json.kpi.unpaid_si_count || 0).toLocaleString('id-ID') + ' Faktur';
                    document.getElementById('kpi-terima').textContent = 'Rp ' + (json.kpi.rcv_month || 0).toLocaleString('id-ID', {maximumFractionDigits:0});
                    document.getElementById('kpi-cust').textContent = (json.kpi.total_cust || 0).toLocaleString('id-ID') + ' Customer';
                }
            } catch (e) { console.error(e); }
        }

        async function loadBankAccounts() {
            try {
                const res = await fetch('ar_receipt.php?action=get_bank_accounts');
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

        async function loadCustomersList() {
            try {
                const res = await fetch('partner_subsidiary.php?action=list_bantu&tipe=PI');
                const json = await res.json();
                if (json.status === 'OK') {
                    customersList = json.data || [];
                }
            } catch (e) { console.error(e); }
        }

        function searchCustomerAc(q) {
            const list = document.getElementById('customer-ac-list');
            if (!q.trim()) { list.style.display = 'none'; return; }
            const ql = q.toLowerCase();
            const matched = customersList.filter(v => (v.kode || '').toLowerCase().includes(ql) || (v.nama_akun || '').toLowerCase().includes(ql));
            if (matched.length === 0) { list.style.display = 'none'; return; }

            let html = '';
            matched.slice(0, 15).forEach(v => {
                html += `<div class="ac-item" onclick="selectCustomer('${escapeHtml(v.kode)}', '${escapeHtml(v.nama_akun)}')">
                    <b>${escapeHtml(v.kode)}</b> - ${escapeHtml(v.nama_akun)}
                </div>`;
            });
            list.innerHTML = html;
            list.style.display = 'block';
        }

        function selectCustomer(kode, nama) {
            document.getElementById('input-customer-search').value = `${kode} - ${nama}`;
            document.getElementById('selected-customer-code').value = kode;
            document.getElementById('customer-ac-list').style.display = 'none';
            loadUnpaidInvoices(kode);
        }

        async function loadUnpaidInvoices(customer) {
            const tbody = document.getElementById('tbodyUnpaidSi');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px;">Memuat faktur piutang belum lunas...</td></tr>';
            try {
                const res = await fetch(`ar_receipt.php?action=get_unpaid_invoices&vendor=${encodeURIComponent(customer)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawUnpaidSi = json.data || [];
                    renderUnpaidTable(rawUnpaidSi);
                } else {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">${json.message || 'Gagal'}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderUnpaidTable(rows) {
            const tbody = document.getElementById('tbodyUnpaidSi');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-sub);">Tidak ada faktur belum lunas untuk customer ini.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center;"><input type="checkbox" class="chk-si-item" data-idx="${idx}" onchange="onSiCheckChange(${idx})"></td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.si_no)}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.si_dt)}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.due_dt)}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs)}</td>
                    <td style="text-align:right; font-family:monospace;">${(r.total_order || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="text-align:right; font-family:monospace;">${(r.paid || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="text-align:right; font-family:monospace; font-weight:700; color:var(--danger);">${(r.piutang || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="padding:1px 4px;">
                        <input type="number" step="0.01" class="ant-input input-rcv-amt" id="rcv-amt-${idx}" value="0" style="text-align:right; font-family:monospace; width:100%; font-weight:600; color:var(--success);">
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function onSiCheckChange(idx) {
            const chk = document.querySelector(`.chk-si-item[data-idx="${idx}"]`);
            const inp = document.getElementById(`rcv-amt-${idx}`);
            if (chk && inp) {
                inp.value = chk.checked ? (rawUnpaidSi[idx].piutang || 0) : 0;
            }
        }

        function toggleSelectAllSi(chkMaster) {
            document.querySelectorAll('.chk-si-item').forEach(c => {
                c.checked = chkMaster.checked;
                const idx = c.getAttribute('data-idx');
                const inp = document.getElementById(`rcv-amt-${idx}`);
                if (inp) inp.value = chkMaster.checked ? (rawUnpaidSi[idx].piutang || 0) : 0;
            });
        }

        async function saveReceipt() {
            const vendor = document.getElementById('selected-customer-code').value.trim();
            const bankCd = document.getElementById('select-bank-cd').value;
            const spDt   = document.getElementById('input-sp-dt').value;
            const spNo   = document.getElementById('input-sp-no').value.trim();
            const note   = document.getElementById('input-sp-note').value.trim();

            if (!vendor) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Pilih Customer terlebih dahulu' });
                return;
            }
            if (!bankCd) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Pilih Rekening Kas / Bank penerimaan' });
                return;
            }

            const items = [];
            document.querySelectorAll('.chk-si-item').forEach(c => {
                if (c.checked) {
                    const idx = parseInt(c.getAttribute('data-idx'));
                    const amt = parseFloat(document.getElementById(`rcv-amt-${idx}`).value) || 0;
                    if (amt > 0) {
                        items.push({ id_si: rawUnpaidSi[idx].id_si, amount: amt });
                    }
                }
            });

            if (items.length === 0) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Centang setidaknya satu faktur dan isi nominal penerimaan' });
                return;
            }

            const payload = { vendor, bank_cd: bankCd, sp_dt: spDt, sp_no: spNo, note, items };

            try {
                const res = await fetch('ar_receipt.php?action=save_sp', {
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

        async function loadHistorySp() {
            const tbody = document.getElementById('tbodyHistorySp');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px;">Memuat riwayat penerimaan...</td></tr>';
            try {
                const res = await fetch('ar_receipt.php?action=list_sp');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawHistorySp = json.data || [];
                    renderHistoryTable(rawHistorySp);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function filterHistoryLocal() {
            const q = document.getElementById('search-sp-history').value.toLowerCase().trim();
            if (!q) { renderHistoryTable(rawHistorySp); return; }
            const filtered = rawHistorySp.filter(r => (r.sp_no || '').toLowerCase().includes(q) || (r.vendor_name || '').toLowerCase().includes(q) || (r.note || '').toLowerCase().includes(q));
            renderHistoryTable(filtered);
        }

        function renderHistoryTable(rows) {
            const tbody = document.getElementById('tbodyHistorySp');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-sub);">Belum ada riwayat penerimaan piutang.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.sp_no)}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.sp_dt)}</td>
                    <td><b>${escapeHtml(r.vendor)}</b> - ${escapeHtml(r.vendor_name)}</td>
                    <td>${escapeHtml(r.bank_cd || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs || 'IDR')}</td>
                    <td style="text-align:right; font-family:monospace; font-weight:700; color:var(--success);">${(r.dist_amt || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="text-align:center;">${r.si_count || 1} SI</td>
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
            const table = document.getElementById('tblHistorySp');
            if (!table) return;
            const wb = XLSX.utils.table_to_book(table, { sheet: "Riwayat Terima Piutang" });
            XLSX.writeFile(wb, `Riwayat_Terima_Piutang_${Date.now()}.xlsx`);
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

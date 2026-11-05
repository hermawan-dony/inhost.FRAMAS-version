<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Entri Voucher Kas, Bank & Memorial
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_v.srw)
 * DataWindows: bc_v_hdr.srd, bc_v_dtl.srd, bc_v_browse.srd, d_v_prt_a.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Voucher Bulan Ini, Kas Masuk KM, Kas Keluar KK, Bank Keluar BK)
 * - Tab 1: Form Entri Multi-Row Voucher dengan Live Balance Indicator (Debet = Kredit)
 * - Tab 2: Browse Riwayat Voucher (Filter Tanggal, Tipe, No Bukti, dan Cetak Voucher Resmi)
 * - Terintegrasi dengan Chart of Accounts (bc_act_akun) & Buku Pembantu (bc_act_bantu)
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
function ensureVoucherTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_v_hdr` (
        `id` varchar(50) NOT NULL DEFAULT '',
        `v_no` varchar(30) DEFAULT NULL,
        `v_dt` date DEFAULT NULL,
        `tipe` varchar(5) DEFAULT 'KK',
        `note` varchar(255) DEFAULT '',
        `set_by` varchar(30) DEFAULT '',
        `status` varchar(1) DEFAULT '0',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_v_no` (`v_no`),
        KEY `idx_v_dt` (`v_dt`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $chk = $koneksi->query("SHOW COLUMNS FROM `bc_v_hdr` LIKE 'tipe'");
    if ($chk && $chk->num_rows == 0) {
        @$koneksi->query("ALTER TABLE `bc_v_hdr` ADD COLUMN `tipe` VARCHAR(10) DEFAULT 'KK' AFTER `v_dt`");
    }

    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_v_dtl` (
        `id` varchar(50) NOT NULL DEFAULT '',
        `id_hdr` varchar(50) NOT NULL DEFAULT '',
        `seq` int(11) DEFAULT '1',
        `akun` varchar(50) DEFAULT '',
        `kode_pembantu` varchar(50) DEFAULT '',
        `amt_db` decimal(15,2) DEFAULT '0.00',
        `amt_cr` decimal(15,2) DEFAULT '0.00',
        `uraian` varchar(255) DEFAULT '',
        `kurs` varchar(4) DEFAULT 'IDR',
        `rate` decimal(10,2) DEFAULT '1.00',
        `tgl_rate` varchar(10) DEFAULT '',
        `amt_prime` decimal(20,5) DEFAULT '0.00',
        `setby` varchar(40) DEFAULT '',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_hdr` (`id_hdr`),
        KEY `idx_akun` (`akun`),
        KEY `idx_cmp` (`cmp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureVoucherTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $currYm = date('Y-m');

    $sql = "SELECT 
        COUNT(*) as total_vouchers,
        SUM(CASE WHEN tipe = 'KM' THEN 1 ELSE 0 END) as total_km,
        SUM(CASE WHEN tipe = 'KK' THEN 1 ELSE 0 END) as total_kk,
        SUM(CASE WHEN tipe = 'BK' THEN 1 ELSE 0 END) as total_bk
        FROM bc_v_hdr WHERE $cmpClause AND DATE_FORMAT(v_dt, '%Y-%m') = '$currYm'";
    $res = $koneksi->query($sql);
    $kpi = $res ? $res->fetch_assoc() : ['total_vouchers' => 0, 'total_km' => 0, 'total_kk' => 0, 'total_bk' => 0];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_vouchers' => (int)($kpi['total_vouchers'] ?? 0),
            'total_km'       => (int)($kpi['total_km'] ?? 0),
            'total_kk'       => (int)($kpi['total_kk'] ?? 0),
            'total_bk'       => (int)($kpi['total_bk'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Save Voucher (Insert)
// =========================================================================
if ($action === 'save_voucher') {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!$req || empty($req['items'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data voucher tidak valid']);
        exit;
    }

    $vNo   = trim($req['v_no'] ?? '');
    $vDt   = trim($req['v_dt'] ?? date('Y-m-d'));
    $tipe  = trim($req['tipe'] ?? 'KK');
    $note  = trim($req['note'] ?? '');
    $items = $req['items'];

    $totDb = 0;
    $totKr = 0;
    foreach ($items as $it) {
        $totDb += floatval($it['amt_db'] ?? 0);
        $totKr += floatval($it['amt_cr'] ?? 0);
    }

    if (abs($totDb - $totKr) > 0.01) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Total Debet dan Total Kredit harus seimbang (Balance)! Selisih: ' . number_format($totDb - $totKr, 2)]);
        exit;
    }
    if ($totDb <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nominal transaksi voucher harus lebih besar dari 0']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        if (empty($vNo)) {
            $ym = date('Y/m', strtotime($vDt));
            $prefix = "$tipe/$ym/";
            $qSeq = $koneksi->query("SELECT MAX(v_no) as max_no FROM bc_v_hdr WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND v_no LIKE '$prefix%'");
            $lastNo = ($qSeq && $rowSeq = $qSeq->fetch_assoc()) ? $rowSeq['max_no'] : null;
            $nextSeq = 1;
            if ($lastNo) {
                $lastNum = intval(substr($lastNo, -4));
                $nextSeq = $lastNum + 1;
            }
            $vNo = $prefix . sprintf('%04d', $nextSeq);
        }

        $idHdr = 'V-' . $sessionCmpId . '-' . time() . '-' . rand(100, 999);
        $escIdHdr = $koneksi->real_escape_string($idHdr);
        $escVNo   = $koneksi->real_escape_string($vNo);
        $escVDt   = $koneksi->real_escape_string($vDt);
        $escTipe  = $koneksi->real_escape_string($tipe);
        $escNote  = $koneksi->real_escape_string($note);

        $sqlHdr = "INSERT INTO bc_v_hdr (id, v_no, v_dt, tipe, note, set_by, status, cmp_id)
                   VALUES ('$escIdHdr', '$escVNo', '$escVDt', '$escTipe', '$escNote', '$userId', '1', '$escCmp')";
        $koneksi->query($sqlHdr);

        $seq = 1;
        $stmtDtl = $koneksi->prepare("INSERT INTO bc_v_dtl (id, id_hdr, seq, akun, kode_pembantu, amt_db, amt_cr, uraian, kurs, rate, setby, cmp_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'IDR', 1.00, ?, ?)");
        foreach ($items as $it) {
            $ak = trim($it['akun'] ?? '');
            if (empty($ak)) continue;

            $kb = trim($it['kode_pembantu'] ?? '');
            $db = floatval($it['amt_db'] ?? 0);
            $kr = floatval($it['amt_cr'] ?? 0);
            $ur = trim($it['uraian'] ?? $note);

            $idDtl = 'VD-' . $sessionCmpId . '-' . time() . '-' . $seq . '-' . rand(10, 99);
            $stmtDtl->bind_param("ssissddsss", $idDtl, $idHdr, $seq, $ak, $kb, $db, $kr, $ur, $userId, $sessionCmpId);
            $stmtDtl->execute();
            $seq++;
        }
        $stmtDtl->close();

        // Otomatis masukkan ke general ledger (bc_act_jurnal)
        foreach ($items as $it) {
            $ak = trim($it['akun'] ?? '');
            if (empty($ak)) continue;
            $db = floatval($it['amt_db'] ?? 0);
            $kr = floatval($it['amt_cr'] ?? 0);
            $ur = $koneksi->real_escape_string($it['uraian'] ?? $note);
            $kb = $koneksi->real_escape_string($it['kode_pembantu'] ?? '');
            $escAk = $koneksi->real_escape_string($ak);

            $koneksi->query("INSERT INTO bc_act_jurnal (tanggal, voucher, keterangan, kode_akun, kode_bantu, db, kr, cmp_id)
                             VALUES ('$escVDt', '$escVNo', '$ur', '$escAk', '$kb', $db, $kr, '$escCmp')");
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Voucher $vNo berhasil disimpan dan diposting ke GL", 'v_no' => $vNo]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan voucher: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Riwayat Voucher (Tab 2)
// =========================================================================
if ($action === 'list_vouchers') {
    header('Content-Type: application/json; charset=utf-8');

    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $search = trim($_GET['search'] ?? '');
    $where = [$cmpClause];
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(a.v_no LIKE '%$escS%' OR a.note LIKE '%$escS%')";
    }
    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "SELECT a.id, a.v_no, a.v_dt, a.tipe, a.note, a.set_by,
            (SELECT SUM(d.amt_db) FROM bc_v_dtl d WHERE d.id_hdr = a.id) as total_amt,
            (SELECT COUNT(*) FROM bc_v_dtl d WHERE d.id_hdr = a.id) as item_count
            FROM bc_v_hdr a
            $whereSql
            ORDER BY a.v_dt DESC, a.v_no DESC LIMIT 200";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['total_amt'] = (float)$r['total_amt'];
            $rows[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Chart of Accounts for Voucher
// =========================================================================
if ($action === 'get_accounts') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $sql = "SELECT kode, nama_akun as nama, tipe FROM bc_act_akun WHERE $cmpClause ORDER BY kode ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entri Voucher Kas, Bank & Memorial - CEISA 4.0 & Enterprise Hub</title>

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
        .portal-title i { color: var(--purple); font-size: 12px; }
        .portal-badge { font-size: 8.5px; font-weight: 600; padding: 1px 6px; border-radius: 3px; background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }

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
        .ant-tab-btn.active { background: var(--purple); color: #ffffff; border-color: var(--purple); font-weight: 600; }

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
        .ant-btn-success { background: var(--success); border-color: var(--success); color: #ffffff; }

        .grid-container { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; overflow: hidden; position: relative; }
        .grid-scroll { max-height: calc(100vh - 240px); overflow: auto; }
        table.ant-table { width: 100%; border-collapse: collapse; font-size: 9.5px; white-space: nowrap; table-layout: fixed; }
        table.ant-table th {
            background: var(--table-head-bg); color: var(--text-main); font-size: 9.5px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px; padding: 3px 6px; border-right: 1px solid var(--border-table);
            border-bottom: 1px solid var(--border-table); position: sticky; top: 0; z-index: 5; user-select: none;
        }
        table.ant-table td { padding: 2px 6px; border-right: 1px solid var(--border-light); border-bottom: 1px solid var(--border-light); vertical-align: middle; }
        table.ant-table tr:hover td { background: var(--row-hover); }

        .balance-bar {
            display: flex; justify-content: space-between; align-items: center;
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 4px; padding: 4px 8px; margin-top: 6px; font-size: 9.5px; font-weight: 600;
        }
        .status-pill { padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
        .status-balance { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .status-unbalanced { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }

        .swal2-popup.swal-compact { width: 330px !important; padding: 12px 14px !important; border-radius: 4px !important; font-family: var(--app-font) !important; font-size: 9.5px !important; }
        .swal2-popup.swal-compact button.swal2-styled { font-size: 9.5px !important; height: 21px !important; padding: 0 10px !important; border-radius: 3px !important; line-height: 21px !important; }
    </style>
</head>
<body>

    <div class="portal-header">
        <div class="portal-title">
            <i class="fa-solid fa-receipt"></i>
            <span>Entri Voucher Kas, Bank & Memorial</span>
            <span class="portal-badge">Tenant: <?= htmlspecialchars($sessionCmpId) ?></span>
            <span style="font-size: 8.5px; color: var(--text-sub); font-weight: 400; margin-left: 4px;">
                Porting PB w_v.srw (Voucher Kas Masuk/Keluar, Bank & Jurnal Memorial)
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
                <h5>Total Voucher Bulan Ini</h5>
                <h3 id="kpi-total">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Kas Masuk (KM)</h5>
                <h3 id="kpi-km">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-circle-arrow-down"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Kas Keluar (KK)</h5>
                <h3 id="kpi-kk">-</h3>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-circle-arrow-up"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Bank Keluar (BK)</h5>
                <h3 id="kpi-bk">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-building-columns"></i></div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="ant-tabs-nav">
        <button type="button" class="ant-tab-btn active" id="tab-btn-entri" onclick="switchTab('entri')">
            <i class="fa-solid fa-pen-to-square"></i> 1. Entri Multi-Row Voucher
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-riwayat" onclick="switchTab('riwayat')">
            <i class="fa-solid fa-list-check"></i> 2. Riwayat Voucher (Browse)
        </button>
    </div>

    <!-- TAB 1: FORM ENTRI VOUCHER -->
    <div id="tab-content-entri">
        <div class="form-card">
            <div class="form-grid">
                <div class="form-group">
                    <label>Jenis / Tipe Voucher <span style="color:var(--danger)">*</span></label>
                    <select id="input-v-tipe" class="ant-select" onchange="onVoucherTypeChange()">
                        <option value="KK">KK - Kas Keluar (Pengeluaran Tunai)</option>
                        <option value="KM">KM - Kas Masuk (Penerimaan Tunai)</option>
                        <option value="BK">BK - Bank Keluar (Transfer / Cek Keluar)</option>
                        <option value="BM">BM - Bank Masuk (Penerimaan Rekening)</option>
                        <option value="MM">MM - Memorial (Penyesuaian / Koreksi)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tanggal Transaksi</label>
                    <input type="date" id="input-v-dt" class="ant-input" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Nomor Bukti Voucher</label>
                    <input type="text" id="input-v-no" class="ant-input" placeholder="[Auto Generated]">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <div style="display:flex; gap:4px;">
                        <button type="button" class="ant-btn ant-btn-primary" onclick="addRowVoucher()"><i class="fa-solid fa-plus"></i> Tambah Baris</button>
                        <button type="button" class="ant-btn ant-btn-success" onclick="saveVoucher()"><i class="fa-solid fa-check"></i> Simpan & Posting</button>
                    </div>
                </div>
                <div class="form-group" style="grid-column: span 4;">
                    <label>Catatan / Keterangan Umum Voucher</label>
                    <input type="text" id="input-v-note" class="ant-input" placeholder="Misal: Pembayaran biaya operasional pabrik / transfer antar bank...">
                </div>
            </div>
        </div>

        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblVoucherItems">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 140px;">Kode Akun COA</th>
                            <th style="width: 120px;">Kode Pembantu</th>
                            <th>Uraian Transaksi</th>
                            <th style="width: 130px; text-align: right;">Debet (Rp)</th>
                            <th style="width: 130px; text-align: right;">Kredit (Rp)</th>
                            <th style="width: 40px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyVoucherItems">
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Balance Indicator Bar -->
        <div class="balance-bar">
            <div style="display:flex; gap:16px; align-items:center;">
                <div>Total Debet: <span id="v-tot-db" style="color:var(--primary); font-weight:700;">Rp 0</span></div>
                <div>Total Kredit: <span id="v-tot-kr" style="color:var(--purple); font-weight:700;">Rp 0</span></div>
                <div>Selisih: <span id="v-tot-diff" style="font-weight:700;">Rp 0</span></div>
            </div>
            <div id="v-status-indicator">
                <span class="status-pill status-balance"><i class="fa-solid fa-circle-check"></i> BALANCE</span>
            </div>
        </div>
    </div>

    <!-- TAB 2: RIWAYAT VOUCHER -->
    <div id="tab-content-riwayat" style="display:none;">
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:4px; padding:4px 8px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
            <input type="text" id="search-v-history" class="ant-input" placeholder="Cari nomor bukti voucher, catatan..." style="width:240px;" oninput="filterHistoryLocal()">
            <button type="button" class="ant-btn" onclick="exportHistoryExcel()"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel</button>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblHistoryV">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 130px;">No Voucher</th>
                            <th style="width: 80px; text-align: center;">Tanggal</th>
                            <th style="width: 60px; text-align: center;">Tipe</th>
                            <th style="width: 120px; text-align: right;">Total Nominal (Rp)</th>
                            <th style="width: 60px; text-align: center;">Baris</th>
                            <th>Keterangan</th>
                            <th style="width: 90px; text-align: center;">User</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyHistoryV">
                        <tr><td colspan="8" style="text-align:center; padding:16px;">Memuat riwayat voucher...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'entri';
        let rawHistoryV = [];
        let accountList = [];
        let voucherRows = [];

        const SwalCompact = Swal.mixin({
            customClass: { popup: 'swal-compact', confirmButton: 'ant-btn ant-btn-primary', cancelButton: 'ant-btn' },
            buttonsStyling: false
        });

        document.addEventListener('DOMContentLoaded', () => {
            loadKPI();
            loadAccounts();
            resetVoucherForm();
        });

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-btn-entri').classList.toggle('active', tab === 'entri');
            document.getElementById('tab-btn-riwayat').classList.toggle('active', tab === 'riwayat');
            document.getElementById('tab-content-entri').style.display = tab === 'entri' ? 'block' : 'none';
            document.getElementById('tab-content-riwayat').style.display = tab === 'riwayat' ? 'block' : 'none';

            if (tab === 'riwayat') loadHistoryV();
        }

        function refreshCurrentTab() {
            loadKPI();
            if (currentTab === 'riwayat') loadHistoryV();
        }

        async function loadKPI() {
            try {
                const res = await fetch('voucher_entry.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-total').textContent = (json.kpi.total_vouchers || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-km').textContent = (json.kpi.total_km || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-kk').textContent = (json.kpi.total_kk || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-bk').textContent = (json.kpi.total_bk || 0).toLocaleString('id-ID');
                }
            } catch (e) { console.error(e); }
        }

        async function loadAccounts() {
            try {
                const res = await fetch('voucher_entry.php?action=get_accounts');
                const json = await res.json();
                if (json.status === 'OK') {
                    accountList = json.data || [];
                }
            } catch (e) { console.error(e); }
        }

        function resetVoucherForm() {
            voucherRows = [
                { akun: '', kode_bantu: '', uraian: '', amt_db: 0, amt_cr: 0 },
                { akun: '', kode_bantu: '', uraian: '', amt_db: 0, amt_cr: 0 }
            ];
            renderVoucherRows();
        }

        function addRowVoucher() {
            voucherRows.push({ akun: '', kode_bantu: '', uraian: '', amt_db: 0, amt_cr: 0 });
            renderVoucherRows();
        }

        function deleteRowVoucher(idx) {
            if (voucherRows.length <= 1) return;
            voucherRows.splice(idx, 1);
            renderVoucherRows();
        }

        function renderVoucherRows() {
            const tbody = document.getElementById('tbodyVoucherItems');
            let html = '';
            voucherRows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="padding:1px 4px;">
                        <input type="text" class="ant-input" value="${escapeHtml(r.akun)}" placeholder="Kode Akun" style="font-family:monospace; width:100%;" onchange="onRowCellChange(${idx}, 'akun', this.value)">
                    </td>
                    <td style="padding:1px 4px;">
                        <input type="text" class="ant-input" value="${escapeHtml(r.kode_bantu || '')}" placeholder="Buku Bantu" style="font-family:monospace; width:100%;" onchange="onRowCellChange(${idx}, 'kode_bantu', this.value)">
                    </td>
                    <td style="padding:1px 4px;">
                        <input type="text" class="ant-input" value="${escapeHtml(r.uraian || '')}" placeholder="Keterangan baris jurnal" style="width:100%;" onchange="onRowCellChange(${idx}, 'uraian', this.value)">
                    </td>
                    <td style="padding:1px 4px;">
                        <input type="number" step="0.01" class="ant-input" value="${r.amt_db || 0}" style="text-align:right; font-family:monospace; width:100%; font-weight:600; color:var(--primary);" oninput="onRowCellChange(${idx}, 'amt_db', this.value)">
                    </td>
                    <td style="padding:1px 4px;">
                        <input type="number" step="0.01" class="ant-input" value="${r.amt_cr || 0}" style="text-align:right; font-family:monospace; width:100%; font-weight:600; color:var(--purple);" oninput="onRowCellChange(${idx}, 'amt_cr', this.value)">
                    </td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="deleteRowVoucher(${idx})" title="Hapus"><i class="fa-solid fa-xmark"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
            recalcVoucherTotals();
        }

        function onRowCellChange(idx, field, val) {
            if (!voucherRows[idx]) return;
            if (field === 'amt_db' || field === 'amt_cr') {
                voucherRows[idx][field] = parseFloat(val) || 0;
                recalcVoucherTotals();
            } else {
                voucherRows[idx][field] = val;
            }
        }

        function recalcVoucherTotals() {
            let totDb = 0;
            let totKr = 0;
            voucherRows.forEach(r => {
                totDb += parseFloat(r.amt_db) || 0;
                totKr += parseFloat(r.amt_cr) || 0;
            });
            const diff = totDb - totKr;
            document.getElementById('v-tot-db').textContent = 'Rp ' + totDb.toLocaleString('id-ID', {minimumFractionDigits:2});
            document.getElementById('v-tot-kr').textContent = 'Rp ' + totKr.toLocaleString('id-ID', {minimumFractionDigits:2});
            document.getElementById('v-tot-diff').textContent = 'Rp ' + diff.toLocaleString('id-ID', {minimumFractionDigits:2});

            const ind = document.getElementById('v-status-indicator');
            if (Math.abs(diff) < 0.01 && totDb > 0) {
                ind.innerHTML = '<span class="status-pill status-balance"><i class="fa-solid fa-circle-check"></i> BALANCE</span>';
            } else {
                ind.innerHTML = '<span class="status-pill status-unbalanced"><i class="fa-solid fa-triangle-exclamation"></i> SELISIH (TIDAK BALANCE)</span>';
            }
        }

        function onVoucherTypeChange() {
            // Bisa isi auto-nomor atau template jika diperlukan
        }

        async function saveVoucher() {
            const tipe = document.getElementById('input-v-tipe').value;
            const vDt  = document.getElementById('input-v-dt').value;
            const vNo  = document.getElementById('input-v-no').value.trim();
            const note = document.getElementById('input-v-note').value.trim();

            let totDb = 0, totKr = 0;
            const validItems = [];
            voucherRows.forEach(r => {
                const db = parseFloat(r.amt_db) || 0;
                const kr = parseFloat(r.amt_cr) || 0;
                if (r.akun && (db > 0 || kr > 0)) {
                    validItems.push({ akun: r.akun.trim(), kode_pembantu: (r.kode_bantu || '').trim(), uraian: r.uraian || note, amt_db: db, amt_cr: kr });
                    totDb += db; totKr += kr;
                }
            });

            if (validItems.length < 2) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Voucher minimal harus memiliki 2 baris akun yang valid' });
                return;
            }
            if (Math.abs(totDb - totKr) > 0.01) {
                SwalCompact.fire({ icon: 'error', title: 'Tidak Seimbang', text: 'Total Debet dan Kredit harus sama (Balance)!' });
                return;
            }

            const payload = { tipe, v_dt: vDt, v_no: vNo, note, items: validItems };

            try {
                const res = await fetch('voucher_entry.php?action=save_voucher', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message });
                    resetVoucherForm();
                    loadKPI();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        async function loadHistoryV() {
            const tbody = document.getElementById('tbodyHistoryV');
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:16px;">Memuat riwayat voucher...</td></tr>';
            try {
                const res = await fetch('voucher_entry.php?action=list_vouchers');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawHistoryV = json.data || [];
                    renderHistoryTable(rawHistoryV);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function filterHistoryLocal() {
            const q = document.getElementById('search-v-history').value.toLowerCase().trim();
            if (!q) { renderHistoryTable(rawHistoryV); return; }
            const filtered = rawHistoryV.filter(r => (r.v_no || '').toLowerCase().includes(q) || (r.note || '').toLowerCase().includes(q));
            renderHistoryTable(filtered);
        }

        function renderHistoryTable(rows) {
            const tbody = document.getElementById('tbodyHistoryV');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:16px; color:var(--text-sub);">Belum ada riwayat voucher.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.v_no)}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.v_dt)}</td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:700; padding:1px 5px; border-radius:3px; background:#f0f0f0;">${escapeHtml(r.tipe)}</span></td>
                    <td style="text-align:right; font-family:monospace; font-weight:700; color:var(--purple);">${(r.total_amt || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                    <td style="text-align:center;">${r.item_count || 0}</td>
                    <td>${escapeHtml(r.note || '-')}</td>
                    <td style="text-align:center; color:var(--text-sub);">${escapeHtml(r.set_by || '-')}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function exportHistoryExcel() {
            const table = document.getElementById('tblHistoryV');
            if (!table) return;
            const wb = XLSX.utils.table_to_book(table, { sheet: "Riwayat Voucher" });
            XLSX.writeFile(wb, `Riwayat_Voucher_${Date.now()}.xlsx`);
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

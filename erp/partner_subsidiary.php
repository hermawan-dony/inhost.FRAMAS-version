<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Kode Rekanan Pembantu (Partner Subsidiary Ledger)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_act_kodebantu.srw)
 * DataWindows: d_act_bantu.srd, d_act_bantusaldo.srd, d_act_bantu_rpt.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Rekanan, Subledger Hutang HU, Subledger Piutang PI, Saldo Periode Aktif)
 * - Tab 1: Master Kode Rekanan Pembantu (bc_act_bantu) dengan sinkronisasi otomatis dari bc_bs
 * - Tab 2: Entri Saldo Awal Buku Pembantu per Periode (bc_act_bantusaldo) dengan verifikasi Debet/Kredit
 * - DataGrid Enterprise Resizable (.col-resizer) & Right-Click Context Menu (TSV, Excel, Filter, Reset)
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

// Ensure tables exist
$koneksi->query("CREATE TABLE IF NOT EXISTS bc_act_bantu (
    kode varchar(20) NOT NULL,
    nama_akun varchar(80) DEFAULT NULL,
    tipe varchar(25) DEFAULT 'HU',
    cmp_id varchar(30) DEFAULT '',
    PRIMARY KEY (kode, cmp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$koneksi->query("CREATE TABLE IF NOT EXISTS bc_act_bantusaldo (
    periode varchar(7) NOT NULL,
    kode_akun varchar(20) NOT NULL,
    saldoawal_db decimal(30,2) DEFAULT 0.00,
    saldoawal_kr decimal(30,2) DEFAULT 0.00,
    set_by varchar(40) DEFAULT NULL,
    cmp_id varchar(30) DEFAULT '',
    PRIMARY KEY (periode, kode_akun, cmp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    
    $sql = "SELECT 
        COUNT(*) as total_rekanan,
        SUM(CASE WHEN tipe = 'HU' THEN 1 ELSE 0 END) as total_hutang,
        SUM(CASE WHEN tipe = 'PI' THEN 1 ELSE 0 END) as total_piutang,
        SUM(CASE WHEN tipe NOT IN ('HU', 'PI') THEN 1 ELSE 0 END) as total_lainnya
        FROM bc_act_bantu WHERE $cmpClause";
    $res = $koneksi->query($sql);
    $kpi = $res ? $res->fetch_assoc() : ['total_rekanan' => 0, 'total_hutang' => 0, 'total_piutang' => 0, 'total_lainnya' => 0];

    // Cek rekanan di bc_bs yang belum terdaftar di bc_act_bantu
    $sqlBs = "SELECT COUNT(*) as blm_sync FROM bc_bs b 
              WHERE (b.cmp_id = '$escCmp' OR b.cmp_id = '') AND b.KODE IS NOT NULL AND b.KODE != ''
              AND b.KODE NOT IN (SELECT kode FROM bc_act_bantu WHERE $cmpClause)";
    $resBs = $koneksi->query($sqlBs);
    $kpi['blm_sync'] = ($resBs && $rowBs = $resBs->fetch_assoc()) ? (int)$rowBs['blm_sync'] : 0;

    echo json_encode(['status' => 'OK', 'kpi' => $kpi]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Rekanan Pembantu (Tab 1)
// =========================================================================
if ($action === 'list_bantu') {
    header('Content-Type: application/json; charset=utf-8');

    $tipe   = trim($_GET['tipe'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $periode = trim($_GET['periode'] ?? date('Y/01'));
    $escPeriode = $koneksi->real_escape_string($periode);

    $where = ["(a.cmp_id = '$escCmp' OR a.cmp_id = '')"];
    if (!empty($tipe)) {
        $escT = $koneksi->real_escape_string($tipe);
        $where[] = "a.tipe = '$escT'";
    }
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(a.kode LIKE '%$escS%' OR a.nama_akun LIKE '%$escS%')";
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "SELECT a.kode, a.nama_akun, a.tipe, a.cmp_id,
            IFNULL(s.saldoawal_db, 0) as saldoawal_db,
            IFNULL(s.saldoawal_kr, 0) as saldoawal_kr
            FROM bc_act_bantu a
            LEFT JOIN bc_act_bantusaldo s ON s.kode_akun = a.kode AND s.periode = '$escPeriode' AND (s.cmp_id = '$escCmp' OR s.cmp_id = '')
            $whereSql
            ORDER BY a.tipe ASC, a.kode ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['saldoawal_db'] = (float)$r['saldoawal_db'];
            $r['saldoawal_kr'] = (float)$r['saldoawal_kr'];
            $rows[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'total'  => count($rows)
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Saldo Awal per Periode (Tab 2)
// =========================================================================
if ($action === 'get_period_saldo') {
    header('Content-Type: application/json; charset=utf-8');

    $periode = trim($_GET['periode'] ?? date('Y/01'));
    $escPeriode = $koneksi->real_escape_string($periode);
    $tipe = trim($_GET['tipe'] ?? '');

    $where = ["(a.cmp_id = '$escCmp' OR a.cmp_id = '')"];
    if (!empty($tipe)) {
        $escT = $koneksi->real_escape_string($tipe);
        $where[] = "a.tipe = '$escT'";
    }
    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "SELECT a.kode, a.nama_akun, a.tipe,
            IFNULL(s.saldoawal_db, 0) as saldoawal_db,
            IFNULL(s.saldoawal_kr, 0) as saldoawal_kr,
            s.set_by
            FROM bc_act_bantu a
            LEFT JOIN bc_act_bantusaldo s ON s.kode_akun = a.kode AND s.periode = '$escPeriode' AND (s.cmp_id = '$escCmp' OR s.cmp_id = '')
            $whereSql
            ORDER BY a.tipe ASC, a.kode ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    $totalDb = 0;
    $totalKr = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['saldoawal_db'] = (float)$r['saldoawal_db'];
            $r['saldoawal_kr'] = (float)$r['saldoawal_kr'];
            $totalDb += $r['saldoawal_db'];
            $totalKr += $r['saldoawal_kr'];
            $rows[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'periode' => $periode,
        'summary' => [
            'total_db' => $totalDb,
            'total_kr' => $totalKr,
            'selisih'  => $totalDb - $totalKr,
            'is_balance' => abs($totalDb - $totalKr) < 0.01
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Save Single Rekanan (Insert / Update)
// =========================================================================
if ($action === 'save_bantu') {
    header('Content-Type: application/json; charset=utf-8');

    $isEdit   = !empty($_POST['is_edit']);
    $oldKode  = trim($_POST['old_kode'] ?? '');
    $kode     = strtoupper(trim($_POST['kode'] ?? ''));
    $namaAkun = trim($_POST['nama_akun'] ?? '');
    $tipe     = strtoupper(trim($_POST['tipe'] ?? 'HU'));

    if (empty($kode) || empty($namaAkun)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Rekanan dan Nama Akun wajib diisi']);
        exit;
    }

    $escKode = $koneksi->real_escape_string($kode);
    $escNama = $koneksi->real_escape_string($namaAkun);
    $escTipe = $koneksi->real_escape_string($tipe);

    if ($isEdit && !empty($oldKode) && $oldKode !== $kode) {
        $escOld = $koneksi->real_escape_string($oldKode);
        // Update kode
        $koneksi->query("UPDATE bc_act_bantu SET kode = '$escKode', nama_akun = '$escNama', tipe = '$escTipe' WHERE kode = '$escOld' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        $koneksi->query("UPDATE bc_act_bantusaldo SET kode_akun = '$escKode' WHERE kode_akun = '$escOld' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        echo json_encode(['status' => 'OK', 'message' => 'Kode Rekanan Pembantu berhasil diperbarui']);
        exit;
    }

    $upsertSql = "INSERT INTO bc_act_bantu (kode, nama_akun, tipe, cmp_id)
                  VALUES ('$escKode', '$escNama', '$escTipe', '$escCmp')
                  ON DUPLICATE KEY UPDATE nama_akun = '$escNama', tipe = '$escTipe'";
    if ($koneksi->query($upsertSql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Kode Rekanan Pembantu berhasil disimpan']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Delete Rekanan
// =========================================================================
if ($action === 'delete_bantu') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim($_POST['kode'] ?? '');
    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode tidak valid']);
        exit;
    }
    $escKode = $koneksi->real_escape_string($kode);
    $koneksi->query("DELETE FROM bc_act_bantusaldo WHERE kode_akun = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '')");
    $del = $koneksi->query("DELETE FROM bc_act_bantu WHERE kode = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '')");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Kode Rekanan berhasil dihapus']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Sync from Master Mitra bc_bs
// =========================================================================
if ($action === 'sync_from_bs') {
    header('Content-Type: application/json; charset=utf-8');

    $syncSql = "INSERT IGNORE INTO bc_act_bantu (kode, nama_akun, tipe, cmp_id)
                SELECT KODE, NAMA, 
                       CASE WHEN UPPER(TIPE) LIKE '%SUPPLIER%' OR UPPER(TIPE) LIKE '%VENDOR%' THEN 'HU' ELSE 'PI' END as tipe, 
                       '$escCmp'
                FROM bc_bs
                WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND KODE IS NOT NULL AND TRIM(KODE) != ''";
    if ($koneksi->query($syncSql)) {
        $affected = $koneksi->affected_rows;
        echo json_encode(['status' => 'OK', 'message' => "Berhasil sinkronisasi $affected rekanan dari Master Buyer/Supplier (bc_bs)"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal sinkronisasi: ' . $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Bulk Save Saldo Awal Pembantu per Periode
// =========================================================================
if ($action === 'save_period_saldo') {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!$req || empty($req['periode']) || !isset($req['items'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Payload JSON tidak valid']);
        exit;
    }

    $periode = trim($req['periode']);
    $escPeriode = $koneksi->real_escape_string($periode);
    $items = $req['items'];
    $setBy = $userId . ' - ' . date('Y-m-d H:i');
    $escSetBy = $koneksi->real_escape_string($setBy);

    $koneksi->begin_transaction();
    try {
        $stmt = $koneksi->prepare("INSERT INTO bc_act_bantusaldo (periode, kode_akun, saldoawal_db, saldoawal_kr, set_by, cmp_id)
                                  VALUES (?, ?, ?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE saldoawal_db = VALUES(saldoawal_db), saldoawal_kr = VALUES(saldoawal_kr), set_by = VALUES(set_by)");
        
        foreach ($items as $it) {
            $kAkun = trim($it['kode'] ?? '');
            $db    = (float)($it['saldoawal_db'] ?? 0);
            $kr    = (float)($it['saldoawal_kr'] ?? 0);

            if (!empty($kAkun)) {
                $stmt->bind_param("ssddss", $periode, $kAkun, $db, $kr, $setBy, $sessionCmpId);
                $stmt->execute();
            }
        }
        $stmt->close();
        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Saldo awal buku pembantu periode $periode berhasil disimpan"]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan saldo: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode Rekanan Pembantu - CEISA 4.0 & Enterprise Hub</title>

    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SweetAlert2 -->
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>

    <!-- SheetJS for Excel Export -->
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

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: var(--app-font);
        }

        body {
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 9.5px;
            line-height: 1.4;
            padding: 8px 10px;
            overflow-x: hidden;
        }

        /* Top Bar */
        .portal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 4px 8px;
            margin-bottom: 6px;
        }
        .portal-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-main);
        }
        .portal-title i {
            color: var(--primary);
            font-size: 12px;
        }
        .portal-badge {
            font-size: 8.5px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 3px;
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }

        /* KPI Cards Grid */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-bottom: 6px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 5px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 44px;
        }
        .kpi-info h5 {
            font-size: 8.5px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-sub);
            letter-spacing: 0.3px;
        }
        .kpi-info h3 {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
        }
        .kpi-icon {
            width: 26px;
            height: 26px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
        }
        .icon-blue   { background: #e6f7ff; color: #1890ff; }
        .icon-amber  { background: #fffbe6; color: #faad14; }
        .icon-purple { background: #f9f0ff; color: #722ed1; }
        .icon-green  { background: #f6ffed; color: #52c41a; }

        /* Navigation Tabs */
        .ant-tabs-nav {
            display: flex;
            gap: 2px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 6px;
        }
        .ant-tab-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            padding: 3px 12px;
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-sub);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            height: 24px;
            transition: all 0.2s;
        }
        .ant-tab-btn:hover {
            color: var(--primary);
        }
        .ant-tab-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 600;
        }

        /* Toolbar Container */
        .ant-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 4px 8px;
            margin-bottom: 6px;
            gap: 6px;
            flex-wrap: wrap;
        }
        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        /* Compact 21px Inputs & Buttons */
        .ant-input, .ant-select {
            font-size: 9.5px;
            height: 21px;
            padding: 0 6px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .ant-input:focus, .ant-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.15);
        }

        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 9.5px;
            font-weight: 500;
            height: 21px;
            padding: 0 8px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .ant-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }
        .ant-btn-success {
            background: var(--success);
            border-color: var(--success);
            color: #ffffff;
        }
        .ant-btn-success:hover {
            opacity: 0.9;
            color: #ffffff;
        }
        .ant-btn-danger {
            background: var(--danger);
            border-color: var(--danger);
            color: #ffffff;
        }
        .ant-btn-danger:hover {
            opacity: 0.9;
            color: #ffffff;
        }

        /* DataGrid Container */
        .grid-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .grid-scroll {
            max-height: calc(100vh - 190px);
            overflow: auto;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
            table-layout: fixed;
        }
        table.ant-table th {
            background: var(--table-head-bg);
            color: var(--text-main);
            font-size: 9.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 3px 6px;
            border-right: 1px solid var(--border-table);
            border-bottom: 1px solid var(--border-table);
            position: sticky;
            top: 0;
            z-index: 5;
            user-select: none;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        table.ant-table td {
            padding: 2px 6px;
            border-right: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        table.ant-table tr:hover td {
            background: var(--row-hover);
        }
        table.ant-table tr.selected td {
            background: var(--row-selected);
        }

        /* Resizer handle */
        .col-resizer {
            position: absolute;
            top: 0;
            right: 0;
            width: 5px;
            cursor: col-resize;
            user-select: none;
            height: 100%;
            z-index: 6;
        }
        .col-resizer:hover, .col-resizer.resizing {
            background: var(--primary);
        }

        /* Badges */
        .badge-type {
            font-size: 8.5px;
            font-weight: 600;
            padding: 1px 5px;
            border-radius: 3px;
            display: inline-block;
            text-align: center;
        }
        .badge-hu { background: #fffbe6; color: #d46b08; border: 1px solid #ffd591; }
        .badge-pi { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; }
        .badge-other { background: #f9f0ff; color: #531dab; border: 1px solid #d3adf7; }

        /* Balance Status Bar */
        .balance-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 4px 8px;
            margin-top: 6px;
            font-size: 9.5px;
            font-weight: 600;
        }
        .status-pill {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .status-balance { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .status-unbalanced { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }

        /* Right-Click Context Menu */
        .ctx-menu {
            position: fixed;
            z-index: 9999;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 170px;
            padding: 3px 0;
            display: none;
        }
        .ctx-item {
            padding: 4px 10px;
            font-size: 9.5px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .ctx-item:hover {
            background: var(--row-hover);
            color: var(--primary);
        }
        .ctx-item i {
            width: 13px;
            font-size: 10px;
            text-align: center;
        }
        .ctx-divider {
            height: 1px;
            background: var(--border-light);
            margin: 2px 0;
        }

        /* Modal Dialog */
        .ant-modal-mask {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .ant-modal {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            width: 360px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            animation: modalFade 0.2s ease;
        }
        @keyframes modalFade {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .ant-modal-header {
            padding: 6px 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10.5px;
            font-weight: 600;
        }
        .ant-modal-body {
            padding: 10px;
        }
        .ant-modal-footer {
            padding: 6px 10px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--table-head-bg);
        }
        .form-row {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
            gap: 6px;
        }
        .form-row label {
            width: 95px;
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-sub);
        }
        .form-row input, .form-row select {
            flex: 1;
        }

        /* Compact SweetAlert2 Theme */
        .swal2-popup.swal-compact {
            width: 330px !important;
            padding: 12px 14px !important;
            border-radius: 4px !important;
            font-family: var(--app-font) !important;
            font-size: 9.5px !important;
            border: 1px solid var(--border-color) !important;
        }
        .swal2-popup.swal-compact .swal2-title {
            font-size: 11.5px !important;
            font-weight: 600 !important;
            margin-bottom: 6px !important;
            padding: 0 !important;
        }
        .swal2-popup.swal-compact .swal2-html-container {
            font-size: 9.5px !important;
            margin: 6px 0 !important;
            line-height: 1.4 !important;
        }
        .swal2-popup.swal-compact .swal2-actions {
            margin-top: 10px !important;
            gap: 6px !important;
        }
        .swal2-popup.swal-compact button.swal2-styled {
            font-size: 9.5px !important;
            height: 21px !important;
            padding: 0 10px !important;
            border-radius: 3px !important;
            line-height: 21px !important;
        }
    </style>
</head>
<body>

    <!-- Portal Header -->
    <div class="portal-header">
        <div class="portal-title">
            <i class="fa-solid fa-address-book"></i>
            <span>Kode Rekanan Pembantu (Subsidiary Ledger)</span>
            <span class="portal-badge">Tenant: <?= htmlspecialchars($sessionCmpId) ?></span>
            <span style="font-size: 8.5px; color: var(--text-sub); font-weight: 400; margin-left: 4px;">
                Porting PB w_act_kodebantu.srw (Subledger Hutang HU & Piutang PI)
            </span>
        </div>
        <div class="toolbar-group">
            <button type="button" class="ant-btn ant-btn-sm" onclick="syncFromMasterBs()" title="Tarik Rekanan Otomatis dari Master Mitra (bc_bs)">
                <i class="fa-solid fa-arrows-rotate" style="color:var(--primary);"></i> Sinkron Mitra (bc_bs)
            </button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="refreshCurrentTab()" title="Muat Ulang Data">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Kode Pembantu</h5>
                <h3 id="kpi-total">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-users"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Subledger Hutang (HU)</h5>
                <h3 id="kpi-hutang">-</h3>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Subledger Piutang (PI)</h5>
                <h3 id="kpi-piutang">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Belum Terdaftar di Bantu</h5>
                <h3 id="kpi-blm-sync">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-link-slash"></i></div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="ant-tabs-nav">
        <button type="button" class="ant-tab-btn active" id="tab-btn-master" onclick="switchTab('master')">
            <i class="fa-solid fa-list-check"></i> 1. Master Kode Rekanan Pembantu (bc_act_bantu)
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-saldo" onclick="switchTab('saldo')">
            <i class="fa-solid fa-calendar-check"></i> 2. Saldo Awal Buku Pembantu per Periode (bc_act_bantusaldo)
        </button>
    </div>

    <!-- TAB 1: MASTER KODE PEMBANTU -->
    <div id="tab-content-master">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-sub);">Filter Tipe:</label>
                <select id="filter-tipe" class="ant-select" onchange="loadMasterBantu()" style="width:110px;">
                    <option value="">-- Semua Tipe --</option>
                    <option value="HU">HU (Hutang / Supplier)</option>
                    <option value="PI">PI (Piutang / Buyer)</option>
                    <option value="BANK">BANK / Rekening</option>
                    <option value="LAIN">LAINNYA</option>
                </select>

                <label style="font-size:9.5px; font-weight:600; color:var(--text-sub); margin-left:6px;">Periode Saldo:</label>
                <input type="text" id="filter-periode-view" class="ant-input" value="<?= date('Y/01') ?>" placeholder="YYYY/MM" style="width:65px; text-align:center;" onchange="loadMasterBantu()">

                <input type="text" id="search-bantu" class="ant-input" placeholder="Cari kode atau nama rekanan..." style="width:180px;" oninput="filterLocalMaster()">
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()">
                    <i class="fa-solid fa-plus"></i> Tambah Kode
                </button>
                <button type="button" class="ant-btn" onclick="exportMasterExcel()">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
                <button type="button" class="ant-btn" onclick="resetMasterGridWidths()" title="Kembalikan ukuran kolom ke setelan standar">
                    <i class="fa-solid fa-arrows-rotate"></i> Reset Lebar
                </button>
            </div>
        </div>

        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblMasterBantu">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 100px;" data-col="kode">Kode Rekanan <span class="sort-icon">↕</span></th>
                            <th style="width: 280px;" data-col="nama_akun">Nama Akun Subledger <span class="sort-icon">↕</span></th>
                            <th style="width: 90px; text-align: center;" data-col="tipe">Tipe <span class="sort-icon">↕</span></th>
                            <th style="width: 120px; text-align: right;" data-col="saldoawal_db">Saldo Debet (Rp) <span class="sort-icon">↕</span></th>
                            <th style="width: 120px; text-align: right;" data-col="saldoawal_kr">Saldo Kredit (Rp) <span class="sort-icon">↕</span></th>
                            <th style="width: 75px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyMasterBantu">
                        <tr><td colspan="7" style="text-align: center; padding: 16px;">Memuat data kode pembantu...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: SALDO AWAL PEMBANTU PER PERIODE -->
    <div id="tab-content-saldo" style="display:none;">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-sub);">Pilih Periode:</label>
                <input type="text" id="input-saldo-periode" class="ant-input" value="<?= date('Y/01') ?>" placeholder="YYYY/MM" style="width:70px; text-align:center;" onchange="loadPeriodSaldo()">

                <label style="font-size:9.5px; font-weight:600; color:var(--text-sub); margin-left:6px;">Filter Tipe:</label>
                <select id="filter-saldo-tipe" class="ant-select" onchange="loadPeriodSaldo()" style="width:110px;">
                    <option value="">-- Semua Tipe --</option>
                    <option value="HU">HU (Hutang / Supplier)</option>
                    <option value="PI">PI (Piutang / Buyer)</option>
                    <option value="BANK">BANK / Rekening</option>
                    <option value="LAIN">LAINNYA</option>
                </select>

                <input type="text" id="search-saldo" class="ant-input" placeholder="Cari di saldo..." style="width:170px;" oninput="filterLocalSaldo()">
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn ant-btn-success" onclick="savePeriodSaldo()">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Saldo Awal Periode
                </button>
                <button type="button" class="ant-btn" onclick="exportSaldoExcel()">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Ekspor Excel
                </button>
                <button type="button" class="ant-btn" onclick="resetSaldoGridWidths()">
                    <i class="fa-solid fa-arrows-rotate"></i> Reset Lebar
                </button>
            </div>
        </div>

        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblSaldoBantu">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 100px;" data-col="kode">Kode Rekanan <span class="sort-icon">↕</span></th>
                            <th style="width: 280px;" data-col="nama_akun">Nama Akun Subledger <span class="sort-icon">↕</span></th>
                            <th style="width: 80px; text-align: center;" data-col="tipe">Tipe <span class="sort-icon">↕</span></th>
                            <th style="width: 130px; text-align: right;" data-col="saldoawal_db">Saldo Awal Debet (Rp)</th>
                            <th style="width: 130px; text-align: right;" data-col="saldoawal_kr">Saldo Awal Kredit (Rp)</th>
                            <th style="width: 130px; text-align: right;" data-col="net">Saldo Bersih (Rp)</th>
                        </tr>
                    </thead>
                    <tbody id="tbodySaldoBantu">
                        <tr><td colspan="7" style="text-align: center; padding: 16px;">Memuat data saldo awal pembantu...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Balance Status Bar -->
        <div class="balance-bar">
            <div style="display:flex; gap:16px; align-items:center;">
                <div>Total Saldo Debet: <span id="lbl-total-db" style="color:var(--primary); font-weight:700;">Rp 0</span></div>
                <div>Total Saldo Kredit: <span id="lbl-total-kr" style="color:var(--purple); font-weight:700;">Rp 0</span></div>
                <div>Selisih: <span id="lbl-selisih" style="font-weight:700;">Rp 0</span></div>
            </div>
            <div id="lbl-balance-status">
                <span class="status-pill status-balance"><i class="fa-solid fa-circle-check"></i> SIKLUS SALDO VALID</span>
            </div>
        </div>
    </div>

    <!-- MODAL TAMBAH / EDIT KODE PEMBANTU -->
    <div class="ant-modal-mask" id="modalBantu">
        <div class="ant-modal">
            <div class="ant-modal-header">
                <span id="modalBantuTitle"><i class="fa-solid fa-user-tag" style="color:var(--primary);"></i> Tambah Kode Rekanan</span>
                <span style="cursor:pointer;" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></span>
            </div>
            <div class="ant-modal-body">
                <form id="formBantu" onsubmit="submitFormBantu(event)">
                    <input type="hidden" id="form-is-edit" value="0">
                    <input type="hidden" id="form-old-kode" value="">

                    <div class="form-row">
                        <label>Kode Rekanan <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="form-kode" class="ant-input" placeholder="Misal: S0001, B0012" required style="text-transform:uppercase;">
                    </div>
                    <div class="form-row">
                        <label>Nama Akun <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="form-nama" class="ant-input" placeholder="Nama mitra rekanan / akun subledger" required>
                    </div>
                    <div class="form-row">
                        <label>Tipe Subledger <span style="color:var(--danger)">*</span></label>
                        <select id="form-tipe" class="ant-select" required>
                            <option value="HU">HU - Hutang Usaha (Supplier / Vendor)</option>
                            <option value="PI">PI - Piutang Usaha (Buyer / Customer)</option>
                            <option value="BANK">BANK - Rekening Bank Perusahaan</option>
                            <option value="LAIN">LAIN - Subledger Operasional Lainnya</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="ant-modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitFormBantu(event)">
                    <i class="fa-solid fa-check"></i> Simpan
                </button>
            </div>
        </div>
    </div>

    <!-- RIGHT-CLICK ENTERPRISE CONTEXT MENU -->
    <div class="ctx-menu" id="ctxMenu">
        <div class="ctx-item" onclick="ctxCopySelectedRow()"><i class="fa-regular fa-copy"></i> Salin Baris Terpilih</div>
        <div class="ctx-item" onclick="ctxCopyAllTSV()"><i class="fa-solid fa-table"></i> Salin Semua Data (Grid TSV)</div>
        <div class="ctx-divider"></div>
        <div class="ctx-item" onclick="ctxExportExcel()"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan ke Excel (.xlsx)</div>
        <div class="ctx-item" onclick="ctxDownloadCSV()"><i class="fa-solid fa-file-csv"></i> Unduh CSV (.csv)</div>
        <div class="ctx-divider"></div>
        <div class="ctx-item" onclick="ctxEditRow()"><i class="fa-solid fa-pen-to-square"></i> Edit Baris Ini (Dobel Klik)</div>
        <div class="ctx-item" onclick="ctxDeleteRow()"><i class="fa-solid fa-trash" style="color:var(--danger);"></i> Hapus Baris Ini</div>
        <div class="ctx-divider"></div>
        <div class="ctx-item" onclick="ctxResetWidths()"><i class="fa-solid fa-arrows-rotate"></i> Reset Ukuran Kolom</div>
    </div>

    <script>
        let currentTab = 'master';
        let rawMasterData = [];
        let rawSaldoData = [];
        let selectedMasterRow = null;
        let selectedMasterIdx = -1;
        let rightClickedRowData = null;

        // Custom Compact Swal
        const SwalCompact = Swal.mixin({
            customClass: {
                popup: 'swal-compact',
                confirmButton: 'ant-btn ant-btn-primary',
                cancelButton: 'ant-btn'
            },
            buttonsStyling: false
        });

        document.addEventListener('DOMContentLoaded', () => {
            initResizableGrid('tblMasterBantu', 'grid_bantu_master_widths');
            initResizableGrid('tblSaldoBantu', 'grid_bantu_saldo_widths');
            initContextMenu();
            loadKPI();
            loadMasterBantu();
        });

        // Tab Switching
        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-btn-master').classList.toggle('active', tab === 'master');
            document.getElementById('tab-btn-saldo').classList.toggle('active', tab === 'saldo');
            document.getElementById('tab-content-master').style.display = tab === 'master' ? 'block' : 'none';
            document.getElementById('tab-content-saldo').style.display = tab === 'saldo' ? 'block' : 'none';

            if (tab === 'master') {
                loadMasterBantu();
            } else {
                loadPeriodSaldo();
            }
        }

        function refreshCurrentTab() {
            loadKPI();
            if (currentTab === 'master') {
                loadMasterBantu();
            } else {
                loadPeriodSaldo();
            }
        }

        // =====================================================================
        // KPI LOADER
        // =====================================================================
        async function loadKPI() {
            try {
                const res = await fetch('partner_subsidiary.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-total').textContent = (json.kpi.total_rekanan || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-hutang').textContent = (json.kpi.total_hutang || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-piutang').textContent = (json.kpi.total_piutang || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-blm-sync').textContent = (json.kpi.blm_sync || 0).toLocaleString('id-ID');
                }
            } catch (e) {
                console.error('Error load KPI:', e);
            }
        }

        // =====================================================================
        // TAB 1: MASTER KODE PEMBANTU
        // =====================================================================
        async function loadMasterBantu() {
            const tipe = document.getElementById('filter-tipe').value;
            const periode = document.getElementById('filter-periode-view').value.trim() || '<?= date("Y/01") ?>';
            const tbody = document.getElementById('tbodyMasterBantu');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:16px;">Memuat data kode pembantu...</td></tr>';

            try {
                const url = `partner_subsidiary.php?action=list_bantu&tipe=${encodeURIComponent(tipe)}&periode=${encodeURIComponent(periode)}`;
                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawMasterData = json.data || [];
                    renderMasterTable(rawMasterData);
                } else {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:16px;">${json.message || 'Gagal memuat data'}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function filterLocalMaster() {
            const q = document.getElementById('search-bantu').value.toLowerCase().trim();
            if (!q) {
                renderMasterTable(rawMasterData);
                return;
            }
            const filtered = rawMasterData.filter(r => 
                (r.kode || '').toLowerCase().includes(q) || 
                (r.nama_akun || '').toLowerCase().includes(q)
            );
            renderMasterTable(filtered);
        }

        function renderMasterTable(rows) {
            const tbody = document.getElementById('tbodyMasterBantu');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:16px; color:var(--text-sub);">Tidak ada kode rekanan pembantu yang sesuai filter.</td></tr>';
                return;
            }

            let html = '';
            rows.forEach((r, idx) => {
                let badgeClass = 'badge-other';
                let tipeLabel = r.tipe || 'LAIN';
                if (r.tipe === 'HU') {
                    badgeClass = 'badge-hu';
                    tipeLabel = 'HU (Hutang)';
                } else if (r.tipe === 'PI') {
                    badgeClass = 'badge-pi';
                    tipeLabel = 'PI (Piutang)';
                }

                const strDb = r.saldoawal_db ? r.saldoawal_db.toLocaleString('id-ID', {minimumFractionDigits: 2}) : '0,00';
                const strKr = r.saldoawal_kr ? r.saldoawal_kr.toLocaleString('id-ID', {minimumFractionDigits: 2}) : '0,00';

                html += `<tr data-idx="${idx}" ondblclick="editRowByIdx(${idx})" onclick="selectMasterRow(this, ${idx})">
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.kode)}</td>
                    <td>${escapeHtml(r.nama_akun)}</td>
                    <td style="text-align:center;"><span class="badge-type ${badgeClass}">${escapeHtml(tipeLabel)}</span></td>
                    <td style="text-align:right; font-family:monospace;">${strDb}</td>
                    <td style="text-align:right; font-family:monospace;">${strKr}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="event.stopPropagation(); editRowByIdx(${idx})" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="event.stopPropagation(); deleteBantu('${escapeHtml(r.kode)}')" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function selectMasterRow(tr, idx) {
            document.querySelectorAll('#tbodyMasterBantu tr').forEach(r => r.classList.remove('selected'));
            tr.classList.add('selected');
            selectedMasterRow = tr;
            selectedMasterIdx = idx;
        }

        function openAddModal() {
            document.getElementById('modalBantuTitle').innerHTML = '<i class="fa-solid fa-user-plus" style="color:var(--primary);"></i> Tambah Kode Rekanan';
            document.getElementById('form-is-edit').value = '0';
            document.getElementById('form-old-kode').value = '';
            document.getElementById('form-kode').value = '';
            document.getElementById('form-kode').readOnly = false;
            document.getElementById('form-nama').value = '';
            document.getElementById('form-tipe').value = 'HU';
            document.getElementById('modalBantu').style.display = 'flex';
            setTimeout(() => document.getElementById('form-kode').focus(), 100);
        }

        function editRowByIdx(idx) {
            const item = rawMasterData[idx];
            if (!item) return;

            document.getElementById('modalBantuTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:var(--primary);"></i> Edit Kode Rekanan';
            document.getElementById('form-is-edit').value = '1';
            document.getElementById('form-old-kode').value = item.kode;
            document.getElementById('form-kode').value = item.kode;
            document.getElementById('form-kode').readOnly = false;
            document.getElementById('form-nama').value = item.nama_akun;
            document.getElementById('form-tipe').value = item.tipe || 'HU';
            document.getElementById('modalBantu').style.display = 'flex';
            setTimeout(() => document.getElementById('form-nama').focus(), 100);
        }

        function closeModal() {
            document.getElementById('modalBantu').style.display = 'none';
        }

        async function submitFormBantu(e) {
            if (e) e.preventDefault();

            const isEdit  = document.getElementById('form-is-edit').value === '1';
            const oldKode = document.getElementById('form-old-kode').value.trim();
            const kode    = document.getElementById('form-kode').value.trim().toUpperCase();
            const nama    = document.getElementById('form-nama').value.trim();
            const tipe    = document.getElementById('form-tipe').value;

            if (!kode || !nama) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Kode dan Nama Akun wajib diisi' });
                return;
            }

            const fd = new FormData();
            fd.append('is_edit', isEdit ? '1' : '0');
            fd.append('old_kode', oldKode);
            fd.append('kode', kode);
            fd.append('nama_akun', nama);
            fd.append('tipe', tipe);

            try {
                const res = await fetch('partner_subsidiary.php?action=save_bantu', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal();
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1500, showConfirmButton: false });
                    loadKPI();
                    loadMasterBantu();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal Simpan', text: json.message || 'Terjadi kesalahan sistem' });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        async function deleteBantu(kode) {
            const result = await SwalCompact.fire({
                icon: 'warning',
                title: 'Konfirmasi Hapus',
                html: `Apakah Anda yakin ingin menghapus Kode Rekanan <b>${escapeHtml(kode)}</b>?<br><span style="color:var(--danger); font-size:8.5px;">Data saldo awal akun ini juga akan dihapus.</span>`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            });

            if (!result.isConfirmed) return;

            const fd = new FormData();
            fd.append('kode', kode);

            try {
                const res = await fetch('partner_subsidiary.php?action=delete_bantu', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1500, showConfirmButton: false });
                    loadKPI();
                    loadMasterBantu();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal Hapus', text: json.message });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        async function syncFromMasterBs() {
            const result = await SwalCompact.fire({
                icon: 'question',
                title: 'Sinkronisasi Rekanan',
                text: 'Tarik seluruh Buyer dan Supplier dari tabel master bc_bs yang belum terdaftar di Kode Pembantu?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Tarik Sekarang',
                cancelButtonText: 'Batal'
            });

            if (!result.isConfirmed) return;

            try {
                const res = await fetch('partner_subsidiary.php?action=sync_from_bs');
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Sinkronisasi Selesai', text: json.message });
                    loadKPI();
                    loadMasterBantu();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        // =====================================================================
        // TAB 2: SALDO AWAL PEMBANTU PER PERIODE
        // =====================================================================
        async function loadPeriodSaldo() {
            const periode = document.getElementById('input-saldo-periode').value.trim() || '<?= date("Y/01") ?>';
            const tipe = document.getElementById('filter-saldo-tipe').value;
            const tbody = document.getElementById('tbodySaldoBantu');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:16px;">Memuat saldo awal pembantu...</td></tr>';

            try {
                const url = `partner_subsidiary.php?action=get_period_saldo&periode=${encodeURIComponent(periode)}&tipe=${encodeURIComponent(tipe)}`;
                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawSaldoData = json.data || [];
                    renderSaldoTable(rawSaldoData);
                    updateSaldoSummary(json.summary);
                } else {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:16px;">${json.message || 'Gagal'}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function filterLocalSaldo() {
            const q = document.getElementById('search-saldo').value.toLowerCase().trim();
            if (!q) {
                renderSaldoTable(rawSaldoData);
                return;
            }
            const filtered = rawSaldoData.filter(r => 
                (r.kode || '').toLowerCase().includes(q) || 
                (r.nama_akun || '').toLowerCase().includes(q)
            );
            renderSaldoTable(filtered);
        }

        function renderSaldoTable(rows) {
            const tbody = document.getElementById('tbodySaldoBantu');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:16px; color:var(--text-sub);">Tidak ada data rekanan untuk periode ini.</td></tr>';
                return;
            }

            let html = '';
            rows.forEach((r, idx) => {
                let badgeClass = r.tipe === 'HU' ? 'badge-hu' : (r.tipe === 'PI' ? 'badge-pi' : 'badge-other');
                const net = (r.saldoawal_db || 0) - (r.saldoawal_kr || 0);

                html += `<tr data-idx="${idx}">
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.kode)}</td>
                    <td>${escapeHtml(r.nama_akun)}</td>
                    <td style="text-align:center;"><span class="badge-type ${badgeClass}">${escapeHtml(r.tipe || 'HU')}</span></td>
                    <td style="padding:1px 4px;">
                        <input type="number" step="0.01" class="ant-input saldo-input-db" data-idx="${idx}" value="${r.saldoawal_db || 0}" style="text-align:right; font-family:monospace; width:100%;" onchange="onSaldoInputChange(${idx})">
                    </td>
                    <td style="padding:1px 4px;">
                        <input type="number" step="0.01" class="ant-input saldo-input-kr" data-idx="${idx}" value="${r.saldoawal_kr || 0}" style="text-align:right; font-family:monospace; width:100%;" onchange="onSaldoInputChange(${idx})">
                    </td>
                    <td style="text-align:right; font-family:monospace; font-weight:600;" id="net-cell-${idx}">
                        ${net.toLocaleString('id-ID', {minimumFractionDigits: 2})}
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function onSaldoInputChange(idx) {
            const inputDb = document.querySelector(`.saldo-input-db[data-idx="${idx}"]`);
            const inputKr = document.querySelector(`.saldo-input-kr[data-idx="${idx}"]`);
            const valDb = parseFloat(inputDb.value) || 0;
            const valKr = parseFloat(inputKr.value) || 0;

            if (rawSaldoData[idx]) {
                rawSaldoData[idx].saldoawal_db = valDb;
                rawSaldoData[idx].saldoawal_kr = valKr;
            }

            const net = valDb - valKr;
            const netCell = document.getElementById(`net-cell-${idx}`);
            if (netCell) {
                netCell.textContent = net.toLocaleString('id-ID', {minimumFractionDigits: 2});
            }

            recalcLocalSaldoSummary();
        }

        function recalcLocalSaldoSummary() {
            let totalDb = 0;
            let totalKr = 0;
            rawSaldoData.forEach(r => {
                totalDb += parseFloat(r.saldoawal_db) || 0;
                totalKr += parseFloat(r.saldoawal_kr) || 0;
            });
            const selisih = totalDb - totalKr;
            updateSaldoSummary({
                total_db: totalDb,
                total_kr: totalKr,
                selisih: selisih,
                is_balance: Math.abs(selisih) < 0.01
            });
        }

        function updateSaldoSummary(summary) {
            if (!summary) return;
            document.getElementById('lbl-total-db').textContent = 'Rp ' + (summary.total_db || 0).toLocaleString('id-ID', {minimumFractionDigits: 2});
            document.getElementById('lbl-total-kr').textContent = 'Rp ' + (summary.total_kr || 0).toLocaleString('id-ID', {minimumFractionDigits: 2});
            document.getElementById('lbl-selisih').textContent = 'Rp ' + (summary.selisih || 0).toLocaleString('id-ID', {minimumFractionDigits: 2});

            const statusContainer = document.getElementById('lbl-balance-status');
            if (summary.is_balance) {
                statusContainer.innerHTML = '<span class="status-pill status-balance"><i class="fa-solid fa-circle-check"></i> SIKLUS SALDO BALANCE</span>';
            } else {
                statusContainer.innerHTML = '<span class="status-pill status-unbalanced"><i class="fa-solid fa-triangle-exclamation"></i> SELISIH DEBET / KREDIT</span>';
            }
        }

        async function savePeriodSaldo() {
            const periode = document.getElementById('input-saldo-periode').value.trim();
            if (!periode) {
                SwalCompact.fire({ icon: 'warning', title: 'Perhatian', text: 'Periode tidak boleh kosong' });
                return;
            }

            const payload = {
                periode: periode,
                items: rawSaldoData.map(r => ({
                    kode: r.kode,
                    saldoawal_db: parseFloat(r.saldoawal_db) || 0,
                    saldoawal_kr: parseFloat(r.saldoawal_kr) || 0
                }))
            };

            try {
                const res = await fetch('partner_subsidiary.php?action=save_period_saldo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil Disimpan', text: json.message });
                    loadPeriodSaldo();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal Simpan', text: json.message });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        // =====================================================================
        // RESIZABLE COLUMNS ENGINE
        // =====================================================================
        function initResizableGrid(tableId, storageKey) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const ths = table.querySelectorAll('thead th');
            const savedWidths = JSON.parse(localStorage.getItem(storageKey) || '{}');

            ths.forEach((th, i) => {
                const colKey = th.getAttribute('data-col') || `col_${i}`;
                if (savedWidths[colKey]) {
                    th.style.width = savedWidths[colKey] + 'px';
                }

                // Add resizer handle
                const resizer = document.createElement('div');
                resizer.className = 'col-resizer';
                th.style.position = 'relative';
                th.appendChild(resizer);

                let startX, startWidth;

                resizer.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    startX = e.pageX;
                    startWidth = th.offsetWidth;
                    resizer.classList.add('resizing');

                    function onMouseMove(e) {
                        const newWidth = Math.max(30, startWidth + (e.pageX - startX));
                        th.style.width = newWidth + 'px';
                    }

                    function onMouseUp() {
                        resizer.classList.remove('resizing');
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

        function resetMasterGridWidths() {
            localStorage.removeItem('grid_bantu_master_widths');
            loadMasterBantu();
            SwalCompact.fire({ icon: 'info', title: 'Reset Kolom', text: 'Ukuran kolom master berhasil dikembalikan ke standar.', timer: 1200, showConfirmButton: false });
        }

        function resetSaldoGridWidths() {
            localStorage.removeItem('grid_bantu_saldo_widths');
            loadPeriodSaldo();
            SwalCompact.fire({ icon: 'info', title: 'Reset Kolom', text: 'Ukuran kolom saldo berhasil dikembalikan ke standar.', timer: 1200, showConfirmButton: false });
        }

        // =====================================================================
        // RIGHT CLICK CONTEXT MENU ENGINE
        // =====================================================================
        function initContextMenu() {
            const menu = document.getElementById('ctxMenu');

            document.addEventListener('click', () => {
                menu.style.display = 'none';
            });

            document.getElementById('tblMasterBantu').addEventListener('contextmenu', (e) => {
                e.preventDefault();
                const tr = e.target.closest('tr');
                if (!tr || !tr.hasAttribute('data-idx')) return;

                const idx = parseInt(tr.getAttribute('data-idx'));
                selectMasterRow(tr, idx);
                rightClickedRowData = rawMasterData[idx];

                menu.style.display = 'block';
                menu.style.left = Math.min(e.pageX, window.innerWidth - 180) + 'px';
                menu.style.top = Math.min(e.pageY, window.innerHeight - 200) + 'px';
            });
        }

        function ctxCopySelectedRow() {
            if (!rightClickedRowData) return;
            const r = rightClickedRowData;
            const text = `${r.kode}\t${r.nama_akun}\t${r.tipe}\t${r.saldoawal_db || 0}\t${r.saldoawal_kr || 0}`;
            navigator.clipboard.writeText(text).then(() => {
                SwalCompact.fire({ icon: 'success', title: 'Tersalin', text: 'Data baris berhasil disalin ke clipboard.', timer: 1000, showConfirmButton: false });
            });
        }

        function ctxCopyAllTSV() {
            if (!rawMasterData || rawMasterData.length === 0) return;
            let tsv = "Kode\tNama Akun\tTipe\tSaldo Debet\tSaldo Kredit\n";
            rawMasterData.forEach(r => {
                tsv += `${r.kode}\t${r.nama_akun}\t${r.tipe}\t${r.saldoawal_db || 0}\t${r.saldoawal_kr || 0}\n`;
            });
            navigator.clipboard.writeText(tsv).then(() => {
                SwalCompact.fire({ icon: 'success', title: 'Tersalin TSV', text: 'Seluruh grid disalin. Siap tempel (Ctrl+V) di Microsoft Excel.', timer: 1500, showConfirmButton: false });
            });
        }

        function ctxExportExcel() {
            exportMasterExcel();
        }

        function ctxDownloadCSV() {
            if (!rawMasterData || rawMasterData.length === 0) return;
            let csv = "Kode,Nama Akun,Tipe,Saldo Debet,Saldo Kredit\n";
            rawMasterData.forEach(r => {
                csv += `"${r.kode}","${(r.nama_akun || '').replace(/"/g, '""')}","${r.tipe}",${r.saldoawal_db || 0},${r.saldoawal_kr || 0}\n`;
            });
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement("a");
            link.href = URL.createObjectURL(blob);
            link.download = `kode_rekanan_pembantu_${Date.now()}.csv`;
            link.click();
        }

        function ctxEditRow() {
            if (selectedMasterIdx >= 0) {
                editRowByIdx(selectedMasterIdx);
            }
        }

        function ctxDeleteRow() {
            if (rightClickedRowData && rightClickedRowData.kode) {
                deleteBantu(rightClickedRowData.kode);
            }
        }

        function ctxResetWidths() {
            resetMasterGridWidths();
        }

        // =====================================================================
        // EXPORT TO EXCEL VIA SHEETJS
        // =====================================================================
        function exportMasterExcel() {
            if (!rawMasterData || rawMasterData.length === 0) {
                SwalCompact.fire({ icon: 'warning', title: 'Kosong', text: 'Tidak ada data untuk diekspor.' });
                return;
            }
            const data = rawMasterData.map((r, i) => ({
                "No": i + 1,
                "Kode Rekanan": r.kode,
                "Nama Akun Subledger": r.nama_akun,
                "Tipe": r.tipe,
                "Saldo Debet": r.saldoawal_db || 0,
                "Saldo Kredit": r.saldoawal_kr || 0
            }));
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Kode Pembantu");
            XLSX.writeFile(wb, `Master_Kode_Pembantu_${Date.now()}.xlsx`);
        }

        function exportSaldoExcel() {
            if (!rawSaldoData || rawSaldoData.length === 0) {
                SwalCompact.fire({ icon: 'warning', title: 'Kosong', text: 'Tidak ada data saldo untuk diekspor.' });
                return;
            }
            const data = rawSaldoData.map((r, i) => ({
                "No": i + 1,
                "Kode Rekanan": r.kode,
                "Nama Akun Subledger": r.nama_akun,
                "Tipe": r.tipe,
                "Saldo Awal Debet": r.saldoawal_db || 0,
                "Saldo Awal Kredit": r.saldoawal_kr || 0,
                "Saldo Bersih": (r.saldoawal_db || 0) - (r.saldoawal_kr || 0)
            }));
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Saldo Awal Pembantu");
            XLSX.writeFile(wb, `Saldo_Awal_Pembantu_${Date.now()}.xlsx`);
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

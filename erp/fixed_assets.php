<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Register & Penyusutan Aktiva Tetap (Fixed Assets)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_material_fa.srw & d_material_cg.srd)
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Unit Aset, Nilai Perolehan, Akumulasi Depresiasi, Nilai Buku Bersih NBV)
 * - Filter Kategori Pills PB: ALL, Machine, Mold, Factory Equipment, Part Machine, Tools, Office Equipments, Vehicles, Buildings, Land
 * - Tab 1: Register Aktiva Tetap (bc_material) dengan modal kalkulator masa manfaat & input modal
 * - Tab 2: Jadwal & Simulasi Penyusutan Garis Lurus (Straight-Line Depreciation Schedule)
 * - Fitur Paste Data dari Excel (TSV Bulk Paste) seperti fitur asli PB (cb_1, cbx_force)
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

// Helper where clause for Fixed Assets
function getFaWhereClause($sessionCmpId, $escCmp) {
    $cmp = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)" : "1=1";
    return "$cmp AND (IFNULL(a.UNIT_ASET, 0) > 0 OR a.KLAS IN (SELECT tipe FROM bc_tipe WHERE grp IN ('CG', 'FA')) OR a.KLAS IN ('Factory Equipment', 'Machine', 'Office Equipments', 'Part Machine', 'Vehicles', 'Buildings', 'Land', 'Tools', 'Mold'))";
}

// =========================================================================
// BACKEND AJAX API: KPI Metrics & Summary
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');

    $whereBase = getFaWhereClause($sessionCmpId, $escCmp);
    $sql = "SELECT 
        COUNT(*) as total_items,
        SUM(IFNULL(a.UNIT_ASET, 1)) as total_units,
        SUM(IFNULL(a.UNIT_ASET, 1) * IFNULL(a.UNIT_PRICE, 0)) as total_perolehan,
        COUNT(DISTINCT a.KLAS) as total_kategori
        FROM bc_material a 
        WHERE $whereBase";
    $res = $koneksi->query($sql);
    $kpi = $res ? $res->fetch_assoc() : [
        'total_items' => 0, 'total_units' => 0, 'total_perolehan' => 0, 'total_kategori' => 0
    ];

    // Hitung estimasi akumulasi depresiasi metode garis lurus
    $sqlDepre = "SELECT 
        a.UNIT_PRICE, a.UNIT_ASET, a.TGL_ASET, a.TAHUN_ASET
        FROM bc_material a 
        WHERE $whereBase AND a.TAHUN_ASET > 0 AND a.TGL_ASET IS NOT NULL";
    $resD = $koneksi->query($sqlDepre);
    $totalDepre = 0;
    $now = new DateTime();

    if ($resD) {
        while ($r = $resD->fetch_assoc()) {
            $cost = floatval($r['UNIT_PRICE']) * max(1, intval($r['UNIT_ASET']));
            $years = floatval($r['TAHUN_ASET']);
            if ($years <= 0 || empty($r['TGL_ASET']) || $r['TGL_ASET'] === '0000-00-00') continue;

            $tgl = new DateTime($r['TGL_ASET']);
            $diffMonths = (($now->format('Y') - $tgl->format('Y')) * 12) + ($now->format('m') - $tgl->format('m'));
            if ($diffMonths < 0) $diffMonths = 0;

            $totalMonths = $years * 12;
            $deprePerMonth = $cost / $totalMonths;
            $accum = min($cost, $deprePerMonth * $diffMonths);
            $totalDepre += $accum;
        }
    }

    $totalPerolehan = floatval($kpi['total_perolehan']);
    $netBookValue   = max(0, $totalPerolehan - $totalDepre);

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_items'     => (int)$kpi['total_items'],
            'total_units'     => (int)$kpi['total_units'],
            'total_perolehan' => $totalPerolehan,
            'total_depresiasi'=> $totalDepre,
            'net_book_value'  => $netBookValue,
            'total_kategori'  => (int)$kpi['total_kategori']
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Fixed Assets (Tab 1)
// =========================================================================
if ($action === 'list_assets') {
    header('Content-Type: application/json; charset=utf-8');

    $where = [getFaWhereClause($sessionCmpId, $escCmp)];

    $klas = trim($_GET['klas'] ?? '');
    if (!empty($klas) && $klas !== 'ALL' && $klas !== '%') {
        $escK = $koneksi->real_escape_string($klas);
        $where[] = "a.KLAS = '$escK'";
    }

    $search = trim($_GET['search'] ?? '');
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(a.KODE_BARANG LIKE '%$escS%' OR a.URAIAN LIKE '%$escS%' OR a.LOKASI LIKE '%$escS%' OR a.POS_TARIF LIKE '%$escS%')";
    }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT 
        a.KODE_BARANG as kode_barang,
        a.URAIAN as uraian,
        a.KLAS as klas,
        IFNULL(a.UNIT_ASET, 1) as unit_aset,
        IFNULL(a.UNIT_PRICE, 0) as unit_price,
        (IFNULL(a.UNIT_ASET, 1) * IFNULL(a.UNIT_PRICE, 0)) as total_cost,
        IFNULL(a.KODE_VALUTA, 'IDR') as valuta,
        IFNULL(a.KODE_SATUAN, 'UNIT') as satuan,
        a.TGL_ASET as tgl_aset,
        IFNULL(a.TAHUN_ASET, 4) as tahun_aset,
        a.POS_TARIF as pos_tarif,
        a.LOKASI as lokasi,
        a.NOTE as note,
        a.GRUP_ASET as grup_aset,
        a.USER_ID as user_id
        FROM bc_material a
        WHERE $whereSql
        ORDER BY a.KLAS ASC, a.KODE_BARANG ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    $now = new DateTime();

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cost = floatval($r['total_cost']);
            $years = floatval($r['tahun_aset']);
            $accum = 0;
            $nbv = $cost;

            if ($years > 0 && !empty($r['tgl_aset']) && $r['tgl_aset'] !== '0000-00-00') {
                $tgl = new DateTime($r['tgl_aset']);
                $diffMonths = (($now->format('Y') - $tgl->format('Y')) * 12) + ($now->format('m') - $tgl->format('m'));
                if ($diffMonths > 0) {
                    $totalMonths = $years * 12;
                    $deprePerMonth = $cost / $totalMonths;
                    $accum = min($cost, $deprePerMonth * $diffMonths);
                    $nbv = max(0, $cost - $accum);
                }
            }

            $r['accum_depre'] = $accum;
            $r['nbv'] = $nbv;
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
// BACKEND AJAX API: Save Asset (Insert / Update)
// =========================================================================
if ($action === 'save_asset') {
    header('Content-Type: application/json; charset=utf-8');

    $isEdit    = !empty($_POST['is_edit']);
    $oldKode   = trim($_POST['old_kode'] ?? '');
    $kode      = strtoupper(trim($_POST['kode_barang'] ?? ''));
    $uraian    = trim($_POST['uraian'] ?? '');
    $klas      = trim($_POST['klas'] ?? 'Machine');
    $unitAset  = max(1, intval($_POST['unit_aset'] ?? 1));
    $unitPrice = floatval($_POST['unit_price'] ?? 0);
    $valuta    = strtoupper(trim($_POST['valuta'] ?? 'IDR'));
    $satuan    = strtoupper(trim($_POST['satuan'] ?? 'UNIT'));
    $tglAset   = trim($_POST['tgl_aset'] ?? '');
    $tahunAset = floatval($_POST['tahun_aset'] ?? 4);
    $posTarif  = trim($_POST['pos_tarif'] ?? '');
    $lokasi    = trim($_POST['lokasi'] ?? '');
    $note      = trim($_POST['note'] ?? '');
    $grupAset  = trim($_POST['grup_aset'] ?? '');

    if (empty($kode) || empty($uraian)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Barang dan Uraian Aset wajib diisi']);
        exit;
    }

    $escKode   = $koneksi->real_escape_string($kode);
    $escUraian = $koneksi->real_escape_string($uraian);
    $escKlas   = $koneksi->real_escape_string($klas);
    $escValuta = $koneksi->real_escape_string($valuta);
    $escSatuan = $koneksi->real_escape_string($satuan);
    $escTgl    = empty($tglAset) ? 'NULL' : "'" . $koneksi->real_escape_string($tglAset) . "'";
    $escTarif  = $koneksi->real_escape_string($posTarif);
    $escLokasi = $koneksi->real_escape_string($lokasi);
    $escNote   = $koneksi->real_escape_string($note);
    $escGrup   = $koneksi->real_escape_string($grupAset);
    $escUser   = $koneksi->real_escape_string($userId);

    if ($isEdit && !empty($oldKode) && $oldKode !== $kode) {
        $escOld = $koneksi->real_escape_string($oldKode);
        $koneksi->query("DELETE FROM bc_material WHERE KODE_BARANG = '$escOld' AND (cmp_id = '$escCmp' OR cmp_id = '')");
    }

    $sql = "INSERT INTO bc_material (
        KODE_BARANG, URAIAN, KLAS, UNIT_ASET, UNIT_PRICE, KODE_VALUTA, KODE_SATUAN,
        TGL_ASET, TAHUN_ASET, POS_TARIF, LOKASI, NOTE, GRUP_ASET, USER_ID, cmp_id, TGL_UPDATE
    ) VALUES (
        '$escKode', '$escUraian', '$escKlas', $unitAset, $unitPrice, '$escValuta', '$escSatuan',
        $escTgl, $tahunAset, '$escTarif', '$escLokasi', '$escNote', '$escGrup', '$escUser', '$escCmp', NOW()
    ) ON DUPLICATE KEY UPDATE 
        URAIAN = '$escUraian', KLAS = '$escKlas', UNIT_ASET = $unitAset, UNIT_PRICE = $unitPrice,
        KODE_VALUTA = '$escValuta', KODE_SATUAN = '$escSatuan', TGL_ASET = $escTgl,
        TAHUN_ASET = $tahunAset, POS_TARIF = '$escTarif', LOKASI = '$escLokasi',
        NOTE = '$escNote', GRUP_ASET = '$escGrup', USER_ID = '$escUser', TGL_UPDATE = NOW()";

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Data Aktiva Tetap berhasil disimpan']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Delete Asset
// =========================================================================
if ($action === 'delete_asset') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim($_POST['kode'] ?? '');
    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode tidak valid']);
        exit;
    }
    $escKode = $koneksi->real_escape_string($kode);
    $del = $koneksi->query("DELETE FROM bc_material WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '')");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => "Aktiva Tetap $kode berhasil dihapus"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Bulk Paste from Excel (Porting PB cb_1 & cbx_force)
// =========================================================================
if ($action === 'paste_from_excel') {
    header('Content-Type: application/json; charset=utf-8');

    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!$req || !isset($req['rows'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Format data tidak valid']);
        exit;
    }

    $rows      = $req['rows'];
    $forceSave = !empty($req['force_save']);
    $success   = 0;
    $errors    = [];

    $koneksi->begin_transaction();
    try {
        foreach ($rows as $r) {
            $kode   = strtoupper(trim($r['kode_barang'] ?? ''));
            $uraian = trim($r['uraian'] ?? '');
            if (empty($kode) || empty($uraian)) continue;

            $klas      = trim($r['klas'] ?? 'Machine');
            $unit      = max(1, intval($r['unit_aset'] ?? 1));
            $price     = floatval($r['unit_price'] ?? 0);
            $valuta    = strtoupper(trim($r['valuta'] ?? 'IDR'));
            $satuan    = strtoupper(trim($r['satuan'] ?? 'UNIT'));
            $tglAset   = trim($r['tgl_aset'] ?? date('Y-m-d'));
            $tahunAset = floatval($r['tahun_aset'] ?? 4);
            $lokasi    = trim($r['lokasi'] ?? '');

            $escKode   = $koneksi->real_escape_string($kode);
            $escUraian = $koneksi->real_escape_string($uraian);
            $escKlas   = $koneksi->real_escape_string($klas);
            $escValuta = $koneksi->real_escape_string($valuta);
            $escSatuan = $koneksi->real_escape_string($satuan);
            $escTgl    = empty($tglAset) ? 'NULL' : "'" . $koneksi->real_escape_string($tglAset) . "'";
            $escLokasi = $koneksi->real_escape_string($lokasi);

            if ($forceSave) {
                $koneksi->query("DELETE FROM bc_material WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '')");
            }

            $sql = "INSERT INTO bc_material (
                KODE_BARANG, URAIAN, KLAS, UNIT_ASET, UNIT_PRICE, KODE_VALUTA, KODE_SATUAN,
                TGL_ASET, TAHUN_ASET, LOKASI, USER_ID, cmp_id, TGL_UPDATE
            ) VALUES (
                '$escKode', '$escUraian', '$escKlas', $unit, $price, '$escValuta', '$escSatuan',
                $escTgl, $tahunAset, '$escLokasi', '$userId', '$escCmp', NOW()
            ) ON DUPLICATE KEY UPDATE 
                URAIAN = '$escUraian', KLAS = '$escKlas', UNIT_ASET = $unit, UNIT_PRICE = $price,
                KODE_VALUTA = '$escValuta', KODE_SATUAN = '$escSatuan', TGL_ASET = $escTgl,
                TAHUN_ASET = $tahunAset, LOKASI = '$escLokasi', USER_ID = '$userId', TGL_UPDATE = NOW()";

            if ($koneksi->query($sql)) {
                $success++;
            }
        }
        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Berhasil memproses $success aktiva tetap dari Excel"]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan massal: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Depreciation Schedule (Tab 2)
// =========================================================================
if ($action === 'depreciation_schedule') {
    header('Content-Type: application/json; charset=utf-8');

    $kode = trim($_GET['kode'] ?? '');
    $where = [getFaWhereClause($sessionCmpId, $escCmp)];
    if (!empty($kode)) {
        $escKode = $koneksi->real_escape_string($kode);
        $where[] = "a.KODE_BARANG = '$escKode'";
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT 
        a.KODE_BARANG as kode_barang,
        a.URAIAN as uraian,
        a.KLAS as klas,
        IFNULL(a.UNIT_ASET, 1) as unit_aset,
        IFNULL(a.UNIT_PRICE, 0) as unit_price,
        (IFNULL(a.UNIT_ASET, 1) * IFNULL(a.UNIT_PRICE, 0)) as total_cost,
        a.TGL_ASET as tgl_aset,
        IFNULL(a.TAHUN_ASET, 4) as tahun_aset
        FROM bc_material a
        WHERE $whereSql AND a.TAHUN_ASET > 0
        ORDER BY a.KLAS ASC, a.KODE_BARANG ASC";
    $res = $koneksi->query($sql);
    $schedules = [];

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cost = floatval($r['total_cost']);
            $years = floatval($r['tahun_aset']);
            $months = max(1, $years * 12);
            $deprePerMonth = $cost / $months;
            $deprePerYear  = $cost / $years;

            $schedules[] = [
                'kode_barang'     => $r['kode_barang'],
                'uraian'          => $r['uraian'],
                'klas'            => $r['klas'],
                'total_cost'      => $cost,
                'tgl_aset'        => $r['tgl_aset'],
                'tahun_aset'      => $years,
                'depre_per_bulan' => $deprePerMonth,
                'depre_per_tahun' => $deprePerYear
            ];
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $schedules]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixed Assets / Aktiva Tetap - CEISA 4.0 & Enterprise Hub</title>

    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SweetAlert2 -->
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>

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
        .icon-green  { background: #f6ffed; color: #52c41a; }
        .icon-amber  { background: #fffbe6; color: #faad14; }
        .icon-purple { background: #f9f0ff; color: #722ed1; }

        /* PowerBuilder Radio Pills Filter Bar */
        .pills-bar {
            display: flex;
            gap: 4px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 4px 6px;
            margin-bottom: 6px;
            overflow-x: auto;
            white-space: nowrap;
            align-items: center;
        }
        .pill-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 500;
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.15s;
        }
        .pill-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .pill-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
            font-weight: 600;
        }

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
            max-height: calc(100vh - 215px);
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
            width: 480px;
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
            max-height: 70vh;
            overflow-y: auto;
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
            width: 120px;
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
            <i class="fa-solid fa-industry"></i>
            <span>Register & Penyusutan Aktiva Tetap (Fixed Assets)</span>
            <span class="portal-badge">Tenant: <?= htmlspecialchars($sessionCmpId) ?></span>
            <span style="font-size: 8.5px; color: var(--text-sub); font-weight: 400; margin-left: 4px;">
                Porting PB w_material_fa.srw & d_material_cg.srd (Capital Goods, Mesin, Kendaraan & Depresiasi)
            </span>
        </div>
        <div class="toolbar-group">
            <button type="button" class="ant-btn ant-btn-sm" onclick="refreshCurrentTab()" title="Muat Ulang Data">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Unit Aktiva Tetap</h5>
                <h3 id="kpi-units">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-boxes-stacked"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Nilai Perolehan</h5>
                <h3 id="kpi-perolehan">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-wallet"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Akumulasi Depresiasi</h5>
                <h3 id="kpi-depresiasi">-</h3>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-calculator"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Nilai Buku Bersih (NBV)</h5>
                <h3 id="kpi-nbv">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-scale-balanced"></i></div>
        </div>
    </div>

    <!-- PowerBuilder Radio Filter Pills -->
    <div class="pills-bar" id="pillsBar">
        <span style="font-size:9px; font-weight:600; color:var(--text-sub); margin-right:4px;">Klasifikasi PB:</span>
        <button type="button" class="pill-btn active" onclick="setKlasFilter('ALL', this)">ALL</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Machine', this)">Machine</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Mold', this)">Mold</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Factory Equipment', this)">Factory Equipment</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Part Machine', this)">Part Machine</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Tools', this)">Tools</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Office Equipments', this)">Office Equipments</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Vehicles', this)">Vehicles</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Buildings', this)">Buildings</button>
        <button type="button" class="pill-btn" onclick="setKlasFilter('Land', this)">Land</button>
    </div>

    <!-- Navigation Tabs -->
    <div class="ant-tabs-nav">
        <button type="button" class="ant-tab-btn active" id="tab-btn-register" onclick="switchTab('register')">
            <i class="fa-solid fa-list-check"></i> 1. Register Aktiva Tetap (Fixed Assets Ledger)
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-depre" onclick="switchTab('depre')">
            <i class="fa-solid fa-calculator"></i> 2. Jadwal & Simulasi Penyusutan Garis Lurus
        </button>
    </div>

    <!-- TAB 1: REGISTER AKTIVA TETAP -->
    <div id="tab-content-register">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <input type="text" id="search-assets" class="ant-input" placeholder="Cari kode barang, uraian, lokasi..." style="width:200px;" oninput="filterLocalAssets()">
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddAssetModal()">
                    <i class="fa-solid fa-plus"></i> Tambah Aktiva
                </button>
                <button type="button" class="ant-btn" onclick="openPasteModal()" title="Salin langsung dari Microsoft Excel (Ctrl+V)">
                    <i class="fa-solid fa-paste"></i> Paste dari Excel
                </button>
                <button type="button" class="ant-btn" onclick="exportAssetsExcel()">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
                <button type="button" class="ant-btn" onclick="resetGridWidths('tblAssets', 'grid_fa_assets_widths')">
                    <i class="fa-solid fa-arrows-rotate"></i> Reset Lebar
                </button>
            </div>
        </div>

        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblAssets">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 100px;" data-col="kode_barang">Kode Barang</th>
                            <th style="width: 250px;" data-col="uraian">Uraian / Nama Aktiva</th>
                            <th style="width: 90px; text-align: center;" data-col="klas">Klasifikasi</th>
                            <th style="width: 45px; text-align: center;" data-col="unit_aset">Unit</th>
                            <th style="width: 45px; text-align: center;" data-col="satuan">Sat</th>
                            <th style="width: 110px; text-align: right;" data-col="total_cost">Nilai Perolehan (Cost)</th>
                            <th style="width: 75px; text-align: center;" data-col="tgl_aset">Tgl Aset</th>
                            <th style="width: 50px; text-align: center;" data-col="tahun_aset">Umur</th>
                            <th style="width: 110px; text-align: right;" data-col="accum_depre">Akumulasi Depr</th>
                            <th style="width: 110px; text-align: right;" data-col="nbv">Nilai Buku (NBV)</th>
                            <th style="width: 80px;" data-col="lokasi">Lokasi</th>
                            <th style="width: 70px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyAssets">
                        <tr><td colspan="13" style="text-align: center; padding: 16px;">Memuat data aktiva tetap...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: JADWAL & SIMULASI PENYUSUTAN GARIS LURUS -->
    <div id="tab-content-depre" style="display:none;">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <span style="font-size:9.5px; font-weight:600; color:var(--text-sub);">
                    Simulasi Beban Penyusutan Garis Lurus (Straight-Line Depreciation Engine)
                </span>
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn" onclick="exportGridExcel('tblDepreSchedule', 'Jadwal_Depresiasi_Aset')">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
            </div>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblDepreSchedule">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 100px;">Kode Barang</th>
                            <th style="width: 250px;">Nama Aktiva</th>
                            <th style="width: 90px; text-align: center;">Klasifikasi</th>
                            <th style="width: 120px; text-align: right;">Nilai Perolehan</th>
                            <th style="width: 80px; text-align: center;">Tgl Perolehan</th>
                            <th style="width: 60px; text-align: center;">Masa (Thn)</th>
                            <th style="width: 120px; text-align: right;">Beban Per Tahun</th>
                            <th style="width: 120px; text-align: right;">Beban Per Bulan</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyDepreSchedule">
                        <tr><td colspan="9" style="text-align: center; padding: 16px;">Memuat jadwal depresiasi...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL TAMBAH / EDIT AKTIVA TETAP -->
    <div class="ant-modal-mask" id="modalAsset">
        <div class="ant-modal" style="width: 500px;">
            <div class="ant-modal-header">
                <span id="modalAssetTitle"><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Tambah Aktiva Tetap</span>
                <span style="cursor:pointer;" onclick="closeModal('modalAsset')"><i class="fa-solid fa-xmark"></i></span>
            </div>
            <div class="ant-modal-body">
                <form id="formAsset" onsubmit="submitFormAsset(event)">
                    <input type="hidden" id="fa-is-edit" value="0">
                    <input type="hidden" id="fa-old-kode" value="">

                    <div class="form-row">
                        <label>Kode Barang <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="fa-kode" class="ant-input" placeholder="Kode unik aktiva" required style="text-transform:uppercase;">
                    </div>
                    <div class="form-row">
                        <label>Uraian / Nama Aset <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="fa-uraian" class="ant-input" placeholder="Deskripsi lengkap aktiva tetap" required>
                    </div>
                    <div class="form-row">
                        <label>Klasifikasi Aset <span style="color:var(--danger)">*</span></label>
                        <select id="fa-klas" class="ant-select" required>
                            <option value="Machine">Machine (Mesin Produksi)</option>
                            <option value="Mold">Mold (Cetakan Moulding)</option>
                            <option value="Factory Equipment">Factory Equipment (Peralatan Pabrik)</option>
                            <option value="Part Machine">Part Machine (Suku Cadang Mesin)</option>
                            <option value="Tools">Tools (Alat Perkakas)</option>
                            <option value="Office Equipments">Office Equipments (Peralatan Kantor)</option>
                            <option value="Vehicles">Vehicles (Kendaraan)</option>
                            <option value="Buildings">Buildings (Gedung & Bangunan)</option>
                            <option value="Land">Land (Tanah)</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Jumlah Unit & Satuan</label>
                        <div style="display:flex; gap:6px; flex:1;">
                            <input type="number" id="fa-unit" class="ant-input" value="1" min="1" style="width:60px;" required>
                            <input type="text" id="fa-satuan" class="ant-input" value="UNIT" style="width:60px; text-transform:uppercase;">
                            <select id="fa-valuta" class="ant-select" style="width:75px;">
                                <option value="IDR">IDR</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Harga Satuan (Cost) <span style="color:var(--danger)">*</span></label>
                        <input type="number" step="0.01" id="fa-price" class="ant-input" value="0" required style="text-align:right; font-family:monospace;">
                    </div>
                    <div class="form-row">
                        <label>Tanggal Perolehan</label>
                        <input type="date" id="fa-tgl" class="ant-input" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-row">
                        <label>Masa Manfaat (Tahun)</label>
                        <input type="number" step="0.5" id="fa-tahun" class="ant-input" value="4" min="0" style="width:80px;" required>
                    </div>
                    <div class="form-row">
                        <label>Pos Tarif (HS Code)</label>
                        <input type="text" id="fa-hs" class="ant-input" placeholder="Pos Tarif Bea Cukai">
                    </div>
                    <div class="form-row">
                        <label>Lokasi / Departemen</label>
                        <input type="text" id="fa-lokasi" class="ant-input" placeholder="Gudang / Ruang / Area Pabrik">
                    </div>
                    <div class="form-row">
                        <label>Catatan / Keterangan</label>
                        <input type="text" id="fa-note" class="ant-input" placeholder="Nomor seri, kondisi, dll">
                    </div>
                </form>
            </div>
            <div class="ant-modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalAsset')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitFormAsset(event)">
                    <i class="fa-solid fa-check"></i> Simpan
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL PASTE DATA DARI EXCEL (Porting PB cb_1 & cbx_force) -->
    <div class="ant-modal-mask" id="modalPaste">
        <div class="ant-modal" style="width: 580px;">
            <div class="ant-modal-header">
                <span><i class="fa-solid fa-paste" style="color:var(--primary);"></i> Paste Data Aktiva Tetap dari Excel</span>
                <span style="cursor:pointer;" onclick="closeModal('modalPaste')"><i class="fa-solid fa-xmark"></i></span>
            </div>
            <div class="ant-modal-body">
                <p style="font-size:9px; color:var(--text-sub); margin-bottom:6px;">
                    Salin (Copy Ctrl+C) kolom dari Excel dengan urutan: <br>
                    <b>Kode Barang</b> | <b>Uraian</b> | <b>Klasifikasi</b> | <b>Unit</b> | <b>Harga Satuan</b> | <b>Tgl Aset (YYYY-MM-DD)</b> | <b>Masa Tahun</b> | <b>Lokasi</b>
                </p>
                <textarea id="paste-area" class="ant-input" style="height:150px; width:100%; font-family:monospace; font-size:9px;" placeholder="Klik di sini lalu tekan Ctrl+V..."></textarea>
                <div style="margin-top:6px; display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="cbx-force-save">
                    <label for="cbx-force-save" style="font-size:9px; font-weight:600; color:var(--text-main);">Force Save (Timpa kode yang sudah ada bila duplikat)</label>
                </div>
            </div>
            <div class="ant-modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalPaste')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="processPasteFromExcel()">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Proses & Simpan
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'register';
        let currentKlas = 'ALL';
        let rawAssetsData = [];

        const SwalCompact = Swal.mixin({
            customClass: {
                popup: 'swal-compact',
                confirmButton: 'ant-btn ant-btn-primary',
                cancelButton: 'ant-btn'
            },
            buttonsStyling: false
        });

        document.addEventListener('DOMContentLoaded', () => {
            initResizableGrid('tblAssets', 'grid_fa_assets_widths');
            loadKPI();
            loadAssets();
        });

        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-btn-register').classList.toggle('active', tab === 'register');
            document.getElementById('tab-btn-depre').classList.toggle('active', tab === 'depre');
            document.getElementById('tab-content-register').style.display = tab === 'register' ? 'block' : 'none';
            document.getElementById('tab-content-depre').style.display = tab === 'depre' ? 'block' : 'none';

            if (tab === 'register') {
                loadAssets();
            } else {
                loadDepreciationSchedule();
            }
        }

        function refreshCurrentTab() {
            loadKPI();
            if (currentTab === 'register') loadAssets();
            else loadDepreciationSchedule();
        }

        function setKlasFilter(klas, btn) {
            currentKlas = klas;
            document.querySelectorAll('#pillsBar .pill-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadAssets();
        }

        async function loadKPI() {
            try {
                const res = await fetch('fixed_assets.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-units').textContent = (json.kpi.total_units || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-perolehan').textContent = 'Rp ' + (json.kpi.total_perolehan || 0).toLocaleString('id-ID', {maximumFractionDigits: 0});
                    document.getElementById('kpi-depresiasi').textContent = 'Rp ' + (json.kpi.total_depresiasi || 0).toLocaleString('id-ID', {maximumFractionDigits: 0});
                    document.getElementById('kpi-nbv').textContent = 'Rp ' + (json.kpi.net_book_value || 0).toLocaleString('id-ID', {maximumFractionDigits: 0});
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function loadAssets() {
            const tbody = document.getElementById('tbodyAssets');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:16px;">Memuat data aktiva tetap...</td></tr>';
            try {
                const url = `fixed_assets.php?action=list_assets&klas=${encodeURIComponent(currentKlas)}`;
                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawAssetsData = json.data || [];
                    renderAssetsTable(rawAssetsData);
                } else {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:var(--danger); padding:16px;">${json.message || 'Gagal memuat data'}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function filterLocalAssets() {
            const q = document.getElementById('search-assets').value.toLowerCase().trim();
            if (!q) {
                renderAssetsTable(rawAssetsData);
                return;
            }
            const filtered = rawAssetsData.filter(r => 
                (r.kode_barang || '').toLowerCase().includes(q) || 
                (r.uraian || '').toLowerCase().includes(q) ||
                (r.lokasi || '').toLowerCase().includes(q) ||
                (r.pos_tarif || '').toLowerCase().includes(q)
            );
            renderAssetsTable(filtered);
        }

        function renderAssetsTable(rows) {
            const tbody = document.getElementById('tbodyAssets');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:16px; color:var(--text-sub);">Tidak ada aktiva tetap untuk klasifikasi ini.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                const costStr = (r.total_cost || 0).toLocaleString('id-ID', {maximumFractionDigits: 0});
                const accumStr = (r.accum_depre || 0).toLocaleString('id-ID', {maximumFractionDigits: 0});
                const nbvStr = (r.nbv || 0).toLocaleString('id-ID', {maximumFractionDigits: 0});

                html += `<tr data-idx="${idx}" ondblclick="editAssetByIdx(${idx})">
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.kode_barang)}</td>
                    <td style="font-weight:500;">${escapeHtml(r.uraian)}</td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:700; padding:1px 5px; border-radius:3px; background:#e6f7ff; color:#096dd9;">${escapeHtml(r.klas)}</span></td>
                    <td style="text-align:center;">${r.unit_aset || 1}</td>
                    <td style="text-align:center; color:var(--text-sub);">${escapeHtml(r.satuan || 'UNIT')}</td>
                    <td style="text-align:right; font-family:monospace;">${costStr}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.tgl_aset || '-')}</td>
                    <td style="text-align:center;">${r.tahun_aset || 4} th</td>
                    <td style="text-align:right; font-family:monospace; color:var(--warning);">${accumStr}</td>
                    <td style="text-align:right; font-family:monospace; font-weight:700; color:var(--success);">${nbvStr}</td>
                    <td>${escapeHtml(r.lokasi || '-')}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="editAssetByIdx(${idx})"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteAsset('${escapeHtml(r.kode_barang)}')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function openAddAssetModal() {
            document.getElementById('modalAssetTitle').innerHTML = '<i class="fa-solid fa-plus" style="color:var(--primary);"></i> Tambah Aktiva Tetap';
            document.getElementById('fa-is-edit').value = '0';
            document.getElementById('fa-old-kode').value = '';
            document.getElementById('fa-kode').value = '';
            document.getElementById('fa-kode').readOnly = false;
            document.getElementById('fa-uraian').value = '';
            document.getElementById('fa-klas').value = currentKlas !== 'ALL' ? currentKlas : 'Machine';
            document.getElementById('fa-unit').value = '1';
            document.getElementById('fa-price').value = '0';
            document.getElementById('fa-tgl').value = '<?= date("Y-m-d") ?>';
            document.getElementById('fa-tahun').value = '4';
            document.getElementById('fa-hs').value = '';
            document.getElementById('fa-lokasi').value = '';
            document.getElementById('fa-note').value = '';
            document.getElementById('modalAsset').style.display = 'flex';
            setTimeout(() => document.getElementById('fa-kode').focus(), 100);
        }

        function editAssetByIdx(idx) {
            const item = rawAssetsData[idx];
            if (!item) return;

            document.getElementById('modalAssetTitle').innerHTML = '<i class="fa-solid fa-pen-to-square" style="color:var(--primary);"></i> Edit Aktiva Tetap';
            document.getElementById('fa-is-edit').value = '1';
            document.getElementById('fa-old-kode').value = item.kode_barang;
            document.getElementById('fa-kode').value = item.kode_barang;
            document.getElementById('fa-kode').readOnly = false;
            document.getElementById('fa-uraian').value = item.uraian;
            document.getElementById('fa-klas').value = item.klas || 'Machine';
            document.getElementById('fa-unit').value = item.unit_aset || 1;
            document.getElementById('fa-price').value = item.unit_price || 0;
            document.getElementById('fa-tgl').value = item.tgl_aset || '<?= date("Y-m-d") ?>';
            document.getElementById('fa-tahun').value = item.tahun_aset || 4;
            document.getElementById('fa-hs').value = item.pos_tarif || '';
            document.getElementById('fa-lokasi').value = item.lokasi || '';
            document.getElementById('fa-note').value = item.note || '';
            document.getElementById('modalAsset').style.display = 'flex';
        }

        async function submitFormAsset(e) {
            if (e) e.preventDefault();

            const fd = new FormData();
            fd.append('is_edit', document.getElementById('fa-is-edit').value);
            fd.append('old_kode', document.getElementById('fa-old-kode').value.trim());
            fd.append('kode_barang', document.getElementById('fa-kode').value.trim().toUpperCase());
            fd.append('uraian', document.getElementById('fa-uraian').value.trim());
            fd.append('klas', document.getElementById('fa-klas').value);
            fd.append('unit_aset', document.getElementById('fa-unit').value);
            fd.append('satuan', document.getElementById('fa-satuan').value.trim());
            fd.append('valuta', document.getElementById('fa-valuta').value);
            fd.append('unit_price', document.getElementById('fa-price').value);
            fd.append('tgl_aset', document.getElementById('fa-tgl').value);
            fd.append('tahun_aset', document.getElementById('fa-tahun').value);
            fd.append('pos_tarif', document.getElementById('fa-hs').value.trim());
            fd.append('lokasi', document.getElementById('fa-lokasi').value.trim());
            fd.append('note', document.getElementById('fa-note').value.trim());

            try {
                const res = await fetch('fixed_assets.php?action=save_asset', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalAsset');
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
                    loadKPI();
                    loadAssets();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        async function deleteAsset(kode) {
            const result = await SwalCompact.fire({
                icon: 'warning',
                title: 'Konfirmasi Hapus',
                text: `Hapus Aktiva Tetap ${kode}?`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Hapus'
            });
            if (!result.isConfirmed) return;

            const fd = new FormData();
            fd.append('kode', kode);

            try {
                const res = await fetch('fixed_assets.php?action=delete_asset', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1000, showConfirmButton: false });
                    loadKPI();
                    loadAssets();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        // TAB 2: DEPRECIATION SCHEDULE
        async function loadDepreciationSchedule() {
            const tbody = document.getElementById('tbodyDepreSchedule');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px;">Memuat simulasi depresiasi...</td></tr>';
            try {
                const res = await fetch('fixed_assets.php?action=depreciation_schedule');
                const json = await res.json();
                if (json.status === 'OK') {
                    renderDepreScheduleTable(json.data || []);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderDepreScheduleTable(rows) {
            const tbody = document.getElementById('tbodyDepreSchedule');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px;">Tidak ada data jadwal penyusutan.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.kode_barang)}</td>
                    <td>${escapeHtml(r.uraian)}</td>
                    <td style="text-align:center;"><span style="font-size:8.5px; padding:1px 4px; border-radius:3px; background:#f0f0f0;">${escapeHtml(r.klas)}</span></td>
                    <td style="text-align:right; font-family:monospace;">${(r.total_cost || 0).toLocaleString('id-ID', {maximumFractionDigits:0})}</td>
                    <td style="text-align:center; font-family:monospace;">${escapeHtml(r.tgl_aset || '-')}</td>
                    <td style="text-align:center;">${r.tahun_aset || 4}</td>
                    <td style="text-align:right; font-family:monospace; color:var(--warning);">${(r.depre_per_tahun || 0).toLocaleString('id-ID', {maximumFractionDigits:0})}</td>
                    <td style="text-align:right; font-family:monospace; font-weight:700; color:var(--success);">${(r.depre_per_bulan || 0).toLocaleString('id-ID', {maximumFractionDigits:0})}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        // EXCEL PASTE
        function openPasteModal() {
            document.getElementById('paste-area').value = '';
            document.getElementById('modalPaste').style.display = 'flex';
            setTimeout(() => document.getElementById('paste-area').focus(), 100);
        }

        async function processPasteFromExcel() {
            const text = document.getElementById('paste-area').value.trim();
            if (!text) {
                SwalCompact.fire({ icon: 'warning', title: 'Kosong', text: 'Silakan tempel (paste) data dari Excel terlebih dahulu' });
                return;
            }

            const lines = text.split('\n');
            const parsed = [];
            for (let line of lines) {
                if (!line.trim()) continue;
                const cols = line.split('\t');
                if (cols.length >= 2) {
                    parsed.push({
                        kode_barang: cols[0]?.trim(),
                        uraian: cols[1]?.trim(),
                        klas: cols[2]?.trim() || 'Machine',
                        unit_aset: parseInt(cols[3]) || 1,
                        unit_price: parseFloat((cols[4] || '0').replace(/[^0-9.-]+/g, '')) || 0,
                        tgl_aset: cols[5]?.trim() || '<?= date("Y-m-d") ?>',
                        tahun_aset: parseFloat(cols[6]) || 4,
                        lokasi: cols[7]?.trim() || ''
                    });
                }
            }

            if (parsed.length === 0) {
                SwalCompact.fire({ icon: 'warning', title: 'Format Tidak Sesuai', text: 'Tidak ada baris data yang berhasil dibaca' });
                return;
            }

            const payload = {
                rows: parsed,
                force_save: document.getElementById('cbx-force-save').checked
            };

            try {
                const res = await fetch('fixed_assets.php?action=paste_from_excel', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalPaste');
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil Diproses', text: json.message });
                    loadKPI();
                    loadAssets();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
        }

        // RESIZABLE GRID
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

        function resetGridWidths(tableId, storageKey) {
            localStorage.removeItem(storageKey);
            loadAssets();
            SwalCompact.fire({ icon: 'info', title: 'Reset Kolom', text: 'Ukuran kolom berhasil dikembalikan ke standar.', timer: 1000, showConfirmButton: false });
        }

        function exportAssetsExcel() {
            if (!rawAssetsData || rawAssetsData.length === 0) {
                SwalCompact.fire({ icon: 'warning', title: 'Kosong', text: 'Tidak ada data untuk diekspor' });
                return;
            }
            const data = rawAssetsData.map((r, i) => ({
                "No": i + 1,
                "Kode Barang": r.kode_barang,
                "Uraian / Nama Aktiva": r.uraian,
                "Klasifikasi": r.klas,
                "Unit": r.unit_aset || 1,
                "Satuan": r.satuan || 'UNIT',
                "Nilai Perolehan (Cost)": r.total_cost || 0,
                "Tgl Aset": r.tgl_aset || '',
                "Masa Manfaat (Tahun)": r.tahun_aset || 4,
                "Akumulasi Depresiasi": r.accum_depre || 0,
                "Nilai Buku Bersih (NBV)": r.nbv || 0,
                "Lokasi": r.lokasi || ''
            }));
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Fixed Assets");
            XLSX.writeFile(wb, `Fixed_Assets_${Date.now()}.xlsx`);
        }

        function exportGridExcel(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const wb = XLSX.utils.table_to_book(table, { sheet: "Sheet1" });
            XLSX.writeFile(wb, `${filename}_${Date.now()}.xlsx`);
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

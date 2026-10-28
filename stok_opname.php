<?php
/**
 * CEISA 4.0 - Warehouse / Inventory: Stok Opname (stok_opname.php)
 * Mengadopsi w_rpt_so.srw & d_rpt_so.srd & w_so.srw
 * Rekonsiliasi Fisik vs Sistem IT Inventory (bc_so & bc_material)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'SYSTEM');
$sessionCmpId = $_SESSION['company_id'] ?? ($_GET['cmp_id'] ?? '');
$escSessionCmp = $koneksi->real_escape_string($sessionCmpId);
$cmpFilter = !empty($sessionCmpId) ? " AND (a.cmp_id = '$escSessionCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)" : "";
$theme = $_GET['theme'] ?? ($_SESSION['theme'] ?? 'light');

$action = $_REQUEST['action'] ?? '';

// 1. AJAX: LIST DATA STOK OPNAME
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $tgl    = trim($_GET['tgl'] ?? '');
    $klas   = trim($_GET['klas'] ?? '');
    $diff   = trim($_GET['diff'] ?? ''); // 'selisih', 'sesuai', ''
    $search = trim($_GET['q'] ?? '');

    $where = ["1=1"];
    if (!empty($sessionCmpId)) {
        $where[] = "(a.cmp_id = '$escSessionCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)";
    }
    if (!empty($tgl)) {
        $where[] = "a.tgl_so = '" . $koneksi->real_escape_string($tgl) . "'";
    }
    if (!empty($klas)) {
        $where[] = "b.KLAS = '" . $koneksi->real_escape_string($klas) . "'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(a.kode_barang LIKE '%$s%' OR b.URAIAN LIKE '%$s%' OR a.note LIKE '%$s%')";
    }
    if ($diff === 'selisih') {
        $where[] = "a.selisih <> 0";
    } elseif ($diff === 'sesuai') {
        $where[] = "a.selisih = 0";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT 
        a.tgl_so, a.kode_barang, b.URAIAN, b.KLAS, a.unit, a.stok, a.sesuai, 
        a.aktual, a.selisih, a.note, a.set_by, a.tipe, a.no_spp
    FROM bc_so a
    LEFT JOIN bc_material b ON b.cmp_id = a.cmp_id AND b.KODE_BARANG = a.kode_barang
    $whereSql
    ORDER BY a.tgl_so DESC, b.KLAS ASC, a.kode_barang ASC
    LIMIT 500";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // Ambil daftar tanggal SO yang tersedia untuk dropdown
    $dateWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escSessionCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";
    $datesRes = $koneksi->query("SELECT DISTINCT tgl_so FROM bc_so $dateWhere ORDER BY tgl_so DESC LIMIT 30");
    $dates = [];
    if ($datesRes) {
        while ($r = $datesRes->fetch_assoc()) {
            if (!empty($r['tgl_so'])) {
                $dates[] = $r['tgl_so'];
            }
        }
    }

    // KPI Counters
    $kpiWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escSessionCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "WHERE 1=1";
    if (!empty($tgl)) {
        $kpiWhere .= " AND tgl_so = '" . $koneksi->real_escape_string($tgl) . "'";
    }
    $kpiTotal = $koneksi->query("SELECT COUNT(1) FROM bc_so $kpiWhere")->fetch_row()[0] ?? 0;
    $kpiSesuai = $koneksi->query("SELECT COUNT(1) FROM bc_so $kpiWhere AND selisih = 0")->fetch_row()[0] ?? 0;
    $kpiSelisih = $koneksi->query("SELECT COUNT(1) FROM bc_so $kpiWhere AND selisih <> 0")->fetch_row()[0] ?? 0;
    $kpiQtySelisih = $koneksi->query("SELECT COALESCE(SUM(ABS(selisih)), 0) FROM bc_so $kpiWhere")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'data' => $data,
        'dates' => $dates,
        'count' => count($data),
        'kpi' => [
            'total' => (int)$kpiTotal,
            'sesuai' => (int)$kpiSesuai,
            'selisih' => (int)$kpiSelisih,
            'qty_selisih' => (float)$kpiQtySelisih
        ]
    ]);
    exit;
}

// 2. AJAX: LOOKUP BARANG DARI bc_material (AUTOFILL)
if ($action === 'lookup_barang') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = strtoupper(trim($_GET['kode'] ?? ''));
    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode kosong']);
        exit;
    }
    $escK = $koneksi->real_escape_string($kode);
    $q = $koneksi->query("SELECT KODE_BARANG, URAIAN, KLAS, KODE_SATUAN FROM bc_material WHERE KODE_BARANG = '$escK' LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        // Cek stok sistem dari bc_1ab2 jika ada
        $stokSys = 0;
        $qStok = $koneksi->query("SELECT COALESCE(SUM(qty), 0) FROM bc_1ab2 WHERE KODE_BARANG = '$escK'");
        if ($qStok && $rStok = $qStok->fetch_row()) {
            $stokSys = (float)$rStok[0];
        }
        echo json_encode([
            'status' => 'OK',
            'data' => [
                'kode_barang' => $r['KODE_BARANG'],
                'uraian' => $r['URAIAN'],
                'klas' => $r['KLAS'],
                'unit' => $r['KODE_SATUAN'] ?? 'PCE',
                'stok_sistem' => $stokSys
            ]
        ]);
    } else {
        echo json_encode(['status' => 'NOT_FOUND']);
    }
    exit;
}

// 3. AJAX: SIMPAN / UPDATE ITEM STOK OPNAME
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $tgl_so      = trim($_POST['tgl_so'] ?? date('Y-m-d'));
    $kode_barang = strtoupper(trim($_POST['kode_barang'] ?? ''));
    $unit        = strtoupper(trim($_POST['unit'] ?? 'PCE'));
    $stok        = floatval($_POST['stok'] ?? 0);
    $aktual      = floatval($_POST['aktual'] ?? 0);
    $note        = trim($_POST['note'] ?? '');

    if (empty($kode_barang)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode barang wajib diisi.']);
        exit;
    }

    $selisih = $aktual - $stok;
    $sesuai  = ($selisih == 0) ? 'Y' : 'T';

    $escTgl  = $koneksi->real_escape_string($tgl_so);
    $escKode = $koneksi->real_escape_string($kode_barang);
    $escUnit = $koneksi->real_escape_string($unit);
    $escNote = $koneksi->real_escape_string($note);
    $escUser = $koneksi->real_escape_string($sessionUser);

    $cmpCheck = !empty($sessionCmpId) ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";
    $cek = $koneksi->query("SELECT COUNT(1) FROM bc_so WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'$cmpCheck")->fetch_row()[0] ?? 0;
    if ($cek > 0) {
        $sql = "UPDATE bc_so SET 
            unit = '$escUnit', stok = $stok, aktual = $aktual, selisih = $selisih, 
            sesuai = '$sesuai', note = '$escNote', set_by = '$escUser'
            WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'$cmpCheck";
    } else {
        $sql = "INSERT INTO bc_so 
            (tgl_so, kode_barang, unit, stok, sesuai, aktual, selisih, note, set_by, cmp_id)
            VALUES 
            ('$escTgl', '$escKode', '$escUnit', $stok, '$sesuai', $aktual, $selisih, '$escNote', '$escUser', '$escSessionCmp')";
    }

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Data Stok Opname berhasil disimpan.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan: ' . $koneksi->error]);
    }
    exit;
}

// 4. AJAX: HAPUS RECORD STOK OPNAME
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $tgl_so = trim($_POST['tgl_so'] ?? '');
    $kode   = trim($_POST['kode_barang'] ?? '');

    $escTgl  = $koneksi->real_escape_string($tgl_so);
    $escKode = $koneksi->real_escape_string($kode);
    $cmpDel = !empty($sessionCmpId) ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";

    $del = $koneksi->query("DELETE FROM bc_so WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'$cmpDel");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Record Stok Opname berhasil dihapus.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 5. AJAX: IMPORT DATA DARI EXCEL (BATCH UPSERT STOK OPNAME)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');
    $rowsJson = $_POST['rows'] ?? '[]';
    $rows = json_decode($rowsJson, true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data impor kosong atau format tidak sesuai.']);
        exit;
    }

    $inserted = 0;
    $updated = 0;
    $escUser = $koneksi->real_escape_string($sessionUser);

    foreach ($rows as $r) {
        $kode = strtoupper(trim($r['kode_barang'] ?? ($r['KODE_BARANG'] ?? ($r['Kode Barang'] ?? ''))));
        if (empty($kode)) continue;

        $tgl = trim($r['tgl_so'] ?? ($r['TGL_SO'] ?? ($r['Tanggal SO'] ?? ($r['tgl'] ?? date('Y-m-d')))));
        $unit = strtoupper(trim($r['unit'] ?? ($r['UNIT'] ?? ($r['Satuan'] ?? 'PCE'))));
        $stok = floatval(str_replace(',', '', $r['stok'] ?? ($r['STOK'] ?? ($r['Stok Sistem'] ?? 0))));
        $aktual = floatval(str_replace(',', '', $r['aktual'] ?? ($r['AKTUAL'] ?? ($r['Stok Fisik'] ?? ($r['Stok Aktual'] ?? 0)))));
        $selisih = $aktual - $stok;
        $sesuai = ($selisih == 0) ? 'Y' : 'T';
        $note = trim($r['note'] ?? ($r['NOTE'] ?? ($r['Catatan'] ?? ($r['Keterangan'] ?? 'Import Excel'))));

        $escKode = $koneksi->real_escape_string($kode);
        $escTgl = $koneksi->real_escape_string($tgl);
        $escUnit = $koneksi->real_escape_string($unit);
        $escNote = $koneksi->real_escape_string($note);
        $cmpChk = !empty($sessionCmpId) ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";

        $chk = $koneksi->query("SELECT 1 FROM bc_so WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'$cmpChk LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $koneksi->query("UPDATE bc_so SET unit = '$escUnit', stok = $stok, aktual = $aktual, selisih = $selisih, sesuai = '$sesuai', note = '$escNote', set_by = '$escUser' WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'$cmpChk");
            $updated++;
        } else {
            $koneksi->query("INSERT INTO bc_so (tgl_so, kode_barang, unit, stok, sesuai, aktual, selisih, note, set_by, cmp_id) VALUES ('$escTgl', '$escKode', '$escUnit', $stok, '$sesuai', $aktual, $selisih, '$escNote', '$escUser', '$escSessionCmp')");
            $inserted++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Proses Impor Stok Opname Berhasil: $inserted item baru, $updated item diperbarui.",
        'inserted' => $inserted,
        'updated' => $updated
    ]);
    exit;
}

// 6. QUERY KLASIFIKASI DARI bc_tipe (WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx)
$klasList = [];
$qKlas = $koneksi->query("SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx");
if ($qKlas) {
    while ($rk = $qKlas->fetch_assoc()) {
        $klasList[] = $rk;
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <title>Stok Opname - Rekonsiliasi Fisik vs IT Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --border-color: #e8e8e8;
            --text-main: rgba(0, 0, 0, 0.85);
            --text-heading: #262626;
            --text-muted: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-hover: #f0f7ff;
            --table-head-bg: #fafafa;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            color-scheme: light;
        }
        [data-theme='dark'], html[data-theme="dark"], body[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --table-hover: #262638;
            --table-head-bg: #1d1d1d;
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            color-scheme: dark !important;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-size: 10.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Top Page Header */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--border-color);
        }
        .page-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .page-subtitle {
            font-size: 9.5px;
            color: var(--text-muted);
            font-weight: 400;
        }

        /* KPI Container & Cards */
        .kpi-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 6px;
            margin-bottom: 6px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 4px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
        }
        .kpi-card.card-blue::before { background: var(--primary); }
        .kpi-card.card-green::before { background: var(--success); }
        .kpi-card.card-red::before { background: var(--danger); }
        .kpi-card.card-amber::before { background: var(--warning); }
        
        .kpi-card:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }
        .kpi-info { display: flex; flex-direction: column; gap: 1px; }
        .kpi-title { font-size: 9px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.3px; }
        .kpi-value { font-size: 13px; font-weight: 700; color: var(--text-heading); }
        .kpi-icon-wrap {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
        }
        .card-blue .kpi-icon-wrap { background: rgba(24, 144, 255, 0.12); color: var(--primary); }
        .card-green .kpi-icon-wrap { background: rgba(82, 196, 26, 0.12); color: var(--success); }
        .card-red .kpi-icon-wrap { background: rgba(255, 77, 79, 0.12); color: var(--danger); }
        .card-amber .kpi-icon-wrap { background: rgba(250, 173, 20, 0.12); color: var(--warning); }

        /* Toolbar Filter Card */
        .toolbar-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 5px 8px;
            margin-bottom: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .filter-label { font-size: 9.5px; font-weight: 600; text-transform: uppercase; color: var(--text-muted); display: inline-flex; align-items: center; gap: 4px; }
        
        /* Form Inputs */
        input, select {
            font-family: inherit;
            font-size: 9.5px;
            height: 22px;
            padding: 1px 6px;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s;
        }
        input:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15);
        }
        select option {
            background-color: var(--bg-card);
            color: var(--text-main);
        }

        /* Buttons */
        .btn-primary, .ant-btn-primary {
            background: var(--primary);
            color: #fff;
            border: 1px solid var(--primary);
            cursor: pointer;
            font-weight: 500;
            font-size: 10px;
            height: 22px;
            padding: 0 8px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            line-height: 1;
            transition: all 0.2s;
        }
        .btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color: #fff; }
        
        .btn-success {
            background: var(--success);
            color: #fff;
            border: 1px solid var(--success);
            cursor: pointer;
            font-weight: 500;
            font-size: 10px;
            height: 22px;
            padding: 0 8px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            line-height: 1;
            transition: all 0.2s;
        }
        .btn-success:hover { background: #49ad16; border-color: #49ad16; color: #fff; }

        .btn-default, .ant-btn {
            background: var(--bg-card);
            color: var(--text-main);
            border: 1px solid var(--border-input);
            cursor: pointer;
            font-size: 10px;
            height: 22px;
            padding: 0 8px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            line-height: 1;
            transition: all 0.2s;
        }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }

        .btn-sm-action {
            height: 20px;
            padding: 0 5px;
            font-size: 9px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            cursor: pointer;
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-main);
            transition: all 0.15s;
        }
        .btn-sm-action:hover { border-color: var(--primary); color: var(--primary); }
        .btn-sm-action.del:hover { border-color: var(--danger); color: var(--danger); }
        
        /* Table Layout */
        .table-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            overflow: auto;
            max-height: calc(100vh - 160px);
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        table { width: 100%; border-collapse: collapse; font-size: 10px; text-align: left; }
        th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border-bottom: 2px solid var(--border-color);
            padding: 4px 6px;
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-heading);
            white-space: nowrap;
            z-index: 2;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
            vertical-align: middle;
            color: var(--text-main);
        }
        tr:hover td { background-color: var(--table-hover) !important; }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 9.5px;
            font-weight: 600;
            line-height: 1.2;
        }
        .badge-match {
            background: rgba(82, 196, 26, 0.12);
            color: var(--success);
            border: 1px solid rgba(82, 196, 26, 0.3);
        }
        .badge-diff {
            background: rgba(255, 77, 79, 0.12);
            color: var(--danger);
            border: 1px solid rgba(255, 77, 79, 0.3);
        }
        .badge-qty-diff {
            display: inline-block;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
        }

        /* Modal Dialogs - MUST HAVE display: none by default! */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.55);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(3px);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }
        .modal.show {
            display: flex !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }
        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            width: 92%;
            max-width: 480px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
            animation: modalFadeIn 0.2s ease;
            overflow: hidden;
        }
        @keyframes modalFadeIn {
            from { transform: translateY(-15px) scale(0.98); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        .modal-header {
            padding: 10px 14px;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--text-heading);
        }
        .modal-header h3 { font-size: 12.5px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 7px; }
        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 18px;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
            transition: color 0.15s;
        }
        .modal-close-btn:hover { color: var(--danger); }
        .modal-body { padding: 12px 16px; font-size: 11px; }
        .modal-footer {
            padding: 8px 14px;
            background: var(--table-head-bg);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .form-group { margin-bottom: 9px; }
        .form-group label {
            display: block;
            margin-bottom: 3px;
            color: var(--text-muted);
            font-size: 9.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-group input, .form-group select { width: 100%; height: 28px; }
    </style>
</head>
<body>

    <!-- Top Page Header -->
    <div class="page-header">
        <div class="page-title">
            <i class="fa-solid fa-boxes-packing" style="color:var(--primary);"></i>
            <span>Stok Opname</span>
            <span class="page-subtitle">(Rekonsiliasi Fisik Gudang vs IT Inventory)</span>
        </div>
        <div>
            <span style="font-size: 10px; color: var(--text-muted);">
                <i class="fa-solid fa-clock-rotate-left"></i> Modul Warehouse Rekap SO
            </span>
        </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="kpi-container">
        <div class="kpi-card card-blue">
            <div class="kpi-info">
                <span class="kpi-title">Total Item Opname</span>
                <span class="kpi-value" id="kpi-total">0</span>
            </div>
            <div class="kpi-icon-wrap">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
        </div>
        <div class="kpi-card card-green">
            <div class="kpi-info">
                <span class="kpi-title">Stok Sesuai (Match)</span>
                <span class="kpi-value" id="kpi-sesuai" style="color: var(--success);">0</span>
            </div>
            <div class="kpi-icon-wrap">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div class="kpi-card card-red">
            <div class="kpi-info">
                <span class="kpi-title">Ada Selisih (Varian)</span>
                <span class="kpi-value" id="kpi-selisih" style="color: var(--danger);">0</span>
            </div>
            <div class="kpi-icon-wrap">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
        </div>
        <div class="kpi-card card-amber">
            <div class="kpi-info">
                <span class="kpi-title">Total Varian Qty (Absolut)</span>
                <span class="kpi-value" id="kpi-qty-selisih">0</span>
            </div>
            <div class="kpi-icon-wrap">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
        </div>
    </div>

    <!-- Toolbar Filters & Actions -->
    <div class="toolbar-card">
        <div class="toolbar-left">
            <span class="filter-label"><i class="fa-solid fa-calendar-day"></i> Tgl SO:</span>
            <select id="filter-tgl" style="min-width: 130px;" onchange="loadData()">
                <option value="">Semua Tanggal SO</option>
            </select>

            <span class="filter-label"><i class="fa-solid fa-tags"></i> Klas:</span>
            <select id="filter-klas" style="min-width: 120px;" onchange="loadData()">
                <option value="">Semua Klasifikasi</option>
                <?php foreach ($klasList as $k): ?>
                    <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                <?php endforeach; ?>
            </select>

            <span class="filter-label"><i class="fa-solid fa-scale-balanced"></i> Kondisi:</span>
            <select id="filter-diff" style="min-width: 130px;" onchange="loadData()">
                <option value="">Semua Kondisi</option>
                <option value="selisih">Hanya yang Selisih</option>
                <option value="sesuai">Hanya yang Sesuai</option>
            </select>

            <input type="text" id="filter-search" placeholder="Cari kode/nama barang..." style="width: 180px;" onkeyup="if(event.key==='Enter') loadData()">
            <button class="btn-default" onclick="loadData()"><i class="fa-solid fa-arrows-rotate"></i> Filter</button>
        </div>
        <div class="toolbar-right">
            <button class="btn-primary" onclick="openNewModal()"><i class="fa-solid fa-plus"></i> Input Hasil Opname</button>
            <button class="btn-success" onclick="openImportModal()"><i class="fa-solid fa-file-excel"></i> Import Excel</button>
            <button class="btn-default" onclick="exportExcel()"><i class="fa-solid fa-file-export"></i> Ekspor Excel</button>
        </div>
    </div>

    <!-- Main Data Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 35px; text-align: center;">#</th>
                    <th>Tanggal SO</th>
                    <th>Kode Barang</th>
                    <th>Uraian Barang</th>
                    <th>Klasifikasi</th>
                    <th>Satuan</th>
                    <th style="text-align: right;">Stok Sistem</th>
                    <th style="text-align: right;">Stok Fisik (Aktual)</th>
                    <th style="text-align: right;">Selisih (Varian)</th>
                    <th style="text-align: center;">Kesesuaian</th>
                    <th>Keterangan / Alasan</th>
                    <th>Set By</th>
                    <th style="text-align: center; width: 80px;">Aksi</th>
                </tr>
            </thead>
            <tbody id="table-body">
                <tr><td colspan="13" style="text-align: center; padding: 25px; color: var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data Stok Opname...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Modal Form (Input / Edit) -->
    <div class="modal" id="modal-form">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa-solid fa-pen-to-square" style="color:var(--primary);"></i> Input / Edit Stok Opname</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Tanggal Pelaksanaan SO</label>
                        <input type="date" id="form-tgl" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Satuan Barang</label>
                        <input type="text" id="form-unit" value="PCE">
                    </div>
                </div>
                <div class="form-group">
                    <label>Kode Barang</label>
                    <div style="display:flex; gap: 4px;">
                        <input type="text" id="form-kode" placeholder="Ketik kode barang..." style="flex:1;" onchange="lookupBarang()">
                        <button type="button" class="btn-default" onclick="lookupBarang()"><i class="fa-solid fa-magnifying-glass"></i> Cek</button>
                    </div>
                    <div id="form-uraian-hint" style="font-size: 10px; color: var(--text-muted); margin-top: 3px; min-height: 14px;"></div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Stok Sistem (Buku)</label>
                        <input type="number" step="0.01" id="form-stok" value="0.00" oninput="calcDiff()">
                    </div>
                    <div class="form-group">
                        <label>Stok Fisik (Aktual)</label>
                        <input type="number" step="0.01" id="form-aktual" value="0.00" oninput="calcDiff()">
                    </div>
                </div>
                <div class="form-group" style="background: rgba(0,0,0,0.02); border: 1px dashed var(--border-color); border-radius: 4px; padding: 6px 10px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:10px; font-weight:600; color:var(--text-muted);">HASIL SELISIH:</span>
                    <strong id="lbl-diff" style="font-size:13px; color: var(--success);">0.00</strong>
                </div>
                <div class="form-group">
                    <label>Catatan / Keterangan Selisih</label>
                    <input type="text" id="form-note" placeholder="Misal: Selisih timbangan, susut, barang cacat, dll">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-default" onclick="closeModal()">Batal</button>
                <button type="button" class="btn-primary" onclick="submitForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Record</button>
            </div>
        </div>
    </div>

    <!-- Modal Import Excel -->
    <div class="modal" id="modal-import">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Import Hasil Stok Opname dari Excel</h3>
                <button type="button" class="modal-close-btn" onclick="closeImportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="background:rgba(24,144,255,0.06); border:1px solid rgba(24,144,255,0.25); border-radius:4px; padding:8px 12px; font-size:10.5px; margin-bottom: 10px;">
                    <strong style="color:var(--primary);"><i class="fa-solid fa-circle-info"></i> Petunjuk Import:</strong>
                    <ul style="margin:4px 0 0 16px; padding:0; color:var(--text-main);">
                        <li>Format kolom Excel: <code>tgl_so</code>, <code>kode_barang</code>, <code>unit</code>, <code>stok</code>, <code>aktual</code>, <code>note</code></li>
                        <li>Format tanggal: YYYY-MM-DD (contoh: <?= date('Y-m-d') ?>).</li>
                        <li>Sistem otomatis menghitung selisih (<code>aktual - stok</code>) dan status kesesuaian.</li>
                    </ul>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                    <button type="button" class="btn-default" onclick="downloadTemplate()">
                        <i class="fa-solid fa-download" style="color:var(--success);"></i> Download Template (.xlsx)
                    </button>
                    <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelUpload(event)">
                    <button type="button" class="btn-primary" onclick="document.getElementById('excelFileInput').click()">
                        <i class="fa-solid fa-folder-open"></i> Pilih File Excel...
                    </button>
                </div>

                <div id="dropZone" style="border: 2px dashed var(--border-color); border-radius: 6px; padding: 22px; text-align: center; cursor: pointer; background: rgba(0,0,0,0.015); margin-bottom: 10px;" onclick="document.getElementById('excelFileInput').click()">
                    <div style="font-size: 24px; margin-bottom: 4px; color:var(--primary);"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <div style="font-weight: 600; font-size: 11px;">Tarik dan letakkan file Excel di sini atau Klik untuk memilih file</div>
                    <div style="font-size: 9.5px; color: var(--text-muted); margin-top: 3px;">Mendukung format .xlsx, .xls, atau .csv</div>
                </div>

                <!-- Preview Table -->
                <div id="importPreviewContainer" style="display:none; max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 4px;">
                    <div style="padding: 5px 10px; background: var(--table-head-bg); font-weight: 600; font-size: 10.5px; border-bottom: 1px solid var(--border-color); display:flex; justify-content:space-between;">
                        <span id="previewCountText">Preview Data: 0 baris</span>
                        <span style="color:var(--text-muted); font-size:9.5px;">Menampilkan 10 baris pertama</span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal SO</th>
                                <th>Kode Barang</th>
                                <th>Satuan</th>
                                <th style="text-align:right;">Stok Sistem</th>
                                <th style="text-align:right;">Stok Fisik</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody id="previewBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-default" onclick="closeImportModal()">Batal</button>
                <button type="button" class="btn-success" id="btnExecuteImport" style="display:none;" onclick="executeImport()">
                    <i class="fa-solid fa-check"></i> Mulai Impor Data
                </button>
            </div>
        </div>
    </div>

    <script>
        const sessionCmpId = <?= json_encode($sessionCmpId) ?>;
        let currentData = [];

        function calcDiff() {
            const stok = parseFloat(document.getElementById('form-stok').value) || 0;
            const aktual = parseFloat(document.getElementById('form-aktual').value) || 0;
            const diff = (aktual - stok).toFixed(2);
            const lbl = document.getElementById('lbl-diff');
            lbl.innerText = (diff > 0 ? '+' : '') + diff;
            if (diff == 0) {
                lbl.style.color = 'var(--success)';
            } else if (diff < 0) {
                lbl.style.color = 'var(--danger)';
            } else {
                lbl.style.color = 'var(--warning)';
            }
        }

        async function lookupBarang() {
            const kode = document.getElementById('form-kode').value.trim();
            const hint = document.getElementById('form-uraian-hint');
            if (!kode) {
                hint.innerText = '';
                return;
            }
            hint.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mencari barang...';
            try {
                const res = await fetch(`stok_opname.php?action=lookup_barang&kode=${encodeURIComponent(kode)}&cmp_id=${encodeURIComponent(sessionCmpId)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    hint.innerHTML = `<span style="color:var(--success);"><i class="fa-solid fa-circle-check"></i> ${escapeHtml(json.data.uraian)} (${escapeHtml(json.data.klas)})</span>`;
                    document.getElementById('form-unit').value = json.data.unit || 'PCE';
                    document.getElementById('form-stok').value = Number(json.data.stok_sistem).toFixed(2);
                    calcDiff();
                } else {
                    hint.innerHTML = `<span style="color:var(--text-muted);"><i class="fa-solid fa-circle-question"></i> Kode belum ada di master barang (akan dibuat otomatis jika disimpan).</span>`;
                }
            } catch (e) {
                hint.innerText = '';
            }
        }

        async function loadData() {
            const tgl = document.getElementById('filter-tgl').value;
            const klas = document.getElementById('filter-klas').value;
            const diff = document.getElementById('filter-diff').value;
            const q = document.getElementById('filter-search').value;

            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; padding: 25px; color: var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data Stok Opname...</td></tr>';

            try {
                const url = `stok_opname.php?action=list&tgl=${encodeURIComponent(tgl)}&klas=${encodeURIComponent(klas)}&diff=${diff}&q=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(sessionCmpId)}`;
                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'OK') {
                    currentData = json.data;
                    document.getElementById('kpi-total').innerText = Number(json.kpi.total).toLocaleString('id-ID');
                    document.getElementById('kpi-sesuai').innerText = Number(json.kpi.sesuai).toLocaleString('id-ID');
                    document.getElementById('kpi-selisih').innerText = Number(json.kpi.selisih).toLocaleString('id-ID');
                    document.getElementById('kpi-qty-selisih').innerText = Number(json.kpi.qty_selisih).toLocaleString('id-ID', {minimumFractionDigits: 2});

                    // Update date dropdown if empty
                    const selectTgl = document.getElementById('filter-tgl');
                    if (selectTgl.options.length <= 1 && json.dates && json.dates.length > 0) {
                        json.dates.forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d;
                            opt.innerText = d;
                            selectTgl.appendChild(opt);
                        });
                    }

                    renderTable(json.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--danger); padding:20px;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: var(--danger); padding:20px;">Error: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('table-body');
            if (data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="13" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                            <div style="font-size: 28px; margin-bottom: 8px; opacity: 0.5;"><i class="fa-solid fa-boxes-stacked"></i></div>
                            <div style="font-weight: 600; font-size: 12px; margin-bottom: 4px;">Tidak ada record Stok Opname ditemukan</div>
                            <div style="font-size: 10px;">Silakan klik <strong>Input Hasil Opname</strong> atau <strong>Import Excel</strong> untuk memasukkan hasil rekonsiliasi.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = data.map((r, i) => {
                const selisih = parseFloat(r.selisih) || 0;
                let badge = '<span class="badge badge-match"><i class="fa-solid fa-check"></i> Sesuai</span>';
                let diffStyle = 'color: var(--success);';
                if (selisih !== 0) {
                    badge = `<span class="badge badge-diff"><i class="fa-solid fa-triangle-exclamation"></i> Selisih</span>`;
                    diffStyle = selisih < 0 ? 'color: var(--danger);' : 'color: var(--warning);';
                }

                return `
                    <tr>
                        <td style="text-align: center; color: var(--text-muted); font-size: 10px;">${i + 1}</td>
                        <td style="font-weight: 500;">${r.tgl_so}</td>
                        <td style="font-weight: 600; color: var(--primary);">${escapeHtml(r.kode_barang)}</td>
                        <td>${escapeHtml(r.URAIAN || '-')}</td>
                        <td><span style="font-size: 9.5px; font-weight: 600; padding: 1px 5px; border-radius: 2px; background: rgba(0,0,0,0.04);">${escapeHtml(r.KLAS || '-')}</span></td>
                        <td>${escapeHtml(r.unit || '-')}</td>
                        <td style="text-align: right; font-weight: 500;">${Number(r.stok || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.aktual || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align: right; font-weight: 700; ${diffStyle}">
                            ${selisih > 0 ? '+' : ''}${Number(selisih).toLocaleString('id-ID', {minimumFractionDigits: 2})}
                        </td>
                        <td style="text-align: center;">${badge}</td>
                        <td style="color: var(--text-muted);">${escapeHtml(r.note || '-')}</td>
                        <td style="font-size: 10px; color: var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
                        <td style="text-align: center;">
                            <div style="display:inline-flex; gap: 3px;">
                                <button class="btn-sm-action" title="Edit Record" onclick="editRow('${r.tgl_so}', '${r.kode_barang}', '${r.unit}', ${r.stok}, ${r.aktual}, '${(r.note || '').replace(/'/g, "\\'")}')">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <button class="btn-sm-action del" title="Hapus Record" onclick="deleteRow('${r.tgl_so}', '${r.kode_barang}')">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function openNewModal() {
            document.getElementById('form-kode').value = '';
            document.getElementById('form-unit').value = 'PCE';
            document.getElementById('form-stok').value = '0.00';
            document.getElementById('form-aktual').value = '0.00';
            document.getElementById('form-note').value = '';
            document.getElementById('form-uraian-hint').innerText = '';
            calcDiff();
            const m = document.getElementById('modal-form');
            m.classList.add('show');
            setTimeout(() => document.getElementById('form-kode').focus(), 100);
        }

        function editRow(tgl, kode, unit, stok, aktual, note) {
            document.getElementById('form-tgl').value = tgl;
            document.getElementById('form-kode').value = kode;
            document.getElementById('form-unit').value = unit || 'PCE';
            document.getElementById('form-stok').value = stok;
            document.getElementById('form-aktual').value = aktual;
            document.getElementById('form-note').value = note;
            document.getElementById('form-uraian-hint').innerText = '';
            calcDiff();
            const m = document.getElementById('modal-form');
            m.classList.add('show');
        }

        function closeModal() {
            document.getElementById('modal-form').classList.remove('show');
        }

        async function submitForm() {
            const tgl    = document.getElementById('form-tgl').value;
            const kode   = document.getElementById('form-kode').value.trim();
            const unit   = document.getElementById('form-unit').value.trim();
            const stok   = document.getElementById('form-stok').value;
            const aktual = document.getElementById('form-aktual').value;
            const note   = document.getElementById('form-note').value.trim();

            if (!kode) {
                Swal.fire({ icon: 'warning', title: 'Data Belum Lengkap', text: 'Kode barang wajib diisi.' });
                return;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('cmp_id', sessionCmpId);
            fd.append('tgl_so', tgl);
            fd.append('kode_barang', kode);
            fd.append('unit', unit);
            fd.append('stok', stok);
            fd.append('aktual', aktual);
            fd.append('note', note);

            try {
                const res = await fetch('stok_opname.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1500, showConfirmButton: false });
                    closeModal();
                    loadData();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal Simpan', text: json.message });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        async function deleteRow(tgl, kode) {
            const confirm = await Swal.fire({
                title: 'Hapus Record?',
                html: `Apakah Anda yakin ingin menghapus data Stok Opname <strong>${escapeHtml(kode)}</strong> tanggal <strong>${escapeHtml(tgl)}</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            });
            if (!confirm.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('cmp_id', sessionCmpId);
            fd.append('tgl_so', tgl);
            fd.append('kode_barang', kode);

            try {
                const res = await fetch('stok_opname.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({ icon: 'success', title: 'Dihapus', text: json.message, timer: 1500, showConfirmButton: false });
                    loadData();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        let importedRowsData = [];

        function openImportModal() {
            importedRowsData = [];
            document.getElementById('excelFileInput').value = '';
            document.getElementById('importPreviewContainer').style.display = 'none';
            document.getElementById('btnExecuteImport').style.display = 'none';
            document.getElementById('modal-import').classList.add('show');
        }

        function closeImportModal() {
            document.getElementById('modal-import').classList.remove('show');
        }

        function downloadTemplate() {
            const template = [
                {
                    tgl_so: "<?= date('Y-m-d') ?>",
                    kode_barang: "BRG-001",
                    unit: "PCE",
                    stok: 100,
                    aktual: 98,
                    note: "Selisih timbangan sampling"
                },
                {
                    tgl_so: "<?= date('Y-m-d') ?>",
                    kode_barang: "BRG-002",
                    unit: "KGM",
                    stok: 50.5,
                    aktual: 50.5,
                    note: "Stok fisik cocok"
                }
            ];
            const ws = XLSX.utils.json_to_sheet(template);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Template_Stok_Opname");
            XLSX.writeFile(wb, "Template_Stok_Opname.xlsx");
        }

        function handleExcelUpload(e) {
            const files = e.target.files;
            if (!files || files.length === 0) return;
            const file = files[0];
            const reader = new FileReader();
            reader.onload = function(evt) {
                try {
                    const data = new Uint8Array(evt.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const jsonData = XLSX.utils.sheet_to_json(firstSheet, { defval: "" });

                    if (!jsonData || jsonData.length === 0) {
                        Swal.fire({ icon: 'warning', title: 'Format Salah', text: 'File Excel kosong atau sheet pertama tidak berisi data.' });
                        return;
                    }

                    importedRowsData = jsonData;
                    renderImportPreview(jsonData);
                } catch (err) {
                    Swal.fire({ icon: 'error', title: 'Gagal Baca File', text: err.message });
                }
            };
            reader.readAsArrayBuffer(file);
        }

        function renderImportPreview(rows) {
            const tbody = document.getElementById('previewBody');
            tbody.innerHTML = '';
            document.getElementById('previewCountText').innerText = `Preview Data: ${rows.length} baris ditemukan`;

            const max = Math.min(rows.length, 10);
            for (let i = 0; i < max; i++) {
                const r = rows[i];
                const tr = document.createElement('tr');
                const tgl = r.tgl_so || r.TGL_SO || r['Tanggal SO'] || r.tgl || '';
                const kd = r.kode_barang || r.KODE_BARANG || r['Kode Barang'] || '';
                const un = r.unit || r.UNIT || r.Satuan || 'PCE';
                const st = r.stok || r.STOK || r['Stok Sistem'] || 0;
                const ak = r.aktual || r.AKTUAL || r['Stok Fisik'] || r['Stok Aktual'] || 0;
                const nt = r.note || r.NOTE || r.Catatan || '';

                tr.innerHTML = `
                    <td>${escapeHtml(tgl)}</td>
                    <td style="font-weight:600; color:var(--primary);">${escapeHtml(kd)}</td>
                    <td>${escapeHtml(un)}</td>
                    <td style="text-align:right;">${Number(st).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                    <td style="text-align:right; font-weight:600;">${Number(ak).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                    <td style="color:var(--text-muted);">${escapeHtml(nt)}</td>
                `;
                tbody.appendChild(tr);
            }

            document.getElementById('importPreviewContainer').style.display = 'block';
            document.getElementById('btnExecuteImport').style.display = 'inline-flex';
        }

        async function executeImport() {
            if (!importedRowsData || importedRowsData.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Tidak ada data untuk diimpor.' });
                return;
            }

            const ok = await Swal.fire({
                title: 'Konfirmasi Impor',
                html: `Import <strong>${importedRowsData.length}</strong> record Stok Opname ke sistem?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Impor Sekarang',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Mengimpor Data...',
                text: 'Harap tunggu, proses penyimpanan sedang berjalan...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'import_excel');
            fd.append('cmp_id', sessionCmpId);
            fd.append('rows', JSON.stringify(importedRowsData));

            try {
                const res = await fetch('stok_opname.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message });
                    closeImportModal();
                    loadData();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal Impor', text: json.message });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error Jaringan', text: err.message });
            }
        }

        function exportExcel() {
            if (!currentData || currentData.length === 0) {
                Swal.fire({ icon: 'info', title: 'Informasi', text: 'Tidak ada data untuk diekspor.' });
                return;
            }
            const dataToExport = currentData.map(r => ({
                'Tanggal SO': r.tgl_so,
                'Kode Barang': r.kode_barang,
                'Uraian Barang': r.URAIAN || '',
                'Klasifikasi': r.KLAS || '',
                'Satuan': r.unit || '',
                'Stok Sistem': Number(r.stok || 0),
                'Stok Aktual (Fisik)': Number(r.aktual || 0),
                'Selisih (Varian)': Number(r.selisih || 0),
                'Status': (r.selisih == 0 ? 'SESUAI' : 'SELISIH'),
                'Catatan': r.note || '',
                'Petugas': r.set_by || ''
            }));
            const ws = XLSX.utils.json_to_sheet(dataToExport);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Stok_Opname");
            XLSX.writeFile(wb, `Stok_Opname_${new Date().toISOString().slice(0,10)}.xlsx`);
        }

        function escapeHtml(text) {
            if (!text && text !== 0) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Close modal on Escape key or clicking outside
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeImportModal();
            }
        });
        document.getElementById('modal-form').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('modal-import').addEventListener('click', function(e) {
            if (e.target === this) closeImportModal();
        });

        // Drag & Drop for Excel
        const dropZone = document.getElementById('dropZone');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); }, false);
        });
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.style.borderColor = 'var(--primary)';
                dropZone.style.background = 'rgba(24, 144, 255, 0.08)';
            }, false);
        });
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.style.borderColor = 'var(--border-color)';
                dropZone.style.background = 'rgba(0,0,0,0.015)';
            }, false);
        });
        dropZone.addEventListener('drop', e => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files.length > 0) {
                document.getElementById('excelFileInput').files = files;
                handleExcelUpload({ target: { files: files } });
            }
        });

        function applyCurrentTheme(th) {
            if (!th) {
                var urlParams = new URLSearchParams(window.location.search);
                th = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            }
            if (th !== 'light' && th !== 'dark') th = 'light';
            document.documentElement.setAttribute('data-theme', th);
            document.documentElement.style.colorScheme = th;
            if (document.body) document.body.setAttribute('data-theme', th);
        }
        applyCurrentTheme();

        window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'SET_THEME' || e.data.theme)) {
                applyCurrentTheme(e.data.theme);
            }
        });

        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>
</html>

<?php
/**
 * CEISA 4.0 - Import BC 2.7 IN & Post ke IT Inventory (scan27.php)
 * Mengadopsi w_bc_ceisa_scan27.srw & d_bc_ceisa_scan27.srd
 * Pemindaian / Import Nomor AJU BC 2.7 Pemasukan dan Sinkronisasi ke IT Inventory (bc_1ab2)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'SYSTEM');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? '');

$action = $_REQUEST['action'] ?? '';

// 1. AJAX: LIST DATA SCAN BC 2.7
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['q'] ?? '');

    $where = [];
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(a.nomor_aju LIKE '%$s%' OR a.kantor LIKE '%$s%' OR a.user_id LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT 
        a.rowid, a.kantor, a.nomor_aju, a.tanggal_scan, a.user_id, a.status,
        (CASE WHEN EXISTS(SELECT 1 FROM bc_ceisa_get_data g WHERE g.nomor_aju = a.nomor_aju) THEN 1 ELSE 0 END) AS cek_ceisa,
        (CASE WHEN EXISTS(SELECT 1 FROM bc_1ab2 inv WHERE inv.NOMOR_AJU = a.nomor_aju AND inv.AB = 'A') THEN 1 ELSE 0 END) AS is_posted,
        (SELECT COALESCE(SUM(brg.cif), 0) FROM bc_ceisa_get_barang brg WHERE brg.nomor_aju = a.nomor_aju) AS total_cif,
        (SELECT COUNT(1) FROM bc_ceisa_get_barang brg WHERE brg.nomor_aju = a.nomor_aju) AS total_items
    FROM bc_ceisa_scan27 a
    $whereSql
    ORDER BY a.rowid DESC
    LIMIT 200";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // KPI Counters
    $kpiTotal = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_scan27")->fetch_row()[0] ?? 0;
    $kpiHariIni = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_scan27 WHERE DATE(tanggal_scan) = CURDATE()")->fetch_row()[0] ?? 0;
    $kpiPosted = $koneksi->query("SELECT COUNT(DISTINCT a.nomor_aju) FROM bc_ceisa_scan27 a JOIN bc_1ab2 b ON a.nomor_aju = b.NOMOR_AJU WHERE b.AB = 'A'")->fetch_row()[0] ?? 0;
    $kpiPending = max(0, $kpiTotal - $kpiPosted);

    echo json_encode([
        'status' => 'OK',
        'data' => $data,
        'count' => count($data),
        'kpi' => [
            'total' => (int)$kpiTotal,
            'hari_ini' => (int)$kpiHariIni,
            'posted' => (int)$kpiPosted,
            'pending' => (int)$kpiPending
        ]
    ]);
    exit;
}

// 2. AJAX: SCAN / TAMBAH NOMOR AJU
if ($action === 'scan') {
    header('Content-Type: application/json; charset=utf-8');
    $aju    = strtoupper(trim($_POST['nomor_aju'] ?? ''));
    $kantor = trim($_POST['kantor'] ?? '040300');

    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }

    $escAju    = $koneksi->real_escape_string($aju);
    $escKantor = $koneksi->real_escape_string($kantor);
    $escUser   = $koneksi->real_escape_string($sessionUser);
    $escCmp    = $koneksi->real_escape_string($sessionCmpId);

    // Cek apakah nomor AJU sudah ada di scan
    $cek = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_scan27 WHERE nomor_aju = '$escAju'")->fetch_row()[0] ?? 0;
    if ($cek > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU $aju sudah pernah di-scan sebelumnya."]);
        exit;
    }

    $sql = "INSERT INTO bc_ceisa_scan27 (cmp_id, kantor, nomor_aju, tanggal_scan, user_id, status)
            VALUES ('$escCmp', '$escKantor', '$escAju', NOW(), '$escUser', 'SCANNED')";

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => "Nomor AJU $aju berhasil dicatat.", 'nomor_aju' => $aju]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => "Gagal scan: " . $koneksi->error]);
    }
    exit;
}

// 3. AJAX: GET RINCIAN BARANG DARI AJU
if ($action === 'detail') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_GET['aju'] ?? '');
    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU kosong']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($aju);
    $hdr = $koneksi->query("SELECT * FROM bc_ceisa_get_data WHERE nomor_aju = '$escAju' LIMIT 1")->fetch_assoc();
    
    $itemRes = $koneksi->query("SELECT 
        b.*, 
        (SELECT klas FROM bc_material m WHERE m.KODE_BARANG = b.kode_barang LIMIT 1) AS klas_material
    FROM bc_ceisa_get_barang b 
    WHERE b.nomor_aju = '$escAju' 
    ORDER BY b.seri_barang ASC");

    $items = [];
    if ($itemRes) {
        while ($r = $itemRes->fetch_assoc()) {
            $items[] = $r;
        }
    }

    $postedRes = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE NOMOR_AJU = '$escAju' AND AB = 'A'");
    $isPosted = ($postedRes && $postedRes->fetch_row()[0] > 0);

    echo json_encode([
        'status' => 'OK',
        'header' => $hdr,
        'items' => $items,
        'is_posted' => $isPosted
    ]);
    exit;
}

// 4. AJAX: POST KE IT INVENTORY
if ($action === 'post_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_POST['aju'] ?? '');
    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU kosong']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($aju);

    // Cek apakah sudah terposting
    $cek = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE NOMOR_AJU = '$escAju' AND AB = 'A'")->fetch_row()[0] ?? 0;
    if ($cek > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU $aju sudah terposting di IT Inventory."]);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        $insertSql = "INSERT INTO bc_1ab2 (
            BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG,
            POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, SJ, INVOICE,
            FAKTUR, NOMOR_AJU, TUJUAN_PEMASUKAN, TUJUAN_PENGIRIMAN, NPWP_BS, SERI_BARANG,
            HARGA_PENYERAHAN, CIF, NETTO, JUMLAH_KEMASAN, DETAIL_KEMASAN, SESUAI, QTY_AKTUAL,
            TGL_PROSES, CRT_DT, CRT_USER
        )
        SELECT 
            '27' AS BC,
            COALESCE(a.nomor_daftar, '-') AS NOMOR_DAFTAR,
            a.tanggal_daftar,
            COALESCE(doc.nomor_dokumen, a.nomor_daftar) AS NO_BUKTI,
            COALESCE(doc.tanggal_dokumen, a.tanggal_daftar) AS TGL_BUKTI,
            COALESCE(ent.nama_entitas, '-') AS NAMA_BS,
            brg.kode_barang,
            brg.pos_tarif,
            brg.uraian,
            COALESCE(mat.KLAS, 'Bahan Baku') AS KLAS,
            COALESCE(brg.kode_satuan, 'PCE') AS UNIT,
            COALESCE(brg.jumlah_satuan, 0) AS QTY,
            COALESCE(a.kode_valuta, 'IDR') AS KURS,
            COALESCE(brg.cif, brg.harga_penyerahan, 0) AS NILAI,
            'A' AS AB, -- Pemasukan
            a.nomor_aju AS ID_HEADER,
            doc.nomor_dokumen AS SJ,
            doc.nomor_dokumen AS INVOICE,
            doc.nomor_dokumen AS FAKTUR,
            a.nomor_aju,
            a.kode_tujuan_pemasukan,
            a.kode_tujuan_pengiriman,
            ent.nomor_identitas AS NPWP_BS,
            brg.seri_barang,
            brg.harga_penyerahan,
            brg.cif,
            brg.netto,
            COALESCE(kem.jumlah_kemasan, 1) AS JUMLAH_KEMASAN,
            kem.kode_jenis_kemasan AS DETAIL_KEMASAN,
            'Y' AS SESUAI,
            brg.jumlah_satuan AS QTY_AKTUAL,
            NOW() AS TGL_PROSES,
            NOW() AS CRT_DT,
            '$sessionUser' AS CRT_USER
        FROM bc_ceisa_get_data a
        JOIN bc_ceisa_get_barang brg ON a.nomor_aju = brg.nomor_aju
        LEFT JOIN bc_ceisa_get_entitas ent ON a.nomor_aju = ent.nomor_aju AND ent.kode_entitas IN ('3', '7', '8', 'PENGIRIM', 'PEMASOK')
        LEFT JOIN bc_ceisa_get_dokumen doc ON a.nomor_aju = doc.nomor_aju AND doc.kode_dokumen IN ('380', 'INVOICE')
        LEFT JOIN bc_ceisa_get_kemasan kem ON a.nomor_aju = kem.nomor_aju
        LEFT JOIN bc_material mat ON brg.kode_barang = mat.KODE_BARANG
        WHERE a.nomor_aju = '$escAju'";

        if (!$koneksi->query($insertSql)) {
            throw new Exception("Gagal posting IT Inventory: " . $koneksi->error);
        }

        // Update status scan
        $koneksi->query("UPDATE bc_ceisa_scan27 SET status = 'POSTED' WHERE nomor_aju = '$escAju'");

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Nomor AJU $aju berhasil diposting ke IT Inventory sebagai PEMASUKAN."]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// 5. AJAX: UNPOST DARI IT INVENTORY
if ($action === 'unpost') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_POST['aju'] ?? '');
    $escAju = $koneksi->real_escape_string($aju);

    $del = $koneksi->query("DELETE FROM bc_1ab2 WHERE NOMOR_AJU = '$escAju' AND AB = 'A'");
    if ($del) {
        $koneksi->query("UPDATE bc_ceisa_scan27 SET status = 'SCANNED' WHERE nomor_aju = '$escAju'");
        echo json_encode(['status' => 'OK', 'message' => "Posting IT Inventory untuk AJU $aju berhasil dibatalkan."]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => "Gagal unpost: " . $koneksi->error]);
    }
    exit;
}

// 6. AJAX: HAPUS SCAN
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_POST['aju'] ?? '');
    $escAju = $koneksi->real_escape_string($aju);

    // Cek apakah sudah terposting
    $cek = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE NOMOR_AJU = '$escAju' AND AB = 'A'")->fetch_row()[0] ?? 0;
    if ($cek > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Tidak bisa dihapus karena dokumen sudah masuk IT Inventory. Lakukan Unpost terlebih dahulu."]);
        exit;
    }

    $koneksi->query("DELETE FROM bc_ceisa_scan27 WHERE nomor_aju = '$escAju'");
    echo json_encode(['status' => 'OK', 'message' => "Data scan untuk AJU $aju berhasil dihapus."]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Import BC 2.7 IN & Post ke IT Inv</title>
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
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --border: #e8e8e8;
            --text: rgba(0, 0, 0, 0.75);
            --text-heading: #262626;
            --text-secondary: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #f5222d;
            --row-hover: #f0f7ff;
            --table-head-bg: #fafafa;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            color-scheme: light;
        }
        [data-theme='dark'], html[data-theme="dark"], body[data-theme="dark"] {
            --bg: #141414;
            --card-bg: #1f1f1f;
            --border: #303030;
            --text: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --row-hover: #262638;
            --table-head-bg: #1d1d1d;
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            color-scheme: dark;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            font-size: 10.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .kpi-container {
            display: flex;
            gap: 6px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }
        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 2px 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 24px;
        }
        .kpi-title { font-size: 9px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; }
        .kpi-value { font-size: 11px; font-weight: 700; color: var(--text-heading); }
        .kpi-icon { font-size: 11px; opacity: 0.8; }
        
        .toolbar-card {
            background: var(--bg-filter);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 5px 8px;
            margin-bottom: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .toolbar-left span { font-size: 9px; font-weight: 600; text-transform: uppercase; color: var(--text-secondary); }
        
        input, select, button {
            font-family: inherit;
            font-size: 9.5px;
            height: 22px;
            padding: 1px 6px;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-input);
            color: var(--text);
            outline: none;
        }
        input:focus, select:focus { border-color: var(--primary); }
        select option {
            background-color: var(--card-bg);
            color: var(--text);
        }
        .btn-primary, .ant-btn-primary {
            background: var(--primary);
            color: #fff;
            border: 1px solid var(--primary);
            cursor: pointer;
            font-weight: 500;
            font-size: 10px;
            height: 22px;
            padding: 0 7px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            line-height: 1.4;
        }
        .btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color: #fff; }
        .btn-default, .ant-btn {
            background: var(--card-bg);
            color: var(--text);
            border: 1px solid var(--border-input);
            cursor: pointer;
            font-size: 10px;
            height: 22px;
            padding: 0 7px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            line-height: 1.4;
        }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            overflow: auto;
            max-height: calc(100vh - 170px);
        }
        table { width: 100%; border-collapse: collapse; font-size: 10px; text-align: left; }
        th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border);
            padding: 4px 6px;
            font-size: 9.5px;
            text-align: left;
            font-weight: 600;
            color: var(--text-heading);
            white-space: nowrap;
            z-index: 2;
        }
        td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            vertical-align: middle;
            color: var(--text);
        }
        tr:hover td { background-color: var(--row-hover) !important; }
        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
        }
        .badge-posted { background: rgba(82, 196, 26, 0.12); color: var(--success); }
        .badge-pending { background: rgba(250, 173, 20, 0.12); color: var(--warning); }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .modal-header {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .modal-header h3 { font-size: 11.5px; font-weight: 600; margin: 0; }
        .modal-body { padding: 8px 12px; overflow-y: auto; flex: 1; font-size: 10px; }
        .modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
        }
    </style>
</head>
<body>

    <!-- KPI Cards -->
    <div class="kpi-container">
        <div class="kpi-card">
            <span class="kpi-title">Total Scan BC 2.7 IN</span>
            <span class="kpi-value" id="kpi-total">0</span>
            <i class="fa-solid fa-barcode kpi-icon" style="color: #1890ff;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Scan Hari Ini</span>
            <span class="kpi-value" id="kpi-hari-ini">0</span>
            <i class="fa-solid fa-calendar-day kpi-icon" style="color: #52c41a;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Sudah Masuk IT Inv</span>
            <span class="kpi-value" id="kpi-posted">0</span>
            <i class="fa-solid fa-circle-check kpi-icon" style="color: #722ed1;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Menunggu Posting</span>
            <span class="kpi-value" id="kpi-pending">0</span>
            <i class="fa-solid fa-clock kpi-icon" style="color: #faad14;"></i>
        </div>
    </div>

    <!-- Scan & Filter Toolbar -->
    <div class="toolbar-card">
        <div class="toolbar-left">
            <span style="font-weight:600; color:var(--text-secondary);"><i class="fa-solid fa-qrcode"></i> Scan / Input AJU:</span>
            <input type="text" id="scan-aju" placeholder="Ketik / Scan 26 Digit Nomor AJU..." style="width: 250px; font-family: monospace; font-weight: 600;" onkeypress="if(event.key === 'Enter') doScan()">
            <select id="scan-kantor">
                <option value="040300">040300 - KPPBC PURWAKARTA</option>
                <option value="040100">040100 - KPPBC BOGOR</option>
                <option value="040200">040200 - KPPBC BEKASI</option>
                <option value="040400">040400 - KPPBC BANDUNG</option>
                <option value="050100">050100 - KPPBC TANJUNG EMAS</option>
            </select>
            <button class="btn-primary" onclick="doScan()"><i class="fa-solid fa-plus"></i> Tambah Scan</button>
        </div>
        <div class="toolbar-right">
            <input type="text" id="filter-search" placeholder="Cari AJU / User..." style="width: 160px;" oninput="loadData()">
            <button class="btn-default" onclick="loadData()"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
    </div>

    <!-- Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 35px; text-align: center;">#</th>
                    <th>Kantor Pabean</th>
                    <th>Nomor AJU BC 2.7 IN</th>
                    <th>Waktu Scan</th>
                    <th>User Petugas</th>
                    <th style="text-align: right;">Total Item Barang</th>
                    <th style="text-align: right;">Total Nilai CIF</th>
                    <th style="text-align: center;">Data CEISA</th>
                    <th style="text-align: center;">Status IT Inv</th>
                    <th style="text-align: center; width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody id="table-body">
                <tr><td colspan="10" style="text-align: center; padding: 20px;">Memuat data...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Modal View Detail -->
    <div class="modal" id="modal-detail">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size: 13px; font-weight: 700;" id="detail-title"><i class="fa-solid fa-circle-info"></i> Rincian Barang BC 2.7 IN</h3>
                <span style="cursor: pointer; font-size: 16px;" onclick="closeModal('modal-detail')">&times;</span>
            </div>
            <div class="modal-body" id="detail-body">
                Memuat rincian...
            </div>
            <div class="modal-footer" id="detail-footer">
                <button class="btn-default" onclick="closeModal('modal-detail')">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        let currentData = [];

        async function loadData() {
            const q = document.getElementById('filter-search').value;
            const tbody = document.getElementById('table-body');

            try {
                const res = await fetch(`scan27.php?action=list&q=${encodeURIComponent(q)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    currentData = json.data;
                    document.getElementById('kpi-total').innerText = Number(json.kpi.total).toLocaleString('id-ID');
                    document.getElementById('kpi-hari-ini').innerText = Number(json.kpi.hari_ini).toLocaleString('id-ID');
                    document.getElementById('kpi-posted').innerText = Number(json.kpi.posted).toLocaleString('id-ID');
                    document.getElementById('kpi-pending').innerText = Number(json.kpi.pending).toLocaleString('id-ID');

                    renderTable(json.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; color: red;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; color: red;">Error: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('table-body');
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 20px;">Belum ada riwayat pemindaian Nomor AJU BC 2.7 IN.</td></tr>';
                return;
            }

            tbody.innerHTML = data.map((r, i) => {
                const isPosted = parseInt(r.is_posted) > 0;
                const badgeIt = isPosted 
                    ? '<span class="badge badge-posted"><i class="fa-solid fa-check"></i> Sudah Diposting</span>' 
                    : '<span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Belum Diposting</span>';
                const badgeCeisa = parseInt(r.cek_ceisa) > 0 
                    ? '<span style="color:var(--success); font-weight:600;"><i class="fa-solid fa-cloud-arrow-down"></i> Tersedia</span>' 
                    : '<span style="color:var(--text-secondary);"><i class="fa-solid fa-minus"></i> Belum Diambil</span>';

                return `
                    <tr>
                        <td style="text-align: center; color: var(--text-secondary);">${i + 1}</td>
                        <td>${r.kantor}</td>
                        <td style="font-family: monospace; font-weight: 600; color: var(--primary); cursor: pointer;" onclick="viewDetail('${r.nomor_aju}')">${r.nomor_aju}</td>
                        <td>${r.tanggal_scan}</td>
                        <td>${r.user_id || '-'}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_items || 0).toLocaleString('id-ID')}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_cif || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align: center;">${badgeCeisa}</td>
                        <td style="text-align: center;">${badgeIt}</td>
                        <td style="text-align: center;">
                            <button class="btn-default" style="padding: 2px 6px;" title="Lihat Rincian" onclick="viewDetail('${r.nomor_aju}')"><i class="fa-solid fa-eye"></i></button>
                            ${!isPosted ? `
                                <button class="btn-primary" style="padding: 2px 6px;" title="Post ke IT Inventory" onclick="postToItInv('${r.nomor_aju}')">
                                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Post
                                </button>
                                <button class="btn-default" style="padding: 2px 6px; color: var(--danger);" title="Hapus Scan" onclick="deleteScan('${r.nomor_aju}')">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            ` : `
                                <button class="btn-default" style="padding: 2px 6px; color: var(--warning);" title="Batalkan Posting (Unpost)" onclick="unpost('${r.nomor_aju}')">
                                    <i class="fa-solid fa-rotate-left"></i> Unpost
                                </button>
                            `}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function doScan() {
            const aju = document.getElementById('scan-aju').value.trim();
            const kantor = document.getElementById('scan-kantor').value;
            if (!aju) return alert('Silakan masukkan nomor AJU.');

            const fd = new FormData();
            fd.append('action', 'scan');
            fd.append('nomor_aju', aju);
            fd.append('kantor', kantor);

            try {
                const res = await fetch('scan27.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    document.getElementById('scan-aju').value = '';
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        async function viewDetail(aju) {
            document.getElementById('modal-detail').style.display = 'flex';
            const body = document.getElementById('detail-body');
            body.innerHTML = 'Memuat rincian dokumen & barang...';

            try {
                const res = await fetch(`scan27.php?action=detail&aju=${encodeURIComponent(aju)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    const h = json.header;
                    document.getElementById('detail-title').innerHTML = `<i class="fa-solid fa-file-invoice"></i> Dokumen BC 2.7 IN: ${aju}`;
                    
                    let html = `
                        <div style="background: var(--bg); padding: 10px; border-radius: 4px; margin-bottom: 12px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                            <div><strong style="color:var(--text-secondary);">No Pendaftaran:</strong> ${h ? h.nomor_daftar : '-'}</div>
                            <div><strong style="color:var(--text-secondary);">Tanggal Daftar:</strong> ${h ? h.tanggal_daftar : '-'}</div>
                            <div><strong style="color:var(--text-secondary);">Kantor Pabean:</strong> ${h ? h.kode_kantor : '-'}</div>
                            <div><strong style="color:var(--text-secondary);">Status IT Inv:</strong> ${json.is_posted ? '<span style="color:var(--success); font-weight:600;">Sudah Masuk</span>' : '<span style="color:var(--warning); font-weight:600;">Belum Masuk</span>'}</div>
                        </div>
                        <table style="width: 100%;">
                            <thead>
                                <tr style="background: var(--bg);">
                                    <th style="width: 30px;">Seri</th>
                                    <th>Kode Barang</th>
                                    <th>Uraian Barang</th>
                                    <th>Pos Tarif</th>
                                    <th style="text-align: right;">Jumlah Satuan</th>
                                    <th>Satuan</th>
                                    <th style="text-align: right;">Nilai CIF / Penyerahan</th>
                                    <th style="text-align: center;">Master Material</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    if (json.items.length === 0) {
                        html += `<tr><td colspan="8" style="text-align: center; padding: 12px;">Data rincian barang belum disinkronkan dari CEISA staging.</td></tr>`;
                    } else {
                        html += json.items.map(b => `
                            <tr>
                                <td style="text-align: center;">${b.seri_barang}</td>
                                <td style="font-weight: 600;">${b.kode_barang}</td>
                                <td>${b.uraian || '-'}</td>
                                <td>${b.pos_tarif || '-'}</td>
                                <td style="text-align: right; font-weight: 600;">${Number(b.jumlah_satuan || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                                <td>${b.kode_satuan || '-'}</td>
                                <td style="text-align: right;">${Number(b.cif || b.harga_penyerahan || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                                <td style="text-align: center;">
                                    ${b.klas_material ? `<span class="badge badge-posted">${b.klas_material}</span>` : `<span class="badge badge-pending">Belum Ada</span>`}
                                </td>
                            </tr>
                        `).join('');
                    }

                    html += `</tbody></table>`;
                    body.innerHTML = html;

                    const footer = document.getElementById('detail-footer');
                    footer.innerHTML = `
                        <button class="btn-default" onclick="closeModal('modal-detail')">Tutup</button>
                        ${!json.is_posted ? `<button class="btn-primary" onclick="postToItInv('${aju}')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Post ke IT Inventory</button>` : `<button class="btn-default" style="color:var(--warning);" onclick="unpost('${aju}')"><i class="fa-solid fa-rotate-left"></i> Unpost</button>`}
                    `;
                } else {
                    body.innerHTML = `<span style="color:red;">${json.message}</span>`;
                }
            } catch (err) {
                body.innerHTML = `<span style="color:red;">Error: ${err.message}</span>`;
            }
        }

        async function postToItInv(aju) {
            if (!confirm(`Yakin ingin memposting Nomor AJU ${aju} ke IT Inventory sebagai PEMASUKAN?`)) return;
            const fd = new FormData();
            fd.append('action', 'post_it_inv');
            fd.append('aju', aju);

            try {
                const res = await fetch('scan27.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    alert(json.message);
                    closeModal('modal-detail');
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        async function unpost(aju) {
            if (!confirm(`Yakin ingin membatalkan posting IT Inventory untuk Nomor AJU ${aju}?`)) return;
            const fd = new FormData();
            fd.append('action', 'unpost');
            fd.append('aju', aju);

            try {
                const res = await fetch('scan27.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    alert(json.message);
                    closeModal('modal-detail');
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        async function deleteScan(aju) {
            if (!confirm(`Hapus scan Nomor AJU ${aju}?`)) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('aju', aju);

            try {
                const res = await fetch('scan27.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

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
            if (e.data && e.data.type === 'SET_THEME') {
                applyCurrentTheme(e.data.theme);
            }
        });

        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>
</html>

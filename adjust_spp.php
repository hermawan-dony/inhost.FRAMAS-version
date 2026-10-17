<?php
/**
 * CEISA 4.0 - Admin Inventory: Adjust SPP (adjust_spp.php)
 * Mengadopsi w_adjust_spp.srw & d_adjust_spp.srd
 * Penyesuaian Selisih Stok Opname (SPP) dan Posting ke IT Inventory (bc_so & bc_1ab2)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'ADMIN');
$action = $_REQUEST['action'] ?? '';
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// Ambil klasifikasi tipe dari database
$klasList = [];
$qTipe = $koneksi->query("SELECT DISTINCT tipe, grp, idx FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx ASC");
if ($qTipe) {
    while ($t = $qTipe->fetch_assoc()) {
        if (!empty($t['tipe']) && $t['tipe'] !== '-- SEMUA KLAS --') {
            $klasList[] = $t['tipe'];
        }
    }
}
if (empty($klasList)) {
    $klasList = ['Raw Materials', 'Finished Goods', 'Work In Progress', 'Capital Goods', 'Wastes'];
}

// -------------------------------------------------------------
// 1. AJAX API: LIST ITEM SELISIH STOK OPNAME (SPP)
// -------------------------------------------------------------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $tgl1   = trim($_GET['tgl1'] ?? date('Y-01-01'));
    $tgl2   = trim($_GET['tgl2'] ?? date('Y-m-d'));
    $klas   = trim($_GET['klas'] ?? '');
    $status = trim($_GET['status'] ?? ''); // 'Y' (Posted), 'N' (Belum Post), '' (Semua)
    $search = trim($_GET['q'] ?? '');

    $where = ["a.selisih <> 0"];
    if (!empty($tgl1) && !empty($tgl2)) {
        $where[] = "a.tgl_so BETWEEN '" . $koneksi->real_escape_string($tgl1) . "' AND '" . $koneksi->real_escape_string($tgl2) . "'";
    }
    if (!empty($klas) && $klas !== '%') {
        $escKlas = $koneksi->real_escape_string($klas);
        $where[] = "b.KLAS = '$escKlas'";
    }
    if ($status === 'Y') {
        $where[] = "a.appv = 'Y'";
    } elseif ($status === 'N') {
        $where[] = "(a.appv IS NULL OR a.appv <> 'Y')";
    }

    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(a.kode_barang LIKE '%$s%' OR b.URAIAN LIKE '%$s%' OR a.no_spp LIKE '%$s%' OR a.bs LIKE '%$s%')";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT 
                a.tgl_so, 
                a.kode_barang, 
                b.URAIAN AS uraian, 
                b.KLAS AS klas, 
                a.unit, 
                a.stok, 
                a.aktual, 
                a.selisih, 
                COALESCE(a.no_spp, CONCAT('SPP-', REPLACE(a.tgl_so, '-', ''), '-', a.kode_barang)) AS no_spp, 
                COALESCE(a.tgl_spp, a.tgl_so) AS tgl_spp, 
                COALESCE(a.qty_spp, ABS(a.selisih)) AS qty_spp, 
                COALESCE(a.nilai_spp, 0) AS nilai_spp, 
                COALESCE(a.appv, 'N') AS appv, 
                a.bs, 
                a.appv_by,
                (SELECT COUNT(1) FROM bc_1ab2 x WHERE x.NO_BUKTI = a.no_spp AND x.kode_barang = a.kode_barang) AS posted_count
            FROM bc_so a
            JOIN bc_material b ON a.kode_barang = b.KODE_BARANG
            $whereSql
            ORDER BY a.tgl_so DESC, a.kode_barang ASC
            LIMIT 500";

    $res = $koneksi->query($sql);
    $data = [];
    $totalSelisih = 0;
    $countPosted = 0;
    $countUnposted = 0;

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
            $totalSelisih += abs((float)$r['selisih']);
            if ($r['appv'] === 'Y') $countPosted++;
            else $countUnposted++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $data,
        'count'  => count($data),
        'kpi'    => [
            'total_items'  => count($data),
            'unposted'     => $countUnposted,
            'posted'       => $countPosted,
            'total_selisih'=> $totalSelisih
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: POSTING SATUAN ATAU BATCH KE IT INVENTORY (bc_1ab2)
// -------------------------------------------------------------
if ($action === 'post_spp') {
    header('Content-Type: application/json; charset=utf-8');
    $tglSo      = trim($_POST['tgl_so'] ?? '');
    $kodeBarang = trim($_POST['kode_barang'] ?? '');
    $unit       = trim($_POST['unit'] ?? '');
    $noSpp      = trim($_POST['no_spp'] ?? '');
    $tglSpp     = trim($_POST['tgl_spp'] ?? date('Y-m-d'));
    $qtySpp     = (float)($_POST['qty_spp'] ?? 0);
    $nilaiSpp   = (float)($_POST['nilai_spp'] ?? 0);
    $bs         = trim($_POST['bs'] ?? 'PERUSAHAAN');

    if (empty($tglSo) || empty($kodeBarang) || empty($noSpp)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tanggal SO, Kode Barang, dan Nomor SPP wajib diisi.']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        // 1. Ambil data bc_so dan material
        $stmtCek = $koneksi->prepare("SELECT a.stok, a.aktual, a.selisih, b.URAIAN, b.KLAS, b.KODE_SATUAN 
                                      FROM bc_so a 
                                      JOIN bc_material b ON a.kode_barang = b.KODE_BARANG 
                                      WHERE a.kode_barang = ? AND a.tgl_so = ? LIMIT 1");
        $stmtCek->bind_param("ss", $kodeBarang, $tglSo);
        $stmtCek->execute();
        $resCek = $stmtCek->get_result();
        $item = $resCek ? $resCek->fetch_assoc() : null;
        $stmtCek->close();

        if (!$item) {
            throw new Exception("Data selisih SO untuk $kodeBarang tanggal $tglSo tidak ditemukan.");
        }

        $selisih = (float)$item['selisih'];
        $ab = ($selisih > 0) ? 'A' : 'B'; // Jika fisik > sistem = Penambahan (A/Masuk), sebaliknya Pengeluaran (B/Keluar)
        $qtyAktual = ($ab === 'A') ? abs($qtySpp) : -abs($qtySpp);
        $qtyAbs = abs($qtySpp);

        // Hapus entri SPP lama jika sudah ada pada nomor bukti & kode barang tersebut
        $koneksi->query("DELETE FROM bc_1ab2 WHERE NO_BUKTI = '" . $koneksi->real_escape_string($noSpp) . "' AND KODE_BARANG = '" . $koneksi->real_escape_string($kodeBarang) . "'");

        // 2. Insert ke bc_1ab2 sesuai PowerBuilder
        $sqlInsert = "INSERT INTO bc_1ab2 (
            BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG, 
            POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, 
            HARGA_PENYERAHAN, SESUAI, QTY_AKTUAL, TGL_PROSES, CRT_DT, CRT_USER
        ) VALUES (
            'SPP', ?, ?, ?, ?, ?, ?, 
            NULL, ?, ?, ?, ?, 'IDR', ?, ?, '1', 
            ?, 'Y', ?, CURDATE(), CURDATE(), ?
        )";

        $stmtIns = $koneksi->prepare($sqlInsert);
        if (!$stmtIns) {
            throw new Exception("Gagal menyiapkan query insert IT Inv: " . $koneksi->error);
        }

        $uraian = $item['URAIAN'];
        $klas   = $item['KLAS'];
        $satuan = !empty($unit) ? $unit : $item['KODE_SATUAN'];

        $stmtIns->bind_param(
            "ssssssssdsddds",
            $noSpp, $tglSpp, $noSpp, $tglSpp, $bs, $kodeBarang,
            $uraian, $klas, $satuan, $qtyAbs, $nilaiSpp, $ab,
            $nilaiSpp, $qtyAktual, $sessionUser
        );

        if (!$stmtIns->execute()) {
            throw new Exception("Gagal insert ke IT Inventory (bc_1ab2): " . $stmtIns->error);
        }
        $stmtIns->close();

        // 3. Update status approval pada bc_so
        $stmtUpdSo = $koneksi->prepare("UPDATE bc_so 
                                        SET no_spp = ?, tgl_spp = ?, qty_spp = ?, nilai_spp = ?, bs = ?, appv = 'Y', appv_by = ? 
                                        WHERE kode_barang = ? AND tgl_so = ?");
        $stmtUpdSo->bind_param("ssddssss", $noSpp, $tglSpp, $qtySpp, $nilaiSpp, $bs, $sessionUser, $kodeBarang, $tglSo);
        $stmtUpdSo->execute();
        $stmtUpdSo->close();

        $koneksi->commit();

        echo json_encode([
            'status' => 'OK', 
            'message' => "Item $kodeBarang berhasil diposting ke IT Inventory dengan Dokumen SPP $noSpp (Tipe: " . ($ab === 'A' ? 'Pemasukan' : 'Pengeluaran') . ", Qty: $qtyAbs $satuan)!"
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: BATALKAN POSTING (UNPOST SPP)
// -------------------------------------------------------------
if ($action === 'unpost_spp') {
    header('Content-Type: application/json; charset=utf-8');
    $tglSo      = trim($_POST['tgl_so'] ?? '');
    $kodeBarang = trim($_POST['kode_barang'] ?? '');
    $noSpp      = trim($_POST['no_spp'] ?? '');

    if (empty($kodeBarang) || empty($tglSo)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Parameter tidak valid.']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        if (!empty($noSpp)) {
            $koneksi->query("DELETE FROM bc_1ab2 WHERE NO_BUKTI = '" . $koneksi->real_escape_string($noSpp) . "' AND KODE_BARANG = '" . $koneksi->real_escape_string($kodeBarang) . "'");
        }
        $koneksi->query("UPDATE bc_so SET appv = 'N', appv_by = NULL WHERE kode_barang = '" . $koneksi->real_escape_string($kodeBarang) . "' AND tgl_so = '" . $koneksi->real_escape_string($tglSo) . "'");

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Posting SPP untuk $kodeBarang berhasil dibatalkan."]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($themeParam) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjust SPP - Admin IT Inventory</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-body: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #e8e8e8;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
            color-scheme: light;
        }

        html[data-theme="dark"], body[data-theme="dark"] {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --border-card: #303030;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            --row-hover: #262638;
            --row-selected: #111d2c;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }

        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 6px;
            overflow: hidden;
        }
        .ant-card-head {
            min-height: 36px;
            padding: 0 10px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            flex-wrap: wrap;
        }
        .ant-card-head-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-panel {
            padding: 5px 8px;
            background: var(--bg-filter);
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: flex-end;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .filter-col label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .filter-control {
            height: 22px;
            padding: 1px 6px;
            font-size: 9.5px;
            font-family: inherit;
            border: 1px solid var(--border-input);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s;
        }
        .filter-control:focus {
            border-color: var(--primary);
        }

        .kpi-container {
            padding: 5px 8px;
            display: flex;
            gap: 6px;
            border-bottom: 1px solid var(--border-card);
            flex-wrap: wrap;
            background: var(--bg-card);
        }
        .kpi-chip {
            padding: 2px 8px;
            border-radius: 2px;
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 9.5px;
        }
        .kpi-val {
            font-weight: 700;
            font-size: 11px;
        }

        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            height: 22px;
            padding: 0 7px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .ant-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff !important;
        }
        .ant-btn-success {
            background: #52c41a;
            border-color: #52c41a;
            color: #ffffff !important;
        }
        .ant-btn-warning {
            background: #faad14;
            border-color: #faad14;
            color: #ffffff !important;
        }
        .ant-btn-danger {
            background: #ff4d4f;
            border-color: #ff4d4f;
            color: #ffffff !important;
        }
        .ant-btn-sm {
            height: 18px;
            padding: 0 5px;
            font-size: 9px;
            border-radius: 2px;
        }

        .ant-table-wrapper {
            width: 100%;
            border-radius: 0 0 2px 2px;
            overflow: hidden;
        }
        .table-scroll {
            width: 100%;
            max-height: calc(100vh - 210px);
            min-height: 380px;
            overflow: auto;
        }
        .ant-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 10px;
        }
        .ant-table th {
            background: var(--table-head-bg);
            color: var(--text-heading);
            font-weight: 600;
            padding: 4px 6px;
            font-size: 9.5px;
            text-align: left;
            border-bottom: 1px solid var(--border-table);
            border-right: 1px solid var(--border-table);
            position: sticky;
            top: 0;
            z-index: 2;
            white-space: nowrap;
            user-select: none;
        }
        .ant-table th.sortable-th {
            cursor: pointer;
        }
        .ant-table th.sortable-th:hover {
            color: var(--primary);
            background: var(--row-hover);
        }
        .ant-table td {
            padding: 3px 6px;
            font-size: 10px;
            border-bottom: 1px solid var(--border-table);
            border-right: 1px solid var(--border-table);
            white-space: nowrap;
            color: var(--text-main);
        }
        .ant-table tbody tr:hover td {
            background: var(--row-hover);
        }

        .ant-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
            text-align: center;
        }
        .ant-badge-blue { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .ant-badge-green { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .ant-badge-gold { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }
        .ant-badge-red { background: #fff1f0; color: #f5222d; border: 1px solid #ffa39e; }

        /* MODAL */
        .ant-modal-mask {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .ant-modal {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            width: 90%;
            max-width: 550px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .ant-modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            font-size: 11.5px;
        }
        .ant-modal-body {
            padding: 10px 14px;
        }
        .ant-modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border-card);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--bg-filter);
        }
        .form-row {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-bottom: 8px;
        }
        .form-row label {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

<div class="ant-card">
    <div class="ant-card-head">
        <div class="ant-card-head-title">
            <span>⚡</span> Adjust SPP (Surat Persetujuan Penyesuaian Selisih SO)
        </div>
        <div style="display:flex; gap:8px;">
            <button type="button" class="ant-btn ant-btn-success" onclick="exportToExcel()" title="Simpan sebagai file Excel (.xlsx)">
                📗 Save Excel (.xlsx)
            </button>
            <button type="button" class="ant-btn" onclick="exportToCSV()" title="Unduh CSV">
                📄 CSV
            </button>
            <button type="button" class="ant-btn" onclick="copyTableClipboard()" title="Salin data ke clipboard">
                📋 Copy Grid
            </button>
        </div>
    </div>

    <!-- FILTER PANEL -->
    <div class="filter-panel">
        <div class="filter-col">
            <label>Tanggal SO Mulai</label>
            <input type="date" id="filterTgl1" class="filter-control" value="<?= date('Y-01-01') ?>">
        </div>
        <div class="filter-col">
            <label>Tanggal SO Sampai</label>
            <input type="date" id="filterTgl2" class="filter-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="filter-col">
            <label>Klasifikasi Barang</label>
            <select id="filterKlas" class="filter-control" style="min-width: 180px;">
                <option value="">Semua Klasifikasi (bc_tipe)</option>
                <?php foreach ($klasList as $k): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-col">
            <label>Status Posting IT Inv</label>
            <select id="filterStatus" class="filter-control" style="width: 140px;">
                <option value="">Semua Item Selisih</option>
                <option value="N" selected>Belum Diposting</option>
                <option value="Y">Sudah Diposting (SPP)</option>
            </select>
        </div>
        <div class="filter-col">
            <label>Kata Kunci Cari</label>
            <input type="text" id="filterSearch" class="filter-control" placeholder="Kode / Nama / No SPP..." style="width: 180px;">
        </div>

        <button type="button" class="ant-btn ant-btn-primary" onclick="loadData()">
            🔍 Tampilkan
        </button>
        <button type="button" class="ant-btn" onclick="resetFilter()">
            🔄 Reset
        </button>
    </div>

    <!-- KPI CHIPS -->
    <div class="kpi-container">
        <div class="kpi-chip">
            <span style="color:var(--text-muted);">Total Item Selisih:</span>
            <span class="kpi-val" id="kpiTotal">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#faad14;">⏳ Belum Diposting:</span>
            <span class="kpi-val" id="kpiUnposted" style="color:#faad14;">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#52c41a;">✔️ Sudah Diposting:</span>
            <span class="kpi-val" id="kpiPosted" style="color:#52c41a;">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#1890ff;">Akumulasi Qty Selisih:</span>
            <span class="kpi-val" id="kpiSelisih" style="color:#1890ff;">0</span>
        </div>
    </div>

    <!-- TABLE TOOLBAR INSTANT -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:7px 12px; background:var(--bg-filter); border-bottom:1px solid var(--border-card); gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span>⚡</span>
            <input type="text" id="tableFilterInput" class="filter-control" placeholder="Cari instan di tabel..." onkeyup="filterTableInstant()" style="width: 220px; padding:3px 8px;">
            <span id="rowCountBadge" class="ant-badge ant-badge-blue">0 Baris Data</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="clearTableFilter()">✕ Clear</button>
        </div>
        <div style="font-size:10.5px; color:var(--text-muted);">
            💡 Klik tombol <b>⚡ Post ke IT Inv</b> pada item yang ingin disesuaikan ke kartu stok bc_1ab2.
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="ant-table-wrapper">
        <div class="table-scroll">
            <table class="ant-table" id="mainDataTable">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>Tanggal SO</th>
                        <th>Kode Barang</th>
                        <th>Uraian / Nama Barang</th>
                        <th>Klasifikasi</th>
                        <th>Satuan</th>
                        <th style="text-align:right;">Stok Buku</th>
                        <th style="text-align:right;">Stok Fisik</th>
                        <th style="text-align:right;">Selisih</th>
                        <th>No Dok SPP</th>
                        <th>Tanggal SPP</th>
                        <th style="text-align:right;">Qty SPP</th>
                        <th style="text-align:right;">Nilai SPP</th>
                        <th>Vendor / Entitas</th>
                        <th style="text-align:center;">Status Posting</th>
                        <th>User Post</th>
                        <th style="text-align:center; width:100px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td colspan="17" style="text-align:center; padding:30px; color:var(--text-muted);">
                            Memuat data penyesuaian selisih SO...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL FORM INPUT SPP SEBELUM POSTING -->
<div class="ant-modal-mask" id="sppModal">
    <div class="ant-modal">
        <div class="ant-modal-header">
            <span>Input Dokumen Penyesuaian SPP - <span id="modalKodeBarang" style="color:var(--primary); font-family:monospace;"></span></span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeSppModal()">✕</button>
        </div>
        <div class="ant-modal-body">
            <input type="hidden" id="formTglSo">
            <input type="hidden" id="formKodeBarang">
            <input type="hidden" id="formUnit">

            <div class="form-row">
                <label>Nomor Surat Persetujuan / Dokumen SPP</label>
                <input type="text" id="formNoSpp" class="filter-control" placeholder="Contoh: SPP-2026-001" style="width:100%;">
            </div>
            <div class="form-row">
                <label>Tanggal SPP</label>
                <input type="date" id="formTglSpp" class="filter-control" value="<?= date('Y-m-d') ?>" style="width:100%;">
            </div>
            <div style="display:flex; gap:10px;">
                <div class="form-row" style="flex:1;">
                    <label>Kuantum Penyesuaian (Qty SPP)</label>
                    <input type="number" step="any" id="formQtySpp" class="filter-control" style="width:100%;">
                </div>
                <div class="form-row" style="flex:1;">
                    <label>Estimasi Nilai SPP (IDR)</label>
                    <input type="number" step="any" id="formNilaiSpp" class="filter-control" value="0" style="width:100%;">
                </div>
            </div>
            <div class="form-row">
                <label>Nama Entitas / Rekanan / Keterangan</label>
                <input type="text" id="formBs" class="filter-control" placeholder="Nama Perusahaan / Rekanan..." value="PERUSAHAAN" style="width:100%;">
            </div>
            <div style="font-size:10.5px; color:var(--text-muted); background:var(--bg-filter); padding:8px 10px; border-radius:4px; border:1px solid var(--border-card);">
                💡 Sistem akan mencatat transaksi di kartu stok (<code>bc_1ab2</code>) dengan kode Dokumen <code>SPP</code>. Selisih positif menjadi Pemasukan (A), selisih negatif menjadi Pengeluaran (B).
            </div>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn" onclick="closeSppModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-success" onclick="submitPostSpp()">
                <span>⚡</span> Posting ke IT Inventory
            </button>
        </div>
    </div>
</div>

<script>
let currentData = [];

function loadData() {
    const tgl1 = document.getElementById('filterTgl1').value;
    const tgl2 = document.getElementById('filterTgl2').value;
    const klas = document.getElementById('filterKlas').value;
    const status = document.getElementById('filterStatus').value;
    const q = document.getElementById('filterSearch').value;

    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '<tr><td colspan="17" style="text-align:center; padding:24px; color:var(--text-muted);">Sedang mengambil data selisih...</td></tr>';

    fetch(`adjust_spp.php?action=list&tgl1=${encodeURIComponent(tgl1)}&tgl2=${encodeURIComponent(tgl2)}&klas=${encodeURIComponent(klas)}&status=${encodeURIComponent(status)}&q=${encodeURIComponent(q)}`)
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK') {
                currentData = res.data || [];
                renderTable(currentData);
                document.getElementById('kpiTotal').innerText = (res.kpi.total_items || 0).toLocaleString();
                document.getElementById('kpiUnposted').innerText = (res.kpi.unposted || 0).toLocaleString();
                document.getElementById('kpiPosted').innerText = (res.kpi.posted || 0).toLocaleString();
                document.getElementById('kpiSelisih').innerText = parseFloat(res.kpi.total_selisih || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 4});
                document.getElementById('rowCountBadge').innerText = currentData.length + ' Baris Data';
            } else {
                tbody.innerHTML = `<tr><td colspan="17" style="text-align:center; color:red;">${res.message || 'Gagal memuat data.'}</td></tr>`;
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="17" style="text-align:center; color:red;">Koneksi error: ${err}</td></tr>`;
        });
}

function renderTable(data) {
    const tbody = document.getElementById('tableBody');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="17" style="text-align:center; padding:24px; color:var(--text-muted);">📭 Tidak ada data selisih stok opname yang ditemukan.</td></tr>';
        return;
    }

    let html = '';
    data.forEach((r, idx) => {
        const sel = parseFloat(r.selisih || 0);
        const selColor = sel > 0 ? '#52c41a' : (sel < 0 ? '#f5222d' : 'inherit');
        const selPrefix = sel > 0 ? '+' : '';
        const isPosted = (r.appv === 'Y');

        html += `<tr>
            <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
            <td>${escapeHtml(r.tgl_so)}</td>
            <td style="font-family:monospace; font-weight:600; color:var(--primary);">${escapeHtml(r.kode_barang)}</td>
            <td>${escapeHtml(r.uraian || '-')}</td>
            <td><span class="ant-badge ant-badge-gold">${escapeHtml(r.klas || '-')}</span></td>
            <td>${escapeHtml(r.unit || '-')}</td>
            <td style="text-align:right;">${parseFloat(r.stok || 0).toLocaleString()}</td>
            <td style="text-align:right; font-weight:600;">${parseFloat(r.aktual || 0).toLocaleString()}</td>
            <td style="text-align:right; font-weight:700; color:${selColor};">${selPrefix}${sel.toLocaleString()}</td>
            <td style="font-family:monospace;">${escapeHtml(r.no_spp || '-')}</td>
            <td>${escapeHtml(r.tgl_spp || '-')}</td>
            <td style="text-align:right; font-weight:600;">${parseFloat(r.qty_spp || 0).toLocaleString()}</td>
            <td style="text-align:right; color:#52c41a;">${parseFloat(r.nilai_spp || 0).toLocaleString()}</td>
            <td>${escapeHtml(r.bs || '-')}</td>
            <td style="text-align:center;">
                ${isPosted ? '<span class="ant-badge ant-badge-green">Posted IT Inv</span>' : '<span class="ant-badge ant-badge-gold">Draft SPP</span>'}
            </td>
            <td style="color:var(--text-muted); font-size:10px;">${escapeHtml(r.appv_by || '-')}</td>
            <td style="text-align:center;">
                ${!isPosted ? `
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="openSppModal(${idx})" title="Posting penyesuaian SPP ke IT Inventory">
                        ⚡ Post
                    </button>
                ` : `
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="unpostSpp('${escapeHtml(r.tgl_so)}', '${escapeHtml(r.kode_barang)}', '${escapeHtml(r.no_spp)}')">
                        ↩️ Batal Post
                    </button>
                `}
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function openSppModal(idx) {
    const item = currentData[idx];
    if (!item) return;

    document.getElementById('modalKodeBarang').innerText = `${item.kode_barang} (${item.uraian})`;
    document.getElementById('formTglSo').value = item.tgl_so;
    document.getElementById('formKodeBarang').value = item.kode_barang;
    document.getElementById('formUnit').value = item.unit;
    document.getElementById('formNoSpp').value = item.no_spp || `SPP-${item.tgl_so.replace(/-/g, '')}-${item.kode_barang}`;
    document.getElementById('formTglSpp').value = item.tgl_spp || item.tgl_so;
    document.getElementById('formQtySpp').value = item.qty_spp || Math.abs(parseFloat(item.selisih || 0));
    document.getElementById('formNilaiSpp').value = item.nilai_spp || 0;
    document.getElementById('formBs').value = item.bs || 'PERUSAHAAN';

    document.getElementById('sppModal').style.display = 'flex';
}

function closeSppModal() {
    document.getElementById('sppModal').style.display = 'none';
}

function submitPostSpp() {
    const tglSo = document.getElementById('formTglSo').value;
    const kodeBarang = document.getElementById('formKodeBarang').value;
    const unit = document.getElementById('formUnit').value;
    const noSpp = document.getElementById('formNoSpp').value.trim();
    const tglSpp = document.getElementById('formTglSpp').value;
    const qtySpp = document.getElementById('formQtySpp').value;
    const nilaiSpp = document.getElementById('formNilaiSpp').value;
    const bs = document.getElementById('formBs').value.trim();

    if (!noSpp) {
        showError('Nomor Dokumen SPP wajib diisi.');
        return;
    }

    const fd = new FormData();
    fd.append('tgl_so', tglSo);
    fd.append('kode_barang', kodeBarang);
    fd.append('unit', unit);
    fd.append('no_spp', noSpp);
    fd.append('tgl_spp', tglSpp);
    fd.append('qty_spp', qtySpp);
    fd.append('nilai_spp', nilaiSpp);
    fd.append('bs', bs);

    fetch('adjust_spp.php?action=post_spp', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(res => {
        if (res.status === 'OK') {
            closeSppModal();
            showSuccess(res.message, 'Berhasil Diposting');
            loadData();
        } else {
            showError(res.message || 'Gagal posting SPP.');
        }
    })
    .catch(err => {
        showError('Koneksi error: ' + err);
    });
}

function unpostSpp(tglSo, kodeBarang, noSpp) {
    Swal.fire({
        title: 'Batalkan Posting SPP?',
        html: `Apakah Anda yakin ingin membatalkan posting penyesuaian untuk <b>${kodeBarang}</b> (Dokumen SPP: <b>${noSpp}</b>)? Data pada kartu stok <code>bc_1ab2</code> akan dihapus.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ff4d4f',
        confirmButtonText: 'Ya, Batalkan Posting',
        cancelButtonText: 'Batal'
    }).then(res => {
        if (res.isConfirmed) {
            const fd = new FormData();
            fd.append('tgl_so', tglSo);
            fd.append('kode_barang', kodeBarang);
            fd.append('no_spp', noSpp);

            fetch('adjust_spp.php?action=unpost_spp', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(r => {
                if (r.status === 'OK') {
                    showSuccess(r.message, 'Posting Dibatalkan');
                    loadData();
                } else {
                    showError(r.message || 'Gagal membatalkan posting.');
                }
            })
            .catch(err => {
                showError('Koneksi error: ' + err);
            });
        }
    });
}

function resetFilter() {
    document.getElementById('filterTgl1').value = '<?= date('Y-01-01') ?>';
    document.getElementById('filterTgl2').value = '<?= date('Y-m-d') ?>';
    document.getElementById('filterKlas').value = '';
    document.getElementById('filterStatus').value = 'N';
    document.getElementById('filterSearch').value = '';
    loadData();
}

function filterTableInstant() {
    const input = document.getElementById("tableFilterInput");
    if (!input) return;
    const filter = input.value.toLowerCase().trim();
    const table = document.getElementById("mainDataTable");
    if (!table) return;
    const tbody = table.querySelector("tbody");
    if (!tbody) return;
    const rows = tbody.querySelectorAll("tr");

    let visibleCount = 0;
    rows.forEach(row => {
        if (row.cells.length <= 1) return;
        const text = row.innerText.toLowerCase();
        if (filter === '' || text.indexOf(filter) !== -1) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const badge = document.getElementById("rowCountBadge");
    if (badge) {
        if (filter === '') {
            badge.innerText = visibleCount + " Baris Data";
            badge.className = "ant-badge ant-badge-blue";
        } else {
            badge.innerText = visibleCount + " Ditemukan";
            badge.className = visibleCount > 0 ? "ant-badge ant-badge-green" : "ant-badge ant-badge-red";
        }
    }
}

function clearTableFilter() {
    const input = document.getElementById("tableFilterInput");
    if (input) {
        input.value = "";
        filterTableInstant();
    }
}

let currentSortCol = -1;
let currentSortDir = 'asc';

function sortTable(colIndex) {
    const table = document.getElementById("mainDataTable");
    if (!table) return;
    const tbody = table.querySelector("tbody");
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll("tr"));
    if (rows.length === 0 || (rows.length === 1 && rows[0].cells.length <= 1)) return;

    if (currentSortCol === colIndex) {
        currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc';
    } else {
        currentSortCol = colIndex;
        currentSortDir = 'asc';
    }

    const ths = table.querySelectorAll("thead th");
    ths.forEach((th, idx) => {
        const icon = th.querySelector(".sort-icon");
        if (icon) {
            if (idx === colIndex) {
                icon.textContent = currentSortDir === 'asc' ? ' ▲' : ' ▼';
                icon.style.opacity = '1';
                icon.style.color = 'var(--primary)';
            } else {
                icon.textContent = ' ↕';
                icon.style.opacity = '0.35';
                icon.style.color = 'inherit';
            }
        }
    });

    rows.sort((a, b) => {
        const cellA = (a.cells[colIndex] ? a.cells[colIndex].innerText : '').trim();
        const cellB = (b.cells[colIndex] ? b.cells[colIndex].innerText : '').trim();

        const cleanA = cellA.replace(/,/g, '').replace(/[^\d.-]/g, '');
        const cleanB = cellB.replace(/,/g, '').replace(/[^\d.-]/g, '');
        const numA = parseFloat(cleanA);
        const numB = parseFloat(cleanB);

        let comp = 0;
        if (!isNaN(numA) && !isNaN(numB) && cellA !== '' && cellB !== '' && !cellA.includes('-') && !cellA.match(/^[A-Za-z]/)) {
            comp = numA - numB;
        } else if (cellA.match(/^\d{4}-\d{2}-\d{2}/) && cellB.match(/^\d{4}-\d{2}-\d{2}/)) {
            comp = Date.parse(cellA) - Date.parse(cellB);
        } else {
            comp = cellA.localeCompare(cellB, undefined, { numeric: true, sensitivity: 'base' });
        }

        return currentSortDir === 'asc' ? comp : -comp;
    });

    rows.forEach(r => tbody.appendChild(r));

    let noIdx = 1;
    rows.forEach(r => {
        if (r.cells.length > 1 && r.cells[0]) {
            r.cells[0].innerText = noIdx++;
        }
    });
}

function initSortableTable() {
    const table = document.getElementById("mainDataTable");
    if (!table) return;
    const ths = table.querySelectorAll("thead tr:last-child th");
    ths.forEach((th, idx) => {
        if (idx === 0 || idx === 16) return;
        th.classList.add("sortable-th");
        th.title = "Klik untuk mengurutkan (Sort)";
        if (!th.querySelector(".sort-icon")) {
            const icon = document.createElement("span");
            icon.className = "sort-icon";
            icon.style.opacity = "0.35";
            icon.style.marginLeft = "4px";
            icon.style.fontSize = "11px";
            icon.textContent = " ↕";
            th.appendChild(icon);
        }
        th.addEventListener("click", () => sortTable(idx));
    });
}

function exportToExcel() {
    if (typeof XLSX === 'undefined') {
        alert('Library Excel belum siap.');
        return;
    }
    const table = document.getElementById("mainDataTable");
    if (!table) return;

    const cloned = table.cloneNode(true);
    cloned.querySelectorAll(".sort-icon").forEach(i => i.remove());
    cloned.querySelectorAll("tr").forEach(r => {
        if (r.cells.length > 16) r.deleteCell(16); // delete action column
        if (r.style.display === 'none') r.remove();
    });

    const wb = XLSX.utils.table_to_book(cloned, { sheet: "Adjust_SPP" });
    XLSX.writeFile(wb, "Adjust_SPP_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

function exportToCSV() {
    const table = document.getElementById("mainDataTable");
    let csv = [];
    for (let row of table.rows) {
        let cols = [];
        for (let j = 0; j < row.cells.length - 1; j++) {
            cols.push('"' + row.cells[j].innerText.replace(/"/g, '""').trim() + '"');
        }
        csv.push(cols.join(","));
    }
    const blob = new Blob([csv.join("\n")], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "Adjust_SPP_" + new Date().toISOString().slice(0,10) + ".csv";
    link.click();
}

function copyTableClipboard() {
    const table = document.getElementById("mainDataTable");
    let csv = [];
    for (let row of table.rows) {
        let cols = [];
        for (let j = 0; j < row.cells.length - 1; j++) {
            cols.push('"' + row.cells[j].innerText.replace(/"/g, '""').trim() + '"');
        }
        csv.push(cols.join("\t"));
    }
    navigator.clipboard.writeText(csv.join("\n")).then(() => {
        showSuccess("Tabel berhasil disalin ke clipboard!", "Tersalin");
    }).catch(() => {
        showInfo("Gagal menyalin otomatis. Silakan blok tabel secara manual.");
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener("DOMContentLoaded", () => {
    initSortableTable();
    loadData();
});
</script>
</body>
</html>

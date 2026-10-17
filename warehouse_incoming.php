<?php
/**
 * CEISA 4.0 - Warehouse: Incoming (warehouse_incoming.php)
 * Mengadopsi w_bc_ceisa_scan27_check.srw & d_ceisa_scan27_cek.srd
 * Monitoring & Pemeriksaan Pemasukan Barang (BC 2.7 IN, BC 2.3, BC 1.6, BC 4.0) vs IT Inventory
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$action = $_REQUEST['action'] ?? '';

// 1. AJAX: LIST DATA INCOMING DENGAN STATUS IT INVENTORY
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $dt1    = trim($_GET['dt1'] ?? date('Y-m-01'));
    $dt2    = trim($_GET['dt2'] ?? date('Y-m-d'));
    $bc     = trim($_GET['bc'] ?? '');
    $status = trim($_GET['status'] ?? ''); // 'posted', 'unposted', ''
    $search = trim($_GET['q'] ?? '');

    $where = [];
    if (!empty($dt1) && !empty($dt2)) {
        $where[] = "a.tanggal_daftar BETWEEN '" . $koneksi->real_escape_string($dt1) . "' AND '" . $koneksi->real_escape_string($dt2) . "'";
    }
    if (!empty($bc)) {
        $where[] = "a.kode_dokumen = '" . $koneksi->real_escape_string($bc) . "'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(a.nomor_aju LIKE '%$s%' OR a.nomor_daftar LIKE '%$s%' OR b.nama_entitas LIKE '%$s%' OR d.nomor_dokumen LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT 
        a.nomor_aju, a.kode_dokumen, a.nomor_daftar, a.tanggal_daftar, a.kode_kantor,
        COALESCE(b.nama_entitas, '-') AS vendor,
        COALESCE(d.dokumen, '-') AS invoice_sj,
        COALESCE(k.kemasan, '-') AS kemasan,
        (SELECT COUNT(1) FROM bc_ceisa_get_barang brg WHERE brg.nomor_aju = a.nomor_aju) AS total_items,
        (SELECT COALESCE(SUM(brg.cif), 0) FROM bc_ceisa_get_barang brg WHERE brg.nomor_aju = a.nomor_aju) AS total_cif,
        (SELECT COUNT(1) FROM bc_1ab2 inv WHERE inv.nomor_aju = a.nomor_aju) AS is_posted
    FROM bc_ceisa_get_data a
    LEFT JOIN (
        SELECT nomor_aju, MAX(nama_entitas) AS nama_entitas 
        FROM bc_ceisa_get_entitas 
        WHERE kode_entitas IN ('3', '7', '8', 'PENGIRIM', 'PEMASOK', 'PENJUAL') 
        GROUP BY nomor_aju
    ) b ON a.nomor_aju = b.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, GROUP_CONCAT(CONCAT(kode_dokumen, ':', nomor_dokumen) SEPARATOR ', ') AS dokumen
        FROM bc_ceisa_get_dokumen 
        GROUP BY nomor_aju
    ) d ON a.nomor_aju = d.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, GROUP_CONCAT(CONCAT(jumlah_kemasan, ' ', kode_jenis_kemasan) SEPARATOR ', ') AS kemasan
        FROM bc_ceisa_get_kemasan
        GROUP BY nomor_aju
    ) k ON a.nomor_aju = k.nomor_aju
    $whereSql
    GROUP BY a.nomor_aju, a.kode_dokumen, a.nomor_daftar, a.tanggal_daftar, a.kode_kantor, b.nama_entitas, d.dokumen, k.kemasan
    ORDER BY a.tanggal_daftar DESC, a.nomor_aju DESC
    LIMIT 200";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if ($status === 'posted' && $r['is_posted'] == 0) continue;
            if ($status === 'unposted' && $r['is_posted'] > 0) continue;
            $data[] = $r;
        }
    }

    // KPI Summary
    $kpiTotal = $koneksi->query("SELECT COUNT(DISTINCT nomor_aju) FROM bc_ceisa_get_data")->fetch_row()[0] ?? 0;
    $kpiPosted = $koneksi->query("SELECT COUNT(DISTINCT nomor_aju) FROM bc_1ab2 WHERE AB = 'A'")->fetch_row()[0] ?? 0;
    $kpiUnposted = max(0, $kpiTotal - $kpiPosted);
    $kpiBc27 = $koneksi->query("SELECT COUNT(DISTINCT nomor_aju) FROM bc_ceisa_get_data WHERE kode_dokumen = '27' OR kode_dokumen = '2.7'")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'data' => $data,
        'count' => count($data),
        'kpi' => [
            'total' => (int)$kpiTotal,
            'posted' => (int)$kpiPosted,
            'unposted' => (int)$kpiUnposted,
            'bc27' => (int)$kpiBc27
        ]
    ]);
    exit;
}

// 2. AJAX: GET RINCIAN BARANG PEMASUKAN
if ($action === 'detail') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_GET['aju'] ?? '');
    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU kosong']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($aju);
    $hdr = $koneksi->query("SELECT * FROM bc_ceisa_get_data WHERE nomor_aju = '$escAju' LIMIT 1")->fetch_assoc();
    $items = [];
    $itemRes = $koneksi->query("SELECT 
        b.*, 
        (SELECT klas FROM bc_material m WHERE m.KODE_BARANG = b.kode_barang LIMIT 1) AS klas_material,
        (SELECT KODE_BARANG FROM bc_material m WHERE m.KODE_BARANG = b.kode_barang LIMIT 1) AS match_material
    FROM bc_ceisa_get_barang b 
    WHERE b.nomor_aju = '$escAju' 
    ORDER BY b.seri_barang ASC");
    if ($itemRes) {
        while ($r = $itemRes->fetch_assoc()) {
            $items[] = $r;
        }
    }

    $postedRes = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '$escAju'");
    $isPosted = ($postedRes && $postedRes->fetch_row()[0] > 0);

    echo json_encode([
        'status' => 'OK',
        'header' => $hdr,
        'items' => $items,
        'is_posted' => $isPosted
    ]);
    exit;
}

// 3. AJAX: POSTING PEMASUKAN KE IT INVENTORY (bc_1ab2)
if ($action === 'post_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_POST['aju'] ?? '');
    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU kosong']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($aju);

    // Cek apakah sudah pernah diposting
    $cek = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '$escAju'")->fetch_row()[0] ?? 0;
    if ($cek > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU $aju sudah terposting di IT Inventory."]);
        exit;
    }

    // Eksekusi insert into bc_1ab2 dari bc_ceisa_get_*
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
            COALESCE(a.kode_dokumen, '27') AS BC,
            a.nomor_daftar,
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
            'A' AS AB, -- 'A' = PEMASUKAN (INCOMING)
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
            'SYSTEM' AS CRT_USER
        FROM bc_ceisa_get_data a
        JOIN bc_ceisa_get_barang brg ON a.nomor_aju = brg.nomor_aju
        LEFT JOIN bc_ceisa_get_entitas ent ON a.nomor_aju = ent.nomor_aju AND ent.kode_entitas IN ('3', '7', '8', 'PENGIRIM', 'PEMASOK')
        LEFT JOIN bc_ceisa_get_dokumen doc ON a.nomor_aju = doc.nomor_aju AND doc.kode_dokumen IN ('380', 'INVOICE')
        LEFT JOIN bc_ceisa_get_kemasan kem ON a.nomor_aju = kem.nomor_aju
        LEFT JOIN bc_material mat ON brg.kode_barang = mat.KODE_BARANG
        WHERE a.nomor_aju = '$escAju'";

        if (!$koneksi->query($insertSql)) {
            throw new Exception("Gagal posting ke IT Inventory: " . $koneksi->error);
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Nomor AJU $aju berhasil diposting ke IT Inventory (Pemasukan)."]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Warehouse - Incoming Monitoring</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --border: #e8e8e8;
            --text: #262626;
            --text-secondary: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #f5222d;
            --row-hover: #f0f7ff;
        }
        [data-theme='dark'] {
            --bg: #141414;
            --card-bg: #1f1f1f;
            --border: #303030;
            --text: rgba(255, 255, 255, 0.85);
            --text-secondary: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --row-hover: #262638;
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
        }
        .kpi-title { font-size: 9px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; }
        .kpi-value { font-size: 11px; font-weight: 700; color: var(--text); }
        .kpi-icon { font-size: 13px; opacity: 0.8; }
        
        .toolbar-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 5px 8px;
            margin-bottom: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        
        input, select, button {
            font-family: inherit;
            font-size: 9.5px;
            height: 22px;
            padding: 1px 6px;
            border-radius: 2px;
            border: 1px solid var(--border);
            background: var(--card-bg);
            color: var(--text);
            outline: none;
        }
        input:focus, select:focus { border-color: var(--primary); }
        .btn-primary { background: var(--primary); color: #fff; border: 1px solid var(--primary); cursor: pointer; font-weight: 500; font-size: 10px; height: 22px; padding: 0 7px; border-radius: 2px; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-default { cursor: pointer; font-size: 10px; height: 22px; padding: 0 7px; border-radius: 2px; }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            overflow: auto;
            max-height: calc(100vh - 170px);
        }
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th {
            position: sticky;
            top: 0;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            padding: 4px 6px;
            font-size: 9.5px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            z-index: 2;
        }
        td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            vertical-align: middle;
        }
        tr:hover td { background-color: var(--row-hover); }
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
            border-radius: 8px;
            width: 90%;
            max-width: 950px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .modal-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-body { padding: 16px; overflow-y: auto; flex: 1; }
        .modal-footer {
            padding: 10px 16px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
    </style>
</head>
<body>

    <!-- KPI Cards -->
    <div class="kpi-container">
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Total Dokumen Pemasukan</div>
                <div class="kpi-value" id="kpi-total">0</div>
            </div>
            <i class="fa-solid fa-truck-ramp-box kpi-icon" style="color: #1890ff;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Sudah Masuk IT Inventory</div>
                <div class="kpi-value" id="kpi-posted">0</div>
            </div>
            <i class="fa-solid fa-circle-check kpi-icon" style="color: #52c41a;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Belum Diposting</div>
                <div class="kpi-value" id="kpi-unposted">0</div>
            </div>
            <i class="fa-solid fa-clock kpi-icon" style="color: #faad14;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Dokumen BC 2.7 IN</div>
                <div class="kpi-value" id="kpi-bc27">0</div>
            </div>
            <i class="fa-solid fa-file-invoice kpi-icon" style="color: #722ed1;"></i>
        </div>
    </div>

    <!-- Toolbar Filters -->
    <div class="toolbar-card">
        <div class="toolbar-left">
            <span style="font-weight:600; color:var(--text-secondary);"><i class="fa-solid fa-filter"></i> Tanggal:</span>
            <input type="date" id="filter-dt1" value="<?= date('Y-m-01') ?>">
            <span>s/d</span>
            <input type="date" id="filter-dt2" value="<?= date('Y-m-d') ?>">
            <select id="filter-bc">
                <option value="">Semua Dokumen BC</option>
                <option value="27">BC 2.7 (TPB ke TPB)</option>
                <option value="23">BC 2.3 (Impor TPB)</option>
                <option value="16">BC 1.6 (LDP ke TPB)</option>
                <option value="40">BC 4.0 (TLDDP ke TPB)</option>
            </select>
            <select id="filter-status">
                <option value="">Semua Status IT Inv</option>
                <option value="posted">Sudah Masuk IT Inv</option>
                <option value="unposted">Belum Masuk IT Inv</option>
            </select>
            <input type="text" id="filter-search" placeholder="Cari AJU / Daftar / Vendor..." style="width: 180px;">
            <button class="btn-default" onclick="loadData()"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        </div>
        <div class="toolbar-right">
            <button class="btn-default" onclick="exportCsv()"><i class="fa-solid fa-file-csv"></i> Ekspor CSV</button>
        </div>
    </div>

    <!-- Table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 35px; text-align: center;">#</th>
                    <th>Dokumen</th>
                    <th>Nomor AJU</th>
                    <th>No & Tgl Pendaftaran</th>
                    <th>Pemasok / Vendor</th>
                    <th>Dokumen Pelengkap</th>
                    <th>Kemasan</th>
                    <th style="text-align: right;">Total Item</th>
                    <th style="text-align: right;">Nilai CIF / Pabean</th>
                    <th style="text-align: center;">Status IT Inv</th>
                    <th style="text-align: center; width: 100px;">Aksi</th>
                </tr>
            </thead>
            <tbody id="table-body">
                <tr><td colspan="11" style="text-align: center; padding: 20px;">Memuat data...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Modal View Detail -->
    <div class="modal" id="modal-detail">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size: 13px; font-weight: 700;" id="detail-title"><i class="fa-solid fa-circle-info"></i> Pemeriksaan Barang Masuk</h3>
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
            const dt1 = document.getElementById('filter-dt1').value;
            const dt2 = document.getElementById('filter-dt2').value;
            const bc = document.getElementById('filter-bc').value;
            const status = document.getElementById('filter-status').value;
            const q = document.getElementById('filter-search').value;

            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; padding: 20px;">Memuat data...</td></tr>';

            try {
                const res = await fetch(`warehouse_incoming.php?action=list&dt1=${dt1}&dt2=${dt2}&bc=${bc}&status=${status}&q=${encodeURIComponent(q)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    currentData = json.data;
                    document.getElementById('kpi-total').innerText = Number(json.kpi.total).toLocaleString('id-ID');
                    document.getElementById('kpi-posted').innerText = Number(json.kpi.posted).toLocaleString('id-ID');
                    document.getElementById('kpi-unposted').innerText = Number(json.kpi.unposted).toLocaleString('id-ID');
                    document.getElementById('kpi-bc27').innerText = Number(json.kpi.bc27).toLocaleString('id-ID');

                    renderTable(json.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; color: red;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; color: red;">Error: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('table-body');
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; padding: 20px;">Tidak ada dokumen ditemukan.</td></tr>';
                return;
            }

            tbody.innerHTML = data.map((r, i) => {
                const isPosted = parseInt(r.is_posted) > 0;
                const badge = isPosted 
                    ? '<span class="badge badge-posted"><i class="fa-solid fa-check"></i> Masuk IT Inv</span>'
                    : '<span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Belum Masuk</span>';

                return `
                    <tr>
                        <td style="text-align: center; color: var(--text-secondary);">${i + 1}</td>
                        <td><span style="font-weight: 700; color: var(--primary);">BC ${r.kode_dokumen}</span></td>
                        <td style="font-family: monospace; font-weight: 600; cursor: pointer; color: var(--primary);" onclick="viewDetail('${r.nomor_aju}')">${r.nomor_aju}</td>
                        <td>${r.nomor_daftar || '-'} <span style="color:var(--text-secondary); font-size:10px;">(${r.tanggal_daftar || '-'})</span></td>
                        <td><span style="font-weight: 500;">${r.vendor || '-'}</span></td>
                        <td>${r.invoice_sj || '-'}</td>
                        <td>${r.kemasan || '-'}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_items || 0).toLocaleString('id-ID')}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_cif || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align: center;">${badge}</td>
                        <td style="text-align: center;">
                            <button class="btn-default" style="padding: 2px 6px;" title="Periksa Rincian" onclick="viewDetail('${r.nomor_aju}')"><i class="fa-solid fa-eye"></i></button>
                            ${!isPosted ? `<button class="btn-primary" style="padding: 2px 6px;" title="Post ke IT Inventory" onclick="postToItInv('${r.nomor_aju}')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Post</button>` : ''}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function viewDetail(aju) {
            document.getElementById('modal-detail').style.display = 'flex';
            const body = document.getElementById('detail-body');
            body.innerHTML = 'Memuat rincian dokumen & barang...';

            try {
                const res = await fetch(`warehouse_incoming.php?action=detail&aju=${encodeURIComponent(aju)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    const h = json.header;
                    document.getElementById('detail-title').innerHTML = `<i class="fa-solid fa-file-invoice"></i> Pemeriksaan Dokumen Masuk: ${h.nomor_aju}`;
                    
                    let html = `
                        <div style="background: var(--bg); padding: 10px; border-radius: 4px; margin-bottom: 12px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                            <div><strong style="color:var(--text-secondary);">Jenis BC:</strong> BC ${h.kode_dokumen}</div>
                            <div><strong style="color:var(--text-secondary);">No Pendaftaran:</strong> ${h.nomor_daftar || '-'}</div>
                            <div><strong style="color:var(--text-secondary);">Tanggal Daftar:</strong> ${h.tanggal_daftar || '-'}</div>
                            <div><strong style="color:var(--text-secondary);">Status IT Inv:</strong> ${json.is_posted ? '<span style="color:var(--success); font-weight:600;">Sudah Masuk</span>' : '<span style="color:var(--warning); font-weight:600;">Belum Masuk</span>'}</div>
                        </div>
                        <table style="width: 100%;">
                            <thead>
                                <tr style="background: var(--bg);">
                                    <th style="width: 30px;">Seri</th>
                                    <th>Kode Barang</th>
                                    <th>Uraian Barang</th>
                                    <th>Pos Tarif (HS)</th>
                                    <th style="text-align: right;">Jumlah Satuan</th>
                                    <th>Satuan</th>
                                    <th style="text-align: right;">Nilai CIF</th>
                                    <th style="text-align: center;">Master Material</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    if (json.items.length === 0) {
                        html += `<tr><td colspan="8" style="text-align: center; padding: 12px;">Tidak ada rincian barang.</td></tr>`;
                    } else {
                        html += json.items.map(b => `
                            <tr>
                                <td style="text-align: center;">${b.seri_barang}</td>
                                <td style="font-weight: 600;">${b.kode_barang}</td>
                                <td>${b.uraian || '-'}</td>
                                <td>${b.pos_tarif || '-'}</td>
                                <td style="text-align: right; font-weight: 600;">${Number(b.jumlah_satuan || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                                <td>${b.kode_satuan || '-'}</td>
                                <td style="text-align: right;">${Number(b.cif || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                                <td style="text-align: center;">
                                    ${b.match_material ? `<span class="badge badge-posted">${b.klas_material || 'Valid'}</span>` : `<span class="badge badge-pending">Belum Terdaftar</span>`}
                                </td>
                            </tr>
                        `).join('');
                    }

                    html += `</tbody></table>`;
                    body.innerHTML = html;

                    // Update footer buttons
                    const footer = document.getElementById('detail-footer');
                    footer.innerHTML = `
                        <button class="btn-default" onclick="closeModal('modal-detail')">Tutup</button>
                        ${!json.is_posted ? `<button class="btn-primary" onclick="postToItInv('${h.nomor_aju}')"><i class="fa-solid fa-arrow-right-to-bracket"></i> Post ke IT Inventory</button>` : ''}
                    `;
                } else {
                    body.innerHTML = `<span style="color:red;">${json.message}</span>`;
                }
            } catch (err) {
                body.innerHTML = `<span style="color:red;">Error: ${err.message}</span>`;
            }
        }

        async function postToItInv(aju) {
            if (!confirm(`Konfirmasi: Posting Nomor AJU ${aju} ke IT Inventory sebagai PEMASUKAN?`)) return;
            const fd = new FormData();
            fd.append('action', 'post_it_inv');
            fd.append('aju', aju);

            try {
                const res = await fetch('warehouse_incoming.php', { method: 'POST', body: fd });
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

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function exportCsv() {
            if (!currentData.length) return alert('Tidak ada data.');
            let csv = "Dokumen,Nomor AJU,No Daftar,Tgl Daftar,Vendor,Dokumen Pelengkap,Kemasan,Total Item,Nilai CIF,Status IT Inv\n";
            currentData.forEach(r => {
                csv += `"${r.kode_dokumen}","${r.nomor_aju}","${r.nomor_daftar || ''}","${r.tanggal_daftar || ''}","${r.vendor || ''}","${r.invoice_sj || ''}","${r.kemasan || ''}",${r.total_items},${r.total_cif},"${r.is_posted > 0 ? 'POSTED' : 'UNPOSTED'}"\n`;
            });
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `warehouse_incoming_${new Date().toISOString().slice(0,10)}.csv`;
            a.click();
        }

        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>
</html>

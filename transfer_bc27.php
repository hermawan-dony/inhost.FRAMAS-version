<?php
/**
 * CEISA 4.0 - Warehouse: Transfer BC27 OUT-->IN (transfer_bc27.php)
 * Mengadopsi w_bc_ceisa_27in_transfer.srw & d_rpt_27in_transfer.srd
 * Rekonsiliasi & Transfer Dokumen BC 2.7 Pengeluaran (OUT) menjadi Pemasukan (IN) Antar Pabrik/TPB
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$action = $_REQUEST['action'] ?? '';

// 1. AJAX: LIST DATA TRANSFER BC 2.7
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $dt1    = trim($_GET['dt1'] ?? date('Y-01-01'));
    $dt2    = trim($_GET['dt2'] ?? date('Y-m-d'));
    $status = trim($_GET['status'] ?? ''); // 'transferred', 'pending', ''
    $search = trim($_GET['q'] ?? '');

    $where = ["AB = 'B'", "BC = '27'"];
    if (!empty($dt1) && !empty($dt2)) {
        $where[] = "COALESCE(TGL_PROSES, TANGGAL_DAFTAR) BETWEEN '" . $koneksi->real_escape_string($dt1) . "' AND '" . $koneksi->real_escape_string($dt2) . "'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(NOMOR_AJU LIKE '%$s%' OR NOMOR_DAFTAR LIKE '%$s%' OR NAMA_BS LIKE '%$s%' OR KODE_BARANG LIKE '%$s%' OR URAIAN LIKE '%$s%')";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT 
        ID_HEADER, SERI_BARANG, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR,
        NO_BUKTI, TGL_BUKTI, NAMA_BS AS PENERIMA, KODE_BARANG, URAIAN,
        QTY, UNIT, NILAI, KURS, POS_TARIF, KLAS, NOMOR_AJU,
        INVOICE, FAKTUR, SJ, NOMOR_PO, TUJUAN_PENGIRIMAN, TUJUAN_PEMASUKAN,
        COALESCE(TGL_PROSES, TANGGAL_DAFTAR) AS TANGGAL_PROSES, NETTO, HARGA_PENYERAHAN,
        (SELECT COUNT(1) FROM bc_1ab2 x WHERE x.NOMOR_AJU = m.NOMOR_AJU AND x.AB = 'A') AS is_transferred
    FROM bc_1ab2 m
    $whereSql
    ORDER BY COALESCE(TGL_PROSES, TANGGAL_DAFTAR) DESC, NOMOR_DAFTAR DESC, SERI_BARANG ASC
    LIMIT 250";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            if ($status === 'transferred' && $r['is_transferred'] == 0) continue;
            if ($status === 'pending' && $r['is_transferred'] > 0) continue;
            $data[] = $r;
        }
    }

    // KPI Counters
    $kpiTotal = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE AB = 'B' AND BC = '27'")->fetch_row()[0] ?? 0;
    $kpiQty   = $koneksi->query("SELECT COALESCE(SUM(QTY), 0) FROM bc_1ab2 WHERE AB = 'B' AND BC = '27'")->fetch_row()[0] ?? 0;
    $kpiDone  = $koneksi->query("SELECT COUNT(DISTINCT NOMOR_AJU) FROM bc_1ab2 WHERE AB = 'A' AND BC = '27'")->fetch_row()[0] ?? 0;
    $kpiPending = max(0, $kpiTotal - $kpiDone);

    echo json_encode([
        'status' => 'OK',
        'data' => $data,
        'count' => count($data),
        'kpi' => [
            'total' => (int)$kpiTotal,
            'total_qty' => (float)$kpiQty,
            'transferred' => (int)$kpiDone,
            'pending' => (int)$kpiPending
        ]
    ]);
    exit;
}

// 2. AJAX: PROSES TRANSFER BC27 OUT -> IN
if ($action === 'transfer_in') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_POST['aju'] ?? '');
    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU kosong']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($aju);

    // Cek apakah sudah pernah ditransfer
    $cek = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE NOMOR_AJU = '$escAju' AND AB = 'A'")->fetch_row()[0] ?? 0;
    if ($cek > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU $aju sudah terdaftar sebagai Pemasukan (IN)."]);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        $ins = "INSERT INTO bc_1ab2 (
            BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG,
            POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, SJ, INVOICE,
            FAKTUR, NOMOR_AJU, TUJUAN_PEMASUKAN, TUJUAN_PENGIRIMAN, NPWP_BS, SERI_BARANG,
            HARGA_PENYERAHAN, CIF, NETTO, JUMLAH_KEMASAN, DETAIL_KEMASAN, SESUAI, QTY_AKTUAL,
            TGL_PROSES, CRT_DT, CRT_USER
        )
        SELECT 
            BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG,
            POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, 'A' AS AB, ID_HEADER, SJ, INVOICE,
            FAKTUR, NOMOR_AJU, TUJUAN_PEMASUKAN, TUJUAN_PENGIRIMAN, NPWP_BS, SERI_BARANG,
            HARGA_PENYERAHAN, CIF, NETTO, JUMLAH_KEMASAN, DETAIL_KEMASAN, SESUAI, QTY_AKTUAL,
            NOW() AS TGL_PROSES, NOW() AS CRT_DT, 'TRANSFER_IN' AS CRT_USER
        FROM bc_1ab2
        WHERE NOMOR_AJU = '$escAju' AND AB = 'B'";

        if (!$koneksi->query($ins)) {
            throw new Exception("Gagal transfer: " . $koneksi->error);
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Dokumen BC 2.7 AJU $aju berhasil ditransfer menjadi Pemasukan (IN)."]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// 3. AJAX: UNPOST TRANSFER (BATALKAN PEMASUKAN)
if ($action === 'unpost_in') {
    header('Content-Type: application/json; charset=utf-8');
    $aju = trim($_POST['aju'] ?? '');
    if (empty($aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU kosong']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($aju);
    $del = $koneksi->query("DELETE FROM bc_1ab2 WHERE NOMOR_AJU = '$escAju' AND AB = 'A'");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => "Pemasukan untuk AJU $aju berhasil dibatalkan (Unpost)."]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => "Gagal unpost: " . $koneksi->error]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Warehouse - Transfer BC 2.7 OUT -> IN</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --row-hover: #fafafa;
        }
        [data-theme='dark'] {
            --bg: #141414;
            --card-bg: #1f1f1f;
            --border: #303030;
            --text: #d9d9d9;
            --text-secondary: #6e6e6e;
            --primary: #177ddc;
            --primary-hover: #3c9ae8;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --row-hover: #262626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Public Sans', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            font-size: 11.5px;
            padding: 12px;
        }
        .kpi-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-title { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; }
        .kpi-value { font-size: 20px; font-weight: 700; margin-top: 4px; color: var(--text); }
        .kpi-icon { font-size: 24px; opacity: 0.8; }
        
        .toolbar-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        
        input, select, button {
            font-family: inherit;
            font-size: 11.5px;
            padding: 5px 10px;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--card-bg);
            color: var(--text);
            outline: none;
        }
        input:focus, select:focus { border-color: var(--primary); }
        .btn-primary { background: var(--primary); color: #fff; border: none; cursor: pointer; font-weight: 500; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-default { cursor: pointer; }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: auto;
            max-height: calc(100vh - 200px);
        }
        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th {
            position: sticky;
            top: 0;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            z-index: 2;
        }
        td {
            padding: 7px 10px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        tr:hover td { background-color: var(--row-hover); }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-transferred { background: rgba(82, 196, 26, 0.15); color: var(--success); }
        .badge-pending { background: rgba(250, 173, 20, 0.15); color: var(--warning); }
    </style>
</head>
<body>

    <!-- KPI Cards -->
    <div class="kpi-container">
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Total Item BC 2.7 OUT</div>
                <div class="kpi-value" id="kpi-total">0</div>
            </div>
            <i class="fa-solid fa-arrow-up-from-bracket kpi-icon" style="color: #1890ff;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Total Qty Transfer</div>
                <div class="kpi-value" id="kpi-qty">0</div>
            </div>
            <i class="fa-solid fa-cubes-stacked kpi-icon" style="color: #52c41a;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Sudah Masuk (IN)</div>
                <div class="kpi-value" id="kpi-done">0</div>
            </div>
            <i class="fa-solid fa-circle-check kpi-icon" style="color: #722ed1;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Menunggu Transfer IN</div>
                <div class="kpi-value" id="kpi-pending">0</div>
            </div>
            <i class="fa-solid fa-clock kpi-icon" style="color: #faad14;"></i>
        </div>
    </div>

    <!-- Toolbar Filters -->
    <div class="toolbar-card">
        <div class="toolbar-left">
            <span style="font-weight:600; color:var(--text-secondary);"><i class="fa-solid fa-filter"></i> Periode:</span>
            <input type="date" id="filter-dt1" value="<?= date('Y-01-01') ?>">
            <span>s/d</span>
            <input type="date" id="filter-dt2" value="<?= date('Y-m-d') ?>">
            <select id="filter-status">
                <option value="">Semua Status</option>
                <option value="transferred">Sudah Ditransfer ke IN</option>
                <option value="pending">Belum Ditransfer ke IN</option>
            </select>
            <input type="text" id="filter-search" placeholder="Cari AJU / Daftar / Penerima..." style="width: 200px;">
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
                    <th>Nomor AJU BC 2.7 OUT</th>
                    <th>No & Tgl Pendaftaran</th>
                    <th>Penerima (Tujuan)</th>
                    <th>Kode Barang</th>
                    <th>Nama Barang</th>
                    <th style="text-align: right;">Qty</th>
                    <th>Satuan</th>
                    <th style="text-align: right;">Nilai Penyerahan</th>
                    <th>No Bukti / SJ</th>
                    <th>Tgl Proses</th>
                    <th style="text-align: center;">Status IN</th>
                    <th style="text-align: center; width: 110px;">Aksi</th>
                </tr>
            </thead>
            <tbody id="table-body">
                <tr><td colspan="13" style="text-align: center; padding: 20px;">Memuat data...</td></tr>
            </tbody>
        </table>
    </div>

    <script>
        let currentData = [];

        async function loadData() {
            const dt1 = document.getElementById('filter-dt1').value;
            const dt2 = document.getElementById('filter-dt2').value;
            const status = document.getElementById('filter-status').value;
            const q = document.getElementById('filter-search').value;

            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; padding: 20px;">Memuat data...</td></tr>';

            try {
                const res = await fetch(`transfer_bc27.php?action=list&dt1=${dt1}&dt2=${dt2}&status=${status}&q=${encodeURIComponent(q)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    currentData = json.data;
                    document.getElementById('kpi-total').innerText = Number(json.kpi.total).toLocaleString('id-ID');
                    document.getElementById('kpi-qty').innerText = Number(json.kpi.total_qty).toLocaleString('id-ID', {minimumFractionDigits: 2});
                    document.getElementById('kpi-done').innerText = Number(json.kpi.transferred).toLocaleString('id-ID');
                    document.getElementById('kpi-pending').innerText = Number(json.kpi.pending).toLocaleString('id-ID');

                    renderTable(json.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: red;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: red;">Error: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('table-body');
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; padding: 20px;">Tidak ada data BC 2.7 OUT ditemukan.</td></tr>';
                return;
            }

            tbody.innerHTML = data.map((r, i) => {
                const isTransferred = parseInt(r.is_transferred) > 0;
                const badge = isTransferred 
                    ? '<span class="badge badge-transferred"><i class="fa-solid fa-check"></i> Sudah IN</span>'
                    : '<span class="badge badge-pending"><i class="fa-solid fa-clock"></i> Belum IN</span>';

                return `
                    <tr>
                        <td style="text-align: center; color: var(--text-secondary);">${i + 1}</td>
                        <td style="font-family: monospace; font-weight: 600; color: var(--primary);">${r.NOMOR_AJU}</td>
                        <td>${r.NOMOR_DAFTAR || '-'} <span style="color:var(--text-secondary); font-size:10px;">(${r.TANGGAL_DAFTAR || '-'})</span></td>
                        <td><span style="font-weight: 500;">${r.PENERIMA || '-'}</span></td>
                        <td style="font-weight: 600;">${r.KODE_BARANG}</td>
                        <td>${r.URAIAN || '-'}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.QTY || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td>${r.UNIT || '-'}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.HARGA_PENYERAHAN || r.NILAI || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td>${r.SJ || r.NO_BUKTI || '-'}</td>
                        <td>${r.TANGGAL_PROSES || '-'}</td>
                        <td style="text-align: center;">${badge}</td>
                        <td style="text-align: center;">
                            ${!isTransferred ? `
                                <button class="btn-primary" style="padding: 2px 6px;" title="Transfer BC27 OUT -> IN" onclick="transferIn('${r.NOMOR_AJU}')">
                                    <i class="fa-solid fa-arrow-right-arrow-left"></i> Transfer IN
                                </button>
                            ` : `
                                <button class="btn-default" style="padding: 2px 6px; color: var(--danger);" title="Batalkan Pemasukan (Unpost)" onclick="unpostIn('${r.NOMOR_AJU}')">
                                    <i class="fa-solid fa-rotate-left"></i> Unpost
                                </button>
                            `}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function transferIn(aju) {
            if (!confirm(`Konfirmasi Transfer: Posting dokumen BC 2.7 AJU ${aju} dari OUT ke Pemasukan (IN)?`)) return;
            const fd = new FormData();
            fd.append('action', 'transfer_in');
            fd.append('aju', aju);

            try {
                const res = await fetch('transfer_bc27.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    alert(json.message);
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        async function unpostIn(aju) {
            if (!confirm(`Konfirmasi Unpost: Batalkan pencatatan Pemasukan (IN) untuk AJU ${aju}?`)) return;
            const fd = new FormData();
            fd.append('action', 'unpost_in');
            fd.append('aju', aju);

            try {
                const res = await fetch('transfer_bc27.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    alert(json.message);
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        function exportCsv() {
            if (!currentData.length) return alert('Tidak ada data.');
            let csv = "Nomor AJU,No Daftar,Tgl Daftar,Penerima,Kode Barang,Nama Barang,Qty,Satuan,Nilai Penyerahan,No Bukti,Tgl Proses,Status IN\n";
            currentData.forEach(r => {
                csv += `"${r.NOMOR_AJU}","${r.NOMOR_DAFTAR || ''}","${r.TANGGAL_DAFTAR || ''}","${r.PENERIMA || ''}","${r.KODE_BARANG}","${r.URAIAN || ''}",${r.QTY},"${r.UNIT || ''}",${r.HARGA_PENYERAHAN || r.NILAI || 0},"${r.SJ || r.NO_BUKTI || ''}","${r.TANGGAL_PROSES || ''}","${r.is_transferred > 0 ? 'SUDAH IN' : 'BELUM IN'}"\n`;
            });
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `transfer_bc27_${new Date().toISOString().slice(0,10)}.csv`;
            a.click();
        }

        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>
</html>

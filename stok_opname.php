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
$action = $_REQUEST['action'] ?? '';

// 1. AJAX: LIST DATA STOK OPNAME
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $tgl    = trim($_GET['tgl'] ?? '');
    $klas   = trim($_GET['klas'] ?? '');
    $diff   = trim($_GET['diff'] ?? ''); // 'selisih', 'sesuai', ''
    $search = trim($_GET['q'] ?? '');

    $where = [];
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

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT 
        a.tgl_so, a.kode_barang, b.URAIAN, b.KLAS, a.unit, a.stok, a.sesuai, 
        a.aktual, a.selisih, a.note, a.set_by, a.tipe, a.no_spp
    FROM bc_so a
    LEFT JOIN bc_material b ON a.kode_barang = b.KODE_BARANG
    $whereSql
    ORDER BY a.tgl_so DESC, b.KLAS ASC, a.kode_barang ASC
    LIMIT 300";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // Ambil daftar tanggal SO yang tersedia untuk dropdown
    $datesRes = $koneksi->query("SELECT DISTINCT tgl_so FROM bc_so ORDER BY tgl_so DESC LIMIT 30");
    $dates = [];
    if ($datesRes) {
        while ($r = $datesRes->fetch_assoc()) {
            $dates[] = $r['tgl_so'];
        }
    }

    // KPI Counters
    $kpiTotal = $koneksi->query("SELECT COUNT(1) FROM bc_so" . (!empty($tgl) ? " WHERE tgl_so = '$tgl'" : ""))->fetch_row()[0] ?? 0;
    $kpiSesuai = $koneksi->query("SELECT COUNT(1) FROM bc_so WHERE selisih = 0" . (!empty($tgl) ? " AND tgl_so = '$tgl'" : ""))->fetch_row()[0] ?? 0;
    $kpiSelisih = $koneksi->query("SELECT COUNT(1) FROM bc_so WHERE selisih <> 0" . (!empty($tgl) ? " AND tgl_so = '$tgl'" : ""))->fetch_row()[0] ?? 0;
    $kpiQtySelisih = $koneksi->query("SELECT COALESCE(SUM(ABS(selisih)), 0) FROM bc_so" . (!empty($tgl) ? " WHERE tgl_so = '$tgl'" : ""))->fetch_row()[0] ?? 0;

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

// 2. AJAX: SIMPAN / UPDATE ITEM STOK OPNAME
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

    // Cek apakah sudah ada di tanggal SO tersebut
    $cek = $koneksi->query("SELECT COUNT(1) FROM bc_so WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'")->fetch_row()[0] ?? 0;
    if ($cek > 0) {
        $sql = "UPDATE bc_so SET 
            unit = '$escUnit', stok = $stok, aktual = $aktual, selisih = $selisih, 
            sesuai = '$sesuai', note = '$escNote', set_by = '$escUser'
            WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'";
    } else {
        $sql = "INSERT INTO bc_so 
            (tgl_so, kode_barang, unit, stok, sesuai, aktual, selisih, note, set_by)
            VALUES 
            ('$escTgl', '$escKode', '$escUnit', $stok, '$sesuai', $aktual, $selisih, '$escNote', '$escUser')";
    }

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Data Stok Opname berhasil disimpan.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan: ' . $koneksi->error]);
    }
    exit;
}

// 3. AJAX: HAPUS RECORD STOK OPNAME
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $tgl_so = trim($_POST['tgl_so'] ?? '');
    $kode   = trim($_POST['kode_barang'] ?? '');

    $escTgl  = $koneksi->real_escape_string($tgl_so);
    $escKode = $koneksi->real_escape_string($kode);

    $del = $koneksi->query("DELETE FROM bc_so WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Record Stok Opname berhasil dihapus.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 4. AJAX: IMPORT DATA DARI EXCEL (BATCH UPSERT STOK OPNAME)
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

        $chk = $koneksi->query("SELECT 1 FROM bc_so WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $koneksi->query("UPDATE bc_so SET unit = '$escUnit', stok = $stok, aktual = $aktual, selisih = $selisih, sesuai = '$sesuai', note = '$escNote', set_by = '$escUser' WHERE tgl_so = '$escTgl' AND kode_barang = '$escKode'");
            $updated++;
        } else {
            $koneksi->query("INSERT INTO bc_so (tgl_so, kode_barang, unit, stok, sesuai, aktual, selisih, note, set_by) VALUES ('$escTgl', '$escKode', '$escUnit', $stok, '$sesuai', $aktual, $selisih, '$escNote', '$escUser')");
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

// 5. QUERY KLASIFIKASI DARI bc_tipe (WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx)
$klasList = [];
$qKlas = $koneksi->query("SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx");
if ($qKlas) {
    while ($rk = $qKlas->fetch_assoc()) {
        $klasList[] = $rk;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Stok Opname - Rekonsiliasi Fisik vs IT Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
        .badge-match { background: rgba(82, 196, 26, 0.12); color: var(--success); }
        .badge-diff { background: rgba(245, 34, 45, 0.12); color: var(--danger); }
        
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
            max-width: 480px;
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
        .modal-body { padding: 8px 12px; font-size: 10px; }
        .modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
        }
        .form-group { margin-bottom: 6px; }
        .form-group label { display: block; margin-bottom: 2px; color: var(--text-secondary); font-size: 9px; font-weight: 600; text-transform: uppercase; }
        .form-group input, .form-group select { width: 100%; }
    </style>
</head>
<body>

    <!-- KPI Cards -->
    <div class="kpi-container">
        <div class="kpi-card">
            <span class="kpi-title">Total Item Opname</span>
            <span class="kpi-value" id="kpi-total">0</span>
            <i class="fa-solid fa-clipboard-list kpi-icon" style="color: #1890ff;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Stok Sesuai (Match)</span>
            <span class="kpi-value" id="kpi-sesuai">0</span>
            <i class="fa-solid fa-square-check kpi-icon" style="color: #52c41a;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Ada Selisih (Varian)</span>
            <span class="kpi-value" id="kpi-selisih" style="color: var(--danger);">0</span>
            <i class="fa-solid fa-triangle-exclamation kpi-icon" style="color: #f5222d;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Total Varian Qty (Absolut)</span>
            <span class="kpi-value" id="kpi-qty-selisih">0</span>
            <i class="fa-solid fa-chart-pie kpi-icon" style="color: #faad14;"></i>
        </div>
    </div>

    <!-- Toolbar Filters -->
    <div class="toolbar-card">
        <div class="toolbar-left">
            <span style="font-weight:600; color:var(--text-secondary);"><i class="fa-solid fa-calendar-day"></i> Tgl SO:</span>
            <select id="filter-tgl" style="min-width: 130px;">
                <option value="">Semua Tanggal SO</option>
            </select>
            <select id="filter-klas">
                <option value="">Semua Klasifikasi</option>
                <?php foreach ($klasList as $k): ?>
                    <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-diff">
                <option value="">Semua Kondisi</option>
                <option value="selisih">Hanya yang Selisih</option>
                <option value="sesuai">Hanya yang Sesuai</option>
            </select>
            <input type="text" id="filter-search" placeholder="Cari kode/nama barang..." style="width: 180px;">
            <button class="btn-default" onclick="loadData()"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        </div>
        <div class="toolbar-right">
            <button class="btn-primary" onclick="openNewModal()"><i class="fa-solid fa-plus"></i> Input Hasil Opname</button>
            <button class="btn-primary" style="background:var(--success); border-color:var(--success);" onclick="openImportModal()"><i class="fa-solid fa-file-excel"></i> Import Excel</button>
            <button class="btn-default" onclick="exportExcel()"><i class="fa-solid fa-file-arrow-down"></i> Ekspor Excel</button>
        </div>
    </div>

    <!-- Main Table -->
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
                <tr><td colspan="13" style="text-align: center; padding: 20px;">Memuat data...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Modal Form -->
    <div class="modal" id="modal-form">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size: 13px; font-weight: 700;"><i class="fa-solid fa-pen-to-square"></i> Input / Edit Stok Opname</h3>
                <span style="cursor: pointer; font-size: 16px;" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Tanggal Pelaksanaan Stok Opname</label>
                    <input type="date" id="form-tgl" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Kode Barang</label>
                    <input type="text" id="form-kode" placeholder="Ketik kode barang...">
                </div>
                <div class="form-group">
                    <label>Satuan</label>
                    <input type="text" id="form-unit" value="PCE">
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
                <div class="form-group">
                    <label>Selisih: <strong id="lbl-diff" style="color: var(--primary);">0.00</strong></label>
                </div>
                <div class="form-group">
                    <label>Catatan / Keterangan Selisih</label>
                    <input type="text" id="form-note" placeholder="Misal: Selisih timbangan, susut, dll">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-default" onclick="closeModal()">Batal</button>
                <button class="btn-primary" onclick="submitForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Record</button>
            </div>
        </div>
    </div>

    <!-- Modal Import Excel -->
    <div class="modal" id="modal-import">
        <div class="modal-content" style="max-width: 750px;">
            <div class="modal-header">
                <h3 style="font-size: 13px; font-weight: 700;"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Import Hasil Stok Opname dari Excel</h3>
                <span style="cursor: pointer; font-size: 16px;" onclick="closeImportModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="background:rgba(24,144,255,0.08); border:1px solid #91d5ff; border-radius:6px; padding:10px 14px; font-size:12px; margin-bottom: 12px;">
                    <strong>Petunjuk Import Stok Opname:</strong>
                    <ul style="margin:4px 0 0 16px; padding:0;">
                        <li>Format kolom: <code>tgl_so</code>, <code>kode_barang</code>, <code>unit</code>, <code>stok</code>, <code>aktual</code>, <code>note</code></li>
                        <li>Format tanggal: YYYY-MM-DD (contoh: <?= date('Y-m-d') ?>).</li>
                        <li>Sistem akan otomatis menghitung selisih (<code>aktual - stok</code>) dan status kesesuaian.</li>
                    </ul>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px;">
                    <button type="button" class="btn-default" onclick="downloadTemplate()">
                        <i class="fa-solid fa-file-excel"></i> Download Template Format (.xlsx)
                    </button>
                    <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelUpload(event)">
                    <button type="button" class="btn-primary" onclick="document.getElementById('excelFileInput').click()">
                        <i class="fa-solid fa-folder-open"></i> Pilih File Excel...
                    </button>
                </div>

                <div id="dropZone" style="border: 2px dashed var(--border); border-radius: 8px; padding: 25px; text-align: center; cursor: pointer; background: rgba(0,0,0,0.02); margin-bottom: 12px;" onclick="document.getElementById('excelFileInput').click()">
                    <div style="font-size: 28px; margin-bottom: 6px;">📥</div>
                    <div style="font-weight: 600; font-size: 13px;">Tarik dan lepaskan file Excel di sini atau Klik untuk memilih file</div>
                    <div style="font-size: 11px; color: var(--text-secondary); margin-top: 4px;">Mendukung format .xlsx, .xls, atau .csv</div>
                </div>

                <!-- Preview Table -->
                <div id="importPreviewContainer" style="display:none; max-height: 220px; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px;">
                    <div style="padding: 6px 12px; background: var(--bg); font-weight: 600; font-size: 11.5px; border-bottom: 1px solid var(--border); display:flex; justify-content:space-between;">
                        <span id="previewCountText">Preview Data: 0 baris</span>
                        <span style="color:var(--text-secondary); font-size:11px;">Maksimal 10 baris pertama</span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal SO</th>
                                <th>Kode Barang</th>
                                <th>Satuan</th>
                                <th style="text-align:right;">Stok Buku</th>
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
                <button type="button" class="btn-primary" id="btnExecuteImport" style="display:none; background:var(--success); border-color:var(--success);" onclick="executeImport()">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Mulai Impor Data
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentData = [];

        function calcDiff() {
            const stok = parseFloat(document.getElementById('form-stok').value) || 0;
            const aktual = parseFloat(document.getElementById('form-aktual').value) || 0;
            const diff = (aktual - stok).toFixed(2);
            const lbl = document.getElementById('lbl-diff');
            lbl.innerText = (diff > 0 ? '+' : '') + diff;
            lbl.style.color = (diff == 0) ? 'var(--success)' : (diff < 0 ? 'var(--danger)' : 'var(--warning)');
        }

        async function loadData() {
            const tgl = document.getElementById('filter-tgl').value;
            const klas = document.getElementById('filter-klas').value;
            const diff = document.getElementById('filter-diff').value;
            const q = document.getElementById('filter-search').value;

            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; padding: 20px;">Memuat data...</td></tr>';

            try {
                const res = await fetch(`stok_opname.php?action=list&tgl=${encodeURIComponent(tgl)}&klas=${encodeURIComponent(klas)}&diff=${diff}&q=${encodeURIComponent(q)}`);
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
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: red;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; color: red;">Error: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('table-body');
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="13" style="text-align: center; padding: 20px;">Tidak ada record Stok Opname ditemukan.</td></tr>';
                return;
            }

            tbody.innerHTML = data.map((r, i) => {
                const selisih = parseFloat(r.selisih) || 0;
                let badge = '<span class="badge badge-match"><i class="fa-solid fa-check"></i> Sesuai</span>';
                let diffColor = 'var(--text)';
                if (selisih !== 0) {
                    badge = `<span class="badge badge-diff"><i class="fa-solid fa-xmark"></i> Selisih</span>`;
                    diffColor = selisih < 0 ? 'var(--danger)' : 'var(--warning)';
                }

                return `
                    <tr>
                        <td style="text-align: center; color: var(--text-secondary);">${i + 1}</td>
                        <td>${r.tgl_so}</td>
                        <td style="font-weight: 600; color: var(--primary);">${r.kode_barang}</td>
                        <td>${r.URAIAN || '-'}</td>
                        <td>${r.KLAS || '-'}</td>
                        <td>${r.unit || '-'}</td>
                        <td style="text-align: right; font-weight: 500;">${Number(r.stok || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.aktual || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align: right; font-weight: 700; color: ${diffColor};">
                            ${selisih > 0 ? '+' : ''}${Number(selisih).toLocaleString('id-ID', {minimumFractionDigits: 2})}
                        </td>
                        <td style="text-align: center;">${badge}</td>
                        <td>${r.note || '-'}</td>
                        <td>${r.set_by || '-'}</td>
                        <td style="text-align: center;">
                            <button class="btn-default" style="padding: 2px 6px;" title="Edit Record" onclick="editRow('${r.tgl_so}', '${r.kode_barang}', '${r.unit}', ${r.stok}, ${r.aktual}, '${(r.note || '').replace(/'/g, "\\'")}')">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <button class="btn-default" style="padding: 2px 6px; color: var(--danger);" title="Hapus Record" onclick="deleteRow('${r.tgl_so}', '${r.kode_barang}')">
                                <i class="fa-solid fa-trash"></i>
                            </button>
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
            calcDiff();
            document.getElementById('modal-form').style.display = 'flex';
        }

        function editRow(tgl, kode, unit, stok, aktual, note) {
            document.getElementById('form-tgl').value = tgl;
            document.getElementById('form-kode').value = kode;
            document.getElementById('form-unit').value = unit || 'PCE';
            document.getElementById('form-stok').value = stok;
            document.getElementById('form-aktual').value = aktual;
            document.getElementById('form-note').value = note;
            calcDiff();
            document.getElementById('modal-form').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('modal-form').style.display = 'none';
        }

        async function submitForm() {
            const tgl    = document.getElementById('form-tgl').value;
            const kode   = document.getElementById('form-kode').value.trim();
            const unit   = document.getElementById('form-unit').value.trim();
            const stok   = document.getElementById('form-stok').value;
            const aktual = document.getElementById('form-aktual').value;
            const note   = document.getElementById('form-note').value.trim();

            if (!kode) return alert('Kode barang harus diisi.');

            const fd = new FormData();
            fd.append('action', 'save');
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
                    alert(json.message);
                    closeModal();
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        async function deleteRow(tgl, kode) {
            if (!confirm(`Hapus record Stok Opname untuk item ${kode} tanggal ${tgl}?`)) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('tgl_so', tgl);
            fd.append('kode_barang', kode);

            try {
                const res = await fetch('stok_opname.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    alert(json.message);
                    loadData();
                } else {
                    alert(json.message);
                }
            } catch (e) { alert(e.message); }
        }

        let importedRowsData = [];

        function openImportModal() {
            importedRowsData = [];
            document.getElementById('excelFileInput').value = '';
            document.getElementById('importPreviewContainer').style.display = 'none';
            document.getElementById('btnExecuteImport').style.display = 'none';
            document.getElementById('modal-import').style.display = 'flex';
        }

        function closeImportModal() {
            document.getElementById('modal-import').style.display = 'none';
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
                        Swal.fire('Format Salah', 'File Excel kosong atau sheet pertama tidak berisi data.', 'warning');
                        return;
                    }

                    importedRowsData = jsonData;
                    renderImportPreview(jsonData);
                } catch (err) {
                    Swal.fire('Error Baca File', 'Gagal memproses file: ' + err.message, 'error');
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
                    <td><strong>${escapeHtml(kd)}</strong></td>
                    <td>${escapeHtml(un)}</td>
                    <td style="text-align:right;">${Number(st).toLocaleString('id-ID')}</td>
                    <td style="text-align:right; font-weight:600;">${Number(ak).toLocaleString('id-ID')}</td>
                    <td>${escapeHtml(nt)}</td>
                `;
                tbody.appendChild(tr);
            }

            document.getElementById('importPreviewContainer').style.display = 'block';
            document.getElementById('btnExecuteImport').style.display = 'inline-flex';
        }

        async function executeImport() {
            if (!importedRowsData || importedRowsData.length === 0) {
                Swal.fire('Error', 'Tidak ada data untuk diimpor.', 'warning');
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
            fd.append('rows', JSON.stringify(importedRowsData));

            try {
                const res = await fetch('stok_opname.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    closeImportModal();
                    loadData();
                } else {
                    Swal.fire('Gagal Impor', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        function exportExcel() {
            if (!currentData || currentData.length === 0) {
                Swal.fire('Informasi', 'Tidak ada data untuk diekspor.', 'info');
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

        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>
</html>

<?php
/**
 * CEISA 4.0 - Admin Inventory: Set Stok Awal Tahun (stok_awal_tahun.php)
 * Mengadopsi w_stok_awal_tahun.srw & d_stockopname.srd
 * Pengaturan Saldo Stok Awal Tahun IT Inventory (bc_so0 & bc_material & bc_tipe)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'ADMIN');
$action = $_REQUEST['action'] ?? '';
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// Ambil klasifikasi tipe dari database sesuai aturan
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
// 1. AJAX API: LIST STOK AWAL TAHUN
// -------------------------------------------------------------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $tahun  = trim($_GET['tahun'] ?? date('Y'));
    $klas   = trim($_GET['klas'] ?? '');
    $search = trim($_GET['q'] ?? '');

    $where = [];
    if (!empty($klas) && $klas !== '%') {
        $escKlas = $koneksi->real_escape_string($klas);
        $where[] = "m.KLAS = '$escKlas'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(m.KODE_BARANG LIKE '%$s%' OR m.URAIAN LIKE '%$s%' OR m.KLAS LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? ' AND ' . implode(' AND ', $where) : '';

    $escTahun = $koneksi->real_escape_string($tahun);
    $sql = "SELECT 
                '$escTahun' AS TAHUN,
                m.KODE_BARANG,
                m.URAIAN,
                m.KLAS,
                m.KODE_SATUAN AS UNIT,
                COALESCE(s.stok_awal, 0) AS STOK_AWAL,
                s.set_by AS SET_BY,
                t.idx AS IDX
            FROM bc_material m
            LEFT JOIN bc_tipe t ON m.KLAS = t.tipe
            LEFT JOIN bc_so0 s ON m.KODE_BARANG = s.kode_barang AND s.tahun = '$escTahun'
            WHERE (t.grp IS NULL OR NOT t.grp IN ('FA', 'Consumables')) $whereSql
            ORDER BY COALESCE(t.idx, 999) ASC, m.KODE_BARANG ASC
            LIMIT 1000";

    $res = $koneksi->query($sql);
    $data = [];
    $totalQty = 0;
    $countHasStock = 0;

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
            $qty = (float)$r['STOK_AWAL'];
            $totalQty += $qty;
            if ($qty != 0) $countHasStock++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'tahun'  => $tahun,
        'data'   => $data,
        'count'  => count($data),
        'kpi'    => [
            'total_items'    => count($data),
            'items_with_so'  => $countHasStock,
            'total_stok'     => $totalQty
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: SIMPAN / UPDATE STOK AWAL SATUAN
// -------------------------------------------------------------
if ($action === 'save_item') {
    header('Content-Type: application/json; charset=utf-8');
    $tahun      = trim($_POST['tahun'] ?? date('Y'));
    $kodeBarang = trim($_POST['kode_barang'] ?? '');
    $unit       = trim($_POST['unit'] ?? '');
    $stokAwal   = (float)($_POST['stok_awal'] ?? 0);

    if (empty($tahun) || empty($kodeBarang)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tahun dan Kode Barang wajib diisi.']);
        exit;
    }

    if (empty($unit)) {
        $qU = $koneksi->query("SELECT KODE_SATUAN FROM bc_material WHERE KODE_BARANG = '" . $koneksi->real_escape_string($kodeBarang) . "' LIMIT 1");
        $unit = ($qU && $rU = $qU->fetch_assoc()) ? $rU['KODE_SATUAN'] : 'PCE';
    }

    $stmt = $koneksi->prepare("INSERT INTO bc_so0 (tahun, kode_barang, unit, stok_awal, set_by) 
                               VALUES (?, ?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE unit = VALUES(unit), stok_awal = VALUES(stok_awal), set_by = VALUES(set_by)");
    if ($stmt) {
        $stmt->bind_param("sssds", $tahun, $kodeBarang, $unit, $stokAwal, $sessionUser);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'OK', 'message' => "Stok awal $kodeBarang tahun $tahun berhasil disimpan: $stokAwal $unit"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => "Gagal menyimpan: " . $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => "Database error: " . $koneksi->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: HITUNG OTOMATIS DARI SALDO TAHUN SEBELUMNYA
// -------------------------------------------------------------
if ($action === 'auto_generate') {
    header('Content-Type: application/json; charset=utf-8');
    $targetTahun = (int)($_POST['tahun'] ?? date('Y'));
    $prevTahun = $targetTahun - 1;
    $prevDate = $prevTahun . '-12-31';

    if ($targetTahun < 2000) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tahun tidak valid.']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        // Hapus saldo tahun terpilih terlebih dahulu
        $koneksi->query("DELETE FROM bc_so0 WHERE tahun = '$targetTahun'");

        // Query perhitungan saldo akumulasi per 31 Des tahun lalu:
        // Saldo = Stok Awal tahun sebelumnya (bc_so0) + Total Masuk - Total Keluar + Penyesuaian
        $sqlGen = "INSERT INTO bc_so0 (tahun, kode_barang, unit, stok_awal, set_by)
                   SELECT 
                       '$targetTahun' AS tahun,
                       m.KODE_BARANG,
                       m.KODE_SATUAN AS unit,
                       COALESCE(sub.saldo_akhir, 0) AS stok_awal,
                       CONCAT('AUTO-', '$sessionUser') AS set_by
                   FROM bc_material m
                   LEFT JOIN bc_tipe t ON m.KLAS = t.tipe
                   JOIN (
                       SELECT 
                           kode_barang, 
                           SUM(qty_netto) AS saldo_akhir
                       FROM (
                           -- Stok Awal Tahun Sebelumnya
                           SELECT kode_barang, stok_awal AS qty_netto
                           FROM bc_so0 
                           WHERE tahun = '$prevTahun'
                           
                           UNION ALL
                           
                           -- Pemasukan & Pengeluaran bc_1ab2 s.d 31 Des tahun lalu
                           SELECT kode_barang, 
                                  CASE WHEN ab = 'A' THEN QTY_AKTUAL ELSE -QTY_AKTUAL END AS qty_netto
                           FROM bc_1ab2 
                           WHERE TANGGAL_DAFTAR <= '$prevDate'
                             AND (tahun_buku IS NULL OR tahun_buku = '$prevTahun' OR TANGGAL_DAFTAR LIKE '$prevTahun%')
                       ) mutasi
                       GROUP BY kode_barang
                       HAVING SUM(qty_netto) > 0
                   ) sub ON m.KODE_BARANG = sub.kode_barang
                   WHERE (t.grp IS NULL OR NOT t.grp IN ('FA', 'Consumables'))";

        if (!$koneksi->query($sqlGen)) {
            throw new Exception("Gagal menghitung saldo otomatis: " . $koneksi->error);
        }

        $inserted = $koneksi->affected_rows;
        $koneksi->commit();

        echo json_encode([
            'status' => 'OK', 
            'message' => "Berhasil menghasilkan $inserted saldo awal untuk tahun $targetTahun dari kalkulasi pergerakan tahun $prevTahun!"
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 4. AJAX API: IMPORT DARI EXCEL / CSV DATA BATCH
// -------------------------------------------------------------
if ($action === 'import_batch') {
    header('Content-Type: application/json; charset=utf-8');
    $tahun = trim($_POST['tahun'] ?? date('Y'));
    $itemsJson = $_POST['items'] ?? '[]';
    $items = json_decode($itemsJson, true);

    if (empty($tahun) || empty($items) || !is_array($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data import kosong atau format salah.']);
        exit;
    }

    $koneksi->begin_transaction();
    $successCount = 0;
    try {
        $stmt = $koneksi->prepare("INSERT INTO bc_so0 (tahun, kode_barang, unit, stok_awal, set_by) 
                                   VALUES (?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE unit = VALUES(unit), stok_awal = VALUES(stok_awal), set_by = VALUES(set_by)");
        
        foreach ($items as $it) {
            $kb = trim($it['kode_barang'] ?? ($it['KODE_BARANG'] ?? ''));
            $stok = (float)($it['stok_awal'] ?? ($it['STOK_AWAL'] ?? ($it['jumlah'] ?? 0)));
            $unit = trim($it['unit'] ?? ($it['UNIT'] ?? ($it['satuan'] ?? '')));

            if (empty($kb)) continue;

            if (empty($unit)) {
                $qU = $koneksi->query("SELECT KODE_SATUAN FROM bc_material WHERE KODE_BARANG = '" . $koneksi->real_escape_string($kb) . "' LIMIT 1");
                $unit = ($qU && $rU = $qU->fetch_assoc()) ? $rU['KODE_SATUAN'] : 'PCE';
            }

            $stmt->bind_param("sssds", $tahun, $kb, $unit, $stok, $sessionUser);
            if ($stmt->execute()) {
                $successCount++;
            }
        }
        $stmt->close();
        $koneksi->commit();

        echo json_encode(['status' => 'OK', 'message' => "Berhasil mengimpor $successCount item stok awal untuk tahun $tahun!"]);
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
    <title>Set Stok Awal Tahun - Admin IT Inventory</title>
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

        .editable-input {
            width: 85px;
            padding: 1px 5px;
            height: 20px;
            font-size: 10px;
            border: 1px solid var(--border-input);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            text-align: right;
            font-family: inherit;
        }
        .editable-input:focus {
            border-color: var(--primary);
            background: #fffbe6;
            outline: none;
        }
    </style>
</head>
<body>

<div class="ant-card">
    <div class="ant-card-head">
        <div class="ant-card-head-title">
            <span>📖</span> Set Stok Awal Tahun IT Inventory (bc_so0)
        </div>
        <div style="display:flex; gap:8px;">
            <button type="button" class="ant-btn ant-btn-warning" onclick="autoGenerateStock()" title="Kalkulasi otomatis dari mutasi tahun lalu">
                ⚡ Generate Saldo dari Tahun Lalu
            </button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="triggerImportExcel()" title="Import Stok Awal dari File Excel">
                📤 Import Excel
            </button>
            <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelImport(event)">
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
            <label>Tahun Buku Saldo Awal</label>
            <select id="filterTahun" class="filter-control" style="width: 100px;" onchange="loadData()">
                <?php 
                $curY = (int)date('Y');
                for ($y = $curY + 1; $y >= 2020; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $curY ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-col">
            <label>Klasifikasi Barang</label>
            <select id="filterKlas" class="filter-control" style="min-width: 180px;" onchange="loadData()">
                <option value="">Semua Klasifikasi (bc_tipe)</option>
                <?php foreach ($klasList as $k): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-col">
            <label>Kata Kunci Cari</label>
            <input type="text" id="filterSearch" class="filter-control" placeholder="Kode / Nama Barang..." style="width: 190px;" onkeyup="if(event.key==='Enter') loadData()">
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
            <span style="color:var(--text-muted);">Total Master Barang:</span>
            <span class="kpi-val" id="kpiTotal">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#52c41a;">Item Memiliki Saldo Awal:</span>
            <span class="kpi-val" id="kpiWithStock" style="color:#52c41a;">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#1890ff;">Total Kuantum Stok Awal:</span>
            <span class="kpi-val" id="kpiQty" style="color:#1890ff;">0</span>
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
            💡 Ubah nilai Stok Awal langsung pada tabel lalu klik icon centang (💾) atau tekan Enter untuk menyimpan.
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="ant-table-wrapper">
        <div class="table-scroll">
            <table class="ant-table" id="mainDataTable">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>Tahun</th>
                        <th>Kode Barang</th>
                        <th>Uraian / Nama Barang</th>
                        <th>Klasifikasi</th>
                        <th>Satuan</th>
                        <th style="text-align:right; width:130px;">Stok Awal</th>
                        <th style="text-align:center; width:60px;">Aksi</th>
                        <th>Set By (User)</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td colspan="9" style="text-align:center; padding:30px; color:var(--text-muted);">
                            Memuat data stok awal tahun...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let currentData = [];

function loadData() {
    const tahun = document.getElementById('filterTahun').value;
    const klas = document.getElementById('filterKlas').value;
    const q = document.getElementById('filterSearch').value;

    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:24px; color:var(--text-muted);">Sedang mengambil data saldo awal...</td></tr>';

    fetch(`stok_awal_tahun.php?action=list&tahun=${encodeURIComponent(tahun)}&klas=${encodeURIComponent(klas)}&q=${encodeURIComponent(q)}`)
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK') {
                currentData = res.data || [];
                renderTable(currentData);
                document.getElementById('kpiTotal').innerText = (res.kpi.total_items || 0).toLocaleString();
                document.getElementById('kpiWithStock').innerText = (res.kpi.items_with_so || 0).toLocaleString();
                document.getElementById('kpiQty').innerText = parseFloat(res.kpi.total_stok || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 4});
                document.getElementById('rowCountBadge').innerText = currentData.length + ' Baris Data';
            } else {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red;">${res.message || 'Gagal memuat data.'}</td></tr>`;
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red;">Koneksi error: ${err}</td></tr>`;
        });
}

function renderTable(data) {
    const tbody = document.getElementById('tableBody');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:24px; color:var(--text-muted);">📭 Tidak ada data master barang yang cocok.</td></tr>';
        return;
    }

    let html = '';
    data.forEach((r, idx) => {
        const val = parseFloat(r.STOK_AWAL || 0);
        html += `<tr>
            <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
            <td style="font-weight:600; color:var(--text-muted);">${escapeHtml(r.TAHUN)}</td>
            <td style="font-family:monospace; font-weight:600; color:var(--primary);">${escapeHtml(r.KODE_BARANG || '-')}</td>
            <td>${escapeHtml(r.URAIAN || '-')}</td>
            <td><span class="ant-badge ant-badge-gold">${escapeHtml(r.KLAS || '-')}</span></td>
            <td>${escapeHtml(r.UNIT || '-')}</td>
            <td style="text-align:right;">
                <input type="number" step="any" class="editable-input" id="input_stok_${idx}" value="${val}" onkeydown="if(event.key==='Enter') saveSingleItem(${idx})">
            </td>
            <td style="text-align:center;">
                <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="saveSingleItem(${idx})" title="Simpan perubahan stok item ini">
                    💾
                </button>
            </td>
            <td style="color:var(--text-muted); font-size:10px;">${escapeHtml(r.SET_BY || '-')}</td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function saveSingleItem(idx) {
    const item = currentData[idx];
    if (!item) return;

    const input = document.getElementById(`input_stok_${idx}`);
    const newQty = parseFloat(input.value || 0);
    const tahun = document.getElementById('filterTahun').value;

    const fd = new FormData();
    fd.append('tahun', tahun);
    fd.append('kode_barang', item.KODE_BARANG);
    fd.append('unit', item.UNIT);
    fd.append('stok_awal', newQty);

    fetch('stok_awal_tahun.php?action=save_item', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(res => {
        if (res.status === 'OK') {
            item.STOK_AWAL = newQty;
            item.SET_BY = '<?= htmlspecialchars($sessionUser) ?>';
            showSuccess(res.message, 'Tersimpan');
        } else {
            showError(res.message || 'Gagal menyimpan.');
        }
    })
    .catch(err => {
        showError('Koneksi bermasalah: ' + err);
    });
}

function autoGenerateStock() {
    const tahun = document.getElementById('filterTahun').value;
    const prevTahun = parseInt(tahun) - 1;

    Swal.fire({
        title: `Generate Saldo Awal ${tahun}?`,
        html: `Sistem akan menghitung akumulasi saldo akhir pergerakan s.d <b>31 Desember ${prevTahun}</b> dan mengisikannya ke tabel <b>bc_so0</b> tahun <b>${tahun}</b>.<br><br><span style="color:#ff4d4f;">Perhatian: Saldo awal tahun ${tahun} yang ada saat ini akan ditimpa/diperbarui.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#faad14',
        cancelButtonColor: '#8c8c8c',
        confirmButtonText: 'Ya, Kalkulasi & Generate',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('tahun', tahun);

            fetch('stok_awal_tahun.php?action=auto_generate', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'OK') {
                    showSuccess(res.message, 'Selesai Kalkulasi');
                    loadData();
                } else {
                    showError(res.message || 'Gagal generate saldo awal.');
                }
            })
            .catch(err => {
                showError('Koneksi bermasalah: ' + err);
            });
        }
    });
}

function triggerImportExcel() {
    document.getElementById('excelFileInput').click();
}

function handleExcelImport(e) {
    const file = e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(evt) {
        try {
            const data = new Uint8Array(evt.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[sheetName];
            const json = XLSX.utils.sheet_to_json(worksheet);

            if (!json || json.length === 0) {
                showError('File Excel tidak berisi data yang terbaca.');
                return;
            }

            const tahun = document.getElementById('filterTahun').value;
            Swal.fire({
                title: 'Konfirmasi Import Excel',
                html: `Ditemukan <b>${json.length}</b> baris data pada file Excel.<br>Apakah Anda ingin mengimpor data ini sebagai Saldo Awal tahun <b>${tahun}</b>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: 'Ya, Impor Data',
                cancelButtonText: 'Batal'
            }).then((res) => {
                if (res.isConfirmed) {
                    const fd = new FormData();
                    fd.append('tahun', tahun);
                    fd.append('items', JSON.stringify(json));

                    fetch('stok_awal_tahun.php?action=import_batch', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(r => {
                        if (r.status === 'OK') {
                            showSuccess(r.message, 'Import Berhasil');
                            loadData();
                        } else {
                            showError(r.message || 'Gagal mengimpor data.');
                        }
                    })
                    .catch(err => {
                        showError('Error saat upload: ' + err);
                    });
                }
            });
        } catch (err) {
            showError('Gagal membaca file Excel: ' + err);
        }
    };
    reader.readAsArrayBuffer(file);
    e.target.value = '';
}

function resetFilter() {
    document.getElementById('filterKlas').value = '';
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
        let cellA = (a.cells[colIndex] ? a.cells[colIndex].innerText : '').trim();
        let cellB = (b.cells[colIndex] ? b.cells[colIndex].innerText : '').trim();

        if (colIndex === 6) {
            const inpA = a.cells[6].querySelector('input');
            const inpB = b.cells[6].querySelector('input');
            cellA = inpA ? inpA.value : cellA;
            cellB = inpB ? inpB.value : cellB;
        }

        const cleanA = cellA.replace(/,/g, '').replace(/[^\d.-]/g, '');
        const cleanB = cellB.replace(/,/g, '').replace(/[^\d.-]/g, '');
        const numA = parseFloat(cleanA);
        const numB = parseFloat(cleanB);

        let comp = 0;
        if (!isNaN(numA) && !isNaN(numB) && cellA !== '' && cellB !== '' && !cellA.includes('-') && !cellA.match(/^[A-Za-z]/)) {
            comp = numA - numB;
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
        if (idx === 0 || idx === 7) return; // skip No and Aksi
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
        // extract input value for Stok Awal column (index 6)
        if (r.cells.length > 6) {
            const inp = r.cells[6].querySelector('input');
            if (inp) r.cells[6].innerText = inp.value;
        }
        if (r.cells.length > 7) r.deleteCell(7); // delete action cell
        if (r.style.display === 'none') r.remove();
    });

    const tahun = document.getElementById('filterTahun').value;
    const wb = XLSX.utils.table_to_book(cloned, { sheet: "Stok_Awal_" + tahun });
    XLSX.writeFile(wb, "Stok_Awal_Tahun_" + tahun + "_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

function exportToCSV() {
    const table = document.getElementById("mainDataTable");
    const tahun = document.getElementById('filterTahun').value;
    let csv = [];
    for (let row of table.rows) {
        let cols = [];
        for (let j = 0; j < row.cells.length; j++) {
            if (j === 7) continue; // skip action column
            let val = row.cells[j].innerText.trim();
            if (j === 6) {
                const inp = row.cells[6].querySelector('input');
                if (inp) val = inp.value;
            }
            cols.push('"' + val.replace(/"/g, '""') + '"');
        }
        csv.push(cols.join(","));
    }
    const blob = new Blob([csv.join("\n")], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "Stok_Awal_Tahun_" + tahun + "_" + new Date().toISOString().slice(0,10) + ".csv";
    link.click();
}

function copyTableClipboard() {
    const table = document.getElementById("mainDataTable");
    let csv = [];
    for (let row of table.rows) {
        let cols = [];
        for (let j = 0; j < row.cells.length; j++) {
            if (j === 7) continue;
            let val = row.cells[j].innerText.trim();
            if (j === 6) {
                const inp = row.cells[6].querySelector('input');
                if (inp) val = inp.value;
            }
            cols.push('"' + val.replace(/"/g, '""') + '"');
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

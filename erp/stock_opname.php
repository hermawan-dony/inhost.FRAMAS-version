<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Stock Opname & Penyesuaian Fisik Stok
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_stok_opname.srw, d_stockopname.srd, w_rpt_so.srw)
 * Terhubung ke tabel bc_so0 (7.500+ entri), bc_sto, bc_material, dan bc_tipe.
 * 
 * Modul wajib Kawasan Berikat untuk inventarisasi fisik barang (Bahan Baku, WIP, FG, Scrap, Mesin)
 * dan rekonsiliasi selisih buku IT Inventory terhadap saldo fisik riil.
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

// Tutup session lock agar request AJAX non-blocking
session_write_close();

$action = $_REQUEST['action'] ?? '';

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $tahun = trim($_GET['tahun'] ?? date('Y'));
    $escTahun = $koneksi->real_escape_string($tahun);

    // Total material
    $mRes = $koneksi->query("SELECT COUNT(*) as total_mat FROM bc_material WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND (AKTIF = 'Y' OR AKTIF IS NULL)");
    $totalMat = $mRes ? (int)$mRes->fetch_assoc()['total_mat'] : 0;

    // Total SO terisi
    $soRes = $koneksi->query("SELECT COUNT(*) as total_so, SUM(IFNULL(stok_awal, 0)) as total_qty FROM bc_so0 WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND tahun = '$escTahun'");
    $soData = $soRes ? $soRes->fetch_assoc() : ['total_so' => 0, 'total_qty' => 0];

    $totalSo = (int)$soData['total_so'];
    $totalQty = (float)$soData['total_qty'];
    $persen = $totalMat > 0 ? round(($totalSo / $totalMat) * 100, 1) : 0;

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_mat' => $totalMat,
            'total_so'  => $totalSo,
            'total_qty' => $totalQty,
            'persen'    => $persen
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Data Stock Opname
// =========================================================================
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');

    $tahun  = trim($_GET['tahun'] ?? date('Y'));
    $klas   = trim($_GET['klas'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $escTahun = $koneksi->real_escape_string($tahun);

    $where = ["(m.cmp_id = '$escCmp' OR m.cmp_id = '')", "(m.AKTIF = 'Y' OR m.AKTIF IS NULL)"];

    if (!empty($klas)) {
        $escK = $koneksi->real_escape_string($klas);
        $where[] = "m.KLAS = '$escK'";
    }

    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(m.KODE_BARANG LIKE '%$escS%' OR m.URAIAN LIKE '%$escS%')";
    }

    if ($status === 'done') {
        $where[] = "s.stok_awal IS NOT NULL";
    } elseif ($status === 'pending') {
        $where[] = "s.stok_awal IS NULL";
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    // Hitung total baris
    $countSql = "SELECT COUNT(*) as cnt 
                 FROM bc_material m
                 LEFT JOIN bc_so0 s ON m.KODE_BARANG = s.kode_barang AND s.tahun = '$escTahun' AND (s.cmp_id = '$escCmp' OR s.cmp_id = '')
                 $whereSql";
    $cRes = $koneksi->query($countSql);
    $total = $cRes ? (int)$cRes->fetch_assoc()['cnt'] : 0;

    // Ambil data
    $sql = "SELECT m.KODE_BARANG as kode_barang, 
                   m.URAIAN as uraian, 
                   m.KLAS as klas, 
                   IFNULL(m.KODE_SATUAN, 'PCE') as unit, 
                   IFNULL(m.UNIT_PRICE, 0) as unit_price,
                   s.stok_awal, 
                   s.set_by,
                   (s.stok_awal * IFNULL(m.UNIT_PRICE, 0)) as total_nilai
            FROM bc_material m
            LEFT JOIN bc_so0 s ON m.KODE_BARANG = s.kode_barang AND s.tahun = '$escTahun' AND (s.cmp_id = '$escCmp' OR s.cmp_id = '')
            $whereSql
            ORDER BY m.KLAS ASC, m.KODE_BARANG ASC
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        'total_pages' => ceil($total / $limit)
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Save Single Row Stock Opname
// =========================================================================
if ($action === 'save_row') {
    header('Content-Type: application/json; charset=utf-8');

    $tahun = trim($_POST['tahun'] ?? date('Y'));
    $kode  = trim($_POST['kode_barang'] ?? '');
    $unit  = trim($_POST['unit'] ?? 'PCE');
    $stok  = (float)($_POST['stok_awal'] ?? 0);
    $userSet = $userId . ' - ' . date('Ymd H:i');

    if (empty($kode) || empty($tahun)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Barang dan Tahun wajib ada']);
        exit;
    }

    $escTahun = $koneksi->real_escape_string($tahun);
    $escKode  = $koneksi->real_escape_string($kode);
    $escUnit  = $koneksi->real_escape_string($unit);
    $escUser  = $koneksi->real_escape_string($userSet);

    // Upsert ke bc_so0
    $upsertSql = "INSERT INTO bc_so0 (tahun, kode_barang, unit, stok_awal, set_by, cmp_id)
                  VALUES ('$escTahun', '$escKode', '$escUnit', $stok, '$escUser', '$escCmp')
                  ON DUPLICATE KEY UPDATE stok_awal = $stok, unit = '$escUnit', set_by = '$escUser'";
    if ($koneksi->query($upsertSql)) {
        // Sinkronisasi opsional ke bc_sto
        @$koneksi->query("INSERT INTO bc_sto (tgl, kode_barang, uraian, unit, stok, klas, cmp_id)
                         SELECT '$escTahun-12-31', KODE_BARANG, URAIAN, KODE_SATUAN, $stok, KLAS, '$escCmp'
                         FROM bc_material WHERE KODE_BARANG = '$escKode'
                         ON DUPLICATE KEY UPDATE stok = $stok");

        echo json_encode(['status' => 'OK', 'message' => 'Stok Opname berhasil disimpan', 'set_by' => $userSet]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $koneksi->error]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Opname & Penyesuaian Fisik - CEISA 4.0 & Enterprise Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="/assets/sweetalert2.all.min.js">        window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <script src="/assets/ant_grid.js">        window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <script>
        (function() {
            try {
                var urlParams = new URLSearchParams(window.location.search);
                var t = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
                if (t === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark-mode');
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch(e) {}
        })();
            window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-sub: #8c8c8c;
            --border: #d9d9d9;
            --border-light: #e8e8e8;
            --table-head-bg: #fafafa;
            --table-hover: #f0f7ff;
            --table-stripe: #fafbfc;
        }
        body[data-theme="dark"], html.dark-mode, body.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-sub: rgba(255, 255, 255, 0.45);
            --border: #303030;
            --border-light: #424242;
            --table-head-bg: #1d1d1d;
            --table-hover: #262638;
            --table-stripe: #1a2234;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 9.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; padding: 6px 10px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 3px; flex-wrap: wrap; gap: 6px; }
        .header-title { display: flex; align-items: center; gap: 6px; }
        .header-title i { font-size: 16px; color: var(--primary); }
        .header-title h2 { font-size: 12px; font-weight: 600; color: var(--text-main); margin: 0; }
        .header-title .badge-cmp { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; font-size: 9px; font-weight: 600; padding: 1px 6px; border-radius: 2px; }

        /* KPI Cards Grid */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 6px; margin-bottom: 6px; }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 3px; padding: 6px 10px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .kpi-info h5 { font-size: 9px; font-weight: 600; text-transform: uppercase; color: var(--text-sub); margin-bottom: 2px; }
        .kpi-info h3 { font-size: 13px; font-weight: 700; color: var(--text-main); }
        .kpi-icon { width: 28px; height: 28px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .icon-blue   { background: #e6f7ff; color: #1890ff; }
        .icon-green  { background: #e6ffed; color: #52c41a; }
        .icon-amber  { background: #fffbe6; color: #faad14; }
        .icon-purple { background: #f9f0ff; color: #722ed1; }

        /* Card Panel */
        .card-panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: 3px; padding: 6px 10px; margin-bottom: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .filter-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 2px; }
        .filter-group label { font-size: 9px; font-weight: 600; text-transform: uppercase; color: var(--text-sub); }
        .input-control, .select-control {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-main);
            border-radius: 2px;
            padding: 1px 6px;
            height: 21px;
            font-size: 9.5px;
            outline: none;
            transition: border-color 0.2s;
        }
        .input-control:focus, .select-control:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24,144,255,0.15); }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 4px; padding: 0 8px; height: 21px; font-size: 9.5px; font-weight: 500; border-radius: 2px; cursor: pointer; border: 1px solid var(--border); transition: all 0.2s; background: var(--bg-card); color: var(--text-main); }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-primary:hover { background: var(--primary-hover); color: #fff; }
        .btn-default { background: var(--bg-card); border-color: var(--border); color: var(--text-main); }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        .btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .btn-success:hover { opacity: 0.9; }

        /* Badges */
        .tag-klas { display: inline-block; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-weight: 600; }
        .klas-rm { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .klas-fg { background: #e6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .klas-wip { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }
        .klas-cg { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }
        .klas-w { background: #fff1f0; color: #f5222d; border: 1px solid #ffa39e; }

        /* Table */
        .table-responsive { width: 100%; overflow-x: auto; border: 1px solid var(--border); border-radius: 2px; }
        table.ant-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 9.5px; }
        table.ant-table thead th {
            background: var(--table-head-bg);
            color: var(--text-sub);
            font-weight: 600;
            padding: 4px 6px;
            font-size: 9.5px;
            border: 1px solid var(--border);
            white-space: nowrap;
        }
        table.ant-table tbody td {
            padding: 2px 6px;
            border: 1px solid var(--border-light);
            color: var(--text-main);
            vertical-align: middle;
            font-size: 9.5px;
        }
        table.ant-table tbody tr:nth-child(even) { background: var(--table-stripe); }
        table.ant-table tbody tr:hover { background: var(--table-hover); }

        .input-inline {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-main);
            border-radius: 2px;
            padding: 1px 4px;
            height: 21px;
            font-size: 9.5px;
            width: 100px;
            text-align: right;
            outline: none;
        }
        .input-inline:focus { border-color: var(--primary); }

        /* Pagination */
        .pagination-container { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; flex-wrap: wrap; gap: 6px; font-size: 9.5px; color: var(--text-sub); }
        .page-buttons { display: flex; gap: 3px; }
        .btn-page { min-width: 22px; height: 21px; padding: 0 5px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); background: var(--bg-card); color: var(--text-main); border-radius: 2px; cursor: pointer; font-size: 9.5px; }
        .btn-page.active { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 600; }
        .btn-page:hover:not(.active):not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .btn-page:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body>

    <!-- Header Section -->
    <div class="page-header">
        <div class="header-title">
            <i class="fa-solid fa-clipboard-check"></i>
            <div>
                <h2>Stock Opname & Penyesuaian Fisik Stok (w_stok_opname.srw)</h2>
                <div style="font-size:11px; color:var(--text-sub); margin-top:2px;">
                    Pencatatan Hasil Perhitungan Fisik Stok, Validasi IT Inventory, dan Selisih Rekonsiliasi Tahunan (bc_so0)
                </div>
            </div>
            <span class="badge-cmp"><?= htmlspecialchars($sessionCmpId) ?></span>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-default" onclick="loadSoData()"><i class="fa-solid fa-rotate"></i> Muat Ulang</button>
            <button class="btn btn-success" onclick="printBeritaAcara()"><i class="fa-solid fa-print"></i> Cetak Berita Acara SO</button>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Master Material</h5>
                <h3 id="kpi-total-mat">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-boxes-stacked"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Item Ter-Opname</h5>
                <h3 id="kpi-total-so">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-check-double"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Qty Fisik</h5>
                <h3 id="kpi-total-qty">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-calculator"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Kelengkapan Opname</h5>
                <h3 id="kpi-persen" style="color:var(--primary);">- %</h3>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-chart-line"></i></div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card-panel">
        <div class="filter-row">
            <div class="filter-group" style="width: 140px;">
                <label>Tahun Opname</label>
                <select id="filter-tahun" class="select-control" onchange="loadKpi(); current_page=1; loadSoData();">
                    <?php
                    $curY = (int)date('Y');
                    for ($y = $curY; $y >= 2018; $y--) {
                        $sel = ($y == 2020 || $y == $curY) ? 'selected' : '';
                        echo "<option value='$y' $sel>$y</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Klasifikasi Barang</label>
                <select id="filter-klas" class="select-control">
                    <option value="">Semua Klasifikasi</option>
                    <option value="Raw Materials">Raw Materials (Bahan Baku)</option>
                    <option value="Finished Goods">Finished Goods (Barang Jadi)</option>
                    <option value="WIP">WIP (Barang Dalam Proses)</option>
                    <option value="Capital Goods">Capital Goods (Mesin/Aset)</option>
                    <option value="Wastes">Wastes / Reject</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status Pengisian</label>
                <select id="filter-status" class="select-control">
                    <option value="">Semua Item</option>
                    <option value="done">Sudah Di-Opname</option>
                    <option value="pending">Belum Di-Opname</option>
                </select>
            </div>
            <div class="filter-group" style="flex:2; min-width: 200px;">
                <label>Cari Kode / Nama Barang</label>
                <input type="text" id="filter-search" class="input-control" placeholder="Ketik kode atau nama barang...">
            </div>
            <button class="btn btn-primary" onclick="current_page=1; loadSoData();"><i class="fa-solid fa-magnifying-glass"></i> Tampilkan Data</button>
            <button class="btn btn-default" onclick="resetFilter()"><i class="fa-solid fa-arrow-rotate-left"></i> Reset</button>
        </div>
    </div>

    <!-- Main Table Panel -->
    <div class="card-panel" style="padding:0;">
        <div class="table-responsive">
            <table class="ant-table ant-grid-table" id="stockOpnameTable">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">No.</th>
                        <th>Klasifikasi</th>
                        <th>Kode Barang</th>
                        <th>Uraian / Deskripsi Barang</th>
                        <th style="text-align: center;">Satuan</th>
                        <th style="text-align: right; width: 140px;">Hasil Fisik (SO)</th>
                        <th style="text-align: right;">Estimasi Nilai</th>
                        <th style="text-align: center;">Pemeriksa / Set By</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="so-table-body">
                    <tr><td colspan="9" style="text-align:center; padding:24px; color:var(--text-sub);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data stock opname...</td></tr>
                </tbody>
            </table>
        </div>
        <div style="padding: 12px 16px;">
            <div class="pagination-container">
                <div id="page-summary">Menampilkan 0 dari 0 data</div>
                <div class="page-buttons" id="page-buttons"></div>
            </div>
        </div>
    </div>

    <script>
        const cmpId = <?= json_encode($sessionCmpId) ?>;
        let current_page = 1;
        const page_limit = 25;

        function formatNumber(num, dec = 4) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });
        }

        function formatCurrency(num) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function getKlasBadge(klas) {
            const k = String(klas).toLowerCase();
            if (k.includes('raw')) return `<span class="tag-klas klas-rm">Raw Material</span>`;
            if (k.includes('finish')) return `<span class="tag-klas klas-fg">Finished Goods</span>`;
            if (k.includes('wip')) return `<span class="tag-klas klas-wip">WIP</span>`;
            if (k.includes('capital')) return `<span class="tag-klas klas-cg">Capital Goods</span>`;
            if (k.includes('waste')) return `<span class="tag-klas klas-w">Wastes</span>`;
            return `<span class="tag-klas">${klas || '-'}</span>`;
        }

        function loadKpi() {
            const tahun = document.getElementById('filter-tahun').value;
            fetch(`?action=kpi&tahun=${encodeURIComponent(tahun)}&cmp_id=${encodeURIComponent(cmpId)}`)
                .then(res => res.json())
                .then(json => {
                    if (json.status === 'OK' && json.kpi) {
                        const k = json.kpi;
                        document.getElementById('kpi-total-mat').innerText = parseInt(k.total_mat || 0).toLocaleString('id-ID');
                        document.getElementById('kpi-total-so').innerText = parseInt(k.total_so || 0).toLocaleString('id-ID');
                        document.getElementById('kpi-total-qty').innerText = formatNumber(k.total_qty, 2);
                        document.getElementById('kpi-persen').innerText = `${k.persen}%`;
                    }
                }).catch(err => console.error(err));
        }

        function loadSoData() {
            const tahun  = document.getElementById('filter-tahun').value;
            const klas   = document.getElementById('filter-klas').value;
            const status = document.getElementById('filter-status').value;
            const search = document.getElementById('filter-search').value.trim();

            const tbody = document.getElementById('so-table-body');
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:24px; color:var(--text-sub);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>`;

            const url = `?action=list&tahun=${encodeURIComponent(tahun)}&klas=${encodeURIComponent(klas)}&status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}&page=${current_page}&limit=${page_limit}&cmp_id=${encodeURIComponent(cmpId)}`;

            fetch(url)
                .then(res => res.json())
                .then(json => {
                    if (json.status !== 'OK' || !json.data || json.data.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:32px; color:var(--text-sub);"><i class="fa-regular fa-folder-open" style="font-size:24px; display:block; margin-bottom:8px;"></i>Tidak ada data material yang cocok.</td></tr>`;
                        document.getElementById('page-summary').innerText = 'Menampilkan 0 dari 0 data';
                        document.getElementById('page-buttons').innerHTML = '';
                        return;
                    }

                    let html = '';
                    const offset = (current_page - 1) * page_limit;
                    json.data.forEach((r, idx) => {
                        const no = offset + idx + 1;
                        const hasStok = r.stok_awal !== null;
                        const valStok = hasStok ? parseFloat(r.stok_awal) : '';
                        const inputId = `stok-input-${idx}`;

                        html += `
                            <tr>
                                <td style="text-align:center; color:var(--text-sub);">${no}</td>
                                <td>${getKlasBadge(r.klas)}</td>
                                <td style="font-family:monospace; font-weight:700; color:var(--primary);">${r.kode_barang || '-'}</td>
                                <td>${r.uraian || '-'}</td>
                                <td style="text-align:center;"><span style="font-size:11px; font-weight:600; color:var(--text-sub);">${r.unit || '-'}</span></td>
                                <td style="text-align:right;">
                                    <input type="number" step="any" class="input-inline" id="${inputId}" value="${valStok}" placeholder="0.0000" onchange="saveRow('${encodeURIComponent(r.kode_barang)}', '${r.unit}', this.value, '${idx}')">
                                </td>
                                <td style="text-align:right; font-weight:600; color:var(--success);">${formatCurrency(r.total_nilai)}</td>
                                <td style="text-align:center; font-size:11px; color:var(--text-sub);" id="setby-${idx}">${r.set_by || '-'}</td>
                                <td style="text-align:center;">
                                    <button class="btn btn-default" style="padding:3px 8px; font-size:11px;" onclick="saveRow('${encodeURIComponent(r.kode_barang)}', '${r.unit}', document.getElementById('${inputId}').value, '${idx}')">
                                        <i class="fa-solid fa-floppy-disk"></i> Simpan
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;
                    if (window.AntEnterpriseGrid) {
                        if (!window.soGrid) {
                            window.soGrid = new AntEnterpriseGrid('#stockOpnameTable', { gridId: 'grid_stock_opname', enablePagination: false });
                        } else {
                            window.soGrid.refresh();
                        }
                    }
                    renderPagination(json.total, json.page, json.limit);
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Terjadi kesalahan memuat data: ${err.message}</td></tr>`;
                });
        }

        function saveRow(rawKode, unit, val, idx) {
            const tahun = document.getElementById('filter-tahun').value;
            const kode = decodeURIComponent(rawKode);
            const numVal = parseFloat(val) || 0;

            const fd = new FormData();
            fd.append('tahun', tahun);
            fd.append('kode_barang', kode);
            fd.append('unit', unit);
            fd.append('stok_awal', numVal);
            fd.append('cmp_id', cmpId);

            fetch(`?action=save_row`, { method: 'POST', body: fd })
                .then(res => res.json())
                .then(json => {
                    if (json.status === 'OK') {
                        document.getElementById(`setby-${idx}`).innerText = json.set_by || 'SAVED';
                        loadKpi();
                        // Toast kecil
                        const Toast = Swal.mixin({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1500,
                            timerProgressBar: true
                        });
                        Toast.fire({ icon: 'success', title: `Tersimpan: ${kode} = ${numVal}` });
                    } else {
                        Swal.fire('Gagal', json.message || 'Gagal menyimpan', 'error');
                    }
                }).catch(err => Swal.fire('Error', err.message, 'error'));
        }

        function renderPagination(total, page, limit) {
            const totalPages = Math.ceil(total / limit) || 1;
            const start = ((page - 1) * limit) + 1;
            const end = Math.min(page * limit, total);
            document.getElementById('page-summary').innerText = `Menampilkan ${start} - ${end} dari ${total.toLocaleString('id-ID')} data`;

            const btnContainer = document.getElementById('page-buttons');
            let btns = '';

            btns += `<button class="btn-page" ${page <= 1 ? 'disabled' : ''} onclick="changePage(1)"><i class="fa-solid fa-angles-left"></i></button>`;
            btns += `<button class="btn-page" ${page <= 1 ? 'disabled' : ''} onclick="changePage(${page - 1})"><i class="fa-solid fa-angle-left"></i></button>`;

            const pStart = Math.max(1, page - 2);
            const pEnd = Math.min(totalPages, page + 2);
            for (let p = pStart; p <= pEnd; p++) {
                btns += `<button class="btn-page ${p === page ? 'active' : ''}" onclick="changePage(${p})">${p}</button>`;
            }

            btns += `<button class="btn-page ${page >= totalPages ? 'disabled' : ''} onclick="changePage(${page + 1})"><i class="fa-solid fa-angle-right"></i></button>`;
            btns += `<button class="btn-page ${page >= totalPages ? 'disabled' : ''} onclick="changePage(${totalPages})"><i class="fa-solid fa-angles-right"></i></button>`;

            btnContainer.innerHTML = btns;
        }

        function changePage(p) {
            current_page = p;
            loadSoData();
        }

        function resetFilter() {
            document.getElementById('filter-klas').value = '';
            document.getElementById('filter-status').value = '';
            document.getElementById('filter-search').value = '';
            current_page = 1;
            loadSoData();
        }

        function printBeritaAcara() {
            const tahun = document.getElementById('filter-tahun').value;
            Swal.fire({
                title: 'Berita Acara Stock Opname',
                text: `Mencetak dokumen Berita Acara Rekonsiliasi Fisik Stock Opname Tahun ${tahun} untuk Audit Bea Cukai...`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Buka Dokumen Cetak'
            }).then(r => {
                if (r.isConfirmed) {
                    window.open(`?action=list&tahun=${tahun}&limit=500&print=1`, '_blank');
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadKpi();
            loadSoData();
        });
            window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
</body>
</html>

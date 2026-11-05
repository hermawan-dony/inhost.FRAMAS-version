<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Scrap & Reject Entry (Entri Scrap Produksi)
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_issue_scrap.srw / bc_scrap)
 * Terhubung ke tabel bc_scrap dengan integrasi IT Inventory 1D (Reject & Scrap).
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);

// Auto-Heal: Pastikan tabel bc_scrap tersedia dan memiliki kolom id
function ensureScrapTable($koneksi) {
    $check = $koneksi->query("SHOW TABLES LIKE 'bc_scrap'");
    if (!$check || $check->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_scrap` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `tgl` date DEFAULT NULL,
            `kode` varchar(50) DEFAULT '',
            `nama` varchar(255) DEFAULT '',
            `satuan` varchar(20) DEFAULT '',
            `jumlah` decimal(18,4) DEFAULT '0.0000',
            `no_bc` varchar(30) DEFAULT '',
            `tgl_bc` date DEFAULT NULL,
            `upd_dt` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `user_id` varchar(30) DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_cmp_scrap` (`cmp_id`, `kode`),
            KEY `idx_tgl` (`tgl`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $colCheck = $koneksi->query("SHOW COLUMNS FROM `bc_scrap` LIKE 'id'");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $koneksi->query("ALTER TABLE `bc_scrap` ADD COLUMN `id` INT AUTO_INCREMENT UNIQUE KEY FIRST");
        }
    }
}
ensureScrapTable($koneksi);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    
    $search = trim($_GET['search'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(a.cmp_id = '$escCmp' OR a.cmp_id = '')";
    }
    if (!empty($search)) {
        $scEsc = $koneksi->real_escape_string($search);
        $where[] = "(a.kode LIKE '%$scEsc%' OR a.nama LIKE '%$scEsc%' OR a.no_bc LIKE '%$scEsc%')";
    }
    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $countSql = "SELECT COUNT(*) as cnt FROM bc_scrap a $whereSql";
    $cRes = $koneksi->query($countSql);
    $total = $cRes ? (int)$cRes->fetch_assoc()['cnt'] : 0;

    $sql = "SELECT a.* FROM bc_scrap a $whereSql ORDER BY a.tgl DESC, a.id DESC LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

    $kpiSql = "SELECT COUNT(*) as total_entri, SUM(IFNULL(jumlah, 0)) as total_qty FROM bc_scrap a $whereSql";
    $kpiRes = $koneksi->query($kpiSql);
    $kpi = $kpiRes ? $kpiRes->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        'kpi'    => $kpi
    ]);
    exit;
}

if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $id      = (int)($_POST['id'] ?? 0);
    $tgl     = trim($_POST['tgl'] ?? date('Y-m-d'));
    $kode    = trim($_POST['kode'] ?? '');
    $nama    = trim($_POST['nama'] ?? '');
    $satuan  = trim($_POST['satuan'] ?? 'KG');
    $jumlah  = (float)($_POST['jumlah'] ?? 0);
    $no_bc   = trim($_POST['no_bc'] ?? '');
    $tgl_bc  = trim($_POST['tgl_bc'] ?? '') ?: null;
    $userSet = $_SESSION['user_name'] ?? 'SYSTEM';

    if (empty($kode) || empty($nama)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode dan Nama Scrap wajib diisi']);
        exit;
    }

    if ($id > 0) {
        $stmt = $koneksi->prepare("UPDATE bc_scrap SET tgl = ?, kode = ?, nama = ?, satuan = ?, jumlah = ?, no_bc = ?, tgl_bc = ?, user_id = ? WHERE id = ? AND (cmp_id = ? OR cmp_id = '')");
        $stmt->bind_param("ssssdsssis", $tgl, $kode, $nama, $satuan, $jumlah, $no_bc, $tgl_bc, $userSet, $id, $sessionCmpId);
        $stmt->execute();
    } else {
        $stmt = $koneksi->prepare("INSERT INTO bc_scrap (cmp_id, tgl, kode, nama, satuan, jumlah, no_bc, tgl_bc, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssdsss", $sessionCmpId, $tgl, $kode, $nama, $satuan, $jumlah, $no_bc, $tgl_bc, $userSet);
        $stmt->execute();
    }

    echo json_encode(['status' => 'OK', 'message' => 'Data Scrap berhasil disimpan']);
    exit;
}

if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID tidak valid']);
        exit;
    }
    $koneksi->query("DELETE FROM bc_scrap WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '')");
    echo json_encode(['status' => 'OK', 'message' => 'Data Scrap berhasil dihapus']);
    exit;
}

if ($action === 'lookup_material_scrap') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $escQ = $koneksi->real_escape_string($q);
    $res = $koneksi->query("SELECT kode_barang, nama_barang, satuan FROM bc_material WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND (kode_barang LIKE '%$escQ%' OR nama_barang LIKE '%$escQ%') AND (klasifikasi IN ('Wastes', 'Scrap', 'Reject') OR klasifikasi IS NULL) LIMIT 30");
    $list = [];
    if ($res) while ($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(['status' => 'OK', 'data' => $list]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entri Scrap & Reject - CEISA 4.0 & Enterprise Hub</title>
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
        }
        body[data-theme="dark"], html.dark-mode, body.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-sub: rgba(255, 255, 255, 0.45);
            --border: #303030;
            --border-light: #424242;
            --table-head-bg: #1d1d1d;
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

        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 6px; margin-bottom: 6px; }
        .kpi-card { background: var(--bg-card); border-radius: 3px; padding: 6px 10px; border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.02); display: flex; align-items: center; justify-content: space-between; }
        .kpi-title { font-size: 9px; font-weight: 600; color: var(--text-sub); text-transform: uppercase; margin-bottom: 2px; }
        .kpi-val { font-size: 13px; font-weight: 700; color: var(--text-main); }
        .kpi-icon { width: 26px; height: 26px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 12px; }

        .card { background: var(--bg-card); border-radius: 3px; border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.02); overflow: hidden; }
        .card-header { padding: 6px 10px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
        .card-title { font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .card-title i { color: #f59e0b; }

        .toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .input-text { padding: 1px 6px; font-size: 9.5px; height: 21px; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-card); color: var(--text-main); outline: none; }
        .input-text:focus { border-color: var(--primary); }
        .btn { padding: 0 8px; font-size: 9.5px; height: 21px; font-weight: 500; border-radius: 2px; border: 1px solid var(--border); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all .15s; background: var(--bg-card); color: var(--text-main); }
        .btn-warning { background: var(--warning); color: #fff; border-color: var(--warning); }
        .btn-warning:hover { background: #d97706; color: #fff; }
        .btn-default { background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border); }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        .btn-danger { background: var(--danger); color: #fff; border-color: var(--danger); }

        .table-responsive { width: 100%; overflow-x: auto; }
        table.data-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 9.5px; }
        table.data-table th { background: var(--table-head-bg); padding: 4px 6px; font-size: 9.5px; font-weight: 600; color: var(--text-sub); border: 1px solid var(--border); white-space: nowrap; }
        table.data-table td { padding: 2px 6px; font-size: 9.5px; border: 1px solid var(--border-light); vertical-align: middle; white-space: nowrap; }
        table.data-table tbody tr:hover { background: rgba(245, 158, 11, 0.04); }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 12px; backdrop-filter: blur(2px); }
        .modal-overlay.active { display: flex; }
        .modal-dialog { background: var(--bg-card); border-radius: 4px; width: 100%; max-width: 500px; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border: 1px solid var(--border); }
        .modal-header { padding: 6px 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; font-weight: 600; font-size: 12px; }
        .modal-body { padding: 10px; }
        .modal-footer { padding: 6px 12px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 6px; background: var(--bg-base); }
        .form-group { display: flex; flex-direction: column; gap: 2px; margin-bottom: 8px; }
        .form-group label { font-size: 9.5px; font-weight: 500; color: var(--text-sub); }
        .form-group input { padding: 1px 6px; font-size: 9.5px; height: 21px; border: 1px solid var(--border); border-radius: 2px; }
    </style>
</head>
<body>

    <div class="kpi-row">
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Total Entri Scrap</div>
                <div class="kpi-val" id="kpiTotal">0</div>
            </div>
            <div class="kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-recycle"></i></div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Akumulasi Qty Limbah/Scrap</div>
                <div class="kpi-val" id="kpiQty" style="color:var(--warning);">0.00</div>
            </div>
            <div class="kpi-icon" style="background:#fee2e2; color:#b91c1c;"><i class="fa-solid fa-weight-hanging"></i></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid fa-trash-can"></i>
                <span>Entri Reject & Scrap Hasil Produksi</span>
            </div>
            <div class="toolbar">
                <input type="text" id="filterSearch" class="input-text" placeholder="Cari Kode / Nama Scrap..." style="width:200px;" onkeyup="if(event.key==='Enter') loadData(1)">
                <button class="btn btn-default" onclick="loadData(1)"><i class="fa-solid fa-rotate"></i> Muat</button>
                <button class="btn btn-warning" onclick="openModal()"><i class="fa-solid fa-plus"></i> Tambah Scrap</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table ant-grid-table" id="scrapTable">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>Tanggal</th>
                        <th>Kode Scrap</th>
                        <th>Nama Barang / Limbah</th>
                        <th style="text-align:right;">Jumlah (Qty)</th>
                        <th>Satuan</th>
                        <th>Ref Dokumen BC</th>
                        <th>Tgl BC</th>
                        <th>Petugas</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-sub);">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>

        <div style="padding:8px 14px; display:flex; align-items:center; justify-content:space-between; border-top:1px solid var(--border); font-size:11px;">
            <span id="pageInfo">Menampilkan 0 data</span>
            <div style="display:flex; gap:4px;">
                <button class="btn btn-default" id="btnPrev" onclick="changePage(-1)"><i class="fa-solid fa-chevron-left"></i></button>
                <button class="btn btn-default" id="btnNext" onclick="changePage(1)"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <!-- Modal Form -->
    <div class="modal-overlay" id="modalForm">
        <div class="modal-dialog">
            <div class="modal-header">
                <span id="modalTitle">Entri Scrap Produksi</span>
                <button style="border:none; background:none; font-size:16px; cursor:pointer;" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="formId">
                <div class="form-group">
                    <label>Tanggal *</label>
                    <input type="date" id="formTgl" class="input-text" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Kode Barang Scrap *</label>
                    <input type="text" id="formKode" class="input-text" placeholder="Ketik kode scrap...">
                </div>
                <div class="form-group">
                    <label>Nama Barang / Limbah Scrap *</label>
                    <input type="text" id="formNama" class="input-text" placeholder="Deskripsi limbah...">
                </div>
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:8px;">
                    <div class="form-group">
                        <label>Jumlah (Qty) *</label>
                        <input type="number" id="formJumlah" class="input-text" step="0.01" value="0.00">
                    </div>
                    <div class="form-group">
                        <label>Satuan</label>
                        <input type="text" id="formSatuan" class="input-text" value="KG">
                    </div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                    <div class="form-group">
                        <label>No Dokumen BC (Jika Ada)</label>
                        <input type="text" id="formNoBc" class="input-text" placeholder="No BC...">
                    </div>
                    <div class="form-group">
                        <label>Tgl Dokumen BC</label>
                        <input type="date" id="formTglBc" class="input-text">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeModal()">Batal</button>
                <button type="button" class="btn btn-warning" onclick="saveData()"><i class="fa-solid fa-save"></i> Simpan Data</button>
            </div>
        </div>
    </div>

    <script>
        let curPage = 1;
        const curLimit = 25;

        function formatNumber(num) {
            return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num || 0);
        }

        function loadData(page = 1) {
            curPage = page;
            const search = document.getElementById('filterSearch').value;

            document.getElementById('tableBody').innerHTML = '<tr><td colspan="10" style="text-align:center; padding:15px;">Memuat data...</td></tr>';

            fetch(`scrap_entry.php?action=list&page=${page}&limit=${curLimit}&search=${encodeURIComponent(search)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'OK') {
                        document.getElementById('tableBody').innerHTML = `<tr><td colspan="10" style="text-align:center; color:red;">${res.message || 'Gagal'}</td></tr>`;
                        return;
                    }
                    renderTable(res.data, (page - 1) * curLimit);
                    document.getElementById('kpiTotal').innerText = res.kpi.total_entri || 0;
                    document.getElementById('kpiQty').innerText = formatNumber(res.kpi.total_qty);
                    document.getElementById('pageInfo').innerText = `Menampilkan ${res.data.length} dari ${res.total} data`;
                });
        }

        function renderTable(data, offset) {
            const tb = document.getElementById('tableBody');
            if (!data || data.length === 0) {
                tb.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-sub);">Tidak ada data scrap.</td></tr>';
                return;
            }
            let html = '';
            data.forEach((r, idx) => {
                html += `<tr>
                    <td>${offset + idx + 1}</td>
                    <td>${r.tgl || '-'}</td>
                    <td><strong>${r.kode}</strong></td>
                    <td>${r.nama}</td>
                    <td style="text-align:right; font-weight:700;">${formatNumber(r.jumlah)}</td>
                    <td>${r.satuan}</td>
                    <td>${r.no_bc || '-'}</td>
                    <td>${r.tgl_bc || '-'}</td>
                    <td>${r.user_id || '-'}</td>
                    <td style="text-align:center;">
                        <button class="btn btn-default" style="padding:2px 6px;" onclick="editData(${JSON.stringify(r).replace(/"/g, '&quot;')})"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button class="btn btn-danger" style="padding:2px 6px;" onclick="deleteData(${r.id})"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            });
            tb.innerHTML = html;
            if (window.AntEnterpriseGrid) {
                if (!window.scrapGrid) {
                    window.scrapGrid = new AntEnterpriseGrid('#scrapTable', { gridId: 'grid_scrap_entry', enablePagination: false });
                } else {
                    window.scrapGrid.refresh();
                }
            }
        }

        function changePage(delta) {
            const target = curPage + delta;
            if (target >= 1) loadData(target);
        }

        function openModal() {
            document.getElementById('formId').value = '';
            document.getElementById('formTgl').value = new Date().toISOString().split('T')[0];
            document.getElementById('formKode').value = '';
            document.getElementById('formNama').value = '';
            document.getElementById('formJumlah').value = '0.00';
            document.getElementById('formSatuan').value = 'KG';
            document.getElementById('formNoBc').value = '';
            document.getElementById('formTglBc').value = '';
            document.getElementById('modalForm').classList.add('active');
        }

        function closeModal() {
            document.getElementById('modalForm').classList.remove('active');
        }

        function editData(r) {
            document.getElementById('formId').value = r.id;
            document.getElementById('formTgl').value = r.tgl || '';
            document.getElementById('formKode').value = r.kode || '';
            document.getElementById('formNama').value = r.nama || '';
            document.getElementById('formJumlah').value = r.jumlah || 0;
            document.getElementById('formSatuan').value = r.satuan || 'KG';
            document.getElementById('formNoBc').value = r.no_bc || '';
            document.getElementById('formTglBc').value = r.tgl_bc || '';
            document.getElementById('modalForm').classList.add('active');
        }

        function saveData() {
            const kode = document.getElementById('formKode').value.trim();
            const nama = document.getElementById('formNama').value.trim();
            if (!kode || !nama) {
                Swal.fire('Peringatan', 'Kode dan Nama Scrap wajib diisi', 'warning');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('id', document.getElementById('formId').value);
            fd.append('tgl', document.getElementById('formTgl').value);
            fd.append('kode', kode);
            fd.append('nama', nama);
            fd.append('jumlah', document.getElementById('formJumlah').value);
            fd.append('satuan', document.getElementById('formSatuan').value);
            fd.append('no_bc', document.getElementById('formNoBc').value);
            fd.append('tgl_bc', document.getElementById('formTglBc').value);

            fetch('scrap_entry.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        Swal.fire('Berhasil', res.message, 'success');
                        closeModal();
                        loadData(curPage);
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                });
        }

        function deleteData(id) {
            Swal.fire({
                title: 'Hapus Scrap?',
                text: 'Data yang dihapus tidak dapat dikembalikan',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus'
            }).then(r => {
                if (r.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', id);
                    fetch('scrap_entry.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire('Terhapus', res.message, 'success');
                                loadData(curPage);
                            }
                        });
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => loadData(1));
            window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
</body>
</html>

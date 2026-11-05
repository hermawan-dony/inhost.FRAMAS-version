<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Traceability Bahan Baku (Pelacakan Asal-Usul Dokumen Pabean)
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_trackbb.srw, w_trace_si.srw, d_trace_rpt1.srd, d_trace_si.srd)
 * Terhubung ke tabel bc_si_aju (28.000+ baris), bc_si_dtl2, bc_si_hdr, bc_si_dtl, dan bc_material.
 * 
 * Melacak rantai pemakaian bahan baku dari Finished Goods / Sales Invoice ke Dokumen Pabean Pemasukan
 * (BC 2.3 Impor, BC 4.0 Lokal, BC 2.7 Antar Kawasan Berikat) secara presisi untuk audit Bea Cukai.
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

// Tutup session lock agar akses paralel instan dan non-blocking
session_write_close();

$action = $_REQUEST['action'] ?? '';

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');

    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";

    $kpiSql = "SELECT 
        COUNT(DISTINCT a.nomor_aju) as total_aju_masuk,
        COUNT(a.id) as total_baris_trace,
        SUM(IFNULL(a.qty_use, 0)) as total_qty_terpakai,
        COUNT(DISTINCT a.kode_brg) as total_ragam_bahan,
        SUM(CASE WHEN a.jenis_bc = '23' THEN 1 ELSE 0 END) as cnt_bc23,
        SUM(CASE WHEN a.jenis_bc = '40' THEN 1 ELSE 0 END) as cnt_bc40,
        SUM(CASE WHEN a.jenis_bc = '27' THEN 1 ELSE 0 END) as cnt_bc27
        FROM bc_si_aju a 
        WHERE $cmpClause";
    $res = $koneksi->query($kpiSql);
    $kpi = $res ? $res->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'kpi' => $kpi
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Trace Penjualan (FG -> Raw Material -> BC In)
// =========================================================================
if ($action === 'list_trace_si') {
    header('Content-Type: application/json; charset=utf-8');

    $page      = max(1, (int)($_GET['page'] ?? 1));
    $limit     = max(10, min(100, (int)($_GET['limit'] ?? 20)));
    $offset    = ($page - 1) * $limit;
    $search    = trim($_GET['search'] ?? '');
    $startDate = trim($_GET['start_date'] ?? '');
    $endDate   = trim($_GET['end_date'] ?? '');
    $jenisBc   = trim($_GET['jenis_bc'] ?? '');

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(a.cmp_id = '$escCmp' OR a.cmp_id = '')";
    }
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(a.nomor_aju LIKE '%$escS%' OR a.no_daftar LIKE '%$escS%' OR a.kode_brg LIKE '%$escS%' OR a.nama_brg LIKE '%$escS%' OR a.nama_bs LIKE '%$escS%')";
    }
    if (!empty($startDate)) {
        $escSd = $koneksi->real_escape_string($startDate);
        $where[] = "a.tgl_daftar >= '$escSd'";
    }
    if (!empty($endDate)) {
        $escEd = $koneksi->real_escape_string($endDate);
        $where[] = "a.tgl_daftar <= '$escEd'";
    }
    if (!empty($jenisBc)) {
        $escBc = $koneksi->real_escape_string($jenisBc);
        $where[] = "a.jenis_bc = '$escBc'";
    }

    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $countSql = "SELECT COUNT(a.id) as cnt FROM bc_si_aju a $whereSql";
    $cRes = $koneksi->query($countSql);
    $total = $cRes ? (int)$cRes->fetch_assoc()['cnt'] : 0;

    $sql = "SELECT a.id, a.id_sidtl2, a.nomor_aju, a.seri_barang, a.jenis_bc, 
                   a.no_daftar, a.tgl_daftar, a.kode_brg, a.nama_brg, 
                   a.qty_use, a.real_qty, a.sat, a.harga, a.nama_bs, a.set_by,
                   (a.qty_use * IFNULL(a.harga, 0)) as total_nilai
            FROM bc_si_aju a
            $whereSql
            ORDER BY a.tgl_daftar DESC, a.no_daftar DESC, a.seri_barang ASC
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
// BACKEND AJAX API: Detail Linkage Invoice & Raw Material
// =========================================================================
if ($action === 'detail_linkage') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Traceability tidak valid']);
        exit;
    }

    $escId = $koneksi->real_escape_string($id);
    $sql = "SELECT a.*, 
                   d2.kode_bahan, d2.qty_taken as qty_resep, d2.sat as sat_resep,
                   d.kode_barang as fg_kode, d.uraian as fg_uraian, d.qty as fg_qty, d.sat as fg_sat,
                   h.si_no, h.si_dt, h.vendor as buyer_code, h.no_bc as si_no_bc, h.tgl_bc as si_tgl_bc,
                   b.NAMA as buyer_name
            FROM bc_si_aju a
            LEFT JOIN bc_si_dtl2 d2 ON a.id_sidtl2 = d2.id
            LEFT JOIN bc_si_dtl d ON d2.id_sidtl = d.id
            LEFT JOIN bc_si_hdr h ON d.id_hdr = h.id
            LEFT JOIN bc_bs b ON h.vendor = b.KODE
            WHERE a.id = '$escId' LIMIT 1";
    $res = $koneksi->query($sql);
    $data = $res ? $res->fetch_assoc() : null;

    if (!$data) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data tidak ditemukan']);
        exit;
    }

    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traceability Bahan Baku - CEISA 4.0 & Enterprise Hub</title>
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
            --purple: #8b5cf6;
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

        /* Top Action Bar & Title */
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
        .icon-purple { background: #f9f0ff; color: #722ed1; }
        .icon-amber  { background: #fffbe6; color: #faad14; }

        /* Filter Panel */
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

        /* Document Badges */
        .badge-bc { display: inline-block; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-weight: 600; text-align: center; }
        .bc-23 { background: #e6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .bc-40 { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .bc-27 { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }
        .bc-25 { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .bc-30 { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }

        /* Data Table */
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
        table.ant-table tbody tr:hover { background: var(--table-hover); cursor: pointer; }

        /* Pagination */
        .pagination-container { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; flex-wrap: wrap; gap: 6px; font-size: 9.5px; color: var(--text-sub); }
        .page-buttons { display: flex; gap: 3px; }
        .btn-page { min-width: 22px; height: 21px; padding: 0 5px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); background: var(--bg-card); color: var(--text-main); border-radius: 2px; cursor: pointer; font-size: 9.5px; }
        .btn-page.active { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 600; }
        .btn-page:hover:not(.active):not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .btn-page:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 12px; }
        .modal-container { background: var(--bg-card); width: 100%; max-width: 700px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh; }
        .modal-header { padding: 14px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 15px; font-weight: 600; }
        .modal-body { padding: 20px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 12px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; background: var(--table-stripe); }
        .detail-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 16px; }
        .detail-item label { display: block; font-size: 11px; font-weight: 600; color: var(--text-sub); margin-bottom: 2px; }
        .detail-item span { font-size: 13px; font-weight: 500; color: var(--text-main); }
    </style>
</head>
<body>

    <!-- Header Section -->
    <div class="page-header">
        <div class="header-title">
            <i class="fa-solid fa-code-fork"></i>
            <div>
                <h2>Pelacakan Dokumen Asal Bahan Baku (Traceability)</h2>
                <div style="font-size:11px; color:var(--text-sub); margin-top:2px;">
                    Integrasi Rantai Pemakaian Bahan Baku Dokumen Pemasukan (BC 2.3, 4.0, 2.7) ke Finished Goods & Sales Invoice (w_trackbb.srw)
                </div>
            </div>
            <span class="badge-cmp"><?= htmlspecialchars($sessionCmpId) ?></span>
        </div>
        <div style="display:flex; gap:8px;">
            <button type="button" class="btn btn-default" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            <button class="btn btn-default" onclick="loadTraceData()"><i class="fa-solid fa-rotate"></i> Muat Ulang</button>
            <button class="btn btn-success" onclick="exportToExcel()"><i class="fa-solid fa-file-excel"></i> Export Rekonsiliasi Excel</button>
        </div>
    </div>

    <!-- KPI Metric Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Dokumen Masuk Terlacak</h5>
                <h3 id="kpi-aju-masuk">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-file-invoice"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Total Volume BB Terpakai</h5>
                <h3 id="kpi-qty-use">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-boxes-stacked"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Ragam Bahan Baku</h5>
                <h3 id="kpi-ragam-bahan">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-cubes"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Komposisi Dokumen Pemasukan</h5>
                <div style="font-size:11px; font-weight:600; display:flex; gap:6px; margin-top:4px;">
                    <span class="badge-bc bc-23" id="kpi-bc23">BC 2.3: -</span>
                    <span class="badge-bc bc-40" id="kpi-bc40">BC 4.0: -</span>
                    <span class="badge-bc bc-27" id="kpi-bc27">BC 2.7: -</span>
                </div>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-chart-pie"></i></div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="card-panel">
        <div class="filter-row">
            <div class="filter-group" style="flex: 2; min-width: 200px;">
                <label>Pencarian Bebas (No Aju / No Daftar / Kode Barang / Nama Supplier)</label>
                <input type="text" id="filter-search" class="input-control" placeholder="Ketik kata kunci pencarian...">
            </div>
            <div class="filter-group">
                <label>Jenis Dokumen Pemasukan</label>
                <select id="filter-bc" class="select-control">
                    <option value="">Semua Dokumen Pabean</option>
                    <option value="23">BC 2.3 - Impor Kawasan Berikat</option>
                    <option value="40">BC 4.0 - Pemasukan Lokal TLDDP</option>
                    <option value="27">BC 2.7 - Antar Kawasan Berikat / TPB</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Tanggal Daftar Dari</label>
                <input type="date" id="filter-start-date" class="input-control">
            </div>
            <div class="filter-group">
                <label>Sampai Dengan</label>
                <input type="date" id="filter-end-date" class="input-control">
            </div>
            <button class="btn btn-primary" onclick="current_page=1; loadTraceData();"><i class="fa-solid fa-magnifying-glass"></i> Filter Data</button>
            <button class="btn btn-default" onclick="resetFilter()"><i class="fa-solid fa-arrow-rotate-left"></i> Reset</button>
        </div>
    </div>

    <!-- Main Table Panel -->
    <div class="card-panel" style="padding:0;">
        <div class="table-responsive">
            <table class="ant-table ant-grid-table" id="traceTable">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">No.</th>
                        <th>Dokumen Pabean Masuk</th>
                        <th>No. Pendaftaran & Tgl</th>
                        <th>Kode Bahan Baku</th>
                        <th>Uraian Bahan Baku</th>
                        <th>Pemasok (Supplier)</th>
                        <th style="text-align: right;">Qty Pemakaian</th>
                        <th style="text-align: right;">Harga Satuan</th>
                        <th style="text-align: right;">Total Nilai</th>
                        <th style="text-align: center;">Otoritas / Set By</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="trace-table-body">
                    <tr><td colspan="11" style="text-align:center; padding:24px; color:var(--text-sub);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data traceability...</td></tr>
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

    <!-- Detail Modal -->
    <div class="modal-overlay" id="detail-modal">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fa-solid fa-circle-info" style="color:var(--primary); margin-right:8px;"></i> Rincian Hubungan Dokumen Pemasukan & Barang Jadi</h3>
                <button style="border:none; background:transparent; font-size:16px; cursor:pointer; color:var(--text-sub);" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <h4 style="font-size:12px; text-transform:uppercase; color:var(--primary); margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                    1. Dokumen Asal Pemasukan Pabean (Incoming Customs Document)
                </h4>
                <div class="detail-grid">
                    <div class="detail-item"><label>Jenis Dokumen Pabean</label><span id="m-jenis-bc">-</span></div>
                    <div class="detail-item"><label>Nomor Pengajuan (Aju)</label><span id="m-nomor-aju" style="font-family:monospace; font-weight:700;">-</span></div>
                    <div class="detail-item"><label>Nomor Pendaftaran</label><span id="m-no-daftar">-</span></div>
                    <div class="detail-item"><label>Tanggal Pendaftaran</label><span id="m-tgl-daftar">-</span></div>
                    <div class="detail-item"><label>Pemasok (Supplier)</label><span id="m-nama-bs">-</span></div>
                    <div class="detail-item"><label>Seri Barang dalam Dokumen</label><span id="m-seri-barang">-</span></div>
                </div>

                <h4 style="font-size:12px; text-transform:uppercase; color:var(--primary); margin-top:16px; margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                    2. Rincian Bahan Baku & Pemakaian Produksi
                </h4>
                <div class="detail-grid">
                    <div class="detail-item"><label>Kode Bahan Baku</label><span id="m-kode-brg" style="font-family:monospace; font-weight:700;">-</span></div>
                    <div class="detail-item"><label>Uraian Bahan Baku</label><span id="m-nama-brg">-</span></div>
                    <div class="detail-item"><label>Qty Terpakai Rantai Trace</label><span id="m-qty-use" style="font-weight:700; color:var(--success);">-</span></div>
                    <div class="detail-item"><label>Harga Satuan Dokumen</label><span id="m-harga">-</span></div>
                    <div class="detail-item"><label>Total Nilai Bahan</label><span id="m-total-nilai" style="font-weight:700;">-</span></div>
                    <div class="detail-item"><label>Pencatat (Set By)</label><span id="m-set-by">-</span></div>
                </div>

                <h4 style="font-size:12px; text-transform:uppercase; color:var(--primary); margin-top:16px; margin-bottom:10px; border-bottom:1px solid var(--border); padding-bottom:4px;">
                    3. Integrasi Barang Jadi & Invoice Penjualan (Jika Terhubung)
                </h4>
                <div class="detail-grid">
                    <div class="detail-item"><label>Nomor Sales Invoice</label><span id="m-si-no">-</span></div>
                    <div class="detail-item"><label>Tanggal Invoice</label><span id="m-si-dt">-</span></div>
                    <div class="detail-item"><label>Customer / Pembeli</label><span id="m-buyer-name">-</span></div>
                    <div class="detail-item"><label>Kode & Nama Barang Jadi</label><span id="m-fg-info">-</span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-default" onclick="closeModal()">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        const cmpId = <?= json_encode($sessionCmpId) ?>;
        let current_page = 1;
        const page_limit = 20;

        function formatNumber(num, dec = 4) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: dec, maximumFractionDigits: dec });
        }

        function formatCurrency(num) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function getBcBadge(jenis) {
            const j = String(jenis).trim();
            if (j === '23') return `<span class="badge-bc bc-23">BC 2.3</span>`;
            if (j === '40') return `<span class="badge-bc bc-40">BC 4.0</span>`;
            if (j === '27') return `<span class="badge-bc bc-27">BC 2.7</span>`;
            if (j === '25') return `<span class="badge-bc bc-25">BC 2.5</span>`;
            if (j === '30') return `<span class="badge-bc bc-30">BC 3.0</span>`;
            return `<span class="badge-bc bc-40">BC ${j}</span>`;
        }

        function loadKpi() {
            fetch(`?action=kpi&cmp_id=${encodeURIComponent(cmpId)}`)
                .then(res => res.json())
                .then(json => {
                    if (json.status === 'OK' && json.kpi) {
                        const k = json.kpi;
                        document.getElementById('kpi-aju-masuk').innerText = parseInt(k.total_aju_masuk || 0).toLocaleString('id-ID');
                        document.getElementById('kpi-qty-use').innerText = formatNumber(k.total_qty_terpakai, 2) + ' Qty';
                        document.getElementById('kpi-ragam-bahan').innerText = parseInt(k.total_ragam_bahan || 0).toLocaleString('id-ID') + ' Item';
                        document.getElementById('kpi-bc23').innerText = `BC 2.3: ${parseInt(k.cnt_bc23 || 0).toLocaleString('id-ID')}`;
                        document.getElementById('kpi-bc40').innerText = `BC 4.0: ${parseInt(k.cnt_bc40 || 0).toLocaleString('id-ID')}`;
                        document.getElementById('kpi-bc27').innerText = `BC 2.7: ${parseInt(k.cnt_bc27 || 0).toLocaleString('id-ID')}`;
                    }
                }).catch(err => console.error(err));
        }

        function loadTraceData() {
            const search = document.getElementById('filter-search').value.trim();
            const jenisBc = document.getElementById('filter-bc').value;
            const startDate = document.getElementById('filter-start-date').value;
            const endDate = document.getElementById('filter-end-date').value;

            const tbody = document.getElementById('trace-table-body');
            tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:24px; color:var(--text-sub);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>`;

            const url = `?action=list_trace_si&cmp_id=${encodeURIComponent(cmpId)}&page=${current_page}&limit=${page_limit}&search=${encodeURIComponent(search)}&jenis_bc=${encodeURIComponent(jenisBc)}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;

            fetch(url)
                .then(res => res.json())
                .then(json => {
                    if (json.status !== 'OK' || !json.data || json.data.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:32px; color:var(--text-sub);"><i class="fa-regular fa-folder-open" style="font-size:24px; display:block; margin-bottom:8px;"></i>Tidak ada data traceability yang cocok dengan kriteria filter.</td></tr>`;
                        document.getElementById('page-summary').innerText = 'Menampilkan 0 dari 0 data';
                        document.getElementById('page-buttons').innerHTML = '';
                        return;
                    }

                    let html = '';
                    const offset = (current_page - 1) * page_limit;
                    json.data.forEach((r, idx) => {
                        const no = offset + idx + 1;
                        html += `
                            <tr onclick="viewDetail('${r.id}')">
                                <td style="text-align:center; color:var(--text-sub);">${no}</td>
                                <td>
                                    ${getBcBadge(r.jenis_bc)}
                                    <div style="font-family:monospace; font-weight:600; font-size:11px; margin-top:2px; color:var(--text-main);">${r.nomor_aju || '-'}</div>
                                </td>
                                <td>
                                    <div style="font-weight:600;">${r.no_daftar || '-'}</div>
                                    <div style="font-size:11px; color:var(--text-sub);">${r.tgl_daftar || '-'} (Seri ${r.seri_barang || '1'})</div>
                                </td>
                                <td style="font-family:monospace; font-weight:600; color:var(--primary);">${r.kode_brg || '-'}</td>
                                <td>${r.nama_brg || '-'}</td>
                                <td><div style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${r.nama_bs || ''}">${r.nama_bs || '-'}</div></td>
                                <td style="text-align:right; font-weight:700;">
                                    ${formatNumber(r.qty_use, 4)} <span style="font-size:10px; color:var(--text-sub);">${r.sat || ''}</span>
                                </td>
                                <td style="text-align:right;">${formatCurrency(r.harga)}</td>
                                <td style="text-align:right; font-weight:600; color:var(--success);">${formatCurrency(r.total_nilai)}</td>
                                <td style="text-align:center; font-size:11px; color:var(--text-sub);">${r.set_by || '-'}</td>
                                <td style="text-align:center;" onclick="event.stopPropagation()">
                                    <button class="btn btn-default" style="padding:3px 8px; font-size:11px;" onclick="viewDetail('${r.id}')"><i class="fa-solid fa-eye"></i> Detail</button>
                                </td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;
                    if (window.AntEnterpriseGrid) {
                        if (!window.traceGrid) {
                            window.traceGrid = new AntEnterpriseGrid('#traceTable', { gridId: 'grid_traceability', enablePagination: false });
                        } else {
                            window.traceGrid.refresh();
                        }
                    }

                    // Pagination
                    renderPagination(json.total, json.page, json.limit);
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Terjadi kesalahan memuat data: ${err.message}</td></tr>`;
                });
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
            loadTraceData();
        }

        function resetFilter() {
            document.getElementById('filter-search').value = '';
            document.getElementById('filter-bc').value = '';
            document.getElementById('filter-start-date').value = '';
            document.getElementById('filter-end-date').value = '';
            current_page = 1;
            loadTraceData();
        }

        function viewDetail(id) {
            fetch(`?action=detail_linkage&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(cmpId)}`)
                .then(res => res.json())
                .then(json => {
                    if (json.status !== 'OK' || !json.data) {
                        Swal.fire('Info', json.message || 'Gagal memuat rincian', 'error');
                        return;
                    }
                    const d = json.data;
                    document.getElementById('m-jenis-bc').innerHTML = getBcBadge(d.jenis_bc);
                    document.getElementById('m-nomor-aju').innerText = d.nomor_aju || '-';
                    document.getElementById('m-no-daftar').innerText = d.no_daftar || '-';
                    document.getElementById('m-tgl-daftar').innerText = d.tgl_daftar || '-';
                    document.getElementById('m-nama-bs').innerText = d.nama_bs || '-';
                    document.getElementById('m-seri-barang').innerText = d.seri_barang || '1';

                    document.getElementById('m-kode-brg').innerText = d.kode_brg || '-';
                    document.getElementById('m-nama-brg').innerText = d.nama_brg || '-';
                    document.getElementById('m-qty-use').innerText = `${formatNumber(d.qty_use, 4)} ${d.sat || ''}`;
                    document.getElementById('m-harga').innerText = formatCurrency(d.harga);
                    document.getElementById('m-total-nilai').innerText = formatCurrency(parseFloat(d.qty_use || 0) * parseFloat(d.harga || 0));
                    document.getElementById('m-set-by').innerText = d.set_by || '-';

                    document.getElementById('m-si-no').innerText = d.si_no || '(Belum terhubung langsung)';
                    document.getElementById('m-si-dt').innerText = d.si_dt || '-';
                    document.getElementById('m-buyer-name').innerText = d.buyer_name || d.buyer_code || '-';
                    document.getElementById('m-fg-info').innerText = d.fg_kode ? `${d.fg_kode} - ${d.fg_uraian || ''} (${formatNumber(d.fg_qty, 2)} ${d.fg_sat})` : '-';

                    document.getElementById('detail-modal').style.display = 'flex';
                }).catch(err => {
                    Swal.fire('Error', err.message, 'error');
                });
        }

        function closeModal() {
            document.getElementById('detail-modal').style.display = 'none';
        }

        function exportToExcel() {
            Swal.fire({
                title: 'Export Rekonsiliasi Traceability',
                text: 'Mengunduh laporan komprehensif rantai bahan baku sesuai standar audit Bea Cukai Kawasan Berikat...',
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            });
            window.location.href = `?action=list_trace_si&cmp_id=${encodeURIComponent(cmpId)}&limit=1000&export=excel`;
        }

        function openModuleGuide() {
            Swal.fire({
                title: 'Panduan Modul & SOP: Traceability Bahan Baku Pabean (Eks-BC)',
                width: 780,
                html: `
                    <div style="text-align:left; font-size:11px; line-height:1.6; max-height:65vh; overflow-y:auto; padding-right:10px;">
                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                            <div>
                                <strong style="color:#1d4ed8; font-size:11.5px;"><i class="fa-solid fa-file-word"></i> Dokumen Resmi Arsitektur &amp; SOP Sistem</strong>
                                <div style="font-size:10px; color:#4b5563;">Tersedia dokumen panduan teknis &amp; SOP lengkap format Microsoft Word (.docx).</div>
                            </div>
                            <a href="/docs/PANDUAN_ARSITEKTUR_WO_TRACEABILITY_CEISA4.docx" target="_blank" download class="btn btn-primary" style="padding:5px 12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                <i class="fa-solid fa-download"></i> Unduh Word (.docx)
                            </a>
                        </div>

                        <h4 style="color:#1890ff; margin-top:0;"><i class="fa-solid fa-code-fork"></i> 1. Konsep Rantai Traceability Bahan Baku</h4>
                        <p>Modul Traceability menelusuri secara presisi asal-usul dokumen pabean pemasukan (BC 2.3 Impor, BC 4.0 Fasilitas Lokal, BC 2.7 Antar Kawasan Berikat) yang terkandung di dalam barang jadi yang telah diterbitkan Faktur Penjualan (Sales Invoice).</p>

                        <h4 style="color:#1890ff;"><i class="fa-solid fa-layer-group"></i> 2. Penanganan Pengiriman Parsial (Sebagian):</h4>
                        <p>Bila suatu Sales Order dikirim dan difakturkan secara bertahap dalam beberapa Sales Invoice, alokasi kuantitas bahan baku pabean dihitung secara proporsional sesuai persentase barang jadi yang dikirim, sehingga tidak terjadi tumpang tindih atau klaim berlebih.</p>

                        <h4 style="color:#1890ff;"><i class="fa-solid fa-arrows-to-dot"></i> 3. Integrasi Eks-BC Dokumen CEISA 4.0:</h4>
                        <p>Data penelusuran ini menjadi lampiran wajib pada formulir pengeluaran Bea Cukai seperti BC 2.5 (Pengeluaran Lokal/Jual Dalam Negeri), BC 3.0 (Ekspor), dan BC 2.7 (Subkontrak/Transfer). Petugas Exim dapat langsung menarik data bahan baku ini pada Form Editor CEISA 4.0.</p>

                        <h4 style="color:#1890ff;"><i class="fa-solid fa-clock-rotate-left"></i> 4. Jaminan Data Histori (100% Backward Compatible):</h4>
                        <p>Untuk invoice masa lalu yang dibuat sebelum penerapan Work Order baru, sistem tetap mempertahankan data trace historisnya secara utuh menggunakan riwayat FIFO dan formula BOM lama tanpa ada perubahan data.</p>
                    </div>
                `,
                confirmButtonText: 'Tutup Panduan',
                confirmButtonColor: '#1890ff'
            });
        }

        // Close modal on Escape
        window.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });

        // Init
        document.addEventListener('DOMContentLoaded', () => {
            loadKpi();
            loadTraceData();
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

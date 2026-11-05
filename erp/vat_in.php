<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - e-Faktur Import VAT In (Pajak Masukan FPM Coretax)
 * Porting Resmi dari OMS C#:
 * - CoretaxImportFpmPage.cs
 * - VatInImportParser.cs
 * 
 * Standar UI: Ant Design Compact Desktop 9.5px
 * Fitur:
 * 1. Upload Excel (.xlsx, .xls, .csv) & Paste Clipboard Tab-Delimited
 * 2. Auto-Detect Mapping Kolom FPM Coretax (Format Baru & Lama)
 * 3. Staging Grid Preview & Validasi Data
 * 4. Simpan ke Database MySQL (Auto-heal table: bc_coretax_vat_in)
 * 5. 4 KPI Summary Cards, Smart Filter Periode, & Search
 * 6. Export Riwayat ke Excel
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (!empty($_GET['cmp_id'])) {
    $sessionCmpId = trim($_GET['cmp_id']);
    $_SESSION['company_cmp_id'] = $sessionCmpId;
    $_SESSION['cmp_id'] = $sessionCmpId;
}
if (empty($sessionCmpId)) {
    $sessionCmpId = 'SNU';
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

// Auto-Heal: Pastikan tabel bc_coretax_vat_in tersedia
function ensureVatInTable($koneksi) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_coretax_vat_in` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        `npwp_penjual` varchar(50) DEFAULT '',
        `nama_penjual` varchar(250) DEFAULT '',
        `nomor_faktur_pajak` varchar(50) NOT NULL DEFAULT '',
        `tanggal_faktur_pajak` date DEFAULT NULL,
        `masa_pajak` varchar(10) DEFAULT '',
        `tahun` varchar(10) DEFAULT '',
        `masa_pajak_pengkreditkan` varchar(10) DEFAULT '',
        `tahun_pajak_pengkreditan` varchar(10) DEFAULT '',
        `status_faktur` varchar(50) DEFAULT '',
        `harga_jual_dpp` decimal(18,2) DEFAULT '0.00',
        `dpp_nilai_lain` decimal(18,2) DEFAULT '0.00',
        `ppn` decimal(18,2) DEFAULT '0.00',
        `ppnbm` decimal(18,2) DEFAULT '0.00',
        `perekam` varchar(100) DEFAULT '',
        `referensi` varchar(250) DEFAULT '',
        `nomor_sp2d` varchar(100) DEFAULT '',
        `valid` varchar(20) DEFAULT 'Ya',
        `dilaporkan` varchar(50) DEFAULT '',
        `dilaporkan_penjual` varchar(50) DEFAULT '',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `created_by` varchar(50) DEFAULT '',
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_faktur_cmp` (`nomor_faktur_pajak`, `cmp_id`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_tgl` (`tanggal_faktur_pajak`),
        KEY `idx_npwp` (`npwp_penjual`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensureVatInTable($koneksi);

// =========================================================================
// BACKEND AJAX HANDLER
// =========================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. Ambil Riwayat Data Tersimpan (Inquiry)
if ($action === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');
    $startDate = trim($_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month')));
    $endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));
    $search    = trim($_GET['search'] ?? '');

    $escSd = $koneksi->real_escape_string($startDate);
    $escEd = $koneksi->real_escape_string($endDate);
    $escS  = $koneksi->real_escape_string($search);

    $where = ["(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)"];
    if (!empty($startDate) && !empty($endDate)) {
        $where[] = "(tanggal_faktur_pajak >= '{$escSd}' AND tanggal_faktur_pajak <= '{$escEd}')";
    }
    if (!empty($search)) {
        $where[] = "(nomor_faktur_pajak LIKE '%{$escS}%' OR nama_penjual LIKE '%{$escS}%' OR npwp_penjual LIKE '%{$escS}%' OR referensi LIKE '%{$escS}%')";
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT * FROM bc_coretax_vat_in WHERE {$whereSql} ORDER BY tanggal_faktur_pajak DESC, id DESC LIMIT 500";
    $res = $koneksi->query($sql);

    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['harga_jual_dpp'] = (float)$r['harga_jual_dpp'];
            $r['dpp_nilai_lain'] = (float)$r['dpp_nilai_lain'];
            $r['ppn']            = (float)$r['ppn'];
            $r['ppnbm']          = (float)$r['ppnbm'];
            $rows[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $rows, 'total' => count($rows)]);
    exit;
}

// 2. Summary KPI
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $startDate = trim($_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month')));
    $endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));
    $escSd = $koneksi->real_escape_string($startDate);
    $escEd = $koneksi->real_escape_string($endDate);

    $whereSql = "(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)";
    if (!empty($startDate) && !empty($endDate)) {
        $whereSql .= " AND (tanggal_faktur_pajak >= '{$escSd}' AND tanggal_faktur_pajak <= '{$escEd}')";
    }

    $kpiSql = "SELECT 
                COUNT(1) as total_faktur,
                COALESCE(SUM(harga_jual_dpp), 0) as total_dpp,
                COALESCE(SUM(ppn), 0) as total_ppn,
                COUNT(DISTINCT npwp_penjual) as total_supplier
               FROM bc_coretax_vat_in WHERE {$whereSql}";
    $kpiRes = $koneksi->query($kpiSql);
    $kpi = $kpiRes ? $kpiRes->fetch_assoc() : ['total_faktur' => 0, 'total_dpp' => 0, 'total_ppn' => 0, 'total_supplier' => 0];

    echo json_encode(['status' => 'OK', 'kpi' => $kpi]);
    exit;
}

// 3. Simpan Batch Data Import ke Database
if ($action === 'save_batch') {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $rows = $data['rows'] ?? [];

    if (empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada baris data untuk disimpan.']);
        exit;
    }

    $saved = 0;
    $koneksi->begin_transaction();

    try {
        $stmt = $koneksi->prepare("
            INSERT INTO bc_coretax_vat_in (
                cmp_id, npwp_penjual, nama_penjual, nomor_faktur_pajak, tanggal_faktur_pajak,
                masa_pajak, tahun, masa_pajak_pengkreditkan, tahun_pajak_pengkreditan,
                status_faktur, harga_jual_dpp, dpp_nilai_lain, ppn, ppnbm, perekam,
                referensi, nomor_sp2d, valid, dilaporkan, dilaporkan_penjual, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                nama_penjual = VALUES(nama_penjual),
                tanggal_faktur_pajak = VALUES(tanggal_faktur_pajak),
                masa_pajak = VALUES(masa_pajak),
                tahun = VALUES(tahun),
                masa_pajak_pengkreditkan = VALUES(masa_pajak_pengkreditkan),
                tahun_pajak_pengkreditan = VALUES(tahun_pajak_pengkreditan),
                status_faktur = VALUES(status_faktur),
                harga_jual_dpp = VALUES(harga_jual_dpp),
                dpp_nilai_lain = VALUES(dpp_nilai_lain),
                ppn = VALUES(ppn),
                ppnbm = VALUES(ppnbm),
                perekam = VALUES(perekam),
                referensi = VALUES(referensi),
                nomor_sp2d = VALUES(nomor_sp2d),
                valid = VALUES(valid),
                dilaporkan = VALUES(dilaporkan),
                dilaporkan_penjual = VALUES(dilaporkan_penjual)
        ");

        foreach ($rows as $r) {
            $nomorFaktur = trim($r['nomor_faktur_pajak'] ?? '');
            if (empty($nomorFaktur)) continue;

            $npwp   = trim($r['npwp_penjual'] ?? '');
            $nama   = trim($r['nama_penjual'] ?? '');
            $tglRaw = trim($r['tanggal_faktur_pajak'] ?? '');
            $tglFmt = null;
            if (!empty($tglRaw)) {
                $tglTime = strtotime($tglRaw);
                if ($tglTime) $tglFmt = date('Y-m-d', $tglTime);
            }
            $masa     = trim($r['masa_pajak'] ?? '');
            $tahun    = trim($r['tahun'] ?? '');
            $masaKred = trim($r['masa_pajak_pengkreditkan'] ?? '');
            $thnKred  = trim($r['tahun_pajak_pengkreditan'] ?? '');
            $status   = trim($r['status_faktur'] ?? '');
            $dpp      = (float)($r['harga_jual_dpp'] ?? 0);
            $dppLain  = (float)($r['dpp_nilai_lain'] ?? 0);
            $ppn      = (float)($r['ppn'] ?? 0);
            $ppnbm    = (float)($r['ppnbm'] ?? 0);
            $perekam  = trim($r['perekam'] ?? '');
            $ref      = trim($r['referensi'] ?? '');
            $sp2d     = trim($r['nomor_sp2d'] ?? '');
            $valid    = trim($r['valid'] ?? 'Ya');
            $dilaporkan = trim($r['dilaporkan'] ?? '');
            $dilPen   = trim($r['dilaporkan_penjual'] ?? '');

            $stmt->bind_param(
                "ssssssssssddddsssssss",
                $sessionCmpId, $npwp, $nama, $nomorFaktur, $tglFmt,
                $masa, $tahun, $masaKred, $thnKred,
                $status, $dpp, $dppLain, $ppn, $ppnbm, $perekam,
                $ref, $sp2d, $valid, $dilaporkan, $dilPen, $currentUser
            );
            $stmt->execute();
            $saved++;
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Berhasil memproses dan menyimpan {$saved} data Faktur Masukan (VAT In)."]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
    }
    exit;
}

// 4. Hapus Baris Terpilih
if ($action === 'delete_selected') {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $ids = $data['ids'] ?? [];

    if (empty($ids)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih data yang ingin dihapus.']);
        exit;
    }

    $cleanIds = array_map('intval', $ids);
    $idList = implode(',', $cleanIds);
    $koneksi->query("DELETE FROM bc_coretax_vat_in WHERE id IN ({$idList}) AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

    echo json_encode(['status' => 'OK', 'message' => count($cleanIds) . ' baris berhasil dihapus.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e-Faktur - Import VAT In (Coretax)</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        :root {
            --primary: #f59e0b;
            --primary-hover: #d97706;
            --primary-light: #fffbeb;
            --primary-focus: rgba(245, 158, 11, 0.25);
            --success: #059669;
            --danger: #ef4444;
            --info: #0284c7;
            --bg-app: #f4f6f9;
            --bg-card: #ffffff;
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --table-head-bg: #f8fafc;
            --table-hover: #fffbeb;
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        [data-theme="dark"] {
            --primary: #fbbf24;
            --primary-hover: #f59e0b;
            --primary-light: #451a03;
            --primary-focus: rgba(251, 191, 36, 0.25);
            --bg-app: #0f172a;
            --bg-card: #1e293b;
            --border-color: #334155;
            --border-light: #1e293b;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --table-head-bg: #0f172a;
            --table-hover: #3b2406;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--app-font);
            background: var(--bg-app);
            color: var(--text-main);
            font-size: 9.5px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* HEADER & ACTIONS */
        .page-header {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 6px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .page-title {
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            color: #d97706;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn {
            height: 22px;
            padding: 0 8px;
            font-size: 9px;
            font-family: var(--app-font);
            font-weight: 500;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }
        .btn:hover { background: var(--table-head-bg); }
        .btn-primary { background: #d97706; color: #fff; border-color: #d97706; }
        .btn-primary:hover { background: #b45309; }
        .btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .btn-danger { background: var(--danger); color: #fff; border-color: var(--danger); }

        /* KPI CARDS */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            padding: 6px 12px;
            background: var(--bg-app);
            flex-shrink: 0;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-label { font-size: 8.5px; color: var(--text-muted); font-weight: 500; }
        .kpi-value { font-size: 12px; font-weight: 700; color: var(--text-main); margin-top: 1px; }

        /* STAGING ACCORDION / INPUT PANEL */
        .input-panel {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 8px 12px;
            display: none;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            flex-shrink: 0;
        }
        .drop-zone {
            border: 1px dashed #d97706;
            border-radius: 4px;
            background: var(--primary-light);
            padding: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .drop-zone:hover { opacity: 0.85; }
        .excel-box {
            width: 100%;
            height: 52px;
            font-size: 9px;
            font-family: monospace;
            padding: 4px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            resize: none;
        }

        /* FILTER BAR */
        .filter-bar {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 4px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .filter-group { display: flex; align-items: center; gap: 4px; }
        .filter-label { font-size: 8.5px; font-weight: 600; color: var(--text-muted); }

        input[type="text"], input[type="date"] {
            height: 21px;
            padding: 0 6px;
            font-size: 9px;
            font-family: var(--app-font);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        input:focus { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-focus); }

        /* TABLE GRID */
        .grid-wrap {
            flex: 1;
            overflow: auto;
            position: relative;
        }
        table.ant-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.ant-grid thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: var(--table-head-bg);
            color: var(--text-main);
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-light);
            padding: 4px 6px;
            font-weight: 600;
            text-align: left;
            user-select: none;
        }
        table.ant-grid tbody td {
            border-bottom: 1px solid var(--border-light);
            border-right: 1px solid var(--border-light);
            padding: 3px 6px;
        }
        table.ant-grid tbody tr:hover { background: var(--table-hover); }
        table.ant-grid tbody tr.selected { background: #fef3c7 !important; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7.5px;
            font-weight: 600;
        }
        .badge-warning { background: #fef3c7; color: #b45309; }
        .badge-success { background: #dcfce7; color: #15803d; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="page-header">
        <div class="page-title">
            <i class="fa-solid fa-file-import"></i>
            <span>Import VAT In (Faktur Pajak Masukan FPM Coretax)</span>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="toggleInputPanel()">
                <i class="fa-solid fa-cloud-arrow-up"></i> Buka / Tutup Staging Import
            </button>
            <button class="btn btn-success" onclick="exportToExcel()">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </button>
            <button class="btn btn-danger" onclick="deleteSelected()">
                <i class="fa-solid fa-trash"></i> Hapus Terpilih
            </button>
            <button class="btn" onclick="loadAll()">
                <i class="fa-solid fa-arrows-rotate"></i> Refresh
            </button>
        </div>
    </div>

    <!-- 4 KPI Summary Cards -->
    <div class="kpi-strip">
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL FAKTUR MASUKAN</div>
                <div class="kpi-value" id="kpiTotalFaktur">0</div>
            </div>
            <i class="fa-solid fa-file-invoice-dollar" style="color:#d97706; font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL DPP MASUKAN</div>
                <div class="kpi-value" id="kpiTotalDpp">Rp 0</div>
            </div>
            <i class="fa-solid fa-money-bill-trend-up" style="color:var(--success); font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL PPN DAPAT DIKREDITKAN</div>
                <div class="kpi-value" id="kpiTotalPpn">Rp 0</div>
            </div>
            <i class="fa-solid fa-coins" style="color:var(--primary); font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL PENJUAL / SUPPLIER</div>
                <div class="kpi-value" id="kpiTotalSupplier">0</div>
            </div>
            <i class="fa-solid fa-truck" style="color:#8b5cf6; font-size:18px;"></i>
        </div>
    </div>

    <!-- Staging Upload & Paste Panel -->
    <div class="input-panel" id="panelInput">
        <div>
            <div style="font-weight:600; margin-bottom:4px; color:#d97706;">
                <i class="fa-solid fa-file-excel"></i> Upload File Excel Coretax FPM (.xlsx / .xls / .csv)
            </div>
            <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:18px; color:#d97706; margin-bottom:3px;"></i>
                <div>Klik atau Drag & Drop file Excel FPM Coretax di sini</div>
                <div style="font-size:8px; color:var(--text-muted);">(Format resmi Coretax DJP ekspor Pajak Masukan)</div>
            </div>
            <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleFileSelect(this)">
        </div>

        <div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                <span style="font-weight:600; color:#d97706;">
                    <i class="fa-solid fa-clipboard"></i> Atau Paste Baris dari Excel (Tab-Delimited)
                </span>
                <button class="btn btn-primary" style="height:18px; font-size:8.5px;" onclick="processPastedText()">
                    <i class="fa-solid fa-check"></i> Proses Data Paste
                </button>
            </div>
            <textarea class="excel-box" id="rawPasteText" placeholder="Copy baris data dari Excel, lalu paste di sini..."></textarea>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-group">
            <span class="filter-label">Periode:</span>
            <input type="date" id="dtStart" value="<?= htmlspecialchars(date('Y-m-01', strtotime('-1 month'))) ?>" onchange="loadAll()">
            <span>s/d</span>
            <input type="date" id="dtEnd" value="<?= htmlspecialchars(date('Y-m-d')) ?>" onchange="loadAll()">
        </div>
        <div class="filter-group" style="margin-left:auto;">
            <span class="filter-label"><i class="fa-solid fa-magnifying-glass"></i> Cari:</span>
            <input type="text" id="txtSearch" placeholder="No Faktur / Penjual / NPWP / Ref..." style="width:200px;" onkeyup="if(event.key==='Enter') loadAll()">
            <button class="btn" onclick="loadAll()">Cari</button>
        </div>
    </div>

    <!-- DataGrid -->
    <div class="grid-wrap">
        <table class="ant-grid" id="tblVatIn">
            <thead>
                <tr>
                    <th style="width:24px;" class="text-center"><input type="checkbox" id="chkAll" onclick="toggleSelectAll(this)"></th>
                    <th style="width:30px;" class="text-center">#</th>
                    <th>Nomor Faktur Pajak</th>
                    <th>Tanggal</th>
                    <th>Masa / Thn</th>
                    <th>Masa Pengkreditan</th>
                    <th>NPWP Penjual</th>
                    <th>Nama Penjual</th>
                    <th>Status Faktur</th>
                    <th class="text-right">Harga Jual / DPP</th>
                    <th class="text-right">PPN Masukan</th>
                    <th>Referensi</th>
                    <th>Perekam</th>
                </tr>
            </thead>
            <tbody id="gridBody">
                <tr><td colspan="13" class="text-center" style="padding:20px;">Memuat data...</td></tr>
            </tbody>
        </table>
    </div>

    <script>
        const CURRENT_CMP_ID = '<?= addslashes($sessionCmpId) ?>';
        let currentData = [];

        document.addEventListener('DOMContentLoaded', () => {
            loadAll();

            // Drag and Drop
            const dropZone = document.getElementById('dropZone');
            ['dragenter', 'dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.style.opacity = '0.7'; }));
            ['dragleave', 'drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.style.opacity = '1'; }));
            dropZone.addEventListener('drop', e => {
                const files = e.dataTransfer.files;
                if (files.length > 0) processExcelFile(files[0]);
            });
        });

        function toggleInputPanel() {
            const p = document.getElementById('panelInput');
            p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'grid' : 'none';
        }

        function loadAll() {
            loadKPI();
            loadGridData();
        }

        async function loadKPI() {
            try {
                const s = document.getElementById('dtStart').value;
                const e = document.getElementById('dtEnd').value;
                const res = await fetch(`vat_in.php?action=kpi&start_date=${s}&end_date=${e}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
                const json = await res.json();
                if (json.status !== 'OK') return;

                const k = json.kpi;
                document.getElementById('kpiTotalFaktur').innerText = parseInt(k.total_faktur || 0).toLocaleString('id-ID');
                document.getElementById('kpiTotalDpp').innerText = 'Rp ' + parseFloat(k.total_dpp || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                document.getElementById('kpiTotalPpn').innerText = 'Rp ' + parseFloat(k.total_ppn || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                document.getElementById('kpiTotalSupplier').innerText = parseInt(k.total_supplier || 0).toLocaleString('id-ID');
            } catch(err) {
                console.error(err);
            }
        }

        async function loadGridData() {
            const tbody = document.getElementById('gridBody');
            tbody.innerHTML = '<tr><td colspan="13" class="text-center" style="padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data Faktur Masukan...</td></tr>';

            try {
                const s = document.getElementById('dtStart').value;
                const e = document.getElementById('dtEnd').value;
                const q = document.getElementById('txtSearch').value;

                const res = await fetch(`vat_in.php?action=get_data&start_date=${s}&end_date=${e}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message);

                currentData = json.data || [];
                renderGrid(currentData);
            } catch(err) {
                tbody.innerHTML = `<tr><td colspan="13" class="text-center" style="color:var(--danger); padding:15px;">Gagal memuat: ${err.message}</td></tr>`;
            }
        }

        function renderGrid(rows) {
            const tbody = document.getElementById('gridBody');
            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="13" class="text-center" style="padding:20px; color:var(--text-muted);">Tidak ada data Faktur Masukan untuk periode ini.</td></tr>';
                return;
            }

            let html = '';
            rows.forEach((r, idx) => {
                const dppStr = parseFloat(r.harga_jual_dpp || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                const ppnStr = parseFloat(r.ppn || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                html += `
                    <tr data-id="${r.id}">
                        <td class="text-center"><input type="checkbox" class="row-chk" value="${r.id}"></td>
                        <td class="text-center">${idx + 1}</td>
                        <td style="font-weight:600; color:#d97706;">${escapeHtml(r.nomor_faktur_pajak || '')}</td>
                        <td>${escapeHtml(r.tanggal_faktur_pajak || '')}</td>
                        <td>${escapeHtml(r.masa_pajak || '')} / ${escapeHtml(r.tahun || '')}</td>
                        <td>${escapeHtml(r.masa_pajak_pengkreditkan || '')} / ${escapeHtml(r.tahun_pajak_pengkreditan || '')}</td>
                        <td>${escapeHtml(r.npwp_penjual || '')}</td>
                        <td>${escapeHtml(r.nama_penjual || '')}</td>
                        <td><span class="badge badge-warning">${escapeHtml(r.status_faktur || 'NORMAL')}</span></td>
                        <td class="text-right" style="font-weight:600;">${dppStr}</td>
                        <td class="text-right" style="color:#d97706; font-weight:600;">${ppnStr}</td>
                        <td>${escapeHtml(r.referensi || '')}</td>
                        <td>${escapeHtml(r.perekam || '')}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // =========================================================================
        // PARSER & EXCEL IMPORT LOGIC (Identik VatInImportParser.cs di C#)
        // =========================================================================
        function handleFileSelect(input) {
            if (input.files.length > 0) {
                processExcelFile(input.files[0]);
            }
        }

        function processExcelFile(file) {
            Swal.fire({
                title: 'Membaca Excel...',
                text: 'Memproses baris data Coretax FPM (Pajak Masukan)',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const jsonRows = XLSX.utils.sheet_to_json(firstSheet, { header: 1, raw: false });

                    if (!jsonRows || jsonRows.length === 0) {
                        throw new Error("File Excel kosong.");
                    }

                    const parsed = parseCoretaxRows(jsonRows);
                    uploadBatchRows(parsed);
                } catch(err) {
                    Swal.fire('Error Membaca Excel', err.message, 'error');
                }
            };
            reader.readAsArrayBuffer(file);
        }

        function processPastedText() {
            const raw = document.getElementById('rawPasteText').value.trim();
            if (!raw) {
                Swal.fire('Peringatan', 'Silakan paste baris data Excel terlebih dahulu.', 'warning');
                return;
            }

            const lines = raw.split(/\r?\n/).filter(l => l.trim().length > 0);
            const matrix = lines.map(line => line.split('\t'));
            const parsed = parseCoretaxRows(matrix);
            uploadBatchRows(parsed);
        }

        function parseCoretaxRows(matrix) {
            if (matrix.length === 0) return [];

            // Identik VatInImportParser Header Dictionary
            const HeaderMap = {
                'npwp penjual': 'npwp_penjual',
                'nama penjual': 'nama_penjual',
                'nomor faktur pajak': 'nomor_faktur_pajak',
                'tanggal faktur pajak': 'tanggal_faktur_pajak',
                'masa pajak': 'masa_pajak',
                'tahun': 'tahun',
                'masa pajak pengkreditkan': 'masa_pajak_pengkreditkan',
                'tahun pajak pengkreditan': 'tahun_pajak_pengkreditan',
                'status faktur': 'status_faktur',
                'harga jual/penggantian/dpp': 'harga_jual_dpp',
                'harga jual/dpp': 'harga_jual_dpp',
                'dpp nilai lain/dpp': 'dpp_nilai_lain',
                'ppn': 'ppn',
                'ppnbm': 'ppnbm',
                'perekam': 'perekam',
                'referensi': 'referensi',
                'nomor sp2d': 'nomor_sp2d',
                'valid': 'valid',
                'dilaporkan': 'dilaporkan',
                'dilaporkan oleh penjual': 'dilaporkan_penjual',
                'dilaporkan penjual': 'dilaporkan_penjual'
            };

            const firstRow = matrix[0];
            let colMap = {};
            let isHeader = false;

            firstRow.forEach((col, idx) => {
                const norm = String(col || '').trim().toLowerCase();
                if (HeaderMap[norm]) {
                    colMap[HeaderMap[norm]] = idx;
                    isHeader = true;
                }
            });

            // Default legacy mapping jika tidak ada header
            if (!colMap['nomor_faktur_pajak'] || !colMap['npwp_penjual']) {
                let offset = (firstRow.length > 1 && !firstRow[0] && looksLikeNpwp(firstRow[1])) ? 1 : 0;
                colMap = {
                    'npwp_penjual': 0 + offset,
                    'nama_penjual': 1 + offset,
                    'nomor_faktur_pajak': 2 + offset,
                    'tanggal_faktur_pajak': 3 + offset,
                    'masa_pajak': 4 + offset,
                    'tahun': 5 + offset,
                    'masa_pajak_pengkreditkan': 6 + offset,
                    'tahun_pajak_pengkreditan': 7 + offset,
                    'status_faktur': 8 + offset,
                    'harga_jual_dpp': 9 + offset,
                    'dpp_nilai_lain': 10 + offset,
                    'ppn': 11 + offset,
                    'ppnbm': 12 + offset,
                    'perekam': 13 + offset,
                    'referensi': 14 + offset,
                    'nomor_sp2d': 15 + offset,
                    'valid': 16 + offset,
                    'dilaporkan': 17 + offset,
                    'dilaporkan_penjual': 18 + offset
                };
            }

            const startIdx = isHeader ? 1 : 0;
            const parsedList = [];

            for (let i = startIdx; i < matrix.length; i++) {
                const r = matrix[i];
                if (!r || r.length < 3) continue;

                const getVal = (prop) => (colMap[prop] !== undefined && r[colMap[prop]]) ? String(r[colMap[prop]]).trim() : '';

                const noFaktur = getVal('nomor_faktur_pajak');
                const npwp = getVal('npwp_penjual');
                if (!noFaktur && !npwp) continue;

                const dpp = parseNum(getVal('harga_jual_dpp'));
                const ppn = parseNum(getVal('ppn'));
                const dppLain = parseNum(getVal('dpp_nilai_lain'));
                const ppnbm = parseNum(getVal('ppnbm'));

                parsedList.push({
                    npwp_penjual: npwp,
                    nama_penjual: getVal('nama_penjual'),
                    nomor_faktur_pajak: noFaktur,
                    tanggal_faktur_pajak: getVal('tanggal_faktur_pajak'),
                    masa_pajak: getVal('masa_pajak'),
                    tahun: getVal('tahun'),
                    masa_pajak_pengkreditkan: getVal('masa_pajak_pengkreditkan'),
                    tahun_pajak_pengkreditan: getVal('tahun_pajak_pengkreditan'),
                    status_faktur: getVal('status_faktur'),
                    harga_jual_dpp: dpp,
                    dpp_nilai_lain: dppLain,
                    ppn: ppn,
                    ppnbm: ppnbm,
                    perekam: getVal('perekam'),
                    referensi: getVal('referensi'),
                    nomor_sp2d: getVal('nomor_sp2d'),
                    valid: getVal('valid') || 'Ya',
                    dilaporkan: getVal('dilaporkan'),
                    dilaporkan_penjual: getVal('dilaporkan_penjual')
                });
            }

            return parsedList;
        }

        function looksLikeNpwp(val) {
            const clean = String(val || '').replace(/[^0-9]/g, '');
            return clean.length >= 15 && clean.length <= 16;
        }

        function parseNum(val) {
            if (!val) return 0;
            let s = String(val).replace(/Rp|\$/g, '').trim();
            if (s.includes(',') && s.includes('.')) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else if (s.includes(',')) {
                s = s.replace(',', '.');
            }
            const n = parseFloat(s);
            return isNaN(n) ? 0 : n;
        }

        async function uploadBatchRows(rows) {
            if (rows.length === 0) {
                Swal.fire('Peringatan', 'Tidak ada baris data faktur valid yang dapat dibaca.', 'warning');
                return;
            }

            Swal.fire({
                title: 'Menyimpan Faktur Masukan...',
                text: `Menyimpan ${rows.length} data ke database`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const res = await fetch(`vat_in.php?action=save_batch&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ rows: rows })
                });
                const json = await res.json();
                Swal.close();

                if (json.status !== 'OK') throw new Error(json.message);

                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil Simpan',
                    text: json.message,
                    timer: 1800,
                    showConfirmButton: false
                });

                document.getElementById('rawPasteText').value = '';
                document.getElementById('fileInput').value = '';
                loadAll();
            } catch(err) {
                Swal.fire('Gagal Simpan', err.message, 'error');
            }
        }

        function toggleSelectAll(master) {
            document.querySelectorAll('.row-chk').forEach(c => c.checked = master.checked);
        }

        async function deleteSelected() {
            const chks = document.querySelectorAll('.row-chk:checked');
            if (chks.length === 0) {
                Swal.fire('Peringatan', 'Pilih minimal satu baris yang ingin dihapus.', 'warning');
                return;
            }

            const ids = Array.from(chks).map(c => parseInt(c.value));
            const confirm = await Swal.fire({
                title: 'Hapus Data Faktur Masukan?',
                text: `Apakah Anda yakin ingin menghapus ${ids.length} data terpilih?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus'
            });

            if (!confirm.isConfirmed) return;

            try {
                const res = await fetch(`vat_in.php?action=delete_selected&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: ids })
                });
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message);

                Swal.fire('Terhapus', json.message, 'success');
                loadAll();
            } catch(err) {
                Swal.fire('Gagal Hapus', err.message, 'error');
            }
        }

        function exportToExcel() {
            if (currentData.length === 0) {
                Swal.fire('Info', 'Tidak ada data untuk diekspor.', 'info');
                return;
            }

            const exportRows = currentData.map((r, i) => ({
                'No': i + 1,
                'Nomor Faktur Pajak': r.nomor_faktur_pajak,
                'Tanggal': r.tanggal_faktur_pajak,
                'Masa': r.masa_pajak,
                'Tahun': r.tahun,
                'Masa Pengkreditan': r.masa_pajak_pengkreditkan,
                'Tahun Pengkreditan': r.tahun_pajak_pengkreditan,
                'NPWP Penjual': r.npwp_penjual,
                'Nama Penjual': r.nama_penjual,
                'Status Faktur': r.status_faktur,
                'DPP': r.harga_jual_dpp,
                'PPN Masukan': r.ppn,
                'Referensi': r.referensi,
                'Perekam': r.perekam
            }));

            const ws = XLSX.utils.json_to_sheet(exportRows);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "VAT_In");
            XLSX.writeFile(wb, `VAT_In_Coretax_${CURRENT_CMP_ID}_${new Date().toISOString().slice(0,10)}.xlsx`);
        }

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>

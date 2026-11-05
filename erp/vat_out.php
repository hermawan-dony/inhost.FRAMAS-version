<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - e-Faktur Import VAT Out (Pajak Keluaran Coretax)
 * Porting Resmi dari OMS C#:
 * - ImportDataCoretaxPage.cs
 * - VatOutImportParser.cs
 * 
 * Standar UI: Ant Design Compact Desktop 9.5px
 * Fitur:
 * 1. Upload Excel (.xlsx, .xls, .csv) & Paste Clipboard Tab-Delimited
 * 2. Auto-Detect Mapping Kolom Coretax (Format Baru & Lama)
 * 3. Staging Grid Preview & Validasi Data
 * 4. Simpan ke Database MySQL (Auto-heal table: bc_coretax_vat_out)
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

// Auto-Heal: Pastikan tabel bc_coretax_vat_out tersedia
function ensureVatOutTable($koneksi) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_coretax_vat_out` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        `npwp_pembeli` varchar(50) DEFAULT '',
        `nama_pembeli` varchar(250) DEFAULT '',
        `kode_transaksi` varchar(10) DEFAULT '01',
        `nomor_faktur_pajak` varchar(50) NOT NULL DEFAULT '',
        `tanggal_faktur_pajak` date DEFAULT NULL,
        `masa_pajak` varchar(10) DEFAULT '',
        `tahun` varchar(10) DEFAULT '',
        `status_faktur` varchar(50) DEFAULT '',
        `esign_status` varchar(50) DEFAULT '',
        `harga_jual_dpp` decimal(18,2) DEFAULT '0.00',
        `dpp_nilai_lain` decimal(18,2) DEFAULT '0.00',
        `ppn` decimal(18,2) DEFAULT '0.00',
        `ppnbm` decimal(18,2) DEFAULT '0.00',
        `penandatangan` varchar(100) DEFAULT '',
        `referensi` varchar(250) DEFAULT '',
        `dilaporkan_penjual` varchar(50) DEFAULT '',
        `dilaporkan_pemungut` varchar(50) DEFAULT '',
        `invoice_no` varchar(100) DEFAULT '',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `created_by` varchar(50) DEFAULT '',
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_faktur_cmp` (`nomor_faktur_pajak`, `cmp_id`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_tgl` (`tanggal_faktur_pajak`),
        KEY `idx_npwp` (`npwp_pembeli`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensureVatOutTable($koneksi);

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
        $where[] = "(nomor_faktur_pajak LIKE '%{$escS}%' OR nama_pembeli LIKE '%{$escS}%' OR npwp_pembeli LIKE '%{$escS}%' OR referensi LIKE '%{$escS}%')";
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT * FROM bc_coretax_vat_out WHERE {$whereSql} ORDER BY tanggal_faktur_pajak DESC, id DESC LIMIT 500";
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
                COUNT(DISTINCT npwp_pembeli) as total_buyer
               FROM bc_coretax_vat_out WHERE {$whereSql}";
    $kpiRes = $koneksi->query($kpiSql);
    $kpi = $kpiRes ? $kpiRes->fetch_assoc() : ['total_faktur' => 0, 'total_dpp' => 0, 'total_ppn' => 0, 'total_buyer' => 0];

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
    $updated = 0;
    $koneksi->begin_transaction();

    try {
        $stmt = $koneksi->prepare("
            INSERT INTO bc_coretax_vat_out (
                cmp_id, npwp_pembeli, nama_pembeli, kode_transaksi, nomor_faktur_pajak,
                tanggal_faktur_pajak, masa_pajak, tahun, status_faktur, esign_status,
                harga_jual_dpp, dpp_nilai_lain, ppn, ppnbm, penandatangan, referensi,
                dilaporkan_penjual, dilaporkan_pemungut, invoice_no, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                nama_pembeli = VALUES(nama_pembeli),
                kode_transaksi = VALUES(kode_transaksi),
                tanggal_faktur_pajak = VALUES(tanggal_faktur_pajak),
                masa_pajak = VALUES(masa_pajak),
                tahun = VALUES(tahun),
                status_faktur = VALUES(status_faktur),
                esign_status = VALUES(esign_status),
                harga_jual_dpp = VALUES(harga_jual_dpp),
                dpp_nilai_lain = VALUES(dpp_nilai_lain),
                ppn = VALUES(ppn),
                ppnbm = VALUES(ppnbm),
                penandatangan = VALUES(penandatangan),
                referensi = VALUES(referensi),
                dilaporkan_penjual = VALUES(dilaporkan_penjual),
                dilaporkan_pemungut = VALUES(dilaporkan_pemungut),
                invoice_no = VALUES(invoice_no)
        ");

        foreach ($rows as $r) {
            $nomorFaktur = trim($r['nomor_faktur_pajak'] ?? '');
            if (empty($nomorFaktur)) continue;

            $npwp   = trim($r['npwp_pembeli'] ?? '');
            $nama   = trim($r['nama_pembeli'] ?? '');
            $kodeTx = trim($r['kode_transaksi'] ?? '01');
            $tglRaw = trim($r['tanggal_faktur_pajak'] ?? '');
            $tglFmt = null;
            if (!empty($tglRaw)) {
                $tglTime = strtotime($tglRaw);
                if ($tglTime) $tglFmt = date('Y-m-d', $tglTime);
            }
            $masa   = trim($r['masa_pajak'] ?? '');
            $tahun  = trim($r['tahun'] ?? '');
            $status = trim($r['status_faktur'] ?? '');
            $esign  = trim($r['esign_status'] ?? '');
            $dpp    = (float)($r['harga_jual_dpp'] ?? 0);
            $dppLain= (float)($r['dpp_nilai_lain'] ?? 0);
            $ppn    = (float)($r['ppn'] ?? 0);
            $ppnbm  = (float)($r['ppnbm'] ?? 0);
            $ttd    = trim($r['penandatangan'] ?? '');
            $ref    = trim($r['referensi'] ?? '');
            $dilPen = trim($r['dilaporkan_penjual'] ?? '');
            $dilPem = trim($r['dilaporkan_pemungut'] ?? '');
            $invNo  = trim($r['invoice_no'] ?? '');

            // Cleanup: jika invoice_no berisi koma / kurs, ambil bagian sebelum koma (seperti logika C#)
            if (strpos($invNo, ',') !== false) {
                $invNo = trim(explode(',', $invNo)[0]);
            }

            $stmt->bind_param(
                "ssssssssssddddssssss",
                $sessionCmpId, $npwp, $nama, $kodeTx, $nomorFaktur,
                $tglFmt, $masa, $tahun, $status, $esign,
                $dpp, $dppLain, $ppn, $ppnbm, $ttd, $ref,
                $dilPen, $dilPem, $invNo, $currentUser
            );
            $stmt->execute();
            $saved++;
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Berhasil memproses dan menyimpan {$saved} data Faktur Keluaran."]);
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
    $koneksi->query("DELETE FROM bc_coretax_vat_out WHERE id IN ({$idList}) AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

    echo json_encode(['status' => 'OK', 'message' => count($cleanIds) . ' baris berhasil dihapus.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e-Faktur - Import VAT Out (Coretax)</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        :root {
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --primary-light: #f0f9ff;
            --primary-focus: rgba(2, 132, 199, 0.25);
            --success: #059669;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg-app: #f4f6f9;
            --bg-card: #ffffff;
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --table-head-bg: #f8fafc;
            --table-hover: #f0f9ff;
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        [data-theme="dark"] {
            --primary: #38bdf8;
            --primary-hover: #0ea5e9;
            --primary-light: #082f49;
            --primary-focus: rgba(56, 189, 248, 0.25);
            --bg-app: #0f172a;
            --bg-card: #1e293b;
            --border-color: #334155;
            --border-light: #1e293b;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --table-head-bg: #0f172a;
            --table-hover: #1e3a5f;
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
            color: var(--primary);
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
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-primary:hover { background: var(--primary-hover); }
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
            border: 1px dashed var(--primary);
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
        table.ant-grid tbody tr.selected { background: #bae6fd !important; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7.5px;
            font-weight: 600;
        }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-primary { background: #e0f2fe; color: #0369a1; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="page-header">
        <div class="page-title">
            <i class="fa-solid fa-file-export"></i>
            <span>Import VAT Out (Faktur Pajak Keluaran Coretax)</span>
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
                <div class="kpi-label">TOTAL FAKTUR KELUARAN</div>
                <div class="kpi-value" id="kpiTotalFaktur">0</div>
            </div>
            <i class="fa-solid fa-file-lines" style="color:var(--primary); font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL DPP (HARGA JUAL)</div>
                <div class="kpi-value" id="kpiTotalDpp">Rp 0</div>
            </div>
            <i class="fa-solid fa-money-bill-trend-up" style="color:var(--success); font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL PPN KELUARAN</div>
                <div class="kpi-value" id="kpiTotalPpn">Rp 0</div>
            </div>
            <i class="fa-solid fa-coins" style="color:var(--warning); font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">TOTAL PEMBELI / BUYER</div>
                <div class="kpi-value" id="kpiTotalBuyer">0</div>
            </div>
            <i class="fa-solid fa-users" style="color:#8b5cf6; font-size:18px;"></i>
        </div>
    </div>

    <!-- Staging Upload & Paste Panel -->
    <div class="input-panel" id="panelInput">
        <div>
            <div style="font-weight:600; margin-bottom:4px; color:var(--primary);">
                <i class="fa-solid fa-file-excel"></i> Upload File Excel Coretax (.xlsx / .xls / .csv)
            </div>
            <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:18px; color:var(--primary); margin-bottom:3px;"></i>
                <div>Klik atau Drag & Drop file Excel Coretax di sini</div>
                <div style="font-size:8px; color:var(--text-muted);">(Format resmi Coretax DJP ekspor Pajak Keluaran)</div>
            </div>
            <input type="file" id="fileInput" accept=".xlsx,.xls,.csv" style="display:none;" onchange="handleFileSelect(this)">
        </div>

        <div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                <span style="font-weight:600; color:var(--primary);">
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
            <input type="text" id="txtSearch" placeholder="No Faktur / Pembeli / NPWP / Ref..." style="width:200px;" onkeyup="if(event.key==='Enter') loadAll()">
            <button class="btn" onclick="loadAll()">Cari</button>
        </div>
    </div>

    <!-- DataGrid -->
    <div class="grid-wrap">
        <table class="ant-grid" id="tblVatOut">
            <thead>
                <tr>
                    <th style="width:24px;" class="text-center"><input type="checkbox" id="chkAll" onclick="toggleSelectAll(this)"></th>
                    <th style="width:30px;" class="text-center">#</th>
                    <th>Nomor Faktur Pajak</th>
                    <th>Tanggal</th>
                    <th>Masa / Thn</th>
                    <th>NPWP Pembeli</th>
                    <th>Nama Pembeli</th>
                    <th>Status Faktur</th>
                    <th class="text-right">Harga Jual / DPP</th>
                    <th class="text-right">PPN</th>
                    <th>Referensi / Inv No</th>
                    <th>Esign Status</th>
                    <th>Penandatangan</th>
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
                const res = await fetch(`vat_out.php?action=kpi&start_date=${s}&end_date=${e}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
                const json = await res.json();
                if (json.status !== 'OK') return;

                const k = json.kpi;
                document.getElementById('kpiTotalFaktur').innerText = parseInt(k.total_faktur || 0).toLocaleString('id-ID');
                document.getElementById('kpiTotalDpp').innerText = 'Rp ' + parseFloat(k.total_dpp || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                document.getElementById('kpiTotalPpn').innerText = 'Rp ' + parseFloat(k.total_ppn || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                document.getElementById('kpiTotalBuyer').innerText = parseInt(k.total_buyer || 0).toLocaleString('id-ID');
            } catch(err) {
                console.error(err);
            }
        }

        async function loadGridData() {
            const tbody = document.getElementById('gridBody');
            tbody.innerHTML = '<tr><td colspan="13" class="text-center" style="padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data Faktur Keluaran...</td></tr>';

            try {
                const s = document.getElementById('dtStart').value;
                const e = document.getElementById('dtEnd').value;
                const q = document.getElementById('txtSearch').value;

                const res = await fetch(`vat_out.php?action=get_data&start_date=${s}&end_date=${e}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
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
                tbody.innerHTML = '<tr><td colspan="13" class="text-center" style="padding:20px; color:var(--text-muted);">Tidak ada data Faktur Keluaran untuk periode ini.</td></tr>';
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
                        <td style="font-weight:600; color:var(--primary);">${escapeHtml(r.nomor_faktur_pajak || '')}</td>
                        <td>${escapeHtml(r.tanggal_faktur_pajak || '')}</td>
                        <td>${escapeHtml(r.masa_pajak || '')} / ${escapeHtml(r.tahun || '')}</td>
                        <td>${escapeHtml(r.npwp_pembeli || '')}</td>
                        <td>${escapeHtml(r.nama_pembeli || '')}</td>
                        <td><span class="badge badge-success">${escapeHtml(r.status_faktur || 'NORMAL')}</span></td>
                        <td class="text-right" style="font-weight:600;">${dppStr}</td>
                        <td class="text-right" style="color:var(--primary); font-weight:600;">${ppnStr}</td>
                        <td>${escapeHtml(r.invoice_no || r.referensi || '')}</td>
                        <td>${escapeHtml(r.esign_status || '')}</td>
                        <td>${escapeHtml(r.penandatangan || '')}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // =========================================================================
        // PARSER & EXCEL IMPORT LOGIC (Identik VatOutImportParser.cs di C#)
        // =========================================================================
        function handleFileSelect(input) {
            if (input.files.length > 0) {
                processExcelFile(input.files[0]);
            }
        }

        function processExcelFile(file) {
            Swal.fire({
                title: 'Membaca Excel...',
                text: 'Memproses baris data Coretax VAT Out',
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

            // Identik VatOutImportParser Header Dictionary
            const HeaderMap = {
                'npwp pembeli / identitas lainnya': 'npwp_pembeli',
                'npwp pembeli': 'npwp_pembeli',
                'nama pembeli': 'nama_pembeli',
                'kode transaksi': 'kode_transaksi',
                'nomor faktur pajak': 'nomor_faktur_pajak',
                'tanggal faktur pajak': 'tanggal_faktur_pajak',
                'masa pajak': 'masa_pajak',
                'tahun': 'tahun',
                'status faktur': 'status_faktur',
                'esignstatus': 'esign_status',
                'esign status': 'esign_status',
                'harga jual/penggantian/dpp': 'harga_jual_dpp',
                'harga jual/dpp': 'harga_jual_dpp',
                'dpp nilai lain/dpp': 'dpp_nilai_lain',
                'ppn': 'ppn',
                'ppnbm': 'ppnbm',
                'penandatangan': 'penandatangan',
                'referensi': 'referensi',
                'dilaporkan oleh penjual': 'dilaporkan_penjual',
                'dilaporkan penjual': 'dilaporkan_penjual',
                'dilaporkan oleh pemungut ppn': 'dilaporkan_pemungut',
                'dilaporkan pemungut': 'dilaporkan_pemungut'
            };

            const firstRow = matrix[0];
            let colMap = {};
            let isHeader = false;

            // Detect header row
            firstRow.forEach((col, idx) => {
                const norm = String(col || '').trim().toLowerCase();
                if (HeaderMap[norm]) {
                    colMap[HeaderMap[norm]] = idx;
                    isHeader = true;
                }
            });

            // Default legacy mapping jika tidak ada header
            if (!colMap['nomor_faktur_pajak'] || !colMap['npwp_pembeli']) {
                let offset = (firstRow.length > 1 && !firstRow[0] && looksLikeNpwp(firstRow[1])) ? 1 : 0;
                colMap = {
                    'npwp_pembeli': 0 + offset,
                    'nama_pembeli': 1 + offset,
                    'kode_transaksi': 2 + offset,
                    'nomor_faktur_pajak': 3 + offset,
                    'tanggal_faktur_pajak': 4 + offset,
                    'masa_pajak': 5 + offset,
                    'tahun': 6 + offset,
                    'status_faktur': 7 + offset,
                    'esign_status': 8 + offset,
                    'harga_jual_dpp': 9 + offset,
                    'dpp_nilai_lain': 10 + offset,
                    'ppn': 11 + offset,
                    'ppnbm': 12 + offset,
                    'penandatangan': 13 + offset,
                    'referensi': 14 + offset,
                    'dilaporkan_penjual': 15 + offset,
                    'dilaporkan_pemungut': 16 + offset
                };
            }

            const startIdx = isHeader ? 1 : 0;
            const parsedList = [];

            for (let i = startIdx; i < matrix.length; i++) {
                const r = matrix[i];
                if (!r || r.length < 3) continue;

                const getVal = (prop) => (colMap[prop] !== undefined && r[colMap[prop]]) ? String(r[colMap[prop]]).trim() : '';

                const noFaktur = getVal('nomor_faktur_pajak');
                const npwp = getVal('npwp_pembeli');
                if (!noFaktur && !npwp) continue;

                const dpp = parseNum(getVal('harga_jual_dpp'));
                const ppn = parseNum(getVal('ppn'));
                const dppLain = parseNum(getVal('dpp_nilai_lain'));
                const ppnbm = parseNum(getVal('ppnbm'));

                parsedList.push({
                    npwp_pembeli: npwp,
                    nama_pembeli: getVal('nama_pembeli'),
                    kode_transaksi: getVal('kode_transaksi') || '01',
                    nomor_faktur_pajak: noFaktur,
                    tanggal_faktur_pajak: getVal('tanggal_faktur_pajak'),
                    masa_pajak: getVal('masa_pajak'),
                    tahun: getVal('tahun'),
                    status_faktur: getVal('status_faktur'),
                    esign_status: getVal('esign_status'),
                    harga_jual_dpp: dpp,
                    dpp_nilai_lain: dppLain,
                    ppn: ppn,
                    ppnbm: ppnbm,
                    penandatangan: getVal('penandatangan'),
                    referensi: getVal('referensi'),
                    dilaporkan_penjual: getVal('dilaporkan_penjual'),
                    dilaporkan_pemungut: getVal('dilaporkan_pemungut'),
                    invoice_no: getVal('referensi')
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
            // Handle ID comma decimal
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
                title: 'Menyimpan Faktur...',
                text: `Menyimpan ${rows.length} data ke database`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const res = await fetch(`vat_out.php?action=save_batch&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
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
                title: 'Hapus Data Faktur?',
                text: `Apakah Anda yakin ingin menghapus ${ids.length} data Faktur Keluaran terpilih?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus'
            });

            if (!confirm.isConfirmed) return;

            try {
                const res = await fetch(`vat_out.php?action=delete_selected&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
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
                'NPWP Pembeli': r.npwp_pembeli,
                'Nama Pembeli': r.nama_pembeli,
                'Status Faktur': r.status_faktur,
                'DPP': r.harga_jual_dpp,
                'PPN': r.ppn,
                'Referensi': r.referensi,
                'Esign Status': r.esign_status,
                'Penandatangan': r.penandatangan
            }));

            const ws = XLSX.utils.json_to_sheet(exportRows);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "VAT_Out");
            XLSX.writeFile(wb, `VAT_Out_Coretax_${CURRENT_CMP_ID}_${new Date().toISOString().slice(0,10)}.xlsx`);
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

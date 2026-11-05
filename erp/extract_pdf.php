<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - e-Faktur Extract PDF (Ekstraksi & Rekonsiliasi Faktur Pajak)
 * Porting Resmi dari OMS C#:
 * - CoretaxExtractPdfPage.cs
 * - TaxExtractedPdfSchema.cs
 * 
 * Standar UI: Ant Design Compact Desktop 9.5px
 * Fitur:
 * 1. Bulk PDF Extractor (Upload multiple PDF file faktur sekaligus)
 * 2. Ekstraksi client-side super cepat menggunakan PDF.js + Regex Parser C# identik
 * 3. Ekstraksi Nomor Faktur, Penjual, Pembeli, Tanggal, DPP, PPN, Nomor Aju, SPPB, Nama Barang
 * 4. Rekonsiliasi Otomatis dengan Data Pabean CEISA (Perbandingan DPP Faktur vs DPP Dokumen BC)
 * 5. Dashboard 4 KPI Status (Total Faktur, Status Match OK, Mismatch DPP, Aju Kosong)
 * 6. Export Riwayat ke Excel & Auto-heal table: bc_tax_extracted_pdf
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

// Auto-Heal: Pastikan tabel bc_tax_extracted_pdf tersedia
function ensureExtractedPdfTable($koneksi) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_tax_extracted_pdf` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        `nomor_faktur` varchar(50) NOT NULL DEFAULT '',
        `nama_penjual` varchar(250) DEFAULT '',
        `npwp_penjual` varchar(50) DEFAULT '',
        `nama_pembeli` varchar(250) DEFAULT '',
        `npwp_pembeli` varchar(50) DEFAULT '',
        `nama_barang` text DEFAULT NULL,
        `total_dpp` decimal(18,2) DEFAULT '0.00',
        `total_ppn` decimal(18,2) DEFAULT '0.00',
        `tanggal_faktur` date DEFAULT NULL,
        `nomor_aju` varchar(50) DEFAULT '',
        `sppb` varchar(100) DEFAULT '',
        `vat_type` varchar(50) DEFAULT '',
        `file_path` varchar(255) DEFAULT '',
        `total_dpp_bc` decimal(18,2) DEFAULT '0.00',
        `diff` decimal(18,2) DEFAULT '0.00',
        `status` varchar(50) DEFAULT 'MATCH',
        `note` varchar(255) DEFAULT '',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `created_by` varchar(50) DEFAULT '',
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_faktur_cmp` (`nomor_faktur`, `cmp_id`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_tgl` (`tanggal_faktur`),
        KEY `idx_aju` (`nomor_aju`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensureExtractedPdfTable($koneksi);

// =========================================================================
// BACKEND AJAX HANDLER
// =========================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. Ambil Riwayat Data Tersimpan (Inquiry)
if ($action === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');
    $startDate = trim($_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month')));
    $endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));
    $statusFlt = trim($_GET['status_filter'] ?? 'ALL');
    $search    = trim($_GET['search'] ?? '');

    $escSd = $koneksi->real_escape_string($startDate);
    $escEd = $koneksi->real_escape_string($endDate);
    $escS  = $koneksi->real_escape_string($search);

    $where = ["(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)"];
    if (!empty($startDate) && !empty($endDate)) {
        $where[] = "(tanggal_faktur >= '{$escSd}' AND tanggal_faktur <= '{$escEd}')";
    }
    if ($statusFlt === 'MISMATCH') {
        $where[] = "(status = 'MISMATCH' OR diff != 0)";
    } elseif ($statusFlt === 'EMPTY_AJU') {
        $where[] = "(nomor_aju IS NULL OR nomor_aju = '')";
    } elseif ($statusFlt === 'MATCH') {
        $where[] = "(status = 'MATCH' AND diff = 0)";
    }
    if (!empty($search)) {
        $where[] = "(nomor_faktur LIKE '%{$escS}%' OR nama_penjual LIKE '%{$escS}%' OR nama_pembeli LIKE '%{$escS}%' OR nomor_aju LIKE '%{$escS}%' OR sppb LIKE '%{$escS}%')";
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT * FROM bc_tax_extracted_pdf WHERE {$whereSql} ORDER BY tanggal_faktur DESC, id DESC LIMIT 500";
    $res = $koneksi->query($sql);

    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['total_dpp']    = (float)$r['total_dpp'];
            $r['total_ppn']    = (float)$r['total_ppn'];
            $r['total_dpp_bc'] = (float)$r['total_dpp_bc'];
            $r['diff']         = (float)$r['diff'];
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
        $whereSql .= " AND (tanggal_faktur >= '{$escSd}' AND tanggal_faktur <= '{$escEd}')";
    }

    $kpiSql = "SELECT 
                COUNT(1) as total_faktur,
                COALESCE(SUM(CASE WHEN (diff = 0 AND (status = 'MATCH' OR status = 'OK')) THEN 1 ELSE 0 END), 0) as total_match,
                COALESCE(SUM(CASE WHEN (diff != 0 OR status = 'MISMATCH') THEN 1 ELSE 0 END), 0) as total_mismatch,
                COALESCE(SUM(CASE WHEN (nomor_aju IS NULL OR nomor_aju = '') THEN 1 ELSE 0 END), 0) as total_empty_aju,
                COALESCE(SUM(total_dpp), 0) as sum_dpp,
                COALESCE(SUM(total_ppn), 0) as sum_ppn
               FROM bc_tax_extracted_pdf WHERE {$whereSql}";
    $kpiRes = $koneksi->query($kpiSql);
    $kpi = $kpiRes ? $kpiRes->fetch_assoc() : [
        'total_faktur' => 0, 'total_match' => 0, 'total_mismatch' => 0, 'total_empty_aju' => 0, 'sum_dpp' => 0, 'sum_ppn' => 0
    ];

    echo json_encode(['status' => 'OK', 'kpi' => $kpi]);
    exit;
}

// 3. Simpan Batch Data Hasil Ekstraksi
if ($action === 'save_batch') {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    $rows = $data['rows'] ?? [];

    if (empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada baris data ekstraksi untuk disimpan.']);
        exit;
    }

    $saved = 0;
    $koneksi->begin_transaction();

    try {
        $stmt = $koneksi->prepare("
            INSERT INTO bc_tax_extracted_pdf (
                cmp_id, nomor_faktur, nama_penjual, npwp_penjual, nama_pembeli, npwp_pembeli,
                nama_barang, total_dpp, total_ppn, tanggal_faktur, nomor_aju, sppb,
                vat_type, file_path, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NEW', ?)
            ON DUPLICATE KEY UPDATE
                nama_penjual = VALUES(nama_penjual),
                npwp_penjual = VALUES(npwp_penjual),
                nama_pembeli = VALUES(nama_pembeli),
                npwp_pembeli = VALUES(npwp_pembeli),
                nama_barang = COALESCE(VALUES(nama_barang), nama_barang),
                total_dpp = VALUES(total_dpp),
                total_ppn = VALUES(total_ppn),
                tanggal_faktur = COALESCE(VALUES(tanggal_faktur), tanggal_faktur),
                nomor_aju = COALESCE(NULLIF(VALUES(nomor_aju), ''), nomor_aju),
                sppb = COALESCE(NULLIF(VALUES(sppb), ''), sppb),
                vat_type = VALUES(vat_type),
                file_path = VALUES(file_path)
        ");

        foreach ($rows as $r) {
            $nomorFaktur = trim($r['nomor_faktur'] ?? '');
            if (empty($nomorFaktur)) continue;

            $namaPenjual = trim($r['nama_penjual'] ?? '');
            $npwpPenjual = trim($r['npwp_penjual'] ?? '');
            $namaPembeli = trim($r['nama_pembeli'] ?? '');
            $npwpPembeli = trim($r['npwp_pembeli'] ?? '');
            $namaBarang  = trim($r['nama_barang'] ?? '');
            $dpp         = (float)($r['total_dpp'] ?? 0);
            $ppn         = (float)($r['total_ppn'] ?? 0);
            $tglRaw      = trim($r['tanggal_faktur'] ?? '');
            $tglFmt      = null;
            if (!empty($tglRaw)) {
                $tglTime = strtotime($tglRaw);
                if ($tglTime) $tglFmt = date('Y-m-d', $tglTime);
            }
            $nomorAju    = trim($r['nomor_aju'] ?? '');
            $sppb        = trim($r['sppb'] ?? '');
            $vatType     = trim($r['vat_type'] ?? '');
            $filePath    = trim($r['file_path'] ?? '');

            $stmt->bind_param(
                "sssssssddssssss",
                $sessionCmpId, $nomorFaktur, $namaPenjual, $npwpPenjual, $namaPembeli, $npwpPembeli,
                $namaBarang, $dpp, $ppn, $tglFmt, $nomorAju, $sppb,
                $vatType, $filePath, $currentUser
            );
            $stmt->execute();
            $saved++;
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Berhasil memproses dan menyimpan {$saved} data ekstraksi PDF."]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
    }
    exit;
}

// 4. Proses Rekonsiliasi Otomatis dengan Data CEISA (DPP Faktur vs DPP Dokumen BC)
if ($action === 'reconcile_bc') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Ambil semua data extracted yang memiliki nomor_aju
    $q = $koneksi->query("
        SELECT p.id, p.nomor_faktur, p.nomor_aju, p.total_dpp,
               COALESCE(b.total_bc_dpp, 0) as bc_dpp
        FROM bc_tax_extracted_pdf p
        LEFT JOIN (
            SELECT nomor_aju, SUM(harga_penyerahan) as total_bc_dpp
            FROM bc_ceisa_barang
            GROUP BY nomor_aju
        ) b ON p.nomor_aju = b.nomor_aju
        WHERE (p.cmp_id = '{$escCmp}' OR p.cmp_id = '' OR p.cmp_id IS NULL)
          AND p.nomor_aju IS NOT NULL AND p.nomor_aju != ''
    ");

    $updated = 0;
    if ($q) {
        $updateStmt = $koneksi->prepare("
            UPDATE bc_tax_extracted_pdf 
            SET total_dpp_bc = ?, diff = ?, status = ?
            WHERE id = ?
        ");

        while ($row = $q->fetch_assoc()) {
            $id = (int)$row['id'];
            $fakturDpp = (float)$row['total_dpp'];
            $bcDpp = (float)$row['bc_dpp'];
            $diff = round($fakturDpp - $bcDpp, 2);
            $status = (abs($diff) < 0.05) ? 'MATCH' : 'MISMATCH';

            $updateStmt->bind_param("ddsi", $bcDpp, $diff, $status, $id);
            $updateStmt->execute();
            $updated++;
        }
    }

    echo json_encode(['status' => 'OK', 'message' => "Rekonsiliasi selesai. {$updated} data berhasil dicocokkan dengan dokumen pabean CEISA."]);
    exit;
}

// 5. Hapus Baris Terpilih
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
    $koneksi->query("DELETE FROM bc_tax_extracted_pdf WHERE id IN ({$idList}) AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

    echo json_encode(['status' => 'OK', 'message' => count($cleanIds) . ' baris berhasil dihapus.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e-Faktur - Extract PDF (Coretax)</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <!-- PDF.js CDN untuk ekstraksi teks instan di browser -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

    <style>
        :root {
            --primary: #ef4444;
            --primary-hover: #dc2626;
            --primary-light: #fef2f2;
            --primary-focus: rgba(239, 68, 68, 0.25);
            --success: #059669;
            --warning: #f59e0b;
            --info: #0284c7;
            --bg-app: #f4f6f9;
            --bg-card: #ffffff;
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --table-head-bg: #f8fafc;
            --table-hover: #fef2f2;
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        [data-theme="dark"] {
            --primary: #f87171;
            --primary-hover: #ef4444;
            --primary-light: #450a0a;
            --primary-focus: rgba(248, 113, 113, 0.25);
            --bg-app: #0f172a;
            --bg-card: #1e293b;
            --border-color: #334155;
            --border-light: #1e293b;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --table-head-bg: #0f172a;
            --table-hover: #3f1212;
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
            color: #dc2626;
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
        .btn-primary { background: #dc2626; color: #fff; border-color: #dc2626; }
        .btn-primary:hover { background: #b91c1c; }
        .btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .btn-info { background: var(--info); color: #fff; border-color: var(--info); }
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

        /* TAB BAR */
        .tab-bar {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            padding: 0 12px;
            gap: 4px;
            flex-shrink: 0;
        }
        .tab-btn {
            padding: 5px 12px;
            font-size: 9.5px;
            font-weight: 600;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .tab-btn.active {
            color: #dc2626;
            border-bottom-color: #dc2626;
        }

        /* VIEW CONTAINER */
        .view-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* UPLOAD & EXTRACT VIEW */
        .extract-view {
            padding: 10px 12px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
            overflow: auto;
        }
        .drop-zone {
            border: 2px dashed #dc2626;
            border-radius: 6px;
            background: var(--primary-light);
            padding: 18px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .drop-zone:hover { opacity: 0.85; }

        .terminal-box {
            background: #0f172a;
            color: #38bdf8;
            font-family: monospace;
            font-size: 8.5px;
            padding: 8px;
            border-radius: 4px;
            height: 120px;
            overflow-y: auto;
            white-space: pre-wrap;
            border: 1px solid #334155;
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

        select, input[type="text"], input[type="date"] {
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
        select:focus, input:focus { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-focus); }

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
        table.ant-grid tbody tr.selected { background: #fee2e2 !important; }

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
        .badge-danger { background: #fee2e2; color: #b91c1c; }
        .badge-warning { background: #fef3c7; color: #b45309; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="page-header">
        <div class="page-title">
            <i class="fa-solid fa-file-pdf"></i>
            <span>Extract PDF & Rekonsiliasi e-Faktur Coretax</span>
        </div>
        <div class="header-actions">
            <button class="btn btn-info" onclick="reconcileBc()">
                <i class="fa-solid fa-scale-balanced"></i> Reconcile Data BC
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
                <div class="kpi-label">TOTAL FAKTUR TEREKSTRAKSI</div>
                <div class="kpi-value" id="kpiTotalFaktur">0</div>
            </div>
            <i class="fa-solid fa-file-lines" style="color:#dc2626; font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">STATUS MATCH (DPP SESUAI BC)</div>
                <div class="kpi-value" id="kpiTotalMatch" style="color:var(--success);">0</div>
            </div>
            <i class="fa-solid fa-circle-check" style="color:var(--success); font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">MISMATCH (SELISIH DPP)</div>
                <div class="kpi-value" id="kpiTotalMismatch" style="color:var(--danger);">0</div>
            </div>
            <i class="fa-solid fa-triangle-exclamation" style="color:var(--danger); font-size:18px;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-label">BELUM ADA NOMOR AJU</div>
                <div class="kpi-value" id="kpiTotalEmptyAju" style="color:var(--warning);">0</div>
            </div>
            <i class="fa-solid fa-circle-question" style="color:var(--warning); font-size:18px;"></i>
        </div>
    </div>

    <!-- Tab Nav -->
    <div class="tab-bar">
        <button class="tab-btn active" id="tabBtnHistory" onclick="switchView('history')">
            <i class="fa-solid fa-table"></i> Data Tersimpan & Rekonsiliasi
        </button>
        <button class="tab-btn" id="tabBtnExtract" onclick="switchView('extract')">
            <i class="fa-solid fa-bolt"></i> Upload & Ekstraksi Bulk PDF
        </button>
    </div>

    <!-- View 1: History & Reconcile -->
    <div class="view-content" id="viewHistory">
        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <span class="filter-label">Periode:</span>
                <input type="date" id="dtStart" value="<?= htmlspecialchars(date('Y-m-01', strtotime('-1 month'))) ?>" onchange="loadAll()">
                <span>s/d</span>
                <input type="date" id="dtEnd" value="<?= htmlspecialchars(date('Y-m-d')) ?>" onchange="loadAll()">
            </div>
            <div class="filter-group">
                <span class="filter-label">Status BC:</span>
                <select id="selStatusFlt" onchange="loadAll()">
                    <option value="ALL">Semua Status</option>
                    <option value="MATCH">Match (Sesuai)</option>
                    <option value="MISMATCH">Mismatch (Selisih)</option>
                    <option value="EMPTY_AJU">Aju Kosong</option>
                </select>
            </div>
            <div class="filter-group" style="margin-left:auto;">
                <span class="filter-label"><i class="fa-solid fa-magnifying-glass"></i> Cari:</span>
                <input type="text" id="txtSearch" placeholder="No Faktur / Penjual / Pembeli / Aju..." style="width:200px;" onkeyup="if(event.key==='Enter') loadAll()">
                <button class="btn" onclick="loadAll()">Cari</button>
            </div>
        </div>

        <!-- DataGrid -->
        <div class="grid-wrap">
            <table class="ant-grid" id="tblPdfHistory">
                <thead>
                    <tr>
                        <th style="width:24px;" class="text-center"><input type="checkbox" id="chkAll" onclick="toggleSelectAll(this)"></th>
                        <th style="width:30px;" class="text-center">#</th>
                        <th>Nomor Faktur</th>
                        <th>Tanggal</th>
                        <th>Penjual</th>
                        <th>Pembeli</th>
                        <th class="text-right">DPP Faktur</th>
                        <th class="text-right">PPN Faktur</th>
                        <th>Nomor Aju BC</th>
                        <th class="text-right">DPP Dokumen BC</th>
                        <th class="text-right">Selisih</th>
                        <th>Status Reconcile</th>
                        <th>SPPB</th>
                        <th>Nama Barang</th>
                    </tr>
                </thead>
                <tbody id="gridBody">
                    <tr><td colspan="14" class="text-center" style="padding:20px;">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- View 2: Upload & Extract -->
    <div class="view-content" id="viewExtract" style="display:none;">
        <div class="extract-view">
            <div class="drop-zone" id="dropZonePdf" onclick="document.getElementById('filePdfInput').click()">
                <i class="fa-solid fa-file-pdf" style="font-size:28px; color:#dc2626; margin-bottom:6px;"></i>
                <div style="font-size:11px; font-weight:600;">Klik atau Drag & Drop Berkas PDF Faktur Pajak di Sini</div>
                <div style="font-size:9px; color:var(--text-muted);">Mendukung banyak file sekaligus (Bulk multi-select .pdf)</div>
                <input type="file" id="filePdfInput" accept=".pdf" multiple style="display:none;" onchange="handlePdfFiles(this.files)">
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:600; color:#dc2626;"><i class="fa-solid fa-terminal"></i> Log Ekstraksi Real-time</span>
                <button class="btn btn-success" id="btnSaveExtracted" onclick="saveExtractedRows()" style="display:none;">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Semua Hasil Ekstraksi ke Database
                </button>
            </div>
            <div class="terminal-box" id="terminalLog">Menunggu file PDF diunggah...</div>

            <!-- Staging Preview Extracted Table -->
            <div class="grid-wrap" style="border:1px solid var(--border-color); border-radius:4px; max-height:220px;">
                <table class="ant-grid" id="tblStagingExtracted">
                    <thead>
                        <tr>
                            <th style="width:30px;" class="text-center">#</th>
                            <th>File</th>
                            <th>Nomor Faktur</th>
                            <th>Tanggal</th>
                            <th>Penjual</th>
                            <th>Pembeli</th>
                            <th class="text-right">DPP</th>
                            <th class="text-right">PPN</th>
                            <th>Nomor Aju</th>
                            <th>Status Ekstrak</th>
                        </tr>
                    </thead>
                    <tbody id="stagingExtractedBody">
                        <tr><td colspan="10" class="text-center" style="padding:15px; color:var(--text-muted);">Belum ada data ekstraksi</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        const CURRENT_CMP_ID = '<?= addslashes($sessionCmpId) ?>';
        let currentData = [];
        let extractedBuffer = [];

        document.addEventListener('DOMContentLoaded', () => {
            loadAll();

            // Drag and drop for PDF
            const dz = document.getElementById('dropZonePdf');
            ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.style.opacity = '0.7'; }));
            ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.style.opacity = '1'; }));
            dz.addEventListener('drop', e => {
                const files = e.dataTransfer.files;
                if (files.length > 0) handlePdfFiles(files);
            });
        });

        function switchView(mode) {
            document.getElementById('tabBtnHistory').classList.toggle('active', mode === 'history');
            document.getElementById('tabBtnExtract').classList.toggle('active', mode === 'extract');
            document.getElementById('viewHistory').style.display = (mode === 'history') ? 'flex' : 'none';
            document.getElementById('viewExtract').style.display = (mode === 'extract') ? 'flex' : 'none';
        }

        function loadAll() {
            loadKPI();
            loadGridData();
        }

        async function loadKPI() {
            try {
                const s = document.getElementById('dtStart').value;
                const e = document.getElementById('dtEnd').value;
                const res = await fetch(`extract_pdf.php?action=kpi&start_date=${s}&end_date=${e}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
                const json = await res.json();
                if (json.status !== 'OK') return;

                const k = json.kpi;
                document.getElementById('kpiTotalFaktur').innerText = parseInt(k.total_faktur || 0).toLocaleString('id-ID');
                document.getElementById('kpiTotalMatch').innerText = parseInt(k.total_match || 0).toLocaleString('id-ID');
                document.getElementById('kpiTotalMismatch').innerText = parseInt(k.total_mismatch || 0).toLocaleString('id-ID');
                document.getElementById('kpiTotalEmptyAju').innerText = parseInt(k.total_empty_aju || 0).toLocaleString('id-ID');
            } catch(err) {
                console.error(err);
            }
        }

        async function loadGridData() {
            const tbody = document.getElementById('gridBody');
            tbody.innerHTML = '<tr><td colspan="14" class="text-center" style="padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data rekonsiliasi...</td></tr>';

            try {
                const s = document.getElementById('dtStart').value;
                const e = document.getElementById('dtEnd').value;
                const st = document.getElementById('selStatusFlt').value;
                const q = document.getElementById('txtSearch').value;

                const res = await fetch(`extract_pdf.php?action=get_data&start_date=${s}&end_date=${e}&status_filter=${st}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message);

                currentData = json.data || [];
                renderGrid(currentData);
            } catch(err) {
                tbody.innerHTML = `<tr><td colspan="14" class="text-center" style="color:var(--danger); padding:15px;">Gagal memuat: ${err.message}</td></tr>`;
            }
        }

        function renderGrid(rows) {
            const tbody = document.getElementById('gridBody');
            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="14" class="text-center" style="padding:20px; color:var(--text-muted);">Tidak ada data Faktur PDF untuk periode ini.</td></tr>';
                return;
            }

            let html = '';
            rows.forEach((r, idx) => {
                const dppStr = parseFloat(r.total_dpp || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                const ppnStr = parseFloat(r.total_ppn || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                const bcDppStr = parseFloat(r.total_dpp_bc || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });
                const diffStr = parseFloat(r.diff || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });

                let badge = '<span class="badge badge-success">MATCH</span>';
                if (Math.abs(r.diff) > 0.05 || r.status === 'MISMATCH') {
                    badge = '<span class="badge badge-danger">MISMATCH</span>';
                } else if (!r.nomor_aju) {
                    badge = '<span class="badge badge-warning">NO AJU</span>';
                }

                html += `
                    <tr data-id="${r.id}">
                        <td class="text-center"><input type="checkbox" class="row-chk" value="${r.id}"></td>
                        <td class="text-center">${idx + 1}</td>
                        <td style="font-weight:600; color:#dc2626;">${escapeHtml(r.nomor_faktur || '')}</td>
                        <td>${escapeHtml(r.tanggal_faktur || '')}</td>
                        <td>${escapeHtml(r.nama_penjual || '')}</td>
                        <td>${escapeHtml(r.nama_pembeli || '')}</td>
                        <td class="text-right" style="font-weight:600;">${dppStr}</td>
                        <td class="text-right">${ppnStr}</td>
                        <td style="font-weight:600; color:var(--info);">${escapeHtml(r.nomor_aju || '')}</td>
                        <td class="text-right">${bcDppStr}</td>
                        <td class="text-right" style="color:${r.diff != 0 ? 'var(--danger)' : 'var(--text-main)'}; font-weight:600;">${diffStr}</td>
                        <td>${badge}</td>
                        <td>${escapeHtml(r.sppb || '')}</td>
                        <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(r.nama_barang || '')}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // =========================================================================
        // CLIENT-SIDE MULTI-PDF EXTRACTOR & REGEX PARSER (Identik C# Logic)
        // =========================================================================
        async function handlePdfFiles(fileList) {
            if (!fileList || fileList.length === 0) return;

            const files = Array.from(fileList).filter(f => f.name.toLowerCase().endsWith('.pdf'));
            if (files.length === 0) {
                Swal.fire('Format Salah', 'Pilih berkas dengan ekstensi .pdf saja.', 'warning');
                return;
            }

            extractedBuffer = [];
            const term = document.getElementById('terminalLog');
            term.innerText = `Memulai ekstraksi ${files.length} berkas PDF...\n`;
            document.getElementById('btnSaveExtracted').style.display = 'none';

            const stagingBody = document.getElementById('stagingExtractedBody');
            stagingBody.innerHTML = '';

            let successCount = 0;
            let failCount = 0;

            for (let i = 0; i < files.length; i++) {
                const f = files[i];
                term.innerText += `\n[${i + 1}/${files.length}] Membaca: ${f.name} ...`;
                term.scrollTop = term.scrollHeight;

                try {
                    const text = await extractTextFromPdfFile(f);
                    const item = parsePdfText(text, f.name);

                    if (!item.nomor_faktur) {
                        throw new Error('Nomor Faktur tidak ditemukan dalam berkas PDF.');
                    }

                    item.file_path = f.name;
                    extractedBuffer.push(item);
                    successCount++;

                    term.innerText += ` -> OK (Faktur: ${item.nomor_faktur}, DPP: ${item.total_dpp.toLocaleString('id-ID')})`;

                    stagingBody.innerHTML += `
                        <tr>
                            <td class="text-center">${successCount}</td>
                            <td>${escapeHtml(f.name)}</td>
                            <td style="font-weight:600; color:#dc2626;">${escapeHtml(item.nomor_faktur)}</td>
                            <td>${escapeHtml(item.tanggal_faktur || '')}</td>
                            <td>${escapeHtml(item.nama_penjual || '')}</td>
                            <td>${escapeHtml(item.nama_pembeli || '')}</td>
                            <td class="text-right">${item.total_dpp.toLocaleString('id-ID')}</td>
                            <td class="text-right">${item.total_ppn.toLocaleString('id-ID')}</td>
                            <td style="color:var(--info); font-weight:600;">${escapeHtml(item.nomor_aju || '')}</td>
                            <td><span class="badge badge-success">OK</span></td>
                        </tr>
                    `;
                } catch(err) {
                    failCount++;
                    term.innerText += ` -> GAGAL: ${err.message}`;
                }
                term.scrollTop = term.scrollHeight;
            }

            term.innerText += `\n\n=== SELESAI: ${successCount} Berhasil, ${failCount} Gagal ===`;
            term.scrollTop = term.scrollHeight;

            if (extractedBuffer.length > 0) {
                document.getElementById('btnSaveExtracted').style.display = 'inline-flex';
                Swal.fire({
                    icon: 'success',
                    title: 'Ekstraksi Selesai',
                    text: `${successCount} dari ${files.length} PDF berhasil diekstrak. Klik tombol Simpan untuk memasukkannya ke database.`
                });
            } else {
                Swal.fire('Gagal', 'Tidak ada faktur yang berhasil diekstrak.', 'error');
            }
        }

        async function extractTextFromPdfFile(file) {
            const arrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            let fullText = '';

            const numPages = pdf.numPages;
            for (let p = 1; p <= numPages; p++) {
                // Sesuai logika C#: ambil halaman 1 dan halaman terakhir
                if (p === 1 || p === numPages) {
                    const page = await pdf.getPage(p);
                    const content = await page.getTextContent();
                    const pageText = content.items.map(item => item.str).join(' ');
                    fullText += pageText + '\n';
                }
            }
            return fullText;
        }

        function parsePdfText(text, fileName) {
            const res = {
                nomor_faktur: '',
                nama_penjual: '',
                npwp_penjual: '',
                nama_pembeli: '',
                npwp_pembeli: '',
                nama_barang: '',
                total_dpp: 0,
                total_ppn: 0,
                tanggal_faktur: '',
                nomor_aju: '',
                sppb: '',
                vat_type: '01'
            };

            // 1. Nomor Seri Faktur Pajak
            const mFaktur = text.match(/Kode dan Nomor Seri Faktur Pajak:\s*(\d+)/i) || text.match(/Faktur Pajak:\s*(\d{13,16})/i);
            if (mFaktur) res.nomor_faktur = mFaktur[1].trim();

            // 2. Penjual
            const mSeller = text.match(/Pengusaha Kena Pajak:\s*(?:Nama|Nama\s*:\s*)(.*?)(?:Alamat|NPWP)/is);
            if (mSeller) res.nama_penjual = mSeller[1].replace(/^:/, '').trim();
            const mSellerNpwp = text.match(/Pengusaha Kena Pajak:.*?NPWP\s*:\s*(\d+)/is);
            if (mSellerNpwp) res.npwp_penjual = mSellerNpwp[1].trim();

            // 3. Pembeli
            const mBuyer = text.match(/Pembeli Barang Kena Pajak\/Penerima Jasa Kena Pajak:\s*(?:Nama|Nama\s*:\s*)(.*?)(?:Alamat|NPWP)/is);
            if (mBuyer) res.nama_pembeli = mBuyer[1].replace(/^:/, '').trim();
            const mBuyerNpwp = text.match(/Pembeli Barang Kena Pajak\/Penerima Jasa Kena Pajak:.*?NPWP\s*:\s*(\d+)/is);
            if (mBuyerNpwp) res.npwp_pembeli = mBuyerNpwp[1].trim();

            // 4. Total DPP
            const mDpp = text.match(/Harga Jual\s*\/\s*Penggantian\s*\/\s*Uang Muka\s*\/\s*Termin(?!\s*\(Rp\))\s*([\d\.,]+)/i) 
                      || text.match(/Dasar Pengenaan Pajak\s*([\d\.,]+)/i);
            if (mDpp) res.total_dpp = parseIndoNumber(mDpp[1]);

            // 5. Total PPN
            const mPpn = text.match(/Jumlah PPN \(Pajak Pertambahan Nilai\)\s*([\d\.,]+)/i) 
                      || text.match(/Pajak Pertambahan Nilai\s*([\d\.,]+)/i);
            if (mPpn) res.total_ppn = parseIndoNumber(mPpn[1]);

            // 6. Tanggal Faktur
            const mDate = text.match(/(?:KOTA\s+ADM\.|KAB\.|KOTA)\s+[^,]{1,80},\s*(\d{1,2}\s+[A-Za-z]+\s+\d{4})/i)
                       || text.match(/(\d{1,2}\s+(?:Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+\d{4})/i);
            if (mDate) {
                res.tanggal_faktur = parseIndoDate(mDate[1].trim());
            }

            // 7. Nomor Aju
            const mAju = text.match(/([0-9]{6}[0-9A-Za-z]{20,26})/i) 
                      || text.match(/([A-Za-z0-9\-\.\/]+)(?:KOTA\s+ADM\.|KAB\.|KOTA)/i);
            if (mAju) {
                let a = mAju[1].trim();
                if (a.length >= 26) res.nomor_aju = a;
            }

            // 8. Nama Barang / Uraian
            const mBarang = text.match(/Nama Barang Kena Pajak.*?:\s*(.*?)(?:Harga Jual|Dasar Pengenaan)/is);
            if (mBarang) {
                res.nama_barang = mBarang[1].replace(/\s+/g, ' ').trim().substring(0, 500);
            }

            return res;
        }

        function parseIndoNumber(str) {
            if (!str) return 0;
            let s = str.replace(/Rp|\$/g, '').trim();
            if (s.includes(',') && s.includes('.')) {
                s = s.replace(/\./g, '').replace(',', '.');
            } else if (s.includes(',')) {
                s = s.replace(',', '.');
            }
            const n = parseFloat(s);
            return isNaN(n) ? 0 : n;
        }

        function parseIndoDate(str) {
            const months = {
                'januari': '01', 'februari': '02', 'maret': '03', 'april': '04', 'mei': '05', 'juni': '06',
                'juli': '07', 'agustus': '08', 'september': '09', 'oktober': '10', 'november': '11', 'desember': '12',
                'january': '01', 'february': '02', 'march': '03', 'may': '05', 'june': '06', 'july': '07', 'august': '08', 'october': '10', 'december': '12'
            };
            const parts = str.split(/\s+/);
            if (parts.length >= 3) {
                const day = parts[0].padStart(2, '0');
                const mKey = parts[1].toLowerCase();
                const month = months[mKey] || '01';
                const year = parts[2];
                return `${year}-${month}-${day}`;
            }
            return '';
        }

        async function saveExtractedRows() {
            if (extractedBuffer.length === 0) return;

            Swal.fire({
                title: 'Menyimpan Hasil...',
                text: `Menyimpan ${extractedBuffer.length} data ekstraksi PDF ke database`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const res = await fetch(`extract_pdf.php?action=save_batch&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ rows: extractedBuffer })
                });
                const json = await res.json();
                Swal.close();

                if (json.status !== 'OK') throw new Error(json.message);

                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil Simpan',
                    text: json.message
                });

                switchView('history');
                loadAll();
            } catch(err) {
                Swal.fire('Gagal Simpan', err.message, 'error');
            }
        }

        async function reconcileBc() {
            Swal.fire({
                title: 'Merekonsiliasi Data...',
                text: 'Mencocokkan DPP Faktur Pajak dengan DPP Dokumen Pabean CEISA...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const res = await fetch(`extract_pdf.php?action=reconcile_bc&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
                const json = await res.json();
                Swal.close();

                if (json.status !== 'OK') throw new Error(json.message);

                Swal.fire({
                    icon: 'success',
                    title: 'Rekonsiliasi Selesai',
                    text: json.message
                });

                loadAll();
            } catch(err) {
                Swal.fire('Gagal Rekonsiliasi', err.message, 'error');
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
                title: 'Hapus Data Faktur PDF?',
                text: `Apakah Anda yakin ingin menghapus ${ids.length} data terpilih?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus'
            });

            if (!confirm.isConfirmed) return;

            try {
                const res = await fetch(`extract_pdf.php?action=delete_selected&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
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
                'Nomor Faktur': r.nomor_faktur,
                'Tanggal': r.tanggal_faktur,
                'Penjual': r.nama_penjual,
                'NPWP Penjual': r.npwp_penjual,
                'Pembeli': r.nama_pembeli,
                'NPWP Pembeli': r.npwp_pembeli,
                'DPP Faktur': r.total_dpp,
                'PPN Faktur': r.total_ppn,
                'Nomor Aju BC': r.nomor_aju,
                'DPP Dokumen BC': r.total_dpp_bc,
                'Selisih': r.diff,
                'Status': r.status,
                'SPPB': r.sppb,
                'Nama Barang': r.nama_barang
            }));

            const ws = XLSX.utils.json_to_sheet(exportRows);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Extracted_PDF");
            XLSX.writeFile(wb, `Extracted_PDF_${CURRENT_CMP_ID}_${new Date().toISOString().slice(0,10)}.xlsx`);
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

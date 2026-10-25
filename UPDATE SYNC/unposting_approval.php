<?php
/**
 * CEISA 4.0 - Admin Inventory: Approval Revisi / UnPosting (unposting_approval.php)
 * Mengadopsi w_bc_ceisa_unposting_appv.srw & d_bc_ceisa_req_unpost_appv.srd & d_bc_1ab2_revisi_show.srd
 * Pengelolaan Pengajuan dan Persetujuan Unposting Dokumen IT Inventory (bc_ceisa_req_unpost & bc_1ab2_revisi)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'ADMIN');
$action = $_REQUEST['action'] ?? '';
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// -------------------------------------------------------------
// 1. AJAX API: AMBIL DAFTAR PENGAJUAN UNPOSTING
// -------------------------------------------------------------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $tgl1   = trim($_GET['tgl1'] ?? date('Y-01-01'));
    $tgl2   = trim($_GET['tgl2'] ?? date('Y-m-d'));
    $status = trim($_GET['status'] ?? '');
    $search = trim($_GET['q'] ?? '');
    $cmpId  = !empty($_GET['cmp_id']) ? trim($_GET['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : ($_SESSION['cmp_id'] ?? ''));

    $where = [];
    if (!empty($cmpId) && $cmpId !== 'ALL') {
        $where[] = "u.cmp_id = '" . $koneksi->real_escape_string($cmpId) . "'";
    }
    if (!empty($tgl1) && !empty($tgl2)) {
        $where[] = "TGL_REQ BETWEEN '" . $koneksi->real_escape_string($tgl1) . "' AND '" . $koneksi->real_escape_string($tgl2) . "'";
    }
    if ($status === 'P') {
        $where[] = "(STATUS IS NULL OR STATUS = '' OR STATUS = ' ' OR STATUS = 'P')";
    } elseif ($status === 'Y') {
        $where[] = "STATUS = 'Y'";
    } elseif ($status === 'X' || $status === 'N') {
        $where[] = "(STATUS = 'X' OR STATUS = 'N')";
    }

    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(NOMOR_AJU LIKE '%$s%' OR NAMA_BS LIKE '%$s%' OR USER_REQ LIKE '%$s%' OR ALASAN LIKE '%$s%' OR BC LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT ROWID, NOMOR_AJU, BC, NAMA_BS, TGL_REQ, USER_REQ, ALASAN, STATUS, USER_APPV, TGL_APPV,
            (SELECT COUNT(1) FROM bc_1ab2_revisi r WHERE r.NOMOR_AJU = u.NOMOR_AJU AND r.CEK = u.ROWID) AS JUMLAH_REVISI,
            (SELECT COUNT(1) FROM bc_1ab2 b WHERE b.NOMOR_AJU = u.NOMOR_AJU) AS JUMLAH_POSTING
            FROM bc_ceisa_req_unpost u
            $whereSql
            ORDER BY ROWID DESC, TGL_REQ DESC
            LIMIT 500";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // KPI Counters
    $kpiTotal   = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_req_unpost")->fetch_row()[0] ?? 0;
    $kpiPending = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_req_unpost WHERE STATUS IS NULL OR STATUS = '' OR STATUS = ' ' OR STATUS = 'P'")->fetch_row()[0] ?? 0;
    $kpiAppv    = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_req_unpost WHERE STATUS = 'Y'")->fetch_row()[0] ?? 0;
    $kpiReject  = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_req_unpost WHERE STATUS IN ('X', 'N')")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'data'   => $data,
        'count'  => count($data),
        'kpi'    => [
            'total'   => (int)$kpiTotal,
            'pending' => (int)$kpiPending,
            'appv'    => (int)$kpiAppv,
            'reject'  => (int)$kpiReject
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: PROSES APPROVAL (SETUJUI / TOLAK)
// -------------------------------------------------------------
if ($action === 'process_approval') {
    header('Content-Type: application/json; charset=utf-8');
    $rowid   = (int)($_POST['rowid'] ?? 0);
    $status  = strtoupper(trim($_POST['status'] ?? '')); // 'Y' (Approve) atau 'X' (Reject)
    $nomorAju = trim($_POST['nomor_aju'] ?? '');

    if ($rowid <= 0 || empty($nomorAju) || !in_array($status, ['Y', 'X'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Parameter tidak valid.']);
        exit;
    }

    $koneksi->begin_transaction();

    try {
        if ($status === 'Y') {
            // Sesuai PowerBuilder: insert into bc_1ab2_revisi from bc_1ab2, then delete from bc_1ab2
            $sqlArchive = "INSERT INTO bc_1ab2_revisi (
                CEK, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG, POS_TARIF, 
                URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, SJ, INVOICE, FAKTUR, NOMOR_AJU, 
                TUJUAN_PEMASUKAN, TUJUAN_PENGIRIMAN, NPWP_BS, SERI_BARANG, HARGA_PENYERAHAN, CIF, NETTO, 
                JUMLAH_KEMASAN, DETAIL_KEMASAN, SESUAI, QTY_AKTUAL, TGL_PROSES, GROSSP, GROSS, NDPBM, 
                MEMO, CRT_DT, CRT_USER
            )
            SELECT 
                ?, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG, POS_TARIF, 
                URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, SJ, INVOICE, FAKTUR, NOMOR_AJU, 
                TUJUAN_PEMASUKAN, TUJUAN_PENGIRIMAN, NPWP_BS, SERI_BARANG, HARGA_PENYERAHAN, CIF, NETTO, 
                JUMLAH_KEMASAN, DETAIL_KEMASAN, SESUAI, QTY_AKTUAL, TGL_PROSES, GROSSP, GROSS, NDPBM, 
                MEMO, CRT_DT, CRT_USER
            FROM bc_1ab2 
            WHERE NOMOR_AJU = ?";
            
            $stmtArch = $koneksi->prepare($sqlArchive);
            if (!$stmtArch) {
                throw new Exception("Gagal menyiapkan query arsip: " . $koneksi->error);
            }
            $stmtArch->bind_param("is", $rowid, $nomorAju);
            $stmtArch->execute();
            $archivedCount = $stmtArch->affected_rows;
            $stmtArch->close();

            // Hapus dari bc_1ab2 (Unposting)
            $stmtDel = $koneksi->prepare("DELETE FROM bc_1ab2 WHERE NOMOR_AJU = ?");
            if (!$stmtDel) {
                throw new Exception("Gagal menyiapkan query hapus posting: " . $koneksi->error);
            }
            $stmtDel->bind_param("s", $nomorAju);
            $stmtDel->execute();
            $deletedCount = $stmtDel->affected_rows;
            $stmtDel->close();
        }

        // Update status pengajuan
        $today = date('Y-m-d');
        $stmtUpd = $koneksi->prepare("UPDATE bc_ceisa_req_unpost SET STATUS = ?, USER_APPV = ?, TGL_APPV = ? WHERE ROWID = ?");
        if (!$stmtUpd) {
            throw new Exception("Gagal menyiapkan update status: " . $koneksi->error);
        }
        $stmtUpd->bind_param("sssi", $status, $sessionUser, $today, $rowid);
        $stmtUpd->execute();
        $stmtUpd->close();

        $koneksi->commit();

        $msg = ($status === 'Y') 
            ? "Pengajuan Unposting untuk AJU $nomorAju berhasil DISETUJUI. $archivedCount baris data berhasil diarsipkan ke bc_1ab2_revisi dan dikeluarkan dari IT Inventory."
            : "Pengajuan Unposting untuk AJU $nomorAju berhasil DITOLAK.";

        echo json_encode(['status' => 'OK', 'message' => $msg]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: DETAIL ARSIP REVISI (bc_1ab2_revisi)
// -------------------------------------------------------------
if ($action === 'detail_revisi') {
    header('Content-Type: application/json; charset=utf-8');
    $nomorAju = trim($_GET['nomor_aju'] ?? '');
    $rowid    = (int)($_GET['rowid'] ?? 0);

    $sql = "SELECT SERI_BARANG, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS,
            KODE_BARANG, URAIAN, KLAS, UNIT, QTY, QTY_AKTUAL, NILAI, HARGA_PENYERAHAN, CIF, NETTO, CRT_USER, CRT_DT
            FROM bc_1ab2_revisi
            WHERE NOMOR_AJU = ? ";
    $params = [$nomorAju];
    $types = "s";
    if ($rowid > 0) {
        $sql .= " AND CEK = ? ";
        $params[] = $rowid;
        $types .= "i";
    }
    $sql .= " ORDER BY SERI_BARANG ASC";

    $stmt = $koneksi->prepare($sql);
    $data = [];
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
        $stmt->close();
    }

    echo json_encode(['status' => 'OK', 'data' => $data, 'count' => count($data)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($themeParam) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approval Revisi / UnPosting - Admin IT Inventory</title>
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
        .ant-btn-danger {
            background: #ff4d4f;
            border-color: #ff4d4f;
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
        .ant-badge-red { background: #fff1f0; color: #f5222d; border: 1px solid #ffa39e; }

        /* MODAL */
        .ant-modal-mask {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .ant-modal {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            width: 85%;
            max-width: 950px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .ant-modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            font-size: 11.5px;
        }
        .ant-modal-body {
            padding: 10px 14px;
            overflow-y: auto;
            flex: 1;
        }
        .ant-modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border-card);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--bg-filter);
        }
    </style>
</head>
<body>

<div class="ant-card">
    <div class="ant-card-head">
        <div class="ant-card-head-title">
            <span>✔️</span> Approval Revisi / UnPosting Dokumen IT Inventory
        </div>
        <div style="display:flex; gap:8px;">
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
            <label>Tanggal Request Mulai</label>
            <input type="date" id="filterTgl1" class="filter-control" value="<?= date('Y-01-01') ?>">
        </div>
        <div class="filter-col">
            <label>Tanggal Request Sampai</label>
            <input type="date" id="filterTgl2" class="filter-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="filter-col">
            <label>Status Permohonan</label>
            <select id="filterStatus" class="filter-control" style="width: 140px;">
                <option value="">Semua Status</option>
                <option value="P" selected>Diajukan / Menunggu</option>
                <option value="Y">Disetujui (Approve)</option>
                <option value="X">Ditolak (Reject)</option>
            </select>
        </div>
        <div class="filter-col">
            <label>Kata Kunci Cari</label>
            <input type="text" id="filterSearch" class="filter-control" placeholder="No Aju, User, Alasan..." style="width: 180px;">
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
            <span style="color:var(--text-muted);">Total Request:</span>
            <span class="kpi-val" id="kpiTotal">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#faad14;">⏳ Menunggu Approval:</span>
            <span class="kpi-val" id="kpiPending" style="color:#faad14;">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#52c41a;">✔️ Disetujui (Unposted):</span>
            <span class="kpi-val" id="kpiAppv" style="color:#52c41a;">0</span>
        </div>
        <div class="kpi-chip">
            <span style="color:#ff4d4f;">❌ Ditolak:</span>
            <span class="kpi-val" id="kpiReject" style="color:#ff4d4f;">0</span>
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
            💡 Klik judul kolom untuk mengurutkan (Sort ▲▼)
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="ant-table-wrapper">
        <div class="table-scroll">
            <table class="ant-table" id="mainDataTable">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>Tanggal Request</th>
                        <th>Dokumen BC</th>
                        <th>Nomor Aju</th>
                        <th>Nama Entitas / Perusahaan</th>
                        <th>User Pemohon</th>
                        <th>Alasan Unposting</th>
                        <th style="text-align:center;">Status</th>
                        <th>User Approval</th>
                        <th>Tanggal Approval</th>
                        <th style="text-align:center;">Arsip Revisi</th>
                        <th style="text-align:center;">Aksi Persetujuan</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td colspan="12" style="text-align:center; padding:30px; color:var(--text-muted);">
                            Memuat data pengajuan unposting...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL DETAIL ARSIP REVISI -->
<div class="ant-modal-mask" id="detailModal">
    <div class="ant-modal">
        <div class="ant-modal-header">
            <span>Detail Arsip Revisi Data (bc_1ab2_revisi) - <span id="modalAjuTitle" style="color:var(--primary); font-family:monospace;"></span></span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeDetailModal()">✕</button>
        </div>
        <div class="ant-modal-body" id="modalDetailBody">
            Memuat detail...
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeDetailModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
let currentData = [];

function loadData() {
    const tgl1 = document.getElementById('filterTgl1').value;
    const tgl2 = document.getElementById('filterTgl2').value;
    const status = document.getElementById('filterStatus').value;
    const q = document.getElementById('filterSearch').value;

    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:24px; color:var(--text-muted);">Sedang mengambil data...</td></tr>';

    fetch(`unposting_approval.php?action=list&tgl1=${encodeURIComponent(tgl1)}&tgl2=${encodeURIComponent(tgl2)}&status=${encodeURIComponent(status)}&q=${encodeURIComponent(q)}`)
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK') {
                currentData = res.data || [];
                renderTable(currentData);
                document.getElementById('kpiTotal').innerText = res.kpi.total;
                document.getElementById('kpiPending').innerText = res.kpi.pending;
                document.getElementById('kpiAppv').innerText = res.kpi.appv;
                document.getElementById('kpiReject').innerText = res.kpi.reject;
                document.getElementById('rowCountBadge').innerText = currentData.length + ' Baris Data';
            } else {
                tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; color:red;">${res.message || 'Gagal memuat data.'}</td></tr>`;
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; color:red;">Koneksi error: ${err}</td></tr>`;
        });
}

function renderTable(data) {
    const tbody = document.getElementById('tableBody');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:24px; color:var(--text-muted);">📭 Tidak ada data pengajuan unposting yang cocok dengan filter.</td></tr>';
        return;
    }

    let html = '';
    data.forEach((r, idx) => {
        const isPending = (!r.STATUS || r.STATUS === '' || r.STATUS === ' ' || r.STATUS === 'P');
        const isAppv = (r.STATUS === 'Y');
        const isReject = (r.STATUS === 'X' || r.STATUS === 'N');

        let statusBadge = '';
        if (isAppv) statusBadge = '<span class="ant-badge ant-badge-green">Disetujui</span>';
        else if (isReject) statusBadge = '<span class="ant-badge ant-badge-red">Ditolak</span>';
        else statusBadge = '<span class="ant-badge ant-badge-gold">Diajukan</span>';

        const revCount = parseInt(r.JUMLAH_REVISI || 0);
        const postCount = parseInt(r.JUMLAH_POSTING || 0);

        html += `<tr>
            <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
            <td>${escapeHtml(r.TGL_REQ || '-')}</td>
            <td><span class="ant-badge ant-badge-blue">${escapeHtml(r.BC || '-')}</span></td>
            <td style="font-family:monospace; font-weight:600; color:var(--primary);">${escapeHtml(r.NOMOR_AJU || '-')}</td>
            <td>${escapeHtml(r.NAMA_BS || '-')}</td>
            <td style="font-weight:600;">${escapeHtml(r.USER_REQ || '-')}</td>
            <td style="max-width:280px; white-space:normal;" title="${escapeHtml(r.ALASAN || '')}">${escapeHtml(r.ALASAN || '-')}</td>
            <td style="text-align:center;">${statusBadge}</td>
            <td>${escapeHtml(r.USER_APPV || '-')}</td>
            <td>${escapeHtml(r.TGL_APPV || '-')}</td>
            <td style="text-align:center;">
                <button type="button" class="ant-btn ant-btn-sm" onclick="showDetailRevisi('${escapeHtml(r.NOMOR_AJU)}', ${r.ROWID})">
                    🔍 Detail (${revCount})
                </button>
            </td>
            <td style="text-align:center;">
                ${isPending ? `
                    <div style="display:inline-flex; gap:4px;">
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="processApproval(${r.ROWID}, '${escapeHtml(r.NOMOR_AJU)}', 'Y', ${postCount})">
                            ✔️ Setujui
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="processApproval(${r.ROWID}, '${escapeHtml(r.NOMOR_AJU)}', 'X', 0)">
                            ❌ Tolak
                        </button>
                    </div>
                ` : `
                    <span style="font-size:10px; color:var(--text-muted);">Selesai</span>
                `}
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function processApproval(rowid, nomorAju, status, postingCount) {
    const isAppv = (status === 'Y');
    const titleText = isAppv ? `Persetujuan Unposting AJU ${nomorAju}` : `Tolak Permohonan AJU ${nomorAju}`;
    const descText = isAppv 
        ? `Apakah Anda yakin ingin <b>MENYETUJUI</b> unposting nomor aju <b>${nomorAju}</b>?<br><br><span style="color:#faad14;">Perhatian: ${postingCount} baris data terkait di tabel bc_1ab2 akan diarsipkan ke bc_1ab2_revisi dan dikeluarkan dari IT Inventory.</span>`
        : `Apakah Anda yakin ingin <b>MENOLAK</b> permohonan unposting nomor aju <b>${nomorAju}</b>?`;

    Swal.fire({
        title: titleText,
        html: descText,
        icon: isAppv ? 'question' : 'warning',
        showCancelButton: true,
        confirmButtonColor: isAppv ? '#52c41a' : '#ff4d4f',
        cancelButtonColor: '#8c8c8c',
        confirmButtonText: isAppv ? 'Ya, Setujui Unposting' : 'Ya, Tolak Permohonan',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('rowid', rowid);
            fd.append('nomor_aju', nomorAju);
            fd.append('status', status);

            fetch('unposting_approval.php?action=process_approval', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'OK') {
                    showSuccess(res.message, 'Berhasil Diproses');
                    loadData();
                } else {
                    showError(res.message || 'Gagal memproses approval.');
                }
            })
            .catch(err => {
                showError('Koneksi bermasalah: ' + err);
            });
        }
    });
}

function showDetailRevisi(nomorAju, rowid) {
    document.getElementById('modalAjuTitle').innerText = nomorAju;
    document.getElementById('detailModal').style.display = 'flex';
    document.getElementById('modalDetailBody').innerHTML = 'Memuat arsip revisi...';

    fetch(`unposting_approval.php?action=detail_revisi&nomor_aju=${encodeURIComponent(nomorAju)}&rowid=${rowid}`)
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK') {
                const rows = res.data || [];
                if (rows.length === 0) {
                    document.getElementById('modalDetailBody').innerHTML = '<p style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada data arsip revisi untuk nomor aju ini.</p>';
                    return;
                }

                let t = `<table class="ant-table" style="font-size:10px;">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Seri</th>
                            <th>Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th>Klas</th>
                            <th>Unit</th>
                            <th style="text-align:right;">Qty Aktual</th>
                            <th style="text-align:right;">Nilai</th>
                            <th>Dok Pabean</th>
                            <th>No Bukti</th>
                            <th>User Arsip</th>
                        </tr>
                    </thead>
                    <tbody>`;
                rows.forEach((r, i) => {
                    t += `<tr>
                        <td>${i + 1}</td>
                        <td>${r.SERI_BARANG}</td>
                        <td style="font-family:monospace; font-weight:600; color:var(--primary);">${escapeHtml(r.KODE_BARANG || '-')}</td>
                        <td>${escapeHtml(r.URAIAN || '-')}</td>
                        <td>${escapeHtml(r.KLAS || '-')}</td>
                        <td>${escapeHtml(r.UNIT || '-')}</td>
                        <td style="text-align:right; font-weight:600;">${parseFloat(r.QTY_AKTUAL || 0).toLocaleString()}</td>
                        <td style="text-align:right; color:#52c41a;">${parseFloat(r.NILAI || 0).toLocaleString()}</td>
                        <td>${escapeHtml(r.BC || '-')} ${escapeHtml(r.NOMOR_DAFTAR || '')}</td>
                        <td>${escapeHtml(r.NO_BUKTI || '-')}</td>
                        <td>${escapeHtml(r.CRT_USER || '-')}</td>
                    </tr>`;
                });
                t += `</tbody></table>`;
                document.getElementById('modalDetailBody').innerHTML = t;
            } else {
                document.getElementById('modalDetailBody').innerHTML = `<p style="color:red;">${res.message || 'Gagal memuat detail.'}</p>`;
            }
        })
        .catch(err => {
            document.getElementById('modalDetailBody').innerHTML = `<p style="color:red;">Error: ${err}</p>`;
        });
}

function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}

function resetFilter() {
    document.getElementById('filterTgl1').value = '<?= date('Y-01-01') ?>';
    document.getElementById('filterTgl2').value = '<?= date('Y-m-d') ?>';
    document.getElementById('filterStatus').value = 'P';
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
        const cellA = (a.cells[colIndex] ? a.cells[colIndex].innerText : '').trim();
        const cellB = (b.cells[colIndex] ? b.cells[colIndex].innerText : '').trim();

        const cleanA = cellA.replace(/,/g, '').replace(/[^\d.-]/g, '');
        const cleanB = cellB.replace(/,/g, '').replace(/[^\d.-]/g, '');
        const numA = parseFloat(cleanA);
        const numB = parseFloat(cleanB);

        let comp = 0;
        if (!isNaN(numA) && !isNaN(numB) && cellA !== '' && cellB !== '' && !cellA.includes('-') && !cellA.match(/^[A-Za-z]/)) {
            comp = numA - numB;
        } else if (cellA.match(/^\d{4}-\d{2}-\d{2}/) && cellB.match(/^\d{4}-\d{2}-\d{2}/)) {
            comp = Date.parse(cellA) - Date.parse(cellB);
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
        if (idx === 0 || idx >= 10) return;
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
        if (r.cells.length > 11) r.deleteCell(11); // remove action column
        if (r.style.display === 'none') r.remove();
    });

    const wb = XLSX.utils.table_to_book(cloned, { sheet: "Approval_Unposting" });
    XLSX.writeFile(wb, "Approval_Unposting_" + new Date().toISOString().slice(0,10) + ".xlsx");
}

function exportToCSV() {
    const table = document.getElementById("mainDataTable");
    let csv = [];
    for (let row of table.rows) {
        let cols = [];
        for (let j = 0; j < row.cells.length - 1; j++) {
            cols.push('"' + row.cells[j].innerText.replace(/"/g, '""').trim() + '"');
        }
        csv.push(cols.join(","));
    }
    const blob = new Blob([csv.join("\n")], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "Approval_Unposting_" + new Date().toISOString().slice(0,10) + ".csv";
    link.click();
}

function copyTableClipboard() {
    const table = document.getElementById("mainDataTable");
    let csv = [];
    for (let row of table.rows) {
        let cols = [];
        for (let j = 0; j < row.cells.length - 1; j++) {
            cols.push('"' + row.cells[j].innerText.replace(/"/g, '""').trim() + '"');
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

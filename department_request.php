<?php
/**
 * CEISA 4.0 & ERP Hub - Department Request (Permintaan Pengeluaran Barang Antar Departemen / BPPB)
 * Modul Permintaan Barang Lintas Departemen (Maintenance, Moldshop, Produksi, GA, Warehouse, dll.)
 * Mendukung Pemilihan Barang dari Dokumen Pabean (bc_1ab2), Riwayat Internal Issue (bc_issue_dtl),
 * Master Material (bc_material), maupun Input Part Baru secara Mandiri.
 * Mendukung Multi-Tenant & Multi-Plant Framas Indonesia (FRM-1, FRM-2, FRM-3, PDPLB).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

// -------------------------------------------------------------
// 1. AUTO-HEAL: STRUKTUR TABEL bc_issue_dept_req_hdr & dtl
// -------------------------------------------------------------
function ensureDeptReqTables(mysqli $koneksi): void {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_issue_dept_req_hdr` (
      `cmp_id` varchar(30) NOT NULL DEFAULT 'FRM-1',
      `id` varchar(50) NOT NULL,
      `req_no` varchar(50) DEFAULT NULL,
      `no_bukti` varchar(50) DEFAULT NULL,
      `req_date` date DEFAULT NULL,
      `tgl` varchar(10) DEFAULT NULL,
      `need_date` date DEFAULT NULL,
      `dept_pemohon` varchar(50) DEFAULT NULL,
      `dept_fr` varchar(50) DEFAULT NULL,
      `dept_tujuan` varchar(50) DEFAULT 'GUDANG',
      `dept_to` varchar(50) DEFAULT 'GUDANG',
      `klas` varchar(50) DEFAULT 'General',
      `pic` varchar(50) DEFAULT '',
      `wo_no` varchar(50) DEFAULT '',
      `wo_id` varchar(50) DEFAULT '',
      `sj_no` varchar(50) DEFAULT NULL,
      `sj_dt` varchar(10) DEFAULT NULL,
      `setby` varchar(50) DEFAULT NULL,
      `created_by` varchar(50) DEFAULT NULL,
      `iscancel` varchar(1) DEFAULT 'N',
      `so_no` varchar(50) DEFAULT NULL,
      `po_no` varchar(50) DEFAULT NULL,
      `buyer` varchar(30) DEFAULT NULL,
      `style` varchar(50) DEFAULT NULL,
      `note` text DEFAULT NULL,
      `status` varchar(20) NOT NULL DEFAULT 'PENDING',
      `approved_by_1` varchar(50) DEFAULT NULL,
      `approved_at_1` datetime DEFAULT NULL,
      `rejected_by` varchar(50) DEFAULT NULL,
      `reject_reason` varchar(255) DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`cmp_id`,`id`),
      KEY `idx_req_no` (`req_no`),
      KEY `idx_no_bukti` (`no_bukti`),
      KEY `idx_date_dept` (`req_date`,`dept_pemohon`,`dept_tujuan`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_issue_dept_req_dtl` (
      `cmp_id` varchar(30) NOT NULL DEFAULT 'FRM-1',
      `id` varchar(50) NOT NULL,
      `id_hdr` varchar(50) NOT NULL,
      `req_no` varchar(50) DEFAULT '',
      `seq` int(11) DEFAULT NULL,
      `kode_barang` varchar(100) NOT NULL,
      `nama_barang` varchar(255) NOT NULL,
      `sat` varchar(10) NOT NULL DEFAULT 'PCE',
      `klas` varchar(50) DEFAULT '',
      `qty_req` decimal(18,4) NOT NULL DEFAULT 0.0000,
      `qty` decimal(18,4) DEFAULT 0.0000,
      `qty_appr` decimal(18,4) NOT NULL DEFAULT 0.0000,
      `qty_issued` decimal(18,4) NOT NULL DEFAULT 0.0000,
      `qty_sj` decimal(18,4) DEFAULT NULL,
      `wo` varchar(50) DEFAULT NULL,
      `setby` varchar(50) DEFAULT NULL,
      `mat_id` varchar(50) DEFAULT NULL,
      `rack` varchar(50) DEFAULT NULL,
      `qty_trace` decimal(18,4) DEFAULT NULL,
      `harga` decimal(18,4) DEFAULT NULL,
      `source_type` varchar(30) DEFAULT 'bc_1ab2',
      `ref_nomor_aju` varchar(50) DEFAULT NULL,
      `ref_no_daftar` varchar(50) DEFAULT NULL,
      `ref_no_bukti` varchar(100) DEFAULT NULL,
      `note` varchar(255) DEFAULT NULL,
      `status` varchar(20) NOT NULL DEFAULT 'PENDING',
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`cmp_id`,`id`),
      KEY `idx_hdr` (`id_hdr`),
      KEY `idx_kd_brg` (`kode_barang`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensureDeptReqTables($koneksi);

// -------------------------------------------------------------
// 2. SESI & MULTI-TENANT CONTEXT
// -------------------------------------------------------------
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '');
if (!empty($_GET['cmp_id'])) {
    $sessionCmpId = trim($_GET['cmp_id']);
}
if (empty($sessionCmpId)) {
    $sessionCmpId = 'FRM-1';
}
$isFramasMultiPlant = in_array($sessionCmpId, ['FRM-1', 'FRM-2', 'FRM-3', 'PDPLB', 'SYSTEM', 'ALL', '']);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$currentUser = $_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? 'user');
$currentRole = $_SESSION['user_role'] ?? 'operator';
$theme = $_GET['theme'] ?? ($_COOKIE['ceisa_theme'] ?? 'light');

// Filter query multi-plant
$cleanCmp = $koneksi->real_escape_string($sessionCmpId);
if ($isFramasMultiPlant && ($sessionCmpId === 'ALL' || $sessionCmpId === 'SYSTEM')) {
    $cmpFilterHdr = " (cmp_id IN ('FRM-1', 'FRM-2', 'FRM-3', 'PDPLB') OR cmp_id = '' OR cmp_id IS NULL) ";
    $cmpFilter1ab2 = " (cmp_id IN ('FRM-1', 'FRM-2', 'FRM-3', 'PDPLB') OR cmp_id = '' OR cmp_id IS NULL) ";
} elseif ($isFramasMultiPlant) {
    // Satu entitas Framas: bisa melihat data antar plant Framas
    $cmpFilterHdr = " (cmp_id = '{$cleanCmp}' OR cmp_id IN ('FRM-1', 'FRM-2', 'FRM-3', 'PDPLB')) ";
    $cmpFilter1ab2 = " (cmp_id IN ('FRM-1', 'FRM-2', 'FRM-3', 'PDPLB') OR cmp_id = '' OR cmp_id IS NULL) ";
} else {
    $cmpFilterHdr = " cmp_id = '{$cleanCmp}' ";
    $cmpFilter1ab2 = " (cmp_id = '{$cleanCmp}' OR cmp_id = '' OR cmp_id IS NULL) ";
}

// -------------------------------------------------------------
// 3. AJAX ENDPOINTS
// -------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

// --- A. LIST PERMINTAAN BARANG ---
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['q'] ?? '');
    $status = strtoupper(trim($_GET['status'] ?? ''));
    $dept   = trim($_GET['dept'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = [$cmpFilterHdr];
    if (!empty($status) && $status !== 'ALL') {
        $stEsc = $koneksi->real_escape_string($status);
        $where[] = "status = '{$stEsc}'";
    }
    if (!empty($dept) && $dept !== 'ALL') {
        $dpEsc = $koneksi->real_escape_string($dept);
        $where[] = "(dept_pemohon = '{$dpEsc}' OR dept_fr = '{$dpEsc}')";
    }
    if (!empty($search)) {
        $sc = $koneksi->real_escape_string($search);
        $where[] = "(req_no LIKE '%{$sc}%' OR no_bukti LIKE '%{$sc}%' OR dept_pemohon LIKE '%{$sc}%' OR wo_no LIKE '%{$sc}%' OR pic LIKE '%{$sc}%' OR note LIKE '%{$sc}%')";
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    // Hitung total dan counter KPI
    $kpiSql = "SELECT 
        COUNT(*) as cnt_total,
        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as cnt_pending,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as cnt_approved,
        SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as cnt_completed,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) as cnt_rejected,
        SUM(CASE WHEN status = 'DRAFT' THEN 1 ELSE 0 END) as cnt_draft
        FROM bc_issue_dept_req_hdr WHERE {$cmpFilterHdr}";
    $kpiRes = $koneksi->query($kpiSql)->fetch_assoc();

    $qCount = $koneksi->query("SELECT COUNT(*) as cnt FROM bc_issue_dept_req_hdr {$whereSql}");
    $totalFiltered = (int)($qCount->fetch_assoc()['cnt'] ?? 0);

    $sqlData = "SELECT h.*,
        COALESCE(h.req_no, h.no_bukti) as display_req_no,
        COALESCE(h.req_date, h.tgl) as display_req_date,
        COALESCE(h.dept_pemohon, h.dept_fr) as display_dept_pemohon,
        COALESCE(h.dept_tujuan, h.dept_to) as display_dept_tujuan,
        COALESCE(h.wo_no, h.wo_id) as display_wo_no,
        (SELECT COUNT(*) FROM bc_issue_dept_req_dtl d WHERE d.id_hdr = h.id AND d.cmp_id = h.cmp_id) as item_count,
        (SELECT COALESCE(SUM(COALESCE(d.qty_req, d.qty, 0)), 0) FROM bc_issue_dept_req_dtl d WHERE d.id_hdr = h.id AND d.cmp_id = h.cmp_id) as total_qty
        FROM bc_issue_dept_req_hdr h
        {$whereSql}
        ORDER BY COALESCE(h.req_date, h.tgl) DESC, h.created_at DESC
        LIMIT {$offset}, {$limit}";

    $res = $koneksi->query($sqlData);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'kpi' => $kpiRes,
        'total' => $totalFiltered,
        'page' => $page,
        'limit' => $limit,
        'data' => $rows
    ]);
    exit;
}

// --- B. DETAIL PERMINTAAN ---
if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $qH = $koneksi->query("SELECT h.*,
        COALESCE(h.req_no, h.no_bukti) as display_req_no,
        COALESCE(h.req_date, h.tgl) as display_req_date,
        COALESCE(h.dept_pemohon, h.dept_fr) as display_dept_pemohon,
        COALESCE(h.dept_tujuan, h.dept_to) as display_dept_tujuan,
        COALESCE(h.wo_no, h.wo_id) as display_wo_no
        FROM bc_issue_dept_req_hdr h 
        WHERE h.id = '{$escId}' LIMIT 1");

    if (!$qH || $qH->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data permintaan tidak ditemukan.']);
        exit;
    }
    $header = $qH->fetch_assoc();

    $qD = $koneksi->query("SELECT d.*,
        COALESCE(d.qty_req, d.qty, 0) as display_qty_req,
        COALESCE(d.qty_appr, d.qty, 0) as display_qty_appr,
        COALESCE(d.qty_issued, 0) as display_qty_issued
        FROM bc_issue_dept_req_dtl d 
        WHERE d.id_hdr = '{$escId}' 
        ORDER BY d.seq ASC, d.id ASC");

    $items = [];
    if ($qD) {
        while ($r = $qD->fetch_assoc()) {
            $items[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'header' => $header, 'items' => $items]);
    exit;
}

// --- C. PENCARIAN BARANG CERDAS (bc_1ab2, bc_issue_dtl, bc_material) ---
if ($action === 'search_goods') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $source = trim($_GET['source'] ?? 'all');
    $dept = trim($_GET['dept'] ?? '');
    $klas = trim($_GET['klas'] ?? '');
    $escQ = $koneksi->real_escape_string($q);

    $results = [];

    // Filter kategori berdasarkan departemen jika diminta
    $klasCond1ab2 = "";
    $klasCondMat = "";
    if (!empty($klas) && $klas !== 'ALL') {
        $escKl = $koneksi->real_escape_string($klas);
        $klasCond1ab2 = " AND KLAS LIKE '%{$escKl}%' ";
        $klasCondMat = " AND KLAS LIKE '%{$escKl}%' ";
    } elseif (!empty($dept)) {
        $dLower = strtolower($dept);
        if (strpos($dLower, 'maint') !== false) {
            $klasCond1ab2 = " AND (KLAS IN ('Machine', 'Part Machine', 'Tools', 'Factory Equipment') OR URAIAN LIKE '%MESIN%' OR URAIAN LIKE '%BEARING%' OR URAIAN LIKE '%PART%') ";
        } elseif (strpos($dLower, 'mold') !== false) {
            $klasCond1ab2 = " AND (KLAS IN ('Mold', 'Tools') OR URAIAN LIKE '%MOLD%' OR URAIAN LIKE '%INJECTION%') ";
        } elseif (strpos($dLower, 'prod') !== false) {
            $klasCond1ab2 = " AND (KLAS IN ('Raw Materials', 'Work In Progress', 'Consumables')) ";
        }
    }

    // 1. CARI DI bc_1ab2 (Pabean Masuk)
    if ($source === 'all' || $source === 'bc_1ab2') {
        $whereSearch = "";
        if (!empty($escQ)) {
            $whereSearch = " AND (KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%' OR NAMA_BS LIKE '%{$escQ}%' OR NO_BUKTI LIKE '%{$escQ}%') ";
        }
        $sql1ab2 = "SELECT 
            KODE_BARANG, URAIAN, KLAS, UNIT, 
            COALESCE(MAX(BC), '-') as ref_bc,
            COALESCE(MAX(NOMOR_DAFTAR), '-') as ref_no_daftar,
            COALESCE(MAX(TANGGAL_DAFTAR), '-') as ref_tgl_daftar,
            COALESCE(MAX(NAMA_BS), '-') as ref_supplier,
            COALESCE(MAX(NOMOR_AJU), '') as ref_nomor_aju,
            COALESCE(MAX(NO_BUKTI), '') as ref_no_bukti,
            COALESCE(SUM(QTY_AKTUAL), 0) as total_qty_masuk
            FROM bc_1ab2
            WHERE (AB IN ('A', 'M') OR AB = '' OR AB IS NULL)
              AND {$cmpFilter1ab2}
              {$whereSearch}
              {$klasCond1ab2}
            GROUP BY KODE_BARANG, URAIAN, KLAS, UNIT
            ORDER BY total_qty_masuk DESC, KODE_BARANG ASC
            LIMIT 40";

        $res1 = $koneksi->query($sql1ab2);
        if ($res1) {
            while ($r = $res1->fetch_assoc()) {
                $results[] = [
                    'source'        => 'bc_1ab2',
                    'source_label'  => 'Pabean (bc_1ab2)',
                    'badge_class'   => 'badge-source-pabean',
                    'kode_barang'   => $r['KODE_BARANG'],
                    'nama_barang'   => $r['URAIAN'],
                    'klas'          => !empty($r['KLAS']) ? $r['KLAS'] : 'General',
                    'sat'           => !empty($r['UNIT']) ? $r['UNIT'] : 'PCE',
                    'ref_bc'        => $r['ref_bc'] !== '-' ? 'BC ' . $r['ref_bc'] : '',
                    'ref_no_daftar' => $r['ref_no_daftar'],
                    'ref_tgl'       => $r['ref_tgl_daftar'],
                    'ref_supplier'  => $r['ref_supplier'],
                    'ref_nomor_aju' => $r['ref_nomor_aju'],
                    'ref_no_bukti'  => $r['ref_no_bukti'],
                    'stok_info'     => 'Total Masuk: ' . number_format((float)$r['total_qty_masuk'], 2, ',', '.') . ' ' . $r['UNIT']
                ];
            }
        }
    }

    // 2. CARI DI bc_issue_dtl (Riwayat Internal Issue / Produksi)
    if (($source === 'all' || $source === 'bc_issue') && count($results) < 50) {
        $whereSearchIssue = "";
        if (!empty($escQ)) {
            $whereSearchIssue = " AND (d.kode_barang LIKE '%{$escQ}%' OR d.nama_barang LIKE '%{$escQ}%') ";
        }
        $sqlIssue = "SELECT 
            d.kode_barang, d.nama_barang, d.sat,
            COALESCE(MAX(h.klas), 'Internal Issue') as klas,
            COALESCE(MAX(h.no_bukti), '') as ref_no_bukti,
            COALESCE(MAX(h.tgl), '') as ref_tgl,
            COALESCE(SUM(d.qty), 0) as total_qty_issue
            FROM bc_issue_dtl d
            LEFT JOIN bc_issue_hdr h ON d.id_hdr = h.id
            WHERE 1=1
              {$whereSearchIssue}
            GROUP BY d.kode_barang, d.nama_barang, d.sat
            ORDER BY total_qty_issue DESC, d.kode_barang ASC
            LIMIT 30";

        $resIssue = $koneksi->query($sqlIssue);
        if ($resIssue) {
            // Deduplikasi agar jika sudah ada dari bc_1ab2 tidak dobel
            $existingKodes = array_column($results, 'kode_barang');
            while ($ri = $resIssue->fetch_assoc()) {
                if (in_array($ri['kode_barang'], $existingKodes)) continue;
                $results[] = [
                    'source'        => 'bc_issue_dtl',
                    'source_label'  => 'Internal Issue',
                    'badge_class'   => 'badge-source-issue',
                    'kode_barang'   => $ri['kode_barang'],
                    'nama_barang'   => $ri['nama_barang'],
                    'klas'          => !empty($ri['klas']) ? $ri['klas'] : 'Internal Issue',
                    'sat'           => !empty($ri['sat']) ? $ri['sat'] : 'PCE',
                    'ref_bc'        => '',
                    'ref_no_daftar' => '',
                    'ref_tgl'       => $ri['ref_tgl'],
                    'ref_supplier'  => 'Internal Produksi',
                    'ref_nomor_aju' => '',
                    'ref_no_bukti'  => $ri['ref_no_bukti'],
                    'stok_info'     => 'Riwayat Issue: ' . number_format((float)$ri['total_qty_issue'], 2, ',', '.') . ' ' . $ri['sat']
                ];
            }
        }
    }

    // 3. CARI DI bc_material (Master Material)
    if (($source === 'all' || $source === 'bc_material') && count($results) < 50) {
        $whereSearchMat = "";
        if (!empty($escQ)) {
            $whereSearchMat = " AND (KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%') ";
        }
        $sqlMat = "SELECT KODE_BARANG, URAIAN, KLAS, KODE_SATUAN 
                   FROM bc_material 
                   WHERE 1=1 {$whereSearchMat} {$klasCondMat}
                   LIMIT 20";
        $resMat = $koneksi->query($sqlMat);
        if ($resMat) {
            $existingKodes = array_column($results, 'kode_barang');
            while ($rm = $resMat->fetch_assoc()) {
                if (in_array($rm['KODE_BARANG'], $existingKodes)) continue;
                $results[] = [
                    'source'        => 'bc_material',
                    'source_label'  => 'Master Barang',
                    'badge_class'   => 'badge-source-material',
                    'kode_barang'   => $rm['KODE_BARANG'],
                    'nama_barang'   => $rm['URAIAN'],
                    'klas'          => !empty($rm['KLAS']) ? $rm['KLAS'] : 'General',
                    'sat'           => !empty($rm['KODE_SATUAN']) ? $rm['KODE_SATUAN'] : 'PCE',
                    'ref_bc'        => '',
                    'ref_no_daftar' => '',
                    'ref_tgl'       => '',
                    'ref_supplier'  => 'Master Data',
                    'ref_nomor_aju' => '',
                    'ref_no_bukti'  => '',
                    'stok_info'     => 'Master Barang'
                ];
            }
        }
    }

    echo json_encode(['status' => 'OK', 'count' => count($results), 'data' => $results]);
    exit;
}

// --- D. SIMPAN PERMINTAAN (SAVE / UPDATE) ---
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $post = json_decode($raw, true) ?: $_POST;

    $id          = trim($post['id'] ?? '');
    $cmpId       = trim($post['cmp_id'] ?? $sessionCmpId);
    if (empty($cmpId) || $cmpId === 'ALL') $cmpId = 'FRM-1';
    $reqNo       = trim($post['req_no'] ?? '');
    $reqDate     = trim($post['req_date'] ?? date('Y-m-d'));
    $needDate    = !empty($post['need_date']) ? trim($post['need_date']) : null;
    $deptPemohon = trim($post['dept_pemohon'] ?? 'Maintenance');
    $deptTujuan  = trim($post['dept_tujuan'] ?? 'GUDANG');
    $klas        = trim($post['klas'] ?? 'General');
    $pic         = trim($post['pic'] ?? $currentUser);
    $woNo        = trim($post['wo_no'] ?? '');
    $note        = trim($post['note'] ?? '');
    $targetStatus = strtoupper(trim($post['status'] ?? 'PENDING'));
    if (!in_array($targetStatus, ['DRAFT', 'PENDING', 'APPROVED'])) $targetStatus = 'PENDING';
    $items       = $post['items'] ?? [];

    if (empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Harap tambahkan minimal 1 item barang yang diminta.']);
        exit;
    }

    $isNew = empty($id);
    if ($isNew) {
        $id = bin2hex(random_bytes(16));
    }

    // Auto-generate nomor request jika kosong
    if (empty($reqNo)) {
        $deptCode = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', substr($deptPemohon, 0, 4)));
        if (empty($deptCode)) $deptCode = 'REQ';
        $prefix = "REQ-{$deptCode}-" . date('Ym') . "-";
        $qLast = $koneksi->query("SELECT req_no FROM bc_issue_dept_req_hdr WHERE req_no LIKE '{$prefix}%' ORDER BY req_no DESC LIMIT 1");
        $nextSeq = 1;
        if ($qLast && $rL = $qLast->fetch_assoc()) {
            $parts = explode('-', $rL['req_no']);
            $lastNum = (int)end($parts);
            $nextSeq = $lastNum + 1;
        }
        $reqNo = $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }

    $escId          = $koneksi->real_escape_string($id);
    $escCmp         = $koneksi->real_escape_string($cmpId);
    $escReqNo       = $koneksi->real_escape_string($reqNo);
    $escReqDate     = $koneksi->real_escape_string($reqDate);
    $escDeptPemohon = $koneksi->real_escape_string($deptPemohon);
    $escDeptTujuan  = $koneksi->real_escape_string($deptTujuan);
    $escKlas        = $koneksi->real_escape_string($klas);
    $escPic         = $koneksi->real_escape_string($pic);
    $escWoNo        = $koneksi->real_escape_string($woNo);
    $escNote        = $koneksi->real_escape_string($note);
    $escUser        = $koneksi->real_escape_string($currentUser);
    $needDtSql      = !empty($needDate) ? "'{$koneksi->real_escape_string($needDate)}'" : "NULL";

    $koneksi->begin_transaction();
    try {
        if ($isNew) {
            $sqlHdr = "INSERT INTO bc_issue_dept_req_hdr 
                (cmp_id, id, req_no, no_bukti, req_date, tgl, need_date, dept_pemohon, dept_fr, dept_tujuan, dept_to, klas, pic, wo_no, wo_id, note, status, created_by, setby)
                VALUES 
                ('{$escCmp}', '{$escId}', '{$escReqNo}', '{$escReqNo}', '{$escReqDate}', '{$escReqDate}', {$needDtSql}, '{$escDeptPemohon}', '{$escDeptPemohon}', '{$escDeptTujuan}', '{$escDeptTujuan}', '{$escKlas}', '{$escPic}', '{$escWoNo}', '{$escWoNo}', '{$escNote}', '{$targetStatus}', '{$escUser}', '{$escUser}')";
            if (!$koneksi->query($sqlHdr)) {
                throw new Exception('Gagal menyimpan header: ' . $koneksi->error);
            }
        } else {
            $sqlHdr = "UPDATE bc_issue_dept_req_hdr SET 
                req_no = '{$escReqNo}', no_bukti = '{$escReqNo}',
                req_date = '{$escReqDate}', tgl = '{$escReqDate}',
                need_date = {$needDtSql},
                dept_pemohon = '{$escDeptPemohon}', dept_fr = '{$escDeptPemohon}',
                dept_tujuan = '{$escDeptTujuan}', dept_to = '{$escDeptTujuan}',
                klas = '{$escKlas}', pic = '{$escPic}',
                wo_no = '{$escWoNo}', wo_id = '{$escWoNo}',
                note = '{$escNote}', status = '{$targetStatus}'
                WHERE id = '{$escId}'";
            if (!$koneksi->query($sqlHdr)) {
                throw new Exception('Gagal memperbarui header: ' . $koneksi->error);
            }
            $koneksi->query("DELETE FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$escId}'");
        }

        // Insert Item Rincian
        $stmtDtl = $koneksi->prepare("INSERT INTO bc_issue_dept_req_dtl 
            (cmp_id, id, id_hdr, req_no, seq, kode_barang, nama_barang, sat, klas, qty_req, qty, qty_appr, source_type, ref_nomor_aju, ref_no_daftar, ref_no_bukti, note, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        foreach ($items as $it) {
            $dtlId   = bin2hex(random_bytes(16));
            $kd      = trim($it['kode_barang'] ?? '');
            $nm      = trim($it['nama_barang'] ?? '');
            $sat     = trim($it['sat'] ?? 'PCE');
            $iKlas   = trim($it['klas'] ?? $klas);
            $qtyReq  = (float)($it['qty_req'] ?? 1);
            $qtyAppr = ($targetStatus === 'APPROVED') ? $qtyReq : 0.0;
            $srcType = trim($it['source_type'] ?? 'bc_1ab2');
            $refAju  = trim($it['ref_nomor_aju'] ?? '');
            $refDaft = trim($it['ref_no_daftar'] ?? '');
            $refBkt  = trim($it['ref_no_bukti'] ?? '');
            $iNote   = trim($it['note'] ?? '');
            $iStatus = $targetStatus;

            if (empty($kd) || $qtyReq <= 0) continue;

            $stmtDtl->bind_param("ssssissssdddssssss",
                $cmpId, $dtlId, $id, $reqNo, $seq,
                $kd, $nm, $sat, $iKlas,
                $qtyReq, $qtyReq, $qtyAppr,
                $srcType, $refAju, $refDaft, $refBkt, $iNote, $iStatus
            );
            $stmtDtl->execute();
            $seq++;
        }
        $stmtDtl->close();

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK',
            'id' => $id,
            'req_no' => $reqNo,
            'message' => "Permintaan barang '{$reqNo}' berhasil disimpan dengan status: {$targetStatus}."
        ]);
        exit;
    } catch (Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- E. APPROVE / REJECT / COMPLETE / DELETE ---
if ($action === 'update_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $targetStatus = strtoupper(trim($_POST['status'] ?? ''));
    $reason = trim($_POST['reason'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $escUser = $koneksi->real_escape_string($currentUser);
    $escReason = $koneksi->real_escape_string($reason);

    if (empty($escId)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID permintaan tidak valid.']);
        exit;
    }

    if ($targetStatus === 'APPROVED') {
        $sql = "UPDATE bc_issue_dept_req_hdr SET 
            status = 'APPROVED', approved_by_1 = '{$escUser}', approved_at_1 = NOW() 
            WHERE id = '{$escId}'";
        $koneksi->query($sql);
        $koneksi->query("UPDATE bc_issue_dept_req_dtl SET status = 'APPROVED', qty_appr = qty_req WHERE id_hdr = '{$escId}'");
        echo json_encode(['status' => 'OK', 'message' => 'Permintaan barang berhasil disetujui (APPROVED). Siap untuk proses pengeluaran gudang.']);
        exit;
    } elseif ($targetStatus === 'REJECTED') {
        $sql = "UPDATE bc_issue_dept_req_hdr SET 
            status = 'REJECTED', rejected_by = '{$escUser}', reject_reason = '{$escReason}' 
            WHERE id = '{$escId}'";
        $koneksi->query($sql);
        $koneksi->query("UPDATE bc_issue_dept_req_dtl SET status = 'REJECTED' WHERE id_hdr = '{$escId}'");
        echo json_encode(['status' => 'OK', 'message' => 'Permintaan barang telah ditolak.']);
        exit;
    } elseif ($targetStatus === 'COMPLETED') {
        $sql = "UPDATE bc_issue_dept_req_hdr SET status = 'COMPLETED' WHERE id = '{$escId}'";
        $koneksi->query($sql);
        $koneksi->query("UPDATE bc_issue_dept_req_dtl SET status = 'COMPLETED', qty_issued = qty_appr WHERE id_hdr = '{$escId}'");
        echo json_encode(['status' => 'OK', 'message' => 'Status permintaan berhasil diselesaikan (COMPLETED).']);
        exit;
    } elseif ($targetStatus === 'DELETE') {
        $koneksi->query("DELETE FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$escId}'");
        $koneksi->query("DELETE FROM bc_issue_dept_req_hdr WHERE id = '{$escId}'");
        echo json_encode(['status' => 'OK', 'message' => 'Data permintaan berhasil dihapus.']);
        exit;
    }
}

// --- F. CETAK BPPB (PRINT BUKTI PERMINTAAN & PENGELUARAN BARANG) ---
if ($action === 'print_bppb') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $qH = $koneksi->query("SELECT * FROM bc_issue_dept_req_hdr WHERE id = '{$escId}' LIMIT 1");
    if (!$qH || $qH->num_rows === 0) die("Dokumen tidak ditemukan.");
    $hdr = $qH->fetch_assoc();
    $qD = $koneksi->query("SELECT * FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $items = [];
    if ($qD) while ($r = $qD->fetch_assoc()) $items[] = $r;
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>BPPB - <?= htmlspecialchars($hdr['req_no']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 15mm; }
            body { font-family: Arial, sans-serif; font-size: 11px; color: #111; margin: 0; padding: 20px; background: #fff; }
            .print-header { display: flex; align-items: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 15px; }
            .print-logo { height: 42px; margin-right: 15px; }
            .print-title { flex: 1; }
            .print-title h2 { margin: 0 0 4px 0; font-size: 16px; font-weight: 800; text-transform: uppercase; }
            .print-title p { margin: 0; font-size: 11px; color: #555; }
            .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; background: #fafafa; border: 1px solid #ddd; padding: 10px; border-radius: 4px; }
            .meta-row { display: flex; margin-bottom: 4px; }
            .meta-label { width: 130px; font-weight: bold; color: #333; }
            .meta-val { flex: 1; }
            table.bppb-tbl { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            table.bppb-tbl th, table.bppb-tbl td { border: 1px solid #444; padding: 6px 8px; font-size: 10.5px; }
            table.bppb-tbl th { background: #f0f0f0; text-align: center; font-weight: bold; }
            .sig-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; text-align: center; margin-top: 30px; page-break-inside: avoid; }
            .sig-box { border: 1px solid #999; padding: 8px; border-radius: 4px; height: 95px; display: flex; flex-direction: column; justify-content: space-between; }
            .sig-title { font-weight: bold; font-size: 10px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
            .sig-name { font-size: 10px; border-top: 1px dashed #777; padding-top: 4px; }
            @media print {
                .no-print { display: none !important; }
                body { padding: 0; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px; padding: 10px; background: #e6f7ff; border: 1px solid #91d5ff; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
            <div><strong>Pratinjau Cetak BPPB</strong> (Bukti Permintaan & Pengeluaran Barang)</div>
            <div>
                <button type="button" onclick="window.print()" style="background:#1890ff; color:#fff; border:none; padding:6px 14px; border-radius:4px; font-weight:bold; cursor:pointer;">🖨️ Cetak Formulir</button>
                <button type="button" onclick="window.close()" style="background:#f5f5f5; border:1px solid #ccc; padding:6px 14px; border-radius:4px; margin-left:6px; cursor:pointer;">Tutup</button>
            </div>
        </div>

        <div class="print-header">
            <img src="/assets/logo.png" class="print-logo" onerror="this.style.display='none'">
            <div class="print-title">
                <h2>PT FRAMAS INDONESIA</h2>
                <p>FORMULIR BUKTI PERMINTAAN & PENGELUARAN BARANG (BPPB)</p>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 13px; font-weight: 800; font-family: monospace;"><?= htmlspecialchars($hdr['req_no']) ?></div>
                <div style="font-size: 9.5px; color: #666;">Status: <?= htmlspecialchars($hdr['status']) ?></div>
            </div>
        </div>

        <div class="meta-grid">
            <div>
                <div class="meta-row"><span class="meta-label">No. Request / BPPB</span><span class="meta-val">: <strong><?= htmlspecialchars($hdr['req_no']) ?></strong></span></div>
                <div class="meta-row"><span class="meta-label">Tgl. Permintaan</span><span class="meta-val">: <?= htmlspecialchars($hdr['req_date']) ?></span></div>
                <div class="meta-row"><span class="meta-label">Tgl. Dibutuhkan</span><span class="meta-val">: <?= htmlspecialchars($hdr['need_date'] ?: '-') ?></span></div>
                <div class="meta-row"><span class="meta-label">Departemen Pemohon</span><span class="meta-val">: <strong><?= htmlspecialchars($hdr['dept_pemohon']) ?></strong></span></div>
            </div>
            <div>
                <div class="meta-row"><span class="meta-label">Departemen Tujuan</span><span class="meta-val">: <?= htmlspecialchars($hdr['dept_tujuan']) ?></span></div>
                <div class="meta-row"><span class="meta-label">Kategori / Klasifikasi</span><span class="meta-val">: <?= htmlspecialchars($hdr['klas']) ?></span></div>
                <div class="meta-row"><span class="meta-label">No. WO / Mesin Ref</span><span class="meta-val">: <?= htmlspecialchars($hdr['wo_no'] ?: '-') ?></span></div>
                <div class="meta-row"><span class="meta-label">Catatan / Keperluan</span><span class="meta-val">: <?= htmlspecialchars($hdr['note'] ?: '-') ?></span></div>
            </div>
        </div>

        <table class="bppb-tbl">
            <thead>
                <tr>
                    <th style="width: 30px;">No</th>
                    <th style="width: 140px;">Kode Barang</th>
                    <th>Nama / Uraian Barang</th>
                    <th style="width: 50px;">Sat</th>
                    <th style="width: 70px;">Qty Minta</th>
                    <th style="width: 70px;">Qty Setuju</th>
                    <th style="width: 130px;">Ref Asal / Dokumen</th>
                    <th style="width: 140px;">Catatan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="8" style="text-align: center; color: #999;">Tidak ada item barang</td></tr>
                <?php else: foreach ($items as $idx => $it): ?>
                    <tr>
                        <td style="text-align: center;"><?= $idx + 1 ?></td>
                        <td style="font-family: monospace; font-weight: bold;"><?= htmlspecialchars($it['kode_barang']) ?></td>
                        <td><?= htmlspecialchars($it['nama_barang']) ?></td>
                        <td style="text-align: center;"><?= htmlspecialchars($it['sat']) ?></td>
                        <td style="text-align: right; font-weight: bold;"><?= number_format((float)$it['qty_req'], 2, ',', '.') ?></td>
                        <td style="text-align: right;"><?= number_format((float)$it['qty_appr'], 2, ',', '.') ?></td>
                        <td style="font-size: 9.5px;"><?= htmlspecialchars(trim(($it['ref_bc'] ?? '') . ' ' . ($it['ref_no_daftar'] ?? '') . ' ' . ($it['ref_no_bukti'] ?? '')) ?: '-') ?></td>
                        <td><?= htmlspecialchars($it['note'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div class="sig-grid">
            <div class="sig-box">
                <div class="sig-title">Diminta Oleh (Pemohon)</div>
                <div style="flex:1;"></div>
                <div class="sig-name">( <?= htmlspecialchars($hdr['pic'] ?: $hdr['created_by'] ?: '......................') ?> )</div>
            </div>
            <div class="sig-box">
                <div class="sig-title">Disetujui (Kepala Dept)</div>
                <div style="flex:1; display:flex; align-items:center; justify-content:center; color:#52c41a; font-weight:bold; font-size:10px;">
                    <?= $hdr['status'] === 'APPROVED' || $hdr['status'] === 'COMPLETED' ? '✓ APPROVED' : '' ?>
                </div>
                <div class="sig-name">( <?= htmlspecialchars($hdr['approved_by_1'] ?: '......................') ?> )</div>
            </div>
            <div class="sig-box">
                <div class="sig-title">Dikeluarkan Oleh (Gudang)</div>
                <div style="flex:1;"></div>
                <div class="sig-name">( ................................... )</div>
            </div>
            <div class="sig-box">
                <div class="sig-title">Diterima Oleh</div>
                <div style="flex:1;"></div>
                <div class="sig-name">( ................................... )</div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Request — Permintaan Pengeluaran Barang</title>
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="/assets/sweetalert2.all.min.js"></script>
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">

    <style>
        :root {
            --bg-page: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --border-color: #d9d9d9;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #fa8c16;
            --danger: #f5222d;
            --purple: #722ed1;
            --row-hover: #fafafa;
        }
        body[data-theme="dark"] {
            --bg-page: #141414;
            --bg-card: #1f1f1f;
            --text-main: #e6e6e6;
            --text-muted: #8c8c8c;
            --border-color: #303030;
            --primary: #177ddc;
            --primary-hover: #3c9ae8;
            --success: #49aa19;
            --warning: #d87a16;
            --danger: #d32029;
            --purple: #642ab5;
            --row-hover: #262626;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 12px 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 12px;
            color: var(--text-main);
            background-color: var(--bg-page);
        }

        /* TOOLBAR HEADER */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 12px;
        }
        .header-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-icon {
            width: 34px;
            height: 34px;
            border-radius: 6px;
            background: rgba(24, 144, 255, 0.12);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .header-text h1 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
        }
        .header-text p {
            margin: 2px 0 0 0;
            font-size: 11px;
            color: var(--text-muted);
        }

        /* KPI CHIPS */
        .kpi-strip {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            overflow-x: auto;
        }
        .kpi-chip {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 6px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
            white-space: nowrap;
        }
        .kpi-chip:hover { border-color: var(--primary); }
        .kpi-chip.active {
            border-color: var(--primary);
            background: rgba(24, 144, 255, 0.08);
            box-shadow: 0 0 0 1px var(--primary);
        }
        .kpi-label { font-size: 11px; font-weight: 600; color: var(--text-muted); }
        .kpi-val { font-size: 13px; font-weight: 800; color: var(--text-main); }
        .kpi-val.pending { color: var(--warning); }
        .kpi-val.approved { color: var(--primary); }
        .kpi-val.completed { color: var(--success); }
        .kpi-val.rejected { color: var(--danger); }

        /* BUTTONS */
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 30px;
            padding: 0 12px;
            font-size: 11.5px;
            font-weight: 600;
            border-radius: 4px;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-default { background: var(--bg-card); border-color: var(--border-color); color: var(--text-main); }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }

        /* FILTER BAR */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
        }
        .search-box {
            position: relative;
            flex: 1;
            min-width: 220px;
        }
        .search-box input {
            width: 100%;
            height: 30px;
            padding: 0 28px 0 10px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 11.5px;
        }
        .search-box i {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }
        .form-select-sm {
            height: 30px;
            padding: 0 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 11.5px;
        }

        /* TABLE */
        .card-table-wrap {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }
        table.custom-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        table.custom-table th {
            background: #fafafa;
            color: #595959;
            font-weight: 700;
            font-size: 11px;
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        body[data-theme="dark"] table.custom-table th {
            background: #222;
            color: #bbb;
        }
        table.custom-table td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-color);
            font-size: 11.5px;
            vertical-align: middle;
        }
        table.custom-table tbody tr:hover { background-color: var(--row-hover); }

        /* STATUS BADGES */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            line-height: 14px;
        }
        .status-pending { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .status-approved { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; }
        .status-completed { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .status-rejected { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }
        .status-draft { background: #f5f5f5; color: #8c8c8c; border: 1px solid #d9d9d9; }

        /* SOURCE BADGES */
        .badge-source-pabean { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; padding: 1px 5px; border-radius: 3px; font-size: 9px; font-weight: 700; }
        .badge-source-issue { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; padding: 1px 5px; border-radius: 3px; font-size: 9px; font-weight: 700; }
        .badge-source-material { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; padding: 1px 5px; border-radius: 3px; font-size: 9px; font-weight: 700; }
        .badge-source-manual { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; padding: 1px 5px; border-radius: 3px; font-size: 9px; font-weight: 700; }

        /* MODAL */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-card {
            background: var(--bg-card);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            width: 90%;
            max-width: 960px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(0,0,0,0.25);
        }
        .modal-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
        }
        .modal-header h3 { margin: 0; font-size: 14px; font-weight: 700; }
        .modal-body {
            padding: 14px 16px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 10px 16px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            background: var(--bg-card);
        }

        /* FORM CONTROLS */
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 10px; }
        .form-label { display: block; font-weight: 600; font-size: 11px; color: var(--text-muted); margin-bottom: 4px; }
        .form-control {
            width: 100%;
            height: 30px;
            padding: 0 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--bg-card);
            color: var(--text-main);
            font-size: 11.5px;
        }
        textarea.form-control { height: auto; padding: 6px 8px; resize: vertical; }

        .dept-hint-box {
            background: rgba(24, 144, 255, 0.08);
            border: 1px solid rgba(24, 144, 255, 0.25);
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 11px;
            color: var(--primary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .items-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 14px;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border-color);
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">

    <!-- 1. HEADER / TOOLBAR -->
    <div class="page-header">
        <div class="header-title-wrap">
            <div class="header-icon">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
            <div class="header-text">
                <h1>Department Request (BPPB)</h1>
                <p>Permintaan Pengeluaran Barang Antar Departemen &bull; Maintenance, Moldshop, Produksi, GA, Warehouse</p>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="status-badge status-approved notranslate" translate="no">
                <i class="fa-solid fa-industry"></i> <?= htmlspecialchars($sessionCmpId) ?>
            </span>
            <button type="button" class="btn-action btn-primary" onclick="openCreateModal()">
                <i class="fa-solid fa-plus"></i> <span>+ Buat Request Baru</span>
            </button>
            <button type="button" class="btn-action btn-default" onclick="loadList()">
                <i class="fa-solid fa-rotate"></i> <span>Refresh</span>
            </button>
        </div>
    </div>

    <!-- 2. KPI STRIP -->
    <div class="kpi-strip">
        <div class="kpi-chip active" id="chipTotal" onclick="filterByStatus('ALL')">
            <span class="kpi-label">Total Permintaan:</span>
            <span class="kpi-val" id="kpiTotal">0</span>
        </div>
        <div class="kpi-chip" id="chipPending" onclick="filterByStatus('PENDING')">
            <span class="kpi-label">Menunggu Persetujuan:</span>
            <span class="kpi-val pending" id="kpiPending">0</span>
        </div>
        <div class="kpi-chip" id="chipApproved" onclick="filterByStatus('APPROVED')">
            <span class="kpi-label">Disetujui (Ready Issue):</span>
            <span class="kpi-val approved" id="kpiApproved">0</span>
        </div>
        <div class="kpi-chip" id="chipCompleted" onclick="filterByStatus('COMPLETED')">
            <span class="kpi-label">Selesai Dikeluarkan:</span>
            <span class="kpi-val completed" id="kpiCompleted">0</span>
        </div>
        <div class="kpi-chip" id="chipDraft" onclick="filterByStatus('DRAFT')">
            <span class="kpi-label">Draft:</span>
            <span class="kpi-val" id="kpiDraft">0</span>
        </div>
        <div class="kpi-chip" id="chipRejected" onclick="filterByStatus('REJECTED')">
            <span class="kpi-label">Ditolak:</span>
            <span class="kpi-val rejected" id="kpiRejected">0</span>
        </div>
    </div>

    <!-- 3. FILTER BAR -->
    <div class="filter-bar">
        <div class="search-box">
            <input type="text" id="searchInput" placeholder="Cari No. Request, Dept, WO, PIC, Catatan..." onkeydown="if(event.key==='Enter') loadList()">
            <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <div>
            <select id="filterDept" class="form-select-sm" onchange="loadList()">
                <option value="ALL">Semua Departemen Pemohon</option>
                <option value="Maintenance">Maintenance (Mesin / Part / Tooling)</option>
                <option value="Moldshop">Moldshop (Mold / Part Mold)</option>
                <option value="Produksi">Produksi (Raw Material / WIP)</option>
                <option value="Warehouse">Warehouse / Gudang</option>
                <option value="Cutting">Cutting</option>
                <option value="Assembling">Assembling</option>
                <option value="Sewing">Sewing</option>
                <option value="QC">Quality Control (QC)</option>
                <option value="GA">General Affair (GA)</option>
            </select>
        </div>
        <div>
            <select id="filterStatus" class="form-select-sm" onchange="loadList()">
                <option value="ALL">Semua Status</option>
                <option value="PENDING">Menunggu Persetujuan</option>
                <option value="APPROVED">Disetujui</option>
                <option value="COMPLETED">Selesai Dikeluarkan</option>
                <option value="DRAFT">Draft</option>
                <option value="REJECTED">Ditolak</option>
            </select>
        </div>
    </div>

    <!-- 4. TABLE WRAP -->
    <div class="card-table-wrap">
        <table class="custom-table" id="reqTable">
            <thead>
                <tr>
                    <th style="width: 32px; text-align:center;">#</th>
                    <th style="width: 140px;">No. Request / BPPB</th>
                    <th style="width: 85px;">Tanggal</th>
                    <th style="width: 120px;">Dept Pemohon</th>
                    <th style="width: 110px;">Dept Tujuan</th>
                    <th style="width: 110px;">Kategori</th>
                    <th style="width: 100px;">No. WO / Mesin</th>
                    <th style="width: 60px; text-align:center;">Items</th>
                    <th style="width: 75px; text-align:right;">Total Qty</th>
                    <th style="width: 115px; text-align:center;">Status</th>
                    <th>Catatan & Audit Approval</th>
                    <th style="width: 120px; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="reqTbody">
                <tr><td colspan="12" style="text-align:center; padding: 24px; color: var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data permintaan...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 5. MODAL FORM: CREATE / EDIT DEPARTMENT REQUEST -->
    <div class="modal-overlay" id="modalReqForm">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="formTitle"><i class="fa-solid fa-file-pen" style="color:var(--primary); margin-right:6px;"></i> Form Permintaan Pengeluaran Barang</h3>
                <button type="button" class="btn-action btn-default" style="border:none; font-size:14px;" onclick="closeModal('modalReqForm')">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="fId" value="">
                <input type="hidden" id="fCmpId" value="<?= htmlspecialchars($sessionCmpId) ?>">

                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label">Departemen Pemohon <span style="color:red;">*</span></label>
                        <select id="fDeptPemohon" class="form-control" onchange="onDeptChange(this.value)">
                            <option value="Maintenance">Maintenance (Mesin / Part Mesin / Tooling)</option>
                            <option value="Moldshop">Moldshop (Mold / Sparepart Mold)</option>
                            <option value="Produksi">Produksi (Raw Material / WIP / Consumables)</option>
                            <option value="Warehouse">Warehouse / Gudang</option>
                            <option value="Cutting">Cutting</option>
                            <option value="Assembling">Assembling</option>
                            <option value="Sewing">Sewing</option>
                            <option value="Quality Control">Quality Control (QC)</option>
                            <option value="GA">General Affair (GA)</option>
                            <option value="IT">IT / Facility</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Departemen Tujuan <span style="color:red;">*</span></label>
                        <select id="fDeptTujuan" class="form-control">
                            <option value="GUDANG">Gudang Utama (Warehouse)</option>
                            <option value="GUDANG RAW MATERIAL">Gudang Raw Material</option>
                            <option value="GUDANG PART & MESIN">Gudang Part & Mesin</option>
                            <option value="GUDANG FINISH GOOD">Gudang Finish Good</option>
                            <option value="MOLDSHOP">Moldshop</option>
                            <option value="PRODUKSI">Produksi</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">No. Request / BPPB (Auto / Manual)</label>
                        <input type="text" id="fReqNo" class="form-control" placeholder="Otomatis digenerate sistem...">
                    </div>
                </div>

                <div class="dept-hint-box" id="deptHintBox">
                    <i class="fa-solid fa-circle-info"></i>
                    <span id="deptHintText">Departemen Maintenance aktif: Kategori barang difokuskan ke Mesin, Part Mesin, Tools & Equipment.</span>
                </div>

                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label">Tanggal Permintaan</label>
                        <input type="date" id="fReqDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target Tanggal Dibutuhkan</label>
                        <input type="date" id="fNeedDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">PIC Pemohon</label>
                        <input type="text" id="fPic" class="form-control" value="<?= htmlspecialchars($currentUser) ?>" placeholder="Nama PIC pemohon...">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">No. WO / Mesin Ref / Alasan Pengeluaran</label>
                        <input type="text" id="fWoNo" class="form-control" placeholder="Contoh: Mesin Line 03, Mold M-201, Perbaikan Hidrolik...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Catatan / Keterangan Keperluan</label>
                        <input type="text" id="fNote" class="form-control" placeholder="Tuliskan keterangan detail keperluan barang...">
                    </div>
                </div>

                <!-- ITEMS SECTION -->
                <div class="items-header-bar">
                    <div style="font-weight: 700; font-size: 12px; display:flex; align-items:center; gap:6px;">
                        <span>📦 Daftar Barang yang Diminta</span>
                        <span id="itemCountBadge" class="status-badge status-approved">0 Item</span>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn-action btn-default" onclick="openItemPickerModal()" style="color:var(--primary); border-color:var(--primary);">
                            <i class="fa-solid fa-magnifying-glass-plus"></i> <span>+ Pilih dari Master (Pabean / Issue)</span>
                        </button>
                        <button type="button" class="btn-action btn-default" onclick="addManualRow()">
                            <i class="fa-solid fa-pen-to-square"></i> <span>+ Input Part Baru / Manual</span>
                        </button>
                    </div>
                </div>

                <table class="custom-table" style="border: 1px solid var(--border-color); border-radius: 4px;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th style="width:80px;">Sumber</th>
                            <th style="width:140px;">Kode Barang</th>
                            <th>Nama / Uraian Barang</th>
                            <th style="width:75px; text-align:center;">Satuan</th>
                            <th style="width:85px; text-align:right;">Qty Minta</th>
                            <th style="width:120px;">Ref Dokumen</th>
                            <th style="width:130px;">Catatan Item</th>
                            <th style="width:40px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="fItemsTbody">
                        <!-- Dynamic Item Rows -->
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action btn-default" onclick="closeModal('modalReqForm')">Batal</button>
                <button type="button" class="btn-action btn-default" onclick="submitRequest('DRAFT')">
                    <i class="fa-solid fa-floppy-disk"></i> <span>Simpan Draft</span>
                </button>
                <button type="button" class="btn-action btn-primary" onclick="submitRequest('PENDING')">
                    <i class="fa-solid fa-paper-plane"></i> <span>Kirim Permintaan (Submit)</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 6. MODAL ITEM PICKER (PENCARIAN CERDAS DARI PABEAN bc_1ab2, ISSUE, & MATERIAL) -->
    <div class="modal-overlay" id="modalItemPicker" style="z-index: 1100;">
        <div class="modal-card" style="max-width: 900px; height: 80vh;">
            <div class="modal-header">
                <h3><i class="fa-solid fa-boxes-stacked" style="color:var(--primary); margin-right:6px;"></i> Pilih Barang dari Master / Riwayat</h3>
                <button type="button" class="btn-action btn-default" style="border:none;" onclick="closeModal('modalItemPicker')">✕</button>
            </div>
            <div style="padding: 10px 16px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <div class="search-box" style="flex:2;">
                    <input type="text" id="pickerSearch" placeholder="Ketik Kode Barang, Uraian, Part No, Supplier..." oninput="debouncePickerSearch()">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <div>
                    <select id="pickerSource" class="form-select-sm" onchange="runPickerSearch()">
                        <option value="all">Semua Sumber (Pabean + Issue + Master)</option>
                        <option value="bc_1ab2">Dokumen Pabean Masuk (bc_1ab2)</option>
                        <option value="bc_issue">Internal Issue / Produksi (bc_issue_dtl)</option>
                        <option value="bc_material">Master Material (bc_material)</option>
                    </select>
                </div>
                <div>
                    <select id="pickerKlas" class="form-select-sm" onchange="runPickerSearch()">
                        <option value="ALL">Semua Kategori</option>
                        <option value="Mesin">Mesin (Machine)</option>
                        <option value="Part Machine">Part Mesin (Sparepart)</option>
                        <option value="Tools">Tools / Tooling</option>
                        <option value="Mold">Mold</option>
                        <option value="Raw Materials">Raw Materials (Bahan Baku)</option>
                        <option value="Consumables">Consumables</option>
                        <option value="Work In Progress">Work In Progress (WIP)</option>
                    </select>
                </div>
            </div>
            <div class="modal-body" style="padding: 0;">
                <table class="custom-table" id="pickerTable">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Sumber</th>
                            <th style="width: 150px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width: 90px;">Kategori</th>
                            <th style="width: 55px; text-align:center;">Satuan</th>
                            <th style="width: 140px;">Ref Dokumen / Asal</th>
                            <th style="width: 80px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="pickerTbody">
                        <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">Ketik kata kunci untuk mencari barang...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-action btn-default" onclick="closeModal('modalItemPicker')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        let currentStatusFilter = 'ALL';
        let reqDataList = [];
        let searchTimer = null;

        // Inisialisasi
        window.addEventListener('DOMContentLoaded', () => {
            loadList();
            onDeptChange(document.getElementById('fDeptPemohon').value);
        });

        function filterByStatus(st) {
            currentStatusFilter = st;
            document.querySelectorAll('.kpi-chip').forEach(c => c.classList.remove('active'));
            if (st === 'ALL') document.getElementById('chipTotal').classList.add('active');
            else if (st === 'PENDING') document.getElementById('chipPending').classList.add('active');
            else if (st === 'APPROVED') document.getElementById('chipApproved').classList.add('active');
            else if (st === 'COMPLETED') document.getElementById('chipCompleted').classList.add('active');
            else if (st === 'DRAFT') document.getElementById('chipDraft').classList.add('active');
            else if (st === 'REJECTED') document.getElementById('chipRejected').classList.add('active');

            document.getElementById('filterStatus').value = st;
            loadList();
        }

        async function loadList() {
            const tbody = document.getElementById('reqTbody');
            tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding: 24px; color: var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>`;

            const q = encodeURIComponent(document.getElementById('searchInput').value.trim());
            const dept = encodeURIComponent(document.getElementById('filterDept').value);
            const st = encodeURIComponent(document.getElementById('filterStatus').value);

            try {
                const res = await fetch(`department_request.php?action=list&q=${q}&dept=${dept}&status=${st}&_t=${Date.now()}`);
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message || 'Gagal memuat data.');

                // Update KPI values
                if (json.kpi) {
                    document.getElementById('kpiTotal').innerText = Number(json.kpi.cnt_total || 0).toLocaleString('id-ID');
                    document.getElementById('kpiPending').innerText = Number(json.kpi.cnt_pending || 0).toLocaleString('id-ID');
                    document.getElementById('kpiApproved').innerText = Number(json.kpi.cnt_approved || 0).toLocaleString('id-ID');
                    document.getElementById('kpiCompleted').innerText = Number(json.kpi.cnt_completed || 0).toLocaleString('id-ID');
                    document.getElementById('kpiDraft').innerText = Number(json.kpi.cnt_draft || 0).toLocaleString('id-ID');
                    document.getElementById('kpiRejected').innerText = Number(json.kpi.cnt_rejected || 0).toLocaleString('id-ID');
                }

                reqDataList = json.data || [];
                renderTable();
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding: 20px; color: var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Terjadi kesalahan: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderTable() {
            const tbody = document.getElementById('reqTbody');
            if (reqDataList.length === 0) {
                tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding: 30px; color: var(--text-muted);"><i class="fa-regular fa-folder-open" style="font-size:24px; opacity:0.4; display:block; margin-bottom:8px;"></i>Belum ada data permintaan barang yang sesuai filter.</td></tr>`;
                return;
            }

            let html = '';
            reqDataList.forEach((r, idx) => {
                let badgeClass = 'status-pending';
                let statusLabel = r.status;
                if (r.status === 'PENDING') { badgeClass = 'status-pending'; statusLabel = 'Menunggu Persetujuan'; }
                else if (r.status === 'APPROVED') { badgeClass = 'status-approved'; statusLabel = 'Disetujui (Ready Issue)'; }
                else if (r.status === 'COMPLETED') { badgeClass = 'status-completed'; statusLabel = 'Selesai Dikeluarkan'; }
                else if (r.status === 'REJECTED') { badgeClass = 'status-rejected'; statusLabel = 'Ditolak'; }
                else if (r.status === 'DRAFT') { badgeClass = 'status-draft'; statusLabel = 'Draft'; }

                let audit = '-';
                if (r.status === 'APPROVED' && r.approved_by_1) {
                    audit = `<span style="color:#096dd9;"><i class="fa-solid fa-check"></i> Disetujui: <strong>${escapeHtml(r.approved_by_1)}</strong></span>`;
                } else if (r.status === 'REJECTED' && r.rejected_by) {
                    audit = `<span style="color:#cf1322;"><i class="fa-solid fa-xmark"></i> Ditolak: "${escapeHtml(r.reject_reason || '-')}"</span>`;
                } else if (r.note) {
                    audit = `<span style="color:var(--text-muted);">${escapeHtml(r.note)}</span>`;
                }

                html += `
                    <tr>
                        <td style="text-align:center; color:var(--text-muted); font-family:monospace;">${idx + 1}</td>
                        <td><strong style="color:var(--primary); font-family:monospace;">${escapeHtml(r.display_req_no)}</strong></td>
                        <td style="font-size:11px;">${escapeHtml(r.display_req_date || '-')}</td>
                        <td><span style="font-weight:700;">${escapeHtml(r.display_dept_pemohon)}</span></td>
                        <td><span style="color:var(--text-muted);">${escapeHtml(r.display_dept_tujuan || 'GUDANG')}</span></td>
                        <td><span style="font-size:10px; background:rgba(0,0,0,0.05); padding:2px 6px; border-radius:3px;">${escapeHtml(r.klas || 'General')}</span></td>
                        <td><span style="font-family:monospace; font-size:11px;">${escapeHtml(r.display_wo_no || '-')}</span></td>
                        <td style="text-align:center;"><strong>${Number(r.item_count || 0)}</strong></td>
                        <td style="text-align:right; font-weight:700;">${Number(r.total_qty || 0).toLocaleString('id-ID', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                        <td style="text-align:center;"><span class="status-badge ${badgeClass}">${statusLabel}</span></td>
                        <td><div style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(r.note || '')}">${audit}</div></td>
                        <td style="text-align:center; white-space:nowrap;">
                            <button type="button" class="btn-action btn-default" style="padding:2px 6px; height:24px;" onclick="printBppb('${r.id}')" title="Cetak BPPB Resmi"><i class="fa-solid fa-print"></i></button>
                            ${(r.status === 'PENDING') ? `
                                <button type="button" class="btn-action btn-success" style="padding:2px 6px; height:24px;" onclick="approveRequest('${r.id}', '${escapeHtml(r.display_req_no)}')" title="Setujui"><i class="fa-solid fa-check"></i></button>
                                <button type="button" class="btn-action btn-danger" style="padding:2px 6px; height:24px;" onclick="rejectRequest('${r.id}', '${escapeHtml(r.display_req_no)}')" title="Tolak"><i class="fa-solid fa-xmark"></i></button>
                            ` : ''}
                            ${(r.status === 'APPROVED') ? `
                                <button type="button" class="btn-action btn-primary" style="padding:2px 6px; height:24px;" onclick="completeRequest('${r.id}', '${escapeHtml(r.display_req_no)}')" title="Tandai Selesai Dikeluarkan"><i class="fa-solid fa-box-open"></i></button>
                            ` : ''}
                            ${(r.status === 'DRAFT' || r.status === 'PENDING') ? `
                                <button type="button" class="btn-action btn-default" style="padding:2px 6px; height:24px;" onclick="editRequest('${r.id}')" title="Edit"><i class="fa-solid fa-pen"></i></button>
                                <button type="button" class="btn-action btn-default" style="padding:2px 6px; height:24px; color:var(--danger);" onclick="deleteRequest('${r.id}', '${escapeHtml(r.display_req_no)}')" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                            ` : ''}
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // --- FORM CREATION & EDITING ---
        function onDeptChange(dept) {
            const hintBox = document.getElementById('deptHintBox');
            const hintText = document.getElementById('deptHintText');
            const pickerKlas = document.getElementById('pickerKlas');

            if (dept === 'Maintenance') {
                hintText.innerText = 'Departemen Maintenance aktif: Kategori barang difokuskan ke Mesin, Part Mesin, Tools & Equipment.';
                if (pickerKlas) pickerKlas.value = 'Part Machine';
            } else if (dept === 'Moldshop') {
                hintText.innerText = 'Departemen Moldshop aktif: Kategori barang difokuskan ke Mold, Sparepart Mold & Tooling.';
                if (pickerKlas) pickerKlas.value = 'Mold';
            } else if (dept === 'Produksi') {
                hintText.innerText = 'Departemen Produksi aktif: Kategori barang difokuskan ke Raw Materials, WIP & Consumables.';
                if (pickerKlas) pickerKlas.value = 'Raw Materials';
            } else {
                hintText.innerText = `Departemen ${dept} aktif: Silakan pilih item barang sesuai kebutuhan dari master barang atau input manual.`;
                if (pickerKlas) pickerKlas.value = 'ALL';
            }
        }

        function openCreateModal() {
            document.getElementById('fId').value = '';
            document.getElementById('fReqNo').value = '';
            document.getElementById('fReqDate').value = new Date().toISOString().substring(0, 10);
            document.getElementById('fNeedDate').value = new Date().toISOString().substring(0, 10);
            document.getElementById('fDeptPemohon').value = 'Maintenance';
            document.getElementById('fDeptTujuan').value = 'GUDANG';
            document.getElementById('fWoNo').value = '';
            document.getElementById('fNote').value = '';
            document.getElementById('fItemsTbody').innerHTML = '';
            onDeptChange('Maintenance');
            updateItemCount();
            document.getElementById('formTitle').innerHTML = '<i class="fa-solid fa-file-pen" style="color:var(--primary); margin-right:6px;"></i> Buat Permintaan Barang Baru (Department Request)';
            openModal('modalReqForm');
        }

        async function editRequest(id) {
            try {
                const res = await fetch(`department_request.php?action=get_detail&id=${encodeURIComponent(id)}`);
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message);

                const h = json.header;
                document.getElementById('fId').value = h.id;
                document.getElementById('fReqNo').value = h.display_req_no;
                document.getElementById('fReqDate').value = h.display_req_date;
                document.getElementById('fNeedDate').value = h.need_date || h.display_req_date;
                document.getElementById('fDeptPemohon').value = h.display_dept_pemohon;
                document.getElementById('fDeptTujuan').value = h.display_dept_tujuan;
                document.getElementById('fPic').value = h.pic || '';
                document.getElementById('fWoNo').value = h.display_wo_no || '';
                document.getElementById('fNote').value = h.note || '';

                onDeptChange(h.display_dept_pemohon);

                const tbody = document.getElementById('fItemsTbody');
                tbody.innerHTML = '';
                (json.items || []).forEach(it => addItemRow(it));
                updateItemCount();

                document.getElementById('formTitle').innerHTML = `<i class="fa-solid fa-pen-to-square" style="color:var(--primary); margin-right:6px;"></i> Edit Permintaan: <strong>${escapeHtml(h.display_req_no)}</strong>`;
                openModal('modalReqForm');
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Gagal Memuat Detail', text: err.message });
            }
        }

        function addItemRow(it = {}) {
            const tbody = document.getElementById('fItemsTbody');
            const rowIdx = tbody.children.length + 1;
            const tr = document.createElement('tr');

            let srcBadge = '<span class="badge-source-pabean">Pabean</span>';
            if (it.source_type === 'bc_issue_dtl') srcBadge = '<span class="badge-source-issue">Issue</span>';
            else if (it.source_type === 'bc_material') srcBadge = '<span class="badge-source-material">Master</span>';
            else if (it.source_type === 'manual') srcBadge = '<span class="badge-source-manual">Manual</span>';

            tr.innerHTML = `
                <td style="text-align:center; color:var(--text-muted); font-size:11px;">${rowIdx}</td>
                <td>${srcBadge}<input type="hidden" class="it-src" value="${escapeHtml(it.source_type || 'bc_1ab2')}"></td>
                <td><input type="text" class="form-control it-kd" style="font-family:monospace; font-weight:700;" value="${escapeHtml(it.kode_barang || '')}" placeholder="Kode barang..."></td>
                <td>
                    <input type="text" class="form-control it-nm" value="${escapeHtml(it.nama_barang || '')}" placeholder="Nama / deskripsi barang...">
                    <input type="hidden" class="it-klas" value="${escapeHtml(it.klas || '')}">
                </td>
                <td><input type="text" class="form-control it-sat" style="text-align:center;" value="${escapeHtml(it.sat || 'PCE')}"></td>
                <td><input type="number" step="0.01" min="0.01" class="form-control it-qty" style="text-align:right; font-weight:700;" value="${it.display_qty_req || it.qty_req || 1}"></td>
                <td>
                    <input type="text" class="form-control it-ref" style="font-size:10.5px;" value="${escapeHtml(it.ref_no_daftar || it.ref_no_bukti || it.ref_bc || '')}" placeholder="No Ref...">
                    <input type="hidden" class="it-ref-aju" value="${escapeHtml(it.ref_nomor_aju || '')}">
                </td>
                <td><input type="text" class="form-control it-note" style="font-size:10.5px;" value="${escapeHtml(it.note || '')}" placeholder="Catatan..."></td>
                <td style="text-align:center;">
                    <button type="button" class="btn-action btn-default" style="padding:2px 6px; height:24px; color:var(--danger); border:none;" onclick="this.closest('tr').remove(); updateItemCount();"><i class="fa-solid fa-trash"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
            updateItemCount();
        }

        function addManualRow() {
            addItemRow({
                source_type: 'manual',
                kode_barang: '',
                nama_barang: '',
                sat: 'PCE',
                qty_req: 1,
                klas: 'General'
            });
        }

        function updateItemCount() {
            const cnt = document.querySelectorAll('#fItemsTbody tr').length;
            const badge = document.getElementById('itemCountBadge');
            if (badge) badge.innerText = `${cnt} Item`;
        }

        async function submitRequest(status) {
            const rows = document.querySelectorAll('#fItemsTbody tr');
            if (rows.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Item Kosong', text: 'Harap tambahkan minimal 1 item barang yang diminta.' });
                return;
            }

            const items = [];
            let hasEmptyKd = false;
            rows.forEach(tr => {
                const kd = tr.querySelector('.it-kd').value.trim();
                const nm = tr.querySelector('.it-nm').value.trim();
                const sat = tr.querySelector('.it-sat').value.trim();
                const qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
                const src = tr.querySelector('.it-src').value;
                const klas = tr.querySelector('.it-klas').value;
                const ref = tr.querySelector('.it-ref').value;
                const refAju = tr.querySelector('.it-ref-aju').value;
                const note = tr.querySelector('.it-note').value;

                if (!kd || !nm) hasEmptyKd = true;
                items.push({
                    kode_barang: kd,
                    nama_barang: nm,
                    sat: sat || 'PCE',
                    qty_req: qty,
                    source_type: src,
                    klas: klas,
                    ref_no_daftar: ref,
                    ref_nomor_aju: refAju,
                    note: note
                });
            });

            if (hasEmptyKd) {
                Swal.fire({ icon: 'warning', title: 'Data Belum Lengkap', text: 'Semua item barang harus memiliki Kode Barang dan Nama Barang.' });
                return;
            }

            const payload = {
                id: document.getElementById('fId').value,
                cmp_id: document.getElementById('fCmpId').value,
                req_no: document.getElementById('fReqNo').value.trim(),
                req_date: document.getElementById('fReqDate').value,
                need_date: document.getElementById('fNeedDate').value,
                dept_pemohon: document.getElementById('fDeptPemohon').value,
                dept_tujuan: document.getElementById('fDeptTujuan').value,
                pic: document.getElementById('fPic').value.trim(),
                wo_no: document.getElementById('fWoNo').value.trim(),
                note: document.getElementById('fNote').value.trim(),
                status: status,
                items: items
            };

            try {
                Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const res = await fetch('department_request.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message);

                Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 2000, showConfirmButton: false });
                closeModal('modalReqForm');
                loadList();
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Gagal Menyimpan', text: err.message });
            }
        }

        // --- ITEM PICKER MODAL SEARCH ---
        function openItemPickerModal() {
            const dept = document.getElementById('fDeptPemohon').value;
            document.getElementById('pickerSearch').value = '';
            runPickerSearch();
            openModal('modalItemPicker');
        }

        function debouncePickerSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { runPickerSearch(); }, 300);
        }

        async function runPickerSearch() {
            const tbody = document.getElementById('pickerTbody');
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mencari barang...</td></tr>`;

            const q = encodeURIComponent(document.getElementById('pickerSearch').value.trim());
            const src = encodeURIComponent(document.getElementById('pickerSource').value);
            const klas = encodeURIComponent(document.getElementById('pickerKlas').value);
            const dept = encodeURIComponent(document.getElementById('fDeptPemohon').value);

            try {
                const res = await fetch(`department_request.php?action=search_goods&q=${q}&source=${src}&klas=${klas}&dept=${dept}&_t=${Date.now()}`);
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message);

                if (!json.data || json.data.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fa-solid fa-box-open" style="font-size:22px; opacity:0.4; display:block; margin-bottom:6px;"></i>Tidak ada barang ditemukan. Coba ubah kata kunci atau gunakan opsi Input Baru.</td></tr>`;
                    return;
                }

                let html = '';
                json.data.forEach(item => {
                    const itemJson = JSON.stringify(item).replace(/"/g, '&quot;');
                    html += `
                        <tr>
                            <td><span class="${item.badge_class}">${escapeHtml(item.source_label)}</span></td>
                            <td><strong style="font-family:monospace; color:var(--primary);">${escapeHtml(item.kode_barang)}</strong></td>
                            <td>
                                <div>${escapeHtml(item.nama_barang)}</div>
                                <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">${escapeHtml(item.stok_info || '')}</div>
                            </td>
                            <td><span style="font-size:10px; background:rgba(0,0,0,0.05); padding:1px 5px; border-radius:3px;">${escapeHtml(item.klas)}</span></td>
                            <td style="text-align:center; font-weight:700;">${escapeHtml(item.sat)}</td>
                            <td style="font-size:10px;">
                                <div>${escapeHtml(item.ref_bc)} ${escapeHtml(item.ref_no_daftar)}</div>
                                <div style="color:var(--text-muted);">${escapeHtml(item.ref_supplier || item.ref_no_bukti || '')}</div>
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="btn-action btn-primary" style="height:24px; padding:0 8px; font-size:10px;" onclick='selectPickerItem(${itemJson})'>
                                    + Pilih
                                </button>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:16px;">Gagal memuat barang: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function selectPickerItem(item) {
            addItemRow({
                source_type: item.source,
                kode_barang: item.kode_barang,
                nama_barang: item.nama_barang,
                sat: item.sat,
                klas: item.klas,
                qty_req: 1,
                ref_bc: item.ref_bc,
                ref_no_daftar: item.ref_no_daftar,
                ref_no_bukti: item.ref_no_bukti,
                ref_nomor_aju: item.ref_nomor_aju,
                note: ''
            });
            Swal.mixin({ toast: true, position: 'top-end', timer: 1500, showConfirmButton: false }).fire({
                icon: 'success',
                title: `Item '${item.kode_barang}' ditambahkan`
            });
        }

        // --- APPROVAL / STATUS ACTIONS ---
        async function approveRequest(id, reqNo) {
            const confirm = await Swal.fire({
                title: 'Setujui Permintaan?',
                html: `Apakah Anda yakin ingin menyetujui permintaan barang <strong>${escapeHtml(reqNo)}</strong>?<br><small style="color:gray;">Setelah disetujui, gudang dapat langsung memproses pengeluaran barang.</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Setujui',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#52c41a'
            });
            if (!confirm.isConfirmed) return;

            postStatusChange(id, 'APPROVED');
        }

        async function rejectRequest(id, reqNo) {
            const { value: reason } = await Swal.fire({
                title: 'Tolak Permintaan?',
                text: `Masukkan alasan penolakan permintaan ${reqNo}:`,
                input: 'text',
                inputPlaceholder: 'Contoh: Stok tidak mencukupi, salah departemen...',
                showCancelButton: true,
                confirmButtonText: 'Tolak Permintaan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#f5222d',
                inputValidator: (v) => { if (!v) return 'Alasan penolakan wajib diisi!'; }
            });
            if (!reason) return;

            postStatusChange(id, 'REJECTED', reason);
        }

        async function completeRequest(id, reqNo) {
            const confirm = await Swal.fire({
                title: 'Selesaikan Pengeluaran?',
                html: `Tandai permintaan <strong>${escapeHtml(reqNo)}</strong> sebagai selesai dikeluarkan (COMPLETED)?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Selesai',
                cancelButtonText: 'Batal'
            });
            if (!confirm.isConfirmed) return;

            postStatusChange(id, 'COMPLETED');
        }

        async function deleteRequest(id, reqNo) {
            const confirm = await Swal.fire({
                title: 'Hapus Permintaan?',
                text: `Apakah Anda yakin ingin menghapus permintaan ${reqNo}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#f5222d'
            });
            if (!confirm.isConfirmed) return;

            postStatusChange(id, 'DELETE');
        }

        async function postStatusChange(id, st, reason = '') {
            try {
                Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const body = new URLSearchParams({ id: id, status: st, reason: reason });
                const res = await fetch('department_request.php?action=update_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                });
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message);

                Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1800, showConfirmButton: false });
                loadList();
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: err.message });
            }
        }

        function printBppb(id) {
            const w = window.open(`department_request.php?action=print_bppb&id=${encodeURIComponent(id)}`, '_blank', 'width=900,height=700,resizable=yes,scrollbars=yes');
            if (w) w.focus();
        }

        // --- UTILS ---
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    </script>
</body>
</html>

<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Purchase Order (PO) Management
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_po.srw / bc_po_hdr.srd / d_po_dtl.srd / d_rinci_po_diproses.srd)
 * Terhubung ke tabel bc_po_hdr & bc_po_dtl dengan integrasi Vendor bc_bs,
 * Material bc_material, Multi-Valuta, Pajak PPN/PPh, Realisasi RI/PI, Resizable Columns, Context Menu,
 * Excel Import/Export, Highlight No. PO, Reset ke Data Awal, dan Tema Gelap.
 */
session_start();
require_once __DIR__ . '/../datalogin.php';
require_once __DIR__ . '/audit_trail.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($_GET['cmp_id'] ?? ($cmp_id ?? '')));
if (!empty($_GET['cmp_id'])) {
    $sessionCmpId = $_GET['cmp_id'];
    $_SESSION['company_cmp_id'] = $sessionCmpId;
    $_SESSION['cmp_id'] = $sessionCmpId;
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']) || !empty($_GET['is_admin']);

$activeCmpName = $_SESSION['company_name'] ?? '';
if (empty($activeCmpName) && !empty($sessionCmpId)) {
    $qCmp = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        $activeCmpName = $rCmp['cmp_name'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'TPB ERP Enterprise';

// Auto-Heal: Pastikan tabel dan kolom bc_po_hdr dan bc_po_dtl tersedia lengkap
function ensurePoTables($koneksi) {
    // 1. Table bc_po_hdr
    $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_po_hdr'");
    if (!$checkHdr || $checkHdr->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_po_hdr` (
            `id` varchar(40) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `po_no` varchar(50) NOT NULL DEFAULT '',
            `po_dt` date DEFAULT NULL,
            `exp_dt` date DEFAULT NULL,
            `jatuhtempo` date DEFAULT NULL,
            `fob` char(2) DEFAULT '1',
            `term` varchar(30) DEFAULT 'Net 30',
            `ship` varchar(50) DEFAULT '',
            `vendor` varchar(50) NOT NULL DEFAULT '',
            `shipto` text DEFAULT NULL,
            `vendoristaxable` char(1) DEFAULT 'Y',
            `inclusivetax` char(1) DEFAULT 'N',
            `issubcon` char(1) DEFAULT 'N',
            `ncv` char(1) DEFAULT '0',
            `note` text DEFAULT NULL,
            `subtotal` decimal(18,2) DEFAULT '0.00',
            `disc_persen` decimal(5,2) DEFAULT '0.00',
            `disc_amt` decimal(18,2) DEFAULT '0.00',
            `ppn` decimal(18,2) DEFAULT '0.00',
            `pph` decimal(18,2) DEFAULT '0.00',
            `est_freight` decimal(18,2) DEFAULT '0.00',
            `total_order` decimal(18,2) DEFAULT '0.00',
            `terbayar` decimal(18,2) DEFAULT '0.00',
            `outstand` decimal(18,2) DEFAULT '0.00',
            `kurs` varchar(5) DEFAULT 'IDR',
            `rate` decimal(12,4) DEFAULT '1.0000',
            `set_by` varchar(50) DEFAULT '',
            `status` char(1) DEFAULT '0',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cmp_po` (`cmp_id`, `po_no`),
            KEY `idx_vendor` (`vendor`),
            KEY `idx_po_dt` (`po_dt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $colsH = [];
        $resH = $koneksi->query("SHOW COLUMNS FROM bc_po_hdr");
        if ($resH) while ($r = $resH->fetch_assoc()) $colsH[strtolower($r['Field'])] = true;
        $hdrExtra = [
            'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
            'jatuhtempo' => "date DEFAULT NULL",
            'issubcon' => "char(1) DEFAULT 'N'",
            'ncv' => "char(1) DEFAULT '0'",
            'pph' => "decimal(18,2) DEFAULT '0.00'",
            'terbayar' => "decimal(18,2) DEFAULT '0.00'",
            'outstand' => "decimal(18,2) DEFAULT '0.00'",
        ];
        foreach ($hdrExtra as $colName => $colDef) {
            if (!isset($colsH[$colName])) {
                try {
                    $koneksi->query("ALTER TABLE bc_po_hdr ADD COLUMN `$colName` $colDef");
                } catch (Throwable $e) {}
            }
        }
    }

    // 2. Table bc_po_dtl
    $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_po_dtl'");
    if (!$checkDtl || $checkDtl->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_po_dtl` (
            `id` varchar(40) NOT NULL DEFAULT '',
            `id_hdr` varchar(40) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `po_no` varchar(50) NOT NULL DEFAULT '',
            `item_no` int(11) NOT NULL DEFAULT '1',
            `seq` int(11) NOT NULL DEFAULT '1',
            `kode_barang` varchar(50) NOT NULL DEFAULT '',
            `nama_barang` varchar(255) NOT NULL DEFAULT '',
            `uraian` varchar(255) NOT NULL DEFAULT '',
            `color_cd` varchar(50) DEFAULT '',
            `size_cd` varchar(50) DEFAULT '',
            `satuan` varchar(20) DEFAULT '',
            `sat` varchar(20) DEFAULT '',
            `qty` decimal(18,4) DEFAULT '0.0000',
            `harga` decimal(18,4) DEFAULT '0.0000',
            `price` decimal(18,4) DEFAULT '0.0000',
            `diskon` decimal(18,2) DEFAULT '0.00',
            `disc` decimal(18,2) DEFAULT '0.00',
            `total` decimal(18,2) DEFAULT '0.00',
            `amount` decimal(18,2) DEFAULT '0.00',
            `wo_no` varchar(50) DEFAULT '',
            `pr_no` varchar(50) DEFAULT '',
            `id_pr` varchar(40) DEFAULT '',
            `exp_dt` date DEFAULT NULL,
            `note` varchar(255) DEFAULT '',
            PRIMARY KEY (`id`, `item_no`),
            KEY `idx_cmp_po` (`cmp_id`, `po_no`),
            KEY `idx_kode_barang` (`kode_barang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $colsD = [];
        $resD = $koneksi->query("SHOW COLUMNS FROM bc_po_dtl");
        if ($resD) while ($r = $resD->fetch_assoc()) $colsD[strtolower($r['Field'])] = true;
        $dtlExtra = [
            'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
            'id_hdr' => "varchar(40) NOT NULL DEFAULT ''",
            'po_no' => "varchar(50) NOT NULL DEFAULT ''",
            'item_no' => "int(11) NOT NULL DEFAULT '1'",
            'seq' => "int(11) NOT NULL DEFAULT '1'",
            'nama_barang' => "varchar(255) NOT NULL DEFAULT ''",
            'uraian' => "varchar(255) NOT NULL DEFAULT ''",
            'color_cd' => "varchar(50) DEFAULT ''",
            'size_cd' => "varchar(50) DEFAULT ''",
            'satuan' => "varchar(20) DEFAULT ''",
            'sat' => "varchar(20) DEFAULT ''",
            'harga' => "decimal(18,4) DEFAULT '0.0000'",
            'price' => "decimal(18,4) DEFAULT '0.0000'",
            'diskon' => "decimal(18,2) DEFAULT '0.00'",
            'disc' => "decimal(18,2) DEFAULT '0.00'",
            'total' => "decimal(18,2) DEFAULT '0.00'",
            'amount' => "decimal(18,2) DEFAULT '0.00'",
            'wo_no' => "varchar(50) DEFAULT ''",
            'pr_no' => "varchar(50) DEFAULT ''",
            'id_pr' => "varchar(40) DEFAULT ''",
            'exp_dt' => "date DEFAULT NULL",
            'note' => "varchar(255) DEFAULT ''",
        ];
        foreach ($dtlExtra as $colName => $colDef) {
            if (!isset($colsD[$colName])) {
                try {
                    $koneksi->query("ALTER TABLE bc_po_dtl ADD COLUMN `$colName` $colDef");
                } catch (Throwable $e) {}
            }
        }
        // Sinkronisasi data antara alias modern dan legacy
        try { $koneksi->query("UPDATE bc_po_dtl SET nama_barang = uraian WHERE (nama_barang = '' OR nama_barang IS NULL) AND (uraian <> '' AND uraian IS NOT NULL)"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET uraian = nama_barang WHERE (uraian = '' OR uraian IS NULL) AND (nama_barang <> '' AND nama_barang IS NOT NULL)"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET satuan = sat WHERE (satuan = '' OR satuan IS NULL) AND (sat <> '' AND sat IS NOT NULL)"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET sat = satuan WHERE (sat = '' OR sat IS NULL) AND (satuan <> '' AND satuan IS NOT NULL)"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET harga = price WHERE (harga = 0 OR harga IS NULL) AND price > 0"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET price = harga WHERE (price = 0 OR price IS NULL) AND harga > 0"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET diskon = disc WHERE (diskon = 0 OR diskon IS NULL) AND disc > 0"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET disc = diskon WHERE (disc = 0 OR disc IS NULL) AND diskon > 0"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET total = amount WHERE (total = 0 OR total IS NULL) AND amount > 0"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET amount = total WHERE (amount = 0 OR amount IS NULL) AND total > 0"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET item_no = seq WHERE (item_no = 1 OR item_no IS NULL) AND seq > 1"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl SET seq = item_no WHERE (seq = 1 OR seq IS NULL) AND item_no > 1"); } catch (Throwable $e) {}
        // Sinkronkan id_hdr dan po_no jika kosong untuk kompatibilitas PB legacy
        try { $koneksi->query("UPDATE bc_po_dtl d JOIN bc_po_hdr h ON d.id_hdr = h.id SET d.po_no = h.po_no WHERE (d.po_no = '' OR d.po_no IS NULL) AND (h.po_no <> '' AND h.po_no IS NOT NULL)"); } catch (Throwable $e) {}
        try { $koneksi->query("UPDATE bc_po_dtl d JOIN bc_po_hdr h ON d.po_no = h.po_no SET d.id_hdr = h.id WHERE (d.id_hdr = '' OR d.id_hdr IS NULL) AND (h.id <> '' AND h.id IS NOT NULL)"); } catch (Throwable $e) {}
    }

    // 3. Table bc_pi_hdr (Purchase Invoice Realisasi)
    try {
        $checkPiHdr = $koneksi->query("SHOW TABLES LIKE 'bc_pi_hdr'");
        if (!$checkPiHdr || $checkPiHdr->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pi_hdr` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `pi_no` varchar(50) NOT NULL DEFAULT '',
                `pi_dt` date DEFAULT NULL,
                `ship_dt` date DEFAULT NULL,
                `jatuhtempo` date DEFAULT NULL,
                `po_no` varchar(300) DEFAULT '',
                `fob` char(2) DEFAULT '1',
                `term` varchar(30) DEFAULT 'Net 30',
                `ship` varchar(50) DEFAULT '',
                `vendor` varchar(50) NOT NULL DEFAULT '',
                `shipto` text DEFAULT NULL,
                `vendoristaxable` char(1) DEFAULT 'Y',
                `inclusivetax` char(1) DEFAULT 'N',
                `issubcon` char(1) DEFAULT 'N',
                `ncv` char(1) DEFAULT '0',
                `note` text DEFAULT NULL,
                `subtotal` decimal(18,2) DEFAULT '0.00',
                `disc_persen` decimal(5,2) DEFAULT '0.00',
                `disc_amt` decimal(18,2) DEFAULT '0.00',
                `ppn` decimal(18,2) DEFAULT '0.00',
                `pph` decimal(18,2) DEFAULT '0.00',
                `est_freight` decimal(18,2) DEFAULT '0.00',
                `total_order` decimal(18,2) DEFAULT '0.00',
                `terbayar` decimal(18,2) DEFAULT '0.00',
                `outstand` decimal(18,2) DEFAULT '0.00',
                `kurs` varchar(5) DEFAULT 'IDR',
                `rate` decimal(12,4) DEFAULT '1.0000',
                `kd_fp` varchar(5) DEFAULT '',
                `no_fp` varchar(20) DEFAULT '',
                `no_bc` varchar(10) DEFAULT '',
                `tgl_bc` date DEFAULT NULL,
                `no_aju` varchar(30) DEFAULT '',
                `set_by` varchar(50) DEFAULT '',
                `status` char(1) DEFAULT '0',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_pi` (`cmp_id`, `pi_no`),
                KEY `idx_po_no` (`po_no`(191)),
                KEY `idx_vendor` (`vendor`),
                KEY `idx_pi_dt` (`pi_dt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $colsPiH = [];
            $resPiH = $koneksi->query("SHOW COLUMNS FROM bc_pi_hdr");
            if ($resPiH) while ($r = $resPiH->fetch_assoc()) $colsPiH[strtolower($r['Field'])] = true;
            $piHdrExtra = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'po_no'  => "varchar(300) DEFAULT ''",
            ];
            foreach ($piHdrExtra as $colName => $colDef) {
                if (!isset($colsPiH[$colName])) {
                    try {
                        $koneksi->query("ALTER TABLE bc_pi_hdr ADD COLUMN `$colName` $colDef");
                    } catch (Throwable $e) {}
                }
            }
        }
    } catch (Throwable $e) {}

    // 4. Table bc_pi_dtl
    try {
        $checkPiDtl = $koneksi->query("SHOW TABLES LIKE 'bc_pi_dtl'");
        if (!$checkPiDtl || $checkPiDtl->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pi_dtl` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `id_hdr` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `pi_no` varchar(50) NOT NULL DEFAULT '',
                `po_no` varchar(50) DEFAULT '',
                `ri_no` varchar(50) DEFAULT '',
                `seq` int(11) NOT NULL DEFAULT '1',
                `kode_barang` varchar(50) NOT NULL DEFAULT '',
                `uraian` varchar(255) NOT NULL DEFAULT '',
                `qty` decimal(18,4) DEFAULT '0.0000',
                `sat` varchar(20) DEFAULT '',
                `price` decimal(18,4) DEFAULT '0.0000',
                `disc` decimal(18,2) DEFAULT '0.00',
                `amount` decimal(18,2) DEFAULT '0.00',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`, `seq`),
                KEY `idx_cmp_pi` (`cmp_id`, `pi_no`),
                KEY `idx_hdr` (`id_hdr`),
                KEY `idx_po` (`po_no`),
                KEY `idx_kode_barang` (`kode_barang`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $colsPiD = [];
            $resPiD = $koneksi->query("SHOW COLUMNS FROM bc_pi_dtl");
            if ($resPiD) while ($r = $resPiD->fetch_assoc()) $colsPiD[strtolower($r['Field'])] = true;
            $piDtlExtra = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'po_no'  => "varchar(50) DEFAULT ''",
                'ri_no'  => "varchar(50) DEFAULT ''",
            ];
            foreach ($piDtlExtra as $colName => $colDef) {
                if (!isset($colsPiD[$colName])) {
                    try {
                        $koneksi->query("ALTER TABLE bc_pi_dtl ADD COLUMN `$colName` $colDef");
                    } catch (Throwable $e) {}
                }
            }
        }
    } catch (Throwable $e) {}

    ensureAuditLogTable($koneksi);
}
ensurePoTables($koneksi);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 0. AUDIT TRAIL LOG API
if ($action === 'audit_list' || $action === 'audit_detail') {
    handleAuditLogApi($koneksi, $sessionCmpId);
    exit;
}

// 1. LIST DATA PO (BROWSE TABLE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    
    $status    = trim($_GET['status'] ?? '');
    $search    = trim($_GET['search'] ?? '');
    $searchCol = trim($_GET['search_col'] ?? '');
    $tglAwal   = trim($_GET['tgl_awal'] ?? '');
    $tglAkhir  = trim($_GET['tgl_akhir'] ?? '');
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $limit     = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset    = ($page - 1) * $limit;

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(a.cmp_id = '$escCmp' OR a.cmp_id = '')";
    }
    if ($status !== '') {
        $stEsc = $koneksi->real_escape_string($status);
        $where[] = "a.status = '$stEsc'";
    }
    if (!empty($tglAwal)) {
        $taEsc = $koneksi->real_escape_string($tglAwal);
        $where[] = "a.po_dt >= '$taEsc'";
    }
    if (!empty($tglAkhir)) {
        $tkEsc = $koneksi->real_escape_string($tglAkhir);
        $where[] = "a.po_dt <= '$tkEsc'";
    }
    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        if ($searchCol === 'po_no') {
            $where[] = "a.po_no LIKE '%$sEsc%'";
        } elseif ($searchCol === 'vendor') {
            $where[] = "(a.vendor LIKE '%$sEsc%' OR b.NAMA LIKE '%$sEsc%')";
        } elseif ($searchCol === 'note') {
            $where[] = "a.note LIKE '%$sEsc%'";
        } else {
            $where[] = "(a.po_no LIKE '%$sEsc%' OR a.vendor LIKE '%$sEsc%' OR b.NAMA LIKE '%$sEsc%' OR a.note LIKE '%$sEsc%')";
        }
    }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(1) as total FROM bc_po_hdr a LEFT JOIN bc_bs b ON a.vendor = b.KODE AND (b.cmp_id = a.cmp_id OR b.cmp_id = '') $whereSql";
    $total = 0;
    $cQ = $koneksi->query($countSql);
    if ($cQ && $cRow = $cQ->fetch_assoc()) $total = (int)$cRow['total'];

    $sql = "SELECT a.*, 
                   IFNULL(b.NAMA, a.vendor) AS vendor_nama,
                   IFNULL(b.ALAMAT, '') AS vendor_alamat,
                   (SELECT COUNT(1) FROM bc_po_dtl d WHERE (d.id_hdr = a.id OR d.id = a.id OR (d.po_no = a.po_no AND d.po_no <> '')) AND (d.cmp_id = a.cmp_id OR d.cmp_id = '')) AS total_item,
                   (SELECT IFNULL(SUM(d.qty), 0) FROM bc_po_dtl d WHERE (d.id_hdr = a.id OR d.id = a.id OR (d.po_no = a.po_no AND d.po_no <> '')) AND (d.cmp_id = a.cmp_id OR d.cmp_id = '')) AS total_qty_po,
                   (SELECT IFNULL(SUM(r.qty), 0) FROM bc_ri_dtl r WHERE (r.po_no = a.po_no OR r.id_po = a.id) AND (r.cmp_id = a.cmp_id OR r.cmp_id = '')) AS total_qty_ri,
                   (SELECT COUNT(1) FROM bc_ri_hdr r WHERE r.po_no = a.po_no AND (r.cmp_id = a.cmp_id OR r.cmp_id = '')) AS count_ri,
                   (SELECT COUNT(DISTINCT d.id_hdr) FROM bc_pi_dtl d WHERE d.po_no = a.po_no AND (d.cmp_id = a.cmp_id OR d.cmp_id = '')) AS count_pi
            FROM bc_po_hdr a
            LEFT JOIN bc_bs b ON a.vendor = b.KODE AND (b.cmp_id = a.cmp_id OR b.cmp_id = '')
            $whereSql
            ORDER BY a.po_dt DESC, a.po_no DESC
            LIMIT $offset, $limit";

    $data = [];
    $res = $koneksi->query($sql);
    if ($res) {
        $todayTs = strtotime(date('Y-m-d'));
        while ($r = $res->fetch_assoc()) {
            $qtyPo = (float)($r['total_qty_po'] ?? 0);
            $qtyRi = (float)($r['total_qty_ri'] ?? 0);
            $sisaQty = max(0, $qtyPo - $qtyRi);
            $pct = ($qtyPo > 0) ? min(100, round(($qtyRi / $qtyPo) * 100)) : 0;
            $expTs = !empty($r['exp_dt']) ? strtotime(substr($r['exp_dt'], 0, 10)) : 0;
            $isOverdue = ($expTs > 0 && $expTs < $todayTs && $sisaQty > 0 && ($r['status'] ?? '0') !== '2');

            $r['qty_po']     = $qtyPo;
            $r['qty_ri']     = $qtyRi;
            $r['sisa_qty']   = $sisaQty;
            $r['pct']        = $pct;
            $r['is_overdue'] = $isOverdue;
            $data[] = $r;
        }
    }

    // KPI Summary
    $kpiWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escCmp' OR cmp_id = '')" : "";
    $kpiQ = $koneksi->query("SELECT 
        COUNT(1) AS total_po,
        SUM(CASE WHEN status = '0' THEN 1 ELSE 0 END) AS po_draft,
        SUM(CASE WHEN status = '1' THEN 1 ELSE 0 END) AS po_approved,
        SUM(CASE WHEN status = '2' THEN 1 ELSE 0 END) AS po_closed,
        SUM(total_order) AS sum_total_order
        FROM bc_po_hdr $kpiWhere");
    $kpi = [
        'total_po'        => 0,
        'po_draft'        => 0,
        'po_approved'     => 0,
        'po_closed'       => 0,
        'sum_total_order' => 0
    ];
    if ($kpiQ && $kRow = $kpiQ->fetch_assoc()) {
        $kpi = [
            'total_po'        => (int)($kRow['total_po'] ?? 0),
            'po_draft'        => (int)($kRow['po_draft'] ?? 0),
            'po_approved'     => (int)($kRow['po_approved'] ?? 0),
            'po_closed'       => (int)($kRow['po_closed'] ?? 0),
            'sum_total_order' => (float)($kRow['sum_total_order'] ?? 0)
        ];
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $data,
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        'kpi'    => $kpi
    ]);
    exit;
}

// 2. GET DETAIL PO (UNTUK EDIT & PREVIEW)
if ($action === 'get' || $action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID PO tidak valid']);
        exit;
    }
    $idEsc = $koneksi->real_escape_string($id);
    $cmpFilter = !empty($sessionCmpId) ? "AND (a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "";

    $hdrQ = $koneksi->query("SELECT a.*, 
                                    b.NAMA as vendor_name, 
                                    b.ALAMAT as vendor_alamat, 
                                    b.NPWP as vendor_npwp, 
                                    b.nitku as vendor_nitku
                             FROM bc_po_hdr a
                             LEFT JOIN bc_bs b ON a.vendor = b.KODE AND (b.cmp_id = a.cmp_id OR b.cmp_id = '')
                             WHERE a.id = '$idEsc' $cmpFilter LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data PO tidak ditemukan']);
        exit;
    }
    $hdr = $hdrQ->fetch_assoc();

    // Ambil baris detail item beserta realisasi penerimaan (RI)
    $items = [];
    $dtlSql = "SELECT d.*, 
                      (SELECT IFNULL(SUM(r.qty), 0) FROM bc_ri_dtl r 
                       WHERE (r.id_po = d.id OR (r.po_no = d.po_no AND r.kode_barang = d.kode_barang)) 
                       AND (r.cmp_id = '$escCmp' OR r.cmp_id = '')) AS qty_terima 
               FROM bc_po_dtl d 
               WHERE (d.id_hdr = '$idEsc' OR d.id = '$idEsc' OR (d.po_no = '{$hdr['po_no']}' AND d.po_no <> '')) 
               AND (d.cmp_id = '$escCmp' OR d.cmp_id = '') 
               ORDER BY d.item_no ASC, d.seq ASC";
    $dtlQ = $koneksi->query($dtlSql);
    if ($dtlQ) {
        while ($d = $dtlQ->fetch_assoc()) {
            $qOrder = (float)($d['qty'] ?? 0);
            $qTerima = (float)($d['qty_terima'] ?? 0);
            $d['qty_terima'] = $qTerima;
            $d['sisa_qty']   = max(0, $qOrder - $qTerima);
            $items[] = $d;
        }
    }

    // Periksa apakah sudah ada penerimaan RI
    $checkRi = $koneksi->query("SELECT COUNT(1) as cnt FROM bc_ri_hdr WHERE po_no = '{$hdr['po_no']}' AND (cmp_id = '$escCmp' OR cmp_id = '')");
    $hasRi = false;
    if ($checkRi && $rRi = $checkRi->fetch_assoc()) {
        $hasRi = ((int)$rRi['cnt'] > 0);
    }

    echo json_encode(['status' => 'OK', 'header' => $hdr, 'items' => $items, 'has_ri' => $hasRi]);
    exit;
}

// 3. SIMPAN TRANSAKSI PO (BARU / UPDATE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Terima JSON body atau FormData
    $rawData = file_get_contents('php://input');
    $payload = json_decode($rawData, true);
    if (!is_array($payload)) $payload = $_POST;

    $poId         = trim($payload['id'] ?? '');
    $isEdit       = !empty($poId);

    // Snapshot data lama sebelum diubah untuk Audit Trail (apa yang diganti)
    $oldHdr = null;
    $oldItems = [];
    if ($isEdit) {
        $poIdEsc = $koneksi->real_escape_string($poId);
        $qOldHdr = $koneksi->query("SELECT * FROM bc_po_hdr WHERE id = '$poIdEsc' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
        if ($qOldHdr && $rowH = $qOldHdr->fetch_assoc()) {
            $oldHdr = $rowH;
            $poNoOld = $oldHdr['po_no'] ?? '';
            $qOldItems = $koneksi->query("SELECT * FROM bc_po_dtl WHERE (id = '$poIdEsc' OR po_no = '$poNoOld') AND (cmp_id = '$escCmp' OR cmp_id = '') ORDER BY item_no ASC");
            if ($qOldItems) {
                while ($ri = $qOldItems->fetch_assoc()) $oldItems[] = $ri;
            }
        }
    }
    $poNo         = trim($payload['po_no'] ?? '');
    $poDt         = trim($payload['po_dt'] ?? date('Y-m-d'));
    $expDt        = trim($payload['exp_dt'] ?? '');
    $jatuhtempo   = trim($payload['jatuhtempo'] ?? '');
    $vendor       = trim($payload['vendor'] ?? '');
    $shipto       = trim($payload['shipto'] ?? '');
    $term         = trim($payload['term'] ?? 'Net 30');
    $fob          = trim($payload['fob'] ?? '1');
    $kurs         = strtoupper(trim($payload['kurs'] ?? 'IDR'));
    $rate         = (float)($payload['rate'] ?? 1);
    $vendorTax    = ($payload['vendoristaxable'] ?? 'Y') === 'N' ? 'N' : 'Y';
    $inclusiveTax = ($payload['inclusivetax'] ?? 'N') === 'Y' ? 'Y' : 'N';
    $isSubcon     = ($payload['issubcon'] ?? 'N') === 'Y' ? 'Y' : 'N';
    $ncv          = ($payload['ncv'] ?? '0') === '1' ? '1' : '0';
    $note         = trim($payload['note'] ?? '');
    $status       = trim((string)($payload['status'] ?? ''));
    if ($status === '' && $isEdit && $oldHdr) {
        $status = (string)($oldHdr['status'] ?? '0');
    }
    if ($status === '') $status = '0';
    $subtotal     = (float)($payload['subtotal'] ?? 0);
    $discPersen   = (float)($payload['disc_persen'] ?? 0);
    $discAmt      = (float)($payload['disc_amt'] ?? 0);
    $ppn          = (float)($payload['ppn'] ?? 0);
    $pph          = (float)($payload['pph'] ?? 0);
    $estFreight   = (float)($payload['est_freight'] ?? 0);
    $totalOrder   = (float)($payload['total_order'] ?? 0);
    $terbayar     = (float)($payload['terbayar'] ?? 0);
    $outstand     = $totalOrder - $terbayar;
    $setBy        = $_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

    if (empty($vendor)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Supplier / Vendor pemasok wajib dipilih.']);
        exit;
    }

    // Auto-generate PO No jika kosong
    if (empty($poNo)) {
        $ym = date('ym');
        $rand = rand(100, 999);
        $poNo = "PO-{$ym}-{$rand}";
    }

    $items = $payload['items'] ?? [];
    if (is_string($items)) $items = json_decode($items, true);
    if (!is_array($items) || count($items) === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Harap masukkan minimal 1 baris rincian barang pada PO.']);
        exit;
    }

    if (!$isEdit) {
        $poId = bin2hex(random_bytes(16));
        $check = $koneksi->query("SELECT 1 FROM bc_po_hdr WHERE po_no = '$poNo' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
        if ($check && $check->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Nomor PO '$poNo' sudah digunakan sebelumnya. Harap gunakan nomor lain."]);
            exit;
        }

        $stmt = $koneksi->prepare("INSERT INTO bc_po_hdr (
            id, cmp_id, po_no, po_dt, exp_dt, jatuhtempo, fob, term, vendor, shipto, 
            vendoristaxable, inclusivetax, issubcon, ncv, note, subtotal, disc_persen, disc_amt, 
            ppn, pph, est_freight, total_order, terbayar, outstand, kurs, rate, status, set_by
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )");
        if (!$stmt) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Database prepare failed: ' . $koneksi->error]);
            exit;
        }
        $expVal = !empty($expDt) ? $expDt : null;
        $jtVal  = !empty($jatuhtempo) ? $jatuhtempo : null;
        $stmt->bind_param("sssssssssssssssdddddddddssss",
            $poId, $sessionCmpId, $poNo, $poDt, $expVal, $jtVal, $fob, $term, $vendor, $shipto,
            $vendorTax, $inclusiveTax, $isSubcon, $ncv, $note, $subtotal, $discPersen, $discAmt,
            $ppn, $pph, $estFreight, $totalOrder, $terbayar, $outstand, $kurs, $rate, $status, $setBy
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan Header PO: ' . $err]);
            exit;
        }
        $stmt->close();
    } else {
        $expVal = !empty($expDt) ? $expDt : null;
        $jtVal  = !empty($jatuhtempo) ? $jatuhtempo : null;
        $stmt = $koneksi->prepare("UPDATE bc_po_hdr SET 
            po_no = ?, po_dt = ?, exp_dt = ?, jatuhtempo = ?, fob = ?, term = ?, vendor = ?, shipto = ?, 
            vendoristaxable = ?, inclusivetax = ?, issubcon = ?, ncv = ?, note = ?, subtotal = ?, disc_persen = ?, disc_amt = ?, 
            ppn = ?, pph = ?, est_freight = ?, total_order = ?, terbayar = ?, outstand = ?, kurs = ?, rate = ?, status = ?, set_by = ?
            WHERE id = ? AND (cmp_id = ? OR cmp_id = '')");
        if (!$stmt) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Database prepare update failed: ' . $koneksi->error]);
            exit;
        }
        $stmt->bind_param("sssssssssssssdddddddddssssss",
            $poNo, $poDt, $expVal, $jtVal, $fob, $term, $vendor, $shipto,
            $vendorTax, $inclusiveTax, $isSubcon, $ncv, $note, $subtotal, $discPersen, $discAmt,
            $ppn, $pph, $estFreight, $totalOrder, $terbayar, $outstand, $kurs, $rate, $status, $setBy,
            $poId, $sessionCmpId
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal update Header PO: ' . $err]);
            exit;
        }
        $stmt->close();

        // Hapus detail lama untuk ditulis ulang
        $koneksi->query("DELETE FROM bc_po_dtl WHERE (id_hdr = '$poId' OR id = '$poId' OR po_no = '$poNo') AND (cmp_id = '$escCmp' OR cmp_id = '')");
    }

    // Insert Baris Item Detail (Kompatibel skema modern & PB legacy TPB-ERP)
    $stmtDtl = $koneksi->prepare("INSERT INTO bc_po_dtl (
        id, id_hdr, cmp_id, po_no, item_no, seq, kode_barang, nama_barang, uraian, color_cd, size_cd, satuan, sat, qty, harga, price, diskon, disc, total, amount, wo_no, pr_no, id_pr, exp_dt, note
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmtDtl) {
        $itemNo = 1;
        foreach ($items as $it) {
            $dtlId  = md5(uniqid(mt_rand(), true));
            $kdBrg  = trim($it['kode_barang'] ?? '');
            $nmBrg  = trim($it['nama_barang'] ?? ($it['uraian'] ?? ''));
            $color  = trim($it['color_cd'] ?? '');
            $size   = trim($it['size_cd'] ?? '');
            $sat    = trim($it['satuan'] ?? ($it['sat'] ?? 'PCE'));
            $qty    = (float)($it['qty'] ?? 0);
            $hrg    = (float)($it['harga'] ?? ($it['price'] ?? 0));
            $disc   = (float)($it['diskon'] ?? ($it['disc'] ?? 0));
            $tot    = (float)($it['total'] ?? ($it['amount'] ?? 0));
            $wo     = trim($it['wo_no'] ?? '');
            $pr     = trim($it['pr_no'] ?? '');
            $idPr   = trim($it['id_pr'] ?? '');
            $itExp  = !empty($it['exp_dt']) ? $it['exp_dt'] : null;
            $nt     = trim($it['note'] ?? '');

            if (!empty($kdBrg) && $qty > 0) {
                $stmtDtl->bind_param("ssssiisssssssddddddsssss",
                    $dtlId, $poId, $sessionCmpId, $poNo, $itemNo, $itemNo,
                    $kdBrg, $nmBrg, $nmBrg, $color, $size, $sat, $sat,
                    $qty, $hrg, $hrg, $disc, $disc, $tot, $tot,
                    $wo, $pr, $idPr, $itExp, $nt
                );
                $stmtDtl->execute();
                $itemNo++;
            }
        }
        $stmtDtl->close();
    }

    // Sinkronisasi Pemenuhan Purchase Requisition (PR) ke bc_poreq_dtl & bc_poreq_hdr
    try {
        $touchedPrs = [];
        foreach ($items as $it) {
            $itemPr = trim($it['pr_no'] ?? '');
            $itemKd = trim($it['kode_barang'] ?? '');
            $itemQty = (float)($it['qty'] ?? 0);
            if (!empty($itemPr) && !empty($itemKd) && $itemQty > 0) {
                $escItemPr = $koneksi->real_escape_string($itemPr);
                $escItemKd = $koneksi->real_escape_string($itemKd);
                $escItemPo = $koneksi->real_escape_string($poNo);

                // Hitung total qty PO yang mengacu pada PR dan item ini
                $koneksi->query("UPDATE bc_poreq_dtl 
                                 SET qty_po = (SELECT IFNULL(SUM(qty), 0) FROM bc_po_dtl WHERE pr_no = '{$escItemPr}' AND kode_barang = '{$escItemKd}'),
                                     sisa_qty = GREATEST(0, qty - (SELECT IFNULL(SUM(qty), 0) FROM bc_po_dtl WHERE pr_no = '{$escItemPr}' AND kode_barang = '{$escItemKd}')),
                                     po_no = '{$escItemPo}'
                                 WHERE pr_no = '{$escItemPr}' AND kode_barang = '{$escItemKd}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
                $touchedPrs[$itemPr] = true;
            }
        }

        // Update status bc_poreq_hdr jika item PR sudah diproses ke PO
        foreach (array_keys($touchedPrs) as $pNo) {
            $escP = $koneksi->real_escape_string($pNo);
            $chkPr = $koneksi->query("SELECT IFNULL(SUM(sisa_qty), 0) as total_sisa FROM bc_poreq_dtl WHERE pr_no = '{$escP}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            if ($chkPr && $cRow = $chkPr->fetch_assoc()) {
                $newPrSt = ((float)$cRow['total_sisa'] <= 0) ? '4' : '3'; // 4 = Closed (selesai), 3 = In PO (sedang diproses)
                $koneksi->query("UPDATE bc_poreq_hdr SET status = '{$newPrSt}' WHERE pr_no = '{$escP}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            }
        }
    } catch (\Throwable $ePr) {
        // Abaikan jika tabel belum ada atau error non-fatal
    }

    // Catat Audit Trail (Apa yang diganti / ditambahkan, oleh siapa, dan kapan)
    try {
        if ($isEdit && $oldHdr) {
            $hdrLabels = [
                'po_no'       => 'No. PO',
                'po_dt'       => 'Tgl PO',
                'exp_dt'      => 'Tgl Dibutuhkan',
                'jatuhtempo'  => 'Jatuh Tempo',
                'vendor'      => 'Supplier',
                'term'        => 'Syarat Pembayaran',
                'fob'         => 'FOB',
                'kurs'        => 'Valuta',
                'rate'        => 'Kurs',
                'issubcon'    => 'Subkontrak',
                'ncv'         => 'NCV',
                'note'        => 'Catatan',
                'subtotal'    => 'Subtotal DPP',
                'disc_persen' => 'Diskon %',
                'disc_amt'    => 'Nilai Diskon',
                'ppn'         => 'PPN',
                'pph'         => 'PPh',
                'est_freight' => 'Freight / Ongkir',
                'total_order' => 'Total Order',
                'status'      => 'Status'
            ];
            $diff = diffAuditDocument($oldHdr, $payload, $oldItems, $items, $hdrLabels, 'kode_barang');
            if ($diff['has_changes']) {
                recordAuditLog($koneksi, [
                    'cmp_id'       => $sessionCmpId,
                    'module'       => 'PO',
                    'doc_no'       => $poNo,
                    'doc_id'       => $poId,
                    'action_type'  => 'UPDATE',
                    'summary'      => $diff['summary'],
                    'changes_json' => $diff['changes'],
                    'old_snapshot' => ['header' => $oldHdr, 'items' => $oldItems],
                    'new_snapshot' => ['header' => $payload, 'items' => $items],
                    'user_id'      => $_SESSION['user_id'] ?? 'SYSTEM',
                    'user_name'    => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'SYSTEM')
                ]);
            }
        } else {
            recordAuditLog($koneksi, [
                'cmp_id'       => $sessionCmpId,
                'module'       => 'PO',
                'doc_no'       => $poNo,
                'doc_id'       => $poId,
                'action_type'  => 'INSERT',
                'summary'      => "Purchase Order baru dibuat: No. {$poNo}, Supplier: {$vendor}, Total: " . number_format($totalOrder, 2, '.', ',') . ", " . count($items) . " item",
                'new_snapshot' => ['header' => $payload, 'items' => $items],
                'user_id'      => $_SESSION['user_id'] ?? 'SYSTEM',
                'user_name'    => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'SYSTEM')
            ]);
        }
    } catch (Throwable $e) {}

    echo json_encode(['status' => 'OK', 'message' => 'Purchase Order berhasil disimpan.', 'id' => $poId, 'po_no' => $poNo]);
    exit;
}

// 4. HAPUS DOKUMEN PO
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID PO tidak valid']);
        exit;
    }
    $idEsc = $koneksi->real_escape_string($id);
    $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '')" : "";

    $poNo = '';
    $oldHdr = null;
    $oldItems = [];

    $q = $koneksi->query("SELECT * FROM bc_po_hdr WHERE id = '$idEsc' $cmpFilter LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        $oldHdr = $r;
        $poNo = $r['po_no'];
        $qDtl = $koneksi->query("SELECT * FROM bc_po_dtl WHERE (id_hdr = '$idEsc' OR id = '$idEsc' OR (po_no = '$poNo' AND po_no <> '')) $cmpFilter ORDER BY item_no ASC, seq ASC");
        if ($qDtl) {
            while ($ri = $qDtl->fetch_assoc()) $oldItems[] = $ri;
        }
    }

    // Cek apakah sudah ada realisasi RI
    if (!empty($poNo)) {
        $checkRi = $koneksi->query("SELECT ri_no FROM bc_ri_hdr WHERE po_no = '$poNo' $cmpFilter LIMIT 1");
        if ($checkRi && $checkRi->num_rows > 0) {
            $ri = $checkRi->fetch_assoc();
            echo json_encode(['status' => 'ERROR', 'message' => "Tidak dapat menghapus PO ini karena sudah memiliki realisasi Penerimaan Barang (RI: {$ri['ri_no']})."]);
            exit;
        }
    }

    $koneksi->query("DELETE FROM bc_po_dtl WHERE (id_hdr = '$idEsc' OR id = '$idEsc' OR (po_no = '$poNo' AND po_no <> '')) $cmpFilter");
    $del = $koneksi->query("DELETE FROM bc_po_hdr WHERE id = '$idEsc' $cmpFilter LIMIT 1");
    if ($del) {
        // Catat Audit Trail Penghapusan Data (apa yang dihapus, oleh siapa, dan kapan)
        try {
            $itemCount = count($oldItems);
            $totalVal = number_format((float)($oldHdr['total_order'] ?? 0), 2, '.', ',');
            $vnd = $oldHdr['vendor'] ?? '-';
            recordAuditLog($koneksi, [
                'cmp_id'       => $sessionCmpId,
                'module'       => 'PO',
                'doc_no'       => $poNo,
                'doc_id'       => $id,
                'action_type'  => 'DELETE',
                'summary'      => "Dokumen Purchase Order dihapus: No. {$poNo} (Supplier: {$vnd}, Total: {$totalVal}, {$itemCount} item barang)",
                'old_snapshot' => ['header' => $oldHdr, 'items' => $oldItems],
                'user_id'      => $_SESSION['user_id'] ?? 'SYSTEM',
                'user_name'    => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'SYSTEM')
            ]);
        } catch (Throwable $e) {}
        echo json_encode(['status' => 'OK', 'message' => "Purchase Order '$poNo' berhasil dihapus."]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 4b. UPDATE STATUS PO (APPROVE / CLOSE / RE-OPEN)
if ($action === 'update_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $newStatus = trim($_POST['status'] ?? '');
    if (empty($id) || $newStatus === '') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Parameter ID atau Status tidak valid']);
        exit;
    }
    if (!in_array($newStatus, ['0', '1', '2', '3'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nilai Status tidak valid']);
        exit;
    }
    $idEsc = $koneksi->real_escape_string($id);
    $stEsc = $koneksi->real_escape_string($newStatus);
    $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '')" : "";

    $q = $koneksi->query("SELECT * FROM bc_po_hdr WHERE id = '$idEsc' $cmpFilter LIMIT 1");
    if (!$q || $q->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data Purchase Order tidak ditemukan']);
        exit;
    }
    $oldHdr = $q->fetch_assoc();
    $oldStatus = (string)($oldHdr['status'] ?? '0');
    $poNo = $oldHdr['po_no'];

    if ($oldStatus === $newStatus) {
        echo json_encode(['status' => 'OK', 'message' => 'Status tidak berubah', 'current_status' => $newStatus]);
        exit;
    }

    $statusMap = ['0' => 'Draft', '1' => 'Approved (Disetujui)', '2' => 'Closed (Selesai)', '3' => 'Batal'];
    $oldLabel = $statusMap[$oldStatus] ?? "Status $oldStatus";
    $newLabel = $statusMap[$newStatus] ?? "Status $newStatus";

    $upd = $koneksi->query("UPDATE bc_po_hdr SET status = '$stEsc' WHERE id = '$idEsc' $cmpFilter LIMIT 1");
    if (!$upd) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $koneksi->error]);
        exit;
    }

    // Catat ke Audit Trail
    try {
        recordAuditLog($koneksi, [
            'cmp_id'       => $sessionCmpId,
            'module'       => 'PO',
            'doc_no'       => $poNo,
            'doc_id'       => $id,
            'action_type'  => 'UPDATE',
            'summary'      => "Perubahan Status PO No. {$poNo}: {$oldLabel} ➔ {$newLabel}",
            'changes_json' => [
                'header' => [
                    'status' => ['label' => 'Status Dokumen', 'old' => $oldLabel, 'new' => $newLabel]
                ]
            ],
            'old_snapshot' => ['header' => $oldHdr],
            'new_snapshot' => ['header' => array_merge($oldHdr, ['status' => $newStatus])],
            'user_id'      => $_SESSION['user_id'] ?? 'SYSTEM',
            'user_name'    => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'SYSTEM')
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'status'         => 'OK',
        'message'        => "Status Purchase Order '$poNo' berhasil diubah menjadi $newLabel",
        'new_status'     => $newStatus,
        'new_status_lbl' => $newLabel
    ]);
    exit;
}

// 5. HISTORY HARGA PEMBELIAN (PB m_42)
if ($action === 'history_price') {
    header('Content-Type: application/json; charset=utf-8');
    $kodeBrg     = trim($_GET['kode_barang'] ?? '');
    $vendor      = trim($_GET['vendor'] ?? '');
    $filterBuyer = (int)($_GET['filter_buyer'] ?? 1);

    if (empty($kodeBrg)) {
        echo json_encode(['status' => 'OK', 'data' => []]);
        exit;
    }

    $escKd = $koneksi->real_escape_string($kodeBrg);
    $escVn = $koneksi->real_escape_string($vendor);
    $cmpFilter = !empty($sessionCmpId) ? "AND (h.cmp_id = '$escCmp' OR h.cmp_id = '')" : "";

    $whereVendor = ($filterBuyer === 1 && !empty($vendor)) ? "AND h.vendor = '$escVn'" : "";

    // Ambil histori dari PO, RI, dan PI
    $sql = "(SELECT 'PO' as tipe, h.po_no as doc_no, h.po_dt as doc_dt, h.vendor, IFNULL(b.NAMA, h.vendor) as vendor_nama,
                    d.qty, d.satuan as sat, d.harga as price, d.diskon as disc, h.kurs, h.rate
             FROM bc_po_dtl d
             JOIN bc_po_hdr h ON (d.id_hdr = h.id OR d.id = h.id OR (d.po_no = h.po_no AND d.po_no <> '')) AND (d.cmp_id = h.cmp_id OR d.cmp_id = '')
             LEFT JOIN bc_bs b ON h.vendor = b.KODE AND (b.cmp_id = h.cmp_id OR b.cmp_id = '')
             WHERE d.kode_barang = '$escKd' $whereVendor $cmpFilter)
            UNION ALL
            (SELECT 'PI' as tipe, h.pi_no as doc_no, h.pi_dt as doc_dt, h.vendor, IFNULL(b.NAMA, h.vendor) as vendor_nama,
                    d.qty, d.sat, d.price, d.disc, h.kurs, h.rate
             FROM bc_pi_dtl d
             JOIN bc_pi_hdr h ON d.id_hdr = h.id AND (d.cmp_id = h.cmp_id OR d.cmp_id = '')
             LEFT JOIN bc_bs b ON h.vendor = b.KODE AND (b.cmp_id = h.cmp_id OR b.cmp_id = '')
             WHERE d.kode_barang = '$escKd' $whereVendor $cmpFilter)
            ORDER BY doc_dt DESC, doc_no DESC LIMIT 25";

    $data = [];
    $res = $koneksi->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// 6. SEARCH MASTER MATERIAL / BARANG
if ($action === 'search_materials') {
    header('Content-Type: application/json; charset=utf-8');
    $klas = trim($_GET['klas'] ?? 'RM');
    $q    = trim($_GET['q'] ?? '');

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(cmp_id = '$escCmp' OR cmp_id = '')";
    }

    // Mapping Klasifikasi
    $klasMap = [
        'RM' => 'Raw Materials',
        'FG' => 'Finished Goods',
        'WI' => 'Work In Progress',
        'CG' => 'Capital Goods',
        'W'  => 'Wastes / Scrap'
    ];
    $targetKlas = $klasMap[$klas] ?? $klas;
    $escKlas = $koneksi->real_escape_string($targetKlas);
    $where[] = "(klas = '$escKlas' OR klas = '$klas')";

    if (!empty($q)) {
        $escQ = $koneksi->real_escape_string($q);
        $where[] = "(kode_barang LIKE '%$escQ%' OR uraian LIKE '%$escQ%')";
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT KODE_BARANG as kode_barang, URAIAN as uraian, IFNULL(KODE_SATUAN, '') as satuan, IFNULL(UNIT_PRICE, 0) as harga_satuan, IFNULL(BERAT, 0) as berat, KLAS as klas
            FROM bc_material
            WHERE $whereSql
            ORDER BY KODE_BARANG ASC
            LIMIT 60";

    $data = [];
    $res = $koneksi->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// 6A. CEK VALIDASI KODE BARANG KE bc_material (UNTUK VISUAL ALERT PO)
if ($action === 'check_material_codes') {
    header('Content-Type: application/json; charset=utf-8');
    $rawCodes = $_POST['codes'] ?? ($_GET['codes'] ?? '');
    if (is_string($rawCodes)) {
        $codes = array_filter(array_map('trim', explode(',', $rawCodes)));
    } elseif (is_array($rawCodes)) {
        $codes = array_filter(array_map('trim', $rawCodes));
    } else {
        $codes = [];
    }

    if (empty($codes)) {
        echo json_encode(['status' => 'OK', 'registered' => [], 'unregistered' => []]);
        exit;
    }

    $escaped = array_map(function($c) use ($koneksi) {
        return "'" . $koneksi->real_escape_string($c) . "'";
    }, $codes);
    $inSql = implode(',', $escaped);

    $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";
    $res = $koneksi->query("SELECT DISTINCT KODE_BARANG FROM bc_material WHERE KODE_BARANG IN ($inSql) $cmpFilter");
    
    $registered = [];
    if ($res) {
        while ($r = $res->fetch_row()) {
            $registered[] = $r[0];
        }
    }

    $regMap = array_flip($registered);
    $unregistered = [];
    foreach ($codes as $c) {
        if (!isset($regMap[$c])) {
            $unregistered[] = $c;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'registered' => array_values(array_unique($registered)),
        'unregistered' => array_values(array_unique($unregistered))
    ]);
    exit;
}

// 6B. QUICK ADD MASTER BARANG DARI MODAL PO
if ($action === 'quick_add_material') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim($_POST['kode_barang'] ?? '');
    $uraian = trim($_POST['uraian'] ?? '');
    $satuan = trim($_POST['kode_satuan'] ?? 'PCE');
    $klas = trim($_POST['klas'] ?? 'Raw Materials');
    $posTarif = trim($_POST['pos_tarif'] ?? '');
    $unitPrice = floatval($_POST['unit_price'] ?? 0);
    $currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'user');

    if (empty($kode) || empty($uraian)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Barang dan Uraian / Nama Barang wajib diisi!']);
        exit;
    }

    $escKode = $koneksi->real_escape_string($kode);
    $escUraian = $koneksi->real_escape_string($uraian);
    $escSatuan = $koneksi->real_escape_string($satuan);
    $escKlas = $koneksi->real_escape_string($klas);
    $escPosTarif = $koneksi->real_escape_string($posTarif);

    // Cek apakah sudah ada
    $chk = $koneksi->query("SELECT KODE_BARANG FROM bc_material WHERE KODE_BARANG = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['status' => 'OK', 'message' => "Kode Barang '$kode' sudah terdaftar di Master Barang.", 'already_exists' => true]);
        exit;
    }

    $ins = $koneksi->query("INSERT INTO bc_material (cmp_id, KODE_BARANG, URAIAN, KODE_SATUAN, KLAS, POS_TARIF, UNIT_PRICE, AKTIF, TGL_REGISTER, USER_ID)
                            VALUES ('$escCmp', '$escKode', '$escUraian', '$escSatuan', '$escKlas', '$escPosTarif', $unitPrice, 'Y', NOW(), '$currentUser')");
    if ($ins) {
        echo json_encode(['status' => 'OK', 'message' => "Kode Barang '$kode' berhasil didaftarkan ke Master Barang!"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mendaftarkan barang: ' . $koneksi->error]);
    }
    exit;
}

// 6C. AMBIL OTORITAS APPROVAL PO DARI bc_po_user (TERMASUK TANDA TANGAN DIGITAL)
if ($action === 'get_po_approvers') {
    header('Content-Type: application/json; charset=utf-8');
    $dept = trim($_REQUEST['dept'] ?? '*');
    $escDept = $koneksi->real_escape_string($dept);
    $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";

    $sql = "SELECT id, cmp_id, doc_type, dept_kode, user_id, nama_lengkap, jabatan, level_approval, urutan, limit_min, limit_max, is_bod_delegate, ttd_digital, aktif
            FROM bc_po_user
            WHERE aktif = 'Y' AND doc_type IN ('PO', 'ALL') 
              AND (dept_kode = '$escDept' OR dept_kode = '*' OR dept_kode = 'ALL')
              $cmpFilter
            ORDER BY urutan ASC, FIELD(level_approval, 'PEMBUAT', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'FINAL'), id ASC";
    
    $approvers = [];
    $res = $koneksi->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $approvers[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $approvers]);
    exit;
}

// 7. LOOKUP VENDOR / SUPPLIER
if ($action === 'lookup_vendor') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '')" : "";
    $q = $koneksi->query("SELECT KODE as kode, NAMA as nama, NAMA_PENDEK as nama_pendek, ALAMAT as alamat, NPWP as npwp, nitku, PAY_TERM as pay_term, DELI_TERM as deli_term, kurs, nonpkp 
                          FROM bc_bs 
                          WHERE TIPE IN ('S', 'BS') AND (AKTIF <> 'N' OR AKTIF IS NULL OR AKTIF = 'Y' OR AKTIF = '') $cmpFilter 
                          ORDER BY NAMA ASC LIMIT 500");
    $list = [];
    if ($q) {
        while ($r = $q->fetch_assoc()) $list[] = $r;
    }
    echo json_encode(['status' => 'OK', 'data' => $list]);
    exit;
}

// 8. TRACE REALISASI PO (TRACKING PENGADAAN: RI & PI) Sesuai PB d_rinci_po_diproses
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID PO tidak valid']);
        exit;
    }
    $idEsc = $koneksi->real_escape_string($id);
    $cmpFilter = !empty($sessionCmpId) ? "AND (h.cmp_id = '$escCmp' OR h.cmp_id = '')" : "";

    $hdrQ = $koneksi->query("SELECT h.*, IFNULL(b.NAMA, h.vendor) as vendor_name FROM bc_po_hdr h LEFT JOIN bc_bs b ON h.vendor = b.KODE AND (b.cmp_id = h.cmp_id OR b.cmp_id = '') WHERE h.id = '$idEsc' $cmpFilter LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'PO tidak ditemukan']);
        exit;
    }
    $hdr = $hdrQ->fetch_assoc();
    $poNo = $hdr['po_no'];
    $escPo = $koneksi->real_escape_string($poNo);

    // 1. Lines PO
    $lines = [];
    $lQ = $koneksi->query("SELECT * FROM bc_po_dtl WHERE (id_hdr = '$idEsc' OR id = '$idEsc' OR (po_no = '$escPo' AND po_no <> '')) AND (cmp_id = '$escCmp' OR cmp_id = '') ORDER BY item_no ASC, seq ASC");
    if ($lQ) {
        while ($l = $lQ->fetch_assoc()) $lines[] = $l;
    }

    // 2. Realisasi Penerimaan Gudang (RI / Receiving Item & Dokumen BC Masuk)
    $riList = [];
    $riQ = $koneksi->query("SELECT d.*, h.ri_no, h.ship_dt, h.vendor, h.no_bc, h.tgl_bc, h.no_aju, h.bc as jenis_bc
                            FROM bc_ri_dtl d
                            JOIN bc_ri_hdr h ON (d.id = h.id OR d.ri_no = h.ri_no) AND (d.cmp_id = h.cmp_id OR d.cmp_id = '')
                            WHERE (d.po_no = '$escPo' OR h.po_no = '$escPo') AND (h.cmp_id = '$escCmp' OR h.cmp_id = '')
                            ORDER BY h.ship_dt ASC, h.ri_no ASC");
    if ($riQ) {
        while ($r = $riQ->fetch_assoc()) $riList[] = $r;
    }

    // 3. Realisasi Faktur Pembelian Supplier (PI / Purchase Invoice & Faktur Pajak)
    $piList = [];
    $piQ = $koneksi->query("SELECT d.*, h.pi_no, h.pi_dt, h.vendor, h.no_fp, h.kd_fp, h.total_order, h.terbayar, h.outstand
                            FROM bc_pi_dtl d
                            JOIN bc_pi_hdr h ON d.id_hdr = h.id AND (d.cmp_id = h.cmp_id OR d.cmp_id = '')
                            WHERE d.po_no = '$escPo' AND (h.cmp_id = '$escCmp' OR h.cmp_id = '')
                            ORDER BY h.pi_dt ASC, h.pi_no ASC");
    if ($piQ) {
        while ($p = $piQ->fetch_assoc()) $piList[] = $p;
    }

    echo json_encode([
        'status' => 'OK',
        'header' => $hdr,
        'lines'  => $lines,
        'ri'     => $riList,
        'pi'     => $piList
    ]);
    exit;
}

// 9. PRINT PURCHASE ORDER FORMAL
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    $idEsc = $koneksi->real_escape_string($id);
    $cmpFilter = !empty($sessionCmpId) ? "AND (a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "";

    $hdr = null;
    $items = [];
    if (!empty($id)) {
        $hQ = $koneksi->query("SELECT a.*, b.NAMA as vendor_name, b.ALAMAT as vendor_alamat, b.NPWP as vendor_npwp, b.nitku as vendor_nitku, b.KONTAK as vendor_contact
                               FROM bc_po_hdr a
                               LEFT JOIN bc_bs b ON a.vendor = b.KODE AND (b.cmp_id = a.cmp_id OR b.cmp_id = '')
                               WHERE a.id = '$idEsc' $cmpFilter LIMIT 1");
        if ($hQ) $hdr = $hQ->fetch_assoc();
        if ($hdr) {
            $dQ = $koneksi->query("SELECT * FROM bc_po_dtl WHERE (id_hdr = '$idEsc' OR id = '$idEsc' OR (po_no = '{$hdr['po_no']}' AND po_no <> '')) AND (cmp_id = '$escCmp' OR cmp_id = '') ORDER BY item_no ASC, seq ASC");
            if ($dQ) while ($d = $dQ->fetch_assoc()) $items[] = $d;
        }
    }

    // Profil Perusahaan
    $cmpQ = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    $cmp = $cmpQ ? $cmpQ->fetch_assoc() : [];
    $cmpName = $cmp['cmp_name'] ?? 'TPB ERP Enterprise';
    $cmpAddr = $cmp['alamat'] ?? '';
    $cmpNpwp = $cmp['npwp'] ?? '';
    $cmpNib  = $cmp['nib'] ?? '';
    $cmpLogo = trim($cmp['logo'] ?? '');
    if (!empty($cmpLogo) && !preg_match('#^(https?://|/)#i', $cmpLogo)) {
        $cmpLogo = '/' . $cmpLogo;
    }
    if (empty($cmpLogo)) {
        $cmpLogo = '/assets/logo.png';
    }

    // Otoritas Approval & Tanda Tangan Digital dari bc_po_user
    $approvers = [
        'PEMBUAT' => null,
        'LEVEL_2' => null,
        'FINAL' => null
    ];
    $apprQ = $koneksi->query("SELECT nama_lengkap, jabatan, level_approval, is_bod_delegate, ttd_digital 
                              FROM bc_po_user 
                              WHERE aktif = 'Y' AND doc_type IN ('PO', 'ALL') AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)
                              ORDER BY urutan ASC, id ASC");
    if ($apprQ) {
        while ($ap = $apprQ->fetch_assoc()) {
            $lvl = $ap['level_approval'];
            if ($lvl === 'PEMBUAT' && empty($approvers['PEMBUAT'])) $approvers['PEMBUAT'] = $ap;
            elseif ($lvl === 'LEVEL_2' && empty($approvers['LEVEL_2'])) $approvers['LEVEL_2'] = $ap;
            elseif ($lvl === 'FINAL' && empty($approvers['FINAL'])) $approvers['FINAL'] = $ap;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Purchase Order - <?= htmlspecialchars($hdr['po_no'] ?? 'Draft') ?></title>
        <style>
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 11px; color: #111; margin: 20px; line-height: 1.3; }
            .po-box { max-width: 800px; margin: 0 auto; border: 1px solid #333; padding: 16px; }
            .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .hdr-table td { vertical-align: top; }
            .title { font-size: 16px; font-weight: bold; text-align: right; text-transform: uppercase; color: #1890ff; }
            .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
            .info-grid td { padding: 3px 6px; border: 1px solid #ccc; font-size: 10.5px; }
            .info-title { background: #f0f0f0; font-weight: bold; }
            table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            table.data th, table.data td { border: 1px solid #444; padding: 4px 6px; font-size: 10.5px; }
            table.data th { background: #f4f4f4; text-align: center; }
            .num { text-align: right; }
            .sum-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            .sum-table td { padding: 2px 6px; font-size: 10.5px; }
            .sig-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 24px; text-align: center; font-size: 10.5px; }
            .sig-box { height: 60px; }
            @media print {
                body { margin: 0; }
                .po-box { border: none; padding: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom:12px; text-align:right;">
            <button onclick="window.print()" style="padding:6px 16px; font-size:12px; font-weight:bold; background:#1890ff; color:#fff; border:none; border-radius:3px; cursor:pointer;">Cetak Sekarang</button>
        </div>
        <div class="po-box">
            <table class="hdr-table">
                <tr>
                    <td style="width:62%; vertical-align:middle;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if (!empty($cmpLogo)): ?>
                            <img src="<?= htmlspecialchars($cmpLogo) ?>" alt="Logo Perusahaan" style="max-height:55px; max-width:140px; object-fit:contain;">
                            <?php endif; ?>
                            <div>
                                <strong style="font-size:14px; text-transform:uppercase; color:#111; letter-spacing:0.3px;"><?= htmlspecialchars($cmpName) ?></strong><br>
                                <span style="font-size:9.5px; color:#444; line-height:1.3; display:inline-block; margin-top:2px;"><?= nl2br(htmlspecialchars($cmpAddr)) ?></span><br>
                                <span style="font-size:9.5px; color:#555;">NPWP: <strong><?= htmlspecialchars($cmpNpwp ?: '-') ?></strong><?= !empty($cmpNib) ? ' | NIB: ' . htmlspecialchars($cmpNib) : '' ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="title" style="width:38%; vertical-align:middle; text-align:right;">
                        <div style="font-size:16px; font-weight:800; color:#1890ff; letter-spacing:0.5px; text-transform:uppercase;">PURCHASE ORDER</div>
                        <div style="font-size:11px; font-weight:700; color:#222; margin-top:2px;">No: <?= htmlspecialchars($hdr['po_no'] ?? '-') ?></div>
                        <div style="font-size:10px; color:#666;">Surat Pesanan Pembelian Resmi</div>
                    </td>
                </tr>
            </table>

            <table class="info-grid">
                <tr>
                    <td class="info-title" style="width:50%;">SUPPLIER / PEMASOK:</td>
                    <td class="info-title" style="width:50%;">INFORMASI PENGIRIMAN &amp; SYARAT:</td>
                </tr>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($hdr['vendor_name'] ?? $hdr['vendor'] ?? '-') ?></strong><br>
                        <?= nl2br(htmlspecialchars($hdr['vendor_alamat'] ?? '')) ?><br>
                        NPWP: <?= htmlspecialchars($hdr['vendor_npwp'] ?? '-') ?>
                    </td>
                    <td>
                        <strong>Tanggal PO:</strong> <?= htmlspecialchars($hdr['po_dt'] ?? date('Y-m-d')) ?><br>
                        <strong>Tgl Dibutuhkan:</strong> <?= htmlspecialchars($hdr['exp_dt'] ?? '-') ?><br>
                        <strong>Syarat Bayar (Term):</strong> <?= htmlspecialchars($hdr['term'] ?? 'Net 30') ?><br>
                        <strong>Valuta:</strong> <?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> (Kurs: <?= number_format((float)($hdr['rate'] ?? 1), 4) ?>)
                    </td>
                </tr>
            </table>

            <table class="data">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th style="width:130px;">Kode Barang</th>
                        <th>Nama &amp; Uraian Barang</th>
                        <th style="width:55px;">Qty</th>
                        <th style="width:45px;">Sat</th>
                        <th style="width:90px;">Harga Satuan</th>
                        <th style="width:45px;">Disc(%)</th>
                        <th style="width:105px;">Total Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) === 0): ?>
                    <tr><td colspan="8" style="text-align:center; padding:12px;">Tidak ada item barang.</td></tr>
                    <?php else: ?>
                    <?php foreach ($items as $idx => $it): 
                        $itNama = !empty($it['nama_barang']) ? $it['nama_barang'] : ($it['uraian'] ?? '');
                        $itSat  = !empty($it['satuan']) ? $it['satuan'] : ($it['sat'] ?? 'PCE');
                        $itHrg  = ((float)($it['harga'] ?? 0) > 0) ? (float)$it['harga'] : (float)($it['price'] ?? 0);
                        $itDisc = ((float)($it['diskon'] ?? 0) > 0) ? (float)$it['diskon'] : (float)($it['disc'] ?? 0);
                        $itTot  = ((float)($it['total'] ?? 0) > 0) ? (float)$it['total'] : (float)($it['amount'] ?? 0);
                    ?>
                    <tr>
                        <td style="text-align:center;"><?= $idx + 1 ?></td>
                        <td><strong><?= htmlspecialchars($it['kode_barang']) ?></strong></td>
                        <td><?= htmlspecialchars($itNama) ?></td>
                        <td class="num"><?= number_format((float)$it['qty'], 2) ?></td>
                        <td style="text-align:center;"><?= htmlspecialchars($itSat) ?></td>
                        <td class="num"><?= number_format($itHrg, 2) ?></td>
                        <td class="num"><?= number_format($itDisc, 1) ?>%</td>
                        <td class="num"><strong><?= number_format($itTot, 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <table class="sum-table">
                <tr>
                    <td style="width:55%; vertical-align:top;">
                        <strong>Catatan:</strong><br>
                        <?= nl2br(htmlspecialchars($hdr['note'] ?? 'Barang dikirim sesuai spesifikasi dan tanggal yang disepakati.')) ?>
                    </td>
                    <td style="width:45%;">
                        <table style="width:100%; border-collapse:collapse;">
                            <tr><td>Subtotal</td><td class="num"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)($hdr['subtotal'] ?? 0), 2) ?></td></tr>
                            <?php if ((float)($hdr['disc_amt'] ?? 0) > 0): ?>
                            <tr><td>Diskon Faktur (<?= number_format((float)($hdr['disc_persen'] ?? 0), 1) ?>%)</td><td class="num">- <?= number_format((float)$hdr['disc_amt'], 2) ?></td></tr>
                            <?php endif; ?>
                            <?php if ((float)($hdr['ppn'] ?? 0) > 0): ?>
                            <tr><td>PPN (11%)</td><td class="num"><?= number_format((float)$hdr['ppn'], 2) ?></td></tr>
                            <?php endif; ?>
                            <?php if ((float)($hdr['est_freight'] ?? 0) > 0): ?>
                            <tr><td>Ongkos Kirim (Freight)</td><td class="num"><?= number_format((float)$hdr['est_freight'], 2) ?></td></tr>
                            <?php endif; ?>
                            <tr style="font-weight:bold; font-size:12px; border-top:1px solid #444;">
                                <td>TOTAL PESANAN</td>
                                <td class="num"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)($hdr['total_order'] ?? 0), 2) ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div class="sig-grid" style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:8px; margin-top:24px; text-align:center; font-size:10px;">
                <div>
                    Dibuat Oleh:<br>
                    <div class="sig-box" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:65px;">
                        <?php if (!empty($approvers['PEMBUAT']['ttd_digital'])): ?>
                            <img src="<?= htmlspecialchars($approvers['PEMBUAT']['ttd_digital']) ?>" style="max-height:48px; max-width:110px; object-fit:contain;" alt="TTD">
                            <span style="font-size:7.5px; color:#52c41a;">✓ Digital Signed</span>
                        <?php else: ?>
                            <div style="height:48px;"></div>
                        <?php endif; ?>
                    </div>
                    ( <?= htmlspecialchars($approvers['PEMBUAT']['nama_lengkap'] ?? 'Purchasing Staff') ?> )<br>
                    <span style="font-size:9px; color:#666;"><?= htmlspecialchars($approvers['PEMBUAT']['jabatan'] ?? 'Staff Pengadaan') ?></span>
                </div>
                <div>
                    Disetujui Dept / Purchasing:<br>
                    <div class="sig-box" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:65px;">
                        <?php if (!empty($approvers['LEVEL_2']['ttd_digital'])): ?>
                            <img src="<?= htmlspecialchars($approvers['LEVEL_2']['ttd_digital']) ?>" style="max-height:48px; max-width:110px; object-fit:contain;" alt="TTD">
                            <span style="font-size:7.5px; color:#52c41a;">✓ Digital Signed</span>
                        <?php else: ?>
                            <div style="height:48px;"></div>
                        <?php endif; ?>
                    </div>
                    ( <?= htmlspecialchars($approvers['LEVEL_2']['nama_lengkap'] ?? 'Manager Dept') ?> )<br>
                    <span style="font-size:9px; color:#666;"><?= htmlspecialchars($approvers['LEVEL_2']['jabatan'] ?? 'Manager Departemen') ?></span>
                </div>
                <div>
                    Mengetahui / Sah BOD:<br>
                    <div class="sig-box" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:65px;">
                        <?php if (!empty($approvers['FINAL']['ttd_digital'])): ?>
                            <img src="<?= htmlspecialchars($approvers['FINAL']['ttd_digital']) ?>" style="max-height:48px; max-width:110px; object-fit:contain;" alt="TTD">
                            <span style="font-size:7.5px; color:#1890ff;">✓ Sah BOD Verified</span>
                        <?php else: ?>
                            <div style="height:48px;"></div>
                        <?php endif; ?>
                    </div>
                    ( <?= htmlspecialchars($approvers['FINAL']['nama_lengkap'] ?? 'Direktur / BOD') ?> )<br>
                    <span style="font-size:9px; color:#666;"><?= htmlspecialchars($approvers['FINAL']['jabatan'] ?? 'Direksi / Board of Directors') ?><?= (!empty($approvers['FINAL']['is_bod_delegate']) && $approvers['FINAL']['is_bod_delegate'] === 'Y') ? ' (Kuasa BOD)' : '' ?></span>
                </div>
                <div>
                    Konfirmasi Supplier:<br>
                    <div class="sig-box" style="height:65px;"></div>
                    ( _____________________ )<br>
                    <span style="font-size:9px; color:#666;">Tanda Tangan &amp; Cap</span>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Data awal untuk form Entri
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// Ambil daftar vendor supplier aktif
$vendors = [];
$vQ = $koneksi->query("SELECT KODE as kode, NAMA as nama, ALAMAT as alamat, NPWP as npwp, nitku, PAY_TERM as term, kurs 
                       FROM bc_bs 
                       WHERE TIPE IN ('S', 'BS') AND (AKTIF <> 'N' OR AKTIF IS NULL OR AKTIF = 'Y' OR AKTIF = '') " . (!empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '')" : "") . "
                       ORDER BY NAMA ASC");
if ($vQ) while ($v = $vQ->fetch_assoc()) $vendors[] = $v;

// Auto-Heal: Jika tabel bc_bs belum ada data vendor, otomatis sinkronkan dari bc_ceisa_entitas
if (empty($vendors)) {
    try {
        $syncQ = $koneksi->query("SELECT DISTINCT NAMA_ENTITAS, ALAMAT_ENTITAS, NOMOR_IDENTITAS, NIB_ENTITAS
                                  FROM bc_ceisa_entitas
                                  WHERE KODE_ENTITAS IN ('5', '7', '3') AND NAMA_ENTITAS IS NOT NULL AND TRIM(NAMA_ENTITAS) != ''
                                  ORDER BY NAMA_ENTITAS ASC");
        if ($syncQ && $syncQ->num_rows > 0) {
            $seqV = 1;
            while ($ent = $syncQ->fetch_assoc()) {
                $vNama = trim($ent['NAMA_ENTITAS']);
                $vAlamat = trim($ent['ALAMAT_ENTITAS'] ?? '');
                $vNpwp = trim($ent['NOMOR_IDENTITAS'] ?? '');
                $vNitku = trim($ent['NIB_ENTITAS'] ?? '');
                $vKode = 'VND-' . str_pad($seqV++, 4, '0', STR_PAD_LEFT);
                $escNm = $koneksi->real_escape_string($vNama);
                $escAl = $koneksi->real_escape_string($vAlamat);
                $escNp = $koneksi->real_escape_string($vNpwp);
                $escNt = $koneksi->real_escape_string($vNitku);
                
                $koneksi->query("INSERT IGNORE INTO bc_bs (cmp_id, KODE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, nitku, TIPE, AKTIF, PAY_TERM, kurs)
                                 VALUES ('$escCmp', '$vKode', '$escNm', LEFT('$escNm', 30), '$escAl', '$escNp', '$escNt', 'S', 'Y', 'Net 30', 'IDR')");
                
                $vendors[] = [
                    'kode' => $vKode,
                    'nama' => $vNama,
                    'alamat' => $vAlamat,
                    'npwp' => $vNpwp,
                    'nitku' => $vNitku,
                    'term' => 'Net 30',
                    'kurs' => 'IDR'
                ];
            }
        }
    } catch (\Throwable $eSync) {}
}

// Mata Uang
$currencies = ['IDR', 'USD', 'EUR', 'JPY', 'SGD', 'CNY', 'GBP'];

// Departemen dari bc_dept
$deptList = [];
$deptQ = $koneksi->query("SELECT kode, nama_dept FROM bc_dept WHERE aktif = 'Y' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY kode ASC");
if ($deptQ && $deptQ->num_rows > 0) {
    while ($dr = $deptQ->fetch_assoc()) {
        $deptList[] = $dr['kode'];
    }
}
if (empty($deptList)) {
    $deptList = ['PRODUKSI', 'MAINTENANCE', 'PPIC', 'GA', 'IT', 'QC'];
}

// Profil Perusahaan Aktif
if (empty($activeCmpName) || $activeCmpName === 'TPB ERP Enterprise') {
    if (!empty($sessionCmpId)) {
        $cmpQ = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
        if ($cmpQ && $cmpR = $cmpQ->fetch_assoc()) $activeCmpName = $cmpR['cmp_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order (PO) - <?= htmlspecialchars($activeCmpName) ?></title>
    <script>
        // Deteksi tema instan sebelum render untuk menghindari flicker putih
        (function() {
            var theme = localStorage.getItem('ceisa_theme');
            if (theme === 'dark' || (!theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <link rel="stylesheet" href="/assets/ant_searchable_select.css?v=20261101">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="/assets/ant_searchable_select.js?v=20261101"></script>
    <style>
        :root {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --text-main: #1f1f1f;
            --text-muted: #595959;
            --border-color: #d9d9d9;
            --border-light: #e8e8e8;
            --border-input: #d9d9d9;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-head-bg: #fafafa;
            --table-hover: #e6f7ff;
            --row-selected: #bae7ff;
            --row-selected-text: #002766;
        }

        html.dark-mode, body.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --text-main: #e6e6e6;
            --text-muted: #8c8c8c;
            --border-color: #434343;
            --border-light: #303030;
            --border-input: #434343;
            --primary: #177ddc;
            --primary-hover: #3c9ae8;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --table-head-bg: #1d1d1d;
            --table-hover: #262626;
            --row-selected: #113559;
            --row-selected-text: #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; background: var(--bg-base); color: var(--text-main); font-family: var(--app-font, 'Poppins', sans-serif); font-size: 10px; }
        input, select, textarea, button, table, .ant-container, .ant-card, .ant-toolbar, .fld-row input, .fld-row select, table.dw {
            font-family: var(--app-font, 'Poppins', sans-serif);
        }

        /* Hilangkan spinner up/down pada input angka */
        input[type="number"] { -moz-appearance: textfield; }
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        .ant-container { height: 100%; display: flex; flex-direction: column; padding: 6px; gap: 0; }
        .ant-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .ant-card-head { min-height: 34px; padding: 4px 10px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; background: var(--bg-card); }
        .ant-card-title { font-size: 12px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }
        .ant-badge { font-size: 9px; padding: 1px 6px; border-radius: 10px; font-weight: 600; line-height: 1.2; text-transform: uppercase; }
        .ant-badge-company { background: rgba(24, 144, 255, 0.12); color: var(--primary); border: 1px solid rgba(24, 144, 255, 0.3); }
        .ant-badge-lunas { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.3); }
        .ant-badge-belum { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.3); }
        .ant-badge-lock { background: rgba(255, 77, 79, 0.12); color: var(--danger); border: 1px solid rgba(255, 77, 79, 0.3); }

        /* Tabs */
        .ant-tabs-nav { display: flex; gap: 2px; padding: 0 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .ant-tab-btn { border: none; border-bottom: 2px solid transparent; background: transparent; color: var(--text-muted); padding: 5px 12px; cursor: pointer; font-size: 10.5px; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }

        /* Toolbar Buttons */
        .ant-toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; padding: 6px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); }
        .ant-btn { font-family: inherit; font-size: 10px; height: 22px; padding: 0 8px; border-radius: 2px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; line-height: 1.4; transition: all 0.15s; }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color:#fff; }
        .ant-btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .ant-btn-success:hover { background: #49ad16; border-color: #49ad16; color:#fff; }
        .ant-btn-warning { background: var(--warning); color: #fff; border-color: var(--warning); }
        .ant-btn-warning:hover { background: #d48806; border-color: #d48806; color:#fff; }
        .ant-btn-danger { color: var(--danger); border-color: #ffccc7; background: #fff; }
        body.dark-mode .ant-btn-danger, html.dark-mode .ant-btn-danger { background: transparent; border-color: #5c2c2c; }
        .ant-btn-danger:hover { color: #fff; background: var(--danger); border-color: var(--danger); }
        .ant-btn:disabled { opacity: 0.45; cursor: not-allowed; }

        /* Tab Content Pages */
        .ant-page { display: none; min-height: 0; flex-direction: column; flex: 1; overflow: hidden; }
        .ant-page.active { display: flex; }

        /* Splitpane layout */
        .split-col { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        .split-pane { min-height: 48px; min-width: 0; overflow: auto; display: flex; flex-direction: column; }
        .split-pane.fill { flex: 1 1 auto; overflow: hidden; }
        #paneHdr { flex: 0 0 148px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        #paneFtr { flex: 0 0 auto; min-height: 110px; background: var(--bg-card); border-top: 1px solid var(--border-color); overflow: hidden; }

        /* Resizable Splitbar (sesuai transfer.php & sales_invoice.php) */
        .split-divider, .splitbar {
            height: 8px;
            background: var(--bg-base);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            cursor: row-resize;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            user-select: none;
            transition: background 0.15s;
            flex-shrink: 0;
            z-index: 20;
        }
        .split-divider:hover, .split-divider.dragging, .splitbar:hover, .splitbar.dragging {
            background: var(--primary) !important;
        }
        .split-divider .split-handle, .splitbar .split-handle, .split-grip {
            width: 40px;
            height: 3px;
            border-top: 1px solid var(--text-muted);
            border-bottom: 1px solid var(--text-muted);
            opacity: 0.7;
            pointer-events: none;
        }
        .split-divider:hover .split-handle, .split-divider.dragging .split-handle,
        .splitbar:hover .split-handle, .splitbar.dragging .split-handle {
            border-color: #ffffff !important;
            opacity: 1;
        }
        .split-divider .split-btn-collapse, .splitbar .split-btn-collapse, .split-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 8px;
            line-height: 1;
            padding: 1px 5px;
            height: 14px;
            border-radius: 2px;
            cursor: pointer;
            margin: 0 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            z-index: 25;
        }
        .split-divider .split-btn-collapse:hover, .splitbar .split-btn-collapse:hover, .split-btn:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        /* Form Controls */
        .hdr-grid { display: grid; grid-template-columns: 1.15fr 1fr; gap: 2px 12px; padding: 6px 10px; }
        .fld-row { display: grid; grid-template-columns: 82px 1fr; gap: 4px; align-items: center; margin-bottom: 2px; }
        .fld-row label { text-align: right; color: var(--text-muted); font-size: 9.5px; font-weight: 500; }
        .fld-row input, .fld-row select, .fld-row textarea, .browse-filter input, .browse-filter select { width: 100%; border: 1px solid var(--border-input); background: var(--bg-input); color: var(--text-main); padding: 1px 6px; height: 21px; border-radius: 2px; outline: none; font-size: 9.5px; }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus, .browse-filter input:focus, .browse-filter select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15); }
        .inline-box { display: flex; gap: 4px; align-items: center; flex: 1; min-width: 0; width: 100%; }
        .pair-box { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 8px; }
        .checks-box { display: flex; flex-wrap: wrap; gap: 2px 10px; margin: 0 0 2px 86px; font-size: 9px; }
        .checks-box label { display: flex; gap: 4px; align-items: center; cursor: pointer; font-size: 9px; }

        /* Excel-like Data Table & Resizable Columns */
        .grid-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        table.dw { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 9.5px; table-layout: auto; border: 1px solid var(--border-color); }
        table.dw thead { position: sticky; top: 0; z-index: 10; }
        table.dw th { position: relative; background: var(--table-head-bg); border: 1px solid var(--border-color); padding: 4px 6px; text-align: left; font-weight: 600; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.3px; color: var(--text-muted); white-space: nowrap; user-select: none; }
        table.dw td { border: 1px solid var(--border-light); padding: 2px 6px; font-size: 9.5px; line-height: 1.4; vertical-align: middle; }
        table.dw input { width: 100%; border: 1px solid transparent; background: transparent; color: var(--text-main); padding: 1px 4px; height: 21px; border-radius: 2px; font-size: 9.5px; font-family: inherit; }
        table.dw input:hover { border-color: var(--border-input); }
        table.dw input:focus { border-color: var(--primary); background: var(--bg-input); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.12); outline: none; }
        table.dw input[type="number"] { text-align: right; }
        table.dw tbody tr:hover td { background: var(--table-hover); }
        table.dw tr.sel td { background: var(--row-selected) !important; color: var(--row-selected-text) !important; }

        /* Highlight No. PO */
        .highlight-inv-no {
            background: #fffbe6 !important;
            border: 1px solid #ffe58f !important;
            color: #d46b08 !important;
            font-weight: 700 !important;
            box-shadow: 0 0 0 1px rgba(250, 173, 20, 0.2);
        }
        body.dark-mode .highlight-inv-no, html.dark-mode .highlight-inv-no {
            background: #2b2111 !important;
            border-color: #594214 !important;
            color: #ffc069 !important;
        }
        th.th-highlight {
            background: #e6f7ff !important;
            color: var(--primary) !important;
            border-bottom: 2px solid var(--primary) !important;
            font-weight: 700 !important;
        }
        body.dark-mode th.th-highlight, html.dark-mode th.th-highlight {
            background: #111d2c !important;
            color: #40a9ff !important;
            border-bottom: 2px solid #177ddc !important;
        }
        td.td-highlight {
            background: rgba(24, 144, 255, 0.06) !important;
            color: var(--primary) !important;
            font-weight: 700 !important;
        }
        body.dark-mode td.td-highlight, html.dark-mode td.td-highlight {
            background: rgba(24, 144, 255, 0.12) !important;
            color: #40a9ff !important;
        }

        /* Visual Alert Kode Barang PO */
        .item-row.unregistered-row td.col-kode,
        .item-row.unregistered-row td.col-uraian {
            background: #fff2e8 !important;
        }
        body.dark-mode .item-row.unregistered-row td.col-kode,
        body.dark-mode .item-row.unregistered-row td.col-uraian {
            background: #2b1d11 !important;
        }
        .it-kode.is-unregistered {
            border: 1px dashed #ff7a45 !important;
            background: #fff7e6 !important;
            color: #d4380d !important;
            font-weight: 600 !important;
        }
        body.dark-mode .it-kode.is-unregistered {
            border-color: #d4380d !important;
            background: #341a0e !important;
            color: #ff9c6e !important;
        }
        .badge-unregistered {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #fff2e8;
            color: #d4380d;
            border: 1px solid #ffbb96;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9.5px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .badge-unregistered:hover {
            background: #ff4d4f;
            color: #ffffff;
            border-color: #ff4d4f;
        }
        .badge-registered {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #f6ffed;
            color: #389e0d;
            border: 1px solid #b7eb8f;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 9.5px;
            font-weight: 600;
            white-space: nowrap;
        }
        body.dark-mode .badge-unregistered {
            background: #431418;
            border-color: #a61d24;
            color: #ff7875;
        }
        body.dark-mode .badge-registered {
            background: #112a15;
            border-color: #274916;
            color: #73d13d;
        }

        /* Column Resizer Handle */
        .col-resizer {
            position: absolute;
            top: 0;
            right: 0;
            width: 7px;
            height: 100%;
            cursor: col-resize;
            user-select: none;
            z-index: 15;
            background: transparent;
            transition: background-color 0.15s;
        }
        .col-resizer:hover, .col-resizer.resizing {
            background-color: var(--primary) !important;
            box-shadow: 0 0 6px rgba(24, 144, 255, 0.6) !important;
        }
        body.grid-resizing { cursor: col-resize !important; user-select: none !important; }
        body.grid-resizing * { user-select: none !important; }

        /* Column Chooser Popover */
        .col-chooser-menu { position: absolute; bottom: 28px; left: 0; width: 290px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 4px 16px rgba(0,0,0,0.18); z-index: 100; padding: 8px; }
        .col-chooser-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 6px; margin-bottom: 6px; border-bottom: 1px solid var(--border-color); font-size: 10.5px; }
        .col-chooser-body { max-height: 260px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 8px; font-size: 10px; }
        .col-chooser-body label { display: flex; align-items: center; gap: 4px; cursor: pointer; user-select: none; }
        .ant-btn-link { background: none; border: none; color: var(--primary); cursor: pointer; font-size: 10px; padding: 0 2px; }
        .ant-btn-link:hover { text-decoration: underline; }

        /* Footer summary & Terbilang callout */
        .ftr-grid { display: grid; grid-template-columns: 1.25fr 360px; gap: 4px 12px; padding: 4px 10px; align-items: start; }
        .sum-box { display: grid; grid-template-columns: 1fr 1fr; gap: 0 8px; align-content: start; }
        .sum-box .fld-row { grid-template-columns: 78px 1fr; margin-bottom: 1px; }
        .sum-box .grand, .sum-box .out { grid-column: 1 / -1; }
        .sum-box .grand { font-weight: 700; color: var(--primary); }
        .sum-box .out { font-weight: 700; color: var(--danger); }
        
        .terbilang-callout {
            margin-top: 4px;
            padding: 4px 8px;
            background: var(--table-head-bg);
            border-left: 3px solid var(--primary);
            border-radius: 2px;
            font-size: 10px;
            line-height: 1.35;
            color: var(--text-main);
            display: flex;
            gap: 4px;
            align-items: flex-start;
        }
        .terbilang-callout .lbl-say { font-weight: 700; color: var(--primary); white-space: nowrap; }
        .terbilang-callout .txt-say { font-style: italic; word-break: break-word; }

        /* Status Line */
        .statusline { padding: 4px 10px; border-top: 1px solid var(--border-color); color: var(--text-muted); min-height: 22px; background: var(--table-head-bg); display: flex; align-items: center; justify-content: space-between; font-size: 10px; }

        /* Browse Data Filter */
        .browse-filter { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; padding: 4px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); white-space: nowrap; overflow-x: auto; }
        .browse-filter label { color: var(--text-muted); font-size: 10px; flex-shrink: 0; }
        .pager { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 10px; background: var(--bg-card); }
        .detail-strip { display: none; overflow: auto; border-top: 1px solid var(--border-color); background: var(--bg-card); }
        .detail-strip.show { display: block; flex: 0 0 180px; }

        /* Trace Boxes */
        .trace-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; background: var(--table-head-bg); border-bottom: 1px solid var(--border-color); }
        .trace-cap { background: var(--table-head-bg); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 4px 10px; font-weight: 600; font-size: 10px; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; }
        .trace-box { flex: 1; min-height: 0; overflow: auto; }

        /* Modals */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.45); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal.active { display: flex; }
        .modal-card { background: var(--bg-card); border-radius: 4px; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12); width: min(920px, 100%); max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--border-color); }
        .modal-card.wide { width: min(1080px, 100%); }
        .modal-h { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--border-color); font-weight: 600; font-size: 11px; background: var(--table-head-bg); }
        .modal-b { padding: 10px 12px; overflow: auto; flex: 1; min-height: 0; }
        .modal-f { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 12px; border-top: 1px solid var(--border-color); background: var(--table-head-bg); }
        .klas-row { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
        .klas-btn.active { color: #fff; background: var(--primary); border-color: var(--primary); font-weight: 600; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }

        /* Modal Master Barang - Consistent Height & Comfortable Spacing */
        #modalMaterial .modal-card { height: 560px; max-height: 90vh; }
        #modalMaterial .grid-wrap { height: 380px; min-height: 380px; }
        #modalMaterial table.dw td { padding: 7px 10px; font-size: 11px; }

        /* Context Menu (Klik Kanan PB Style) */
        .context-menu { position: fixed; z-index: 99999; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18); min-width: 235px; padding: 4px 0; user-select: none; font-size: 11px; }
        .ctx-item { display: flex; align-items: center; gap: 8px; padding: 5px 12px; cursor: pointer; color: var(--text-main); transition: background 0.15s ease; }
        .ctx-item:hover { background: var(--table-hover); color: var(--primary); }
        .ctx-item i { width: 14px; text-align: center; font-size: 11px; }
        .ctx-item span { flex: 1; }
        .ctx-item kbd { font-size: 9px; padding: 1px 4px; background: var(--table-head-bg); border: 1px solid var(--border-color); border-radius: 3px; color: var(--text-muted); }
        .ctx-sep { height: 1px; background: var(--border-color); margin: 4px 0; }

        /* Dropdown Menu */
        .ant-dropdown-menu { position: absolute; top: 100%; left: 0; margin-top: 4px; z-index: 1000; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 220px; padding: 4px 0; }
        .dropdown-item { display: flex; align-items: center; gap: 8px; padding: 6px 12px; cursor: pointer; font-size: 11px; color: var(--text-main); }
        .dropdown-item:hover { background: var(--table-hover); color: var(--primary); }
        .dropdown-item i { width: 14px; text-align: center; }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Header Bar -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-file-signature" style="color:var(--primary);"></i>
                <span>Purchase Order (Pesanan Pembelian)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-belum" id="statusBadgeHeader">Draft</span>
                <span id="statusActionButtons" style="display:inline-flex; gap:4px; margin-left:6px;"></span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="resetForm()"><i class="fa-solid fa-plus"></i> Baru</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="savePo()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                <button type="button" class="ant-btn" id="btnCopyPo" onclick="copyCurrentPo()" title="Duplikat seluruh rincian barang dan data supplier ke PO baru"><i class="fa-solid fa-copy"></i> Duplikat PO</button>
                <button type="button" class="ant-btn ant-btn-danger" onclick="deleteCurrent()"><i class="fa-solid fa-trash"></i> Hapus</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="printPo()"><i class="fa-solid fa-print"></i> Cetak PO</button>
                <button type="button" class="ant-btn ant-btn-warning" id="btnRestoreOriginal" style="display:none;" onclick="restoreOriginalData()" title="Kembalikan semua field dan rincian ke data awal sebelum diedit"><i class="fa-solid fa-rotate-left"></i> Reset ke Data Awal</button>
                <div style="position:relative; display:inline-block;">
                    <button type="button" class="ant-btn" id="btnExcelMenu" onclick="toggleExcelMenu(event)" title="Menu Import &amp; Export Excel"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Excel <i class="fa-solid fa-caret-down"></i></button>
                    <div id="excelMenuDropdown" class="ant-dropdown-menu" style="display:none;" onclick="event.stopPropagation()">
                        <div class="dropdown-item" onclick="openModalImportExcel(); hideExcelMenu();"><i class="fa-solid fa-file-import" style="color:#1890ff;"></i> Import dari Excel...</div>
                        <div class="dropdown-item" onclick="exportGridToExcel(); hideExcelMenu();"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Rincian ke Excel (.xlsx)</div>
                        <div class="dropdown-item" onclick="downloadPoImportTemplate(); hideExcelMenu();"><i class="fa-solid fa-download" style="color:#faad14;"></i> Download Template Excel Import</div>
                    </div>
                </div>
                <button type="button" class="ant-btn" onclick="openLookupPr()" title="Tarik dari Permintaan Pembelian (PR)"><i class="fa-solid fa-clipboard-list"></i> Tarik PR</button>
                <button type="button" class="ant-btn" id="btnAuditLog" onclick="showDocAuditLog(document.getElementById('poNo') ? document.getElementById('poNo').value : '', 'PO')" title="Lihat Catatan Riwayat Perubahan &amp; Penghapusan Data (Audit Trail)"><i class="fa-solid fa-clock-rotate-left" style="color:#1890ff;"></i> Log Perubahan</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Navigation Tabs (3 Tab Utama) -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="entri"><i class="fa-solid fa-pen-to-square"></i> Entri (Purchase Order)</button>
            <button type="button" class="ant-tab-btn" data-tab="browse"><i class="fa-solid fa-table-list"></i> Browse Data</button>
            <button type="button" class="ant-tab-btn" data-tab="trace"><i class="fa-solid fa-route"></i> Trace Realisasi PO (RI &amp; PI)</button>
        </div>

        <!-- Tab 1: Entri (Purchase Order) -->
        <section class="ant-page active" id="pageEntri">
            <div class="split-col" id="splitEntri">
                <!-- Pane Header -->
                <div class="split-pane" id="paneHdr">
                    <div class="hdr-grid">
                        <!-- Sisi Kiri: Supplier & Syarat Pengiriman -->
                        <div>
                            <div class="fld-row">
                                <label>Supplier/Vendor:</label>
                                <div class="inline-box">
                                    <select id="poVendor" onchange="onVendorChange()" style="flex:1;">
                                        <option value="">-- Pilih Supplier / Pemasok --</option>
                                        <?php foreach ($vendors as $v): ?>
                                        <option value="<?= htmlspecialchars($v['kode']) ?>" data-nama="<?= htmlspecialchars($v['nama']) ?>" data-alamat="<?= htmlspecialchars($v['alamat']) ?>" data-npwp="<?= htmlspecialchars($v['npwp']) ?>" data-term="<?= htmlspecialchars($v['term'] ?? 'Net 30') ?>" data-kurs="<?= htmlspecialchars($v['kurs'] ?? 'IDR') ?>"><?= htmlspecialchars($v['kode']) ?> - <?= htmlspecialchars($v['nama']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="ant-btn" onclick="openLookupPr()" title="Tarik dari Purchase Requisition (PR)"><i class="fa-solid fa-clipboard-list"></i> PR</button>
                                </div>
                            </div>
                            <div class="checks-box">
                                <label><input type="checkbox" id="poTaxable" checked onchange="calcTotals()"> Kena Pajak</label>
                                <label><input type="checkbox" id="poInclusive" onchange="calcTotals()"> Termasuk Pajak (Inclusive)</label>
                                <label><input type="checkbox" id="poSubcon" onchange="calcTotals()"> Jasa Subkon</label>
                                <label><input type="checkbox" id="poNcv"> NCV</label>
                            </div>
                            <div class="fld-row">
                                <label>Ship To:</label>
                                <input type="text" id="poShipTo" placeholder="Alamat Pengiriman / Gudang Penerima">
                            </div>
                            <div class="fld-row">
                                <label>Alamat Supplier:</label>
                                <input type="text" id="poAlamat" readonly placeholder="Alamat Supplier Pemasok">
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>No. PR:</label><input type="text" id="poPrNo" placeholder="No Requisition Acuan"></div>
                                <div class="fld-row"><label>No. WO / Job:</label><input type="text" id="poWoNo" placeholder="No Work Order Acuan"></div>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Kurs, No PO, Tanggal, Terms -->
                        <div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Kurs:</label>
                                    <select id="poKurs" onchange="toggleRate()">
                                        <?php foreach ($currencies as $c): ?>
                                        <option value="<?= $c ?>"><?= $c ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fld-row"><label>Kurs Pajak:</label><input type="number" id="poRate" value="1" step="0.0001" oninput="calcTotals()"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label style="font-weight:700; color:var(--primary);">No. PO:</label>
                                    <div class="inline-box">
                                        <input type="text" id="poNo" class="highlight-inv-no" placeholder="Otomatis jika kosong">
                                        <button type="button" class="ant-btn" onclick="generateAutoPoNo()" title="Generate Nomor Urut"><i class="fa-solid fa-rotate"></i></button>
                                    </div>
                                </div>
                                <div class="fld-row"><label>Tgl Dibutuhkan:</label><input type="date" id="poExpDt" value="<?= $today ?>"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl PO:</label><input type="date" id="poDt" value="<?= $today ?>" onchange="onDateOrTermChange()"></div>
                                <div class="fld-row"><label>Terms:</label>
                                    <input type="text" id="poTerm" value="Net 30" list="termList" onchange="onDateOrTermChange()">
                                    <datalist id="termList">
                                        <option value="Cash"></option>
                                        <option value="COD"></option>
                                        <option value="Net 14"></option>
                                        <option value="Net 30"></option>
                                        <option value="Net 60"></option>
                                    </datalist>
                                </div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Jatuh Tempo:</label><input type="date" id="poJatuhTempo"></div>
                                <div class="fld-row"><label>FOB / Kirim:</label>
                                    <select id="poFob">
                                        <option value="1">1 - Franco Gudang Pembeli</option>
                                        <option value="2">2 - Locco Gudang Penjual</option>
                                        <option value="3">3 - CIF Port</option>
                                        <option value="4">4 - FOB Port</option>
                                    </select>
                                </div>
                            </div>
                            <input type="hidden" id="poId">
                            <input type="hidden" id="poStatusValue" value="0">
                        </div>
                    </div>
                </div>

                <!-- Splitbar Header/Rincian -->
                <div class="split-divider" id="splitHdr" data-pane="paneHdr" data-edge="prev" title="Tarik untuk mengubah ukuran, dobel-klik untuk toggle maximize">
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); togglePoPane('hdr')" title="Toggle Maximize Header">&#x25BC; Header</button>
                    <div class="split-handle"></div>
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); togglePoPane('grid')" title="Toggle Maximize Grid">&#x25B2; Grid</button>
                </div>

                <!-- Pane Grid Rincian Barang -->
                <div class="split-pane fill" id="paneGrid">
                    <div class="grid-wrap">
                        <table class="dw" id="tableItems">
                            <thead>
                                <tr>
                                    <th style="width:30px; text-align:center;" class="col-seq">#</th>
                                    <th style="width:140px;" class="col-kode">Kode Barang</th>
                                    <th style="width:260px;" class="col-uraian">Nama &amp; Uraian Barang</th>
                                    <th style="width:80px;" class="col-color">Color</th>
                                    <th style="width:80px;" class="col-style">Style/Size</th>
                                    <th style="width:85px; text-align:right;" class="col-qty">Qty PO</th>
                                    <th style="width:65px;" class="col-sat">Satuan</th>
                                    <th style="width:105px; text-align:right;" class="col-price">Harga Satuan</th>
                                    <th style="width:65px; text-align:right;" class="col-disc">Disc(%)</th>
                                    <th style="width:115px; text-align:right;" class="col-amount">Total Nilai</th>
                                    <th style="width:100px;" class="col-prno">No. PR</th>
                                    <th style="width:90px;" class="col-wono">No. WO</th>
                                    <th style="width:95px;" class="col-expdt">Tgl Butuh</th>
                                    <th style="width:140px;" class="col-note">Catatan</th>
                                    <th style="width:40px; text-align:center;" class="col-action">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="itemsContainer">
                                <!-- Baris item dibuat dinamis -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Splitbar Rincian/Footer -->
                <div class="split-divider" id="splitFtr" data-pane="paneFtr" data-edge="next" title="Tarik untuk mengubah ukuran, dobel-klik untuk toggle maximize">
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); togglePoPane('grid')" title="Toggle Maximize Grid">&#x25BC; Grid</button>
                    <div class="split-handle"></div>
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); togglePoPane('ftr')" title="Toggle Maximize Footer">&#x25B2; Footer</button>
                </div>

                <!-- Pane Footer -->
                <div class="split-pane" id="paneFtr">
                    <div class="ftr-grid">
                        <!-- Sisi Kiri: Terbilang & Catatan -->
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px;">
                                <div class="inline-box">
                                    <button type="button" class="ant-btn ant-btn-primary" onclick="tambahBaris()"><i class="fa-solid fa-plus"></i> Tambah Baris (Insert)</button>
                                    <div style="position:relative; display:inline-block;">
                                        <button type="button" class="ant-btn" id="btnColChooser" onclick="toggleColChooser(event)"><i class="fa-solid fa-table-columns"></i> Atur Kolom</button>
                                        <div id="colChooserMenu" class="col-chooser-menu" style="display:none;" onclick="event.stopPropagation()">
                                            <div class="col-chooser-header">
                                                <strong>Pilih Kolom PO</strong>
                                                <div style="display:flex; gap:6px;">
                                                    <button type="button" class="ant-btn-link" onclick="setAllColumns(true)">Semua</button>
                                                    <button type="button" class="ant-btn-link" onclick="resetDefaultColumns()">Default</button>
                                                </div>
                                            </div>
                                            <div class="col-chooser-body" id="colChooserList"></div>
                                        </div>
                                    </div>
                                    <div class="inline-box" style="margin-left:8px;" title="Atur Presisi Angka Desimal (Harga &amp; Nilai)">
                                        <label style="font-size:10.5px; font-weight:600;"><i class="fa-solid fa-sliders"></i> Desimal:</label>
                                        <select id="numDecimals" onchange="changeDecimalPrecision(this.value)" style="width:62px; font-weight:600; font-size:11px; padding:2px 4px; height:24px;">
                                            <option value="0">0 (0)</option>
                                            <option value="2" selected>2 (0.00)</option>
                                            <option value="3">3 (0.000)</option>
                                            <option value="4">4 (0.0000)</option>
                                        </select>
                                    </div>
                                </div>
                                <span class="muted" id="itemCountInfo">0 baris rincian</span>
                            </div>
                            <div class="fld-row" style="grid-template-columns:55px 1fr; margin-top:2px;">
                                <label>Catatan:</label>
                                <input type="text" id="poNote" placeholder="Catatan instruksi pesanan pembelian...">
                            </div>
                            <div class="terbilang-callout" id="terbilangBox">
                                <span class="lbl-say"><i class="fa-solid fa-volume-high"></i> Terbilang:</span>
                                <span class="txt-say" id="poTerbilang">Nol Rupiah</span>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Summary Total Order -->
                        <div class="sum-box">
                            <div class="fld-row"><label>Subtotal:</label><input type="text" id="poSubtotal" readonly class="num" value="0.00"></div>
                            <div class="fld-row"><label>Disc (%):</label><input type="number" id="poDiscPersen" value="0" step="0.01" class="num" oninput="onDiscPercentChange()"></div>
                            <div class="fld-row"><label>Disc Nilai:</label><input type="text" id="poDiscAmt" value="0.00" class="num" onfocus="onNumFocus(this)" onblur="onDiscAmtBlur(this)"></div>
                            <div class="fld-row"><label>DPP:</label><input type="text" id="poDpp" readonly class="num" value="0.00"></div>
                            <div class="fld-row"><label>PPN (11%):</label><input type="text" id="poPpn" readonly class="num" value="0.00"></div>
                            <div class="fld-row"><label>PPh:</label><input type="text" id="poPph" value="0.00" class="num" onfocus="onNumFocus(this)" onblur="onPphBlur(this)"></div>
                            <div class="fld-row"><label>Freight:</label><input type="text" id="poFreight" value="0.00" class="num" onfocus="onNumFocus(this)" onblur="onFreightBlur(this)"></div>
                            <div class="fld-row grand"><label style="font-size:11px;">TOTAL PO:</label><input type="text" id="poTotalOrder" readonly class="num" style="font-size:12px; font-weight:700; color:var(--primary);" value="0.00"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Line -->
            <div class="statusline">
                <span id="poStatus">Siap entri Purchase Order baru.</span>
                <span id="poModeBadge" class="ant-badge ant-badge-company">Mode: Baru</span>
            </div>
        </section>

        <!-- Tab 2: Browse Data (1 Baris Ringkas) -->
        <section class="ant-page" id="pageBrowse">
            <div class="browse-filter">
                <label>Periode:</label>
                <input type="date" id="filterTglAwal" value="<?= $monthStart ?>" style="width:110px;">
                <label>s/d</label>
                <input type="date" id="filterTglAkhir" value="<?= $today ?>" style="width:110px;">
                <label>Status:</label>
                <select id="filterStatus" style="width:95px;">
                    <option value="">- SEMUA -</option>
                    <option value="0">DRAFT</option>
                    <option value="1">APPROVED</option>
                    <option value="2">CLOSED</option>
                    <option value="3">BATAL</option>
                </select>
                <label>Cari:</label>
                <select id="filterCol" style="width:105px;">
                    <option value="">Semua kolom</option>
                    <option value="po_no">No PO</option>
                    <option value="vendor">Supplier/Vendor</option>
                    <option value="note">Catatan</option>
                </select>
                <input type="text" id="filterSearch" placeholder="Kata kunci..." style="width:140px;" onkeydown="if(event.key==='Enter') loadPoList(1)">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadPoList(1)"><i class="fa-solid fa-magnifying-glass"></i> Retrieve</button>
                <label class="inline-box" style="margin-left:auto; cursor:pointer; flex-shrink:0;">
                    <input type="checkbox" id="chkDetail" onchange="toggleBrowseDetail()"> <strong>PREVIEW RINCIAN</strong>
                </label>
            </div>
            <div class="split-col">
                <div class="split-pane fill">
                    <div class="grid-wrap">
                        <table class="dw" id="poTable">
                            <thead>
                                <tr>
                                    <th data-col="seq" style="width:34px; text-align:center;">#</th>
                                    <th data-col="po_no" class="th-highlight" style="width:140px;">No. PO</th>
                                    <th data-col="po_dt" style="width:90px;">Tgl PO</th>
                                    <th data-col="vendor" style="width:190px;">Supplier / Vendor</th>
                                    <th data-col="kurs" style="width:55px;">Kurs</th>
                                    <th data-col="total_order" style="width:110px; text-align:right;">Total Nilai</th>
                                    <th data-col="exp_dt" style="width:95px;">Tgl Butuh</th>
                                    <th data-col="term" style="width:80px;">Terms</th>
                                    <th data-col="ri_stat" style="width:130px; text-align:center;">Realisasi RI &amp; Sisa</th>
                                    <th data-col="pi_stat" style="width:75px; text-align:center;">Faktur PI</th>
                                    <th data-col="status" style="width:80px;">Status</th>
                                    <th data-col="action" class="th-action" style="width:185px; text-align:center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="poTableBody">
                                <tr><td colspan="12" class="muted" style="padding:16px; text-align:center;">Memuat data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="detail-strip" id="browseDetail"></div>
            </div>
            <div class="pager">
                <span id="pageInfo">Menampilkan 0 data</span>
                <span id="pageBtns"></span>
            </div>
        </section>

        <!-- Tab 3: Trace Realisasi PO (Tracking Pengadaan: RI & PI) -->
        <section class="ant-page" id="pageTrace">
            <div class="trace-toolbar">
                <span class="muted"><i class="fa-solid fa-circle-info" style="color:var(--primary);"></i> Klik salah satu baris <strong>Item PO</strong> untuk melihat riwayat Penerimaan Barang (RI) dan Faktur Pembelian (PI) terkait.</span>
                <button type="button" class="ant-btn ant-btn-primary" onclick="printTraceReport()"><i class="fa-solid fa-print"></i> Cetak Laporan Realisasi</button>
            </div>
            <div class="split-col">
                <div class="split-pane fill" id="paneTrace1">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-box"></i> 1. Rincian Barang Purchase Order yang Dipesan</span>
                        <span id="traceSelectedPoBadge" class="ant-badge ant-badge-company">Pilih item</span>
                    </div>
                    <div class="trace-box grid-wrap" id="traceLines"><div class="muted" style="padding:10px;">Buka Purchase Order di tab Entri, lalu buka tab ini untuk melacak realisasi.</div></div>
                </div>
                <div class="split-divider" data-pane="paneTrace1" data-edge="prev" title="Geser pembatas">
                    <div class="split-handle"></div>
                </div>
                <div class="split-pane fill" id="paneTrace2">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-truck-ramp-box"></i> 2. Realisasi Penerimaan Barang Gudang (RI / Dokumen BC Masuk)</span>
                        <span id="traceRiCount" class="muted">0 penerimaan</span>
                    </div>
                    <div class="trace-box grid-wrap" id="traceRi"><div class="muted" style="padding:10px;">Klik salah satu baris Item PO di atas untuk melihat riwayat penerimaan barang.</div></div>
                </div>
                <div class="split-divider" data-pane="paneTrace2" data-edge="prev" title="Geser pembatas">
                    <div class="split-handle"></div>
                </div>
                <div class="split-pane fill" id="paneTrace3">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-file-invoice-dollar"></i> 3. Realisasi Faktur Pembelian Supplier (PI / Purchase Invoice &amp; Faktur Pajak)</span>
                        <span id="tracePiCount" class="muted">0 faktur</span>
                    </div>
                    <div class="trace-box grid-wrap" id="tracePi"><div class="muted" style="padding:10px;">Klik salah satu baris Item PO di atas untuk melihat faktur pembelian supplier.</div></div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Modal: Pilih Material / Master Barang -->
<div class="modal" id="modalMaterial">
    <div class="modal-card wide">
        <div class="modal-h">
            <span id="materialTitle"><i class="fa-solid fa-boxes-stacked"></i> Pilih Master Barang : Raw Materials (RM)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeMaterialPicker()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="klas-row">
                <button type="button" class="ant-btn klas-btn active" data-klas="RM" onclick="setKlas('RM')">Raw Materials (RM)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="FG" onclick="setKlas('FG')">Finished Goods (FG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="WI" onclick="setKlas('WI')">Work In Progress (WIP)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="CG" onclick="setKlas('CG')">Capital Goods (CG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="W" onclick="setKlas('W')">Wastes / Scrap</button>
            </div>
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="materialSearch" style="flex:1;" placeholder="Ketik kode barang atau uraian..." oninput="queueMaterialSearch()" onkeydown="if(event.key==='Enter'){event.preventDefault(); searchMaterials();}">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchMaterials()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="height:380px; min-height:380px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:140px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width:60px;">Satuan</th>
                            <th style="width:105px; text-align:right;">Harga Beli Satuan</th>
                            <th style="width:80px; text-align:right;">Berat(Kg)</th>
                            <th style="width:110px;">Klasifikasi</th>
                        </tr>
                    </thead>
                    <tbody id="materialBody">
                        <tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Ketik pencarian atau klik Cari. Dobel-klik baris untuk memilih.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Gunakan tombol panah / dobel klik baris untuk memilih barang.</span>
            <div class="inline-box">
                <input type="text" id="materialChosen" readonly placeholder="Kode terpilih" style="width:200px;">
                <button type="button" class="ant-btn ant-btn-primary" onclick="confirmMaterial()"><i class="fa-solid fa-check"></i> PILIH BARANG</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: History Harga Pembelian Supplier (PB m_42) -->
<div class="modal" id="modalHistoryHarga">
    <div class="modal-card wide">
        <div class="modal-h">
            <span id="historyHargaTitle"><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary);"></i> History Harga Pembelian Barang</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalHistoryHarga()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--table-head-bg); padding:6px 10px; border:1px solid var(--border-color); border-radius:3px;">
                <div style="display:flex; gap:8px; align-items:center;">
                    <span>Barang: <strong id="lblHistBarang">-</strong></span>
                    <span class="muted" id="lblHistSatuan"></span>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <label style="display:flex; gap:4px; align-items:center; cursor:pointer;">
                        <input type="checkbox" id="chkHistFilterVendor" checked onchange="loadHistoryHarga()"> Hanya Supplier Aktif
                    </label>
                </div>
            </div>
            <div class="grid-wrap" style="height:320px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" id="tblHistoryHarga">
                    <thead>
                        <tr>
                            <th style="width:35px; text-align:center;">Tipe</th>
                            <th style="width:130px;">No Dokumen</th>
                            <th style="width:90px;">Tgl Dokumen</th>
                            <th>Supplier / Vendor</th>
                            <th style="width:70px; text-align:right;">Qty</th>
                            <th style="width:105px; text-align:right;">Harga Satuan</th>
                            <th style="width:55px; text-align:right;">Disc(%)</th>
                            <th style="width:75px; text-align:right;">Kurs Pajak</th>
                            <th style="width:65px; text-align:center;">Gunakan</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyHistoryHarga">
                        <tr><td colspan="9" class="muted" style="padding:16px; text-align:center;">Memuat riwayat harga...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Sumber data: Rekam jejak transaksi Purchase Order &amp; Purchase Invoice yang telah diterbitkan sebelumnya.</span>
            <button type="button" class="ant-btn" onclick="closeModalHistoryHarga()">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal: Import Excel Wizard -->
<div class="modal" id="modalImportExcel">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Import Rincian Barang Purchase Order dari Excel (.xlsx / .csv)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalImportExcel()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:8px;">
            <div style="display:flex; gap:12px; align-items:center; background:var(--table-head-bg); padding:8px 12px; border:1px solid var(--border-color); border-radius:3px;">
                <input type="file" id="fileExcelImport" accept=".xlsx, .xls, .csv" onchange="handleExcelFileSelected(event)" style="font-size:11px;">
                <div style="display:flex; gap:12px; margin-left:auto; align-items:center;">
                    <label style="cursor:pointer;"><input type="radio" name="importMode" value="append" checked> Tambahkan (Append)</label>
                    <label style="cursor:pointer;"><input type="radio" name="importMode" value="replace"> Ganti Semua (Replace)</label>
                </div>
            </div>
            <div class="grid-wrap" style="max-height:300px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" id="tblImportPreview">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th>Kode Barang</th>
                            <th>Nama &amp; Uraian Barang</th>
                            <th>Color</th>
                            <th>Style/Size</th>
                            <th style="text-align:right;">Qty</th>
                            <th>Satuan</th>
                            <th style="text-align:right;">Harga Satuan</th>
                            <th style="text-align:right;">Disc(%)</th>
                            <th style="text-align:right;">Total Nilai</th>
                            <th>No. PR</th>
                            <th>No. WO</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyImportPreview">
                        <tr><td colspan="12" class="muted" style="padding:24px; text-align:center;">Pilih file Excel (.xlsx / .csv) di atas untuk melihat preview baris barang.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Mendukung kolom: Kode Barang, Uraian, Qty, Satuan, Harga, Disc, No PR, No WO, Color, Style.</span>
            <div style="display:flex; gap:6px;">
                <button type="button" class="ant-btn" onclick="closeModalImportExcel()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnApplyImport" disabled onclick="applyImportedData()"><i class="fa-solid fa-check"></i> Terapkan ke Purchase Order</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Tarik Purchase Requisition (PR) -->
<div class="modal" id="modalLookupPr">
    <div class="modal-card wide" style="width:min(1180px, 98vw); height:580px; max-height:92vh;">
        <div class="modal-h">
            <span><i class="fa-solid fa-clipboard-list" style="color:var(--primary);"></i> Tarik Permintaan Pembelian (Purchase Requisition - PR) ke PO</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeLookupPr()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; overflow:hidden;">
            <div class="inline-box" style="margin-bottom:8px; gap:8px;">
                <input type="text" id="searchPrText" style="flex:1;" placeholder="Cari No PR, Pemohon, Kode Barang, atau Nama Barang..." onkeydown="if(event.key==='Enter') searchPr()">
                <select id="filterPrDept" style="width:125px;" onchange="searchPr()">
                    <option value="">Semua Dept</option>
                    <?php foreach ($deptList as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterPrStatus" style="width:120px;" onchange="searchPr()">
                    <option value="">Semua Status</option>
                    <option value="2">Approved</option>
                    <option value="1">Diajukan</option>
                    <option value="0">Draft</option>
                    <option value="3">In PO</option>
                </select>
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchPr()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="flex:1; border:1px solid var(--border-color); border-radius:3px; overflow:auto;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:36px; text-align:center;"><input type="checkbox" id="chkAllPr" onchange="toggleSelectAllPr(this)" title="Pilih Semua"></th>
                            <th style="width:125px;">No. PR</th>
                            <th style="width:80px;">Tgl Butuh</th>
                            <th style="width:120px;">Dept / Pemohon</th>
                            <th>Kode &amp; Uraian Barang</th>
                            <th style="width:75px; text-align:right;">Sisa Qty</th>
                            <th style="width:50px; text-align:center;">Satuan</th>
                            <th style="width:95px; text-align:right;">Est. Harga</th>
                            <th style="width:85px; text-align:center;">Status PR</th>
                            <th style="width:65px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="lookupPrBody">
                        <tr><td colspan="10" class="muted" style="padding:16px; text-align:center;">Ketik pencarian atau klik Cari untuk melihat daftar permintaan pembelian.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f" style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; gap:12px; align-items:center;">
                <span id="prSelectedCountText" style="font-size:11px; font-weight:600; color:var(--primary);">0 item dipilih</span>
                <span class="muted" style="font-size:10px;">(Anda dapat mencentang beberapa item dari nomor PR berbeda sekaligus untuk 1 PO)</span>
            </div>
            <div class="inline-box">
                <button type="button" class="ant-btn ant-btn-primary" id="btnPullSelectedPr" onclick="pullSelectedPrItems()"><i class="fa-solid fa-check-double"></i> Tarik Item Terpilih (0)</button>
                <button type="button" class="ant-btn" onclick="closeLookupPr()">Tutup</button>
            </div>
        </div>
<!-- Modal: Quick Register Master Barang (bc_material) -->
<div class="modal" id="modalQuickMaterial" style="display:none;">
    <div class="modal-card" style="max-width:540px;">
        <div class="modal-h" style="background:#fff7e6; color:#d46b08;">
            <span><i class="fa-solid fa-boxes-stacked"></i> Daftarkan Barang Baru ke Master Barang</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeQuickRegModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:8px;">
            <div style="background:rgba(250, 173, 20, 0.1); border:1px solid #ffd591; padding:8px 10px; border-radius:3px; font-size:10.5px; color:#ad4e00;">
                <i class="fa-solid fa-circle-info"></i> Item ini terdeteksi <strong>belum terdaftar</strong> di Master Barang. Lengkapi klasifikasi dan satuan di bawah untuk standarisasi pabean &amp; inventory.
            </div>
            <div class="fld-row">
                <label>Kode Barang:</label>
                <input type="text" id="qregKode" style="font-weight:700; text-transform:uppercase;" placeholder="Contoh: RM-001, BAUT-M5">
            </div>
            <div class="fld-row">
                <label>Nama / Uraian:</label>
                <input type="text" id="qregUraian" placeholder="Uraian spesifikasi barang...">
            </div>
            <div class="fld-row">
                <label>Satuan (UoM):</label>
                <div class="inline-box">
                    <input type="text" id="qregSatuan" value="PCE" style="width:90px; text-transform:uppercase;">
                    <select onchange="if(this.value) document.getElementById('qregSatuan').value = this.value;" style="flex:1;">
                        <option value="">-- Rekomendasi Satuan CEISA --</option>
                        <option value="PCE">PCE - Pieces / Buah</option>
                        <option value="KGM">KGM - Kilogram</option>
                        <option value="MTR">MTR - Meter</option>
                        <option value="LTR">LTR - Liter</option>
                        <option value="SET">SET - Set / Pasang</option>
                        <option value="ROL">ROL - Roll</option>
                        <option value="BX">BX - Box / Dus</option>
                        <option value="CAN">CAN - Kaleng</option>
                        <option value="DR">DR - Drum</option>
                    </select>
                </div>
            </div>
            <div class="fld-row">
                <label>Klasifikasi:</label>
                <select id="qregKlas">
                    <option value="Raw Materials">Raw Materials (Bahan Baku / Penolong)</option>
                    <option value="Consumables">Consumables (Barang Habis Pakai / Pelumas / ATK)</option>
                    <option value="Capital Goods">Capital Goods (Mesin, Peralatan, Sparepart)</option>
                    <option value="Work In Progress">Work In Progress (WIP - Barang Setengah Jadi)</option>
                    <option value="Finished Goods">Finished Goods (Barang Jadi)</option>
                    <option value="Wastes / Scrap">Wastes / Scrap (Limbah / Sisa Produksi)</option>
                </select>
            </div>
            <div class="fld-row">
                <label>Pos Tarif (HS):</label>
                <input type="text" id="qregPosTarif" placeholder="8 digit HS Code jika ada (opsional)">
            </div>
            <div class="fld-row">
                <label>Harga Acuan:</label>
                <input type="text" id="qregPrice" class="num" value="0" placeholder="Perkiraan harga satuan">
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Barang otomatis aktif dan dapat dipakai untuk seluruh dokumen PO &amp; Pabean.</span>
            <div class="inline-box">
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitQuickRegMaterial()"><i class="fa-solid fa-floppy-disk"></i> Simpan ke Master Barang</button>
                <button type="button" class="ant-btn" onclick="closeQuickRegModal()">Batal</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: PANDUAN LENGKAP PURCHASE ORDER (PO) -->
<div class="modal" id="modalModuleGuide">
    <div class="modal-card" style="max-width:840px; max-height:88vh;">
        <div class="modal-h" style="background:linear-gradient(135deg, rgba(24,144,255,0.08), rgba(24,144,255,0.02));">
            <span style="font-weight:700; color:var(--primary); font-size:13px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-book-open"></i> Panduan Lengkap: Purchase Order (PO) &amp; Tata Kelola Pengadaan
            </span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="padding:16px 20px; overflow-y:auto; line-height:1.55; font-size:11px;">
            <div style="background:rgba(24,144,255,0.06); border-left:3px solid var(--primary); padding:10px 14px; border-radius:4px; margin-bottom:16px;">
                <strong style="color:var(--primary); font-size:11.5px;">SOP Pengadaan Barang &amp; Penerbitan Purchase Order:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main);">Purchase Order (PO) merupakan kontrak pemesanan resmi kepada Supplier yang mengikat secara komersial dan hukum, serta menjadi acuan penerimaan barang (RI/GRN), penagihan (PI), dan dokumen pabean CEISA (BC 2.0 / BC 2.3 / BC 4.0 / BC 2.7).</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#1890ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-cart-shopping"></i> 1. Langkah Penerbitan PO
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Supplier (Vendor):</strong> Pilih pemasok dari Master Rekanan / Supplier (sistem otomatis mengisi TOP, mata uang, dan NPWP).</li>
                        <li><strong>Tarik Konsolidasi PR:</strong> Klik tombol <strong>Tarik PR</strong> untuk menggabungkan beberapa baris permintaan barang dari berbagai departemen ke dalam 1 PO.</li>
                        <li><strong>Term &amp; Biaya:</strong> Tentukan syarat pembayaran (Net 30, COD, dll), estimasi ongkos kirim, diskon, dan tarif PPN (11% / 12%).</li>
                    </ul>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#faad14; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-barcode"></i> 2. Standarisasi Master Barang &amp; Kode Material
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Validasi Otomatis:</strong> Setiap item divalidasi ke Master Barang &amp; Material Pabean.</li>
                        <li><strong>Indikator ⚠️ Belum Ada:</strong> Item yang belum terdaftar akan diberi penanda visual kuning.</li>
                        <li><strong>Registrasi Kilat:</strong> Klik badge untuk mendaftarkan barang baru secara instan tanpa meninggalkan halaman PO.</li>
                    </ul>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#52c41a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-stamp"></i> 3. Matriks Approval &amp; Kuasa BOD
                    </div>
                    <p style="margin:0 0 4px 0; color:var(--text-main);">Pengadaan barang memerlukan persetujuan berjenjang sesuai matriks otorisasi perusahaan:</p>
                    <div style="font-size:10px; color:var(--text-muted);">
                        • <strong>Pembuat:</strong> Staf Purchasing entri data.<br>
                        • <strong>Level 2:</strong> Manager Departemen Terkait.<br>
                        • <strong>Final:</strong> Direktur Utama atau Pejabat yang diberi <strong>Wewenang Kuasa BOD</strong>.<br>
                        • Tanda tangan digital otomatis tersemat di form cetak.
                    </div>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#722ed1; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-print"></i> 4. Dokumen Cetak &amp; Logo Perusahaan
                    </div>
                    <p style="margin:0 0 4px 0; color:var(--text-main);">Tombol <strong>Cetak PO</strong> menghasilkan dokumen cetak profesional:</p>
                    <div style="font-size:10px; color:var(--text-muted);">
                        • Logo resmi perusahaan pada header kiri.<br>
                        • Rincian alamat, NPWP, syarat pembayaran &amp; mata uang.<br>
                        • Matriks 4 kolom persetujuan bertanda tangan digital.<br>
                        • Siap dicetak langsung atau disimpan sebagai PDF.
                    </div>
                </div>
            </div>

            <div style="background:rgba(82,196,26,0.08); border:1px solid rgba(82,196,26,0.3); border-radius:6px; padding:10px 14px;">
                <strong style="color:#389e0d;"><i class="fa-solid fa-route"></i> Ketertelusuran (Traceability) Realisasi:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main); font-size:10.5px;">Buka tab <strong>Trace Realisasi PO</strong> untuk memantau status fisik penerimaan barang di gudang (Receive Item / RI) dan status tagihan faktur pembelian (Purchase Invoice / PI) secara real-time.</p>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()"><i class="fa-solid fa-check"></i> Mengerti &amp; Tutup</button>
        </div>
    </div>
</div>

<script src="/assets/sweetalert2.all.min.js"></script>
<script src="/assets/sweetalert-theme.js?v=20261025"></script>
<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let currentPage = 1;
let selectedBrowseId = '';
let vendorCache = [];
let materialCache = [];
let pickerRow = null;
let pickerSelected = -1;
let materialTimer = null;
let lastCalc = { subtotal: 0, discPersen: 0, discAmt: 0, dpp: 0, ppn: 0, pph: 0, freight: 0, grand: 0 };
let originalLoadedData = null;
let currentPrecision = parseInt(localStorage.getItem('ceisa_po_decimals') || '2', 10);
if (isNaN(currentPrecision) || currentPrecision < 0 || currentPrecision > 4) currentPrecision = 2;

const klasLabel = {
    RM: 'Raw Materials',
    FG: 'Finished Goods',
    WI: 'Work In Progress',
    CG: 'Capital Goods',
    W:  'Wastes / Scrap'
};

// Pengaturan Kolom Grid Sesuai PowerBuilder Desktop (d_po_dtl.srd)
const PO_COLUMNS = [
    { id: 'color', label: 'Color', default: true },
    { id: 'style', label: 'Style / Size', default: true },
    { id: 'disc', label: 'Disc (%)', default: true },
    { id: 'prno', label: 'No. PR', default: true },
    { id: 'wono', label: 'No. WO', default: false },
    { id: 'expdt', label: 'Tgl Butuh', default: true },
    { id: 'note', label: 'Catatan', default: true }
];
let visibleCols = {};

function initColumnChooser() {
    let saved = null;
    try {
        saved = JSON.parse(localStorage.getItem('ceisa_grid_cols_po'));
    } catch(e) {}
    visibleCols = {};
    PO_COLUMNS.forEach(c => {
        visibleCols[c.id] = (saved && typeof saved[c.id] !== 'undefined') ? saved[c.id] : c.default;
    });
    renderColumnChooser();
    applyColumnStyles();
}

function renderColumnChooser() {
    const list = document.getElementById('colChooserList');
    if (!list) return;
    list.innerHTML = PO_COLUMNS.map(c => `
        <label class="col-chooser-item">
            <input type="checkbox" data-col="${c.id}" ${visibleCols[c.id] ? 'checked' : ''} onchange="toggleCol('${c.id}', this.checked)">
            <span>${escapeHtml(c.label)}</span>
        </label>
    `).join('');
}

function toggleCol(colId, isVisible) {
    visibleCols[colId] = !!isVisible;
    saveColumnPrefs();
    applyColumnStyles();
}

function setAllColumns(val) {
    PO_COLUMNS.forEach(c => { visibleCols[c.id] = !!val; });
    saveColumnPrefs();
    renderColumnChooser();
    applyColumnStyles();
}

function resetDefaultColumns() {
    PO_COLUMNS.forEach(c => { visibleCols[c.id] = c.default; });
    saveColumnPrefs();
    renderColumnChooser();
    applyColumnStyles();
}

function saveColumnPrefs() {
    try {
        localStorage.setItem('ceisa_grid_cols_po', JSON.stringify(visibleCols));
    } catch(e) {}
}

function applyColumnStyles() {
    let styleTag = document.getElementById('poColStyles');
    if (!styleTag) {
        styleTag = document.createElement('style');
        styleTag.id = 'poColStyles';
        document.head.appendChild(styleTag);
    }
    const cssRules = [];
    PO_COLUMNS.forEach(c => {
        if (!visibleCols[c.id]) {
            cssRules.push(`.col-${c.id} { display: none !important; }`);
        }
    });
    styleTag.textContent = cssRules.join('\n');
}

function toggleColChooser(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('colChooserMenu');
    if (!menu) return;
    const isShown = menu.style.display !== 'none';
    menu.style.display = isShown ? 'none' : 'block';
}

document.addEventListener('click', (e) => {
    const menu = document.getElementById('colChooserMenu');
    if (menu && menu.style.display !== 'none' && !menu.contains(e.target) && e.target.id !== 'btnColChooser') {
        menu.style.display = 'none';
    }
});

// Resizable Columns Engine (sesuai editor.php & sales_invoice.php)
let _isGridResizing = false;

function getSavedColumnWidths(tableKey) {
    try {
        const raw = localStorage.getItem('ceisa_grid_widths_' + tableKey);
        return raw ? JSON.parse(raw) : {};
    } catch (e) {
        return {};
    }
}

function saveColumnWidth(tableKey, colKey, width) {
    try {
        const saved = getSavedColumnWidths(tableKey);
        saved[colKey] = width;
        localStorage.setItem('ceisa_grid_widths_' + tableKey, JSON.stringify(saved));
    } catch (e) {}
}

function removeSavedColumnWidth(tableKey, colKey) {
    try {
        const saved = getSavedColumnWidths(tableKey);
        delete saved[colKey];
        localStorage.setItem('ceisa_grid_widths_' + tableKey, JSON.stringify(saved));
    } catch (e) {}
}

function setupGridColumnResize(tableSelector, tableKey) {
    const table = typeof tableSelector === 'string' ? document.querySelector(tableSelector) : tableSelector;
    if (!table) return;

    const ths = table.querySelectorAll('thead tr th');
    if (!ths.length) return;

    const savedWidths = getSavedColumnWidths(tableKey);

    ths.forEach((th, idx) => {
        if (th.classList.contains('col-action') || th.classList.contains('th-action') || th.dataset.col === 'action') {
            return;
        }

        let colKey = th.dataset.col;
        if (!colKey) {
            const m = th.className.match(/col-([a-z0-9_]+)/i);
            colKey = m ? m[1] : ('col_' + idx);
            th.dataset.col = colKey;
        }

        if (savedWidths && savedWidths[colKey]) {
            const sw = savedWidths[colKey];
            th.style.width = sw;
            th.style.minWidth = sw;
            th.style.maxWidth = sw;
        }

        let resizer = th.querySelector('.col-resizer');
        if (!resizer) {
            resizer = document.createElement('div');
            resizer.className = 'col-resizer';
            resizer.title = 'Tarik untuk ubah lebar kolom (Klik 2x untuk reset)';
            th.appendChild(resizer);

            resizer.addEventListener('dblclick', function(e) {
                e.stopPropagation();
                e.preventDefault();
                th.style.width = '';
                th.style.minWidth = '';
                th.style.maxWidth = '';
                removeSavedColumnWidth(tableKey, colKey);
            });

            resizer.addEventListener('mousedown', function(e) {
                e.stopPropagation();
                e.preventDefault();
                _isGridResizing = true;

                const startX = e.pageX;
                const startWidth = th.offsetWidth;
                resizer.classList.add('resizing');
                document.body.classList.add('grid-resizing');

                function onMouseMove(moveEvent) {
                    const diff = moveEvent.pageX - startX;
                    const newWidth = Math.max(30, startWidth + diff);
                    const wStr = newWidth + 'px';
                    th.style.width = wStr;
                    th.style.minWidth = wStr;
                    th.style.maxWidth = wStr;
                }

                function onMouseUp() {
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    resizer.classList.remove('resizing');
                    document.body.classList.remove('grid-resizing');

                    if (th.style.width && tableKey && colKey) {
                        saveColumnWidth(tableKey, colKey, th.style.width);
                    }

                    setTimeout(() => {
                        _isGridResizing = false;
                    }, 120);
                }

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            });
        }
    });
}

// Sinkronisasi Tema Gelap (Dark Mode)
function applyTheme(theme) {
    if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        document.body.classList.add('dark-mode');
    } else {
        document.documentElement.classList.remove('dark-mode');
        document.body.classList.remove('dark-mode');
    }
}

window.addEventListener('message', (ev) => {
    if (ev.data && (ev.data.type === 'SET_THEME' || ev.data.action === 'SET_THEME')) {
        applyTheme(ev.data.theme);
    }
});

window.addEventListener('storage', (ev) => {
    if (ev.key === 'ceisa_theme') {
        applyTheme(ev.newValue);
    }
});

function openModuleGuide() {
    const m = document.getElementById('modalModuleGuide');
    if (m) m.classList.add('active');
}

function closeModuleGuide() {
    const m = document.getElementById('modalModuleGuide');
    if (m) m.classList.remove('active');
}

document.addEventListener('DOMContentLoaded', () => {
    try {
        const initialTheme = localStorage.getItem('ceisa_theme');
        if (initialTheme) applyTheme(initialTheme);
    } catch (e) {}

    initSplitbars();
    initColumnChooser();
    initGridContextMenu();
    setupGridColumnResize('#tableItems', 'po_items');
    setupGridColumnResize('#poTable', 'po_browse');
    try {
        const selDec = document.getElementById('numDecimals');
        if (selDec) selDec.value = String(currentPrecision);
    } catch(e) {}
    if (typeof initAntSearchableSelect === 'function') {
        initAntSearchableSelect('poVendor', { placeholder: 'Ketik nama / kode supplier...' });
    }
    resetForm();
    loadPoList(1);

    // Auto-load PR jika dibuka dari tombol "Terbitkan PO dari PR"
    const urlParams = new URLSearchParams(window.location.search);
    const initPr = urlParams.get('pr_no');
    if (initPr) {
        document.getElementById('poPrNo').value = initPr;
        fetch(`purchase_requisition.php?action=lookup_open_pr&search=${encodeURIComponent(initPr)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
            .then(res => res.json())
            .then(res => {
                if (res.status === 'OK' && res.data && res.data.length > 0) {
                    res.data.forEach(it => {
                        if (it.pr_no === initPr) {
                            pullPrItem(it);
                        }
                    });
                }
            })
            .catch(err => console.error(err));
    }
});

document.querySelectorAll('.ant-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => showTab(btn.dataset.tab));
});

function showTab(name) {
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.getElementById('pageEntri').classList.toggle('active', name === 'entri');
    document.getElementById('pageBrowse').classList.toggle('active', name === 'browse');
    document.getElementById('pageTrace').classList.toggle('active', name === 'trace');
    if (name === 'browse') loadPoList(currentPage);
    if (name === 'trace') loadTrace();
}

function setStatus(msg, isErr = false) {
    const el = document.getElementById('poStatus');
    if (el) {
        el.textContent = msg;
        el.style.color = isErr ? 'var(--danger)' : 'var(--text-muted)';
    }
}

// Splitbar Resizing Engine
function initSplitbars() {
    document.querySelectorAll('.splitbar, .split-divider').forEach(bar => {
        const pane = document.getElementById(bar.dataset.pane || '');
        if (!pane) return;
        const key = 'po-split-v3-' + (bar.id || bar.dataset.pane);
        const saved = sessionStorage.getItem(key);
        if (saved && parseInt(saved, 10) >= 60) pane.style.flex = '0 0 ' + parseInt(saved, 10) + 'px';
        bar.addEventListener('pointerdown', (e) => {
            if (e.target.closest('.split-btn') || e.target.closest('.split-btn-collapse')) return;
            e.preventDefault();
            const startY = e.clientY;
            const startH = pane.getBoundingClientRect().height;
            const parentH = bar.parentElement.getBoundingClientRect().height;
            bar.classList.add('dragging');
            const move = (ev) => {
                const dy = ev.clientY - startY;
                let h = bar.dataset.edge === 'next' ? startH - dy : startH + dy;
                h = Math.max(60, Math.min(h, parentH - 120));
                pane.style.flex = '0 0 ' + h + 'px';
            };
            const up = () => {
                bar.classList.remove('dragging');
                sessionStorage.setItem(key, String(Math.round(pane.getBoundingClientRect().height)));
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        });
        bar.addEventListener('dblclick', (e) => {
            if (e.target.closest('.split-btn') || e.target.closest('.split-btn-collapse')) return;
            if (bar.id === 'splitHdr') togglePoPane('hdr');
            else if (bar.id === 'splitFtr') togglePoPane('ftr');
        });
    });
}

let poMaxPane = null;
function togglePoPane(which) {
    const panes = { hdr: 'paneHdr', grid: 'paneGrid', ftr: 'paneFtr' };
    const root = document.getElementById('splitEntri');
    const restore = poMaxPane === which;
    poMaxPane = restore ? null : which;
    root.classList.toggle('max-on', !restore);
    Object.keys(panes).forEach(key => {
        const el = document.getElementById(panes[key]);
        const show = restore || key === which;
        el.style.display = show ? '' : 'none';
        if (!restore && key === which) el.style.flex = '1 1 auto';
    });
    document.getElementById('splitHdr').style.display = (!restore && which === 'ftr') ? 'none' : '';
    document.getElementById('splitFtr').style.display = (!restore && which !== 'ftr') ? 'none' : '';
    if (restore) {
        ['splitHdr', 'splitFtr'].forEach(barId => {
            const bar = document.getElementById(barId);
            const pane = document.getElementById(bar.dataset.pane);
            const saved = sessionStorage.getItem('po-split-v3-' + barId);
            pane.style.flex = (saved && parseInt(saved, 10) >= 60) ? ('0 0 ' + parseInt(saved, 10) + 'px') : '';
            bar.style.display = '';
        });
        document.getElementById('paneGrid').style.flex = '';
    }
}

// Otomatisasi Jatuh Tempo dari Tgl PO + Terms
function onDateOrTermChange() {
    const tgl = document.getElementById('poDt').value;
    const term = document.getElementById('poTerm').value.trim();
    if (!tgl) return;
    const d = new Date(tgl);
    if (isNaN(d.getTime())) return;

    let days = 30;
    if (/cash|cod/i.test(term)) days = 0;
    else {
        const match = term.match(/\d+/);
        if (match) days = parseInt(match[0], 10);
    }
    d.setDate(d.getDate() + days);
    document.getElementById('poJatuhTempo').value = d.toISOString().slice(0, 10);
}

function onVendorChange() {
    const sel = document.getElementById('poVendor');
    const opt = sel.selectedOptions[0];
    if (opt && opt.value) {
        document.getElementById('poAlamat').value = opt.dataset.alamat || '';
        if (opt.dataset.term) document.getElementById('poTerm').value = opt.dataset.term;
        if (opt.dataset.kurs) document.getElementById('poKurs').value = opt.dataset.kurs;
        toggleRate();
        onDateOrTermChange();
    } else {
        document.getElementById('poAlamat').value = '';
    }
}

function toggleRate() {
    const kurs = document.getElementById('poKurs').value;
    const isIdr = (kurs === 'IDR');
    if (isIdr) {
        document.getElementById('poRate').value = '1';
    }
    calcTotals();
}

function generateAutoPoNo() {
    const today = new Date();
    const ym = today.getFullYear().toString().slice(2) + String(today.getMonth() + 1).padStart(2, '0');
    const rand = String(Math.floor(Math.random() * 9000) + 1000);
    const pfx = (typeof CURRENT_CMP_ID !== 'undefined' && CURRENT_CMP_ID) ? (CURRENT_CMP_ID + '-PO') : 'PO';
    document.getElementById('poNo').value = `${pfx}-${ym}-${rand}`;
    setStatus('Nomor PO di-generate otomatis: ' + document.getElementById('poNo').value);
}

function updateStatusUi(status, id) {
    status = String(status || '0');
    const badge = document.getElementById('statusBadgeHeader');
    const actionBox = document.getElementById('statusActionButtons');
    const statusVal = document.getElementById('poStatusValue');
    if (statusVal) statusVal.value = status;

    const statusMap = {
        '0': { label: 'Draft', cls: 'ant-badge ant-badge-belum' },
        '1': { label: 'Approved', cls: 'ant-badge ant-badge-lunas' },
        '2': { label: 'Closed', cls: 'ant-badge ant-badge-lock' },
        '3': { label: 'Batal', cls: 'ant-badge ant-badge-danger' }
    };

    const info = statusMap[status] || { label: 'Status ' + status, cls: 'ant-badge' };
    if (badge) {
        badge.className = info.cls;
        badge.textContent = info.label;
    }

    if (!actionBox) return;
    actionBox.innerHTML = '';

    if (!id) {
        return;
    }

    if (status === '0') {
        actionBox.innerHTML = `
            <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="changePoStatus('1')" title="Setujui Purchase Order (Approve)"><i class="fa-solid fa-check"></i> Approve PO</button>
            <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="changePoStatus('3')" title="Batalkan Purchase Order"><i class="fa-solid fa-ban"></i> Batal</button>
        `;
    } else if (status === '1') {
        actionBox.innerHTML = `
            <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="createRiFromCurrentPo()" title="Terbitkan Penerimaan Barang (Goods Receipt / RI) dari PO ini"><i class="fa-solid fa-truck-ramp-box"></i> Terbitkan RI</button>
            <button type="button" class="ant-btn ant-btn-sm ant-btn-warning" onclick="changePoStatus('0')" title="Kembalikan ke status Draft"><i class="fa-solid fa-rotate-left"></i> Re-open ke Draft</button>
            <button type="button" class="ant-btn ant-btn-sm" style="background:#595959; color:#fff;" onclick="changePoStatus('2')" title="Tutup PO (Selesai)"><i class="fa-solid fa-box-archive"></i> Close PO</button>
        `;
    } else if (status === '2') {
        actionBox.innerHTML = `
            <button type="button" class="ant-btn ant-btn-sm ant-btn-warning" onclick="changePoStatus('1')" title="Buka kembali PO ke Approved"><i class="fa-solid fa-box-open"></i> Buka PO (Approved)</button>
        `;
    } else if (status === '3') {
        actionBox.innerHTML = `
            <button type="button" class="ant-btn ant-btn-sm ant-btn-warning" onclick="changePoStatus('0')" title="Aktifkan kembali ke Draft"><i class="fa-solid fa-rotate-left"></i> Aktifkan ke Draft</button>
        `;
    }
}

function changePoStatus(newStatus) {
    const id = document.getElementById('poId') ? document.getElementById('poId').value : '';
    const poNo = document.getElementById('poNo') ? document.getElementById('poNo').value : '(tanpa nomor)';
    if (!id) {
        showWarning('Simpan Purchase Order terlebih dahulu sebelum mengubah status!');
        return;
    }
    quickChangeStatus(id, newStatus, poNo);
}

async function quickChangeStatus(id, newStatus, poNo) {
    if (!id) return;
    const labels = { '0': 'Draft', '1': 'Approved', '2': 'Closed (Selesai)', '3': 'Batal' };
    const targetLbl = labels[newStatus] || newStatus;
    
    let confirmPrompt = `Ubah status Purchase Order "${poNo}" menjadi <strong>${targetLbl}</strong>?`;
    let confirmBtn = 'Ya, Ubah Status';
    let icon = 'question';
    if (newStatus === '1') {
        confirmPrompt = `Setujui (Approve) Purchase Order "<strong>${poNo}</strong>"?<br><small style="color:var(--text-muted);">Status Approved menandakan pesanan resmi disetujui dan siap diteruskan untuk Penerimaan Barang (RI).</small>`;
        confirmBtn = 'Ya, Setujui PO';
        icon = 'info';
    } else if (newStatus === '2') {
        confirmPrompt = `Tutup (Close) Purchase Order "<strong>${poNo}</strong>"?<br><small style="color:var(--text-muted);">PO akan ditandai selesai/arsip.</small>`;
        confirmBtn = 'Ya, Tutup PO';
        icon = 'warning';
    } else if (newStatus === '3') {
        confirmPrompt = `Batalkan Purchase Order "<strong>${poNo}</strong>"?<br><small style="color:var(--danger);">PO akan dibatalkan.</small>`;
        confirmBtn = 'Ya, Batalkan PO';
        icon = 'warning';
    }

    const ok = await showConfirm(confirmPrompt, 'Konfirmasi Perubahan Status', confirmBtn, 'Batal', icon);
    if (!ok) return;

    const fd = new FormData();
    fd.append('id', id);
    fd.append('status', newStatus);
    if (typeof CURRENT_CMP_ID !== 'undefined' && CURRENT_CMP_ID) {
        fd.append('cmp_id', CURRENT_CMP_ID);
    }

    fetch('purchase_order.php?action=update_status' + (typeof CURRENT_CMP_ID !== 'undefined' && CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            showToast(res.message, 'success');
            if (document.getElementById('poId') && document.getElementById('poId').value === id) {
                updateStatusUi(newStatus, id);
            }
            loadPoList(currentPage);
        } else {
            showError(res.message || 'Gagal mengubah status');
        }
    })
    .catch(() => showError('Gagal menghubungi server untuk mengubah status'));
}

function copyCurrentPo() {
    const vendor = document.getElementById('poVendor').value;
    const items = collectItems();
    if (!vendor && items.length === 0) {
        showWarning('Tidak ada data PO atau rincian barang untuk diduplikasi.');
        return;
    }

    document.getElementById('poId').value = '';
    generateAutoPoNo();
    document.getElementById('poDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('poExpDt').value = new Date().toISOString().slice(0, 10);
    onDateOrTermChange();
    const statusVal = document.getElementById('poStatusValue');
    if (statusVal) statusVal.value = '0';
    updateStatusUi('0', '');
    originalLoadedData = null;
    const btnRestore = document.getElementById('btnRestoreOriginal');
    if (btnRestore) btnRestore.style.display = 'none';

    document.getElementById('poModeBadge').className = 'ant-badge ant-badge-warning';
    document.getElementById('poModeBadge').textContent = 'Mode: Duplikasi PO Baru (Belum Disimpan)';
    setStatus('PO telah disalin ke transaksi baru dengan nomor ' + document.getElementById('poNo').value + '. Silakan periksa rincian lalu Simpan Transaksi.');
    showSuccess('Data supplier dan ' + items.length + ' item barang telah disalin ke Purchase Order baru. Silakan klik "Simpan Transaksi" untuk menerbitkan.', 'Duplikasi Berhasil');
}

function duplicatePoFromBrowse(id) {
    fetch(`purchase_order.php?action=get_detail&id=${encodeURIComponent(id)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                showError(res.message || 'Gagal memuat detail PO untuk duplikasi');
                return;
            }
            fillHeader(res.header, res.items || []);
            // Ubah menjadi transaksi baru
            document.getElementById('poId').value = '';
            generateAutoPoNo();
            document.getElementById('poDt').value = new Date().toISOString().slice(0, 10);
            document.getElementById('poExpDt').value = new Date().toISOString().slice(0, 10);
            onDateOrTermChange();
            const statusVal = document.getElementById('poStatusValue');
            if (statusVal) statusVal.value = '0';
            updateStatusUi('0', '');
            originalLoadedData = null;
            const btnRestore = document.getElementById('btnRestoreOriginal');
            if (btnRestore) btnRestore.style.display = 'none';

            document.getElementById('poModeBadge').className = 'ant-badge ant-badge-warning';
            document.getElementById('poModeBadge').textContent = 'Mode: Duplikasi PO Baru (Belum Disimpan)';
            showTab('entri');
            showSuccess(`Seluruh rincian barang dari PO sebelumnya telah disalin ke No. Baru: ${document.getElementById('poNo').value}. Silakan sesuaikan dan klik "Simpan Transaksi".`, 'Duplikasi PO Siap');
        })
        .catch(() => showError('Gagal menduplikasi Purchase Order'));
}

function printPoById(id) {
    if (!id) return;
    window.open('purchase_order.php?action=print&id=' + encodeURIComponent(id) + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), '_blank');
}

function createRiFromCurrentPo() {
    const poNo = document.getElementById('poNo') ? document.getElementById('poNo').value : '';
    const vendor = document.getElementById('poVendor') ? document.getElementById('poVendor').value : '';
    if (!poNo) {
        showWarning('Purchase Order belum memiliki nomor!');
        return;
    }
    createRiFromPo(poNo, vendor);
}

function createRiFromPo(poNo, vendor) {
    if (!poNo) return;
    const url = 'goods_receipt.php?po_no=' + encodeURIComponent(poNo) + (vendor ? '&vendor=' + encodeURIComponent(vendor) : '') + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : '');
    if (window.parent && window.parent !== window && typeof window.parent.openTab === 'function') {
        window.parent.openTab('pur_ri', 'Penerimaan Barang (RI)', url);
    } else {
        window.open(url, '_blank');
    }
}

function resetForm() {
    document.getElementById('poId').value = '';
    document.getElementById('poNo').value = '';
    document.getElementById('poDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('poExpDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('poVendor').value = '';
    document.getElementById('poVendor').dispatchEvent(new Event('change'));
    document.getElementById('poAlamat').value = '';
    document.getElementById('poShipTo').value = '';
    document.getElementById('poTerm').value = 'Net 30';
    document.getElementById('poFob').value = '1';
    document.getElementById('poPrNo').value = '';
    document.getElementById('poWoNo').value = '';
    onDateOrTermChange();
    document.getElementById('poKurs').value = 'IDR';
    document.getElementById('poRate').value = '1';
    document.getElementById('poTaxable').checked = true;
    document.getElementById('poInclusive').checked = false;
    document.getElementById('poSubcon').checked = false;
    document.getElementById('poNcv').checked = false;
    document.getElementById('poNote').value = '';
    document.getElementById('poDiscPersen').value = '0';
    document.getElementById('poDiscAmt').value = '0';
    document.getElementById('poPph').value = '0';
    document.getElementById('poFreight').value = '0';
    document.getElementById('itemsContainer').innerHTML = '';
    addItemRow();
    toggleRate();
    originalLoadedData = null;
    const btnRestore = document.getElementById('btnRestoreOriginal');
    if (btnRestore) btnRestore.style.display = 'none';
    document.getElementById('poModeBadge').className = 'ant-badge ant-badge-company';
    document.getElementById('poModeBadge').textContent = 'Mode: Baru';
    
    // Status UI reset
    const statusVal = document.getElementById('poStatusValue');
    if (statusVal) statusVal.value = '0';
    updateStatusUi('0', '');

    setStatus('Pesanan pembelian baru. Pilih Supplier, isi rincian barang, lalu Simpan Transaksi.');
}

function parseRawNumber(val) {
    if (val === null || val === undefined) return 0;
    if (typeof val === 'number') return isNaN(val) ? 0 : val;
    let s = String(val).trim();
    if (!s) return 0;
    if (s.indexOf('.') !== -1 && s.indexOf(',') !== -1) {
        if (s.lastIndexOf(',') > s.lastIndexOf('.')) {
            s = s.replace(/\./g, '').replace(/,/g, '.');
        } else {
            s = s.replace(/,/g, '');
        }
    } else if (s.indexOf(',') !== -1) {
        if (s.length - s.lastIndexOf(',') <= 3) {
            s = s.replace(/,/g, '.');
        } else {
            s = s.replace(/,/g, '');
        }
    }
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}

function formatMoney(val, decimals) {
    if (decimals === undefined || decimals === null) decimals = currentPrecision;
    const n = parseRawNumber(val);
    return n.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function changeDecimalPrecision(val) {
    currentPrecision = parseInt(val, 10);
    if (isNaN(currentPrecision) || currentPrecision < 0 || currentPrecision > 4) currentPrecision = 2;
    localStorage.setItem('ceisa_po_decimals', currentPrecision);
    
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        const qtyInp = row.querySelector('.it-qty');
        const priceInp = row.querySelector('.it-price');
        const discInp = row.querySelector('.it-disc');
        const amtInp = row.querySelector('.it-amount');

        if (qtyInp) {
            const raw = parseRawNumber(qtyInp.dataset.raw !== undefined ? qtyInp.dataset.raw : qtyInp.value);
            qtyInp.dataset.raw = raw;
            qtyInp.value = formatMoney(raw, Math.min(currentPrecision, 2));
        }
        if (priceInp) {
            const raw = parseRawNumber(priceInp.dataset.raw !== undefined ? priceInp.dataset.raw : priceInp.value);
            priceInp.dataset.raw = raw;
            priceInp.value = formatMoney(raw, currentPrecision);
        }
        if (discInp) {
            const raw = parseRawNumber(discInp.dataset.raw !== undefined ? discInp.dataset.raw : discInp.value);
            discInp.dataset.raw = raw;
            discInp.value = formatMoney(raw, 2);
        }
        if (amtInp) {
            const raw = parseRawNumber(amtInp.dataset.raw !== undefined ? amtInp.dataset.raw : amtInp.value);
            amtInp.dataset.raw = raw;
            amtInp.value = formatMoney(raw, currentPrecision);
        }
    });

    calcTotals();
}

function onNumFocus(el) {
    const raw = (el.dataset.raw !== undefined && el.dataset.raw !== '') ? el.dataset.raw : parseRawNumber(el.value);
    el.value = raw > 0 ? String(raw) : (raw === 0 ? '0' : '');
    setTimeout(() => el.select(), 10);
}

function onQtyBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, Math.min(currentPrecision, 2));
    calcItemRow(el);
}

function onPriceBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, currentPrecision);
    calcItemRow(el);
}

function onDiscBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, 2);
    calcItemRow(el);
}

function onDiscAmtBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, currentPrecision);
    onDiscAmtChange();
}

function onPphBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, currentPrecision);
    calcTotals();
}

function onFreightBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, currentPrecision);
    calcTotals();
}

// Grid Rincian Barang
function tambahBaris() {
    addItemRow();
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    const last = rows[rows.length - 1];
    if (last) openMaterialPicker(last.querySelector('.it-kode'));
}

function addItemRow(item = {}) {
    const container = document.getElementById('itemsContainer');
    const row = document.createElement('tr');
    row.className = 'item-row';
    const seq = container.querySelectorAll('.item-row').length + 1;
    const rawQty = parseRawNumber(item.qty ?? 1);
    const rawPrice = parseRawNumber(item.harga ?? item.price ?? 0);
    const rawDisc = parseRawNumber(item.diskon ?? item.disc ?? 0);
    const rawAmount = parseRawNumber(item.total ?? item.amount ?? (rawQty * rawPrice * (1 - rawDisc / 100)));

    row.innerHTML = `
        <td style="text-align:center;" class="it-seq col-seq">${seq}</td>
        <td class="col-kode">
            <div class="inline-box" style="gap:2px;">
                <input type="text" class="it-kode" value="${escapeHtml(item.kode_barang || '')}" placeholder="Pilih barang..." ondblclick="openMaterialPicker(this)" oninput="onKodeBarangChange(this)" onblur="onKodeBarangBlur(this)" style="min-width:90px;">
                <span class="reg-status-badge" style="display:none;"></span>
                <button type="button" class="ant-btn ant-btn-sm btn-quick-reg" style="display:none; padding:0 4px; color:#d4380d; background:#fff2e8; border-color:#ffbb96;" title="Daftarkan ke Master Barang" onclick="openQuickRegModal(this)"><i class="fa-solid fa-plus"></i> Master</button>
                <button type="button" class="ant-btn ant-btn-sm" title="Buka katalog barang" onclick="openMaterialPicker(this)"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </td>
        <td class="col-uraian"><input type="text" class="it-uraian" value="${escapeHtml(item.nama_barang || item.uraian || '')}" placeholder="Nama barang" ondblclick="openMaterialPicker(this)"></td>
        <td class="col-color"><input type="text" class="it-color" value="${escapeHtml(item.color_cd || '')}" placeholder="Color"></td>
        <td class="col-style"><input type="text" class="it-style" value="${escapeHtml(item.size_cd || '')}" placeholder="Style/Size"></td>
        <td class="col-qty"><input type="text" class="it-qty num" value="${formatMoney(rawQty, Math.min(currentPrecision, 2))}" data-raw="${rawQty}" onfocus="onNumFocus(this)" onblur="onQtyBlur(this)"></td>
        <td class="col-sat"><input type="text" class="it-sat" value="${escapeHtml(item.satuan || item.sat || 'PCE')}"></td>
        <td class="col-price"><input type="text" class="it-price num" value="${formatMoney(rawPrice, currentPrecision)}" data-raw="${rawPrice}" onfocus="onNumFocus(this)" onblur="onPriceBlur(this)"></td>
        <td class="col-disc"><input type="text" class="it-disc num" value="${formatMoney(rawDisc, 2)}" data-raw="${rawDisc}" onfocus="onNumFocus(this)" onblur="onDiscBlur(this)"></td>
        <td class="col-amount"><input type="text" class="it-amount num" value="${formatMoney(rawAmount, currentPrecision)}" data-raw="${rawAmount}" readonly></td>
        <td class="col-prno"><input type="text" class="it-prno" value="${escapeHtml(item.pr_no || '')}" placeholder="No PR"></td>
        <td class="col-wono"><input type="text" class="it-wono" value="${escapeHtml(item.wo_no || '')}" placeholder="No WO"></td>
        <td class="col-expdt"><input type="date" class="it-expdt" value="${(item.exp_dt || '').slice(0, 10)}"></td>
        <td class="col-note"><input type="text" class="it-note" value="${escapeHtml(item.note || '')}" placeholder="Catatan"></td>
        <td class="col-action" style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    container.appendChild(row);
    calcItemRow(row.querySelector('.it-qty'));
    renumber();
    queueMaterialCodeCheck();
}

function removeRow(btn) {
    const body = document.getElementById('itemsContainer');
    btn.closest('tr').remove();
    if (!body.querySelector('.item-row')) addItemRow();
    renumber();
    calcTotals();
}

function renumber() {
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    rows.forEach((row, i) => {
        row.querySelector('.it-seq').textContent = String(i + 1);
    });
    document.getElementById('itemCountInfo').textContent = rows.length + ' baris rincian';
}

function calcItemRow(el) {
    const row = el.closest('tr');
    if (!row) return;
    const qtyInp = row.querySelector('.it-qty');
    const priceInp = row.querySelector('.it-price');
    const discInp = row.querySelector('.it-disc');
    const amtInp = row.querySelector('.it-amount');

    const qty = parseRawNumber(qtyInp?.dataset.raw !== undefined ? qtyInp.dataset.raw : qtyInp?.value);
    const price = parseRawNumber(priceInp?.dataset.raw !== undefined ? priceInp.dataset.raw : priceInp?.value);
    const disc = parseRawNumber(discInp?.dataset.raw !== undefined ? discInp.dataset.raw : discInp?.value);

    const amt = (qty * price) * (1 - (disc / 100));
    if (amtInp) {
        amtInp.dataset.raw = amt;
        amtInp.value = formatMoney(amt, currentPrecision);
    }
    calcTotals();
}

function onDiscPercentChange() {
    const subtotal = lastCalc.subtotal;
    const pct = parseFloat(document.getElementById('poDiscPersen').value) || 0;
    const amt = (subtotal * pct) / 100;
    const discAmtEl = document.getElementById('poDiscAmt');
    discAmtEl.dataset.raw = amt;
    discAmtEl.value = formatMoney(amt, currentPrecision);
    calcTotals();
}

function onDiscAmtChange() {
    const subtotal = lastCalc.subtotal;
    const discAmtEl = document.getElementById('poDiscAmt');
    const amt = parseRawNumber(discAmtEl.dataset.raw !== undefined ? discAmtEl.dataset.raw : discAmtEl.value);
    discAmtEl.dataset.raw = amt;
    discAmtEl.value = formatMoney(amt, currentPrecision);
    const pct = subtotal > 0 ? (amt / subtotal * 100) : 0;
    document.getElementById('poDiscPersen').value = pct.toFixed(2);
    calcTotals();
}

function calcTotals() {
    let subtotal = 0;
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        const amtInp = row.querySelector('.it-amount');
        if (amtInp) {
            subtotal += parseRawNumber(amtInp.dataset.raw !== undefined ? amtInp.dataset.raw : amtInp.value);
        }
    });

    const discAmtEl = document.getElementById('poDiscAmt');
    const discAmt = parseRawNumber(discAmtEl ? (discAmtEl.dataset.raw !== undefined ? discAmtEl.dataset.raw : discAmtEl.value) : 0);
    const isTaxable = document.getElementById('poTaxable').checked;
    const isInclusive = document.getElementById('poInclusive').checked;

    let dpp = Math.max(0, subtotal - discAmt);
    let ppn = 0;

    if (isTaxable) {
        if (isInclusive) {
            dpp = dpp / 1.11;
            ppn = dpp * 0.11;
        } else {
            ppn = dpp * 0.11;
        }
    }

    const pphEl = document.getElementById('poPph');
    const pph = parseRawNumber(pphEl ? (pphEl.dataset.raw !== undefined ? pphEl.dataset.raw : pphEl.value) : 0);
    const freightEl = document.getElementById('poFreight');
    const freight = parseRawNumber(freightEl ? (freightEl.dataset.raw !== undefined ? freightEl.dataset.raw : freightEl.value) : 0);
    const grand = dpp + ppn + freight - pph;

    lastCalc = { subtotal, discAmt, dpp, ppn, pph, freight, grand };

    document.getElementById('poSubtotal').value = formatMoney(subtotal, currentPrecision);
    document.getElementById('poDpp').value = formatMoney(dpp, currentPrecision);
    document.getElementById('poPpn').value = formatMoney(ppn, currentPrecision);
    document.getElementById('poTotalOrder').value = formatMoney(grand, currentPrecision);

    document.getElementById('poTerbilang').textContent = terbilang(grand) + ' ' + (document.getElementById('poKurs').value || 'Rupiah');
}

function collectItems() {
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    const items = [];
    rows.forEach(r => {
        const kd = (r.querySelector('.it-kode')?.value || '').trim();
        const nm = (r.querySelector('.it-uraian')?.value || '').trim();
        const qtyInp = r.querySelector('.it-qty');
        const priceInp = r.querySelector('.it-price');
        const discInp = r.querySelector('.it-disc');
        const amtInp = r.querySelector('.it-amount');

        const qty = parseRawNumber(qtyInp?.dataset.raw !== undefined ? qtyInp.dataset.raw : qtyInp?.value);
        const price = parseRawNumber(priceInp?.dataset.raw !== undefined ? priceInp.dataset.raw : priceInp?.value);
        const disc = parseRawNumber(discInp?.dataset.raw !== undefined ? discInp.dataset.raw : discInp?.value);
        const amt = parseRawNumber(amtInp?.dataset.raw !== undefined ? amtInp.dataset.raw : amtInp?.value);

        if (kd && qty > 0) {
            items.push({
                kode_barang: kd,
                nama_barang: nm,
                color_cd: (r.querySelector('.it-color')?.value || '').trim(),
                size_cd: (r.querySelector('.it-style')?.value || '').trim(),
                qty: qty,
                satuan: (r.querySelector('.it-sat')?.value || 'PCE').trim(),
                harga: price,
                diskon: disc,
                total: amt,
                pr_no: (r.querySelector('.it-prno')?.value || '').trim(),
                wo_no: (r.querySelector('.it-wono')?.value || '').trim(),
                exp_dt: (r.querySelector('.it-expdt')?.value || '').trim(),
                note: (r.querySelector('.it-note')?.value || '').trim()
            });
        }
    });
    return items;
}

// =========================================================================
// VALIDASI & VISUAL ALERT KODE BARANG KE MASTER BARANG (bc_material)
// =========================================================================
let registeredMaterialsCache = new Set();
let unregisteredMaterialsCache = new Set();
let checkCodeTimer = null;
let currentQuickRegTargetRow = null;

function queueMaterialCodeCheck() {
    clearTimeout(checkCodeTimer);
    checkCodeTimer = setTimeout(validateAllItemCodes, 150);
}

function onKodeBarangChange(inp) {
    const row = inp.closest('tr');
    if (!row) return;
    const val = inp.value.trim();
    if (!val) {
        row.classList.remove('unregistered-row');
        inp.classList.remove('is-unregistered');
        const badge = row.querySelector('.reg-status-badge');
        const btnQuick = row.querySelector('.btn-quick-reg');
        if (badge) badge.style.display = 'none';
        if (btnQuick) btnQuick.style.display = 'none';
    } else {
        queueMaterialCodeCheck();
    }
}

function onKodeBarangBlur(inp) {
    const val = inp.value.trim();
    if (val) queueMaterialCodeCheck();
}

function validateAllItemCodes() {
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    const toCheck = [];
    rows.forEach(r => {
        const val = (r.querySelector('.it-kode')?.value || '').trim();
        if (val && !registeredMaterialsCache.has(val) && !unregisteredMaterialsCache.has(val)) {
            toCheck.push(val);
        }
    });

    if (toCheck.length > 0) {
        const cmpParam = CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : '';
        const fd = new FormData();
        fd.append('codes', toCheck.join(','));
        fetch(`purchase_order.php?action=check_material_codes${cmpParam}`, {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                (res.registered || []).forEach(c => registeredMaterialsCache.add(c));
                (res.unregistered || []).forEach(c => unregisteredMaterialsCache.add(c));
                updateAllRowBadges();
            }
        })
        .catch(err => console.error('Gagal verifikasi kode barang:', err));
    } else {
        updateAllRowBadges();
    }
}

function updateAllRowBadges() {
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    rows.forEach(r => updateSingleRowBadge(r));
}

function updateSingleRowBadge(row) {
    const inp = row.querySelector('.it-kode');
    const badge = row.querySelector('.reg-status-badge');
    const btnQuick = row.querySelector('.btn-quick-reg');
    if (!inp || !badge) return;

    const code = inp.value.trim();
    if (!code) {
        row.classList.remove('unregistered-row');
        inp.classList.remove('is-unregistered');
        badge.style.display = 'none';
        if (btnQuick) btnQuick.style.display = 'none';
        return;
    }

    if (registeredMaterialsCache.has(code)) {
        row.classList.remove('unregistered-row');
        inp.classList.remove('is-unregistered');
        badge.className = 'reg-status-badge badge-registered';
        badge.innerHTML = '<i class="fa-solid fa-check"></i> Master';
        badge.title = 'Terdaftar resmi di Master Barang & Material Pabean';
        badge.style.display = 'inline-flex';
        badge.onclick = null;
        if (btnQuick) btnQuick.style.display = 'none';
    } else if (unregisteredMaterialsCache.has(code)) {
        row.classList.add('unregistered-row');
        inp.classList.add('is-unregistered');
        badge.className = 'reg-status-badge badge-unregistered';
        badge.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Belum Ada';
        badge.title = 'Kode barang belum ada di Master Barang. Klik untuk daftarkan.';
        badge.style.display = 'inline-flex';
        badge.onclick = () => openQuickRegModal(inp);
        if (btnQuick) btnQuick.style.display = 'inline-flex';
    }
}

function openQuickRegModal(el) {
    const row = el.closest('tr');
    if (!row) return;
    currentQuickRegTargetRow = row;

    const kode = (row.querySelector('.it-kode')?.value || '').trim();
    const uraian = (row.querySelector('.it-uraian')?.value || '').trim();
    const sat = (row.querySelector('.it-sat')?.value || 'PCE').trim();
    const price = parseRawNumber(row.querySelector('.it-price')?.value || 0);

    document.getElementById('qregKode').value = kode;
    document.getElementById('qregUraian').value = uraian;
    document.getElementById('qregSatuan').value = sat || 'PCE';
    document.getElementById('qregPrice').value = price;
    document.getElementById('qregPosTarif').value = '';
    document.getElementById('qregKlas').value = 'Raw Materials';

    document.getElementById('modalQuickMaterial').style.display = 'flex';
    document.getElementById('modalQuickMaterial').classList.add('active');
    setTimeout(() => {
        if (!kode) document.getElementById('qregKode').focus();
        else if (!uraian) document.getElementById('qregUraian').focus();
        else document.getElementById('qregSatuan').focus();
    }, 100);
}

function closeQuickRegModal() {
    document.getElementById('modalQuickMaterial').style.display = 'none';
    document.getElementById('modalQuickMaterial').classList.remove('active');
    currentQuickRegTargetRow = null;
}

function submitQuickRegMaterial() {
    const kode = document.getElementById('qregKode').value.trim();
    const uraian = document.getElementById('qregUraian').value.trim();
    const sat = document.getElementById('qregSatuan').value.trim() || 'PCE';
    const klas = document.getElementById('qregKlas').value;
    const posTarif = document.getElementById('qregPosTarif').value.trim();
    const price = parseRawNumber(document.getElementById('qregPrice').value || 0);

    if (!kode || !uraian) {
        showWarning('Kode Barang dan Nama / Uraian wajib diisi!');
        return;
    }

    const fd = new FormData();
    fd.append('kode_barang', kode);
    fd.append('uraian', uraian);
    fd.append('kode_satuan', sat);
    fd.append('klas', klas);
    fd.append('pos_tarif', posTarif);
    fd.append('unit_price', price);
    if (CURRENT_CMP_ID) fd.append('cmp_id', CURRENT_CMP_ID);

    fetch('purchase_order.php?action=quick_add_material' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            registeredMaterialsCache.add(kode);
            unregisteredMaterialsCache.delete(kode);

            if (currentQuickRegTargetRow) {
                currentQuickRegTargetRow.querySelector('.it-kode').value = kode;
                if (!currentQuickRegTargetRow.querySelector('.it-uraian').value) {
                    currentQuickRegTargetRow.querySelector('.it-uraian').value = uraian;
                }
                currentQuickRegTargetRow.querySelector('.it-sat').value = sat;
                updateSingleRowBadge(currentQuickRegTargetRow);
            }
            updateAllRowBadges();
            closeQuickRegModal();
            showSuccess(res.message, 'Master Barang Ditambahkan');
        } else {
            showError(res.message || 'Gagal mendaftarkan barang');
        }
    })
    .catch(() => showError('Gagal menghubungi server'));
}

// Simpan Purchase Order
async function savePo() {
    const vendor = document.getElementById('poVendor').value;
    if (!vendor) {
        showWarning('Pilih Supplier / Vendor terlebih dahulu!');
        document.getElementById('poVendor').focus();
        return;
    }
    const items = collectItems();
    if (items.length === 0) {
        showWarning('Mohon isi minimal 1 baris rincian barang!');
        return;
    }

    // Pengecekan Standarisasi Master Barang (bc_material)
    const unregList = [];
    items.forEach(it => {
        const cd = (it.kode_barang || '').trim();
        if (cd && unregisteredMaterialsCache.has(cd)) {
            unregList.push(`${cd} - ${it.uraian || it.nama_barang || ''}`);
        }
    });

    if (unregList.length > 0) {
        const confirmUnreg = await showConfirm(
            `Terdapat ${unregList.length} item barang yang belum terdaftar di Master Barang:\n\n` +
            unregList.slice(0, 5).map(s => '• ' + s).join('\n') +
            (unregList.length > 5 ? `\n... dan ${unregList.length - 5} item lainnya.` : '') +
            `\n\nApakah Anda ingin melanjutkan penyimpanan sebagai item Jasa / Non-Inventory, atau mendaftarkannya terlebih dahulu?`,
            'Peringatan: Item Belum Terdaftar di Master Barang',
            'Tetap Simpan (Non-Inventory / Jasa)',
            'Daftarkan Barang Dulu',
            'warning'
        );
        if (!confirmUnreg) {
            const firstUnregRow = document.querySelector('.item-row.unregistered-row');
            if (firstUnregRow) {
                firstUnregRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const inp = firstUnregRow.querySelector('.it-kode');
                if (inp) openQuickRegModal(inp);
            }
            return;
        }
    }

    calcTotals();

    const payload = {
        id: document.getElementById('poId').value,
        po_no: document.getElementById('poNo').value,
        status: document.getElementById('poStatusValue') ? document.getElementById('poStatusValue').value : '0',
        po_dt: document.getElementById('poDt').value,
        exp_dt: document.getElementById('poExpDt').value,
        jatuhtempo: document.getElementById('poJatuhTempo').value,
        vendor: vendor,
        shipto: document.getElementById('poShipTo').value,
        term: document.getElementById('poTerm').value,
        fob: document.getElementById('poFob').value,
        kurs: document.getElementById('poKurs').value,
        rate: parseFloat(document.getElementById('poRate').value) || 1,
        vendoristaxable: document.getElementById('poTaxable').checked ? 'Y' : 'N',
        inclusivetax: document.getElementById('poInclusive').checked ? 'Y' : 'N',
        issubcon: document.getElementById('poSubcon').checked ? 'Y' : 'N',
        ncv: document.getElementById('poNcv').checked ? '1' : '0',
        note: document.getElementById('poNote').value,
        subtotal: lastCalc.subtotal,
        disc_persen: parseFloat(document.getElementById('poDiscPersen').value) || 0,
        disc_amt: lastCalc.discAmt,
        ppn: lastCalc.ppn,
        pph: lastCalc.pph,
        est_freight: lastCalc.freight,
        total_order: lastCalc.grand,
        items: items
    };

    setStatus('Menyimpan Purchase Order...');
    fetch('purchase_order.php?action=save' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            document.getElementById('poId').value = res.id;
            document.getElementById('poNo').value = res.po_no;
            document.getElementById('poModeBadge').className = 'ant-badge ant-badge-company';
            document.getElementById('poModeBadge').textContent = 'PO Terbuka: ' + res.po_no;
            updateStatusUi(res.status || document.getElementById('poStatusValue').value || '0', res.id);
            setStatus('Berhasil disimpan. No. PO: ' + res.po_no);
            showSuccess('Purchase Order ' + res.po_no + ' berhasil disimpan!', 'Tersimpan');
            loadPoList(currentPage);
        } else {
            showError(res.message || 'Gagal menyimpan transaksi');
            setStatus(res.message || 'Gagal menyimpan', true);
        }
    })
    .catch(() => {
        showError('Gagal menghubungi server');
        setStatus('Gagal menghubungi server', true);
    });
}

function loadPo(id, goEntri) {
    fetch(`purchase_order.php?action=get_detail&id=${encodeURIComponent(id)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                showError(res.message || 'Gagal memuat Purchase Order');
                return;
            }
            fillHeader(res.header, res.items || []);
            if (goEntri) showTab('entri');
        })
        .catch(() => showError('Gagal memuat detail Purchase Order'));
}

function fillHeader(h, items) {
    document.getElementById('poId').value = h.id || '';
    document.getElementById('poNo').value = h.po_no || '';
    document.getElementById('poDt').value = (h.po_dt || '').slice(0, 10);
    document.getElementById('poExpDt').value = (h.exp_dt || '').slice(0, 10);
    document.getElementById('poJatuhTempo').value = (h.jatuhtempo || '').slice(0, 10);
    document.getElementById('poVendor').value = h.vendor || '';
    document.getElementById('poVendor').dispatchEvent(new Event('change'));
    document.getElementById('poShipTo').value = h.shipto || '';
    document.getElementById('poAlamat').value = h.vendor_alamat || '';
    if (!h.vendor_alamat) onVendorChange();
    document.getElementById('poTerm').value = h.term || 'Net 30';
    document.getElementById('poFob').value = h.fob || '1';
    document.getElementById('poKurs').value = h.kurs || 'IDR';
    document.getElementById('poRate').value = h.rate || '1';
    document.getElementById('poTaxable').checked = (h.vendoristaxable || 'Y') !== 'N';
    document.getElementById('poInclusive').checked = (h.inclusivetax || 'N') === 'Y';
    document.getElementById('poSubcon').checked = (h.issubcon || 'N') === 'Y';
    document.getElementById('poNcv').checked = String(h.ncv || '0') === '1';
    document.getElementById('poNote').value = h.note || '';
    document.getElementById('poDiscPersen').value = h.disc_persen || '0';
    const discAmt = parseRawNumber(h.disc_amt || 0);
    const pph = parseRawNumber(h.pph || 0);
    const freight = parseRawNumber(h.est_freight || 0);

    const discAmtEl = document.getElementById('poDiscAmt');
    discAmtEl.dataset.raw = discAmt;
    discAmtEl.value = formatMoney(discAmt, currentPrecision);

    const pphEl = document.getElementById('poPph');
    pphEl.dataset.raw = pph;
    pphEl.value = formatMoney(pph, currentPrecision);

    const freightEl = document.getElementById('poFreight');
    freightEl.dataset.raw = freight;
    freightEl.value = formatMoney(freight, currentPrecision);

    const cont = document.getElementById('itemsContainer');
    cont.innerHTML = '';
    if (items.length) items.forEach(it => addItemRow(it));
    else addItemRow();

    toggleRate();
    onDateOrTermChange();

    // Simpan snapshot untuk Reset ke Data Awal
    if (h.id) {
        originalLoadedData = JSON.parse(JSON.stringify({ header: h, items: items }));
        const btnRestore = document.getElementById('btnRestoreOriginal');
        if (btnRestore) btnRestore.style.display = 'inline-flex';
    } else {
        originalLoadedData = null;
        const btnRestore = document.getElementById('btnRestoreOriginal');
        if (btnRestore) btnRestore.style.display = 'none';
    }

    document.getElementById('poModeBadge').className = 'ant-badge ant-badge-company';
    document.getElementById('poModeBadge').textContent = 'PO Terbuka: ' + (h.po_no || '');

    // Status UI update
    const poStatus = String(h.status || '0');
    const statusVal = document.getElementById('poStatusValue');
    if (statusVal) statusVal.value = poStatus;
    updateStatusUi(poStatus, h.id);

    setStatus('PO ' + (h.po_no || '') + ' dimuat di Entri.');
}

// Kembalikan Data ke Kondisi Awal Sebelum Diedit
async function restoreOriginalData() {
    if (!originalLoadedData || !originalLoadedData.header) {
        showWarning('Tidak ada data awal yang tersimpan untuk transaksi ini.');
        return;
    }
    const no = originalLoadedData.header.po_no || '(tanpa nomor)';
    const ok = await showConfirm(
        `Kembalikan semua field header dan rincian barang Purchase Order "${no}" ke data awal sebelum diedit?\n\nPerubahan yang belum disimpan akan dibatalkan.`,
        'Konfirmasi Kembalikan Data',
        'Ya, Kembalikan Data Awal',
        'Batal',
        'warning'
    );
    if (!ok) return;

    fillHeader(originalLoadedData.header, originalLoadedData.items);
    setStatus(`Data PO "${no}" berhasil dikembalikan ke status awal sebelum diedit.`);
    showSuccess(`Data PO "${no}" telah dikembalikan ke data awal sebelum diedit!`, 'Data Dikembalikan');
}

// Hapus PO
function deleteCurrent() {
    const id = document.getElementById('poId').value;
    const no = document.getElementById('poNo').value || '(baru)';
    if (!id) {
        resetForm();
        return;
    }
    deletePo(id, no, true);
}

async function deletePo(id, poNo, resetAfter) {
    const ok = await showConfirm('Hapus Purchase Order ' + poNo + ' beserta seluruh rincian barangnya?', 'Konfirmasi Hapus', 'Ya, Hapus', 'Batal', 'warning');
    if (!ok) return;
    const fd = new FormData();
    fd.append('id', id);
    if (CURRENT_CMP_ID) fd.append('cmp_id', CURRENT_CMP_ID);
    fetch('purchase_order.php?action=delete' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                if (resetAfter || document.getElementById('poId').value === id) resetForm();
                loadPoList(currentPage);
                setStatus('Purchase Order ' + poNo + ' berhasil dihapus.');
                showSuccess('Purchase Order ' + poNo + ' dihapus.');
            } else {
                showError(res.message || 'Gagal menghapus');
            }
        })
        .catch(() => showError('Gagal menghubungi server'));
}

// Cetak Dokumen PO
function printPo() {
    const id = document.getElementById('poId').value;
    if (!id) {
        showWarning('Simpan Purchase Order terlebih dahulu sebelum mencetak formulir resmi!');
        return;
    }
    window.open('purchase_order.php?action=print&id=' + encodeURIComponent(id) + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), '_blank');
}

// Browse Data Table
function loadPoList(page = 1) {
    currentPage = page;
    const q = new URLSearchParams({
        action: 'list',
        page: String(page),
        limit: '25',
        search: document.getElementById('filterSearch').value,
        search_col: document.getElementById('filterCol').value,
        status: document.getElementById('filterStatus').value,
        tgl_awal: document.getElementById('filterTglAwal').value,
        tgl_akhir: document.getElementById('filterTglAkhir').value
    });
    if (CURRENT_CMP_ID) q.set('cmp_id', CURRENT_CMP_ID);
    fetch('purchase_order.php?' + q.toString())
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('poTableBody');
            if (res.status !== 'OK' || !res.data || !res.data.length) {
                tbody.innerHTML = '<tr><td colspan="12" class="muted" style="padding:16px; text-align:center;">Belum ada data Purchase Order pada periode filter ini.</td></tr>';
                document.getElementById('pageInfo').textContent = 'Menampilkan 0 data';
                document.getElementById('pageBtns').innerHTML = '';
                return;
            }
            const start = (res.page - 1) * res.limit;
            tbody.innerHTML = res.data.map((r, i) => {
                const riCount = parseInt(r.count_ri || 0);
                const piCount = parseInt(r.count_pi || 0);
                const st = String(r.status || '0');
                let stBadge = '<span class="ant-badge ant-badge-belum">Draft</span>';
                let quickStatusBtn = `<button type="button" class="ant-btn ant-btn-sm ant-btn-success" title="Approve PO (Setujui)" onclick="quickChangeStatus('${escapeHtml(r.id)}', '1', '${escapeHtml(r.po_no)}')"><i class="fa-solid fa-check"></i></button>`;
                if (st === '1') {
                    stBadge = '<span class="ant-badge ant-badge-lunas">Approved</span>';
                    quickStatusBtn = `<button type="button" class="ant-btn ant-btn-sm" style="background:#595959; color:#fff;" title="Close PO (Selesai)" onclick="quickChangeStatus('${escapeHtml(r.id)}', '2', '${escapeHtml(r.po_no)}')"><i class="fa-solid fa-box-archive"></i></button>`;
                } else if (st === '2') {
                    stBadge = '<span class="ant-badge ant-badge-lock">Closed</span>';
                    quickStatusBtn = `<button type="button" class="ant-btn ant-btn-sm ant-btn-warning" title="Buka Kembali PO (Approved)" onclick="quickChangeStatus('${escapeHtml(r.id)}', '1', '${escapeHtml(r.po_no)}')"><i class="fa-solid fa-box-open"></i></button>`;
                } else if (st === '3') {
                    stBadge = '<span class="ant-badge ant-badge-danger">Batal</span>';
                    quickStatusBtn = `<button type="button" class="ant-btn ant-btn-sm ant-btn-warning" title="Aktifkan ke Draft" onclick="quickChangeStatus('${escapeHtml(r.id)}', '0', '${escapeHtml(r.po_no)}')"><i class="fa-solid fa-rotate-left"></i></button>`;
                }

                // Pintasan Terbitkan RI (Hanya saat status Approved)
                let createRiBtn = '';
                if (st === '1') {
                    createRiBtn = `<button type="button" class="ant-btn ant-btn-sm" style="background:#52c41a; color:#fff;" title="Terbitkan Penerimaan Barang (Goods Receipt / RI)" onclick="createRiFromPo('${escapeHtml(r.po_no)}', '${escapeHtml(r.vendor)}')"><i class="fa-solid fa-truck-ramp-box"></i></button>`;
                }

                // Tanggal Kebutuhan & Deteksi Overdue
                const expDateStr = (r.exp_dt || '').slice(0, 10);
                let expDisplay = escapeHtml(expDateStr);
                if (r.is_overdue) {
                    expDisplay = `<span style="color:#cf1322; font-weight:700;" title="Jatuh tempo butuh terlewat dan barang belum diterima lengkap!">${escapeHtml(expDateStr)} <span class="ant-badge ant-badge-danger" style="font-size:8px; padding:0 3px;">LATE</span></span>`;
                }

                // Progress Pemenuhan RI vs Qty Order
                const qPo = parseFloat(r.qty_po || 0);
                const qRi = parseFloat(r.qty_ri || 0);
                const sisa = parseFloat(r.sisa_qty || 0);
                const pct = parseInt(r.pct || 0, 10);

                let barColor = '#d9d9d9';
                let pctColor = '#8c8c8c';
                if (pct >= 100) {
                    barColor = '#52c41a';
                    pctColor = '#389e0d';
                } else if (pct > 0) {
                    barColor = '#1890ff';
                    pctColor = '#096dd9';
                }

                const realisasiHtml = `
                    <div style="min-width:115px; text-align:left; padding:2px 0;">
                        <div style="display:flex; justify-content:space-between; font-size:9.5px; font-weight:600; margin-bottom:2px;">
                            <span>${formatMoney(qRi, 0)} / ${formatMoney(qPo, 0)}</span>
                            <span style="color:${pctColor};">${pct}%</span>
                        </div>
                        <div style="width:100%; height:5px; background:#f0f0f0; border-radius:3px; overflow:hidden; border:1px solid #e8e8e8;">
                            <div style="width:${pct}%; height:100%; background:${barColor}; border-radius:3px; transition:width 0.3s ease;"></div>
                        </div>
                        <div style="font-size:8.5px; color:${sisa > 0 ? '#d46b08' : '#389e0d'}; margin-top:2px;">
                            ${sisa > 0 ? 'Sisa: ' + formatMoney(sisa, 0) : 'Lengkap'} (${riCount} RI)
                        </div>
                    </div>
                `;

                return `
                <tr data-id="${escapeHtml(r.id)}" class="${r.id === selectedBrowseId ? 'sel' : ''}">
                    <td style="text-align:center;">${start + i + 1}</td>
                    <td class="td-highlight"><strong>${escapeHtml(r.po_no)}</strong></td>
                    <td>${escapeHtml((r.po_dt || '').slice(0, 10))}</td>
                    <td>${escapeHtml(r.vendor_nama || r.vendor)}</td>
                    <td>${escapeHtml(r.kurs || 'IDR')}</td>
                    <td class="num"><strong>${formatMoney(r.total_order, currentPrecision)}</strong></td>
                    <td>${expDisplay}</td>
                    <td>${escapeHtml(r.term || 'Net 30')}</td>
                    <td style="text-align:center;">${realisasiHtml}</td>
                    <td style="text-align:center;">
                        <span class="ant-badge ${piCount > 0 ? 'ant-badge-lunas' : 'ant-badge-belum'}">${piCount > 0 ? piCount + ' PI' : '-'}</span>
                    </td>
                    <td>${stBadge}</td>
                    <td style="text-align:center; white-space:nowrap;">
                        <button type="button" class="ant-btn ant-btn-sm" title="Edit di Entri" onclick="loadPo('${escapeHtml(r.id)}', true)"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm" title="Duplikat PO ke Transaksi Baru" onclick="duplicatePoFromBrowse('${escapeHtml(r.id)}')"><i class="fa-solid fa-copy"></i></button>
                        ${quickStatusBtn}
                        ${createRiBtn}
                        <button type="button" class="ant-btn ant-btn-sm" title="Catatan Riwayat (Audit Trail)" onclick="showDocAuditLog('${escapeHtml(r.po_no)}', 'PO')"><i class="fa-solid fa-clock-rotate-left" style="color:#1890ff;"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-success" title="Cetak PO" onclick="printPoById('${escapeHtml(r.id)}')"><i class="fa-solid fa-print"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" title="Hapus" data-del="${escapeHtml(r.id)}" data-no="${escapeHtml(r.po_no)}"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>
            `}).join('');

            tbody.querySelectorAll('tr[data-id]').forEach(tr => {
                tr.addEventListener('click', ev => {
                    if (ev.target.closest('[data-del]') || ev.target.closest('button')) return;
                    selectedBrowseId = tr.dataset.id;
                    tbody.querySelectorAll('tr').forEach(x => x.classList.remove('sel'));
                    tr.classList.add('sel');
                    if (document.getElementById('chkDetail').checked) showBrowseDetail(tr.dataset.id);
                });
                tr.addEventListener('dblclick', () => loadPo(tr.dataset.id, true));
            });

            tbody.querySelectorAll('[data-del]').forEach(btn => {
                btn.addEventListener('click', ev => {
                    ev.stopPropagation();
                    deletePo(btn.dataset.del, btn.dataset.no, false);
                });
            });

            const totalPages = Math.ceil(res.total / res.limit) || 1;
            document.getElementById('pageInfo').textContent = `Menampilkan ${start + 1} - ${Math.min(res.total, start + res.data.length)} dari ${res.total} data. Dobel-klik baris untuk membuka.`;
            let btns = '';
            if (res.page > 1) btns += `<button type="button" class="ant-btn ant-btn-sm" onclick="loadPoList(${res.page - 1})">Prev</button> `;
            btns += `<span style="margin:0 4px;">${res.page} / ${totalPages}</span> `;
            if (res.page < totalPages) btns += `<button type="button" class="ant-btn ant-btn-sm" onclick="loadPoList(${res.page + 1})">Next</button>`;
            document.getElementById('pageBtns').innerHTML = btns;
        })
        .catch(() => {
            document.getElementById('poTableBody').innerHTML = '<tr><td colspan="12" style="padding:16px; text-align:center; color:var(--danger);">Gagal memuat data dari server.</td></tr>';
        });
}

function toggleBrowseDetail() {
    const box = document.getElementById('browseDetail');
    if (!document.getElementById('chkDetail').checked) {
        box.classList.remove('show');
        box.innerHTML = '';
        return;
    }
    if (!selectedBrowseId) {
        box.classList.add('show');
        box.innerHTML = '<div class="muted" style="padding:12px; text-align:center;">Klik salah satu PO di atas untuk melihat rincian barangnya.</div>';
        return;
    }
    showBrowseDetail(selectedBrowseId);
}

function showBrowseDetail(id) {
    const box = document.getElementById('browseDetail');
    box.classList.add('show');
    box.innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Memuat detail barang...</div>';
    fetch(`purchase_order.php?action=get_detail&id=${encodeURIComponent(id)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                box.innerHTML = '<div class="muted" style="padding:10px;">Detail barang tidak ditemukan.</div>';
                return;
            }
            const rows = (res.items || []).map((it, i) => `
                <tr>
                    <td style="text-align:center;">${i + 1}</td>
                    <td><strong>${escapeHtml(it.kode_barang)}</strong></td>
                    <td>${escapeHtml(it.nama_barang || it.uraian || '')}</td>
                    <td>${escapeHtml(it.color_cd || '-')}</td>
                    <td>${escapeHtml(it.size_cd || '-')}</td>
                    <td class="num">${formatMoney(it.qty, 0)}</td>
                    <td class="num" style="color:#1890ff; font-weight:600;">${formatMoney(it.qty_terima || 0, 0)}</td>
                    <td class="num" style="color:${(it.sisa_qty || 0) > 0 ? '#faad14' : '#52c41a'}; font-weight:600;">${formatMoney(it.sisa_qty !== undefined ? it.sisa_qty : it.qty, 0)}</td>
                    <td>${escapeHtml(it.satuan || it.sat || 'PCE')}</td>
                    <td class="num">${formatMoney(it.harga || it.price || 0, currentPrecision)}</td>
                    <td class="num">${formatMoney(it.diskon || it.disc || 0, 2)}%</td>
                    <td class="num"><strong>${formatMoney(it.total || it.amount || 0, currentPrecision)}</strong></td>
                    <td>${escapeHtml(it.pr_no || '')}</td>
                    <td>${escapeHtml(it.wo_no || '')}</td>
                </tr>`).join('');
            box.innerHTML = `
                <div style="padding:4px 8px; background:var(--table-head-bg); font-weight:600; font-size:10px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between;">
                    <span><i class="fa-solid fa-boxes-stacked"></i> Rincian Barang PO: <strong>${escapeHtml(res.header.po_no)}</strong> (Total: ${formatMoney(res.header.total_order, currentPrecision)})</span>
                    <span>Supplier: <strong>${escapeHtml(res.header.vendor_name || res.header.vendor)}</strong></span>
                </div>
                <div class="grid-wrap" style="max-height:160px;">
                    <table class="dw" style="min-width:1100px;">
                        <thead>
                            <tr>
                                <th style="width:30px; text-align:center;">#</th>
                                <th>Kode Barang</th>
                                <th>Nama &amp; Uraian Barang</th>
                                <th>Color</th>
                                <th>Style</th>
                                <th style="text-align:right;">Qty PO</th>
                                <th style="text-align:right;">Qty Terima</th>
                                <th style="text-align:right;">Sisa Qty</th>
                                <th>Satuan</th>
                                <th style="text-align:right;">Harga Sat</th>
                                <th style="text-align:right;">Disc(%)</th>
                                <th style="text-align:right;">Total Nilai</th>
                                <th>No. PR</th>
                                <th>No. WO</th>
                            </tr>
                        </thead>
                        <tbody>${rows || '<tr><td colspan="14" class="muted" style="padding:8px; text-align:center;">Tidak ada barang.</td></tr>'}</tbody>
                    </table>
                </div>`;
        });
}

// Trace Realisasi PO (Tracking Pengadaan: RI & PI)
let traceData = null;
let selectedTraceIndex = 0;

function loadTrace() {
    const id = document.getElementById('poId').value;
    if (!id) {
        document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Belum ada Purchase Order terbuka. Buka atau pilih PO terlebih dahulu.</div>';
        document.getElementById('traceRi').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Belum ada penerimaan gudang.</div>';
        document.getElementById('tracePi').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Belum ada faktur pembelian.</div>';
        const badge = document.getElementById('traceSelectedPoBadge');
        if (badge) badge.textContent = 'Pilih item';
        document.getElementById('traceRiCount').textContent = '0 penerimaan';
        document.getElementById('tracePiCount').textContent = '0 faktur';
        return;
    }

    document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data tracking pengadaan...</div>';

    fetch(`purchase_order.php?action=trace&id=${encodeURIComponent(id)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; color:var(--danger);">' + (res.message || 'Gagal memuat trace') + '</div>';
                return;
            }
            traceData = res;
            selectedTraceIndex = 0;
            renderTraceLines();
            selectTraceLine(0);
        })
        .catch(() => {
            document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; color:var(--danger);">Gagal memuat data pelacakan dari server.</div>';
        });
}

function renderTraceLines() {
    const lines = (traceData && traceData.lines) || [];
    if (!lines.length) {
        document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">PO ini belum memiliki rincian barang.</div>';
        document.getElementById('traceRi').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">-</div>';
        document.getElementById('tracePi').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">-</div>';
        return;
    }

    const rowsHtml = lines.map((it, i) => `
        <tr class="${i === selectedTraceIndex ? 'sel' : ''}" style="cursor:pointer;" onclick="selectTraceLine(${i})" title="Klik untuk melacak realisasi RI & PI">
            <td style="text-align:center;">${i + 1}</td>
            <td><strong>${escapeHtml(it.kode_barang)}</strong></td>
            <td>${escapeHtml(it.nama_barang)}</td>
            <td>${escapeHtml(it.color_cd || '-')}</td>
            <td>${escapeHtml(it.size_cd || '-')}</td>
            <td class="num">${formatNum(it.qty)}</td>
            <td>${escapeHtml(it.satuan || 'PCE')}</td>
            <td class="num">${formatNum(it.harga)}</td>
            <td class="num"><strong>${formatNum(it.total)}</strong></td>
            <td style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ${i === selectedTraceIndex ? 'ant-btn-primary' : ''}" onclick="event.stopPropagation(); selectTraceLine(${i})"><i class="fa-solid fa-arrow-right"></i></button></td>
        </tr>
    `).join('');

    document.getElementById('traceLines').innerHTML = `
        <table class="dw">
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;">#</th>
                    <th>Kode Barang</th>
                    <th>Nama &amp; Uraian Barang</th>
                    <th>Color</th>
                    <th>Style</th>
                    <th style="text-align:right;">Qty PO</th>
                    <th>Satuan</th>
                    <th style="text-align:right;">Harga</th>
                    <th style="text-align:right;">Total PO</th>
                    <th style="width:40px; text-align:center;">Pilih</th>
                </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
        </table>
    `;
}

function selectTraceLine(i) {
    selectedTraceIndex = i;
    const lines = (traceData && traceData.lines) || [];
    const item = lines[i];
    if (!item) return;

    // Highlight row
    const tbody = document.querySelector('#traceLines tbody');
    if (tbody) {
        tbody.querySelectorAll('tr').forEach((tr, idx) => {
            tr.classList.toggle('sel', idx === i);
            const btn = tr.querySelector('button');
            if (btn) btn.className = 'ant-btn ant-btn-sm ' + (idx === i ? 'ant-btn-primary' : '');
        });
    }

    const badge = document.getElementById('traceSelectedPoBadge');
    if (badge) {
        badge.innerHTML = `<i class="fa-solid fa-check"></i> [${escapeHtml(item.kode_barang)}] ${escapeHtml(item.nama_barang)}`;
    }

    // Filter Realisasi RI (Penerimaan Gudang)
    const allRi = (traceData && traceData.ri) || [];
    const riFiltered = allRi.filter(r => r.kode_barang === item.kode_barang);
    document.getElementById('traceRiCount').textContent = riFiltered.length + ' penerimaan (' + item.kode_barang + ')';
    if (!riFiltered.length) {
        document.getElementById('traceRi').innerHTML = `<div class="muted" style="padding:10px; text-align:center;">Belum ada penerimaan barang gudang untuk item [${escapeHtml(item.kode_barang)}].</div>`;
    } else {
        const riRows = riFiltered.map((r, idx) => `
            <tr>
                <td style="text-align:center;">${idx + 1}</td>
                <td><strong>${escapeHtml(r.ri_no)}</strong></td>
                <td>${escapeHtml((r.ship_dt || '').slice(0, 10))}</td>
                <td><span class="ant-badge ant-badge-company">${escapeHtml(r.jenis_bc ? ('BC ' + r.jenis_bc) : 'BC')}</span></td>
                <td><strong>${escapeHtml(r.no_bc || '-')}</strong></td>
                <td>${escapeHtml((r.tgl_bc || '').slice(0, 10))}</td>
                <td class="num"><strong>${formatNum(r.qty)}</strong></td>
                <td>${escapeHtml(r.satuan || 'PCE')}</td>
                <td>${escapeHtml(r.gudang || '')}</td>
            </tr>
        `).join('');
        document.getElementById('traceRi').innerHTML = `
            <table class="dw">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">#</th>
                        <th>No. Surat Jalan (RI)</th>
                        <th>Tgl Penerimaan</th>
                        <th>Jenis BC</th>
                        <th>No. Dok Pabean</th>
                        <th>Tgl Pabean</th>
                        <th style="text-align:right;">Qty Diterima</th>
                        <th>Satuan</th>
                        <th>Gudang</th>
                    </tr>
                </thead>
                <tbody>${riRows}</tbody>
            </table>
        `;
    }

    // Filter Realisasi PI (Faktur Pembelian)
    const allPi = (traceData && traceData.pi) || [];
    const piFiltered = allPi.filter(p => p.kode_barang === item.kode_barang);
    document.getElementById('tracePiCount').textContent = piFiltered.length + ' faktur (' + item.kode_barang + ')';
    if (!piFiltered.length) {
        document.getElementById('tracePi').innerHTML = `<div class="muted" style="padding:10px; text-align:center;">Belum ada faktur pembelian supplier untuk item [${escapeHtml(item.kode_barang)}].</div>`;
    } else {
        const piRows = piFiltered.map((p, idx) => `
            <tr>
                <td style="text-align:center;">${idx + 1}</td>
                <td><strong>${escapeHtml(p.pi_no)}</strong></td>
                <td>${escapeHtml((p.pi_dt || '').slice(0, 10))}</td>
                <td>${escapeHtml(p.no_fp || '-')}</td>
                <td class="num">${formatNum(p.qty)}</td>
                <td>${escapeHtml(p.sat || 'PCE')}</td>
                <td class="num">${formatNum(p.price)}</td>
                <td class="num"><strong>${formatNum(p.amount)}</strong></td>
                <td class="num" style="color:${parseFloat(p.outstand || 0) <= 0 ? 'var(--success)' : 'var(--danger)'};"><strong>${formatNum(p.outstand)}</strong></td>
            </tr>
        `).join('');
        document.getElementById('tracePi').innerHTML = `
            <table class="dw">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">#</th>
                        <th>No. Faktur Pembelian (PI)</th>
                        <th>Tgl Faktur</th>
                        <th>Faktur Pajak</th>
                        <th style="text-align:right;">Qty Faktur</th>
                        <th>Satuan</th>
                        <th style="text-align:right;">Harga Sat</th>
                        <th style="text-align:right;">Total Tagihan</th>
                        <th style="text-align:right;">Sisa (Outstand)</th>
                    </tr>
                </thead>
                <tbody>${piRows}</tbody>
            </table>
        `;
    }
}

function printTraceReport() {
    const id = document.getElementById('poId').value;
    if (!id) {
        showWarning('Buka atau pilih Purchase Order terlebih dahulu!');
        return;
    }
    window.open('purchase_order.php?action=print&id=' + encodeURIComponent(id) + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), '_blank');
}

// Material Picker
function openMaterialPicker(el) {
    const row = el.closest('tr');
    if (!row) return;
    pickerRow = row;
    pickerSelected = -1;
    document.getElementById('materialChosen').value = row.querySelector('.it-kode').value || '';
    document.getElementById('modalMaterial').classList.add('active');
    const box = document.getElementById('materialSearch');
    box.focus();
    searchMaterials();
}

function closeMaterialPicker() {
    document.getElementById('modalMaterial').classList.remove('active');
    pickerRow = null;
}

function setKlas(k) {
    document.querySelectorAll('.klas-btn').forEach(b => b.classList.toggle('active', b.dataset.klas === k));
    document.getElementById('materialTitle').innerHTML = '<i class="fa-solid fa-boxes-stacked"></i> Pilih Master Barang : ' + (klasLabel[k] || k);
    searchMaterials();
}

function queueMaterialSearch() {
    clearTimeout(materialTimer);
    materialTimer = setTimeout(searchMaterials, 250);
}

function searchMaterials() {
    const q = document.getElementById('materialSearch').value.trim();
    const klas = document.querySelector('.klas-btn.active')?.dataset.klas || 'RM';
    const body = document.getElementById('materialBody');
    body.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Mencari data barang...</td></tr>';
    fetch(`purchase_order.php?action=search_materials&klas=${encodeURIComponent(klas)}&q=${encodeURIComponent(q)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
        .then(r => r.json())
        .then(res => {
            materialCache = res.data || [];
            const chosen = document.getElementById('materialChosen').value;
            const found = materialCache.findIndex(m => m.kode_barang === chosen);
            pickerSelected = found >= 0 ? found : (materialCache.length ? 0 : -1);
            if (pickerSelected >= 0) document.getElementById('materialChosen').value = materialCache[pickerSelected].kode_barang;
            renderMaterials();
        })
        .catch(() => {
            body.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center; color:var(--danger);">Gagal membaca master barang.</td></tr>';
        });
}

function renderMaterials() {
    const body = document.getElementById('materialBody');
    if (!materialCache.length) {
        body.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Tidak ada barang yang cocok dengan kriteria pencarian ini.</td></tr>';
        return;
    }
    body.innerHTML = materialCache.map((m, i) => `
        <tr class="${i === pickerSelected ? 'sel' : ''}" onclick="pickMaterialRow(${i})" ondblclick="pickMaterialRow(${i}); confirmMaterial();">
            <td><strong>${escapeHtml(m.kode_barang || '')}</strong></td>
            <td>${escapeHtml(m.uraian || '')}</td>
            <td>${escapeHtml(m.satuan || '')}</td>
            <td class="num">${formatNum(m.harga_satuan)}</td>
            <td class="num">${formatNum(m.berat)}</td>
            <td>${escapeHtml(m.klas || '')}</td>
        </tr>`).join('');
}

function pickMaterialRow(i) {
    pickerSelected = i;
    const m = materialCache[i];
    document.getElementById('materialChosen').value = m ? (m.kode_barang || '') : '';
    renderMaterials();
}

function confirmMaterial() {
    if (pickerSelected < 0 || !materialCache[pickerSelected] || !pickerRow) return;
    const m = materialCache[pickerSelected];
    const row = pickerRow;
    row.querySelector('.it-kode').value = m.kode_barang || '';
    row.querySelector('.it-uraian').value = m.uraian || '';
    row.querySelector('.it-sat').value = m.satuan || 'PCE';
    const harga = parseFloat(m.harga_satuan) || 0;
    if (harga > 0) row.querySelector('.it-price').value = harga;
    calcItemRow(row.querySelector('.it-qty'));
    closeMaterialPicker();

    if (m.kode_barang) {
        registeredMaterialsCache.add(m.kode_barang);
        unregisteredMaterialsCache.delete(m.kode_barang);
        updateSingleRowBadge(row);
    }

    row.querySelector('.it-qty').focus();
    row.querySelector('.it-qty').select();
}

// History Harga Pembelian (PB m_42)
let historyPriceTargetRow = null;
function openHistoryHarga(row) {
    historyPriceTargetRow = row;
    const kode = row.querySelector('.it-kode')?.value.trim();
    const uraian = row.querySelector('.it-uraian')?.value.trim();
    const satuan = row.querySelector('.it-sat')?.value.trim();
    if (!kode) {
        showWarning('Pilih kode barang terlebih dahulu!');
        return;
    }
    document.getElementById('lblHistBarang').textContent = `[${kode}] ${uraian}`;
    document.getElementById('lblHistSatuan').textContent = `Satuan: ${satuan}`;
    document.getElementById('modalHistoryHarga').classList.add('active');
    loadHistoryHarga();
}

function closeModalHistoryHarga() {
    document.getElementById('modalHistoryHarga').classList.remove('active');
    historyPriceTargetRow = null;
}

function loadHistoryHarga() {
    if (!historyPriceTargetRow) return;
    const kode = historyPriceTargetRow.querySelector('.it-kode')?.value.trim();
    const vendor = document.getElementById('poVendor').value;
    const filterVendor = document.getElementById('chkHistFilterVendor').checked ? 1 : 0;
    const tbody = document.getElementById('tbodyHistoryHarga');
    tbody.innerHTML = '<tr><td colspan="9" class="muted" style="padding:16px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat riwayat harga...</td></tr>';

    fetch(`purchase_order.php?action=history_price&kode_barang=${encodeURIComponent(kode)}&vendor=${encodeURIComponent(vendor)}&filter_buyer=${filterVendor}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK' || !res.data || !res.data.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="muted" style="padding:16px; text-align:center;">Tidak ada riwayat harga pembelian untuk barang ini.</td></tr>';
                return;
            }
            tbody.innerHTML = res.data.map(r => `
                <tr>
                    <td style="text-align:center;"><span class="ant-badge ${r.tipe === 'PO' ? 'ant-badge-company' : 'ant-badge-lunas'}">${escapeHtml(r.tipe)}</span></td>
                    <td><strong>${escapeHtml(r.doc_no)}</strong></td>
                    <td>${escapeHtml(r.doc_dt)}</td>
                    <td>${escapeHtml(r.vendor_nama || r.vendor)}</td>
                    <td class="num">${formatNum(r.qty)} ${escapeHtml(r.sat || '')}</td>
                    <td class="num"><strong>${escapeHtml(r.kurs || 'IDR')} ${formatNum(r.price)}</strong></td>
                    <td class="num">${formatNum(r.disc)}%</td>
                    <td class="num">${formatNum(r.rate)}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="applyHistoryPrice(${r.price}, ${r.disc})">Gunakan</button>
                    </td>
                </tr>
            `).join('');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="9" class="muted" style="padding:16px; text-align:center; color:var(--danger);">Gagal memuat history harga dari server.</td></tr>';
        });
}

function applyHistoryPrice(price, disc) {
    if (!historyPriceTargetRow) return;
    const priceInput = historyPriceTargetRow.querySelector('.it-price');
    const discInput = historyPriceTargetRow.querySelector('.it-disc');
    if (priceInput) priceInput.value = price;
    if (discInput) discInput.value = disc || 0;
    calcItemRow(priceInput);
    closeModalHistoryHarga();
    setStatus(`Harga riwayat diterapkan: ${formatNum(price)} (Disc: ${disc}%)`);
}

// Modal Tarik Purchase Requisition (PR)
function openLookupPr() {
    document.getElementById('modalLookupPr').classList.add('active');
    searchPr();
}

function closeLookupPr() {
    document.getElementById('modalLookupPr').classList.remove('active');
}

function appendPrNoToHeader(prNo) {
    if (!prNo) return;
    const prInp = document.getElementById('poPrNo');
    if (!prInp) return;
    let existing = (prInp.value || '').split(',').map(s => s.trim()).filter(Boolean);
    if (!existing.includes(prNo)) {
        existing.push(prNo);
        prInp.value = existing.join(', ');
    }
}

function toggleSelectAllPr(masterChk) {
    const isChecked = masterChk.checked;
    document.querySelectorAll('#lookupPrBody .chk-pr-item').forEach(chk => {
        chk.checked = isChecked;
    });
    updateSelectedPrCount();
}

function updateSelectedPrCount() {
    const checkedCount = document.querySelectorAll('#lookupPrBody .chk-pr-item:checked').length;
    const countText = document.getElementById('prSelectedCountText');
    const btnPull = document.getElementById('btnPullSelectedPr');
    if (countText) countText.textContent = `${checkedCount} item dipilih`;
    if (btnPull) {
        btnPull.innerHTML = `<i class="fa-solid fa-check-double"></i> Tarik Item Terpilih (${checkedCount})`;
        btnPull.disabled = checkedCount === 0;
    }
}

function searchPr() {
    const q = (document.getElementById('searchPrText')?.value || '').trim();
    const dept = (document.getElementById('filterPrDept')?.value || '').trim();
    const st = (document.getElementById('filterPrStatus')?.value || '').trim();
    const tbody = document.getElementById('lookupPrBody');
    const masterChk = document.getElementById('chkAllPr');
    if (masterChk) masterChk.checked = false;
    updateSelectedPrCount();

    tbody.innerHTML = '<tr><td colspan="10" class="muted" style="padding:16px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Mencari dokumen PR...</td></tr>';
    
    let url = `purchase_requisition.php?action=lookup_open_pr&search=${encodeURIComponent(q)}&dept=${encodeURIComponent(dept)}&status=${encodeURIComponent(st)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`;
    
    fetch(url)
        .then(res => res.json())
        .then(res => {
            if (res.status !== 'OK' || !res.data || res.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="muted" style="padding:16px; text-align:center;">Tidak ada Permintaan Pembelian (PR) terbuka yang siap diproses ke PO. Pastikan PR sudah dibuat di modul Purchase Requisition.</td></tr>';
                return;
            }
            tbody.innerHTML = res.data.map((r, idx) => {
                const jsonStr = escapeHtml(JSON.stringify(r));
                const sisaQty = parseFloat(r.sisa_qty) > 0 ? parseFloat(r.sisa_qty) : parseFloat(r.qty);
                const estPrice = parseFloat(r.est_price) || 0;
                
                let stBadge = '<span class="ant-badge ant-badge-approved">Approved</span>';
                if (r.pr_status === '0') stBadge = '<span class="ant-badge ant-badge-draft">Draft</span>';
                else if (r.pr_status === '1') stBadge = '<span class="ant-badge ant-badge-waiting">Diajukan</span>';
                else if (r.pr_status === '3') stBadge = '<span class="ant-badge ant-badge-diproses">In PO (Sebagian)</span>';
                
                let prioBadge = '';
                if (r.priority === 'URGENT') prioBadge = '<span class="ant-badge ant-badge-urgent" style="font-size:9px; margin-left:4px;">URGENT</span>';

                return `
                <tr class="pr-row">
                    <td style="text-align:center;">
                        <input type="checkbox" class="chk-pr-item" data-item="${jsonStr}" onchange="updateSelectedPrCount()">
                    </td>
                    <td>
                        <strong style="color:var(--primary); font-family:monospace;">${escapeHtml(r.pr_no)}</strong>${prioBadge}
                        <br><small class="muted">Tgl: ${escapeHtml(r.pr_dt || '-')}</small>
                    </td>
                    <td><strong style="color:#d46b08;">${escapeHtml(r.required_dt || '-')}</strong></td>
                    <td><strong>${escapeHtml(r.dept)}</strong><br><small class="muted">${escapeHtml(r.requester)}</small></td>
                    <td>
                        <strong style="font-family:monospace; color:var(--primary);">${escapeHtml(r.kode_barang)}</strong> - ${escapeHtml(r.uraian)}
                        ${r.spesifikasi ? ('<br><small class="muted">Spek: ' + escapeHtml(r.spesifikasi) + '</small>') : ''}
                    </td>
                    <td style="text-align:right;">
                        <strong style="font-size:12px; color:#389e0d;">${formatMoney(sisaQty, 2)}</strong>
                        <br><small class="muted">dari ${formatMoney(r.qty, 2)}</small>
                    </td>
                    <td style="text-align:center;">${escapeHtml(r.sat || 'PCE')}</td>
                    <td style="text-align:right;" class="num">${formatMoney(estPrice, currentPrecision)}</td>
                    <td style="text-align:center;">${stBadge}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary btn-tarik-single" data-item="${jsonStr}" onclick="pullPrItemFromBtn(this)" title="Tarik item ini ke PO">Tarik</button>
                    </td>
                </tr>
            `;
            }).join('');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="10" class="muted" style="padding:16px; text-align:center; color:var(--danger);">Gagal memuat daftar PR dari server.</td></tr>';
        });
}

function pullPrItemFromBtn(btn) {
    try {
        const item = JSON.parse(btn.getAttribute('data-item'));
        pullPrItem(item, false);
        btn.disabled = true;
        btn.className = 'ant-btn ant-btn-sm';
        btn.innerHTML = '<i class="fa-solid fa-check" style="color:green;"></i> Ditarik';
    } catch(e) {
        console.error(e);
    }
}

function pullSelectedPrItems() {
    const checkedBoxes = document.querySelectorAll('#lookupPrBody .chk-pr-item:checked');
    if (!checkedBoxes.length) {
        if (typeof Swal !== 'undefined') {
            Swal.fire('Perhatian', 'Silakan centang minimal satu item PR yang ingin ditarik ke PO.', 'warning');
        } else {
            alert('Silakan centang minimal satu item PR yang ingin ditarik ke PO.');
        }
        return;
    }

    let pulledCount = 0;
    const touchedPrNos = new Set();

    checkedBoxes.forEach(chk => {
        try {
            const it = JSON.parse(chk.getAttribute('data-item'));
            if (it) {
                pullPrItem(it, true);
                touchedPrNos.add(it.pr_no);
                pulledCount++;
            }
        } catch(e) {
            console.error(e);
        }
    });

    closeLookupPr();
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'success',
            title: 'Berhasil Tarik PR',
            text: `Berhasil menarik ${pulledCount} item dari ${touchedPrNos.size} dokumen PR ke dalam form PO.`,
            timer: 2500,
            showConfirmButton: false
        });
    } else {
        setStatus(`Berhasil menarik ${pulledCount} item PR ke PO.`);
    }
}

function pullPrItem(item, keepModalOpen = false) {
    if (!item) return;

    // Hapus baris kosong default pertama jika belum diisi apa-apa
    const container = document.getElementById('itemsContainer');
    const existingRows = container.querySelectorAll('.item-row');
    if (existingRows.length === 1) {
        const firstKode = (existingRows[0].querySelector('.it-kode')?.value || '').trim();
        const firstUraian = (existingRows[0].querySelector('.it-uraian')?.value || '').trim();
        if (!firstKode && !firstUraian) {
            existingRows[0].remove();
        }
    }

    // Gabungkan nomor PR ke header (bisa multi-PR dipisah koma)
    appendPrNoToHeader(item.pr_no);

    const remainQty = parseFloat(item.sisa_qty) > 0 ? parseFloat(item.sisa_qty) : parseFloat(item.qty);
    const estPrice = parseFloat(item.est_price) || 0;
    
    // Tambahkan baris baru ke rincian PO via addItemRow langsung
    addItemRow({
        kode_barang: item.kode_barang || '',
        uraian: item.uraian || '',
        qty: remainQty,
        sat: item.sat || 'PCE',
        harga: estPrice,
        pr_no: item.pr_no || '',
        exp_dt: (item.required_dt || item.pr_dt || '').slice(0, 10),
        note: (item.spesifikasi ? ('[' + item.spesifikasi + '] ') : '') + (item.dept ? ('(Dept: ' + item.dept + ')') : '')
    });

    if (!keepModalOpen) {
        closeLookupPr();
    }
    setStatus(`Item ${item.kode_barang} dari PR ${item.pr_no} berhasil dimasukkan ke PO.`);
}

// Menu Excel & Import/Export
function toggleExcelMenu(e) {
    e.stopPropagation();
    const m = document.getElementById('excelMenuDropdown');
    m.style.display = (m.style.display === 'none' || !m.style.display) ? 'block' : 'none';
}

function hideExcelMenu() {
    const m = document.getElementById('excelMenuDropdown');
    if (m) m.style.display = 'none';
}

document.addEventListener('click', () => hideExcelMenu());

function exportGridToExcel() {
    const items = collectItems();
    if (!items.length) {
        showWarning('Tidak ada baris rincian barang untuk diekspor!');
        return;
    }
    const poNo = document.getElementById('poNo').value || 'PO-Draft';
    const rows = items.map((it, idx) => ({
        'No': idx + 1,
        'Kode Barang': it.kode_barang,
        'Nama & Uraian Barang': it.nama_barang,
        'Color': it.color_cd,
        'Style/Size': it.size_cd,
        'Qty': it.qty,
        'Satuan': it.satuan,
        'Harga Satuan': it.harga,
        'Disc (%)': it.diskon,
        'Total Nilai': it.total,
        'No. PR': it.pr_no,
        'No. WO': it.wo_no,
        'Catatan': it.note
    }));

    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Rincian_PO");
    XLSX.writeFile(wb, `Purchase_Order_${poNo}.xlsx`);
    setStatus('Export Excel berhasil diunduh.');
}

function downloadPoImportTemplate() {
    const sampleRows = [
        {
            'Kode Barang': 'RM-FABRIC-001',
            'Nama & Uraian Barang': 'Kain Katun Single Jersey 30s Soft',
            'Color': 'Black',
            'Style/Size': 'Roll',
            'Qty': 500,
            'Satuan': 'KGM',
            'Harga Satuan': 85000,
            'Disc (%)': 0,
            'No. PR': 'PR-2026-001',
            'No. WO': 'WO-001',
            'Catatan': 'Kirim ke Gudang Raw Materials 1'
        },
        {
            'Kode Barang': 'RM-THREAD-002',
            'Nama & Uraian Barang': 'Benang Jahit Spun Polyester 40/2',
            'Color': 'White',
            'Style/Size': 'Cone',
            'Qty': 200,
            'Satuan': 'PCE',
            'Harga Satuan': 12500,
            'Disc (%)': 2.5,
            'No. PR': 'PR-2026-002',
            'No. WO': '',
            'Catatan': 'Spesifikasi tahan luntur'
        }
    ];
    const ws = XLSX.utils.json_to_sheet(sampleRows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Template_PO");
    XLSX.writeFile(wb, "Template_Import_Purchase_Order.xlsx");
    setStatus('Template Excel Import berhasil diunduh.');
}

let importedExcelRows = [];
function openModalImportExcel() {
    document.getElementById('modalImportExcel').classList.add('active');
    document.getElementById('fileExcelImport').value = '';
    document.getElementById('tbodyImportPreview').innerHTML = '<tr><td colspan="12" class="muted" style="padding:24px; text-align:center;">Pilih file Excel (.xlsx / .csv) di atas untuk melihat preview baris barang.</td></tr>';
    document.getElementById('btnApplyImport').disabled = true;
    importedExcelRows = [];
}

function closeModalImportExcel() {
    document.getElementById('modalImportExcel').classList.remove('active');
}

function handleExcelFileSelected(e) {
    const file = e.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(evt) {
        try {
            const data = new Uint8Array(evt.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const sheet = workbook.Sheets[sheetName];
            const rawJson = XLSX.utils.sheet_to_json(sheet, { defval: '' });

            if (!rawJson.length) {
                showWarning('File Excel kosong atau tidak terbaca.');
                return;
            }

            importedExcelRows = [];
            rawJson.forEach(row => {
                const getVal = (aliases) => {
                    for (const a of aliases) {
                        for (const k in row) {
                            if (k.trim().toLowerCase() === a.toLowerCase()) return row[k];
                        }
                    }
                    return '';
                };

                const kd = getVal(['kode barang', 'kode_barang', 'kode', 'item_code', 'item code']);
                const nm = getVal(['nama & uraian barang', 'nama barang', 'nama_barang', 'uraian', 'deskripsi', 'item_name']);
                const qty = parseFloat(getVal(['qty', 'jumlah', 'kuantitas'])) || 0;
                const sat = getVal(['satuan', 'sat', 'uom', 'unit']) || 'PCE';
                const hrg = parseFloat(getVal(['harga satuan', 'harga_satuan', 'harga', 'price'])) || 0;
                const disc = parseFloat(getVal(['disc (%)', 'disc', 'diskon'])) || 0;
                const tot = (qty * hrg) * (1 - (disc / 100));
                const col = getVal(['color', 'warna']);
                const sty = getVal(['style/size', 'style', 'size', 'ukuran']);
                const pr  = getVal(['no. pr', 'no_pr', 'pr']);
                const wo  = getVal(['no. wo', 'no_wo', 'wo']);
                const nt  = getVal(['catatan', 'note', 'keterangan']);

                if (kd && qty > 0) {
                    importedExcelRows.push({
                        kode_barang: kd,
                        nama_barang: nm || kd,
                        color_cd: col,
                        size_cd: sty,
                        qty: qty,
                        satuan: sat,
                        harga: hrg,
                        diskon: disc,
                        total: tot,
                        pr_no: pr,
                        wo_no: wo,
                        note: nt
                    });
                }
            });

            const tbody = document.getElementById('tbodyImportPreview');
            if (!importedExcelRows.length) {
                tbody.innerHTML = '<tr><td colspan="12" class="muted" style="padding:24px; text-align:center; color:var(--danger);">Tidak ada baris barang valid yang terdeteksi. Pastikan file menyertakan kolom Kode Barang dan Qty.</td></tr>';
                document.getElementById('btnApplyImport').disabled = true;
                return;
            }

            tbody.innerHTML = importedExcelRows.map((it, idx) => `
                <tr>
                    <td style="text-align:center;">${idx + 1}</td>
                    <td><strong>${escapeHtml(it.kode_barang)}</strong></td>
                    <td>${escapeHtml(it.nama_barang)}</td>
                    <td>${escapeHtml(it.color_cd)}</td>
                    <td>${escapeHtml(it.size_cd)}</td>
                    <td class="num">${formatNum(it.qty)}</td>
                    <td>${escapeHtml(it.satuan)}</td>
                    <td class="num">${formatNum(it.harga)}</td>
                    <td class="num">${formatNum(it.diskon)}%</td>
                    <td class="num"><strong>${formatNum(it.total)}</strong></td>
                    <td>${escapeHtml(it.pr_no)}</td>
                    <td>${escapeHtml(it.wo_no)}</td>
                </tr>
            `).join('');

            document.getElementById('btnApplyImport').disabled = false;
            document.getElementById('btnApplyImport').innerHTML = `<i class="fa-solid fa-check"></i> Terapkan ${importedExcelRows.length} Baris ke PO`;
        } catch(err) {
            showError('Gagal memproses file Excel: ' + err.message);
        }
    };
    reader.readAsArrayBuffer(file);
}

function applyImportedData() {
    if (!importedExcelRows.length) return;
    const mode = document.querySelector('input[name="importMode"]:checked')?.value || 'append';
    const container = document.getElementById('itemsContainer');

    if (mode === 'replace') {
        container.innerHTML = '';
    }

    importedExcelRows.forEach(it => {
        addItemRow(it);
    });

    closeModalImportExcel();
    renumber();
    calcTotals();
    showSuccess(`Berhasil menerapkan ${importedExcelRows.length} baris barang dari Excel.`, 'Import Sukses');
}

// Context Menu (Klik Kanan PB Style)
let contextTargetRow = null;
function initGridContextMenu() {
    const table = document.getElementById('tableItems');
    if (!table) return;

    let menu = document.getElementById('gridContextMenu');
    if (!menu) {
        menu = document.createElement('div');
        menu.id = 'gridContextMenu';
        menu.className = 'context-menu';
        menu.style.display = 'none';
        menu.innerHTML = `
            <div class="ctx-item" onclick="ctxAction('new')"><i class="fa-solid fa-plus" style="color:var(--primary);"></i><span>Baru (Tambah Baris)</span><kbd>Ctrl+I</kbd></div>
            <div class="ctx-item" onclick="ctxAction('delete')"><i class="fa-solid fa-trash" style="color:var(--danger);"></i><span>Hapus Baris</span><kbd>Ctrl+Del</kbd></div>
            <div class="ctx-item" onclick="ctxAction('pick')"><i class="fa-solid fa-magnifying-glass" style="color:#52c41a;"></i><span>Pilih Barang / Master</span><kbd>Ctrl+E</kbd></div>
            <div class="ctx-sep"></div>
            <div class="ctx-item" onclick="ctxAction('insert_above')"><i class="fa-solid fa-arrow-up"></i><span>Sisipkan Baris Di Atas</span></div>
            <div class="ctx-item" onclick="ctxAction('insert_below')"><i class="fa-solid fa-arrow-down"></i><span>Sisipkan Baris Di Bawah</span></div>
            <div class="ctx-item" onclick="ctxAction('duplicate')"><i class="fa-solid fa-copy"></i><span>Duplikasi Baris</span></div>
            <div class="ctx-sep"></div>
            <div class="ctx-item" onclick="ctxAction('history_price')"><i class="fa-solid fa-clock-rotate-left" style="color:#faad14;"></i><span>History Harga Beli Supplier</span></div>
            <div class="ctx-sep"></div>
            <div class="ctx-item" onclick="ctxAction('import_excel')"><i class="fa-solid fa-file-import" style="color:var(--primary);"></i><span>Import dari Excel...</span></div>
            <div class="ctx-item" onclick="ctxAction('export_excel')"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i><span>Export Rincian ke Excel</span></div>
        `;
        document.body.appendChild(menu);
    }

    table.addEventListener('contextmenu', (e) => {
        const row = e.target.closest('tr.item-row');
        if (!row) return;
        e.preventDefault();
        contextTargetRow = row;

        document.querySelectorAll('#tableItems tr.item-row').forEach(r => r.classList.remove('sel'));
        row.classList.add('sel');

        menu.style.display = 'block';
        const menuW = menu.offsetWidth;
        const menuH = menu.offsetHeight;
        let left = e.clientX;
        let top = e.clientY;

        if (left + menuW > window.innerWidth) left = window.innerWidth - menuW - 10;
        if (top + menuH > window.innerHeight) top = window.innerHeight - menuH - 10;

        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    });

    document.addEventListener('click', () => {
        if (menu) menu.style.display = 'none';
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (menu) menu.style.display = 'none';
        } else if ((e.ctrlKey || e.metaKey) && (e.key === 'i' || e.key === 'I')) {
            e.preventDefault();
            tambahBaris();
        } else if ((e.ctrlKey || e.metaKey) && e.key === 'Delete') {
            e.preventDefault();
            const activeRow = document.querySelector('#tableItems tr.item-row.sel') || document.activeElement.closest('tr.item-row');
            if (activeRow) removeRow(activeRow.querySelector('.col-action button'));
        } else if ((e.ctrlKey || e.metaKey) && (e.key === 'e' || e.key === 'E')) {
            e.preventDefault();
            const activeRow = document.querySelector('#tableItems tr.item-row.sel') || document.activeElement.closest('tr.item-row');
            if (activeRow) openMaterialPicker(activeRow.querySelector('.it-kode'));
        }
    });
}

function ctxAction(type) {
    const row = contextTargetRow;
    const menu = document.getElementById('gridContextMenu');
    if (menu) menu.style.display = 'none';

    if (type === 'new') {
        tambahBaris();
    } else if (type === 'delete' && row) {
        removeRow(row.querySelector('.col-action button'));
    } else if (type === 'pick' && row) {
        openMaterialPicker(row.querySelector('.it-kode'));
    } else if (type === 'insert_above' && row) {
        const newRow = createRowElement();
        row.parentElement.insertBefore(newRow, row);
        renumber();
        calcTotals();
        openMaterialPicker(newRow.querySelector('.it-kode'));
    } else if (type === 'insert_below' && row) {
        const newRow = createRowElement();
        row.parentElement.insertBefore(newRow, row.nextSibling);
        renumber();
        calcTotals();
        openMaterialPicker(newRow.querySelector('.it-kode'));
    } else if (type === 'duplicate' && row) {
        const itemData = {
            kode_barang: row.querySelector('.it-kode')?.value || '',
            nama_barang: row.querySelector('.it-uraian')?.value || '',
            color_cd: row.querySelector('.it-color')?.value || '',
            size_cd: row.querySelector('.it-style')?.value || '',
            qty: parseFloat(row.querySelector('.it-qty')?.value) || 1,
            satuan: row.querySelector('.it-sat')?.value || 'PCE',
            harga: parseFloat(row.querySelector('.it-price')?.value) || 0,
            diskon: parseFloat(row.querySelector('.it-disc')?.value) || 0,
            pr_no: row.querySelector('.it-prno')?.value || '',
            wo_no: row.querySelector('.it-wono')?.value || '',
            exp_dt: row.querySelector('.it-expdt')?.value || '',
            note: row.querySelector('.it-note')?.value || ''
        };
        const newRow = createRowElement(itemData);
        row.parentElement.insertBefore(newRow, row.nextSibling);
        renumber();
        calcTotals();
        setStatus('Baris berhasil diduplikasi.');
    } else if (type === 'history_price' && row) {
        openHistoryHarga(row);
    } else if (type === 'import_excel') {
        openModalImportExcel();
    } else if (type === 'export_excel') {
        exportGridToExcel();
    }
}

function createRowElement(item = {}) {
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.innerHTML = `
        <td style="text-align:center;" class="it-seq col-seq"></td>
        <td class="col-kode">
            <div class="inline-box">
                <input type="text" class="it-kode" value="${escapeHtml(item.kode_barang || '')}" placeholder="Pilih barang..." ondblclick="openMaterialPicker(this)">
                <button type="button" class="ant-btn ant-btn-sm" title="Buka katalog barang" onclick="openMaterialPicker(this)"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </td>
        <td class="col-uraian"><input type="text" class="it-uraian" value="${escapeHtml(item.nama_barang || item.uraian || '')}" placeholder="Nama barang" ondblclick="openMaterialPicker(this)"></td>
        <td class="col-color"><input type="text" class="it-color" value="${escapeHtml(item.color_cd || '')}" placeholder="Color"></td>
        <td class="col-style"><input type="text" class="it-style" value="${escapeHtml(item.size_cd || '')}" placeholder="Style/Size"></td>
        <td class="col-qty"><input type="number" class="it-qty" value="${item.qty ?? 1}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-sat"><input type="text" class="it-sat" value="${escapeHtml(item.satuan || item.sat || 'PCE')}"></td>
        <td class="col-price"><input type="number" class="it-price" value="${item.harga ?? item.price ?? 0}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-disc"><input type="number" class="it-disc" value="${item.diskon ?? item.disc ?? 0}" step="0.01" oninput="calcItemRow(this)"></td>
        <td class="col-amount"><input type="number" class="it-amount" value="${item.total ?? item.amount ?? 0}" step="0.01" readonly></td>
        <td class="col-prno"><input type="text" class="it-prno" value="${escapeHtml(item.pr_no || '')}" placeholder="No PR"></td>
        <td class="col-wono"><input type="text" class="it-wono" value="${escapeHtml(item.wo_no || '')}" placeholder="No WO"></td>
        <td class="col-expdt"><input type="date" class="it-expdt" value="${(item.exp_dt || '').slice(0, 10)}"></td>
        <td class="col-note"><input type="text" class="it-note" value="${escapeHtml(item.note || '')}" placeholder="Catatan"></td>
        <td class="col-action" style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    calcItemRow(row.querySelector('.it-qty'));
    return row;
}

// Utility: Terbilang Bahasa Indonesia
function terbilang(n) {
    n = Math.floor(Math.abs(Number(n)));
    if (isNaN(n) || n === 0) return 'Nol';
    const huruf = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
    let hasil = '';
    if (n < 12) hasil = huruf[n];
    else if (n < 20) hasil = terbilang(n - 10) + ' Belas';
    else if (n < 100) hasil = terbilang(Math.floor(n / 10)) + ' Puluh ' + terbilang(n % 10);
    else if (n < 200) hasil = 'Seratus ' + terbilang(n - 100);
    else if (n < 1000) hasil = terbilang(Math.floor(n / 100)) + ' Ratus ' + terbilang(n % 100);
    else if (n < 2000) hasil = 'Seribu ' + terbilang(n - 1000);
    else if (n < 1000000) hasil = terbilang(Math.floor(n / 1000)) + ' Ribu ' + terbilang(n % 1000);
    else if (n < 1000000000) hasil = terbilang(Math.floor(n / 1000000)) + ' Juta ' + terbilang(n % 1000000);
    else if (n < 1000000000000) hasil = terbilang(Math.floor(n / 1000000000)) + ' Miliar ' + terbilang(n % 1000000000);
    else if (n < 1000000000000000) hasil = terbilang(Math.floor(n / 1000000000000)) + ' Triliun ' + terbilang(n % 1000000000000);
    return hasil.replace(/\s+/g, ' ').trim();
}

function formatNum(v) {
    const n = parseFloat(v);
    if (isNaN(n)) return '0,00';
    return n.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
<?= renderAuditLogModalHtml() ?>
<script>
<?= renderAuditLogJs('PO') ?>
</script>
</body>
</html>

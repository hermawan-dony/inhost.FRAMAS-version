<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Sales Return (SR / Retur Penjualan)
 * ============================================================================
 * Porting Lengkap dari PowerBuilder TPB-ERP:
 * - w_sr.srw (Window Sales Return / Retur Penjualan dari Pelanggan)
 * - bc_sr_hdr.srd / bc_sr_hdr2.srd (Header Sales Return & Integrasi Pabean CEISA)
 * - d_sr_dtl.srd (Rincian Item Barang Jadi / Komponen yang Diretur Pelanggan)
 * - d_sr_browse.srd (Daftar Dokumen Retur Penjualan, Status, dan Filter Periode)
 * - d_si_dtl_return.srd / w_popup_pilih_return.srw (Tarik Item dari Faktur Penjualan / SI)
 * - d_sr_bc_manual.srd (Integrasi Dokumen Pabean Pemasukan BC 2.7 / BC 4.0 / BC 4.1)
 *
 * Mengadopsi 100% Ant Design Compact Desktop 9.5px Identik dengan Sales Invoice (SI) & Purchase Return (PRT)
 * Standar Warna Badge & Accent: Rose / Crimson / Wine (#e11d48 / #be123c)
 * ============================================================================
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

// -------------------------------------------------------------------------
// SESSION & MULTI-TENANT CONTEXT
// -------------------------------------------------------------------------
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (!empty($_GET['cmp_id'])) {
    $sessionCmpId = trim($_GET['cmp_id']);
    $_SESSION['company_cmp_id'] = $sessionCmpId;
    $_SESSION['cmp_id'] = $sessionCmpId;
} elseif (!empty($_POST['cmp_id'])) {
    $sessionCmpId = trim($_POST['cmp_id']);
    $_SESSION['company_cmp_id'] = $sessionCmpId;
    $_SESSION['cmp_id'] = $sessionCmpId;
}
if (empty($sessionCmpId)) {
    $sessionCmpId = 'SNU';
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']) || !empty($_GET['is_admin']);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

$activeCmpName = $_SESSION['company_name'] ?? '';
$companyLogo   = '/assets/logo.png';
$companyAlamat = '';
$companyNpwp   = '';
$companyNib    = '';
$companyTelp   = '';

if (!empty($sessionCmpId)) {
    $cmpQ = $koneksi->query("SELECT cmp_name, logo, alamat, npwp, nib FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($cmpQ && $cRow = $cmpQ->fetch_assoc()) {
        if (!empty($cRow['cmp_name'])) $activeCmpName = $cRow['cmp_name'];
        if (!empty($cRow['logo'])) $companyLogo = $cRow['logo'];
        if (!empty($cRow['alamat'])) $companyAlamat = $cRow['alamat'];
        if (!empty($cRow['npwp'])) $companyNpwp = $cRow['npwp'];
        if (!empty($cRow['nib'])) $companyNib = $cRow['nib'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'TPB ERP Enterprise';

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_sr_hdr dan bc_sr_dtl Siap Multi-Tenant
// -------------------------------------------------------------------------
function ensureSrTables($koneksi) {
    try {
        // 1. Table bc_sr_hdr
        $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_sr_hdr'");
        if (!$checkHdr || $checkHdr->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_sr_hdr` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `sr_no` varchar(50) DEFAULT NULL,
                `sr_dt` date DEFAULT NULL,
                `ship_dt` date DEFAULT NULL,
                `fob` varchar(2) DEFAULT '1',
                `term` varchar(30) DEFAULT 'COD',
                `ship` varchar(50) DEFAULT '',
                `vendor` varchar(50) DEFAULT '',
                `vendoristaxable` varchar(1) DEFAULT 'N',
                `inclusivetax` varchar(1) DEFAULT 'N',
                `note` varchar(255) DEFAULT '',
                `subtotal` decimal(15,5) DEFAULT '0.00000',
                `disc_persen` decimal(5,2) DEFAULT '0.00',
                `disc_amt` decimal(13,2) DEFAULT '0.00',
                `ppn` decimal(18,8) DEFAULT '0.00000000',
                `est_freight` decimal(13,2) DEFAULT '0.00',
                `total_order` decimal(15,5) DEFAULT '0.00000',
                `dp` decimal(13,2) DEFAULT '0.00',
                `terbayar` decimal(13,2) DEFAULT '0.00',
                `retur` decimal(13,2) DEFAULT '0.00',
                `outstand` decimal(13,2) DEFAULT '0.00',
                `jatuhtempo` date DEFAULT NULL,
                `kurs` varchar(5) DEFAULT 'IDR',
                `rate` decimal(13,2) DEFAULT '1.00',
                `set_by` varchar(30) DEFAULT '',
                `status` varchar(1) DEFAULT '0',
                `kd_fp` varchar(5) DEFAULT '',
                `no_fp` varchar(30) DEFAULT '',
                `no_bc` varchar(20) DEFAULT '',
                `tgl_bc` date DEFAULT NULL,
                `no_aju` varchar(30) DEFAULT '',
                `bc` varchar(10) DEFAULT '',
                `si_no` varchar(100) DEFAULT '',
                `gudang` varchar(30) DEFAULT '',
                `kompensasi` varchar(30) DEFAULT 'POTONG_PIUTANG',
                `si_target` varchar(100) DEFAULT '',
                `alasan_retur` varchar(255) DEFAULT '',
                `is_lock` varchar(1) DEFAULT 'N',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_sr` (`cmp_id`, `sr_no`),
                KEY `idx_vendor` (`vendor`),
                KEY `idx_sr_dt` (`sr_dt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $res = $koneksi->query("SHOW COLUMNS FROM bc_sr_hdr");
            $cols = [];
            while ($r = $res->fetch_assoc()) $cols[$r['Field']] = true;
            $extraCols = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'jatuhtempo' => "date DEFAULT NULL",
                'kurs' => "varchar(5) DEFAULT 'IDR'",
                'rate' => "decimal(13,2) DEFAULT '1.00'",
                'kd_fp' => "varchar(5) DEFAULT ''",
                'no_fp' => "varchar(30) DEFAULT ''",
                'bc' => "varchar(10) DEFAULT ''",
                'no_bc' => "varchar(20) DEFAULT ''",
                'tgl_bc' => "date DEFAULT NULL",
                'no_aju' => "varchar(30) DEFAULT '',",
                'si_no' => "varchar(100) DEFAULT ''",
                'gudang' => "varchar(30) DEFAULT ''",
                'kompensasi' => "varchar(30) DEFAULT 'POTONG_PIUTANG'",
                'si_target' => "varchar(100) DEFAULT ''",
                'alasan_retur' => "varchar(255) DEFAULT ''",
                'is_lock' => "varchar(1) DEFAULT 'N'",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
            ];
            foreach ($extraCols as $col => $def) {
                if (!isset($cols[$col])) {
                    @$koneksi->query("ALTER TABLE bc_sr_hdr ADD COLUMN `$col` $def");
                }
            }
        }

        // 2. Table bc_sr_dtl
        $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_sr_dtl'");
        if (!$checkDtl || $checkDtl->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_sr_dtl` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `id_hdr` varchar(40) NOT NULL DEFAULT '',
                `seq` int(11) DEFAULT '1',
                `id_si` varchar(40) DEFAULT '',
                `si_no` varchar(50) DEFAULT '',
                `kode_barang` varchar(50) DEFAULT '',
                `uraian` varchar(255) DEFAULT '',
                `qty` decimal(15,4) DEFAULT '0.0000',
                `sat` varchar(10) DEFAULT '',
                `price` decimal(15,5) DEFAULT '0.00000',
                `disc` decimal(10,2) DEFAULT '0.00',
                `amount` decimal(15,5) DEFAULT '0.00000',
                `dept` varchar(30) DEFAULT '',
                `note` varchar(255) DEFAULT '',
                `cif` decimal(15,5) DEFAULT '0.00000',
                `cif_rupiah` decimal(15,5) DEFAULT '0.00000',
                `no_bc` varchar(20) DEFAULT '',
                `tgl_bc` date DEFAULT NULL,
                `no_aju` varchar(30) DEFAULT '',
                `bc` varchar(10) DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `idx_cmp_hdr` (`cmp_id`, `id_hdr`),
                KEY `idx_kode_barang` (`kode_barang`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $res = $koneksi->query("SHOW COLUMNS FROM bc_sr_dtl");
            $cols = [];
            while ($r = $res->fetch_assoc()) $cols[$r['Field']] = true;
            $extraCols = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'id_hdr' => "varchar(40) NOT NULL DEFAULT ''",
                'id_si' => "varchar(40) DEFAULT ''",
                'id_do' => "varchar(40) DEFAULT ''",
                'do_no' => "varchar(50) DEFAULT ''",
                'si_no' => "varchar(50) DEFAULT ''",
                'kode_barang' => "varchar(50) DEFAULT ''",
                'uraian' => "varchar(255) DEFAULT ''",
                'qty' => "decimal(15,4) DEFAULT '0.0000'",
                'sat' => "varchar(10) DEFAULT ''",
                'price' => "decimal(15,5) DEFAULT '0.00000'",
                'disc' => "decimal(10,2) DEFAULT '0.00'",
                'amount' => "decimal(15,5) DEFAULT '0.00000'",
                'note' => "varchar(255) DEFAULT ''",
                'dept' => "varchar(30) DEFAULT ''",
                'cif' => "decimal(15,5) DEFAULT '0.00000'",
                'cif_rupiah' => "decimal(15,5) DEFAULT '0.00000'",
                'no_bc' => "varchar(20) DEFAULT ''",
                'tgl_bc' => "date DEFAULT NULL",
                'no_aju' => "varchar(30) DEFAULT ''",
                'bc' => "varchar(10) DEFAULT ''"
            ];
            foreach ($extraCols as $col => $def) {
                if (!isset($cols[$col])) {
                    @$koneksi->query("ALTER TABLE bc_sr_dtl ADD COLUMN `$col` $def");
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("ensureSrTables Error: " . $e->getMessage());
    }
}
ensureSrTables($koneksi);

// -------------------------------------------------------------------------
// HELPER: Auto-generate Nomor Retur Penjualan (Format: SR-YYMM-XXXX)
// -------------------------------------------------------------------------
function generateSrNumber($koneksi, $cmpId) {
    $yymm = date('ym');
    $prefix = "SR-{$yymm}-";
    $escPrefix = $koneksi->real_escape_string($prefix);
    $escCmp = $koneksi->real_escape_string($cmpId);

    $sql = "SELECT sr_no FROM bc_sr_hdr 
            WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
              AND sr_no LIKE '{$escPrefix}%' 
            ORDER BY sr_no DESC LIMIT 1";
    $res = $koneksi->query($sql);
    $nextNum = 1;
    if ($res && $row = $res->fetch_assoc()) {
        $lastPart = substr($row['sr_no'], strlen($prefix));
        if (is_numeric($lastPart)) {
            $nextNum = (int)$lastPart + 1;
        }
    }
    return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
}

// =========================================================================
// BACKEND AJAX API HANDLER
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. DAFTAR BROWSE RETUR PENJUALAN (d_sr_browse.srd)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tglAwal  = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir = trim($_GET['tgl_akhir'] ?? '');
        $status   = trim($_GET['status'] ?? 'ALL');
        $customer = trim($_GET['customer'] ?? '');
        $search   = trim($_GET['search'] ?? '');
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $limit    = max(10, min(500, (int)($_GET['limit'] ?? 25)));
        $offset   = ($page - 1) * $limit;

        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "(a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)";
        }
        if (!empty($tglAwal)) {
            $escAwal = $koneksi->real_escape_string($tglAwal);
            $where[] = "a.sr_dt >= '{$escAwal}'";
        }
        if (!empty($tglAkhir)) {
            $escAkhir = $koneksi->real_escape_string($tglAkhir);
            $where[] = "a.sr_dt <= '{$escAkhir}'";
        }
        if ($status !== 'ALL' && $status !== '') {
            $escStatus = $koneksi->real_escape_string($status);
            $where[] = "a.status = '{$escStatus}'";
        }
        if (!empty($customer)) {
            $escC = $koneksi->real_escape_string($customer);
            $where[] = "(a.vendor = '{$escC}' OR b.NAMA LIKE '%{$escC}%' OR b.NAMA_PENDEK LIKE '%{$escC}%')";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(a.sr_no LIKE '%{$escS}%' OR a.si_no LIKE '%{$escS}%' OR a.no_bc LIKE '%{$escS}%' OR a.no_aju LIKE '%{$escS}%' OR a.note LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%')";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        // Total count
        $cntSql = "SELECT COUNT(*) AS total FROM bc_sr_hdr a 
                   LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                   {$whereSql}";
        $cntRes = $koneksi->query($cntSql);
        $totalRows = $cntRes ? (int)$cntRes->fetch_assoc()['total'] : 0;

        // KPI Calculations
        $curMonth = date('Y-m');
        $kpiSql = "SELECT 
                    COUNT(a.id) AS total_sr,
                    SUM(CASE WHEN a.status = '0' THEN 1 ELSE 0 END) AS draft_sr,
                    SUM(CASE WHEN a.sr_dt LIKE '{$curMonth}%' THEN a.total_order ELSE 0 END) AS total_nilai_month,
                    COALESCE((SELECT SUM(d.qty) FROM bc_sr_dtl d JOIN bc_sr_hdr h ON d.id_hdr = h.id 
                              WHERE (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)), 0) AS total_qty_retur
                   FROM bc_sr_hdr a
                   WHERE (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)";
        $kpiRes = $koneksi->query($kpiSql);
        $kpiData = $kpiRes ? $kpiRes->fetch_assoc() : [
            'total_sr' => 0, 'draft_sr' => 0, 'total_nilai_month' => 0, 'total_qty_retur' => 0
        ];

        // Main Query
        $sql = "SELECT a.*, 
                       COALESCE(b.NAMA, a.vendor) AS customer_name,
                       b.NAMA_PENDEK AS customer_short,
                       b.NPWP AS customer_npwp,
                       (SELECT COUNT(1) FROM bc_sr_dtl d WHERE d.id_hdr = a.id) AS total_items,
                       (SELECT SUM(d.qty) FROM bc_sr_dtl d WHERE d.id_hdr = a.id) AS total_qty,
                       (SELECT 1 FROM bc_1ab2 it WHERE it.ID_HEADER = a.id LIMIT 1) AS is_locked_it
                FROM bc_sr_hdr a
                LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY a.sr_dt DESC, a.sr_no DESC
                LIMIT {$offset}, {$limit}";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['sr_dt_fmt'] = !empty($r['sr_dt']) ? date('d/m/Y', strtotime($r['sr_dt'])) : '-';
                $r['subtotal_fmt'] = number_format((float)$r['subtotal'], 2, '.', ',');
                $r['total_order_fmt'] = number_format((float)$r['total_order'], 2, '.', ',');
                $r['total_qty_fmt'] = number_format((float)$r['total_qty'], 2, '.', ',');
                $rows[] = $r;
            }
        }

        echo json_encode([
            'status' => 'OK',
            'data'   => $rows,
            'total'  => $totalRows,
            'page'   => $page,
            'limit'  => $limit,
            'kpi'    => $kpiData
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 2. AMBIL DETAIL RETUR PENJUALAN (d_sr_dtl.srd & bc_sr_hdr.srd)
if ($action === 'get_sr') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        $sr_no = trim($_GET['sr_no'] ?? '');
        $escId = $koneksi->real_escape_string($id);
        $escSr = $koneksi->real_escape_string($sr_no);

        $whereClause = !empty($id) ? "a.id = '{$escId}'" : "a.sr_no = '{$escSr}'";

        $hdrSql = "SELECT a.*, 
                          COALESCE(b.NAMA, a.vendor) AS customer_name,
                          b.NAMA_PENDEK AS customer_short,
                          b.ALAMAT AS customer_alamat,
                          b.NPWP AS customer_npwp,
                          b.TELP AS customer_telp,
                          (SELECT 1 FROM bc_1ab2 it WHERE it.ID_HEADER = a.id LIMIT 1) AS is_locked_it
                   FROM bc_sr_hdr a
                   LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                   WHERE {$whereClause} AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)
                   LIMIT 1";
        $hdrRes = $koneksi->query($hdrSql);
        if (!$hdrRes || $hdrRes->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Dokumen Retur Penjualan tidak ditemukan.']);
            exit;
        }
        $header = $hdrRes->fetch_assoc();
        $realHdrId = $header['id'];

        $dtlSql = "SELECT d.* 
                   FROM bc_sr_dtl d
                   WHERE d.id_hdr = '{$realHdrId}'
                   ORDER BY d.seq ASC, d.id ASC";
        $dtlRes = $koneksi->query($dtlSql);
        $items = [];
        if ($dtlRes) {
            while ($d = $dtlRes->fetch_assoc()) {
                $items[] = $d;
            }
        }

        echo json_encode(['status' => 'OK', 'header' => $header, 'items' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 3. GENERATE NOMOR RETUR BARU (AUTO-INCREMENT FORMAT SR-YYMM-XXXX)
if ($action === 'generate_no') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $newNo = generateSrNumber($koneksi, $sessionCmpId);
        echo json_encode(['status' => 'OK', 'sr_no' => $newNo]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 4. LOOKUP FAKTUR PENJUALAN (SI) UNTUK DIRETUR (d_si_dtl_return.srd / w_popup_pilih_return.srw)
if ($action === 'lookup_si_for_return') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $customer = trim($_GET['customer'] ?? '');
        $search   = trim($_GET['search'] ?? '');
        $tglAwal  = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir = trim($_GET['tgl_akhir'] ?? '');

        $where = ["(h.status = '1' OR h.status = '2' OR h.status = '0')"];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        if (!empty($customer)) {
            $escC = $koneksi->real_escape_string($customer);
            $where[] = "h.vendor = '{$escC}'";
        }
        if (!empty($tglAwal)) {
            $escTa = $koneksi->real_escape_string($tglAwal);
            $where[] = "h.si_dt >= '{$escTa}'";
        }
        if (!empty($tglAkhir)) {
            $escTk = $koneksi->real_escape_string($tglAkhir);
            $where[] = "h.si_dt <= '{$escTk}'";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(h.si_no LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%')";
        }

        $whereSql = "WHERE " . implode(' AND ', $where);

        $sql = "SELECT d.id AS id_si_dtl, d.id_hdr AS id_si_hdr, d.seq, d.kode_barang, d.uraian,
                       d.qty AS qty_si, d.sat, d.price, d.disc, d.amount,
                       h.si_no, h.si_dt, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS customer_nama,
                       h.kurs, h.rate, h.term, h.bc, h.no_bc, h.tgl_bc, h.no_aju
                FROM bc_si_dtl d
                JOIN bc_si_hdr h ON d.id_hdr = h.id
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY h.si_dt DESC, h.si_no DESC, d.seq ASC
                LIMIT 150";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['qty_si_fmt'] = number_format((float)$r['qty_si'], 2, '.', ',');
                $r['price_fmt']  = number_format((float)$r['price'], 2, '.', ',');
                $r['amount_fmt'] = number_format((float)$r['amount'], 2, '.', ',');
                $r['si_dt_fmt']  = !empty($r['si_dt']) ? date('d/m/Y', strtotime($r['si_dt'])) : '-';
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 4B. LOOKUP SURAT JALAN / DELIVERY ORDER (DO) UNTUK DIRETUR
if ($action === 'lookup_do_outstanding') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $customer = trim($_GET['customer'] ?? '');
        $search   = trim($_GET['search'] ?? '');
        $tglAwal  = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir = trim($_GET['tgl_akhir'] ?? '');

        $where = ["(h.status = '1' OR h.status = '2' OR h.status = '0')"];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        if (!empty($customer)) {
            $escC = $koneksi->real_escape_string($customer);
            $where[] = "h.vendor = '{$escC}'";
        }
        if (!empty($tglAwal)) {
            $escTa = $koneksi->real_escape_string($tglAwal);
            $where[] = "h.do_dt >= '{$escTa}'";
        }
        if (!empty($tglAkhir)) {
            $escTk = $koneksi->real_escape_string($tglAkhir);
            $where[] = "h.do_dt <= '{$escTk}'";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(h.do_no LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%' OR d.so_no LIKE '%{$escS}%')";
        }

        $whereSql = "WHERE " . implode(' AND ', $where);

        $sql = "SELECT d.id AS id_do_dtl, d.id_hdr AS id_do_hdr, d.seq, d.kode_barang, d.uraian,
                       d.qty AS qty_do, d.sat, d.so_no, d.po_no, d.note,
                       COALESCE(s.price, m.UNIT_PRICE, 0) AS price,
                       h.do_no, h.do_dt, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS customer_nama,
                       h.bc_doc_type AS bc, h.nomor_daftar AS no_bc, h.tanggal_daftar AS tgl_bc, h.nomor_aju AS no_aju
                FROM bc_do_dtl d
                JOIN bc_do_hdr h ON d.id_hdr = h.id
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                LEFT JOIN bc_so_dtl s ON d.id_so = s.id
                LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                {$whereSql}
                ORDER BY h.do_dt DESC, h.do_no DESC, d.seq ASC
                LIMIT 150";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $qty = (float)$r['qty_do'];
                $prc = (float)$r['price'];
                $r['qty_do_fmt']  = number_format($qty, 2, '.', ',');
                $r['price_fmt']   = number_format($prc, 2, '.', ',');
                $r['amount_fmt']  = number_format($qty * $prc, 2, '.', ',');
                $r['do_dt_fmt']   = !empty($r['do_dt']) ? date('d/m/Y', strtotime($r['do_dt'])) : '-';
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 5. LOOKUP MASTER BARANG (bc_material)
if ($action === 'search_materials') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $q = trim($_GET['q'] ?? '');
        $klas = trim($_GET['klas'] ?? '');
        $escQ = $koneksi->real_escape_string($q);

        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)";
        }
        if (!empty($escQ)) {
            $where[] = "(KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%' OR POS_TARIF LIKE '%{$escQ}%')";
        }
        if (!empty($klas) && $klas !== 'ALL') {
            $escK = $koneksi->real_escape_string($klas);
            $where[] = "KLAS = '{$escK}'";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
        $sql = "SELECT KODE_BARANG AS kode_barang, URAIAN AS uraian, KODE_SATUAN AS sat, POS_TARIF AS hs_code, KLAS AS klasifikasi,
                       COALESCE(UNIT_PRICE, 0) AS price, 0 AS stok
                FROM bc_material 
                {$whereSql}
                ORDER BY KODE_BARANG ASC LIMIT 100";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['stok_fmt'] = number_format((float)$r['stok'], 2, '.', ',');
                $r['price_fmt'] = number_format((float)$r['price'], 2, '.', ',');
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 6. SIMPAN RETUR PENJUALAN (INSERT / UPDATE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Format payload JSON tidak valid']);
            exit;
        }

        $id          = trim($payload['id'] ?? '');
        $sr_no       = trim($payload['sr_no'] ?? '');
        $sr_dt       = trim($payload['sr_dt'] ?? date('Y-m-d'));
        $ship_dt     = trim($payload['ship_dt'] ?? '') ?: null;
        $jatuhtempo  = trim($payload['jatuhtempo'] ?? '') ?: null;
        $customer    = trim($payload['customer'] ?? '');
        $term        = trim($payload['term'] ?? 'COD');
        $kurs        = trim($payload['kurs'] ?? 'IDR') ?: 'IDR';
        $rate        = (float)($payload['rate'] ?? 1.00);
        if ($rate <= 0) $rate = 1.00;
        $note        = trim($payload['note'] ?? '');
        $status      = trim($payload['status'] ?? '0');
        $si_no       = trim($payload['si_no'] ?? '');
        $gudang      = trim($payload['gudang'] ?? 'UTAMA');
        $kompensasi  = trim($payload['kompensasi'] ?? 'POTONG_PIUTANG');
        $si_target   = trim($payload['si_target'] ?? '');
        $alasan_retur= trim($payload['alasan_retur'] ?? '');

        // Pabean
        $bc          = trim($payload['bc'] ?? '');
        $no_bc       = trim($payload['no_bc'] ?? '');
        $tgl_bc      = trim($payload['tgl_bc'] ?? '') ?: null;
        $no_aju      = trim($payload['no_aju'] ?? '');
        $kd_fp       = trim($payload['kd_fp'] ?? '');
        $no_fp       = trim($payload['no_fp'] ?? '');

        // Pajak & Diskon
        $vendoristaxable = trim($payload['vendoristaxable'] ?? 'N');
        $disc_persen = (float)($payload['disc_persen'] ?? 0);
        $disc_amt    = (float)($payload['disc_amt'] ?? 0);
        $est_freight = (float)($payload['est_freight'] ?? 0);

        $items       = $payload['items'] ?? [];

        if (empty($sr_no)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Retur Penjualan (SR) wajib diisi.']);
            exit;
        }
        if (empty($customer)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Pelanggan / Buyer wajib dipilih.']);
            exit;
        }
        if (empty($items) || !is_array($items)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Minimal satu baris rincian barang retur harus diisi.']);
            exit;
        }

        // Kalkulasi Keuangan Bersih (PowerBuilder PB logic)
        $subtotal = 0;
        foreach ($items as $it) {
            $q = (float)($it['qty'] ?? 0);
            $p = (float)($it['price'] ?? 0);
            $d = (float)($it['disc'] ?? 0);
            $lineAmt = ($q * $p) - (($q * $p) * ($d / 100));
            $subtotal += $lineAmt;
        }

        if ($disc_persen > 0) {
            $disc_amt = ($subtotal * $disc_persen) / 100;
        }
        $netto = max(0, $subtotal - $disc_amt);

        $ppn = 0;
        if ($vendoristaxable === 'Y') {
            $ppn = round($netto * 0.11, 2); // PPN 11%
        }
        $total_order = $netto + $ppn + $est_freight;

        $koneksi->begin_transaction();

        $isNew = false;
        if (empty($id)) {
            $isNew = true;
            $id = 'SR-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        $escId = $koneksi->real_escape_string($id);

        if ($isNew) {
            $stmt = $koneksi->prepare("INSERT INTO bc_sr_hdr (
                id, cmp_id, sr_no, sr_dt, ship_dt, jatuhtempo, vendor, term, kurs, rate,
                note, status, si_no, gudang, kompensasi, si_target, alasan_retur,
                bc, no_bc, tgl_bc, no_aju, kd_fp, no_fp, vendoristaxable,
                subtotal, disc_persen, disc_amt, ppn, est_freight, total_order, set_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssdsssssssssssssdddddds",
                $id, $sessionCmpId, $sr_no, $sr_dt, $ship_dt, $jatuhtempo, $customer, $term, $kurs, $rate,
                $note, $status, $si_no, $gudang, $kompensasi, $si_target, $alasan_retur,
                $bc, $no_bc, $tgl_bc, $no_aju, $kd_fp, $no_fp, $vendoristaxable,
                $subtotal, $disc_persen, $disc_amt, $ppn, $est_freight, $total_order, $currentUser
            );
            $stmt->execute();
        } else {
            // Check lock status
            $checkLock = $koneksi->query("SELECT is_lock, status FROM bc_sr_hdr WHERE id = '{$escId}' LIMIT 1");
            if ($checkLock && $lRow = $checkLock->fetch_assoc()) {
                if ($lRow['is_lock'] === 'Y') {
                    $koneksi->rollback();
                    echo json_encode(['status' => 'ERROR', 'message' => 'Transaksi sudah terkunci di IT Inventory. Tidak dapat diubah.']);
                    exit;
                }
            }

            $stmt = $koneksi->prepare("UPDATE bc_sr_hdr SET
                sr_no = ?, sr_dt = ?, ship_dt = ?, jatuhtempo = ?, vendor = ?, term = ?, kurs = ?, rate = ?,
                note = ?, status = ?, si_no = ?, gudang = ?, kompensasi = ?, si_target = ?, alasan_retur = ?,
                bc = ?, no_bc = ?, tgl_bc = ?, no_aju = ?, kd_fp = ?, no_fp = ?, vendoristaxable = ?,
                subtotal = ?, disc_persen = ?, disc_amt = ?, ppn = ?, est_freight = ?, total_order = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
            $stmt->bind_param("sssssssdsssssssssssssddddddss",
                $sr_no, $sr_dt, $ship_dt, $jatuhtempo, $customer, $term, $kurs, $rate,
                $note, $status, $si_no, $gudang, $kompensasi, $si_target, $alasan_retur,
                $bc, $no_bc, $tgl_bc, $no_aju, $kd_fp, $no_fp, $vendoristaxable,
                $subtotal, $disc_persen, $disc_amt, $ppn, $est_freight, $total_order,
                $id, $sessionCmpId
            );
            $stmt->execute();

            $koneksi->query("DELETE FROM bc_sr_dtl WHERE id_hdr = '{$escId}'");
        }

        // Insert Detail Items
        $dStmt = $koneksi->prepare("INSERT INTO bc_sr_dtl (
            id, cmp_id, id_hdr, seq, id_si, si_no, kode_barang, uraian,
            qty, sat, price, disc, amount, cif, cif_rupiah, dept, note,
            bc, no_bc, tgl_bc, no_aju
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        foreach ($items as $it) {
            $dtlId   = 'SRD-' . date('YmdHis') . '-' . $seq . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $id_si   = trim($it['id_si'] ?? '');
            $item_si = trim($it['si_no'] ?? $si_no);
            $kd      = trim($it['kode_barang'] ?? '');
            $uraian  = trim($it['uraian'] ?? '');
            $qty     = (float)($it['qty'] ?? 0);
            $sat     = trim($it['sat'] ?? 'PCE');
            $price   = (float)($it['price'] ?? 0);
            $disc    = (float)($it['disc'] ?? 0);
            $amount  = ($qty * $price) - (($qty * $price) * ($disc / 100));

            $cif     = $amount;
            $cif_rp  = ($kurs !== 'IDR') ? ($amount * $rate) : $amount;

            $dept    = trim($it['dept'] ?? '');
            $dNote   = trim($it['note'] ?? '');
            $itBc    = trim($it['bc'] ?? $bc);
            $itNoBc  = trim($it['no_bc'] ?? $no_bc);
            $itTglBc = trim($it['tgl_bc'] ?? '') ?: $tgl_bc;
            $itNoAju = trim($it['no_aju'] ?? $no_aju);

            if (empty($kd) && empty($uraian)) continue;

            $dStmt->bind_param("sssissssdsddddsssssss",
                $dtlId, $sessionCmpId, $id, $seq, $id_si, $item_si, $kd, $uraian,
                $qty, $sat, $price, $disc, $amount, $cif, $cif_rp, $dept, $dNote,
                $itBc, $itNoBc, $itTglBc, $itNoAju
            );
            $dStmt->execute();
            $seq++;
        }

        $koneksi->commit();
        echo json_encode([
            'status'  => 'OK',
            'message' => 'Retur Penjualan berhasil disimpan.',
            'id'      => $id,
            'sr_no'   => $sr_no
        ]);
    } catch (\Throwable $ex) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $ex->getMessage()]);
    }
    exit;
}

// 7. HAPUS RETUR PENJUALAN
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'ID Retur tidak valid.']);
            exit;
        }
        $escId = $koneksi->real_escape_string($id);

        $checkLock = $koneksi->query("SELECT sr_no, is_lock, status FROM bc_sr_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if (!$checkLock || $checkLock->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data Retur Penjualan tidak ditemukan.']);
            exit;
        }
        $row = $checkLock->fetch_assoc();
        if ($row['is_lock'] === 'Y') {
            echo json_encode(['status' => 'ERROR', 'message' => 'Sudah masuk IT Inventory. TIDAK BISA HAPUS!']);
            exit;
        }
        if ($row['status'] === '1' || $row['status'] === '2') {
            echo json_encode(['status' => 'ERROR', 'message' => 'Sales Return ' . $row['sr_no'] . ' sudah terposting/settled. Tidak bisa dihapus.']);
            exit;
        }

        $koneksi->begin_transaction();
        $koneksi->query("DELETE FROM bc_sr_dtl WHERE id_hdr = '{$escId}'");
        $koneksi->query("DELETE FROM bc_sr_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        $koneksi->commit();

        echo json_encode(['status' => 'OK', 'message' => 'Sales Return ' . $row['sr_no'] . ' berhasil dihapus.']);
    } catch (\Throwable $ex) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $ex->getMessage()]);
    }
    exit;
}

// 7B. POSTING PEMASUKAN RETUR KE IT INVENTORY (bc_1ab2, AB='A')
if ($action === 'transfer_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Retur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        // Ambil Header Retur
        $hRes = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as customer_nama, b.NPWP as customer_npwp 
                                 FROM bc_sr_hdr h 
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                 WHERE h.id = '{$escId}' LIMIT 1");
        if (!$hRes || $hRes->num_rows === 0) throw new Exception('Dokumen Retur Penjualan tidak ditemukan.');
        $h = $hRes->fetch_assoc();

        // Ambil Detail Barang
        $dRes = $koneksi->query("SELECT d.*, m.POS_TARIF as m_pos_tarif, COALESCE(m.KLAS, 'Barang Jadi') as klas 
                                 FROM bc_sr_dtl d 
                                 LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL)) 
                                 WHERE d.id_hdr = '{$escId}' 
                                 ORDER BY d.seq ASC");
        if (!$dRes || $dRes->num_rows === 0) throw new Exception('Rincian barang kosong. Simpan transaksi sebelum posting ke IT Inventory.');

        // Bersihkan posting lama jika ada
        $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");

        $dokumenPabean = !empty($h['bc']) ? $h['bc'] : 'NON-BC';
        $noDaftar      = !empty($h['no_bc']) ? $h['no_bc'] : '-';
        $tglDaftar     = !empty($h['tgl_bc']) ? $h['tgl_bc'] : $h['sr_dt'];
        $noAju         = !empty($h['no_aju']) ? $h['no_aju'] : '-';
        $noBukti       = $h['sr_no'];
        $tglBukti      = $h['sr_dt'];
        $namaBs        = $koneksi->real_escape_string($h['customer_nama']);
        $npwpBs        = $koneksi->real_escape_string($h['customer_npwp'] ?? '');
        $kurs          = !empty($h['kurs']) ? $h['kurs'] : 'IDR';
        $rate          = (float)$h['rate'] > 0 ? (float)$h['rate'] : 1;
        $ab            = 'A'; // 'A' = Pemasukan Kembali Barang Jadi Retur ke Pabrik

        $seq = 1;
        while ($d = $dRes->fetch_assoc()) {
            $kodeBrg   = $koneksi->real_escape_string($d['kode_barang']);
            $uraian    = $koneksi->real_escape_string($d['uraian']);
            $posTarif  = !empty($d['pos_tarif']) ? $d['pos_tarif'] : (!empty($d['m_pos_tarif']) ? $d['m_pos_tarif'] : '');
            $posTarif  = $koneksi->real_escape_string($posTarif);
            $klas      = $koneksi->real_escape_string($d['klas']);
            $unit      = !empty($d['sat']) ? $d['sat'] : 'PCE';
            $qty       = (float)$d['qty'];
            $nilai     = (float)$d['amount'];
            $cif       = $nilai * $rate;
            $gudang    = !empty($h['gudang']) ? $h['gudang'] : 'GUDANG JADI';
            $refSo     = !empty($d['so_no']) ? $d['so_no'] : (!empty($d['si_no']) ? $d['si_no'] : '');
            $refSj     = !empty($d['do_no']) ? $d['do_no'] : $noBukti;
            $refInv    = !empty($h['si_no']) ? $h['si_no'] : '';

            $sqlIns = "INSERT INTO bc_1ab2 (
                BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS,
                KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB,
                ID_HEADER, SJ, INVOICE, NOMOR_AJU, NPWP_BS, SERI_BARANG, CIF,
                GUDANG, NOMOR_PO, CRT_DT, CRT_USER, TGL_PROSES, cmp_id
            ) VALUES (
                '{$dokumenPabean}', '{$noDaftar}', '{$tglDaftar}', '{$noBukti}', '{$tglBukti}', '{$namaBs}',
                '{$kodeBrg}', '{$posTarif}', '{$uraian}', '{$klas}', '{$unit}', {$qty}, '{$kurs}', {$nilai}, '{$ab}',
                '{$escId}', '{$refSj}', '{$refInv}', '{$noAju}', '{$npwpBs}', {$seq}, {$cif},
                '{$gudang}', '{$refSo}', CURDATE(), '{$currentUser}', '{$h['sr_dt']}', '{$escCmp}'
            )";

            if (!$koneksi->query($sqlIns)) {
                throw new Exception('Gagal insert bc_1ab2: ' . $koneksi->error);
            }
            $seq++;
        }

        // Tandai is_lock di Header
        $koneksi->query("UPDATE bc_sr_hdr SET is_lock = 'Y', status = '1' WHERE id = '{$escId}'");

        echo json_encode(['status' => 'OK', 'message' => 'Berhasil memposting penerimaan retur penjualan ke IT Inventory (' . ($seq - 1) . ' item) dan dokumen dikunci.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 7C. BUKA KUNCI IT INVENTORY
if ($action === 'delete_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Retur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $del = $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
        if (!$del) throw new Exception('Gagal menghapus mutasi IT Inventory: ' . $koneksi->error);

        $koneksi->query("UPDATE bc_sr_hdr SET is_lock = 'N' WHERE id = '{$escId}'");

        echo json_encode(['status' => 'OK', 'message' => 'Kunci IT Inventory berhasil dibuka. Dokumen dapat diedit kembali.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 7D. BRIDGE OTOMATIS KE STAGING DOKUMEN BC CEISA 4.0 (bc_ceisa_header, barang, dokumen, kemasan, entitas)
if ($action === 'create_draft_bc') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id      = trim($_POST['id'] ?? '');
        $kodeBc  = trim($_POST['kode_dokumen'] ?? '27');
        $ndpbm   = floatval($_POST['ndpbm'] ?? 0);
        $valuta  = strtoupper(trim($_POST['valuta'] ?? 'IDR'));

        if (empty($id)) throw new Exception('ID Retur Penjualan belum tersimpan atau tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $cleanBc = preg_replace('/[^0-9]/', '', $kodeBc);
        if (empty($cleanBc)) $cleanBc = '27';
        $isLocalDoc = in_array($cleanBc, ['40', '41', '25', '261', '262', '613']);
        if ($isLocalDoc && ($valuta === 'USD' || empty($valuta))) {
            $valuta = 'IDR';
        }
        if ($valuta === 'IDR' && $ndpbm <= 0) {
            $ndpbm = 1.0;
        }
        if ($ndpbm <= 0) $ndpbm = 1.0;

        // 1. Ambil Header Retur
        $hRes = $koneksi->query("SELECT * FROM bc_sr_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if (!$hRes || $hRes->num_rows === 0) throw new Exception('Data Retur Penjualan tidak ditemukan.');
        $srHdr = $hRes->fetch_assoc();

        // 2. Ambil Detail Barang Retur
        $dRes = $koneksi->query("SELECT d.*, m.URAIAN AS mat_uraian, m.POS_TARIF, m.KODE_SATUAN, m.BERAT AS mat_netto 
                                FROM bc_sr_dtl d 
                                LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                                WHERE d.id_hdr = '{$escId}' 
                                ORDER BY d.seq ASC");
        $items = [];
        if ($dRes) while ($r = $dRes->fetch_assoc()) $items[] = $r;
        if (empty($items)) throw new Exception('Rincian barang retur masih kosong. Tambahkan minimal 1 barang sebelum membuat draft BC.');

        // 3. Profil Perusahaan (Penerima Barang)
        $comp = [];
        $qCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
        if ($qCmp && $rCmp = $qCmp->fetch_assoc()) $comp = $rCmp;

        // 4. Data Rekanan / Buyer (Pengirim Barang)
        $escVendor = $koneksi->real_escape_string($srHdr['vendor']);
        $vendor = [];
        $qV = $koneksi->query("SELECT * FROM bc_bs WHERE KODE = '{$escVendor}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if ($qV && $rV = $qV->fetch_assoc()) $vendor = $rV;

        // 5. Generate / Ensure Nomor AJU 26 Digit
        $cleanBc2 = substr(str_pad($cleanBc, 2, '0', STR_PAD_LEFT), 0, 2);
        $tglAju = !empty($srHdr['sr_dt']) ? $srHdr['sr_dt'] : date('Y-m-d');
        $ymd = date('Ymd', strtotime($tglAju));

        $nomorAju = trim($srHdr['no_aju'] ?? '');
        $isExistingValid = (strlen($nomorAju) === 26 && substr($nomorAju, 4, 2) === $cleanBc2);

        if (!$isExistingValid) {
            $a = !empty($comp['kode_kantor']) ? substr(preg_replace('/[^0-9]/', '', $comp['kode_kantor']), 0, 4) : '0000';
            $a = str_pad($a, 4, '0', STR_PAD_LEFT);
            $b = substr(preg_replace('/[^A-Za-z0-9]/', '', $sessionCmpId), 0, 6);
            $b = str_pad($b, 6, '0', STR_PAD_RIGHT);

            $qSet = $koneksi->query("SELECT * FROM bc_ceisa_setaju WHERE cmp_id = '{$escCmp}' AND (bc = '{$cleanBc}' OR bc = '{$cleanBc2}') ORDER BY IF(bc = '{$cleanBc}', 0, 1) LIMIT 1");
            $rSet = ($qSet && $qSet->num_rows > 0) ? $qSet->fetch_assoc() : null;

            if (!$rSet) {
                $todayPrefix = $a . $cleanBc2 . $b . $ymd;
                $qMax = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju LIKE '{$todayPrefix}%' ORDER BY nomor_aju DESC LIMIT 1");
                $lastSeq = 0;
                if ($qMax && $rMax = $qMax->fetch_assoc()) {
                    $lastSeq = intval(substr($rMax['nomor_aju'], -6));
                }
                $nextSeq = $lastSeq + 1;
                $koneksi->query("INSERT INTO bc_ceisa_setaju (cmp_id, bc, a, b, aju) VALUES ('{$escCmp}', '{$cleanBc}', '{$a}', '{$b}', {$nextSeq})");
            } else {
                $a = str_pad(substr($rSet['a'] ?? $a, 0, 4), 4, '0', STR_PAD_LEFT);
                $b = str_pad(substr($rSet['b'] ?? $b, 0, 6), 6, '0', STR_PAD_RIGHT);
                $nextSeq = intval($rSet['aju'] ?? 0) + 1;
                $koneksi->query("UPDATE bc_ceisa_setaju SET aju = {$nextSeq} WHERE cmp_id = '{$escCmp}' AND (bc = '{$cleanBc}' OR bc = '{$cleanBc2}')");
            }

            $nomorAju = sprintf('%04s%02s%06s%08s%06d', $a, $cleanBc2, $b, $ymd, $nextSeq);
        }

        $escAju = $koneksi->real_escape_string($nomorAju);

        // 6. Kalkulasi Nilai & Berat
        $totFob = 0;
        $totNetto = 0;
        $totBruto = 0;
        $totVol = 0;

        foreach ($items as $it) {
            $qty = (float)$it['qty'];
            $price = (float)$it['price'];
            $amt = (float)$it['amount'];
            if ($amt <= 0) $amt = $qty * $price;
            $totFob += $amt;

            $nPerUnit = (float)($it['mat_netto'] ?? 0);
            if ($nPerUnit <= 0) $nPerUnit = 1.0;
            $rowNetto = $qty * $nPerUnit;
            $rowBruto = $rowNetto * 1.05;

            $totNetto += $rowNetto;
            $totBruto += $rowBruto;
        }

        $totCif = $totFob;
        $totCifIdr = ($valuta === 'IDR') ? $totCif : ($totCif * $ndpbm);
        $totPenyerahan = $totCifIdr;

        $koneksi->begin_transaction();

        // 7. Insert / Update Header bc_ceisa_header
        $chkHead = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju = '{$escAju}' LIMIT 1");
        $kodeKantor = $comp['kode_kantor'] ?? '0000';
        $namaTtd    = $comp['nama_ttd'] ?? ($currentUser ?? 'Pimpinan');
        $jabatanTtd = $comp['jabatan_ttd'] ?? 'Kuasa Direksi';
        $kotaTtd    = $comp['kota_ttd'] ?? 'BEKASI';

        $kdTujuanKirim = in_array($cleanBc, ['40', '41', '25']) ? '1' : ($cleanBc === '27' ? '1' : '');
        $kdTujuanMasuk = ($cleanBc === '27') ? '1' : (($cleanBc === '40') ? '1' : '');

        if ($chkHead && $chkHead->num_rows > 0) {
            $koneksi->query("UPDATE bc_ceisa_header SET 
                kode_dokumen = '{$cleanBc}',
                kode_valuta = '{$valuta}',
                ndpbm = {$ndpbm},
                fob = {$totFob},
                cif = {$totCif},
                cif_rupiah = {$totCifIdr},
                harga_penyerahan = {$totPenyerahan},
                netto = {$totNetto},
                bruto = {$totBruto},
                volume = {$totVol},
                jumlah_barang = " . count($items) . ",
                tanggal_pernyataan = '{$tglAju}',
                tanggal_aju = '{$tglAju}'
                WHERE nomor_aju = '{$escAju}'");
        } else {
            $koneksi->query("INSERT INTO bc_ceisa_header (
                nomor_aju, cmp_id, kode_dokumen, kode_kantor, kode_valuta, ndpbm,
                fob, cif, cif_rupiah, harga_penyerahan, netto, bruto, volume,
                jumlah_barang, tanggal_pernyataan, tanggal_aju,
                kode_tujuan_pengiriman, kode_tujuan_pemasukan,
                nama_pernyataan, jabatan_pernyataan, kota_pernyataan,
                created_at
            ) VALUES (
                '{$escAju}', '{$escCmp}', '{$cleanBc}', '{$kodeKantor}', '{$valuta}', {$ndpbm},
                {$totFob}, {$totCif}, {$totCifIdr}, {$totPenyerahan}, {$totNetto}, {$totBruto}, {$totVol},
                " . count($items) . ", '{$tglAju}', '{$tglAju}',
                '{$kdTujuanKirim}', '{$kdTujuanMasuk}',
                '{$namaTtd}', '{$jabatanTtd}', '{$kotaTtd}',
                NOW()
            )");
        }

        // 8. Insert Detail Barang bc_ceisa_barang
        $koneksi->query("DELETE FROM bc_ceisa_barang WHERE nomor_aju = '{$escAju}'");
        $bStmt = $koneksi->prepare("INSERT INTO bc_ceisa_barang (
            nomor_aju, seri_barang, kode_barang, uraian, hs,
            kode_satuan, jumlah_satuan, kode_kemasan, jumlah_kemasan,
            netto, bruto, volume, ndpbm, harga_satuan, fob, cif, cif_rupiah,
            harga_penyerahan, harga_perolehan, kode_asal_barang
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seri = 1;
        foreach ($items as $it) {
            $kdBrg   = trim($it['kode_barang']);
            $uraian  = !empty($it['uraian']) ? trim($it['uraian']) : (!empty($it['mat_uraian']) ? trim($it['mat_uraian']) : $kdBrg);
            $hsRaw   = !empty($it['pos_tarif']) ? $it['pos_tarif'] : ($it['POS_TARIF'] ?? '');
            $hsCode  = preg_replace('/[^0-9]/', '', $hsRaw);
            $satuan  = !empty($it['sat']) ? trim($it['sat']) : (!empty($it['KODE_SATUAN']) ? trim($it['KODE_SATUAN']) : 'PCE');
            $qty     = (float)$it['qty'];
            $hrgSat  = (float)$it['price'];
            $amt     = (float)$it['amount'];
            if ($amt <= 0) $amt = $qty * $hrgSat;
            $amtIdr  = ($valuta === 'IDR') ? $amt : ($amt * $ndpbm);

            $nPerUnit = (float)($it['mat_netto'] ?? 0);
            if ($nPerUnit <= 0) $nPerUnit = 1.0;
            $bNetto  = $qty * $nPerUnit;
            $bBruto  = $bNetto * 1.05;
            $bVol    = 0;
            $kms     = 'PK';
            $jmlKms  = 1;
            $asalBrg = '1';
            $hrgPerolehan = 0;

            $bStmt->bind_param("sissssdsddddddddddds",
                $escAju, $seri, $kdBrg, $uraian, $hsCode,
                $satuan, $qty, $kms, $jmlKms,
                $bNetto, $bBruto, $bVol, $ndpbm, $hrgSat, $amt, $amt, $amtIdr,
                $amtIdr, $hrgPerolehan, $asalBrg
            );
            $bStmt->execute();
            $seri++;
        }
        $bStmt->close();

        // 9. Insert Dokumen Pelengkap bc_ceisa_dokumen
        $koneksi->query("DELETE FROM bc_ceisa_dokumen WHERE nomor_aju = '{$escAju}'");
        $dSeri = 1;
        $srNo  = trim($srHdr['sr_no'] ?? '');
        $srDt  = !empty($srHdr['sr_dt']) ? $srHdr['sr_dt'] : $tglAju;

        if (!empty($srNo)) {
            $escSrNo = $koneksi->real_escape_string($srNo);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('{$escAju}', {$dSeri}, '380', '{$escSrNo}', '{$srDt}')");
            $dSeri++;
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('{$escAju}', {$dSeri}, '217', '{$escSrNo}', '{$srDt}')");
            $dSeri++;
        }

        if (!empty($srHdr['si_no'])) {
            $escSi = $koneksi->real_escape_string($srHdr['si_no']);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('{$escAju}', {$dSeri}, '380', '{$escSi}', '{$srDt}')");
            $dSeri++;
        }

        // 10. Insert Kemasan bc_ceisa_kemasan
        $koneksi->query("DELETE FROM bc_ceisa_kemasan WHERE nomor_aju = '{$escAju}'");
        $vName = !empty($vendor['NAMA']) ? $vendor['NAMA'] : ($srHdr['vendor'] ?? 'STANDARD PACK');
        $escMerek = $koneksi->real_escape_string(substr($vName, 0, 30));
        $totalPack = count($items);
        $koneksi->query("INSERT INTO bc_ceisa_kemasan (cmp_id, nomor_aju, seri, kode_kemasan, jumlah_kemasan, merek) 
                         VALUES ('{$escCmp}', '{$escAju}', 1, 'PK', {$totalPack}, '{$escMerek}')");

        // 11. Insert Pengangkut bc_ceisa_pengangkut
        $koneksi->query("DELETE FROM bc_ceisa_pengangkut WHERE nomor_aju = '{$escAju}'");
        $koneksi->query("INSERT INTO bc_ceisa_pengangkut (cmp_id, nomor_aju, seri, kode_cara_angkut) 
                         VALUES ('{$escCmp}', '{$escAju}', 1, '3')");

        // 12. Insert Entitas bc_ceisa_entitas
        $koneksi->query("DELETE FROM bc_ceisa_entitas WHERE nomor_aju = '{$escAju}'");
        
        $npwpComp   = $koneksi->real_escape_string($comp['npwp'] ?? '');
        $npwp16Comp = !empty($comp['npwp16']) ? trim($comp['npwp16']) : (!empty($npwpComp) ? str_pad($npwpComp, 16, '0', STR_PAD_LEFT) : '');
        $nitkuComp  = !empty($comp['nitku']) ? trim($comp['nitku']) : (!empty($npwp16Comp) ? $npwp16Comp . '000000' : '');
        $namaComp   = $koneksi->real_escape_string($comp['cmp_name'] ?? '');
        $alamatComp = $koneksi->real_escape_string($comp['alamat'] ?? '');
        $nibComp    = $koneksi->real_escape_string($comp['nib'] ?? '');
        $skepNoComp = $koneksi->real_escape_string($comp['nomor_skep'] ?? '');
        $skepDtComp = !empty($comp['tanggal_skep']) ? "'" . $comp['tanggal_skep'] . "'" : "NULL";

        $vNpwp   = $koneksi->real_escape_string(preg_replace('/[^0-9]/', '', $vendor['NPWP'] ?? ''));
        $vNpwp16 = (strlen($vNpwp) === 15) ? '0' . $vNpwp : $vNpwp;
        $vNitku  = (strlen($vNpwp16) === 16) ? $vNpwp16 . '000000' : $vNpwp;
        $vNama   = $koneksi->real_escape_string($vendor['NAMA'] ?? $srHdr['vendor']);
        $vAlamat = $koneksi->real_escape_string($vendor['ALAMAT'] ?? '');
        $vNegara = $koneksi->real_escape_string($vendor['NEGARA'] ?? 'ID');

        // Untuk Dokumen Pemasukan:
        // Entitas 1 (kode='3'): Pengusaha TPB / Penerima Barang (Perusahaan Kita)
        // Entitas 2 (kode='7'): Pemilik Barang (Perusahaan Kita)
        // Entitas 3 (kode='9'): Pengirim Barang (Pembeli / Rekanan Asal Retur)
        $entitasList = [];
        $entitasList[] = ['seri' => 1, 'kode' => '3', 'npwp' => (!empty($nitkuComp) ? $nitkuComp : $npwp16Comp), 'nama' => $namaComp, 'alamat' => $alamatComp, 'nib' => $nibComp, 'negara' => 'ID', 'no_ijin' => $skepNoComp, 'tgl_ijin' => $skepDtComp];
        $entitasList[] = ['seri' => 2, 'kode' => '7', 'npwp' => (!empty($nitkuComp) ? $nitkuComp : $npwp16Comp), 'nama' => $namaComp, 'alamat' => $alamatComp, 'nib' => $nibComp, 'negara' => 'ID', 'no_ijin' => '', 'tgl_ijin' => 'NULL'];
        $entitasList[] = ['seri' => 3, 'kode' => '9', 'npwp' => $vNitku, 'nama' => $vNama, 'alamat' => $vAlamat, 'nib' => '', 'negara' => $vNegara, 'no_ijin' => '', 'tgl_ijin' => 'NULL'];

        foreach ($entitasList as $e) {
            $ijinVal = ($e['tgl_ijin'] !== 'NULL') ? $e['tgl_ijin'] : "NULL";
            $noIjinVal = !empty($e['no_ijin']) ? "'" . $e['no_ijin'] . "'" : "NULL";
            $koneksi->query("INSERT INTO bc_ceisa_entitas (
                cmp_id, nomor_aju, seri, kode_entitas, kode_jenis_identitas,
                nomor_identitas, nama_entitas, alamat_entitas, nib_entitas, kode_negara,
                nomor_ijin_entitas, tanggal_ijin_entitas, kode_jenis_api
            ) VALUES (
                '{$escCmp}', '{$escAju}', {$e['seri']}, '{$e['kode']}', '6',
                '{$e['npwp']}', '{$e['nama']}', '{$e['alamat']}', '{$e['nib']}', '{$e['negara']}',
                {$noIjinVal}, {$ijinVal}, '02'
            )");
        }

        // 13. Update Header Retur Penjualan
        $koneksi->query("UPDATE bc_sr_hdr SET no_aju = '{$escAju}', bc = '{$cleanBc}' WHERE id = '{$escId}'");

        $koneksi->commit();

        echo json_encode([
            'status'       => 'OK',
            'nomor_aju'    => $nomorAju,
            'kode_dokumen' => $cleanBc,
            'editor_url'   => '/editor.php?nomor_aju=' . urlencode($nomorAju),
            'message'      => "Draft Dokumen BC {$cleanBc} (AJU: {$nomorAju}) berhasil dibuat di tabel staging CEISA 4.0!"
        ]);
    } catch (\Throwable $ex) {
        if ($koneksi) $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 8. TRACEABILITY RETUR PENJUALAN (SI ↔ SR ↔ AR / Pembukuan ↔ CEISA)
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $search = trim($_GET['search'] ?? '');
        if (empty($search)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Masukkan No SR atau No SI untuk ditelusuri.']);
            exit;
        }
        $escS = $koneksi->real_escape_string($search);

        // Find SR Header
        $srQ = $koneksi->query("SELECT a.*, COALESCE(b.NAMA, a.vendor) AS customer_name 
                                FROM bc_sr_hdr a 
                                LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                WHERE (a.sr_no LIKE '%{$escS}%' OR a.si_no LIKE '%{$escS}%' OR a.id = '{$escS}')
                                  AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)
                                LIMIT 1");
        if (!$srQ || $srQ->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data tidak ditemukan dalam jejak transaksi Retur Penjualan.']);
            exit;
        }
        $sr = $srQ->fetch_assoc();
        $srId = $sr['id'];

        // Detail SR
        $dtlQ = $koneksi->query("SELECT * FROM bc_sr_dtl WHERE id_hdr = '{$srId}' ORDER BY seq ASC");
        $items = [];
        if ($dtlQ) while ($d = $dtlQ->fetch_assoc()) $items[] = $d;

        // Linked SI (Sales Invoice)
        $siNo = $sr['si_no'];
        $linkedSi = null;
        if (!empty($siNo)) {
            $escSi = $koneksi->real_escape_string($siNo);
            $siQ = $koneksi->query("SELECT id, si_no, si_dt, total_order, terbayar, status, bc, no_bc, tgl_bc, no_aju 
                                   FROM bc_si_hdr WHERE (si_no = '{$escSi}' OR id = '{$escSi}') LIMIT 1");
            if ($siQ && $siQ->num_rows > 0) $linkedSi = $siQ->fetch_assoc();
        }

        echo json_encode([
            'status' => 'OK',
            'sr'     => $sr,
            'items'  => $items,
            'si'     => $linkedSi
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 9. PRINT DOKUMEN RESMI A4 (NOTA RETUR PENJUALAN / CREDIT MEMO)
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrQ = $koneksi->query("SELECT a.*, 
                                    COALESCE(b.NAMA, a.vendor) AS customer_name,
                                    b.ALAMAT AS customer_alamat,
                                    b.NPWP AS customer_npwp,
                                    b.TELP AS customer_telp
                             FROM bc_sr_hdr a
                             LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                             WHERE a.id = '{$escId}' LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) die("Dokumen Retur Penjualan tidak ditemukan.");
    $h = $hdrQ->fetch_assoc();

    $dtlQ = $koneksi->query("SELECT * FROM bc_sr_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $items = [];
    if ($dtlQ) while ($r = $dtlQ->fetch_assoc()) $items[] = $r;
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Nota Retur Penjualan - <?= htmlspecialchars($h['sr_no']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 12mm 15mm; }
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 10px; color: #111; line-height: 1.4; margin: 0; padding: 0; }
            .print-btn { position: fixed; top: 10px; right: 10px; padding: 6px 14px; background: #e11d48; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
            @media print { .print-btn { display: none; } }
            .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .hdr-table td { vertical-align: top; }
            .logo-img { max-height: 48px; max-width: 130px; object-fit: contain; }
            .title-box { text-align: center; border-top: 2px solid #e11d48; border-bottom: 2px solid #e11d48; padding: 6px 0; margin-bottom: 12px; }
            .title-box h2 { margin: 0; font-size: 15px; color: #e11d48; letter-spacing: 0.5px; text-transform: uppercase; }
            .title-box small { color: #555; font-size: 9px; }
            .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 9.5px; }
            .info-grid td { padding: 3px 6px; }
            .info-box { border: 1px solid #ccc; border-radius: 4px; padding: 6px 8px; background: #fafafa; }
            table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
            table.data-table th, table.data-table td { border: 1px solid #777; padding: 4px 6px; font-size: 9.5px; }
            table.data-table th { background: #f5f5f5; text-align: center; font-weight: 600; }
            .tot-table { width: 45%; margin-left: auto; border-collapse: collapse; margin-bottom: 14px; }
            .tot-table td { padding: 2px 6px; font-size: 9.5px; }
            .tot-table .grand { font-weight: 700; font-size: 11px; border-top: 1px solid #333; border-bottom: 2px solid #333; }
            .sign-grid { width: 100%; border-collapse: collapse; margin-top: 25px; page-break-inside: avoid; }
            .sign-grid td { width: 25%; text-align: center; border: 1px solid #aaa; padding: 4px; font-size: 9px; }
            .sign-space { height: 50px; }
            .badge-pabean { display: inline-block; padding: 2px 6px; background: #ffe4e6; color: #9f1239; border-radius: 3px; font-weight: 600; font-size: 9px; }
        </style>
    </head>
    <body>
        <button class="print-btn" onclick="window.print()">🖨️ Cetak Dokumen</button>

        <table class="hdr-table">
            <tr>
                <td style="width: 140px;">
                    <img src="<?= htmlspecialchars($companyLogo) ?>" class="logo-img" alt="Logo">
                </td>
                <td>
                    <strong style="font-size: 13px;"><?= htmlspecialchars($activeCmpName) ?></strong><br>
                    <?= htmlspecialchars($companyAlamat) ?><br>
                    <small>NPWP: <?= htmlspecialchars($companyNpwp) ?></small>
                </td>
                <td style="text-align: right; width: 220px;">
                    <div style="font-size: 14px; font-weight: 700; color: #e11d48;"><?= htmlspecialchars($h['sr_no']) ?></div>
                    <small>Tanggal: <?= !empty($h['sr_dt']) ? date('d F Y', strtotime($h['sr_dt'])) : '-' ?></small><br>
                    <?php if (!empty($h['bc'])): ?>
                        <span class="badge-pabean">Dokumen Pabean: <?= htmlspecialchars($h['bc']) ?> No. <?= htmlspecialchars($h['no_bc']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div class="title-box">
            <h2>NOTA RETUR PENJUALAN (SALES RETURN / CREDIT MEMO)</h2>
            <small>Surat Bukti Penerimaan Kembali Barang dari Pembeli / Pemotongan Piutang Usaha</small>
        </div>

        <table class="info-grid">
            <tr>
                <td style="width: 50%;">
                    <div class="info-box">
                        <strong>DARI PELANGGAN / BUYER:</strong><br>
                        <b style="font-size: 11px;"><?= htmlspecialchars($h['customer_name']) ?></b> (<?= htmlspecialchars($h['vendor']) ?>)<br>
                        <?= htmlspecialchars($h['customer_alamat'] ?? '-') ?><br>
                        NPWP: <?= htmlspecialchars($h['customer_npwp'] ?? '-') ?> | Telp: <?= htmlspecialchars($h['customer_telp'] ?? '-') ?>
                    </div>
                </td>
                <td style="width: 50%;">
                    <div class="info-box">
                        <strong>INFORMASI TRANSAKSI RETUR:</strong><br>
                        Mata Uang / Kurs: <b><?= htmlspecialchars($h['kurs']) ?></b> (Rate: <?= number_format((float)$h['rate'], 2) ?>)<br>
                        Ref. Faktur Jual (SI): <b><?= htmlspecialchars($h['si_no'] ?: '-') ?></b><br>
                        Kompensasi: <b><?= htmlspecialchars(str_replace('_', ' ', $h['kompensasi'] ?? 'POTONG PIUTANG')) ?></b><br>
                        Gudang Penerima: <b><?= htmlspecialchars($h['gudang'] ?: 'Gudang Utama') ?></b><br>
                        No. Aju Pabean: <?= htmlspecialchars($h['no_aju'] ?: '-') ?>
                    </div>
                </td>
            </tr>
        </table>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">NO</th>
                    <th style="width: 120px;">KODE BARANG</th>
                    <th>URAIAN / DESKRIPSI BARANG</th>
                    <th style="width: 55px;">SAT</th>
                    <th style="width: 75px;">QTY RETUR</th>
                    <th style="width: 90px;">HARGA SATUAN</th>
                    <th style="width: 60px;">DISC (%)</th>
                    <th style="width: 100px;">TOTAL NILAI</th>
                    <th style="width: 140px;">ALASAN / CATATAN</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                foreach ($items as $it): 
                ?>
                <tr>
                    <td style="text-align: center;"><?= $no++ ?></td>
                    <td><b><?= htmlspecialchars($it['kode_barang']) ?></b></td>
                    <td><?= htmlspecialchars($it['uraian']) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($it['sat']) ?></td>
                    <td style="text-align: right; font-weight: 600;"><?= number_format((float)$it['qty'], 2) ?></td>
                    <td style="text-align: right;"><?= number_format((float)$it['price'], 2) ?></td>
                    <td style="text-align: right;"><?= number_format((float)$it['disc'], 2) ?></td>
                    <td style="text-align: right; font-weight: 600;"><?= number_format((float)$it['amount'], 2) ?></td>
                    <td><?= htmlspecialchars($it['note']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table style="width: 100%;">
            <tr>
                <td style="vertical-align: top; width: 55%;">
                    <div style="border: 1px dashed #888; padding: 6px 10px; border-radius: 4px; font-size: 9px;">
                        <strong>Catatan / Alasan Utama Retur:</strong><br>
                        <?= nl2br(htmlspecialchars($h['note'] ?: ($h['alasan_retur'] ?: 'Penerimaan kembali barang rusak / tidak sesuai pesanan pembeli.'))) ?>
                    </div>
                </td>
                <td style="vertical-align: top; width: 45%;">
                    <table class="tot-table">
                        <tr>
                            <td>Subtotal Retur:</td>
                            <td style="text-align: right;"><?= number_format((float)$h['subtotal'], 2) ?></td>
                        </tr>
                        <?php if ((float)$h['disc_amt'] > 0): ?>
                        <tr>
                            <td>Diskon:</td>
                            <td style="text-align: right;">-<?= number_format((float)$h['disc_amt'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ((float)$h['ppn'] > 0): ?>
                        <tr>
                            <td>PPN Retur (11%):</td>
                            <td style="text-align: right;"><?= number_format((float)$h['ppn'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="grand">
                            <td>TOTAL NILAI RETUR:</td>
                            <td style="text-align: right; color: #e11d48;"><?= number_format((float)$h['total_order'], 2) ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="sign-grid">
            <tr>
                <td><strong>Diterima Gudang</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>Sales / Marketing</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>Finance / Accounting</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>Pelanggan / Pengemudi</strong><div class="sign-space"></div>( .................................. )</td>
            </tr>
        </table>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------------------
// DATA REFERENSI UNTUK FORM UI
// -------------------------------------------------------------------------
$bsQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, PAY_TERM AS TOP, kurs AS CURRENCY 
                       FROM bc_bs 
                       WHERE (TIPE = 'C' OR TIPE = 'B' OR TIPE = 'CUSTOMER' OR TIPE = 'BUYER' OR TIPE IS NULL OR TIPE = '' OR TIPE = 'BC') 
                         AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                       ORDER BY NAMA ASC");
$customers = [];
if ($bsQ) {
    while ($r = $bsQ->fetch_assoc()) $customers[] = $r;
}

$gudangList = ['UTAMA', 'GUDANG JADI', 'GUDANG RETUR', 'WIP', 'BAHAN BAKU'];
try {
    $gdQ = @$koneksi->query("SELECT kode, nama FROM bc_gudang WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY kode ASC");
    if ($gdQ && $gdQ->num_rows > 0) {
        $gudangList = [];
        while ($r = $gdQ->fetch_assoc()) $gudangList[] = $r['kode'];
    }
} catch (\Throwable $e) {}

$kursList = ['IDR', 'USD', 'EUR', 'JPY', 'SGD', 'CNY', 'GBP'];
try {
    $krsQ = @$koneksi->query("SELECT DISTINCT kurs FROM erp_kurs WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY kurs ASC");
    if ($krsQ && $krsQ->num_rows > 0) {
        $kursList = [];
        while ($r = $krsQ->fetch_assoc()) if (!empty($r['kurs'])) $kursList[] = $r['kurs'];
    }
} catch (\Throwable $e) {}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Return - <?= htmlspecialchars($activeCmpName) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_searchable_select.css?v=20261101">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/assets/ant_searchable_select.js?v=20261101"></script>
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
    </script>
    <style>
        :root, html[data-theme='light'], body[data-theme='light'] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --border-color: #d9d9d9;
            --border-light: #e8e8e8;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --primary: #e11d48;          /* Rose / Crimson Khas Sales Return */
            --primary-hover: #be123c;
            --primary-light: #fff1f2;
            --primary-focus: rgba(225, 29, 72, 0.25);
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-hover: #fff1f2;
            --table-head-bg: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --row-selected: #ffe4e6;
            --row-selected-text: #9f1239;
            color-scheme: light;
        }
        body.dark-mode, [data-theme='dark'], html[data-theme='dark'], body[data-theme='dark'], html.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --border-light: #424242;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #f43f5e;
            --primary-hover: #fb7185;
            --primary-light: #2a1215;
            --primary-focus: rgba(225, 29, 72, 0.35);
            --success: #49aa19;
            --warning: #d89614;
            --danger: #ff4d4f;
            --table-hover: #261217;
            --table-head-bg: #1d1d1d;
            --bg-input: #141414;
            --border-input: #434343;
            --row-selected: #3f121d;
            --row-selected-text: #fda4af;
            color-scheme: dark !important;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 10px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }
        button, input, select, textarea {
            font-family: inherit;
            font-size: 9.5px;
            font-weight: 500;
            color: inherit;
        }
        
        /* Spinner remove */
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none !important;
            margin: 0 !important;
        }
        input[type="number"] {
            -moz-appearance: textfield !important;
            appearance: textfield !important;
        }

        .ant-container { height: 100%; display: flex; flex-direction: column; padding: 6px; gap: 0; }
        
        /* Top Card Head */
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .ant-card-head {
            min-height: 34px;
            padding: 4px 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px;
            background: var(--bg-card);
        }
        .ant-card-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ant-badge {
            font-size: 9px;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 600;
            line-height: 1.2;
            text-transform: uppercase;
        }
        .ant-badge-company {
            background: rgba(225, 29, 72, 0.12);
            color: var(--primary);
            border: 1px solid rgba(225, 29, 72, 0.3);
        }
        .ant-badge-belum {
            background: rgba(250, 173, 20, 0.12);
            color: var(--warning);
            border: 1px solid rgba(250, 173, 20, 0.3);
        }
        .ant-badge-lunas {
            background: rgba(82, 196, 26, 0.12);
            color: var(--success);
            border: 1px solid rgba(82, 196, 26, 0.3);
        }
        .ant-badge-lock {
            background: rgba(255, 77, 79, 0.12);
            color: var(--danger);
            border: 1px solid rgba(255, 77, 79, 0.3);
        }

        /* Tabs */
        .ant-tabs-nav {
            display: flex;
            gap: 2px;
            padding: 0 10px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
        }
        .ant-tab-btn {
            border: none;
            border-bottom: 2px solid transparent;
            background: transparent;
            color: var(--text-muted);
            padding: 5px 12px;
            cursor: pointer;
            font-size: 10px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
        }

        /* Toolbar Buttons */
        .ant-toolbar {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
            padding: 6px 10px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-card);
        }
        .ant-btn {
            font-family: inherit;
            font-size: 9.5px;
            height: 21px;
            padding: 0 8px;
            border-radius: 2px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 500;
            line-height: 1.4;
            transition: all 0.15s;
        }
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
        #paneHdr { flex: 0 0 152px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        #paneFtr { flex: 0 0 auto; min-height: 114px; background: var(--bg-card); border-top: 1px solid var(--border-color); overflow: hidden; }
        
        /* Resizable Splitbar PB Style */
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
        .split-divider:hover, .split-divider.dragging {
            background: var(--primary-light);
            border-color: var(--primary);
        }
        .split-handle {
            width: 38px;
            height: 2px;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }
        .split-btn-collapse {
            position: absolute;
            height: 14px;
            padding: 0 5px;
            font-size: 8px;
            line-height: 12px;
            border-radius: 2px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            z-index: 2;
        }
        .split-btn-collapse:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
        .split-btn-collapse:first-child { right: 54px; }
        .split-btn-collapse:last-child { right: 6px; }

        /* Form Controls */
        .hdr-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 4px 18px;
            padding: 6px 12px;
            font-size: 9.5px;
        }
        .ftr-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 4px 18px;
            padding: 6px 12px;
            font-size: 9.5px;
            background: var(--bg-card);
        }
        .fld-row {
            display: flex;
            align-items: center;
            margin-bottom: 3px;
            gap: 6px;
        }
        .fld-row label {
            width: 86px;
            flex-shrink: 0;
            color: var(--text-muted);
            font-size: 9.5px;
            font-weight: 500;
        }
        .fld-row input, .fld-row select, .fld-row textarea,
        .browse-filter input, .browse-filter select {
            width: 100%;
            border: 1px solid var(--border-input);
            background: var(--bg-input);
            padding: 1px 6px;
            height: 21px;
            border-radius: 2px;
            outline: none;
            font-size: 9.5px;
        }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus,
        .browse-filter input:focus, .browse-filter select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-focus);
        }
        .inline-box { display: flex; gap: 4px; align-items: center; flex: 1; min-width: 0; width: 100%; }
        .pair-box { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 8px; }
        .checks-box { display: flex; flex-wrap: wrap; gap: 2px 10px; margin: 0 0 2px 86px; font-size: 9px; }
        .checks-box label { display: flex; gap: 4px; align-items: center; cursor: pointer; }

        /* IT Inv Lock Banner */
        .lock-alert {
            display: none;
            margin: 4px 10px;
            padding: 4px 10px;
            background: #fff1f0;
            color: #cf1322;
            border: 1px solid #ffa39e;
            border-radius: 3px;
            font-weight: 600;
            font-size: 10px;
            align-items: center;
            justify-content: space-between;
        }
        body.dark-mode .lock-alert { background: #2a1215; color: #ff7875; border-color: #5c2223; }

        /* Excel-like Data Table & Resizable Columns */
        .grid-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        table.dw {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            table-layout: auto;
            border: 1px solid var(--border-color);
        }
        table.dw thead { position: sticky; top: 0; z-index: 10; }
        table.dw th {
            position: relative;
            background: var(--table-head-bg);
            border: 1px solid var(--border-color);
            padding: 4px 10px 4px 6px;
            text-align: left;
            font-weight: 600;
            font-size: 9.5px;
            color: var(--text-muted);
            white-space: nowrap;
            user-select: none;
        }
        table.dw td {
            border: 1px solid var(--border-light);
            padding: 2px 6px;
            font-size: 9.5px;
            vertical-align: middle;
        }
        table.dw input {
            width: 100%;
            border: 1px solid transparent;
            background: transparent;
            padding: 1px 4px;
            height: 21px;
            border-radius: 2px;
            font-size: 9.5px;
        }
        table.dw input:hover { border-color: var(--border-input); }
        table.dw input:focus {
            border-color: var(--primary);
            background: var(--bg-input);
            box-shadow: 0 0 0 2px var(--primary-focus);
            outline: none;
        }
        table.dw input[type="number"] { text-align: right; }
        table.dw tbody tr:hover td { background: var(--table-hover); }
        table.dw tr.sel td {
            background: var(--row-selected) !important;
            color: var(--row-selected-text) !important;
        }

        .highlight-inv-no {
            background: #fffbe6 !important;
            border: 1px solid #ffe58f !important;
            color: #d46b08 !important;
            font-weight: 700 !important;
            box-shadow: 0 0 0 1px rgba(250, 173, 20, 0.2);
        }
        body.dark-mode .highlight-inv-no {
            background: #2b2111 !important;
            border-color: #594214 !important;
            color: #ffc069 !important;
        }

        .col-resizer {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            cursor: col-resize;
            user-select: none;
        }
        .col-resizer:hover, .col-resizer.resizing {
            background: var(--primary);
        }

        /* Terbilang Callout */
        .terbilang-callout {
            background: rgba(225, 29, 72, 0.04);
            border: 1px dashed rgba(225, 29, 72, 0.3);
            border-radius: 3px;
            padding: 4px 8px;
            font-size: 9px;
            display: flex;
            gap: 6px;
            align-items: flex-start;
            margin-top: 4px;
        }
        .lbl-say { font-weight: 700; color: var(--primary); flex-shrink: 0; }
        .txt-say { font-style: italic; color: var(--text-main); }

        /* Context Menu */
        .context-menu {
            position: fixed;
            z-index: 9999;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            min-width: 220px;
            padding: 4px 0;
            font-size: 9.5px;
        }
        .ctx-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 12px;
            cursor: pointer;
            color: var(--text-main);
            transition: background 0.15s ease;
        }
        .ctx-item:hover {
            background: var(--table-hover);
            color: var(--primary);
        }
        .ctx-item i { width: 14px; text-align: center; font-size: 11px; }
        .ctx-item span { flex: 1; }
        .ctx-item kbd {
            font-size: 8.5px;
            padding: 1px 4px;
            background: var(--table-head-bg);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            color: var(--text-muted);
        }
        .ctx-sep { height: 1px; background: var(--border-color); margin: 4px 0; }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-card {
            background: var(--bg-card);
            border-radius: 4px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
            width: 720px;
            max-width: 95vw;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        .modal-card.wide { width: 920px; }
        .modal-h {
            padding: 7px 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 600;
            background: var(--table-head-bg);
        }
        .modal-b { padding: 10px 12px; overflow: auto; flex: 1; }
        .modal-f {
            padding: 6px 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--table-head-bg);
        }

        /* KPI Strip */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            padding: 6px 10px;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-color);
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .kpi-icon {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        .browse-filter {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .muted { color: var(--text-muted); }
        .num { text-align: right; }
    </style>
</head>
<body class="<?= (!empty($_GET['theme']) && $_GET['theme'] === 'dark') ? 'dark-mode' : '' ?>">
<div class="ant-container">
    <div class="ant-card">
        <!-- Header Bar (Persis Sales Invoice) -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-arrow-rotate-left" style="color:var(--primary);"></i>
                <span>Sales Return (Retur Penjualan)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-belum" id="statusBadgeHeader">DRAFT</span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="resetForm()"><i class="fa-solid fa-plus"></i> Baru</button>
                <button type="button" class="ant-btn ant-btn-primary" data-lock="block" onclick="saveSr()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                <button type="button" class="ant-btn ant-btn-danger" data-lock="block" onclick="deleteCurrent()"><i class="fa-solid fa-trash"></i> Hapus</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="printSr()"><i class="fa-solid fa-print"></i> Cetak Nota Retur</button>
                <button type="button" class="ant-btn ant-btn-warning" id="btnCeisaDraft" onclick="openCeisaDraftModal()" title="Bridge/Sinkronisasi Draft ke Staging CEISA 4.0"><i class="fa-solid fa-rocket"></i> Buat Draft BC</button>
                <button type="button" class="ant-btn" id="btnOpenEditor" style="display:none;" onclick="openInCeisaEditor()" title="Buka Dokumen di Form Editor CEISA 4.0"><i class="fa-solid fa-arrow-up-right-from-square"></i> Buka di Form Editor</button>
                <button type="button" class="ant-btn" data-lock="block" onclick="openSiPickerModal()"><i class="fa-solid fa-file-invoice"></i> Tarik dari Faktur SI (F3)</button>
                <button type="button" class="ant-btn" data-lock="block" onclick="openDoPickerModal()"><i class="fa-solid fa-truck"></i> Tarik dari DO / SJ</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnTransferItInv" onclick="postToItInventory()"><i class="fa-solid fa-cube"></i> Posting IT Inv</button>
                <button type="button" class="ant-btn ant-btn-danger" id="btnDelItInv" style="display:none;" onclick="deleteFromItInventory()"><i class="fa-solid fa-unlock"></i> Buka Kunci IT Inv</button>
                <button type="button" class="ant-btn" id="btnAuditLog" onclick="showDocAuditLog(document.getElementById('srNo') ? document.getElementById('srNo').value : '', 'SR')" title="Lihat Catatan Riwayat Perubahan & Penghapusan Data (Audit Trail)"><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary);"></i> Log Perubahan</button>
            </div>
        </div>

        <!-- Navigation Tabs (3 Tab Utama Sesuai Sales Invoice) -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="entri" onclick="switchTab('entri')"><i class="fa-solid fa-pen-to-square"></i> Entri (Retur Penjualan)</button>
            <button type="button" class="ant-tab-btn" data-tab="browse" onclick="switchTab('browse')"><i class="fa-solid fa-table-list"></i> Browse Data</button>
            <button type="button" class="ant-tab-btn" data-tab="trace" onclick="switchTab('trace')"><i class="fa-solid fa-route"></i> Traceability Retur</button>
        </div>

        <!-- TAB 1: Entri (Retur Penjualan) -->
        <section class="ant-page active" id="pageEntri">
            <div class="split-col" id="splitEntri">
                <!-- Pane Header (Identik Sales Invoice) -->
                <div class="split-pane" id="paneHdr">
                    <div class="lock-alert" id="srLock">
                        <span><i class="fa-solid fa-lock"></i> <strong>DOKUMEN TERKUNCI:</strong> Sudah masuk pembukuan IT Inventory (bc_1ab2, AB='A'). Mode baca saja (Read-Only).</span>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteFromItInventory()"><i class="fa-solid fa-unlock"></i> Hapus dari IT Inv &amp; Buka Kunci</button>
                    </div>
                    <div class="hdr-grid">
                        <!-- Sisi Kiri: Customer & Dokumen Asal -->
                        <div>
                            <div class="fld-row">
                                <label>Buyer / Cust:</label>
                                <div class="inline-box">
                                    <select id="srVendor" onchange="onBuyerChange()" style="flex:1;">
                                        <option value="">-- Pilih Buyer / Customer --</option>
                                        <?php foreach ($customers as $b): ?>
                                        <option value="<?= htmlspecialchars($b['KODE']) ?>" data-nama="<?= htmlspecialchars($b['NAMA']) ?>" data-alamat="<?= htmlspecialchars($b['ALAMAT'] ?? '') ?>" data-npwp="<?= htmlspecialchars($b['NPWP'] ?? '') ?>"><?= htmlspecialchars($b['KODE']) ?> - <?= htmlspecialchars($b['NAMA']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="ant-btn" onclick="openSiPickerModal()" title="Tarik dari Faktur Penjualan SI"><i class="fa-solid fa-file-invoice"></i> SI</button>
                                    <button type="button" class="ant-btn" onclick="openDoPickerModal()" title="Tarik dari Surat Jalan DO"><i class="fa-solid fa-truck"></i> DO</button>
                                </div>
                            </div>
                            <div class="checks-box">
                                <label><input type="checkbox" id="srTaxable" checked onchange="calcTotals()"> Kena Pajak</label>
                                <label><input type="checkbox" id="srInclusive" onchange="calcTotals()"> Termasuk Pajak (Inclusive)</label>
                            </div>
                            <div class="fld-row">
                                <label>Alamat:</label>
                                <input type="text" id="srAlamat" readonly placeholder="Alamat Customer">
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>No. DO:</label><input type="text" id="srDoNo" placeholder="Ref Surat Jalan"></div>
                                <div class="fld-row"><label>No. SI:</label><input type="text" id="srSiNo" placeholder="Ref Faktur SI"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Alasan:</label><input type="text" id="srAlasan" placeholder="Cacat / Reject / Salah Kirim"></div>
                                <div class="fld-row"><label>Kompensasi:</label>
                                    <select id="srKompensasi">
                                        <option value="POTONG_PIUTANG">Potong Piutang (CN)</option>
                                        <option value="GANTI_BARANG">Ganti Barang Baru</option>
                                        <option value="REFUND_UANG">Pengembalian Uang</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Kurs, No Retur, Tanggal, Gudang -->
                        <div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Kurs:</label>
                                    <select id="srKurs" onchange="toggleRate()">
                                        <?php foreach ($kursList as $c): ?>
                                        <option value="<?= $c ?>"><?= $c ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fld-row"><label>Kurs Pajak:</label><input type="number" id="srRate" value="1" step="0.0001" oninput="calcTotals()"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label style="font-weight:700; color:var(--primary);">No. Retur:</label>
                                    <div class="inline-box">
                                        <input type="text" id="srNo" class="highlight-inv-no" placeholder="Otomatis jika kosong">
                                        <button type="button" class="ant-btn" onclick="generateAutoSrNo()" title="Generate Nomor Urut"><i class="fa-solid fa-rotate"></i></button>
                                    </div>
                                </div>
                                <div class="fld-row"><label>Tgl Terima:</label><input type="date" id="srShipDt" value="<?= $today ?>"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl Retur:</label><input type="date" id="srDt" value="<?= $today ?>" onchange="calcTotals()"></div>
                                <div class="fld-row"><label>Terms:</label><input type="text" id="srTerm" value="COD"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Gudang Masuk:</label>
                                    <select id="srGudang">
                                        <?php foreach ($gudangList as $g): ?>
                                        <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fld-row"><label>No. FP Retur:</label><input type="text" id="srNoFp" placeholder="No Seri Nota Retur Pajak"></div>
                            </div>
                            <input type="hidden" id="srId">
                            <input type="hidden" id="srIsLock" value="N">
                        </div>
                    </div>
                </div>

                <!-- Splitbar 1: Header / Grid (Persis Sales Invoice) -->
                <div class="split-divider" id="splitHdr" data-pane="paneHdr" data-edge="prev" title="Tarik untuk mengubah ukuran, dobel-klik untuk toggle maximize">
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleSiPane('hdr')" title="Toggle Maximize Header">&#x25BC; Header</button>
                    <div class="split-handle"></div>
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleSiPane('grid')" title="Toggle Maximize Grid">&#x25B2; Grid</button>
                </div>

                <!-- Pane Grid Rincian Barang -->
                <div class="split-pane fill" id="paneGrid">
                    <div class="grid-wrap">
                        <table class="dw" id="tableItems">
                            <thead>
                                <tr>
                                    <th class="col-seq" data-col="seq" style="width:34px; text-align:center;">#</th>
                                    <th class="col-kode" data-col="kode" style="width:130px;">Kode Barang</th>
                                    <th class="col-uraian" data-col="uraian" style="min-width:220px; width:220px;">Nama &amp; Uraian Barang</th>
                                    <th class="col-qty" data-col="qty" style="width:80px; text-align:right;">Qty Retur</th>
                                    <th class="col-sat" data-col="sat" style="width:55px; text-align:center;">Satuan</th>
                                    <th class="col-price" data-col="price" style="width:100px; text-align:right;">Harga Satuan</th>
                                    <th class="col-disc" data-col="disc" style="width:65px; text-align:right;">Disc(%)</th>
                                    <th class="col-amount" data-col="amount" style="width:110px; text-align:right;">Total Nilai</th>
                                    <th class="col-refsi" data-col="refsi" style="width:105px;">Ref Faktur SI</th>
                                    <th class="col-refdo" data-col="refdo" style="width:105px;">Ref Surat Jalan DO</th>
                                    <th class="col-note" data-col="note" style="min-width:140px; width:140px;">Catatan / Alasan</th>
                                    <th class="col-action th-action" data-col="action" style="width:30px; text-align:center;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsContainer"></tbody>
                        </table>
                    </div>
                    <div style="padding:4px 8px; background:var(--table-head-bg); border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <button type="button" class="ant-btn ant-btn-sm" data-lock="block" onclick="addItemRow()"><i class="fa-solid fa-plus"></i> Tambah Baris Barang</button>
                            <button type="button" class="ant-btn ant-btn-sm" data-lock="block" onclick="openMaterialPicker()"><i class="fa-solid fa-boxes-stacked" style="color:var(--success);"></i> Master Barang (Ctrl+E)</button>
                            <button type="button" class="ant-btn ant-btn-sm" data-lock="block" onclick="openSiPickerModal()"><i class="fa-solid fa-file-invoice" style="color:var(--primary);"></i> Tarik dari Faktur SI (F3)</button>
                            <button type="button" class="ant-btn ant-btn-sm" data-lock="block" onclick="openDoPickerModal()"><i class="fa-solid fa-truck" style="color:#0284c7;"></i> Tarik dari Surat Jalan DO</button>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="exportGridToExcel()"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Export Excel</button>
                        </div>
                        <span class="muted" id="itemCountInfo">0 baris rincian</span>
                    </div>
                </div>

                <!-- Splitbar 2: Grid / Footer (Persis Sales Invoice) -->
                <div class="split-divider" id="splitFtr" data-pane="paneFtr" data-edge="next" title="Tarik untuk mengubah ukuran, dobel-klik untuk toggle maximize">
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleSiPane('grid')" title="Toggle Maximize Grid">&#x25BC; Grid</button>
                    <div class="split-handle"></div>
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleSiPane('ftr')" title="Toggle Maximize Footer">&#x25B2; Footer</button>
                </div>

                <!-- Pane Footer / Total Order (Persis Sales Invoice) -->
                <div class="split-pane" id="paneFtr">
                    <div class="ftr-grid">
                        <!-- Sisi Kiri: Pabean BC & Pajak Coretax & Terbilang -->
                        <div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Dok Pabean:</label>
                                    <select id="srBc">
                                        <option value="">- Non BC / Lokal -</option>
                                        <option value="27">BC 2.7 MASUK</option>
                                        <option value="40">BC 4.0 MASUK</option>
                                        <option value="41">BC 4.1 MASUK</option>
                                        <option value="25">BC 2.5 MASUK</option>
                                    </select>
                                </div>
                                <div class="fld-row"><label>No. BC:</label><input type="text" id="srNoBc" placeholder="Nomor Pendaftaran"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl BC:</label><input type="date" id="srTglBc"></div>
                                <div class="fld-row"><label>No. AJU:</label><input type="text" id="srNoAju" placeholder="######-######-########-######"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Faktur Pajak:</label>
                                    <div class="inline-box">
                                        <select id="srKdFp" style="width:55px;">
                                            <option value="01">01</option>
                                            <option value="02">02</option>
                                            <option value="03">03</option>
                                            <option value="04">04</option>
                                            <option value="07">07</option>
                                            <option value="08">08</option>
                                        </select>
                                        <input type="text" id="srNoFpInput" placeholder="No Seri FP">
                                    </div>
                                </div>
                                <div class="fld-row"><label>Note/Ket:</label><input type="text" id="srNote" placeholder="Catatan transaksi retur"></div>
                            </div>
                            <!-- Terbilang Callout Rapih Sisi Kiri -->
                            <div class="terbilang-callout" id="boxTerbilang">
                                <span class="lbl-say">Say:</span>
                                <span class="txt-say" id="dispTerbilang">Nol Rupiah</span>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Financial Totals -->
                        <div>
                            <div class="fld-row"><label>Subtotal Retur:</label><input type="text" id="dispSubtotal" readonly style="text-align:right; font-weight:600;"></div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Diskon (%):</label><input type="number" id="srDiscPersen" value="0" step="0.01" style="text-align:right;" oninput="calcTotals()"></div>
                                <div class="fld-row"><label>Potongan (Rp):</label><input type="number" id="srDiscAmt" value="0" step="0.01" style="text-align:right;" oninput="calcTotals(true)"></div>
                            </div>
                            <div class="fld-row"><label>DPP Retur:</label><input type="text" id="dispDpp" readonly style="text-align:right;"></div>
                            <div class="fld-row"><label>PPN (11%):</label><input type="text" id="dispPpn" readonly style="text-align:right;"></div>
                            <div class="fld-row"><label>Biaya Freight:</label><input type="number" id="srFreight" value="0" step="0.01" style="text-align:right;" oninput="calcTotals()"></div>
                            <div class="fld-row" style="margin-top:4px;">
                                <label style="font-size:11px; font-weight:700; color:var(--primary);">TOTAL RETUR:</label>
                                <input type="text" id="dispTotal" readonly style="text-align:right; font-size:12px; font-weight:700; color:var(--primary); background:rgba(225,29,72,0.06); border:1px solid rgba(225,29,72,0.3);">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 2: Browse Data (Persis Sales Invoice) -->
        <section class="ant-page" id="pageBrowse">
            <!-- 4 KPI Metrics Strip -->
            <div class="kpi-strip">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#ffe4e6; color:#e11d48;"><i class="fa-solid fa-arrow-rotate-left"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TOTAL RETUR PENJUALAN</div>
                        <div style="font-size:12px; font-weight:700;" id="kpiTotalSr">0 Dokumen</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-calendar-days"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">RETUR BULAN INI</div>
                        <div style="font-size:12px; font-weight:700; color:#d97706;" id="kpiMthSr">0 Dokumen</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#ecfdf5; color:#10b981;"><i class="fa-solid fa-cubes-stacked"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TOTAL QTY RETUR</div>
                        <div style="font-size:12px; font-weight:700; color:#10b981;" id="kpiTotalQty">0.00 PCE</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#f5f3ff; color:#8b5cf6;"><i class="fa-solid fa-money-bill-transfer"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TOTAL NILAI RETUR</div>
                        <div style="font-size:12px; font-weight:700; color:#8b5cf6;" id="kpiTotalNilai">Rp 0.00</div>
                    </div>
                </div>
            </div>

            <!-- Browse Filter Toolbar -->
            <div class="browse-filter">
                <label>Periode:</label>
                <input type="date" id="filterTglAwal" value="<?= $monthStart ?>" style="width:110px;">
                <label>s/d</label>
                <input type="date" id="filterTglAkhir" value="<?= $today ?>" style="width:110px;">
                <label>Status:</label>
                <select id="filterStatus" style="width:95px;">
                    <option value="">- SEMUA -</option>
                    <option value="0">DRAFT</option>
                    <option value="1">POSTED (IT INV)</option>
                </select>
                <label>Cari:</label>
                <input type="text" id="filterSearch" placeholder="No Retur / Pelanggan / Faktur..." style="width:200px;" onkeydown="if(event.key==='Enter') loadBrowseData(1)">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadBrowseData(1)"><i class="fa-solid fa-magnifying-glass"></i> Retrieve</button>
            </div>

            <!-- Browse Grid Table -->
            <div class="split-col">
                <div class="split-pane fill">
                    <div class="grid-wrap">
                        <table class="dw" id="srBrowseTable">
                            <thead>
                                <tr>
                                    <th style="width:34px; text-align:center;">#</th>
                                    <th style="width:140px;" class="th-highlight">No. Retur (SR)</th>
                                    <th style="width:85px; text-align:center;">Tgl Retur</th>
                                    <th style="width:180px;">Buyer / Customer</th>
                                    <th style="width:120px;">Ref Faktur SI</th>
                                    <th style="width:55px; text-align:center;">Kurs</th>
                                    <th style="width:110px; text-align:right;">Total Nilai</th>
                                    <th style="width:90px; text-align:center;">Status</th>
                                    <th style="width:85px; text-align:center;">Dok BC</th>
                                    <th style="width:170px;">Nomor AJU</th>
                                    <th style="width:110px;">Kompensasi</th>
                                    <th style="width:140px;">Alasan Retur</th>
                                </tr>
                            </thead>
                            <tbody id="srBrowseBody"></tbody>
                        </table>
                    </div>
                    <!-- Browse Pagination -->
                    <div style="padding:4px 10px; background:var(--table-head-bg); border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                        <span class="muted" id="browsePageInfo">Menampilkan 0-0 dari 0 data</span>
                        <div style="display:flex; gap:4px; align-items:center;">
                            <button type="button" class="ant-btn ant-btn-sm" onclick="browsePrevPage()"><i class="fa-solid fa-chevron-left"></i> Prev</button>
                            <span style="font-weight:600; padding:0 4px;" id="browsePageNum">1</span>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="browseNextPage()">Next <i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 3: Traceability Retur (Persis Sales Invoice) -->
        <section class="ant-page" id="pageTrace">
            <div style="padding:6px 12px; background:var(--table-head-bg); border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:8px;">
                <label>Nomor Retur / AJU / Faktur:</label>
                <input type="text" id="traceSearchText" placeholder="Ketik nomor retur atau nomor faktur..." style="width:240px;" onkeydown="if(event.key==='Enter') executeTrace()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="executeTrace()"><i class="fa-solid fa-route"></i> Lacak Traceability</button>
            </div>
            <div class="split-col" style="overflow:auto; padding:12px;" id="traceContainer">
                <div style="text-align:center; padding:40px 0; color:var(--text-muted);">
                    <i class="fa-solid fa-route" style="font-size:32px; opacity:0.3; margin-bottom:8px;"></i>
                    <div>Ketik nomor retur atau pilih dokumen dari tab browse lalu klik <b>Lacak Traceability</b>.</div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Context Menu Grid (Klik Kanan PB Style - Persis Sales Invoice) -->
<div id="gridContextMenu" class="context-menu" style="display:none;" onclick="event.stopPropagation()">
    <div class="ctx-item" onclick="ctxTambahBaru()"><i class="fa-solid fa-plus" style="color:var(--primary);"></i> <span>Baru (Tambah Baris)</span> <kbd>Ctrl+I / Insert</kbd></div>
    <div class="ctx-item" onclick="ctxHapusBaris()"><i class="fa-solid fa-xmark" style="color:var(--danger);"></i> <span>Hapus Baris</span> <kbd>Ctrl+Del / Del</kbd></div>
    <div class="ctx-item" onclick="ctxPilihBarang()"><i class="fa-solid fa-boxes-stacked" style="color:var(--success);"></i> <span>Pilih dari Master Barang</span> <kbd>Ctrl+E</kbd></div>
    <div class="ctx-item" onclick="ctxTarikSi()"><i class="fa-solid fa-file-invoice" style="color:var(--primary);"></i> <span>Tarik dari Faktur SI</span> <kbd>F3</kbd></div>
    <div class="ctx-item" onclick="ctxTarikDo()"><i class="fa-solid fa-truck" style="color:#0284c7;"></i> <span>Tarik dari Surat Jalan DO</span></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxSisipkanAtas()"><i class="fa-solid fa-arrow-up" style="color:#64748b;"></i> <span>Sisipkan Di Atas</span></div>
    <div class="ctx-item" onclick="ctxSisipkanBawah()"><i class="fa-solid fa-arrow-down" style="color:#64748b;"></i> <span>Sisipkan Di Bawah</span></div>
    <div class="ctx-item" onclick="ctxDuplikasiBaris()"><i class="fa-solid fa-copy" style="color:#8b5cf6;"></i> <span>Duplikasi Baris</span></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxSalinBaris()"><i class="fa-regular fa-clipboard" style="color:#f59e0b;"></i> <span>Salin Baris Terpilih (TSV)</span></div>
    <div class="ctx-item" onclick="exportGridToExcel(); hideContextMenu();"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> <span>Ekspor Rincian ke Excel (.xlsx)</span></div>
</div>

<!-- Modal: Pilih Master Barang -->
<div class="modal" id="modalMaterial">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-boxes-stacked"></i> Pilih Master Barang : Finished Goods / Retur</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalMaterial')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="modalMaterialSearch" style="flex:1;" placeholder="Ketik kode barang atau uraian..." onkeydown="if(event.key==='Enter') searchMaterialItems()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchMaterialItems()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw">
                    <thead>
                        <tr>
                            <th style="width:130px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width:60px; text-align:center;">Satuan</th>
                            <th style="width:100px; text-align:right;">Harga Satuan</th>
                            <th style="width:90px;">Pos Tarif</th>
                            <th style="width:60px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tblModalMaterialBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Tarik dari Faktur Penjualan (SI) -->
<div class="modal" id="modalSiPicker">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-file-invoice" style="color:var(--primary);"></i> Tarik Rincian Barang dari Faktur Penjualan (Sales Invoice)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalSiPicker')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="modalSiSearch" style="flex:1;" placeholder="Cari No SI / Kode Barang / Uraian..." onkeydown="if(event.key==='Enter') searchSiItems()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchSiItems()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;"><input type="checkbox" onchange="toggleSelectAllSiModal(this.checked)"></th>
                            <th style="width:120px;">No. Faktur SI</th>
                            <th style="width:80px; text-align:center;">Tgl Faktur</th>
                            <th>Pelanggan</th>
                            <th style="width:120px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width:75px; text-align:right;">Qty SI</th>
                            <th style="width:50px; text-align:center;">Sat</th>
                            <th style="width:90px; text-align:right;">Harga Sat</th>
                            <th style="width:100px; text-align:right;">Total Nilai</th>
                        </tr>
                    </thead>
                    <tbody id="tblModalSiBody"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Centang baris faktur penjualan yang akan dimasukkan ke nota retur.</span>
            <button type="button" class="ant-btn ant-btn-primary" onclick="importSelectedSiItems()"><i class="fa-solid fa-file-import"></i> Masukkan ke Rincian Retur</button>
        </div>
    </div>
</div>

<!-- Modal: Tarik dari Surat Jalan (DO) -->
<div class="modal" id="modalDoPicker">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-truck" style="color:#0284c7;"></i> Tarik Rincian Barang dari Surat Jalan (Delivery Order)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalDoPicker')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="modalDoSearch" style="flex:1;" placeholder="Cari No DO / Kode Barang / Uraian..." onkeydown="if(event.key==='Enter') searchDoItems()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchDoItems()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;"><input type="checkbox" onchange="toggleSelectAllDoModal(this.checked)"></th>
                            <th style="width:120px;">No. Surat Jalan</th>
                            <th style="width:80px; text-align:center;">Tgl DO</th>
                            <th>Pelanggan</th>
                            <th style="width:120px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width:75px; text-align:right;">Qty DO</th>
                            <th style="width:50px; text-align:center;">Sat</th>
                            <th style="width:90px; text-align:right;">Harga Sat</th>
                            <th>Note / Keterangan</th>
                        </tr>
                    </thead>
                    <tbody id="tblModalDoBody"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Centang baris pengiriman fisik yang akan diretur kembali ke pabrik.</span>
            <button type="button" class="ant-btn ant-btn-primary" onclick="importSelectedDoItems()"><i class="fa-solid fa-truck-ramp-box"></i> Masukkan ke Rincian Retur</button>
        </div>
    </div>
</div>

<!-- Modal: Buat Draft BC CEISA 4.0 Staging -->
<div class="modal" id="modalCeisaDraft">
    <div class="modal-card" style="width:480px;">
        <div class="modal-h">
            <span><i class="fa-solid fa-rocket" style="color:var(--warning);"></i> Buat Draft Dokumen BC CEISA 4.0</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalCeisaDraft')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:10px;">
            <p style="font-size:10px; color:var(--text-muted); line-height:1.5;">
                Sistem akan membuat nomor aju resmi (26 digit) dan menyinkronkan rincian retur ke <b>5 tabel staging CEISA 4.0</b> (Header, Barang, Dokumen, Kemasan, Entitas).
            </p>
            <div class="fld-row">
                <label style="width:110px;">Jenis Dokumen BC:</label>
                <select id="draftBcType" style="flex:1;">
                    <option value="27">BC 2.7 MASUK (TPB ke TPB)</option>
                    <option value="40">BC 4.0 MASUK (Kawasan Berikat)</option>
                    <option value="41">BC 4.1 MASUK (Subkon Selesai)</option>
                    <option value="25">BC 2.5 MASUK (Lokal / DPIL)</option>
                </select>
            </div>
            <div class="fld-row">
                <label style="width:110px;">Valuta:</label>
                <select id="draftValuta" style="flex:1;">
                    <option value="IDR">IDR - Rupiah</option>
                    <option value="USD">USD - US Dollar</option>
                </select>
            </div>
            <div class="fld-row">
                <label style="width:110px;">Kurs NDPBM:</label>
                <input type="number" id="draftNdpbm" value="1" step="0.0001" style="flex:1; text-align:right;">
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeModal('modalCeisaDraft')">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitCeisaDraft()"><i class="fa-solid fa-paper-plane"></i> Proses Buat Draft</button>
        </div>
    </div>
</div>

<script>
    // GLOBAL STATE
    const CURRENT_CMP_ID = '<?= addslashes($sessionCmpId) ?>';
    let siMaxPane = null;
    let browseCurrentPage = 1;
    let browseTotalPages = 1;
    let contextRow = null;

    document.addEventListener('DOMContentLoaded', () => {
        initSplitbars();
        initGridContextMenu();
        setupGridColumnResize('#tableItems', 'sr_items');
        setupGridColumnResize('#srBrowseTable', 'sr_browse');
        setupKeyboardShortcuts();

        // Theme switch listener
        const savedTheme = localStorage.getItem('ceisa_theme');
        if (savedTheme === 'dark') document.body.classList.add('dark-mode');
        window.addEventListener('storage', (e) => {
            if (e.key === 'ceisa_theme') {
                if (e.newValue === 'dark') document.body.classList.add('dark-mode');
                else document.body.classList.remove('dark-mode');
            }
        });

        if (typeof initAntSearchableSelect === 'function') {
            initAntSearchableSelect('srVendor', { placeholder: 'Ketik nama / kode buyer...' });
        }

        resetForm();
        loadBrowseData(1);
    });

    // -------------------------------------------------------------------------
    // 1. TABS NAVIGATION
    // -------------------------------------------------------------------------
    function switchTab(tabId) {
        document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
        document.querySelectorAll('.ant-page').forEach(p => p.classList.toggle('active', p.id === 'page' + capitalize(tabId)));
        if (tabId === 'browse') loadBrowseData(browseCurrentPage);
    }
    function capitalize(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

    // -------------------------------------------------------------------------
    // 2. RESIZABLE SPLITBARS (Persis Sales Invoice)
    // -------------------------------------------------------------------------
    function initSplitbars() {
        document.querySelectorAll('.splitbar, .split-divider').forEach(bar => {
            const pane = document.getElementById(bar.dataset.pane || '');
            if (!pane) return;
            const key = 'sr-split-v3-' + (bar.id || bar.dataset.pane);
            const saved = sessionStorage.getItem(key);
            if (saved && parseInt(saved, 10) >= 60) pane.style.flex = '0 0 ' + parseInt(saved, 10) + 'px';

            bar.addEventListener('pointerdown', (e) => {
                if (e.target.closest('.split-btn-collapse')) return;
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
        });
    }

    function toggleSiPane(which) {
        const panes = { hdr: 'paneHdr', grid: 'paneGrid', ftr: 'paneFtr' };
        const root = document.getElementById('splitEntri');
        const restore = siMaxPane === which;
        siMaxPane = restore ? null : which;
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
                const saved = sessionStorage.getItem('sr-split-v3-' + barId);
                pane.style.flex = (saved && parseInt(saved, 10) >= 60) ? ('0 0 ' + parseInt(saved, 10) + 'px') : '';
                bar.style.display = '';
            });
            document.getElementById('paneGrid').style.flex = '';
        }
    }

    // -------------------------------------------------------------------------
    // 3. ENTERPRISE CONTEXT MENU GRID (Persis Sales Invoice)
    // -------------------------------------------------------------------------
    function initGridContextMenu() {
        const table = document.getElementById('tableItems');
        const menu  = document.getElementById('gridContextMenu');
        if (!table || !menu) return;

        table.addEventListener('contextmenu', (e) => {
            const row = e.target.closest('#itemsContainer tr');
            if (!row) return;
            e.preventDefault();
            e.stopPropagation();

            contextRow = row;
            table.querySelectorAll('#itemsContainer tr').forEach(r => r.classList.remove('context-active'));
            row.classList.add('context-active');

            menu.style.display = 'block';
            const mWidth = menu.offsetWidth || 220;
            const mHeight = menu.offsetHeight || 260;
            let x = e.clientX;
            let y = e.clientY;
            if (x + mWidth > window.innerWidth) x = window.innerWidth - mWidth - 8;
            if (y + mHeight > window.innerHeight) y = window.innerHeight - mHeight - 8;
            menu.style.left = x + 'px';
            menu.style.top = y + 'px';
        });

        document.addEventListener('click', (e) => {
            if (!menu.contains(e.target)) hideContextMenu();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') hideContextMenu();
        });
    }

    function hideContextMenu() {
        const menu = document.getElementById('gridContextMenu');
        if (menu) menu.style.display = 'none';
        if (contextRow) contextRow.classList.remove('context-active');
    }

    function ctxTambahBaru() { hideContextMenu(); addItemRow(); }
    function ctxHapusBaris() { hideContextMenu(); if (contextRow) { contextRow.remove(); renumber(); calcTotals(); } }
    function ctxPilihBarang() { hideContextMenu(); openMaterialPicker(contextRow); }
    function ctxTarikSi() { hideContextMenu(); openSiPickerModal(); }
    function ctxTarikDo() { hideContextMenu(); openDoPickerModal(); }

    function ctxSisipkanAtas() {
        hideContextMenu();
        if (!contextRow) return;
        addItemRow();
        const rows = document.querySelectorAll('#itemsContainer tr');
        const newRow = rows[rows.length - 1];
        contextRow.parentNode.insertBefore(newRow, contextRow);
        renumber();
        calcTotals();
    }

    function ctxSisipkanBawah() {
        hideContextMenu();
        if (!contextRow) return;
        addItemRow();
        const rows = document.querySelectorAll('#itemsContainer tr');
        const newRow = rows[rows.length - 1];
        contextRow.parentNode.insertBefore(newRow, contextRow.nextSibling);
        renumber();
        calcTotals();
    }

    function ctxDuplikasiBaris() {
        hideContextMenu();
        if (!contextRow) return;
        const d = {
            id_si: contextRow.querySelector('.it-idsi')?.value || '',
            si_no: contextRow.querySelector('.it-sino')?.value || '',
            id_do: contextRow.querySelector('.it-iddo')?.value || '',
            do_no: contextRow.querySelector('.it-dono')?.value || '',
            kode_barang: contextRow.querySelector('.it-kode')?.value || '',
            uraian: contextRow.querySelector('.it-uraian')?.value || '',
            qty: parseFloat(contextRow.querySelector('.it-qty')?.value) || 1,
            sat: contextRow.querySelector('.it-sat')?.value || 'PCE',
            price: parseFloat(contextRow.querySelector('.it-price')?.value) || 0,
            disc: parseFloat(contextRow.querySelector('.it-disc')?.value) || 0,
            note: contextRow.querySelector('.it-note')?.value || '',
            bc: contextRow.querySelector('.it-bc')?.value || '',
            no_bc: contextRow.querySelector('.it-nobc')?.value || '',
            tgl_bc: contextRow.querySelector('.it-tglbc')?.value || '',
            no_aju: contextRow.querySelector('.it-noaju')?.value || ''
        };
        addItemRow(d);
        const rows = document.querySelectorAll('#itemsContainer tr');
        const newRow = rows[rows.length - 1];
        contextRow.parentNode.insertBefore(newRow, contextRow.nextSibling);
        renumber();
        calcTotals();
    }

    function ctxSalinBaris() {
        hideContextMenu();
        if (!contextRow) return;
        const cols = [
            contextRow.querySelector('.it-kode')?.value || '',
            contextRow.querySelector('.it-uraian')?.value || '',
            contextRow.querySelector('.it-qty')?.value || '',
            contextRow.querySelector('.it-sat')?.value || '',
            contextRow.querySelector('.it-price')?.value || '',
            contextRow.querySelector('.it-disc')?.value || '',
            contextRow.querySelector('.it-amount')?.value || '',
            contextRow.querySelector('.it-sino')?.value || '',
            contextRow.querySelector('.it-dono')?.value || '',
            contextRow.querySelector('.it-note')?.value || ''
        ];
        navigator.clipboard.writeText(cols.join('\t'));
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Baris tersalin ke clipboard (TSV)', showConfirmButton: false, timer: 1500 });
    }

    function setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            if (document.querySelector('.modal.active')) return;
            if ((e.ctrlKey && e.key.toLowerCase() === 'i') || (!e.ctrlKey && !e.altKey && !e.shiftKey && e.key === 'Insert')) {
                e.preventDefault();
                addItemRow();
            } else if ((e.ctrlKey && e.key === 'Delete') || (!e.ctrlKey && !e.altKey && !e.shiftKey && e.key === 'Delete' && document.activeElement && document.activeElement.tagName === 'BODY')) {
                if (contextRow) { e.preventDefault(); ctxHapusBaris(); }
            } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'e') {
                e.preventDefault();
                openMaterialPicker(contextRow);
            } else if (e.key === 'F3') {
                e.preventDefault();
                openSiPickerModal();
            } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                saveSr();
            }
        });
    }

    // -------------------------------------------------------------------------
    // 4. COLUMN RESIZING (localStorage persistent)
    // -------------------------------------------------------------------------
    function getSavedColumnWidths(tableKey) {
        try {
            const raw = localStorage.getItem('ceisa_grid_widths_' + tableKey);
            return raw ? JSON.parse(raw) : {};
        } catch (e) { return {}; }
    }
    function saveColumnWidth(tableKey, colKey, width) {
        try {
            const saved = getSavedColumnWidths(tableKey);
            saved[colKey] = width;
            localStorage.setItem('ceisa_grid_widths_' + tableKey, JSON.stringify(saved));
        } catch (e) {}
    }
    function setupGridColumnResize(tableSelector, tableKey) {
        const table = document.querySelector(tableSelector);
        if (!table) return;
        const ths = table.querySelectorAll('thead tr th');
        const saved = getSavedColumnWidths(tableKey);

        ths.forEach((th, idx) => {
            if (th.classList.contains('col-action') || th.classList.contains('th-action')) return;
            let colKey = th.dataset.col || ('col_' + idx);
            th.dataset.col = colKey;

            if (saved && saved[colKey]) {
                th.style.width = saved[colKey];
                th.style.minWidth = saved[colKey];
            }

            let resizer = th.querySelector('.col-resizer');
            if (!resizer) {
                resizer = document.createElement('div');
                resizer.className = 'col-resizer';
                th.appendChild(resizer);
            }

            resizer.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                resizer.classList.add('resizing');
                const startX = e.clientX;
                const startW = th.getBoundingClientRect().width;

                const onMove = (ev) => {
                    const nw = Math.max(30, startW + (ev.clientX - startX));
                    th.style.width = nw + 'px';
                    th.style.minWidth = nw + 'px';
                };
                const onUp = () => {
                    resizer.classList.remove('resizing');
                    saveColumnWidth(tableKey, colKey, Math.round(th.getBoundingClientRect().width) + 'px');
                    window.removeEventListener('pointermove', onMove);
                    window.removeEventListener('pointerup', onUp);
                };
                window.addEventListener('pointermove', onMove);
                window.addEventListener('pointerup', onUp);
            });
        });
    }

    // -------------------------------------------------------------------------
    // 5. GRID DETAIL ROWS & CALCULATION (Persis Sales Invoice)
    // -------------------------------------------------------------------------
    function addItemRow(data = {}) {
        const tbody = document.getElementById('itemsContainer');
        const tr = document.createElement('tr');
        tr.className = 'item-row';

        const id_si       = data.id_si || '';
        const si_no       = data.si_no || '';
        const id_do       = data.id_do || '';
        const do_no       = data.do_no || '';
        const kode_barang = data.kode_barang || '';
        const uraian      = data.uraian || '';
        const qty         = parseFloat(data.qty) || 1;
        const sat         = data.sat || 'PCE';
        const price       = parseFloat(data.price) || 0;
        const disc        = parseFloat(data.disc) || 0;
        const lineAmt     = (qty * price) - ((qty * price) * (disc / 100));
        const note        = data.note || '';
        const bc          = data.bc || '';
        const no_bc       = data.no_bc || '';
        const tgl_bc      = data.tgl_bc || '';
        const no_aju      = data.no_aju || '';

        tr.innerHTML = `
            <td style="text-align:center;" class="row-seq">1</td>
            <td>
                <div style="display:flex; align-items:center;">
                    <input type="text" class="it-kode" value="${escapeHtml(kode_barang)}" placeholder="Kode Barang" style="font-weight:600;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="openMaterialPicker(this.closest('tr'))" style="padding:0 4px; height:18px;"><i class="fa-solid fa-ellipsis"></i></button>
                </div>
                <input type="hidden" class="it-idsi" value="${escapeHtml(id_si)}">
                <input type="hidden" class="it-iddo" value="${escapeHtml(id_do)}">
                <input type="hidden" class="it-bc" value="${escapeHtml(bc)}">
                <input type="hidden" class="it-nobc" value="${escapeHtml(no_bc)}">
                <input type="hidden" class="it-tglbc" value="${escapeHtml(tgl_bc)}">
                <input type="hidden" class="it-noaju" value="${escapeHtml(no_aju)}">
            </td>
            <td><input type="text" class="it-uraian" value="${escapeHtml(uraian)}" placeholder="Nama & Uraian Barang"></td>
            <td><input type="number" step="any" class="it-qty" value="${qty}" style="text-align:right; font-weight:600;" oninput="calcItemRow(this)"></td>
            <td><input type="text" class="it-sat" value="${escapeHtml(sat)}" style="text-align:center;"></td>
            <td><input type="number" step="any" class="it-price" value="${price.toFixed(4)}" style="text-align:right;" oninput="calcItemRow(this)"></td>
            <td><input type="number" step="any" class="it-disc" value="${disc.toFixed(2)}" style="text-align:right;" oninput="calcItemRow(this)"></td>
            <td><input type="text" class="it-amount num" value="${numberFormat(lineAmt)}" readonly style="font-weight:600; background:rgba(0,0,0,0.02);"></td>
            <td><input type="text" class="it-sino" value="${escapeHtml(si_no)}" placeholder="No Faktur"></td>
            <td><input type="text" class="it-dono" value="${escapeHtml(do_no)}" placeholder="No Surat Jalan"></td>
            <td><input type="text" class="it-note" value="${escapeHtml(note)}" placeholder="Catatan/Alasan"></td>
            <td style="text-align:center;">
                <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="this.closest('tr').remove(); renumber(); calcTotals();" style="padding:0 5px; height:18px;"><i class="fa-solid fa-xmark"></i></button>
            </td>
        `;

        tbody.appendChild(tr);
        renumber();
        calcTotals();
    }

    function renumber() {
        const rows = document.querySelectorAll('#itemsContainer tr');
        rows.forEach((r, idx) => {
            const seqTd = r.querySelector('.row-seq');
            if (seqTd) seqTd.innerText = idx + 1;
        });
        document.getElementById('itemCountInfo').innerText = rows.length + ' baris rincian';
    }

    function calcItemRow(input) {
        const tr = input.closest('tr');
        if (!tr) return;
        const q = parseFloat(tr.querySelector('.it-qty').value) || 0;
        const p = parseFloat(tr.querySelector('.it-price').value) || 0;
        const d = parseFloat(tr.querySelector('.it-disc').value) || 0;
        const amt = (q * p) - ((q * p) * (d / 100));
        tr.querySelector('.it-amount').value = numberFormat(amt);
        calcTotals();
    }

    function calcTotals(isManualDiscAmt = false) {
        let subtotal = 0;
        document.querySelectorAll('#itemsContainer tr').forEach(tr => {
            const q = parseFloat(tr.querySelector('.it-qty').value) || 0;
            const p = parseFloat(tr.querySelector('.it-price').value) || 0;
            const d = parseFloat(tr.querySelector('.it-disc').value) || 0;
            subtotal += (q * p) - ((q * p) * (d / 100));
        });

        const isTaxable   = document.getElementById('srTaxable').checked;
        const isInclusive = document.getElementById('srInclusive').checked;
        let discPct       = parseFloat(document.getElementById('srDiscPersen').value) || 0;
        let discAmt       = parseFloat(document.getElementById('srDiscAmt').value) || 0;

        if (isManualDiscAmt) {
            discPct = subtotal > 0 ? (discAmt / subtotal) * 100 : 0;
            document.getElementById('srDiscPersen').value = discPct.toFixed(2);
        } else {
            discAmt = subtotal * (discPct / 100);
            document.getElementById('srDiscAmt').value = discAmt.toFixed(2);
        }

        const netAfterDisc = Math.max(0, subtotal - discAmt);
        let dpp = netAfterDisc;
        let ppn = 0;

        if (isTaxable) {
            if (isInclusive) {
                dpp = netAfterDisc / 1.11;
                ppn = netAfterDisc - dpp;
            } else {
                ppn = dpp * 0.11;
            }
        }

        const freight = parseFloat(document.getElementById('srFreight').value) || 0;
        const grandTotal = (isInclusive ? netAfterDisc : (dpp + ppn)) + freight;

        document.getElementById('dispSubtotal').value = numberFormat(subtotal);
        document.getElementById('dispDpp').value      = numberFormat(dpp);
        document.getElementById('dispPpn').value      = numberFormat(ppn);
        document.getElementById('dispTotal').value    = numberFormat(grandTotal);

        const sayEl = document.getElementById('dispTerbilang');
        if (sayEl) sayEl.innerText = terbilangJs(grandTotal);
    }

    function terbilangJs(nilai) {
        nilai = Math.floor(Math.abs(Number(nilai) || 0));
        const huruf = ["", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"];
        if (nilai === 0) return "Nol Rupiah";
        function convert(n) {
            if (n < 12) return " " + huruf[n];
            if (n < 20) return convert(n - 10) + " Belas";
            if (n < 100) return convert(Math.floor(n / 10)) + " Puluh" + convert(n % 10);
            if (n < 200) return " Seratus" + convert(n - 100);
            if (n < 1000) return convert(Math.floor(n / 100)) + " Ratus" + convert(n % 100);
            if (n < 2000) return " Seribu" + convert(n - 1000);
            if (n < 1000000) return convert(Math.floor(n / 1000)) + " Ribu" + convert(n % 1000);
            if (n < 1000000000) return convert(Math.floor(n / 1000000)) + " Juta" + convert(n % 1000000);
            if (n < 1000000000000) return convert(Math.floor(n / 1000000000)) + " Miliar" + convert(n % 1000000000);
            return convert(Math.floor(n / 1000000000000)) + " Triliun" + convert(n % 1000000000000);
        }
        return (convert(nilai).trim() + " Rupiah").replace(/\s+/g, ' ');
    }

    function onBuyerChange() {
        const sel = document.getElementById('srVendor');
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) {
            document.getElementById('srAlamat').value = opt.dataset.alamat || '';
        } else {
            document.getElementById('srAlamat').value = '';
        }
    }

    function toggleRate() {
        const kurs = document.getElementById('srKurs').value;
        const rateInput = document.getElementById('srRate');
        if (kurs === 'IDR') {
            rateInput.value = '1';
        }
        calcTotals();
    }

    function generateAutoSrNo() {
        fetch('sales_return.php?action=generate_no&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID))
            .then(r => r.json())
            .then(res => {
                if (res.status === 'OK') document.getElementById('srNo').value = res.sr_no;
            })
            .catch(err => console.error(err));
    }

    // -------------------------------------------------------------------------
    // 6. SAVE, DELETE, RESET, PRINT, IT INVENTORY
    // -------------------------------------------------------------------------
    function resetForm() {
        document.getElementById('srId').value = '';
        document.getElementById('srIsLock').value = 'N';
        document.getElementById('srVendor').value = '';
        if (document.getElementById('srVendor').syncAntSelect) document.getElementById('srVendor').syncAntSelect();
        document.getElementById('srAlamat').value = '';
        document.getElementById('srDoNo').value = '';
        document.getElementById('srSiNo').value = '';
        document.getElementById('srAlasan').value = '';
        document.getElementById('srKompensasi').value = 'POTONG_PIUTANG';
        document.getElementById('srKurs').value = 'IDR';
        document.getElementById('srRate').value = '1';
        document.getElementById('srShipDt').value = '<?= $today ?>';
        document.getElementById('srDt').value = '<?= $today ?>';
        document.getElementById('srTerm').value = 'COD';
        document.getElementById('srGudang').value = 'UTAMA';
        document.getElementById('srNoFp').value = '';
        document.getElementById('srBc').value = '';
        document.getElementById('srNoBc').value = '';
        document.getElementById('srTglBc').value = '';
        document.getElementById('srNoAju').value = '';
        document.getElementById('srKdFp').value = '01';
        document.getElementById('srNoFpInput').value = '';
        document.getElementById('srNote').value = '';
        document.getElementById('srDiscPersen').value = '0';
        document.getElementById('srDiscAmt').value = '0';
        document.getElementById('srFreight').value = '0';

        document.getElementById('itemsContainer').innerHTML = '';
        addItemRow();

        document.getElementById('statusBadgeHeader').className = 'ant-badge ant-badge-belum';
        document.getElementById('statusBadgeHeader').innerText = 'DRAFT';
        document.getElementById('srLock').style.display = 'none';
        document.getElementById('btnTransferItInv').style.display = '';
        document.getElementById('btnDelItInv').style.display = 'none';
        document.getElementById('btnOpenEditor').style.display = 'none';

        generateAutoSrNo();
    }

    function saveSr() {
        if (document.getElementById('srIsLock').value === 'Y') {
            Swal.fire({ icon: 'warning', title: 'Terkunci', text: 'Dokumen sudah diposting ke IT Inventory. Buka kunci terlebih dahulu jika ingin mengedit.' });
            return;
        }

        const sr_no = document.getElementById('srNo').value.trim();
        const vendor = document.getElementById('srVendor').value.trim();
        if (!sr_no) { Swal.fire('Error', 'Nomor Retur Penjualan tidak boleh kosong.', 'error'); return; }
        if (!vendor) { Swal.fire('Error', 'Pilih Buyer / Customer terlebih dahulu.', 'error'); return; }

        const rows = document.querySelectorAll('#itemsContainer tr');
        const items = [];
        rows.forEach(tr => {
            const kd = tr.querySelector('.it-kode')?.value.trim();
            if (kd) {
                items.push({
                    id_si: tr.querySelector('.it-idsi')?.value.trim() || '',
                    si_no: tr.querySelector('.it-sino')?.value.trim() || '',
                    id_do: tr.querySelector('.it-iddo')?.value.trim() || '',
                    do_no: tr.querySelector('.it-dono')?.value.trim() || '',
                    kode_barang: kd,
                    uraian: tr.querySelector('.it-uraian')?.value.trim() || '',
                    qty: parseFloat(tr.querySelector('.it-qty')?.value) || 0,
                    sat: tr.querySelector('.it-sat')?.value.trim() || 'PCE',
                    price: parseFloat(tr.querySelector('.it-price')?.value) || 0,
                    disc: parseFloat(tr.querySelector('.it-disc')?.value) || 0,
                    note: tr.querySelector('.it-note')?.value.trim() || '',
                    bc: tr.querySelector('.it-bc')?.value.trim() || '',
                    no_bc: tr.querySelector('.it-nobc')?.value.trim() || '',
                    tgl_bc: tr.querySelector('.it-tglbc')?.value.trim() || '',
                    no_aju: tr.querySelector('.it-noaju')?.value.trim() || ''
                });
            }
        });

        if (items.length === 0) {
            Swal.fire('Error', 'Tambahkan minimal satu baris rincian barang retur.', 'error');
            return;
        }

        const payload = {
            id: document.getElementById('srId').value.trim(),
            sr_no: sr_no,
            sr_dt: document.getElementById('srDt').value,
            ship_dt: document.getElementById('srShipDt').value,
            customer: vendor,
            term: document.getElementById('srTerm').value.trim(),
            kurs: document.getElementById('srKurs').value,
            rate: parseFloat(document.getElementById('srRate').value) || 1,
            note: document.getElementById('srNote').value.trim(),
            gudang: document.getElementById('srGudang').value,
            kompensasi: document.getElementById('srKompensasi').value,
            alasan_retur: document.getElementById('srAlasan').value.trim(),
            do_no: document.getElementById('srDoNo').value.trim(),
            si_no: document.getElementById('srSiNo').value.trim(),
            bc: document.getElementById('srBc').value,
            no_bc: document.getElementById('srNoBc').value.trim(),
            tgl_bc: document.getElementById('srTglBc').value,
            no_aju: document.getElementById('srNoAju').value.trim(),
            kd_fp: document.getElementById('srKdFp').value,
            no_fp: document.getElementById('srNoFpInput').value.trim(),
            vendoristaxable: document.getElementById('srTaxable').checked ? 'Y' : 'N',
            disc_persen: parseFloat(document.getElementById('srDiscPersen').value) || 0,
            disc_amt: parseFloat(document.getElementById('srDiscAmt').value) || 0,
            est_freight: parseFloat(document.getElementById('srFreight').value) || 0,
            items: items
        };

        fetch('sales_return.php?action=save&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                Swal.fire({ icon: 'success', title: 'Tersimpan', text: res.message });
                document.getElementById('srId').value = res.id;
                document.getElementById('srNo').value = res.sr_no;
                loadBrowseData(browseCurrentPage);
            } else {
                Swal.fire('Gagal Simpan', res.message, 'error');
            }
        })
        .catch(err => Swal.fire('Error Network', err.message, 'error'));
    }

    function deleteCurrent() {
        const id = document.getElementById('srId').value.trim();
        if (!id) { Swal.fire('Info', 'Pilih dokumen yang ingin dihapus terlebih dahulu.', 'info'); return; }
        if (document.getElementById('srIsLock').value === 'Y') {
            Swal.fire('Terkunci', 'Dokumen terkunci IT Inventory. Buka kunci terlebih dahulu.', 'warning');
            return;
        }

        Swal.fire({
            title: 'Hapus Retur Penjualan?',
            text: 'Data dokumen dan rincian item akan dihapus permanen.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Ya, Hapus'
        }).then(r => {
            if (r.isConfirmed) {
                fetch('sales_return.php?action=delete&id=' + encodeURIComponent(id) + '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID))
                    .then(res => res.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire('Terhapus', res.message, 'success');
                            resetForm();
                            loadBrowseData(browseCurrentPage);
                        } else {
                            Swal.fire('Gagal Hapus', res.message, 'error');
                        }
                    });
            }
        });
    }

    function printSr() {
        const id = document.getElementById('srId').value.trim();
        const no = document.getElementById('srNo').value.trim();
        if (!id && !no) { Swal.fire('Info', 'Pilih atau simpan dokumen retur sebelum mencetak.', 'info'); return; }
        window.open('sales_return.php?action=print&id=' + encodeURIComponent(id) + '&sr_no=' + encodeURIComponent(no) + '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), '_blank');
    }

    function postToItInventory() {
        const id = document.getElementById('srId').value.trim();
        if (!id) { Swal.fire('Info', 'Simpan dokumen retur terlebih dahulu sebelum posting ke IT Inventory.', 'info'); return; }

        Swal.fire({
            title: 'Posting ke IT Inventory?',
            text: "Data barang akan dicatat di buku penerimaan kembali barang jadi retur (bc_1ab2, AB='A') dan dokumen akan dikunci.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            confirmButtonText: 'Ya, Posting & Kunci'
        }).then(r => {
            if (r.isConfirmed) {
                fetch('sales_return.php?action=transfer_it_inv&id=' + encodeURIComponent(id) + '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID))
                    .then(res => res.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire('Berhasil Posting', res.message, 'success');
                            loadSrDetail(id);
                        } else {
                            Swal.fire('Gagal Posting', res.message, 'error');
                        }
                    });
            }
        });
    }

    function deleteFromItInventory() {
        const id = document.getElementById('srId').value.trim();
        if (!id) return;

        Swal.fire({
            title: 'Buka Kunci IT Inventory?',
            text: 'Catatan mutasi penerimaan di IT Inventory (bc_1ab2) akan dihapus dan dokumen dapat diedit kembali.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Ya, Buka Kunci'
        }).then(r => {
            if (r.isConfirmed) {
                fetch('sales_return.php?action=delete_it_inv&id=' + encodeURIComponent(id) + '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID))
                    .then(res => res.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire('Kunci Terbuka', res.message, 'success');
                            loadSrDetail(id);
                        } else {
                            Swal.fire('Gagal', res.message, 'error');
                        }
                    });
            }
        });
    }

    // -------------------------------------------------------------------------
    // 7. CEISA 4.0 STAGING BRIDGE
    // -------------------------------------------------------------------------
    function openCeisaDraftModal() {
        const id = document.getElementById('srId').value.trim();
        if (!id) { Swal.fire('Info', 'Simpan transaksi retur terlebih dahulu sebelum membuat Draft BC.', 'info'); return; }
        const currentBc = document.getElementById('srBc').value;
        if (currentBc) document.getElementById('draftBcType').value = currentBc;
        document.getElementById('modalCeisaDraft').classList.add('active');
    }

    function submitCeisaDraft() {
        const id = document.getElementById('srId').value.trim();
        const bc = document.getElementById('draftBcType').value;
        const valuta = document.getElementById('draftValuta').value;
        const ndpbm = document.getElementById('draftNdpbm').value;

        const fd = new FormData();
        fd.append('id', id);
        fd.append('kode_dokumen', bc);
        fd.append('valuta', valuta);
        fd.append('ndpbm', ndpbm);

        closeModal('modalCeisaDraft');
        Swal.fire({ title: 'Membuat Draft BC...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch('sales_return.php?action=create_draft_bc&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            Swal.close();
            if (res.status === 'OK') {
                document.getElementById('srNoAju').value = res.nomor_aju;
                document.getElementById('srBc').value = res.kode_dokumen;
                const btnEd = document.getElementById('btnOpenEditor');
                if (btnEd) {
                    btnEd.style.display = 'inline-flex';
                    btnEd.dataset.url = res.editor_url;
                }

                Swal.fire({
                    icon: 'success',
                    title: 'Draft BC Berhasil Dibuat!',
                    html: `<div style="text-align:left; font-size:11px; line-height:1.6;">
                            Dokumen <b>BC ${escapeHtml(res.kode_dokumen)}</b> berhasil dibuat di Staging CEISA 4.0!<br>
                            Nomor AJU (26 Digit):<br>
                            <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:4px; padding:6px 10px; font-weight:700; color:#15803d; font-size:11px; margin:6px 0; word-break:break-all;">
                                ${escapeHtml(res.nomor_aju)}
                            </div>
                            Data header, rincian barang, dokumen pelengkap, kemasan, dan entitas telah tersinkronisasi.<br><br>
                            Anda dapat langsung meninjau & melengkapi dokumen di <b>Form Editor CEISA 4.0</b>.
                           </div>`,
                    showCancelButton: true,
                    confirmButtonColor: '#059669',
                    confirmButtonText: '<i class="fa-solid fa-arrow-up-right-from-square"></i> Buka di Form Editor',
                    cancelButtonText: 'Tutup'
                }).then((r) => {
                    if (r.isConfirmed && res.editor_url) window.open(res.editor_url, '_blank');
                });
            } else {
                Swal.fire('Gagal Buat Draft', res.message, 'error');
            }
        })
        .catch(err => { Swal.close(); Swal.fire('Error', err.message, 'error'); });
    }

    function openInCeisaEditor() {
        const btn = document.getElementById('btnOpenEditor');
        const url = btn?.dataset.url;
        if (url) window.open(url, '_blank');
        else Swal.fire('Info', 'Buat Draft BC terlebih dahulu.', 'info');
    }

    // -------------------------------------------------------------------------
    // 8. BROWSE DATA TAB & PAGINATION
    // -------------------------------------------------------------------------
    function loadBrowseData(page = 1) {
        browseCurrentPage = page;
        const tglAwal  = document.getElementById('filterTglAwal').value;
        const tglAkhir = document.getElementById('filterTglAkhir').value;
        const status   = document.getElementById('filterStatus').value;
        const search   = document.getElementById('filterSearch').value.trim();

        const params = new URLSearchParams({
            action: 'list',
            page: page,
            limit: 25,
            tgl_awal: tglAwal,
            tgl_akhir: tglAkhir,
            status: status,
            search: search,
            cmp_id: CURRENT_CMP_ID
        });

        fetch('sales_return.php?' + params.toString())
            .then(r => r.json())
            .then(res => {
                if (res.status !== 'OK') return;
                const tbody = document.getElementById('srBrowseBody');
                tbody.innerHTML = '';

                // KPI Strip
                if (res.kpi) {
                    document.getElementById('kpiTotalSr').innerText = (res.kpi.total_sr || 0) + ' Dokumen';
                    document.getElementById('kpiMthSr').innerText   = (res.kpi.mth_sr || 0) + ' Dokumen';
                    document.getElementById('kpiTotalQty').innerText = numberFormat(res.kpi.total_qty || 0) + ' PCE';
                    document.getElementById('kpiTotalNilai').innerText = 'Rp ' + numberFormat(res.kpi.total_nilai || 0);
                }

                if (!res.data || res.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="12" class="muted" style="text-align:center; padding:18px;">Tidak ada data retur penjualan ditemukan.</td></tr>';
                    document.getElementById('browsePageInfo').innerText = 'Menampilkan 0-0 dari 0 data';
                    return;
                }

                res.data.forEach((r, idx) => {
                    const tr = document.createElement('tr');
                    tr.style.cursor = 'pointer';
                    tr.title = 'Dobel-klik untuk membuka dan mengedit dokumen ini';
                    tr.ondblclick = () => { loadSrDetail(r.id); switchTab('entri'); };

                    const seq = ((page - 1) * 25) + idx + 1;
                    const stBadge = r.is_lock === 'Y' 
                        ? '<span class="ant-badge ant-badge-lock">POSTED</span>' 
                        : '<span class="ant-badge ant-badge-belum">DRAFT</span>';

                    tr.innerHTML = `
                        <td style="text-align:center;">${seq}</td>
                        <td style="font-weight:700; color:var(--primary);">${escapeHtml(r.sr_no)}</td>
                        <td style="text-align:center;">${escapeHtml(r.sr_dt_fmt || r.sr_dt)}</td>
                        <td style="font-weight:500;">${escapeHtml(r.customer_name || r.vendor)}</td>
                        <td>${escapeHtml(r.si_no || '-')}</td>
                        <td style="text-align:center;">${escapeHtml(r.kurs || 'IDR')}</td>
                        <td class="num" style="font-weight:600;">${numberFormat(r.total_order)}</td>
                        <td style="text-align:center;">${stBadge}</td>
                        <td style="text-align:center;">${escapeHtml(r.bc ? ('BC ' + r.bc) : '-')}</td>
                        <td><code>${escapeHtml(r.no_aju || '-')}</code></td>
                        <td>${escapeHtml(r.kompensasi || '-')}</td>
                        <td>${escapeHtml(r.alasan_retur || '-')}</td>
                    `;
                    tbody.appendChild(tr);
                });

                const totalRows = res.total || res.data.length;
                browseTotalPages = Math.ceil(totalRows / 25) || 1;
                const start = ((page - 1) * 25) + 1;
                const end = Math.min(totalRows, page * 25);
                document.getElementById('browsePageInfo').innerText = `Menampilkan ${start}-${end} dari ${totalRows} data`;
                document.getElementById('browsePageNum').innerText = page;
            });
    }

    function browsePrevPage() { if (browseCurrentPage > 1) loadBrowseData(browseCurrentPage - 1); }
    function browseNextPage() { if (browseCurrentPage < browseTotalPages) loadBrowseData(browseCurrentPage + 1); }

    function loadSrDetail(id) {
        fetch('sales_return.php?action=get_sr&id=' + encodeURIComponent(id) + '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID))
            .then(r => r.json())
            .then(res => {
                if (res.status !== 'OK') { Swal.fire('Error', res.message, 'error'); return; }
                const h = res.header;
                document.getElementById('srId').value = h.id;
                document.getElementById('srNo').value = h.sr_no;
                document.getElementById('srDt').value = h.sr_dt;
                document.getElementById('srShipDt').value = h.ship_dt || h.sr_dt;
                document.getElementById('srVendor').value = h.vendor;
                document.getElementById('srAlamat').value = h.customer_alamat || '';
                document.getElementById('srTerm').value = h.term || 'COD';
                document.getElementById('srKurs').value = h.kurs || 'IDR';
                document.getElementById('srRate').value = h.rate || 1;
                document.getElementById('srGudang').value = h.gudang || 'UTAMA';
                document.getElementById('srKompensasi').value = h.kompensasi || 'POTONG_PIUTANG';
                document.getElementById('srAlasan').value = h.alasan_retur || '';
                document.getElementById('srDoNo').value = h.do_no || '';
                document.getElementById('srSiNo').value = h.si_no || '';
                document.getElementById('srBc').value = h.bc || '';
                document.getElementById('srNoBc').value = h.no_bc || '';
                document.getElementById('srTglBc').value = h.tgl_bc || '';
                document.getElementById('srNoAju').value = h.no_aju || '';
                document.getElementById('srKdFp').value = h.kd_fp || '01';
                document.getElementById('srNoFpInput').value = h.no_fp || '';
                document.getElementById('srNote').value = h.note || '';
                document.getElementById('srDiscPersen').value = h.disc_persen || 0;
                document.getElementById('srDiscAmt').value = h.disc_amt || 0;
                document.getElementById('srFreight').value = h.est_freight || 0;
                document.getElementById('srIsLock').value = h.is_lock || 'N';

                // Status Badge & Controls
                const isLocked = h.is_lock === 'Y' || h.is_locked_it === 1;
                document.getElementById('srLock').style.display = isLocked ? 'flex' : 'none';
                document.getElementById('statusBadgeHeader').className = isLocked ? 'ant-badge ant-badge-lock' : 'ant-badge ant-badge-belum';
                document.getElementById('statusBadgeHeader').innerText = isLocked ? 'POSTED (IT INV)' : 'DRAFT';
                document.getElementById('btnTransferItInv').style.display = isLocked ? 'none' : '';
                document.getElementById('btnDelItInv').style.display = isLocked ? '' : 'none';

                if (h.no_aju && h.no_aju.length === 26) {
                    const btnEd = document.getElementById('btnOpenEditor');
                    btnEd.style.display = 'inline-flex';
                    btnEd.dataset.url = '/editor.php?nomor_aju=' + encodeURIComponent(h.no_aju);
                }

                // Render detail barang
                const tbody = document.getElementById('itemsContainer');
                tbody.innerHTML = '';
                if (res.items && res.items.length > 0) {
                    res.items.forEach(d => addItemRow(d));
                } else {
                    addItemRow();
                }

                calcTotals();
            });
    }

    // -------------------------------------------------------------------------
    // 9. MODALS: SI PICKER, DO PICKER, MATERIAL PICKER
    // -------------------------------------------------------------------------
    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('active');
    }

    let siModalItems = [];
    function openSiPickerModal() {
        const cust = document.getElementById('srVendor').value;
        if (!cust) { Swal.fire('Pilih Buyer', 'Pilih Buyer / Customer terlebih dahulu.', 'warning'); return; }
        document.getElementById('modalSiPicker').classList.add('active');
        searchSiItems();
    }
    function searchSiItems() {
        const cust = document.getElementById('srVendor').value;
        const q = document.getElementById('modalSiSearch').value.trim();
        fetch(`sales_return.php?action=lookup_si_for_return&customer=${encodeURIComponent(cust)}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`)
            .then(r => r.json())
            .then(res => {
                const tbody = document.getElementById('tblModalSiBody');
                tbody.innerHTML = '';
                if (res.status === 'OK' && res.data && res.data.length > 0) {
                    siModalItems = res.data;
                    res.data.forEach((it, idx) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="text-align:center;"><input type="checkbox" class="chk-si-modal" data-idx="${idx}"></td>
                            <td><b>${escapeHtml(it.si_no)}</b></td>
                            <td style="text-align:center;">${escapeHtml(it.si_dt_fmt || it.si_dt)}</td>
                            <td>${escapeHtml(it.customer_nama)}</td>
                            <td><b>${escapeHtml(it.kode_barang)}</b></td>
                            <td>${escapeHtml(it.uraian)}</td>
                            <td class="num">${numberFormat(it.qty_si)}</td>
                            <td style="text-align:center;">${escapeHtml(it.sat)}</td>
                            <td class="num">${numberFormat(it.price)}</td>
                            <td class="num">${numberFormat(it.amount)}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="10" class="muted" style="text-align:center; padding:18px;">Tidak ada faktur penjualan untuk customer ini.</td></tr>';
                }
            });
    }
    function toggleSelectAllSiModal(chk) {
        document.querySelectorAll('#tblModalSiBody .chk-si-modal').forEach(c => c.checked = chk);
    }
    function importSelectedSiItems() {
        const checked = document.querySelectorAll('#tblModalSiBody .chk-si-modal:checked');
        if (checked.length === 0) { Swal.fire('Pilih Baris', 'Centang minimal satu barang faktur yang ingin diretur.', 'warning'); return; }

        const rows = document.querySelectorAll('#itemsContainer tr');
        if (rows.length === 1 && !rows[0].querySelector('.it-kode')?.value) {
            document.getElementById('itemsContainer').innerHTML = '';
        }

        checked.forEach(c => {
            const it = siModalItems[parseInt(c.dataset.idx)];
            if (it) {
                addItemRow({
                    id_si: it.id_si_dtl,
                    si_no: it.si_no,
                    kode_barang: it.kode_barang,
                    uraian: it.uraian,
                    qty: parseFloat(it.qty_si) || 1,
                    sat: it.sat,
                    price: parseFloat(it.price) || 0,
                    note: 'Retur dari Faktur ' + it.si_no,
                    bc: it.bc || '',
                    no_bc: it.no_bc || '',
                    tgl_bc: it.tgl_bc || '',
                    no_aju: it.no_aju || ''
                });
                document.getElementById('srSiNo').value = it.si_no;
            }
        });

        closeModal('modalSiPicker');
    }

    let doModalItems = [];
    function openDoPickerModal() {
        const cust = document.getElementById('srVendor').value;
        if (!cust) { Swal.fire('Pilih Buyer', 'Pilih Buyer / Customer terlebih dahulu.', 'warning'); return; }
        document.getElementById('modalDoPicker').classList.add('active');
        searchDoItems();
    }
    function searchDoItems() {
        const cust = document.getElementById('srVendor').value;
        const q = document.getElementById('modalDoSearch').value.trim();
        fetch(`sales_return.php?action=lookup_do_outstanding&customer=${encodeURIComponent(cust)}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`)
            .then(r => r.json())
            .then(res => {
                const tbody = document.getElementById('tblModalDoBody');
                tbody.innerHTML = '';
                if (res.status === 'OK' && res.data && res.data.length > 0) {
                    doModalItems = res.data;
                    res.data.forEach((it, idx) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="text-align:center;"><input type="checkbox" class="chk-do-modal" data-idx="${idx}"></td>
                            <td><b>${escapeHtml(it.do_no)}</b></td>
                            <td style="text-align:center;">${escapeHtml(it.do_dt_fmt || it.do_dt)}</td>
                            <td>${escapeHtml(it.customer_nama)}</td>
                            <td><b>${escapeHtml(it.kode_barang)}</b></td>
                            <td>${escapeHtml(it.uraian)}</td>
                            <td class="num">${numberFormat(it.qty_do)}</td>
                            <td style="text-align:center;">${escapeHtml(it.sat)}</td>
                            <td class="num">${numberFormat(it.price)}</td>
                            <td>${escapeHtml(it.note || '-')}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="10" class="muted" style="text-align:center; padding:18px;">Tidak ada Surat Jalan untuk customer ini.</td></tr>';
                }
            });
    }
    function toggleSelectAllDoModal(chk) {
        document.querySelectorAll('#tblModalDoBody .chk-do-modal').forEach(c => c.checked = chk);
    }
    function importSelectedDoItems() {
        const checked = document.querySelectorAll('#tblModalDoBody .chk-do-modal:checked');
        if (checked.length === 0) { Swal.fire('Pilih Baris', 'Centang minimal satu pengiriman DO yang ingin diretur.', 'warning'); return; }

        const rows = document.querySelectorAll('#itemsContainer tr');
        if (rows.length === 1 && !rows[0].querySelector('.it-kode')?.value) {
            document.getElementById('itemsContainer').innerHTML = '';
        }

        checked.forEach(c => {
            const it = doModalItems[parseInt(c.dataset.idx)];
            if (it) {
                addItemRow({
                    id_do: it.id_do_dtl,
                    do_no: it.do_no,
                    si_no: it.do_no,
                    kode_barang: it.kode_barang,
                    uraian: it.uraian,
                    qty: parseFloat(it.qty_do) || 1,
                    sat: it.sat,
                    price: parseFloat(it.price) || 0,
                    note: 'Retur dari DO ' + it.do_no,
                    bc: it.bc || '',
                    no_bc: it.no_bc || '',
                    tgl_bc: it.tgl_bc || '',
                    no_aju: it.no_aju || ''
                });
                document.getElementById('srDoNo').value = it.do_no;
            }
        });

        closeModal('modalDoPicker');
    }

    let targetMaterialRow = null;
    function openMaterialPicker(row = null) {
        targetMaterialRow = row;
        document.getElementById('modalMaterial').classList.add('active');
        searchMaterialItems();
    }
    function searchMaterialItems() {
        const q = document.getElementById('modalMaterialSearch').value.trim();
        fetch(`sales_return.php?action=search_materials&q=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`)
            .then(r => r.json())
            .then(res => {
                const tbody = document.getElementById('tblModalMaterialBody');
                tbody.innerHTML = '';
                if (res.status === 'OK' && res.data && res.data.length > 0) {
                    res.data.forEach(m => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><b>${escapeHtml(m.kode_barang)}</b></td>
                            <td>${escapeHtml(m.uraian)}</td>
                            <td style="text-align:center;">${escapeHtml(m.sat)}</td>
                            <td class="num">${numberFormat(m.price)}</td>
                            <td>${escapeHtml(m.hs_code || '-')}</td>
                            <td style="text-align:center;">
                                <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="selectMaterial('${escapeHtml(m.kode_barang)}', '${escapeHtml(m.uraian)}', '${escapeHtml(m.sat)}', ${m.price})"><i class="fa-solid fa-check"></i> Pilih</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:18px;">Barang tidak ditemukan.</td></tr>';
                }
            });
    }
    function selectMaterial(kode, uraian, sat, price) {
        if (targetMaterialRow) {
            targetMaterialRow.querySelector('.it-kode').value = kode;
            targetMaterialRow.querySelector('.it-uraian').value = uraian;
            targetMaterialRow.querySelector('.it-sat').value = sat;
            targetMaterialRow.querySelector('.it-price').value = parseFloat(price || 0).toFixed(4);
            calcItemRow(targetMaterialRow.querySelector('.it-qty'));
        } else {
            addItemRow({ kode_barang: kode, uraian: uraian, sat: sat, price: price });
        }
        closeModal('modalMaterial');
    }

    function exportGridToExcel() {
        const rows = document.querySelectorAll('#itemsContainer tr');
        if (rows.length === 0) { Swal.fire('Info', 'Tidak ada baris barang untuk diekspor.', 'info'); return; }

        const data = [
            ['NO', 'KODE BARANG', 'NAMA & URAIAN BARANG', 'QTY RETUR', 'SATUAN', 'HARGA SATUAN', 'DISC (%)', 'TOTAL NILAI', 'REF SI', 'REF DO', 'CATATAN']
        ];
        rows.forEach((r, idx) => {
            data.push([
                idx + 1,
                r.querySelector('.it-kode')?.value || '',
                r.querySelector('.it-uraian')?.value || '',
                parseFloat(r.querySelector('.it-qty')?.value) || 0,
                r.querySelector('.it-sat')?.value || '',
                parseFloat(r.querySelector('.it-price')?.value) || 0,
                parseFloat(r.querySelector('.it-disc')?.value) || 0,
                r.querySelector('.it-amount')?.value || '',
                r.querySelector('.it-sino')?.value || '',
                r.querySelector('.it-dono')?.value || '',
                r.querySelector('.it-note')?.value || ''
            ]);
        });

        const ws = XLSX.utils.aoa_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "SalesReturn");
        const srNo = document.getElementById('srNo').value.trim() || 'SR_ITEMS';
        XLSX.writeFile(wb, `${srNo}.xlsx`);
    }

    function executeTrace() {
        const q = document.getElementById('traceSearchText').value.trim();
        if (!q) { Swal.fire('Info', 'Ketik nomor retur atau nomor faktur untuk melacak traceability.', 'info'); return; }

        const c = document.getElementById('traceContainer');
        c.innerHTML = '<div style="text-align:center; padding:40px 0;"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px; color:var(--primary);"></i><div style="margin-top:8px;">Melacak dokumen...</div></div>';

        fetch(`sales_return.php?action=trace&q=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`)
            .then(r => r.json())
            .then(res => {
                if (res.status !== 'OK' || !res.data) {
                    c.innerHTML = '<div style="text-align:center; padding:40px 0; color:var(--danger);"><i class="fa-solid fa-circle-exclamation" style="font-size:28px;"></i><div style="margin-top:8px;">Data traceability tidak ditemukan.</div></div>';
                    return;
                }
                const d = res.data;
                c.innerHTML = `
                    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:4px; padding:12px; margin-bottom:12px;">
                        <div style="font-size:12px; font-weight:700; color:var(--primary); margin-bottom:6px;">
                            <i class="fa-solid fa-rotate-left"></i> Dokumen Retur Penjualan: ${escapeHtml(d.sr_no)} (${escapeHtml(d.sr_dt)})
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; font-size:10px;">
                            <div><b>Customer:</b> ${escapeHtml(d.customer_name || d.vendor)}</div>
                            <div><b>Ref Faktur SI:</b> ${escapeHtml(d.si_no || '-')}</div>
                            <div><b>Dokumen BC:</b> ${escapeHtml(d.bc ? ('BC ' + d.bc) : '-')}</div>
                            <div><b>No. AJU:</b> <code>${escapeHtml(d.no_aju || '-')}</code></div>
                        </div>
                    </div>
                `;
            });
    }

    // Helper functions
    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function numberFormat(val) {
        const n = parseFloat(val) || 0;
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
</script>
</body>
</html>

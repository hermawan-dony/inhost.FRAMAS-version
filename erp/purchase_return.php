<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Purchase Return (PRT / Retur Pembelian)
 * ============================================================================
 * Porting Lengkap dari PowerBuilder TPB-ERP:
 * - w_pr.srw (Window Purchase Return / Retur Pembelian ke Pemasok)
 * - bc_pr_hdr.srd / bc_pr_hdr2.srd (Header Purchase Return)
 * - d_pr_dtl.srd (Rincian Material / Barang yang Diretur)
 * - d_pr_browse.srd (Daftar Dokumen Retur Pembelian & Status)
 * - d_pi_dtl_return.srd (Tarik Item dari Faktur Pembelian / PI)
 * - d_pr_bc_manual.srd (Integrasi Dokumen Pabean Pengeluaran BC 2.7 / BC 4.1 / BC 2.6.1)
 *
 * Mengadopsi 100% Ant Design Compact Desktop 9.5px Identik dengan Sales Invoice (SI) & Purchase Invoice (PI)
 * Standar Warna Badge & Accent: Rose / Red (#ef4444)
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
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']) || !empty($_GET['is_admin']);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

$activeCmpName = $_SESSION['company_name'] ?? '';
$companyLogo   = '/assets/logo.png';
$companyAlamat = '';
$companyNpwp   = '';

if (!empty($sessionCmpId)) {
    $cmpQ = $koneksi->query("SELECT cmp_name, logo, alamat, npwp FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($cmpQ && $cRow = $cmpQ->fetch_assoc()) {
        if (!empty($cRow['cmp_name'])) $activeCmpName = $cRow['cmp_name'];
        if (!empty($cRow['logo'])) $companyLogo = $cRow['logo'];
        if (!empty($cRow['alamat'])) $companyAlamat = $cRow['alamat'];
        if (!empty($cRow['npwp'])) $companyNpwp = $cRow['npwp'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'TPB ERP Enterprise';

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_pr_hdr dan bc_pr_dtl Siap Multi-Tenant
// -------------------------------------------------------------------------
function ensurePrTables($koneksi) {
    try {
        // 1. Table bc_pr_hdr
        $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_pr_hdr'");
        if (!$checkHdr || $checkHdr->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pr_hdr` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `pr_no` varchar(50) DEFAULT NULL,
                `pr_dt` date DEFAULT NULL,
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
                `pi_no` varchar(100) DEFAULT '',
                `ri_no` varchar(100) DEFAULT '',
                `gudang` varchar(30) DEFAULT '',
                `kompensasi` varchar(30) DEFAULT 'POTONG_HUTANG',
                `pi_target` varchar(100) DEFAULT '',
                `alasan_retur` varchar(255) DEFAULT '',
                `is_lock` varchar(1) DEFAULT 'N',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_pr` (`cmp_id`, `pr_no`),
                KEY `idx_vendor` (`vendor`),
                KEY `idx_pr_dt` (`pr_dt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $res = $koneksi->query("SHOW COLUMNS FROM bc_pr_hdr");
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
                'no_aju' => "varchar(30) DEFAULT ''",
                'pi_no' => "varchar(100) DEFAULT ''",
                'ri_no' => "varchar(100) DEFAULT ''",
                'gudang' => "varchar(30) DEFAULT ''",
                'kompensasi' => "varchar(30) DEFAULT 'POTONG_HUTANG'",
                'pi_target' => "varchar(100) DEFAULT ''",
                'alasan_retur' => "varchar(255) DEFAULT ''",
                'is_lock' => "varchar(1) DEFAULT 'N'",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
            ];
            foreach ($extraCols as $col => $def) {
                if (!isset($cols[$col])) {
                    @$koneksi->query("ALTER TABLE bc_pr_hdr ADD COLUMN `$col` $def");
                }
            }
        }

        // 2. Table bc_pr_dtl
        $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_pr_dtl'");
        if (!$checkDtl || $checkDtl->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pr_dtl` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `id_hdr` varchar(40) NOT NULL DEFAULT '',
                `seq` int(11) DEFAULT '1',
                `id_pi` varchar(40) DEFAULT '',
                `pi_no` varchar(50) DEFAULT '',
                `id_ri` varchar(40) DEFAULT '',
                `ri_no` varchar(50) DEFAULT '',
                `po_no` varchar(50) DEFAULT '',
                `kode_barang` varchar(50) DEFAULT '',
                `uraian` varchar(255) DEFAULT '',
                `qty` decimal(15,4) DEFAULT '0.0000',
                `sat` varchar(10) DEFAULT '',
                `price` decimal(15,5) DEFAULT '0.00000',
                `disc` decimal(10,2) DEFAULT '0.00',
                `amount` decimal(15,5) DEFAULT '0.00000',
                `dept` varchar(30) DEFAULT '',
                `note` varchar(255) DEFAULT '',
                `pos_tarif` varchar(30) DEFAULT '',
                `no_bc` varchar(20) DEFAULT '',
                `tgl_bc` date DEFAULT NULL,
                `no_aju` varchar(30) DEFAULT '',
                `bc` varchar(10) DEFAULT '',
                PRIMARY KEY (`id`),
                KEY `idx_cmp_hdr` (`cmp_id`, `id_hdr`),
                KEY `idx_kode_barang` (`kode_barang`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $res = $koneksi->query("SHOW COLUMNS FROM bc_pr_dtl");
            $cols = [];
            while ($r = $res->fetch_assoc()) $cols[$r['Field']] = true;
            $extraCols = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'id_hdr' => "varchar(40) NOT NULL DEFAULT ''",
                'id_pi' => "varchar(40) DEFAULT ''",
                'pi_no' => "varchar(50) DEFAULT ''",
                'id_ri' => "varchar(40) DEFAULT ''",
                'ri_no' => "varchar(50) DEFAULT ''",
                'po_no' => "varchar(50) DEFAULT ''",
                'kode_barang' => "varchar(50) DEFAULT ''",
                'uraian' => "varchar(255) DEFAULT ''",
                'qty' => "decimal(15,4) DEFAULT '0.0000'",
                'sat' => "varchar(10) DEFAULT ''",
                'price' => "decimal(15,5) DEFAULT '0.00000'",
                'disc' => "decimal(10,2) DEFAULT '0.00'",
                'amount' => "decimal(15,5) DEFAULT '0.00000'",
                'note' => "varchar(255) DEFAULT ''",
                'dept' => "varchar(30) DEFAULT ''",
                'pos_tarif' => "varchar(30) DEFAULT ''"
            ];
            foreach ($extraCols as $col => $def) {
                if (!isset($cols[$col])) {
                    @$koneksi->query("ALTER TABLE bc_pr_dtl ADD COLUMN `$col` $def");
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("ensurePrTables Error: " . $e->getMessage());
    }
}
ensurePrTables($koneksi);

// -------------------------------------------------------------------------
// HELPER: Auto-generate Nomor Retur Pembelian (Format: PRT-YYMM-XXXX)
// -------------------------------------------------------------------------
function generatePrNumber($koneksi, $cmpId) {
    $yymm = date('ym');
    $prefix = "PRT-{$yymm}-";
    $escPrefix = $koneksi->real_escape_string($prefix);
    $escCmp = $koneksi->real_escape_string($cmpId);

    $sql = "SELECT pr_no FROM bc_pr_hdr 
            WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
              AND pr_no LIKE '{$escPrefix}%' 
            ORDER BY pr_no DESC LIMIT 1";
    $res = $koneksi->query($sql);
    $nextNum = 1;
    if ($res && $row = $res->fetch_assoc()) {
        $lastPart = substr($row['pr_no'], strlen($prefix));
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

// 1. DAFTAR BROWSE RETUR PEMBELIAN (d_pr_browse.srd)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tglAwal = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir = trim($_GET['tgl_akhir'] ?? '');
        $status  = trim($_GET['status'] ?? 'ALL');
        $vendor  = trim($_GET['vendor'] ?? '');
        $search  = trim($_GET['search'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = max(10, min(500, (int)($_GET['limit'] ?? 25)));
        $offset  = ($page - 1) * $limit;

        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "(a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)";
        }
        if (!empty($tglAwal)) {
            $escAwal = $koneksi->real_escape_string($tglAwal);
            $where[] = "a.pr_dt >= '{$escAwal}'";
        }
        if (!empty($tglAkhir)) {
            $escAkhir = $koneksi->real_escape_string($tglAkhir);
            $where[] = "a.pr_dt <= '{$escAkhir}'";
        }
        if ($status !== 'ALL' && $status !== '') {
            $escStatus = $koneksi->real_escape_string($status);
            $where[] = "a.status = '{$escStatus}'";
        }
        if (!empty($vendor)) {
            $escV = $koneksi->real_escape_string($vendor);
            $where[] = "(a.vendor = '{$escV}' OR b.NAMA LIKE '%{$escV}%' OR b.NAMA_PENDEK LIKE '%{$escV}%')";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(a.pr_no LIKE '%{$escS}%' OR a.pi_no LIKE '%{$escS}%' OR a.no_bc LIKE '%{$escS}%' OR a.no_aju LIKE '%{$escS}%' OR a.note LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%')";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        // Hitung Total Data
        $cntSql = "SELECT COUNT(1) AS cnt FROM bc_pr_hdr a LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL)) {$whereSql}";
        $cntRes = $koneksi->query($cntSql);
        $total  = ($cntRes && $r = $cntRes->fetch_assoc()) ? (int)$r['cnt'] : 0;

        // Ambil Data Baris
        $sql = "SELECT a.*, 
                       COALESCE(b.NAMA, a.vendor) AS vendor_name,
                       COALESCE(b.NAMA_PENDEK, '') AS vendor_short,
                       b.ALAMAT AS vendor_alamat,
                       b.NPWP AS vendor_npwp,
                       (SELECT COUNT(1) FROM bc_pr_dtl d WHERE d.id_hdr = a.id) AS total_item,
                       (SELECT 1 FROM bc_1ab2 it WHERE it.ID_HEADER = a.id LIMIT 1) AS is_locked_it
                FROM bc_pr_hdr a
                LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY a.pr_dt DESC, a.pr_no DESC
                LIMIT {$offset}, {$limit}";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['subtotal_fmt']    = number_format((float)$r['subtotal'], 2, '.', ',');
                $r['disc_fmt']        = number_format((float)$r['disc_amt'], 2, '.', ',');
                $r['ppn_fmt']         = number_format((float)$r['ppn'], 2, '.', ',');
                $r['total_order_fmt'] = number_format((float)$r['total_order'], 2, '.', ',');
                $r['pr_dt_fmt']       = !empty($r['pr_dt']) ? date('d/m/Y', strtotime($r['pr_dt'])) : '-';
                $r['tgl_bc_fmt']      = !empty($r['tgl_bc']) ? date('d/m/Y', strtotime($r['tgl_bc'])) : '-';
                $r['is_locked']       = !empty($r['is_locked_it']) ? 1 : 0;
                $rows[] = $r;
            }
        }

        // KPI Summary Strip
        $kpiWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)" : "";
        $kpiSql = "SELECT 
                    COUNT(1) AS total_pr,
                    SUM(COALESCE(total_order, 0)) AS sum_total_order,
                    SUM(CASE WHEN status = '0' THEN 1 ELSE 0 END) AS total_draft,
                    SUM(CASE WHEN status = '1' THEN 1 ELSE 0 END) AS total_approved,
                    SUM(CASE WHEN status = '9' THEN 1 ELSE 0 END) AS total_void
                   FROM bc_pr_hdr {$kpiWhere}";
        $kpiRes = $koneksi->query($kpiSql);
        $kpi = [
            'total_pr'        => 0,
            'sum_total_order' => 0,
            'total_draft'     => 0,
            'total_approved'  => 0,
            'total_void'      => 0
        ];
        if ($kpiRes && $kr = $kpiRes->fetch_assoc()) {
            $kpi = [
                'total_pr'        => (int)$kr['total_pr'],
                'sum_total_order' => (float)$kr['sum_total_order'],
                'total_draft'     => (int)$kr['total_draft'],
                'total_approved'  => (int)$kr['total_approved'],
                'total_void'      => (int)$kr['total_void']
            ];
        }

        echo json_encode([
            'status' => 'OK',
            'data'   => $rows,
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'kpi'    => $kpi
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 2. GET DETAIL DOKUMEN RETUR (EDIT / LOAD - bc_pr_hdr.srd / d_pr_dtl.srd)
if ($action === 'get_pr') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Retur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hdrSql = "SELECT a.*, 
                          COALESCE(b.NAMA, a.vendor) AS vendor_name,
                          COALESCE(b.NAMA_PENDEK, '') AS vendor_short,
                          b.ALAMAT AS vendor_alamat,
                          b.NPWP AS vendor_npwp
                   FROM bc_pr_hdr a
                   LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                   WHERE a.id = '{$escId}' AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)
                   LIMIT 1";
        $hdrRes = $koneksi->query($hdrSql);
        if (!$hdrRes || $hdrRes->num_rows === 0) throw new Exception('Dokumen Retur Pembelian tidak ditemukan.');
        $header = $hdrRes->fetch_assoc();

        // Cek Status Penguncian IT Inventory (bc_1ab2)
        $chkLock = $koneksi->query("SELECT 1 FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' LIMIT 1");
        $header['is_locked'] = ($chkLock && $chkLock->num_rows > 0) ? 1 : 0;

        // Detail Material Retur
        $dtlSql = "SELECT d.*
                   FROM bc_pr_dtl d
                   WHERE d.id_hdr = '{$escId}'
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

// 3. GENERATE NOMOR RETUR BARU (AUTO-INCREMENT FORMAT PRT-YYMM-XXXX)
if ($action === 'generate_no') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $newNo = generatePrNumber($koneksi, $sessionCmpId);
        echo json_encode(['status' => 'OK', 'pr_no' => $newNo]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 4. LOOKUP FAKTUR PEMBELIAN (PI) UNTUK DIRETUR (d_pi_dtl_return.srd)
if ($action === 'lookup_pi_for_return') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor  = trim($_GET['vendor'] ?? '');
        $search  = trim($_GET['search'] ?? '');
        $tglAwal = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir= trim($_GET['tgl_akhir'] ?? '');

        $where = ["(h.status = '1' OR h.status = '2' OR h.status = '0')"];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        if (!empty($vendor)) {
            $escV = $koneksi->real_escape_string($vendor);
            $where[] = "h.vendor = '{$escV}'";
        }
        if (!empty($tglAwal)) {
            $escTa = $koneksi->real_escape_string($tglAwal);
            $where[] = "h.pi_dt >= '{$escTa}'";
        }
        if (!empty($tglAkhir)) {
            $escTk = $koneksi->real_escape_string($tglAkhir);
            $where[] = "h.pi_dt <= '{$escTk}'";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(h.pi_no LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%')";
        }

        $whereSql = "WHERE " . implode(' AND ', $where);

        $sql = "SELECT d.id AS id_pi_dtl, d.id_hdr AS id_pi_hdr, d.seq, d.kode_barang, d.uraian,
                       d.qty AS qty_pi, d.sat, d.price, d.disc, d.amount, d.dept, d.note,
                       d.ri_no, d.po_no, d.pos_tarif,
                       h.pi_no, h.pi_dt, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                       h.kurs, h.rate, h.term, h.bc, h.no_bc, h.tgl_bc, h.no_aju
                FROM bc_pi_dtl d
                JOIN bc_pi_hdr h ON d.id_hdr = h.id
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY h.pi_dt DESC, h.pi_no DESC, d.seq ASC
                LIMIT 150";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['qty_pi_fmt'] = number_format((float)$r['qty_pi'], 2, '.', ',');
                $r['price_fmt']  = number_format((float)$r['price'], 2, '.', ',');
                $r['amount_fmt'] = number_format((float)$r['amount'], 2, '.', ',');
                $r['pi_dt_fmt']  = !empty($r['pi_dt']) ? date('d/m/Y', strtotime($r['pi_dt'])) : '-';
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 5. LOOKUP PENERIMAAN BARANG (RI / GRN) UNTUK DIRETUR FISIK GUDANG
if ($action === 'lookup_ri_outstanding') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor = trim($_GET['vendor'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $escV = $koneksi->real_escape_string($vendor);
        $escS = $koneksi->real_escape_string($search);

        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        if (!empty($vendor)) {
            $where[] = "(h.vendor = '{$escV}' OR b.NAMA LIKE '%{$escV}%' OR b.NAMA_PENDEK LIKE '%{$escV}%')";
        }
        if (!empty($search)) {
            $where[] = "(h.ri_no LIKE '%{$escS}%' OR h.no_sj LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%')";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $sql = "SELECT d.id AS id_ri_dtl, d.id_hdr AS id_ri_hdr, d.seq, d.kode_barang, d.uraian,
                       d.qty AS qty_ri, d.sat, d.price, d.disc, d.amount, d.dept, d.note,
                       d.po_no, d.pos_tarif,
                       h.ri_no, h.ri_dt, h.no_sj, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                       h.kurs, h.rate, h.term, h.bc, h.no_bc, h.tgl_bc, h.no_aju
                FROM bc_ri_dtl d
                JOIN bc_ri_hdr h ON d.id_hdr = h.id
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY h.ri_dt DESC, h.ri_no DESC, d.seq ASC
                LIMIT 150";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['qty_ri_fmt'] = number_format((float)$r['qty_ri'], 2, '.', ',');
                $r['price_fmt']  = number_format((float)$r['price'], 2, '.', ',');
                $r['amount_fmt'] = number_format((float)$r['amount'], 2, '.', ',');
                $r['ri_dt_fmt']  = !empty($r['ri_dt']) ? date('d/m/Y', strtotime($r['ri_dt'])) : '-';
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 6. FITUR INOVATIF: REKAP PO & HUTANG VENDOR (VENDOR 360° AP EXPOSURE)
if ($action === 'vendor_summary_ap') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor = trim($_GET['vendor'] ?? '');
        if (empty($vendor)) throw new Exception('Vendor belum dipilih.');
        $escV = $koneksi->real_escape_string($vendor);

        // A. Info Vendor
        $vQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, TELP, PAY_TERM as TOP, kurs as CURRENCY FROM bc_bs WHERE KODE = '{$escV}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        $vendorInfo = ($vQ && $v = $vQ->fetch_assoc()) ? $v : ['KODE' => $vendor, 'NAMA' => $vendor];

        // B. Ringkasan Hutang Faktur (Unpaid PI)
        $unpaidSql = "SELECT h.id, h.pi_no, h.pi_dt, h.jatuhtempo, h.term, h.kurs, h.rate,
                             h.total_order AS total_tagihan, h.terbayar,
                             (COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) AS sisa_hutang,
                             h.status, h.bc, h.no_bc
                      FROM bc_pi_hdr h
                      WHERE h.vendor = '{$escV}' 
                        AND (h.status = '0' OR h.status = '1')
                        AND (COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) > 0.01
                        AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)
                      ORDER BY h.pi_dt ASC, h.pi_no ASC";
        $unpaidRes = $koneksi->query($unpaidSql);
        $unpaidList = [];
        $totalHutang = 0;
        if ($unpaidRes) {
            while ($up = $unpaidRes->fetch_assoc()) {
                $totalHutang += (float)$up['sisa_hutang'];
                $up['total_fmt'] = number_format((float)$up['total_tagihan'], 2, '.', ',');
                $up['terbayar_fmt'] = number_format((float)$up['terbayar'], 2, '.', ',');
                $up['sisa_fmt'] = number_format((float)$up['sisa_hutang'], 2, '.', ',');
                $up['pi_dt_fmt'] = !empty($up['pi_dt']) ? date('d/m/Y', strtotime($up['pi_dt'])) : '-';
                $up['jt_fmt'] = !empty($up['jatuhtempo']) ? date('d/m/Y', strtotime($up['jatuhtempo'])) : '-';
                $unpaidList[] = $up;
            }
        }

        // C. Ringkasan Purchase Order (PO) Aktif / Berjalan
        $openPoSql = "SELECT p.id, p.po_no, p.po_dt, p.kurs, p.total_order, p.status,
                             (SELECT COUNT(1) FROM bc_po_dtl pd WHERE pd.id_hdr = p.id) as item_count
                      FROM bc_po_hdr p
                      WHERE p.vendor = '{$escV}' 
                        AND (p.status = '0' OR p.status = '1')
                        AND (p.cmp_id = '{$escCmp}' OR p.cmp_id = '' OR p.cmp_id IS NULL)
                      ORDER BY p.po_dt DESC LIMIT 15";
        $openPoRes = $koneksi->query($openPoSql);
        $openPoList = [];
        $totalOpenPo = 0;
        if ($openPoRes) {
            while ($op = $openPoRes->fetch_assoc()) {
                $totalOpenPo += (float)$op['total_order'];
                $op['total_fmt'] = number_format((float)$op['total_order'], 2, '.', ',');
                $op['po_dt_fmt'] = !empty($op['po_dt']) ? date('d/m/Y', strtotime($op['po_dt'])) : '-';
                $openPoList[] = $op;
            }
        }

        // D. Akumulasi Retur yang Belum Dikompensasikan (Pending Return Credit)
        $returPendingSql = "SELECT SUM(total_order) as total_retur_open 
                            FROM bc_pr_hdr 
                            WHERE vendor = '{$escV}' AND (status = '0' OR status = '1')
                              AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)";
        $retRes = $koneksi->query($returPendingSql);
        $totalReturOpen = ($retRes && $rRow = $retRes->fetch_assoc()) ? (float)$rRow['total_retur_open'] : 0;

        echo json_encode([
            'status' => 'OK',
            'vendor' => $vendorInfo,
            'summary' => [
                'total_hutang'     => $totalHutang,
                'total_hutang_fmt' => number_format($totalHutang, 2, '.', ','),
                'total_open_po'    => $totalOpenPo,
                'total_open_po_fmt'=> number_format($totalOpenPo, 2, '.', ','),
                'total_retur_open' => $totalReturOpen,
                'total_retur_open_fmt' => number_format($totalReturOpen, 2, '.', ','),
                'count_unpaid_pi'  => count($unpaidList),
                'count_open_po'    => count($openPoList)
            ],
            'unpaid_pi' => $unpaidList,
            'open_po'   => $openPoList
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 7. SEARCH MASTER BARANG (AUTOCOMPLETE / MULTI-KLASIFIKASI)
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
        $klasMap = [
            'FG' => "(KLAS LIKE '%Finish%' OR KLAS LIKE '%Barang Jadi%' OR KLAS = 'FG')",
            'RM' => "(KLAS LIKE '%Raw%' OR KLAS LIKE '%Bahan Baku%' OR KLAS = 'RM')",
            'WI' => "(KLAS LIKE '%WIP%' OR KLAS LIKE '%Progress%' OR KLAS LIKE '%Dalam Proses%' OR KLAS = 'WI')",
            'CG' => "(KLAS LIKE '%Capital%' OR KLAS LIKE '%Machine%' OR KLAS LIKE '%Equipment%' OR KLAS = 'CG')",
            'W'  => "(KLAS LIKE '%Waste%' OR KLAS LIKE '%Scrap%' OR KLAS = 'W')",
        ];
        if (!empty($klas) && isset($klasMap[$klas])) {
            $where[] = $klasMap[$klas];
        } elseif (!empty($klas)) {
            $escK = $koneksi->real_escape_string($klas);
            $where[] = "(KLAS = '{$escK}' OR KLAS LIKE '%{$escK}%')";
        }
        if ($escQ !== '') {
            $where[] = "(KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%' OR IFNULL(POS_TARIF, '') LIKE '%{$escQ}%')";
        }
        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $sql = "SELECT KODE_BARANG AS kode_barang, URAIAN AS uraian, IFNULL(KODE_SATUAN, 'PCE') AS sat, 
                       COALESCE(UNIT_PRICE, 0) AS harga, COALESCE(BERAT, 0) AS berat, 
                       KLAS AS klas, COALESCE(POS_TARIF, '') AS pos_tarif
                FROM bc_material 
                {$whereSql} 
                ORDER BY KODE_BARANG ASC LIMIT 80";
        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $data[] = $r;
        }
        echo json_encode(['status' => 'OK', 'data' => $data]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 8. SIMPAN RETUR PEMBELIAN (CREATE / UPDATE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload) throw new Exception('Format data JSON tidak valid.');

        $header  = $payload['header'] ?? [];
        $details = $payload['details'] ?? [];

        if (empty($header)) throw new Exception('Data Header Retur Pembelian kosong.');
        if (empty($details)) throw new Exception('Tabel rincian barang retur tidak boleh kosong.');

        $prNo = trim($header['pr_no'] ?? '');
        if (empty($prNo)) {
            $prNo = generatePrNumber($koneksi, $sessionCmpId);
        }

        $id = trim($header['id'] ?? '');
        $isNew = empty($id);
        if ($isNew) {
            $id = md5($sessionCmpId . '_' . $prNo . '_' . microtime(true));
        }

        $escChkId = $koneksi->real_escape_string($id);
        if (!$isNew) {
            $chkLock = $koneksi->query("SELECT 1 FROM bc_1ab2 WHERE ID_HEADER = '{$escChkId}' LIMIT 1");
            if ($chkLock && $chkLock->num_rows > 0) {
                throw new Exception('Transaksi Retur ini SUDAH DIPOSTING ke IT Inventory (Terkunci). Silakan klik Buka Kunci IT Inv terlebih dahulu sebelum mengedit.');
            }
        }

        // Siapkan variabel Header
        $prDt        = !empty($header['pr_dt']) ? $header['pr_dt'] : date('Y-m-d');
        $shipDt      = !empty($header['ship_dt']) ? $header['ship_dt'] : $prDt;
        $jatuhtempo  = !empty($header['jatuhtempo']) ? $header['jatuhtempo'] : $shipDt;
        $vendor      = trim($header['vendor'] ?? '');
        $term        = trim($header['term'] ?? 'COD');
        $kurs        = trim($header['kurs'] ?? 'IDR');
        $rate        = (float)($header['rate'] ?? 1);
        if ($rate <= 0) $rate = 1;
        $status      = trim($header['status'] ?? '0');
        $note        = trim($header['note'] ?? '');
        $alasanRetur = trim($header['alasan_retur'] ?? '');
        $kompensasi  = trim($header['kompensasi'] ?? 'POTONG_HUTANG');
        $piTarget    = trim($header['pi_target'] ?? '');
        $piNo        = trim($header['pi_no'] ?? '');
        $riNo        = trim($header['ri_no'] ?? '');
        $gudang      = trim($header['gudang'] ?? '');
        $bc          = trim($header['bc'] ?? '');
        $noBc        = trim($header['no_bc'] ?? '');
        $tglBc       = !empty($header['tgl_bc']) ? $header['tgl_bc'] : null;
        $noAju       = trim($header['no_aju'] ?? '');
        $kdFp        = trim($header['kd_fp'] ?? '');
        $noFp        = trim($header['no_fp'] ?? '');

        $subtotal    = (float)($header['subtotal'] ?? 0);
        $discPersen  = (float)($header['disc_persen'] ?? 0);
        $discAmt     = (float)($header['disc_amt'] ?? 0);
        $ppn         = (float)($header['ppn'] ?? 0);
        $freight     = (float)($header['est_freight'] ?? 0);
        $totalOrder  = (float)($header['total_order'] ?? 0);

        // Eksekusi Simpan Header (Prepared Statement)
        if ($isNew) {
            $stmt = $koneksi->prepare("INSERT INTO bc_pr_hdr (
                id, cmp_id, pr_no, pr_dt, ship_dt, jatuhtempo, vendor, term, kurs, rate,
                status, note, alasan_retur, kompensasi, pi_target, pi_no, ri_no, gudang,
                bc, no_bc, tgl_bc, no_aju, kd_fp, no_fp,
                subtotal, disc_persen, disc_amt, ppn, est_freight, total_order, set_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssdsssssssssssssdddddds",
                $id, $sessionCmpId, $prNo, $prDt, $shipDt, $jatuhtempo, $vendor, $term, $kurs, $rate,
                $status, $note, $alasanRetur, $kompensasi, $piTarget, $piNo, $riNo, $gudang,
                $bc, $noBc, $tglBc, $noAju, $kdFp, $noFp,
                $subtotal, $discPersen, $discAmt, $ppn, $freight, $totalOrder, $currentUser
            );
            if (!$stmt->execute()) throw new Exception('Gagal menyimpan Header Retur: ' . $stmt->error);
            $stmt->close();
        } else {
            $stmt = $koneksi->prepare("UPDATE bc_pr_hdr SET
                pr_no = ?, pr_dt = ?, ship_dt = ?, jatuhtempo = ?, vendor = ?, term = ?, kurs = ?, rate = ?,
                status = ?, note = ?, alasan_retur = ?, kompensasi = ?, pi_target = ?, pi_no = ?, ri_no = ?, gudang = ?,
                bc = ?, no_bc = ?, tgl_bc = ?, no_aju = ?, kd_fp = ?, no_fp = ?,
                subtotal = ?, disc_persen = ?, disc_amt = ?, ppn = ?, est_freight = ?, total_order = ?, set_by = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
            $stmt->bind_param("ssssssssssssssssssssssddddddsss",
                $prNo, $prDt, $shipDt, $jatuhtempo, $vendor, $term, $kurs, $rate,
                $status, $note, $alasanRetur, $kompensasi, $piTarget, $piNo, $riNo, $gudang,
                $bc, $noBc, $tglBc, $noAju, $kdFp, $noFp,
                $subtotal, $discPersen, $discAmt, $ppn, $freight, $totalOrder, $currentUser,
                $id, $sessionCmpId
            );
            if (!$stmt->execute()) throw new Exception('Gagal memperbarui Header Retur: ' . $stmt->error);
            $stmt->close();

            // Hapus detail lama untuk di-replace
            $escId = $koneksi->real_escape_string($id);
            $koneksi->query("DELETE FROM bc_pr_dtl WHERE id_hdr = '{$escId}'");
        }

        // Simpan Detail Material
        $dStmt = $koneksi->prepare("INSERT INTO bc_pr_dtl (
            id, cmp_id, id_hdr, seq, id_pi, pi_no, id_ri, ri_no, po_no,
            kode_barang, uraian, qty, sat, price, disc, amount, note, pos_tarif,
            no_bc, tgl_bc, no_aju, bc
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        foreach ($details as $d) {
            $dtlId = md5($id . '_' . $seq . '_' . microtime(true));
            $dPiNo = trim($d['pi_no'] ?? $piNo);
            $dRiNo = trim($d['ri_no'] ?? $riNo);
            $dPoNo = trim($d['po_no'] ?? '');
            $kdBrg = trim($d['kode_barang'] ?? '');
            $uraian= trim($d['uraian'] ?? '');
            $qty   = (float)($d['qty'] ?? 0);
            $sat   = trim($d['sat'] ?? 'PCE');
            $price = (float)($d['price'] ?? 0);
            $disc  = (float)($d['disc'] ?? 0);
            $amt   = (float)($d['amount'] ?? ($qty * $price - $disc));
            $dNote = trim($d['note'] ?? '');
            $posTarif = trim($d['pos_tarif'] ?? '');

            if (empty($kdBrg) && empty($uraian)) continue;

            $dStmt->bind_param("sssissssssdsddssssssss",
                $dtlId, $sessionCmpId, $id, $seq, $dPiNo, $dPiNo, $dRiNo, $dRiNo, $dPoNo,
                $kdBrg, $uraian, $qty, $sat, $price, $disc, $amt, $dNote, $posTarif,
                $noBc, $tglBc, $noAju, $bc
            );
            if (!$dStmt->execute()) throw new Exception('Gagal menyimpan Detail Material: ' . $dStmt->error);
            $seq++;
        }
        $dStmt->close();

        echo json_encode([
            'status'  => 'OK',
            'message' => 'Dokumen Retur Pembelian berhasil disimpan.',
            'id'      => $id,
            'pr_no'   => $prNo
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 9. HAPUS RETUR PEMBELIAN
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Retur Pembelian tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        // Validasi Kunci IT Inventory
        $chkLock = $koneksi->query("SELECT 1 FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' LIMIT 1");
        if ($chkLock && $chkLock->num_rows > 0) {
            throw new Exception('Transaksi Retur ini SUDAH DIPOSTING ke IT Inventory. TIDAK BISA DIHAPUS!');
        }

        // Hapus Header & Detail
        $koneksi->query("DELETE FROM bc_pr_dtl WHERE id_hdr = '{$escId}'");
        $koneksi->query("DELETE FROM bc_pr_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        echo json_encode(['status' => 'OK', 'message' => 'Dokumen Retur Pembelian berhasil dihapus.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 10. POSTING PENGELUARAN KE IT INVENTORY (bc_1ab2, AB='B')
if ($action === 'transfer_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Retur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        // Ambil Header Retur
        $hRes = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as vendor_nama, b.NPWP as vendor_npwp 
                                 FROM bc_pr_hdr h 
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                 WHERE h.id = '{$escId}' LIMIT 1");
        if (!$hRes || $hRes->num_rows === 0) throw new Exception('Dokumen Retur tidak ditemukan.');
        $h = $hRes->fetch_assoc();

        // Ambil Detail Barang
        $dRes = $koneksi->query("SELECT d.*, m.POS_TARIF as m_pos_tarif, COALESCE(m.KLAS, 'Raw Materials') as klas 
                                 FROM bc_pr_dtl d 
                                 LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL)) 
                                 WHERE d.id_hdr = '{$escId}' 
                                 ORDER BY d.seq ASC");
        if (!$dRes || $dRes->num_rows === 0) throw new Exception('Rincian barang kosong. Simpan transaksi sebelum posting ke IT Inventory.');

        // Bersihkan posting lama jika ada
        $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");

        $dokumenPabean = !empty($h['bc']) ? $h['bc'] : 'NON-BC';
        $noDaftar      = !empty($h['no_bc']) ? $h['no_bc'] : '-';
        $tglDaftar     = !empty($h['tgl_bc']) ? $h['tgl_bc'] : $h['pr_dt'];
        $noAju         = !empty($h['no_aju']) ? $h['no_aju'] : '-';
        $noBukti       = $h['pr_no'];
        $tglBukti      = $h['pr_dt'];
        $namaBs        = $koneksi->real_escape_string($h['vendor_nama']);
        $npwpBs        = $koneksi->real_escape_string($h['vendor_npwp'] ?? '');
        $kurs          = !empty($h['kurs']) ? $h['kurs'] : 'IDR';
        $rate          = (float)$h['rate'] > 0 ? (float)$h['rate'] : 1;
        $ab            = 'B'; // 'B' = Pengeluaran / Retur Pengeluaran Pabrik

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
            $gudang    = !empty($h['gudang']) ? $h['gudang'] : 'UTAMA';
            $noPo      = !empty($d['po_no']) ? $d['po_no'] : '';

            $sqlIns = "INSERT INTO bc_1ab2 (
                BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS,
                KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB,
                ID_HEADER, SJ, INVOICE, NOMOR_AJU, NPWP_BS, SERI_BARANG, CIF,
                GUDANG, NOMOR_PO, CRT_DT, CRT_USER, TGL_PROSES, cmp_id
            ) VALUES (
                '{$dokumenPabean}', '{$noDaftar}', '{$tglDaftar}', '{$noBukti}', '{$tglBukti}', '{$namaBs}',
                '{$kodeBrg}', '{$posTarif}', '{$uraian}', '{$klas}', '{$unit}', {$qty}, '{$kurs}', {$nilai}, '{$ab}',
                '{$escId}', '{$noBukti}', '{$h['pi_no']}', '{$noAju}', '{$npwpBs}', {$seq}, {$cif},
                '{$gudang}', '{$noPo}', CURDATE(), '{$currentUser}', '{$h['pr_dt']}', '{$escCmp}'
            )";

            if (!$koneksi->query($sqlIns)) {
                throw new Exception('Gagal insert bc_1ab2: ' . $koneksi->error);
            }
            $seq++;
        }

        // Tandai is_lock di Header
        $koneksi->query("UPDATE bc_pr_hdr SET is_lock = 'Y', status = '1' WHERE id = '{$escId}'");

        echo json_encode(['status' => 'OK', 'message' => 'Berhasil memposting pengeluaran retur ke IT Inventory (' . ($seq - 1) . ' item) dan dokumen dikunci.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 11. BUKA KUNCI IT INVENTORY
if ($action === 'delete_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Retur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $del = $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
        if (!$del) throw new Exception('Gagal menghapus mutasi IT Inventory: ' . $koneksi->error);

        $koneksi->query("UPDATE bc_pr_hdr SET is_lock = 'N' WHERE id = '{$escId}'");

        echo json_encode(['status' => 'OK', 'message' => 'Kunci IT Inventory berhasil dibuka. Dokumen dapat diedit kembali.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 12. TRACEABILITY DOKUMEN (PRT <-> PI <-> RI <-> PO <-> BC 2.7 / 4.1)
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Retur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hRes = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as vendor_nama FROM bc_pr_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')) WHERE h.id = '{$escId}' LIMIT 1");
        $hdr = ($hRes && $hRes->num_rows > 0) ? $hRes->fetch_assoc() : null;

        $dtlRes = $koneksi->query("SELECT id, seq, kode_barang, uraian, qty, sat, price, amount, pi_no, ri_no, po_no, pos_tarif FROM bc_pr_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
        $details = [];
        $piNos = [];
        $riNos = [];
        $poNos = [];
        if ($dtlRes) {
            while ($r = $dtlRes->fetch_assoc()) {
                if (!empty($r['pi_no'])) $piNos[$r['pi_no']] = true;
                if (!empty($r['ri_no'])) $riNos[$r['ri_no']] = true;
                if (!empty($r['po_no'])) $poNos[$r['po_no']] = true;
                $details[] = $r;
            }
        }

        // Kueri PI Terkait
        $piData = [];
        if (!empty($piNos)) {
            $inPi = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], array_keys($piNos))) . "'";
            $pQ = $koneksi->query("SELECT pi_no, pi_dt, vendor, term, kurs, rate, total_order AS total_tagihan, terbayar, bc, no_bc, tgl_bc, no_aju FROM bc_pi_hdr WHERE pi_no IN ({$inPi}) AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            if ($pQ) while ($p = $pQ->fetch_assoc()) $piData[] = $p;
        }

        // Kueri RI Terkait
        $riData = [];
        if (!empty($riNos)) {
            $inRi = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], array_keys($riNos))) . "'";
            $rQ = $koneksi->query("SELECT ri_no, ri_dt, no_sj, no_bc, tgl_bc, no_aju, bc, po_no FROM bc_ri_hdr WHERE ri_no IN ({$inRi}) AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            if ($rQ) while ($r = $rQ->fetch_assoc()) $riData[] = $r;
        }

        // Kueri PO Terkait
        $poData = [];
        if (!empty($poNos)) {
            $inPo = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], array_keys($poNos))) . "'";
            $poQ = $koneksi->query("SELECT po_no, po_dt, vendor, term, kurs, rate, total_order FROM bc_po_hdr WHERE po_no IN ({$inPo}) AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            if ($poQ) while ($po = $poQ->fetch_assoc()) $poData[] = $po;
        }

        echo json_encode([
            'status'  => 'OK',
            'header'  => $hdr,
            'details' => $details,
            'pi_list' => $piData,
            'ri_list' => $riData,
            'po_list' => $poData
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 12B. BRIDGE OTOMATIS KE STAGING DOKUMEN BC CEISA 4.0 (bc_ceisa_header, barang, dokumen, kemasan, entitas)
if ($action === 'create_draft_bc') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id      = trim($_POST['id'] ?? '');
        $kodeBc  = trim($_POST['kode_dokumen'] ?? '27');
        $ndpbm   = floatval($_POST['ndpbm'] ?? 0);
        $valuta  = strtoupper(trim($_POST['valuta'] ?? 'IDR'));

        if (empty($id)) throw new Exception('ID Retur Pembelian belum tersimpan atau tidak valid.');
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
        $hRes = $koneksi->query("SELECT * FROM bc_pr_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if (!$hRes || $hRes->num_rows === 0) throw new Exception('Data Retur Pembelian tidak ditemukan.');
        $prHdr = $hRes->fetch_assoc();

        // 2. Ambil Detail Barang Retur
        $dRes = $koneksi->query("SELECT d.*, m.URAIAN AS mat_uraian, m.HS_CODE, m.KODE_SATUAN, m.NETTO AS mat_netto, m.BRUTO AS mat_bruto, m.VOLUME AS mat_vol 
                                FROM bc_pr_dtl d 
                                LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                                WHERE d.id_hdr = '{$escId}' 
                                ORDER BY d.seq ASC");
        $items = [];
        if ($dRes) {
            while ($r = $dRes->fetch_assoc()) $items[] = $r;
        }
        if (empty($items)) throw new Exception('Rincian barang retur masih kosong. Tambahkan minimal 1 barang sebelum membuat draft BC.');

        // 3. Profil Perusahaan
        $comp = [];
        $qCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
        if ($qCmp && $rCmp = $qCmp->fetch_assoc()) $comp = $rCmp;

        // 4. Data Vendor / Rekanan
        $escVendor = $koneksi->real_escape_string($prHdr['vendor']);
        $vendor = [];
        $qV = $koneksi->query("SELECT * FROM bc_bs WHERE KODE = '{$escVendor}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if ($qV && $rV = $qV->fetch_assoc()) $vendor = $rV;

        // 5. Generate / Ensure Nomor AJU 26 Digit
        $cleanBc2 = substr(str_pad($cleanBc, 2, '0', STR_PAD_LEFT), 0, 2);
        $tglAju = !empty($prHdr['pr_dt']) ? $prHdr['pr_dt'] : date('Y-m-d');
        $ymd = date('Ymd', strtotime($tglAju));

        $nomorAju = trim($prHdr['no_aju'] ?? '');
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
            $rowBruto = (float)($it['mat_bruto'] ?? 0);
            if ($rowBruto <= 0) $rowBruto = $rowNetto * 1.05;
            $rowVol = (float)($it['mat_vol'] ?? 0);
            if ($rowVol <= 0) $rowVol = 0;

            $totNetto += $rowNetto;
            $totBruto += $rowBruto;
            $totVol   += $rowVol;
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

        $kdTujuanKirim = in_array($cleanBc, ['41', '25']) ? '1' : ($cleanBc === '27' ? '1' : '');
        $kdTujuanKeluar = ($cleanBc === '41') ? '2' : (($cleanBc === '27') ? '1' : '');

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
                kode_tujuan_pengiriman, kode_tujuan_pengeluaran,
                nama_pernyataan, jabatan_pernyataan, kota_pernyataan,
                created_at
            ) VALUES (
                '{$escAju}', '{$escCmp}', '{$cleanBc}', '{$kodeKantor}', '{$valuta}', {$ndpbm},
                {$totFob}, {$totCif}, {$totCifIdr}, {$totPenyerahan}, {$totNetto}, {$totBruto}, {$totVol},
                " . count($items) . ", '{$tglAju}', '{$tglAju}',
                '{$kdTujuanKirim}', '{$kdTujuanKeluar}',
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
            $hsRaw   = !empty($it['pos_tarif']) ? $it['pos_tarif'] : ($it['HS_CODE'] ?? '');
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
            $bBruto  = (float)($it['mat_bruto'] ?? 0);
            if ($bBruto <= 0) $bBruto = $bNetto * 1.05;
            $bVol    = (float)($it['mat_vol'] ?? 0);
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
        $prNo  = trim($prHdr['pr_no'] ?? '');
        $prDt  = !empty($prHdr['pr_dt']) ? $prHdr['pr_dt'] : $tglAju;
        $shipDt= !empty($prHdr['ship_dt']) ? $prHdr['ship_dt'] : $prDt;

        if (!empty($prNo)) {
            $escPrNo = $koneksi->real_escape_string($prNo);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('{$escAju}', {$dSeri}, '380', '{$escPrNo}', '{$prDt}')");
            $dSeri++;
        }

        if (!empty($prNo)) {
            $escPrNo = $koneksi->real_escape_string($prNo);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('{$escAju}', {$dSeri}, '217', '{$escPrNo}', '{$shipDt}')");
            $dSeri++;
        }

        if (!empty($prHdr['pi_no'])) {
            $escPi = $koneksi->real_escape_string($prHdr['pi_no']);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('{$escAju}', {$dSeri}, '380', '{$escPi}', '{$prDt}')");
            $dSeri++;
        }

        if (!empty($prHdr['ri_no'])) {
            $escRi = $koneksi->real_escape_string($prHdr['ri_no']);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen) 
                             VALUES ('{$escAju}', {$dSeri}, '217', '{$escRi}', '{$prDt}')");
            $dSeri++;
        }

        // 10. Insert Kemasan bc_ceisa_kemasan
        $koneksi->query("DELETE FROM bc_ceisa_kemasan WHERE nomor_aju = '{$escAju}'");
        $vName = !empty($vendor['NAMA']) ? $vendor['NAMA'] : ($prHdr['vendor'] ?? 'STANDARD PACK');
        $escMerek = $koneksi->real_escape_string(substr($vName, 0, 30));
        $totalPack = count($items);
        $koneksi->query("INSERT INTO bc_ceisa_kemasan (cmp_id, nomor_aju, seri, kode_kemasan, jumlah_kemasan, merek) 
                         VALUES ('{$escCmp}', '{$escAju}', 1, 'PK', {$totalPack}, '{$escMerek}')");

        // 11. Insert Pengangkut bc_ceisa_pengangkut
        $koneksi->query("DELETE FROM bc_ceisa_pengangkut WHERE nomor_aju = '{$escAju}'");
        $caraAngkut = ($cleanBc === '30') ? '1' : '3';
        $koneksi->query("INSERT INTO bc_ceisa_pengangkut (cmp_id, nomor_aju, seri, kode_cara_angkut) 
                         VALUES ('{$escCmp}', '{$escAju}', 1, '{$caraAngkut}')");

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
        $vNama   = $koneksi->real_escape_string($vendor['NAMA'] ?? $prHdr['vendor']);
        $vAlamat = $koneksi->real_escape_string($vendor['ALAMAT'] ?? '');
        $vNegara = $koneksi->real_escape_string($vendor['NEGARA'] ?? 'ID');

        $entitasList = [];
        if ($cleanBc === '30') {
            $entitasList[] = ['seri' => 1, 'kode' => '2', 'npwp' => (!empty($nitkuComp) ? $nitkuComp : $npwp16Comp), 'nama' => $namaComp, 'alamat' => $alamatComp, 'nib' => $nibComp, 'negara' => 'ID', 'no_ijin' => $skepNoComp, 'tgl_ijin' => $skepDtComp];
            $entitasList[] = ['seri' => 2, 'kode' => '7', 'npwp' => (!empty($nitkuComp) ? $nitkuComp : $npwp16Comp), 'nama' => $namaComp, 'alamat' => $alamatComp, 'nib' => $nibComp, 'negara' => 'ID', 'no_ijin' => '', 'tgl_ijin' => 'NULL'];
            $entitasList[] = ['seri' => 3, 'kode' => '8', 'npwp' => $vNitku, 'nama' => $vNama, 'alamat' => $vAlamat, 'nib' => '', 'negara' => $vNegara, 'no_ijin' => '', 'tgl_ijin' => 'NULL'];
        } else {
            $entitasList[] = ['seri' => 1, 'kode' => '3', 'npwp' => (!empty($nitkuComp) ? $nitkuComp : $npwp16Comp), 'nama' => $namaComp, 'alamat' => $alamatComp, 'nib' => $nibComp, 'negara' => 'ID', 'no_ijin' => $skepNoComp, 'tgl_ijin' => $skepDtComp];
            $entitasList[] = ['seri' => 2, 'kode' => '7', 'npwp' => (!empty($nitkuComp) ? $nitkuComp : $npwp16Comp), 'nama' => $namaComp, 'alamat' => $alamatComp, 'nib' => $nibComp, 'negara' => 'ID', 'no_ijin' => '', 'tgl_ijin' => 'NULL'];
            $entitasList[] = ['seri' => 3, 'kode' => '9', 'npwp' => $vNitku, 'nama' => $vNama, 'alamat' => $vAlamat, 'nib' => '', 'negara' => $vNegara, 'no_ijin' => '', 'tgl_ijin' => 'NULL'];
        }

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

        // 13. Update Header Retur Pembelian
        $koneksi->query("UPDATE bc_pr_hdr SET no_aju = '{$escAju}', bc = '{$cleanBc}' WHERE id = '{$escId}'");

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

// 13. PRINT DOKUMEN RESMI A4 (SURAT JALAN & BUKTI RETUR PEMBELIAN)
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrQ = $koneksi->query("SELECT a.*, 
                                    COALESCE(b.NAMA, a.vendor) AS vendor_name,
                                    b.ALAMAT AS vendor_alamat,
                                    b.NPWP AS vendor_npwp,
                                    b.TELP AS vendor_telp
                             FROM bc_pr_hdr a
                             LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                             WHERE a.id = '{$escId}' LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) die("Dokumen Retur tidak ditemukan.");
    $h = $hdrQ->fetch_assoc();

    $dtlQ = $koneksi->query("SELECT * FROM bc_pr_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $items = [];
    if ($dtlQ) while ($r = $dtlQ->fetch_assoc()) $items[] = $r;
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Surat Jalan & Bukti Retur Pembelian - <?= htmlspecialchars($h['pr_no']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 12mm 15mm; }
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 10px; color: #111; line-height: 1.4; margin: 0; padding: 0; }
            .print-btn { position: fixed; top: 10px; right: 10px; padding: 6px 14px; background: #ef4444; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
            @media print { .print-btn { display: none; } }
            .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .hdr-table td { vertical-align: top; }
            .logo-img { max-height: 48px; max-width: 130px; object-fit: contain; }
            .title-box { text-align: center; border-top: 2px solid #ef4444; border-bottom: 2px solid #ef4444; padding: 6px 0; margin-bottom: 12px; }
            .title-box h2 { margin: 0; font-size: 15px; color: #ef4444; letter-spacing: 0.5px; text-transform: uppercase; }
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
            .badge-pabean { display: inline-block; padding: 2px 6px; background: #fee2e2; color: #b91c1c; border-radius: 3px; font-weight: 600; font-size: 9px; }
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
                    <div style="font-size: 14px; font-weight: 700; color: #ef4444;"><?= htmlspecialchars($h['pr_no']) ?></div>
                    <small>Tanggal: <?= !empty($h['pr_dt']) ? date('d F Y', strtotime($h['pr_dt'])) : '-' ?></small><br>
                    <?php if (!empty($h['bc'])): ?>
                        <span class="badge-pabean">Dokumen Pabean: <?= htmlspecialchars($h['bc']) ?> No. <?= htmlspecialchars($h['no_bc']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div class="title-box">
            <h2>SURAT JALAN & BUKTI RETUR PEMBELIAN (PRT)</h2>
            <small>Surat Bukti Pengeluaran Barang / Pengembalian Material Kepada Pemasok</small>
        </div>

        <table class="info-grid">
            <tr>
                <td style="width: 50%;">
                    <div class="info-box">
                        <strong>KEPADA PEMASOK / VENDOR:</strong><br>
                        <b style="font-size: 11px;"><?= htmlspecialchars($h['vendor_name']) ?></b> (<?= htmlspecialchars($h['vendor']) ?>)<br>
                        <?= htmlspecialchars($h['vendor_alamat']) ?><br>
                        NPWP: <?= htmlspecialchars($h['vendor_npwp']) ?> | Telp: <?= htmlspecialchars($h['vendor_telp'] ?? '-') ?>
                    </div>
                </td>
                <td style="width: 50%;">
                    <div class="info-box">
                        <strong>INFORMASI TRANSAKSI RETUR:</strong><br>
                        Tgl Pengiriman / Kirim: <?= !empty($h['ship_dt']) ? date('d/m/Y', strtotime($h['ship_dt'])) : '-' ?><br>
                        Mata Uang / Kurs: <b><?= htmlspecialchars($h['kurs']) ?></b> (Rate: <?= number_format((float)$h['rate'], 2) ?>)<br>
                        Ref. Faktur Beli (PI): <b><?= htmlspecialchars($h['pi_no'] ?: '-') ?></b><br>
                        Ref. Penerimaan (RI): <b><?= htmlspecialchars($h['ri_no'] ?: '-') ?></b><br>
                        Gudang Pengeluaran: <b><?= htmlspecialchars($h['gudang'] ?: 'UTAMA') ?></b><br>
                        Kompensasi: <b><?= $h['kompensasi'] === 'POTONG_HUTANG' ? 'Potong Hutang (Debit Note AP)' : 'Tukar Barang (Replacement)' ?></b>
                    </div>
                </td>
            </tr>
        </table>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 25px;">No</th>
                    <th style="width: 110px;">Kode Barang</th>
                    <th>Uraian / Nama Barang</th>
                    <th style="width: 50px;">Sat</th>
                    <th style="width: 60px;">Qty Retur</th>
                    <th style="width: 85px;">Harga Sat</th>
                    <th style="width: 50px;">Disc</th>
                    <th style="width: 95px;">Jumlah (<?= htmlspecialchars($h['kurs']) ?>)</th>
                    <th>Alasan Retur</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $seq = 1;
                foreach ($items as $it): 
                ?>
                <tr>
                    <td style="text-align: center;"><?= $seq++ ?></td>
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
                        <strong>Catatan / Alasan Retur:</strong><br>
                        <?= nl2br(htmlspecialchars($h['note'] ?: ($h['alasan_retur'] ?: 'Pengembalian barang rusak / tidak sesuai pesanan.'))) ?>
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
                            <td>PPN Retur:</td>
                            <td style="text-align: right;"><?= number_format((float)$h['ppn'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="grand">
                            <td>TOTAL NILAI RETUR:</td>
                            <td style="text-align: right; color: #ef4444;"><?= number_format((float)$h['total_order'], 2) ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="sign-grid">
            <tr>
                <td><strong>Petugas Gudang</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>QC Inspector</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>Purchasing Manager</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>Pemasok / Ekspedisi</strong><div class="sign-space"></div>( .................................. )</td>
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
                       WHERE (TIPE = 'S' OR TIPE = 'B' OR TIPE = 'VENDOR' OR TIPE IS NULL OR TIPE = '') 
                         AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                       ORDER BY NAMA ASC");
$vendors = [];
if ($bsQ) {
    while ($r = $bsQ->fetch_assoc()) $vendors[] = $r;
}

$gudangList = ['UTAMA', 'BAHAN BAKU', 'WIP', 'SCRAP'];
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

$currencies = $kursList;
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Return (PRT) - <?= htmlspecialchars($activeCmpName) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_searchable_select.css?v=20261101">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="/assets/sweetalert2.all.min.js"></script>
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
            --primary: #ef4444;          /* Merah/Rose Khas Purchase Return */
            --primary-hover: #dc2626;
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
            --primary: #ef4444;
            --primary-hover: #f87171;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #ff4d4f;
            --table-hover: #4c0519;
            --table-head-bg: #1d1d1d;
            --bg-input: #141414;
            --border-input: #434343;
            --row-selected: #881337;
            --row-selected-text: #ffe4e6;
            color-scheme: dark !important;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-base); color: var(--text-main); font-size: 10px; line-height: 1.45; -webkit-font-smoothing: antialiased; }
        button, input, select, textarea { font-family: inherit; font-size: 9.5px; font-weight: 500; color: inherit; }
        
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
        .ant-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .ant-card-head { min-height: 34px; padding: 4px 10px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; background: var(--bg-card); }
        .ant-card-title { font-size: 12px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }
        .ant-badge { font-size: 9px; padding: 1px 6px; border-radius: 10px; font-weight: 600; line-height: 1.2; text-transform: uppercase; }
        .ant-badge-company { background: rgba(239, 68, 68, 0.12); color: var(--primary); border: 1px solid rgba(239, 68, 68, 0.3); }
        .ant-badge-approved { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.3); }
        .ant-badge-draft { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.3); }
        .ant-badge-lock { background: rgba(239, 68, 68, 0.15); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.4); }

        /* Tabs */
        .ant-tabs-nav { display: flex; gap: 2px; padding: 0 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .ant-tab-btn { border: none; border-bottom: 2px solid transparent; background: transparent; color: var(--text-muted); padding: 5px 12px; cursor: pointer; font-size: 10px; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }

        /* Toolbar Buttons */
        .ant-toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .ant-btn { font-family: inherit; font-size: 9.5px; height: 21px; padding: 0 8px; border-radius: 2px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; line-height: 1.4; transition: all 0.15s; }
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
        
        /* Resizable Splitbar */
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
        .split-divider .split-handle, .splitbar .split-handle {
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
        .split-divider .split-btn-collapse, .splitbar .split-btn-collapse {
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
        .split-divider .split-btn-collapse:hover, .splitbar .split-btn-collapse:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        /* 2-Column Compact Header Grid */
        .hdr-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 8px;
            padding: 6px 10px;
            font-size: 9.5px;
        }
        .hdr-col { display: flex; flex-direction: column; gap: 4px; }
        .fld-row { display: flex; align-items: center; gap: 6px; min-height: 22px; }
        .fld-row label { width: 95px; flex-shrink: 0; font-size: 9.5px; color: var(--text-muted); font-weight: 500; }
        .fld-row .fld-input { flex: 1; min-width: 0; }
        .fld-row input, .fld-row select, .fld-row textarea {
            width: 100%;
            height: 21px;
            padding: 2px 6px;
            border: 1px solid var(--border-input);
            background: var(--bg-input);
            color: var(--text-main);
            border-radius: 2px;
            outline: none;
            transition: border-color 0.15s;
        }
        .fld-row textarea { height: 38px; resize: none; font-size: 9px; line-height: 1.3; }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus { border-color: var(--primary); }
        .fld-row input[readonly] { background: var(--bg-base); cursor: not-allowed; }

        .pair-box { display: flex; gap: 4px; align-items: center; width: 100%; }
        .checks-box { display: flex; align-items: center; gap: 10px; margin-left: 95px; font-size: 9px; }
        .checks-box label { width: auto !important; color: var(--text-main) !important; cursor: pointer; display: flex; align-items: center; gap: 3px; }

        .highlight-inv-no {
            font-weight: 700;
            color: #b91c1c;
            border-color: #f87171 !important;
            background: #fff1f2 !important;
        }
        body.dark-mode .highlight-inv-no, html.dark-mode .highlight-inv-no {
            color: #fca5a5 !important;
            background: #4c0519 !important;
            border-color: #9f1239 !important;
        }

        /* Banner Lock IT Inventory */
        .lock-banner {
            background: #fee2e2;
            border: 1px solid #f87171;
            color: #991b1b;
            padding: 3px 10px;
            font-size: 9.5px;
            font-weight: 600;
            display: none;
            align-items: center;
            justify-content: space-between;
        }
        body.dark-mode .lock-banner { background: #450a0a; border-color: #7f1d1d; color: #fca5a5; }

        /* Tables & Enterprise DataGrid */
        .table-wrap { flex: 1 1 auto; min-height: 0; overflow: auto; position: relative; background: var(--bg-card); }
        table.dw { width: 100%; border-collapse: collapse; font-size: 9.5px; white-space: nowrap; }
        table.dw thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            padding: 4px 6px;
            font-weight: 600;
            text-align: left;
            user-select: none;
            color: var(--text-main);
        }
        table.dw thead th:last-child { border-right: none; }
        table.dw tbody tr { border-bottom: 1px solid var(--border-light); transition: background 0.1s; }
        table.dw tbody tr:hover { background: var(--table-hover); }
        table.dw tbody tr.selected { background: var(--row-selected) !important; color: var(--row-selected-text) !important; }
        table.dw tbody td { padding: 2px 5px; border-right: 1px solid var(--border-light); vertical-align: middle; }
        table.dw tbody td:last-child { border-right: none; }
        table.dw input, table.dw select {
            width: 100%;
            height: 21px;
            border: 1px solid transparent;
            background: transparent;
            color: inherit;
            padding: 1px 4px;
            outline: none;
        }
        table.dw tr:hover input, table.dw tr:hover select, table.dw input:focus, table.dw select:focus {
            border-color: var(--border-input);
            background: var(--bg-input);
        }

        /* Footer Finansial */
        .ftr-grid { display: grid; grid-template-columns: 1.15fr 1fr; gap: 8px; padding: 4px 10px; font-size: 9.5px; height: 100%; }
        .ftr-left { display: flex; flex-direction: column; gap: 4px; }
        .ftr-right { display: flex; flex-direction: column; justify-content: flex-end; }
        .terbilang-callout {
            background: var(--bg-base);
            border-left: 3px solid var(--primary);
            padding: 4px 8px;
            font-style: italic;
            color: var(--text-muted);
            border-radius: 0 2px 2px 0;
            font-size: 9px;
            line-height: 1.35;
        }
        .sum-box {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3px 8px;
            background: var(--bg-base);
            padding: 4px 8px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
        }
        .sum-row { display: flex; justify-content: space-between; align-items: center; min-height: 19px; }
        .sum-row.grand {
            grid-column: span 2;
            border-top: 1px solid var(--border-color);
            padding-top: 3px;
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
        }

        /* Browse Data Page */
        .browse-filter { padding: 4px 8px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .kpi-strip { display: flex; gap: 6px; padding: 6px 8px; background: var(--bg-base); border-bottom: 1px solid var(--border-color); }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; padding: 4px 8px; flex: 1; display: flex; flex-direction: column; }
        .kpi-card .lbl { font-size: 8.5px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
        .kpi-card .val { font-size: 12px; font-weight: 700; color: var(--text-main); margin-top: 2px; }

        /* Modals */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; width: 620px; max-width: 95vw; max-height: 88vh; display: flex; flex-direction: column; box-shadow: 0 8px 24px rgba(0,0,0,0.25); overflow: hidden; }
        .modal-card.wide { width: 920px; }
        .modal-card.extra-wide { width: 1100px; }
        .modal-h { padding: 6px 12px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; font-weight: 600; font-size: 11px; }
        .modal-b { padding: 8px 12px; overflow: auto; flex: 1; font-size: 9.5px; }
        .modal-f { padding: 6px 12px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-base); }
        
        .klas-row { display: flex; gap: 4px; margin-bottom: 8px; }
        .klas-btn { flex: 1; padding: 3px 6px; font-size: 9px; text-align: center; }
        .klas-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 600; }

        /* Context Menu */
        .context-menu { display: none; position: fixed; z-index: 2000; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 170px; padding: 2px 0; }
        .context-menu-item { padding: 4px 10px; display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 9.5px; color: var(--text-main); }
        .context-menu-item:hover { background: var(--table-hover); color: var(--primary); }
        .context-menu-divider { height: 1px; background: var(--border-light); margin: 2px 0; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .muted { color: var(--text-muted); }
        .inline-box { display: flex; gap: 4px; align-items: center; flex: 1; min-width: 0; width: 100%; }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- HEADER UTAMA MODUL IDENTIK SI & PI -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-rotate-left" style="color:var(--primary); font-size:13px;"></i>
                <span>RETUR PEMBELIAN (PURCHASE RETURN)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-draft" id="badgePrStatus">DRAFT</span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="newPr()"><i class="fa-solid fa-file-circle-plus"></i> + Baru</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="savePr()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                <button type="button" class="ant-btn ant-btn-danger" onclick="deletePr()"><i class="fa-solid fa-trash-can"></i> Hapus</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="printPr()"><i class="fa-solid fa-print"></i> Cetak Bukti Retur</button>
                
                <!-- Dropdown Excel -->
                <div style="position:relative; display:inline-block;">
                    <button type="button" class="ant-btn" onclick="toggleExcelMenu()"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Excel <i class="fa-solid fa-caret-down"></i></button>
                    <div id="excelDrop" class="context-menu" style="top:23px; right:0;">
                        <div class="context-menu-item" onclick="exportExcelSheetJS()"><i class="fa-solid fa-file-export"></i> Ekspor Data (.xlsx)</div>
                        <div class="context-menu-item" onclick="downloadExcelTemplate()"><i class="fa-solid fa-download"></i> Unduh Format Template</div>
                        <div class="context-menu-item" onclick="triggerImportExcel()"><i class="fa-solid fa-file-import"></i> Impor dari Excel</div>
                    </div>
                </div>

                <button type="button" class="ant-btn" onclick="openLookupPi()"><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Tarik PI</button>
                <button type="button" class="ant-btn" onclick="openLookupRi()"><i class="fa-solid fa-dolly" style="color:#0ea5e9;"></i> Tarik RI</button>
                
                <!-- FITUR INOVATIF: REKAP PO & HUTANG VENDOR -->
                <button type="button" class="ant-btn" onclick="openVendorApModal()" style="border-color:#faad14; color:#d48806; font-weight:600;"><i class="fa-solid fa-chart-pie"></i> Rekap PO & Hutang</button>

                <!-- FITUR INOVATIF: INTEGRASI DIRECT STAGING CEISA 4.0 -->
                <button type="button" class="ant-btn" onclick="openCeisaDraftModal()" style="border-color:#10b981; color:#059669; font-weight:600;" title="Otomatis Dorong Data Retur ke Staging Dokumen BC CEISA 4.0"><i class="fa-solid fa-cloud-arrow-up"></i> 🚀 Buat Draft BC</button>

                <button type="button" class="ant-btn ant-btn-warning" onclick="postItInventory()" id="btnPostIt"><i class="fa-solid fa-boxes-packing"></i> Posting IT Inv</button>
                <button type="button" class="ant-btn" onclick="unlockItInventory()" id="btnUnlockIt" style="display:none;"><i class="fa-solid fa-lock-open"></i> Buka Kunci IT Inv</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul & SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- TABS NAVIGASI 3-TAB IDENTIK SI & PI -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-page="pageEntry" onclick="switchPage('pageEntry')"><i class="fa-solid fa-pen-to-square"></i> Entri (Retur Pembelian)</button>
            <button type="button" class="ant-tab-btn" data-page="pageBrowse" onclick="switchPage('pageBrowse')"><i class="fa-solid fa-table-list"></i> Browse Data</button>
            <button type="button" class="ant-tab-btn" data-page="pageTrace" onclick="switchPage('pageTrace')"><i class="fa-solid fa-diagram-project"></i> Trace Faktur, Penerimaan & BC</button>
        </div>

        <!-- TAB 1: FORM TRANSAKSI RETUR (IDENTIK DENGAN SPLITTERS & DUAL PANE) -->
        <section class="ant-page active" id="pageEntry">
            <div id="prLock" class="lock-banner">
                <span><i class="fa-solid fa-lock"></i> Dokumen Retur ini SUDAH DIPOSTING ke IT Inventory (bc_1ab2, AB='B'). Formulir terkunci dalam mode Read-Only.</span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="unlockItInventory()" style="height:18px; padding:0 6px; font-size:9px;">Buka Kunci</button>
            </div>

            <div class="split-col">
                <!-- PANE 1: HEADER FORM 2-KOLOM -->
                <div class="split-pane" id="paneHdr">
                    <form id="formPr" autocomplete="off" onsubmit="return false;">
                        <input type="hidden" id="prId">
                        <input type="hidden" id="prStatus" value="0">
                        <input type="hidden" id="prIsLock" value="N">

                        <div class="hdr-grid">
                            <!-- Kolom Kiri: Vendor, Alamat, Kompensasi, Ref Dokumen -->
                            <div class="hdr-col">
                                <div class="fld-row">
                                    <label for="prVendor">Pemasok / Vendor *</label>
                                    <div class="fld-input inline-box">
                                        <select id="prVendor" class="ant-searchable-select" style="flex:1;" onchange="onVendorChange(this.value)">
                                            <option value="">-- Pilih Pemasok / Vendor --</option>
                                            <?php foreach ($vendors as $v): ?>
                                                <option value="<?= htmlspecialchars($v['KODE']) ?>" data-alamat="<?= htmlspecialchars($v['ALAMAT'] ?? '') ?>" data-npwp="<?= htmlspecialchars($v['NPWP'] ?? '') ?>" data-top="<?= htmlspecialchars($v['TOP'] ?? 'COD') ?>" data-kurs="<?= htmlspecialchars($v['CURRENCY'] ?? 'IDR') ?>">
                                                    <?= htmlspecialchars($v['KODE']) ?> - <?= htmlspecialchars($v['NAMA']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="ant-btn ant-btn-sm" onclick="openVendorApModal()" title="Lihat Rekap Hutang & PO Vendor"><i class="fa-solid fa-chart-pie" style="color:#d48806;"></i></button>
                                        <button type="button" class="ant-btn ant-btn-sm" onclick="openLookupPi()" title="Tarik dari Faktur Pembelian (PI)"><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> PI</button>
                                        <button type="button" class="ant-btn ant-btn-sm" onclick="openLookupRi()" title="Tarik dari Penerimaan Barang (RI)"><i class="fa-solid fa-dolly" style="color:#0ea5e9;"></i> RI</button>
                                    </div>
                                </div>
                                <div class="checks-box">
                                    <label><input type="radio" name="radKompensasi" id="kompPotong" value="POTONG_HUTANG" checked onchange="toggleKompensasi()"> <b>Potong Hutang (Debit Note AP)</b></label>
                                    <label><input type="radio" name="radKompensasi" id="kompGanti" value="GANTI_BARANG" onchange="toggleKompensasi()"> Tukar Barang Baru (Replacement)</label>
                                    <label><input type="checkbox" id="prTaxable" onchange="calcPrSummary()"> Termasuk PPN</label>
                                </div>
                                <div class="fld-row">
                                    <label for="prAlamat">Alamat Pemasok</label>
                                    <div class="fld-input">
                                        <input type="text" id="prAlamat" readonly placeholder="Alamat pemasok otomatis...">
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="prRefPi">Ref. No. PI / RI</label>
                                    <div class="fld-input pair-box">
                                        <input type="text" id="prRefPi" placeholder="No. Faktur PI acuan..." style="flex:1;">
                                        <input type="text" id="prRefRi" placeholder="No. Penerimaan RI acuan..." style="flex:1;">
                                    </div>
                                </div>
                                <div class="fld-row" id="rowPiTarget">
                                    <label for="prPiTarget">Target Faktur Dipotong</label>
                                    <div class="fld-input inline-box">
                                        <input type="text" id="prPiTarget" placeholder="Nomor Faktur PI yang saldonya dikurangi retur ini..." style="flex:1;">
                                        <button type="button" class="ant-btn ant-btn-sm" onclick="choosePiTargetFromVendor()" title="Pilih Faktur Unpaid"><i class="fa-solid fa-list-check"></i> Pilih</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Kolom Kanan: Nomor Retur, Tanggal, Kepabeanan, Gudang -->
                            <div class="hdr-col">
                                <div class="fld-row">
                                    <label for="prNo">No. Retur (PRT) *</label>
                                    <div class="fld-input inline-box">
                                        <input type="text" id="prNo" class="highlight-inv-no" placeholder="PRT-YYMM-XXXX" style="flex:1;">
                                        <button type="button" class="ant-btn ant-btn-sm" onclick="generateNo()" title="Auto-generate Nomor"><i class="fa-solid fa-rotate"></i></button>
                                        <span style="font-size:9px; color:var(--text-muted); margin-left:4px;">Kurs:</span>
                                        <select id="prKurs" style="width:65px;" onchange="onKursChange()">
                                            <?php foreach ($currencies as $c): ?>
                                                <option value="<?= htmlspecialchars($c) ?>" <?= $c === 'IDR' ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" id="prRate" value="1.00" step="0.01" style="width:65px; text-align:right;" oninput="calcPrSummary()">
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="prDt">Tgl Retur / Kirim</label>
                                    <div class="fld-input pair-box">
                                        <input type="date" id="prDt" value="<?= $today ?>">
                                        <input type="date" id="prShipDt" value="<?= $today ?>" title="Tanggal Pengeluaran Fisik Barang">
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="prBc">Dokumen Pabean</label>
                                    <div class="fld-input inline-box">
                                        <select id="prBc" style="width:110px;">
                                            <option value="">-- Non BC --</option>
                                            <option value="2.7">BC 2.7 (Keluar KB)</option>
                                            <option value="4.1">BC 4.1 (Keluar TLDDP)</option>
                                            <option value="2.6.1">BC 2.6.1 (Subkon Keluar)</option>
                                            <option value="3.0">BC 3.0 (Re-Ekspor)</option>
                                            <option value="2.8">BC 2.8 (Keluar PLB)</option>
                                        </select>
                                        <input type="text" id="prNoBc" placeholder="No. Daftar BC" style="flex:1;">
                                        <input type="date" id="prTglBc" style="width:110px;" title="Tanggal Pendaftaran BC">
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="prNoAju">Nomor Aju (26 Digit)</label>
                                    <div class="fld-input inline-box">
                                        <input type="text" id="prNoAju" maxlength="26" placeholder="000023-......-2026...." style="flex:1;">
                                        <button type="button" class="ant-btn ant-btn-sm" id="btnOpenEditor" onclick="openCeisaEditorDirect()" style="display:none; color:#059669; font-weight:600; padding:0 6px;" title="Buka Dokumen AJU ini langsung di Editor CEISA 4.0"><i class="fa-solid fa-arrow-up-right-from-square"></i> Editor BC</button>
                                        <span style="font-size:9px; color:var(--text-muted);">Gudang:</span>
                                        <select id="prGudang" style="width:90px;">
                                            <?php foreach ($gudangList as $g): ?>
                                                <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- SPLITBAR 1 (HEADER <-> ITEMS) -->
                <div class="splitbar" id="splitHdr" title="Geser untuk mengatur tinggi form header">
                    <button type="button" class="split-btn-collapse" onclick="togglePaneCollapse('paneHdr', this)" title="Ciutkan / Bentangkan Header"><i class="fa-solid fa-chevron-up"></i></button>
                    <div class="split-handle"></div>
                </div>

                <!-- PANE 2: RINCIAN BARANG RETUR (TABLE GRID) -->
                <div class="split-pane fill" id="paneGrid">
                    <div class="table-wrap" oncontextmenu="openContextMenu(event)">
                        <table class="dw" id="tblItems">
                            <thead>
                                <tr>
                                    <th style="width:28px; text-align:center;">#</th>
                                    <th style="width:130px;">Kode Barang</th>
                                    <th>Uraian / Nama Barang</th>
                                    <th style="width:60px; text-align:center;">Satuan</th>
                                    <th style="width:85px; text-align:right;">Qty Retur</th>
                                    <th style="width:95px; text-align:right;">Harga Satuan</th>
                                    <th style="width:65px; text-align:right;">Diskon %</th>
                                    <th style="width:105px; text-align:right;">Jumlah Nilai</th>
                                    <th style="width:100px;">Ref No. PI</th>
                                    <th style="width:100px;">Ref No. RI</th>
                                    <th>Alasan / Catatan Baris</th>
                                    <th style="width:30px; text-align:center;"><i class="fa-solid fa-gear"></i></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <!-- Dynamic item rows -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SPLITBAR 2 (ITEMS <-> FOOTER) -->
                <div class="splitbar" id="splitFtr" title="Geser untuk mengatur tinggi ringkasan">
                    <button type="button" class="split-btn-collapse" onclick="togglePaneCollapse('paneFtr', this)" title="Ciutkan / Bentangkan Ringkasan"><i class="fa-solid fa-chevron-down"></i></button>
                    <div class="split-handle"></div>
                </div>

                <!-- PANE 3: FOOTER RINGKASAN FINANSIAL & AUTO TERBILANG -->
                <div class="split-pane" id="paneFtr">
                    <div class="ftr-grid">
                        <div class="ftr-left">
                            <div class="inline-box">
                                <span style="font-size:9.5px; font-weight:600; color:var(--text-muted); width:80px;">Alasan/Memo:</span>
                                <input type="text" id="prNote" placeholder="Catatan tagihan retur / alasan pengembalian barang..." style="flex:1;">
                            </div>
                            <div class="terbilang-callout" id="boxTerbilang">
                                <strong>Terbilang (Say):</strong> <span id="txtTerbilang"># Nol Rupiah #</span>
                            </div>
                            <div class="inline-box" style="margin-top:2px;">
                                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="addItemRow()"><i class="fa-solid fa-plus"></i> Tambah Baris</button>
                                <button type="button" class="ant-btn ant-btn-sm" onclick="openMaterialPicker()"><i class="fa-solid fa-boxes-stacked"></i> Pilih Master Barang</button>
                                <button type="button" class="ant-btn ant-btn-sm" onclick="openLookupPi()"><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Tarik PI</button>
                                <button type="button" class="ant-btn ant-btn-sm" onclick="openLookupRi()"><i class="fa-solid fa-dolly" style="color:#0ea5e9;"></i> Tarik RI</button>
                                <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="clearAllItems()"><i class="fa-solid fa-eraser"></i> Bersihkan Semua</button>
                            </div>
                        </div>

                        <div class="ftr-right">
                            <div class="sum-box">
                                <div class="sum-row">
                                    <span class="muted">Subtotal:</span>
                                    <b id="txtSubtotal">0.00</b>
                                </div>
                                <div class="sum-row">
                                    <span class="muted">Diskon Header (%):</span>
                                    <div class="inline-box">
                                        <input type="number" id="prDiscPersen" value="0.00" step="0.01" style="width:40px; text-align:right;" oninput="calcPrSummary()">
                                        <span id="txtDiscAmt">0.00</span>
                                    </div>
                                </div>
                                <div class="sum-row">
                                    <span class="muted">DPP Retur:</span>
                                    <b id="txtDpp">0.00</b>
                                </div>
                                <div class="sum-row">
                                    <span class="muted">PPN Retur (11%):</span>
                                    <b id="txtPpn">0.00</b>
                                </div>
                                <div class="sum-row">
                                    <span class="muted">Biaya Kirim:</span>
                                    <input type="number" id="prFreight" value="0.00" step="0.01" style="width:75px; text-align:right;" oninput="calcPrSummary()">
                                </div>
                                <div class="sum-row">
                                    <span class="muted">Potong Hutang:</span>
                                    <span id="txtKompensasiBadge" style="color:var(--success); font-weight:600;">Ya (Debit Note)</span>
                                </div>
                                <div class="sum-row grand">
                                    <span>GRAND TOTAL RETUR:</span>
                                    <span id="txtGrandTotal">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 2: BROWSE DATA RETUR PEMBELIAN -->
        <section class="ant-page" id="pageBrowse">
            <!-- KPI Strip -->
            <div class="kpi-strip">
                <div class="kpi-card">
                    <span class="lbl">Total Dokumen Retur</span>
                    <span class="val" id="kpiTotalPr">0</span>
                </div>
                <div class="kpi-card">
                    <span class="lbl">Total Nilai Retur (IDR)</span>
                    <span class="val" id="kpiSumTotal" style="color:var(--primary);">0.00</span>
                </div>
                <div class="kpi-card">
                    <span class="lbl">Draft / Belum Disetujui</span>
                    <span class="val" id="kpiTotalDraft" style="color:var(--warning);">0</span>
                </div>
                <div class="kpi-card">
                    <span class="lbl">Approved / Terposting</span>
                    <span class="val" id="kpiTotalApproved" style="color:var(--success);">0</span>
                </div>
                <div class="kpi-card">
                    <span class="lbl">Batal / Void</span>
                    <span class="val" id="kpiTotalVoid" style="color:var(--danger);">0</span>
                </div>
            </div>

            <!-- 1-Row Compact Filter Toolbar -->
            <div class="browse-filter">
                <span class="muted">Periode:</span>
                <input type="date" id="filterTglAwal" style="height:21px; padding:0 4px;">
                <span class="muted">s/d</span>
                <input type="date" id="filterTglAkhir" style="height:21px; padding:0 4px;">
                <span class="muted" style="margin-left:6px;">Status:</span>
                <select id="filterStatus" style="height:21px;">
                    <option value="ALL">-- Semua Status --</option>
                    <option value="0">Draft</option>
                    <option value="1">Approved / Selesai</option>
                    <option value="9">Batal / Void</option>
                </select>
                <span class="muted" style="margin-left:6px;">Pemasok:</span>
                <select id="filterVendor" class="ant-searchable-select" style="width:160px;">
                    <option value="">-- Semua Pemasok --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= htmlspecialchars($v['KODE']) ?>"><?= htmlspecialchars($v['NAMA']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="filterSearch" placeholder="Cari No. PRT, PI, No BC, Aju, Note..." style="width:200px; height:21px; padding:0 6px;" onkeydown="if(event.key==='Enter') loadBrowseData(1)">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadBrowseData(1)"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <button type="button" class="ant-btn" onclick="resetBrowseFilter()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                <div style="flex:1;"></div>
                <span class="muted" id="txtTotalBrowse">Total: 0</span>
                <select id="filterLimit" style="height:21px;" onchange="loadBrowseData(1)">
                    <option value="25">25 baris</option>
                    <option value="50">50 baris</option>
                    <option value="100">100 baris</option>
                    <option value="200">200 baris</option>
                </select>
            </div>

            <!-- Browse Table Grid -->
            <div class="table-wrap">
                <table class="dw" id="tblBrowse">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th style="width:120px;">No. Retur</th>
                            <th style="width:80px;">Tgl Retur</th>
                            <th>Pemasok / Vendor</th>
                            <th style="width:50px; text-align:center;">Kurs</th>
                            <th style="width:105px; text-align:right;">Subtotal</th>
                            <th style="width:90px; text-align:right;">PPN</th>
                            <th style="width:115px; text-align:right;">Total Nilai</th>
                            <th style="width:70px; text-align:center;">Dok. BC</th>
                            <th style="width:90px;">No. Daftar BC</th>
                            <th style="width:80px;">Tgl BC</th>
                            <th style="width:75px; text-align:center;">IT Inv</th>
                            <th style="width:75px; text-align:center;">Status</th>
                            <th style="width:60px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="browseBody">
                        <tr><td colspan="14" class="muted text-center" style="padding:16px;">Memuat data retur...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginasi Cerdas -->
            <div class="modal-f" style="border-top:1px solid var(--border-color); padding:4px 10px;">
                <span class="muted" id="txtPageInfo">Halaman 1 dari 1</span>
                <div class="inline-box">
                    <button type="button" class="ant-btn ant-btn-sm" id="btnPagePrev" onclick="navigateBrowsePage(-1)"><i class="fa-solid fa-angle-left"></i> Prev</button>
                    <span id="txtCurrentPage" style="font-weight:600; padding:0 4px;">1</span>
                    <button type="button" class="ant-btn ant-btn-sm" id="btnPageNext" onclick="navigateBrowsePage(1)">Next <i class="fa-solid fa-angle-right"></i></button>
                </div>
            </div>
        </section>

        <!-- TAB 3: TRACEABILITY DOKUMEN (PRT <-> PI <-> RI <-> PO <-> BC) -->
        <section class="ant-page" id="pageTrace">
            <div style="padding:8px 12px; background:var(--bg-base); border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:600;"><i class="fa-solid fa-diagram-project" style="color:var(--primary);"></i> Matriks Keterlacakan Dokumen (4-Way Procurement Traceability)</span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="reloadTrace()"><i class="fa-solid fa-rotate"></i> Muat Ulang Trace</button>
            </div>
            <div class="modal-b" id="traceContainer" style="padding:14px;">
                <div class="muted text-center" style="padding:20px;">Buka atau simpan dokumen retur untuk melihat peta keterlacakan dokumen pabean & pengadaan.</div>
            </div>
        </section>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: REKAP PO & HUTANG VENDOR (VENDOR 360° AP EXPOSURE)                 -->
<!-- ========================================================================= -->
<div class="modal" id="modalVendorAp">
    <div class="modal-card extra-wide">
        <div class="modal-h" style="background:#fffbe6; color:#d48806; border-bottom:1px solid #ffe58f;">
            <span><i class="fa-solid fa-chart-pie"></i> Rekap Status PO & Hutang Dagang Vendor (Vendor 360° AP Exposure)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeVendorApModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <!-- Vendor Info Strip -->
            <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:3px; padding:6px 10px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <strong style="font-size:12px;" id="apVendorName">-</strong>
                    <div class="muted" id="apVendorDetail">Kode: - | Alamat: - | NPWP: -</div>
                </div>
                <div class="inline-box" style="gap:14px;">
                    <div style="text-align:right;">
                        <span class="muted" style="font-size:8.5px; text-transform:uppercase;">Total Hutang Unpaid (AP):</span>
                        <div style="font-size:13px; font-weight:700; color:var(--danger);" id="apTotalHutang">0.00</div>
                    </div>
                    <div style="text-align:right;">
                        <span class="muted" style="font-size:8.5px; text-transform:uppercase;">Total Open PO Aktif:</span>
                        <div style="font-size:13px; font-weight:700; color:var(--primary);" id="apTotalOpenPo">0.00</div>
                    </div>
                    <div style="text-align:right;">
                        <span class="muted" style="font-size:8.5px; text-transform:uppercase;">Retur Belum Dikompensasi:</span>
                        <div style="font-size:13px; font-weight:700; color:#d48806;" id="apTotalReturOpen">0.00</div>
                    </div>
                </div>
            </div>

            <!-- Sub-Heading 1: Faktur Pembelian Belum Lunas (Siap Dipotong Retur) -->
            <div style="font-weight:600; font-size:10.5px; margin:8px 0 4px; display:flex; justify-content:space-between;">
                <span><i class="fa-solid fa-file-invoice-dollar" style="color:var(--danger);"></i> Faktur Pembelian (PI) Belum Lunas (Siap Dikompensasikan)</span>
                <small class="muted">Klik tombol "Pilih untuk Dipotong" untuk mengarahkan retur ke tagihan tersebut</small>
            </div>
            <div class="grid-wrap" style="max-height:200px; border:1px solid var(--border-color); border-radius:3px; margin-bottom:12px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th>No. Faktur (PI)</th>
                            <th>Tgl Faktur</th>
                            <th>Jatuh Tempo</th>
                            <th style="text-align:center;">Term</th>
                            <th style="text-align:center;">Kurs</th>
                            <th style="text-align:right;">Total Tagihan</th>
                            <th style="text-align:right;">Terbayar</th>
                            <th style="text-align:right;">Sisa Hutang</th>
                            <th style="text-align:center; width:120px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="apUnpaidPiBody">
                        <tr><td colspan="9" class="muted text-center" style="padding:10px;">Pilih vendor untuk memuat tagihan.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Sub-Heading 2: Purchase Order Aktif (Open PO) -->
            <div style="font-weight:600; font-size:10.5px; margin:8px 0 4px;">
                <i class="fa-solid fa-cart-shopping" style="color:var(--primary);"></i> Purchase Order (PO) Aktif / Berjalan
            </div>
            <div class="grid-wrap" style="max-height:160px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th>No. PO</th>
                            <th>Tgl PO</th>
                            <th style="text-align:center;">Kurs</th>
                            <th style="text-align:right;">Total Nilai PO</th>
                            <th style="text-align:center;">Item</th>
                            <th style="text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="apOpenPoBody">
                        <tr><td colspan="6" class="muted text-center" style="padding:10px;">Pilih vendor untuk memuat PO.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted"><i class="fa-solid fa-circle-info"></i> Memotong tagihan hutang akan otomatis memperbarui saldo kartu hutang vendor saat diaudit.</span>
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeVendorApModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: TARIK FAKTUR PEMBELIAN (PI / INVOICE) UNTUK DIRETUR                -->
<!-- ========================================================================= -->
<div class="modal" id="modalLookupPi">
    <div class="modal-card extra-wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Tarik Barang dari Faktur Pembelian (Purchase Invoice / PI)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeLookupPi()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="searchPiText" style="flex:1;" placeholder="Cari No. Faktur PI, Kode Barang, Uraian..." onkeydown="if(event.key==='Enter') searchPiForReturn()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchPiForReturn()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;"><input type="checkbox" id="chkAllPi" onchange="toggleCheckAllPi(this.checked)"></th>
                            <th>No. Faktur (PI)</th>
                            <th>Tgl Faktur</th>
                            <th>Pemasok</th>
                            <th>No. RI</th>
                            <th>No. PO</th>
                            <th>Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="text-align:right;">Qty Faktur</th>
                            <th style="text-align:center;">Sat</th>
                            <th style="text-align:right;">Harga Sat</th>
                            <th style="text-align:right;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody id="lookupPiBody">
                        <tr><td colspan="12" class="muted text-center" style="padding:12px;">Pilih pemasok dan klik Cari untuk memuat faktur pembelian.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" id="txtCountSelectedPi">0 item terpilih</span>
            <div class="inline-box">
                <button type="button" class="ant-btn" onclick="closeLookupPi()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="confirmSelectPi()"><i class="fa-solid fa-arrow-down"></i> Tarik Item Terpilih</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: TARIK PENERIMAAN BARANG (RI / GRN GUDANG)                          -->
<!-- ========================================================================= -->
<div class="modal" id="modalLookupRi">
    <div class="modal-card extra-wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-dolly" style="color:#0ea5e9;"></i> Tarik Penerimaan Barang Fisik Gudang (Goods Receipt / RI)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeLookupRi()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="searchRiText" style="flex:1;" placeholder="Cari No. RI, No. Surat Jalan, PO, atau Barang..." onkeydown="if(event.key==='Enter') searchRiForReturn()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchRiForReturn()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;"><input type="checkbox" id="chkAllRi" onchange="toggleCheckAllRi(this.checked)"></th>
                            <th>No. RI</th>
                            <th>Tgl Terima</th>
                            <th>Pemasok</th>
                            <th>No. SJ</th>
                            <th>No. PO</th>
                            <th>Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="text-align:right;">Qty RI</th>
                            <th style="text-align:center;">Sat</th>
                            <th style="text-align:right;">Harga Sat</th>
                        </tr>
                    </thead>
                    <tbody id="lookupRiBody">
                        <tr><td colspan="11" class="muted text-center" style="padding:12px;">Ketik pencarian atau klik Cari.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" id="txtCountSelectedRi">0 item terpilih</span>
            <div class="inline-box">
                <button type="button" class="ant-btn" onclick="closeLookupRi()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="confirmSelectRi()"><i class="fa-solid fa-arrow-down"></i> Tarik Item Terpilih</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: KATALOG MASTER BARANG (5 KLASIFIKASI: RM, FG, WIP, CG, SCRAP)      -->
<!-- ========================================================================= -->
<div class="modal" id="modalMaterial">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Katalog Master Barang</span>
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
                <input type="text" id="materialSearch" style="flex:1;" placeholder="Ketik kode barang atau uraian..." onkeydown="if(event.key==='Enter'){event.preventDefault(); searchMaterials();}">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchMaterials()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:140px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width:60px;">Satuan</th>
                            <th style="width:100px; text-align:right;">Harga Satuan</th>
                            <th style="width:80px; text-align:right;">Berat(Kg)</th>
                            <th style="width:110px;">Klasifikasi</th>
                        </tr>
                    </thead>
                    <tbody id="materialBody">
                        <tr><td colspan="6" class="muted text-center" style="padding:12px;">Mencari katalog barang...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <label class="muted" style="display:flex; gap:6px; align-items:center; cursor:pointer;"><input type="checkbox" id="materialMulti"> Multi Pilih (Tetap buka popup setelah memilih)</label>
            <button type="button" class="ant-btn" onclick="closeMaterialPicker()">Tutup</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 5: PANDUAN & SOP MODUL RETUR PEMBELIAN                               -->
<!-- ========================================================================= -->
<div class="modal" id="modalGuide">
    <div class="modal-card wide">
        <div class="modal-h" style="background:var(--primary); color:#fff;">
            <span><i class="fa-solid fa-circle-question"></i> Panduan Operasional & SOP Retur Pembelian (PRT)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()" style="color:#fff; border-color:transparent; background:transparent;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="line-height:1.6; font-size:10px;">
            <h4 style="color:var(--primary); margin-bottom:4px;">1. Alur Bisnis Retur Pembelian Kawasan Berikat</h4>
            <p>Retur Pembelian (PRT) digunakan untuk mencatat pengembalian material/barang kepada pemasok akibat cacat produk, rusak fisik, atau kelebihan pasok.</p>
            
            <h4 style="color:var(--primary); margin:10px 0 4px;">2. Mekanisme Kompensasi Finansial</h4>
            <ul style="padding-left:18px;">
                <li><b>Potong Hutang (Debit Note AP):</b> Nilai retur langsung mengurangi saldo tagihan Faktur Pembelian (PI) vendor di buku besar hutang usaha.</li>
                <li><b>Tukar Barang (Replacement):</b> Menandakan vendor akan mengirimkan barang pengganti, sehingga kuantitas PO/RI terkait kembali terbuka.</li>
            </ul>

            <h4 style="color:var(--primary); margin:10px 0 4px;">3. Ketentuan Kepabeanan CEISA 4.0 & IT Inventory</h4>
            <ul style="padding-left:18px;">
                <li>Pengeluaran fisik barang dari Kawasan Berikat wajib dilengkapi dokumen pabean pengeluaran resmi: <b>BC 2.7</b> (ke sesama KB/TPB), <b>BC 4.1</b> (ke TLDDP), atau <b>BC 3.0 / 2.8</b> (Re-Ekspor).</li>
                <li>Setelah nomor pendaftaran BC terbit, klik tombol <b>Posting IT Inv</b> untuk mencatatkan mutasi keluar pada buku persediaan <code>bc_1ab2</code> (AB='B'). Dokumen otomatis terkunci demi kepatuhan audit.</li>
            </ul>
        </div>
        <div class="modal-f">
            <span class="muted">SOP CEISA 4.0 & IT Inventory Compliance</span>
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Saya Mengerti</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 6: BUAT DRAFT DOKUMEN BC KE STAGING CEISA 4.0                        -->
<!-- ========================================================================= -->
<div class="modal" id="modalCeisaDraft">
    <div class="modal-card" style="width:480px;">
        <div class="modal-h" style="background:#059669; color:#fff;">
            <span><i class="fa-solid fa-cloud-arrow-up"></i> Buat Draft Dokumen Pabean BC (CEISA 4.0 Staging)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeCeisaDraftModal()" style="color:#fff; border-color:transparent; background:transparent;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="line-height:1.6; font-size:10px;">
            <p style="margin-bottom:10px; color:var(--text-muted);">
                Sistem akan menyusun dan memasukkan data transaksi retur ini ke tabel staging CEISA 4.0 (<code>bc_ceisa_header</code>, <code>bc_ceisa_barang</code>, <code>bc_ceisa_dokumen</code>, <code>bc_ceisa_kemasan</code>, dan <code>bc_ceisa_entitas</code>) sehingga siap diproses dan dikirim ke Bea Cukai.
            </p>
            <div class="fld-row" style="margin-bottom:6px;">
                <label style="width:130px; font-weight:600;">Jenis Dokumen BC *</label>
                <div class="fld-input">
                    <select id="draftBcType" style="width:100%;">
                        <option value="2.7">BC 2.7 - Pengeluaran Antar TPB / Kawasan Berikat</option>
                        <option value="4.1">BC 4.1 - Pengeluaran ke TLDDP / Vendor Lokal</option>
                        <option value="2.6.1">BC 2.6.1 - Pengeluaran Barang Subkontrak</option>
                        <option value="3.0">BC 3.0 - Pemberitahuan Ekspor / Re-Ekspor Luar Negeri</option>
                        <option value="2.8">BC 2.8 - Pengeluaran Barang dari PLB</option>
                    </select>
                </div>
            </div>
            <div class="fld-row" style="margin-bottom:6px;">
                <label style="width:130px; font-weight:600;">Mata Uang (Valuta)</label>
                <div class="fld-input">
                    <input type="text" id="draftValuta" style="width:80px; text-transform:uppercase;" readonly>
                </div>
            </div>
            <div class="fld-row" style="margin-bottom:6px;">
                <label style="width:130px; font-weight:600;">Nilai Kurs / NDPBM *</label>
                <div class="fld-input">
                    <input type="number" id="draftNdpbm" step="0.01" style="width:140px; text-align:right;">
                    <span style="font-size:9px; color:var(--text-muted); margin-left:6px;">(Kurs Pajak BC)</span>
                </div>
            </div>
            <div style="background:var(--bg-app); border:1px solid var(--border-color); border-radius:4px; padding:8px 10px; margin-top:10px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                    <span style="color:var(--text-muted);">Pemasok / Penerima:</span>
                    <strong id="draftVendorName">-</strong>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                    <span style="color:var(--text-muted);">Jumlah Item Barang:</span>
                    <strong id="draftItemCount">0 Item</strong>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-muted);">Total Nilai Retur:</span>
                    <strong id="draftTotalValue" style="color:#ef4444;">0.00</strong>
                </div>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeCeisaDraftModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-success" onclick="submitCeisaDraft()" style="background:#059669; border-color:#059669;"><i class="fa-solid fa-cloud-arrow-up"></i> 🚀 Proses Masuk Staging</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- RIGHT-CLICK CONTEXT MENU (PB STYLE)                                       -->
<!-- ========================================================================= -->
<div class="context-menu" id="gridContextMenu">
    <div class="context-menu-item" onclick="addItemRow()"><i class="fa-solid fa-plus" style="color:var(--primary);"></i> Tambah Baris Baru</div>
    <div class="context-menu-item" onclick="deleteSelectedRow()"><i class="fa-solid fa-trash-can" style="color:var(--danger);"></i> Hapus Baris Terpilih</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="openMaterialPicker()"><i class="fa-solid fa-boxes-stacked" style="color:#0ea5e9;"></i> Pilih Master Barang...</div>
    <div class="context-menu-item" onclick="openLookupPi()"><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Tarik dari Faktur (PI)...</div>
    <div class="context-menu-item" onclick="openLookupRi()"><i class="fa-solid fa-dolly" style="color:#10b981;"></i> Tarik dari Penerimaan (RI)...</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="exportExcelSheetJS()"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Ekspor ke Excel (.xlsx)</div>
</div>

<!-- File Input Hidden untuk Import Excel -->
<input type="file" id="importExcelFile" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelFileSelected(this)">

<script>
// =========================================================================
// JAVASCRIPT LOGIC & STATE MANAGEMENT
// =========================================================================
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let currentActiveTargetInput = null;
let currentMaterialKlas = 'RM';
let currentBrowsePage = 1;
let currentBrowseLimit = 25;
let currentSelectedRow = null;
let lookupPiCache = [];
let lookupRiCache = [];

document.addEventListener('DOMContentLoaded', () => {
    // Inisialisasi splitbars
    initSplitbars();
    // Inisialisasi Searchable Select Vendor (Ketik nama / kode)
    if (typeof initAntSearchableSelect === 'function') {
        initAntSearchableSelect('prVendor', { placeholder: 'Ketik nama / kode supplier...' });
        initAntSearchableSelect('filterVendor', { placeholder: 'Semua Pemasok...' });
    }
    // Buka form baru jika kosong
    newPr();
    // Tutup context menu saat klik luar
    document.addEventListener('click', () => {
        document.getElementById('gridContextMenu').style.display = 'none';
        document.getElementById('excelDrop').style.display = 'none';
    });
});

// -------------------------------------------------------------------------
// DUAL RESIZABLE SPLITTERS
// -------------------------------------------------------------------------
function initSplitbars() {
    setupSplitter('splitHdr', 'paneHdr', 'down');
    setupSplitter('splitFtr', 'paneFtr', 'up');
}

function setupSplitter(splitId, targetPaneId, direction) {
    const splitter = document.getElementById(splitId);
    const pane = document.getElementById(targetPaneId);
    if (!splitter || !pane) return;

    let startY = 0;
    let startHeight = 0;

    splitter.addEventListener('mousedown', (e) => {
        if (e.target.closest('.split-btn-collapse')) return;
        startY = e.clientY;
        startHeight = pane.offsetHeight;
        splitter.classList.add('dragging');

        function onMouseMove(moveEvent) {
            const dy = moveEvent.clientY - startY;
            let newHeight = direction === 'down' ? (startHeight + dy) : (startHeight - dy);
            if (newHeight < 40) newHeight = 40;
            if (newHeight > 450) newHeight = 450;
            pane.style.flex = `0 0 ${newHeight}px`;
        }

        function onMouseUp() {
            splitter.classList.remove('dragging');
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
        }

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    });
}

function togglePaneCollapse(paneId, btn) {
    const pane = document.getElementById(paneId);
    if (!pane) return;
    const isCollapsed = pane.dataset.collapsed === 'true';
    if (isCollapsed) {
        pane.style.flex = pane.dataset.prevFlex || (paneId === 'paneHdr' ? '0 0 152px' : '0 0 114px');
        pane.dataset.collapsed = 'false';
        btn.querySelector('i').className = paneId === 'paneHdr' ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down';
    } else {
        pane.dataset.prevFlex = pane.style.flex || (paneId === 'paneHdr' ? '0 0 152px' : '0 0 114px');
        pane.style.flex = '0 0 0px';
        pane.dataset.collapsed = 'true';
        btn.querySelector('i').className = paneId === 'paneHdr' ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-up';
    }
}

// -------------------------------------------------------------------------
// TAB NAVIGATION
// -------------------------------------------------------------------------
function switchPage(pageId) {
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.page === pageId));
    document.querySelectorAll('.ant-page').forEach(p => p.classList.toggle('active', p.id === pageId));
    if (pageId === 'pageBrowse') loadBrowseData(1);
    if (pageId === 'pageTrace') reloadTrace();
}

// -------------------------------------------------------------------------
// GUARD EDIT (LOCK CHECK)
// -------------------------------------------------------------------------
function isLocked() {
    return document.getElementById('prIsLock').value === 'Y';
}

function guardEdit() {
    if (isLocked()) {
        Swal.fire({
            icon: 'warning',
            title: 'Dokumen Terkunci',
            text: 'Dokumen ini sudah diposting ke IT Inventory. Silakan Buka Kunci terlebih dahulu untuk mengedit.',
            confirmButtonColor: 'var(--primary)'
        });
        return true;
    }
    return false;
}

// -------------------------------------------------------------------------
// INISIALISASI DOKUMEN RETUR BARU
// -------------------------------------------------------------------------
function newPr() {
    document.getElementById('formPr').reset();
    document.getElementById('prId').value = '';
    document.getElementById('prStatus').value = '0';
    document.getElementById('prIsLock').value = 'N';
    document.getElementById('prLock').style.display = 'none';
    document.getElementById('btnPostIt').style.display = 'inline-flex';
    document.getElementById('btnUnlockIt').style.display = 'none';

    document.getElementById('prDt').value = '<?= $today ?>';
    document.getElementById('prShipDt').value = '<?= $today ?>';
    document.getElementById('prRate').value = '1.00';
    document.getElementById('prFreight').value = '0.00';
    document.getElementById('prDiscPersen').value = '0.00';

    updateStatusBadge('0');
    const selV = document.getElementById('prVendor');
    if (selV) {
        selV.value = '';
        if (selV.syncAntSelect) selV.syncAntSelect();
    }
    const btnOpenEd = document.getElementById('btnOpenEditor');
    if (btnOpenEd) btnOpenEd.style.display = 'none';
    document.getElementById('itemsBody').innerHTML = '';
    addItemRow();
    generateNo();
    calcPrSummary();
}

async function generateNo() {
    try {
        const res = await fetch(`purchase_return.php?action=generate_no&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status === 'OK' && json.pr_no) {
            document.getElementById('prNo').value = json.pr_no;
        }
    } catch(e) {}
}

function updateStatusBadge(st, isLock) {
    const badge = document.getElementById('badgePrStatus');
    if (isLock === 'Y' || isLock === 1) {
        badge.className = 'ant-badge ant-badge-lock';
        badge.innerText = 'TERKUNCI IT INV';
        return;
    }
    if (st === '1') {
        badge.className = 'ant-badge ant-badge-approved';
        badge.innerText = 'APPROVED';
    } else if (st === '9') {
        badge.className = 'ant-badge ant-badge-lock';
        badge.innerText = 'BATAL / VOID';
    } else {
        badge.className = 'ant-badge ant-badge-draft';
        badge.innerText = 'DRAFT';
    }
}

function toggleKompensasi() {
    const isPotong = document.getElementById('kompPotong').checked;
    document.getElementById('rowPiTarget').style.display = isPotong ? 'flex' : 'none';
    document.getElementById('txtKompensasiBadge').innerText = isPotong ? 'Ya (Debit Note AP)' : 'Tidak (Tukar Barang)';
    document.getElementById('txtKompensasiBadge').style.color = isPotong ? 'var(--success)' : '#d48806';
}

function onVendorChange(vendorVal) {
    const opt = document.querySelector(`#prVendor option[value="${vendorVal}"]`);
    if (opt) {
        document.getElementById('prAlamat').value = opt.dataset.alamat || '';
        const k = opt.dataset.kurs || 'IDR';
        if (document.getElementById('prKurs').value !== k) {
            document.getElementById('prKurs').value = k;
            onKursChange();
        }
    }
}

function onKursChange() {
    const k = document.getElementById('prKurs').value;
    document.getElementById('prRate').value = (k === 'IDR') ? '1.00' : '15500.00';
    calcPrSummary();
}

// -------------------------------------------------------------------------
// ITEM GRID TABLE MANAGEMENT
// -------------------------------------------------------------------------
function addItemRow(data = {}) {
    const tbody = document.getElementById('itemsBody');
    const rowIdx = tbody.children.length + 1;
    const tr = document.createElement('tr');
    tr.dataset.idx = rowIdx;

    tr.innerHTML = `
        <td class="text-center muted row-seq">${rowIdx}</td>
        <td>
            <div class="inline-box">
                <input type="text" class="it-kode" value="${escapeHtml(data.kode_barang || '')}" placeholder="Kode..." onkeydown="if(event.key==='Enter') quickSearchMaterial(this)">
                <button type="button" class="ant-btn ant-btn-sm" onclick="openMaterialPicker(this)" title="Katalog Master Barang"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </td>
        <td><input type="text" class="it-uraian" value="${escapeHtml(data.uraian || '')}" placeholder="Uraian barang..."></td>
        <td><input type="text" class="it-sat text-center" value="${escapeHtml(data.sat || 'PCE')}" style="width:100%;"></td>
        <td><input type="number" class="it-qty text-right" value="${data.qty || 1}" step="0.01" oninput="calcItemRow(this)"></td>
        <td><input type="number" class="it-price text-right" value="${data.price || 0}" step="0.01" oninput="calcItemRow(this)"></td>
        <td><input type="number" class="it-disc text-right" value="${data.disc || 0}" step="0.01" oninput="calcItemRow(this)"></td>
        <td><input type="number" class="it-amount text-right" value="${data.amount || 0}" step="0.01" readonly style="font-weight:600;"></td>
        <td><input type="text" class="it-pi" value="${escapeHtml(data.pi_no || '')}" placeholder="No. PI..."></td>
        <td><input type="text" class="it-ri" value="${escapeHtml(data.ri_no || '')}" placeholder="No. RI..."></td>
        <td><input type="text" class="it-note" value="${escapeHtml(data.note || '')}" placeholder="Alasan retur item..."></td>
        <td class="text-center">
            <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="removeItemRow(this)" style="height:19px; padding:0 4px;"><i class="fa-solid fa-xmark"></i></button>
            <input type="hidden" class="it-postarif" value="${escapeHtml(data.pos_tarif || '')}">
            <input type="hidden" class="it-pono" value="${escapeHtml(data.po_no || '')}">
        </td>
    `;

    tr.addEventListener('click', () => {
        document.querySelectorAll('#tblItems tbody tr').forEach(r => r.classList.remove('selected'));
        tr.classList.add('selected');
        currentSelectedRow = tr;
    });

    tbody.appendChild(tr);
    calcItemRow(tr.querySelector('.it-qty'));
}

function removeItemRow(btn) {
    if (guardEdit()) return;
    const tr = btn.closest('tr');
    tr.remove();
    reindexItemRows();
    calcPrSummary();
}

function clearAllItems() {
    if (guardEdit()) return;
    document.getElementById('itemsBody').innerHTML = '';
    addItemRow();
    calcPrSummary();
}

function reindexItemRows() {
    const rows = document.querySelectorAll('#itemsBody tr');
    rows.forEach((r, idx) => {
        r.dataset.idx = idx + 1;
        r.querySelector('.row-seq').innerText = idx + 1;
    });
}

function calcItemRow(inputEl) {
    const tr = inputEl.closest('tr');
    const qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
    const price = parseFloat(tr.querySelector('.it-price').value) || 0;
    const discPct = parseFloat(tr.querySelector('.it-disc').value) || 0;

    let sub = qty * price;
    if (discPct > 0) {
        sub -= (sub * (discPct / 100));
    }
    tr.querySelector('.it-amount').value = sub.toFixed(2);
    calcPrSummary();
}

function calcPrSummary() {
    let subtotal = 0;
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        subtotal += parseFloat(tr.querySelector('.it-amount').value) || 0;
    });

    const discPersen = parseFloat(document.getElementById('prDiscPersen').value) || 0;
    const discAmt = subtotal * (discPersen / 100);
    const dpp = Math.max(0, subtotal - discAmt);

    const isTax = document.getElementById('prTaxable').checked;
    const ppn = isTax ? (dpp * 0.11) : 0;
    const freight = parseFloat(document.getElementById('prFreight').value) || 0;
    const grandTotal = dpp + ppn + freight;

    document.getElementById('txtSubtotal').innerText = numberFormat(subtotal);
    document.getElementById('txtDiscAmt').innerText = numberFormat(discAmt);
    document.getElementById('txtDpp').innerText = numberFormat(dpp);
    document.getElementById('txtPpn').innerText = numberFormat(ppn);
    document.getElementById('txtGrandTotal').innerText = numberFormat(grandTotal);

    const kurs = document.getElementById('prKurs').value;
    document.getElementById('txtTerbilang').innerText = terbilang(grandTotal, kurs);
}

// -------------------------------------------------------------------------
// SIMPAN TRANSAKSI RETUR (SAVE AJAX)
// -------------------------------------------------------------------------
async function savePr() {
    if (guardEdit()) return;

    const vendor = document.getElementById('prVendor').value;
    if (!vendor) {
        Swal.fire('Peringatan', 'Silakan pilih Pemasok / Vendor terlebih dahulu.', 'warning');
        return;
    }

    const items = [];
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        const kd = tr.querySelector('.it-kode').value.trim();
        const ur = tr.querySelector('.it-uraian').value.trim();
        const qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
        const price = parseFloat(tr.querySelector('.it-price').value) || 0;
        const disc = parseFloat(tr.querySelector('.it-disc').value) || 0;
        const amt = parseFloat(tr.querySelector('.it-amount').value) || 0;

        if (kd || ur) {
            items.push({
                kode_barang: kd,
                uraian: ur,
                sat: tr.querySelector('.it-sat').value.trim() || 'PCE',
                qty: qty,
                price: price,
                disc: disc,
                amount: amt,
                pi_no: tr.querySelector('.it-pi').value.trim(),
                ri_no: tr.querySelector('.it-ri').value.trim(),
                note: tr.querySelector('.it-note').value.trim(),
                pos_tarif: tr.querySelector('.it-postarif').value.trim(),
                po_no: tr.querySelector('.it-pono').value.trim()
            });
        }
    });

    if (items.length === 0) {
        Swal.fire('Peringatan', 'Tabel rincian barang retur tidak boleh kosong.', 'warning');
        return;
    }

    const subtotal = parseNumber(document.getElementById('txtSubtotal').innerText);
    const discPersen = parseFloat(document.getElementById('prDiscPersen').value) || 0;
    const discAmt = parseNumber(document.getElementById('txtDiscAmt').innerText);
    const ppn = parseNumber(document.getElementById('txtPpn').innerText);
    const freight = parseFloat(document.getElementById('prFreight').value) || 0;
    const grandTotal = parseNumber(document.getElementById('txtGrandTotal').innerText);

    const payload = {
        header: {
            id: document.getElementById('prId').value.trim(),
            pr_no: document.getElementById('prNo').value.trim(),
            pr_dt: document.getElementById('prDt').value,
            ship_dt: document.getElementById('prShipDt').value,
            jatuhtempo: document.getElementById('prShipDt').value,
            vendor: vendor,
            kurs: document.getElementById('prKurs').value,
            rate: parseFloat(document.getElementById('prRate').value) || 1,
            status: document.getElementById('prStatus').value,
            note: document.getElementById('prNote').value.trim(),
            kompensasi: document.getElementById('kompPotong').checked ? 'POTONG_HUTANG' : 'GANTI_BARANG',
            pi_target: document.getElementById('prPiTarget').value.trim(),
            pi_no: document.getElementById('prRefPi').value.trim(),
            ri_no: document.getElementById('prRefRi').value.trim(),
            bc: document.getElementById('prBc').value,
            no_bc: document.getElementById('prNoBc').value.trim(),
            tgl_bc: document.getElementById('prTglBc').value,
            no_aju: document.getElementById('prNoAju').value.trim(),
            gudang: document.getElementById('prGudang').value,
            subtotal: subtotal,
            disc_persen: discPersen,
            disc_amt: discAmt,
            ppn: ppn,
            est_freight: freight,
            total_order: grandTotal
        },
        details: items
    };

    try {
        Swal.fire({ title: 'Menyimpan...', didOpen: () => Swal.showLoading() });
        const res = await fetch(`purchase_return.php?action=save&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        document.getElementById('prId').value = json.id;
        document.getElementById('prNo').value = json.pr_no;
        Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1500, showConfirmButton: false });
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// HAPUS RETUR (DELETE AJAX)
// -------------------------------------------------------------------------
async function deletePr() {
    if (guardEdit()) return;
    const id = document.getElementById('prId').value.trim();
    if (!id) {
        Swal.fire('Peringatan', 'Tidak ada dokumen yang dipilih untuk dihapus.', 'warning');
        return;
    }

    const ask = await Swal.fire({
        icon: 'warning',
        title: 'Hapus Retur Pembelian?',
        text: 'Dokumen retur ini beserta seluruh itemnya akan dihapus permanen.',
        showCancelButton: true,
        confirmButtonColor: 'var(--danger)',
        confirmButtonText: 'Ya, Hapus!'
    });
    if (!ask.isConfirmed) return;

    try {
        Swal.fire({ title: 'Menghapus...', didOpen: () => Swal.showLoading() });
        const res = await fetch(`purchase_return.php?action=delete&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1500, showConfirmButton: false });
        newPr();
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// POSTING & UNLOCK IT INVENTORY (bc_1ab2, AB='B')
// -------------------------------------------------------------------------
async function postItInventory() {
    const id = document.getElementById('prId').value.trim();
    if (!id) {
        Swal.fire('Peringatan', 'Silakan simpan transaksi retur terlebih dahulu sebelum posting ke IT Inventory.', 'warning');
        return;
    }

    const ask = await Swal.fire({
        icon: 'question',
        title: 'Posting ke IT Inventory?',
        text: 'Transaksi ini akan dicatat sebagai Pengeluaran Barang (AB="B") pada IT Inventory dan dokumen akan dikunci.',
        showCancelButton: true,
        confirmButtonColor: 'var(--primary)',
        confirmButtonText: 'Ya, Posting Sekarang'
    });
    if (!ask.isConfirmed) return;

    try {
        Swal.fire({ title: 'Memposting IT Inventory...', didOpen: () => Swal.showLoading() });
        const res = await fetch(`purchase_return.php?action=transfer_it_inv&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire({ icon: 'success', title: 'Terkunci IT Inv', text: json.message });
        document.getElementById('prIsLock').value = 'Y';
        document.getElementById('prLock').style.display = 'flex';
        document.getElementById('btnPostIt').style.display = 'none';
        document.getElementById('btnUnlockIt').style.display = 'inline-flex';
        updateStatusBadge('1', 'Y');
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

async function unlockItInventory() {
    const id = document.getElementById('prId').value.trim();
    if (!id) return;

    const ask = await Swal.fire({
        icon: 'warning',
        title: 'Buka Kunci IT Inventory?',
        text: 'Catatan pengeluaran di IT Inventory (bc_1ab2) akan dihapus sementara agar dokumen dapat diedit kembali.',
        showCancelButton: true,
        confirmButtonColor: 'var(--warning)',
        confirmButtonText: 'Ya, Buka Kunci'
    });
    if (!ask.isConfirmed) return;

    try {
        Swal.fire({ title: 'Membuka Kunci...', didOpen: () => Swal.showLoading() });
        const res = await fetch(`purchase_return.php?action=delete_it_inv&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire({ icon: 'success', title: 'Terbuka', text: json.message });
        document.getElementById('prIsLock').value = 'N';
        document.getElementById('prLock').style.display = 'none';
        document.getElementById('btnPostIt').style.display = 'inline-flex';
        document.getElementById('btnUnlockIt').style.display = 'none';
        updateStatusBadge('1', 'N');
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// PRINT FORMULIR RESMI
// -------------------------------------------------------------------------
function printPr() {
    const id = document.getElementById('prId').value.trim();
    if (!id) {
        Swal.fire('Peringatan', 'Silakan pilih atau simpan transaksi retur terlebih dahulu.', 'warning');
        return;
    }
    window.open(`purchase_return.php?action=print&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

// -------------------------------------------------------------------------
// BROWSE DATA LIST & PAGINASI
// -------------------------------------------------------------------------
async function loadBrowseData(page = 1) {
    currentBrowsePage = page;
    currentBrowseLimit = parseInt(document.getElementById('filterLimit').value) || 25;
    const tglAwal = document.getElementById('filterTglAwal').value;
    const tglAkhir = document.getElementById('filterTglAkhir').value;
    const status = document.getElementById('filterStatus').value;
    const vendor = document.getElementById('filterVendor').value;
    const search = document.getElementById('filterSearch').value.trim();

    const tbody = document.getElementById('browseBody');
    tbody.innerHTML = '<tr><td colspan="14" class="muted text-center" style="padding:14px;">Memuat data retur pembelian...</td></tr>';

    try {
        const url = `purchase_return.php?action=list&page=${page}&limit=${currentBrowseLimit}&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}&status=${encodeURIComponent(status)}&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(search)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        // Update KPI Strip
        if (json.kpi) {
            document.getElementById('kpiTotalPr').innerText = numberFormat(json.kpi.total_pr, 0);
            document.getElementById('kpiSumTotal').innerText = numberFormat(json.kpi.sum_total_order);
            document.getElementById('kpiTotalDraft').innerText = numberFormat(json.kpi.total_draft, 0);
            document.getElementById('kpiTotalApproved').innerText = numberFormat(json.kpi.total_approved, 0);
            document.getElementById('kpiTotalVoid').innerText = numberFormat(json.kpi.total_void, 0);
        }

        document.getElementById('txtTotalBrowse').innerText = `Total: ${json.total} data`;
        const totalPages = Math.ceil(json.total / currentBrowseLimit) || 1;
        document.getElementById('txtPageInfo').innerText = `Halaman ${page} dari ${totalPages}`;
        document.getElementById('txtCurrentPage').innerText = page;
        document.getElementById('btnPagePrev').disabled = (page <= 1);
        document.getElementById('btnPageNext').disabled = (page >= totalPages);

        if (!json.data || json.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="14" class="muted text-center" style="padding:16px;">Tidak ada dokumen retur pembelian yang cocok.</td></tr>';
            return;
        }

        let html = '';
        json.data.forEach((r, idx) => {
            const seq = (page - 1) * currentBrowseLimit + (idx + 1);
            const lockBadge = r.is_locked ? '<span class="ant-badge ant-badge-lock">Terkunci</span>' : '<span class="ant-badge">Draft</span>';
            const statusBadge = (r.status === '1') ? '<span class="ant-badge ant-badge-approved">Approved</span>' : '<span class="ant-badge ant-badge-draft">Draft</span>';

            html += `
                <tr ondblclick="loadPrDetail('${r.id}')" style="cursor:pointer;">
                    <td class="text-center muted">${seq}</td>
                    <td><b>${escapeHtml(r.pr_no)}</b></td>
                    <td>${r.pr_dt_fmt}</td>
                    <td>${escapeHtml(r.vendor_name)}</td>
                    <td class="text-center"><span class="ant-badge">${escapeHtml(r.kurs)}</span></td>
                    <td class="text-right">${r.subtotal_fmt}</td>
                    <td class="text-right">${r.ppn_fmt}</td>
                    <td class="text-right" style="font-weight:600; color:var(--primary);">${r.total_order_fmt}</td>
                    <td class="text-center">${escapeHtml(r.bc || '-')}</td>
                    <td>${escapeHtml(r.no_bc || '-')}</td>
                    <td>${r.tgl_bc_fmt}</td>
                    <td class="text-center">${lockBadge}</td>
                    <td class="text-center">${statusBadge}</td>
                    <td class="text-center">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="loadPrDetail('${r.id}')" title="Edit Transaksi"><i class="fa-solid fa-pen"></i></button>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="14" style="color:var(--danger); text-align:center; padding:14px;">${e.message}</td></tr>`;
    }
}

function navigateBrowsePage(delta) {
    loadBrowseData(currentBrowsePage + delta);
}

function resetBrowseFilter() {
    document.getElementById('filterTglAwal').value = '';
    document.getElementById('filterTglAkhir').value = '';
    document.getElementById('filterStatus').value = 'ALL';
    document.getElementById('filterVendor').value = '';
    document.getElementById('filterSearch').value = '';
    loadBrowseData(1);
}

// -------------------------------------------------------------------------
// LOAD DOKUMEN RETUR DETAIL (EDIT MODE)
// -------------------------------------------------------------------------
async function loadPrDetail(id) {
    try {
        Swal.fire({ title: 'Memuat detail retur...', didOpen: () => Swal.showLoading() });
        const res = await fetch(`purchase_return.php?action=get_pr&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        Swal.close();
        if (json.status !== 'OK') throw new Error(json.message);

        const h = json.header;
        document.getElementById('prId').value = h.id;
        document.getElementById('prNo').value = h.pr_no;
        document.getElementById('prDt').value = h.pr_dt || '';
        document.getElementById('prShipDt').value = h.ship_dt || h.pr_dt || '';
        document.getElementById('prVendor').value = h.vendor;
        onVendorChange(h.vendor);

        document.getElementById('prKurs').value = h.kurs || 'IDR';
        document.getElementById('prRate').value = h.rate || '1.00';
        document.getElementById('prBc').value = h.bc || '';
        document.getElementById('prNoBc').value = h.no_bc || '';
        document.getElementById('prTglBc').value = h.tgl_bc || '';
        document.getElementById('prNoAju').value = h.no_aju || '';
        const btnOpenEd = document.getElementById('btnOpenEditor');
        if (btnOpenEd) {
            btnOpenEd.style.display = (h.no_aju && h.no_aju.length === 26) ? 'inline-flex' : 'none';
        }
        document.getElementById('prGudang').value = h.gudang || 'UTAMA';
        document.getElementById('prRefPi').value = h.pi_no || '';
        document.getElementById('prRefRi').value = h.ri_no || '';
        document.getElementById('prPiTarget').value = h.pi_target || '';
        document.getElementById('prNote').value = h.note || '';

        const isPotong = (h.kompensasi !== 'GANTI_BARANG');
        document.getElementById('kompPotong').checked = isPotong;
        document.getElementById('kompGanti').checked = !isPotong;
        toggleKompensasi();

        const isLockedDoc = (h.is_locked === 1 || h.is_lock === 'Y');
        document.getElementById('prIsLock').value = isLockedDoc ? 'Y' : 'N';
        document.getElementById('prLock').style.display = isLockedDoc ? 'flex' : 'none';
        document.getElementById('btnPostIt').style.display = isLockedDoc ? 'none' : 'inline-flex';
        document.getElementById('btnUnlockIt').style.display = isLockedDoc ? 'inline-flex' : 'none';
        updateStatusBadge(h.status, isLockedDoc ? 'Y' : 'N');

        // Render Baris Material
        const tbody = document.getElementById('itemsBody');
        tbody.innerHTML = '';
        if (json.items && json.items.length > 0) {
            json.items.forEach(it => addItemRow(it));
        } else {
            addItemRow();
        }

        calcPrSummary();
        switchPage('pageEntry');
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// FITUR INOVATIF: MODAL REKAP PO & HUTANG VENDOR (VENDOR 360°)
// -------------------------------------------------------------------------
async function openVendorApModal() {
    const vendor = document.getElementById('prVendor').value;
    if (!vendor) {
        Swal.fire('Peringatan', 'Silakan pilih Pemasok / Vendor terlebih dahulu pada form.', 'warning');
        return;
    }

    document.getElementById('modalVendorAp').classList.add('active');
    document.getElementById('apUnpaidPiBody').innerHTML = '<tr><td colspan="9" class="muted text-center" style="padding:10px;">Memuat data hutang vendor...</td></tr>';
    document.getElementById('apOpenPoBody').innerHTML = '<tr><td colspan="6" class="muted text-center" style="padding:10px;">Memuat data PO vendor...</td></tr>';

    try {
        const res = await fetch(`purchase_return.php?action=vendor_summary_ap&vendor=${encodeURIComponent(vendor)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        // Header info
        document.getElementById('apVendorName').innerText = `${json.vendor.KODE} - ${json.vendor.NAMA}`;
        document.getElementById('apVendorDetail').innerText = `Alamat: ${json.vendor.ALAMAT || '-'} | NPWP: ${json.vendor.NPWP || '-'} | Termin: ${json.vendor.TOP || 'COD'}`;
        document.getElementById('apTotalHutang').innerText = json.summary.total_hutang_fmt;
        document.getElementById('apTotalOpenPo').innerText = json.summary.total_open_po_fmt;
        document.getElementById('apTotalReturOpen').innerText = json.summary.total_retur_open_fmt;

        // Unpaid PI Table
        const upBody = document.getElementById('apUnpaidPiBody');
        if (!json.unpaid_pi || json.unpaid_pi.length === 0) {
            upBody.innerHTML = '<tr><td colspan="9" class="muted text-center" style="padding:10px;">Tidak ada tagihan faktur (PI) yang belum lunas untuk vendor ini.</td></tr>';
        } else {
            let html = '';
            json.unpaid_pi.forEach(p => {
                html += `
                    <tr>
                        <td><b>${escapeHtml(p.pi_no)}</b></td>
                        <td>${p.pi_dt_fmt}</td>
                        <td>${p.jt_fmt}</td>
                        <td class="text-center">${escapeHtml(p.term || '-')}</td>
                        <td class="text-center"><span class="ant-badge">${escapeHtml(p.kurs)}</span></td>
                        <td class="text-right">${p.total_fmt}</td>
                        <td class="text-right">${p.terbayar_fmt}</td>
                        <td class="text-right" style="font-weight:700; color:var(--danger);">${p.sisa_fmt}</td>
                        <td class="text-center">
                            <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="setPiTarget('${escapeHtml(p.pi_no)}')"><i class="fa-solid fa-check"></i> Potong Faktur Ini</button>
                        </td>
                    </tr>
                `;
            });
            upBody.innerHTML = html;
        }

        // Open PO Table
        const poBody = document.getElementById('apOpenPoBody');
        if (!json.open_po || json.open_po.length === 0) {
            poBody.innerHTML = '<tr><td colspan="6" class="muted text-center" style="padding:10px;">Tidak ada Purchase Order aktif / berjalan untuk vendor ini.</td></tr>';
        } else {
            let html = '';
            json.open_po.forEach(po => {
                html += `
                    <tr>
                        <td><b>${escapeHtml(po.po_no)}</b></td>
                        <td>${po.po_dt_fmt}</td>
                        <td class="text-center"><span class="ant-badge">${escapeHtml(po.kurs)}</span></td>
                        <td class="text-right" style="font-weight:600;">${po.total_fmt}</td>
                        <td class="text-center">${po.item_count} item</td>
                        <td class="text-center"><span class="ant-badge ant-badge-approved">Open</span></td>
                    </tr>
                `;
            });
            poBody.innerHTML = html;
        }
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

function closeVendorApModal() {
    document.getElementById('modalVendorAp').classList.remove('active');
}

function setPiTarget(piNo) {
    document.getElementById('prPiTarget').value = piNo;
    document.getElementById('kompPotong').checked = true;
    toggleKompensasi();
    closeVendorApModal();
    Swal.fire({
        icon: 'success',
        title: 'Target Faktur Terpilih',
        text: `Faktur ${piNo} ditetapkan sebagai tagihan yang akan dipotong oleh retur ini.`,
        timer: 1500,
        showConfirmButton: false
    });
}

function choosePiTargetFromVendor() {
    openVendorApModal();
}

// -------------------------------------------------------------------------
// MODAL: TARIK FAKTUR PEMBELIAN (PI)
// -------------------------------------------------------------------------
function openLookupPi() {
    if (guardEdit()) return;
    document.getElementById('modalLookupPi').classList.add('active');
    searchPiForReturn();
}

function closeLookupPi() {
    document.getElementById('modalLookupPi').classList.remove('active');
}

async function searchPiForReturn() {
    const vendor = document.getElementById('prVendor').value;
    const search = document.getElementById('searchPiText').value.trim();
    const tbody = document.getElementById('lookupPiBody');
    tbody.innerHTML = '<tr><td colspan="12" class="muted text-center" style="padding:12px;">Mencari faktur pembelian...</td></tr>';

    try {
        const url = `purchase_return.php?action=lookup_pi_for_return&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(search)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        lookupPiCache = json.data || [];
        if (lookupPiCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="muted text-center" style="padding:12px;">Tidak ada item faktur pembelian yang ditemukan.</td></tr>';
            return;
        }

        let html = '';
        lookupPiCache.forEach((it, idx) => {
            html += `
                <tr>
                    <td class="text-center"><input type="checkbox" class="chk-pi-item" data-idx="${idx}" onchange="updateSelectedPiCount()"></td>
                    <td><b>${escapeHtml(it.pi_no)}</b></td>
                    <td>${it.pi_dt_fmt}</td>
                    <td>${escapeHtml(it.vendor_nama)}</td>
                    <td>${escapeHtml(it.ri_no || '-')}</td>
                    <td>${escapeHtml(it.po_no || '-')}</td>
                    <td><b>${escapeHtml(it.kode_barang)}</b></td>
                    <td>${escapeHtml(it.uraian)}</td>
                    <td class="text-right" style="font-weight:600;">${it.qty_pi_fmt}</td>
                    <td class="text-center">${escapeHtml(it.sat)}</td>
                    <td class="text-right">${it.price_fmt}</td>
                    <td class="text-right">${it.amount_fmt}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
        updateSelectedPiCount();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="12" style="color:var(--danger); text-align:center; padding:12px;">${e.message}</td></tr>`;
    }
}

function toggleCheckAllPi(checked) {
    document.querySelectorAll('.chk-pi-item').forEach(c => c.checked = checked);
    updateSelectedPiCount();
}

function updateSelectedPiCount() {
    const cnt = document.querySelectorAll('.chk-pi-item:checked').length;
    document.getElementById('txtCountSelectedPi').innerText = `${cnt} item terpilih`;
}

function confirmSelectPi() {
    const checkboxes = document.querySelectorAll('.chk-pi-item:checked');
    if (checkboxes.length === 0) {
        Swal.fire('Peringatan', 'Silakan pilih setidaknya satu item faktur.', 'warning');
        return;
    }

    const tbody = document.getElementById('itemsBody');
    // Jika hanya 1 baris kosong, bersihkan
    if (tbody.children.length === 1 && !tbody.querySelector('.it-kode').value.trim()) {
        tbody.innerHTML = '';
    }

    let firstItem = null;
    checkboxes.forEach(c => {
        const item = lookupPiCache[parseInt(c.dataset.idx)];
        if (item) {
            if (!firstItem) firstItem = item;
            addItemRow({
                kode_barang: item.kode_barang,
                uraian: item.uraian,
                sat: item.sat,
                qty: item.qty_pi,
                price: item.price,
                disc: item.disc || 0,
                amount: item.amount,
                pi_no: item.pi_no,
                ri_no: item.ri_no,
                po_no: item.po_no,
                pos_tarif: item.pos_tarif || ''
            });
        }
    });

    if (firstItem) {
        if (!document.getElementById('prVendor').value && firstItem.vendor) {
            document.getElementById('prVendor').value = firstItem.vendor;
            onVendorChange(firstItem.vendor);
        }
        if (!document.getElementById('prRefPi').value) document.getElementById('prRefPi').value = firstItem.pi_no;
        if (!document.getElementById('prPiTarget').value) document.getElementById('prPiTarget').value = firstItem.pi_no;
        if (!document.getElementById('prBc').value && firstItem.bc) document.getElementById('prBc').value = firstItem.bc;
        if (!document.getElementById('prNoBc').value && firstItem.no_bc) document.getElementById('prNoBc').value = firstItem.no_bc;
        if (!document.getElementById('prTglBc').value && firstItem.tgl_bc) document.getElementById('prTglBc').value = firstItem.tgl_bc;
        if (!document.getElementById('prNoAju').value && firstItem.no_aju) document.getElementById('prNoAju').value = firstItem.no_aju;
    }

    closeLookupPi();
    calcPrSummary();
}

// -------------------------------------------------------------------------
// MODAL: TARIK PENERIMAAN BARANG (RI / GRN)
// -------------------------------------------------------------------------
function openLookupRi() {
    if (guardEdit()) return;
    document.getElementById('modalLookupRi').classList.add('active');
    searchRiForReturn();
}

function closeLookupRi() {
    document.getElementById('modalLookupRi').classList.remove('active');
}

async function searchRiForReturn() {
    const vendor = document.getElementById('prVendor').value;
    const search = document.getElementById('searchRiText').value.trim();
    const tbody = document.getElementById('lookupRiBody');
    tbody.innerHTML = '<tr><td colspan="11" class="muted text-center" style="padding:12px;">Mencari penerimaan barang...</td></tr>';

    try {
        const url = `purchase_return.php?action=lookup_ri_outstanding&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(search)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        lookupRiCache = json.data || [];
        if (lookupRiCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="muted text-center" style="padding:12px;">Tidak ada data penerimaan barang yang cocok.</td></tr>';
            return;
        }

        let html = '';
        lookupRiCache.forEach((it, idx) => {
            html += `
                <tr>
                    <td class="text-center"><input type="checkbox" class="chk-ri-item" data-idx="${idx}" onchange="updateSelectedRiCount()"></td>
                    <td><b>${escapeHtml(it.ri_no)}</b></td>
                    <td>${it.ri_dt_fmt}</td>
                    <td>${escapeHtml(it.vendor_nama)}</td>
                    <td>${escapeHtml(it.no_sj || '-')}</td>
                    <td>${escapeHtml(it.po_no || '-')}</td>
                    <td><b>${escapeHtml(it.kode_barang)}</b></td>
                    <td>${escapeHtml(it.uraian)}</td>
                    <td class="text-right" style="font-weight:600;">${it.qty_ri_fmt}</td>
                    <td class="text-center">${escapeHtml(it.sat)}</td>
                    <td class="text-right">${it.price_fmt}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
        updateSelectedRiCount();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="11" style="color:var(--danger); text-align:center; padding:12px;">${e.message}</td></tr>`;
    }
}

function toggleCheckAllRi(checked) {
    document.querySelectorAll('.chk-ri-item').forEach(c => c.checked = checked);
    updateSelectedRiCount();
}

function updateSelectedRiCount() {
    const cnt = document.querySelectorAll('.chk-ri-item:checked').length;
    document.getElementById('txtCountSelectedRi').innerText = `${cnt} item terpilih`;
}

function confirmSelectRi() {
    const checkboxes = document.querySelectorAll('.chk-ri-item:checked');
    if (checkboxes.length === 0) {
        Swal.fire('Peringatan', 'Silakan pilih setidaknya satu item penerimaan.', 'warning');
        return;
    }

    const tbody = document.getElementById('itemsBody');
    if (tbody.children.length === 1 && !tbody.querySelector('.it-kode').value.trim()) {
        tbody.innerHTML = '';
    }

    let firstItem = null;
    checkboxes.forEach(c => {
        const item = lookupRiCache[parseInt(c.dataset.idx)];
        if (item) {
            if (!firstItem) firstItem = item;
            addItemRow({
                kode_barang: item.kode_barang,
                uraian: item.uraian,
                sat: item.sat,
                qty: item.qty_ri,
                price: item.price,
                disc: item.disc || 0,
                amount: item.amount,
                ri_no: item.ri_no,
                po_no: item.po_no,
                pos_tarif: item.pos_tarif || ''
            });
        }
    });

    if (firstItem) {
        if (!document.getElementById('prVendor').value && firstItem.vendor) {
            document.getElementById('prVendor').value = firstItem.vendor;
            onVendorChange(firstItem.vendor);
        }
        if (!document.getElementById('prRefRi').value) document.getElementById('prRefRi').value = firstItem.ri_no;
        if (!document.getElementById('prBc').value && firstItem.bc) document.getElementById('prBc').value = firstItem.bc;
        if (!document.getElementById('prNoBc').value && firstItem.no_bc) document.getElementById('prNoBc').value = firstItem.no_bc;
        if (!document.getElementById('prTglBc').value && firstItem.tgl_bc) document.getElementById('prTglBc').value = firstItem.tgl_bc;
        if (!document.getElementById('prNoAju').value && firstItem.no_aju) document.getElementById('prNoAju').value = firstItem.no_aju;
    }

    closeLookupRi();
    calcPrSummary();
}

// -------------------------------------------------------------------------
// MODAL: KATALOG MASTER BARANG (5 KLASIFIKASI)
// -------------------------------------------------------------------------
function openMaterialPicker(inputEl = null) {
    if (guardEdit()) return;
    currentActiveTargetInput = inputEl;
    document.getElementById('modalMaterial').classList.add('active');
    searchMaterials();
}

function closeMaterialPicker() {
    document.getElementById('modalMaterial').classList.remove('active');
}

function setKlas(k) {
    currentMaterialKlas = k;
    document.querySelectorAll('.klas-btn').forEach(b => b.classList.toggle('active', b.dataset.klas === k));
    searchMaterials();
}

async function searchMaterials() {
    const q = document.getElementById('materialSearch').value.trim();
    const tbody = document.getElementById('materialBody');
    tbody.innerHTML = '<tr><td colspan="6" class="muted text-center" style="padding:12px;">Mencari katalog barang...</td></tr>';

    try {
        const url = `purchase_return.php?action=search_materials&q=${encodeURIComponent(q)}&klas=${encodeURIComponent(currentMaterialKlas)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        if (!json.data || json.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="muted text-center" style="padding:12px;">Tidak ada barang yang cocok.</td></tr>';
            return;
        }

        let html = '';
        json.data.forEach(item => {
            html += `
                <tr ondblclick="pickMaterialItem('${escapeHtml(item.kode_barang)}', '${escapeHtml(item.uraian)}', '${escapeHtml(item.sat)}', ${item.harga || 0}, '${escapeHtml(item.pos_tarif || '')}')" style="cursor:pointer;">
                    <td><b>${escapeHtml(item.kode_barang)}</b></td>
                    <td>${escapeHtml(item.uraian)}</td>
                    <td>${escapeHtml(item.sat)}</td>
                    <td class="text-right">${numberFormat(item.harga)}</td>
                    <td class="text-right">${numberFormat(item.berat)}</td>
                    <td><small>${escapeHtml(item.klas)}</small></td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" style="color:var(--danger); text-align:center; padding:12px;">${e.message}</td></tr>`;
    }
}

function pickMaterialItem(kode, uraian, sat, harga, posTarif) {
    if (currentActiveTargetInput) {
        const row = currentActiveTargetInput.closest('tr');
        if (row) {
            row.querySelector('.it-kode').value = kode;
            row.querySelector('.it-uraian').value = uraian;
            row.querySelector('.it-sat').value = sat;
            if (parseFloat(row.querySelector('.it-price').value) === 0) {
                row.querySelector('.it-price').value = harga;
            }
            if (row.querySelector('.it-postarif')) row.querySelector('.it-postarif').value = posTarif;
            calcItemRow(row.querySelector('.it-qty'));
        }
    } else {
        addItemRow({ kode_barang: kode, uraian: uraian, sat: sat, price: harga, pos_tarif: posTarif });
    }

    if (!document.getElementById('materialMulti').checked) {
        closeMaterialPicker();
    }
}

async function quickSearchMaterial(inputEl) {
    const val = inputEl.value.trim();
    if (!val) return;
    try {
        const res = await fetch(`purchase_return.php?action=search_materials&q=${encodeURIComponent(val)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status === 'OK' && json.data && json.data.length > 0) {
            const item = json.data[0];
            const row = inputEl.closest('tr');
            row.querySelector('.it-kode').value = item.kode_barang;
            row.querySelector('.it-uraian').value = item.uraian;
            row.querySelector('.it-sat').value = item.sat || 'PCE';
            if (parseFloat(row.querySelector('.it-price').value) === 0) {
                row.querySelector('.it-price').value = item.harga || 0;
            }
            if (row.querySelector('.it-postarif')) row.querySelector('.it-postarif').value = item.pos_tarif || '';
            calcItemRow(row.querySelector('.it-qty'));
        }
    } catch (e) {}
}

// -------------------------------------------------------------------------
// TRACEABILITY DOKUMEN (TAB 3)
// -------------------------------------------------------------------------
async function reloadTrace() {
    const id = document.getElementById('prId').value.trim();
    const container = document.getElementById('traceContainer');
    if (!id) {
        container.innerHTML = '<div class="muted text-center" style="padding:24px;">Silakan buka atau simpan transaksi retur terlebih dahulu untuk melihat matriks trace keterlacakan dokumen.</div>';
        return;
    }

    container.innerHTML = '<div class="muted text-center" style="padding:24px;">Memuat peta keterlacakan dokumen...</div>';
    try {
        const res = await fetch(`purchase_return.php?action=trace&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        const h = json.header;
        let html = `
            <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:3px; padding:10px; margin-bottom:12px;">
                <h4 style="color:var(--primary); margin-bottom:4px;"><i class="fa-solid fa-rotate-left"></i> Dokumen Retur: ${escapeHtml(h.pr_no)}</h4>
                <div class="muted" style="font-size:9.5px;">Tanggal: ${h.pr_dt} | Vendor: ${escapeHtml(h.vendor_nama)} | Status: ${h.status} | Total: ${numberFormat(h.total_order)}</div>
                <div style="margin-top:6px; display:flex; gap:10px; font-size:9.5px;">
                    <span><b>Dok. Pabean:</b> ${escapeHtml(h.bc || 'Non-BC')}</span>
                    <span><b>No. Daftar BC:</b> ${escapeHtml(h.no_bc || '-')}</span>
                    <span><b>Tgl BC:</b> ${h.tgl_bc || '-'}</span>
                    <span><b>Nomor Aju:</b> ${escapeHtml(h.no_aju || '-')}</span>
                </div>
            </div>

            <!-- Matriks Relasi PI -->
            <div style="font-weight:600; margin:10px 0 4px;"><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Faktur Pembelian Terkait (Purchase Invoice / PI):</div>
            <div class="grid-wrap" style="border:1px solid var(--border-color); border-radius:3px; margin-bottom:12px;">
                <table class="dw">
                    <thead>
                        <tr>
                            <th>No. Faktur (PI)</th>
                            <th>Tgl Faktur</th>
                            <th>Term</th>
                            <th class="text-right">Total Tagihan</th>
                            <th class="text-right">Terbayar</th>
                            <th class="text-center">Dok. BC</th>
                            <th>No. Daftar BC</th>
                            <th>No. Aju</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        if (json.pi_list && json.pi_list.length > 0) {
            json.pi_list.forEach(p => {
                html += `
                    <tr>
                        <td><b>${escapeHtml(p.pi_no)}</b></td>
                        <td>${p.pi_dt}</td>
                        <td>${escapeHtml(p.term || '-')}</td>
                        <td class="text-right">${numberFormat(p.total_tagihan)}</td>
                        <td class="text-right">${numberFormat(p.terbayar)}</td>
                        <td class="text-center">${escapeHtml(p.bc || '-')}</td>
                        <td>${escapeHtml(p.no_bc || '-')}</td>
                        <td>${escapeHtml(p.no_aju || '-')}</td>
                    </tr>
                `;
            });
        } else {
            html += '<tr><td colspan="8" class="muted text-center" style="padding:8px;">Tidak ada referensi nomor PI yang tercatat pada item.</td></tr>';
        }
        html += `
                    </tbody>
                </table>
            </div>

            <!-- Matriks Relasi RI -->
            <div style="font-weight:600; margin:10px 0 4px;"><i class="fa-solid fa-dolly" style="color:#0ea5e9;"></i> Penerimaan Barang Terkait (Goods Receipt / RI):</div>
            <div class="grid-wrap" style="border:1px solid var(--border-color); border-radius:3px; margin-bottom:12px;">
                <table class="dw">
                    <thead>
                        <tr>
                            <th>No. RI</th>
                            <th>Tgl Terima</th>
                            <th>No. Surat Jalan</th>
                            <th>No. PO</th>
                            <th class="text-center">Dok. BC</th>
                            <th>No. Daftar BC</th>
                            <th>Nomor Aju</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        if (json.ri_list && json.ri_list.length > 0) {
            json.ri_list.forEach(r => {
                html += `
                    <tr>
                        <td><b>${escapeHtml(r.ri_no)}</b></td>
                        <td>${r.ri_dt}</td>
                        <td>${escapeHtml(r.no_sj || '-')}</td>
                        <td>${escapeHtml(r.po_no || '-')}</td>
                        <td class="text-center">${escapeHtml(r.bc || '-')}</td>
                        <td>${escapeHtml(r.no_bc || '-')}</td>
                        <td>${escapeHtml(r.no_aju || '-')}</td>
                    </tr>
                `;
            });
        } else {
            html += '<tr><td colspan="7" class="muted text-center" style="padding:8px;">Tidak ada referensi nomor RI yang tercatat pada item.</td></tr>';
        }
        html += `
                    </tbody>
                </table>
            </div>
        `;

        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = `<div style="color:var(--danger); text-align:center; padding:20px;">${e.message}</div>`;
    }
}

// -------------------------------------------------------------------------
// RIGHT CLICK CONTEXT MENU & MODAL GUIDE
// -------------------------------------------------------------------------
function openContextMenu(e) {
    e.preventDefault();
    const menu = document.getElementById('gridContextMenu');
    menu.style.display = 'block';
    menu.style.left = `${Math.min(e.clientX, window.innerWidth - 180)}px`;
    menu.style.top = `${Math.min(e.clientY, window.innerHeight - 150)}px`;
}

function deleteSelectedRow() {
    if (currentSelectedRow) {
        removeItemRow(currentSelectedRow.querySelector('button'));
    }
}

function toggleExcelMenu() {
    const el = document.getElementById('excelDrop');
    el.style.display = el.style.display === 'block' ? 'none' : 'block';
}

function openModuleGuide() {
    document.getElementById('modalGuide').classList.add('active');
}
function closeModuleGuide() {
    document.getElementById('modalGuide').classList.remove('active');
}

// -------------------------------------------------------------------------
// EXCEL IMPORT & EXPORT (SHEETJS)
// -------------------------------------------------------------------------
function exportExcelSheetJS() {
    const table = document.getElementById('tblItems');
    const wsData = [
        ['NO', 'KODE_BARANG', 'URAIAN_BARANG', 'SATUAN', 'QTY_RETUR', 'HARGA_SATUAN', 'DISKON_PCT', 'JUMLAH_NILAI', 'REF_PI', 'REF_RI', 'ALASAN_RETUR']
    ];

    document.querySelectorAll('#itemsBody tr').forEach((tr, idx) => {
        const kd = tr.querySelector('.it-kode').value;
        const ur = tr.querySelector('.it-uraian').value;
        const sat = tr.querySelector('.it-sat').value;
        const qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
        const price = parseFloat(tr.querySelector('.it-price').value) || 0;
        const disc = parseFloat(tr.querySelector('.it-disc').value) || 0;
        const amt = parseFloat(tr.querySelector('.it-amount').value) || 0;
        const pi = tr.querySelector('.it-pi').value;
        const ri = tr.querySelector('.it-ri').value;
        const note = tr.querySelector('.it-note').value;

        if (kd || ur) {
            wsData.push([idx + 1, kd, ur, sat, qty, price, disc, amt, pi, ri, note]);
        }
    });

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(wsData);
    XLSX.utils.book_append_sheet(wb, ws, 'Retur_Pembelian');
    const prNo = document.getElementById('prNo').value.trim() || 'Retur_Pembelian';
    XLSX.writeFile(wb, `${prNo}.xlsx`);
}

function downloadExcelTemplate() {
    const wsData = [
        ['KODE_BARANG', 'URAIAN_BARANG', 'SATUAN', 'QTY_RETUR', 'HARGA_SATUAN', 'DISKON_PCT', 'REF_PI', 'REF_RI', 'ALASAN_RETUR'],
        ['SAMPLE-001', 'BAHAN BAKU KAIN POLYESTER', 'KGM', 100, 25000, 0, 'PI-2610-0001', 'RI-2610-0001', 'Warna kain tidak sesuai spesifikasi']
    ];
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(wsData);
    XLSX.utils.book_append_sheet(wb, ws, 'Template_Retur');
    XLSX.writeFile(wb, `Template_Import_Retur.xlsx`);
}

function triggerImportExcel() {
    if (guardEdit()) return;
    document.getElementById('importExcelFile').click();
}

function handleExcelFileSelected(input) {
    const file = input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const rows = XLSX.utils.sheet_to_json(worksheet, { defval: '' });

            if (rows.length === 0) {
                Swal.fire('Error', 'File excel kosong atau format tidak sesuai.', 'error');
                return;
            }

            const tbody = document.getElementById('itemsBody');
            tbody.innerHTML = '';
            rows.forEach(r => {
                const kd = r.KODE_BARANG || r.kode_barang || '';
                const ur = r.URAIAN_BARANG || r.uraian || '';
                const sat = r.SATUAN || r.sat || 'PCE';
                const qty = parseFloat(r.QTY_RETUR || r.qty || 1);
                const price = parseFloat(r.HARGA_SATUAN || r.price || 0);
                const disc = parseFloat(r.DISKON_PCT || r.disc || 0);
                const pi = r.REF_PI || r.pi_no || '';
                const ri = r.REF_RI || r.ri_no || '';
                const note = r.ALASAN_RETUR || r.note || '';

                addItemRow({
                    kode_barang: kd,
                    uraian: ur,
                    sat: sat,
                    qty: qty,
                    price: price,
                    disc: disc,
                    amount: (qty * price) - (qty * price * (disc / 100)),
                    pi_no: pi,
                    ri_no: ri,
                    note: note
                });
            });

            calcPrSummary();
            Swal.fire('Sukses', `Berhasil mengimpor ${rows.length} baris barang dari Excel.`, 'success');
        } catch (err) {
            Swal.fire('Gagal Impor', err.message, 'error');
        }
    };
    reader.readAsArrayBuffer(file);
    input.value = '';
}

// -------------------------------------------------------------------------
// FORMATTING UTILITIES & TERBILANG
// -------------------------------------------------------------------------
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

function numberFormat(val, decimals = 2) {
    const num = parseFloat(val) || 0;
    return num.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function parseNumber(str) {
    if (!str) return 0;
    return parseFloat(str.replace(/,/g, '')) || 0;
}

function terbilang(n, kurs = 'IDR') {
    n = Math.abs(parseFloat(n) || 0);
    const satuanArr = ["", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"];
    
    function bilang(x) {
        x = Math.floor(x);
        if (x < 12) return satuanArr[x];
        if (x < 20) return bilang(x - 10) + " Belas";
        if (x < 100) return bilang(Math.floor(x / 10)) + " Puluh " + bilang(x % 10);
        if (x < 200) return "Seratus " + bilang(x - 100);
        if (x < 1000) return bilang(Math.floor(x / 100)) + " Ratus " + bilang(x % 100);
        if (x < 2000) return "Seribu " + bilang(x - 1000);
        if (x < 1000000) return bilang(Math.floor(x / 1000)) + " Ribu " + bilang(x % 1000);
        if (x < 1000000000) return bilang(Math.floor(x / 1000000)) + " Juta " + bilang(x % 1000000);
        if (x < 1000000000000) return bilang(Math.floor(x / 1000000000)) + " Milyar " + bilang(x % 1000000000);
        return bilang(Math.floor(x / 1000000000000)) + " Triliun " + bilang(x % 1000000000000);
    }

    if (n === 0) return kurs === 'USD' ? '# Nol Dollar #' : '# Nol Rupiah #';
    const bulat = Math.floor(n);
    const sen = Math.round((n - bulat) * 100);
    let str = bilang(bulat).trim().replace(/\s+/g, ' ');

    let currencyWord = "Rupiah";
    if (kurs === 'USD') currencyWord = "Dollar";
    else if (kurs === 'EUR') currencyWord = "Euro";
    else if (kurs === 'JPY') currencyWord = "Yen";
    else if (kurs === 'SGD') currencyWord = "Singapura Dollar";

    if (sen > 0) {
        str += " " + currencyWord + " " + bilang(sen) + " Sen";
    } else {
        str += " " + currencyWord;
    }
    return `# ${str} #`;
}

// -------------------------------------------------------------------------
// FITUR INOVATIF: DIRECT BRIDGE DRAFT DOKUMEN BC KE STAGING CEISA 4.0
// -------------------------------------------------------------------------
function openCeisaDraftModal() {
    const id = document.getElementById('prId').value;
    if (!id) {
        Swal.fire({
            icon: 'info',
            title: 'Simpan Transaksi Terlebih Dahulu',
            text: 'Silakan klik tombol "Simpan Transaksi" terlebih dahulu sebelum membuat Draft Dokumen BC ke CEISA 4.0.',
            confirmButtonColor: 'var(--primary)'
        });
        return;
    }

    const prBc = document.getElementById('prBc').value || '2.7';
    const rate = document.getElementById('prRate').value || '1.00';
    const kurs = document.getElementById('prKurs').value || 'IDR';

    const vendorSel = document.getElementById('prVendor');
    let vText = 'Vendor Belum Dipilih';
    if (vendorSel && vendorSel.selectedIndex >= 0) {
        vText = vendorSel.options[vendorSel.selectedIndex].text;
    }

    let itemCount = 0;
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        const kd = tr.querySelector('.it-kd') ? tr.querySelector('.it-kd').value : '';
        const ur = tr.querySelector('.it-uraian') ? tr.querySelector('.it-uraian').value : '';
        if (kd || ur) itemCount++;
    });

    if (itemCount === 0) {
        Swal.fire('Peringatan', 'Rincian barang retur masih kosong. Tambahkan minimal 1 barang sebelum membuat draft BC.', 'warning');
        return;
    }

    const totalVal = document.getElementById('txtGrandTotal').innerText;

    // Isi ke Modal
    document.getElementById('draftBcType').value = prBc;
    document.getElementById('draftNdpbm').value = rate;
    document.getElementById('draftValuta').value = kurs;
    document.getElementById('draftVendorName').innerText = vText;
    document.getElementById('draftItemCount').innerText = `${itemCount} Item Barang`;
    document.getElementById('draftTotalValue').innerText = `${kurs} ${totalVal}`;

    document.getElementById('modalCeisaDraft').style.display = 'flex';
}

function closeCeisaDraftModal() {
    document.getElementById('modalCeisaDraft').style.display = 'none';
}

async function submitCeisaDraft() {
    const id = document.getElementById('prId').value;
    const kodeBc = document.getElementById('draftBcType').value;
    const ndpbm = document.getElementById('draftNdpbm').value;
    const valuta = document.getElementById('draftValuta').value;

    closeCeisaDraftModal();
    Swal.fire({
        title: 'Membuat Dokumen BC di Staging CEISA...',
        html: '<div style="font-size:11px; color:#666;">Menyusun tabel <code>bc_ceisa_header</code>, <code>bc_ceisa_barang</code>, <code>bc_ceisa_dokumen</code>, <code>bc_ceisa_kemasan</code> & <code>bc_ceisa_entitas</code>...</div>',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('kode_dokumen', kodeBc);
        formData.append('ndpbm', ndpbm);
        formData.append('valuta', valuta);
        formData.append('cmp_id', CURRENT_CMP_ID);

        const res = await fetch('purchase_return.php?action=create_draft_bc', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        Swal.close();

        if (json.status !== 'OK') throw new Error(json.message);

        // Update form values
        document.getElementById('prNoAju').value = json.nomor_aju;
        document.getElementById('prBc').value = kodeBc;
        const btnOpenEd = document.getElementById('btnOpenEditor');
        if (btnOpenEd) btnOpenEd.style.display = 'inline-flex';

        Swal.fire({
            icon: 'success',
            title: 'Draft BC Berhasil Dibuat!',
            html: `<div style="text-align:left; font-size:11px; line-height:1.6;">
                    Dokumen <b>BC ${json.kode_dokumen}</b> berhasil dibuat di Staging CEISA 4.0!<br>
                    Nomor AJU (26 Digit):<br>
                    <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:4px; padding:6px 10px; font-weight:700; color:#15803d; font-size:11px; margin:6px 0; word-break:break-all;">
                        ${json.nomor_aju}
                    </div>
                    Data header, barang (${document.getElementById('draftItemCount').innerText}), faktur retur, kemasan, dan entitas telah tersinkronisasi.<br><br>
                    Anda dapat langsung meninjau & melengkapi dokumen di <b>Form Editor CEISA 4.0</b>.
                   </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-arrow-up-right-from-square"></i> Buka di Form Editor BC',
            cancelButtonText: 'Tetap di Form Retur',
            confirmButtonColor: '#059669',
            cancelButtonColor: '#6b7280'
        }).then(action => {
            if (action.isConfirmed) {
                window.open(json.editor_url, '_blank');
            }
        });
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

function openCeisaEditorDirect() {
    const noAju = document.getElementById('prNoAju').value.trim();
    if (!noAju) {
        Swal.fire('Informasi', 'Nomor AJU belum terisi.', 'info');
        return;
    }
    window.open(`/editor.php?nomor_aju=${encodeURIComponent(noAju)}`, '_blank');
}
</script>
</body>
</html>

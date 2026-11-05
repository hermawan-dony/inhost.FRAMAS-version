<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Purchase Invoice (PI) / Faktur Pembelian AP
 * Hasil Porting Sempurna dari PowerBuilder Desktop TPB-ERP v2.0 (w_pi.srw / bc_pi_hdr.srd / d_pi_dtl.srd / d_pi_browse.srd / d_ri_pi.srd / d_po_pi.srd / d_pi_prt_a.srd / d_rinci_pi_bayar.srd)
 * Desain, Tata Letak, dan Interaktivitas 100% SAMA & IDENTIK DENGAN Sales Invoice (SI) / sales_invoice.php:
 * - Header Card dengan Toolbar Aksi Langsung di Kanan
 * - 3 Tab Navigasi: 1. Entri (Faktur Pembelian), 2. Browse Data, 3. Trace Penerimaan (RI) & Dokumen BC
 * - Split-Pane Layout dengan Resizable Splitbar (#splitHdr & #splitFtr)
 * - 2-Kolom Header Grid (1.15fr 1fr) dengan .pair-box, .checks-box, dan .highlight-inv-no
 * - Lock Banner IT Inventory (#piLock) & Integrasi Pembukuan IT Inventory (bc_1ab2, AB='A')
 * - Terbilang Callout Bahasa Indonesia (.terbilang-callout) & Sum-Box 2 Kolom Finansial
 * - Context Menu Klik Kanan PB Style, Resizable Columns (.col-resizer), Modal Master Barang Multi-Klasifikasi,
 *   Modal Tarik RI (GRN), Modal Tarik PO, Modal Riwayat Bayar AP, Menu Export & Import Excel.
 */
session_start();
require_once __DIR__ . '/../datalogin.php';
require_once __DIR__ . '/audit_trail.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($_GET['cmp_id'] ?? ($cmp_id ?? '')));
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

$activeCmpName = $_SESSION['company_name'] ?? '';
$companyLogo   = '/assets/logo.png';
$companyAlamat = '';
$companyNpwp   = '';
$companyTelp   = '';

if (!empty($sessionCmpId)) {
    $qCmp = $koneksi->query("SELECT cmp_name, logo, alamat, npwp FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        if (!empty($rCmp['cmp_name'])) $activeCmpName = $rCmp['cmp_name'];
        if (!empty($rCmp['logo'])) $companyLogo = $rCmp['logo'];
        if (!empty($rCmp['alamat'])) $companyAlamat = $rCmp['alamat'];
        if (!empty($rCmp['npwp'])) $companyNpwp = $rCmp['npwp'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'PT. PERUSAHAAN';

// Auto-Heal: Pastikan tabel dan kolom bc_pi_hdr dan bc_pi_dtl tersedia sesuai PB
function ensurePiTables($koneksi) {
    try {
        $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_pi_hdr'");
        if (!$checkHdr || $checkHdr->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pi_hdr` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `pi_no` varchar(50) NOT NULL DEFAULT '',
                `pi_dt` date DEFAULT NULL,
                `ship_dt` date DEFAULT NULL,
                `jatuhtempo` date DEFAULT NULL,
                `po_no` varchar(300) DEFAULT '',
                `ri_no` varchar(300) DEFAULT '',
                `fob` varchar(20) DEFAULT '1',
                `term` varchar(50) DEFAULT 'Net 30',
                `ship` varchar(50) DEFAULT '',
                `vendor` varchar(50) NOT NULL DEFAULT '',
                `shipto` varchar(50) DEFAULT '',
                `vendoristaxable` char(1) DEFAULT 'Y',
                `inclusivetax` char(1) DEFAULT 'N',
                `issubcon` char(1) DEFAULT 'N',
                `ncv` char(1) DEFAULT '0',
                `bill2buyer` char(1) DEFAULT 'N',
                `note` text DEFAULT NULL,
                `subtotal` decimal(18,4) DEFAULT '0.0000',
                `disc_persen` decimal(10,2) DEFAULT '0.00',
                `disc_amt` decimal(18,4) DEFAULT '0.0000',
                `ppn` decimal(18,4) DEFAULT '0.0000',
                `pph` decimal(18,4) DEFAULT '0.0000',
                `jasa` decimal(18,4) DEFAULT '0.0000',
                `est_freight` decimal(18,4) DEFAULT '0.0000',
                `total_order` decimal(18,4) DEFAULT '0.0000',
                `dp` decimal(18,4) DEFAULT '0.0000',
                `terbayar` decimal(18,4) DEFAULT '0.0000',
                `outstand` decimal(18,4) DEFAULT '0.0000',
                `kurs` varchar(10) DEFAULT 'IDR',
                `rate` decimal(18,4) DEFAULT '1.0000',
                `birate` decimal(18,4) DEFAULT '1.0000',
                `set_by` varchar(50) DEFAULT '',
                `status` char(1) DEFAULT '0',
                `kd_fp` varchar(10) DEFAULT '01',
                `no_fp` varchar(50) DEFAULT '',
                `bc` varchar(10) DEFAULT '',
                `no_bc` varchar(50) DEFAULT '',
                `tgl_bc` date DEFAULT NULL,
                `no_aju` varchar(50) DEFAULT '',
                `gudang` varchar(30) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_pi` (`cmp_id`, `pi_no`),
                KEY `idx_vendor` (`vendor`),
                KEY `idx_pi_dt` (`pi_dt`),
                KEY `idx_po_no` (`po_no`),
                KEY `idx_no_fp` (`no_fp`),
                KEY `idx_bc` (`bc`, `no_bc`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $hdrCols = [];
            $hdrColRes = $koneksi->query("SHOW COLUMNS FROM bc_pi_hdr");
            if ($hdrColRes) while ($r = $hdrColRes->fetch_assoc()) $hdrCols[strtolower($r['Field'])] = true;
            $hdrExtra = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'ri_no' => "varchar(300) DEFAULT ''",
                'po_no' => "varchar(300) DEFAULT ''",
                'jatuhtempo' => "date DEFAULT NULL",
                'kd_fp' => "varchar(10) DEFAULT '01'",
                'no_fp' => "varchar(50) DEFAULT ''",
                'bc' => "varchar(10) DEFAULT ''",
                'no_bc' => "varchar(50) DEFAULT ''",
                'tgl_bc' => "date DEFAULT NULL",
                'no_aju' => "varchar(50) DEFAULT ''",
                'shipto' => "varchar(50) DEFAULT ''",
                'jasa' => "decimal(18,4) DEFAULT '0.0000'",
                'birate' => "decimal(18,4) DEFAULT '1.0000'",
                'ncv' => "char(1) DEFAULT '0'",
                'bill2buyer' => "char(1) DEFAULT 'N'",
                'issubcon' => "char(1) DEFAULT 'N'",
                'ship_dt' => "date DEFAULT NULL",
                'vendoristaxable' => "char(1) DEFAULT 'Y'",
                'inclusivetax' => "char(1) DEFAULT 'N'",
                'est_freight' => "decimal(18,4) DEFAULT '0.0000'",
                'gudang' => "varchar(30) DEFAULT ''"
            ];
            foreach ($hdrExtra as $colName => $colDef) {
                if (!isset($hdrCols[$colName])) {
                    @$koneksi->query("ALTER TABLE bc_pi_hdr ADD COLUMN `$colName` $colDef");
                }
            }
        }

        // 2. Table bc_pi_dtl
        $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_pi_dtl'");
        if (!$checkDtl || $checkDtl->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_pi_dtl` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `id_hdr` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `pi_no` varchar(50) NOT NULL DEFAULT '',
                `seq` int(11) NOT NULL DEFAULT '1',
                `id_po` varchar(40) DEFAULT '',
                `po_no` varchar(50) DEFAULT '',
                `id_ri` varchar(40) DEFAULT '',
                `ri_no` varchar(50) DEFAULT '',
                `kode_barang` varchar(50) NOT NULL DEFAULT '',
                `uraian` varchar(255) NOT NULL DEFAULT '',
                `qty` decimal(18,4) DEFAULT '0.0000',
                `sat` varchar(20) DEFAULT '',
                `price` decimal(18,4) DEFAULT '0.0000',
                `disc` decimal(10,2) DEFAULT '0.00',
                `amount` decimal(18,4) DEFAULT '0.0000',
                `price_jasa` decimal(18,4) DEFAULT '0.0000',
                `amt_jasa` decimal(18,4) DEFAULT '0.0000',
                `terima` decimal(18,4) DEFAULT '0.0000',
                `gudang` varchar(30) DEFAULT '',
                `pos_tarif` varchar(20) DEFAULT '',
                `cif` decimal(18,4) DEFAULT '0.0000',
                `cif_rupiah` decimal(18,4) DEFAULT '0.0000',
                `hpp` decimal(18,4) DEFAULT '0.0000',
                `dept` varchar(30) DEFAULT '',
                `note` text DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_dtl` (`cmp_id`, `id_hdr`),
                KEY `idx_kode` (`kode_barang`),
                KEY `idx_ri` (`ri_no`),
                KEY `idx_po` (`po_no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $dtlCols = [];
            $dtlColRes = $koneksi->query("SHOW COLUMNS FROM bc_pi_dtl");
            if ($dtlColRes) while ($r = $dtlColRes->fetch_assoc()) $dtlCols[strtolower($r['Field'])] = true;
            $dtlExtra = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'id_hdr' => "varchar(40) NOT NULL DEFAULT ''",
                'pi_no' => "varchar(50) NOT NULL DEFAULT ''",
                'id_po' => "varchar(40) DEFAULT ''",
                'po_no' => "varchar(50) DEFAULT ''",
                'id_ri' => "varchar(40) DEFAULT ''",
                'ri_no' => "varchar(50) DEFAULT ''",
                'price_jasa' => "decimal(18,4) DEFAULT '0.0000'",
                'amt_jasa' => "decimal(18,4) DEFAULT '0.0000'",
                'terima' => "decimal(18,4) DEFAULT '0.0000'",
                'gudang' => "varchar(30) DEFAULT ''",
                'pos_tarif' => "varchar(20) DEFAULT ''",
                'cif' => "decimal(18,4) DEFAULT '0.0000'",
                'cif_rupiah' => "decimal(18,4) DEFAULT '0.0000'",
                'hpp' => "decimal(18,4) DEFAULT '0.0000'",
                'dept' => "varchar(30) DEFAULT ''"
            ];
            foreach ($dtlExtra as $colName => $colDef) {
                if (!isset($dtlCols[$colName])) {
                    @$koneksi->query("ALTER TABLE bc_pi_dtl ADD COLUMN `$colName` $colDef");
                }
            }
        }
    } catch (\Throwable $e) {}
}
ensurePiTables($koneksi);

// Helper Auto-generate Nomor Faktur Pembelian (Format PB: PI-YYMM-XXXX)
function generatePiNumber($koneksi, $cmpId) {
    $yymm = date('ym');
    $prefix = "PI-{$yymm}-";
    $escPrefix = $koneksi->real_escape_string($prefix);
    $escCmp = $koneksi->real_escape_string($cmpId);

    $sql = "SELECT pi_no FROM bc_pi_hdr 
            WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
              AND pi_no LIKE '{$escPrefix}%' 
            ORDER BY pi_no DESC LIMIT 1";
    $res = $koneksi->query($sql);
    $nextNum = 1;
    if ($res && $row = $res->fetch_assoc()) {
        $lastPart = substr($row['pi_no'], strlen($prefix));
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

// 1. LIST DOKUMEN FAKTUR PEMBELIAN (TAB 2 / BROWSE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $limit        = max(10, min(500, (int)($_GET['limit'] ?? 25)));
        $offset       = ($page - 1) * $limit;
        $search       = trim($_GET['search'] ?? '');
        $vendorFilter = trim($_GET['vendor'] ?? '');
        $bcFilter     = trim($_GET['bc_filter'] ?? '');
        $statusFilter = trim($_GET['status_filter'] ?? '');
        $tglAwal      = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir     = trim($_GET['tgl_akhir'] ?? '');

        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(h.pi_no LIKE '%{$escS}%' OR h.vendor LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%' OR h.po_no LIKE '%{$escS}%' OR h.ri_no LIKE '%{$escS}%' OR h.no_fp LIKE '%{$escS}%' OR h.no_aju LIKE '%{$escS}%' OR h.no_bc LIKE '%{$escS}%')";
        }
        if (!empty($vendorFilter)) {
            $escV = $koneksi->real_escape_string($vendorFilter);
            $where[] = "(h.vendor = '{$escV}' OR b.NAMA LIKE '%{$escV}%' OR b.NAMA_PENDEK LIKE '%{$escV}%' OR b.KODE LIKE '%{$escV}%')";
        }
        if (!empty($bcFilter)) {
            $escBc = $koneksi->real_escape_string($bcFilter);
            $where[] = "h.bc = '{$escBc}'";
        }
        if ($statusFilter !== '' && $statusFilter !== 'ALL') {
            $escSt = $koneksi->real_escape_string($statusFilter);
            $where[] = "h.status = '{$escSt}'";
        }
        if (!empty($tglAwal)) {
            $escAwal = $koneksi->real_escape_string($tglAwal);
            $where[] = "h.pi_dt >= '{$escAwal}'";
        }
        if (!empty($tglAkhir)) {
            $escAkhir = $koneksi->real_escape_string($tglAkhir);
            $where[] = "h.pi_dt <= '{$escAkhir}'";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $cntRes = $koneksi->query("SELECT COUNT(1) as total FROM bc_pi_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL)) {$whereSql}");
        $total = $cntRes ? (int)$cntRes->fetch_assoc()['total'] : 0;

        $sql = "SELECT h.id, h.cmp_id, h.pi_no, h.pi_dt, h.jatuhtempo, h.term, h.kurs, h.rate, h.vendor,
                       h.subtotal, h.disc_persen, h.disc_amt, h.ppn, h.est_freight, h.total_order, h.dp,
                       h.terbayar, h.jasa, h.pph, h.total_jasa, h.retur, h.outstand,
                       h.status, h.kd_fp, h.no_fp, h.no_bc, h.tgl_bc, h.no_aju, h.bc, h.po_no, h.ri_no, h.note, h.set_by,
                       COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                       COALESCE(b.NAMA_PENDEK, '') AS vendor_short,
                       b.ALAMAT AS vendor_alamat,
                       b.NPWP AS vendor_npwp,
                       (SELECT COUNT(1) FROM bc_pi_dtl d WHERE d.id_hdr = h.id) as item_count,
                       (SELECT 1 FROM bc_1ab2 x WHERE x.ID_HEADER = h.id LIMIT 1) as is_it_inv_locked
                FROM bc_pi_hdr h
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY h.pi_dt DESC, h.pi_no DESC
                LIMIT {$offset}, {$limit}";

        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['subtotal_fmt'] = number_format((float)$r['subtotal'], 2, '.', ',');
                $r['ppn_fmt'] = number_format((float)$r['ppn'], 2, '.', ',');
                $r['total_order_fmt'] = number_format((float)$r['total_order'], 2, '.', ',');
                $r['terbayar_fmt'] = number_format((float)$r['terbayar'], 2, '.', ',');
                $r['outstand_fmt'] = number_format((float)$r['outstand'], 2, '.', ',');
                $r['pi_dt_fmt'] = !empty($r['pi_dt']) ? date('d/m/Y', strtotime($r['pi_dt'])) : '-';
                $r['jatuhtempo_fmt'] = !empty($r['jatuhtempo']) ? date('d/m/Y', strtotime($r['jatuhtempo'])) : '-';
                $r['tgl_bc_fmt'] = !empty($r['tgl_bc']) ? date('d/m/Y', strtotime($r['tgl_bc'])) : '-';
                $r['is_locked'] = !empty($r['is_it_inv_locked']);
                $data[] = $r;
            }
        }

        // Summary KPI Strip
        $kpiWhere = !empty($sessionCmpId) ? "WHERE (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)" : "";
        $curMonth = date('Y-m');
        $kpiSql = "SELECT 
                    COUNT(1) as total_pi,
                    SUM(CASE WHEN DATE_FORMAT(h.pi_dt, '%Y-%m') = '{$curMonth}' THEN 1 ELSE 0 END) as pi_bulan_ini,
                    SUM(COALESCE(h.total_order, 0)) as total_nilai,
                    SUM(CASE WHEN (h.total_order - COALESCE(h.terbayar, 0)) > 0 AND h.status != '9' THEN 1 ELSE 0 END) as unpaid_pi,
                    SUM(CASE WHEN (h.total_order - COALESCE(h.terbayar, 0)) <= 0 AND h.status != '9' THEN 1 ELSE 0 END) as paid_pi
                   FROM bc_pi_hdr h {$kpiWhere}";
        $kpiRes = $koneksi->query($kpiSql);
        $kpiRow = $kpiRes ? $kpiRes->fetch_assoc() : [];

        $kpi = [
            'total_pi'     => (int)($kpiRow['total_pi'] ?? 0),
            'pi_bulan_ini' => (int)($kpiRow['pi_bulan_ini'] ?? 0),
            'total_nilai'  => 'IDR ' . number_format((float)($kpiRow['total_nilai'] ?? 0), 2, '.', ','),
            'unpaid_pi'    => (int)($kpiRow['unpaid_pi'] ?? 0),
            'paid_pi'      => (int)($kpiRow['paid_pi'] ?? 0)
        ];

        echo json_encode([
            'status' => 'OK',
            'data'   => $data,
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'total_pages' => ceil($total / $limit) ?: 1,
            'kpi'    => $kpi
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 2. DETAIL FAKTUR PEMBELIAN (UNTUK LOAD / EDIT)
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Faktur Pembelian tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hdrSql = "SELECT h.*, 
                          COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                          b.ALAMAT AS vendor_alamat,
                          b.NPWP AS vendor_npwp,
                          (SELECT 1 FROM bc_1ab2 x WHERE x.ID_HEADER = h.id LIMIT 1) as is_it_inv_locked
                   FROM bc_pi_hdr h
                   LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                   WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)
                   LIMIT 1";
        $hdrRes = $koneksi->query($hdrSql);
        if (!$hdrRes || $hdrRes->num_rows === 0) throw new Exception('Dokumen Faktur Pembelian tidak ditemukan.');
        $header = $hdrRes->fetch_assoc();
        $header['is_locked'] = !empty($header['is_it_inv_locked']);

        // Ambil rincian detail barang
        $dtlSql = "SELECT d.*
                   FROM bc_pi_dtl d
                   WHERE d.id_hdr = '{$escId}'
                   ORDER BY d.seq ASC, d.id ASC";
        $dtlRes = $koneksi->query($dtlSql);
        $details = [];
        if ($dtlRes) {
            while ($d = $dtlRes->fetch_assoc()) {
                $details[] = $d;
            }
        }

        echo json_encode(['status' => 'OK', 'header' => $header, 'details' => $details]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 3. LOOKUP PENERIMAAN BARANG (RI / GRN) BELUM DIFAKTURKAN (d_ri_pi.srd)
if ($action === 'lookup_ri_outstanding') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor = trim($_GET['vendor'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $escV = $koneksi->real_escape_string($vendor);
        $escS = $koneksi->real_escape_string($search);

        $where = ["(COALESCE(d.terima, 0) < d.qty)", "(h.status = '0' OR h.status = '1' OR h.status = '')"];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        if (!empty($vendor)) {
            $where[] = "(h.vendor = '{$escV}' OR b.NAMA LIKE '%{$escV}%' OR b.NAMA_PENDEK LIKE '%{$escV}%')";
        }
        if (!empty($search)) {
            $where[] = "(h.ri_no LIKE '%{$escS}%' OR h.po_no LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%')";
        }

        $whereSql = "WHERE " . implode(' AND ', $where);

        $sql = "SELECT d.id AS id_ri_dtl, d.id_hdr AS id_ri_hdr, d.seq, d.kode_barang, d.uraian,
                       d.qty AS qty_ri, COALESCE(d.terima, 0) AS qty_terima,
                       (d.qty - COALESCE(d.terima, 0)) AS qty_sisa,
                       d.sat, d.price, d.disc, d.amount, d.dept, d.note,
                       h.ri_no, h.ri_dt, h.po_no, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                       h.kurs, h.rate, h.term, h.no_sj, h.no_bc, h.tgl_bc, h.no_aju, h.bc
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
                $r['qty_sisa_fmt'] = number_format((float)$r['qty_sisa'], 2, '.', ',');
                $r['price_fmt'] = number_format((float)$r['price'], 2, '.', ',');
                $r['amount_fmt'] = number_format((float)$r['amount'], 2, '.', ',');
                $r['ri_dt_fmt'] = !empty($r['ri_dt']) ? date('d/m/Y', strtotime($r['ri_dt'])) : '-';
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 4. LOOKUP PO BELUM DIFAKTURKAN (DIRECT PO / JASA - d_po_pi.srd)
if ($action === 'lookup_po_outstanding') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor = trim($_GET['vendor'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $escV = $koneksi->real_escape_string($vendor);
        $escS = $koneksi->real_escape_string($search);

        $where = ["(h.status = '0' OR h.status = '1')"];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        if (!empty($vendor)) {
            $where[] = "(h.vendor = '{$escV}' OR b.NAMA LIKE '%{$escV}%' OR b.NAMA_PENDEK LIKE '%{$escV}%')";
        }
        if (!empty($search)) {
            $where[] = "(h.po_no LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%')";
        }

        $whereSql = "WHERE " . implode(' AND ', $where);

        $sql = "SELECT d.id AS id_po_dtl, d.id_hdr AS id_po_hdr, d.seq, d.kode_barang, d.uraian,
                       d.qty AS qty_po, COALESCE(d.terima, 0) AS qty_terima,
                       (d.qty - COALESCE(d.terima, 0)) AS qty_sisa,
                       d.sat, d.price, d.disc, d.amount, d.dept, d.note,
                       h.po_no, h.po_dt, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                       h.kurs, h.rate, h.term
                FROM bc_po_dtl d
                JOIN bc_po_hdr h ON d.id_hdr = h.id
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY h.po_dt DESC, h.po_no DESC, d.seq ASC
                LIMIT 150";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['qty_po_fmt'] = number_format((float)$r['qty_po'], 2, '.', ',');
                $r['qty_sisa_fmt'] = number_format((float)$r['qty_sisa'], 2, '.', ',');
                $r['price_fmt'] = number_format((float)$r['price'], 2, '.', ',');
                $r['amount_fmt'] = number_format((float)$r['amount'], 2, '.', ',');
                $r['po_dt_fmt'] = !empty($r['po_dt']) ? date('d/m/Y', strtotime($r['po_dt'])) : '-';
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 5. SEARCH MASTER BARANG (AUTOCOMPLETE / MULTI-KLASIFIKASI)
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

// 6. GENERATE NOMOR PI BARU (AUTO-INCREMENT FORMAT PI-YYMM-XXXX)
if ($action === 'generate_no') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $newNo = generatePiNumber($koneksi, $sessionCmpId);
        echo json_encode(['status' => 'OK', 'pi_no' => $newNo]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 7. SIMPAN FAKTUR PEMBELIAN (CREATE / UPDATE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload) throw new Exception('Format data JSON tidak valid.');

        $header  = $payload['header'] ?? [];
        $details = $payload['details'] ?? [];

        if (empty($header)) throw new Exception('Data Header Faktur Pembelian kosong.');
        if (empty($details)) throw new Exception('Rincian item barang wajib diisi minimal 1 baris.');

        $id             = trim($header['id'] ?? '');
        $piNo           = trim($header['pi_no'] ?? '');
        $piDt           = trim($header['pi_dt'] ?? date('Y-m-d'));
        $shipDt         = trim($header['ship_dt'] ?? '') ?: null;
        $jatuhtempo     = trim($header['jatuhtempo'] ?? '') ?: null;
        $vendor         = trim($header['vendor'] ?? '');
        $term           = trim($header['term'] ?? 'COD');
        $kurs           = trim($header['kurs'] ?? 'IDR') ?: 'IDR';
        $rate           = (float)($header['rate'] ?? 1);
        if ($rate <= 0) $rate = 1.0;
        $birate         = (float)($header['birate'] ?? $rate);
        $status         = trim($header['status'] ?? '0');
        $bc             = trim($header['bc'] ?? '');
        $noBc           = trim($header['no_bc'] ?? '');
        $tglBc          = trim($header['tgl_bc'] ?? '') ?: null;
        $noAju          = trim($header['no_aju'] ?? '');
        $kdFp           = trim($header['kd_fp'] ?? '01');
        $noFp           = trim($header['no_fp'] ?? '');
        $vendoristaxable= trim($header['vendoristaxable'] ?? 'Y');
        $inclusivetax   = trim($header['inclusivetax'] ?? 'N');
        $issubcon       = trim($header['issubcon'] ?? 'N');
        $ncv            = trim($header['ncv'] ?? '0');
        $bill2buyer     = trim($header['bill2buyer'] ?? 'N');
        $note           = trim($header['note'] ?? '');
        $gudang         = trim($header['gudang'] ?? '');

        $estFreight     = (float)($header['est_freight'] ?? 0);
        $jasa           = (float)($header['jasa'] ?? 0);
        $pph            = (float)($header['pph'] ?? 0);
        $dp             = (float)($header['dp'] ?? 0);
        $discPersen     = (float)($header['disc_persen'] ?? 0);
        $discAmt        = (float)($header['disc_amt'] ?? 0);

        if (empty($vendor)) throw new Exception('Pemasok / Vendor wajib dipilih.');

        // Cek apakah dokumen sedang terkunci IT Inventory
        if (!empty($id)) {
            $escChkId = $koneksi->real_escape_string($id);
            $chkLock = $koneksi->query("SELECT 1 FROM bc_1ab2 WHERE ID_HEADER = '{$escChkId}' LIMIT 1");
            if ($chkLock && $chkLock->num_rows > 0) {
                throw new Exception('Faktur Pembelian ini berstatus TERKUNCI di IT Inventory (bc_1ab2). Buka kunci terlebih dahulu sebelum mengedit.');
            }
        }

        $koneksi->begin_transaction();

        // Generate Nomor PI jika baru / kosong
        $isNew = empty($id);
        if ($isNew) {
            $id = 'PI-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            if (empty($piNo)) {
                $piNo = generatePiNumber($koneksi, $sessionCmpId);
            }
        }

        // Kalkulasi Subtotal dari baris detail
        $subtotal = 0;
        $poListToSync = [];
        $riListToSync = [];
        foreach ($details as $d) {
            $qty   = (float)($d['qty'] ?? 0);
            $price = (float)($d['price'] ?? 0);
            $disc  = (float)($d['disc'] ?? 0);
            $amt   = ($qty * $price) * (1 - ($disc / 100));
            $subtotal += $amt;

            if (!empty($d['ri_no'])) $riListToSync[] = trim($d['ri_no']);
            if (!empty($d['po_no'])) $poListToSync[] = trim($d['po_no']);
        }

        // Kalkulasi Diskon Global
        if ($discPersen > 0 && $discAmt <= 0) {
            $discAmt = ($subtotal * $discPersen) / 100;
        }
        $dpp = max(0, $subtotal - $discAmt);

        // Kalkulasi PPN
        $ppnTarif = (float)($header['ppn_tarif'] ?? 11);
        $ppn = 0;
        if ($vendoristaxable === 'Y' && $dpp > 0) {
            if ($inclusivetax === 'Y') {
                $ppn = $dpp * 11 / 111;
                $dpp = $dpp - $ppn;
            } else {
                $ppn = ($dpp * $ppnTarif) / 100;
            }
        }

        // Total Tagihan & Sisa Hutang
        $totalOrder = ($inclusivetax === 'Y' ? ($subtotal - $discAmt) : ($dpp + $ppn)) + $jasa + $estFreight - $pph;
        $terbayar   = (float)($header['terbayar'] ?? 0);
        $outstand   = max(0, $totalOrder - $dp - $terbayar);

        // Satukan daftar PO & RI acuan untuk header
        $poNoHeader = implode(', ', array_unique($poListToSync));
        if (strlen($poNoHeader) > 280) $poNoHeader = substr($poNoHeader, 0, 280) . '...';
        $riNoHeader = implode(', ', array_unique($riListToSync));
        if (strlen($riNoHeader) > 280) $riNoHeader = substr($riNoHeader, 0, 280) . '...';

        // Auto-status lunas jika terbayar penuh
        if ($totalOrder > 0 && $terbayar >= $totalOrder && $status !== '9') {
            $status = '2'; // Lunas
        } elseif ($status === '0' && !empty($piNo) && count($details) > 0) {
            $status = '1'; // Belum Lunas
        }

        $escId    = $koneksi->real_escape_string($id);
        $escPi    = $koneksi->real_escape_string($piNo);
        $escVnd   = $koneksi->real_escape_string($vendor);
        $escUser  = $koneksi->real_escape_string($currentUser);

        // Simpan / Update Header
        if ($isNew) {
            $stmt = $koneksi->prepare("INSERT INTO bc_pi_hdr (
                id, cmp_id, pi_no, pi_dt, ship_dt, jatuhtempo, vendor, term, kurs, rate, birate, note,
                kd_fp, no_fp, bc, no_bc, tgl_bc, no_aju, vendoristaxable, inclusivetax, issubcon, ncv, bill2buyer,
                subtotal, disc_persen, disc_amt, ppn, pph, jasa, est_freight, total_order, dp,
                terbayar, outstand, set_by, status, po_no, ri_no, gudang
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->bind_param("sssssssssddsssssssssssddddddddddssssss",
                $id, $sessionCmpId, $piNo, $piDt, $shipDt, $jatuhtempo, $vendor, $term, $kurs, $rate, $birate, $note,
                $kdFp, $noFp, $bc, $noBc, $tglBc, $noAju, $vendoristaxable, $inclusivetax, $issubcon, $ncv, $bill2buyer,
                $subtotal, $discPersen, $discAmt, $ppn, $pph, $jasa, $estFreight, $totalOrder, $dp,
                $terbayar, $outstand, $escUser, $status, $poNoHeader, $riNoHeader, $gudang
            );
            if (!$stmt->execute()) throw new Exception('Gagal menyimpan Header PI: ' . $stmt->error);
        } else {
            // Ambil RI lama sebelum update untuk rekalkulasi status
            $oldRiRes = $koneksi->query("SELECT DISTINCT ri_no FROM bc_pi_dtl WHERE id_hdr = '{$escId}' AND ri_no != ''");
            if ($oldRiRes) {
                while ($or = $oldRiRes->fetch_assoc()) $riListToSync[] = $or['ri_no'];
            }

            $stmt = $koneksi->prepare("UPDATE bc_pi_hdr SET
                pi_no = ?, pi_dt = ?, ship_dt = ?, jatuhtempo = ?, vendor = ?, term = ?, kurs = ?, rate = ?, birate = ?, note = ?,
                kd_fp = ?, no_fp = ?, bc = ?, no_bc = ?, tgl_bc = ?, no_aju = ?, vendoristaxable = ?, inclusivetax = ?, issubcon = ?, ncv = ?, bill2buyer = ?,
                subtotal = ?, disc_persen = ?, disc_amt = ?, ppn = ?, pph = ?, jasa = ?, est_freight = ?, total_order = ?, dp = ?,
                terbayar = ?, outstand = ?, status = ?, po_no = ?, ri_no = ?, gudang = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");

            $stmt->bind_param("ssssssddsssssssssssddddddddddssssss",
                $piNo, $piDt, $shipDt, $jatuhtempo, $vendor, $term, $kurs, $rate, $birate, $note,
                $kdFp, $noFp, $bc, $noBc, $tglBc, $noAju, $vendoristaxable, $inclusivetax, $issubcon, $ncv, $bill2buyer,
                $subtotal, $discPersen, $discAmt, $ppn, $pph, $jasa, $estFreight, $totalOrder, $dp,
                $terbayar, $outstand, $status, $poNoHeader, $riNoHeader, $gudang,
                $id, $sessionCmpId
            );
            if (!$stmt->execute()) throw new Exception('Gagal memperbarui Header PI: ' . $stmt->error);

            // Bersihkan baris detail lama
            $koneksi->query("DELETE FROM bc_pi_dtl WHERE id_hdr = '{$escId}'");
        }

        // Insert Detail Items
        $dStmt = $koneksi->prepare("INSERT INTO bc_pi_dtl (
            id, cmp_id, id_hdr, pi_no, seq, id_po, po_no, id_ri, ri_no,
            kode_barang, uraian, qty, sat, price, disc, amount, price_jasa, amt_jasa,
            gudang, pos_tarif, cif, cif_rupiah, hpp, dept, note
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        foreach ($details as $d) {
            $dtlId   = 'PID-' . date('YmdHis') . '-' . $seq . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $idPo    = trim($d['id_po'] ?? '');
            $pNo     = trim($d['po_no'] ?? '');
            $idRi    = trim($d['id_ri'] ?? '');
            $rNo     = trim($d['ri_no'] ?? '');
            $kd      = trim($d['kode_barang'] ?? '');
            $ur      = trim($d['uraian'] ?? '');
            $qty     = (float)($d['qty'] ?? 0);
            $sat     = trim($d['sat'] ?? 'PCE');
            $price   = (float)($d['price'] ?? 0);
            $disc    = (float)($d['disc'] ?? 0);
            $amt     = ($qty * $price) * (1 - ($disc / 100));
            $prcJasa = (float)($d['price_jasa'] ?? 0);
            $amtJasa = $qty * $prcJasa;
            $dGudang = trim($d['gudang'] ?? $gudang);
            $posTarif= trim($d['pos_tarif'] ?? '');
            $cif     = (float)($d['cif'] ?? 0);
            $cifRp   = $cif * $rate;
            $hpp     = (float)($d['hpp'] ?? 0);
            $dept    = trim($d['dept'] ?? '');
            $dNote   = trim($d['note'] ?? '');

            if (empty($kd) && empty($ur)) continue;

            $dStmt->bind_param("ssssisssssdsddddssddddss",
                $dtlId, $sessionCmpId, $id, $piNo, $seq, $idPo, $pNo, $idRi, $rNo,
                $kd, $ur, $qty, $sat, $price, $disc, $amt, $prcJasa, $amtJasa,
                $dGudang, $posTarif, $cif, $cifRp, $hpp, $dept, $dNote
            );
            if (!$dStmt->execute()) throw new Exception('Gagal menyimpan Detail PI baris #' . $seq . ': ' . $dStmt->error);
            $seq++;
        }

        // Sinkronisasi status RI dan total difakturkan
        syncRiStatusAndInvoiced($koneksi, $sessionCmpId, array_unique($riListToSync));

        // Auto Sync Lot Warehouse untuk Cetak Label QR & Traceability (alur direct PI tanpa RI)
        require_once __DIR__ . '/lot_helper.php';
        syncPiToLots($koneksi, $id);

        $koneksi->commit();

        echo json_encode([
            'status'  => 'OK',
            'message' => 'Faktur Pembelian berhasil disimpan.',
            'id'      => $id,
            'pi_no'   => $piNo
        ]);
    } catch (\Throwable $ex) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 8. HAPUS FAKTUR PEMBELIAN
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Faktur Pembelian tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        // Cek kunci IT Inventory
        $chkLock = $koneksi->query("SELECT 1 FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' LIMIT 1");
        if ($chkLock && $chkLock->num_rows > 0) {
            throw new Exception('Faktur Pembelian ini berstatus TERKUNCI di IT Inventory (bc_1ab2). Buka kunci terlebih dahulu.');
        }

        // Ambil data PI dan RI terkait untuk sinkronisasi kembali
        $piRow = $koneksi->query("SELECT pi_no FROM bc_pi_hdr WHERE id = '{$escId}' LIMIT 1")->fetch_assoc();
        $piNoDel = $piRow['pi_no'] ?? '';

        $riToSync = [];
        $riRes = $koneksi->query("SELECT DISTINCT ri_no FROM bc_pi_dtl WHERE id_hdr = '{$escId}' AND ri_no != ''");
        if ($riRes) {
            while ($r = $riRes->fetch_assoc()) $riToSync[] = $r['ri_no'];
        }

        $koneksi->begin_transaction();
        $koneksi->query("DELETE FROM bc_pi_dtl WHERE id_hdr = '{$escId}'");
        $koneksi->query("DELETE FROM bc_pi_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        // Kembalikan status RI
        syncRiStatusAndInvoiced($koneksi, $sessionCmpId, $riToSync);

        // Bersihkan lot warehouse yang belum terpakai jika alur direct PI
        if (!empty($piNoDel)) {
            require_once __DIR__ . '/lot_helper.php';
            deleteWhLotsByRef($koneksi, $sessionCmpId, 'PI', $piNoDel);
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => 'Faktur Pembelian berhasil dihapus.']);
    } catch (\Throwable $ex) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 9. POSTING TRANSAKSI KE IT INVENTORY (bc_1ab2, AB = 'A' Pemasukan)
if ($action === 'transfer_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Faktur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hRes = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as vendor_nama, b.NPWP as vendor_npwp 
                                 FROM bc_pi_hdr h 
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL)) 
                                 WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL) LIMIT 1");
        if (!$hRes || $hRes->num_rows === 0) throw new Exception('Data Faktur Pembelian tidak ditemukan.');
        $h = $hRes->fetch_assoc();

        $dRes = $koneksi->query("SELECT d.*, m.POS_TARIF, COALESCE(m.KLAS, 'Raw Material') as klas 
                                 FROM bc_pi_dtl d 
                                 LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL)) 
                                 WHERE d.id_hdr = '{$escId}' 
                                 ORDER BY d.seq ASC");
        if (!$dRes || $dRes->num_rows === 0) throw new Exception('Rincian barang kosong. Simpan transaksi sebelum posting ke IT Inventory.');

        $koneksi->begin_transaction();
        // Bersihkan data lama jika ada
        $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");

        $userNow = $currentUser;
        $tglNow  = date('Y-m-d');

        while ($d = $dRes->fetch_assoc()) {
            $bcDoc     = !empty($h['bc']) ? $h['bc'] : 'LOKAL';
            $noDaftar  = !empty($h['no_bc']) ? $h['no_bc'] : '-';
            $tglDaftar = !empty($h['tgl_bc']) ? $h['tgl_bc'] : $h['pi_dt'];
            $noBukti   = $h['pi_no'];
            $tglBukti  = $h['pi_dt'];
            $namaBs    = $h['vendor_nama'] ?: $h['vendor'];
            $kodeBrg   = $d['kode_barang'];
            $posTarif  = !empty($d['pos_tarif']) ? $d['pos_tarif'] : (!empty($d['POS_TARIF']) ? $d['POS_TARIF'] : '-');
            $uraian    = $d['uraian'];
            $klas      = !empty($d['klas']) ? $d['klas'] : 'Raw Material';
            $unit      = !empty($d['sat']) ? $d['sat'] : 'PCE';
            $qty       = (float)$d['qty'];
            $kurs      = !empty($h['kurs']) ? $h['kurs'] : 'IDR';
            $nilai     = (float)$d['amount'];
            $ab        = 'A'; // 'A' = Pemasukan Bahan Baku / Masuk Pembelian
            $sj        = !empty($d['ri_no']) ? $d['ri_no'] : $h['ri_no'];
            $inv       = $h['pi_no'];
            $faktur    = $h['no_fp'];
            $noAju     = $h['no_aju'];
            $npwpBs    = !empty($h['vendor_npwp']) ? $h['vendor_npwp'] : '-';
            $seri      = (int)$d['seq'];
            $hrg       = (float)$d['price'];
            $cif       = (float)($d['cif'] ?: 0);
            $gudang    = !empty($d['gudang']) ? $d['gudang'] : (!empty($h['gudang']) ? $h['gudang'] : 'G-BB');
            $poNo      = !empty($d['po_no']) ? $d['po_no'] : $h['po_no'];
            $idBs      = $h['vendor'];
            $tglProses = !empty($h['ship_dt']) ? $h['ship_dt'] : $h['pi_dt'];

            $sqlIns = "INSERT INTO bc_1ab2 (
                cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS,
                KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB,
                ID_HEADER, SJ, INVOICE, FAKTUR, NOMOR_AJU, NPWP_BS, SERI_BARANG,
                HARGA_PENYERAHAN, CIF, NETTO, GROSS, SESUAI, QTY_AKTUAL, GUDANG,
                NOMOR_PO, ID_BS, TGL_PROSES, CRT_DT, CRT_USER
            ) VALUES (
                '{$escCmp}', '{$bcDoc}', '{$noDaftar}', '{$tglDaftar}', '{$noBukti}', '{$tglBukti}', '{$namaBs}',
                '{$kodeBrg}', '{$posTarif}', '{$uraian}', '{$klas}', '{$unit}', {$qty}, '{$kurs}', {$nilai}, '{$ab}',
                '{$escId}', '{$sj}', '{$inv}', '{$faktur}', '{$noAju}', '{$npwpBs}', {$seri},
                {$hrg}, {$cif}, 0, 0, 'Y', {$qty}, '{$gudang}',
                '{$poNo}', '{$idBs}', '{$tglProses}', '{$tglNow}', '{$userNow}'
            )";

            if (!$koneksi->query($sqlIns)) {
                throw new Exception("Gagal insert IT Inventory: " . $koneksi->error);
            }
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Faktur Pembelian {$h['pi_no']} berhasil dibukukan ke IT Inventory (bc_1ab2). Dokumen kini berstatus TERKUNCI."]);
    } catch (\Throwable $ex) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 10. HAPUS DARI IT INVENTORY (BUKA KUNCI)
if ($action === 'delete_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Faktur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $del = $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
        if ($del) {
            echo json_encode(['status' => 'OK', 'message' => "Data transaksi berhasil dihapus dari IT Inventory. Faktur Pembelian kini TERBUKA kembali untuk diedit."]);
        } else {
            throw new Exception("Gagal menghapus dari IT Inventory: " . $koneksi->error);
        }
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 11. TRACE DOKUMEN PENERIMAAN (RI) & BC DOKUMEN
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Faktur tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hRes = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as vendor_nama FROM bc_pi_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')) WHERE h.id = '{$escId}' LIMIT 1");
        $header = $hRes ? $hRes->fetch_assoc() : null;

        $dtlRes = $koneksi->query("SELECT id, seq, kode_barang, uraian, qty, sat, price, amount, ri_no, po_no, pos_tarif FROM bc_pi_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
        $lines = [];
        if ($dtlRes) while ($r = $dtlRes->fetch_assoc()) $lines[] = $r;

        // Ambil data penerimaan barang (RI) terkait
        $riList = [];
        $poList = [];
        foreach ($lines as $ln) {
            if (!empty($ln['ri_no'])) $riList[] = "'" . $koneksi->real_escape_string($ln['ri_no']) . "'";
            if (!empty($ln['po_no'])) $poList[] = "'" . $koneksi->real_escape_string($ln['po_no']) . "'";
        }

        $riHeaders = [];
        if (!empty($riList)) {
            $inRi = implode(',', array_unique($riList));
            $rQ = $koneksi->query("SELECT ri_no, ri_dt, no_sj, no_bc, tgl_bc, no_aju, bc, po_no FROM bc_ri_hdr WHERE ri_no IN ({$inRi}) AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            if ($rQ) while ($rq = $rQ->fetch_assoc()) $riHeaders[] = $rq;
        }

        $poHeaders = [];
        if (!empty($poList)) {
            $inPo = implode(',', array_unique($poList));
            $pQ = $koneksi->query("SELECT po_no, po_dt, vendor, term, kurs, rate, total_order FROM bc_po_hdr WHERE po_no IN ({$inPo}) AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            if ($pQ) while ($pq = $pQ->fetch_assoc()) $poHeaders[] = $pq;
        }

        echo json_encode([
            'status' => 'OK',
            'header' => $header,
            'lines'  => $lines,
            'ri_headers' => $riHeaders,
            'po_headers' => $poHeaders
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// 12. CETAK VOUCHER FAKTUR PEMBELIAN RESMI A4 (PRINT VIEW)
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) die("ID Faktur tidak valid.");
    $escId = $koneksi->real_escape_string($id);

    $hdrQ = $koneksi->query("SELECT a.*, 
                                    COALESCE(b.NAMA, a.vendor) AS vendor_name, 
                                    b.ALAMAT AS vendor_alamat, 
                                    b.NPWP AS vendor_npwp,
                                    b.TELP AS vendor_telp
                             FROM bc_pi_hdr a 
                             LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                             WHERE a.id = '{$escId}' AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL) LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) die("Faktur Pembelian tidak ditemukan.");
    $hdr = $hdrQ->fetch_assoc();

    $dtlQ = $koneksi->query("SELECT * FROM bc_pi_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $items = [];
    if ($dtlQ) while ($d = $dtlQ->fetch_assoc()) $items[] = $d;

    $grandTotal = (float)$hdr['total_order'];
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Voucher Faktur Pembelian - <?= htmlspecialchars($hdr['pi_no']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 10mm 12mm; }
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
            body { background: #fff; color: #111; font-size: 9.5px; line-height: 1.4; padding: 12px; }
            .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; border-bottom: 2px solid #8b5cf6; padding-bottom: 6px; }
            .hdr-table td { vertical-align: middle; }
            .print-logo { height: 48px; max-width: 120px; object-fit: contain; }
            .cmp-name { font-size: 13px; font-weight: 700; color: #4c1d95; text-transform: uppercase; }
            .cmp-sub { font-size: 8.5px; color: #555; }
            .doc-title { font-size: 14px; font-weight: 800; color: #8b5cf6; text-align: right; text-transform: uppercase; }
            .doc-sub { font-size: 9px; color: #444; text-align: right; font-weight: 600; }

            .meta-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 4px; padding: 6px 10px; margin-bottom: 10px; font-size: 8.5px; }
            .meta-item { display: flex; flex-direction: column; }
            .meta-lbl { font-size: 7.5px; color: #6b7280; text-transform: uppercase; font-weight: 600; }
            .meta-val { font-size: 9.5px; font-weight: 700; color: #5b21b6; }

            table.tbl-items { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 9px; }
            table.tbl-items th { background: #f3f4f6; border: 1px solid #ccc; padding: 4px 6px; text-align: left; font-weight: 700; text-transform: uppercase; }
            table.tbl-items td { border: 1px solid #ddd; padding: 3px 6px; vertical-align: middle; }
            table.tbl-items tr:nth-child(even) td { background: #fafafa; }
            .tar { text-align: right; }
            .tac { text-align: center; }

            .calc-footer { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
            .note-box { width: 55%; font-size: 8.5px; border: 1px dashed #ccc; padding: 6px 10px; border-radius: 4px; background: #fafafa; }
            .calc-box { width: 42%; }
            .calc-table { width: 100%; border-collapse: collapse; font-size: 9px; }
            .calc-table td { padding: 2px 4px; }
            .calc-table td.val { text-align: right; font-weight: 700; }
            .calc-table tr.grand td { font-size: 11px; font-weight: 800; border-top: 1.5px solid #8b5cf6; color: #5b21b6; }

            .sign-table { width: 100%; border-collapse: collapse; margin-top: 20px; text-align: center; font-size: 8.5px; page-break-inside: avoid; }
            .sign-table td { width: 25%; padding: 4px; vertical-align: top; }
            .sign-box { height: 50px; }

            @media print {
                body { padding: 0; }
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 12px; display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" onclick="window.print()" style="padding: 5px 14px; background: #8b5cf6; color: #fff; border: none; border-radius: 3px; cursor: pointer; font-weight: 600;">🖨️ Cetak Voucher</button>
            <button type="button" onclick="window.close()" style="padding: 5px 12px; background: #f3f4f6; border: 1px solid #ccc; border-radius: 3px; cursor: pointer;">Tutup</button>
        </div>

        <table class="hdr-table">
            <tr>
                <td style="width: 100px;">
                    <img src="<?= htmlspecialchars($companyLogo) ?>" class="print-logo" alt="Logo">
                </td>
                <td>
                    <div class="cmp-name"><?= htmlspecialchars($activeCmpName) ?></div>
                    <div class="cmp-sub"><?= htmlspecialchars($companyAlamat) ?> &bull; NPWP: <?= htmlspecialchars($companyNpwp) ?></div>
                </td>
                <td>
                    <div class="doc-title">FAKTUR PEMBELIAN (PI)</div>
                    <div class="doc-sub">No: <?= htmlspecialchars($hdr['pi_no']) ?></div>
                </td>
            </tr>
        </table>

        <div class="meta-grid">
            <div class="meta-item"><span class="meta-lbl">Pemasok / Vendor</span><span class="meta-val"><?= htmlspecialchars($hdr['vendor_name']) ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Tgl. Faktur</span><span class="meta-val"><?= !empty($hdr['pi_dt']) ? date('d/m/Y', strtotime($hdr['pi_dt'])) : '-' ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Jatuh Tempo</span><span class="meta-val"><?= !empty($hdr['jatuhtempo']) ? date('d/m/Y', strtotime($hdr['jatuhtempo'])) : '-' ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Termin / Kurs</span><span class="meta-val"><?= htmlspecialchars($hdr['term']) ?> &bull; <?= htmlspecialchars($hdr['kurs']) ?> (<?= number_format((float)$hdr['rate'], 2) ?>)</span></div>
            <div class="meta-item"><span class="meta-lbl">No. Surat Jalan (RI)</span><span class="meta-val"><?= htmlspecialchars($hdr['ri_no'] ?: '-') ?></span></div>
            <div class="meta-item"><span class="meta-lbl">No. PO Acuan</span><span class="meta-val"><?= htmlspecialchars($hdr['po_no'] ?: '-') ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Dokumen Pabean Masuk</span><span class="meta-val"><?= htmlspecialchars($hdr['bc'] ?: 'NON-BC') ?> <?= !empty($hdr['no_bc']) ? ('(No: ' . htmlspecialchars($hdr['no_bc']) . ')') : '' ?></span></div>
            <div class="meta-item"><span class="meta-lbl">No. Faktur Pajak</span><span class="meta-val"><?= !empty($hdr['no_fp']) ? (htmlspecialchars($hdr['kd_fp']) . '.' . htmlspecialchars($hdr['no_fp'])) : '-' ?></span></div>
        </div>

        <table class="tbl-items">
            <thead>
                <tr>
                    <th class="tac" style="width: 25px;">#</th>
                    <th style="width: 110px;">Kode Barang</th>
                    <th>Nama &amp; Uraian Barang</th>
                    <th class="tar" style="width: 65px;">Qty</th>
                    <th class="tac" style="width: 40px;">Sat</th>
                    <th class="tar" style="width: 85px;">Harga Satuan</th>
                    <th class="tar" style="width: 55px;">Disc(%)</th>
                    <th class="tar" style="width: 95px;">Total Nilai</th>
                    <th style="width: 90px;">Ref RI / PO</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="9" class="tac" style="padding: 12px; color: #888;">Tidak ada rincian barang.</td></tr>
                <?php else: foreach ($items as $i => $it): ?>
                <tr>
                    <td class="tac"><?= $i + 1 ?></td>
                    <td><b><?= htmlspecialchars($it['kode_barang']) ?></b></td>
                    <td><?= htmlspecialchars($it['uraian']) ?></td>
                    <td class="tar"><b><?= number_format((float)$it['qty'], 2, '.', ',') ?></b></td>
                    <td class="tac"><?= htmlspecialchars($it['sat']) ?></td>
                    <td class="tar"><?= number_format((float)$it['price'], 2, '.', ',') ?></td>
                    <td class="tar"><?= (float)$it['disc'] > 0 ? number_format((float)$it['disc'], 2) : '-' ?></td>
                    <td class="tar"><b><?= number_format((float)$it['amount'], 2, '.', ',') ?></b></td>
                    <td><small><?= htmlspecialchars($it['ri_no'] ?: ($it['po_no'] ?: '-')) ?></small></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div class="calc-footer">
            <div class="note-box">
                <b>Catatan Tagihan:</b><br>
                <?= nl2br(htmlspecialchars($hdr['note'] ?: 'Faktur Pembelian resmi atas barang/jasa yang telah diterima secara lengkap dan sesuai spesifikasi pesanan.')) ?>
            </div>
            <div class="calc-box">
                <table class="calc-table">
                    <tr><td>Subtotal Material</td><td class="val"><?= number_format((float)$hdr['subtotal'], 2, '.', ',') ?></td></tr>
                    <?php if ((float)$hdr['disc_amt'] > 0): ?>
                    <tr><td>Diskon Faktur (<?= number_format((float)$hdr['disc_persen'], 2) ?>%)</td><td class="val">- <?= number_format((float)$hdr['disc_amt'], 2, '.', ',') ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$hdr['ppn'] > 0): ?>
                    <tr><td>PPN</td><td class="val"><?= number_format((float)$hdr['ppn'], 2, '.', ',') ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$hdr['pph'] > 0): ?>
                    <tr><td>PPh Pasal 23 (2%)</td><td class="val">- <?= number_format((float)$hdr['pph'], 2, '.', ',') ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$hdr['jasa'] > 0): ?>
                    <tr><td>Biaya Jasa</td><td class="val"><?= number_format((float)$hdr['jasa'], 2, '.', ',') ?></td></tr>
                    <?php endif; ?>
                    <?php if ((float)$hdr['est_freight'] > 0): ?>
                    <tr><td>Ongkos Kirim (Freight)</td><td class="val"><?= number_format((float)$hdr['est_freight'], 2, '.', ',') ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand"><td>GRAND TOTAL</td><td class="val"><?= htmlspecialchars($hdr['kurs']) ?> <?= number_format($grandTotal, 2, '.', ',') ?></td></tr>
                    <tr><td>Terbayar (AP)</td><td class="val"><?= number_format((float)$hdr['terbayar'], 2, '.', ',') ?></td></tr>
                    <tr style="font-weight: 700; color: #dc2626;"><td>Sisa Hutang Dagang</td><td class="val"><?= htmlspecialchars($hdr['kurs']) ?> <?= number_format((float)$hdr['outstand'], 2, '.', ',') ?></td></tr>
                </table>
            </div>
        </div>

        <table class="sign-table">
            <tr>
                <td>Dibuat Oleh (Purchasing)</td>
                <td>Diverifikasi (Gudang)</td>
                <td>Diperiksa (Accounting AP)</td>
                <td>Disetujui (Finance Director)</td>
            </tr>
            <tr>
                <td><div class="sign-box"></div>( <?= htmlspecialchars($hdr['set_by'] ?: 'Staff Purchasing') ?> )</td>
                <td><div class="sign-box"></div>( .................................... )</td>
                <td><div class="sign-box"></div>( .................................... )</td>
                <td><div class="sign-box"></div>( .................................... )</td>
            </tr>
        </table>
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
    </body>
    </html>
    <?php
    exit;
}

// Helper: Sinkronisasi Status Penerimaan Barang (RI)
function syncRiStatusAndInvoiced($koneksi, $cmpId, $riNumbers) {
    if (empty($riNumbers)) return;
    $escCmp = $koneksi->real_escape_string($cmpId);

    foreach ($riNumbers as $riNo) {
        $riNo = trim($riNo);
        if (empty($riNo)) continue;
        $escRi = $koneksi->real_escape_string($riNo);

        // Update jumlah terima pada bc_ri_dtl dari akumulasi bc_pi_dtl
        $koneksi->query("UPDATE bc_ri_dtl rd
            JOIN (
                SELECT pd.id_ri, pd.kode_barang, SUM(pd.qty) as total_pi_qty
                FROM bc_pi_dtl pd
                WHERE pd.ri_no = '{$escRi}' AND (pd.cmp_id = '{$escCmp}' OR pd.cmp_id = '' OR pd.cmp_id IS NULL)
                GROUP BY pd.id_ri, pd.kode_barang
            ) p ON (rd.id = p.id_ri OR (rd.kode_barang = p.kode_barang AND rd.id_hdr IN (SELECT id FROM bc_ri_hdr WHERE ri_no = '{$escRi}')))
            SET rd.terima = p.total_pi_qty
            WHERE (rd.cmp_id = '{$escCmp}' OR rd.cmp_id = '' OR rd.cmp_id IS NULL)");

        // Cek apakah seluruh baris pada bc_ri_dtl sudah lunas difakturkan
        $chkQ = $koneksi->query("SELECT COUNT(1) as total_item,
                                        SUM(CASE WHEN COALESCE(terima, 0) >= qty THEN 1 ELSE 0 END) as invoiced_item
                                 FROM bc_ri_dtl rd
                                 JOIN bc_ri_hdr rh ON rd.id_hdr = rh.id
                                 WHERE rh.ri_no = '{$escRi}' AND (rh.cmp_id = '{$escCmp}' OR rh.cmp_id = '' OR rh.cmp_id IS NULL)");
        if ($chkQ && $chk = $chkQ->fetch_assoc()) {
            $totalItem = (int)($chk['total_item'] ?? 0);
            $invItem   = (int)($chk['invoiced_item'] ?? 0);
            $newStatus = ($totalItem > 0 && $invItem >= $totalItem) ? '2' : '1';
            $koneksi->query("UPDATE bc_ri_hdr SET status = '{$newStatus}' WHERE ri_no = '{$escRi}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        }
    }
}

// -------------------------------------------------------------------------
// DATA MASTER DROPDOWN REFERENSI
// -------------------------------------------------------------------------
$suppliers = [];
$bsQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, PAY_TERM AS TOP, kurs AS CURRENCY 
    FROM bc_bs 
    WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
      AND (TIPE = 'S' OR TIPE = 'B' OR TIPE = '' OR TIPE IS NULL) 
    ORDER BY NAMA ASC");
if ($bsQ) {
    while ($r = $bsQ->fetch_assoc()) $suppliers[] = $r;
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
    <title>Purchase Invoice (PI) - <?= htmlspecialchars($activeCmpName) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_searchable_select.css?v=20261101">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>
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
            --primary: #8b5cf6;          /* Ungu Khas PI / Purchasing */
            --primary-hover: #7c3aed;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-hover: #f5f3ff;
            --table-head-bg: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --row-selected: #ede9fe;
            --row-selected-text: #4c1d95;
            color-scheme: light;
        }
        body.dark-mode, [data-theme='dark'], html[data-theme='dark'], body[data-theme='dark'], html.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --border-light: #424242;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #8b5cf6;
            --primary-hover: #a78bfa;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #ff4d4f;
            --table-hover: #2e1065;
            --table-head-bg: #1d1d1d;
            --bg-input: #141414;
            --border-input: #434343;
            --row-selected: #3b0764;
            --row-selected-text: #f5f3ff;
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
        .ant-badge-company { background: rgba(139, 92, 246, 0.12); color: var(--primary); border: 1px solid rgba(139, 92, 246, 0.3); }
        .ant-badge-lunas { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.3); }
        .ant-badge-belum { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.3); }
        .ant-badge-lock { background: rgba(255, 77, 79, 0.12); color: var(--danger); border: 1px solid rgba(255, 77, 79, 0.3); }

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

        /* Form Controls */
        .hdr-grid { display: grid; grid-template-columns: 1.15fr 1fr; gap: 2px 12px; padding: 6px 10px; }
        .fld-row { display: grid; grid-template-columns: 82px 1fr; gap: 4px; align-items: center; margin-bottom: 2px; }
        .fld-row label { text-align: right; color: var(--text-muted); font-size: 9.5px; font-weight: 500; }
        .fld-row input, .fld-row select, .fld-row textarea, .browse-filter input, .browse-filter select { width: 100%; border: 1px solid var(--border-input); background: var(--bg-input); padding: 1px 6px; height: 21px; border-radius: 2px; outline: none; font-size: 9.5px; }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus, .browse-filter input:focus, .browse-filter select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.15); }
        .inline-box { display: flex; gap: 4px; align-items: center; flex: 1; min-width: 0; width: 100%; }
        .pair-box { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 8px; }
        .checks-box { display: flex; flex-wrap: wrap; gap: 2px 10px; margin: 0 0 2px 86px; font-size: 9px; }
        .checks-box label { display: flex; gap: 4px; align-items: center; cursor: pointer; }

        /* IT Inv Lock Banner */
        .lock-alert { display: none; margin: 4px 10px; padding: 4px 10px; background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; border-radius: 3px; font-weight: 600; font-size: 10px; align-items: center; justify-content: space-between; }
        body.dark-mode .lock-alert { background: #2a1215; color: #ff7875; border-color: #5c2223; }
        #pageEntri.is-locked .split-pane input:disabled,
        #pageEntri.is-locked .split-pane select:disabled,
        #pageEntri.is-locked .split-pane textarea:disabled { background: #f5f5f5; color: rgba(0, 0, 0, 0.45); cursor: not-allowed; }
        body.dark-mode #pageEntri.is-locked .split-pane input:disabled,
        body.dark-mode #pageEntri.is-locked .split-pane select:disabled,
        body.dark-mode #pageEntri.is-locked .split-pane textarea:disabled { background: #141414; color: rgba(255, 255, 255, 0.35); }

        /* Excel-like Data Table & Resizable Columns */
        .grid-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        table.dw { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 9.5px; table-layout: auto; border: 1px solid var(--border-color); }
        table.dw thead { position: sticky; top: 0; z-index: 10; }
        table.dw th { position: relative; background: var(--table-head-bg); border: 1px solid var(--border-color); padding: 4px 10px 4px 6px; text-align: left; font-weight: 600; font-size: 9.5px; color: var(--text-muted); white-space: nowrap; user-select: none; }
        table.dw td { border: 1px solid var(--border-light); padding: 2px 6px; font-size: 9.5px; vertical-align: middle; }
        table.dw input { width: 100%; border: 1px solid transparent; background: transparent; padding: 1px 4px; height: 21px; border-radius: 2px; font-size: 9.5px; }
        table.dw input:hover { border-color: var(--border-input); }
        table.dw input:focus { border-color: var(--primary); background: var(--bg-input); box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.12); outline: none; }
        table.dw input[type="number"] { text-align: right; }
        table.dw tbody tr:hover td { background: var(--table-hover); }
        table.dw tr.sel td { background: var(--row-selected) !important; color: var(--row-selected-text) !important; }

        /* PI No (No. Inv) Highlight (Emas / Amber PB Style Identik SI) */
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
            box-shadow: 0 0 6px rgba(139, 92, 246, 0.6) !important;
        }

        /* Column Chooser Popover */
        .col-chooser-menu { position: absolute; bottom: 28px; left: 0; width: 290px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 4px 16px rgba(0,0,0,0.18); z-index: 100; padding: 8px; }
        .col-chooser-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 6px; margin-bottom: 6px; border-bottom: 1px solid var(--border-color); font-size: 10.5px; }
        .col-chooser-body { max-height: 260px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 8px; font-size: 10px; }
        .col-chooser-body label { display: flex; align-items: center; gap: 4px; cursor: pointer; user-select: none; }

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

        /* Browse Data Filter (Single Compact Line) */
        .browse-filter { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; padding: 4px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); white-space: nowrap; overflow-x: auto; }
        .browse-filter label { color: var(--text-muted); font-size: 10px; flex-shrink: 0; }
        .pager { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 10px; background: var(--bg-card); }
        .detail-strip { display: none; overflow: auto; border-top: 1px solid var(--border-color); background: var(--bg-card); }
        .detail-strip.show { display: block; flex: 0 0 180px; }

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
        .muted { color: var(--text-muted); }

        /* Context Menu (Klik Kanan PB Style) */
        .context-menu {
            position: fixed;
            z-index: 99999;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
            min-width: 235px;
            padding: 4px 0;
            user-select: none;
            font-size: 11px;
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
        .ctx-item kbd { font-size: 9px; padding: 1px 4px; background: var(--table-head-bg); border: 1px solid var(--border-color); border-radius: 3px; color: var(--text-muted); }
        .ctx-sep { height: 1px; background: var(--border-color); margin: 4px 0; }

        /* Dropdown Menu */
        .ant-dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 4px;
            z-index: 1000;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 220px;
            padding: 4px 0;
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 11px;
            color: var(--text-main);
        }
        .dropdown-item:hover {
            background: var(--table-hover);
            color: var(--primary);
        }
    </style>
</head>
<body class="<?= (!empty($_GET['theme']) && $_GET['theme'] === 'dark') ? 'dark-mode' : '' ?>">
<div class="ant-container">
    <div class="ant-card">
        <!-- Header Bar -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-file-invoice-dollar" style="color:var(--primary);"></i>
                <span>Purchase Invoice (Faktur Pembelian AP)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-belum" id="statusBadgeHeader">Belum Lunas</span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="resetForm()"><i class="fa-solid fa-plus"></i> Baru</button>
                <button type="button" class="ant-btn ant-btn-primary" data-lock="block" onclick="savePi()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                <button type="button" class="ant-btn ant-btn-danger" data-lock="block" onclick="deleteCurrent()"><i class="fa-solid fa-trash"></i> Hapus</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="printPi()"><i class="fa-solid fa-print"></i> Cetak Faktur</button>
                <button type="button" class="ant-btn" onclick="openQrLabelPi()" style="color:#059669; font-weight:600;" title="Cetak Stiker Label QR Dokumen BC (Traceability AJU)"><i class="fa-solid fa-qrcode"></i> Label QR Dokumen BC</button>
                <div style="position:relative; display:inline-block;">
                    <button type="button" class="ant-btn" id="btnExcelMenu" onclick="toggleExcelMenu(event)" title="Menu Import & Export Excel"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Excel <i class="fa-solid fa-caret-down"></i></button>
                    <div id="excelMenuDropdown" class="ant-dropdown-menu" style="display:none;" onclick="event.stopPropagation()">
                        <div class="dropdown-item" onclick="openModalImportExcel(); hideExcelMenu();"><i class="fa-solid fa-file-import" style="color:#8b5cf6;"></i> Import dari Excel...</div>
                        <div class="dropdown-item" onclick="exportGridToExcel(); hideExcelMenu();"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Rincian ke Excel (.xlsx)</div>
                        <div class="dropdown-item" onclick="downloadPiImportTemplate(); hideExcelMenu();"><i class="fa-solid fa-download" style="color:#faad14;"></i> Download Template Excel Import</div>
                    </div>
                </div>
                <button type="button" class="ant-btn" data-lock="block" onclick="openLookupRi()"><i class="fa-solid fa-dolly"></i> Tarik RI</button>
                <button type="button" class="ant-btn" data-lock="block" onclick="openLookupPo()"><i class="fa-solid fa-cart-shopping"></i> Tarik PO</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnTransferItInv" onclick="postToItInventory()"><i class="fa-solid fa-cube"></i> Posting IT Inv</button>
                <button type="button" class="ant-btn ant-btn-danger" id="btnDelItInv" style="display:none;" onclick="deleteFromItInventory()"><i class="fa-solid fa-unlock"></i> Buka Kunci IT Inv</button>
                <button type="button" class="ant-btn" onclick="openModalApHistory()"><i class="fa-solid fa-receipt" style="color:#f59e0b;"></i> Riwayat AP</button>
                <button type="button" class="ant-btn" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i></button>
            </div>
        </div>

        <!-- Navigation Tabs (3 Tab Utama Identik SI) -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="entri" onclick="showTab('entri')"><i class="fa-solid fa-pen-to-square"></i> Entri (Faktur Pembelian)</button>
            <button type="button" class="ant-tab-btn" data-tab="browse" onclick="showTab('browse')"><i class="fa-solid fa-table-list"></i> Browse Data</button>
            <button type="button" class="ant-tab-btn" data-tab="trace" onclick="showTab('trace')"><i class="fa-solid fa-route"></i> Trace Penerimaan &amp; Dokumen BC</button>
        </div>

        <!-- TAB 1: ENTRI FAKTUR PEMBELIAN (IDENTIK DENGAN SI) -->
        <section class="ant-page active" id="pageEntri">
            <div class="split-col" id="splitEntri">
                <!-- Pane Header (2 Kolom Rapat Desktop) -->
                <div class="split-pane" id="paneHdr">
                    <div class="lock-alert" id="piLock">
                        <span><i class="fa-solid fa-lock"></i> <strong>DOKUMEN TERKUNCI:</strong> Sudah masuk pembukuan IT Inventory (bc_1ab2). Mode baca saja (Read-Only).</span>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteFromItInventory()"><i class="fa-solid fa-unlock"></i> Hapus dari IT Inv &amp; Buka Kunci</button>
                    </div>
                    <div class="hdr-grid">
                        <!-- Sisi Kiri: Vendor & Pengiriman -->
                        <div>
                            <div class="fld-row">
                                <label>Pemasok/Vend:</label>
                                <div class="inline-box">
                                    <select id="piVendor" onchange="onVendorChange()" style="flex:1;">
                                        <option value="">-- Pilih Supplier / Vendor --</option>
                                        <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= htmlspecialchars($s['KODE']) ?>" data-nama="<?= htmlspecialchars($s['NAMA']) ?>" data-alamat="<?= htmlspecialchars($s['ALAMAT'] ?? '') ?>" data-npwp="<?= htmlspecialchars($s['NPWP'] ?? '') ?>" data-top="<?= htmlspecialchars($s['TOP'] ?? '') ?>" data-kurs="<?= htmlspecialchars($s['CURRENCY'] ?? '') ?>"><?= htmlspecialchars($s['KODE']) ?> - <?= htmlspecialchars($s['NAMA']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="ant-btn" onclick="openLookupRi()" title="Tarik dari Penerimaan Barang (RI/GRN)"><i class="fa-solid fa-dolly"></i> RI</button>
                                    <button type="button" class="ant-btn" onclick="openLookupPo()" title="Tarik dari Purchase Order (PO)"><i class="fa-solid fa-cart-shopping"></i> PO</button>
                                </div>
                            </div>
                            <div class="checks-box">
                                <label><input type="checkbox" id="piTaxable" checked onchange="calcTotals()"> Kena Pajak</label>
                                <label><input type="checkbox" id="piInclusive" onchange="calcTotals()"> Termasuk Pajak (Inclusive)</label>
                                <label><input type="checkbox" id="piSubcon" onchange="calcTotals()"> Jasa / Subkon</label>
                                <label><input type="checkbox" id="piNcv"> NCV</label>
                                <label><input type="checkbox" id="piBill2"> Bill to Supplier</label>
                            </div>
                            <div class="fld-row">
                                <label>Alamat:</label>
                                <input type="text" id="piAlamat" readonly placeholder="Alamat Pemasok">
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>No. RI:</label><input type="text" id="piRiNo" placeholder="No Surat Jalan Penerimaan (RI)"></div>
                                <div class="fld-row"><label>No. PO:</label><input type="text" id="piPoNo" placeholder="No Purchase Order Acuan"></div>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Kurs, No Faktur, Tanggal, Terms, Pabean -->
                        <div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Kurs:</label>
                                    <select id="piKurs" onchange="toggleRate()">
                                        <?php foreach ($currencies as $c): ?>
                                        <option value="<?= $c ?>"><?= $c ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fld-row"><label>Kurs Pajak:</label><input type="number" id="piRate" value="1" step="0.0001" oninput="calcTotals()"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label style="font-weight:700; color:var(--primary);">No. Inv:</label>
                                    <div class="inline-box">
                                        <input type="text" id="piNo" class="highlight-inv-no" placeholder="Otomatis jika kosong">
                                        <button type="button" class="ant-btn" onclick="generateAutoPiNo()" title="Generate Nomor Urut"><i class="fa-solid fa-rotate"></i></button>
                                    </div>
                                </div>
                                <div class="fld-row"><label>Tgl Terima:</label><input type="date" id="piShipDt" value="<?= $today ?>"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl Inv:</label><input type="date" id="piDt" value="<?= $today ?>" onchange="onDateOrTermChange()"></div>
                                <div class="fld-row"><label>Terms:</label>
                                    <input type="text" id="piTerm" value="Net 30" list="termList" onchange="onDateOrTermChange()">
                                    <datalist id="termList">
                                        <option value="Cash"></option>
                                        <option value="COD"></option>
                                        <option value="Net 7"></option>
                                        <option value="Net 14"></option>
                                        <option value="Net 30"></option>
                                        <option value="Net 60"></option>
                                        <option value="Net 90"></option>
                                    </datalist>
                                </div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Jatuh Tempo:</label><input type="date" id="piJatuhTempo"></div>
                                <div class="fld-row"><label>Faktur Pajak:</label>
                                    <div class="inline-box">
                                        <input type="text" id="piKdFp" value="01" style="width:38px; text-align:center;" placeholder="01">
                                        <input type="text" id="piNoFp" placeholder="000.00.00000000">
                                    </div>
                                </div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Dok. Pabean:</label>
                                    <select id="piBc">
                                        <option value="">-- Dokumen Pabean --</option>
                                        <option value="BC 2.3">BC 2.3 (Impor KB)</option>
                                        <option value="BC 4.0">BC 4.0 (TLDDP Lokal)</option>
                                        <option value="BC 2.7">BC 2.7 (Transfer TPB)</option>
                                        <option value="BC 2.6.2">BC 2.6.2 (Subkontrak)</option>
                                        <option value="NON-BC">NON-BC</option>
                                    </select>
                                </div>
                                <div class="fld-row"><label>No. Daftar BC:</label><input type="text" id="piNoBc" placeholder="6 digit no daftar"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl Daftar:</label><input type="date" id="piTglBc"></div>
                                <div class="fld-row"><label>Nomor AJU:</label><input type="text" id="piNoAju" placeholder="26 digit no aju"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resizable Splitbar Antara Header & Data Grid -->
                <div class="split-divider" id="splitHdr" data-pane="paneHdr" data-edge="prev" title="Geser untuk mengatur tinggi header (Dobel-klik untuk buka/tutup)">
                    <div class="split-handle"></div>
                    <button type="button" class="split-btn-collapse" onclick="togglePiPane('hdr')"><i class="fa-solid fa-arrows-up-down"></i></button>
                </div>

                <!-- Pane Data Grid Excel-like -->
                <div class="split-pane fill" id="paneGrid">
                    <input type="hidden" id="piId">
                    <div class="grid-wrap">
                        <table class="dw" id="tableItems" oncontextmenu="openGridContextMenu(event)">
                            <thead>
                                <tr>
                                    <th style="width:30px; text-align:center;">#</th>
                                    <th style="width:130px;">Kode Barang <div class="col-resizer"></div></th>
                                    <th style="width:260px;">Nama &amp; Uraian Barang <div class="col-resizer"></div></th>
                                    <th style="width:75px; text-align:right;">Qty <div class="col-resizer"></div></th>
                                    <th style="width:45px; text-align:center;">Satuan <div class="col-resizer"></div></th>
                                    <th style="width:95px; text-align:right;">Harga Sat <div class="col-resizer"></div></th>
                                    <th style="width:65px; text-align:right;">Disc(%) <div class="col-resizer"></div></th>
                                    <th style="width:110px; text-align:right;">Total Nilai <div class="col-resizer"></div></th>
                                    <th style="width:85px; text-align:right;">Harga Jasa <div class="col-resizer"></div></th>
                                    <th style="width:95px; text-align:right;">Total Jasa <div class="col-resizer"></div></th>
                                    <th style="width:105px;">No. RI <div class="col-resizer"></div></th>
                                    <th style="width:105px;">No. PO <div class="col-resizer"></div></th>
                                    <th style="width:80px;">Gudang <div class="col-resizer"></div></th>
                                    <th style="width:90px;">Pos Tarif (HS) <div class="col-resizer"></div></th>
                                    <th style="width:120px;">Catatan <div class="col-resizer"></div></th>
                                    <th style="width:35px; text-align:center;"><i class="fa-solid fa-ellipsis-vertical"></i></th>
                                </tr>
                            </thead>
                            <tbody id="itemsContainer">
                                <!-- Populated dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Resizable Splitbar Antara Data Grid & Footer -->
                <div class="split-divider" id="splitFtr" data-pane="paneFtr" data-edge="next" title="Geser untuk mengatur tinggi footer">
                    <div class="split-handle"></div>
                    <button type="button" class="split-btn-collapse" onclick="togglePiPane('ftr')"><i class="fa-solid fa-arrows-up-down"></i></button>
                </div>

                <!-- Pane Footer Summary (Kalkulasi Kaki Identik SI) -->
                <div class="split-pane" id="paneFtr">
                    <div class="ftr-grid">
                        <!-- Sisi Kiri: Catatan, Terbilang Callout, & Action Buttons -->
                        <div>
                            <div class="fld-row" style="grid-template-columns:55px 1fr;">
                                <label>Catatan:</label>
                                <input type="text" id="piNote" placeholder="Catatan tagihan faktur pembelian / keterangan...">
                            </div>
                            <div class="terbilang-callout">
                                <span class="lbl-say">Terbilang (Say):</span>
                                <span class="txt-say" id="dispTerbilang"># Nol Rupiah #</span>
                            </div>
                            <div style="display:flex; gap:4px; align-items:center; margin-top:6px; flex-wrap:wrap;">
                                <button type="button" class="ant-btn ant-btn-primary" onclick="tambahBaris()"><i class="fa-solid fa-plus"></i> Tambah Baris</button>
                                <button type="button" class="ant-btn" onclick="openMaterialPicker()"><i class="fa-solid fa-boxes-stacked"></i> Pilih Master Barang</button>
                                <button type="button" class="ant-btn" onclick="openLookupRi()"><i class="fa-solid fa-dolly"></i> Tarik RI</button>
                                <button type="button" class="ant-btn" onclick="openLookupPo()"><i class="fa-solid fa-cart-shopping"></i> Tarik PO</button>
                                <button type="button" class="ant-btn ant-btn-danger" onclick="clearAllRows()"><i class="fa-solid fa-broom"></i> Bersihkan Semua</button>
                                <span class="muted" style="margin-left:auto; font-size:9.5px;" id="itemCountInfo">0 baris rincian</span>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Sum-Box 2 Kolom Finansial -->
                        <div class="sum-box">
                            <div class="fld-row"><label>Subtotal:</label><span id="dispSubtotal" style="text-align:right; font-weight:600;">0.00</span></div>
                            <div class="fld-row"><label>Disc (%):</label><input type="number" id="piDiscPersen" value="0" step="0.01" style="text-align:right;" oninput="onDiscPercentChange()"></div>
                            <div class="fld-row"><label>Nilai Disc:</label><input type="number" id="piDiscAmt" value="0" step="0.01" style="text-align:right;" oninput="onDiscAmtChange()"></div>
                            <div class="fld-row"><label>PPN (11%):</label><span id="dispPpn" style="text-align:right; font-weight:600;">0.00</span></div>
                            <div class="fld-row"><label><input type="checkbox" id="piPph" onchange="calcTotals()"> PPh 23 (2%):</label><span id="dispPph" style="text-align:right; font-weight:600;">0.00</span></div>
                            <div class="fld-row"><label>Biaya Jasa:</label><input type="number" id="piJasa" value="0" step="0.01" style="text-align:right;" oninput="calcTotals()"></div>
                            <div class="fld-row"><label>Freight:</label><input type="number" id="piFreight" value="0" step="0.01" style="text-align:right;" oninput="calcTotals()"></div>
                            <div class="fld-row"><label>Uang Muka:</label><input type="number" id="piDp" value="0" step="0.01" style="text-align:right;" oninput="calcTotals()"></div>
                            <div class="fld-row grand"><label style="color:var(--primary); font-weight:700;">GRAND TOTAL:</label><span id="dispGrandTotal" style="text-align:right; font-size:12px; font-weight:700; color:var(--primary);">0.00</span></div>
                            <div class="fld-row"><label>Terbayar:</label><input type="number" id="piTerbayar" value="0" step="0.01" style="text-align:right;" oninput="calcTotals()"></div>
                            <div class="fld-row out"><label style="color:var(--danger); font-weight:700;">Sisa Hutang:</label><span id="dispOutstand" style="text-align:right; font-size:11px; font-weight:700; color:var(--danger);">0.00</span></div>
                        </div>
                    </div>
                </div>

                <!-- Bottom Status Line -->
                <div class="statusline">
                    <span id="piModeBadge" class="ant-badge ant-badge-company">Mode: Baru / Siap Entri</span>
                    <span id="siStatus" class="muted">Siap menginput faktur pembelian.</span>
                    <span id="siUser" class="muted">Operator: <?= htmlspecialchars($currentUser) ?></span>
                </div>
            </div>
        </section>

        <!-- TAB 2: BROWSE DATA (DAFTAR FAKTUR & STATUS HUTANG AP) -->
        <section class="ant-page" id="pageBrowse">
            <!-- Dedicated 1-Row Compact Browse Filter Toolbar -->
            <div class="browse-filter">
                <label>Periode:</label>
                <input type="date" id="brwTglAwal" style="width:110px;">
                <label>s/d</label>
                <input type="date" id="brwTglAkhir" style="width:110px;">
                <button type="button" class="ant-btn" onclick="filterBrowseCurMonth()"><i class="fa-regular fa-calendar-days"></i> Bulan Ini</button>

                <label style="margin-left:4px;">Status:</label>
                <select id="brwStatus" style="width:100px;" onchange="loadPiList(1)">
                    <option value="ALL">Semua</option>
                    <option value="0">Draft</option>
                    <option value="1">Belum Lunas</option>
                    <option value="2">Lunas</option>
                    <option value="9">Batal</option>
                </select>

                <label>Dok BC:</label>
                <select id="brwBc" style="width:90px;" onchange="loadPiList(1)">
                    <option value="">Semua BC</option>
                    <option value="BC 2.3">BC 2.3</option>
                    <option value="BC 4.0">BC 4.0</option>
                    <option value="BC 2.7">BC 2.7</option>
                    <option value="BC 2.6.2">BC 2.6.2</option>
                    <option value="NON-BC">NON-BC</option>
                </select>

                <label>Pemasok:</label>
                <select id="brwVendor" style="width:160px;" onchange="loadPiList(1)">
                    <option value="">Semua Pemasok</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= htmlspecialchars($s['KODE']) ?>"><?= htmlspecialchars($s['KODE']) ?> - <?= htmlspecialchars($s['NAMA']) ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" id="brwSearch" placeholder="Cari No. Inv / PO / RI / FP / Aju..." style="width:180px;" onkeydown="if(event.key==='Enter') loadPiList(1)">

                <button type="button" class="ant-btn ant-btn-primary" onclick="loadPiList(1)"><i class="fa-solid fa-filter"></i> Filter</button>
                <button type="button" class="ant-btn" onclick="resetBrowseFilter()"><i class="fa-solid fa-rotate"></i> Reset</button>

                <span style="margin-left:auto; display:flex; align-items:center; gap:4px;">
                    <button type="button" class="ant-btn" onclick="exportBrowseExcel()"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Excel</button>
                    <button type="button" class="ant-btn" onclick="exportBrowseCsv()"><i class="fa-solid fa-file-csv"></i> CSV</button>
                    <select id="brwLimit" style="width:65px;" onchange="loadPiList(1)">
                        <option value="15">15</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="250">250</option>
                    </select>
                </span>
            </div>

            <!-- Browse Table (DataGrid) -->
            <div class="grid-wrap">
                <table class="dw" id="piTable">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th style="width:125px;">No. Invoice (PI)</th>
                            <th style="width:75px;">Tgl. Faktur</th>
                            <th style="width:75px;">Jatuh Tempo</th>
                            <th style="width:75px; text-align:center;">Status</th>
                            <th style="width:180px;">Pemasok / Vendor</th>
                            <th style="width:100px;">No. RI (SJ)</th>
                            <th style="width:100px;">No. PO Acuan</th>
                            <th style="width:65px; text-align:center;">Dok BC</th>
                            <th style="width:90px;">No. Daftar BC</th>
                            <th style="width:120px;">No. Pengajuan</th>
                            <th style="width:40px; text-align:center;">Item</th>
                            <th style="width:45px; text-align:center;">Kurs</th>
                            <th style="width:105px; text-align:right;">Grand Total</th>
                            <th style="width:95px; text-align:right;">Terbayar</th>
                            <th style="width:105px; text-align:right;">Sisa Hutang</th>
                            <th style="width:80px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="piTableBody">
                        <!-- Populated via AJAX -->
                    </tbody>
                </table>
            </div>

            <!-- Browse Pagination Bar -->
            <div class="pager">
                <span id="pageInfo">Memuat data...</span>
                <div id="pageBtns" style="display:flex; gap:3px;"></div>
            </div>
        </section>

        <!-- TAB 3: TRACE PENERIMAAN (RI) & DOKUMEN BC -->
        <section class="ant-page" id="pageTrace">
            <div class="split-col">
                <div style="padding:6px 10px; background:var(--table-head-bg); border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-weight:600;"><i class="fa-solid fa-route" style="color:var(--primary);"></i> Penelusuran Keterkaitan Faktur Pembelian, Dokumen Penerimaan (RI), PO, &amp; BC</span>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="loadTrace()"><i class="fa-solid fa-rotate"></i> Refresh Trace</button>
                </div>
                <div class="grid-wrap" style="padding:10px;">
                    <div style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:4px; padding:8px 12px; margin-bottom:10px;">
                        <span style="font-weight:700;" id="tracePiTitle">Pilih dokumen Faktur Pembelian pada tab Browse untuk melihat jejak penelusuran.</span>
                    </div>

                    <h4 style="margin:10px 0 4px 0; font-size:11px; color:var(--primary);"><i class="fa-solid fa-dolly"></i> 1. Dokumen Penerimaan Barang (Goods Receipt / RI Terkait)</h4>
                    <table class="dw" style="width:100%; margin-bottom:14px;">
                        <thead>
                            <tr>
                                <th>No. RI</th>
                                <th>Tgl. Terima</th>
                                <th>No. Surat Jalan Pemasok</th>
                                <th>Jenis BC</th>
                                <th>No. Daftar BC</th>
                                <th>Tgl. BC</th>
                                <th>Nomor Pengajuan (AJU)</th>
                                <th>No. PO</th>
                            </tr>
                        </thead>
                        <tbody id="traceRiBody">
                            <tr><td colspan="8" class="muted" style="text-align:center; padding:10px;">Tidak ada dokumen RI terkait.</td></tr>
                        </tbody>
                    </table>

                    <h4 style="margin:10px 0 4px 0; font-size:11px; color:var(--primary);"><i class="fa-solid fa-cart-shopping"></i> 2. Dokumen Purchase Order (PO Terkait)</h4>
                    <table class="dw" style="width:100%; margin-bottom:14px;">
                        <thead>
                            <tr>
                                <th>No. PO</th>
                                <th>Tgl. PO</th>
                                <th>Pemasok</th>
                                <th>Termin</th>
                                <th>Kurs</th>
                                <th>Rate</th>
                                <th style="text-align:right;">Total Order</th>
                            </tr>
                        </thead>
                        <tbody id="tracePoBody">
                            <tr><td colspan="7" class="muted" style="text-align:center; padding:10px;">Tidak ada dokumen PO terkait.</td></tr>
                        </tbody>
                    </table>

                    <h4 style="margin:10px 0 4px 0; font-size:11px; color:var(--primary);"><i class="fa-solid fa-boxes-stacked"></i> 3. Rincian Barang Faktur</h4>
                    <table class="dw" style="width:100%;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Kode Barang</th>
                                <th>Uraian Barang</th>
                                <th style="text-align:right;">Qty</th>
                                <th>Satuan</th>
                                <th style="text-align:right;">Harga</th>
                                <th style="text-align:right;">Total Nilai</th>
                                <th>No. RI</th>
                                <th>No. PO</th>
                                <th>Pos Tarif (HS)</th>
                            </tr>
                        </thead>
                        <tbody id="traceLinesBody">
                            <tr><td colspan="10" class="muted" style="text-align:center; padding:10px;">Pilih transaksi untuk memuat rincian barang.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PILIH MASTER BARANG (FG, RM, WIP, CG, SCRAP)                        -->
<!-- ========================================================================= -->
<div class="modal" id="modalMaterial">
    <div class="modal-card wide">
        <div class="modal-h">
            <span id="materialTitle"><i class="fa-solid fa-boxes-stacked"></i> Katalog Master Barang</span>
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
            <div class="grid-wrap" style="max-height:380px; border:1px solid var(--border-color); border-radius:3px;">
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
                        <tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Ketik pencarian atau klik Cari. Dobel-klik baris untuk memilih.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <label class="muted" style="display:flex; gap:6px; align-items:center; cursor:pointer;"><input type="checkbox" id="materialMulti"> Multi Pilih (Tetap buka popup setelah memilih)</label>
            <div class="inline-box">
                <input type="text" id="materialChosen" readonly placeholder="Kode terpilih" style="width:200px;">
                <button type="button" class="ant-btn ant-btn-primary" onclick="confirmMaterial()"><i class="fa-solid fa-check"></i> PILIH BARANG</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TARIK PENERIMAAN BARANG (RI / GRN)                                 -->
<!-- ========================================================================= -->
<div class="modal" id="modalLookupRi">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-dolly" style="color:#0ea5e9;"></i> Tarik Penerimaan Barang (Goods Receipt / RI Belum Difakturkan)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeLookupRi()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="searchRiText" style="flex:1;" placeholder="Cari No RI, Pemasok, Surat Jalan, PO, atau Barang..." onkeydown="if(event.key==='Enter') searchRi()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchRi()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
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
                            <th style="text-align:right;">Qty Sisa</th>
                            <th style="text-align:center;">Sat</th>
                            <th style="text-align:right;">Harga Sat</th>
                        </tr>
                    </thead>
                    <tbody id="lookupRiBody">
                        <tr><td colspan="11" class="muted" style="padding:12px; text-align:center;">Ketik pencarian atau klik Cari.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Item yang ditarik akan diakumulasi ke dalam tagihan faktur pembelian.</span>
            <div class="inline-box">
                <button type="button" class="ant-btn" onclick="closeLookupRi()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="applySelectedRi()"><i class="fa-solid fa-check"></i> Masukkan Item Terpilih</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TARIK PURCHASE ORDER (PO)                                          -->
<!-- ========================================================================= -->
<div class="modal" id="modalLookupPo">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-cart-shopping" style="color:#10b981;"></i> Tarik Purchase Order (PO Belum Difakturkan / Uang Muka)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeLookupPo()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="searchPoText" style="flex:1;" placeholder="Cari No PO, Pemasok, atau Barang..." onkeydown="if(event.key==='Enter') searchPo()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchPo()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;"><input type="checkbox" id="chkAllPo" onchange="toggleCheckAllPo(this.checked)"></th>
                            <th>No. PO</th>
                            <th>Tgl PO</th>
                            <th>Pemasok</th>
                            <th>Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="text-align:right;">Qty PO</th>
                            <th style="text-align:right;">Qty Sisa</th>
                            <th style="text-align:center;">Sat</th>
                            <th style="text-align:right;">Harga Sat</th>
                        </tr>
                    </thead>
                    <tbody id="lookupPoBody">
                        <tr><td colspan="10" class="muted" style="padding:12px; text-align:center;">Ketik pencarian atau klik Cari.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeLookupPo()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="applySelectedPo()"><i class="fa-solid fa-check"></i> Masukkan Item Terpilih</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: RIWAYAT PEMBAYARAN HUTANG (AP HISTORY)                             -->
<!-- ========================================================================= -->
<div class="modal" id="modalApHistory">
    <div class="modal-card">
        <div class="modal-h">
            <span><i class="fa-solid fa-receipt" style="color:#f59e0b;"></i> Riwayat Pembayaran Hutang Dagang (AP)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalApHistory()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <table class="dw" style="width:100%;">
                <thead>
                    <tr>
                        <th>No. Voucher Bayar</th>
                        <th>Tgl. Bayar</th>
                        <th>Akun Kas / Bank</th>
                        <th style="text-align:right;">Jumlah Bayar</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody id="apHistoryBody">
                    <tr><td colspan="5" class="muted" style="text-align:center; padding:12px;">Memuat riwayat pembayaran...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeModalApHistory()">Tutup</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: IMPORT DATA DARI EXCEL                                             -->
<!-- ========================================================================= -->
<div class="modal" id="modalImportExcel">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-file-import" style="color:var(--primary);"></i> Import Rincian Faktur Pembelian dari Excel / CSV</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalImportExcel()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--table-head-bg); padding:8px 12px; border:1px solid var(--border-color); border-radius:3px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <label style="font-weight:600;"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> File Excel / CSV:</label>
                    <input type="file" id="fileImportExcel" accept=".xlsx, .xls, .csv" onchange="handleExcelFileSelect(event)">
                </div>
                <button type="button" class="ant-btn ant-btn-sm" onclick="downloadPiImportTemplate()"><i class="fa-solid fa-download"></i> Template Excel</button>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; font-size:10px;">
                <div style="display:flex; gap:16px; align-items:center;">
                    <span style="font-weight:600;">Mode:</span>
                    <label style="cursor:pointer;"><input type="radio" name="importMode" value="append" checked> Tambahkan (Append)</label>
                    <label style="cursor:pointer;"><input type="radio" name="importMode" value="replace"> Ganti Semua (Replace)</label>
                </div>
                <span class="muted" id="importStats">Belum ada file dipilih</span>
            </div>
            <div class="grid-wrap" style="max-height:280px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" id="tblImportPreview" style="min-width:850px;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th>Kode Barang</th>
                            <th>Nama &amp; Uraian</th>
                            <th style="text-align:right;">Qty</th>
                            <th style="text-align:center;">Sat</th>
                            <th style="text-align:right;">Harga Satuan</th>
                            <th style="text-align:right;">Disc(%)</th>
                            <th>No. RI</th>
                            <th>No. PO</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyImportPreview">
                        <tr><td colspan="9" class="muted" style="text-align:center; padding:15px;">Pilih file Excel untuk memuat pratinjau data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeModalImportExcel()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" id="btnApplyImport" onclick="applyImportedExcelData()" disabled><i class="fa-solid fa-check"></i> Masukkan ke Faktur</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PANDUAN MODUL & SOP                                                -->
<!-- ========================================================================= -->
<div class="modal" id="modalSopGuide">
    <div class="modal-card">
        <div class="modal-h">
            <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Bisnis &amp; SOP Purchase Invoice (PI)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="line-height:1.6; font-size:10px;">
            <h4 style="color:var(--primary); font-size:11.5px; margin-bottom:4px;">1. Pengenalan Purchase Invoice (PI)</h4>
            <p>Purchase Invoice digunakan untuk mencatat pengakuan tagihan / hutang dagang (Accounts Payable) atas penerimaan bahan baku, barang penolong, atau jasa dari rekanan/vendor.</p>
            <h4 style="color:var(--primary); font-size:11.5px; margin:8px 0 4px 0;">2. Alur Penagihan &amp; Integrasi</h4>
            <ul style="margin-left:18px;">
                <li><b>Penerimaan Barang (RI / GRN)</b>: Penagihan ditarik dari bukti penerimaan barang fisik di gudang. Saat PI disimpan, status RI otomatis menjadi Invoiced.</li>
                <li><b>Pembukuan IT Inventory</b>: Gunakan tombol <b>Posting IT Inv</b> untuk membukukan transaksi ke laporan pertanggungjawaban kepabeanan (bc_1ab2, Pemasukan 'A'). Dokumen akan terkunci secara otomatis.</li>
                <li><b>Pembayaran Hutang (AP)</b>: Terintegrasi dengan modul pelunasan hutang dagang. Riwayat pencairan dapat dipantau via tombol <b>Riwayat AP</b>.</li>
            </ul>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Tutup</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- CONTEXT MENU GRID (KLIK KANAN PB STYLE)                                   -->
<!-- ========================================================================= -->
<div id="gridContextMenu" class="context-menu" style="display:none;" onclick="event.stopPropagation()">
    <div class="ctx-item" onclick="ctxTambahBaru()"><i class="fa-solid fa-plus" style="color:#8b5cf6;"></i> <span>Tambah Baris Baru</span> <kbd>Ctrl+I</kbd></div>
    <div class="ctx-item" onclick="ctxHapusBaris()"><i class="fa-solid fa-xmark" style="color:#ff4d4f;"></i> <span>Hapus Baris</span> <kbd>Ctrl+Del</kbd></div>
    <div class="ctx-item" onclick="ctxPilihBarang()"><i class="fa-solid fa-boxes-stacked" style="color:#52c41a;"></i> <span>Pilih dari Master Barang</span> <kbd>Ctrl+E</kbd></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="openLookupRi(); hideContextMenu();"><i class="fa-solid fa-dolly" style="color:#0ea5e9;"></i> <span>Tarik dari Penerimaan (RI)</span></div>
    <div class="ctx-item" onclick="openLookupPo(); hideContextMenu();"><i class="fa-solid fa-cart-shopping" style="color:#10b981;"></i> <span>Tarik dari Purchase Order (PO)</span></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="openModalImportExcel(); hideContextMenu();"><i class="fa-solid fa-file-import" style="color:#8b5cf6;"></i> <span>Import dari Excel...</span></div>
    <div class="ctx-item" onclick="exportGridToExcel(); hideContextMenu();"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> <span>Export Rincian ke Excel</span></div>
    <div class="ctx-item" onclick="downloadPiImportTemplate(); hideContextMenu();"><i class="fa-solid fa-download" style="color:#faad14;"></i> <span>Download Template Import</span></div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT CONTROLLER IDENTIK SALES INVOICE (SI)                          -->
<!-- ========================================================================= -->
<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let piLocked = false;
let currentBrowsePage = 1;
let lastCalc = { subtotal: 0, discPersen: 0, discAmt: 0, dpp: 0, ppn: 0, pph: 0, grand: 0, outstand: 0 };
let currentActiveTargetInput = null;
let currentMaterialKlas = 'RM';
let cachedRiData = [];
let cachedPoData = [];
let importedExcelRows = [];

function initPurchaseInvoiceApp() {
    try { initSplitbars(); } catch(e) { console.warn('initSplitbars:', e); }
    try { initGridContextMenu(); } catch(e) { console.warn('initGridContextMenu:', e); }
    if (typeof initAntSearchableSelect === 'function') {
        initAntSearchableSelect('piVendor', { placeholder: 'Ketik nama / kode supplier...' });
        initAntSearchableSelect('brwVendor', { placeholder: 'Semua Supplier...' });
    }
    resetForm();
    loadPiList(1);
    
    // Fallback safety to guarantee combobox creation
    setTimeout(() => {
        const piV = document.getElementById('piVendor');
        if (piV && !piV._antSearchableInstance && typeof initAntSearchableSelect === 'function') {
            initAntSearchableSelect('piVendor', { placeholder: 'Ketik nama / kode supplier...' });
        }
        const brwV = document.getElementById('brwVendor');
        if (brwV && !brwV._antSearchableInstance && typeof initAntSearchableSelect === 'function') {
            initAntSearchableSelect('brwVendor', { placeholder: 'Semua Supplier...' });
        }
    }, 100);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPurchaseInvoiceApp);
} else {
    initPurchaseInvoiceApp();
}

// -------------------------------------------------------------------------
// TAB NAVIGATION
// -------------------------------------------------------------------------
function showTab(name) {
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.getElementById('pageEntri').classList.toggle('active', name === 'entri');
    document.getElementById('pageBrowse').classList.toggle('active', name === 'browse');
    document.getElementById('pageTrace').classList.toggle('active', name === 'trace');
    if (name === 'browse') loadPiList(currentBrowsePage);
    if (name === 'trace') loadTrace();
}

function setStatus(msg, isErr = false) {
    const el = document.getElementById('siStatus');
    el.textContent = msg;
    el.style.color = isErr ? 'var(--danger)' : 'var(--text-muted)';
}

function guardEdit() {
    if (!piLocked) return false;
    Swal.fire('Terkunci IT Inventory', 'Dokumen ini berstatus Terkunci karena sudah dibukukan ke IT Inventory. Buka kunci terlebih dahulu untuk mengedit.', 'warning');
    return true;
}

function applyLock(locked) {
    piLocked = !!locked;
    const page = document.getElementById('pageEntri');
    page.classList.toggle('is-locked', piLocked);
    document.getElementById('piLock').style.display = piLocked ? 'flex' : 'none';
    document.getElementById('btnDelItInv').style.display = piLocked ? 'inline-flex' : 'none';
    document.getElementById('btnTransferItInv').style.display = piLocked ? 'none' : 'inline-flex';
    
    const badge = document.getElementById('statusBadgeHeader');
    if (piLocked) {
        badge.className = 'ant-badge ant-badge-lock';
        badge.innerHTML = '<i class="fa-solid fa-lock"></i> Terkunci IT Inv';
        document.getElementById('piModeBadge').className = 'ant-badge ant-badge-lock';
        document.getElementById('piModeBadge').textContent = 'Status: Terkunci IT Inventory (Read-Only)';
    } else {
        updateStatusBadge();
    }

    page.querySelectorAll('input, select, textarea, button').forEach(el => {
        if (el.id === 'piId' || el.classList.contains('split-btn-collapse') || el.id === 'btnDelItInv') return;
        if (el.dataset.lock === 'block') {
            el.disabled = piLocked;
        } else if (piLocked && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA')) {
            el.disabled = true;
        } else {
            el.disabled = false;
        }
    });
}

function updateStatusBadge() {
    if (piLocked) return;
    const badge = document.getElementById('statusBadgeHeader');
    const isLunas = lastCalc.outstand <= 0 && lastCalc.grand > 0;
    if (isLunas) {
        badge.className = 'ant-badge ant-badge-lunas';
        badge.innerHTML = '<i class="fa-solid fa-check"></i> LUNAS';
    } else {
        badge.className = 'ant-badge ant-badge-belum';
        badge.innerHTML = 'BELUM LUNAS';
    }
}

// -------------------------------------------------------------------------
// RESIZABLE SPLITBARS (IDENTIK SI & TRANSFER)
// -------------------------------------------------------------------------
function initSplitbars() {
    document.querySelectorAll('.splitbar, .split-divider').forEach(bar => {
        const pane = document.getElementById(bar.dataset.pane || '');
        if (!pane) return;
        const key = 'pi-split-' + (bar.id || bar.dataset.pane);
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

let piMaxPane = null;
function togglePiPane(which) {
    const panes = { hdr: 'paneHdr', ftr: 'paneFtr' };
    const el = document.getElementById(panes[which]);
    if (!el) return;
    const isClosed = el.style.display === 'none';
    el.style.display = isClosed ? '' : 'none';
}

// -------------------------------------------------------------------------
// PERHITUNGAN JATUH TEMPO, RATE, & FORM INIT
// -------------------------------------------------------------------------
function onDateOrTermChange() {
    const tgl = document.getElementById('piDt').value;
    const term = document.getElementById('piTerm').value.trim();
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
    document.getElementById('piJatuhTempo').value = d.toISOString().slice(0, 10);
}

function onVendorChange() {
    const sel = document.getElementById('piVendor');
    if (!sel) return;
    const opt = (sel.options && sel.selectedIndex >= 0) ? sel.options[sel.selectedIndex] : (sel.selectedOptions ? sel.selectedOptions[0] : null);
    if (opt && opt.value) {
        document.getElementById('piAlamat').value = opt.dataset.alamat || '';
        if (opt.dataset.top) {
            document.getElementById('piTerm').value = opt.dataset.top;
            onDateOrTermChange();
        }
        if (opt.dataset.kurs) {
            document.getElementById('piKurs').value = opt.dataset.kurs;
            toggleRate();
        }
    } else {
        document.getElementById('piAlamat').value = '';
    }
}

function toggleRate() {
    const kurs = document.getElementById('piKurs').value;
    const isIdr = (kurs === 'IDR');
    if (isIdr) {
        document.getElementById('piRate').value = '1';
    }
    calcTotals();
}

async function generateAutoPiNo() {
    try {
        const res = await fetch('purchase_invoice.php?action=generate_no&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID));
        const json = await res.json();
        if (json.status === 'OK' && json.pi_no) {
            document.getElementById('piNo').value = json.pi_no;
            setStatus('Nomor faktur di-generate otomatis: ' + json.pi_no);
        }
    } catch (e) {
        console.warn('Gagal generate nomor:', e);
    }
}

function resetForm() {
    document.getElementById('piId').value = '';
    document.getElementById('piNo').value = '';
    document.getElementById('piDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('piShipDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('piRiNo').value = '';
    document.getElementById('piPoNo').value = '';
    
    const vEl = document.getElementById('piVendor');
    vEl.value = '';
    vEl.dispatchEvent(new Event('change', { bubbles: true }));
    if (vEl._antSearchableInstance && vEl._antSearchableInstance.input) {
        vEl._antSearchableInstance.input.value = '';
    }
    document.getElementById('piAlamat').value = '';
    document.getElementById('piTerm').value = 'Net 30';
    onDateOrTermChange();
    document.getElementById('piKurs').value = 'IDR';
    document.getElementById('piRate').value = '1';
    document.getElementById('piTaxable').checked = true;
    document.getElementById('piInclusive').checked = false;
    document.getElementById('piSubcon').checked = false;
    document.getElementById('piNcv').checked = false;
    document.getElementById('piBill2').checked = false;
    document.getElementById('piKdFp').value = '01';
    document.getElementById('piNoFp').value = '';
    document.getElementById('piBc').value = '';
    document.getElementById('piNoBc').value = '';
    document.getElementById('piTglBc').value = '';
    document.getElementById('piNoAju').value = '';
    document.getElementById('piNote').value = '';
    document.getElementById('piDiscPersen').value = '0';
    document.getElementById('piDiscAmt').value = '0';
    document.getElementById('piJasa').value = '0';
    document.getElementById('piFreight').value = '0';
    document.getElementById('piDp').value = '0';
    document.getElementById('piPph').checked = false;
    document.getElementById('piTerbayar').value = '0';
    
    document.getElementById('itemsContainer').innerHTML = '';
    addItemRow();
    applyLock(false);
    toggleRate();
    generateAutoPiNo();

    document.getElementById('piModeBadge').className = 'ant-badge ant-badge-company';
    document.getElementById('piModeBadge').textContent = 'Mode: Baru / Siap Entri';
    setStatus('Transaksi baru. Pilih Pemasok/Vendor, isi rincian barang, lalu Simpan Transaksi.');
}

// -------------------------------------------------------------------------
// GRID ITEM ROWS & REAL-TIME CALCULATIONS
// -------------------------------------------------------------------------
function tambahBaris() {
    if (guardEdit()) return;
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
    row.innerHTML = `
        <td style="text-align:center;" class="it-seq col-seq">${seq}</td>
        <td class="col-kode">
            <div class="inline-box">
                <input type="text" class="it-kode" value="${escapeHtml(item.kode_barang || '')}" placeholder="Pilih barang..." ondblclick="openMaterialPicker(this)">
                <button type="button" class="ant-btn ant-btn-sm" title="Buka katalog barang" onclick="openMaterialPicker(this)"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </td>
        <td class="col-uraian"><input type="text" class="it-uraian" value="${escapeHtml(item.uraian || '')}" placeholder="Nama barang" ondblclick="openMaterialPicker(this)"></td>
        <td class="col-qty"><input type="number" class="it-qty" value="${item.qty ?? 1}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-sat"><input type="text" class="it-sat" value="${escapeHtml(item.sat || 'PCE')}" style="text-align:center;"></td>
        <td class="col-price"><input type="number" class="it-price" value="${item.price ?? 0}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-disc"><input type="number" class="it-disc" value="${item.disc ?? 0}" step="0.01" oninput="calcItemRow(this)"></td>
        <td class="col-amount"><input type="number" class="it-amount" value="${item.amount ?? 0}" step="0.01" readonly style="font-weight:700;"></td>
        <td class="col-price_jasa"><input type="number" class="it-price_jasa" value="${item.price_jasa ?? 0}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-amt_jasa"><input type="number" class="it-amt_jasa" value="${item.amt_jasa ?? 0}" step="0.01" readonly></td>
        <td class="col-rino"><input type="text" class="it-rino" value="${escapeHtml(item.ri_no || '')}" placeholder="Ref RI"></td>
        <td class="col-pono"><input type="text" class="it-pono" value="${escapeHtml(item.po_no || '')}" placeholder="Ref PO"></td>
        <td class="col-gudang"><input type="text" class="it-gudang" value="${escapeHtml(item.gudang || '')}" placeholder="Gudang"></td>
        <td class="col-postarif"><input type="text" class="it-postarif" value="${escapeHtml(item.pos_tarif || '')}" placeholder="HS Code"></td>
        <td class="col-note"><input type="text" class="it-note" value="${escapeHtml(item.note || '')}" placeholder="Catatan"></td>
        <td class="col-action" style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    container.appendChild(row);
    calcItemRow(row.querySelector('.it-qty'));
    renumber();
}

function removeRow(btn) {
    if (guardEdit()) return;
    const body = document.getElementById('itemsContainer');
    btn.closest('tr').remove();
    if (!body.querySelector('.item-row')) addItemRow();
    renumber();
    calcTotals();
}

function clearAllRows() {
    if (guardEdit()) return;
    document.getElementById('itemsContainer').innerHTML = '';
    addItemRow();
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
    const qty = parseFloat(row.querySelector('.it-qty')?.value) || 0;
    const price = parseFloat(row.querySelector('.it-price')?.value) || 0;
    const disc = parseFloat(row.querySelector('.it-disc')?.value) || 0;
    const amt = (qty * price) * (1 - (disc / 100));
    if (row.querySelector('.it-amount')) row.querySelector('.it-amount').value = amt.toFixed(2);

    const priceJasa = parseFloat(row.querySelector('.it-price_jasa')?.value) || 0;
    const amtJasa = qty * priceJasa;
    if (row.querySelector('.it-amt_jasa')) row.querySelector('.it-amt_jasa').value = amtJasa.toFixed(2);

    calcTotals();
}

function onDiscPercentChange() {
    const subtotal = lastCalc.subtotal;
    const pct = parseFloat(document.getElementById('piDiscPersen').value) || 0;
    const amt = (subtotal * pct) / 100;
    document.getElementById('piDiscAmt').value = amt.toFixed(2);
    calcTotals();
}

function onDiscAmtChange() {
    const subtotal = lastCalc.subtotal;
    const amt = parseFloat(document.getElementById('piDiscAmt').value) || 0;
    const pct = subtotal > 0 ? (amt / subtotal * 100) : 0;
    document.getElementById('piDiscPersen').value = pct.toFixed(2);
    calcTotals();
}

function calcTotals() {
    let subtotal = 0;
    let totalJasa = 0;
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        subtotal += parseFloat(row.querySelector('.it-amount')?.value) || 0;
        totalJasa += parseFloat(row.querySelector('.it-amt_jasa')?.value) || 0;
    });

    const discAmt = parseFloat(document.getElementById('piDiscAmt').value) || 0;
    const discPersen = parseFloat(document.getElementById('piDiscPersen').value) || 0;
    const net = Math.max(0, subtotal - discAmt);

    const taxable = document.getElementById('piTaxable').checked;
    const inclusive = document.getElementById('piInclusive').checked;
    const isSubcon = document.getElementById('piSubcon').checked;

    let ppn = 0;
    let dpp = net;

    if (taxable && net > 0) {
        if (inclusive) {
            ppn = net * 11 / 111;
            dpp = net - ppn;
        } else {
            ppn = net * 0.11;
        }
    }

    let pph = 0;
    if (document.getElementById('piPph').checked || isSubcon) {
        pph = dpp * 0.02; // PPh Pasal 23 (2%)
        document.getElementById('piPph').checked = true;
    }

    const manualJasa = parseFloat(document.getElementById('piJasa').value) || 0;
    const freight = parseFloat(document.getElementById('piFreight').value) || 0;
    const dp = parseFloat(document.getElementById('piDp').value) || 0;
    const grand = (inclusive ? net : net + ppn) + totalJasa + manualJasa + freight - pph;
    const terbayar = parseFloat(document.getElementById('piTerbayar').value) || 0;
    const outstand = Math.max(0, grand - dp - terbayar);

    lastCalc = { subtotal, discPersen, discAmt, dpp, ppn, pph, grand, outstand };

    document.getElementById('dispSubtotal').textContent = formatNum(subtotal);
    document.getElementById('dispPpn').textContent = formatNum(ppn);
    document.getElementById('dispPph').textContent = formatNum(pph);
    document.getElementById('dispGrandTotal').textContent = formatNum(grand);
    document.getElementById('dispOutstand').textContent = formatNum(outstand);
    
    const dispSay = document.getElementById('dispTerbilang');
    if (dispSay) dispSay.textContent = '# ' + terbilangJs(grand) + ' #';

    updateStatusBadge();
}

function collectItems() {
    const items = [];
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        const kode = row.querySelector('.it-kode')?.value.trim();
        const uraian = row.querySelector('.it-uraian')?.value.trim();
        if (!kode && !uraian) return;
        items.push({
            kode_barang: kode,
            uraian: uraian,
            qty: parseFloat(row.querySelector('.it-qty')?.value) || 0,
            sat: row.querySelector('.it-sat')?.value.trim() || 'PCE',
            price: parseFloat(row.querySelector('.it-price')?.value) || 0,
            disc: parseFloat(row.querySelector('.it-disc')?.value) || 0,
            amount: parseFloat(row.querySelector('.it-amount')?.value) || 0,
            price_jasa: parseFloat(row.querySelector('.it-price_jasa')?.value) || 0,
            amt_jasa: parseFloat(row.querySelector('.it-amt_jasa')?.value) || 0,
            ri_no: row.querySelector('.it-rino')?.value.trim() || document.getElementById('piRiNo').value,
            po_no: row.querySelector('.it-pono')?.value.trim() || document.getElementById('piPoNo').value,
            gudang: row.querySelector('.it-gudang')?.value.trim() || '',
            pos_tarif: row.querySelector('.it-postarif')?.value.trim() || '',
            note: row.querySelector('.it-note')?.value.trim() || ''
        });
    });
    return items;
}

// -------------------------------------------------------------------------
// SIMPAN TRANSAKSI PI
// -------------------------------------------------------------------------
async function savePi() {
    if (guardEdit()) return;
    const vendor = document.getElementById('piVendor').value;
    if (!vendor) {
        Swal.fire('Validasi', 'Supplier / Vendor belum dipilih!', 'warning');
        document.getElementById('piVendor').focus();
        return;
    }
    const items = collectItems();
    if (items.length === 0) {
        Swal.fire('Validasi', 'Mohon isi minimal 1 baris rincian barang!', 'warning');
        return;
    }
    calcTotals();

    const payload = {
        header: {
            id: document.getElementById('piId').value,
            pi_no: document.getElementById('piNo').value,
            pi_dt: document.getElementById('piDt').value,
            ship_dt: document.getElementById('piShipDt').value,
            jatuhtempo: document.getElementById('piJatuhTempo').value,
            ri_no: document.getElementById('piRiNo').value,
            po_no: document.getElementById('piPoNo').value,
            vendor: vendor,
            vendoristaxable: document.getElementById('piTaxable').checked ? 'Y' : 'N',
            inclusivetax: document.getElementById('piInclusive').checked ? 'Y' : 'N',
            issubcon: document.getElementById('piSubcon').checked ? 'Y' : 'N',
            ncv: document.getElementById('piNcv').checked ? '1' : '0',
            bill2buyer: document.getElementById('piBill2').checked ? 'Y' : 'N',
            term: document.getElementById('piTerm').value,
            kurs: document.getElementById('piKurs').value,
            rate: parseFloat(document.getElementById('piRate').value) || 1,
            est_freight: parseFloat(document.getElementById('piFreight').value) || 0,
            jasa: parseFloat(document.getElementById('piJasa').value) || 0,
            dp: parseFloat(document.getElementById('piDp').value) || 0,
            terbayar: parseFloat(document.getElementById('piTerbayar').value) || 0,
            kd_fp: document.getElementById('piKdFp').value,
            no_fp: document.getElementById('piNoFp').value,
            bc: document.getElementById('piBc').value,
            no_bc: document.getElementById('piNoBc').value,
            tgl_bc: document.getElementById('piTglBc').value,
            no_aju: document.getElementById('piNoAju').value,
            note: document.getElementById('piNote').value,
            disc_persen: parseFloat(document.getElementById('piDiscPersen').value) || 0,
            disc_amt: parseFloat(document.getElementById('piDiscAmt').value) || 0
        },
        details: items
    };

    setStatus('Menyimpan transaksi...');
    try {
        const res = await fetch('purchase_invoice.php?action=save&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        document.getElementById('piId').value = json.id;
        if (json.pi_no) document.getElementById('piNo').value = json.pi_no;

        document.getElementById('piModeBadge').className = 'ant-badge ant-badge-company';
        document.getElementById('piModeBadge').textContent = 'Mode: Edit Dokumen (' + json.pi_no + ')';
        setStatus('Tersimpan: ' + new Date().toLocaleTimeString());

        Swal.fire({
            icon: 'success',
            title: 'Berhasil Disimpan',
            text: `Faktur Pembelian "${json.pi_no}" berhasil disimpan.`,
            timer: 1800,
            showConfirmButton: false
        });
    } catch (e) {
        setStatus('Gagal menyimpan', true);
        Swal.fire('Gagal Menyimpan', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// EDIT LOAD DARI BROWSE
// -------------------------------------------------------------------------
async function loadPiForEdit(id) {
    try {
        const res = await fetch(`purchase_invoice.php?action=get&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        const h = json.header;
        document.getElementById('piId').value = h.id || '';
        document.getElementById('piNo').value = h.pi_no || '';
        document.getElementById('piDt').value = h.pi_dt ? h.pi_dt.slice(0, 10) : '';
        document.getElementById('piShipDt').value = h.ship_dt ? h.ship_dt.slice(0, 10) : '';
        document.getElementById('piJatuhTempo').value = h.jatuhtempo ? h.jatuhtempo.slice(0, 10) : '';
        document.getElementById('piRiNo').value = h.ri_no || '';
        document.getElementById('piPoNo').value = h.po_no || '';
        
        const vEl = document.getElementById('piVendor');
        vEl.value = h.vendor || '';
        vEl.dispatchEvent(new Event('change', { bubbles: true }));
        if (vEl._antSearchableInstance && vEl._antSearchableInstance.input) {
            vEl._antSearchableInstance.input.value = h.vendor_nama ? `${h.vendor} - ${h.vendor_nama}` : h.vendor;
        }

        document.getElementById('piAlamat').value = h.vendor_alamat || '';
        document.getElementById('piTerm').value = h.term || 'Net 30';
        document.getElementById('piKurs').value = h.kurs || 'IDR';
        document.getElementById('piRate').value = h.rate || 1;
        document.getElementById('piTaxable').checked = (h.vendoristaxable !== 'N');
        document.getElementById('piInclusive').checked = (h.inclusivetax === 'Y');
        document.getElementById('piSubcon').checked = (h.issubcon === 'Y');
        document.getElementById('piNcv').checked = (h.ncv === '1');
        document.getElementById('piBill2').checked = (h.bill2buyer === 'Y');
        document.getElementById('piKdFp').value = h.kd_fp || '01';
        document.getElementById('piNoFp').value = h.no_fp || '';
        document.getElementById('piBc').value = h.bc || '';
        document.getElementById('piNoBc').value = h.no_bc || '';
        document.getElementById('piTglBc').value = h.tgl_bc ? h.tgl_bc.slice(0, 10) : '';
        document.getElementById('piNoAju').value = h.no_aju || '';
        document.getElementById('piNote').value = h.note || '';
        document.getElementById('piDiscPersen').value = parseFloat(h.disc_persen) || 0;
        document.getElementById('piDiscAmt').value = parseFloat(h.disc_amt) || 0;
        document.getElementById('piJasa').value = parseFloat(h.jasa) || 0;
        document.getElementById('piFreight').value = parseFloat(h.est_freight) || 0;
        document.getElementById('piDp').value = parseFloat(h.dp) || 0;
        document.getElementById('piPph').checked = (parseFloat(h.pph) > 0);
        document.getElementById('piTerbayar').value = parseFloat(h.terbayar) || 0;

        document.getElementById('itemsContainer').innerHTML = '';
        if (json.details && json.details.length > 0) {
            json.details.forEach(it => addItemRow(it));
        } else {
            addItemRow();
        }

        applyLock(h.is_locked);
        calcTotals();

        document.getElementById('piModeBadge').className = 'ant-badge ant-badge-company';
        document.getElementById('piModeBadge').textContent = 'Mode: Edit Dokumen (' + h.pi_no + ')';
        setStatus('Memuat data faktur: ' + h.pi_no);
        showTab('entri');
    } catch (e) {
        Swal.fire('Gagal Memuat', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// POSTING & BUKA KUNCI IT INVENTORY
// -------------------------------------------------------------------------
async function postToItInventory() {
    const id = document.getElementById('piId').value;
    const piNo = document.getElementById('piNo').value;
    if (!id) {
        Swal.fire('Informasi', 'Simpan transaksi terlebih dahulu sebelum posting ke IT Inventory.', 'info');
        return;
    }

    const ask = await Swal.fire({
        title: 'Posting ke IT Inventory?',
        text: `Faktur Pembelian "${piNo}" akan dibukukan ke Laporan Pemasokan IT Inventory (bc_1ab2, AB='A'). Dokumen akan TERKUNCI.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Posting Sekarang'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('purchase_invoice.php?action=transfer_it_inv&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        applyLock(true);
        Swal.fire('Sukses', json.message, 'success');
    } catch (e) {
        Swal.fire('Gagal Posting', e.message, 'error');
    }
}

async function deleteFromItInventory() {
    const id = document.getElementById('piId').value;
    const piNo = document.getElementById('piNo').value;
    if (!id) return;

    const ask = await Swal.fire({
        title: 'Buka Kunci IT Inventory?',
        text: `Hapus pembukuan Faktur "${piNo}" dari IT Inventory agar dokumen dapat diedit kembali?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Ya, Buka Kunci'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('purchase_invoice.php?action=delete_it_inv&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        applyLock(false);
        Swal.fire('Kunci Dibuka', json.message, 'success');
    } catch (e) {
        Swal.fire('Gagal Buka Kunci', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// HAPUS & CETAK DOKUMEN PI
// -------------------------------------------------------------------------
async function deleteCurrent() {
    if (guardEdit()) return;
    const id = document.getElementById('piId').value;
    const piNo = document.getElementById('piNo').value;
    if (!id) {
        Swal.fire('Informasi', 'Pilih atau simpan dokumen terlebih dahulu sebelum menghapus.', 'info');
        return;
    }

    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus Faktur Pembelian "${piNo}"? Realisasi pada Penerimaan Barang (RI) akan dikembalikan.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Ya, Hapus Sekarang'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('purchase_invoice.php?action=delete&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire('Terhapus', `Faktur Pembelian "${piNo}" telah dihapus.`, 'success');
        resetForm();
    } catch (e) {
        Swal.fire('Gagal Menghapus', e.message, 'error');
    }
}

function printPi() {
    const id = document.getElementById('piId').value;
    if (!id) {
        Swal.fire('Informasi', 'Simpan faktur terlebih dahulu sebelum mencetak voucher.', 'info');
        return;
    }
    const url = `purchase_invoice.php?action=print&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
    window.open(url, '_blank', 'width=950,height=800,scrollbars=yes');
}

function openQrLabelPi() {
    const piNo = (document.getElementById('pi_no')?.value || '').trim();
    if (!piNo) {
        Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Nomor Faktur Pembelian (PI) belum tersimpan / belum dipilih.' });
        return;
    }
    window.open(`inv_qr_label.php?ref_type=PI&ref_no=${encodeURIComponent(piNo)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

// -------------------------------------------------------------------------
// MODAL: PILIH MASTER BARANG (FG, RM, WIP, CG, W)
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
    tbody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:12px;">Mencari katalog barang...</td></tr>';
    try {
        const url = `purchase_invoice.php?action=search_materials&q=${encodeURIComponent(q)}&klas=${encodeURIComponent(currentMaterialKlas)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);
        if (!json.data || json.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:12px;">Tidak ada barang yang cocok.</td></tr>';
            return;
        }
        let html = '';
        json.data.forEach(item => {
            html += `
                <tr ondblclick="pickMaterialItem('${escapeHtml(item.kode_barang)}', '${escapeHtml(item.uraian)}', '${escapeHtml(item.sat)}', ${item.harga || 0}, '${escapeHtml(item.pos_tarif || '')}')" style="cursor:pointer;">
                    <td><b>${escapeHtml(item.kode_barang)}</b></td>
                    <td>${escapeHtml(item.uraian)}</td>
                    <td>${escapeHtml(item.sat)}</td>
                    <td style="text-align:right;">${numberFormat(item.harga)}</td>
                    <td style="text-align:right;">${numberFormat(item.berat)}</td>
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
function confirmMaterial() {
    const val = document.getElementById('materialChosen').value.trim();
    if (val) {
        searchMaterials();
    }
}

// -------------------------------------------------------------------------
// MODAL: TARIK PENERIMAAN BARANG (RI / GRN)
// -------------------------------------------------------------------------
function openLookupRi() {
    if (guardEdit()) return;
    document.getElementById('modalLookupRi').classList.add('active');
    searchRi();
}
function closeLookupRi() {
    document.getElementById('modalLookupRi').classList.remove('active');
}
async function searchRi() {
    const vendor = document.getElementById('piVendor').value;
    const q = document.getElementById('searchRiText').value.trim();
    const tbody = document.getElementById('lookupRiBody');
    tbody.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center; padding:12px;">Mencari penerimaan barang...</td></tr>';
    try {
        const url = `purchase_invoice.php?action=lookup_ri_outstanding&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);
        cachedRiData = json.data || [];
        if (cachedRiData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center; padding:12px;">Tidak ada penerimaan barang yang belum difakturkan.</td></tr>';
            return;
        }
        let html = '';
        cachedRiData.forEach((d, i) => {
            html += `
                <tr>
                    <td style="text-align:center;"><input type="checkbox" class="chk-ri-item" value="${i}"></td>
                    <td><b>${escapeHtml(d.ri_no)}</b></td>
                    <td>${d.ri_dt_fmt}</td>
                    <td>${escapeHtml(d.vendor_nama)}</td>
                    <td>${escapeHtml(d.no_sj || '-')}</td>
                    <td>${escapeHtml(d.po_no || '-')}</td>
                    <td><b>${escapeHtml(d.kode_barang)}</b></td>
                    <td>${escapeHtml(d.uraian)}</td>
                    <td style="text-align:right; font-weight:700;">${d.qty_sisa_fmt}</td>
                    <td style="text-align:center;">${escapeHtml(d.sat)}</td>
                    <td style="text-align:right;">${d.price_fmt}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="11" style="color:var(--danger); text-align:center; padding:12px;">${e.message}</td></tr>`;
    }
}
function toggleCheckAllRi(chk) {
    document.querySelectorAll('.chk-ri-item').forEach(c => c.checked = chk);
}
function applySelectedRi() {
    const checked = document.querySelectorAll('.chk-ri-item:checked');
    if (checked.length === 0) {
        Swal.fire('Pilih Item', 'Silakan centang minimal 1 item untuk dimasukkan.', 'warning');
        return;
    }
    const container = document.getElementById('itemsContainer');
    // Jika baris pertama masih kosong melompong, hapus
    const firstRow = container.querySelector('.item-row');
    if (firstRow && !firstRow.querySelector('.it-kode').value.trim() && !firstRow.querySelector('.it-uraian').value.trim()) {
        firstRow.remove();
    }

    let firstItem = null;
    checked.forEach(c => {
        const idx = parseInt(c.value, 10);
        const d = cachedRiData[idx];
        if (d) {
            if (!firstItem) firstItem = d;
            addItemRow({
                kode_barang: d.kode_barang,
                uraian: d.uraian,
                qty: parseFloat(d.qty_sisa) || 1,
                sat: d.sat,
                price: parseFloat(d.price) || 0,
                disc: parseFloat(d.disc) || 0,
                ri_no: d.ri_no,
                po_no: d.po_no,
                id_ri: d.id_ri_dtl,
                id_po: ''
            });
        }
    });

    // Auto-isi Header jika masih kosong
    if (firstItem) {
        const vEl = document.getElementById('piVendor');
        if (!vEl.value && firstItem.vendor) {
            vEl.value = firstItem.vendor;
            vEl.dispatchEvent(new Event('change', { bubbles: true }));
            if (vEl._antSearchableInstance && vEl._antSearchableInstance.input) {
                vEl._antSearchableInstance.input.value = `${firstItem.vendor} - ${firstItem.vendor_nama}`;
            }
        }
        if (!document.getElementById('piRiNo').value && firstItem.ri_no) {
            document.getElementById('piRiNo').value = firstItem.ri_no;
        }
        if (!document.getElementById('piPoNo').value && firstItem.po_no) {
            document.getElementById('piPoNo').value = firstItem.po_no;
        }
        if (!document.getElementById('piBc').value && firstItem.bc) {
            document.getElementById('piBc').value = firstItem.bc;
        }
        if (!document.getElementById('piNoBc').value && firstItem.no_bc) {
            document.getElementById('piNoBc').value = firstItem.no_bc;
        }
        if (!document.getElementById('piTglBc').value && firstItem.tgl_bc) {
            document.getElementById('piTglBc').value = firstItem.tgl_bc.slice(0, 10);
        }
        if (!document.getElementById('piNoAju').value && firstItem.no_aju) {
            document.getElementById('piNoAju').value = firstItem.no_aju;
        }
    }

    closeLookupRi();
    calcTotals();
}

// -------------------------------------------------------------------------
// MODAL: TARIK PURCHASE ORDER (PO)
// -------------------------------------------------------------------------
function openLookupPo() {
    if (guardEdit()) return;
    document.getElementById('modalLookupPo').classList.add('active');
    searchPo();
}
function closeLookupPo() {
    document.getElementById('modalLookupPo').classList.remove('active');
}
async function searchPo() {
    const vendor = document.getElementById('piVendor').value;
    const q = document.getElementById('searchPoText').value.trim();
    const tbody = document.getElementById('lookupPoBody');
    tbody.innerHTML = '<tr><td colspan="10" class="muted" style="text-align:center; padding:12px;">Mencari Purchase Order...</td></tr>';
    try {
        const url = `purchase_invoice.php?action=lookup_po_outstanding&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);
        cachedPoData = json.data || [];
        if (cachedPoData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="muted" style="text-align:center; padding:12px;">Tidak ada PO yang belum difakturkan.</td></tr>';
            return;
        }
        let html = '';
        cachedPoData.forEach((d, i) => {
            html += `
                <tr>
                    <td style="text-align:center;"><input type="checkbox" class="chk-po-item" value="${i}"></td>
                    <td><b>${escapeHtml(d.po_no)}</b></td>
                    <td>${d.po_dt_fmt}</td>
                    <td>${escapeHtml(d.vendor_nama)}</td>
                    <td><b>${escapeHtml(d.kode_barang)}</b></td>
                    <td>${escapeHtml(d.uraian)}</td>
                    <td style="text-align:right;">${d.qty_po_fmt}</td>
                    <td style="text-align:right; font-weight:700;">${d.qty_sisa_fmt}</td>
                    <td style="text-align:center;">${escapeHtml(d.sat)}</td>
                    <td style="text-align:right;">${d.price_fmt}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="10" style="color:var(--danger); text-align:center; padding:12px;">${e.message}</td></tr>`;
    }
}
function toggleCheckAllPo(chk) {
    document.querySelectorAll('.chk-po-item').forEach(c => c.checked = chk);
}
function applySelectedPo() {
    const checked = document.querySelectorAll('.chk-po-item:checked');
    if (checked.length === 0) {
        Swal.fire('Pilih Item', 'Silakan centang minimal 1 item PO.', 'warning');
        return;
    }
    const container = document.getElementById('itemsContainer');
    const firstRow = container.querySelector('.item-row');
    if (firstRow && !firstRow.querySelector('.it-kode').value.trim() && !firstRow.querySelector('.it-uraian').value.trim()) {
        firstRow.remove();
    }

    let firstItem = null;
    checked.forEach(c => {
        const idx = parseInt(c.value, 10);
        const d = cachedPoData[idx];
        if (d) {
            if (!firstItem) firstItem = d;
            addItemRow({
                kode_barang: d.kode_barang,
                uraian: d.uraian,
                qty: parseFloat(d.qty_sisa) || 1,
                sat: d.sat,
                price: parseFloat(d.price) || 0,
                disc: parseFloat(d.disc) || 0,
                po_no: d.po_no,
                id_po: d.id_po_dtl
            });
        }
    });

    if (firstItem) {
        const vEl = document.getElementById('piVendor');
        if (!vEl.value && firstItem.vendor) {
            vEl.value = firstItem.vendor;
            vEl.dispatchEvent(new Event('change', { bubbles: true }));
            if (vEl._antSearchableInstance && vEl._antSearchableInstance.input) {
                vEl._antSearchableInstance.input.value = `${firstItem.vendor} - ${firstItem.vendor_nama}`;
            }
        }
        if (!document.getElementById('piPoNo').value && firstItem.po_no) {
            document.getElementById('piPoNo').value = firstItem.po_no;
        }
    }

    closeLookupPo();
    calcTotals();
}

// -------------------------------------------------------------------------
// MODAL: RIWAYAT PEMBAYARAN AP
// -------------------------------------------------------------------------
async function openModalApHistory() {
    const id = document.getElementById('piId').value;
    const m = document.getElementById('modalApHistory');
    m.classList.add('active');
    const tbody = document.getElementById('apHistoryBody');
    if (!id) {
        tbody.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center; padding:12px;">Simpan faktur terlebih dahulu untuk melihat riwayat pembayaran.</td></tr>';
        return;
    }
    tbody.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center; padding:12px;">Memuat riwayat bayar AP...</td></tr>';
    try {
        const res = await fetch(`purchase_invoice.php?action=get&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        const terbayar = parseFloat(json.header?.terbayar) || 0;
        if (terbayar <= 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted" style="text-align:center; padding:12px;">Belum ada pembayaran dicatat pada faktur ini.</td></tr>';
        } else {
            tbody.innerHTML = `
                <tr>
                    <td><b>VCH-${json.header.pi_no}</b></td>
                    <td>${json.header.pi_dt}</td>
                    <td>Kas / Bank Pembayaran Hutang</td>
                    <td style="text-align:right; font-weight:700;">${formatNum(terbayar)}</td>
                    <td>Pelunasan Hutang Dagang</td>
                </tr>
            `;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" style="color:var(--danger); text-align:center; padding:12px;">${e.message}</td></tr>`;
    }
}
function closeModalApHistory() {
    document.getElementById('modalApHistory').classList.remove('active');
}

// -------------------------------------------------------------------------
// EXCEL MENU & IMPORT / EXPORT (SHEETJS)
// -------------------------------------------------------------------------
function toggleExcelMenu(e) {
    e.stopPropagation();
    const d = document.getElementById('excelMenuDropdown');
    d.style.display = (d.style.display === 'none') ? 'block' : 'none';
}
function hideExcelMenu() {
    document.getElementById('excelMenuDropdown').style.display = 'none';
}
document.addEventListener('click', () => hideExcelMenu());

function openModalImportExcel() {
    if (guardEdit()) return;
    document.getElementById('modalImportExcel').classList.add('active');
}
function closeModalImportExcel() {
    document.getElementById('modalImportExcel').classList.remove('active');
}

function handleExcelFileSelect(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(evt) {
        try {
            const data = new Uint8Array(evt.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const rawRows = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
            if (rawRows.length <= 1) throw new Error('File Excel tidak memuat baris data.');

            importedExcelRows = [];
            for (let i = 1; i < rawRows.length; i++) {
                const r = rawRows[i];
                if (!r || r.length === 0) continue;
                const kd = String(r[0] || '').trim();
                const ur = String(r[1] || '').trim();
                const qty = parseFloat(r[2]) || 1;
                const sat = String(r[3] || 'PCE').trim();
                const price = parseFloat(r[4]) || 0;
                const disc = parseFloat(r[5]) || 0;
                const rino = String(r[6] || '').trim();
                const pono = String(r[7] || '').trim();
                if (kd || ur) {
                    importedExcelRows.push({ kode_barang: kd, uraian: ur, qty, sat, price, disc, ri_no: rino, po_no: pono });
                }
            }

            const tbody = document.getElementById('tbodyImportPreview');
            let html = '';
            importedExcelRows.forEach((r, idx) => {
                html += `
                    <tr>
                        <td style="text-align:center;">${idx + 1}</td>
                        <td><b>${escapeHtml(r.kode_barang)}</b></td>
                        <td>${escapeHtml(r.uraian)}</td>
                        <td style="text-align:right;">${r.qty}</td>
                        <td style="text-align:center;">${escapeHtml(r.sat)}</td>
                        <td style="text-align:right;">${numberFormat(r.price)}</td>
                        <td style="text-align:right;">${r.disc}</td>
                        <td>${escapeHtml(r.ri_no)}</td>
                        <td>${escapeHtml(r.po_no)}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
            document.getElementById('importStats').textContent = `${importedExcelRows.length} baris siap dimasukkan`;
            document.getElementById('btnApplyImport').disabled = false;
        } catch (err) {
            Swal.fire('Gagal Membaca Excel', err.message, 'error');
        }
    };
    reader.readAsArrayBuffer(file);
}

function applyImportedExcelData() {
    if (importedExcelRows.length === 0) return;
    const mode = document.querySelector('input[name="importMode"]:checked')?.value || 'append';
    if (mode === 'replace') {
        document.getElementById('itemsContainer').innerHTML = '';
    } else {
        const first = document.querySelector('#itemsContainer .item-row');
        if (first && !first.querySelector('.it-kode').value.trim() && !first.querySelector('.it-uraian').value.trim()) {
            first.remove();
        }
    }

    importedExcelRows.forEach(r => addItemRow(r));
    closeModalImportExcel();
    calcTotals();
    Swal.fire('Sukses', `${importedExcelRows.length} baris barang berhasil diimport.`, 'success');
}

function downloadPiImportTemplate() {
    const ws_data = [
        ['Kode Barang', 'Uraian Barang', 'Qty', 'Satuan', 'Harga Satuan', 'Diskon (%)', 'No. RI', 'No. PO'],
        ['MAT-001', 'BAHAN BAKU SAMPEL', 100, 'KGM', 25000, 0, 'RI-2610-001', 'PO-2610-001']
    ];
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(ws_data);
    XLSX.utils.book_append_sheet(wb, ws, 'Template');
    XLSX.writeFile(wb, 'Template_Import_Purchase_Invoice.xlsx');
}

function exportGridToExcel() {
    const items = collectItems();
    if (items.length === 0) {
        Swal.fire('Informasi', 'Tidak ada data rincian barang untuk diexport.', 'info');
        return;
    }
    const rows = items.map((it, idx) => ({
        'No': idx + 1,
        'Kode Barang': it.kode_barang,
        'Uraian Barang': it.uraian,
        'Qty': it.qty,
        'Satuan': it.sat,
        'Harga Satuan': it.price,
        'Diskon (%)': it.disc,
        'Total Nilai': it.amount,
        'No. RI': it.ri_no,
        'No. PO': it.po_no,
        'Catatan': it.note
    }));
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.json_to_sheet(rows);
    XLSX.utils.book_append_sheet(wb, ws, 'Rincian PI');
    XLSX.writeFile(wb, `Rincian_PI_${document.getElementById('piNo').value || 'Draft'}.xlsx`);
}

// -------------------------------------------------------------------------
// TAB 2: BROWSE DATA (AJAX)
// -------------------------------------------------------------------------
async function loadPiList(page = 1) {
    currentBrowsePage = page;
    const tglAwal  = document.getElementById('brwTglAwal').value;
    const tglAkhir = document.getElementById('brwTglAkhir').value;
    const status   = document.getElementById('brwStatus').value;
    const bc       = document.getElementById('brwBc').value;
    const vendor   = document.getElementById('brwVendor').value;
    const search   = document.getElementById('brwSearch').value;
    const limit    = document.getElementById('brwLimit').value;

    const tbody = document.getElementById('piTableBody');
    tbody.innerHTML = '<tr><td colspan="17" class="muted" style="text-align:center; padding:18px;">Memuat daftar faktur pembelian...</td></tr>';

    try {
        const url = `purchase_invoice.php?action=list&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}&status_filter=${encodeURIComponent(status)}&bc_filter=${encodeURIComponent(bc)}&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(search)}&page=${page}&limit=${limit}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        const res = await fetch(url);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        renderBrowseTable(json.data || [], json.total || 0, page, parseInt(limit, 10));
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="17" style="color:var(--danger); text-align:center; padding:18px;">${e.message}</td></tr>`;
    }
}

function renderBrowseTable(rows, total, page, limit) {
    const tbody = document.getElementById('piTableBody');
    if (rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="17" class="muted" style="text-align:center; padding:18px;">Tidak ada dokumen faktur pembelian yang sesuai filter.</td></tr>';
        document.getElementById('pageInfo').textContent = '0 data';
        document.getElementById('pageBtns').innerHTML = '';
        return;
    }

    const startNo = (page - 1) * limit + 1;
    let html = '';
    rows.forEach((r, i) => {
        let badgeSt = '<span class="ant-badge ant-badge-belum">Belum Lunas</span>';
        if (r.status === '2' || (parseFloat(r.outstand) <= 0 && parseFloat(r.total_order) > 0)) {
            badgeSt = '<span class="ant-badge ant-badge-lunas">Lunas</span>';
        } else if (r.status === '9') {
            badgeSt = '<span class="ant-badge" style="background:#f3f4f6; color:#6b7280;">Batal</span>';
        }

        const lockIcon = r.is_locked ? '<i class="fa-solid fa-lock" style="color:#cf1322;" title="Terkunci IT Inv"></i> ' : '';

        html += `
            <tr ondblclick="loadPiForEdit('${escapeHtml(r.id)}')" style="cursor:pointer;" title="Dobel-klik untuk mengedit dokumen">
                <td style="text-align:center;" class="muted">${startNo + i}</td>
                <td><b style="color:var(--primary);">${lockIcon}${escapeHtml(r.pi_no)}</b></td>
                <td>${r.pi_dt_fmt}</td>
                <td>${r.jatuhtempo_fmt}</td>
                <td style="text-align:center;">${badgeSt}</td>
                <td>${escapeHtml(r.vendor_nama)}</td>
                <td>${escapeHtml(r.ri_no || '-')}</td>
                <td>${escapeHtml(r.po_no || '-')}</td>
                <td style="text-align:center;"><small><b>${escapeHtml(r.bc || '-')}</b></small></td>
                <td>${escapeHtml(r.no_bc || '-')}</td>
                <td><small>${escapeHtml(r.no_aju || '-')}</small></td>
                <td style="text-align:center; font-weight:700;">${r.item_count}</td>
                <td style="text-align:center;"><small>${escapeHtml(r.kurs || 'IDR')}</small></td>
                <td style="text-align:right; font-weight:700;">${r.total_order_fmt}</td>
                <td style="text-align:right;">${r.terbayar_fmt}</td>
                <td style="text-align:right; font-weight:700; color:var(--danger);">${r.outstand_fmt}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="event.stopPropagation(); loadPiForEdit('${escapeHtml(r.id)}')" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="event.stopPropagation(); printPiDirect('${escapeHtml(r.id)}')" title="Cetak"><i class="fa-solid fa-print"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="event.stopPropagation(); window.open('inv_qr_label.php?ref_type=PI&ref_no=' + encodeURIComponent(r.pi_no) + '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), '_blank')" title="Cetak Label QR Dokumen BC (Traceability AJU)"><i class="fa-solid fa-qrcode" style="color:#059669;"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="event.stopPropagation(); deleteFromBrowse('${escapeHtml(r.id)}', '${escapeHtml(r.pi_no)}')" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;

    const totalPages = Math.ceil(total / limit) || 1;
    const endNo = Math.min(total, page * limit);
    document.getElementById('pageInfo').textContent = `Menampilkan ${startNo} - ${endNo} dari ${total} dokumen faktur`;

    let pageHtml = '';
    if (totalPages > 1) {
        pageHtml += `<button type="button" class="ant-btn ant-btn-sm" ${page <= 1 ? 'disabled style="opacity:0.5;"' : ''} onclick="loadPiList(${page - 1})"><i class="fa-solid fa-angle-left"></i> Prev</button>`;
        const maxBtn = 5;
        let startP = Math.max(1, page - 2);
        let endP = Math.min(totalPages, startP + maxBtn - 1);
        if (endP - startP < maxBtn - 1) startP = Math.max(1, endP - maxBtn + 1);

        for (let p = startP; p <= endP; p++) {
            pageHtml += `<button type="button" class="ant-btn ant-btn-sm ${p === page ? 'ant-btn-primary' : ''}" onclick="loadPiList(${p})">${p}</button>`;
        }
        pageHtml += `<button type="button" class="ant-btn ant-btn-sm" ${page >= totalPages ? 'disabled style="opacity:0.5;"' : ''} onclick="loadPiList(${page + 1})">Next <i class="fa-solid fa-angle-right"></i></button>`;
    }
    document.getElementById('pageBtns').innerHTML = pageHtml;
}

function printPiDirect(id) {
    const url = `purchase_invoice.php?action=print&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
    window.open(url, '_blank', 'width=950,height=800,scrollbars=yes');
}

function filterBrowseCurMonth() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    document.getElementById('brwTglAwal').value = `${y}-${m}-01`;
    document.getElementById('brwTglAkhir').value = new Date(y, now.getMonth() + 1, 0).toISOString().slice(0, 10);
    loadPiList(1);
}

function resetBrowseFilter() {
    document.getElementById('brwSearch').value = '';
    const vEl = document.getElementById('brwVendor');
    vEl.value = '';
    vEl.dispatchEvent(new Event('change', { bubbles: true }));
    if (vEl._antSearchableInstance && vEl._antSearchableInstance.input) {
        vEl._antSearchableInstance.input.value = '';
    }
    document.getElementById('brwBc').value = '';
    document.getElementById('brwStatus').value = 'ALL';
    document.getElementById('brwTglAwal').value = '';
    document.getElementById('brwTglAkhir').value = '';
    loadPiList(1);
}

async function deleteFromBrowse(id, piNo) {
    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus Faktur Pembelian "${piNo}"? Realisasi pada Penerimaan Barang (RI) akan dikembalikan.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Ya, Hapus Sekarang'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('purchase_invoice.php?action=delete&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire('Terhapus', `Faktur Pembelian "${piNo}" telah dihapus.`, 'success');
        loadPiList(currentBrowsePage);
    } catch (e) {
        Swal.fire('Gagal Menghapus', e.message, 'error');
    }
}

function exportBrowseExcel() {
    const tbl = document.getElementById('piTable');
    if (!tbl) return;
    const clone = tbl.cloneNode(true);
    clone.querySelectorAll('tr').forEach(tr => {
        if (tr.lastElementChild) tr.removeChild(tr.lastElementChild);
    });
    const wb = XLSX.utils.table_to_book(clone, { sheet: 'Faktur Pembelian' });
    XLSX.writeFile(wb, `Daftar_Faktur_Pembelian_PI_${new Date().toISOString().slice(0, 10)}.xlsx`);
}

function exportBrowseCsv() {
    const tbl = document.getElementById('piTable');
    if (!tbl) return;
    const clone = tbl.cloneNode(true);
    clone.querySelectorAll('tr').forEach(tr => {
        if (tr.lastElementChild) tr.removeChild(tr.lastElementChild);
    });
    const wb = XLSX.utils.table_to_book(clone, { sheet: 'Faktur Pembelian' });
    XLSX.writeFile(wb, `Daftar_Faktur_Pembelian_PI_${new Date().toISOString().slice(0, 10)}.csv`);
}

// -------------------------------------------------------------------------
// TAB 3: TRACE PENERIMAAN & DOKUMEN BC
// -------------------------------------------------------------------------
async function loadTrace() {
    const id = document.getElementById('piId').value;
    if (!id) return;
    try {
        const res = await fetch(`purchase_invoice.php?action=trace&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        document.getElementById('tracePiTitle').innerHTML = `Jejak Penelusuran Faktur: <b style="color:var(--primary);">${escapeHtml(json.header.pi_no)}</b> &bull; Pemasok: ${escapeHtml(json.header.vendor_nama)} &bull; Tgl: ${json.header.pi_dt}`;

        const rBody = document.getElementById('traceRiBody');
        if (!json.ri_headers || json.ri_headers.length === 0) {
            rBody.innerHTML = '<tr><td colspan="8" class="muted" style="text-align:center; padding:10px;">Tidak ada dokumen RI terkait.</td></tr>';
        } else {
            let rHtml = '';
            json.ri_headers.forEach(r => {
                rHtml += `
                    <tr>
                        <td><b>${escapeHtml(r.ri_no)}</b></td>
                        <td>${r.ri_dt}</td>
                        <td>${escapeHtml(r.no_sj || '-')}</td>
                        <td><span style="font-weight:700; color:var(--primary);">${escapeHtml(r.bc || '-')}</span></td>
                        <td>${escapeHtml(r.no_bc || '-')}</td>
                        <td>${r.tgl_bc || '-'}</td>
                        <td><code>${escapeHtml(r.no_aju || '-')}</code></td>
                        <td>${escapeHtml(r.po_no || '-')}</td>
                    </tr>
                `;
            });
            rBody.innerHTML = rHtml;
        }

        const pBody = document.getElementById('tracePoBody');
        if (!json.po_headers || json.po_headers.length === 0) {
            pBody.innerHTML = '<tr><td colspan="7" class="muted" style="text-align:center; padding:10px;">Tidak ada dokumen PO terkait.</td></tr>';
        } else {
            let pHtml = '';
            json.po_headers.forEach(p => {
                pHtml += `
                    <tr>
                        <td><b>${escapeHtml(p.po_no)}</b></td>
                        <td>${p.po_dt}</td>
                        <td>${escapeHtml(p.vendor)}</td>
                        <td>${escapeHtml(p.term)}</td>
                        <td>${escapeHtml(p.kurs)}</td>
                        <td>${numberFormat(p.rate)}</td>
                        <td style="text-align:right; font-weight:700;">${numberFormat(p.total_order)}</td>
                    </tr>
                `;
            });
            pBody.innerHTML = pHtml;
        }

        const lBody = document.getElementById('traceLinesBody');
        let lHtml = '';
        json.lines.forEach((l, i) => {
            lHtml += `
                <tr>
                    <td style="text-align:center;">${i + 1}</td>
                    <td><b>${escapeHtml(l.kode_barang)}</b></td>
                    <td>${escapeHtml(l.uraian)}</td>
                    <td style="text-align:right;">${numberFormat(l.qty)}</td>
                    <td>${escapeHtml(l.sat)}</td>
                    <td style="text-align:right;">${numberFormat(l.price)}</td>
                    <td style="text-align:right; font-weight:700;">${numberFormat(l.amount)}</td>
                    <td>${escapeHtml(l.ri_no || '-')}</td>
                    <td>${escapeHtml(l.po_no || '-')}</td>
                    <td>${escapeHtml(l.pos_tarif || '-')}</td>
                </tr>
            `;
        });
        lBody.innerHTML = lHtml;
    } catch (e) {
        console.warn('Trace Error:', e);
    }
}

// -------------------------------------------------------------------------
// CONTEXT MENU GRID
// -------------------------------------------------------------------------
let currentCtxRow = null;

function initGridContextMenu() {
    const table = document.getElementById('tableItems');
    const menu = document.getElementById('gridContextMenu');
    if (!table || !menu) return;

    table.addEventListener('contextmenu', (e) => {
        if (guardEdit()) return;
        const row = e.target.closest('#itemsContainer tr, #tableItems tbody tr');
        if (!row) return;
        e.preventDefault();
        e.stopPropagation();

        currentCtxRow = row;
        table.querySelectorAll('tr').forEach(r => r.classList.remove('context-active'));
        row.classList.add('context-active');

        menu.style.display = 'block';
        const menuWidth = 240;
        const menuHeight = 300;
        let left = e.clientX;
        let top = e.clientY;
        if (left + menuWidth > window.innerWidth) left = window.innerWidth - menuWidth - 10;
        if (top + menuHeight > window.innerHeight) top = window.innerHeight - menuHeight - 10;
        menu.style.left = Math.max(10, left) + 'px';
        menu.style.top = Math.max(10, top) + 'px';
    });

    document.addEventListener('click', () => hideContextMenu());
}

function openGridContextMenu(e) {
    if (guardEdit()) return;
    e.preventDefault();
    currentCtxRow = e.target.closest('tr');
    const menu = document.getElementById('gridContextMenu');
    menu.style.display = 'block';
    menu.style.left = `${Math.min(e.clientX, window.innerWidth - 240)}px`;
    menu.style.top = `${Math.min(e.clientY, window.innerHeight - 300)}px`;
}
function hideContextMenu() {
    document.getElementById('gridContextMenu').style.display = 'none';
}
document.addEventListener('click', () => hideContextMenu());

function ctxTambahBaru() {
    hideContextMenu();
    tambahBaris();
}
function ctxHapusBaris() {
    hideContextMenu();
    if (currentCtxRow) {
        const btn = currentCtxRow.querySelector('.ant-btn-danger');
        if (btn) removeRow(btn);
    }
}
function ctxPilihBarang() {
    hideContextMenu();
    const input = currentCtxRow ? currentCtxRow.querySelector('.it-kode') : null;
    openMaterialPicker(input);
}

// -------------------------------------------------------------------------
// PANDUAN SOP DIALOG
// -------------------------------------------------------------------------
function openModuleGuide() {
    document.getElementById('modalSopGuide').classList.add('active');
}
function closeModuleGuide() {
    document.getElementById('modalSopGuide').classList.remove('active');
}

// -------------------------------------------------------------------------
// UTILITIES: TERBILANG & FORMAT NUM
// -------------------------------------------------------------------------
function formatNum(val) {
    const n = parseFloat(val) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function numberFormat(val) {
    return formatNum(val);
}
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function terbilangJs(nilai) {
    const angka = Math.floor(Math.abs(nilai));
    if (angka === 0) return 'Nol Rupiah';

    const huruf = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
    function sebut(x) {
        if (x < 12) return ' ' + huruf[x];
        if (x < 20) return sebut(x - 10) + ' Belas';
        if (x < 100) return sebut(Math.floor(x / 10)) + ' Puluh' + sebut(x % 10);
        if (x < 200) return ' Seratus' + sebut(x - 100);
        if (x < 1000) return sebut(Math.floor(x / 100)) + ' Ratus' + sebut(x % 100);
        if (x < 2000) return ' Seribu' + sebut(x - 1000);
        if (x < 1000000) return sebut(Math.floor(x / 1000)) + ' Ribu' + sebut(x % 1000);
        if (x < 1000000000) return sebut(Math.floor(x / 1000000)) + ' Juta' + sebut(x % 1000000);
        if (x < 1000000000000) return sebut(Math.floor(x / 1000000000)) + ' Miliar' + sebut(x % 1000000000);
        return sebut(Math.floor(x / 1000000000000)) + ' Triliun' + sebut(x % 1000000000000);
    }
    const kurs = document.getElementById('piKurs')?.value || 'IDR';
    const mataUang = (kurs === 'USD') ? 'Dollar' : (kurs === 'EUR' ? 'Euro' : (kurs === 'JPY' ? 'Yen' : 'Rupiah'));
    return sebut(angka).trim() + ' ' + mataUang;
}
</script>
</body>
</html>

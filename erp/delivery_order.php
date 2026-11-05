<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Surat Jalan / Delivery Order (DO)
 * ============================================================================
 * Porting Lengkap dari PowerBuilder TPB-ERP:
 * - w_do.srw (Window Delivery Order & Alur Pengiriman Barang)
 * - bc_do_hdr.srd / bc_do_hdr2.srd (Header DO & Integrasi Dokumen Pabean CEISA)
 * - d_do_dtl.srd (Rincian Item Barang yang Dikirim, CTN, Netto/Gross)
 * - d_do_browse.srd / d_do_show_hdr.srd / d_do_show_dtl.srd (Browse DO & Filter)
 * - ddw_so_belum_do.srd / ddw_so_open.srd / d_so_do.srd (Tarik Item dari Sales Order)
 * - d_do_si.srd / d_si_hist.srd (Integrasi Realisasi Penagihan Faktur Penjualan / SI)
 * - f_update_do.srf & f_update_so2.srf (Sinkronisasi Realisasi Kirim SO & Tagih SI)
 * - d_do_prt.srd / d_do_prt_snunggul_2026.srd (Cetak Surat Jalan Resmi A4)
 *
 * Mengadopsi 100% Ant Design Compact Desktop 9.5px Standard Seragam ERP Hub
 * Standar Warna Badge & Accent: Sky Blue / Marine (#0284c7 / #0369a1)
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
    $cmpQ = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($cmpQ && $cRow = $cmpQ->fetch_assoc()) {
        if (!empty($cRow['cmp_name'])) $activeCmpName = $cRow['cmp_name'];
        if (!empty($cRow['logo'])) $companyLogo = $cRow['logo'];
        if (!empty($cRow['alamat'])) $companyAlamat = $cRow['alamat'];
        if (!empty($cRow['npwp'])) $companyNpwp = $cRow['npwp'];
        if (!empty($cRow['nib'])) $companyNib = $cRow['nib'];
        if (!empty($cRow['telp'])) $companyTelp = $cRow['telp'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'TPB ERP Enterprise';

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_do_hdr dan bc_do_dtl Siap Multi-Tenant
// -------------------------------------------------------------------------
function ensureDoTables($koneksi) {
    try {
        // 1. Table bc_do_hdr
        $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_do_hdr'");
        if (!$checkHdr || $checkHdr->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_do_hdr` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `do_no` varchar(50) NOT NULL DEFAULT '',
                `do_dt` date DEFAULT NULL,
                `ship_dt` date DEFAULT NULL,
                `po_no` varchar(50) DEFAULT '',
                `so_no` varchar(50) DEFAULT '',
                `ship` varchar(50) DEFAULT '',
                `vendor` varchar(50) NOT NULL DEFAULT '',
                `shipto` text DEFAULT NULL,
                `attn` varchar(200) DEFAULT '',
                `no_polisi` varchar(50) DEFAULT '',
                `driver_name` varchar(100) DEFAULT '',
                `bc_doc_type` varchar(20) DEFAULT '',
                `nomor_aju` varchar(50) DEFAULT '',
                `nomor_daftar` varchar(50) DEFAULT '',
                `tanggal_daftar` date DEFAULT NULL,
                `note` text DEFAULT NULL,
                `set_by` varchar(50) DEFAULT '',
                `status` char(1) DEFAULT '0',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_do` (`cmp_id`, `do_no`),
                KEY `idx_buyer` (`vendor`),
                KEY `idx_do_dt` (`do_dt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $cols = [];
            $res = $koneksi->query("SHOW COLUMNS FROM bc_do_hdr");
            if ($res) while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' FIRST");
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD INDEX `idx_cmp_id` (`cmp_id`)");
            }
            if (!isset($cols['so_no'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `so_no` varchar(50) DEFAULT '' AFTER `po_no`");
            }
            if (!isset($cols['no_polisi'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `no_polisi` varchar(50) DEFAULT '' AFTER `attn`");
            }
            if (!isset($cols['driver_name'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `driver_name` varchar(100) DEFAULT '' AFTER `no_polisi`");
            }
            if (!isset($cols['bc_doc_type'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `bc_doc_type` varchar(20) DEFAULT '' AFTER `driver_name`");
            }
            if (!isset($cols['nomor_aju'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `nomor_aju` varchar(50) DEFAULT '' AFTER `bc_doc_type`");
            }
            if (!isset($cols['nomor_daftar'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `nomor_daftar` varchar(50) DEFAULT '' AFTER `nomor_aju`");
            }
            if (!isset($cols['tanggal_daftar'])) {
                @$koneksi->query("ALTER TABLE bc_do_hdr ADD COLUMN `tanggal_daftar` date DEFAULT NULL AFTER `nomor_daftar`");
            }
        }

        // 2. Table bc_do_dtl
        $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_do_dtl'");
        if (!$checkDtl || $checkDtl->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_do_dtl` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `id_hdr` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `do_no` varchar(50) NOT NULL DEFAULT '',
                `seq` int(11) NOT NULL DEFAULT '1',
                `id_so` varchar(40) DEFAULT '',
                `so_no` varchar(50) DEFAULT '',
                `po_no` varchar(50) DEFAULT '',
                `kode_barang` varchar(50) NOT NULL DEFAULT '',
                `uraian` varchar(255) NOT NULL DEFAULT '',
                `qty` decimal(18,4) DEFAULT '0.0000',
                `sat` varchar(20) DEFAULT '',
                `netto` decimal(18,4) DEFAULT '0.0000',
                `gross` decimal(18,4) DEFAULT '0.0000',
                `volume` decimal(18,4) DEFAULT '0.0000',
                `ctn` decimal(18,2) DEFAULT '0.00',
                `color_cd` varchar(80) DEFAULT '',
                `size_cd` varchar(80) DEFAULT '',
                `note` varchar(350) DEFAULT '',
                `status` char(1) DEFAULT '0',
                `terima` decimal(18,4) DEFAULT '0.0000',
                PRIMARY KEY (`id`),
                KEY `idx_id_hdr` (`id_hdr`),
                KEY `idx_cmp_do` (`cmp_id`, `do_no`),
                KEY `idx_kode_barang` (`kode_barang`),
                KEY `idx_id_so` (`id_so`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $cols = [];
            $res = $koneksi->query("SHOW COLUMNS FROM bc_do_dtl");
            if ($res) while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_do_dtl ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' FIRST");
                @$koneksi->query("ALTER TABLE bc_do_dtl ADD INDEX `idx_cmp_id` (`cmp_id`)");
            }
            if (!isset($cols['terima'])) {
                @$koneksi->query("ALTER TABLE bc_do_dtl ADD COLUMN `terima` decimal(18,4) DEFAULT '0.0000' AFTER `status`");
            }
        }
    } catch (\Throwable $e) {}
}
ensureDoTables($koneksi);

// -------------------------------------------------------------------------
// ROUTER AKSI BACKEND (Mendukung alias nama PB & standar baru)
// -------------------------------------------------------------------------
$action = trim($_REQUEST['action'] ?? '');
if ($action === 'get_do_list') $action = 'list';
if ($action === 'get_do_detail' || $action === 'get_do_dtl' || $action === 'get') $action = 'get_do';
if ($action === 'generate_do_no') $action = 'generate_no';
if ($action === 'save_do') $action = 'save';
if ($action === 'delete_do') $action = 'delete';

// -------------------------------------------------------------------------
// 1. DAFTAR DELIVERY ORDER (BROWSE DATA GRID & 4 KPI METRICS)
// -------------------------------------------------------------------------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $search    = trim($_GET['search'] ?? '');
        $status    = trim($_GET['status'] ?? '');
        $startDate = trim($_GET['start_date'] ?? '');
        $endDate   = trim($_GET['end_date'] ?? '');
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $limit     = min(150, max(10, (int)($_GET['limit'] ?? 25)));
        $offset    = ($page - 1) * $limit;

        $where = ["(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)"];

        if (!empty($search)) {
            $escSearch = $koneksi->real_escape_string($search);
            $where[] = "(h.do_no LIKE '%{$escSearch}%' 
                         OR h.so_no LIKE '%{$escSearch}%' 
                         OR h.po_no LIKE '%{$escSearch}%' 
                         OR h.vendor LIKE '%{$escSearch}%' 
                         OR b.NAMA LIKE '%{$escSearch}%' 
                         OR h.no_polisi LIKE '%{$escSearch}%'
                         OR h.driver_name LIKE '%{$escSearch}%'
                         OR h.nomor_daftar LIKE '%{$escSearch}%')";
        }

        if ($status !== '' && $status !== 'all') {
            $escStatus = $koneksi->real_escape_string($status);
            $where[] = "h.status = '{$escStatus}'";
        }

        if (!empty($startDate)) {
            $escStart = $koneksi->real_escape_string($startDate);
            $where[] = "h.do_dt >= '{$escStart}'";
        }
        if (!empty($endDate)) {
            $escEnd = $koneksi->real_escape_string($endDate);
            $where[] = "h.do_dt <= '{$escEnd}'";
        }

        $whereSql = implode(' AND ', $where);

        // Hitung Total Data
        $countRes = $koneksi->query("SELECT COUNT(1) AS total FROM bc_do_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL)) WHERE {$whereSql}");
        $totalRows = ($countRes && $row = $countRes->fetch_assoc()) ? (int)$row['total'] : 0;

        // Ambil Data Baris DO
        $sql = "SELECT h.id, h.do_no, h.do_dt, h.ship_dt, h.po_no, h.so_no, h.ship, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS buyer_nama,
                       h.no_polisi, h.driver_name, h.bc_doc_type, h.nomor_aju, h.nomor_daftar, h.tanggal_daftar,
                       h.status, h.set_by, h.note,
                       (SELECT COUNT(1) FROM bc_do_dtl d WHERE d.id_hdr = h.id) AS item_count,
                       (SELECT COALESCE(SUM(d.qty), 0) FROM bc_do_dtl d WHERE d.id_hdr = h.id) AS total_qty,
                       (SELECT COALESCE(SUM(d.ctn), 0) FROM bc_do_dtl d WHERE d.id_hdr = h.id) AS total_ctn,
                       (SELECT COALESCE(SUM(d.netto), 0) FROM bc_do_dtl d WHERE d.id_hdr = h.id) AS total_netto
                FROM bc_do_hdr h
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                WHERE {$whereSql}
                ORDER BY h.do_dt DESC, h.do_no DESC
                LIMIT {$offset}, {$limit}";

        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $data[] = $r;
            }
        }

        // Metrik 4 KPI Strip Cepat
        $kpiRes = $koneksi->query("SELECT 
            COUNT(1) as total_do,
            SUM(CASE WHEN status = '0' THEN 1 ELSE 0 END) as open_do,
            SUM(CASE WHEN status = '1' THEN 1 ELSE 0 END) as partial_do,
            SUM(CASE WHEN status = '2' THEN 1 ELSE 0 END) as closed_do
            FROM bc_do_hdr 
            WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        
        $kpi = ($kpiRes && $kr = $kpiRes->fetch_assoc()) ? [
            'total_do'    => (int)$kr['total_do'],
            'open_do'     => (int)$kr['open_do'],
            'partial_do'  => (int)$kr['partial_do'],
            'closed_do'   => (int)$kr['closed_do'],
            'total_qty'   => 0
        ] : ['total_do' => 0, 'open_do' => 0, 'partial_do' => 0, 'closed_do' => 0, 'total_qty' => 0];

        // Total Qty Kirim Bulan Ini
        $currentMonth = date('Y-m');
        $qMth = $koneksi->query("SELECT COALESCE(SUM(d.qty), 0) as mth_qty
                                 FROM bc_do_dtl d
                                 JOIN bc_do_hdr h ON d.id_hdr = h.id
                                 WHERE (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)
                                   AND h.do_dt LIKE '{$currentMonth}%'");
        if ($qMth && $qm = $qMth->fetch_assoc()) {
            $kpi['total_qty'] = (float)$qm['mth_qty'];
        }

        echo json_encode([
            'status' => 'OK',
            'data'   => $data,
            'total'  => $totalRows,
            'page'   => $page,
            'limit'  => $limit,
            'kpi'    => $kpi
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 2. AMBIL SATU TRANSAKSI DO LENGKAP (HEADER + DETAIL)
// -------------------------------------------------------------------------
if ($action === 'get_do') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Surat Jalan (DO) tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hdrQ = $koneksi->query("SELECT h.*, 
                                        COALESCE(b.NAMA, h.vendor) AS buyer_nama,
                                        b.ALAMAT AS buyer_alamat,
                                        b.NPWP AS buyer_npwp,
                                        b.TELP AS buyer_telp
                                 FROM bc_do_hdr h
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                 WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)
                                 LIMIT 1");
        if (!$hdrQ || $hdrQ->num_rows === 0) throw new Exception('Dokumen Surat Jalan (DO) tidak ditemukan.');
        $hdr = $hdrQ->fetch_assoc();

        $dtlQ = $koneksi->query("SELECT d.*, 
                                        COALESCE(m.URAIAN, d.uraian) AS master_uraian,
                                        COALESCE(m.BERAT, 0) AS berat_satuan
                                 FROM bc_do_dtl d
                                 LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                                 WHERE d.id_hdr = '{$escId}'
                                 ORDER BY d.seq ASC");
        $items = [];
        if ($dtlQ) {
            while ($r = $dtlQ->fetch_assoc()) {
                $items[] = $r;
            }
        }

        echo json_encode([
            'status' => 'OK',
            'header' => $hdr,
            'items'  => $items
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 3. GENERATOR NOMOR SURAT JALAN / DO OTOMATIS (FORMAT: DO/YYYYMM/0001)
// -------------------------------------------------------------------------
if ($action === 'generate_no') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $prefix = "DO/" . date('Ym') . "/";
        $res = $koneksi->query("SELECT MAX(do_no) as max_no FROM bc_do_hdr 
                                WHERE do_no LIKE '{$prefix}%' 
                                  AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        $maxNo = ($res && $r = $res->fetch_assoc()) ? $r['max_no'] : null;

        if (!empty($maxNo)) {
            $lastNum = intval(substr($maxNo, strlen($prefix)));
            $nextNo = $prefix . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $nextNo = $prefix . "0001";
        }

        echo json_encode(['status' => 'OK', 'do_no' => $nextNo]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 4. DAFTAR SALES ORDER (SO) BELUM LUNAS KIRIM (MODAL TARIK SO - ddw_so_belum_do)
// -------------------------------------------------------------------------
if ($action === 'search_so_open') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor = trim($_GET['vendor'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $escVen = $koneksi->real_escape_string($vendor);
        $escS   = $koneksi->real_escape_string($search);

        $where = ["(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)",
                  "h.status IN ('0', '1')",
                  "(d.qty - COALESCE(d.terima, 0)) > 0"];

        if (!empty($escVen)) {
            $where[] = "h.vendor = '{$escVen}'";
        }
        if (!empty($escS)) {
            $where[] = "(h.so_no LIKE '%{$escS}%' OR h.po_no LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%')";
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT d.id AS id_so, d.id_hdr AS id_so_hdr, d.so_no, h.so_dt, h.po_no, h.po_dt, h.vendor,
                       COALESCE(b.NAMA, h.vendor) AS buyer_nama,
                       d.seq, d.kode_barang, d.uraian, d.qty AS qty_order,
                       COALESCE(d.terima, 0) AS qty_terkirim,
                       (d.qty - COALESCE(d.terima, 0)) AS sisa_kirim,
                       d.sat, d.price, COALESCE(m.BERAT, 0) AS berat,
                       d.color_cd, d.size_cd
                FROM bc_so_dtl d
                JOIN bc_so_hdr h ON d.id_hdr = h.id
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                WHERE {$whereSql}
                ORDER BY h.so_dt DESC, d.so_no DESC, d.seq ASC
                LIMIT 100";

        $res = $koneksi->query($sql);
        $list = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $list[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $list]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 5. KATALOG MASTER BARANG (5 KLASIFIKASI: FG, RM, WIP, CG, SCRAP)
// -------------------------------------------------------------------------
if ($action === 'search_materials') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $q    = trim($_GET['q'] ?? '');
        $klas = trim($_GET['klas'] ?? 'FG');
        $escQ = $koneksi->real_escape_string($q);

        $klasMap = [
            'FG' => ['Finished Goods', 'Barang Jadi', 'FG'],
            'RM' => ['Raw Materials', 'Bahan Baku', 'RM'],
            'WI' => ['Work In Progress', 'Barang Setengah Jadi', 'WIP', 'WI'],
            'CG' => ['Capital Goods', 'Mesin', 'Peralatan', 'CG'],
            'W'  => ['Waste', 'Scrap', 'Wastes', 'W']
        ];
        $selectedKlas = $klasMap[$klas] ?? ['Finished Goods', 'Barang Jadi', 'FG'];
        $inKlas = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $selectedKlas)) . "'";

        $where = ["(KLAS IN ({$inKlas}) OR KLAS IS NULL OR KLAS = '')",
                  "(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)"];

        if (!empty($escQ)) {
            $where[] = "(KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%' OR POS_TARIF LIKE '%{$escQ}%')";
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT KODE_BARANG AS kode_barang, 
                       URAIAN AS uraian, 
                       IFNULL(KODE_SATUAN, 'PCE') AS satuan, 
                       COALESCE(UNIT_PRICE, 0) AS harga_satuan, 
                       COALESCE(BERAT, 0) AS netto, 
                       IFNULL(KLAS, 'FG') AS klas,
                       IFNULL(POS_TARIF, '') AS hs_code
                FROM bc_material 
                WHERE {$whereSql} 
                ORDER BY KODE_BARANG ASC 
                LIMIT 80";

        $res = $koneksi->query($sql);
        $list = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['harga_fmt'] = number_format((float)$r['harga_satuan'], 2, '.', ',');
                $list[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $list]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 6. SIMPAN TRANSAKSI SURAT JALAN / DO (BARU & UPDATE)
// -------------------------------------------------------------------------
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!$input) $input = $_POST;

        $id         = trim($input['id'] ?? '');
        $isNew      = empty($id);
        if ($isNew) {
            $id = 'DO-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        $header  = $input['header'] ?? $input;
        $items   = $input['items'] ?? ($input['details'] ?? []);

        $doNo       = trim($header['do_no'] ?? '');
        $doDt       = !empty($header['do_dt']) ? $header['do_dt'] : date('Y-m-d');
        $shipDt     = !empty($header['ship_dt']) ? $header['ship_dt'] : $doDt;
        $vendor     = trim($header['vendor'] ?? '');
        $soNo       = trim($header['so_no'] ?? '');
        $poNo       = trim($header['po_no'] ?? '');
        $shipto     = trim($header['shipto'] ?? '');
        $attn       = trim($header['attn'] ?? '');
        $ship       = trim($header['ship'] ?? 'TRUCK');
        $noPolisi   = trim($header['no_polisi'] ?? '');
        $driverName = trim($header['driver_name'] ?? '');
        $bcDocType  = trim($header['bc_doc_type'] ?? '');
        $nomorAju   = trim($header['nomor_aju'] ?? '');
        $nomorDaftar= trim($header['nomor_daftar'] ?? '');
        $tanggalDaftar = !empty($header['tanggal_daftar']) ? $header['tanggal_daftar'] : null;
        $note       = trim($header['note'] ?? '');
        $status     = trim($header['status'] ?? '0');

        if (empty($doNo)) throw new Exception('Nomor Surat Jalan (DO) wajib diisi.');
        if (empty($vendor)) throw new Exception('Penerima / Buyer wajib dipilih.');
        if (empty($items) || !is_array($items)) throw new Exception('Rincian barang Surat Jalan minimal 1 baris.');

        // Simpan id_so lama jika update agar bisa di-sinkronkan ulang
        $affectedSoIds = [];
        if (!$isNew) {
            $escId = $koneksi->real_escape_string($id);
            $qOldSo = $koneksi->query("SELECT DISTINCT id_so FROM bc_do_dtl WHERE id_hdr = '{$escId}' AND id_so != ''");
            if ($qOldSo) while ($os = $qOldSo->fetch_assoc()) $affectedSoIds[] = $os['id_so'];
        }

        $koneksi->begin_transaction();

        if ($isNew) {
            $stmt = $koneksi->prepare("INSERT INTO bc_do_hdr (
                id, cmp_id, do_no, do_dt, ship_dt, po_no, so_no, ship,
                vendor, shipto, attn, no_polisi, driver_name, bc_doc_type,
                nomor_aju, nomor_daftar, tanggal_daftar, note, set_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssssssssssssss",
                $id, $sessionCmpId, $doNo, $doDt, $shipDt, $poNo, $soNo, $ship,
                $vendor, $shipto, $attn, $noPolisi, $driverName, $bcDocType,
                $nomorAju, $nomorDaftar, $tanggalDaftar, $note, $currentUser, $status
            );
            if (!$stmt->execute()) throw new Exception('Gagal menyimpan Header DO: ' . $stmt->error);
            $stmt->close();
        } else {
            $stmt = $koneksi->prepare("UPDATE bc_do_hdr SET
                do_no = ?, do_dt = ?, ship_dt = ?, po_no = ?, so_no = ?, ship = ?,
                vendor = ?, shipto = ?, attn = ?, no_polisi = ?, driver_name = ?, bc_doc_type = ?,
                nomor_aju = ?, nomor_daftar = ?, tanggal_daftar = ?, note = ?, set_by = ?, status = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
            $stmt->bind_param("ssssssssssssssssssss",
                $doNo, $doDt, $shipDt, $poNo, $soNo, $ship,
                $vendor, $shipto, $attn, $noPolisi, $driverName, $bcDocType,
                $nomorAju, $nomorDaftar, $tanggalDaftar, $note, $currentUser, $status,
                $id, $sessionCmpId
            );
            if (!$stmt->execute()) throw new Exception('Gagal memperbarui Header DO: ' . $stmt->error);
            $stmt->close();

            $koneksi->query("DELETE FROM bc_do_dtl WHERE id_hdr = '{$escId}'");
        }

        // Simpan Detail Items
        $dStmt = $koneksi->prepare("INSERT INTO bc_do_dtl (
            id, id_hdr, cmp_id, do_no, seq, id_so, so_no, po_no,
            kode_barang, uraian, qty, sat, netto, gross, volume, ctn,
            color_cd, size_cd, note, status, terima
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        $poList = [];
        $soList = [];

        foreach ($items as $it) {
            $dtlId = md5($id . '_' . $seq . '_' . microtime(true));
            $kdBrg = trim($it['kode_barang'] ?? '');
            $uraian= trim($it['uraian'] ?? '');
            if (empty($kdBrg) && empty($uraian)) continue;

            $idSo    = trim($it['id_so'] ?? '');
            $itSoNo  = trim($it['so_no'] ?? $soNo);
            $itPoNo  = trim($it['po_no'] ?? $poNo);

            // Auto-lookup matching id_so dari bc_so_dtl jika belum ada
            if (empty($idSo) && !empty($itSoNo) && !empty($kdBrg)) {
                $escItSo = $koneksi->real_escape_string($itSoNo);
                $escKd   = $koneksi->real_escape_string($kdBrg);
                $qSoItem = $koneksi->query("SELECT d.id FROM bc_so_dtl d
                                            JOIN bc_so_hdr h ON d.id_hdr = h.id
                                            WHERE (h.so_no = '{$escItSo}' OR d.so_no = '{$escItSo}')
                                              AND d.kode_barang = '{$escKd}'
                                              AND (h.cmp_id = '{$sessionCmpId}' OR h.cmp_id = '' OR h.cmp_id IS NULL)
                                            LIMIT 1");
                if ($qSoItem && $qsi = $qSoItem->fetch_assoc()) {
                    $idSo = $qsi['id'];
                }
            }
            $qty     = (float)($it['qty'] ?? 0);
            $sat     = trim($it['sat'] ?? 'PCE');
            $ctn     = (float)($it['ctn'] ?? 0);
            $netto   = (float)($it['netto'] ?? 0);
            $gross   = (float)($it['gross'] ?? 0);
            $volume  = (float)($it['volume'] ?? 0);
            $color   = trim($it['color_cd'] ?? '');
            $size    = trim($it['size_cd'] ?? '');
            $dNote   = trim($it['note'] ?? '');
            $dStatus = '0';
            $terima  = (float)($it['terima'] ?? 0);

            // Auto Netto & Gross jika belum diisi mengikuti PB w_do.srw
            if ($netto <= 0 && !empty($kdBrg)) {
                $escK = $koneksi->real_escape_string($kdBrg);
                $qMat = $koneksi->query("SELECT BERAT FROM bc_material WHERE KODE_BARANG = '{$escK}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
                if ($qMat && $qm = $qMat->fetch_assoc()) {
                    $beratSat = (float)$qm['BERAT'];
                    $netto = $beratSat * $qty;
                }
            }
            if ($gross <= 0) {
                $gross = ($ctn / 2) + $netto;
            }

            if (!empty($itPoNo)) $poList[$itPoNo] = true;
            if (!empty($itSoNo)) $soList[$itSoNo] = true;
            if (!empty($idSo))   $affectedSoIds[] = $idSo;

            $dStmt->bind_param("ssssissssdsddddsssssd",
                $dtlId, $id, $sessionCmpId, $doNo, $seq, $idSo, $itSoNo, $itPoNo,
                $kdBrg, $uraian, $qty, $sat, $netto, $gross, $volume, $ctn,
                $color, $size, $dNote, $dStatus, $terima
            );
            if (!$dStmt->execute()) throw new Exception("Gagal menyimpan rincian barang baris ke-{$seq}: " . $dStmt->error);
            $seq++;
        }
        $dStmt->close();

        // Update header po_no & so_no agregasi jika kosong
        if (empty($poNo) && !empty($poList)) {
            $aggPo = implode(', ', array_keys($poList));
            $koneksi->query("UPDATE bc_do_hdr SET po_no = '" . $koneksi->real_escape_string($aggPo) . "' WHERE id = '{$id}'");
        }
        if (empty($soNo) && !empty($soList)) {
            $aggSo = implode(', ', array_keys($soList));
            $koneksi->query("UPDATE bc_do_hdr SET so_no = '" . $koneksi->real_escape_string($aggSo) . "' WHERE id = '{$id}'");
        }

        // SINKRONISASI KEMBALI REALISASI TERIMA DI SALES ORDER (SO) - Acuan f_update_so2.srf & ue_update_so_vendor()
        if (!empty($affectedSoIds)) {
            $cleanSoIds = array_unique(array_filter($affectedSoIds));
            foreach ($cleanSoIds as $sId) {
                $escSId = $koneksi->real_escape_string($sId);
                // 1. Update bc_so_dtl.terima = akumulasi semua DO yang mengacu pada id_so ini
                $koneksi->query("UPDATE bc_so_dtl a 
                                 SET a.terima = COALESCE((SELECT SUM(b.qty) FROM bc_do_dtl b WHERE b.id_so = a.id), 0)
                                 WHERE a.id = '{$escSId}'");

                // 2. Ambil id header SO terkait
                $qHdr = $koneksi->query("SELECT id_hdr FROM bc_so_dtl WHERE id = '{$escSId}' LIMIT 1");
                if ($qHdr && $qh = $qHdr->fetch_assoc()) {
                    $soHdrId = $koneksi->real_escape_string($qh['id_hdr']);
                    $koneksi->query("UPDATE bc_so_hdr SET status = CASE
                        WHEN (SELECT COALESCE(SUM(terima), 0) FROM bc_so_dtl WHERE id_hdr = '{$soHdrId}') = 0 THEN '0'
                        WHEN (SELECT COUNT(1) FROM bc_so_dtl WHERE id_hdr = '{$soHdrId}' AND terima < qty) = 0 THEN '2'
                        ELSE '1'
                    END WHERE id = '{$soHdrId}'");
                }
            }
        }

        $koneksi->commit();

        echo json_encode([
            'status'  => 'OK',
            'message' => 'Surat Jalan (Delivery Order) berhasil disimpan.',
            'id'      => $id,
            'do_no'   => $doNo
        ]);
    } catch (\Throwable $ex) {
        if ($koneksi) $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 7. HAPUS DELIVERY ORDER DENGAN GUARD LOCK FAKTUR PENJUALAN (SI)
// -------------------------------------------------------------------------
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Surat Jalan tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        // Guard Check: Apakah DO sudah difakturkan di Sales Invoice (SI)?
        $chkSi = $koneksi->query("SELECT sd.si_no FROM bc_si_dtl sd 
                                  JOIN bc_do_dtl dd ON sd.id_do = dd.id 
                                  WHERE dd.id_hdr = '{$escId}' LIMIT 1");
        if ($chkSi && $cs = $chkSi->fetch_assoc()) {
            throw new Exception("Surat Jalan ini SUDAH DIFAKTURKAN ke Faktur Penjualan / SI ({$cs['si_no']}). Transaksi TIDAK BISA DIHAPUS!");
        }

        // Catat id_so untuk sinkronisasi kembali
        $affectedSoIds = [];
        $qOldSo = $koneksi->query("SELECT DISTINCT id_so, so_no, kode_barang FROM bc_do_dtl WHERE id_hdr = '{$escId}'");
        if ($qOldSo) {
            while ($os = $qOldSo->fetch_assoc()) {
                if (!empty($os['id_so'])) {
                    $affectedSoIds[] = $os['id_so'];
                } elseif (!empty($os['so_no']) && !empty($os['kode_barang'])) {
                    $escSoNo = $koneksi->real_escape_string($os['so_no']);
                    $escKd   = $koneksi->real_escape_string($os['kode_barang']);
                    $qMatch = $koneksi->query("SELECT d.id FROM bc_so_dtl d 
                                               JOIN bc_so_hdr h ON d.id_hdr = h.id
                                               WHERE (h.so_no = '{$escSoNo}' OR d.so_no = '{$escSoNo}')
                                                 AND d.kode_barang = '{$escKd}'
                                               LIMIT 1");
                    if ($qMatch && $qm = $qMatch->fetch_assoc()) $affectedSoIds[] = $qm['id'];
                }
            }
        }

        $koneksi->begin_transaction();

        $koneksi->query("DELETE FROM bc_do_dtl WHERE id_hdr = '{$escId}'");
        $koneksi->query("DELETE FROM bc_do_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        // Sinkronisasi status SO kembali
        if (!empty($affectedSoIds)) {
            $cleanSoIds = array_unique(array_filter($affectedSoIds));
            foreach ($cleanSoIds as $sId) {
                $escSId = $koneksi->real_escape_string($sId);
                $koneksi->query("UPDATE bc_so_dtl a 
                                 SET a.terima = COALESCE((SELECT SUM(b.qty) FROM bc_do_dtl b WHERE b.id_so = a.id), 0)
                                 WHERE a.id = '{$escSId}'");

                $qHdr = $koneksi->query("SELECT id_hdr FROM bc_so_dtl WHERE id = '{$escSId}' LIMIT 1");
                if ($qHdr && $qh = $qHdr->fetch_assoc()) {
                    $soHdrId = $koneksi->real_escape_string($qh['id_hdr']);
                    $koneksi->query("UPDATE bc_so_hdr SET status = CASE
                        WHEN (SELECT COALESCE(SUM(terima), 0) FROM bc_so_dtl WHERE id_hdr = '{$soHdrId}') = 0 THEN '0'
                        WHEN (SELECT COUNT(1) FROM bc_so_dtl WHERE id_hdr = '{$soHdrId}' AND terima < qty) = 0 THEN '2'
                        ELSE '1'
                    END WHERE id = '{$soHdrId}'");
                }
            }
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => 'Dokumen Surat Jalan berhasil dihapus.']);
    } catch (\Throwable $ex) {
        if ($koneksi) $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 8. TRACEABILITY SURAT JALAN (SO <-> DO <-> SI <-> DOKUMEN BC)
// -------------------------------------------------------------------------
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Surat Jalan tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hdrQ = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) AS buyer_nama 
                                 FROM bc_do_hdr h 
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')) 
                                 WHERE h.id = '{$escId}' LIMIT 1");
        $hdr = ($hdrQ && $hdrQ->num_rows > 0) ? $hdrQ->fetch_assoc() : null;

        $dtlQ = $koneksi->query("SELECT d.*, m.URAIAN as mat_uraian 
                                 FROM bc_do_dtl d 
                                 LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '')) 
                                 WHERE d.id_hdr = '{$escId}' ORDER BY d.seq ASC");
        $details = [];
        $doDtlIds = [];
        if ($dtlQ) {
            while ($r = $dtlQ->fetch_assoc()) {
                $doDtlIds[] = "'" . $koneksi->real_escape_string($r['id']) . "'";
                $details[] = $r;
            }
        }

        // Kueri Sales Order (SO) Terkait
        $soList = [];
        if (!empty($hdr['so_no'])) {
            $escSoNo = $koneksi->real_escape_string($hdr['so_no']);
            $soQ = $koneksi->query("SELECT DISTINCT id, so_no, so_dt, po_no, total_order, status 
                                    FROM bc_so_hdr 
                                    WHERE so_no LIKE '%{$escSoNo}%' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
            if ($soQ) while ($s = $soQ->fetch_assoc()) $soList[] = $s;
        }

        // Kueri Sales Invoice (SI) Terkait
        $siList = [];
        if (!empty($doDtlIds) || !empty($hdr['do_no'])) {
            $escDoNo = $koneksi->real_escape_string($hdr['do_no']);
            $siQ = $koneksi->query("SELECT DISTINCT sh.id, sh.si_no, sh.si_dt, sh.total_order, sh.terbayar, sh.status, 
                                           sh.no_bc, sh.no_aju, sh.bc
                                    FROM bc_si_hdr sh
                                    LEFT JOIN bc_si_dtl sd ON sh.id = sd.id_hdr
                                    WHERE (sh.do_no = '{$escDoNo}' OR sd.do_no = '{$escDoNo}')
                                      AND (sh.cmp_id = '{$escCmp}' OR sh.cmp_id = '')
                                    ORDER BY sh.si_dt DESC");
            if ($siQ) while ($si = $siQ->fetch_assoc()) $siList[] = $si;
        }

        echo json_encode([
            'status'  => 'OK',
            'header'  => $hdr,
            'details' => $details,
            'so_list' => $soList,
            'si_list' => $siList
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 9. FORMULIR CETAK RESMI A4 SURAT JALAN (PRINT FORM)
// -------------------------------------------------------------------------
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrQ = $koneksi->query("SELECT a.*, 
                                    COALESCE(b.NAMA, a.vendor) AS buyer_nama,
                                    COALESCE(b.ALAMAT, a.shipto) AS buyer_alamat,
                                    b.NPWP AS buyer_npwp,
                                    b.TELP AS buyer_telp
                             FROM bc_do_hdr a
                             LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                             WHERE a.id = '{$escId}' AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)
                             LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) die("Dokumen Surat Jalan tidak ditemukan.");
    $h = $hdrQ->fetch_assoc();

    $dtlQ = $koneksi->query("SELECT d.*, 
                                    COALESCE(m.URAIAN, d.uraian) AS item_uraian,
                                    COALESCE(m.BERAT, 0) AS item_berat
                             FROM bc_do_dtl d
                             LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                             WHERE d.id_hdr = '{$escId}'
                             ORDER BY d.seq ASC");
    $items = [];
    $totalQty = 0;
    $totalCtn = 0;
    $totalNetto = 0;
    $totalGross = 0;

    if ($dtlQ) {
        while ($r = $dtlQ->fetch_assoc()) {
            $items[] = $r;
            $totalQty += (float)$r['qty'];
            $totalCtn += (float)$r['ctn'];
            $totalNetto += (float)$r['netto'];
            $totalGross += (float)$r['gross'];
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Surat Jalan - <?= htmlspecialchars($h['do_no']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 12mm 15mm 15mm 15mm; }
            * { box-sizing: border-box; }
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 10px; color: #111; margin: 0; padding: 0; line-height: 1.4; }
            .print-btn-bar { background: #f1f5f9; padding: 10px; text-align: right; border-bottom: 1px solid #cbd5e1; margin-bottom: 15px; }
            @media print { .print-btn-bar { display: none; } }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            .logo-img { max-height: 52px; max-width: 160px; object-fit: contain; }
            .doc-title { font-size: 16px; font-weight: 700; text-align: right; letter-spacing: 0.5px; color: #0f172a; }
            .doc-badge { display: inline-block; font-size: 9px; padding: 2px 8px; border: 1px solid #0284c7; background: #e0f2fe; color: #0369a1; border-radius: 3px; font-weight: 600; margin-top: 4px; }
            
            .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .meta-table td { vertical-align: top; padding: 3px 6px; }
            .box-border { border: 1px solid #94a3b8; border-radius: 4px; padding: 6px 8px; }

            .item-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .item-table th { background: #f8fafc; border: 1px solid #94a3b8; padding: 5px 6px; font-size: 9.5px; font-weight: 600; text-align: center; }
            .item-table td { border: 1px solid #cbd5e1; padding: 4px 6px; font-size: 9.5px; vertical-align: top; }

            .sign-table { width: 100%; border-collapse: collapse; margin-top: 20px; page-break-inside: avoid; }
            .sign-table td { width: 33.33%; text-align: center; vertical-align: top; padding: 6px; font-size: 9.5px; }
            .sign-box { height: 60px; }
        </style>
    </head>
    <body>
        <div class="print-btn-bar">
            <button onclick="window.print()" style="padding: 6px 14px; font-size: 11px; font-weight: 600; cursor: pointer; background: #0284c7; color: #fff; border: 1px solid #0369a1; border-radius: 4px;">🖨️ Cetak Surat Jalan</button>
        </div>

        <table class="header-table">
            <tr>
                <td style="width: 15%; vertical-align: middle;">
                    <img src="<?= htmlspecialchars($companyLogo) ?>" class="logo-img" alt="Logo">
                </td>
                <td style="width: 45%; vertical-align: middle; padding-left: 10px;">
                    <div style="font-size: 13px; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($activeCmpName) ?></div>
                    <div><?= htmlspecialchars($companyAlamat ?: 'Kawasan Berikat Enterprise') ?></div>
                    <div>NPWP: <?= htmlspecialchars($companyNpwp ?: '-') ?> | NIB: <?= htmlspecialchars($companyNib ?: '-') ?></div>
                </td>
                <td style="width: 40%; text-align: right; vertical-align: middle;">
                    <div class="doc-title">SURAT JALAN / DELIVERY ORDER</div>
                    <div class="doc-badge">DOKUMEN RESMI PENGIRIMAN</div>
                    <div style="margin-top: 4px; font-size: 11px; font-weight: 600; color: #0369a1;">No: <?= htmlspecialchars($h['do_no']) ?></div>
                </td>
            </tr>
        </table>
        <hr style="border: 0; border-top: 1.5px solid #0284c7; margin: 0 0 10px 0;">

        <table class="meta-table">
            <tr>
                <td style="width: 50%;">
                    <div class="box-border">
                        <div style="font-weight: 700; color: #0369a1; border-bottom: 1px dashed #cbd5e1; padding-bottom: 2px; margin-bottom: 4px;">TUJUAN PENGIRIMAN (SHIP TO / CUSTOMER)</div>
                        <div style="font-weight: 600; font-size: 10.5px;"><?= htmlspecialchars($h['buyer_nama']) ?></div>
                        <div><?= nl2br(htmlspecialchars($h['shipto'] ?: ($h['buyer_alamat'] ?: '-'))) ?></div>
                        <div style="margin-top: 4px;">
                            <strong>Attn:</strong> <?= htmlspecialchars($h['attn'] ?: '-') ?> | 
                            <strong>NPWP:</strong> <?= htmlspecialchars($h['buyer_npwp'] ?: '-') ?>
                        </div>
                    </div>
                </td>
                <td style="width: 50%;">
                    <div class="box-border">
                        <div style="font-weight: 700; color: #0369a1; border-bottom: 1px dashed #cbd5e1; padding-bottom: 2px; margin-bottom: 4px;">RINCIAN PENGIRIMAN & DOKUMEN PABEAN</div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 9.5px;">
                            <tr><td style="width: 38%; padding: 1px 0;"><strong>Tgl Surat Jalan:</strong></td><td><?= htmlspecialchars($h['do_dt']) ?></td></tr>
                            <tr><td style="padding: 1px 0;"><strong>Tgl Pengiriman:</strong></td><td><?= htmlspecialchars($h['ship_dt'] ?: $h['do_dt']) ?></td></tr>
                            <tr><td style="padding: 1px 0;"><strong>Referensi SO / PO:</strong></td><td><?= htmlspecialchars($h['so_no'] ?: '-') ?> / <?= htmlspecialchars($h['po_no'] ?: '-') ?></td></tr>
                            <tr><td style="padding: 1px 0;"><strong>Kendaraan / Sopir:</strong></td><td><?= htmlspecialchars($h['no_polisi'] ?: '-') ?> (<?= htmlspecialchars($h['driver_name'] ?: '-') ?>)</td></tr>
                            <?php if (!empty($h['bc_doc_type']) || !empty($h['nomor_daftar'])): ?>
                            <tr>
                                <td style="padding: 1px 0; color: #0284c7;"><strong>Dokumen BC:</strong></td>
                                <td style="color: #0284c7; font-weight: 600;">
                                    <?= htmlspecialchars($h['bc_doc_type'] ?: 'PEB') ?> | No. Daftar: <?= htmlspecialchars($h['nomor_daftar'] ?: '-') ?> (<?= htmlspecialchars($h['tanggal_daftar'] ?: '-') ?>)
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <table class="item-table">
            <thead>
                <tr>
                    <th style="width: 4%;">NO</th>
                    <th style="width: 16%;">KODE BARANG</th>
                    <th style="width: 34%;">URAIAN BARANG</th>
                    <th style="width: 10%;">QTY KIRIM</th>
                    <th style="width: 6%;">SAT</th>
                    <th style="width: 8%;">CTN/KOLI</th>
                    <th style="width: 10%;">NETTO (KG)</th>
                    <th style="width: 12%;">KETERANGAN</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($items as $it): ?>
                <tr>
                    <td style="text-align: center;"><?= $no++ ?></td>
                    <td style="font-weight: 600;"><?= htmlspecialchars($it['kode_barang']) ?></td>
                    <td><?= htmlspecialchars($it['item_uraian'] ?: $it['uraian']) ?></td>
                    <td style="text-align: right; font-weight: 600;"><?= number_format((float)$it['qty'], 2, '.', ',') ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($it['sat']) ?></td>
                    <td style="text-align: right;"><?= number_format((float)$it['ctn'], 0, '.', ',') ?></td>
                    <td style="text-align: right;"><?= number_format((float)$it['netto'], 2, '.', ',') ?></td>
                    <td><?= htmlspecialchars($it['note'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8fafc; font-weight: 700;">
                    <td colspan="3" style="text-align: right;">TOTAL:</td>
                    <td style="text-align: right; color: #0369a1;"><?= number_format($totalQty, 2, '.', ',') ?></td>
                    <td></td>
                    <td style="text-align: right;"><?= number_format($totalCtn, 0, '.', ',') ?></td>
                    <td style="text-align: right;"><?= number_format($totalNetto, 2, '.', ',') ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <?php if (!empty($h['note'])): ?>
        <div style="font-size: 9.5px; border-left: 3px solid #0284c7; padding: 4px 8px; background: #f8fafc; margin-bottom: 12px;">
            <strong>Catatan Pengiriman:</strong> <?= nl2br(htmlspecialchars($h['note'])) ?>
        </div>
        <?php endif; ?>

        <table class="sign-table">
            <tr>
                <td>
                    <div>Dibuat Oleh (Bag. Gudang),</div>
                    <div class="sign-box"></div>
                    <div style="border-top: 1px solid #333; display: inline-block; width: 75%; padding-top: 2px;">
                        ( <?= htmlspecialchars($h['set_by'] ?: 'Admin Gudang') ?> )
                    </div>
                </td>
                <td>
                    <div>Yang Menyerahkan (Driver),</div>
                    <div class="sign-box"></div>
                    <div style="border-top: 1px solid #333; display: inline-block; width: 75%; padding-top: 2px;">
                        ( <?= htmlspecialchars($h['driver_name'] ?: 'Supir / Ekspedisi') ?> )
                    </div>
                </td>
                <td>
                    <div>Diterima Oleh (Customer),</div>
                    <div class="sign-box"></div>
                    <div style="border-top: 1px solid #333; display: inline-block; width: 75%; padding-top: 2px;">
                        ( <?= htmlspecialchars($h['attn'] ?: 'Penerima Barang') ?> )
                    </div>
                </td>
            </tr>
        </table>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------------------
// PRE-FETCH DATA DROPDOWN BUYER / CUSTOMER
// -------------------------------------------------------------------------
$buyerQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, KONTAK, TELP 
                           FROM bc_bs 
                           WHERE (TIPE = 'B' OR TIPE = 'BUYER' OR TIPE = 'CUSTOMER' OR TIPE IS NULL OR TIPE = '') 
                             AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                           ORDER BY NAMA ASC");
$buyerList = [];
if ($buyerQ) {
    while ($b = $buyerQ->fetch_assoc()) {
        $buyerList[] = $b;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan (Delivery Order / DO) - <?= htmlspecialchars($activeCmpName) ?></title>
    
    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 Pro / Solid -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- SheetJS Excel Export -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <!-- Ant Design DataGrid CSS -->
    <link rel="stylesheet" href="/assets/ant_grid.css">

    <!-- Searchable Select Combobox -->
    <link rel="stylesheet" href="/assets/ant_searchable_select.css?v=20261101">
    <script src="/assets/ant_searchable_select.js?v=20261101"></script>

    <style>
        :root {
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --primary-light: #e0f2fe;
            --primary-focus: rgba(2, 132, 199, 0.25);
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #0ea5e9;
            --success: #10b981;
            --bg-app: #f4f6f9;
            --bg-card: #ffffff;
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --table-head-bg: #f8fafc;
            --row-hover: #f0f9ff;
            --row-selected: #e0f2fe;
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            --app-font-size: 9.5px;
            --form-font-size: 9.5px;
        }

        body.dark-theme {
            --bg-app: #111827;
            --bg-card: #1f2937;
            --border-color: #374151;
            --border-light: #2d3748;
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --table-head-bg: #1e293b;
            --row-hover: #0c4a6e;
            --row-selected: #075985;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            font-family: var(--app-font);
            font-size: var(--app-font-size);
            color: var(--text-main);
            background: var(--bg-app);
            overflow: hidden;
        }

        .ant-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 6px;
            gap: 6px;
        }

        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: 100%;
        }

        /* Header Card */
        .ant-card-header {
            padding: 5px 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            background: var(--bg-card);
            flex-shrink: 0;
        }

        .ant-card-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: 0.3px;
        }

        .ant-badge {
            font-size: 8.5px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 3px;
            text-transform: uppercase;
        }
        .ant-badge-company { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .ant-badge-status { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }

        /* Toolbar */
        .ant-toolbar {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }

        .ant-btn {
            height: 21px;
            padding: 0 8px;
            font-size: 9.5px;
            font-family: var(--app-font);
            font-weight: 500;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            border-radius: 3px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .ant-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }
        .ant-btn-danger {
            background: #ffffff;
            border-color: #fca5a5;
            color: var(--danger);
        }
        .ant-btn-danger:hover {
            background: #fef2f2;
            border-color: var(--danger);
            color: var(--danger);
        }

        /* Tab Navigation Bar */
        .ant-tabs-bar {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            background: #fafbfc;
            padding: 0 8px;
            gap: 4px;
            flex-shrink: 0;
        }
        body.dark-theme .ant-tabs-bar { background: #1a2234; }

        .ant-tab-btn {
            padding: 5px 12px;
            font-size: 10px;
            font-weight: 500;
            color: var(--text-muted);
            border: none;
            background: transparent;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active {
            color: var(--primary);
            font-weight: 600;
            border-bottom-color: var(--primary);
            background: var(--bg-card);
        }

        /* Main Workspace Content */
        .ant-tab-content {
            flex: 1;
            display: none;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }
        .ant-tab-content.active { display: flex; }

        /* Split Panels (PB Desktop Style) */
        #paneHdr {
            height: 185px;
            min-height: 110px;
            max-height: 380px;
            overflow-y: auto;
            padding: 6px 10px;
            background: var(--bg-card);
            flex-shrink: 0;
        }

        .splitbar {
            height: 5px;
            background: #e2e8f0;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            cursor: row-resize;
            position: relative;
            z-index: 10;
            flex-shrink: 0;
            transition: background 0.15s;
        }
        .splitbar:hover { background: var(--primary); }

        #paneGrid {
            flex: 1;
            min-height: 130px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--bg-card);
        }

        #paneFtr {
            height: 95px;
            min-height: 60px;
            max-height: 200px;
            overflow-y: auto;
            padding: 6px 10px;
            background: var(--bg-card);
            border-top: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        /* Form Grid 2-Column Compact Desktop */
        .fld-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .fld-col {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .fld-row {
            display: grid;
            grid-template-columns: 105px 1fr;
            align-items: center;
            gap: 6px;
        }
        .fld-row label {
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .fld-row label.req::after {
            content: " *";
            color: var(--danger);
            font-weight: bold;
        }
        .fld-ctrl {
            display: flex;
            align-items: center;
            gap: 4px;
            flex: 1;
            min-width: 0;
            width: 100%;
        }
        .ant-input {
            width: 100%;
            height: 21px;
            padding: 2px 6px;
            font-size: 9.5px;
            font-family: var(--app-font);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .ant-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-focus);
        }
        .ant-input[readonly], .ant-input:disabled {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }
        body.dark-theme .ant-input[readonly] { background: #1e293b; color: #94a3b8; }

        /* Grid Table DataWindow */
        .table-wrapper {
            flex: 1;
            overflow: auto;
            position: relative;
        }
        table.dw {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.dw th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border: 1px solid var(--border-color);
            padding: 3px 6px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 9.5px;
            color: var(--text-main);
            z-index: 5;
            user-select: none;
        }
        table.dw td {
            border: 1px solid var(--border-color);
            padding: 2px 4px;
            height: 22px;
            vertical-align: middle;
            color: var(--text-main);
        }
        table.dw tbody tr:hover { background: var(--row-hover); }
        table.dw tbody tr.selected { background: var(--row-selected); font-weight: 500; }

        table.dw td input.cell-input {
            width: 100%;
            height: 19px;
            border: 1px solid transparent;
            background: transparent;
            font-size: 9.5px;
            font-family: var(--app-font);
            padding: 1px 4px;
            color: var(--text-main);
            outline: none;
        }
        table.dw td input.cell-input:focus {
            background: #ffffff;
            border-color: var(--primary);
            border-radius: 2px;
        }
        body.dark-theme table.dw td input.cell-input:focus { background: #1f2937; }

        /* 4 KPI Strip in Browse Tab */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            padding: 6px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }
        body.dark-theme .kpi-strip { background: #1e293b; }

        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 5px 8px;
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

        /* Trace Timeline Cards */
        .trace-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 10px;
            overflow-y: auto;
            flex: 1;
        }
        .trace-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .trace-card-head {
            padding: 6px 10px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 10.5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        body.dark-theme .trace-card-head { background: #1e293b; }

        /* Modal Popups */
        .ant-modal-backdrop {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .ant-modal {
            background: var(--bg-card);
            border-radius: 5px;
            width: 850px;
            max-width: 95vw;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        .ant-modal-header {
            padding: 7px 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            font-weight: 600;
        }
        body.dark-theme .ant-modal-header { background: #1e293b; }
        .ant-modal-body {
            padding: 10px;
            overflow-y: auto;
            flex: 1;
        }
        .ant-modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: #f8fafc;
        }
        body.dark-theme .ant-modal-footer { background: #1e293b; }

        /* Context Menu (Klik Kanan PB Style - seperti Sales Invoice) */
        .context-menu {
            position: fixed;
            z-index: 99999;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
            min-width: 230px;
            padding: 4px 0;
            user-select: none;
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
            background: var(--row-hover);
            color: var(--primary);
        }
        .ctx-item i {
            width: 14px;
            text-align: center;
            font-size: 11px;
        }
        .ctx-item span {
            flex: 1;
        }
        .ctx-item kbd {
            font-size: 8.5px;
            padding: 1px 4px;
            background: var(--table-head-bg);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            color: var(--text-muted);
        }
        .ctx-sep {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }
        tr.context-active {
            background: var(--row-selected) !important;
            outline: 1px dashed var(--primary);
        }
    </style>
</head>
<body>

<div class="ant-container">
    <div class="ant-card">
        
        <!-- TOP HEADER CARD -->
        <div class="ant-card-header">
            <div class="ant-card-title">
                <i class="fa-solid fa-truck-fast" style="color:var(--primary); font-size:14px;"></i>
                <span>Surat Jalan (Delivery Order / DO)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-status" id="badgeDoStatus">DRAFT</span>
            </div>

            <!-- ACTION TOOLBAR -->
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="newDo()" title="Buat Surat Jalan Baru (F2)">
                    <i class="fa-solid fa-file-plus"></i> Baru
                </button>
                <button type="button" class="ant-btn" onclick="openSoPickerModal()" title="Tarik Item dari Sales Order (F3)">
                    <i class="fa-solid fa-cart-arrow-down" style="color:#0284c7;"></i> Tarik Pesanan SO
                </button>
                <button type="button" class="ant-btn" onclick="saveDo()" title="Simpan Transaksi (Ctrl+S)">
                    <i class="fa-solid fa-floppy-disk" style="color:#10b981;"></i> Simpan
                </button>
                <button type="button" class="ant-btn ant-btn-danger" onclick="deleteDo()" title="Hapus Surat Jalan">
                    <i class="fa-solid fa-trash-can"></i> Hapus
                </button>
                <button type="button" class="ant-btn" onclick="printDo()" title="Cetak Surat Jalan Resmi A4">
                    <i class="fa-solid fa-print"></i> Cetak DO
                </button>
                
                <!-- Dropdown Export Excel -->
                <div style="position:relative; display:inline-block;">
                    <button type="button" class="ant-btn" onclick="toggleExcelMenu()">
                        <i class="fa-solid fa-file-excel" style="color:#10b981;"></i> Excel <i class="fa-solid fa-chevron-down" style="font-size:8px;"></i>
                    </button>
                    <div id="excelDrop" style="display:none; position:absolute; right:0; top:23px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:3px; box-shadow:0 3px 8px rgba(0,0,0,0.15); z-index:100; min-width:145px;">
                        <a href="javascript:void(0)" onclick="exportExcelSheetJS()" style="display:block; padding:5px 9px; text-decoration:none; color:var(--text-main); font-size:9.5px; border-bottom:1px solid var(--border-light);">
                            📗 Ekspor Item DO
                        </a>
                        <a href="javascript:void(0)" onclick="exportBrowseExcel()" style="display:block; padding:5px 9px; text-decoration:none; color:var(--text-main); font-size:9.5px;">
                            📋 Ekspor Daftar DO (Browse)
                        </a>
                    </div>
                </div>

                <!-- SOP Guide Icon Button (Rule 6) -->
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul & SOP Delivery Order">
                    <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i>
                </button>
            </div>
        </div>

        <!-- TAB NAVIGATION -->
        <div class="ant-tabs-bar">
            <button type="button" class="ant-tab-btn active" id="tabBtn1" onclick="switchTab(1)">
                <i class="fa-solid fa-pen-to-square"></i> Form Entri Surat Jalan
            </button>
            <button type="button" class="ant-tab-btn" id="tabBtn2" onclick="switchTab(2)">
                <i class="fa-solid fa-list-check"></i> Browse Data DO & KPI Strip
            </button>
            <button type="button" class="ant-tab-btn" id="tabBtn3" onclick="switchTab(3)">
                <i class="fa-solid fa-timeline"></i> Traceability Pengiriman (DO ↔ SO ↔ SI ↔ BC)
            </button>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 1: FORM ENTRI SURAT JALAN / DELIVERY ORDER                    -->
        <!-- ================================================================= -->
        <div class="ant-tab-content active" id="tabContent1">
            <input type="hidden" id="doId" value="">

            <!-- PANE 1: HEADER FORM ENTRI (RESIZABLE) -->
            <div id="paneHdr">
                <div class="fld-grid">
                    <!-- KOLOM KIRI: BUYER, TUJUAN & KENDARAAN -->
                    <div class="fld-col">
                        <div class="fld-row">
                            <label class="req">Pelanggan / Buyer</label>
                            <div class="fld-ctrl">
                                <select class="ant-input" id="doBuyer" onchange="onBuyerChange()">
                                    <option value="">-- Pilih Customer / Penerima --</option>
                                    <?php foreach ($buyerList as $b): ?>
                                    <option value="<?= htmlspecialchars($b['KODE']) ?>"
                                            data-nama="<?= htmlspecialchars($b['NAMA']) ?>"
                                            data-alamat="<?= htmlspecialchars($b['ALAMAT'] ?? '') ?>"
                                            data-npwp="<?= htmlspecialchars($b['NPWP'] ?? '') ?>"
                                            data-kontak="<?= htmlspecialchars($b['KONTAK'] ?? '') ?>">
                                        <?= htmlspecialchars($b['KODE'] . ' - ' . $b['NAMA']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="fld-row">
                            <label>Tujuan Kirim (Shipto)</label>
                            <div class="fld-ctrl">
                                <input type="text" class="ant-input" id="doShipto" placeholder="Alamat pabrik / gudang tujuan pengiriman">
                            </div>
                        </div>

                        <div class="fld-row">
                            <label>Attn / Penerima</label>
                            <div class="fld-ctrl">
                                <input type="text" class="ant-input" id="doAttn" placeholder="Nama PIC penerima barang">
                            </div>
                        </div>

                        <div class="fld-row">
                            <label>Pengiriman (Via)</label>
                            <div class="fld-ctrl">
                                <select class="ant-input" id="doShip">
                                    <option value="TRUCK">TRUCK / ARMADA PABRIK</option>
                                    <option value="CONTAINER">CONTAINER 20/40 FEET</option>
                                    <option value="COURIER">EKSPEDISI / KURIR</option>
                                    <option value="PICKUP">AMBIL SENDIRI (CUSTOMER PICKUP)</option>
                                    <option value="LAUT">KAPAL LAUT</option>
                                    <option value="UDARA">PESAWAT UDARA</option>
                                </select>
                            </div>
                        </div>

                        <div class="fld-row">
                            <label>No Polisi & Driver</label>
                            <div class="fld-ctrl">
                                <input type="text" class="ant-input" id="doNoPolisi" placeholder="B 1234 ABC" style="width:120px;">
                                <input type="text" class="ant-input" id="doDriver" placeholder="Nama Supir / Pengemudi">
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM KANAN: NOMOR DO, REFERENSI SO & DATA DOKUMEN PABEAN -->
                    <div class="fld-col">
                        <div class="fld-row">
                            <label class="req">No. Surat Jalan (DO)</label>
                            <div class="fld-ctrl">
                                <input type="text" class="ant-input" id="doNo" placeholder="DO/YYYYMM/0001" style="font-weight:600;">
                                <button type="button" class="ant-btn" onclick="generateDoNo()" title="Generate Nomor Otomatis">
                                    <i class="fa-solid fa-arrows-rotate"></i> Auto
                                </button>
                            </div>
                        </div>

                        <div class="fld-row">
                            <label class="req">Tgl DO & Kirim</label>
                            <div class="fld-ctrl">
                                <input type="date" class="ant-input" id="doDt" value="<?= date('Y-m-d') ?>">
                                <span style="font-size:9.5px; color:var(--text-muted);">s/d</span>
                                <input type="date" class="ant-input" id="doShipDt" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <div class="fld-row">
                            <label>Ref SO / PO Buyer</label>
                            <div class="fld-ctrl">
                                <input type="text" class="ant-input" id="doSoNo" placeholder="No SO Pesanan" style="width:140px;">
                                <input type="text" class="ant-input" id="doPoNo" placeholder="No PO Buyer">
                            </div>
                        </div>

                        <div class="fld-row">
                            <label>Dokumen Pabean BC</label>
                            <div class="fld-ctrl">
                                <select class="ant-input" id="doBcDocType" style="width:120px;">
                                    <option value="">Non-BC / Lokal</option>
                                    <option value="BC 3.0">PEB / BC 3.0</option>
                                    <option value="BC 2.7">BC 2.7 (TPB-TPB)</option>
                                    <option value="BC 4.1">BC 4.1 (Subkon Masuk)</option>
                                    <option value="BC 2.5">BC 2.5 (Lokal / DPIL)</option>
                                    <option value="BC 2.6.2">BC 2.6.2 (Subkon Keluar)</option>
                                </select>
                                <input type="text" class="ant-input" id="doNomorAju" placeholder="Nomor Aju (26 Digit)">
                            </div>
                        </div>

                        <div class="fld-row">
                            <label>No & Tgl Daftar BC</label>
                            <div class="fld-ctrl">
                                <input type="text" class="ant-input" id="doNomorDaftar" placeholder="No Pendaftaran BC">
                                <input type="date" class="ant-input" id="doTanggalDaftar">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SPLITBAR 1 -->
            <div class="splitbar" id="splitHdr"></div>

            <!-- PANE 2: GRID DETAIL BARANG (d_do_dtl) -->
            <div id="paneGrid">
                <!-- GRID TOOLBAR -->
                <div style="padding: 3px 6px; background: #fafbfc; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
                    <div style="display:flex; align-items:center; gap:4px;">
                        <button type="button" class="ant-btn" onclick="addItemRow()">
                            <i class="fa-solid fa-plus" style="color:var(--primary);"></i> Tambah Baris
                        </button>
                        <button type="button" class="ant-btn" onclick="openSoPickerModal()">
                            <i class="fa-solid fa-cart-arrow-down" style="color:#0284c7;"></i> Tarik dari SO (F3)
                        </button>
                        <button type="button" class="ant-btn" onclick="openMaterialPicker()">
                            <i class="fa-solid fa-magnifying-glass" style="color:#f59e0b;"></i> Cari Master Barang
                        </button>
                        <button type="button" class="ant-btn ant-btn-danger" onclick="removeSelectedRows()">
                            <i class="fa-solid fa-minus"></i> Hapus Baris
                        </button>
                    </div>
                    <div style="font-size:9.5px; color:var(--text-muted);">
                        <span>Total Rincian: <strong id="lblGridItemCount">0</strong> item</span>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="dw" id="tblItems">
                        <thead>
                            <tr>
                                <th style="width: 28px;"><input type="checkbox" id="chkAllItems" onclick="toggleSelectAllItems(this)"></th>
                                <th style="width: 32px;">NO</th>
                                <th style="width: 140px;">KODE BARANG</th>
                                <th>URAIAN BARANG</th>
                                <th style="width: 80px; text-align:right;">QTY KIRIM</th>
                                <th style="width: 55px; text-align:center;">SATUAN</th>
                                <th style="width: 70px; text-align:right;">CTN/KOLI</th>
                                <th style="width: 85px; text-align:right;">NETTO (KG)</th>
                                <th style="width: 85px; text-align:right;">GROSS (KG)</th>
                                <th style="width: 70px; text-align:right;">VOL (M3)</th>
                                <th style="width: 75px;">COLOR</th>
                                <th style="width: 75px;">SIZE</th>
                                <th style="width: 120px;">KETERANGAN</th>
                                <th style="width: 35px; text-align:center;">AKSI</th>
                            </tr>
                        </thead>
                        <tbody id="tblItemsBody">
                            <!-- Baris item dinamis di-render via Javascript -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SPLITBAR 2 -->
            <div class="splitbar" id="splitFtr"></div>

            <!-- PANE 3: FOOTER TOTAL REKAPITULASI PENGIRIMAN -->
            <div id="paneFtr">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; height:100%; align-items:center;">
                    <!-- KIRI: CATATAN SURAT JALAN -->
                    <div>
                        <div style="font-size:9px; font-weight:600; color:var(--text-muted); margin-bottom:2px;">CATATAN PENGIRIMAN / SPECIAL INSTRUCTION:</div>
                        <textarea class="ant-input" id="doNote" style="height:52px; resize:none;" placeholder="Catatan instruksi bongkar muat, no segel kontainer, dll..."></textarea>
                    </div>

                    <!-- KANAN: TOTAL RINGKASAN KUANTITAS & BERAT -->
                    <div style="background:#f8fafc; border:1px solid var(--border-color); border-radius:4px; padding:6px 12px; display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; text-align:center;">
                        <div>
                            <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TOTAL QTY</div>
                            <div style="font-size:13px; font-weight:700; color:#0284c7;" id="lblTotalQty">0.00</div>
                        </div>
                        <div>
                            <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TOTAL KOLI / CTN</div>
                            <div style="font-size:13px; font-weight:700;" id="lblTotalCtn">0</div>
                        </div>
                        <div>
                            <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">NETTO (KG)</div>
                            <div style="font-size:13px; font-weight:700; color:#10b981;" id="lblTotalNetto">0.00</div>
                        </div>
                        <div>
                            <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">GROSS (KG)</div>
                            <div style="font-size:13px; font-weight:700; color:#d97706;" id="lblTotalGross">0.00</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 2: BROWSE DATA DO & 4 KPI STRIP                               -->
        <!-- ================================================================= -->
        <div class="ant-tab-content" id="tabContent2">
            <!-- 4 KPI Metrics Strip -->
            <div class="kpi-strip">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-truck-ramp-box"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TOTAL SURAT JALAN</div>
                        <div style="font-size:12px; font-weight:700;" id="kpiTotalDo">0 DO</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">DO OPEN (BELUM SI)</div>
                        <div style="font-size:12px; font-weight:700; color:#d97706;" id="kpiOpenDo">0 DO</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#ecfdf5; color:#10b981;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">DO TERFAKTUR SI (CLOSED)</div>
                        <div style="font-size:12px; font-weight:700; color:#10b981;" id="kpiClosedDo">0 DO</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#f5f3ff; color:#8b5cf6;"><i class="fa-solid fa-cubes-stacked"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">QTY KIRIM BULAN INI</div>
                        <div style="font-size:12px; font-weight:700; color:#8b5cf6;" id="kpiTotalQty">0.00 PCE</div>
                    </div>
                </div>
            </div>

            <!-- BROWSE FILTER TOOLBAR -->
            <div style="padding: 5px 8px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                <span style="font-size:9.5px; font-weight:500; color:var(--text-muted);">Periode:</span>
                <input type="date" class="ant-input" id="filterStartDt" style="width:115px;" value="<?= date('Y-m-01') ?>">
                <span style="font-size:9.5px; color:var(--text-muted);">s/d</span>
                <input type="date" class="ant-input" id="filterEndDt" style="width:115px;" value="<?= date('Y-m-d') ?>">
                
                <span style="font-size:9.5px; font-weight:500; color:var(--text-muted); margin-left:6px;">Status:</span>
                <select class="ant-input" id="filterStatus" style="width:125px;">
                    <option value="all">Semua Status</option>
                    <option value="0">0 - Open (Belum SI)</option>
                    <option value="1">1 - Parsial SI</option>
                    <option value="2">2 - Closed (Lengkap SI)</option>
                    <option value="3">3 - Batal / Cancel</option>
                </select>

                <div style="position:relative; width:220px; margin-left:6px;">
                    <input type="text" class="ant-input" id="filterSearch" placeholder="Cari No DO / SO / Buyer / No Polisi..." style="padding-left:22px;">
                    <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:6px; top:5px; color:var(--text-muted); font-size:10px;"></i>
                </div>

                <button type="button" class="ant-btn ant-btn-primary" onclick="loadBrowseData(1)">
                    <i class="fa-solid fa-filter"></i> Tampilkan
                </button>
                <button type="button" class="ant-btn" onclick="resetBrowseFilter()">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
            </div>

            <!-- TABEL DAFTAR SURAT JALAN -->
            <div class="table-wrapper">
                <table class="dw" id="tblBrowse">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align:center;">NO</th>
                            <th style="width: 120px;">NO SURAT JALAN</th>
                            <th style="width: 75px; text-align:center;">TGL DO</th>
                            <th>PELANGGAN / BUYER</th>
                            <th style="width: 90px;">NO POLISI</th>
                            <th style="width: 100px;">DRIVER</th>
                            <th style="width: 110px;">REF SO</th>
                            <th style="width: 100px;">REF PO</th>
                            <th style="width: 60px; text-align:right;">ITEM</th>
                            <th style="width: 80px; text-align:right;">TOTAL QTY</th>
                            <th style="width: 65px; text-align:center;">DOK BC</th>
                            <th style="width: 90px;">NO DAFTAR</th>
                            <th style="width: 80px; text-align:center;">STATUS</th>
                            <th style="width: 70px; text-align:center;">AKSI</th>
                        </tr>
                    </thead>
                    <tbody id="tblBrowseBody">
                        <!-- Data rows load via AJAX -->
                    </tbody>
                </table>
            </div>

            <!-- BROWSE PAGINATION -->
            <div style="padding: 4px 8px; background: #fafbfc; border-top: 1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div style="font-size:9.5px; color:var(--text-muted);">
                    Menampilkan <span id="lblPageRange">0-0</span> dari <strong id="lblTotalRows">0</strong> Surat Jalan
                </div>
                <div style="display:flex; align-items:center; gap:4px;">
                    <button type="button" class="ant-btn" id="btnPrevPage" onclick="changePage(-1)">
                        <i class="fa-solid fa-chevron-left"></i> Prev
                    </button>
                    <span style="font-size:9.5px; padding:0 6px;">Hal <strong id="lblCurrentPage">1</strong></span>
                    <button type="button" class="ant-btn" id="btnNextPage" onclick="changePage(1)">
                        Next <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 3: TRACEABILITY PENGIRIMAN (DO ↔ SO ↔ SI ↔ DOKUMEN BC)       -->
        <!-- ================================================================= -->
        <div class="ant-tab-content" id="tabContent3">
            <div style="padding: 6px 10px; background:#f0f9ff; border-bottom:1px solid #bae6fd; font-size:9.5px; color:#0369a1; display:flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-circle-info"></i>
                <span>Memantau jejak pesanan dari <strong>Sales Order (SO)</strong> ➔ <strong>Surat Jalan (DO)</strong> ➔ <strong>Faktur Penjualan (SI)</strong> ➔ <strong>Respon Bea Cukai</strong>.</span>
            </div>

            <div class="trace-container">
                <!-- CARD 1: SALES ORDER ASAL -->
                <div class="trace-card">
                    <div class="trace-card-head">
                        <span><i class="fa-solid fa-file-invoice" style="color:#10b981;"></i> 1. Pesanan Sales Order</span>
                        <span class="ant-badge ant-badge-company" id="traceSoNo">-</span>
                    </div>
                    <div style="padding:8px; font-size:9.5px; overflow-y:auto; flex:1;" id="traceSoList">
                        <div style="color:var(--text-muted); text-align:center; padding:20px 0;">Pilih Surat Jalan untuk melihat SO asal</div>
                    </div>
                </div>

                <!-- CARD 2: REALISASI SURAT JALAN -->
                <div class="trace-card">
                    <div class="trace-card-head">
                        <span><i class="fa-solid fa-truck-fast" style="color:#0284c7;"></i> 2. Rincian Kirim DO Aktif</span>
                        <span class="ant-badge ant-badge-status" id="traceDoBadge">ACTIVE</span>
                    </div>
                    <div style="padding:8px; font-size:9.5px; overflow-y:auto; flex:1;" id="traceDoItems">
                        <div style="color:var(--text-muted); text-align:center; padding:20px 0;">Belum ada item terpilih</div>
                    </div>
                </div>

                <!-- CARD 3: FAKTUR PENJUALAN (SI) & CEISA -->
                <div class="trace-card">
                    <div class="trace-card-head">
                        <span><i class="fa-solid fa-receipt" style="color:#8b5cf6;"></i> 3. Penagihan Faktur Penjualan (SI)</span>
                        <span class="ant-badge" id="traceSiCount">0 SI</span>
                    </div>
                    <div style="padding:8px; font-size:9.5px; overflow-y:auto; flex:1;" id="traceSiList">
                        <div style="color:var(--text-muted); text-align:center; padding:20px 0;">Belum ada penagihan SI</div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TARIK BARANG DARI SALES ORDER (SO) - ddw_so_belum_do                -->
<!-- ========================================================================= -->
<div class="ant-modal-backdrop" id="modalSoPicker">
    <div class="ant-modal" style="width: 900px;">
        <div class="ant-modal-header">
            <div style="display:flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-cart-arrow-down" style="color:#0284c7;"></i>
                <span>Tarik Item dari Pesanan Penjualan (Sales Order)</span>
            </div>
            <button type="button" class="ant-btn" onclick="closeSoPickerModal()" style="border:none; background:transparent;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div style="padding:6px 10px; background:#f8fafc; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:6px;">
            <input type="text" class="ant-input" id="txtSearchOpenSo" placeholder="Cari No SO / No PO / Kode Barang..." style="width:250px;" onkeydown="if(event.key==='Enter') loadOpenSoItems()">
            <button type="button" class="ant-btn ant-btn-primary" onclick="loadOpenSoItems()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            <span style="font-size:9px; color:var(--text-muted); margin-left:auto;">Item yang ditampilkan adalah pesanan dengan sisa kirim &gt; 0.</span>
        </div>

        <div class="ant-modal-body" style="padding:0;">
            <table class="dw" id="tblOpenSo">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="chkAllOpenSo" onclick="toggleSelectAllOpenSo(this)"></th>
                        <th style="width: 110px;">NO SO</th>
                        <th style="width: 75px; text-align:center;">TGL SO</th>
                        <th style="width: 90px;">NO PO BUYER</th>
                        <th>KODE & NAMA BARANG</th>
                        <th style="width: 65px; text-align:right;">QTY ORDER</th>
                        <th style="width: 65px; text-align:right;">TERKIRIM</th>
                        <th style="width: 65px; text-align:right; color:#0284c7;">SISA KIRIM</th>
                        <th style="width: 45px; text-align:center;">SAT</th>
                    </tr>
                </thead>
                <tbody id="tblOpenSoBody">
                    <!-- Loaded via AJAX -->
                </tbody>
            </table>
        </div>

        <div class="ant-modal-footer">
            <button type="button" class="ant-btn" onclick="closeSoPickerModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="importSelectedSoItems()">
                <i class="fa-solid fa-check"></i> Masukkan ke Surat Jalan
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: CARI MASTER BARANG 5 KLASIFIKASI                                   -->
<!-- ========================================================================= -->
<div class="ant-modal-backdrop" id="modalMaterialPicker">
    <div class="ant-modal" style="width: 850px;">
        <div class="ant-modal-header">
            <div style="display:flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i>
                <span>Pencarian Master Barang (5 Klasifikasi)</span>
            </div>
            <button type="button" class="ant-btn" onclick="closeMaterialPicker()" style="border:none; background:transparent;"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Tab Klasifikasi Barang -->
        <div style="padding: 4px 8px; background:#f8fafc; border-bottom:1px solid var(--border-color); display:flex; gap:4px;">
            <button type="button" class="ant-btn ant-btn-sm active" id="btnKlasFG" onclick="switchMaterialKlas('FG')">Finished Goods (FG)</button>
            <button type="button" class="ant-btn ant-btn-sm" id="btnKlasRM" onclick="switchMaterialKlas('RM')">Raw Materials (RM)</button>
            <button type="button" class="ant-btn ant-btn-sm" id="btnKlasWI" onclick="switchMaterialKlas('WI')">Work In Progress (WIP)</button>
            <button type="button" class="ant-btn ant-btn-sm" id="btnKlasCG" onclick="switchMaterialKlas('CG')">Capital Goods (CG)</button>
            <button type="button" class="ant-btn ant-btn-sm" id="btnKlasW" onclick="switchMaterialKlas('W')">Scrap / Waste</button>
        </div>

        <div style="padding:6px 10px; display:flex; gap:6px;">
            <input type="text" class="ant-input" id="txtSearchMaterial" placeholder="Cari Kode Barang / Uraian / Pos Tarif..." onkeydown="if(event.key==='Enter') searchMaterials()">
            <button type="button" class="ant-btn ant-btn-primary" onclick="searchMaterials()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
        </div>

        <div class="ant-modal-body" style="padding:0;">
            <table class="dw" id="tblMaterialPicker">
                <thead>
                    <tr>
                        <th style="width: 140px;">KODE BARANG</th>
                        <th>URAIAN BARANG</th>
                        <th style="width: 60px; text-align:center;">SATUAN</th>
                        <th style="width: 75px; text-align:right;">BERAT (KG)</th>
                        <th style="width: 85px;">POS TARIF</th>
                        <th style="width: 60px; text-align:center;">AKSI</th>
                    </tr>
                </thead>
                <tbody id="tblMaterialPickerBody">
                    <!-- Rows rendered via JS -->
                </tbody>
            </table>
        </div>

        <div class="ant-modal-footer">
            <label style="margin-right:auto; font-size:9.5px; display:flex; align-items:center; gap:4px; cursor:pointer;">
                <input type="checkbox" id="chkMaterialMulti"> Tetap buka setelah memilih item
            </label>
            <button type="button" class="ant-btn" onclick="closeMaterialPicker()">Tutup</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PANDUAN MODUL & SOP BISNIS (Rule 6)                                -->
<!-- ========================================================================= -->
<div class="ant-modal-backdrop" id="modalGuide">
    <div class="ant-modal" style="width: 780px;">
        <div class="ant-modal-header">
            <div style="display:flex; align-items:center; gap:6px;">
                <i class="fa-solid fa-circle-question" style="color:var(--primary);"></i>
                <span>Panduan Modul &amp; SOP Pengiriman Barang (Delivery Order)</span>
            </div>
            <button type="button" class="ant-btn" onclick="closeModuleGuide()" style="border:none; background:transparent;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="ant-modal-body" style="line-height:1.6; font-size:10px;">
            <h4 style="margin-bottom:6px; color:#0284c7;">1. Alur Bisnis Delivery Order (Surat Jalan)</h4>
            <p>Delivery Order (DO) merupakan dokumen resmi pengeluaran dan pengiriman barang jadi maupun material dari gudang menuju ke Customer/Buyer. Modul ini terhubung secara langsung dengan modul <strong>Sales Order (SO)</strong> dan <strong>Faktur Penjualan (Sales Invoice / SI)</strong> serta kepatuhan <strong>IT Inventory CEISA 4.0</strong>.</p>
            
            <h4 style="margin:10px 0 6px; color:#0284c7;">2. Langkah Penerbitan Surat Jalan:</h4>
            <ol style="padding-left:18px;">
                <li>Pilih Pelanggan / Buyer pada form entri Surat Jalan.</li>
                <li>Tekan tombol <strong>Tarik Pesanan SO (F3)</strong> untuk menampilkan pesanan yang belum selesai dikirim.</li>
                <li>Centang barang yang hendak dikirim hari ini, lalu klik <em>Masukkan ke Surat Jalan</em>.</li>
                <li>Periksa kuantitas kirim, jumlah koli (CTN), berat netto &amp; gross, serta nomor polisi kendaraan dan nama supir pengantar.</li>
                <li>Bila pengiriman melibatkan kepabeanan (Kawasan Berikat), pilih jenis dokumen BC (BC 3.0 / BC 2.7 / BC 4.1) beserta nomor pendaftaran.</li>
                <li>Tekan <strong>Simpan (Ctrl+S)</strong>. Sistem otomatis memperbarui status realisasi kirim di Sales Order.</li>
            </ol>

            <h4 style="margin:10px 0 6px; color:#0284c7;">3. Ketentuan Pengamanan (Guard Lock):</h4>
            <ul style="padding-left:18px;">
                <li>Surat Jalan yang item barangnya <strong>sudah difakturkan ke Sales Invoice (SI)</strong> terkunci otomatis dan tidak dapat dihapus demi menjaga integritas data keuangan dan jejak audit pembukuan.</li>
            </ul>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Saya Mengerti</button>
        </div>
    </div>
</div>

<script>
// -------------------------------------------------------------------------
// STATE & VARIABEL GLOBAL APLIKASI
// -------------------------------------------------------------------------
const CURRENT_CMP_ID = "<?= htmlspecialchars($sessionCmpId) ?>";
let currentOpenSoData = [];
let currentMaterialKlas = 'FG';
let activeMaterialRowTarget = null;
let currentBrowsePage = 1;
const BROWSE_LIMIT = 25;

// Inisialisasi awal saat halaman selesai dimuat
document.addEventListener('DOMContentLoaded', () => {
    initSplitbars();
    loadBrowseData(1);
    setupKeyboardShortcuts();
    initGridContextMenu();

    if (typeof initAntSearchableSelect === 'function') {
        initAntSearchableSelect('doBuyer', { placeholder: 'Ketik nama / kode customer...' });
    }

    // Reaktif ganti tema jika induk portal berganti
    const savedTheme = localStorage.getItem('ceisa_theme');
    if (savedTheme === 'dark') document.body.classList.add('dark-theme');
    window.addEventListener('storage', (e) => {
        if (e.key === 'ceisa_theme') {
            if (e.newValue === 'dark') document.body.classList.add('dark-theme');
            else document.body.classList.remove('dark-theme');
        }
    });
});

// -------------------------------------------------------------------------
// SHORTCUT KEYBOARD (F2, F3, Ctrl+S, Ctrl+I, Ctrl+Del, Ctrl+E)
// -------------------------------------------------------------------------
function setupKeyboardShortcuts() {
    window.addEventListener('keydown', (e) => {
        if (e.key === 'F2') {
            e.preventDefault();
            newDo();
        } else if (e.key === 'F3') {
            e.preventDefault();
            openSoPickerModal();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            saveDo();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i') {
            e.preventDefault();
            addItemRow();
        } else if (e.key === 'Insert') {
            e.preventDefault();
            addItemRow();
        } else if ((e.ctrlKey || e.metaKey) && (e.key === 'Delete' || e.key.toLowerCase() === 'del')) {
            if (contextRow) {
                e.preventDefault();
                ctxHapusBaris();
            }
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'e') {
            if (contextRow) {
                e.preventDefault();
                ctxPilihBarang();
            } else {
                e.preventDefault();
                openMaterialPicker();
            }
        }
    });
}

// -------------------------------------------------------------------------
// RESIZABLE DUAL SPLITBARS (PB STYLE)
// -------------------------------------------------------------------------
function initSplitbars() {
    const paneHdr  = document.getElementById('paneHdr');
    const splitHdr = document.getElementById('splitHdr');
    const paneFtr  = document.getElementById('paneFtr');
    const splitFtr = document.getElementById('splitFtr');

    let isDraggingHdr = false;
    let isDraggingFtr = false;
    let startY = 0;
    let startHdrHeight = 0;
    let startFtrHeight = 0;

    splitHdr.addEventListener('mousedown', (e) => {
        isDraggingHdr = true;
        startY = e.clientY;
        startHdrHeight = paneHdr.offsetHeight;
        document.body.style.cursor = 'row-resize';
        e.preventDefault();
    });

    splitFtr.addEventListener('mousedown', (e) => {
        isDraggingFtr = true;
        startY = e.clientY;
        startFtrHeight = paneFtr.offsetHeight;
        document.body.style.cursor = 'row-resize';
        e.preventDefault();
    });

    window.addEventListener('mousemove', (e) => {
        if (isDraggingHdr) {
            const delta = e.clientY - startY;
            const newH = Math.max(110, Math.min(380, startHdrHeight + delta));
            paneHdr.style.height = `${newH}px`;
        } else if (isDraggingFtr) {
            const delta = startY - e.clientY;
            const newH = Math.max(60, Math.min(220, startFtrHeight + delta));
            paneFtr.style.height = `${newH}px`;
        }
    });

    window.addEventListener('mouseup', () => {
        if (isDraggingHdr || isDraggingFtr) {
            isDraggingHdr = false;
            isDraggingFtr = false;
            document.body.style.cursor = 'default';
        }
    });
}

// -------------------------------------------------------------------------
// TAB SWITCHING LOGIC
// -------------------------------------------------------------------------
function switchTab(idx) {
    for (let i = 1; i <= 3; i++) {
        document.getElementById(`tabBtn${i}`).classList.remove('active');
        document.getElementById(`tabContent${i}`).classList.remove('active');
    }
    document.getElementById(`tabBtn${idx}`).classList.add('active');
    document.getElementById(`tabContent${idx}`).classList.add('active');

    if (idx === 2) {
        loadBrowseData(currentBrowsePage);
    } else if (idx === 3) {
        loadTraceability();
    }
}

// -------------------------------------------------------------------------
// FORM ENTRI DO UTILITIES & RESET
// -------------------------------------------------------------------------
function newDo() {
    document.getElementById('doId').value = '';
    document.getElementById('badgeDoStatus').innerText = 'DRAFT';
    document.getElementById('badgeDoStatus').className = 'ant-badge ant-badge-status';
    document.getElementById('doBuyer').value = '';
    if (document.getElementById('doBuyer').syncAntSelect) document.getElementById('doBuyer').syncAntSelect();
    document.getElementById('doShipto').value = '';
    document.getElementById('doAttn').value = '';
    document.getElementById('doShip').value = 'TRUCK';
    document.getElementById('doNoPolisi').value = '';
    document.getElementById('doDriver').value = '';
    document.getElementById('doNo').value = '';
    document.getElementById('doDt').value = new Date().toISOString().substring(0, 10);
    document.getElementById('doShipDt').value = new Date().toISOString().substring(0, 10);
    document.getElementById('doSoNo').value = '';
    document.getElementById('doPoNo').value = '';
    document.getElementById('doBcDocType').value = '';
    document.getElementById('doNomorAju').value = '';
    document.getElementById('doNomorDaftar').value = '';
    document.getElementById('doTanggalDaftar').value = '';
    document.getElementById('doNote').value = '';
    document.getElementById('tblItemsBody').innerHTML = '';

    generateDoNo();
    recalcItemTotals();
    switchTab(1);
}

async function generateDoNo() {
    try {
        const res = await fetch(`delivery_order.php?action=generate_no&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status === 'OK' && json.do_no) {
            document.getElementById('doNo').value = json.do_no;
        }
    } catch (e) {
        console.error('Gagal generate no DO:', e);
    }
}

function onBuyerChange() {
    const sel = document.getElementById('doBuyer');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('doShipto').value = opt.getAttribute('data-alamat') || '';
        document.getElementById('doAttn').value = opt.getAttribute('data-kontak') || '';
    }
}

// -------------------------------------------------------------------------
// GRID ITEM DELIVERY ORDER (d_do_dtl)
// -------------------------------------------------------------------------
function addItemRow(data = {}) {
    const tbody = document.getElementById('tblItemsBody');
    const seq = tbody.children.length + 1;
    const tr = document.createElement('tr');

    const idSo       = data.id_so || '';
    const soNo       = data.so_no || document.getElementById('doSoNo').value || '';
    const poNo       = data.po_no || document.getElementById('doPoNo').value || '';
    const kodeBarang = data.kode_barang || '';
    const uraian     = data.uraian || '';
    const qty        = parseFloat(data.qty) || 1;
    const sat        = data.sat || 'PCE';
    const ctn        = parseFloat(data.ctn) || 0;
    const berat      = parseFloat(data.berat) || 0;
    let netto        = parseFloat(data.netto) || (berat * qty);
    let gross        = parseFloat(data.gross) || ((ctn / 2) + netto);
    const volume     = parseFloat(data.volume) || 0;
    const colorCd    = data.color_cd || '';
    const sizeCd     = data.size_cd || '';
    const note       = data.note || '';

    tr.className = 'item-row';
    tr.innerHTML = `
        <td style="text-align:center;"><input type="checkbox" class="chk-item"></td>
        <td style="text-align:center;" class="row-seq">${seq}</td>
        <td>
            <div style="display:flex; align-items:center;">
                <input type="text" class="cell-input it-kode" value="${escapeHtml(kodeBarang)}" placeholder="Kode Barang" style="font-weight:600;">
                <button type="button" class="ant-btn ant-btn-sm" onclick="pickMaterialForRow(this)" style="padding:0 4px; height:18px;"><i class="fa-solid fa-ellipsis"></i></button>
            </div>
            <input type="hidden" class="it-idso" value="${escapeHtml(idSo)}">
            <input type="hidden" class="it-sono" value="${escapeHtml(soNo)}">
            <input type="hidden" class="it-pono" value="${escapeHtml(poNo)}">
            <input type="hidden" class="it-berat" value="${berat}">
        </td>
        <td><input type="text" class="cell-input it-uraian" value="${escapeHtml(uraian)}" placeholder="Nama / Uraian Barang"></td>
        <td><input type="number" step="any" class="cell-input it-qty" value="${qty}" style="text-align:right; font-weight:600;" oninput="calcItemRow(this)"></td>
        <td><input type="text" class="cell-input it-sat" value="${escapeHtml(sat)}" style="text-align:center;"></td>
        <td><input type="number" step="any" class="cell-input it-ctn" value="${ctn}" style="text-align:right;" oninput="calcItemRow(this)"></td>
        <td><input type="number" step="any" class="cell-input it-netto" value="${netto.toFixed(2)}" style="text-align:right; color:#10b981;" oninput="recalcItemTotals()"></td>
        <td><input type="number" step="any" class="cell-input it-gross" value="${gross.toFixed(2)}" style="text-align:right; color:#d97706;" oninput="recalcItemTotals()"></td>
        <td><input type="number" step="any" class="cell-input it-vol" value="${volume.toFixed(2)}" style="text-align:right;"></td>
        <td><input type="text" class="cell-input it-color" value="${escapeHtml(colorCd)}"></td>
        <td><input type="text" class="cell-input it-size" value="${escapeHtml(sizeCd)}"></td>
        <td><input type="text" class="cell-input it-note" value="${escapeHtml(note)}" placeholder="Ket."></td>
        <td style="text-align:center;">
            <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteItemRow(this)" style="padding:0 5px; height:18px;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </td>
    `;

    tbody.appendChild(tr);
    recalcItemTotals();
}

function calcItemRow(input) {
    const tr = input.closest('tr');
    const qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
    const ctn = parseFloat(tr.querySelector('.it-ctn').value) || 0;
    const berat = parseFloat(tr.querySelector('.it-berat').value) || 0;

    let netto = qty * berat;
    if (netto <= 0) netto = parseFloat(tr.querySelector('.it-netto').value) || 0;
    
    let gross = (ctn / 2) + netto;
    tr.querySelector('.it-netto').value = netto.toFixed(2);
    tr.querySelector('.it-gross').value = gross.toFixed(2);

    recalcItemTotals();
}

function recalcItemTotals() {
    const rows = document.querySelectorAll('#tblItemsBody tr');
    let totalQty = 0;
    let totalCtn = 0;
    let totalNetto = 0;
    let totalGross = 0;

    rows.forEach((tr, idx) => {
        tr.querySelector('.row-seq').innerText = idx + 1;
        totalQty += parseFloat(tr.querySelector('.it-qty').value) || 0;
        totalCtn += parseFloat(tr.querySelector('.it-ctn').value) || 0;
        totalNetto += parseFloat(tr.querySelector('.it-netto').value) || 0;
        totalGross += parseFloat(tr.querySelector('.it-gross').value) || 0;
    });

    document.getElementById('lblGridItemCount').innerText = rows.length;
    document.getElementById('lblTotalQty').innerText = numberFormat(totalQty);
    document.getElementById('lblTotalCtn').innerText = totalCtn.toFixed(0);
    document.getElementById('lblTotalNetto').innerText = numberFormat(totalNetto);
    document.getElementById('lblTotalGross').innerText = numberFormat(totalGross);
}

function deleteItemRow(btn) {
    btn.closest('tr').remove();
    recalcItemTotals();
}

function removeSelectedRows() {
    const chks = document.querySelectorAll('#tblItemsBody .chk-item:checked');
    if (chks.length === 0) {
        Swal.fire('Informasi', 'Centang baris yang ingin dihapus terlebih dahulu.', 'info');
        return;
    }
    chks.forEach(c => c.closest('tr').remove());
    document.getElementById('chkAllItems').checked = false;
    recalcItemTotals();
}

function toggleSelectAllItems(master) {
    document.querySelectorAll('#tblItemsBody .chk-item').forEach(c => c.checked = master.checked);
}

// -------------------------------------------------------------------------
// SIMPAN TRANSAKSI DO
// -------------------------------------------------------------------------
async function saveDo() {
    const doNo   = document.getElementById('doNo').value.trim();
    const vendor = document.getElementById('doBuyer').value.trim();

    if (!doNo) {
        Swal.fire('Validasi', 'Nomor Surat Jalan (DO) wajib diisi.', 'warning');
        return;
    }
    if (!vendor) {
        Swal.fire('Validasi', 'Pelanggan / Buyer wajib dipilih.', 'warning');
        return;
    }

    const rows = document.querySelectorAll('#tblItemsBody tr');
    if (rows.length === 0) {
        Swal.fire('Validasi', 'Rincian barang Surat Jalan minimal 1 baris.', 'warning');
        return;
    }

    const items = [];
    rows.forEach(tr => {
        const kd = tr.querySelector('.it-kode').value.trim();
        const ur = tr.querySelector('.it-uraian').value.trim();
        if (kd || ur) {
            items.push({
                id_so: tr.querySelector('.it-idso').value.trim(),
                so_no: tr.querySelector('.it-sono').value.trim(),
                po_no: tr.querySelector('.it-pono').value.trim(),
                kode_barang: kd,
                uraian: ur,
                qty: parseFloat(tr.querySelector('.it-qty').value) || 0,
                sat: tr.querySelector('.it-sat').value.trim() || 'PCE',
                ctn: parseFloat(tr.querySelector('.it-ctn').value) || 0,
                netto: parseFloat(tr.querySelector('.it-netto').value) || 0,
                gross: parseFloat(tr.querySelector('.it-gross').value) || 0,
                volume: parseFloat(tr.querySelector('.it-vol').value) || 0,
                color_cd: tr.querySelector('.it-color').value.trim(),
                size_cd: tr.querySelector('.it-size').value.trim(),
                note: tr.querySelector('.it-note').value.trim()
            });
        }
    });

    const payload = {
        id: document.getElementById('doId').value.trim(),
        header: {
            do_no: doNo,
            do_dt: document.getElementById('doDt').value,
            ship_dt: document.getElementById('doShipDt').value,
            vendor: vendor,
            shipto: document.getElementById('doShipto').value.trim(),
            attn: document.getElementById('doAttn').value.trim(),
            ship: document.getElementById('doShip').value,
            no_polisi: document.getElementById('doNoPolisi').value.trim(),
            driver_name: document.getElementById('doDriver').value.trim(),
            so_no: document.getElementById('doSoNo').value.trim(),
            po_no: document.getElementById('doPoNo').value.trim(),
            bc_doc_type: document.getElementById('doBcDocType').value,
            nomor_aju: document.getElementById('doNomorAju').value.trim(),
            nomor_daftar: document.getElementById('doNomorDaftar').value.trim(),
            tanggal_daftar: document.getElementById('doTanggalDaftar').value,
            note: document.getElementById('doNote').value.trim()
        },
        items: items
    };

    Swal.fire({ title: 'Menyimpan Surat Jalan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const res = await fetch(`delivery_order.php?action=save&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        Swal.close();

        if (json.status === 'OK') {
            document.getElementById('doId').value = json.id;
            Swal.fire('Berhasil', json.message || 'Surat Jalan berhasil disimpan.', 'success');
        } else {
            Swal.fire('Error', json.message || 'Gagal menyimpan Surat Jalan.', 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Terjadi kesalahan sistem saat menyimpan data.', 'error');
    }
}

// -------------------------------------------------------------------------
// HAPUS DELIVERY ORDER
// -------------------------------------------------------------------------
async function deleteDo() {
    const id   = document.getElementById('doId').value.trim();
    const doNo = document.getElementById('doNo').value.trim();

    if (!id) {
        Swal.fire('Informasi', 'Pilih dokumen Surat Jalan yang ingin dihapus terlebih dahulu.', 'info');
        return;
    }

    const conf = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Yakin ingin menghapus Surat Jalan ${doNo}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Ya, Hapus!'
    });
    if (!conf.isConfirmed) return;

    Swal.fire({ title: 'Menghapus Surat Jalan...', didOpen: () => Swal.showLoading() });

    try {
        const res = await fetch(`delivery_order.php?action=delete&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        Swal.close();

        if (json.status === 'OK') {
            Swal.fire('Dihapus', json.message || 'Surat Jalan berhasil dihapus.', 'success');
            newDo();
        } else {
            Swal.fire('Gagal Hapus', json.message, 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Terjadi kesalahan koneksi server.', 'error');
    }
}

// -------------------------------------------------------------------------
// BROWSE TAB: LOAD DATA & 4 KPI STRIP
// -------------------------------------------------------------------------
async function loadBrowseData(page = 1) {
    currentBrowsePage = page;
    const startDt = document.getElementById('filterStartDt').value;
    const endDt   = document.getElementById('filterEndDt').value;
    const status  = document.getElementById('filterStatus').value;
    const search  = document.getElementById('filterSearch').value.trim();

    const tbody = document.getElementById('tblBrowseBody');
    tbody.innerHTML = `<tr><td colspan="14" style="text-align:center; padding:15px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat daftar Surat Jalan...</td></tr>`;

    try {
        const url = `delivery_order.php?action=list&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}&page=${page}&limit=${BROWSE_LIMIT}&start_date=${encodeURIComponent(startDt)}&end_date=${encodeURIComponent(endDt)}&status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;
        const res = await fetch(url);
        const json = await res.json();

        // Update KPI Strip
        const k = json.kpi;
        if (k) {
            document.getElementById('kpiTotalDo').innerText = `${k.total_do} DO`;
            document.getElementById('kpiOpenDo').innerText = `${k.open_do} DO`;
            document.getElementById('kpiClosedDo').innerText = `${k.closed_do} DO`;
            document.getElementById('kpiTotalQty').innerText = `${numberFormat(k.total_qty)} PCE`;
        }

        tbody.innerHTML = '';
        if (!json.data || json.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="14" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada dokumen Surat Jalan ditemukan.</td></tr>`;
            document.getElementById('lblPageRange').innerText = '0-0';
            document.getElementById('lblTotalRows').innerText = '0';
            return;
        }

        const startIdx = (page - 1) * BROWSE_LIMIT + 1;
        const endIdx   = Math.min(json.total, startIdx + json.data.length - 1);
        document.getElementById('lblPageRange').innerText = `${startIdx}-${endIdx}`;
        document.getElementById('lblTotalRows').innerText = json.total;
        document.getElementById('lblCurrentPage').innerText = page;

        json.data.forEach((r, idx) => {
            const tr = document.createElement('tr');
            tr.setAttribute('ondblclick', `loadDoForEdit('${r.id}')`);

            let stBadge = `<span class="ant-badge" style="background:#fef3c7; color:#d97706;">OPEN</span>`;
            if (r.status === '1') stBadge = `<span class="ant-badge" style="background:#e0f2fe; color:#0369a1;">PARSIAL</span>`;
            else if (r.status === '2') stBadge = `<span class="ant-badge" style="background:#ecfdf5; color:#10b981;">CLOSED</span>`;
            else if (r.status === '3') stBadge = `<span class="ant-badge" style="background:#fee2e2; color:#ef4444;">BATAL</span>`;

            tr.innerHTML = `
                <td style="text-align:center;">${startIdx + idx}</td>
                <td style="font-weight:600; color:#0284c7;">${escapeHtml(r.do_no)}</td>
                <td style="text-align:center;">${escapeHtml(r.do_dt)}</td>
                <td style="font-weight:500;">${escapeHtml(r.buyer_nama)}</td>
                <td>${escapeHtml(r.no_polisi || '-')}</td>
                <td>${escapeHtml(r.driver_name || '-')}</td>
                <td>${escapeHtml(r.so_no || '-')}</td>
                <td>${escapeHtml(r.po_no || '-')}</td>
                <td style="text-align:right;">${r.item_count}</td>
                <td style="text-align:right; font-weight:600;">${numberFormat(r.total_qty)}</td>
                <td style="text-align:center;">${escapeHtml(r.bc_doc_type || '-')}</td>
                <td>${escapeHtml(r.nomor_daftar || '-')}</td>
                <td style="text-align:center;">${stBadge}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="loadDoForEdit('${r.id}')" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="window.open('delivery_order.php?action=print&id=${encodeURIComponent(r.id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}', '_blank')" title="Print"><i class="fa-solid fa-print"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="14" style="text-align:center; padding:15px; color:var(--danger);">Gagal memuat data dari server.</td></tr>`;
    }
}

function resetBrowseFilter() {
    document.getElementById('filterStartDt').value = "<?= date('Y-m-01') ?>";
    document.getElementById('filterEndDt').value   = "<?= date('Y-m-d') ?>";
    document.getElementById('filterStatus').value  = "all";
    document.getElementById('filterSearch').value  = "";
    loadBrowseData(1);
}

function changePage(delta) {
    const newPage = currentBrowsePage + delta;
    if (newPage >= 1) loadBrowseData(newPage);
}

// -------------------------------------------------------------------------
// EDIT DO DARI BROWSE DATA
// -------------------------------------------------------------------------
async function loadDoForEdit(id) {
    Swal.fire({ title: 'Memuat data Surat Jalan...', didOpen: () => Swal.showLoading() });
    try {
        const res = await fetch(`delivery_order.php?action=get_do&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        Swal.close();

        if (json.status !== 'OK' || !json.header) {
            Swal.fire('Error', json.message || 'Gagal memuat detail Surat Jalan.', 'error');
            return;
        }

        const h = json.header;
        document.getElementById('doId').value = h.id;
        document.getElementById('doNo').value = h.do_no;
        document.getElementById('doDt').value = h.do_dt;
        document.getElementById('doShipDt').value = h.ship_dt || h.do_dt;
        document.getElementById('doBuyer').value = h.vendor;
        document.getElementById('doShipto').value = h.shipto || '';
        document.getElementById('doAttn').value = h.attn || '';
        document.getElementById('doShip').value = h.ship || 'TRUCK';
        document.getElementById('doNoPolisi').value = h.no_polisi || '';
        document.getElementById('doDriver').value = h.driver_name || '';
        document.getElementById('doSoNo').value = h.so_no || '';
        document.getElementById('doPoNo').value = h.po_no || '';
        document.getElementById('doBcDocType').value = h.bc_doc_type || '';
        document.getElementById('doNomorAju').value = h.nomor_aju || '';
        document.getElementById('doNomorDaftar').value = h.nomor_daftar || '';
        document.getElementById('doTanggalDaftar').value = h.tanggal_daftar || '';
        document.getElementById('doNote').value = h.note || '';

        const badge = document.getElementById('badgeDoStatus');
        if (h.status === '1') {
            badge.innerText = 'PARSIAL SI';
            badge.style.background = '#e0f2fe';
            badge.style.color = '#0369a1';
        } else if (h.status === '2') {
            badge.innerText = 'CLOSED (INVOICED)';
            badge.style.background = '#ecfdf5';
            badge.style.color = '#10b981';
        } else if (h.status === '3') {
            badge.innerText = 'BATAL';
            badge.style.background = '#fee2e2';
            badge.style.color = '#ef4444';
        } else {
            badge.innerText = 'OPEN (BELUM SI)';
            badge.style.background = '#fef3c7';
            badge.style.color = '#d97706';
        }

        const tbody = document.getElementById('tblItemsBody');
        tbody.innerHTML = '';
        if (json.items) {
            json.items.forEach(it => {
                addItemRow({
                    id_so: it.id_so,
                    so_no: it.so_no,
                    po_no: it.po_no,
                    kode_barang: it.kode_barang,
                    uraian: it.uraian,
                    qty: it.qty,
                    sat: it.sat,
                    ctn: it.ctn,
                    netto: it.netto,
                    gross: it.gross,
                    volume: it.volume,
                    color_cd: it.color_cd,
                    size_cd: it.size_cd,
                    note: it.note
                });
            });
        }
        recalcItemTotals();
        switchTab(1);
    } catch (e) {
        Swal.fire('Error', 'Gagal memuat dokumen dari server.', 'error');
    }
}

// -------------------------------------------------------------------------
// MODAL: TARIK BARANG DARI SALES ORDER (SO) - ddw_so_belum_do
// -------------------------------------------------------------------------
function openSoPickerModal() {
    document.getElementById('modalSoPicker').style.display = 'flex';
    loadOpenSoItems();
}

function closeSoPickerModal() {
    document.getElementById('modalSoPicker').style.display = 'none';
}

async function loadOpenSoItems() {
    const vendor = document.getElementById('doBuyer').value.trim();
    const search = document.getElementById('txtSearchOpenSo').value.trim();
    const tbody  = document.getElementById('tblOpenSoBody');

    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:15px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mencari Sales Order yang belum selesai kirim...</td></tr>`;

    try {
        const url = `delivery_order.php?action=search_so_open&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(search)}`;
        const res = await fetch(url);
        const json = await res.json();

        currentOpenSoData = json.data || [];
        tbody.innerHTML = '';

        if (currentOpenSoData.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada pesanan Sales Order yang belum selesai dikirim untuk kriteria ini.</td></tr>`;
            return;
        }

        currentOpenSoData.forEach((it, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="text-align:center;"><input type="checkbox" class="chk-so-item" value="${idx}"></td>
                <td style="font-weight:600; color:#0284c7;">${escapeHtml(it.so_no)}</td>
                <td style="text-align:center;">${escapeHtml(it.so_dt)}</td>
                <td>${escapeHtml(it.po_no || '-')}</td>
                <td>
                    <div style="font-weight:600;">${escapeHtml(it.kode_barang)}</div>
                    <div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(it.uraian)}</div>
                </td>
                <td style="text-align:right;">${numberFormat(it.qty_order)}</td>
                <td style="text-align:right;">${numberFormat(it.qty_terkirim)}</td>
                <td style="text-align:right; font-weight:700; color:#0284c7;">${numberFormat(it.sisa_kirim)}</td>
                <td style="text-align:center;">${escapeHtml(it.sat)}</td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:15px; color:var(--danger);">Gagal mencari Sales Order.</td></tr>`;
    }
}

function toggleSelectAllOpenSo(master) {
    document.querySelectorAll('.chk-so-item').forEach(c => c.checked = master.checked);
}

function importSelectedSoItems() {
    const chks = document.querySelectorAll('.chk-so-item:checked');
    if (chks.length === 0) {
        Swal.fire('Informasi', 'Centang minimal satu baris barang pesanan.', 'info');
        return;
    }

    chks.forEach(c => {
        const idx = parseInt(c.value);
        const it = currentOpenSoData[idx];
        if (it) {
            addItemRow({
                id_so: it.id_so,
                so_no: it.so_no,
                po_no: it.po_no,
                kode_barang: it.kode_barang,
                uraian: it.uraian,
                qty: it.sisa_kirim,
                sat: it.sat,
                berat: it.berat,
                color_cd: it.color_cd,
                size_cd: it.size_cd
            });

            // Set vendor jika masih kosong
            if (!document.getElementById('doBuyer').value && it.vendor) {
                document.getElementById('doBuyer').value = it.vendor;
                onBuyerChange();
            }
            if (!document.getElementById('doSoNo').value && it.so_no) {
                document.getElementById('doSoNo').value = it.so_no;
            }
            if (!document.getElementById('doPoNo').value && it.po_no) {
                document.getElementById('doPoNo').value = it.po_no;
            }
        }
    });

    closeSoPickerModal();
    recalcItemTotals();
}

// -------------------------------------------------------------------------
// MODAL MASTER BARANG 5 KLASIFIKASI
// -------------------------------------------------------------------------
function openMaterialPicker(targetInput = null) {
    activeMaterialRowTarget = targetInput;
    document.getElementById('modalMaterialPicker').style.display = 'flex';
    searchMaterials();
}

function closeMaterialPicker() {
    document.getElementById('modalMaterialPicker').style.display = 'none';
    activeMaterialRowTarget = null;
}

function pickMaterialForRow(btn) {
    openMaterialPicker(btn.closest('tr'));
}

function switchMaterialKlas(klas) {
    currentMaterialKlas = klas;
    ['FG', 'RM', 'WI', 'CG', 'W'].forEach(k => {
        const el = document.getElementById(`btnKlas${k}`);
        if (el) {
            if (k === klas) el.classList.add('ant-btn-primary');
            else el.classList.remove('ant-btn-primary');
        }
    });
    searchMaterials();
}

async function searchMaterials() {
    const q = document.getElementById('txtSearchMaterial').value.trim();
    const tbody = document.getElementById('tblMaterialPickerBody');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat master barang...</td></tr>`;

    try {
        const url = `delivery_order.php?action=search_materials&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}&klas=${encodeURIComponent(currentMaterialKlas)}&q=${encodeURIComponent(q)}`;
        const res = await fetch(url);
        const json = await res.json();

        tbody.innerHTML = '';
        if (!json.data || json.data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:15px; color:var(--text-muted);">Tidak ada barang ditemukan.</td></tr>`;
            return;
        }

        json.data.forEach(it => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-weight:600;">${escapeHtml(it.kode_barang)}</td>
                <td>${escapeHtml(it.uraian)}</td>
                <td style="text-align:center;">${escapeHtml(it.satuan)}</td>
                <td style="text-align:right;">${numberFormat(it.netto)}</td>
                <td>${escapeHtml(it.hs_code || '-')}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick='selectMaterialItem(${JSON.stringify(it)})'>Pilih</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:15px; color:var(--danger);">Gagal mencari barang.</td></tr>`;
    }
}

function selectMaterialItem(it) {
    if (activeMaterialRowTarget) {
        activeMaterialRowTarget.querySelector('.it-kode').value = it.kode_barang;
        activeMaterialRowTarget.querySelector('.it-uraian').value = it.uraian;
        activeMaterialRowTarget.querySelector('.it-sat').value = it.satuan || 'PCE';
        activeMaterialRowTarget.querySelector('.it-berat').value = it.netto || 0;
        calcItemRow(activeMaterialRowTarget.querySelector('.it-qty'));
    } else {
        addItemRow({
            kode_barang: it.kode_barang,
            uraian: it.uraian,
            sat: it.satuan || 'PCE',
            berat: it.netto || 0,
            qty: 1
        });
    }

    if (!document.getElementById('chkMaterialMulti').checked) {
        closeMaterialPicker();
    }
}

// -------------------------------------------------------------------------
// TAB 3: TRACEABILITY PENGIRIMAN
// -------------------------------------------------------------------------
async function loadTraceability() {
    const id = document.getElementById('doId').value.trim();
    if (!id) {
        document.getElementById('traceSoList').innerHTML = `<div style="color:var(--text-muted); text-align:center; padding:20px 0;">Pilih atau simpan Surat Jalan terlebih dahulu untuk melihat jejak pengiriman.</div>`;
        return;
    }

    try {
        const res = await fetch(`delivery_order.php?action=trace&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();

        if (json.status !== 'OK') return;

        const h = json.header;
        document.getElementById('traceSoNo').innerText = h.so_no || '-';

        // 1. SO List
        let soHtml = '';
        if (json.so_list && json.so_list.length > 0) {
            json.so_list.forEach(s => {
                soHtml += `
                    <div style="border:1px solid var(--border-color); border-radius:3px; padding:6px; margin-bottom:6px;">
                        <div style="font-weight:600; color:#10b981;">${escapeHtml(s.so_no)}</div>
                        <div style="color:var(--text-muted);">Tgl: ${escapeHtml(s.so_dt)} | Ref PO: ${escapeHtml(s.po_no || '-')}</div>
                        <div>Nilai SO: <strong>Rp ${numberFormat(s.total_order)}</strong></div>
                    </div>
                `;
            });
        } else {
            soHtml = `<div style="color:var(--text-muted); text-align:center; padding:15px;">Tidak ada referensi SO langsung.</div>`;
        }
        document.getElementById('traceSoList').innerHTML = soHtml;

        // 2. DO Active Items
        let doHtml = '';
        if (json.details && json.details.length > 0) {
            json.details.forEach(d => {
                doHtml += `
                    <div style="border:1px solid var(--border-color); border-radius:3px; padding:5px 8px; margin-bottom:5px;">
                        <div style="font-weight:600;">${escapeHtml(d.kode_barang)}</div>
                        <div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(d.uraian)}</div>
                        <div style="display:flex; justify-content:space-between; margin-top:2px;">
                            <span>Kirim: <strong>${numberFormat(d.qty)} ${escapeHtml(d.sat)}</strong></span>
                            <span>Koli: <strong>${d.ctn || 0} CTN</strong></span>
                        </div>
                    </div>
                `;
            });
        }
        document.getElementById('traceDoItems').innerHTML = doHtml;

        // 3. SI List
        let siHtml = '';
        document.getElementById('traceSiCount').innerText = `${json.si_list.length} SI`;
        if (json.si_list && json.si_list.length > 0) {
            json.si_list.forEach(si => {
                siHtml += `
                    <div style="border:1px solid var(--border-color); border-radius:3px; padding:6px; margin-bottom:6px; background:#faf5ff;">
                        <div style="font-weight:600; color:#8b5cf6;">${escapeHtml(si.si_no)}</div>
                        <div style="color:var(--text-muted);">Tgl Faktur: ${escapeHtml(si.si_dt)}</div>
                        <div>Total Tagihan: <strong>Rp ${numberFormat(si.total_order)}</strong></div>
                        <div>Terbayar: <strong style="color:#10b981;">Rp ${numberFormat(si.terbayar)}</strong></div>
                    </div>
                `;
            });
        } else {
            siHtml = `<div style="color:var(--text-muted); text-align:center; padding:15px;">Surat Jalan ini belum ditagih di Faktur Penjualan (SI).</div>`;
        }
        document.getElementById('traceSiList').innerHTML = siHtml;
    } catch (e) {
        console.error('Gagal memuat traceability:', e);
    }
}

// -------------------------------------------------------------------------
// PRINT & EXCEL UTILITIES
// -------------------------------------------------------------------------
function printDo() {
    const id = document.getElementById('doId').value.trim();
    if (!id) {
        Swal.fire('Informasi', 'Pilih atau simpan Surat Jalan terlebih dahulu.', 'info');
        return;
    }
    window.open(`delivery_order.php?action=print&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function toggleExcelMenu() {
    const el = document.getElementById('excelDrop');
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}

function exportExcelSheetJS() {
    const tbl = document.getElementById('tblItems');
    if (!tbl) return;
    try {
        const wb = XLSX.utils.table_to_book(tbl, { sheet: "DO_Items" });
        const doNo = document.getElementById('doNo').value || 'DO';
        XLSX.writeFile(wb, `${doNo}_Items_${Date.now()}.xlsx`);
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

function exportBrowseExcel() {
    const tbl = document.getElementById('tblBrowse');
    if (!tbl) return;
    try {
        const wb = XLSX.utils.table_to_book(tbl, { sheet: "Daftar_DO" });
        XLSX.writeFile(wb, `Daftar_Surat_Jalan_${Date.now()}.xlsx`);
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

function openModuleGuide() {
    document.getElementById('modalGuide').style.display = 'flex';
}

function closeModuleGuide() {
    document.getElementById('modalGuide').style.display = 'none';
}

function numberFormat(val, decimals = 2) {
    const num = parseFloat(val) || 0;
    return num.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

// -------------------------------------------------------------------------
// ENTERPRISE CONTEXT MENU (KLIK KANAN SEPERTI SALES INVOICE)
// -------------------------------------------------------------------------
let contextRow = null;

function initGridContextMenu() {
    const table = document.getElementById('tblItems');
    const menu  = document.getElementById('gridContextMenu');
    if (!table || !menu) return;

    table.addEventListener('contextmenu', (e) => {
        const row = e.target.closest('#tblItemsBody tr');
        if (!row) return;
        e.preventDefault();
        e.stopPropagation();

        contextRow = row;
        document.querySelectorAll('#tblItemsBody tr').forEach(r => r.classList.remove('context-active'));
        row.classList.add('context-active');

        // Buka context menu dengan viewport clamping
        menu.style.display = 'block';
        const menuWidth = 240;
        const menuHeight = 280;
        let left = e.clientX;
        let top = e.clientY;

        if (left + menuWidth > window.innerWidth) {
            left = window.innerWidth - menuWidth - 10;
        }
        if (top + menuHeight > window.innerHeight) {
            top = window.innerHeight - menuHeight - 10;
        }
        menu.style.left = Math.max(10, left) + 'px';
        menu.style.top = Math.max(10, top) + 'px';
    });

    document.addEventListener('click', (e) => {
        if (!menu.contains(e.target)) {
            hideContextMenu();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hideContextMenu();
        }
    });
}

function hideContextMenu() {
    const menu = document.getElementById('gridContextMenu');
    if (menu) menu.style.display = 'none';
    if (contextRow) {
        contextRow.classList.remove('context-active');
    }
}

function ctxTambahBaru() {
    hideContextMenu();
    addItemRow();
}

function ctxHapusBaris() {
    hideContextMenu();
    if (!contextRow) return;
    contextRow.remove();
    recalcItemTotals();
}

function ctxPilihBarang() {
    hideContextMenu();
    if (!contextRow) return;
    openMaterialPicker(contextRow);
}

function ctxTarikSo() {
    hideContextMenu();
    openSoPickerModal();
}

function ctxSisipkanAtas() {
    hideContextMenu();
    if (!contextRow) return;
    addItemRow();
    const rows = document.querySelectorAll('#tblItemsBody tr');
    const newRow = rows[rows.length - 1];
    contextRow.parentNode.insertBefore(newRow, contextRow);
    recalcItemTotals();
}

function ctxSisipkanBawah() {
    hideContextMenu();
    if (!contextRow) return;
    addItemRow();
    const rows = document.querySelectorAll('#tblItemsBody tr');
    const newRow = rows[rows.length - 1];
    contextRow.parentNode.insertBefore(newRow, contextRow.nextSibling);
    recalcItemTotals();
}

function ctxDuplikasiBaris() {
    hideContextMenu();
    if (!contextRow) return;
    const data = {
        id_so: contextRow.querySelector('.it-idso')?.value || '',
        so_no: contextRow.querySelector('.it-sono')?.value || '',
        po_no: contextRow.querySelector('.it-pono')?.value || '',
        kode_barang: contextRow.querySelector('.it-kode')?.value || '',
        uraian: contextRow.querySelector('.it-uraian')?.value || '',
        qty: parseFloat(contextRow.querySelector('.it-qty')?.value) || 1,
        sat: contextRow.querySelector('.it-sat')?.value || 'PCE',
        ctn: parseFloat(contextRow.querySelector('.it-ctn')?.value) || 0,
        netto: parseFloat(contextRow.querySelector('.it-netto')?.value) || 0,
        gross: parseFloat(contextRow.querySelector('.it-gross')?.value) || 0,
        volume: parseFloat(contextRow.querySelector('.it-vol')?.value) || 0,
        color_cd: contextRow.querySelector('.it-color')?.value || '',
        size_cd: contextRow.querySelector('.it-size')?.value || '',
        note: contextRow.querySelector('.it-note')?.value || '',
        berat: parseFloat(contextRow.querySelector('.it-berat')?.value) || 0
    };
    addItemRow(data);
    const rows = document.querySelectorAll('#tblItemsBody tr');
    const newRow = rows[rows.length - 1];
    contextRow.parentNode.insertBefore(newRow, contextRow.nextSibling);
    recalcItemTotals();
}

function ctxSalinBaris() {
    hideContextMenu();
    if (!contextRow) return;
    const cols = [
        contextRow.querySelector('.row-seq')?.innerText || '',
        contextRow.querySelector('.it-kode')?.value || '',
        contextRow.querySelector('.it-uraian')?.value || '',
        contextRow.querySelector('.it-qty')?.value || '',
        contextRow.querySelector('.it-sat')?.value || '',
        contextRow.querySelector('.it-ctn')?.value || '',
        contextRow.querySelector('.it-netto')?.value || '',
        contextRow.querySelector('.it-gross')?.value || '',
        contextRow.querySelector('.it-vol')?.value || '',
        contextRow.querySelector('.it-color')?.value || '',
        contextRow.querySelector('.it-size')?.value || '',
        contextRow.querySelector('.it-note')?.value || ''
    ];
    navigator.clipboard.writeText(cols.join('\t')).then(() => {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Baris disalin ke clipboard', showConfirmButton: false, timer: 1500 });
    });
}
</script>

<!-- Context Menu Grid (Klik Kanan PB Style - seperti Sales Invoice) -->
<div id="gridContextMenu" class="context-menu" style="display:none;" onclick="event.stopPropagation()">
    <div class="ctx-item" onclick="ctxTambahBaru()"><i class="fa-solid fa-plus" style="color:#0284c7;"></i> <span>Baru (Tambah Baris)</span> <kbd>Ctrl+I / Insert</kbd></div>
    <div class="ctx-item" onclick="ctxHapusBaris()"><i class="fa-solid fa-xmark" style="color:#ef4444;"></i> <span>Hapus Baris</span> <kbd>Ctrl+Del / Del</kbd></div>
    <div class="ctx-item" onclick="ctxPilihBarang()"><i class="fa-solid fa-boxes-stacked" style="color:#10b981;"></i> <span>Pilih dari Master Barang</span> <kbd>Ctrl+E</kbd></div>
    <div class="ctx-item" onclick="ctxTarikSo()"><i class="fa-solid fa-cart-arrow-down" style="color:#0284c7;"></i> <span>Tarik Pesanan SO</span> <kbd>F3</kbd></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxSisipkanAtas()"><i class="fa-solid fa-arrow-up" style="color:#64748b;"></i> <span>Sisipkan Di Atas</span></div>
    <div class="ctx-item" onclick="ctxSisipkanBawah()"><i class="fa-solid fa-arrow-down" style="color:#64748b;"></i> <span>Sisipkan Di Bawah</span></div>
    <div class="ctx-item" onclick="ctxDuplikasiBaris()"><i class="fa-solid fa-copy" style="color:#8b5cf6;"></i> <span>Duplikasi Baris</span></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxSalinBaris()"><i class="fa-regular fa-clipboard" style="color:#f59e0b;"></i> <span>Salin Baris Terpilih (TSV)</span></div>
    <div class="ctx-item" onclick="exportExcelSheetJS(); hideContextMenu();"><i class="fa-solid fa-file-excel" style="color:#10b981;"></i> <span>Ekspor Rincian ke Excel (.xlsx)</span></div>
</div>

</body>
</html>
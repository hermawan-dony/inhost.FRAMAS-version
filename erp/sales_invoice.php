<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Sales Invoice (SI) / Faktur Penjualan
 * Hasil Porting Sempurna dari PowerBuilder Desktop TPB-ERP v2.0 (w_si.srw / bc_si_hdr.srd / d_si_dtl.srd / bc_si_hdr2.srd / bc_si_sumbc.srd / w_trace_si.srw)
 * Terhubung ke tabel bc_si_hdr & bc_si_dtl, Tarik DO (bc_do_hdr) & Tarik SO (bc_so_hdr),
 * Kunci & Posting IT Inventory (bc_1ab2), Rekap Dokumen Pabean (PEB 3.0, BC 4.1, BC 2.7, BC 2.5),
 * Trace Issue Produksi & Bahan Baku Asal (bc_si_dtl2 / bc_issue2 / bc_si_aju), Cetak Commercial Invoice Resmi, dan CSV E-Faktur.
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

$activeCmpName = $_SESSION['company_name'] ?? '';
if (empty($activeCmpName) && !empty($sessionCmpId)) {
    $qCmp = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        $activeCmpName = $rCmp['cmp_name'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'PT. PERUSAHAAN';

// Auto-Heal: Pastikan tabel dan kolom bc_si_hdr dan bc_si_dtl tersedia sesuai PB
function ensureSiTables($koneksi) {
    // 1. Table bc_si_hdr
    $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_si_hdr'");
    if (!$checkHdr || $checkHdr->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_si_hdr` (
            `id` varchar(40) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `si_no` varchar(50) NOT NULL DEFAULT '',
            `si_dt` date DEFAULT NULL,
            `ship_dt` date DEFAULT NULL,
            `jatuhtempo` date DEFAULT NULL,
            `po_no` varchar(300) DEFAULT '',
            `so_no` varchar(50) DEFAULT '',
            `do_no` varchar(50) DEFAULT '',
            `fob` varchar(20) DEFAULT '1',
            `term` varchar(50) DEFAULT 'Net 30',
            `ship` varchar(50) DEFAULT '',
            `vendor` varchar(50) NOT NULL DEFAULT '',
            `shipto` varchar(50) DEFAULT '',
            `vendoristaxable` char(1) DEFAULT 'Y',
            `inclusivetax` char(1) DEFAULT 'N',
            `issubcon` char(1) DEFAULT 'N',
            `tipe_subkon` varchar(10) DEFAULT '0',
            `ncv` char(1) DEFAULT '0',
            `bill2buyer` char(1) DEFAULT 'N',
            `tujuan_kirim` varchar(100) DEFAULT '',
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
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cmp_si` (`cmp_id`, `si_no`),
            KEY `idx_buyer` (`vendor`),
            KEY `idx_si_dt` (`si_dt`),
            KEY `idx_so_no` (`so_no`),
            KEY `idx_do_no` (`do_no`),
            KEY `idx_no_fp` (`no_fp`),
            KEY `idx_bc` (`bc`, `no_bc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $hdrCols = [];
    $hdrColRes = $koneksi->query("SHOW COLUMNS FROM bc_si_hdr");
    if ($hdrColRes) while ($r = $hdrColRes->fetch_assoc()) $hdrCols[strtolower($r['Field'])] = true;
    $hdrExtra = [
        'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
        'so_no' => "varchar(50) DEFAULT ''",
        'po_no' => "varchar(300) DEFAULT ''",
        'do_no' => "varchar(50) DEFAULT ''",
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
        'tipe_subkon' => "varchar(10) DEFAULT '0'",
        'tujuan_kirim' => "varchar(100) DEFAULT ''",
        'ship_dt' => "date DEFAULT NULL",
        'vendoristaxable' => "char(1) DEFAULT 'Y'",
        'inclusivetax' => "char(1) DEFAULT 'N'",
        'est_freight' => "decimal(18,4) DEFAULT '0.0000'",
    ];
    foreach ($hdrExtra as $colName => $colDef) {
        if (!isset($hdrCols[$colName])) {
            try {
                $koneksi->query("ALTER TABLE bc_si_hdr ADD COLUMN `$colName` $colDef");
            } catch (Throwable $e) {}
        }
    }

    // 2. Table bc_si_dtl
    $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_si_dtl'");
    if (!$checkDtl || $checkDtl->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_si_dtl` (
            `id` varchar(40) NOT NULL DEFAULT '',
            `id_hdr` varchar(40) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `si_no` varchar(50) NOT NULL DEFAULT '',
            `seq` int(11) NOT NULL DEFAULT '1',
            `id_so` varchar(40) DEFAULT '',
            `so_no` varchar(50) DEFAULT '',
            `id_do` varchar(40) DEFAULT '',
            `do_no` varchar(50) DEFAULT '',
            `kode_barang` varchar(50) NOT NULL DEFAULT '',
            `uraian` varchar(255) NOT NULL DEFAULT '',
            `qty` decimal(18,4) DEFAULT '0.0000',
            `sat` varchar(20) DEFAULT '',
            `price` decimal(18,4) DEFAULT '0.0000',
            `disc` decimal(10,2) DEFAULT '0.00',
            `amount` decimal(18,4) DEFAULT '0.0000',
            `cif` decimal(18,4) DEFAULT '0.0000',
            `cif_rupiah` decimal(18,4) DEFAULT '0.0000',
            `price_jasa` decimal(18,4) DEFAULT '0.0000',
            `amt_jasa` decimal(18,4) DEFAULT '0.0000',
            `netto` decimal(18,4) DEFAULT '0.0000',
            `gross` decimal(18,4) DEFAULT '0.0000',
            `note` text DEFAULT NULL,
            `status` char(1) DEFAULT '0',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hdr` (`id_hdr`),
            KEY `idx_cmp_si_dtl` (`cmp_id`, `si_no`),
            KEY `idx_kode_barang` (`kode_barang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $colsD = [];
        $resD = $koneksi->query("SHOW COLUMNS FROM bc_si_dtl");
        if ($resD) while ($r = $resD->fetch_assoc()) $colsD[strtolower($r['Field'])] = true;
        $dtlExtra = [
            'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
            'si_no' => "varchar(50) NOT NULL DEFAULT ''",
            'so_no' => "varchar(50) DEFAULT ''",
            'po_no' => "varchar(50) DEFAULT ''",
            'id_do' => "varchar(40) DEFAULT ''",
            'do_no' => "varchar(50) DEFAULT ''",
            'color_cd' => "varchar(50) DEFAULT ''",
            'size_cd' => "varchar(50) DEFAULT ''",
            'ctn' => "decimal(18,4) DEFAULT '0.0000'",
            'plb' => "decimal(18,4) DEFAULT '0.0000'",
            'volume' => "decimal(18,4) DEFAULT '0.0000'",
            'gudang' => "varchar(50) DEFAULT 'G-FG'",
            'pos_tarif' => "varchar(50) DEFAULT ''",
            'hpp' => "decimal(18,4) DEFAULT '0.0000'",
            'price_jasa' => "decimal(18,4) DEFAULT '0.0000'",
            'amt_jasa' => "decimal(18,4) DEFAULT '0.0000'",
            'netto' => "decimal(18,4) DEFAULT '0.0000'",
            'gross' => "decimal(18,4) DEFAULT '0.0000'",
        ];
        foreach ($dtlExtra as $colName => $colDef) {
            if (!isset($colsD[$colName])) {
                try {
                    $koneksi->query("ALTER TABLE bc_si_dtl ADD COLUMN `$colName` $colDef");
                } catch (Throwable $e) {}
            }
        }
    }
}

ensureSiTables($koneksi);

// Fungsi Terbilang Indonesia (mengadopsi f_terbilangkoma dari PB)
function terbilangIndonesia($nilai) {
    $nilai = abs((float)$nilai);
    $huruf = ["", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"];
    $temp = "";
    if ($nilai < 12) {
        $temp = " " . $huruf[(int)$nilai];
    } else if ($nilai < 20) {
        $temp = terbilangIndonesia($nilai - 10) . " Belas";
    } else if ($nilai < 100) {
        $temp = terbilangIndonesia((int)($nilai / 10)) . " Puluh" . terbilangIndonesia($nilai % 10);
    } else if ($nilai < 200) {
        $temp = " Seratus" . terbilangIndonesia($nilai - 100);
    } else if ($nilai < 1000) {
        $temp = terbilangIndonesia((int)($nilai / 100)) . " Ratus" . terbilangIndonesia($nilai % 100);
    } else if ($nilai < 2000) {
        $temp = " Seribu" . terbilangIndonesia($nilai - 1000);
    } else if ($nilai < 1000000) {
        $temp = terbilangIndonesia((int)($nilai / 1000)) . " Ribu" . terbilangIndonesia($nilai % 1000);
    } else if ($nilai < 1000000000) {
        $temp = terbilangIndonesia((int)($nilai / 1000000)) . " Juta" . terbilangIndonesia($nilai % 1000000);
    } else if ($nilai < 1000000000000) {
        $temp = terbilangIndonesia((int)($nilai / 1000000000)) . " Miliar" . terbilangIndonesia(fmod($nilai, 1000000000));
    } else if ($nilai < 1000000000000000) {
        $temp = terbilangIndonesia((int)($nilai / 1000000000000)) . " Triliun" . terbilangIndonesia(fmod($nilai, 1000000000000));
    }
    return trim($temp);
}

// Cek Penguncian Dokumen oleh IT Inventory (bc_1ab2)
function siInvoiceLocked($koneksi, $escId) {
    if ($escId === '') return false;
    try {
        $lockRes = $koneksi->query("SELECT 1 FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' LIMIT 1");
        return $lockRes && $lockRes->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// ==========================================
// AJAX API ENDPOINTS
// ==========================================
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// 0. AUDIT TRAIL LOG API
if ($action === 'audit_list' || $action === 'audit_detail') {
    handleAuditLogApi($koneksi, $sessionCmpId);
    exit;
}

// 1. LIST DATA INVOICE (BROWSE DATA)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $searchCol = trim($_GET['search_col'] ?? '');
    $statusBayar = trim($_GET['status_bayar'] ?? '');
    $tglAwal = trim($_GET['tgl_awal'] ?? '');
    $tglAkhir = trim($_GET['tgl_akhir'] ?? '');

    $where = ["(h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')"];
    if ($statusBayar === '0' || $statusBayar === '1') {
        $where[] = "h.status = '{$statusBayar}'";
    }
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $colMap = [
            'inv_no' => 'h.si_no',
            'supplier' => "COALESCE(b.nama, h.vendor)",
            'do_no' => 'h.do_no',
            'so_no' => 'h.so_no',
            'po_no' => 'h.po_no',
            'note' => 'h.note',
            'kurs' => 'h.kurs',
            'set_by' => 'h.set_by',
            'no_bc' => 'h.no_bc',
            'fp' => 'h.no_fp',
            'no_aju' => 'h.no_aju',
        ];
        if (isset($colMap[$searchCol])) {
            $where[] = "{$colMap[$searchCol]} LIKE '%{$escS}%'";
        } else {
            $where[] = "(h.si_no LIKE '%{$escS}%' OR h.vendor LIKE '%{$escS}%' OR b.nama LIKE '%{$escS}%' OR h.do_no LIKE '%{$escS}%' OR h.so_no LIKE '%{$escS}%' OR h.po_no LIKE '%{$escS}%' OR h.no_fp LIKE '%{$escS}%' OR h.no_aju LIKE '%{$escS}%' OR h.no_bc LIKE '%{$escS}%')";
        }
    }
    if (!empty($tglAwal)) {
        $escAwal = $koneksi->real_escape_string($tglAwal);
        $where[] = "h.si_dt >= '{$escAwal}'";
    }
    if (!empty($tglAkhir)) {
        $escAkhir = $koneksi->real_escape_string($tglAkhir);
        $where[] = "h.si_dt <= '{$escAkhir}'";
    }

    $whereStr = implode(' AND ', $where);

    $cntRes = $koneksi->query("SELECT COUNT(*) as total FROM bc_si_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')) WHERE {$whereStr}");
    $total = $cntRes ? (int)$cntRes->fetch_assoc()['total'] : 0;

    $sql = "SELECT h.*, COALESCE(b.nama, h.vendor) as buyer_nama,
                   (SELECT COUNT(*) FROM bc_si_dtl d WHERE d.id_hdr = h.id) as item_count,
                   (SELECT 1 FROM bc_1ab2 l WHERE l.ID_HEADER = h.id LIMIT 1) as is_locked
            FROM bc_si_hdr h
            LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = ''))
            WHERE {$whereStr}
            ORDER BY h.si_dt DESC, h.si_no DESC
            LIMIT {$offset}, {$limit}";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }

    echo json_encode(['status' => 'OK', 'total' => $total, 'page' => $page, 'limit' => $limit, 'data' => $data]);
    exit;
}

// 2. GET DETAIL TRANSAKSI
if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrRes = $koneksi->query("SELECT h.*, COALESCE(b.nama, h.vendor) as buyer_nama, b.alamat, b.npwp, b.nitku
                               FROM bc_si_hdr h
                               LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = ''))
                               WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    if (!$hdrRes || $hdrRes->num_rows === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'Data Sales Invoice tidak ditemukan']);
        exit;
    }
    $header = $hdrRes->fetch_assoc();

    $dtlRes = $koneksi->query("SELECT * FROM bc_si_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $details = [];
    if ($dtlRes) {
        while ($r = $dtlRes->fetch_assoc()) {
            $details[] = $r;
        }
    }

    $isLocked = siInvoiceLocked($koneksi, $escId);
    echo json_encode(['status' => 'OK', 'header' => $header, 'details' => $details, 'locked' => $isLocked]);
    exit;
}

// 3. PENCARIAN MASTER BARANG (MATERIAL PICKER)
if ($action === 'search_materials') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $klas = trim($_GET['klas'] ?? 'FG');
    $escQ = $koneksi->real_escape_string($q);
    $where = ['1=1'];
    if (!empty($sessionCmpId)) {
        $where[] = "(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)";
    }
    $klasMap = [
        'FG' => "(KLAS LIKE '%Finish%' OR KLAS LIKE '%Barang Jadi%' OR KLAS = 'FG')",
        'RM' => "(KLAS LIKE '%Raw%' OR KLAS LIKE '%Bahan Baku%' OR KLAS = 'RM')",
        'WI' => "(KLAS LIKE '%WIP%' OR KLAS LIKE '%Progress%' OR KLAS LIKE '%Dalam Proses%' OR KLAS = 'WI')",
        'CG' => "(KLAS LIKE '%Capital%' OR KLAS = 'CG')",
        'W'  => "(KLAS LIKE '%Waste%' OR KLAS LIKE '%Scrap%' OR KLAS = 'W')",
    ];
    if (isset($klasMap[$klas])) {
        $where[] = $klasMap[$klas];
    }
    if ($escQ !== '') {
        $where[] = "(KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%' OR IFNULL(POS_TARIF,'') LIKE '%{$escQ}%')";
    }
    $whereSql = implode(' AND ', $where);
    $list = [];
    try {
        $res = $koneksi->query("SELECT KODE_BARANG AS kode_barang, URAIAN AS uraian, IFNULL(KODE_SATUAN,'PCE') AS satuan, IFNULL(POS_TARIF,'') AS hs_code, IFNULL(KLAS,'') AS klas, IFNULL(UNIT_PRICE,0) AS harga_satuan, IFNULL(BERAT,0) AS berat
            FROM bc_material WHERE {$whereSql} ORDER BY KODE_BARANG ASC LIMIT 80");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $list[] = $r;
            }
        }
        echo json_encode(['status' => 'OK', 'data' => $list]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal membaca master barang.', 'data' => []]);
    }
    exit;
}

// 4. TARIK DO (LOOKUP DELIVERY ORDER)
if ($action === 'lookup_do') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $where = ["(d.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')"];
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(d.do_no LIKE '%{$escS}%' OR d.vendor LIKE '%{$escS}%' OR b.nama LIKE '%{$escS}%' OR d.so_no LIKE '%{$escS}%' OR d.po_no LIKE '%{$escS}%')";
    }
    $whereStr = implode(' AND ', $where);

    $sql = "SELECT d.*, COALESCE(b.nama, d.vendor) as buyer_nama, b.alamat, b.npwp
            FROM bc_do_hdr d
            LEFT JOIN bc_bs b ON (d.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = ''))
            WHERE {$whereStr}
            ORDER BY d.do_dt DESC, d.do_no DESC
            LIMIT 40";
    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

if ($action === 'get_do_items') {
    header('Content-Type: application/json; charset=utf-8');
    $doNo = trim($_GET['do_no'] ?? '');
    $escDo = $koneksi->real_escape_string($doNo);

    $sql = "SELECT d.*, m.berat 
            FROM bc_do_dtl d
            LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = ''))
            WHERE (d.do_no = '{$escDo}' OR d.id_hdr = '{$escDo}') AND (d.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')
            ORDER BY d.seq ASC";
    $res = $koneksi->query($sql);
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'items' => $items]);
    exit;
}

// 5. TARIK SO (LOOKUP SALES ORDER)
if ($action === 'lookup_so') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $where = ["(s.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')"];
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(s.so_no LIKE '%{$escS}%' OR s.vendor LIKE '%{$escS}%' OR b.nama LIKE '%{$escS}%' OR s.po_no LIKE '%{$escS}%')";
    }
    $whereStr = implode(' AND ', $where);

    $sql = "SELECT s.*, COALESCE(b.nama, s.vendor) as buyer_nama, b.alamat, b.npwp
            FROM bc_so_hdr s
            LEFT JOIN bc_bs b ON (s.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = ''))
            WHERE {$whereStr}
            ORDER BY s.so_dt DESC, s.so_no DESC
            LIMIT 40";
    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

if ($action === 'get_so_items') {
    header('Content-Type: application/json; charset=utf-8');
    $soNo = trim($_GET['so_no'] ?? '');
    $escSo = $koneksi->real_escape_string($soNo);

    $sql = "SELECT d.*, m.berat 
            FROM bc_so_dtl d
            LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = ''))
            WHERE (d.so_no = '{$escSo}' OR d.id_hdr = '{$escSo}') AND (d.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')
            ORDER BY d.seq ASC";
    $res = $koneksi->query($sql);
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'items' => $items]);
    exit;
}

// 6. SIMPAN TRANSAKSI SALES INVOICE (SAVE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $post = json_decode($raw, true) ?: $_POST;

    $id = trim($post['id'] ?? '');
    $isEdit = !empty($id);

    // Snapshot data lama sebelum diubah untuk Audit Trail (apa yang diganti)
    $oldHdr = null;
    $oldItems = [];
    if ($isEdit) {
        $escIdTemp = $koneksi->real_escape_string($id);
        $qOldHdr = $koneksi->query("SELECT * FROM bc_si_hdr WHERE id = '{$escIdTemp}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
        if ($qOldHdr && $rowH = $qOldHdr->fetch_assoc()) {
            $oldHdr = $rowH;
            $qOldItems = $koneksi->query("SELECT * FROM bc_si_dtl WHERE id_hdr = '{$escIdTemp}' ORDER BY seq ASC");
            if ($qOldItems) {
                while ($ri = $qOldItems->fetch_assoc()) $oldItems[] = $ri;
            }
        }
    }
    if (!$isEdit) {
        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    $escId = $koneksi->real_escape_string($id);
    if ($isEdit && siInvoiceLocked($koneksi, $escId)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Sudah Masuk IT Inventory. TIDAK BISA EDIT !']);
        exit;
    }

    $siNo = trim($post['si_no'] ?? '');
    if (empty($siNo)) {
        // Auto number SI-YYYYMM-XXXX
        $ym = date('Ym');
        $numRes = $koneksi->query("SELECT MAX(CAST(SUBSTRING(si_no, 11) AS UNSIGNED)) as max_num FROM bc_si_hdr WHERE cmp_id = '{$escCmp}' AND si_no LIKE 'SI-{$ym}-%'");
        $next = ($numRes && ($row = $numRes->fetch_assoc()) && $row['max_num']) ? ((int)$row['max_num'] + 1) : 1;
        $siNo = sprintf('SI-%s-%04d', $ym, $next);
    }
    $escSiNo = $koneksi->real_escape_string($siNo);

    // Cek duplikasi nomor invoice untuk ID berbeda
    $chkDup = $koneksi->query("SELECT 1 FROM bc_si_hdr WHERE si_no = '{$escSiNo}' AND id <> '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    if ($chkDup && $chkDup->num_rows > 0) {
        echo json_encode(['status' => 'ERR', 'message' => "Nomor Invoice '{$siNo}' sudah dipakai pada transaksi lain!"]);
        exit;
    }

    $siDt = !empty($post['si_dt']) ? $koneksi->real_escape_string($post['si_dt']) : date('Y-m-d');
    $shipDt = !empty($post['ship_dt']) ? "'" . $koneksi->real_escape_string($post['ship_dt']) . "'" : "'{$siDt}'";
    $jatuhtempo = !empty($post['jatuhtempo']) ? "'" . $koneksi->real_escape_string($post['jatuhtempo']) . "'" : "NULL";
    $doNo = $koneksi->real_escape_string($post['do_no'] ?? '');
    $soNo = $koneksi->real_escape_string($post['so_no'] ?? '');
    $poNo = $koneksi->real_escape_string($post['po_no'] ?? '');
    $vendor = $koneksi->real_escape_string($post['vendor'] ?? '');
    $shipTo = $koneksi->real_escape_string($post['shipto'] ?? '');
    $term = $koneksi->real_escape_string($post['term'] ?? 'Net 30');
    $kurs = $koneksi->real_escape_string($post['kurs'] ?? 'IDR');
    $rate = ($kurs === 'IDR') ? 1.0 : (float)($post['rate'] ?? 1);
    $birate = ($kurs === 'IDR') ? 1.0 : (float)($post['birate'] ?? 1);
    $taxable = (($post['vendoristaxable'] ?? 'Y') === 'Y') ? 'Y' : 'N';
    $inclusive = (($post['inclusivetax'] ?? 'N') === 'Y') ? 'Y' : 'N';
    $isSubcon = (($post['issubcon'] ?? 'N') === 'Y') ? 'Y' : 'N';
    $ncv = !empty($post['ncv']) && $post['ncv'] !== '0' && $post['ncv'] !== 'N' ? '1' : '0';
    $bill2 = (($post['bill2buyer'] ?? 'N') === 'Y') ? 'Y' : 'N';
    $estFreight = (float)($post['est_freight'] ?? 0);
    $jasa = (float)($post['jasa'] ?? 0);
    $tujuanKirim = $koneksi->real_escape_string($post['tujuan_kirim'] ?? '');
    $kdFp = $koneksi->real_escape_string($post['kd_fp'] ?? '01');
    $noFp = $koneksi->real_escape_string($post['no_fp'] ?? '');
    $bc = $koneksi->real_escape_string($post['bc'] ?? '');
    $noBc = $koneksi->real_escape_string($post['no_bc'] ?? '');
    $tglBc = !empty($post['tgl_bc']) ? "'" . $koneksi->real_escape_string($post['tgl_bc']) . "'" : "NULL";
    $noAju = $koneksi->real_escape_string($post['no_aju'] ?? '');
    $note = $koneksi->real_escape_string($post['note'] ?? '');
    $subtotal = (float)($post['subtotal'] ?? 0);
    $discPersen = (float)($post['disc_persen'] ?? 0);
    $discAmt = (float)($post['disc_amt'] ?? 0);
    $ppn = (float)($post['ppn'] ?? 0);
    $pph = (float)($post['pph'] ?? 0);
    $totalOrder = (float)($post['total_order'] ?? 0);
    $terbayar = (float)($post['terbayar'] ?? 0);
    $outstand = $totalOrder - $terbayar;
    $status = ($outstand <= 0 && $totalOrder > 0) ? '1' : '0';
    $setBy = $_SESSION['user_id'] ?? ($_SESSION['username'] ?? 'admin');

    if (empty($vendor)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Supplier / Buyer Belum Dipilih !']);
        exit;
    }

    $items = $post['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'Mohon isi minimal 1 baris rincian barang!']);
        exit;
    }

    // Auto-fetch PO No dari SO jika PO No di header kosong (sesuai PB ue_save)
    if (empty($poNo) && !empty($soNo)) {
        $poRes = $koneksi->query("SELECT GROUP_CONCAT(DISTINCT po_no) as po_list FROM bc_so_hdr WHERE so_no = '{$soNo}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
        if ($poRes && $rPo = $poRes->fetch_assoc()) {
            if (!empty($rPo['po_list'])) $poNo = $koneksi->real_escape_string($rPo['po_list']);
        }
    }

    if ($isEdit) {
        $sqlHdr = "UPDATE bc_si_hdr SET
                    si_no = '{$escSiNo}',
                    si_dt = '{$siDt}',
                    ship_dt = {$shipDt},
                    jatuhtempo = {$jatuhtempo},
                    do_no = '{$doNo}',
                    so_no = '{$soNo}',
                    po_no = '{$poNo}',
                    vendor = '{$vendor}',
                    shipto = '{$shipTo}',
                    vendoristaxable = '{$taxable}',
                    inclusivetax = '{$inclusive}',
                    issubcon = '{$isSubcon}',
                    ncv = '{$ncv}',
                    bill2buyer = '{$bill2}',
                    term = '{$term}',
                    kurs = '{$kurs}',
                    rate = {$rate},
                    birate = {$birate},
                    est_freight = {$estFreight},
                    jasa = {$jasa},
                    tujuan_kirim = '{$tujuanKirim}',
                    kd_fp = '{$kdFp}',
                    no_fp = '{$noFp}',
                    bc = '{$bc}',
                    no_bc = '{$noBc}',
                    tgl_bc = {$tglBc},
                    no_aju = '{$noAju}',
                    note = '{$note}',
                    subtotal = {$subtotal},
                    disc_persen = {$discPersen},
                    disc_amt = {$discAmt},
                    ppn = {$ppn},
                    pph = {$pph},
                    total_order = {$totalOrder},
                    terbayar = {$terbayar},
                    outstand = {$outstand},
                    status = '{$status}',
                    set_by = '{$setBy}'
                   WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')";
    } else {
        $sqlHdr = "INSERT INTO bc_si_hdr (id, cmp_id, si_no, si_dt, ship_dt, jatuhtempo, do_no, so_no, po_no, vendor, shipto, vendoristaxable, inclusivetax, issubcon, ncv, bill2buyer, term, kurs, rate, birate, est_freight, jasa, tujuan_kirim, kd_fp, no_fp, bc, no_bc, tgl_bc, no_aju, note, subtotal, disc_persen, disc_amt, ppn, pph, total_order, terbayar, outstand, status, set_by)
                   VALUES ('{$escId}', '{$escCmp}', '{$escSiNo}', '{$siDt}', {$shipDt}, {$jatuhtempo}, '{$doNo}', '{$soNo}', '{$poNo}', '{$vendor}', '{$shipTo}', '{$taxable}', '{$inclusive}', '{$isSubcon}', '{$ncv}', '{$bill2}', '{$term}', '{$kurs}', {$rate}, {$birate}, {$estFreight}, {$jasa}, '{$tujuanKirim}', '{$kdFp}', '{$noFp}', '{$bc}', '{$noBc}', {$tglBc}, '{$noAju}', '{$note}', {$subtotal}, {$discPersen}, {$discAmt}, {$ppn}, {$pph}, {$totalOrder}, {$terbayar}, {$outstand}, '{$status}', '{$setBy}')";
    }

    if (!$koneksi->query($sqlHdr)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Gagal menyimpan header invoice: ' . $koneksi->error]);
        exit;
    }

    // Detail items
    $koneksi->query("DELETE FROM bc_si_dtl WHERE id_hdr = '{$escId}'");
    $seq = 1;
    foreach ($items as $item) {
        $dtlId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $kodeBarang = $koneksi->real_escape_string($item['kode_barang'] ?? '');
        $uraian = $koneksi->real_escape_string($item['uraian'] ?? '');
        $qty = (float)($item['qty'] ?? 0);
        $sat = $koneksi->real_escape_string($item['sat'] ?? 'PCE');
        $price = (float)($item['price'] ?? 0);
        $disc = (float)($item['disc'] ?? 0);
        $amount = (float)($item['amount'] ?? ($qty * $price * (1 - $disc / 100)));
        $priceJasa = (float)($item['price_jasa'] ?? 0);
        $amtJasa = (float)($item['amt_jasa'] ?? ($qty * $priceJasa));
        $netto = (float)($item['netto'] ?? 0);
        $gross = (float)($item['gross'] ?? ($netto > 0 ? $netto : 0));
        
        // Kalkulasi CIF & CIF Rupiah sesuai mata uang (PB ue_save)
        if ($kurs !== 'IDR') {
            $cif = $amount;
            $cifRupiah = $amount * $rate;
        } else {
            $cif = ($rate > 0) ? ($amount / $rate) : $amount;
            $cifRupiah = $amount;
        }

        $itemNote = $koneksi->real_escape_string($item['note'] ?? '');
        $dtlDoNo = $koneksi->real_escape_string($item['do_no'] ?? $doNo);
        $dtlSoNo = $koneksi->real_escape_string($item['so_no'] ?? $soNo);
        $dtlPoNo = $koneksi->real_escape_string($item['po_no'] ?? $poNo);
        $dtlColor = $koneksi->real_escape_string($item['color_cd'] ?? '');
        $dtlSize = $koneksi->real_escape_string($item['size_cd'] ?? '');
        $dtlCtn = (float)($item['ctn'] ?? 0);
        $dtlPlb = (float)($item['plb'] ?? 0);
        $dtlVol = (float)($item['volume'] ?? 0);
        $dtlGudang = $koneksi->real_escape_string($item['gudang'] ?? 'G-FG');
        $dtlPosTarif = $koneksi->real_escape_string($item['pos_tarif'] ?? '');
        $dtlHpp = (float)($item['hpp'] ?? 0);

        if (!empty($kodeBarang)) {
            $sqlDtl = "INSERT INTO bc_si_dtl (id, id_hdr, cmp_id, si_no, seq, id_so, so_no, po_no, id_do, do_no, kode_barang, uraian, color_cd, size_cd, qty, sat, price, disc, amount, cif, cif_rupiah, price_jasa, amt_jasa, netto, gross, volume, ctn, plb, gudang, pos_tarif, hpp, note)
                       VALUES ('{$dtlId}', '{$escId}', '{$escCmp}', '{$escSiNo}', {$seq}, '', '{$dtlSoNo}', '{$dtlPoNo}', '', '{$dtlDoNo}', '{$kodeBarang}', '{$uraian}', '{$dtlColor}', '{$dtlSize}', {$qty}, '{$sat}', {$price}, {$disc}, {$amount}, {$cif}, {$cifRupiah}, {$priceJasa}, {$amtJasa}, {$netto}, {$gross}, {$dtlVol}, {$dtlCtn}, {$dtlPlb}, '{$dtlGudang}', '{$dtlPosTarif}', {$dtlHpp}, '{$itemNote}')";
            $koneksi->query($sqlDtl);
            $seq++;
        }
    }

    // Sinkronisasi status DO (PB ue_update_do_vendor) jika terhubung ke DO
    if (!empty($doNo)) {
        try {
            $koneksi->query("UPDATE bc_do_dtl a 
                             SET a.terima = COALESCE((SELECT SUM(x.qty) FROM bc_si_dtl x WHERE x.do_no = a.do_no AND x.kode_barang = a.kode_barang), 0)
                             WHERE a.do_no = '{$doNo}' AND (a.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
            
            $koneksi->query("UPDATE bc_do_hdr 
                             SET status = IF(
                                 (SELECT SUM(qty) - SUM(terima) FROM bc_do_dtl WHERE do_no = '{$doNo}') <= 0, '2',
                                 IF((SELECT SUM(terima) FROM bc_do_dtl WHERE do_no = '{$doNo}') > 0, '1', '0')
                             )
                             WHERE do_no = '{$doNo}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
        } catch (Throwable $e) {}
    }

    // Perbarui referensi urutan penomoran (bc_ref_urut)
    try {
        $koneksi->query("REPLACE INTO bc_ref_urut (kolom, lastno) VALUES ('SI', '{$escSiNo}')");
    } catch (Throwable $e) {}

    // Catat Audit Trail (Apa yang diganti / ditambahkan, oleh siapa, dan kapan)
    try {
        if ($isEdit && $oldHdr) {
            $hdrLabels = [
                'si_no'       => 'No. Invoice',
                'si_dt'       => 'Tgl Invoice',
                'ship_dt'     => 'Tgl Kirim',
                'jatuhtempo'  => 'Jatuh Tempo',
                'do_no'       => 'No. DO',
                'so_no'       => 'No. SO',
                'po_no'       => 'No. PO',
                'vendor'      => 'Buyer / Customer',
                'term'        => 'Syarat Pembayaran',
                'kurs'        => 'Valuta',
                'rate'        => 'Kurs',
                'subtotal'    => 'Subtotal DPP',
                'disc_persen' => 'Diskon %',
                'disc_amt'    => 'Nilai Diskon',
                'ppn'         => 'PPN',
                'pph'         => 'PPh',
                'est_freight' => 'Freight / Ongkir',
                'jasa'        => 'Nilai Jasa',
                'total_order' => 'Total Tagihan',
                'no_bc'       => 'No Pendaftaran BC',
                'no_fp'       => 'No Faktur Pajak',
                'no_aju'      => 'No Pengajuan BC',
                'status'      => 'Status'
            ];
            $diff = diffAuditDocument($oldHdr, $post, $oldItems, $items, $hdrLabels, 'kode_barang');
            if ($diff['has_changes']) {
                recordAuditLog($koneksi, [
                    'cmp_id'       => $sessionCmpId,
                    'module'       => 'SI',
                    'doc_no'       => $siNo,
                    'doc_id'       => $id,
                    'action_type'  => 'UPDATE',
                    'summary'      => $diff['summary'],
                    'changes_json' => $diff['changes'],
                    'old_snapshot' => ['header' => $oldHdr, 'items' => $oldItems],
                    'new_snapshot' => ['header' => $post, 'items' => $items],
                    'user_id'      => $_SESSION['user_id'] ?? 'SYSTEM',
                    'user_name'    => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'SYSTEM')
                ]);
            }
        } else {
            recordAuditLog($koneksi, [
                'cmp_id'       => $sessionCmpId,
                'module'       => 'SI',
                'doc_no'       => $siNo,
                'doc_id'       => $id,
                'action_type'  => 'INSERT',
                'summary'      => "Sales Invoice baru dibuat: No. {$siNo}, Buyer: {$vendor}, Total: " . number_format($totalOrder, 2, '.', ',') . ", " . count($items) . " item",
                'new_snapshot' => ['header' => $post, 'items' => $items],
                'user_id'      => $_SESSION['user_id'] ?? 'SYSTEM',
                'user_name'    => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'SYSTEM')
            ]);
        }
    } catch (Throwable $e) {}

    echo json_encode(['status' => 'OK', 'id' => $id, 'si_no' => $siNo, 'message' => 'Sales Invoice berhasil disimpan']);
    exit;
}

// 7. HAPUS TRANSAKSI SALES INVOICE (DELETE)
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    if (siInvoiceLocked($koneksi, $escId)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Sudah Masuk IT Inventory. TIDAK BISA EDIT / HAPUS !']);
        exit;
    }

    $siNo = '';
    $oldHdr = null;
    $oldItems = [];
    $qOld = $koneksi->query("SELECT * FROM bc_si_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    if ($qOld && $rOld = $qOld->fetch_assoc()) {
        $oldHdr = $rOld;
        $siNo = $rOld['si_no'];
        $qDtl = $koneksi->query("SELECT * FROM bc_si_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
        if ($qDtl) {
            while ($ri = $qDtl->fetch_assoc()) $oldItems[] = $ri;
        }
    }

    $koneksi->query("DELETE FROM bc_si_dtl WHERE id_hdr = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
    $del = $koneksi->query("DELETE FROM bc_si_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");

    if ($del) {
        // Catat Audit Trail Penghapusan Data (apa yang dihapus, oleh siapa, dan kapan)
        try {
            $itemCount = count($oldItems);
            $totalVal = number_format((float)($oldHdr['total_order'] ?? 0), 2, '.', ',');
            $vnd = $oldHdr['vendor'] ?? '-';
            recordAuditLog($koneksi, [
                'cmp_id'       => $sessionCmpId,
                'module'       => 'SI',
                'doc_no'       => $siNo,
                'doc_id'       => $id,
                'action_type'  => 'DELETE',
                'summary'      => "Dokumen Sales Invoice dihapus: No. {$siNo} (Buyer: {$vnd}, Total: {$totalVal}, {$itemCount} item barang)",
                'old_snapshot' => ['header' => $oldHdr, 'items' => $oldItems],
                'user_id'      => $_SESSION['user_id'] ?? 'SYSTEM',
                'user_name'    => $_SESSION['user_name'] ?? ($_SESSION['username'] ?? 'SYSTEM')
            ]);
        } catch (Throwable $e) {}
        echo json_encode(['status' => 'OK', 'message' => 'Sales Invoice berhasil dihapus']);
    } else {
        echo json_encode(['status' => 'ERR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 8. POSTING TRANSAKSI KE IT INVENTORY (TRANSFER IT INV - w_si.srw)
if ($action === 'transfer_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    if (empty($id)) {
        echo json_encode(['status' => 'ERR', 'message' => 'ID Invoice tidak valid']);
        exit;
    }

    $hRes = $koneksi->query("SELECT h.*, COALESCE(b.nama, h.vendor) as buyer_nama, b.npwp as buyer_npwp 
                             FROM bc_si_hdr h 
                             LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')) 
                             WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    if (!$hRes || $hRes->num_rows === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'Data Invoice tidak ditemukan']);
        exit;
    }
    $h = $hRes->fetch_assoc();

    $dRes = $koneksi->query("SELECT d.*, m.POS_TARIF, COALESCE(m.KLAS, 'Finished Goods') as klas 
                             FROM bc_si_dtl d 
                             LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '')) 
                             WHERE d.id_hdr = '{$escId}' 
                             ORDER BY d.seq ASC");
    if (!$dRes || $dRes->num_rows === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'Rincian barang kosong. Simpan invoice sebelum posting ke IT Inventory.']);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        // Hapus rekaman lama jika ada
        $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");

        $userNow = $_SESSION['username'] ?? 'admin';
        $tglNow = date('Y-m-d');

        while ($d = $dRes->fetch_assoc()) {
            $bcDoc = !empty($h['bc']) ? $h['bc'] : 'LOKAL';
            $noDaftar = !empty($h['no_bc']) ? $h['no_bc'] : '-';
            $tglDaftar = !empty($h['tgl_bc']) ? $h['tgl_bc'] : $h['si_dt'];
            $noBukti = $h['si_no'];
            $tglBukti = $h['si_dt'];
            $namaBs = $h['buyer_nama'] ?: $h['vendor'];
            $kodeBrg = $d['kode_barang'];
            $posTarif = !empty($d['POS_TARIF']) ? $d['POS_TARIF'] : '-';
            $uraian = $d['uraian'];
            $klas = !empty($d['klas']) ? $d['klas'] : 'Finished Goods';
            $unit = !empty($d['sat']) ? $d['sat'] : 'PCE';
            $qty = (float)$d['qty'];
            $kurs = !empty($h['kurs']) ? $h['kurs'] : 'IDR';
            $nilai = (float)$d['amount'];
            $ab = 'B'; // 'B' = Pengeluaran / Sales
            $sj = !empty($d['do_no']) ? $d['do_no'] : $h['do_no'];
            $inv = $h['si_no'];
            $faktur = $h['no_fp'];
            $noAju = $h['no_aju'];
            $npwpBs = !empty($h['buyer_npwp']) ? $h['buyer_npwp'] : '-';
            $seri = (int)$d['seq'];
            $hrg = (float)$d['price'];
            $cif = (float)$d['cif'];
            $netto = (float)($d['netto'] ?: 0);
            $gross = (float)($d['gross'] ?: $netto);
            $poNo = !empty($d['so_no']) ? $d['so_no'] : $h['po_no'];
            $idBs = $h['vendor'];
            $tglProses = !empty($h['ship_dt']) ? $h['ship_dt'] : $h['si_dt'];

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
                {$hrg}, {$cif}, {$netto}, {$gross}, 'Y', {$qty}, 'G-FG',
                '{$poNo}', '{$idBs}', '{$tglProses}', '{$tglNow}', '{$userNow}'
            )";

            if (!$koneksi->query($sqlIns)) {
                throw new Exception("Gagal insert IT Inventory: " . $koneksi->error);
            }
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Sales Invoice {$h['si_no']} berhasil dibukukan ke IT Inventory (bc_1ab2). Dokumen kini berstatus TERKUNCI."]);
    } catch (Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERR', 'message' => $e->getMessage()]);
    }
    exit;
}

// 9. HAPUS DARI IT INVENTORY (BUKA KUNCI - w_si.srw b_delit)
if ($action === 'delete_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    if (empty($id)) {
        echo json_encode(['status' => 'ERR', 'message' => 'ID Invoice tidak valid']);
        exit;
    }

    $del = $koneksi->query("DELETE FROM bc_1ab2 WHERE ID_HEADER = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => "Data transaksi berhasil dihapus dari IT Inventory. Sales Invoice kini TERBUKA kembali untuk diedit."]);
    } else {
        echo json_encode(['status' => 'ERR', 'message' => "Gagal menghapus dari IT Inventory: " . $koneksi->error]);
    }
    exit;
}

// 10. REKAP DOKUMEN PABEAN (BC SUMMARY - bc_si_sumbc.srd)
if ($action === 'rekap_bc') {
    header('Content-Type: application/json; charset=utf-8');
    $tglAwal = trim($_GET['tgl_awal'] ?? date('Y-m-01'));
    $tglAkhir = trim($_GET['tgl_akhir'] ?? date('Y-m-d'));
    $escAwal = $koneksi->real_escape_string($tglAwal);
    $escAkhir = $koneksi->real_escape_string($tglAkhir);

    $sql = "SELECT 
                CASE 
                    WHEN h.bc = '30' OR h.bc LIKE '%3.0%' OR h.bc LIKE '%PEB%' THEN 'BC 3.0 (PEB)'
                    WHEN h.bc = '41' OR h.bc LIKE '%4.1%' THEN 'BC 4.1'
                    WHEN h.bc = '27' OR h.bc LIKE '%2.7%' THEN 'BC 2.7 OUT'
                    WHEN h.bc = '25' OR h.bc LIKE '%2.5%' THEN 'BC 2.5'
                    ELSE 'LOKAL / NON-BC'
                END as jenis_dok,
                COUNT(DISTINCT h.id) as jml_faktur,
                COUNT(d.id) as jml_item,
                SUM(d.qty) as total_qty,
                SUM(h.subtotal) as total_subtotal,
                SUM(h.ppn) as total_ppn,
                SUM(h.pph) as total_pph,
                SUM(h.total_order) as grand_total,
                SUM(h.terbayar) as total_terbayar,
                SUM(h.outstand) as total_outstand
            FROM bc_si_hdr h
            LEFT JOIN bc_si_dtl d ON h.id = d.id_hdr
            WHERE (h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')
              AND h.si_dt >= '{$escAwal}' AND h.si_dt <= '{$escAkhir}'
            GROUP BY jenis_dok
            ORDER BY jml_faktur DESC";

    $res = $koneksi->query($sql);
    $data = [];
    $totalFaktur = 0; $totalGrand = 0; $totalPpn = 0; $totalPph = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $totalFaktur += (int)$r['jml_faktur'];
            $totalGrand += (float)$r['grand_total'];
            $totalPpn += (float)$r['total_ppn'];
            $totalPph += (float)$r['total_pph'];
            $data[] = $r;
        }
    }
    echo json_encode([
        'status' => 'OK',
        'data' => $data,
        'summary' => [
            'total_faktur' => $totalFaktur,
            'total_grand' => $totalGrand,
            'total_ppn' => $totalPpn,
            'total_pph' => $totalPph,
        ]
    ]);
    exit;
}

// 11. TRACE DOKUMEN & ISSUE PRODUKSI (w_trace_si.srw)
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $header = null;
    $lines = [];
    $komponen = [];
    $aju = [];

    // Header invoice
    $hRes = $koneksi->query("SELECT h.*, COALESCE(b.nama, h.vendor) as buyer_nama FROM bc_si_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')) WHERE h.id = '{$escId}' LIMIT 1");
    if ($hRes && ($rowH = $hRes->fetch_assoc())) {
        $header = $rowH;
    }

    $dtlRes = $koneksi->query("SELECT id, seq, kode_barang, uraian, color_cd, size_cd, qty, sat, do_no, so_no, po_no FROM bc_si_dtl WHERE id_hdr = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') ORDER BY seq ASC");
    if ($dtlRes) {
        while ($r = $dtlRes->fetch_assoc()) $lines[] = $r;
    }

    $idList = [];
    foreach ($lines as $ln) {
        if (!empty($ln['id'])) $idList[] = "'" . $koneksi->real_escape_string($ln['id']) . "'";
    }

    // 1. Cek Dokumen Eks-BC CEISA 4.0 yang sudah tersimpan untuk invoice ini di bc_si_aju_ceisa
    if (!empty($idList)) {
        $in = implode(',', $idList);
        $ceisaRes = @$koneksi->query("SELECT * FROM bc_si_aju_ceisa WHERE id_sidtl IN ({$in}) AND (cmp_id = '{$escCmp}' OR cmp_id = '') ORDER BY id ASC");
        if ($ceisaRes && $ceisaRes->num_rows > 0) {
            while ($cr = $ceisaRes->fetch_assoc()) {
                $cr['id_sidtl2'] = $cr['id_sidtl'];
                $aju[] = $cr;
            }
        }
    }

    // 2. Cek bc_si_dtl2 & bc_si_aju (Riwayat Trace PowerBuilder / Sesi Sebelumnya)
    if ($idList) {
        $in = implode(',', $idList);
        $komRes = @$koneksi->query("SELECT * FROM bc_si_dtl2 WHERE id_sidtl IN ({$in})");
        if ($komRes) while ($r = $komRes->fetch_assoc()) $komponen[] = $r;
        $komIds = [];
        foreach ($komponen as $k) {
            if (!empty($k['id'])) $komIds[] = "'" . $koneksi->real_escape_string($k['id']) . "'";
        }
        if ($komIds && empty($aju)) {
            $ajuRes = @$koneksi->query("SELECT * FROM bc_si_aju WHERE id_sidtl2 IN (" . implode(',', $komIds) . ")");
            if ($ajuRes) while ($r = $ajuRes->fetch_assoc()) $aju[] = $r;
        }
    }

    // 3. Integrasi Konsumsi Material Real dari Work Order & Issue Produksi (bc_issue_hdr & bc_issue_dtl)
    if (empty($komponen) && !empty($lines)) {
        foreach ($lines as $ln) {
            $kdFg = $koneksi->real_escape_string($ln['kode_barang']);
            $qtyFg = (float)$ln['qty'];
            $soNo = $koneksi->real_escape_string($ln['so_no'] ?? '');
            $doNo = $koneksi->real_escape_string($ln['do_no'] ?? '');

            $woHdr = null;
            if (!empty($soNo)) {
                $woQ = @$koneksi->query("SELECT h.id, h.wo_no, d.qty as target_qty 
                                         FROM bc_wo_hdr h 
                                         JOIN bc_wo_dtl d ON h.id = d.id_hdr 
                                         WHERE (d.so_no = '{$soNo}' OR h.wo_no = '{$soNo}') 
                                           AND d.kode_barang = '{$kdFg}' 
                                           AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '') 
                                         LIMIT 1");
                if ($woQ && ($wRow = $woQ->fetch_assoc())) {
                    $woHdr = $wRow;
                }
            }

            if ($woHdr) {
                $targetQty = (float)($woHdr['target_qty'] > 0 ? $woHdr['target_qty'] : $qtyFg);
                $ratio = $targetQty > 0 ? min(1.0, $qtyFg / $targetQty) : 1.0;
                $escWoNo = $koneksi->real_escape_string($woHdr['wo_no']);
                $escWoId = $koneksi->real_escape_string($woHdr['id']);

                $issQ = @$koneksi->query("SELECT d.*, h.wo_no as h_wo_no, h.no_bukti as issue_no, h.tgl as issue_dt,
                                                 IFNULL(m.URAIAN, d.nama_barang) as nama_mat,
                                                 IFNULL(m.UNIT, d.sat) as sat_mat
                                          FROM bc_issue_dtl d
                                          JOIN bc_issue_hdr h ON d.id_hdr = h.id
                                          LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = ''))
                                          WHERE (h.wo_no = '{$escWoNo}' OR h.wo_id = '{$escWoId}' OR d.wo = '{$escWoNo}' OR h.fg = '{$kdFg}')
                                            AND (d.cmp_id = '{$escCmp}' OR d.cmp_id = '')");

                if ($issQ && $issQ->num_rows > 0) {
                    while ($ir = $issQ->fetch_assoc()) {
                        $usedQty = round((float)$ir['qty'] * $ratio, 4);
                        $cId = 'ISS-' . $ir['id'];
                        $komponen[] = [
                            'id' => $cId,
                            'id_sidtl' => $ln['id'],
                            'kode_fg' => $ln['kode_barang'],
                            'kode_bahan' => $ir['kode_barang'],
                            'uraian' => $ir['nama_mat'] ?: ($ir['nama_barang'] ?: $ir['kode_barang']),
                            'qty_taken' => $usedQty,
                            'sat' => $ir['sat_mat'] ?: ($ir['sat'] ?: 'PCE'),
                            'total_cost' => round((float)($ir['harga'] ?? 0) * $usedQty, 2),
                            'wo_no' => $woHdr['wo_no'],
                            'so_no' => $ln['so_no']
                        ];

                        if (empty($aju)) {
                            $escAju = $koneksi->real_escape_string($ir['no_aju'] ?? '');
                            $escBc = $koneksi->real_escape_string($ir['no_bc'] ?? '');
                            $escMat = $koneksi->real_escape_string($ir['kode_barang']);
                            $aQ = @$koneksi->query("SELECT BC as jenis_bc, NOMOR_AJU as nomor_aju, NOMOR_DAFTAR as no_daftar, 
                                                           TANGGAL_DAFTAR as tgl_daftar, KODE_BARANG as kode_brg, URAIAN as nama_brg, 
                                                           UNIT as sat, SERI_BARANG as seri_barang
                                                    FROM bc_1ab2
                                                    WHERE AB = 'A' AND (NOMOR_AJU = '{$escAju}' OR NO_BUKTI = '{$escBc}' OR KODE_BARANG = '{$escMat}')
                                                      AND (cmp_id = '{$escCmp}' OR cmp_id = '')
                                                    ORDER BY (NOMOR_AJU = '{$escAju}' AND '{$escAju}' != '') DESC, TANGGAL_DAFTAR DESC LIMIT 1");
                            if ($aQ && ($ar = $aQ->fetch_assoc())) {
                                $ar['id_sidtl2'] = $cId;
                                $ar['id_sidtl'] = $ln['id'];
                                $ar['kode_fg'] = $ln['kode_barang'];
                                $ar['qty_use'] = $usedQty;
                                $ar['wo_no'] = $woHdr['wo_no'];
                                $ar['so_no'] = $ln['so_no'];
                                $aju[] = $ar;
                            }
                        }
                    }
                }
            }
        }
    }

    // 4. Fallback Standar: Jika belum ada issue riil, kalkulasi BOM resep dari bc_bom
    if (empty($komponen) && !empty($lines)) {
        foreach ($lines as $ln) {
            $kd = $koneksi->real_escape_string($ln['kode_barang']);
            $qtyFg = (float)$ln['qty'];
            $bRes = @$koneksi->query("SELECT b.*, m.URAIAN as nama_bahan, m.UNIT as sat_bahan 
                                      FROM bc_bom b 
                                      LEFT JOIN bc_material m ON (b.kode_bahan = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = ''))
                                      WHERE b.kode_barang = '{$kd}' AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')");
            if ($bRes && $bRes->num_rows > 0) {
                while ($br = $bRes->fetch_assoc()) {
                    $komponen[] = [
                        'id' => 'BOM-' . $br['id'],
                        'id_sidtl' => $ln['id'],
                        'kode_fg' => $ln['kode_barang'],
                        'kode_bahan' => $br['kode_bahan'],
                        'uraian' => $br['nama_bahan'] ?: ($br['uraian'] ?: $br['kode_bahan']),
                        'qty_taken' => ($br['koefisien'] ?? 1) * $qtyFg,
                        'sat' => $br['sat_bahan'] ?: ($br['sat'] ?: 'PCE'),
                        'total_cost' => ($br['harga'] ?? 0) * (($br['koefisien'] ?? 1) * $qtyFg),
                        'so_no' => $ln['so_no'] ?? ''
                    ];
                }
            }
        }
    }

    // 5. Fallback Dokumen Pabean Asal: jika bc_si_aju dan bc_si_aju_ceisa kosong, cari pemasukan terakhir bahan terkait di bc_1ab2 (AB='A')
    if (empty($aju) && !empty($komponen)) {
        foreach ($komponen as $km) {
            $kdBahan = $koneksi->real_escape_string($km['kode_bahan'] ?? $km['kode_barang'] ?? '');
            if (!$kdBahan) continue;
            $aRes = @$koneksi->query("SELECT BC as jenis_bc, NOMOR_AJU as nomor_aju, NOMOR_DAFTAR as no_daftar, TANGGAL_DAFTAR as tgl_daftar, KODE_BARANG as kode_brg, URAIAN as nama_brg, QTY as qty_use, UNIT as sat, SERI_BARANG as seri_barang
                                      FROM bc_1ab2 
                                      WHERE AB = 'A' AND KODE_BARANG = '{$kdBahan}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')
                                      ORDER BY TANGGAL_DAFTAR DESC LIMIT 3");
            if ($aRes) {
                while ($ar = $aRes->fetch_assoc()) {
                    $ar['id_sidtl2'] = $km['id'] ?? '';
                    $ar['id_sidtl'] = $km['id_sidtl'] ?? '';
                    $ar['kode_fg'] = $km['kode_fg'] ?? '';
                    $ar['so_no'] = $km['so_no'] ?? '';
                    $ar['wo_no'] = $km['wo_no'] ?? '';
                    $aju[] = $ar;
                }
            }
        }
    }

    echo json_encode(['status' => 'OK', 'header' => $header, 'lines' => $lines, 'komponen' => $komponen, 'aju' => $aju]);
    exit;
}

// 11.B SINKRONISASI TRACE KE DOKUMEN EKS-BC CEISA 4.0 (bc_si_aju_ceisa)
if ($action === 'sync_trace_ceisa') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $siId = trim($input['id'] ?? '');
    $items = $input['aju'] ?? [];

    if (empty($siId) || empty($items)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Data trace kosong atau belum dimuat']);
        exit;
    }

    $escSiId = $koneksi->real_escape_string($siId);
    $dtlRes = $koneksi->query("SELECT id FROM bc_si_dtl WHERE id_hdr = '{$escSiId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
    $idList = [];
    if ($dtlRes) while ($r = $dtlRes->fetch_assoc()) $idList[] = "'" . $koneksi->real_escape_string($r['id']) . "'";

    if (!empty($idList)) {
        $koneksi->query("DELETE FROM bc_si_aju_ceisa WHERE id_sidtl IN (" . implode(',', $idList) . ") AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
    }

    $stmt = $koneksi->prepare("INSERT INTO bc_si_aju_ceisa (
        cmp_id, id, id_sidtl, id_sidtl2, id_bom, id_issuehdr, nomor_aju, seri_barang, jenis_bc,
        no_daftar, tgl_daftar, kode_brg, nama_brg, qty_use, sat, wo_no, so_no, set_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $saved = 0;
    foreach ($items as $it) {
        $uid = uniqid('CEISA_AJU_');
        $idSidtl = trim($it['id_sidtl'] ?? '');
        $idSidtl2 = trim($it['id_sidtl2'] ?? '');
        $idBom = trim($it['id_bom'] ?? '');
        $idIssue = trim($it['id_issuehdr'] ?? '');
        $noAju = trim($it['nomor_aju'] ?? '');
        $seri = (int)($it['seri_barang'] ?? 1);
        $jnsBc = trim($it['jenis_bc'] ?? '');
        $noDaf = trim($it['no_daftar'] ?? '');
        $tglDaf = trim($it['tgl_daftar'] ?? '');
        $kdBrg = trim($it['kode_brg'] ?? '');
        $nmBrg = trim($it['nama_brg'] ?? '');
        $qtyUse = (float)($it['qty_use'] ?? 0);
        $sat = trim($it['sat'] ?? 'PCE');
        $woNo = trim($it['wo_no'] ?? '');
        $soNo = trim($it['so_no'] ?? '');
        $userSet = $_SESSION['user_id'] ?? 'SYSTEM';

        if (empty($noAju) && empty($kdBrg)) continue;

        $stmt->bind_param("sssssssisssssdssss",
            $sessionCmpId, $uid, $idSidtl, $idSidtl2, $idBom, $idIssue, $noAju, $seri, $jnsBc,
            $noDaf, $tglDaf, $kdBrg, $nmBrg, $qtyUse, $sat, $woNo, $soNo, $userSet
        );
        $stmt->execute();
        $saved++;
    }

    echo json_encode(['status' => 'OK', 'message' => "Berhasil menyinkronkan {$saved} dokumen Eks-BC ke CEISA 4.0"]);
    exit;
}

// 12. RIWAYAT HARGA PENJUALAN / PEMBELIAN (History Harga PB w_si.srw)
if ($action === 'history_price') {
    header('Content-Type: application/json; charset=utf-8');
    $kd = trim($_GET['kode_barang'] ?? '');
    $buyer = trim($_GET['buyer'] ?? '');
    if (empty($kd)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Kode barang harus diisi', 'data' => []]);
        exit;
    }
    $escKd = $koneksi->real_escape_string($kd);
    $escBuyer = $koneksi->real_escape_string($buyer);

    // 1. Dari Transaksi Sales Invoice (bc_si_dtl & bc_si_hdr)
    $whereSi = ["d.kode_barang = '{$escKd}'", "(h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')"];
    if (!empty($escBuyer)) {
        $whereSi[] = "(h.vendor = '{$escBuyer}')";
    }
    $whereSiStr = implode(' AND ', $whereSi);

    $sqlSi = "SELECT h.si_no as no_bukti, h.si_dt as tgl_bukti, 'SI' as tipe_dok,
                     h.vendor as buyer_kode, COALESCE(b.nama, h.vendor) as buyer_nama,
                     h.kurs, h.rate, d.qty, d.sat, d.price, d.disc, d.amount,
                     d.color_cd, d.size_cd
              FROM bc_si_dtl d
              JOIN bc_si_hdr h ON d.id_hdr = h.id
              LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR '{$escCmp}' = ''))
              WHERE {$whereSiStr}
              ORDER BY h.si_dt DESC, h.si_no DESC
              LIMIT 30";
    $resSi = $koneksi->query($sqlSi);
    $history = [];
    if ($resSi) while ($r = $resSi->fetch_assoc()) $history[] = $r;

    // 2. Dari Sales Order (bc_so_dtl & bc_so_hdr)
    $whereSo = ["d.kode_barang = '{$escKd}'", "(h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')"];
    if (!empty($escBuyer)) {
        $whereSo[] = "(h.vendor = '{$escBuyer}')";
    }
    $whereSoStr = implode(' AND ', $whereSo);

    $sqlSo = "SELECT h.so_no as no_bukti, h.so_dt as tgl_bukti, 'SO' as tipe_dok,
                     h.vendor as buyer_kode, COALESCE(b.nama, h.vendor) as buyer_nama,
                     h.kurs, h.rate, d.qty, d.sat, d.price, d.disc, d.amount,
                     d.color_cd, d.size_cd
              FROM bc_so_dtl d
              JOIN bc_so_hdr h ON d.id_hdr = h.id
              LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR '{$escCmp}' = ''))
              WHERE {$whereSoStr}
              ORDER BY h.so_dt DESC, h.so_no DESC
              LIMIT 20";
    $resSo = @$koneksi->query($sqlSo);
    if ($resSo) while ($r = $resSo->fetch_assoc()) $history[] = $r;

    // Urutkan berdasarkan tanggal terbaru
    usort($history, function($a, $b) {
        return strcmp($b['tgl_bukti'], $a['tgl_bukti']);
    });

    // Info master barang
    $matRes = $koneksi->query("SELECT KODE_BARANG as kode_barang, URAIAN as uraian, IFNULL(KODE_SATUAN, 'PCE') as sat, IFNULL(UNIT_PRICE, 0) as price_master FROM bc_material WHERE KODE_BARANG = '{$escKd}' AND (cmp_id = '{$escCmp}' OR cmp_id = '') LIMIT 1");
    $mat = ($matRes && ($rm = $matRes->fetch_assoc())) ? $rm : null;

    echo json_encode([
        'status' => 'OK',
        'material' => $mat,
        'data' => $history
    ]);
    exit;
}

// 13. CEK KETERSEDIAAN STOK FISIK / IT INVENTORY (w_si.srw - Cek Stok Kurang)
if ($action === 'check_stock') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true) ?: $_POST;
    $items = $req['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'Tidak ada item yang dicek.']);
        exit;
    }

    $reqMap = [];
    $itemNames = [];
    $itemSats = [];
    foreach ($items as $it) {
        $kd = trim($it['kode_barang'] ?? '');
        if (!$kd) continue;
        $qty = (float)($it['qty'] ?? 0);
        $reqMap[$kd] = ($reqMap[$kd] ?? 0) + $qty;
        if (!empty($it['uraian'])) $itemNames[$kd] = $it['uraian'];
        if (!empty($it['sat'])) $itemSats[$kd] = $it['sat'];
    }

    if (empty($reqMap)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Belum ada kode barang yang valid pada rincian.']);
        exit;
    }

    $kdList = array_map(function($k) use ($koneksi) {
        return "'" . $koneksi->real_escape_string($k) . "'";
    }, array_keys($reqMap));
    $inSql = implode(',', $kdList);

    // Ambil saldo stok riil dari bc_1ab2 (IT Inventory)
    // Stok = Masuk (A) - Keluar (B)
    $stockMap = [];
    $sqlStk = "SELECT KODE_BARANG, URAIAN, UNIT,
                      SUM(CASE WHEN AB = 'A' THEN COALESCE(QTY_AKTUAL, QTY) ELSE -COALESCE(QTY_AKTUAL, QTY) END) as stok_akhir
               FROM bc_1ab2
               WHERE KODE_BARANG IN ({$inSql}) AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)
               GROUP BY KODE_BARANG, UNIT";
    $resStk = @$koneksi->query($sqlStk);
    if ($resStk) {
        while ($r = $resStk->fetch_assoc()) {
            $stockMap[$r['KODE_BARANG']] = (float)$r['stok_akhir'];
            if (empty($itemNames[$r['KODE_BARANG']]) && !empty($r['URAIAN'])) {
                $itemNames[$r['KODE_BARANG']] = $r['URAIAN'];
            }
            if (empty($itemSats[$r['KODE_BARANG']]) && !empty($r['UNIT'])) {
                $itemSats[$r['KODE_BARANG']] = $r['UNIT'];
            }
        }
    }

    // Ambil info master barang untuk nama jika belum ada
    $sqlMat = "SELECT KODE_BARANG, URAIAN, KODE_SATUAN, STOK_MINIMAL FROM bc_material WHERE KODE_BARANG IN ({$inSql}) AND (cmp_id = '{$escCmp}' OR cmp_id = '')";
    $resMat = @$koneksi->query($sqlMat);
    if ($resMat) {
        while ($r = $resMat->fetch_assoc()) {
            if (empty($itemNames[$r['KODE_BARANG']])) $itemNames[$r['KODE_BARANG']] = $r['URAIAN'];
            if (empty($itemSats[$r['KODE_BARANG']])) $itemSats[$r['KODE_BARANG']] = $r['KODE_SATUAN'];
        }
    }

    $result = [];
    $totalDefisit = 0;
    foreach ($reqMap as $kd => $qtyFaktur) {
        $stokGudang = $stockMap[$kd] ?? 0.0;
        $selisih = $stokGudang - $qtyFaktur;
        $isDefisit = ($selisih < 0);
        if ($isDefisit) $totalDefisit++;

        $result[] = [
            'kode_barang' => $kd,
            'uraian' => $itemNames[$kd] ?? $kd,
            'sat' => $itemSats[$kd] ?? 'PCE',
            'qty_faktur' => $qtyFaktur,
            'stok_gudang' => $stokGudang,
            'selisih' => abs($selisih),
            'is_defisit' => $isDefisit,
            'status' => $isDefisit ? 'DEFISIT' : 'AMAN'
        ];
    }

    echo json_encode([
        'status' => 'OK',
        'has_deficit' => ($totalDefisit > 0),
        'total_deficit_items' => $totalDefisit,
        'total_items_checked' => count($result),
        'summary' => [
            'total_items' => count($result),
            'deficit_count' => $totalDefisit,
            'safe_count' => count($result) - $totalDefisit
        ],
        'items' => $result,
        'data' => $result
    ]);
    exit;
}

// ==========================================
// RENDER PRINT VIEW (COMMERCIAL INVOICE)
// ==========================================
$isPrint = ($action === 'print');
if ($isPrint) {
    $id = trim($_GET['id'] ?? $_POST['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $hdr = null;
    $items = [];
    $draft = json_decode($_POST['payload'] ?? '', true);

    if ($escId !== '') {
        try {
            $hdrRes = $koneksi->query("SELECT h.*, COALESCE(b.nama, h.vendor) as buyer_nama, IFNULL(b.alamat,'') AS alamat, IFNULL(b.npwp,'') AS npwp, IFNULL(b.nitku,'') AS nitku
                                       FROM bc_si_hdr h
                                       LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = ''))
                                       WHERE h.id = '{$escId}' LIMIT 1");
            $hdr = $hdrRes ? $hdrRes->fetch_assoc() : null;
            $dtlRes = $koneksi->query("SELECT * FROM bc_si_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
            if ($dtlRes) while ($r = $dtlRes->fetch_assoc()) $items[] = $r;
        } catch (Throwable $e) {
            $hdr = null;
        }
    } elseif (is_array($draft)) {
        $hdr = $draft;
        $items = is_array($draft['items'] ?? null) ? $draft['items'] : [];
    }

    if (!$hdr) {
        echo '<p style="font-family:Poppins,Segoe UI,sans-serif; padding:20px;">Invoice tidak ditemukan. Simpan transaksi, atau isi barang lalu cetak lagi.</p>';
        exit;
    }

    $cmp = [
        'nama' => $_SESSION['company_name'] ?? 'PT. PERUSAHAAN',
        'alamat' => '',
        'npwp' => '',
        'nib' => '',
        'logo' => '',
    ];
    try {
        $cmpRes = $koneksi->query("SELECT cmp_name AS nama, IFNULL(alamat,'') AS alamat, IFNULL(npwp,'') AS npwp, IFNULL(nib,'') AS nib, IFNULL(logo,'') AS logo FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
        if ($cmpRes && ($row = $cmpRes->fetch_assoc()) && !empty($row['nama'])) $cmp = $row;
    } catch (Throwable $e) {}

    $cmpLogo = trim($cmp['logo'] ?? '');
    if (!empty($cmpLogo) && !preg_match('#^(https?://|/)#i', $cmpLogo)) {
        $cmpLogo = '/' . $cmpLogo;
    }
    if (empty($cmpLogo)) {
        $cmpLogo = '/assets/logo.png';
    }

    $grandTotal = (float)($hdr['total_order'] ?? 0);
    $sayIndo = terbilangIndonesia($grandTotal) . " Rupiah";
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Commercial Invoice - <?= htmlspecialchars($hdr['si_no'] ?? '') ?></title>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
        <style>
            * { box-sizing: border-box; }
            body { font-family: 'Poppins', 'Segoe UI', Arial, sans-serif; font-size: 9.5pt; color: #111; margin: 15mm; line-height: 1.4; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; border-bottom: 2px solid #1890ff; padding-bottom: 8px; }
            .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 9pt; }
            .info-table td { padding: 3px 6px; vertical-align: top; }
            .items-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 9pt; }
            .items-table th, .items-table td { border: 1px solid #444; padding: 5px 6px; }
            .items-table th { background: #f0f2f5; text-align: center; font-weight: 600; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .sign-table { width: 100%; border-collapse: collapse; margin-top: 20px; page-break-inside: avoid; }
            .sign-table td { border: 1px solid #444; padding: 6px; text-align: center; font-size: 8.5pt; width: 33.3%; }
            .sign-space { height: 55px; }
            .say-box { background: #f9fbfd; border: 1px dashed #91d5ff; padding: 6px 10px; margin-bottom: 10px; font-size: 8.5pt; font-style: italic; }
            @media print {
                .no-print { display: none !important; }
                body { margin: 8mm 10mm; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px;">
            <button onclick="window.print()" style="padding: 6px 14px; background:#1890ff; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:600;">Cetak Faktur</button>
            <button onclick="window.close()" style="padding: 6px 14px; background:#fff; color:#262626; border:1px solid #d9d9d9; border-radius:3px; cursor:pointer; margin-left:6px;">Tutup</button>
        </div>

        <table class="header-table">
            <tr>
                <td style="width: 60%; vertical-align: middle;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <?php if (!empty($cmpLogo)): ?>
                        <img src="<?= htmlspecialchars($cmpLogo) ?>" alt="Logo Perusahaan" style="max-height:55px; max-width:140px; object-fit:contain;">
                        <?php endif; ?>
                        <div>
                            <div style="font-size: 13pt; font-weight: 700; color:#002766; text-transform:uppercase;"><?= htmlspecialchars($cmp['nama'] ?? 'PT. PERUSAHAAN') ?></div>
                            <div style="font-size: 8.5pt; color:#444; margin-top: 2px;"><?= nl2br(htmlspecialchars($cmp['alamat'] ?? '')) ?></div>
                            <div style="font-size: 8.5pt; color:#333; margin-top: 2px;">NPWP: <strong><?= htmlspecialchars($cmp['npwp'] ?? '-') ?></strong> <?= !empty($cmp['nib']) ? ' | NIB: ' . htmlspecialchars($cmp['nib']) : '' ?></div>
                        </div>
                    </div>
                </td>
                <td style="width: 40%; text-align: right; vertical-align: middle;">
                    <div style="font-size: 15pt; font-weight: 700; color: #1890ff; letter-spacing: 0.5px;">FAKTUR PENJUALAN</div>
                    <div style="font-size: 9pt; color: #595959; font-weight: 600;">COMMERCIAL SALES INVOICE</div>
                    <div style="font-size: 10.5pt; font-weight: 700; color: #111; margin-top: 4px;">NO: <?= htmlspecialchars($hdr['si_no'] ?? '') ?></div>
                </td>
            </tr>
        </table>

        <table class="info-table">
            <tr>
                <td style="width: 15%;"><strong>Kepada (Buyer)</strong></td>
                <td style="width: 35%;">: <strong style="font-size: 9.5pt;"><?= htmlspecialchars($hdr['buyer_nama'] ?? $hdr['vendor']) ?></strong></td>
                <td style="width: 15%;"><strong>Tgl Invoice</strong></td>
                <td style="width: 35%;">: <?= htmlspecialchars($hdr['si_dt'] ?? '') ?></td>
            </tr>
            <tr>
                <td><strong>Alamat Buyer</strong></td>
                <td>: <?= htmlspecialchars($hdr['alamat'] ?? '-') ?></td>
                <td><strong>Tgl Kirim</strong></td>
                <td>: <?= htmlspecialchars($hdr['ship_dt'] ?? '-') ?></td>
            </tr>
            <tr>
                <td><strong>NPWP / NITKU</strong></td>
                <td>: <?= htmlspecialchars($hdr['npwp'] ?? '-') ?> <?= !empty($hdr['nitku']) ? ' / ' . htmlspecialchars($hdr['nitku']) : '' ?></td>
                <td><strong>Jatuh Tempo</strong></td>
                <td>: <strong><?= htmlspecialchars($hdr['jatuhtempo'] ?? '-') ?></strong> (Term: <?= htmlspecialchars($hdr['term'] ?? 'Net 30') ?>)</td>
            </tr>
            <tr>
                <td><strong>No. Surat Jalan (DO)</strong></td>
                <td>: <?= htmlspecialchars($hdr['do_no'] ?? '-') ?> <?= !empty($hdr['so_no']) ? '(SO: ' . htmlspecialchars($hdr['so_no']) . ')' : '' ?></td>
                <td><strong>Faktur Pajak</strong></td>
                <td>: <strong><?= !empty($hdr['no_fp']) ? htmlspecialchars($hdr['kd_fp'] ?? '01') . '.' . htmlspecialchars($hdr['no_fp']) : '-' ?></strong></td>
            </tr>
            <tr>
                <td><strong>Dokumen Pabean</strong></td>
                <td>: <?= !empty($hdr['bc']) ? 'BC ' . htmlspecialchars($hdr['bc']) . ' (No: ' . htmlspecialchars($hdr['no_bc'] ?? '-') . ' Tgl: ' . htmlspecialchars($hdr['tgl_bc'] ?? '-') . ')' : '-' ?></td>
                <td><strong>Nomor AJU</strong></td>
                <td>: <code><?= htmlspecialchars($hdr['no_aju'] ?? '-') ?></code></td>
            </tr>
            <?php if (!empty($hdr['po_no'])): ?>
            <tr>
                <td><strong>No. PO Customer</strong></td>
                <td colspan="3">: <?= htmlspecialchars($hdr['po_no']) ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 4%;">No</th>
                    <th style="width: 16%;">Kode Barang</th>
                    <th>Nama &amp; Uraian Barang</th>
                    <th style="width: 7%;">Sat</th>
                    <th style="width: 10%;">Qty</th>
                    <th style="width: 12%;">Harga Sat</th>
                    <th style="width: 7%;">Disc(%)</th>
                    <th style="width: 14%;">Total Nilai</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="8" class="text-center" style="padding: 15px; color:#888;">Tidak ada rincian barang.</td></tr>
                <?php else:
                    $no = 1;
                    $totQty = 0;
                    foreach ($items as $it):
                        $qty = (float)($it['qty'] ?? 0);
                        $totQty += $qty;
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><strong><?= htmlspecialchars($it['kode_barang']) ?></strong></td>
                    <td><?= htmlspecialchars($it['uraian']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($it['sat']) ?></td>
                    <td class="text-right"><strong><?= number_format($qty, 2, ',', '.') ?></strong></td>
                    <td class="text-right"><?= number_format((float)$it['price'], 2, ',', '.') ?></td>
                    <td class="text-right"><?= (float)($it['disc'] ?? 0) > 0 ? number_format((float)$it['disc'], 2, ',', '.') : '-' ?></td>
                    <td class="text-right"><?= number_format((float)$it['amount'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="4" class="text-right"><strong>Total Kuantitas:</strong></td>
                    <td class="text-right"><strong><?= number_format($totQty, 2, ',', '.') ?></strong></td>
                    <td colspan="2" class="text-right"><strong>Subtotal:</strong></td>
                    <td class="text-right"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)$hdr['subtotal'], 2, ',', '.') ?></td>
                </tr>
                <?php if ((float)($hdr['disc_amt'] ?? 0) > 0): ?>
                <tr>
                    <td colspan="5" rowspan="6" style="vertical-align: top; background:#fafafa; font-size: 8.5pt;">
                        <strong>Catatan Pembayaran &amp; Rekening:</strong><br>
                        Pembayaran dapat ditransfer ke rekening resmi perusahaan:<br>
                        <strong>Bank: BCA / Mandiri</strong><br>
                        A/N: <strong><?= htmlspecialchars($cmp['nama'] ?? 'PERUSAHAAN') ?></strong><br>
                        <?php if (!empty($hdr['note'])): ?>
                        <div style="margin-top:6px;"><strong>Keterangan:</strong> <?= nl2br(htmlspecialchars($hdr['note'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td colspan="2" class="text-right">Diskon (<?= number_format((float)$hdr['disc_persen'], 2, ',', '.') ?>%):</td>
                    <td class="text-right">- <?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)$hdr['disc_amt'], 2, ',', '.') ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td colspan="5" rowspan="5" style="vertical-align: top; background:#fafafa; font-size: 8.5pt;">
                        <strong>Catatan Pembayaran &amp; Rekening:</strong><br>
                        Pembayaran dapat ditransfer ke rekening resmi perusahaan:<br>
                        <strong>Bank: BCA / Mandiri</strong><br>
                        A/N: <strong><?= htmlspecialchars($cmp['nama'] ?? 'PERUSAHAAN') ?></strong><br>
                        <?php if (!empty($hdr['note'])): ?>
                        <div style="margin-top:6px;"><strong>Keterangan:</strong> <?= nl2br(htmlspecialchars($hdr['note'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td colspan="2" class="text-right"><strong>PPN (11%):</strong></td>
                    <td class="text-right"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)$hdr['ppn'], 2, ',', '.') ?></td>
                </tr>
                <?php endif; ?>

                <?php if ((float)($hdr['disc_amt'] ?? 0) > 0): ?>
                <tr>
                    <td colspan="2" class="text-right"><strong>PPN (11%):</strong></td>
                    <td class="text-right"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)$hdr['ppn'], 2, ',', '.') ?></td>
                </tr>
                <?php endif; ?>

                <?php if ((float)($hdr['pph'] ?? 0) > 0): ?>
                <tr>
                    <td colspan="2" class="text-right">PPh Pasal 23 (2%):</td>
                    <td class="text-right">- <?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)$hdr['pph'], 2, ',', '.') ?></td>
                </tr>
                <?php endif; ?>

                <?php if ((float)($hdr['est_freight'] ?? 0) > 0): ?>
                <tr>
                    <td colspan="2" class="text-right">Ongkos Kirim (Freight):</td>
                    <td class="text-right"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)$hdr['est_freight'], 2, ',', '.') ?></td>
                </tr>
                <?php endif; ?>

                <tr style="font-weight: 700; background:#f0f7ff;">
                    <td colspan="2" class="text-right" style="color:#002766; font-size: 10pt;">GRAND TOTAL:</td>
                    <td class="text-right" style="color:#002766; font-size: 10.5pt;"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format($grandTotal, 2, ',', '.') ?></td>
                </tr>
                <tr style="font-weight: 600;">
                    <td colspan="2" class="text-right">Sisa Piutang:</td>
                    <td class="text-right"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)($hdr['outstand'] ?? ($grandTotal - (float)($hdr['terbayar'] ?? 0))), 2, ',', '.') ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="say-box">
            <strong>Terbilang (Say):</strong> <em># <?= htmlspecialchars($sayIndo) ?> #</em>
        </div>

        <table class="sign-table">
            <tr>
                <td>Dibuat Oleh (Billing)</td>
                <td>Diperiksa Oleh (Accounting)</td>
                <td>Disetujui Oleh (Finance Director)</td>
            </tr>
            <tr>
                <td class="sign-space"></td>
                <td class="sign-space"></td>
                <td class="sign-space"></td>
            </tr>
            <tr>
                <td>( .................................... )<br><span style="font-size: 7.5pt; color:#666;">Staff Administrasi Penjualan</span></td>
                <td>( .................................... )<br><span style="font-size: 7.5pt; color:#666;">Head of Accounting</span></td>
                <td>( .................................... )<br><span style="font-size: 7.5pt; color:#666;">Direktur Keuangan</span></td>
            </tr>
        </table>
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// RENDER PRINT VIEW (TRACEABILITY REPORT)
// ==========================================
if ($action === 'print_trace') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $hdr = null;
    $lines = [];
    $komponen = [];
    $aju = [];

    if ($escId !== '') {
        $hdrRes = $koneksi->query("SELECT h.*, COALESCE(b.nama, h.vendor) as buyer_nama, IFNULL(b.alamat,'') AS alamat, IFNULL(b.npwp,'') AS npwp
                                   FROM bc_si_hdr h
                                   LEFT JOIN bc_bs b ON (h.vendor = b.kode AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = ''))
                                   WHERE h.id = '{$escId}' LIMIT 1");
        if ($hdrRes) $hdr = $hdrRes->fetch_assoc();

        $dtlRes = $koneksi->query("SELECT id, seq, kode_barang, uraian, color_cd, size_cd, qty, sat, do_no, so_no, po_no FROM bc_si_dtl WHERE id_hdr = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') ORDER BY seq ASC");
        if ($dtlRes) while ($r = $dtlRes->fetch_assoc()) $lines[] = $r;

        $idList = [];
        foreach ($lines as $ln) if (!empty($ln['id'])) $idList[] = "'" . $koneksi->real_escape_string($ln['id']) . "'";
        if ($idList) {
            $in = implode(',', $idList);
            $komRes = @$koneksi->query("SELECT * FROM bc_si_dtl2 WHERE id_sidtl IN ({$in})");
            if ($komRes) while ($r = $komRes->fetch_assoc()) $komponen[] = $r;
            $komIds = [];
            foreach ($komponen as $k) if (!empty($k['id'])) $komIds[] = "'" . $koneksi->real_escape_string($k['id']) . "'";
            if ($komIds) {
                $ajuRes = @$koneksi->query("SELECT * FROM bc_si_aju WHERE id_sidtl2 IN (" . implode(',', $komIds) . ")");
                if ($ajuRes) while ($r = $ajuRes->fetch_assoc()) $aju[] = $r;
            }
        }

        // Fallback BOM
        if (empty($komponen) && !empty($lines)) {
            foreach ($lines as $ln) {
                $kd = $koneksi->real_escape_string($ln['kode_barang']);
                $qtyFg = (float)$ln['qty'];
                $bRes = @$koneksi->query("SELECT b.*, m.URAIAN as nama_bahan, m.UNIT as sat_bahan 
                                          FROM bc_bom b 
                                          LEFT JOIN bc_material m ON (b.kode_bahan = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = ''))
                                          WHERE b.kode_barang = '{$kd}' AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')");
                if ($bRes) {
                    while ($br = $bRes->fetch_assoc()) {
                        $komponen[] = [
                            'id' => 'BOM-' . $br['id'],
                            'id_sidtl' => $ln['id'],
                            'kode_fg' => $ln['kode_barang'],
                            'kode_bahan' => $br['kode_bahan'],
                            'uraian' => $br['nama_bahan'] ?: ($br['uraian'] ?: $br['kode_bahan']),
                            'qty_taken' => ($br['koefisien'] ?? 1) * $qtyFg,
                            'sat' => $br['sat_bahan'] ?: ($br['sat'] ?: 'PCE'),
                            'total_cost' => ($br['harga'] ?? 0) * (($br['koefisien'] ?? 1) * $qtyFg)
                        ];
                    }
                }
            }
        }

        // Fallback Dokumen Pabean Asal
        if (empty($aju) && !empty($komponen)) {
            foreach ($komponen as $km) {
                $kdBahan = $koneksi->real_escape_string($km['kode_bahan'] ?? $km['kode_barang'] ?? '');
                if (!$kdBahan) continue;
                $aRes = @$koneksi->query("SELECT BC as jenis_bc, NOMOR_AJU as nomor_aju, NOMOR_DAFTAR as no_daftar, TANGGAL_DAFTAR as tgl_daftar, KODE_BARANG as kode_brg, URAIAN as nama_brg, QTY as qty_use, UNIT as sat
                                          FROM bc_1ab2 
                                          WHERE AB = 'A' AND KODE_BARANG = '{$kdBahan}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')
                                          ORDER BY TANGGAL_DAFTAR DESC LIMIT 3");
                if ($aRes) {
                    while ($ar = $aRes->fetch_assoc()) {
                        $ar['id_sidtl2'] = $km['id'] ?? '';
                        $ar['kode_fg'] = $km['kode_fg'] ?? '';
                        $aju[] = $ar;
                    }
                }
            }
        }
    }

    if (!$hdr) {
        echo '<p style="font-family:Poppins,sans-serif; padding:20px;">Invoice tidak ditemukan untuk dicetak penelusurannya.</p>';
        exit;
    }

    $cmp = ['nama' => $_SESSION['company_name'] ?? 'PT. PERUSAHAAN', 'alamat' => '', 'npwp' => ''];
    $cmpRes = $koneksi->query("SELECT cmp_name AS nama, IFNULL(alamat,'') AS alamat, IFNULL(npwp,'') AS npwp FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($cmpRes && ($row = $cmpRes->fetch_assoc()) && !empty($row['nama'])) $cmp = $row;
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Traceability Report - <?= htmlspecialchars($hdr['si_no']) ?></title>
        <style>
            @page { size: A4 landscape; margin: 8mm; }
            body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 8.5pt; color: #222; margin: 0; padding: 12px; background: #fff; }
            .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; border-bottom: 2px solid #1890ff; padding-bottom: 6px; }
            .hdr-table td { vertical-align: top; }
            .company-name { font-size: 13pt; font-weight: 700; color: #003a8c; letter-spacing: 0.5px; }
            .doc-title { font-size: 12pt; font-weight: 700; color: #1890ff; text-align: right; text-transform: uppercase; }
            .doc-sub { font-size: 8pt; color: #666; text-align: right; }
            .meta-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; background: #f0f5ff; border: 1px solid #adc6ff; border-radius: 4px; padding: 6px 10px; margin-bottom: 10px; font-size: 8pt; }
            .meta-item { display: flex; flex-direction: column; }
            .meta-lbl { font-size: 7pt; color: #595959; text-transform: uppercase; font-weight: 600; }
            .meta-val { font-size: 8.5pt; font-weight: 700; color: #1d39c4; }
            .fg-card { border: 1px solid #d9d9d9; border-radius: 4px; margin-bottom: 10px; overflow: hidden; page-break-inside: avoid; }
            .fg-hdr { background: #fafafa; border-bottom: 1px solid #d9d9d9; padding: 5px 10px; display: flex; justify-content: space-between; align-items: center; font-size: 8.5pt; }
            .fg-title { font-weight: 700; color: #002766; }
            .fg-badge { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; padding: 1px 6px; border-radius: 3px; font-weight: 600; font-size: 8pt; }
            .trace-tables { display: grid; grid-template-columns: 1.1fr 1.3fr; gap: 8px; padding: 6px; }
            table.sub-tbl { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
            table.sub-tbl th { background: #f5f5f5; border: 1px solid #e8e8e8; padding: 3px 5px; text-align: left; font-weight: 600; color: #595959; }
            table.sub-tbl td { border: 1px solid #e8e8e8; padding: 3px 5px; vertical-align: top; }
            table.sub-tbl tr:nth-child(even) td { background: #fafafa; }
            .tbl-title { font-size: 8pt; font-weight: 700; margin-bottom: 3px; color: #262626; display: flex; align-items: center; gap: 4px; }
            .num { text-align: right; }
            .sign-table { width: 100%; border-collapse: collapse; margin-top: 14px; page-break-inside: avoid; font-size: 8pt; }
            .sign-table td { width: 25%; text-align: center; vertical-align: top; padding: 4px; }
            .sign-box { height: 48px; }
            @media print {
                body { padding: 0; }
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 10px; display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" onclick="window.print()" style="padding: 6px 14px; background: #1890ff; color: #fff; border: none; border-radius: 3px; cursor: pointer; font-weight: 600;">🖨️ Cetak Dokumen</button>
            <button type="button" onclick="window.close()" style="padding: 6px 14px; background: #f5f5f5; border: 1px solid #d9d9d9; border-radius: 3px; cursor: pointer;">Tutup</button>
        </div>

        <table class="hdr-table">
            <tr>
                <td>
                    <div class="company-name"><?= htmlspecialchars($cmp['nama']) ?></div>
                    <div style="font-size: 8pt; color: #595959;"><?= htmlspecialchars($cmp['alamat']) ?></div>
                    <div style="font-size: 8pt; color: #595959;">NPWP: <?= htmlspecialchars($cmp['npwp']) ?></div>
                </td>
                <td>
                    <div class="doc-title">Laporan Penelusuran Bahan Baku (Traceability Report)</div>
                    <div class="doc-sub">Keterkaitan Barang Jadi &bull; Bill of Material &bull; Dokumen Pemasukan Eks-BC</div>
                </td>
            </tr>
        </table>

        <div class="meta-grid">
            <div class="meta-item"><span class="meta-lbl">No. Sales Invoice</span><span class="meta-val"><?= htmlspecialchars($hdr['si_no']) ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Tgl. Invoice</span><span class="meta-val"><?= htmlspecialchars(substr($hdr['si_dt'], 0, 10)) ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Buyer / Customer</span><span class="meta-val"><?= htmlspecialchars($hdr['buyer_nama'] ?: $hdr['vendor']) ?></span></div>
            <div class="meta-item"><span class="meta-lbl">No. Surat Jalan (DO)</span><span class="meta-val"><?= htmlspecialchars($hdr['do_no'] ?: '-') ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Dokumen Pabean (Out)</span><span class="meta-val"><?= $hdr['bc'] ? ('BC ' . htmlspecialchars($hdr['bc'])) : 'Lokal / Non-BC' ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Nomor &amp; Tgl Daftar BC</span><span class="meta-val"><?= htmlspecialchars($hdr['no_bc'] ?: '-') ?> <?= !empty($hdr['tgl_bc']) ? ('(' . substr($hdr['tgl_bc'], 0, 10) . ')') : '' ?></span></div>
            <div class="meta-item"><span class="meta-lbl">Nomor Pengajuan (AJU)</span><span class="meta-val"><code><?= htmlspecialchars($hdr['no_aju'] ?: '-') ?></code></span></div>
            <div class="meta-item"><span class="meta-lbl">Total Item Output</span><span class="meta-val"><?= count($lines) ?> Item Barang Jadi</span></div>
        </div>

        <?php foreach ($lines as $idx => $fg): 
            $fgKom = array_filter($komponen, function($k) use ($fg) {
                return (isset($k['id_sidtl']) && $k['id_sidtl'] == $fg['id']) || (isset($k['kode_fg']) && $k['kode_fg'] == $fg['kode_barang']);
            });
            $fgAju = array_filter($aju, function($a) use ($fg) {
                return (isset($a['kode_fg']) && $a['kode_fg'] == $fg['kode_barang']);
            });
            if (empty($fgAju)) $fgAju = $aju; // Tampilkan seluruh dokumen pabean jika tidak terikat per item
        ?>
        <div class="fg-card">
            <div class="fg-hdr">
                <span class="fg-title">#<?= $idx + 1 ?> &bull; <?= htmlspecialchars($fg['kode_barang']) ?> &mdash; <?= htmlspecialchars($fg['uraian']) ?></span>
                <span class="fg-badge">Output: <?= number_format((float)$fg['qty'], 2, ',', '.') ?> <?= htmlspecialchars($fg['sat']) ?> &bull; DO: <?= htmlspecialchars($fg['do_no'] ?: '-') ?> &bull; SO: <?= htmlspecialchars($fg['so_no'] ?: '-') ?></span>
            </div>
            <div class="trace-tables">
                <div>
                    <div class="tbl-title">📦 Komponen Pemakaian Bahan Baku (Issue BOM)</div>
                    <table class="sub-tbl">
                        <thead>
                            <tr>
                                <th style="width: 25px; text-align:center;">#</th>
                                <th>Kode Bahan</th>
                                <th>Uraian Bahan Baku</th>
                                <th style="width: 70px; text-align:right;">Qty Ambil</th>
                                <th style="width: 40px; text-align:center;">Sat</th>
                                <th style="width: 75px; text-align:right;">Total Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fgKom)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#999; padding:6px;">Tidak ada komponen bahan baku tercatat.</td></tr>
                            <?php else: $kNo = 1; foreach ($fgKom as $km): ?>
                            <tr>
                                <td style="text-align:center;"><?= $kNo++ ?></td>
                                <td><strong><?= htmlspecialchars($km['kode_bahan'] ?? $km['kode_barang'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($km['uraian'] ?? '-') ?></td>
                                <td class="num"><strong><?= number_format((float)($km['qty_taken'] ?? $km['qty'] ?? 0), 4, ',', '.') ?></strong></td>
                                <td style="text-align:center;"><?= htmlspecialchars($km['sat'] ?? 'PCE') ?></td>
                                <td class="num"><?= number_format((float)($km['total_cost'] ?? 0), 2, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div>
                    <div class="tbl-title">🛡️ Dokumen Pabean Pemasukan Asal (Eks-BC)</div>
                    <table class="sub-tbl">
                        <thead>
                            <tr>
                                <th style="width: 25px; text-align:center;">#</th>
                                <th style="width: 45px;">Jenis</th>
                                <th>No. AJU Pemasukan</th>
                                <th>No. Daftar BC</th>
                                <th style="width: 65px;">Tgl Daftar</th>
                                <th>Kode Brg Asal</th>
                                <th style="width: 60px; text-align:right;">Qty Pakai</th>
                                <th style="width: 35px; text-align:center;">Sat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fgAju)): ?>
                            <tr><td colspan="8" style="text-align:center; color:#999; padding:6px;">Belum ada dokumen pemasukan pabean asal tertaut.</td></tr>
                            <?php else: $aNo = 1; foreach ($fgAju as $aj): ?>
                            <tr>
                                <td style="text-align:center;"><?= $aNo++ ?></td>
                                <td><span style="font-weight:700; color:#1890ff;">BC <?= htmlspecialchars($aj['jenis_bc'] ?? $aj['BC'] ?? '') ?></span></td>
                                <td><code><?= htmlspecialchars($aj['nomor_aju'] ?? $aj['NOMOR_AJU'] ?? '') ?></code></td>
                                <td><?= htmlspecialchars($aj['no_daftar'] ?? $aj['NOMOR_DAFTAR'] ?? '-') ?></td>
                                <td><?= htmlspecialchars(substr($aj['tgl_daftar'] ?? $aj['TANGGAL_DAFTAR'] ?? '', 0, 10)) ?></td>
                                <td><?= htmlspecialchars($aj['kode_brg'] ?? $aj['KODE_BARANG'] ?? '') ?></td>
                                <td class="num"><strong><?= number_format((float)($aj['qty_use'] ?? $aj['QTY'] ?? 0), 4, ',', '.') ?></strong></td>
                                <td style="text-align:center;"><?= htmlspecialchars($aj['sat'] ?? $aj['UNIT'] ?? 'PCE') ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <table class="sign-table">
            <tr>
                <td>Dibuat Oleh:<div class="sign-box"></div>( .................................... )<br><span style="color:#666;">Bagian Produksi / PPC</span></td>
                <td>Diperiksa Oleh:<div class="sign-box"></div>( .................................... )<br><span style="color:#666;">Kepala Bagian Gudang</span></td>
                <td>Diverifikasi Oleh:<div class="sign-box"></div>( .................................... )<br><span style="color:#666;">Kepala Kepabeanan / Exim</span></td>
                <td>Diketahui Oleh:<div class="sign-box"></div>( .................................... )<br><span style="color:#666;">Pimpinan / Manajemen</span></td>
            </tr>
        </table>

        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
    </body>
    </html>
    <?php
    exit;
}

// Data Referensi Customer
$buyers = [];
$buyRes = $koneksi->query("SELECT kode, nama, IFNULL(alamat,'') AS alamat, IFNULL(npwp,'') AS npwp FROM bc_bs WHERE (cmp_id = '{$escCmp}' OR cmp_id = '') AND (tipe = 'B' OR tipe = 'B/S') ORDER BY nama ASC");
if ($buyRes) while ($r = $buyRes->fetch_assoc()) $buyers[] = $r;

$currencies = ['IDR', 'USD', 'EUR', 'JPY', 'SGD', 'CNY', 'GBP'];
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Invoice - <?= htmlspecialchars($activeCmpName) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_searchable_select.css?v=20261101">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-hover: #f0f7ff;
            --table-head-bg: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --row-selected: #bae7ff;
            --row-selected-text: #002766;
            color-scheme: light;
        }
        body.dark-mode, [data-theme='dark'], html[data-theme='dark'], body[data-theme='dark'], html.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --border-light: #424242;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #ff4d4f;
            --table-hover: #262638;
            --table-head-bg: #1d1d1d;
            --bg-input: #141414;
            --border-input: #434343;
            --row-selected: #112a45;
            --row-selected-text: #e6f7ff;
            color-scheme: dark !important;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-base); color: var(--text-main); font-size: 10px; line-height: 1.45; -webkit-font-smoothing: antialiased; }
        button, input, select, textarea { font-family: inherit; font-size: 9.5px; font-weight: 500; color: inherit; }
        
        /* Hilangkan spinner up/down pada input angka agar tidak sengaja terpencet */
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
        .ant-badge-company { background: rgba(24, 144, 255, 0.12); color: var(--primary); border: 1px solid rgba(24, 144, 255, 0.3); }
        .ant-badge-lunas { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.3); }
        .ant-badge-belum { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.3); }
        .ant-badge-lock { background: rgba(255, 77, 79, 0.12); color: var(--danger); border: 1px solid rgba(255, 77, 79, 0.3); }

        /* Tabs */
        .ant-tabs-nav { display: flex; gap: 2px; padding: 0 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .ant-tab-btn { border: none; border-bottom: 2px solid transparent; background: transparent; color: var(--text-muted); padding: 5px 12px; cursor: pointer; font-size: 10px; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }

        /* Toolbar Buttons */
        .ant-toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; padding: 6px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); }
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
        
        /* Resizable Splitbar (sesuai index.php?tab=transfer) */
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
        .splitbar:hover .split-handle, .splitbar.dragging .split-handle,
        .splitbar:hover .split-grip, .splitbar.dragging .split-grip {
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
        .fld-row input, .fld-row select, .fld-row textarea, .browse-filter input, .browse-filter select { width: 100%; border: 1px solid var(--border-input); background: var(--bg-input); padding: 1px 6px; height: 21px; border-radius: 2px; outline: none; font-size: 9.5px; }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus, .browse-filter input:focus, .browse-filter select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15); }
        .inline-box { display: flex; gap: 4px; align-items: center; }
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

        /* Excel-like Data Table & Resizable Columns (Sesuai editor.php /?tab=editor) */
        .grid-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        table.dw { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 9.5px; table-layout: auto; border: 1px solid var(--border-color); }
        table.dw thead { position: sticky; top: 0; z-index: 10; }
        table.dw th { position: relative; background: var(--table-head-bg); border: 1px solid var(--border-color); padding: 4px 10px 4px 6px; text-align: left; font-weight: 600; font-size: 9.5px; color: var(--text-muted); white-space: nowrap; user-select: none; }
        table.dw td { border: 1px solid var(--border-light); padding: 2px 6px; font-size: 9.5px; vertical-align: middle; }
        table.dw input { width: 100%; border: 1px solid transparent; background: transparent; padding: 1px 4px; height: 21px; border-radius: 2px; font-size: 9.5px; }
        table.dw input:hover { border-color: var(--border-input); }
        table.dw input:focus { border-color: var(--primary); background: var(--bg-input); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.12); outline: none; }
        table.dw input[type="number"] { text-align: right; }
        table.dw tbody tr:hover td { background: var(--table-hover); }
        table.dw tr.sel td { background: var(--row-selected) !important; color: var(--row-selected-text) !important; }

        /* SI No (No. Inv) Highlight */
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

        /* Modal Master Barang - Consistent Height & Comfortable Spacing */
        #modalMaterial .modal-card {
            height: 560px;
            max-height: 90vh;
        }
        #modalMaterial .grid-wrap {
            height: 380px;
            min-height: 380px;
        }
        #modalMaterial table.dw td {
            padding: 7px 10px;
            font-size: 11px;
        }

        /* Trace BOM Row Cursor & Hover */
        #traceBom tr.trace-bom-row {
            cursor: pointer;
        }
        #traceBom tr.trace-bom-row:hover td {
            background: var(--table-hover);
        }
        #traceBom tr.trace-bom-row.sel td {
            background: var(--row-selected) !important;
            color: var(--row-selected-text) !important;
        }

        /* Column Resizer Handle (Sesuai editor.php /?tab=editor) */
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
        .col-resizer:hover,
        .col-resizer.resizing {
            background-color: var(--primary) !important;
            box-shadow: 0 0 6px rgba(24, 144, 255, 0.6) !important;
        }
        body.grid-resizing {
            cursor: col-resize !important;
            user-select: none !important;
        }
        body.grid-resizing * {
            user-select: none !important;
        }

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

        /* Browse Data Filter (Single Compact Line) */
        .browse-filter { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; padding: 4px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); white-space: nowrap; overflow-x: auto; }
        .browse-filter label { color: var(--text-muted); font-size: 10px; flex-shrink: 0; }
        .pager { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 10px; background: var(--bg-card); }
        .detail-strip { display: none; overflow: auto; border-top: 1px solid var(--border-color); background: var(--bg-card); }
        .detail-strip.show { display: block; flex: 0 0 180px; }

        /* Trace Boxes */
        .trace-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; background: var(--table-head-bg); border-bottom: 1px solid var(--border-color); }
        .trace-cap { background: var(--table-head-bg); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 4px 10px; font-weight: 600; font-size: 10px; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; }
        .trace-box { flex: 1; min-height: 0; overflow: auto; }
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
        .ctx-item i {
            width: 14px;
            text-align: center;
            font-size: 11px;
        }
        .ctx-item span {
            flex: 1;
        }
        .ctx-item kbd {
            font-size: 9px;
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
        .dropdown-item i {
            width: 14px;
            text-align: center;
        }

        /* Deficit / Stok Kurang Highlight */
        tr.row-deficit td {
            background: rgba(255, 77, 79, 0.08) !important;
            border-bottom: 1px solid rgba(255, 77, 79, 0.3) !important;
        }
        tr.row-deficit td.col-qty input {
            color: #ff4d4f !important;
            font-weight: 700 !important;
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
                <span>Sales Invoice (Faktur Penjualan)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-belum" id="statusBadgeHeader">Belum Lunas</span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="resetForm()"><i class="fa-solid fa-plus"></i> Baru</button>
                <button type="button" class="ant-btn ant-btn-primary" data-lock="block" onclick="saveSi()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                <button type="button" class="ant-btn ant-btn-danger" data-lock="block" onclick="deleteCurrent()"><i class="fa-solid fa-trash"></i> Hapus</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="printSi()"><i class="fa-solid fa-print"></i> Cetak Faktur</button>
                <button type="button" class="ant-btn ant-btn-warning" id="btnRestoreOriginal" style="display:none;" onclick="restoreOriginalData()" title="Kembalikan semua field dan rincian ke data awal sebelum diedit"><i class="fa-solid fa-rotate-left"></i> Reset ke Data Awal</button>
                <div style="position:relative; display:inline-block;">
                    <button type="button" class="ant-btn" id="btnExcelMenu" onclick="toggleExcelMenu(event)" title="Menu Import & Export Excel"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Excel <i class="fa-solid fa-caret-down"></i></button>
                    <div id="excelMenuDropdown" class="ant-dropdown-menu" style="display:none;" onclick="event.stopPropagation()">
                        <div class="dropdown-item" onclick="openModalImportExcel(); hideExcelMenu();"><i class="fa-solid fa-file-import" style="color:#1890ff;"></i> Import dari Excel...</div>
                        <div class="dropdown-item" onclick="exportGridToExcel(); hideExcelMenu();"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Rincian ke Excel (.xlsx)</div>
                        <div class="dropdown-item" onclick="downloadSiImportTemplate(); hideExcelMenu();"><i class="fa-solid fa-download" style="color:#faad14;"></i> Download Template Excel Import</div>
                    </div>
                </div>
                <button type="button" class="ant-btn" data-lock="block" onclick="openLookupDo()"><i class="fa-solid fa-truck"></i> Tarik DO</button>
                <button type="button" class="ant-btn" data-lock="block" onclick="openLookupSo()"><i class="fa-solid fa-cart-shopping"></i> Tarik SO</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnTransferItInv" onclick="postToItInventory()"><i class="fa-solid fa-cube"></i> Posting IT Inv</button>
                <button type="button" class="ant-btn ant-btn-danger" id="btnDelItInv" style="display:none;" onclick="deleteFromItInventory()"><i class="fa-solid fa-unlock"></i> Buka Kunci IT Inv</button>
                <button type="button" class="ant-btn" id="btnAuditLog" onclick="showDocAuditLog(document.getElementById('siNo') ? document.getElementById('siNo').value : '', 'SI')" title="Lihat Catatan Riwayat Perubahan &amp; Penghapusan Data (Audit Trail)"><i class="fa-solid fa-clock-rotate-left" style="color:#1890ff;"></i> Log Perubahan</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Navigation Tabs (3 Tab Utama) -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="entri"><i class="fa-solid fa-pen-to-square"></i> Entri (Faktur Penjualan)</button>
            <button type="button" class="ant-tab-btn" data-tab="browse"><i class="fa-solid fa-table-list"></i> Browse Data</button>
            <button type="button" class="ant-tab-btn" data-tab="trace"><i class="fa-solid fa-route"></i> Trace Issue &amp; Ex BC</button>
        </div>

        <!-- Tab 1: Entri (Faktur Penjualan) -->
        <section class="ant-page active" id="pageEntri">
            <div class="split-col" id="splitEntri">
                <!-- Pane Header -->
                <div class="split-pane" id="paneHdr">
                    <div class="lock-alert" id="siLock">
                        <span><i class="fa-solid fa-lock"></i> <strong>DOKUMEN TERKUNCI:</strong> Sudah masuk pembukuan IT Inventory (bc_1ab2). Mode baca saja (Read-Only).</span>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteFromItInventory()"><i class="fa-solid fa-unlock"></i> Hapus dari IT Inv &amp; Buka Kunci</button>
                    </div>
                    <div class="hdr-grid">
                        <!-- Sisi Kiri: Customer & Pengiriman -->
                        <div>
                            <div class="fld-row">
                                <label>Buyer/Cust:</label>
                                <div class="inline-box">
                                    <select id="siVendor" onchange="onBuyerChange()" style="flex:1;">
                                        <option value="">-- Pilih Buyer / Customer --</option>
                                        <?php foreach ($buyers as $b): ?>
                                        <option value="<?= htmlspecialchars($b['kode']) ?>" data-nama="<?= htmlspecialchars($b['nama']) ?>" data-alamat="<?= htmlspecialchars($b['alamat']) ?>" data-npwp="<?= htmlspecialchars($b['npwp']) ?>"><?= htmlspecialchars($b['kode']) ?> - <?= htmlspecialchars($b['nama']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="ant-btn" onclick="openLookupDo()" title="Tarik dari Surat Jalan DO"><i class="fa-solid fa-truck"></i> DO</button>
                                    <button type="button" class="ant-btn" onclick="openLookupSo()" title="Tarik dari Sales Order"><i class="fa-solid fa-cart-shopping"></i> SO</button>
                                </div>
                            </div>
                            <div class="checks-box">
                                <label><input type="checkbox" id="siTaxable" checked onchange="calcTotals()"> Kena Pajak</label>
                                <label><input type="checkbox" id="siInclusive" onchange="calcTotals()"> Termasuk Pajak (Inclusive)</label>
                                <label><input type="checkbox" id="siSubcon" onchange="calcTotals()"> Jasa Subkon</label>
                                <label><input type="checkbox" id="siNcv"> NCV</label>
                                <label><input type="checkbox" id="siBill2"> Bill to Buyer</label>
                            </div>
                            <div class="fld-row">
                                <label>Ship To:</label>
                                <select id="siShipTo">
                                    <option value="">Sama dengan Buyer</option>
                                    <?php foreach ($buyers as $b): ?>
                                    <option value="<?= htmlspecialchars($b['kode']) ?>"><?= htmlspecialchars($b['kode']) ?> - <?= htmlspecialchars($b['nama']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fld-row">
                                <label>Alamat:</label>
                                <input type="text" id="siAlamat" readonly placeholder="Alamat Customer">
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>No. DO:</label><input type="text" id="siDoNo" placeholder="No Surat Jalan"></div>
                                <div class="fld-row"><label>No. SO:</label><input type="text" id="siSoNo" placeholder="No Sales Order"></div>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Kurs, No Faktur, Tanggal, Terms -->
                        <div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Kurs:</label>
                                    <select id="siKurs" onchange="toggleRate()">
                                        <?php foreach ($currencies as $c): ?>
                                        <option value="<?= $c ?>"><?= $c ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fld-row"><label>Kurs Pajak:</label><input type="number" id="siRate" value="1" step="0.0001" oninput="calcTotals()"></div>
                            </div>
                            <div class="pair-box" id="rowBiRate" style="display:none;">
                                <div class="fld-row"><label>Kurs BI:</label><input type="number" id="siBirate" value="1" step="0.0001"></div>
                                <div class="fld-row"><label>Tujuan:</label><input type="text" id="siTujuan" placeholder="Tujuan Pengiriman"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label style="font-weight:700; color:var(--primary);">No. Inv:</label>
                                    <div class="inline-box">
                                        <input type="text" id="siNo" class="highlight-inv-no" placeholder="Otomatis jika kosong">
                                        <button type="button" class="ant-btn" onclick="generateAutoSiNo()" title="Generate Nomor Urut"><i class="fa-solid fa-rotate"></i></button>
                                    </div>
                                </div>
                                <div class="fld-row"><label>Tgl Kirim:</label><input type="date" id="siShipDt" value="<?= $today ?>"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl Inv:</label><input type="date" id="siDt" value="<?= $today ?>" onchange="onDateOrTermChange()"></div>
                                <div class="fld-row"><label>Terms:</label>
                                    <input type="text" id="siTerm" value="Net 30" list="termList" onchange="onDateOrTermChange()">
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
                                <div class="fld-row"><label>Jatuh Tempo:</label><input type="date" id="siJatuhTempo"></div>
                                <div class="fld-row"><label>PO No:</label><input type="text" id="siPoNo" placeholder="No PO Customer"></div>
                            </div>
                            <input type="hidden" id="siId">
                        </div>
                    </div>
                </div>

                <!-- Splitbar Header/Rincian (sesuai transfer.php) -->
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
                                    <th class="col-color" data-col="color" style="width:80px;">Color</th>
                                    <th class="col-style" data-col="style" style="width:80px;">Style</th>
                                    <th class="col-qty" data-col="qty" style="width:75px; text-align:right;">Qty</th>
                                    <th class="col-sat" data-col="sat" style="width:55px;">Satuan</th>
                                    <th class="col-price" data-col="price" style="width:100px; text-align:right;">Harga Sat</th>
                                    <th class="col-disc" data-col="disc" style="width:65px; text-align:right;">Disc(%)</th>
                                    <th class="col-amount" data-col="amount" style="width:110px; text-align:right;">Total Nilai</th>
                                    <th class="col-price_jasa" data-col="price_jasa" style="width:90px; text-align:right;">Harga Jasa</th>
                                    <th class="col-amt_jasa" data-col="amt_jasa" style="width:95px; text-align:right;">Total Jasa</th>
                                    <th class="col-netto" data-col="netto" style="width:75px; text-align:right;">Netto(Kg)</th>
                                    <th class="col-gross" data-col="gross" style="width:75px; text-align:right;">Gross(Kg)</th>
                                    <th class="col-volume" data-col="volume" style="width:70px; text-align:right;">Volume</th>
                                    <th class="col-ctn" data-col="ctn" style="width:60px; text-align:right;">Ctn</th>
                                    <th class="col-plb" data-col="plb" style="width:60px; text-align:right;">Plb</th>
                                    <th class="col-gudang" data-col="gudang" style="width:75px;">Gudang</th>
                                    <th class="col-dono" data-col="dono" style="width:95px;">No. DO</th>
                                    <th class="col-sono" data-col="sono" style="width:95px;">No. SO</th>
                                    <th class="col-pono" data-col="pono" style="width:95px;">No. PO</th>
                                    <th class="col-postarif" data-col="postarif" style="width:90px;">Pos Tarif</th>
                                    <th class="col-cif" data-col="cif" style="width:95px; text-align:right;">CIF</th>
                                    <th class="col-cifrupiah" data-col="cifrupiah" style="width:105px; text-align:right;">CIF Rp</th>
                                    <th class="col-hpp" data-col="hpp" style="width:90px; text-align:right;">HPP</th>
                                    <th class="col-stok" data-col="stok" style="width:70px; text-align:right;">Stok</th>
                                    <th class="col-note" data-col="note" style="min-width:130px; width:130px;">Catatan (Note)</th>
                                    <th class="col-action th-action" data-col="action" style="width:30px; text-align:center;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsContainer"></tbody>
                        </table>
                    </div>
                    <div style="padding:4px 8px; background:var(--table-head-bg); border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <button type="button" class="ant-btn ant-btn-sm" data-lock="block" onclick="tambahBaris()"><i class="fa-solid fa-plus"></i> Tambah Baris Barang</button>
                            <button type="button" class="ant-btn ant-btn-sm" data-lock="block" onclick="openModalImportExcel()" title="Import rincian barang dari file Excel (.xlsx / .csv)"><i class="fa-solid fa-file-import" style="color:var(--primary);"></i> Import Excel</button>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="exportGridToExcel()" title="Export rincian barang ke Excel (.xlsx)"><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Export Excel</button>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="checkStockShortage()" title="Cek ketersediaan stok fisik gudang (IT Inventory)"><i class="fa-solid fa-boxes-stacked" style="color:var(--warning);"></i> Cek Stok</button>
                            <div style="position:relative; display:inline-block;">
                                <button type="button" class="ant-btn ant-btn-sm" id="btnColChooser" onclick="toggleColChooser(event)" title="Atur kolom yang ditampilkan"><i class="fa-solid fa-table-columns"></i> Atur Kolom <i class="fa-solid fa-caret-down"></i></button>
                                <div id="colChooserMenu" class="col-chooser-menu" style="display:none;" onclick="event.stopPropagation()">
                                    <div class="col-chooser-header">
                                        <span><strong><i class="fa-solid fa-sliders"></i> Tampilkan Kolom</strong></span>
                                        <div>
                                            <button type="button" class="ant-btn-link" onclick="setAllColumns(true)">Semua</button> |
                                            <button type="button" class="ant-btn-link" onclick="resetDefaultColumns()">Default</button>
                                        </div>
                                    </div>
                                    <div class="col-chooser-body" id="colChooserList"></div>
                                </div>
                            </div>
                        </div>
                        <span class="muted" id="itemCountInfo">0 baris rincian</span>
                    </div>
                </div>

                <!-- Splitbar Rincian/Footer (sesuai transfer.php) -->
                <div class="split-divider" id="splitFtr" data-pane="paneFtr" data-edge="next" title="Tarik untuk mengubah ukuran, dobel-klik untuk toggle maximize">
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleSiPane('grid')" title="Toggle Maximize Grid">&#x25BC; Grid</button>
                    <div class="split-handle"></div>
                    <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleSiPane('ftr')" title="Toggle Maximize Footer">&#x25B2; Footer</button>
                </div>

                <!-- Pane Footer / Total Order -->
                <div class="split-pane" id="paneFtr">
                    <div class="ftr-grid">
                        <!-- Sisi Kiri: Pabean BC & Pajak Coretax & Terbilang -->
                        <div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Dok Pabean:</label>
                                    <select id="siBc">
                                        <option value="">- Non BC / Lokal -</option>
                                        <option value="30">BC 3.0 / PEB</option>
                                        <option value="41">BC 4.1</option>
                                        <option value="27">BC 2.7 OUT</option>
                                        <option value="25">BC 2.5</option>
                                        <option value="261">BC 2.6.1</option>
                                        <option value="28">BC 2.8 PLB</option>
                                    </select>
                                </div>
                                <div class="fld-row"><label>No. BC:</label><input type="text" id="siNoBc" placeholder="Nomor Pendaftaran"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl BC:</label><input type="date" id="siTglBc"></div>
                                <div class="fld-row"><label>No. AJU:</label><input type="text" id="siNoAju" placeholder="######-######-########-######"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Faktur Pajak:</label>
                                    <div class="inline-box">
                                        <select id="siKdFp" style="width:55px;">
                                            <option value="01">01</option>
                                            <option value="02">02</option>
                                            <option value="03">03</option>
                                            <option value="04">04</option>
                                            <option value="07">07</option>
                                            <option value="08">08</option>
                                        </select>
                                        <input type="text" id="siNoFp" placeholder="No Seri FP">
                                    </div>
                                </div>
                                <div class="fld-row"><label>Note/Ket:</label><input type="text" id="siNote" placeholder="Catatan transaksi"></div>
                            </div>
                            <!-- Say / Terbilang Rapih di Sebelah KIRI Saja -->
                            <div class="terbilang-callout" id="boxTerbilang">
                                <span class="lbl-say">Say:</span>
                                <span class="txt-say" id="dispTerbilang">Nol Rupiah</span>
                            </div>
                        </div>

                        <!-- Sisi Kanan: Rekapitulasi Nilai & Pajak (PB ue_calc) -->
                        <div class="sum-box">
                            <div class="fld-row"><label>SubTotal:</label><span class="num" id="dispSubtotal">0,00</span></div>
                            <div class="fld-row"><label>Diskon:</label>
                                <div class="inline-box">
                                    <input type="number" id="siDiscPersen" value="0" step="0.01" style="width:48px;" oninput="onDiscPercentChange()"> %
                                    <input type="number" id="siDiscAmt" value="0" step="0.01" style="width:78px; text-align:right;" oninput="onDiscAmtChange()">
                                </div>
                            </div>
                            <div class="fld-row"><label>PPN (11%):</label><span class="num" id="dispPpn">0,00</span></div>
                            <div class="fld-row"><label>PPh 23 (2%):</label>
                                <label class="inline-box"><input type="checkbox" id="siPph" onchange="calcTotals()"> <span id="dispPph">0,00</span></label>
                            </div>
                            <div class="fld-row"><label>Jasa:</label><input type="number" id="siJasa" value="0" step="0.01" oninput="calcTotals()"></div>
                            <div class="fld-row"><label>Freight:</label><input type="number" id="siFreight" value="0" step="0.01" oninput="calcTotals()"></div>
                            <div class="fld-row grand"><label>TOTAL ORDER:</label><span class="num" id="dispGrandTotal">0,00</span></div>
                            <div class="fld-row"><label>Terbayar:</label><input type="number" id="siTerbayar" value="0" step="0.01" oninput="calcTotals()"></div>
                            <div class="fld-row out"><label>OUTSTANDING:</label><span class="num" id="dispOutstand">0,00</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Status Line -->
            <div class="statusline">
                <span id="siStatus">Siap. Pilih Buyer/Customer lalu masukkan rincian barang, atau buka data dari tab Browse Data.</span>
                <span id="siModeBadge" class="ant-badge ant-badge-company">Mode: Baru / Siap Entri</span>
            </div>
        </section>

        <!-- Tab 2: Browse Data (Parameter 1 Line Ringkas) -->
        <section class="ant-page" id="pageBrowse">
            <div class="browse-filter">
                <label>Periode:</label>
                <input type="date" id="filterTglAwal" value="<?= $monthStart ?>" style="width:110px;">
                <label>s/d</label>
                <input type="date" id="filterTglAkhir" value="<?= $today ?>" style="width:110px;">
                <label>Status:</label>
                <select id="filterStatus" style="width:95px;">
                    <option value="">- SEMUA -</option>
                    <option value="0">BELUM LUNAS</option>
                    <option value="1">LUNAS</option>
                </select>
                <label>Cari:</label>
                <select id="filterCol" style="width:105px;">
                    <option value="">Semua kolom</option>
                    <option value="inv_no">No Invoice</option>
                    <option value="supplier">Buyer/Cust</option>
                    <option value="do_no">No DO</option>
                    <option value="so_no">No SO</option>
                    <option value="po_no">No PO</option>
                    <option value="no_bc">No BC</option>
                    <option value="fp">Faktur Pajak</option>
                    <option value="no_aju">No AJU</option>
                </select>
                <input type="text" id="filterSearch" placeholder="Kata kunci..." style="width:140px;" onkeydown="if(event.key==='Enter') loadSiList(1)">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadSiList(1)"><i class="fa-solid fa-magnifying-glass"></i> Retrieve</button>
                <button type="button" class="ant-btn" onclick="openModalRekapBc()" title="Rekapitulasi Dokumen Pabean (BC)"><i class="fa-solid fa-chart-pie"></i> Rekap BC</button>
                <label class="inline-box" style="margin-left:auto; cursor:pointer; flex-shrink:0;">
                    <input type="checkbox" id="chkDetail" onchange="toggleBrowseDetail()"> <strong>PREVIEW RINCIAN</strong>
                </label>
            </div>
            <div class="split-col">
                <div class="split-pane fill">
                    <div class="grid-wrap">
                        <table class="dw" id="siTable">
                            <thead>
                                <tr>
                                    <th data-col="seq" style="width:34px; text-align:center;">#</th>
                                    <th data-col="si_no" class="th-highlight" style="width:140px;">No. Invoice</th>
                                    <th data-col="si_dt" style="width:90px;">Tgl Invoice</th>
                                    <th data-col="vendor" style="width:180px;">Buyer / Customer</th>
                                    <th data-col="shipto" style="width:140px;">Ship To</th>
                                    <th data-col="kurs" style="width:55px;">Kurs</th>
                                    <th data-col="total_order" style="width:110px; text-align:right;">Tagihan</th>
                                    <th data-col="terbayar" style="width:100px; text-align:right;">Terbayar</th>
                                    <th data-col="outstand" style="width:110px; text-align:right;">Sisa (Outstand)</th>
                                    <th data-col="status" style="width:90px;">Status</th>
                                    <th data-col="jatuhtempo" style="width:90px;">Jatuh Tempo</th>
                                    <th data-col="no_fp" style="width:130px;">Faktur Pajak</th>
                                    <th data-col="bc" style="width:85px;">Dokumen BC</th>
                                    <th data-col="no_aju" style="width:160px;">No. AJU</th>
                                    <th data-col="lock" style="width:50px; text-align:center;">IT Inv</th>
                                    <th data-col="action" class="th-action" style="width:75px; text-align:center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="siTableBody">
                                <tr><td colspan="16" class="muted" style="padding:16px; text-align:center;">Memuat data...</td></tr>
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

        <!-- Tab 3: Trace Issue & Ex BC (Master-Detail Klik Baris + Cetak Traceability) -->
        <section class="ant-page" id="pageTrace">
            <div class="trace-toolbar">
                <span class="muted"><i class="fa-solid fa-circle-info" style="color:var(--primary);"></i> Klik salah satu baris <strong>Barang Jadi</strong> untuk melihat rincian komponen BOM dan Dokumen Eks-BC terkait.</span>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="ant-btn ant-btn-success" onclick="syncTraceToCeisa()" title="Simpan hasil penelusuran bahan baku ke Dokumen Fasilitas / Eks-BC CEISA 4.0"><i class="fa-solid fa-arrows-rotate"></i> Sinkronkan ke Dokumen Eks-BC CEISA 4.0</button>
                    <button type="button" class="ant-btn ant-btn-primary" onclick="printTraceReport()"><i class="fa-solid fa-print"></i> Cetak Laporan Traceability</button>
                </div>
            </div>
            <div class="split-col">
                <div class="split-pane fill" id="paneTrace1">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-box"></i> Barang Sales Invoice yang Ditelusuri (Finish Goods / Output)</span>
                        <span id="traceSelectedFgBadge" class="ant-badge ant-badge-company">Pilih barang</span>
                    </div>
                    <div class="trace-box grid-wrap" id="traceLines"><div class="muted" style="padding:10px;">Buka invoice di tab Entri, lalu buka tab ini untuk melihat jejak bahan baku.</div></div>
                </div>
                <div class="split-divider" data-pane="paneTrace1" data-edge="prev" title="Geser pembatas komponen">
                    <div class="split-handle"></div>
                </div>
                <div class="split-pane fill" id="paneTrace2">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-diagram-project"></i> Komponen Bahan Baku | Bill of Material (Issue Produksi)</span>
                        <span id="traceBomCount" class="muted">0 komponen</span>
                    </div>
                    <div class="trace-box grid-wrap" id="traceBom"><div class="muted" style="padding:10px;">Belum ada komponen produksi terkait.</div></div>
                </div>
                <div class="split-divider" data-pane="paneTrace2" data-edge="prev" title="Geser pembatas dokumen asal">
                    <div class="split-handle"></div>
                </div>
                <div class="split-pane fill" id="paneTrace3">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-file-shield"></i> Dokumen Pemasukan Pabean Asal (Tracking Dokumen BC Asal)</span>
                        <span id="traceAjuCount" class="muted">0 dokumen asal</span>
                    </div>
                    <div class="trace-box grid-wrap" id="traceAju"><div class="muted" style="padding:10px;">Belum ada dokumen pemasukan pabean asal.</div></div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Modal: Rekap Dokumen Pabean (BC Summary - dipanggil dari Browse Data) -->
<div class="modal" id="modalRekapBc">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-chart-pie" style="color:var(--primary);"></i> Rekapitulasi Dokumen Pabean BC (Sales Invoice)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalRekapBc()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="browse-filter" style="margin-bottom:8px; border:1px solid var(--border-color); border-radius:3px; padding:4px 8px;">
                <label>Periode Faktur:</label>
                <input type="date" id="rekapTglAwal" value="<?= $monthStart ?>" style="width:115px;">
                <label>s/d</label>
                <input type="date" id="rekapTglAkhir" value="<?= $today ?>" style="width:115px;">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadRekapBc()"><i class="fa-solid fa-rotate"></i> Hitung Rekap</button>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px; margin-bottom:10px;">
                <div style="background:var(--table-head-bg); border:1px solid var(--border-color); border-radius:3px; padding:8px 10px;">
                    <div class="muted" style="font-size:9.5px;">Total Faktur Penjualan</div>
                    <div style="font-size:15px; font-weight:700; color:var(--primary);" id="statRekapFaktur">0</div>
                </div>
                <div style="background:var(--table-head-bg); border:1px solid var(--border-color); border-radius:3px; padding:8px 10px;">
                    <div class="muted" style="font-size:9.5px;">Grand Total Penjualan</div>
                    <div style="font-size:15px; font-weight:700; color:var(--text-main);" id="statRekapGrand">IDR 0,00</div>
                </div>
                <div style="background:var(--table-head-bg); border:1px solid var(--border-color); border-radius:3px; padding:8px 10px;">
                    <div class="muted" style="font-size:9.5px;">Total PPN (11%)</div>
                    <div style="font-size:15px; font-weight:700; color:var(--success);" id="statRekapPpn">IDR 0,00</div>
                </div>
                <div style="background:var(--table-head-bg); border:1px solid var(--border-color); border-radius:3px; padding:8px 10px;">
                    <div class="muted" style="font-size:9.5px;">Total PPh 23</div>
                    <div style="font-size:15px; font-weight:700; color:var(--warning);" id="statRekapPph">IDR 0,00</div>
                </div>
            </div>
            <div class="grid-wrap" style="border:1px solid var(--border-color); border-radius:3px; max-height:360px;">
                <table class="dw">
                    <thead>
                        <tr>
                            <th>Jenis Dokumen Pabean</th>
                            <th style="text-align:right;">Jumlah Faktur</th>
                            <th style="text-align:right;">Total Item</th>
                            <th style="text-align:right;">Total Qty</th>
                            <th style="text-align:right;">Total DPP / Subtotal</th>
                            <th style="text-align:right;">Total PPN</th>
                            <th style="text-align:right;">Total PPh 23</th>
                            <th style="text-align:right;">Grand Total Tagihan</th>
                            <th style="text-align:right;">Terbayar</th>
                            <th style="text-align:right;">Sisa Piutang</th>
                        </tr>
                    </thead>
                    <tbody id="rekapBcBody">
                        <tr><td colspan="10" class="muted" style="padding:12px; text-align:center;">Klik tombol Hitung Rekap untuk memuat data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Sumber data: Faktur Penjualan Terdaftar &bull; BC 3.0, BC 4.1, BC 2.7 OUT, BC 2.5, Lokal</span>
            <button type="button" class="ant-btn" onclick="closeModalRekapBc()">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal: Pilih Material / Barang -->
<div class="modal" id="modalMaterial">
    <div class="modal-card wide">
        <div class="modal-h">
            <span id="materialTitle"><i class="fa-solid fa-boxes-stacked"></i> Pilih Master Barang : Finished Goods</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeMaterialPicker()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="klas-row">
                <button type="button" class="ant-btn klas-btn active" data-klas="FG" onclick="setKlas('FG')">Finished Goods (FG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="RM" onclick="setKlas('RM')">Raw Materials (RM)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="WI" onclick="setKlas('WI')">Work In Progress (WIP)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="CG" onclick="setKlas('CG')">Capital Goods (CG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="W" onclick="setKlas('W')">Wastes / Scrap</button>
            </div>
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="materialSearch" style="flex:1;" placeholder="Ketik kode barang atau uraian..." oninput="queueMaterialSearch()" onkeydown="if(event.key==='Enter'){event.preventDefault(); searchMaterials();}">
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

<!-- Modal: Pilih Surat Jalan (DO) -->
<div class="modal" id="modalLookupDo">
    <div class="modal-card">
        <div class="modal-h">
            <span><i class="fa-solid fa-truck"></i> Tarik Surat Jalan Delivery Order (DO)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeLookupDo()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="searchDoText" style="flex:1;" placeholder="Cari No DO, Buyer, No SO, atau No PO..." onkeydown="if(event.key==='Enter') searchDo()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchDo()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th>No. DO</th>
                            <th>Tgl Kirim</th>
                            <th>Buyer / Customer</th>
                            <th>No. SO</th>
                            <th>No. PO</th>
                            <th style="width:60px; text-align:center;"></th>
                        </tr>
                    </thead>
                    <tbody id="lookupDoBody">
                        <tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Ketik pencarian atau klik Cari</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Pilih Sales Order (SO) -->
<div class="modal" id="modalLookupSo">
    <div class="modal-card">
        <div class="modal-h">
            <span><i class="fa-solid fa-cart-shopping"></i> Tarik Sales Order (SO)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeLookupSo()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="searchSoText" style="flex:1;" placeholder="Cari No SO, Buyer, atau No PO..." onkeydown="if(event.key==='Enter') searchSo()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchSo()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th>No. SO</th>
                            <th>Tgl SO</th>
                            <th>Buyer / Customer</th>
                            <th>No. PO</th>
                            <th>Terms</th>
                            <th style="width:60px; text-align:center;"></th>
                        </tr>
                    </thead>
                    <tbody id="lookupSoBody">
                        <tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Ketik pencarian atau klik Cari</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Context Menu Grid (Klik Kanan PB Style - w_si.srw) -->
<div id="gridContextMenu" class="context-menu" style="display:none;" onclick="event.stopPropagation()">
    <div class="ctx-item" onclick="ctxTambahBaru()"><i class="fa-solid fa-plus" style="color:#1890ff;"></i> <span>Baru</span> <kbd>Ctrl+I</kbd></div>
    <div class="ctx-item" onclick="ctxHapusBaris()"><i class="fa-solid fa-xmark" style="color:#ff4d4f;"></i> <span>Hapus</span> <kbd>Ctrl+Del</kbd></div>
    <div class="ctx-item" onclick="ctxPilihBarang()"><i class="fa-solid fa-boxes-stacked" style="color:#52c41a;"></i> <span>Barang Baru / Master</span> <kbd>Ctrl+E</kbd></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxSisipkanAtas()"><i class="fa-solid fa-arrow-up"></i> <span>Sisipkan Di Atas</span></div>
    <div class="ctx-item" onclick="ctxSisipkanBawah()"><i class="fa-solid fa-arrow-down"></i> <span>Sisipkan Di Bawah</span></div>
    <div class="ctx-item" onclick="ctxDuplikasiBaris()"><i class="fa-solid fa-copy"></i> <span>Duplikasi Baris</span></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxHistoryHarga()"><i class="fa-solid fa-chart-line" style="color:#faad14;"></i> <span>History Harga Penjualan</span></div>
    <div class="ctx-item" onclick="ctxCekStok()"><i class="fa-solid fa-boxes-stacked" style="color:#fa8c16;"></i> <span>Cek Stok Kurang</span></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="openModalImportExcel(); hideContextMenu();"><i class="fa-solid fa-file-import" style="color:#1890ff;"></i> <span>Import dari Excel...</span></div>
    <div class="ctx-item" onclick="exportGridToExcel(); hideContextMenu();"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> <span>Export Rincian ke Excel</span></div>
    <div class="ctx-item" onclick="downloadSiImportTemplate(); hideContextMenu();"><i class="fa-solid fa-download" style="color:#722ed1;"></i> <span>Download Template Import</span></div>
</div>

<!-- Modal: Import Data Sales Invoice dari Excel / CSV -->
<div class="modal" id="modalImportExcel">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-file-import" style="color:var(--primary);"></i> Import Data Rincian Sales Invoice dari Excel / CSV</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalImportExcel()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--table-head-bg); padding:8px 12px; border:1px solid var(--border-color); border-radius:3px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <label style="font-weight:600;"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Pilih File Excel / CSV:</label>
                    <input type="file" id="fileImportExcel" accept=".xlsx, .xls, .csv" onchange="handleExcelFileSelect(event)">
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="downloadSiImportTemplate()"><i class="fa-solid fa-download"></i> Download Template Excel</button>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; font-size:10px;">
                <div style="display:flex; gap:16px; align-items:center;">
                    <span style="font-weight:600;">Mode Penambahan Data:</span>
                    <label style="cursor:pointer;"><input type="radio" name="importMode" value="append" checked> Tambahkan ke baris yang ada (Append)</label>
                    <label style="cursor:pointer;"><input type="radio" name="importMode" value="replace"> Ganti seluruh baris yang ada (Replace)</label>
                </div>
                <span class="muted" id="importStats">Belum ada file dipilih</span>
            </div>

            <!-- Preview Data Table -->
            <div class="grid-wrap" style="max-height:300px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" id="tblImportPreview" style="min-width:1050px;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th>Kode Barang</th>
                            <th>Nama &amp; Uraian</th>
                            <th>Color</th>
                            <th>Style</th>
                            <th style="text-align:right;">Qty</th>
                            <th>Satuan</th>
                            <th style="text-align:right;">Harga Sat</th>
                            <th style="text-align:right;">Disc(%)</th>
                            <th style="text-align:right;">Total Nilai</th>
                            <th>No. DO</th>
                            <th>No. SO</th>
                            <th>No. PO</th>
                            <th>Gudang</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyImportPreview">
                        <tr><td colspan="14" class="muted" style="padding:24px; text-align:center;">Pilih file Excel (.xlsx / .csv) di atas untuk melihat preview baris barang.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Mendukung kolom: Kode Barang, Uraian, Qty, Satuan, Harga, Disc, No DO, No SO, No PO, Color, Style.</span>
            <div style="display:flex; gap:6px;">
                <button type="button" class="ant-btn" onclick="closeModalImportExcel()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnApplyImport" disabled onclick="applyImportedData()"><i class="fa-solid fa-check"></i> Terapkan ke Sales Invoice</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: History Harga Penjualan Barang (PB w_si.srw) -->
<div class="modal" id="modalHistoryHarga">
    <div class="modal-card wide">
        <div class="modal-h">
            <span id="historyHargaTitle"><i class="fa-solid fa-chart-line" style="color:var(--primary);"></i> History Harga Penjualan Barang</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalHistoryHarga()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--table-head-bg); padding:6px 10px; border:1px solid var(--border-color); border-radius:3px;">
                <div style="display:flex; gap:8px; align-items:center;">
                    <span>Barang: <strong id="lblHistBarang">-</strong></span>
                    <span class="muted" id="lblHistSatuan"></span>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <label class="muted" style="cursor:pointer; display:flex; align-items:center; gap:4px;">
                        <input type="checkbox" id="chkHistOnlyBuyer" onchange="loadHistoryHargaData()"> Hanya Buyer Ini (<span id="lblHistBuyerNama">Customer Aktif</span>)
                    </label>
                </div>
            </div>

            <div class="grid-wrap" style="max-height:320px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" id="tblHistoryHarga" style="min-width:980px;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th style="width:45px; text-align:center;">Tipe</th>
                            <th>No. Bukti / Faktur</th>
                            <th>Tanggal</th>
                            <th>Buyer / Customer</th>
                            <th>Color / Style</th>
                            <th style="text-align:right;">Qty</th>
                            <th>Sat</th>
                            <th>Kurs</th>
                            <th style="text-align:right;">Harga Satuan</th>
                            <th style="text-align:right;">Disc(%)</th>
                            <th style="text-align:right;">Total Nilai</th>
                            <th style="width:90px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyHistoryHarga">
                        <tr><td colspan="13" class="muted" style="padding:16px; text-align:center;">Memuat riwayat harga...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Sumber data: Rekam jejak transaksi Sales Invoice &amp; Sales Order yang telah diterbitkan sebelumnya.</span>
            <button type="button" class="ant-btn" onclick="closeModalHistoryHarga()">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal: Cek Stok Kurang (IT Inventory PB w_si.srw) -->
<div class="modal" id="modalCekStok">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Cek Ketersediaan Stok Gudang (IT Inventory)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModalCekStok()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="display:flex; flex-direction:column; gap:8px;">
            <div id="boxStokSummaryAlert" style="padding:8px 12px; border-radius:3px; display:flex; justify-content:space-between; align-items:center; background:var(--table-head-bg); border:1px solid var(--border-color);">
                <span id="txtStokSummary"><i class="fa-solid fa-spinner fa-spin"></i> Memeriksa saldo stok gudang...</span>
                <span id="badgeStokDefisit" class="ant-badge ant-badge-company">-</span>
            </div>

            <div class="grid-wrap" style="max-height:340px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" id="tblCekStok">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th>Kode Barang</th>
                            <th>Nama &amp; Uraian Barang</th>
                            <th>Satuan</th>
                            <th style="text-align:right;">Qty Faktur</th>
                            <th style="text-align:right;">Saldo Stok Gudang</th>
                            <th style="text-align:right;">Selisih (Defisit)</th>
                            <th style="width:110px; text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyCekStok">
                        <tr><td colspan="8" class="muted" style="padding:16px; text-align:center;">Memeriksa ketersediaan stok...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Perhitungan stok berbasis riwayat mutasi IT Inventory pabean (bc_1ab2: Masuk AB='A' dikurangi Keluar AB='B').</span>
            <button type="button" class="ant-btn" onclick="closeModalCekStok()">Tutup</button>
        </div>
    </div>
</div>

<script src="/assets/sweetalert2.all.min.js"></script>
<script src="/assets/sweetalert-theme.js?v=20261025"></script>
<script>
let currentPage = 1;
let siLocked = false;
const LOCK_MSG = 'Sudah Masuk IT Inventory (bc_1ab2). TIDAK BISA EDIT !';
let selectedBrowseId = '';
let doCache = [];
let soCache = [];
let materialCache = [];
let pickerRow = null;
let pickerSelected = -1;
let materialTimer = null;
let lastCalc = { subtotal: 0, discPersen: 0, discAmt: 0, dpp: 0, ppn: 0, pph: 0, grand: 0, outstand: 0 };
let originalLoadedData = null;

const klasLabel = {
    FG: 'Finished Goods',
    RM: 'Raw Materials',
    WI: 'Work In Progress',
    CG: 'Capital Goods',
    W: 'Wastes / Scrap'
};

// Pengaturan Kolom Grid Sesuai PowerBuilder Desktop (d_si_dtl.srd)
const SI_COLUMNS = [
    { id: 'color', label: 'Color', default: false },
    { id: 'style', label: 'Style', default: false },
    { id: 'disc', label: 'Disc (%)', default: true },
    { id: 'price_jasa', label: 'Harga Jasa', default: false },
    { id: 'amt_jasa', label: 'Total Jasa', default: false },
    { id: 'netto', label: 'Netto (Kg)', default: true },
    { id: 'gross', label: 'Gross (Kg)', default: true },
    { id: 'volume', label: 'Volume', default: false },
    { id: 'ctn', label: 'Ctn', default: false },
    { id: 'plb', label: 'Plb', default: false },
    { id: 'gudang', label: 'Gudang', default: false },
    { id: 'dono', label: 'No. DO', default: true },
    { id: 'sono', label: 'No. SO', default: true },
    { id: 'pono', label: 'No. PO', default: false },
    { id: 'postarif', label: 'Pos Tarif', default: false },
    { id: 'cif', label: 'CIF', default: false },
    { id: 'cifrupiah', label: 'CIF Rp', default: false },
    { id: 'hpp', label: 'HPP', default: false },
    { id: 'stok', label: 'Stok', default: false },
    { id: 'note', label: 'Catatan (Note)', default: false }
];
let visibleCols = {};

function initColumnChooser() {
    let saved = null;
    try {
        saved = JSON.parse(localStorage.getItem('si_visible_columns'));
    } catch(e) {}
    visibleCols = {};
    SI_COLUMNS.forEach(c => {
        visibleCols[c.id] = (saved && typeof saved[c.id] !== 'undefined') ? saved[c.id] : c.default;
    });
    renderColumnChooser();
    applyColumnStyles();
}

function renderColumnChooser() {
    const list = document.getElementById('colChooserList');
    if (!list) return;
    list.innerHTML = SI_COLUMNS.map(c => `
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
    SI_COLUMNS.forEach(c => { visibleCols[c.id] = !!val; });
    saveColumnPrefs();
    renderColumnChooser();
    applyColumnStyles();
}

function resetDefaultColumns() {
    SI_COLUMNS.forEach(c => { visibleCols[c.id] = c.default; });
    saveColumnPrefs();
    renderColumnChooser();
    applyColumnStyles();
}

function saveColumnPrefs() {
    try {
        localStorage.setItem('si_visible_columns', JSON.stringify(visibleCols));
    } catch(e) {}
}

function applyColumnStyles() {
    let styleTag = document.getElementById('siColStyles');
    if (!styleTag) {
        styleTag = document.createElement('style');
        styleTag.id = 'siColStyles';
        document.head.appendChild(styleTag);
    }
    const cssRules = [];
    SI_COLUMNS.forEach(c => {
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

document.querySelectorAll('.ant-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => showTab(btn.dataset.tab));
});

// ==========================================
// COLUMN RESIZING ENGINE (Sesuai editor.php /?tab=editor)
// ==========================================
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

        // Terapkan saved width dari localStorage jika ada
        if (savedWidths && savedWidths[colKey]) {
            const sw = savedWidths[colKey];
            th.style.width = sw;
            th.style.minWidth = sw;
            th.style.maxWidth = sw;
        }

        // Pasang resizer handle jika belum ada
        let resizer = th.querySelector('.col-resizer');
        if (!resizer) {
            resizer = document.createElement('div');
            resizer.className = 'col-resizer';
            resizer.title = 'Tarik untuk ubah lebar kolom (Klik 2x untuk reset)';
            th.appendChild(resizer);

            // Double click reset lebar kolom
            resizer.addEventListener('dblclick', function(e) {
                e.stopPropagation();
                e.preventDefault();
                th.style.width = '';
                th.style.minWidth = '';
                th.style.maxWidth = '';
                removeSavedColumnWidth(tableKey, colKey);
            });

            // Resizer mousedown logic
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

function applyTheme(theme) {
    if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        document.body.classList.add('dark-mode');
    } else {
        document.documentElement.classList.remove('dark-mode');
        document.body.classList.remove('dark-mode');
    }
}

// Dengarkan pergantian tema dari induk (index.php) via postMessage
window.addEventListener('message', (ev) => {
    if (ev.data && (ev.data.type === 'SET_THEME' || ev.data.action === 'SET_THEME')) {
        applyTheme(ev.data.theme);
    }
});

// Dengarkan pergantian tema via localStorage storage event
window.addEventListener('storage', (ev) => {
    if (ev.key === 'ceisa_theme') {
        applyTheme(ev.newValue);
    }
});

document.addEventListener('DOMContentLoaded', () => {
    try {
        const initialTheme = localStorage.getItem('ceisa_theme');
        if (initialTheme) applyTheme(initialTheme);
    } catch (e) {}
    initSplitbars();
    initColumnChooser();
    initGridContextMenu();
    setupGridColumnResize('#tableItems', 'si_items');
    setupGridColumnResize('#siTable', 'si_browse');
    if (typeof initAntSearchableSelect === 'function') {
        initAntSearchableSelect('siVendor', { placeholder: 'Ketik nama / kode buyer...' });
        initAntSearchableSelect('siShipTo', { placeholder: 'Pilih / ketik nama penerima...' });
    }
    resetForm();
    loadSiList(1);
});

function showTab(name) {
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.getElementById('pageEntri').classList.toggle('active', name === 'entri');
    document.getElementById('pageBrowse').classList.toggle('active', name === 'browse');
    document.getElementById('pageTrace').classList.toggle('active', name === 'trace');
    if (name === 'browse') loadSiList(currentPage);
    if (name === 'trace') loadTrace();
}

function openModalRekapBc() {
    const m = document.getElementById('modalRekapBc');
    if (m) {
        m.classList.add('active');
        loadRekapBc();
    }
}

function closeModalRekapBc() {
    const m = document.getElementById('modalRekapBc');
    if (m) m.classList.remove('active');
}

function setStatus(msg, isErr = false) {
    const el = document.getElementById('siStatus');
    el.textContent = msg;
    el.style.color = isErr ? 'var(--danger)' : 'var(--text-muted)';
}

function guardEdit() {
    if (!siLocked) return false;
    showWarning(LOCK_MSG, 'Terkunci IT Inventory');
    return true;
}

function applyLock(locked) {
    siLocked = !!locked;
    const page = document.getElementById('pageEntri');
    page.classList.toggle('is-locked', siLocked);
    document.getElementById('siLock').style.display = siLocked ? 'flex' : 'none';
    document.getElementById('btnDelItInv').style.display = siLocked ? 'inline-flex' : 'none';
    document.getElementById('btnTransferItInv').style.display = siLocked ? 'none' : 'inline-flex';
    
    const badge = document.getElementById('statusBadgeHeader');
    if (siLocked) {
        badge.className = 'ant-badge ant-badge-lock';
        badge.innerHTML = '<i class="fa-solid fa-lock"></i> Terkunci IT Inv';
        document.getElementById('siModeBadge').className = 'ant-badge ant-badge-lock';
        document.getElementById('siModeBadge').textContent = 'Status: Terkunci IT Inventory (Read-Only)';
    } else {
        updateStatusBadge();
    }

    page.querySelectorAll('input, select, textarea, button').forEach(el => {
        if (el.id === 'siId' || el.classList.contains('split-btn') || el.id === 'btnDelItInv') return;
        if (el.dataset.lock === 'block') {
            el.disabled = siLocked;
        } else if (siLocked && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA')) {
            el.disabled = true;
        } else {
            el.disabled = false;
        }
    });
}

function updateStatusBadge() {
    if (siLocked) return;
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

// Splitbar resizing
function initSplitbars() {
    document.querySelectorAll('.splitbar, .split-divider').forEach(bar => {
        const pane = document.getElementById(bar.dataset.pane || '');
        if (!pane) return;
        const key = 'si-split-v3-' + (bar.id || bar.dataset.pane);
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
            if (bar.id === 'splitHdr') toggleSiPane('hdr');
            else if (bar.id === 'splitFtr') toggleSiPane('ftr');
        });
    });
}

let siMaxPane = null;
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
            const saved = sessionStorage.getItem('si-split-v3-' + barId);
            pane.style.flex = (saved && parseInt(saved, 10) >= 60) ? ('0 0 ' + parseInt(saved, 10) + 'px') : '';
            bar.style.display = '';
        });
        document.getElementById('paneGrid').style.flex = '';
    }
    document.querySelectorAll('.split-btn[data-max]').forEach(btn => {
        const on = !restore && btn.dataset.max === which;
        btn.classList.toggle('on', on);
        btn.innerHTML = on ? '<i class="fa-solid fa-compress"></i> Restore' : btn.dataset.label;
    });
}
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && siMaxPane) toggleSiPane(siMaxPane);
});

// Otomatisasi Jatuh Tempo dari Tgl Invoice + Terms
function onDateOrTermChange() {
    const tgl = document.getElementById('siDt').value;
    const term = document.getElementById('siTerm').value.trim();
    if (!tgl) return;
    const d = new Date(tgl);
    if (isNaN(d.getTime())) return;

    let days = 30; // default Net 30
    if (/cash|cod/i.test(term)) days = 0;
    else {
        const match = term.match(/\d+/);
        if (match) days = parseInt(match[0], 10);
    }
    d.setDate(d.getDate() + days);
    document.getElementById('siJatuhTempo').value = d.toISOString().slice(0, 10);
}

function onBuyerChange() {
    const sel = document.getElementById('siVendor');
    const opt = sel.selectedOptions[0];
    if (opt && opt.value) {
        document.getElementById('siAlamat').value = opt.dataset.alamat || '';
        if (!document.getElementById('siShipTo').value) {
            document.getElementById('siShipTo').value = opt.value;
        }
    } else {
        document.getElementById('siAlamat').value = '';
    }
}

function toggleRate() {
    const kurs = document.getElementById('siKurs').value;
    const isIdr = (kurs === 'IDR');
    document.getElementById('rowBiRate').style.display = isIdr ? 'none' : 'grid';
    if (isIdr) {
        document.getElementById('siRate').value = '1';
        document.getElementById('siBirate').value = '1';
    }
    calcTotals();
}

function generateAutoSiNo() {
    const today = new Date();
    const ym = today.getFullYear() + String(today.getMonth() + 1).padStart(2, '0');
    const rand = String(Math.floor(Math.random() * 900) + 100);
    document.getElementById('siNo').value = `SI-${ym}-${rand}`;
    setStatus('Nomor faktur di-generate otomatis: ' + document.getElementById('siNo').value);
}

function resetForm() {
    document.getElementById('siId').value = '';
    document.getElementById('siNo').value = '';
    document.getElementById('siDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('siShipDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('siDoNo').value = '';
    document.getElementById('siSoNo').value = '';
    document.getElementById('siPoNo').value = '';
    document.getElementById('siVendor').value = '';
    document.getElementById('siVendor').dispatchEvent(new Event('change'));
    document.getElementById('siShipTo').value = '';
    document.getElementById('siShipTo').dispatchEvent(new Event('change'));
    document.getElementById('siAlamat').value = '';
    document.getElementById('siTerm').value = 'Net 30';
    onDateOrTermChange();
    document.getElementById('siKurs').value = 'IDR';
    document.getElementById('siRate').value = '1';
    document.getElementById('siBirate').value = '1';
    document.getElementById('siTaxable').checked = true;
    document.getElementById('siInclusive').checked = false;
    document.getElementById('siSubcon').checked = false;
    document.getElementById('siNcv').checked = false;
    document.getElementById('siBill2').checked = false;
    document.getElementById('siKdFp').value = '01';
    document.getElementById('siNoFp').value = '';
    document.getElementById('siBc').value = '';
    document.getElementById('siNoBc').value = '';
    document.getElementById('siTglBc').value = '';
    document.getElementById('siNoAju').value = '';
    document.getElementById('siTujuan').value = '';
    document.getElementById('siNote').value = '';
    document.getElementById('siDiscPersen').value = '0';
    document.getElementById('siDiscAmt').value = '0';
    document.getElementById('siJasa').value = '0';
    document.getElementById('siFreight').value = '0';
    document.getElementById('siPph').checked = false;
    document.getElementById('siTerbayar').value = '0';
    document.getElementById('itemsContainer').innerHTML = '';
    addItemRow();
    applyLock(false);
    toggleRate();
    originalLoadedData = null;
    const btnRestore = document.getElementById('btnRestoreOriginal');
    if (btnRestore) btnRestore.style.display = 'none';
    document.getElementById('siModeBadge').className = 'ant-badge ant-badge-company';
    document.getElementById('siModeBadge').textContent = 'Mode: Baru / Siap Entri';
    setStatus('Transaksi baru. Pilih Buyer/Customer, isi rincian barang, lalu Simpan Transaksi.');
}

// Grid Rincian Barang
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
        <td class="col-color"><input type="text" class="it-color" value="${escapeHtml(item.color_cd || '')}" placeholder="Color"></td>
        <td class="col-style"><input type="text" class="it-style" value="${escapeHtml(item.size_cd || '')}" placeholder="Style/Size"></td>
        <td class="col-qty"><input type="number" class="it-qty" value="${item.qty ?? 1}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-sat"><input type="text" class="it-sat" value="${escapeHtml(item.sat || 'PCE')}"></td>
        <td class="col-price"><input type="number" class="it-price" value="${item.price ?? 0}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-disc"><input type="number" class="it-disc" value="${item.disc ?? 0}" step="0.01" oninput="calcItemRow(this)"></td>
        <td class="col-amount"><input type="number" class="it-amount" value="${item.amount ?? 0}" step="0.01" readonly></td>
        <td class="col-price_jasa"><input type="number" class="it-price_jasa" value="${item.price_jasa ?? 0}" step="0.0001" oninput="calcItemRow(this)"></td>
        <td class="col-amt_jasa"><input type="number" class="it-amt_jasa" value="${item.amt_jasa ?? 0}" step="0.01" readonly></td>
        <td class="col-netto"><input type="number" class="it-netto" value="${item.netto ?? 0}" step="0.0001"></td>
        <td class="col-gross"><input type="number" class="it-gross" value="${item.gross ?? 0}" step="0.0001"></td>
        <td class="col-volume"><input type="number" class="it-volume" value="${item.volume ?? 0}" step="0.0001"></td>
        <td class="col-ctn"><input type="number" class="it-ctn" value="${item.ctn ?? 0}" step="1"></td>
        <td class="col-plb"><input type="number" class="it-plb" value="${item.plb ?? 0}" step="1"></td>
        <td class="col-gudang"><input type="text" class="it-gudang" value="${escapeHtml(item.gudang || '')}" placeholder="Gudang"></td>
        <td class="col-dono"><input type="text" class="it-dono" value="${escapeHtml(item.do_no || '')}" placeholder="No DO"></td>
        <td class="col-sono"><input type="text" class="it-sono" value="${escapeHtml(item.so_no || '')}" placeholder="No SO"></td>
        <td class="col-pono"><input type="text" class="it-pono" value="${escapeHtml(item.po_no || '')}" placeholder="No PO"></td>
        <td class="col-postarif"><input type="text" class="it-postarif" value="${escapeHtml(item.pos_tarif || '')}" placeholder="HS Code"></td>
        <td class="col-cif"><input type="number" class="it-cif" value="${item.cif ?? 0}" step="0.01" oninput="calcItemRow(this)"></td>
        <td class="col-cifrupiah"><input type="number" class="it-cifrupiah" value="${item.cif_rupiah ?? 0}" step="0.01" readonly></td>
        <td class="col-hpp"><input type="number" class="it-hpp" value="${item.hpp ?? 0}" step="0.01"></td>
        <td class="col-stok"><input type="number" class="it-stok" value="${item.stok ?? 0}" step="0.0001" readonly></td>
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

    const rate = parseFloat(document.getElementById('siRate')?.value) || 1;
    const cif = parseFloat(row.querySelector('.it-cif')?.value) || 0;
    if (row.querySelector('.it-cifrupiah')) row.querySelector('.it-cifrupiah').value = (cif * rate).toFixed(2);

    calcTotals();
}

// Sinkronisasi Diskon % dan Nilai dua arah
function onDiscPercentChange() {
    const subtotal = lastCalc.subtotal;
    const pct = parseFloat(document.getElementById('siDiscPersen').value) || 0;
    const amt = (subtotal * pct) / 100;
    document.getElementById('siDiscAmt').value = amt.toFixed(2);
    calcTotals();
}

function onDiscAmtChange() {
    const subtotal = lastCalc.subtotal;
    const amt = parseFloat(document.getElementById('siDiscAmt').value) || 0;
    const pct = subtotal > 0 ? (amt / subtotal * 100) : 0;
    document.getElementById('siDiscPersen').value = pct.toFixed(2);
    calcTotals();
}

// Kalkulasi Total Order Sesuai PB ue_calc
function calcTotals() {
    let subtotal = 0;
    document.querySelectorAll('#itemsContainer .it-amount').forEach(inp => {
        subtotal += parseFloat(inp.value) || 0;
    });

    const discAmt = parseFloat(document.getElementById('siDiscAmt').value) || 0;
    const discPersen = parseFloat(document.getElementById('siDiscPersen').value) || 0;
    const net = Math.max(0, subtotal - discAmt);

    const taxable = document.getElementById('siTaxable').checked;
    const inclusive = document.getElementById('siInclusive').checked;
    const isSubcon = document.getElementById('siSubcon').checked;

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
    if (document.getElementById('siPph').checked || isSubcon) {
        pph = dpp * 0.02; // PPh Pasal 23 (2%)
        document.getElementById('siPph').checked = true;
    }

    const jasa = parseFloat(document.getElementById('siJasa').value) || 0;
    const freight = parseFloat(document.getElementById('siFreight').value) || 0;
    const grand = (inclusive ? net : net + ppn) + jasa + freight - pph;
    const terbayar = parseFloat(document.getElementById('siTerbayar').value) || 0;
    const outstand = grand - terbayar;

    lastCalc = { subtotal, discPersen, discAmt, dpp, ppn, pph, grand, outstand };

    document.getElementById('dispSubtotal').textContent = formatNum(subtotal);
    document.getElementById('dispPpn').textContent = formatNum(ppn);
    document.getElementById('dispPph').textContent = formatNum(pph);
    document.getElementById('dispGrandTotal').textContent = formatNum(grand);
    document.getElementById('dispOutstand').textContent = formatNum(outstand);
    const dispSay = document.getElementById('dispTerbilang');
    if (dispSay) dispSay.textContent = terbilangJs(grand);

    updateStatusBadge();
}

function collectItems() {
    const items = [];
    document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
        const kode = row.querySelector('.it-kode')?.value.trim();
        if (!kode) return;
        items.push({
            kode_barang: kode,
            uraian: row.querySelector('.it-uraian')?.value.trim() || '',
            color_cd: row.querySelector('.it-color')?.value.trim() || '',
            size_cd: row.querySelector('.it-style')?.value.trim() || '',
            qty: parseFloat(row.querySelector('.it-qty')?.value) || 0,
            sat: row.querySelector('.it-sat')?.value.trim() || 'PCE',
            price: parseFloat(row.querySelector('.it-price')?.value) || 0,
            disc: parseFloat(row.querySelector('.it-disc')?.value) || 0,
            amount: parseFloat(row.querySelector('.it-amount')?.value) || 0,
            price_jasa: parseFloat(row.querySelector('.it-price_jasa')?.value) || 0,
            amt_jasa: parseFloat(row.querySelector('.it-amt_jasa')?.value) || 0,
            netto: parseFloat(row.querySelector('.it-netto')?.value) || 0,
            gross: parseFloat(row.querySelector('.it-gross')?.value) || 0,
            volume: parseFloat(row.querySelector('.it-volume')?.value) || 0,
            ctn: parseInt(row.querySelector('.it-ctn')?.value, 10) || 0,
            plb: parseInt(row.querySelector('.it-plb')?.value, 10) || 0,
            gudang: row.querySelector('.it-gudang')?.value.trim() || '',
            do_no: row.querySelector('.it-dono')?.value.trim() || document.getElementById('siDoNo').value,
            so_no: row.querySelector('.it-sono')?.value.trim() || document.getElementById('siSoNo').value,
            po_no: row.querySelector('.it-pono')?.value.trim() || document.getElementById('siPoNo').value,
            pos_tarif: row.querySelector('.it-postarif')?.value.trim() || '',
            cif: parseFloat(row.querySelector('.it-cif')?.value) || 0,
            cif_rupiah: parseFloat(row.querySelector('.it-cifrupiah')?.value) || 0,
            hpp: parseFloat(row.querySelector('.it-hpp')?.value) || 0,
            stok: parseFloat(row.querySelector('.it-stok')?.value) || 0,
            note: row.querySelector('.it-note')?.value.trim() || ''
        });
    });
    return items;
}

// Simpan Data SI
function saveSi() {
    if (guardEdit()) return;
    const vendor = document.getElementById('siVendor').value;
    if (!vendor) {
        showWarning('Buyer / Customer belum dipilih!');
        document.getElementById('siVendor').focus();
        return;
    }
    const items = collectItems();
    if (items.length === 0) {
        showWarning('Mohon isi minimal 1 baris rincian barang!');
        return;
    }
    calcTotals();

    const payload = {
        id: document.getElementById('siId').value,
        si_no: document.getElementById('siNo').value,
        si_dt: document.getElementById('siDt').value,
        ship_dt: document.getElementById('siShipDt').value,
        jatuhtempo: document.getElementById('siJatuhTempo').value,
        do_no: document.getElementById('siDoNo').value,
        so_no: document.getElementById('siSoNo').value,
        po_no: document.getElementById('siPoNo').value,
        vendor: vendor,
        shipto: document.getElementById('siShipTo').value,
        vendoristaxable: document.getElementById('siTaxable').checked ? 'Y' : 'N',
        inclusivetax: document.getElementById('siInclusive').checked ? 'Y' : 'N',
        issubcon: document.getElementById('siSubcon').checked ? 'Y' : 'N',
        ncv: document.getElementById('siNcv').checked ? '1' : '0',
        bill2buyer: document.getElementById('siBill2').checked ? 'Y' : 'N',
        term: document.getElementById('siTerm').value,
        kurs: document.getElementById('siKurs').value,
        rate: parseFloat(document.getElementById('siRate').value) || 1,
        birate: parseFloat(document.getElementById('siBirate').value) || 1,
        est_freight: parseFloat(document.getElementById('siFreight').value) || 0,
        jasa: parseFloat(document.getElementById('siJasa').value) || 0,
        tujuan_kirim: document.getElementById('siTujuan').value,
        kd_fp: document.getElementById('siKdFp').value,
        no_fp: document.getElementById('siNoFp').value,
        bc: document.getElementById('siBc').value,
        no_bc: document.getElementById('siNoBc').value,
        tgl_bc: document.getElementById('siTglBc').value,
        no_aju: document.getElementById('siNoAju').value,
        note: document.getElementById('siNote').value,
        subtotal: lastCalc.subtotal,
        disc_persen: parseFloat(document.getElementById('siDiscPersen').value) || 0,
        disc_amt: lastCalc.discAmt,
        ppn: lastCalc.ppn,
        pph: lastCalc.pph,
        total_order: lastCalc.grand,
        terbayar: parseFloat(document.getElementById('siTerbayar').value) || 0,
        items: items
    };

    setStatus('Menyimpan transaksi...');
    fetch('sales_invoice.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            document.getElementById('siId').value = res.id;
            document.getElementById('siNo').value = res.si_no;
            document.getElementById('siModeBadge').className = 'ant-badge ant-badge-company';
            document.getElementById('siModeBadge').textContent = 'Faktur Terbuka: ' + res.si_no;
            setStatus('Berhasil disimpan. No. Invoice: ' + res.si_no);
            showSuccess('Sales Invoice ' + res.si_no + ' berhasil disimpan!', 'Tersimpan');
            loadSiList(currentPage);
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

function loadSi(id, goEntri) {
    fetch(`sales_invoice.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                showError(res.message || 'Gagal memuat invoice');
                return;
            }
            fillHeader(res.header, res.details || [], !!res.locked);
            if (goEntri) showTab('entri');
        })
        .catch(() => showError('Gagal memuat detail invoice'));
}

function fillHeader(h, details, locked) {
    document.getElementById('siId').value = h.id || '';
    document.getElementById('siNo').value = h.si_no || '';
    document.getElementById('siDt').value = (h.si_dt || '').slice(0, 10);
    document.getElementById('siShipDt').value = (h.ship_dt || '').slice(0, 10);
    document.getElementById('siDoNo').value = h.do_no || '';
    document.getElementById('siSoNo').value = h.so_no || '';
    document.getElementById('siPoNo').value = h.po_no || '';
    document.getElementById('siVendor').value = h.vendor || '';
    document.getElementById('siVendor').dispatchEvent(new Event('change'));
    document.getElementById('siShipTo').value = h.shipto || '';
    document.getElementById('siShipTo').dispatchEvent(new Event('change'));
    document.getElementById('siAlamat').value = h.alamat || '';
    if (!h.alamat) onBuyerChange();
    document.getElementById('siJatuhTempo').value = (h.jatuhtempo || '').slice(0, 10);
    document.getElementById('siTerm').value = h.term || 'Net 30';
    document.getElementById('siKurs').value = h.kurs || 'IDR';
    document.getElementById('siRate').value = h.rate || '1';
    document.getElementById('siBirate').value = h.birate || '1';
    document.getElementById('siTaxable').checked = (h.vendoristaxable || 'Y') !== 'N';
    document.getElementById('siInclusive').checked = (h.inclusivetax || 'N') === 'Y';
    document.getElementById('siSubcon').checked = (h.issubcon || 'N') === 'Y';
    document.getElementById('siNcv').checked = String(h.ncv || '0') === '1';
    document.getElementById('siBill2').checked = (h.bill2buyer || 'N') === 'Y';
    document.getElementById('siKdFp').value = h.kd_fp || '01';
    document.getElementById('siNoFp').value = h.no_fp || '';
    document.getElementById('siBc').value = h.bc || '';
    document.getElementById('siNoBc').value = h.no_bc || '';
    document.getElementById('siTglBc').value = (h.tgl_bc || '').slice(0, 10);
    document.getElementById('siNoAju').value = h.no_aju || '';
    document.getElementById('siTujuan').value = h.tujuan_kirim || '';
    document.getElementById('siNote').value = h.note || '';
    document.getElementById('siDiscPersen').value = h.disc_persen || '0';
    document.getElementById('siDiscAmt').value = h.disc_amt || '0';
    document.getElementById('siJasa').value = h.jasa || '0';
    document.getElementById('siFreight').value = h.est_freight || '0';
    document.getElementById('siPph').checked = parseFloat(h.pph || 0) > 0 || (h.issubcon === 'Y');
    document.getElementById('siTerbayar').value = h.terbayar || '0';

    const cont = document.getElementById('itemsContainer');
    cont.innerHTML = '';
    if (details.length) details.forEach(it => addItemRow(it));
    else addItemRow();

    toggleRate();
    applyLock(!!locked);

    // Simpan snapshot data asli jika sedang mengedit dokumen lama
    if (h.id) {
        originalLoadedData = JSON.parse(JSON.stringify({ header: h, details: details, locked: locked }));
        const btnRestore = document.getElementById('btnRestoreOriginal');
        if (btnRestore) btnRestore.style.display = 'inline-flex';
    } else {
        originalLoadedData = null;
        const btnRestore = document.getElementById('btnRestoreOriginal');
        if (btnRestore) btnRestore.style.display = 'none';
    }

    document.getElementById('siModeBadge').className = 'ant-badge ant-badge-company';
    document.getElementById('siModeBadge').textContent = 'Faktur Terbuka: ' + (h.si_no || '');
    setStatus(locked ? LOCK_MSG : ('Faktur ' + (h.si_no || '') + ' dimuat di Entri.'));
}

// Kembalikan Data ke Kondisi Awal Sebelum Diedit (Dengan Konfirmasi)
async function restoreOriginalData() {
    if (!originalLoadedData || !originalLoadedData.header) {
        showWarning('Tidak ada data awal yang tersimpan untuk transaksi ini.');
        return;
    }
    const no = originalLoadedData.header.si_no || '(tanpa nomor)';
    const ok = await showConfirm(
        `Kembalikan semua field header dan rincian barang Faktur "${no}" ke data awal sebelum diedit?\n\nPerubahan yang belum disimpan akan dibatalkan.`,
        'Konfirmasi Kembalikan Data',
        'Ya, Kembalikan Data Awal',
        'Batal',
        'warning'
    );
    if (!ok) return;

    fillHeader(originalLoadedData.header, originalLoadedData.details, originalLoadedData.locked);
    setStatus(`Data Faktur "${no}" berhasil dikembalikan ke status awal sebelum diedit.`);
    showSuccess(`Data Faktur "${no}" telah dikembalikan ke data awal sebelum diedit!`, 'Data Dikembalikan');
}

// Posting ke IT Inventory (bc_1ab2)
async function postToItInventory() {
    const id = document.getElementById('siId').value;
    const no = document.getElementById('siNo').value;
    if (!id) {
        showWarning('Simpan Sales Invoice terlebih dahulu sebelum posting ke IT Inventory!');
        return;
    }
    const ok = await showConfirm(
        `Posting Sales Invoice ${no} ke IT Inventory (bc_1ab2)?\nSetelah diposting, dokumen akan berstatus TERKUNCI.`,
        'Konfirmasi Posting IT Inventory',
        'Ya, Posting Sekarang',
        'Batal',
        'question'
    );
    if (!ok) return;

    setStatus('Memproses posting ke IT Inventory...');
    const fd = new FormData();
    fd.append('id', id);
    fetch('sales_invoice.php?action=transfer_it_inv', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                showSuccess(res.message, 'Berhasil Diposting');
                applyLock(true);
                loadSiList(currentPage);
            } else {
                showError(res.message || 'Gagal posting IT Inventory');
            }
        })
        .catch(() => showError('Gagal menghubungi server'));
}

// Hapus dari IT Inventory (Buka Kunci)
async function deleteFromItInventory() {
    const id = document.getElementById('siId').value;
    const no = document.getElementById('siNo').value;
    if (!id) return;

    const ok = await showConfirm(
        `Buka kunci dan hapus Sales Invoice ${no} dari IT Inventory (bc_1ab2)?\nDokumen akan dapat diedit kembali.`,
        'Konfirmasi Buka Kunci IT Inventory',
        'Ya, Buka Kunci',
        'Batal',
        'warning'
    );
    if (!ok) return;

    setStatus('Membuka kunci IT Inventory...');
    const fd = new FormData();
    fd.append('id', id);
    fetch('sales_invoice.php?action=delete_it_inv', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                showSuccess(res.message, 'Kunci Terbuka');
                applyLock(false);
                loadSiList(currentPage);
            } else {
                showError(res.message || 'Gagal menghapus dari IT Inventory');
            }
        })
        .catch(() => showError('Gagal menghubungi server'));
}

// Hapus Dokumen
function deleteCurrent() {
    if (guardEdit()) return;
    const id = document.getElementById('siId').value;
    const no = document.getElementById('siNo').value || '(baru)';
    if (!id) {
        resetForm();
        return;
    }
    deleteSi(id, no, true);
}

async function deleteSi(id, siNo, resetAfter) {
    if (siLocked && document.getElementById('siId').value === id) {
        showWarning(LOCK_MSG, 'Tidak Bisa Hapus');
        return;
    }
    const ok = await showConfirm('Hapus Sales Invoice ' + siNo + ' beserta seluruh rincian barangnya?', 'Konfirmasi Hapus', 'Ya, Hapus', 'Batal', 'warning');
    if (!ok) return;
    const fd = new FormData();
    fd.append('id', id);
    fetch('sales_invoice.php?action=delete', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                if (resetAfter || document.getElementById('siId').value === id) resetForm();
                loadSiList(currentPage);
                setStatus('Sales Invoice ' + siNo + ' berhasil dihapus.');
                showSuccess('Sales Invoice ' + siNo + ' dihapus.');
            } else {
                showError(res.message || 'Gagal menghapus');
            }
        })
        .catch(() => showError('Gagal menghubungi server'));
}

// Cetak & Export
function printSi() {
    const id = document.getElementById('siId').value;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'sales_invoice.php?action=print' + (id ? '&id=' + encodeURIComponent(id) : '');
    form.target = '_blank';
    if (!id) {
        const items = collectItems();
        if (!items.length) {
            showWarning('Isi minimal 1 rincian barang sebelum mencetak faktur!');
            return;
        }
        calcTotals();
        const buyer = document.getElementById('siVendor');
        const buyerText = buyer.selectedOptions[0] ? (buyer.selectedOptions[0].dataset.nama || buyer.selectedOptions[0].text) : '';
        const payload = {
            si_no: document.getElementById('siNo').value || '(Belum Disimpan)',
            si_dt: document.getElementById('siDt').value,
            ship_dt: document.getElementById('siShipDt').value,
            jatuhtempo: document.getElementById('siJatuhTempo').value,
            term: document.getElementById('siTerm').value,
            vendor: buyer.value,
            buyer_nama: buyerText,
            alamat: document.getElementById('siAlamat').value,
            do_no: document.getElementById('siDoNo').value,
            so_no: document.getElementById('siSoNo').value,
            po_no: document.getElementById('siPoNo').value,
            no_fp: document.getElementById('siNoFp').value,
            kd_fp: document.getElementById('siKdFp').value,
            bc: document.getElementById('siBc').value,
            no_bc: document.getElementById('siNoBc').value,
            tgl_bc: document.getElementById('siTglBc').value,
            no_aju: document.getElementById('siNoAju').value,
            note: document.getElementById('siNote').value,
            kurs: document.getElementById('siKurs').value,
            subtotal: lastCalc.subtotal,
            disc_persen: lastCalc.discPersen,
            disc_amt: lastCalc.discAmt,
            ppn: lastCalc.ppn,
            pph: lastCalc.pph,
            est_freight: parseFloat(document.getElementById('siFreight').value) || 0,
            total_order: lastCalc.grand,
            terbayar: parseFloat(document.getElementById('siTerbayar').value) || 0,
            outstand: lastCalc.outstand,
            items: items
        };
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'payload';
        input.value = JSON.stringify(payload);
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function exportCsv() {
    const items = collectItems();
    if (!items.length) {
        showWarning('Belum ada barang untuk diekspor!');
        return;
    }
    const h = [
        document.getElementById('siKdFp').value,
        document.getElementById('siNoFp').value,
        document.getElementById('siDt').value,
        document.getElementById('siVendor').value,
        document.getElementById('siNo').value
    ];
    const rows = [['KD_FP', 'NO_FP', 'TGL', 'BUYER', 'NO_INV', 'KODE_BARANG', 'URAIAN', 'QTY', 'SATUAN', 'HARGA_SATUAN', 'TOTAL']];
    items.forEach(it => rows.push(h.concat([it.kode_barang, it.uraian, it.qty, it.sat, it.price, it.amount])));
    const csv = rows.map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = (document.getElementById('siNo').value || 'sales-invoice') + '.csv';
    a.click();
    setStatus('CSV E-Faktur berhasil diunduh.');
}

// Browse Data Table
function loadSiList(page = 1) {
    currentPage = page;
    const q = new URLSearchParams({
        action: 'list',
        page: String(page),
        limit: '25',
        search: document.getElementById('filterSearch').value,
        search_col: document.getElementById('filterCol').value,
        status_bayar: document.getElementById('filterStatus').value,
        tgl_awal: document.getElementById('filterTglAwal').value,
        tgl_akhir: document.getElementById('filterTglAkhir').value
    });
    fetch('sales_invoice.php?' + q.toString())
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('siTableBody');
            if (res.status !== 'OK' || !res.data || !res.data.length) {
                tbody.innerHTML = '<tr><td colspan="16" class="muted" style="padding:16px; text-align:center;">Belum ada data Sales Invoice pada periode filter ini.</td></tr>';
                document.getElementById('pageInfo').textContent = 'Menampilkan 0 data';
                document.getElementById('pageBtns').innerHTML = '';
                return;
            }
            const start = (res.page - 1) * res.limit;
            tbody.innerHTML = res.data.map((r, i) => {
                const isLunas = (parseFloat(r.outstand || 0) <= 0 && parseFloat(r.total_order || 0) > 0);
                const isLock = !!parseInt(r.is_locked || 0);
                return `
                <tr data-id="${escapeHtml(r.id)}" class="${r.id === selectedBrowseId ? 'sel' : ''}">
                    <td style="text-align:center;">${start + i + 1}</td>
                    <td class="td-highlight"><strong>${escapeHtml(r.si_no)}</strong></td>
                    <td>${escapeHtml((r.si_dt || '').slice(0, 10))}</td>
                    <td>${escapeHtml(r.buyer_nama || r.vendor)}</td>
                    <td>${escapeHtml(r.shipto || '')}</td>
                    <td>${escapeHtml(r.kurs || '')}</td>
                    <td class="num"><strong>${formatNum(r.total_order)}</strong></td>
                    <td class="num">${formatNum(r.terbayar)}</td>
                    <td class="num" style="color:${isLunas ? 'var(--success)' : 'var(--danger)'};"><strong>${formatNum(r.outstand)}</strong></td>
                    <td><span class="ant-badge ${isLunas ? 'ant-badge-lunas' : 'ant-badge-belum'}">${isLunas ? 'Lunas' : 'Belum Lunas'}</span></td>
                    <td>${escapeHtml((r.jatuhtempo || '').slice(0, 10))}</td>
                    <td>${escapeHtml(r.no_fp || '')}</td>
                    <td>${escapeHtml(r.bc ? 'BC ' + r.bc : '')}</td>
                    <td><code>${escapeHtml(r.no_aju || '')}</code></td>
                    <td style="text-align:center;">${isLock ? '<i class="fa-solid fa-lock" style="color:var(--danger);" title="Terkunci IT Inventory"></i>' : '-'}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" title="Edit di Entri" onclick="loadSi('${escapeHtml(r.id)}', true)"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" title="Hapus" data-del="${escapeHtml(r.id)}" data-no="${escapeHtml(r.si_no)}"><i class="fa-solid fa-trash"></i></button>
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
                tr.addEventListener('dblclick', () => loadSi(tr.dataset.id, true));
            });

            tbody.querySelectorAll('[data-del]').forEach(btn => {
                btn.addEventListener('click', ev => {
                    ev.stopPropagation();
                    deleteSi(btn.dataset.del, btn.dataset.no, false);
                });
            });

            const totalPages = Math.ceil(res.total / res.limit) || 1;
            document.getElementById('pageInfo').textContent = `Menampilkan ${start + 1} - ${Math.min(res.total, start + res.data.length)} dari ${res.total} data. Dobel-klik baris untuk membuka.`;
            let btns = '';
            if (res.page > 1) btns += `<button type="button" class="ant-btn ant-btn-sm" onclick="loadSiList(${res.page - 1})">Prev</button> `;
            btns += `<span style="margin:0 4px;">${res.page} / ${totalPages}</span> `;
            if (res.page < totalPages) btns += `<button type="button" class="ant-btn ant-btn-sm" onclick="loadSiList(${res.page + 1})">Next</button>`;
            document.getElementById('pageBtns').innerHTML = btns;
        })
        .catch(() => {
            document.getElementById('siTableBody').innerHTML = '<tr><td colspan="16" style="padding:16px; text-align:center; color:var(--danger);">Gagal memuat data dari server.</td></tr>';
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
        box.innerHTML = '<div class="muted" style="padding:12px; text-align:center;">Klik salah satu faktur di atas untuk melihat rincian barangnya.</div>';
        return;
    }
    showBrowseDetail(selectedBrowseId);
}

function showBrowseDetail(id) {
    const box = document.getElementById('browseDetail');
    box.classList.add('show');
    box.innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Memuat detail barang...</div>';
    fetch(`sales_invoice.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                box.innerHTML = '<div class="muted" style="padding:10px;">Detail barang tidak ditemukan.</div>';
                return;
            }
            const rows = (res.details || []).map((it, i) => `
                <tr>
                    <td style="text-align:center;">${i + 1}</td>
                    <td><strong>${escapeHtml(it.kode_barang)}</strong></td>
                    <td>${escapeHtml(it.uraian)}</td>
                    <td>${escapeHtml(it.color_cd || '-')}</td>
                    <td>${escapeHtml(it.size_cd || '-')}</td>
                    <td class="num">${formatNum(it.qty)}</td>
                    <td>${escapeHtml(it.sat)}</td>
                    <td class="num">${formatNum(it.price)}</td>
                    <td class="num">${formatNum(it.disc)}%</td>
                    <td class="num"><strong>${formatNum(it.amount)}</strong></td>
                    <td class="num">${formatNum(it.netto)}</td>
                    <td class="num">${formatNum(it.gross)}</td>
                    <td>${escapeHtml(it.do_no || '')}</td>
                    <td>${escapeHtml(it.so_no || '')}</td>
                    <td>${escapeHtml(it.po_no || '')}</td>
                </tr>`).join('');
            box.innerHTML = `
                <div style="padding:4px 8px; background:var(--table-head-bg); font-weight:600; font-size:10px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between;">
                    <span><i class="fa-solid fa-boxes-stacked"></i> Rincian Barang Faktur: <strong>${escapeHtml(res.header.si_no)}</strong> (Total: ${formatNum(res.header.total_order)})</span>
                    <span>Buyer: <strong>${escapeHtml(res.header.buyer_nama || res.header.vendor)}</strong></span>
                </div>
                <div class="grid-wrap" style="max-height:160px;">
                    <table class="dw" style="min-width:1100px;">
                        <thead>
                            <tr>
                                <th style="width:30px; text-align:center;">#</th>
                                <th>Kode Barang</th>
                                <th>Uraian Barang</th>
                                <th>Color</th>
                                <th>Style</th>
                                <th style="text-align:right;">Qty</th>
                                <th>Satuan</th>
                                <th style="text-align:right;">Harga Sat</th>
                                <th style="text-align:right;">Disc(%)</th>
                                <th style="text-align:right;">Total Nilai</th>
                                <th style="text-align:right;">Netto(Kg)</th>
                                <th style="text-align:right;">Gross(Kg)</th>
                                <th>No. DO</th>
                                <th>No. SO</th>
                                <th>No. PO</th>
                            </tr>
                        </thead>
                        <tbody>${rows || '<tr><td colspan="15" class="muted" style="padding:8px; text-align:center;">Tidak ada barang.</td></tr>'}</tbody>
                    </table>
                </div>`;
        });
}

// Rekap Dokumen Pabean (BC Summary)
function loadRekapBc() {
    const tglAwal = document.getElementById('rekapTglAwal').value;
    const tglAkhir = document.getElementById('rekapTglAkhir').value;
    const tbody = document.getElementById('rekapBcBody');
    tbody.innerHTML = '<tr><td colspan="10" class="muted" style="padding:12px; text-align:center;">Menghitung rekapitulasi dokumen BC...</td></tr>';
    
    fetch(`sales_invoice.php?action=rekap_bc&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK' || !res.data || !res.data.length) {
                tbody.innerHTML = '<tr><td colspan="10" class="muted" style="padding:12px; text-align:center;">Tidak ada data faktur pada periode ini.</td></tr>';
                document.getElementById('statRekapFaktur').textContent = '0';
                document.getElementById('statRekapGrand').textContent = 'IDR 0,00';
                document.getElementById('statRekapPpn').textContent = 'IDR 0,00';
                document.getElementById('statRekapPph').textContent = 'IDR 0,00';
                return;
            }
            document.getElementById('statRekapFaktur').textContent = res.summary.total_faktur;
            document.getElementById('statRekapGrand').textContent = 'IDR ' + formatNum(res.summary.total_grand);
            document.getElementById('statRekapPpn').textContent = 'IDR ' + formatNum(res.summary.total_ppn);
            document.getElementById('statRekapPph').textContent = 'IDR ' + formatNum(res.summary.total_pph);

            tbody.innerHTML = res.data.map(r => `
                <tr>
                    <td><strong>${escapeHtml(r.jenis_dok)}</strong></td>
                    <td class="num">${r.jml_faktur}</td>
                    <td class="num">${r.jml_item}</td>
                    <td class="num">${formatNum(r.total_qty)}</td>
                    <td class="num">${formatNum(r.total_subtotal)}</td>
                    <td class="num">${formatNum(r.total_ppn)}</td>
                    <td class="num">${formatNum(r.total_pph)}</td>
                    <td class="num"><strong>${formatNum(r.grand_total)}</strong></td>
                    <td class="num">${formatNum(r.total_terbayar)}</td>
                    <td class="num" style="color:${parseFloat(r.total_outstand) > 0 ? 'var(--danger)' : 'var(--success)'};">${formatNum(r.total_outstand)}</td>
                </tr>
            `).join('');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="10" class="muted" style="padding:12px; text-align:center; color:var(--danger);">Gagal memuat rekap dokumen.</td></tr>';
        });
}

// Trace Issue & Ex BC (Master-Detail & Cetak Laporan Traceability)
let traceData = null;
let selectedTraceIndex = 0;
let currentBomList = [];
let selectedBomIndex = -1;

function loadTrace() {
    const id = document.getElementById('siId').value;
    if (!id) {
        document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Belum ada invoice terbuka. Dobel-klik data di Browse Data atau buat invoice baru.</div>';
        document.getElementById('traceBom').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Belum ada komponen produksi.</div>';
        document.getElementById('traceAju').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Klik salah satu baris Komponen Bahan Baku untuk melihat dokumen BC Asal.</div>';
        const badge = document.getElementById('traceSelectedFgBadge');
        if (badge) badge.textContent = 'Pilih barang';
        document.getElementById('traceBomCount').textContent = '0 komponen';
        document.getElementById('traceAjuCount').textContent = '0 dokumen asal';
        return;
    }

    document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data traceability...</div>';

    fetch(`sales_invoice.php?action=trace&id=${encodeURIComponent(id)}`)
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
        document.getElementById('traceLines').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">Invoice ini belum memiliki rincian barang.</div>';
        document.getElementById('traceBom').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">-</div>';
        document.getElementById('traceAju').innerHTML = '<div class="muted" style="padding:10px; text-align:center;">-</div>';
        const badge = document.getElementById('traceSelectedFgBadge');
        if (badge) badge.textContent = 'Tidak ada barang';
        return;
    }

    const rowsHtml = lines.map((it, i) => `
        <tr class="${i === selectedTraceIndex ? 'sel' : ''}" style="cursor:pointer;" onclick="selectTraceLine(${i})" title="Klik untuk melihat BOM">
            <td style="text-align:center;">${i + 1}</td>
            <td><strong>${escapeHtml(it.kode_barang)}</strong></td>
            <td>${escapeHtml(it.uraian)}</td>
            <td>${escapeHtml(it.color_cd || '-')}</td>
            <td>${escapeHtml(it.size_cd || '-')}</td>
            <td class="num">${formatNum(it.qty)}</td>
            <td>${escapeHtml(it.sat)}</td>
            <td>${escapeHtml(it.do_no || '-')}</td>
            <td>${escapeHtml(it.so_no || '-')}</td>
            <td style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ${i === selectedTraceIndex ? 'ant-btn-primary' : ''}" onclick="event.stopPropagation(); selectTraceLine(${i})"><i class="fa-solid fa-arrow-right"></i></button></td>
        </tr>
    `).join('');

    document.getElementById('traceLines').innerHTML = `
        <table class="dw">
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;">#</th>
                    <th>Kode Barang</th>
                    <th>Uraian Barang (Finish Goods)</th>
                    <th>Color</th>
                    <th>Style</th>
                    <th style="text-align:right;">Qty</th>
                    <th>Satuan</th>
                    <th>No. DO</th>
                    <th>No. SO</th>
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

    // Badge FG Terpilih
    const badge = document.getElementById('traceSelectedFgBadge');
    if (badge) {
        badge.innerHTML = `<i class="fa-solid fa-check"></i> [${escapeHtml(item.kode_barang)}] ${escapeHtml(item.uraian)}`;
    }

    // Filter BOM Komponen
    const allBom = (traceData && traceData.komponen) || [];
    let bomList = allBom.filter(b => (item.id && b.id_sidtl && b.id_sidtl == item.id) || (b.kode_fg && b.kode_fg === item.kode_barang));
    if (!bomList.length && lines.length === 1) bomList = allBom;
    currentBomList = bomList;
    selectedBomIndex = -1;

    document.getElementById('traceBomCount').textContent = bomList.length + ' komponen';
    if (!bomList.length) {
        document.getElementById('traceBom').innerHTML = `<div class="muted" style="padding:10px; text-align:center;">Belum ada komponen BOM / bahan baku terdaftar untuk barang [${escapeHtml(item.kode_barang)}].</div>`;
    } else {
        const bomRows = bomList.map((b, idx) => `
            <tr class="trace-bom-row" onclick="selectBomLine(${idx})" title="Klik baris ini untuk melihat Dokumen BC Asal">
                <td style="text-align:center;">${idx + 1}</td>
                <td><strong>${escapeHtml(b.kode_bahan || b.kode_barang || '')}</strong></td>
                <td>${escapeHtml(b.uraian || b.nama_bahan || '-')}</td>
                <td class="num"><strong>${formatNum(b.qty_taken || b.qty || 0)}</strong></td>
                <td>${escapeHtml(b.sat || b.sat_bahan || '')}</td>
                <td class="num">${formatNum(b.total_cost || 0)}</td>
                <td style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm" onclick="event.stopPropagation(); selectBomLine(${idx})"><i class="fa-solid fa-arrow-right"></i></button></td>
            </tr>
        `).join('');
        document.getElementById('traceBom').innerHTML = `
            <table class="dw">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">#</th>
                        <th>Kode Bahan</th>
                        <th>Uraian Komponen / Bahan Baku</th>
                        <th style="text-align:right;">Qty Issue</th>
                        <th>Satuan</th>
                        <th style="text-align:right;">Total Cost</th>
                        <th style="width:40px; text-align:center;">Pilih</th>
                    </tr>
                </thead>
                <tbody>${bomRows}</tbody>
            </table>
        `;
    }

    // Data Dokumen Pemasukan Pabean Asal hanya tampil jika baris BOM diklik per baris
    document.getElementById('traceAjuCount').textContent = 'Pilih komponen di atas';
    document.getElementById('traceAju').innerHTML = `<div class="muted" style="padding:14px; text-align:center;"><i class="fa-solid fa-arrow-up" style="color:var(--primary); margin-right:4px;"></i> Klik salah satu baris <strong>Komponen Bahan Baku</strong> di atas untuk melihat <strong>Dokumen Pemasukan Pabean Asal (Tracking Dokumen BC Asal)</strong>.</div>`;
}

function selectBomLine(bIdx) {
    selectedBomIndex = bIdx;
    const b = currentBomList[bIdx];
    if (!b) return;

    // Highlight row di grid BOM
    const tbody = document.querySelector('#traceBom tbody');
    if (tbody) {
        tbody.querySelectorAll('tr').forEach((tr, idx) => {
            tr.classList.toggle('sel', idx === bIdx);
            const btn = tr.querySelector('button');
            if (btn) btn.className = 'ant-btn ant-btn-sm ' + (idx === bIdx ? 'ant-btn-primary' : '');
        });
    }

    const kodeComp = b.kode_bahan || b.kode_barang || '';
    const compId = String(b.id || '');

    // Filter Dokumen Pabean Asal (Eks BC) KHUSUS untuk komponen bahan baku yang diklik
    const allAju = (traceData && traceData.aju) || [];
    const ajuList = allAju.filter(a => {
        if (compId && a.id_sidtl2 && String(a.id_sidtl2) === compId) return true;
        if (kodeComp && a.kode_brg && a.kode_brg.trim().toLowerCase() === kodeComp.trim().toLowerCase()) return true;
        return false;
    });

    document.getElementById('traceAjuCount').textContent = ajuList.length + ' dokumen asal (' + kodeComp + ')';
    if (!ajuList.length) {
        document.getElementById('traceAju').innerHTML = `<div class="muted" style="padding:12px; text-align:center;">Belum ada dokumen pemasukan pabean asal untuk bahan baku <strong>[${escapeHtml(kodeComp)}]</strong> ${escapeHtml(b.uraian || b.nama_bahan || '')}.</div>`;
    } else {
        const ajuRows = ajuList.map((a, idx) => `
            <tr>
                <td style="text-align:center;">${idx + 1}</td>
                <td><span class="ant-badge ant-badge-company">${escapeHtml(a.jenis_bc ? ('BC ' + a.jenis_bc) : 'BC')}</span></td>
                <td><code>${escapeHtml(a.nomor_aju || '-')}</code></td>
                <td><strong>${escapeHtml(a.no_daftar || '-')}</strong></td>
                <td>${escapeHtml((a.tgl_daftar || '').slice(0, 10))}</td>
                <td><strong>${escapeHtml(a.kode_brg || '-')}</strong></td>
                <td>${escapeHtml(a.nama_brg || '-')}</td>
                <td class="num"><strong>${formatNum(a.qty_use || a.qty || 0)}</strong></td>
                <td>${escapeHtml(a.sat || '')}</td>
            </tr>
        `).join('');
        document.getElementById('traceAju').innerHTML = `
            <table class="dw">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">#</th>
                        <th>Jenis BC</th>
                        <th>Nomor AJU</th>
                        <th>No. Daftar</th>
                        <th>Tgl Daftar</th>
                        <th>Kode Bahan</th>
                        <th>Nama Bahan Baku</th>
                        <th style="text-align:right;">Qty Pakai</th>
                        <th>Satuan</th>
                    </tr>
                </thead>
                <tbody>${ajuRows}</tbody>
            </table>
        `;
    }
}

function printTraceReport() {
    const id = document.getElementById('siId').value;
    if (!id) {
        showWarning('Buka atau pilih Sales Invoice terlebih dahulu untuk mencetak Laporan Traceability!');
        return;
    }
    window.open('sales_invoice.php?action=print_trace&id=' + encodeURIComponent(id), '_blank');
}

// Material Picker
function openMaterialPicker(el) {
    if (guardEdit()) return;
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
    const klas = document.querySelector('.klas-btn.active')?.dataset.klas || 'FG';
    const body = document.getElementById('materialBody');
    body.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Mencari data barang...</td></tr>';
    fetch(`sales_invoice.php?action=search_materials&klas=${encodeURIComponent(klas)}&q=${encodeURIComponent(q)}`)
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
    const berat = parseFloat(m.berat) || 0;
    const qty = parseFloat(row.querySelector('.it-qty').value) || 1;
    if (berat > 0) {
        row.querySelector('.it-netto').value = (berat * qty).toFixed(4);
        row.querySelector('.it-gross').value = (berat * qty).toFixed(4);
    }
    calcItemRow(row.querySelector('.it-qty'));

    if (!document.getElementById('materialMulti').checked) {
        closeMaterialPicker();
        setStatus('Barang ' + (m.kode_barang || '') + ' dipilih ke rincian.');
        return;
    }
    addItemRow();
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    pickerRow = rows[rows.length - 1];
    setStatus('Barang ditambahkan. Pilih lagi, atau tutup popup.');
}

// Modal DO
function openLookupDo() {
    if (guardEdit()) return;
    document.getElementById('modalLookupDo').classList.add('active');
    searchDo();
}
function closeLookupDo() {
    document.getElementById('modalLookupDo').classList.remove('active');
}
function searchDo() {
    const q = document.getElementById('searchDoText').value;
    fetch(`sales_invoice.php?action=lookup_do&search=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('lookupDoBody');
            doCache = (res.status === 'OK' && res.data) ? res.data : [];
            if (!doCache.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Tidak ada Surat Jalan Delivery Order yang ditemukan.</td></tr>';
                return;
            }
            tbody.innerHTML = doCache.map((d, i) => `
                <tr>
                    <td><strong>${escapeHtml(d.do_no)}</strong></td>
                    <td>${escapeHtml(d.do_dt || '')}</td>
                    <td>${escapeHtml(d.buyer_nama || d.vendor || '')}</td>
                    <td>${escapeHtml(d.so_no || '')}</td>
                    <td>${escapeHtml(d.po_no || '')}</td>
                    <td style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="selectDo(${i})">Pilih</button></td>
                </tr>`).join('');
        });
}
function selectDo(i) {
    if (guardEdit()) return;
    const d = doCache[i];
    if (!d) return;
    document.getElementById('siDoNo').value = d.do_no || '';
    if (d.vendor) {
        document.getElementById('siVendor').value = d.vendor;
        onBuyerChange();
    }
    if (d.so_no) document.getElementById('siSoNo').value = d.so_no;
    if (d.po_no) document.getElementById('siPoNo').value = d.po_no;
    if (d.do_dt) document.getElementById('siShipDt').value = String(d.do_dt).slice(0, 10);
    if (d.nomor_aju) document.getElementById('siNoAju').value = d.nomor_aju;
    if (d.nomor_daftar) document.getElementById('siNoBc').value = d.nomor_daftar;
    if (d.tanggal_daftar) document.getElementById('siTglBc').value = String(d.tanggal_daftar).slice(0, 10);
    closeLookupDo();

    fetch(`sales_invoice.php?action=get_do_items&do_no=${encodeURIComponent(d.do_no)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK' && res.items && res.items.length) {
                const cont = document.getElementById('itemsContainer');
                cont.innerHTML = '';
                res.items.forEach(it => {
                    const qty = parseFloat(it.qty) || 0;
                    const berat = parseFloat(it.berat) || 0;
                    addItemRow({
                        kode_barang: it.kode_barang,
                        uraian: it.uraian,
                        qty: qty,
                        sat: it.sat,
                        price: it.price || 0,
                        amount: qty * (parseFloat(it.price) || 0),
                        netto: (berat * qty) || 0,
                        gross: (berat * qty) || 0,
                        do_no: d.do_no,
                        so_no: d.so_no || ''
                    });
                });
                calcTotals();
                setStatus('Rincian barang dari Surat Jalan DO ' + d.do_no + ' berhasil ditarik ke Entri.');
            }
        });
}

// Modal SO
function openLookupSo() {
    if (guardEdit()) return;
    document.getElementById('modalLookupSo').classList.add('active');
    searchSo();
}
function closeLookupSo() {
    document.getElementById('modalLookupSo').classList.remove('active');
}
function searchSo() {
    const q = document.getElementById('searchSoText').value;
    fetch(`sales_invoice.php?action=lookup_so&search=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('lookupSoBody');
            soCache = (res.status === 'OK' && res.data) ? res.data : [];
            if (!soCache.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Tidak ada Sales Order yang ditemukan.</td></tr>';
                return;
            }
            tbody.innerHTML = soCache.map((s, i) => `
                <tr>
                    <td><strong>${escapeHtml(s.so_no)}</strong></td>
                    <td>${escapeHtml(s.so_dt || '')}</td>
                    <td>${escapeHtml(s.buyer_nama || s.vendor || '')}</td>
                    <td>${escapeHtml(s.po_no || '')}</td>
                    <td>${escapeHtml(s.term || '')}</td>
                    <td style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="selectSo(${i})">Pilih</button></td>
                </tr>`).join('');
        });
}
function selectSo(i) {
    if (guardEdit()) return;
    const s = soCache[i];
    if (!s) return;
    document.getElementById('siSoNo').value = s.so_no || '';
    if (s.vendor) {
        document.getElementById('siVendor').value = s.vendor;
        onBuyerChange();
    }
    if (s.po_no) document.getElementById('siPoNo').value = s.po_no;
    if (s.term) {
        document.getElementById('siTerm').value = s.term;
        onDateOrTermChange();
    }
    if (s.kurs) {
        document.getElementById('siKurs').value = s.kurs;
        toggleRate();
    }
    if (s.rate) document.getElementById('siRate').value = s.rate;
    if (s.vendoristaxable) document.getElementById('siTaxable').checked = (s.vendoristaxable === 'Y');
    if (s.inclusivetax) document.getElementById('siInclusive').checked = (s.inclusivetax === 'Y');
    closeLookupSo();

    fetch(`sales_invoice.php?action=get_so_items&so_no=${encodeURIComponent(s.so_no)}`)
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK' && res.items && res.items.length) {
                const cont = document.getElementById('itemsContainer');
                cont.innerHTML = '';
                res.items.forEach(it => {
                    const qty = parseFloat(it.qty) || 0;
                    const berat = parseFloat(it.berat) || 0;
                    addItemRow({
                        kode_barang: it.kode_barang,
                        uraian: it.uraian,
                        qty: qty,
                        sat: it.sat,
                        price: it.price || 0,
                        disc: it.disc || 0,
                        amount: qty * (parseFloat(it.price) || 0) * (1 - (parseFloat(it.disc) || 0) / 100),
                        netto: (berat * qty) || 0,
                        gross: (berat * qty) || 0,
                        do_no: '',
                        so_no: s.so_no
                    });
                });
                calcTotals();
                setStatus('Rincian barang dari Sales Order ' + s.so_no + ' berhasil ditarik ke Entri.');
            }
        });
}

// Format Angka & Teks
function formatNum(num) {
    return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Real-time Terbilang Javascript
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

// ==========================================
// 1. CONTEXT MENU INTERAKTIF GRID ITEM (PB Desktop Standard)
// ==========================================
let contextRow = null;

function initGridContextMenu() {
    const table = document.getElementById('tableItems');
    const menu = document.getElementById('gridContextMenu');
    if (!table || !menu) return;

    table.addEventListener('contextmenu', (e) => {
        const row = e.target.closest('tr.item-row');
        if (!row) return;
        e.preventDefault();
        e.stopPropagation();

        contextRow = row;
        // Tandai baris yang diklik kanan
        table.querySelectorAll('tr.item-row').forEach(r => r.classList.remove('context-active'));
        row.classList.add('context-active');

        // Buka context menu dengan perhitungan batas layar (viewport clamping)
        menu.style.display = 'block';
        const menuWidth = 240;
        const menuHeight = 310;
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
    tambahBaris();
}

function ctxHapusBaris() {
    hideContextMenu();
    if (!contextRow) return;
    const btn = contextRow.querySelector('.col-action button');
    if (btn) removeRow(btn);
}

function ctxPilihBarang() {
    hideContextMenu();
    if (!contextRow) return;
    openMaterialPicker(contextRow.querySelector('.it-kode'));
}

function ctxSisipkanAtas() {
    hideContextMenu();
    if (guardEdit() || !contextRow) return;
    addItemRow();
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    const newRow = rows[rows.length - 1];
    contextRow.parentNode.insertBefore(newRow, contextRow);
    renumber();
    calcTotals();
    openMaterialPicker(newRow.querySelector('.it-kode'));
}

function ctxSisipkanBawah() {
    hideContextMenu();
    if (guardEdit() || !contextRow) return;
    addItemRow();
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    const newRow = rows[rows.length - 1];
    contextRow.parentNode.insertBefore(newRow, contextRow.nextSibling);
    renumber();
    calcTotals();
    openMaterialPicker(newRow.querySelector('.it-kode'));
}

function ctxDuplikasiBaris() {
    hideContextMenu();
    if (guardEdit() || !contextRow) return;
    const itemData = {
        kode_barang: contextRow.querySelector('.it-kode')?.value || '',
        uraian: contextRow.querySelector('.it-uraian')?.value || '',
        color_cd: contextRow.querySelector('.it-color')?.value || '',
        size_cd: contextRow.querySelector('.it-style')?.value || '',
        qty: parseFloat(contextRow.querySelector('.it-qty')?.value) || 1,
        sat: contextRow.querySelector('.it-sat')?.value || 'PCE',
        price: parseFloat(contextRow.querySelector('.it-price')?.value) || 0,
        disc: parseFloat(contextRow.querySelector('.it-disc')?.value) || 0,
        amount: parseFloat(contextRow.querySelector('.it-amount')?.value) || 0,
        price_jasa: parseFloat(contextRow.querySelector('.it-price_jasa')?.value) || 0,
        amt_jasa: parseFloat(contextRow.querySelector('.it-amt_jasa')?.value) || 0,
        netto: parseFloat(contextRow.querySelector('.it-netto')?.value) || 0,
        gross: parseFloat(contextRow.querySelector('.it-gross')?.value) || 0,
        volume: parseFloat(contextRow.querySelector('.it-volume')?.value) || 0,
        ctn: parseInt(contextRow.querySelector('.it-ctn')?.value, 10) || 0,
        plb: parseInt(contextRow.querySelector('.it-plb')?.value, 10) || 0,
        gudang: contextRow.querySelector('.it-gudang')?.value || '',
        do_no: contextRow.querySelector('.it-dono')?.value || '',
        so_no: contextRow.querySelector('.it-sono')?.value || '',
        po_no: contextRow.querySelector('.it-pono')?.value || '',
        pos_tarif: contextRow.querySelector('.it-postarif')?.value || '',
        cif: parseFloat(contextRow.querySelector('.it-cif')?.value) || 0,
        cif_rupiah: parseFloat(contextRow.querySelector('.it-cifrupiah')?.value) || 0,
        hpp: parseFloat(contextRow.querySelector('.it-hpp')?.value) || 0,
        stok: parseFloat(contextRow.querySelector('.it-stok')?.value) || 0,
        note: contextRow.querySelector('.it-note')?.value || ''
    };
    addItemRow(itemData);
    const rows = document.querySelectorAll('#itemsContainer .item-row');
    const newRow = rows[rows.length - 1];
    contextRow.parentNode.insertBefore(newRow, contextRow.nextSibling);
    renumber();
    calcTotals();
    setStatus('Baris rincian berhasil diduplikasi.');
}

function ctxHistoryHarga() {
    hideContextMenu();
    if (!contextRow) return;
    openModalHistoryHarga(contextRow);
}

function ctxCekStok() {
    hideContextMenu();
    checkStockShortage();
}

// Global & Grid Keyboard Shortcuts (PB Desktop standard: Ctrl+I / Insert, Ctrl+Del, Ctrl+E)
document.addEventListener('keydown', (e) => {
    const activeModal = document.querySelector('.modal.active');
    if (activeModal) return;

    // Ctrl+I atau Insert: Tambah Baris
    if ((e.ctrlKey && e.key.toLowerCase() === 'i') || (!e.ctrlKey && !e.altKey && !e.shiftKey && e.key === 'Insert')) {
        const entri = document.getElementById('pageEntri');
        if (entri && entri.classList.contains('active')) {
            e.preventDefault();
            tambahBaris();
        }
    }
    // Ctrl+Del: Hapus Baris Aktif
    else if (e.ctrlKey && (e.key === 'Delete' || e.key === 'Del')) {
        const focused = document.activeElement;
        const row = (focused ? focused.closest('tr.item-row') : null) || contextRow;
        if (row) {
            e.preventDefault();
            const btn = row.querySelector('.col-action button');
            if (btn) removeRow(btn);
        }
    }
    // Ctrl+E: Buka Katalog Barang
    else if (e.ctrlKey && e.key.toLowerCase() === 'e') {
        const focused = document.activeElement;
        const row = (focused ? focused.closest('tr.item-row') : null) || contextRow;
        if (row) {
            e.preventDefault();
            openMaterialPicker(row.querySelector('.it-kode'));
        }
    }
});

// ==========================================
// 2. EXCEL MENU & DOWNLOAD TEMPLATE / EXPORT
// ==========================================
function toggleExcelMenu(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('excelMenuDropdown');
    if (!menu) return;
    menu.style.display = (menu.style.display === 'none') ? 'block' : 'none';
}

function hideExcelMenu() {
    const menu = document.getElementById('excelMenuDropdown');
    if (menu) menu.style.display = 'none';
}

document.addEventListener('click', (e) => {
    const menu = document.getElementById('excelMenuDropdown');
    if (menu && menu.style.display !== 'none' && !menu.contains(e.target) && e.target.id !== 'btnExcelMenu') {
        menu.style.display = 'none';
    }
});

function downloadSiImportTemplate() {
    if (typeof XLSX === 'undefined') {
        showError('Library SheetJS belum termuat.');
        return;
    }
    const headers = [
        "KODE_BARANG", "URAIAN_BARANG", "COLOR", "STYLE", "QTY", "SATUAN", 
        "HARGA_SATUAN", "DISC_PERSEN", "HARGA_JASA", "NETTO_KG", "GROSS_KG", 
        "VOLUME", "CTN", "PLB", "GUDANG", "NO_DO", "NO_SO", "NO_PO", "POS_TARIF", "CATATAN"
    ];
    const sampleRows = [
        ["FG-001", "Contoh Sepatu Sneakers Pria", "BLACK", "42", 100, "PRS", 150000, 0, 0, 80, 85, 0.5, 10, 0, "GUDANG FG", "DO-2026-001", "SO-2026-001", "PO-CUST-01", "6404.11.00", "Contoh barang 1"],
        ["FG-002", "Contoh Sandal Santai Wanita", "WHITE", "38", 50, "PRS", 75000, 5, 0, 25, 28, 0.2, 5, 0, "GUDANG FG", "DO-2026-001", "SO-2026-001", "PO-CUST-01", "6402.20.00", "Contoh barang 2"]
    ];
    const ws = XLSX.utils.aoa_to_sheet([headers, ...sampleRows]);
    ws['!cols'] = headers.map(h => ({ wch: Math.max(h.length + 3, 14) }));
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Template_SI");
    XLSX.writeFile(wb, "Template_Import_Sales_Invoice.xlsx");
    setStatus('Template Excel berhasil diunduh.');
}

function exportGridToExcel() {
    if (typeof XLSX === 'undefined') {
        showError('Library SheetJS belum termuat.');
        return;
    }
    const items = collectItems();
    if (!items.length) {
        showWarning('Tidak ada rincian barang untuk di-export.');
        return;
    }
    const siNo = document.getElementById('siNo').value.trim() || 'DRAFT';
    const rows = items.map((it, idx) => ({
        "NO": idx + 1,
        "KODE_BARANG": it.kode_barang,
        "URAIAN_BARANG": it.uraian,
        "COLOR": it.color_cd,
        "STYLE": it.size_cd,
        "QTY": it.qty,
        "SATUAN": it.sat,
        "HARGA_SATUAN": it.price,
        "DISC_PERSEN": it.disc,
        "TOTAL_NILAI": it.amount,
        "HARGA_JASA": it.price_jasa,
        "TOTAL_JASA": it.amt_jasa,
        "NETTO_KG": it.netto,
        "GROSS_KG": it.gross,
        "VOLUME": it.volume,
        "CTN": it.ctn,
        "PLB": it.plb,
        "GUDANG": it.gudang,
        "NO_DO": it.do_no,
        "NO_SO": it.so_no,
        "NO_PO": it.po_no,
        "POS_TARIF": it.pos_tarif,
        "CIF": it.cif,
        "CIF_RUPIAH": it.cif_rupiah,
        "CATATAN": it.note
    }));
    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Rincian_Faktur");
    const filename = `Sales_Invoice_${siNo.replace(/[^a-zA-Z0-9_-]/g, '_')}.xlsx`;
    XLSX.writeFile(wb, filename);
    setStatus(`Rincian barang berhasil diekspor ke ${filename}.`);
}

// ==========================================
// 3. IMPORT EXCEL DENGAN PRATINJAU INTERAKTIF
// ==========================================
let excelImportedRows = [];

function openModalImportExcel() {
    if (guardEdit()) return;
    excelImportedRows = [];
    document.getElementById('excelFileInput').value = '';
    document.getElementById('tbodyImportPreview').innerHTML = '<tr><td colspan="8" class="muted" style="padding:16px; text-align:center;">Pilih file Excel di atas untuk menampilkan pratinjau data.</td></tr>';
    document.getElementById('importPreviewSummary').textContent = 'Pilih file .xlsx atau .csv untuk melihat pratinjau data.';
    document.getElementById('btnApplyImport').disabled = true;
    document.getElementById('modalImportExcel').classList.add('active');
}

function closeModalImportExcel() {
    document.getElementById('modalImportExcel').classList.remove('active');
}

function handleExcelFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            const rawJson = XLSX.utils.sheet_to_json(worksheet, { defval: '' });

            if (!rawJson.length) {
                showWarning('File Excel kosong atau tidak memiliki baris data.');
                return;
            }

            excelImportedRows = [];
            rawJson.forEach(row => {
                const item = parseImportRow(row);
                if (item && item.kode_barang) {
                    excelImportedRows.push(item);
                }
            });

            if (!excelImportedRows.length) {
                document.getElementById('tbodyImportPreview').innerHTML = '<tr><td colspan="8" class="muted" style="padding:16px; text-align:center; color:var(--danger);">Tidak ada data valid yang memiliki Kode Barang. Periksa header kolom Excel.</td></tr>';
                document.getElementById('btnApplyImport').disabled = true;
                return;
            }

            // Render Pratinjau (Maksimal 50 baris untuk performa)
            const previewRows = excelImportedRows.slice(0, 50).map((r, i) => `
                <tr>
                    <td style="text-align:center;">${i + 1}</td>
                    <td><strong>${escapeHtml(r.kode_barang)}</strong></td>
                    <td>${escapeHtml(r.uraian)}</td>
                    <td class="num">${formatNum(r.qty)}</td>
                    <td>${escapeHtml(r.sat)}</td>
                    <td class="num">${formatNum(r.price)}</td>
                    <td class="num">${formatNum(r.disc)}%</td>
                    <td class="num"><strong>${formatNum(r.amount)}</strong></td>
                </tr>
            `).join('');

            document.getElementById('tbodyImportPreview').innerHTML = previewRows;
            document.getElementById('importPreviewSummary').innerHTML = `Terbaca <strong>${excelImportedRows.length}</strong> baris barang valid${excelImportedRows.length > 50 ? ' (menampilkan 50 baris pertama)' : ''}. Klik 'Terapkan ke Faktur' untuk memasukkan ke grid.`;
            document.getElementById('btnApplyImport').disabled = false;
        } catch (err) {
            console.error(err);
            showError('Gagal membaca file Excel: ' + err.message);
        }
    };
    reader.readAsArrayBuffer(file);
}

function parseImportRow(obj) {
    const norm = {};
    Object.keys(obj).forEach(k => {
        const cleanKey = k.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
        norm[cleanKey] = obj[k];
    });

    const getVal = (...keys) => {
        for (const k of keys) {
            const ck = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            if (typeof norm[ck] !== 'undefined' && String(norm[ck]).trim() !== '') {
                return norm[ck];
            }
        }
        return '';
    };

    const kode = String(getVal('kode_barang', 'kode', 'itemcode', 'item_code', 'kdbrg', 'kd_barang', 'partno', 'part_number')).trim();
    if (!kode) return null;

    const uraian = String(getVal('uraian_barang', 'uraian', 'nama', 'nama_barang', 'itemname', 'description', 'deskripsi')).trim();
    const color = String(getVal('color', 'colour', 'warna', 'color_cd')).trim();
    const style = String(getVal('style', 'size', 'ukuran', 'size_cd')).trim();
    const qty = parseFloat(getVal('qty', 'quantity', 'jumlah', 'vol')) || 1;
    const sat = String(getVal('satuan', 'sat', 'uom', 'unit')).trim() || 'PCE';
    const price = parseFloat(getVal('harga_satuan', 'harga', 'price', 'unit_price', 'hrg')) || 0;
    const disc = parseFloat(getVal('disc_persen', 'disc', 'diskon', 'discount')) || 0;
    const priceJasa = parseFloat(getVal('harga_jasa', 'price_jasa', 'jasa')) || 0;
    const netto = parseFloat(getVal('netto_kg', 'netto', 'berat_bersih', 'weight_net')) || 0;
    const gross = parseFloat(getVal('gross_kg', 'gross', 'berat_kotor', 'weight_gross')) || 0;
    const volume = parseFloat(getVal('volume', 'vol_m3')) || 0;
    const ctn = parseInt(getVal('ctn', 'carton', 'koli'), 10) || 0;
    const plb = parseInt(getVal('plb'), 10) || 0;
    const gudang = String(getVal('gudang', 'warehouse', 'loc', 'lokasi')).trim();
    const doNo = String(getVal('no_do', 'dono', 'surat_jalan', 'sj')).trim();
    const soNo = String(getVal('no_so', 'sono', 'sales_order')).trim();
    const poNo = String(getVal('no_po', 'pono', 'purchase_order', 'cust_po')).trim();
    const posTarif = String(getVal('pos_tarif', 'postarif', 'hs_code', 'hs', 'hscode')).trim();
    const note = String(getVal('catatan', 'note', 'keterangan', 'remarks')).trim();

    const amt = (qty * price) * (1 - (disc / 100));

    return {
        kode_barang: kode,
        uraian: uraian,
        color_cd: color,
        size_cd: style,
        qty: qty,
        sat: sat,
        price: price,
        disc: disc,
        amount: amt,
        price_jasa: priceJasa,
        amt_jasa: qty * priceJasa,
        netto: netto,
        gross: gross,
        volume: volume,
        ctn: ctn,
        plb: plb,
        gudang: gudang,
        do_no: doNo,
        so_no: soNo,
        po_no: poNo,
        pos_tarif: posTarif,
        cif: 0,
        cif_rupiah: 0,
        hpp: 0,
        stok: 0,
        note: note
    };
}

function applyImportedData() {
    if (!excelImportedRows.length) return;
    const mode = document.querySelector('input[name="importMode"]:checked')?.value || 'append';
    const container = document.getElementById('itemsContainer');

    if (mode === 'replace') {
        container.innerHTML = '';
    } else {
        const currentRows = container.querySelectorAll('.item-row');
        if (currentRows.length === 1 && !currentRows[0].querySelector('.it-kode').value.trim()) {
            currentRows[0].remove();
        }
    }

    excelImportedRows.forEach(item => {
        addItemRow(item);
    });

    renumber();
    calcTotals();
    closeModalImportExcel();
    showSuccess(`${excelImportedRows.length} baris barang berhasil dimasukkan ke faktur!`, 'Import Berhasil');
    setStatus(`${excelImportedRows.length} baris barang di-import dari Excel.`);
}

// ==========================================
// 4. HISTORY HARGA PENJUALAN (PB m_42)
// ==========================================
let historyPriceTargetRow = null;

function openModalHistoryHarga(rowEl) {
    historyPriceTargetRow = rowEl || contextRow;
    const kode = historyPriceTargetRow ? (historyPriceTargetRow.querySelector('.it-kode')?.value || '').trim() : '';
    if (!kode) {
        showWarning('Pilih baris yang memiliki Kode Barang terlebih dahulu!');
        return;
    }
    const buyerSel = document.getElementById('siVendor');
    const buyerName = buyerSel.selectedOptions[0] ? (buyerSel.selectedOptions[0].dataset.nama || buyerSel.selectedOptions[0].text) : 'Semua Customer';

    document.getElementById('historyKodeBarang').value = kode;
    document.getElementById('historyBuyerName').textContent = buyerName;
    document.getElementById('modalHistoryHarga').classList.add('active');

    loadHistoryHargaData();
}

function closeModalHistoryHarga() {
    document.getElementById('modalHistoryHarga').classList.remove('active');
}

function loadHistoryHargaData() {
    const kode = document.getElementById('historyKodeBarang').value.trim();
    const filterBuyer = document.getElementById('chkHistoryFilterBuyer').checked ? '1' : '0';
    const buyer = document.getElementById('siVendor').value.trim();
    const tbody = document.getElementById('tbodyHistoryHarga');

    tbody.innerHTML = '<tr><td colspan="9" class="muted" style="padding:16px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat riwayat transaksi penjualan...</td></tr>';

    fetch(`sales_invoice.php?action=history_price&kode_barang=${encodeURIComponent(kode)}&buyer=${encodeURIComponent(buyer)}&filter_buyer=${filterBuyer}`)
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK' || !res.data || !res.data.length) {
                tbody.innerHTML = '<tr><td colspan="9" class="muted" style="padding:16px; text-align:center;">Tidak ada riwayat harga penjualan untuk barang ini.</td></tr>';
                return;
            }
            tbody.innerHTML = res.data.map(r => `
                <tr>
                    <td style="text-align:center;"><span class="ant-badge ${r.tipe === 'SI' ? 'ant-badge-company' : 'ant-badge-lunas'}">${escapeHtml(r.tipe)}</span></td>
                    <td><strong>${escapeHtml(r.doc_no)}</strong></td>
                    <td>${escapeHtml(r.doc_dt)}</td>
                    <td>${escapeHtml(r.buyer_nama || r.vendor)}</td>
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

// ==========================================
// 5. CEK STOK KURANG / DEFISIT (PB m_liststokkurang)
// ==========================================
function checkStockShortage() {
    const items = collectItems();
    if (!items.length) {
        showWarning('Tidak ada rincian barang untuk diperiksa stoknya.');
        return;
    }

    const modal = document.getElementById('modalCekStok');
    const tbody = document.getElementById('tbodyCekStok');
    modal.classList.add('active');
    tbody.innerHTML = '<tr><td colspan="8" class="muted" style="padding:16px; text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Memeriksa saldo fisik gudang IT Inventory (bc_1ab2)...</td></tr>';

    fetch('sales_invoice.php?action=check_stock', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ items: items })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status !== 'OK' || !res.items) {
            tbody.innerHTML = '<tr><td colspan="8" class="muted" style="padding:16px; text-align:center; color:var(--danger);">' + (res.message || 'Gagal mengecek stok') + '</td></tr>';
            return;
        }

        const alertBox = document.getElementById('cekStokAlertBox');
        const alertIcon = document.getElementById('cekStokAlertIcon');
        const alertTitle = document.getElementById('cekStokAlertTitle');
        const alertDesc = document.getElementById('cekStokAlertDesc');

        // Bersihkan tanda defisit sebelumnya di grid
        document.querySelectorAll('#tableItems tr.item-row').forEach(tr => tr.classList.remove('row-deficit'));

        const deficitMap = {};
        if (res.summary.deficit_count > 0) {
            alertBox.style.background = '#fff2f0';
            alertBox.style.borderColor = '#ffccc7';
            alertIcon.className = 'fa-solid fa-triangle-exclamation';
            alertIcon.style.color = 'var(--danger)';
            alertTitle.style.color = 'var(--danger)';
            alertTitle.textContent = `PERINGATAN: Ditemukan ${res.summary.deficit_count} barang mengalami defisit stok!`;
            alertDesc.textContent = 'Kuantitas faktur melebihi saldo mutasi IT Inventory pabean (bc_1ab2). Baris defisit ditandai merah di grid.';
        } else {
            alertBox.style.background = '#f6ffed';
            alertBox.style.borderColor = '#b7eb8f';
            alertIcon.className = 'fa-solid fa-circle-check';
            alertIcon.style.color = 'var(--success)';
            alertTitle.style.color = 'var(--success)';
            alertTitle.textContent = 'SEMUA STOK MENCUKUPI (AMAN)';
            alertDesc.textContent = `Seluruh ${res.summary.total_items} barang yang tercantum pada faktur memiliki saldo mutasi IT Inventory yang cukup.`;
        }

        tbody.innerHTML = res.items.map((it, idx) => {
            const isDeficit = it.status === 'DEFISIT';
            if (isDeficit) deficitMap[it.kode_barang] = true;
            return `
                <tr style="${isDeficit ? 'background:rgba(255, 77, 79, 0.08);' : ''}">
                    <td style="text-align:center;">${idx + 1}</td>
                    <td><strong>${escapeHtml(it.kode_barang)}</strong></td>
                    <td>${escapeHtml(it.uraian)}</td>
                    <td class="num"><strong>${formatNum(it.qty_faktur)}</strong></td>
                    <td>${escapeHtml(it.sat)}</td>
                    <td class="num">${formatNum(it.stok_gudang)}</td>
                    <td class="num" style="color:${isDeficit ? 'var(--danger)' : 'var(--text-main)'}; font-weight:${isDeficit ? '700' : '400'};">
                        ${isDeficit ? '-' + formatNum(it.selisih) : '0,00'}
                    </td>
                    <td style="text-align:center;">
                        <span class="ant-badge ${isDeficit ? 'ant-badge-belum' : 'ant-badge-lunas'}">
                            ${isDeficit ? '<i class="fa-solid fa-triangle-exclamation"></i> DEFISIT' : '<i class="fa-solid fa-check"></i> AMAN'}
                        </span>
                    </td>
                </tr>
            `;
        }).join('');

        // Tandai baris defisit pada grid utama
        document.querySelectorAll('#tableItems tr.item-row').forEach(tr => {
            const kode = (tr.querySelector('.it-kode')?.value || '').trim();
            if (deficitMap[kode]) {
                tr.classList.add('row-deficit');
            }
        });
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="8" class="muted" style="padding:16px; text-align:center; color:var(--danger);">Gagal memeriksa ketersediaan stok dari server.</td></tr>';
    });
}

function closeModalCekStok() {
    document.getElementById('modalCekStok').classList.remove('active');
}

function openModuleGuide() {
    const m = document.getElementById('modalGuide');
    if (m) m.classList.add('active');
}

function closeModuleGuide() {
    const m = document.getElementById('modalGuide');
    if (m) m.classList.remove('active');
}

function syncTraceToCeisa() {
    const id = document.getElementById('siId').value;
    if (!id) {
        showWarning('Pilih atau buka Sales Invoice terlebih dahulu!');
        return;
    }
    const ajuList = (traceData && traceData.aju) || [];
    if (!ajuList.length) {
        showWarning('Belum ada data dokumen pabean asal (Eks-BC) yang teridentifikasi untuk invoice ini.');
        return;
    }

    Swal.fire({
        title: 'Sinkronisasi ke CEISA 4.0?',
        text: `Apakah Anda ingin menyinkronkan ${ajuList.length} rekaman bahan baku asal ke modul Dokumen Fasilitas / Eks-BC CEISA 4.0?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#1890ff',
        confirmButtonText: 'Ya, Sinkronkan!'
    }).then(res => {
        if (res.isConfirmed) {
            fetch('sales_invoice.php?action=sync_trace_ceisa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, aju: ajuList })
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'OK') {
                    showSuccess(res.message);
                } else {
                    showError(res.message || 'Gagal menyinkronkan data.');
                }
            })
            .catch(err => {
                showError('Koneksi server gagal: ' + err.message);
            });
        }
    });
}
</script>

<!-- Modal: Panduan Bisnis & SOP Sales Invoice & Traceability -->
<div class="modal" id="modalGuide">
    <div class="modal-card wide" style="max-width:760px;">
        <div class="modal-h">
            <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Bisnis &amp; SOP Faktur Penjualan &amp; Traceability Eks-BC</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="font-size:10.5px; line-height:1.6; max-height:480px; overflow-y:auto; padding:12px 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px;">
                <div>
                    <strong style="color:#1d4ed8; font-size:11.5px;"><i class="fa-solid fa-book"></i> Dokumentasi Arsitektur Resmi</strong>
                    <div style="color:#3b82f6; font-size:9.5px;">Petunjuk Teknis Dual Work Order, Konsumsi Material, &amp; Traceability Dokumen Fasilitas CEISA 4.0</div>
                </div>
                <a href="/docs/PANDUAN_ARSITEKTUR_WO_TRACEABILITY_CEISA4.docx" class="ant-btn ant-btn-primary" download style="text-decoration:none;">
                    <i class="fa-solid fa-file-word"></i> Unduh Dokumen Resmi (.docx)
                </a>
            </div>

            <h4 style="font-size:11px; margin:8px 0 4px 0; color:var(--text-main);"><i class="fa-solid fa-file-invoice-dollar"></i> 1. Penagihan &amp; Hubungan dengan Surat Jalan (DO)</h4>
            <p>Faktur Penjualan (Sales Invoice) dapat dibuat mandiri atau ditarik langsung dari referensi Surat Jalan (Delivery Order) maupun Pesanan Penjualan (Sales Order). Setiap baris produk mencatat kuantitas, harga satuan, diskon, dan status perpajakan (PPN 11% / PPh 23).</p>

            <h4 style="font-size:11px; margin:8px 0 4px 0; color:var(--text-main);"><i class="fa-solid fa-route"></i> 2. Alur Traceability Bahan Baku (Dokumen Fasilitas Eks-BC)</h4>
            <ul style="padding-left:18px; margin:4px 0 10px 0;">
                <li><strong>Penelusuran Otomatis Berbasis Perintah Kerja:</strong> Sistem menelusuri nomor pesanan (SO) ke Perintah Kerja (Work Order) dan realisasi pengeluaran material fisik ke bagian produksi.</li>
                <li><strong>Alokasi Pengiriman Bertahap (Parsial):</strong> Apabila faktur diterbitkan sebagian dari total target produksi, material yang dialokasikan dihitung proporsional sesuai rasio pengiriman terhadap total pesanan.</li>
                <li><strong>Sinkronisasi Dokumen Pabean (Eks-BC):</strong> Klik tombol <em>"Sinkronkan ke Dokumen Eks-BC CEISA 4.0"</em> pada tab Trace untuk menyimpan referensi dokumen pemasukan asal sehingga modul transfer Host-to-Host CEISA 4.0 dapat membaca bahan baku secara otomatis tanpa entri ulang manual.</li>
                <li><strong>Kompatibilitas Penuh:</strong> Dokumen faktur terdahulu yang telah memiliki riwayat penelusuran tetap terjaga 100% tanpa perubahan struktur maupun data historis.</li>
            </ul>

            <h4 style="font-size:11px; margin:8px 0 4px 0; color:var(--text-main);"><i class="fa-solid fa-cube"></i> 3. Kepatuhan Pembukuan IT Inventory</h4>
            <p>Setelah faktur divalidasi, lakukan posting pembukuan ke IT Inventory. Dokumen yang telah diposting terkunci secara otomatis guna menjamin keabsahan data audit kepabeanan.</p>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeModuleGuide()">Tutup</button>
        </div>
    </div>
</div>
<?= renderAuditLogModalHtml() ?>
<script>
<?= renderAuditLogJs('SI') ?>
</script>
</body>
</html>

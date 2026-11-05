<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Pesanan Penjualan (Sales Order / SO)
 * ============================================================================
 * Porting Lengkap dari PowerBuilder TPB-ERP:
 * - w_so.srw (Window Sales Order & Alur Pesanan Penjualan)
 * - bc_so_hdr.srd / bc_so_hdr2.srd (Header Sales Order)
 * - d_so_dtl.srd (Rincian Item Barang yang Dipesan)
 * - d_so_browse.srd (Daftar Dokumen SO, Filter Periode & Status)
 * - d_so_do.srd (Integrasi Realisasi Pengiriman Barang ke Surat Jalan / DO)
 * - d_so_si.srd (Integrasi Penagihan Faktur Penjualan / SI)
 * - f_update_so2.srf (Kalkulasi Otomatis Realisasi Terima/Kirim & Status SO: 0=Open, 1=Parsial, 2=Closed)
 *
 * Mengadopsi 100% Ant Design Compact Desktop 9.5px Standard Seragam ERP Hub
 * Standar Warna Badge & Accent: Emerald Green (#10b981 / #059669)
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
$companyTelp   = '';

if (!empty($sessionCmpId)) {
    $cmpQ = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($cmpQ && $cRow = $cmpQ->fetch_assoc()) {
        if (!empty($cRow['cmp_name'])) $activeCmpName = $cRow['cmp_name'];
        if (!empty($cRow['logo'])) $companyLogo = $cRow['logo'];
        if (!empty($cRow['alamat'])) $companyAlamat = $cRow['alamat'];
        if (!empty($cRow['npwp'])) $companyNpwp = $cRow['npwp'];
        if (!empty($cRow['telp'])) $companyTelp = $cRow['telp'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'TPB ERP Enterprise';

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_so_hdr dan bc_so_dtl Siap Multi-Tenant
// -------------------------------------------------------------------------
function ensureSoTables($koneksi) {
    try {
        // 1. Table bc_so_hdr
        $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_so_hdr'");
        if (!$checkHdr || $checkHdr->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_so_hdr` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `so_no` varchar(50) NOT NULL DEFAULT '',
                `so_dt` date DEFAULT NULL,
                `exp_dt` date DEFAULT NULL,
                `po_no` varchar(50) DEFAULT '',
                `po_dt` date DEFAULT NULL,
                `fob` char(2) DEFAULT '1',
                `term` varchar(30) DEFAULT 'COD',
                `ship` varchar(50) DEFAULT '',
                `vendor` varchar(50) NOT NULL DEFAULT '',
                `shipto` text DEFAULT NULL,
                `vendoristaxable` char(1) DEFAULT 'Y',
                `inclusivetax` char(1) DEFAULT 'N',
                `note` text DEFAULT NULL,
                `subtotal` decimal(18,2) DEFAULT '0.00',
                `disc_persen` decimal(5,2) DEFAULT '0.00',
                `disc_amt` decimal(18,2) DEFAULT '0.00',
                `ppn` decimal(18,2) DEFAULT '0.00',
                `est_freight` decimal(18,2) DEFAULT '0.00',
                `total_order` decimal(18,2) DEFAULT '0.00',
                `kurs` varchar(5) DEFAULT 'IDR',
                `rate` decimal(12,4) DEFAULT '1.0000',
                `set_by` varchar(50) DEFAULT '',
                `status` char(1) DEFAULT '0',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_so` (`cmp_id`, `so_no`),
                KEY `idx_buyer` (`vendor`),
                KEY `idx_so_dt` (`so_dt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $res = $koneksi->query("SHOW COLUMNS FROM bc_so_hdr");
            $cols = [];
            while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_so_hdr ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' FIRST");
                @$koneksi->query("ALTER TABLE bc_so_hdr ADD INDEX `idx_cmp_id` (`cmp_id`)");
            }
        }

        // 2. Table bc_so_dtl
        $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_so_dtl'");
        if (!$checkDtl || $checkDtl->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_so_dtl` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `id_hdr` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `so_no` varchar(50) NOT NULL DEFAULT '',
                `seq` int(11) NOT NULL DEFAULT '1',
                `id_pr` varchar(40) DEFAULT '',
                `kode_barang` varchar(50) NOT NULL DEFAULT '',
                `uraian` varchar(255) NOT NULL DEFAULT '',
                `qty` decimal(18,4) DEFAULT '0.0000',
                `sat` varchar(20) DEFAULT '',
                `price` decimal(18,4) DEFAULT '0.0000',
                `disc` decimal(5,2) DEFAULT '0.00',
                `istax` char(1) DEFAULT 'Y',
                `amount` decimal(18,2) DEFAULT '0.00',
                `terima` decimal(18,4) DEFAULT '0.0000',
                `note` varchar(255) DEFAULT '',
                `color_cd` varchar(80) DEFAULT '',
                `size_cd` varchar(80) DEFAULT '',
                `status` char(1) DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `idx_id_hdr` (`id_hdr`),
                KEY `idx_cmp_so` (`cmp_id`, `so_no`),
                KEY `idx_kode_barang` (`kode_barang`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $res = $koneksi->query("SHOW COLUMNS FROM bc_so_dtl");
            $cols = [];
            while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_so_dtl ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' FIRST");
                @$koneksi->query("ALTER TABLE bc_so_dtl ADD INDEX `idx_cmp_id` (`cmp_id`)");
            }
        }
    } catch (\Throwable $e) {}
}
ensureSoTables($koneksi);

// -------------------------------------------------------------------------
// ROUTER AKSI BACKEND (Mendukung alias nama PB & standar baru)
// -------------------------------------------------------------------------
$action = trim($_REQUEST['action'] ?? '');
if ($action === 'get_so_list') $action = 'list';
if ($action === 'get_so_dtl' || $action === 'get') $action = 'get_so';
if ($action === 'generate_so_no') $action = 'generate_no';
if ($action === 'save_so') $action = 'save';
if ($action === 'delete_so') $action = 'delete';

// -------------------------------------------------------------------------
// 1. DAFTAR SALES ORDER (BROWSE DATA GRID & KPI)
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

        if ($search !== '') {
            $escSearch = $koneksi->real_escape_string($search);
            $where[] = "(h.so_no LIKE '%{$escSearch}%' OR h.po_no LIKE '%{$escSearch}%' OR b.NAMA LIKE '%{$escSearch}%' OR h.note LIKE '%{$escSearch}%')";
        }
        if ($status !== '') {
            $escSt = $koneksi->real_escape_string($status);
            $where[] = "h.status = '{$escSt}'";
        }
        if ($startDate !== '') {
            $escSd = $koneksi->real_escape_string($startDate);
            $where[] = "h.so_dt >= '{$escSd}'";
        }
        if ($endDate !== '') {
            $escEd = $koneksi->real_escape_string($endDate);
            $where[] = "h.so_dt <= '{$escEd}'";
        }

        $whereSql = implode(' AND ', $where);

        // Hitung Total Data
        $countRes = $koneksi->query("SELECT COUNT(1) AS total FROM bc_so_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL)) WHERE {$whereSql}");
        $totalRows = ($countRes && $row = $countRes->fetch_assoc()) ? (int)$row['total'] : 0;

        // Ambil Data Baris SO
        $sql = "SELECT h.id, h.so_no, h.so_dt, h.exp_dt, h.po_no, h.po_dt, h.vendor, 
                       COALESCE(b.NAMA, h.vendor) AS buyer_nama,
                       h.term, h.kurs, h.rate, h.subtotal, h.disc_amt, h.ppn, h.est_freight, h.total_order,
                       h.status, h.set_by, h.note,
                       (SELECT COUNT(1) FROM bc_so_dtl d WHERE d.id_hdr = h.id) AS item_count,
                       (SELECT SUM(d.qty) FROM bc_so_dtl d WHERE d.id_hdr = h.id) AS total_qty_order,
                       (SELECT SUM(d.terima) FROM bc_so_dtl d WHERE d.id_hdr = h.id) AS total_qty_kirim
                FROM bc_so_hdr h
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                WHERE {$whereSql}
                ORDER BY h.so_dt DESC, h.so_no DESC
                LIMIT {$offset}, {$limit}";

        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $orderQty = (float)($r['total_qty_order'] ?? 0);
                $kirimQty = (float)($r['total_qty_kirim'] ?? 0);
                $pct = ($orderQty > 0) ? min(100, round(($kirimQty / $orderQty) * 100)) : 0;

                $r['delivery_pct']  = $pct;
                $r['so_dt_fmt']     = !empty($r['so_dt']) ? date('d/m/Y', strtotime($r['so_dt'])) : '-';
                $r['exp_dt_fmt']    = !empty($r['exp_dt']) ? date('d/m/Y', strtotime($r['exp_dt'])) : '-';
                $r['subtotal_fmt']  = number_format((float)$r['subtotal'], 2, '.', ',');
                $r['total_fmt']     = number_format((float)$r['total_order'], 2, '.', ',');
                $r['qty_order_fmt'] = number_format($orderQty, 2, '.', ',');
                $r['qty_kirim_fmt'] = number_format($kirimQty, 2, '.', ',');
                $data[] = $r;
            }
        }

        // Metrik KPI Cepat
        $kpiRes = $koneksi->query("SELECT 
            COUNT(1) as total_so,
            SUM(CASE WHEN status = '0' THEN 1 ELSE 0 END) as open_so,
            SUM(CASE WHEN status = '1' THEN 1 ELSE 0 END) as partial_so,
            SUM(CASE WHEN status = '2' THEN 1 ELSE 0 END) as closed_so,
            SUM(CASE WHEN MONTH(so_dt) = MONTH(CURRENT_DATE()) AND YEAR(so_dt) = YEAR(CURRENT_DATE()) THEN total_order * rate ELSE 0 END) as nilai_bln_ini,
            COUNT(DISTINCT vendor) as total_buyer
            FROM bc_so_hdr WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        $kpi = ($kpiRes && $kr = $kpiRes->fetch_assoc()) ? [
            'total_so'      => (int)$kr['total_so'],
            'open_so'       => (int)$kr['open_so'],
            'partial_so'    => (int)$kr['partial_so'],
            'closed_so'     => (int)$kr['closed_so'],
            'nilai_bln_ini' => (float)$kr['nilai_bln_ini'],
            'total_buyer'   => (int)$kr['total_buyer']
        ] : ['total_so' => 0, 'open_so' => 0, 'partial_so' => 0, 'closed_so' => 0, 'nilai_bln_ini' => 0, 'total_buyer' => 0];

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
// 2. LOAD DETAIL SALES ORDER UNTUK FORM EDIT
// -------------------------------------------------------------------------
if ($action === 'get_so') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Sales Order tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hdrQ = $koneksi->query("SELECT h.*, 
                                        COALESCE(b.NAMA, h.vendor) AS buyer_nama,
                                        b.ALAMAT AS buyer_alamat,
                                        b.NPWP AS buyer_npwp,
                                        b.TELP AS buyer_telp,
                                        b.PAY_TERM AS default_top
                                 FROM bc_so_hdr h
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                 WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)
                                 LIMIT 1");
        if (!$hdrQ || $hdrQ->num_rows === 0) throw new Exception('Dokumen Sales Order tidak ditemukan.');
        $hdr = $hdrQ->fetch_assoc();

        // Cek apakah SO ini sudah memiliki realisasi Delivery Order (DO)
        $chkDo = $koneksi->query("SELECT COUNT(1) AS cnt_do FROM bc_do_dtl dd 
                                  JOIN bc_so_dtl sd ON dd.id_so = sd.id 
                                  WHERE sd.id_hdr = '{$escId}'");
        $cntDo = ($chkDo && $cd = $chkDo->fetch_assoc()) ? (int)$cd['cnt_do'] : 0;
        $hdr['has_do'] = ($cntDo > 0);

        // Ambil Item Detail
        $dtlQ = $koneksi->query("SELECT d.*, 
                                        m.URAIAN AS mat_uraian,
                                        m.KODE_SATUAN AS mat_satuan,
                                        m.UNIT_PRICE AS mat_price
                                 FROM bc_so_dtl d
                                 LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                                 WHERE d.id_hdr = '{$escId}'
                                 ORDER BY d.seq ASC");
        $items = [];
        if ($dtlQ) {
            while ($r = $dtlQ->fetch_assoc()) {
                $qty = (float)$r['qty'];
                $terima = (float)$r['terima'];
                $r['sisa_kirim'] = max(0, $qty - $terima);
                $r['price_fmt']  = number_format((float)$r['price'], 2, '.', ',');
                $r['amount_fmt'] = number_format((float)$r['amount'], 2, '.', ',');
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
// 3. GENERATE NOMOR URUT SALES ORDER OTOMATIS
// -------------------------------------------------------------------------
if ($action === 'generate_no') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $soDt = trim($_GET['so_dt'] ?? date('Y-m-d'));
        $prefix = "SO/" . date('Ym', strtotime($soDt)) . "/";

        $res = $koneksi->query("SELECT MAX(so_no) as max_no FROM bc_so_hdr 
                                WHERE so_no LIKE '{$prefix}%' 
                                  AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        $maxNo = ($res && $r = $res->fetch_assoc()) ? $r['max_no'] : null;

        if (!empty($maxNo)) {
            $lastNum = intval(substr($maxNo, strlen($prefix)));
            $nextNo = $prefix . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $nextNo = $prefix . "0001";
        }

        echo json_encode(['status' => 'OK', 'so_no' => $nextNo]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 4. SIMPAN TRANSAKSI SALES ORDER (BARU & UPDATE)
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
            $id = md5($sessionCmpId . '_' . uniqid(rand(), true) . '_' . microtime(true));
        }

        $header  = $input['header'] ?? $input;
        $details = $input['details'] ?? [];

        $soNo       = trim($header['so_no'] ?? '');
        $soDt       = !empty($header['so_dt']) ? $header['so_dt'] : date('Y-m-d');
        $expDt      = !empty($header['exp_dt']) ? $header['exp_dt'] : $soDt;
        $vendor     = trim($header['vendor'] ?? '');
        $poNo       = trim($header['po_no'] ?? '');
        $poDt       = !empty($header['po_dt']) ? $header['po_dt'] : $soDt;
        $fob        = trim($header['fob'] ?? '1');
        $term       = trim($header['term'] ?? 'COD');
        $ship       = trim($header['ship'] ?? 'TRUCK');
        $shipto     = trim($header['shipto'] ?? '');
        $isTaxable  = trim($header['vendoristaxable'] ?? 'Y');
        $incTax     = trim($header['inclusivetax'] ?? 'N');
        $kurs       = trim($header['kurs'] ?? 'IDR');
        $rate       = (float)($header['rate'] ?? 1);
        if ($rate <= 0) $rate = 1;
        $status     = trim($header['status'] ?? '0');
        $note       = trim($header['note'] ?? '');

        if (empty($soNo)) throw new Exception('Nomor Sales Order (SO) wajib diisi.');
        if (empty($vendor)) throw new Exception('Pelanggan / Buyer wajib dipilih.');

        $subtotal    = (float)($header['subtotal'] ?? 0);
        $discPersen  = (float)($header['disc_persen'] ?? 0);
        $discAmt     = (float)($header['disc_amt'] ?? 0);
        $ppn         = (float)($header['ppn'] ?? 0);
        $freight     = (float)($header['est_freight'] ?? 0);
        $totalOrder  = (float)($header['total_order'] ?? 0);

        $koneksi->begin_transaction();

        if ($isNew) {
            $stmt = $koneksi->prepare("INSERT INTO bc_so_hdr (
                id, cmp_id, so_no, so_dt, exp_dt, po_no, po_dt, fob, term, ship,
                vendor, shipto, vendoristaxable, inclusivetax, note,
                subtotal, disc_persen, disc_amt, ppn, est_freight, total_order,
                kurs, rate, set_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssssssssdddddsdsss",
                $id, $sessionCmpId, $soNo, $soDt, $expDt, $poNo, $poDt, $fob, $term, $ship,
                $vendor, $shipto, $isTaxable, $incTax, $note,
                $subtotal, $discPersen, $discAmt, $ppn, $freight, $totalOrder,
                $kurs, $rate, $currentUser, $status
            );
            if (!$stmt->execute()) throw new Exception('Gagal menyimpan Header SO: ' . $stmt->error);
            $stmt->close();
        } else {
            $stmt = $koneksi->prepare("UPDATE bc_so_hdr SET
                so_no = ?, so_dt = ?, exp_dt = ?, po_no = ?, po_dt = ?, fob = ?, term = ?, ship = ?,
                vendor = ?, shipto = ?, vendoristaxable = ?, inclusivetax = ?, note = ?,
                subtotal = ?, disc_persen = ?, disc_amt = ?, ppn = ?, est_freight = ?, total_order = ?,
                kurs = ?, rate = ?, set_by = ?, status = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
            $stmt->bind_param("sssssssssssssdddddsdsssss",
                $soNo, $soDt, $expDt, $poNo, $poDt, $fob, $term, $ship,
                $vendor, $shipto, $isTaxable, $incTax, $note,
                $subtotal, $discPersen, $discAmt, $ppn, $freight, $totalOrder,
                $kurs, $rate, $currentUser, $status,
                $id, $sessionCmpId
            );
            if (!$stmt->execute()) throw new Exception('Gagal memperbarui Header SO: ' . $stmt->error);
            $stmt->close();

            // Pertahankan nilai terima lama per kode_barang saat update
            $oldTerimaMap = [];
            $escId = $koneksi->real_escape_string($id);
            $qOld = $koneksi->query("SELECT id, seq, kode_barang, terima FROM bc_so_dtl WHERE id_hdr = '{$escId}'");
            if ($qOld) while ($o = $qOld->fetch_assoc()) $oldTerimaMap[$o['kode_barang']] = (float)$o['terima'];

            $koneksi->query("DELETE FROM bc_so_dtl WHERE id_hdr = '{$escId}'");
        }

        // Simpan Rincian Barang
        $dStmt = $koneksi->prepare("INSERT INTO bc_so_dtl (
            id, id_hdr, cmp_id, so_no, seq, id_pr, kode_barang, uraian,
            qty, sat, price, disc, istax, amount, terima, note,
            color_cd, size_cd, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        $allFulfilled = true;
        $anyDelivered = false;

        foreach ($details as $d) {
            $dtlId = md5($id . '_' . $seq . '_' . microtime(true));
            $kdBrg = trim($d['kode_barang'] ?? '');
            $uraian= trim($d['uraian'] ?? '');
            if (empty($kdBrg) && empty($uraian)) continue;

            $qty   = (float)($d['qty'] ?? 0);
            $sat   = trim($d['sat'] ?? 'PCE');
            $price = (float)($d['price'] ?? 0);
            $disc  = (float)($d['disc'] ?? 0);
            $isTax = trim($d['istax'] ?? 'Y');
            $amt   = (float)($d['amount'] ?? ($qty * $price - $disc));
            $color = trim($d['color_cd'] ?? '');
            $size  = trim($d['size_cd'] ?? '');
            $dNote = trim($d['note'] ?? '');
            $idPr  = trim($d['id_pr'] ?? '');
            $terima= isset($oldTerimaMap[$kdBrg]) ? $oldTerimaMap[$kdBrg] : (float)($d['terima'] ?? 0);

            $itemStatus = ($qty > 0 && $terima >= $qty) ? '1' : '0';
            if ($terima > 0) $anyDelivered = true;
            if ($terima < $qty) $allFulfilled = false;

            $dStmt->bind_param("ssssisssdsddsdsssss",
                $dtlId, $id, $sessionCmpId, $soNo, $seq, $idPr, $kdBrg, $uraian,
                $qty, $sat, $price, $disc, $isTax, $amt, $terima, $dNote,
                $color, $size, $itemStatus
            );
            if (!$dStmt->execute()) throw new Exception('Gagal menyimpan Detail Barang: ' . $dStmt->error);
            $seq++;
        }
        $dStmt->close();

        // Update status SO secara cerdas mengikuti PB f_update_so2.srf
        if ($seq > 1) {
            if ($allFulfilled && $anyDelivered) {
                $status = '2'; // Closed / Selesai Kirim
            } elseif ($anyDelivered) {
                $status = '1'; // Parsial Terkirim
            } else {
                $status = '0'; // Open
            }
            $koneksi->query("UPDATE bc_so_hdr SET status = '{$status}' WHERE id = '{$id}'");
        }

        $koneksi->commit();

        echo json_encode([
            'status'  => 'OK',
            'message' => 'Pesanan Penjualan (Sales Order) berhasil disimpan.',
            'id'      => $id,
            'so_no'   => $soNo
        ]);
    } catch (\Throwable $ex) {
        if ($koneksi) $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 5. HAPUS SALES ORDER DENGAN GUARD LOCK
// -------------------------------------------------------------------------
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_REQUEST['id'] ?? '');
        if (empty($id)) throw new Exception('ID Sales Order tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        // Guard Check: Apakah SO sudah terealisasi ke DO?
        $chkDo = $koneksi->query("SELECT dd.do_no FROM bc_do_dtl dd 
                                  JOIN bc_so_dtl sd ON dd.id_so = sd.id 
                                  WHERE sd.id_hdr = '{$escId}' LIMIT 1");
        if ($chkDo && $cd = $chkDo->fetch_assoc()) {
            throw new Exception("Sales Order ini SUDAH DIPROSES ke Surat Jalan / DO ({$cd['do_no']}). Transaksi TIDAK BISA DIHAPUS!");
        }

        // Hapus detail & header
        $koneksi->query("DELETE FROM bc_so_dtl WHERE id_hdr = '{$escId}'");
        $koneksi->query("DELETE FROM bc_so_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        echo json_encode(['status' => 'OK', 'message' => 'Dokumen Sales Order berhasil dihapus.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 6. KATALOG MASTER BARANG (5 KLASIFIKASI)
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
// 7. TRACEABILITY PESANAN (SO <-> DO <-> SI <-> BC DOKUMEN)
// -------------------------------------------------------------------------
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) throw new Exception('ID Sales Order tidak valid.');
        $escId = $koneksi->real_escape_string($id);

        $hdrQ = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as buyer_nama 
                                 FROM bc_so_hdr h 
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '')) 
                                 WHERE h.id = '{$escId}' LIMIT 1");
        $hdr = ($hdrQ && $hdrQ->num_rows > 0) ? $hdrQ->fetch_assoc() : null;

        $dtlQ = $koneksi->query("SELECT d.*, m.URAIAN as mat_uraian 
                                 FROM bc_so_dtl d 
                                 LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '')) 
                                 WHERE d.id_hdr = '{$escId}' ORDER BY d.seq ASC");
        $details = [];
        $soDtlIds = [];
        if ($dtlQ) {
            while ($r = $dtlQ->fetch_assoc()) {
                $soDtlIds[] = "'" . $koneksi->real_escape_string($r['id']) . "'";
                $details[] = $r;
            }
        }

        // Kueri Delivery Order (DO) Terkait
        $doList = [];
        if (!empty($soDtlIds)) {
            $inIds = implode(',', $soDtlIds);
            $doQ = $koneksi->query("SELECT DISTINCT dh.id, dh.do_no, dh.do_dt, dh.status, 
                                           dh.nomor_daftar as no_bc, dh.tanggal_daftar as tgl_bc, dh.nomor_aju as no_aju, dh.bc_doc_type as bc,
                                           dd.kode_barang, dd.qty, dd.sat
                                    FROM bc_do_dtl dd
                                    JOIN bc_do_hdr dh ON dd.id_hdr = dh.id
                                    WHERE dd.id_so IN ({$inIds}) AND (dh.cmp_id = '{$escCmp}' OR dh.cmp_id = '')
                                    ORDER BY dh.do_dt DESC");
            if ($doQ) while ($d = $doQ->fetch_assoc()) $doList[] = $d;
        }

        // Kueri Sales Invoice (SI) Terkait
        $siList = [];
        if (!empty($hdr['so_no'])) {
            $escSoNo = $koneksi->real_escape_string($hdr['so_no']);
            $siQ = $koneksi->query("SELECT DISTINCT sh.id, sh.si_no, sh.si_dt, sh.total_order, sh.terbayar, sh.status, sh.no_bc, sh.no_aju, sh.bc
                                    FROM bc_si_hdr sh
                                    WHERE (sh.so_no = '{$escSoNo}' OR sh.po_no = '{$escSoNo}')
                                      AND (sh.cmp_id = '{$escCmp}' OR sh.cmp_id = '')
                                    ORDER BY sh.si_dt DESC");
            if ($siQ) while ($s = $siQ->fetch_assoc()) $siList[] = $s;
        }

        echo json_encode([
            'status'  => 'OK',
            'header'  => $hdr,
            'details' => $details,
            'do_list' => $doList,
            'si_list' => $siList
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// 8. CETAK DOKUMEN RESMI A4 SALES ORDER CONFIRMATION
// -------------------------------------------------------------------------
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrQ = $koneksi->query("SELECT a.*, 
                                    COALESCE(b.NAMA, a.vendor) AS buyer_name,
                                    b.ALAMAT AS buyer_alamat,
                                    b.NPWP AS buyer_npwp,
                                    b.TELP AS buyer_telp
                             FROM bc_so_hdr a
                             LEFT JOIN bc_bs b ON (a.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                             WHERE a.id = '{$escId}' AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)
                             LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) die("Dokumen Sales Order tidak ditemukan.");
    $h = $hdrQ->fetch_assoc();

    $dtlQ = $koneksi->query("SELECT d.*, m.URAIAN AS mat_uraian 
                             FROM bc_so_dtl d
                             LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                             WHERE d.id_hdr = '{$escId}' 
                             ORDER BY d.seq ASC");
    $items = [];
    if ($dtlQ) while ($r = $dtlQ->fetch_assoc()) $items[] = $r;
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Sales Order - <?= htmlspecialchars($h['so_no']) ?></title>
        <style>
            @page { size: A4 portrait; margin: 12mm 15mm; }
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 9.5px; color: #111; margin: 0; padding: 10px; }
            .header-table { width: 100%; border-bottom: 2px solid #059669; padding-bottom: 8px; margin-bottom: 12px; }
            .logo-img { max-height: 52px; max-width: 140px; object-fit: contain; }
            h2 { margin: 0 0 2px; font-size: 15px; color: #059669; text-transform: uppercase; letter-spacing: 0.5px; }
            .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .info-grid td { vertical-align: top; padding: 2px 4px; font-size: 9.5px; }
            .item-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            .item-table th { background: #ecfdf5; border: 1px solid #059669; padding: 5px 6px; font-size: 9px; text-align: left; }
            .item-table td { border: 1px solid #d1d5db; padding: 4px 6px; font-size: 9px; }
            .tot-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            .tot-table td { padding: 2px 6px; font-size: 9.5px; }
            .tot-table tr.grand td { font-weight: 700; font-size: 11px; border-top: 2px solid #059669; border-bottom: 2px solid #059669; }
            .sign-grid { width: 100%; margin-top: 35px; border-collapse: collapse; page-break-inside: avoid; }
            .sign-grid td { width: 33.3%; text-align: center; vertical-align: top; font-size: 9px; }
            .sign-space { height: 55px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body onload="window.print()">
        <div class="no-print" style="margin-bottom: 12px;">
            <button onclick="window.print()" style="padding: 6px 12px; font-size: 11px; cursor: pointer; font-weight: 600;">🖨️ Cetak Sales Order</button>
            <button onclick="window.close()" style="padding: 6px 12px; font-size: 11px; cursor: pointer;">Tutup</button>
        </div>

        <table class="header-table">
            <tr>
                <td style="width: 15%; vertical-align: middle;">
                    <img src="<?= htmlspecialchars($companyLogo) ?>" class="logo-img" alt="Logo">
                </td>
                <td style="width: 55%; vertical-align: middle;">
                    <h2><?= htmlspecialchars($activeCmpName) ?></h2>
                    <div><?= htmlspecialchars($companyAlamat ?: 'Kawasan Berikat Enterprise') ?></div>
                    <div>NPWP: <?= htmlspecialchars($companyNpwp ?: '-') ?> | Telp: <?= htmlspecialchars($companyTelp ?: '-') ?></div>
                </td>
                <td style="width: 30%; text-align: right; vertical-align: middle;">
                    <strong style="font-size: 14px; color: #059669;">SALES ORDER</strong><br>
                    <b style="font-size: 12px;"><?= htmlspecialchars($h['so_no']) ?></b><br>
                    <span style="font-size: 9px; color: #555;">Tgl SO: <?= date('d/m/Y', strtotime($h['so_dt'])) ?></span>
                </td>
            </tr>
        </table>

        <table class="info-grid">
            <tr>
                <td style="width: 50%;">
                    <div style="border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 8px;">
                        <strong>KEPADA PELANGGAN / BUYER:</strong><br>
                        <b><?= htmlspecialchars($h['buyer_name']) ?></b> (<?= htmlspecialchars($h['vendor']) ?>)<br>
                        <?= nl2br(htmlspecialchars($h['buyer_alamat'] ?: '-')) ?><br>
                        NPWP: <?= htmlspecialchars($h['buyer_npwp'] ?: '-') ?> | Telp: <?= htmlspecialchars($h['buyer_telp'] ?: '-') ?>
                    </div>
                </td>
                <td style="width: 50%;">
                    <div style="border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 8px;">
                        <strong>RINCIAN KESEPAKATAN PESANAN:</strong><br>
                        No. PO Buyer: <b><?= htmlspecialchars($h['po_no'] ?: '-') ?></b><br>
                        Tgl PO Buyer: <?= !empty($h['po_dt']) ? date('d/m/Y', strtotime($h['po_dt'])) : '-' ?><br>
                        Rencana Kirim: <b><?= !empty($h['exp_dt']) ? date('d/m/Y', strtotime($h['exp_dt'])) : '-' ?></b><br>
                        Termin / Valuta: <?= htmlspecialchars($h['term']) ?> | <?= htmlspecialchars($h['kurs']) ?> (Rate: <?= number_format((float)$h['rate'], 2) ?>)<br>
                        Alamat Kirim: <?= htmlspecialchars($h['shipto'] ?: $h['buyer_alamat']) ?>
                    </div>
                </td>
            </tr>
        </table>

        <table class="item-table">
            <thead>
                <tr>
                    <th style="width: 25px; text-align: center;">No</th>
                    <th style="width: 100px;">Kode Barang</th>
                    <th>Deskripsi / Uraian Barang</th>
                    <th style="width: 60px;">Warna/Size</th>
                    <th style="width: 65px; text-align: right;">Qty</th>
                    <th style="width: 45px; text-align: center;">Sat</th>
                    <th style="width: 80px; text-align: right;">Harga Sat</th>
                    <th style="width: 85px; text-align: right;">Jumlah (<?= htmlspecialchars($h['kurs']) ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                <tr>
                    <td style="text-align: center;"><?= $idx + 1 ?></td>
                    <td><b><?= htmlspecialchars($it['kode_barang']) ?></b></td>
                    <td><?= htmlspecialchars($it['uraian']) ?></td>
                    <td><?= htmlspecialchars(trim(($it['color_cd'] ?? '') . ' ' . ($it['size_cd'] ?? '')) ?: '-') ?></td>
                    <td style="text-align: right; font-weight: 600;"><?= number_format((float)$it['qty'], 2) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($it['sat']) ?></td>
                    <td style="text-align: right;"><?= number_format((float)$it['price'], 2) ?></td>
                    <td style="text-align: right; font-weight: 600;"><?= number_format((float)$it['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table style="width: 100%; margin-top: 8px;">
            <tr>
                <td style="vertical-align: top; width: 55%;">
                    <div style="border: 1px dashed #059669; padding: 6px 10px; border-radius: 4px; font-size: 9px;">
                        <strong>Catatan / Keterangan Tambahan:</strong><br>
                        <?= nl2br(htmlspecialchars($h['note'] ?: 'Pesanan penjualan resmi sesuai kesepakatan PO Pelanggan.')) ?>
                    </div>
                </td>
                <td style="vertical-align: top; width: 45%;">
                    <table class="tot-table">
                        <tr>
                            <td>Subtotal Nilai Pesanan:</td>
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
                            <td>PPN 11%:</td>
                            <td style="text-align: right;"><?= number_format((float)$h['ppn'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ((float)$h['est_freight'] > 0): ?>
                        <tr>
                            <td>Estimasi Ongkos Kirim:</td>
                            <td style="text-align: right;"><?= number_format((float)$h['est_freight'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="grand">
                            <td>TOTAL ORDER (SO):</td>
                            <td style="text-align: right; color: #059669;"><?= number_format((float)$h['total_order'], 2) ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="sign-grid">
            <tr>
                <td><strong>Dibuat oleh (Marketing)</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>Disetujui (Sales Manager)</strong><div class="sign-space"></div>( .................................. )</td>
                <td><strong>Dikonfirmasi (Buyer)</strong><div class="sign-space"></div>( .................................. )</td>
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
$buyerQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, PAY_TERM AS TOP, kurs AS CURRENCY 
                           FROM bc_bs 
                           WHERE (TIPE = 'B' OR TIPE = 'BUYER' OR TIPE = 'CUSTOMER' OR TIPE IS NULL OR TIPE = '') 
                             AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                           ORDER BY NAMA ASC");
$buyers = [];
if ($buyerQ) while ($r = $buyerQ->fetch_assoc()) $buyers[] = $r;

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
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan Penjualan (Sales Order) - <?= htmlspecialchars($activeCmpName) ?></title>
    
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
            --primary: #10b981;
            --primary-hover: #059669;
            --primary-light: #ecfdf5;
            --primary-focus: rgba(16, 185, 129, 0.25);
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #0ea5e9;
            --bg-app: #f4f6f9;
            --bg-card: #ffffff;
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --table-head-bg: #f8fafc;
            --row-hover: #f0fdf4;
            --row-selected: #dcfce7;
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
            --row-hover: #14532d;
            --row-selected: #064e3b;
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
            gap: 4px;
            line-height: 1;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color: #fff; }
        .ant-btn-danger { background: #fee2e2; border-color: #fca5a5; color: #b91c1c; }
        .ant-btn-danger:hover { background: #ef4444; border-color: #ef4444; color: #fff; }
        .ant-btn-success { background: #10b981; border-color: #10b981; color: #fff; }
        .ant-btn-success:hover { background: #059669; border-color: #059669; color: #fff; }
        .ant-btn-sm { height: 18px; padding: 0 5px; font-size: 8.5px; }

        /* Tabs Nav */
        .ant-tabs-nav {
            display: flex;
            align-items: center;
            gap: 2px;
            padding: 0 10px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-card);
            flex-shrink: 0;
        }

        .ant-tab-btn {
            height: 26px;
            padding: 0 12px;
            font-size: 10px;
            font-family: var(--app-font);
            font-weight: 600;
            border: none;
            border-bottom: 2px solid transparent;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }

        /* Pages */
        .ant-page {
            display: none;
            flex: 1;
            flex-direction: column;
            overflow: hidden;
            background: var(--bg-card);
        }
        .ant-page.active { display: flex; }

        /* Split Columns & Panes */
        .split-col {
            display: flex;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        .split-pane {
            overflow: auto;
            position: relative;
        }
        #paneHdr { flex: 0 0 148px; min-height: 40px; padding: 6px 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        #paneGrid { flex: 1; overflow: auto; position: relative; background: var(--bg-card); }
        #paneFtr { flex: 0 0 114px; min-height: 40px; padding: 6px 10px; background: var(--bg-app); border-top: 1px solid var(--border-color); }

        .splitbar {
            height: 5px;
            background: var(--border-light);
            cursor: row-resize;
            position: relative;
            z-index: 10;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .splitbar:hover, .splitbar.dragging { background: var(--primary); }
        .split-btn-collapse {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: 32px;
            height: 11px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 2px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 7px;
            color: var(--text-muted);
            line-height: 1;
        }

        /* Form Layout 2-Column */
        .hdr-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .hdr-col { display: flex; flex-direction: column; gap: 4px; }
        .fld-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .fld-row label {
            width: 120px;
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-main);
            flex-shrink: 0;
            white-space: nowrap;
        }
        .fld-input {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        input[type="text"], input[type="date"], input[type="number"], select, textarea {
            height: 21px;
            padding: 0 6px;
            font-size: 9.5px;
            font-family: var(--app-font);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        input:focus, select:focus, textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-focus); }
        input[readonly] { background: var(--bg-app); color: var(--text-muted); cursor: not-allowed; }

        .highlight-no {
            font-weight: 700;
            color: var(--primary);
            background: var(--primary-light) !important;
            border-color: #a7f3d0 !important;
        }

        /* Grid Table DataWindow */
        table.dw {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.dw thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: var(--table-head-bg);
            color: var(--text-main);
            border-right: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-color);
            padding: 3px 6px;
            font-size: 9.5px;
            font-weight: 600;
            text-align: left;
            user-select: none;
        }
        table.dw tbody tr { border-bottom: 1px solid var(--border-light); }
        table.dw tbody tr:hover { background: var(--row-hover); }
        table.dw tbody tr.selected { background: var(--row-selected); }
        table.dw tbody td {
            border-right: 1px solid var(--border-light);
            padding: 2px 4px;
            font-size: 9.5px;
        }
        table.dw tbody td input {
            width: 100%;
            border: 1px solid transparent;
            background: transparent;
            height: 19px;
            padding: 0 4px;
        }
        table.dw tbody td input:focus {
            background: var(--bg-card);
            border-color: var(--primary);
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* Footer Summary */
        .ftr-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 12px;
            height: 100%;
        }
        .terbilang-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 6px 8px;
            font-size: 9px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .terbilang-text {
            font-style: italic;
            font-weight: 600;
            color: var(--primary);
            line-height: 1.4;
        }
        .calc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }
        .calc-table td { padding: 1.5px 4px; }
        .calc-table tr.grand-row td {
            font-weight: 700;
            font-size: 11px;
            border-top: 1px solid var(--border-color);
            color: var(--primary);
            padding-top: 3px;
        }

        /* 4 KPI Strip in Browse Tab */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            padding: 6px 10px;
            background: var(--bg-app);
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 6px 8px;
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

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            width: 650px;
            max-height: 85vh;
        }
        .modal-card.wide { width: 850px; }
        .modal-h {
            padding: 6px 10px;
            background: var(--primary);
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 10.5px;
        }
        .modal-b { padding: 8px 10px; overflow: auto; flex: 1; }
        .modal-f {
            padding: 5px 10px;
            background: var(--bg-app);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Context Menu */
        .context-menu {
            display: none;
            position: fixed;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            border-radius: 3px;
            z-index: 9999;
            min-width: 170px;
            padding: 3px 0;
        }
        .context-menu-item {
            padding: 4px 10px;
            font-size: 9.5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-main);
        }
        .context-menu-item:hover { background: var(--primary-light); color: var(--primary-hover); }
        .context-menu-divider { height: 1px; background: var(--border-light); margin: 3px 0; }

        /* Lock banner */
        .lock-banner {
            display: none;
            background: #fffbeb;
            border-bottom: 1px solid #fef3c7;
            color: #b45309;
            padding: 4px 10px;
            font-size: 9px;
            font-weight: 600;
            align-items: center;
            justify-content: space-between;
        }
    </style>
</head>
<body>

<div class="ant-container">
    <div class="ant-card">
        <!-- HEADER PORTAL / TOOLBAR -->
        <div class="ant-card-header">
            <div class="ant-card-title">
                <i class="fa-solid fa-cart-shopping" style="color:var(--primary); font-size:13px;"></i>
                <span>PESANAN PENJUALAN (SALES ORDER)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-status" id="badgeSoStatus">OPEN</span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="newSo()"><i class="fa-solid fa-file-circle-plus"></i> + Baru</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="saveSo()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                <button type="button" class="ant-btn ant-btn-danger" onclick="deleteSo()"><i class="fa-solid fa-trash-can"></i> Hapus</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="printSo()"><i class="fa-solid fa-print"></i> Cetak Surat Pesanan (SO)</button>
                
                <!-- Dropdown Excel -->
                <div style="position:relative; display:inline-block;">
                    <button type="button" class="ant-btn" onclick="toggleExcelMenu()"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Excel <i class="fa-solid fa-caret-down"></i></button>
                    <div id="excelDrop" class="context-menu" style="top:23px; right:0;">
                        <div class="context-menu-item" onclick="exportExcelSheetJS()"><i class="fa-solid fa-file-export"></i> Ekspor Data (.xlsx)</div>
                        <div class="context-menu-item" onclick="openModule('sale_so_import')"><i class="fa-solid fa-file-import"></i> Impor dari Excel (SO Import)</div>
                    </div>
                </div>

                <button type="button" class="ant-btn" onclick="openModule('sale_do')"><i class="fa-solid fa-truck-ramp-box" style="color:#0ea5e9;"></i> Modul DO</button>
                <button type="button" class="ant-btn" onclick="openModule('sale_si')"><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Modul SI</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul & SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- 3 TABS NAVIGASI UTAMA -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-page="pageEntry" onclick="switchPage('pageEntry')"><i class="fa-solid fa-pen-to-square"></i> Entri (Sales Order)</button>
            <button type="button" class="ant-tab-btn" data-page="pageBrowse" onclick="switchPage('pageBrowse')"><i class="fa-solid fa-table-list"></i> Browse Data</button>
            <button type="button" class="ant-tab-btn" data-page="pageTrace" onclick="switchPage('pageTrace')"><i class="fa-solid fa-diagram-project"></i> Trace Pengiriman (DO) & Penagihan (SI)</button>
        </div>

        <!-- TAB 1: FORM TRANSAKSI SALES ORDER (SPLITTERS & DUAL PANE) -->
        <section class="ant-page active" id="pageEntry">
            <div id="soLock" class="lock-banner">
                <span><i class="fa-solid fa-lock"></i> Sales Order ini SUDAH TEREALISASI ke Surat Jalan / Delivery Order (DO). Formulir terkunci untuk mencegah selisih pengiriman.</span>
            </div>

            <div class="split-col">
                <!-- PANE 1: HEADER FORM 2-KOLOM -->
                <div class="split-pane" id="paneHdr">
                    <form id="formSo" autocomplete="off" onsubmit="return false;">
                        <input type="hidden" id="soId">
                        <input type="hidden" id="soStatus" value="0">
                        <input type="hidden" id="soIsLock" value="N">

                        <div class="hdr-grid">
                            <!-- Kolom Kiri: Buyer, Alamat, Syarat Pembayaran & Pengiriman -->
                            <div class="hdr-col">
                                <div class="fld-row">
                                    <label for="soBuyer">Pelanggan / Buyer *</label>
                                    <div class="fld-input">
                                        <select id="soBuyer" style="flex:1;" onchange="onBuyerChange(this.value)">
                                            <option value="">-- Pilih Pelanggan / Buyer --</option>
                                            <?php foreach ($buyers as $b): ?>
                                                <option value="<?= htmlspecialchars($b['KODE']) ?>" data-alamat="<?= htmlspecialchars($b['ALAMAT'] ?? '') ?>" data-top="<?= htmlspecialchars($b['TOP'] ?? 'COD') ?>" data-kurs="<?= htmlspecialchars($b['CURRENCY'] ?? 'IDR') ?>">
                                                    <?= htmlspecialchars($b['KODE']) ?> - <?= htmlspecialchars($b['NAMA']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="ant-btn ant-btn-sm" onclick="openModule('erp_buyer_supplier')" title="Master Pelanggan"><i class="fa-solid fa-user-tag" style="color:var(--primary);"></i></button>
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="soShipto">Alamat Pengiriman</label>
                                    <div class="fld-input">
                                        <input type="text" id="soShipto" placeholder="Alamat tujuan pengiriman fisik barang..." style="width:100%;">
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="soTerm">Termin & Pengiriman</label>
                                    <div class="fld-input">
                                        <input type="text" id="soTerm" value="COD" placeholder="Termin (misal: Net 30)" style="width:110px;">
                                        <select id="soShip" style="width:110px;">
                                            <option value="TRUCK">TRUCK</option>
                                            <option value="CONTAINER">CONTAINER</option>
                                            <option value="AIR FREIGHT">AIR FREIGHT</option>
                                            <option value="SEA FREIGHT">SEA FREIGHT</option>
                                            <option value="PICKUP">PICKUP SENDIRI</option>
                                        </select>
                                        <label style="font-size:9px; margin-left:6px; cursor:pointer;"><input type="checkbox" id="soTaxable" checked onchange="calcSoSummary()"> PPN 11%</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Kolom Kanan: Nomor SO, Tanggal, No PO Buyer, Kurs & Rate -->
                            <div class="hdr-col">
                                <div class="fld-row">
                                    <label for="soNo">No. Sales Order (SO) *</label>
                                    <div class="fld-input">
                                        <input type="text" id="soNo" class="highlight-no" placeholder="SO/YYMM/XXXX" style="flex:1;">
                                        <button type="button" class="ant-btn ant-btn-sm" onclick="generateNo()" title="Auto-generate Nomor SO"><i class="fa-solid fa-rotate"></i></button>
                                        <span style="font-size:9px; color:var(--text-muted); margin-left:4px;">Kurs:</span>
                                        <select id="soKurs" style="width:65px;" onchange="onKursChange()">
                                            <?php foreach ($kursList as $c): ?>
                                                <option value="<?= htmlspecialchars($c) ?>" <?= $c === 'IDR' ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" id="soRate" value="1.0000" step="0.0001" style="width:70px; text-align:right;" oninput="calcSoSummary()">
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="soDt">Tgl Order & Rencana Kirim</label>
                                    <div class="fld-input">
                                        <input type="date" id="soDt" value="<?= $today ?>" style="flex:1;">
                                        <input type="date" id="soExpDt" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" style="flex:1;" title="Tanggal Rencana Pengiriman (Exp Date)">
                                    </div>
                                </div>
                                <div class="fld-row">
                                    <label for="soPoNo">No. PO Buyer & Tgl PO</label>
                                    <div class="fld-input">
                                        <input type="text" id="soPoNo" placeholder="Nomor PO dari Buyer..." style="flex:1;">
                                        <input type="date" id="soPoDt" value="<?= $today ?>" style="width:115px;" title="Tanggal PO Buyer">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- SPLITBAR 1 (HEADER <-> GRID) -->
                <div class="splitbar" id="splitHdr" title="Geser untuk mengatur tinggi form header">
                    <button type="button" class="split-btn-collapse" onclick="togglePaneCollapse('paneHdr', this)" title="Ciutkan / Bentangkan Header"><i class="fa-solid fa-chevron-up"></i></button>
                </div>

                <!-- PANE 2: GRID RINCIAN ITEM BARANG (d_so_dtl) -->
                <div class="split-pane" id="paneGrid">
                    <table class="dw" id="tblItems">
                        <thead>
                            <tr>
                                <th style="width:30px; text-align:center;">No</th>
                                <th style="width:130px;">Kode Barang</th>
                                <th>Deskripsi / Uraian Barang</th>
                                <th style="width:85px;" class="text-right">Qty Order *</th>
                                <th style="width:55px; text-align:center;">Satuan</th>
                                <th style="width:90px;" class="text-right">Harga Satuan *</th>
                                <th style="width:55px;" class="text-right">Disc(%)</th>
                                <th style="width:105px;" class="text-right">Jumlah Total</th>
                                <th style="width:75px;">Color</th>
                                <th style="width:60px;">Size</th>
                                <th style="width:75px;" class="text-right" title="Realisasi Kuantitas Terkirim di Surat Jalan (DO)">Terkirim</th>
                                <th style="width:75px;" class="text-right" title="Sisa Kuantitas yang Belum Dikirim">Sisa Kirim</th>
                                <th>Catatan Item</th>
                                <th style="width:35px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <!-- Baris item dinamis -->
                        </tbody>
                    </table>
                </div>

                <!-- SPLITBAR 2 (GRID <-> FOOTER) -->
                <div class="splitbar" id="splitFtr" title="Geser untuk mengatur tinggi summary footer">
                    <button type="button" class="split-btn-collapse" onclick="togglePaneCollapse('paneFtr', this)" title="Ciutkan / Bentangkan Footer"><i class="fa-solid fa-chevron-down"></i></button>
                </div>

                <!-- PANE 3: FOOTER & KALKULATOR TOTAL (dw_3) -->
                <div class="split-pane" id="paneFtr">
                    <div class="ftr-grid">
                        <!-- Kolom Kiri: Catatan & Auto Terbilang -->
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <textarea id="soNote" placeholder="Catatan instruksi khusus pesanan penjualan, toleransi pengiriman, nomor referensi kontrak, dll..." style="width:100%; height:50px; resize:none;"></textarea>
                            <div class="terbilang-box">
                                <span style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TERBILANG JUMLAH:</span>
                                <span class="terbilang-text" id="txtTerbilang"># Nol Rupiah #</span>
                            </div>
                        </div>

                        <!-- Kolom Kanan: Kalkulator Keuangan -->
                        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:3px; padding:4px 8px;">
                            <table class="calc-table">
                                <tr>
                                    <td>Subtotal Pesanan:</td>
                                    <td class="text-right" id="txtSubtotal">0.00</td>
                                </tr>
                                <tr>
                                    <td style="display:flex; align-items:center; gap:4px;">
                                        <span>Diskon Tambahan (%):</span>
                                        <input type="number" id="soDiscPersen" value="0.00" step="0.1" style="width:50px; height:18px; text-align:right;" oninput="calcSoSummary()">
                                    </td>
                                    <td class="text-right" id="txtDiscAmt" style="color:var(--danger);">-0.00</td>
                                </tr>
                                <tr>
                                    <td>PPN (11%):</td>
                                    <td class="text-right" id="txtPpn">0.00</td>
                                </tr>
                                <tr>
                                    <td style="display:flex; align-items:center; gap:4px;">
                                        <span>Estimasi Ongkos Kirim:</span>
                                        <input type="number" id="soFreight" value="0.00" step="1000" style="width:80px; height:18px; text-align:right;" oninput="calcSoSummary()">
                                    </td>
                                    <td class="text-right" id="txtFreight">0.00</td>
                                </tr>
                                <tr class="grand-row">
                                    <td>TOTAL PENJUALAN (SO):</td>
                                    <td class="text-right" id="txtGrandTotal">0.00</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 2: BROWSE DATA SALES ORDER (d_so_browse) -->
        <section class="ant-page" id="pageBrowse">
            <!-- 4 KPI Metrics Strip -->
            <div class="kpi-strip">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#ecfdf5; color:#10b981;"><i class="fa-solid fa-cart-shopping"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">TOTAL SALES ORDER</div>
                        <div style="font-size:12px; font-weight:700;" id="kpiTotalSo">0 SO</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">SO OPEN (AKTIF)</div>
                        <div style="font-size:12px; font-weight:700; color:#d97706;" id="kpiOpenSo">0 SO</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#eff6ff; color:#0284c7;"><i class="fa-solid fa-truck-fast"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">SO PARSIAL KIRIM</div>
                        <div style="font-size:12px; font-weight:700; color:#0284c7;" id="kpiPartialSo">0 SO</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#f5f3ff; color:#8b5cf6;"><i class="fa-solid fa-coins"></i></div>
                    <div>
                        <div style="font-size:8.5px; color:var(--text-muted); font-weight:600;">NILAI SO BULAN INI</div>
                        <div style="font-size:12px; font-weight:700; color:#8b5cf6;" id="kpiNilaiBlnIni">Rp 0.00</div>
                    </div>
                </div>
            </div>

            <!-- Filter Toolbar 1-Baris -->
            <div style="padding:4px 10px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:8px; flex-shrink:0; background:var(--bg-card); flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:4px;">
                    <span style="font-size:9px; font-weight:600; color:var(--text-muted);">Periode:</span>
                    <input type="date" id="bStartDate" style="width:115px;">
                    <span>s/d</span>
                    <input type="date" id="bEndDate" style="width:115px;">
                </div>
                <div style="display:flex; align-items:center; gap:4px;">
                    <span style="font-size:9px; font-weight:600; color:var(--text-muted);">Status:</span>
                    <select id="bStatus" style="width:110px;" onchange="loadBrowseData(1)">
                        <option value="">-- Semua Status --</option>
                        <option value="0">Open (Baru)</option>
                        <option value="1">Parsial Terkirim</option>
                        <option value="2">Closed (Selesai)</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:4px; margin-left:auto;">
                    <input type="text" id="bSearch" placeholder="Cari No. SO, PO, Pelanggan..." style="width:180px;" onkeydown="if(event.key==='Enter') loadBrowseData(1)">
                    <button type="button" class="ant-btn" onclick="loadBrowseData(1)"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
                    <button type="button" class="ant-btn" onclick="exportBrowseExcel()"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Excel</button>
                </div>
            </div>

            <!-- Table DataGrid Browse (d_so_browse) -->
            <div class="grid-wrap">
                <table class="dw" id="tblBrowse">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">No</th>
                            <th style="width:125px;">No. Sales Order</th>
                            <th style="width:70px;">Tgl SO</th>
                            <th style="width:70px;">Rencana Kirim</th>
                            <th style="width:125px;">No. PO Buyer</th>
                            <th>Pelanggan / Buyer</th>
                            <th style="width:45px; text-align:center;">Term</th>
                            <th style="width:40px; text-align:center;">Kurs</th>
                            <th style="width:50px; text-align:center;">Item</th>
                            <th style="width:105px;" class="text-right">Total Order</th>
                            <th style="width:85px; text-align:center;">Progress DO</th>
                            <th style="width:75px; text-align:center;">Status</th>
                            <th>Catatan Pesanan</th>
                            <th style="width:45px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="browseBody">
                        <tr><td colspan="14" class="text-center" style="padding:25px; color:var(--text-muted);">Memuat daftar Sales Order...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginasi -->
            <div class="status-bar">
                <span id="txtBrowseCount">Menampilkan 0 dokumen Sales Order</span>
                <div style="display:flex; align-items:center; gap:4px;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="navBrowsePage(-1)"><i class="fa-solid fa-chevron-left"></i> Prev</button>
                    <span id="txtBrowsePage" style="font-weight:600; padding:0 4px;">1</span>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="navBrowsePage(1)">Next <i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
        </section>

        <!-- TAB 3: TRACEABILITY PENGIRIMAN (DO) & PENAGIHAN (SI) -->
        <section class="ant-page" id="pageTrace">
            <div style="padding:6px 10px; background:var(--bg-app); border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-weight:700; color:var(--primary); font-size:10.5px;">ALUR DOKUMEN PESANAN PENJUALAN:</span>
                    <strong id="traceSoNo" style="font-size:11px; color:#0369a1;">-</strong>
                    <span class="ant-badge" id="traceBuyerName" style="background:#e0f2fe; color:#0369a1;">-</span>
                </div>
                <button type="button" class="ant-btn ant-btn-sm" onclick="reloadTrace()"><i class="fa-solid fa-rotate"></i> Muat Ulang Trace</button>
            </div>

            <div class="grid-wrap" style="padding:10px; display:flex; flex-direction:column; gap:12px;">
                <!-- SEKSI 1: STATUS PEMENUHAN KUANTITAS PER ITEM (ORDER VS DELIVERED) -->
                <div style="border:1px solid var(--border-color); border-radius:4px; overflow:hidden;">
                    <div style="background:var(--table-head-bg); padding:4px 8px; font-weight:700; font-size:9.5px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between;">
                        <span><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Realisasi Pengiriman Item Barang (SO vs Delivery Order)</span>
                        <span id="txtTraceItemCount" style="color:var(--text-muted);">0 Item</span>
                    </div>
                    <table class="dw" style="min-width:0;">
                        <thead>
                            <tr>
                                <th style="width:30px; text-align:center;">Seq</th>
                                <th style="width:130px;">Kode Barang</th>
                                <th>Deskripsi Barang</th>
                                <th style="width:90px;" class="text-right">Qty Pesanan</th>
                                <th style="width:90px;" class="text-right">Qty Terkirim (DO)</th>
                                <th style="width:90px;" class="text-right">Sisa Belum Kirim</th>
                                <th style="width:100px; text-align:center;">Progress</th>
                            </tr>
                        </thead>
                        <tbody id="traceItemsBody">
                            <tr><td colspan="7" class="text-center" style="padding:15px; color:var(--text-muted);">Buka dokumen SO terlebih dahulu untuk melihat rantai pesanan.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- SEKSI 2: DAFTAR SURAT JALAN / DELIVERY ORDER (DO) TERKAIT -->
                <div style="border:1px solid var(--border-color); border-radius:4px; overflow:hidden;">
                    <div style="background:var(--table-head-bg); padding:4px 8px; font-weight:700; font-size:9.5px; border-bottom:1px solid var(--border-color);">
                        <i class="fa-solid fa-truck-ramp-box" style="color:#0ea5e9;"></i> Surat Jalan / Delivery Order (DO) Terkait
                    </div>
                    <table class="dw" style="min-width:0;">
                        <thead>
                            <tr>
                                <th style="width:125px;">No. Delivery Order</th>
                                <th style="width:70px;">Tgl DO</th>
                                <th style="width:120px;">No. Surat Jalan</th>
                                <th style="width:120px;">Kode Barang</th>
                                <th style="width:80px;" class="text-right">Qty Kirim</th>
                                <th style="width:50px; text-align:center;">Satuan</th>
                                <th style="width:85px;">Dokumen BC</th>
                                <th style="width:120px;">No. Pendaftaran BC</th>
                            </tr>
                        </thead>
                        <tbody id="traceDoBody">
                            <tr><td colspan="8" class="text-center" style="padding:12px; color:var(--text-muted);">Belum ada pengiriman barang untuk SO ini.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- SEKSI 3: DAFTAR FAKTUR PENJUALAN / SALES INVOICE (SI) TERKAIT -->
                <div style="border:1px solid var(--border-color); border-radius:4px; overflow:hidden;">
                    <div style="background:var(--table-head-bg); padding:4px 8px; font-weight:700; font-size:9.5px; border-bottom:1px solid var(--border-color);">
                        <i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Faktur Penjualan / Sales Invoice (SI) Terkait
                    </div>
                    <table class="dw" style="min-width:0;">
                        <thead>
                            <tr>
                                <th style="width:125px;">No. Sales Invoice</th>
                                <th style="width:70px;">Tgl Invoice</th>
                                <th style="width:110px;" class="text-right">Total Tagihan</th>
                                <th style="width:110px;" class="text-right">Sudah Dibayar</th>
                                <th style="width:85px; text-align:center;">Status Bayar</th>
                                <th style="width:85px;">Dokumen BC</th>
                                <th style="width:140px;">Nomor AJU (26 Digit)</th>
                            </tr>
                        </thead>
                        <tbody id="traceSiBody">
                            <tr><td colspan="7" class="text-center" style="padding:12px; color:var(--text-muted);">Belum ada penagihan faktur untuk pesanan ini.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: KATALOG MASTER BARANG (5 KLASIFIKASI: FG, RM, WIP, CG, SCRAP)       -->
<!-- ========================================================================= -->
<div class="modal" id="modalMaterial">
    <div class="modal-card wide">
        <div class="modal-h">
            <span><i class="fa-solid fa-boxes-stacked"></i> Katalog Master Barang</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeMaterialPicker()" style="color:#fff; border-color:transparent; background:transparent;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div style="display:flex; gap:4px; margin-bottom:8px;">
                <button type="button" class="ant-btn klas-btn active" data-klas="FG" onclick="setKlas('FG')">Barang Jadi (FG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="RM" onclick="setKlas('RM')">Bahan Baku (RM)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="WI" onclick="setKlas('WI')">WIP</button>
                <button type="button" class="ant-btn klas-btn" data-klas="CG" onclick="setKlas('CG')">Mesin (CG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="W" onclick="setKlas('W')">Scrap</button>
            </div>
            <div style="display:flex; gap:6px; margin-bottom:8px;">
                <input type="text" id="materialSearch" style="flex:1;" placeholder="Ketik kode barang atau nama..." onkeydown="if(event.key==='Enter'){event.preventDefault(); searchMaterials();}">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchMaterials()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="max-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:130px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width:60px;">Satuan</th>
                            <th style="width:95px;" class="text-right">Harga Sat</th>
                            <th style="width:110px;">Klasifikasi</th>
                        </tr>
                    </thead>
                    <tbody id="materialBody">
                        <tr><td colspan="5" class="text-center" style="padding:15px;">Memuat barang...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <label style="font-size:8.5px; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" id="chkMaterialMulti"> Multi-Pilih (Tetap buka popup setelah memilih)</label>
            <button type="button" class="ant-btn" onclick="closeMaterialPicker()">Tutup</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PANDUAN & SOP MODUL SALES ORDER                                    -->
<!-- ========================================================================= -->
<div class="modal" id="modalGuide">
    <div class="modal-card">
        <div class="modal-h">
            <span><i class="fa-solid fa-circle-question"></i> Panduan & SOP Pesanan Penjualan (Sales Order)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()" style="color:#fff; border-color:transparent; background:transparent;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="line-height:1.6; font-size:10px;">
            <h4 style="color:var(--primary); margin-bottom:4px;">1. Alur Transaksi Penjualan (Sales Cycle)</h4>
            <p>Modul Sales Order (SO) adalah awal dari siklus pengeluaran barang dan piutang usaha:</p>
            <ul style="padding-left:18px; margin:4px 0 8px;">
                <li><b>Input Pesanan:</b> Catat kesepakatan PO dari Buyer lengkap dengan kuantitas, harga, dan jadwal rencana pengiriman (Exp Date).</li>
                <li><b>Realisasi Pengeluaran Fisik (DO):</b> Kuantitas SO akan ditarik ke Surat Jalan / Delivery Order (DO) saat barang siap dikeluarkan dari gudang.</li>
                <li><b>Penagihan (Sales Invoice):</b> Dokumen DO atau SO kemudian ditarik ke Sales Invoice untuk mencatatkan piutang dagang.</li>
            </ul>

            <h4 style="color:var(--primary); margin:10px 0 4px;">2. Status Sales Order (Otomatis)</h4>
            <ul style="padding-left:18px; margin:4px 0 8px;">
                <li><b>Open (0):</b> Pesanan baru disimpan, belum ada barang yang dikirimkan.</li>
                <li><b>Parsial (1):</b> Sebagian kuantitas barang telah terkirim via Delivery Order.</li>
                <li><b>Closed (2):</b> Seluruh kuantitas barang telah 100% terkirim.</li>
            </ul>
        </div>
        <div class="modal-f">
            <span style="font-size:8.5px; color:var(--text-muted);">SOP Kawasan Berikat & CEISA 4.0 ERP</span>
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Saya Mengerti</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- RIGHT-CLICK CONTEXT MENU                                                  -->
<!-- ========================================================================= -->
<div class="context-menu" id="gridContextMenu">
    <div class="context-menu-item" onclick="addItemRow()"><i class="fa-solid fa-plus" style="color:var(--primary);"></i> Tambah Baris Baru</div>
    <div class="context-menu-item" onclick="deleteSelectedRow()"><i class="fa-solid fa-trash-can" style="color:var(--danger);"></i> Hapus Baris Terpilih</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="openMaterialPicker()"><i class="fa-solid fa-boxes-stacked" style="color:#0ea5e9;"></i> Pilih Master Barang...</div>
    <div class="context-menu-item" onclick="openModule('sale_so_import')"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Impor dari Excel...</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="exportExcelSheetJS()"><i class="fa-solid fa-file-export" style="color:#107c41;"></i> Ekspor ke Excel (.xlsx)</div>
</div>

<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let currentActiveTargetInput = null;
let currentMaterialKlas = 'FG';
let currentBrowsePage = 1;
let currentBrowseLimit = 25;
let currentSelectedRow = null;

document.addEventListener('DOMContentLoaded', () => {
    initSplitbars();
    if (typeof initAntSearchableSelect === 'function') {
        initAntSearchableSelect('soBuyer', { placeholder: 'Ketik nama / kode buyer...' });
    }
    newSo();

    // Tutup context menu saat klik luar
    document.addEventListener('click', () => {
        document.getElementById('gridContextMenu').style.display = 'none';
        const ed = document.getElementById('excelDrop');
        if (ed) ed.style.display = 'none';
    });

    // Delegasi contextmenu di tabel rincian
    const tbl = document.getElementById('tblItems');
    if (tbl) {
        tbl.addEventListener('contextmenu', (e) => {
            const tr = e.target.closest('tr');
            if (tr && tr.parentElement.tagName === 'TBODY') {
                e.preventDefault();
                tr.parentElement.querySelectorAll('tr').forEach(r => r.classList.remove('selected'));
                tr.classList.add('selected');
                currentSelectedRow = tr;
                const menu = document.getElementById('gridContextMenu');
                menu.style.display = 'block';
                menu.style.left = `${e.pageX}px`;
                menu.style.top = `${e.pageY}px`;
            }
        });
    }
});

// -------------------------------------------------------------------------
// DUAL SPLITTERS ENGINE
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
            if (newHeight > 400) newHeight = 400;
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
        pane.style.flex = pane.dataset.prevFlex || (paneId === 'paneHdr' ? '0 0 148px' : '0 0 114px');
        pane.dataset.collapsed = 'false';
        btn.querySelector('i').className = paneId === 'paneHdr' ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down';
    } else {
        pane.dataset.prevFlex = pane.style.flex || (paneId === 'paneHdr' ? '0 0 148px' : '0 0 114px');
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

function isLocked() {
    return document.getElementById('soIsLock').value === 'Y';
}

function guardEdit() {
    if (isLocked()) {
        Swal.fire({
            icon: 'warning',
            title: 'Dokumen Terkunci',
            text: 'Sales Order ini sudah diproses ke Delivery Order (DO). Perubahan dibatasi untuk menjaga konsistensi fisik gudang.',
            confirmButtonColor: 'var(--primary)'
        });
        return true;
    }
    return false;
}

// -------------------------------------------------------------------------
// INISIALISASI DOKUMEN SALES ORDER BARU
// -------------------------------------------------------------------------
function newSo() {
    document.getElementById('formSo').reset();
    document.getElementById('soId').value = '';
    document.getElementById('soStatus').value = '0';
    document.getElementById('soIsLock').value = 'N';
    document.getElementById('soLock').style.display = 'none';

    document.getElementById('soDt').value = '<?= $today ?>';
    document.getElementById('soPoDt').value = '<?= $today ?>';
    document.getElementById('soExpDt').value = '<?= date('Y-m-d', strtotime('+14 days')) ?>';
    document.getElementById('soRate').value = '1.0000';
    document.getElementById('soFreight').value = '0.00';
    document.getElementById('soDiscPersen').value = '0.00';

    updateStatusBadge('0');
    const selB = document.getElementById('soBuyer');
    if (selB) {
        selB.value = '';
        if (selB.syncAntSelect) selB.syncAntSelect();
    }
    document.getElementById('itemsBody').innerHTML = '';
    addItemRow();
    generateNo();
    calcSoSummary();
}

async function generateNo() {
    try {
        const soDt = document.getElementById('soDt').value;
        const res = await fetch(`sales_order.php?action=generate_no&so_dt=${encodeURIComponent(soDt)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status === 'OK' && json.so_no) {
            document.getElementById('soNo').value = json.so_no;
        }
    } catch (e) {
        console.error('Gagal generate nomor SO:', e);
    }
}

function updateStatusBadge(status) {
    const badge = document.getElementById('badgeSoStatus');
    if (!badge) return;
    badge.className = 'ant-badge';

    if (status === '2') {
        badge.innerText = 'CLOSED (LUNAS DO)';
        badge.style.background = '#f1f5f9';
        badge.style.color = '#475569';
        badge.style.borderColor = '#cbd5e1';
    } else if (status === '1') {
        badge.innerText = 'PARSIAL KIRIM';
        badge.style.background = '#e0f2fe';
        badge.style.color = '#0369a1';
        badge.style.borderColor = '#bae6fd';
    } else {
        badge.innerText = 'OPEN (BELUM KIRIM)';
        badge.style.background = '#ecfdf5';
        badge.style.color = '#059669';
        badge.style.borderColor = '#a7f3d0';
    }
}

function onBuyerChange(buyerKode) {
    const sel = document.getElementById('soBuyer');
    if (!sel || !sel.selectedIndex) return;
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.dataset) return;

    if (opt.dataset.alamat) {
        document.getElementById('soShipto').value = opt.dataset.alamat;
    }
    if (opt.dataset.top) {
        document.getElementById('soTerm').value = opt.dataset.top;
    }
    if (opt.dataset.kurs) {
        document.getElementById('soKurs').value = opt.dataset.kurs;
        onKursChange();
    }
}

function onKursChange() {
    const kurs = document.getElementById('soKurs').value;
    if (kurs === 'IDR') {
        document.getElementById('soRate').value = '1.0000';
    }
    calcSoSummary();
}

// -------------------------------------------------------------------------
// MANAJEMEN BARIS DETAIL BARANG (d_so_dtl)
// -------------------------------------------------------------------------
function addItemRow(data = null) {
    const tbody = document.getElementById('itemsBody');
    const rowIdx = tbody.children.length + 1;
    const tr = document.createElement('tr');
    tr.dataset.idx = rowIdx;

    const kdVal      = data ? escapeHtml(data.kode_barang || '') : '';
    const urVal      = data ? escapeHtml(data.uraian || '') : '';
    const qtyVal     = data ? parseFloat(data.qty || 1) : 1;
    const satVal     = data ? escapeHtml(data.sat || 'PCE') : 'PCE';
    const priceVal   = data ? parseFloat(data.price || 0) : 0;
    const discVal    = data ? parseFloat(data.disc || 0) : 0;
    const amtVal     = data ? parseFloat(data.amount || (qtyVal * priceVal - discVal)) : (qtyVal * priceVal);
    const colorVal   = data ? escapeHtml(data.color_cd || '') : '';
    const sizeVal    = data ? escapeHtml(data.size_cd || '') : '';
    const terimaVal  = data ? parseFloat(data.terima || 0) : 0;
    const sisaVal    = Math.max(0, qtyVal - terimaVal);
    const noteVal    = data ? escapeHtml(data.note || '') : '';

    tr.innerHTML = `
        <td class="text-center row-seq">${rowIdx}</td>
        <td>
            <div style="display:flex; align-items:center; gap:2px;">
                <input type="text" class="it-kd" value="${kdVal}" placeholder="Kode..." style="font-weight:600; text-transform:uppercase;">
                <button type="button" class="ant-btn ant-btn-sm" onclick="triggerRowMaterialPicker(this)" title="Pilih Barang"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </td>
        <td><input type="text" class="it-uraian" value="${urVal}" placeholder="Deskripsi barang pesanan..."></td>
        <td><input type="number" class="it-qty text-right" value="${qtyVal}" step="0.01" style="font-weight:600;" oninput="calcItemRow(this)"></td>
        <td><input type="text" class="it-sat text-center" value="${satVal}" style="text-transform:uppercase;"></td>
        <td><input type="number" class="it-price text-right" value="${priceVal}" step="0.01" oninput="calcItemRow(this)"></td>
        <td><input type="number" class="it-disc text-right" value="${discVal}" step="0.1" oninput="calcItemRow(this)"></td>
        <td><input type="text" class="it-amount text-right" value="${amtVal.toFixed(2)}" readonly style="font-weight:700; color:#b91c1c;"></td>
        <td><input type="text" class="it-color" value="${colorVal}" placeholder="Warna"></td>
        <td><input type="text" class="it-size" value="${sizeVal}" placeholder="Size"></td>
        <td class="text-right"><span class="it-terima" style="padding:0 4px; font-weight:600; color:#0369a1;">${terimaVal.toFixed(2)}</span></td>
        <td class="text-right"><span class="it-sisa" style="padding:0 4px; font-weight:700; color:#15803d;">${sisaVal.toFixed(2)}</span></td>
        <td><input type="text" class="it-note" value="${noteVal}" placeholder="Catatan..."></td>
        <td class="text-center">
            <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteItemRow(this)" title="Hapus"><i class="fa-solid fa-xmark"></i></button>
        </td>
    `;
    tbody.appendChild(tr);
    reindexItemRows();
    calcSoSummary();
}

function deleteItemRow(btn) {
    if (guardEdit()) return;
    const tr = btn.closest('tr');
    tr.remove();
    reindexItemRows();
    calcSoSummary();
}

function deleteSelectedRow() {
    if (!currentSelectedRow) return;
    deleteItemRow(currentSelectedRow.querySelector('.ant-btn-danger'));
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
    if (discPct > 0) sub -= (sub * (discPct / 100));
    tr.querySelector('.it-amount').value = sub.toFixed(2);

    const terima = parseFloat(tr.querySelector('.it-terima').innerText) || 0;
    tr.querySelector('.it-sisa').innerText = Math.max(0, qty - terima).toFixed(2);

    calcSoSummary();
}

function calcSoSummary() {
    let subtotal = 0;
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        subtotal += parseFloat(tr.querySelector('.it-amount').value) || 0;
    });

    const discPersen = parseFloat(document.getElementById('soDiscPersen').value) || 0;
    const discAmt = subtotal * (discPersen / 100);
    const dpp = Math.max(0, subtotal - discAmt);

    const isTax = document.getElementById('soTaxable').checked;
    const ppn = isTax ? (dpp * 0.11) : 0;
    const freight = parseFloat(document.getElementById('soFreight').value) || 0;
    const grandTotal = dpp + ppn + freight;

    document.getElementById('txtSubtotal').innerText = numberFormat(subtotal);
    document.getElementById('txtDiscAmt').innerText = `-${numberFormat(discAmt)}`;
    document.getElementById('txtPpn').innerText = numberFormat(ppn);
    document.getElementById('txtFreight').innerText = numberFormat(freight);
    document.getElementById('txtGrandTotal').innerText = numberFormat(grandTotal);

    const kurs = document.getElementById('soKurs').value;
    document.getElementById('txtTerbilang').innerText = terbilang(grandTotal, kurs);
}

// -------------------------------------------------------------------------
// SIMPAN SALES ORDER (SAVE AJAX)
// -------------------------------------------------------------------------
async function saveSo() {
    if (guardEdit()) return;

    const buyer = document.getElementById('soBuyer').value;
    if (!buyer) {
        Swal.fire('Peringatan', 'Silakan pilih Pelanggan / Buyer terlebih dahulu.', 'warning');
        return;
    }
    const soNo = document.getElementById('soNo').value.trim();
    if (!soNo) {
        Swal.fire('Peringatan', 'Nomor Sales Order (SO) tidak boleh kosong.', 'warning');
        return;
    }

    const items = [];
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        const kd = tr.querySelector('.it-kd').value.trim();
        const ur = tr.querySelector('.it-uraian').value.trim();
        if (kd || ur) {
            items.push({
                kode_barang: kd,
                uraian: ur,
                qty: parseFloat(tr.querySelector('.it-qty').value) || 0,
                sat: tr.querySelector('.it-sat').value.trim() || 'PCE',
                price: parseFloat(tr.querySelector('.it-price').value) || 0,
                disc: parseFloat(tr.querySelector('.it-disc').value) || 0,
                amount: parseFloat(tr.querySelector('.it-amount').value) || 0,
                color_cd: tr.querySelector('.it-color').value.trim(),
                size_cd: tr.querySelector('.it-size').value.trim(),
                note: tr.querySelector('.it-note').value.trim()
            });
        }
    });

    if (items.length === 0) {
        Swal.fire('Peringatan', 'Masukkan minimal 1 baris rincian barang yang dipesan.', 'warning');
        return;
    }

    Swal.fire({
        title: 'Menyimpan Sales Order...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const payload = {
        id: document.getElementById('soId').value,
        header: {
            so_no: soNo,
            so_dt: document.getElementById('soDt').value,
            exp_dt: document.getElementById('soExpDt').value,
            po_no: document.getElementById('soPoNo').value.trim(),
            po_dt: document.getElementById('soPoDt').value,
            vendor: buyer,
            shipto: document.getElementById('soShipto').value.trim(),
            term: document.getElementById('soTerm').value.trim(),
            ship: document.getElementById('soShip').value,
            vendoristaxable: document.getElementById('soTaxable').checked ? 'Y' : 'N',
            kurs: document.getElementById('soKurs').value,
            rate: parseFloat(document.getElementById('soRate').value) || 1,
            note: document.getElementById('soNote').value.trim(),
            disc_persen: parseFloat(document.getElementById('soDiscPersen').value) || 0,
            disc_amt: parseNumber(document.getElementById('txtDiscAmt').innerText.replace('-', '')),
            subtotal: parseNumber(document.getElementById('txtSubtotal').innerText),
            ppn: parseNumber(document.getElementById('txtPpn').innerText),
            est_freight: parseFloat(document.getElementById('soFreight').value) || 0,
            total_order: parseNumber(document.getElementById('txtGrandTotal').innerText),
            status: document.getElementById('soStatus').value
        },
        details: items,
        cmp_id: CURRENT_CMP_ID
    };

    try {
        const res = await fetch('sales_order.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        Swal.close();

        if (json.status !== 'OK') throw new Error(json.message);

        document.getElementById('soId').value = json.id;
        document.getElementById('soNo').value = json.so_no;

        Swal.fire({
            icon: 'success',
            title: 'Tersimpan!',
            text: json.message,
            timer: 1500,
            showConfirmButton: false
        });
    } catch (e) {
        Swal.fire('Gagal Menyimpan', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// HAPUS SALES ORDER (DELETE)
// -------------------------------------------------------------------------
async function deleteSo() {
    const id = document.getElementById('soId').value;
    if (!id) {
        Swal.fire('Informasi', 'Belum ada dokumen Sales Order yang dipilih.', 'info');
        return;
    }

    const ask = await Swal.fire({
        icon: 'warning',
        title: 'Hapus Sales Order?',
        text: 'Dokumen ini akan dihapus permanen. Lanjutkan?',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: 'var(--danger)'
    });
    if (!ask.isConfirmed) return;

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('cmp_id', CURRENT_CMP_ID);

        const res = await fetch('sales_order.php?action=delete', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1200, showConfirmButton: false });
        newSo();
    } catch (e) {
        Swal.fire('Gagal Menghapus', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// LOAD DETAIL SALES ORDER (EDIT MODE)
// -------------------------------------------------------------------------
async function loadSoDetail(id) {
    try {
        Swal.fire({ title: 'Memuat detail SO...', didOpen: () => Swal.showLoading() });
        const res = await fetch(`sales_order.php?action=get_so&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        Swal.close();
        if (json.status !== 'OK') throw new Error(json.message);

        const h = json.header;
        document.getElementById('soId').value = h.id;
        document.getElementById('soNo').value = h.so_no;
        document.getElementById('soDt').value = h.so_dt || '';
        document.getElementById('soExpDt').value = h.exp_dt || '';
        document.getElementById('soPoNo').value = h.po_no || '';
        document.getElementById('soPoDt').value = h.po_dt || '';
        document.getElementById('soBuyer').value = h.vendor || '';
        document.getElementById('soShipto').value = h.shipto || '';
        document.getElementById('soTerm').value = h.term || 'COD';
        document.getElementById('soShip').value = h.ship || 'TRUCK';
        document.getElementById('soKurs').value = h.kurs || 'IDR';
        document.getElementById('soRate').value = h.rate || '1.0000';
        document.getElementById('soTaxable').checked = (h.vendoristaxable !== 'N');
        document.getElementById('soDiscPersen').value = h.disc_persen || '0.00';
        document.getElementById('soFreight').value = h.est_freight || '0.00';
        document.getElementById('soNote').value = h.note || '';

        const isLockedDoc = (h.has_do === true || h.status === '2');
        document.getElementById('soIsLock').value = isLockedDoc ? 'Y' : 'N';
        document.getElementById('soLock').style.display = isLockedDoc ? 'flex' : 'none';
        updateStatusBadge(h.status);

        // Render baris rincian
        const tbody = document.getElementById('itemsBody');
        tbody.innerHTML = '';
        if (json.items && json.items.length > 0) {
            json.items.forEach(it => addItemRow(it));
        } else {
            addItemRow();
        }

        calcSoSummary();
        switchPage('pageEntry');
    } catch (e) {
        Swal.fire('Error', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// BROWSE TAB: DAFTAR DOKUMEN & KPI
// -------------------------------------------------------------------------
async function loadBrowseData(page = 1) {
    currentBrowsePage = page;
    const tbody = document.getElementById('browseBody');
    tbody.innerHTML = '<tr><td colspan="14" class="text-center" style="padding:25px;">Memuat data Sales Order...</td></tr>';

    try {
        const start = document.getElementById('bStartDate').value;
        const end   = document.getElementById('bEndDate').value;
        const st    = document.getElementById('bStatus').value;
        const q     = document.getElementById('bSearch').value;

        const res = await fetch(`sales_order.php?action=list&page=${page}&limit=${currentBrowseLimit}&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}&status=${encodeURIComponent(st)}&search=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        // Update KPI
        const k = json.kpi;
        if (k) {
            document.getElementById('kpiTotalSo').innerText = `${k.total_so} SO`;
            document.getElementById('kpiOpenSo').innerText = `${k.open_so} SO`;
            document.getElementById('kpiPartialSo').innerText = `${k.partial_so} SO`;
            document.getElementById('kpiNilaiBlnIni').innerText = `Rp ${numberFormat(k.nilai_bln_ini)}`;
        }

        const rows = json.data || [];
        document.getElementById('txtBrowseCount').innerText = `Menampilkan ${rows.length} dari ${json.total} dokumen Sales Order`;
        document.getElementById('txtBrowsePage').innerText = page;

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="14" class="text-center" style="padding:25px; color:var(--text-muted);">Tidak ada dokumen Sales Order yang cocok.</td></tr>';
            return;
        }

        let html = '';
        rows.forEach((r, idx) => {
            const rowNum = (page - 1) * currentBrowseLimit + idx + 1;
            let stBadge = '<span class="ant-badge" style="background:#ecfdf5; color:#059669;">Open</span>';
            if (r.status === '2') stBadge = '<span class="ant-badge" style="background:#f1f5f9; color:#475569;">Closed</span>';
            else if (r.status === '1') stBadge = '<span class="ant-badge" style="background:#e0f2fe; color:#0369a1;">Parsial</span>';

            const pct = r.delivery_pct || 0;
            const progressColor = (pct >= 100) ? '#059669' : (pct > 0 ? '#0284c7' : '#9ca3af');

            html += `<tr ondblclick="loadSoDetail('${r.id}')" onclick="selectRow(this)">
                <td class="text-center">${rowNum}</td>
                <td><b style="color:var(--primary);">${escapeHtml(r.so_no)}</b></td>
                <td>${r.so_dt_fmt}</td>
                <td>${r.exp_dt_fmt}</td>
                <td><b>${escapeHtml(r.po_no || '-')}</b></td>
                <td>${escapeHtml(r.buyer_nama)}</td>
                <td class="text-center">${escapeHtml(r.term || 'COD')}</td>
                <td class="text-center">${escapeHtml(r.kurs)}</td>
                <td class="text-center"><span class="ant-badge" style="background:#f1f5f9; color:#475569;">${r.item_count}</span></td>
                <td class="text-right" style="font-weight:700;">${r.total_fmt}</td>
                <td class="text-center">
                    <div style="font-size:8.5px; font-weight:700; color:${progressColor};">${pct}%</div>
                    <div style="width:100%; height:3px; background:#e5e7eb; border-radius:2px; overflow:hidden;">
                        <div style="width:${pct}%; height:100%; background:${progressColor};"></div>
                    </div>
                </td>
                <td class="text-center">${stBadge}</td>
                <td>${escapeHtml(r.note || '-')}</td>
                <td class="text-center">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="loadSoDetail('${r.id}')" title="Edit SO"><i class="fa-solid fa-pen-to-square"></i></button>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="14" class="text-center" style="padding:25px; color:var(--danger);">${e.message}</td></tr>`;
    }
}

function navBrowsePage(dir) {
    const next = currentBrowsePage + dir;
    if (next >= 1) loadBrowseData(next);
}

// -------------------------------------------------------------------------
// TRACEABILITY TAB
// -------------------------------------------------------------------------
async function reloadTrace() {
    const id = document.getElementById('soId').value;
    if (!id) {
        document.getElementById('traceSoNo').innerText = '-';
        document.getElementById('traceBuyerName').innerText = '-';
        document.getElementById('traceItemsBody').innerHTML = '<tr><td colspan="7" class="text-center" style="padding:15px; color:var(--text-muted);">Buka atau pilih dokumen SO terlebih dahulu di Tab 1.</td></tr>';
        document.getElementById('traceDoBody').innerHTML = '<tr><td colspan="8" class="text-center" style="padding:12px; color:var(--text-muted);">Belum ada data pengiriman.</td></tr>';
        document.getElementById('traceSiBody').innerHTML = '<tr><td colspan="7" class="text-center" style="padding:12px; color:var(--text-muted);">Belum ada data penagihan faktur.</td></tr>';
        return;
    }

    try {
        const res = await fetch(`sales_order.php?action=trace&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        const h = json.header || {};
        document.getElementById('traceSoNo').innerText = h.so_no || '-';
        document.getElementById('traceBuyerName').innerText = h.buyer_nama || '-';

        // 1. Items Trace
        const itBody = document.getElementById('traceItemsBody');
        const items = json.details || [];
        document.getElementById('txtTraceItemCount').innerText = `${items.length} Item`;
        if (items.length === 0) {
            itBody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:15px;">Tidak ada item terdaftar.</td></tr>';
        } else {
            itBody.innerHTML = items.map((it, idx) => {
                const qty = parseFloat(it.qty) || 0;
                const terima = parseFloat(it.terima) || 0;
                const sisa = Math.max(0, qty - terima);
                const pct = (qty > 0) ? min(100, Math.round((terima / qty) * 100)) : 0;
                const color = (pct >= 100) ? '#059669' : (pct > 0 ? '#0284c7' : '#9ca3af');
                return `<tr>
                    <td class="text-center">${idx + 1}</td>
                    <td><b>${escapeHtml(it.kode_barang)}</b></td>
                    <td>${escapeHtml(it.uraian || it.mat_uraian || '')}</td>
                    <td class="text-right" style="font-weight:600;">${numberFormat(qty)} ${escapeHtml(it.sat)}</td>
                    <td class="text-right" style="font-weight:600; color:#0369a1;">${numberFormat(terima)} ${escapeHtml(it.sat)}</td>
                    <td class="text-right" style="font-weight:700; color:#15803d;">${numberFormat(sisa)} ${escapeHtml(it.sat)}</td>
                    <td class="text-center"><span class="ant-badge" style="background:#f1f5f9; color:${color}; font-weight:700;">${pct}%</span></td>
                </tr>`;
            }).join('');
        }

        // 2. DO List Trace
        const doBody = document.getElementById('traceDoBody');
        const dos = json.do_list || [];
        if (dos.length === 0) {
            doBody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:12px; color:var(--text-muted);">Belum ada Surat Jalan / Delivery Order (DO) untuk pesanan ini.</td></tr>';
        } else {
            doBody.innerHTML = dos.map(d => `<tr>
                <td><b style="color:#0ea5e9;">${escapeHtml(d.no_do)}</b></td>
                <td>${d.tgl_do ? d.tgl_do.substr(0,10) : '-'}</td>
                <td><b>${escapeHtml(d.no_sj || '-')}</b></td>
                <td>${escapeHtml(d.kode_barang)}</td>
                <td class="text-right" style="font-weight:600;">${numberFormat(d.qty)}</td>
                <td class="text-center">${escapeHtml(d.sat)}</td>
                <td>${d.bc ? `<span class="ant-badge" style="background:#e0f2fe; color:#0369a1;">BC ${escapeHtml(d.bc)}</span>` : '-'}</td>
                <td>${escapeHtml(d.no_bc || '-')}</td>
            </tr>`).join('');
        }

        // 3. SI List Trace
        const siBody = document.getElementById('traceSiBody');
        const sis = json.si_list || [];
        if (sis.length === 0) {
            siBody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:12px; color:var(--text-muted);">Belum ada Faktur Penjualan (SI) yang diterbitkan.</td></tr>';
        } else {
            siBody.innerHTML = sis.map(s => `<tr>
                <td><b style="color:#8b5cf6;">${escapeHtml(s.si_no)}</b></td>
                <td>${s.si_dt ? s.si_dt.substr(0,10) : '-'}</td>
                <td class="text-right" style="font-weight:700;">Rp ${numberFormat(s.total_order)}</td>
                <td class="text-right" style="color:#059669;">Rp ${numberFormat(s.terbayar)}</td>
                <td class="text-center"><span class="ant-badge" style="background:#ecfdf5; color:#059669;">Lunas</span></td>
                <td>${s.bc ? `<span class="ant-badge" style="background:#fef3c7; color:#d97706;">BC ${escapeHtml(s.bc)}</span>` : '-'}</td>
                <td><code style="font-size:9px;">${escapeHtml(s.no_aju || '-')}</code></td>
            </tr>`).join('');
        }
    } catch (e) {
        console.error('Gagal reload trace:', e);
    }
}

// -------------------------------------------------------------------------
// KATALOG BARANG POPUP
// -------------------------------------------------------------------------
function triggerRowMaterialPicker(btn) {
    currentActiveTargetInput = btn.closest('tr');
    openMaterialPicker();
}

function openMaterialPicker() {
    document.getElementById('modalMaterial').style.display = 'flex';
    searchMaterials();
}

function closeMaterialPicker() {
    document.getElementById('modalMaterial').style.display = 'none';
}

function setKlas(klas) {
    currentMaterialKlas = klas;
    document.querySelectorAll('.klas-btn').forEach(b => b.classList.toggle('active', b.dataset.klas === klas));
    searchMaterials();
}

async function searchMaterials() {
    const q = document.getElementById('materialSearch').value.trim();
    const tbody = document.getElementById('materialBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="padding:15px;">Mencari barang...</td></tr>';

    try {
        const res = await fetch(`sales_order.php?action=search_materials&klas=${encodeURIComponent(currentMaterialKlas)}&q=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        const items = json.data || [];
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="padding:15px; color:var(--text-muted);">Tidak ada barang yang cocok.</td></tr>';
            return;
        }

        tbody.innerHTML = items.map(it => `<tr onclick="pickMaterial(${JSON.stringify(it).replace(/"/g, '&quot;')})" style="cursor:pointer;">
            <td><b style="color:var(--primary);">${escapeHtml(it.kode_barang)}</b></td>
            <td>${escapeHtml(it.uraian)}</td>
            <td><span class="ant-badge" style="background:#f1f5f9; color:#475569;">${escapeHtml(it.satuan)}</span></td>
            <td class="text-right" style="font-weight:600;">${it.harga_fmt}</td>
            <td>${escapeHtml(it.klas)}</td>
        </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center" style="color:var(--danger);">${e.message}</td></tr>`;
    }
}

function pickMaterial(it) {
    if (currentActiveTargetInput) {
        currentActiveTargetInput.querySelector('.it-kd').value = it.kode_barang;
        currentActiveTargetInput.querySelector('.it-uraian').value = it.uraian;
        currentActiveTargetInput.querySelector('.it-sat').value = it.satuan || 'PCE';
        if (parseFloat(it.harga_satuan) > 0) {
            currentActiveTargetInput.querySelector('.it-price').value = it.harga_satuan;
        }
        calcItemRow(currentActiveTargetInput.querySelector('.it-qty'));
    } else {
        addItemRow({
            kode_barang: it.kode_barang,
            uraian: it.uraian,
            sat: it.satuan || 'PCE',
            price: it.harga_satuan || 0,
            qty: 1
        });
    }

    if (!document.getElementById('chkMaterialMulti').checked) {
        closeMaterialPicker();
    }
}

// -------------------------------------------------------------------------
// PRINT & EXCEL UTILITIES
// -------------------------------------------------------------------------
function printSo() {
    const id = document.getElementById('soId').value;
    if (!id) {
        Swal.fire('Informasi', 'Pilih atau simpan dokumen Sales Order terlebih dahulu.', 'info');
        return;
    }
    window.open(`sales_order.php?action=print&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function toggleExcelMenu() {
    const m = document.getElementById('excelDrop');
    m.style.display = (m.style.display === 'block') ? 'none' : 'block';
}

function exportExcelSheetJS() {
    const tbl = document.getElementById('tblItems');
    if (!tbl) return;
    try {
        const wb = XLSX.utils.table_to_book(tbl, { sheet: "Sales_Order_Items" });
        const soNo = document.getElementById('soNo').value || 'SO';
        XLSX.writeFile(wb, `${soNo}_Items_${Date.now()}.xlsx`);
    } catch (e) {
        Swal.fire('Error Export', e.message, 'error');
    }
}

function exportBrowseExcel() {
    const tbl = document.getElementById('tblBrowse');
    if (!tbl) return;
    try {
        const wb = XLSX.utils.table_to_book(tbl, { sheet: "Browse_SO" });
        XLSX.writeFile(wb, `Daftar_Sales_Order_${Date.now()}.xlsx`);
    } catch (e) {
        Swal.fire('Error Export', e.message, 'error');
    }
}

function selectRow(tr) {
    tr.parentElement.querySelectorAll('tr').forEach(r => r.classList.remove('selected'));
    tr.classList.add('selected');
}

function openModule(mod) {
    if (window.parent && window.parent.switchMainTab) {
        window.parent.switchMainTab(mod);
    } else {
        const map = {
            'sale_do': '/erp/delivery_order.php',
            'sale_si': '/erp/sales_invoice.php',
            'sale_so_import': '/erp/import_so_xls.php',
            'erp_buyer_supplier': '/erp/buyer_supplier.php'
        };
        window.open(`${map[mod]}?cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
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

function parseNumber(str) {
    if (!str) return 0;
    return parseFloat(str.replace(/,/g, '')) || 0;
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
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
</script>

</body>
</html>

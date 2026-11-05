<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Import Sales Order dari Excel
 * ============================================================================
 * Hasil Porting Lengkap dari PowerBuilder TPB-ERP:
 * - w_so_xls.srw (Window Import Sales Order dari Excel / Clipboard)
 * - d_so_xls.srd (DataWindow Staging Rincian Item SO)
 * - d_so_xls_hdr.srd (DataWindow Grouping Header SO per Buyer & PO)
 *
 * Mengadopsi 100% Ant Design Compact Desktop 9.5px Standard Seragam ERP Hub
 * Standar Warna Badge & Accent: Emerald Green (#10b981 / #059669)
 *
 * Alur Kerja:
 * 1. Upload File Excel (.xlsx/.xls/.csv) via SheetJS atau Paste Tab-Delimited dari Clipboard
 * 2. Staging Grid: Validasi real-time Master Buyer (bc_bs) dan Master Material (bc_material)
 * 3. Fitur Cerdas: Auto Lookup Harga Satuan & Satuan Master jika kosong di Excel
 * 4. Generate Sales Order Otomatis: Membentuk bc_so_hdr dan bc_so_dtl dengan nomor urut otomatis
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

$action = trim($_REQUEST['action'] ?? '');

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Tabel Staging bc_so_xls & bc_so_xls_hdr Siap
// -------------------------------------------------------------------------
function ensureXlsTables($koneksi) {
    try {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_so_xls` (
            `customer` varchar(50) NOT NULL,
            `order_dt` date DEFAULT NULL,
            `deliv_dt` date DEFAULT NULL,
            `po` varchar(50) NOT NULL,
            `po_seq` int(11) NOT NULL,
            `material` varchar(50) DEFAULT NULL,
            `qty` decimal(15,4) DEFAULT 0.0000,
            `color_cd` varchar(90) DEFAULT '',
            `size_cd` varchar(90) DEFAULT '',
            `so_no` varchar(50) DEFAULT '',
            `price` decimal(15,4) DEFAULT 0.0000,
            `kurs` varchar(10) DEFAULT 'IDR',
            `set_by` varchar(50) DEFAULT NULL,
            `nama_cust` varchar(150) DEFAULT NULL,
            `nama_mat` varchar(255) DEFAULT NULL,
            `sat` varchar(20) DEFAULT NULL,
            `cmp_id` varchar(30) NOT NULL DEFAULT 'SNU',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`customer`, `po`, `po_seq`, `cmp_id`),
            KEY `idx_cmp_so_xls` (`cmp_id`, `customer`, `po`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_so_xls_hdr` (
            `customer` varchar(50) NOT NULL,
            `order_dt` date DEFAULT NULL,
            `deliv_dt` date DEFAULT NULL,
            `po` varchar(50) NOT NULL,
            `kurs` varchar(10) DEFAULT 'IDR',
            `id` varchar(50) DEFAULT NULL,
            `so_no` varchar(50) DEFAULT NULL,
            `subtotal` decimal(15,4) DEFAULT 0.0000,
            `ppn` decimal(15,4) DEFAULT 0.0000,
            `total_order` decimal(15,4) DEFAULT 0.0000,
            `set_by` varchar(50) DEFAULT NULL,
            `cmp_id` varchar(30) NOT NULL DEFAULT 'SNU',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`customer`, `po`, `cmp_id`),
            KEY `idx_cmp_so_hdr` (`cmp_id`, `so_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) {}
}
ensureXlsTables($koneksi);

// -------------------------------------------------------------------------
// BACKEND API 1: KPI RINGKASAN STAGING & HISTORI SO
// -------------------------------------------------------------------------
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cmpClause = "(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)";

        // Staging count & qty
        $resStaging = $koneksi->query("SELECT COUNT(*) AS c, SUM(IFNULL(qty, 0)) AS total_qty, SUM(IFNULL(qty, 0) * IFNULL(price, 0)) AS total_amt FROM bc_so_xls WHERE {$cmpClause}");
        $rowStaging = ($resStaging && $r = $resStaging->fetch_assoc()) ? $r : ['c' => 0, 'total_qty' => 0, 'total_amt' => 0];

        // Riwayat SO dari bc_so_xls_hdr
        $resHdr = $koneksi->query("SELECT COUNT(*) AS c, SUM(IFNULL(total_order, 0)) AS total_val FROM bc_so_xls_hdr WHERE {$cmpClause}");
        $rowHdr = ($resHdr && $r = $resHdr->fetch_assoc()) ? $r : ['c' => 0, 'total_val' => 0];

        // Validasi count di staging
        $sqlVal = "SELECT 
            SUM(CASE WHEN b.KODE IS NULL OR m.KODE_BARANG IS NULL THEN 1 ELSE 0 END) AS invalid_rows
            FROM bc_so_xls x
            LEFT JOIN bc_bs b ON (b.KODE = x.customer AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
            LEFT JOIN bc_material m ON (m.KODE_BARANG = x.material AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
            WHERE (x.cmp_id = '{$escCmp}' OR x.cmp_id = '' OR x.cmp_id IS NULL)";
        $resVal = $koneksi->query($sqlVal);
        $invalidRows = ($resVal && $r = $resVal->fetch_assoc()) ? (int)$r['invalid_rows'] : 0;

        echo json_encode([
            'status' => 'OK',
            'kpi' => [
                'staging_rows'       => (int)$rowStaging['c'],
                'staging_qty'        => (float)$rowStaging['total_qty'],
                'staging_amount'     => (float)$rowStaging['total_amt'],
                'invalid_rows'       => $invalidRows,
                'total_imported_so'  => (int)$rowHdr['c'],
                'total_imported_val' => (float)$rowHdr['total_val']
            ]
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// BACKEND API 2: UNDUH TEMPLATE STANDAR CSV
// -------------------------------------------------------------------------
if ($action === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="so_import_template.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['customer', 'order_dt', 'deliv_dt', 'po', 'po_seq', 'material', 'qty', 'sat', 'price', 'kurs', 'color_cd', 'size_cd']);
    fputcsv($output, ['B0001', date('Y-m-d'), date('Y-m-d', strtotime('+14 days')), 'PO-BUYER-2026/001', '1', 'RM001', '500', 'YDS', '25000', 'IDR', 'BLACK', 'L']);
    fputcsv($output, ['B0001', date('Y-m-d'), date('Y-m-d', strtotime('+14 days')), 'PO-BUYER-2026/001', '2', 'RM002', '350', 'YDS', '32000', 'IDR', 'WHITE', 'M']);
    fclose($output);
    exit;
}

// -------------------------------------------------------------------------
// BACKEND API 3: GET DATA STAGING AREA (bc_so_xls) LENGKAP VALIDASI MASTER
// -------------------------------------------------------------------------
if ($action === 'get_staging') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $filterCust = trim($_GET['customer'] ?? '');
        $filterStatus = trim($_GET['status_filter'] ?? 'all'); // all, valid, invalid
        $search = trim($_GET['search'] ?? '');

        $cmpClause = "(x.cmp_id = '{$escCmp}' OR x.cmp_id = '' OR x.cmp_id IS NULL)";
        $where = [$cmpClause];

        if (!empty($filterCust)) {
            $escCust = $koneksi->real_escape_string($filterCust);
            $where[] = "x.customer = '{$escCust}'";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(x.customer LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%' OR x.po LIKE '%{$escS}%' OR x.material LIKE '%{$escS}%' OR m.URAIAN LIKE '%{$escS}%')";
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT 
            x.customer,
            x.order_dt,
            x.deliv_dt,
            x.po,
            x.po_seq,
            x.material,
            x.qty,
            x.sat,
            x.price,
            (IFNULL(x.qty, 0) * IFNULL(x.price, 0)) AS amount,
            x.kurs,
            x.color_cd,
            x.size_cd,
            x.so_no,
            IFNULL(b.NAMA, x.nama_cust) AS nama_cust,
            IFNULL(m.URAIAN, x.nama_mat) AS nama_mat,
            m.KODE_SATUAN AS master_sat,
            m.UNIT_PRICE AS master_price,
            CASE WHEN b.KODE IS NOT NULL THEN 1 ELSE 0 END AS cust_valid,
            CASE WHEN m.KODE_BARANG IS NOT NULL THEN 1 ELSE 0 END AS mat_valid
            FROM bc_so_xls x
            LEFT JOIN bc_bs b ON (b.KODE = x.customer AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
            LEFT JOIN bc_material m ON (m.KODE_BARANG = x.material AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
            WHERE {$whereSql}
            ORDER BY x.customer ASC, x.po ASC, x.po_seq ASC";

        $res = $koneksi->query($sql);
        $data = [];
        $totalQty = 0;
        $totalAmount = 0;
        $invalidCount = 0;

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $isValid = ($r['cust_valid'] == 1 && $r['mat_valid'] == 1);
                if (!$isValid) $invalidCount++;

                if ($filterStatus === 'valid' && !$isValid) continue;
                if ($filterStatus === 'invalid' && $isValid) continue;

                $totalQty += (float)$r['qty'];
                $totalAmount += (float)$r['amount'];

                $r['qty_fmt']    = number_format((float)$r['qty'], 2, '.', ',');
                $r['price_fmt']  = number_format((float)$r['price'], 2, '.', ',');
                $r['amount_fmt'] = number_format((float)$r['amount'], 2, '.', ',');
                $r['order_dt_fmt'] = !empty($r['order_dt']) ? date('d/m/Y', strtotime($r['order_dt'])) : '-';
                $r['deliv_dt_fmt'] = !empty($r['deliv_dt']) ? date('d/m/Y', strtotime($r['deliv_dt'])) : '-';
                $data[] = $r;
            }
        }

        echo json_encode([
            'status'        => 'OK',
            'count'         => count($data),
            'total_qty'     => $totalQty,
            'total_amount'  => $totalAmount,
            'invalid_count' => $invalidCount,
            'data'          => $data
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// BACKEND API 4: PARSE & SIMPAN DATA KE STAGING (EXCEL CLIPBOARD / ARRAY)
// -------------------------------------------------------------------------
if ($action === 'paste_import') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $mode       = trim($_POST['mode'] ?? 'replace'); // replace atau append
        $autoPrice  = !empty($_POST['auto_price']) && $_POST['auto_price'] === '1';
        $autoSat    = !empty($_POST['auto_sat']) && $_POST['auto_sat'] === '1';
        $rawText    = trim($_POST['raw_text'] ?? '');
        $jsonData   = trim($_POST['json_data'] ?? '');

        $rowsToInsert = [];

        // 1. Jika dikirim via JSON (dari upload file Excel SheetJS)
        if (!empty($jsonData)) {
            $parsed = json_decode($jsonData, true);
            if (is_array($parsed) && count($parsed) > 0) {
                $rowsToInsert = $parsed;
            }
        }

        // 2. Jika dikirim via Teks Mentah (Paste Clipboard)
        if (empty($rowsToInsert) && !empty($rawText)) {
            $lines = explode("\n", str_replace("\r", "", $rawText));
            foreach ($lines as $lineIndex => $line) {
                $line = trim($line);
                if (empty($line)) continue;

                if (strpos($line, "\t") !== false) {
                    $cols = explode("\t", $line);
                } elseif (strpos($line, ";") !== false) {
                    $cols = explode(";", $line);
                } else {
                    $cols = str_getcsv($line);
                }

                // Abaikan header jika baris pertama memuat kata customer / cust
                if ($lineIndex === 0 && (stripos($cols[0] ?? '', 'customer') !== false || stripos($cols[0] ?? '', 'cust') !== false)) {
                    continue;
                }

                $cust     = trim($cols[0] ?? '');
                $orderDt  = trim($cols[1] ?? date('Y-m-d'));
                $delivDt  = trim($cols[2] ?? date('Y-m-d', strtotime('+14 days')));
                $po       = trim($cols[3] ?? '');
                $poSeq    = intval($cols[4] ?? ($lineIndex + 1));
                $material = trim($cols[5] ?? '');
                $qty      = floatval(str_replace(',', '.', str_replace('.', '', trim($cols[6] ?? '0'))));
                if ($qty == 0 && isset($cols[6])) $qty = floatval(trim($cols[6]));
                $sat      = trim($cols[7] ?? 'PCE');
                $price    = floatval(str_replace(',', '', trim($cols[8] ?? '0')));
                $kurs     = strtoupper(trim($cols[9] ?? 'IDR'));
                if (empty($kurs)) $kurs = 'IDR';
                $color    = trim($cols[10] ?? '');
                $size     = trim($cols[11] ?? '');

                if (empty($cust) || empty($po) || empty($material)) continue;

                $rowsToInsert[] = [
                    'customer' => $cust,
                    'order_dt' => $orderDt,
                    'deliv_dt' => $delivDt,
                    'po'       => $po,
                    'po_seq'   => $poSeq,
                    'material' => $material,
                    'qty'      => $qty,
                    'sat'      => $sat,
                    'price'    => $price,
                    'kurs'     => $kurs,
                    'color_cd' => $color,
                    'size_cd'  => $size
                ];
            }
        }

        if (empty($rowsToInsert)) {
            throw new Exception('Tidak ada baris data valid yang ditemukan untuk diproses.');
        }

        $koneksi->begin_transaction();

        if ($mode === 'replace') {
            $koneksi->query("DELETE FROM bc_so_xls WHERE cmp_id = '{$escCmp}'");
        }

        $imported = 0;
        $setby = "{$currentUser} - " . date('Ymd Hi');

        $stmt = $koneksi->prepare("INSERT INTO bc_so_xls (
            customer, order_dt, deliv_dt, po, po_seq, material, qty, sat, price, kurs, color_cd, size_cd, set_by, cmp_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            order_dt = VALUES(order_dt),
            deliv_dt = VALUES(deliv_dt),
            material = VALUES(material),
            qty      = VALUES(qty),
            sat      = VALUES(sat),
            price    = VALUES(price),
            kurs     = VALUES(kurs),
            color_cd = VALUES(color_cd),
            size_cd  = VALUES(size_cd),
            set_by   = VALUES(set_by)");

        foreach ($rowsToInsert as $idx => $r) {
            $cust    = trim($r['customer'] ?? '');
            $orderDt = !empty($r['order_dt']) ? date('Y-m-d', strtotime(str_replace('/', '-', $r['order_dt']))) : date('Y-m-d');
            $delivDt = !empty($r['deliv_dt']) ? date('Y-m-d', strtotime(str_replace('/', '-', $r['deliv_dt']))) : date('Y-m-d', strtotime('+14 days'));
            $po      = trim($r['po'] ?? '');
            $poSeq   = intval($r['po_seq'] ?? ($idx + 1));
            $mat     = trim($r['material'] ?? '');
            $qty     = (float)($r['qty'] ?? 0);
            $sat     = trim($r['sat'] ?? 'PCE');
            $price   = (float)($r['price'] ?? 0);
            $kurs    = strtoupper(trim($r['kurs'] ?? 'IDR'));
            if (empty($kurs)) $kurs = 'IDR';
            $color   = trim($r['color_cd'] ?? '');
            $size    = trim($r['size_cd'] ?? '');

            if (empty($cust) || empty($po) || empty($mat)) continue;

            // Fitur Cerdas: Auto Lookup Satuan & Harga dari bc_material jika kosong
            if (($autoSat || empty($sat) || $sat === 'UNIT') || ($autoPrice || $price <= 0)) {
                $escMat = $koneksi->real_escape_string($mat);
                $mQ = $koneksi->query("SELECT KODE_SATUAN, UNIT_PRICE FROM bc_material WHERE KODE_BARANG = '{$escMat}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
                if ($mQ && $m = $mQ->fetch_assoc()) {
                    if (($autoSat || empty($sat) || $sat === 'UNIT') && !empty($m['KODE_SATUAN'])) {
                        $sat = $m['KODE_SATUAN'];
                    }
                    if (($autoPrice || $price <= 0) && (float)$m['UNIT_PRICE'] > 0) {
                        $price = (float)$m['UNIT_PRICE'];
                    }
                }
            }

            $stmt->bind_param("ssssisdsdsssss",
                $cust, $orderDt, $delivDt, $po, $poSeq, $mat, $qty, $sat, $price, $kurs, $color, $size, $setby, $sessionCmpId
            );
            if (!$stmt->execute()) {
                throw new Exception("Gagal simpan baris " . ($idx + 1) . ": " . $stmt->error);
            }
            $imported++;
        }
        $stmt->close();

        $koneksi->commit();

        echo json_encode([
            'status'   => 'OK',
            'message'  => "Berhasil memuat {$imported} baris transaksi ke staging area.",
            'imported' => $imported
        ]);
    } catch (\Throwable $ex) {
        if ($koneksi) $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// BACKEND API 5: BERSIHKAN SEMUA DATA STAGING
// -------------------------------------------------------------------------
if ($action === 'clear_staging') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $koneksi->query("DELETE FROM bc_so_xls WHERE cmp_id = '{$escCmp}'");
        echo json_encode(['status' => 'OK', 'message' => 'Staging area berhasil dibersihkan.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// BACKEND API 6: HAPUS BARIS TERTENTU DARI STAGING
// -------------------------------------------------------------------------
if ($action === 'delete_row') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cust  = trim($_POST['customer'] ?? '');
        $po    = trim($_POST['po'] ?? '');
        $seq   = intval($_POST['po_seq'] ?? 0);

        if (empty($cust) || empty($po)) throw new Exception('Parameter baris tidak lengkap.');
        $escCust = $koneksi->real_escape_string($cust);
        $escPo   = $koneksi->real_escape_string($po);

        $koneksi->query("DELETE FROM bc_so_xls WHERE customer = '{$escCust}' AND po = '{$escPo}' AND po_seq = {$seq} AND cmp_id = '{$escCmp}'");
        echo json_encode(['status' => 'OK', 'message' => 'Baris data berhasil dihapus dari staging.']);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// BACKEND API 7: GENERATE REAL SALES ORDER (bc_so_hdr & bc_so_dtl)
// -------------------------------------------------------------------------
if ($action === 'generate_so') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $soStatus = trim($_POST['so_status'] ?? '1'); // '1' = Open, '0' = Draft
        $cmpClause = "(x.cmp_id = '{$escCmp}' OR x.cmp_id = '' OR x.cmp_id IS NULL)";

        // 1. Kelompokkan per Buyer & PO
        $grpSql = "SELECT x.customer, x.po, MIN(x.order_dt) as order_dt, MAX(x.deliv_dt) as deliv_dt, x.kurs,
                          SUM(IFNULL(x.qty, 0) * IFNULL(x.price, 0)) as subtotal
                   FROM bc_so_xls x
                   WHERE {$cmpClause}
                   GROUP BY x.customer, x.po, x.kurs";
        $grpRes = $koneksi->query($grpSql);

        if (!$grpRes || $grpRes->num_rows === 0) {
            throw new Exception('Tidak ada data di staging area untuk digenerate menjadi Sales Order.');
        }

        $koneksi->begin_transaction();

        $generatedList = [];
        $nowStr = date('Ymd Hi');

        while ($grp = $grpRes->fetch_assoc()) {
            $cust       = $grp['customer'];
            $po         = $grp['po'];
            $kurs       = $grp['kurs'] ?: 'IDR';
            $orderDt    = $grp['order_dt'] ?: date('Y-m-d');
            $delivDt    = $grp['deliv_dt'] ?: date('Y-m-d', strtotime('+14 days'));
            $subtotal   = (float)$grp['subtotal'];
            $ppn        = round($subtotal * 0.11, 2);
            $totalOrder = $subtotal + $ppn;

            // Penomoran Urut SO: SO/YYYYMM/XXXX
            $prefix = "SO/" . date('Ym', strtotime($orderDt)) . "/";
            $chkMax = $koneksi->query("SELECT MAX(so_no) as max_no FROM bc_so_hdr WHERE so_no LIKE '{$prefix}%' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
            $maxRow = ($chkMax && $r = $chkMax->fetch_assoc()) ? $r['max_no'] : null;
            if ($maxRow) {
                $lastNum = intval(substr($maxRow, strlen($prefix)));
                $soNo = $prefix . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
            } else {
                $soNo = $prefix . "0001";
            }

            $idHdr = md5($sessionCmpId . '_' . $soNo . '_' . uniqid(rand(), true));
            $setby = "{$currentUser} - {$nowStr}";

            $escCust    = $koneksi->real_escape_string($cust);
            $escPo      = $koneksi->real_escape_string($po);
            $escSoNo    = $koneksi->real_escape_string($soNo);
            $escOrderDt = $koneksi->real_escape_string($orderDt);
            $escDelivDt = $koneksi->real_escape_string($delivDt);
            $escKurs    = $koneksi->real_escape_string($kurs);
            $escSetby   = $koneksi->real_escape_string($setby);

            // Ambil Info Rekanan Buyer (Termin, Alamat)
            $top = 'COD';
            $alamat = '';
            $bQ = $koneksi->query("SELECT PAY_TERM, ALAMAT FROM bc_bs WHERE KODE = '{$escCust}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
            if ($bQ && $b = $bQ->fetch_assoc()) {
                if (!empty($b['PAY_TERM'])) $top = $b['PAY_TERM'];
                if (!empty($b['ALAMAT'])) $alamat = $b['ALAMAT'];
            }
            $escTop = $koneksi->real_escape_string($top);
            $escAlamat = $koneksi->real_escape_string($alamat);

            // Insert bc_so_hdr (Identik skema sales_order.php)
            $hdrSql = "INSERT INTO bc_so_hdr (
                id, cmp_id, so_no, so_dt, exp_dt, po_no, po_dt, fob, term, ship,
                vendor, shipto, vendoristaxable, inclusivetax, note,
                subtotal, disc_persen, disc_amt, ppn, est_freight, total_order,
                kurs, rate, set_by, status
            ) VALUES (
                '{$idHdr}', '{$escCmp}', '{$escSoNo}', '{$escOrderDt}', '{$escDelivDt}', '{$escPo}', '{$escOrderDt}', '1', '{$escTop}', 'TRUCK',
                '{$escCust}', '{$escAlamat}', 'Y', 'N', 'Import Otomatis dari Excel PO {$escPo}',
                {$subtotal}, 0.00, 0.00, {$ppn}, 0.00, {$totalOrder},
                '{$escKurs}', 1.00, '{$escSetby}', '{$soStatus}'
            )";
            if (!$koneksi->query($hdrSql)) {
                throw new Exception("Gagal membuat Header SO '{$soNo}': " . $koneksi->error);
            }

            // Simpan Riwayat ke bc_so_xls_hdr (Untuk history porting PB)
            $xlsHdrSql = "INSERT INTO bc_so_xls_hdr (
                customer, order_dt, deliv_dt, po, kurs, id, so_no, subtotal, ppn, total_order, set_by, cmp_id
            ) VALUES (
                '{$escCust}', '{$escOrderDt}', '{$escDelivDt}', '{$escPo}', '{$escKurs}', '{$idHdr}', '{$escSoNo}', {$subtotal}, {$ppn}, {$totalOrder}, '{$escSetby}', '{$escCmp}'
            ) ON DUPLICATE KEY UPDATE 
                id = '{$idHdr}', so_no = '{$escSoNo}', subtotal = {$subtotal}, ppn = {$ppn}, total_order = {$totalOrder}, set_by = '{$escSetby}'";
            $koneksi->query($xlsHdrSql);

            // Insert Detail Items dari bc_so_xls ke bc_so_dtl
            $linesSql = "SELECT x.*, IFNULL(m.URAIAN, x.material) as uraian_mat
                         FROM bc_so_xls x
                         LEFT JOIN bc_material m ON (m.KODE_BARANG = x.material AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                         WHERE x.customer = '{$escCust}' AND x.po = '{$escPo}' AND {$cmpClause}
                         ORDER BY x.po_seq ASC";
            $linesRes = $koneksi->query($linesSql);

            if ($linesRes) {
                $seq = 1;
                while ($ln = $linesRes->fetch_assoc()) {
                    $idDtl  = md5($idHdr . '_' . $seq . '_' . uniqid(rand(), true));
                    $mat    = $koneksi->real_escape_string($ln['material']);
                    $uraian = $koneksi->real_escape_string($ln['uraian_mat']);
                    $qty    = (float)$ln['qty'];
                    $sat    = $koneksi->real_escape_string(!empty($ln['sat']) ? $ln['sat'] : 'PCE');
                    $price  = (float)$ln['price'];
                    $amt    = $qty * $price;
                    $color  = $koneksi->real_escape_string($ln['color_cd'] ?? '');
                    $size   = $koneksi->real_escape_string($ln['size_cd'] ?? '');

                    $dtlSql = "INSERT INTO bc_so_dtl (
                        id, id_hdr, seq, kode_barang, uraian, qty, sat, price, disc, istax, amount, terima, status, setby, color_cd, size_cd, cmp_id
                    ) VALUES (
                        '{$idDtl}', '{$idHdr}', {$seq}, '{$mat}', '{$uraian}', {$qty}, '{$sat}', {$price}, 0.00, 'Y', {$amt}, 0.00, '0', '{$escSetby}', '{$color}', '{$size}', '{$escCmp}'
                    )";
                    if (!$koneksi->query($dtlSql)) {
                        throw new Exception("Gagal insert detail item SO {$soNo} Seq {$seq}: " . $koneksi->error);
                    }
                    $seq++;
                }
            }

            $generatedList[] = [
                'so_no'       => $soNo,
                'customer'    => $cust,
                'po'          => $po,
                'total_order' => $totalOrder
            ];
        }

        // Hapus staging table setelah berhasil digenerate
        $koneksi->query("DELETE FROM bc_so_xls WHERE cmp_id = '{$escCmp}'");

        $koneksi->commit();

        echo json_encode([
            'status'         => 'OK',
            'message'        => "Sukses! " . count($generatedList) . " Sales Order resmi berhasil dibentuk dan disimpan.",
            'generated_list' => $generatedList,
            'count'          => count($generatedList)
        ]);
    } catch (\Throwable $ex) {
        if ($koneksi) $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// DATA REFERENSI FILTER UNTUK FORM UI
// -------------------------------------------------------------------------
$buyerQ = $koneksi->query("SELECT KODE, NAMA FROM bc_bs 
                          WHERE (TIPE = 'B' OR TIPE = 'BUYER' OR TIPE = 'CUSTOMER' OR TIPE IS NULL OR TIPE = '') 
                            AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                          ORDER BY NAMA ASC");
$buyerList = [];
if ($buyerQ) {
    while ($r = $buyerQ->fetch_assoc()) $buyerList[] = $r;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Sales Order dari Excel - <?= htmlspecialchars($activeCmpName) ?></title>
    
    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 Pro / Solid -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- SheetJS Excel Parser & Exporter -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <!-- Ant Design DataGrid CSS -->
    <link rel="stylesheet" href="/assets/ant_grid.css">

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
        .ant-badge-so { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }

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
        .ant-btn-sm { height: 18px; padding: 0 5px; font-size: 8.5px; }

        /* KPI Strip */
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
            transition: all 0.2s;
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
        .kpi-icon-staging { background: #ecfdf5; color: #10b981; }
        .kpi-icon-qty { background: #eff6ff; color: #3b82f6; }
        .kpi-icon-so { background: #fef3c7; color: #d97706; }
        .kpi-icon-val { background: #f5f3ff; color: #8b5cf6; }

        .kpi-content {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .kpi-label { font-size: 8.5px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kpi-val { font-size: 12px; font-weight: 700; color: var(--text-main); white-space: nowrap; }

        /* Dual Input Area (Collapsible Split) */
        .input-panel {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-card);
            flex-shrink: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        @media (max-width: 900px) {
            .input-panel { grid-template-columns: 1fr; }
        }

        .box-step {
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 8px 10px;
            background: var(--bg-card);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .box-title {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 4px;
        }
        .box-title .step-num {
            width: 16px;
            height: 16px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 8.5px;
            font-weight: 700;
        }

        textarea.excel-box {
            width: 100%;
            height: 60px;
            padding: 4px 6px;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 9px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-app);
            color: var(--text-main);
            outline: none;
            resize: vertical;
        }
        textarea.excel-box:focus { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-focus); background: var(--bg-card); }

        .drop-zone {
            border: 1px dashed var(--primary);
            border-radius: 4px;
            padding: 8px;
            text-align: center;
            background: var(--primary-light);
            cursor: pointer;
            transition: all 0.2s;
            font-size: 9.5px;
        }
        .drop-zone:hover { background: #d1fae5; }

        /* Filter Row */
        .filter-row {
            padding: 4px 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-card);
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .filter-group { display: flex; align-items: center; gap: 4px; }
        .filter-label { font-size: 9px; font-weight: 600; color: var(--text-muted); }

        select, input[type="text"] {
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
        select:focus, input:focus { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-focus); }

        /* Grid Wrapper & Table */
        .grid-wrap {
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

        table.dw tbody tr {
            border-bottom: 1px solid var(--border-light);
            cursor: pointer;
        }
        table.dw tbody tr:hover { background: var(--row-hover); }
        table.dw tbody tr.selected { background: var(--row-selected); }
        table.dw tbody tr.row-invalid { background: #fff1f2; }

        table.dw tbody td {
            border-right: 1px solid var(--border-light);
            padding: 2.5px 6px;
            font-size: 9.5px;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .badge-status {
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 8.5px;
            font-weight: 700;
            display: inline-block;
        }
        .badge-valid { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-invalid { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        /* Footer Status Bar */
        .status-bar {
            padding: 3px 10px;
            background: var(--bg-card);
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9px;
            color: var(--text-muted);
            flex-shrink: 0;
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
    </style>
</head>
<body>

<div class="ant-container">
    <div class="ant-card">
        <!-- HEADER PORTAL / TOOLBAR -->
        <div class="ant-card-header">
            <div class="ant-card-title">
                <i class="fa-solid fa-file-excel" style="color:var(--primary); font-size:13px;"></i>
                <span>IMPORT SALES ORDER DARI EXCEL (PORTING w_so_xls)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-so">SO IMPORT</span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="triggerUploadFile()"><i class="fa-solid fa-file-arrow-up"></i> Upload File Excel</button>
                <button type="button" class="ant-btn" onclick="toggleInputPanel()"><i class="fa-solid fa-paste"></i> Paste Clipboard</button>
                <button type="button" class="ant-btn" onclick="downloadTemplate()"><i class="fa-solid fa-download"></i> Unduh Template</button>
                <button type="button" class="ant-btn ant-btn-danger" onclick="clearStaging()"><i class="fa-solid fa-trash-can"></i> Bersihkan Staging</button>
                <button type="button" class="ant-btn" onclick="loadAll()"><i class="fa-solid fa-rotate"></i> Refresh</button>
                <button type="button" class="ant-btn ant-btn-success" id="btnTopGenerate" onclick="generateSalesOrder()" style="font-weight:600;"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Sales Order</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openGuideModal()" title="Panduan Modul"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:12px;"></i></button>
            </div>
        </div>

        <!-- 4-CHIP KPI SUMMARY STRIP -->
        <div class="kpi-strip">
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-staging"><i class="fa-solid fa-table-list"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Baris di Staging</span>
                    <span class="kpi-val" id="kpiStagingRows">0 Baris</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-qty"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Total Qty Produk</span>
                    <span class="kpi-val" id="kpiStagingQty">0.00</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-so"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Riwayat SO Terimport</span>
                    <span class="kpi-val" id="kpiImportedSo">0 Dokumen</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-val"><i class="fa-solid fa-money-bill-wave"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Total Nilai Riwayat SO</span>
                    <span class="kpi-val" id="kpiImportedVal">Rp 0.00</span>
                </div>
            </div>
        </div>

        <!-- DUAL INPUT PANEL (COLLAPSIBLE / TOGGLE) -->
        <div class="input-panel" id="panelInput">
            <!-- BOX 1: UPLOAD FILE EXCEL / CSV VIA SHEETJS -->
            <div class="box-step">
                <div class="box-title">
                    <span class="step-num">1</span>
                    <span>PILIHAN A: UPLOAD BERKAS EXCEL (.XLSX / .XLS / .CSV)</span>
                </div>
                <div class="drop-zone" onclick="triggerUploadFile()" id="dropZone">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:18px; color:var(--primary); margin-bottom:4px;"></i><br>
                    <strong>Klik untuk Pilih Berkas Excel</strong> atau seret & lepas berkas ke sini.<br>
                    <span style="color:var(--text-muted); font-size:8.5px;">Mendukung format Microsoft Excel .xlsx, .xls, dan CSV</span>
                </div>
                <input type="file" id="fileExcelInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelUpload(this)">
                
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:2px;">
                    <div style="display:flex; gap:10px;">
                        <label style="font-size:8.5px; cursor:pointer;"><input type="checkbox" id="chkAutoPrice" checked> Auto Harga Master</label>
                        <label style="font-size:8.5px; cursor:pointer;"><input type="checkbox" id="chkAutoSat" checked> Auto Satuan Master</label>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <label style="font-size:8.5px; cursor:pointer;"><input type="radio" name="radMode" value="replace" checked> Timpa (Replace)</label>
                        <label style="font-size:8.5px; cursor:pointer;"><input type="radio" name="radMode" value="append"> Tambah (Append)</label>
                    </div>
                </div>
            </div>

            <!-- BOX 2: PASTE DARI CLIPBOARD EXCEL (PB importclipboard) -->
            <div class="box-step">
                <div class="box-title">
                    <span class="step-num">2</span>
                    <span>PILIHAN B: PASTE DARI CLIPBOARD (CTRL+C DARI EXCEL -> CTRL+V)</span>
                </div>
                <textarea id="rawExcelText" class="excel-box" placeholder="Pilih baris di Excel, tekan Ctrl+C, lalu paste di sini...
Format: customer | order_dt | deliv_dt | po | po_seq | material | qty | sat | price | kurs | color | size"></textarea>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:8.5px; color:var(--text-muted);">Mendukung separator Tab, Koma, atau Titik Koma.</span>
                    <button type="button" class="ant-btn ant-btn-primary" onclick="submitPasteData()"><i class="fa-solid fa-bolt"></i> Proses ke Staging</button>
                </div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-row">
            <div class="filter-group">
                <span class="filter-label">Customer / Buyer:</span>
                <select id="selBuyer" style="width:180px;" onchange="loadStaging()">
                    <option value="">-- Semua Buyer --</option>
                    <?php foreach ($buyerList as $b): ?>
                        <option value="<?= htmlspecialchars($b['KODE']) ?>"><?= htmlspecialchars($b['KODE']) ?> - <?= htmlspecialchars($b['NAMA']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Status Validasi Master:</span>
                <select id="selStatusValid" style="width:140px;" onchange="loadStaging()">
                    <option value="all">Semua Data</option>
                    <option value="valid">Hanya Yang Valid (Siap SO)</option>
                    <option value="invalid">Hanya Bermasalah (Invalid)</option>
                </select>
            </div>
            <div class="filter-group" style="margin-left:auto;">
                <input type="text" id="txtSearch" placeholder="Cari Buyer, PO, atau Barang..." style="width:190px;" onkeydown="if(event.key==='Enter') loadStaging()">
                <button type="button" class="ant-btn" onclick="loadStaging()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
        </div>

        <!-- MAIN STAGING GRID (d_so_xls) -->
        <div class="grid-wrap">
            <table class="dw" id="tblStaging">
                <thead>
                    <tr>
                        <th style="width:25px; text-align:center;"><input type="checkbox" id="chkAllRows" onchange="toggleCheckAll(this.checked)"></th>
                        <th style="width:30px; text-align:center;">No</th>
                        <th style="width:85px;">Kode Buyer</th>
                        <th>Nama Customer</th>
                        <th style="width:130px;">No. PO Buyer</th>
                        <th style="width:40px; text-align:center;">Seq</th>
                        <th style="width:110px;">Kode Barang</th>
                        <th>Deskripsi Barang</th>
                        <th style="width:75px;" class="text-right">Qty</th>
                        <th style="width:50px; text-align:center;">Sat</th>
                        <th style="width:85px;" class="text-right">Harga Sat</th>
                        <th style="width:95px;" class="text-right">Subtotal</th>
                        <th style="width:45px; text-align:center;">Kurs</th>
                        <th style="width:70px; text-align:center;">Tgl Order</th>
                        <th style="width:70px; text-align:center;">Tgl Kirim</th>
                        <th style="width:65px;">Color</th>
                        <th style="width:50px;">Size</th>
                        <th style="width:85px; text-align:center;">Validasi</th>
                        <th style="width:40px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="stagingBody">
                    <tr><td colspan="19" class="text-center" style="padding:25px; color:var(--text-muted);">Memuat staging data...</td></tr>
                </tbody>
                <tfoot style="position:sticky; bottom:0; background:var(--table-head-bg); font-weight:700; border-top:2px solid var(--border-color);">
                    <tr>
                        <td colspan="8" class="text-right" style="padding:4px 6px;">TOTAL STAGING AREA:</td>
                        <td class="text-right" id="footTotQty" style="color:var(--primary); font-size:10px;">0.00</td>
                        <td colspan="2"></td>
                        <td class="text-right" id="footTotAmt" style="color:#b91c1c; font-size:10px;">0.00</td>
                        <td colspan="7"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- STATUS BAR -->
        <div class="status-bar">
            <span id="txtStagingStatus">0 Baris data di staging area</span>
            <span>Gunakan tombol "Generate Sales Order" untuk memproses data valid menjadi SO resmi</span>
        </div>
    </div>
</div>

<!-- CONTEXT MENU KLIK KANAN -->
<div class="context-menu" id="rightContextMenu">
    <div class="context-menu-item" onclick="copySelectedRow()"><i class="fa-solid fa-copy"></i> Salin Baris Terpilih</div>
    <div class="context-menu-item" onclick="deleteSelectedRow()"><i class="fa-solid fa-trash-can" style="color:var(--danger);"></i> Hapus Baris Terpilih</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="exportExcelCurrent()"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Ekspor ke Excel (.xlsx)</div>
    <div class="context-menu-item" onclick="openModule('inv_material')"><i class="fa-solid fa-boxes-stacked" style="color:#0ea5e9;"></i> Buka Master Barang</div>
    <div class="context-menu-item" onclick="openModule('erp_buyer_supplier')"><i class="fa-solid fa-user-tag" style="color:#8b5cf6;"></i> Buka Master Buyer / Rekanan</div>
</div>

<!-- MODAL PANDUAN SOP IMPORT EXCEL -->
<div id="modalGuide" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:var(--bg-card); width:540px; border-radius:4px; border:1px solid var(--border-color); overflow:hidden; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
        <div style="background:var(--primary); color:#fff; padding:8px 12px; font-weight:700; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fa-solid fa-circle-question"></i> Panduan & SOP Import Sales Order dari Excel</span>
            <button onclick="closeGuideModal()" style="background:none; border:none; color:#fff; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:12px; font-size:10px; line-height:1.6; max-height:420px; overflow-y:auto;">
            <h4 style="color:var(--primary); margin-bottom:4px;">1. Susunan Kolom File Excel</h4>
            <p>File Excel atau data clipboard wajib memiliki susunan urutan kolom berikut:</p>
            <ol style="padding-left:18px; margin:4px 0 8px;">
                <li><b>customer:</b> Kode Buyer / Customer (sesuai Master Rekanan <code>bc_bs</code>).</li>
                <li><b>order_dt:</b> Tanggal Order Buyer (format <code>YYYY-MM-DD</code> atau <code>DD/MM/YYYY</code>).</li>
                <li><b>deliv_dt:</b> Tanggal Rencana Pengiriman (Delivery Date).</li>
                <li><b>po:</b> Nomor Purchase Order dari Buyer.</li>
                <li><b>po_seq:</b> Nomor Urut Item dalam PO (1, 2, 3, ...).</li>
                <li><b>material:</b> Kode Barang / Part Number (sesuai Master Material <code>bc_material</code>).</li>
                <li><b>qty:</b> Kuantitas pesanan.</li>
                <li><b>sat:</b> Kode Satuan Barang (misal: PCE, YDS, MTR, KGM).</li>
                <li><b>price:</b> Harga satuan barang.</li>
                <li><b>kurs:</b> Mata uang transaksi (IDR, USD, EUR, dsb).</li>
                <li><b>color_cd:</b> Kode atau nama warna (opsional).</li>
                <li><b>size_cd:</b> Ukuran / Size produk (opsional).</li>
            </ol>
            <h4 style="color:var(--primary); margin:10px 0 4px;">2. Mekanisme Generate Otomatis</h4>
            <p>Sistem akan mengelompokkan data per <b>Customer</b> dan <b>Nomor PO</b>. Setiap kombinasi akan membentuk 1 Header Sales Order resmi di tabel <code>bc_so_hdr</code> dengan nomor otomatis (<code>SO/YYYYMM/XXXX</code>) dan seluruh item rincian masuk ke <code>bc_so_dtl</code>.</p>
        </div>
        <div style="padding:6px 12px; background:var(--bg-app); border-top:1px solid var(--border-color); text-align:right;">
            <button class="ant-btn ant-btn-primary" onclick="closeGuideModal()">Saya Mengerti</button>
        </div>
    </div>
</div>

<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let stagingData = [];
let lastSelectedRow = null;

document.addEventListener('DOMContentLoaded', () => {
    loadAll();

    // Tutup Context Menu saat klik di luar
    document.addEventListener('click', () => {
        document.getElementById('rightContextMenu').style.display = 'none';
    });

    // Drag and Drop Zone handler
    const dropZone = document.getElementById('dropZone');
    ['dragenter', 'dragover'].forEach(name => {
        dropZone.addEventListener(name, (e) => { e.preventDefault(); dropZone.style.background = '#d1fae5'; });
    });
    ['dragleave', 'drop'].forEach(name => {
        dropZone.addEventListener(name, (e) => { e.preventDefault(); dropZone.style.background = 'var(--primary-light)'; });
    });
    dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length > 0) processExcelFile(files[0]);
    });

    // Right Click Context Menu on Staging Table
    const tbl = document.getElementById('tblStaging');
    tbl.addEventListener('contextmenu', (e) => {
        const tr = e.target.closest('tr');
        if (tr && tr.parentElement.tagName === 'TBODY' && tr.dataset.cust) {
            e.preventDefault();
            tr.parentElement.querySelectorAll('tr').forEach(r => r.classList.remove('selected'));
            tr.classList.add('selected');
            lastSelectedRow = tr;
            const menu = document.getElementById('rightContextMenu');
            menu.style.display = 'block';
            menu.style.left = `${e.pageX}px`;
            menu.style.top = `${e.pageY}px`;
        }
    });
});

function toggleInputPanel() {
    const p = document.getElementById('panelInput');
    p.style.display = (p.style.display === 'none') ? 'grid' : 'none';
}

function loadAll() {
    loadKPI();
    loadStaging();
}

// -------------------------------------------------------------------------
// 1. LOAD KPI
// -------------------------------------------------------------------------
async function loadKPI() {
    try {
        const res = await fetch(`import_so_xls.php?action=kpi&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') return;

        const k = json.kpi;
        document.getElementById('kpiStagingRows').innerText = `${numberFormat(k.staging_rows, 0)} Baris`;
        document.getElementById('kpiStagingQty').innerText = numberFormat(k.staging_qty, 2);
        document.getElementById('kpiImportedSo').innerText = `${numberFormat(k.total_imported_so, 0)} SO`;
        document.getElementById('kpiImportedVal').innerText = `Rp ${numberFormat(k.total_imported_val, 2)}`;
    } catch(e) {
        console.error('Gagal KPI:', e);
    }
}

// -------------------------------------------------------------------------
// 2. LOAD STAGING DATA (d_so_xls)
// -------------------------------------------------------------------------
async function loadStaging() {
    const tbody = document.getElementById('stagingBody');
    tbody.innerHTML = '<tr><td colspan="19" class="text-center" style="padding:20px;">Memuat staging data...</td></tr>';

    try {
        const buyer = document.getElementById('selBuyer').value;
        const statusValid = document.getElementById('selStatusValid').value;
        const search = document.getElementById('txtSearch').value;

        const res = await fetch(`import_so_xls.php?action=get_staging&customer=${encodeURIComponent(buyer)}&status_filter=${encodeURIComponent(statusValid)}&search=${encodeURIComponent(search)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        stagingData = json.data || [];
        document.getElementById('txtStagingStatus').innerText = `${stagingData.length} Baris data di staging area`;

        if (stagingData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="19" class="text-center" style="padding:25px; color:var(--text-muted);">Staging area kosong. Silakan unggah file Excel atau paste data dari clipboard.</td></tr>';
            document.getElementById('footTotQty').innerText = '0.00';
            document.getElementById('footTotAmt').innerText = '0.00';
            document.getElementById('btnTopGenerate').disabled = true;
            return;
        }

        let totQty = 0, totAmt = 0;
        let html = '';

        stagingData.forEach((r, idx) => {
            totQty += parseFloat(r.qty) || 0;
            totAmt += parseFloat(r.amount) || 0;

            const isValid = (r.cust_valid == 1 && r.mat_valid == 1);
            const rowClass = isValid ? '' : 'row-invalid';

            let validBadge = '<span class="badge-status badge-valid"><i class="fa-solid fa-check"></i> OK</span>';
            if (!isValid) {
                let errText = [];
                if (r.cust_valid != 1) errText.push('Buyer ?');
                if (r.mat_valid != 1) errText.push('Barang ?');
                validBadge = `<span class="badge-status badge-invalid" title="Belum terdaftar di Master"><i class="fa-solid fa-triangle-exclamation"></i> ${errText.join(', ')}</span>`;
            }

            html += `<tr class="${rowClass}" data-cust="${escapeHtml(r.customer)}" data-po="${escapeHtml(r.po)}" data-seq="${r.po_seq}" onclick="selectRow(this)">
                <td class="text-center"><input type="checkbox" class="chk-row" data-cust="${escapeHtml(r.customer)}" data-po="${escapeHtml(r.po)}" data-seq="${r.po_seq}"></td>
                <td class="text-center">${idx + 1}</td>
                <td><b style="color:var(--primary);">${escapeHtml(r.customer)}</b></td>
                <td>${escapeHtml(r.nama_cust || '-')}</td>
                <td><b>${escapeHtml(r.po)}</b></td>
                <td class="text-center">${r.po_seq}</td>
                <td><b style="color:#0369a1;">${escapeHtml(r.material)}</b></td>
                <td>${escapeHtml(r.nama_mat || '-')}</td>
                <td class="text-right" style="font-weight:600;">${r.qty_fmt}</td>
                <td class="text-center"><span class="ant-badge" style="background:#f1f5f9; color:#475569;">${escapeHtml(r.sat || 'PCE')}</span></td>
                <td class="text-right">${r.price_fmt}</td>
                <td class="text-right" style="font-weight:700; color:#b91c1c;">${r.amount_fmt}</td>
                <td class="text-center">${escapeHtml(r.kurs)}</td>
                <td class="text-center">${r.order_dt_fmt}</td>
                <td class="text-center">${r.deliv_dt_fmt}</td>
                <td>${escapeHtml(r.color_cd || '-')}</td>
                <td>${escapeHtml(r.size_cd || '-')}</td>
                <td class="text-center">${validBadge}</td>
                <td class="text-center">
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteRow('${escapeHtml(r.customer)}', '${escapeHtml(r.po)}', ${r.po_seq})" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;
        document.getElementById('footTotQty').innerText = numberFormat(totQty);
        document.getElementById('footTotAmt').innerText = numberFormat(totAmt);
        document.getElementById('btnTopGenerate').disabled = (stagingData.length === 0);
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="19" class="text-center" style="padding:20px; color:var(--danger);">${e.message}</td></tr>`;
    }
}

// -------------------------------------------------------------------------
// 3. FILE UPLOAD & PARSING (SHEETJS)
// -------------------------------------------------------------------------
function triggerUploadFile() {
    document.getElementById('fileExcelInput').click();
}

function handleExcelUpload(input) {
    if (input.files && input.files.length > 0) {
        processExcelFile(input.files[0]);
    }
}

function processExcelFile(file) {
    Swal.fire({
        title: 'Membaca Berkas Excel...',
        html: `<div style="font-size:11px;">Mengekstrak baris data dari <b>${escapeHtml(file.name)}</b>...</div>`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[sheetName];

            // Convert to JSON array of objects
            const jsonRows = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
            if (!jsonRows || jsonRows.length <= 1) {
                throw new Error('Berkas Excel kosong atau tidak memiliki baris data.');
            }

            // Normalisasi data baris
            const headerRow = jsonRows[0].map(h => String(h || '').trim().toLowerCase());
            
            // Kolom index detection
            let idxCust = headerRow.findIndex(h => h.includes('cust') || h.includes('buyer'));
            let idxOrd  = headerRow.findIndex(h => h.includes('order') || h.includes('tgl') || h.includes('date'));
            let idxDel  = headerRow.findIndex(h => h.includes('deliv') || h.includes('kirim'));
            let idxPo   = headerRow.findIndex(h => h.includes('po') && !h.includes('seq'));
            let idxSeq  = headerRow.findIndex(h => h.includes('seq') || h.includes('item'));
            let idxMat  = headerRow.findIndex(h => h.includes('mat') || h.includes('item') || h.includes('kode') || h.includes('part'));
            let idxQty  = headerRow.findIndex(h => h.includes('qty') || h.includes('jumlah'));
            let idxSat  = headerRow.findIndex(h => h.includes('sat') || h.includes('uom') || h.includes('unit'));
            let idxPrc  = headerRow.findIndex(h => h.includes('price') || h.includes('harga'));
            let idxKrs  = headerRow.findIndex(h => h.includes('kurs') || h.includes('val'));
            let idxClr  = headerRow.findIndex(h => h.includes('color') || h.includes('warna'));
            let idxSiz  = headerRow.findIndex(h => h.includes('size') || h.includes('ukuran'));

            // Fallback default index jika tidak ada header jelas
            if (idxCust < 0) idxCust = 0;
            if (idxOrd < 0) idxOrd = 1;
            if (idxDel < 0) idxDel = 2;
            if (idxPo < 0) idxPo = 3;
            if (idxSeq < 0) idxSeq = 4;
            if (idxMat < 0) idxMat = 5;
            if (idxQty < 0) idxQty = 6;
            if (idxSat < 0) idxSat = 7;
            if (idxPrc < 0) idxPrc = 8;
            if (idxKrs < 0) idxKrs = 9;
            if (idxClr < 0) idxClr = 10;
            if (idxSiz < 0) idxSiz = 11;

            const extracted = [];
            for (let i = 1; i < jsonRows.length; i++) {
                const row = jsonRows[i];
                if (!row || row.length === 0) continue;

                const cust = String(row[idxCust] || '').trim();
                const po   = String(row[idxPo] || '').trim();
                const mat  = String(row[idxMat] || '').trim();
                if (!cust || !po || !mat) continue;

                extracted.push({
                    customer: cust,
                    order_dt: String(row[idxOrd] || ''),
                    deliv_dt: String(row[idxDel] || ''),
                    po: po,
                    po_seq: parseInt(row[idxSeq]) || i,
                    material: mat,
                    qty: parseFloat(row[idxQty]) || 0,
                    sat: String(row[idxSat] || 'PCE').trim(),
                    price: parseFloat(row[idxPrc]) || 0,
                    kurs: String(row[idxKrs] || 'IDR').trim().toUpperCase(),
                    color_cd: String(row[idxClr] || '').trim(),
                    size_cd: String(row[idxSiz] || '').trim()
                });
            }

            if (extracted.length === 0) {
                throw new Error('Tidak ada baris data valid yang berhasil dibaca. Pastikan format kolom sesuai template.');
            }

            sendDataToStaging(extracted);
        } catch(err) {
            Swal.fire('Error Membaca Excel', err.message, 'error');
        }
    };
    reader.readAsArrayBuffer(file);
}

// -------------------------------------------------------------------------
// 4. SUBMIT DATA KE BACKEND STAGING
// -------------------------------------------------------------------------
async function submitPasteData() {
    const rawText = document.getElementById('rawExcelText').value.trim();
    if (!rawText) {
        Swal.fire('Peringatan', 'Silakan paste data tab-delimited dari Excel ke dalam kotak teks terlebih dahulu.', 'warning');
        return;
    }

    sendDataToStaging(null, rawText);
}

async function sendDataToStaging(jsonRows, rawText = null) {
    const mode = document.querySelector('input[name="radMode"]:checked').value;
    const autoPrice = document.getElementById('chkAutoPrice').checked ? '1' : '0';
    const autoSat = document.getElementById('chkAutoSat').checked ? '1' : '0';

    Swal.fire({
        title: 'Menyimpan ke Staging Area...',
        html: '<div style="font-size:11px;">Memvalidasi Master Buyer & Master Barang...</div>',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const formData = new FormData();
        formData.append('mode', mode);
        formData.append('auto_price', autoPrice);
        formData.append('auto_sat', autoSat);
        formData.append('cmp_id', CURRENT_CMP_ID);

        if (jsonRows) {
            formData.append('json_data', JSON.stringify(jsonRows));
        } else if (rawText) {
            formData.append('raw_text', rawText);
        }

        const res = await fetch('import_so_xls.php?action=paste_import', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        Swal.close();

        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire({
            icon: 'success',
            title: 'Berhasil Masuk Staging',
            text: json.message,
            timer: 1600,
            showConfirmButton: false
        });

        document.getElementById('rawExcelText').value = '';
        document.getElementById('fileExcelInput').value = '';
        loadAll();
    } catch(e) {
        Swal.fire('Gagal Menyimpan', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// 5. BERSIHKAN & HAPUS BARIS
// -------------------------------------------------------------------------
async function clearStaging() {
    if (stagingData.length === 0) {
        Swal.fire('Informasi', 'Staging area sudah kosong.', 'info');
        return;
    }

    const ask = await Swal.fire({
        icon: 'warning',
        title: 'Bersihkan Staging?',
        text: 'Seluruh baris transaksi di staging area akan dihapus.',
        showCancelButton: true,
        confirmButtonText: 'Ya, Bersihkan',
        cancelButtonText: 'Batal',
        confirmButtonColor: 'var(--danger)'
    });
    if (!ask.isConfirmed) return;

    try {
        const res = await fetch(`import_so_xls.php?action=clear_staging&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        Swal.fire({ icon: 'success', title: 'Bersih', text: json.message, timer: 1200, showConfirmButton: false });
        loadAll();
    } catch(e) {
        Swal.fire('Error', e.message, 'error');
    }
}

async function deleteRow(cust, po, seq) {
    try {
        const formData = new FormData();
        formData.append('customer', cust);
        formData.append('po', po);
        formData.append('po_seq', seq);
        formData.append('cmp_id', CURRENT_CMP_ID);

        const res = await fetch('import_so_xls.php?action=delete_row', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        loadAll();
    } catch(e) {
        Swal.fire('Error', e.message, 'error');
    }
}

function selectRow(tr) {
    tr.parentElement.querySelectorAll('tr').forEach(r => r.classList.remove('selected'));
    tr.classList.add('selected');
    lastSelectedRow = tr;
}

function toggleCheckAll(checked) {
    document.querySelectorAll('.chk-row').forEach(c => c.checked = checked);
}

// -------------------------------------------------------------------------
// 6. GENERATE SALES ORDER OTOMATIS
// -------------------------------------------------------------------------
async function generateSalesOrder() {
    if (stagingData.length === 0) {
        Swal.fire('Peringatan', 'Staging area masih kosong. Tidak ada data untuk digenerate.', 'warning');
        return;
    }

    // Cek invalid rows
    const invalidCount = stagingData.filter(r => r.cust_valid != 1 || r.mat_valid != 1).length;
    let warnHtml = '';
    if (invalidCount > 0) {
        warnHtml = `<div style="background:#fee2e2; border:1px solid #fca5a5; border-radius:4px; padding:6px 10px; color:#b91c1c; font-size:11px; margin-top:8px; text-align:left;">
            <i class="fa-solid fa-triangle-exclamation"></i> <b>Perhatian:</b> Ditemukan <b>${invalidCount} baris</b> yang Buyer atau Barangnya belum terdaftar di master. Item tersebut mungkin ditolak atau tidak lengkap.
        </div>`;
    }

    const ask = await Swal.fire({
        icon: 'question',
        title: 'Generate Sales Order?',
        html: `<div style="font-size:11.5px; line-height:1.6;">
            Sistem akan membentuk Sales Order resmi dari <b>${stagingData.length} baris</b> data di staging.<br>
            Nomor SO akan di-generate otomatis (misal: <code>SO/${new Date().getFullYear()}${String(new Date().getMonth()+1).padStart(2,'0')}/XXXX</code>).
            ${warnHtml}
        </div>`,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-wand-magic-sparkles"></i> Ya, Generate SO',
        cancelButtonText: 'Batal',
        confirmButtonColor: 'var(--primary)'
    });
    if (!ask.isConfirmed) return;

    Swal.fire({
        title: 'Membentuk Sales Order...',
        html: '<div style="font-size:11px;">Menyimpan header & detail ke tabel <code>bc_so_hdr</code> dan <code>bc_so_dtl</code>...</div>',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    try {
        const formData = new FormData();
        formData.append('so_status', '1'); // '1' = Open
        formData.append('cmp_id', CURRENT_CMP_ID);

        const res = await fetch('import_so_xls.php?action=generate_so', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        Swal.close();

        if (json.status !== 'OK') throw new Error(json.message);

        let listHtml = '';
        if (json.generated_list && json.generated_list.length > 0) {
            listHtml = `<div style="max-height:150px; overflow-y:auto; margin:8px 0; border:1px solid var(--border-color); border-radius:3px; text-align:left; font-size:10.5px;">
                <table style="width:100%; border-collapse:collapse;">
                    ${json.generated_list.map(s => `
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:3px 6px;"><b>${s.so_no}</b></td>
                            <td style="padding:3px 6px;">${s.customer}</td>
                            <td style="padding:3px 6px;">PO: ${s.po}</td>
                            <td style="padding:3px 6px; text-align:right;">Rp ${numberFormat(s.total_order)}</td>
                        </tr>
                    `).join('')}
                </table>
            </div>`;
        }

        Swal.fire({
            icon: 'success',
            title: 'Sales Order Berhasil Dibentuk!',
            html: `<div style="font-size:11px; line-height:1.6;">
                ${json.message}<br>
                ${listHtml}
                Data staging telah dipindahkan ke modul Sales Order resmi.
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-cart-shopping"></i> Buka Modul Sales Order',
            cancelButtonText: 'Tetap di Halaman Ini',
            confirmButtonColor: 'var(--primary)'
        }).then(res => {
            if (res.isConfirmed) {
                openModule('sale_so');
            }
        });

        loadAll();
    } catch(e) {
        Swal.fire('Gagal Generate SO', e.message, 'error');
    }
}

// -------------------------------------------------------------------------
// 7. EXPORT EXCEL & CLIPBOARD
// -------------------------------------------------------------------------
function exportExcelCurrent() {
    const tbl = document.getElementById('tblStaging');
    if (!tbl) return;
    try {
        const wb = XLSX.utils.table_to_book(tbl, { sheet: "Staging_SO" });
        XLSX.writeFile(wb, `Staging_SO_${Date.now()}.xlsx`);
    } catch(e) {
        Swal.fire('Error Export', e.message, 'error');
    }
}

function copySelectedRow() {
    if (!lastSelectedRow) return;
    const cells = Array.from(lastSelectedRow.cells).map(c => c.innerText.trim());
    const tsv = cells.join('\t');
    navigator.clipboard.writeText(tsv).then(() => {
        Swal.fire({ icon: 'success', title: 'Tersalin', text: 'Baris disalin ke clipboard.', timer: 1000, showConfirmButton: false });
    });
}

function downloadTemplate() {
    window.location.href = 'import_so_xls.php?action=download_template';
}

function openModule(mod) {
    if (window.parent && window.parent.switchMainTab) {
        window.parent.switchMainTab(mod);
    } else {
        const map = {
            'sale_so': '/erp/sales_order.php',
            'inv_material': '/material.php',
            'erp_buyer_supplier': '/erp/buyer_supplier.php'
        };
        window.open(`${map[mod]}?cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
    }
}

function openGuideModal() {
    document.getElementById('modalGuide').style.display = 'flex';
}

function closeGuideModal() {
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
</script>

</body>
</html>

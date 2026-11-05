<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Receive Item (GRN / Goods Receipt Note)
 * ============================================================================
 * Porting Lengkap dari PowerBuilder TPB-ERP:
 * - w_ri.srw (Window Penerimaan Barang)
 * - bc_ri_hdr.srd / bc_ri_hdr2.srd (Header Receive Item)
 * - d_ri_dtl.srd (Rincian Barang Diterima)
 * - d_ri_browse.srd (Daftar Dokumen Penerimaan Barang)
 * - d_rinci_ri_diproses2.srd (Realisasi ke Faktur Pembelian / Purchase Invoice)
 * - d_ri_prt_a.srd / d_ri_prt_b.srd (Dokumen Cetak Bukti Penerimaan Barang / BPB)
 * Mengadopsi 100% Ant Design Compact Desktop 11px Identik Purchasing - Purchase Order (PO)
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
if (empty($activeCmpName) && !empty($sessionCmpId)) {
    $cmpQ = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($cmpQ && $cRow = $cmpQ->fetch_assoc()) {
        $activeCmpName = $cRow['cmp_name'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'TPB ERP Enterprise';

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_ri_hdr dan bc_ri_dtl Siap Multi-Tenant
// -------------------------------------------------------------------------
function ensureRiTables($koneksi) {
    try {
        // 1. Table bc_ri_hdr
        $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_ri_hdr'");
        if (!$checkHdr || $checkHdr->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ri_hdr` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `ri_no` varchar(50) NOT NULL DEFAULT '',
                `ri_dt` date DEFAULT NULL,
                `ship_dt` date DEFAULT NULL,
                `sj_no` varchar(50) DEFAULT '',
                `po_no` varchar(50) DEFAULT '',
                `vendor` varchar(50) NOT NULL DEFAULT '',
                `fob` varchar(20) DEFAULT '1',
                `term` varchar(50) DEFAULT 'Net 30',
                `ship` varchar(50) DEFAULT '',
                `gudang` varchar(50) DEFAULT 'UTAMA',
                `kurs` varchar(10) DEFAULT 'IDR',
                `rate` decimal(18,4) DEFAULT '1.0000',
                `vendoristaxable` char(1) DEFAULT 'Y',
                `inclusivetax` char(1) DEFAULT 'N',
                `note` text DEFAULT NULL,
                `subtotal` decimal(18,4) DEFAULT '0.0000',
                `disc_persen` decimal(10,2) DEFAULT '0.00',
                `disc_amt` decimal(18,4) DEFAULT '0.0000',
                `ppn` decimal(18,4) DEFAULT '0.0000',
                `est_freight` decimal(18,4) DEFAULT '0.0000',
                `total_order` decimal(18,4) DEFAULT '0.0000',
                `terbayar` decimal(18,4) DEFAULT '0.0000',
                `outstand` decimal(18,4) DEFAULT '0.0000',
                `no_fp` varchar(50) DEFAULT '',
                `kd_fp` varchar(10) DEFAULT '',
                `bc` varchar(10) DEFAULT '',
                `no_bc` varchar(50) DEFAULT '',
                `tgl_bc` date DEFAULT NULL,
                `no_aju` varchar(50) DEFAULT '',
                `issubcon` char(1) DEFAULT 'N',
                `status` char(1) DEFAULT '0',
                `set_by` varchar(50) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_cmp_ri` (`cmp_id`, `ri_no`),
                KEY `idx_vendor` (`vendor`),
                KEY `idx_ri_dt` (`ri_dt`),
                KEY `idx_po_no` (`po_no`),
                KEY `idx_no_aju` (`no_aju`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $colsH = [];
            $resH = $koneksi->query("SHOW COLUMNS FROM bc_ri_hdr");
            if ($resH) while ($r = $resH->fetch_assoc()) $colsH[strtolower($r['Field'])] = true;
            $hdrExtra = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'sj_no' => "varchar(50) DEFAULT ''",
                'po_no' => "varchar(50) DEFAULT ''",
                'gudang' => "varchar(50) DEFAULT 'UTAMA'",
                'kurs' => "varchar(10) DEFAULT 'IDR'",
                'rate' => "decimal(18,4) DEFAULT '1.0000'",
                'subtotal' => "decimal(18,4) DEFAULT '0.0000'",
                'total_order' => "decimal(18,4) DEFAULT '0.0000'",
                'bc' => "varchar(10) DEFAULT ''",
                'no_bc' => "varchar(50) DEFAULT ''",
                'tgl_bc' => "date DEFAULT NULL",
                'no_aju' => "varchar(50) DEFAULT ''",
                'status' => "char(1) DEFAULT '0'",
                'is_it_inv' => "tinyint(1) NOT NULL DEFAULT 0",
                'it_inv_no' => "varchar(50) DEFAULT ''",
                'it_inv_dt' => "datetime DEFAULT NULL",
                'set_by' => "varchar(50) DEFAULT ''",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
            ];
            foreach ($hdrExtra as $col => $def) {
                if (!isset($colsH[$col])) {
                    @$koneksi->query("ALTER TABLE bc_ri_hdr ADD COLUMN `$col` $def");
                }
            }
        }

        // 2. Table bc_ri_dtl
        $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_ri_dtl'");
        if (!$checkDtl || $checkDtl->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ri_dtl` (
                `id` varchar(40) NOT NULL DEFAULT '',
                `id_hdr` varchar(40) NOT NULL DEFAULT '',
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `ri_no` varchar(50) NOT NULL DEFAULT '',
                `seq` int(11) NOT NULL DEFAULT '1',
                `id_po` varchar(40) DEFAULT '',
                `po_no` varchar(50) DEFAULT '',
                `kode_barang` varchar(50) NOT NULL DEFAULT '',
                `uraian` varchar(255) NOT NULL DEFAULT '',
                `qty` decimal(18,4) DEFAULT '0.0000',
                `qty_dokumen` decimal(18,4) DEFAULT '0.0000',
                `qty_aktual` decimal(18,4) DEFAULT '0.0000',
                `sesuai` char(1) DEFAULT 'Y',
                `memo` text DEFAULT NULL,
                `seri_barang` int(11) DEFAULT 0,
                `pos_tarif` varchar(30) DEFAULT '',
                `sat` varchar(20) DEFAULT '',
                `price` decimal(18,4) DEFAULT '0.0000',
                `disc` decimal(10,2) DEFAULT '0.00',
                `amount` decimal(18,4) DEFAULT '0.0000',
                `terima` decimal(18,4) DEFAULT '0.0000',
                `netto` decimal(18,4) DEFAULT '0.0000',
                `gross` decimal(18,4) DEFAULT '0.0000',
                `cif` decimal(18,4) DEFAULT '0.0000',
                `cif_rupiah` decimal(18,4) DEFAULT '0.0000',
                `gudang` varchar(50) DEFAULT 'UTAMA',
                `rack` varchar(50) DEFAULT '',
                `dept` varchar(50) DEFAULT '',
                `note` text DEFAULT NULL,
                `status` char(1) DEFAULT '0',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_hdr` (`id_hdr`),
                KEY `idx_cmp_ri_dtl` (`cmp_id`, `ri_no`),
                KEY `idx_kode_barang` (`kode_barang`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $colsD = [];
            $resD = $koneksi->query("SHOW COLUMNS FROM bc_ri_dtl");
            if ($resD) while ($r = $resD->fetch_assoc()) $colsD[strtolower($r['Field'])] = true;
            $dtlExtra = [
                'cmp_id' => "varchar(30) NOT NULL DEFAULT ''",
                'ri_no' => "varchar(50) NOT NULL DEFAULT ''",
                'id_po' => "varchar(40) DEFAULT ''",
                'po_no' => "varchar(50) DEFAULT ''",
                'qty_dokumen' => "decimal(18,4) DEFAULT '0.0000'",
                'qty_aktual' => "decimal(18,4) DEFAULT '0.0000'",
                'sesuai' => "char(1) DEFAULT 'Y'",
                'memo' => "text DEFAULT NULL",
                'seri_barang' => "int(11) DEFAULT 0",
                'pos_tarif' => "varchar(30) DEFAULT ''",
                'terima' => "decimal(18,4) DEFAULT '0.0000'",
                'cif' => "decimal(18,4) DEFAULT '0.0000'",
                'cif_rupiah' => "decimal(18,4) DEFAULT '0.0000'",
                'gudang' => "varchar(50) DEFAULT 'UTAMA'",
                'rack' => "varchar(50) DEFAULT ''",
                'dept' => "varchar(50) DEFAULT ''",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP"
            ];
            foreach ($dtlExtra as $col => $def) {
                if (!isset($colsD[$col])) {
                    @$koneksi->query("ALTER TABLE bc_ri_dtl ADD COLUMN `$col` $def");
                }
            }
        }
    } catch (\Throwable $ex) {}
}
ensureRiTables($koneksi);

/**
 * Sinkronisasi Rekalkulasi Akumulasi Terima & Status PO (Auto-Close PO jika tuntas diterima)
 * Mengacu pada logika PowerBuilder TPB-ERP w_ri.srw: ue_update_po_vendor()
 * Status PO Header: '0' = Open, '1' = Parsial, '2' = Closed (Full Received), '3' = Cancel
 */
function syncPoStatusAndOutstanding($koneksi, $poList, $cmpId = '') {
    if (empty($poList)) return;
    if (!is_array($poList)) $poList = [$poList];
    $poList = array_unique(array_filter(array_map('trim', $poList)));
    if (empty($poList)) return;

    $escCmp = $koneksi->real_escape_string($cmpId);
    $cmpClause = !empty($escCmp) ? "AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)" : "";

    foreach ($poList as $poNo) {
        if (empty($poNo)) continue;
        $escPo = $koneksi->real_escape_string($poNo);

        // 1. Update kolom terima pada masing-masing baris bc_po_dtl
        $koneksi->query("UPDATE bc_po_dtl d SET d.terima = (
            SELECT COALESCE(SUM(r.qty), 0)
            FROM bc_ri_dtl r
            WHERE (r.id_po = d.id OR (r.po_no = d.po_no AND r.kode_barang = d.kode_barang))
        ) WHERE d.po_no = '{$escPo}' $cmpClause");

        // 2. Update status per baris bc_po_dtl (1 = received / closed, 0 = open)
        $koneksi->query("UPDATE bc_po_dtl SET status = CASE WHEN (terima >= qty AND qty > 0) THEN '1' ELSE '0' END
                         WHERE po_no = '{$escPo}' $cmpClause");

        // 3. Evaluasi agregat untuk update bc_po_hdr.status
        $aggQ = $koneksi->query("SELECT
            COUNT(1) AS total_items,
            SUM(CASE WHEN terima >= qty AND qty > 0 THEN 1 ELSE 0 END) AS full_items,
            SUM(qty) AS total_qty,
            SUM(terima) AS total_terima
            FROM bc_po_dtl
            WHERE po_no = '{$escPo}' $cmpClause");

        if ($aggQ && $agg = $aggQ->fetch_assoc()) {
            $totalItems  = (int)($agg['total_items'] ?? 0);
            $fullItems   = (int)($agg['full_items'] ?? 0);
            $totalQty    = (float)($agg['total_qty'] ?? 0);
            $totalTerima = (float)($agg['total_terima'] ?? 0);

            $newStatus = '0'; // Default Open
            if ($totalItems > 0 && $fullItems >= $totalItems) {
                $newStatus = '2'; // Closed (Full Received)
            } elseif ($totalTerima > 0 || $fullItems > 0) {
                $newStatus = '1'; // Parsial (Sebagian diterima)
            } else {
                $newStatus = '0'; // Open
            }

            // Jangan override jika statusnya Batal (3)
            $koneksi->query("UPDATE bc_po_hdr
                             SET status = '{$newStatus}'
                             WHERE po_no = '{$escPo}' AND status <> '3' $cmpClause");
        }
    }
}

// =========================================================================
// AJAX BACKEND API HANDLERS
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. LIST DOKUMEN PENERIMAAN BARANG (TAB 2 / BROWSE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $limit        = max(10, min(200, (int)($_GET['limit'] ?? 25)));
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
            $where[] = "(h.ri_no LIKE '%{$escS}%' OR h.vendor LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%' OR h.po_no LIKE '%{$escS}%' OR h.sj_no LIKE '%{$escS}%' OR h.no_aju LIKE '%{$escS}%' OR h.no_bc LIKE '%{$escS}%')";
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
            $where[] = "h.ri_dt >= '{$escAwal}'";
        }
        if (!empty($tglAkhir)) {
            $escAkhir = $koneksi->real_escape_string($tglAkhir);
            $where[] = "h.ri_dt <= '{$escAkhir}'";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $cntRes = $koneksi->query("SELECT COUNT(1) as total FROM bc_ri_hdr h LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL)) {$whereSql}");
        $total = $cntRes ? (int)$cntRes->fetch_assoc()['total'] : 0;

        $sql = "SELECT h.id, h.cmp_id, h.ri_no, h.ri_dt, h.ship_dt, h.sj_no, h.po_no, h.vendor, 
                       h.term, h.ship, h.fob, h.gudang, h.kurs, h.rate, h.subtotal, h.disc_persen, h.disc_amt, 
                       h.ppn, h.total_order, h.bc, h.no_bc, h.tgl_bc, h.no_aju, h.status, h.is_it_inv, h.it_inv_no, h.it_inv_dt, h.set_by,
                       COALESCE(b.NAMA, h.vendor) as vendor_nama,
                       b.NAMA_PENDEK as vendor_alias,
                       (SELECT COUNT(1) FROM bc_ri_dtl d WHERE d.id_hdr = h.id) as item_count,
                       (SELECT COALESCE(SUM(d.qty), 0) FROM bc_ri_dtl d WHERE d.id_hdr = h.id) as total_qty,
                       (SELECT COALESCE(SUM(d.amount), 0) FROM bc_ri_dtl d WHERE d.id_hdr = h.id) as detail_amount,
                       (SELECT COALESCE(SUM(d.terima), 0) FROM bc_ri_dtl d WHERE d.id_hdr = h.id) as qty_invoiced
                FROM bc_ri_hdr h
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                {$whereSql}
                ORDER BY h.ri_dt DESC, h.ri_no DESC
                LIMIT {$offset}, {$limit}";

        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $totalOrder = (float)$row['total_order'];
                if ($totalOrder <= 0) $totalOrder = (float)$row['detail_amount'];
                $totalQty = (float)$row['total_qty'];
                $qtyInvoiced = (float)$row['qty_invoiced'];

                $row['total_order_formatted'] = number_format($totalOrder, 2, '.', ',');
                $row['total_qty_formatted'] = number_format($totalQty, 2, '.', ',');
                $row['ri_dt_formatted'] = !empty($row['ri_dt']) ? date('d/m/Y', strtotime($row['ri_dt'])) : '-';
                $row['is_fully_invoiced'] = ($totalQty > 0 && $qtyInvoiced >= $totalQty);
                $data[] = $row;
            }
        }

        // Mini KPI Summary Strip
        $kpiWhere = !empty($sessionCmpId) ? "WHERE (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)" : "";
        $curMonth = date('Y-m');
        $kpiQ = $koneksi->query("SELECT 
            COUNT(1) AS total_grn,
            SUM(CASE WHEN h.ri_dt LIKE '{$curMonth}%' THEN 1 ELSE 0 END) AS grn_bulan_ini,
            SUM(h.total_order) AS total_nilai,
            SUM(CASE WHEN h.status = '0' OR (h.status = '1' AND (SELECT COALESCE(SUM(terima), 0) FROM bc_ri_dtl y WHERE y.id_hdr = h.id) < (SELECT COALESCE(SUM(qty), 0) FROM bc_ri_dtl z WHERE z.id_hdr = h.id)) THEN 1 ELSE 0 END) AS unbilled_grn,
            SUM(CASE WHEN h.status = '2' OR (h.status = '1' AND (SELECT COALESCE(SUM(terima), 0) FROM bc_ri_dtl y WHERE y.id_hdr = h.id) >= (SELECT COALESCE(SUM(qty), 0) FROM bc_ri_dtl z WHERE z.id_hdr = h.id) AND (SELECT COALESCE(SUM(qty), 0) FROM bc_ri_dtl z WHERE z.id_hdr = h.id) > 0) THEN 1 ELSE 0 END) AS invoiced_grn
            FROM bc_ri_hdr h $kpiWhere");
        
        $kpi = [
            'total_grn'     => 0,
            'grn_bulan_ini' => 0,
            'total_nilai'   => 0,
            'unbilled_grn'  => 0,
            'invoiced_grn'  => 0
        ];
        if ($kpiQ && $kRow = $kpiQ->fetch_assoc()) {
            $kpi = [
                'total_grn'     => (int)($kRow['total_grn'] ?? 0),
                'grn_bulan_ini' => (int)($kRow['grn_bulan_ini'] ?? 0),
                'total_nilai'   => number_format((float)($kRow['total_nilai'] ?? 0), 2, '.', ','),
                'unbilled_grn'  => (int)($kRow['unbilled_grn'] ?? 0),
                'invoiced_grn'  => (int)($kRow['invoiced_grn'] ?? 0)
            ];
        }

        echo json_encode([
            'status'      => 'OK',
            'data'        => $data,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => ceil($total / $limit),
            'kpi'         => $kpi
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memuat data: ' . $e->getMessage()]);
    }
    exit;
}

// 2. GET SINGLE RI DETAIL & PI REALIZATION
if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_GET['id'] ?? '');
        if (empty($id)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'ID Penerimaan Barang tidak valid']);
            exit;
        }
        $escId = $koneksi->real_escape_string($id);

        $hdrQ = $koneksi->query("SELECT h.*, 
                                        COALESCE(b.NAMA, h.vendor) as vendor_nama, 
                                        b.NAMA_PENDEK as vendor_alias,
                                        b.ALAMAT as vendor_alamat, 
                                        b.NPWP as vendor_npwp, 
                                        b.nitku as vendor_nitku
                                 FROM bc_ri_hdr h
                                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                 WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL) 
                                 LIMIT 1");
        if (!$hdrQ || $hdrQ->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data Penerimaan Barang (RI) tidak ditemukan']);
            exit;
        }
        $header = $hdrQ->fetch_assoc();

        // Details
        $dtlQ = $koneksi->query("SELECT d.*, 
                                       COALESCE(m.KODE_SATUAN, d.sat) as master_satuan,
                                       COALESCE(m.URAIAN, d.uraian) as master_nama
                                FROM bc_ri_dtl d
                                LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                                WHERE d.id_hdr = '{$escId}' 
                                ORDER BY d.seq ASC, d.id ASC");
        $details = [];
        if ($dtlQ) {
            while ($r = $dtlQ->fetch_assoc()) {
                $details[] = $r;
            }
        }

        // Realisasi Purchase Invoice (sesuai d_rinci_ri_diproses2.srd)
        $piRealization = [];
        try {
            $piQ = $koneksi->query("SELECT a.pi_no, a.pi_dt, b.kode_barang, b.uraian, b.qty, b.sat, b.price, b.amount, a.id as id_pi
                                    FROM bc_pi_dtl b
                                    JOIN bc_pi_hdr a ON a.id = b.id_hdr
                                    WHERE b.id_ri = '{$escId}' OR (b.id_ri = '' AND b.ri_no = '{$header['ri_no']}')
                                    ORDER BY a.pi_dt DESC, a.pi_no DESC");
            if ($piQ) {
                while ($pr = $piQ->fetch_assoc()) {
                    $pr['pi_dt_formatted'] = !empty($pr['pi_dt']) ? date('d/m/Y', strtotime($pr['pi_dt'])) : '-';
                    $piRealization[] = $pr;
                }
            }
        } catch (\Throwable $ePi) {}

        echo json_encode([
            'status'         => 'OK',
            'header'         => $header,
            'details'        => $details,
            'pi_realization' => $piRealization
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengambil data: ' . $e->getMessage()]);
    }
    exit;
}

// 3. LOOKUP OPEN PO (PO YANG MASIH PUNYA SISA BARANG BELUM DITERIMA)
if ($action === 'lookup_open_po') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $search = trim($_GET['search'] ?? '');
        $vendor = trim($_GET['vendor'] ?? '');
        $where = ["(p.cmp_id = '{$escCmp}' OR p.cmp_id = '' OR p.cmp_id IS NULL)", "p.status != 'X'"];
        
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(p.po_no LIKE '%{$escS}%' OR p.vendor LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%')";
        }
        if (!empty($vendor)) {
            $escV = $koneksi->real_escape_string($vendor);
            $where[] = "p.vendor = '{$escV}'";
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT DISTINCT p.id, p.po_no, p.po_dt, p.vendor, p.kurs, p.rate, p.term, p.fob,
                               COALESCE(b.NAMA, p.vendor) as vendor_nama,
                               (SELECT COUNT(1) FROM bc_po_dtl d WHERE (d.id_hdr = p.id OR d.po_no = p.po_no)) as total_items,
                               (SELECT COALESCE(SUM(d.qty), 0) FROM bc_po_dtl d WHERE (d.id_hdr = p.id OR d.po_no = p.po_no)) as po_qty,
                               (SELECT COALESCE(SUM(r.qty), 0) FROM bc_ri_dtl r WHERE (r.id_po = p.id OR r.po_no = p.po_no)) as ri_qty
                FROM bc_po_hdr p
                LEFT JOIN bc_bs b ON (p.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                WHERE {$whereSql}
                ORDER BY p.po_dt DESC, p.po_no DESC
                LIMIT 40";
        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $poQty = (float)$r['po_qty'];
                $riQty = (float)$r['ri_qty'];
                $r['po_dt_formatted'] = !empty($r['po_dt']) ? date('d/m/Y', strtotime($r['po_dt'])) : '-';
                $r['sisa_qty'] = max(0, $poQty - $riQty);
                $r['sisa_qty_formatted'] = number_format($r['sisa_qty'], 2, '.', ',');
                // Tampilkan hanya jika ada sisa belum diterima atau jika dicari spesifik
                if ($r['sisa_qty'] > 0 || !empty($search)) {
                    $data[] = $r;
                }
            }
        }
        echo json_encode(['status' => 'OK', 'data' => $data]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memuat daftar PO: ' . $e->getMessage()]);
    }
    exit;
}

// 4. GET PO ITEMS WITH REMAINING QUANTITY FOR RI
if ($action === 'get_po_items_for_ri') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $poNo = trim($_GET['po_no'] ?? '');
        if (empty($poNo)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Nomor PO tidak valid']);
            exit;
        }
        $escPo = $koneksi->real_escape_string($poNo);

        $poHdrQ = $koneksi->query("SELECT p.*, COALESCE(b.NAMA, p.vendor) as vendor_nama, b.ALAMAT as vendor_alamat, b.NPWP as vendor_npwp
                                  FROM bc_po_hdr p
                                  LEFT JOIN bc_bs b ON (p.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                  WHERE (p.po_no = '{$escPo}' OR p.id = '{$escPo}') AND (p.cmp_id = '{$escCmp}' OR p.cmp_id = '' OR p.cmp_id IS NULL)
                                  LIMIT 1");
        $poHdr = $poHdrQ ? $poHdrQ->fetch_assoc() : null;

        $sql = "SELECT d.id, d.id_hdr, d.po_no, d.item_no, d.seq, d.kode_barang, 
                       COALESCE(d.nama_barang, d.uraian) as uraian,
                       d.qty as qty_po,
                       COALESCE(d.satuan, d.sat) as sat,
                       COALESCE(d.harga, d.price) as price,
                       COALESCE(d.diskon, d.disc) as disc,
                       COALESCE(d.total, d.amount) as amount,
                       COALESCE((SELECT SUM(r.qty) FROM bc_ri_dtl r WHERE (r.id_po = d.id OR (r.po_no = d.po_no AND r.kode_barang = d.kode_barang))), 0) as qty_terima_sebelumnya
                FROM bc_po_dtl d
                WHERE (d.po_no = '{$escPo}' OR d.id_hdr = '{$escPo}') AND (d.cmp_id = '{$escCmp}' OR d.cmp_id = '' OR d.cmp_id IS NULL)
                ORDER BY d.seq ASC, d.item_no ASC";
        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $qtyPo = (float)$r['qty_po'];
                $qtyTerima = (float)$r['qty_terima_sebelumnya'];
                $sisa = max(0, $qtyPo - $qtyTerima);
                $r['sisa_qty'] = $sisa;
                $r['qty_receive_now'] = $sisa; // default diusulkan terima sejumlah sisa
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'header' => $poHdr, 'items' => $items]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengambil item PO: ' . $e->getMessage()]);
    }
    exit;
}

// 5. LOOKUP MASTER MATERIAL (bc_material)
if ($action === 'lookup_materials') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $q = trim($_GET['q'] ?? '');
        $w = ["(cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)"];
        if (!empty($q)) {
            $escQ = $koneksi->real_escape_string($q);
            $w[] = "(KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%' OR MODEL LIKE '%{$escQ}%')";
        }
        $whereSql = implode(' AND ', $w);
        $res = $koneksi->query("SELECT KODE_BARANG, URAIAN as NAMA, KODE_SATUAN as SATUAN, MODEL as SPESIFIKASI, GRUP_MATERIAL, UNIT_PRICE as HARGA_STANDAR 
                                FROM bc_material 
                                WHERE {$whereSql} 
                                ORDER BY KODE_BARANG ASC 
                                LIMIT 30");
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $items[] = $r;
        }
        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mencari master material: ' . $e->getMessage()]);
    }
    exit;
}

// 5.1 LOOKUP STAGING DOKUMEN PABEAN MASUK (CEISA 4.0 STAGING)
if ($action === 'lookup_staging_aju') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $search   = trim($_GET['search'] ?? ($_GET['q'] ?? ''));
        $tglAwal  = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir = trim($_GET['tgl_akhir'] ?? '');
        $bcDoc    = trim($_GET['bc_doc'] ?? 'ALL');

        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)";
        }
        // Wajib sudah ada nomor pendaftaran Bea Cukai
        $where[] = "h.nomor_daftar IS NOT NULL AND TRIM(h.nomor_daftar) != '' AND TRIM(h.nomor_daftar) != '-'";

        // Inbound documents: 23, 40, 262, 27, 611, 20, 16, 28
        if (!empty($bcDoc) && $bcDoc !== 'ALL') {
            $escBcDoc = $koneksi->real_escape_string($bcDoc);
            $where[] = "h.kode_dokumen = '{$escBcDoc}'";
        } else {
            $inboundCodes = "('23','40','262','27','611','20','16','28')";
            $where[] = "h.kode_dokumen IN {$inboundCodes}";
        }

        // Tanggal Filter: jika tanggal kosong dan search kosong, default 60 hari terakhir
        if (empty($tglAwal) && empty($tglAkhir) && empty($search)) {
            $tglAwal  = date('Y-m-d', strtotime('-60 days'));
            $tglAkhir = date('Y-m-d');
        }

        if (!empty($tglAwal) && !empty($tglAkhir)) {
            $escT1 = $koneksi->real_escape_string($tglAwal);
            $escT2 = $koneksi->real_escape_string($tglAkhir);
            $where[] = "((h.tanggal_daftar >= '{$escT1}' AND h.tanggal_daftar <= '{$escT2}') OR (h.tanggal_pernyataan >= '{$escT1}' AND h.tanggal_pernyataan <= '{$escT2}'))";
        } elseif (!empty($tglAwal)) {
            $escT1 = $koneksi->real_escape_string($tglAwal);
            $where[] = "(h.tanggal_daftar >= '{$escT1}' OR h.tanggal_pernyataan >= '{$escT1}')";
        } elseif (!empty($tglAkhir)) {
            $escT2 = $koneksi->real_escape_string($tglAkhir);
            $where[] = "(h.tanggal_daftar <= '{$escT2}' OR h.tanggal_pernyataan <= '{$escT2}')";
        }

        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(
                h.nomor_aju LIKE '%{$escS}%' 
                OR h.nomor_daftar LIKE '%{$escS}%' 
                OR EXISTS (SELECT 1 FROM bc_ceisa_entitas e WHERE e.nomor_aju = h.nomor_aju AND e.nama_entitas LIKE '%{$escS}%') 
                OR EXISTS (SELECT 1 FROM bc_ceisa_dokumen d WHERE d.nomor_aju = h.nomor_aju AND d.nomor_dokumen LIKE '%{$escS}%')
            )";
        }
        $whereSql = "WHERE " . implode(' AND ', $where);

        $sql = "SELECT 
            h.nomor_aju,
            h.kode_dokumen AS bc,
            IFNULL(h.nomor_daftar, '-') AS nomor_daftar,
            IFNULL(h.tanggal_daftar, '-') AS tanggal_daftar,
            IFNULL(h.tanggal_pernyataan, '-') AS tanggal_pernyataan,
            IFNULL(
                (SELECT e.nama_entitas FROM bc_ceisa_entitas e 
                 WHERE e.nomor_aju = h.nomor_aju AND e.kode_entitas IN ('3','7','8','PENGIRIM','PEMASOK','PENJUAL','5','9','1') 
                 ORDER BY FIELD(e.kode_entitas, '3','PEMASOK','7','8','PENGIRIM','PENJUAL','5','9','1') ASC LIMIT 1), 
                IFNULL(h.nama_pernyataan, '-')
            ) AS supplier_name,
            (SELECT e.nomor_identitas FROM bc_ceisa_entitas e 
             WHERE e.nomor_aju = h.nomor_aju AND e.kode_entitas IN ('3','7','8','PENGIRIM','PEMASOK','PENJUAL','5','9','1') 
             ORDER BY FIELD(e.kode_entitas, '3','PEMASOK','7','8','PENGIRIM','PENJUAL','5','9','1') ASC LIMIT 1) AS supplier_npwp,
            IFNULL(
                (SELECT d.nomor_dokumen FROM bc_ceisa_dokumen d 
                 WHERE d.nomor_aju = h.nomor_aju AND d.kode_dokumen IN ('380','230','217','999') 
                 ORDER BY FIELD(d.kode_dokumen, '380','230','217','999') ASC LIMIT 1), 
                '-'
            ) AS invoice_sj,
            IFNULL(h.kode_valuta, 'IDR') AS valuta,
            (SELECT COUNT(1) FROM bc_ceisa_barang b WHERE b.nomor_aju = h.nomor_aju) AS jml_barang,
            (SELECT COUNT(1) FROM bc_1ab2 inv WHERE inv.nomor_aju = h.nomor_aju) AS is_it_inv,
            (SELECT MAX(inv.NO_BUKTI) FROM bc_1ab2 inv WHERE inv.nomor_aju = h.nomor_aju) AS it_no_bukti
        FROM bc_ceisa_header h
        {$whereSql}
        ORDER BY COALESCE(h.tanggal_daftar, h.tanggal_pernyataan) DESC, h.nomor_aju DESC
        LIMIT 100";

        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['tgl_daftar_fmt'] = ($r['tanggal_daftar'] !== '-') ? date('d/m/Y', strtotime($r['tanggal_daftar'])) : '-';
                $r['tgl_aju_fmt']    = ($r['tanggal_pernyataan'] !== '-') ? date('d/m/Y', strtotime($r['tanggal_pernyataan'])) : '-';
                $data[] = $r;
            }
        }
        echo json_encode(['status' => 'OK', 'data' => $data]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mencari dokumen staging AJU: ' . $e->getMessage()]);
    }
    exit;
}

// 5.2 GET DETAIL BARANG DARI STAGING CEISA SESUAI AJU
if ($action === 'get_staging_barang') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $nomorAju = trim($_GET['nomor_aju'] ?? '');
        if (empty($nomorAju)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong']);
            exit;
        }
        $escAju = $koneksi->real_escape_string($nomorAju);

        // Header Dokumen
        $hRes = $koneksi->query("SELECT * FROM bc_ceisa_header WHERE nomor_aju = '{$escAju}' LIMIT 1");
        if (!$hRes || $hRes->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU '$nomorAju' tidak ditemukan di staging header."]);
            exit;
        }
        $hdrStaging = $hRes->fetch_assoc();

        // Entitas Supplier / Pengirim
        $entRes = $koneksi->query("SELECT kode_entitas, nama_entitas, nomor_identitas, alamat_entitas 
                                  FROM bc_ceisa_entitas 
                                  WHERE nomor_aju = '{$escAju}' AND kode_entitas IN ('3','7','8','PENGIRIM','PEMASOK','PENJUAL','5','9','1') 
                                  ORDER BY FIELD(kode_entitas, '3','PEMASOK','7','8','PENGIRIM','PENJUAL','5','9','1') ASC LIMIT 1");
        $supplier = $entRes ? $entRes->fetch_assoc() : null;
        $supplierName = $supplier['nama_entitas'] ?? ($hdrStaging['nama_pernyataan'] ?? '');
        $supplierNpwp = preg_replace('/[^0-9]/', '', $supplier['nomor_identitas'] ?? '');

        // Cari Vendor yang cocok di bc_bs (prioritas NPWP, kemudian Nama)
        $matchedVendor = null;
        if (!empty($supplierNpwp)) {
            $bsQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, PAY_TERM, kurs FROM bc_bs 
                                    WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                                      AND REPLACE(REPLACE(REPLACE(NPWP, '.', ''), '-', ''), ' ', '') LIKE '%{$supplierNpwp}%' 
                                    LIMIT 1");
            if ($bsQ && $bsRow = $bsQ->fetch_assoc()) $matchedVendor = $bsRow;
        }
        if (!$matchedVendor && !empty($supplierName)) {
            $escSupName = $koneksi->real_escape_string(substr($supplierName, 0, 30));
            $bsQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, PAY_TERM, kurs FROM bc_bs 
                                    WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                                      AND (NAMA LIKE '%{$escSupName}%' OR NAMA_PENDEK LIKE '%{$escSupName}%') 
                                    LIMIT 1");
            if ($bsQ && $bsRow = $bsQ->fetch_assoc()) $matchedVendor = $bsRow;
        }

        // Dokumen Invoice / SJ
        $docQ = $koneksi->query("SELECT kode_dokumen, nomor_dokumen, tanggal_dokumen 
                                 FROM bc_ceisa_dokumen 
                                 WHERE nomor_aju = '{$escAju}' AND kode_dokumen IN ('230','380','217') 
                                 ORDER BY FIELD(kode_dokumen, '230','380','217') ASC LIMIT 1");
        $docRow = $docQ ? $docQ->fetch_assoc() : null;

        // Ambil Open POs untuk Matched Vendor
        $selectedPo = trim($_GET['po_no'] ?? '');
        $openPos = [];
        if ($matchedVendor && !empty($matchedVendor['KODE'])) {
            $escVk = $koneksi->real_escape_string($matchedVendor['KODE']);
            $poRes = $koneksi->query("SELECT p.id, p.po_no, p.po_dt, p.total_order, p.status,
                                            (SELECT COUNT(1) FROM bc_po_dtl d WHERE d.po_no = p.po_no) as jml_item
                                     FROM bc_po_hdr p
                                     WHERE (p.vendor = '{$escVk}' OR p.vendor = '{$koneksi->real_escape_string($matchedVendor['NAMA'])}')
                                       AND p.status IN ('0', '1')
                                       AND (p.cmp_id = '{$escCmp}' OR p.cmp_id = '' OR cmp_id IS NULL)
                                     ORDER BY p.po_dt DESC LIMIT 15");
            if ($poRes) {
                while ($pr = $poRes->fetch_assoc()) {
                    $pr['po_dt_fmt'] = !empty($pr['po_dt']) ? date('d/m/Y', strtotime($pr['po_dt'])) : '-';
                    $openPos[] = $pr;
                }
            }
        }
        if (empty($selectedPo) && count($openPos) === 1) {
            $selectedPo = $openPos[0]['po_no'];
        }

        // Ambil baris detail PO jika ada PO acuan yang dipilih
        $poItemsMap = [];
        if (!empty($selectedPo)) {
            $escSelPo = $koneksi->real_escape_string($selectedPo);
            $poDtlQ = $koneksi->query("SELECT d.id, d.id_hdr, d.po_no, d.kode_barang, d.nama_barang, d.qty,
                                              COALESCE((SELECT SUM(r.qty) FROM bc_ri_dtl r WHERE r.id_po = d.id OR (r.po_no = d.po_no AND r.kode_barang = d.kode_barang)), 0) as terima
                                       FROM bc_po_dtl d
                                       WHERE (d.po_no = '{$escSelPo}' OR d.id_hdr = '{$escSelPo}')
                                         AND (d.cmp_id = '{$escCmp}' OR d.cmp_id = '' OR d.cmp_id IS NULL)");
            if ($poDtlQ) {
                while ($prow = $poDtlQ->fetch_assoc()) {
                    $sisa = max(0, (float)$prow['qty'] - (float)$prow['terima']);
                    $prow['sisa_qty'] = $sisa;
                    $poItemsMap[strtoupper(trim($prow['kode_barang']))] = $prow;
                }
            }
        }

        // Ambil Daftar Barang Staging
        $bRes = $koneksi->query("SELECT * FROM bc_ceisa_barang WHERE nomor_aju = '{$escAju}' ORDER BY seri_barang ASC");
        $items = [];
        if ($bRes) {
            while ($b = $bRes->fetch_assoc()) {
                $seri = (int)$b['seri_barang'];
                $kode = trim($b['kode_barang']);
                $uraian = trim($b['uraian']);
                $qtyDok = (float)($b['jumlah_satuan'] ?? 0);
                $satuan = strtoupper(trim($b['kode_satuan'] ?? 'PCE'));
                $posTarif = trim($b['pos_tarif'] ?? ($b['hs'] ?? ''));
                $cif = (float)($b['cif'] ?? 0);
                $hargaPenyerahan = (float)($b['harga_penyerahan'] ?? 0);
                $netto = (float)($b['netto'] ?? 0);

                // Auto-map material master
                $escKode = $koneksi->real_escape_string($kode);
                $matQ = $koneksi->query("SELECT KODE_BARANG, URAIAN, KODE_SATUAN, UNIT_PRICE, KLAS, POS_TARIF 
                                        FROM bc_material 
                                        WHERE (KODE_BARANG = '{$escKode}' OR KODE_BARANG2 = '{$escKode}') 
                                          AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                                        ORDER BY (KODE_BARANG = '{$escKode}') DESC LIMIT 1");
                $matRow = $matQ ? $matQ->fetch_assoc() : null;

                $finalKode = $matRow['KODE_BARANG'] ?? $kode;
                $finalUraian = !empty($uraian) ? $uraian : ($matRow['URAIAN'] ?? '');
                $finalSat = !empty($satuan) ? $satuan : ($matRow['KODE_SATUAN'] ?? 'PCE');
                $price = 0;
                if ($qtyDok > 0) {
                    if ($hargaPenyerahan > 0) {
                        $price = $hargaPenyerahan / $qtyDok;
                    } elseif ($cif > 0) {
                        $price = $cif / $qtyDok;
                    }
                }
                if ($price <= 0 && $matRow && (float)$matRow['UNIT_PRICE'] > 0) {
                    $price = (float)$matRow['UNIT_PRICE'];
                }

                // Tautkan ke baris PO jika ditemukan kode barang yang sama
                $matchedPoItem = $poItemsMap[strtoupper($finalKode)] ?? null;
                $idPo       = $matchedPoItem ? $matchedPoItem['id'] : '';
                $poNoVal    = $matchedPoItem ? $matchedPoItem['po_no'] : (!empty($selectedPo) ? $selectedPo : '');
                $sisaPo     = $matchedPoItem ? $matchedPoItem['sisa_qty'] : null;
                $isPoLinked = !empty($idPo);

                $items[] = [
                    'seri_barang'  => $seri,
                    'kode_barang'  => $finalKode,
                    'uraian'       => $finalUraian,
                    'qty_dokumen'  => $qtyDok,
                    'qty_aktual'   => $qtyDok, // default fisik sama dengan dokumen
                    'qty'          => $qtyDok,
                    'sesuai'       => 'Y',
                    'memo'         => '',
                    'sat'          => $finalSat,
                    'price'        => $price,
                    'disc'         => 0,
                    'amount'       => $qtyDok * $price,
                    'netto'        => $netto,
                    'gross'        => $netto,
                    'cif'          => $cif,
                    'pos_tarif'    => !empty($posTarif) ? $posTarif : ($matRow['POS_TARIF'] ?? ''),
                    'gudang'       => 'UTAMA',
                    'rack'         => '',
                    'dept'         => '',
                    'note'         => $isPoLinked ? "Ref PO: {$poNoVal}" : '',
                    'id_po'        => $idPo,
                    'po_no'        => $poNoVal,
                    'sisa_po_qty'  => $sisaPo,
                    'is_po_linked' => $isPoLinked
                ];
            }
        }

        // Cek status IT Inventory di bc_1ab2
        $invQ = $koneksi->query("SELECT COUNT(1) AS jml, MAX(NO_BUKTI) AS no_bukti, MAX(TGL_PROSES) AS tgl_proses FROM bc_1ab2 WHERE nomor_aju = '{$escAju}'");
        $invInfo = $invQ ? $invQ->fetch_assoc() : null;
        $isPosted = !empty($invInfo['jml']) && (int)$invInfo['jml'] > 0;

        echo json_encode([
            'status'         => 'OK',
            'nomor_aju'      => $nomorAju,
            'header'         => $hdrStaging,
            'supplier'       => $supplier,
            'supplier_name'  => $supplierName,
            'matched_vendor' => $matchedVendor,
            'doc_reference'  => $docRow,
            'open_pos'       => $openPos,
            'selected_po'    => $selectedPo,
            'is_posted'      => $isPosted,
            'posted_info'    => $invInfo,
            'items'          => $items
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengambil detail barang staging: ' . $e->getMessage()]);
    }
    exit;
}

// 5.3 POSTING RECEIVE ITEM KE IT INVENTORY (bc_1ab2 - LAPORAN 1A PEMASUKAN)
if ($action === 'post_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'ID Dokumen Penerimaan Barang tidak valid']);
            exit;
        }
        $escId = $koneksi->real_escape_string($id);

        // Ambil header RI
        $hdrQ = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as vendor_nama 
                                FROM bc_ri_hdr h 
                                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                                WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL) LIMIT 1");
        if (!$hdrQ || $hdrQ->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data Penerimaan Barang tidak ditemukan']);
            exit;
        }
        $h = $hdrQ->fetch_assoc();

        if (!empty($h['is_it_inv'])) {
            echo json_encode(['status' => 'ERROR', 'message' => "Dokumen RI '{$h['ri_no']}' sudah berstatus TERPOSTING ke IT Inventory!"]);
            exit;
        }

        $riNo = $h['ri_no'];
        $riDt = !empty($h['ri_dt']) ? $h['ri_dt'] : date('Y-m-d');
        $noAju = trim($h['no_aju'] ?? '');
        $noBc = trim($h['no_bc'] ?? '');
        $tglBc = !empty($h['tgl_bc']) ? $h['tgl_bc'] : $riDt;
        $bcCode = trim($h['bc'] ?? 'BC 2.3');
        $kodeBcNum = preg_replace('/[^0-9]/', '', $bcCode);
        if (empty($kodeBcNum)) $kodeBcNum = '23';

        // Ambil Rincian Barang RI
        $dtlQ = $koneksi->query("SELECT d.*, m.KLAS as mat_klas, m.POS_TARIF as mat_hs 
                                FROM bc_ri_dtl d 
                                LEFT JOIN bc_material m ON (d.kode_barang = m.KODE_BARANG AND (m.cmp_id = '{$escCmp}' OR m.cmp_id = '' OR m.cmp_id IS NULL))
                                WHERE d.id_hdr = '{$escId}' 
                                ORDER BY d.seq ASC, d.id ASC");
        $items = [];
        if ($dtlQ) while ($r = $dtlQ->fetch_assoc()) $items[] = $r;

        if (empty($items)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Rincian barang masih kosong. Tidak dapat memposting ke IT Inventory.']);
            exit;
        }

        // Validasi: Wajib isi MEMO jika ada selisih Qty Dokumen vs Qty Aktual Fisik
        foreach ($items as $idx => $it) {
            $qtyDok = (float)($it['qty_dokumen'] > 0 ? $it['qty_dokumen'] : $it['qty']);
            $qtyAkt = (float)($it['qty_aktual'] > 0 ? $it['qty_aktual'] : $it['qty']);
            $memo   = trim($it['memo'] ?? '');
            if (abs($qtyAkt - $qtyDok) > 0.00001 && empty($memo)) {
                $seqNo = $it['seq'] ?: ($idx + 1);
                echo json_encode([
                    'status' => 'ERROR', 
                    'message' => "Baris #{$seqNo} ({$it['kode_barang']}) memiliki selisih fisik ({$qtyAkt} vs {$qtyDok}). Kolom MEMO wajib diisi alasan terjadinya selisih!"
                ]);
                exit;
            }
        }

        // Cek duplikasi posting di bc_1ab2
        $escRiNo = $koneksi->real_escape_string($riNo);
        $chkDup = $koneksi->query("SELECT COUNT(1) as total FROM bc_1ab2 WHERE NO_BUKTI = '{$escRiNo}' OR DOK = '{$escRiNo}'");
        if ($chkDup && (int)$chkDup->fetch_assoc()['total'] > 0) {
            // Hapus data posting lama dari RI ini agar bersih
            $koneksi->query("DELETE FROM bc_1ab2 WHERE NO_BUKTI = '{$escRiNo}' OR DOK = '{$escRiNo}'");
        }

        $koneksi->begin_transaction();

        $stmtInsert = $koneksi->prepare("
            INSERT INTO bc_1ab2 (
                cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS,
                KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER,
                SJ, INVOICE, FAKTUR, NOMOR_AJU, SERI_BARANG, HARGA_PENYERAHAN, CIF, NETTO,
                SESUAI, QTY_AKTUAL, MEMO, DOK, TGL_PROSES, CRT_DT, CRT_USER
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, NOW(), ?
            )
        ");

        if (!$stmtInsert) {
            throw new Exception("Prepare statement gagal: " . $koneksi->error);
        }

        $docCmp    = $h['cmp_id'] ?: $sessionCmpId;
        $supplier  = $h['vendor_nama'] ?: $h['vendor'];
        $valuta    = $h['kurs'] ?: 'IDR';
        $sj        = $h['sj_no'] ?: '';
        $invoice   = $h['po_no'] ?: '';
        $faktur    = '';
        $ab        = 'A'; // Incoming = Pemasukan (1A)
        $noBukti   = $riNo; // Nomor Bukti = Nomor RI
        $tglBukti  = $riDt; // Tanggal Bukti = Tanggal RI
        $dok       = $riNo; // DOK = Nomor RI
        $tglProses = $riDt; // Tgl Proses = Tanggal RI
        $postedCount = 0;

        foreach ($items as $idx => $it) {
            $seri = (int)($it['seri_barang'] ?: ($it['seq'] ?: ($idx + 1)));
            $qtyDok = (float)($it['qty_dokumen'] > 0 ? $it['qty_dokumen'] : $it['qty']);
            $qtyAkt = (float)($it['qty_aktual'] > 0 ? $it['qty_aktual'] : $it['qty']);
            $memo   = trim($it['memo'] ?? '');
            $sesuai = ($qtyAkt == $qtyDok && empty($memo)) ? 'Y' : 'N';

            $kodeBarang = $it['kode_barang'];
            $posTarif   = $it['pos_tarif'] ?: ($it['mat_hs'] ?? '');
            $uraian     = $it['uraian'];
            $klas       = $it['mat_klas'] ?: 'Raw Materials';
            $unit       = $it['sat'] ?: 'PCE';
            $nilai      = (float)($it['amount'] > 0 ? $it['amount'] : ($qtyAkt * (float)$it['price']));
            $idHeader   = !empty($noAju) ? $noAju : $riNo;
            $nomorAjuVal = !empty($noAju) ? $noAju : $riNo;
            $hargaPenyerahan = $qtyAkt * (float)$it['price'];
            $cif        = (float)($it['cif'] ?? 0);
            $netto      = (float)($it['netto'] ?? 0);

            $stmtInsert->bind_param(
                "ssssssssssssdsssssssiddssssssss",
                $docCmp, $kodeBcNum, $noBc, $tglBc, $noBukti, $tglBukti, $supplier,
                $kodeBarang, $posTarif, $uraian, $klas, $unit, $qtyDok, $valuta, $nilai, $ab, $idHeader,
                $sj, $invoice, $faktur, $nomorAjuVal, $seri, $hargaPenyerahan, $cif, $netto,
                $sesuai, $qtyAkt, $memo, $dok, $tglProses, $currentUser
            );

            if (!$stmtInsert->execute()) {
                throw new Exception("Gagal insert item #{$seri}: " . $stmtInsert->error);
            }
            $postedCount++;
        }
        $stmtInsert->close();

        // Tandai header bc_ri_hdr sebagai terposting
        $koneksi->query("UPDATE bc_ri_hdr SET is_it_inv = 1, it_inv_no = '{$escRiNo}', it_inv_dt = NOW(), status = '1' WHERE id = '{$escId}'");

        // Jika ada nomor aju, catat juga di bc_ceisa_aju_posted
        if (!empty($noAju)) {
            $escAju = $koneksi->real_escape_string($noAju);
            $koneksi->query("INSERT INTO bc_ceisa_aju_posted (cmp_id, nomor_aju, waktu, bc, nomor_daftar, tanggal_daftar) 
                             VALUES ('{$docCmp}', '{$escAju}', NOW(), '{$kodeBcNum}', '{$koneksi->real_escape_string($noBc)}', '{$tglBc}')
                             ON DUPLICATE KEY UPDATE waktu = NOW(), nomor_daftar = VALUES(nomor_daftar), tanggal_daftar = VALUES(tanggal_daftar)");
        }

        $koneksi->commit();
        echo json_encode([
            'status'       => 'OK',
            'posted_count' => $postedCount,
            'ri_no'        => $riNo,
            'message'      => "Berhasil memposting {$postedCount} item barang ke IT Inventory (bc_1ab2 - Laporan 1A) dengan No. Bukti '{$riNo}'!"
        ]);
    } catch (\Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal posting ke IT Inventory: ' . $e->getMessage()]);
    }
    exit;
}

// 5.4 BATALKAN POSTING IT INVENTORY (UNPOST bc_1ab2 & BUKA KUNCI EDIT)
if ($action === 'unpost_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'ID Dokumen Penerimaan Barang tidak valid']);
            exit;
        }
        $escId = $koneksi->real_escape_string($id);

        $hdrQ = $koneksi->query("SELECT ri_no, no_aju FROM bc_ri_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if (!$hdrQ || $hdrQ->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data Penerimaan Barang tidak ditemukan']);
            exit;
        }
        $h = $hdrQ->fetch_assoc();
        $riNo = $h['ri_no'];
        $noAju = trim($h['no_aju'] ?? '');
        $escRiNo = $koneksi->real_escape_string($riNo);

        $koneksi->begin_transaction();

        // 1. Hapus transaksi pemasukan di bc_1ab2
        $delSql = "DELETE FROM bc_1ab2 WHERE NO_BUKTI = '{$escRiNo}' OR DOK = '{$escRiNo}'";
        if (!empty($noAju)) {
            $escAju = $koneksi->real_escape_string($noAju);
            $delSql .= " OR (nomor_aju = '{$escAju}' AND (NO_BUKTI = '{$escRiNo}' OR DOK = '{$escRiNo}'))";
        }
        $koneksi->query($delSql);

        // 2. Hapus status posting aju jika relevan
        if (!empty($noAju)) {
            $escAju = $koneksi->real_escape_string($noAju);
            // Cek apakah masih ada posting lain untuk AJU ini
            $chkAju = $koneksi->query("SELECT COUNT(1) as sisa FROM bc_1ab2 WHERE nomor_aju = '{$escAju}'");
            if ($chkAju && (int)$chkAju->fetch_assoc()['sisa'] === 0) {
                $koneksi->query("DELETE FROM bc_ceisa_aju_posted WHERE nomor_aju = '{$escAju}'");
            }
        }

        // 3. Update status bc_ri_hdr
        $koneksi->query("UPDATE bc_ri_hdr SET is_it_inv = 0, it_inv_no = '', it_inv_dt = NULL WHERE id = '{$escId}'");

        $koneksi->commit();
        echo json_encode([
            'status'  => 'OK',
            'ri_no'   => $riNo,
            'message' => "Posting IT Inventory untuk Dokumen RI '{$riNo}' berhasil dibatalkan. Dokumen kini dapat diedit kembali."
        ]);
    } catch (\Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal membatalkan posting: ' . $e->getMessage()]);
    }
    exit;
}

// 6. SIMPAN TRANSAKSI RECEIVE ITEM (INSERT / UPDATE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $raw = file_get_contents('php://input');
        $post = json_decode($raw, true) ?: $_POST;

        $id = trim($post['id'] ?? '');
        $isEdit = !empty($id);
        if (!$isEdit) {
            $id = bin2hex(random_bytes(16));
        }
        $escId = $koneksi->real_escape_string($id);

        // Kunci Integritas: Cek apakah dokumen ini sudah diposting ke IT Inventory
        if ($isEdit) {
            $chkLock = $koneksi->query("SELECT is_it_inv, ri_no FROM bc_ri_hdr WHERE id = '{$escId}' LIMIT 1");
            if ($chkLock && $lRow = $chkLock->fetch_assoc()) {
                if (!empty($lRow['is_it_inv'])) {
                    echo json_encode(['status' => 'ERROR', 'message' => "Dokumen '{$lRow['ri_no']}' telah TERKUNCI karena sudah diposting ke IT Inventory (bc_1ab2). Batalkan posting (Unpost) terlebih dahulu jika ingin mengubah data."]);
                    exit;
                }
            }
        }

        $riNo = strtoupper(trim($post['ri_no'] ?? ''));
        if (empty($riNo)) {
            // Auto number: RI-YYMM-XXXX
            $ym = date('ym');
            $numRes = $koneksi->query("SELECT MAX(CAST(SUBSTRING(ri_no, 9) AS UNSIGNED)) as max_num FROM bc_ri_hdr WHERE cmp_id = '{$escCmp}' AND ri_no LIKE 'RI-{$ym}-%'");
            $next = ($numRes && ($row = $numRes->fetch_assoc()) && $row['max_num']) ? ((int)$row['max_num'] + 1) : 1;
            $riNo = sprintf('RI-%s-%04d', $ym, $next);
        }
        $escRiNo = $koneksi->real_escape_string($riNo);

        $riDt      = !empty($post['ri_dt']) ? $koneksi->real_escape_string($post['ri_dt']) : date('Y-m-d');
        $shipDt    = !empty($post['ship_dt']) ? "'" . $koneksi->real_escape_string($post['ship_dt']) . "'" : "NULL";
        $sjNo      = trim($post['sj_no'] ?? '');
        $poNo      = trim($post['po_no'] ?? '');
        $vendor    = trim($post['vendor'] ?? '');
        $term      = trim($post['term'] ?? 'Net 30');
        $ship      = trim($post['ship'] ?? '');
        $fob       = trim($post['fob'] ?? '1');
        $gudang    = trim($post['gudang'] ?? 'UTAMA');
        $kurs      = strtoupper(trim($post['kurs'] ?? 'IDR'));
        $rate      = (float)($post['rate'] ?? 1);
        $note      = trim($post['note'] ?? '');
        $subtotal  = (float)($post['subtotal'] ?? 0);
        $discP     = (float)($post['disc_persen'] ?? 0);
        $discA     = (float)($post['disc_amt'] ?? 0);
        $ppn       = (float)($post['ppn'] ?? 0);
        $totalO    = (float)($post['total_order'] ?? 0);
        $bc        = trim($post['bc'] ?? '');
        $noBc      = trim($post['no_bc'] ?? '');
        $tglBc     = !empty($post['tgl_bc']) ? "'" . $koneksi->real_escape_string($post['tgl_bc']) . "'" : "NULL";
        $noAju     = trim($post['no_aju'] ?? '');
        $status    = trim($post['status'] ?? '1'); // Default '1' = Diterima/In Stock
        $setby     = $currentUser . ' - ' . date('Ymd H:i');
        $cmpVal    = $sessionCmpId ?: '';

        if (empty($vendor)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Supplier / Vendor pengirim wajib dipilih!']);
            exit;
        }

        $items = $post['items'] ?? [];
        if (is_string($items)) $items = json_decode($items, true);
        if (!is_array($items) || count($items) === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Harap masukkan minimal 1 baris rincian barang yang diterima.']);
            exit;
        }

        // Cek duplikasi nomor RI untuk insert baru
        if (!$isEdit) {
            $chk = $koneksi->query("SELECT 1 FROM bc_ri_hdr WHERE ri_no = '{$escRiNo}' AND cmp_id = '{$koneksi->real_escape_string($cmpVal)}' LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                echo json_encode(['status' => 'ERROR', 'message' => "Nomor Dokumen Penerimaan '$riNo' sudah terdaftar. Gunakan nomor lain."]);
                exit;
            }
        }

        if ($isEdit) {
            $stmt = $koneksi->prepare("UPDATE bc_ri_hdr SET
                ri_no = ?, ri_dt = ?, ship_dt = $shipDt, sj_no = ?, po_no = ?, vendor = ?,
                term = ?, ship = ?, fob = ?, gudang = ?, kurs = ?, rate = ?, note = ?,
                subtotal = ?, disc_persen = ?, disc_amt = ?, ppn = ?, total_order = ?,
                bc = ?, no_bc = ?, tgl_bc = $tglBc, no_aju = ?, status = ?, set_by = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
            $stmt->bind_param("ssssssssssdssddddsssssss",
                $riNo, $riDt, $sjNo, $poNo, $vendor,
                $term, $ship, $fob, $gudang, $kurs, $rate, $note,
                $subtotal, $discP, $discA, $ppn, $totalO,
                $bc, $noBc, $noAju, $status, $setby,
                $id, $cmpVal
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui header RI: ' . $err]);
                exit;
            }
            $stmt->close();
        } else {
            $stmt = $koneksi->prepare("INSERT INTO bc_ri_hdr (
                id, cmp_id, ri_no, ri_dt, ship_dt, sj_no, po_no, vendor,
                term, ship, fob, gudang, kurs, rate, note,
                subtotal, disc_persen, disc_amt, ppn, total_order,
                bc, no_bc, tgl_bc, no_aju, status, set_by
            ) VALUES (
                ?, ?, ?, ?, $shipDt, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, $tglBc, ?, ?, ?
            )");
            $stmt->bind_param("ssssssssssssdssddddsssss",
                $id, $cmpVal, $riNo, $riDt, $sjNo, $poNo, $vendor,
                $term, $ship, $fob, $gudang, $kurs, $rate, $note,
                $subtotal, $discP, $discA, $ppn, $totalO,
                $bc, $noBc, $noAju, $status, $setby
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan header RI: ' . $err]);
                exit;
            }
            $stmt->close();
        }

        // Hapus detail lama untuk di-replace rincian baru
        $koneksi->query("DELETE FROM bc_ri_dtl WHERE id_hdr = '{$escId}'");

        $seq = 1;
        $stmtDtl = $koneksi->prepare("INSERT INTO bc_ri_dtl (
            id, id_hdr, cmp_id, ri_no, seq, id_po, po_no,
            kode_barang, uraian, qty, qty_dokumen, qty_aktual, sesuai, memo, seri_barang, pos_tarif,
            sat, price, disc, amount,
            netto, gross, cif, cif_rupiah, gudang, rack, dept, note, status
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?
        )");

        foreach ($items as $item) {
            $dtlId = bin2hex(random_bytes(16));
            $idPo       = trim($item['id_po'] ?? '');
            $dtlPoNo    = trim($item['po_no'] ?? $poNo);
            $kodeBarang = trim($item['kode_barang'] ?? '');
            $uraian     = trim($item['uraian'] ?? '');
            $qtyAkt     = (float)($item['qty_aktual'] ?? ($item['qty'] ?? 0));
            $qtyDok     = (float)($item['qty_dokumen'] ?? $qtyAkt);
            $qty        = $qtyAkt; // Qty riil yang diterima
            $memo       = trim($item['memo'] ?? '');
            $sesuai     = trim($item['sesuai'] ?? (($qtyAkt == $qtyDok && empty($memo)) ? 'Y' : 'N'));
            $seriBarang = (int)($item['seri_barang'] ?? 0);
            $posTarif   = trim($item['pos_tarif'] ?? '');
            $sat        = strtoupper(trim($item['sat'] ?? 'PCE'));
            $price      = (float)($item['price'] ?? 0);
            $disc       = (float)($item['disc'] ?? 0);
            $amount     = (float)($item['amount'] ?? ($qty * $price * (1 - $disc / 100)));
            $netto      = (float)($item['netto'] ?? 0);
            $gross      = (float)($item['gross'] ?? 0);
            $cif        = (float)($item['cif'] ?? 0);
            $cifRupiah  = (float)($item['cif_rupiah'] ?? ($amount * $rate));
            $itemGudang = trim($item['gudang'] ?? $gudang);
            $rack       = trim($item['rack'] ?? '');
            $dept       = trim($item['dept'] ?? '');
            $itemNote   = trim($item['note'] ?? '');
            $dtlStatus  = '0';

            if (!empty($kodeBarang) && $qty > 0) {
                $stmtDtl->bind_param("ssssissssdddssissddddddsssss",
                    $dtlId, $id, $cmpVal, $riNo, $seq, $idPo, $dtlPoNo,
                    $kodeBarang, $uraian, $qty, $qtyDok, $qtyAkt, $sesuai, $memo, $seriBarang, $posTarif,
                    $sat, $price, $disc, $amount,
                    $netto, $gross, $cif, $cifRupiah, $itemGudang, $rack, $dept, $itemNote, $dtlStatus
                );
                $stmtDtl->execute();
                $seq++;
            }
        }
        $stmtDtl->close();

        // Rekalkulasi akumulasi penerimaan di tabel PO Detail (bc_po_dtl.terima) & Status PO Header (Auto-Close PO jika komplit)
        $affectedPos = [];
        if (!empty($poNo)) $affectedPos[] = $poNo;
        foreach ($items as $item) {
            $ipn = trim($item['po_no'] ?? '');
            if (!empty($ipn)) $affectedPos[] = $ipn;
        }
        syncPoStatusAndOutstanding($koneksi, $affectedPos, $cmpVal);

        // Auto Sync Lot Warehouse untuk Cetak Label QR & Traceability
        require_once __DIR__ . '/lot_helper.php';
        $lotSyncResult = syncRiToLots($koneksi, $id);

        echo json_encode([
            'status'   => 'OK',
            'id'       => $id,
            'ri_no'    => $riNo,
            'lot_sync' => $lotSyncResult,
            'message'  => "Penerimaan Barang '$riNo' berhasil disimpan."
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage()]);
    }
    exit;
}

// 7. HAPUS TRANSAKSI RECEIVE ITEM
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = trim($_POST['id'] ?? '');
        if (empty($id)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'ID Penerimaan Barang tidak valid']);
            exit;
        }
        $escId = $koneksi->real_escape_string($id);

        // Ambil data nomor RI dan PO acuan sebelum dihapus
        $riQ = $koneksi->query("SELECT ri_no, po_no, is_it_inv FROM bc_ri_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
        if (!$riQ || $riQ->num_rows === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data penerimaan tidak ditemukan']);
            exit;
        }
        $riRow = $riQ->fetch_assoc();
        $riNo = $riRow['ri_no'];
        $poNo = $riRow['po_no'];

        // Cek apakah sudah terposting di IT Inventory (bc_1ab2)
        if (!empty($riRow['is_it_inv'])) {
            echo json_encode(['status' => 'ERROR', 'message' => "Dokumen '$riNo' tidak dapat dihapus karena sudah diposting ke IT Inventory (bc_1ab2). Batalkan posting (Unpost) terlebih dahulu."]);
            exit;
        }

        // Cek integritas transaksi: Apakah sudah ditagih di Purchase Invoice (bc_pi_dtl)?
        $checkPi = $koneksi->query("SELECT 1 FROM bc_pi_dtl WHERE id_ri = '{$escId}' OR ri_no = '{$koneksi->real_escape_string($riNo)}' LIMIT 1");
        if ($checkPi && $checkPi->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Tidak dapat dihapus: Penerimaan '$riNo' sudah pernah ditagih pada Faktur Pembelian (Purchase Invoice / PI)."]);
            exit;
        }

        // Kumpulkan seluruh nomor PO sebelum dihapus untuk rekalkulasi status
        $affectedPos = [];
        if (!empty($poNo)) $affectedPos[] = $poNo;
        $itemPosQ = $koneksi->query("SELECT DISTINCT po_no FROM bc_ri_dtl WHERE id_hdr = '{$escId}' AND po_no <> ''");
        if ($itemPosQ) {
            while ($ipr = $itemPosQ->fetch_assoc()) $affectedPos[] = $ipr['po_no'];
        }

        $koneksi->query("DELETE FROM bc_ri_dtl WHERE id_hdr = '{$escId}'");
        $del = $koneksi->query("DELETE FROM bc_ri_hdr WHERE id = '{$escId}'");

        // Bersihkan lot warehouse yang belum terpakai
        require_once __DIR__ . '/lot_helper.php';
        deleteWhLotsByRef($koneksi, $escCmp, 'RI', $riNo);

        // Sinkronkan ulang kolom terima dan status PO (buka kembali PO jika tuntas dihapus)
        syncPoStatusAndOutstanding($koneksi, $affectedPos, $escCmp);

        if ($del) {
            echo json_encode(['status' => 'OK', 'message' => "Penerimaan Barang '$riNo' berhasil dihapus."]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
    }
    exit;
}

// 8. EXPORT CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Receive_Item_GRN_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['No', 'No RI / GRN', 'Tanggal Terima', 'No SJ Vendor', 'No PO Acuan', 'Kode Supplier', 'Nama Supplier', 'Valuta', 'Total Nilai', 'Gudang', 'Dokumen BC', 'No BC', 'Tgl BC', 'No Aju 26 Dig', 'Status']);

    $w = !empty($sessionCmpId) ? "WHERE (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)" : "";
    $q = $koneksi->query("SELECT h.*, COALESCE(b.NAMA, h.vendor) as vendor_nama 
                          FROM bc_ri_hdr h 
                          LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                          $w ORDER BY h.ri_dt DESC, h.ri_no DESC");
    $no = 1;
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $stText = ($r['status'] === '2') ? 'Ditagih (Invoiced)' : (($r['status'] === '1') ? 'In Stock (Diterima)' : 'Draft');
            fputcsv($out, [
                $no++,
                $r['ri_no'],
                $r['ri_dt'],
                $r['sj_no'],
                $r['po_no'],
                $r['vendor'],
                $r['vendor_nama'],
                $r['kurs'],
                $r['total_order'],
                $r['gudang'],
                $r['bc'],
                $r['no_bc'],
                $r['tgl_bc'],
                $r['no_aju'],
                $stText
            ]);
        }
    }
    fclose($out);
    exit;
}

// 9. PRINT VIEW: BUKTI PENERIMAAN BARANG (BPB / GRN FORM)
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrQ = $koneksi->query("SELECT h.*, 
                                    COALESCE(b.NAMA, h.vendor) as vendor_nama, 
                                    b.NAMA_PENDEK as vendor_alias,
                                    b.ALAMAT as vendor_alamat, 
                                    b.NPWP as vendor_npwp, 
                                    b.nitku as vendor_nitku
                             FROM bc_ri_hdr h
                             LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                             WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL) 
                             LIMIT 1");
    if (!$hdrQ || $hdrQ->num_rows === 0) {
        die("Data Penerimaan Barang tidak ditemukan.");
    }
    $hdr = $hdrQ->fetch_assoc();

    $dtlQ = $koneksi->query("SELECT * FROM bc_ri_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC, id ASC");
    $items = [];
    if ($dtlQ) while ($r = $dtlQ->fetch_assoc()) $items[] = $r;

    // Profil Perusahaan & Logo
    $cmpProfile = [];
    $cmpQ = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($cmpQ && $c = $cmpQ->fetch_assoc()) $cmpProfile = $c;
    $cmpName = $cmpProfile['cmp_name'] ?? $activeCmpName;
    $cmpAddr = $cmpProfile['cmp_address'] ?? 'Kawasan Berikat, Indonesia';
    $cmpNpwp = $cmpProfile['npwp'] ?? '-';
    $cmpNib  = $cmpProfile['nib'] ?? '-';
    $cmpLogo = trim($cmpProfile['logo'] ?? '');
    if (!empty($cmpLogo) && !preg_match('#^(https?://|/)#i', $cmpLogo)) {
        $cmpLogo = '/' . $cmpLogo;
    }
    if (empty($cmpLogo)) $cmpLogo = '/assets/logo.png';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Bukti Penerimaan Barang - <?= htmlspecialchars($hdr['ri_no'] ?? 'Draft') ?></title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', Arial, sans-serif; }
            body { font-size: 11px; color: #111; padding: 20px; line-height: 1.35; background: #fff; }
            .bpb-box { max-width: 840px; margin: 0 auto; border: 1px solid #333; padding: 18px; }
            .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .hdr-table td { vertical-align: top; }
            .title-box { text-align: right; }
            .title-doc { font-size: 15px; font-weight: 800; color: #0284c7; text-transform: uppercase; letter-spacing: 0.5px; }
            .no-doc { font-size: 12px; font-weight: 700; color: #111; margin-top: 2px; }
            .sub-doc { font-size: 9.5px; color: #555; }
            .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .info-grid td { padding: 4px 6px; border: 1px solid #ccc; font-size: 10px; vertical-align: top; }
            .info-head { background: #f1f5f9; font-weight: 700; color: #334155; }
            table.items-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            table.items-table th, table.items-table td { border: 1px solid #333; padding: 4px 6px; font-size: 10px; }
            table.items-table th { background: #f8fafc; font-weight: 700; text-align: center; }
            .num { text-align: right; }
            .center { text-align: center; }
            .summary-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            .summary-table td { padding: 2px 6px; font-size: 10px; }
            .sign-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 22px; text-align: center; font-size: 9.5px; }
            .sign-box { height: 60px; display: flex; align-items: center; justify-content: center; }
            @media print {
                body { padding: 0; }
                .bpb-box { border: none; padding: 0; }
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="max-width:840px; margin:0 auto 12px auto; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:12px; font-weight:600; color:#0284c7;"><i class="fa-solid fa-boxes-packing"></i> Preview Dokumen Cetak Bukti Penerimaan Barang</span>
            <button onclick="window.print()" style="padding:6px 18px; font-size:12px; font-weight:700; background:#0284c7; color:#fff; border:none; border-radius:3px; cursor:pointer;">
                <i class="fa-solid fa-print"></i> Cetak Dokumen (Ctrl + P)
            </button>
        </div>

        <div class="bpb-box">
            <!-- Header Surat -->
            <table class="hdr-table">
                <tr>
                    <td style="width:62%; vertical-align:middle;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if (!empty($cmpLogo)): ?>
                            <img src="<?= htmlspecialchars($cmpLogo) ?>" alt="Logo Perusahaan" style="max-height:50px; max-width:130px; object-fit:contain;">
                            <?php endif; ?>
                            <div>
                                <strong style="font-size:13px; text-transform:uppercase; color:#0f172a;"><?= htmlspecialchars($cmpName) ?></strong><br>
                                <span style="font-size:9.5px; color:#475569; line-height:1.3; display:inline-block;"><?= nl2br(htmlspecialchars($cmpAddr)) ?></span><br>
                                <span style="font-size:9.5px; color:#475569;">NPWP: <strong><?= htmlspecialchars($cmpNpwp) ?></strong> | NIB: <strong><?= htmlspecialchars($cmpNib) ?></strong></span>
                            </div>
                        </div>
                    </td>
                    <td class="title-box" style="width:38%; vertical-align:middle;">
                        <div class="title-doc">BUKTI PENERIMAAN BARANG</div>
                        <div class="no-doc">NO: <?= htmlspecialchars($hdr['ri_no'] ?? '-') ?></div>
                        <div class="sub-doc">Goods Receipt Note (GRN) / Receive Item</div>
                    </td>
                </tr>
            </table>

            <!-- Identitas Penerimaan & Kepabeanan -->
            <table class="info-grid">
                <tr>
                    <td class="info-head" style="width:50%;">INFORMASI SUPPLIER / PENGIRIM:</td>
                    <td class="info-head" style="width:50%;">SURAT JALAN &amp; KEPABEANAN (CEISA 4.0):</td>
                </tr>
                <tr>
                    <td>
                        <strong style="font-size:11px;"><?= htmlspecialchars($hdr['vendor_nama'] ?? $hdr['vendor'] ?? '-') ?></strong>
                        <?php if (!empty($hdr['vendor_alias'])): ?>
                            <span style="color:#64748b;">(<?= htmlspecialchars($hdr['vendor_alias']) ?>)</span>
                        <?php endif; ?><br>
                        <?= nl2br(htmlspecialchars($hdr['vendor_alamat'] ?? '')) ?><br>
                        NPWP: <strong><?= htmlspecialchars($hdr['vendor_npwp'] ?? '-') ?></strong>
                        <?= !empty($hdr['vendor_nitku']) ? ' | NITKU: ' . htmlspecialchars($hdr['vendor_nitku']) : '' ?>
                    </td>
                    <td>
                        <div style="display:grid; grid-template-columns:110px 1fr; gap:2px;">
                            <span>Tanggal Terima:</span><strong><?= !empty($hdr['ri_dt']) ? date('d F Y', strtotime($hdr['ri_dt'])) : '-' ?></strong>
                            <span>No. Surat Jalan:</span><strong><?= htmlspecialchars($hdr['sj_no'] ?: '-') ?></strong>
                            <span>Tgl Surat Jalan:</span><span><?= !empty($hdr['ship_dt']) ? date('d/m/Y', strtotime($hdr['ship_dt'])) : '-' ?></span>
                            <span>No. PO Acuan:</span><strong><?= htmlspecialchars($hdr['po_no'] ?: '-') ?></strong>
                            <span>Gudang Tujuan:</span><strong><?= htmlspecialchars($hdr['gudang'] ?: 'UTAMA') ?></strong>
                            <span>Dokumen Pabean:</span><span><strong><?= htmlspecialchars($hdr['bc'] ?: 'NON-BC') ?></strong> <?= !empty($hdr['no_bc']) ? 'No: ' . htmlspecialchars($hdr['no_bc']) : '' ?> <?= !empty($hdr['tgl_bc']) ? '(' . date('d/m/Y', strtotime($hdr['tgl_bc'])) . ')' : '' ?></span>
                            <?php if (!empty($hdr['no_aju'])): ?>
                            <span>No. Pengajuan:</span><code style="font-size:9px;"><?= htmlspecialchars($hdr['no_aju']) ?></code>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Tabel Rincian Barang Diterima -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:28px;">#</th>
                        <th style="width:120px;">Kode Barang</th>
                        <th>Nama &amp; Spesifikasi Barang</th>
                        <th style="width:55px;">Qty</th>
                        <th style="width:45px;">Sat</th>
                        <th style="width:85px;">Harga (<?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?>)</th>
                        <th style="width:40px;">Disc%</th>
                        <th style="width:95px;">Total Nilai</th>
                        <th style="width:75px;">Lokasi / Rak</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="center" style="padding:16px; color:#888;">Tidak ada rincian barang.</td></tr>
                    <?php else: 
                        $tQty = 0;
                        $tAmt = 0;
                        foreach ($items as $idx => $it):
                            $tQty += (float)$it['qty'];
                            $tAmt += (float)$it['amount'];
                    ?>
                    <tr>
                        <td class="center"><?= $idx + 1 ?></td>
                        <td><strong><?= htmlspecialchars($it['kode_barang']) ?></strong></td>
                        <td>
                            <strong><?= htmlspecialchars($it['uraian']) ?></strong>
                            <?php if (!empty($it['note'])): ?>
                                <br><span style="font-size:8.5px; color:#555;">Note: <?= htmlspecialchars($it['note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><strong><?= number_format((float)$it['qty'], 2, '.', ',') ?></strong></td>
                        <td class="center"><?= htmlspecialchars($it['sat']) ?></td>
                        <td class="num"><?= number_format((float)$it['price'], 2, '.', ',') ?></td>
                        <td class="num"><?= number_format((float)$it['disc'], 1) ?>%</td>
                        <td class="num"><strong><?= number_format((float)$it['amount'], 2, '.', ',') ?></strong></td>
                        <td class="center"><?= htmlspecialchars($it['rack'] ?: '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:bold; background:#f8fafc;">
                        <td colspan="3" class="num">TOTAL KUANTITAS:</td>
                        <td class="num"><?= number_format($tQty, 2, '.', ',') ?></td>
                        <td></td>
                        <td colspan="2" class="num">SUBTOTAL:</td>
                        <td class="num"><?= number_format($tAmt, 2, '.', ',') ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Ringkasan & Catatan -->
            <table class="summary-table">
                <tr>
                    <td style="width:55%; vertical-align:top;">
                        <strong>Catatan Penerimaan Gudang:</strong><br>
                        <span style="color:#334155;"><?= nl2br(htmlspecialchars($hdr['note'] ?: 'Barang telah diperiksa fisik, kondisi utuh, dan sesuai spesifikasi PO.')) ?></span>
                    </td>
                    <td style="width:45%; vertical-align:top;">
                        <table style="width:100%; border-collapse:collapse;">
                            <tr><td>Subtotal Barang</td><td class="num"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)($hdr['subtotal'] ?? $tAmt), 2, '.', ',') ?></td></tr>
                            <?php if ((float)($hdr['disc_amt'] ?? 0) > 0): ?>
                            <tr><td>Diskon Faktur</td><td class="num">- <?= number_format((float)$hdr['disc_amt'], 2, '.', ',') ?></td></tr>
                            <?php endif; ?>
                            <?php if ((float)($hdr['ppn'] ?? 0) > 0): ?>
                            <tr><td>PPN</td><td class="num"><?= number_format((float)$hdr['ppn'], 2, '.', ',') ?></td></tr>
                            <?php endif; ?>
                            <tr style="font-weight:700; font-size:11px; border-top:1px solid #333;">
                                <td>TOTAL PENERIMAAN</td>
                                <td class="num"><?= htmlspecialchars($hdr['kurs'] ?? 'IDR') ?> <?= number_format((float)($hdr['total_order'] ?? $tAmt), 2, '.', ',') ?></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <!-- 4 Kolom Tanda Tangan Resmi Sesuai SOP Gudang Pabrik TPB -->
            <div class="sign-grid">
                <div>
                    Diterima Oleh (Gudang):<br>
                    <div class="sign-box"></div>
                    ( .................................... )<br>
                    <span style="font-size:8.5px; color:#666;">Petugas Gudang Bahan</span>
                </div>
                <div>
                    Diperiksa QC Inspector:<br>
                    <div class="sign-box"></div>
                    ( .................................... )<br>
                    <span style="font-size:8.5px; color:#666;">Quality Control Fisik</span>
                </div>
                <div>
                    Bagian Pembelian (Purchasing):<br>
                    <div class="sign-box"></div>
                    ( .................................... )<br>
                    <span style="font-size:8.5px; color:#666;">Staff Purchasing</span>
                </div>
                <div>
                    Mengetahui (Head of Plant/WH):<br>
                    <div class="sign-box"></div>
                    ( .................................... )<br>
                    <span style="font-size:8.5px; color:#666;">Kepala Gudang / Pabrik</span>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------------------
// PRELOAD DATA DROPDOWN FORM TRANSAKSI
// -------------------------------------------------------------------------
// 1. Supplier / Vendor Aktif dari bc_bs
$suppliers = [];
$supQ = $koneksi->query("SELECT KODE, NAMA, NAMA_PENDEK, ALAMAT, NPWP, nitku, PAY_TERM, kurs FROM bc_bs WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) AND (TIPE = 'S' OR TIPE = 'B/S') ORDER BY NAMA ASC");
if ($supQ) while ($r = $supQ->fetch_assoc()) $suppliers[] = $r;

// 2. Gudang Penerima Aktif dari bc_dept (gudang = 'Y')
$gudangList = [];
$gdQ = $koneksi->query("SELECT kode, nama_dept FROM bc_dept WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) AND gudang = 'Y' AND (aktif = 'Y' OR aktif IS NULL) ORDER BY kode ASC");
if ($gdQ && $gdQ->num_rows > 0) {
    while ($r = $gdQ->fetch_assoc()) $gudangList[] = $r['kode'];
}
if (empty($gudangList)) $gudangList = ['UTAMA', 'GUDANG BAHAN BAKU', 'GUDANG CHEMICAL', 'GUDANG PACKAGING', 'GUDANG SPAREPART'];

// 3. Jasa Pengiriman dari bc_ref_jasakirim
$jasakirimList = [];
$jkQ = $koneksi->query("SELECT kode, nama FROM bc_ref_jasakirim WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) AND (status = 1 OR status IS NULL) ORDER BY kode ASC");
if ($jkQ) while ($r = $jkQ->fetch_assoc()) $jasakirimList[] = $r;

// 4. Termin Pembayaran dari bc_ref_term
$termList = [];
$tQ = $koneksi->query("SELECT kode, note FROM bc_ref_term WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) AND (status = 1 OR status IS NULL) ORDER BY jatuhtempo ASC, kode ASC");
if ($tQ) while ($r = $tQ->fetch_assoc()) $termList[] = $r;

// 5. Valuta & Kurs dari bc_ref_kurs
$kursList = [];
$kQ = $koneksi->query("SELECT nama, simbol FROM bc_ref_kurs WHERE (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) AND (status = 1 OR status IS NULL) ORDER BY is_default DESC, nama ASC");
if ($kQ) while ($r = $kQ->fetch_assoc()) $kursList[] = $r['nama'];
if (empty($kursList)) $kursList = ['IDR', 'USD', 'EUR', 'JPY', 'SGD', 'CNY'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receive Item (GRN) - <?= htmlspecialchars($activeCmpName) ?></title>
    <script>
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
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>
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
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-head-bg: #fafafa;
            --table-hover: #f0f9ff;
            --row-selected: #e0f2fe;
            --row-selected-text: #0369a1;
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
            --primary: #38bdf8;
            --primary-hover: #0284c7;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --table-head-bg: #1d1d1d;
            --table-hover: #262626;
            --row-selected: #0c4a6e;
            --row-selected-text: #ffffff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; background: var(--bg-base); color: var(--text-main); font-family: var(--app-font, 'Poppins', sans-serif); font-size: 10px; }
        input, select, textarea, button, table, .ant-container, .ant-card, .ant-toolbar, .fld-row input, .fld-row select, table.dw {
            font-family: var(--app-font, 'Poppins', sans-serif);
        }

        input[type="number"] { -moz-appearance: textfield; }
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        .ant-container { height: 100%; display: flex; flex-direction: column; padding: 6px; gap: 0; }
        .ant-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .ant-card-head { min-height: 34px; padding: 4px 10px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; background: var(--bg-card); }
        .ant-card-title { font-size: 12px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }

        .ant-badge { font-size: 9px; padding: 1px 6px; border-radius: 10px; font-weight: 600; line-height: 1.2; text-transform: uppercase; }
        .ant-badge-company { background: rgba(2, 132, 199, 0.12); color: var(--primary); border: 1px solid rgba(2, 132, 199, 0.3); }
        .ant-badge-success { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.3); }
        .ant-badge-warning { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.3); }
        .ant-badge-danger { background: rgba(255, 77, 79, 0.12); color: var(--danger); border: 1px solid rgba(255, 77, 79, 0.3); }

        /* Tabs Navigasi Utama */
        .ant-tabs-nav { display: flex; gap: 2px; padding: 0 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .ant-tab-btn { border: none; border-bottom: 2px solid transparent; background: transparent; color: var(--text-muted); padding: 5px 12px; cursor: pointer; font-size: 10px; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }

        /* Toolbar */
        .ant-toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; padding: 6px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); }
        .ant-btn { font-family: inherit; font-size: 9.5px; height: 21px; padding: 0 8px; border-radius: 2px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; line-height: 1.4; transition: all 0.15s; }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color:#fff; }
        .ant-btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .ant-btn-success:hover { background: #49ad16; border-color: #49ad16; color:#fff; }
        .ant-btn-danger { color: var(--danger); border-color: #ffccc7; background: #fff; }
        body.dark-mode .ant-btn-danger { background: transparent; border-color: #5c2c2c; }
        .ant-btn-danger:hover { color: #fff; background: var(--danger); border-color: var(--danger); }
        .ant-btn-sm { font-size: 9.5px; height: 19px; padding: 0 5px; }

        /* Tab Content Pages */
        .ant-page { display: none; min-height: 0; flex-direction: column; flex: 1; overflow: hidden; }
        .ant-page.active { display: flex; }

        /* Split-pane Form Transaksi */
        .split-col { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        #paneRiHdr { flex: 0 0 auto; background: var(--bg-card); border-bottom: 1px solid var(--border-color); padding: 8px 10px 4px 10px; }
        #paneRiFtr { flex: 0 0 auto; min-height: 95px; background: var(--bg-card); border-top: 1px solid var(--border-color); overflow: hidden; padding: 6px 10px; }

        /* Form Controls */
        .hdr-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 12px; }
        .fld-col { display: flex; flex-direction: column; gap: 3px; }
        .fld-row { display: grid; grid-template-columns: 85px 1fr; gap: 4px; align-items: center; margin-bottom: 2px; }
        .fld-row label { text-align: right; color: var(--text-muted); font-size: 9.5px; font-weight: 500; white-space: nowrap; }
        .fld-row input, .fld-row select, .fld-row textarea { width: 100%; border: 1px solid var(--border-input); background: var(--bg-input); color: var(--text-main); padding: 1px 6px; height: 21px; border-radius: 2px; outline: none; font-size: 9.5px; }
        .fld-row input:focus, .fld-row select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15); }
        .inline-box { display: flex; gap: 4px; align-items: center; }

        /* Dedicated 1-Row Compact Browse Filter Toolbar */
        .browse-filter { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; padding: 4px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); white-space: nowrap; overflow: visible; min-height: 32px; }
        .browse-filter input, .browse-filter select { width: auto; border: 1px solid var(--border-input); background: var(--bg-input); color: var(--text-main); padding: 1px 6px; height: 21px; border-radius: 2px; outline: none; font-size: 9.5px; flex-shrink: 0; }
        .browse-filter input:focus, .browse-filter select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15); }
        .browse-filter label, .browse-filter span { font-size: 9.5px; color: var(--text-muted); flex-shrink: 0; }
        .browse-filter .ant-searchable-wrapper { width: 180px !important; min-width: 150px; max-width: 220px; flex-shrink: 0; }

        /* Sub-Tabs di Form Transaksi */
        .subtab-nav { display: flex; gap: 2px; padding: 2px 8px; background: var(--table-head-bg); border-bottom: 1px solid var(--border-color); }
        .subtab-btn { border: none; background: transparent; color: var(--text-muted); padding: 4px 10px; font-size: 10px; font-weight: 500; cursor: pointer; border-radius: 2px 2px 0 0; }
        .subtab-btn.active { background: var(--bg-card); color: var(--primary); font-weight: 600; border-top: 2px solid var(--primary); }

        /* DataWindow Table */
        .grid-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        table.dw { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 9.5px; table-layout: auto; border: 1px solid var(--border-color); }
        table.dw thead { position: sticky; top: 0; z-index: 10; }
        table.dw th { position: relative; background: var(--table-head-bg); border: 1px solid var(--border-color); padding: 4px 6px; text-align: left; font-weight: 600; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.3px; color: var(--text-muted); white-space: nowrap; user-select: none; }
        table.dw td { border: 1px solid var(--border-light); padding: 2px 6px; font-size: 9.5px; line-height: 1.4; vertical-align: middle; }
        table.dw input { width: 100%; border: 1px solid transparent; background: transparent; color: var(--text-main); padding: 1px 4px; height: 21px; border-radius: 2px; font-size: 9.5px; font-family: inherit; }
        table.dw input:hover { border-color: var(--border-input); }
        table.dw input:focus { border-color: var(--primary); background: var(--bg-input); box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.12); outline: none; }
        table.dw input[type="number"] { text-align: right; }
        table.dw tbody tr:hover td { background: var(--table-hover); }
        table.dw tr.sel td { background: var(--row-selected) !important; color: var(--row-selected-text) !important; }

        /* Mini KPI Strip 5 Kartu */
        .kpi-strip { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; padding: 6px 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .kpi-card { background: var(--bg-base); border: 1px solid var(--border-color); border-radius: 3px; padding: 6px 8px; display: flex; align-items: center; gap: 8px; cursor: pointer; transition: all 0.15s; }
        .kpi-card:hover { border-color: var(--primary); transform: translateY(-1px); }
        .kpi-icon { width: 28px; height: 28px; border-radius: 3px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
        .kpi-num { font-size: 14px; font-weight: 700; color: var(--text-main); line-height: 1.1; }
        .kpi-lbl { font-size: 9.5px; color: var(--text-muted); }

        /* Modals */
        .ant-modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1000; justify-content: center; align-items: center; }
        .ant-modal-backdrop.active { display: flex; }
        .ant-modal { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 4px; width: 100%; max-width: 950px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 11px; }
        .ant-modal-header { padding: 8px 12px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 11.5px; background: var(--table-head-bg); }
        .ant-modal-body { padding: 12px; overflow-y: auto; flex: 1; }
        .ant-modal-footer { padding: 8px 12px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 6px; background: var(--table-head-bg); }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Card Header -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-boxes-packing" style="color:var(--primary);"></i>
                <span>Purchasing &ndash; Receive Item (GRN)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div class="inline-box">
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP">
                    <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i>
                </button>
            </div>
        </div>

        <!-- Tab Navigasi Utama -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" id="tabBtnForm" onclick="switchMainTab('form')">
                <i class="fa-solid fa-file-pen"></i> 1. Form Transaksi Penerimaan
            </button>
            <button type="button" class="ant-tab-btn" id="tabBtnBrowse" onclick="switchMainTab('browse')">
                <i class="fa-solid fa-table-list"></i> 2. Daftar Dokumen Penerimaan (GRN List)
            </button>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 1: FORM TRANSAKSI / EDITOR RECEIVE ITEM                       -->
        <!-- ================================================================= -->
        <div class="ant-page active" id="pageForm">
            <!-- Toolbar Editor -->
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="newRiDocument()"><i class="fa-solid fa-file-circle-plus"></i> Buat Baru</button>
                <button type="button" class="ant-btn ant-btn-success" id="btnSaveRi" onclick="saveRiDocument()"><i class="fa-solid fa-floppy-disk"></i> Simpan Penerimaan (RI)</button>
                <button type="button" class="ant-btn" onclick="openAjuPickerModal(false)" style="color:#0284c7; font-weight:600;"><i class="fa-solid fa-file-import"></i> Tarik dari AJU Pabean</button>
                <button type="button" class="ant-btn" onclick="openPoPickerModal()"><i class="fa-solid fa-cloud-arrow-down" style="color:#0284c7;"></i> Tarik dari PO</button>
                <button type="button" class="ant-btn" onclick="openAjuPickerModal(true)" style="color:#0891b2;" title="Pasangkan Nomor &amp; Data Dokumen Pabean (No BC, Tgl BC, No Aju, CIF) ke Dokumen PO/GRN saat ini"><i class="fa-solid fa-link"></i> Tempel Dokumen BC</button>
                <button type="button" class="ant-btn" onclick="printRiDocument()"><i class="fa-solid fa-print"></i> Cetak Bukti (BPB)</button>
                <button type="button" class="ant-btn" onclick="printRiQrLabel()" style="color:#059669; font-weight:600;" title="Cetak Stiker Label QR Dokumen BC (Traceability AJU)"><i class="fa-solid fa-qrcode"></i> Label QR Dokumen BC</button>
                <button type="button" class="ant-btn" id="btnAddRow" onclick="addRowManual()"><i class="fa-solid fa-plus"></i> Tambah Baris</button>
                <button type="button" class="ant-btn ant-btn-danger" id="btnDeleteRi" onclick="deleteRiDocument()"><i class="fa-solid fa-trash-can"></i> Hapus</button>
                <button type="button" class="ant-btn" id="btnPostItInv" onclick="postToItInventory()" style="background:#059669; color:#fff; border-color:#047857; display:none;"><i class="fa-solid fa-shield-halved"></i> Posting IT Inv</button>
                <button type="button" class="ant-btn" id="btnUnpostItInv" onclick="unpostFromItInventory()" style="background:#dc2626; color:#fff; border-color:#b91c1c; display:none;"><i class="fa-solid fa-lock-open"></i> Buka Kunci IT Inv</button>
                <span style="border-left:1px solid var(--border-color); height:16px; margin:0 4px;"></span>
                <span id="labelDocStatus" class="ant-badge ant-badge-warning">DRAFT</span>
            </div>

            <!-- Alert Banner Kunci IT Inventory -->
            <div id="riLockAlert" style="display:none; background:#ecfdf5; border:1px solid #10b981; border-radius:4px; padding:6px 12px; margin-bottom:8px; font-size:11px; color:#065f46; align-items:center; justify-content:space-between;">
                <div>
                    <i class="fa-solid fa-shield-check" style="color:#059669; margin-right:6px; font-size:13px;"></i>
                    <span>Dokumen RI ini telah <strong>TERPOSTING KE IT INVENTORY (bc_1ab2)</strong> sebagai Bukti Pemasukan. Data dikunci untuk menjaga integritas kepatuhan pabean.</span>
                </div>
                <span id="itInvPostingInfo" style="font-size:10px; color:#047857; font-weight:600;"></span>
            </div>

            <!-- Form Split-Col -->
            <div class="split-col">
                <!-- Header Panel -->
                <div id="paneRiHdr">
                    <input type="hidden" id="hdrId" value="">
                    <div class="hdr-grid">
                        <!-- Kolom 1: No RI, Tgl, SJ Vendor -->
                        <div class="fld-col">
                            <div class="fld-row">
                                <label>No. RI / GRN:</label>
                                <input type="text" id="hdrRiNo" placeholder="(Otomatis RI-YYMM-XXXX)" style="font-weight:700; color:var(--primary);">
                            </div>
                            <div class="fld-row">
                                <label>Tgl. Terima:</label>
                                <input type="date" id="hdrRiDt" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="fld-row">
                                <label>No. SJ Vendor:</label>
                                <input type="text" id="hdrSjNo" placeholder="No. Surat Jalan Vendor">
                            </div>
                            <div class="fld-row">
                                <label>Tgl. SJ Vendor:</label>
                                <input type="date" id="hdrShipDt" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <!-- Kolom 2: Supplier, No PO, Gudang -->
                        <div class="fld-col">
                            <div class="fld-row">
                                <label>Supplier:</label>
                                <select id="hdrVendor" onchange="onVendorChange()">
                                    <option value="">-- Pilih Supplier --</option>
                                    <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= htmlspecialchars($s['KODE']) ?>" data-term="<?= htmlspecialchars($s['PAY_TERM']) ?>" data-kurs="<?= htmlspecialchars($s['kurs']) ?>" data-nama="<?= htmlspecialchars($s['NAMA']) ?>">
                                        <?= htmlspecialchars($s['KODE']) ?> - <?= htmlspecialchars($s['NAMA']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fld-row">
                                <label>No. PO Acuan:</label>
                                <div class="inline-box">
                                    <input type="text" id="hdrPoNo" placeholder="Acuan No. PO (Opsional)">
                                    <button type="button" class="ant-btn ant-btn-sm" onclick="openPoPickerModal()" title="Cari &amp; Tarik PO"><i class="fa-solid fa-magnifying-glass"></i></button>
                                </div>
                            </div>
                            <div class="fld-row">
                                <label>Gudang Masuk:</label>
                                <select id="hdrGudang">
                                    <?php foreach ($gudangList as $g): ?>
                                    <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fld-row">
                                <label>Ekspedisi / Kirim:</label>
                                <select id="hdrShip">
                                    <option value="">-- Pilih Ekspedisi --</option>
                                    <?php foreach ($jasakirimList as $jk): ?>
                                    <option value="<?= htmlspecialchars($jk['nama']) ?>"><?= htmlspecialchars($jk['kode']) ?> - <?= htmlspecialchars($jk['nama']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Kolom 3: Valuta, Kurs, Termin -->
                        <div class="fld-col">
                            <div class="fld-row">
                                <label>Mata Uang:</label>
                                <div class="inline-box">
                                    <select id="hdrKurs" style="width:70px;" onchange="calculateGrandTotal()">
                                        <?php foreach ($kursList as $k): ?>
                                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" id="hdrRate" step="0.0001" value="1.0000" placeholder="Kurs" style="width:75px;" onchange="calculateGrandTotal()">
                                </div>
                            </div>
                            <div class="fld-row">
                                <label>Termin Bayar:</label>
                                <select id="hdrTerm">
                                    <?php foreach ($termList as $t): ?>
                                    <option value="<?= htmlspecialchars($t['kode']) ?>"><?= htmlspecialchars($t['kode']) ?> (<?= htmlspecialchars($t['note']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fld-row">
                                <label>Incoterms / FOB:</label>
                                <select id="hdrFob">
                                    <option value="1">FOB (Free on Board)</option>
                                    <option value="2">CIF (Cost, Insurance &amp; Freight)</option>
                                    <option value="3">CFR (Cost &amp; Freight)</option>
                                    <option value="4">EXW (Ex Works)</option>
                                    <option value="5">DDP (Delivered Duty Paid)</option>
                                </select>
                            </div>
                            <div class="fld-row">
                                <label>Status Dokumen:</label>
                                <select id="hdrStatus">
                                    <option value="0">0 - Draft / Terbuka</option>
                                    <option value="1" selected>1 - Diterima / In Stock</option>
                                    <option value="2">2 - Ditagih / Selesai (Invoiced)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Kolom 4: Kepatuhan Pabean CEISA 4.0 -->
                        <div class="fld-col">
                            <div class="fld-row">
                                <label>Dokumen BC:</label>
                                <select id="hdrBc">
                                    <option value="">NON-BC (Bukan Fasilitas Pabean)</option>
                                    <option value="BC 2.3">BC 2.3 (Impor Kawasan Berikat)</option>
                                    <option value="BC 4.0">BC 4.0 (Pemasukan Lokal TLDDP)</option>
                                    <option value="BC 2.7">BC 2.7 (Transfer Masuk Sesama TPB)</option>
                                    <option value="BC 2.6.2">BC 2.6.2 (Pemasukan Subkontrak)</option>
                                    <option value="BC 2.0">BC 2.0 (Impor Umum / PIB)</option>
                                </select>
                            </div>
                            <div class="fld-row">
                                <label>No. Pendaftaran:</label>
                                <input type="text" id="hdrNoBc" placeholder="No. Daftar Pabean (6 Digit)">
                            </div>
                            <div class="fld-row">
                                <label>Tgl. Pendaftaran:</label>
                                <input type="date" id="hdrTglBc">
                            </div>
                            <div class="fld-row">
                                <label>No. Aju (26 Dig):</label>
                                <input type="text" id="hdrNoAju" placeholder="Nomor Pengajuan CEISA" maxlength="30" style="font-family:'JetBrains Mono', Consolas, monospace; font-size:9.5px;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subtab Navigation -->
                <div class="subtab-nav">
                    <button type="button" class="subtab-btn active" id="subtabBtnItems" onclick="switchSubTab('items')"><i class="fa-solid fa-list-check"></i> Rincian Barang Diterima (<span id="countItemBadge">0</span> Item)</button>
                    <button type="button" class="subtab-btn" id="subtabBtnPi" onclick="switchSubTab('pi')"><i class="fa-solid fa-file-invoice-dollar"></i> Riwayat Faktur Pembelian (Purchase Invoice)</button>
                    <button type="button" class="subtab-btn" id="subtabBtnCeisa" onclick="switchSubTab('ceisa')"><i class="fa-solid fa-shield-halved"></i> Ketentuan Kepabeanan &amp; IT Inventory</button>
                </div>

                <!-- Sub-Page A: Rincian Barang Grid -->
                <div class="grid-wrap" id="subpageItems">
                    <table class="dw" id="tblRiItems">
                        <thead>
                            <tr>
                                <th style="width:28px; text-align:center;">#</th>
                                <th style="width:125px;">Kode Barang</th>
                                <th style="width:220px;">Nama &amp; Spesifikasi Barang</th>
                                <th style="width:75px; text-align:right;" title="Kuantitas tercatat pada Dokumen Pabean (Bea Cukai)">Qty Dokumen</th>
                                <th style="width:75px; text-align:right;" title="Kuantitas fisik aktual yang benar-benar diterima di gudang">Qty Fisik (Aktual)</th>
                                <th style="width:45px; text-align:center;" title="Kesesuaian fisik vs dokumen (Y = Sesuai, N = Selisih)">Sesuai</th>
                                <th style="width:45px; text-align:center;">Satuan</th>
                                <th style="width:80px; text-align:right;">Harga Satuan</th>
                                <th style="width:50px; text-align:right;">Disc (%)</th>
                                <th style="width:95px; text-align:right;">Total Nilai</th>
                                <th style="width:170px;" title="Wajib diisi jika terdapat selisih fisik vs dokumen pabean">Memo / Alasan Selisih</th>
                                <th style="width:105px;">No. PO Acuan</th>
                                <th style="width:90px;">Gudang / Rak</th>
                                <th style="width:35px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyRiItems">
                            <!-- Baris item di-render secara dinamis via JavaScript -->
                        </tbody>
                    </table>
                </div>

                <!-- Sub-Page B: Riwayat Faktur Pembelian (PI) -->
                <div class="grid-wrap" id="subpagePi" style="display:none; padding:10px;">
                    <table class="dw" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="width:30px;">#</th>
                                <th>No. Faktur (PI No)</th>
                                <th>Tanggal PI</th>
                                <th>Kode Barang</th>
                                <th>Uraian Barang</th>
                                <th style="text-align:right;">Qty Ditagih</th>
                                <th>Sat</th>
                                <th style="text-align:right;">Harga</th>
                                <th style="text-align:right;">Total Faktur</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyPiRealization">
                            <tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">Belum ada faktur pembelian (Purchase Invoice) yang menagih dokumen ini.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Sub-Page C: Kepatuhan Kepabeanan -->
                <div class="grid-wrap" id="subpageCeisa" style="display:none; padding:12px;">
                    <div style="background:rgba(2,132,199,0.05); border:1px solid rgba(2,132,199,0.2); border-radius:4px; padding:10px; margin-bottom:10px;">
                        <h4 style="color:var(--primary); font-size:11px; margin-bottom:4px;"><i class="fa-solid fa-circle-info"></i> Ketentuan Pabean Penerimaan Barang Kawasan Berikat (IT Inventory)</h4>
                        <p style="font-size:10.5px; line-height:1.5;">Seluruh penerimaan barang ke gudang pabrik berstatus Kawasan Berikat (TPB) otomatis dicatat sebagai Dokumen Pemasukan pada Laporan Pertanggungjawaban Mutasi Bahan Baku/Penolong (IT Inventory). Pastikan nomor pendaftaran dokumen kepabeanan (BC 2.3 / BC 4.0 / BC 2.7) dan nomor pengajuan 26 digit telah diverifikasi dengan respon valid CEISA 4.0.</p>
                    </div>
                    <div class="fld-row" style="max-width:400px;">
                        <label>Catatan Pabean:</label>
                        <input type="text" id="hdrCustomsNote" placeholder="Keterangan respon pabean / gate pass">
                    </div>
                </div>

                <!-- Footer Summary Panel -->
                <div id="paneRiFtr">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div style="width:50%;">
                            <div class="fld-row" style="grid-template-columns:60px 1fr;">
                                <label style="text-align:left;">Catatan:</label>
                                <textarea id="hdrNote" rows="2" style="height:48px; resize:none;" placeholder="Catatan penerimaan fisik barang, hasil pengecekan kemasan / QC..."></textarea>
                            </div>
                        </div>
                        <div style="width:40%; max-width:380px;">
                            <div style="display:grid; grid-template-columns:120px 1fr; gap:2px 8px; font-size:10.5px; text-align:right;">
                                <span>Subtotal Nilai:</span><strong id="lblSubtotal">0.00</strong>
                                <span>Diskon Faktur (%):</span>
                                <div class="inline-box" style="justify-content:flex-end;">
                                    <input type="number" id="hdrDiscPersen" value="0.00" step="0.1" style="width:50px; text-align:right;" onchange="calculateGrandTotal()">
                                    <span id="lblDiscAmt" style="width:90px;">0.00</span>
                                </div>
                                <span>PPN:</span>
                                <div class="inline-box" style="justify-content:flex-end;">
                                    <input type="number" id="hdrPpn" value="0.00" step="0.01" style="width:100px; text-align:right;" onchange="calculateGrandTotal()">
                                </div>
                                <span style="font-size:12px; font-weight:700; color:var(--primary); border-top:1px solid var(--border-color); padding-top:4px;">TOTAL PENERIMAAN:</span>
                                <strong style="font-size:12px; font-weight:700; color:var(--primary); border-top:1px solid var(--border-color); padding-top:4px;" id="lblGrandTotal">0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 2: DAFTAR DOKUMEN / BROWSE GRID (RECEIVE ITEM LIST)           -->
        <!-- ================================================================= -->
        <div class="ant-page" id="pageBrowse">
            <!-- Mini KPI Strip 5 Kartu -->
            <div class="kpi-strip">
                <div class="kpi-card" onclick="filterBrowseStatus('ALL')">
                    <div class="kpi-icon" style="background:rgba(2,132,199,0.1); color:#0284c7;"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div><div class="kpi-num" id="kpiTotalGrn">0</div><div class="kpi-lbl">Total Penerimaan (GRN)</div></div>
                </div>
                <div class="kpi-card" onclick="filterBrowseCurMonth()">
                    <div class="kpi-icon" style="background:rgba(16,185,129,0.1); color:#10b981;"><i class="fa-regular fa-calendar-check"></i></div>
                    <div><div class="kpi-num" id="kpiGrnBulanIni">0</div><div class="kpi-lbl">Penerimaan Bulan Ini</div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:rgba(139,92,246,0.1); color:#8b5cf6;"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div><div class="kpi-num" style="font-size:11px;" id="kpiTotalNilai">0.00</div><div class="kpi-lbl">Total Nilai Masuk (IDR)</div></div>
                </div>
                <div class="kpi-card" onclick="filterBrowseStatus('0')">
                    <div class="kpi-icon" style="background:rgba(245,158,11,0.1); color:#f59e0b;"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div><div class="kpi-num" id="kpiUnbilledGrn">0</div><div class="kpi-lbl">Belum Ditagih (Unbilled)</div></div>
                </div>
                <div class="kpi-card" onclick="filterBrowseStatus('2')">
                    <div class="kpi-icon" style="background:rgba(59,130,246,0.1); color:#3b82f6;"><i class="fa-solid fa-check-double"></i></div>
                    <div><div class="kpi-num" id="kpiInvoicedGrn">0</div><div class="kpi-lbl">Selesai (Invoiced)</div></div>
                </div>
            </div>

            <!-- Toolbar Filter Browse (1 Baris Ringkas & Rapi) -->
            <div class="browse-filter">
                <label>Periode:</label>
                <input type="date" id="brwTglAwal" style="width:105px;" onchange="loadBrowseList(1)">
                <span>s/d</span>
                <input type="date" id="brwTglAkhir" style="width:105px;" onchange="loadBrowseList(1)">
                <label>Dok BC:</label>
                <select id="brwBc" style="width:115px;" onchange="loadBrowseList(1)">
                    <option value="">-- Dokumen BC --</option>
                    <option value="BC 2.3">BC 2.3 (Impor KB)</option>
                    <option value="BC 4.0">BC 4.0 (TLDDP Lokal)</option>
                    <option value="BC 2.7">BC 2.7 (Transfer TPB)</option>
                    <option value="BC 2.6.2">BC 2.6.2 (Subkontrak)</option>
                    <option value="NON-BC">NON-BC</option>
                </select>
                <label>Status:</label>
                <select id="brwStatus" style="width:95px;" onchange="loadBrowseList(1)">
                    <option value="ALL">-- Status --</option>
                    <option value="0">Draft</option>
                    <option value="1">In Stock</option>
                    <option value="2">Invoiced</option>
                </select>
                <label>Supplier:</label>
                <select id="brwVendor" style="width:180px;" onchange="loadBrowseList(1)">
                    <option value="">-- Semua Supplier --</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= htmlspecialchars($s['KODE']) ?>"><?= htmlspecialchars($s['KODE']) ?> - <?= htmlspecialchars($s['NAMA']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Cari:</label>
                <input type="text" id="brwSearch" placeholder="Kata kunci No RI, SJ, PO..." style="width:160px;" onkeydown="if(event.key==='Enter') loadBrowseList(1)">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadBrowseList(1)"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
                <button type="button" class="ant-btn" onclick="resetBrowseFilter()"><i class="fa-solid fa-arrow-rotate-left"></i> Reset</button>
                <span style="border-left:1px solid var(--border-color); height:16px; margin:0 2px;"></span>
                <button type="button" class="ant-btn" onclick="exportBrowseExcel()"><i class="fa-solid fa-file-excel" style="color:#10b981;"></i> Excel</button>
                <button type="button" class="ant-btn" onclick="exportBrowseCsv()"><i class="fa-solid fa-file-csv"></i> CSV</button>
            </div>

            <!-- Table Browse Data -->
            <div class="grid-wrap">
                <table class="dw" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th style="width:120px;">No. RI / GRN</th>
                            <th style="width:80px;">Tgl. Terima</th>
                            <th style="width:110px;">No. SJ Vendor</th>
                            <th style="width:110px;">No. PO Acuan</th>
                            <th style="width:200px;">Supplier / Pemasok</th>
                            <th style="width:75px; text-align:center;">Dokumen BC</th>
                            <th style="width:85px;">Gudang</th>
                            <th style="width:60px; text-align:right;">Item</th>
                            <th style="width:75px; text-align:right;">Total Qty</th>
                            <th style="width:95px; text-align:right;">Total Nilai</th>
                            <th style="width:80px; text-align:center;">IT Inventory</th>
                            <th style="width:75px; text-align:center;">Status</th>
                            <th style="width:80px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyBrowse">
                        <!-- Di-render via JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div style="padding:4px 10px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; background:var(--bg-card); font-size:10px;">
                <span id="lblPageInfo">Memuat data...</span>
                <div class="inline-box" id="paginationBtns"></div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TARIK ITEMS DARI OUTSTANDING PO                                    -->
<!-- ========================================================================= -->
<div class="ant-modal-backdrop" id="modalPoPicker">
    <div class="ant-modal" style="max-width:850px;">
        <div class="ant-modal-header">
            <span><i class="fa-solid fa-cloud-arrow-down" style="color:var(--primary);"></i> Tarik Items dari Outstanding Purchase Order (PO)</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closePoPickerModal()"></i>
        </div>
        <div class="ant-modal-body">
            <div style="display:flex; gap:8px; margin-bottom:10px;">
                <input type="text" id="popSearch" class="form-control" placeholder="Cari No. PO atau Supplier..." style="flex:1; height:24px; font-size:11px;" onkeydown="if(event.key==='Enter') searchOpenPo()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchOpenPo()"><i class="fa-solid fa-magnifying-glass"></i> Cari PO</button>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1.6fr; gap:10px; height:340px;">
                <!-- Daftar PO -->
                <div style="border:1px solid var(--border-color); border-radius:3px; overflow-y:auto; background:var(--bg-card);">
                    <div style="padding:4px 8px; background:var(--table-head-bg); font-weight:600; border-bottom:1px solid var(--border-color); font-size:10px;">Daftar PO yang Terbuka:</div>
                    <div id="listOpenPoContainer">
                        <div style="text-align:center; padding:20px; color:var(--text-muted);">Memuat daftar PO...</div>
                    </div>
                </div>

                <!-- Rincian Item PO -->
                <div style="border:1px solid var(--border-color); border-radius:3px; display:flex; flex-direction:column; overflow:hidden; background:var(--bg-card);">
                    <div style="padding:4px 8px; background:var(--table-head-bg); font-weight:600; border-bottom:1px solid var(--border-color); font-size:10px; display:flex; justify-content:space-between;">
                        <span>Pilih Barang Diterima:</span>
                        <label style="cursor:pointer;"><input type="checkbox" id="chkSelectAllPoItems" onchange="toggleSelectAllPoItems(this.checked)"> Pilih Semua</label>
                    </div>
                    <div style="flex:1; overflow-y:auto;">
                        <table class="dw" style="width:100%;">
                            <thead>
                                <tr>
                                    <th style="width:25px; text-align:center;">✓</th>
                                    <th>Kode &amp; Nama Barang</th>
                                    <th style="width:55px; text-align:right;">Qty PO</th>
                                    <th style="width:55px; text-align:right;">Sisa</th>
                                    <th style="width:65px; text-align:right;">Terima</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyPoItemsPicker">
                                <tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Pilih salah satu PO di sebelah kiri untuk melihat rincian barang.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn" onclick="closePoPickerModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="insertSelectedPoItems()"><i class="fa-solid fa-check"></i> Masukkan ke Dokumen Penerimaan</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TARIK BARANG DARI AJU PABEAN (CEISA 4.0 STAGING)                   -->
<!-- ========================================================================= -->
<div class="ant-modal-backdrop" id="modalAjuPicker">
    <div class="ant-modal" style="max-width:940px;">
        <div class="ant-modal-header">
            <span id="lblModalAjuTitle"><i class="fa-solid fa-file-import" style="color:var(--primary);"></i> Tarik Barang dari Dokumen Pabean (CEISA 4.0 Staging)</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeAjuPickerModal()"></i>
        </div>
        <div class="ant-modal-body">
            <!-- Filter Bar Box -->
            <div style="background:var(--table-head-bg); border:1px solid var(--border-color); border-radius:3px; padding:6px 10px; margin-bottom:8px; display:flex; flex-direction:column; gap:6px;">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <span style="font-size:10px; font-weight:600; color:var(--text-muted);">Dokumen BC:</span>
                    <select id="ajuBcDoc" class="form-control" style="width:130px; height:24px; font-size:11px;" onchange="searchStagingAju()">
                        <option value="ALL">Semua BC Masuk</option>
                        <option value="40">BC 4.0 (Lokal)</option>
                        <option value="23">BC 2.3 (Impor)</option>
                        <option value="27">BC 2.7 (Antar TPB)</option>
                        <option value="262">BC 2.6.2 (Subkon)</option>
                        <option value="20">BC 2.0 (Impor Umum)</option>
                        <option value="16">BC 1.6 (PLB Masuk)</option>
                    </select>

                    <span style="font-size:10px; font-weight:600; color:var(--text-muted); margin-left:6px;">Tgl Daftar / AJU:</span>
                    <input type="date" id="ajuTglAwal" class="form-control" style="width:115px; height:24px; font-size:11px;">
                    <span style="font-size:11px; color:var(--text-muted);">s/d</span>
                    <input type="date" id="ajuTglAkhir" class="form-control" style="width:115px; height:24px; font-size:11px;">

                    <div style="display:inline-flex; gap:3px;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="setAjuDateRange(30)" title="30 Hari Terakhir">30H</button>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="setAjuDateRange(60)" title="60 Hari Terakhir">60H</button>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="setAjuDateRange('month')" title="Bulan Ini">Bln Ini</button>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="setAjuDateRange('all')" title="Semua Periode">Semua</button>
                    </div>
                </div>

                <div style="display:flex; gap:6px; align-items:center;">
                    <input type="text" id="ajuSearch" class="form-control" placeholder="Cari No. AJU (26 digit), No. Daftar, Rekanan / Pemasok, atau No. Invoice / SJ..." style="flex:1; height:24px; font-size:11px;" onkeydown="if(event.key==='Enter') searchStagingAju()">
                    <button type="button" class="ant-btn ant-btn-primary" onclick="searchStagingAju()"><i class="fa-solid fa-magnifying-glass"></i> Filter AJU</button>
                    <button type="button" class="ant-btn ant-btn-default" onclick="resetAjuFilter()" title="Reset Filter"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1.6fr; gap:10px; height:370px;">
                <!-- Daftar AJU Masuk -->
                <div style="border:1px solid var(--border-color); border-radius:3px; overflow-y:auto; background:var(--bg-card);">
                    <div style="padding:4px 8px; background:var(--table-head-bg); font-weight:600; border-bottom:1px solid var(--border-color); font-size:10px;">Dokumen Pemasukan Ber-Nomor Daftar:</div>
                    <div id="listAjuContainer">
                        <div style="text-align:center; padding:20px; color:var(--text-muted);">Memuat dokumen pabean...</div>
                    </div>
                </div>

                <!-- Preview Barang AJU -->
                <div style="border:1px solid var(--border-color); border-radius:3px; display:flex; flex-direction:column; overflow:hidden; background:var(--bg-card);">
                    <div style="padding:4px 8px; background:var(--table-head-bg); font-weight:600; border-bottom:1px solid var(--border-color); font-size:10px; display:flex; justify-content:space-between; align-items:center;">
                        <span id="lblPreviewAjuTitle">Rincian Barang Pabean:</span>
                        <span id="lblPreviewAjuStatus" class="ant-badge" style="display:none;"></span>
                    </div>

                    <!-- Bar Tautan PO Terkait (Agar PO terpotong & bisa tertutup otomatis) -->
                    <div id="boxAjuPoSelector" style="padding:4px 8px; background:var(--bg-base); border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:6px; font-size:10px;">
                        <span style="font-weight:600; color:var(--text-main); white-space:nowrap;"><i class="fa-solid fa-link" style="color:var(--primary);"></i> Tautkan PO:</span>
                        <select id="ajuPoSelector" class="form-control" style="flex:1; height:22px; font-size:10px;" onchange="onAjuPoSelected(this.value)">
                            <option value="">-- Tanpa PO (Non-PO / Standalone Dokumen BC) --</option>
                        </select>
                        <span id="ajuPoMatchBadge" class="ant-badge ant-badge-company" style="display:none; font-size:8.5px;"></span>
                    </div>

                    <div style="flex:1; overflow-y:auto;">
                        <table class="dw" style="width:100%;">
                            <thead>
                                <tr>
                                    <th style="width:25px; text-align:center;">#</th>
                                    <th>Kode &amp; Spesifikasi Barang</th>
                                    <th style="width:65px; text-align:right;">Qty Dok</th>
                                    <th style="width:45px; text-align:center;">Sat</th>
                                    <th style="width:115px; text-align:left;">Status Link PO</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyAjuItemsPreview">
                                <tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Pilih salah satu dokumen AJU di sebelah kiri untuk melihat rincian barang.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn" onclick="closeAjuPickerModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" id="btnImportAjuToRi" onclick="importAjuItemsToRi()" disabled style="opacity:0.6;"><i class="fa-solid fa-cloud-arrow-down"></i> <span id="lblBtnImportAju">Impor Barang ke Form Penerimaan (RI)</span></button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: LOOKUP MASTER MATERIAL (bc_material)                               -->
<!-- ========================================================================= -->
<div class="ant-modal-backdrop" id="modalMaterialPicker">
    <div class="ant-modal" style="max-width:700px;">
        <div class="ant-modal-header">
            <span><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Pilih Master Barang &amp; Material (bc_material)</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeMaterialModal()"></i>
        </div>
        <div class="ant-modal-body">
            <div style="display:flex; gap:8px; margin-bottom:10px;">
                <input type="text" id="matSearch" placeholder="Cari Kode atau Nama Material..." style="flex:1; height:24px; font-size:11px;" onkeydown="if(event.key==='Enter') searchMaterials()">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchMaterials()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div style="max-height:300px; overflow-y:auto; border:1px solid var(--border-color);">
                <table class="dw" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:120px;">Kode Barang</th>
                            <th>Nama &amp; Spesifikasi Barang</th>
                            <th style="width:60px; text-align:center;">Satuan</th>
                            <th style="width:90px; text-align:right;">Harga Standar</th>
                            <th style="width:50px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyMaterialPicker">
                        <tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">Ketik kata kunci untuk mencari barang.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn" onclick="closeMaterialModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: PANDUAN MODUL & SOP KEPABEANAN (ICON-ONLY BUTTON)                  -->
<!-- ========================================================================= -->
<div class="ant-modal-backdrop" id="modalModuleGuide">
    <div class="ant-modal" style="max-width:650px;">
        <div class="ant-modal-header">
            <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Modul &amp; SOP: Receive Item (GRN)</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModuleGuide()"></i>
        </div>
        <div class="ant-modal-body" style="line-height:1.6; font-size:11px;">
            <h4 style="font-size:12px; color:var(--primary); margin-bottom:6px;"><i class="fa-solid fa-truck-ramp-box"></i> Standar Operasional Prosedur (SOP) Penerimaan Barang Gudang</h4>
            <p style="margin-bottom:8px;">Modul Receive Item mencatat fisik barang yang diterima di gudang pabrik dan terhubung langsung dengan sistem Purchasing, Pencatatan Hutang, serta Laporan Kepatuhan Kawasan Berikat CEISA 4.0:</p>
            <ul style="padding-left:18px; margin-bottom:12px;">
                <li><b>Verifikasi Surat Jalan &amp; PO:</b> Petugas penerimaan wajib mencocokkan Surat Jalan vendor dengan Purchase Order acuan. Gunakan tombol <code>Tarik dari PO</code> agar kuantitas, harga, dan spesifikasi konsisten.</li>
                <li><b>Pemeriksaan Fisik &amp; Kualitas (QC):</b> Barang yang diterima diperiksa kesesuaian fisik, kemasan, nomor batch/lot, dan tanggal kedaluwarsa sebelum status diubah menjadi <code>In Stock</code>.</li>
                <li><b>Kepatuhan Pabean CEISA 4.0 (IT Inventory):</b> Penerimaan bahan baku/penolong impor maupun lokal Kawasan Berikat (Dokumen BC 2.3, BC 4.0, BC 2.7) wajib mencantumkan Nomor Pengajuan (26 digit) serta Nomor dan Tanggal Pendaftaran Pabean resmi.</li>
                <li><b>Integritas Realisasi Faktur (PI):</b> Dokumen penerimaan yang sudah ditagih pada Faktur Pembelian (Purchase Invoice) dikunci secara otomatis agar tidak dapat dihapus sembarangan demi keabsahan laporan akuntansi dan audit trail.</li>
            </ul>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Tutup Panduan</button>
        </div>
    </div>
</div>

<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let riItems = [];
let targetEditItemIndex = -1;
let currentPoList = [];
let currentPoItems = [];
let currentAjuList = [];
let currentAjuData = null;
let isCurrentRiLocked = false;

// -------------------------------------------------------------------------
// HELPER UTILITIES
// -------------------------------------------------------------------------
function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function formatNum(n, dec = 2) {
    const val = parseFloat(n) || 0;
    return val.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}

// -------------------------------------------------------------------------
// TABS SWITCHER
// -------------------------------------------------------------------------
function switchMainTab(tab) {
    document.getElementById('tabBtnForm').classList.toggle('active', tab === 'form');
    document.getElementById('tabBtnBrowse').classList.toggle('active', tab === 'browse');
    document.getElementById('pageForm').classList.toggle('active', tab === 'form');
    document.getElementById('pageBrowse').classList.toggle('active', tab === 'browse');

    if (tab === 'browse') {
        loadBrowseList(1);
    }
}

function switchSubTab(st) {
    document.getElementById('subtabBtnItems').classList.toggle('active', st === 'items');
    document.getElementById('subtabBtnPi').classList.toggle('active', st === 'pi');
    document.getElementById('subtabBtnCeisa').classList.toggle('active', st === 'ceisa');

    document.getElementById('subpageItems').style.display = (st === 'items') ? 'block' : 'none';
    document.getElementById('subpagePi').style.display = (st === 'pi') ? 'block' : 'none';
    document.getElementById('subpageCeisa').style.display = (st === 'ceisa') ? 'block' : 'none';
}

// -------------------------------------------------------------------------
// FORM EDITOR FUNCTIONS (TAB 1)
// -------------------------------------------------------------------------
function newRiDocument() {
    isCurrentRiLocked = false;
    document.getElementById('hdrId').value = '';
    document.getElementById('hdrRiNo').value = '';
    document.getElementById('hdrRiDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('hdrSjNo').value = '';
    document.getElementById('hdrShipDt').value = new Date().toISOString().slice(0, 10);
    document.getElementById('hdrVendor').value = '';
    document.getElementById('hdrPoNo').value = '';
    document.getElementById('hdrGudang').selectedIndex = 0;
    document.getElementById('hdrShip').value = '';
    document.getElementById('hdrKurs').value = 'IDR';
    document.getElementById('hdrRate').value = '1.0000';
    document.getElementById('hdrTerm').selectedIndex = 0;
    document.getElementById('hdrFob').value = '1';
    document.getElementById('hdrStatus').value = '1';
    document.getElementById('hdrBc').value = '';
    document.getElementById('hdrNoBc').value = '';
    document.getElementById('hdrTglBc').value = '';
    document.getElementById('hdrNoAju').value = '';
    document.getElementById('hdrNote').value = '';
    document.getElementById('hdrDiscPersen').value = '0.00';
    document.getElementById('hdrPpn').value = '0.00';

    // Update Banner & Tombol IT Inventory
    document.getElementById('riLockAlert').style.display = 'none';
    document.getElementById('btnPostItInv').style.display = 'none';
    document.getElementById('btnUnpostItInv').style.display = 'none';
    document.getElementById('btnSaveRi').style.display = '';
    document.getElementById('btnDeleteRi').style.display = '';
    document.getElementById('btnAddRow').style.display = '';

    document.getElementById('labelDocStatus').className = 'ant-badge ant-badge-warning';
    document.getElementById('labelDocStatus').innerText = 'DRAFT (BARU)';

    // Sinkronkan Combobox Pencarian Supplier
    document.getElementById('hdrVendor').dispatchEvent(new Event('change', { bubbles: true }));

    riItems = [];
    renderItemRows();
    document.getElementById('tbodyPiRealization').innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">Belum ada faktur pembelian yang menagih dokumen ini.</td></tr>';
    switchSubTab('items');
}

function onVendorChange() {
    const sel = document.getElementById('hdrVendor');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        const term = opt.getAttribute('data-term');
        const kurs = opt.getAttribute('data-kurs');
        if (term) document.getElementById('hdrTerm').value = term;
        if (kurs) document.getElementById('hdrKurs').value = kurs;
    }
}

function renderItemRows() {
    const tbody = document.getElementById('tbodyRiItems');
    document.getElementById('countItemBadge').innerText = riItems.length;

    if (riItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="14" style="text-align:center; padding:18px; color:var(--text-muted);"><i class="fa-solid fa-inbox"></i> Belum ada rincian barang. Klik <b>Tarik dari AJU Pabean</b>, <b>Tarik dari PO</b>, atau <b>Tambah Baris</b>.</td></tr>';
        calculateGrandTotal();
        return;
    }

    let html = '';
    riItems.forEach((it, idx) => {
        const qtyAkt = (it.qty_aktual !== undefined && it.qty_aktual !== null) ? parseFloat(it.qty_aktual) : (parseFloat(it.qty) || 0);
        const qtyDok = (it.qty_dokumen !== undefined && it.qty_dokumen !== null) ? parseFloat(it.qty_dokumen) : qtyAkt;
        it.qty = qtyAkt;
        it.qty_aktual = qtyAkt;
        it.qty_dokumen = qtyDok;

        const price = parseFloat(it.price) || 0;
        const disc  = parseFloat(it.disc) || 0;
        const amount = qtyAkt * price * (1 - disc / 100);
        it.amount = amount;

        const isDiff = Math.abs(qtyAkt - qtyDok) > 0.00001;
        const memo = (it.memo || '').trim();
        const isMissingMemo = isDiff && (memo === '');
        const isSesuai = !isDiff && (memo === '');
        it.sesuai = isSesuai ? 'Y' : 'N';

        html += `<tr>
            <td style="text-align:center; color:var(--text-muted); font-size:9.5px;">${idx + 1}</td>
            <td>
                <div class="inline-box">
                    <input type="text" value="${escapeHtml(it.kode_barang)}" onchange="updateItemField(${idx}, 'kode_barang', this.value)" placeholder="Kode Barang" ${isCurrentRiLocked ? 'disabled' : ''}>
                    ${!isCurrentRiLocked ? `<button type="button" class="ant-btn ant-btn-sm" onclick="openMaterialModalFor(${idx})" title="Cari Material"><i class="fa-solid fa-magnifying-glass"></i></button>` : ''}
                </div>
            </td>
            <td><input type="text" value="${escapeHtml(it.uraian)}" onchange="updateItemField(${idx}, 'uraian', this.value)" placeholder="Nama / Spesifikasi Barang" ${isCurrentRiLocked ? 'disabled' : ''}></td>
            <td style="text-align:right; font-weight:600; color:#475569; padding-right:8px;" title="Kuantitas tercatat pada Dokumen Pabean (Bea Cukai)">
                ${formatNum(qtyDok, 4)}
            </td>
            <td>
                <input type="number" step="0.0001" value="${qtyAkt}" style="font-weight:700; text-align:right;" onchange="updateItemField(${idx}, 'qty_aktual', this.value)" ${isCurrentRiLocked ? 'disabled' : ''} title="Kuantitas fisik aktual yang benar-benar diterima">
            </td>
            <td style="text-align:center;">
                ${isSesuai 
                    ? '<span class="ant-badge ant-badge-success" style="font-size:9.5px; font-weight:700; padding:1px 5px;" title="Kuantitas fisik sesuai dokumen">Y</span>' 
                    : '<span class="ant-badge ant-badge-danger" style="font-size:9.5px; font-weight:700; padding:1px 5px;" title="Terdapat selisih kuantitas fisik vs dokumen">N</span>'}
            </td>
            <td><input type="text" value="${escapeHtml(it.sat || 'PCE')}" style="text-align:center;" onchange="updateItemField(${idx}, 'sat', this.value)" ${isCurrentRiLocked ? 'disabled' : ''}></td>
            <td><input type="number" step="0.0001" value="${price}" style="text-align:right;" onchange="updateItemField(${idx}, 'price', this.value)" ${isCurrentRiLocked ? 'disabled' : ''}></td>
            <td><input type="number" step="0.1" value="${disc}" style="text-align:right;" onchange="updateItemField(${idx}, 'disc', this.value)" ${isCurrentRiLocked ? 'disabled' : ''}></td>
            <td style="text-align:right; font-weight:700; padding-right:6px;">${formatNum(amount)}</td>
            <td>
                <input type="text" value="${escapeHtml(it.memo || '')}" placeholder="${isDiff ? 'Wajib isi alasan selisih!' : 'Memo / Catatan'}" 
                       style="${isMissingMemo ? 'border-color:#ef4444; background:#fef2f2; font-weight:600; color:#dc2626;' : ''}" 
                       onchange="updateItemField(${idx}, 'memo', this.value)" ${isCurrentRiLocked ? 'disabled' : ''}>
            </td>
            <td><input type="text" value="${escapeHtml(it.po_no || '')}" onchange="updateItemField(${idx}, 'po_no', this.value)" placeholder="PO Acuan" ${isCurrentRiLocked ? 'disabled' : ''}></td>
            <td><input type="text" value="${escapeHtml(it.rack || it.gudang || '')}" onchange="updateItemField(${idx}, 'rack', this.value)" placeholder="No. Rak" ${isCurrentRiLocked ? 'disabled' : ''}></td>
            <td style="text-align:center;">
                <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="removeItemRow(${idx})" title="Hapus" ${isCurrentRiLocked ? 'disabled style="opacity:0.4;"' : ''}><i class="fa-solid fa-trash-can"></i></button>
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;
    calculateGrandTotal();
}

function updateItemField(idx, field, val) {
    if (riItems[idx]) {
        riItems[idx][field] = val;
        if (field === 'qty_aktual') {
            const v = parseFloat(val) || 0;
            riItems[idx].qty_aktual = v;
            riItems[idx].qty = v;
            const qDok = (riItems[idx].qty_dokumen !== undefined) ? parseFloat(riItems[idx].qty_dokumen) : v;
            const memo = (riItems[idx].memo || '').trim();
            riItems[idx].sesuai = (Math.abs(v - qDok) < 0.00001 && memo === '') ? 'Y' : 'N';
            renderItemRows();
        } else if (field === 'memo') {
            riItems[idx].memo = val.trim();
            const qAkt = parseFloat(riItems[idx].qty_aktual) || 0;
            const qDok = (riItems[idx].qty_dokumen !== undefined) ? parseFloat(riItems[idx].qty_dokumen) : qAkt;
            riItems[idx].sesuai = (Math.abs(qAkt - qDok) < 0.00001 && riItems[idx].memo === '') ? 'Y' : 'N';
            renderItemRows();
        } else if (field === 'price' || field === 'disc' || field === 'qty') {
            renderItemRows();
        }
    }
}

function addRowManual() {
    if (isCurrentRiLocked) return;
    riItems.push({
        id_po: '',
        po_no: document.getElementById('hdrPoNo').value.trim(),
        kode_barang: '',
        uraian: '',
        qty: 1,
        qty_dokumen: 1,
        qty_aktual: 1,
        sesuai: 'Y',
        memo: '',
        seri_barang: 0,
        pos_tarif: '',
        sat: 'PCE',
        price: 0,
        disc: 0,
        amount: 0,
        netto: 0,
        gross: 0,
        gudang: document.getElementById('hdrGudang').value,
        rack: '',
        dept: '',
        note: ''
    });
    renderItemRows();
}

function removeItemRow(idx) {
    if (isCurrentRiLocked) return;
    riItems.splice(idx, 1);
    renderItemRows();
}

function calculateGrandTotal() {
    let subtotal = 0;
    riItems.forEach(it => {
        const q = (it.qty_aktual !== undefined && it.qty_aktual !== null) ? parseFloat(it.qty_aktual) : (parseFloat(it.qty) || 0);
        const p = parseFloat(it.price) || 0;
        const d = parseFloat(it.disc) || 0;
        subtotal += q * p * (1 - d / 100);
    });

    const discP = parseFloat(document.getElementById('hdrDiscPersen').value) || 0;
    const discAmt = subtotal * (discP / 100);
    const ppn = parseFloat(document.getElementById('hdrPpn').value) || 0;
    const grandTotal = Math.max(0, subtotal - discAmt + ppn);

    document.getElementById('lblSubtotal').innerText = formatNum(subtotal);
    document.getElementById('lblDiscAmt').innerText = formatNum(discAmt);
    document.getElementById('lblGrandTotal').innerText = formatNum(grandTotal);
}

async function saveRiDocument() {
    if (isCurrentRiLocked) {
        Swal.fire({ icon: 'error', title: 'Terkunci', text: 'Dokumen ini telah terkunci karena sudah diposting ke IT Inventory (bc_1ab2). Batalkan posting terlebih dahulu jika ingin mengubah.' });
        return;
    }

    const vendor = document.getElementById('hdrVendor').value;
    if (!vendor) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Silakan pilih Supplier / Vendor pengirim terlebih dahulu!' });
        return;
    }
    if (riItems.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Rincian barang masih kosong. Silakan tarik dari AJU Pabean, PO, atau tambah baris!' });
        return;
    }

    // Validasi Selisih Qty vs Memo
    for (let i = 0; i < riItems.length; i++) {
        const it = riItems[i];
        const qAkt = (it.qty_aktual !== undefined) ? parseFloat(it.qty_aktual) : parseFloat(it.qty || 0);
        const qDok = (it.qty_dokumen !== undefined) ? parseFloat(it.qty_dokumen) : qAkt;
        const memo = (it.memo || '').trim();
        if (Math.abs(qAkt - qDok) > 0.00001 && memo === '') {
            Swal.fire({
                icon: 'warning',
                title: 'Alasan Selisih Wajib Diisi',
                text: `Baris #${i + 1} (${it.kode_barang || 'Item'}) memiliki perbedaan kuantitas fisik (${qAkt}) vs dokumen pabean (${qDok}). Kolom Memo/Alasan Selisih wajib diisi!`
            });
            return;
        }
    }

    let subtotal = 0;
    riItems.forEach(it => {
        const q = (it.qty_aktual !== undefined) ? parseFloat(it.qty_aktual) : (parseFloat(it.qty) || 0);
        const p = parseFloat(it.price) || 0;
        const d = parseFloat(it.disc) || 0;
        subtotal += q * p * (1 - d / 100);
    });
    const discP = parseFloat(document.getElementById('hdrDiscPersen').value) || 0;
    const discAmt = subtotal * (discP / 100);
    const ppn = parseFloat(document.getElementById('hdrPpn').value) || 0;
    const grandTotal = Math.max(0, subtotal - discAmt + ppn);

    const payload = {
        cmp_id: CURRENT_CMP_ID,
        id: document.getElementById('hdrId').value.trim(),
        ri_no: document.getElementById('hdrRiNo').value.trim(),
        ri_dt: document.getElementById('hdrRiDt').value,
        ship_dt: document.getElementById('hdrShipDt').value,
        sj_no: document.getElementById('hdrSjNo').value.trim(),
        po_no: document.getElementById('hdrPoNo').value.trim(),
        vendor: vendor,
        term: document.getElementById('hdrTerm').value,
        ship: document.getElementById('hdrShip').value,
        fob: document.getElementById('hdrFob').value,
        gudang: document.getElementById('hdrGudang').value,
        kurs: document.getElementById('hdrKurs').value,
        rate: document.getElementById('hdrRate').value,
        status: document.getElementById('hdrStatus').value,
        bc: document.getElementById('hdrBc').value,
        no_bc: document.getElementById('hdrNoBc').value.trim(),
        tgl_bc: document.getElementById('hdrTglBc').value,
        no_aju: document.getElementById('hdrNoAju').value.trim(),
        note: document.getElementById('hdrNote').value.trim(),
        subtotal: subtotal,
        disc_persen: discP,
        disc_amt: discAmt,
        ppn: ppn,
        total_order: grandTotal,
        items: riItems.map((it, idx) => ({
            ...it,
            seq: idx + 1,
            qty_dokumen: (it.qty_dokumen !== undefined) ? parseFloat(it.qty_dokumen) : parseFloat(it.qty || 0),
            qty_aktual: (it.qty_aktual !== undefined) ? parseFloat(it.qty_aktual) : parseFloat(it.qty || 0),
            qty: (it.qty_aktual !== undefined) ? parseFloat(it.qty_aktual) : parseFloat(it.qty || 0),
            sesuai: it.sesuai || 'Y',
            memo: (it.memo || '').trim()
        }))
    };

    try {
        const res = await fetch(`goods_receipt.php?action=save&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (!res.ok) {
            const errTxt = await res.text();
            throw new Error(`Server status ${res.status}: ${errTxt.substring(0, 150)}`);
        }
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('hdrId').value = json.id;
            document.getElementById('hdrRiNo').value = json.ri_no;
            document.getElementById('labelDocStatus').className = 'ant-badge ant-badge-success';
            document.getElementById('labelDocStatus').innerText = 'IN STOCK (TERSIMPAN)';

            // Tampilkan tombol Posting IT Inventory jika dokumen tersimpan dan belum dikunci
            document.getElementById('btnPostItInv').style.display = '';
            document.getElementById('btnUnpostItInv').style.display = 'none';

            Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1500, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

async function loadRiDetail(id) {
    try {
        const res = await fetch(`goods_receipt.php?action=get_detail&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            return;
        }

        const h = json.header;
        document.getElementById('hdrId').value = h.id;
        document.getElementById('hdrRiNo').value = h.ri_no || '';
        document.getElementById('hdrRiDt').value = h.ri_dt || '';
        document.getElementById('hdrShipDt').value = h.ship_dt || '';
        document.getElementById('hdrSjNo').value = h.sj_no || '';
        document.getElementById('hdrPoNo').value = h.po_no || '';
        document.getElementById('hdrVendor').value = h.vendor || '';
        document.getElementById('hdrTerm').value = h.term || 'Net 30';
        document.getElementById('hdrShip').value = h.ship || '';
        document.getElementById('hdrFob').value = h.fob || '1';
        document.getElementById('hdrGudang').value = h.gudang || 'UTAMA';
        document.getElementById('hdrKurs').value = h.kurs || 'IDR';
        document.getElementById('hdrRate').value = h.rate || '1.0000';
        document.getElementById('hdrStatus').value = h.status || '1';
        document.getElementById('hdrBc').value = h.bc || '';
        document.getElementById('hdrNoBc').value = h.no_bc || '';
        document.getElementById('hdrTglBc').value = h.tgl_bc || '';
        document.getElementById('hdrNoAju').value = h.no_aju || '';
        document.getElementById('hdrNote').value = h.note || '';
        document.getElementById('hdrDiscPersen').value = h.disc_persen || '0.00';
        document.getElementById('hdrPpn').value = h.ppn || '0.00';

        // Sinkronkan Searchable Select Supplier
        document.getElementById('hdrVendor').dispatchEvent(new Event('change', { bubbles: true }));

        // Cek Status Terkunci IT Inventory (bc_1ab2)
        isCurrentRiLocked = (parseInt(h.is_it_inv) === 1);
        const lockBanner = document.getElementById('riLockAlert');
        const lockInfo = document.getElementById('itInvPostingInfo');
        const btnPost = document.getElementById('btnPostItInv');
        const btnUnpost = document.getElementById('btnUnpostItInv');
        const btnSave = document.getElementById('btnSaveRi');
        const btnDel = document.getElementById('btnDeleteRi');
        const btnAdd = document.getElementById('btnAddRow');

        if (isCurrentRiLocked) {
            lockBanner.style.display = 'flex';
            lockInfo.innerText = `No. Bukti: ${h.it_inv_no || h.ri_no} | Tgl: ${h.it_inv_dt || '-'}`;
            btnPost.style.display = 'none';
            btnUnpost.style.display = '';
            btnSave.style.display = 'none';
            btnDel.style.display = 'none';
            btnAdd.style.display = 'none';
        } else {
            lockBanner.style.display = 'none';
            btnPost.style.display = '';
            btnUnpost.style.display = 'none';
            btnSave.style.display = '';
            btnDel.style.display = '';
            btnAdd.style.display = '';
        }

        const stText = isCurrentRiLocked ? 'POSTED IT INV' : ((h.status === '2') ? 'INVOICED' : ((h.status === '1') ? 'IN STOCK' : 'DRAFT'));
        const stCls = isCurrentRiLocked ? 'ant-badge-company' : ((h.status === '2') ? 'ant-badge-company' : ((h.status === '1') ? 'ant-badge-success' : 'ant-badge-warning'));
        document.getElementById('labelDocStatus').className = 'ant-badge ' + stCls;
        document.getElementById('labelDocStatus').innerText = stText;

        riItems = (json.details || []).map(d => ({
            ...d,
            qty: parseFloat(d.qty_aktual > 0 ? d.qty_aktual : d.qty),
            qty_aktual: parseFloat(d.qty_aktual > 0 ? d.qty_aktual : d.qty),
            qty_dokumen: parseFloat(d.qty_dokumen > 0 ? d.qty_dokumen : d.qty),
            sesuai: d.sesuai || 'Y',
            memo: d.memo || ''
        }));
        renderItemRows();

        // Render Realisasi PI
        const piTbody = document.getElementById('tbodyPiRealization');
        if (json.pi_realization && json.pi_realization.length > 0) {
            let piHtml = '';
            json.pi_realization.forEach((pr, i) => {
                piHtml += `<tr>
                    <td style="text-align:center;">${i + 1}</td>
                    <td><strong>${escapeHtml(pr.pi_no)}</strong></td>
                    <td>${escapeHtml(pr.pi_dt_formatted)}</td>
                    <td>${escapeHtml(pr.kode_barang)}</td>
                    <td>${escapeHtml(pr.uraian)}</td>
                    <td style="text-align:right; font-weight:600;">${formatNum(pr.qty)}</td>
                    <td style="text-align:center;">${escapeHtml(pr.sat)}</td>
                    <td style="text-align:right;">${formatNum(pr.price)}</td>
                    <td style="text-align:right; font-weight:700;">${formatNum(pr.amount)}</td>
                </tr>`;
            });
            piTbody.innerHTML = piHtml;
        } else {
            piTbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">Belum ada faktur pembelian yang menagih dokumen ini.</td></tr>';
        }

        switchMainTab('form');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

async function deleteRiDocument() {
    if (isCurrentRiLocked) {
        Swal.fire({ icon: 'error', title: 'Terkunci', text: 'Dokumen ini tidak dapat dihapus karena sudah diposting ke IT Inventory (bc_1ab2). Batalkan posting terlebih dahulu.' });
        return;
    }

    const id = document.getElementById('hdrId').value.trim();
    const riNo = document.getElementById('hdrRiNo').value.trim() || 'Dokumen Ini';
    if (!id) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada dokumen yang dipilih untuk dihapus.' });
        return;
    }

    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus Dokumen Penerimaan Barang "${riNo}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ff4d4f'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('cmp_id', CURRENT_CMP_ID);
        const res = await fetch('goods_receipt.php?action=delete', { method: 'POST', body: fd });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1500, showConfirmButton: false });
            newRiDocument();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

function printRiDocument() {
    const id = document.getElementById('hdrId').value.trim();
    if (!id) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Silakan simpan dokumen terlebih dahulu sebelum mencetak.' });
        return;
    }
    window.open(`goods_receipt.php?action=print&id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function printRiQrLabel() {
    const riNo = (document.getElementById('hdrRiNo')?.value || '').trim();
    if (!riNo) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Silakan simpan dokumen terlebih dahulu sebelum mencetak Label QR Dokumen BC.' });
        return;
    }
    window.open(`inv_qr_label.php?ref_type=RI&ref_no=${encodeURIComponent(riNo)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

// -------------------------------------------------------------------------
// POSTING & UNPOSTING KE IT INVENTORY (bc_1ab2)
// -------------------------------------------------------------------------
async function postToItInventory() {
    const id = document.getElementById('hdrId').value.trim();
    const riNo = document.getElementById('hdrRiNo').value.trim() || 'Dokumen RI';
    if (!id) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Silakan simpan dokumen RI terlebih dahulu sebelum memposting ke IT Inventory!' });
        return;
    }

    // Validasi Selisih
    for (let i = 0; i < riItems.length; i++) {
        const it = riItems[i];
        const qAkt = (it.qty_aktual !== undefined) ? parseFloat(it.qty_aktual) : parseFloat(it.qty || 0);
        const qDok = (it.qty_dokumen !== undefined) ? parseFloat(it.qty_dokumen) : qAkt;
        const memo = (it.memo || '').trim();
        if (Math.abs(qAkt - qDok) > 0.00001 && memo === '') {
            Swal.fire({
                icon: 'warning',
                title: 'Alasan Selisih Wajib Diisi',
                text: `Baris #${i + 1} (${it.kode_barang || 'Item'}) memiliki selisih fisik (${qAkt} vs ${qDok}). Harap isi kolom Memo/Alasan Selisih dan simpan dokumen terlebih dahulu!`
            });
            return;
        }
    }

    const ask = await Swal.fire({
        title: 'Posting ke IT Inventory?',
        html: `Apakah Anda yakin ingin memposting <b>${riNo}</b> (${riItems.length} item) ke IT Inventory <code>bc_1ab2</code> (Laporan 1A - Pemasukan)?<br><br><span style="font-size:10.5px; color:#555;">Nomor Bukti dan Tanggal Bukti pada kartu stok akan dicatat sebagai <b>${escapeHtml(riNo)}</b>.</span>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Posting Sekarang',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#059669'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('cmp_id', CURRENT_CMP_ID);
        const res = await fetch('goods_receipt.php?action=post_it_inv', { method: 'POST', body: fd });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status === 'OK') {
            await Swal.fire({ icon: 'success', title: 'Terposting', text: json.message, timer: 1800, showConfirmButton: false });
            loadRiDetail(id);
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal Posting', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

async function unpostFromItInventory() {
    const id = document.getElementById('hdrId').value.trim();
    const riNo = document.getElementById('hdrRiNo').value.trim() || 'Dokumen RI';
    if (!id) return;

    const ask = await Swal.fire({
        title: 'Buka Kunci / Batalkan Posting?',
        html: `Apakah Anda yakin ingin membatalkan posting IT Inventory untuk <b>${riNo}</b>?<br><br><span style="font-size:11px; color:#dc2626;">Data transaksi pemasukan pada kartu stok <code>bc_1ab2</code> akan dihapus dan dokumen RI akan dibuka kembali untuk diedit.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Batalkan Posting',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#dc2626'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('cmp_id', CURRENT_CMP_ID);
        const res = await fetch('goods_receipt.php?action=unpost_it_inv', { method: 'POST', body: fd });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status === 'OK') {
            await Swal.fire({ icon: 'success', title: 'Posting Dibatalkan', text: json.message, timer: 1800, showConfirmButton: false });
            loadRiDetail(id);
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

// -------------------------------------------------------------------------
// MODAL: TARIK BARANG DARI AJU PABEAN (CEISA 4.0 STAGING)
// -------------------------------------------------------------------------
function initAjuDateFilterDefaults() {
    const tglAwalEl = document.getElementById('ajuTglAwal');
    const tglAkhirEl = document.getElementById('ajuTglAkhir');
    if (tglAwalEl && tglAkhirEl && (!tglAwalEl.value || !tglAkhirEl.value)) {
        const today = new Date();
        const pad = n => String(n).padStart(2, '0');
        const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
        tglAkhirEl.value = fmt(today);
        const past = new Date();
        past.setDate(past.getDate() - 60);
        tglAwalEl.value = fmt(past);
    }
}

function setAjuDateRange(mode) {
    const today = new Date();
    const pad = n => String(n).padStart(2, '0');
    const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;

    const tglAwalEl = document.getElementById('ajuTglAwal');
    const tglAkhirEl = document.getElementById('ajuTglAkhir');
    if (!tglAwalEl || !tglAkhirEl) return;

    if (mode === 'month') {
        tglAkhirEl.value = fmt(today);
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        tglAwalEl.value = fmt(firstDay);
    } else if (mode === 'all') {
        tglAwalEl.value = '';
        tglAkhirEl.value = '';
    } else {
        const days = parseInt(mode) || 60;
        tglAkhirEl.value = fmt(today);
        const past = new Date();
        past.setDate(past.getDate() - days);
        tglAwalEl.value = fmt(past);
    }
    searchStagingAju();
}

function resetAjuFilter() {
    document.getElementById('ajuSearch').value = '';
    if (document.getElementById('ajuBcDoc')) document.getElementById('ajuBcDoc').value = 'ALL';
    setAjuDateRange(60);
}

let isAjuPickerAttachOnly = false;

function openAjuPickerModal(attachOnly = false) {
    if (isCurrentRiLocked) {
        Swal.fire({ icon: 'warning', title: 'Terkunci', text: 'Dokumen ini telah terposting ke IT Inventory. Batalkan posting terlebih dahulu jika ingin mengganti data.' });
        return;
    }
    isAjuPickerAttachOnly = !!attachOnly;
    const modalTitleEl = document.getElementById('lblModalAjuTitle');
    const btnImportEl = document.getElementById('lblBtnImportAju');
    const poBoxEl = document.getElementById('boxAjuPoSelector');

    if (isAjuPickerAttachOnly) {
        if (modalTitleEl) modalTitleEl.innerHTML = '<i class="fa-solid fa-link" style="color:var(--primary);"></i> Tempelkan Dokumen Pabean (CEISA) ke Dokumen GRN';
        if (btnImportEl) btnImportEl.innerText = 'Pasangkan Data Pabean ke Form Header';
        if (poBoxEl) poBoxEl.style.display = 'none';
    } else {
        if (modalTitleEl) modalTitleEl.innerHTML = '<i class="fa-solid fa-file-import" style="color:var(--primary);"></i> Tarik Barang dari Dokumen Pabean (CEISA 4.0 Staging)';
        if (btnImportEl) btnImportEl.innerText = 'Impor Barang ke Form Penerimaan (RI)';
        if (poBoxEl) poBoxEl.style.display = 'flex';
    }

    document.getElementById('modalAjuPicker').classList.add('active');
    initAjuDateFilterDefaults();
    searchStagingAju();
}

function closeAjuPickerModal() {
    document.getElementById('modalAjuPicker').classList.remove('active');
}

function onAjuPoSelected(val) {
    if (currentAjuData && currentAjuData.nomor_aju) {
        selectAjuForDetails(currentAjuData.nomor_aju, val);
    }
}

async function searchStagingAju() {
    const q = document.getElementById('ajuSearch').value.trim();
    const bcDoc = document.getElementById('ajuBcDoc') ? document.getElementById('ajuBcDoc').value : 'ALL';
    const tglAwal = document.getElementById('ajuTglAwal') ? document.getElementById('ajuTglAwal').value : '';
    const tglAkhir = document.getElementById('ajuTglAkhir') ? document.getElementById('ajuTglAkhir').value : '';

    const container = document.getElementById('listAjuContainer');
    container.innerHTML = '<div style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mencari dokumen pabean...</div>';
    document.getElementById('btnImportAjuToRi').disabled = true;
    document.getElementById('btnImportAjuToRi').style.opacity = '0.6';
    document.getElementById('tbodyAjuItemsPreview').innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Pilih salah satu dokumen AJU di sebelah kiri.</td></tr>';

    try {
        const params = new URLSearchParams({
            action: 'lookup_staging_aju',
            q: q,
            bc_doc: bcDoc,
            tgl_awal: tglAwal,
            tgl_akhir: tglAkhir,
            cmp_id: CURRENT_CMP_ID
        });
        const res = await fetch(`goods_receipt.php?${params.toString()}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            container.innerHTML = `<div style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(json.message)}</div>`;
            return;
        }

        currentAjuList = json.data || [];
        if (currentAjuList.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada dokumen pabean ber-Nomor Daftar yang sesuai kriteria filter.</div>';
            return;
        }

        let html = '';
        currentAjuList.forEach(a => {
            const isInv = parseInt(a.is_it_inv) > 0;
            const invBadge = isInv 
                ? `<span class="ant-badge" style="background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; font-size:8.5px;" title="Sudah pernah diposting ke IT Inventory (${escapeHtml(a.it_no_bukti || '')})">POSTED</span>` 
                : `<span class="ant-badge" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; font-size:8.5px;">UNPOSTED</span>`;

            html += `<div style="padding:6px 8px; border-bottom:1px solid var(--border-light); cursor:pointer; transition:all 0.15s;" 
                         onmouseover="this.style.background='var(--table-hover)'" 
                         onmouseout="this.style.background='transparent'" 
                         onclick="selectAjuForDetails('${escapeHtml(a.nomor_aju)}')">
                <div style="font-weight:700; color:var(--primary); display:flex; justify-content:space-between; font-size:10px;">
                    <span>${escapeHtml(a.nomor_daftar || '-')} (${escapeHtml(a.bc)})</span>
                    <span>${invBadge}</span>
                </div>
                <div style="font-family:'JetBrains Mono', Consolas, monospace; font-size:9px; color:#475569;">${escapeHtml(a.nomor_aju)}</div>
                <div style="font-size:10px; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(a.supplier_name)}</div>
                <div style="font-size:9px; color:var(--text-muted); display:flex; justify-content:space-between; margin-top:2px;">
                    <span>Tgl Daftar: ${escapeHtml(a.tgl_daftar_fmt)}</span>
                    <span style="color:#0284c7; font-weight:600;">${a.jml_barang} Item</span>
                </div>
            </div>`;
        });
        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = `<div style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(e.message)}</div>`;
    }
}

async function selectAjuForDetails(nomorAju, selectedPo = null) {
    const tbody = document.getElementById('tbodyAjuItemsPreview');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mengambil rincian barang pabean...</td></tr>';
    document.getElementById('btnImportAjuToRi').disabled = true;
    document.getElementById('btnImportAjuToRi').style.opacity = '0.6';

    try {
        let url = `goods_receipt.php?action=get_staging_barang&nomor_aju=${encodeURIComponent(nomorAju)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
        if (selectedPo !== null && selectedPo !== undefined) {
            url += `&po_no=${encodeURIComponent(selectedPo)}`;
        } else {
            const curPo = document.getElementById('hdrPoNo')?.value?.trim();
            if (curPo) url += `&po_no=${encodeURIComponent(curPo)}`;
        }

        const res = await fetch(url);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(json.message)}</td></tr>`;
            return;
        }

        currentAjuData = json;
        const items = json.items || [];
        document.getElementById('lblPreviewAjuTitle').innerText = `Rincian Barang (${items.length} Item) - AJU: ${nomorAju.substring(nomorAju.length - 8)}`;
        
        if (json.is_posted) {
            document.getElementById('lblPreviewAjuStatus').style.display = 'inline-block';
            document.getElementById('lblPreviewAjuStatus').className = 'ant-badge ant-badge-warning';
            document.getElementById('lblPreviewAjuStatus').innerText = `Sudah Pernah Di-Post (Bukti: ${json.posted_info.no_bukti || '-'})`;
        } else {
            document.getElementById('lblPreviewAjuStatus').style.display = 'none';
        }

        // Render Dropdown Open POs
        const poSelect = document.getElementById('ajuPoSelector');
        const matchBadge = document.getElementById('ajuPoMatchBadge');
        if (poSelect) {
            let poOptHtml = '<option value="">-- Tanpa PO (Non-PO / Standalone Dokumen BC) --</option>';
            (json.open_pos || []).forEach(po => {
                const isSel = (po.po_no === json.selected_po) ? 'selected' : '';
                poOptHtml += `<option value="${escapeHtml(po.po_no)}" ${isSel}>${escapeHtml(po.po_no)} | Tgl: ${escapeHtml(po.po_dt_fmt)} (${po.jml_item} Item)</option>`;
            });
            poSelect.innerHTML = poOptHtml;
        }

        // Hitung berapa item yang berhasil terlink
        const linkedCount = items.filter(it => it.is_po_linked).length;
        if (matchBadge) {
            if (json.selected_po && linkedCount > 0) {
                matchBadge.style.display = 'inline-block';
                matchBadge.className = 'ant-badge ant-badge-success';
                matchBadge.innerText = `${linkedCount} dari ${items.length} Item Cocok dg PO`;
            } else if (json.selected_po && linkedCount === 0) {
                matchBadge.style.display = 'inline-block';
                matchBadge.className = 'ant-badge ant-badge-warning';
                matchBadge.innerText = `0 Item Cocok dg PO`;
            } else {
                matchBadge.style.display = 'none';
            }
        }

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada rincian barang pada dokumen ini.</td></tr>';
            return;
        }

        let html = '';
        items.forEach((it, idx) => {
            const linkStatus = it.is_po_linked 
                ? `<span class="ant-badge ant-badge-success" style="font-size:8.5px;" title="Terhubung ke baris PO: ${escapeHtml(it.po_no)}"><i class="fa-solid fa-link"></i> ${escapeHtml(it.po_no)} (Sisa: ${formatNum(it.sisa_po_qty)})</span>`
                : (json.selected_po ? `<span class="ant-badge ant-badge-warning" style="font-size:8.5px;">Tidak di PO</span>` : `<span class="ant-badge" style="background:#f1f5f9; color:#64748b; font-size:8.5px;">Non-PO</span>`);

            html += `<tr>
                <td style="text-align:center; color:var(--text-muted); font-size:9.5px;">${idx + 1}</td>
                <td>
                    <strong>${escapeHtml(it.kode_barang)}</strong><br>
                    <span style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(it.uraian)}</span>
                </td>
                <td style="text-align:right; font-weight:700;">${formatNum(it.qty_dokumen)}</td>
                <td style="text-align:center;">${escapeHtml(it.sat)}</td>
                <td style="text-align:left;">${linkStatus}</td>
            </tr>`;
        });
        tbody.innerHTML = html;

        // Aktifkan tombol Impor
        document.getElementById('btnImportAjuToRi').disabled = false;
        document.getElementById('btnImportAjuToRi').style.opacity = '1';
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(e.message)}</td></tr>`;
    }
}

function importAjuItemsToRi() {
    if (!currentAjuData) return;
    const h = currentAjuData.header;
    const items = currentAjuData.items || [];

    // Map Header Kepabeanan
    let bcCode = h.kode_dokumen || '23';
    if (bcCode === '23') bcCode = 'BC 2.3';
    else if (bcCode === '40') bcCode = 'BC 4.0';
    else if (bcCode === '27') bcCode = 'BC 2.7';
    else if (bcCode === '262') bcCode = 'BC 2.6.2';
    else if (bcCode === '20') bcCode = 'BC 2.0';

    document.getElementById('hdrBc').value = bcCode;
    document.getElementById('hdrNoBc').value = h.nomor_daftar || '';
    document.getElementById('hdrTglBc').value = h.tanggal_daftar || '';
    document.getElementById('hdrNoAju').value = currentAjuData.nomor_aju;

    // Map Dokumen Referensi (SJ / Invoice)
    if (currentAjuData.doc_reference && currentAjuData.doc_reference.nomor_dokumen) {
        document.getElementById('hdrSjNo').value = currentAjuData.doc_reference.nomor_dokumen;
        if (currentAjuData.doc_reference.tanggal_dokumen) {
            document.getElementById('hdrShipDt').value = currentAjuData.doc_reference.tanggal_dokumen;
        }
    }

    // Set Mata Uang
    if (h.kode_valuta) document.getElementById('hdrKurs').value = h.kode_valuta;

    // Jika mode Attach Only: Cukup update Header Dokumen Pabean, jangan timpa item barang yang sudah ada
    if (isAjuPickerAttachOnly) {
        closeAjuPickerModal();
        Swal.fire({
            icon: 'success',
            title: 'Dokumen Pabean Dipasangkan',
            text: `Data Pabean (${bcCode} No. ${h.nomor_daftar || '-'}) berhasil ditempelkan ke dokumen penerimaan ini.`,
            timer: 1800,
            showConfirmButton: false
        });
        return;
    }

    // Map Supplier
    if (currentAjuData.matched_vendor && currentAjuData.matched_vendor.KODE) {
        document.getElementById('hdrVendor').value = currentAjuData.matched_vendor.KODE;
        if (currentAjuData.matched_vendor.PAY_TERM) document.getElementById('hdrTerm').value = currentAjuData.matched_vendor.PAY_TERM;
        if (currentAjuData.matched_vendor.kurs) document.getElementById('hdrKurs').value = currentAjuData.matched_vendor.kurs;
        document.getElementById('hdrVendor').dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Ambil PO yang dipilih di modal
    const selPo = document.getElementById('ajuPoSelector')?.value || currentAjuData.selected_po || document.getElementById('hdrPoNo').value.trim();
    if (selPo) {
        document.getElementById('hdrPoNo').value = selPo;
    }

    // Masukkan Items ke Form RI lengkap dengan tautan PO (id_po & po_no)
    riItems = items.map(b => ({
        id_po: b.id_po || '',
        po_no: b.po_no || selPo,
        kode_barang: b.kode_barang,
        uraian: b.uraian,
        qty_dokumen: b.qty_dokumen,
        qty_aktual: b.qty_aktual,
        qty: b.qty_aktual,
        sesuai: 'Y',
        memo: '',
        seri_barang: b.seri_barang,
        pos_tarif: b.pos_tarif,
        sat: b.sat,
        price: b.price,
        disc: 0,
        amount: b.amount,
        netto: b.netto,
        gross: b.gross,
        cif: b.cif,
        gudang: document.getElementById('hdrGudang').value,
        rack: '',
        dept: '',
        note: b.is_po_linked ? `Ref PO: ${b.po_no}` : ''
    }));

    closeAjuPickerModal();
    renderItemRows();
    const linkedMsg = items.filter(x => x.is_po_linked).length > 0 ? ` & ditautkan ke PO ${selPo}` : '';
    Swal.fire({
        icon: 'success',
        title: 'Barang Berhasil Diimpor',
        text: `${items.length} item barang dari dokumen pabean ${currentAjuData.nomor_aju.substring(currentAjuData.nomor_aju.length - 8)} berhasil dimasukkan${linkedMsg}.`,
        timer: 1800,
        showConfirmButton: false
    });
}

// -------------------------------------------------------------------------
// MODAL: TARIK ITEMS DARI OUTSTANDING PO
// -------------------------------------------------------------------------
function openPoPickerModal() {
    if (isCurrentRiLocked) return;
    document.getElementById('modalPoPicker').classList.add('active');
    searchOpenPo();
}
function closePoPickerModal() {
    document.getElementById('modalPoPicker').classList.remove('active');
}

async function searchOpenPo() {
    const q = document.getElementById('popSearch').value.trim();
    const vendor = document.getElementById('hdrVendor').value;
    const container = document.getElementById('listOpenPoContainer');
    container.innerHTML = '<div style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data PO...</div>';

    try {
        const res = await fetch(`goods_receipt.php?action=lookup_open_po&search=${encodeURIComponent(q)}&vendor=${encodeURIComponent(vendor)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            container.innerHTML = `<div style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(json.message)}</div>`;
            return;
        }

        currentPoList = json.data || [];
        if (currentPoList.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada PO yang masih memiliki outstanding.</div>';
            return;
        }

        let html = '';
        currentPoList.forEach(po => {
            html += `<div style="padding:6px 8px; border-bottom:1px solid var(--border-light); cursor:pointer; transition:all 0.15s;" 
                         onmouseover="this.style.background='var(--table-hover)'" 
                         onmouseout="this.style.background='transparent'" 
                         onclick="selectPoForDetails('${escapeHtml(po.po_no)}')">
                <div style="font-weight:700; color:var(--primary); display:flex; justify-content:space-between;">
                    <span>${escapeHtml(po.po_no)}</span>
                    <span style="font-size:9px; color:var(--text-muted);">${escapeHtml(po.po_dt_formatted)}</span>
                </div>
                <div style="font-size:10px; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(po.vendor_nama)}</div>
                <div style="font-size:9px; color:var(--text-muted); display:flex; justify-content:space-between; margin-top:2px;">
                    <span>${po.total_items} Item</span>
                    <span style="color:#0284c7; font-weight:600;">Sisa: ${po.sisa_qty_formatted}</span>
                </div>
            </div>`;
        });
        container.innerHTML = html;
    } catch (e) {
        container.innerHTML = `<div style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(e.message)}</div>`;
    }
}

async function selectPoForDetails(poNo) {
    const tbody = document.getElementById('tbodyPoItemsPicker');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mengambil rincian item PO...</td></tr>';

    try {
        const res = await fetch(`goods_receipt.php?action=get_po_items_for_ri&po_no=${encodeURIComponent(poNo)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(json.message)}</td></tr>`;
            return;
        }

        currentPoItems = json.items || [];
        if (json.header) {
            // Auto-fill header vendor jika belum diisi
            if (!document.getElementById('hdrVendor').value && json.header.vendor) {
                document.getElementById('hdrVendor').value = json.header.vendor;
                document.getElementById('hdrVendor').dispatchEvent(new Event('change', { bubbles: true }));
            }
            document.getElementById('hdrPoNo').value = json.header.po_no;
            if (json.header.kurs) document.getElementById('hdrKurs').value = json.header.kurs;
            if (json.header.term) document.getElementById('hdrTerm').value = json.header.term;
        }

        if (currentPoItems.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada baris item pada PO ini.</td></tr>';
            return;
        }

        let html = '';
        currentPoItems.forEach((it, idx) => {
            const isZeroSisa = (it.sisa_qty <= 0);
            html += `<tr style="${isZeroSisa ? 'opacity:0.5; background:#fafafa;' : ''}">
                <td style="text-align:center;"><input type="checkbox" class="chk-po-item" data-idx="${idx}" ${isZeroSisa ? 'disabled' : 'checked'}></td>
                <td>
                    <strong>${escapeHtml(it.kode_barang)}</strong><br>
                    <span style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(it.uraian)}</span>
                </td>
                <td style="text-align:right;">${formatNum(it.qty_po)} ${escapeHtml(it.sat)}</td>
                <td style="text-align:right; font-weight:700; color:#0284c7;">${formatNum(it.sisa_qty)}</td>
                <td>
                    <input type="number" step="0.0001" id="popQty_${idx}" value="${it.qty_receive_now}" 
                           style="width:70px; text-align:right; height:20px; font-weight:700;" ${isZeroSisa ? 'disabled' : ''}>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:10px;">${escapeHtml(e.message)}</td></tr>`;
    }
}

function toggleSelectAllPoItems(checked) {
    document.querySelectorAll('.chk-po-item').forEach(chk => {
        if (!chk.disabled) chk.checked = checked;
    });
}

function insertSelectedPoItems() {
    const checkboxes = document.querySelectorAll('.chk-po-item:checked');
    if (checkboxes.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Pilih minimal 1 item barang yang akan diterima.' });
        return;
    }

    let addedCount = 0;
    checkboxes.forEach(chk => {
        const idx = parseInt(chk.getAttribute('data-idx'));
        const it = currentPoItems[idx];
        if (it) {
            const qtyInput = document.getElementById(`popQty_${idx}`);
            const qty = parseFloat(qtyInput ? qtyInput.value : it.sisa_qty) || 0;
            if (qty > 0) {
                riItems.push({
                    id_po: it.id,
                    po_no: it.po_no,
                    kode_barang: it.kode_barang,
                    uraian: it.uraian,
                    qty_dokumen: qty,
                    qty_aktual: qty,
                    qty: qty,
                    sesuai: 'Y',
                    memo: '',
                    seri_barang: 0,
                    pos_tarif: '',
                    sat: it.sat,
                    price: parseFloat(it.price) || 0,
                    disc: parseFloat(it.disc) || 0,
                    amount: qty * (parseFloat(it.price) || 0) * (1 - (parseFloat(it.disc) || 0) / 100),
                    netto: 0,
                    gross: 0,
                    gudang: document.getElementById('hdrGudang').value,
                    rack: '',
                    dept: '',
                    note: ''
                });
                addedCount++;
            }
        }
    });

    closePoPickerModal();
    renderItemRows();
    Swal.fire({ icon: 'success', title: 'Item Ditambahkan', text: `${addedCount} item barang berhasil dimasukkan ke penerimaan.`, timer: 1200, showConfirmButton: false });
}

// -------------------------------------------------------------------------
// MODAL: LOOKUP MASTER MATERIAL (bc_material)
// -------------------------------------------------------------------------
function openMaterialModalFor(idx) {
    if (isCurrentRiLocked) return;
    targetEditItemIndex = idx;
    document.getElementById('modalMaterialPicker').classList.add('active');
    searchMaterials();
}
function closeMaterialModal() {
    document.getElementById('modalMaterialPicker').classList.remove('active');
}

async function searchMaterials() {
    const q = document.getElementById('matSearch').value.trim();
    const tbody = document.getElementById('tbodyMaterialPicker');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mencari material...</td></tr>';

    try {
        const res = await fetch(`goods_receipt.php?action=lookup_materials&q=${encodeURIComponent(q)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        const items = json.data || [];
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:var(--text-muted);">Tidak ada material yang cocok.</td></tr>';
            return;
        }

        let html = '';
        items.forEach(m => {
            html += `<tr>
                <td><strong>${escapeHtml(m.KODE_BARANG)}</strong></td>
                <td>${escapeHtml(m.NAMA)} ${m.SPESIFIKASI ? `<span style="color:#666;">(${escapeHtml(m.SPESIFIKASI)})</span>` : ''}</td>
                <td style="text-align:center;">${escapeHtml(m.SATUAN)}</td>
                <td style="text-align:right;">${formatNum(m.HARGA_STANDAR)}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="pickMaterial('${escapeHtml(m.KODE_BARANG)}', '${escapeHtml(m.NAMA)}', '${escapeHtml(m.SATUAN)}', ${m.HARGA_STANDAR || 0})">Pilih</button>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:color:var(--danger); padding:10px;">${escapeHtml(e.message)}</td></tr>`;
    }
}

function pickMaterial(kode, nama, sat, harga) {
    if (targetEditItemIndex >= 0 && riItems[targetEditItemIndex]) {
        riItems[targetEditItemIndex].kode_barang = kode;
        riItems[targetEditItemIndex].uraian = nama;
        riItems[targetEditItemIndex].sat = sat || 'PCE';
        if (harga > 0 && riItems[targetEditItemIndex].price === 0) riItems[targetEditItemIndex].price = harga;
    } else {
        riItems.push({
            id_po: '',
            po_no: document.getElementById('hdrPoNo').value.trim(),
            kode_barang: kode,
            uraian: nama,
            qty_dokumen: 1,
            qty_aktual: 1,
            qty: 1,
            sesuai: 'Y',
            memo: '',
            seri_barang: 0,
            pos_tarif: '',
            sat: sat || 'PCE',
            price: harga || 0,
            disc: 0,
            amount: harga || 0,
            netto: 0,
            gross: 0,
            gudang: document.getElementById('hdrGudang').value,
            rack: '',
            dept: '',
            note: ''
        });
    }
    closeMaterialModal();
    renderItemRows();
}

// -------------------------------------------------------------------------
// BROWSE LIST FUNCTIONS (TAB 2)
// -------------------------------------------------------------------------
let currentBrowsePage = 1;

async function loadBrowseList(page = 1) {
    currentBrowsePage = page;
    const search    = document.getElementById('brwSearch').value.trim();
    let vendor      = document.getElementById('brwVendor').value;

    // Smart fallback jika user mengetik kata kunci supplier tanpa klik dropdown
    const brwVendorEl = document.getElementById('brwVendor');
    if (brwVendorEl && brwVendorEl._antSearchableInstance) {
        const inst = brwVendorEl._antSearchableInstance;
        const typed = inst.input ? inst.input.value.trim() : '';
        if (typed && !vendor) {
            const m = inst.findMatch ? inst.findMatch(typed) : null;
            if (m) {
                vendor = m.value;
                inst.setValue(m.value);
            } else {
                vendor = typed;
            }
        }
    }

    const bc        = document.getElementById('brwBc').value;
    const status    = document.getElementById('brwStatus').value;
    const tglAwal   = document.getElementById('brwTglAwal').value;
    const tglAkhir  = document.getElementById('brwTglAkhir').value;

    const tbody = document.getElementById('tbodyBrowse');
    tbody.innerHTML = '<tr><td colspan="14" style="text-align:center; padding:18px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat daftar dokumen penerimaan barang...</td></tr>';

    try {
        const qParams = new URLSearchParams({
            action: 'list',
            page: String(page),
            limit: '25',
            search: search,
            vendor: vendor,
            bc_filter: bc,
            status_filter: status,
            tgl_awal: tglAwal,
            tgl_akhir: tglAkhir,
            cmp_id: CURRENT_CMP_ID
        });

        const res = await fetch('goods_receipt.php?' + qParams.toString());
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            tbody.innerHTML = `<tr><td colspan="14" style="text-align:center; color:var(--danger); padding:12px;">${escapeHtml(json.message)}</td></tr>`;
            return;
        }

        renderBrowseRows(json.data || [], json.total, json.page, json.limit, json.total_pages);
        if (json.kpi) {
            document.getElementById('kpiTotalGrn').innerText = json.kpi.total_grn;
            document.getElementById('kpiGrnBulanIni').innerText = json.kpi.grn_bulan_ini;
            document.getElementById('kpiTotalNilai').innerText = json.kpi.total_nilai;
            document.getElementById('kpiUnbilledGrn').innerText = json.kpi.unbilled_grn;
            document.getElementById('kpiInvoicedGrn').innerText = json.kpi.invoiced_grn;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="14" style="text-align:center; color:var(--danger); padding:12px;">Kesalahan: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function renderBrowseRows(rows, total, page, limit, totalPages) {
    const tbody = document.getElementById('tbodyBrowse');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="14" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada dokumen penerimaan barang yang sesuai dengan filter pencarian.</td></tr>';
        document.getElementById('lblPageInfo').innerText = '0 dokumen ditemukan';
        document.getElementById('paginationBtns').innerHTML = '';
        return;
    }

    let html = '';
    const startNo = (page - 1) * limit + 1;
    rows.forEach((r, idx) => {
        const stCls = (r.status === '2') ? 'ant-badge-company' : ((r.status === '1') ? 'ant-badge-success' : 'ant-badge-warning');
        const stText = (r.status === '2') ? 'Invoiced' : ((r.status === '1') ? 'In Stock' : 'Draft');
        const bcBadge = r.bc ? `<span class="ant-badge" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;">${escapeHtml(r.bc)}</span>` : '<span style="color:#aaa;">-</span>';
        
        const isInv = parseInt(r.is_it_inv) === 1;
        const itInvBadge = isInv 
            ? `<span class="ant-badge" style="background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; font-weight:700;"><i class="fa-solid fa-shield-check"></i> POSTED</span>` 
            : `<span class="ant-badge" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1;">UNPOSTED</span>`;

        html += `<tr ondblclick="loadRiDetail('${escapeHtml(r.id)}')" style="cursor:pointer;">
            <td style="text-align:center; color:var(--text-muted);">${startNo + idx}</td>
            <td><strong style="color:var(--primary); font-family:'JetBrains Mono', Consolas, monospace;">${escapeHtml(r.ri_no)}</strong></td>
            <td>${escapeHtml(r.ri_dt_formatted)}</td>
            <td>${escapeHtml(r.sj_no || '-')}</td>
            <td><strong>${escapeHtml(r.po_no || '-')}</strong></td>
            <td>
                <strong>${escapeHtml(r.vendor_nama)}</strong>
                ${r.vendor_alias ? `<br><span style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.vendor_alias)}</span>` : ''}
            </td>
            <td style="text-align:center;">${bcBadge}</td>
            <td>${escapeHtml(r.gudang || 'UTAMA')}</td>
            <td style="text-align:right;">${r.item_count || 0}</td>
            <td style="text-align:right; font-weight:600;">${escapeHtml(r.total_qty_formatted)}</td>
            <td style="text-align:right; font-weight:700;">${escapeHtml(r.kurs)} ${escapeHtml(r.total_order_formatted)}</td>
            <td style="text-align:center;">${itInvBadge}</td>
            <td style="text-align:center;"><span class="ant-badge ${stCls}">${stText}</span></td>
            <td style="text-align:center;">
                <div class="inline-box" style="justify-content:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="loadRiDetail('${escapeHtml(r.id)}')" title="Buka / Edit"><i class="fa-solid fa-pen" style="color:var(--primary);"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="window.open('goods_receipt.php?action=print&id=${encodeURIComponent(r.id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}', '_blank')" title="Cetak Bukti (BPB)"><i class="fa-solid fa-print"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="window.open('inv_qr_label.php?ref_type=RI&ref_no=' + encodeURIComponent(r.ri_no) + '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID), '_blank')" title="Cetak Label QR Dokumen BC (Traceability AJU)"><i class="fa-solid fa-qrcode" style="color:#059669;"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteFromBrowse('${escapeHtml(r.id)}', '${escapeHtml(r.ri_no)}')" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </td>
        </tr>`;
    });
    tbody.innerHTML = html;

    // Info Halaman
    document.getElementById('lblPageInfo').innerText = `Menampilkan ${startNo} - ${Math.min(page * limit, total)} dari ${total} dokumen penerimaan`;

    // Tombol Navigasi Paginasi
    let pageHtml = '';
    if (totalPages > 1) {
        pageHtml += `<button type="button" class="ant-btn ant-btn-sm" ${page <= 1 ? 'disabled style="opacity:0.5;"' : ''} onclick="loadBrowseList(${page - 1})"><i class="fa-solid fa-angle-left"></i> Prev</button>`;
        pageHtml += `<span style="padding:0 6px; font-weight:600;">${page} / ${totalPages}</span>`;
        pageHtml += `<button type="button" class="ant-btn ant-btn-sm" ${page >= totalPages ? 'disabled style="opacity:0.5;"' : ''} onclick="loadBrowseList(${page + 1})">Next <i class="fa-solid fa-angle-right"></i></button>`;
    }
    document.getElementById('paginationBtns').innerHTML = pageHtml;
}

function filterBrowseStatus(st) {
    document.getElementById('brwStatus').value = st;
    loadBrowseList(1);
}
function filterBrowseCurMonth() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    document.getElementById('brwTglAwal').value = `${y}-${m}-01`;
    document.getElementById('brwTglAkhir').value = new Date(y, now.getMonth() + 1, 0).toISOString().slice(0, 10);
    loadBrowseList(1);
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
    loadBrowseList(1);
}

async function deleteFromBrowse(id, riNo) {
    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus Dokumen Penerimaan Barang "${riNo}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ff4d4f'
    });
    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('cmp_id', CURRENT_CMP_ID);
        const res = await fetch('goods_receipt.php?action=delete', { method: 'POST', body: fd });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1200, showConfirmButton: false });
            loadBrowseList(currentBrowsePage);
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

function exportBrowseCsv() {
    window.location.href = `goods_receipt.php?action=export_csv&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
}

function exportBrowseExcel() {
    const rows = [];
    document.querySelectorAll('#tbodyBrowse tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (tds.length >= 13) {
            rows.push({
                'No': tds[0].innerText.trim(),
                'No RI / GRN': tds[1].innerText.trim(),
                'Tanggal Terima': tds[2].innerText.trim(),
                'No. SJ Vendor': tds[3].innerText.trim(),
                'No. PO Acuan': tds[4].innerText.trim(),
                'Supplier': tds[5].innerText.trim(),
                'Dokumen BC': tds[6].innerText.trim(),
                'Gudang': tds[7].innerText.trim(),
                'Jml Item': tds[8].innerText.trim(),
                'Total Qty': tds[9].innerText.trim(),
                'Total Nilai': tds[10].innerText.trim(),
                'IT Inventory': tds[11].innerText.trim(),
                'Status': tds[12].innerText.trim()
            });
        }
    });
    if (rows.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }
    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Receive Item");
    XLSX.writeFile(wb, `Receive_Item_GRN_${new Date().toISOString().slice(0, 10)}.xlsx`);
}

function openModuleGuide() {
    document.getElementById('modalModuleGuide').classList.add('active');
}
function closeModuleGuide() {
    document.getElementById('modalModuleGuide').classList.remove('active');
}

// -------------------------------------------------------------------------
// INISIALISASI HALAMAN
// -------------------------------------------------------------------------
window.addEventListener('DOMContentLoaded', () => {
    // Inisialisasi Searchable Select Supplier pada Form Transaksi & Filter Browse
    if (typeof initAntSearchableSelect === 'function') {
        initAntSearchableSelect('hdrVendor', { placeholder: 'Ketik nama / kode supplier...' });
        initAntSearchableSelect('brwVendor', { placeholder: 'Semua Supplier...', width: '180px' });
    }
    newRiDocument();
});
</script>
</body>
</html>

<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Rekap Purchase Order & Hutang Usaha (AP 360°)
 * ============================================================================
 * Modul Terpadu Monitoring & Analisis:
 * 1. Matriks Rekap Hutang Per Pemasok / Vendor (Vendor AP Summary Matrix)
 * 2. Rincian Faktur Belum Lunas (Outstanding PI) & Analisis Umur Hutang (AP Aging)
 * 3. Purchase Order (PO) Aktif & Realisasi Penerimaan Barang (GRN / RI)
 * 4. Integrasi Kompensasi Retur Pembelian (Debit Note AP) & Dokumen Pabean BC
 *
 * Mengadopsi Standar Desain Ant Design Compact Desktop 9.5px Seragam ERP Hub
 * Standar Warna Badge & Accent: Amber / Gold (#f59e0b / #d97706)
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

$action = trim($_REQUEST['action'] ?? '');

// -------------------------------------------------------------------------
// API 1: KPI RINGKASAN GLOBAL (SUMMARY METRICS)
// -------------------------------------------------------------------------
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $filterVendor = trim($_GET['vendor'] ?? '');
        $vWherePi = "";
        $vWherePo = "";
        $vWherePr = "";
        if (!empty($filterVendor)) {
            $escV = $koneksi->real_escape_string($filterVendor);
            $vWherePi = " AND h.vendor = '{$escV}'";
            $vWherePo = " AND p.vendor = '{$escV}'";
            $vWherePr = " AND pr.vendor = '{$escV}'";
        }

        // 1. Total Hutang Berjalan (Unpaid PI)
        $qPi = $koneksi->query("SELECT 
                    COUNT(h.id) AS count_pi,
                    SUM(COALESCE(h.total_order, 0)) AS total_tagihan,
                    SUM(COALESCE(h.terbayar, 0)) AS total_terbayar,
                    SUM(COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) AS total_sisa_ap,
                    SUM(CASE WHEN h.jatuhtempo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
                             THEN (COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) ELSE 0 END) AS due_soon_ap,
                    SUM(CASE WHEN h.jatuhtempo < CURDATE() 
                             THEN (COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) ELSE 0 END) AS overdue_ap
                 FROM bc_pi_hdr h
                 WHERE (h.status = '0' OR h.status = '1')
                   AND (COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) > 0.01
                   AND (h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)
                   {$vWherePi}");
        $piKpi = ($qPi && $rPi = $qPi->fetch_assoc()) ? $rPi : [];

        // 2. Total Open PO (Purchase Order Aktif / Berjalan)
        $qPo = $koneksi->query("SELECT 
                    COUNT(p.id) AS count_po,
                    SUM(COALESCE(p.total_order, 0)) AS total_open_po
                 FROM bc_po_hdr p
                 WHERE (p.status = '0' OR p.status = '1')
                   AND (p.cmp_id = '{$escCmp}' OR p.cmp_id = '' OR p.cmp_id IS NULL)
                   {$vWherePo}");
        $poKpi = ($qPo && $rPo = $qPo->fetch_assoc()) ? $rPo : [];

        // 3. Total Retur Pending (Debit Note Belum Dikompensasikan)
        $qPr = $koneksi->query("SELECT 
                    COUNT(pr.id) AS count_pr,
                    SUM(COALESCE(pr.total_order, 0)) AS total_retur_open
                 FROM bc_pr_hdr pr
                 WHERE (pr.status = '0' OR pr.status = '1')
                   AND (pr.cmp_id = '{$escCmp}' OR pr.cmp_id = '' OR pr.cmp_id IS NULL)
                   {$vWherePr}");
        $prKpi = ($qPr && $rPr = $qPr->fetch_assoc()) ? $rPr : [];

        // 4. Hitung Vendor Aktif
        $qVendors = $koneksi->query("SELECT COUNT(DISTINCT vendor) AS active_vendors FROM (
            SELECT vendor FROM bc_pi_hdr WHERE (status='0' OR status='1') AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)
            UNION
            SELECT vendor FROM bc_po_hdr WHERE (status='0' OR status='1') AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)
        ) AS t WHERE vendor IS NOT NULL AND vendor != ''");
        $vendorCount = ($qVendors && $rV = $qVendors->fetch_assoc()) ? (int)$rV['active_vendors'] : 0;

        $totAp = (float)($piKpi['total_sisa_ap'] ?? 0);
        $totRetur = (float)($prKpi['total_retur_open'] ?? 0);
        $netAp = max(0, $totAp - $totRetur);

        echo json_encode([
            'status' => 'OK',
            'data'   => [
                'total_hutang'     => $totAp,
                'total_tagihan'    => (float)($piKpi['total_tagihan'] ?? 0),
                'total_terbayar'   => (float)($piKpi['total_terbayar'] ?? 0),
                'due_soon_ap'      => (float)($piKpi['due_soon_ap'] ?? 0),
                'overdue_ap'       => (float)($piKpi['overdue_ap'] ?? 0),
                'count_unpaid_pi'  => (int)($piKpi['count_pi'] ?? 0),
                'total_open_po'    => (float)($poKpi['total_open_po'] ?? 0),
                'count_open_po'    => (int)($poKpi['count_po'] ?? 0),
                'total_retur_open' => $totRetur,
                'count_retur_open' => (int)($prKpi['count_pr'] ?? 0),
                'net_hutang'       => $netAp,
                'active_vendors'   => $vendorCount
            ]
        ]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// API 2: MATRIKS REKAP HUTANG PER VENDOR (VENDOR AP SUMMARY MATRIX)
// -------------------------------------------------------------------------
if ($action === 'vendor_matrix') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $search = trim($_GET['search'] ?? '');
        $statusFilter = trim($_GET['status_filter'] ?? 'all'); // all, with_debt, overdue, with_po

        $whereClause = "(b.TIPE = 'S' OR b.TIPE = 'B' OR b.TIPE = 'VENDOR' OR b.TIPE IS NULL OR b.TIPE = '')
                        AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL)";
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $whereClause .= " AND (b.KODE LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%' OR b.ALAMAT LIKE '%{$escS}%')";
        }

        $sql = "SELECT b.KODE AS vendor_kode, b.NAMA AS vendor_nama, b.NAMA_PENDEK, b.ALAMAT, b.NPWP, b.TELP,
                       b.PAY_TERM AS top, b.kurs AS currency,
                       -- Unpaid PI Summary
                       COALESCE(pi.count_pi, 0) AS count_pi,
                       COALESCE(pi.total_tagihan, 0) AS total_tagihan,
                       COALESCE(pi.total_terbayar, 0) AS total_terbayar,
                       COALESCE(pi.sisa_hutang, 0) AS sisa_hutang,
                       COALESCE(pi.overdue_hutang, 0) AS overdue_hutang,
                       -- Open PO Summary
                       COALESCE(po.count_po, 0) AS count_po,
                       COALESCE(po.total_po, 0) AS total_po,
                       -- Open PR Summary
                       COALESCE(pr.count_pr, 0) AS count_pr,
                       COALESCE(pr.total_retur, 0) AS total_retur,
                       -- Net Hutang = Sisa Hutang - Retur Pending
                       GREATEST(0, (COALESCE(pi.sisa_hutang, 0) - COALESCE(pr.total_retur, 0))) AS net_hutang
                FROM bc_bs b
                LEFT JOIN (
                    SELECT vendor,
                           COUNT(id) AS count_pi,
                           SUM(total_order) AS total_tagihan,
                           SUM(terbayar) AS total_terbayar,
                           SUM(COALESCE(total_order, 0) - COALESCE(terbayar, 0)) AS sisa_hutang,
                           SUM(CASE WHEN jatuhtempo < CURDATE() THEN (COALESCE(total_order, 0) - COALESCE(terbayar, 0)) ELSE 0 END) AS overdue_hutang
                    FROM bc_pi_hdr
                    WHERE (status = '0' OR status = '1')
                      AND (COALESCE(total_order, 0) - COALESCE(terbayar, 0)) > 0.01
                      AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)
                    GROUP BY vendor
                ) pi ON b.KODE = pi.vendor
                LEFT JOIN (
                    SELECT vendor,
                           COUNT(id) AS count_po,
                           SUM(total_order) AS total_po
                    FROM bc_po_hdr
                    WHERE (status = '0' OR status = '1')
                      AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)
                    GROUP BY vendor
                ) po ON b.KODE = po.vendor
                LEFT JOIN (
                    SELECT vendor,
                           COUNT(id) AS count_pr,
                           SUM(total_order) AS total_retur
                    FROM bc_pr_hdr
                    WHERE (status = '0' OR status = '1')
                      AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)
                    GROUP BY vendor
                ) pr ON b.KODE = pr.vendor
                WHERE {$whereClause}";

        if ($statusFilter === 'with_debt') {
            $sql .= " AND COALESCE(pi.sisa_hutang, 0) > 0.01";
        } elseif ($statusFilter === 'overdue') {
            $sql .= " AND COALESCE(pi.overdue_hutang, 0) > 0.01";
        } elseif ($statusFilter === 'with_po') {
            $sql .= " AND COALESCE(po.count_po, 0) > 0";
        }

        $sql .= " ORDER BY (COALESCE(pi.sisa_hutang, 0)) DESC, b.NAMA ASC";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['sisa_fmt']     = number_format((float)$r['sisa_hutang'], 2, '.', ',');
                $r['tagihan_fmt']  = number_format((float)$r['total_tagihan'], 2, '.', ',');
                $r['terbayar_fmt'] = number_format((float)$r['total_terbayar'], 2, '.', ',');
                $r['overdue_fmt']  = number_format((float)$r['overdue_hutang'], 2, '.', ',');
                $r['po_fmt']       = number_format((float)$r['total_po'], 2, '.', ',');
                $r['retur_fmt']    = number_format((float)$r['total_retur'], 2, '.', ',');
                $r['net_fmt']      = number_format((float)$r['net_hutang'], 2, '.', ',');
                $rows[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $rows]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// API 3: RINCIAN FAKTUR BELUM LUNAS (OUTSTANDING PI & AP AGING)
// -------------------------------------------------------------------------
if ($action === 'outstanding_pi') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor = trim($_GET['vendor'] ?? '');
        $agingBucket = trim($_GET['aging_bucket'] ?? 'all'); // all, current, 1-30, 31-60, 61-90, >90
        $search = trim($_GET['search'] ?? '');

        $where = ["(h.status = '0' OR h.status = '1')",
                  "(COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) > 0.01",
                  "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)"];

        if (!empty($vendor)) {
            $escV = $koneksi->real_escape_string($vendor);
            $where[] = "h.vendor = '{$escV}'";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(h.pi_no LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%' OR h.no_bc LIKE '%{$escS}%' OR h.no_aju LIKE '%{$escS}%')";
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT h.id, h.pi_no, h.pi_dt, h.jatuhtempo, h.vendor, 
                       COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                       h.term, h.kurs, h.rate,
                       h.subtotal, h.ppn, h.total_order, h.terbayar,
                       (COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) AS sisa_hutang,
                       h.bc, h.no_bc, h.tgl_bc, h.no_aju,
                       DATEDIFF(CURDATE(), h.jatuhtempo) AS aging_days
                FROM bc_pi_hdr h
                LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                WHERE {$whereSql}
                ORDER BY h.jatuhtempo ASC, h.pi_dt ASC";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $days = (int)($r['aging_days'] ?? 0);
                $bucket = 'CURRENT';
                $badgeClass = 'tag-current';

                if ($days > 90) {
                    $bucket = '>90';
                    $badgeClass = 'tag-overdue-90';
                } elseif ($days > 60) {
                    $bucket = '61-90';
                    $badgeClass = 'tag-overdue-60';
                } elseif ($days > 30) {
                    $bucket = '31-60';
                    $badgeClass = 'tag-overdue-30';
                } elseif ($days > 0) {
                    $bucket = '1-30';
                    $badgeClass = 'tag-overdue-1';
                }

                if ($agingBucket !== 'all') {
                    if ($agingBucket === 'current' && $bucket !== 'CURRENT') continue;
                    if ($agingBucket === '1-30' && $bucket !== '1-30') continue;
                    if ($agingBucket === '31-60' && $bucket !== '31-60') continue;
                    if ($agingBucket === '61-90' && $bucket !== '61-90') continue;
                    if ($agingBucket === '>90' && $bucket !== '>90') continue;
                }

                $r['aging_bucket'] = $bucket;
                $r['badge_class']  = $badgeClass;
                $r['pi_dt_fmt']    = !empty($r['pi_dt']) ? date('d/m/Y', strtotime($r['pi_dt'])) : '-';
                $r['jt_fmt']       = !empty($r['jatuhtempo']) ? date('d/m/Y', strtotime($r['jatuhtempo'])) : '-';
                $r['total_fmt']    = number_format((float)$r['total_order'], 2, '.', ',');
                $r['terbayar_fmt'] = number_format((float)$r['terbayar'], 2, '.', ',');
                $r['sisa_fmt']     = number_format((float)$r['sisa_hutang'], 2, '.', ',');
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// API 4: PURCHASE ORDER (PO) AKTIF & REALISASI PENERIMAAN BARANG (OPEN PO)
// -------------------------------------------------------------------------
if ($action === 'open_po') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $vendor = trim($_GET['vendor'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $where = ["(p.status = '0' OR p.status = '1')",
                  "(p.cmp_id = '{$escCmp}' OR p.cmp_id = '' OR p.cmp_id IS NULL)"];

        if (!empty($vendor)) {
            $escV = $koneksi->real_escape_string($vendor);
            $where[] = "p.vendor = '{$escV}'";
        }
        if (!empty($search)) {
            $escS = $koneksi->real_escape_string($search);
            $where[] = "(p.po_no LIKE '%{$escS}%' OR b.NAMA LIKE '%{$escS}%' OR p.note LIKE '%{$escS}%')";
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT p.id, p.po_no, p.po_dt, p.vendor, 
                       COALESCE(b.NAMA, p.vendor) AS vendor_nama,
                       p.term, p.kurs, p.rate, p.total_order, p.status, p.note,
                       (SELECT COUNT(1) FROM bc_po_dtl pd WHERE pd.id_hdr = p.id) AS item_count,
                       (SELECT SUM(pd.qty) FROM bc_po_dtl pd WHERE pd.id_hdr = p.id) AS total_qty_po,
                       (SELECT COUNT(DISTINCT r.ri_no) FROM bc_ri_hdr r WHERE r.po_no = p.po_no AND (r.cmp_id = '{$escCmp}' OR r.cmp_id = '' OR r.cmp_id IS NULL)) AS count_ri
                FROM bc_po_hdr p
                LEFT JOIN bc_bs b ON (p.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                WHERE {$whereSql}
                ORDER BY p.po_dt DESC, p.po_no DESC
                LIMIT 200";

        $res = $koneksi->query($sql);
        $items = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['po_dt_fmt'] = !empty($r['po_dt']) ? date('d/m/Y', strtotime($r['po_dt'])) : '-';
                $r['total_fmt'] = number_format((float)$r['total_order'], 2, '.', ',');
                $r['qty_fmt']   = number_format((float)$r['total_qty_po'], 2, '.', ',');
                $items[] = $r;
            }
        }

        echo json_encode(['status' => 'OK', 'data' => $items]);
    } catch (\Throwable $ex) {
        echo json_encode(['status' => 'ERROR', 'message' => $ex->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------------
// API 5: PRINT LAPORAN RESMI AP AGING (A4 LANDSCAPE / PORTRAIT)
// -------------------------------------------------------------------------
if ($action === 'print') {
    $vendorFilter = trim($_GET['vendor'] ?? '');
    $where = ["(h.status = '0' OR h.status = '1')",
              "(COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) > 0.01",
              "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '' OR h.cmp_id IS NULL)"];
    if (!empty($vendorFilter)) {
        $escV = $koneksi->real_escape_string($vendorFilter);
        $where[] = "h.vendor = '{$escV}'";
    }
    $whereSql = implode(' AND ', $where);

    $printSql = "SELECT h.pi_no, h.pi_dt, h.jatuhtempo, h.vendor, 
                        COALESCE(b.NAMA, h.vendor) AS vendor_nama,
                        h.term, h.kurs, h.total_order, h.terbayar,
                        (COALESCE(h.total_order, 0) - COALESCE(h.terbayar, 0)) AS sisa_hutang,
                        DATEDIFF(CURDATE(), h.jatuhtempo) AS aging_days
                 FROM bc_pi_hdr h
                 LEFT JOIN bc_bs b ON (h.vendor = b.KODE AND (b.cmp_id = '{$escCmp}' OR b.cmp_id = '' OR b.cmp_id IS NULL))
                 WHERE {$whereSql}
                 ORDER BY b.NAMA ASC, h.jatuhtempo ASC";
    $pRes = $koneksi->query($printSql);
    $items = [];
    $totalTagihan = 0;
    $totalTerbayar = 0;
    $totalSisa = 0;
    if ($pRes) {
        while ($r = $pRes->fetch_assoc()) {
            $totalTagihan += (float)$r['total_order'];
            $totalTerbayar += (float)$r['terbayar'];
            $totalSisa += (float)$r['sisa_hutang'];
            $items[] = $r;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <title>LAPORAN REKAP HUTANG USAHA & AP AGING - <?= htmlspecialchars($activeCmpName) ?></title>
        <style>
            @page { size: A4 landscape; margin: 12mm 15mm; }
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 9.5px; color: #111; margin: 0; padding: 10px; }
            .header-table { width: 100%; border-bottom: 2px solid #222; padding-bottom: 8px; margin-bottom: 12px; }
            .logo-img { max-height: 48px; max-width: 140px; object-fit: contain; }
            h2 { margin: 0 0 4px; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px; }
            .tbl-data { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .tbl-data th { background: #f3f4f6; border: 1px solid #bbb; padding: 5px 6px; font-size: 9px; text-align: left; }
            .tbl-data td { border: 1px solid #ddd; padding: 4px 6px; font-size: 9px; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .badge-aging { padding: 1px 4px; border-radius: 2px; font-size: 8px; font-weight: 600; display: inline-block; }
            .tag-current { background: #dcfce7; color: #15803d; }
            .tag-overdue { background: #fee2e2; color: #b91c1c; }
            .sign-table { width: 100%; margin-top: 30px; border-collapse: collapse; page-break-inside: avoid; }
            .sign-table td { width: 25%; text-align: center; vertical-align: top; font-size: 9px; }
            .sign-box { height: 50px; }
            @media print {
                .no-print { display: none; }
            }
        </style>
    </head>
    <body onload="window.print()">
        <div class="no-print" style="margin-bottom: 10px;">
            <button onclick="window.print()" style="padding: 6px 12px; font-size: 11px; cursor: pointer;">🖨️ Cetak Dokumen</button>
            <button onclick="window.close()" style="padding: 6px 12px; font-size: 11px; cursor: pointer;">Tutup</button>
        </div>

        <table class="header-table">
            <tr>
                <td style="width: 15%; vertical-align: middle;">
                    <img src="<?= htmlspecialchars($companyLogo) ?>" class="logo-img" alt="Logo">
                </td>
                <td style="width: 60%; vertical-align: middle;">
                    <h2><?= htmlspecialchars($activeCmpName) ?></h2>
                    <div><?= htmlspecialchars($companyAlamat ?: 'Kawasan Berikat Enterprise') ?></div>
                    <div>NPWP: <?= htmlspecialchars($companyNpwp ?: '-') ?></div>
                </td>
                <td style="width: 25%; text-align: right; vertical-align: middle;">
                    <strong style="font-size: 12px; color: #d97706;">LAPORAN HUTANG & AP AGING</strong><br>
                    <span style="font-size: 9px; color: #555;">Per Tanggal: <?= date('d/m/Y') ?></span><br>
                    <span style="font-size: 8.5px; color: #777;">Dicetak oleh: <?= htmlspecialchars($currentUser) ?></span>
                </td>
            </tr>
        </table>

        <table class="tbl-data">
            <thead>
                <tr>
                    <th style="width: 25px; text-align: center;">No</th>
                    <th style="width: 110px;">No. Faktur (PI)</th>
                    <th style="width: 65px;">Tgl Faktur</th>
                    <th style="width: 65px;">Jatuh Tempo</th>
                    <th>Pemasok / Vendor</th>
                    <th style="width: 45px; text-align: center;">Term</th>
                    <th style="width: 40px; text-align: center;">Kurs</th>
                    <th style="width: 85px;" class="text-right">Total Tagihan</th>
                    <th style="width: 85px;" class="text-right">Terbayar</th>
                    <th style="width: 90px;" class="text-right">Sisa Hutang</th>
                    <th style="width: 45px;" class="text-center">Umur</th>
                    <th style="width: 75px;" class="text-center">Status Aging</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="12" class="text-center" style="padding: 15px;">Tidak ada faktur pembelian outstanding (Hutang Lunas Seluruhnya).</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $idx => $it): 
                        $days = (int)$it['aging_days'];
                        $isOverdue = ($days > 0);
                        $badgeText = $isOverdue ? "Lewat {$days} Hari" : "Lancar";
                        $badgeCls  = $isOverdue ? "tag-overdue" : "tag-current";
                    ?>
                    <tr>
                        <td class="text-center"><?= $idx + 1 ?></td>
                        <td><b><?= htmlspecialchars($it['pi_no']) ?></b></td>
                        <td><?= !empty($it['pi_dt']) ? date('d/m/Y', strtotime($it['pi_dt'])) : '-' ?></td>
                        <td><?= !empty($it['jatuhtempo']) ? date('d/m/Y', strtotime($it['jatuhtempo'])) : '-' ?></td>
                        <td><?= htmlspecialchars($it['vendor_nama']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($it['term']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($it['kurs']) ?></td>
                        <td class="text-right"><?= number_format((float)$it['total_order'], 2) ?></td>
                        <td class="text-right"><?= number_format((float)$it['terbayar'], 2) ?></td>
                        <td class="text-right" style="font-weight: 700; color: #b91c1c;"><?= number_format((float)$it['sisa_hutang'], 2) ?></td>
                        <td class="text-center"><?= $days ?> Hr</td>
                        <td class="text-center"><span class="badge-aging <?= $badgeCls ?>"><?= $badgeText ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background: #fef3c7; font-weight: 700;">
                        <td colspan="7" class="text-right" style="padding: 6px;">TOTAL KESELURUHAN:</td>
                        <td class="text-right"><?= number_format($totalTagihan, 2) ?></td>
                        <td class="text-right"><?= number_format($totalTerbayar, 2) ?></td>
                        <td class="text-right" style="color: #b91c1c; font-size: 10px;"><?= number_format($totalSisa, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <table class="sign-table">
            <tr>
                <td><strong>Dibuat oleh (AP Staff)</strong><div class="sign-box"></div>( .................................. )</td>
                <td><strong>Diperiksa (Purchasing)</strong><div class="sign-box"></div>( .................................. )</td>
                <td><strong>Accounting Manager</strong><div class="sign-box"></div>( .................................. )</td>
                <td><strong>Direktur Keuangan</strong><div class="sign-box"></div>( .................................. )</td>
            </tr>
        </table>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------------------
// DATA REFERENSI FILTER UNTUK FORM UI
// -------------------------------------------------------------------------
$bsQ = $koneksi->query("SELECT KODE, NAMA FROM bc_bs 
                       WHERE (TIPE = 'S' OR TIPE = 'B' OR TIPE = 'VENDOR' OR TIPE IS NULL OR TIPE = '') 
                         AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) 
                       ORDER BY NAMA ASC");
$vendorList = [];
if ($bsQ) {
    while ($r = $bsQ->fetch_assoc()) $vendorList[] = $r;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap PO & Hutang Usaha (AP 360°) - <?= htmlspecialchars($activeCmpName) ?></title>
    
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

    <style>
        :root {
            --primary: #f59e0b;
            --primary-hover: #d97706;
            --primary-light: #fef3c7;
            --primary-focus: rgba(245, 158, 11, 0.25);
            --danger: #ef4444;
            --success: #10b981;
            --info: #0ea5e9;
            --bg-app: #f4f6f9;
            --bg-card: #ffffff;
            --border-color: #d1d5db;
            --border-light: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --table-head-bg: #f8fafc;
            --row-hover: #fffbeb;
            --row-selected: #fef3c7;
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
            --row-hover: #292524;
            --row-selected: #451a03;
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

        /* Container Card */
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
        .ant-badge-ap { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

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
        .ant-btn-success { background: #10b981; border-color: #10b981; color: #fff; }
        .ant-btn-success:hover { background: #059669; border-color: #059669; color: #fff; }
        .ant-btn-sm { height: 18px; padding: 0 5px; font-size: 8.5px; }

        /* KPI Chips Strip */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
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
            cursor: pointer;
        }
        .kpi-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            border-color: var(--primary);
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
        .kpi-icon-ap { background: #fef3c7; color: #d97706; }
        .kpi-icon-po { background: #dcfce7; color: #15803d; }
        .kpi-icon-due { background: #ffedd5; color: #ea580c; }
        .kpi-icon-overdue { background: #fee2e2; color: #b91c1c; }
        .kpi-icon-retur { background: #f1f5f9; color: #475569; }

        .kpi-content {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .kpi-label { font-size: 8.5px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kpi-val { font-size: 12px; font-weight: 700; color: var(--text-main); white-space: nowrap; }

        /* Filter Row */
        .filter-row {
            padding: 5px 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-card);
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .filter-label { font-size: 9px; font-weight: 600; color: var(--text-muted); }

        select, input[type="text"], input[type="date"] {
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

        /* Page Content */
        .ant-page {
            display: none;
            flex: 1;
            flex-direction: column;
            overflow: hidden;
            background: var(--bg-card);
        }
        .ant-page.active { display: flex; }

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

        table.dw tbody td {
            border-right: 1px solid var(--border-light);
            padding: 2.5px 6px;
            font-size: 9.5px;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* Aging Badges */
        .tag-aging {
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 8.5px;
            font-weight: 700;
            display: inline-block;
        }
        .tag-current { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .tag-overdue-1 { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        .tag-overdue-30 { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .tag-overdue-60 { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
        .tag-overdue-90 { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

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
    <div class="ant-card" style="height:100%;">
        <!-- HEADER PORTAL / TOOLBAR -->
        <div class="ant-card-header">
            <div class="ant-card-title">
                <i class="fa-solid fa-chart-pie" style="color:var(--primary); font-size:13px;"></i>
                <span>REKAP PURCHASE ORDER & HUTANG USAHA (ACCOUNTS PAYABLE 360°)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-ap">MODUL AP</span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadAllData()"><i class="fa-solid fa-rotate"></i> Refresh Data</button>
                <button type="button" class="ant-btn" onclick="exportCurrentTableExcel()"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Ekspor Excel</button>
                <button type="button" class="ant-btn" onclick="printReport()"><i class="fa-solid fa-print"></i> Cetak Rekap AP</button>
                <button type="button" class="ant-btn" onclick="openModule('pur_po')" title="Buka Modul Purchase Order"><i class="fa-solid fa-cart-shopping" style="color:#10b981;"></i> Modul PO</button>
                <button type="button" class="ant-btn" onclick="openModule('pur_pi')" title="Buka Modul Purchase Invoice"><i class="fa-solid fa-file-invoice-dollar" style="color:#8b5cf6;"></i> Modul PI</button>
                <button type="button" class="ant-btn" onclick="openModule('pur_pr')" title="Buka Modul Purchase Return"><i class="fa-solid fa-rotate-left" style="color:#ef4444;"></i> Modul Retur</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openGuideModal()" title="Panduan Modul"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:12px;"></i></button>
            </div>
        </div>

        <!-- 5-CHIP KPI SUMMARY STRIP -->
        <div class="kpi-strip">
            <div class="kpi-card" onclick="filterByKpi('all')">
                <div class="kpi-icon kpi-icon-ap"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Total Hutang Berjalan</span>
                    <span class="kpi-val" id="kpiTotalHutang">Rp 0.00</span>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByKpi('po')">
                <div class="kpi-icon kpi-icon-po"><i class="fa-solid fa-cart-shopping"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Total PO Aktif / Open</span>
                    <span class="kpi-val" id="kpiTotalOpenPo">Rp 0.00</span>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByKpi('due')">
                <div class="kpi-icon kpi-icon-due"><i class="fa-solid fa-clock"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Jatuh Tempo &lt; 7 Hari</span>
                    <span class="kpi-val" id="kpiDueSoonAp" style="color:#ea580c;">Rp 0.00</span>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByKpi('overdue')">
                <div class="kpi-icon kpi-icon-overdue"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Hutang Lewat Tempo</span>
                    <span class="kpi-val" id="kpiOverdueAp" style="color:#b91c1c;">Rp 0.00</span>
                </div>
            </div>
            <div class="kpi-card" onclick="filterByKpi('retur')">
                <div class="kpi-icon kpi-icon-retur"><i class="fa-solid fa-rotate-left"></i></div>
                <div class="kpi-content">
                    <span class="kpi-label">Retur / Debit Note Open</span>
                    <span class="kpi-val" id="kpiPendingRetur" style="color:#475569;">Rp 0.00</span>
                </div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-row">
            <div class="filter-group">
                <span class="filter-label">Pemasok / Vendor:</span>
                <select id="selVendor" style="width:190px;" onchange="onVendorFilterChange()">
                    <option value="">-- Semua Pemasok / Vendor --</option>
                    <?php foreach ($vendorList as $v): ?>
                        <option value="<?= htmlspecialchars($v['KODE']) ?>"><?= htmlspecialchars($v['KODE']) ?> - <?= htmlspecialchars($v['NAMA']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Filter Status:</span>
                <select id="selStatusFilter" style="width:140px;" onchange="loadCurrentTab()">
                    <option value="all">Semua Status</option>
                    <option value="with_debt">Hanya Yang Berhutang</option>
                    <option value="overdue">Hanya Overdue / Macet</option>
                    <option value="with_po">Hanya Ada PO Aktif</option>
                </select>
            </div>
            <div class="filter-group">
                <span class="filter-label">Aging Bucket:</span>
                <select id="selAgingBucket" style="width:130px;" onchange="loadCurrentTab()">
                    <option value="all">Semua Umur</option>
                    <option value="current">Current (Belum JT)</option>
                    <option value="1-30">1 - 30 Hari Lewat</option>
                    <option value="31-60">31 - 60 Hari Lewat</option>
                    <option value="61-90">61 - 90 Hari Lewat</option>
                    <option value=">90">&gt; 90 Hari (Macet)</option>
                </select>
            </div>
            <div class="filter-group" style="margin-left:auto;">
                <input type="text" id="txtSearchGlobal" placeholder="Cari nomor, nama, dokumen..." style="width:180px;" onkeydown="if(event.key==='Enter') loadCurrentTab()">
                <button type="button" class="ant-btn" onclick="loadCurrentTab()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
        </div>

        <!-- TABS NAVIGASI 3-TAB -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="tabMatrix" onclick="switchTab('tabMatrix')"><i class="fa-solid fa-table-cells"></i> 1. Matriks Hutang Per Pemasok</button>
            <button type="button" class="ant-tab-btn" data-tab="tabPi" onclick="switchTab('tabPi')"><i class="fa-solid fa-file-invoice"></i> 2. Rincian Faktur Belum Lunas &amp; Aging</button>
            <button type="button" class="ant-tab-btn" data-tab="tabPo" onclick="switchTab('tabPo')"><i class="fa-solid fa-cart-shopping"></i> 3. Monitoring Purchase Order (PO) Aktif</button>
        </div>

        <!-- TAB 1: MATRIKS REKAP HUTANG PER VENDOR -->
        <section class="ant-page active" id="tabMatrix">
            <div class="grid-wrap">
                <table class="dw" id="tblMatrix">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">No</th>
                            <th style="width:110px;">Kode Vendor</th>
                            <th>Nama Pemasok / Vendor</th>
                            <th style="width:65px; text-align:center;">TOP</th>
                            <th style="width:45px; text-align:center;">Kurs</th>
                            <th style="width:60px; text-align:center;">Jml PI</th>
                            <th style="width:110px;" class="text-right">Total Tagihan</th>
                            <th style="width:110px;" class="text-right">Total Terbayar</th>
                            <th style="width:105px;" class="text-right">Retur Pending</th>
                            <th style="width:115px;" class="text-right">Sisa Hutang (Net)</th>
                            <th style="width:105px;" class="text-right">Overdue</th>
                            <th style="width:70px; text-align:center;">PO Open</th>
                            <th style="width:90px; text-align:center;">Aksi Cepat</th>
                        </tr>
                    </thead>
                    <tbody id="matrixBody">
                        <tr><td colspan="13" class="text-center" style="padding:20px; color:var(--text-muted);">Memuat matriks hutang...</td></tr>
                    </tbody>
                    <tfoot style="position:sticky; bottom:0; background:var(--table-head-bg); font-weight:700; border-top:2px solid var(--border-color);">
                        <tr>
                            <td colspan="6" class="text-right" style="padding:4px 6px;">TOTAL KESELURUHAN:</td>
                            <td class="text-right" id="matTotTagihan">0.00</td>
                            <td class="text-right" id="matTotTerbayar">0.00</td>
                            <td class="text-right" id="matTotRetur" style="color:var(--text-muted);">0.00</td>
                            <td class="text-right" id="matTotNet" style="color:#b91c1c; font-size:10px;">0.00</td>
                            <td class="text-right" id="matTotOverdue" style="color:#b91c1c;">0.00</td>
                            <td class="text-center" id="matTotPo">0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="status-bar">
                <span id="txtMatrixStatus">Menampilkan 0 rekanan pemasok</span>
                <span>Klik 2x pada baris vendor untuk meninjau rincian faktur</span>
            </div>
        </section>

        <!-- TAB 2: RINCIAN FAKTUR BELUM LUNAS & AGING -->
        <section class="ant-page" id="tabPi">
            <div class="grid-wrap">
                <table class="dw" id="tblPi">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">No</th>
                            <th style="width:120px;">No. Faktur (PI)</th>
                            <th style="width:70px;">Tgl Faktur</th>
                            <th style="width:70px;">Jatuh Tempo</th>
                            <th>Pemasok / Vendor</th>
                            <th style="width:50px; text-align:center;">Term</th>
                            <th style="width:45px; text-align:center;">Kurs</th>
                            <th style="width:95px;" class="text-right">Total Tagihan</th>
                            <th style="width:95px;" class="text-right">Terbayar</th>
                            <th style="width:105px;" class="text-right">Sisa Hutang</th>
                            <th style="width:55px;" class="text-center">Umur</th>
                            <th style="width:95px;" class="text-center">Status Aging</th>
                            <th style="width:85px;">Dokumen BC</th>
                            <th style="width:80px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="piBody">
                        <tr><td colspan="14" class="text-center" style="padding:20px; color:var(--text-muted);">Memuat daftar faktur outstanding...</td></tr>
                    </tbody>
                    <tfoot style="position:sticky; bottom:0; background:var(--table-head-bg); font-weight:700; border-top:2px solid var(--border-color);">
                        <tr>
                            <td colspan="7" class="text-right" style="padding:4px 6px;">TOTAL FAKTUR OUTSTANDING:</td>
                            <td class="text-right" id="piTotTagihan">0.00</td>
                            <td class="text-right" id="piTotTerbayar">0.00</td>
                            <td class="text-right" id="piTotSisa" style="color:#b91c1c; font-size:10px;">0.00</td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="status-bar">
                <span id="txtPiStatus">Menampilkan 0 faktur outstanding</span>
                <span>Klik 2x pada faktur untuk membuka transaksi PI di tab baru</span>
            </div>
        </section>

        <!-- TAB 3: MONITORING PURCHASE ORDER (PO) AKTIF -->
        <section class="ant-page" id="tabPo">
            <div class="grid-wrap">
                <table class="dw" id="tblPo">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">No</th>
                            <th style="width:125px;">No. Purchase Order</th>
                            <th style="width:70px;">Tgl PO</th>
                            <th>Pemasok / Vendor</th>
                            <th style="width:50px; text-align:center;">Term</th>
                            <th style="width:45px; text-align:center;">Kurs</th>
                            <th style="width:60px;" class="text-right">Item</th>
                            <th style="width:80px;" class="text-right">Total Qty</th>
                            <th style="width:110px;" class="text-right">Nilai Total PO</th>
                            <th style="width:70px; text-align:center;">Realisasi RI</th>
                            <th>Catatan Pesanan</th>
                            <th style="width:80px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="poBody">
                        <tr><td colspan="12" class="text-center" style="padding:20px; color:var(--text-muted);">Memuat purchase order aktif...</td></tr>
                    </tbody>
                    <tfoot style="position:sticky; bottom:0; background:var(--table-head-bg); font-weight:700; border-top:2px solid var(--border-color);">
                        <tr>
                            <td colspan="8" class="text-right" style="padding:4px 6px;">TOTAL PO AKTIF:</td>
                            <td class="text-right" id="poTotNilai" style="color:#15803d; font-size:10px;">0.00</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="status-bar">
                <span id="txtPoStatus">Menampilkan 0 pesanan PO aktif</span>
                <span>Klik 2x pada nomor PO untuk membuka transaksi PO</span>
            </div>
        </section>
    </div>
</div>

<!-- CONTEXT MENU KLIK KANAN -->
<div class="context-menu" id="rightContextMenu">
    <div class="context-menu-item" onclick="copySelectedRow()"><i class="fa-solid fa-copy"></i> Salin Baris Terpilih</div>
    <div class="context-menu-item" onclick="exportCurrentTableExcel()"><i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Ekspor Seluruh Tabel (.xlsx)</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="loadAllData()"><i class="fa-solid fa-rotate"></i> Muat Ulang Data</div>
</div>

<!-- MODAL PANDUAN SOP -->
<div id="modalGuide" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:var(--bg-card); width:520px; border-radius:4px; border:1px solid var(--border-color); overflow:hidden; box-shadow:0 8px 24px rgba(0,0,0,0.2);">
        <div style="background:var(--primary); color:#fff; padding:8px 12px; font-weight:700; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fa-solid fa-circle-question"></i> Panduan Modul Rekap PO & Hutang Usaha</span>
            <button onclick="closeGuideModal()" style="background:none; border:none; color:#fff; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:12px; font-size:10px; line-height:1.6; max-height:400px; overflow-y:auto;">
            <h4 style="color:var(--primary); margin-bottom:4px;">1. Tujuan & Fungsi Modul</h4>
            <p>Modul ini memberikan visibilitas menyeluruh (360° AP Exposure) terhadap komitmen belanja (Open PO), tagihan berjalan yang belum lunas (Faktur PI), umur hutang (Aging), dan retur pembelian pending demi akurasi perencanaan arus kas (Cashflow).</p>
            <h4 style="color:var(--primary); margin:10px 0 4px;">2. Rumus Net AP</h4>
            <p><b>Net Sisa Hutang</b> = Sisa Tagihan Faktur PI - Nilai Retur Pembelian (Debit Note) yang belum dikompensasikan.</p>
            <h4 style="color:var(--primary); margin:10px 0 4px;">3. Klasifikasi Aging Hutang</h4>
            <ul style="padding-left:18px;">
                <li><b>Current:</b> Faktur belum melewati tanggal jatuh tempo (lancar).</li>
                <li><b>1 - 30 Hari:</b> Lewat tempo tahap awal (peringatan).</li>
                <li><b>31 - 60 / 61 - 90 Hari:</b> Lewat tempo jatuh tempo sedang.</li>
                <li><b>&gt; 90 Hari:</b> Hutang macet / perhatian khusus pimpinan.</li>
            </ul>
        </div>
        <div style="padding:6px 12px; background:var(--bg-app); border-top:1px solid var(--border-color); text-align:right;">
            <button class="ant-btn ant-btn-primary" onclick="closeGuideModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let activeTab = 'tabMatrix';
let lastSelectedRow = null;

document.addEventListener('DOMContentLoaded', () => {
    loadAllData();

    // Close Context Menu on click outside
    document.addEventListener('click', () => {
        document.getElementById('rightContextMenu').style.display = 'none';
    });

    // Right Click Context Menu on Tables
    document.querySelectorAll('table.dw').forEach(tbl => {
        tbl.addEventListener('contextmenu', (e) => {
            const tr = e.target.closest('tr');
            if (tr && tr.parentElement.tagName === 'TBODY') {
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
});

// -------------------------------------------------------------------------
// TABS SWITCHER
// -------------------------------------------------------------------------
function switchTab(tabId) {
    activeTab = tabId;
    document.querySelectorAll('.ant-tab-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.tab === tabId));
    document.querySelectorAll('.ant-page').forEach(page => page.classList.toggle('active', page.id === tabId));
    loadCurrentTab();
}

function loadCurrentTab() {
    if (activeTab === 'tabMatrix') loadVendorMatrix();
    else if (activeTab === 'tabPi') loadOutstandingPi();
    else if (activeTab === 'tabPo') loadOpenPo();
}

function onVendorFilterChange() {
    loadAllData();
}

function loadAllData() {
    loadKpi();
    loadCurrentTab();
}

// -------------------------------------------------------------------------
// 1. LOAD KPI SUMMARY STRIP
// -------------------------------------------------------------------------
async function loadKpi() {
    try {
        const vendor = document.getElementById('selVendor').value;
        const res = await fetch(`rekap_po_hutang.php?action=kpi&vendor=${encodeURIComponent(vendor)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') return;

        const d = json.data;
        document.getElementById('kpiTotalHutang').innerText = `Rp ${numberFormat(d.total_hutang)}`;
        document.getElementById('kpiTotalOpenPo').innerText = `Rp ${numberFormat(d.total_open_po)}`;
        document.getElementById('kpiDueSoonAp').innerText = `Rp ${numberFormat(d.due_soon_ap)}`;
        document.getElementById('kpiOverdueAp').innerText = `Rp ${numberFormat(d.overdue_ap)}`;
        document.getElementById('kpiPendingRetur').innerText = `Rp ${numberFormat(d.total_retur_open)}`;
    } catch (e) {
        console.error('Gagal memuat KPI:', e);
    }
}

function filterByKpi(type) {
    if (type === 'all') {
        document.getElementById('selStatusFilter').value = 'all';
        switchTab('tabMatrix');
    } else if (type === 'po') {
        switchTab('tabPo');
    } else if (type === 'due') {
        document.getElementById('selAgingBucket').value = 'current';
        switchTab('tabPi');
    } else if (type === 'overdue') {
        document.getElementById('selStatusFilter').value = 'overdue';
        switchTab('tabPi');
    } else if (type === 'retur') {
        openModule('pur_pr');
    }
}

// -------------------------------------------------------------------------
// 2. LOAD VENDOR MATRIX (TAB 1)
// -------------------------------------------------------------------------
async function loadVendorMatrix() {
    const tbody = document.getElementById('matrixBody');
    tbody.innerHTML = '<tr><td colspan="13" class="text-center" style="padding:20px;">Memuat matriks hutang...</td></tr>';

    try {
        const search = document.getElementById('txtSearchGlobal').value;
        const statusFilter = document.getElementById('selStatusFilter').value;
        const vendor = document.getElementById('selVendor').value;

        const res = await fetch(`rekap_po_hutang.php?action=vendor_matrix&search=${encodeURIComponent(search || vendor)}&status_filter=${encodeURIComponent(statusFilter)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        const rows = json.data || [];
        document.getElementById('txtMatrixStatus').innerText = `Menampilkan ${rows.length} rekanan pemasok`;

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="13" class="text-center" style="padding:20px; color:var(--text-muted);">Tidak ada data rekanan yang cocok dengan kriteria filter.</td></tr>';
            document.getElementById('matTotTagihan').innerText = '0.00';
            document.getElementById('matTotTerbayar').innerText = '0.00';
            document.getElementById('matTotRetur').innerText = '0.00';
            document.getElementById('matTotNet').innerText = '0.00';
            document.getElementById('matTotOverdue').innerText = '0.00';
            document.getElementById('matTotPo').innerText = '0';
            return;
        }

        let totTag = 0, totByr = 0, totRet = 0, totNet = 0, totOvd = 0, totPo = 0;
        let html = '';

        rows.forEach((r, idx) => {
            totTag += parseFloat(r.total_tagihan) || 0;
            totByr += parseFloat(r.total_terbayar) || 0;
            totRet += parseFloat(r.total_retur) || 0;
            totNet += parseFloat(r.net_hutang) || 0;
            totOvd += parseFloat(r.overdue_hutang) || 0;
            totPo  += parseInt(r.count_po) || 0;

            const netStyle = (parseFloat(r.net_hutang) > 0) ? 'font-weight:700; color:#b91c1c;' : 'color:var(--text-muted);';
            const ovdStyle = (parseFloat(r.overdue_hutang) > 0) ? 'font-weight:700; color:#b91c1c;' : 'color:var(--text-muted);';

            html += `<tr ondblclick="filterVendorAndOpenPi('${r.vendor_kode}')" onclick="selectRow(this)">
                <td class="text-center">${idx + 1}</td>
                <td><b style="color:var(--primary);">${escapeHtml(r.vendor_kode)}</b></td>
                <td>${escapeHtml(r.vendor_nama)}</td>
                <td class="text-center">${escapeHtml(r.top || 'COD')}</td>
                <td class="text-center">${escapeHtml(r.currency || 'IDR')}</td>
                <td class="text-center"><span class="ant-badge" style="background:#f1f5f9; color:#334155;">${r.count_pi}</span></td>
                <td class="text-right">${r.tagihan_fmt}</td>
                <td class="text-right">${r.terbayar_fmt}</td>
                <td class="text-right" style="color:var(--text-muted);">${r.retur_fmt}</td>
                <td class="text-right" style="${netStyle}">${r.net_fmt}</td>
                <td class="text-right" style="${ovdStyle}">${r.overdue_fmt}</td>
                <td class="text-center"><span class="ant-badge" style="background:#dcfce7; color:#15803d;">${r.count_po} PO</span></td>
                <td class="text-center">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="filterVendorAndOpenPi('${r.vendor_kode}')" title="Lihat Faktur"><i class="fa-solid fa-list-check"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="createReturnForVendor('${r.vendor_kode}')" title="Buat Retur"><i class="fa-solid fa-rotate-left" style="color:#ef4444;"></i></button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;
        document.getElementById('matTotTagihan').innerText = numberFormat(totTag);
        document.getElementById('matTotTerbayar').innerText = numberFormat(totByr);
        document.getElementById('matTotRetur').innerText = numberFormat(totRet);
        document.getElementById('matTotNet').innerText = numberFormat(totNet);
        document.getElementById('matTotOverdue').innerText = numberFormat(totOvd);
        document.getElementById('matTotPo').innerText = `${totPo} PO`;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="13" class="text-center" style="padding:20px; color:var(--danger);">${e.message}</td></tr>`;
    }
}

// -------------------------------------------------------------------------
// 3. LOAD OUTSTANDING PI & AGING (TAB 2)
// -------------------------------------------------------------------------
async function loadOutstandingPi() {
    const tbody = document.getElementById('piBody');
    tbody.innerHTML = '<tr><td colspan="14" class="text-center" style="padding:20px;">Memuat faktur outstanding...</td></tr>';

    try {
        const vendor = document.getElementById('selVendor').value;
        const agingBucket = document.getElementById('selAgingBucket').value;
        const search = document.getElementById('txtSearchGlobal').value;

        const res = await fetch(`rekap_po_hutang.php?action=outstanding_pi&vendor=${encodeURIComponent(vendor)}&aging_bucket=${encodeURIComponent(agingBucket)}&search=${encodeURIComponent(search)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        const items = json.data || [];
        document.getElementById('txtPiStatus').innerText = `Menampilkan ${items.length} faktur outstanding`;

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="14" class="text-center" style="padding:20px; color:var(--text-muted);">Tidak ada faktur pembelian belum lunas sesuai kriteria.</td></tr>';
            document.getElementById('piTotTagihan').innerText = '0.00';
            document.getElementById('piTotTerbayar').innerText = '0.00';
            document.getElementById('piTotSisa').innerText = '0.00';
            return;
        }

        let totTag = 0, totByr = 0, totSisa = 0;
        let html = '';

        items.forEach((it, idx) => {
            totTag  += parseFloat(it.total_order) || 0;
            totByr  += parseFloat(it.terbayar) || 0;
            totSisa += parseFloat(it.sisa_hutang) || 0;

            const bcBadge = it.bc ? `<span class="ant-badge" style="background:#e0f2fe; color:#0369a1;">BC ${escapeHtml(it.bc)}</span>` : '<span style="color:var(--text-muted);">-</span>';

            html += `<tr ondblclick="openPiDetail('${it.id}')" onclick="selectRow(this)">
                <td class="text-center">${idx + 1}</td>
                <td><b style="color:var(--primary);">${escapeHtml(it.pi_no)}</b></td>
                <td>${it.pi_dt_fmt}</td>
                <td>${it.jt_fmt}</td>
                <td>${escapeHtml(it.vendor_nama)}</td>
                <td class="text-center">${escapeHtml(it.term || 'COD')}</td>
                <td class="text-center">${escapeHtml(it.kurs || 'IDR')}</td>
                <td class="text-right">${it.total_fmt}</td>
                <td class="text-right">${it.terbayar_fmt}</td>
                <td class="text-right" style="font-weight:700; color:#b91c1c;">${it.sisa_fmt}</td>
                <td class="text-center"><b>${it.aging_days}</b> Hr</td>
                <td class="text-center"><span class="tag-aging ${it.badge_class}">${escapeHtml(it.aging_bucket)}</span></td>
                <td>${bcBadge}</td>
                <td class="text-center">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="openPiDetail('${it.id}')" title="Buka PI"><i class="fa-solid fa-up-right-from-square"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="createReturnFromPi('${escapeHtml(it.pi_no)}', '${escapeHtml(it.vendor)}')" title="Retur Faktur"><i class="fa-solid fa-rotate-left" style="color:#ef4444;"></i></button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;
        document.getElementById('piTotTagihan').innerText = numberFormat(totTag);
        document.getElementById('piTotTerbayar').innerText = numberFormat(totByr);
        document.getElementById('piTotSisa').innerText = numberFormat(totSisa);
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="14" class="text-center" style="padding:20px; color:var(--danger);">${e.message}</td></tr>`;
    }
}

// -------------------------------------------------------------------------
// 4. LOAD OPEN PURCHASE ORDERS (TAB 3)
// -------------------------------------------------------------------------
async function loadOpenPo() {
    const tbody = document.getElementById('poBody');
    tbody.innerHTML = '<tr><td colspan="12" class="text-center" style="padding:20px;">Memuat purchase order aktif...</td></tr>';

    try {
        const vendor = document.getElementById('selVendor').value;
        const search = document.getElementById('txtSearchGlobal').value;

        const res = await fetch(`rekap_po_hutang.php?action=open_po&vendor=${encodeURIComponent(vendor)}&search=${encodeURIComponent(search)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
        const json = await res.json();
        if (json.status !== 'OK') throw new Error(json.message);

        const items = json.data || [];
        document.getElementById('txtPoStatus').innerText = `Menampilkan ${items.length} pesanan PO aktif`;

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center" style="padding:20px; color:var(--text-muted);">Tidak ada Purchase Order berstatus aktif.</td></tr>';
            document.getElementById('poTotNilai').innerText = '0.00';
            return;
        }

        let totNilai = 0;
        let html = '';

        items.forEach((it, idx) => {
            totNilai += parseFloat(it.total_order) || 0;
            const riBadge = (parseInt(it.count_ri) > 0) 
                ? `<span class="ant-badge" style="background:#e0f2fe; color:#0369a1;">${it.count_ri} RI</span>` 
                : `<span class="ant-badge" style="background:#fee2e2; color:#b91c1c;">Belum Ada</span>`;

            html += `<tr ondblclick="openPoDetail('${it.id}')" onclick="selectRow(this)">
                <td class="text-center">${idx + 1}</td>
                <td><b style="color:#10b981;">${escapeHtml(it.po_no)}</b></td>
                <td>${it.po_dt_fmt}</td>
                <td>${escapeHtml(it.vendor_nama)}</td>
                <td class="text-center">${escapeHtml(it.term || 'COD')}</td>
                <td class="text-center">${escapeHtml(it.kurs || 'IDR')}</td>
                <td class="text-right">${it.item_count} Item</td>
                <td class="text-right">${it.qty_fmt}</td>
                <td class="text-right" style="font-weight:700; color:#15803d;">${it.total_fmt}</td>
                <td class="text-center">${riBadge}</td>
                <td>${escapeHtml(it.note || '-')}</td>
                <td class="text-center">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="openPoDetail('${it.id}')" title="Buka PO"><i class="fa-solid fa-up-right-from-square"></i></button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;
        document.getElementById('poTotNilai').innerText = numberFormat(totNilai);
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:20px; color:var(--danger);">${e.message}</td></tr>`;
    }
}

// -------------------------------------------------------------------------
// HELPER ACTIONS & CROSS-MODULE NAVIGATION
// -------------------------------------------------------------------------
function selectRow(tr) {
    tr.parentElement.querySelectorAll('tr').forEach(r => r.classList.remove('selected'));
    tr.classList.add('selected');
    lastSelectedRow = tr;
}

function filterVendorAndOpenPi(vendorKode) {
    document.getElementById('selVendor').value = vendorKode;
    switchTab('tabPi');
}

function createReturnForVendor(vendorKode) {
    window.open(`/erp/purchase_return.php?vendor=${encodeURIComponent(vendorKode)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function createReturnFromPi(piNo, vendor) {
    window.open(`/erp/purchase_return.php?pi_no=${encodeURIComponent(piNo)}&vendor=${encodeURIComponent(vendor)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function openPiDetail(id) {
    window.open(`/erp/purchase_invoice.php?id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function openPoDetail(id) {
    window.open(`/erp/purchase_order.php?id=${encodeURIComponent(id)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function openModule(mod) {
    if (window.parent && window.parent.switchMainTab) {
        window.parent.switchMainTab(mod);
    } else {
        const pathMap = {
            'pur_po': '/erp/purchase_order.php',
            'pur_pi': '/erp/purchase_invoice.php',
            'pur_pr': '/erp/purchase_return.php'
        };
        window.open(`${pathMap[mod]}?cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
    }
}

function printReport() {
    const vendor = document.getElementById('selVendor').value;
    window.open(`rekap_po_hutang.php?action=print&vendor=${encodeURIComponent(vendor)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, '_blank');
}

function exportCurrentTableExcel() {
    const tblId = activeTab === 'tabMatrix' ? 'tblMatrix' : (activeTab === 'tabPi' ? 'tblPi' : 'tblPo');
    const tbl = document.getElementById(tblId);
    if (!tbl) return;

    try {
        const wb = XLSX.utils.table_to_book(tbl, { sheet: "Rekap_AP" });
        const fname = `Rekap_AP_${activeTab}_${Date.now()}.xlsx`;
        XLSX.writeFile(wb, fname);
    } catch (e) {
        Swal.fire('Error Export', e.message, 'error');
    }
}

function copySelectedRow() {
    if (!lastSelectedRow) return;
    const cells = Array.from(lastSelectedRow.cells).map(c => c.innerText.trim());
    const tsv = cells.join('\t');
    navigator.clipboard.writeText(tsv).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'Tersalin',
            text: 'Baris data berhasil disalin ke clipboard.',
            timer: 1200,
            showConfirmButton: false
        });
    });
}

function openGuideModal() {
    document.getElementById('modalGuide').style.display = 'flex';
}

function closeGuideModal() {
    document.getElementById('modalGuide').style.display = 'none';
}

function numberFormat(val) {
    const n = parseFloat(val) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[m]);
}
</script>

</body>
</html>

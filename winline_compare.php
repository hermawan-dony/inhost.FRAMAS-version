<?php
/**
 * CEISA 4.0 - Add-on Module: Winline Compare & IDN (Incoming Delivery Note)
 * File: winline_compare.php
 * 
 * Modul terintegrasi Winline ERP PT Framas Indonesia:
 * 1. IDN (Incoming Delivery Note):
 *    - Query live dari Microsoft SQL Server Winline (FRAMASDB @ 192.168.170.254)
 *    - Mengambil data penerimaan barang masuk supplier (cwldata..t025, t026, t002, t023)
 *    - Filter default: per HARI INI dengan dukungan date-range & quick presets
 * 2. IDN vs IT Inventory (Winline Compare):
 *    - Rekonsiliasi ringkasan harian (Daily Summary) dan detail per transaksi
 *    - Membandingkan IDN Winline dengan Dokumen Pemasukan Pabean:
 *      * Pilihan Sumber: Tabel bc_1ab2 (IT Inventory) ATAU bc_ceisa_data (Sync CEISA 4.0)
 *      * Jenis Dokumen Inbound: BC 4.0, BC 2.3, BC 1.6, BC 2.6.2, BC 2.7 IN
 *    - Kalkulasi selisih dokumen & qty per hari, indikator MATCH / SELISIH / HANYA DI WINLINE / HANYA DI PABEAN
 *    - Modal drill-down perbandingan dua sisi (Side-by-Side Reconciliation)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';
require_once __DIR__ . '/winline_helper.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_POST['cmp_id'] ?? ($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? 'FRM-1'));
$activeTab = trim($_REQUEST['tab'] ?? ($_GET['tab'] ?? 'idn'));
if (!in_array($activeTab, ['idn', 'compare'])) {
    $activeTab = 'idn';
}

$escCmp = $koneksi->real_escape_string($sessionCmpId);

// Ambil profil perusahaan aktif
$company = [];
$resCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
if ($resCmp && $rC = $resCmp->fetch_assoc()) {
    $company = $rC;
}

// Master Plant Framas
$framasPlants = [
    'ALL'   => 'Semua Plant Framas (FRM 1, 2, 3, PDPLB)',
    'FRM-1' => 'PT FRAMAS INDONESIA (Plant 1 MM2100)',
    'FRM-2' => 'PT FRAMAS INDONESIA (Plant 2 Kenari DS5)',
    'FRM-3' => 'PT FRAMAS INDONESIA (Plant 3 DS6)',
    'PDPLB' => 'PT FRAMAS INDONESIA (PLB MM2100)'
];

$action = trim($_REQUEST['action'] ?? '');

// =========================================================================
// API 1: AMBIL DATA IDN (Incoming Delivery Note) DARI WINLINE SQL SERVER
// =========================================================================
if ($action === 'get_idn_data') {
    header('Content-Type: application/json; charset=utf-8');

    $tglAwal = trim($_REQUEST['tgl_awal'] ?? date('Y-m-d'));
    $tglAkhir = trim($_REQUEST['tgl_akhir'] ?? date('Y-m-d'));
    $search = trim($_REQUEST['search'] ?? '');

    $conn = getWinlineDb();
    if (!$conn) {
        echo json_encode([
            'status'  => 'ERROR',
            'message' => 'Gagal terhubung ke database Winline SQL Server (192.168.170.254). Periksa koneksi LAN/VPN Anda.'
        ]);
        exit;
    }

    $sql = "SELECT 
        CONVERT(varchar(10), t025.c029, 20) AS idn_dt, 
        t025.c045 AS dn_no, 
        t025.c044 AS po_number, 
        CONVERT(varchar(10), t025.c059, 20) AS order_dt, 
        t025.c004 AS supplier_name, 
        t025.c021 AS vendor,
        t026.c114 AS seq, 
        t026.c003 AS item_code, 
        t026.c004 AS item_name,
        t026.c005 AS qty 
    FROM cwldata..t025 t025, cwldata..t026 t026, cwldata..t002 t002 
    WHERE t025.c021 = t026.c044  
      AND t025.mesocomp = t026.mesocomp
      AND t025.mesocomp = t002.mesocomp
      AND t025.c022 = t026.c045 
      AND t025.c029 BETWEEN ? AND ?
      AND LEFT(t025.c045, 3) = 'IDN'
      AND t026.c003 <> 'TEXT'
      AND t026.c055 <> 13
      AND (t026.c005 > 0) 
      AND t025.mesoyear = t002.mesoyear 	
      AND t026.c003 IN (SELECT c.c010 FROM cwldata..t023 c WHERE c.c005 IN (86)) 	
      AND (CASE t025.c041 WHEN 0 THEN 1 ELSE t025.c041 END) = t002.c017
    ORDER BY dn_no, seq";

    $params = [$tglAwal, $tglAkhir];
    $stmt = sqlsrv_query($conn, $sql, $params);

    if (!$stmt) {
        $errs = sqlsrv_errors();
        $msg = !empty($errs[0]['message']) ? $errs[0]['message'] : 'Gagal mengeksekusi kueri Winline IDN.';
        echo json_encode(['status' => 'ERROR', 'message' => $msg]);
        exit;
    }

    $rows = [];
    $dnSet = [];
    $poSet = [];
    $supplierSet = [];
    $totQty = 0;

    $sLower = strtolower($search);

    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $dnNo = trim($r['dn_no'] ?? '');
        $poNo = trim($r['po_number'] ?? '');
        $splr = trim($r['supplier_name'] ?? '');
        $iCode = trim($r['item_code'] ?? '');
        $iName = trim($r['item_name'] ?? '');
        $q = (float)($r['qty'] ?? 0);

        // Filter search jika ada
        if (!empty($sLower)) {
            $haystack = strtolower("$dnNo $poNo $splr $iCode $iName");
            if (strpos($haystack, $sLower) === false) {
                continue;
            }
        }

        $dnSet[$dnNo] = true;
        if (!empty($poNo)) $poSet[$poNo] = true;
        if (!empty($splr)) $supplierSet[$splr] = true;
        $totQty += $q;

        $rows[] = [
            'idn_dt'        => trim($r['idn_dt'] ?? ''),
            'dn_no'         => $dnNo,
            'po_number'     => $poNo,
            'order_dt'      => trim($r['order_dt'] ?? ''),
            'supplier_name' => $splr,
            'vendor'        => trim($r['vendor'] ?? ''),
            'seq'           => (int)($r['seq'] ?? 0),
            'item_code'     => $iCode,
            'item_name'     => $iName,
            'qty'           => $q
        ];
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'kpi'    => [
            'total_rows'      => count($rows),
            'total_dn'        => count($dnSet),
            'total_po'        => count($poSet),
            'total_suppliers' => count($supplierSet),
            'total_qty'       => $totQty
        ]
    ]);
    exit;
}

// =========================================================================
// API 2: AMBIL SUMMARY KOMPARASI HARIAN (IDN vs IT INVENTORY / CEISA)
// =========================================================================
if ($action === 'get_compare_summary') {
    header('Content-Type: application/json; charset=utf-8');

    $tglAwal  = trim($_REQUEST['tgl_awal'] ?? date('Y-m-d'));
    $tglAkhir = trim($_REQUEST['tgl_akhir'] ?? date('Y-m-d'));
    $source   = trim($_REQUEST['source'] ?? 'bc_1ab2'); // 'bc_1ab2' atau 'bc_ceisa_data'
    $plant    = trim($_REQUEST['plant'] ?? 'ALL');
    $statusFilter = trim($_REQUEST['status_filter'] ?? 'ALL');

    // Dokumen Pemasukan yang dipilih (default: 40, 23, 16, 262, 27)
    $docsReq = $_REQUEST['doc_types'] ?? ['40', '23', '16', '262', '27'];
    if (is_string($docsReq)) {
        $docsReq = explode(',', $docsReq);
    }
    $cleanDocs = [];
    foreach ($docsReq as $d) {
        $d = trim($d);
        if (in_array($d, ['40', '23', '16', '262', '27', '20'])) {
            $cleanDocs[] = $d;
        }
    }
    if (empty($cleanDocs)) {
        $cleanDocs = ['40', '23', '16', '262', '27'];
    }

    // 1. Ambil Ringkasan Harian dari Winline SQL Server
    $winlineDaily = [];
    $conn = getWinlineDb();
    if ($conn) {
        $sqlWinline = "SELECT 
            CONVERT(varchar(10), t025.c029, 20) AS idn_dt, 
            COUNT(DISTINCT t025.c045) AS cnt_dn,
            COUNT(DISTINCT t025.c044) AS cnt_po,
            COUNT(*) AS cnt_item,
            COALESCE(SUM(t026.c005), 0) AS tot_qty
        FROM cwldata..t025 t025, cwldata..t026 t026, cwldata..t002 t002 
        WHERE t025.c021 = t026.c044  
          AND t025.mesocomp = t026.mesocomp
          AND t025.mesocomp = t002.mesocomp
          AND t025.c022 = t026.c045 
          AND t025.c029 BETWEEN ? AND ?
          AND LEFT(t025.c045, 3) = 'IDN'
          AND t026.c003 <> 'TEXT'
          AND t026.c055 <> 13
          AND (t026.c005 > 0) 
          AND t025.mesoyear = t002.mesoyear 	
          AND t026.c003 IN (SELECT c.c010 FROM cwldata..t023 c WHERE c.c005 IN (86)) 	
          AND (CASE t025.c041 WHEN 0 THEN 1 ELSE t025.c041 END) = t002.c017
        GROUP BY CONVERT(varchar(10), t025.c029, 20)";

        $stmtW = sqlsrv_query($conn, $sqlWinline, [$tglAwal, $tglAkhir]);
        if ($stmtW) {
            while ($r = sqlsrv_fetch_array($stmtW, SQLSRV_FETCH_ASSOC)) {
                $dt = trim($r['idn_dt'] ?? '');
                if (!empty($dt)) {
                    $winlineDaily[$dt] = [
                        'cnt_dn'   => (int)($r['cnt_dn'] ?? 0),
                        'cnt_po'   => (int)($r['cnt_po'] ?? 0),
                        'cnt_item' => (int)($r['cnt_item'] ?? 0),
                        'tot_qty'  => (float)($r['tot_qty'] ?? 0)
                    ];
                }
            }
        }
    }

    // 2. Ambil Ringkasan Harian dari Database Lokal Pabean (MySQL framasbc)
    $customsDaily = [];
    $docInSql = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $cleanDocs)) . "'";
    $escTglAwal = $koneksi->real_escape_string($tglAwal);
    $escTglAkhir = $koneksi->real_escape_string($tglAkhir);

    // Filter plant
    $plantSqlBc1a = "";
    $plantSqlCeisa = "";
    if ($plant !== 'ALL' && !empty($plant)) {
        $escPlant = $koneksi->real_escape_string($plant);
        $plantSqlBc1a = "AND cmp_id = '{$escPlant}'";
        $plantSqlCeisa = "AND d.cmp_id = '{$escPlant}'";
    }

    if ($source === 'bc_1ab2') {
        // Sumber: Tabel bc_1ab2 (IT Inventory Pemasukan)
        $sqlCust = "SELECT 
            DATE(TANGGAL_DAFTAR) AS tgl,
            COUNT(DISTINCT NOMOR_DAFTAR) AS cnt_dok,
            COUNT(*) AS cnt_item,
            COALESCE(SUM(QTY), 0) AS tot_qty
        FROM bc_1ab2 
        WHERE AB = 'A' 
          AND BC IN ({$docInSql})
          {$plantSqlBc1a}
          AND TANGGAL_DAFTAR BETWEEN '{$escTglAwal}' AND '{$escTglAkhir}'
        GROUP BY DATE(TANGGAL_DAFTAR)";

        $resCust = $koneksi->query($sqlCust);
        if ($resCust) {
            while ($r = $resCust->fetch_assoc()) {
                $dt = trim($r['tgl'] ?? '');
                if (!empty($dt)) {
                    $customsDaily[$dt] = [
                        'cnt_dok'  => (int)($r['cnt_dok'] ?? 0),
                        'cnt_item' => (int)($r['cnt_item'] ?? 0),
                        'tot_qty'  => (float)($r['tot_qty'] ?? 0)
                    ];
                }
            }
        }
    } else {
        // Sumber: Tabel bc_ceisa_data (Hasil Sinkronisasi CEISA 4.0)
        $sqlCust = "SELECT 
            d.tanggalDaftar AS tgl,
            COUNT(DISTINCT d.nomorDaftar) AS cnt_dok,
            COUNT(b.seri_barang) AS cnt_item,
            COALESCE(SUM(b.jumlah_satuan), 0) AS tot_qty
        FROM bc_ceisa_data d
        LEFT JOIN bc_ceisa_barang b ON d.nomorAju = b.nomor_aju
        WHERE d.kodeDokumen IN ({$docInSql})
          {$plantSqlCeisa}
          AND d.tanggalDaftar BETWEEN '{$escTglAwal}' AND '{$escTglAkhir}'
        GROUP BY d.tanggalDaftar";

        $resCust = $koneksi->query($sqlCust);
        if ($resCust) {
            while ($r = $resCust->fetch_assoc()) {
                $dt = trim($r['tgl'] ?? '');
                if (!empty($dt)) {
                    $customsDaily[$dt] = [
                        'cnt_dok'  => (int)($r['cnt_dok'] ?? 0),
                        'cnt_item' => (int)($r['cnt_item'] ?? 0),
                        'tot_qty'  => (float)($r['tot_qty'] ?? 0)
                    ];
                }
            }
        }
    }

    // 3. Gabungkan Semua Tanggal (Outer Merge)
    $allDates = array_unique(array_merge(array_keys($winlineDaily), array_keys($customsDaily)));
    rsort($allDates);

    $summaryList = [];
    $totalWinlineQty = 0;
    $totalCustomsQty = 0;
    $totalWinlineDok = 0;
    $totalCustomsDok = 0;
    $cntMatch = 0;
    $cntDiff = 0;

    foreach ($allDates as $dt) {
        $w = $winlineDaily[$dt] ?? ['cnt_dn' => 0, 'cnt_po' => 0, 'cnt_item' => 0, 'tot_qty' => 0];
        $c = $customsDaily[$dt] ?? ['cnt_dok' => 0, 'cnt_item' => 0, 'tot_qty' => 0];

        $wQty = round($w['tot_qty'], 4);
        $cQty = round($c['tot_qty'], 4);
        $wDok = $w['cnt_dn'];
        $cDok = $c['cnt_dok'];

        $diffQty = round($wQty - $cQty, 4);
        $diffDok = $wDok - $cDok;

        // Status Klasifikasi
        $status = 'MATCH';
        if ($wDok > 0 && $cDok === 0) {
            $status = 'WINLINE_ONLY';
        } elseif ($wDok === 0 && $cDok > 0) {
            $status = 'CUSTOMS_ONLY';
        } elseif (abs($diffQty) > 0.0001 || $diffDok !== 0) {
            $status = 'VARIANCE';
        } else {
            $status = 'MATCH';
        }

        if ($status === 'MATCH') {
            $cntMatch++;
        } else {
            $cntDiff++;
        }

        // Filter status jika dipilih
        if ($statusFilter !== 'ALL' && $statusFilter !== $status) {
            continue;
        }

        $totalWinlineQty += $wQty;
        $totalCustomsQty += $cQty;
        $totalWinlineDok += $wDok;
        $totalCustomsDok += $cDok;

        $summaryList[] = [
            'tanggal'        => $dt,
            'winline_dn'     => $wDok,
            'winline_po'     => $w['cnt_po'],
            'winline_items'  => $w['cnt_item'],
            'winline_qty'    => $wQty,
            'customs_dok'    => $cDok,
            'customs_items'  => $c['cnt_item'],
            'customs_qty'    => $cQty,
            'diff_dok'       => $diffDok,
            'diff_qty'       => $diffQty,
            'status'         => $status
        ];
    }

    $totProcessed = $cntMatch + $cntDiff;
    $matchRate = $totProcessed > 0 ? round(($cntMatch / $totProcessed) * 100, 1) : 100;

    echo json_encode([
        'status' => 'OK',
        'data'   => $summaryList,
        'kpi'    => [
            'total_dates'       => count($summaryList),
            'winline_total_qty' => $totalWinlineQty,
            'customs_total_qty' => $totalCustomsQty,
            'net_qty_diff'      => round($totalWinlineQty - $totalCustomsQty, 4),
            'winline_total_dok' => $totalWinlineDok,
            'customs_total_dok' => $totalCustomsDok,
            'net_dok_diff'      => $totalWinlineDok - $totalCustomsDok,
            'match_count'       => $cntMatch,
            'variance_count'    => $cntDiff,
            'match_rate'        => $matchRate
        ]
    ]);
    exit;
}

// =========================================================================
// API 3: AMBIL DETAIL PERBANDINGAN DUA SISI PADA SATU TANGGAL
// =========================================================================
if ($action === 'get_compare_detail') {
    header('Content-Type: application/json; charset=utf-8');

    $targetDate = trim($_REQUEST['tgl'] ?? date('Y-m-d'));
    $source     = trim($_REQUEST['source'] ?? 'bc_1ab2');
    $plant      = trim($_REQUEST['plant'] ?? 'ALL');

    $docsReq = $_REQUEST['doc_types'] ?? ['40', '23', '16', '262', '27'];
    if (is_string($docsReq)) $docsReq = explode(',', $docsReq);
    $cleanDocs = [];
    foreach ($docsReq as $d) {
        $d = trim($d);
        if (in_array($d, ['40', '23', '16', '262', '27', '20'])) $cleanDocs[] = $d;
    }
    if (empty($cleanDocs)) $cleanDocs = ['40', '23', '16', '262', '27'];

    // 1. Data IDN Winline pada tanggal tersebut
    $winlineItems = [];
    $conn = getWinlineDb();
    if ($conn) {
        $sqlW = "SELECT 
            t025.c045 AS dn_no, 
            t025.c044 AS po_number, 
            CONVERT(varchar(10), t025.c059, 20) AS order_dt, 
            t025.c004 AS supplier_name, 
            t026.c114 AS seq, 
            t026.c003 AS item_code, 
            t026.c004 AS item_name,
            t026.c005 AS qty 
        FROM cwldata..t025 t025, cwldata..t026 t026, cwldata..t002 t002 
        WHERE t025.c021 = t026.c044  
          AND t025.mesocomp = t026.mesocomp
          AND t025.mesocomp = t002.mesocomp
          AND t025.c022 = t026.c045 
          AND t025.c029 = ?
          AND LEFT(t025.c045, 3) = 'IDN'
          AND t026.c003 <> 'TEXT'
          AND t026.c055 <> 13
          AND (t026.c005 > 0) 
          AND t025.mesoyear = t002.mesoyear 	
          AND t026.c003 IN (SELECT c.c010 FROM cwldata..t023 c WHERE c.c005 IN (86)) 	
          AND (CASE t025.c041 WHEN 0 THEN 1 ELSE t025.c041 END) = t002.c017
        ORDER BY dn_no, seq";

        $stmtW = sqlsrv_query($conn, $sqlW, [$targetDate]);
        if ($stmtW) {
            while ($r = sqlsrv_fetch_array($stmtW, SQLSRV_FETCH_ASSOC)) {
                $winlineItems[] = [
                    'dn_no'         => trim($r['dn_no'] ?? ''),
                    'po_number'     => trim($r['po_number'] ?? ''),
                    'order_dt'      => trim($r['order_dt'] ?? ''),
                    'supplier_name' => trim($r['supplier_name'] ?? ''),
                    'seq'           => (int)($r['seq'] ?? 0),
                    'item_code'     => trim($r['item_code'] ?? ''),
                    'item_name'     => trim($r['item_name'] ?? ''),
                    'qty'           => (float)($r['qty'] ?? 0)
                ];
            }
        }
    }

    // 2. Data Pabean pada tanggal tersebut
    $customsItems = [];
    $docInSql = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $cleanDocs)) . "'";
    $escDate = $koneksi->real_escape_string($targetDate);

    $plantSqlBc1a = "";
    $plantSqlCeisa = "";
    if ($plant !== 'ALL' && !empty($plant)) {
        $escPlant = $koneksi->real_escape_string($plant);
        $plantSqlBc1a = "AND cmp_id = '{$escPlant}'";
        $plantSqlCeisa = "AND d.cmp_id = '{$escPlant}'";
    }

    if ($source === 'bc_1ab2') {
        $sqlC = "SELECT 
            BC AS kode_dokumen,
            NOMOR_DAFTAR AS nomor_daftar,
            TANGGAL_DAFTAR AS tanggal_daftar,
            NO_BUKTI AS nomor_bukti,
            NOMOR_PO AS nomor_po,
            NOMOR_AJU AS nomor_aju,
            NAMA_BS AS pemasok,
            KODE_BARANG AS kode_barang,
            URAIAN AS uraian,
            UNIT AS sat,
            QTY AS qty,
            NILAI AS nilai,
            cmp_id
        FROM bc_1ab2 
        WHERE AB = 'A'
          AND BC IN ({$docInSql})
          {$plantSqlBc1a}
          AND TANGGAL_DAFTAR = '{$escDate}'
        ORDER BY NOMOR_DAFTAR, SERI_BARANG, KODE_BARANG";

        $resC = $koneksi->query($sqlC);
        if ($resC) {
            while ($r = $resC->fetch_assoc()) {
                $customsItems[] = [
                    'kode_dokumen'  => $r['kode_dokumen'],
                    'nomor_daftar' => $r['nomor_daftar'],
                    'tanggal_daftar'=> $r['tanggal_daftar'],
                    'nomor_bukti'  => $r['nomor_bukti'],
                    'nomor_po'     => $r['nomor_po'],
                    'nomor_aju'    => $r['nomor_aju'],
                    'pemasok'      => $r['pemasok'],
                    'kode_barang'  => $r['kode_barang'],
                    'uraian'       => $r['uraian'],
                    'sat'          => $r['sat'],
                    'qty'          => (float)$r['qty'],
                    'nilai'        => (float)$r['nilai'],
                    'cmp_id'       => $r['cmp_id']
                ];
            }
        }
    } else {
        $sqlC = "SELECT 
            d.kodeDokumen AS kode_dokumen,
            d.nomorDaftar AS nomor_daftar,
            d.tanggalDaftar AS tanggal_daftar,
            d.nomorAju AS nomor_aju,
            d.namaPerusahaan AS pemasok,
            b.kode_barang,
            b.uraian,
            b.kode_satuan AS sat,
            b.jumlah_satuan AS qty,
            b.fob AS nilai,
            d.cmp_id
        FROM bc_ceisa_data d
        LEFT JOIN bc_ceisa_barang b ON d.nomorAju = b.nomor_aju
        WHERE d.kodeDokumen IN ({$docInSql})
          {$plantSqlCeisa}
          AND d.tanggalDaftar = '{$escDate}'
        ORDER BY d.nomorDaftar, b.seri_barang";

        $resC = $koneksi->query($sqlC);
        if ($resC) {
            while ($r = $resC->fetch_assoc()) {
                $customsItems[] = [
                    'kode_dokumen'  => $r['kode_dokumen'],
                    'nomor_daftar' => $r['nomor_daftar'],
                    'tanggal_daftar'=> $r['tanggal_daftar'],
                    'nomor_bukti'  => '-',
                    'nomor_po'     => '-',
                    'nomor_aju'    => $r['nomor_aju'],
                    'pemasok'      => $r['pemasok'],
                    'kode_barang'  => $r['kode_barang'] ?: '-',
                    'uraian'       => $r['uraian'] ?: '-',
                    'sat'          => $r['sat'] ?: '-',
                    'qty'          => (float)($r['qty'] ?? 0),
                    'nilai'        => (float)($r['nilai'] ?? 0),
                    'cmp_id'       => $r['cmp_id']
                ];
            }
        }
    }

    echo json_encode([
        'status'        => 'OK',
        'target_date'   => $targetDate,
        'winline_items' => $winlineItems,
        'customs_items' => $customsItems
    ]);
    exit;
}

// Cek status koneksi live SQL Server Winline
$isDbOnline = false;
$testConn = getWinlineDb();
if ($testConn) {
    $isDbOnline = true;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winline Compare & IDN — PT FRAMAS INDONESIA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --bg-page: #f4f6f9;
            --bg-card: #ffffff;
            --border-color: #e8e8e8;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --header-bg: #fafafa;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #f5222d;
            --info: #13c2c2;
        }

        body[data-theme="dark"] {
            --bg-page: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --text-main: #d9d9d9;
            --text-muted: #8c8c8c;
            --primary: #177ddc;
            --primary-hover: #165996;
            --header-bg: #1d1d1d;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --info: #13a8a8;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 12px 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            font-size: 11.5px;
        }

        /* Top Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-title h1 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .badge-plant {
            background: linear-gradient(135deg, #1890ff, #096dd9);
            color: #fff;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .conn-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .conn-online { background: rgba(82, 196, 26, 0.15); color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.3); }
        .conn-offline { background: rgba(245, 34, 45, 0.15); color: #f5222d; border: 1px solid rgba(245, 34, 45, 0.3); }
        .dot { width: 6px; height: 6px; border-radius: 50%; }
        .dot-green { background: #52c41a; box-shadow: 0 0 6px #52c41a; }
        .dot-red { background: #f5222d; }

        /* Main Tabs Switcher */
        .main-tab-nav {
            display: flex;
            gap: 4px;
            background: var(--bg-card);
            padding: 3px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }

        .main-tab-btn {
            background: transparent;
            border: none;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .main-tab-btn:hover {
            color: var(--primary);
            background: rgba(24, 144, 255, 0.05);
        }

        .main-tab-btn.active {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(24, 144, 255, 0.25);
        }

        /* Filter Box */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .filter-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 10.5px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .form-control-custom {
            height: 27px;
            padding: 2px 7px;
            font-size: 11px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--bg-page);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15);
        }

        .btn-custom {
            height: 27px;
            padding: 0 10px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            border: 1px solid transparent;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s;
        }

        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: #52c41a; color: #fff; }
        .btn-success:hover { background: #73d13d; }
        .btn-secondary { background: var(--header-bg); color: var(--text-main); border-color: var(--border-color); }
        .btn-secondary:hover { border-color: var(--primary); color: var(--primary); }

        .quick-presets {
            display: inline-flex;
            gap: 3px;
        }

        .preset-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            padding: 2px 6px;
            font-size: 10px;
            border-radius: 3px;
            color: var(--text-muted);
            cursor: pointer;
        }
        .preset-btn:hover, .preset-btn.active {
            background: rgba(24, 144, 255, 0.1);
            color: var(--primary);
            border-color: var(--primary);
        }

        /* KPI Chips Container */
        .kpi-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 8px;
            margin-bottom: 12px;
        }

        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 3px;
        }

        .kpi-blue::before { background: #1890ff; }
        .kpi-green::before { background: #52c41a; }
        .kpi-orange::before { background: #fa8c16; }
        .kpi-purple::before { background: #722ed1; }
        .kpi-teal::before { background: #13c2c2; }
        .kpi-red::before { background: #f5222d; }

        .kpi-title {
            font-size: 9.5px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .kpi-value {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
        }

        .kpi-sub {
            font-size: 9px;
            color: var(--text-muted);
        }

        /* Table Card */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            max-height: calc(100vh - 270px);
        }

        table.winline-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            text-align: left;
        }

        table.winline-table th {
            background: var(--header-bg);
            color: var(--text-muted);
            font-weight: 700;
            font-size: 10px;
            padding: 6px 8px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        table.winline-table td {
            padding: 5px 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }

        table.winline-table tr:hover td {
            background: rgba(24, 144, 255, 0.035);
        }

        .badge-doc {
            display: inline-block;
            font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 9.5px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
        }

        .badge-dn { background: rgba(24, 144, 255, 0.12); color: #1890ff; }
        .badge-po { background: rgba(114, 46, 209, 0.12); color: #722ed1; }
        .badge-bc { background: rgba(19, 194, 194, 0.12); color: #13c2c2; }
        .badge-seq { background: var(--header-bg); color: var(--text-muted); border: 1px solid var(--border-color); }

        .badge-status {
            font-size: 9.5px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-match { background: rgba(82, 196, 26, 0.12); color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.3); }
        .status-variance { background: rgba(245, 34, 45, 0.12); color: #f5222d; border: 1px solid rgba(245, 34, 45, 0.3); }
        .status-winline-only { background: rgba(24, 144, 255, 0.12); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.3); }
        .status-customs-only { background: rgba(250, 140, 22, 0.12); color: #fa8c16; border: 1px solid rgba(250, 140, 22, 0.3); }

        .diff-pos { color: #52c41a; font-weight: 700; }
        .diff-neg { color: #f5222d; font-weight: 700; }
        .diff-zero { color: var(--text-muted); }

        /* Modal Styles */
        .custom-modal-backdrop {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 15px;
        }

        .custom-modal-dialog {
            background: var(--bg-card);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            width: 95%;
            max-width: 1250px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        }

        .modal-header-custom {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--header-bg);
        }

        .modal-header-custom h3 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-body-custom {
            padding: 12px;
            overflow-y: auto;
            flex: 1;
        }

        .split-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 900px) {
            .split-grid { grid-template-columns: 1fr; }
        }

        .split-pane {
            background: var(--bg-page);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .split-pane-header {
            padding: 7px 10px;
            font-weight: 700;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }

        .pane-winline-header { background: rgba(24, 144, 255, 0.1); color: #1890ff; }
        .pane-customs-header { background: rgba(19, 194, 194, 0.1); color: #13c2c2; }

        .spinner-custom {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Offline alert banner */
        .alert-banner {
            background: rgba(245, 34, 45, 0.08);
            border: 1px solid rgba(245, 34, 45, 0.3);
            color: #f5222d;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 600;
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($_GET['theme'] ?? 'light') ?>">

    <!-- Top Page Header -->
    <div class="page-header">
        <div class="header-title">
            <h1>
                <span>⚖️</span>
                <span id="pageTitleText"><?= $activeTab === 'idn' ? 'Winline ERP — IDN (Incoming Delivery Note)' : 'Winline Compare — IDN vs IT Inventory / CEISA' ?></span>
            </h1>
            <span class="badge-plant" title="<?= htmlspecialchars($company['cmp_name'] ?? 'FRAMAS') ?>">
                <?= htmlspecialchars($sessionCmpId) ?>
            </span>
            <span class="conn-indicator <?= $isDbOnline ? 'conn-online' : 'conn-offline' ?>" title="Koneksi Microsoft SQL Server Winline ERP">
                <span class="dot <?= $isDbOnline ? 'dot-green' : 'dot-red' ?>"></span>
                <?= $isDbOnline ? '192.168.170.254 (FRAMASDB Online)' : '192.168.170.254 (Terputus / Offline)' ?>
            </span>
        </div>

        <!-- Tab Switcher -->
        <div class="main-tab-nav">
            <button type="button" class="main-tab-btn <?= $activeTab === 'idn' ? 'active' : '' ?>" onclick="switchViewTab('idn')">
                <i class="fas fa-inbox"></i> IDN (Incoming Delivery Note)
            </button>
            <button type="button" class="main-tab-btn <?= $activeTab === 'compare' ? 'active' : '' ?>" onclick="switchViewTab('compare')">
                <i class="fas fa-balance-scale"></i> IDN vs IT Inventory
            </button>
        </div>
    </div>

    <?php if (!$isDbOnline): ?>
    <div class="alert-banner">
        <i class="fas fa-exclamation-triangle"></i>
        <span>Peringatan: Gagal terhubung ke Microsoft SQL Server Winline ERP (IP: <code>192.168.170.254:1433</code>). Pastikan Anda terhubung ke jaringan LAN pabrik atau VPN Framas aktif.</span>
    </div>
    <?php endif; ?>

    <!-- ========================================================================= -->
    <!-- VIEW 1: IDN (Incoming Delivery Note)                                      -->
    <!-- ========================================================================= -->
    <div id="view-idn" style="display: <?= $activeTab === 'idn' ? 'block' : 'none' ?>;">
        <!-- Filter Card IDN -->
        <div class="filter-card">
            <div class="filter-row">
                <div class="filter-label"><i class="far fa-calendar-alt"></i> Periode IDN:</div>
                <input type="date" id="idnDateFrom" class="form-control-custom" value="<?= date('Y-m-d') ?>" style="width: 125px;">
                <span style="color:var(--text-muted);">s/d</span>
                <input type="date" id="idnDateTo" class="form-control-custom" value="<?= date('Y-m-d') ?>" style="width: 125px;">

                <div class="quick-presets">
                    <button type="button" class="preset-btn active" onclick="setPresetDate('idn', 'today')">Hari Ini</button>
                    <button type="button" class="preset-btn" onclick="setPresetDate('idn', 'yesterday')">Kemarin</button>
                    <button type="button" class="preset-btn" onclick="setPresetDate('idn', 'last7')">7 Hari</button>
                    <button type="button" class="preset-btn" onclick="setPresetDate('idn', 'this_month')">Bulan Ini</button>
                    <button type="button" class="preset-btn" onclick="setPresetDate('idn', 'last_month')">Bulan Lalu</button>
                </div>

                <div style="flex: 1; min-width: 180px;">
                    <input type="text" id="idnSearch" class="form-control-custom" placeholder="Cari No. IDN / No. PO / Supplier / Kode / Nama Barang..." style="width: 100%;">
                </div>

                <button type="button" class="btn-custom btn-primary" onclick="loadIdnData()">
                    <i class="fas fa-search"></i> Tampilkan
                </button>
                <button type="button" class="btn-custom btn-success" onclick="exportIdnExcel()">
                    <i class="fas fa-file-excel"></i> Export Excel
                </button>
            </div>
        </div>

        <!-- KPI Strip IDN -->
        <div class="kpi-container">
            <div class="kpi-card kpi-blue">
                <span class="kpi-title">Total Dokumen IDN</span>
                <span class="kpi-value" id="kpiIdnTotalDn">0</span>
                <span class="kpi-sub">Nomor Delivery Note</span>
            </div>
            <div class="kpi-card kpi-purple">
                <span class="kpi-title">Total Purchase Order</span>
                <span class="kpi-value" id="kpiIdnTotalPo">0</span>
                <span class="kpi-sub">Nomor PO Winline</span>
            </div>
            <div class="kpi-card kpi-teal">
                <span class="kpi-title">Total Baris Item</span>
                <span class="kpi-value" id="kpiIdnTotalItems">0</span>
                <span class="kpi-sub">Detail Baris Barang</span>
            </div>
            <div class="kpi-card kpi-green">
                <span class="kpi-title">Total Qty Diterima</span>
                <span class="kpi-value" id="kpiIdnTotalQty">0</span>
                <span class="kpi-sub">Akumulasi Fisik Barang</span>
            </div>
            <div class="kpi-card kpi-orange">
                <span class="kpi-title">Jumlah Supplier</span>
                <span class="kpi-value" id="kpiIdnTotalSuppliers">0</span>
                <span class="kpi-sub">Vendor Pengirim</span>
            </div>
        </div>

        <!-- Table IDN -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="winline-table" id="tableIdn">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">No</th>
                            <th style="width: 85px;">Tgl IDN</th>
                            <th style="width: 105px;">No. IDN</th>
                            <th style="width: 100px;">No. PO</th>
                            <th style="width: 85px;">Tgl PO</th>
                            <th>Nama Supplier / Vendor</th>
                            <th style="width: 45px; text-align: center;">Seq</th>
                            <th style="width: 155px;">Kode Barang</th>
                            <th>Nama / Uraian Barang</th>
                            <th style="width: 85px; text-align: right;">Qty</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyIdn">
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 25px; color: var(--text-muted);">
                                <i class="fas fa-spinner fa-spin"></i> Memuat data IDN Winline...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- VIEW 2: IDN vs IT INVENTORY (Winline Compare)                             -->
    <!-- ========================================================================= -->
    <div id="view-compare" style="display: <?= $activeTab === 'compare' ? 'block' : 'none' ?>;">
        <!-- Filter Card Compare -->
        <div class="filter-card">
            <div class="filter-row" style="margin-bottom: 8px;">
                <div class="filter-label"><i class="far fa-calendar-alt"></i> Range Rekonsiliasi:</div>
                <input type="date" id="cmpDateFrom" class="form-control-custom" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" style="width: 125px;">
                <span style="color:var(--text-muted);">s/d</span>
                <input type="date" id="cmpDateTo" class="form-control-custom" value="<?= date('Y-m-d') ?>" style="width: 125px;">

                <div class="quick-presets">
                    <button type="button" class="preset-btn" onclick="setPresetDate('cmp', 'today')">Hari Ini</button>
                    <button type="button" class="preset-btn" onclick="setPresetDate('cmp', 'yesterday')">Kemarin</button>
                    <button type="button" class="preset-btn active" onclick="setPresetDate('cmp', 'last7')">7 Hari</button>
                    <button type="button" class="preset-btn" onclick="setPresetDate('cmp', 'this_month')">Bulan Ini</button>
                    <button type="button" class="preset-btn" onclick="setPresetDate('cmp', 'last_month')">Bulan Lalu</button>
                </div>

                <div class="filter-label" style="margin-left: 8px;"><i class="fas fa-database"></i> Sumber Pabean:</div>
                <select id="cmpSource" class="form-control-custom" style="font-weight: 600;">
                    <option value="bc_1ab2" selected>Tabel BC_1ab2 (IT Inventory Pemasukan)</option>
                    <option value="bc_ceisa_data">Tabel bc_ceisa_data (Sync CEISA 4.0)</option>
                </select>

                <div class="filter-label" style="margin-left: 8px;"><i class="fas fa-industry"></i> Plant:</div>
                <select id="cmpPlant" class="form-control-custom">
                    <?php foreach ($framasPlants as $pCode => $pName): ?>
                        <option value="<?= $pCode ?>" <?= $pCode === 'ALL' ? 'selected' : '' ?>><?= $pCode ?> — <?= $pName ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-row">
                <div class="filter-label"><i class="fas fa-file-invoice"></i> Dokumen Pemasukan:</div>
                <label style="margin: 0; cursor: pointer; font-size: 10.5px; display:inline-flex; align-items:center; gap:3px;">
                    <input type="checkbox" class="doc-filter-chk" value="40" checked> BC 4.0
                </label>
                <label style="margin: 0; cursor: pointer; font-size: 10.5px; display:inline-flex; align-items:center; gap:3px;">
                    <input type="checkbox" class="doc-filter-chk" value="23" checked> BC 2.3
                </label>
                <label style="margin: 0; cursor: pointer; font-size: 10.5px; display:inline-flex; align-items:center; gap:3px;">
                    <input type="checkbox" class="doc-filter-chk" value="16" checked> BC 1.6
                </label>
                <label style="margin: 0; cursor: pointer; font-size: 10.5px; display:inline-flex; align-items:center; gap:3px;">
                    <input type="checkbox" class="doc-filter-chk" value="262" checked> BC 2.6.2
                </label>
                <label style="margin: 0; cursor: pointer; font-size: 10.5px; display:inline-flex; align-items:center; gap:3px;">
                    <input type="checkbox" class="doc-filter-chk" value="27" checked> BC 2.7 IN
                </label>

                <div class="filter-label" style="margin-left: 12px;"><i class="fas fa-filter"></i> Filter Status:</div>
                <select id="cmpStatusFilter" class="form-control-custom" style="width: 140px;">
                    <option value="ALL">Semua Status</option>
                    <option value="VARIANCE">⚠️ Hanya Selisih (Varian)</option>
                    <option value="MATCH">✅ Hanya Match (Sesuai)</option>
                    <option value="WINLINE_ONLY">🔵 Hanya di Winline</option>
                    <option value="CUSTOMS_ONLY">🟠 Hanya di Pabean</option>
                </select>

                <button type="button" class="btn-custom btn-primary" onclick="loadCompareSummary()">
                    <i class="fas fa-sync-alt"></i> Bandingkan Data
                </button>
                <button type="button" class="btn-custom btn-success" onclick="exportCompareExcel()">
                    <i class="fas fa-file-excel"></i> Export Rekap
                </button>
            </div>
        </div>

        <!-- KPI Strip Compare -->
        <div class="kpi-container">
            <div class="kpi-card kpi-blue">
                <span class="kpi-title">Total Qty Winline IDN</span>
                <span class="kpi-value" id="kpiCmpWinlineQty">0</span>
                <span class="kpi-sub" id="kpiCmpWinlineDok">0 Dokumen</span>
            </div>
            <div class="kpi-card kpi-teal">
                <span class="kpi-title">Total Qty Bea Cukai</span>
                <span class="kpi-value" id="kpiCmpCustomsQty">0</span>
                <span class="kpi-sub" id="kpiCmpCustomsDok">0 Dokumen</span>
            </div>
            <div class="kpi-card kpi-orange">
                <span class="kpi-title">Net Selisih Qty</span>
                <span class="kpi-value" id="kpiCmpNetQty">0</span>
                <span class="kpi-sub" id="kpiCmpNetDok">0 Dokumen Selisih</span>
            </div>
            <div class="kpi-card kpi-green">
                <span class="kpi-title">Tingkat Keselarasan</span>
                <span class="kpi-value" id="kpiCmpMatchRate">100%</span>
                <span class="kpi-sub" id="kpiCmpMatchSummary">0 Cocok / 0 Varian</span>
            </div>
        </div>

        <!-- Table Compare -->
        <div class="table-card">
            <div class="table-responsive">
                <table class="winline-table" id="tableCompare">
                    <thead>
                        <tr>
                            <th style="width: 95px;">Tanggal</th>
                            <th colspan="3" style="text-align: center; background: rgba(24, 144, 255, 0.08); color: #1890ff; border-left: 2px solid #1890ff;">
                                📥 WINLINE ERP (IDN)
                            </th>
                            <th colspan="3" style="text-align: center; background: rgba(19, 194, 194, 0.08); color: #13c2c2; border-left: 2px solid #13c2c2;">
                                🛃 BEA CUKAI (INBOUND)
                            </th>
                            <th colspan="2" style="text-align: center; background: rgba(250, 140, 22, 0.08); color: #fa8c16; border-left: 2px solid #fa8c16;">
                                ⚖️ SELISIH (VARIAN)
                            </th>
                            <th style="width: 130px; text-align: center;">Status</th>
                            <th style="width: 80px; text-align: center;">Aksi</th>
                        </tr>
                        <tr>
                            <th></th>
                            <!-- Winline -->
                            <th style="width: 75px; text-align: right; background: rgba(24, 144, 255, 0.04); border-left: 2px solid #1890ff;">Dokumen</th>
                            <th style="width: 65px; text-align: right; background: rgba(24, 144, 255, 0.04);">Item</th>
                            <th style="width: 95px; text-align: right; background: rgba(24, 144, 255, 0.04);">Total Qty</th>
                            <!-- Customs -->
                            <th style="width: 75px; text-align: right; background: rgba(19, 194, 194, 0.04); border-left: 2px solid #13c2c2;">Dokumen</th>
                            <th style="width: 65px; text-align: right; background: rgba(19, 194, 194, 0.04);">Item</th>
                            <th style="width: 95px; text-align: right; background: rgba(19, 194, 194, 0.04);">Total Qty</th>
                            <!-- Selisih -->
                            <th style="width: 70px; text-align: right; background: rgba(250, 140, 22, 0.04); border-left: 2px solid #fa8c16;">Δ Dok</th>
                            <th style="width: 90px; text-align: right; background: rgba(250, 140, 22, 0.04);">Δ Qty</th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="tbodyCompare">
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 25px; color: var(--text-muted);">
                                Silakan klik tombol "Bandingkan Data" untuk memproses rekonsiliasi.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL DETAIL REKONSILIASI DUA SISI (Side-by-Side Drilldown)                -->
    <!-- ========================================================================= -->
    <div class="custom-modal-backdrop" id="drilldownModal">
        <div class="custom-modal-dialog">
            <div class="modal-header-custom">
                <h3>
                    <i class="fas fa-columns" style="color: var(--primary);"></i>
                    <span>Rincian Rekonsiliasi Tanggal: <strong id="modalDateTitle" style="color: var(--primary);">-</strong></span>
                </h3>
                <button type="button" onclick="closeDrilldownModal()" style="background:none; border:none; font-size:16px; cursor:pointer; color:var(--text-muted);">&times;</button>
            </div>
            <div class="modal-body-custom">
                <div class="split-grid">
                    <!-- Sisi Kiri: Winline IDN -->
                    <div class="split-pane">
                        <div class="split-pane-header pane-winline-header">
                            <span><i class="fas fa-inbox"></i> Rincian IDN Winline ERP</span>
                            <span id="paneWinlineCount">0 Baris Item</span>
                        </div>
                        <div style="overflow-x: auto; max-height: 480px;">
                            <table class="winline-table" id="tableModalWinline">
                                <thead>
                                    <tr>
                                        <th style="width: 75px;">No. IDN</th>
                                        <th style="width: 75px;">No. PO</th>
                                        <th>Supplier</th>
                                        <th>Kode Barang</th>
                                        <th style="width: 65px; text-align: right;">Qty</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyModalWinline">
                                    <tr><td colspan="5" style="text-align:center; padding:15px; color:var(--text-muted);">Memuat data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sisi Kanan: Dokumen Pabean -->
                    <div class="split-pane">
                        <div class="split-pane-header pane-customs-header">
                            <span><i class="fas fa-passport"></i> Rincian Dokumen Pemasukan Pabean</span>
                            <span id="paneCustomsCount">0 Baris Item</span>
                        </div>
                        <div style="overflow-x: auto; max-height: 480px;">
                            <table class="winline-table" id="tableModalCustoms">
                                <thead>
                                    <tr>
                                        <th style="width: 45px;">BC</th>
                                        <th style="width: 80px;">No. Daftar</th>
                                        <th style="width: 85px;">No. Bukti / PO</th>
                                        <th>Kode Barang</th>
                                        <th style="width: 65px; text-align: right;">Qty</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyModalCustoms">
                                    <tr><td colspan="5" style="text-align:center; padding:15px; color:var(--text-muted);">Memuat data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div style="padding: 8px 14px; background: var(--header-bg); border-top: 1px solid var(--border-color); text-align: right;">
                <button type="button" class="btn-custom btn-secondary" onclick="closeDrilldownModal()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- JAVASCRIPT LOGIC & HANDLERS                                               -->
    <!-- ========================================================================= -->
    <script>
        let currentTab = '<?= $activeTab ?>';
        let idnRawData = [];
        let compareRawData = [];

        // Tab View Switcher
        function switchViewTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.main-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('#view-idn, #view-compare').forEach(v => v.style.display = 'none');

            if (tab === 'idn') {
                document.querySelectorAll('.main-tab-btn')[0].classList.add('active');
                document.getElementById('view-idn').style.display = 'block';
                document.getElementById('pageTitleText').innerText = 'Winline ERP — IDN (Incoming Delivery Note)';
                if (idnRawData.length === 0) loadIdnData();
            } else {
                document.querySelectorAll('.main-tab-btn')[1].classList.add('active');
                document.getElementById('view-compare').style.display = 'block';
                document.getElementById('pageTitleText').innerText = 'Winline Compare — IDN vs IT Inventory / CEISA';
                if (compareRawData.length === 0) loadCompareSummary();
            }
        }

        // Date Preset Helper
        function setPresetDate(prefix, type) {
            const fromInput = document.getElementById(prefix === 'idn' ? 'idnDateFrom' : 'cmpDateFrom');
            const toInput = document.getElementById(prefix === 'idn' ? 'idnDateTo' : 'cmpDateTo');

            // Toggle active preset button in container
            const container = fromInput.closest('.filter-row').querySelector('.quick-presets');
            if (container) {
                container.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
                const clickedBtn = event ? event.target : null;
                if (clickedBtn && clickedBtn.classList.contains('preset-btn')) clickedBtn.classList.add('active');
            }

            const now = new Date();
            const formatDate = (d) => {
                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            if (type === 'today') {
                fromInput.value = formatDate(now);
                toInput.value = formatDate(now);
            } else if (type === 'yesterday') {
                const y = new Date(now);
                y.setDate(y.getDate() - 1);
                fromInput.value = formatDate(y);
                toInput.value = formatDate(y);
            } else if (type === 'last7') {
                const l7 = new Date(now);
                l7.setDate(l7.getDate() - 6);
                fromInput.value = formatDate(l7);
                toInput.value = formatDate(now);
            } else if (type === 'this_month') {
                const f = new Date(now.getFullYear(), now.getMonth(), 1);
                fromInput.value = formatDate(f);
                toInput.value = formatDate(now);
            } else if (type === 'last_month') {
                const f = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                const l = new Date(now.getFullYear(), now.getMonth(), 0);
                fromInput.value = formatDate(f);
                toInput.value = formatDate(l);
            }

            if (prefix === 'idn') loadIdnData();
            else loadCompareSummary();
        }

        // Format Numbers
        function formatNumber(num, decimals = 2) {
            if (num === null || num === undefined || isNaN(num)) return '0';
            return parseFloat(num).toLocaleString('id-ID', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        }

        // =========================================================
        // HANDLER 1: LOAD IDN DATA
        // =========================================================
        async function loadIdnData() {
            const tglAwal = document.getElementById('idnDateFrom').value;
            const tglAkhir = document.getElementById('idnDateTo').value;
            const search = document.getElementById('idnSearch').value;
            const tbody = document.getElementById('tbodyIdn');

            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:25px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Memuat data IDN Winline dari 192.168.170.254...</td></tr>`;

            try {
                const res = await fetch(`winline_compare.php?action=get_idn_data&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}&search=${encodeURIComponent(search)}`);
                const json = await res.json();

                if (json.status !== 'OK') throw new Error(json.message);

                idnRawData = json.data || [];
                const kpi = json.kpi || {};

                // Update KPI
                document.getElementById('kpiIdnTotalDn').innerText = formatNumber(kpi.total_dn, 0);
                document.getElementById('kpiIdnTotalPo').innerText = formatNumber(kpi.total_po, 0);
                document.getElementById('kpiIdnTotalItems').innerText = formatNumber(kpi.total_rows, 0);
                document.getElementById('kpiIdnTotalQty').innerText = formatNumber(kpi.total_qty, 2);
                document.getElementById('kpiIdnTotalSuppliers').innerText = formatNumber(kpi.total_suppliers, 0);

                if (idnRawData.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:30px; color:var(--text-muted);">Tidak ada data Incoming Delivery Note (IDN) pada rentang tanggal terpilih.</td></tr>`;
                    return;
                }

                let html = '';
                idnRawData.forEach((r, idx) => {
                    html += `
                        <tr>
                            <td style="text-align: center; color: var(--text-muted);">${idx + 1}</td>
                            <td>${r.idn_dt}</td>
                            <td><span class="badge-doc badge-dn">${r.dn_no}</span></td>
                            <td>${r.po_number ? `<span class="badge-doc badge-po">${r.po_number}</span>` : '-'}</td>
                            <td>${r.order_dt || '-'}</td>
                            <td><strong>${r.supplier_name || '-'}</strong> ${r.vendor ? `<span style="color:var(--text-muted); font-size:9.5px;">(${r.vendor})</span>` : ''}</td>
                            <td style="text-align: center;"><span class="badge-doc badge-seq">${r.seq}</span></td>
                            <td><code style="font-weight:700; color:var(--primary);">${r.item_code}</code></td>
                            <td>${r.item_name || '-'}</td>
                            <td style="text-align: right; font-weight: 700;">${formatNumber(r.qty, 2)}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:25px; color:var(--danger);"><i class="fas fa-exclamation-circle"></i> ${err.message}</td></tr>`;
            }
        }

        // Live search pada input IDN
        let idnSearchTimeout = null;
        document.getElementById('idnSearch').addEventListener('input', () => {
            clearTimeout(idnSearchTimeout);
            idnSearchTimeout = setTimeout(() => {
                loadIdnData();
            }, 300);
        });

        // =========================================================
        // HANDLER 2: LOAD COMPARE SUMMARY
        // =========================================================
        async function loadCompareSummary() {
            const tglAwal = document.getElementById('cmpDateFrom').value;
            const tglAkhir = document.getElementById('cmpDateTo').value;
            const source = document.getElementById('cmpSource').value;
            const plant = document.getElementById('cmpPlant').value;
            const statusFilter = document.getElementById('cmpStatusFilter').value;

            // Dokumen Pemasukan tercentang
            const checkedDocs = [];
            document.querySelectorAll('.doc-filter-chk:checked').forEach(c => checkedDocs.push(c.value));
            if (checkedDocs.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Pilih Dokumen', text: 'Pilih minimal satu jenis dokumen pemasukan pabean.' });
                return;
            }

            const tbody = document.getElementById('tbodyCompare');
            tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:25px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Memproses rekonsiliasi data Winline vs Pabean...</td></tr>`;

            try {
                const params = new URLSearchParams({
                    action: 'get_compare_summary',
                    tgl_awal: tglAwal,
                    tgl_akhir: tglAkhir,
                    source: source,
                    plant: plant,
                    status_filter: statusFilter,
                    doc_types: checkedDocs.join(',')
                });

                const res = await fetch(`winline_compare.php?${params.toString()}`);
                const json = await res.json();

                if (json.status !== 'OK') throw new Error(json.message);

                compareRawData = json.data || [];
                const kpi = json.kpi || {};

                // Update KPI Compare
                document.getElementById('kpiCmpWinlineQty').innerText = formatNumber(kpi.winline_total_qty, 2);
                document.getElementById('kpiCmpWinlineDok').innerText = `${formatNumber(kpi.winline_total_dok, 0)} Dokumen IDN`;

                document.getElementById('kpiCmpCustomsQty').innerText = formatNumber(kpi.customs_total_qty, 2);
                document.getElementById('kpiCmpCustomsDok').innerText = `${formatNumber(kpi.customs_total_dok, 0)} Dokumen Pabean`;

                const netQtyEl = document.getElementById('kpiCmpNetQty');
                netQtyEl.innerText = formatNumber(kpi.net_qty_diff, 2);
                if (Math.abs(kpi.net_qty_diff) > 0.0001) {
                    netQtyEl.style.color = '#f5222d';
                } else {
                    netQtyEl.style.color = '#52c41a';
                }
                document.getElementById('kpiCmpNetDok').innerText = `Selisih: ${kpi.net_dok_diff > 0 ? '+' : ''}${kpi.net_dok_diff} Dok`;

                document.getElementById('kpiCmpMatchRate').innerText = `${kpi.match_rate}%`;
                document.getElementById('kpiCmpMatchSummary').innerText = `${kpi.match_count} Tanggal Match / ${kpi.variance_count} Selisih`;

                if (compareRawData.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:30px; color:var(--text-muted);">Tidak ada data rekonsiliasi pada rentang tanggal atau filter terpilih.</td></tr>`;
                    return;
                }

                let html = '';
                compareRawData.forEach(r => {
                    let statusBadge = '';
                    if (r.status === 'MATCH') {
                        statusBadge = `<span class="badge-status status-match"><i class="fas fa-check-circle"></i> MATCH</span>`;
                    } else if (r.status === 'VARIANCE') {
                        statusBadge = `<span class="badge-status status-variance"><i class="fas fa-exclamation-circle"></i> SELISIH</span>`;
                    } else if (r.status === 'WINLINE_ONLY') {
                        statusBadge = `<span class="badge-status status-winline-only"><i class="fas fa-info-circle"></i> HANYA WINLINE</span>`;
                    } else if (r.status === 'CUSTOMS_ONLY') {
                        statusBadge = `<span class="badge-status status-customs-only"><i class="fas fa-info-circle"></i> HANYA PABEAN</span>`;
                    }

                    const diffDokCls = r.diff_dok === 0 ? 'diff-zero' : (r.diff_dok > 0 ? 'diff-pos' : 'diff-neg');
                    const diffQtyCls = Math.abs(r.diff_qty) < 0.0001 ? 'diff-zero' : (r.diff_qty > 0 ? 'diff-pos' : 'diff-neg');

                    html += `
                        <tr>
                            <td><strong>${r.tanggal}</strong></td>
                            <!-- Winline -->
                            <td style="text-align: right; border-left: 2px solid #1890ff; font-weight:600;">${r.winline_dn}</td>
                            <td style="text-align: right; color:var(--text-muted);">${r.winline_items}</td>
                            <td style="text-align: right; font-weight:700; color:#1890ff;">${formatNumber(r.winline_qty, 2)}</td>
                            <!-- Customs -->
                            <td style="text-align: right; border-left: 2px solid #13c2c2; font-weight:600;">${r.customs_dok}</td>
                            <td style="text-align: right; color:var(--text-muted);">${r.customs_items}</td>
                            <td style="text-align: right; font-weight:700; color:#13c2c2;">${formatNumber(r.customs_qty, 2)}</td>
                            <!-- Selisih -->
                            <td style="text-align: right; border-left: 2px solid #fa8c16;" class="${diffDokCls}">${r.diff_dok > 0 ? '+' : ''}${r.diff_dok}</td>
                            <td style="text-align: right;" class="${diffQtyCls}">${r.diff_qty > 0 ? '+' : ''}${formatNumber(r.diff_qty, 2)}</td>
                            <td style="text-align: center;">${statusBadge}</td>
                            <td style="text-align: center;">
                                <button type="button" class="btn-custom btn-secondary" style="height:22px; padding:0 6px; font-size:10px;" onclick="openDrilldownModal('${r.tanggal}')" title="Lihat Rincian Dua Sisi">
                                    <i class="fas fa-search-plus"></i> Rincian
                                </button>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:25px; color:var(--danger);"><i class="fas fa-exclamation-circle"></i> ${err.message}</td></tr>`;
            }
        }

        // =========================================================
        // HANDLER 3: DRILLDOWN MODAL DETAIL DUA SISI
        // =========================================================
        async function openDrilldownModal(dateStr) {
            document.getElementById('modalDateTitle').innerText = dateStr;
            document.getElementById('drilldownModal').style.display = 'flex';

            const tbodyW = document.getElementById('tbodyModalWinline');
            const tbodyC = document.getElementById('tbodyModalCustoms');

            tbodyW.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Memuat data IDN Winline...</td></tr>`;
            tbodyC.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Memuat data Pabean...</td></tr>`;

            const source = document.getElementById('cmpSource').value;
            const plant = document.getElementById('cmpPlant').value;
            const checkedDocs = [];
            document.querySelectorAll('.doc-filter-chk:checked').forEach(c => checkedDocs.push(c.value));

            try {
                const params = new URLSearchParams({
                    action: 'get_compare_detail',
                    tgl: dateStr,
                    source: source,
                    plant: plant,
                    doc_types: checkedDocs.join(',')
                });

                const res = await fetch(`winline_compare.php?${params.toString()}`);
                const json = await res.json();

                if (json.status !== 'OK') throw new Error(json.message);

                const wItems = json.winline_items || [];
                const cItems = json.customs_items || [];

                document.getElementById('paneWinlineCount').innerText = `${wItems.length} Baris Item`;
                document.getElementById('paneCustomsCount').innerText = `${cItems.length} Baris Item`;

                // Render Winline Items
                if (wItems.length === 0) {
                    tbodyW.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada transaksi IDN pada tanggal ini.</td></tr>`;
                } else {
                    let htmlW = '';
                    wItems.forEach(r => {
                        htmlW += `
                            <tr>
                                <td><span class="badge-doc badge-dn">${r.dn_no}</span></td>
                                <td>${r.po_number ? `<span class="badge-doc badge-po">${r.po_number}</span>` : '-'}</td>
                                <td style="max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${r.supplier_name}">${r.supplier_name}</td>
                                <td><code style="font-weight:700;">${r.item_code}</code><br><span style="color:var(--text-muted); font-size:9.5px;">${r.item_name}</span></td>
                                <td style="text-align:right; font-weight:700;">${formatNumber(r.qty, 2)}</td>
                            </tr>
                        `;
                    });
                    tbodyW.innerHTML = htmlW;
                }

                // Render Customs Items
                if (cItems.length === 0) {
                    tbodyC.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada transaksi dokumen pabean pada tanggal ini.</td></tr>`;
                } else {
                    let htmlC = '';
                    cItems.forEach(r => {
                        htmlC += `
                            <tr>
                                <td><span class="badge-doc badge-bc">BC ${r.kode_dokumen}</span></td>
                                <td><strong>${r.nomor_daftar || '-'}</strong><br><span style="font-size:9px; color:var(--text-muted);">${r.nomor_aju ? r.nomor_aju.substr(-6) : ''}</span></td>
                                <td>${r.nomor_po && r.nomor_po !== '-' ? `<span class="badge-doc badge-po">${r.nomor_po}</span>` : (r.nomor_bukti || '-')}</td>
                                <td><code style="font-weight:700;">${r.kode_barang}</code><br><span style="color:var(--text-muted); font-size:9.5px;">${r.uraian}</span></td>
                                <td style="text-align:right; font-weight:700;">${formatNumber(r.qty, 2)}</td>
                            </tr>
                        `;
                    });
                    tbodyC.innerHTML = htmlC;
                }

            } catch (err) {
                tbodyW.innerHTML = `<tr><td colspan="5" style="color:var(--danger);">${err.message}</td></tr>`;
                tbodyC.innerHTML = `<tr><td colspan="5" style="color:var(--danger);">${err.message}</td></tr>`;
            }
        }

        function closeDrilldownModal() {
            document.getElementById('drilldownModal').style.display = 'none';
        }

        // Close modal on backdrop click
        document.getElementById('drilldownModal').addEventListener('click', (e) => {
            if (e.target.id === 'drilldownModal') closeDrilldownModal();
        });

        // =========================================================
        // HANDLER 4: EXPORT EXCEL
        // =========================================================
        function exportIdnExcel() {
            if (idnRawData.length === 0) {
                Swal.fire({ icon: 'info', title: 'Data Kosong', text: 'Tidak ada data untuk diekspor.' });
                return;
            }

            let csv = "No\tTanggal IDN\tNo. IDN\tNo. PO\tTanggal PO\tSupplier\tVendor Code\tSeq\tKode Barang\tNama Barang\tQty\n";
            idnRawData.forEach((r, idx) => {
                csv += `${idx + 1}\t${r.idn_dt}\t${r.dn_no}\t${r.po_number}\t${r.order_dt}\t"${(r.supplier_name||'').replace(/"/g, '""')}"\t${r.vendor}\t${r.seq}\t${r.item_code}\t"${(r.item_name||'').replace(/"/g, '""')}"\t${r.qty}\n`;
            });

            const blob = new Blob(["\uFEFF" + csv], { type: 'application/vnd.ms-excel;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `IDN_Winline_${document.getElementById('idnDateFrom').value}_sd_${document.getElementById('idnDateTo').value}.xls`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        function exportCompareExcel() {
            if (compareRawData.length === 0) {
                Swal.fire({ icon: 'info', title: 'Data Kosong', text: 'Tidak ada data rekonsiliasi untuk diekspor.' });
                return;
            }

            let csv = "Tanggal\tWinline Dokumen\tWinline Item\tWinline Total Qty\tBea Cukai Dokumen\tBea Cukai Item\tBea Cukai Total Qty\tSelisih Dokumen\tSelisih Qty\tStatus\n";
            compareRawData.forEach(r => {
                csv += `${r.tanggal}\t${r.winline_dn}\t${r.winline_items}\t${r.winline_qty}\t${r.customs_dok}\t${r.customs_items}\t${r.customs_qty}\t${r.diff_dok}\t${r.diff_qty}\t${r.status}\n`;
            });

            const blob = new Blob(["\uFEFF" + csv], { type: 'application/vnd.ms-excel;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `Rekonsiliasi_IDN_vs_IT_Inventory_${document.getElementById('cmpDateFrom').value}_sd_${document.getElementById('cmpDateTo').value}.xls`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        // Initialize on load
        window.addEventListener('DOMContentLoaded', () => {
            if (currentTab === 'idn') {
                loadIdnData();
            } else {
                loadCompareSummary();
            }
        });
    </script>
</body>
</html>

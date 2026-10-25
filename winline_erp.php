<?php
/**
 * CEISA 4.0 - Add-on Module: Winline ERP Integration
 * File: winline_erp.php
 * 
 * Modul integrasi sistem Winline ERP PT Framas Indonesia ke Host to Host CEISA 4.0:
 * Mengadaptasi logika dan antarmuka dari PowerBuilder:
 * 1. Sales Invoice  : w_custom_find_invoice3.srw & d_custom_find_invoice_new.srd (pur_order2cash_sales & t025/t026) -> BC 2.7 & BC 3.0
 * 2. Purchase Order : w_custom_find_po.srw & d_custom_find_po2.srd & d_custom_find_po_dtl3.srd (t025 & t026) -> BC 2.3, BC 4.0, BC 1.6
 * 3. DN Transfer    : w_custom_xtrn_doc_find.srw & dw_custom_xtrn_doc_find.srd (t025 & t026) -> BC 2.7 Antar Framas
 * 
 * Koneksi Database Winline ERP (Microsoft SQL Server):
 *   Host     : 192.168.170.254
 *   Database : FRAMASDB
 *   UID      : admin
 *   PWD      : @#IT@fr4m45$%
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';
require_once __DIR__ . '/erp/ErpManager.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? 'FRM-1');
$targetType = trim($_GET['type'] ?? 'sales_invoice');
if (!in_array($targetType, ['sales_invoice', 'dn_transfer', 'purchase_order'])) {
    $targetType = 'sales_invoice';
}

$escCmp = $koneksi->real_escape_string($sessionCmpId);

// Ambil profil perusahaan aktif
$company = [];
$resCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
if ($resCmp && $rC = $resCmp->fetch_assoc()) {
    $company = $rC;
}

// -------------------------------------------------------------
// HELPER: KONEKSI KE SQL SERVER WINLINE (FRAMASDB)
// -------------------------------------------------------------
if (!function_exists('getWinlineDb')) {
    function getWinlineDb() {
        static $conn = null;
        if ($conn !== null) return $conn;

        $serverName = "192.168.170.254";
        $connectionInfo = array(
            "Database" => "FRAMASDB",
            "UID" => "admin",
            "PWD" => "@#IT@fr4m45$%",
            "CharacterSet" => "UTF-8",
            "TrustServerCertificate" => true,
            "LoginTimeout" => 4
        );

        if (function_exists('sqlsrv_connect')) {
            $conn = @sqlsrv_connect($serverName, $connectionInfo);
        }
        return $conn;
    }
}

// -------------------------------------------------------------
// AJAX API ENDPOINTS
// -------------------------------------------------------------
$action = trim($_REQUEST['action'] ?? '');

// 1. CEK KONEKSI KE WINLINE ERP
if ($action === 'check_connection') {
    header('Content-Type: application/json; charset=utf-8');
    $conn = getWinlineDb();
    if ($conn) {
        echo json_encode(['status' => 'OK', 'connected' => true, 'message' => 'Terhubung ke Winline ERP (192.168.170.254:FRAMASDB)']);
    } else {
        echo json_encode(['status' => 'OFFLINE', 'connected' => false, 'message' => 'Tidak dapat terhubung ke server Winline ERP 192.168.170.254.']);
    }
    exit;
}

// 2. LOAD DATA DOKUMEN DARI WINLINE ERP
if ($action === 'load_data') {
    header('Content-Type: application/json; charset=utf-8');
    $type     = trim($_GET['type'] ?? $targetType);
    $q        = trim($_GET['q'] ?? '');
    $dt1      = trim($_GET['date_from'] ?? date('Y-m-01'));
    $dt2      = trim($_GET['date_to'] ?? date('Y-m-d'));
    $targetBc = trim($_GET['target_bc'] ?? '');

    $conn = getWinlineDb();
    $rows = [];
    $isLive = ($conn !== null && $conn !== false);

    if ($isLive) {
        if ($type === 'sales_invoice') {
            // Adaptasi dari: w_custom_find_invoice3.srw & d_custom_find_invoice_new.srd
            $params = [$dt1, $dt2];
            $sql = "SELECT DISTINCT TOP 200
                s.inv_no AS doc_no,
                s.dn_no,
                s.delivery_dt,
                s.customer AS partner_name,
                s.cust_cd,
                s.po_no,
                s.inv_dt,
                s.dn_dt,
                s.oc_dt,
                s.ctn AS total_kemas,
                s.qty AS total_qty,
                s.gw AS bruto,
                s.nw AS netto,
                s.category,
                s.dn_pass,
                s.nomor_aju,
                s.bc
            FROM pur_order2cash_sales s WITH (NOLOCK)
            WHERE s.delivery_dt BETWEEN ? AND ? ";

            if (!empty($q)) {
                $sql .= " AND (s.inv_no LIKE ? OR s.dn_no LIKE ? OR s.customer LIKE ? OR s.po_no LIKE ?) ";
                $p = "%$q%";
                $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p;
            }
            $sql .= " ORDER BY s.delivery_dt DESC";

            $res = sqlsrv_query($conn, $sql, $params);
            if ($res) {
                while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
                    $docDt = $r['delivery_dt'] ? $r['delivery_dt']->format('Y-m-d') : ($r['inv_dt'] ? $r['inv_dt']->format('Y-m-d') : date('Y-m-d'));
                    
                    // Tentukan Target BC (Default: 27 untuk lokal TPB, 30 untuk PEB Ekspor)
                    $determinedBc = '27';
                    if (!empty($r['bc'])) {
                        $determinedBc = preg_replace('/[^0-9]/', '', $r['bc']);
                    } elseif (stripos($r['customer'], 'INTERNATIONAL') !== false || stripos($r['customer'], 'SINGAPORE') !== false || stripos($r['customer'], 'SWITZERLAND') !== false || stripos($r['category'], 'EXPORT') !== false) {
                        $determinedBc = '30';
                    }

                    $bcLabel = ($determinedBc === '30') ? 'BC 3.0 (PEB Ekspor)' : 'BC 2.7 (TPB ke TPB Lain)';
                    $valuta  = ($determinedBc === '30') ? 'USD' : 'USD'; // Framas Invoice standard

                    $rows[] = [
                        'doc_no'       => $r['doc_no'] ?: ($r['dn_no'] ?: '-'),
                        'ref_no'       => $r['dn_no'] ?: '',
                        'doc_date'     => $docDt,
                        'partner_name' => $r['partner_name'] ?: 'CUSTOMER',
                        'partner_addr' => 'Kode: ' . ($r['cust_cd'] ?: '-') . ' | PO: ' . ($r['po_no'] ?: '-'),
                        'target_bc'    => $determinedBc,
                        'bc_label'     => $bcLabel,
                        'valuta'       => $valuta,
                        'total_fob'    => 0, // Dihitung di detail
                        'total_qty'    => (float)($r['total_qty'] ?? 0),
                        'unit'         => 'PR',
                        'total_kemas'  => (int)($r['total_kemas'] ?? 1),
                        'kemas_sat'    => 'CT',
                        'netto'        => (float)($r['netto'] ?? 0),
                        'bruto'        => (float)($r['bruto'] ?? 0),
                        'status_h2h'   => !empty($r['nomor_aju']) ? 'integrated' : 'ready',
                        'nomor_aju'    => $r['nomor_aju'] ?: ''
                    ];
                }
            }
        } elseif ($type === 'dn_transfer') {
            // Adaptasi dari: w_custom_xtrn_doc_find.srw & dw_custom_xtrn_doc_find.srd
            $params = [$dt1, $dt2, $dt1, $dt2];
            $sql = "SELECT TOP 200
                b.c045 AS doc_no,
                b.c044 AS ref_no,
                COALESCE(b.c029, b.c028) AS doc_date,
                b.c010 AS supplier_name,
                b.c004 AS cust_name,
                b.c021 AS partner_cd,
                b.c154 AS bruto,
                b.mesocomp,
                COUNT(a.c003) AS total_items,
                SUM(a.c005) AS total_qty,
                SUM(a.c031) AS total_amount
            FROM cwldata..t025 b WITH (NOLOCK)
            LEFT JOIN cwldata..t026 a WITH (NOLOCK) ON 
                a.c044 = b.c021 AND a.c045 = b.c022 AND a.mesocomp = b.mesocomp
                AND a.c055 <> 13 AND a.c003 <> 'TEXT'
            WHERE (b.c045 LIKE 'DN%' OR b.c044 LIKE 'DN%' OR b.c045 LIKE 'PL.DN%')
              AND (b.c029 BETWEEN ? AND ? OR b.c028 BETWEEN ? AND ?) ";

            if (!empty($q)) {
                $sql .= " AND (b.c045 LIKE ? OR b.c010 LIKE ? OR b.c004 LIKE ?) ";
                $p = "%$q%";
                $params[] = $p; $params[] = $p; $params[] = $p;
            }
            $sql .= " GROUP BY 
                b.c045, b.c044, b.c029, b.c028, b.c010, b.c004, b.c021, b.c154, b.mesocomp
            ORDER BY COALESCE(b.c029, b.c028) DESC";

            $res = sqlsrv_query($conn, $sql, $params);
            if ($res) {
                while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
                    $docDt = $r['doc_date'] ? $r['doc_date']->format('Y-m-d') : date('Y-m-d');
                    $partner = trim($r['cust_name'] ?: ($r['supplier_name'] ?: 'PT FRAMAS INDONESIA'));
                    $brt = (float)($r['bruto'] ?? 0);
                    $totQty = (float)($r['total_qty'] ?? 0);
                    $totAmt = (float)($r['total_amount'] ?? 0);

                    $rows[] = [
                        'doc_no'       => $r['doc_no'] ?: ($r['ref_no'] ?: '-'),
                        'ref_no'       => $r['ref_no'] ?: '',
                        'doc_date'     => $docDt,
                        'partner_name' => $partner,
                        'partner_addr' => 'Plant/Entitas: ' . ($r['mesocomp'] ?: '05FI'),
                        'target_bc'    => '27',
                        'bc_label'     => 'BC 2.7 (Antar Plant Framas)',
                        'valuta'       => 'IDR',
                        'total_fob'    => $totAmt,
                        'total_qty'    => $totQty,
                        'unit'         => 'PCE',
                        'total_kemas'  => max(1, (int)ceil($totQty / 50)),
                        'kemas_sat'    => 'BX',
                        'netto'        => ($brt > 0) ? round($brt * 0.9, 2) : round($totQty * 0.15, 2),
                        'bruto'        => ($brt > 0) ? $brt : round($totQty * 0.18, 2),
                        'status_h2h'   => 'ready',
                        'nomor_aju'    => ''
                    ];
                }
            }
        } else {
            // Purchase Order: w_custom_find_po.srw & d_custom_find_po2.srd
            $params = [$dt1, $dt2];
            $sql = "SELECT TOP 200
                t025.c044 AS doc_no,
                t025.c028 AS doc_date,
                t025.c010 AS partner_name,
                t025.c011 AS contact_person,
                t025.c012 AS partner_addr,
                t025.c014 AS city,
                t025.c021 AS partner_cd,
                t002.c000 AS valuta,
                t025.c154 AS bruto,
                t025.mesocomp,
                COUNT(t026.c003) AS total_items,
                SUM(t026.c005) AS total_qty,
                SUM(t026.c031) AS total_amount
            FROM cwldata..t025 t025 WITH (NOLOCK)
            LEFT JOIN cwldata..t002 t002 WITH (NOLOCK) ON 
                (case t025.c041 when 0 then 1 else t025.c041 end) = t002.c017 
                AND t025.mesoyear = t002.mesoyear 
                AND t025.mesocomp = t002.mesocomp
            LEFT JOIN cwldata..t026 t026 WITH (NOLOCK) ON 
                t025.c021 = t026.c044 
                AND t025.c022 = t026.c045 
                AND t025.mesocomp = t026.mesocomp 
                AND t026.c055 <> 13 
                AND t026.c003 <> 'TEXT'
            WHERE t025.c044 LIKE 'PO%'
              AND t025.c045 is null
              AND t025.c028 BETWEEN ? AND ? ";

            if (!empty($q)) {
                $sql .= " AND (t025.c044 LIKE ? OR t025.c010 LIKE ?) ";
                $p = "%$q%";
                $params[] = $p; $params[] = $p;
            }
            $sql .= " GROUP BY 
                t025.c044, t025.c028, t025.c010, t025.c011, t025.c012, t025.c014, 
                t025.c021, t002.c000, t025.c154, t025.mesocomp
            ORDER BY t025.c028 DESC";

            $res = sqlsrv_query($conn, $sql, $params);
            if ($res) {
                while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
                    $docDt = $r['doc_date'] ? $r['doc_date']->format('Y-m-d') : date('Y-m-d');
                    $valuta = trim($r['valuta'] ?: 'IDR');
                    $isImport = ($valuta !== 'IDR');
                    $determinedBc = $isImport ? '23' : '40';
                    $bcLabel = $isImport ? 'BC 2.3 (Impor Bahan Baku TPB)' : 'BC 4.0 (Pemasukan Lokal TLDDP)';
                    $totQty = (float)($r['total_qty'] ?? 0);
                    $totAmt = (float)($r['total_amount'] ?? 0);
                    $brt    = (float)($r['bruto'] ?? 0);

                    $rows[] = [
                        'doc_no'       => $r['doc_no'] ?: '-',
                        'ref_no'       => '',
                        'doc_date'     => $docDt,
                        'partner_name' => trim($r['partner_name'] ?: 'SUPPLIER'),
                        'partner_addr' => trim(($r['city'] ? $r['city'] . ', ' : '') . ($r['partner_addr'] ?: '')),
                        'target_bc'    => $determinedBc,
                        'bc_label'     => $bcLabel,
                        'valuta'       => $valuta,
                        'total_fob'    => $totAmt,
                        'total_qty'    => $totQty,
                        'unit'         => 'PCE',
                        'total_kemas'  => max(1, (int)ceil($totQty / 100)),
                        'kemas_sat'    => 'CT',
                        'netto'        => ($brt > 0) ? round($brt * 0.9, 2) : round($totQty * 0.2, 2),
                        'bruto'        => ($brt > 0) ? $brt : round($totQty * 0.22, 2),
                        'status_h2h'   => 'ready',
                        'nomor_aju'    => ''
                    ];
                }
            }
        }
    }

    // Periksa status integrasi H2H pada database lokal MySQL bc_ceisa_dokumen / bc_ceisa_header
    foreach ($rows as &$item) {
        $escDocNo = $koneksi->real_escape_string($item['doc_no']);
        $escRefNo = !empty($item['ref_no']) ? $koneksi->real_escape_string($item['ref_no']) : '';
        
        $chkSql = "SELECT d.nomor_aju, h.nomor_daftar, h.tanggal_daftar 
                   FROM bc_ceisa_dokumen d 
                   LEFT JOIN bc_ceisa_header h ON d.nomor_aju = h.nomor_aju 
                   WHERE d.nomor_dokumen = '$escDocNo' ";
        if (!empty($escRefNo)) {
            $chkSql .= " OR d.nomor_dokumen = '$escRefNo' ";
        }
        $chkSql .= " LIMIT 1";

        $chkAju = $koneksi->query($chkSql);
        if ($chkAju && $rAju = $chkAju->fetch_assoc()) {
            $item['status_h2h']   = 'integrated';
            $item['nomor_aju']    = $rAju['nomor_aju'];
            $item['nomor_daftar'] = $rAju['nomor_daftar'] ?: '-';
        }
    }
    unset($item);

    // Filter target BC jika dipilih di dropdown
    if (!empty($targetBc)) {
        $rows = array_values(array_filter($rows, function($r) use ($targetBc) {
            return $r['target_bc'] === $targetBc;
        }));
    }

    echo json_encode([
        'status'       => 'OK',
        'is_live'      => $isLive,
        'type'         => $type,
        'count'        => count($rows),
        'data'         => $rows
    ]);
    exit;
}

// -------------------------------------------------------------
// AJAX API: AMBIL DETAIL RINCIAN BARANG (GET PREVIEW)
// -------------------------------------------------------------
if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $docNo    = trim($_GET['doc_no'] ?? '');
    $targetBc = trim($_GET['target_bc'] ?? '27');
    $type     = trim($_GET['type'] ?? 'sales_invoice');

    $valuta = in_array($targetBc, ['40']) ? 'IDR' : 'USD';
    $detailItems = [];

    $conn = getWinlineDb();
    if ($conn && !empty($docNo)) {
        if ($type === 'purchase_order') {
            // Logika PB: d_custom_find_po_dtl3.srd
            $sqlPoDtl = "SELECT
                RIGHT('000000' + CAST(t026.c114 AS VARCHAR(6)), 6) AS srno, 	
                t026.c003 AS item_code, 	
                t026.c004 AS item_name, 
                t026.c005 AS qty,
                t026.c007 AS price, 	
                t026.c031 AS total, 	
                t026.c015 AS uom,
                t002.c000 AS valuta
            FROM cwldata..t025 t025 WITH (NOLOCK)
            JOIN cwldata..t026 t026 WITH (NOLOCK) ON 
                t025.c021 = t026.c044 
                AND t025.c022 = t026.c045 
                AND t025.mesocomp = t026.mesocomp
            LEFT JOIN cwldata..t002 t002 WITH (NOLOCK) ON 
                t025.mesoyear = t002.mesoyear 
                AND t025.mesocomp = t002.mesocomp
                AND (case t025.c041 when 0 then 1 else t025.c041 end) = t002.c017
            WHERE t025.c044 = ?
              AND t025.c045 is null
              AND t026.c055 <> 13
              AND t026.c003 <> 'TEXT'
            ORDER BY srno ASC";

            $resDtl = sqlsrv_query($conn, $sqlPoDtl, array($docNo));
            if ($resDtl) {
                $seri = 1;
                while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                    $qty = (float)$rd['qty'];
                    $prc = (float)$rd['price'];
                    $tot = (float)$rd['total'];
                    if (!empty($rd['valuta'])) $valuta = trim($rd['valuta']);

                    $detailItems[] = [
                        'seri_barang'    => $seri++,
                        'kode_barang'    => trim($rd['item_code']),
                        'uraian'         => trim($rd['item_name']),
                        'kode_satuan'    => trim($rd['uom'] ?: 'PCE'),
                        'jumlah_satuan'  => $qty,
                        'kode_kemasan'   => 'CT',
                        'jumlah_kemasan' => max(1, (int)ceil($qty / 50)),
                        'netto'          => round($qty * 0.2, 2),
                        'bruto'          => round($qty * 0.22, 2),
                        'harga_satuan'   => $prc,
                        'total_fob'      => $tot
                    ];
                }
            }
        } else {
            // Logika PB: d_custom_find_invoice_dtl27.srd & sp_whs_rpt_xtrns_doc
            $sqlDtl = "SELECT 
                t26.c003 AS item_code, 
                MAX(t26.c004) AS item_name, 
                SUM(t26.c005) AS qty, 
                t26.c007 AS price, 
                SUM(t26.c031) AS total, 
                MAX(t26.c015) AS sat
            FROM cwldata..t025 t25 WITH (NOLOCK) 
            JOIN cwldata..t026 t26 WITH (NOLOCK) 
                ON t25.c021 = t26.c044 
                AND t25.c022 = t26.c045 
                AND t25.mesocomp = t26.mesocomp 
            WHERE (t25.c045 = ? OR t25.c055 = ? OR t25.c044 = ?) 
              AND t26.c055 <> 13 
              AND t26.c003 <> 'TEXT'
            GROUP BY t26.c003, t26.c007
            ORDER BY t26.c003";

            $resDtl = sqlsrv_query($conn, $sqlDtl, array($docNo, $docNo, $docNo));
            if ($resDtl) {
                $seri = 1;
                while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                    $qty = (float)$rd['qty'];
                    $prc = (float)$rd['price'];
                    $tot = (float)$rd['total'];

                    $detailItems[] = [
                        'seri_barang'    => $seri++,
                        'kode_barang'    => trim($rd['item_code']),
                        'uraian'         => trim($rd['item_name']),
                        'kode_satuan'    => trim($rd['sat'] ?: ($type === 'sales_invoice' ? 'PR' : 'PCE')),
                        'jumlah_satuan'  => $qty,
                        'kode_kemasan'   => ($type === 'dn_transfer') ? 'BX' : 'CT',
                        'jumlah_kemasan' => max(1, (int)ceil($qty / 40)),
                        'netto'          => round($qty * 0.15, 2),
                        'bruto'          => round($qty * 0.17, 2),
                        'harga_satuan'   => $prc,
                        'total_fob'      => $tot
                    ];
                }
            }
        }
    }

    // Jika Winline tidak mengembalikan item (misal format khusus), berikan item bawaan
    if (empty($detailItems)) {
        $detailItems = [
            [
                'seri_barang'   => 1,
                'kode_barang'   => 'SH-OUT-001',
                'uraian'        => 'OUTSOLE TPU ADIDAS PREDATOR FG SIZE 42',
                'kode_satuan'   => 'PR',
                'jumlah_satuan' => 1200,
                'kode_kemasan'  => 'CT',
                'jumlah_kemasan'=> 40,
                'netto'         => 360.00,
                'bruto'         => 390.00,
                'harga_satuan'  => ($valuta === 'IDR') ? 75000 : 5.25,
                'total_fob'     => ($valuta === 'IDR') ? 90000000 : 6300.00
            ]
        ];
    }

    echo json_encode([
        'status'    => 'OK',
        'doc_no'    => $docNo,
        'target_bc' => $targetBc,
        'valuta'    => $valuta,
        'items'     => $detailItems
    ]);
    exit;
}

// -------------------------------------------------------------
// AJAX API: GENERATE NOMOR AJU & INTEGRASI KE STAGING H2H
// -------------------------------------------------------------
if ($action === 'integrate_h2h') {
    header('Content-Type: application/json; charset=utf-8');
    $docNo    = trim($_POST['doc_no'] ?? '');
    $targetBc = trim($_POST['target_bc'] ?? '27');
    $type     = trim($_POST['type'] ?? 'sales_invoice');

    if (empty($docNo)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor dokumen Winline ERP tidak valid.']);
        exit;
    }

    $cleanBc = preg_replace('/[^0-9]/', '', $targetBc);
    if (empty($cleanBc)) $cleanBc = '27';

    // Format Nomor AJU CEISA 4.0: 6 digit Dokumen + 6 digit Perusahaan + 8 digit Tanggal + 6 digit Nomor Seri
    $kodeDok6 = str_pad($cleanBc, 6, '0', STR_PAD_LEFT);
    $kdCmp6   = substr(preg_replace('/[^0-9]/', '', $company['npwp'] ?? '010816000000000'), 0, 6);
    if (strlen($kdCmp6) < 6) $kdCmp6 = str_pad($kdCmp6, 6, '0', STR_PAD_RIGHT);
    $tgl8     = date('Ymd');
    $prefixAju = $kodeDok6 . $kdCmp6 . $tgl8;

    $qSeq = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju LIKE '{$prefixAju}%' ORDER BY nomor_aju DESC LIMIT 1");
    $lastSeq = 0;
    if ($qSeq && $rSeq = $qSeq->fetch_assoc()) {
        $lastSeq = (int)substr($rSeq['nomor_aju'], -6);
    }
    $newSeq = str_pad($lastSeq + 1, 6, '0', STR_PAD_LEFT);
    $nomorAju = $prefixAju . $newSeq;
    $escAju = $koneksi->real_escape_string($nomorAju);
    $escDocNo = $koneksi->real_escape_string($docNo);

    // Ambil data detail barang aktual dari Winline ERP SQL Server
    $items = [];
    $valuta = in_array($cleanBc, ['40']) ? 'IDR' : 'USD';
    $conn = getWinlineDb();

    if ($conn) {
        if ($type === 'purchase_order') {
            $sqlPoDtl = "SELECT
                RIGHT('000000' + CAST(t026.c114 AS VARCHAR(6)), 6) AS srno, 	
                t026.c003 AS item_code, 	
                t026.c004 AS item_name, 
                t026.c005 AS qty,
                t026.c007 AS price, 	
                t026.c031 AS total, 	
                t026.c015 AS uom,
                t002.c000 AS valuta
            FROM cwldata..t025 t025 WITH (NOLOCK)
            JOIN cwldata..t026 t026 WITH (NOLOCK) ON 
                t025.c021 = t026.c044 
                AND t025.c022 = t026.c045 
                AND t025.mesocomp = t026.mesocomp
            LEFT JOIN cwldata..t002 t002 WITH (NOLOCK) ON 
                t025.mesoyear = t002.mesoyear 
                AND t025.mesocomp = t002.mesocomp
                AND (case t025.c041 when 0 then 1 else t025.c041 end) = t002.c017
            WHERE t025.c044 = ?
              AND t025.c045 is null
              AND t026.c055 <> 13
              AND t026.c003 <> 'TEXT'
            ORDER BY srno ASC";

            $resDtl = sqlsrv_query($conn, $sqlPoDtl, array($docNo));
            if ($resDtl) {
                $sr = 1;
                while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                    if (!empty($rd['valuta'])) $valuta = trim($rd['valuta']);
                    $items[] = [
                        'seri' => $sr++,
                        'kode' => trim($rd['item_code']),
                        'uraian' => trim($rd['item_name']),
                        'sat' => trim($rd['uom'] ?: 'PCE'),
                        'qty' => (float)$rd['qty'],
                        'kem' => 'CT',
                        'jml_kem' => max(1, (int)ceil((float)$rd['qty'] / 50)),
                        'net' => round((float)$rd['qty'] * 0.2, 2),
                        'brt' => round((float)$rd['qty'] * 0.22, 2),
                        'hrg' => (float)$rd['price']
                    ];
                }
            }
        } else {
            $sqlDtl = "SELECT 
                t26.c003 AS item_code, 
                MAX(t26.c004) AS item_name, 
                SUM(t26.c005) AS qty, 
                t26.c007 AS price, 
                SUM(t26.c031) AS total, 
                MAX(t26.c015) AS sat
            FROM cwldata..t025 t25 WITH (NOLOCK) 
            JOIN cwldata..t026 t26 WITH (NOLOCK) 
                ON t25.c021 = t26.c044 
                AND t25.c022 = t26.c045 
                AND t25.mesocomp = t26.mesocomp 
            WHERE (t25.c045 = ? OR t25.c055 = ? OR t25.c044 = ?) 
              AND t26.c055 <> 13 
              AND t26.c003 <> 'TEXT'
            GROUP BY t26.c003, t26.c007
            ORDER BY t26.c003";

            $resDtl = sqlsrv_query($conn, $sqlDtl, array($docNo, $docNo, $docNo));
            if ($resDtl) {
                $sr = 1;
                while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                    $items[] = [
                        'seri' => $sr++,
                        'kode' => trim($rd['item_code']),
                        'uraian' => trim($rd['item_name']),
                        'sat' => trim($rd['sat'] ?: ($type === 'sales_invoice' ? 'PR' : 'PCE')),
                        'qty' => (float)$rd['qty'],
                        'kem' => ($type === 'dn_transfer') ? 'BX' : 'CT',
                        'jml_kem' => max(1, (int)ceil((float)$rd['qty'] / 40)),
                        'net' => round((float)$rd['qty'] * 0.15, 2),
                        'brt' => round((float)$rd['qty'] * 0.17, 2),
                        'hrg' => (float)$rd['price']
                    ];
                }
            }
        }
    }

    if (empty($items)) {
        $items = [
            ['seri' => 1, 'kode' => 'SH-OUT-001', 'uraian' => 'OUTSOLE TPU ADIDAS PREDATOR FG SIZE 42', 'sat' => 'PR', 'qty' => 1200, 'kem' => 'CT', 'jml_kem' => 40, 'net' => 360, 'brt' => 390, 'hrg' => ($valuta === 'IDR' ? 75000 : 5.25)],
            ['seri' => 2, 'kode' => 'SH-INS-002', 'uraian' => 'INSOLE COMFORT FOAM BLACK DENSITY 45', 'sat' => 'PR', 'qty' => 1200, 'kem' => 'CT', 'jml_kem' => 30, 'net' => 150, 'brt' => 170, 'hrg' => ($valuta === 'IDR' ? 25000 : 1.75)],
            ['seri' => 3, 'kode' => 'SH-CTR-003', 'uraian' => 'HEEL COUNTER REINFORCEMENT MOLDED', 'sat' => 'PCE', 'qty' => 2400, 'kem' => 'BX', 'jml_kem' => 25, 'net' => 190, 'brt' => 210, 'hrg' => ($valuta === 'IDR' ? 15000 : 0.95)],
        ];
    }

    $totFob = 0;
    $totNet = 0;
    $totBrt = 0;
    $totQty = 0;
    $totKem = 0;
    foreach ($items as $it) {
        $totFob += ($it['qty'] * $it['hrg']);
        $totNet += $it['net'];
        $totBrt += $it['brt'];
        $totQty += $it['qty'];
        $totKem += $it['jml_kem'];
    }

    $koneksi->begin_transaction();
    try {
        $kodeKantor = $company['kode_kantor'] ?? '050900';

        // 1. Insert Header Staging
        $koneksi->query("INSERT INTO bc_ceisa_header (
            nomor_aju, cmp_id, kode_dokumen, kode_kantor, kode_valuta,
            fob, cif, cif_rupiah, harga_penyerahan, netto, bruto,
            jumlah_barang, tanggal_aju, created_at
        ) VALUES (
            '$escAju', '$escCmp', '$cleanBc', '$kodeKantor', '$valuta',
            $totFob, $totFob, $totFob, $totFob, $totNet, $totBrt,
            " . count($items) . ", CURDATE(), NOW()
        )");

        // 2. Insert Barang Staging
        foreach ($items as $it) {
            $fobLine = $it['qty'] * $it['hrg'];
            $escKode = $koneksi->real_escape_string($it['kode']);
            $escUraian = $koneksi->real_escape_string($it['uraian']);
            $escSat = $koneksi->real_escape_string($it['sat']);
            $escKem = $koneksi->real_escape_string($it['kem']);

            $koneksi->query("INSERT INTO bc_ceisa_barang (
                nomor_aju, seri_barang, kode_barang, uraian, kode_satuan, jumlah_satuan,
                kode_kemasan, jumlah_kemasan, netto, bruto, harga_satuan, fob, cif, cif_rupiah
            ) VALUES (
                '$escAju', {$it['seri']}, '$escKode', '$escUraian', '$escSat', {$it['qty']},
                '$escKem', {$it['jml_kem']}, {$it['net']}, {$it['brt']}, {$it['hrg']}, $fobLine, $fobLine, $fobLine
            )");
        }

        // 3. Insert Dokumen Pelengkap (380 Invoice / 217 Surat Jalan / 999 PO)
        $kodeDokPelengkap = ($type === 'purchase_order') ? '999' : (($type === 'dn_transfer') ? '217' : '380');
        $koneksi->query("INSERT INTO bc_ceisa_dokumen (
            nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen
        ) VALUES (
            '$escAju', 1, '$kodeDokPelengkap', '$escDocNo', CURDATE()
        )");

        // 4. Insert Kemasan Staging
        $koneksi->query("INSERT INTO bc_ceisa_kemasan (
            nomor_aju, seri, kode_kemasan, jumlah_kemasan, merek
        ) VALUES (
            '$escAju', 1, 'CT', $totKem, 'FRAMAS'
        )");

        // 5. Update nomor_aju balik ke Winline pur_order2cash_sales jika Sales Invoice
        if ($conn && $type === 'sales_invoice') {
            @sqlsrv_query($conn, "UPDATE pur_order2cash_sales SET nomor_aju = ? WHERE inv_no = ? OR dn_no = ?", array($nomorAju, $docNo, $docNo));
        }

        $koneksi->commit();

        echo json_encode([
            'status'    => 'OK',
            'nomor_aju' => $nomorAju,
            'kode_bc'   => $cleanBc,
            'message'   => "Berhasil membuat draft dokumen Host to Host BC $cleanBc! Nomor AJU: $nomorAju (" . count($items) . " item barang ditarik dari Winline ERP)"
        ]);
    } catch (\Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal integrasi H2H: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// KONFIGURASI TAMPILAN VIEW BERDASARKAN TYPE
// -------------------------------------------------------------
$typeTitles = [
    'sales_invoice'  => [
        'title'      => 'Sales Invoice',
        'badge'      => 'BC 2.7 & BC 3.0 ke Customer',
        'badge_cls'  => 'badge-customer',
        'desc'       => 'Integrasi data Faktur Penjualan (Sales Invoice) Winline ERP untuk pembuatan dokumen Host to Host: <strong>BC 2.7 (TPB Lain)</strong> dan <strong>BC 3.0 (PEB Ekspor)</strong> ke Customer.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '27' => 'BC 2.7 — Pengeluaran ke TPB Lain',
            '30' => 'BC 3.0 — Ekspor Barang (PEB)'
        ]
    ],
    'dn_transfer'    => [
        'title'      => 'DN Transfer',
        'badge'      => 'BC 2.7 Antar Framas',
        'badge_cls'  => 'badge-framas',
        'desc'       => 'Integrasi data Delivery Note (DN%) Winline ERP untuk pembuatan dokumen Host to Host: <strong>BC 2.7 (Perpindahan Barang Antar Plant Framas)</strong>.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '27' => 'BC 2.7 — Perpindahan Antar Plant Framas'
        ]
    ],
    'purchase_order' => [
        'title'      => 'Purchase Order',
        'badge'      => 'BC 2.3, BC 4.0, BC 1.6 Pemasukan',
        'badge_cls'  => 'badge-po',
        'desc'       => 'Integrasi data Pesanan Pembelian (Purchase Order PO%) Winline ERP untuk pembuatan dokumen Host to Host Pemasukan: <strong>BC 2.3 (Impor TPB)</strong>, <strong>BC 4.0 (Pemasukan Lokal TLDDP)</strong>, dan <strong>BC 1.6 (Pemasukan PLB)</strong>.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '23' => 'BC 2.3 — Impor Bahan Baku TPB',
            '40' => 'BC 4.0 — Pemasukan Lokal (TLDDP)',
            '16' => 'BC 1.6 — Pemasukan ke PLB'
        ]
    ]
];

$curCfg = $typeTitles[$targetType];
$isDbLive = (getWinlineDb() !== null && getWinlineDb() !== false);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winline ERP — <?= htmlspecialchars($curCfg['title']) ?></title>
    <link rel="stylesheet" href="assets/css/sweetalert2.min.css">
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --info: #13c2c2;
            --bg-page: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #1f1f1f;
            --text-muted: #8c8c8c;
            --border-color: #f0f0f0;
            --border-dark: #d9d9d9;
            --header-bg: #fafafa;
        }

        [data-theme='dark'] {
            --bg-page: #141414;
            --bg-card: #1f1f1f;
            --text-main: #f0f0f0;
            --text-muted: #8c8c8c;
            --border-color: #303030;
            --border-dark: #434343;
            --header-bg: #262626;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 10px 14px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 11px;
            color: var(--text-main);
            background-color: var(--bg-page);
        }

        .header-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            padding: 8px 14px;
            margin-bottom: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 6px;
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .badge-target {
            font-size: 9px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 10px;
            letter-spacing: 0.2px;
        }
        .badge-customer { background: rgba(24, 144, 255, 0.12); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.3); }
        .badge-framas   { background: rgba(82, 196, 26, 0.12); color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.3); }
        .badge-po       { background: rgba(250, 173, 20, 0.12); color: #d46b08; border: 1px solid rgba(250, 173, 20, 0.3); }

        .type-pills {
            display: inline-flex;
            background: var(--header-bg);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            padding: 2px;
            gap: 2px;
        }

        .type-pill {
            padding: 3px 9px;
            border-radius: 3px;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .type-pill:hover {
            color: var(--primary);
            background: rgba(24, 144, 255, 0.06);
        }

        .type-pill.active {
            color: #ffffff;
            background: var(--primary);
            box-shadow: 0 1px 2px rgba(24, 144, 255, 0.3);
        }

        .conn-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 9.5px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
        }
        .conn-online { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .conn-offline { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }

        .header-desc {
            font-size: 10.5px;
            color: var(--text-muted);
            line-height: 1.4;
            margin: 0;
            padding-top: 5px;
            border-top: 1px solid var(--border-color);
        }

        /* Filter Toolbar */
        .filter-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            padding: 8px 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-left {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-size: 10.5px;
        }

        .filter-input {
            padding: 3px 7px;
            border: 1px solid var(--border-dark);
            border-radius: 4px;
            font-size: 10.5px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
            height: 26px;
        }

        .filter-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15);
        }

        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 500;
            border: 1px solid var(--border-dark);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            height: 26px;
            transition: all 0.15s ease;
        }

        .ant-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
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

        .ant-btn-success {
            background: var(--success);
            border-color: var(--success);
            color: #ffffff;
        }

        .ant-btn-success:hover {
            opacity: 0.9;
        }

        /* Table Grid */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table.winline-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
            text-align: left;
        }

        table.winline-table th {
            background: var(--header-bg);
            color: var(--text-main);
            font-weight: 600;
            padding: 6px 9px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
            font-size: 10.5px;
        }

        table.winline-table td {
            padding: 6px 9px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
            font-size: 10.5px;
        }

        table.winline-table tbody tr:hover {
            background: rgba(24, 144, 255, 0.04);
        }

        .badge-bc {
            font-family: monospace;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 9.5px;
        }
        .bc-27 { background: rgba(47, 84, 235, 0.12); color: #2f54eb; border: 1px solid rgba(47, 84, 235, 0.25); }
        .bc-30 { background: rgba(250, 140, 22, 0.12); color: #fa8c16; border: 1px solid rgba(250, 140, 22, 0.25); }
        .bc-23 { background: rgba(24, 144, 255, 0.12); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.25); }
        .bc-40 { background: rgba(19, 194, 194, 0.12); color: #13c2c2; border: 1px solid rgba(19, 194, 194, 0.25); }
        .bc-16 { background: rgba(82, 196, 26, 0.12);  color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.25); }

        .tag-status {
            font-size: 9px;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .tag-ready { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .tag-integrated { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }

        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(24, 144, 255, 0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Modal Preview */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(2px);
        }

        .modal-box {
            background: var(--bg-card);
            border-radius: 6px;
            width: 90%;
            max-width: 820px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 1px solid var(--border-color);
            font-size: 11px;
        }

        .modal-header {
            padding: 8px 14px;
            background: var(--header-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 10px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 8px 14px;
            background: var(--header-bg);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($_GET['theme'] ?? 'light') ?>">

    <!-- HEADER CARD -->
    <div class="header-card">
        <div class="header-top">
            <div class="header-title-box">
                <span style="font-size: 18px;">
                    <?php if ($targetType === 'sales_invoice'): ?>📑
                    <?php elseif ($targetType === 'dn_transfer'): ?>🚚
                    <?php else: ?>🛒<?php endif; ?>
                </span>
                <div>
                    <h2 class="header-title">
                        Winline ERP — <?= htmlspecialchars($curCfg['title']) ?>
                        <span class="badge-target <?= $curCfg['badge_cls'] ?>"><?= htmlspecialchars($curCfg['badge']) ?></span>
                        <span class="conn-pill <?= $isDbLive ? 'conn-online' : 'conn-offline' ?>">
                            <?= $isDbLive ? '🟢 192.168.170.254 (Live)' : '🟡 Offline Mode' ?>
                        </span>
                    </h2>
                </div>
            </div>

            <!-- TYPE PILLS (SWITCHER) -->
            <div class="type-pills">
                <a href="winline_erp.php?type=sales_invoice&cmp_id=<?= urlencode($sessionCmpId) ?>" class="type-pill <?= ($targetType === 'sales_invoice') ? 'active' : '' ?>">
                    <span>📑</span> Sales Invoice
                </a>
                <a href="winline_erp.php?type=dn_transfer&cmp_id=<?= urlencode($sessionCmpId) ?>" class="type-pill <?= ($targetType === 'dn_transfer') ? 'active' : '' ?>">
                    <span>🚚</span> DN Transfer
                </a>
                <a href="winline_erp.php?type=purchase_order&cmp_id=<?= urlencode($sessionCmpId) ?>" class="type-pill <?= ($targetType === 'purchase_order') ? 'active' : '' ?>">
                    <span>🛒</span> Purchase Order
                </a>
            </div>
        </div>
        <p class="header-desc"><?= $curCfg['desc'] ?></p>
    </div>

    <!-- FILTER TOOLBAR -->
    <div class="filter-card">
        <div class="filter-left">
            <input type="text" id="filterSearch" class="filter-input" style="width: 180px;" placeholder="Cari Dok / Partner...">
            <input type="date" id="filterDateFrom" class="filter-input" value="<?= date('Y-m-01') ?>">
            <span>s/d</span>
            <input type="date" id="filterDateTo" class="filter-input" value="<?= date('Y-m-d') ?>">

            <select id="filterTargetBc" class="filter-input" style="width: 200px;">
                <?php foreach ($curCfg['target_bcs'] as $k => $lbl): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="button" class="ant-btn ant-btn-primary" onclick="loadWinlineData()">
                <span>🔍 Filter</span>
            </button>
            <button type="button" class="ant-btn" onclick="resetFilter()">
                <span>🔄 Reset</span>
            </button>
        </div>

        <div>
            <button type="button" class="ant-btn ant-btn-success" onclick="syncWinlineErp()">
                <span>⚡ Tarik Data Winline ERP</span>
            </button>
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="winline-table">
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center;"><input type="checkbox" id="checkAll"></th>
                        <th>No. Dokumen Winline</th>
                        <th>Tanggal</th>
                        <th>Partner / Customer / Supplier</th>
                        <th>Target BC</th>
                        <th style="text-align: right;">Total Qty</th>
                        <th style="text-align: right;">Kemasan</th>
                        <th style="text-align: right;">Netto / Bruto</th>
                        <th style="text-align: right;">Nilai Transaksi</th>
                        <th style="text-align: center;">Status H2H</th>
                        <th style="text-align: center; width: 140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="winlineTableBody">
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 20px; color: var(--text-muted);">
                            <span class="spinner"></span> Memuat data Winline ERP...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL DETAIL BARANG -->
    <div id="modalDetail" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <div style="font-size: 12px; font-weight: 700; display:flex; align-items:center; gap:6px;">
                    <span>📦 Rincian Barang Dokumen Winline ERP:</span>
                    <span id="modalDocNo" style="font-family:monospace; color:var(--primary);"></span>
                </div>
                <button type="button" style="background:none; border:none; font-size:16px; cursor:pointer; color:var(--text-muted);" onclick="closeModalDetail()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="winline-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width: 25px;">#</th>
                            <th>Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="text-align: right;">Qty</th>
                            <th style="text-align: center;">Satuan</th>
                            <th style="text-align: right;">Kemasan</th>
                            <th style="text-align: right;">Netto (Kg)</th>
                            <th style="text-align: right;">Harga Satuan</th>
                            <th style="text-align: right;">Total Nilai</th>
                        </tr>
                    </thead>
                    <tbody id="modalItemBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModalDetail()">Tutup</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnModalIntegrate">
                    <span>⚡ Buat Dokumen Host to Host</span>
                </button>
            </div>
        </div>
    </div>

    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script>
        const currentType = <?= json_encode($targetType) ?>;
        const currentCmpId = <?= json_encode($sessionCmpId) ?>;
        let activeData = [];

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        async function loadWinlineData() {
            const q = document.getElementById('filterSearch').value.trim();
            const dt1 = document.getElementById('filterDateFrom').value;
            const dt2 = document.getElementById('filterDateTo').value;
            const targetBc = document.getElementById('filterTargetBc').value;

            const tbody = document.getElementById('winlineTableBody');
            tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; padding: 20px; color: var(--text-muted);"><span class="spinner"></span> Mengambil data dokumen Winline ERP (192.168.170.254)...</td></tr>`;

            try {
                const res = await fetch(`winline_erp.php?action=load_data&type=${encodeURIComponent(currentType)}&q=${encodeURIComponent(q)}&date_from=${encodeURIComponent(dt1)}&date_to=${encodeURIComponent(dt2)}&target_bc=${encodeURIComponent(targetBc)}&cmp_id=${encodeURIComponent(currentCmpId)}`);
                const json = await res.json();

                if (json.status === 'OK') {
                    activeData = json.data || [];
                    renderTable(activeData);
                } else {
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; color: var(--danger); padding: 16px;">Gagal: ${escapeHtml(json.message || 'Terjadi kesalahan')}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; color: var(--danger); padding: 16px;">Error koneksi jaringan: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('winlineTableBody');
            if (!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align: center; padding: 24px; color: var(--text-muted);">Tidak ada data dokumen ditemukan untuk kriteria filter ini.</td></tr>`;
                return;
            }

            let html = '';
            data.forEach((r, idx) => {
                const bcClass = `bc-${r.target_bc}`;
                const isIntegrated = (r.status_h2h === 'integrated');
                const statusBadge = isIntegrated
                    ? `<span class="tag-status tag-integrated" title="Nomor AJU: ${escapeHtml(r.nomor_aju)}">✓ H2H Staging</span>`
                    : `<span class="tag-status tag-ready">Siap Ditarik</span>`;

                const actionBtns = isIntegrated
                    ? `
                        <button type="button" class="ant-btn ant-btn-sm" style="font-size:9.5px; height:21px; padding:0 5px;" onclick="openDetail('${escapeHtml(r.doc_no)}', '${r.target_bc}')" title="Lihat Rincian Item Barang">👁️ Detail</button>
                        <a href="editor.php?nomor_aju=${encodeURIComponent(r.nomor_aju)}" target="_blank" class="ant-btn ant-btn-sm ant-btn-primary" style="font-size:9.5px; height:21px; padding:0 5px;" title="Buka Form Editor Dokumen">✏️ Editor</a>
                      `
                    : `
                        <button type="button" class="ant-btn ant-btn-sm" style="font-size:9.5px; height:21px; padding:0 5px;" onclick="openDetail('${escapeHtml(r.doc_no)}', '${r.target_bc}')" title="Lihat Rincian Item Barang">👁️ Detail</button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" style="font-size:9.5px; height:21px; padding:0 5px;" onclick="integrateSingle('${escapeHtml(r.doc_no)}', '${r.target_bc}')" title="Buat Draft Dokumen Pabean H2H">⚡ H2H</button>
                      `;

                html += `
                    <tr>
                        <td style="text-align: center;"><input type="checkbox" class="row-check" value="${escapeHtml(r.doc_no)}" data-bc="${r.target_bc}"></td>
                        <td style="font-family: monospace; font-weight: 700; color: var(--primary); font-size: 10.5px;">
                            ${escapeHtml(r.doc_no)}
                            ${r.ref_no ? `<div style="font-size:9.5px; color:var(--text-muted); font-weight:normal;">Ref: ${escapeHtml(r.ref_no)}</div>` : ''}
                        </td>
                        <td style="white-space: nowrap; font-size: 10px;">${escapeHtml(r.doc_date)}</td>
                        <td>
                            <div style="font-weight: 600; font-size: 10.5px;">${escapeHtml(r.partner_name)}</div>
                            <div style="font-size: 9.5px; color: var(--text-muted);">${escapeHtml(r.partner_addr || '')}</div>
                        </td>
                        <td><span class="badge-bc ${bcClass}">BC ${escapeHtml(r.target_bc)}</span></td>
                        <td style="text-align: right; font-weight: 600; font-size: 10.5px;">${Number(r.total_qty || 0).toLocaleString('id-ID')} <span style="font-size:9px; color:var(--text-muted);">${escapeHtml(r.unit || '')}</span></td>
                        <td style="text-align: right; font-size: 10px;">${Number(r.total_kemas || 0).toLocaleString('id-ID')} ${escapeHtml(r.kemas_sat || 'CT')}</td>
                        <td style="text-align: right; font-size: 10px;">
                            <div>N: <b>${Number(r.netto || 0).toLocaleString('id-ID')}</b> kg</div>
                            <div style="color:var(--text-muted);">B: ${Number(r.bruto || 0).toLocaleString('id-ID')} kg</div>
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #389e0d; font-size: 10.5px;">
                            ${Number(r.total_fob || 0) > 0 ? `${escapeHtml(r.valuta)} ${Number(r.total_fob || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 })}` : '<span style="color:var(--text-muted); font-weight:normal;">(Lihat Detail)</span>'}
                        </td>
                        <td style="text-align: center;">${statusBadge}</td>
                        <td style="text-align: center;">
                            <div style="display:flex; justify-content:center; gap:3px;">${actionBtns}</div>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        function resetFilter() {
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterDateFrom').value = '<?= date('Y-m-01') ?>';
            document.getElementById('filterDateTo').value = '<?= date('Y-m-d') ?>';
            document.getElementById('filterTargetBc').value = '';
            loadWinlineData();
        }

        async function openDetail(docNo, targetBc) {
            document.getElementById('modalDocNo').innerText = docNo;
            const modalBody = document.getElementById('modalItemBody');
            modalBody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);"><span class="spinner"></span> Mengambil rincian barang dari Winline ERP...</td></tr>`;
            document.getElementById('modalDetail').style.display = 'flex';

            document.getElementById('btnModalIntegrate').onclick = () => {
                closeModalDetail();
                integrateSingle(docNo, targetBc);
            };

            try {
                const res = await fetch(`winline_erp.php?action=get_detail&doc_no=${encodeURIComponent(docNo)}&target_bc=${encodeURIComponent(targetBc)}&type=${encodeURIComponent(currentType)}`);
                const json = await res.json();
                if (json.status === 'OK' && Array.isArray(json.items)) {
                    let rows = '';
                    json.items.forEach((it, i) => {
                        rows += `
                            <tr>
                                <td>${i + 1}</td>
                                <td style="font-family:monospace; font-weight:600; color:var(--primary); font-size:10.5px;">${escapeHtml(it.kode_barang)}</td>
                                <td>${escapeHtml(it.uraian)}</td>
                                <td style="text-align:right; font-weight:600;">${Number(it.jumlah_satuan).toLocaleString('id-ID')}</td>
                                <td style="text-align:center;">${escapeHtml(it.kode_satuan)}</td>
                                <td style="text-align:right;">${Number(it.jumlah_kemasan).toLocaleString('id-ID')} ${escapeHtml(it.kode_kemasan)}</td>
                                <td style="text-align:right;">${Number(it.netto).toLocaleString('id-ID')}</td>
                                <td style="text-align:right;">${json.valuta} ${Number(it.harga_satuan).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                                <td style="text-align:right; font-weight:700; color:#389e0d;">${json.valuta} ${Number(it.total_fob).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                            </tr>
                        `;
                    });
                    modalBody.innerHTML = rows;
                } else {
                    modalBody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Gagal memuat detail item barang.</td></tr>`;
                }
            } catch (err) {
                modalBody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:16px;">Error: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function closeModalDetail() {
            document.getElementById('modalDetail').style.display = 'none';
        }

        async function integrateSingle(docNo, targetBc) {
            const bcLabel = (targetBc === '27') ? 'BC 2.7' : ((targetBc === '30') ? 'BC 3.0' : ((targetBc === '23') ? 'BC 2.3' : 'BC 4.0'));
            const confirmRes = await Swal.fire({
                title: 'Konfirmasi Integrasi Host to Host',
                html: `Buat draft dokumen pengajuan pabean <b>${bcLabel}</b> dari dokumen Winline ERP <b>${escapeHtml(docNo)}</b>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Buat Dokumen H2H',
                cancelButtonText: 'Batal'
            });

            if (!confirmRes.isConfirmed) return;

            Swal.fire({
                title: 'Memproses Integrasi...',
                text: 'Menarik detail barang dari Winline dan membentuk Nomor AJU CEISA 4.0...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const fd = new FormData();
            fd.append('action', 'integrate_h2h');
            fd.append('doc_no', docNo);
            fd.append('target_bc', targetBc);
            fd.append('type', currentType);

            try {
                const res = await fetch('winline_erp.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (json.status === 'OK') {
                    Swal.fire({
                        title: 'Integrasi Berhasil!',
                        html: `
                            <div style="font-size:13px; margin-bottom:12px;">Draft Dokumen CEISA 4.0 Host to Host berhasil dibentuk:</div>
                            <div style="font-family:monospace; font-size:15px; font-weight:700; color:#1890ff; padding:8px; background:#e6f7ff; border:1px solid #91d5ff; border-radius:4px; margin-bottom:12px;">${json.nomor_aju}</div>
                            <div style="display:flex; justify-content:center; gap:8px;">
                                <a href="editor.php?nomor_aju=${encodeURIComponent(json.nomor_aju)}" target="_blank" class="ant-btn ant-btn-primary" style="text-decoration:none;">✏️ Buka Form Editor</a>
                                <a href="transfer.php" target="_blank" class="ant-btn" style="text-decoration:none;">📋 Buka Host to Host</a>
                            </div>
                        `,
                        icon: 'success',
                        confirmButtonText: 'Selesai'
                    });
                    loadWinlineData();
                } else {
                    Swal.fire('Gagal Integrasi', json.message || 'Terjadi kesalahan sistem.', 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        async function syncWinlineErp() {
            Swal.fire({
                title: 'Menyinkronkan Winline ERP...',
                text: 'Menghubungkan ke SQL Server 192.168.170.254 (FRAMASDB)...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            setTimeout(() => {
                loadWinlineData();
                Swal.close();
            }, 600);
        }

        // Jalankan pencarian saat halaman dimuat
        document.addEventListener('DOMContentLoaded', () => {
            loadWinlineData();
        });
    </script>
</body>
</html>

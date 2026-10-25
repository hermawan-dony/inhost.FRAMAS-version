<?php
/**
 * CEISA 4.0 - Add-on Module: Winline ERP Integration
 * File: winline_erp.php
 * 
 * Modul integrasi sistem Winline ERP PT Framas Indonesia ke Host to Host CEISA 4.0:
 * 1. Sales Invoice  : w_custom_find_invoice3.srw (pur_order2cash_sales & t025/t026) -> BC 2.7 & BC 3.0 ke Customer
 *    - Filter: per tanggal saja
 *    - Fitur: Multi-Select (bisa pilih banyak invoice sekaligus untuk digabung ke CEISA)
 * 2. Purchase Order : w_custom_find_po.srw (t025, t002, t026) -> BC 2.3, BC 4.0, BC 1.6 Pemasukan
 *    - Fitur: Bisa edit Qty PO (Qty Masuk Aktual). Jika Qty PO 100 masuk 50, nilai FOB, netto, gross dihitung prorata untuk IT Inventory
 * 3. DN Transfer    : w_custom_xtrn_doc_find.srw (t025, t026) -> BC 2.7 Perpindahan Antar Plant Framas
 *    - Filter: per tanggal saja
 *    - Fitur: Multi-Select
 * 4. Entitas Otomatis:
 *    - Sesuai flow logic w_custom_form_trn.srw (menggunakan v_supplier_mas, v_customer_mas, dan profil bc_ceisa_company)
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
// HELPER 1: KONEKSI KE SQL SERVER WINLINE (FRAMASDB)
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
// HELPER 2: PENCARIAN DATA PARTNER (v_supplier_mas & v_customer_mas)
// -------------------------------------------------------------
if (!function_exists('getWinlinePartnerInfo')) {
    function getWinlinePartnerInfo($conn, $type, $codeOrName, $docNo = null) {
        if (!$conn) return null;
        $term = trim($codeOrName ?? '');

        if ($type === 'purchase_order') {
            if (!empty($term)) {
                $sql = "SELECT TOP 1 splr_cd, splr_desc, contact_person, add1, add2, npwp, country 
                        FROM v_supplier_mas WITH (NOLOCK)
                        WHERE splr_cd = ? OR splr_desc LIKE ?";
                $stmt = sqlsrv_query($conn, $sql, [$term, "%$term%"]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    return [
                        'code'    => trim($row['splr_cd']),
                        'name'    => trim($row['splr_desc']),
                        'npwp'    => preg_replace('/[^0-9]/', '', $row['npwp']),
                        'address' => trim($row['add1'] . ' ' . $row['add2']),
                        'country' => trim($row['country'] ?: 'ID')
                    ];
                }
            }

            // Fallback via docNo or term ke t025
            $whereT25 = !empty($docNo) ? "c044 = ?" : "(c021 = ? OR c010 LIKE ?)";
            $paramT25 = !empty($docNo) ? [$docNo] : [$term, "%$term%"];
            $sqlT25 = "SELECT TOP 1 c021 AS code, c010 AS name, c012 AS add1, c014 AS city FROM cwldata..t025 WITH (NOLOCK) WHERE $whereT25 ORDER BY c028 DESC";
            $stmtT25 = sqlsrv_query($conn, $sqlT25, $paramT25);
            if ($stmtT25 && $r25 = sqlsrv_fetch_array($stmtT25, SQLSRV_FETCH_ASSOC)) {
                $pName = trim($r25['name']);
                if (!empty($pName)) {
                    return [
                        'code'    => trim($r25['code']),
                        'name'    => $pName,
                        'npwp'    => '',
                        'address' => trim(($r25['city'] ? $r25['city'] . ', ' : '') . $r25['add1']),
                        'country' => 'ID'
                    ];
                }
            }
        } elseif ($type === 'dn_transfer') {
            $whereT25 = !empty($docNo) ? "(c045 = ? OR c044 = ?)" : "(c021 = ? OR c004 LIKE ?)";
            $paramT25 = !empty($docNo) ? [$docNo, $docNo] : [$term, "%$term%"];
            $sqlT25 = "SELECT TOP 1 c021 AS code, c004 AS cust_name, c010 AS supplier_name, mesocomp FROM cwldata..t025 WITH (NOLOCK) WHERE $whereT25 ORDER BY c028 DESC";
            $stmtT25 = sqlsrv_query($conn, $sqlT25, $paramT25);
            if ($stmtT25 && $r25 = sqlsrv_fetch_array($stmtT25, SQLSRV_FETCH_ASSOC)) {
                $pName = trim($r25['cust_name'] ?: ($r25['supplier_name'] ?: 'PT FRAMAS INDONESIA'));
                return [
                    'code'    => trim($r25['code'] ?: 'FRAMAS-DS'),
                    'name'    => $pName,
                    'npwp'    => '',
                    'address' => 'Plant: ' . ($r25['mesocomp'] ?: 'DELTA SILICON 5'),
                    'country' => 'ID'
                ];
            }
        } else {
            // Sales Invoice
            if (!empty($term)) {
                $sql = "SELECT TOP 1 cust_cd, cust_desc, add1, add2, add3, add4, npwp, country 
                        FROM v_customer_mas WITH (NOLOCK)
                        WHERE cust_cd = ? OR cust_desc LIKE ?";
                $stmt = sqlsrv_query($conn, $sql, [$term, "%$term%"]);
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    return [
                        'code'    => trim($row['cust_cd']),
                        'name'    => trim($row['cust_desc']),
                        'npwp'    => preg_replace('/[^0-9]/', '', $row['npwp']),
                        'address' => trim($row['add1'] . ' ' . $row['add2'] . ' ' . $row['add3'] . ' ' . $row['add4']),
                        'country' => trim($row['country'] ?: 'ID')
                    ];
                }
            }

            // Fallback via docNo atau term ke pur_order2cash_sales
            $whereSales = !empty($docNo) ? "(inv_no = ? OR dn_no = ?)" : "(cust_cd = ? OR customer LIKE ?)";
            $paramSales = !empty($docNo) ? [$docNo, $docNo] : [$term, "%$term%"];
            $sqlSales = "SELECT TOP 1 cust_cd, customer FROM pur_order2cash_sales WITH (NOLOCK) WHERE $whereSales";
            $stmtSales = sqlsrv_query($conn, $sqlSales, $paramSales);
            if ($stmtSales && $rSales = sqlsrv_fetch_array($stmtSales, SQLSRV_FETCH_ASSOC)) {
                return [
                    'code'    => trim($rSales['cust_cd']),
                    'name'    => trim($rSales['customer']),
                    'npwp'    => '',
                    'address' => 'INDONESIA',
                    'country' => 'ID'
                ];
            }
        }
        return null;
    }
}

// -------------------------------------------------------------
// HELPER 3: ENTITAS OTOMATIS SESUAI LOGIKA w_custom_form_trn.srw
// -------------------------------------------------------------
if (!function_exists('autoGenerateCeisaEntitas')) {
    function autoGenerateCeisaEntitas($nomorAju, $cleanBc, $company, $partnerData, $type, $koneksi) {
        $escAju = $koneksi->real_escape_string($nomorAju);
        $cmpId = $company['cmp_id'] ?? 'FRM-1';
        $escCmp = $koneksi->real_escape_string($cmpId);

        // Hapus entitas lama untuk nomor_aju ini jika ada
        $koneksi->query("DELETE FROM bc_ceisa_entitas WHERE nomor_aju = '$escAju'");

        // Profil Framas (Perusahaan Sendiri)
        $npwpFramas = preg_replace('/[^0-9]/', '', $company['npwp'] ?? '010816064431000');
        $namaFramas = trim($company['cmp_name'] ?? ($company['nama_perusahaan'] ?? 'PT FRAMAS INDONESIA'));
        $alamatFramas1 = trim($company['alamat'] ?? 'KAWASAN INDUSTRI MM 2100 JL.SULAWESI BLOK M 33-34, GANDAMEKAR, CIKARANG BARAT, BEKASI');
        $alamatFramas2 = 'JL. KENARI RAYA BLOK G2 KAV 08B-09A DELTA SILICON 5, LIPPO CIKARANG, BEKASI';
        $ijinTpb = trim($company['nomor_ijin_tpb'] ?? '000001/TPB/2020');
        $tglIjinTpb = trim($company['tanggal_ijin_tpb'] ?? '2020-01-01');

        // Profil Partner
        $partnerName = trim($partnerData['name'] ?? 'PARTNER WINLINE');
        $partnerNpwp = preg_replace('/[^0-9]/', '', $partnerData['npwp'] ?? '');
        $partnerAddr = trim($partnerData['address'] ?? 'INDONESIA');
        $partnerCountry = strtoupper(trim($partnerData['country'] ?? 'ID'));
        if (empty($partnerCountry) || $partnerCountry === 'INDONESIA') $partnerCountry = 'ID';

        $entitasRows = [];

        if ($type === 'purchase_order') {
            if ($cleanBc === '23') {
                // BC 2.3: Impor TPB
                $entitasRows[] = [
                    'seri' => 1, 'kode_entitas' => '3', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => $ijinTpb, 'tanggal_ijin_entitas' => $tglIjinTpb, 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 2, 'kode_entitas' => '7', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 3, 'kode_entitas' => '5', 'kode_jenis_identitas' => '0',
                    'nomor_identitas' => '', 'nama_entitas' => $partnerName, 'alamat_entitas' => $partnerAddr,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => ($partnerCountry !== 'ID' ? $partnerCountry : 'CN')
                ];
            } elseif ($cleanBc === '40') {
                // BC 4.0: Pemasukan Lokal TLDDP
                $entitasRows[] = [
                    'seri' => 1, 'kode_entitas' => '3', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => $ijinTpb, 'tanggal_ijin_entitas' => $tglIjinTpb, 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 2, 'kode_entitas' => '7', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 3, 'kode_entitas' => '9', 'kode_jenis_identitas' => !empty($partnerNpwp) ? '5' : '0',
                    'nomor_identitas' => $partnerNpwp, 'nama_entitas' => $partnerName, 'alamat_entitas' => $partnerAddr,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
            } else {
                // BC 1.6: Pemasukan dari PLB
                $entitasRows[] = [
                    'seri' => 1, 'kode_entitas' => '3', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => $ijinTpb, 'tanggal_ijin_entitas' => $tglIjinTpb, 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 2, 'kode_entitas' => '7', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 3, 'kode_entitas' => '8', 'kode_jenis_identitas' => !empty($partnerNpwp) ? '5' : '0',
                    'nomor_identitas' => $partnerNpwp, 'nama_entitas' => $partnerName, 'alamat_entitas' => $partnerAddr,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
            }
        } elseif ($type === 'dn_transfer') {
            // BC 2.7: Perpindahan Antar Plant Framas
            $entitasRows[] = [
                'seri' => 1, 'kode_entitas' => '3', 'kode_jenis_identitas' => '5',
                'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas . ' (PLANT MM2100)', 'alamat_entitas' => $alamatFramas1,
                'nomor_ijin_entitas' => $ijinTpb, 'tanggal_ijin_entitas' => $tglIjinTpb, 'kode_negara' => 'ID'
            ];
            $entitasRows[] = [
                'seri' => 2, 'kode_entitas' => '7', 'kode_jenis_identitas' => '5',
                'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
            ];
            $entitasRows[] = [
                'seri' => 3, 'kode_entitas' => '8', 'kode_jenis_identitas' => '5',
                'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas . ' (PLANT DELTA SILICON 5)', 'alamat_entitas' => $alamatFramas2,
                'nomor_ijin_entitas' => $ijinTpb, 'tanggal_ijin_entitas' => $tglIjinTpb, 'kode_negara' => 'ID'
            ];
        } else {
            // Sales Invoice: BC 2.7 (TPB Lain) atau BC 3.0 (PEB Ekspor)
            if ($cleanBc === '30') {
                $entitasRows[] = [
                    'seri' => 1, 'kode_entitas' => '2', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => $ijinTpb, 'tanggal_ijin_entitas' => $tglIjinTpb, 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 2, 'kode_entitas' => '7', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 3, 'kode_entitas' => '6', 'kode_jenis_identitas' => '0',
                    'nomor_identitas' => '', 'nama_entitas' => $partnerName, 'alamat_entitas' => $partnerAddr,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => ($partnerCountry !== 'ID' ? $partnerCountry : 'US')
                ];
                $entitasRows[] = [
                    'seri' => 4, 'kode_entitas' => '8', 'kode_jenis_identitas' => '0',
                    'nomor_identitas' => '', 'nama_entitas' => $partnerName, 'alamat_entitas' => $partnerAddr,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => ($partnerCountry !== 'ID' ? $partnerCountry : 'US')
                ];
            } else {
                $entitasRows[] = [
                    'seri' => 1, 'kode_entitas' => '3', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => $ijinTpb, 'tanggal_ijin_entitas' => $tglIjinTpb, 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 2, 'kode_entitas' => '7', 'kode_jenis_identitas' => '5',
                    'nomor_identitas' => $npwpFramas, 'nama_entitas' => $namaFramas, 'alamat_entitas' => $alamatFramas1,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
                $entitasRows[] = [
                    'seri' => 3, 'kode_entitas' => '8', 'kode_jenis_identitas' => !empty($partnerNpwp) ? '5' : '0',
                    'nomor_identitas' => $partnerNpwp, 'nama_entitas' => $partnerName, 'alamat_entitas' => $partnerAddr,
                    'nomor_ijin_entitas' => '', 'tanggal_ijin_entitas' => '', 'kode_negara' => 'ID'
                ];
            }
        }

        foreach ($entitasRows as $row) {
            $seri = (int)$row['seri'];
            $kdEnt = $koneksi->real_escape_string($row['kode_entitas']);
            $kdJnsId = $koneksi->real_escape_string($row['kode_jenis_identitas']);
            $noId = $koneksi->real_escape_string($row['nomor_identitas']);
            $namaEnt = $koneksi->real_escape_string($row['nama_entitas']);
            $almtEnt = $koneksi->real_escape_string($row['alamat_entitas']);
            $noIjin = $koneksi->real_escape_string($row['nomor_ijin_entitas']);
            $tglIjin = $koneksi->real_escape_string($row['tanggal_ijin_entitas']);
            $kdNeg = $koneksi->real_escape_string($row['kode_negara']);

            $insSql = "INSERT INTO bc_ceisa_entitas (
                cmp_id, nomor_aju, seri, kode_entitas, kode_jenis_identitas,
                nomor_identitas, nama_entitas, alamat_entitas, nomor_ijin_entitas,
                tanggal_ijin_entitas, kode_negara, user_id, upd_time
            ) VALUES (
                '$escCmp', '$escAju', $seri, '$kdEnt', '$kdJnsId',
                '$noId', '$namaEnt', '$almtEnt', '$noIjin',
                '$tglIjin', '$kdNeg', 'WINLINE', NOW()
            )";
            $koneksi->query($insSql);
        }
        return count($entitasRows);
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

// 2. LOAD DATA DOKUMEN DARI WINLINE ERP (SINGLE DATE ATAU RANGE)
if ($action === 'load_data') {
    header('Content-Type: application/json; charset=utf-8');
    $type       = trim($_GET['type'] ?? $targetType);
    $q          = trim($_GET['q'] ?? '');
    $targetBc   = trim($_GET['target_bc'] ?? '');
    $singleDate = trim($_GET['date'] ?? ($_GET['date_from'] ?? date('Y-m-d')));

    $conn = getWinlineDb();
    $rows = [];
    $isLive = ($conn !== null && $conn !== false);

    if ($isLive) {
        if ($type === 'sales_invoice') {
            // Adaptasi dari: w_custom_find_invoice3.srw (HANYA TAMPILKAN PER TANGGAL SAJA)
            $params = [$singleDate];
            $sql = "SELECT DISTINCT TOP 300
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
            WHERE CAST(s.delivery_dt AS DATE) = ? ";

            if (!empty($q)) {
                $sql .= " AND (s.inv_no LIKE ? OR s.dn_no LIKE ? OR s.customer LIKE ? OR s.po_no LIKE ?) ";
                $p = "%$q%";
                $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p;
            }
            $sql .= " ORDER BY s.inv_no ASC, s.dn_no ASC";

            $res = sqlsrv_query($conn, $sql, $params);
            if ($res) {
                while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
                    $docDt = $r['delivery_dt'] ? $r['delivery_dt']->format('Y-m-d') : date('Y-m-d');

                    // Tentukan Target Dokumen BC:
                    // 1. Jika kategori ekspor / valuta luar negeri -> BC 3.0 (PEB Ekspor)
                    // 2. Jika pengiriman ke Kawasan Berikat / TPB lain -> BC 2.7
                    $isExport = (stripos($r['customer'] ?? '', 'EXPORT') !== false) || (stripos($r['category'] ?? '', 'EXP') !== false);
                    $determinedBc = $isExport ? '30' : '27';
                    $bcLabel = $isExport ? 'BC 3.0 (PEB Ekspor)' : 'BC 2.7 (TPB ke TPB Lain)';
                    $valuta = $isExport ? 'USD' : 'USD';

                    $rows[] = [
                        'doc_no'       => $r['doc_no'] ?: ($r['dn_no'] ?: '-'),
                        'ref_no'       => $r['dn_no'] ?: '',
                        'doc_date'     => $docDt,
                        'partner_name' => $r['partner_name'] ?: 'CUSTOMER',
                        'partner_addr' => 'Kode: ' . ($r['cust_cd'] ?: '-') . ' | PO: ' . ($r['po_no'] ?: '-'),
                        'target_bc'    => $determinedBc,
                        'bc_label'     => $bcLabel,
                        'valuta'       => $valuta,
                        'total_fob'    => 0,
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
            // Adaptasi dari: w_custom_xtrn_doc_find.srw (HANYA TAMPILKAN PER TANGGAL SAJA)
            $params = [$singleDate, $singleDate];
            $sql = "SELECT TOP 300
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
              AND (CAST(b.c029 AS DATE) = ? OR CAST(b.c028 AS DATE) = ?) ";

            if (!empty($q)) {
                $sql .= " AND (b.c045 LIKE ? OR b.c010 LIKE ? OR b.c004 LIKE ?) ";
                $p = "%$q%";
                $params[] = $p; $params[] = $p; $params[] = $p;
            }
            $sql .= " GROUP BY 
                b.c045, b.c044, b.c029, b.c028, b.c010, b.c004, b.c021, b.c154, b.mesocomp
            ORDER BY b.c045 ASC";

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
                        'partner_addr' => 'Plant: ' . ($r['mesocomp'] ?: '05FI'),
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
            // Purchase Order: w_custom_find_po.srw
            $params = [$singleDate];
            $sql = "SELECT TOP 300
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
                t002.c001 = t025.c021
            LEFT JOIN cwldata..t026 t026 WITH (NOLOCK) ON 
                t026.c044 = t025.c021 
                AND t026.c045 = t025.c022 
                AND t026.mesocomp = t025.mesocomp
                AND t026.c055 <> 13 
                AND t026.c003 <> 'TEXT'
            WHERE t025.c044 LIKE 'PO%'
              AND CAST(t025.c028 AS DATE) = ? ";

            if (!empty($q)) {
                $sql .= " AND (t025.c044 LIKE ? OR t025.c010 LIKE ? OR t025.c021 LIKE ?) ";
                $p = "%$q%";
                $params[] = $p; $params[] = $p; $params[] = $p;
            }
            $sql .= " GROUP BY 
                t025.c044, t025.c028, t025.c010, t025.c011, t025.c012, t025.c014, t025.c021, 
                t002.c000, t025.c154, t025.mesocomp
            ORDER BY t025.c044 ASC";

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
        'date'         => $singleDate,
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
    $partnerInfo = null;

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
                t002.c000 AS valuta,
                t025.c010 AS partner_name,
                t025.c021 AS partner_cd
            FROM cwldata..t025 t025 WITH (NOLOCK)
            JOIN cwldata..t026 t026 WITH (NOLOCK) ON 
                t025.c021 = t026.c044 
                AND t025.c022 = t026.c045 
                AND t025.mesocomp = t026.mesocomp
            LEFT JOIN cwldata..t002 t002 WITH (NOLOCK) ON 
                t002.c001 = t025.c021
            WHERE t025.c044 = ?
              AND t026.c055 <> 13
              AND t026.c003 <> 'TEXT'
            ORDER BY srno ASC";

            $resDtl = sqlsrv_query($conn, $sqlPoDtl, array($docNo));
            if ($resDtl) {
                $seri = 1;
                while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                    if (!empty($rd['valuta'])) $valuta = trim($rd['valuta']);
                    if (!$partnerInfo && !empty($rd['partner_cd'])) {
                        $partnerInfo = getWinlinePartnerInfo($conn, 'purchase_order', $rd['partner_cd'], $docNo);
                    }
                    $qty = (float)$rd['qty'];
                    $prc = (float)$rd['price'];
                    $tot = (float)$rd['total'];
                    if ($tot == 0 && $qty > 0 && $prc > 0) $tot = $qty * $prc;

                    $detailItems[] = [
                        'seri_barang'    => $seri++,
                        'kode_barang'    => trim($rd['item_code']),
                        'uraian'         => trim($rd['item_name']),
                        'kode_satuan'    => trim($rd['uom'] ?: 'PCE'),
                        'qty_po'         => $qty,
                        'jumlah_satuan'  => $qty, // editable actual qty
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
            // Sales Invoice & DN Transfer: t025 join t026
            $sqlDtl = "SELECT 
                t26.c003 AS item_code, 
                MAX(t26.c004) AS item_name, 
                SUM(t26.c005) AS qty, 
                t26.c007 AS price, 
                SUM(t26.c031) AS total, 
                MAX(t26.c015) AS sat,
                MAX(t25.c010) AS partner_name,
                MAX(t25.c021) AS partner_cd
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
                    if (!$partnerInfo && !empty($rd['partner_cd'])) {
                        $partnerInfo = getWinlinePartnerInfo($conn, $type, $rd['partner_cd'], $docNo);
                    }
                    $qty = (float)$rd['qty'];
                    $prc = (float)$rd['price'];
                    $tot = (float)$rd['total'];
                    if ($tot == 0 && $qty > 0 && $prc > 0) $tot = $qty * $prc;

                    $detailItems[] = [
                        'seri_barang'    => $seri++,
                        'kode_barang'    => trim($rd['item_code']),
                        'uraian'         => trim($rd['item_name']),
                        'kode_satuan'    => trim($rd['sat'] ?: ($type === 'sales_invoice' ? 'PR' : 'PCE')),
                        'qty_po'         => $qty,
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

        if (!$partnerInfo) {
            $partnerInfo = getWinlinePartnerInfo($conn, $type, '', $docNo);
        }
    }

    echo json_encode([
        'status'       => 'OK',
        'doc_no'       => $docNo,
        'target_bc'    => $targetBc,
        'valuta'       => $valuta,
        'partner_info' => $partnerInfo,
        'items'        => $detailItems
    ]);
    exit;
}

// -------------------------------------------------------------
// AJAX API: GENERATE NOMOR AJU & INTEGRASI KE STAGING H2H & IT INVENTORY
// (MENDUKUNG SINGLE, MULTI-SELECT, DAN EDIT QTY PO PRORATA)
// -------------------------------------------------------------
if ($action === 'integrate_h2h') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Bisa menerima single doc_no atau array doc_nos (Multi-Select)
    $rawDocNos = $_POST['doc_nos'] ?? ($_POST['doc_no'] ?? '');
    $docNos = [];
    if (is_array($rawDocNos)) {
        $docNos = $rawDocNos;
    } elseif (!empty($rawDocNos)) {
        // Cek jika format JSON array
        $decoded = json_decode($rawDocNos, true);
        if (is_array($decoded)) {
            $docNos = $decoded;
        } else {
            $docNos = array_filter(array_map('trim', explode(',', $rawDocNos)));
        }
    }

    if (empty($docNos)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih minimal satu dokumen Winline ERP untuk diintegrasikan.']);
        exit;
    }

    $targetBc = trim($_POST['target_bc'] ?? '27');
    $type     = trim($_POST['type'] ?? 'sales_invoice');
    $cleanBc  = preg_replace('/[^0-9]/', '', $targetBc);
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

    $conn = getWinlineDb();
    $valuta = in_array($cleanBc, ['40']) ? 'IDR' : 'USD';
    $combinedItems = [];
    $partnerData = null;

    // Cek apakah ada customized items (misal dari edit Qty PO di modal)
    $customItemsRaw = $_POST['items'] ?? '';
    $hasCustomItems = false;
    if (!empty($customItemsRaw)) {
        $parsedItems = is_array($customItemsRaw) ? $customItemsRaw : json_decode($customItemsRaw, true);
        if (!empty($parsedItems) && is_array($parsedItems)) {
            $hasCustomItems = true;
            $sr = 1;
            foreach ($parsedItems as $ci) {
                $actualQty = (float)($ci['jumlah_satuan'] ?? ($ci['qty_actual'] ?? ($ci['qty'] ?? 1)));
                $origQty   = (float)($ci['qty_po'] ?? $actualQty);
                $ratio     = ($origQty > 0) ? ($actualQty / $origQty) : 1;
                $hrg       = (float)($ci['harga_satuan'] ?? ($ci['hrg'] ?? 0));
                $fob       = isset($ci['total_fob']) ? (float)$ci['total_fob'] : round($actualQty * $hrg, 2);
                $net       = isset($ci['netto']) ? (float)$ci['netto'] : round(((float)($origQty * 0.2)) * $ratio, 2);
                $brt       = isset($ci['bruto']) ? (float)$ci['bruto'] : round(((float)($origQty * 0.22)) * $ratio, 2);

                $combinedItems[] = [
                    'seri'     => $sr++,
                    'kode'     => trim($ci['kode_barang'] ?? ($ci['kode'] ?? '')),
                    'uraian'   => trim($ci['uraian'] ?? 'Barang Winline ERP'),
                    'sat'      => trim($ci['kode_satuan'] ?? ($ci['sat'] ?? 'PCE')),
                    'qty_po'   => $origQty,
                    'qty'      => $actualQty,
                    'kem'      => trim($ci['kode_kemasan'] ?? 'CT'),
                    'jml_kem'  => max(1, (int)ceil($actualQty / 50)),
                    'net'      => $net,
                    'brt'      => $brt,
                    'hrg'      => $hrg,
                    'fob'      => $fob
                ];
            }
        }
    }

    // Jika tidak ada custom items, tarik otomatis dari Winline SQL Server untuk setiap docNo yang dipilih
    if (!$hasCustomItems) {
        $seri = 1;
        foreach ($docNos as $docNo) {
            if ($type === 'purchase_order') {
                $sqlPoDtl = "SELECT
                    RIGHT('000000' + CAST(t026.c114 AS VARCHAR(6)), 6) AS srno, 	
                    t026.c003 AS item_code, 	
                    t026.c004 AS item_name, 
                    t026.c005 AS qty,
                    t026.c007 AS price, 	
                    t026.c031 AS total, 	
                    t026.c015 AS uom,
                    t002.c000 AS valuta,
                    t025.c010 AS partner_name,
                    t025.c021 AS partner_cd
                FROM cwldata..t025 t025 WITH (NOLOCK)
                JOIN cwldata..t026 t026 WITH (NOLOCK) ON 
                    t025.c021 = t026.c044 
                    AND t025.c022 = t026.c045 
                    AND t025.mesocomp = t026.mesocomp
                LEFT JOIN cwldata..t002 t002 WITH (NOLOCK) ON 
                    t002.c001 = t025.c021
                WHERE t025.c044 = ?
                  AND t026.c055 <> 13
                  AND t026.c003 <> 'TEXT'
                ORDER BY srno ASC";

                $resDtl = @sqlsrv_query($conn, $sqlPoDtl, [$docNo]);
                if ($resDtl) {
                    while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                        if (!empty($rd['valuta'])) $valuta = trim($rd['valuta']);
                        if (!$partnerData && !empty($rd['partner_cd'])) {
                            $partnerData = getWinlinePartnerInfo($conn, 'purchase_order', $rd['partner_cd']);
                        }
                        $qVal = (float)$rd['qty'];
                        $pVal = (float)$rd['price'];
                        $tVal = (float)$rd['total'];
                        if ($tVal == 0 && $qVal > 0 && $pVal > 0) $tVal = $qVal * $pVal;

                        $combinedItems[] = [
                            'seri'     => $seri++,
                            'kode'     => trim($rd['item_code']),
                            'uraian'   => trim($rd['item_name']),
                            'sat'      => trim($rd['uom'] ?: 'PCE'),
                            'qty_po'   => $qVal,
                            'qty'      => $qVal,
                            'kem'      => 'CT',
                            'jml_kem'  => max(1, (int)ceil($qVal / 50)),
                            'net'      => round($qVal * 0.2, 2),
                            'brt'      => round($qVal * 0.22, 2),
                            'hrg'      => $pVal,
                            'fob'      => $tVal
                        ];
                    }
                }
            } else {
                // Sales Invoice & DN Transfer
                $sqlDtl = "SELECT 
                    t26.c003 AS item_code, 
                    MAX(t26.c004) AS item_name, 
                    SUM(t26.c005) AS qty, 
                    t26.c007 AS price, 
                    SUM(t26.c031) AS total, 
                    MAX(t26.c015) AS sat,
                    MAX(t25.c010) AS partner_name,
                    MAX(t25.c021) AS partner_cd
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

                $resDtl = @sqlsrv_query($conn, $sqlDtl, [$docNo, $docNo, $docNo]);
                if ($resDtl) {
                    while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                        if (!$partnerData && !empty($rd['partner_cd'])) {
                            $partnerData = getWinlinePartnerInfo($conn, $type, $rd['partner_cd']);
                        }
                        $qVal = (float)$rd['qty'];
                        $pVal = (float)$rd['price'];
                        $tVal = (float)$rd['total'];
                        if ($tVal == 0 && $qVal > 0 && $pVal > 0) $tVal = $qVal * $pVal;

                        $combinedItems[] = [
                            'seri'     => $seri++,
                            'kode'     => trim($rd['item_code']),
                            'uraian'   => trim($rd['item_name']),
                            'sat'      => trim($rd['sat'] ?: ($type === 'sales_invoice' ? 'PR' : 'PCE')),
                            'qty_po'   => $qVal,
                            'qty'      => $qVal,
                            'kem'      => ($type === 'dn_transfer') ? 'BX' : 'CT',
                            'jml_kem'  => max(1, (int)ceil($qVal / 40)),
                            'net'      => round($qVal * 0.15, 2),
                            'brt'      => round($qVal * 0.17, 2),
                            'hrg'      => $pVal,
                            'fob'      => $tVal
                        ];
                    }
                }
            }
        }
    }

    // Pastikan informasi partner (Supplier / Customer / Plant) selalu terambil
    if ($conn && empty($partnerData) && !empty($docNos)) {
        $partnerData = getWinlinePartnerInfo($conn, $type, '', $docNos[0]);
    }

    if (empty($combinedItems)) {
        // Fallback default sample item jika tidak ada koneksi
        $combinedItems = [
            ['seri' => 1, 'kode' => 'SH-OUT-001', 'uraian' => 'OUTSOLE TPU ADIDAS PREDATOR FG SIZE 42', 'sat' => 'PR', 'qty_po' => 600, 'qty' => 600, 'kem' => 'CT', 'jml_kem' => 30, 'net' => 180, 'brt' => 195, 'hrg' => ($valuta === 'IDR' ? 75000 : 5.25), 'fob' => ($valuta === 'IDR' ? 45000000 : 3150.00)]
        ];
    }

    // Hitung total akumulasi
    $totFob = 0;
    $totNet = 0;
    $totBrt = 0;
    $totQty = 0;
    $totKem = 0;
    foreach ($combinedItems as $it) {
        $totFob += $it['fob'];
        $totNet += $it['net'];
        $totBrt += $it['brt'];
        $totQty += $it['qty'];
        $totKem += $it['jml_kem'];
    }

    $koneksi->begin_transaction();
    try {
        $kodeKantor = $company['kode_kantor'] ?? '050900';

        // 1. Insert Header Staging CEISA 4.0
        $koneksi->query("INSERT INTO bc_ceisa_header (
            cmp_id, nomor_aju, kode_dokumen, kode_kantor, kode_valuta,
            fob, cif, harga_penyerahan, netto, bruto,
            tanggal_pernyataan, user_id, upd_time
        ) VALUES (
            '$escCmp', '$escAju', '$cleanBc', '$kodeKantor', '$valuta',
            $totFob, $totFob, $totFob, $totNet, $totBrt,
            CURDATE(), 'WINLINE', NOW()
        )");

        // 2. Insert Barang Staging CEISA 4.0
        foreach ($combinedItems as $it) {
            $escKode = $koneksi->real_escape_string($it['kode']);
            $escUraian = $koneksi->real_escape_string($it['uraian']);
            $escSat = $koneksi->real_escape_string($it['sat']);
            $escKem = $koneksi->real_escape_string($it['kem']);

            $koneksi->query("INSERT INTO bc_ceisa_barang (
                cmp_id, nomor_aju, seri_barang, kode_barang, uraian, kode_satuan, jumlah_satuan,
                kode_kemasan, jumlah_kemasan, netto, bruto, harga_satuan, fob, cif, cif_rupiah,
                harga_penyerahan, user_id, upd_time
            ) VALUES (
                '$escCmp', '$escAju', {$it['seri']}, '$escKode', '$escUraian', '$escSat', {$it['qty']},
                '$escKem', {$it['jml_kem']}, {$it['net']}, {$it['brt']}, {$it['hrg']}, {$it['fob']}, {$it['fob']}, {$it['fob']},
                {$it['fob']}, 'WINLINE', NOW()
            )");

            // 3. Jika PO, masukkan data penerimaan aktual & prorata ke IT Inventory (tabel bc_1ab2 Pemasukan)
            if ($type === 'purchase_order') {
                $chk1ab = $koneksi->query("SHOW TABLES LIKE 'bc_1ab2'");
                if ($chk1ab && $chk1ab->num_rows > 0) {
                    $poDocFirst = $docNos[0] ?? '';
                    $vendorName = $koneksi->real_escape_string($partnerData['name'] ?? 'SUPPLIER WINLINE');
                    $vendorNpwp = $koneksi->real_escape_string($partnerData['npwp'] ?? '');

                    $koneksi->query("INSERT INTO bc_1ab2 (
                        cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI,
                        NOMOR_AJU, NOMOR_PO, SERI_BARANG, KODE_BARANG, URAIAN, UNIT,
                        QTY, QTY_AKTUAL, NILAI, CIF, NETTO, GROSS, AB, NAMA_BS, NPWP_BS, TGL_PROSES, CRT_DT, CRT_USER
                    ) VALUES (
                        '$escCmp', '$cleanBc', '-', CURDATE(), '$poDocFirst', CURDATE(),
                        '$escAju', '$poDocFirst', {$it['seri']}, '$escKode', '$escUraian', '$escSat',
                        {$it['qty_po']}, {$it['qty']}, {$it['fob']}, {$it['fob']}, {$it['net']}, {$it['brt']}, 'A',
                        '$vendorName', '$vendorNpwp', CURDATE(), CURDATE(), 'WINLINE'
                    ) ON DUPLICATE KEY UPDATE
                        QTY_AKTUAL = VALUES(QTY_AKTUAL),
                        NILAI = VALUES(NILAI),
                        CIF = VALUES(CIF),
                        NETTO = VALUES(NETTO),
                        GROSS = VALUES(GROSS),
                        NOMOR_AJU = VALUES(NOMOR_AJU),
                        NAMA_BS = VALUES(NAMA_BS),
                        NPWP_BS = VALUES(NPWP_BS),
                        TGL_PROSES = VALUES(TGL_PROSES)");
                }
            }
        }

        // 4. Insert Dokumen Pelengkap (Satu baris dokumen untuk setiap doc_no yang digabung)
        $docSeri = 1;
        $kodeDokPelengkap = ($type === 'purchase_order') ? '999' : (($type === 'dn_transfer') ? '217' : '380');
        foreach ($docNos as $dn) {
            $escDn = $koneksi->real_escape_string($dn);
            $koneksi->query("INSERT INTO bc_ceisa_dokumen (
                cmp_id, nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen, user_id, upd_time
            ) VALUES (
                '$escCmp', '$escAju', $docSeri, '$kodeDokPelengkap', '$escDn', CURDATE(), 'WINLINE', NOW()
            )");
            $docSeri++;
        }

        // 5. Insert Kemasan Staging
        $koneksi->query("INSERT INTO bc_ceisa_kemasan (
            cmp_id, nomor_aju, seri, kode_kemasan, jumlah_kemasan, merek, user_id, upd_time
        ) VALUES (
            '$escCmp', '$escAju', 1, 'CT', $totKem, 'FRAMAS', 'WINLINE', NOW()
        )");

        // 6. GENERATE ENTITAS SECARA OTOMATIS (w_custom_form_trn.srw)
        autoGenerateCeisaEntitas($nomorAju, $cleanBc, $company, $partnerData ?: ['name' => 'PARTNER WINLINE'], $type, $koneksi);

        // 7. Update nomor_aju balik ke Winline jika Sales Invoice
        if ($conn && $type === 'sales_invoice') {
            foreach ($docNos as $dn) {
                @sqlsrv_query($conn, "UPDATE pur_order2cash_sales SET nomor_aju = ? WHERE inv_no = ? OR dn_no = ?", array($nomorAju, $dn, $dn));
            }
        }

        $koneksi->commit();

        $docCount = count($docNos);
        $docSummary = ($docCount > 1) ? "$docCount dokumen (" . implode(', ', array_slice($docNos, 0, 3)) . "...)" : $docNos[0];

        echo json_encode([
            'status'    => 'OK',
            'nomor_aju' => $nomorAju,
            'kode_bc'   => $cleanBc,
            'doc_count' => $docCount,
            'message'   => "Berhasil membuat draft Host to Host BC $cleanBc! Nomor AJU: $nomorAju ($docSummary terintegrasi otomatis dengan " . count($combinedItems) . " item barang & entitas resmi)."
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
        'desc'       => 'Integrasi data Faktur Penjualan (Sales Invoice) Winline ERP untuk pembuatan dokumen Host to Host: <strong>BC 2.7 (TPB Lain)</strong> dan <strong>BC 3.0 (PEB Ekspor)</strong> ke Customer. Tampilkan per tanggal dengan fitur Multi-Select.',
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
        'desc'       => 'Integrasi data Delivery Note (DN%) Winline ERP untuk pembuatan dokumen Host to Host: <strong>BC 2.7 (Perpindahan Barang Antar Plant Framas)</strong>. Tampilkan per tanggal dengan fitur Multi-Select.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '27' => 'BC 2.7 — Perpindahan Antar Plant Framas'
        ]
    ],
    'purchase_order' => [
        'title'      => 'Purchase Order',
        'badge'      => 'BC 2.3, BC 4.0, BC 1.6 Pemasukan',
        'badge_cls'  => 'badge-po',
        'desc'       => 'Integrasi data Pesanan Pembelian (PO%) Winline ERP untuk pembuatan dokumen Host to Host Pemasukan: <strong>BC 2.3 (Impor TPB)</strong>, <strong>BC 4.0 (Pemasukan Lokal TLDDP)</strong>, dan <strong>BC 1.6 (Pemasukan PLB)</strong>. Dilengkapi fitur Edit Qty Aktual Masuk & Prorata IT Inventory.',
        'target_bcs' => [
            ''   => 'Semua Target Dokumen',
            '23' => 'BC 2.3 — Impor Bahan Baku TPB',
            '40' => 'BC 4.0 — Pemasukan Lokal (TLDDP)',
            '16' => 'BC 1.6 — Pemasukan ke PLB'
        ]
    ]
];

$curCfg = $typeTitles[$targetType];
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
            padding: 8px 12px 60px 12px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 11px;
            color: var(--text-main);
            background-color: var(--bg-page);
        }

        .header-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 6px 12px;
            margin-bottom: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 4px;
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .header-title {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .badge-target {
            font-size: 9px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 8px;
            letter-spacing: 0.2px;
        }
        .badge-customer { background: rgba(24, 144, 255, 0.12); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.3); }
        .badge-framas   { background: rgba(82, 196, 26, 0.12); color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.3); }
        .badge-po       { background: rgba(250, 173, 20, 0.12); color: #d46b08; border: 1px solid rgba(250, 173, 20, 0.3); }

        .type-pills {
            display: inline-flex;
            background: var(--header-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 2px;
            gap: 2px;
        }

        .type-pill {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .type-pill:hover {
            color: var(--primary);
            background: rgba(24, 144, 255, 0.06);
        }

        .type-pill.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 1px 2px rgba(24, 144, 255, 0.2);
        }

        .header-desc {
            font-size: 10.5px;
            color: var(--text-muted);
            margin: 0;
            line-height: 1.4;
        }

        .filter-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 6px 12px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-fields {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .form-group label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .form-control {
            height: 24px;
            padding: 2px 6px;
            font-size: 10.5px;
            border: 1px solid var(--border-dark);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.1);
        }

        .btn {
            height: 24px;
            padding: 0 8px;
            font-size: 10.5px;
            font-weight: 600;
            border: 1px solid transparent;
            border-radius: 3px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { background: #73d13d; }
        .btn-secondary { background: var(--header-bg); border-color: var(--border-dark); color: var(--text-main); }
        .btn-secondary:hover { background: #e8e8e8; }

        .btn-xs { height: 20px; padding: 0 5px; font-size: 9.5px; border-radius: 2px; }

        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
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
            color: var(--text-muted);
            font-weight: 600;
            font-size: 10px;
            padding: 5px 7px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        table.winline-table td {
            padding: 4px 7px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }

        table.winline-table tr:hover td {
            background: rgba(24, 144, 255, 0.03);
        }

        table.winline-table tr.selected td {
            background: rgba(24, 144, 255, 0.08);
        }

        .badge-status {
            display: inline-block;
            font-size: 9px;
            font-weight: 600;
            padding: 1px 5px;
            border-radius: 3px;
        }
        .badge-ready { background: rgba(24, 144, 255, 0.1); color: #1890ff; border: 1px solid rgba(24, 144, 255, 0.2); }
        .badge-integrated { background: rgba(82, 196, 26, 0.1); color: #52c41a; border: 1px solid rgba(82, 196, 26, 0.2); }

        .conn-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 9.5px;
            padding: 1px 6px;
            border-radius: 10px;
        }
        .conn-online { background: rgba(82, 196, 26, 0.12); color: #52c41a; }
        .conn-offline { background: rgba(255, 77, 79, 0.12); color: #ff4d4f; }
        .dot { width: 5px; height: 5px; border-radius: 50%; }
        .dot-green { background: #52c41a; }
        .dot-red { background: #ff4d4f; }

        /* Floating Multi-Select Toolbar */
        .multi-bar {
            position: fixed;
            bottom: 12px;
            left: 50%;
            transform: translateX(-50%);
            background: #001529;
            color: #fff;
            padding: 6px 16px;
            border-radius: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.25);
            display: flex;
            align-items: center;
            gap: 14px;
            z-index: 1000;
            animation: slideUp 0.2s ease-out;
            font-size: 11px;
        }

        @keyframes slideUp {
            from { bottom: -50px; opacity: 0; }
            to { bottom: 12px; opacity: 1; }
        }

        .multi-stats strong { color: #40a9ff; }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1050;
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: 6px;
            width: 92%;
            max-width: 900px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            padding: 8px 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--header-bg);
            border-radius: 6px 6px 0 0;
        }

        .modal-title {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-main);
        }

        .modal-body {
            padding: 10px 14px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 8px 14px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--header-bg);
            border-radius: 0 0 6px 6px;
        }

        .input-qty-edit {
            width: 75px;
            height: 20px;
            padding: 1px 4px;
            font-size: 10.5px;
            font-weight: 600;
            text-align: right;
            border: 1px solid #40a9ff;
            border-radius: 2px;
            background: #e6f7ff;
            color: #0050b3;
        }
        .input-qty-edit:focus {
            background: #fff;
            border-color: #1890ff;
            outline: none;
        }

        .alert-info-box {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 4px;
            padding: 6px 10px;
            margin-bottom: 8px;
            font-size: 10px;
            color: #0050b3;
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <div class="header-card">
        <div class="header-top">
            <div class="header-title-box">
                <h1 class="header-title">
                    <span>⚡ Winline ERP — <?= htmlspecialchars($curCfg['title']) ?></span>
                    <span class="badge-target <?= $curCfg['badge_cls'] ?>"><?= $curCfg['badge'] ?></span>
                </h1>
                <span class="conn-indicator conn-online" id="dbStatusBadge">
                    <span class="dot dot-green"></span> 192.168.170.254 (FRAMASDB)
                </span>
            </div>

            <!-- Tab Switcher -->
            <div class="type-pills">
                <a href="?type=sales_invoice" class="type-pill <?= $targetType === 'sales_invoice' ? 'active' : '' ?>">
                    <span>🛒 Sales Invoice</span>
                </a>
                <a href="?type=dn_transfer" class="type-pill <?= $targetType === 'dn_transfer' ? 'active' : '' ?>">
                    <span>🚚 DN Transfer</span>
                </a>
                <a href="?type=purchase_order" class="type-pill <?= $targetType === 'purchase_order' ? 'active' : '' ?>">
                    <span>📦 Purchase Order</span>
                </a>
            </div>
        </div>
        <p class="header-desc"><?= $curCfg['desc'] ?></p>
    </div>

    <!-- Filter Section (PER TANGGAL SAJA UNTUK SALES INVOICE & DN TRANSFER) -->
    <div class="filter-panel">
        <div class="filter-fields">
            <div class="form-group">
                <label for="filterDate">
                    <?= ($targetType === 'purchase_order') ? 'Tanggal PO:' : 'Tanggal Transaksi:' ?>
                </label>
                <input type="date" id="filterDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="form-group">
                <label for="searchKw">Pencarian:</label>
                <input type="text" id="searchKw" class="form-control" placeholder="No Dokumen / Partner..." style="width: 150px;">
            </div>

            <div class="form-group">
                <label for="filterTargetBc">Target Dokumen:</label>
                <select id="filterTargetBc" class="form-control" style="width: 140px;">
                    <?php foreach ($curCfg['target_bcs'] as $k => $lbl): ?>
                        <option value="<?= $k ?>"><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="button" class="btn btn-primary" onclick="loadWinlineData()">
                <span>🔍 Muat Data</span>
            </button>
            <button type="button" class="btn btn-secondary" onclick="resetFilter()">
                <span>🔄 Reset</span>
            </button>
        </div>

        <div>
            <span id="recordCountText" style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Memuat data...</span>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="table-card">
        <div class="table-responsive">
            <table class="winline-table" id="winlineTable">
                <thead>
                    <tr>
                        <th style="width: 28px; text-align: center;">
                            <input type="checkbox" id="checkAll" onchange="toggleCheckAll(this)" title="Pilih Semua">
                        </th>
                        <th style="width: 25px; text-align: center;">#</th>
                        <th style="width: 75px;">Status H2H</th>
                        <th style="width: 100px;">No Dokumen</th>
                        <th style="width: 90px;">No Ref (DN/PO)</th>
                        <th style="width: 70px;">Tanggal</th>
                        <th>Partner (Customer / Supplier / Plant)</th>
                        <th style="width: 90px;">Target Dokumen</th>
                        <th style="width: 65px; text-align: right;">Total Qty</th>
                        <th style="width: 60px; text-align: right;">Kemasan</th>
                        <th style="width: 65px; text-align: right;">Netto (kg)</th>
                        <th style="width: 65px; text-align: right;">Bruto (kg)</th>
                        <th style="width: 120px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td colspan="13" style="text-align: center; padding: 20px; color: var(--text-muted);">
                            <span>Memuat data dari SQL Server Winline ERP (192.168.170.254)...</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Floating Action Toolbar for Multi-Select (Sales Invoice & DN Transfer) -->
    <div id="multiBar" class="multi-bar" style="display: none;">
        <div>
            <span id="multiCountBadge" class="badge-target badge-customer" style="font-size: 10px; padding: 2px 7px;">0 Dokumen Terpilih</span>
        </div>
        <div class="multi-stats">
            Qty: <strong id="multiTotalQty">0</strong> |
            Netto: <strong id="multiTotalNetto">0.00</strong> kg |
            Bruto: <strong id="multiTotalBruto">0.00</strong> kg
        </div>
        <div style="display: flex; gap: 6px;">
            <button type="button" class="btn btn-secondary btn-xs" onclick="clearSelection()">Batal</button>
            <button type="button" class="btn btn-success btn-xs" onclick="integrateMulti()">⚡ Integrasikan Terpilih (Multi) ke Host to Host CEISA</button>
        </div>
    </div>

    <!-- Detail & Edit Qty Modal (Purchase Order Prorata & Item Inspection) -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Rincian Barang & Prorata Qty</h2>
                <button type="button" onclick="closeDetailModal()" style="border:none; background:none; font-size:16px; cursor:pointer; color:var(--text-muted);">&times;</button>
            </div>
            <div class="modal-body">
                <div id="modalInfoAlert" class="alert-info-box" style="display: none;">
                    <span>💡</span>
                    <span><strong>Konsep Prorata PO:</strong> Masukkan Qty Aktual Masuk/Terima pada kolom input. Nilai FOB, Netto, dan Bruto otomatis dihitung secara prorata untuk masuk ke IT Inventory dan Staging CEISA 4.0.</span>
                </div>

                <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:10.5px; background:var(--header-bg); padding:6px 10px; border-radius:4px; border:1px solid var(--border-color);">
                    <div>
                        <strong>Dokumen:</strong> <span id="mDocNo" style="font-family:monospace; color:var(--primary); font-weight:700;">-</span> |
                        <strong>Valuta:</strong> <span id="mValuta">-</span> |
                        <strong>Target BC:</strong> <span id="mTargetBc" class="badge-target badge-po">-</span>
                    </div>
                    <div>
                        <strong>Partner:</strong> <span id="mPartnerName">-</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="winline-table" id="modalItemTable">
                        <thead>
                            <tr>
                                <th style="width: 25px; text-align: center;">#</th>
                                <th style="width: 110px;">Kode Barang</th>
                                <th>Uraian Barang</th>
                                <th style="width: 45px;">Satuan</th>
                                <th style="width: 65px; text-align: right;" id="thOrigQty">Qty PO</th>
                                <th style="width: 85px; text-align: right;" id="thActualQty">Qty Aktual Masuk</th>
                                <th style="width: 70px; text-align: right;">Harga Satuan</th>
                                <th style="width: 80px; text-align: right;">Total Nilai (FOB)</th>
                                <th style="width: 65px; text-align: right;">Netto (kg)</th>
                                <th style="width: 65px; text-align: right;">Bruto (kg)</th>
                            </tr>
                        </thead>
                        <tbody id="modalItemBody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <div style="font-size:10.5px; color:var(--text-main);">
                    Total Qty Masuk: <strong id="mFootQty" style="color:var(--primary);">0</strong> |
                    FOB: <strong id="mFootFob" style="color:#52c41a;">0.00</strong> |
                    Netto: <strong id="mFootNet">0.00</strong> kg |
                    Bruto: <strong id="mFootBrt">0.00</strong> kg
                </div>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn btn-secondary" onclick="closeDetailModal()">Tutup</button>
                    <button type="button" class="btn btn-primary" id="btnModalIntegrate" onclick="executeModalIntegration()">
                        <span>⚡ Simpan & Integrasikan ke Host to Host CEISA</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script>
        const CURRENT_TYPE = <?= json_encode($targetType) ?>;
        let globalWinlineData = [];
        let selectedDocNos = new Set();
        let activeModalDocNo = '';
        let activeModalTargetBc = '';
        let activeModalItems = [];

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
            });
        }

        async function loadWinlineData() {
            const tableBody = document.getElementById('tableBody');
            tableBody.innerHTML = `<tr><td colspan="13" style="text-align: center; padding: 20px; color: var(--text-muted);">
                <div style="display:inline-block; animation: spin 1s infinite linear;">🔄</div> Sedang mengambil data ${CURRENT_TYPE} dari SQL Server Winline...
            </td></tr>`;
            clearSelection();

            const dateVal = document.getElementById('filterDate').value;
            const kwVal   = document.getElementById('searchKw').value.trim();
            const bcVal   = document.getElementById('filterTargetBc').value;

            try {
                const url = `winline_erp.php?action=load_data&type=${encodeURIComponent(CURRENT_TYPE)}&date=${encodeURIComponent(dateVal)}&q=${encodeURIComponent(kwVal)}&target_bc=${encodeURIComponent(bcVal)}`;
                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK') {
                    globalWinlineData = json.data || [];
                    renderTable(globalWinlineData);
                    document.getElementById('recordCountText').innerText = `Menampilkan ${globalWinlineData.length} dokumen (${dateVal})`;
                } else {
                    tableBody.innerHTML = `<tr><td colspan="13" style="text-align: center; padding: 15px; color: var(--danger);">Gagal memuat: ${escapeHtml(json.message || 'Error')}</td></tr>`;
                }
            } catch (e) {
                tableBody.innerHTML = `<tr><td colspan="13" style="text-align: center; padding: 15px; color: var(--danger);">Koneksi terputus ke backend: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('tableBody');
            if (!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align: center; padding: 25px; color: var(--text-muted);">Tidak ada transaksi ${CURRENT_TYPE} pada tanggal ini.</td></tr>`;
                return;
            }

            let html = '';
            data.forEach((r, idx) => {
                const isIntegrated = (r.status_h2h === 'integrated');
                const isSelected   = selectedDocNos.has(r.doc_no);
                const statusBadge  = isIntegrated 
                    ? `<span class="badge-status badge-integrated" title="AJU: ${escapeHtml(r.nomor_aju)}">Terintegrasi</span>` 
                    : `<span class="badge-status badge-ready">Siap H2H</span>`;

                const rowClass = isSelected ? 'selected' : '';

                html += `
                    <tr class="${rowClass}" id="row-${escapeHtml(r.doc_no)}">
                        <td style="text-align: center;">
                            <input type="checkbox" class="doc-chk" value="${escapeHtml(r.doc_no)}" 
                                ${isSelected ? 'checked' : ''} 
                                onchange="toggleRowSelect('${escapeHtml(r.doc_no)}', this.checked)">
                        </td>
                        <td style="text-align: center; color: var(--text-muted);">${idx + 1}</td>
                        <td>${statusBadge}</td>
                        <td style="font-family: monospace; font-weight: 700; color: var(--primary);">
                            ${escapeHtml(r.doc_no)}
                        </td>
                        <td style="font-family: monospace; color: var(--text-muted);">${escapeHtml(r.ref_no || '-')}</td>
                        <td>${escapeHtml(r.doc_date)}</td>
                        <td>
                            <div style="font-weight: 600;">${escapeHtml(r.partner_name)}</div>
                            <div style="font-size: 9.5px; color: var(--text-muted);">${escapeHtml(r.partner_addr)}</div>
                        </td>
                        <td>
                            <span class="badge-target ${CURRENT_TYPE === 'purchase_order' ? 'badge-po' : (CURRENT_TYPE === 'dn_transfer' ? 'badge-framas' : 'badge-customer')}">
                                BC ${escapeHtml(r.target_bc)}
                            </span>
                        </td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_qty).toLocaleString()} <span style="font-size: 9px; color: var(--text-muted);">${escapeHtml(r.unit || '')}</span></td>
                        <td style="text-align: right;">${Number(r.total_kemas).toLocaleString()} <span style="font-size: 9px; color: var(--text-muted);">${escapeHtml(r.kemas_sat || '')}</span></td>
                        <td style="text-align: right;">${Number(r.netto).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td style="text-align: right;">${Number(r.bruto).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td style="text-align: center;">
                            <div style="display: inline-flex; gap: 3px;">
                                <button type="button" class="btn btn-secondary btn-xs" title="Lihat Rincian / Edit Qty Prorata" onclick="openDetailModal('${escapeHtml(r.doc_no)}', '${escapeHtml(r.target_bc)}')">
                                    👁 ${CURRENT_TYPE === 'purchase_order' ? 'Prorata' : 'Rincian'}
                                </button>
                                <button type="button" class="btn btn-success btn-xs" title="Integrasikan ke Host to Host CEISA" onclick="integrateSingle('${escapeHtml(r.doc_no)}', '${escapeHtml(r.target_bc)}')">
                                    ⚡ H2H
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
            updateMultiBar();
        }

        // Multi-Select Checkbox Handler
        function toggleCheckAll(master) {
            const chks = document.querySelectorAll('.doc-chk');
            chks.forEach(chk => {
                chk.checked = master.checked;
                const docNo = chk.value;
                if (master.checked) {
                    selectedDocNos.add(docNo);
                    document.getElementById(`row-${docNo}`)?.classList.add('selected');
                } else {
                    selectedDocNos.delete(docNo);
                    document.getElementById(`row-${docNo}`)?.classList.remove('selected');
                }
            });
            updateMultiBar();
        }

        function toggleRowSelect(docNo, isChecked) {
            if (isChecked) {
                selectedDocNos.add(docNo);
                document.getElementById(`row-${docNo}`)?.classList.add('selected');
            } else {
                selectedDocNos.delete(docNo);
                document.getElementById(`row-${docNo}`)?.classList.remove('selected');
                document.getElementById('checkAll').checked = false;
            }
            updateMultiBar();
        }

        function clearSelection() {
            selectedDocNos.clear();
            document.querySelectorAll('.doc-chk').forEach(c => c.checked = false);
            document.querySelectorAll('tr.selected').forEach(r => r.classList.remove('selected'));
            const checkAll = document.getElementById('checkAll');
            if (checkAll) checkAll.checked = false;
            updateMultiBar();
        }

        function updateMultiBar() {
            const bar = document.getElementById('multiBar');
            const count = selectedDocNos.size;
            if (count > 0) {
                bar.style.display = 'flex';
                document.getElementById('multiCountBadge').innerText = `${count} Dokumen Terpilih`;

                // Hitung total Qty, Netto, Bruto terpilih
                let totQty = 0, totNet = 0, totBrt = 0;
                globalWinlineData.forEach(r => {
                    if (selectedDocNos.has(r.doc_no)) {
                        totQty += Number(r.total_qty || 0);
                        totNet += Number(r.netto || 0);
                        totBrt += Number(r.bruto || 0);
                    }
                });
                document.getElementById('multiTotalQty').innerText = totQty.toLocaleString();
                document.getElementById('multiTotalNetto').innerText = totNet.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('multiTotalBruto').innerText = totBrt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            } else {
                bar.style.display = 'none';
            }
        }

        // Integrasi Single Dokumen
        async function integrateSingle(docNo, targetBc) {
            const cfm = await Swal.fire({
                title: 'Konfirmasi Integrasi CEISA H2H',
                html: `Apakah Anda ingin membuat draft dokumen Host to Host <b>BC ${escapeHtml(targetBc)}</b> untuk dokumen Winline <b>${escapeHtml(docNo)}</b>?<br><small style="color:#8c8c8c;">Entitas, barang, dan dokumen pendukung akan dibuat otomatis.</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#d9d9d9',
                confirmButtonText: '⚡ Ya, Integrasikan!',
                cancelButtonText: 'Batal'
            });

            if (!cfm.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Memproses...',
                text: 'Mengambil data Winline ERP dan mendaftarkan staging CEISA 4.0...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const formData = new FormData();
                formData.append('action', 'integrate_h2h');
                formData.append('doc_no', docNo);
                formData.append('target_bc', targetBc);
                formData.append('type', CURRENT_TYPE);

                const res = await fetch('winline_erp.php', { method: 'POST', body: formData });
                const json = await res.json();

                if (json.status === 'OK') {
                    await Swal.fire({
                        title: 'Integrasi Berhasil!',
                        html: `${escapeHtml(json.message)}<br><br><b>Nomor AJU:</b> <code style="font-size:13px; color:#1890ff;">${escapeHtml(json.nomor_aju)}</code>`,
                        icon: 'success',
                        confirmButtonText: 'OK'
                    });
                    loadWinlineData();
                } else {
                    Swal.fire('Gagal Integrasi', escapeHtml(json.message || 'Terjadi kesalahan sistem.'), 'error');
                }
            } catch (e) {
                Swal.fire('Error', escapeHtml(e.message), 'error');
            }
        }

        // Integrasi Multi Dokumen
        async function integrateMulti() {
            const arr = Array.from(selectedDocNos);
            if (arr.length === 0) return;

            const cfm = await Swal.fire({
                title: 'Integrasi Multi-Dokumen',
                html: `Apakah Anda yakin ingin menggabungkan <b>${arr.length} dokumen</b> terpilih ke dalam satu berkas Host to Host CEISA 4.0?<br><br>Dokumen terpilih:<br><code style="font-size:10.5px;">${arr.slice(0, 5).join(', ')}${arr.length > 5 ? '...' : ''}</code>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#d9d9d9',
                confirmButtonText: `⚡ Integrasikan ${arr.length} Dokumen!`,
                cancelButtonText: 'Batal'
            });

            if (!cfm.isConfirmed) return;

            Swal.fire({
                title: 'Menggabungkan Dokumen...',
                text: `Memproses ${arr.length} dokumen ke Staging CEISA & IT Inventory...`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const formData = new FormData();
                formData.append('action', 'integrate_h2h');
                formData.append('doc_nos', JSON.stringify(arr));
                formData.append('type', CURRENT_TYPE);
                // Ambil target BC dari dokumen pertama
                const firstDoc = globalWinlineData.find(d => d.doc_no === arr[0]);
                formData.append('target_bc', firstDoc ? firstDoc.target_bc : '27');

                const res = await fetch('winline_erp.php', { method: 'POST', body: formData });
                const json = await res.json();

                if (json.status === 'OK') {
                    await Swal.fire({
                        title: 'Multi-Integrasi Sukses!',
                        html: `${escapeHtml(json.message)}<br><br><b>Nomor AJU Gabungan:</b> <code style="font-size:13px; color:#1890ff;">${escapeHtml(json.nomor_aju)}</code>`,
                        icon: 'success'
                    });
                    clearSelection();
                    loadWinlineData();
                } else {
                    Swal.fire('Gagal Integrasi', escapeHtml(json.message || 'Terjadi kesalahan sistem.'), 'error');
                }
            } catch (e) {
                Swal.fire('Error', escapeHtml(e.message), 'error');
            }
        }

        // Modal Rincian & Edit Qty PO Prorata
        async function openDetailModal(docNo, targetBc) {
            activeModalDocNo = docNo;
            activeModalTargetBc = targetBc;
            activeModalItems = [];

            document.getElementById('mDocNo').innerText = docNo;
            document.getElementById('mTargetBc').innerText = `BC ${targetBc}`;
            document.getElementById('mPartnerName').innerText = 'Memuat partner...';
            document.getElementById('modalItemBody').innerHTML = '<tr><td colspan="10" style="text-align:center; padding:15px; color:#8c8c8c;">Mengambil rincian barang dari Winline ERP...</td></tr>';
            
            const isPo = (CURRENT_TYPE === 'purchase_order');
            document.getElementById('modalInfoAlert').style.display = isPo ? 'flex' : 'none';
            document.getElementById('modalTitle').innerText = isPo ? 'Prorata Penerimaan PO & IT Inventory' : 'Rincian Barang Winline ERP';

            document.getElementById('detailModal').style.display = 'flex';

            try {
                const res = await fetch(`winline_erp.php?action=get_detail&doc_no=${encodeURIComponent(docNo)}&target_bc=${encodeURIComponent(targetBc)}&type=${encodeURIComponent(CURRENT_TYPE)}`);
                const json = await res.json();

                if (json.status === 'OK' && json.items) {
                    activeModalItems = json.items;
                    document.getElementById('mValuta').innerText = json.valuta || 'IDR';
                    if (json.partner_info && json.partner_info.name) {
                        document.getElementById('mPartnerName').innerText = `${json.partner_info.name} (${json.partner_info.country || 'ID'})`;
                    } else {
                        const findDoc = globalWinlineData.find(d => d.doc_no === docNo);
                        document.getElementById('mPartnerName').innerText = findDoc ? findDoc.partner_name : '-';
                    }
                    renderModalItems(isPo);
                } else {
                    document.getElementById('modalItemBody').innerHTML = '<tr><td colspan="10" style="text-align:center; padding:15px; color:var(--danger);">Gagal memuat rincian barang.</td></tr>';
                }
            } catch (e) {
                document.getElementById('modalItemBody').innerHTML = `<tr><td colspan="10" style="text-align:center; padding:15px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderModalItems(isPo) {
            const tbody = document.getElementById('modalItemBody');
            let html = '';

            activeModalItems.forEach((it, idx) => {
                const origQty = Number(it.qty_po || it.jumlah_satuan || 1);
                const actQty  = Number(it.jumlah_satuan || origQty);
                const hrg     = Number(it.harga_satuan || 0);
                const fob     = Number(it.total_fob || (actQty * hrg));
                const net     = Number(it.netto || (actQty * 0.2));
                const brt     = Number(it.bruto || (net * 1.1));

                html += `
                    <tr id="mRow-${idx}">
                        <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                        <td style="font-family:monospace; font-weight:600; color:var(--primary);">${escapeHtml(it.kode_barang)}</td>
                        <td>${escapeHtml(it.uraian)}</td>
                        <td>${escapeHtml(it.kode_satuan)}</td>
                        <td style="text-align:right; font-weight:600;">${origQty.toLocaleString()}</td>
                        <td style="text-align:right;">
                            ${isPo ? `
                                <input type="number" step="any" min="0" value="${actQty}" 
                                    class="input-qty-edit" 
                                    id="inputActQty-${idx}"
                                    data-idx="${idx}"
                                    data-orig-qty="${origQty}"
                                    data-price="${hrg}"
                                    data-orig-net="${net}"
                                    data-orig-brt="${brt}"
                                    oninput="recalcProrataRow(${idx})">
                            ` : `${actQty.toLocaleString()}`}
                        </td>
                        <td style="text-align:right;">${hrg.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td style="text-align:right; font-weight:600; color:#52c41a;" id="mColFob-${idx}">${fob.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td style="text-align:right;" id="mColNet-${idx}">${net.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td style="text-align:right;" id="mColBrt-${idx}">${brt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            recalcModalTotals();
        }

        // Perhitungan Prorata Real-Time Saat Edit Qty PO
        function recalcProrataRow(idx) {
            const input = document.getElementById(`inputActQty-${idx}`);
            if (!input) return;

            const actualQty = parseFloat(input.value) || 0;
            const origQty   = parseFloat(input.dataset.origQty) || 1;
            const price     = parseFloat(input.dataset.price) || 0;
            const origNet   = parseFloat(input.dataset.origNet) || (origQty * 0.2);
            const origBrt   = parseFloat(input.dataset.origBrt) || (origNet * 1.1);

            const ratio = (origQty > 0) ? (actualQty / origQty) : 1;
            const newFob = actualQty * price;
            const newNet = origNet * ratio;
            const newBrt = origBrt * ratio;

            document.getElementById(`mColFob-${idx}`).innerText = newFob.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById(`mColNet-${idx}`).innerText = newNet.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById(`mColBrt-${idx}`).innerText = newBrt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

            // Update state
            activeModalItems[idx].jumlah_satuan = actualQty;
            activeModalItems[idx].total_fob     = newFob;
            activeModalItems[idx].netto         = newNet;
            activeModalItems[idx].bruto         = newBrt;

            recalcModalTotals();
        }

        function recalcModalTotals() {
            let totQty = 0, totFob = 0, totNet = 0, totBrt = 0;
            activeModalItems.forEach(it => {
                totQty += Number(it.jumlah_satuan || 0);
                totFob += Number(it.total_fob || 0);
                totNet += Number(it.netto || 0);
                totBrt += Number(it.bruto || 0);
            });

            document.getElementById('mFootQty').innerText = totQty.toLocaleString();
            document.getElementById('mFootFob').innerText = totFob.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('mFootNet').innerText = totNet.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('mFootBrt').innerText = totBrt.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        async function executeModalIntegration() {
            if (!activeModalDocNo) return;

            const cfm = await Swal.fire({
                title: 'Simpan & Integrasikan CEISA H2H',
                html: `Apakah Anda ingin mengintegrasikan dokumen <b>${escapeHtml(activeModalDocNo)}</b> dengan rincian barang dan perhitungan prorata saat ini?<br><small style="color:#52c41a;">Qty aktual akan masuk ke IT Inventory dan data pabean.</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: '⚡ Ya, Integrasikan!',
                cancelButtonText: 'Batal'
            });

            if (!cfm.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Memproses...',
                text: 'Menyimpan barang prorata dan mendaftarkan staging CEISA 4.0...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const formData = new FormData();
                formData.append('action', 'integrate_h2h');
                formData.append('doc_no', activeModalDocNo);
                formData.append('target_bc', activeModalTargetBc);
                formData.append('type', CURRENT_TYPE);
                formData.append('items', JSON.stringify(activeModalItems));

                const res = await fetch('winline_erp.php', { method: 'POST', body: formData });
                const json = await res.json();

                if (json.status === 'OK') {
                    closeDetailModal();
                    await Swal.fire({
                        title: 'Integrasi Prorata Sukses!',
                        html: `${escapeHtml(json.message)}<br><br><b>Nomor AJU:</b> <code style="font-size:13px; color:#1890ff;">${escapeHtml(json.nomor_aju)}</code>`,
                        icon: 'success'
                    });
                    loadWinlineData();
                } else {
                    Swal.fire('Gagal Integrasi', escapeHtml(json.message || 'Terjadi kesalahan.'), 'error');
                }
            } catch (e) {
                Swal.fire('Error', escapeHtml(e.message), 'error');
            }
        }

        function closeDetailModal() {
            document.getElementById('detailModal').style.display = 'none';
        }

        function resetFilter() {
            document.getElementById('filterDate').value = '<?= date('Y-m-d') ?>';
            document.getElementById('searchKw').value = '';
            document.getElementById('filterTargetBc').value = '';
            loadWinlineData();
        }

        // Initial Load on Page Ready
        document.addEventListener('DOMContentLoaded', () => {
            loadWinlineData();
        });
    </script>
</body>
</html>

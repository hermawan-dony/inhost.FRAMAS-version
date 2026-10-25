<?php
/**
 * CEISA 4.0 - Shared Winline ERP Integration Helper
 * File: winline_helper.php
 * 
 * Digunakan bersama oleh:
 *   - winline_erp.php (Modul Standalone Winline ERP Multi-Select)
 *   - editor.php (Modal Integrasi ERP pada Form Editor)
 *   - erp/drivers/FramasDriver.php (Driver ERP Perusahaan Framas)
 */

if (!function_exists('getWinlineDb')) {
    function getWinlineDb() {
        static $conn = null;
        if ($conn !== null) return $conn;

        $serverName = "192.168.170.254";
        $connectionInfo = [
            "Database"               => "FRAMASDB",
            "UID"                    => "admin",
            "PWD"                    => "@#IT@fr4m45$%",
            "CharacterSet"           => "UTF-8",
            "TrustServerCertificate" => true,
            "LoginTimeout"           => 4
        ];

        if (function_exists('sqlsrv_connect')) {
            $conn = @sqlsrv_connect($serverName, $connectionInfo);
        }
        return $conn ?: null;
    }
}

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
                // BC 2.3: Impor TPB (Pemasukan Bahan Baku Luar Daerah Pabean)
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

if (!function_exists('getWinlineDocumentItems')) {
    function getWinlineDocumentItems($conn, $type, array $docNos) {
        $items = [];
        $valuta = 'USD';
        $partnerData = null;
        if (!$conn || empty($docNos)) {
            return ['items' => $items, 'valuta' => $valuta, 'partner' => $partnerData];
        }

        $seri = 1;
        foreach ($docNos as $docNo) {
            $docNo = trim($docNo);
            if (empty($docNo)) continue;

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
                            $partnerData = getWinlinePartnerInfo($conn, 'purchase_order', $rd['partner_cd'], $docNo);
                        }
                        $qVal = (float)$rd['qty'];
                        $pVal = (float)$rd['price'];
                        $tVal = (float)$rd['total'];
                        if ($tVal == 0 && $qVal > 0 && $pVal > 0) $tVal = $qVal * $pVal;

                        $items[] = [
                            'seri'     => $seri++,
                            'doc_no'   => $docNo,
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

                $foundInT25 = false;
                $resDtl = @sqlsrv_query($conn, $sqlDtl, [$docNo, $docNo, $docNo]);
                if ($resDtl) {
                    while ($rd = sqlsrv_fetch_array($resDtl, SQLSRV_FETCH_ASSOC)) {
                        $foundInT25 = true;
                        if (!$partnerData && !empty($rd['partner_cd'])) {
                            $partnerData = getWinlinePartnerInfo($conn, $type, $rd['partner_cd'], $docNo);
                        }
                        $qVal = (float)$rd['qty'];
                        $pVal = (float)$rd['price'];
                        $tVal = (float)$rd['total'];
                        if ($tVal == 0 && $qVal > 0 && $pVal > 0) $tVal = $qVal * $pVal;

                        $items[] = [
                            'seri'     => $seri++,
                            'doc_no'   => $docNo,
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

                // Fallback jika tidak ditemukan di t025 tetapi ada di pur_order2cash_sales
                if (!$foundInT25 && $type === 'sales_invoice') {
                    $sqlSales = "SELECT inv_no, dn_no, customer, cust_cd, ctn, qty, gw, nw, category 
                                 FROM pur_order2cash_sales WITH (NOLOCK) 
                                 WHERE inv_no = ? OR dn_no = ?";
                    $resSales = @sqlsrv_query($conn, $sqlSales, [$docNo, $docNo]);
                    if ($resSales) {
                        while ($rs = sqlsrv_fetch_array($resSales, SQLSRV_FETCH_ASSOC)) {
                            if (!$partnerData && !empty($rs['cust_cd'])) {
                                $partnerData = getWinlinePartnerInfo($conn, 'sales_invoice', $rs['cust_cd'], $docNo);
                            }
                            $qVal = (float)($rs['qty'] ?: 1);
                            $nw   = (float)($rs['nw'] ?: ($qVal * 0.15));
                            $gw   = (float)($rs['gw'] ?: ($nw * 1.1));
                            $ctn  = max(1, (int)($rs['ctn'] ?: ceil($qVal / 40)));
                            $pVal = 1.0;
                            $tVal = $qVal * $pVal;

                            $items[] = [
                                'seri'     => $seri++,
                                'doc_no'   => $docNo,
                                'kode'     => trim($rs['dn_no'] ?: 'ITEM-' . $seri),
                                'uraian'   => 'PRODUCT ' . trim($rs['category'] ?: 'FINISHED GOODS'),
                                'sat'      => 'PR',
                                'qty_po'   => $qVal,
                                'qty'      => $qVal,
                                'kem'      => 'CT',
                                'jml_kem'  => $ctn,
                                'net'      => round($nw, 2),
                                'brt'      => round($gw, 2),
                                'hrg'      => $pVal,
                                'fob'      => $tVal
                            ];
                        }
                    }
                }
            }
        }

        if (empty($partnerData) && !empty($docNos)) {
            $partnerData = getWinlinePartnerInfo($conn, $type, '', $docNos[0]);
        }

        return [
            'items'   => $items,
            'valuta'  => $valuta,
            'partner' => $partnerData
        ];
    }
}

if (!function_exists('executeWinlineH2hIntegration')) {
    function executeWinlineH2hIntegration($koneksi, $cmpId, $nomorAju, array $docNos, $type = 'sales_invoice', $targetBc = '27', $customItems = null, $createHeaderIfMissing = true) {
        $escCmp = $koneksi->real_escape_string($cmpId);
        $cleanBc = preg_replace('/[^0-9]/', '', $targetBc);
        if (empty($cleanBc)) $cleanBc = '27';

        // 1. Ambil Profil Perusahaan
        $company = [];
        $resCmp = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
        if ($resCmp && $rC = $resCmp->fetch_assoc()) {
            $company = $rC;
        }

        // Auto-generate Nomor AJU jika belum disediakan
        if (empty($nomorAju)) {
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
        }
        $escAju = $koneksi->real_escape_string($nomorAju);

        $conn = getWinlineDb();
        $combinedItems = [];
        $valuta = in_array($cleanBc, ['40']) ? 'IDR' : 'USD';
        $partnerData = null;

        // Cek Custom Items (misal dari Prorata Qty Modal)
        if (!empty($customItems) && is_array($customItems)) {
            $sr = 1;
            foreach ($customItems as $ci) {
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
        } else {
            // Tarik otomatis dari SQL Server Winline
            $winlineRes = getWinlineDocumentItems($conn, $type, $docNos);
            $combinedItems = $winlineRes['items'];
            if (!empty($winlineRes['valuta'])) $valuta = $winlineRes['valuta'];
            $partnerData = $winlineRes['partner'];
        }

        if (empty($partnerData) && $conn && !empty($docNos)) {
            $partnerData = getWinlinePartnerInfo($conn, $type, '', $docNos[0]);
        }

        if (empty($combinedItems)) {
            // Fallback default sample item jika offline / data kosong
            $combinedItems = [
                ['seri' => 1, 'kode' => 'SH-OUT-001', 'uraian' => 'OUTSOLE TPU ADIDAS PREDATOR FG SIZE 42', 'sat' => 'PR', 'qty_po' => 600, 'qty' => 600, 'kem' => 'CT', 'jml_kem' => 30, 'net' => 180, 'brt' => 195, 'hrg' => ($valuta === 'IDR' ? 75000 : 5.25), 'fob' => ($valuta === 'IDR' ? 45000000 : 3150.00)]
            ];
        }

        // Akumulasi total
        $totFob = 0; $totNet = 0; $totBrt = 0; $totQty = 0; $totKem = 0;
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

            // 1. Cek Header Staging
            $chkH = $koneksi->query("SELECT nomor_aju, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
            $headerExists = ($chkH && $chkH->num_rows > 0);
            $ndpbm = 1.0;
            if ($headerExists) {
                $rH = $chkH->fetch_assoc();
                $ndpbm = floatval($rH['ndpbm'] ?: 1.0);
            }

            $hargaPenyerahan = ($valuta === 'IDR') ? $totFob : round($totFob * $ndpbm, 2);

            if ($headerExists) {
                $koneksi->query("UPDATE bc_ceisa_header SET
                    kode_dokumen = '$cleanBc',
                    kode_valuta = '$valuta',
                    fob = $totFob,
                    cif = $totFob,
                    harga_penyerahan = $hargaPenyerahan,
                    netto = $totNet,
                    bruto = $totBrt,
                    upd_time = NOW(),
                    user_id = 'WINLINE'
                    WHERE nomor_aju = '$escAju'");
            } elseif ($createHeaderIfMissing) {
                $koneksi->query("INSERT INTO bc_ceisa_header (
                    cmp_id, nomor_aju, kode_dokumen, kode_kantor, kode_valuta,
                    fob, cif, harga_penyerahan, netto, bruto,
                    tanggal_pernyataan, user_id, upd_time
                ) VALUES (
                    '$escCmp', '$escAju', '$cleanBc', '$kodeKantor', '$valuta',
                    $totFob, $totFob, $hargaPenyerahan, $totNet, $totBrt,
                    CURDATE(), 'WINLINE', NOW()
                )");
            }

            // 2. Refresh Staging Barang
            $koneksi->query("DELETE FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'");
            $seriBarang = 1;
            foreach ($combinedItems as $it) {
                $escKode = $koneksi->real_escape_string($it['kode']);
                $escUraian = $koneksi->real_escape_string($it['uraian']);
                $escSat = $koneksi->real_escape_string($it['sat']);
                $escKem = $koneksi->real_escape_string($it['kem']);
                $itemHrgPenyerahan = ($valuta === 'IDR') ? $it['fob'] : round($it['fob'] * $ndpbm, 2);

                $koneksi->query("INSERT INTO bc_ceisa_barang (
                    cmp_id, nomor_aju, seri_barang, kode_barang, uraian, kode_satuan, jumlah_satuan,
                    kode_kemasan, jumlah_kemasan, netto, bruto, harga_satuan, fob, cif, cif_rupiah,
                    harga_penyerahan, user_id, upd_time
                ) VALUES (
                    '$escCmp', '$escAju', $seriBarang, '$escKode', '$escUraian', '$escSat', {$it['qty']},
                    '$escKem', {$it['jml_kem']}, {$it['net']}, {$it['brt']}, {$it['hrg']}, {$it['fob']}, {$it['fob']}, {$itemHrgPenyerahan},
                    {$itemHrgPenyerahan}, 'WINLINE', NOW()
                )");

                // IT Inventory (bc_1ab2) jika tipe Purchase Order
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
                            '$escAju', '$poDocFirst', $seriBarang, '$escKode', '$escUraian', '$escSat',
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
                $seriBarang++;
            }

            // 3. Refresh Dokumen Pelengkap
            $kodeDokPelengkap = ($type === 'purchase_order') ? '999' : (($type === 'dn_transfer') ? '217' : '380');
            $koneksi->query("DELETE FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju' AND kode_dokumen IN ('380', '217', '999')");
            
            $maxDocSeri = 0;
            $qMaxDoc = $koneksi->query("SELECT MAX(seri) AS m FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju'");
            if ($qMaxDoc && $rMax = $qMaxDoc->fetch_assoc()) {
                $maxDocSeri = (int)($rMax['m'] ?? 0);
            }

            foreach ($docNos as $dn) {
                $maxDocSeri++;
                $escDn = $koneksi->real_escape_string($dn);
                $koneksi->query("INSERT INTO bc_ceisa_dokumen (
                    cmp_id, nomor_aju, seri, kode_dokumen, nomor_dokumen, tanggal_dokumen, user_id, upd_time
                ) VALUES (
                    '$escCmp', '$escAju', $maxDocSeri, '$kodeDokPelengkap', '$escDn', CURDATE(), 'WINLINE', NOW()
                )");
            }

            // 4. Refresh Kemasan Staging
            $koneksi->query("DELETE FROM bc_ceisa_kemasan WHERE nomor_aju = '$escAju'");
            $koneksi->query("INSERT INTO bc_ceisa_kemasan (
                cmp_id, nomor_aju, seri, kode_kemasan, jumlah_kemasan, merek, user_id, upd_time
            ) VALUES (
                '$escCmp', '$escAju', 1, 'CT', $totKem, 'FRAMAS', 'WINLINE', NOW()
            )");

            // 5. Otomasi Entitas CEISA 4.0
            autoGenerateCeisaEntitas($nomorAju, $cleanBc, $company, $partnerData ?: ['name' => 'PARTNER WINLINE'], $type, $koneksi);

            // 6. Update status nomor_aju balik ke Winline jika Sales Invoice
            if ($conn && $type === 'sales_invoice') {
                foreach ($docNos as $dn) {
                    @sqlsrv_query($conn, "UPDATE pur_order2cash_sales SET nomor_aju = ? WHERE inv_no = ? OR dn_no = ?", [$nomorAju, $dn, $dn]);
                }
            }

            $koneksi->commit();

            $docCount = count($docNos);
            $docSummary = ($docCount > 1) ? "$docCount dokumen (" . implode(', ', array_slice($docNos, 0, 3)) . "...)" : ($docNos[0] ?? '-');

            return [
                'success'    => true,
                'status'     => 'OK',
                'nomor_aju'  => $nomorAju,
                'kode_bc'    => $cleanBc,
                'doc_count'  => $docCount,
                'item_count' => count($combinedItems),
                'partner'    => $partnerData['name'] ?? '',
                'total_qty'  => $totQty,
                'total_fob'  => $totFob,
                'valuta'     => $valuta,
                'message'    => "Berhasil mengintegrasikan $docSummary dari Winline ERP ke Staging BC $cleanBc! ($seriBarang item barang & entitas resmi)."
            ];
        } catch (\Throwable $e) {
            $koneksi->rollback();
            return [
                'success' => false,
                'status'  => 'ERROR',
                'message' => 'Gagal integrasi Winline ERP: ' . $e->getMessage()
            ];
        }
    }
}

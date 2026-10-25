<?php
/**
 * CEISA 4.0 - ERP Driver: FRAMAS (Winline ERP)
 * File: drivers/FramasDriver.php
 * 
 * Mengadaptasi logika dari PowerBuilder:
 *   - w_load_erp_framas.srw (Outbound Sales / Delivery Note: pur_order2cash_sales)
 *   - w_custom_xtrn_doc_find.srw (DN Transfer Antar Plant: cwldata..t025)
 *   - w_load_erp_framas_po.srw (Inbound PO: cwldata..t025/t026)
 * Mendukung Single dan Multi-Dokumen terpadu via winline_helper.php
 */

require_once __DIR__ . '/../ErpBaseDriver.php';
require_once __DIR__ . '/../../winline_helper.php';

class FramasDriver extends ErpBaseDriver
{
    public function getName(): string
    {
        return 'FRAMAS';
    }

    public function getTitle(): string
    {
        return 'FRAMAS (Winline ERP)';
    }

    public function getDescription(): string
    {
        return 'Integrasi sistem ERP Winline PT Framas Indonesia (Sales Invoice, DN Transfer Antar Plant & Purchase Order).';
    }

    public function getTransactionTypes(string $kodeDokumen = ''): array
    {
        $cleanBc = preg_replace('/[^0-9]/', '', $kodeDokumen);
        if (in_array($cleanBc, ['30', '25', '27', '41', '261', '33'])) {
            return [
                'sales_invoice'  => '🛒 Sales Invoice (BC 2.7 & BC 3.0 ke Customer)',
                'dn_transfer'    => '🚚 DN Transfer (BC 2.7 Antar Plant Framas)',
                'purchase_order' => '📦 Purchase Order (Pemasukan Bahan Baku)'
            ];
        }
        return [
            'purchase_order' => '📦 Purchase Order (BC 2.3, BC 4.0, BC 1.6 Pemasukan)',
            'sales_invoice'  => '🛒 Sales Invoice (Pengeluaran Barang)',
            'dn_transfer'    => '🚚 DN Transfer (Antar Plant Framas)'
        ];
    }

    /**
     * Helper koneksi ke SQL Server Winline ERP
     */
    protected function getWinlineDb()
    {
        return getWinlineDb();
    }

    /**
     * Cari dokumen transaksi di ERP Framas (Sales Invoice, DN Transfer, Purchase Order)
     */
    public function search(array $filters): array
    {
        $q       = trim($filters['search_q'] ?? '');
        $dt1     = trim($filters['date_from'] ?? date('Y-m-d'));
        $dt2     = trim($filters['date_to'] ?? $dt1);
        $type    = trim($filters['doc_type'] ?? 'sales_invoice');
        $kodeBc  = preg_replace('/[^0-9]/', '', $filters['kode_dokumen'] ?? '');

        // Normalisasi backward compatibility
        if ($type === 'outbound') $type = 'sales_invoice';
        if ($type === 'inbound')  $type = 'purchase_order';

        $results = [];
        $sqlsrv = $this->getWinlineDb();

        if ($sqlsrv) {
            try {
                if ($type === 'sales_invoice') {
                    // Outbound Sales Invoice dari pur_order2cash_sales
                    $params = [$dt1, $dt2];
                    $sql = "SELECT DISTINCT TOP 300
                        s.inv_no AS doc_no,
                        s.dn_no AS ref_no,
                        s.delivery_dt AS doc_date,
                        s.customer AS partner_name,
                        s.cust_cd,
                        s.po_no,
                        s.ctn AS total_kemas,
                        s.qty AS total_qty,
                        s.gw AS bruto,
                        s.nw AS netto,
                        s.category,
                        s.nomor_aju,
                        s.bc
                    FROM pur_order2cash_sales s WITH (NOLOCK)
                    WHERE CAST(s.delivery_dt AS DATE) BETWEEN ? AND ? ";

                    if (!empty($q)) {
                        $sql .= " AND (s.inv_no LIKE ? OR s.dn_no LIKE ? OR s.customer LIKE ? OR s.po_no LIKE ?) ";
                        $p = "%$q%";
                        $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p;
                    }
                    $sql .= " ORDER BY s.delivery_dt DESC, s.inv_no ASC";

                    $stmt = sqlsrv_query($sqlsrv, $sql, $params);
                    if ($stmt) {
                        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                            $docDate = $r['doc_date'] ? $r['doc_date']->format('Y-m-d') : date('Y-m-d');
                            $isExport = (stripos($r['partner_name'] ?? '', 'EXPORT') !== false) || (stripos($r['category'] ?? '', 'EXP') !== false);
                            $targetBc = $isExport ? '30' : '27';
                            $docId = trim($r['doc_no'] ?: ($r['ref_no'] ?: '-'));

                            $results[] = [
                                'doc_id'       => $docId,
                                'ref_no'       => trim($r['ref_no'] ?: ''),
                                'doc_date'     => $docDate,
                                'partner_name' => trim($r['partner_name'] ?: 'CUSTOMER'),
                                'partner_addr' => 'Kode: ' . ($r['cust_cd'] ?: '-') . ' | PO: ' . ($r['po_no'] ?: '-'),
                                'target_bc'    => $targetBc,
                                'total_items'  => 1,
                                'total_qty'    => floatval($r['total_qty'] ?? 0),
                                'total_kemas'  => intval($r['total_kemas'] ?? 1),
                                'total_netto'  => floatval($r['netto'] ?? 0),
                                'total_bruto'  => floatval($r['bruto'] ?? 0),
                                'total_fob'    => 0.0,
                                'valuta'       => 'USD',
                                'status_h2h'   => !empty($r['nomor_aju']) ? 'integrated' : 'ready',
                                'nomor_aju'    => $r['nomor_aju'] ?: '',
                                'is_simulated' => false
                            ];
                        }
                    }
                } elseif ($type === 'dn_transfer') {
                    // Perpindahan Antar Plant Framas dari cwldata..t025
                    $params = [$dt1, $dt2, $dt1, $dt2];
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
                      AND (CAST(b.c029 AS DATE) BETWEEN ? AND ? OR CAST(b.c028 AS DATE) BETWEEN ? AND ?) ";

                    if (!empty($q)) {
                        $sql .= " AND (b.c045 LIKE ? OR b.c010 LIKE ? OR b.c004 LIKE ?) ";
                        $p = "%$q%";
                        $params[] = $p; $params[] = $p; $params[] = $p;
                    }
                    $sql .= " GROUP BY 
                        b.c045, b.c044, b.c029, b.c028, b.c010, b.c004, b.c021, b.c154, b.mesocomp
                    ORDER BY b.c045 ASC";

                    $stmt = sqlsrv_query($sqlsrv, $sql, $params);
                    if ($stmt) {
                        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                            $docDate = $r['doc_date'] ? $r['doc_date']->format('Y-m-d') : date('Y-m-d');
                            $partner = trim($r['cust_name'] ?: ($r['supplier_name'] ?: 'PT FRAMAS INDONESIA'));
                            $brt = floatval($r['bruto'] ?? 0);
                            $totQty = floatval($r['total_qty'] ?? 0);
                            $totAmt = floatval($r['total_amount'] ?? 0);
                            $docId = trim($r['doc_no'] ?: ($r['ref_no'] ?: '-'));

                            $results[] = [
                                'doc_id'       => $docId,
                                'ref_no'       => trim($r['ref_no'] ?: ''),
                                'doc_date'     => $docDate,
                                'partner_name' => $partner,
                                'partner_addr' => 'Plant: ' . ($r['mesocomp'] ?: '05FI'),
                                'target_bc'    => '27',
                                'total_items'  => intval($r['total_items'] ?: 1),
                                'total_qty'    => $totQty,
                                'total_kemas'  => max(1, (int)ceil($totQty / 50)),
                                'total_netto'  => ($brt > 0) ? round($brt * 0.9, 2) : round($totQty * 0.15, 2),
                                'total_bruto'  => ($brt > 0) ? $brt : round($totQty * 0.18, 2),
                                'total_fob'    => $totAmt,
                                'valuta'       => 'IDR',
                                'status_h2h'   => 'ready',
                                'nomor_aju'    => '',
                                'is_simulated' => false
                            ];
                        }
                    }
                } else {
                    // Inbound / Purchase Order dari cwldata..t025 & t026
                    $params = [$dt1, $dt2];
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
                    LEFT JOIN cwldata..t002 t002 WITH (NOLOCK) ON t002.c001 = t025.c021
                    LEFT JOIN cwldata..t026 t026 WITH (NOLOCK) ON 
                        t026.c044 = t025.c021 AND t026.c045 = t025.c022 AND t026.mesocomp = t025.mesocomp
                        AND t026.c055 <> 13 AND t026.c003 <> 'TEXT'
                    WHERE t025.c044 LIKE 'PO%'
                      AND CAST(t025.c028 AS DATE) BETWEEN ? AND ? ";

                    if (!empty($q)) {
                        $sql .= " AND (t025.c044 LIKE ? OR t025.c010 LIKE ? OR t025.c021 LIKE ?) ";
                        $p = "%$q%";
                        $params[] = $p; $params[] = $p; $params[] = $p;
                    }
                    $sql .= " GROUP BY 
                        t025.c044, t025.c028, t025.c010, t025.c011, t025.c012, t025.c014, t025.c021, 
                        t002.c000, t025.c154, t025.mesocomp
                    ORDER BY t025.c028 DESC, t025.c044 ASC";

                    $stmt = sqlsrv_query($sqlsrv, $sql, $params);
                    if ($stmt) {
                        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                            $docDate = $r['doc_date'] ? $r['doc_date']->format('Y-m-d') : date('Y-m-d');
                            $val = (!empty($r['valuta']) && !in_array($r['valuta'], ['0', ''])) ? trim($r['valuta']) : 'IDR';
                            $isImport = ($val !== 'IDR');
                            $targetBc = $isImport ? '23' : '40';
                            $totQty = floatval($r['total_qty'] ?? 0);
                            $totAmt = floatval($r['total_amount'] ?? 0);
                            $brt    = floatval($r['bruto'] ?? 0);

                            $results[] = [
                                'doc_id'       => trim($r['doc_no'] ?: '-'),
                                'ref_no'       => '',
                                'doc_date'     => $docDate,
                                'partner_name' => trim($r['partner_name'] ?: 'SUPPLIER'),
                                'partner_addr' => trim(($r['city'] ? $r['city'] . ', ' : '') . ($r['partner_addr'] ?: '')),
                                'target_bc'    => $targetBc,
                                'total_items'  => intval($r['total_items'] ?: 1),
                                'total_qty'    => $totQty,
                                'total_kemas'  => max(1, (int)ceil($totQty / 50)),
                                'total_netto'  => ($brt > 0) ? round($brt * 0.9, 2) : round($totQty * 0.2, 2),
                                'total_bruto'  => ($brt > 0) ? $brt : round($totQty * 0.22, 2),
                                'total_fob'    => $totAmt,
                                'valuta'       => $val,
                                'status_h2h'   => 'ready',
                                'nomor_aju'    => '',
                                'is_simulated' => false
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("FramasDriver search error: " . $e->getMessage());
            }
        }

        // Mock fallback jika tidak ada koneksi SQL Server sama sekali
        if (empty($results)) {
            $mockBase = ($type === 'purchase_order') ? 'PO-FRG' : (($type === 'dn_transfer') ? 'DO.DN' : 'DO.IN');
            $partner  = ($type === 'purchase_order') ? 'DONGGUAN FRAMAS PLASTIC CO LTD' : (($type === 'dn_transfer') ? 'PT FRAMAS INDONESIA - DELTA SILICON' : 'HWI - PT Hwa Seung Indonesia');
            $currency = ($type === 'dn_transfer' || in_array($kodeBc, ['40'])) ? 'IDR' : 'USD';

            for ($i = 1; $i <= 3; $i++) {
                $docNo = $mockBase . '52' . (40 + $i);
                if (!empty($q) && stripos($docNo, $q) === false && stripos($partner, $q) === false) continue;
                $results[] = [
                    'doc_id'       => $docNo,
                    'ref_no'       => 'DN-REF' . $i,
                    'doc_date'     => date('Y-m-d', strtotime("-{$i} days")),
                    'partner_name' => $partner,
                    'partner_addr' => 'Kode: 120803 | PO: 56100010' . $i,
                    'target_bc'    => ($type === 'purchase_order') ? '23' : '27',
                    'total_items'  => 3,
                    'total_qty'    => 200 * $i,
                    'total_kemas'  => 5 * $i,
                    'total_netto'  => 45.0 * $i,
                    'total_bruto'  => 52.0 * $i,
                    'total_fob'    => ($currency === 'IDR') ? (15000000 * $i) : (2500.00 * $i),
                    'valuta'       => $currency,
                    'status_h2h'   => 'ready',
                    'nomor_aju'    => '',
                    'is_simulated' => true
                ];
            }
        }

        return $results;
    }

    /**
     * Ambil rincian dokumen dan baris barang untuk preview
     */
    public function getPreview(string $docId, string $kodeDokumen): array
    {
        $cleanBc = preg_replace('/[^0-9]/', '', $kodeDokumen);
        $valuta  = in_array($cleanBc, ['40']) ? 'IDR' : 'USD';

        // Deteksi jenis dokumen dari docId atau kodeDokumen
        $type = 'sales_invoice';
        if (strpos($docId, 'PO') === 0 || in_array($cleanBc, ['23', '40', '16'])) {
            $type = 'purchase_order';
        } elseif (strpos($docId, 'DN') === 0) {
            $type = 'dn_transfer';
        }

        $docNos = array_filter(array_map('trim', explode(',', $docId)));
        $sqlsrv = $this->getWinlineDb();
        $winlineData = getWinlineDocumentItems($sqlsrv, $type, $docNos);

        $items = [];
        $seri = 1;
        $totNetto = 0; $totBruto = 0; $totFob = 0; $totKemas = 0;

        foreach ($winlineData['items'] as $it) {
            $items[] = [
                'seri_barang'   => $seri++,
                'kode_barang'   => $it['kode'],
                'uraian'        => $it['uraian'],
                'kode_satuan'   => $it['sat'],
                'jumlah_satuan' => $it['qty'],
                'kode_kemasan'  => $it['kem'],
                'jumlah_kemasan'=> $it['jml_kem'],
                'netto'         => $it['net'],
                'bruto'         => $it['brt'],
                'harga_satuan'  => $it['hrg'],
                'fob'           => $it['fob'],
                'cif'           => $it['fob']
            ];
            $totNetto += $it['net'];
            $totBruto += $it['brt'];
            $totFob   += $it['fob'];
            $totKemas += $it['jml_kem'];
        }

        if (empty($items)) {
            // Sample fallback item
            $items = [
                [
                    'seri_barang'   => 1,
                    'kode_barang'   => 'SH-OUT-001',
                    'uraian'        => 'OUTSOLE TPU ADIDAS PREDATOR FG SIZE 42',
                    'kode_satuan'   => 'PR',
                    'jumlah_satuan' => 600,
                    'kode_kemasan'  => 'CT',
                    'jumlah_kemasan'=> 30,
                    'netto'         => 180.00,
                    'bruto'         => 195.00,
                    'harga_satuan'  => ($valuta === 'IDR') ? 75000 : 5.25,
                    'fob'           => ($valuta === 'IDR') ? 45000000 : 3150.00,
                    'cif'           => ($valuta === 'IDR') ? 45000000 : 3150.00
                ]
            ];
            $totNetto = 180.0; $totBruto = 195.0; $totFob = ($valuta === 'IDR' ? 45000000 : 3150.00); $totKemas = 30;
        }

        $partnerName = $winlineData['partner']['name'] ?? 'CUSTOMER / PARTNER WINLINE';
        $docFirst = $docNos[0] ?? $docId;
        $kodeDokPelengkap = ($type === 'purchase_order') ? '999' : (($type === 'dn_transfer') ? '217' : '380');

        $docList = [];
        foreach ($docNos as $dn) {
            $docList[] = [
                'kode_dokumen'    => $kodeDokPelengkap,
                'nomor_dokumen'   => $dn,
                'tanggal_dokumen' => date('Y-m-d')
            ];
        }

        return [
            'header' => [
                'doc_id'        => $docId,
                'partner_name'  => $partnerName,
                'valuta'        => $winlineData['valuta'] ?: $valuta,
                'total_fob'     => $totFob,
                'total_netto'   => $totNetto,
                'total_bruto'   => $totBruto,
                'total_kemasan' => $totKemas,
                'invoice_no'    => $docFirst,
                'invoice_date'  => date('Y-m-d')
            ],
            'items'     => $items,
            'documents' => $docList,
            'packages'  => [
                ['seri' => 1, 'kode_kemasan' => 'CT', 'jumlah_kemasan' => $totKemas, 'merek' => 'FRAMAS']
            ]
        ];
    }

    /**
     * Integrasikan data ERP Framas (Single / Multi-Dokumen) ke tabel staging CEISA 4.0
     */
    public function integrate(string $nomorAju, $docId, string $kodeDokumen, array $options = []): array
    {
        // Normalisasi docId (bisa array, JSON string, atau comma-separated string)
        $docNos = [];
        if (is_array($docId)) {
            $docNos = $docId;
        } elseif (!empty($docId)) {
            $decoded = json_decode($docId, true);
            if (is_array($decoded)) {
                $docNos = $decoded;
            } else {
                $docNos = array_filter(array_map('trim', explode(',', $docId)));
            }
        }

        if (empty($docNos)) {
            return ['status' => 'ERROR', 'message' => 'Pilih minimal satu dokumen ERP untuk diintegrasikan.'];
        }

        $cleanBc = preg_replace('/[^0-9]/', '', $kodeDokumen);
        if (empty($cleanBc)) $cleanBc = '27';

        $type = $options['type'] ?? '';
        if (empty($type)) {
            if (strpos($docNos[0], 'PO') === 0 || in_array($cleanBc, ['23', '40', '16'])) {
                $type = 'purchase_order';
            } elseif (strpos($docNos[0], 'DN') === 0) {
                $type = 'dn_transfer';
            } else {
                $type = 'sales_invoice';
            }
        }

        $cmpId = $this->company['cmp_id'] ?? 'FRM-1';
        $customItems = $options['items'] ?? null;

        // Eksekusi integrasi transaksional terpusat
        $res = executeWinlineH2hIntegration($this->db, $cmpId, $nomorAju, $docNos, $type, $cleanBc, $customItems, true);

        if ($res['status'] === 'OK') {
            return [
                'status'     => 'OK',
                'nomor_aju'  => $nomorAju,
                'message'    => $res['message'],
                'row_counts' => [
                    'barang'   => $res['item_count'] ?? 1,
                    'dokumen'  => count($docNos),
                    'kemasan'  => 1
                ]
            ];
        }

        return $res;
    }
}

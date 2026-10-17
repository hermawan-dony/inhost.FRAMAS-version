<?php
/**
 * CEISA 4.0 - Odoo ERP Synchronization Service
 * File: erp/odoo/OdooSyncService.php
 * 
 * Mengintegrasikan data transaksi Odoo ERP via JSON-RPC ke Database Staging CEISA 4.0:
 * - Header Invoices: bc_ceisa_odoo_header
 * - Detail Finished Goods: bc_ceisa_odoo_detail
 * - Raw Materials (BOM): bc_ceisa_odoo_bom
 * 
 * Mengacu pada arsitektur PowerBuilder: w_load_erp_odoo.srw
 * dan backend service: share/rmb/rmb/odoo_sync_out.php
 */

class OdooSyncService
{
    protected $db;
    protected $company;
    protected $odooUrl = 'https://rmb.uduu.io';
    protected $odooDb  = 'rmb';
    protected $odooUser = 'bc_api@rmb.uduu.io';
    protected $odooPass = 'd0eebb836b65ba1ba87fd24746a5792131e44b03';

    public function __construct(mysqli $db, array $company = [])
    {
        $this->db = $db;
        $this->company = $company;

        // Baca kredensial dari bc_ceisa_company jika tersedia
        $conStr = trim($company['ERP_Constring'] ?? '');
        $u = trim($company['ERP_User'] ?? '');
        $p = trim($company['ERP_Password'] ?? '');

        if (!empty($u)) $this->odooUser = $u;
        if (!empty($p)) $this->odooPass = $p;

        if (!empty($conStr)) {
            // Format ConString: url=https://...;db=... atau langsung URL https://...
            if (preg_match('/url=([^;]+)/i', $conStr, $m)) {
                $this->odooUrl = rtrim($m[1], '/');
            } elseif (filter_var($conStr, FILTER_VALIDATE_URL)) {
                $this->odooUrl = rtrim($conStr, '/');
            }
            if (preg_match('/db=([^;]+)/i', $conStr, $m)) {
                $this->odooDb = trim($m[1]);
            }
        }
    }

    /**
     * Helper Eksekusi JSON-RPC ke Server Odoo
     */
    public function odooCall(string $service, string $method, array $args)
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => [
                'service' => $service,
                'method'  => $method,
                'args'    => $args
            ],
            'id'      => mt_rand(1000, 9999)
        ]);

        $ch = curl_init("{$this->odooUrl}/jsonrpc");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception("cURL Error ke Odoo ($this->odooUrl): " . $err);
        }

        $json = json_decode($response, true);
        if (isset($json['error'])) {
            $msg = $json['error']['data']['message'] ?? ($json['error']['message'] ?? 'Odoo RPC Error');
            throw new Exception("Odoo Error: " . $msg);
        }

        return $json['result'] ?? null;
    }

    /**
     * Autentikasi ke Odoo dan kembalikan User ID (UID)
     */
    public function authenticate()
    {
        $uid = $this->odooCall('common', 'authenticate', [
            $this->odooDb,
            $this->odooUser,
            $this->odooPass,
            []
        ]);

        if (!$uid) {
            throw new Exception("Odoo Authentication Failed untuk user {$this->odooUser} pada database {$this->odooDb}");
        }

        return $uid;
    }

    /**
     * Pemetaan Satuan UoM Odoo ke Standar Bea Cukai CEISA 4.0
     */
    public static function mapCeisaUom(?string $uomStr): string
    {
        $uom = strtoupper(trim((string)$uomStr));
        if (strpos($uom, 'SET') !== false) return 'SET';
        if (strpos($uom, 'PCS') !== false || strpos($uom, 'PIECE') !== false || strpos($uom, 'PCE') !== false) return 'PCE';
        if (strpos($uom, 'KG') !== false) return 'KGM';
        if (strpos($uom, 'ROL') !== false) return 'NRL';
        if (strpos($uom, 'SHEET') !== false || strpos($uom, 'SHT') !== false) return 'ST';
        if (strpos($uom, 'BOX') !== false || strpos($uom, 'BX') !== false) return 'BX';
        if (strpos($uom, 'PAC') !== false || strpos($uom, 'PK') !== false) return 'PK';
        if (strpos($uom, 'PAIR') !== false || strpos($uom, 'PASANG') !== false) return 'PR';
        if (strpos($uom, 'METER') !== false || strpos($uom, 'MTR') !== false) return 'MTR';
        return substr($uom, 0, 3) ?: 'PCE';
    }

    /**
     * Pemetaan Kemasan Odoo ke Kode Kemasan Bea Cukai
     */
    public static function mapCeisaKemasan(?string $kemStr): string
    {
        $k = strtoupper(trim((string)$kemStr));
        if (strpos($k, 'PAL') !== false) return 'PX';
        if (strpos($k, 'BOX') !== false) return 'BX';
        if (strpos($k, 'PAC') !== false) return 'PK';
        if (strpos($k, 'CAS') !== false) return 'CS';
        if (strpos($k, 'CAR') !== false) return 'CT';
        return 'PK';
    }

    /**
     * Sinkronisasi Dokumen Pengeluaran (Invoices, Pickings, Lines, BOM)
     * Mengacu pada script backend: odoo_sync_out.php & w_load_erp_odoo.srw
     */
    public function syncOutbound(string $startDate, string $endDate, string $kodeDokumenBc = '25'): array
    {
        $uid = $this->authenticate();

        // 1. Fetch Company Pengirim
        $compRes = $this->odooCall('object', 'execute_kw', [
            $this->odooDb, $uid, $this->odooPass,
            'res.company', 'read',
            [[1]],
            ['fields' => ['name', 'vat']]
        ]);
        $companyData = $compRes[0] ?? [];

        // 2. Fetch Invoices Odoo by Date Range
        $invoices = $this->odooCall('object', 'execute_kw', [
            $this->odooDb, $uid, $this->odooPass,
            'account.move', 'search_read',
            [[
                ['move_type', '=', 'out_invoice'],
                ['state', '=', 'posted'],
                ['invoice_date', '>=', $startDate],
                ['invoice_date', '<=', $endDate]
            ]],
            ['fields' => [
                'name', 'invoice_date', 'partner_id', 'currency_id',
                'amount_untaxed', 'amount_tax', 'amount_total',
                'invoice_line_ids', 'picking_id', 'ref', 'invoice_incoterm_id'
            ]]
        ]);

        if (empty($invoices)) {
            return [
                'status'           => 'success',
                'message'          => "Tidak ada invoice pengeluaran posted pada periode $startDate s/d $endDate",
                'synced_invoices'  => 0,
                'synced_details'   => 0,
                'synced_bom_items' => 0
            ];
        }

        // 3. Batch Collect IDs
        $partnerIds = [];
        $pickingIds = [];
        $allLineIds = [];

        foreach ($invoices as $inv) {
            if (!empty($inv['partner_id']) && is_array($inv['partner_id'])) {
                $partnerIds[] = $inv['partner_id'][0];
            }
            if (!empty($inv['picking_id'])) {
                $pickingIds[] = is_array($inv['picking_id']) ? $inv['picking_id'][0] : $inv['picking_id'];
            }
            if (!empty($inv['invoice_line_ids'])) {
                $allLineIds = array_merge($allLineIds, $inv['invoice_line_ids']);
            }
        }

        $partnerIds = array_values(array_unique(array_filter($partnerIds)));
        $pickingIds = array_values(array_unique(array_filter($pickingIds)));
        $allLineIds = array_values(array_unique(array_filter($allLineIds)));

        // 4. Batch Fetch Partners
        $partnersMap = [];
        if (!empty($partnerIds)) {
            $pRes = $this->odooCall('object', 'execute_kw', [
                $this->odooDb, $uid, $this->odooPass,
                'res.partner', 'read',
                [$partnerIds],
                ['fields' => ['name', 'vat', 'street', 'street2', 'city', 'state_id', 'country_id', 'country_code', 'zip', 'phone']]
            ]);
            if (is_array($pRes)) {
                foreach ($pRes as $p) $partnersMap[$p['id']] = $p;
            }
        }

        // 5. Batch Fetch Pickings (Delivery Surat Jalan)
        $pickingsMap = [];
        if (!empty($pickingIds)) {
            $pickRes = $this->odooCall('object', 'execute_kw', [
                $this->odooDb, $uid, $this->odooPass,
                'stock.picking', 'read',
                [$pickingIds],
                ['fields' => ['name', 'date_done', 'origin', 'vehicle_number', 'expedition_id', 'total_weight_product', 'total_gross_weight_product', 'total_volume_product']]
            ]);
            if (is_array($pickRes)) {
                foreach ($pickRes as $pick) $pickingsMap[$pick['id']] = $pick;
            }
        }

        // 6. Batch Fetch Invoice Lines
        $linesByInvoice = [];
        $productIds = [];
        if (!empty($allLineIds)) {
            $linesRes = $this->odooCall('object', 'execute_kw', [
                $this->odooDb, $uid, $this->odooPass,
                'account.move.line', 'read',
                [$allLineIds],
                ['fields' => ['move_id', 'product_id', 'name', 'quantity', 'product_uom_id', 'price_unit', 'price_subtotal']]
            ]);
            if (is_array($linesRes)) {
                foreach ($linesRes as $l) {
                    if (empty($l['product_id'])) continue;
                    $mId = is_array($l['move_id']) ? $l['move_id'][0] : $l['move_id'];
                    $linesByInvoice[$mId][] = $l;
                    $productIds[] = $l['product_id'][0];
                }
            }
        }

        // 7. Batch Fetch Products & Template IDs
        $productIds = array_values(array_unique(array_filter($productIds)));
        $productsMap = [];
        $productTmplIds = [];
        $hsCodeIds = [];

        if (!empty($productIds)) {
            $prodRes = $this->odooCall('object', 'execute_kw', [
                $this->odooDb, $uid, $this->odooPass,
                'product.product', 'read',
                [$productIds],
                ['fields' => ['default_code', 'default_code_bc', 'name_bc', 'name', 'product_tmpl_id', 'hs_code_id', 'weight', 'volume']]
            ]);
            if (is_array($prodRes)) {
                foreach ($prodRes as $prod) {
                    $productsMap[$prod['id']] = $prod;
                    if (!empty($prod['product_tmpl_id']) && is_array($prod['product_tmpl_id'])) {
                        $productTmplIds[] = $prod['product_tmpl_id'][0];
                    }
                    if (!empty($prod['hs_code_id']) && is_array($prod['hs_code_id'])) {
                        $hsCodeIds[] = $prod['hs_code_id'][0];
                    }
                }
            }
        }

        // 8. Batch Fetch BOMs for Finished Goods
        $bomsMap = [];
        $bomLineIds = [];
        if (!empty($productIds) || !empty($productTmplIds)) {
            $bomsRes = $this->odooCall('object', 'execute_kw', [
                $this->odooDb, $uid, $this->odooPass,
                'mrp.bom', 'search_read',
                [['|', ['product_id', 'in', $productIds], ['product_tmpl_id', 'in', $productTmplIds]]],
                ['fields' => ['product_id', 'product_tmpl_id', 'bom_line_ids']]
            ]);
            if (is_array($bomsRes)) {
                foreach ($bomsRes as $b) {
                    $pKey = !empty($b['product_id']) ? "p_{$b['product_id'][0]}" : "t_{$b['product_tmpl_id'][0]}";
                    $bomsMap[$pKey] = $b;
                    if (!empty($b['bom_line_ids'])) {
                        $bomLineIds = array_merge($bomLineIds, $b['bom_line_ids']);
                    }
                }
            }
        }

        // 9. Batch Fetch BOM Lines & Raw Material Products
        $bomLinesMap = [];
        $rawMaterialIds = [];
        if (!empty($bomLineIds)) {
            $bomLineIds = array_values(array_unique(array_filter($bomLineIds)));
            $blinesRes = $this->odooCall('object', 'execute_kw', [
                $this->odooDb, $uid, $this->odooPass,
                'mrp.bom.line', 'read',
                [$bomLineIds],
                ['fields' => ['bom_id', 'product_id', 'product_qty', 'product_uom_id']]
            ]);
            if (is_array($blinesRes)) {
                foreach ($blinesRes as $bl) {
                    $bId = is_array($bl['bom_id']) ? $bl['bom_id'][0] : $bl['bom_id'];
                    $bomLinesMap[$bId][] = $bl;
                    if (!empty($bl['product_id'])) {
                        $rawMaterialIds[] = $bl['product_id'][0];
                    }
                }
            }
        }

        if (!empty($rawMaterialIds)) {
            $rawMaterialIds = array_values(array_unique(array_filter($rawMaterialIds)));
            $rawProds = $this->odooCall('object', 'execute_kw', [
                $this->odooDb, $uid, $this->odooPass,
                'product.product', 'read',
                [$rawMaterialIds],
                ['fields' => ['default_code', 'default_code_bc', 'name_bc', 'name', 'hs_code_id']]
            ]);
            if (is_array($rawProds)) {
                foreach ($rawProds as $rp) {
                    $productsMap[$rp['id']] = $rp;
                    if (!empty($rp['hs_code_id']) && is_array($rp['hs_code_id'])) {
                        $hsCodeIds[] = $rp['hs_code_id'][0];
                    }
                }
            }
        }

        // 10. Batch Fetch HS Code Rates (Tarif BM, PPN, PPh)
        $hsCodeIds = array_values(array_unique(array_filter($hsCodeIds)));
        $hsMap = [];
        if (!empty($hsCodeIds)) {
            try {
                $hsRes = $this->odooCall('object', 'execute_kw', [
                    $this->odooDb, $uid, $this->odooPass,
                    'djbc.hs.code', 'read',
                    [$hsCodeIds],
                    ['fields' => ['name', 'bm', 'ppn', 'pph']]
                ]);
                if (is_array($hsRes)) {
                    foreach ($hsRes as $hs) $hsMap[$hs['id']] = $hs;
                }
            } catch (\Exception $e) {
                // Abaikan jika model djbc.hs.code belum terinstall di Odoo
            }
        }

        // 11. Simpan Transaksi ke Database Staging MySQL (Alibaba Cloud RDS ceisa0400db)
        $cmpId = trim($this->company['cmp_id'] ?? 'RMB');
        if (empty($cmpId)) $cmpId = 'RMB';

        $this->db->begin_transaction();
        try {
            $stmtHeader = $this->db->prepare("
                INSERT INTO `bc_ceisa_odoo_header` (
                  `invoice_id`, `cmp_id`, `nomor_invoice`, `tanggal_invoice`, `nomor_surat_jalan`, `tanggal_kirim`,
                  `nomor_so`, `plat_kendaraan`, `ekspedisi`, `kode_dokumen_bc`, `kode_kantor_bc`, `kode_tpb`, `kode_tujuan_pengiriman`,
                  `berat_netto_total`, `berat_bruto_total`, `volume_total`,
                  `customer_nama`, `customer_npwp`, `customer_alamat`, `customer_kota`, `customer_provinsi`, `customer_kodepos`, `customer_telepon`, `customer_negara`,
                  `company_nama`, `company_npwp`, `mata_uang`, `incoterm`, `total_fob`, `cif`, `cif_rp`, `total_ppn`, `grand_total`,
                  `nama_vessel`, `pelabuhan_tujuan`, `nomor_bl`
                ) VALUES (
                  ?, ?, ?, ?, ?, ?,
                  ?, ?, ?, ?, '040300', '1', '1',
                  ?, ?, ?,
                  ?, ?, ?, ?, ?, ?, ?, ?,
                  ?, ?, ?, ?, ?, ?, ?, ?, ?,
                  ?, ?, ?
                ) ON DUPLICATE KEY UPDATE
                  `cmp_id` = VALUES(`cmp_id`),
                  `nomor_invoice` = VALUES(`nomor_invoice`), `tanggal_invoice` = VALUES(`tanggal_invoice`),
                  `nomor_surat_jalan` = VALUES(`nomor_surat_jalan`), `tanggal_kirim` = VALUES(`tanggal_kirim`),
                  `nomor_so` = VALUES(`nomor_so`), `plat_kendaraan` = VALUES(`plat_kendaraan`), `ekspedisi` = VALUES(`ekspedisi`),
                  `kode_dokumen_bc` = VALUES(`kode_dokumen_bc`),
                  `berat_netto_total` = VALUES(`berat_netto_total`), `berat_bruto_total` = VALUES(`berat_bruto_total`), `volume_total` = VALUES(`volume_total`),
                  `customer_nama` = VALUES(`customer_nama`), `customer_npwp` = VALUES(`customer_npwp`), `customer_alamat` = VALUES(`customer_alamat`),
                  `customer_kota` = VALUES(`customer_kota`), `customer_provinsi` = VALUES(`customer_provinsi`), `customer_kodepos` = VALUES(`customer_kodepos`),
                  `customer_telepon` = VALUES(`customer_telepon`), `customer_negara` = VALUES(`customer_negara`),
                  `total_fob` = VALUES(`total_fob`), `cif` = VALUES(`cif`), `cif_rp` = VALUES(`cif_rp`),
                  `total_ppn` = VALUES(`total_ppn`), `grand_total` = VALUES(`grand_total`),
                  `nama_vessel` = VALUES(`nama_vessel`), `pelabuhan_tujuan` = VALUES(`pelabuhan_tujuan`), `nomor_bl` = VALUES(`nomor_bl`)
            ");

            $stmtDelDetail = $this->db->prepare("DELETE FROM `bc_ceisa_odoo_detail` WHERE `invoice_id` = ?");
            $stmtDelBom    = $this->db->prepare("DELETE FROM `bc_ceisa_odoo_bom` WHERE `invoice_id` = ?");

            $stmtDetail = $this->db->prepare("
                INSERT INTO `bc_ceisa_odoo_detail` (
                  `invoice_id`, `cmp_id`, `seri_barang`, `product_id`, `kode_barang`, `nama_barang`, `hs_code`,
                  `tarif_bm_persen`, `tarif_ppn_persen`, `tarif_pph_persen`,
                  `nilai_bm`, `nilai_ppn`, `nilai_pph`,
                  `jumlah`, `satuan`, `jumlah_kemasan`, `harga_satuan`, `subtotal_fob`, `berat_netto`, `volume`
                ) VALUES (
                  ?, ?, ?, ?, ?, ?, ?,
                  ?, ?, ?,
                  ?, ?, ?,
                  ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmtBom = $this->db->prepare("
                INSERT INTO `bc_ceisa_odoo_bom` (
                  `invoice_id`, `cmp_id`, `product_jadi_id`, `kode_barang_jadi`, `nama_barang_jadi`, `jumlah_barang_jadi`,
                  `product_bahan_id`, `kode_bahan_baku`, `nama_bahan_baku`, `hs_code_bahan_baku`,
                  `jumlah_per_unit`, `total_kebutuhan`, `satuan_bahan_baku`
                ) VALUES (
                  ?, ?, ?, ?, ?, ?,
                  ?, ?, ?, ?,
                  ?, ?, ?
                )
            ");

            $countInvoices = 0;
            $countDetails  = 0;
            $countBoms     = 0;

            foreach ($invoices as $inv) {
                $invId      = (int)$inv['id'];
                $customerId = is_array($inv['partner_id'] ?? null) ? $inv['partner_id'][0] : null;
                $partner    = $customerId ? ($partnersMap[$customerId] ?? []) : [];

                $pickId     = !empty($inv['picking_id']) ? (is_array($inv['picking_id']) ? $inv['picking_id'][0] : $inv['picking_id']) : null;
                $picking    = $pickId ? ($pickingsMap[$pickId] ?? []) : [];

                $cityName   = is_array($partner['city'] ?? null) ? $partner['city'][1] : ($partner['city'] ?? '');
                $stateName  = is_array($partner['state_id'] ?? null) ? $partner['state_id'][1] : ($partner['state_id'] ?? '');
                $expedition = is_array($picking['expedition_id'] ?? null) ? $picking['expedition_id'][1] : ($picking['expedition_id'] ?? '');

                $fobVal     = floatval($inv['amount_untaxed'] ?? 0);
                $nomorInv   = (string)($inv['name'] ?? '');
                $tglInv     = (string)($inv['invoice_date'] ?? date('Y-m-d'));
                $sjNo       = (string)($picking['name'] ?? '');
                $tglKirim   = !empty($picking['date_done']) ? $picking['date_done'] : date('Y-m-d H:i:s');
                $soNo       = (string)(!empty($picking['origin']) ? $picking['origin'] : ($inv['ref'] ?? ''));
                $vehicle    = (string)($picking['vehicle_number'] ?? '');
                $cleanDocBc = preg_replace('/[^0-9]/', '', $kodeDokumenBc) ?: '25';

                $nettoTot   = floatval($picking['total_weight_product'] ?? 0);
                $brutoTot   = floatval($picking['total_gross_weight_product'] ?? 0);
                $volTot     = floatval($picking['total_volume_product'] ?? 0);

                $custNama   = (string)($partner['name'] ?? '');
                $custNpwp   = (string)($partner['vat'] ?? '');
                $custAlamat = trim(($partner['street'] ?? '') . ' ' . ($partner['street2'] ?? ''));
                $custZip    = (string)($partner['zip'] ?? '');
                $custPhone  = (string)($partner['phone'] ?? '');
                $custNegara = (string)($partner['country_code'] ?? (is_array($partner['country_id'] ?? null) ? $partner['country_id'][1] : 'ID'));

                $compNama   = (string)($companyData['name'] ?? 'Roda Maju Bahagia, PT');
                $compNpwp   = (string)($companyData['vat'] ?? '0801677725044000');
                $currVal    = is_array($inv['currency_id'] ?? null) ? $inv['currency_id'][1] : 'IDR';
                $incoVal    = is_array($inv['invoice_incoterm_id'] ?? null) ? $inv['invoice_incoterm_id'][1] : 'FOB';
                $taxVal     = floatval($inv['amount_tax'] ?? 0);
                $totalVal   = floatval($inv['amount_total'] ?? 0);
                $pelabuhan  = '';

                $stmtHeader->bind_param(
                    "isssssssssdddssssssssssssdddddsss",
                    $invId, $cmpId, $nomorInv, $tglInv, $sjNo, $tglKirim,
                    $soNo, $vehicle, $expedition, $cleanDocBc,
                    $nettoTot, $brutoTot, $volTot,
                    $custNama, $custNpwp, $custAlamat, $cityName, $stateName, $custZip, $custPhone, $custNegara,
                    $compNama, $compNpwp, $currVal, $incoVal, $fobVal, $fobVal, $fobVal, $taxVal, $totalVal,
                    $vehicle, $pelabuhan, $sjNo
                );
                $stmtHeader->execute();
                $countInvoices++;

                // Hapus detail & BOM lama sebelum insert ulang (smart upsert)
                $stmtDelDetail->bind_param("i", $invId);
                $stmtDelDetail->execute();

                $stmtDelBom->bind_param("i", $invId);
                $stmtDelBom->execute();

                $invLines = $linesByInvoice[$invId] ?? [];
                $seri = 1;
                foreach ($invLines as $line) {
                    $prodId = $line['product_id'][0];
                    $prod   = $productsMap[$prodId] ?? [];

                    $defCodeBc = $prod['default_code_bc'] ?? null;
                    $defCode   = $prod['default_code'] ?? null;
                    $nameBc    = $prod['name_bc'] ?? null;
                    $hsCodeVal = $prod['hs_code_id'] ?? null;

                    $hsObjId   = is_array($hsCodeVal) ? $hsCodeVal[0] : null;
                    $hsRec     = $hsObjId ? ($hsMap[$hsObjId] ?? null) : null;

                    $kodeBarang = ($defCodeBc && $defCodeBc !== false) ? $defCodeBc : (($defCode && $defCode !== false) ? $defCode : '');
                    $namaBarang = ($nameBc && $nameBc !== false) ? $nameBc : (isset($line['name']) ? $line['name'] : ($prod['name'] ?? ''));
                    $hsCode     = is_array($hsCodeVal) ? $hsCodeVal[1] : '';

                    $qtyJadi    = floatval($line['quantity'] ?? 0);
                    $satuan     = self::mapCeisaUom(is_array($line['product_uom_id'] ?? null) ? $line['product_uom_id'][1] : '');
                    $jmlKemas   = 1;
                    $hrgSat     = floatval($line['price_unit'] ?? 0);
                    $subFob     = floatval($line['price_subtotal'] ?? 0);
                    $brtNetto   = floatval($prod['weight'] ?? 0) * $qtyJadi;
                    $volBarang  = floatval($prod['volume'] ?? 0) * $qtyJadi;

                    $bmPct      = floatval($hsRec['bm'] ?? 0);
                    $ppnPct     = floatval($hsRec['ppn'] ?? 11);
                    $pphPct     = floatval($hsRec['pph'] ?? 0);

                    $nilaiBm    = ($subFob * $bmPct) / 100.0;
                    $nilaiPpn   = ($subFob * $ppnPct) / 100.0;
                    $nilaiPph   = ($subFob * $pphPct) / 100.0;

                    $stmtDetail->bind_param(
                        "isiisssdddddddsidddd",
                        $invId, $cmpId, $seri, $prodId, $kodeBarang, $namaBarang, $hsCode,
                        $bmPct, $ppnPct, $pphPct,
                        $nilaiBm, $nilaiPpn, $nilaiPph,
                        $qtyJadi, $satuan, $jmlKemas, $hrgSat, $subFob, $brtNetto, $volBarang
                    );
                    $stmtDetail->execute();
                    $countDetails++;
                    $seri++;

                    // Expand BOM (Bahan Baku)
                    $tmplId = is_array($prod['product_tmpl_id'] ?? null) ? $prod['product_tmpl_id'][0] : null;
                    $bomRec = $bomsMap["p_{$prodId}"] ?? ($tmplId ? ($bomsMap["t_{$tmplId}"] ?? null) : null);

                    if ($bomRec && !empty($bomRec['id'])) {
                        $bId    = $bomRec['id'];
                        $bLines = $bomLinesMap[$bId] ?? [];
                        foreach ($bLines as $bl) {
                            if (empty($bl['product_id'])) continue;
                            $rprodId = $bl['product_id'][0];
                            $rprod   = $productsMap[$rprodId] ?? [];

                            $rdefCodeBc = $rprod['default_code_bc'] ?? null;
                            $rdefCode   = $rprod['default_code'] ?? null;
                            $rnameBc    = $rprod['name_bc'] ?? null;
                            $rhsCodeVal = $rprod['hs_code_id'] ?? null;

                            $rkodeBahan = ($rdefCodeBc && $rdefCodeBc !== false) ? $rdefCodeBc : (($rdefCode && $rdefCode !== false) ? $rdefCode : '');
                            $rnamaBahan = ($rnameBc && $rnameBc !== false) ? $rnameBc : ($rprod['name'] ?? '');
                            $rhsCode    = is_array($rhsCodeVal) ? $rhsCodeVal[1] : '';

                            $koefBom    = floatval($bl['product_qty'] ?? 0);
                            $totKebut   = $qtyJadi * $koefBom;
                            $satBahan   = self::mapCeisaUom(is_array($bl['product_uom_id'] ?? null) ? $bl['product_uom_id'][1] : '');

                            $stmtBom->bind_param(
                                "isissdisssdds",
                                $invId, $cmpId, $prodId, $kodeBarang, $namaBarang, $qtyJadi,
                                $rprodId, $rkodeBahan, $rnamaBahan, $rhsCode,
                                $koefBom, $totKebut, $satBahan
                            );
                            $stmtBom->execute();
                            $countBoms++;
                        }
                    }
                }
            }

            $stmtHeader->close();
            $stmtDelDetail->close();
            $stmtDelBom->close();
            $stmtDetail->close();
            $stmtBom->close();

            $this->db->commit();

            return [
                'status'           => 'success',
                'message'          => "OK - Sinkronisasi Odoo ke MySQL Berhasil ($countInvoices Invoice)",
                'period'           => "$startDate s/d $endDate",
                'synced_invoices'  => $countInvoices,
                'synced_details'   => $countDetails,
                'synced_bom_items' => $countBoms
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}

<?php
/**
 * CEISA 4.0 - ERP Driver: TPBERP (TPB & Kawasan Berikat Inventory ERP)
 * File: drivers/TpberpDriver.php
 * 
 * Integrasi dokumen keluar/masuk khusus kawasan berikat (TPB ERP),
 * disesuaikan dengan laporan IT Inventory Bea Cukai (1A, 1B, BC 2.3, BC 2.5, BC 4.0, BC 2.7, dll).
 */

require_once __DIR__ . '/../ErpBaseDriver.php';

class TpberpDriver extends ErpBaseDriver
{
    public function getName(): string
    {
        return 'TPBERP';
    }

    public function getTitle(): string
    {
        return 'TPBERP (Kawasan Berikat & IT Inventory ERP)';
    }

    public function getDescription(): string
    {
        return 'Konektor integrasi transaksi staging TPB ERP untuk otomatisasi dokumen Pabean dan IT Inventory.';
    }

    public function search(array $filters): array
    {
        $q       = trim($filters['search_q'] ?? '');
        $type    = trim($filters['doc_type'] ?? 'outbound');
        $kodeBc  = preg_replace('/[^0-9]/', '', $filters['kode_dokumen'] ?? '');

        $currency = in_array($kodeBc, ['40', '41']) ? 'IDR' : 'USD';
        $results = [];

        $prefix = ($type === 'outbound') ? 'TPB-OUT-' : 'TPB-IN-';
        $partner = ($type === 'outbound') ? 'PT. KAWASAN BERIKAT LOGISTIK' : 'PT. SUPPLIER BAHAN BAKU UTAMA';

        for ($i = 1; $i <= 3; $i++) {
            $docNo = $prefix . date('Ym') . sprintf('%03d', $i);
            if (!empty($q) && stripos($docNo, $q) === false && stripos($partner, $q) === false) {
                continue;
            }
            $results[] = [
                'doc_id'       => $docNo,
                'doc_date'     => date('Y-m-d', strtotime("-{$i} days")),
                'partner_name' => $partner,
                'total_items'  => 1,
                'total_netto'  => 450.00 * $i,
                'total_bruto'  => 480.00 * $i,
                'total_fob'    => ($currency === 'IDR') ? (35000000 * $i) : (4200.00 * $i),
                'valuta'       => $currency,
                'is_simulated' => true
            ];
        }

        return $results;
    }

    public function getPreview(string $docId, string $kodeDokumen): array
    {
        $cleanBc = preg_replace('/[^0-9]/', '', $kodeDokumen);
        $valuta  = in_array($cleanBc, ['40', '41']) ? 'IDR' : 'USD';

        $items = [
            [
                'seri_barang'   => 1,
                'kode_barang'   => 'TPB-RAW-001',
                'uraian'        => 'STEEL COIL COLD ROLLED SHEET 0.8MM X 1219MM',
                'kode_satuan'   => 'KGM',
                'jumlah_satuan' => 12,
                'kode_kemasan'  => 'CL',
                'jumlah_kemasan'=> 12,
                'netto'         => 450.00,
                'bruto'         => 480.00,
                'harga_satuan'  => ($valuta === 'IDR') ? 2900000 : 350.00,
                'fob'           => ($valuta === 'IDR') ? 34800000 : 4200.00,
                'cif'           => 0
            ]
        ];

        $totNetto = 0; $totBruto = 0; $totFob = 0; $totKemas = 0;
        foreach ($items as $it) {
            $totNetto += $it['netto'];
            $totBruto += $it['bruto'];
            $totFob   += $it['fob'];
            $totKemas += $it['jumlah_kemasan'];
        }

        return [
            'header' => [
                'doc_id'        => $docId,
                'partner_name'  => 'PT. KAWASAN BERIKAT LOGISTIK',
                'valuta'        => $valuta,
                'total_fob'     => $totFob,
                'total_netto'   => $totNetto,
                'total_bruto'   => $totBruto,
                'total_kemasan' => $totKemas,
                'invoice_no'    => 'INV-' . $docId,
                'invoice_date'  => date('Y-m-d')
            ],
            'items' => $items,
            'documents' => [
                ['kode_dokumen' => '380', 'nomor_dokumen' => 'INV-' . $docId, 'tanggal_dokumen' => date('Y-m-d')],
                ['kode_dokumen' => '217', 'nomor_dokumen' => $docId, 'tanggal_dokumen' => date('Y-m-d')]
            ],
            'packages' => [
                ['seri' => 1, 'kode_kemasan' => 'CL', 'jumlah_kemasan' => $totKemas, 'merek' => 'TPBERP']
            ]
        ];
    }

    public function integrate(string $nomorAju, string $docId, string $kodeDokumen, array $options = []): array
    {
        $preview = $this->getPreview($docId, $kodeDokumen);
        $hdr     = $preview['header'];
        $items   = $preview['items'];
        $docs    = $preview['documents'];
        $pkgs    = $preview['packages'];

        $escAju  = $this->db->real_escape_string($nomorAju);
        $ndpbm   = 1.0;
        $qH = $this->db->query("SELECT ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if ($qH && $rH = $qH->fetch_assoc()) {
            $ndpbm = floatval($rH['ndpbm'] ?: 1.0);
        }

        $this->db->begin_transaction();
        try {
            $fob      = floatval($hdr['total_fob']);
            $fobIdr   = ($hdr['valuta'] === 'IDR') ? $fob : ($fob * $ndpbm);
            $netto    = floatval($hdr['total_netto']);
            $bruto    = floatval($hdr['total_bruto']);
            $valuta   = $this->db->real_escape_string($hdr['valuta']);

            $this->db->query("UPDATE bc_ceisa_header SET kode_valuta = '$valuta', fob = $fob, harga_penyerahan = $fobIdr, netto = $netto, bruto = $bruto WHERE nomor_aju = '$escAju'");
            $this->db->query("DELETE FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'");

            $seri = 1;
            foreach ($items as $item) {
                $jmlSat = floatval($item['jumlah_satuan']);
                $hrgSat = floatval($item['harga_satuan']);
                $itemFob = floatval($item['fob']);
                $bData = [
                    'nomor_aju'        => $nomorAju,
                    'seri_barang'      => $seri,
                    'kode_barang'      => $item['kode_barang'],
                    'uraian'           => $item['uraian'],
                    'kode_satuan'      => $this->mapUom($item['kode_satuan']),
                    'jumlah_satuan'    => $jmlSat,
                    'kode_kemasan'     => $this->mapPackage($item['kode_kemasan']),
                    'jumlah_kemasan'   => intval($item['jumlah_kemasan']),
                    'netto'            => floatval($item['netto']),
                    'bruto'            => floatval($item['bruto']),
                    'ndpbm'            => $ndpbm,
                    'harga_satuan'     => $hrgSat,
                    'fob'              => $itemFob,
                    'cif'              => 0,
                    'cif_rupiah'       => 0,
                    'harga_penyerahan' => ($valuta === 'IDR') ? $itemFob : ($itemFob * $ndpbm),
                    'harga_perolehan'  => 0,
                    'kode_asal_barang' => '1'
                ];
                $this->insertStagingRow('bc_ceisa_barang', $bData);
                $seri++;
            }

            $this->db->query("DELETE FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju' AND kode_dokumen IN ('380', '217')");
            $maxSeriDoc = 0;
            $qDoc = $this->db->query("SELECT MAX(seri) AS m FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju'");
            if ($qDoc && $rDoc = $qDoc->fetch_assoc()) $maxSeriDoc = intval($rDoc['m'] ?? 0);
            foreach ($docs as $d) {
                $maxSeriDoc++;
                $dData = [
                    'nomor_aju'       => $nomorAju,
                    'seri'            => $maxSeriDoc,
                    'kode_dokumen'    => $d['kode_dokumen'],
                    'nomor_dokumen'   => $d['nomor_dokumen'],
                    'tanggal_dokumen' => $d['tanggal_dokumen']
                ];
                $this->insertStagingRow('bc_ceisa_dokumen', $dData);
            }

            $this->db->query("DELETE FROM bc_ceisa_kemasan WHERE nomor_aju = '$escAju'");
            $seriKemas = 1;
            foreach ($pkgs as $p) {
                $pData = [
                    'nomor_aju'       => $nomorAju,
                    'seri'            => $seriKemas,
                    'kode_kemasan'    => $p['kode_kemasan'],
                    'jumlah_kemasan'  => intval($p['jumlah_kemasan']),
                    'merek'           => $p['merek'] ?? 'TPBERP'
                ];
                $this->insertStagingRow('bc_ceisa_kemasan', $pData);
                $seriKemas++;
            }

            $this->db->commit();
            return [
                'status'     => 'OK',
                'message'    => "Berhasil mengintegrasikan data dari TPBERP ($docId) ke Staging Dokumen AJU!",
                'row_counts' => ['barang' => count($items), 'dokumen' => count($docs), 'kemasan' => count($pkgs)]
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['status' => 'ERROR', 'message' => 'Gagal mengintegrasikan data TPBERP: ' . $e->getMessage()];
        }
    }
}

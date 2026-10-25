<?php
/**
 * CEISA 4.0 LNSW JSON Document Parser Engine (CeisaJsonDocParser.php)
 * Mengonversi respon JSON resmi CEISA 4.0 LNSW endpoint:
 * https://apis-gw.beacukai.go.id/v2/report-parser/v1/lnsw/get-data-doc/td-header?idHeader={idHeader}
 * ke struktur 21 sheet standar CEISA 4.0 untuk:
 * 1. Diimpor langsung ke database staging MySQL (bc_ceisa_*) via CeisaXlsxImporter
 * 2. Dikonversi ke file Excel resmi (.xlsx) 21 Sheet via CeisaXlsxExporter
 */

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/CeisaXlsxExporter.php';
require_once __DIR__ . '/CeisaXlsxImporter.php';

class CeisaJsonDocParser {

    /**
     * Helper untuk format tanggal dari ISO 8601 (misal 2026-10-23T00:00:00.000+07:00) ke format MySQL (YYYY-MM-DD atau YYYY-MM-DD HH:ii:ss)
     */
    public static function formatDate($val, $withTime = false) {
        if (empty($val)) return null;
        $valTrim = trim($val);
        $time = strtotime($valTrim);
        if (!$time) return null;
        return $withTime ? date('Y-m-d H:i:s', $time) : date('Y-m-d', $time);
    }

    /**
     * Mengambil JSON dokumen langsung dari API resmi LNSW Bea Cukai via initMFA
     */
    public static function fetchLnswDoc($idHeader, $cmpId = '') {
        $cleanIdHeader = trim($idHeader);
        if (empty($cleanIdHeader)) {
            throw new Exception("idHeader tidak boleh kosong.");
        }

        global $koneksi;
        if (empty($cmpId) && $koneksi) {
            $escId = $koneksi->real_escape_string($cleanIdHeader);
            $q = $koneksi->query("SELECT cmp_id FROM bc_ceisa_data WHERE idHeader = '{$escId}' LIMIT 1");
            if ($q && $r = $q->fetch_assoc()) {
                $cmpId = $r['cmp_id'];
            }
        }
        if (empty($cmpId) && !empty($_SESSION['company_cmp_id'])) {
            $cmpId = $_SESSION['company_cmp_id'];
        }

        $targetCmp = $cmpId;
        $cmp_id = $targetCmp;
        $_GET['cmp_id'] = $targetCmp;
        $tokenCacheFile = sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $targetCmp ?: 'default') . '.json';
        require __DIR__ . '/initmfa.php';

        $activeToken = function_exists('get_cached_mfatoken') ? get_cached_mfatoken($targetCmp, $tokenCacheFile) : ($activeToken ?? '');

        if (empty($activeToken)) {
            throw new Exception("Gagal mendapatkan Token MFA aktif untuk perusahaan {$targetCmp}. Silakan periksa kredensial MFA di Kelola Perusahaan.");
        }

        // Endpoint resmi CEISA 4.0 Report Parser LNSW (/td-header)
        $url = "https://apis-gw.beacukai.go.id/v2/report-parser/v1/lnsw/get-data-doc/td-header?idHeader=" . urlencode($cleanIdHeader);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => "", // Otomatis decompress gzip/deflate
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $activeToken,
                'beacukai-api-key: 6222a75e-1dbb-493e-9461-27f721097e9c',
                'Accept: application/json, text/plain, */*',
                'Origin: https://portal.beacukai.go.id',
                'Referer: https://portal.beacukai.go.id/',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'
            ]
        ]);
        $respJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // Jika token kedaluwarsa (401 / 403), hapus cache dan coba minta token baru 1x
        if ($httpCode === 401 || $httpCode === 403) {
            @unlink($tokenCacheFile);
            unset($_SESSION["mfatoken_" . $targetCmp]);
            unset($_SESSION["mfatoken_created_at_" . $targetCmp]);
            require __DIR__ . '/initmfa.php';
            $activeToken = function_exists('get_cached_mfatoken') ? get_cached_mfatoken($targetCmp, $tokenCacheFile) : ($activeToken ?? '');
            if (!empty($activeToken)) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_ENCODING => "",
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $activeToken,
                        'beacukai-api-key: 6222a75e-1dbb-493e-9461-27f721097e9c',
                        'Accept: application/json, text/plain, */*',
                        'Origin: https://portal.beacukai.go.id',
                        'Referer: https://portal.beacukai.go.id/',
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'
                    ]
                ]);
                $respJson = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);
            }
        }

        if ($curlErr) {
            throw new Exception("cURL Error ke API LNSW: " . $curlErr);
        }

        if ($httpCode !== 200 || empty($respJson)) {
            throw new Exception("API LNSW mengembalikan kode HTTP {$httpCode}. Respon: " . substr((string)$respJson, 0, 300));
        }

        $docArr = json_decode($respJson, true);
        if (!$docArr || empty($docArr['data'])) {
            $msg = $docArr['message'] ?? 'Data dokumen tidak ditemukan pada API LNSW CEISA 4.0';
            throw new Exception("LNSW: " . $msg);
        }

        return $docArr;
    }

    /**
     * Konversi Payload JSON LNSW ke struktur associative array $ajuData (21 Sheet standar CEISA 4.0)
     */
    public static function parseToAjuData($jsonPayload) {
        $raw = is_array($jsonPayload) ? $jsonPayload : json_decode($jsonPayload, true);
        if (!$raw) {
            throw new Exception("Format JSON tidak valid atau gagal didecode.");
        }

        $d = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;

        $nomorAju = trim($d['nomorAju'] ?? '');
        if (empty($nomorAju)) {
            throw new Exception("Nomor Pengajuan (nomorAju) tidak ditemukan dalam payload JSON.");
        }

        $kodeDokumen = '';
        if (!empty($d['kodeDokumen'])) {
            $kodeDokumen = (string)$d['kodeDokumen'];
        } elseif (preg_match('/^\d{4}(\d{2})/', $nomorAju, $m)) {
            $kodeDokumen = $m[1];
        }

        // Ambil fallback nomor_daftar & tanggal_daftar dari bc_ceisa_data jika di JSON tidak tersedia
        $nomorDaftar = !empty($d['nomorDaftar']) ? $d['nomorDaftar'] : null;
        $tanggalDaftar = self::formatDate($d['tanggalDaftar'] ?? null);
        if (empty($nomorDaftar) && !empty($nomorAju)) {
            global $koneksi;
            if ($koneksi) {
                $escA = $koneksi->real_escape_string($nomorAju);
                $qD = $koneksi->query("SELECT nomorDaftar, tanggalDaftar FROM bc_ceisa_data WHERE nomorAju = '{$escA}' LIMIT 1");
                if ($qD && $rD = $qD->fetch_assoc()) {
                    if (!empty($rD['nomorDaftar'])) $nomorDaftar = $rD['nomorDaftar'];
                    if (empty($tanggalDaftar) && !empty($rD['tanggalDaftar'])) $tanggalDaftar = self::formatDate($rD['tanggalDaftar']);
                }
            }
        }

        // Siapkan kerangka 21 sheet
        $ajuData = [];
        foreach (CeisaXlsxExporter::$sheetTableMap as $sheet => $tbl) {
            $ajuData[$sheet] = [];
        }

        // 1. HEADER (bc_ceisa_header)
        $ajuData['HEADER'][] = [
            'nomor_aju'                => $nomorAju,
            'kode_dokumen'             => $kodeDokumen,
            'nomor_daftar'             => $nomorDaftar,
            'tanggal_daftar'           => $tanggalDaftar,
            'kode_kantor'              => $d['kodeKantor'] ?? null,
            'kode_kantor_bongkar'      => $d['kodeKantorBongkar'] ?? null,
            'kode_kantor_muat'         => $d['kodeKantorMuat'] ?? null,
            'kode_kantor_tujuan'       => $d['kodeKantorTujuan'] ?? null,
            'kode_kantor_ekspor'       => $d['kodeKantorEkspor'] ?? null,
            'kode_kantor_periksa'      => $d['kodeKantorPeriksa'] ?? null,
            'kode_jenis_impor'         => $d['kodeJenisImpor'] ?? null,
            'kode_jenis_ekspor'        => $d['kodeJenisEkspor'] ?? null,
            'kode_jenis_tpb'           => $d['kodeJenisTpb'] ?? null,
            'kode_jenis_plb'           => $d['kodeJenisPlb'] ?? null,
            'kode_jenis_prosedur'      => $d['kodeJenisProsedur'] ?? null,
            'kode_tujuan_pemasukan'    => $d['kodeTujuanPemasukan'] ?? null,
            'kode_tujuan_pengiriman'   => $d['kodeTujuanPengiriman'] ?? null,
            'kode_tujuan_tpb'          => $d['kodeTujuanTpb'] ?? null,
            'kode_cara_dagang'         => $d['kodeCaraDagang'] ?? null,
            'kode_cara_bayar'          => $d['kodeCaraBayar'] ?? null,
            'kode_cara_bayar_lainnya'  => $d['kodeCaraBayarLainnya'] ?? null,
            'kode_gudang_asal'         => $d['kodeGudangAsal'] ?? null,
            'kode_gudang_tujuan'       => $d['kodeGudangTujuan'] ?? null,
            'kode_jenis_pengiriman'    => $d['kodeJenisPengiriman'] ?? null,
            'kode_kategori_ekspor'     => $d['kodeKategoriEkspor'] ?? null,
            'kode_lokasi'              => $d['kodeLokasi'] ?? null,
            'kode_tutup_pu'            => $d['kodeTutupPu'] ?? null,
            'nomor_bc11'               => $d['nomorBc11'] ?? null,
            'tanggal_bc11'             => self::formatDate($d['tanggalBc11'] ?? null),
            'nomor_pos'                => $d['posBc11'] ?? null,
            'nomor_sub_pos'            => $d['subposBc11'] ?? null,
            'kode_pelabuhan_bongkar'   => $d['kodePelBongkar'] ?? null,
            'kode_pelabuhan_muat'      => $d['kodePelMuat'] ?? null,
            'kode_pelabuhan_transit'   => $d['kodePelTransit'] ?? null,
            'kode_pelabuhan_tujuan'    => $d['kodePelTujuan'] ?? null,
            'kode_pelabuhan_ekspor'    => $d['kodePelEkspor'] ?? null,
            'kode_tps'                 => $d['kodeTps'] ?? null,
            'tanggal_ekspor'           => self::formatDate($d['tanggalEkspor'] ?? null),
            'tanggal_tiba'             => self::formatDate($d['tanggalTiba'] ?? null),
            'tanggal_periksa'          => self::formatDate($d['tanggalPeriksa'] ?? null),
            'asuransi'                 => $d['asuransi'] ?? 0,
            'freight'                  => $d['freight'] ?? 0,
            'fob'                      => $d['fob'] ?? 0,
            'biaya_tambahan'           => $d['biayaPenambah'] ?? 0,
            'biaya_pengurang'          => $d['biayaPengurang'] ?? 0,
            'vd'                       => $d['vd'] ?? 0,
            'cif'                      => $d['cif'] ?? 0,
            'harga_penyerahan'         => $d['hargaPenyerahan'] ?? 0,
            'ndpbm'                    => $d['nilaiKurs'] ?? ($d['ndpbm'] ?? 0),
            'dasar_pengenaan_pajak'    => $d['dasarPengenaanPajak'] ?? 0,
            'nilai_jasa'               => $d['nilaiJasa'] ?? 0,
            'uang_muka'                => $d['uangMuka'] ?? 0,
            'bruto'                    => $d['bruto'] ?? 0,
            'netto'                    => $d['netto'] ?? 0,
            'volume'                   => $d['volume'] ?? 0,
            'kota_pernyataan'          => $d['kotaTtd'] ?? null,
            'tanggal_pernyataan'       => self::formatDate($d['tanggalTtd'] ?? null),
            'nama_pernyataan'          => $d['namaTtd'] ?? null,
            'jabatan_pernyataan'       => $d['jabatanTtd'] ?? null,
            'kode_valuta'              => $d['kodeValuta'] ?? null,
            'kode_incoterm'            => $d['kodeIncoterm'] ?? null,
            'kode_jasa_kena_pajak'     => $d['kodeKenaPajak'] ?? null,
            'nomor_bukti_bayar'        => $d['nomorBuktiBayar'] ?? null,
            'tanggal_bukti_bayar'      => self::formatDate($d['tanggalBuktiBayar'] ?? null),
            'kode_jenis_nilai'         => $d['kodeJenisNilai'] ?? null,
            'ppn_pajak'                => $d['ppnPajak'] ?? null,
            'ppnbm_pajak'              => $d['ppnbmPajak'] ?? null,
            'tarif_ppn_pajak'          => $d['tarifPpnPajak'] ?? null,
            'tarif_ppnbm_pajak'        => $d['tarifPpnbmPajak'] ?? null
        ];

        // 2. ENTITAS (bc_ceisa_entitas)
        if (!empty($d['entitas']) && is_array($d['entitas'])) {
            foreach ($d['entitas'] as $idx => $e) {
                $ajuData['ENTITAS'][] = [
                    'nomor_aju'            => $nomorAju,
                    'seri'                 => $e['seriEntitas'] ?? ($idx + 1),
                    'kode_entitas'         => $e['kodeEntitas'] ?? null,
                    'kode_jenis_identitas' => $e['kodeJenisIdentitas'] ?? (strlen($e['nomorIdentitas'] ?? '') > 12 ? '5' : '0'),
                    'nomor_identitas'      => $e['nomorIdentitas'] ?? null,
                    'nama_entitas'         => $e['namaEntitas'] ?? null,
                    'alamat_entitas'       => $e['alamatEntitas'] ?? null,
                    'nib_entitas'          => $e['nibEntitas'] ?? null,
                    'kode_jenis_api'       => $e['kodeJenisApi'] ?? null,
                    'kode_status'          => $e['kodeStatus'] ?? null,
                    'nomor_ijin_entitas'   => $e['nomorIjinEntitas'] ?? null,
                    'tanggal_ijin_entitas' => self::formatDate($e['tanggalIjinEntitas'] ?? null),
                    'kode_negara'          => $e['kodeNegara'] ?? null
                ];
            }
        }

        // 3. DOKUMEN (bc_ceisa_dokumen)
        if (!empty($d['dokumen']) && is_array($d['dokumen'])) {
            foreach ($d['dokumen'] as $idx => $doc) {
                $ajuData['DOKUMEN'][] = [
                    'nomor_aju'       => $nomorAju,
                    'seri'            => $doc['seriDokumen'] ?? ($idx + 1),
                    'kode_dokumen'    => $doc['kodeDokumen'] ?? null,
                    'nomor_dokumen'   => $doc['nomorDokumen'] ?? null,
                    'tanggal_dokumen' => self::formatDate($doc['tanggalDokumen'] ?? null),
                    'kode_fasilitas'  => $doc['kodeFasilitas'] ?? null,
                    'kode_ijin'       => $doc['kodeIjin'] ?? null
                ];
            }
        }

        // 4. PENGANGKUT (bc_ceisa_pengangkut)
        if (!empty($d['pengangkut']) && is_array($d['pengangkut'])) {
            foreach ($d['pengangkut'] as $idx => $p) {
                $ajuData['PENGANGKUT'][] = [
                    'nomor_aju'        => $nomorAju,
                    'seri'             => $p['seriPengangkut'] ?? ($idx + 1),
                    'kode_cara_angkut' => $p['kodeCaraAngkut'] ?? null,
                    'nama_pengangkut'  => $p['namaPengangkut'] ?? null,
                    'nomor_pengangkut' => $p['nomorPengangkut'] ?? null,
                    'kode_bendera'     => $p['kodeBendera'] ?? null,
                    'call_sign'        => $p['callSign'] ?? null
                ];
            }
        }

        // 5. KEMASAN (bc_ceisa_kemasan)
        if (!empty($d['kemasan']) && is_array($d['kemasan'])) {
            foreach ($d['kemasan'] as $idx => $k) {
                $ajuData['KEMASAN'][] = [
                    'nomor_aju'      => $nomorAju,
                    'seri'           => $k['seriKemasan'] ?? ($idx + 1),
                    'kode_kemasan'   => $k['kodeJenisKemasan'] ?? null,
                    'jumlah_kemasan' => $k['jumlahKemasan'] ?? null,
                    'merek'          => $k['merkKemasan'] ?? null
                ];
            }
        }

        // 6. KONTAINER (bc_ceisa_kontainer)
        if (!empty($d['kontainer']) && is_array($d['kontainer'])) {
            foreach ($d['kontainer'] as $idx => $c) {
                $ajuData['KONTAINER'][] = [
                    'nomor_aju'             => $nomorAju,
                    'seri'                  => $c['seriKontainer'] ?? ($idx + 1),
                    'nomor_kontainer'       => $c['nomorKontainer'] ?? null,
                    'kode_ukuran_kontainer' => $c['kodeUkuranKontainer'] ?? null,
                    'kode_jenis_kontainer'  => $c['kodeJenisKontainer'] ?? null,
                    'kode_tipe_kontainer'   => $c['kodeTipeKontainer'] ?? null
                ];
            }
        }

        // 7. BARANG & RELASINYA (bc_ceisa_barang, bc_ceisa_barangtarif, bc_ceisa_barangdokumen, bahanbaku)
        if (!empty($d['barang']) && is_array($d['barang'])) {
            foreach ($d['barang'] as $bIdx => $b) {
                $seriBarang = $b['seriBarang'] ?? ($bIdx + 1);
                $ajuData['BARANG'][] = [
                    'nomor_aju'            => $nomorAju,
                    'seri_barang'          => $seriBarang,
                    'hs'                   => $b['posTarif'] ?? ($b['hs'] ?? ''),
                    'kode_barang'          => $b['kodeBarang'] ?? null,
                    'uraian'               => $b['uraian'] ?? null,
                    'merek'                => $b['merk'] ?? ($b['merek'] ?? null),
                    'tipe'                 => $b['tipe'] ?? null,
                    'ukuran'               => $b['ukuran'] ?? null,
                    'spesifikasi_lain'     => $b['spesifikasiLain'] ?? null,
                    'kode_satuan'          => $b['kodeSatuanBarang'] ?? ($b['kodeSatuan'] ?? null),
                    'jumlah_satuan'        => $b['jumlahSatuan'] ?? 0,
                    'kode_kemasan'         => $b['kodeJenisKemasan'] ?? ($b['kodeKemasan'] ?? null),
                    'jumlah_kemasan'       => $b['jumlahKemasan'] ?? 0,
                    'netto'                => $b['netto'] ?? 0,
                    'bruto'                => $b['bruto'] ?? 0,
                    'volume'               => $b['volume'] ?? 0,
                    'cif'                  => $b['cif'] ?? 0,
                    'fob'                  => $b['fob'] ?? 0,
                    'asuransi'             => $b['asuransi'] ?? 0,
                    'freight'              => $b['freight'] ?? 0,
                    'harga_penyerahan'     => $b['hargaPenyerahan'] ?? 0,
                    'harga_ekspor'         => $b['hargaEkspor'] ?? 0,
                    'nilai_jasa'           => $b['nilaiJasa'] ?? 0,
                    'kode_daerah_asal'     => $b['kodeDaerahAsal'] ?? null,
                    'kode_kategori_barang' => $b['kodeKategoriBarang'] ?? null,
                    'kode_kondisi_barang'  => $b['kodeKondisiBarang'] ?? null,
                    'kode_negara_asal'     => $b['kodeNegaraAsal'] ?? null,
                    'kode_perhitungan'     => $b['kodePerhitungan'] ?? null
                ];

                // BARANGTARIF
                if (!empty($b['barangTarif']) && is_array($b['barangTarif'])) {
                    foreach ($b['barangTarif'] as $bt) {
                        $ajuData['BARANGTARIF'][] = [
                            'nomor_aju'       => $nomorAju,
                            'seri_barang'     => $seriBarang,
                            'kode_pungutan'   => $bt['kodeJenisPungutan'] ?? ($bt['kodePungutan'] ?? ''),
                            'tarif'           => $bt['tarif'] ?? 0,
                            'kode_fasilitas'  => $bt['kodeFasilitasTarif'] ?? ($bt['kodeFasilitas'] ?? ''),
                            'tarif_fasilitas' => $bt['tarifFasilitas'] ?? 0,
                            'kode_tarif'      => $bt['tarifBk'] ?? null
                        ];
                    }
                }

                // BARANGDOKUMEN
                if (!empty($b['barangDokumen']) && is_array($b['barangDokumen'])) {
                    foreach ($b['barangDokumen'] as $bd) {
                        $ajuData['BARANGDOKUMEN'][] = [
                            'nomor_aju'    => $nomorAju,
                            'seri_barang'  => $seriBarang,
                            'seri_dokumen' => $bd['seriDokumen'] ?? ($bd['seri'] ?? 1)
                        ];
                    }
                }

                // BAHANBAKU
                if (!empty($b['bahanBaku']) && is_array($b['bahanBaku'])) {
                    foreach ($b['bahanBaku'] as $bbIdx => $bb) {
                        $ajuData['BAHANBAKU'][] = [
                            'nomor_aju'       => $nomorAju,
                            'seri_barang'     => $seriBarang,
                            'seri_bahan_baku' => $bb['seriBahanBaku'] ?? ($bbIdx + 1),
                            'kode_asal_bahan' => $bb['kodeAsalBahan'] ?? null,
                            'hs'              => $bb['hs'] ?? null,
                            'kode_barang'     => $bb['kodeBarang'] ?? null,
                            'uraian'          => $bb['uraian'] ?? null,
                            'jumlah_satuan'   => $bb['jumlahSatuan'] ?? 0,
                            'kode_satuan'     => $bb['kodeSatuan'] ?? null,
                            'cif'             => $bb['cif'] ?? 0
                        ];
                    }
                }
            }
        }

        // 8. PUNGUTAN (bc_ceisa_pungutan)
        if (!empty($d['pungutan']) && is_array($d['pungutan'])) {
            foreach ($d['pungutan'] as $p) {
                $keterangan = $p['keterangan'] ?? '';
                $facMap = [
                    'dibayar'              => '1',
                    'ditanggungPemerintah' => '2',
                    'ditangguhkan'         => '3',
                    'berkala'              => '4',
                    'dibebaskan'           => '5',
                    'tidakDipungut'        => '6',
                    'sudahDilunasi'        => '7',
                    'dijaminkan'           => '8',
                    'ditunda'              => '9'
                ];
                $hasFac = false;
                foreach ($facMap as $k => $fCode) {
                    $val = (float)($p[$k] ?? 0);
                    if ($val > 0) {
                        $ajuData['PUNGUTAN'][] = [
                            'nomor_aju'            => $nomorAju,
                            'kode_fasilitas_tarif' => $fCode,
                            'kode_jenis_pungutan'  => $keterangan,
                            'nilai_pungutan'       => $val,
                            'npwp_billing'         => ''
                        ];
                        $hasFac = true;
                    }
                }
                if (!$hasFac) {
                    $ajuData['PUNGUTAN'][] = [
                        'nomor_aju'            => $nomorAju,
                        'kode_fasilitas_tarif' => '',
                        'kode_jenis_pungutan'  => $keterangan,
                        'nilai_pungutan'       => 0,
                        'npwp_billing'         => ''
                    ];
                }
            }
        }

        // 9. JAMINAN (bc_ceisa_jaminan)
        if (!empty($d['jaminan']) && is_array($d['jaminan'])) {
            foreach ($d['jaminan'] as $j) {
                $ajuData['JAMINAN'][] = [
                    'nomor_aju'           => $nomorAju,
                    'kode_kantor'         => $j['kodeKantor'] ?? null,
                    'kode_jaminan'        => $j['kodeJaminan'] ?? null,
                    'nomor_jaminan'       => $j['nomorJaminan'] ?? null,
                    'tanggal_jaminan'     => self::formatDate($j['tanggalJaminan'] ?? null),
                    'nilai_jaminan'       => $j['nilaiJaminan'] ?? 0,
                    'penjamin'            => $j['penjamin'] ?? null,
                    'tanggal_jatuh_tempo' => self::formatDate($j['tanggalJatuhTempo'] ?? null),
                    'nomor_bpj'           => $j['nomorBpj'] ?? null,
                    'tanggal_bpj'         => self::formatDate($j['tanggalBpj'] ?? null)
                ];
            }
        }

        // 10. BANKDEVISA (bc_ceisa_bankdevisa)
        if (!empty($d['bankDevisa']) && is_array($d['bankDevisa'])) {
            foreach ($d['bankDevisa'] as $idx => $bd) {
                $ajuData['BANKDEVISA'][] = [
                    'nomor_aju' => $nomorAju,
                    'seri'      => $bd['seri'] ?? ($idx + 1),
                    'kode'      => $bd['kode'] ?? null,
                    'nama'      => $bd['nama'] ?? null
                ];
            }
        }

        // 11. VERSI (bc_ceisa_versi)
        $ajuData['VERSI'][] = [
            'nomor_aju' => $nomorAju,
            'versi'     => '1.3'
        ];

        return $ajuData;
    }

    /**
     * Impor data dokumen JSON langsung ke tabel staging H2H (MySQL bc_ceisa_*)
     */
    public static function importToStaging($koneksiOrPdo, $jsonPayload, $cmpId = '', $overwrite = true) {
        $ajuData = self::parseToAjuData($jsonPayload);
        $primaryAju = $ajuData['HEADER'][0]['nomor_aju'] ?? '';

        $importer = new CeisaXlsxImporter($koneksiOrPdo, $cmpId);
        $result = $importer->importData($ajuData, [
            'cmp_id'    => $cmpId,
            'overwrite' => $overwrite,
            'file_name' => 'LNSW_' . $primaryAju . '.json'
        ]);

        return $result;
    }

    /**
     * Konversi data dokumen JSON langsung ke berkas Excel (.xlsx) 21 Sheet resmi CEISA 4.0
     */
    public static function exportToExcel($koneksiOrPdo, $jsonPayload, $targetFilePath) {
        $ajuData = self::parseToAjuData($jsonPayload);
        $primaryAju = $ajuData['HEADER'][0]['nomor_aju'] ?? '';

        $exporter = new CeisaXlsxExporter($koneksiOrPdo);
        $success = $exporter->generateXlsx($primaryAju, $targetFilePath, $ajuData);

        if (!$success && (!file_exists($targetFilePath) || filesize($targetFilePath) < 1500)) {
            throw new Exception("Gagal membuat berkas Excel di: " . $targetFilePath);
        }

        return $targetFilePath;
    }
}

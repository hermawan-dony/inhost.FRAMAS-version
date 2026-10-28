<?php
/**
 * CEISA 4.0 JSON Builder Engine (CeisaJsonBuilder.php)
 * Membangun payload JSON resmi CEISA 4.0 langsung dari tabel riil database MySQL
 * Mendukung dokumen pabean: BC 2.3, 2.5, 2.6.1, 2.6.2, 2.7, 3.0, 4.0, 4.1.
 */

class CeisaJsonBuilder
{
    private static $templateDir = __DIR__ . '/sql_templates';

    /**
     * Generate JSON CEISA 4.0 untuk nomor AJU tertentu
     *
     * @param string $nomor_aju Nomor pengajuan dokumen pabean (26 digit)
     * @param string|null $cmp_id ID Perusahaan / NPWP (opsional, otomatis dari header)
     * @return array [ 'status' => 'OK'|'ERROR', 'nomor_aju' => string, 'kode_dokumen' => string, 'json' => string, 'data' => array, 'message' => string ]
     */
    public static function generate(string $nomor_aju, ?string $cmp_id = null): array
    {
        require __DIR__ . '/koneksi.php';
        /** @var mysqli $koneksi */

        $nomor_aju = trim($nomor_aju);
        if (empty($nomor_aju)) {
            return ['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.'];
        }

        // 1. Cari Header Dokumen & Tentukan Jenis Dokumen BC
        $stmt = $koneksi->prepare("SELECT kode_dokumen, cmp_id FROM bc_ceisa_header WHERE nomor_aju = ? LIMIT 1");
        if (!$stmt) {
            return ['status' => 'ERROR', 'message' => 'Database error (prepare header): ' . $koneksi->error];
        }
        $stmt->bind_param("s", $nomor_aju);
        $stmt->execute();
        $res = $stmt->get_result();
        $header = $res->fetch_assoc();
        $stmt->close();

        if (!$header) {
            return ['status' => 'ERROR', 'message' => "Dokumen dengan Nomor AJU '$nomor_aju' tidak ditemukan di bc_ceisa_header."];
        }

        $kode_dokumen = trim((string)$header['kode_dokumen']);
        $headerCmp    = trim((string)($header['cmp_id'] ?? ''));

        // Isolasi multi-company: jika cmp_id spesifik diberikan, pastikan dokumen memang milik cmp_id tersebut
        if (!empty($cmp_id) && !empty($headerCmp) && $headerCmp !== $cmp_id) {
            return [
                'status' => 'ERROR',
                'message' => "Akses ditolak: Dokumen AJU '$nomor_aju' terdaftar untuk perusahaan '$headerCmp', bukan '$cmp_id'."
            ];
        }

        if (empty($cmp_id)) {
            $cmp_id = $headerCmp;
        }

        // Jika cmp_id masih kosong, deteksi dari fungsi ceisa_get_company
        if (empty($cmp_id) && file_exists(__DIR__ . '/datalogin.php')) {
            require_once __DIR__ . '/datalogin.php';
            if (function_exists('ceisa_get_company')) {
                $comp = ceisa_get_company($nomor_aju);
                if ($comp && !empty($comp['cmp_id'])) {
                    $cmp_id = $comp['cmp_id'];
                }
            }
        }

        // 2. Normalisasi Kode Dokumen ke Nama Template
        // Contoh: '23' -> 'bc23', '261' -> 'bc261', '30' -> 'bc30', dsb.
        $bcMap = [
            '16'  => 'bc16',
            '20'  => 'bc20',
            '23'  => 'bc23',
            '25'  => 'bc25',
            '261' => 'bc261',
            '262' => 'bc262',
            '27'  => 'bc27',
            '28'  => 'bc28',
            '30'  => 'bc30',
            '33'  => 'bc33',
            '331' => 'p3bet',
            'p3bet' => 'p3bet',
            '40'  => 'bc40',
            '41'  => 'bc41',
            '611' => 'bc611',
            '613' => 'bc613',
            '631' => 'bc631',
        ];

        if (!isset($bcMap[$kode_dokumen])) {
            return [
                'status' => 'ERROR',
                'message' => "Jenis dokumen BC '$kode_dokumen' belum didukung template builder. Pilihan: " . implode(', ', array_keys($bcMap)) . "."
            ];
        }

        $templateFile = self::$templateDir . DIRECTORY_SEPARATOR . $bcMap[$kode_dokumen] . '.sql';
        if (!file_exists($templateFile)) {
            return [
                'status' => 'ERROR',
                'message' => "Berkas template SQL '$templateFile' tidak ditemukan."
            ];
        }

        // 3. Sanitasi Karakter Teks Barang, Bahan Baku & Entitas (Kutip Ganda & Line Break)
        $cleanStmt = $koneksi->prepare("UPDATE bc_ceisa_barang SET 
            uraian = REPLACE(REPLACE(REPLACE(uraian, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            tipe = REPLACE(REPLACE(REPLACE(tipe, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            merek = REPLACE(REPLACE(REPLACE(merek, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            ukuran = REPLACE(REPLACE(REPLACE(ukuran, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            spesifikasi_lain = REPLACE(REPLACE(REPLACE(spesifikasi_lain, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            kode_barang = REPLACE(REPLACE(REPLACE(kode_barang, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' ')
        WHERE nomor_aju = ?");
        if ($cleanStmt) { $cleanStmt->bind_param("s", $nomor_aju); $cleanStmt->execute(); $cleanStmt->close(); }

        $cleanStmt2 = $koneksi->prepare("UPDATE bc_ceisa_bahanbaku SET 
            uraian = REPLACE(REPLACE(REPLACE(uraian, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            tipe = REPLACE(REPLACE(REPLACE(tipe, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            merek = REPLACE(REPLACE(REPLACE(merek, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            ukuran = REPLACE(REPLACE(REPLACE(ukuran, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            spesifikasi_lain = REPLACE(REPLACE(REPLACE(spesifikasi_lain, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' '),
            kode_barang = REPLACE(REPLACE(REPLACE(kode_barang, '\"', \"''\"), CHAR(13), ''), CHAR(10), ' ')
        WHERE nomor_aju = ?");
        if ($cleanStmt2) { $cleanStmt2->bind_param("s", $nomor_aju); $cleanStmt2->execute(); $cleanStmt2->close(); }

        // Sanitasi alamat_entitas & nama_entitas agar valid dalam JSON
        $cleanStmt5 = $koneksi->prepare("UPDATE bc_ceisa_entitas SET 
            alamat_entitas = REPLACE(REPLACE(REPLACE(alamat_entitas, '\"', \"'\"), CHAR(13), ''), CHAR(10), ' '),
            nama_entitas = REPLACE(REPLACE(REPLACE(nama_entitas, '\"', \"'\"), CHAR(13), ''), CHAR(10), ' ')
        WHERE nomor_aju = ?");
        if ($cleanStmt5) { $cleanStmt5->bind_param("s", $nomor_aju); $cleanStmt5->execute(); $cleanStmt5->close(); }

        // Normalisasi entitas: Jika NPWP 15 digit, ubah jadi 16 digit (diawali '0') dan set kode_jenis_identitas = '6'
        $cleanEntNpwp = $koneksi->prepare("UPDATE bc_ceisa_entitas SET 
            nomor_identitas = CONCAT('0', nomor_identitas),
            kode_jenis_identitas = '6'
        WHERE nomor_aju = ? AND (kode_jenis_identitas = '5' OR kode_jenis_identitas = '' OR kode_jenis_identitas IS NULL) AND CHAR_LENGTH(nomor_identitas) = 15 AND nomor_identitas REGEXP '^[0-9]+$'");
        if ($cleanEntNpwp) { $cleanEntNpwp->bind_param("s", $nomor_aju); $cleanEntNpwp->execute(); $cleanEntNpwp->close(); }

        $cleanEntNpwp16 = $koneksi->prepare("UPDATE bc_ceisa_entitas SET 
            kode_jenis_identitas = '6'
        WHERE nomor_aju = ? AND (kode_jenis_identitas = '5' OR kode_jenis_identitas = '' OR kode_jenis_identitas IS NULL) AND CHAR_LENGTH(nomor_identitas) = 16 AND nomor_identitas REGEXP '^[0-9]+$'");
        if ($cleanEntNpwp16) { $cleanEntNpwp16->bind_param("s", $nomor_aju); $cleanEntNpwp16->execute(); $cleanEntNpwp16->close(); }

        // Normalisasi format tanggal MM-DD-YYYY di tabel bc_ceisa_entitas ke YYYY-MM-DD
        $cleanDate = $koneksi->prepare("UPDATE bc_ceisa_entitas SET tanggal_ijin_entitas = STR_TO_DATE(SUBSTRING(tanggal_ijin_entitas, 1, 10), '%m-%d-%Y') WHERE nomor_aju = ? AND tanggal_ijin_entitas IS NOT NULL AND tanggal_ijin_entitas LIKE '__-__-____%'");
        if ($cleanDate) { $cleanDate->bind_param("s", $nomor_aju); $cleanDate->execute(); $cleanDate->close(); }

        // Normalisasi format tanggal YYYY-DD-MM (misal: 2026-31-08) di tabel bc_ceisa_bahanbaku ke YYYY-MM-DD
        $cleanBbDate = $koneksi->prepare("UPDATE bc_ceisa_bahanbaku SET tanggal_daftar_asal = CONCAT(SUBSTRING(tanggal_daftar_asal, 1, 4), '-', SUBSTRING(tanggal_daftar_asal, 9, 2), '-', SUBSTRING(tanggal_daftar_asal, 6, 2)) WHERE nomor_aju = ? AND tanggal_daftar_asal REGEXP '^[0-9]{4}-(1[3-9]|[23][0-9])-[0-9]{2}'");
        if ($cleanBbDate) { $cleanBbDate->bind_param("s", $nomor_aju); $cleanBbDate->execute(); $cleanBbDate->close(); }

        // Normalisasi kode_status pada entitas 3 (Pengusaha TPB/Importir) & 7 (Pemilik Barang) jika kosong/invalid
        $cleanStatus = $koneksi->prepare("UPDATE bc_ceisa_entitas SET kode_status = '1' WHERE nomor_aju = ? AND kode_entitas IN ('3', '7') AND (kode_status IS NULL OR TRIM(kode_status) = '' OR TRIM(kode_status) NOT IN ('0','1','2','3','4','5','6','7','8','9','10'))");
        if ($cleanStatus) { $cleanStatus->bind_param("s", $nomor_aju); $cleanStatus->execute(); $cleanStatus->close(); }

        // Normalisasi kode_jenis_api pada entitas 1 (Importir), 2 (Eksportir), 3 (Pengusaha TPB), 7 (Pemilik), 8 (Penerima), 9 (Pengirim) jika kosong/invalid
        $cleanApi = $koneksi->prepare("UPDATE bc_ceisa_entitas SET kode_jenis_api = '02' WHERE nomor_aju = ? AND (kode_entitas IN ('1', '2', '3', '7', '8', '9') OR ? IN ('40', '41')) AND (kode_jenis_api IS NULL OR TRIM(kode_jenis_api) = '' OR TRIM(kode_jenis_api) NOT IN ('01', '02'))");
        if ($cleanApi) { $cleanApi->bind_param("ss", $nomor_aju, $kode_dokumen); $cleanApi->execute(); $cleanApi->close(); }

        // Normalisasi & Perbaikan Otomatis Bahan Baku Tarif yang Tertukar Seri (misal ke seri 25)
        self::autoRepairBahanBakuTarif($koneksi, $nomor_aju);

        // Normalisasi tarif_fasilitas: Pastikan bernilai 100 untuk seluruh tarif berfasilitas jika NULL atau 0
        $cleanTarFas = $koneksi->prepare("UPDATE bc_ceisa_barangtarif SET tarif_fasilitas = 100.00 WHERE nomor_aju = ? AND (tarif_fasilitas IS NULL OR tarif_fasilitas = 0) AND kode_fasilitas IN ('2','3','4','5','6','7')");
        if ($cleanTarFas) { $cleanTarFas->bind_param("s", $nomor_aju); $cleanTarFas->execute(); $cleanTarFas->close(); }

        // 4. Pastikan Ukuran Maksimum GROUP_CONCAT Cukup Besar
        $koneksi->query("SET SESSION group_concat_max_len = 100000000");

        // 5. Eksekusi Template SQL Builder
        $sqlQuery = file_get_contents($templateFile);
        $sqlQuery = preg_replace('/^\xEF\xBB\xBF/', '', trim($sqlQuery)); // Hapus BOM

        $escapedAju = "'" . $koneksi->real_escape_string($nomor_aju) . "'";
        $sqlQuery = str_replace(':ls_aju', $escapedAju, $sqlQuery);

        // Eksekusi query dengan proteksi exception
        try {
            $resBuilder = $koneksi->query($sqlQuery);
            if (!$resBuilder) {
                return [
                    'status' => 'ERROR',
                    'message' => 'Gagal mengeksekusi query builder BC ' . $kode_dokumen . ': ' . $koneksi->error
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => 'ERROR',
                'message' => 'Gagal mengeksekusi query builder BC ' . $kode_dokumen . ': ' . $e->getMessage()
            ];
        }

        $rowBuilder = $resBuilder->fetch_row();
        $rawJson = $rowBuilder[0] ?? '';

        if (empty($rawJson)) {
            return [
                'status' => 'ERROR',
                'message' => "Hasil query pembentukan JSON kosong untuk nomor AJU '$nomor_aju'."
            ];
        }

        // 6. Validasi Integritas JSON & Normalisasi Tanggal Global
        $decoded = json_decode($rawJson, true);
        if ($decoded === null) {
            return [
                'status' => 'ERROR',
                'message' => 'JSON Syntax Error: ' . json_last_error_msg(),
                'raw_sample' => substr($rawJson, 0, 500)
            ];
        }

        // Pastikan seluruh tanggal di dalam JSON bersih dan berformat YYYY-MM-DD
        self::recursiveNormalizeDates($decoded);

        // Sanitasi & Normalisasi Entitas: 
        // 1. Nomor Identitas NPWP (15/16 digit) dikonversi ke format standar NITKU (22 digit)
        // 2. Cegah error "is an invalid date [CODE:1034]" jika tanggal ijin kosong/invalid
        if (isset($decoded['entitas']) && is_array($decoded['entitas'])) {
            foreach ($decoded['entitas'] as &$ent) {
                // Normalisasi NITKU 22 Digit untuk nomor identitas
                if (isset($ent['nomorIdentitas'])) {
                    $rawId = preg_replace('/[^0-9]/', '', (string)$ent['nomorIdentitas']);
                    if (strlen($rawId) === 15) {
                        $ent['nomorIdentitas'] = '0' . $rawId . '000000';
                        $ent['kodeJenisIdentitas'] = '6';
                    } elseif (strlen($rawId) === 16) {
                        $ent['nomorIdentitas'] = $rawId . '000000';
                        $ent['kodeJenisIdentitas'] = '6';
                    } elseif (strlen($rawId) === 22) {
                        $ent['nomorIdentitas'] = $rawId;
                        $ent['kodeJenisIdentitas'] = '6';
                    }
                }

                if (isset($ent['tanggalIjinEntitas'])) {
                    $tglIjin = trim((string)$ent['tanggalIjinEntitas']);
                    if ($tglIjin === '' || $tglIjin === '0000-00-00' || strtolower($tglIjin) === 'null' || $tglIjin === '-') {
                        unset($ent['tanggalIjinEntitas']);
                    } else {
                        $normTgl = self::normalizeDateStr($tglIjin);
                        if (empty($normTgl) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $normTgl)) {
                            unset($ent['tanggalIjinEntitas']);
                        } else {
                            $ent['tanggalIjinEntitas'] = $normTgl;
                        }
                    }
                }
                if (isset($ent['nomorIjinEntitas'])) {
                    $noIjin = trim((string)$ent['nomorIjinEntitas']);
                    if ($noIjin === '' || $noIjin === '-') {
                        unset($ent['nomorIjinEntitas']);
                        unset($ent['tanggalIjinEntitas']);
                    }
                }
                // Normalisasi & Proteksi kodeJenisApi: CEISA 4.0 hanya menerima enum ['01', '02'].
                // Untuk entitas utama (1=Importir, 2=Eksportir, 3=Pengusaha TPB, 7=Pemilik Barang, 8=Penerima, 9=Pengirim)
                // serta seluruh entitas pada dokumen lokal (BC 40, BC 41): WAJIB ada di schema JSON CEISA 4.0.
                // Jika kosong/invalid, default ke '02' (API-P) agar tidak memicu error CODE:1028 (missing required field) maupun CODE:1008 (enum violation).
                // Hanya entitas non-pemegang API (seperti 4=PPJK, 5=Pemasok LN, 6=Pengangkut, 10=Penjual LN, 11=Pembeli LN, 12=Penerima LN) yang dihapus agar tidak memicu CODE:1008.
                $kdEnt = (string)($ent['kodeEntitas'] ?? '');
                $api = trim((string)($ent['kodeJenisApi'] ?? ''));
                if ($api === '1') $api = '01';
                if ($api === '2') $api = '02';
                if (in_array($api, ['01', '02'], true)) {
                    $ent['kodeJenisApi'] = $api;
                } elseif (in_array($kdEnt, ['1', '2', '3', '7', '8', '9'], true) || in_array($kode_dokumen, ['40', '41'], true)) {
                    $ent['kodeJenisApi'] = '02';
                } else {
                    unset($ent['kodeJenisApi']);
                }
            }
            unset($ent);
        }

        // Normalisasi Dokumen Lampiran:
        // Khusus BC 3.0 (PEB), CEISA 4.0 memvalidasi secara ketat skema Dokumen:
        // Dokumen #1 WAJIB berkode '380' (Invoice), Dokumen #2 WAJIB berkode '217' (Packing List).
        if (isset($decoded['dokumen']) && is_array($decoded['dokumen']) && count($decoded['dokumen']) > 0) {
            if ($kode_dokumen === '30') {
                usort($decoded['dokumen'], function ($a, $b) {
                    $orderA = ($a['kodeDokumen'] === '380') ? 1 : (($a['kodeDokumen'] === '217') ? 2 : 3);
                    $orderB = ($b['kodeDokumen'] === '380') ? 1 : (($b['kodeDokumen'] === '217') ? 2 : 3);
                    if ($orderA !== $orderB) {
                        return $orderA <=> $orderB;
                    }
                    return intval($a['seriDokumen'] ?? 0) <=> intval($b['seriDokumen'] ?? 0);
                });
            }
            $seriDocCounter = 1;
            foreach ($decoded['dokumen'] as &$doc) {
                $doc['seriDokumen'] = $seriDocCounter++;
            }
            unset($doc);
        }

        // BC 40: Aturan Resmi Bea Cukai CEISA 4.0: Nilai CIF header, CIF barang, dan CIF Rupiah barang WAJIB DIKOSONGKAN
        if ($kode_dokumen === '40') {
            unset($decoded['cif']);
            if (isset($decoded['barang']) && is_array($decoded['barang'])) {
                foreach ($decoded['barang'] as &$b) {
                    unset($b['cif']);
                    unset($b['cifRupiah']);
                }
                unset($b);
            }
        } else {
            // Pastikan CIF Header sama persis dengan total penjumlahan CIF seluruh barang (mencegah error selisih sen di CEISA 4.0)
            if (isset($decoded['barang']) && is_array($decoded['barang']) && count($decoded['barang']) > 0) {
                $sumBarangCif = 0;
                foreach ($decoded['barang'] as $b) {
                    $sumBarangCif += (float)($b['cif'] ?? 0);
                }
                if (isset($decoded['cif'])) {
                    $decoded['cif'] = round($sumBarangCif, 2);
                }
            }
        }

        $rawJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 7. Simpan Hasil JSON ke Tabel bc_ceisa_json & File json.txt
        $delJson = $koneksi->prepare("DELETE FROM bc_ceisa_json WHERE cmp_id = ? OR cmp_id IS NULL OR cmp_id = ''");
        if ($delJson) {
            $delJson->bind_param("s", $cmp_id);
            $delJson->execute();
            $delJson->close();
        }

        $insJson = $koneksi->prepare("INSERT INTO bc_ceisa_json (json, cmp_id) VALUES (?, ?)");
        if ($insJson) {
            $insJson->bind_param("ss", $rawJson, $cmp_id);
            $insJson->execute();
            $insJson->close();
        }

        // Jalankan pembersihan otomatis file JSON sementara yang kadaluarsa (> 30 menit)
        self::cleanupTempJsonFiles(1800);

        // Tulis ke json.txt sementara untuk kompatibilitas proses posting
        $outputFile = __DIR__ . '/json.txt';
        @file_put_contents($outputFile, $rawJson);

        // Simpan file terisolasi sementara per company (cegah tabrakan multi-company)
        $cleanCmp = preg_replace('/[^0-9A-Za-z_-]/', '', $cmp_id);
        if (!empty($cleanCmp)) {
            $cmpFile = __DIR__ . '/json_' . $cleanCmp . '.txt';
            @file_put_contents($cmpFile, $rawJson);
        }

        return [
            'status'       => 'OK',
            'nomor_aju'    => $nomor_aju,
            'kode_dokumen' => $kode_dokumen,
            'cmp_id'       => $cmp_id,
            'bytes'        => strlen($rawJson),
            'json'         => $rawJson,
            'data'         => $decoded,
            'output_file'  => $outputFile,
            'message'      => "JSON BC $kode_dokumen berhasil disusun (" . strlen($rawJson) . " bytes)."
        ];
    }

    /**
     * Normalisasi string tanggal ke YYYY-MM-DD (memperbaiki format YYYY-DD-MM atau format bertanda baca)
     */
    public static function normalizeDateStr($val) {
        if ($val === null || !is_string($val)) return $val;
        $vTrim = trim($val);
        if ($vTrim === '' || $vTrim === '0000-00-00' || strpos($vTrim, '0000-00-00') === 0) return '';

        // YYYY-DD-MM -> YYYY-MM-DD
        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})(?:\s+.*)?$/', $vTrim, $m)) {
            $y = $m[1];
            $p2 = (int)$m[2];
            $p3 = (int)$m[3];
            if ($p2 > 12 && $p3 <= 12) {
                $month = str_pad($p3, 2, '0', STR_PAD_LEFT);
                $day = str_pad($p2, 2, '0', STR_PAD_LEFT);
            } else {
                $month = str_pad($p2, 2, '0', STR_PAD_LEFT);
                $day = str_pad($p3, 2, '0', STR_PAD_LEFT);
            }
            return "{$y}-{$month}-{$day}";
        }

        // DD-MM-YYYY or MM-DD-YYYY or DD/MM/YYYY
        if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{4})(\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', $vTrim, $m)) {
            $p1 = (int)$m[1];
            $p2 = (int)$m[2];
            $y = $m[3];
            $hasTime = !empty($m[4]);
            if ($p1 > 12 && $p2 <= 12) {
                $day = str_pad($p1, 2, '0', STR_PAD_LEFT);
                $month = str_pad($p2, 2, '0', STR_PAD_LEFT);
            } elseif ($p1 <= 12 && $p2 > 12) {
                $month = str_pad($p1, 2, '0', STR_PAD_LEFT);
                $day = str_pad($p2, 2, '0', STR_PAD_LEFT);
            } elseif ($hasTime) {
                // CEISA export resmi dengan jam (MM-DD-YYYY HH:mm:ss)
                $month = str_pad($p1, 2, '0', STR_PAD_LEFT);
                $day = str_pad($p2, 2, '0', STR_PAD_LEFT);
            } else {
                // Standar manual input Indonesia tanpa jam (DD-MM-YYYY)
                $day = str_pad($p1, 2, '0', STR_PAD_LEFT);
                $month = str_pad($p2, 2, '0', STR_PAD_LEFT);
            }
            return "{$y}-{$month}-{$day}";
        }

        return $vTrim;
    }

    private static function recursiveNormalizeDates(&$data) {
        if (!is_array($data)) return;
        foreach ($data as $k => &$v) {
            if (is_array($v)) {
                self::recursiveNormalizeDates($v);
            } elseif (is_string($v)) {
                $lowerK = strtolower((string)$k);
                if (strpos($lowerK, 'tanggal') !== false || strpos($lowerK, 'tgl') !== false || $lowerK === 'jatuhtempo' || $lowerK === 'waktu') {
                    $v = self::normalizeDateStr($v);
                }
            }
        }
    }

    /**
     * Memperbaiki dan menormalkan integritas relasi baris bahan baku tarif yang tertukar nomor serinya (misal tertumpuk di seri 25)
     */
    public static function autoRepairBahanBakuTarif(mysqli $koneksi, string $nomor_aju): void
    {
        $escapedAju = $koneksi->real_escape_string($nomor_aju);
        $resBrg = $koneksi->query("SELECT DISTINCT seri_barang FROM bc_ceisa_barang WHERE nomor_aju='$escapedAju' ORDER BY seri_barang");
        if (!$resBrg) return;

        while ($bRow = $resBrg->fetch_assoc()) {
            $brg = (int)$bRow['seri_barang'];
            $resMissing = $koneksi->query("
                SELECT bb.seri_bahan_baku, bb.cif 
                FROM bc_ceisa_bahanbaku bb
                LEFT JOIN bc_ceisa_bahanbakutarif t 
                  ON t.nomor_aju = bb.nomor_aju 
                 AND t.seri_barang = bb.seri_barang 
                 AND t.seri_bahan_baku = bb.seri_bahan_baku 
                 AND t.kode_asal_bahan_baku = bb.kode_asal_bahan_baku
                WHERE bb.nomor_aju = '$escapedAju' 
                  AND bb.seri_barang = $brg 
                  AND bb.kode_asal_bahan_baku = '0'
                  AND t.seri_bahan_baku IS NULL
                ORDER BY bb.seri_bahan_baku
            ");
            if (!$resMissing || $resMissing->num_rows == 0) continue;

            $missing = [];
            while ($m = $resMissing->fetch_assoc()) {
                $missing[] = [
                    'seri' => (int)$m['seri_bahan_baku'],
                    'ppn'  => round((float)$m['cif'] * 0.11, 2),
                    'pph'  => round((float)$m['cif'] * 0.025, 2),
                ];
            }

            foreach ($missing as $m) {
                $targetSeri = $m['seri'];
                $expPpn = $m['ppn'];
                $expPph = $m['pph'];

                // Update 1 baris PPN yang match
                $koneksi->query("
                    UPDATE bc_ceisa_bahanbakutarif 
                    SET seri_bahan_baku = $targetSeri 
                    WHERE nomor_aju='$escapedAju' AND seri_barang=$brg AND seri_bahan_baku=25 AND kode_asal_bahan_baku='0' 
                      AND kode_pungutan='PPN' AND ABS(nilai_sudah_dilunasi - $expPpn) < 0.05
                    LIMIT 1
                ");

                // Update 1 baris PPH yang match
                $koneksi->query("
                    UPDATE bc_ceisa_bahanbakutarif 
                    SET seri_bahan_baku = $targetSeri 
                    WHERE nomor_aju='$escapedAju' AND seri_barang=$brg AND seri_bahan_baku=25 AND kode_asal_bahan_baku='0' 
                      AND kode_pungutan='PPH' AND ABS(nilai_sudah_dilunasi - $expPph) < 0.05
                    LIMIT 1
                ");

                // Update 1 baris BM (tarif 0) yang berlebih
                $koneksi->query("
                    UPDATE bc_ceisa_bahanbakutarif 
                    SET seri_bahan_baku = $targetSeri 
                    WHERE nomor_aju='$escapedAju' AND seri_barang=$brg AND seri_bahan_baku=25 AND kode_asal_bahan_baku='0' 
                      AND kode_pungutan='BM'
                    LIMIT 1
                ");
            }
        }
    }

    /**
     * Pembersihan otomatis file JSON sementara yang tersisa / kadaluarsa
     * Menghapus file json.txt, json_{cmp}.txt, json_{aju}.txt yang lebih lama dari $maxAgeSeconds
     *
     * @param int $maxAgeSeconds Batas usia file dalam detik (default: 1800 detik / 30 menit)
     * @param bool $deleteAll Jika true, hapus seluruh file json sementara tanpa melihat usia
     * @return int Jumlah file yang berhasil dibersihkan
     */
    public static function cleanupTempJsonFiles($maxAgeSeconds = 1800, $deleteAll = false) {
        $dirs = [__DIR__, dirname(__DIR__) . '/public', dirname(__DIR__) . '/www'];
        $dirs = array_unique(array_filter($dirs, 'is_dir'));
        $now = time();
        $deleted = 0;

        foreach ($dirs as $dir) {
            $patterns = [$dir . '/json*.txt', $dir . '/json'];
            foreach ($patterns as $pattern) {
                $files = @glob($pattern);
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (!is_file($file)) continue;
                        if ($deleteAll || ($now - filemtime($file)) >= $maxAgeSeconds) {
                            if (@unlink($file)) {
                                $deleted++;
                            }
                        }
                    }
                }
            }
        }
        return $deleted;
    }
}

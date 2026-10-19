<?php
/**
 * CEISA 4.0 - Manifest (NVOCC) JSON Payload Builder
 * Sesuai Standar Resmi OpenAPI Portal Bea Cukai (Host-to-Host Manifes v1.0)
 * Endpoint: POST /manifes/nvocc/draft
 */

class CeisaManifestBuilder {
    private $db;
    private $cmp_id;

    public function __construct($mysqli, $cmp_id) {
        $this->db = $mysqli;
        $this->cmp_id = $cmp_id;
    }

    /**
     * Membersihkan string teks dari karakter aneh dan line break
     */
    private function cleanString($str) {
        if ($str === null) return '';
        $s = trim((string)$str);
        $s = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $s);
        return preg_replace('/\s+/', ' ', $s);
    }

    /**
     * Format tanggal YYYY-MM-DD
     */
    private function formatDate($dateStr) {
        if (empty($dateStr)) return date('Y-m-d');
        $time = strtotime($dateStr);
        return $time ? date('Y-m-d', $time) : date('Y-m-d');
    }

    /**
     * Format datetime YYYY-MM-DD HH:mm:ss
     */
    private function formatDateTime($dateTimeStr) {
        if (empty($dateTimeStr)) return date('Y-m-d H:i:s');
        $time = strtotime($dateTimeStr);
        return $time ? date('Y-m-d H:i:s', $time) : date('Y-m-d H:i:s');
    }

    /**
     * Validasi kelengkapan data dokumen sebelum dibangun menjadi JSON
     */
    public function validate($nomorAju) {
        $errors = [];
        $stmt = $this->db->prepare("SELECT * FROM bc_ceisa_manifes_header WHERE nomor_aju = ? AND cmp_id = ? LIMIT 1");
        if (!$stmt) {
            return ["Database error: " . $this->db->error];
        }
        $stmt->bind_param("ss", $nomorAju, $this->cmp_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $h = $res->fetch_assoc();
        $stmt->close();

        if (!$h) {
            return ["Data Header Manifes dengan Nomor Aju '$nomorAju' tidak ditemukan."];
        }

        if (empty($h['kode_kantor'])) $errors[] = "Kode Kantor Bea Cukai tidak boleh kosong.";
        if (empty($h['id_perusahaan'])) $errors[] = "NPWP / ID Perusahaan NVOCC tidak boleh kosong.";
        if (empty($h['nama_perusahaan'])) $errors[] = "Nama Perusahaan NVOCC tidak boleh kosong.";
        if (empty($h['nomor_voyage'])) $errors[] = "Nomor Voyage / Penerbangan tidak boleh kosong.";
        if (empty($h['nama_sarana_pengangkut'])) $errors[] = "Nama Sarana Pengangkut tidak boleh kosong.";

        // Validasi kesesuaian awalan nomor AJU dengan kode kantor
        if (!empty($h['kode_kantor']) && strlen($nomorAju) >= 6 && substr($nomorAju, 0, 6) !== $h['kode_kantor']) {
            $errors[] = "Awalan 6 digit Nomor AJU (" . substr($nomorAju, 0, 6) . ") tidak sesuai dengan Kode Kantor (" . $h['kode_kantor'] . "). Silakan gunakan tombol ✏️ Rename AJU.";
        }

        // Validasi urutan tanggal
        if (!empty($h['tanggal_berangkat']) && !empty($h['tanggal_tiba'])) {
            if (strtotime($h['tanggal_tiba']) < strtotime($h['tanggal_berangkat'])) {
                $errors[] = "Tanggal Tiba (" . $h['tanggal_tiba'] . ") tidak boleh lebih awal dari Tanggal Berangkat (" . $h['tanggal_berangkat'] . ").";
            }
        }

        // Cek data House BL
        $stmtBL = $this->db->prepare("SELECT COUNT(*) as cnt FROM bc_ceisa_manifes_bl WHERE nomor_aju = ? AND cmp_id = ?");
        $stmtBL->bind_param("ss", $nomorAju, $this->cmp_id);
        $stmtBL->execute();
        $cntBL = $stmtBL->get_result()->fetch_assoc()['cnt'] ?? 0;
        $stmtBL->close();

        if ($cntBL == 0) {
            $errors[] = "Minimal harus ada 1 (satu) data House B/L (HBL) terdaftar.";
        }

        return $errors;
    }

    /**
     * Membangun payload associative array sesuai skema JsonNvocc
     */
    public function buildPayload($nomorAju) {
        $stmt = $this->db->prepare("SELECT * FROM bc_ceisa_manifes_header WHERE nomor_aju = ? AND cmp_id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception("Gagal query header manifes: " . $this->db->error);
        }
        $stmt->bind_param("ss", $nomorAju, $this->cmp_id);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$header) {
            throw new Exception("Manifes Nomor Aju '$nomorAju' tidak ditemukan.");
        }

        // 1. Data Kelompok Pos
        $kelompokPosArr = [];
        $stmtKP = $this->db->prepare("SELECT kode_kelompok_pos, jumlah FROM bc_ceisa_manifes_kelompok_pos WHERE nomor_aju = ? AND cmp_id = ? ORDER BY kode_kelompok_pos ASC");
        if ($stmtKP) {
            $stmtKP->bind_param("ss", $nomorAju, $this->cmp_id);
            $stmtKP->execute();
            $resKP = $stmtKP->get_result();
            while ($kp = $resKP->fetch_assoc()) {
                $kelompokPosArr[] = [
                    "kodeKelompokPos" => (string)$kp['kode_kelompok_pos'],
                    "jumlah"          => (int)$kp['jumlah']
                ];
            }
            $stmtKP->close();
        }

        // Jika kelompok pos belum dibuat di DB, buat otomatis default '01'
        if (empty($kelompokPosArr)) {
            $kelompokPosArr[] = [
                "kodeKelompokPos" => "01",
                "jumlah"          => 1
            ];
        }

        // 2. Data Master BLs
        $masterBlsArr = [];
        $stmtMBL = $this->db->prepare("SELECT kelompok_pos, master_bl, tanggal_bl, jumlah_pos FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = ? AND cmp_id = ? ORDER BY id ASC");
        if ($stmtMBL) {
            $stmtMBL->bind_param("ss", $nomorAju, $this->cmp_id);
            $stmtMBL->execute();
            $resMBL = $stmtMBL->get_result();
            while ($mbl = $resMBL->fetch_assoc()) {
                $masterBlsArr[] = [
                    "kelompokPos" => (string)$mbl['kelompok_pos'],
                    "masterBl"    => (string)$mbl['master_bl'],
                    "tanggalBl"   => $this->formatDate($mbl['tanggal_bl']),
                    "jumlahPos"   => (int)$mbl['jumlah_pos']
                ];
            }
            $stmtMBL->close();
        }

        // 3. Lampirans
        $lampiransArr = [];
        $stmtLamp = $this->db->prepare("SELECT seri_lampiran, kode_lampiran, nomor_lampiran, tanggal_lampiran FROM bc_ceisa_manifes_lampiran WHERE nomor_aju = ? AND cmp_id = ? ORDER BY seri_lampiran ASC");
        if ($stmtLamp) {
            $stmtLamp->bind_param("ss", $nomorAju, $this->cmp_id);
            $stmtLamp->execute();
            $resLamp = $stmtLamp->get_result();
            while ($lamp = $resLamp->fetch_assoc()) {
                $lampiransArr[] = [
                    "seriLampiran"    => (int)$lamp['seri_lampiran'],
                    "kodeLampiran"    => (string)$lamp['kode_lampiran'],
                    "nomorLampiran"   => (string)$lamp['nomor_lampiran'],
                    "tanggalLampiran" => $this->formatDate($lamp['tanggal_lampiran'])
                ];
            }
            $stmtLamp->close();
        }

        // 4. Data House BLs (dataBls)
        $dataBlsArr = [];
        $stmtBL = $this->db->prepare("SELECT * FROM bc_ceisa_manifes_bl WHERE nomor_aju = ? AND cmp_id = ? ORDER BY seri_bl ASC, id ASC");
        if ($stmtBL) {
            $stmtBL->bind_param("ss", $nomorAju, $this->cmp_id);
            $stmtBL->execute();
            $resBL = $stmtBL->get_result();
            while ($bl = $resBL->fetch_assoc()) {
                $hblNo = $bl['nomor_host_bl'];

                // A. BL HS Items
                $blHsArr = [];
                $stmtHS = $this->db->prepare("SELECT seri_hs, kode_hs, uraian_barang FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = ? AND nomor_host_bl = ? AND cmp_id = ? ORDER BY seri_hs ASC");
                if ($stmtHS) {
                    $stmtHS->bind_param("sss", $nomorAju, $hblNo, $this->cmp_id);
                    $stmtHS->execute();
                    $resHS = $stmtHS->get_result();
                    while ($hs = $resHS->fetch_assoc()) {
                        $blHsArr[] = [
                            "seriHs"       => (int)$hs['seri_hs'],
                            "kodeHs"       => (string)$hs['kode_hs'],
                            "uraianBarang" => $this->cleanString($hs['uraian_barang'])
                        ];
                    }
                    $stmtHS->close();
                }

                // B. BL Petikemas Terangkut
                $blPetikemasArr = [];
                $stmtPeti = $this->db->prepare("SELECT seri_container, nomor_container, fl_b3, fl_terangkut, status, type_container, ukuran_container, nomor_segel, nomor_polisi, jenis_container, jenis_muat, driver FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = ? AND nomor_host_bl = ? AND cmp_id = ? ORDER BY seri_container ASC");
                if ($stmtPeti) {
                    $stmtPeti->bind_param("sss", $nomorAju, $hblNo, $this->cmp_id);
                    $stmtPeti->execute();
                    $resPeti = $stmtPeti->get_result();
                    while ($peti = $resPeti->fetch_assoc()) {
                        $blPetikemasArr[] = [
                            "seriContainer"   => (int)$peti['seri_container'],
                            "nomorContainer"  => $this->cleanString($peti['nomor_container']),
                            "flB3"            => (string)($peti['fl_b3'] ?? ''),
                            "flTerangkut"     => (string)($peti['fl_terangkut'] ?: '01'),
                            "status"          => (string)($peti['status'] ?? ''),
                            "typeContainer"   => (string)($peti['type_container'] ?: '1'),
                            "ukuranContainer" => (string)($peti['ukuran_container'] ?: '20'),
                            "nomorSegel"      => $this->cleanString($peti['nomor_segel']),
                            "nomorPolisi"     => (string)($peti['nomor_polisi'] ?? ''),
                            "jenisContainer"  => (string)($peti['jenis_container'] ?: '7'),
                            "jenisMuat"       => (string)($peti['jenis_muat'] ?? ''),
                            "driver"          => $this->cleanString($peti['driver'] ?? '')
                        ];
                    }
                    $stmtPeti->close();
                }

                // C. BL Dokumen
                $blDokumenArr = [];
                $stmtDok = $this->db->prepare("SELECT seri_dokumen, kode_kantor, kode_dokumen, nomor_dokumen, tanggal_dokumen FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = ? AND nomor_host_bl = ? AND cmp_id = ? ORDER BY seri_dokumen ASC");
                if ($stmtDok) {
                    $stmtDok->bind_param("sss", $nomorAju, $hblNo, $this->cmp_id);
                    $stmtDok->execute();
                    $resDok = $stmtDok->get_result();
                    while ($dok = $resDok->fetch_assoc()) {
                        $blDokumenArr[] = [
                            "seriDokumen"    => (int)$dok['seri_dokumen'],
                            "kodeKantor"     => (string)$dok['kode_kantor'],
                            "kodeDokumen"    => (string)$dok['kode_dokumen'],
                            "nomorDokumen"   => (string)$dok['nomor_dokumen'],
                            "tanggalDokumen" => $this->formatDate($dok['tanggal_dokumen'])
                        ];
                    }
                    $stmtDok->close();
                }

                // Susun item House BL
                $itemBL = [
                    "kodeKelompokPos"           => (string)($bl['kode_kelompok_pos'] ?: '01'),
                    "nomorPos"                  => (string)($bl['nomor_pos'] ?: '000100000000'),
                    "nomorBl"                   => (string)$bl['nomor_bl'],
                    "tanggalBl"                 => $this->formatDate($bl['tanggal_bl']),
                    "nomorHostBl"               => (string)$bl['nomor_host_bl'],
                    "tanggalHostBl"             => $this->formatDate($bl['tanggal_host_bl']),
                    "marking"                   => $this->cleanString($bl['marking'] ?: 'NO MARKING'),
                    "npwpPengirim"              => (string)($bl['npwp_pengirim'] ?? ''),
                    "namaPengirim"              => $this->cleanString($bl['nama_pengirim']),
                    "alamatPengirim"            => $this->cleanString($bl['alamat_pengirim']),
                    "npwpPenerima"              => (string)$bl['npwp_penerima'],
                    "namaPenerima"              => $this->cleanString($bl['nama_penerima']),
                    "alamatPenerima"            => $this->cleanString($bl['alamat_penerima']),
                    "npwpNotify"                => (string)($bl['npwp_notify'] ?? ''),
                    "namaNotify"                => $this->cleanString($bl['nama_notify'] ?: 'SAME AS CONSIGNEE'),
                    "alamatNotify"              => $this->cleanString($bl['alamat_notify'] ?: 'SAME AS CONSIGNEE'),
                    "berat"                     => (float)$bl['berat'],
                    "dimensi"                   => (float)$bl['dimensi'],
                    "jumlahContainer"           => (int)$bl['jumlah_container'],
                    "jenisKemasan"              => (string)($bl['jenis_kemasan'] ?: 'CT'),
                    "jumlahKemasan"             => (float)$bl['jumlah_kemasan'],
                    "motherVessel"              => $this->cleanString($bl['mother_vessel']),
                    "kodePelabuhanAsal"         => (string)($bl['kode_pelabuhan_asal'] ?? ''),
                    "kodePelabuhanBongkar"      => (string)($bl['kode_pelabuhan_bongkar'] ?? ''),
                    "kodePelabuhanTransit"      => (string)($bl['kode_pelabuhan_transit'] ?: ($bl['kode_pelabuhan_asal'] ?? '')),
                    "kodePelabuhanAkhir"        => (string)($bl['kode_pelabuhan_akhir'] ?? ''),
                    "jumlahContainerTertinggal" => (int)($bl['jumlah_container_tertinggal'] ?? 0),
                    "jumlahKemasanTertinggal"   => (int)($bl['jumlah_kemasan_tertinggal'] ?? 0),
                    "jumlahKemasanTerangkut"    => (float)($bl['jumlah_kemasan_terangkut'] ?: $bl['jumlah_kemasan']),
                    "flagKonsolidasi"           => strtoupper((string)($bl['flag_konsolidasi'] ?: 'N')),
                    "flagParsial"               => strtoupper((string)($bl['flag_parsial'] ?: 'N')),
                    "flagPartof"                => strtoupper((string)($bl['flag_partof'] ?: 'N')),
                    "blHs"                      => $blHsArr,
                    "blPetikemasTerangkut"      => $blPetikemasArr,
                    "blPetikemasTertinggals"    => [],
                    "blDokumen"                 => $blDokumenArr
                ];

                $dataBlsArr[] = $itemBL;
            }
            $stmtBL->close();
        }

        // Jika masterBlsArr kosong namun dataBls ada, buat masterBls otomatis dari dataBls pertama
        if (empty($masterBlsArr) && !empty($dataBlsArr)) {
            $masterBlsArr[] = [
                "kelompokPos" => $dataBlsArr[0]['kodeKelompokPos'],
                "masterBl"    => $dataBlsArr[0]['nomorBl'],
                "tanggalBl"   => $dataBlsArr[0]['tanggalBl'],
                "jumlahPos"   => count($dataBlsArr)
            ];
        }

        // Susun JSON NVOCC sesuai skema resmi
        $payload = [
            "kodeKantor"           => (string)$header['kode_kantor'],
            "jenisManifes"         => (string)($header['jenis_manifes'] ?: '11'),
            "idPerusahaan"         => (string)$header['id_perusahaan'],
            "namaPerusahaan"       => $this->cleanString($header['nama_perusahaan']),
            "alamatPerusahaan"     => $this->cleanString($header['alamat_perusahaan']),
            "nomorAju"             => (string)$header['nomor_aju'],
            "nomorVoyage"          => (string)$header['nomor_voyage'],
            "tanggalBerangkat"     => $this->formatDateTime($header['tanggal_berangkat']),
            "tanggalTiba"          => $this->formatDateTime($header['tanggal_tiba']),
            "modePengangkut"       => (string)($header['mode_pengangkut'] ?: 'Laut'),
            "namaSaranaPengangkut" => $this->cleanString($header['nama_sarana_pengangkut']),
            "imoNumber"            => (string)($header['imo_number'] ?: ''),
            "kodeNegara"           => (string)($header['kode_negara'] ?: 'PA'),
            "asalData"             => "S",
            "flwaktuTempuh"        => (string)($header['flwaktu_tempuh'] ?: '24'),
            "dataKelompokPos"      => $kelompokPosArr,
            "lampirans"            => $lampiransArr,
            "masterBls"            => $masterBlsArr,
            "dataBls"              => $dataBlsArr
        ];

        return $payload;
    }

    /**
     * Mengembalikan output string JSON valid
     */
    public function buildJson($nomorAju, $pretty = true) {
        $payload = $this->buildPayload($nomorAju);
        $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }
        return json_encode($payload, $options);
    }
}
?>

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
        if (empty($cmp_id)) {
            $cmp_id = trim((string)($header['cmp_id'] ?? ''));
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

        // Normalisasi format tanggal MM-DD-YYYY di tabel bc_ceisa_entitas ke YYYY-MM-DD
        $cleanDate = $koneksi->prepare("UPDATE bc_ceisa_entitas SET tanggal_ijin_entitas = STR_TO_DATE(SUBSTRING(tanggal_ijin_entitas, 1, 10), '%m-%d-%Y') WHERE nomor_aju = ? AND tanggal_ijin_entitas IS NOT NULL AND tanggal_ijin_entitas LIKE '__-__-____%'");
        if ($cleanDate) { $cleanDate->bind_param("s", $nomor_aju); $cleanDate->execute(); $cleanDate->close(); }

        // 4. Pastikan Ukuran Maksimum GROUP_CONCAT Cukup Besar
        $koneksi->query("SET SESSION group_concat_max_len = 100000000");

        // 5. Eksekusi Template SQL Builder
        $sqlQuery = file_get_contents($templateFile);
        $sqlQuery = preg_replace('/^\xEF\xBB\xBF/', '', trim($sqlQuery)); // Hapus BOM

        $escapedAju = "'" . $koneksi->real_escape_string($nomor_aju) . "'";
        $sqlQuery = str_replace(':ls_aju', $escapedAju, $sqlQuery);

        // Isolasi multi-company: filter cmp_id pada setiap klausa WHERE agar data dokumen tidak ganda/tabrakan
        if (!empty($cmp_id)) {
            $escapedCmp = "'" . $koneksi->real_escape_string($cmp_id) . "'";
            $sqlQuery = str_replace('WHERE nomor_aju = ', "WHERE cmp_id = $escapedCmp AND nomor_aju = ", $sqlQuery);
            $sqlQuery = str_replace('WHERE a.nomor_aju = ', "WHERE a.cmp_id = $escapedCmp AND a.nomor_aju = ", $sqlQuery);
            $sqlQuery = str_replace('WHERE x.nomor_aju = ', "WHERE x.cmp_id = $escapedCmp AND x.nomor_aju = ", $sqlQuery);
            $sqlQuery = str_replace('WHERE h.nomor_aju = ', "WHERE h.cmp_id = $escapedCmp AND h.nomor_aju = ", $sqlQuery);
        }

        $resBuilder = $koneksi->query($sqlQuery);
        if (!$resBuilder) {
            return [
                'status' => 'ERROR',
                'message' => 'Gagal mengeksekusi query builder BC ' . $kode_dokumen . ': ' . $koneksi->error
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

        // 6. Validasi Integritas JSON
        $decoded = json_decode($rawJson, true);
        if ($decoded === null) {
            return [
                'status' => 'ERROR',
                'message' => 'JSON Syntax Error: ' . json_last_error_msg(),
                'raw_sample' => substr($rawJson, 0, 500)
            ];
        }

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

        // Tulis ke json.txt untuk kompatibilitas penuh dengan posting.php & viewjson.php
        $outputFile = __DIR__ . '/json.txt';
        file_put_contents($outputFile, $rawJson);

        // Simpan file terisolasi per company (cegah tabrakan multi-company)
        $cleanCmp = preg_replace('/[^0-9A-Za-z_-]/', '', $cmp_id);
        if (!empty($cleanCmp)) {
            $cmpFile = __DIR__ . '/json_' . $cleanCmp . '.txt';
            @file_put_contents($cmpFile, $rawJson);
        }

        // Simpan juga arsip per nomor aju
        $archiveFile = __DIR__ . '/json_' . preg_replace('/[^0-9A-Za-z_-]/', '', $nomor_aju) . '.txt';
        file_put_contents($archiveFile, $rawJson);

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
}

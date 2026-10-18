<?php
/**
 * CEISA 4.0 Calculation, Tarif, and Trace Helper
 * Menangani otomasi kalkulasi tarif barang, bahan baku tarif, pungutan,
 * rekonsiliasi total CIF header, dan utilitas pelengkap (FIX HS, FIX NETTO, TRACE EX-BC).
 */

if (!defined('CEISA_CALC_HELPER_LOADED')) {
    define('CEISA_CALC_HELPER_LOADED', true);

    /**
     * 1. Sinkronisasi Total Header (wf_sum_barang)
     * Menghitung akumulasi CIF, FOB, Freight, Asuransi, Netto, Bruto, Volume, Harga Penyerahan
     * dari tabel bc_ceisa_barang dan mengupdate bc_ceisa_header.
     * Mencegah error "CIF HEADER TIDAK SAMA DENGAN TOTAL CIF BARANG".
     */
    function ceisa_sync_header_totals($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        // Ambil info dokumen
        $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header dokumen tidak ditemukan.'];
        }
        $kodeDok = trim($hdr['kode_dokumen'] ?? '');
        $ndpbm = floatval($hdr['ndpbm'] ?? 1);
        if ($ndpbm <= 0) $ndpbm = 1;

        // Ambil akumulasi nilai dari bc_ceisa_barang
        $sql = "SELECT 
            COUNT(1) as total_barang,
            SUM(COALESCE(cif, 0)) as sum_cif,
            SUM(COALESCE(cif_rupiah, 0)) as sum_cif_rupiah,
            SUM(COALESCE(fob, 0)) as sum_fob,
            SUM(COALESCE(freight, 0)) as sum_freight,
            SUM(COALESCE(asuransi, 0)) as sum_asuransi,
            SUM(COALESCE(netto, 0)) as sum_netto,
            SUM(COALESCE(bruto, 0)) as sum_bruto,
            SUM(COALESCE(volume, 0)) as sum_volume,
            SUM(COALESCE(harga_penyerahan, 0)) as sum_harga_penyerahan,
            SUM(COALESCE(nilai_barang, 0)) as sum_nilai_barang
        FROM bc_ceisa_barang 
        WHERE nomor_aju = '$escAju'";

        $sumRes = $koneksi->query($sql);
        $sums = $sumRes ? $sumRes->fetch_assoc() : null;
        if (!$sums || intval($sums['total_barang']) == 0) {
            return ['status' => 'OK', 'message' => 'Tidak ada barang untuk disinkronisasi.', 'updated' => 0];
        }

        $cif = round(floatval($sums['sum_cif']), 2);
        $fob = round(floatval($sums['sum_fob']), 2);
        $freight = round(floatval($sums['sum_freight']), 2);
        $asuransi = round(floatval($sums['sum_asuransi']), 2);
        $netto = round(floatval($sums['sum_netto']), 4);
        $bruto = round(floatval($sums['sum_bruto']), 4);
        $volume = round(floatval($sums['sum_volume']), 4);
        $harga_penyerahan = round(floatval($sums['sum_harga_penyerahan']), 2);
        $nilai_barang = round(floatval($sums['sum_nilai_barang']), 2);

        // Jika CIF 0 tapi ada CIF Rupiah atau Harga Penyerahan
        if ($cif <= 0) {
            if ($sums['sum_cif_rupiah'] > 0 && $ndpbm > 0) {
                $cif = round($sums['sum_cif_rupiah'] / $ndpbm, 2);
            } elseif ($harga_penyerahan > 0 && $ndpbm > 0) {
                $cif = round($harga_penyerahan / $ndpbm, 2);
            }
        }

        // Susun query update header
        $updates = [];
        $updates[] = "cif = $cif";
        if ($fob > 0) $updates[] = "fob = $fob";
        if ($freight > 0) $updates[] = "freight = $freight";
        if ($asuransi > 0) {
            $updates[] = "asuransi = $asuransi";
            $updates[] = "asuransi1 = $asuransi";
        }
        if ($netto > 0) $updates[] = "netto = $netto";
        if ($bruto > 0) $updates[] = "bruto = $bruto";
        if ($volume > 0) $updates[] = "volume = $volume";
        if ($harga_penyerahan > 0) $updates[] = "harga_penyerahan = $harga_penyerahan";
        if ($nilai_barang > 0) $updates[] = "nilai_barang = $nilai_barang";

        if ($kodeDok === '40' || $kodeDok === '41') {
            $dpp = $harga_penyerahan > 0 ? $harga_penyerahan : $cif;
            $updates[] = "dasar_pengenaan_pajak = '$dpp'";
        }

        $updSql = "UPDATE bc_ceisa_header SET " . implode(", ", $updates) . " WHERE nomor_aju = '$escAju'";
        $koneksi->query($updSql);

        return [
            'status' => 'OK',
            'message' => 'Total CIF dan akumulasi header berhasil disinkronisasi.',
            'cif' => $cif,
            'netto' => $netto,
            'bruto' => $bruto,
            'harga_penyerahan' => $harga_penyerahan
        ];
    }

    /**
     * 2. Auto-Generate Barang Tarif (wf_gen_barangtarif)
     * Mengisi otomatis bc_ceisa_barangtarif dari bc_ceisa_barang.
     */
    function ceisa_generate_barang_tarif($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen, ndpbm, kode_tujuan_pengiriman FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header dokumen tidak ditemukan.'];
        }
        $cmp_id = $hdr['cmp_id'] ?? '';
        $kodeDok = trim($hdr['kode_dokumen'] ?? '');
        $ndpbm = floatval($hdr['ndpbm'] ?? 1);
        if ($ndpbm <= 0) $ndpbm = 1;
        $kodeTujuan = trim($hdr['kode_tujuan_pengiriman'] ?? '');

        // Tarif PPN & PPH default nasional
        $is_ppn = 11.0;
        $is_pph = 2.5;

        // Ambil data barang
        $bRes = $koneksi->query("SELECT seri_barang, hs, kode_satuan, jumlah_satuan, cif, cif_rupiah, harga_penyerahan, nilai_barang FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' ORDER BY seri_barang ASC");
        if (!$bRes || $bRes->num_rows == 0) {
            return ['status' => 'WARNING', 'message' => 'Data barang masih kosong, tidak ada tarif barang yang digenerate.', 'count' => 0];
        }

        $barangRows = [];
        $hsList = [];
        while ($b = $bRes->fetch_assoc()) {
            $barangRows[] = $b;
            $cleanHs = trim($b['hs'] ?? '');
            if (!empty($cleanHs)) {
                $hsList[$cleanHs] = true;
            }
        }

        // Ambil / pastikan tarif BM ada di bc_ceisa_tarifbm
        $tarifBmMap = [];
        if (!empty($hsList)) {
            $quotedHs = "'" . implode("', '", array_map([$koneksi, 'real_escape_string'], array_keys($hsList))) . "'";
            $tRes = $koneksi->query("SELECT hs, tarif_bm FROM bc_ceisa_tarifbm WHERE hs IN ($quotedHs)");
            if ($tRes) {
                while ($tr = $tRes->fetch_assoc()) {
                    $tarifBmMap[trim($tr['hs'])] = floatval($tr['tarif_bm'] ?? 0);
                }
            }
            // Insert missing HS ke bc_ceisa_tarifbm dengan default BM 5.0
            foreach (array_keys($hsList) as $hs) {
                if (!isset($tarifBmMap[$hs])) {
                    $escHs = $koneksi->real_escape_string($hs);
                    $koneksi->query("INSERT IGNORE INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) VALUES ('$escHs', 5.0, CURDATE())");
                    $tarifBmMap[$hs] = 5.0;
                }
            }
        }

        // Hapus data barangtarif lama
        $koneksi->query("DELETE FROM bc_ceisa_barangtarif WHERE nomor_aju = '$escAju'");

        $insStmt = $koneksi->prepare("INSERT INTO bc_ceisa_barangtarif 
            (cmp_id, nomor_aju, seri_barang, kode_pungutan, kode_tarif, tarif, kode_fasilitas, tarif_fasilitas, nilai_bayar, nilai_fasilitas, nilai_sudah_dilunasi, kode_satuan, jumlah_satuan) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $insertedCount = 0;

        foreach ($barangRows as $b) {
            $seri = intval($b['seri_barang']);
            $hs = trim($b['hs'] ?? '');
            $kodeSatuan = trim($b['kode_satuan'] ?? '-');
            $jumlahSatuan = floatval($b['jumlah_satuan'] ?? 0);

            // Tentukan CIF Rupiah
            $cifRupiah = floatval($b['cif_rupiah'] ?? 0);
            if ($cifRupiah <= 0) {
                $cif = floatval($b['cif'] ?? 0);
                if ($cif > 0) $cifRupiah = round($cif * $ndpbm, 2);
                elseif (floatval($b['harga_penyerahan'] ?? 0) > 0) $cifRupiah = floatval($b['harga_penyerahan']);
                elseif (floatval($b['nilai_barang'] ?? 0) > 0) $cifRupiah = floatval($b['nilai_barang']);
            }

            $hargaPenyerahan = floatval($b['harga_penyerahan'] ?? 0);
            if ($hargaPenyerahan <= 0) $hargaPenyerahan = $cifRupiah;

            $tarifBm = floatval($tarifBmMap[$hs] ?? 0);

            if ($kodeDok === '40') {
                // BC 40: PPN
                $kodeFas = ($kodeTujuan === '4') ? '7' : '6';
                $ppnVal = round($hargaPenyerahan * ($is_ppn / 100), 2);
                $bayar = 0.00;
                $fasilitas = ($kodeFas === '6') ? $ppnVal : 0.00;
                $lunas = ($kodeFas === '7') ? $ppnVal : 0.00;
                $kdPung = 'PPN';
                $kdTarif = '1';
                $tarFas = 100.0;

                $insStmt->bind_param('ssissdsddddsd', 
                    $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $is_ppn, $kodeFas, $tarFas, $bayar, $fasilitas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

            } elseif ($kodeDok === '23') {
                // BC 23: BM (Ditangguhkan 3), PPH (Tidak Dipungut 6), PPN (Tidak Dipungut 6)
                $bmVal = round($cifRupiah * ($tarifBm / 100), 2);
                $nilaiImpor = $cifRupiah + $bmVal;
                $pphVal = round($nilaiImpor * ($is_pph / 100), 2);
                $ppnVal = round($nilaiImpor * ($is_ppn / 100), 2);

                // 1. BM
                $kdPung = 'BM'; $kdTarif = '1'; $tarFas = 100.0; $kdFas = '3'; $bayar = 0.0; $lunas = 0.0;
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $tarifBm, $kdFas, $tarFas, $bayar, $bmVal, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // 2. PPH
                $kdPung = 'PPH'; $kdFas = '6';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $is_pph, $kdFas, $tarFas, $bayar, $pphVal, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // 3. PPN
                $kdPung = 'PPN'; $kdFas = '6';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $is_ppn, $kdFas, $tarFas, $bayar, $ppnVal, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

            } elseif ($kodeDok === '25') {
                // BC 25: BM, PPH, PPN
                $bmVal = round($cifRupiah * ($tarifBm / 100), 2);
                $nilaiImpor = $cifRupiah + $bmVal;
                $pphVal = round($nilaiImpor * ($is_pph / 100), 2);
                $ppnVal = round($nilaiImpor * ($is_ppn / 100), 2);

                $kdTarif = '1'; $tarFas = 100.0; $kdFas = '1'; $fasilitas = 0.0; $lunas = 0.0;

                // BM
                $kdPung = 'BM';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $tarifBm, $kdFas, $tarFas, $bmVal, $fasilitas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // PPH
                $kdPung = 'PPH';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $is_pph, $kdFas, $tarFas, $pphVal, $fasilitas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // PPN
                $kdPung = 'PPN';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $is_ppn, $kdFas, $tarFas, $ppnVal, $fasilitas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

            } else {
                // BC 27 / 28 / 20 / Umum: BM, PPH, PPN
                $bmVal = round($cifRupiah * ($tarifBm / 100), 2);
                $nilaiImpor = $cifRupiah + $bmVal;
                $pphVal = round($nilaiImpor * ($is_pph / 100), 2);
                $ppnVal = round($nilaiImpor * ($is_ppn / 100), 2);

                $kdTarif = '1'; $tarFas = 100.0; $kdFas = '3'; $bayar = 0.0; $lunas = 0.0;

                // BM
                $kdPung = 'BM';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $tarifBm, $kdFas, $tarFas, $bayar, $bmVal, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // PPH
                $kdPung = 'PPH'; $kdFas = '6';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $is_pph, $kdFas, $tarFas, $bayar, $pphVal, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // PPN
                $kdPung = 'PPN'; $kdFas = '6';
                $insStmt->bind_param('ssissdsddddsd', $cmp_id, $nomor_aju, $seri, $kdPung, $kdTarif, $is_ppn, $kdFas, $tarFas, $bayar, $ppnVal, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;
            }
        }

        $insStmt->close();

        return [
            'status' => 'OK',
            'message' => "Berhasil generate $insertedCount baris Barang Tarif.",
            'count' => $insertedCount
        ];
    }

    /**
     * 3. Auto-Generate Bahan Baku Tarif (wf_gen_bbtarif & wf_gen_bbtarif2)
     * Mengisi otomatis bc_ceisa_bahanbakutarif dari bc_ceisa_bahanbaku.
     */
    function ceisa_generate_bahanbaku_tarif($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header dokumen tidak ditemukan.'];
        }
        $cmp_id = $hdr['cmp_id'] ?? '';
        $kodeDok = trim($hdr['kode_dokumen'] ?? '');
        $ndpbm = floatval($hdr['ndpbm'] ?? 1);
        if ($ndpbm <= 0) $ndpbm = 1;

        // Ambil data bahan baku
        $bbRes = $koneksi->query("SELECT seri_barang, seri_bahan_baku, kode_asal_bahan_baku, hs, kode_satuan, jumlah_satuan, cif, cif_rupiah, harga_penyerahan, harga_perolehan FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju' ORDER BY seri_barang ASC, seri_bahan_baku ASC");
        if (!$bbRes || $bbRes->num_rows == 0) {
            return ['status' => 'OK', 'message' => 'Tidak ada data bahan baku, skip generate bahan baku tarif.', 'count' => 0];
        }

        $bbRows = [];
        $hsList = [];
        while ($r = $bbRes->fetch_assoc()) {
            $bbRows[] = $r;
            $cleanHs = trim($r['hs'] ?? '');
            if (!empty($cleanHs)) {
                $hsList[$cleanHs] = true;
            }
        }

        // Ambil / pastikan tarif BM ada di bc_ceisa_tarifbm
        $tarifBmMap = [];
        if (!empty($hsList)) {
            $quotedHs = "'" . implode("', '", array_map([$koneksi, 'real_escape_string'], array_keys($hsList))) . "'";
            $tRes = $koneksi->query("SELECT hs, tarif_bm FROM bc_ceisa_tarifbm WHERE hs IN ($quotedHs)");
            if ($tRes) {
                while ($tr = $tRes->fetch_assoc()) {
                    $tarifBmMap[trim($tr['hs'])] = floatval($tr['tarif_bm'] ?? 0);
                }
            }
            foreach (array_keys($hsList) as $hs) {
                if (!isset($tarifBmMap[$hs])) {
                    $escHs = $koneksi->real_escape_string($hs);
                    $koneksi->query("INSERT IGNORE INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) VALUES ('$escHs', 5.0, CURDATE())");
                    $tarifBmMap[$hs] = 5.0;
                }
            }
        }

        // Hapus data bahanbakutarif lama
        $koneksi->query("DELETE FROM bc_ceisa_bahanbakutarif WHERE nomor_aju = '$escAju'");

        $insStmt = $koneksi->prepare("INSERT INTO bc_ceisa_bahanbakutarif 
            (cmp_id, nomor_aju, seri_barang, seri_bahan_baku, kode_asal_bahan_baku, kode_pungutan, kode_tarif, tarif, kode_fasilitas, tarif_fasilitas, nilai_bayar, nilai_fasilitas, nilai_sudah_dilunasi, kode_satuan, jumlah_satuan) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $insertedCount = 0;
        $is_ppn = 11.0;
        $is_pph = 2.5;

        // Kode fasilitas default
        if ($kodeDok === '27') {
            $fasImpor = '3';
            $fasLokal = '5';
        } elseif ($kodeDok === '25') {
            $fasImpor = '1';
            $fasLokal = '1';
        } else {
            $fasImpor = '3';
            $fasLokal = '5';
        }

        foreach ($bbRows as $bb) {
            $seriB = intval($bb['seri_barang']);
            $seriBb = intval($bb['seri_bahan_baku']);
            $asal = trim($bb['kode_asal_bahan_baku'] ?? '0');
            $hs = trim($bb['hs'] ?? '');
            $kodeSatuan = trim($bb['kode_satuan'] ?? '-');
            $jumlahSatuan = floatval($bb['jumlah_satuan'] ?? 0);

            $cifRupiah = floatval($bb['cif_rupiah'] ?? 0);
            if ($cifRupiah <= 0) {
                $cif = floatval($bb['cif'] ?? 0);
                if ($cif > 0) $cifRupiah = round($cif * $ndpbm, 2);
                elseif (floatval($bb['harga_penyerahan'] ?? 0) > 0) $cifRupiah = floatval($bb['harga_penyerahan']);
                elseif (floatval($bb['harga_perolehan'] ?? 0) > 0) $cifRupiah = floatval($bb['harga_perolehan']);
            }

            $tarifBm = floatval($tarifBmMap[$hs] ?? 0);

            if ($asal === '0') {
                // IMPOR: Wajib BM, PPH, PPN
                $bmVal = round($cifRupiah * ($tarifBm / 100), 2);
                $nilaiImpor = $cifRupiah + $bmVal;
                $pphVal = round($nilaiImpor * ($is_pph / 100), 2);
                $ppnVal = round($nilaiImpor * ($is_ppn / 100), 2);

                $kdTarif = '1';
                $tarFas = 100.0;

                // Nilai Bayar vs Nilai Fasilitas
                $isBayar = ($fasImpor === '1');
                $bmBayar = $isBayar ? $bmVal : 0.0;
                $bmFas = $isBayar ? 0.0 : $bmVal;
                $pphBayar = $isBayar ? $pphVal : 0.0;
                $pphFas = $isBayar ? 0.0 : $pphVal;
                $ppnBayar = $isBayar ? $ppnVal : 0.0;
                $ppnFas = $isBayar ? 0.0 : $ppnVal;
                $lunas = 0.0;

                // 1. BM
                $kdPung = 'BM';
                $insStmt->bind_param('ssiisssdsddddsd', 
                    $cmp_id, $nomor_aju, $seriB, $seriBb, $asal, $kdPung, $kdTarif, $tarifBm, $fasImpor, $tarFas, $bmBayar, $bmFas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // 2. PPH
                $kdPung = 'PPH';
                $insStmt->bind_param('ssiisssdsddddsd', 
                    $cmp_id, $nomor_aju, $seriB, $seriBb, $asal, $kdPung, $kdTarif, $is_pph, $fasImpor, $tarFas, $pphBayar, $pphFas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

                // 3. PPN
                $kdPung = 'PPN';
                $insStmt->bind_param('ssiisssdsddddsd', 
                    $cmp_id, $nomor_aju, $seriB, $seriBb, $asal, $kdPung, $kdTarif, $is_ppn, $fasImpor, $tarFas, $ppnBayar, $ppnFas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;

            } else {
                // LOKAL: PPN / PPNLOKAL
                $ppnVal = round($cifRupiah * ($is_ppn / 100), 2);
                $kdTarif = '1';
                $tarFas = 100.0;

                $isBayar = ($fasLokal === '1');
                $ppnBayar = $isBayar ? $ppnVal : 0.0;
                $ppnFas = $isBayar ? 0.0 : $ppnVal;
                $lunas = 0.0;

                $kdPung = 'PPN';
                $insStmt->bind_param('ssiisssdsddddsd', 
                    $cmp_id, $nomor_aju, $seriB, $seriBb, $asal, $kdPung, $kdTarif, $is_ppn, $fasLokal, $tarFas, $ppnBayar, $ppnFas, $lunas, $kodeSatuan, $jumlahSatuan);
                $insStmt->execute();
                $insertedCount++;
            }
        }

        $insStmt->close();

        return [
            'status' => 'OK',
            'message' => "Berhasil generate $insertedCount baris Bahan Baku Tarif.",
            'count' => $insertedCount
        ];
    }

    /**
     * 4. Auto-Hitung Pungutan (wf_gen_pungutan)
     * Mengelompokkan total bayar/fasilitas per kode pungutan & fasilitas,
     * membulatkan ke ribuan (CEIL/round), dan mengupdate bc_ceisa_pungutan.
     */
    function ceisa_calculate_pungutan($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header dokumen tidak ditemukan.'];
        }
        $cmp_id = $hdr['cmp_id'] ?? '';
        $kodeDok = trim($hdr['kode_dokumen'] ?? '');

        // Cek NPWP billing eksisting jika ada
        $existingNpwp = '';
        $pCheck = $koneksi->query("SELECT npwp_billing FROM bc_ceisa_pungutan WHERE nomor_aju = '$escAju' AND npwp_billing IS NOT NULL AND npwp_billing != '' LIMIT 1");
        if ($pCheck && $pr = $pCheck->fetch_assoc()) {
            $existingNpwp = $pr['npwp_billing'];
        }

        // 1. Rekap Bahan Baku Tarif
        $bbSummary = [];
        $bbSumTotal = 0;
        $resBb = $koneksi->query("SELECT kode_pungutan, kode_fasilitas, 
            SUM(COALESCE(nilai_bayar,0)) as total_bayar, 
            SUM(COALESCE(nilai_fasilitas,0)) as total_fasilitas, 
            SUM(COALESCE(nilai_sudah_dilunasi,0)) as total_lunas 
            FROM bc_ceisa_bahanbakutarif 
            WHERE nomor_aju = '$escAju' 
            GROUP BY kode_pungutan, kode_fasilitas");
        if ($resBb) {
            while ($r = $resBb->fetch_assoc()) {
                $tot = floatval($r['total_bayar']) + floatval($r['total_fasilitas']) + floatval($r['total_lunas']);
                if ($tot > 0) {
                    $bbSummary[] = [
                        'kode_pungutan' => trim($r['kode_pungutan']),
                        'kode_fasilitas' => trim($r['kode_fasilitas']),
                        'total' => $tot
                    ];
                    $bbSumTotal += $tot;
                }
            }
        }

        // 2. Rekap Barang Tarif
        $btSummary = [];
        $btSumTotal = 0;
        $resBt = $koneksi->query("SELECT kode_pungutan, kode_fasilitas, 
            SUM(COALESCE(nilai_bayar,0)) as total_bayar, 
            SUM(COALESCE(nilai_fasilitas,0)) as total_fasilitas, 
            SUM(COALESCE(nilai_sudah_dilunasi,0)) as total_lunas 
            FROM bc_ceisa_barangtarif 
            WHERE nomor_aju = '$escAju' 
            GROUP BY kode_pungutan, kode_fasilitas");
        if ($resBt) {
            while ($r = $resBt->fetch_assoc()) {
                $tot = floatval($r['total_bayar']) + floatval($r['total_fasilitas']) + floatval($r['total_lunas']);
                if ($tot > 0) {
                    $btSummary[] = [
                        'kode_pungutan' => trim($r['kode_pungutan']),
                        'kode_fasilitas' => trim($r['kode_fasilitas']),
                        'total' => $tot
                    ];
                    $btSumTotal += $tot;
                }
            }
        }

        // Prioritas penentuan sumber:
        // BC 25 biasanya bersumber dari Bahan Baku Tarif jika tersedia
        $finalSummary = [];
        $source = '';
        if (($kodeDok === '25' || $kodeDok === '261' || $kodeDok === '262') && $bbSumTotal > 0) {
            $finalSummary = $bbSummary;
            $source = 'Bahan Baku Tarif';
        } elseif ($btSumTotal > 0) {
            $finalSummary = $btSummary;
            $source = 'Barang Tarif';
        } elseif ($bbSumTotal > 0) {
            $finalSummary = $bbSummary;
            $source = 'Bahan Baku Tarif';
        }

        if (empty($finalSummary)) {
            return ['status' => 'WARNING', 'message' => 'Tidak ditemukan data tarif bernilai > 0 pada Barang Tarif maupun Bahan Baku Tarif.', 'count' => 0];
        }

        $newPungutan = [];
        foreach ($finalSummary as $item) {
            $kp = strtoupper($item['kode_pungutan']);
            if ($kp === 'PPNLOKAL' && $item['total'] == 0) continue;

            // Aturan pembulatan ribuan CEISA (CEIL ke atas ribuan / PMK)
            $rounded = ceil($item['total'] / 1000) * 1000;
            if ($rounded < 1000 && $item['total'] > 0) {
                $rounded = ceil($item['total']);
            }

            $newPungutan[] = [
                'cmp_id' => $cmp_id,
                'nomor_aju' => $nomor_aju,
                'kode_jenis_pungutan' => $item['kode_pungutan'],
                'kode_fasilitas_tarif' => $item['kode_fasilitas'],
                'nilai_pungutan' => $rounded,
                'npwp_billing' => $existingNpwp ?: null
            ];
        }

        $koneksi->query("DELETE FROM bc_ceisa_pungutan WHERE nomor_aju = '$escAju'");
        $insStmt = $koneksi->prepare("INSERT INTO bc_ceisa_pungutan (cmp_id, nomor_aju, kode_jenis_pungutan, kode_fasilitas_tarif, nilai_pungutan, npwp_billing) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($newPungutan as $np) {
            $valStr = (string)$np['nilai_pungutan'];
            $insStmt->bind_param('ssssss', $np['cmp_id'], $np['nomor_aju'], $np['kode_jenis_pungutan'], $np['kode_fasilitas_tarif'], $valStr, $np['npwp_billing']);
            $insStmt->execute();
        }
        $insStmt->close();

        return [
            'status' => 'OK',
            'message' => "Kalkulasi pungutan sukses! Dihitung " . count($newPungutan) . " jenis pungutan dari $source (pembulatan ribuan CEISA).",
            'source' => $source,
            'count' => count($newPungutan),
            'rows' => $newPungutan
        ];
    }

    /**
     * 5. MASTER ACTION: Auto-Generate Semua Tarif & Pungutan
     * Sinkronisasi header total CIF, generate Barang Tarif, generate Bahan Baku Tarif, dan hitung Pungutan.
     */
    function ceisa_auto_generate_all_tarifs($koneksi, $nomor_aju) {
        $resHeader = ceisa_sync_header_totals($koneksi, $nomor_aju);
        $resBarang = ceisa_generate_barang_tarif($koneksi, $nomor_aju);
        $resBahanBaku = ceisa_generate_bahanbaku_tarif($koneksi, $nomor_aju);
        $resPungutan = ceisa_calculate_pungutan($koneksi, $nomor_aju);

        return [
            'status' => 'OK',
            'message' => "Auto-generate sukses! Diperbarui " . intval($resBarang['count'] ?? 0) . " Barang Tarif, " . intval($resBahanBaku['count'] ?? 0) . " Bahan Baku Tarif, dan " . intval($resPungutan['count'] ?? 0) . " Pungutan. CIF Header disinkronkan (" . floatval($resHeader['cif'] ?? 0) . ").",
            'header' => $resHeader,
            'barang_tarif' => $resBarang,
            'bahanbaku_tarif' => $resBahanBaku,
            'pungutan' => $resPungutan
        ];
    }

    /**
     * 6. Utility FIX HS CODE (ue_fixhs)
     * Mengisi HS Code kosong dari bc_1ab2 atau riwayat bc_ceisa_barang / bahanbaku.
     */
    function ceisa_fix_hs_code($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        $fixedBarang = 0;
        $fixedBahanBaku = 0;

        // 1. Perbaiki bc_ceisa_barang
        $bRes = $koneksi->query("SELECT id, seri_barang, kode_barang, hs FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' AND (hs IS NULL OR hs = '' OR hs = '0' OR CAST(hs AS UNSIGNED) = 0)");
        if ($bRes) {
            while ($b = $bRes->fetch_assoc()) {
                $kb = $koneksi->real_escape_string(trim($b['kode_barang']));
                if (empty($kb)) continue;

                // Cari dari bc_1ab2
                $hsFound = '';
                $q1 = $koneksi->query("SELECT POS_TARIF FROM bc_1ab2 WHERE kode_barang = '$kb' AND CAST(POS_TARIF AS UNSIGNED) > 0 ORDER BY TANGGAL_DAFTAR DESC LIMIT 1");
                if ($q1 && $r1 = $q1->fetch_assoc()) {
                    $hsFound = trim($r1['POS_TARIF']);
                }

                // Fallback riwayat bc_ceisa_barang
                if (empty($hsFound)) {
                    $q2 = $koneksi->query("SELECT hs FROM bc_ceisa_barang WHERE kode_barang = '$kb' AND CAST(hs AS UNSIGNED) > 0 ORDER BY id DESC LIMIT 1");
                    if ($q2 && $r2 = $q2->fetch_assoc()) {
                        $hsFound = trim($r2['hs']);
                    }
                }

                if (!empty($hsFound)) {
                    $escHs = $koneksi->real_escape_string($hsFound);
                    $koneksi->query("UPDATE bc_ceisa_barang SET hs = '$escHs' WHERE id = " . intval($b['id']));
                    $fixedBarang++;
                }
            }
        }

        // 2. Perbaiki bc_ceisa_bahanbaku
        $bbRes = $koneksi->query("SELECT id, seri_barang, seri_bahan_baku, kode_barang, hs FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju' AND (hs IS NULL OR hs = '' OR hs = '0' OR CAST(hs AS UNSIGNED) = 0)");
        if ($bbRes) {
            while ($bb = $bbRes->fetch_assoc()) {
                $kb = $koneksi->real_escape_string(trim($bb['kode_barang']));
                if (empty($kb)) continue;

                $hsFound = '';
                $q1 = $koneksi->query("SELECT POS_TARIF FROM bc_1ab2 WHERE kode_barang = '$kb' AND CAST(POS_TARIF AS UNSIGNED) > 0 ORDER BY TANGGAL_DAFTAR DESC LIMIT 1");
                if ($q1 && $r1 = $q1->fetch_assoc()) {
                    $hsFound = trim($r1['POS_TARIF']);
                }

                if (empty($hsFound)) {
                    $q2 = $koneksi->query("SELECT hs FROM bc_ceisa_bahanbaku WHERE kode_barang = '$kb' AND CAST(hs AS UNSIGNED) > 0 ORDER BY id DESC LIMIT 1");
                    if ($q2 && $r2 = $q2->fetch_assoc()) {
                        $hsFound = trim($r2['hs']);
                    }
                }

                if (empty($hsFound)) {
                    $q3 = $koneksi->query("SELECT hs FROM bc_ceisa_barang WHERE kode_barang = '$kb' AND CAST(hs AS UNSIGNED) > 0 ORDER BY id DESC LIMIT 1");
                    if ($q3 && $r3 = $q3->fetch_assoc()) {
                        $hsFound = trim($r3['hs']);
                    }
                }

                if (!empty($hsFound)) {
                    $escHs = $koneksi->real_escape_string($hsFound);
                    $koneksi->query("UPDATE bc_ceisa_bahanbaku SET hs = '$escHs' WHERE id = " . intval($bb['id']));
                    $fixedBahanBaku++;
                }
            }
        }

        return [
            'status' => 'OK',
            'message' => "FIX HS CODE selesai! Diperbarui $fixedBarang baris Barang dan $fixedBahanBaku baris Bahan Baku.",
            'fixed_barang' => $fixedBarang,
            'fixed_bahanbaku' => $fixedBahanBaku
        ];
    }

    /**
     * 7. Utility FIX NETTO (ue_fixnetto)
     * Mengisi netto barang kosong dengan rasio historis (netto / jumlah_satuan) * current_jumlah_satuan.
     */
    function ceisa_fix_netto($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        $fixedCount = 0;

        $bRes = $koneksi->query("SELECT id, seri_barang, kode_barang, jumlah_satuan, netto FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' AND (netto IS NULL OR netto <= 0)");
        if ($bRes) {
            while ($b = $bRes->fetch_assoc()) {
                $kb = $koneksi->real_escape_string(trim($b['kode_barang']));
                $jml = floatval($b['jumlah_satuan'] ?? 0);
                if (empty($kb) || $jml <= 0) continue;

                // Cari rasio historis (netto / jumlah_satuan)
                $q = $koneksi->query("SELECT (netto / jumlah_satuan) as net_satuan FROM bc_ceisa_barang WHERE kode_barang = '$kb' AND netto > 0 AND jumlah_satuan > 0 ORDER BY id DESC LIMIT 1");
                if ($q && $r = $q->fetch_assoc()) {
                    $ratio = floatval($r['net_satuan']);
                    if ($ratio > 0) {
                        $calcNetto = round($ratio * $jml, 4);
                        $koneksi->query("UPDATE bc_ceisa_barang SET netto = $calcNetto WHERE id = " . intval($b['id']));
                        $fixedCount++;
                    }
                }
            }
        }

        return [
            'status' => 'OK',
            'message' => "FIX NETTO selesai! Diperbarui $fixedCount baris Barang berdasarkan riwayat rasio bobot satuan.",
            'fixed_count' => $fixedCount
        ];
    }

    /**
     * 8. Utility Edit Seri Barang (Re-sequence seri_barang 1..N)
     */
    function ceisa_edit_seri_barang($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $bRes = $koneksi->query("SELECT id, seri_barang FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' ORDER BY id ASC");
        if (!$bRes || $bRes->num_rows == 0) {
            return ['status' => 'WARNING', 'message' => 'Tidak ada data barang.', 'count' => 0];
        }

        $items = [];
        while ($r = $bRes->fetch_assoc()) {
            $items[] = $r;
        }

        $koneksi->begin_transaction();
        try {
            $seq = 1;
            foreach ($items as $item) {
                $oldSeri = intval($item['seri_barang']);
                $newSeri = $seq;
                $rowId = intval($item['id']);

                if ($oldSeri !== $newSeri) {
                    $koneksi->query("UPDATE bc_ceisa_barang SET seri_barang = $newSeri WHERE id = $rowId");
                    $koneksi->query("UPDATE bc_ceisa_barangtarif SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                    $koneksi->query("UPDATE bc_ceisa_barangdokumen SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                    $koneksi->query("UPDATE bc_ceisa_barangentitas SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                    $koneksi->query("UPDATE bc_ceisa_bahanbaku SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                    $koneksi->query("UPDATE bc_ceisa_bahanbakutarif SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                }
                $seq++;
            }
            $koneksi->commit();

            return [
                'status' => 'OK',
                'message' => "Urutan Seri Barang berhasil ditata ulang menjadi 1 s/d " . ($seq - 1) . ".",
                'count' => count($items)
            ];
        } catch (Exception $e) {
            $koneksi->rollback();
            return ['status' => 'ERROR', 'message' => 'Gagal merapikan seri barang: ' . $e->getMessage()];
        }
    }

    /**
     * 9. Utility Urutkan Seri Bahan Baku (wf_seq_bb)
     */
    function ceisa_seq_bahanbaku($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $bbRes = $koneksi->query("SELECT id, seri_barang, seri_bahan_baku FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju' ORDER BY seri_barang ASC, id ASC");
        if (!$bbRes || $bbRes->num_rows == 0) {
            return ['status' => 'OK', 'message' => 'Tidak ada bahan baku untuk diurutkan.', 'count' => 0];
        }

        $items = [];
        while ($r = $bbRes->fetch_assoc()) {
            $items[] = $r;
        }

        $koneksi->begin_transaction();
        try {
            $lastSeriBrg = null;
            $seqBb = 1;
            $updated = 0;

            foreach ($items as $item) {
                $currentBrg = intval($item['seri_barang']);
                if ($lastSeriBrg !== $currentBrg) {
                    $seqBb = 1;
                    $lastSeriBrg = $currentBrg;
                } else {
                    $seqBb++;
                }

                $rowId = intval($item['id']);
                $oldSeq = intval($item['seri_bahan_baku']);

                if ($oldSeq !== $seqBb) {
                    $koneksi->query("UPDATE bc_ceisa_bahanbaku SET seri_bahan_baku = $seqBb WHERE id = $rowId");
                    $koneksi->query("UPDATE bc_ceisa_bahanbakutarif SET seri_bahan_baku = $seqBb WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $oldSeq");
                    $koneksi->query("UPDATE bc_ceisa_bahanbakudokumen SET seri_bahan_baku = $seqBb WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $oldSeq");
                    $updated++;
                }
            }

            $koneksi->commit();
            return [
                'status' => 'OK',
                'message' => "Urutan Seri Bahan Baku berhasil ditata ulang per Seri Barang. $updated baris diperbarui.",
                'count' => count($items)
            ];
        } catch (Exception $e) {
            $koneksi->rollback();
            return ['status' => 'ERROR', 'message' => 'Gagal mengurutkan seri bahan baku: ' . $e->getMessage()];
        }
    }

    /**
     * 10. Delete All Trace (b_deltrace)
     * Menghapus seluruh data tracking BOM dan Dokumen Asal pada AJU aktif.
     */
    function ceisa_delete_all_trace($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        $koneksi->query("DELETE FROM bc_ceisa_tracebb WHERE aju = '$escAju' OR nomor_aju = '$escAju'");
        $koneksi->query("DELETE FROM bc_ceisa_barangbom WHERE aju = '$escAju'");

        return [
            'status' => 'OK',
            'message' => 'Seluruh data Trace BOM & Dokumen Asal pada AJU ini berhasil dihapus.'
        ];
    }

    /**
     * 11. Load All BOM dari Master (ue_bomall / ue_bomload)
     * Mengambil struktur bill of material dari bc_ceisa_bom ke bc_ceisa_barangbom.
     */
    function ceisa_load_all_bom($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $hRes = $koneksi->query("SELECT cmp_id FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        $cmp_id = ($hRes && $hr = $hRes->fetch_assoc()) ? $hr['cmp_id'] : '';

        // Ambil barang pada AJU
        $bRes = $koneksi->query("SELECT seri_barang, kode_barang, uraian, jumlah_satuan, kode_satuan, harga_penyerahan, cif FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'");
        if (!$bRes || $bRes->num_rows == 0) {
            return ['status' => 'WARNING', 'message' => 'Data barang kosong.'];
        }

        $insCount = 0;
        while ($b = $bRes->fetch_assoc()) {
            $seri = intval($b['seri_barang']);
            $kbParent = trim($b['kode_barang']);
            $jmlParent = floatval($b['jumlah_satuan'] ?? 1);
            if (empty($kbParent)) continue;

            $escKb = $koneksi->real_escape_string($kbParent);
            $bomRes = $koneksi->query("SELECT komponen, req_qty FROM bc_ceisa_bom WHERE kode_barang = '$escKb'");
            if ($bomRes && $bomRes->num_rows > 0) {
                while ($bom = $bomRes->fetch_assoc()) {
                    $komp = trim($bom['komponen']);
                    $reqQty = floatval($bom['req_qty'] ?? 1);
                    $totQty = round($reqQty * $jmlParent, 4);

                    $uuid = md5(uniqid(rand(), true));
                    $escKomp = $koneksi->real_escape_string($komp);

                    // Ambil satuan & uraian komponen dari master atau bc_1ab2
                    $sat = 'PCE'; $uraian = $komp;
                    $mRes = $koneksi->query("SELECT URAIAN, UNIT FROM bc_1ab2 WHERE KODE_BARANG = '$escKomp' LIMIT 1");
                    if ($mRes && $mr = $mRes->fetch_assoc()) {
                        if (!empty($mr['UNIT'])) $sat = trim($mr['UNIT']);
                        if (!empty($mr['URAIAN'])) $uraian = trim($mr['URAIAN']);
                    }

                    $escUraian = $koneksi->real_escape_string($uraian);
                    $escSat = $koneksi->real_escape_string($sat);

                    $sqlIns = "INSERT INTO bc_ceisa_barangbom 
                        (cmp_id, id, kode_barang_parent, kode_barang, uraian, kode_satuan, qty, aju, seri)
                        VALUES ('$cmp_id', '$uuid', '$escKb', '$escKomp', '$escUraian', '$escSat', $totQty, '$escAju', $seri)";
                    $koneksi->query($sqlIns);
                    $insCount++;
                }
            }
        }

        return [
            'status' => 'OK',
            'message' => "BOM berhasil dimuat! Ditambahkan $insCount baris komponen ke tabel tracking.",
            'count' => $insCount
        ];
    }

    /**
     * 12. Transfer Trace Ex-BC ke Bahan Baku (dw_barang.ue_bb)
     * Memindahkan data dari bc_ceisa_barangbom & bc_ceisa_tracebb langsung ke bc_ceisa_bahanbaku,
     * lalu otomatis mengurutkan seri bahan baku dan mengenerate bahan baku tarif!
     */
    function ceisa_transfer_trace_to_bb($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header dokumen tidak ditemukan.'];
        }
        $cmp_id = $hdr['cmp_id'] ?? '';
        $kodeDok = trim($hdr['kode_dokumen'] ?? '');
        $ndpbm = floatval($hdr['ndpbm'] ?? 1);
        if ($ndpbm <= 0) $ndpbm = 1;

        // Ambil trace items
        $sql = "SELECT 
            b.id_bom, b.aju, b.seri as seri_barang, b.kode_brg, b.nama_brg, b.sat, b.qty_use,
            b.jenis_bc, b.no_daftar, b.tgl_daftar, b.nomor_aju as nomor_aju_asal, b.seri_barang as seri_barang_asal,
            b.hs, b.gross, b.netto, b.harga, b.cif, b.ndpbm
        FROM bc_ceisa_tracebb b
        WHERE b.aju = '$escAju' OR b.nomor_aju = '$escAju'
        ORDER BY b.seri ASC, b.id ASC";

        $traceRes = $koneksi->query($sql);
        if (!$traceRes || $traceRes->num_rows == 0) {
            return ['status' => 'WARNING', 'message' => 'Tidak ditemukan data hasil Tracking Ex-BC untuk AJU ini. Silakan jalankan Mulai Trace terlebih dahulu!'];
        }

        $traceRows = [];
        while ($r = $traceRes->fetch_assoc()) {
            $traceRows[] = $r;
        }

        // Hapus bahan baku & tarif eksisting untuk AJU ini
        $koneksi->query("DELETE FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju'");
        $koneksi->query("DELETE FROM bc_ceisa_bahanbakutarif WHERE nomor_aju = '$escAju'");

        $insBb = $koneksi->prepare("INSERT INTO bc_ceisa_bahanbaku 
            (cmp_id, nomor_aju, seri_barang, seri_bahan_baku, kode_asal_bahan_baku, hs, kode_barang, uraian, merek, tipe, ukuran, spesifikasi_lain, kode_satuan, jumlah_satuan, kode_dokumen_asal, nomor_daftar_asal, tanggal_daftar_asal, nomor_aju_asal, seri_barang_asal, netto, bruto, cif, cif_rupiah, ndpbm, harga_penyerahan)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seqBb = 1;
        $lastSeri = null;
        $inserted = 0;

        foreach ($traceRows as $tr) {
            $seriBrg = intval($tr['seri_barang']);
            if ($lastSeri !== $seriBrg) {
                $seqBb = 1;
                $lastSeri = $seriBrg;
            } else {
                $seqBb++;
            }

            $jenisBc = trim($tr['jenis_bc'] ?? '');
            // 0 = Impor, 1 = Lokal (BC 40)
            $asalBb = ($jenisBc === '40') ? '1' : '0';

            $hs = trim($tr['hs'] ?? '');
            $kb = trim($tr['kode_brg'] ?? '-');
            $nama = trim($tr['nama_brg'] ?? '-');
            $merek = '-'; $tipe = '-'; $ukuran = '-'; $spek = '-';
            $sat = trim($tr['sat'] ?? 'PCE');
            $qty = floatval($tr['qty_use'] ?? 0);
            $noDaftar = trim($tr['no_daftar'] ?? '');
            $tglDaftar = trim($tr['tgl_daftar'] ?? '');
            $ajuAsal = trim($tr['nomor_aju_asal'] ?? '');
            $seriAsal = intval($tr['seri_barang_asal'] ?? 1);
            $netto = floatval($tr['netto'] ?? 0);
            $gross = floatval($tr['gross'] ?? 0);
            $cifUnit = floatval($tr['cif'] ?? 0);
            $cifTot = round($cifUnit * $qty, 2);
            $itemNdpbm = floatval($tr['ndpbm'] ?? $ndpbm);
            if ($itemNdpbm <= 0) $itemNdpbm = $ndpbm;
            $cifRupiah = round($cifTot * $itemNdpbm, 2);
            $hargaUnit = floatval($tr['harga'] ?? 0);
            $penyerahan = round($hargaUnit * $qty, 2);

            $insBb->bind_param('ssiissssssssdsdsssssddddd',
                $cmp_id, $nomor_aju, $seriBrg, $seqBb, $asalBb, $hs, $kb, $nama, $merek, $tipe, $ukuran, $spek,
                $sat, $qty, $jenisBc, $noDaftar, $tglDaftar, $ajuAsal, $seriAsal, $netto, $gross, $cifTot, $cifRupiah, $itemNdpbm, $penyerahan
            );
            $insBb->execute();
            $inserted++;
        }
        $insBb->close();

        // Otomatis generate Bahan Baku Tarif
        $resTarif = ceisa_generate_bahanbaku_tarif($koneksi, $nomor_aju);

        return [
            'status' => 'OK',
            'message' => "Sukses transfer $inserted baris ke Bahan Baku & dibuatkan otomatis " . intval($resTarif['count'] ?? 0) . " baris Bahan Baku Tarif!",
            'inserted_bb' => $inserted,
            'inserted_tarif' => $resTarif['count'] ?? 0
        ];
    }
}
?>

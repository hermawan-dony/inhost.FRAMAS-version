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
    if (!function_exists('ceisa_calc_pungutan')) {
        function ceisa_calc_pungutan($koneksi, $nomor_aju) {
            return ceisa_calculate_pungutan($koneksi, $nomor_aju);
        }
    }

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

        // 1. Bersihkan karakter non-digit pada HS Code yang sudah ada di bc_ceisa_barang
        $cleanB = $koneksi->query("SELECT seri_barang, hs FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' AND hs IS NOT NULL AND hs != ''");
        if ($cleanB) {
            while ($cb = $cleanB->fetch_assoc()) {
                $rawHs = trim($cb['hs']);
                $cleaned = preg_replace('/[^0-9]/', '', $rawHs);
                if ($cleaned !== $rawHs) {
                    $seriB = intval($cb['seri_barang']);
                    $koneksi->query("UPDATE bc_ceisa_barang SET hs = '$cleaned' WHERE nomor_aju = '$escAju' AND seri_barang = $seriB");
                    $fixedBarang++;
                }
            }
        }

        // Bersihkan karakter non-digit pada HS Code di bc_ceisa_bahanbaku
        $cleanBb = $koneksi->query("SELECT seri_barang, seri_bahan_baku, kode_barang, hs FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju' AND hs IS NOT NULL AND hs != ''");
        if ($cleanBb) {
            while ($cbb = $cleanBb->fetch_assoc()) {
                $rawHs = trim($cbb['hs']);
                $cleaned = preg_replace('/[^0-9]/', '', $rawHs);
                if ($cleaned !== $rawHs) {
                    $seriB = intval($cbb['seri_barang']);
                    $seriBb = intval($cbb['seri_bahan_baku']);
                    $kb = $koneksi->real_escape_string($cbb['kode_barang']);
                    $koneksi->query("UPDATE bc_ceisa_bahanbaku SET hs = '$cleaned' WHERE nomor_aju = '$escAju' AND seri_barang = $seriB AND seri_bahan_baku = $seriBb AND kode_barang = '$kb'");
                    $fixedBahanBaku++;
                }
            }
        }

        // Bersihkan juga pada bc_ceisa_tracebb jika ada
        $chkTrace = $koneksi->query("SHOW TABLES LIKE 'bc_ceisa_tracebb'");
        if ($chkTrace && $chkTrace->num_rows > 0) {
            $koneksi->query("UPDATE bc_ceisa_tracebb SET hs = REPLACE(REPLACE(REPLACE(hs, '.', ''), ' ', ''), '-', '') WHERE (aju = '$escAju' OR nomor_aju = '$escAju') AND hs IS NOT NULL AND hs != ''");
        }

        // 2. Isi HS Code kosong pada bc_ceisa_barang
        $bRes = $koneksi->query("SELECT seri_barang, kode_barang, hs FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' AND (hs IS NULL OR hs = '' OR hs = '0' OR CAST(hs AS UNSIGNED) = 0)");
        if ($bRes) {
            while ($b = $bRes->fetch_assoc()) {
                $seriB = intval($b['seri_barang']);
                $kb = $koneksi->real_escape_string(trim($b['kode_barang']));
                if (empty($kb)) continue;

                $hsFound = '';
                // Cek dari bc_1ab2
                $q1 = $koneksi->query("SELECT POS_TARIF FROM bc_1ab2 WHERE kode_barang = '$kb' AND CAST(POS_TARIF AS UNSIGNED) > 0 ORDER BY TANGGAL_DAFTAR DESC LIMIT 1");
                if ($q1 && $r1 = $q1->fetch_assoc()) {
                    $hsFound = trim($r1['POS_TARIF']);
                }

                // Cek dari master bc_material
                if (empty($hsFound)) {
                    $qMat = $koneksi->query("SELECT POS_TARIF FROM bc_material WHERE KODE_BARANG = '$kb' AND CAST(POS_TARIF AS UNSIGNED) > 0 LIMIT 1");
                    if ($qMat && $rm = $qMat->fetch_assoc()) {
                        $hsFound = trim($rm['POS_TARIF']);
                    }
                }

                // Cek dari riwayat transaksi bc_ceisa_barang terdahulu
                if (empty($hsFound)) {
                    $q2 = $koneksi->query("SELECT hs FROM bc_ceisa_barang WHERE kode_barang = '$kb' AND CAST(hs AS UNSIGNED) > 0 ORDER BY nomor_aju DESC, seri_barang DESC LIMIT 1");
                    if ($q2 && $r2 = $q2->fetch_assoc()) {
                        $hsFound = trim($r2['hs']);
                    }
                }

                if (!empty($hsFound)) {
                    $cleanedHs = preg_replace('/[^0-9]/', '', $hsFound);
                    if (!empty($cleanedHs)) {
                        $escHs = $koneksi->real_escape_string($cleanedHs);
                        $koneksi->query("UPDATE bc_ceisa_barang SET hs = '$escHs' WHERE nomor_aju = '$escAju' AND seri_barang = $seriB");
                        $fixedBarang++;
                    }
                }
            }
        }

        // 3. Isi HS Code kosong pada bc_ceisa_bahanbaku
        $bbRes = $koneksi->query("SELECT seri_barang, seri_bahan_baku, kode_barang, hs FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju' AND (hs IS NULL OR hs = '' OR hs = '0' OR CAST(hs AS UNSIGNED) = 0)");
        if ($bbRes) {
            while ($bb = $bbRes->fetch_assoc()) {
                $seriB = intval($bb['seri_barang']);
                $seriBb = intval($bb['seri_bahan_baku']);
                $kb = $koneksi->real_escape_string(trim($bb['kode_barang']));
                if (empty($kb)) continue;

                $hsFound = '';
                $q1 = $koneksi->query("SELECT POS_TARIF FROM bc_1ab2 WHERE kode_barang = '$kb' AND CAST(POS_TARIF AS UNSIGNED) > 0 ORDER BY TANGGAL_DAFTAR DESC LIMIT 1");
                if ($q1 && $r1 = $q1->fetch_assoc()) {
                    $hsFound = trim($r1['POS_TARIF']);
                }

                if (empty($hsFound)) {
                    $qMat = $koneksi->query("SELECT POS_TARIF FROM bc_material WHERE KODE_BARANG = '$kb' AND CAST(POS_TARIF AS UNSIGNED) > 0 LIMIT 1");
                    if ($qMat && $rm = $qMat->fetch_assoc()) {
                        $hsFound = trim($rm['POS_TARIF']);
                    }
                }

                if (empty($hsFound)) {
                    $q2 = $koneksi->query("SELECT hs FROM bc_ceisa_bahanbaku WHERE kode_barang = '$kb' AND CAST(hs AS UNSIGNED) > 0 ORDER BY nomor_aju DESC, seri_barang DESC, seri_bahan_baku DESC LIMIT 1");
                    if ($q2 && $r2 = $q2->fetch_assoc()) {
                        $hsFound = trim($r2['hs']);
                    }
                }

                if (empty($hsFound)) {
                    $q3 = $koneksi->query("SELECT hs FROM bc_ceisa_barang WHERE kode_barang = '$kb' AND CAST(hs AS UNSIGNED) > 0 ORDER BY nomor_aju DESC, seri_barang DESC LIMIT 1");
                    if ($q3 && $r3 = $q3->fetch_assoc()) {
                        $hsFound = trim($r3['hs']);
                    }
                }

                if (!empty($hsFound)) {
                    $cleanedHs = preg_replace('/[^0-9]/', '', $hsFound);
                    if (!empty($cleanedHs)) {
                        $escHs = $koneksi->real_escape_string($cleanedHs);
                        $koneksi->query("UPDATE bc_ceisa_bahanbaku SET hs = '$escHs' WHERE nomor_aju = '$escAju' AND seri_barang = $seriB AND seri_bahan_baku = $seriBb AND kode_barang = '$kb'");
                        $fixedBahanBaku++;
                    }
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
     * Mengisi netto barang kosong dengan rasio historis (netto / jumlah_satuan) * current_jumlah_satuan,
     * mengecek master material bc_material, menstandarkan netto <= bruto, serta menyinkronkan total header.
     */
    function ceisa_fix_netto($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        $fixedCount = 0;

        $bRes = $koneksi->query("SELECT seri_barang, kode_barang, jumlah_satuan, netto, bruto FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'");
        if ($bRes) {
            while ($b = $bRes->fetch_assoc()) {
                $seri = intval($b['seri_barang']);
                $kb = $koneksi->real_escape_string(trim($b['kode_barang']));
                $jml = floatval($b['jumlah_satuan'] ?? 0);
                $netto = floatval($b['netto'] ?? 0);
                $bruto = floatval($b['bruto'] ?? 0);
                $changed = false;

                if ($netto <= 0) {
                    $calcNetto = 0;
                    // 1. Rasio historis dari bc_ceisa_barang
                    if (!empty($kb) && $jml > 0) {
                        $q = $koneksi->query("SELECT (netto / jumlah_satuan) as net_satuan FROM bc_ceisa_barang WHERE kode_barang = '$kb' AND netto > 0 AND jumlah_satuan > 0 ORDER BY nomor_aju DESC, seri_barang DESC LIMIT 1");
                        if ($q && $r = $q->fetch_assoc()) {
                            $ratio = floatval($r['net_satuan']);
                            if ($ratio > 0) {
                                $calcNetto = round($ratio * $jml, 4);
                            }
                        }
                    }

                    // 2. Master berat dari bc_material
                    if ($calcNetto <= 0 && !empty($kb) && $jml > 0) {
                        $qMat = $koneksi->query("SELECT BERAT FROM bc_material WHERE KODE_BARANG = '$kb' AND BERAT > 0 LIMIT 1");
                        if ($qMat && $rm = $qMat->fetch_assoc()) {
                            $calcNetto = round(floatval($rm['BERAT']) * $jml, 4);
                        }
                    }

                    // 3. Fallback dari bruto
                    if ($calcNetto <= 0 && $bruto > 0) {
                        $calcNetto = round($bruto * 0.9, 4);
                    }

                    if ($calcNetto > 0) {
                        $netto = $calcNetto;
                        $changed = true;
                    }
                }

                // Validasi Bruto: Bruto harus >= Netto
                if ($netto > 0) {
                    if ($bruto <= 0 || $bruto < $netto) {
                        $bruto = round($netto * 1.05, 4);
                        $changed = true;
                    }
                }

                if ($changed) {
                    $koneksi->query("UPDATE bc_ceisa_barang SET netto = $netto, bruto = $bruto WHERE nomor_aju = '$escAju' AND seri_barang = $seri");
                    $fixedCount++;
                }
            }
        }

        // Sinkronkan ke akumulasi header
        ceisa_sync_header_totals($koneksi, $nomor_aju);

        return [
            'status' => 'OK',
            'message' => "FIX NETTO selesai! Diperbarui $fixedCount baris Barang beserta sinkronisasi total bobot Header.",
            'fixed_count' => $fixedCount
        ];
    }

    /**
     * 8. Utility Edit Seri Barang (Re-sequence seri_barang 1..N)
     */
    function ceisa_edit_seri_barang($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $bRes = $koneksi->query("SELECT seri_barang, kode_barang FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' ORDER BY seri_barang ASC");
        if (!$bRes || $bRes->num_rows == 0) {
            return ['status' => 'WARNING', 'message' => 'Tidak ada data barang.', 'count' => 0];
        }

        $items = [];
        while ($r = $bRes->fetch_assoc()) {
            $items[] = $r;
        }

        $koneksi->begin_transaction();
        try {
            // Step 1: Assign temporary negative values to avoid duplicate key conflicts on PK (cmp_id, nomor_aju, seri_barang)
            foreach ($items as $idx => $item) {
                $oldSeri = intval($item['seri_barang']);
                $tempSeri = -($idx + 1);
                $kb = $koneksi->real_escape_string($item['kode_barang']);

                $koneksi->query("UPDATE bc_ceisa_barang SET seri_barang = $tempSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri AND kode_barang = '$kb' LIMIT 1");
                $koneksi->query("UPDATE bc_ceisa_barangtarif SET seri_barang = $tempSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                $koneksi->query("UPDATE bc_ceisa_barangdokumen SET seri_barang = $tempSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                $koneksi->query("UPDATE bc_ceisa_barangentitas SET seri_barang = $tempSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                $koneksi->query("UPDATE bc_ceisa_bahanbaku SET seri_barang = $tempSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                $koneksi->query("UPDATE bc_ceisa_bahanbakutarif SET seri_barang = $tempSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
                $koneksi->query("UPDATE bc_ceisa_bahanbakudokumen SET seri_barang = $tempSeri WHERE nomor_aju = '$escAju' AND seri_barang = $oldSeri");
            }

            // Step 2: Assign sequential 1..N
            $seq = 1;
            foreach ($items as $idx => $item) {
                $tempSeri = -($idx + 1);
                $newSeri = $seq;

                $koneksi->query("UPDATE bc_ceisa_barang SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $tempSeri LIMIT 1");
                $koneksi->query("UPDATE bc_ceisa_barangtarif SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $tempSeri");
                $koneksi->query("UPDATE bc_ceisa_barangdokumen SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $tempSeri");
                $koneksi->query("UPDATE bc_ceisa_barangentitas SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $tempSeri");
                $koneksi->query("UPDATE bc_ceisa_bahanbaku SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $tempSeri");
                $koneksi->query("UPDATE bc_ceisa_bahanbakutarif SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $tempSeri");
                $koneksi->query("UPDATE bc_ceisa_bahanbakudokumen SET seri_barang = $newSeri WHERE nomor_aju = '$escAju' AND seri_barang = $tempSeri");
                $seq++;
            }
            $koneksi->commit();

            return [
                'status' => 'OK',
                'message' => "Urutan Seri Barang berhasil ditata ulang menjadi 1 s/d " . ($seq - 1) . ".",
                'count' => count($items)
            ];
        } catch (\Throwable $e) {
            $koneksi->rollback();
            return ['status' => 'ERROR', 'message' => 'Gagal merapikan seri barang: ' . $e->getMessage()];
        }
    }

    /**
     * 9. Utility Urutkan Seri Bahan Baku (wf_seq_bb)
     */
    function ceisa_seq_bahanbaku($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $bbRes = $koneksi->query("SELECT seri_barang, seri_bahan_baku, kode_barang, kode_dokumen_asal, nomor_daftar_asal FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju' ORDER BY seri_barang ASC, seri_bahan_baku ASC, kode_barang ASC");
        if (!$bbRes || $bbRes->num_rows == 0) {
            return ['status' => 'OK', 'message' => 'Tidak ada bahan baku untuk diurutkan.', 'count' => 0];
        }

        $items = [];
        while ($r = $bbRes->fetch_assoc()) {
            $items[] = $r;
        }

        $koneksi->begin_transaction();
        try {
            // Step 1: Assign temporary negative values to avoid collisions
            foreach ($items as $idx => $item) {
                $currentBrg = intval($item['seri_barang']);
                $oldSeq = intval($item['seri_bahan_baku']);
                $kb = $koneksi->real_escape_string($item['kode_barang']);
                $tempSeq = -($idx + 1);

                $koneksi->query("UPDATE bc_ceisa_bahanbaku SET seri_bahan_baku = $tempSeq WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $oldSeq AND kode_barang = '$kb' LIMIT 1");
                $koneksi->query("UPDATE bc_ceisa_bahanbakutarif SET seri_bahan_baku = $tempSeq WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $oldSeq");
                $koneksi->query("UPDATE bc_ceisa_bahanbakudokumen SET seri_bahan_baku = $tempSeq WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $oldSeq");
            }

            // Step 2: Assign sequential 1..N per seri_barang
            $lastSeriBrg = null;
            $seqBb = 1;
            $updated = 0;

            foreach ($items as $idx => $item) {
                $currentBrg = intval($item['seri_barang']);
                if ($lastSeriBrg !== $currentBrg) {
                    $seqBb = 1;
                    $lastSeriBrg = $currentBrg;
                } else {
                    $seqBb++;
                }

                $tempSeq = -($idx + 1);
                $koneksi->query("UPDATE bc_ceisa_bahanbaku SET seri_bahan_baku = $seqBb WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $tempSeq LIMIT 1");
                $koneksi->query("UPDATE bc_ceisa_bahanbakutarif SET seri_bahan_baku = $seqBb WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $tempSeq");
                $koneksi->query("UPDATE bc_ceisa_bahanbakudokumen SET seri_bahan_baku = $seqBb WHERE nomor_aju = '$escAju' AND seri_barang = $currentBrg AND seri_bahan_baku = $tempSeq");
                $updated++;
            }

            $koneksi->commit();
            return [
                'status' => 'OK',
                'message' => "Urutan Seri Bahan Baku berhasil ditata ulang per Seri Barang. $updated baris diperbarui.",
                'count' => count($items)
            ];
        } catch (\Throwable $e) {
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
     * Jika targetSeri dispesifikasikan, hanya memuat BOM untuk seri barang tersebut.
     */
    function ceisa_load_all_bom($koneksi, $nomor_aju, $targetSeri = null) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        $hRes = $koneksi->query("SELECT cmp_id FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        $cmp_id = ($hRes && $hr = $hRes->fetch_assoc()) ? $hr['cmp_id'] : '';

        // Bersihkan BOM lama: jika per seri, hapus seri tersebut; jika all, hapus seluruh aju
        if ($targetSeri !== null && intval($targetSeri) > 0) {
            $tSeri = intval($targetSeri);
            $koneksi->query("DELETE FROM bc_ceisa_barangbom WHERE aju = '$escAju' AND seri = $tSeri");
            $seriSql = " AND seri_barang = $tSeri";
        } else {
            $koneksi->query("DELETE FROM bc_ceisa_barangbom WHERE aju = '$escAju'");
            $seriSql = "";
        }

        // Ambil barang pada AJU
        $bRes = $koneksi->query("SELECT seri_barang, kode_barang, uraian, jumlah_satuan, kode_satuan, harga_penyerahan, cif FROM bc_ceisa_barang WHERE nomor_aju = '$escAju' $seriSql");
        if (!$bRes || $bRes->num_rows == 0) {
            return ['status' => 'WARNING', 'message' => 'Data barang kosong atau tidak ditemukan.'];
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

        $msg = ($targetSeri !== null && intval($targetSeri) > 0)
            ? "BOM Barang Seri #$targetSeri berhasil dimuat! ($insCount komponen)"
            : "BOM seluruh barang berhasil dimuat! Ditambahkan $insCount baris komponen ke tabel tracking.";

        return [
            'status' => 'OK',
            'message' => $msg,
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

        // Otomatis generate Bahan Baku Tarif & Pungutan
        $resTarif = ceisa_generate_bahanbaku_tarif($koneksi, $nomor_aju);
        ceisa_calc_pungutan($koneksi, $nomor_aju);

        return [
            'status' => 'OK',
            'message' => "Sukses transfer $inserted baris ke Bahan Baku & dibuatkan otomatis " . intval($resTarif['count'] ?? 0) . " baris Bahan Baku Tarif beserta rekonsiliasi Pungutan!",
            'inserted_bb' => $inserted,
            'inserted_tarif' => $resTarif['count'] ?? 0
        ];
    }

    /**
     * 13. Mesin Trace FIFO (ue_trace & ue_traceall)
     * Otomatis mencocokkan kebutuhan bahan baku dari BOM dengan saldo pemasukan di bc_1ab2 (AB = 'A') secara First-In First-Out.
     */
    function ceisa_trace_fifo($koneksi, $nomor_aju) {
        $escAju = $koneksi->real_escape_string($nomor_aju);

        // Ambil info header dokumen
        $hRes = $koneksi->query("SELECT cmp_id, kode_dokumen, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header dokumen tidak ditemukan.'];
        }
        $cmp_id = $hdr['cmp_id'] ?? '';
        $ndpbm = floatval($hdr['ndpbm'] ?? 1);
        if ($ndpbm <= 0) $ndpbm = 1;

        // Pastikan BOM terisi di bc_ceisa_barangbom
        $chkBom = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_barangbom WHERE aju = '$escAju'")->fetch_row()[0] ?? 0;
        if ($chkBom == 0) {
            $resBom = ceisa_load_all_bom($koneksi, $nomor_aju);
            if ($resBom['status'] === 'ERROR') {
                return $resBom;
            }
        }

        // Ambil seluruh komponen BOM yang dibutuhkan
        $bomsRes = $koneksi->query("SELECT id, seri, kode_barang_parent, kode_barang, uraian, kode_satuan, qty FROM bc_ceisa_barangbom WHERE aju = '$escAju' ORDER BY seri ASC, id ASC");
        if (!$bomsRes || $bomsRes->num_rows == 0) {
            return ['status' => 'WARNING', 'message' => 'Tidak ada komponen BOM yang ditemukan untuk AJU ini. Pastikan Master BOM telah diset.'];
        }

        $boms = [];
        while ($r = $bomsRes->fetch_assoc()) {
            $boms[] = $r;
        }

        // Bersihkan data trace lama untuk AJU ini
        $koneksi->query("DELETE FROM bc_ceisa_tracebb WHERE aju = '$escAju' OR nomor_aju = '$escAju'");

        $seq = 0;
        $totalTracedRows = 0;
        $unmatchedItems = 0;
        $insStmt = $koneksi->prepare("INSERT INTO bc_ceisa_tracebb 
            (cmp_id, id, aju, nomor_aju, seri, seri_barang, id_bom, id_barang,
             kode_brg, nama_brg, sat, qty_use,
             jenis_bc, no_daftar, tgl_daftar,
             hs, nama_bs, harga, cif, ndpbm, gross, netto, set_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $setBy = 'AUTO-FIFO ' . date('Ymd H:i');

        foreach ($boms as $bom) {
            $idBom = $bom['id'];
            $seriBrg = intval($bom['seri']);
            $kdKomp = trim($bom['kode_barang']);
            $uraianKomp = trim($bom['uraian'] ?? $kdKomp);
            $satKomp = trim($bom['kode_satuan'] ?? 'PCE');
            $qtyNeeded = floatval($bom['qty'] ?? 0);
            if ($qtyNeeded <= 0) continue;

            $remQty = $qtyNeeded;
            $escKomp = $koneksi->real_escape_string($kdKomp);

            // Cari saldo masuk di bc_1ab2 (AB = 'A') diurutkan FIFO: TANGGAL_DAFTAR ASC, ID ASC
            $sqlStock = "SELECT 
                a.ID, a.BC, a.NOMOR_AJU, a.NOMOR_DAFTAR, a.TANGGAL_DAFTAR, a.SERI_BARANG,
                a.KODE_BARANG, a.URAIAN, COALESCE(a.QTY_AKTUAL, a.QTY, 0) as QTY_AKTUAL, a.UNIT,
                a.POS_TARIF, a.NILAI, a.HARGA_PENYERAHAN, a.NDPBM, a.CIF, a.NAMA_BS, a.GROSS, a.NETTO,
                COALESCE((SELECT SUM(x.qty_use) FROM bc_ceisa_tracebb x WHERE x.nomor_aju = a.NOMOR_AJU AND x.seri_barang = a.SERI_BARANG), 0) as used_qty
                FROM bc_1ab2 a
                WHERE a.AB = 'A' AND a.KODE_BARANG = '$escKomp'
                HAVING (QTY_AKTUAL - used_qty) > 0.000001
                ORDER BY a.TANGGAL_DAFTAR ASC, a.ID ASC";

            $stockRes = $koneksi->query($sqlStock);
            if ($stockRes && $stockRes->num_rows > 0) {
                while ($stock = $stockRes->fetch_assoc()) {
                    if ($remQty <= 0.000001) break;

                    $avail = floatval($stock['QTY_AKTUAL']) - floatval($stock['used_qty']);
                    if ($avail <= 0) continue;

                    $takeQty = min($remQty, $avail);
                    $seq++;
                    $uuid = md5(uniqid(rand(), true));

                    $bcAsal = trim($stock['BC'] ?? '23');
                    $noDaftar = trim($stock['NOMOR_DAFTAR'] ?? '');
                    $tglDaftar = trim($stock['TANGGAL_DAFTAR'] ?? '');
                    $ajuAsal = trim($stock['NOMOR_AJU'] ?? '');
                    $seriAsal = intval($stock['SERI_BARANG'] ?? 1);
                    $hs = preg_replace('/[^0-9]/', '', trim($stock['POS_TARIF'] ?? ''));
                    $supplier = trim($stock['NAMA_BS'] ?? '');
                    $itemNdpbm = floatval($stock['NDPBM'] ?? $ndpbm);
                    if ($itemNdpbm <= 0) $itemNdpbm = $ndpbm;

                    $stockQty = floatval($stock['QTY_AKTUAL']);
                    if ($stockQty <= 0) $stockQty = 1;
                    $unitCif = floatval($stock['CIF'] ?? 0) / $stockQty;
                    if ($unitCif <= 0 && floatval($stock['NILAI'] ?? 0) > 0) {
                        $unitCif = floatval($stock['NILAI']) / $stockQty;
                    }
                    $unitHarga = floatval($stock['HARGA_PENYERAHAN'] ?? 0) / $stockQty;
                    if ($unitHarga <= 0) $unitHarga = $unitCif * $itemNdpbm;

                    $itemGross = round((floatval($stock['GROSS'] ?? 0) / $stockQty) * $takeQty, 4);
                    $itemNetto = round((floatval($stock['NETTO'] ?? 0) / $stockQty) * $takeQty, 4);

                    $insStmt->bind_param('ssssiisissssdsssdddddds',
                        $cmp_id, $uuid, $nomor_aju, $ajuAsal, $seq, $seriAsal, $idBom, $seriBrg,
                        $kdKomp, $uraianKomp, $satKomp, $takeQty,
                        $bcAsal, $noDaftar, $tglDaftar,
                        $hs, $supplier, $unitHarga, $unitCif, $itemNdpbm, $itemGross, $itemNetto, $setBy
                    );
                    $insStmt->execute();
                    $totalTracedRows++;

                    $remQty -= $takeQty;
                }
            }

            // Jika masih ada sisa kebutuhan yang belum teralokasi dari bc_1ab2
            if ($remQty > 0.000001) {
                $unmatchedItems++;
                $seq++;
                $uuid = md5(uniqid(rand(), true));

                // Ambil info master material jika ada
                $hsFallback = ''; $hargaFallback = 0; $satFallback = $satKomp;
                $matRes = $koneksi->query("SELECT POS_TARIF, UNIT_PRICE, KODE_SATUAN FROM bc_material WHERE KODE_BARANG = '$escKomp' LIMIT 1");
                if ($matRes && $mr = $matRes->fetch_assoc()) {
                    $hsFallback = preg_replace('/[^0-9]/', '', trim($mr['POS_TARIF'] ?? ''));
                    $hargaFallback = floatval($mr['UNIT_PRICE'] ?? 0);
                    if (!empty($mr['KODE_SATUAN'])) $satFallback = trim($mr['KODE_SATUAN']);
                }
                $cifFallback = ($ndpbm > 0) ? round($hargaFallback / $ndpbm, 4) : 0;
                $bcFallback = '40'; // Ditandai sebagai lokal / standby

                $insStmt->bind_param('ssssiisissssdsssdddddds',
                    $cmp_id, $uuid, $nomor_aju, '-', $seq, 1, $idBom, $seriBrg,
                    $kdKomp, $uraianKomp, $satFallback, $remQty,
                    $bcFallback, '-', CURDATE(),
                    $hsFallback, 'LOKAL / STANDBY IT INVENTORY', $hargaFallback, $cifFallback, $ndpbm, 0, 0, $setBy
                );
                $insStmt->execute();
                $totalTracedRows++;
            }
        }
        $insStmt->close();

        // Update rata-rata harga dan CIF kembali ke bc_ceisa_barangbom
        $koneksi->query("UPDATE bc_ceisa_barangbom ni
            JOIN (
                SELECT id_bom,
                    SUM(COALESCE(harga, 0) * qty_use) / NULLIF(SUM(qty_use), 0) AS avg_harga,
                    SUM(COALESCE(cif, 0) * qty_use) / NULLIF(SUM(qty_use), 0) AS avg_cif
                FROM bc_ceisa_tracebb
                WHERE aju = '$escAju'
                GROUP BY id_bom
            ) na ON ni.id = na.id_bom
            SET ni.harga_penyerahan = ROUND(ni.qty * COALESCE(na.avg_harga, 0), 2),
                ni.cif = ROUND(ni.qty * COALESCE(na.avg_cif, 0), 2)
            WHERE ni.aju = '$escAju'");

        return [
            'status' => 'OK',
            'message' => "Trace FIFO selesai! Berhasil menghubungkan $totalTracedRows baris dokumen asal ke BOM. " .
                         ($unmatchedItems > 0 ? "($unmatchedItems komponen dialokasikan saldo standby)." : ""),
            'total_rows' => $totalTracedRows,
            'unmatched' => $unmatchedItems
        ];
    }

    /**
     * 14. Quick Picker Master Barang (bc_material)
     */
    function ceisa_get_material_picker($koneksi, $search = '', $klas = '', $limit = 60) {
        $where = ["(AKTIF = 1 OR AKTIF IS NULL OR AKTIF = 'Y' OR AKTIF = '1')"];
        if (!empty($search)) {
            $esc = $koneksi->real_escape_string($search);
            $where[] = "(KODE_BARANG LIKE '%$esc%' OR URAIAN LIKE '%$esc%' OR MODEL LIKE '%$esc%' OR POS_TARIF LIKE '%$esc%')";
        }
        if (!empty($klas)) {
            $escK = $koneksi->real_escape_string($klas);
            $where[] = "KLAS = '$escK'";
        }
        $whereSql = implode(' AND ', $where);
        $q = $koneksi->query("SELECT KODE_BARANG, URAIAN, MODEL, POS_TARIF, KODE_SATUAN, UNIT_PRICE, BERAT, KLAS 
            FROM bc_material WHERE $whereSql ORDER BY KODE_BARANG ASC LIMIT " . intval($limit));
        $data = [];
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $data[] = [
                    'kode_barang' => $r['KODE_BARANG'],
                    'uraian' => $r['URAIAN'],
                    'model' => $r['MODEL'],
                    'pos_tarif' => preg_replace('/[^0-9]/', '', $r['POS_TARIF'] ?? ''),
                    'kode_satuan' => $r['KODE_SATUAN'] ?: 'PCE',
                    'unit_price' => floatval($r['UNIT_PRICE'] ?? 0),
                    'berat' => floatval($r['BERAT'] ?? 0),
                    'klas' => $r['KLAS']
                ];
            }
        }
        return ['status' => 'OK', 'data' => $data];
    }

    /**
     * 15. Insert Barang dari Master (Quick Picker bc_material)
     */
    function ceisa_insert_from_material($koneksi, $nomor_aju, $items) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        if (empty($items) || !is_array($items)) {
            return ['status' => 'ERROR', 'message' => 'Tidak ada barang yang dipilih.'];
        }

        $hRes = $koneksi->query("SELECT cmp_id, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header AJU tidak ditemukan.'];
        }
        $cmp_id = $hdr['cmp_id'] ?? '';
        $ndpbm = floatval($hdr['ndpbm'] ?? 1);
        if ($ndpbm <= 0) $ndpbm = 1;

        $maxSeri = intval($koneksi->query("SELECT MAX(seri_barang) FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'")->fetch_row()[0] ?? 0);

        $insStmt = $koneksi->prepare("INSERT INTO bc_ceisa_barang 
            (cmp_id, nomor_aju, seri_barang, kode_barang, hs, uraian, merek, tipe, ukuran, spesifikasi_lain, 
             kode_satuan, jumlah_satuan, kode_kemasan, jumlah_kemasan, netto, bruto, volume, cif, cif_rupiah, fob, harga_satuan, harga_penyerahan, nilai_barang, kode_guna_barang, kode_kondisi_barang)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $inserted = 0;
        foreach ($items as $it) {
            $maxSeri++;
            $kb = trim($it['kode_barang'] ?? '');
            if (empty($kb)) continue;

            $hs = preg_replace('/[^0-9]/', '', trim($it['pos_tarif'] ?? ''));
            $uraian = trim($it['uraian'] ?? $kb);
            $sat = trim($it['kode_satuan'] ?? 'PCE');
            $jml = floatval($it['jumlah_satuan'] ?? 1);
            if ($jml <= 0) $jml = 1;
            $hargaSat = floatval($it['unit_price'] ?? 0);
            $cif = round($jml * $hargaSat, 2);
            $cifRupiah = round($cif * $ndpbm, 2);
            $berat = floatval($it['berat'] ?? 0);
            $netto = round($berat * $jml, 4);
            if ($netto <= 0) $netto = 1.0;
            $bruto = round($netto * 1.05, 4);
            $merek = '-'; $tipe = '-'; $ukuran = '-'; $spek = '-';
            $kemasan = 'PK'; $jmlKemas = 1.0; $vol = 0.0;
            $guna = '1'; $kondisi = '1';

            $insStmt->bind_param('ssissssssssdssddddddddsss',
                $cmp_id, $nomor_aju, $maxSeri, $kb, $hs, $uraian, $merek, $tipe, $ukuran, $spek,
                $sat, $jml, $kemasan, $jmlKemas, $netto, $bruto, $vol, $cif, $cifRupiah, $cif, $hargaSat, $cifRupiah, $cifRupiah, $guna, $kondisi
            );
            $insStmt->execute();
            $inserted++;
        }
        $insStmt->close();

        ceisa_sync_header_totals($koneksi, $nomor_aju);

        return [
            'status' => 'OK',
            'message' => "Berhasil menambahkan $inserted barang dari master ke dokumen AJU ini!",
            'inserted_count' => $inserted
        ];
    }

    /**
     * 15b. Insert Komponen BOM dari Master Barang (Quick Picker bc_material)
     */
    function ceisa_insert_bom_from_material($koneksi, $nomor_aju, $seri, $kode_barang_parent, $items) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        $seri = intval($seri);
        $escParent = $koneksi->real_escape_string($kode_barang_parent);

        if (empty($items) || !is_array($items)) {
            return ['status' => 'ERROR', 'message' => 'Tidak ada komponen yang dipilih.'];
        }

        $hRes = $koneksi->query("SELECT cmp_id FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        $cmp_id = ($hRes && $hr = $hRes->fetch_assoc()) ? $hr['cmp_id'] : '';

        $insCount = 0;
        foreach ($items as $it) {
            $kb = trim($it['kode_barang'] ?? '');
            if (empty($kb)) continue;
            $uraian = trim($it['uraian'] ?? $kb);
            $sat = trim($it['kode_satuan'] ?? 'PCE');
            $qty = floatval($it['qty'] ?? 1);
            if ($qty <= 0) $qty = 1;
            $uuid = md5(uniqid(rand(), true));

            $escKb = $koneksi->real_escape_string($kb);
            $escUraian = $koneksi->real_escape_string($uraian);
            $escSat = $koneksi->real_escape_string($sat);

            $sql = "INSERT INTO bc_ceisa_barangbom 
                (cmp_id, id, kode_barang_parent, kode_barang, uraian, kode_satuan, qty, aju, seri)
                VALUES ('$cmp_id', '$uuid', '$escParent', '$escKb', '$escUraian', '$escSat', $qty, '$escAju', $seri)";
            if ($koneksi->query($sql)) {
                $insCount++;
            }
        }

        return [
            'status' => 'OK',
            'message' => "Berhasil menambahkan $insCount komponen ke BOM Barang Seri #$seri ($kode_barang_parent)!",
            'inserted_count' => $insCount
        ];
    }

    /**
     * 16. Quick Picker Dokumen Masuk Eks BC (bc_1ab2 AB = 'A')
     */
    function ceisa_get_incoming_picker($koneksi, $search = '', $bc = '', $limit = 60) {
        $where = ["a.AB = 'A'"];
        if (!empty($search)) {
            $esc = $koneksi->real_escape_string($search);
            $where[] = "(a.NOMOR_AJU LIKE '%$esc%' OR a.NOMOR_DAFTAR LIKE '%$esc%' OR a.KODE_BARANG LIKE '%$esc%' OR a.URAIAN LIKE '%$esc%' OR a.NAMA_BS LIKE '%$esc%')";
        }
        if (!empty($bc)) {
            $escB = $koneksi->real_escape_string($bc);
            $where[] = "a.BC = '$escB'";
        }
        $whereSql = implode(' AND ', $where);
        $sql = "SELECT a.ID, a.BC, a.NOMOR_AJU, a.NOMOR_DAFTAR, a.TANGGAL_DAFTAR, a.SERI_BARANG,
            a.KODE_BARANG, a.URAIAN, a.QTY, a.QTY_AKTUAL, a.UNIT, a.POS_TARIF, a.NILAI, a.CIF, a.NDPBM, a.HARGA_PENYERAHAN, a.NAMA_BS, a.GROSS, a.NETTO,
            COALESCE((SELECT SUM(x.qty_use) FROM bc_ceisa_tracebb x WHERE x.nomor_aju = a.NOMOR_AJU AND x.seri_barang = a.SERI_BARANG), 0) as already_used
            FROM bc_1ab2 a
            WHERE $whereSql
            ORDER BY a.TANGGAL_DAFTAR DESC, a.ID DESC
            LIMIT " . intval($limit);

        $q = $koneksi->query($sql);
        $data = [];
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $total = floatval($r['QTY_AKTUAL'] ?: $r['QTY']);
                $used = floatval($r['already_used'] ?? 0);
                $avail = max(0, $total - $used);
                $data[] = [
                    'id' => $r['ID'],
                    'bc' => $r['BC'],
                    'nomor_aju' => $r['NOMOR_AJU'],
                    'nomor_daftar' => $r['NOMOR_DAFTAR'],
                    'tanggal_daftar' => $r['TANGGAL_DAFTAR'],
                    'seri_barang' => intval($r['SERI_BARANG']),
                    'kode_barang' => $r['KODE_BARANG'],
                    'uraian' => $r['URAIAN'],
                    'qty_total' => $total,
                    'qty_avail' => $avail,
                    'unit' => $r['UNIT'] ?: 'PCE',
                    'pos_tarif' => preg_replace('/[^0-9]/', '', $r['POS_TARIF'] ?? ''),
                    'cif' => floatval($r['CIF'] ?? 0),
                    'harga' => floatval($r['HARGA_PENYERAHAN'] ?? 0),
                    'ndpbm' => floatval($r['NDPBM'] ?? 1),
                    'nama_bs' => $r['NAMA_BS'],
                    'gross' => floatval($r['GROSS'] ?? 0),
                    'netto' => floatval($r['NETTO'] ?? 0)
                ];
            }
        }
        return ['status' => 'OK', 'data' => $data];
    }

    /**
     * 17. Insert Bahan Baku dari Dokumen Masuk (Quick Picker bc_1ab2)
     */
    function ceisa_insert_from_incoming($koneksi, $nomor_aju, $seri_barang_target, $items) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        $seriTarget = intval($seri_barang_target);
        if ($seriTarget <= 0) $seriTarget = 1;

        if (empty($items) || !is_array($items)) {
            return ['status' => 'ERROR', 'message' => 'Tidak ada dokumen masuk yang dipilih.'];
        }

        $hRes = $koneksi->query("SELECT cmp_id, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        if (!$hRes || !($hdr = $hRes->fetch_assoc())) {
            return ['status' => 'ERROR', 'message' => 'Header AJU tidak ditemukan.'];
        }
        $cmp_id = $hdr['cmp_id'] ?? '';
        $ndpbm = floatval($hdr['ndpbm'] ?? 1);
        if ($ndpbm <= 0) $ndpbm = 1;

        $maxBbSeri = intval($koneksi->query("SELECT MAX(seri_bahan_baku) FROM bc_ceisa_bahanbaku WHERE nomor_aju = '$escAju' AND seri_barang = $seriTarget")->fetch_row()[0] ?? 0);

        $insBb = $koneksi->prepare("INSERT INTO bc_ceisa_bahanbaku 
            (cmp_id, nomor_aju, seri_barang, seri_bahan_baku, kode_asal_bahan_baku, hs, kode_barang, uraian, merek, tipe, ukuran, spesifikasi_lain, kode_satuan, jumlah_satuan, kode_dokumen_asal, nomor_daftar_asal, tanggal_daftar_asal, nomor_aju_asal, seri_barang_asal, netto, bruto, cif, cif_rupiah, ndpbm, harga_penyerahan)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $inserted = 0;
        foreach ($items as $it) {
            $maxBbSeri++;
            $bcAsal = trim($it['bc'] ?? '23');
            $asalBb = ($bcAsal === '40') ? '1' : '0';
            $hs = preg_replace('/[^0-9]/', '', trim($it['pos_tarif'] ?? ''));
            $kb = trim($it['kode_barang'] ?? '-');
            $uraian = trim($it['uraian'] ?? $kb);
            $sat = trim($it['unit'] ?? 'PCE');
            $qty = floatval($it['qty_pakai'] ?? 1);
            if ($qty <= 0) $qty = 1;

            $noDaftar = trim($it['nomor_daftar'] ?? '');
            $tglDaftar = trim($it['tanggal_daftar'] ?? '');
            $ajuAsal = trim($it['nomor_aju'] ?? '');
            $seriAsal = intval($it['seri_barang'] ?? 1);

            $itemNdpbm = floatval($it['ndpbm'] ?? $ndpbm);
            if ($itemNdpbm <= 0) $itemNdpbm = $ndpbm;

            $unitCif = floatval($it['unit_cif'] ?? 0);
            $cifTot = round($unitCif * $qty, 2);
            $cifRupiah = round($cifTot * $itemNdpbm, 2);

            $unitHarga = floatval($it['unit_harga'] ?? 0);
            $penyerahan = round($unitHarga * $qty, 2);

            $netto = floatval($it['netto'] ?? 0);
            $gross = floatval($it['gross'] ?? 0);
            $merek = '-'; $tipe = '-'; $ukuran = '-'; $spek = '-';

            $insBb->bind_param('ssiissssssssdsdsssssddddd',
                $cmp_id, $nomor_aju, $seriTarget, $maxBbSeri, $asalBb, $hs, $kb, $uraian, $merek, $tipe, $ukuran, $spek,
                $sat, $qty, $bcAsal, $noDaftar, $tglDaftar, $ajuAsal, $seriAsal, $netto, $gross, $cifTot, $cifRupiah, $itemNdpbm, $penyerahan
            );
            $insBb->execute();
            $inserted++;
        }
        $insBb->close();

        // Urutkan & generate tarif bahan baku otomatis
        ceisa_seq_bahanbaku($koneksi, $nomor_aju);
        ceisa_generate_bahanbaku_tarif($koneksi, $nomor_aju);
        ceisa_calc_pungutan($koneksi, $nomor_aju);

        return [
            'status' => 'OK',
            'message' => "Berhasil menambahkan $inserted bahan baku dari dokumen masuk dan langsung mengenerate tarifnya!",
            'inserted_count' => $inserted
        ];
    }

    /**
     * 18. Insert Dokumen Masuk Eks-BC langsung ke Trace Eks-BC (bc_ceisa_tracebb)
     */
    function ceisa_insert_trace_from_incoming($koneksi, $nomor_aju, $seri_barang, $id_bom, $items) {
        $escAju = $koneksi->real_escape_string($nomor_aju);
        $seri = intval($seri_barang);
        $escIdBom = $koneksi->real_escape_string($id_bom);

        if (empty($items) || !is_array($items)) {
            return ['status' => 'ERROR', 'message' => 'Tidak ada dokumen asal yang dipilih.'];
        }

        $hRes = $koneksi->query("SELECT cmp_id, ndpbm FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        $cmp_id = ($hRes && $hr = $hRes->fetch_assoc()) ? $hr['cmp_id'] : '';

        $inserted = 0;
        foreach ($items as $it) {
            $uuid = md5(uniqid(rand(), true));
            $bc = trim($it['bc'] ?? '');
            $noAjuAsal = trim($it['nomor_aju'] ?? '');
            $noDaftar = trim($it['nomor_daftar'] ?? '');
            $tglDaftar = trim($it['tanggal_daftar'] ?? '');
            $kb = trim($it['kode_barang'] ?? '');
            $nama = trim($it['uraian'] ?? $kb);
            $sat = trim($it['unit'] ?? 'PCE');
            $qtyUse = floatval($it['qty_pakai'] ?? 1);
            $hs = trim($it['pos_tarif'] ?? '');
            $harga = floatval($it['unit_harga'] ?? 0);
            $cif = floatval($it['unit_cif'] ?? 0);
            $ndpbm = floatval($it['ndpbm'] ?? 1);
            $gross = floatval($it['gross'] ?? 0);
            $netto = floatval($it['netto'] ?? 0);
            $setBy = 'MANUAL-PICKER ' . date('Ymd H:i');

            $escBc = $koneksi->real_escape_string($bc);
            $escAjuAsal = $koneksi->real_escape_string($noAjuAsal);
            $escNoDaf = $koneksi->real_escape_string($noDaftar);
            $escTglDaf = $koneksi->real_escape_string($tglDaftar);
            $escKb = $koneksi->real_escape_string($kb);
            $escNama = $koneksi->real_escape_string($nama);
            $escSat = $koneksi->real_escape_string($sat);
            $escHs = $koneksi->real_escape_string($hs);

            $sql = "INSERT INTO bc_ceisa_tracebb 
                (cmp_id, id, aju, nomor_aju, seri, seri_barang, id_bom, id_barang,
                 kode_brg, nama_brg, sat, qty_use,
                 jenis_bc, no_daftar, tgl_daftar,
                 hs, nama_bs, harga, cif, ndpbm, gross, netto, set_by)
                VALUES
                ('$cmp_id', '$uuid', '$escAju', '$escAjuAsal', $seri, 1, '$escIdBom', 0,
                 '$escKb', '$escNama', '$escSat', $qtyUse,
                 '$escBc', '$escNoDaf', '$escTglDaf',
                 '$escHs', '', $harga, $cif, $ndpbm, $gross, $netto, '$setBy')";

            if ($koneksi->query($sql)) {
                $inserted++;
            }
        }

        return [
            'status' => 'OK',
            'message' => "Berhasil menambahkan $inserted dokumen asal ke tracking eks-BC!",
            'inserted_count' => $inserted
        ];
    }
}
?>

<?php
/**
 * CEISA 4.0 Manifest (NVOCC) Excel Importer Engine
 * Membaca file Excel .xlsx backup/ekspor resmi dari Portal CEISA 4.0 (Manifest Online / NVOCC)
 * dan mengimpor data ke tabel-tabel bc_ceisa_manifes_* di MySQL.
 * 100% PHP Native (ZipArchive + SimpleXML) tanpa library eksternal.
 */

class CeisaManifestXlsxImporter {
    private $koneksi;
    private $cmpId;

    public function __construct($koneksi, $cmpId = '') {
        $this->koneksi = $koneksi;
        $this->cmpId = trim($cmpId);
    }

    public function setCmpId($cmpId) {
        $this->cmpId = trim($cmpId);
    }

    public function getCmpId() {
        return $this->cmpId;
    }

    /**
     * Konversi Excel serial date / string ke format Date YYYY-MM-DD
     */
    public static function excelToDate($val) {
        if ($val === null || $val === '') return null;
        $valTrim = trim((string)$val);
        if ($valTrim === '' || $valTrim === '0000-00-00') return null;

        if (is_numeric($valTrim) && (float)$valTrim > 1000) {
            $days = (float)$valTrim;
            $unixTimestamp = ($days - 25569) * 86400;
            return gmdate('Y-m-d', (int)$unixTimestamp);
        }

        $time = strtotime($valTrim);
        return $time ? date('Y-m-d', $time) : null;
    }

    /**
     * Konversi Excel serial date / string ke format Datetime YYYY-MM-DD HH:mm:ss
     */
    public static function excelToDateTime($val) {
        if ($val === null || $val === '') return null;
        $valTrim = trim((string)$val);
        if ($valTrim === '' || $valTrim === '0000-00-00' || strpos($valTrim, '0000-00-00') === 0) return null;

        if (is_numeric($valTrim) && (float)$valTrim > 1000) {
            $days = (float)$valTrim;
            $unixTimestamp = ($days - 25569) * 86400;
            return gmdate('Y-m-d H:i:s', (int)$unixTimestamp);
        }

        $time = strtotime($valTrim);
        return $time ? date('Y-m-d H:i:s', $time) : null;
    }

    /**
     * Membaca struktur dan isi file Excel .xlsx secara native
     */
    public function parseXlsx($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("File tidak ditemukan: " . $filePath);
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Gagal mengekstrak arsip file Excel (.xlsx)");
        }

        // 1. Shared Strings
        $sharedStrings = [];
        $ssXmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXmlContent !== false) {
            $ssXml = simplexml_load_string($ssXmlContent);
            if ($ssXml && isset($ssXml->si)) {
                foreach ($ssXml->si as $val) {
                    if (isset($val->t)) {
                        $sharedStrings[] = (string)$val->t;
                    } elseif (isset($val->r)) {
                        $text = '';
                        foreach ($val->r as $r) {
                            $text .= (string)$r->t;
                        }
                        $sharedStrings[] = $text;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Mapping Nama Sheet
        $wbXmlContent = $zip->getFromName('xl/workbook.xml');
        $relsXmlContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!$wbXmlContent || !$relsXmlContent) {
            $zip->close();
            throw new Exception("Struktur file Excel tidak valid (workbook / rels hilang)");
        }

        $wbXml = simplexml_load_string($wbXmlContent);
        $relsXml = simplexml_load_string($relsXmlContent);

        $rels = [];
        foreach ($relsXml->Relationship as $r) {
            $rels[(string)$r['Id']] = (string)$r['Target'];
        }

        $sheetMap = [];
        foreach ($wbXml->sheets->sheet as $s) {
            $name = strtoupper(trim((string)$s['name']));
            $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($rels[$rId])) {
                $target = $rels[$rId];
                if (strpos($target, 'xl/') !== 0) {
                    $target = 'xl/' . ltrim($target, '/');
                }
                $sheetMap[$name] = $target;
            }
        }

        // 3. Baca Data Tiap Sheet
        $parsedData = [];
        foreach ($sheetMap as $sheetName => $sheetFile) {
            $sheetContent = $zip->getFromName($sheetFile);
            if (!$sheetContent) continue;

            $sheetXml = simplexml_load_string($sheetContent);
            if (!$sheetXml || !isset($sheetXml->sheetData->row)) continue;

            $headers = [];
            $headerKeyMap = [];
            $rows = [];

            foreach ($sheetXml->sheetData->row as $row) {
                $rowNum = (int)$row['r'];
                $rowData = [];

                foreach ($row->c as $c) {
                    $cellRef = (string)$c['r'];
                    $colLetter = rtrim($cellRef, '0123456789');

                    $val = '';
                    if (isset($c->v)) {
                        $val = (string)$c->v;
                        $type = (string)$c['t'];
                        if ($type === 's' && isset($sharedStrings[(int)$val])) {
                            $val = $sharedStrings[(int)$val];
                        }
                    }
                    $rowData[$colLetter] = trim($val);
                }

                if ($rowNum === 1) {
                    $headers = $rowData;
                    foreach ($headers as $colLetter => $rawHeader) {
                        $baseKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($rawHeader)));
                        $baseKey = trim($baseKey, '_');
                        if ($baseKey !== '') {
                            $headerKeyMap[$colLetter] = $baseKey;
                        }
                    }
                } else {
                    $namedRow = [];
                    $hasPayload = false;
                    foreach ($headerKeyMap as $colLetter => $colKey) {
                        $v = $rowData[$colLetter] ?? null;
                        $namedRow[$colKey] = $v;
                        if ($v !== null && $v !== '') {
                            $hasPayload = true;
                        }
                    }
                    if ($hasPayload) {
                        $rows[] = $namedRow;
                    }
                }
            }

            $parsedData[$sheetName] = $rows;
        }

        $zip->close();
        return $parsedData;
    }

    /**
     * Eksekusi proses import file Excel Manifes NVOCC ke database
     */
    public function import($filePath, $options = []) {
        $data = $this->parseXlsx($filePath);

        // Validasi sheet NVOCC
        $nvoccRows = $data['NVOCC'] ?? [];
        if (empty($nvoccRows)) {
            throw new Exception("Sheet 'NVOCC' tidak ditemukan atau tidak memiliki data di dalam file Excel!");
        }

        $blRows = $data['BL'] ?? [];
        $masterRows = $data['MASTER'] ?? [];
        $blHsRows = $data['BL HS'] ?? [];
        $blPetiRows = $data['BL PETIKEMAS TERANGKUT'] ?? [];
        $blPetiTinggalRows = $data['BL PETIKEMAS TERTINGGAL'] ?? [];
        $blDokRows = $data['BL DOKUMEN'] ?? [];

        $db = $this->koneksi;
        $db->begin_transaction();

        try {
            $importedAjus = [];

            foreach ($nvoccRows as $hRow) {
                $nomorAju = trim($hRow['nomor_aju'] ?? '');
                if (empty($nomorAju)) continue;

                $idNvoccHeader = trim($hRow['id_nvocc_header'] ?? '');
                $kodeKantor    = trim($hRow['kode_kantor'] ?? '070100');
                $idPerusahaan  = trim($hRow['id_perusahaan'] ?? '');
                $namaSarana    = trim($hRow['nama_sarana_pengangkut'] ?? '');
                $nomorVoyage   = trim($hRow['nomor_voyage'] ?? '');
                $imoNumber     = trim($hRow['imo_number'] ?? '');
                $modeAngkut    = trim($hRow['mode_pengangkut'] ?? 'Laut');
                $jenisProses   = trim($hRow['jenis_proses'] ?? '12'); // 12=Outward, 11=Inward
                $tglBerangkat  = self::excelToDateTime($hRow['tanggal_berangkat'] ?? '');
                $tglTiba       = self::excelToDateTime($hRow['tanggal_tiba'] ?? '');
                $nomorDaftar   = trim($hRow['nomor_daftar'] ?? '');
                $tglDaftar     = self::excelToDate($hRow['tanggal_daftar'] ?? '');

                // Auto-fill tanggal_tiba jika kosong pada file Excel (umum terjadi pada manifes Outward)
                if (empty($tglTiba)) {
                    if (!empty($tglBerangkat)) {
                        $tglTiba = date('Y-m-d H:i:s', strtotime($tglBerangkat . ' +24 hours'));
                    } else {
                        $tglTiba = date('Y-m-d H:i:s', strtotime('+1 day'));
                    }
                }

                // Tentukan cmp_id
                $targetCmp = $this->cmpId;
                if (empty($targetCmp) && !empty($idPerusahaan)) {
                    $cleanNpwp = preg_replace('/\D/', '', $idPerusahaan);
                    $qCmp = $db->query("SELECT cmp_id, cmp_name FROM bc_ceisa_company WHERE REPLACE(REPLACE(npwp, '-', ''), '.', '') LIKE '%$cleanNpwp%' OR cmp_id LIKE '%$cleanNpwp%' LIMIT 1");
                    if ($qCmp && $rC = $qCmp->fetch_assoc()) {
                        $targetCmp = $rC['cmp_id'];
                    }
                }
                if (empty($targetCmp)) {
                    // Fallback ke perusahaan pertama yang terdaftar
                    $qDef = $db->query("SELECT cmp_id FROM bc_ceisa_company ORDER BY cmp_id ASC LIMIT 1");
                    if ($qDef && $rD = $qDef->fetch_assoc()) {
                        $targetCmp = $rD['cmp_id'];
                    }
                }

                // Ambil Nama & Alamat Perusahaan
                $namaPerusahaan = '';
                $alamatPerusahaan = '';
                if (!empty($targetCmp)) {
                    $qNm = $db->query("SELECT cmp_name, alamat FROM bc_ceisa_company WHERE cmp_id = '" . $db->real_escape_string($targetCmp) . "' LIMIT 1");
                    if ($qNm && $rN = $qNm->fetch_assoc()) {
                        $namaPerusahaan = $rN['cmp_name'];
                        $alamatPerusahaan = trim($rN['alamat'] ?? '');
                    }
                }
                if (empty($alamatPerusahaan)) {
                    $alamatPerusahaan = '-';
                }

                $escAju = $db->real_escape_string($nomorAju);
                $escCmp = $db->real_escape_string($targetCmp);

                // 1. Bersihkan data manifes lama untuk nomor_aju ini agar tidak ada duplikasi
                $db->query("DELETE FROM bc_ceisa_manifes_header WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp'");
                $db->query("DELETE FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp'");
                $db->query("DELETE FROM bc_ceisa_manifes_kelompok_pos WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp'");
                $db->query("DELETE FROM bc_ceisa_manifes_bl WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp'");
                $db->query("DELETE FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp'");
                $db->query("DELETE FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp'");
                $db->query("DELETE FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp'");

                // 2. Simpan Header Manifes
                $stmtHdr = $db->prepare("INSERT INTO bc_ceisa_manifes_header (
                    nomor_aju, cmp_id, kode_kantor, jenis_manifes, id_perusahaan, nama_perusahaan, alamat_perusahaan,
                    nomor_voyage, tanggal_berangkat, tanggal_tiba, mode_pengangkut, nama_sarana_pengangkut, imo_number,
                    status, id_nvocc_header, nomor_bc11, tanggal_bc11, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', ?, ?, ?, NOW(), NOW())");
                $stmtHdr->bind_param(
                    "ssssssssssssssss",
                    $nomorAju, $targetCmp, $kodeKantor, $jenisProses, $idPerusahaan, $namaPerusahaan, $alamatPerusahaan,
                    $nomorVoyage, $tglBerangkat, $tglTiba, $modeAngkut, $namaSarana, $imoNumber,
                    $idNvoccHeader, $nomorDaftar, $tglDaftar
                );
                $stmtHdr->execute();
                $stmtHdr->close();

                // 3. Simpan Master BL & Kelompok Pos
                $masterBlMap = []; // id_nvocc_master => master_bl
                foreach ($masterRows as $mRow) {
                    $mHdrId = trim($mRow['id_nvocc_header'] ?? '');
                    if (!empty($idNvoccHeader) && !empty($mHdrId) && $mHdrId !== $idNvoccHeader) {
                        continue;
                    }

                    $masterBlNo = trim($mRow['master_bl'] ?? '');
                    $kelompokPos = trim($mRow['kelompok_pos'] ?? '01');
                    $tglBl       = self::excelToDate($mRow['tanggal_bl'] ?? '');
                    $jmlPos      = intval($mRow['jumlah_pos'] ?? 1);
                    $mMasterId   = trim($mRow['id_nvocc_master'] ?? '');

                    if (!empty($mMasterId)) {
                        $masterBlMap[$mMasterId] = $masterBlNo;
                    }

                    $stmtM = $db->prepare("INSERT INTO bc_ceisa_manifes_master_bl (nomor_aju, cmp_id, kelompok_pos, master_bl, tanggal_bl, jumlah_pos) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtM->bind_param("sssssi", $nomorAju, $targetCmp, $kelompokPos, $masterBlNo, $tglBl, $jmlPos);
                    $stmtM->execute();
                    $stmtM->close();

                    // Insert ke kelompok pos jika belum ada
                    $qChkKp = $db->query("SELECT id FROM bc_ceisa_manifes_kelompok_pos WHERE nomor_aju = '$escAju' AND cmp_id = '$escCmp' AND kode_kelompok_pos = '" . $db->real_escape_string($kelompokPos) . "' LIMIT 1");
                    if (!$qChkKp || $qChkKp->num_rows === 0) {
                        $uraianKp = "KELOMPOK POS " . $kelompokPos;
                        $stmtKp = $db->prepare("INSERT INTO bc_ceisa_manifes_kelompok_pos (nomor_aju, cmp_id, kode_kelompok_pos, uraian_kelompok_pos, jumlah) VALUES (?, ?, ?, ?, ?)");
                        $stmtKp->bind_param("ssssi", $nomorAju, $targetCmp, $kelompokPos, $uraianKp, $jmlPos);
                        $stmtKp->execute();
                        $stmtKp->close();
                    }
                }

                // 4. Simpan House BL (dataBls)
                $blIdMap = []; // id_bl (Excel UUID) => nomor_host_bl
                $seriBlCounter = 1;

                foreach ($blRows as $bRow) {
                    $bHdrId = trim($bRow['id_nvocc_header'] ?? '');
                    if (!empty($idNvoccHeader) && !empty($bHdrId) && $bHdrId !== $idNvoccHeader) {
                        continue;
                    }

                    $excelBlId     = trim($bRow['id_bl'] ?? '');
                    $hostBlNo      = trim($bRow['nomor_host_bl'] ?? '');
                    if (empty($hostBlNo)) {
                        $hostBlNo = 'HBL-' . $seriBlCounter;
                    }
                    if (!empty($excelBlId)) {
                        $blIdMap[$excelBlId] = $hostBlNo;
                    }

                    $mBlNo         = trim($bRow['nomor_bl'] ?? '');
                    $kodeKp        = trim($bRow['kode_kelompok_pos'] ?? '01');
                    $nomorPos      = trim($bRow['nomor_pos'] ?? '');
                    $tglBl         = self::excelToDate($bRow['tanggal_bl'] ?? '');
                    $tglHostBl     = self::excelToDate($bRow['tanggal_host_bl'] ?? '');
                    $marking       = trim($bRow['marking'] ?? 'N/M');
                    $namaPengirim  = trim($bRow['nama_pengirim'] ?? '');
                    $almtPengirim  = trim($bRow['alamat_pengirim'] ?? '');
                    $npwpPengirim  = trim($bRow['npwp_pengirim'] ?? '');
                    $namaPenerima  = trim($bRow['nama_penerima'] ?? '');
                    $almtPenerima  = trim($bRow['alamat_penerima'] ?? '');
                    $npwpPenerima  = trim($bRow['npwp_penerima'] ?? '');
                    $namaNotify    = trim($bRow['nama_notify'] ?? 'SAME AS CONSIGNEE');
                    $almtNotify    = trim($bRow['alamat_notify'] ?? 'SAME AS CONSIGNEE');
                    $npwpNotify    = trim($bRow['npwp_notify'] ?? '');
                    $berat         = floatval($bRow['berat'] ?? 0);
                    $dimensi       = floatval($bRow['dimensi'] ?? 0);
                    $jmlContainer  = intval($bRow['jumlah_container'] ?? 0);
                    $jenisKemasan  = trim($bRow['jenis_kemasan'] ?? 'CT');
                    $jmlKemasan    = floatval($bRow['jumlah_kemasan'] ?? 0);
                    $motherVessel  = trim($bRow['mother_vessel'] ?? $namaSarana);
                    $pelAsal       = trim($bRow['kode_pelabuhan_asal'] ?? '');
                    $pelBongkar    = trim($bRow['kode_pelabuhan_bongkar'] ?? '');
                    $pelTransit    = trim($bRow['kode_pelabuhan_transit'] ?? $pelAsal);
                    $pelAkhir      = trim($bRow['kode_pelabuhan_akhir'] ?? $pelBongkar);
                    $flKonsolidasi = strtoupper(trim($bRow['flag_konsolidasi'] ?? 'N'));
                    $flParsial     = strtoupper(trim($bRow['flag_parsial'] ?? 'N'));
                    $flPartof      = strtoupper(trim($bRow['flag_partof'] ?? 'N'));
                    $jmlKemasanTrk = $jmlKemasan;

                    $stmtBl = $db->prepare("INSERT INTO bc_ceisa_manifes_bl (
                        nomor_aju, cmp_id, seri_bl, kode_kelompok_pos, nomor_pos, nomor_bl, tanggal_bl,
                        nomor_host_bl, tanggal_host_bl, marking, npwp_pengirim, nama_pengirim, alamat_pengirim,
                        npwp_penerima, nama_penerima, alamat_penerima, npwp_notify, nama_notify, alamat_notify,
                        berat, dimensi, jumlah_container, jenis_kemasan, jumlah_kemasan, mother_vessel,
                        kode_pelabuhan_asal, kode_pelabuhan_bongkar, kode_pelabuhan_transit, kode_pelabuhan_akhir,
                        jumlah_kemasan_terangkut, flag_konsolidasi, flag_parsial, flag_partof
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?
                    )");
                    $stmtBl->bind_param(
                        "ssissssssssssssssssddisdsssssdsss",
                        $nomorAju, $targetCmp, $seriBlCounter, $kodeKp, $nomorPos, $mBlNo, $tglBl,
                        $hostBlNo, $tglHostBl, $marking, $npwpPengirim, $namaPengirim, $almtPengirim,
                        $npwpPenerima, $namaPenerima, $almtPenerima, $npwpNotify, $namaNotify, $almtNotify,
                        $berat, $dimensi, $jmlContainer, $jenisKemasan, $jmlKemasan, $motherVessel,
                        $pelAsal, $pelBongkar, $pelTransit, $pelAkhir,
                        $jmlKemasanTrk, $flKonsolidasi, $flParsial, $flPartof
                    );
                    $stmtBl->execute();
                    $stmtBl->close();

                    $seriBlCounter++;
                }

                // 5. Simpan BL HS
                $hsCounters = []; // hostBl => counter
                foreach ($blHsRows as $hItem) {
                    $blId = trim($hItem['id_bl'] ?? '');
                    $targetHostBl = $blIdMap[$blId] ?? '';
                    if (empty($targetHostBl)) {
                        // Jika id_bl tidak cocok, masukkan ke HBL pertama
                        $targetHostBl = reset($blIdMap) ?: '';
                    }
                    if (empty($targetHostBl)) continue;

                    if (!isset($hsCounters[$targetHostBl])) $hsCounters[$targetHostBl] = 1;
                    $seriHs = intval($hItem['seri_hs'] ?? 0);
                    if ($seriHs <= 0) {
                        $seriHs = $hsCounters[$targetHostBl];
                    }
                    $hsCounters[$targetHostBl]++;

                    $kodeHs = trim($hItem['kode_hs'] ?? '');
                    $uraian = trim($hItem['uraian_barang'] ?? '');

                    $stmtHs = $db->prepare("INSERT INTO bc_ceisa_manifes_bl_hs (nomor_aju, cmp_id, nomor_host_bl, seri_hs, kode_hs, uraian_barang) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtHs->bind_param("sssiss", $nomorAju, $targetCmp, $targetHostBl, $seriHs, $kodeHs, $uraian);
                    $stmtHs->execute();
                    $stmtHs->close();
                }

                // 6. Simpan BL Petikemas Terangkut & Tertinggal
                $petiCounters = []; // hostBl => counter
                $allPeti = array_merge(
                    array_map(function($p) { $p['_fl_terangkut'] = '01'; return $p; }, $blPetiRows),
                    array_map(function($p) { $p['_fl_terangkut'] = '02'; return $p; }, $blPetiTinggalRows)
                );

                foreach ($allPeti as $pItem) {
                    $blId = trim($pItem['id_bl'] ?? ($pItem['id_bl_tertinggal'] ?? ''));
                    $targetHostBl = $blIdMap[$blId] ?? '';
                    if (empty($targetHostBl)) {
                        $targetHostBl = reset($blIdMap) ?: '';
                    }
                    if (empty($targetHostBl)) continue;

                    if (!isset($petiCounters[$targetHostBl])) $petiCounters[$targetHostBl] = 1;
                    $seriPeti = intval($pItem['seri_container'] ?? 0);
                    if ($seriPeti <= 0) {
                        $seriPeti = $petiCounters[$targetHostBl];
                    }
                    $petiCounters[$targetHostBl]++;

                    $noContainer = trim($pItem['nomor_container'] ?? '');
                    if (empty($noContainer)) continue;

                    $flB3        = trim($pItem['fl_b3'] ?? '');
                    $flTerangkut = trim($pItem['fl_terangkut'] ?? ($pItem['_fl_terangkut'] ?? '01'));
                    $stPeti      = trim($pItem['status'] ?? '');
                    $typePeti    = trim($pItem['type_container'] ?? '1');
                    $ukuranPeti  = trim($pItem['ukuran_container'] ?? '20');
                    $segel       = trim($pItem['nomor_segel'] ?? '');
                    $polisi      = trim($pItem['nomor_polisi'] ?? '');
                    $jenisPeti   = trim($pItem['jenis_container'] ?? '7');
                    $jenisMuat   = trim($pItem['jenis_muat'] ?? '');
                    $driver      = trim($pItem['driver'] ?? '');

                    $stmtPeti = $db->prepare("INSERT INTO bc_ceisa_manifes_bl_petikemas (
                        nomor_aju, cmp_id, nomor_host_bl, seri_container, nomor_container,
                        fl_b3, fl_terangkut, status, type_container, ukuran_container,
                        nomor_segel, nomor_polisi, jenis_container, jenis_muat, driver
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtPeti->bind_param(
                        "sssisssssssssss",
                        $nomorAju, $targetCmp, $targetHostBl, $seriPeti, $noContainer,
                        $flB3, $flTerangkut, $stPeti, $typePeti, $ukuranPeti,
                        $segel, $polisi, $jenisPeti, $jenisMuat, $driver
                    );
                    $stmtPeti->execute();
                    $stmtPeti->close();
                }

                // 7. Simpan BL Dokumen
                $dokCounters = [];
                foreach ($blDokRows as $dItem) {
                    $blId = trim($dItem['id_bl'] ?? '');
                    $targetHostBl = $blIdMap[$blId] ?? '';
                    if (empty($targetHostBl)) {
                        $targetHostBl = reset($blIdMap) ?: '';
                    }
                    if (empty($targetHostBl)) continue;

                    if (!isset($dokCounters[$targetHostBl])) $dokCounters[$targetHostBl] = 1;
                    $seriDok = intval($dItem['seri_dokumen'] ?? 0);
                    if ($seriDok <= 0) {
                        $seriDok = $dokCounters[$targetHostBl];
                    }
                    $dokCounters[$targetHostBl]++;

                    $kdKantorDok = trim($dItem['id_kantor'] ?? ($dItem['id_kantor_dokumen_asal'] ?? $kodeKantor));
                    $kdDok       = trim($dItem['kode_dokumen_asal'] ?? ($dItem['kode_dokumen'] ?? '913'));
                    $noDok       = trim($dItem['nomor_dokumen_asal'] ?? ($dItem['nomor_dokumen'] ?? ''));
                    $tglDok      = self::excelToDate($dItem['tanggal_dokumen_asal'] ?? ($dItem['tanggal_dokumen'] ?? ''));

                    $stmtDok = $db->prepare("INSERT INTO bc_ceisa_manifes_bl_dokumen (
                        nomor_aju, cmp_id, nomor_host_bl, seri_dokumen, kode_kantor, kode_dokumen, nomor_dokumen, tanggal_dokumen
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtDok->bind_param("sssissss", $nomorAju, $targetCmp, $targetHostBl, $seriDok, $kdKantorDok, $kdDok, $noDok, $tglDok);
                    $stmtDok->execute();
                    $stmtDok->close();
                }

                $importedAjus[] = [
                    'nomor_aju'        => $nomorAju,
                    'cmp_id'           => $targetCmp,
                    'nama_perusahaan'  => $namaPerusahaan,
                    'sarana_pengangkut'=> $namaSarana,
                    'nomor_voyage'     => $nomorVoyage,
                    'total_hbl'        => count($blIdMap),
                    'total_container'  => array_sum($petiCounters),
                    'total_hs'         => array_sum($hsCounters),
                    'total_dokumen'    => array_sum($dokCounters)
                ];
            }

            $db->commit();

            return [
                'status'  => 'OK',
                'message' => 'Berhasil mengimpor data Manifes NVOCC dari file Excel (' . count($importedAjus) . ' Dokumen AJU)',
                'data'    => $importedAjus
            ];

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }
}

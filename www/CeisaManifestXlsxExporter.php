<?php
/**
 * CEISA 4.0 Manifest (NVOCC) Excel Exporter Engine
 * Menghasilkan file Excel .xlsx "Format Baru" resmi standar Portal CEISA 4.0 (Manifest Online / NVOCC)
 * 100% klop 1:1 dengan format backup/restore resmi Portal CEISA 4.0 (seperti data-manifest.xlsx)
 * 100% PHP Native (ZipArchive + XML SharedStrings).
 */

class CeisaManifestXlsxExporter {
    private $db;
    private $cmpId;
    private $sharedStrings = [];
    private $sharedStringsMap = [];

    public function __construct($mysqli, $cmpId = '') {
        $this->db = $mysqli;
        $this->cmpId = trim($cmpId);
    }

    /**
     * Generate UUID v4 standar
     */
    public static function genUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Konversi tanggal / waktu string ke format tanggal yang diterima parser CEISA 4.0 (ISO string)
     */
    public static function toExcelSerial($dateStr, $includeTime = false) {
        if (empty($dateStr) || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($dateStr);
        if (!$ts) return '';
        return $includeTime ? date('Y-m-d H:i:s', $ts) : date('Y-m-d', $ts);
    }


    /**
     * Konversi index 0-based ke Excel column letter (A, B, ..., Z, AA, AB, ...)
     */
    private static function colLetter($i) {
        $letter = '';
        while ($i >= 0) {
            $letter = chr($i % 26 + 65) . $letter;
            $i = intval($i / 26) - 1;
        }
        return $letter;
    }

    /**
     * Mendaftarkan string ke tabel sharedStrings
     */
    private function addString($str) {
        $s = (string)$str;
        if (isset($this->sharedStringsMap[$s])) {
            return $this->sharedStringsMap[$s];
        }
        $idx = count($this->sharedStrings);
        $this->sharedStrings[] = $s;
        $this->sharedStringsMap[$s] = $idx;
        return $idx;
    }

    /**
     * Membentuk XML Worksheet Excel OpenXML
     */
    private function generateSheetXml($headers, $rows) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $xml .= '<sheetData>' . "\n";

        // Header (Row 1)
        $xml .= '<row r="1">' . "\n";
        foreach ($headers as $cIdx => $h) {
            $cellRef = self::colLetter($cIdx) . '1';
            $sIdx = $this->addString($h);
            $xml .= "<c r=\"{$cellRef}\" t=\"s\"><v>{$sIdx}</v></c>";
        }
        $xml .= "\n</row>\n";

        // Data (Rows 2..N)
        $rowNum = 2;
        foreach ($rows as $row) {
            $xml .= "<row r=\"{$rowNum}\">\n";
            foreach ($headers as $cIdx => $h) {
                $cellRef = self::colLetter($cIdx) . $rowNum;
                $val = $row[$h] ?? null;

                if ($val === null || $val === '') {
                    // Cell kosong
                    $xml .= "<c r=\"{$cellRef}\"/>";
                } elseif (is_numeric($val) && strpos($h, 'Nomor') === false && strpos($h, 'kode') === false && strpos($h, 'Kode') === false && strpos($h, 'Id') === false && strpos($h, 'id') === false && strpos($h, 'NPWP') === false && strpos($h, 'npwp') === false) {
                    // Simpan sebagai nilai numerik langsung
                    $xml .= "<c r=\"{$cellRef}\"><v>{$val}</v></c>";
                } else {
                    // Simpan sebagai shared string
                    $sIdx = $this->addString((string)$val);
                    $xml .= "<c r=\"{$cellRef}\" t=\"s\"><v>{$sIdx}</v></c>";
                }
            }
            $xml .= "\n</row>\n";
            $rowNum++;
        }

        $xml .= '</sheetData>' . "\n";
        $xml .= '</worksheet>';
        return $xml;
    }

    /**
     * Ekspor dokumen ke file Excel .xlsx persis sesuai format resmi CEISA 4.0
     *
     * @param string $nomorAju Nomor pengajuan manifes
     * @param string|null $outputFile Path file target
     * @return string Path file .xlsx yang berhasil dibuat
     */
    public function export($nomorAju, $outputFile = null) {
        $escAju = $this->db->real_escape_string($nomorAju);
        $whereCmp = !empty($this->cmpId) ? "AND (cmp_id = '" . $this->db->real_escape_string($this->cmpId) . "' OR cmp_id = '' OR cmp_id IS NULL)" : "";

        // 1. Query Header
        $qH = $this->db->query("SELECT * FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escAju}' LIMIT 1");
        if (!$qH || !($hdr = $qH->fetch_assoc())) {
            throw new Exception("Dokumen Manifes Header dengan Nomor Aju '{$nomorAju}' tidak ditemukan.");
        }

        $idNvocc = !empty($hdr['id_nvocc_header']) ? $hdr['id_nvocc_header'] : self::genUuid();
        $nowSerial = self::toExcelSerial(date('Y-m-d H:i:s'), true);

        // 1. Sheet NVOCC (Headers sesuai data-manifest.xlsx)
        $nvoccHeaders = [
            'Id Nvocc Header', 'Nomor Aju', 'kode Kantor', 'Jenis Proses', 'Nomor Daftar', 
            'Tanggal Daftar', 'Mode Pengangkut', 'Nama Sarana Pengangkut', 'Nomor Voyage', 
            'Imo Number', 'Tanggal Tiba', 'Tanggal Berangkat', 'Wk Rekam', 'Id Perusahaan'
        ];
        $nvoccRows = [[
            'Id Nvocc Header'        => $idNvocc,
            'Nomor Aju'              => $hdr['nomor_aju'],
            'kode Kantor'            => $hdr['kode_kantor'],
            'Jenis Proses'           => $hdr['jenis_manifes'] ?: '12',
            'Nomor Daftar'           => $hdr['nomor_bc11'] ?? '',
            'Tanggal Daftar'         => self::toExcelSerial($hdr['tanggal_bc11'] ?? ''),
            'Mode Pengangkut'        => $hdr['mode_pengangkut'] ?: 'Laut',
            'Nama Sarana Pengangkut' => $hdr['nama_sarana_pengangkut'],
            'Nomor Voyage'           => $hdr['nomor_voyage'],
            'Imo Number'             => $hdr['imo_number'] ?: '-',
            'Tanggal Tiba'           => self::toExcelSerial($hdr['tanggal_tiba'] ?? '', true),
            'Tanggal Berangkat'      => self::toExcelSerial($hdr['tanggal_berangkat'] ?? '', true),
            'Wk Rekam'               => $nowSerial,
            'Id Perusahaan'          => $hdr['id_perusahaan'] ?: ($hdr['npwp'] ?? '')
        ]];

        // 2. Sheet MASTER (Headers sesuai data-manifest.xlsx)
        $masterHeaders = [
            'Id Nvocc Master', 'Id Nvocc Header', 'Master Bl', 'Tanggal Bl', 
            'Kelompok Pos', 'Jumlah Pos', 'Jumlah Container', 'Pos BC 11', 'Respon', 'Waktu Rekon'
        ];
        $masterRows = [];
        $masterIdMap = [];

        $qM = $this->db->query("SELECT * FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = '{$escAju}' ORDER BY id ASC");
        if ($qM && $qM->num_rows > 0) {
            while ($rM = $qM->fetch_assoc()) {
                $mId = self::genUuid();
                $masterIdMap[$rM['master_bl']] = $mId;
                $masterRows[] = [
                    'Id Nvocc Master'  => $mId,
                    'Id Nvocc Header'  => $idNvocc,
                    'Master Bl'        => $rM['master_bl'],
                    'Tanggal Bl'       => self::toExcelSerial($rM['tanggal_bl'] ?? ''),
                    'Kelompok Pos'     => $rM['kelompok_pos'] ?: (($hdr['jenis_manifes'] === '11') ? '01' : '09'),
                    'Jumlah Pos'       => number_format((float)($rM['jumlah_pos'] ?? 1), 1, '.', ''),
                    'Jumlah Container' => number_format((float)($rM['jumlah_container'] ?? 0), 1, '.', ''),
                    'Pos BC 11'        => $rM['pos_bc11'] ?? '',
                    'Respon'           => $rM['respon'] ?? '',
                    'Waktu Rekon'      => self::toExcelSerial($rM['waktu_rekon'] ?? '', true)
                ];
            }
        } else {
            $defaultMbl = 'ONEYSUBG30948300';
            $mId = self::genUuid();
            $masterIdMap[$defaultMbl] = $mId;
            $masterRows[] = [
                'Id Nvocc Master'  => $mId,
                'Id Nvocc Header'  => $idNvocc,
                'Master Bl'        => $defaultMbl,
                'Tanggal Bl'       => self::toExcelSerial(date('Y-m-d')),
                'Kelompok Pos'     => ($hdr['jenis_manifes'] === '11') ? '01' : '09',
                'Jumlah Pos'       => '1.0',
                'Jumlah Container' => '0.0',
                'Pos BC 11'        => '',
                'Respon'           => '',
                'Waktu Rekon'      => ''
            ];
        }

        // Query House BLs
        $blRowsRaw = [];
        $blIdMap = [];
        $qB = $this->db->query("SELECT * FROM bc_ceisa_manifes_bl WHERE nomor_aju = '{$escAju}' ORDER BY seri_bl ASC, id ASC");
        if ($qB) {
            while ($rB = $qB->fetch_assoc()) {
                $hblId = self::genUuid();
                $blIdMap[$rB['nomor_host_bl']] = $hblId;
                $blRowsRaw[] = array_merge($rB, ['_id_bl' => $hblId]);
            }
        }

        // 3. Sheet BL HS (Headers sesuai data-manifest.xlsx)
        $hsHeaders = ['Id Hs', 'Id Bl', 'Kode Hs', 'Uraian Barang', 'Seri Hs', 'Waktu Rekam'];
        $hsRows = [];
        $qHs = $this->db->query("SELECT * FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = '{$escAju}' ORDER BY seri_hs ASC");
        if ($qHs) {
            while ($rHs = $qHs->fetch_assoc()) {
                $hId = $blIdMap[$rHs['nomor_host_bl']] ?? (reset($blIdMap) ?: self::genUuid());
                $hsRows[] = [
                    'Id Hs'         => self::genUuid(),
                    'Id Bl'         => $hId,
                    'Kode Hs'       => $rHs['kode_hs'] ?: '0101',
                    'Uraian Barang' => $rHs['uraian_barang'] ?: 'GENERAL CARGO',
                    'Seri Hs'       => number_format((float)($rHs['seri_hs'] ?: 1), 1, '.', ''),
                    'Waktu Rekam'   => $nowSerial
                ];
            }
        }

        // 4. Sheet BL (Headers sesuai data-manifest.xlsx)
        $blHeaders = [
            'Id Bl', 'Id Header', 'Id Nvocc Header', 'Kode Kelompok Pos', 'Nomor Pos', 
            'Nomor Bl', 'Tanggal Bl', 'Nomor Host Bl', 'Tanggal Host Bl', 'Marking', 
            'Nama Pengirim', 'Alamat Pengirim', 'Nama Penerima', 'Alamat Penerima', 
            'Nama Notify', 'Alamat Notify', 'Berat', 'Dimensi', 'Jumlah Container', 
            'Jenis Kemasan', 'Jumlah Kemasan', 'Mother Vessel', 'Kode Pelabuhan Asal', 
            'Kode Pelabuhan Bongkar', 'Kode Pelabuhan Transit', 'Kode Pelabuhan Akhir', 
            'Gudang', 'Nomor Voyage', 'Jumlah Container Tertinggal', 'Jumlah Kemasan Tertinggal', 
            'Waktu Rekam', 'NPWP Penerima', 'NPWP Notify', 'NPWP Pengirim', 
            'Flag Konsolidasi', 'Flag Parsial', 'Flag Partof', 'Id Nvocc Master'
        ];
        $blRows = [];
        foreach ($blRowsRaw as $rB) {
            $mblNo = $rB['nomor_bl'] ?: ($masterRows[0]['Master Bl'] ?? '');
            $mblId = $masterIdMap[$mblNo] ?? ($masterRows[0]['Id Nvocc Master'] ?? '');

            $blRows[] = [
                'Id Bl'                      => $rB['_id_bl'],
                'Id Header'                  => '',
                'Id Nvocc Header'            => $idNvocc,
                'Kode Kelompok Pos'          => $rB['kode_kelompok_pos'] ?: (($hdr['jenis_manifes'] === '11') ? '01' : '09'),
                'Nomor Pos'                  => $rB['nomor_pos'] ?: '000100000000',
                'Nomor Bl'                   => $mblNo,
                'Tanggal Bl'                 => self::toExcelSerial($rB['tanggal_bl'] ?? ''),
                'Nomor Host Bl'              => $rB['nomor_host_bl'],
                'Tanggal Host Bl'            => self::toExcelSerial($rB['tanggal_host_bl'] ?? ''),
                'Marking'                    => $rB['marking'] ?: 'N/M',
                'Nama Pengirim'              => $rB['nama_pengirim'] ?: '-',
                'Alamat Pengirim'            => $rB['alamat_pengirim'] ?: '-',
                'Nama Penerima'              => $rB['nama_penerima'] ?: '-',
                'Alamat Penerima'            => $rB['alamat_penerima'] ?: '-',
                'Nama Notify'                => $rB['nama_notify'] ?: 'SAME AS CONSIGNEE',
                'Alamat Notify'              => $rB['alamat_notify'] ?: 'SAME AS CONSIGNEE',
                'Berat'                      => number_format((float)$rB['berat'], 1, '.', ''),
                'Dimensi'                    => (string)(float)$rB['dimensi'],
                'Jumlah Container'           => number_format((float)$rB['jumlah_container'], 1, '.', ''),
                'Jenis Kemasan'              => $rB['jenis_kemasan'] ?: 'CT',
                'Jumlah Kemasan'             => number_format((float)$rB['jumlah_kemasan'], 1, '.', ''),
                'Mother Vessel'              => $rB['mother_vessel'] ?: ($hdr['nama_sarana_pengangkut'] . ' ' . $hdr['nomor_voyage']),
                'Kode Pelabuhan Asal'        => $rB['kode_pelabuhan_asal'] ?? '',
                'Kode Pelabuhan Bongkar'     => $rB['kode_pelabuhan_bongkar'] ?? '',
                'Kode Pelabuhan Transit'     => $rB['kode_pelabuhan_transit'] ?: ($rB['kode_pelabuhan_asal'] ?? ''),
                'Kode Pelabuhan Akhir'       => $rB['kode_pelabuhan_akhir'] ?: ($rB['kode_pelabuhan_bongkar'] ?? ''),
                'Gudang'                     => '',
                'Nomor Voyage'               => '',
                'Jumlah Container Tertinggal'=> '0.0',
                'Jumlah Kemasan Tertinggal'  => '0.0',
                'Waktu Rekam'                => $nowSerial,
                'NPWP Penerima'              => !empty($rB['npwp_penerima']) ? $rB['npwp_penerima'] : null,
                'NPWP Notify'                => !empty($rB['npwp_notify']) ? $rB['npwp_notify'] : null,
                'NPWP Pengirim'              => !empty($rB['npwp_pengirim']) ? $rB['npwp_pengirim'] : null,
                'Flag Konsolidasi'           => strtoupper((string)($rB['flag_konsolidasi'] ?: 'N')),
                'Flag Parsial'               => strtoupper((string)($rB['flag_parsial'] ?: 'N')),
                'Flag Partof'                => strtoupper((string)($rB['flag_partof'] ?: 'N')),
                'Id Nvocc Master'            => $mblId
            ];
        }

        // 5. Sheet BL DOKUMEN (Headers sesuai data-manifest.xlsx)
        $dokHeaders = [
            'Id Dokumen Asal', 'Id Bl', 'Nomor Bc 11', 'Tanggal Bc 11', 'Id Kelompok Pos', 
            'Id Kantor Dokumen Asal', 'Nomor Pos', 'Tanggal Dokumen Asal', 'Nomor Dokumen Asal', 
            'Id Kantor', 'seri Dokumen', 'Waktu Rekam', 'Kode Dokumen Asal'
        ];
        $dokRows = [];
        $qDok = $this->db->query("SELECT * FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = '{$escAju}' ORDER BY seri_dokumen ASC");
        if ($qDok) {
            while ($rD = $qDok->fetch_assoc()) {
                $hId = $blIdMap[$rD['nomor_host_bl']] ?? (reset($blIdMap) ?: self::genUuid());
                $dokRows[] = [
                    'Id Dokumen Asal'        => self::genUuid(),
                    'Id Bl'                  => $hId,
                    'Nomor Bc 11'            => '',
                    'Tanggal Bc 11'          => '',
                    'Id Kelompok Pos'        => '',
                    'Id Kantor Dokumen Asal' => '',
                    'Nomor Pos'              => '',
                    'Tanggal Dokumen Asal'   => self::toExcelSerial($rD['tanggal_dokumen'] ?? ''),
                    'Nomor Dokumen Asal'     => $rD['nomor_dokumen'] ?? '',
                    'Id Kantor'              => $rD['kode_kantor'] ?: $hdr['kode_kantor'],
                    'seri Dokumen'           => '',
                    'Waktu Rekam'            => $nowSerial,
                    'Kode Dokumen Asal'      => $rD['kode_dokumen'] ?: '913'
                ];
            }
        }

        // 6. Sheet BL PETIKEMAS TERANGKUT (Headers sesuai data-manifest.xlsx)
        $petiHeaders = [
            'Id Terangkut', 'Id Container', 'Seri Container', 'Nomor Container', 
            'Id Bl', 'Fl B3', 'Fl Terangkut', 'Status', 'Type Container', 
            'Ukuran Container', 'Nomor Segel', 'Nomor Polisi', 'Jenis COntainer', 'Jenis Muat', 'Driver'
        ];
        $petiRows = [];
        $qPeti = $this->db->query("SELECT * FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '{$escAju}' AND (fl_terangkut = '01' OR fl_terangkut IS NULL OR fl_terangkut = '') ORDER BY seri_container ASC");
        if ($qPeti) {
            while ($rP = $qPeti->fetch_assoc()) {
                $hId = $blIdMap[$rP['nomor_host_bl']] ?? (reset($blIdMap) ?: self::genUuid());
                $petiRows[] = [
                    'Id Terangkut'     => self::genUuid(),
                    'Id Container'     => '',
                    'Seri Container'   => '',
                    'Nomor Container'  => $rP['nomor_container'],
                    'Id Bl'            => $hId,
                    'Fl B3'            => $rP['fl_b3'] ?? '',
                    'Fl Terangkut'     => '01',
                    'Status'           => $rP['status'] ?? '',
                    'Type Container'   => $rP['type_container'] ?: '1',
                    'Ukuran Container' => $rP['ukuran_container'] ?: '20',
                    'Nomor Segel'      => $rP['nomor_segel'] ?? '',
                    'Nomor Polisi'     => !empty($rP['nomor_polisi']) ? $rP['nomor_polisi'] : null,
                    'Jenis COntainer'  => $rP['jenis_container'] ?: '8',
                    'Jenis Muat'       => $rP['jenis_muat'] ?? '',
                    'Driver'           => !empty($rP['driver']) ? $rP['driver'] : null
                ];
            }
        }

        // 7. Sheet BL PETIKEMAS TERTINGGAL (Headers sesuai data-manifest.xlsx)
        $petiTinggalHeaders = [
            'Id Tertinggal', 'Id Container', 'id BL', 'Seri Container', 'Nomor Container', 
            'Fl B3', 'Fl Terangkut', 'Status', 'Type Container', 'Ukuran Container', 
            'Nomor Segel', 'Nomor Polisi', 'Jenis COntainer', 'Jenis Muat', 'Driver'
        ];
        $petiTinggalRows = [];
        $qPetiTinggal = $this->db->query("SELECT * FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '{$escAju}' AND fl_terangkut = '02' ORDER BY seri_container ASC");
        if ($qPetiTinggal) {
            while ($rPT = $qPetiTinggal->fetch_assoc()) {
                $hId = $blIdMap[$rPT['nomor_host_bl']] ?? (reset($blIdMap) ?: self::genUuid());
                $petiTinggalRows[] = [
                    'Id Tertinggal'    => self::genUuid(),
                    'Id Container'     => '',
                    'id BL'            => $hId,
                    'Seri Container'   => '',
                    'Nomor Container'  => $rPT['nomor_container'],
                    'Fl B3'            => $rPT['fl_b3'] ?? '',
                    'Fl Terangkut'     => '02',
                    'Status'           => $rPT['status'] ?? '',
                    'Type Container'   => $rPT['type_container'] ?: '1',
                    'Ukuran Container' => $rPT['ukuran_container'] ?: '20',
                    'Nomor Segel'      => $rPT['nomor_segel'] ?? '',
                    'Nomor Polisi'     => !empty($rPT['nomor_polisi']) ? $rPT['nomor_polisi'] : null,
                    'Jenis COntainer'  => $rPT['jenis_container'] ?: '8',
                    'Jenis Muat'       => $rPT['jenis_muat'] ?? '',
                    'Driver'           => !empty($rPT['driver']) ? $rPT['driver'] : null
                ];
            }
        }

        // URUTAN SHEET PERSIS 1:1 SESUAI data-manifest.xlsx:
        // 1: NVOCC
        // 2: MASTER
        // 3: BL HS
        // 4: BL
        // 5: BL DOKUMEN
        // 6: BL PETIKEMAS TERANGKUT
        // 7: BL PETIKEMAS TERTINGGAL
        $sheetsData = [
            'NVOCC'                    => ['headers' => $nvoccHeaders, 'rows' => $nvoccRows],
            'MASTER'                   => ['headers' => $masterHeaders, 'rows' => $masterRows],
            'BL HS'                    => ['headers' => $hsHeaders, 'rows' => $hsRows],
            'BL'                       => ['headers' => $blHeaders, 'rows' => $blRows],
            'BL DOKUMEN'               => ['headers' => $dokHeaders, 'rows' => $dokRows],
            'BL PETIKEMAS TERANGKUT'   => ['headers' => $petiHeaders, 'rows' => $petiRows],
            'BL PETIKEMAS TERTINGGAL'  => ['headers' => $petiTinggalHeaders, 'rows' => $petiTinggalRows]
        ];

        // Reset shared strings
        $this->sharedStrings = [];
        $this->sharedStringsMap = [];

        // Generate sheet XMLs
        $sheetXmls = [];
        foreach ($sheetsData as $sName => $sConf) {
            $sheetXmls[$sName] = $this->generateSheetXml($sConf['headers'], $sConf['rows']);
        }

        // Tentukan output path
        if (empty($outputFile)) {
            $tmpDir = sys_get_temp_dir();
            $outputFile = $tmpDir . '/Manifest_CEISA4_NVOCC_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $nomorAju) . '_' . uniqid() . '.xlsx';
        }

        if (file_exists($outputFile)) {
            @unlink($outputFile);
        }

        $zip = new ZipArchive();
        if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Gagal membuat arsip zip Excel pada path: " . $outputFile);
        }

        // 1. [Content_Types].xml
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $ct .= '<Default Extension="xml" ContentType="application/xml"/>' . "\n";
        $ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n";
        $ct .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' . "\n";

        $sheetNames = array_keys($sheetsData);
        for ($i = 1; $i <= count($sheetNames); $i++) {
            $ct .= "<Override PartName=\"/xl/worksheets/sheet{$i}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>\n";
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n";
        $rels .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        for ($i = 1; $i <= count($sheetNames); $i++) {
            $wbRels .= "<Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$i}.xml\"/>\n";
        }
        $sharedStrRId = count($sheetNames) + 1;
        $wbRels .= "<Relationship Id=\"rId{$sharedStrRId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings\" Target=\"sharedStrings.xml\"/>\n";
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $wb .= '<sheets>' . "\n";
        for ($i = 1; $i <= count($sheetNames); $i++) {
            $sName = htmlspecialchars($sheetNames[$i - 1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $wb .= "<sheet name=\"{$sName}\" sheetId=\"{$i}\" r:id=\"rId{$i}\"/>\n";
        }
        $wb .= '</sheets>' . "\n";
        $wb .= '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // 5. xl/sharedStrings.xml
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $count = count($this->sharedStrings);
        $ssXml .= "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$count}\" uniqueCount=\"{$count}\">\n";
        foreach ($this->sharedStrings as $str) {
            $safeStr = htmlspecialchars($str, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $ssXml .= "<si><t>{$safeStr}</t></si>\n";
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);

        // 6. xl/worksheets/sheetX.xml
        for ($i = 1; $i <= count($sheetNames); $i++) {
            $sName = $sheetNames[$i - 1];
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $sheetXmls[$sName]);
        }

        $closed = $zip->close();
        if (!$closed) {
            throw new Exception("Gagal menyelesaikan penulisan arsip zip Excel pada path: " . $outputFile);
        }
        if (file_exists($outputFile)) {
            @chmod($outputFile, 0666);
        }
        return $outputFile;
    }

    /**
     * Download langsung file Excel ke browser
     */
    public function download($nomorAju) {
        $filePath = $this->export($nomorAju);
        if (!file_exists($filePath)) {
            throw new Exception("File export tidak ditemukan.");
        }

        $filename = "Manifest_CEISA4_NVOCC_" . preg_replace('/[^a-zA-Z0-9_-]/', '', $nomorAju) . ".xlsx";

        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        readfile($filePath);
        @unlink($filePath);
        exit;
    }
}

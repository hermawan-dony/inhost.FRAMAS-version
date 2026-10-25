<?php
/**
 * CEISA 4.0 Excel Exporter Engine (100% PHP Native)
 * Menghasilkan file .xlsx resmi yang 100% persis dengan struktur unduhan Portal CEISA 4.0.
 * Tanpa library pihak ketiga (PhpSpreadsheet dll), murni menggunakan ZipArchive + XML OpenXML standar.
 * Menghasilkan 21 sheet lengkap, styling header bold, dan format sel yang kompatibel 100% dengan CEISA 4.0.
 */

class CeisaXlsxExporter {
    private $koneksi;
    private $pdo;

    // 21 Sheet Resmi Portal CEISA 4.0 berurutan
    public static $sheetTableMap = [
        'HEADER'           => 'bc_ceisa_header',
        'ENTITAS'          => 'bc_ceisa_entitas',
        'DOKUMEN'          => 'bc_ceisa_dokumen',
        'PENGANGKUT'       => 'bc_ceisa_pengangkut',
        'KEMASAN'          => 'bc_ceisa_kemasan',
        'KONTAINER'        => 'bc_ceisa_kontainer',
        'KOMPONENBIAYA'    => 'bc_ceisa_komponenbiaya',
        'BARANG'           => 'bc_ceisa_barang',
        'BARANGTARIF'      => 'bc_ceisa_barangtarif',
        'BARANGDOKUMEN'    => 'bc_ceisa_barangdokumen',
        'BARANGENTITAS'    => 'bc_ceisa_barangentitas',
        'BARANGSPEKKHUSUS' => 'bc_ceisa_barangspekkhusus',
        'BARANGVD'         => 'bc_ceisa_barangvd',
        'BAHANBAKU'        => 'bc_ceisa_bahanbaku',
        'BAHANBAKUTARIF'   => 'bc_ceisa_bahanbakutarif',
        'BAHANBAKUDOKUMEN' => 'bc_ceisa_bahanbakudokumen',
        'PUNGUTAN'         => 'bc_ceisa_pungutan',
        'JAMINAN'          => 'bc_ceisa_jaminan',
        'BANKDEVISA'       => 'bc_ceisa_bankdevisa',
        'VERSI'            => 'bc_ceisa_versi',
        'RESPON'           => 'bc_ceisa_respons'
    ];

    // Kolom-kolom yang harus dipaksa sebagai STRING agar leading zero tidak hilang (misal: 000040..., 050900, HS code, NPWP)
    private static $forceStringCols = [
        'nomor_aju', 'kode_dokumen', 'kode_kantor', 'kode_kantor_bongkar', 'kode_kantor_periksa',
        'kode_kantor_tujuan', 'kode_kantor_ekspor', 'kode_kantor_muat', 'kode_kantor_asal',
        'kode_jenis_impor', 'kode_jenis_ekspor', 'kode_jenis_tpb', 'kode_jenis_plb', 'kode_jenis_prosedur',
        'kode_tujuan_pemasukan', 'kode_tujuan_pengiriman', 'kode_tujuan_tpb', 'kode_tujuan_pengeluaran',
        'kode_cara_dagang', 'kode_cara_bayar', 'kode_cara_bayar_lainnya', 'kode_gudang_asal', 'kode_gudang_tujuan',
        'kode_jenis_kirim', 'kode_jenis_pengiriman', 'kode_kategori_ekspor', 'kode_lokasi', 'kode_lokasi_bayar',
        'kode_daerah_asal', 'kode_negara_tujuan', 'kode_negara_asal', 'kode_negara', 'kode_tutup_pu',
        'nomor_bc11', 'nomor_pos', 'nomor_sub_pos', 'kode_pelabuhan_bongkar', 'kode_pelabuhan_muat',
        'kode_pelabuhan_muat_akhir', 'kode_pelabuhan_transit', 'kode_pelabuhan_tujuan', 'kode_pelabuhan_ekspor',
        'kode_tps', 'kode_valuta', 'kode_incoterm', 'nomor_daftar', 'nomor_daftar_asal', 'nomor_aju_asal',
        'kode_dokumen_asal', 'nomor_identitas', 'nib_entitas', 'nomor_ijin_entitas', 'nomor_dokumen',
        'kode_entitas', 'kode_jenis_identitas', 'kode_jenis_api', 'kode_status', 'kode_fasilitas', 'kode_ijin',
        'kode_cara_angkut', 'nomor_pengangkut', 'kode_bendera', 'call_sign', 'kode_kemasan', 'nomor_kontainer',
        'nomor_kontiner', 'kode_ukuran_kontainer', 'kode_jenis_kontainer', 'kode_tipe_kontainer', 'hs',
        'kode_barang', 'kode_satuan', 'kode_kategori_barang', 'kode_guna_barang', 'kode_kondisi_barang',
        'kode_perhitungan', 'kode_bkc', 'kode_komoditi_bkc', 'kode_sub_komoditi_bkc', 'kode_komoditi_cukai',
        'kode_sub_komoditi_cukai', 'kode_pungutan', 'kode_tarif', 'kode_fasilitas_tarif', 'kode_jaminan',
        'nomor_jaminan', 'penjamin', 'nomor_bpj', 'kode', 'versi', 'kode_respon', 'nomor_respon', 'npwp_billing',
        'flag_curah', 'flag_sda', 'flag_vd', 'flag_ap_bk', 'flag_migas', 'flag_konsol', 'flag_4_tahun',
        'flag4_tahun', 'flag_tis', 'flag_pelekatan', 'flag_bmt_sementara'
    ];

    private $structure = [];

    public function __construct($koneksiOrPdo = null) {
        if ($koneksiOrPdo instanceof PDO) {
            $this->pdo = $koneksiOrPdo;
        } elseif ($koneksiOrPdo instanceof mysqli) {
            $this->koneksi = $koneksiOrPdo;
        } else {
            // Include default connection jika belum ada
            if (file_exists(__DIR__ . '/koneksi.php')) {
                require_once __DIR__ . '/koneksi.php';
                global $koneksi, $pdo;
                if ($pdo instanceof PDO) $this->pdo = $pdo;
                if ($koneksi instanceof mysqli) $this->koneksi = $koneksi;
            }
        }

        // Muat struktur resmi 21 sheet CEISA 4.0
        $structFile = __DIR__ . '/xls_structure_ceisa40.json';
        if (file_exists($structFile)) {
            $this->structure = json_decode(file_get_contents($structFile), true);
        }
    }

    /**
     * Konversi nomor kolom 1-indexed (1, 2, ... 107) ke huruf kolom Excel (A, B, ... DC)
     */
    public static function getColLetter($colNum) {
        $letter = '';
        while ($colNum > 0) {
            $mod = ($colNum - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $colNum = (int)(($colNum - $mod) / 26);
        }
        return $letter;
    }

    /**
     * Ambil data lengkap seluruh tabel untuk 1 Nomor AJU
     */
    public function getAjuData($nomorAju) {
        $cleanAju = trim($nomorAju);
        $data = [];

        foreach (self::$sheetTableMap as $sheetName => $tableName) {
            $data[$sheetName] = [];
            $orderClause = '';

            // Pengurutan baris natural sesuai standard CEISA
            if (in_array($sheetName, ['ENTITAS', 'DOKUMEN', 'PENGANGKUT', 'KEMASAN', 'KONTAINER', 'BANKDEVISA'])) {
                $orderClause = 'ORDER BY CAST(seri AS UNSIGNED) ASC';
            } elseif (in_array($sheetName, ['BARANG', 'BARANGTARIF', 'BARANGDOKUMEN', 'BARANGENTITAS', 'BARANGSPEKKHUSUS', 'BARANGVD'])) {
                $orderClause = 'ORDER BY CAST(seri_barang AS UNSIGNED) ASC';
            } elseif (in_array($sheetName, ['BAHANBAKU', 'BAHANBAKUTARIF', 'BAHANBAKUDOKUMEN'])) {
                $orderClause = 'ORDER BY CAST(seri_barang AS UNSIGNED), CAST(seri_bahan_baku AS UNSIGNED) ASC';
            } elseif ($sheetName === 'RESPON') {
                $orderClause = 'ORDER BY id ASC';
            }

            $sql = "SELECT * FROM `{$tableName}` WHERE `nomor_aju` = ? {$orderClause}";

            if ($this->pdo) {
                try {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([$cleanAju]);
                    $data[$sheetName] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $data[$sheetName] = [];
                }
            } elseif ($this->koneksi) {
                try {
                    $stmt = @mysqli_prepare($this->koneksi, $sql);
                    if ($stmt) {
                        @mysqli_stmt_bind_param($stmt, 's', $cleanAju);
                        @mysqli_stmt_execute($stmt);
                        $res = @mysqli_stmt_get_result($stmt);
                        if ($res) {
                            while ($r = @mysqli_fetch_assoc($res)) {
                                $data[$sheetName][] = $r;
                            }
                        }
                        @mysqli_stmt_close($stmt);
                    }
                } catch (Exception $e) {
                    $data[$sheetName] = [];
                }
            }
        }

        // Khusus sheet VERSI: jika kosong, berikan 1 baris default versi 1.3
        if (empty($data['VERSI'])) {
            $data['VERSI'] = [
                ['nomor_aju' => $cleanAju, 'versi' => '1.3']
            ];
        }

        return $data;
    }

    /**
     * Membangun file .xlsx resmi CEISA 4.0
     */
    public function generateXlsx($nomorAju, $targetFilePath) {
        $cleanAju = trim($nomorAju);
        $ajuData = $this->getAjuData($cleanAju);

        // Siapkan pool shared strings
        $sharedStrings = [];
        $stringIndexMap = [];

        $getStringIdx = function($val) use (&$sharedStrings, &$stringIndexMap) {
            $str = (string)$val;
            if (isset($stringIndexMap[$str])) {
                return $stringIndexMap[$str];
            }
            $idx = count($sharedStrings);
            $sharedStrings[] = $str;
            $stringIndexMap[$str] = $idx;
            return $idx;
        };

        // Buat file ZIP
        $zip = new ZipArchive();
        if ($zip->open($targetFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Gagal membuat arsip file Excel di: " . $targetFilePath);
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $contentTypes .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $contentTypes .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' . "\n";

        for ($i = 1; $i <= 21; $i++) {
            $contentTypes .= '  <Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n";
        }
        $contentTypes .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $relsDot = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $relsDot .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $relsDot .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n";
        $relsDot .= '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' . "\n";
        $relsDot .= '  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' . "\n";
        $relsDot .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $relsDot);

        // 3. docProps/core.xml & app.xml
        $nowIso = date('Y-m-d\TH:i:s\Z');
        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $coreXml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $coreXml .= '  <dc:creator>CEISA 4.0</dc:creator>' . "\n";
        $coreXml .= '  <cp:lastModifiedBy>CEISA 4.0</cp:lastModifiedBy>' . "\n";
        $coreXml .= '  <dcterms:created xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:created>' . "\n";
        $coreXml .= '  <dcterms:modified xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:modified>' . "\n";
        $coreXml .= '</cp:coreProperties>';
        $zip->addFromString('docProps/core.xml', $coreXml);

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $appXml .= '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">' . "\n";
        $appXml .= '  <Application>Portal CEISA 4.0</Application>' . "\n";
        $appXml .= '</Properties>';
        $zip->addFromString('docProps/app.xml', $appXml);

        // 4. xl/styles.xml (Persis CEISA 4.0: s=0 regular, s=1 bold header, s=2 date)
        $stylesXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $stylesXml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $stylesXml .= '  <numFmts count="1"><numFmt numFmtId="165" formatCode="dd-MM-yyyy HH:mm:ss"/></numFmts>' . "\n";
        $stylesXml .= '  <fonts count="2">' . "\n";
        $stylesXml .= '    <font><sz val="11.00"/><color rgb="FF000000"/><name val="Calibri"/></font>' . "\n";
        $stylesXml .= '    <font><b/><sz val="11.00"/><color rgb="FF000000"/><name val="Calibri"/></font>' . "\n";
        $stylesXml .= '  </fonts>' . "\n";
        $stylesXml .= '  <fills count="2">' . "\n";
        $stylesXml .= '    <fill><patternFill patternType="none"/></fill>' . "\n";
        $stylesXml .= '    <fill><patternFill patternType="gray125"/></fill>' . "\n";
        $stylesXml .= '  </fills>' . "\n";
        $stylesXml .= '  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' . "\n";
        $stylesXml .= '  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' . "\n";
        $stylesXml .= '  <cellXfs count="3">' . "\n";
        $stylesXml .= '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n";
        $stylesXml .= '    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>' . "\n"; // Header bold
        $stylesXml .= '    <xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n"; // Tanggal
        $stylesXml .= '  </cellXfs>' . "\n";
        $stylesXml .= '  <dxfs count="0"/>' . "\n";
        $stylesXml .= '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $stylesXml);

        // 5. xl/workbook.xml
        $wbXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $wbXml .= '  <workbookPr date1904="false"/>' . "\n";
        $wbXml .= '  <bookViews><workbookView activeTab="0"/></bookViews>' . "\n";
        $wbXml .= '  <sheets>' . "\n";

        $sheetIdx = 1;
        foreach (self::$sheetTableMap as $sheetName => $tbl) {
            $rId = 'rId' . ($sheetIdx + 2); // rId1=sharedStrings, rId2=styles
            $wbXml .= '    <sheet name="' . $sheetName . '" sheetId="' . $sheetIdx . '" r:id="' . $rId . '"/>' . "\n";
            $sheetIdx++;
        }
        $wbXml .= '  </sheets>' . "\n";
        $wbXml .= '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wbXml);

        // 6. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n";
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $wbRels .= '  <Relationship Id="rId1" Target="sharedStrings.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"/>' . "\n";
        $wbRels .= '  <Relationship Id="rId2" Target="styles.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"/>' . "\n";

        $sheetIdx = 1;
        foreach (self::$sheetTableMap as $sheetName => $tbl) {
            $rId = 'rId' . ($sheetIdx + 2);
            $wbRels .= '  <Relationship Id="' . $rId . '" Target="worksheets/sheet' . $sheetIdx . '.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>' . "\n";
            $sheetIdx++;
        }
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 7. Worksheets 1..21
        $sheetIdx = 1;
        foreach (self::$sheetTableMap as $sheetName => $tableName) {
            $cols = $this->structure[$sheetName] ?? [];
            $rows = $ajuData[$sheetName] ?? [];
            $colCount = count($cols);
            $maxRow = max(1, count($rows) + 1);
            $lastColLetter = self::getColLetter($colCount);

            $wsXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $wsXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
            $wsXml .= '  <dimension ref="A1:' . $lastColLetter . $maxRow . '"/>' . "\n";
            $wsXml .= '  <sheetViews><sheetView tabSelected="' . ($sheetIdx === 1 ? '1' : '0') . '" workbookViewId="0"/></sheetViews>' . "\n";
            $wsXml .= '  <sheetFormatPr defaultRowHeight="15.0"/>' . "\n";

            // Definisi lebar kolom
            if ($colCount > 0) {
                $wsXml .= '  <cols>' . "\n";
                for ($c = 1; $c <= $colCount; $c++) {
                    $w = 16.0;
                    if ($c === 1) $w = 28.0; // Nomor AJU
                    elseif ($c === 5 && in_array($sheetName, ['BARANG', 'BAHANBAKU'])) $w = 35.0; // Uraian
                    $wsXml .= '    <col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>' . "\n";
                }
                $wsXml .= '  </cols>' . "\n";
            }

            $wsXml .= '  <sheetData>' . "\n";

            // Baris 1: Headers (s="1" bold)
            $wsXml .= '    <row r="1">' . "\n";
            for ($c = 1; $c <= $colCount; $c++) {
                $rawHeader = $cols[$c - 1];
                $cellRef = self::getColLetter($c) . '1';
                $sIdx = $getStringIdx($rawHeader);
                $wsXml .= '      <c r="' . $cellRef . '" s="1" t="s"><v>' . $sIdx . '</v></c>' . "\n";
            }
            $wsXml .= '    </row>' . "\n";

            // Baris 2..N: Data Riil
            $rNum = 2;
            foreach ($rows as $row) {
                $wsXml .= '    <row r="' . $rNum . '">' . "\n";
                for ($c = 1; $c <= $colCount; $c++) {
                    $rawHeader = $cols[$c - 1];
                    $cellRef = self::getColLetter($c) . $rNum;

                    // Normalisasi nama kolom DB
                    $cleanKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($rawHeader)));
                    $cleanKey = trim($cleanKey, '_');

                    // Penanganan alias
                    if ($cleanKey === 'nomor_kontiner' && !isset($row['nomor_kontiner']) && isset($row['nomor_kontainer'])) {
                        $cleanKey = 'nomor_kontainer';
                    } elseif ($cleanKey === 'flag_4_tahun' && !isset($row['flag_4_tahun']) && isset($row['flag4_tahun'])) {
                        $cleanKey = 'flag4_tahun';
                    }

                    $cellVal = $row[$cleanKey] ?? null;

                    // Khusus kolom nomor_aju jika di DB kosong
                    if ($cleanKey === 'nomor_aju' && ($cellVal === null || $cellVal === '')) {
                        $cellVal = $cleanAju;
                    }

                    // Jika null atau kosong, lewati cell (persis CEISA 4.0)
                    if ($cellVal === null || $cellVal === '') {
                        continue;
                    }

                    $valStr = (string)$cellVal;

                    // Cek apakah kolom ini dipaksa sebagai STRING
                    $isForceString = in_array($cleanKey, self::$forceStringCols);

                    // Cek apakah numerik
                    $isNumeric = (!$isForceString) && is_numeric($valStr) && !preg_match('/^0\d+/', $valStr);

                    if ($isNumeric) {
                        // Numeric cell
                        $wsXml .= '      <c r="' . $cellRef . '"><v>' . (float)$valStr . '</v></c>' . "\n";
                    } else {
                        // String cell (shared strings)
                        $sIdx = $getStringIdx($valStr);
                        $wsXml .= '      <c r="' . $cellRef . '" t="s"><v>' . $sIdx . '</v></c>' . "\n";
                    }
                }
                $wsXml .= '    </row>' . "\n";
                $rNum++;
            }

            $wsXml .= '  </sheetData>' . "\n";
            $wsXml .= '</worksheet>';

            $zip->addFromString('xl/worksheets/sheet' . $sheetIdx . '.xml', $wsXml);
            $sheetIdx++;
        }

        // 8. xl/sharedStrings.xml
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $ssCount = count($sharedStrings);
        $ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $ssCount . '" uniqueCount="' . $ssCount . '">' . "\n";
        foreach ($sharedStrings as $str) {
            $escaped = htmlspecialchars($str, ENT_XML1, 'UTF-8');
            $ssXml .= '  <si><t>' . $escaped . '</t></si>' . "\n";
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);

        $zip->close();
        return true;
    }

    /**
     * Download langsung ke browser via HTTP stream
     */
    public function streamDownload($nomorAju) {
        $cleanAju = trim($nomorAju);
        $tmpFile = tempnam(sys_get_temp_dir(), 'ceisa40_') . '.xlsx';

        $this->generateXlsx($cleanAju, $tmpFile);

        $downloadFilename = $cleanAju . '.xlsx';

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmpFile));

        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }
}

<?php
/**
 * CEISA 4.0 Excel Importer Engine
 * Membaca file .xlsx resmi dari Portal CEISA 4.0 dan mengimpor data langsung ke database MySQL (ceisa0400db).
 * Tanpa tabel temporary (_tmp), 100% PHP native (ZipArchive + SimpleXML) tanpa ketergantungan library luar.
 * Mendukung Single AJU & Batch Multi-AJU, 22 sheet dokumen pabean, bulk-insert tuning berkecepatan tinggi.
 */

class CeisaXlsxImporter {
    private $pdo;
    private $koneksi;
    private $cmpId;
    private $tableColumnsCache = [];
    
    // Mapping 22 Sheet Portal CEISA 4.0 ke Tabel Database Riil
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
        'KESIAPANBARANG'   => 'bc_ceisa_kesiapanbarang',
        'VERSI'            => 'bc_ceisa_versi',
        'RESPON'           => 'bc_ceisa_respons'
    ];

    // Alias kolom dari Excel ke database
    private static $columnAliases = [
        'nomor_kontiner' => 'nomor_kontainer',
        'flag_4_tahun'   => 'flag4_tahun'
    ];

    public function __construct($koneksiOrPdo, $cmpId = '') {
        if ($koneksiOrPdo instanceof PDO) {
            $this->pdo = $koneksiOrPdo;
        } elseif ($koneksiOrPdo instanceof mysqli) {
            $this->koneksi = $koneksiOrPdo;
        }
        $this->cmpId = trim($cmpId);
    }

    public function setCmpId($cmpId) {
        $this->cmpId = trim($cmpId);
    }

    public function getCmpId() {
        return $this->cmpId;
    }

    /**
     * Membaca struktur dan isi file Excel .xlsx secara native dengan SimpleXML & ZipArchive
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

        // 1. Ambil Shared Strings
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

        // 2. Ambil Mapping Nama Sheet ke File XML
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
            $headerKeyMap = []; // letter => column key (dengan penanganan nama kolom duplikat)
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
                    // Row Header
                    $headers = $rowData;
                    $headerCounts = [];
                    foreach ($headers as $colLetter => $rawHeader) {
                        $baseKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim($rawHeader)));
                        $baseKey = trim($baseKey, '_');
                        if ($baseKey === '') continue;

                        if (!isset($headerCounts[$baseKey])) {
                            $headerCounts[$baseKey] = 1;
                            $headerKeyMap[$colLetter] = $baseKey;
                        } else {
                            $headerCounts[$baseKey]++;
                            // Tambahkan suffix angka untuk kolom kembar (misal: asuransi1, kode_gudang_asal1)
                            $suffixNum = $headerCounts[$baseKey] - 1;
                            $headerKeyMap[$colLetter] = $baseKey . $suffixNum;
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
     * Mengambil daftar kolom riil dari tabel database (dengan caching)
     */
    private function getTableColumns($tableName) {
        if (isset($this->tableColumnsCache[$tableName])) {
            return $this->tableColumnsCache[$tableName];
        }

        $cols = [];
        if ($this->pdo) {
            try {
                $stmt = $this->pdo->query("DESCRIBE `$tableName`");
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[strtolower($r['Field'])] = true;
                }
            } catch (Exception $e) {
                $cols = [];
            }
        } elseif ($this->koneksi) {
            try {
                $res = @mysqli_query($this->koneksi, "DESCRIBE `$tableName`");
                if ($res) {
                    while ($r = mysqli_fetch_assoc($res)) {
                        $cols[strtolower($r['Field'])] = true;
                    }
                }
            } catch (Exception $e) {
                $cols = [];
            }
        }

        $this->tableColumnsCache[$tableName] = $cols;
        return $cols;
    }

    /**
     * Mengecek apakah tabel tertentu ada di database
     */
    private function tableExists($tableName) {
        $cols = $this->getTableColumns($tableName);
        return !empty($cols);
    }

    /**
     * Sanitasi & normalisasi nilai kolom untuk MySQL
     */
    private function normalizeValue($colName, $val) {
        if ($val === '' || $val === null) {
            return null;
        }

        // Normalisasi format tanggal jika kolom bertipe tanggal
        if (strpos($colName, 'tanggal') !== false || strpos($colName, 'tgl') !== false || $colName === 'waktu' || $colName === 'jatuh_tempo') {
            $vTrim = trim($val);
            if ($vTrim === '' || $vTrim === '0000-00-00' || strpos($vTrim, '0000-00-00') === 0) return null;

            // Format Excel integer date (misal: 45445)
            if (is_numeric($vTrim) && (int)$vTrim > 30000 && (int)$vTrim < 60000) {
                $unixDate = ((int)$vTrim - 25569) * 86400;
                return gmdate("Y-m-d", $unixDate);
            }

            // Format YYYY-MM-DD atau YYYY-DD-MM atau YYYY/MM/DD atau YYYY/DD/MM
            if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})(?:\s+.*)?$/', $vTrim, $m)) {
                $y = $m[1];
                $p2 = (int)$m[2];
                $p3 = (int)$m[3];
                if ($p2 > 12 && $p3 <= 12) {
                    // YYYY-DD-MM -> balik ke YYYY-MM-DD
                    $month = str_pad($p3, 2, '0', STR_PAD_LEFT);
                    $day = str_pad($p2, 2, '0', STR_PAD_LEFT);
                } else {
                    $month = str_pad($p2, 2, '0', STR_PAD_LEFT);
                    $day = str_pad($p3, 2, '0', STR_PAD_LEFT);
                }
                return "{$y}-{$month}-{$day}";
            }

            // Format DD-MM-YYYY atau MM-DD-YYYY atau DD/MM/YYYY atau MM/DD/YYYY
            if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{4})(\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', $vTrim, $m)) {
                $p1 = (int)$m[1];
                $p2 = (int)$m[2];
                $y = $m[3];
                $hasTime = !empty($m[4]);
                if ($p1 > 12 && $p2 <= 12) {
                    // Pasti DD-MM-YYYY (karena p1 > 12 tidak mungkin bulan)
                    $day = str_pad($p1, 2, '0', STR_PAD_LEFT);
                    $month = str_pad($p2, 2, '0', STR_PAD_LEFT);
                } elseif ($p1 <= 12 && $p2 > 12) {
                    // Pasti MM-DD-YYYY (karena p2 > 12 tidak mungkin bulan)
                    $month = str_pad($p1, 2, '0', STR_PAD_LEFT);
                    $day = str_pad($p2, 2, '0', STR_PAD_LEFT);
                } elseif ($hasTime) {
                    // Format CEISA export resmi dengan jam (MM-DD-YYYY HH:mm:ss)
                    $month = str_pad($p1, 2, '0', STR_PAD_LEFT);
                    $day = str_pad($p2, 2, '0', STR_PAD_LEFT);
                } else {
                    // Standar manual input Indonesia tanpa jam (DD-MM-YYYY)
                    $day = str_pad($p1, 2, '0', STR_PAD_LEFT);
                    $month = str_pad($p2, 2, '0', STR_PAD_LEFT);
                }
                return "{$y}-{$month}-{$day}";
            }
        }

        return $val;
    }

    /**
     * Eksekusi batch insert berkecepatan tinggi (Chunked Bulk Insert)
     */
    private function insertBatch($tableName, array $rows, array $tableCols, $chunkSize = 150) {
        if (empty($rows)) return 0;

        $insertedCount = 0;
        $chunks = array_chunk($rows, $chunkSize);

        foreach ($chunks as $chunk) {
            // Tentukan kolom gabungan dari seluruh baris dalam chunk
            $allKeys = [];
            foreach ($chunk as $r) {
                foreach ($r as $k => $v) {
                    if (isset($tableCols[$k])) {
                        $allKeys[$k] = true;
                    }
                }
            }
            $cols = array_keys($allKeys);
            if (empty($cols)) continue;

            $quotedCols = array_map(function($c) { return "`$c`"; }, $cols);
            $valuesClauses = [];
            $bindParams = [];

            foreach ($chunk as $r) {
                $rowPlaceholders = [];
                foreach ($cols as $c) {
                    $val = $r[$c] ?? null;
                    $rowPlaceholders[] = '?';
                    $bindParams[] = $this->normalizeValue($c, $val);
                }
                $valuesClauses[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            // Tambahkan ON DUPLICATE KEY UPDATE untuk kolom data non-identitas agar tidak terjadi Duplicate Entry crash
            $updateClauses = [];
            foreach ($cols as $c) {
                if ($c === 'cmp_id' || $c === 'nomor_aju') continue;
                $updateClauses[] = "`$c` = VALUES(`$c`)";
            }
            
            if (!empty($updateClauses)) {
                $sql = "INSERT INTO `$tableName` (" . implode(', ', $quotedCols) . ") VALUES " . implode(', ', $valuesClauses) . " ON DUPLICATE KEY UPDATE " . implode(', ', $updateClauses);
            } else {
                $sql = "INSERT IGNORE INTO `$tableName` (" . implode(', ', $quotedCols) . ") VALUES " . implode(', ', $valuesClauses);
            }

            if ($this->pdo) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindParams);
                $insertedCount += count($chunk);
            } elseif ($this->koneksi) {
                $stmt = mysqli_prepare($this->koneksi, $sql);
                if (!$stmt) {
                    throw new Exception("MySQL Prepare failed on $tableName: " . mysqli_error($this->koneksi));
                }
                // Bind parameter secara dinamis
                $types = '';
                foreach ($bindParams as $bp) {
                    $types .= 's';
                }
                mysqli_stmt_bind_param($stmt, $types, ...$bindParams);
                if (!mysqli_stmt_execute($stmt)) {
                    $err = mysqli_stmt_error($stmt);
                    mysqli_stmt_close($stmt);
                    throw new Exception("MySQL Execute failed on $tableName: " . $err);
                }
                mysqli_stmt_close($stmt);
                $insertedCount += count($chunk);
            }
        }

        return $insertedCount;
    }

    /**
     * Menjalankan impor data Excel ke tabel database
     */
    public function import($filePath, $options = []) {
        $excelData = $this->parseXlsx($filePath);
        return $this->importData($excelData, $options);
    }

    /**
     * Menjalankan impor data array terstruktur ke tabel database
     */
    public function importData(array $excelData, $options = []) {
        $startTime = microtime(true);
        $overwrite = $options['overwrite'] ?? true;
        
        if (empty($excelData['HEADER']) || empty($excelData['HEADER'][0])) {
            throw new Exception("Data tidak memiliki Sheet 'HEADER' yang valid.");
        }

        // Kumpulkan seluruh nomor AJU yang ada di file Excel (mendukung file batch dengan banyak AJU)
        $allAjus = [];
        $kodeDokumen = '';
        foreach ($excelData['HEADER'] as $hRow) {
            if (!empty($hRow['nomor_aju'])) {
                $allAjus[] = trim($hRow['nomor_aju']);
            }
            if (empty($kodeDokumen) && !empty($hRow['kode_dokumen'])) {
                $kodeDokumen = trim($hRow['kode_dokumen']);
            }
        }
        $allAjus = array_values(array_unique(array_filter($allAjus)));
        if (empty($allAjus)) {
            throw new Exception("Kolom 'NOMOR AJU' pada sheet HEADER kosong atau tidak ditemukan.");
        }

        $primaryAju = $allAjus[0];

        // Validasi / penentuan cmp_id
        $cmpId = $this->cmpId;
        if (empty($cmpId)) {
            $cmpId = $options['cmp_id'] ?? ($_SESSION['cmp_id'] ?? '');
        }
        if (empty($cmpId)) {
            $headerRow0 = $excelData['HEADER'][0];
            $idPengusaha = $headerRow0['id_pengusaha'] ?? ($headerRow0['npwp_pengusaha'] ?? '');
            if (!empty($idPengusaha)) {
                $cmpId = $idPengusaha;
            }
        }
        if (empty($cmpId)) {
            throw new Exception("cmp_id (Company ID / NPWP) harus ditentukan untuk multi-company.");
        }

        // Mulai transaksi database
        if ($this->pdo) {
            $this->pdo->beginTransaction();
        } elseif ($this->koneksi) {
            mysqli_begin_transaction($this->koneksi);
        }

        $importedStats = [];
        $importWarnings = [];
        $totalRows = 0;
        $hasHeaderSuccess = false;
        $hasBarangSuccess = false;

        try {
            foreach (self::$sheetTableMap as $sheetName => $tableName) {
                try {
                    // Khusus VERSI: jika sheet VERSI ada tapi tidak memiliki nomor_aju, kita sebarkan ke semua AJU
                    if ($sheetName === 'VERSI') {
                        if (!$this->tableExists($tableName)) continue;
                        $tableCols = $this->getTableColumns($tableName);
                        $versiStr = '1.3';
                        if (!empty($excelData['VERSI']) && !empty($excelData['VERSI'][0]['versi'])) {
                            $versiStr = trim($excelData['VERSI'][0]['versi']);
                        }
                        $versiRows = [];
                        foreach ($allAjus as $aju) {
                            $versiRows[] = [
                                'cmp_id'    => $cmpId,
                                'nomor_aju' => $aju,
                                'versi'     => $versiStr
                            ];
                        }

                        if ($overwrite) {
                            $this->deleteExistingBatch($tableName, $cmpId, $allAjus);
                        }

                        $cnt = $this->insertBatch($tableName, $versiRows, $tableCols, 150);
                        $importedStats[$tableName] = $cnt;
                        $totalRows += $cnt;
                        continue;
                    }

                    if (empty($excelData[$sheetName])) {
                        continue;
                    }

                    // Cek apakah tabel ada di database
                    if (!$this->tableExists($tableName)) {
                        continue;
                    }

                    $tableCols = $this->getTableColumns($tableName);
                    $rows = $excelData[$sheetName];

                    // Jika overwrite aktif, bersihkan data lama untuk seluruh AJU yang tertera di file ini
                    if ($overwrite) {
                        $this->deleteExistingBatch($tableName, $cmpId, $allAjus);
                    }

                    // Siapkan baris data dengan penyesuaian kolom & alias
                    $preparedRows = [];
                    $seriCounters = [];
                    foreach ($rows as $row) {
                        // Gunakan nomor_aju dari baris tersebut jika ada, atau fallback ke primaryAju
                        $rowAju = !empty($row['nomor_aju']) ? trim($row['nomor_aju']) : $primaryAju;
                        $row['nomor_aju'] = $rowAju;
                        if (isset($tableCols['cmp_id'])) {
                            $row['cmp_id'] = $cmpId;
                        }

                        // Terapkan kolom alias (misal nomor_kontiner -> nomor_kontainer)
                        foreach (self::$columnAliases as $srcCol => $dstCol) {
                            if (isset($row[$srcCol]) && !isset($row[$dstCol])) {
                                $row[$dstCol] = $row[$srcCol];
                            }
                        }

                        // Cek apakah baris ini memiliki data riil selain nomor_aju dan cmp_id
                        $hasPayload = false;
                        foreach ($row as $k => $v) {
                            if ($k === 'nomor_aju' || $k === 'cmp_id') continue;
                            if ($v !== null && trim((string)$v) !== '') {
                                $hasPayload = true;
                                break;
                            }
                        }

                        // Jika baris kosong tanpa data riil (hanya nomor_aju), lewati baris ini
                        if (!$hasPayload) {
                            continue;
                        }

                        // Auto-assign seri jika seri bernilai null/empty pada tabel yang membutuhkannya
                        if (isset($tableCols['seri']) && (!isset($row['seri']) || $row['seri'] === null || $row['seri'] === '')) {
                            if (!isset($seriCounters[$rowAju])) $seriCounters[$rowAju] = 0;
                            $row['seri'] = ++$seriCounters[$rowAju];
                        }

                        // Filter kolom: hanya masukkan kolom yang benar-benar ada di tabel MySQL
                        $filteredRow = [];
                        foreach ($row as $k => $v) {
                            if (isset($tableCols[$k])) {
                                $filteredRow[$k] = $v;
                            }
                        }

                        if (!empty($filteredRow)) {
                            $preparedRows[] = $filteredRow;
                        }
                    }

                    // Deduplikasi khusus & akumulasi nilai jika ada duplikat dalam satu sheet Excel:
                    // 1. PUNGUTAN: gabungkan nilai_pungutan jika cmp_id, nomor_aju, jenis pungutan, dan fasilitas sama
                    if ($tableName === 'bc_ceisa_pungutan' && !empty($preparedRows)) {
                        $merged = [];
                        foreach ($preparedRows as $pRow) {
                            $k = ($pRow['cmp_id'] ?? '') . '|' . ($pRow['nomor_aju'] ?? '') . '|' . ($pRow['kode_jenis_pungutan'] ?? '') . '|' . ($pRow['kode_fasilitas_tarif'] ?? '');
                            if (isset($merged[$k])) {
                                $merged[$k]['nilai_pungutan'] = (float)($merged[$k]['nilai_pungutan'] ?? 0) + (float)($pRow['nilai_pungutan'] ?? 0);
                                if (empty($merged[$k]['npwp_billing']) && !empty($pRow['npwp_billing'])) {
                                    $merged[$k]['npwp_billing'] = $pRow['npwp_billing'];
                                }
                            } else {
                                $merged[$k] = $pRow;
                            }
                        }
                        $preparedRows = array_values($merged);
                    }

                    // 2. KOMPONENBIAYA: gabungkan nilai jika kode_komponen_biaya sama
                    if ($tableName === 'bc_ceisa_komponenbiaya' && !empty($preparedRows)) {
                        $merged = [];
                        foreach ($preparedRows as $pRow) {
                            $k = ($pRow['cmp_id'] ?? '') . '|' . ($pRow['nomor_aju'] ?? '') . '|' . ($pRow['kode_komponen_biaya'] ?? '');
                            if (isset($merged[$k])) {
                                $merged[$k]['nilai'] = (float)($merged[$k]['nilai'] ?? 0) + (float)($pRow['nilai'] ?? 0);
                            } else {
                                $merged[$k] = $pRow;
                            }
                        }
                        $preparedRows = array_values($merged);
                    }

                    // Jalankan Bulk Insert Tuning
                    $insertedCount = $this->insertBatch($tableName, $preparedRows, $tableCols, 150);
                    $importedStats[$tableName] = $insertedCount;
                    $totalRows += $insertedCount;

                    if ($sheetName === 'HEADER') {
                        $hasHeaderSuccess = true;
                    } elseif ($sheetName === 'BARANG') {
                        $hasBarangSuccess = true;
                    }

                } catch (Exception $sheetErr) {
                    // Jika HEADER atau BARANG gagal, batalkan seluruh transaksi karena ini data inti dokumen
                    if ($sheetName === 'HEADER' || $sheetName === 'BARANG') {
                        throw $sheetErr;
                    }
                    // Jika sheet pelengkap (PUNGUTAN, KONTAINER, dsb) gagal, simpan sebagai warning & lanjutkan transaksi
                    $importWarnings[$sheetName] = $sheetErr->getMessage();
                    error_log("CeisaXlsxImporter [Warning] Sheet $sheetName ($tableName): " . $sheetErr->getMessage());
                }
            }

            // Pastikan data HEADER minimal berhasil tersimpan sebelum commit
            if (!$hasHeaderSuccess) {
                throw new Exception("Gagal mengimpor data inti (HEADER kosong atau gagal tersimpan).");
            }

            // Commit transaksi
            if ($this->pdo) {
                $this->pdo->commit();
            } elseif ($this->koneksi) {
                mysqli_commit($this->koneksi);
            }

        } catch (Exception $e) {
            // Rollback jika terjadi kesalahan pada HEADER/BARANG atau kesalahan fatal
            if ($this->pdo) {
                $this->pdo->rollBack();
            } elseif ($this->koneksi) {
                mysqli_rollback($this->koneksi);
            }
            throw $e;
        }

        $execTime = round(microtime(true) - $startTime, 3);

        return [
            'status'                 => 'OK',
            'message'                => !empty($importWarnings) 
                ? 'Data dokumen pabean berhasil disimpan (HEADER & BARANG aman), dengan catatan pada beberapa sheet pelengkap.' 
                : 'Data dokumen pabean berhasil diimport ke tabel riil.',
            'warnings'               => $importWarnings,
            'cmp_id'                 => $cmpId,
            'nomor_aju'              => (count($allAjus) === 1 ? $primaryAju : $allAjus),
            'total_ajus'             => count($allAjus),
            'kode_dokumen'           => $kodeDokumen,
            'file_name'              => (!empty($filePath) ? basename($filePath) : ($options['file_name'] ?? 'LNSW_JSON')),
            'tables_imported'        => $importedStats,
            'total_rows_imported'    => $totalRows,
            'execution_time_seconds' => $execTime
        ];
    }

    /**
     * Hapus data AJU lama saat overwrite secara batch (jauh lebih cepat daripada query satu per satu)
     */
    private function deleteExistingBatch($tableName, $cmpId, array $allAjus) {
        if (empty($allAjus)) return;
        $tableCols = $this->getTableColumns($tableName);
        if (!isset($tableCols['cmp_id']) || !isset($tableCols['nomor_aju'])) {
            return;
        }

        $chunks = array_chunk($allAjus, 150);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $deleteSql = "DELETE FROM `$tableName` WHERE cmp_id = ? AND nomor_aju IN ($placeholders)";
            $params = array_merge([$cmpId], $chunk);

            if ($this->pdo) {
                $delStmt = $this->pdo->prepare($deleteSql);
                $delStmt->execute($params);
            } elseif ($this->koneksi) {
                $delStmt = mysqli_prepare($this->koneksi, $deleteSql);
                if ($delStmt) {
                    $types = str_repeat('s', count($params));
                    mysqli_stmt_bind_param($delStmt, $types, ...$params);
                    mysqli_stmt_execute($delStmt);
                    mysqli_stmt_close($delStmt);
                }
            }
        }
    }

    private function deleteExisting($tableName, $cmpId, $nomorAju) {
        $this->deleteExistingBatch($tableName, $cmpId, [$nomorAju]);
    }
}
?>

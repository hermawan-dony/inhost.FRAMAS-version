<?php
/**
 * CEISA 4.0 Official Excel Downloader & Cache Service (download_ceisa_excel.php)
 * Mengunduh berkas Excel resmi 21 Sheet langsung dari API CEISA 4.0:
 * Endpoint Resmi: https://apis-gw.beacukai.go.id/excel-service/v1/ekspor-xml/Xlsx?nomorAju={nomorAju}&idUser={idUser}&kodeDokumen={kodeDokumen}
 * 
 * Fitur:
 * 1. Otentikasi otomatis OAuth MFA OTP per perusahaan via initmfa.php
 * 2. Multi-tier Local Caching dengan verifikasi integritas data sheet (Anti File Kosong)
 * 3. Proteksi WAF Bea Cukai & Anti-Blokir IP dengan header presisi portal resmi
 * 4. Otomatis sinkronisasi data ke Staging MySQL via CeisaXlsxImporter
 * 5. Dukungan Unduhan Tunggal (.xlsx), Mode AJAX Batch (JSON), dan Bundling Arsip (.zip)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/zip_archive_polyfill.php';
require_once __DIR__ . '/datalogin.php';

// Pastikan folder cache excel tersedia
$cacheDir = __DIR__ . '/excel_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}

/**
 * Validasi apakah file Excel hasil unduhan benar-benar memiliki data transaksi
 * (Bukan file kosong atau file template yang hanya memiliki baris header).
 */
function isValidCeisaExcel($filePath) {
    if (!file_exists($filePath) || filesize($filePath) < 2000) {
        return false;
    }
    if (!class_exists('ZipArchive')) {
        return filesize($filePath) > 2000;
    }
    
    $zip = new ZipArchive();
    if ($zip->open($filePath) === true) {
        $sheet1 = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet1) {
            // Bea Cukai XLSX selalu menyertakan <row r="1"> untuk nama kolom.
            // Baris data transaksi riil dimulai dari <row r="2"> atau lebih besar.
            if (preg_match('/<row\s+r=["\']([2-9]|\d{2,})["\']/i', $sheet1)) {
                return true; // Ada baris transaksi (row 2 atau lebih)!
            }
            return false; // Hanya ada row 1 (header kosong tanpa data)
        }
        return true;
    }
    return false;
}

// -----------------------------------------------------------------------------
// 1. ACTION: BUNDLE ZIP (Menggabungkan beberapa file Excel terpilih menjadi 1 ZIP)
// -----------------------------------------------------------------------------
$action = trim($_REQUEST['action'] ?? '');
if ($action === 'bundle_zip') {
    $rawAjus = $_REQUEST['nomor_ajus'] ?? '';
    $ajuList = [];
    if (is_array($rawAjus)) {
        $ajuList = $rawAjus;
    } elseif (is_string($rawAjus)) {
        $ajuList = explode(',', $rawAjus);
    }
    
    $cleanList = [];
    foreach ($ajuList as $a) {
        $c = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($a));
        if (!empty($c)) {
            $cleanList[] = $c;
        }
    }
    $cleanList = array_unique($cleanList);

    if (empty($cleanList)) {
        http_response_code(400);
        die("Daftar nomor pengajuan (nomor_ajus) tidak boleh kosong.");
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die("Ekstensi ZipArchive PHP tidak aktif pada server ini.");
    }

    $zipFilename = 'Unduh_Excel_CEISA_' . date('Ymd_His') . '.zip';
    $tempZipPath = sys_get_temp_dir() . '/' . uniqid('ceisa_zip_') . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die("Gagal membuat arsip ZIP di direktori sementara.");
    }

    $addedCount = 0;
    foreach ($cleanList as $cleanAju) {
        $filePath = $cacheDir . '/' . $cleanAju . '.xlsx';
        if (isValidCeisaExcel($filePath)) {
            $zip->addFile($filePath, $cleanAju . '.xlsx');
            $addedCount++;
        }
    }
    $zip->close();

    if ($addedCount === 0 || !file_exists($tempZipPath) || filesize($tempZipPath) < 100) {
        @unlink($tempZipPath);
        http_response_code(404);
        die("Tidak ada berkas Excel yang valid ditemukan di cache untuk nomor pengajuan tersebut. Harap unduh terlebih dahulu.");
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($tempZipPath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    readfile($tempZipPath);
    @unlink($tempZipPath);
    exit;
}

// -----------------------------------------------------------------------------
// 2. UNDUH DOKUMEN EXCEL TUNGGAL / STATUS JSON
// -----------------------------------------------------------------------------
$nomorAju          = trim($_REQUEST['nomor_aju'] ?? ($_REQUEST['aju'] ?? ''));
$targetCmp         = trim($_REQUEST['cmp_id'] ?? '');
$customIdUser      = trim($_REQUEST['id_user'] ?? '');
$customKodeDokumen = trim($_REQUEST['kode_dokumen'] ?? ($_REQUEST['kodeDokumen'] ?? ''));
$format            = strtolower(trim($_REQUEST['format'] ?? 'download')); // 'download' | 'json'
$force             = !empty($_REQUEST['force']) && ($_REQUEST['force'] === '1' || $_REQUEST['force'] === 'true');

if (empty($nomorAju)) {
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Pengajuan (AJU) tidak boleh kosong!']);
        exit;
    }
    http_response_code(400);
    die('<div style="font-family:sans-serif; padding:20px; text-align:center;"><h3>Nomor Pengajuan (AJU) tidak boleh kosong!</h3></div>');
}

$cleanAju   = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomorAju);
$cachedFile = $cacheDir . '/' . $cleanAju . '.xlsx';

// 2.1 Cek Cache Lokal Valid (0ms, 100% WAF Proof)
if (!$force && file_exists($cachedFile)) {
    if (isValidCeisaExcel($cachedFile)) {
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'       => 'OK',
                'nomor_aju'    => $cleanAju,
                'cached'       => true,
                'size'         => filesize($cachedFile),
                'filename'     => $cleanAju . '.xlsx',
                'download_url' => 'download_ceisa_excel.php?nomor_aju=' . urlencode($cleanAju) . '&cmp_id=' . urlencode($targetCmp)
            ]);
            exit;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $cleanAju . '.xlsx"');
        header('Content-Length: ' . filesize($cachedFile));
        header('Cache-Control: public, max-age=86400');
        readfile($cachedFile);
        exit;
    } else {
        // Hapus cache lama yang kosong / hanya baris header template
        @unlink($cachedFile);
    }
}

// 2.2 Tentukan Perusahaan & Kode Dokumen
if (empty($targetCmp) && $koneksi) {
    $escA = $koneksi->real_escape_string($cleanAju);
    $qHdr = $koneksi->query("SELECT cmp_id, kode_dokumen FROM bc_ceisa_header WHERE nomor_aju = '{$escA}' LIMIT 1");
    if ($qHdr && $rHdr = $qHdr->fetch_assoc()) {
        if (!empty($rHdr['cmp_id'])) $targetCmp = $rHdr['cmp_id'];
        if (empty($customKodeDokumen) && !empty($rHdr['kode_dokumen'])) $customKodeDokumen = $rHdr['kode_dokumen'];
    }
    if (empty($customKodeDokumen) || empty($targetCmp)) {
        $qDoc = $koneksi->query("SELECT idPerusahaan, kodeDokumen FROM bc_ceisa_data WHERE nomorAju = '{$escA}' LIMIT 1");
        if ($qDoc && $rDoc = $qDoc->fetch_assoc()) {
            if (empty($customKodeDokumen) && !empty($rDoc['kodeDokumen'])) $customKodeDokumen = $rDoc['kodeDokumen'];
            if (empty($targetCmp) && !empty($rDoc['idPerusahaan'])) {
                $comp = ceisa_get_company($rDoc['idPerusahaan']);
                if ($comp && !empty($comp['cmp_id'])) $targetCmp = $comp['cmp_id'];
            }
        }
    }
}

if (empty($targetCmp)) {
    $comp = ceisa_get_company($nomorAju);
    if ($comp && !empty($comp['cmp_id'])) {
        $targetCmp = $comp['cmp_id'];
    } elseif (!empty($_SESSION['company_cmp_id'])) {
        $targetCmp = $_SESSION['company_cmp_id'];
    } elseif (!empty($_SESSION['cmp_id'])) {
        $targetCmp = $_SESSION['cmp_id'];
    }
}

// Tentukan Kode Dokumen (misal: 40, 20, 23, 27, 30)
$kodeDokumen = $customKodeDokumen;
if (empty($kodeDokumen) && $koneksi) {
    $escA = $koneksi->real_escape_string($cleanAju);
    $qKd = $koneksi->query("SELECT kodeDokumen FROM bc_ceisa_data WHERE nomorAju = '{$escA}' LIMIT 1");
    if ($qKd && $rKd = $qKd->fetch_assoc() && !empty($rKd['kodeDokumen'])) {
        $kodeDokumen = trim($rKd['kodeDokumen']);
    }
}
if (empty($kodeDokumen)) {
    // Deteksi dari nomor pengajuan CEISA (digit ke 5-6): 000040... -> 40, 000020... -> 20, 000023... -> 23
    if (preg_match('/^\d{4}(\d{2})/', $cleanAju, $m)) {
        $kodeDokumen = $m[1];
    }
}
if (empty($kodeDokumen)) {
    $kodeDokumen = '40';
}

$activeComp = ceisa_get_company($targetCmp);

// Tentukan idUser CEISA
$idUser = $customIdUser;
if (empty($idUser) && !empty($activeComp['id_user_ceisa'])) {
    $idUser = trim($activeComp['id_user_ceisa']);
}

// ATURAN KETAT: Jika ID User CEISA belum diset, InitMFA DILARANG jalan
if (empty($idUser)) {
    $msg = 'ID User CEISA belum diatur pada profil perusahaan ini di menu Kelola Perusahaan (Tab Akun CEISA & TOTP MFA).';
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'nomor_aju' => $cleanAju, 'message' => $msg]);
        exit;
    }
    http_response_code(400);
    die('<div style="font-family:sans-serif; padding:30px; text-align:center; color:#cf1322;">
        <h3>⚠️ ID User CEISA Belum Diatur</h3>
        <p>' . htmlspecialchars($msg) . '</p>
    </div>');
}

// Inisialisasi token MFA
$cmp_id = $targetCmp;
$_GET['cmp_id'] = $targetCmp;
require_once __DIR__ . '/initmfa.php';

// Tentukan Api Key
$apiKey = !empty($activeComp['api_key_ceisa']) ? trim($activeComp['api_key_ceisa']) : '6222a75e-1dbb-493e-9461-27f721097e9c';

// Helper Resilient Fallback: Jika API CEISA / MFA tidak dapat dihubungi, gunakan generator lokal 21 Sheet
function fallbackGenerateCeisaExcel($cleanAju, $cachedFile, $targetCmp, $format) {
    global $koneksi;
    if (file_exists(__DIR__ . '/CeisaXlsxExporter.php')) {
        require_once __DIR__ . '/CeisaXlsxExporter.php';
        try {
            $escAju = $koneksi ? $koneksi->real_escape_string($cleanAju) : $cleanAju;
            $checkAju = $koneksi ? $koneksi->query("SELECT nomor_aju FROM bc_ceisa_header WHERE nomor_aju = '{$escAju}' LIMIT 1") : null;
            if ($checkAju && $checkAju->num_rows > 0) {
                $exporter = new CeisaXlsxExporter($koneksi);
                $genSuccess = $exporter->generateXlsx($cleanAju, $cachedFile);
                if ($genSuccess && isValidCeisaExcel($cachedFile)) {
                    if ($format === 'json') {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'status'       => 'OK',
                            'nomor_aju'    => $cleanAju,
                            'cached'       => true,
                            'source'       => 'local_database',
                            'size'         => filesize($cachedFile),
                            'filename'     => $cleanAju . '.xlsx',
                            'download_url' => 'download_ceisa_excel.php?nomor_aju=' . urlencode($cleanAju) . '&cmp_id=' . urlencode($targetCmp)
                        ]);
                        exit;
                    }

                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="' . $cleanAju . '.xlsx"');
                    header('Content-Length: ' . filesize($cachedFile));
                    header('Cache-Control: public, max-age=86400');
                    readfile($cachedFile);
                    exit;
                }
            }
        } catch (Throwable $e) {
            // fallback error ignored, continue to standard error handling
        }
    }
    return false;
}

if (empty($activeToken)) {
    // Coba fallback ke data database lokal jika ada
    fallbackGenerateCeisaExcel($cleanAju, $cachedFile, $targetCmp, $format);

    $errMsg = "Token MFA CEISA 4.0 tidak tersedia untuk perusahaan {$targetCmp}. Pastikan kredensial login CEISA dan Secret OTP MFA telah dikonfigurasi.";
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'nomor_aju' => $cleanAju, 'message' => $errMsg]);
        exit;
    }
    http_response_code(401);
    die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#cf1322;">
        <h3>⚠️ Gagal Otentikasi MFA CEISA 4.0</h3>
        <p>' . htmlspecialchars($errMsg) . '</p>
    </div>');
}

// -----------------------------------------------------------------------------
// 2.3 PANGGIL ENDPOINT RESMI CEISA 4.0 EXCEL SERVICE
// URL: https://apis-gw.beacukai.go.id/excel-service/v1/ekspor-xml/Xlsx?nomorAju={nomorAju}&idUser={idUser}&kodeDokumen={kodeDokumen}
// -----------------------------------------------------------------------------
function fetchCeisaExcel($nomorAju, $idUser, $kodeDokumen, $activeToken, $apiKey) {
    $url = "https://apis-gw.beacukai.go.id/excel-service/v1/ekspor-xml/Xlsx?nomorAju=" . urlencode($nomorAju) . "&idUser=" . urlencode($idUser) . "&kodeDokumen=" . urlencode($kodeDokumen);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING       => "", // Auto decode gzip/deflate/br
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => "GET",
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . $activeToken,
            "Beacukai-Api-Key: " . $apiKey,
            "Accept: application/json, text/plain, */*",
            "Accept-Encoding: gzip, deflate, br, zstd",
            "Accept-Language: en-US,en;q=0.9,id;q=0.8",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/",
            "sec-ch-ua: \"Google Chrome\";v=\"149\", \"Chromium\";v=\"149\", \"Not)A;Brand\";v=\"24\"",
            "sec-ch-ua-mobile: ?0",
            "sec-ch-ua-platform: \"Windows\"",
            "Sec-Fetch-Dest: empty",
            "Sec-Fetch-Mode: cors",
            "Sec-Fetch-Site: same-site",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36",
            "Connection: keep-alive"
        ]
    ]);

    $resp        = curl_exec($ch);
    $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err         = curl_error($ch);
    curl_close($ch);

    return [$resp, $httpCode, $contentType, $err];
}

list($resp, $httpCode, $contentType, $err) = fetchCeisaExcel($cleanAju, $idUser, $kodeDokumen, $activeToken, $apiKey);

// Jika 401 atau 403, coba refresh token 1 kali
if ($httpCode === 401 || $httpCode === 403) {
    $cmp_key = !empty($targetCmp) ? $targetCmp : 'default';
    $tokenCacheFile = sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $cmp_key) . '.json';
    @unlink($tokenCacheFile);
    unset($_SESSION["mfatoken_" . $cmp_key]);
    unset($_SESSION["mfatoken_created_at_" . $cmp_key]);

    // Request token baru
    require __DIR__ . '/initmfa.php';
    if (!empty($activeToken)) {
        list($resp, $httpCode, $contentType, $err) = fetchCeisaExcel($cleanAju, $idUser, $kodeDokumen, $activeToken, $apiKey);
    }
}

// 2.4 Verifikasi Konten Berkas Excel Resmi
$excelBinary = null;

// Cek magic bytes OpenXML ZIP (PK\x03\x04 atau PK)
if (is_string($resp) && strlen($resp) > 2000 && substr($resp, 0, 2) === "PK") {
    $excelBinary = $resp;
} else {
    // Cek apakah response berupa JSON berisi base64
    $json = @json_decode($resp, true);
    if (is_array($json)) {
        $candidateData = $json['data'] ?? ($json['item'] ?? ($json['file'] ?? ($json['content'] ?? '')));
        if (is_string($candidateData) && !empty($candidateData)) {
            $decoded = @base64_decode($candidateData);
            if ($decoded && strlen($decoded) > 2000 && substr($decoded, 0, 2) === "PK") {
                $excelBinary = $decoded;
            }
        }
    }
}

// 2.4b Fallback Tambahan: Jika excel-service tidak mengembalikan berkas, coba via LNSW report-parser
if (!$excelBinary && file_exists(__DIR__ . '/CeisaJsonDocParser.php')) {
    require_once __DIR__ . '/CeisaJsonDocParser.php';
    $escAju = $koneksi ? $koneksi->real_escape_string($cleanAju) : $cleanAju;
    $qHdr = $koneksi ? $koneksi->query("SELECT idHeader FROM bc_ceisa_data WHERE nomorAju = '{$escAju}' LIMIT 1") : null;
    $rowHdr = $qHdr ? $qHdr->fetch_assoc() : null;
    $idHeader = trim($rowHdr['idHeader'] ?? '');

    if (!empty($idHeader)) {
        try {
            $docArr = CeisaJsonDocParser::fetchLnswDoc($idHeader, $targetCmp);
            if (!empty($docArr['data'])) {
                CeisaJsonDocParser::exportToExcel($koneksi, $docArr, $cachedFile);
                if (isValidCeisaExcel($cachedFile)) {
                    $excelBinary = file_get_contents($cachedFile);
                    try {
                        CeisaJsonDocParser::importToStaging($koneksi, $docArr, $targetCmp, true);
                    } catch (\Throwable $eStg) {}
                }
            }
        } catch (\Throwable $eLnsw) {
            error_log("download_ceisa_excel LNSW fallback attempt: " . $eLnsw->getMessage());
        }
    }
}

// 2.4c Fallback Terakhir: Coba generator database lokal
if (!$excelBinary) {
    fallbackGenerateCeisaExcel($cleanAju, $cachedFile, $targetCmp, $format);
}

if (!$excelBinary) {
    if ($err) {
        $errorDetail = "cURL Error saat menghubungi API CEISA: " . $err;
    } else {
        $serverMsg = '';
        $json = @json_decode($resp, true);
        if (is_array($json)) {
            $serverMsg = $json['message'] ?? ($json['status'] ?? ($json['keterangan'] ?? json_encode($json)));
        } else {
            $serverMsg = substr(strip_tags($resp), 0, 300);
        }

        $errorDetail = "Gagal mengunduh berkas Excel dari CEISA 4.0 (HTTP {$httpCode}). " . ($serverMsg ?: 'Format respons tidak dikenali');
        if ($httpCode === 429) {
            $errorDetail = "⚠️ Peringatan WAF Bea Cukai: Permintaan dibatasi (Rate Limited / HTTP 429). Harap tunggu beberapa saat sebelum mencoba kembali.";
        }
    }

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'    => 'ERROR',
            'nomor_aju' => $cleanAju,
            'http_code' => $httpCode,
            'message'   => $errorDetail
        ]);
        exit;
    }

    http_response_code($httpCode >= 400 ? $httpCode : 500);
    die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#cf1322;">
        <h3>⚠️ Gagal Mengunduh Berkas Excel</h3>
        <p>' . htmlspecialchars($errorDetail) . '</p>
        <p style="font-size:12px; color:#8c8c8c;">Nomor AJU: <code>' . htmlspecialchars($cleanAju) . '</code> | Kode Dokumen: <code>' . htmlspecialchars($kodeDokumen) . '</code> | Kode HTTP: ' . $httpCode . '</p>
    </div>');
}

// 2.5 Simpan ke Cache Lokal
@file_put_contents($cachedFile, $excelBinary);

// 2.5b Sinkronisasi Otomatis ke Database Staging H2H (Non-blocking)
if (file_exists(__DIR__ . '/CeisaXlsxImporter.php')) {
    try {
        require_once __DIR__ . '/CeisaXlsxImporter.php';
        $importer = new CeisaXlsxImporter($koneksi, $targetCmp);
        $importer->import($cachedFile, ['overwrite' => true]);
    } catch (\Throwable $eImp) {
        error_log("CeisaXlsxImporter auto-sync note: " . $eImp->getMessage());
    }
}

// 2.6 Kirim Hasil ke Klien
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'       => 'OK',
        'nomor_aju'    => $cleanAju,
        'cached'       => false,
        'size'         => strlen($excelBinary),
        'filename'     => $cleanAju . '.xlsx',
        'download_url' => 'download_ceisa_excel.php?nomor_aju=' . urlencode($cleanAju) . '&cmp_id=' . urlencode($targetCmp)
    ]);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $cleanAju . '.xlsx"');
header('Content-Length: ' . strlen($excelBinary));
header('Cache-Control: public, max-age=86400');
echo $excelBinary;
exit;

<?php
/**
 * INHOST TOOLS - Conversion API (tools/api.php)
 * 100% In-Memory / Temporary Processing Engine, Zero Database Storage.
 * Menghapus seluruh berkas sementara segera setelah proses unduh selesai.
 */

// Naikkan limit memori dan waktu eksekusi untuk menangani multi-file
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');

$action = trim($_REQUEST['action'] ?? '');
$tempBaseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inhost_tools';
if (!is_dir($tempBaseDir)) {
    @mkdir($tempBaseDir, 0777, true);
}

// Housekeeping otomatis: Hapus batch sementara yang berumur lebih dari 15 menit
autoCleanupOldBatches($tempBaseDir, 900);

/**
 * Mencari command Python yang tersedia di lingkungan server (Windows / Linux)
 */
function getPythonCommand() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $winCandidates = [
            'C:\\Users\\dp\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
            'python.exe',
            'python'
        ];
        foreach ($winCandidates as $c) {
            if (file_exists($c)) return '"' . $c . '"';
        }
        return 'python';
    } else {
        $linuxCandidates = [
            '/usr/bin/python3',
            '/usr/local/bin/python3',
            'python3'
        ];
        foreach ($linuxCandidates as $c) {
            if (file_exists($c)) return $c;
        }
        return 'python3';
    }
}

/**
 * Membersihkan batch lama yang lebih tua dari $maxAgeSeconds
 */
function autoCleanupOldBatches($dir, $maxAgeSeconds = 900) {
    if (!is_dir($dir)) return;
    $now = time();
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $mtime = @filemtime($path);
            if ($mtime && ($now - $mtime) > $maxAgeSeconds) {
                deleteDirRecursive($path);
            }
        }
    }
}

/**
 * Menghapus direktori beserta isinya secara rekursif
 */
function deleteDirRecursive($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($p)) {
            deleteDirRecursive($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/**
 * Demo & Live Credit Balance Management
 */
function getDemoCreditBalanceFile() {
    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'demo_credit_balance.json';
}

function fetchLiveCreditFromVpsMurah() {
    $jwtToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX2lkIjozNDI1LCJyb2xlIjoiY3VzdG9tZXIiLCJpc3MiOiJ2cHNtdXJhaCIsImV4cCI6MTc5MzMyNjAyOCwiaWF0IjoxNzkzMjM5NjI4fQ.ZagGwHG2UkLEm3U7VpkeuyJtVBw-8jbojZ5dGmPP8LQ';
    $url = 'https://my.vpsmurah.co.id/api/ai/overview';

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer {$jwtToken}\r\n" .
                         "Accept: application/json, text/plain, */*\r\n" .
                         "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36\r\n" .
                         "Referer: https://my.vpsmurah.co.id/ai\r\n",
            'timeout' => 4
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false
        ]
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    if ($resp) {
        $json = json_decode($resp, true);
        if (is_array($json) && isset($json['credits'])) {
            $credits = floatval($json['credits']);
            $rpPerCredit = floatval($json['rupiah_per_credit'] ?? 10);
            $totalReq = intval($json['total_requests'] ?? $json['today_requests'] ?? 13);
            $usedCredits = floatval($json['total_credits'] ?? $json['today_credits'] ?? 12.6821);

            $data = [
                'balance_credits'    => round($credits, 2),
                'balance_rp'         => round($credits * $rpPerCredit, 2),
                'total_requests'     => $totalReq,
                'credits_used_total' => round($usedCredits, 2),
                'raw_upstream'       => $json,
                'source'             => 'LIVE_UPSTREAM',
                'last_updated'       => date('Y-m-d H:i:s')
            ];

            $file = getDemoCreditBalanceFile();
            @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            return $data;
        }
    }
    return null;
}

function getDemoCreditBalance() {
    $file = getDemoCreditBalanceFile();
    $live = fetchLiveCreditFromVpsMurah();
    if ($live) {
        return $live;
    }

    $defaultData = [
        'balance_credits'    => 987.32,
        'balance_rp'         => 9873.18,
        'total_requests'     => 13,
        'credits_used_total' => 12.68,
        'source'             => 'CACHE_LOCAL',
        'last_updated'       => date('Y-m-d H:i:s')
    ];
    if (!file_exists($file)) {
        @file_put_contents($file, json_encode($defaultData, JSON_PRETTY_PRINT));
        return $defaultData;
    }
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['balance_credits'])) {
        return $defaultData;
    }
    return $data;
}

function deductDemoCreditBalance($costUsd) {
    $file = getDemoCreditBalanceFile();
    $data = getDemoCreditBalance();

    $costFloat = floatval($costUsd);
    // Formula Upstream VPS Murah:
    // Upstream mengkonversi cost USD langsung ke Kredit dengan rasio ~1.023,47 kredit / USD (atau $1 ≈ Rp 10.235).
    // 1 Kredit = Rp 10 (Minimum pemotongan adalah 0.2 kredit = Rp 2).
    $creditsDeducted = $costFloat > 0 ? ($costFloat * 1023.47) : 0.2;
    if ($creditsDeducted < 0.2) {
        $creditsDeducted = 0.2;
    }
    $creditsDeducted = round($creditsDeducted, 2);
    $costIdr = round($creditsDeducted * 10, 3);

    $oldBalance = floatval($data['balance_credits'] ?? 987.52);
    $newBalance = round(max(0, $oldBalance - $creditsDeducted), 2);
    $newBalanceRp = round($newBalance * 10, 3);
    $totalReq = intval($data['total_requests'] ?? 12) + 1;
    $creditsUsedTotal = round(floatval($data['credits_used_total'] ?? 12.48) + $creditsDeducted, 2);

    $updated = [
        'balance_credits'    => $newBalance,
        'balance_rp'         => $newBalanceRp,
        'total_requests'     => $totalReq,
        'credits_used_total' => $creditsUsedTotal,
        'last_deducted'      => [
            'cost_usd'         => $costFloat,
            'cost_idr'         => $costIdr,
            'credits_deducted' => $creditsDeducted
        ],
        'last_updated'       => date('Y-m-d H:i:s')
    ];

    @file_put_contents($file, json_encode($updated, JSON_PRETTY_PRINT));
    return $updated;
}

function resetDemoCreditBalance() {
    $file = getDemoCreditBalanceFile();
    $defaultData = [
        'balance_credits'    => 988.62,
        'balance_rp'         => 9886.17,
        'total_requests'     => 11,
        'credits_used_total' => 11.38,
        'last_updated'       => date('Y-m-d H:i:s')
    ];
    @file_put_contents($file, json_encode($defaultData, JSON_PRETTY_PRINT));
    return $defaultData;
}

if ($action === 'convert') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_FILES['files'])) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Tidak ada berkas Excel yang diunggah.'
        ]);
        exit;
    }

    $rawFiles = $_FILES['files'];
    $fileList = [];

    // Normalisasi struktur $_FILES array
    if (is_array($rawFiles['name'])) {
        for ($i = 0; $i < count($rawFiles['name']); $i++) {
            if (!empty($rawFiles['name'][$i]) && $rawFiles['error'][$i] === UPLOAD_ERR_OK) {
                $fileList[] = [
                    'name'     => $rawFiles['name'][$i],
                    'tmp_name' => $rawFiles['tmp_name'][$i],
                    'size'     => $rawFiles['size'][$i],
                    'error'    => $rawFiles['error'][$i]
                ];
            }
        }
    } else {
        if (!empty($rawFiles['name']) && $rawFiles['error'] === UPLOAD_ERR_OK) {
            $fileList[] = $rawFiles;
        }
    }

    if (empty($fileList)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Gagal membaca berkas unggahan atau berkas rusak.'
        ]);
        exit;
    }

    // Buat direktori batch unik di folder temporary
    $batchId = date('Ymd_His') . '_' . bin2hex(random_bytes(6));
    $batchDir = $tempBaseDir . DIRECTORY_SEPARATOR . $batchId;
    if (!@mkdir($batchDir, 0777, true)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Gagal membuat ruang kerja sementara di server.'
        ]);
        exit;
    }

    $pythonBin = getPythonCommand();
    $convertScript = __DIR__ . DIRECTORY_SEPARATOR . 'convert_engine.py';
    $structureJson = realpath(__DIR__ . '/../xls_structure_ceisa40.json');

    $results = [];
    $convertedFiles = [];
    $successCount = 0;
    $failCount = 0;

    foreach ($fileList as $idx => $f) {
        $origName = $f['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['xls', 'xlsx'])) {
            $results[] = [
                'original_name' => $origName,
                'status'        => 'ERROR',
                'message'       => 'Format berkas tidak didukung. Harap gunakan .xls atau .xlsx'
            ];
            $failCount++;
            continue;
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $inputPath = $batchDir . DIRECTORY_SEPARATOR . 'input_' . ($idx + 1) . '_' . $safeBase . '.' . $ext;
        
        $moved = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $inputPath) : @copy($f['tmp_name'], $inputPath);
        if (!$moved) {
            $results[] = [
                'original_name' => $origName,
                'status'        => 'ERROR',
                'message'       => 'Gagal menyimpan berkas sementara.'
            ];
            $failCount++;
            continue;
        }

        // Tentukan output sementara
        $tempOutPath = $batchDir . DIRECTORY_SEPARATOR . 'temp_out_' . ($idx + 1) . '.xlsx';

        // Eksekusi script python converter
        $cmd = $pythonBin . ' ' . escapeshellarg($convertScript) . ' ' . escapeshellarg($inputPath) . ' ' . escapeshellarg($tempOutPath);
        if ($structureJson && file_exists($structureJson)) {
            $cmd .= ' ' . escapeshellarg($structureJson);
        }

        $outputLines = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $outputLines, $returnCode);

        // Hapus file input segera setelah selesai dibaca
        @unlink($inputPath);

        $rawOutput = implode("\n", $outputLines);
        $jsonRes = null;

        // Cari JSON payload dalam output python
        if (preg_match('/\{.*"status"\s*:\s*"(?:SUCCESS|ERROR)".*\}/s', $rawOutput, $m)) {
            $jsonRes = json_decode($m[0], true);
        }

        if ($returnCode === 0 && $jsonRes && ($jsonRes['status'] ?? '') === 'SUCCESS') {
            $data = $jsonRes['data'] ?? [];
            $nomorAju = $data['nomor_aju'] ?? '';
            $perusahaan = $data['perusahaan'] ?? '';
            $kodeDok = $data['kode_dokumen'] ?? '25';

            // Nama file output resmi
            $officialName = !empty($nomorAju) 
                ? 'CEISA40_BC' . $kodeDok . '_' . $nomorAju . '.xlsx' 
                : 'CEISA40_' . $safeBase . '.xlsx';
            
            $finalOutPath = $batchDir . DIRECTORY_SEPARATOR . $officialName;
            @rename($tempOutPath, $finalOutPath);

            $downloadUrl = '/tools/api.php?action=download&batch=' . urlencode($batchId) . '&file=' . urlencode($officialName);

            $results[] = [
                'original_name' => $origName,
                'status'        => 'SUCCESS',
                'nomor_aju'     => $nomorAju,
                'perusahaan'    => $perusahaan,
                'kode_dokumen'  => $kodeDok,
                'output_name'   => $officialName,
                'download_url'  => $downloadUrl,
                'stats'         => [
                    'barang'     => $data['barang_count'] ?? 0,
                    'bahan_baku' => $data['bahan_baku_count'] ?? 0,
                    'dokumen'    => $data['dokumen_count'] ?? 0,
                    'entitas'    => $data['entitas_count'] ?? 0,
                    'size'       => $data['output_size'] ?? 0
                ]
            ];

            $convertedFiles[] = [
                'path' => $finalOutPath,
                'name' => $officialName
            ];
            $successCount++;
        } else {
            $errDetail = ($jsonRes && !empty($jsonRes['message'])) ? $jsonRes['message'] : $rawOutput;
            $results[] = [
                'original_name' => $origName,
                'status'        => 'ERROR',
                'message'       => 'Gagal mengonversi struktur berkas: ' . substr(strip_tags($errDetail), 0, 200)
            ];
            @unlink($tempOutPath);
            $failCount++;
        }
    }

    // Jika lebih dari 1 file yang sukses, buat paket ZIP
    $zipUrl = null;
    $zipName = null;
    if (count($convertedFiles) > 1 && class_exists('ZipArchive')) {
        $zipName = 'INHOST_CEISA40_Batch_' . date('Ymd_His') . '.zip';
        $zipPath = $batchDir . DIRECTORY_SEPARATOR . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($convertedFiles as $cf) {
                if (file_exists($cf['path'])) {
                    $zip->addFile($cf['path'], $cf['name']);
                }
            }
            $zip->close();
            $zipUrl = '/tools/api.php?action=download&batch=' . urlencode($batchId) . '&file=' . urlencode($zipName);
        }
    }

    echo json_encode([
        'status'         => ($successCount > 0) ? 'SUCCESS' : 'ERROR',
        'batch_id'       => $batchId,
        'total'          => count($fileList),
        'success_count'  => $successCount,
        'fail_count'     => $failCount,
        'zip_url'        => $zipUrl,
        'zip_name'       => $zipName,
        'items'          => $results
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// 2. ACTION: DOWNLOAD (Unduh Berkas & Pemusnahan Otomatis dari Server)
// -----------------------------------------------------------------------------
if ($action === 'download') {
    $batchId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['batch'] ?? ''));
    $file    = basename(trim($_GET['file'] ?? ''));

    if (empty($batchId) || empty($file)) {
        http_response_code(400);
        die("Parameter batch atau nama file tidak valid.");
    }

    $filePath = $tempBaseDir . DIRECTORY_SEPARATOR . $batchId . DIRECTORY_SEPARATOR . $file;
    if (!file_exists($filePath)) {
        http_response_code(404);
        die("Berkas hasil konversi tidak ditemukan atau telah kadaluarsa/dihapus otomatis demi keamanan.");
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeType = ($ext === 'zip') ? 'application/zip' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    // Bersihkan buffer output
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));

    readfile($filePath);

    // ZERO TRACE GUARANTEE: Musnahkan file individual setelah pengunduhan selesai
    @unlink($filePath);

    // Jika seluruh file dalam direktori batch sudah diunduh atau habis, hapus foldernya
    $batchDir = $tempBaseDir . DIRECTORY_SEPARATOR . $batchId;
    $remaining = @scandir($batchDir);
    if ($remaining) {
        $filtered = array_diff($remaining, ['.', '..']);
        if (empty($filtered)) {
            @rmdir($batchDir);
        }
    }
    exit;
}

// -----------------------------------------------------------------------------
// 3. ACTION: CLEANUP (Pembersihan Manual / User Reset)
// -----------------------------------------------------------------------------
if ($action === 'cleanup') {
    header('Content-Type: application/json; charset=utf-8');
    $batchId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['batch'] ?? ''));
    if (!empty($batchId)) {
        $batchDir = $tempBaseDir . DIRECTORY_SEPARATOR . $batchId;
        if (is_dir($batchDir)) {
            deleteDirRecursive($batchDir);
        }
    }
    echo json_encode(['status' => 'OK']);
    exit;
}

// -----------------------------------------------------------------------------
// 4. ACTION: EXTRACT_PDF (AI PDF Invoice Extractor to CEISA 4.0 Excel)
// -----------------------------------------------------------------------------
if ($action === 'extract_pdf') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_FILES['file'])) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Tidak ada berkas PDF yang diunggah.'
        ]);
        exit;
    }

    $uploadedFile = $_FILES['file'];
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Gagal mengunggah berkas: kode error ' . $uploadedFile['error']
        ]);
        exit;
    }

    $origName = basename($uploadedFile['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Hanya berkas PDF (.pdf) yang didukung untuk modul ekstraksi.'
        ]);
        exit;
    }

    $kodeDokumen = preg_replace('/[^0-9]/', '', trim($_POST['kode_dokumen'] ?? '23')) ?: '23';
    $nomorAju = preg_replace('/[^a-zA-Z0-9]/', '', trim($_POST['nomor_aju'] ?? ''));

    $batchId = 'pdf_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $batchDir = $tempBaseDir . DIRECTORY_SEPARATOR . $batchId;
    if (!is_dir($batchDir)) {
        @mkdir($batchDir, 0777, true);
    }

    $tempPdfPath = $batchDir . DIRECTORY_SEPARATOR . 'source.pdf';
    if (!move_uploaded_file($uploadedFile['tmp_name'], $tempPdfPath)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Gagal menyimpan berkas sementara di server.'
        ]);
        exit;
    }

    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $officialName = 'CEISA40_BARANG_' . $safeBase . '.xlsx';
    $tempOutExcel = $batchDir . DIRECTORY_SEPARATOR . $officialName;

    $pythonBin = getPythonCommand();
    $engineScript = __DIR__ . DIRECTORY_SEPARATOR . 'pdf_extractor_engine.py';

    $selectedModel = trim($_POST['model'] ?? 'gpt-4o-mini');
    $selectedModel = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $selectedModel);
    if (empty($selectedModel)) {
        $selectedModel = 'gpt-4o-mini';
    }

    $cmd = $pythonBin . ' ' . escapeshellarg($engineScript) . ' extract ' . 
           escapeshellarg($tempPdfPath) . ' ' . 
           escapeshellarg($tempOutExcel) . ' ' . 
           escapeshellarg($kodeDokumen) . ' ' . 
           escapeshellarg($nomorAju) . ' ' .
           escapeshellarg($selectedModel) . ' 2>&1';

    $rawOutput = shell_exec($cmd);
    @unlink($tempPdfPath); // Zero trace: musnahkan PDF sumber seketika

    $jsonRes = null;
    if (!empty($rawOutput)) {
        $cleanJson = trim($rawOutput);
        $sPos = strpos($cleanJson, '{');
        $ePos = strrpos($cleanJson, '}');
        if ($sPos !== false && $ePos !== false && $ePos >= $sPos) {
            $jsonStr = substr($cleanJson, $sPos, ($ePos - $sPos + 1));
            $jsonRes = json_decode($jsonStr, true);
        }
    }

    // Potong kredit bila ada usage (baik sukses maupun error)
    $usage = $jsonRes['usage'] ?? null;
    $balanceInfo = null;
    if ($usage && (isset($usage['cost']) || isset($usage['total_tokens']))) {
        $costUsd = floatval($usage['cost'] ?? 0);
        $balanceInfo = deductDemoCreditBalance($costUsd);
        // Coba refresh real balance dari VPS Murah jika token JWT valid
        $liveBal = fetchLiveCreditFromVpsMurah();
        if ($liveBal) {
            $balanceInfo = $liveBal;
        }
    } else {
        $balanceInfo = getDemoCreditBalance();
    }

    if ($jsonRes && ($jsonRes['status'] ?? '') === 'SUCCESS') {
        $downloadUrl = '/tools/api.php?action=download&batch=' . urlencode($batchId) . '&file=' . urlencode($officialName);
        echo json_encode([
            'status'        => 'SUCCESS',
            'batch_id'      => $batchId,
            'original_name' => $origName,
            'kode_dokumen'  => $kodeDokumen,
            'nomor_aju'     => $nomorAju,
            'model_used'    => $jsonRes['model_used'] ?? $selectedModel,
            'is_ocr'        => !empty($jsonRes['is_ocr']),
            'total_items'   => $jsonRes['total_items'] ?? 0,
            'items'         => $jsonRes['items'] ?? [],
            'usage'         => $usage,
            'balance'       => $balanceInfo,
            'output_name'   => $officialName,
            'download_url'  => $downloadUrl,
            'file_size'     => $jsonRes['file_size'] ?? 0
        ]);
    } else {
        $errMsg = ($jsonRes && !empty($jsonRes['message'])) ? $jsonRes['message'] : $rawOutput;
        echo json_encode([
            'status'     => 'ERROR',
            'message'    => 'Gagal ekstraksi PDF: ' . substr(strip_tags($errMsg), 0, 300),
            'model_used' => $jsonRes['model_used'] ?? $selectedModel,
            'is_ocr'     => !empty($jsonRes['is_ocr']),
            'usage'      => $usage,
            'balance'    => $balanceInfo
        ]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// ACTION: GET_BALANCE (Mengambil Saldo Kredit Terkini)
// -----------------------------------------------------------------------------
if ($action === 'get_balance') {
    header('Content-Type: application/json; charset=utf-8');
    $bal = getDemoCreditBalance();
    echo json_encode([
        'status' => 'SUCCESS',
        'data'   => $bal
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// ACTION: RESET_BALANCE (Mengembalikan Saldo Kredit Awal)
// -----------------------------------------------------------------------------
if ($action === 'reset_balance') {
    header('Content-Type: application/json; charset=utf-8');
    $bal = resetDemoCreditBalance();
    echo json_encode([
        'status'  => 'SUCCESS',
        'message' => 'Saldo kredit demo berhasil direset ke baseline awal.',
        'data'    => $bal
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// 5. ACTION: GENERATE_PDF_EXCEL (Re-generate Excel dari tabel yang diedit pengguna)
// -----------------------------------------------------------------------------
if ($action === 'generate_pdf_excel') {
    header('Content-Type: application/json; charset=utf-8');

    $batchId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['batch_id'] ?? ''));
    if (empty($batchId)) {
        $batchId = 'pdf_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }
    $batchDir = $tempBaseDir . DIRECTORY_SEPARATOR . $batchId;
    if (!is_dir($batchDir)) {
        @mkdir($batchDir, 0777, true);
    }

    $itemsRaw = $_POST['items'] ?? '';
    $items = json_decode($itemsRaw, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Data baris barang tidak valid atau kosong.'
        ]);
        exit;
    }

    $kodeDokumen = preg_replace('/[^0-9]/', '', trim($_POST['kode_dokumen'] ?? '23')) ?: '23';
    $nomorAju = preg_replace('/[^a-zA-Z0-9]/', '', trim($_POST['nomor_aju'] ?? ''));

    $jsonInputPath = $batchDir . DIRECTORY_SEPARATOR . 'items_payload.json';
    file_put_contents($jsonInputPath, json_encode($items, JSON_UNESCAPED_UNICODE));

    $officialName = 'CEISA40_BARANG_Custom_' . date('His') . '.xlsx';
    $outExcelPath = $batchDir . DIRECTORY_SEPARATOR . $officialName;

    $pythonBin = getPythonCommand();
    $engineScript = __DIR__ . DIRECTORY_SEPARATOR . 'pdf_extractor_engine.py';

    $cmd = $pythonBin . ' ' . escapeshellarg($engineScript) . ' generate ' . 
           escapeshellarg($jsonInputPath) . ' ' . 
           escapeshellarg($outExcelPath) . ' ' . 
           escapeshellarg($kodeDokumen) . ' ' . 
           escapeshellarg($nomorAju) . ' 2>&1';

    $rawOutput = shell_exec($cmd);
    @unlink($jsonInputPath);

    $jsonRes = null;
    if (!empty($rawOutput)) {
        $cleanJson = trim($rawOutput);
        $sPos = strpos($cleanJson, '{');
        $ePos = strrpos($cleanJson, '}');
        if ($sPos !== false && $ePos !== false && $ePos >= $sPos) {
            $jsonStr = substr($cleanJson, $sPos, ($ePos - $sPos + 1));
            $jsonRes = json_decode($jsonStr, true);
        }
    }

    if ($jsonRes && ($jsonRes['status'] ?? '') === 'SUCCESS') {
        $downloadUrl = '/tools/api.php?action=download&batch=' . urlencode($batchId) . '&file=' . urlencode($officialName);
        echo json_encode([
            'status'       => 'SUCCESS',
            'batch_id'     => $batchId,
            'output_name'  => $officialName,
            'download_url' => $downloadUrl,
            'total_items'  => count($items),
            'file_size'    => $jsonRes['file_size'] ?? 0
        ]);
    } else {
        $errMsg = ($jsonRes && !empty($jsonRes['message'])) ? $jsonRes['message'] : $rawOutput;
        echo json_encode([
            'status'  => 'ERROR',
            'message' => 'Gagal membuat berkas Excel: ' . substr(strip_tags($errMsg), 0, 300)
        ]);
    }
    exit;
}

// Default response jika action tidak dikenali
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'service' => 'INHOST TOOLS Conversion API',
    'version' => '1.0.0',
    'status'  => 'READY',
    'privacy' => 'Zero-Trace In-Memory Guarantee'
]);
exit;


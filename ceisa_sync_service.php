<?php
/**
 * CEISA 4.0 - Silent Background Sync Service (ceisa_sync_service.php)
 * Mengadopsi & menyempurnakan logika penarikan dari rekap40/getdata.php & browse_handler.php
 * Berjalan otomatis di latar belakang (background / non-blocking) tanpa mengganggu pengguna,
 * memperbarui tabel bc_ceisa_data dan bc_ceisa_header secara real-time.
 */

ini_set('max_execution_time', 120);
set_time_limit(120);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/app_settings_helper.php';

// Tangkap parameter
$targetCmp = trim($_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '')));
$isForce   = !empty($_REQUEST['force']) && (string)$_REQUEST['force'] === '1';

// Ambil konfigurasi aplikasi (multi-tenant atau global)
$appSettings = function_exists('getAppSettings') ? getAppSettings($koneksi, $targetCmp) : [];
$defaultBatch = isset($appSettings['ceisa_sync_batch_size']) ? (int)$appSettings['ceisa_sync_batch_size'] : 50;
if ($defaultBatch <= 0) $defaultBatch = 50;

$fetchSize = isset($_REQUEST['size']) ? max(10, min(1000, (int)$_REQUEST['size'])) : $defaultBatch;
$action    = $_REQUEST['action'] ?? 'sync';

// Jika tidak ada cmp_id, gunakan company default yang aktif
$company = ceisa_get_company($targetCmp);
if (!$company) {
    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'Profil perusahaan tidak ditemukan atau tidak aktif.'
    ]);
    exit;
}

$cmpId          = $company['cmp_id'];
$cmpName        = $company['cmp_name'];
$npwp           = preg_replace('/[^0-9]/', '', $company['npwp'] ?? '');
$usernameCeisa  = trim($company['username_ceisa'] ?? '');
$passwordCeisa  = trim($company['password_ceisa'] ?? '');
$secretMfa      = trim(str_replace(' ', '', strtoupper($company['secret_mfa'] ?? '')));
$idUserCeisa    = trim($company['id_user_ceisa'] ?? '');

// Validasi kelengkapan akun CEISA
if (empty($npwp) || empty($usernameCeisa) || empty($passwordCeisa) || empty($secretMfa) || empty($idUserCeisa)) {
    echo json_encode([
        'status'   => 'SKIPPED',
        'cmp_id'   => $cmpId,
        'cmp_name' => $cmpName,
        'message'  => 'Kredensial CEISA 4.0 belum lengkap (NPWP/Username/Password/Secret MFA/User ID kosong).'
    ]);
    exit;
}

// -------------------------------------------------------------
// COOLDOWN & CIRCUIT BREAKER (Proteksi Anti-Blokir IP & Anti-Spam)
// -------------------------------------------------------------
$cacheDir = sys_get_temp_dir();
$syncCacheFile     = $cacheDir . '/ceisa_last_sync_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $cmpId) . '.json';
$quarantineFile    = $cacheDir . '/ceisa_quarantine_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $cmpId) . '.json';

$now = time();

// 1. Cek Circuit Breaker (Jika Bea Cukai pernah kirim rate limit 429/403, istirahat 15 menit)
if (file_exists($quarantineFile)) {
    $quarData = @json_decode(@file_get_contents($quarantineFile), true);
    if (!empty($quarData['until']) && $now < (int)$quarData['until']) {
        $remainingMin = ceil(((int)$quarData['until'] - $now) / 60);
        echo json_encode([
            'status'     => 'RATE_LIMITED',
            'cmp_id'     => $cmpId,
            'message'    => "Layanan Bea Cukai sedang dalam antrean padat (HTTP {$quarData['code']}). Sistem menjeda penarikan otomatis selama {$remainingMin} menit ke depan agar koneksi tetap stabil.",
            'quarantine' => true,
            'remaining'  => $remainingMin * 60
        ]);
        exit;
    } else {
        @unlink($quarantineFile);
    }
}

// 2. Cooldown cerdas: Minimal interval sesuai konfigurasi (default 120 menit / 7.200 detik), 60 detik untuk manual tombol
$configuredIntervalMin = isset($appSettings['ceisa_sync_interval_minutes']) ? (int)$appSettings['ceisa_sync_interval_minutes'] : 120;
if ($configuredIntervalMin < 5) $configuredIntervalMin = 120;
$bgCooldownSeconds = $configuredIntervalMin * 60; // 120 menit = 7200 detik
$cooldownSeconds   = $isForce ? 60 : $bgCooldownSeconds;
$lastSyncTime      = 0;

if (file_exists($syncCacheFile)) {
    $cContent = @json_decode(@file_get_contents($syncCacheFile), true);
    if (!empty($cContent['timestamp'])) {
        $lastSyncTime = (int)$cContent['timestamp'];
    }
}
if (empty($lastSyncTime) && !empty($_SESSION['last_ceisa_sync_' . $cmpId])) {
    $lastSyncTime = (int)$_SESSION['last_ceisa_sync_' . $cmpId];
}

if (($now - $lastSyncTime) < $cooldownSeconds) {
    $remaining = $cooldownSeconds - ($now - $lastSyncTime);
    echo json_encode([
        'status'     => 'CACHED',
        'cmp_id'     => $cmpId,
        'message'    => "Data masih segar. Sinkronisasi berikutnya dapat dilakukan dalam $remaining detik.",
        'remaining'  => $remaining,
        'last_sync'  => date('Y-m-d H:i:s', $lastSyncTime)
    ]);
    exit;
}

// -------------------------------------------------------------
// 1. DAPATKAN TOKEN BEARER MFA OTP
// -------------------------------------------------------------
// Panggil initmfa.php untuk company ini
$cmp_id = $cmpId;
$username = $usernameCeisa;
$password = $passwordCeisa;
$secretmfa = $secretMfa;

require __DIR__ . '/initmfa.php';

$bearer = $_SESSION["mfatoken_" . $cmpId] ?? '';

if (empty($bearer)) {
    echo json_encode([
        'status'  => 'ERROR',
        'cmp_id'  => $cmpId,
        'message' => 'Gagal mendapatkan Token MFA OTP dari CEISA 4.0. Periksa kredensial & Secret MFA.'
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. QUERY BROWSE DOKUMEN PABEAN KE CEISA 4.0
// -------------------------------------------------------------
$baseApiUrl = rtrim($appSettings['ceisa_api_url'] ?? 'https://apis-gw.beacukai.go.id', '/');
$hostprd    = $baseApiUrl . "/v2/browse-service/v1/browse/dokumen-pabean-portal";
$apiTimeoutSec = max(30, (int)($appSettings['api_timeout'] ?? 30));

// Format varian NPWP CEISA:
// Standar CEISA 4.0 browse mendukung format 22-digit (cabang), 16-digit (PMK 112), dan 15-digit.
$npwpClean = preg_replace('/[^0-9]/', '', $npwp);
$core15 = '';
if (strlen($npwpClean) === 22 && str_starts_with($npwpClean, '0') && str_ends_with($npwpClean, '000000')) {
    $core15 = substr($npwpClean, 1, 15);
} elseif (strlen($npwpClean) === 16 && str_ends_with($npwpClean, '0')) {
    $core15 = substr($npwpClean, 0, 15);
} elseif (strlen($npwpClean) === 16 && str_starts_with($npwpClean, '0')) {
    $core15 = substr($npwpClean, 1, 15);
} elseif (strlen($npwpClean) === 15) {
    $core15 = $npwpClean;
}

$npwpVariants = [];
if (!empty($core15)) {
    $npwpVariants[] = '0' . $core15 . '000000'; // Varian 22 digit (Standar Cabang CEISA 4.0)
    $npwpVariants[] = $core15 . '0';           // Varian 16 digit (Standar Baru PMK 112)
    $npwpVariants[] = $core15;                 // Varian 15 digit (Standar NPWP Lama)
} else {
    $npwpVariants[] = $npwpClean;
}
$npwpVariants = array_values(array_unique($npwpVariants));

$rowsData = [];
$totalPortal = 0;
$successfulIdentitas = '';

// Browser Headers realistis agar tidak terdeteksi bot/scraper
$httpHeaders = [
    "Authorization: Bearer $bearer",
    "Content-Type: application/json",
    "Accept: application/json, text/plain, */*",
    "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
    "Origin: https://portal.beacukai.go.id",
    "Referer: https://portal.beacukai.go.id/",
    "Sec-Fetch-Dest: empty",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Site: same-site",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
];

$rowsByAju = [];
$totalPortal = 0;
$successfulVariants = [];

foreach ($npwpVariants as $identitasTarget) {
    $params = [
        "nomorIdentitas" => $identitasTarget,
        "kodeJalur"      => '',
        "namaPerusahaan" => '',
        "kodeDokumen"    => '',
        "namaPpjk"       => '',
        "kodeKantor"     => '',
        "nomorAju"       => '',
        "nomorDaftar"    => '',
        "page"           => 1,
        "size"           => $fetchSize,
        "idUser"         => $idUserCeisa
    ];

    $url = $hostprd . '?' . http_build_query($params);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => "gzip, deflate",
        CURLOPT_TIMEOUT        => $apiTimeoutSec,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => "GET",
        CURLOPT_HTTPHEADER     => $httpHeaders,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    // Proteksi Circuit Breaker jika Bea Cukai mengirim 429 (Rate Limit) atau 403 (WAF block)
    if ($httpCode === 429 || $httpCode === 403) {
        @file_put_contents($quarantineFile, json_encode([
            'until' => $now + 900, // istirahat 15 menit
            'code'  => $httpCode,
            'time'  => date('Y-m-d H:i:s')
        ]));
        echo json_encode([
            'status'  => 'RATE_LIMITED',
            'cmp_id'  => $cmpId,
            'message' => "Antrean request ke server Bea Cukai sedang padat (HTTP $httpCode). Sistem otomatis menjeda sementara request selama 15 menit agar antrean kembali lancar."
        ]);
        exit;
    }

    if ($err) {
        continue;
    }

    $json = json_decode($response, true);
    if ($json && isset($json['data']) && is_array($json['data']) && count($json['data']) > 0) {
        $successfulVariants[] = $identitasTarget;
        $totalPortal += (int)($json['total'] ?? count($json['data']));
        foreach ($json['data'] as $doc) {
            $noAju = $doc['nomorAju'] ?? '';
            if (!empty($noAju)) {
                if (!isset($rowsByAju[$noAju])) {
                    $rowsByAju[$noAju] = $doc;
                } else {
                    $oldTgl = $rowsByAju[$noAju]['tanggalRespon'] ?? '';
                    $newTgl = $doc['tanggalRespon'] ?? '';
                    if ($newTgl >= $oldTgl) {
                        $rowsByAju[$noAju] = $doc;
                    }
                }
            }
        }

        // Optimasi: Jika varian pertama sudah mengumpulkan dokumen >= $fetchSize, tidak perlu request varian berikutnya
        if (count($rowsByAju) >= $fetchSize) {
            break;
        }
    }
}

$rowsData = array_values($rowsByAju);

// Urutkan dokumen terbaru berdasarkan tanggal respon atau tanggal daftar secara descending
usort($rowsData, function($a, $b) {
    $tA = !empty($a['tanggalRespon']) ? $a['tanggalRespon'] : (!empty($a['tanggalDaftar']) ? $a['tanggalDaftar'] : '');
    $tB = !empty($b['tanggalRespon']) ? $b['tanggalRespon'] : (!empty($b['tanggalDaftar']) ? $b['tanggalDaftar'] : '');
    return strcmp($tB, $tA);
});

// Kunci pembatasan ketat: tidak boleh melebihi $fetchSize (default 50 baris)
if (count($rowsData) > $fetchSize) {
    $rowsData = array_slice($rowsData, 0, $fetchSize);
}

$successfulIdentitas = implode(', ', $successfulVariants);

// Simpan timestamp sync berhasil ke shared cache
@file_put_contents($syncCacheFile, json_encode([
    'timestamp' => $now,
    'cmp_id'    => $cmpId,
    'date'      => date('Y-m-d H:i:s', $now)
]));

if (empty($rowsData)) {
    $_SESSION[$sessionKey] = $now;
    echo json_encode([
        'status'   => 'OK',
        'cmp_id'   => $cmpId,
        'message'  => 'Koneksi CEISA 4.0 berhasil, namun belum ada dokumen pabean yang ditemukan.',
        'count'    => 0,
        'total'    => $totalPortal
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. UPSERT KE bc_ceisa_data & SINKRONISASI bc_ceisa_header
// -------------------------------------------------------------
$sqlDelete = "DELETE FROM bc_ceisa_data WHERE nomorAju = ?";
$stmtDelete = $koneksi->prepare($sqlDelete);

$sqlInsertData = "INSERT INTO bc_ceisa_data (
    cmp_id, idHeader, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen, 
    kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas, namaProses, 
    idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb, nipAp, kodeJalur, 
    flagHardcopy, flagHardcopySka, waktuTerimaHardcopy, jatuhtempoHardcopy, namaPpjk, 
    nomorBc11, totalCount, tanggalBc11, subposBc11, posBc11, kodeProsesPerbaikan, 
    namaProsesPerbaikan, flagSsm, idKepTujuan, nomorSkep, idRespon, namaRespon, 
    nomorRespon, tanggalRespon, pdf, asalData, statusPkb
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmtInsertData = $koneksi->prepare($sqlInsertData);

// Update nomor & tanggal daftar ke bc_ceisa_header jika tersedia
$sqlUpdateHdr = "UPDATE bc_ceisa_header 
    SET nomor_daftar = ?, tanggal_daftar = ? 
    WHERE nomor_aju = ? AND (nomor_daftar IS NULL OR nomor_daftar = '' OR nomor_daftar != ?)";
$stmtUpdateHdr = $koneksi->prepare($sqlUpdateHdr);

// Update juga ke bc_ceisa_aju_posted
$sqlUpdatePosted = "UPDATE bc_ceisa_aju_posted 
    SET nomor_daftar = ?, tanggal_daftar = ? 
    WHERE nomor_aju = ? AND (nomor_daftar IS NULL OR nomor_daftar = '' OR nomor_daftar != ?)";
$stmtUpdatePosted = $koneksi->prepare($sqlUpdatePosted);

$cleanCmp = $koneksi->real_escape_string($cmpId);

// Amankan data SPPB lama dari bc_ceisa_data sebelum tertimpa
$sqlArchiveSppb = "INSERT INTO bc_ceisa_sppb (
    cmp_id, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen,
    kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas,
    namaProses, idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb,
    nipAp, kodeJalur, idRespon, namaRespon, nomorRespon, tanggalRespon, pdf
) SELECT 
    '$cleanCmp', nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen,
    kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas,
    namaProses, idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb,
    nipAp, kodeJalur, idRespon, namaRespon, nomorRespon, tanggalRespon, pdf
FROM bc_ceisa_data 
WHERE nomorAju = ? AND UPPER(TRIM(namaRespon)) LIKE '%SPPB%'
ON DUPLICATE KEY UPDATE 
    nomorDaftar = VALUES(nomorDaftar),
    tanggalDaftar = VALUES(tanggalDaftar),
    nomorRespon = VALUES(nomorRespon),
    tanggalRespon = VALUES(tanggalRespon),
    pdf = COALESCE(NULLIF(VALUES(pdf), ''), bc_ceisa_sppb.pdf),
    upd_time = NOW()";
$stmtArchiveSppb = $koneksi->prepare($sqlArchiveSppb);

// Upsert SPPB baru langsung ke tabel arsip permanen bc_ceisa_sppb
$sqlUpsertSppb = "INSERT INTO bc_ceisa_sppb (
    cmp_id, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen,
    kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas,
    namaProses, idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb,
    nipAp, kodeJalur, idRespon, namaRespon, nomorRespon, tanggalRespon, pdf
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE 
    nomorDaftar = VALUES(nomorDaftar),
    tanggalDaftar = VALUES(tanggalDaftar),
    nomorRespon = VALUES(nomorRespon),
    tanggalRespon = VALUES(tanggalRespon),
    pdf = COALESCE(NULLIF(VALUES(pdf), ''), bc_ceisa_sppb.pdf),
    upd_time = NOW()";
$stmtUpsertSppb = $koneksi->prepare($sqlUpsertSppb);

$insertedCount  = 0;
$updatedHdrCount = 0;
$newHdrCount    = 0;

$koneksi->begin_transaction();

try {
    foreach ($rowsData as $row) {
        $nomorAju = trim($row['nomorAju'] ?? '');
        if (empty($nomorAju)) continue;

        // Parsing tanggal Daftar (d-m-Y atau Y-m-d)
        $tanggalDaftar = null;
        if (!empty($row['tanggalDaftar'])) {
            $parsed = DateTime::createFromFormat('d-m-Y', $row['tanggalDaftar']) ?: DateTime::createFromFormat('Y-m-d', $row['tanggalDaftar']);
            if ($parsed) $tanggalDaftar = $parsed->format('Y-m-d');
        }

        // Parsing tanggal Respon (d-m-Y H:i:s atau Y-m-d H:i:s)
        $tanggalRespon = null;
        if (!empty($row['tanggalRespon'])) {
            $parsed = DateTime::createFromFormat('d-m-Y H:i:s', $row['tanggalRespon']) 
                   ?: DateTime::createFromFormat('d-m-Y', $row['tanggalRespon']) 
                   ?: DateTime::createFromFormat('Y-m-d H:i:s', $row['tanggalRespon']);
            if ($parsed) $tanggalRespon = $parsed->format('Y-m-d H:i:s');
        }

        // Parsing tanggal BC 1.1
        $tanggalBc11 = null;
        if (!empty($row['tanggalBc11'])) {
            $parsed = DateTime::createFromFormat('d-m-Y H:i:s', $row['tanggalBc11']) 
                   ?: DateTime::createFromFormat('d-m-Y', $row['tanggalBc11'])
                   ?: DateTime::createFromFormat('Y-m-d', $row['tanggalBc11']);
            if ($parsed) $tanggalBc11 = $parsed->format('Y-m-d');
        }

        // 0. Amankan data SPPB lama ke bc_ceisa_sppb sebelum bc_ceisa_data ditimpa
        if ($stmtArchiveSppb) {
            $stmtArchiveSppb->bind_param("s", $nomorAju);
            $stmtArchiveSppb->execute();
        }

        // 1. Bersihkan data lama di bc_ceisa_data
        if ($stmtDelete) {
            $stmtDelete->bind_param("s", $nomorAju);
            $stmtDelete->execute();
        }

        // 2. Simpan record terbaru ke bc_ceisa_data
        if ($stmtInsertData) {
            $totalCountVal = isset($row['totalCount']) ? (int)$row['totalCount'] : 0;
            $idHeaderVal   = (string)($row['idHeader'] ?? '');
            $noDaftarVal   = (string)($row['nomorDaftar'] ?? '');
            $kdDokVal      = (string)($row['kodeDokumen'] ?? '');
            $nmDokVal      = (string)($row['namaDokumen'] ?? '');
            $kdKantorVal   = (string)($row['kodeKantor'] ?? '');
            $nmKantorVal   = (string)($row['namaKantorPendek'] ?? '');
            $nmEntitasVal  = (string)($row['namaEntitas'] ?? '');
            $kdProsesVal   = (string)($row['kodeProses'] ?? '');
            $kdEntitasVal  = (string)($row['kodeEntitas'] ?? '');
            $nmProsesVal   = (string)($row['namaProses'] ?? '');
            $idPrshVal     = (string)($row['idPerusahaan'] ?? '');
            $nmPrshVal     = (string)($row['namaPerusahaan'] ?? '');
            $idPpjkVal     = (string)($row['idPpjk'] ?? '');
            $nipPfpdVal    = (string)($row['nipPfpd'] ?? '');
            $nipPfpbVal    = (string)($row['nipPfpb'] ?? '');
            $nipApVal      = (string)($row['nipAp'] ?? '');
            $kdJalurVal    = (string)($row['kodeJalur'] ?? '');
            $flgHrdVal     = (string)($row['flagHardcopy'] ?? '');
            $flgSkaVal     = (string)($row['flagHardcopySka'] ?? '');
            $wktHrdVal     = (string)($row['waktuTerimaHardcopy'] ?? '');
            $jthHrdVal     = (string)($row['jatuhtempoHardcopy'] ?? '');
            $nmPpjkVal     = (string)($row['namaPpjk'] ?? '');
            $noBc11Val     = (string)($row['nomorBc11'] ?? '');
            $subposVal     = (string)($row['subposBc11'] ?? '');
            $posBc11Val    = (string)($row['posBc11'] ?? '');
            $kdPrbVal      = (string)($row['kodeProsesPerbaikan'] ?? '');
            $nmPrbVal      = (string)($row['namaProsesPerbaikan'] ?? '');
            $flgSsmVal     = (string)($row['flagSsm'] ?? '');
            $idKepVal      = (string)($row['idKepTujuan'] ?? '');
            $noSkepVal     = (string)($row['nomorSkep'] ?? '');
            $idResponVal   = (string)($row['idRespon'] ?? '');
            $nmResponVal   = (string)($row['namaRespon'] ?? '');
            $noResponVal   = (string)($row['nomorRespon'] ?? '');
            $pdfVal        = (string)($row['pdf'] ?? '');
            $asalDataVal   = (string)($row['asalData'] ?? '');
            $statusPkbVal  = (string)($row['statusPkb'] ?? '');

            $targetCmpId   = !empty($core15) ? $core15 : (preg_replace('/[^0-9]/', '', $idPrshVal) ?: $cmpId);

            $stmtInsertData->bind_param(
                "ssssssssssssssssssssssssssisssssssssssssss",
                $targetCmpId, $idHeaderVal, $nomorAju, $noDaftarVal, $tanggalDaftar, $kdDokVal, $nmDokVal,
                $kdKantorVal, $nmKantorVal, $nmEntitasVal, $kdProsesVal, $kdEntitasVal, $nmProsesVal,
                $idPrshVal, $nmPrshVal, $idPpjkVal, $nipPfpdVal, $nipPfpbVal, $nipApVal, $kdJalurVal,
                $flgHrdVal, $flgSkaVal, $wktHrdVal, $jthHrdVal, $nmPpjkVal,
                $noBc11Val, $totalCountVal, $tanggalBc11, $subposVal, $posBc11Val, $kdPrbVal,
                $nmPrbVal, $flgSsmVal, $idKepVal, $noSkepVal, $idResponVal, $nmResponVal,
                $noResponVal, $tanggalRespon, $pdfVal, $asalDataVal, $statusPkbVal
            );
            $stmtInsertData->execute();
            $insertedCount++;

            // 2b. Simpan / Amankan respon SPPB secara permanen ke bc_ceisa_sppb agar tidak hilang jika kelak tertimpa status baru
            if (stripos($nmResponVal, 'SPPB') !== false && $stmtUpsertSppb) {
                $stmtUpsertSppb->bind_param(
                    "ssssssssssssssssssssssss",
                    $cmpId, $nomorAju, $noDaftarVal, $tanggalDaftar, $kdDokVal, $nmDokVal,
                    $kdKantorVal, $nmKantorVal, $nmEntitasVal, $kdProsesVal, $kdEntitasVal,
                    $nmProsesVal, $idPrshVal, $nmPrshVal, $idPpjkVal, $nipPfpdVal, $nipPfpbVal,
                    $nipApVal, $kdJalurVal, $idResponVal, $nmResponVal, $noResponVal, $tanggalRespon, $pdfVal
                );
                $stmtUpsertSppb->execute();
            }
        }

        // 3. Update nomor_daftar ke bc_ceisa_header jika sudah terbit
        if (!empty($noDaftarVal) && !empty($tanggalDaftar) && $stmtUpdateHdr) {
            $stmtUpdateHdr->bind_param("ssss", $noDaftarVal, $tanggalDaftar, $nomorAju, $noDaftarVal);
            $stmtUpdateHdr->execute();
            if ($stmtUpdateHdr->affected_rows > 0) {
                $updatedHdrCount++;
            }

            if ($stmtUpdatePosted) {
                $stmtUpdatePosted->bind_param("ssss", $noDaftarVal, $tanggalDaftar, $nomorAju, $noDaftarVal);
                $stmtUpdatePosted->execute();
            }
        }
    }

    $koneksi->commit();
} catch (\Throwable $ex) {
    $koneksi->rollback();
    echo json_encode([
        'status'  => 'ERROR',
        'cmp_id'  => $cmpId,
        'message' => 'Terjadi kesalahan database: ' . $ex->getMessage()
    ]);
    exit;
}

if (isset($stmtDelete) && $stmtDelete) $stmtDelete->close();
if (isset($stmtInsertData) && $stmtInsertData) $stmtInsertData->close();
if (isset($stmtUpdateHdr) && $stmtUpdateHdr) $stmtUpdateHdr->close();
if (isset($stmtUpdatePosted) && $stmtUpdatePosted) $stmtUpdatePosted->close();
if (isset($stmtArchiveSppb) && $stmtArchiveSppb) $stmtArchiveSppb->close();
if (isset($stmtUpsertSppb) && $stmtUpsertSppb) $stmtUpsertSppb->close();

// Simpan waktu sync terakhir ke session
$_SESSION['last_ceisa_sync_' . $cmpId] = $now;

echo json_encode([
    'status'          => 'OK',
    'cmp_id'          => $cmpId,
    'cmp_name'        => $cmpName,
    'identitas'       => $successfulIdentitas,
    'total_portal'    => $totalPortal,
    'fetched'         => count($rowsData),
    'inserted'        => $insertedCount,
    'updated_headers' => $updatedHdrCount,
    'new_headers'     => $newHdrCount,
    'has_changes'     => ($updatedHdrCount > 0 || $newHdrCount > 0),
    'timestamp'       => date('Y-m-d H:i:s', $now),
    'message'         => "Sinkronisasi CEISA 4.0 berhasil! Diproses: $insertedCount dokumen, Diperbarui: $updatedHdrCount, Baru: $newHdrCount."
]);

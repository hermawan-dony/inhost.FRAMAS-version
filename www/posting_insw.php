<?php
/**
 * INSW / PPKEK Document Posting Engine (posting_insw.php)
 * Mengirimkan dokumen pabean KEK / FTZ (seperti PPKEK Dokumen 613, 611, 612) ke Portal / Gateway INSW (LNSW).
 * Mendukung pemanggilan langsung dengan parameter: nomor_aju, cmp_id
 */
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/CeisaJsonBuilder.php';

$nomor_aju = trim((string)($_REQUEST['nomor_aju'] ?? $_REQUEST['aju'] ?? ''));
$cmp_id    = trim((string)($_REQUEST['cmp_id'] ?? ''));

if (empty($nomor_aju)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
    exit;
}

// 1. Generate payload JSON PPKEK menggunakan CeisaJsonBuilder
$genResult = CeisaJsonBuilder::generate($nomor_aju, $cmp_id);
if ($genResult['status'] !== 'OK') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ERROR', 'message' => 'Gagal generate JSON INSW: ' . $genResult['message']]);
    exit;
}

$json = $genResult['json'];
if (empty($cmp_id)) {
    $cmp_id = $genResult['cmp_id'];
}

$decodedPayload = json_decode($json, true);
$kodeDokumen = $decodedPayload['kodeDokumen'] ?? ($genResult['kode_dokumen'] ?? '613');

// 2. Deteksi data perusahaan & pastikan kolom konfigurasi INSW tersedia di database
$comp = null;
if (function_exists('ceisa_get_company')) {
    $comp = ceisa_get_company($nomor_aju);
}

if (isset($koneksi) && $koneksi) {
    // Auto migration jika kolom INSW belum ada di bc_ceisa_company
    $chkCol = $koneksi->query("SHOW COLUMNS FROM bc_ceisa_company LIKE 'insw_client_id'");
    if ($chkCol && $chkCol->num_rows === 0) {
        @$koneksi->query("ALTER TABLE bc_ceisa_company 
            ADD COLUMN insw_client_id VARCHAR(100) NULL AFTER api_key_ceisa,
            ADD COLUMN insw_client_secret VARCHAR(100) NULL AFTER insw_client_id,
            ADD COLUMN insw_token TEXT NULL AFTER insw_client_secret,
            ADD COLUMN insw_api_url VARCHAR(255) NULL DEFAULT 'https://api.insw.go.id/document' AFTER insw_token");
    }

    if (!$comp && !empty($cmp_id)) {
        $cstmt = $koneksi->prepare("SELECT * FROM bc_ceisa_company WHERE cmp_id = ? LIMIT 1");
        if ($cstmt) {
            $cstmt->bind_param("s", $cmp_id);
            $cstmt->execute();
            $cres = $cstmt->get_result();
            $comp = $cres->fetch_assoc();
            $cstmt->close();
        }
    }
}

// 3. Ambil konfigurasi API INSW (dari database, session, atau parameter request langsung)
$insw_client_id     = trim($_REQUEST['insw_client_id'] ?? ($comp['insw_client_id'] ?? ($_SESSION['insw_client_id_' . $cmp_id] ?? '')));
$insw_client_secret = trim($_REQUEST['insw_client_secret'] ?? ($comp['insw_client_secret'] ?? ($_SESSION['insw_client_secret_' . $cmp_id] ?? '')));
$insw_token         = trim($_REQUEST['insw_token'] ?? ($comp['insw_token'] ?? ($_SESSION['insw_token_' . $cmp_id] ?? '')));
$insw_api_url       = trim($_REQUEST['insw_api_url'] ?? ($comp['insw_api_url'] ?? 'https://api.insw.go.id/document'));
if (empty($insw_api_url)) {
    $insw_api_url = 'https://api.insw.go.id/document';
}

// 4. Jika token atau Client ID & Secret INSW sudah tersedia, lakukan pengiriman cURL ke Gateway INSW
if (!empty($insw_token) || (!empty($insw_client_id) && !empty($insw_client_secret))) {
    $headers = [
        "Content-Type: application/json",
        "Accept: application/json"
    ];
    if (!empty($insw_token)) {
        $headers[] = "Authorization: Bearer " . $insw_token;
    }
    if (!empty($insw_client_id)) {
        $headers[] = "Client-Id: " . $insw_client_id;
    }
    if (!empty($insw_client_secret)) {
        $headers[] = "Client-Secret: " . $insw_client_secret;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $insw_api_url,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ERROR',
            'gateway' => 'INSW',
            'message' => 'cURL Error ke Gateway INSW: ' . $err
        ]);
        exit;
    }

    // Catat jika respon sukses
    if ($httpCode >= 200 && $httpCode < 300) {
        if (isset($koneksi) && $koneksi) {
            $del = $koneksi->prepare("DELETE FROM bc_ceisa_aju_posted WHERE nomor_aju = ?");
            if ($del) {
                $del->bind_param("s", $nomor_aju);
                $del->execute();
                $del->close();
            }
            $ins = $koneksi->prepare("INSERT INTO bc_ceisa_aju_posted (nomor_aju, waktu) VALUES (?, NOW())");
            if ($ins) {
                $ins->bind_param("s", $nomor_aju);
                $ins->execute();
                $ins->close();
            }
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    $decodedRes = json_decode($response, true);
    echo json_encode([
        'status' => ($httpCode >= 200 && $httpCode < 300) ? 'OK' : 'FAILED',
        'gateway' => 'INSW',
        'http_code' => $httpCode,
        'nomor_aju' => $nomor_aju,
        'kode_dokumen' => $kodeDokumen,
        'response' => $decodedRes ?? $response,
        'raw_response' => $response
    ]);
    exit;
}

// 5. Default/Initial: Kembalikan status READY_INSW dengan payload JSON PPKEK siap pakai & petunjuk akun INSW
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'READY_INSW',
    'gateway' => 'INSW (Lembaga National Single Window)',
    'nomor_aju' => $nomor_aju,
    'kode_dokumen' => $kodeDokumen,
    'cmp_name' => $comp['cmp_name'] ?? ($cmp_id ?: '-'),
    'target_url' => $insw_api_url,
    'message' => "Dokumen {$kodeDokumen} (PPKEK) berhasil digenerate sesuai skema LNSW / INSW!",
    'note' => 'Dokumen ini dikelola oleh SINSW / LNSW (bukan API Bea Cukai CEISA 4.0 konvensional). Untuk pengiriman otomatis via Host-to-Host INSW, diperlukan Client ID & Client Secret / Bearer Token LNSW yang didaftarkan di portal kek.insw.go.id. Payload JSON di bawah siap diunggah atau disalin langsung ke Portal KEK INSW.',
    'json_payload' => $decodedPayload,
    'raw_json' => $json
]);
exit;

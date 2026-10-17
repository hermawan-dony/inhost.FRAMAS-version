<?php 
/**
 * CEISA 4.0 Document Posting Engine (posting.php)
 * Mengirimkan dokumen pabean ke API CEISA 4.0 secara multi-company.
 * Mendukung pemanggilan langsung dengan parameter: nomor_aju, cmp_id
 */
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/CeisaJsonBuilder.php';

$nomor_aju = trim((string)($_REQUEST['nomor_aju'] ?? $_REQUEST['aju'] ?? ''));
$cmp_id    = trim((string)($_REQUEST['cmp_id'] ?? ''));

// 1. Jika nomor_aju diberikan, otomatis bangun JSON segar langsung dari database riil
$json = '';
if (!empty($nomor_aju)) {
    $genResult = CeisaJsonBuilder::generate($nomor_aju, $cmp_id);
    if ($genResult['status'] === 'OK') {
        $json = $genResult['json'];
        if (empty($cmp_id)) {
            $cmp_id = $genResult['cmp_id'];
        }
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal generate JSON: ' . $genResult['message']]);
        exit;
    }
}

// 2. Fallback Mode: Ambil payload JSON dari file per-company, tabel bc_ceisa_json, atau json.txt
if (empty($json)) {
    $cleanCmp = preg_replace('/[^0-9A-Za-z_-]/', '', $cmp_id);
    if (!empty($cleanCmp) && file_exists(__DIR__ . "/json_{$cleanCmp}.txt")) {
        $json = file_get_contents(__DIR__ . "/json_{$cleanCmp}.txt");
    }
    if (empty($json) && isset($koneksi) && $koneksi) {
        if (!empty($cmp_id)) {
            $stmt = $koneksi->prepare("SELECT json FROM bc_ceisa_json WHERE cmp_id = ? ORDER BY id DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $cmp_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($r = $res->fetch_assoc()) {
                    $json = $r['json'];
                }
                $stmt->close();
            }
        }
        if (empty($json)) {
            $res = $koneksi->query("SELECT json FROM bc_ceisa_json ORDER BY id DESC LIMIT 1");
            if ($res && $r = $res->fetch_assoc()) {
                $json = $r['json'];
            }
        }
    }
    if (empty($json) && file_exists(__DIR__ . '/json.txt')) {
        $json = file_get_contents(__DIR__ . '/json.txt');
    }
}

if (empty($json)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ERROR', 'message' => 'Payload JSON kosong. Berikan parameter nomor_aju atau buat json terlebih dahulu.']);
    exit;
}

// 3. Deteksi otomatis perusahaan dari payload JSON jika belum ditentukan
$decoded = json_decode($json, true);
$activeAju = $decoded['nomorAju'] ?? $nomor_aju;

if (!empty($activeAju)) {
    $detected_company = ceisa_get_company($activeAju);
    if ($detected_company) {
        $cmp_id         = $detected_company['cmp_id'];
        $username       = $detected_company['username_ceisa'];
        $password       = $detected_company['password_ceisa'];
        $isfinal        = (!empty($detected_company['is_final']) && strtolower((string)$detected_company['is_final']) === 'true') ? 'true' : 'false';
        $_SESSION['cmp_id'] = $cmp_id;
    }
}

// 4. Sertakan init.php untuk inisialisasi / refresh Bearer Token OAuth
require __DIR__ . '/init.php';

$hostprd = "https://apis-gw.beacukai.go.id/openapi/document?isFinal=$isfinal&isRevision=false";
$bearer  = $_SESSION["token_" . $cmp_id] ?? ($_SESSION["token"] ?? '');

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true, 
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ERROR', 'message' => 'cURL Error: ' . $err]);
    exit;
}

// 5. Catat ke bc_ceisa_aju_posted jika respon sukses diterima dari CEISA 4.0
if (!empty($activeAju) && (strpos($response, '"status":"OK"') !== false || strpos($response, '"status": "OK"') !== false || strpos($response, '"status":"ok"') !== false)) {
    if (isset($koneksi) && $koneksi) {
        $del = $koneksi->prepare("DELETE FROM bc_ceisa_aju_posted WHERE nomor_aju = ?");
        if ($del) {
            $del->bind_param("s", $activeAju);
            $del->execute();
            $del->close();
        }
        $ins = $koneksi->prepare("INSERT INTO bc_ceisa_aju_posted (nomor_aju, waktu) VALUES (?, NOW())");
        if ($ins) {
            $ins->bind_param("s", $activeAju);
            $ins->execute();
            $ins->close();
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo $response;
?>
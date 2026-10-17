<?php 
/**
 * CEISA 4.0 Document Revision Engine (perbaikan.php)
 * Mengirimkan perbaikan / revisi dokumen pabean ke API CEISA 4.0 secara multi-company.
 */
require_once __DIR__ . '/datalogin.php';

// 1. Ambil payload JSON (dari json.txt atau tabel bc_ceisa_json)
$file = __DIR__ . '/json.txt';
$json = '';
if (file_exists($file)) {
    $json = file_get_contents($file);
}

if (empty($json) && isset($koneksi) && $koneksi) {
    if (!empty($cmp_id)) {
        $stmt = $koneksi->prepare("SELECT json FROM bc_ceisa_json WHERE cmp_id = ? LIMIT 1");
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
        $res = $koneksi->query("SELECT json FROM bc_ceisa_json LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) {
            $json = $r['json'];
        }
    }
}

// 2. Deteksi otomatis perusahaan dari payload JSON jika belum ditentukan
if (!empty($json)) {
    $decoded = json_decode($json, true);
    if (!empty($decoded['nomorAju'])) {
        $detected_company = ceisa_get_company($decoded['nomorAju']);
        if ($detected_company) {
            $cmp_id         = $detected_company['cmp_id'];
            $username       = $detected_company['username_ceisa'];
            $password       = $detected_company['password_ceisa'];
            $isfinal        = (!empty($detected_company['is_final']) && strtolower($detected_company['is_final']) === 'true') ? 'true' : 'false';
            $_SESSION['cmp_id'] = $cmp_id;
        }
    }
}

require_once __DIR__ . '/init.php';

$hostprd = "https://apis-gw.beacukai.go.id/openapi/document?isFinal=$isfinal&isRevision=true";
$bearer  = $_SESSION["token"] ?? '';

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
    echo "cURL Error #:" . $err;
} else {
    echo $response;
}
?>
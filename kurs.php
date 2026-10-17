<?php 
/**
 * CEISA 4.0 Kurs Pajak Valuta Asing (kurs.php)
 * Mengambil nilai kurs pajak mingguan dari API CEISA 4.0.
 * Parameter:
 *   - kode: Kode valuta (contoh: usd, eur, jpy, sgd, cny). Default: usd
 *   - json: 1 (opsional: mengembalikan format JSON lengkap)
 */
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/init.php';

$kodeValuta = strtolower(trim($_GET["kode"] ?? ($_GET["valuta"] ?? 'usd')));
if (empty($kodeValuta)) {
    $kodeValuta = 'usd';
}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/kurs/" . urlencode($kodeValuta);
$bearer  = $_SESSION["token"] ?? '';

$curl = curl_init(); 
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
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
    exit;
}

if (!empty($_GET['json'])) {
    header('Content-Type: application/json');
    echo $response;
    exit;
}

$data = json_decode($response, true);
$status = $data['message'] ?? ($data['status'] ?? '');

if (strtolower($status) === 'success' || strtolower($status) === 'ok') {
    if (!empty($data['data'][0]['nilaiKurs'])) {
        // Output angka kurs langsung dalam format plain text
        echo $data['data'][0]['nilaiKurs'];
        exit;
    }
}

// Fallback jika format data berbeda
echo $response;
?>
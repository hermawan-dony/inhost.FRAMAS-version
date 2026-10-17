<?php 
/**
 * CEISA 4.0 Daftar Gudang TPS (gettps.php)
 * Mengambil referensi kode dan nama TPS berdasarkan Kode Kantor Pabean.
 * Output: KodeGudang \t NamaGudang \n
 */
require_once __DIR__ . '/datalogin.php'; 
require_once __DIR__ . '/init.php';

$kantor = trim($_GET["a"] ?? ($_GET["kode_kantor"] ?? ($kode_kantor ?: '050100')));
if (empty($kantor)) {
    echo "Kode kantor tidak boleh kosong.";
    exit;
}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/gudangTPS/kodeKantor/" . urlencode($kantor);
$bearer  = $_SESSION["token"] ?? '';

$curl = curl_init(); 
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 20,
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

$data = json_decode($response, true);
$status = $data['status'] ?? '';

if ($status === 'OK' && !empty($data['data']) && is_array($data['data'])) {
    foreach ($data['data'] as $item) {
        $kodeGudang = $item['kodeGudang'] ?? 'null';
        $namaGudang = $item['namaGudang'] ?? 'null';
        echo $kodeGudang . "\t" . $namaGudang . "\n";
    }
} else {
    echo $response;
}
?>
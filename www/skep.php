<?php 
/**
 * CEISA 4.0 Nomor SKEP Fasilitas Perusahaan (skep.php)
 * Mengambil Nomor SKEP dan Tanggal SKEP Fasilitas TPB/PLB dari CEISA 4.0 berdasarkan NPWP.
 * Output: NomorSkep|TanggalSkep
 */
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/init.php';

$targetNpwp = trim($_GET["npwp"] ?? ($npwp ?? ''));
if (empty($targetNpwp)) {
    echo 'Nomor NPWP tidak boleh kosong!';
    exit;
}

$hostprd = "https://apis-gw.beacukai.go.id/v2/parser/v1/GudangPlb/perusahanSkepFasilitas?idPerusahaanPajak=" . urlencode($targetNpwp);
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

if ($status === 'OK' && !empty($data['data'][0])) {
    $row = $data['data'][0];
    $noSkep  = $row['nomorSkep'] ?? '';
    $tglSkep = $row['tanggalSkep'] ?? '';
    echo $noSkep . "|" . $tglSkep;
} else {
    echo "|";
}
?>
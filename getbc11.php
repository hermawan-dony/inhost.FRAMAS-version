<?php 
/**
 * CEISA 4.0 Manifest BC 1.1 (getbc11.php)
 * Mengambil data pos/subpos manifes kedatangan sarana pengangkut (BC 1.1).
 * Parameter:
 *   - a: Nomor Host B/L
 *   - b: Tanggal Host B/L (YYYY-MM-DD)
 *   - c: Kode Kantor Pabean
 *   - d: Nama Perusahaan Importir
 */
require_once __DIR__ . '/datalogin.php'; 
require_once __DIR__ . '/init.php';

$noBl    = trim($_GET["a"] ?? ($_GET["no_bl"] ?? ''));
$tglBl   = trim($_GET["b"] ?? ($_GET["tgl_bl"] ?? date('Y-m-d')));
$kantor  = trim($_GET["c"] ?? ($_GET["kode_kantor"] ?? ($kode_kantor ?: '050100')));
$nama    = trim($_GET["d"] ?? ($_GET["nama"] ?? ($namaperusahaan ?: '')));

if (empty($noBl)) {
    echo "Nomor B/L tidak boleh kosong.";
    exit;
}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/manifes-bc11?" . http_build_query([
    'noHostBl'   => $noBl,
    'tglHostBl'  => $tglBl,
    'kodeKantor' => $kantor,
    'nama'       => $nama
]);

$bearer  = $_SESSION["token"] ?? '';
$curl = curl_init(); 
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 25,
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

echo $response;
?>
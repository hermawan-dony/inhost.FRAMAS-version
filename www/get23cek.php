<?php
include('init.php');
include('datalogin.php'); 

@$aju = '00003001081620250224000288'; // $_GET["aju"];

$aju = '00002303006520251008182342' ;
if ($aju == '') {
    echo 'Nomor AJU tidak Boleh Kosong!';
    exit;
}

// URL API
$hostprd = "https://apis-gw.beacukai.go.id/openapi/document/detail/23/$aju/050900";

$bearer = $_SESSION["token"];

 
// Inisialisasi cURL
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json"
    ],
]);

// Eksekusi cURL dan ambil respons
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

echo $response ;
if ($err) {
    echo "cURL Error #:" . $err;
} else {
    // Dekode JSON menjadi array
    $data = json_decode($response, true);
 
}
?>

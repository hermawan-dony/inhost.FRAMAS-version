<?php 
include('init.php');
include('datalogin.php');

$hostprd = "https://apis-gw.beacukai.go.id/openapi/document?isFinal=$isfinal&isRevision=false" ;
$file = 'json.txt' ;
$json = file_get_contents($file,0,null,null);
$bearer = $_SESSION["token"] ; 
$curl = curl_init();
$res = $json; 
curl_setopt_array($curl, [
  CURLOPT_URL => $hostprd,
  CURLOPT_SSL_VERIFYHOST => false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 10,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS => $res,
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
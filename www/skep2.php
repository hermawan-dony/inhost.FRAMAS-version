<?php 
include('initmfa.php'); 

@$npwp = $_GET["npwp"] ;
if($npwp=='') {echo 'Nomor NPWP tidak Boleh Kosong (PAKE NITKU), contoh : ?npwp=0021930615401000000000' ; exit;}

$hostprd = "https://apis-gw.beacukai.go.id/v2/parser/v1/GudangPlb/perusahanSkepFasilitas?idPerusahaanPajak=$npwp" ;
 
$bearer = $_SESSION["mfatoken"] ;  
$curl = curl_init(); 
curl_setopt_array($curl, [
  CURLOPT_URL => $hostprd,
  CURLOPT_SSL_VERIFYHOST => false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
//  CURLOPT_POSTFIELDS => $sendrest,
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
  // echo $response . '<hr>';
		$jsonData  = json_decode($response, true);
		@$status = $jsonData['status'] ;
		if (@$status === 'OK') {
			// Loop melalui setiap data dan format menjadi ..|..|..|..
			foreach ($jsonData['data'] as $item) {
				echo $item['idPerusahaanPajak'] . "|" . $item['nomorSkep'] . "|" . $item['tanggalSkep'] . "|" . $item['alamatTpb'] .    "|" . $item['kodeKantor'] . "\n";
			}		
		}
}	
?>
<?php 
include('initmfa.php'); 
 echo "pake user : $username<hr>";

@$npwp = $_GET["npwp"] ;

if($npwp=='') {echo 'Nomor NPWP tidak Boleh Kosong!' ; exit;}

  //$hostprd = "https://apis-gw.beacukai.go.id/v2/sce-ws/profil/perusahaan/data-perusahaan-by-npwp/?npwp=$npwp" ;
//$hostprd = "https://apis-gw.beacukai.go.id/v2/sce-ws/profil/perusahaan/perusahaan-by-npwp?npwp=018244244055000" ;
 // $hostprd = "https://apis-gw.beacukai.go.id/v2/sce-ws/profil/perusahaan/perusahaan-by-npwp" ; ///?npwp=$npwp" ;
  $hostprd = "https://apis-gw.beacukai.go.id/v2/sce-ws/profil/perusahaan/perusahaanib-by-npwp9?npwp9=$npwp" ;  //INI OKE
  
  echo $hostprd . '<hr>' ;
  
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
      echo $response;
	exit ;	  
	// Decode JSON
	$jsonData = json_decode($response, true);

	// Validasi JSON berhasil di-decode
	if (json_last_error() !== JSON_ERROR_NONE) {
		 echo 'Terjadi kesalahan pada respons JSON: ' . json_last_error_msg();
		exit;
	}

	// Tampilkan data dalam format ..|..|..|..
	$nib = isset($jsonData['nib']) ? $jsonData['nib'] : '';
	$namaPerusahaan = isset($jsonData['namaPerusahaan']) ? $jsonData['namaPerusahaan'] : '';
	$alamatPerusahaan = isset($jsonData['alamatPerusahaan']) ? $jsonData['alamatPerusahaan'] : ''; 
	
	// Cetak data dengan format yang diinginkan
	echo '<hr>'. $npwp . '|'. $nib . "|" . $namaPerusahaan . "|" . $alamatPerusahaan  . "\n";
 
}	
?>
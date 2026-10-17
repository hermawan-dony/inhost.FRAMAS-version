<?php 
include('init.php');
include('datalogin.php');
include('koneksi.php');

@$aju = $_GET["aju"] ;
if($aju=='') {echo 'Nomor AJU tidak Boleh Kosong!' ; exit;}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/status/$aju" ;
 
$bearer = $_SESSION["token"] ;  
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
 // CURLOPT_POSTFIELDS => $sendrest,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $bearer",
    "Content-Type: application/json"
  ],
]);
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

/*
if ($err) {
  echo "cURL Error #:" . $err;
} else {
  echo $response;
}
*/

if ($err) {
  echo "cURL Error #:" . $err;
} else {
	   echo $response;
	 	// SAVE ke MySQL
		$data = json_decode($response, true);
		@$status = $data['status'] ;
		if (@$status === 'Success') {
		echo 'OK' ;	
		$sql = "DELETE FROM bc_ceisa_status WHERE nomor_aju = '$aju'";
		$koneksi->query($sql) ;				
// ------------------------------------------------------------------------		
// Simpan Status
/*
			echo '<h2>Status</h2><table border="1">
					<thead>
						<tr>
							<th>Nomor Aju</th>							
							<th>Nomor Daftar</th>
							<th>Tanggal Daftar</th>
							<th>Kode Proses</th>
							<th>Waktu Status</th>
							<th>Keterangan</th>
							<th>Kode Dokumen</th>
							<th>Status Save</th>
						</tr>
					</thead>
					<tbody>';
*/			
			foreach ($data['dataStatus'] as $respon) {
				/*
				echo '<tr>
						<td>' . $respon['nomorAju'] . '</td>
						<td>' . $respon['nomorDaftar'] . '</td>
						<td>' . $respon['tanggalDaftar'] . '</td>
						<td>' . $respon['kodeProses'] . '</td>
						<td>' . $respon['waktuStatus'] . '</td>
						<td>' . $respon['keterangan'] . '</td> 
						<td>' . $respon['kodeDokumen'] . '</td><td>';
				*/	
				// Prepare Variabel				
				$nomorAju = $respon['nomorAju']; 
				$nomorDaftar = $respon['nomorDaftar'];
				$tanggalDaftar = $respon['tanggalDaftar'];
				$kodeProses = $respon['kodeProses'];
				$waktuStatus = $respon['waktuStatus'];
				$keterangan = $respon['keterangan'];
				$kodeDokumen = $respon['kodeDokumen'];

				// SAVE ke MySQL
				$sql = "INSERT INTO bc_ceisa_status (nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan, kode_dokumen) VALUES ('$nomorAju', '$nomorDaftar', '$tanggalDaftar', '$kodeProses', '$waktuStatus', '$keterangan', '$kodeDokumen')";
				if ($koneksi->query($sql) === TRUE) {
					//echo "Sukses";
					$sql = "UPDATE tpb_header SET nomor_daftar='$nomorDaftar', tanggal_daftar = STR_TO_DATE('$tanggalDaftar', '%d-%m-%Y') WHERE nomor_aju = '$nomorAju' AND coalesce(nomor_daftar,'') = '' ";
					$koneksi->query($sql) ;
				} else {
					echo "Error: " . $sql . "<br>" . $koneksi->error;	
				}	
				//echo '</td></tr>' ;	
					
			}

			//echo '</tbody> </table>';
		} ELSE {
			echo $response; //@$data['message'] ;
		}
}		
?>
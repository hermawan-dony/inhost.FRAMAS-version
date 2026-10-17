<style type="text/css"> body {font-family: verdana,arial,sans-serif; 	font-size:12px;}  table { 	font-family: verdana,arial,sans-serif; 	font-size:11px; 	color:#333333; 	border-width: 1px; 	border-color: #666666; 	border-collapse: collapse; }  th { 	border-width: 1px; 	padding: 5px; 	border-style: solid; 	border-color: #666666; 	background-color: #dedede; }  td { 	border-width: 1px; 	padding: 5px; 	border-style: solid; 	border-color: #666666; 	background-color: #ffffff; } </style>
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
//  CURLOPT_POSTFIELDS => $sendrest,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $bearer",
    "Content-Type: application/json"
  ],
]);
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

//$response = '{"status":"Success","message":"Data Ditemukan","dataStatus":[],"dataRespon":[{"nomorAju":"00003001081620231128000239","kodeRespon":"3015","nomorDaftar":"010272","tanggalDaftar":"2023-11-28 13:04:00","nomorRespon":"010267/PM/KPU.1/2023","tanggalRespon":"2023-11-28 13:04:00","waktuRespon":"2023-11-28 13:04:00","keterangan":"NPE","pesan":[],"pdf":"respon/2023/11/28/14/1bf20f3d-ca87-49e4-b37f-00d32e74446a-respon-0d2fa6df-5cc6-4a6c-ab16-5451ea889cc1.pdf","kodeDokumen":"30"},{"nomorAju":"00003001081620231128000238","kodeRespon":"3015","nomorDaftar":"986568","tanggalDaftar":"2023-11-28 09:38:00","nomorRespon":"986672/KPU.3/2023","tanggalRespon":"2023-11-28 09:38:00","waktuRespon":"2023-11-28 09:38:00","keterangan":"NPE","pesan":[],"pdf":"respon/2023/11/28/14/262d6424-3fe7-401c-9b47-69ba1397c30e-respon-cc15c1d5-1a56-42d8-b743-2185b58863e1.pdf","kodeDokumen":"30"}]}' ;
		

if ($err) {
  echo "cURL Error #:" . $err;
} else {
	 	// SAVE ke MySQL
		$sql = "INSERT INTO bc_ceisa_respon_logs (respon) VALUES ('$response')";
		$koneksi->query($sql) ;					
					
		$data = json_decode($response, true);
		@$status = $data['status'] ;
		if (@$status === 'Success') {
// ------------------------------------------------------------------------		
// Simpan Status
		$sql = "DELETE FROM bc_ceisa_status WHERE nomor_aju = '$aju'";
		$koneksi->query($sql) ;	
		$sql = "DELETE FROM bc_ceisa_respons WHERE nomor_aju = '$aju'";
		$koneksi->query($sql) ;
		
				
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
			
			foreach ($data['dataStatus'] as $respon) {
				echo '<tr>
						<td>' . $respon['nomorAju'] . '</td>
						<td>' . $respon['nomorDaftar'] . '</td>
						<td>' . $respon['tanggalDaftar'] . '</td>
						<td>' . $respon['kodeProses'] . '</td>
						<td>' . $respon['waktuStatus'] . '</td>
						<td>' . $respon['keterangan'] . '</td> 
						<td>' . $respon['kodeDokumen'] . '</td><td>';
					
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
					echo "Sukses";
					$sql = "UPDATE tpb_header SET nomor_daftar='$nomorDaftar', tanggal_daftar = STR_TO_DATE('$tanggalDaftar', '%d-%m-%Y') WHERE nomor_aju = '$nomorAju' AND coalesce(nomor_daftar,'') = '' ";
					$koneksi->query($sql) ;				
				} else {
					echo "Error: " . $sql . "<br>" . $koneksi->error;	
				}	
				echo '</td></tr>' ;	
					
			}

			echo '</tbody>
				  </table>';

// ------------------------------------------------------------------------
// Simpan Respon				  
			
			echo '<hr><h2>Respon</h2><table border="1">
					<thead>
						<tr>
							<th>Nomor Aju</th>
							<th>Kode Respon</th>
							<th>Nomor Daftar</th>
							<th>Tanggal Daftar</th>
							<th>Nomor Respon</th>
							<th>Tanggal Respon</th>
							<th>Keterangan</th>
							<th>PDF</th>
							<th>Kode Dokumen</th>
							<th>Status Save</th>
						</tr>
					</thead>
					<tbody>';
			
			foreach ($data['dataRespon'] as $respon) {
				echo '<tr>
						<td>' . $respon['nomorAju'] . '</td>
						<td>' . $respon['kodeRespon'] . '</td>
						<td>' . $respon['nomorDaftar'] . '</td>
						<td>' . $respon['tanggalDaftar'] . '</td>
						<td>' . $respon['nomorRespon'] . '</td>
						<td>' . $respon['tanggalRespon'] . '</td>
						<td>' . $respon['keterangan'] . '</td>
						<td>' . $respon['pdf'] . '</td>
						<td>' . $respon['kodeDokumen'] . '</td><td>';
					
				// Prepare Variabel				
				$nomorAju = $respon['nomorAju'];
				$kodeRespon = $respon['kodeRespon'];
				$nomorDaftar = $respon['nomorDaftar'];
				$tanggalDaftar = $respon['tanggalDaftar'];
				$nomorRespon = $respon['nomorRespon'];
				$tanggalRespon = $respon['waktuRespon'];
				$keterangan = $respon['keterangan'];
				$pdf = $respon['pdf'];
				$kodeDokumen = $respon['kodeDokumen'];

				// SAVE ke MySQL									
				$sql = "INSERT INTO bc_ceisa_respons (nomor_aju, kode_respon, nomor_daftar, tanggal_daftar, nomor_respon, tanggal_respon, keterangan, pdf, kode_dokumen) VALUES ('$nomorAju', '$kodeRespon', '$nomorDaftar', '$tanggalDaftar', '$nomorRespon', '$tanggalRespon', '$keterangan', '$pdf', '$kodeDokumen')";
				if ($koneksi->query($sql) === TRUE) {
					echo "Sukses";
				} else {
					echo "Error: " . $sql . "<br>" . $koneksi->error;	
				}	
				echo '</td></tr>' ;	
					
			}

			echo '</tbody>
				  </table>';
		} else {
			
			if (@$status === 'Failed') {
				echo $data['message'] ; }
			else 
			{
				echo $response ;
			}
		}
}
?>
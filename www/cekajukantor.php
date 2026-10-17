<?php
ini_set('max_execution_time', 300); // 5 menit
set_time_limit(300);

include('initmfa.php'); 
include('koneksi.php');
include('datalogin.php');
 
$aju   = isset($_GET['aju']) ? $_GET['aju'] : '-';
$page = isset($_GET['page']) ? intval($_GET['page']) : 0;
$size = isset($_GET['size']) ? intval($_GET['size']) : 1;


if (!isset($user) || !$user || !isset($token)) {
    echo json_encode(array("status" => false, "message" => "Parameter npwp, user, atau token tidak valid"));
    exit;
}

$hostprd = "https://apis-gw.beacukai.go.id/v2/browse-service/v1/browse/dokumen-pabean-portal";
$bearer = $_SESSION['mfatoken'];

$params = array(
    "nomorIdentitas" =>  '0' . $npwp . '000000',
    "kodeJalur" => '',
    "namaPerusahaan" => '',
    "kodeDokumen" => '',
    "namaPpjk" => '',
    "kodeKantor" => '',
    "nomorAju" =>  $aju ,
    "nomorDaftar" => '',
    "page" => $page,
    "size" => $size,
    "idUser" => $user
);

$url = $hostprd . '?' . http_build_query($params);

$curl = curl_init(); 
curl_setopt_array($curl, array(
  CURLOPT_URL => $url,
  CURLOPT_SSL_VERIFYHOST => false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 180,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_HTTPHEADER => array(
    "Authorization: Bearer $bearer",
    "Content-Type: application/json"
  ),
));

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
  echo json_encode(array("status" => false, "message" => "cURL Error: $err"));
  exit;
}

$data = json_decode($response, true);

//echo $response // Decode JSON menjadi array
$data = json_decode($response, true);

// Ambil kodeKantor
$kodeKantor = $data['data'][0]['kodeKantor'];
$namaKantor = $data['data'][0]['namaKantorPendek'];
// Tampilkan
echo "$kodeKantor|$namaKantor" ;
//exit ; 

if (is_null($data)) {
    echo json_encode(array("status" => false, "message" => "Invalid JSON dari server", "raw" => substr($response, 0, 200)));
    exit;
}

if ($page === 0) {
    $total = isset($data['total']) ? $data['total'] : 0;
   // echo json_encode(array("status" => true, "total" => $total));
    exit;
}

$rows = isset($data['data']) ? $data['data'] : array();
$inserted = 0;

foreach ($rows as $row) {
    $tanggalDaftar = isset($row['tanggalDaftar']) ? DateTime::createFromFormat('d-m-Y', $row['tanggalDaftar']) : false;
    $tanggalRespon = isset($row['tanggalRespon']) ? DateTime::createFromFormat('d-m-Y H:i:s', $row['tanggalRespon']) : false;
    $tanggalBc11 = isset($row['tanggalBc11']) ? substr($row['tanggalBc11'], 10) : null;

    if (!$tanggalDaftar || !$tanggalRespon) continue;

    $tanggalDaftar = $tanggalDaftar->format('Y-m-d');
    $tanggalRespon = $tanggalRespon->format('Y-m-d H:i:s');

    $sql_delete = "DELETE FROM bc_ceisa_data WHERE nomorAju = ?";
    $stmt = $koneksi->prepare($sql_delete);
    if ($stmt) {
        $stmt->bind_param("s", $row['nomorAju']);
        $stmt->execute();
        $stmt->close();
    }

    $sql_insert = "INSERT INTO bc_ceisa_data (idHeader, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen, kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas, namaProses, idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb, nipAp, kodeJalur, flagHardcopy, flagHardcopySka, waktuTerimaHardcopy, jatuhtempoHardcopy, namaPpjk, nomorBc11, totalCount, tanggalBc11, subposBc11, posBc11, kodeProsesPerbaikan, namaProsesPerbaikan, flagSsm, idKepTujuan, nomorSkep, idRespon, namaRespon, nomorRespon, tanggalRespon, pdf, asalData, statusPkb) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $koneksi->prepare($sql_insert);
    if ($stmt) {
        $stmt->bind_param(str_repeat("s", 41),
            $row['idHeader'], $row['nomorAju'], $row['nomorDaftar'], $tanggalDaftar, $row['kodeDokumen'],
            $row['namaDokumen'], $row['kodeKantor'], $row['namaKantorPendek'], $row['namaEntitas'], $row['kodeProses'],
            $row['kodeEntitas'], $row['namaProses'], $row['idPerusahaan'], $row['namaPerusahaan'], $row['idPpjk'],
            $row['nipPfpd'], $row['nipPfpb'], $row['nipAp'], $row['kodeJalur'], $row['flagHardcopy'],
            $row['flagHardcopySka'], $row['waktuTerimaHardcopy'], $row['jatuhtempoHardcopy'], $row['namaPpjk'],
            $row['nomorBc11'], $row['totalCount'], $tanggalBc11, $row['subposBc11'], $row['posBc11'],
            $row['kodeProsesPerbaikan'], $row['namaProsesPerbaikan'], $row['flagSsm'], $row['idKepTujuan'],
            $row['nomorSkep'], $row['idRespon'], $row['namaRespon'], $row['nomorRespon'], $tanggalRespon,
            $row['pdf'], $row['asalData'], $row['statusPkb']
        );
        $stmt->execute();
        $stmt->close();
        $inserted++;
    }
}

$koneksi->close();
 /*
echo json_encode(array(
  "status" => true,
  "inserted" => $inserted,
  "count" => count($rows) // jumlah data mentah dari CEISA
));
*/

<?php
include('init.php'); 
include('koneksi.php');

// Get the 'bc' and 'page' parameters from the URL
@$bc = $_GET["bc"];
@$page = $_GET["page"];
if ($page == '') { $page = '1'; }

// Set the default timezone
date_default_timezone_set('Asia/Jakarta');

// Define the API URL and token
$hostprd = "https://apis-gw.beacukai.go.id/v2/browse-service/v1/browse/dokumen-pabean-portal?nomorIdentitas=010816064431000&kodeJalur=&namaPerusahaan=&kodeDokumen=$bc&&namaPpjk=&kodeKantor=&nomorAju=&nomorDaftar=&page=$page&size=1000&idUser=2f614626-7995-4f79-a113-228259d683ba";
$bearer = $_SESSION["token"];

// Initialize cURL
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

// Execute cURL request
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

// Handle errors in cURL request
if ($err) {
    echo "cURL Error #:" . $err;
    exit; // Stop script execution on error
}

// Decode JSON response data
$data = json_decode($response, true);

// Debug: Print the raw response to check the data structure
echo "<pre>";
//print_r($data);
echo "</pre>";

// Check if data is valid
if (isset($data['data']) && is_array($data['data'])) {
    // Iterate through each row of the response data
    foreach ($data['data'] as $row) {
        // Debugging: Print the current row
        //echo "<pre>";
      //  print_r($row);
        //echo "</pre>";

        // Convert tanggal format dd-mm-yyyy to yyyy-mm-dd
        $tanggalDaftar = DateTime::createFromFormat('d-m-Y', $row['tanggalDaftar']);
        $tanggalRespon = DateTime::createFromFormat('d-m-Y H:i:s', $row['tanggalRespon']);
        $tanggalBc11 = isset($row['tanggalBc11']) ? DateTime::createFromFormat('d-m-Y', $row['tanggalBc11']) : null;

        // Check if DateTime objects were created successfully
        if (!$tanggalDaftar || !$tanggalRespon || ($tanggalBc11 && !$tanggalBc11)) {
            echo "Invalid date format encountered, Skip AJU " . $row['nomorAju'] ;
            continue; // Skip this row if the date conversion fails
        }

        // Format the DateTime objects if they are valid
        $tanggalDaftar = $tanggalDaftar->format('Y-m-d');
        $tanggalRespon = $tanggalRespon->format('Y-m-d H:i:s');
        $tanggalBc11 = $tanggalBc11 ? $tanggalBc11->format('Y-m-d') : null;

        // Ensure that we have 41 values to bind
        if (count($row) != 41) {
            continue; // Skip if row does not match the expected column count
        }

        // Delete existing records with the same 'nomorAju'
        $sql_delete = "DELETE FROM bc_ceisa_data WHERE nomorAju = ?";
        $delete_stmt = $koneksi->prepare($sql_delete);
        $delete_stmt->bind_param("s", $row['nomorAju']);
        $delete_stmt->execute();
        $delete_stmt->close();

        // Construct the INSERT query
        $sql_insert = "
        INSERT INTO bc_ceisa_data 
        (idHeader, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen, kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas, namaProses, idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb, nipAp, kodeJalur, flagHardcopy, flagHardcopySka, waktuTerimaHardcopy, jatuhtempoHardcopy, namaPpjk, nomorBc11, totalCount, tanggalBc11, subposBc11, posBc11, kodeProsesPerbaikan, namaProsesPerbaikan, flagSsm, idKepTujuan, nomorSkep, idRespon, namaRespon, nomorRespon, tanggalRespon, pdf, asalData, statusPkb) 
        VALUES 
        ('" . $row['idHeader'] . "', 
        '" . $row['nomorAju'] . "', 
        '" . $row['nomorDaftar'] . "', 
        '" . $tanggalDaftar . "', 
        '" . $row['kodeDokumen'] . "', 
        '" . $row['namaDokumen'] . "', 
        '" . $row['kodeKantor'] . "', 
        '" . $row['namaKantorPendek'] . "', 
        '" . $row['namaEntitas'] . "', 
        '" . $row['kodeProses'] . "', 
        '" . $row['kodeEntitas'] . "', 
        '" . $row['namaProses'] . "', 
        '" . $row['idPerusahaan'] . "', 
        '" . $row['namaPerusahaan'] . "', 
        '" . $row['idPpjk'] . "', 
        '" . $row['nipPfpd'] . "', 
        '" . $row['nipPfpb'] . "', 
        '" . $row['nipAp'] . "', 
        '" . $row['kodeJalur'] . "', 
        '" . $row['flagHardcopy'] . "', 
        '" . $row['flagHardcopySka'] . "', 
        '" . $row['waktuTerimaHardcopy'] . "', 
        '" . $row['jatuhtempoHardcopy'] . "', 
        '" . $row['namaPpjk'] . "', 
        '" . $row['nomorBc11'] . "', 
        '" . $row['totalCount'] . "', 
        '" . $tanggalBc11 . "', 
        '" . $row['subposBc11'] . "', 
        '" . $row['posBc11'] . "', 
        '" . $row['kodeProsesPerbaikan'] . "', 
        '" . $row['namaProsesPerbaikan'] . "', 
        '" . $row['flagSsm'] . "', 
        '" . $row['idKepTujuan'] . "', 
        '" . $row['nomorSkep'] . "', 
        '" . $row['idRespon'] . "', 
        '" . $row['namaRespon'] . "', 
        '" . $row['nomorRespon'] . "', 
        '" . $tanggalRespon . "', 
        '" . $row['pdf'] . "', 
        '" . $row['asalData'] . "', 
        '" . $row['statusPkb'] . "')
        ";

        // Execute the query
        if (!$koneksi->query($sql_insert)) {
            echo "Error executing query: " . $koneksi->error;
            exit; // Stop script execution if execution fails
        }
    }

    echo "Data berhasil disimpan.";

} else {
    echo "Data tidak valid atau tidak ditemukan.";
}

$koneksi->close();
?>

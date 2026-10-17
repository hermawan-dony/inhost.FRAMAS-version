<?php
include('initmfa.php');
// Hapus operator @ (error suppression) karena tidak selalu disarankan
$a = isset($_GET["a"]) ? $_GET["a"] : '';
if ($a == '') {
    exit;
}

// ----------------------------------------------------
// FUNGSI BANTUAN UNTUK AMAN MENGAMBIL NILAI DARI ARRAY
// Agar kode ini kompatibel di PHP 5, 7, dan 8
// Menggantikan operator Null Coalescing (??) yang baru ada di PHP 7
// ----------------------------------------------------
function get_safe_value($array, $key, $default = '') {
    // Periksa apakah array-nya valid dan key-nya ada
    if (is_array($array) && array_key_exists($key, $array)) {
        return $array[$key];
    }
    return $default;
}
// ----------------------------------------------------


// ------ HEADER ------------------------------
$hostprd = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/getByNoHostBlTanggalAndKodeKantor?noHostBl={$a}";

// Menggunakan Curly Braces untuk semua interpolasi variabel
$bearer = get_safe_value($_SESSION, "mfatoken");
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
        "Authorization: Bearer {$bearer}",
        "Content-Type: application/json"
    ],
]);
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

$idHeader = ''; // Inisialisasi variabel untuk menghindari warning jika data[0] gagal

if ($err) {
    echo "cURL Error #:" . $err;
} else {
    // Mengubah JSON menjadi array asosiatif PHP
    $data = json_decode($response, true);
    
    // Gunakan fungsi get_safe_value untuk mengambil data agar aman dari error/warning
    // Kita ambil data[0] jika ada, jika tidak, pakai array kosong agar get_safe_value aman
    $data_header = get_safe_value($data, 'data');
    $data_item = get_safe_value($data_header, 0, []); 

    $kodeKantor = get_safe_value($data_item, 'kodeKantor');
    $nomorDaftar = get_safe_value($data_item, 'nomorDaftar');
    $tanggalDaftar = get_safe_value($data_item, 'tanggalDaftar');
    $nomorPos = get_safe_value($data_item, 'nomorPos');
    $nomorBl = get_safe_value($data_item, 'nomorBl');
    $tanggalBl = get_safe_value($data_item, 'tanggalBl');
    $nomorHostBl = get_safe_value($data_item, 'nomorHostBl');
    $tanggalHostBl = get_safe_value($data_item, 'tanggalHostBl');
    $namaPengirim = get_safe_value($data_item, 'namaPengirim');
    
    // str_replace dilakukan setelah dipastikan nilainya ada
    $alamatPengirimRaw = get_safe_value($data_item, 'alamatPengirim');
    $alamatPengirim = str_replace("\n", ' ', $alamatPengirimRaw);
    
    $berat = get_safe_value($data_item, 'berat');
    $jenisKemasan = get_safe_value($data_item, 'jenisKemasan');
    $jumlahKemasan = get_safe_value($data_item, 'jumlahKemasan');
    $jumlahContainer = get_safe_value($data_item, 'jumlahContainer');
    $motherVessel = get_safe_value($data_item, 'motherVessel');
    $kodePelabuhanAsal = get_safe_value($data_item, 'kodePelabuhanAsal');
    $kodePelabuhanBongkar = get_safe_value($data_item, 'kodePelabuhanBongkar');
    $kodePelabuhanTransit = get_safe_value($data_item, 'kodePelabuhanTransit');
    $kodePelabuhanAkhir = get_safe_value($data_item, 'kodePelabuhanAkhir');
    $nomorVoyageHeader = get_safe_value($data_item, 'nomorVoyage'); // Diberi nama berbeda agar tidak tertimpa variabel nomorVoyage di detail
    $namaPelabuhanAsal = get_safe_value($data_item, 'namaPelabuhanAsal');
    $namaPelabuhanBongkar = get_safe_value($data_item, 'namaPelabuhanBongkar');
    $namaPelabuhanTransit = get_safe_value($data_item, 'namaPelabuhanTransit');
    $namaPelabuhanAkhir = get_safe_value($data_item, 'namaPelabuhanAkhir');
    $idHeader = get_safe_value($data_item, 'idHeader'); // Ambil idHeader untuk request detail

    // Menampilkan hasil dengan format 2 kolom yang dipisahkan dengan TAB
    echo "nomorDaftar\t{$nomorDaftar}\n";
    echo "tanggalDaftar\t{$tanggalDaftar}\n";
    echo "kodeKantor\t{$kodeKantor}\n";
    echo "nomorPos\t{$nomorPos}\n";
    echo "nomorBl\t{$nomorBl}\n";
    echo "tanggalBl\t{$tanggalBl}\n";
    echo "nomorHostBl\t{$nomorHostBl}\n";
    echo "tanggalHostBl\t{$tanggalHostBl}\n";
    echo "namaPengirim\t{$namaPengirim}\n";
    echo "alamatPengirim\t{$alamatPengirim}\n";
    echo "berat\t{$berat}\n";
    echo "jenisKemasan\t{$jenisKemasan}\n";
    echo "jumlahKemasan\t{$jumlahKemasan}\n";
    echo "jumlahContainer\t{$jumlahContainer}\n";
    echo "motherVessel\t{$motherVessel}\n";
    echo "kodePelabuhanAsal\t{$kodePelabuhanAsal}\n";
    echo "kodePelabuhanBongkar\t{$kodePelabuhanBongkar}\n";
    echo "kodePelabuhanTransit\t{$kodePelabuhanTransit}\n";
    echo "kodePelabuhanAkhir\t{$kodePelabuhanAkhir}\n";
    echo "nomorVoyage\t{$nomorVoyageHeader}\n"; // Tampilkan hasil dari header
    echo "namaPelabuhanAsal\t{$namaPelabuhanAsal}\n";
    echo "namaPelabuhanBongkar\t{$namaPelabuhanBongkar}\n";
    echo "namaPelabuhanTransit\t{$namaPelabuhanTransit}\n";
    echo "namaPelabuhanAkhir\t{$namaPelabuhanAkhir}\n";
}

// ------ DETAIL ------------------------------
if ($idHeader == '') {
    exit;
}
$hostprd = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/DatamanifesHeader/{$idHeader}";

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
        "Authorization: Bearer {$bearer}",
        "Content-Type: application/json"
    ],
]);
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    echo "cURL Error #:" . $err;
} else {
    // Mengubah JSON menjadi array asosiatif PHP
    $data = json_decode($response, true);
    
    // Gunakan fungsi get_safe_value
    $data_detail = get_safe_value($data, 'data', []);

    // Mengambil data yang diminta
    $nomorVoyage = get_safe_value($data_detail, 'nomorVoyage');
    $tanggalTiba = get_safe_value($data_detail, 'tanggalTiba');

    echo "tanggalTiba\t{$tanggalTiba}\n";
    echo "nomorVoyage_detail\t{$nomorVoyage}\n"; // Diberi nama beda agar jelas sumbernya
}
?>
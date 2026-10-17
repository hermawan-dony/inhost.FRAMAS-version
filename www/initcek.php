<?php
include('datalogin.php'); 
 
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Fungsi untuk memeriksa apakah token sudah expired
function is_token_expired() {
    // Set batas waktu kadaluarsa token dalam detik (600 detik = 10 menit)
    $expiry_time = 6; 
 
    // Cek apakah token dan waktu pembuatan token ada di sesi
    if (isset($_SESSION["token"]) && isset($_SESSION["token_created_at"])) {
        $elapsed_time = time() - $_SESSION["token_created_at"];
        return $elapsed_time >= $expiry_time;
    }
    return true; // Jika tidak ada token, anggap expired
}

if (is_token_expired()) { 
    // Token sudah expired atau belum ada, lakukan request token baru
    $curl = curl_init();
    $hostdev = "https://nlehub-dev.kemenkeu.go.id/auth-amws/v1/user/login";
    $hostprd = "https://apis-gw.beacukai.go.id/nle-oauth/v1/user/login";  
    $dataarr = [
        "username" => "$username",
        "password" => "$password" 
    ];
	 
    $sendrest = json_encode($dataarr, true);
    curl_setopt_array($curl, [
        CURLOPT_URL => $hostprd,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,  
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $sendrest,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
    ]);
    $response = curl_exec($curl);
	echo $response ;
    $response = json_decode($response, true);
    $err = curl_error($curl);
    @$item = $response["item"];
    
    // Simpan token dan waktu pembuatan token dalam sesi
    $_SESSION["token"] = $item["access_token"];
    $_SESSION["token_created_at"] = time(); // Simpan waktu token dihasilkan
     echo 'Token NEW : ' .  $item["access_token"] . '<hr>'; 
    curl_close($curl);

    if ($err) {
        echo "cURL Error #:" . $err;
    }
} else {
    // Token masih valid, gunakan token yang sudah ada di sesi
    echo 'Token masih valid, gunakan token yang sudah ada di sesi : ' .  $_SESSION["token"];
}
?>
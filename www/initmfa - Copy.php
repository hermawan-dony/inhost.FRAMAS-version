<?php
include('datalogin.php');
require_once 'GoogleAuthenticator.php';

// Generate kode OTP MFA
$ga = new PHPGangsta_GoogleAuthenticator();
$otp = $ga->getCode($secretmfa);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Fungsi untuk memeriksa apakah mfatoken sudah expired
function is_mfatoken_expired() {
    // Set batas waktu kadaluarsa mfatoken dalam detik (600 detik = 10 menit)
    $expiry_time = 600; 
 
    // Cek apakah mfatoken dan waktu pembuatan mfatoken ada di sesi
    if (isset($_SESSION["mfatoken"]) && isset($_SESSION["mfatoken_created_at"])) {
        $elapsed_time = time() - $_SESSION["mfatoken_created_at"];
        return $elapsed_time >= $expiry_time;
    }
    return true; // Jika tidak ada mfatoken, anggap expired
}

if (is_mfatoken_expired()) {
    // mfatoken sudah expired atau belum ada, lakukan request mfatoken baru
    $curl = curl_init();
 
 //   $hostprd = "https://apis-gw.beacukai.go.id/nle-oauth/v1/user/login";
	$hostprd = "https://apis-gw.beacukai.go.id/v2/authws/mfa/otp-login";
 
    $dataarr = [
        "username" => "$username",
        "password" => "$password",
		"otp" => "$otp",
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
	//echo $response ;
    $response = json_decode($response, true);
    $err = curl_error($curl);
    @$item = $response["item"];
    
    // Simpan mfatoken dan waktu pembuatan mfatoken dalam sesi
    $_SESSION["mfatoken"] = $item["access_token"];
    $_SESSION["mfatoken_created_at"] = time();  
    //echo 'mfatoken NEW : ' .  $item["access_token"] . '<hr>'; 
    curl_close($curl);

    if ($err) {
        echo "cURL Error #:" . $err;
    }
} else {
   // mfatoken masih valid, gunakan mfatoken yang sudah ada di sesi
   //echo 'mfatoken masih valid, gunakan mfatoken yang sudah ada di sesi : ' .  $_SESSION["mfatoken"];
}
?>
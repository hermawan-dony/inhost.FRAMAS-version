<?php
include('datalogin.php');
 
    // Token sudah expired atau belum ada, lakukan request token baru
    $curl = curl_init();
    $hostdev = "https://nlehub-dev.kemenkeu.go.id/auth-amws/v1/user/login";
    $hostprd = "https://apis-gw.beacukai.go.id/nle-oauth/v2/user/login";
	 $hostprd = "https://apis-gw.beacukai.go.id/v2/authws/mfa/otp-login";
    $dataarr = [
        "username" => "maikelwawoh",
		"otp" => "663752",
        "password" => "KOQ2NjQE+Wv2mI+ieyLGmT9W4H8WPmgJyow5NX9Uc+d3COKXgM/g6U1SKjP00EY4S831KTkDvbRSbIQ83VMq7yIQoIU/OEDcX281JgwvNG8nvyXeCcaBjIkMvNU+qz8SX9en5qT/koHowXwKjWe4d0jOsTl8TXwkKC8UbTyid+M="
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
	
		echo  $response ;
		
    $response = json_decode($response, true);
    $err = curl_error($curl);
	

    @$item = $response["item"];
    
    // Simpan token dan waktu pembuatan token dalam sesi
    $_SESSION["token"] = $item["access_token"];
    $_SESSION["token_created_at"] = time(); // Simpan waktu token dihasilkan
   echo 'Token NEW : ' .  $item["access_token"] . '<hr>'; 
   
?>

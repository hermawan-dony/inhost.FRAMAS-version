<?php
/**
 * CEISA 4.0 Multi-Company Token Manager (init.php)
 * Mengelola token login OAuth CEISA 4.0 terisolasi per perusahaan.
 */
require_once __DIR__ . '/datalogin.php'; 
 
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$cmp_key = !empty($cmp_id) ? $cmp_id : 'default';
$tokenCacheFile = sys_get_temp_dir() . '/ceisa_nle_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $cmp_key) . '.json';

// Fungsi untuk memeriksa apakah token perusahaan masih valid (cache session & file shared)
if (!function_exists('get_cached_nle_token')) {
    function get_cached_nle_token($cmp_key, $tokenCacheFile) {
        $expiry_time = 3000; // 50 menit (Token CEISA NLE berlaku 60 menit)

        // 1. Cek memory session
        if (!empty($_SESSION["token_" . $cmp_key]) && !empty($_SESSION["token_created_at_" . $cmp_key])) {
            if ((time() - (int)$_SESSION["token_created_at_" . $cmp_key]) < $expiry_time) {
                return $_SESSION["token_" . $cmp_key];
            }
        }

        // 2. Cek shared cache file lintas session/worker
        if (file_exists($tokenCacheFile)) {
            $content = @file_get_contents($tokenCacheFile);
            if ($content) {
                $cache = @json_decode($content, true);
                if (!empty($cache['token']) && !empty($cache['created_at'])) {
                    if ((time() - (int)$cache['created_at']) < $expiry_time) {
                        $_SESSION["token_" . $cmp_key] = $cache['token'];
                        $_SESSION["token_created_at_" . $cmp_key] = (int)$cache['created_at'];
                        $_SESSION["token"] = $cache['token'];
                        return $cache['token'];
                    }
                }
            }
        }

        return null;
    }
}

$activeToken = get_cached_nle_token($cmp_key, $tokenCacheFile);

if (empty($activeToken)) {
    // Token sudah expired atau belum ada untuk perusahaan ini, minta token baru
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
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $sendrest,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
    ]);
    
    $response = curl_exec($curl);
    $response_arr = json_decode($response, true);
    $err = curl_error($curl);
    curl_close($curl);
    
    @$item = $response_arr["item"];
    if (!empty($item["access_token"])) {
        $newToken = $item["access_token"];
        $now = time();
        $_SESSION["token_" . $cmp_key] = $newToken;
        $_SESSION["token_created_at_" . $cmp_key] = $now;
        $_SESSION["token"] = $newToken; // backward compatibility

        // Simpan ke shared cache file agar tidak request login ulang
        @file_put_contents($tokenCacheFile, json_encode([
            'token'      => $newToken,
            'created_at' => $now,
            'cmp_key'    => $cmp_key
        ]));
    }

    if ($err) {
        error_log("CEISA NLE OAuth cURL Error: " . $err);
    }
} else {
    // Token perusahaan masih valid
    $_SESSION["token"] = $activeToken;
    $_SESSION["token_" . $cmp_key] = $activeToken;
}
?>
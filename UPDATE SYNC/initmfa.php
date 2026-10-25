<?php
/**
 * CEISA 4.0 Multi-Company MFA Authentication (initmfa.php)
 * Mengelola token login OAuth MFA OTP CEISA 4.0 terisolasi per perusahaan.
 */
require_once __DIR__ . '/datalogin.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$cmp_key = !empty($cmp_id) ? $cmp_id : 'default';

// Fungsi untuk memeriksa apakah mfatoken perusahaan masih valid (cache session & file shared)
$tokenCacheFile = sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $cmp_key) . '.json';

if (!function_exists('get_cached_mfatoken')) {
    function get_cached_mfatoken($cmp_key, $tokenCacheFile) {
    $expiry_time = 480; // 8 menit (CEISA token berlaku 10 menit, pakai margin aman 8 menit)

    // 1. Cek memory session
    if (!empty($_SESSION["mfatoken_" . $cmp_key]) && !empty($_SESSION["mfatoken_created_at_" . $cmp_key])) {
        if ((time() - $_SESSION["mfatoken_created_at_" . $cmp_key]) < $expiry_time) {
            return $_SESSION["mfatoken_" . $cmp_key];
        }
    }

    // 2. Cek shared cache file lintas session/worker
    if (file_exists($tokenCacheFile)) {
        $content = @file_get_contents($tokenCacheFile);
        if ($content) {
            $cache = @json_decode($content, true);
            if (!empty($cache['token']) && !empty($cache['created_at'])) {
                if ((time() - (int)$cache['created_at']) < $expiry_time) {
                    $_SESSION["mfatoken_" . $cmp_key] = $cache['token'];
                    $_SESSION["mfatoken_created_at_" . $cmp_key] = (int)$cache['created_at'];
                    return $cache['token'];
                }
            }
        }
    }

    return null;
    }
}

$activeToken = get_cached_mfatoken($cmp_key, $tokenCacheFile);

// Validasi Wajib: Jika ID User CEISA belum diset di Kelola Perusahaan, InitMFA dilarang berjalan
if (empty($id_user_ceisa) && empty($user) && (empty($active_company) || empty($active_company['id_user_ceisa']))) {
    $activeToken = null;
    $mfaToken = null;
    $activeMfaToken = null;
    return;
}

if (empty($activeToken)) {
    // Proteksi Anti-WAF & Anti-Blokir IP: Gunakan file lock mutex agar request concurrent tidak menembak login berkali-kali
    $lockFile = $tokenCacheFile . '.lock';
    $fpLock = @fopen($lockFile, 'c+');
    if ($fpLock && @flock($fpLock, LOCK_EX)) {
        // Double-check cache setelah lock berhasil didapatkan (barangkali proses lain baru saja selesai login)
        $activeToken = get_cached_mfatoken($cmp_key, $tokenCacheFile);

        if (empty($activeToken)) {
            // Generate kode OTP MFA jika secret key tersedia
            $otp = '';
            if (!empty($secretmfa)) {
                if (file_exists(__DIR__ . '/GoogleAuthenticator.php')) {
                    require_once __DIR__ . '/GoogleAuthenticator.php';
                    $ga = new PHPGangsta_GoogleAuthenticator();
                    $otp = $ga->getCode($secretmfa);
                }
            }

            $curl = curl_init();
            $hostprd = "https://apis-gw.beacukai.go.id/v2/authws/mfa/otp-login";
         
            $dataarr = [
                "username" => $username,
                "password" => $password,
                "otp"      => (string)$otp,
            ];
         
            $sendrest = json_encode($dataarr);
            curl_setopt_array($curl, [
                CURLOPT_URL            => $hostprd,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,  
                CURLOPT_RETURNTRANSFER => true, 
                CURLOPT_ENCODING       => "gzip, deflate",
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => $sendrest,
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "Accept: application/json, text/plain, */*",
                    "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
                    "Origin: https://portal.beacukai.go.id",
                    "Referer: https://portal.beacukai.go.id/",
                    "Sec-Fetch-Dest: empty",
                    "Sec-Fetch-Mode: cors",
                    "Sec-Fetch-Site: same-site",
                    "sec-ch-ua: \"Chromium\";v=\"130\", \"Google Chrome\";v=\"130\", \"Not?A_Brand\";v=\"99\"",
                    "sec-ch-ua-mobile: ?0",
                    "sec-ch-ua-platform: \"Windows\"",
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
                ],
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $response_arr = json_decode($response, true);
            $err = curl_error($curl);
            curl_close($curl);

            @$item = $response_arr["item"];
            if (!empty($item["access_token"])) {
                $newToken = $item["access_token"];
                $now = time();
                $_SESSION["mfatoken_" . $cmp_key] = $newToken;
                $_SESSION["mfatoken_created_at_" . $cmp_key] = $now;  
                $activeToken = $newToken;

                // Simpan ke shared cache file agar tidak request login ulang
                @file_put_contents($tokenCacheFile, json_encode([
                    'token'      => $newToken,
                    'created_at' => $now,
                    'cmp_key'    => $cmp_key
                ]));
            }

            if ($err) {
                error_log("CEISA MFA cURL Error: " . $err);
            }
        }

        @flock($fpLock, LOCK_UN);
        @fclose($fpLock);
    }
}

// Pembedaan eksplisit variabel token khusus MFA (terisolasi dari token NLE init biasa)
$mfaToken = $activeToken;
$activeMfaToken = $activeToken;
?>
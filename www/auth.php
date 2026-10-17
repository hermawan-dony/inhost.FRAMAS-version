<?php
date_default_timezone_set("Asia/Jakarta");

// Fungsi untuk mendekode Base64 URL-safe
function base64UrlDecode($data) {
    $data .= str_repeat('=', (4 - strlen($data) % 4) % 4); // Tambahkan padding jika perlu
    return base64_decode(strtr($data, '-_', '+/'));
}

// Fungsi untuk mendekode Base32
function base32Decode($base32) {
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $decoded = '';

    foreach (str_split(strtoupper($base32)) as $char) {
        if (($index = strpos($base32Chars, $char)) === false) {
            throw new Exception("Invalid Base32 character: $char");
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    foreach (str_split($bits, 8) as $byte) {
        $decoded .= chr(bindec($byte));
    }

    return $decoded;
}

// Fungsi untuk menghasilkan TOTP
function generateTOTP($secret, $timeStep = 30, $digits = 6) {
    $time = floor(time() / $timeStep);

    // Decode secret key dari Base32
    $decodedKey = base32Decode($secret);

    // Konversi waktu ke format byte array
    $timeBytes = pack('N*', 0) . pack('N*', $time);
    $timeBytes = substr($timeBytes, -8);

    // HMAC menggunakan SHA1
    $hash = hash_hmac('sha1', $timeBytes, $decodedKey, true);

    // Ambil offset dari byte terakhir hash
    $offset = ord(substr($hash, -1)) & 0x0F;

    // Ambil 4 byte mulai dari offset
    $binary = (ord($hash[$offset]) & 0x7F) << 24 |
              (ord($hash[$offset + 1]) & 0xFF) << 16 |
              (ord($hash[$offset + 2]) & 0xFF) << 8 |
              (ord($hash[$offset + 3]) & 0xFF);

    // Ambil digit terakhir sebanyak $digits
    return str_pad($binary % pow(10, $digits), $digits, '0', STR_PAD_LEFT);
}

// URL otpauth-migration
$url = 'otpauth-migration://offline?data=Ck8KFGNmMk0ycG4zcWxmUkloQ1dEU0dEEgttYWlrZWx3YXdvaBoPcG9ydGFsLWJlYWN';

// Ekstrak data dari URL
parse_str(parse_url($url, PHP_URL_QUERY), $query);
$encodedData = $query['data'];

// Decode Base64 dari data
$decodedData = base64UrlDecode($encodedData);

// Secret Key (contoh: "cf2M2pn3qlfRIhCWDSGD", ini dapat diambil dari hasil decode)
$secret = 'cf2M2pn3qlfRIhCWDSGD'; // Sesuaikan dengan hasil decode yang sebenarnya

// Generate OTP
$otp = generateTOTP($secret);
echo "Generated OTP: " . $otp . "\n";
?>

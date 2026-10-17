<?php
// --- Konfigurasi Login Mas Wawan ---
$loginUrl = 'https://adidas.trustrace.com/api/v1/auth/login'; // Endpoint API login biasanya beda dengan URL form
$targetUrl = 'https://adidas.trustrace.com/product-segregation/transaction';
$cookieFile = 'cookie_trustrace.txt'; // File buat nyimpen session

$payload = json_encode([
    'email' => 'pandhu.utomo@framas.com',
    'password' => 'P4ndhu@frm'
]);

// --- STEP 1: Proses Login (Dapetin Cookie) ---
$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIEJAR, realpath($cookieFile)); // Simpan cookie di sini
$response = curl_exec($ch);

// Cek kalau login berhasil (Mas bisa tambahkan logika pengecekan response di sini)
if (curl_errno($ch)) {
    echo 'Aduh Mas, ada error pas login: ' . curl_error($ch);
}

// --- STEP 2: Baca Halaman Target ---
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_POST, false); // Ganti jadi GET
curl_setopt($ch, CURLOPT_COOKIEFILE, realpath($cookieFile)); // Pakai cookie yang tadi
$html = curl_exec($ch);

if (!curl_errno($ch)) {
    echo "Hore! Berhasil baca datanya, Mas Wawan! ✅\n";
    echo $html; // Ini isi HTML-nya
}

curl_close($ch);
?>
<?php
echo "<h1>Tes 2: Mencoba koneksi ke endpoint API Gemini</h1>";

// PASTIKE MAS WAWAN NGLEBETAKEN API KEY ENGGAL ING MBRIKI
$apiKey = "AIzaSyAV5l9avm76E0hI3TvrmhPoQCRiVi3biNw";
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

echo "<hr>";

if ($curlError) {
    echo "<h2>❌ KONEKSI GAGAL</h2>";
    echo "<p><strong>Pesan Error cURL:</strong> " . htmlspecialchars($curlError) . "</p>";
    echo "<p><strong>Kesimpulan:</strong> Masalah tasih wonten ing konfigurasi SSL (cacert.pem), kemungkinan firewall, utawi masalah jaringan sanesipun ingkang spesifik mblokir koneksi ke 'generativelanguage.googleapis.com'.</p>";
} else {
    echo "<h2>✅ KONEKSI BERHASIL</h2>";
    echo "<p><strong>Kode Status HTTP:</strong> " . $httpCode . "</p>";
    echo "<p><strong>Jawaban dari Server:</strong></p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";

    if ($httpCode === 200) {
        echo "<p><strong>Kesimpulan:</strong> Koneksi lan API Key sampun leres! Masalahipun wonten ing format data POST ing skrip utama.</p>";
    } elseif ($httpCode === 400 || $httpCode === 403) {
        echo "<p><strong>Kesimpulan:</strong> Koneksi berhasil, nanging API Key mboten valid utawi API service dereng aktif. Mangga priksa malih Langkah 1 & 2 saking jawaban Ay sak derengipun (Damel Ulang Key & Enable API).</p>";
    }
}

echo "<hr>";
?>
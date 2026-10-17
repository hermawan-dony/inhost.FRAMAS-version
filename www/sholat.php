<?php

function getJadwalSholatBekasi() {
    // ID Kota Bekasi di API myquran.com (bisa berubah, jadi perlu verifikasi)
    // Berdasarkan daftar kota di API, Bekasi ada di ID 1402 (Kota Bekasi) dan 1403 (Kab. Bekasi)
    // Mari kita pakai Kota Bekasi (1402)
    $id_kota_bekasi = 1402; 
    
    // Dapatkan tanggal hari ini dalam format Y/m/d
    $tanggal_hari_ini = date('Y/m/d');

    // URL API untuk jadwal sholat
    // Format: https://api.myquran.com/v1/sholat/jadwal/{id_kota}/{tanggal}
    $api_url = "https://api.myquran.com/v1/sholat/jadwal/{$id_kota_bekasi}/{$tanggal_hari_ini}";

    // Inisialisasi cURL untuk mengambil data dari API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Hati-hati dengan ini di produksi, gunakan hanya jika perlu
    $response = curl_exec($ch);
    
    // Cek jika ada error cURL
    if (curl_errno($ch)) {
        echo 'Error cURL: ' . curl_error($ch);
        return false;
    }
    
    curl_close($ch);

    // Decode JSON response
    $data = json_decode($response, true);

    // Cek apakah data berhasil didapatkan dan tidak ada error dari API
    if ($data && $data['status'] === true && isset($data['data']['jadwal'])) {
        return $data['data']['jadwal'];
    } else {
        echo 'Gagal mengambil data jadwal sholat. Pesan API: ' . ($data['message'] ?? 'Tidak diketahui') . "<br>";
        return false;
    }
}

// Panggil fungsi untuk mendapatkan jadwal sholat
$jadwal_sholat = getJadwalSholatBekasi();

// Tampilkan hasil
if ($jadwal_sholat) {
    echo "<h1>Jadwal Sholat Kota Bekasi Hari Ini (" . $jadwal_sholat['tanggal'] . ")</h1>";
    echo "<table border='1' cellpadding='10' cellspacing='0'>";
    echo "<tr><td>Imsak</td><td>" . $jadwal_sholat['imsak'] . "</td></tr>";
    echo "<tr><td>Subuh</td><td>" . $jadwal_sholat['subuh'] . "</td></tr>";
    echo "<tr><td>Terbit</td><td>" . $jadwal_sholat['terbit'] . "</td></tr>";
    echo "<tr><td>Dhuha</td><td>" . $jadwal_sholat['dhuha'] . "</td></tr>";
    echo "<tr><td>Dzuhur</td><td>" . $jadwal_sholat['dzuhur'] . "</td></tr>";
    echo "<tr><td>Ashar</td><td>" . $jadwal_sholat['ashar'] . "</td></tr>";
    echo "<tr><td>Maghrib</td><td>" . $jadwal_sholat['maghrib'] . "</td></tr>";
    echo "<tr><td>Isya</td><td>" . $jadwal_sholat['isya'] . "</td></tr>";
    echo "</table>";
    echo "<p>Sumber: api.myquran.com</p>";
} else {
    echo "<p>Tidak dapat menampilkan jadwal sholat untuk saat ini.</p>";
}

?>
<?php 

// Set default timezone
date_default_timezone_set('Asia/Jakarta'); // Ganti sesuai lokasi Anda

// Ambil nilai pencarian dari URL
$cari = isset($_GET["cari"]) ? urlencode($_GET["cari"]) : '';

// URL API dengan parameter pencarian
$url = "https://api.insw.go.id/api/cms/v1/hscode?keyword=$cari&size=300&from=0";

// Token Authorization baru (HARDCODED dalam contoh ini, idealnya disimpan aman)
$token = "*";
$apiKey = "x6Cd3SF1rTBEYJJGjj78hWKkDI02bjPf";

// Header lengkap
$headers = [
    "Accept: application/json, text/plain, */*",
    "Accept-Encoding: gzip, deflate, br, zstd",
    "Accept-Language: en-US,en;q=0.9",
    "Authorization: Basic $token",
    "x-insw-key: $apiKey",
    "Connection: keep-alive",
    "Host: api.insw.go.id",
    "Origin: https://insw.go.id",
    "Referer: https://insw.go.id/",
    "sec-ch-ua: \"Not;A=Brand\";v=\"99\", \"Google Chrome\";v=\"139\", \"Chromium\";v=\"139\"",
    "sec-ch-ua-mobile: ?0",
    "sec-ch-ua-platform: \"Windows\"",
    "Sec-Fetch-Dest: empty",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Site: same-site",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36"
];

// CURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_ENCODING, "");

// Eksekusi
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Cek Error
if ($httpcode != 200) {
    die("Gagal ambil data. Kode: $httpcode<br>Respon: $response");
}

// Parse JSON
$data = json_decode($response, true);

// Validasi data
if (!isset($data['data'][0]['result'])) {
    die("Data tidak ditemukan");
}

// Tampilkan hasil
$results = $data['data'][0]['result'];
 

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HS Code Data</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px; /* Smaller font */
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px; /* Smaller font in table */
        }

        th, td {
            border: 1px solid #ddd;
            padding: 6px 12px; /* Reduced padding for tighter rows */
            text-align: left;
        }

        th {
            background-color: #4CAF50;
            color: white;
        }

        tr:hover {
            background-color: #f9f9f9;
            cursor: pointer;
        }

        .search-container {
            margin-bottom: 20px;
        }

        .search-box {
            padding: 8px;
            width: 300px;
            font-size: 14px;
            margin-right: 10px;
        }

        .search-btn {
            padding: 8px 16px;
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s ease;
        }

        .search-btn:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body> 
<!-- Search Bar -->
<div class="search-container">
    <form method="GET">
        <input type="text" name="cari" class="search-box" placeholder="Cari HS Code..." value="<?= htmlspecialchars($cari) ?>">
        <button type="submit" class="search-btn">Cari</button>
    </form>
</div>

<table style="margin-top:-15px;">
    <thead>
        <tr>
            <th>HS Code</th>
            <th>Uraian ID</th>
            <th>Uraian EN</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($results as $item) : ?>
            <?php 
                // Ambil HS Code dan hapus "."
                $hsCode = isset($item['_source']['hs_code']) ? str_replace('.', '', $item['_source']['hs_code']) : 'N/A';
                $uraianId = isset($item['_source']['uraian_id']) ? $item['_source']['uraian_id'] : 'N/A';
                $uraianEn = isset($item['_source']['uraian_en']) ? $item['_source']['uraian_en'] : 'N/A';

                // Cek apakah HS Code memiliki 8 karakter setelah replace
                $isEightChar = (strlen($hsCode) === 8);
            ?>
            <tr>
                <td>
                    <?php if ($isEightChar) : ?>
                        <a href="#" onclick="openWindow('<?= $hsCode ?>')"><?= $hsCode ?></a>
                    <?php else: ?>
                        <?= $hsCode ?>
                    <?php endif; ?>
                </td>
                <td><?= $uraianId ?></td>
                <td><?= $uraianEn ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
    function openWindow(hsCode) {
        // Menghitung posisi tengah layar
        var width = 800;
        var height = 200;
        var left = (screen.width / 2) - (width / 2);
        var top = (screen.height / 2) - (height / 2);

        // Membuka jendela baru dengan detail HS Code, di tengah layar dan tanpa ikon Internet Explorer
        var newWindow = window.open('inswdtl.php?hs=' + hsCode, 'HS Code Details', 'width=' + width + ',height=' + height + ',resizable=yes,location=no,menubar=no,scrollbars=yes,status=no,toolbar=no,left=' + left + ',top=' + top);
        
        // Fokus pada jendela baru
        if (newWindow) {
            newWindow.focus(); // Secara otomatis mengatur fokus pada jendela baru
        }
    }
</script>

</body>
</html>

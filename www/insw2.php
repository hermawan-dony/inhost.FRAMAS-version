<?php 

// Set default timezone
date_default_timezone_set('Asia/Jakarta'); // Ganti sesuai lokasi Anda

$url = "https://api.insw.go.id/api/cms/v1/hscode?keyword=plastik&size=200&from=0";

$headers = [
    "Accept: application/json, text/plain, */*",
    "Accept-Encoding: gzip, deflate, br, zstd",
    "Accept-Language: en-US,en;q=0.9",
    "Connection: keep-alive",
    "Host: api.insw.go.id",
    "Origin: https://insw.go.id",
    "Referer: https://insw.go.id/",
    "sec-ch-ua: \"Chromium\";v=\"134\", \"Not:A-Brand\";v=\"24\", \"Google Chrome\";v=\"134\"",
    "sec-ch-ua-mobile: ?0",
    "sec-ch-ua-platform: \"Windows\"",
    "Sec-Fetch-Dest: empty",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Site: same-site",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
    "x-insw-key: x6Cd3SF1rTBEYJJGjj78hWKkDI02bjPf"
];

// Initialize cURL session
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_ENCODING, ""); // Enables gzip, deflate, etc.

// Execute cURL request
$response = curl_exec($ch);

// Check for errors
if (curl_errno($ch)) {
    echo 'Curl error: ' . curl_error($ch);
} else {
    // Print response
  //  echo $response;
}

// Close cURL session
curl_close($ch);

// Decode JSON ke array
$data = json_decode($response, true);

// Cek apakah ada data yang valid
if (!isset($data['data'][0]['result'])) {
    die("Data tidak ditemukan");
}

$results = $data['data'][0]['result'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HS Code Data</title>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
    </style>
</head>
<body>

<h2>Data HS Code</h2>

<table>
    <thead>
        <tr>
            <th>HS Code</th>
            <th>Uraian ID</th>
            <th>Uraian EN</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($results as $item) : ?>
            <tr>
                <td><?= isset($item['_source']['hs_code']) ? $item['_source']['hs_code'] : 'N/A'; ?></td>
                <td><?= isset($item['_source']['uraian_id']) ? $item['_source']['uraian_id'] : 'N/A'; ?></td>
                <td><?= isset($item['_source']['uraian_en']) ? $item['_source']['uraian_en'] : 'N/A'; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>

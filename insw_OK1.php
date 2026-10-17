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
    exit;
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
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
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
        tr:hover {
            background-color: #f1f1f1;
            cursor: pointer;
        }
        .copy-btn {
            background-color: #008CBA;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            margin-left: 10px;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 60%; 
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
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
            <?php
                $hsCode = isset($item['_source']['hs_code']) ? str_replace('.', '', $item['_source']['hs_code']) : 'N/A';
                $uraianId = isset($item['_source']['uraian_id']) ? $item['_source']['uraian_id'] : 'N/A';
                $uraianEn = isset($item['_source']['uraian_en']) ? $item['_source']['uraian_en'] : 'N/A';
            ?>
            <tr>
                <td>
                    <?php if (strlen($hsCode) == 8) : ?>
                        <a href="#" onclick="openModal('inswdtl.php?hs=<?= $hsCode; ?>')"> <?= $hsCode; ?> </a>
                        <button class="copy-btn" onclick="copyToClipboard('<?= $hsCode; ?>')">Copy</button>
                    <?php else : ?>
                        <?= $hsCode; ?>
                    <?php endif; ?>
                </td>
                <td><?= $uraianId; ?></td>
                <td><?= $uraianEn; ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div id="myModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <iframe id="modalFrame" style="width: 100%; height: 400px;"></iframe>
    </div>
</div>

<script>
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            showSuccess("HS Code " + text + " copied to clipboard!");
        }).catch(err => {
            showError('Failed to copy: ' + err);
        });
    }

    function openModal(url) {
        document.getElementById("modalFrame").src = url;
        document.getElementById("myModal").style.display = "block";
    }

    function closeModal() {
        document.getElementById("myModal").style.display = "none";
    }
</script>

</body>
</html>

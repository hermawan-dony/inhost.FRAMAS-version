<?php 

// Set default timezone
date_default_timezone_set('Asia/Jakarta'); // Ganti sesuai lokasi Anda

// Ambil nilai pencarian dari URL
$cari = isset($_GET["cari"]) ? urlencode($_GET["cari"]) : '';

// URL API dengan parameter pencarian
$url = "https://api.insw.go.id/api/cms/v1/hscode?keyword=$cari&size=300&from=0";

// Token Authorization baru (HARDCODED dalam contoh ini, idealnya disimpan aman)
$token = "eyJhbGciOiJSUzI1NiIsInR5cCI6ImJzYStqd3QiLCJraWQiOiJYLWx1Ri14aTByWlFfbk05UXNsNDRKRnpycTg5ZEJiblFNUUkyMEw2aGdZIn0.eyJpYXQiOjE3NTYxMzUwNjEsImV4cCI6MTc1NjE0MTE5OSwiYXVkIjoiaHR0cHM6Ly9pbnN3LmdvLmlkIiwiaXNzIjoiaHR0cHM6Ly9zc28uaW5zdy5nby5pZCIsInN1YiI6ImEwYWMwOWZhLTU2NzMtNDJjNi05ZmUxLTQxMWU2YzkzNzEwYSIsImp0aSI6IlUyRnNkR1ZrWDErRENKZFgvOGVRZWR6c0tYdEdZOHVsUmxxVlIzYmt6V2JlbmJXUjR2V2NqR0NCdkowYzlJUzU1b25ITWFmbU5tUkJENngyUmd2MG42bUhYVTl6VlU4SUEyOU12dFNOc1Q0U0NER01nNjBXMzhwZUNDcDBoZzkyOENMVXJoQ0oweTNYYm1BYzhVOVpMWmZ3OWFMUjk0NFlXTTdidTlXd3lzSUNQSGNqWXB5ekJScDc2V0dTc0FrMC9LRFZtQ1lwOTE2SXlJZGVBeGkyamc0UzZWOTI0ZUVGWFAxclZNS25WV0Z0YjE3dFNRK0dVTlV1OHRaQnNuK3UifQ.m36eVKSQy29BmmMFC8XZVAkbgXyuQaGmlMbYyY_vq-lzxs3RJzOsxxtwm61L6hWBPmENty3IWPgr5B9bIKgLwxju5Q0NslBgLeb_dRfnmQzoSsj7PU4E1hsTZFylVpvjWDkg6szh2UGNwEiNdMJUweAb2l4laFaGKT_qf8El-ToSvJysrRooCPswUhkR3K3nC19a4u5yupQbnKu4GC5bZlPiiT5JuwmG8WN02km169B8UpTsPDlr12M5YB1sYlLKBqFw8JU_qTkU6qLktDMZioF8y4uHOPOjmZJa2UAooyK_3DKAGbE9X9SHbfwOi5MBYKwWXbjKcCu1DgFbMlO1gA";
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
            border-radius: 4px;
            font-size: 12px;
        }
        .copy-btn:hover {
            background-color: #005f73;
        }
        /* Modal Styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 50%;
            top: 50%;
            width: 50%;
        
            transform: translate(-50%, -50%);
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.2);
            border: 2px solid #ccc;
        }
        .modal-header {
            font-size: 16px;
            font-weight: bold;
            padding-bottom: 8px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-content {
            max-height: 300px;
            overflow-y: auto;
        }
        .modal-close {
            cursor: pointer;
            font-size: 20px;
            font-weight: bold;
            color: red;
        }
        .search-container {
            margin-bottom: 20px;
        }
        .search-box {
            padding: 8px;
            width: 300px;
            font-size: 14px;
        }
        .search-btn {
            padding: 8px;
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 14px;
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
                        <a href="#" onclick="openModal('<?= $hsCode ?>')"><?= $hsCode ?></a>
                        <button class="copy-btn" onclick="copyToClipboard('<?= $hsCode ?>')">Copy</button>
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

<!-- Modal Structure -->
<div id="hsCodeModal" class="modal">
    <div class="modal-header">
        <span>Detail HS Code</span>
        <span class="modal-close" onclick="closeModal()">&times;</span>
    </div>
    <div class="modal-content" id="modalContent">
        <p>Loading...</p>
    </div>
</div>

<script>
    function openModal(hsCode) {
        document.getElementById("hsCodeModal").style.display = "block";
        document.getElementById("modalContent").innerHTML = "Loading...";

        // Fetch data
        fetch("inswdtl.php?hs=" + hsCode)
            .then(response => response.text())
            .then(data => {
                document.getElementById("modalContent").innerHTML = data;
            })
            .catch(error => {
                document.getElementById("modalContent").innerHTML = "Error loading data.";
            });

        // Mencegah halaman kembali ke atas
        event.preventDefault();
    }

    function closeModal() {
        document.getElementById("hsCodeModal").style.display = "none";
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            showSuccess("HS Code " + text + " telah disalin!");
        }).catch(function(error) {
            showError("Gagal menyalin HS Code: " + error);
        });
    }
</script>

</body>
</html>

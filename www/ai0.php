<?php

// --- KONFIGURASI ---
$apiKey = "AIzaSyAV5l9avm76E0hI3TvrmhPoQCRiVi3biNw";

// Variabel untuk menampung hasil atau error
$hasilEkstraksi = null;
$errorMsg = null;
$debugInfo = null; // Variabel baru untuk menampung info debug

/**
 * Fungsi untuk meminta Gemini mengekstrak data dari PDF
 * PERUBAHAN 1: Menambahkan parameter $debugOutput untuk menampung detail error
 */
function extractInvoiceDataFromGemini($filePath, $apiKey, &$debugOutput = null) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $apiKey;

    $pdfData = base64_encode(file_get_contents($filePath));
    $prompt = "Anda adalah seorang ahli ekstraksi data invoice yang sangat akurat. Tugas Anda adalah membaca data dari file PDF invoice yang saya berikan dan mengekstrak informasi berikut: 1. nomor_invoice, 2. tanggal_invoice, 3. tanggal_jatuh_tempo, 4. nama_pelanggan, 5. perusahaan_pelanggan, 6. daftar_item: Harus berupa sebuah array dari objek JSON, dimana setiap objek berisi: - deskripsi (string), - jumlah (number), - harga_satuan (number), - total_harga_item (number), 7. subtotal (number), 8. pajak (number, 0 jika tidak ada), 9. total_keseluruhan (number). Tolong berikan output HANYA dalam format JSON yang valid, tanpa tambahan teks atau markdown '```json' di awal maupun di akhir.";

    $data = [ "contents" => [ "parts" => [ ["text" => $prompt], ["inline_data" => [ "mime_type" => "application/pdf", "data" => $pdfData ] ] ] ] ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseData = json_decode($response, true);

    if ($httpCode === 200) {
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            $jsonString = $responseData['candidates'][0]['content']['parts'][0]['text'];
            return json_decode($jsonString, true);
        }
    }
    
    // PERUBAHAN 2: Jika terjadi error, simpan detailnya ke variabel debugOutput
    $debugOutput = [
        'httpCode' => $httpCode,
        'response' => $responseData ?? $response // Simpan array JSON jika ada, jika tidak simpan teks mentah
    ];
    return null;
}

// Logika utama untuk menangani file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['invoice_pdf']) && $_FILES['invoice_pdf']['error'] === UPLOAD_ERR_OK) {
    if ($apiKey === "MASUKKAN_API_KEY_ANDA_YANG_BARU") {
        $errorMsg = "Harap masukkan API Key baru Anda yang sudah aman.";
    } else {
        $filePath = $_FILES['invoice_pdf']['tmp_name'];
        $mimeType = mime_content_type($filePath);

        if ($mimeType === 'application/pdf') {
            // PERUBAHAN 3: Mengirim variabel $debugInfo ke dalam fungsi
            $hasilEkstraksi = extractInvoiceDataFromGemini($filePath, $apiKey, $debugInfo);
            
            if (!$hasilEkstraksi) {
                 if (isset($debugInfo['response']['promptFeedback']['blockReason'])) {
                    $errorMsg = "Permintaan diblokir. Alasan: " . $debugInfo['response']['promptFeedback']['blockReason'];
                 } else {
                    $errorMsg = "Gagal mengekstrak data. Coba lagi atau periksa detail error teknis.";
                 }
            }
        } else {
            $errorMsg = "File yang diunggah harus berformat PDF.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ekstraktor Invoice PDF dengan AI</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; background-color: #f4f7f9; color: #333; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; background-color: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        h1 { color: #1a73e8; text-align: center; }
        .upload-form { margin-bottom: 30px; border: 2px dashed #ccc; padding: 20px; border-radius: 8px; text-align: center; }
        .btn { background-color: #1a73e8; color: white; padding: 12px 20px; border: none; border-radius: 6px; font-size: 16px; cursor: pointer; transition: background-color 0.3s; }
        .error { background-color: #ffebee; color: #c62828; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 5px solid #c62828; }
        .hasil { border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
        details { margin-top: 15px; }
        summary { cursor: pointer; color: #555; font-weight: bold; }
        .debug-box { background-color:#eee; border:1px solid #ccc; padding:10px; border-radius:5px; white-space: pre-wrap; word-wrap: break-word; margin-top: 10px; }
    </style>
</head>
<body>

<div class="container">
    <h1>📄 Ekstraktor Invoice PDF</h1>
    
    <form class="upload-form" method="post" enctype="multipart/form-data">
        <label for="invoice_pdf">Pilih file Invoice PDF:</label><br><br>
        <input type="file" name="invoice_pdf" id="invoice_pdf" accept="application/pdf" required>
        <br><br>
        <button type="submit" class="btn">Proses Invoice</button>
    </form>

    <?php if ($errorMsg): ?>
        <div class="error">
            <strong>Gagal:</strong> <?php echo htmlspecialchars($errorMsg); ?>
            
            <?php if (!empty($debugInfo)): ?>
                <details>
                    <summary>[Tampilkan Detail Error Teknis]</summary>
                    <div class="debug-box">
                        <p><strong>Kode Status HTTP:</strong> <?php echo $debugInfo['httpCode']; ?></p>
                        <p><strong>Jawaban Lengkap dari Server:</strong></p>
                        <pre><?php print_r($debugInfo['response']); ?></pre>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($hasilEkstraksi): ?>
        <div class="hasil">
            <h2>Berhasil! Hasil Ekstraksi Data:</h2>
            <p><strong>Nomor Invoice:</strong> <?php echo htmlspecialchars($hasilEkstraksi['nomor_invoice'] ?? 'N/A'); ?></p>
            <p><strong>Tanggal Invoice:</strong> <?php echo htmlspecialchars($hasilEkstraksi['tanggal_invoice'] ?? 'N/A'); ?></p>
            <p><strong>Jatuh Tempo:</strong> <?php echo htmlspecialchars($hasilEkstraksi['tanggal_jatuh_tempo'] ?? 'N/A'); ?></p>
            <p><strong>Nama Pelanggan:</strong> <?php echo htmlspecialchars($hasilEkstraksi['nama_pelanggan'] ?? 'N/A'); ?></p>
            <p><strong>Perusahaan:</strong> <?php echo htmlspecialchars($hasilEkstraksi['perusahaan_pelanggan'] ?? 'N/A'); ?></p>
            <hr>
            <h3>Rincian Item</h3>
            <table>
                <thead>
                    <tr>
                        <th>Deskripsi</th>
                        <th>Jumlah</th>
                        <th>Harga Satuan</th>
                        <th>Total Harga</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hasilEkstraksi['daftar_item'] ?? [] as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['deskripsi'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($item['jumlah'] ?? 'N/A'); ?></td>
                        <td>Rp <?php echo number_format($item['harga_satuan'] ?? 0, 0, ',', '.'); ?></td>
                        <td>Rp <?php echo number_format($item['total_harga_item'] ?? 0, 0, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
<?php

set_time_limit(600);

// GANTI DENGAN API KEY BARU ANDA
// PERHATIAN: JANGAN PERNAH MENGUNGGAH API KEY PRIBADI KE REPOSITORI PUBLIK
$apiKey = "AIzaSyAV5l9avm76E0hI3TvrmhPoQCRiVi3biNw";

// Gunakan model yang dioptimalkan untuk multimodal (termasuk dokumen/PDF)
// gemini-2.5-flash adalah pilihan yang cepat dan mampu, namun gemini-2.5-pro
// dapat digunakan jika diperlukan akurasi OCR yang sangat tinggi.
$geminiModel = "gemini-2.5-flash"; // Sesuai permintaan untuk OCR lebih baik

// Variabel untuk menampung hasil atau error
$hasilEkstraksi = null;
$errorMsg = null;
$debugInfo = null; // Variabel untuk menampung info debug jika terjadi error

/**
 * Fungsi untuk meminta Gemini mengekstrak data dari PDF
 * @param string $filePath Path sementara file yang diupload
 * @param string $apiKey API Key Gemini
 * @param string $model Nama model Gemini yang digunakan
 * @param array|null &$debugOutput Variabel untuk menampung detail error
 * @return array|null Data yang diekstrak atau null jika gagal
 */
function extractInvoiceDataFromGemini($filePath, $apiKey, $model, &$debugOutput = null) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $apiKey;

    $pdfData = base64_encode(file_get_contents($filePath));

    // --- PROMPT YANG DIPERBAIKI DAN DITINGKATKAN ---
    $prompt = "Anda adalah sistem OCR dan ekstraksi data yang sangat canggih. Tugas Anda adalah menganalisis keseluruhan dokumen PDF invoice berikut, termasuk semua halaman, dan mengekstrak informasi kunci secara akurat.

    Informasi yang harus diekstrak adalah:
    1. `nomor_invoice`: Cari nomor dokumen atau 'Document No.'.
    2. `tanggal_invoice`: Cari tanggal dokumen atau 'Date'.
    3. `tanggal_jatuh_tempo`: Cari 'Due Date' atau 'Jatuh Tempo'. Jika tidak ada, isi dengan null.
    4. `nama_pelanggan`: Cari nama perusahaan atau perorangan di bagian 'Kepada' atau 'Bill To'.
    5. `perusahaan_pelanggan`: Sama seperti nama_pelanggan, jika berupa nama perusahaan. Jika tidak ada, isi N/A.
    6. `subtotal`: Total nilai barang sebelum pajak atau biaya tambahan. Cari kata kunci seperti 'Subtotal' atau 'Total Value of Goods'.
    7. `pajak`: Nilai pajak seperti PPN atau VAT. Jika tidak tertera, isi dengan 0.
    8. `total_keseluruhan`: Nilai akhir yang harus dibayar. Cari kata kunci 'Total Amount' atau 'Grand Total'.
    9. `kurs`: **Valuta atau Mata Uang** yang digunakan pada invoice (misalnya: USD, EUR, IDR).
    10. `daftar_item`: Ekstrak SEMUA item dari setiap halaman. Ini harus berupa sebuah array dari objek JSON. Setiap objek harus berisi:
        - `kode_item`: **Kode barang** atau Part Number/SKU.
        - `deskripsi`: Nama barang atau jasa.
        - `jumlah`: Kuantitas barang (number).
        - `harga_satuan`: Harga per unit (number). Tampilkan desimalnya.
        - `total_harga_item`: Nilai total untuk item tersebut (number). Tampilkan desimalnya.

    PENTING:
    - Abaikan baris 'BALANCE FORWARD'.
    - Jika sebuah nilai tidak ditemukan, kembalikan `null` untuk field tersebut.
    - Semua nilai moneter harus berupa **angka murni** (tanpa koma ribuan atau simbol mata uang, gunakan titik sebagai desimal).
    - Jawaban Anda **HARUS dan HANYA berupa format JSON yang valid dan bersih**, tanpa tambahan teks atau markdown '```json' di awal maupun di akhir.";

    $data = [
        "contents" => [
            "parts" => [
                ["text" => $prompt],
                ["inline_data" => [
                    "mime_type" => "application/pdf",
                    "data" => $pdfData
                ]]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Timeout diperpanjang untuk proses PDF yang lebih besar/kompleks

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseData = json_decode($response, true);

    if ($httpCode === 200 && isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $jsonString = $responseData['candidates'][0]['content']['parts'][0]['text'];
        // Hapus kode markdown JSON yang mungkin ditambahkan oleh model
        $jsonString = preg_replace('/```json\s*|```\s*$/', '', $jsonString);
        return json_decode($jsonString, true);
    }
    
    // Jika terjadi error, simpan detailnya ke variabel debug
    $debugOutput = [
        'httpCode' => $httpCode,
        'response' => $responseData ?? $response
    ];
    return null;
}

// Logika utama untuk menangani file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['invoice_pdf']) && $_FILES['invoice_pdf']['error'] === UPLOAD_ERR_OK) {
    if ($apiKey === "xxx") { // Periksa apakah API Key masih yang default
        $errorMsg = "Harap masukkan API Key baru Anda yang sudah aman di baris \$apiKey.";
    } else {
        $filePath = $_FILES['invoice_pdf']['tmp_name'];
        $mimeType = mime_content_type($filePath);

        if ($mimeType === 'application/pdf') {
            // Gunakan model yang telah didefinisikan
            $hasilEkstraksi = extractInvoiceDataFromGemini($filePath, $apiKey, $geminiModel, $debugInfo);
            
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
    <title>Ekstraktor Invoice PDF</title>
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; background-color: #f4f7f9; color: #333; }
        .container { max-width: 900px; margin: 40px auto; padding: 30px; background-color: #fff; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        h1 { color: #1a73e8; text-align: center; }
        .upload-form { margin-bottom: 30px; border: 2px dashed #ccc; padding: 20px; border-radius: 8px; text-align: center; }
        .btn { background-color: #1a73e8; color: white; padding: 10px 18px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; transition: background-color 0.3s; margin: 5px; }
        .btn-copy { background-color: #0f9d58; }
        .btn-copy:hover { background-color: #0c8045; }
        .btn:hover { background-color: #1558b0; }
        .error { background-color: #ffebee; color: #c62828; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 5px solid #c62828; }
        .hasil { border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #ddd; font-size: 14px; }
        th { background-color: #f2f2f2; }
        details { margin-top: 15px; }
        summary { cursor: pointer; color: #555; font-weight: bold; }
        .debug-box { background-color:#eee; border:1px solid #ccc; padding:10px; border-radius:5px; white-space: pre-wrap; word-wrap: break-word; margin-top: 10px; font-size: 12px; }
        .header-data p { margin: 5px 0; display: flex; justify-content: space-between; padding-right: 20px; }
        .header-data strong { font-weight: 600; }
    </style>
</head>
<body>

<div class="container">
    <h1>📄 Ekstraktor Invoice PDF</h1>
    
    <form class="upload-form" method="post" enctype="multipart/form-data">
        <label for="invoice_pdf">Pilih file Invoice PDF:</label><br><br>
        <input type="file" name="invoice_pdf" id="invoice_pdf" accept="application/pdf" required>
        <br><br>
        <button type="submit" class="btn">Proses Ekstrak Data</button>
      <!--  <p style="font-size: 12px; color: #777;">Menggunakan model: <?php echo $geminiModel; ?></p>-->
    </form>

    <?php if ($errorMsg): ?>
        <div class="error">
            <strong>Gagal:</strong> <?php echo htmlspecialchars($errorMsg); ?>
            
            <?php if (!empty($debugInfo)): ?>
                <details>
                    <summary>[Tampilkan Detail Error Teknis]</summary>
                    <div class="debug-box">
                        <p><strong>Kode Status HTTP:</strong> <?php echo htmlspecialchars($debugInfo['httpCode'] ?? 'N/A'); ?></p>
                        <p><strong>Jawaban Lengkap dari Server:</strong></p>
                        <pre><?php print_r($debugInfo['response'] ?? 'Tidak ada respons'); ?></pre>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($hasilEkstraksi): ?>
        <div class="hasil">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h2>✅ Hasil Ekstraksi Data Header</h2>
                <button class="btn btn-copy" onclick="copyHeaderData()">Salin Data Header</button>
            </div>
            <div class="header-data" id="header-data">
                <p><strong>Nomor Invoice:</strong> <span data-field="nomor_invoice"><?php echo htmlspecialchars($hasilEkstraksi['nomor_invoice'] ?? 'N/A'); ?></span></p>
                <p><strong>Tanggal Invoice:</strong> <span data-field="tanggal_invoice"><?php echo htmlspecialchars($hasilEkstraksi['tanggal_invoice'] ?? 'N/A'); ?></span></p>
                <p><strong>Jatuh Tempo:</strong> <span data-field="tanggal_jatuh_tempo"><?php echo htmlspecialchars($hasilEkstraksi['tanggal_jatuh_tempo'] ?? 'N/A'); ?></span></p>
                <p><strong>Nama Pelanggan:</strong> <span data-field="nama_pelanggan"><?php echo htmlspecialchars($hasilEkstraksi['nama_pelanggan'] ?? 'N/A'); ?></span></p>
                <p><strong>Perusahaan:</strong> <span data-field="perusahaan_pelanggan"><?php echo htmlspecialchars($hasilEkstraksi['perusahaan_pelanggan'] ?? 'N/A'); ?></span></p>
                <p><strong>Kurs/Valuta:</strong> <span data-field="kurs"><?php echo htmlspecialchars($hasilEkstraksi['kurs'] ?? 'N/A'); ?></span></p>
            </div>
            
            <hr>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3>📦 Rincian Item (Detail Barang)</h3>
                <button class="btn btn-copy" onclick="copyItemData()">Salin Data Detail Item</button>
            </div>
            
            <div id="item-table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Kode Item</th>
                            <th>Deskripsi</th>
                            <th style="text-align: center;">Jumlah</th>
                            <th style="text-align: right;">Harga Satuan</th>
                            <th style="text-align: right;">Total Harga</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $itemDataForCopy = [];
                        foreach ($hasilEkstraksi['daftar_item'] ?? [] as $item): 
                            // Siapkan data untuk disalin dalam format CSV atau tab-delimited
                            $itemDataForCopy[] = [
                                $item['kode_item'] ?? '', 
                                $item['deskripsi'] ?? '', 
                                $item['jumlah'] ?? '', 
                                $item['harga_satuan'] ?? '', 
                                $item['total_harga_item'] ?? ''
                            ];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['kode_item'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($item['deskripsi'] ?? 'N/A'); ?></td>
                            <td style="text-align: center;"><?php echo htmlspecialchars($item['jumlah'] ?? 'N/A'); ?></td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($item['harga_satuan'] ?? 'N/A'); ?></td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($item['total_harga_item'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
                // Simpan data item dalam bentuk JSON agar bisa diakses JS untuk disalin
                const itemDataJSON = <?php echo json_encode($itemDataForCopy); ?>;
            </script>
            
            <hr>
            <div style="text-align: right;">
                <p><strong>Subtotal:</strong> <span data-field="subtotal"><?php echo htmlspecialchars($hasilEkstraksi['kurs'] ?? 'N/A') . ' ' . ($hasilEkstraksi['subtotal'] ?? '0'); ?></span></p>
                <p><strong>Pajak:</strong> <span data-field="pajak"><?php echo htmlspecialchars($hasilEkstraksi['kurs'] ?? 'N/A') . ' ' . ($hasilEkstraksi['pajak'] ?? '0'); ?></span></p>
                <h3><strong>TOTAL:</strong> <span data-field="total_keseluruhan"><?php echo htmlspecialchars($hasilEkstraksi['kurs'] ?? 'N/A') . ' ' . ($hasilEkstraksi['total_keseluruhan'] ?? '0'); ?></span></h3>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    /**
     * Menyalin string teks ke clipboard
     * @param {string} text Teks yang akan disalin
     */
    function copyToClipboard(text, buttonElement) {
        if (!navigator.clipboard) {
            // Fallback untuk browser lama
            const textArea = document.createElement("textarea");
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
                showSuccess("Data berhasil disalin (Fallback).");
            } catch (err) {
                console.error('Gagal menyalin (Fallback): ', err);
                showError("Gagal menyalin data.");
            }
            document.body.removeChild(textArea);
            return;
        }

        navigator.clipboard.writeText(text).then(function() {
            // Feedback visual pada tombol
            const originalText = buttonElement.innerHTML;
            buttonElement.innerHTML = 'Tersalin! ✅';
            setTimeout(() => {
                buttonElement.innerHTML = originalText;
            }, 1500);
        }, function(err) {
            console.error('Gagal menyalin: ', err);
            showError("Gagal menyalin data.");
        });
    }

    /**
     * Mengambil dan menyalin data Header Invoice
     */
    function copyHeaderData() {
        const headerContainer = document.getElementById('header-data');
        const fields = ['nomor_invoice', 'tanggal_invoice', 'tanggal_jatuh_tempo', 'nama_pelanggan', 'perusahaan_pelanggan', 'kurs', 'subtotal', 'pajak', 'total_keseluruhan'];
        
        // Cek data total yang mungkin tidak ada di div header-data, tapi ada di footer
        const subtotalSpan = document.querySelector('[data-field="subtotal"]');
        const pajakSpan = document.querySelector('[data-field="pajak"]');
        const totalSpan = document.querySelector('[data-field="total_keseluruhan"]');
        
        let copyText = "Data Header Invoice:\n";
        
        // Ekstraksi data dari tag span dengan data-field
        fields.forEach(field => {
            let value = 'N/A';
            const element = headerContainer.querySelector(`[data-field="${field}"]`);
            
            if (element) {
                value = element.textContent.trim();
            } else if (field === 'subtotal' && subtotalSpan) {
                 value = subtotalSpan.textContent.trim();
            } else if (field === 'pajak' && pajakSpan) {
                 value = pajakSpan.textContent.trim();
            } else if (field === 'total_keseluruhan' && totalSpan) {
                 value = totalSpan.textContent.trim();
            }
            
            // Format lebih bersih untuk disalin
            const label = field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            copyText += `${label}: ${value}\n`;
        });

        // Tambahkan detail total yang ada di footer
        copyText += `Subtotal: ${subtotalSpan ? subtotalSpan.textContent.trim() : 'N/A'}\n`;
        copyText += `Pajak: ${pajakSpan ? pajakSpan.textContent.trim() : 'N/A'}\n`;
        copyText += `Total Keseluruhan: ${totalSpan ? totalSpan.textContent.trim() : 'N/A'}\n`;

        copyToClipboard(copyText, event.target);
    }

    /**
     * Mengambil dan menyalin data Item Invoice (dalam format tab-delimited)
     */
    function copyItemData() {
        if (!itemDataJSON || itemDataJSON.length === 0) {
            showWarning("Tidak ada data item untuk disalin.");
            return;
        }

        // Header kolom
        let copyText = "Kode Item\tDeskripsi\tJumlah\tHarga Satuan\tTotal Harga\n";

        // Baris data (dipisahkan dengan tab \t)
        itemDataJSON.forEach(item => {
            copyText += item.join('\t') + '\n';
        });

        copyToClipboard(copyText, event.target);
    }
</script>

</body>
</html>
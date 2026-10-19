<?php 
/**
 * CEISA 4.0 PDF Downloader & Viewer (pdf.php)
 * Mengunduh dokumen respon PDF (SPPB, NPE, Billing, dll) dari API CEISA 4.0,
 * menyimpan salinan lokal untuk mempercepat akses (0ms), dan menampilkannya di tab browser.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$aju = trim($_GET["aju"] ?? ($_GET["nomor_aju"] ?? ''));
$pdf = trim($_GET["pdf"] ?? '');
$targetCmp = trim($_GET["cmp_id"] ?? '');

if (empty($aju)) {
    http_response_code(400);
    die('<div style="font-family:sans-serif; padding:20px; text-align:center;"><h3>Nomor Pengajuan (AJU) tidak boleh kosong!</h3></div>');
}

// 1. Cek Salinan File Lokal Terlebih Dahulu (Cache Hit -> 0ms, Tanpa Beban ke Bea Cukai)
$cleanAju = preg_replace('/[^a-zA-Z0-9_-]/', '_', $aju);
$pdfDir = __DIR__ . '/pdf';
if (!is_dir($pdfDir)) {
    @mkdir($pdfDir, 0777, true);
}
$localPdfFile = $pdfDir . '/' . $cleanAju . '.pdf';

if (file_exists($localPdfFile) && filesize($localPdfFile) > 1000) {
    header("Content-Type: application/pdf");
    header("Content-Disposition: inline; filename=\"SPPB_{$cleanAju}.pdf\"");
    header("Content-Length: " . filesize($localPdfFile));
    header("Cache-Control: public, max-age=86400");
    readfile($localPdfFile);
    exit;
}

// 2. Jika path pdf kosong di request, coba cari dari tabel bc_ceisa_sppb atau bc_ceisa_data
if (empty($pdf) && isset($koneksi) && $koneksi) {
    $stmtFindPdf = $koneksi->prepare("
        SELECT COALESCE(NULLIF(s.pdf, ''), NULLIF(d.pdf, ''), NULLIF(r.pdf, '')) AS pdf_path,
               COALESCE(NULLIF(s.cmp_id, ''), NULLIF(d.cmp_id, ''), NULLIF(r.cmp_id, '')) AS found_cmp
        FROM (SELECT ? AS aju) x
        LEFT JOIN bc_ceisa_sppb s ON s.nomorAju = x.aju
        LEFT JOIN bc_ceisa_data d ON d.nomorAju = x.aju
        LEFT JOIN bc_ceisa_respons r ON r.nomor_aju = x.aju AND r.pdf IS NOT NULL AND r.pdf != ''
        ORDER BY r.id DESC
        LIMIT 1
    ");
    if ($stmtFindPdf) {
        $stmtFindPdf->bind_param("s", $aju);
        $stmtFindPdf->execute();
        $resPdf = $stmtFindPdf->get_result();
        if ($resPdf && $rPdf = $resPdf->fetch_assoc()) {
            if (!empty($rPdf['pdf_path'])) $pdf = $rPdf['pdf_path'];
            if (empty($targetCmp) && !empty($rPdf['found_cmp'])) $targetCmp = $rPdf['found_cmp'];
        }
        $stmtFindPdf->close();
    }
}

if (empty($pdf)) {
    http_response_code(404);
    die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#595959;">
        <h3 style="color:#ff4d4f;">⚠️ Dokumen PDF Belum Tersedia</h3>
        <p>Path file PDF belum diterbitkan oleh Bea Cukai atau respon dokumen belum berstatus persetujuan resmi (SPPB/NPE).</p>
        <p style="font-size:12px; color:#8c8c8c;">Nomor AJU: <code>' . htmlspecialchars($aju) . '</code></p>
    </div>');
}

// 3. Tentukan Company & Dapatkan OAuth Token
if (empty($targetCmp)) {
    $comp = ceisa_get_company($aju);
    if ($comp) {
        $targetCmp = $comp['cmp_id'];
    }
}

$cmp_id = $targetCmp;
require_once __DIR__ . '/init.php';

$bearer = $_SESSION["token_" . $targetCmp] ?? ($_SESSION["token"] ?? '');

if (empty($bearer)) {
    // Coba fallback ke MFA token jika ada
    require_once __DIR__ . '/initmfa.php';
    $bearer = $_SESSION["mfatoken_" . $targetCmp] ?? '';
}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/download-respon?path=" . urlencode($pdf);

$curl = curl_init(); 
curl_setopt_array($curl, [
    CURLOPT_URL            => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING       => "gzip, deflate",
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 35,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST  => "GET",
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json",
        "Accept: application/pdf, application/json, */*",
        "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "Origin: https://portal.beacukai.go.id",
        "Referer: https://portal.beacukai.go.id/",
        "Sec-Fetch-Dest: empty",
        "Sec-Fetch-Mode: cors",
        "Sec-Fetch-Site: same-site",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($err) {
    http_response_code(502);
    die('<div style="font-family:sans-serif; padding:40px; text-align:center;">
        <h3 style="color:#ff4d4f;">Gagal Menghubungi Server Bea Cukai</h3>
        <p>' . htmlspecialchars($err) . '</p>
    </div>');
}

// 4. Verifikasi format PDF valid (Magic bytes: %PDF)
$isPdf = (substr($response, 0, 4) === '%PDF');

if ($isPdf) {
    // Simpan salinan lokal agar unduhan berikutnya instan
    @file_put_contents($localPdfFile, $response);

    $backupDir = "C:/iceisa40/pdf";
    if (is_dir($backupDir)) {
        @file_put_contents($backupDir . DIRECTORY_SEPARATOR . $cleanAju . ".pdf", $response);
    }

    // Stream langsung ke browser sebagai PDF interaktif
    header("Content-Type: application/pdf");
    header("Content-Disposition: inline; filename=\"SPPB_{$cleanAju}.pdf\"");
    header("Content-Length: " . strlen($response));
    echo $response;
    exit;
} else {
    // Response bukan file PDF (misal token kedaluwarsa atau file belum siap)
    http_response_code($httpCode ?: 400);
    header("Content-Type: text/html; charset=utf-8");
    echo '<!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Status Unduh PDF CEISA</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; background:#f0f2f5; color:#262626; }
            .card { background:#fff; padding:32px; border-radius:10px; box-shadow:0 4px 16px rgba(0,0,0,0.08); max-width:480px; text-align:center; }
            h3 { color:#faad14; margin-top:0; font-size:18px; }
            p { font-size:12.5px; color:#595959; line-height:1.6; }
            pre { background:#f5f5f5; padding:10px; border-radius:6px; font-size:11px; text-align:left; overflow:auto; max-height:120px; }
            .btn { display:inline-block; margin-top:12px; padding:7px 16px; background:#1890ff; color:#fff; border-radius:6px; text-decoration:none; font-size:12px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div style="font-size:36px; margin-bottom:8px;">📄</div>
            <h3>Respon PDF Belum Dapat Dibuka</h3>
            <p>Server Bea Cukai mengembalikan pesan status (HTTP ' . $httpCode . '):</p>
            <pre>' . htmlspecialchars(substr($response, 0, 500)) . '</pre>
            <p style="font-size:11px; color:#8c8c8c;">Silakan lakukan sinkronisasi data terbaru pada halaman Dokumen CEISA 4.0 terlebih dahulu.</p>
            <a href="javascript:window.close()" class="btn">Tutup Jendela</a>
        </div>
    </body>
    </html>';
}
<?php
/**
 * CEISA 4.0 - Official NVOCC Manifest PDF Downloader & Viewer (pdf_manifest.php)
 * Mengunduh & menampilkan dokumen PDF resmi dari Bea Cukai (Jasper Report Service):
 * https://apis-gw.beacukai.go.id/report-manifes-v2/v1/jasper/get-file-nvocc/{idNvoccHeader}
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$nomorAju   = trim($_GET['nomor_aju'] ?? ($_GET['aju'] ?? ''));
$idNvocc    = trim($_GET['id_nvocc_header'] ?? ($_GET['id_nvocc'] ?? ''));
$targetCmp  = trim($_GET['cmp_id'] ?? ($cmp_id ?: ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''))));
$forceFresh = !empty($_GET['refresh']) || !empty($_GET['force']);
$isDownload = !empty($_GET['download']) && $_GET['download'] == '1';

if (empty($nomorAju) && empty($idNvocc)) {
    http_response_code(400);
    die('<div style="font-family:sans-serif; padding:30px; text-align:center; color:#cf1322;">
        <h3>⚠️ Parameter Nomor AJU atau ID NVOCC tidak boleh kosong.</h3>
    </div>');
}

// 1. Ambil data dari tabel bc_ceisa_manifes_header jika salah satu parameter kosong
$headerData = null;
if (isset($koneksi) && $koneksi) {
    if (!empty($nomorAju)) {
        $stmtH = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_header WHERE nomor_aju = ? LIMIT 1");
        if ($stmtH) {
            $stmtH->bind_param("s", $nomorAju);
            $stmtH->execute();
            $resH = $stmtH->get_result();
            if ($resH && $rowH = $resH->fetch_assoc()) {
                $headerData = $rowH;
                if (empty($idNvocc) && !empty($rowH['id_nvocc_header'])) {
                    $idNvocc = trim($rowH['id_nvocc_header']);
                }
                if (empty($targetCmp) && !empty($rowH['cmp_id'])) {
                    $targetCmp = trim($rowH['cmp_id']);
                }
            }
            $stmtH->close();
        }
    } elseif (!empty($idNvocc)) {
        $stmtH = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_header WHERE id_nvocc_header = ? LIMIT 1");
        if ($stmtH) {
            $stmtH->bind_param("s", $idNvocc);
            $stmtH->execute();
            $resH = $stmtH->get_result();
            if ($resH && $rowH = $resH->fetch_assoc()) {
                $headerData = $rowH;
                if (empty($nomorAju)) {
                    $nomorAju = trim($rowH['nomor_aju']);
                }
                if (empty($targetCmp) && !empty($rowH['cmp_id'])) {
                    $targetCmp = trim($rowH['cmp_id']);
                }
            }
            $stmtH->close();
        }
    }
}

$cleanFileId = !empty($nomorAju) ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomorAju) : preg_replace('/[^a-zA-Z0-9_-]/', '_', $idNvocc);
$pdfDir = __DIR__ . '/pdf';
if (!is_dir($pdfDir)) {
    @mkdir($pdfDir, 0777, true);
}
$cachedPdfFile = $pdfDir . '/manifest_' . $cleanFileId . '.pdf';

// 2. Cek Cache Lokal (jika tidak force refresh)
if (!$forceFresh && file_exists($cachedPdfFile) && filesize($cachedPdfFile) > 1000) {
    $fHandle = @fopen($cachedPdfFile, 'rb');
    $magic = $fHandle ? fread($fHandle, 4) : '';
    if ($fHandle) fclose($fHandle);

    if ($magic === '%PDF') {
        $disposition = $isDownload ? 'attachment' : 'inline';
        header("Content-Type: application/pdf");
        header("Content-Disposition: {$disposition}; filename=\"Manifes_{$cleanFileId}.pdf\"");
        header("Content-Length: " . filesize($cachedPdfFile));
        header("Cache-Control: public, max-age=86400");
        readfile($cachedPdfFile);
        exit;
    }
}

// 3. Jika belum memiliki ID NVOCC dari CEISA
if (empty($idNvocc)) {
    $stt = $headerData['status'] ?? 'Draft';
    echo '<!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Dokumen Belum Tersedia - CEISA 4.0</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .card { background: #fff; border-radius: 6px; padding: 28px 36px; max-width: 520px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); text-align: center; }
            .icon { font-size: 42px; margin-bottom: 12px; }
            h2 { font-size: 16px; margin: 0 0 10px; color: #1f1f1f; }
            p { font-size: 12px; color: #595959; line-height: 1.6; margin: 0 0 20px; }
            .btn { display: inline-block; background: #1890ff; color: #fff; padding: 7px 16px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 600; cursor: pointer; }
            .btn:hover { background: #40a9ff; }
            code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 11px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">📄</div>
            <h2>Dokumen PDF Resmi Belum Tersedia</h2>
            <p>Nomor AJU: <code>' . htmlspecialchars($nomorAju) . '</code><br>Status Saat Ini: <strong>' . htmlspecialchars($stt) . '</strong><br><br>
            Dokumen ini belum memiliki <strong>ID NVOCC</strong> resmi dari sistem Bea Cukai CEISA 4.0. Cetak dokumen PDF Jasper resmi Bea Cukai tersedia setelah dokumen dikirimkan atau ditarik dari Portal CEISA 4.0.</p>
            <button class="btn" onclick="window.close()">Tutup Jendela</button>
        </div>
    </body>
    </html>';
    exit;
}

// 4. Ambil Token Portal Bea Cukai
require_once __DIR__ . '/manifest_api.php';
$portalToken = getActivePortalMfaToken($targetCmp);

if (empty($portalToken)) {
    echo '<!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Sesi Token Kedaluwarsa - CEISA 4.0</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .card { background: #fff; border-radius: 6px; padding: 28px 36px; max-width: 500px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); text-align: center; }
            .icon { font-size: 42px; margin-bottom: 12px; }
            h2 { font-size: 16px; margin: 0 0 10px; color: #cf1322; }
            p { font-size: 12px; color: #595959; line-height: 1.6; margin: 0 0 20px; }
            .btn { display: inline-block; background: #1890ff; color: #fff; padding: 7px 16px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 600; cursor: pointer; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">🔐</div>
            <h2>Token Autentikasi CEISA Diperlukan</h2>
            <p>Sesi login Portal CEISA 4.0 belum aktif atau telah kedaluwarsa. Silakan perbarui MFA Token pada menu <strong>Manifest Ceisa 4.0</strong> lalu coba cetak kembali.</p>
            <button class="btn" onclick="window.close()">Tutup Jendela</button>
        </div>
    </body>
    </html>';
    exit;
}

// 5. Unduh PDF Langsung dari Endpoint Jasper Report CEISA 4.0
$jasperUrl = "https://apis-gw.beacukai.go.id/report-manifes-v2/v1/jasper/get-file-nvocc/{$idNvocc}";
$apiKey = '6222a75e-1dbb-493e-9461-27f721097e9c';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $jasperUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_TIMEOUT => 40,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$portalToken}",
        "Beacukai-Api-Key: {$apiKey}",
        "Accept: application/pdf, application/json, text/plain, */*",
        "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "Origin: https://portal.beacukai.go.id",
        "Referer: https://portal.beacukai.go.id/",
        "Sec-Fetch-Dest: empty",
        "Sec-Fetch-Mode: cors",
        "Sec-Fetch-Site: same-site",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
    ]
]);

$pdfContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

// 6. Validasi Respon PDF
if ($httpCode === 200 && !empty($pdfContent) && substr($pdfContent, 0, 4) === '%PDF') {
    // Simpan ke Cache Lokal
    @file_put_contents($cachedPdfFile, $pdfContent);

    $disposition = $isDownload ? 'attachment' : 'inline';
    header("Content-Type: application/pdf");
    header("Content-Disposition: {$disposition}; filename=\"Manifes_{$cleanFileId}.pdf\"");
    header("Content-Length: " . strlen($pdfContent));
    header("Cache-Control: public, max-age=86400");
    echo $pdfContent;
    exit;
}

// 7. Penanganan Kegagalan / Error Response dari Gateway
http_response_code($httpCode >= 400 ? $httpCode : 500);
$errMessage = 'Gagal mengunduh file PDF dari server Jasper Bea Cukai.';
$jsonErr = json_decode($pdfContent, true);
if (!empty($jsonErr['message'])) {
    $errMessage = $jsonErr['message'];
} elseif (!empty($jsonErr['item'])) {
    $errMessage = is_string($jsonErr['item']) ? $jsonErr['item'] : json_encode($jsonErr['item']);
}

echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Gagal Cetak PDF - CEISA 4.0</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 6px; padding: 28px 36px; max-width: 520px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); text-align: center; }
        .icon { font-size: 42px; margin-bottom: 12px; }
        h2 { font-size: 16px; margin: 0 0 10px; color: #cf1322; }
        p { font-size: 12px; color: #595959; line-height: 1.6; margin: 0 0 20px; }
        .btn { display: inline-block; background: #1890ff; color: #fff; padding: 7px 16px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 600; cursor: pointer; }
        pre { background: #fafafa; border: 1px solid #d9d9d9; padding: 10px; font-size: 11px; text-align: left; overflow: auto; border-radius: 3px; max-height: 150px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">⚠️</div>
        <h2>Gagal Mencetak Dokumen PDF</h2>
        <p>Layanan Jasper Report Bea Cukai memberikan respon (HTTP <strong>' . htmlspecialchars($httpCode) . '</strong>):<br>
        <span style="color:#cf1322; font-weight:600;">' . htmlspecialchars($errMessage) . '</span></p>
        ' . (!empty($curlErr) ? '<pre>' . htmlspecialchars($curlErr) . '</pre>' : '') . '
        <div style="margin-top:16px; display:flex; gap:8px; justify-content:center;">
            <button class="btn" onclick="location.reload()">🔄 Coba Lagi</button>
            <button class="btn" style="background:#8c8c8c;" onclick="window.close()">Tutup Jendela</button>
        </div>
    </div>
</body>
</html>';
exit;

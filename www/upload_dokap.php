<?php
/**
 * CEISA 4.0 - Upload Dokumen Pelengkap & Gambar Barang (upload_dokap.php)
 * Sesuai Standar Resmi OpenAPI Bea Cukai (Postman: H2H Upload Dokap dan Gambar PROD OpenAPI)
 * 
 * Endpoints Bea Cukai:
 * 1. Upload Dokap Single: POST https://apis-gw.beacukai.go.id/v2/openapi/file/dokumen
 * 2. Upload Gambar Single: POST https://apis-gw.beacukai.go.id/v2/openapi/file/barang
 * 3. Upload Dokap NPD:    POST https://apis-gw.beacukai.go.id/v2/openapi/file/upload-dokap-npd
 */

session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/init.php';

$activeCmp = $cmp_id ?: ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''));
$apiKey    = $active_company['api_key_ceisa'] ?? 'f662de2e-1d35-4b11-b2fd-ee164a5c9d83';
$bearer    = $_SESSION["token"] ?? ($_SESSION["token_" . $activeCmp] ?? '');
$sessionCmpName = $active_company['cmp_name'] ?? ($_SESSION['company_cmp_name'] ?? $activeCmp);
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// Helper cURL Upload Multipart Form-data
function uploadFileToCeisa($url, $filePath, $fileName, $paramJson, $bearer, $apiKey) {
    $curl = curl_init();
    
    // PHP 5.5+ CURLFile
    $cFile = new CURLFile($filePath, mime_content_type($filePath) ?: 'application/octet-stream', $fileName);
    
    $postFields = [
        'file'  => $cFile,
        'param' => is_string($paramJson) ? $paramJson : json_encode($paramJson)
    ];

    $headers = [
        "Authorization: Bearer {$bearer}",
        "Beacukai-Api-Key: {$apiKey}",
        "Accept: application/json, text/plain, */*"
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => "",
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => "POST",
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    return [
        'http_code' => $httpCode,
        'response'  => $response,
        'error'     => $err
    ];
}

// -------------------------------------------------------------
// AJAX API HANDLER
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action   = $_POST['action'];
    $nomorAju = trim($_POST['nomor_aju'] ?? '');
    $npwpVal  = trim($_POST['npwp'] ?? ($active_company['npwp'] ?? ''));

    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU wajib diisi!']);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Berkas file tidak ditemukan atau terjadi kesalahan saat upload.']);
        exit;
    }

    $tmpPath  = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'];

    // 1. Upload Dokumen Pelengkap Single (PDF)
    if ($action === 'upload_dokumen') {
        $seriDokumen = (int)($_POST['seri_dokumen'] ?? 1);
        $param = [
            "nomorAju"    => $nomorAju,
            "seriDokumen" => $seriDokumen,
            "npwp"        => preg_replace('/\D/', '', $npwpVal)
        ];

        $targetUrl = "https://apis-gw.beacukai.go.id/v2/openapi/file/dokumen";
        $call = uploadFileToCeisa($targetUrl, $tmpPath, $origName, $param, $bearer, $apiKey);
        $resJson = json_decode($call['response'], true);

        if ($call['http_code'] === 200 || $call['http_code'] === 201) {
            echo json_encode([
                'status'   => 'OK',
                'message'  => "Dokumen pelengkap Seri #{$seriDokumen} berhasil diunggah ke CEISA 4.0!",
                'response' => $resJson ?: $call['response']
            ]);
        } else {
            $msg = $resJson['message'] ?? ($call['error'] ?: "HTTP Error {$call['http_code']}");
            echo json_encode(['status' => 'ERROR', 'message' => "Gagal unggah dokumen: {$msg}", 'raw' => $call]);
        }
        exit;
    }

    // 2. Upload Gambar Fisik Barang (JPG/PNG)
    if ($action === 'upload_barang') {
        $seriBarang = (int)($_POST['seri_barang'] ?? 1);
        $keterangan = trim($_POST['keterangan'] ?? "Gambar Barang Seri {$seriBarang}");
        $param = [
            "keterangan"  => $keterangan,
            "nomorAju"    => $nomorAju,
            "seriBarang"  => $seriBarang,
            "npwp"        => preg_replace('/\D/', '', $npwpVal)
        ];

        $targetUrl = "https://apis-gw.beacukai.go.id/v2/openapi/file/barang";
        $call = uploadFileToCeisa($targetUrl, $tmpPath, $origName, $param, $bearer, $apiKey);
        $resJson = json_decode($call['response'], true);

        if ($call['http_code'] === 200 || $call['http_code'] === 201) {
            echo json_encode([
                'status'   => 'OK',
                'message'  => "Gambar barang Seri #{$seriBarang} berhasil diunggah ke CEISA 4.0!",
                'response' => $resJson ?: $call['response']
            ]);
        } else {
            $msg = $resJson['message'] ?? ($call['error'] ?: "HTTP Error {$call['http_code']}");
            echo json_encode(['status' => 'ERROR', 'message' => "Gagal unggah gambar: {$msg}", 'raw' => $call]);
        }
        exit;
    }

    // 3. Upload Dokumen NPD (Nota Pemberitahuan Dokumen)
    if ($action === 'upload_npd') {
        $seriDokumen = (int)($_POST['seri_dokumen'] ?? 1);
        $param = [
            "nomorAju"    => $nomorAju,
            "seriDokumen" => $seriDokumen,
            "npwp"        => preg_replace('/\D/', '', $npwpVal)
        ];

        $targetUrl = "https://apis-gw.beacukai.go.id/v2/openapi/file/upload-dokap-npd";
        $call = uploadFileToCeisa($targetUrl, $tmpPath, $origName, $param, $bearer, $apiKey);
        $resJson = json_decode($call['response'], true);

        if ($call['http_code'] === 200 || $call['http_code'] === 201) {
            echo json_encode([
                'status'   => 'OK',
                'message'  => "Dokumen NPD Seri #{$seriDokumen} berhasil diunggah ke CEISA 4.0!",
                'response' => $resJson ?: $call['response']
            ]);
        } else {
            $msg = $resJson['message'] ?? ($call['error'] ?: "HTTP Error {$call['http_code']}");
            echo json_encode(['status' => 'ERROR', 'message' => "Gagal unggah NPD: {$msg}", 'raw' => $call]);
        }
        exit;
    }

    echo json_encode(['status' => 'ERROR', 'message' => 'Action tidak valid.']);
    exit;
}

// -------------------------------------------------------------
// UI SCREEN (Jika diakses via browser)
// -------------------------------------------------------------
$nomorAjuReq = $_GET['nomor_aju'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Dokumen Pelengkap & Gambar Barang - CEISA 4.0</title>
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || '<?= $themeParam ?>' || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        })();
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border-card: #e8e8e8;
            --border-input: #d9d9d9;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            color-scheme: light;
        }
        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --border-card: #303030;
            --border-input: #434343;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --bg-filter: #1a1a27;
            color-scheme: dark !important;
        }
        html[data-theme="dark"], html[data-theme="dark"] body, body[data-theme="dark"] {
            color-scheme: dark !important;
        }
        html[data-theme="light"], html[data-theme="light"] body, body[data-theme="light"] {
            color-scheme: light !important;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            padding: 6px 10px;
            font-size: 10.5px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Full-Width Ant Design Card Layout */
        .ant-card {
            box-sizing: border-box;
            background: var(--bg-card);
            border-radius: 3px;
            position: relative;
            width: 100%;
            transition: all .2s;
        }
        .ant-card-bordered {
            border: 1px solid var(--border-card);
        }
        .ant-card-head {
            min-height: 38px;
            padding: 0 12px;
            color: var(--text-heading);
            font-weight: 600;
            font-size: 11.5px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .ant-card-head-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ant-card-body {
            padding: 12px 14px;
        }

        /* Modern Tabs Pill */
        .upload-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
            border-bottom: 1px solid var(--border-card);
            padding-bottom: 8px;
            flex-wrap: wrap;
        }
        .upload-tab-btn {
            background: var(--bg-filter);
            border: 1px solid var(--border-input);
            border-radius: 4px;
            color: var(--text-muted);
            padding: 5px 12px;
            font-size: 10.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .upload-tab-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
        .upload-tab-btn.active {
            background: var(--primary) !important;
            color: #ffffff !important;
            border-color: var(--primary) !important;
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.35);
        }

        /* Form Layout */
        .form-wrap {
            max-width: 680px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 10px;
        }
        .form-group label {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-control {
            background-color: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: 3px;
            padding: 3px 8px;
            color: var(--text-main);
            font-size: 11px;
            height: 28px;
            outline: none;
            font-family: inherit;
            transition: all 0.2s;
            width: 100%;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.18);
        }
        input[type="file"].form-control {
            height: auto;
            padding: 5px 8px;
            cursor: pointer;
        }

        /* Modern Ant Design Button */
        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 28px;
            padding: 0 14px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .ant-btn-primary {
            color: #ffffff;
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.35);
        }
        .ant-btn-primary:hover {
            background: linear-gradient(135deg, #40a9ff 0%, #1890ff 100%);
            box-shadow: 0 4px 12px rgba(24, 144, 255, 0.45);
            transform: translateY(-1px);
        }
        .ant-btn-primary:active {
            transform: translateY(0);
        }

        /* MODERN DRAG & DROP ZONE */
        .dropzone {
            border: 2px dashed var(--border-input);
            background: var(--bg-filter);
            border-radius: 6px;
            padding: 18px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            user-select: none;
        }
        .dropzone:hover, .dropzone.dragover {
            border-color: var(--primary);
            background: rgba(24, 144, 255, 0.05);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(24, 144, 255, 0.12);
        }
        .dropzone.dragover {
            border-style: solid;
            border-color: var(--primary);
            background: rgba(24, 144, 255, 0.1);
            transform: scale(1.01);
        }
        .dropzone-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .dropzone-icon {
            font-size: 32px;
            line-height: 1;
            margin-bottom: 4px;
            transition: transform 0.2s ease;
        }
        .dropzone:hover .dropzone-icon, .dropzone.dragover .dropzone-icon {
            transform: scale(1.12) rotate(-4deg);
        }
        .dropzone-title {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .dropzone-desc {
            font-size: 10px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .btn-browse-file {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--bg-card);
            border: 1px solid var(--border-input);
            color: var(--text-main);
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .btn-browse-file:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* PREVIEW BOX */
        .dropzone-preview {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            padding: 10px 14px;
            text-align: left;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .preview-media {
            width: 48px;
            height: 48px;
            border-radius: 4px;
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .preview-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .preview-media .preview-file-icon {
            font-size: 24px;
            line-height: 1;
        }
        .preview-info {
            flex: 1;
            min-width: 0;
        }
        .preview-filename {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-heading);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }
        .preview-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 9.5px;
            color: var(--text-muted);
        }
        .preview-badge {
            background: rgba(24, 144, 255, 0.12);
            color: var(--primary);
            border: 1px solid rgba(24, 144, 255, 0.3);
            padding: 1px 6px;
            border-radius: 3px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .preview-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-change-file {
            background: var(--bg-filter);
            border: 1px solid var(--border-input);
            color: var(--text-main);
            border-radius: 3px;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 500;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        .btn-change-file:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .btn-remove-file {
            background: #ff4d4f;
            border: none;
            color: #fff;
            border-radius: 3px;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-remove-file:hover {
            background: #cf1322;
        }

        /* Result Log */
        #resultLog {
            background: var(--bg-input);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            padding: 10px 12px;
            font-family: 'JetBrains Mono', Consolas, monospace;
            font-size: 10px;
            margin-top: 14px;
            display: none;
            white-space: pre-wrap;
            word-break: break-all;
            max-width: 680px;
            max-height: 260px;
            overflow-y: auto;
            color: var(--text-main);
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($themeParam) ?>">
    <!-- MAIN CONTAINER CARD (.ant-card .ant-card-bordered) -->
    <div class="ant-card ant-card-bordered">
        <!-- CARD HEADER -->
        <div class="ant-card-head">
            <div class="ant-card-head-title">
                <span style="font-size: 16px;">📎</span>
                <span style="font-weight: 600; font-size: 11.5px; color: var(--text-heading);">Upload Dokumen Pelengkap & Gambar Barang CEISA 4.0</span>
            </div>
            <div style="font-size: 10px; color: var(--text-muted);">
                <span style="background: var(--bg-filter); border: 1px solid var(--border-card); padding: 2px 8px; border-radius: 3px;">
                    🏢 <?= htmlspecialchars($sessionCmpName) ?> (<?= htmlspecialchars($activeCmp) ?>)
                </span>
            </div>
        </div>

        <div class="ant-card-body">
            <!-- TABS -->
            <div class="upload-tabs">
                <button type="button" class="upload-tab-btn active" onclick="switchUploadTab('dokumen')">📄 Dokumen Pelengkap (PDF)</button>
                <button type="button" class="upload-tab-btn" onclick="switchUploadTab('barang')">🖼️ Gambar Barang (Foto)</button>
                <button type="button" class="upload-tab-btn" onclick="switchUploadTab('npd')">📑 Dokumen NPD (PDF)</button>
            </div>

            <!-- FORM CONTAINER -->
            <div class="form-wrap">
                <form id="formUpload" onsubmit="submitUpload(event)">
                    <input type="hidden" id="uploadAction" value="upload_dokumen">

                    <div class="form-group">
                        <label>Nomor AJU (26 Digit)</label>
                        <input type="text" id="nomorAju" class="form-control" required value="<?= htmlspecialchars($nomorAjuReq) ?>" placeholder="Contoh: 000020SUJ64320260130000001">
                    </div>

                    <div class="form-group">
                        <label>NPWP Perusahaan</label>
                        <input type="text" id="npwp" class="form-control" required value="<?= htmlspecialchars($active_company['npwp'] ?? '') ?>">
                    </div>

                    <div class="form-group" id="groupSeriDokumen">
                        <label>Seri Dokumen (Sesuai bc_ceisa_dokumen)</label>
                        <input type="number" id="seriDokumen" class="form-control" value="1" min="1">
                    </div>

                    <div class="form-group" id="groupSeriBarang" style="display:none;">
                        <label>Seri Barang (Sesuai bc_ceisa_barang)</label>
                        <input type="number" id="seriBarang" class="form-control" value="1" min="1">
                    </div>

                    <div class="form-group" id="groupKeterangan" style="display:none;">
                        <label>Keterangan Gambar Barang</label>
                        <input type="text" id="keteranganBarang" class="form-control" placeholder="Contoh: Foto Fisik Komoditas Barang">
                    </div>

                    <div class="form-group">
                        <label id="lblFile">Berkas Lampiran PDF Dokumen</label>
                        
                        <!-- MODERN DRAG & DROP ZONE -->
                        <div class="dropzone" id="dropArea" onclick="triggerBrowse()">
                            <input type="file" id="fileUpload" style="display:none;" accept=".pdf" onchange="handleFileSelect(this.files)">
                            
                            <!-- Empty State -->
                            <div id="dropzoneEmpty" class="dropzone-content">
                                <div class="dropzone-icon" id="dropzoneIcon">📄</div>
                                <div class="dropzone-title" id="dropzoneTitle">Tarik & Lepaskan Berkas PDF di Sini</div>
                                <div class="dropzone-desc" id="dropzoneDesc">atau klik untuk memilih dokumen dari komputer (Maks. 5 MB)</div>
                                <button type="button" class="btn-browse-file" onclick="event.stopPropagation(); triggerBrowse();">
                                    <span>📁</span> <span>Pilih Berkas</span>
                                </button>
                            </div>

                            <!-- Preview State -->
                            <div id="dropzonePreview" class="dropzone-preview" style="display:none;" onclick="event.stopPropagation()">
                                <div class="preview-media" id="previewMedia">
                                    <span class="preview-file-icon">📄</span>
                                </div>
                                <div class="preview-info">
                                    <div class="preview-filename" id="previewFileName">nama_berkas.pdf</div>
                                    <div class="preview-meta">
                                        <span class="preview-size" id="previewFileSize">0 KB</span>
                                        <span class="preview-badge" id="previewFileBadge">PDF</span>
                                    </div>
                                </div>
                                <div class="preview-actions">
                                    <button type="button" class="btn-change-file" onclick="event.stopPropagation(); triggerBrowse();" title="Ganti Berkas">
                                        <span>🔄 Ganti</span>
                                    </button>
                                    <button type="button" class="btn-remove-file" onclick="event.stopPropagation(); clearSelectedFile();" title="Hapus Berkas">
                                        ✕
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="ant-btn ant-btn-primary" id="btnUpload" style="margin-top: 4px;">
                        <span>🚀</span>
                        <span>Unggah ke CEISA 4.0</span>
                    </button>
                </form>

                <pre id="resultLog"></pre>
            </div>
        </div>
    </div>

    <script>
        let currentUploadType = 'dokumen';
        let selectedFile = null;

        function triggerBrowse() {
            const fInp = document.getElementById('fileUpload');
            if (fInp) fInp.click();
        }

        function formatBytes(bytes, decimals = 1) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        function clearSelectedFile() {
            selectedFile = null;
            const fileInput = document.getElementById('fileUpload');
            if (fileInput) fileInput.value = '';
            
            document.getElementById('dropzoneEmpty').style.display = 'flex';
            document.getElementById('dropzonePreview').style.display = 'none';
            document.getElementById('previewMedia').innerHTML = '<span class="preview-file-icon">📄</span>';
            document.getElementById('previewFileName').innerText = '';
            document.getElementById('previewFileSize').innerText = '';
            document.getElementById('previewFileBadge').innerText = '';
        }

        function handleFileSelect(files) {
            if (!files || files.length === 0) return;
            const file = files[0];
            const ext = file.name.split('.').pop().toLowerCase();

            // Validasi format sesuai tab aktif
            if (currentUploadType === 'barang') {
                if (!['jpg', 'jpeg', 'png'].includes(ext)) {
                    if (typeof showWarning === 'function') {
                        showWarning('Format berkas tidak sesuai! Harap pilih gambar dengan format JPG atau PNG.', 'Format Berkas Salah');
                    } else {
                        alert('Format berkas tidak sesuai! Harap pilih gambar dengan format JPG atau PNG.');
                    }
                    clearSelectedFile();
                    return;
                }
            } else {
                if (ext !== 'pdf') {
                    if (typeof showWarning === 'function') {
                        showWarning('Format berkas tidak sesuai! Harap pilih dokumen dengan format PDF.', 'Format Berkas Salah');
                    } else {
                        alert('Format berkas tidak sesuai! Harap pilih dokumen dengan format PDF.');
                    }
                    clearSelectedFile();
                    return;
                }
            }

            selectedFile = file;

            // Masukkan ke input file agar sinkron
            try {
                const dt = new DataTransfer();
                dt.items.add(file);
                document.getElementById('fileUpload').files = dt.files;
            } catch(e) {}

            // Tampilkan preview UI
            document.getElementById('dropzoneEmpty').style.display = 'none';
            document.getElementById('dropzonePreview').style.display = 'flex';
            document.getElementById('previewFileName').innerText = file.name;
            document.getElementById('previewFileSize').innerText = formatBytes(file.size);
            document.getElementById('previewFileBadge').innerText = ext.toUpperCase();

            const mediaEl = document.getElementById('previewMedia');
            if (currentUploadType === 'barang' && ['jpg', 'jpeg', 'png'].includes(ext)) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    mediaEl.innerHTML = `<img src="${e.target.result}" alt="Preview" style="width:100%; height:100%; object-fit:cover; border-radius:3px;">`;
                };
                reader.readAsDataURL(file);
            } else {
                mediaEl.innerHTML = '<span class="preview-file-icon">📄</span>';
            }
        }

        function switchUploadTab(type) {
            currentUploadType = type;
            document.querySelectorAll('.upload-tab-btn').forEach(b => b.classList.remove('active'));
            if (window.event && window.event.target) {
                window.event.target.classList.add('active');
            }

            const act = document.getElementById('uploadAction');
            const gDok = document.getElementById('groupSeriDokumen');
            const gBar = document.getElementById('groupSeriBarang');
            const gKet = document.getElementById('groupKeterangan');
            const fInp = document.getElementById('fileUpload');
            const fLbl = document.getElementById('lblFile');
            const dIcon = document.getElementById('dropzoneIcon');
            const dTitle = document.getElementById('dropzoneTitle');
            const dDesc = document.getElementById('dropzoneDesc');

            // Reset file saat berpindah tab
            clearSelectedFile();

            if (type === 'dokumen') {
                act.value = 'upload_dokumen';
                gDok.style.display = 'flex';
                gBar.style.display = 'none';
                gKet.style.display = 'none';
                fLbl.innerText = 'Berkas PDF Dokumen Pelengkap';
                fInp.accept = '.pdf';
                dIcon.innerText = '📄';
                dTitle.innerText = 'Tarik & Lepaskan Berkas PDF di Sini';
                dDesc.innerText = 'atau klik untuk memilih dokumen dari komputer (Maks. 5 MB)';
            } else if (type === 'barang') {
                act.value = 'upload_barang';
                gDok.style.display = 'none';
                gBar.style.display = 'flex';
                gKet.style.display = 'flex';
                fLbl.innerText = 'Berkas Gambar Fisik Barang (JPG/PNG)';
                fInp.accept = '.jpg,.jpeg,.png';
                dIcon.innerText = '🖼️';
                dTitle.innerText = 'Tarik & Lepaskan Gambar Barang di Sini';
                dDesc.innerText = 'Format JPG atau PNG (foto fisik komoditas, maks. 5 MB)';
            } else if (type === 'npd') {
                act.value = 'upload_npd';
                gDok.style.display = 'flex';
                gBar.style.display = 'none';
                gKet.style.display = 'none';
                fLbl.innerText = 'Berkas PDF Dokumen NPD';
                fInp.accept = '.pdf';
                dIcon.innerText = '📑';
                dTitle.innerText = 'Tarik & Lepaskan Berkas PDF NPD di Sini';
                dDesc.innerText = 'atau klik untuk memilih dokumen NPD dari komputer (Maks. 5 MB)';
            }
        }

        function initDragAndDrop() {
            const dropArea = document.getElementById('dropArea');
            if (!dropArea) return;

            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropArea.classList.add('dragover');
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropArea.classList.remove('dragover');
                }, false);
            });

            dropArea.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length > 0) {
                    handleFileSelect(dt.files);
                }
            }, false);
        }

        async function submitUpload(e) {
            e.preventDefault();
            const btn = document.getElementById('btnUpload');
            const log = document.getElementById('resultLog');
            const fData = new FormData();

            fData.append('action', document.getElementById('uploadAction').value);
            fData.append('nomor_aju', document.getElementById('nomorAju').value.trim());
            fData.append('npwp', document.getElementById('npwp').value.trim());
            fData.append('seri_dokumen', document.getElementById('seriDokumen').value);
            fData.append('seri_barang', document.getElementById('seriBarang').value);
            fData.append('keterangan', document.getElementById('keteranganBarang').value.trim());

            const file = selectedFile || document.getElementById('fileUpload').files[0];
            if (!file) {
                if (typeof showWarning === 'function') {
                    showWarning('Silakan pilih atau tarik berkas file terlebih dahulu!', 'Berkas Belum Dipilih');
                } else {
                    alert('Silakan pilih atau tarik berkas file terlebih dahulu!');
                }
                return;
            }
            fData.append('file', file);

            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span> <span>Mengunggah ke Gateway...</span>';
            log.style.display = 'block';
            log.innerText = 'Menghubungi https://apis-gw.beacukai.go.id/v2/openapi/file/...';

            try {
                const res = await fetch('upload_dokap.php', { method: 'POST', body: fData });
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = '<span>🚀</span> <span>Unggah ke CEISA 4.0</span>';

                log.innerText = JSON.stringify(json, null, 2);
                if (json.status === 'OK') {
                    if (typeof showSuccess === 'function') {
                        showSuccess(json.message);
                    } else {
                        alert(json.message);
                    }
                    clearSelectedFile();
                } else {
                    if (typeof showError === 'function') {
                        showError(json.message);
                    } else {
                        alert(json.message);
                    }
                }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = '<span>🚀</span> <span>Unggah ke CEISA 4.0</span>';
                log.innerText = 'Error: ' + err.message;
            }
        }

        function applyCurrentTheme(th) {
            if (!th) {
                var urlParams = new URLSearchParams(window.location.search);
                th = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            }
            if (th !== 'light' && th !== 'dark') th = 'light';
            document.documentElement.setAttribute('data-theme', th);
            document.documentElement.style.colorScheme = th;
            document.body.setAttribute('data-theme', th);
        }
        applyCurrentTheme();

        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                applyCurrentTheme(e.data.theme);
            }
        });

        // Inisialisasi drag & drop saat DOM selesai dimuat
        document.addEventListener('DOMContentLoaded', function() {
            initDragAndDrop();
        });
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            initDragAndDrop();
        }
    </script>
</body>
</html>

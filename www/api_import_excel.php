<?php
/**
 * CEISA 4.0 Excel Importer REST API (api_import_excel.php)
 * Endpoint tanpa UI untuk mengimpor file Excel CEISA 4.0 ke tabel database.
 * Bisa dipanggil dari cURL, HTTP Client, Python, maupun sistem eksternal.
 * 
 * Parameter POST:
 *   - file: Upload file .xlsx (multipart/form-data)
 *   - ATAU file_name: Nama file di folder share/xls (contoh: bc-23.xlsx)
 *   - cmp_id: Company ID / NPWP (opsional, fallback ke perusahaan aktif)
 *   - overwrite: 1 (default) / 0
 *   - action: 'import' (default) / 'preview'
 */

header('Content-Type: application/json; charset=utf-8');

// Matikan output error langsung agar JSON tidak rusak
error_reporting(E_ALL);
ini_set('display_errors', '0');
@ini_set('memory_limit', '1024M');
@set_time_limit(600);

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/CeisaXlsxImporter.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'status'  => 'ERROR',
            'message' => 'Hanya metode HTTP POST yang didukung.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $candidateDirs = [
        __DIR__ . '/../xls',
        __DIR__ . '/xls',
        __DIR__ . '/../share/xls',
        '/source/share/xls',
        '/source/public/xls',
        'C:/dp/g-ceisa/share/xls'
    ];
    $xlsFolder = null;
    foreach ($candidateDirs as $cd) {
        if (is_dir($cd)) {
            $xlsFolder = realpath($cd);
            break;
        }
    }
    $tempFileToDelete = null;
    $targetFilePath = null;
    $origName = 'unknown.xlsx';

    // 1. Cek jika ada file yang di-upload (tunggal 'file' atau array 'files')
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $uploadedTmp = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext !== 'xlsx') {
            throw new Exception("Hanya file format .xlsx yang didukung: " . $origName);
        }

        $targetFilePath = $uploadedTmp;
    }
    elseif (isset($_FILES['files']['name'][0]) && $_FILES['files']['error'][0] === UPLOAD_ERR_OK) {
        $uploadedTmp = $_FILES['files']['tmp_name'][0];
        $origName = $_FILES['files']['name'][0];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext !== 'xlsx') {
            throw new Exception("Hanya file format .xlsx yang didukung: " . $origName);
        }

        $targetFilePath = $uploadedTmp;
    }
    // 2. Cek jika memilih file lokal dari folder share/xls
    elseif (!empty($_POST['file_name']) || !empty($_GET['file_name'])) {
        $fileName = basename($_POST['file_name'] ?? $_GET['file_name']);
        $origName = $fileName;
        if (!$xlsFolder) {
            throw new Exception("Folder xls tidak ditemukan di server.");
        }
        $candidate = $xlsFolder . DIRECTORY_SEPARATOR . $fileName;

        if (!file_exists($candidate)) {
            throw new Exception("File tidak ditemukan di folder xls: " . $fileName);
        }
        $targetFilePath = $candidate;
    }
    // 3. Cek jika diberikan path absolut
    elseif (!empty($_POST['file_path'])) {
        $candidate = $_POST['file_path'];
        $origName = basename($candidate);
        if (!file_exists($candidate)) {
            throw new Exception("File tidak ditemukan: " . $candidate);
        }
        $targetFilePath = $candidate;
    } else {
        throw new Exception("Tidak ada file Excel yang diberikan. Kirim file via upload 'file' atau parameter 'file_name'.");
    }

    // Tentukan cmp_id
    $targetCmpId = trim($_POST['cmp_id'] ?? ($_GET['cmp_id'] ?? ($cmp_id ?? '')));
    if (empty($targetCmpId)) {
        // Fallback ke perusahaan aktif pertama dari database
        $comp = ceisa_get_company();
        if ($comp) {
            $targetCmpId = $comp['cmp_id'];
        }
    }

    $importer = new CeisaXlsxImporter($koneksi, $targetCmpId);

    $action = $_POST['action'] ?? ($_GET['action'] ?? 'import');

    // Jika hanya preview
    if ($action === 'preview') {
        $parsed = $importer->parseXlsx($targetFilePath);
        $summary = [];
        $kodeDokumen = '';
        $allAjus = [];

        if (!empty($parsed['HEADER'])) {
            foreach ($parsed['HEADER'] as $hRow) {
                if (!empty($hRow['nomor_aju'])) {
                    $allAjus[] = trim($hRow['nomor_aju']);
                }
                if (empty($kodeDokumen) && !empty($hRow['kode_dokumen'])) {
                    $kodeDokumen = trim($hRow['kode_dokumen']);
                }
            }
            $allAjus = array_values(array_unique(array_filter($allAjus)));
        }

        foreach ($parsed as $sheetName => $rows) {
            $summary[$sheetName] = [
                'table'     => CeisaXlsxImporter::$sheetTableMap[$sheetName] ?? null,
                'row_count' => count($rows),
                'sample'    => !empty($rows[0]) ? array_slice($rows[0], 0, 5) : []
            ];
        }

        echo json_encode([
            'status'       => 'OK',
            'action'       => 'preview',
            'file_name'    => $origName,
            'cmp_id'       => $targetCmpId,
            'nomor_aju'    => (count($allAjus) === 1 ? ($allAjus[0] ?? '') : $allAjus),
            'total_ajus'   => count($allAjus),
            'kode_dokumen' => $kodeDokumen,
            'sheets'       => $summary
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Jalankan proses impor ke database
    $overwrite = isset($_POST['overwrite']) ? (bool)$_POST['overwrite'] : true;
    $result = $importer->import($targetFilePath, [
        'cmp_id'    => $targetCmpId,
        'overwrite' => $overwrite
    ]);
    if (!empty($origName)) {
        $result['file_name'] = $origName;
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status'    => 'ERROR',
        'file_name' => $origName ?? 'unknown.xlsx',
        'message'   => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>

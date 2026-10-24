<?php
/**
 * API Detail Rekap Dokumen CEISA 4.0
 */
require_once __DIR__ . '/../initmfa.php';
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../datalogin.php';

header('Content-Type: application/json; charset=utf-8');

$kodeDokumen = $_GET['kodeDokumen'] ?? '';
$ym          = $_GET['ym'] ?? '';
$unfin       = isset($_GET['unfin']) && $_GET['unfin'] == '1' ? 1 : 0;
$targetCmp   = $_GET['cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));

// Update active company jika berbeda
if (!empty($targetCmp) && $targetCmp !== $cmp_id) {
    $cRow = ceisa_get_company($targetCmp);
    if ($cRow) {
        $namaperusahaan = $cRow['cmp_name'];
        $cmp_id         = $cRow['cmp_id'];
    }
}

$cleanNamaPrs = $koneksi->real_escape_string($namaperusahaan ?? 'FRAMAS');
$cleanCmpId   = $koneksi->real_escape_string($cmp_id ?? '');

// bc_ceisa_data bersifat shared lintas plant
$whereCmp = "";

$unfinSql = "";
if ($unfin) {
    $unfinSql = "AND a.namaProses <> 'Selesai Proses'";
}

$query = "
SELECT 
    date_format(a.tanggalDaftar, '%Y-%m') as ym, 
    date_format(a.tanggalDaftar, '%Y-%m-%d') as tanggalDaftar,  
    a.nomorDaftar, 
    a.nomorAju, 
    a.namaPerusahaan, 
    a.idPerusahaan,
    CASE WHEN a.kodeDokumen = '27' AND (NOT a.namaPerusahaan LIKE '%$cleanNamaPrs%') THEN '27IN' ELSE a.kodeDokumen END as kodeDokumen,
    a.kodeJalur, 
    a.namaProses, 
    a.namaRespon,  
    a.nomorRespon, 
    a.asalData,
    a.pdf
FROM bc_ceisa_data a
WHERE date_format(a.tanggalDaftar, '%Y-%m') = ? 
  AND (CASE WHEN a.kodeDokumen = '27' AND (NOT a.namaPerusahaan LIKE '%$cleanNamaPrs%') THEN '27IN' ELSE a.kodeDokumen END) = ?
  $whereCmp
  $unfinSql
ORDER BY a.tanggalDaftar DESC, a.nomorAju DESC
";

$stmt = $koneksi->prepare($query);
if (!$stmt) {
    echo json_encode(['error' => $koneksi->error]);
    exit;
}

$stmt->bind_param("ss", $ym, $kodeDokumen);
$stmt->execute();
$result = $stmt->get_result();

$details = [];
while ($row = $result->fetch_assoc()) {
    $details[] = $row;
}

echo json_encode($details);

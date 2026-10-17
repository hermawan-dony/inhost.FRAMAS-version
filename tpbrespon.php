<?php
/**
 * CEISA 4.0 Respon PDF Exporter (tpbrespon.php)
 * Mengambil file PDF respon pabean yang tersimpan dalam format Base64 di database dan menyajikan ke browser.
 */
require_once __DIR__ . '/koneksi.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    die("Invalid ID.");
}

$stmt = $koneksi->prepare("SELECT BYTE_STREM_PDF FROM tpb_respon WHERE ID = ?");
if (!$stmt) {
    die("Query error.");
}

$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->store_result();
$stmt->bind_result($pdf_data);
$stmt->fetch();

if (empty($pdf_data)) {
    die("Data PDF tidak ditemukan untuk ID: " . $id);
}
$stmt->close();

$pdf_data_decoded = base64_decode($pdf_data);

header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename=\"respon_{$id}.pdf\"");
header("Content-Length: " . strlen($pdf_data_decoded));
echo $pdf_data_decoded;
exit;
?>

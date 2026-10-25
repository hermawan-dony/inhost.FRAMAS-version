<?php
/**
 * CEISA 4.0 - Download File Excel Resmi Sesuai Portal CEISA 4.0
 * Format .xlsx 21 sheet lengkap, format cell persis hasil unduh Portal CEISA 4.0
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/CeisaXlsxExporter.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$nomorAju = trim($_REQUEST['nomor_aju'] ?? ($_GET['aju'] ?? ''));
if (empty($nomorAju)) {
    http_response_code(400);
    die("Parameter nomor_aju atau aju tidak ditemukan.");
}

$exporter = new CeisaXlsxExporter($koneksi);
$exporter->streamDownload($nomorAju);

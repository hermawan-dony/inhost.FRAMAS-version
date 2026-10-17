<?php
/**
 * CEISA 4.0 JSON Generator & Exporter (genjson.php)
 * Membangun JSON dari tabel riil database (CeisaJsonBuilder) atau mengekspor dari bc_ceisa_json ke json.txt.
 * Mendukung parameter: nomor_aju, cmp_id, json=1
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/CeisaJsonBuilder.php';

$output_file = __DIR__ . DIRECTORY_SEPARATOR . 'json.txt';
$nomor_aju   = $_REQUEST['nomor_aju'] ?? $_REQUEST['aju'] ?? '';
$cmp_id      = $_REQUEST['cmp_id'] ?? '';
$wantsJson   = isset($_REQUEST['json']) && $_REQUEST['json'] == '1';

// 1. Jika nomor_aju diberikan, bangun JSON langsung dari tabel riil
if (!empty($nomor_aju)) {
    $result = CeisaJsonBuilder::generate($nomor_aju, $cmp_id);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }
    if ($result['status'] === 'OK') {
        echo "OK. Berhasil menyusun JSON untuk AJU: {$nomor_aju} (BC {$result['kode_dokumen']})";
    } else {
        echo "ERROR: " . $result['message'];
    }
    exit;
}

// 2. Fallback Mode Legacy: Dump dari bc_ceisa_json ke json.txt
$json_data = null;
if (isset($koneksi) && $koneksi) {
    if (!empty($cmp_id)) {
        $stmt = $koneksi->prepare("SELECT json FROM bc_ceisa_json WHERE cmp_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $cmp_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) {
                $json_data = $r['json'];
            }
            $stmt->close();
        }
    }
    if (empty($json_data)) {
        $res = mysqli_query($koneksi, "SELECT json FROM bc_ceisa_json LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $json_data = $row['json'];
        }
    }
    if (!empty($json_data)) {
        file_put_contents($output_file, $json_data);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'OK', 'bytes' => strlen($json_data), 'output_file' => $output_file]);
            exit;
        }
        echo "OK. Data berhasil di-dump ke: " . $output_file;
        exit;
    }
}

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada data JSON di tabel bc_ceisa_json']);
    exit;
}
echo "ERROR: Tidak ada data di bc_ceisa_json dan parameter nomor_aju tidak diberikan.";
?>
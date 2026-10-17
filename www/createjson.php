<?php
/**
 * CEISA 4.0 JSON Exporter (createjson.php)
 * Mengambil JSON dari tabel bc_ceisa_json dan menyimpannya ke json.txt
 */
include 'koneksi.php'; 

$base_dir = __DIR__ . DIRECTORY_SEPARATOR;
$output_file = $base_dir . "json.txt";

$json_data = null;

// Cara 1: Ambil langsung lewat koneksi mysqli
if (isset($koneksi) && $koneksi) {
    $res = mysqli_query($koneksi, "SELECT json FROM bc_ceisa_json LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $json_data = $row['json'];
        file_put_contents($output_file, $json_data);
        echo "OK. Data berhasil di-dump ke: " . $output_file;
        exit;
    }
}

// Cara 2: Fallback via mysql.exe
$mysql_path = $base_dir . "mysql.exe"; 
$query = "SELECT json FROM " . $database . ".bc_ceisa_json LIMIT 1"; 

$command = 
    escapeshellarg($mysql_path) . 
    " -u" . escapeshellarg($user) . 
    " -p" . escapeshellarg($password) . 
    " -h" . escapeshellarg($host) . 
    " -P " . escapeshellarg($port) . 
    " -N -e " . escapeshellarg($query) . 
    " > " . escapeshellarg($output_file);

exec($command, $output_array, $return_var);

if ($return_var === 0) {
    echo "OK. Data berhasil di-dump ke: " . $output_file;
} else {
    echo "Gagal menjalankan `" . $mysql_path . "`. Kode error: " . $return_var;
}
?>
<?php
/**
 * CEISA 4.0 Database Connection
 * Terhubung ke Alibaba Cloud RDS MySQL (ceisa0400db)
 */
$host     = 'rm-d9j4584f890612j7vro.mysql.ap-southeast-5.rds.aliyuncs.com';
$port     = 63306;
$user     = 'ceisarwuser';
$password = 'CiT8T2#h4Jd#3wer$9d36hd';
$database = 'ceisa0400db';

// Hubungkan ke database RDS Alibaba Cloud
$koneksi = @mysqli_connect($host, $user, $password, $database, $port);

// Fallback ke port database lokal jika koneksi langsung tidak tersedia
if (!$koneksi) {
    $koneksi = @mysqli_connect('127.0.0.1', $user, $password, $database, 3306);
}

if (!$koneksi) {
    die('Connection Error: ' . mysqli_connect_error());
}

mysqli_set_charset($koneksi, "utf8mb4");
?>

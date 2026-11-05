<?php
/**
 * CEISA 4.0 - Automated Dedicated Company Database Setup CLI Utility
 * 
 * Penggunaan CLI:
 *   php tools/setup_company_database.php <DB_NAME> <CMP_ID> [MASTER_DB]
 * 
 * Contoh:
 *   php tools/setup_company_database.php SNUDB SNU
 *   php tools/setup_company_database.php CLIENT2DB CL2
 */

if (php_sapi_name() !== 'cli') {
    die("Utilitas ini hanya dapat dijalankan melalui Command Line Interface (CLI).\n");
}

$targetDb = trim($argv[1] ?? '');
$cmpId    = trim($argv[2] ?? '');
$masterDb = trim($argv[3] ?? 'bc2faktur_inhost');

if (empty($targetDb) || empty($cmpId)) {
    echo "\n";
    echo "=====================================================================\n";
    echo " CEISA 4.0 - Setup Dedicated Client Database Utility\n";
    echo "=====================================================================\n";
    echo "Format Penggunaan:\n";
    echo "  php tools/setup_company_database.php <DB_NAME> <CMP_ID> [MASTER_DB]\n\n";
    echo "Contoh:\n";
    echo "  php tools/setup_company_database.php SNUDB SNU\n";
    echo "  php tools/setup_company_database.php ABC_DB ABC bc2faktur_inhost\n";
    echo "=====================================================================\n\n";
    exit(1);
}

require_once __DIR__ . '/../koneksi.php';

if (!$koneksi) {
    die("[ERROR] Gagal terhubung ke database server.\n");
}

echo "=====================================================================\n";
echo " Memulai Penyiapan Database Mandiri Klien\n";
echo " Target DB : $targetDb\n";
echo " Company ID: $cmpId\n";
echo " Master DB : $masterDb\n";
echo "=====================================================================\n\n";

// 1. Verifikasi Keberadaan Database Target
$checkDb = $koneksi->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$targetDb'");
if (!$checkDb || $checkDb->num_rows === 0) {
    echo "[1/4] Membuat database target '$targetDb'...\n";
    $koneksi->query("CREATE DATABASE `$targetDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} else {
    echo "[1/4] Database target '$targetDb' ditemukan.\n";
}

// 2. Scan dan ALTER seluruh tabel untuk menambahkan cmp_id jika belum ada
echo "[2/4] Memeriksa & menambahkan kolom cmp_id pada seluruh tabel...\n";
$tablesRes = $koneksi->query("
    SELECT TABLE_NAME 
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_TYPE = 'BASE TABLE'
      AND TABLE_NAME NOT IN ('ref_btki_2022', 'bc_app_version_log')
");

$alteredCount = 0;
$skippedCount = 0;

if ($tablesRes) {
    while ($tRow = $tablesRes->fetch_assoc()) {
        $tbl = $tRow['TABLE_NAME'];
        
        $cRes = $koneksi->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$targetDb' AND TABLE_NAME = '$tbl' AND COLUMN_NAME = 'cmp_id'");
        if ($cRes && $cRes->num_rows === 0) {
            $sql = "ALTER TABLE `$targetDb`.`$tbl` ADD COLUMN `cmp_id` VARCHAR(30) NOT NULL DEFAULT '$cmpId', ADD INDEX `idx_cmp_id` (`cmp_id`)";
            if ($koneksi->query($sql)) {
                $alteredCount++;
            } else {
                echo "  [WARN] Gagal alter tabel '$tbl': " . $koneksi->error . "\n";
            }
        } else {
            $skippedCount++;
        }
    }
}
echo "      -> $alteredCount tabel berhasil ditambahkan kolom cmp_id.\n";
echo "      -> $skippedCount tabel sudah memiliki kolom cmp_id.\n";

// 3. Sinkronisasi tabel pendukung CEISA 4.0 dari master DB
echo "[3/4] Menyinkronkan struktur tabel pendukung CEISA 4.0...\n";
$supportTables = [
    'bc_ceisa_sppb',
    'bc_ceisa_barangdokumen',
    'bc_ceisa_barangvd',
    'bc_ceisa_bahanbakudokumen',
    'bc_ceisa_komponenbiaya',
    'bc_ceisa_barangentitas',
    'bc_ceisa_barangspekkhusus',
    'bc_ceisa_setting_kolom',
    'bc_ceisa_setting_tab',
    'bc_ceisa_setting_desimal',
    'bc_ceisa_versi',
    'bc_app_version_log',
    'bc_material_approval',
    'bc_kek_transaksi_log',
    'bc_kek_transaksi_detail',
    'bc_ppkek_masuk_header',
    'bc_ppkek_masuk_detail',
    'bc_ppkek_masuk_kontainer',
    'bc_ceisa_odoo_header',
    'bc_ceisa_odoo_detail',
    'bc_ceisa_odoo_bom',
    'bc_ceisa_manifes_header',
    'bc_ceisa_manifes_bl',
    'bc_ceisa_manifes_bl_dokumen',
    'bc_ceisa_manifes_bl_hs',
    'bc_ceisa_manifes_bl_petikemas',
    'bc_ceisa_manifes_kelompok_pos',
    'bc_ceisa_manifes_lampiran',
    'bc_ceisa_manifes_master_bl',
    'bc_ceisa_manifes_respon_logs'
];

foreach ($supportTables as $st) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `$targetDb`.`$st` LIKE `$masterDb`.`$st`");
}
echo "      -> " . count($supportTables) . " tabel pendukung CEISA 4.0 siap.\n";

// 4. Buat Views lintas database ke master DB
echo "[4/4] Membangun views lintas database ke master DB ($masterDb)...\n";
$views = [
    'bc_ceisa_company',
    'bc_kek_setting',
    'ref_btki_2022',
    'bc_kek_ref_dokumen',
    'bc_kek_ref_kategori',
    'bc_visitor_geo_cache',
    'bc_visitor_hits',
    'bc_visitor_log',
    'bc_visitor_menu_log'
];

foreach ($views as $vw) {
    $koneksi->query("CREATE OR REPLACE VIEW `$targetDb`.`$vw` AS SELECT * FROM `$masterDb`.`$vw`");
}
echo "      -> " . count($views) . " master views siap.\n";

echo "\n=====================================================================\n";
echo " ✅ SELESAI: Database mandiri '$targetDb' siap digunakan untuk '$cmpId'.\n";
echo "=====================================================================\n";

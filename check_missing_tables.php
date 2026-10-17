<?php
require_once __DIR__ . '/koneksi.php';

$neededTables = [
    'bc_1ab2',
    'bc_material',
    'bc_so0',
    'bc_so',
    'bc_issue_hdr',
    'bc_issue_dtl',
    'bc_ceisa_req_unpost',
    'bc_1ab2_revisi',
    'bc_ceisa_header',
    'bc_ceisa_barang',
    'bc_ceisa_dokumen',
    'bc_ceisa_entitas',
    'bc_ceisa_aju_posted',
    'bc_ceisa_status',
    'bc_ceisa_respons'
];

$existingTablesRes = $koneksi->query("SHOW TABLES");
$existing = [];
while ($r = $existingTablesRes->fetch_array()) {
    $existing[strtolower($r[0])] = true;
}

echo "=== CHECK HASIL PEMERIKSAAN TABEL DATABASE (ceisa0400db) ===\n";
$missing = [];
$found = [];

foreach ($neededTables as $tb) {
    if (isset($existing[strtolower($tb)])) {
        $cnt = $koneksi->query("SELECT COUNT(*) FROM `$tb`")->fetch_row()[0];
        $found[] = "$tb (ADA - $cnt baris)";
    } else {
        $missing[] = $tb;
    }
}

echo "\n[TABEL YANG DITEMUKAN]:\n";
foreach ($found as $f) echo "  - $f\n";

echo "\n[TABEL YANG TIDAK ADA / KURANG]:\n";
if (empty($missing)) {
    echo "  (Tidak ada, seluruh tabel tersedia!)\n";
} else {
    foreach ($missing as $m) echo "  - $m (TIDAK ADA)\n";
}

<?php
require_once '/source/public/koneksi.php';

function runSqlFile($conn, $file) {
    echo "Running $file...\n";
    if (!file_exists($file)) {
        echo "File $file not found!\n";
        return;
    }
    $sql = file_get_contents($file);
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "Completed $file successfully. Error (if any): " . ($conn->error ?: 'NONE') . "\n";
    } else {
        echo "Failed to run $file: " . $conn->error . "\n";
    }
}

runSqlFile($koneksi, '/tmp/gceisa4.sql');
runSqlFile($koneksi, '/tmp/gceisa5.sql');

echo "\n--- VERIFIKASI TABEL ---\n";
$tables = ['bc_1ab2_revisi', 'bc_ceisa_req_unpost', 'bc_so', 'bc_so0', 'bc_tipe'];
foreach ($tables as $t) {
    $res = $koneksi->query("SHOW TABLES LIKE '$t'");
    if ($res && $res->num_rows > 0) {
        $cnt = $koneksi->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0];
        echo "Tabel $t: ADA ($cnt baris)\n";
    } else {
        echo "Tabel $t: TIDAK ADA\n";
    }
}

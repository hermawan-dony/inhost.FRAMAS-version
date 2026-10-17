<?php
require '/source/public/koneksi.php';
$tbls = ['bc_ceisa_kontainer', 'bc_ceisa_bankdevisa', 'bc_ceisa_pungutan', 'bc_ceisa_jaminan', 'bc_ceisa_barangtarif', 'bc_ceisa_bahanbakutarif', 'bc_ceisa_bahanbaku'];
foreach ($tbls as $t) {
    echo "=== TABLE: $t ===\n";
    $res = $koneksi->query("DESCRIBE $t");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            echo "  " . sprintf("%-25s %-15s\n", $r['Field'], $r['Type']);
        }
    } else {
        echo "  [ERROR / NOT FOUND]\n";
    }
}

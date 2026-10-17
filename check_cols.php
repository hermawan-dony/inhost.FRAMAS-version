<?php
require '/source/public/koneksi.php';
echo "=== bc_ceisa_varheader (barang) ===\n";
$res = $koneksi->query("SELECT tabel, col_id, col_txt, isnum FROM bc_ceisa_varheader WHERE tabel='barang' ORDER BY idx");
while($r = $res->fetch_assoc()){
    echo sprintf("%-20s %-25s %-5s\n", $r['col_id'], $r['col_txt'], $r['isnum']);
}

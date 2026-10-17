<?php
require '/source/public/koneksi.php';
$res = $koneksi->query("DESCRIBE bc_ceisa_varheader");
while($r = $res->fetch_assoc()) {
    echo $r['Field'] . " | " . $r['Type'] . PHP_EOL;
}

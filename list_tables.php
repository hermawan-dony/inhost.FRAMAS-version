<?php
require '/source/public/koneksi.php';
$res = $koneksi->query("SHOW TABLES LIKE 'bc_ceisa_%'");
while($r = $res->fetch_row()) {
    echo $r[0] . PHP_EOL;
}

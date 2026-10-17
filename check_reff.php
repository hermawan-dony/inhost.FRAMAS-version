<?php
require '/source/public/koneksi.php';
$res = $koneksi->query("SELECT reff, COUNT(1) AS jml FROM bc_ceisa_reff GROUP BY reff ORDER BY reff ASC");
while ($r = $res->fetch_assoc()) {
    echo sprintf("%-30s : %d\n", $r['reff'], $r['jml']);
}

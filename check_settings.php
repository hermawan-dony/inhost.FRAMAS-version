<?php
require '/source/public/koneksi.php';
echo "--- bc_ceisa_varkolom ---" . PHP_EOL;
$res = $koneksi->query("DESCRIBE bc_ceisa_varkolom");
if ($res) while($r = $res->fetch_assoc()) echo $r['Field'].' | '.$r['Type'].PHP_EOL;
echo "COUNT: " . ($koneksi->query("SELECT count(1) FROM bc_ceisa_varkolom")->fetch_row()[0] ?? 0) . PHP_EOL;

echo "--- bc_ceisa_setting ---" . PHP_EOL;
$res2 = $koneksi->query("DESCRIBE bc_ceisa_setting");
if ($res2) while($r = $res2->fetch_assoc()) echo $r['Field'].' | '.$r['Type'].PHP_EOL;
$q2 = $koneksi->query("SELECT * FROM bc_ceisa_setting LIMIT 5");
if ($q2) while($r = $q2->fetch_assoc()) print_r($r);

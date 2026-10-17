<?php
require_once '/source/public/koneksi.php';
$res = $koneksi->query("DESCRIBE bc_ceisa_header");
while ($r = $res->fetch_assoc()) {
    echo "{$r['Field']} ({$r['Type']})\n";
}

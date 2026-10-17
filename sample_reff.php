<?php
require '/source/public/koneksi.php';
$reffs = ['UKURAN KONTAINER', 'JENIS KONTAINER', 'TIPE KONTAINER', 'BANK', 'JENIS JAMINAN', 'JENIS PUNGUTAN', 'FASILITAS TARIF', 'JENIS TARIF', 'ASAL BARANG'];
foreach ($reffs as $rf) {
    echo "=== REFF: $rf ===\n";
    $res = $koneksi->query("SELECT kode, teks FROM bc_ceisa_reff WHERE reff='$rf' AND (status IS NULL OR status<>'N') ORDER BY (kode+0) ASC, kode ASC LIMIT 10");
    while ($r = $res->fetch_assoc()) {
        echo "  " . $r['kode'] . " : " . $r['teks'] . "\n";
    }
}

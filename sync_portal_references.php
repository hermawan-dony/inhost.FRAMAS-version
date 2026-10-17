<?php
/**
 * Sync Official Reference Tables from Portal Bea Cukai OpenAPI
 * Source: https://openapi.beacukai.go.id/portal/pages/reference
 */
require_once '/source/public/koneksi.php';

$jsonFile = '/tmp/portal_reference_tables.json';
if (!file_exists($jsonFile)) {
    die("File $jsonFile not found!\n");
}

$tables = json_decode(file_get_contents($jsonFile), true);
if (!$tables) {
    die("Failed to parse JSON from $jsonFile\n");
}

echo "Loaded " . count($tables) . " reference tables from Portal.\n\n";

// Map Portal Header to bc_ceisa_reff `reff` name
function mapReffName($header) {
    $h = trim($header);
    $map = [
        "Referensi Asal Barang"                     => "ASAL BARANG",
        "Referensi Asal Barang FTZ"                 => "ASAL BARANG FTZ",
        "Referensi Bank"                            => "BANK",
        "Referensi Cara Angkut"                     => "CARA ANGKUT",
        "Referensi Cara Bayar"                      => "CARA BAYAR",
        "Referensi Cara Dagang"                     => "CARA DAGANG",
        "Referensi Daerah Asal"                     => "DAERAH ASAL",
        "Referensi Dokumen"                         => "DOKUMEN",
        "Referensi Entitas"                         => "ENTITAS",
        "Referensi Fasilitas"                       => "FASILITAS",
        "Referensi Fasilitas Tarif"                 => "FASILITAS TARIF",
        "Referensi Ijin"                            => "IJIN",
        "Referensi Incoterm"                        => "INCOTERM",
        "Referensi Jenis API"                       => "API",
        "Referensi Jenis Ekspor"                    => "JENIS EKSPOR",
        "Referensi Jenis Identitas"                 => "JENIS IDENTITAS",
        "Referensi Jenis Jaminan"                   => "JENIS JAMINAN",
        "Referensi Jenis Kemasan"                   => "JENIS KEMASAN",
        "Referensi Jenis Kontainer"                 => "JENIS KONTAINER",
        "Referensi Jenis Transaksi Perdagangan"     => "JENIS TRANSAKSI DAGANG",
        "Referensi Jenis Pengangkutan"              => "JENIS PENGANGKUTAN",
        "Referensi Jenis PIB / Prosedur"            => "JENIS PIB",
        "Referensi Kode Jenis Impor"                => "KODE JENIS IMPOR",
        "Referensi Jenis Pungutan"                  => "JENIS PUNGUTAN",
        "Referensi Jenis Tanda Pengaman"            => "JENIS TANDA PENGAMAN",
        "Referensi Jenis Tarif"                     => "JENIS TARIF",
        "Referensi Jenis TPB"                       => "JENIS TPB",
        "Referensi Jenis VD"                        => "JENIS VD",
        "Referensi Kantor"                          => "KANTOR",
        "Referensi Kategori Ekspor"                 => "KATEGORI EKSPOR",
        "Referensi Komoditi Cukai"                  => "KOMODITI CUKAI",
        "Referensi Kategori Barang"                 => "KATEGORI BARANG",
        "Referensi Kategori Keluar FTZ"             => "KATEGORI KELUAR FTZ",
        "Referensi Kategori Konsolidator"           => "KATEGORI KONSOLIDATOR",
        "Referensi Kategori Masuk FTZ"              => "KATEGORI MASUK FTZ",
        "Referensi Kode Laporan Inventori"          => "KODE LAPORAN INVENTORI",
        "Referensi Kondisi Barang"                  => "KONDISI BARANG",
        "Referensi Lokasi Bayar"                    => "LOKASI BAYAR",
        "Referensi Negara"                          => "NEGARA",
        "Referensi Respon"                          => "RESPON",
        "Referensi Satuan Barang"                   => "SATUAN BARANG",
        "Referensi Spesifikasi Khusus"              => "SPESIFIKASI KHUSUS",
        "Referensi Spesifikasi Khusus Detail"       => "SPESIFIKASI KHUSUS DETAIL",
        "Referensi Status"                          => "STATUS",
        "Referensi Status Pengusaha"                => "STATUS PENGUSAHA",
        "Referensi Tipe Kontainer"                  => "TIPE KONTAINER",
        "Referensi Tujuan Pemasukan"                => "TUJUAN PEMASUKAN",
        "Referensi Tujuan Pengeluaran"              => "TUJUAN PENGELUARAN",
        "Referensi Tujuan Pengiriman"               => "TUJUAN PENGIRIMAN",
        "Referensi Tutup Pu"                        => "TUTUP PU",
        "Referensi Ukuran Kontainer"                => "UKURAN KONTAINER",
        "Referensi Valuta"                          => "VALUTA",
        "Barang Kiriman - Jenis Aju"                => "BARANG KIRIMAN JENIS AJU",
        "Barang Kiriman - Kode Jenis PIBK"          => "BARANG KIRIMAN JENIS PIBK",
        "Barang Kiriman - Jenis Aju CN FTZ"         => "BARANG KIRIMAN JENIS AJU CN FTZ",
        "Barang Kiriman - Kode Jenis PIBK CN FTZ"   => "BARANG KIRIMAN JENIS PIBK CN FTZ",
        "Barang Kiriman - Kategori Barang Kiriman"  => "BARANG KIRIMAN KATEGORI",
        "Barang Kiriman - Kondisi Barang"           => "BARANG KIRIMAN KONDISI",
        "Barang Kiriman - Badan Usaha Penerima dan Pengirim" => "BARANG KIRIMAN BADAN USAHA",
        "Barang Kiriman - Kode Status"              => "BARANG KIRIMAN KODE STATUS",
        "TPS Online - Referensi Kode Dokumen In Out"=> "TPS KODE DOKUMEN IN OUT",
        "TPS Online - Kode Dokumen (Transaksi Coarri Codeco)" => "TPS KODE DOKUMEN COARRI CODECO"
    ];

    if (isset($map[$h])) {
        return $map[$h];
    }
    // Fallback: uppercase and strip 'Referensi '
    $clean = preg_replace('/^Referensi\s+/i', '', $h);
    return strtoupper(trim($clean));
}

$summary = [];
$totalRowsInserted = 0;

foreach ($tables as $tIdx => $t) {
    $rawHeader = $t['header'] ?? '';
    $reffName = mapReffName($rawHeader);
    $dataRows = $t['table']['data'] ?? [];
    
    if (empty($dataRows)) {
        continue;
    }
    
    // Check existing count in DB
    $resBefore = $koneksi->query("SELECT COUNT(1) as cnt FROM bc_ceisa_reff WHERE reff = '" . $koneksi->real_escape_string($reffName) . "'");
    $cntBefore = $resBefore ? (int)$resBefore->fetch_assoc()['cnt'] : 0;
    
    // Delete existing records for this specific category (except PELABUHAN)
    if ($reffName !== 'PELABUHAN') {
        $koneksi->query("DELETE FROM bc_ceisa_reff WHERE reff = '" . $koneksi->real_escape_string($reffName) . "'");
    }
    
    // Prepare rows for insertion
    $rowsToInsert = [];
    foreach ($dataRows as $r) {
        $kode = '';
        $teks = '';
        $grup = '-';
        $status = '';
        
        if (isset($r['tableKey'])) {
            $kode = trim((string)$r['tableKey']);
            $teks = trim((string)($r['tableValue'] ?? ''));
            if (isset($r['tipe'])) {
                $grup = trim((string)$r['tipe']);
            }
        } elseif (isset($r['firstColumn'])) {
            // Specialized multi-column mappings
            if ($rawHeader === 'Referensi Kategori Ekspor') {
                $kode = trim((string)$r['firstColumn']);
                $grup = trim((string)($r['secondColumn'] ?? '-'));
                $teks = trim((string)($r['thirdColumn'] ?? ''));
            } elseif ($rawHeader === 'Referensi Kategori Barang') {
                $grup = trim((string)$r['firstColumn']);
                $kode = trim((string)($r['secondColumn'] ?? ''));
                $teks = trim((string)($r['thirdColumn'] ?? ''));
            } elseif ($rawHeader === 'Referensi Respon') {
                $kode = trim((string)$r['firstColumn']);
                $grup = trim((string)($r['secondColumn'] ?? '-'));
                $teks = trim((string)($r['thirdColumn'] ?? ''));
            } elseif ($rawHeader === 'Referensi Spesifikasi Khusus') {
                $kode = trim((string)$r['firstColumn']);
                $grup = trim((string)($r['secondColumn'] ?? '-'));
                $teks = trim((string)($r['thirdColumn'] ?? ''));
            } elseif ($rawHeader === 'Referensi Spesifikasi Khusus Detail') {
                $grup = trim((string)$r['firstColumn']);
                $kode = trim((string)($r['secondColumn'] ?? ''));
                $teks = trim((string)($r['thirdColumn'] ?? '')) . ' (HS: ' . trim((string)($r['fourthColumn'] ?? '')) . ')';
            } elseif ($rawHeader === 'Referensi Tujuan Pemasukan' || $rawHeader === 'Referensi Tujuan Pengeluaran' || $rawHeader === 'Referensi Tujuan Pengiriman') {
                $grup = trim((string)$r['firstColumn']);
                $kode = trim((string)($r['secondColumn'] ?? ''));
                $teks = trim((string)($r['thirdColumn'] ?? ''));
            } elseif (isset($r['thirdColumn']) && !empty($r['thirdColumn'])) {
                $kode = trim((string)$r['firstColumn']);
                $teks = trim((string)$r['secondColumn']) . ' - ' . trim((string)$r['thirdColumn']);
            } else {
                $kode = trim((string)$r['firstColumn']);
                $teks = trim((string)($r['secondColumn'] ?? ''));
            }
        }
        
        if ($kode === '' && $teks === '') {
            continue;
        }
        
        $rowsToInsert[] = [
            'reff'   => $reffName,
            'grup'   => substr($grup ?: '-', 0, 100),
            'kode'   => substr($kode, 0, 50),
            'teks'   => substr($teks, 0, 1000),
            'status' => substr($status, 0, 1)
        ];
    }
    
    // Batch Insert (500 rows per query)
    $chunks = array_chunk($rowsToInsert, 500);
    $catInserted = 0;
    foreach ($chunks as $chunk) {
        $valuesArr = [];
        foreach ($chunk as $cr) {
            $escReff = "'" . $koneksi->real_escape_string($cr['reff']) . "'";
            $escGrup = "'" . $koneksi->real_escape_string($cr['grup']) . "'";
            $escKode = "'" . $koneksi->real_escape_string($cr['kode']) . "'";
            $escTeks = "'" . $koneksi->real_escape_string($cr['teks']) . "'";
            $escStat = "'" . $koneksi->real_escape_string($cr['status']) . "'";
            $valuesArr[] = "($escReff, $escGrup, $escKode, $escTeks, $escStat)";
        }
        $sql = "INSERT INTO bc_ceisa_reff (reff, grup, kode, teks, status) VALUES " . implode(',', $valuesArr);
        if ($koneksi->query($sql)) {
            $catInserted += count($chunk);
        } else {
            echo "Error inserting into $reffName: " . $koneksi->error . "\n";
        }
    }
    
    $totalRowsInserted += $catInserted;
    $summary[] = [
        'idx'       => $tIdx + 1,
        'portal_hdr'=> $rawHeader,
        'reff'      => $reffName,
        'before'    => $cntBefore,
        'after'     => $catInserted
    ];
    
    printf("[%2d] %-35s -> %-30s | Sblm: %4d | Skrg: %4d\n", $tIdx + 1, substr($rawHeader, 0, 35), $reffName, $cntBefore, $catInserted);
}

echo "\n======================================================\n";
echo "SELESAI! Total Baris Referensi Dimasukkan: $totalRowsInserted baris.\n";

// Total summary in DB now
$resTotal = $koneksi->query("SELECT COUNT(1) as total_rows, COUNT(DISTINCT reff) as total_cats FROM bc_ceisa_reff");
$rowTotal = $resTotal->fetch_assoc();
echo "Kondisi Database Sekarang: {$rowTotal['total_cats']} Kategori | {$rowTotal['total_rows']} Total Baris.\n";
echo "======================================================\n";

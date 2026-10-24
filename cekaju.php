<?php
/**
 * CEISA 4.0 Browse Dokumen Pabean Portal (cekaju.php)
 * Mengambil daftar dokumen pabean dari portal CEISA 4.0 dan menyimpannya ke tabel bc_ceisa_data.
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/init.php';

date_default_timezone_set('Asia/Jakarta');

$bc   = trim($_GET["bc"] ?? '');
$page = trim($_GET["page"] ?? '1');
if (empty($page)) { $page = '1'; }

// NITKU format (biasanya 16 digit NPWP + 6 digit cabang, total 22 digit)
$nitku = str_pad($npwp, 22, '0', STR_PAD_RIGHT);

$hostprd = "https://apis-gw.beacukai.go.id/v2/browse-service/v1/browse/dokumen-pabean-portal?" . http_build_query([
    'nomorIdentitas' => $nitku,
    'kodeJalur'      => '',
    'namaPerusahaan' => '',
    'kodeDokumen'    => $bc,
    'namaPpjk'       => '',
    'kodeKantor'     => '',
    'nomorAju'       => '',
    'nomorDaftar'    => '',
    'page'           => $page,
    'size'           => 1000,
    'idUser'         => $idUser
]);

$bearer = $_SESSION["token"] ?? '';

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    echo "cURL Error #:" . $err;
    exit;
}

$data = json_decode($response, true);

if (isset($data['data']) && is_array($data['data']) && isset($koneksi) && $koneksi) {
    $delStmt = $koneksi->prepare("DELETE FROM bc_ceisa_data WHERE nomorAju = ?");
    
    $insStmt = $koneksi->prepare("
        INSERT INTO bc_ceisa_data 
        (idHeader, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen, kodeKantor, namaKantorPendek, namaEntitas, kodeProses, kodeEntitas, namaProses, idPerusahaan, namaPerusahaan, idPpjk, nipPfpd, nipPfpb, nipAp, kodeJalur, flagHardcopy, flagHardcopySka, waktuTerimaHardcopy, jatuhtempoHardcopy, namaPpjk, nomorBc11, totalCount, tanggalBc11, subposBc11, posBc11, kodeProsesPerbaikan, namaProsesPerbaikan, flagSsm, idKepTujuan, nomorSkep, idRespon, namaRespon, nomorRespon, tanggalRespon, pdf, asalData, statusPkb) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $savedCount = 0;
    foreach ($data['data'] as $row) {
        $nomorAju = $row['nomorAju'] ?? '';
        if (empty($nomorAju)) continue;

        // Bersihkan data lama
        if ($delStmt) {
            $delStmt->bind_param("s", $nomorAju);
            $delStmt->execute();
        }

        // Format tanggal
        $tanggalDaftar = !empty($row['tanggalDaftar']) ? DateTime::createFromFormat('d-m-Y', $row['tanggalDaftar']) : null;
        $tanggalRespon = !empty($row['tanggalRespon']) ? DateTime::createFromFormat('d-m-Y H:i:s', $row['tanggalRespon']) : null;
        $tanggalBc11   = !empty($row['tanggalBc11'])   ? DateTime::createFromFormat('d-m-Y', $row['tanggalBc11']) : null;

        $tglDaftarStr = $tanggalDaftar ? $tanggalDaftar->format('Y-m-d') : null;
        $tglResponStr = $tanggalRespon ? $tanggalRespon->format('Y-m-d H:i:s') : null;
        $tglBc11Str   = $tanggalBc11   ? $tanggalBc11->format('Y-m-d') : null;

        if ($insStmt) {
            $insStmt->bind_param(
                "sssssssssssssssssssssssssssssssssssssssss",
                $row['idHeader'],
                $nomorAju,
                $row['nomorDaftar'],
                $tglDaftarStr,
                $row['kodeDokumen'],
                $row['namaDokumen'],
                $row['kodeKantor'],
                $row['namaKantorPendek'],
                $row['namaEntitas'],
                $row['kodeProses'],
                $row['kodeEntitas'],
                $row['namaProses'],
                $row['idPerusahaan'],
                $row['namaPerusahaan'],
                $row['idPpjk'],
                $row['nipPfpd'],
                $row['nipPfpb'],
                $row['nipAp'],
                $row['kodeJalur'],
                $row['flagHardcopy'],
                $row['flagHardcopySka'],
                $row['waktuTerimaHardcopy'],
                $row['jatuhtempoHardcopy'],
                $row['namaPpjk'],
                $row['nomorBc11'],
                $row['totalCount'],
                $tglBc11Str,
                $row['subposBc11'],
                $row['posBc11'],
                $row['kodeProsesPerbaikan'],
                $row['namaProsesPerbaikan'],
                $row['flagSsm'],
                $row['idKepTujuan'],
                $row['nomorSkep'],
                $row['idRespon'],
                $row['namaRespon'],
                $row['nomorRespon'],
                $tglResponStr,
                $row['pdf'],
                $row['asalData'],
                $row['statusPkb']
            );
            $insStmt->execute();
            $savedCount++;
        }
    }

    if ($delStmt) $delStmt->close();
    if ($insStmt) $insStmt->close();

    echo "OK. {$savedCount} data berhasil disimpan.";
} else {
    echo $response;
}
?>

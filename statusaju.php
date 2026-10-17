<?php 
/**
 * CEISA 4.0 Status Check by Nomor AJU (statusaju.php)
 * Mengambil status dan respon dokumen pabean dari API CEISA 4.0 dan menyimpannya ke MySQL.
 * Mendukung Multi-Company dan penanganan query aman (prepared statements).
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$aju = trim($_GET["aju"] ?? ($_GET["nomor_aju"] ?? ''));
if (empty($aju)) {
    echo 'Nomor AJU tidak boleh kosong!';
    exit;
}

// Otomatis pastikan company sesuai dokumen jika aju diberikan
if (empty($cmp_id)) {
    $comp = ceisa_get_company($aju);
    if ($comp) {
        $cmp_id = $comp['cmp_id'];
        $_SESSION['cmp_id'] = $cmp_id;
    }
}

require_once __DIR__ . '/init.php';

$hostprd = "https://apis-gw.beacukai.go.id/openapi/status/" . urlencode($aju);
$bearer  = $_SESSION["token"] ?? '';

$curl = curl_init(); 
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json",
        "Accept: application/json, text/plain, */*",
        "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "Origin: https://portal.beacukai.go.id",
        "Referer: https://portal.beacukai.go.id/",
        "Sec-Fetch-Dest: empty",
        "Sec-Fetch-Mode: cors",
        "Sec-Fetch-Site: same-site",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    echo "cURL Error #:" . $err;
    exit;
}

// 1. Simpan respon mentah ke log menggunakan prepared statement
if (isset($koneksi) && $koneksi) {
    $stmtLog = $koneksi->prepare("INSERT INTO bc_ceisa_respon_logs (respon) VALUES (?)");
    if ($stmtLog) {
        $stmtLog->bind_param("s", $response);
        $stmtLog->execute();
        $stmtLog->close();
    }
}

$data = json_decode($response, true);
$status = $data['status'] ?? '';

if ($status === 'Success' && isset($koneksi) && $koneksi) {
    echo 'OK';

    // 2. Bersihkan status dan respon lama untuk AJU dan cmp_id ini
    $delStatus = $koneksi->prepare("DELETE FROM bc_ceisa_status WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?");
    if ($delStatus) {
        $delStatus->bind_param("ss", $cmp_id, $aju);
        $delStatus->execute();
        $delStatus->close();
    }

    $delResp = $koneksi->prepare("DELETE FROM bc_ceisa_respons WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?");
    if ($delResp) {
        $delResp->bind_param("ss", $cmp_id, $aju);
        $delResp->execute();
        $delResp->close();
    }

    // 3. Simpan dataStatus
    if (!empty($data['dataStatus']) && is_array($data['dataStatus'])) {
        $stmtStatus = $koneksi->prepare("
            INSERT INTO bc_ceisa_status 
            (cmp_id, nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan, kode_dokumen) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $updHdr = $koneksi->prepare("
            UPDATE bc_ceisa_header 
            SET nomor_daftar = ?, tanggal_daftar = STR_TO_DATE(?, '%d-%m-%Y') 
            WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?
        ");

        $updPosted = $koneksi->prepare("
            UPDATE bc_ceisa_aju_posted 
            SET bc = ?, nomor_daftar = ?, tanggal_daftar = STR_TO_DATE(?, '%d-%m-%Y') 
            WHERE (cmp_id = ? OR cmp_id = '') AND nomor_aju = ?
        ");

        foreach ($data['dataStatus'] as $row) {
            $nomorAju      = $row['nomorAju'] ?? $aju;
            $nomorDaftar   = $row['nomorDaftar'] ?? '';
            $tanggalDaftar = $row['tanggalDaftar'] ?? '';
            $kodeProses    = $row['kodeProses'] ?? '';
            $waktuStatus   = $row['waktuStatus'] ?? '';
            $keterangan    = $row['keterangan'] ?? '';
            $kodeDokumen   = $row['kodeDokumen'] ?? '';

            if ($stmtStatus) {
                $stmtStatus->bind_param("ssssssss", $cmp_id, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeProses, $waktuStatus, $keterangan, $kodeDokumen);
                $stmtStatus->execute();
            }

            if (!empty($nomorDaftar)) {
                if ($updHdr) {
                    $updHdr->bind_param("ssss", $nomorDaftar, $tanggalDaftar, $cmp_id, $nomorAju);
                    $updHdr->execute();
                }
                if ($updPosted) {
                    $updPosted->bind_param("sssss", $kodeDokumen, $nomorDaftar, $tanggalDaftar, $cmp_id, $nomorAju);
                    $updPosted->execute();
                }
            }
        }

        if ($stmtStatus) $stmtStatus->close();
        if ($updHdr) $updHdr->close();
        if ($updPosted) $updPosted->close();
    }

    // 4. Simpan dataRespon
    if (!empty($data['dataRespon']) && is_array($data['dataRespon'])) {
        $stmtRespon = $koneksi->prepare("
            INSERT INTO bc_ceisa_respons 
            (cmp_id, nomor_aju, kode_respon, nomor_daftar, tanggal_daftar, nomor_respon, tanggal_respon, waktu_respon, keterangan, pdf, kode_dokumen) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($data['dataRespon'] as $row) {
            $nomorAju      = $row['nomorAju'] ?? $aju;
            $kodeRespon    = $row['kodeRespon'] ?? '';
            $nomorDaftar   = $row['nomorDaftar'] ?? '';
            $tanggalDaftar = $row['tanggalDaftar'] ?? '';
            $nomorRespon   = $row['nomorRespon'] ?? '';
            $tanggalRespon = $row['tanggalRespon'] ?? '';
            $waktuRespon   = $row['waktuRespon'] ?? '';
            $keterangan    = $row['keterangan'] ?? '';
            $pdf           = $row['pdf'] ?? '';
            $kodeDokumen   = $row['kodeDokumen'] ?? '';

            if ($stmtRespon) {
                $stmtRespon->bind_param("sssssssssss", $cmp_id, $nomorAju, $kodeRespon, $nomorDaftar, $tanggalDaftar, $nomorRespon, $tanggalRespon, $waktuRespon, $keterangan, $pdf, $kodeDokumen);
                $stmtRespon->execute();
            }
        }

        if ($stmtRespon) $stmtRespon->close();
    }
} else {
    echo $response;
}
?>
<?php
/**
 * Sinkronisasi Status / Detail Satu AJU dari Portal CEISA 4.0
 */
ini_set('max_execution_time', 60);
set_time_limit(60);

require_once __DIR__ . '/../initmfa.php';
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../datalogin.php';

header('Content-Type: application/json; charset=utf-8');

$targetCmp = $_GET['cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (!empty($targetCmp) && $targetCmp !== $cmp_id) {
    $cRow = ceisa_get_company($targetCmp);
    if ($cRow) {
        $npwp           = $cRow['npwp'];
        $user           = $cRow['id_user_ceisa'] ?? '';
        $cmp_id         = $cRow['cmp_id'];
        $namaperusahaan = $cRow['cmp_name'];
    }
}

$aju  = trim($_GET['aju'] ?? '');
$paramNpwp = trim($_GET['npwp'] ?? '');

if (empty($aju)) {
    echo json_encode(["status" => false, "message" => "Nomor AJU tidak boleh kosong"]);
    exit;
}

if (empty($user)) {
    echo json_encode(["status" => false, "message" => "idUser CEISA belum dikonfigurasi untuk perusahaan ini"]);
    exit;
}

// NPWP handling: Coba dari parameter, jika kosong ambil dari perusahaan
$searchNpwp = !empty($paramNpwp) ? $paramNpwp : $npwp;
// Bersihkan tanda titik dan strip jika ada
$cleanNpwp = preg_replace('/[^0-9]/', '', $searchNpwp);

$hostprd = "https://apis-gw.beacukai.go.id/v2/browse-service/v1/browse/dokumen-pabean-portal";
$bearer = $_SESSION['mfatoken'] ?? ($activeToken ?? '');

if (empty($bearer)) {
    echo json_encode(["status" => false, "message" => "Token MFA CEISA tidak tersedia. Silakan generate MFA Token terlebih dahulu."]);
    exit;
}

// Siapkan parameter browse CEISA
$params = [
    "nomorIdentitas" => $cleanNpwp,
    "kodeJalur"      => '',
    "namaPerusahaan" => '',
    "kodeDokumen"    => '',
    "namaPpjk"       => '',
    "kodeKantor"     => '',
    "nomorAju"       => $aju,
    "nomorDaftar"    => '',
    "page"           => 0,
    "size"           => 10,
    "idUser"         => $user
];

$url = $hostprd . '?' . http_build_query($params);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json",
        "Accept: application/json, text/plain, */*",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($err) {
    echo json_encode(["status" => false, "message" => "cURL Error: $err"]);
    exit;
}

$data = json_decode($response, true);
if (is_null($data)) {
    echo json_encode(["status" => false, "message" => "Format respons dari CEISA tidak valid (HTTP $httpCode)"]);
    exit;
}

$rows = $data['data'] ?? [];
$inserted = 0;

if (!empty($rows)) {
    $sql_delete = "DELETE FROM bc_ceisa_data WHERE nomorAju = ?";
    $stmt_del = $koneksi->prepare($sql_delete);

    $sql_insert = "INSERT INTO bc_ceisa_data (
        idHeader, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, 
        namaDokumen, kodeKantor, namaKantorPendek, namaEntitas, kodeProses, 
        kodeEntitas, namaProses, idPerusahaan, namaPerusahaan, idPpjk, 
        nipPfpd, nipPfpb, nipAp, kodeJalur, flagHardcopy, 
        flagHardcopySka, waktuTerimaHardcopy, jatuhtempoHardcopy, namaPpjk, nomorBc11, 
        totalCount, tanggalBc11, subposBc11, posBc11, kodeProsesPerbaikan, 
        namaProsesPerbaikan, flagSsm, idKepTujuan, nomorSkep, idRespon, 
        namaRespon, nomorRespon, tanggalRespon, pdf, asalData, statusPkb
    ) VALUES (
        ?, ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?
    )";
    $stmt_ins = $koneksi->prepare($sql_insert);

    foreach ($rows as $row) {
        $tglDaftar = null;
        if (!empty($row['tanggalDaftar'])) {
            $parsed = DateTime::createFromFormat('d-m-Y', $row['tanggalDaftar']) ?: DateTime::createFromFormat('Y-m-d', $row['tanggalDaftar']);
            if ($parsed) $tglDaftar = $parsed->format('Y-m-d');
        }

        $tglRespon = null;
        if (!empty($row['tanggalRespon'])) {
            $parsed = DateTime::createFromFormat('d-m-Y H:i:s', $row['tanggalRespon']) ?: DateTime::createFromFormat('Y-m-d H:i:s', $row['tanggalRespon']);
            if ($parsed) $tglRespon = $parsed->format('Y-m-d H:i:s');
        }

        $tglBc11 = null;
        if (!empty($row['tanggalBc11'])) {
            $parsed = DateTime::createFromFormat('d-m-Y H:i:s', $row['tanggalBc11']) ?: DateTime::createFromFormat('d-m-Y', $row['tanggalBc11']);
            if ($parsed) $tglBc11 = $parsed->format('Y-m-d');
        }

        if ($stmt_del) {
            $stmt_del->bind_param("s", $row['nomorAju']);
            $stmt_del->execute();
        }

        if ($stmt_ins) {
            $totalCountVal = (int)($row['totalCount'] ?? 0);
            $stmt_ins->bind_param(
                "sssssssssssssssssssssssssisssssssssssssss",
                $row['idHeader'], $row['nomorAju'], $row['nomorDaftar'], $tglDaftar, $row['kodeDokumen'],
                $row['namaDokumen'], $row['kodeKantor'], $row['namaKantorPendek'], $row['namaEntitas'], $row['kodeProses'],
                $row['kodeEntitas'], $row['namaProses'], $row['idPerusahaan'], $row['namaPerusahaan'], $row['idPpjk'],
                $row['nipPfpd'], $row['nipPfpb'], $row['nipAp'], $row['kodeJalur'], $row['flagHardcopy'],
                $row['flagHardcopySka'], $row['waktuTerimaHardcopy'], $row['jatuhtempoHardcopy'], $row['namaPpjk'], $row['nomorBc11'],
                $totalCountVal, $tglBc11, $row['subposBc11'], $row['posBc11'], $row['kodeProsesPerbaikan'],
                $row['namaProsesPerbaikan'], $row['flagSsm'], $row['idKepTujuan'], $row['nomorSkep'], $row['idRespon'],
                $row['namaRespon'], $row['nomorRespon'], $tglRespon, $row['pdf'], $row['asalData'], $row['statusPkb']
            );
            $stmt_ins->execute();
            $inserted++;
        }
    }

    if ($stmt_del) $stmt_del->close();
    if ($stmt_ins) $stmt_ins->close();
}

echo json_encode([
    "status"   => true,
    "inserted" => $inserted,
    "count"    => count($rows),
    "nomorAju" => $aju,
    "namaProses" => $rows[0]['namaProses'] ?? '-',
    "namaRespon" => $rows[0]['namaRespon'] ?? '-'
]);

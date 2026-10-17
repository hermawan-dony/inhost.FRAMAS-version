<?php
/**
 * Tarik Data Dokumen & Barang BC 2.7 IN dari CEISA OpenAPI
 * Disimpan ke tabel bc_ceisa_get_data dan bc_ceisa_get_barang
 */
date_default_timezone_set("Asia/Jakarta");
set_time_limit(90);

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../datalogin.php';

header('Content-Type: application/json; charset=utf-8');

$targetCmp = $_GET['cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (!empty($targetCmp) && $targetCmp !== $cmp_id) {
    $cRow = ceisa_get_company($targetCmp);
    if ($cRow) {
        $cmp_id         = $cRow['cmp_id'];
        $namaperusahaan = $cRow['cmp_name'];
        $username       = $cRow['username_ceisa'];
        $password       = $cRow['password_ceisa'];
    }
}

$aju = trim($_GET['aju'] ?? '');
$kantorParam = trim($_GET['a'] ?? ($_GET['kantor'] ?? ''));

if (empty($aju)) {
    echo json_encode(["status" => false, "message" => "Nomor AJU tidak boleh kosong"]);
    exit;
}

$cleanAju = str_replace('-', '', $aju);
if (strlen($cleanAju) > 62) {
    $cleanAju = substr($cleanAju, 61, 26);
}
if (strlen($cleanAju) < 26) {
    echo json_encode(["status" => false, "message" => "Nomor AJU tidak valid (harus 26 digit)"]);
    exit;
}

$bc = substr($cleanAju, 4, 2); // '27'

$kantor = $kantorParam;
if (empty($kantor)) {
    if (substr($cleanAju, 0, 4) != '0000') {
        $kantor = substr($cleanAju, 0, 4) . '00';
    } else {
        $kantor = $kode_kantor ?? '050900';
    }
}

// Cek apakah data sudah pernah ditarik sebelumnya
$stmt_check = $koneksi->prepare("SELECT COUNT(*) FROM bc_ceisa_get_data WHERE nomor_aju = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
if ($stmt_check) {
    $stmt_check->bind_param("ss", $cleanAju, $cmp_id);
    $stmt_check->execute();
    $cnt = $stmt_check->get_result()->fetch_row()[0];
    $stmt_check->close();
    if ($cnt > 0) {
        echo json_encode(["status" => true, "already_exists" => true, "message" => "Data AJU ini sudah pernah ditarik sebelumnya."]);
        exit;
    }
}

$bearer = $_SESSION["token"] ?? ($activeToken ?? '');
if (empty($bearer)) {
    echo json_encode(["status" => false, "message" => "Token OpenAPI CEISA tidak tersedia."]);
    exit;
}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/document/detail/$bc/$cleanAju/$kantor";

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($err) {
    echo json_encode(["status" => false, "message" => "Gagal koneksi API CEISA: $err"]);
    exit;
}

$data = json_decode($response, true);
if (is_null($data)) {
    echo json_encode(["status" => false, "message" => "Respons CEISA tidak valid (HTTP $httpCode)"]);
    exit;
}

if (isset($data['status']) && ($data['status'] === false || $data['status'] === 'false')) {
    echo json_encode(["status" => false, "message" => ($data['message'] ?? 'Error CEISA') . " (Kantor: $kantor)"]);
    exit;
}

if (empty($data['data'])) {
    echo json_encode(["status" => false, "message" => "Data dokumen tidak ditemukan di server CEISA"]);
    exit;
}

// Simpan ke database
$koneksi->begin_transaction();
try {
    $mainItems = $data['data'];
    $barangCount = 0;

    foreach ($mainItems as $main_data) {
        $nomorAju        = $main_data['nomorAju'] ?? $cleanAju;
        $nomorDaftar     = $main_data['nomorDaftar'] ?? '';
        $tanggalDaftar   = $main_data['tanggalDaftar'] ?? null;
        $kodeKantor      = $main_data['kodeKantor'] ?? $kantor;
        $kodeValuta      = $main_data['kodeValuta'] ?? 'IDR';
        $kotaTtd         = $main_data['kotaTtd'] ?? '';
        $namaTtd         = $main_data['namaTtd'] ?? '';
        $jabatanTtd      = $main_data['jabatanTtd'] ?? '';
        $tanggalAju      = $main_data['tanggalAju'] ?? null;
        $kodeDokumen     = $main_data['kodeDokumen'] ?? $bc;
        $kodeProses      = $main_data['kodeProses'] ?? '';
        $kodeJenisTpb    = $main_data['kodeJenisTpb'] ?? '';
        $kodeTujuanPeng  = $main_data['kodeTujuanPengiriman'] ?? '';
        $kodeTujuanTpb   = $main_data['kodeTujuanTpb'] ?? '';
        $jumlahKontainer = (int)($main_data['jumlahKontainer'] ?? 0);
        $hargaPenyerahan = (float)($main_data['hargaPenyerahan'] ?? 0);
        $cif             = (float)($main_data['cif'] ?? 0);
        $nilaiTukar      = (float)($main_data['nilaiTukar'] ?? 1);
        $bruto           = (float)($main_data['bruto'] ?? 0);
        $netto           = (float)($main_data['netto'] ?? 0);

        // Hapus jika ada
        $delHeader = $koneksi->prepare("DELETE FROM bc_ceisa_get_data WHERE nomor_aju = ? AND (cmp_id = ? OR cmp_id = '')");
        if ($delHeader) {
            $delHeader->bind_param("ss", $nomorAju, $cmp_id);
            $delHeader->execute();
            $delHeader->close();
        }

        $insHeader = $koneksi->prepare("
            INSERT INTO bc_ceisa_get_data (
                cmp_id, nomor_aju, nomor_daftar, tanggal_daftar, kode_kantor, 
                kode_valuta, kota_ttd, nama_ttd, jabatan_ttd, tanggal_aju, 
                kode_dokumen, kode_proses, kode_jenis_tpb, kode_tujuan_pengiriman, kode_tujuan_tpb, 
                jumlah_kontainer, harga_penyerahan, cif, nilai_tukar, bruto, netto
            ) VALUES (
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?, ?
            )
        ");
        if (!$insHeader) {
            throw new Exception("Prepare Header Error: " . $koneksi->error);
        }

        $insHeader->bind_param(
            "sssssssssssssssiddddd",
            $cmp_id, $nomorAju, $nomorDaftar, $tanggalDaftar, $kodeKantor,
            $kodeValuta, $kotaTtd, $namaTtd, $jabatanTtd, $tanggalAju,
            $kodeDokumen, $kodeProses, $kodeJenisTpb, $kodeTujuanPeng, $kodeTujuanTpb,
            $jumlahKontainer, $hargaPenyerahan, $cif, $nilaiTukar, $bruto, $netto
        );
        $insHeader->execute();
        $insHeader->close();

        // Hapus detail barang lama
        $delBarang = $koneksi->prepare("DELETE FROM bc_ceisa_get_barang WHERE nomor_aju = ? AND (cmp_id = ? OR cmp_id = '')");
        if ($delBarang) {
            $delBarang->bind_param("ss", $nomorAju, $cmp_id);
            $delBarang->execute();
            $delBarang->close();
        }

        // Simpan detail barang
        $barangs = $main_data['barang'] ?? [];
        $insBarang = $koneksi->prepare("
            INSERT INTO bc_ceisa_get_barang (
                cmp_id, nomor_aju, seri_barang, jumlah_satuan, netto, 
                nilai_barang, cif, harga_penyerahan, kode_barang, pos_tarif, 
                uraian, merk, tipe, spesifikasi_lain, kode_satuan_barang
            ) VALUES (
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, ?
            )
        ");
        if (!$insBarang) {
            throw new Exception("Prepare Barang Error: " . $koneksi->error);
        }

        foreach ($barangs as $b) {
            $seriBarang   = (int)($b['seriBarang'] ?? 1);
            $jumlahSatuan = (float)($b['jumlahSatuan'] ?? 0);
            $bNetto       = (float)($b['netto'] ?? 0);
            $nilaiBarang  = (float)($b['nilaiBarang'] ?? 0);
            $bCif         = (float)($b['cif'] ?? 0);
            $bHargaPenyerahan = (float)($b['hargaPenyerahan'] ?? 0);
            $kodeBarang   = trim($b['kodeBarang'] ?? '');
            $posTarif     = trim($b['posTarif'] ?? '');
            $uraian       = trim($b['uraian'] ?? '');
            $merk         = trim($b['merk'] ?? '');
            $tipe         = trim($b['tipe'] ?? '');
            $spesifikasi  = trim($b['spesifikasiLain'] ?? '');
            $kodeSatuan   = trim($b['kodeSatuanBarang'] ?? '');

            $insBarang->bind_param(
                "ssidddsssssssss",
                $cmp_id, $nomorAju, $seriBarang, $jumlahSatuan, $bNetto,
                $nilaiBarang, $bCif, $bHargaPenyerahan, $kodeBarang, $posTarif,
                $uraian, $merk, $tipe, $spesifikasi, $kodeSatuan
            );
            $insBarang->execute();
            $barangCount++;
        }
        $insBarang->close();
    }

    $koneksi->commit();
    echo json_encode([
        "status"      => true,
        "message"     => "Berhasil tarik data AJU $cleanAju ($barangCount item barang)",
        "nomor_aju"   => $cleanAju,
        "barang_count"=> $barangCount
    ]);
} catch (Exception $e) {
    $koneksi->rollback();
    echo json_encode(["status" => false, "message" => "Gagal simpan database: " . $e->getMessage()]);
}

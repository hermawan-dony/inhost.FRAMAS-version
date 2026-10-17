<?php
/**
 * Olah Data BC 2.7 IN Masuk ke IT Inventory (tabel bc_1ab2)
 * Syarat: Kode Barang harus sesuai dengan master Barang (bc_material) dan Klas sudah diset!
 */
date_default_timezone_set("Asia/Jakarta");

require_once __DIR__ . '/../initmfa.php';
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../datalogin.php';

header('Content-Type: application/json; charset=utf-8');

$targetCmp = $_POST['cmp_id'] ?? ($_GET['cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? '')));
if (!empty($targetCmp) && $targetCmp !== $cmp_id) {
    $cRow = ceisa_get_company($targetCmp);
    if ($cRow) {
        $cmp_id         = $cRow['cmp_id'];
        $namaperusahaan = $cRow['cmp_name'];
    }
}

$nomorAju = trim($_POST['nomor_aju'] ?? ($_GET['nomor_aju'] ?? ''));
if (empty($nomorAju)) {
    echo json_encode(["status" => false, "message" => "Nomor AJU tidak boleh kosong"]);
    exit;
}

// 1. Ambil Header dari bc_ceisa_get_data
$stmtHeader = $koneksi->prepare("SELECT * FROM bc_ceisa_get_data WHERE nomor_aju = ? LIMIT 1");
if (!$stmtHeader) {
    echo json_encode(["status" => false, "message" => "Query header error: " . $koneksi->error]);
    exit;
}
$stmtHeader->bind_param("s", $nomorAju);
$stmtHeader->execute();
$header = $stmtHeader->get_result()->fetch_assoc();
$stmtHeader->close();

if (!$header) {
    echo json_encode([
        "status" => false,
        "message" => "Dokumen AJU $nomorAju belum ditarik dari CEISA. Silakan klik tombol 'Tarik Data' terlebih dahulu."
    ]);
    exit;
}

// Ambil Nama Perusahaan Pengirim dari bc_ceisa_data
$namaPengirim = '-';
$stmtSender = $koneksi->prepare("SELECT namaPerusahaan, idPerusahaan FROM bc_ceisa_data WHERE nomorAju = ? LIMIT 1");
if ($stmtSender) {
    $stmtSender->bind_param("s", $nomorAju);
    $stmtSender->execute();
    $sRow = $stmtSender->get_result()->fetch_assoc();
    if ($sRow && !empty($sRow['namaPerusahaan'])) {
        $namaPengirim = $sRow['namaPerusahaan'];
    }
    $stmtSender->close();
}

// 2. Ambil Barang dari bc_ceisa_get_barang
$stmtBarang = $koneksi->prepare("SELECT * FROM bc_ceisa_get_barang WHERE nomor_aju = ? ORDER BY seri_barang ASC");
if (!$stmtBarang) {
    echo json_encode(["status" => false, "message" => "Query barang error: " . $koneksi->error]);
    exit;
}
$stmtBarang->bind_param("s", $nomorAju);
$stmtBarang->execute();
$resBarang = $stmtBarang->get_result();

$items = [];
while ($b = $resBarang->fetch_assoc()) {
    $items[] = $b;
}
$stmtBarang->close();

if (empty($items)) {
    echo json_encode([
        "status" => false,
        "message" => "Tidak ditemukan baris barang untuk AJU $nomorAju pada tabel bc_ceisa_get_barang."
    ]);
    exit;
}

// 3. Validasi ke Master Barang (bc_material)
// ATURAN: Kode Barang harus terdaftar di bc_material DAN kolom KLAS harus sudah diset (tidak boleh kosong)!
$invalidItems = [];
$validatedItems = [];

$stmtMat = $koneksi->prepare("SELECT KODE_BARANG, URAIAN, KLAS, KODE_SATUAN, POS_TARIF FROM bc_material WHERE KODE_BARANG = ? LIMIT 1");

foreach ($items as $it) {
    $kd = trim($it['kode_barang']);
    $mat = null;

    if (!empty($kd) && $stmtMat) {
        $stmtMat->bind_param("s", $kd);
        $stmtMat->execute();
        $mat = $stmtMat->get_result()->fetch_assoc();
    }

    if (!$mat) {
        $invalidItems[] = [
            "seri"   => $it['seri_barang'],
            "kode"   => $kd ?: '(KOSONG)',
            "uraian" => $it['uraian'],
            "alasan" => "Kode Barang tidak ditemukan di Master Data Barang"
        ];
    } elseif (empty(trim($mat['KLAS'] ?? ''))) {
        $invalidItems[] = [
            "seri"   => $it['seri_barang'],
            "kode"   => $kd,
            "uraian" => $it['uraian'],
            "alasan" => "Klasifikasi barang pada Master Data Barang belum diset / masih kosong"
        ];
    } else {
        $validatedItems[] = [
            "raw" => $it,
            "mat" => $mat
        ];
    }
}
if ($stmtMat) $stmtMat->close();

// Jika ada item yang tidak valid, tolak proses dan tampilkan detailnya
if (count($invalidItems) > 0) {
    echo json_encode([
        "status"         => false,
        "message"        => "Validasi gagal: Terdapat " . count($invalidItems) . " item barang yang belum sesuai dengan Master Data Barang.",
        "invalid_count"  => count($invalidItems),
        "invalid_items"  => $invalidItems,
        "can_proceed"    => false
    ]);
    exit;
}

// 4. Semua item valid! Masukkan ke tabel IT Inventory bc_1ab2
$koneksi->begin_transaction();
try {
    // Hapus data lama di bc_1ab2 jika ada
    $delAb2 = $koneksi->prepare("DELETE FROM bc_1ab2 WHERE NOMOR_AJU = ? AND AB = 'A'");
    if ($delAb2) {
        $delAb2->bind_param("s", $nomorAju);
        $delAb2->execute();
        $delAb2->close();
    }

    $insAb2 = $koneksi->prepare("
        INSERT INTO bc_1ab2 (
            BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, 
            NAMA_BS, KODE_BARANG, POS_TARIF, URAIAN, KLAS, 
            UNIT, QTY, KURS, NILAI, AB, 
            ID_HEADER, NOMOR_AJU, SERI_BARANG, HARGA_PENYERAHAN, CIF, 
            NETTO, SESUAI, CEK_KODEBRG, CEK, TGL_PROSES, 
            CRT_DT, CRT_USER
        ) VALUES (
            '2.7', ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, 'A', 
            ?, ?, ?, ?, ?, 
            ?, 'Y', 'Y', 1, CURDATE(), 
            CURDATE(), ?
        )
    ");

    if (!$insAb2) {
        throw new Exception("Prepare insert bc_1ab2 error: " . $koneksi->error);
    }

    $crtUser = $_SESSION['username'] ?? 'SYSTEM';
    $noDaftar = $header['nomor_daftar'] ?? '';
    $tglDaftar = $header['tanggal_daftar'] ?? '';
    $noBukti = $noDaftar ?: $nomorAju;
    $tglBukti = $tglDaftar ?: date('Y-m-d');
    $valuta = $header['kode_valuta'] ?: 'IDR';
    $idHeader = $nomorAju;

    foreach ($validatedItems as $v) {
        $r = $v['raw'];
        $m = $v['mat'];

        $kdBarang = $r['kode_barang'];
        $posTarif = !empty($r['pos_tarif']) ? $r['pos_tarif'] : ($m['POS_TARIF'] ?? '');
        $uraian   = !empty($r['uraian']) ? $r['uraian'] : ($m['URAIAN'] ?? '');
        $klas     = $m['KLAS'];
        $unit     = !empty($r['kode_satuan_barang']) ? $r['kode_satuan_barang'] : ($m['KODE_SATUAN'] ?? 'PCE');
        $qty      = (float)($r['jumlah_satuan'] ?? 0);
        $nilai    = (float)($r['nilai_barang'] ?? 0);
        $seri     = (int)($r['seri_barang'] ?? 1);
        $hargaPenyerahan = (float)($r['harga_penyerahan'] ?? 0);
        $cif      = (float)($r['cif'] ?? 0);
        $netto    = (float)($r['netto'] ?? 0);

        $insAb2->bind_param(
            "ssssssssssdssdssiddds",
            $noDaftar, $tglDaftar, $noBukti, $tglBukti,
            $namaPengirim, $kdBarang, $posTarif, $uraian, $klas,
            $unit, $qty, $valuta, $nilai,
            $idHeader, $nomorAju, $seri, $hargaPenyerahan, $cif,
            $netto, $crtUser
        );
        $insAb2->execute();
    }
    $insAb2->close();

    $koneksi->commit();
    echo json_encode([
        "status"      => true,
        "message"     => "Sukses! Berhasil membukukan dokumen BC 2.7 IN (No AJU: $nomorAju) ke catatan IT Inventory. Sebanyak " . count($validatedItems) . " item barang berhasil dimasukkan.",
        "item_count"  => count($validatedItems),
        "nomor_aju"   => $nomorAju
    ]);
} catch (Exception $ex) {
    $koneksi->rollback();
    echo json_encode(["status" => false, "message" => "Gagal menyimpan ke IT Inventory: " . $ex->getMessage()]);
}

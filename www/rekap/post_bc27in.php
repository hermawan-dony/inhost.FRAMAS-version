<?php
/**
 * Olah Data BC 2.7 IN Masuk ke IT Inventory (tabel bc_1ab2)
 * Dilengkapi Auto-Mapping Supplier Part Number (bc_material.KODE_BARANG2),
 * Resolusi Kode Manual, dan Workflow Pengajuan Kode Baru ke Admin Inventory.
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
$escCmp = $koneksi->real_escape_string($cmp_id);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'OPERATOR');

$action = trim($_POST['action'] ?? ($_GET['action'] ?? ''));

// -------------------------------------------------------------
// ENDPOINT 1: SEARCH MASTER BARANG (bc_material) untuk Dropdown Mapping
// -------------------------------------------------------------
if ($action === 'search_material') {
    $q = trim($_GET['q'] ?? ($_POST['q'] ?? ''));
    $escQ = $koneksi->real_escape_string($q);
    
    $where = ["(cmp_id = '$escCmp' OR cmp_id = '')"];
    if (!empty($escQ)) {
        $where[] = "(KODE_BARANG LIKE '%$escQ%' OR KODE_BARANG2 LIKE '%$escQ%' OR URAIAN LIKE '%$escQ%' OR POS_TARIF LIKE '%$escQ%')";
    }
    $whereSql = implode(' AND ', $where);
    
    $items = [];
    $res = $koneksi->query("SELECT KODE_BARANG, KODE_BARANG2, URAIAN, KLAS, KODE_SATUAN, POS_TARIF FROM bc_material WHERE $whereSql ORDER BY KODE_BARANG ASC LIMIT 25");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'kode'    => $r['KODE_BARANG'],
                'kode2'   => $r['KODE_BARANG2'] ?? '',
                'uraian'  => $r['URAIAN'],
                'klas'    => $r['KLAS'],
                'satuan'  => $r['KODE_SATUAN'] ?? 'PCE',
                'pos_tarif'=> $r['POS_TARIF'] ?? ''
            ];
        }
    }
    
    echo json_encode([
        'status' => true,
        'query'  => $q,
        'count'  => count($items),
        'items'  => $items
    ]);
    exit;
}

// -------------------------------------------------------------
// ENDPOINT 2: MAP KODE SUPPLIER KE KODE INTERNAL EXISTING
// -------------------------------------------------------------
if ($action === 'map_supplier') {
    $nomorAju     = trim($_POST['nomor_aju'] ?? '');
    $kodeSupplier = trim($_POST['kode_supplier'] ?? '');
    $kodeInternal = trim($_POST['kode_internal'] ?? '');
    $saveMapping  = (int)($_POST['save_mapping'] ?? 1);

    if (empty($kodeSupplier) || empty($kodeInternal)) {
        echo json_encode(['status' => false, 'message' => 'Kode Supplier dan Kode Internal wajib diisi.']);
        exit;
    }

    $escSupp = $koneksi->real_escape_string($kodeSupplier);
    $escInt  = $koneksi->real_escape_string($kodeInternal);

    // Pastikan kode internal ada di bc_material
    $mat = $koneksi->query("SELECT KODE_BARANG, URAIAN, KLAS, KODE_SATUAN, POS_TARIF FROM bc_material WHERE KODE_BARANG = '$escInt' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1")->fetch_assoc();
    if (!$mat) {
        echo json_encode(['status' => false, 'message' => "Kode Barang Internal '$kodeInternal' tidak ditemukan pada Master Data Barang."]);
        exit;
    }

    $koneksi->begin_transaction();
    try {
        if ($saveMapping === 1) {
            // Update kolom KODE_BARANG2 pada bc_material
            $upd = $koneksi->prepare("UPDATE bc_material SET KODE_BARANG2 = ?, TGL_UPDATE = NOW() WHERE KODE_BARANG = ? AND (cmp_id = ? OR cmp_id = '')");
            if ($upd) {
                $upd->bind_param("sss", $kodeSupplier, $kodeInternal, $cmp_id);
                $upd->execute();
                $upd->close();
            }
        }

        // Catat ke bc_material_approval sebagai riwayat mapping
        $insAppr = $koneksi->prepare("
            INSERT INTO bc_material_approval (
                cmp_id, tipe_pengajuan, nomor_aju, kode_supplier, kode_internal, 
                uraian, pos_tarif, satuan, klas, alasan, 
                status, requested_by, approved_by, approved_at, approval_notes
            ) VALUES (
                ?, 'MAPPING_SUPPLIER', ?, ?, ?, 
                ?, ?, ?, ?, 'Pemetaan Kode Supplier langsung dari proses Dokumen Pemasukan', 
                'APPROVED', ?, ?, NOW(), ?
            )
        ");
        if ($insAppr) {
            $notes = "Mapping Part Supplier [$kodeSupplier] ke Internal [$kodeInternal]";
            $insAppr->bind_param(
                "sssssssssss",
                $cmp_id, $nomorAju, $kodeSupplier, $kodeInternal,
                $mat['URAIAN'], $mat['POS_TARIF'], $mat['KODE_SATUAN'], $mat['KLAS'],
                $currentUser, $currentUser, $notes
            );
            $insAppr->execute();
            $insAppr->close();
        }

        $koneksi->commit();
        echo json_encode([
            'status'        => true,
            'message'       => "Berhasil memetakan kode supplier '$kodeSupplier' ke kode internal '$kodeInternal'. Dokumen sekarang dapat diolah ke IT Inventory.",
            'kode_supplier' => $kodeSupplier,
            'kode_internal' => $kodeInternal,
            'mat'           => $mat
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => false, 'message' => 'Gagal menyimpan mapping: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// ENDPOINT 3: PENGAJUAN KODE BARU KE ADMIN INVENTORY (APPROVAL WORKFLOW)
// -------------------------------------------------------------
if ($action === 'request_new_code') {
    $nomorAju     = trim($_POST['nomor_aju'] ?? '');
    $kodeSupplier = trim($_POST['kode_supplier'] ?? '');
    $kodeInternal = trim($_POST['kode_internal'] ?? '');
    $uraian       = trim($_POST['uraian'] ?? '');
    $klas         = trim($_POST['klas'] ?? 'Raw Materials');
    $satuan       = trim($_POST['satuan'] ?? 'PCE');
    $posTarif     = trim($_POST['pos_tarif'] ?? '');
    $alasan       = trim($_POST['alasan'] ?? 'Barang baru dari supplier pada dokumen masuk');

    if (empty($kodeSupplier) || empty($kodeInternal) || empty($uraian)) {
        echo json_encode(['status' => false, 'message' => 'Kode Supplier, Usulan Kode Internal, dan Uraian Barang wajib diisi.']);
        exit;
    }

    $escInt = $koneksi->real_escape_string($kodeInternal);
    // Cek apakah usulan kode internal sudah terdaftar di bc_material
    $chkMat = $koneksi->query("SELECT KODE_BARANG FROM bc_material WHERE KODE_BARANG = '$escInt' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
    if ($chkMat && $chkMat->num_rows > 0) {
        echo json_encode([
            'status' => false,
            'message' => "Kode internal '$kodeInternal' sudah terdaftar di Master Barang. Gunakan tombol 'Hubungkan ke Master Existing' untuk memetakan supplier part ini ke kode tersebut."
        ]);
        exit;
    }

    // Cek apakah sudah ada pengajuan pending yang sama
    $escSupp = $koneksi->real_escape_string($kodeSupplier);
    $chkPending = $koneksi->query("SELECT id FROM bc_material_approval WHERE kode_supplier = '$escSupp' AND status = 'PENDING' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
    if ($chkPending && $rP = $chkPending->fetch_assoc()) {
        echo json_encode([
            'status' => true,
            'message' => "Pengajuan untuk kode supplier '$kodeSupplier' sudah pernah dibuat dan saat ini berstatus MENUNGGU APPROVAL Admin Inventory (ID Pengajuan: #{$rP['id']})."
        ]);
        exit;
    }

    $stmtIns = $koneksi->prepare("
        INSERT INTO bc_material_approval (
            cmp_id, tipe_pengajuan, nomor_aju, kode_supplier, kode_internal, 
            uraian, pos_tarif, satuan, klas, alasan, 
            status, requested_by, requested_at
        ) VALUES (
            ?, 'KODE_BARU', ?, ?, ?, 
            ?, ?, ?, ?, ?, 
            'PENDING', ?, NOW()
        )
    ");
    if (!$stmtIns) {
        echo json_encode(['status' => false, 'message' => 'Query error: ' . $koneksi->error]);
        exit;
    }

    $stmtIns->bind_param(
        "ssssssssss",
        $cmp_id, $nomorAju, $kodeSupplier, $kodeInternal,
        $uraian, $posTarif, $satuan, $klas, $alasan,
        $currentUser
    );

    if ($stmtIns->execute()) {
        $insertId = $stmtIns->insert_id;
        $stmtIns->close();
        echo json_encode([
            'status'       => true,
            'message'      => "Pengajuan kode barang baru '$kodeInternal' berhasil dikirim ke Admin Inventory (Status: Menunggu Persetujuan). Hubungi Admin Inventory untuk menyetujui di modul Master Barang.",
            'approval_id'  => $insertId,
            'kode_internal'=> $kodeInternal
        ]);
    } else {
        echo json_encode(['status' => false, 'message' => 'Gagal menyimpan pengajuan: ' . $stmtIns->error]);
        $stmtIns->close();
    }
    exit;
}

// -------------------------------------------------------------
// ENDPOINT 4: PROSES POSTING BC 2.7 IN KE IT INVENTORY (bc_1ab2)
// -------------------------------------------------------------
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

// 3. Validasi ke Master Barang (bc_material) dengan Dukungan KODE_BARANG & KODE_BARANG2 (Auto-mapping)
$invalidItems = [];
$validatedItems = [];

// Prepare query pencarian: cek KODE_BARANG dan KODE_BARANG2
$stmtMat = $koneksi->prepare("
    SELECT KODE_BARANG, KODE_BARANG2, URAIAN, KLAS, KODE_SATUAN, POS_TARIF 
    FROM bc_material 
    WHERE (cmp_id = ? OR cmp_id = '') 
      AND (KODE_BARANG = ? OR KODE_BARANG2 = ?) 
    ORDER BY (KODE_BARANG = ?) DESC LIMIT 1
");

// Prepare query cek approval pending
$stmtAppr = $koneksi->prepare("
    SELECT id, status, kode_internal, tipe_pengajuan 
    FROM bc_material_approval 
    WHERE (cmp_id = ? OR cmp_id = '') 
      AND kode_supplier = ? 
      AND status = 'PENDING' 
    ORDER BY id DESC LIMIT 1
");

foreach ($items as $it) {
    $kd = trim($it['kode_barang'] ?? '');
    $mat = null;

    if (!empty($kd) && $stmtMat) {
        $stmtMat->bind_param("ssss", $cmp_id, $kd, $kd, $kd);
        $stmtMat->execute();
        $mat = $stmtMat->get_result()->fetch_assoc();
    }

    if (!$mat) {
        // Cek apakah ada pengajuan pending di bc_material_approval
        $hasPending = false;
        $pendingKode = '';
        $pendingId = 0;
        if (!empty($kd) && $stmtAppr) {
            $stmtAppr->bind_param("ss", $cmp_id, $kd);
            $stmtAppr->execute();
            $resA = $stmtAppr->get_result()->fetch_assoc();
            if ($resA) {
                $hasPending = true;
                $pendingKode = $resA['kode_internal'];
                $pendingId = (int)$resA['id'];
            }
        }

        $alasan = $hasPending 
            ? "Menunggu Approval Admin Inventory (Usulan Kode: $pendingKode)" 
            : "Kode Barang tidak ditemukan di Master Data Barang";

        $invalidItems[] = [
            "seri"         => $it['seri_barang'],
            "kode"         => $kd ?: '(KOSONG)',
            "uraian"       => $it['uraian'] ?? '',
            "satuan"       => $it['kode_satuan_barang'] ?? ($it['kode_satuan'] ?? 'PCE'),
            "hs"           => $it['pos_tarif'] ?? ($it['hs'] ?? ''),
            "alasan"       => $alasan,
            "has_pending"  => $hasPending,
            "pending_kode" => $pendingKode,
            "pending_id"   => $pendingId
        ];
    } elseif (empty(trim($mat['KLAS'] ?? ''))) {
        $invalidItems[] = [
            "seri"         => $it['seri_barang'],
            "kode"         => $kd,
            "uraian"       => $it['uraian'] ?? '',
            "satuan"       => $it['kode_satuan_barang'] ?? ($it['kode_satuan'] ?? 'PCE'),
            "hs"           => $it['pos_tarif'] ?? ($it['hs'] ?? ''),
            "alasan"       => "Klasifikasi barang pada Master Data Barang belum diset / masih kosong",
            "has_pending"  => false,
            "pending_kode" => '',
            "pending_id"   => 0
        ];
    } else {
        $isMapped = (strcasecmp($mat['KODE_BARANG'], $kd) !== 0);
        $validatedItems[] = [
            "raw"           => $it,
            "mat"           => $mat,
            "internal_kode" => $mat['KODE_BARANG'],
            "is_mapped"     => $isMapped
        ];
    }
}
if ($stmtMat) $stmtMat->close();
if ($stmtAppr) $stmtAppr->close();

// Jika ada item yang tidak valid, tolak proses dan kembalikan detail untuk modal interaktif
if (count($invalidItems) > 0) {
    echo json_encode([
        "status"         => false,
        "message"        => "Validasi gagal: Terdapat " . count($invalidItems) . " item barang yang belum sesuai dengan Master Data Barang.",
        "invalid_count"  => count($invalidItems),
        "invalid_items"  => $invalidItems,
        "can_proceed"    => false,
        "nomor_aju"      => $nomorAju
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
            cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, 
            NAMA_BS, KODE_BARANG, POS_TARIF, URAIAN, KLAS, 
            UNIT, QTY, KURS, NILAI, AB, 
            ID_HEADER, NOMOR_AJU, SERI_BARANG, HARGA_PENYERAHAN, CIF, 
            NETTO, SESUAI, CEK_KODEBRG, CEK, TGL_PROSES, 
            CRT_DT, CRT_USER
        ) VALUES (
            ?, '2.7', ?, ?, ?, ?, 
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

    $mappedCount = 0;
    foreach ($validatedItems as $v) {
        $r = $v['raw'];
        $m = $v['mat'];

        // Gunakan KODE_BARANG internal hasil pemetaan master barang!
        $kdBarang = $v['internal_kode'];
        if ($v['is_mapped']) {
            $mappedCount++;
        }

        $posTarif = !empty($r['pos_tarif']) ? $r['pos_tarif'] : ($m['POS_TARIF'] ?? '');
        $uraian   = !empty($m['URAIAN']) ? $m['URAIAN'] : ($r['uraian'] ?? '');
        $klas     = $m['KLAS'];
        $unit     = !empty($r['kode_satuan_barang']) ? $r['kode_satuan_barang'] : ($m['KODE_SATUAN'] ?? 'PCE');
        $qty      = (float)($r['jumlah_satuan'] ?? 0);
        $nilai    = (float)($r['nilai_barang'] ?? 0);
        $seri     = (int)($r['seri_barang'] ?? 1);
        $hargaPenyerahan = (float)($r['harga_penyerahan'] ?? 0);
        $cif      = (float)($r['cif'] ?? 0);
        $netto    = (float)($r['netto'] ?? 0);

        $insAb2->bind_param(
            "sssssssssssdssdssiddds",
            $cmp_id, $noDaftar, $tglDaftar, $noBukti, $tglBukti,
            $namaPengirim, $kdBarang, $posTarif, $uraian, $klas,
            $unit, $qty, $valuta, $nilai,
            $idHeader, $nomorAju, $seri, $hargaPenyerahan, $cif,
            $netto, $crtUser
        );
        $insAb2->execute();
    }
    $insAb2->close();

    $koneksi->commit();
    $mapMsg = $mappedCount > 0 ? " ($mappedCount item terpetakan otomatis dari kode supplier)" : "";
    echo json_encode([
        "status"      => true,
        "message"     => "Sukses! Berhasil membukukan dokumen BC 2.7 IN (No AJU: $nomorAju) ke catatan IT Inventory. Sebanyak " . count($validatedItems) . " item barang berhasil dimasukkan$mapMsg.",
        "item_count"  => count($validatedItems),
        "mapped_count"=> $mappedCount,
        "nomor_aju"   => $nomorAju
    ]);
} catch (Exception $ex) {
    $koneksi->rollback();
    echo json_encode(["status" => false, "message" => "Gagal menyimpan ke IT Inventory: " . $ex->getMessage()]);
}

<?php
/**
 * CEISA 4.0 - Warehouse: Incoming (warehouse_incoming.php)
 * Modul Penerimaan Barang Aktual Fisik Gudang & Posting ke IT Inventory (Laporan 1A - Pemasukan).
 * Tampilan & nuansa setara dengan Data Host to Host (transfer.php) khusus untuk PIC Warehouse.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = trim($_GET['cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? '')));
$theme = trim($_GET['theme'] ?? ($_SESSION['theme'] ?? 'light'));

// Resolusi Hak Akses Dokumen Perusahaan
$targetCmpId = !empty($sessionCmpId) ? $sessionCmpId : ($_SESSION['company_cmp_id'] ?? '');
$companyRoles = 'tpb';
$companyAllowedDocsStr = null;
if (!empty($targetCmpId) && isset($koneksi) && $koneksi) {
    $qComp = $koneksi->query("SELECT IFNULL(company_roles, 'tpb') AS company_roles, allowed_docs FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($targetCmpId) . "' LIMIT 1");
    if ($qComp && $rComp = $qComp->fetch_assoc()) {
        $companyRoles = $rComp['company_roles'];
        $companyAllowedDocsStr = $rComp['allowed_docs'];
    }
}
$companyAllowedDocs = ceisa_resolve_allowed_docs($companyRoles, $companyAllowedDocsStr);

if (!function_exists('isWhDocPermitted')) {
    function isWhDocPermitted($d) {
        global $isAdmin, $targetCmpId, $companyAllowedDocs;
        if (!empty($isAdmin) && (empty($targetCmpId) || $targetCmpId === 'ALL')) {
            return true;
        }
        return in_array((string)$d, $companyAllowedDocs);
    }
}

// -------------------------------------------------------------
// AJAX API ENDPOINTS
// -------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

// 1. LIST DOKUMEN PEMASUKAN (INCOMING)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $filter_cmp  = trim($_GET['cmp_id'] ?? $sessionCmpId);
    $filter_bc   = trim($_GET['bc'] ?? '');
    $filter_stat = trim($_GET['status'] ?? '');
    $search      = trim($_GET['q'] ?? '');
    $filter_per  = trim($_GET['periode'] ?? '');
    $filter_tgl1 = trim($_GET['tgl_awal'] ?? '');
    $filter_tgl2 = trim($_GET['tgl_akhir'] ?? '');

    $escCmp = $koneksi->real_escape_string($filter_cmp);
    $allInboundCodes = ['16', '20', '23', '262', '27', '28', '40', '611', '613'];
    $allowedInboundWh = (!empty($isAdmin) && (empty($filter_cmp) || $filter_cmp === 'ALL')) 
        ? $allInboundCodes 
        : array_values(array_filter($allInboundCodes, 'isWhDocPermitted'));
    if (empty($allowedInboundWh)) {
        $allowedInboundWh = ['__NONE__'];
    }
    $inboundBcs = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $allowedInboundWh)) . "'";

    $where = [];
    if (!empty($escCmp)) {
        $where[] = "(h.cmp_id = '$escCmp' OR h.cmp_id = '')";
    }

    // WAJIB: HANYA TAMPILKAN DOKUMEN YANG SUDAH MENDAPATKAN NOMOR DAFTAR DARI BEA CUKAI
    $where[] = "h.nomor_daftar IS NOT NULL AND TRIM(h.nomor_daftar) != '' AND TRIM(h.nomor_daftar) != '-'";

    if (!empty($filter_bc) && isWhDocPermitted($filter_bc)) {
        $where[] = "h.kode_dokumen = '" . $koneksi->real_escape_string($filter_bc) . "'";
    } else {
        $where[] = "h.kode_dokumen IN ($inboundBcs)";
    }

    // Filter tanggal pernyataan / pendaftaran
    if (!empty($filter_tgl1) && !empty($filter_tgl2)) {
        $t1 = $koneksi->real_escape_string($filter_tgl1);
        $t2 = $koneksi->real_escape_string($filter_tgl2);
        $where[] = "((h.tanggal_pernyataan >= '$t1' AND h.tanggal_pernyataan <= '$t2') OR (h.tanggal_daftar >= '$t1' AND h.tanggal_daftar <= '$t2'))";
    } elseif (!empty($filter_per)) {
        $per = $koneksi->real_escape_string($filter_per);
        $where[] = "(h.tanggal_pernyataan LIKE '$per%' OR h.tanggal_daftar LIKE '$per%')";
    }

    // Filter pencarian teks
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(h.nomor_aju LIKE '%$s%' OR h.nomor_daftar LIKE '%$s%' OR c.nama_entitas LIKE '%$s%' OR doc.nomor_dokumen LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT 
        h.nomor_aju,
        h.kode_dokumen AS bc,
        IFNULL(h.nomor_daftar, '-') AS nomor_daftar,
        IFNULL(h.tanggal_daftar, '-') AS tanggal_daftar,
        IFNULL(h.tanggal_pernyataan, '-') AS tanggal_pernyataan,
        IFNULL(c.nama_entitas, IFNULL(h.nama_pernyataan, '-')) AS supplier_name,
        IFNULL(doc.nomor_dokumen, '-') AS invoice_sj,
        IFNULL(h.kode_valuta, 'IDR') AS valuta,
        IFNULL(h.bruto, 0) AS bruto,
        IFNULL(resp.nomor_respon, IFNULL(resp.keterangan, '-')) AS sppb,
        (SELECT COUNT(1) FROM bc_ceisa_barang b WHERE b.nomor_aju = h.nomor_aju) AS jml_barang,
        (SELECT COUNT(1) FROM bc_1ab2 inv WHERE inv.nomor_aju = h.nomor_aju) AS is_it_inv,
        (SELECT MAX(inv.TGL_PROSES) FROM bc_1ab2 inv WHERE inv.nomor_aju = h.nomor_aju) AS it_tgl_proses,
        (SELECT MAX(inv.DOK) FROM bc_1ab2 inv WHERE inv.nomor_aju = h.nomor_aju) AS it_dok
    FROM bc_ceisa_header h
    LEFT JOIN (
        SELECT nomor_aju, MIN(nama_entitas) AS nama_entitas
        FROM bc_ceisa_entitas 
        WHERE kode_entitas IN ('3','7','8','PENGIRIM','PEMASOK','PENJUAL','5','9','1')
        GROUP BY nomor_aju
    ) c ON h.nomor_aju = c.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, MIN(nomor_dokumen) AS nomor_dokumen
        FROM bc_ceisa_dokumen 
        WHERE kode_dokumen IN ('380','230','217','999')
        GROUP BY nomor_aju
    ) doc ON h.nomor_aju = doc.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, MAX(nomor_respon) AS nomor_respon, MAX(keterangan) AS keterangan
        FROM bc_ceisa_respons 
        WHERE kode_respon IN ('2003','2303','2703','4003','61103') OR keterangan LIKE '%SPPB%'
        GROUP BY nomor_aju
    ) resp ON h.nomor_aju = resp.nomor_aju
    $whereSql
    ORDER BY h.tanggal_pernyataan DESC, h.nomor_aju DESC
    LIMIT 300";

    $res = $koneksi->query($sql);
    $data = [];
    $kpiTotal = 0;
    $kpiUnposted = 0;
    $kpiPosted = 0;

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $isInv = intval($r['is_it_inv']);
            if ($isInv > 0) {
                $kpiPosted++;
            } else {
                $kpiUnposted++;
            }
            $kpiTotal++;

            if ($filter_stat === 'it_inv' && $isInv == 0) continue;
            if ($filter_stat === 'unposted' && $isInv > 0) continue;

            $data[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $data,
        'count'  => count($data),
        'kpi'    => [
            'total'    => $kpiTotal,
            'unposted' => $kpiUnposted,
            'posted'   => $kpiPosted
        ]
    ]);
    exit;
}

// 2. GET DETAIL BARANG & VERIFIKASI FISIK
if ($action === 'detail_barang') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_GET['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);
    $escCmp = $koneksi->real_escape_string($sessionCmpId);

    // Ambil Header & Dokumen
    $h = $koneksi->query("SELECT nomor_aju, kode_dokumen, nomor_daftar, tanggal_daftar, nama_pernyataan FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1")->fetch_assoc();
    $supplier = $koneksi->query("SELECT nama_entitas FROM bc_ceisa_entitas WHERE nomor_aju = '$escAju' AND kode_entitas IN ('3','7','8','PENGIRIM','PEMASOK','PENJUAL','5','9','1') LIMIT 1")->fetch_assoc()['nama_entitas'] ?? ($h['nama_pernyataan'] ?? '-');
    $suggestedDok = $koneksi->query("SELECT nomor_dokumen FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju' AND kode_dokumen IN ('230','380','217') ORDER BY kode_dokumen ASC LIMIT 1")->fetch_assoc()['nomor_dokumen'] ?? ($h['nomor_daftar'] ?? '');

    // Cek apakah sudah pernah diposting di bc_1ab2
    $postedInfo = $koneksi->query("SELECT MAX(TGL_PROSES) AS tgl_proses, MAX(DOK) AS dok, COUNT(1) AS jml FROM bc_1ab2 WHERE nomor_aju = '$escAju'")->fetch_assoc();
    $isPosted = !empty($postedInfo['jml']) && intval($postedInfo['jml']) > 0;

    // Ambil Data Barang
    $sql = "SELECT 
        b.seri_barang,
        b.kode_barang,
        b.uraian,
        IFNULL(b.jumlah_satuan, 0) AS jumlah_satuan,
        IFNULL(b.kode_satuan, 'PCE') AS kode_satuan,
        IFNULL(b.hs, '') AS pos_tarif,
        IFNULL(b.harga_penyerahan, 0) AS harga_penyerahan,
        IFNULL(b.cif, 0) AS cif,
        IFNULL(b.netto, 0) AS netto,
        IFNULL((SELECT klas FROM bc_material m WHERE (m.KODE_BARANG = b.kode_barang OR m.KODE_BARANG2 = b.kode_barang) AND (m.cmp_id = '$escCmp' OR m.cmp_id = '') ORDER BY (m.KODE_BARANG = b.kode_barang) DESC LIMIT 1), IFNULL(b.tipe, 'Raw Materials')) AS klas,
        IFNULL((SELECT KODE_BARANG FROM bc_material m WHERE (m.KODE_BARANG = b.kode_barang OR m.KODE_BARANG2 = b.kode_barang) AND (m.cmp_id = '$escCmp' OR m.cmp_id = '') ORDER BY (m.KODE_BARANG = b.kode_barang) DESC LIMIT 1), b.kode_barang) AS kode_internal,
        inv.SESUAI AS inv_sesuai,
        inv.QTY_AKTUAL AS inv_qty_aktual,
        inv.MEMO AS inv_memo
    FROM bc_ceisa_barang b
    LEFT JOIN bc_1ab2 inv ON b.nomor_aju = inv.nomor_aju AND b.seri_barang = inv.SERI_BARANG
    WHERE b.nomor_aju = '$escAju'
    ORDER BY b.seri_barang ASC";

    $res = $koneksi->query($sql);
    $rows = [];
    $totQtyDok = 0;
    $totQtyAktual = 0;
    $totCif = 0;

    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $qtyDok = floatval($r['jumlah_satuan']);
            $totQtyDok += $qtyDok;
            $totCif += floatval($r['cif']);

            if ($isPosted) {
                $qtyAktual = floatval($r['inv_qty_aktual']);
                $sesuai = $r['inv_sesuai'] ?: ($qtyAktual == $qtyDok ? 'Y' : 'N');
                $memo = $r['inv_memo'] ?? '';
            } else {
                $qtyAktual = $qtyDok;
                $sesuai = 'Y';
                $memo = '';
            }
            $totQtyAktual += $qtyAktual;

            $r['qty_dokumen'] = $qtyDok;
            $r['qty_aktual']  = $qtyAktual;
            $r['sesuai']      = $sesuai;
            $r['memo']        = $memo;

            $rows[] = $r;
        }
    }

    echo json_encode([
        'status'         => 'OK',
        'nomor_aju'      => $nomor_aju,
        'header'         => $h,
        'supplier'       => $supplier,
        'suggested_dok'  => $suggestedDok,
        'is_posted'      => $isPosted,
        'posted_info'    => [
            'tgl_proses' => $postedInfo['tgl_proses'] ?? '',
            'dok'        => $postedInfo['dok'] ?? ''
        ],
        'totals'         => [
            'qty_dokumen' => $totQtyDok,
            'qty_aktual'  => $totQtyAktual,
            'cif'         => $totCif
        ],
        'data'           => $rows
    ]);
    exit;
}

// 3. KONFIRMASI PENERIMAAN WAREHOUSE & POSTING KE IT INVENTORY (bc_1ab2)
if ($action === 'confirm_post') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju   = trim($_POST['nomor_aju'] ?? '');
    $tgl_proses  = trim($_POST['tanggal_proses'] ?? date('Y-m-d'));
    $dok         = trim($_POST['dok'] ?? '');
    $itemsJson   = $_POST['items'] ?? '[]';
    $items       = json_decode($itemsJson, true);

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }
    if (empty($tgl_proses)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tanggal Aktual Masuk (TANGGAL_PROSES) wajib diisi.']);
        exit;
    }
    if (empty($dok)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor BUKTI Pemasukan / Surat Jalan (DOK) wajib diisi.']);
        exit;
    }
    if (!is_array($items) || empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Daftar rincian barang kosong.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);

    // Cek apakah sudah terposting
    $chk = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '$escAju'")->fetch_row()[0] ?? 0;
    if ($chk > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah terposting di IT Inventory!"]);
        exit;
    }

    // Ambil data header
    $h = $koneksi->query("SELECT * FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1")->fetch_assoc();
    if (!$h) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju tidak ditemukan di staging header."]);
        exit;
    }

    $docCmp = !empty($h['cmp_id']) ? $h['cmp_id'] : $sessionCmpId;
    $kodeBc = $h['kode_dokumen'] ?? '23';
    $noDaftar = $h['nomor_daftar'] ?? '-';
    $tglDaftar = $h['tanggal_daftar'] ?? '-';

    // Ambil supplier & invoice
    $supplier = $koneksi->query("SELECT nama_entitas FROM bc_ceisa_entitas WHERE nomor_aju = '$escAju' AND kode_entitas IN ('3','7','8','PENGIRIM','PEMASOK','PENJUAL','5','9','1') LIMIT 1")->fetch_assoc()['nama_entitas'] ?? ($h['nama_pernyataan'] ?? 'PEMASOK');
    $invoice  = $koneksi->query("SELECT nomor_dokumen FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju' AND kode_dokumen IN ('380','217') LIMIT 1")->fetch_assoc()['nomor_dokumen'] ?? '';
    $sj       = $koneksi->query("SELECT nomor_dokumen FROM bc_ceisa_dokumen WHERE nomor_aju = '$escAju' AND kode_dokumen = '230' LIMIT 1")->fetch_assoc()['nomor_dokumen'] ?? '';

    // Ambil master barang pabean
    $qBrg = $koneksi->query("SELECT * FROM bc_ceisa_barang WHERE nomor_aju = '$escAju'");
    $mapBrg = [];
    if ($qBrg) {
        while ($b = $qBrg->fetch_assoc()) {
            $mapBrg[$b['seri_barang']] = $b;
        }
    }

    $koneksi->begin_transaction();
    try {
        $stmtInsert = $koneksi->prepare("
            INSERT INTO bc_1ab2 (
                cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS,
                KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER,
                SJ, INVOICE, FAKTUR, NOMOR_AJU, SERI_BARANG, HARGA_PENYERAHAN, CIF, NETTO,
                SESUAI, QTY_AKTUAL, MEMO, DOK, TGL_PROSES, CRT_DT, CRT_USER
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, NOW(), ?
            )
        ");

        if (!$stmtInsert) {
            throw new Exception("Prepare failed: " . $koneksi->error);
        }

        $postedItems = 0;
        $currentUser = $_SESSION['user_id'] ?? ($_SESSION['username'] ?? 'WAREHOUSE');

        foreach ($items as $it) {
            $seri = intval($it['seri_barang'] ?? 0);
            if (!isset($mapBrg[$seri])) continue;
            $b = $mapBrg[$seri];

            $qtyDok = floatval($b['jumlah_satuan'] ?? 0);
            $qtyAkt = floatval($it['qty_aktual'] ?? $qtyDok);
            $sesuai = ($it['sesuai'] === 'Y' || ($qtyAkt == $qtyDok && empty($it['memo']))) ? 'Y' : 'N';
            $memo   = trim((string)($it['memo'] ?? ''));

            // Validasi wajib memo jika beda
            if ($qtyAkt != $qtyDok && empty($memo)) {
                throw new Exception("Baris #$seri (Kode: {$b['kode_barang']}) memiliki selisih fisik ({$qtyAkt} vs {$qtyDok}), kolom MEMO wajib diisi alasan selisih!");
            }

            // Ambil klasifikasi & kode internal material (mendukung auto-mapping via KODE_BARANG2)
            $escKode = $koneksi->real_escape_string($b['kode_barang']);
            $matRow = $koneksi->query("SELECT KODE_BARANG, KLAS FROM bc_material WHERE (KODE_BARANG = '$escKode' OR KODE_BARANG2 = '$escKode') AND (cmp_id = '$docCmp' OR cmp_id = '') ORDER BY (KODE_BARANG = '$escKode') DESC LIMIT 1")->fetch_assoc();
            $finalKodeBarang = !empty($matRow['KODE_BARANG']) ? $matRow['KODE_BARANG'] : $b['kode_barang'];
            $klas = $matRow['KLAS'] ?? ($b['tipe'] ?: 'Raw Materials');

            $hs = $b['pos_tarif'] ?: ($b['hs'] ?? '');
            $satuan = $b['kode_satuan'] ?: 'PCE';
            $valuta = $h['kode_valuta'] ?: 'IDR';
            $nilai  = floatval($b['cif'] ?: ($b['harga_penyerahan'] ?: 0));
            $ab     = 'A'; // Incoming = Pemasukan (1A)
            $noBukti = !empty($invoice) ? $invoice : (!empty($sj) ? $sj : $noDaftar);
            $tglBukti = $h['tanggal_pernyataan'] ?: ($h['tanggal_daftar'] ?: date('Y-m-d'));
            $faktur = '';
            $hargaPenyerahan = floatval($b['harga_penyerahan'] ?? 0);
            $cif = floatval($b['cif'] ?? 0);
            $netto = floatval($b['netto'] ?? 0);

            $stmtInsert->bind_param(
                "ssssssssssssdsssssssiddssssssss",
                $docCmp, $kodeBc, $noDaftar, $tglDaftar, $noBukti, $tglBukti, $supplier,
                $finalKodeBarang, $hs, $b['uraian'], $klas, $satuan, $qtyDok, $valuta, $nilai, $ab, $nomor_aju,
                $sj, $invoice, $faktur, $nomor_aju, $seri, $hargaPenyerahan, $cif, $netto,
                $sesuai, $qtyAkt, $memo, $dok, $tgl_proses, $currentUser
            );

            if (!$stmtInsert->execute()) {
                throw new Exception("Execute insert error item #$seri: " . $stmtInsert->error);
            }
            $postedItems++;
        }
        $stmtInsert->close();

        // Update bc_ceisa_aju_posted
        $koneksi->query("INSERT INTO bc_ceisa_aju_posted (cmp_id, nomor_aju, waktu, bc, nomor_daftar, tanggal_daftar) 
                         VALUES ('$docCmp', '$escAju', NOW(), '$kodeBc', '$noDaftar', '$tglDaftar')
                         ON DUPLICATE KEY UPDATE waktu = NOW(), nomor_daftar = VALUES(nomor_daftar), tanggal_daftar = VALUES(tanggal_daftar)");

        $koneksi->commit();
        echo json_encode([
            'status'  => 'OK',
            'message' => "Berhasil mengonfirmasi penerimaan barang dan memposting $postedItems item ke IT Inventory (1A - Pemasukan)!"
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// 4. BATALKAN POSTING WAREHOUSE (UNPOSTING)
if ($action === 'unpost_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);

    $koneksi->begin_transaction();
    try {
        // Hapus dari bc_1ab2
        $del = $koneksi->query("DELETE FROM bc_1ab2 WHERE nomor_aju = '$escAju'");
        if (!$del) {
            throw new Exception("Gagal menghapus data dari bc_1ab2: " . $koneksi->error);
        }
        $koneksi->commit();
        echo json_encode([
            'status'  => 'OK',
            'message' => "Posting IT Inventory untuk nomor AJU $nomor_aju berhasil dibatalkan. Dokumen sekarang siap diverifikasi ulang."
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// 5. DETAIL TAB: DOKUMEN LAMPIRAN, STATUS, RESPON
if ($action === 'detail_tab') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_GET['nomor_aju'] ?? '');
    $tab       = trim($_GET['tab'] ?? 'dokumen');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);

    if ($tab === 'dokumen') {
        $q = "SELECT 
                d.seri, 
                d.kode_dokumen, 
                d.nomor_dokumen, 
                d.tanggal_dokumen,
                COALESCE(r.teks, '') AS nama_dokumen
              FROM bc_ceisa_dokumen d
              LEFT JOIN bc_ceisa_reff r 
                ON r.reff = 'DOKUMEN' AND r.kode = d.kode_dokumen
              WHERE d.nomor_aju = '$escAju'
              ORDER BY d.seri ASC";
        $res = $koneksi->query($q);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'data' => $rows]);
        exit;
    }

    if ($tab === 'status') {
        $res = $koneksi->query("SELECT waktu_status, kode_proses, keterangan, nomor_daftar FROM bc_ceisa_status WHERE nomor_aju = '$escAju' ORDER BY waktu_status ASC, id ASC");
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'data' => $rows]);
        exit;
    }

    if ($tab === 'respon') {
        $res = $koneksi->query("SELECT tanggal_respon, kode_respon, nomor_respon, keterangan, pdf FROM bc_ceisa_respons WHERE nomor_aju = '$escAju' ORDER BY id ASC");
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'data' => $rows]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <title>Warehouse - Incoming (Penerimaan Barang & Posting IT Inventory 1A)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-filter: #f9fbfd;
            --border-color: #d9d9d9;
            --border-table: #e0e0e0;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
            --row-selected-text: #002766;
            --table-head-bg: #fafafa;
        }
        [data-theme='dark'], html[data-theme="dark"], body[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-filter: #181818;
            --border-color: #303030;
            --border-table: #303030;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --row-hover: #262638;
            --row-selected: #112a45;
            --row-selected-text: #bae7ff;
            --table-head-bg: #191919;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-size: 10px;
            line-height: 1.45;
            padding: 4px 6px;
            overflow: hidden;
            height: 100vh;
        }

        /* Split Screen Container */
        .split-container {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 8px);
            width: 100%;
            overflow: hidden;
        }
        .split-pane-top {
            flex: 0 0 52%;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .split-divider {
            height: 5px;
            background: #d9d9d9;
            cursor: row-resize;
            margin: 2px 0;
            position: relative;
            z-index: 10;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .split-divider:hover, .split-divider.dragging {
            background: var(--primary);
        }
        .split-handle {
            width: 32px;
            height: 2px;
            background: #8c8c8c;
            border-radius: 1px;
        }
        .split-pane-bottom {
            flex: 1;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Card container */
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .ant-card-head {
            min-height: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            padding: 4px 8px;
            background: var(--bg-card);
            flex-wrap: wrap;
            gap: 6px;
        }
        .ant-card-head-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .ant-card-head-actions {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        .ant-card-body {
            padding: 4px 6px;
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Buttons */
        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            height: 24px;
            padding: 0 8px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .ant-btn:hover { color: var(--primary); border-color: var(--primary); }
        .ant-btn-primary { background: var(--primary); border-color: var(--primary); color: #fff !important; font-weight: 600; }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
        .ant-btn-success { background: var(--success); border-color: var(--success); color: #fff !important; font-weight: 600; }
        .ant-btn-success:hover { opacity: 0.9; }
        .ant-btn-warning { background: var(--warning); border-color: var(--warning); color: #fff !important; font-weight: 600; }
        .ant-btn-danger  { background: var(--danger); border-color: var(--danger); color: #fff !important; font-weight: 600; }
        .ant-btn-sm { height: 20px; padding: 0 5px; font-size: 9px; }

        /* Filter Panel */
        .filter-panel {
            display: flex;
            padding: 4px 6px;
            margin-bottom: 4px;
            gap: 6px;
            align-items: flex-end;
            background: var(--bg-filter);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            flex-wrap: wrap;
        }
        .filter-col { display: flex; flex-direction: column; gap: 2px; }
        .filter-col label { font-size: 9px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
        .filter-control {
            height: 22px;
            padding: 0 6px;
            font-size: 10px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .filter-control:focus { border-color: var(--primary); }

        /* KPI Chips */
        .kpi-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            background: rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            font-size: 10px;
        }
        .kpi-chip .kpi-label { color: var(--text-muted); }
        .kpi-chip .kpi-val { font-weight: 700; color: var(--text-heading); }

        /* Table Grid */
        .table-scroll {
            flex: 1;
            overflow: auto;
            border: 1px solid var(--border-table);
            background: var(--bg-card);
        }
        table.excel-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            white-space: nowrap;
        }
        table.excel-grid th {
            background: var(--table-head-bg);
            color: var(--text-heading);
            font-weight: 600;
            padding: 4px 6px;
            border: 1px solid var(--border-table);
            position: sticky;
            top: 0;
            z-index: 5;
            font-size: 9.5px;
        }
        table.excel-grid td {
            padding: 3px 6px;
            border: 1px solid var(--border-table);
            color: var(--text-main);
            vertical-align: middle;
        }
        table.excel-grid tbody tr { cursor: pointer; }
        table.excel-grid tbody tr:hover td:not(.cell-it-inv) {
            background: var(--row-hover) !important;
        }
        table.excel-grid tbody tr.selected-row {
            outline: 2px solid #ff7a45 !important;
            outline-offset: -2px !important;
        }
        table.excel-grid tbody tr.selected-row td:not(.cell-it-inv) {
            background: var(--row-selected) !important;
            color: var(--row-selected-text) !important;
        }

        /* PowerBuilder Color Rules */
        td.cell-it-inv {
            background: #ffff88 !important;
            color: #000000 !important;
            font-weight: 600;
        }
        tr.row-submitted td:not(.cell-it-inv) {
            background-color: #e6f4ff !important;
            color: #002c6a !important;
            border-color: rgba(24, 144, 255, 0.18) !important;
        }
        tr.row-submitted:hover td:not(.cell-it-inv) {
            background-color: #d4ecff !important;
            color: #002c6a !important;
        }
        body[data-theme="dark"] tr.row-submitted td:not(.cell-it-inv) {
            background-color: #11253e !important;
            color: #bae7ff !important;
            border-color: rgba(23, 125, 220, 0.25) !important;
        }
        body[data-theme="dark"] tr.row-submitted:hover td:not(.cell-it-inv) {
            background-color: #163254 !important;
        }

        /* Maximized states */
        .split-container.maximized-top .split-pane-top {
            flex: 1 1 100% !important;
            height: 100% !important;
            max-height: 100% !important;
        }
        .split-container.maximized-top .split-divider,
        .split-container.maximized-top .split-pane-bottom {
            display: none !important;
        }

        .split-container.maximized-bottom .split-pane-bottom {
            flex: 1 1 100% !important;
            height: 100% !important;
            max-height: 100% !important;
        }
        .split-container.maximized-bottom .split-divider,
        .split-container.maximized-bottom .split-pane-top {
            display: none !important;
        }

        .split-divider .split-btn-collapse {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 8px;
            line-height: 1;
            padding: 1px 5px;
            height: 14px;
            border-radius: 2px;
            cursor: pointer;
            margin: 0 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            z-index: 25;
        }
        .split-divider .split-btn-collapse:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        /* Tags */
        .ant-tag {
            display: inline-block;
            padding: 1px 5px;
            font-size: 9.5px;
            border-radius: 2px;
            border: 1px solid var(--border-color);
            line-height: 1.3;
        }
        .ant-tag-blue   { background: #e6f7ff; color: #1890ff; border-color: #91d5ff; }
        .ant-tag-green  { background: #f6ffed; color: #52c41a; border-color: #b7eb8f; }
        .ant-tag-orange { background: #fff7e6; color: #fa8c16; border-color: #ffd591; }
        .ant-tag-red    { background: #fff1f0; color: #ff4d4f; border-color: #ffa39e; }
        .ant-tag-yellow { background: #feffe6; color: #ad8b00; border-color: #fffb8f; }

        /* Detail Pane Tabs */
        .tab-bar { display: flex; gap: 4px; }
        .tab-btn {
            background: none;
            border: 1px solid transparent;
            border-bottom: 2px solid transparent;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(24, 144, 255, 0.05);
            border-radius: 3px 3px 0 0;
        }
        .tab-panel { display: none; flex: 1; flex-direction: column; overflow: hidden; }
        .tab-panel.active { display: flex; }

        /* Editable Inputs inside Grid */
        .grid-input-num {
            width: 75px;
            height: 20px;
            padding: 0 4px;
            font-size: 10px;
            text-align: right;
            border: 1px solid var(--border-color);
            border-radius: 2px;
            background: var(--bg-card);
            color: var(--text-main);
            font-weight: 600;
        }
        .grid-input-num.diff {
            border-color: #fa8c16;
            background: #fff7e6;
            color: #d46b08;
        }
        .grid-input-text {
            width: 100%;
            height: 20px;
            padding: 0 4px;
            font-size: 10px;
            border: 1px solid var(--border-color);
            border-radius: 2px;
            background: var(--bg-card);
            color: var(--text-main);
        }
        .grid-input-text.required-empty {
            border-color: #ff4d4f;
            background: #fff1f0;
        }

        /* Summary bar */
        .summary-strip {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
            padding: 3px 8px;
            background: var(--table-head-bg);
            border: 1px solid var(--border-table);
            border-top: none;
            font-size: 10px;
        }
        .summary-strip strong { font-weight: 700; color: var(--primary); }
    </style>
</head>
<body>

    <div class="split-container" id="splitContainer">
        
        <!-- PANE TOP: DAFTAR DOKUMEN PEMASUKAN -->
        <div class="split-pane-top" id="topPane">
            <div class="ant-card" style="height:100%;">
                <div class="ant-card-head">
                    <div class="ant-card-head-title">
                        <i class="fa-solid fa-boxes-packing" style="color:var(--primary); font-size:13px;"></i>
                        <span>Warehouse - Incoming (Penerimaan Barang Fisik)</span>
                        <div class="kpi-chip">
                            <span class="kpi-label">Total:</span>
                            <span class="kpi-val" id="kpiTotal">0</span>
                        </div>
                        <div class="kpi-chip">
                            <span class="kpi-label">Belum Diterima:</span>
                            <span class="kpi-val" style="color:var(--danger);" id="kpiUnposted">0</span>
                        </div>
                        <div class="kpi-chip">
                            <span class="kpi-label">Sudah Masuk IT Inv:</span>
                            <span class="kpi-val" style="color:var(--success);" id="kpiPosted">0</span>
                        </div>
                    </div>
                    <div class="ant-card-head-actions">
                        <button type="button" class="ant-btn ant-btn-sm" id="btnSyncRespon" onclick="triggerSyncRespon()" title="Tarik respon & perbarui nomor daftar dari portal CEISA 4.0">
                            <i class="fa-solid fa-cloud-arrow-down" id="iconSyncRespon" style="color:var(--primary);"></i> <span id="textSyncRespon">Tarik / Sync Respon</span>
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="loadList()" title="Segarkan Data">
                            <i class="fa-solid fa-arrows-rotate"></i> Muat Ulang
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm" id="btnMaxTop" onclick="toggleMaximizePane('top')" title="Perbesar / Maximize Grid Dokumen (Layar Penuh)">
                            <i class="fa-solid fa-expand" id="iconMaxTop"></i> <span id="textMaxTop">Maximize</span>
                        </button>
                    </div>
                </div>

                <div class="ant-card-body">
                    <!-- Filter Toolbar -->
                    <div class="filter-panel">
                        <div class="filter-col">
                            <label>Dokumen Pemasukan</label>
                            <select id="filterBc" class="filter-control" onchange="loadList()">
                                <option value="">Semua Dokumen Masuk</option>
                                <?php if (isWhDocPermitted('16')): ?><option value="16">BC 1.6 (Pemasukan PLB)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('20')): ?><option value="20">BC 2.0 (Impor PIB)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('23')): ?><option value="23">BC 2.3 (Impor Kawasan Berikat)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('262')): ?><option value="262">BC 2.6.2 (Subkon Masuk)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('27')): ?><option value="27">BC 2.7 (TPB ke TPB IN)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('28')): ?><option value="28">BC 2.8 (Impor PLB)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('40')): ?><option value="40">BC 4.0 (Pemasukan TLDDP)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('611')): ?><option value="611">PPKEK 6.1.1 (Pemasukan KEK)</option><?php endif; ?>
                                <?php if (isWhDocPermitted('613')): ?><option value="613">PPKEK 6.1.3 (Pemasukan KEK)</option><?php endif; ?>
                            </select>
                        </div>
                        <div class="filter-col">
                            <label>Status IT Inventory</label>
                            <select id="filterStatus" class="filter-control" onchange="loadList()">
                                <option value="" selected>Semua Status</option>
                                <option value="unposted">⚠️ Belum Masuk IT Inventory</option>
                                <option value="it_inv">✅ Sudah Masuk IT Inventory</option>
                            </select>
                        </div>
                        <div class="filter-col">
                            <label>Periode</label>
                            <input type="month" id="filterPeriode" class="filter-control" value="<?= date('Y-m') ?>" onchange="loadList()">
                        </div>
                        <div class="filter-col" style="flex:1; min-width:200px;">
                            <label>Pencarian Dokumen / Supplier / SJ</label>
                            <input type="text" id="filterSearch" class="filter-control" placeholder="Ketik No. AJU, Supplier, No. Daftar, Invoice/SJ lalu tekan Enter..." onkeyup="if(event.key==='Enter') loadList()">
                        </div>
                        <div>
                            <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadList()">
                                <i class="fa-solid fa-magnifying-glass"></i> Cari
                            </button>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="resetFilters()">Reset</button>
                        </div>
                    </div>

                    <!-- Master Table -->
                    <div class="table-scroll">
                        <table class="excel-grid" id="hdrTable">
                            <thead>
                                <tr>
                                    <th style="width:30px; text-align:center;">#</th>
                                    <th style="width:60px; text-align:center;">Dokumen</th>
                                    <th style="width:215px;">Nomor Pengajuan (AJU)</th>
                                    <th style="width:75px; text-align:center;">Tgl AJU</th>
                                    <th style="width:80px; text-align:center;">No. Daftar</th>
                                    <th style="width:75px; text-align:center;">Tgl Daftar</th>
                                    <th style="width:230px;">Pemasok / Supplier</th>
                                    <th style="width:130px;">Invoice / SJ Dokumen</th>
                                    <th style="width:50px; text-align:center;">Valuta</th>
                                    <th style="width:60px; text-align:right;">Jml Item</th>
                                    <th style="width:80px; text-align:right;">Bruto (kg)</th>
                                    <th style="width:120px;">SPPB / Respon</th>
                                    <th style="width:115px; text-align:center;">Status IT Inventory</th>
                                </tr>
                            </thead>
                            <tbody id="hdrTbody">
                                <tr><td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data dokumen masuk...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- SPLITBAR DIVIDER -->
        <div class="split-divider" id="splitbar" title="Tarik untuk mengubah ukuran tampilan, double-click untuk toggle maximize">
            <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleMaximizePane('top');" title="Maximize Grid Master (Atas)">&#x25BC; Maximize Grid</button>
            <div class="split-handle"></div>
            <button type="button" class="split-btn-collapse" onclick="event.stopPropagation(); toggleMaximizePane('bottom');" title="Maximize Grid Detail (Bawah)">&#x25B2; Detail</button>
        </div>

        <!-- PANE BOTTOM: DETAIL DOKUMEN & VERIFIKASI FISIK WAREHOUSE -->
        <div class="split-pane-bottom" id="bottomPane">
            <div class="ant-card" style="height:100%;">
                <div class="ant-card-head">
                    <div class="ant-card-head-title">
                        <span style="font-size:9.5px; color:var(--text-muted);">Dokumen:</span>
                        <strong id="activeAjuBadge" style="color:var(--primary); font-family:monospace; font-size:11px;">PILIH DOKUMEN</strong>
                        <span id="activeSupplierBadge" style="font-size:10px; color:var(--text-muted);"></span>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px;">
                        <!-- Action Bar Barang -->
                        <div id="actionUnposted" style="display:none; align-items:center; gap:4px;">
                            <button type="button" class="ant-btn ant-btn-sm" onclick="checkAllSesuai()" title="Samakan semua QTY Aktual dengan QTY Dokumen">
                                <i class="fa-solid fa-check-double" style="color:var(--primary);"></i> Centang Semua Sesuai
                            </button>
                            <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="openConfirmModal()" title="Konfirmasi barang fisik masuk dan posting ke IT Inventory">
                                <i class="fa-solid fa-boxes-packing"></i> Konfirmasi Penerimaan (Posting IT Inv)
                            </button>
                        </div>
                        <div id="actionPosted" style="display:none; align-items:center; gap:6px;">
                            <span class="ant-tag ant-tag-yellow" id="badgePostedInfo" style="font-weight:600;">
                                <i class="fa-solid fa-check"></i> Sudah Masuk IT Inventory
                            </span>
                            <button type="button" class="ant-btn ant-btn-sm ant-btn-warning" onclick="unpostItInventory()" title="Batalkan posting untuk revisi data fisik">
                                <i class="fa-solid fa-rotate-left"></i> Batalkan Konfirmasi
                            </button>
                        </div>

                        <!-- Tab Switcher -->
                        <div class="tab-bar">
                            <button type="button" class="tab-btn active" data-tab="barang" onclick="switchDetailTab('barang')">
                                <i class="fa-solid fa-boxes-stacked"></i> Verifikasi Barang Fisik (<span id="cntBarang">0</span>)
                            </button>
                            <button type="button" class="tab-btn" data-tab="dokumen" onclick="switchDetailTab('dokumen')">
                                <i class="fa-solid fa-file-invoice"></i> Dokumen Lampiran
                            </button>
                            <button type="button" class="tab-btn" data-tab="status" onclick="switchDetailTab('status')">
                                <i class="fa-solid fa-satellite-dish"></i> Status Pabean
                            </button>
                            <button type="button" class="tab-btn" data-tab="respon" onclick="switchDetailTab('respon')">
                                <i class="fa-solid fa-reply"></i> Respon CEISA
                            </button>
                        </div>

                        <button type="button" class="ant-btn ant-btn-sm" id="btnMaxBottom" onclick="toggleMaximizePane('bottom')" title="Perbesar / Maximize Grid Detail (Layar Penuh)">
                            <i class="fa-solid fa-expand" id="iconMaxBottom"></i> <span id="textMaxBottom">Maximize Detail</span>
                        </button>
                    </div>
                </div>

                <div class="ant-card-body">
                    <!-- Tab Panel 1: Verifikasi Barang Fisik -->
                    <div class="tab-panel active" id="panel-barang">
                        <div class="table-scroll">
                            <table class="excel-grid" id="dtlTableBarang">
                                <thead>
                                    <tr>
                                        <th style="width:30px; text-align:center;">#</th>
                                        <th style="width:130px;">Kode Barang</th>
                                        <th>Uraian Barang</th>
                                        <th style="width:55px; text-align:center;">Satuan</th>
                                        <th style="width:85px; text-align:right;">Qty Dokumen</th>
                                        <th style="width:90px; text-align:center;" title="Centang jika jumlah fisik aktual sesuai persis dengan dokumen">
                                            Centang Sesuai
                                        </th>
                                        <th style="width:95px; text-align:right;" title="Isi jumlah aktual fisik yang diterima di gudang">
                                            Qty Aktual Fisik
                                        </th>
                                        <th style="width:75px; text-align:center;">Selisih</th>
                                        <th style="width:240px;" title="Wajib diisi jika Qty Aktual fisik berbeda dengan Qty Dokumen">
                                            Catatan / Memo Fisik (Wajib jika beda)
                                        </th>
                                        <th style="width:110px;">Klasifikasi</th>
                                        <th style="width:95px; text-align:right;">Nilai CIF</th>
                                    </tr>
                                </thead>
                                <tbody id="dtlTbodyBarang">
                                    <tr><td colspan="11" style="text-align:center; padding:16px; color:var(--text-muted);">Pilih salah satu dokumen di atas untuk memeriksa barang fisik.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="summary-strip" id="summaryBarang">
                            <div>Total Qty Dokumen: <strong id="sumQtyDok">0.00</strong></div>
                            <div>Total Qty Aktual Fisik: <strong id="sumQtyAkt">0.00</strong></div>
                            <div>Status Selisih: <strong id="sumSelisih" style="color:var(--success);">0 (Semua Sesuai)</strong></div>
                            <div>Total CIF: <strong id="sumCif">0.00</strong></div>
                        </div>
                    </div>

                    <!-- Tab Panel 2: Dokumen Lampiran -->
                    <div class="tab-panel" id="panel-dokumen">
                        <div class="table-scroll">
                            <table class="excel-grid">
                                <thead>
                                    <tr>
                                        <th style="width:35px; text-align:center;">#</th>
                                        <th style="width:75px; text-align:center;">Kode</th>
                                        <th style="width:200px;">Jenis Dokumen</th>
                                        <th>Nomor Dokumen</th>
                                        <th style="width:110px; text-align:center;">Tanggal Dokumen</th>
                                    </tr>
                                </thead>
                                <tbody id="dtlTbodyDokumen">
                                    <tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">Pilih dokumen terlebih dahulu.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab Panel 3: Status Pabean -->
                    <div class="tab-panel" id="panel-status">
                        <div class="table-scroll">
                            <table class="excel-grid">
                                <thead>
                                    <tr>
                                        <th style="width:130px;">Waktu Status</th>
                                        <th style="width:80px;">Kode Proses</th>
                                        <th>Keterangan Status</th>
                                        <th style="width:100px;">No. Daftar</th>
                                    </tr>
                                </thead>
                                <tbody id="dtlTbodyStatus">
                                    <tr><td colspan="4" style="text-align:center; padding:16px; color:var(--text-muted);">Pilih dokumen terlebih dahulu.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab Panel 4: Respon CEISA -->
                    <div class="tab-panel" id="panel-respon">
                        <div class="table-scroll">
                            <table class="excel-grid">
                                <thead>
                                    <tr>
                                        <th style="width:120px;">Tgl Respon</th>
                                        <th style="width:80px;">Kode Respon</th>
                                        <th style="width:150px;">No. Respon</th>
                                        <th>Keterangan Respon</th>
                                        <th style="width:80px; text-align:center;">PDF</th>
                                    </tr>
                                </thead>
                                <tbody id="dtlTbodyRespon">
                                    <tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">Pilih dokumen terlebih dahulu.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Script Application Logic -->
    <script>
        const cmpId = '<?= addslashes($sessionCmpId) ?>';
        let activeAju = '';
        let activeDocData = null;
        let activeItems = [];
        let isDocPosted = false;

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        function escapeJs(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        // 1. Load Master Documents
        async function loadList() {
            const bc = document.getElementById('filterBc').value;
            const stat = document.getElementById('filterStatus').value;
            const per = document.getElementById('filterPeriode').value;
            const q = document.getElementById('filterSearch').value.trim();

            const tbody = document.getElementById('hdrTbody');
            tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data dokumen masuk...</td></tr>`;

            try {
                const res = await fetch(`warehouse_incoming.php?action=list&cmp_id=${encodeURIComponent(cmpId)}&bc=${encodeURIComponent(bc)}&status=${encodeURIComponent(stat)}&periode=${encodeURIComponent(per)}&q=${encodeURIComponent(q)}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    renderMasterKPI(result.kpi);
                    renderMasterTable(result.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding:15px; color:var(--danger);">${escapeHtml(result.message)}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding:15px; color:var(--danger);">Kesalahan jaringan: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderMasterKPI(kpi) {
            if (!kpi) return;
            document.getElementById('kpiTotal').textContent = (kpi.total || 0).toLocaleString();
            document.getElementById('kpiUnposted').textContent = (kpi.unposted || 0).toLocaleString();
            document.getElementById('kpiPosted').textContent = (kpi.posted || 0).toLocaleString();
        }

        function resetFilters() {
            document.getElementById('filterBc').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterSearch').value = '';
            loadList();
        }

        function renderMasterTable(data) {
            const tbody = document.getElementById('hdrTbody');
            if (!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding:30px; color:var(--text-muted);"><i class="fa-solid fa-inbox" style="font-size:20px;"></i><br>Tidak ada dokumen pemasukan yang sesuai kriteria.</td></tr>`;
                return;
            }

            let html = '';
            data.forEach((r, idx) => {
                const aju = r.nomor_aju;
                const isInv = parseInt(r.is_it_inv) > 0;
                const isSelected = (aju === activeAju);
                const hasDaftar = (r.nomor_daftar && r.nomor_daftar !== '-');

                let rowClass = '';
                if (isSelected) rowClass += ' selected-row';
                if (hasDaftar && !isInv) rowClass += ' row-submitted';

                let statusBadge = '';
                if (isInv) {
                    statusBadge = `<span class="ant-tag ant-tag-yellow"><i class="fa-solid fa-check"></i> Sudah Masuk IT Inv</span>`;
                } else if (hasDaftar) {
                    statusBadge = `<span class="ant-tag ant-tag-orange"><i class="fa-solid fa-triangle-exclamation"></i> Belum Masuk IT Inv</span>`;
                } else {
                    statusBadge = `<span class="ant-tag ant-tag-blue">Draft Perekaman</span>`;
                }

                html += `
                    <tr class="${rowClass}" onclick="selectAju('${escapeJs(aju)}')">
                        <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                        <td style="text-align:center;"><span class="ant-tag ant-tag-blue">BC ${escapeHtml(r.bc)}</span></td>
                        <td class="${isInv ? 'cell-it-inv' : ''}">
                            <strong style="font-family:monospace;">${escapeHtml(aju)}</strong>
                        </td>
                        <td style="text-align:center;">${escapeHtml(r.tanggal_pernyataan)}</td>
                        <td style="text-align:center;"><strong>${escapeHtml(r.nomor_daftar)}</strong></td>
                        <td style="text-align:center;">${escapeHtml(r.tanggal_daftar)}</td>
                        <td title="${escapeHtml(r.supplier_name)}">${escapeHtml(r.supplier_name)}</td>
                        <td>${escapeHtml(r.invoice_sj)}</td>
                        <td style="text-align:center;">${escapeHtml(r.valuta)}</td>
                        <td style="text-align:right;"><strong>${escapeHtml(r.jml_barang)}</strong></td>
                        <td style="text-align:right;">${parseFloat(r.bruto || 0).toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                        <td>${escapeHtml(r.sppb)}</td>
                        <td style="text-align:center;" class="${isInv ? 'cell-it-inv' : ''}">${statusBadge}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;

            // Auto select first document if none active
            if (!activeAju && data.length > 0) {
                selectAju(data[0].nomor_aju);
            }
        }

        // 2. Select Document & Load Items
        async function selectAju(aju) {
            activeAju = aju;
            document.querySelectorAll('#hdrTbody tr').forEach(tr => {
                if (tr.innerHTML.includes(aju)) tr.classList.add('selected-row');
                else tr.classList.remove('selected-row');
            });

            document.getElementById('activeAjuBadge').textContent = aju;
            document.getElementById('dtlTbodyBarang').innerHTML = `<tr><td colspan="11" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data rincian barang...</td></tr>`;

            try {
                const res = await fetch(`warehouse_incoming.php?action=detail_barang&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    activeDocData = result;
                    activeItems = result.data || [];
                    isDocPosted = result.is_posted;

                    document.getElementById('cntBarang').textContent = activeItems.length;
                    document.getElementById('activeSupplierBadge').textContent = `• Supplier: ${result.supplier || '-'}`;

                    if (isDocPosted) {
                        document.getElementById('actionUnposted').style.display = 'none';
                        document.getElementById('actionPosted').style.display = 'flex';
                        document.getElementById('badgePostedInfo').innerHTML = `<i class="fa-solid fa-check"></i> Sudah Masuk IT Inv (Tgl: ${escapeHtml(result.posted_info.tgl_proses || '-')} | Dok: ${escapeHtml(result.posted_info.dok || '-')})`;
                    } else {
                        document.getElementById('actionUnposted').style.display = 'flex';
                        document.getElementById('actionPosted').style.display = 'none';
                    }

                    renderBarangTable();
                } else {
                    document.getElementById('dtlTbodyBarang').innerHTML = `<tr><td colspan="11" style="text-align:center; color:var(--danger);">${escapeHtml(result.message)}</td></tr>`;
                }
            } catch (err) {
                document.getElementById('dtlTbodyBarang').innerHTML = `<tr><td colspan="11" style="text-align:center; color:var(--danger);">Gagal memuat barang: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        // 3. Render Barang Table (with QTY_AKTUAL, SESUAI, MEMO)
        function renderBarangTable() {
            const tbody = document.getElementById('dtlTbodyBarang');
            if (!activeItems || activeItems.length === 0) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:16px; color:var(--text-muted);">Dokumen ini tidak memiliki rincian barang.</td></tr>`;
                return;
            }

            let html = '';
            let totQtyDok = 0;
            let totQtyAkt = 0;
            let selisihCount = 0;
            let totCif = 0;

            activeItems.forEach((b, idx) => {
                const seri = b.seri_barang;
                const qtyDok = parseFloat(b.qty_dokumen || b.jumlah_satuan || 0);
                const qtyAkt = parseFloat(b.qty_aktual !== undefined ? b.qty_aktual : qtyDok);
                const isSesuai = (b.sesuai === 'Y');
                const memo = b.memo || '';
                const diff = (qtyAkt - qtyDok);
                const hasDiff = Math.abs(diff) > 0.0001;

                totQtyDok += qtyDok;
                totQtyAkt += qtyAkt;
                totCif += parseFloat(b.cif || 0);
                if (hasDiff) selisihCount++;

                let diffBadge = '<span style="color:var(--success); font-weight:600;">Sesuai</span>';
                if (hasDiff) {
                    const diffSign = diff > 0 ? `+${diff.toFixed(2)}` : `${diff.toFixed(2)}`;
                    diffBadge = `<span class="ant-tag ant-tag-red" style="font-weight:700;">${diffSign}</span>`;
                }

                const memoRequiredClass = (hasDiff && !memo.trim()) ? 'required-empty' : '';
                const disabledAttr = isDocPosted ? 'disabled' : '';

                html += `
                    <tr>
                        <td style="text-align:center; color:var(--text-muted);">${seri}</td>
                        <td><strong style="color:var(--primary); font-family:monospace;">${escapeHtml(b.kode_barang)}</strong></td>
                        <td title="${escapeHtml(b.uraian)}">${escapeHtml(b.uraian)}</td>
                        <td style="text-align:center;"><span class="ant-tag">${escapeHtml(b.kode_satuan)}</span></td>
                        <td style="text-align:right; font-weight:700;">${qtyDok.toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                        <td style="text-align:center;">
                            <input type="checkbox" id="chk_sesuai_${seri}" ${isSesuai ? 'checked' : ''} ${disabledAttr} onchange="toggleSesuai(${seri}, this.checked)">
                        </td>
                        <td style="text-align:right;">
                            <input type="number" step="any" class="grid-input-num ${hasDiff ? 'diff' : ''}" id="input_qty_${seri}" value="${qtyAkt}" ${disabledAttr} oninput="onQtyAktualChange(${seri}, this.value)">
                        </td>
                        <td style="text-align:center;">${diffBadge}</td>
                        <td>
                            <input type="text" class="grid-input-text ${memoRequiredClass}" id="input_memo_${seri}" value="${escapeHtml(memo)}" placeholder="${hasDiff ? 'Wajib isi alasan selisih fisik...' : 'Catatan fisik (opsional)...'}" ${disabledAttr} oninput="onMemoChange(${seri}, this.value)">
                        </td>
                        <td><span class="ant-tag ant-tag-blue">${escapeHtml(b.klas || 'Raw Materials')}</span></td>
                        <td style="text-align:right;">${parseFloat(b.cif || 0).toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;

            document.getElementById('sumQtyDok').textContent = totQtyDok.toLocaleString(undefined, {minimumFractionDigits:2});
            document.getElementById('sumQtyAkt').textContent = totQtyAkt.toLocaleString(undefined, {minimumFractionDigits:2});
            document.getElementById('sumCif').textContent = totCif.toLocaleString(undefined, {minimumFractionDigits:2});

            const selisihEl = document.getElementById('sumSelisih');
            if (selisihCount === 0) {
                selisihEl.style.color = 'var(--success)';
                selisihEl.innerHTML = `<i class="fa-solid fa-circle-check"></i> 0 (Semua Sesuai)`;
            } else {
                selisihEl.style.color = 'var(--danger)';
                selisihEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> ${selisihCount} Item Selisih Fisik!`;
            }
        }

        // Live item state changes
        function toggleSesuai(seri, isChecked) {
            const item = activeItems.find(i => parseInt(i.seri_barang) === seri);
            if (!item) return;

            item.sesuai = isChecked ? 'Y' : 'N';
            if (isChecked) {
                item.qty_aktual = item.qty_dokumen || item.jumlah_satuan;
                item.memo = '';
            }
            renderBarangTable();
        }

        function onQtyAktualChange(seri, val) {
            const item = activeItems.find(i => parseInt(i.seri_barang) === seri);
            if (!item) return;

            const newQty = parseFloat(val) || 0;
            item.qty_aktual = newQty;
            const qtyDok = parseFloat(item.qty_dokumen || item.jumlah_satuan || 0);

            if (Math.abs(newQty - qtyDok) < 0.0001) {
                item.sesuai = 'Y';
            } else {
                item.sesuai = 'N';
            }
            renderBarangTable();
        }

        function onMemoChange(seri, val) {
            const item = activeItems.find(i => parseInt(i.seri_barang) === seri);
            if (!item) return;
            item.memo = val;
            const input = document.getElementById(`input_memo_${seri}`);
            if (input) {
                const qtyDok = parseFloat(item.qty_dokumen || item.jumlah_satuan || 0);
                const hasDiff = Math.abs(parseFloat(item.qty_aktual) - qtyDok) > 0.0001;
                if (hasDiff && !val.trim()) {
                    input.classList.add('required-empty');
                } else {
                    input.classList.remove('required-empty');
                }
            }
        }

        function checkAllSesuai() {
            activeItems.forEach(item => {
                item.sesuai = 'Y';
                item.qty_aktual = parseFloat(item.qty_dokumen || item.jumlah_satuan || 0);
                item.memo = '';
            });
            renderBarangTable();
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Semua item barang ditandai Sesuai.',
                showConfirmButton: false,
                timer: 1500
            });
        }

        // 4. Konfirmasi Penerimaan Dialog & Posting
        async function openConfirmModal() {
            if (!activeAju || !activeDocData) {
                Swal.fire('Peringatan', 'Silakan pilih dokumen terlebih dahulu.', 'warning');
                return;
            }

            // Validasi: Cek apakah ada item beda yang kolom memo-nya kosong
            let invalidItem = null;
            activeItems.forEach(it => {
                const qtyDok = parseFloat(it.qty_dokumen || it.jumlah_satuan || 0);
                const qtyAkt = parseFloat(it.qty_aktual || 0);
                if (Math.abs(qtyAkt - qtyDok) > 0.0001 && (!it.memo || !it.memo.trim())) {
                    invalidItem = it;
                }
            });

            if (invalidItem) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Memo Wajib Diisi!',
                    html: `Baris <b>#${invalidItem.seri_barang}</b> (Kode: <code>${escapeHtml(invalidItem.kode_barang)}</code>) memiliki selisih antara Qty Aktual (${invalidItem.qty_aktual}) vs Dokumen (${invalidItem.jumlah_satuan}), namun kolom <b>MEMO</b> masih kosong.<br><br>Silakan isi alasan selisih fisik terlebih dahulu!`
                });
                return;
            }

            const today = new Date().toISOString().split('T')[0];
            const defaultDok = activeDocData.suggested_dok || '';

            const { value: formValues } = await Swal.fire({
                title: '<span style="font-size:15px; font-weight:700;"><i class="fa-solid fa-boxes-packing" style="color:#52c41a;"></i> Konfirmasi Penerimaan Barang Masuk (IT Inventory 1A)</span>',
                html: `
                    <div style="font-size:11px; text-align:left; background:var(--bg-filter); border:1px solid var(--border-color); padding:10px; border-radius:4px; margin-bottom:12px;">
                        <div>Nomor AJU: <strong style="font-family:monospace;">${escapeHtml(activeAju)}</strong></div>
                        <div>Supplier: <strong>${escapeHtml(activeDocData.supplier || '-')}</strong></div>
                        <div>No. & Tgl Daftar: <strong>${escapeHtml(activeDocData.header?.nomor_daftar || '-')} (${escapeHtml(activeDocData.header?.tanggal_daftar || '-')})</strong></div>
                        <div style="margin-top:4px; color:var(--text-muted);">Total Item: <b>${activeItems.length}</b> &bull; Total Qty Fisik: <b>${document.getElementById('sumQtyAkt').textContent}</b></div>
                    </div>
                    <div style="text-align:left; display:flex; flex-direction:column; gap:10px; font-size:11px;">
                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:3px;">
                                <i class="fa-regular fa-calendar-check" style="color:var(--primary);"></i> Tanggal Aktual Masuk Warehouse (TANGGAL_PROSES) *
                            </label>
                            <input type="date" id="swal_tgl_proses" class="filter-control" style="width:100%; height:28px;" value="${today}">
                            <small style="color:var(--text-muted); font-size:9.5px;">Tanggal fisik barang diterima di gudang (kolom TGL_PROSES di bc_1ab2).</small>
                        </div>
                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:3px;">
                                <i class="fa-solid fa-file-lines" style="color:var(--primary);"></i> Nomor BUKTI Pemasukan / Surat Jalan Gudang (DOK) *
                            </label>
                            <input type="text" id="swal_dok" class="filter-control" style="width:100%; height:28px;" value="${escapeHtml(defaultDok)}" placeholder="Contoh: SJ-2026-10-09, GRN-1002, BAST-12">
                            <small style="color:var(--text-muted); font-size:9.5px;">Nomor bukti penerimaan fisik gudang (kolom DOK di bc_1ab2).</small>
                        </div>
                    </div>
                `,
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '<i class="fa-solid fa-check"></i> Simpan & Posting ke IT Inventory',
                cancelButtonText: 'Batal',
                preConfirm: () => {
                    const tgl = document.getElementById('swal_tgl_proses').value;
                    const dok = document.getElementById('swal_dok').value.trim();
                    if (!tgl) {
                        Swal.showValidationMessage('Tanggal Aktual Masuk wajib diisi!');
                        return false;
                    }
                    if (!dok) {
                        Swal.showValidationMessage('Nomor Bukti Pemasukan (DOK) wajib diisi!');
                        return false;
                    }
                    return { tgl_proses: tgl, dok: dok };
                }
            });

            if (!formValues) return;

            // Eksekusi POST
            Swal.fire({
                title: 'Memproses Posting IT Inventory',
                html: 'Menyimpan rincian fisik barang ke tabel IT Inventory (bc_1ab2)...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const payload = new URLSearchParams();
                payload.append('action', 'confirm_post');
                payload.append('nomor_aju', activeAju);
                payload.append('tanggal_proses', formValues.tgl_proses);
                payload.append('dok', formValues.dok);
                payload.append('items', JSON.stringify(activeItems));

                const res = await fetch('warehouse_incoming.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload.toString()
                });
                const result = await res.json();

                if (result.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Penerimaan Berhasil Dikonfirmasi!',
                        text: result.message,
                        timer: 2500,
                        showConfirmButton: false
                    });
                    loadList();
                    selectAju(activeAju);
                } else {
                    Swal.fire('Gagal Posting', result.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        // 5. Batalkan Posting (Unposting)
        async function unpostItInventory() {
            const confirm = await Swal.fire({
                title: 'Batalkan Posting IT Inventory?',
                html: `Apakah Anda yakin ingin membatalkan posting IT Inventory untuk nomor AJU:<br><b>${escapeHtml(activeAju)}</b>?<br><br><span style="color:var(--danger); font-size:11px;">Data barang akan dihapus dari bc_1ab2 sehingga dapat diverifikasi ulang.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#fa8c16',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Batalkan Posting',
                cancelButtonText: 'Batal'
            });

            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Membatalkan Posting...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const payload = new URLSearchParams();
                payload.append('action', 'unpost_it_inv');
                payload.append('nomor_aju', activeAju);

                const res = await fetch('warehouse_incoming.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload.toString()
                });
                const result = await res.json();

                if (result.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Unposting Berhasil',
                        text: result.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    loadList();
                    selectAju(activeAju);
                } else {
                    Swal.fire('Gagal', result.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error', err.message, 'error');
            }
        }

        // 6. Detail Tabs Switcher
        function switchDetailTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(b => {
                if (b.dataset.tab === tab) b.classList.add('active');
                else b.classList.remove('active');
            });
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.getElementById(`panel-${tab}`).classList.add('active');

            if (tab === 'dokumen' || tab === 'status' || tab === 'respon') {
                loadSubTab(tab);
            }
        }

        async function loadSubTab(tab) {
            if (!activeAju) return;
            const targetTbody = document.getElementById(`dtlTbody${tab.charAt(0).toUpperCase() + tab.slice(1)}`);
            targetTbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data ${tab}...</td></tr>`;

            try {
                const res = await fetch(`warehouse_incoming.php?action=detail_tab&nomor_aju=${encodeURIComponent(activeAju)}&tab=${encodeURIComponent(tab)}`);
                const result = await res.json();
                if (result.status === 'OK') {
                    const data = result.data || [];
                    if (data.length === 0) {
                        targetTbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada data ${tab}.</td></tr>`;
                        return;
                    }
                    let html = '';
                    if (tab === 'dokumen') {
                        data.forEach((r, idx) => {
                            html += `<tr>
                                <td style="text-align:center; font-weight:600; color:var(--text-muted);">${idx + 1}</td>
                                <td style="text-align:center;"><span class="ant-tag" style="font-family:monospace; font-weight:700;">${escapeHtml(r.kode_dokumen)}</span></td>
                                <td><strong style="color:var(--text-heading); font-size:11px;">${escapeHtml(r.nama_dokumen || '-')}</strong></td>
                                <td><strong style="font-family:monospace; font-size:11.5px;">${escapeHtml(r.nomor_dokumen || '-')}</strong></td>
                                <td style="text-align:center; font-size:11px;">${escapeHtml(r.tanggal_dokumen || '-')}</td>
                            </tr>`;
                        });
                    } else if (tab === 'status') {
                        data.forEach(r => {
                            html += `<tr><td><code>${escapeHtml(r.waktu_status)}</code></td><td><span class="ant-tag">${escapeHtml(r.kode_proses)}</span></td><td><strong>${escapeHtml(r.keterangan)}</strong></td><td>${escapeHtml(r.nomor_daftar)}</td></tr>`;
                        });
                    } else if (tab === 'respon') {
                        data.forEach(r => {
                            let pdfBtn = '-';
                            if (r.pdf) pdfBtn = `<a href="pdf.php?aju=${encodeURIComponent(activeAju)}&pdf=${encodeURIComponent(r.pdf)}" target="_blank" class="ant-btn ant-btn-sm" style="color:var(--primary);"><i class="fa-solid fa-file-pdf"></i> Unduh</a>`;
                            html += `<tr><td>${escapeHtml(r.tanggal_respon)}</td><td><span class="ant-tag ant-tag-blue">${escapeHtml(r.kode_respon)}</span></td><td><strong>${escapeHtml(r.nomor_respon)}</strong></td><td>${escapeHtml(r.keterangan)}</td><td style="text-align:center;">${pdfBtn}</td></tr>`;
                        });
                    }
                    targetTbody.innerHTML = html;
                }
            } catch (e) {
                targetTbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        // Resizable Splitbar Logic
        (function initSplitbar() {
            let isDragging = false;
            const splitbar = document.getElementById('splitbar');
            const topPane = document.getElementById('topPane');
            const container = document.getElementById('splitContainer');

            const savedH = localStorage.getItem('ceisa_wh_in_top_h');
            if (savedH && topPane) {
                const parsed = parseInt(savedH, 10);
                if (parsed >= 160 && parsed <= window.innerHeight - 160) {
                    topPane.style.flex = `0 0 ${parsed}px`;
                }
            }
            if (!splitbar) return;

            splitbar.addEventListener('mousedown', function(e) {
                isDragging = true;
                splitbar.classList.add('dragging');
                document.body.style.userSelect = 'none';
                document.body.style.cursor = 'row-resize';
            });
            window.addEventListener('mousemove', function(e) {
                if (!isDragging) return;
                const containerRect = container.getBoundingClientRect();
                let newH = e.clientY - containerRect.top;
                if (newH < 160) newH = 160;
                if (newH > containerRect.height - 140) newH = containerRect.height - 140;
                topPane.style.flex = `0 0 ${newH}px`;
                localStorage.setItem('ceisa_wh_in_top_h', newH);
            });
            window.addEventListener('mouseup', function() {
                if (isDragging) {
                    isDragging = false;
                    splitbar.classList.remove('dragging');
                    document.body.style.userSelect = '';
                    document.body.style.cursor = '';
                }
            });
        })();

        // -------------------------------------------------------------
        // MAXIMIZE / RESTORE PANE LOGIC
        // -------------------------------------------------------------
        let currentMaximizedPane = null; // null | 'top' | 'bottom'

        function toggleMaximizePane(pane) {
            const container = document.getElementById('splitContainer');
            const topPane = document.getElementById('topPane');
            const bottomPane = document.getElementById('bottomPane');
            const splitbar = document.getElementById('splitbar');
            const btnTop = document.getElementById('btnMaxTop');
            const btnBottom = document.getElementById('btnMaxBottom');
            const iconTop = document.getElementById('iconMaxTop');
            const textTop = document.getElementById('textMaxTop');
            const iconBottom = document.getElementById('iconMaxBottom');
            const textBottom = document.getElementById('textMaxBottom');

            if (currentMaximizedPane === pane) {
                // Restore split layout
                currentMaximizedPane = null;
                container.classList.remove('maximized-top', 'maximized-bottom');
                topPane.style.display = '';
                bottomPane.style.display = '';
                splitbar.style.display = '';

                const savedH = localStorage.getItem('ceisa_wh_in_top_h');
                if (savedH && parseInt(savedH, 10) >= 160) {
                    topPane.style.flex = `0 0 ${savedH}px`;
                } else {
                    topPane.style.flex = '0 0 52%';
                }
                bottomPane.style.flex = '1';

                if (btnTop) {
                    btnTop.classList.remove('ant-btn-primary');
                    btnTop.title = 'Perbesar / Maximize Grid Dokumen (Layar Penuh)';
                    if (iconTop) iconTop.className = 'fa-solid fa-expand';
                    if (textTop) textTop.textContent = 'Maximize';
                }
                if (btnBottom) {
                    btnBottom.classList.remove('ant-btn-primary');
                    btnBottom.title = 'Perbesar / Maximize Grid Detail (Layar Penuh)';
                    if (iconBottom) iconBottom.className = 'fa-solid fa-expand';
                    if (textBottom) textBottom.textContent = 'Maximize Detail';
                }
            } else {
                // Maximize target pane
                currentMaximizedPane = pane;
                if (pane === 'top') {
                    container.classList.remove('maximized-bottom');
                    container.classList.add('maximized-top');
                    topPane.style.display = 'flex';
                    topPane.style.flex = '1 1 100%';
                    topPane.style.height = '100%';
                    bottomPane.style.display = 'none';
                    splitbar.style.display = 'none';

                    if (btnTop) {
                        btnTop.classList.add('ant-btn-primary');
                        btnTop.title = 'Kembalikan Ukuran Normal Grid Dokumen (Restore / Esc)';
                        if (iconTop) iconTop.className = 'fa-solid fa-compress';
                        if (textTop) textTop.textContent = 'Restore';
                    }
                    if (btnBottom) {
                        btnBottom.classList.remove('ant-btn-primary');
                        if (iconBottom) iconBottom.className = 'fa-solid fa-expand';
                        if (textBottom) textBottom.textContent = 'Maximize Detail';
                    }
                } else if (pane === 'bottom') {
                    container.classList.remove('maximized-top');
                    container.classList.add('maximized-bottom');
                    topPane.style.display = 'none';
                    splitbar.style.display = 'none';
                    bottomPane.style.display = 'flex';
                    bottomPane.style.flex = '1 1 100%';
                    bottomPane.style.height = '100%';

                    if (btnBottom) {
                        btnBottom.classList.add('ant-btn-primary');
                        btnBottom.title = 'Kembalikan Ukuran Normal Grid Detail (Restore / Esc)';
                        if (iconBottom) iconBottom.className = 'fa-solid fa-compress';
                        if (textBottom) textBottom.textContent = 'Restore';
                    }
                    if (btnTop) {
                        btnTop.classList.remove('ant-btn-primary');
                        if (iconTop) iconTop.className = 'fa-solid fa-expand';
                        if (textTop) textTop.textContent = 'Maximize';
                    }
                }
            }

            // Trigger window resize to adjust internal scroll & table columns
            window.dispatchEvent(new Event('resize'));
        }

        // Double-click splitbar toggles maximize
        document.addEventListener('DOMContentLoaded', function() {
            const sb = document.getElementById('splitbar');
            if (sb) {
                sb.addEventListener('dblclick', function(e) {
                    if (e.target.closest('.split-btn-collapse')) return;
                    toggleMaximizePane('top');
                });
            }
        });

        // Esc key restores maximized view
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && currentMaximizedPane) {
                toggleMaximizePane(currentMaximizedPane);
            }
        });

        // Theme sync from parent
        window.addEventListener('message', function(e) {
            if (e.data && e.data.theme) {
                document.documentElement.setAttribute('data-theme', e.data.theme);
                document.body.setAttribute('data-theme', e.data.theme);
            }
        });

        // 7. Tarik / Sync Respon dari CEISA 4.0
        async function triggerSyncRespon() {
            const btn = document.getElementById('btnSyncRespon');
            const icon = document.getElementById('iconSyncRespon');
            const text = document.getElementById('textSyncRespon');

            if (btn) btn.disabled = true;
            if (icon) icon.className = 'fa-solid fa-spinner fa-spin';
            if (text) text.innerText = 'Menyinkronkan...';

            Swal.fire({
                title: 'Menarik Respon CEISA 4.0...',
                html: '<div style="font-size:12px; line-height:1.6; color:var(--text-main);">Menghubungi server Bea Cukai untuk memperbarui nomor daftar dan status respon pabean terbaru...</div>',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            try {
                const targetCmp = (new URLSearchParams(window.location.search)).get('cmp_id') || cmpId;
                const res = await fetch(`ceisa_sync_service.php?cmp_id=${encodeURIComponent(targetCmp)}&force=1`);
                const json = await res.json();

                Swal.close();

                if (json.status === 'OK') {
                    const insertedCount = json.inserted || json.count || 0;
                    Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Berhasil!',
                        html: `<span style="font-size:12.5px;">Respon CEISA 4.0 berhasil ditarik & diperbarui.</span><br><span style="font-size:11px; color:#8c8c8c;">${insertedCount} dokumen terupdate. Daftar dokumen akan otomatis disegarkan.</span>`,
                        timer: 2500,
                        showConfirmButton: false
                    });
                    loadList();
                    if (activeAju) {
                        const curTab = document.querySelector('.tab-btn.active')?.dataset.tab || 'dokumen';
                        if (curTab !== 'barang') loadSubTab(curTab);
                    }
                } else if (json.status === 'RATE_LIMITED') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Antrean Server Bea Cukai Padat',
                        text: json.message || 'Antrean padat, sinkronisasi dijeda sejenak.',
                        timer: 4000
                    });
                    loadList();
                } else if (json.status === 'SKIPPED') {
                    Swal.fire({
                        icon: 'info',
                        title: 'Sinkronisasi Dilewati',
                        text: json.message || 'Kredensial belum lengkap.'
                    });
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'Info CEISA 4.0',
                        text: json.message || 'Sinkronisasi selesai.'
                    });
                    loadList();
                }
            } catch (err) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Terhubung ke CEISA 4.0',
                    text: err.message
                });
            } finally {
                if (btn) btn.disabled = false;
                if (icon) icon.className = 'fa-solid fa-cloud-arrow-down';
                if (text) text.innerText = 'Tarik / Sync Respon';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadList();
        });
    </script>
</body>
</html>

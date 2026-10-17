<?php
/**
 * CEISA 4.0 - INHOST: Interface Host to Host & Data Host to Host (transfer.php)
 * Mengadopsi Layout, Menu "mn.srm", Quick Toolbar, Warna, Grid Excel, dan Fungsi Lengkap PowerBuilder
 * Sesuai screenshot menu mn.srm dan Data Host to Host CEISA 4.0
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/CeisaJsonBuilder.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? '');
$companyTitle = !empty($_SESSION['company_name']) ? $_SESSION['company_name'] : 'PT. SN UNGGUL';

// Ambil daftar profil perusahaan aktif untuk filter
$companies = [];
if (isset($koneksi) && $koneksi) {
    $resComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp FROM bc_ceisa_company ORDER BY cmp_name ASC");
    if ($resComp) {
        while ($r = $resComp->fetch_assoc()) {
            if ($r['cmp_id'] === $sessionCmpId) {
                $companyTitle = $r['cmp_name'];
            }
            $companies[] = $r;
        }
    }
}

$userAllowedDocs = $_SESSION['allowed_docs'] ?? ['*'];
if (!function_exists('isDocPermitted')) {
    function isDocPermitted($d) {
        global $isAdmin, $userAllowedDocs;
        if (!empty($isAdmin)) return true;
        if (empty($userAllowedDocs) || in_array('*', $userAllowedDocs) || in_array('ALL', $userAllowedDocs)) return true;
        return in_array((string)$d, $userAllowedDocs);
    }
}

// -------------------------------------------------------------
// AJAX API ENDPOINTS
// -------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

// 1. LIST DOKUMEN HEADER (MASTER DATAWINDOW)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $filter_cmp   = trim($_GET['cmp_id'] ?? '');
    $filter_bc    = trim($_GET['bc'] ?? '');
    $filter_stat  = trim($_GET['status'] ?? '');
    $search       = trim($_GET['q'] ?? '');
    $filter_per   = trim($_GET['periode'] ?? '');
    $filter_tgl1  = trim($_GET['tgl_awal'] ?? '');
    $filter_tgl2  = trim($_GET['tgl_akhir'] ?? '');

    if ((!$isAdmin || empty($filter_cmp)) && !empty($sessionCmpId)) {
        $filter_cmp = $sessionCmpId;
    }

    $whereKiri = [];
    $whereOuter = [];

    if (!empty($filter_cmp)) {
        $whereKiri[] = "a.cmp_id = '" . $koneksi->real_escape_string($filter_cmp) . "'";
    }
    if (!empty($filter_bc)) {
        $whereKiri[] = "a.kode_dokumen = '" . $koneksi->real_escape_string($filter_bc) . "'";
    }
    // Pastikan Data Host to Host hanya mengambil data staging murni (bukan stub dari sinkronisasi CEISA)
    $whereKiri[] = "(a.user_id != 'CEISA_PORTAL' OR a.user_id IS NULL OR a.user_id = '')";

    // Penegakan Izin Jenis Dokumen Bea Cukai Pengguna
    if (!$isAdmin && !in_array('*', $userAllowedDocs) && !in_array('ALL', $userAllowedDocs)) {
        if (!empty($userAllowedDocs)) {
            $escapedDocs = array_map(function($d) use ($koneksi) { return "'" . $koneksi->real_escape_string($d) . "'"; }, $userAllowedDocs);
            $whereKiri[] = "a.kode_dokumen IN (" . implode(',', $escapedDocs) . ")";
        } else {
            $whereKiri[] = "1=0";
        }
    }

    // Filter tanggal / periode
    if (!empty($filter_tgl1) && !empty($filter_tgl2)) {
        $t1 = $koneksi->real_escape_string($filter_tgl1);
        $t2 = $koneksi->real_escape_string($filter_tgl2);
        $whereKiri[] = "((a.tanggal_pernyataan >= '$t1' AND a.tanggal_pernyataan <= '$t2') OR (a.tanggal_daftar >= '$t1' AND a.tanggal_daftar <= '$t2'))";
    } elseif (!empty($filter_per)) {
        $per = $koneksi->real_escape_string($filter_per);
        $whereKiri[] = "(a.tanggal_pernyataan LIKE '$per%' OR a.tanggal_daftar LIKE '$per%')";
    }

    if ($filter_stat === 'posted') {
        $whereOuter[] = "posted.jml > 0";
    } elseif ($filter_stat === 'unposted') {
        $whereOuter[] = "(posted.jml IS NULL OR posted.jml = 0)";
    } elseif ($filter_stat === 'it_inv') {
        $whereOuter[] = "cekit.jml > 0";
    } elseif ($filter_stat === 'not_it_inv') {
        $whereOuter[] = "(cekit.jml IS NULL OR cekit.jml = 0) AND (kiri.nomor_daftar IS NOT NULL AND kiri.nomor_daftar != '' AND kiri.nomor_daftar != '-')";
    }

    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $whereOuter[] = "(kiri.nomor_aju LIKE '%$s%' OR kiri.bs LIKE '%$s%' OR kiri.id_bs LIKE '%$s%' OR kiri.nomor_daftar LIKE '%$s%' OR doc_inv.nomor_dokumen LIKE '%$s%')";
    }

    $whereKiriSql = !empty($whereKiri) ? 'WHERE ' . implode(' AND ', $whereKiri) : '';
    $whereOuterSql = !empty($whereOuter) ? 'WHERE ' . implode(' AND ', $whereOuter) : '';

    $sql = "SELECT
        kiri.*,
        IFNULL(jml_barang.jml, 0) AS jml_barang,
        IFNULL(posted.jml, 0) AS posted,
        IFNULL(posted.waktu, '') AS posted_waktu,
        IFNULL(posted.sppb, '') AS sppb,
        IFNULL(cekit.jml, 0) AS cekit,
        IFNULL(doc_inv.nomor_dokumen, '-') AS invoice,
        IFNULL(bcd.namaProses, '') AS namaProses,
        IFNULL(bcd.namaRespon, '') AS bcd_namaRespon,
        IFNULL(bcd.nomorRespon, '') AS bcd_nomorRespon,
        IFNULL(bcd.tanggalRespon, '') AS bcd_tanggalRespon,
        IFNULL(bcd.kodeJalur, '') AS bcd_kodeJalur,
        IFNULL(bcd.namaKantorPendek, '') AS bcd_namaKantorPendek,
        IFNULL(bcd.namaPerusahaan, '') AS bcd_namaPerusahaan,
        IFNULL(bcd.idPerusahaan, '') AS bcd_idPerusahaan
    FROM (
        SELECT
            a.kode_dokumen AS bc,
            a.nomor_aju,
            a.nomor_daftar,
            a.tanggal_daftar,
            IFNULL(c.nama_entitas, IFNULL(c_fb.nama_entitas, '-')) AS bs,
            IFNULL(c.nomor_identitas, IFNULL(c_fb.nomor_identitas, '')) AS id_bs,
            IFNULL(a.kode_valuta, 'USD') AS kode_valuta,
            IFNULL(a.bruto, 0) AS bruto,
            IFNULL(a.user_id, 'USER') AS user_id,
            a.upd_time, 
            a.tanggal_pernyataan,
            a.cmp_id,
            IFNULL(a.kode_kantor, '') AS kode_kantor,
            IFNULL(cmp.cmp_name, '') AS cmp_name,
            IFNULL(cmp.npwp, '') AS cmp_npwp,
            IFNULL(cmp.IS_INV, 1) AS is_inv
        FROM bc_ceisa_header a
        LEFT JOIN bc_ceisa_company cmp ON a.cmp_id = cmp.cmp_id
        LEFT JOIN bc_ceisa_bs_entitas b ON a.kode_dokumen = b.kode_dokumen
        LEFT JOIN bc_ceisa_entitas c    ON b.kode_entitas = c.kode_entitas AND a.nomor_aju = c.nomor_aju
        LEFT JOIN (
            SELECT nomor_aju, MIN(nama_entitas) AS nama_entitas, MIN(nomor_identitas) AS nomor_identitas
            FROM bc_ceisa_entitas
            WHERE kode_entitas IN ('5', '6', '8', '9', '7', '3')
            GROUP BY nomor_aju
        ) c_fb ON a.nomor_aju = c_fb.nomor_aju
        $whereKiriSql
    ) AS kiri
    LEFT JOIN (
        SELECT nomor_aju, COUNT(1) AS jml
        FROM bc_ceisa_barang
        GROUP BY nomor_aju
    ) jml_barang ON kiri.nomor_aju = jml_barang.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, COUNT(1) AS jml, MAX(waktu) AS waktu, MAX(sppb) AS sppb
        FROM bc_ceisa_aju_posted
        GROUP BY nomor_aju
    ) posted ON kiri.nomor_aju = posted.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, MIN(nomor_dokumen) AS nomor_dokumen
        FROM bc_ceisa_dokumen
        WHERE kode_dokumen = '380'
        GROUP BY nomor_aju
    ) doc_inv ON kiri.nomor_aju = doc_inv.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, COUNT(1) AS jml
        FROM bc_1ab2
        GROUP BY nomor_aju
    ) cekit ON kiri.nomor_aju = cekit.nomor_aju
    LEFT JOIN bc_ceisa_data bcd ON kiri.nomor_aju = bcd.nomorAju
    $whereOuterSql
    ORDER BY kiri.tanggal_pernyataan DESC, kiri.nomor_aju DESC";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }
    // Ambil flag IS_INV dan alert_it_inv_unposted perusahaan yang sedang aktif / difilter
    $activeCompanyIsInv = 1;
    $activeCompanyAlertItInv = 0;
    $targetCmpCheck = !empty($filter_cmp) ? $filter_cmp : $sessionCmpId;
    if (!empty($targetCmpCheck)) {
        $resCmp = $koneksi->query("SELECT IFNULL(IS_INV, 1) AS is_inv, IFNULL(alert_it_inv_unposted, 0) AS alert_it_inv FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($targetCmpCheck) . "' LIMIT 1");
        if ($resCmp && $rc = $resCmp->fetch_assoc()) {
            $activeCompanyIsInv = (int)$rc['is_inv'];
            $activeCompanyAlertItInv = (int)$rc['alert_it_inv'];
        }
    }
    echo json_encode(['status' => 'OK', 'count' => count($data), 'data' => $data, 'is_inv' => $activeCompanyIsInv, 'alert_it_inv' => $activeCompanyAlertItInv]);
    exit;
}

// 2. DETAIL TABS (BARANG, DOKUMEN, STATUS, RESPON)
if ($action === 'detail_tab') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_GET['nomor_aju'] ?? '');
    $tab       = trim($_GET['tab'] ?? 'barang');

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }

    $escaped = "'" . $koneksi->real_escape_string($nomor_aju) . "'";

    if ($tab === 'barang') {
        $sql = "SELECT 
            a.seri_barang, 
            a.kode_barang, 
            a.uraian, 
            IFNULL(a.jumlah_satuan, 0) AS jumlah_satuan, 
            IFNULL(a.kode_satuan, '') AS kode_satuan, 
            IFNULL((SELECT klas FROM bc_material m WHERE m.kode_barang = a.kode_barang LIMIT 1), 'Finished Goods') AS klas,
            0.00 AS stok,
            IFNULL(a.harga_penyerahan, 0) AS harga_penyerahan, 
            IFNULL(a.cif, 0) AS cif, 
            IFNULL(a.netto, 0) AS netto,
            1 AS cek
        FROM bc_ceisa_barang a
        WHERE a.nomor_aju = $escaped
        ORDER BY a.seri_barang ASC";

        $res = $koneksi->query($sql);
        $rows = [];
        $totJml = 0; $totPenyerahan = 0; $totCif = 0; $totNetto = 0;
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $totJml += floatval($r['jumlah_satuan']);
                $totPenyerahan += floatval($r['harga_penyerahan']);
                $totCif += floatval($r['cif']);
                $totNetto += floatval($r['netto']);
                $rows[] = $r;
            }
        }
        echo json_encode([
            'status' => 'OK',
            'tab' => 'barang',
            'count' => count($rows),
            'totals' => [
                'jumlah' => $totJml,
                'harga_penyerahan' => $totPenyerahan,
                'cif' => $totCif,
                'netto' => $totNetto
            ],
            'data' => $rows
        ]);
        exit;
    }

    if ($tab === 'dokumen') {
        $refMap = [
            '380' => 'Invoice',
            '217' => 'Packing List',
            '705' => 'Bill of Lading (B/L)',
            '740' => 'Air Waybill (AWB)',
            '230' => 'Surat Jalan',
            '381' => 'Commercial Invoice',
            '860' => 'SPPB (Surat Persetujuan Pengeluaran Barang)',
            '861' => 'Dokumen BC Lainnya',
            '911' => 'SKEP Fasilitas',
            '999' => 'Dokumen Lainnya'
        ];

        $sql = "SELECT seri, kode_dokumen, nomor_dokumen, tanggal_dokumen 
                FROM bc_ceisa_dokumen 
                WHERE nomor_aju = $escaped 
                ORDER BY seri ASC";
        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $kd = trim($r['kode_dokumen']);
                $r['nama_dokumen'] = $refMap[$kd] ?? ($kd ? "Dokumen $kd" : '-');
                $rows[] = $r;
            }
        }
        echo json_encode(['status' => 'OK', 'tab' => 'dokumen', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    if ($tab === 'status') {
        $sql = "SELECT kode_dokumen, nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan 
                FROM bc_ceisa_status 
                WHERE nomor_aju = $escaped 
                ORDER BY waktu_status DESC";
        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        echo json_encode(['status' => 'OK', 'tab' => 'status', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    if ($tab === 'respon') {
        $sql = "SELECT nomor_aju, nomor_daftar, tanggal_daftar, kode_respon, nomor_respon, tanggal_respon, keterangan AS pesan 
                FROM bc_ceisa_respons 
                WHERE nomor_aju = $escaped 
                ORDER BY id DESC";
        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        echo json_encode(['status' => 'OK', 'tab' => 'respon', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    echo json_encode(['status' => 'ERROR', 'message' => 'Tab tidak dikenal.']);
    exit;
}

// 3. LAPORAN IT INVENTORY (1A, 1B, 1C, 1D, LOG PERUBAHAN)
if ($action === 'report_itinv') {
    header('Content-Type: application/json; charset=utf-8');
    $type = trim($_GET['type'] ?? '1A');
    $cmp_id = trim($_GET['cmp_id'] ?? $sessionCmpId);

    $escCmp = $koneksi->real_escape_string($cmp_id);
    $cmpFilter = !empty($escCmp) ? "AND h.cmp_id = '$escCmp'" : "";

    if ($type === '1A') {
        // Laporan Pemasukan (BC 2.0, BC 2.3, BC 4.0, BC 1.6, BC 2.7 Masuk)
        $sql = "SELECT 
            h.kode_dokumen AS jenis_dok,
            IFNULL(h.nomor_daftar, '-') AS no_dok,
            IFNULL(h.tanggal_daftar, h.tanggal_pernyataan) AS tgl_dok,
            h.nomor_aju,
            IFNULL(doc_sj.nomor_dokumen, doc_inv.nomor_dokumen) AS no_bukti,
            IFNULL(doc_sj.tanggal_dokumen, h.tanggal_pernyataan) AS tgl_bukti,
            IFNULL(c.nama_entitas, '-') AS pemasok,
            b.kode_barang,
            b.uraian,
            IFNULL(b.jumlah_satuan, 0) AS jumlah,
            IFNULL(b.kode_satuan, 'PCE') AS satuan,
            IFNULL(b.cif, 0) AS nilai_cif,
            IFNULL(h.kode_valuta, 'USD') AS valuta
        FROM bc_ceisa_header h
        JOIN bc_ceisa_barang b ON h.nomor_aju = b.nomor_aju
        LEFT JOIN (
            SELECT nomor_aju, MIN(nama_entitas) AS nama_entitas
            FROM bc_ceisa_entitas WHERE kode_entitas IN ('5', '9', '7', '1') GROUP BY nomor_aju
        ) c ON h.nomor_aju = c.nomor_aju
        LEFT JOIN (
            SELECT nomor_aju, MIN(nomor_dokumen) AS nomor_dokumen, MIN(tanggal_dokumen) AS tanggal_dokumen
            FROM bc_ceisa_dokumen WHERE kode_dokumen = '230' GROUP BY nomor_aju
        ) doc_sj ON h.nomor_aju = doc_sj.nomor_aju
        LEFT JOIN (
            SELECT nomor_aju, MIN(nomor_dokumen) AS nomor_dokumen, MIN(tanggal_dokumen) AS tanggal_dokumen
            FROM bc_ceisa_dokumen WHERE kode_dokumen = '380' GROUP BY nomor_aju
        ) doc_inv ON h.nomor_aju = doc_inv.nomor_aju
        WHERE h.kode_dokumen IN ('20', '23', '40', '16', '27', '331') $cmpFilter
        ORDER BY h.tanggal_pernyataan DESC, h.nomor_aju DESC LIMIT 100";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'type' => '1A', 'title' => '1A - Laporan Pemasukan Barang per Dokumen Pabean', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    if ($type === '1B') {
        // Laporan Pengeluaran (BC 3.0, BC 2.5, BC 4.1, BC 2.7 Keluar)
        $sql = "SELECT 
            h.kode_dokumen AS jenis_dok,
            IFNULL(h.nomor_daftar, '-') AS no_dok,
            IFNULL(h.tanggal_daftar, h.tanggal_pernyataan) AS tgl_dok,
            h.nomor_aju,
            IFNULL(doc_inv.nomor_dokumen, '-') AS no_bukti,
            IFNULL(doc_inv.tanggal_dokumen, h.tanggal_pernyataan) AS tgl_bukti,
            IFNULL(c.nama_entitas, '-') AS penerima,
            b.kode_barang,
            b.uraian,
            IFNULL(b.jumlah_satuan, 0) AS jumlah,
            IFNULL(b.kode_satuan, 'PCE') AS satuan,
            IFNULL(b.harga_penyerahan, b.cif) AS nilai,
            IFNULL(h.kode_valuta, 'USD') AS valuta
        FROM bc_ceisa_header h
        JOIN bc_ceisa_barang b ON h.nomor_aju = b.nomor_aju
        LEFT JOIN (
            SELECT nomor_aju, MIN(nama_entitas) AS nama_entitas
            FROM bc_ceisa_entitas WHERE kode_entitas IN ('6', '8', '2', '3') GROUP BY nomor_aju
        ) c ON h.nomor_aju = c.nomor_aju
        LEFT JOIN (
            SELECT nomor_aju, MIN(nomor_dokumen) AS nomor_dokumen, MIN(tanggal_dokumen) AS tanggal_dokumen
            FROM bc_ceisa_dokumen WHERE kode_dokumen = '380' GROUP BY nomor_aju
        ) doc_inv ON h.nomor_aju = doc_inv.nomor_aju
        WHERE h.kode_dokumen IN ('30', '25', '41', '261', '27') $cmpFilter
        ORDER BY h.tanggal_pernyataan DESC, h.nomor_aju DESC LIMIT 100";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'type' => '1B', 'title' => '1B - Laporan Pengeluaran Barang per Dokumen Pabean', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    if ($type === '1C') {
        // Laporan WIP
        $sql = "SELECT 
            b.kode_barang,
            b.uraian,
            IFNULL(b.kode_satuan, 'PCE') AS satuan,
            IFNULL(SUM(b.jumlah_satuan), 0) AS jumlah_wip,
            'Proses Produksi' AS keterangan
        FROM bc_ceisa_barang b
        JOIN bc_ceisa_header h ON b.nomor_aju = h.nomor_aju
        WHERE 1=1 $cmpFilter
        GROUP BY b.kode_barang, b.uraian, b.kode_satuan LIMIT 100";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'type' => '1C', 'title' => '1C - Laporan Posisi Barang dalam Proses (WIP)', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    if ($type === '1D') {
        // Laporan Mutasi Barang
        $sql = "SELECT 
            b.kode_barang,
            b.uraian,
            IFNULL(b.kode_satuan, 'PCE') AS satuan,
            0.00 AS saldo_awal,
            IFNULL(SUM(CASE WHEN h.kode_dokumen IN ('20', '23', '40', '16', '331') THEN b.jumlah_satuan ELSE 0 END), 0) AS pemasukan,
            IFNULL(SUM(CASE WHEN h.kode_dokumen IN ('30', '25', '41', '261') THEN b.jumlah_satuan ELSE 0 END), 0) AS pengeluaran,
            0.00 AS penyesuaian,
            IFNULL(SUM(CASE WHEN h.kode_dokumen IN ('20', '23', '40', '16', '331') THEN b.jumlah_satuan ELSE -b.jumlah_satuan END), 0) AS saldo_buku,
            IFNULL(SUM(CASE WHEN h.kode_dokumen IN ('20', '23', '40', '16', '331') THEN b.jumlah_satuan ELSE -b.jumlah_satuan END), 0) AS stock_opname,
            0.00 AS selisih,
            'Sesuai' AS keterangan
        FROM bc_ceisa_barang b
        JOIN bc_ceisa_header h ON b.nomor_aju = h.nomor_aju
        WHERE 1=1 $cmpFilter
        GROUP BY b.kode_barang, b.uraian, b.kode_satuan LIMIT 100";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'type' => '1D', 'title' => '1D - Laporan Pertanggungjawaban Mutasi Barang', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    if ($type === 'log') {
        // Log Perubahan Data / Posting
        $sql = "SELECT 
            p.nomor_aju,
            p.bc,
            p.waktu,
            IFNULL(h.tanggal_pernyataan, '') AS tgl_aju,
            IFNULL(h.nomor_daftar, '') AS nomor_daftar,
            IFNULL(h.tanggal_daftar, '') AS tanggal_daftar,
            IFNULL(p.sppb, '-') AS sppb,
            'Posted IT Inventory' AS aksi,
            IFNULL(h.user_id, 'USER') AS user_id
        FROM bc_ceisa_aju_posted p
        LEFT JOIN bc_ceisa_header h ON p.nomor_aju = h.nomor_aju
        ORDER BY p.waktu DESC LIMIT 100";

        $res = $koneksi->query($sql);
        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
        echo json_encode(['status' => 'OK', 'type' => 'log', 'title' => 'Log Perubahan & Riwayat Posting IT Inventory', 'count' => count($rows), 'data' => $rows]);
        exit;
    }

    echo json_encode(['status' => 'ERROR', 'message' => 'Laporan tidak ditemukan.']);
    exit;
}

// 4. POSTING / UNPOSTING IT INVENTORY & SET H2H
if ($action === 'post_it') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih dokumen terlebih dahulu.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);

    // Cek apakah sudah ada di bc_1ab2
    $chk = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '$escAju'")->fetch_row()[0] ?? 0;
    if ($chk > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju sudah masuk ke IT Inventory!"]);
        exit;
    }

    // Ambil data header
    $hRes = $koneksi->query("SELECT * FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    if (!$hRes || $hRes->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju tidak ditemukan di database."]);
        exit;
    }
    $h = $hRes->fetch_assoc();
    $kodeBc = trim($h['kode_dokumen'] ?? '');
    $noDaftar = trim($h['nomor_daftar'] ?? '');
    $tglDaftar = trim($h['tanggal_daftar'] ?? '');

    // Validasi Mutlak: Nomor Daftar dan Tanggal Daftar TIDAK BOLEH KOSONG!
    if (empty($noDaftar) || $noDaftar === '-' || empty($tglDaftar) || $tglDaftar === '-' || strpos($tglDaftar, '0000-00-00') === 0) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => "Posting IT Inventory Gagal! Dokumen AJU $nomor_aju belum memiliki Nomor Daftar atau Tanggal Daftar. Nomor Daftar dan Tanggal Daftar WAJIB terisi sebelum dapat diposting ke IT Inventory. Silakan gunakan menu 'Set Manual Respon' untuk melengkapi data terlebih dahulu."
        ]);
        exit;
    }

    // Tentukan arah AB
    // A: Inbound (20, 23, 40, 16, 262, 28, 611, 27IN)
    // B: Outbound (25, 261, 27, 30, 41, 33, 331, 613)
    $inboundBcs = ['20', '23', '40', '16', '262', '28', '611', '27IN'];
    $ab = in_array($kodeBc, $inboundBcs) ? 'A' : 'B';
    $abLabel = ($ab === 'A') ? 'Laporan 1A - Pemasukan' : 'Laporan 1B - Pengeluaran';

    // Insert ke bc_1ab2 (Multi-tenancy dengan cmp_id)
    $sqlInsert = "INSERT INTO bc_1ab2 (
        cmp_id, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, SJ, INVOICE, FAKTUR, NOMOR_AJU, SERI_BARANG, HARGA_PENYERAHAN, CIF, NETTO, SESUAI, QTY_AKTUAL, TGL_PROSES, NDPBM
    )
    SELECT 
        IFNULL(h.cmp_id, '') AS cmp_id,
        h.kode_dokumen AS BC,
        h.nomor_daftar AS NOMOR_DAFTAR,
        h.tanggal_daftar AS TANGGAL_DAFTAR,
        IFNULL(d.nomor_dokumen, '-') AS NO_BUKTI,
        IFNULL(d.tanggal_dokumen, h.tanggal_pernyataan) AS TGL_BUKTI,
        IFNULL(e.nama_entitas, IFNULL(h.nama_pernyataan, 'PERUSAHAAN')) AS NAMA_BS,
        b.kode_barang,
        b.hs AS POS_TARIF,
        b.uraian,
        IFNULL(b.tipe, 'Raw Materials') AS KLAS,
        b.kode_satuan AS UNIT,
        b.jumlah_satuan AS QTY,
        IFNULL(h.kode_valuta, 'IDR') AS KURS,
        IFNULL(b.harga_penyerahan, IFNULL(b.cif, 0)) AS NILAI,
        '$ab' AS AB,
        h.nomor_aju AS ID_HEADER,
        '' AS SJ,
        IFNULL(d.nomor_dokumen, '') AS INVOICE,
        '' AS FAKTUR,
        h.nomor_aju AS NOMOR_AJU,
        b.seri_barang,
        IFNULL(b.harga_penyerahan, 0) AS HARGA_PENYERAHAN,
        IFNULL(b.cif, 0) AS CIF,
        IFNULL(b.netto, 0) AS NETTO,
        'Y' AS SESUAI,
        b.jumlah_satuan AS QTY_AKTUAL,
        h.tanggal_daftar AS TGL_PROSES,
        IFNULL(h.ndpbm, 1) AS NDPBM
    FROM bc_ceisa_header h
    JOIN bc_ceisa_barang b ON h.nomor_aju = b.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, MIN(nomor_dokumen) AS nomor_dokumen, MIN(tanggal_dokumen) AS tanggal_dokumen
        FROM bc_ceisa_dokumen WHERE kode_dokumen IN ('380','217','230','999') GROUP BY nomor_aju
    ) d ON h.nomor_aju = d.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, MIN(nama_entitas) AS nama_entitas
        FROM bc_ceisa_entitas GROUP BY nomor_aju
    ) e ON h.nomor_aju = e.nomor_aju
    WHERE h.nomor_aju = '$escAju'";

    $ok = $koneksi->query($sqlInsert);
    if ($ok) {
        $koneksi->query("INSERT INTO bc_ceisa_aju_posted (cmp_id, nomor_aju, waktu, bc, nomor_daftar, tanggal_daftar) 
                         VALUES ('{$h['cmp_id']}', '$escAju', NOW(), '$kodeBc', '$noDaftar', '$tglDaftar')
                         ON DUPLICATE KEY UPDATE nomor_daftar = VALUES(nomor_daftar), tanggal_daftar = VALUES(tanggal_daftar)");
        echo json_encode([
            'status' => 'OK', 
            'message' => "Dokumen AJU $nomor_aju berhasil diposting ke IT Inventory ($abLabel).",
            'is_it_inv' => 1
        ]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal posting: ' . $koneksi->error]);
    }
    exit;
}

// 4b. GET DATA MANUAL RESPON (Cek Header, Posted, bc_ceisa_data & History bc_ceisa_sppb)
if ($action === 'get_manual_respon') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_GET['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih dokumen terlebih dahulu.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);

    $hRes = $koneksi->query("SELECT nomor_aju, kode_dokumen, nomor_daftar, tanggal_daftar, cmp_id FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    if (!$hRes || $hRes->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju tidak ditemukan."]);
        exit;
    }
    $h = $hRes->fetch_assoc();

    // Data saat ini dari header & posted
    $curNoDaftar = trim($h['nomor_daftar'] ?? '');
    if ($curNoDaftar === '-') $curNoDaftar = '';
    $curTglDaftar = trim($h['tanggal_daftar'] ?? '');
    if ($curTglDaftar === '-' || strpos($curTglDaftar, '0000-00-00') === 0) $curTglDaftar = '';
    if (!empty($curTglDaftar) && strlen($curTglDaftar) >= 10) {
        $curTglDaftar = substr($curTglDaftar, 0, 10);
    }

    $curSppb = '';
    $qPost = $koneksi->query("SELECT sppb, nomor_daftar, tanggal_daftar FROM bc_ceisa_aju_posted WHERE nomor_aju = '$escAju' LIMIT 1");
    if ($qPost && $rPost = $qPost->fetch_assoc()) {
        $curSppb = trim($rPost['sppb'] ?? '');
        if (empty($curNoDaftar) && !empty($rPost['nomor_daftar']) && $rPost['nomor_daftar'] !== '-') {
            $curNoDaftar = trim($rPost['nomor_daftar']);
        }
        if (empty($curTglDaftar) && !empty($rPost['tanggal_daftar']) && $rPost['tanggal_daftar'] !== '-' && strpos($rPost['tanggal_daftar'], '0000-00-00') !== 0) {
            $curTglDaftar = substr(trim($rPost['tanggal_daftar']), 0, 10);
        }
    }

    // Kandidat dari bc_ceisa_sppb (History SPPB Sync CEISA)
    $candNoDaftar = '';
    $candTglDaftar = '';
    $candSppb = '';
    $candSource = '';

    $qSppb = $koneksi->query("SELECT nomorDaftar, tanggalDaftar, nomorRespon, namaRespon, tanggalRespon FROM bc_ceisa_sppb WHERE nomorAju = '$escAju' ORDER BY upd_time DESC LIMIT 1");
    if ($qSppb && $rSppb = $qSppb->fetch_assoc()) {
        if (!empty($rSppb['nomorDaftar']) && $rSppb['nomorDaftar'] !== '-') {
            $candNoDaftar = trim($rSppb['nomorDaftar']);
        }
        if (!empty($rSppb['tanggalDaftar']) && strpos((string)$rSppb['tanggalDaftar'], '0000-00-00') !== 0) {
            $candTglDaftar = substr(trim((string)$rSppb['tanggalDaftar']), 0, 10);
        }
        if (!empty($rSppb['nomorRespon'])) {
            $candSppb = trim($rSppb['nomorRespon']);
        }
        $candSource = 'bc_ceisa_sppb (History SPPB)';
    }

    // Kandidat dari bc_ceisa_data (Staging Respon CEISA)
    $qData = $koneksi->query("SELECT nomorDaftar, tanggalDaftar, nomorRespon, namaRespon, tanggalRespon FROM bc_ceisa_data WHERE nomorAju = '$escAju' ORDER BY idHeader DESC LIMIT 1");
    if ($qData && $rData = $qData->fetch_assoc()) {
        if (empty($candNoDaftar) && !empty($rData['nomorDaftar']) && $rData['nomorDaftar'] !== '-') {
            $candNoDaftar = trim($rData['nomorDaftar']);
        }
        if (empty($candTglDaftar) && !empty($rData['tanggalDaftar']) && strpos((string)$rData['tanggalDaftar'], '0000-00-00') !== 0) {
            $candTglDaftar = substr(trim((string)$rData['tanggalDaftar']), 0, 10);
        }
        if (empty($candSppb) && !empty($rData['nomorRespon']) && stripos($rData['namaRespon'] ?? '', 'SPPB') !== false) {
            $candSppb = trim($rData['nomorRespon']);
        }
        if (empty($candSource)) {
            $candSource = 'bc_ceisa_data (Staging)';
        } else {
            $candSource .= ' & bc_ceisa_data';
        }
    }

    // Cek juga dari bc_ceisa_respons jika ada respon nomor SPPB
    if (empty($candSppb)) {
        $qResp = $koneksi->query("SELECT nomor_respon, keterangan FROM bc_ceisa_respons WHERE nomor_aju = '$escAju' AND (keterangan LIKE '%SPPB%' OR kode_respon IN ('860','SPPB')) ORDER BY tanggal_respon DESC LIMIT 1");
        if ($qResp && $rResp = $qResp->fetch_assoc()) {
            if (!empty($rResp['nomor_respon'])) {
                $candSppb = trim($rResp['nomor_respon']);
                $candSource = !empty($candSource) ? ($candSource . ' & bc_ceisa_respons') : 'bc_ceisa_respons';
            }
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data' => [
            'nomor_aju'     => $h['nomor_aju'],
            'kode_dokumen'  => $h['kode_dokumen'],
            'nomor_daftar'  => $curNoDaftar,
            'tanggal_daftar'=> $curTglDaftar,
            'sppb'          => $curSppb,
            'candidate'     => [
                'has_candidate'  => (!empty($candNoDaftar) || !empty($candTglDaftar) || !empty($candSppb)),
                'nomor_daftar'   => $candNoDaftar,
                'tanggal_daftar' => $candTglDaftar,
                'sppb'           => $candSppb,
                'source'         => $candSource
            ]
        ]
    ]);
    exit;
}

// 4c. SIMPAN MANUAL RESPON
if ($action === 'save_manual_respon') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju     = trim($_POST['nomor_aju'] ?? '');
    $nomor_daftar  = trim($_POST['nomor_daftar'] ?? '');
    $tanggal_daftar= trim($_POST['tanggal_daftar'] ?? '');
    $sppb          = trim($_POST['sppb'] ?? '');

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }
    if (empty($nomor_daftar) || $nomor_daftar === '-') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Daftar wajib diisi (tidak boleh kosong atau "-").']);
        exit;
    }
    if (empty($tanggal_daftar) || $tanggal_daftar === '-' || strpos($tanggal_daftar, '0000-00-00') === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tanggal Daftar wajib diisi dengan tanggal yang valid.']);
        exit;
    }

    $escAju  = $koneksi->real_escape_string($nomor_aju);
    $escNo   = $koneksi->real_escape_string($nomor_daftar);
    $escTgl  = $koneksi->real_escape_string($tanggal_daftar);
    $escSppb = $koneksi->real_escape_string($sppb);

    $hRes = $koneksi->query("SELECT nomor_aju, kode_dokumen, cmp_id FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    if (!$hRes || $hRes->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju tidak ditemukan."]);
        exit;
    }
    $h = $hRes->fetch_assoc();
    $cmpId = $h['cmp_id'] ?? '';
    $kodeBc = $h['kode_dokumen'] ?? '';

    // 1. Update bc_ceisa_header
    $koneksi->query("UPDATE bc_ceisa_header SET nomor_daftar = '$escNo', tanggal_daftar = '$escTgl' WHERE nomor_aju = '$escAju'");

    // 2. Upsert ke bc_ceisa_aju_posted
    $chkPost = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_aju_posted WHERE nomor_aju = '$escAju' LIMIT 1");
    if ($chkPost && $chkPost->num_rows > 0) {
        $koneksi->query("UPDATE bc_ceisa_aju_posted SET nomor_daftar = '$escNo', tanggal_daftar = '$escTgl', sppb = '$escSppb' WHERE nomor_aju = '$escAju'");
    } else {
        $koneksi->query("INSERT INTO bc_ceisa_aju_posted (cmp_id, nomor_aju, waktu, nomor_daftar, tanggal_daftar, sppb, bc) VALUES ('$cmpId', '$escAju', NOW(), '$escNo', '$escTgl', '$escSppb', '$kodeBc')");
    }

    // 3. Upsert ke bc_ceisa_sppb jika SPPB diisi
    if (!empty($escSppb)) {
        $chkSppb = $koneksi->query("SELECT nomorAju FROM bc_ceisa_sppb WHERE nomorAju = '$escAju' LIMIT 1");
        if ($chkSppb && $chkSppb->num_rows > 0) {
            $koneksi->query("UPDATE bc_ceisa_sppb SET nomorDaftar = '$escNo', tanggalDaftar = '$escTgl', nomorRespon = '$escSppb', upd_time = NOW() WHERE nomorAju = '$escAju'");
        } else {
            $koneksi->query("INSERT INTO bc_ceisa_sppb (cmp_id, nomorAju, nomorDaftar, tanggalDaftar, kodeDokumen, namaDokumen, namaRespon, nomorRespon, created_at, upd_time) VALUES ('$cmpId', '$escAju', '$escNo', '$escTgl', '$kodeBc', 'BC $kodeBc', 'SPPB Manual', '$escSppb', NOW(), NOW())");
        }
    }

    // 4. Update ke bc_1ab2 jika dokumen sudah pernah diposting ke IT Inventory
    $koneksi->query("UPDATE bc_1ab2 SET NOMOR_DAFTAR = '$escNo', TANGGAL_DAFTAR = '$escTgl' WHERE nomor_aju = '$escAju'");

    echo json_encode([
        'status'  => 'OK',
        'message' => "Set Manual Respon berhasil disimpan! Nomor Daftar: $nomor_daftar, Tanggal: $tanggal_daftar" . (!empty($sppb) ? ", SPPB: $sppb" : "") . ".",
        'nomor_daftar'  => $nomor_daftar,
        'tanggal_daftar'=> $tanggal_daftar,
        'sppb'          => $sppb
    ]);
    exit;
}

if ($action === 'unpost_it') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih dokumen terlebih dahulu.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);

    try {
        $cnt = 0;
        $qCnt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '$escAju'");
        if ($qCnt && $rCnt = $qCnt->fetch_row()) {
            $cnt = (int)$rCnt[0];
        }
        if ($cnt === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "AJU $nomor_aju belum masuk ke IT Inventory."]);
            exit;
        }

        // 1. Simpan log request unposting
        $hRes = $koneksi->query("SELECT kode_dokumen, nama_pernyataan FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
        $bc = ''; $bs = '';
        if ($hRes && $hr = $hRes->fetch_assoc()) {
            $bc = substr(trim($hr['kode_dokumen'] ?? ''), 0, 5);
            $bs = substr(trim($hr['nama_pernyataan'] ?? ''), 0, 200);
        }
        $user_req = substr(trim($_SESSION['user_name'] ?? 'USER'), 0, 30);
        $tglToday = date('Y-m-d'); // 10 karakter: YYYY-MM-DD sesuai tipe varchar(10)

        $koneksi->query("INSERT INTO bc_ceisa_req_unpost (NOMOR_AJU, BC, NAMA_BS, TGL_REQ, USER_REQ, ALASAN, STATUS, USER_APPV, TGL_APPV)
                         VALUES ('$escAju', '$bc', '$bs', '$tglToday', '$user_req', 'Unposting melalui Daftar Dokumen Web', 'Y', '$user_req', '$tglToday')");
        $unpostId = (int)$koneksi->insert_id;

        // 2. Arsipkan ke bc_1ab2_revisi
        $koneksi->query("INSERT INTO bc_1ab2_revisi (CEK, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, SJ, INVOICE, FAKTUR, NOMOR_AJU, SERI_BARANG, HARGA_PENYERAHAN, CIF, NETTO, SESUAI, QTY_AKTUAL, TGL_PROSES)
                         SELECT $unpostId, BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI, NAMA_BS, KODE_BARANG, POS_TARIF, URAIAN, KLAS, UNIT, QTY, KURS, NILAI, AB, ID_HEADER, SJ, INVOICE, FAKTUR, NOMOR_AJU, SERI_BARANG, HARGA_PENYERAHAN, CIF, NETTO, SESUAI, QTY_AKTUAL, TGL_PROSES
                         FROM bc_1ab2 WHERE nomor_aju = '$escAju'");

        // 3. Hapus dari bc_1ab2
        $ok = $koneksi->query("DELETE FROM bc_1ab2 WHERE nomor_aju = '$escAju'");
        if ($ok) {
            echo json_encode([
                'status' => 'OK', 
                'message' => "Dokumen AJU $nomor_aju berhasil di-unposting. Kunci edit telah dibuka.",
                'is_it_inv' => 0
            ]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal unposting: ' . $koneksi->error]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal unposting: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'set_h2h') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih dokumen terlebih dahulu.']);
        exit;
    }
    $escAju = $koneksi->real_escape_string($nomor_aju);

    $hRes = $koneksi->query("SELECT kode_dokumen, cmp_id FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    if (!$hRes || $hRes->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju tidak ditemukan."]);
        exit;
    }
    $h = $hRes->fetch_assoc();
    $escBc = $koneksi->real_escape_string($h['kode_dokumen']);
    $escCmp = $koneksi->real_escape_string($h['cmp_id']);

    $koneksi->query("DELETE FROM bc_ceisa_aju_posted WHERE nomor_aju = '$escAju'");
    $ok = $koneksi->query("INSERT INTO bc_ceisa_aju_posted (cmp_id, nomor_aju, waktu, bc) VALUES ('$escCmp', '$escAju', NOW(), '$escBc')");
    if ($ok) {
        echo json_encode(['status' => 'OK', 'message' => "Nomor AJU $nomor_aju berhasil diset ke H2H (Siap Kirim)."]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// 5. COPY AJU (DEDICATED)
if ($action === 'copy_aju') {
    header('Content-Type: application/json; charset=utf-8');
    $old_aju = trim($_POST['old_aju'] ?? '');
    $new_aju = trim($_POST['new_aju'] ?? '');
    $new_tgl = trim($_POST['new_tgl'] ?? date('Y-m-d'));

    if (empty($old_aju) || empty($new_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU asal dan Nomor AJU baru wajib diisi.']);
        exit;
    }

    $escOld = $koneksi->real_escape_string($old_aju);
    $escNew = $koneksi->real_escape_string($new_aju);
    $escTgl = $koneksi->real_escape_string($new_tgl);

    $chk = $koneksi->query("SELECT 1 FROM bc_ceisa_header WHERE nomor_aju = '$escNew'");
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU $new_aju sudah digunakan. Silakan masukkan nomor AJU lainnya."]);
        exit;
    }

    $hRes = $koneksi->query("SELECT * FROM bc_ceisa_header WHERE nomor_aju = '$escOld' LIMIT 1");
    if (!$hRes || $hRes->num_rows == 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen asal $old_aju tidak ditemukan."]);
        exit;
    }
    $hdr = $hRes->fetch_assoc();
    $hdr['nomor_aju'] = $new_aju;
    $hdr['tanggal_pernyataan'] = $new_tgl;
    $hdr['nomor_daftar'] = null;
    $hdr['tanggal_daftar'] = null;

    $hdrCols = []; $hdrVals = [];
    foreach ($hdr as $k => $v) {
        $hdrCols[] = "`$k`";
        $hdrVals[] = ($v === null) ? "NULL" : "'" . $koneksi->real_escape_string($v) . "'";
    }
    $koneksi->query("INSERT INTO bc_ceisa_header (" . implode(',', $hdrCols) . ") VALUES (" . implode(',', $hdrVals) . ")");

    $childs = ['bc_ceisa_barang', 'bc_ceisa_entitas', 'bc_ceisa_dokumen', 'bc_ceisa_kemasan', 'bc_ceisa_pengangkut', 'bc_ceisa_kontainer', 'bc_ceisa_pungutan', 'bc_ceisa_bahanbaku', 'bc_ceisa_bankdevisa', 'bc_ceisa_barangtarif', 'bc_ceisa_bahanbakutarif', 'bc_ceisa_jaminan', 'bc_ceisa_kesiapanbarang'];
    foreach ($childs as $tb) {
        $chkTable = $koneksi->query("SHOW TABLES LIKE '$tb'");
        if ($chkTable && $chkTable->num_rows > 0) {
            $cRes = $koneksi->query("SELECT * FROM $tb WHERE nomor_aju = '$escOld'");
            if ($cRes) {
                while ($row = $cRes->fetch_assoc()) {
                    $row['nomor_aju'] = $new_aju;
                    $cols = []; $vals = [];
                    foreach ($row as $k => $v) {
                        $cols[] = "`$k`";
                        $vals[] = ($v === null) ? "NULL" : "'" . $koneksi->real_escape_string($v) . "'";
                    }
                    $koneksi->query("INSERT INTO $tb (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
                }
            }
        }
    }

    echo json_encode(['status' => 'OK', 'message' => "Dokumen berhasil disalin ke Nomor AJU baru: $new_aju", 'new_aju' => $new_aju]);
    exit;
}

// 6. RENAME AJU (DEDICATED)
if ($action === 'rename_aju') {
    header('Content-Type: application/json; charset=utf-8');
    $old_aju = trim($_POST['old_aju'] ?? '');
    $new_aju = trim($_POST['new_aju'] ?? '');

    if (empty($old_aju) || empty($new_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU lama dan Nomor AJU baru wajib diisi.']);
        exit;
    }

    $escOld = $koneksi->real_escape_string($old_aju);
    $escNew = $koneksi->real_escape_string($new_aju);

    if ($escOld === $escNew) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU baru harus berbeda dengan nomor AJU lama.']);
        exit;
    }

    $chk = $koneksi->query("SELECT 1 FROM bc_ceisa_header WHERE nomor_aju = '$escNew'");
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU $new_aju sudah digunakan di database."]);
        exit;
    }

    $tables = [
        'bc_ceisa_header', 'bc_ceisa_barang', 'bc_ceisa_dokumen', 'bc_ceisa_entitas', 
        'bc_ceisa_bankdevisa', 'bc_ceisa_kemasan', 'bc_ceisa_pengangkut', 'bc_ceisa_kontainer', 
        'bc_ceisa_pungutan', 'bc_ceisa_barangtarif', 'bc_ceisa_bahanbakutarif', 'bc_ceisa_bahanbaku', 
        'bc_ceisa_jaminan', 'bc_ceisa_kesiapanbarang', 'bc_ceisa_aju_posted', 'bc_1ab2', 'bc_ceisa_status', 'bc_ceisa_respons'
    ];

    foreach ($tables as $tb) {
        $chkTable = $koneksi->query("SHOW TABLES LIKE '$tb'");
        if ($chkTable && $chkTable->num_rows > 0) {
            $koneksi->query("UPDATE `$tb` SET `nomor_aju` = '$escNew' WHERE `nomor_aju` = '$escOld'");
        }
    }

    echo json_encode(['status' => 'OK', 'message' => "Nomor AJU $old_aju berhasil di-rename menjadi $new_aju", 'new_aju' => $new_aju]);
    exit;
}

// 6b. DELETE DATA AJU (HAPUS STAGING TABLES)
if ($action === 'delete_aju') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_POST['nomor_aju'] ?? '');
    $confirm   = strtoupper(trim($_POST['confirm'] ?? ''));

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }

    if ($confirm !== 'YA') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Konfirmasi gagal. Wajib mengetik "YA" untuk menghapus data AJU.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);

    // Cek apakah dokumen ada
    $chkHdr = $koneksi->query("SELECT nomor_aju, cmp_id, kode_dokumen FROM bc_ceisa_header WHERE nomor_aju = '$escAju' LIMIT 1");
    $hasHdr = ($chkHdr && $chkHdr->num_rows > 0);
    $chkData = $koneksi->query("SELECT nomorAju, cmp_id FROM bc_ceisa_data WHERE nomorAju = '$escAju' LIMIT 1");
    $hasData = ($chkData && $chkData->num_rows > 0);

    if (!$hasHdr && !$hasData) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen AJU $nomor_aju tidak ditemukan di database."]);
        exit;
    }

    // Cek apakah dokumen sudah terkunci / masuk IT Inventory (bc_1ab2)
    $chkIt = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE nomor_aju = '$escAju'");
    if ($chkIt && ($rIt = $chkIt->fetch_row()) && (int)$rIt[0] > 0) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => "Dokumen AJU $nomor_aju tidak dapat dihapus karena sudah dikunci (masuk ke IT Inventory). Silakan lakukan Unposting IT Inventory terlebih dahulu."
        ]);
        exit;
    }

    $stagingTables = [
        'bc_ceisa_header', 'bc_ceisa_barang', 'bc_ceisa_barangtarif', 'bc_ceisa_barangdokumen',
        'bc_ceisa_barangentitas', 'bc_ceisa_barangspekkhusus', 'bc_ceisa_barangvd', 'bc_ceisa_barangbom',
        'bc_ceisa_bahanbaku', 'bc_ceisa_bahanbakudokumen', 'bc_ceisa_bahanbakutarif', 'bc_ceisa_dokumen',
        'bc_ceisa_entitas', 'bc_ceisa_kemasan', 'bc_ceisa_pengangkut', 'bc_ceisa_kontainer',
        'bc_ceisa_bankdevisa', 'bc_ceisa_pungutan', 'bc_ceisa_jaminan', 'bc_ceisa_komponenbiaya',
        'bc_ceisa_kesiapanbarang', 'bc_ceisa_aju_posted', 'bc_ceisa_status', 'bc_ceisa_respons',
        'bc_ceisa_respon_logs', 'bc_ceisa_json', 'bc_ceisa_data_compareit', 'bc_ceisa_req_unpost',
        'bc_ceisa_docidx', 'bc_ceisa_entidx', 'bc_ceisa_scan27', 'bc_si_aju_ceisa'
    ];

    $deletedCounts = [];
    foreach ($stagingTables as $tb) {
        $chkTable = $koneksi->query("SHOW TABLES LIKE '$tb'");
        if ($chkTable && $chkTable->num_rows > 0) {
            $chkCol = $koneksi->query("SHOW COLUMNS FROM `$tb` LIKE 'nomor_aju'");
            if ($chkCol && $chkCol->num_rows > 0) {
                $koneksi->query("DELETE FROM `$tb` WHERE `nomor_aju` = '$escAju'");
                if ($koneksi->affected_rows > 0) {
                    $deletedCounts[$tb] = $koneksi->affected_rows;
                }
            }
        }
    }

    // Hapus dari bc_ceisa_data jika ada
    $chkDataTb = $koneksi->query("SHOW TABLES LIKE 'bc_ceisa_data'");
    if ($chkDataTb && $chkDataTb->num_rows > 0) {
        $koneksi->query("DELETE FROM `bc_ceisa_data` WHERE `nomorAju` = '$escAju'");
        if ($koneksi->affected_rows > 0) {
            $deletedCounts['bc_ceisa_data'] = $koneksi->affected_rows;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Dokumen AJU $nomor_aju beserta seluruh data staging berhasil dihapus.",
        'deleted_details' => $deletedCounts
    ]);
    exit;
}

// 6c. PREVIEW JSON
if ($action === 'preview_json') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_GET['nomor_aju'] ?? '');
    $cmp_id    = trim($_GET['cmp_id'] ?? '');

    $result = CeisaJsonBuilder::generate($nomor_aju, $cmp_id);
    echo json_encode($result);
    exit;
}

// 7. TRANSFER KE CEISA 4.0
if ($action === 'transfer') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? '');
    $cmp_id    = trim($_REQUEST['cmp_id'] ?? '');

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }

    $_REQUEST['nomor_aju'] = $nomor_aju;
    $_REQUEST['cmp_id']    = $cmp_id;

    ob_start();
    include __DIR__ . '/posting.php';
    $postOutput = ob_get_clean();

    $decoded = json_decode($postOutput, true);
    if ($decoded !== null) {
        echo json_encode(['status' => 'OK', 'nomor_aju' => $nomor_aju, 'response' => $decoded]);
    } else {
        echo json_encode(['status' => 'RAW', 'nomor_aju' => $nomor_aju, 'raw_response' => $postOutput]);
    }
    exit;
}

$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Host to Host - Interface Host to Host Ceisa 4.0</title>
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || '<?= $themeParam ?>' || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        })();
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-body: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #d9d9d9;
            --border-table: #d9d9d9;
            --table-head-bg: #fafafa;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --row-hover: #f0f7ff;
            --row-selected: #bae7ff;
            --row-selected-text: #002766;
            --row-selected-border: #1890ff;
            color-scheme: light;
        }

        html[data-theme="dark"], body[data-theme="dark"] {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --border-card: #303030;
            --border-table: #383838;
            --table-head-bg: #1d1d1d;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            --row-hover: #262638;
            --row-selected: #112a45;
            --row-selected-text: #e6f7ff;
            --row-selected-border: #177ddc;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            overflow: hidden;
        }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }

        /* -------------------------------------------------------------
           SPLIT CONTAINER & SPLITBAR (RESIZABLE VERTICAL LAYOUT)
           ------------------------------------------------------------- */
        .split-container {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 12px);
            width: 100%;
            overflow: hidden;
        }
        .split-pane-top {
            flex: 0 0 53%;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .split-divider {
            height: 8px;
            background: var(--bg-body);
            border-top: 1px solid var(--border-card);
            border-bottom: 1px solid var(--border-card);
            cursor: row-resize;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            user-select: none;
            transition: background 0.15s;
            flex-shrink: 0;
            z-index: 20;
        }
        .split-divider:hover, .split-divider.dragging {
            background: var(--primary) !important;
        }
        .split-divider .split-handle {
            width: 40px;
            height: 3px;
            border-top: 1px solid var(--text-muted);
            border-bottom: 1px solid var(--text-muted);
            opacity: 0.7;
            pointer-events: none;
        }
        .split-divider:hover .split-handle, .split-divider.dragging .split-handle {
            border-color: #ffffff !important;
            opacity: 1;
        }
        .split-pane-bottom {
            flex: 1 1 0;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* -------------------------------------------------------------
           ANT DESIGN CARD (.ant-card)
           ------------------------------------------------------------- */
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .ant-card-head {
            min-height: 36px;
            padding: 4px 10px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            gap: 8px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .ant-card-head-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .ant-card-head-actions {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }
        .ant-card-body {
            padding: 6px 8px;
        }

        /* -------------------------------------------------------------
           BUTTONS (.ant-btn)
           ------------------------------------------------------------- */
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
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
            font-family: inherit;
        }
        .ant-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff !important;
            font-weight: 600;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff !important;
        }
        .ant-btn-success {
            background: #52c41a;
            border-color: #52c41a;
            color: #ffffff !important;
            font-weight: 600;
        }
        .ant-btn-success:hover {
            background: #73d13d;
            border-color: #73d13d;
            color: #ffffff !important;
        }
        .ant-btn-warning {
            background: #fa8c16;
            border-color: #fa8c16;
            color: #ffffff !important;
            font-weight: 600;
        }
        .ant-btn-warning:hover {
            background: #ffa940;
            border-color: #ffa940;
            color: #ffffff !important;
        }
        .ant-btn-danger {
            background: #ff4d4f;
            border-color: #ff4d4f;
            color: #ffffff !important;
            font-weight: 600;
        }
        .ant-btn-danger:hover {
            background: #ff7875;
            border-color: #ff7875;
            color: #ffffff !important;
        }
        .ant-btn-sm {
            height: 22px;
            padding: 0 6px;
            font-size: 9.5px;
        }

        /* -------------------------------------------------------------
           FILTER PANEL (.filter-panel)
           ------------------------------------------------------------- */
        .filter-panel {
            display: flex;
            padding: 5px 8px;
            margin-bottom: 6px;
            gap: 6px;
            align-items: flex-end;
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .filter-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .filter-col label {
            font-size: 8.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-control {
            padding: 1px 6px;
            height: 23px;
            font-size: 10px;
            border-radius: 3px;
            border: 1px solid var(--border-input);
            background-color: var(--bg-input);
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .filter-control:focus {
            border-color: var(--primary);
        }

        /* Fix select options in Windows/Chromium */
        html[data-theme="light"] select.filter-control,
        body[data-theme="light"] select.filter-control,
        select.filter-control option {
            background-color: #ffffff !important;
            color: #262626 !important;
            color-scheme: light !important;
        }
        html[data-theme="dark"] select.filter-control,
        body[data-theme="dark"] select.filter-control,
        html[data-theme="dark"] select.filter-control option,
        body[data-theme="dark"] select.filter-control option {
            background-color: #1f1f1f !important;
            color: #ffffff !important;
            color-scheme: dark !important;
        }

        /* -------------------------------------------------------------
           KPI CHIPS (.kpi-chip)
           ------------------------------------------------------------- */
        .kpi-chip {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 2px;
            padding: 1px 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 9.5px;
        }
        .kpi-label {
            color: var(--text-muted);
            font-weight: 500;
            font-size: 8.5px;
        }
        .kpi-val {
            font-weight: 700;
        }

        /* -------------------------------------------------------------
           EXCEL GRID DATA TABLES (.ant-table-wrapper & .ant-table)
           ------------------------------------------------------------- */
        .ant-table-wrapper {
            width: 100%;
            border: 1px solid var(--border-table);
            border-radius: 3px;
            background: var(--bg-card);
            overflow: hidden;
        }
        .table-scroll {
            width: 100%;
            height: 100%;
            overflow: auto;
        }
        .detail-scroll {
            width: 100%;
            height: 100%;
            overflow: auto;
        }

        /* Crisp Excel Table Borders & Rigid Fixed Column Layout */
        .ant-table, table.excel-grid {
            width: 100%;
            table-layout: fixed !important;
            border-collapse: collapse !important;
            border-spacing: 0;
            font-size: 10px;
            text-align: left;
            border: 1px solid var(--border-table) !important;
        }
        .ant-table-thead > tr > th,
        .ant-table th,
        table.excel-grid th {
            color: var(--text-heading);
            font-weight: 600;
            background: var(--table-head-bg);
            border: 1px solid var(--border-table) !important;
            padding: 4px 6px;
            font-size: 9.5px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
            overflow: hidden;
            text-overflow: ellipsis;
            box-sizing: border-box;
        }
        .ant-table-tbody > tr > td,
        .ant-table td,
        table.excel-grid td {
            border: 1px solid var(--border-table) !important;
            padding: 3px 6px;
            font-size: 10px;
            vertical-align: middle;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            box-sizing: border-box;
        }
        .ant-table-tbody > tr {
            cursor: pointer;
            transition: background 0.15s;
        }
        .ant-table-tbody > tr:hover > td:not(.cell-no-it-inv):not(.cell-it-inv) {
            background: var(--row-hover) !important;
        }

        /* Prominent Excel Row Selection (Warna konsisten tanpa mengubah font-weight atau border agar lebar kolom 100% stabil) */
        #hdrTbody tr.selected-row td:not(.cell-no-it-inv):not(.cell-it-inv),
        .ant-table-tbody tr.selected-row td:not(.cell-no-it-inv):not(.cell-it-inv),
        .ant-table tbody tr.selected-row td:not(.cell-no-it-inv):not(.cell-it-inv) {
            background: var(--row-selected) !important;
            color: var(--row-selected-text) !important;
        }
        #hdrTbody tr.selected-row td:first-child:not(.cell-no-it-inv):not(.cell-it-inv),
        .ant-table-tbody tr.selected-row td:first-child:not(.cell-no-it-inv):not(.cell-it-inv) {
            border-left: 1px solid var(--border-table) !important;
        }

        /* -------------------------------------------------------------
           POWERBUILDER CEISA COLORING RULES (Aktif HANYA JIKA IS_INV = 1)
           ------------------------------------------------------------- */
        /* 1. Baris Dokumen sudah submit ke CEISA: Warna Latar BIRU SOFT TERANG */
        #hdrTbody tr.row-submitted-ceisa td:not(.cell-no-it-inv):not(.cell-it-inv) {
            background-color: #bfe0ff !important;
            color: #002766 !important;
            border-color: rgba(24, 144, 255, 0.25) !important;
        }
        #hdrTbody tr.row-submitted-ceisa td:not(.cell-no-it-inv):not(.cell-it-inv) a {
            color: #0958d9 !important;
        }
        #hdrTbody tr.row-submitted-ceisa td .ant-tag {
            background: #ffffff !important;
            border-color: #91caff !important;
        }
        #hdrTbody tr.row-submitted-ceisa td .ant-tag-blue {
            color: #096dd9 !important;
        }
        #hdrTbody tr.row-submitted-ceisa td .ant-tag-green {
            color: #389e0d !important;
            border-color: #b7eb8f !important;
        }
        #hdrTbody tr.row-submitted-ceisa td .ant-tag-gold {
            color: #d46b08 !important;
            border-color: #ffd591 !important;
        }
        #hdrTbody tr.row-submitted-ceisa td .ant-tag-red {
            color: #cf1322 !important;
            border-color: #ffa39e !important;
        }
        #hdrTbody tr.row-submitted-ceisa:hover td:not(.cell-no-it-inv):not(.cell-it-inv) {
            background-color: #a8d5ff !important;
            color: #002766 !important;
        }
        body[data-theme="dark"] #hdrTbody tr.row-submitted-ceisa td:not(.cell-no-it-inv):not(.cell-it-inv) {
            background-color: #112a45 !important;
            color: #bae7ff !important;
            border-color: rgba(23, 125, 220, 0.3) !important;
        }
        body[data-theme="dark"] #hdrTbody tr.row-submitted-ceisa td .ant-tag {
            background: #163860 !important;
            color: #e6f7ff !important;
            border-color: #177ddc !important;
        }
        body[data-theme="dark"] #hdrTbody tr.row-submitted-ceisa:hover td:not(.cell-no-it-inv):not(.cell-it-inv) {
            background-color: #17375e !important;
        }

        /* 2. Kolom Nomor AJU & nomor urut jika sudah masuk IT Inventory: KUNING (TETAP KUNING SAAT HOVER / TERPILIH) */
        #hdrTbody tr td.cell-it-inv,
        #hdrTbody tr:hover td.cell-it-inv,
        #hdrTbody tr.selected-row td.cell-it-inv,
        #hdrTbody tr.selected-row:hover td.cell-it-inv,
        #hdrTbody tr.row-submitted-ceisa td.cell-it-inv,
        #hdrTbody tr.row-submitted-ceisa:hover td.cell-it-inv,
        #hdrTbody tr.row-submitted-ceisa.selected-row td.cell-it-inv,
        .ant-table-tbody tr.selected-row td.cell-it-inv,
        .ant-table-tbody tr:hover td.cell-it-inv {
            background: #ffff88 !important;
            background-color: #ffff88 !important;
            color: #000000 !important;
            border-left: 1px solid var(--border-table) !important;
        }
        #hdrTbody tr td.cell-it-inv .ant-tag,
        #hdrTbody tr:hover td.cell-it-inv .ant-tag,
        #hdrTbody tr.selected-row td.cell-it-inv .ant-tag,
        #hdrTbody tr.row-submitted-ceisa td.cell-it-inv .ant-tag {
            background: #fffb8f !important;
            color: #000000 !important;
            border-color: #d4b106 !important;
        }

        /* 3. Nomor urut jika sudah ada nomor daftar tapi belum masuk IT Inventory: MERAH TETAP MERAH (TIDAK BERUBAH WARNA) */
        #hdrTbody tr td.cell-no-it-inv,
        #hdrTbody tr:hover td.cell-no-it-inv,
        #hdrTbody tr.selected-row td.cell-no-it-inv,
        #hdrTbody tr.selected-row:hover td.cell-no-it-inv,
        #hdrTbody tr.row-submitted-ceisa td.cell-no-it-inv,
        #hdrTbody tr.row-submitted-ceisa:hover td.cell-no-it-inv,
        #hdrTbody tr.row-submitted-ceisa.selected-row td.cell-no-it-inv,
        .ant-table-tbody tr.selected-row td.cell-no-it-inv,
        .ant-table-tbody tr:hover td.cell-no-it-inv {
            background: #ff4d4f !important;
            background-color: #ff4d4f !important;
            color: #ffffff !important;
            text-align: center !important;
            border-left: 1px solid var(--border-table) !important;
        }

        /* Baris Terpilih (Active Row Selection) dengan Outline tegas */
        #hdrTbody tr.selected-row {
            outline: 2px solid #ff7a45 !important;
            outline-offset: -2px !important;
        }

        /* Tags / Badges */
        .ant-tag {
            box-sizing: border-box;
            margin: 0;
            padding: 0 5px;
            font-size: 8.5px;
            line-height: 15px;
            height: 16px;
            display: inline-block;
            border: 1px solid var(--border-input);
            border-radius: 2px;
            background: var(--bg-filter);
            white-space: nowrap;
            font-weight: 600;
        }
        .ant-tag-green { color: #52c41a; background: #f6ffed; border-color: #b7eb8f; }
        .ant-tag-gold { color: #fa8c16; background: #fff7e6; border-color: #ffd591; }
        .ant-tag-red { color: #f5222d; background: #fff1f0; border-color: #ffa39e; }
        .ant-tag-blue { color: #1890ff; background: #e6f7ff; border-color: #91d5ff; }
        html[data-theme="dark"] .ant-tag-green { color: #49aa19; background: #162312; border-color: #274916; }
        html[data-theme="dark"] .ant-tag-gold { color: #d87a16; background: #2b1d11; border-color: #442a11; }
        html[data-theme="dark"] .ant-tag-red { color: #a61d24; background: #2a1215; border-color: #431418; }
        html[data-theme="dark"] .ant-tag-blue { color: #177ddc; background: #111d2c; border-color: #15395b; }

        /* -------------------------------------------------------------
           DETAIL TABS (Matching editor.php)
           ------------------------------------------------------------- */
        .tab-bar {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .tab-btn {
            padding: 3px 10px;
            font-size: 10px;
            font-weight: 500;
            background: transparent;
            border: 1px solid transparent;
            border-radius: 3px;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .tab-btn:hover {
            color: var(--primary);
            background: rgba(24, 144, 255, 0.06);
        }
        .tab-btn.active {
            color: var(--primary);
            font-weight: 600;
            background: var(--bg-card);
            border-color: var(--border-card);
            border-bottom-color: var(--primary);
            border-bottom-width: 2px;
        }
        .tab-panel {
            display: none;
            height: 100%;
        }
        .tab-panel.active {
            display: flex;
            flex-direction: column;
        }

        /* Summary Footer Strip */
        .summary-strip {
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            padding: 4px 10px;
            background: var(--table-head-bg);
            border-top: 1px solid var(--border-table);
            font-size: 9.5px;
            font-weight: 600;
            flex-shrink: 0;
        }
        .summary-item {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .summary-label {
            color: var(--text-muted);
        }
        .summary-val {
            color: var(--primary);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            padding: 12px;
            backdrop-filter: blur(2px);
        }
        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            width: 100%;
            max-width: 720px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--table-head-bg);
        }
        .modal-header h3 { font-size: 11.5px; color: var(--text-heading); font-weight: 600; }
        .modal-body {
            padding: 10px 12px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border-card);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--table-head-bg);
        }
        pre.code-view {
            background: #0b1120;
            border: 1px solid #1e293b;
            padding: 8px 10px;
            border-radius: 3px;
            color: #38bdf8;
            font-family: monospace;
            font-size: 10px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
        }

        /* Context Menu (DataWindow Style) */
        .context-menu {
            position: fixed;
            z-index: 99999;
            background: var(--bg-card);
            border: 1px solid var(--border-input);
            border-radius: 4px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.18);
            min-width: 195px;
            padding: 4px 0;
            font-size: 11px;
            display: none;
        }
        .context-menu-item {
            padding: 6px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-main);
            transition: all .15s;
            user-select: none;
        }
        .context-menu-item:hover {
            background: #e6f7ff;
            color: #1890ff;
        }
        body[data-theme="dark"] .context-menu-item:hover {
            background: #111d2c;
            color: #177ddc;
        }
        .context-menu-divider {
            height: 1px;
            background: var(--border-table);
            margin: 4px 0;
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($themeParam) ?>">

    <div class="split-container" id="splitContainer">

        <!-- PANE TOP: DAFTAR DOKUMEN HEADER (MASTER GRID) -->
        <div class="split-pane-top" id="topPane">
            <div class="ant-card" style="height:100%; display:flex; flex-direction:column; margin-bottom:0;">
                <!-- Card Header with All Toolbar Buttons -->
                <div class="ant-card-head">
                    <div class="ant-card-head-title">
                        <span>&#128203;</span>
                        <span>Data Host to Host</span>
                        <div class="kpi-chip">
                            <span class="kpi-label">Total:</span>
                            <strong class="kpi-val" id="kpiTotal">0</strong>
                        </div>
                        <div class="kpi-chip">
                            <span class="kpi-label">Belum Kirim:</span>
                            <strong class="kpi-val" style="color:#fa8c16;" id="kpiUnposted">0</strong>
                        </div>
                        <div class="kpi-chip">
                            <span class="kpi-label">Sudah Kirim:</span>
                            <strong class="kpi-val" style="color:#52c41a;" id="kpiPosted">0</strong>
                        </div>
                        <button type="button" class="btn-beluminv" id="chipBelumItInv" onclick="filterBelumItInv()" style="display:none; align-items:center; gap:5px; background:#ff4d4f; color:#ffffff; border:none; padding:3px 10px; border-radius:3px; font-weight:700; font-size:11px; cursor:pointer;" title="Klik untuk menampilkan dokumen yang belum masuk IT Inventory">
                            &#9888;&#65039; <span id="cntBelumItInv">0</span> Dok Belum Masuk IT Inv !
                        </button>
                    </div>
                    <div class="ant-card-head-actions">
                        <button type="button" class="ant-btn ant-btn-primary" onclick="transferActiveAju()" title="Transfer Dokumen ke Portal CEISA 4.0">
                            <span>&#128640; Transfer CEISA</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="setH2HActiveAju()" title="Set Status Dokumen ke H2H (Siap Kirim)">
                            <span>&#9889; Set H2H</span>
                        </button>
                        <button type="button" class="ant-btn ant-btn-success" onclick="postItInventoryActiveAju()" title="Posting Dokumen ke IT Inventory<?= $isAdmin ? ' (bc_1ab2)' : '' ?>">
                            <span>&#128229; Posting IT Inventory</span>
                        </button>
                        <button type="button" class="ant-btn ant-btn-warning" onclick="unpostItInventoryActiveAju()" title="Batalkan Posting IT Inventory untuk Membuka Kunci Edit">
                            <span>&#128228; Unposting</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="openManualResponModal()" title="Set Manual Respon / Isi Nomor & Tanggal Daftar / SPPB">
                            <span>📝 Set Manual Respon</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="viewJsonActiveAju()" title="Lihat Payload JSON CEISA">
                            <span>&#128065;&#65039; View JSON</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="openEditorForm()" title="Buka Form Entri AJU Baru">
                            <span>&#10133; Entri AJU Baru</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="openCopyModal()" title="Salin Dokumen Lengkap ke Nomor AJU Baru">
                            <span>&#128203; Copy AJU</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="openRenameModal()" title="Ubah Nomor AJU Dokumen">
                            <span>&#9999;&#65039; Rename AJU</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="editActiveAju()" title="Edit Dokumen di Form Editor">
                            <span>&#128196; Edit AJU</span>
                        </button>
                        <button type="button" class="ant-btn ant-btn-danger" style="color:#ff4d4f; border-color:#ffa39e;" onclick="deleteActiveAju()" title="Hapus Semua Data Staging Dokumen AJU Terpilih">
                            <span>🗑️ Hapus AJU</span>
                        </button>
                        <button type="button" class="ant-btn" onclick="loadList()" title="Muat Ulang Data">
                            <span>&#128259; Muat Ulang</span>
                        </button>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="ant-card-body" style="flex:1; display:flex; flex-direction:column; min-height:0; overflow:hidden;">
                    <!-- Filter Bar -->
                    <div class="filter-panel">
                        <div class="filter-col">
                            <label>Dokumen BC</label>
                            <select id="filterBc" class="filter-control" onchange="loadList()">
                                <option value="">Semua Dokumen</option>
                                <?php if (isDocPermitted('16')): ?><option value="16">BC 1.6 (Pemasukan PLB)</option><?php endif; ?>
                                <?php if (isDocPermitted('20')): ?><option value="20">BC 2.0 (Impor PIB)</option><?php endif; ?>
                                <?php if (isDocPermitted('23')): ?><option value="23">BC 2.3 (Impor TPB)</option><?php endif; ?>
                                <?php if (isDocPermitted('25')): ?><option value="25">BC 2.5 (Pengeluaran TPB)</option><?php endif; ?>
                                <?php if (isDocPermitted('261')): ?><option value="261">BC 2.6.1 (Subkon Luar)</option><?php endif; ?>
                                <?php if (isDocPermitted('262')): ?><option value="262">BC 2.6.2 (Subkon Masuk)</option><?php endif; ?>
                                <?php if (isDocPermitted('27')): ?><option value="27">BC 2.7 (TPB ke TPB)</option><?php endif; ?>
                                <?php if (isDocPermitted('28')): ?><option value="28">BC 2.8 (Impor PLB)</option><?php endif; ?>
                                <?php if (isDocPermitted('30')): ?><option value="30">BC 3.0 (Ekspor PEB)</option><?php endif; ?>
                                <?php if (isDocPermitted('33')): ?><option value="33">BC 3.3 (Ekspor PLB)</option><?php endif; ?>
                                <?php if (isDocPermitted('331')): ?><option value="331">BC 3.3.1 (P3BET)</option><?php endif; ?>
                                <?php if (isDocPermitted('40')): ?><option value="40">BC 4.0 (Pemasukan TLDDP)</option><?php endif; ?>
                                <?php if (isDocPermitted('41')): ?><option value="41">BC 4.1 (Pengeluaran TLDDP)</option><?php endif; ?>
                                <?php if (isDocPermitted('611')): ?><option value="611">PPKEK 6.1.1 (Pemasukan LDP)</option><?php endif; ?>
                                <?php if (isDocPermitted('613')): ?><option value="613">PPKEK 6.1.3 (Pemasukan TLDDP)</option><?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-col">
                            <label>Status Posting / IT Inv</label>
                            <select id="filterStatus" class="filter-control" onchange="loadList()">
                                <option value="" selected>Semua Status</option>
                                <option value="unposted">Belum Diposting (Siap Kirim)</option>
                                <option value="posted">Sudah Diposting H2H</option>
                                <option value="it_inv">Sudah Masuk IT Inventory</option>
                                <option value="not_it_inv">Belum Masuk IT Inventory</option>
                            </select>
                        </div>

                        <div class="filter-col">
                            <label>Periode (Bulan)</label>
                            <input type="month" id="filterPeriode" class="filter-control" value="<?= date('Y-m') ?>" onchange="onPeriodChange(this.value)">
                        </div>

                        <div class="filter-col">
                            <label>Rentang Tanggal</label>
                            <div style="display:flex; align-items:center; gap:3px;">
                                <input type="date" id="filterTgl1" class="filter-control" value="<?= date('Y-m-01') ?>">
                                <span style="color:var(--text-muted); font-size:9px;">~</span>
                                <input type="date" id="filterTgl2" class="filter-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <div class="filter-col" style="flex:1; min-width:180px;">
                            <label>Pencarian Global</label>
                            <input type="text" id="filterSearch" class="filter-control" placeholder="Cari No. AJU, Perusahaan, No. Daftar, Invoice..." onkeyup="if(event.key==='Enter') loadList()">
                        </div>

                        <input type="hidden" id="filterCmp" value="<?= htmlspecialchars($sessionCmpId) ?>">

                        <div>
                            <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadList()">Cari</button>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="resetFilters()">Reset</button>
                        </div>
                    </div>

                    <!-- Master Grid Table (Daftar Dokumen Header) -->
                    <div class="ant-table-wrapper" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                        <div class="table-scroll" id="masterTableScroll">
                            <table class="ant-table excel-grid" id="hdrTable" style="table-layout: fixed !important; width: 100%; min-width: 1420px;">
                                <colgroup>
                                    <col style="width: 38px;">
                                    <col style="width: 65px;">
                                    <col style="width: 220px;">
                                    <col style="width: 85px;">
                                    <col style="width: 85px;">
                                    <col style="width: 85px;">
                                    <col style="width: 250px;">
                                    <col style="width: 125px;">
                                    <col style="width: 55px;">
                                    <col style="width: 65px;">
                                    <col style="width: 95px;">
                                    <col style="width: 150px;">
                                    <col style="width: 105px;">
                                </colgroup>
                                <thead class="ant-table-thead">
                                    <tr>
                                        <th style="width: 38px; text-align:center;">#</th>
                                        <th style="width: 65px; text-align:center;">Dokumen</th>
                                        <th style="width: 220px;">Nomor Pengajuan (AJU)</th>
                                        <th style="width: 85px; text-align:center;">Tgl AJU</th>
                                        <th style="width: 85px; text-align:center;">No. Daftar</th>
                                        <th style="width: 85px; text-align:center;">Tgl Daftar</th>
                                        <th style="width: 250px;">Buyer / Supplier</th>
                                        <th style="width: 125px;">Invoice</th>
                                        <th style="width: 55px; text-align:center;">Valuta</th>
                                        <th style="width: 65px; text-align:right;">Jml Brg</th>
                                        <th style="width: 95px; text-align:right;">Bruto (kg)</th>
                                        <th style="width: 150px;">SPPB</th>
                                        <th style="width: 105px; text-align:center;">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="hdrTbody" class="ant-table-tbody">
                                    <tr><td colspan="13" style="text-align:center; padding: 20px; color:var(--text-muted);">Memuat data dokumen...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SPLITBAR (RESIZABLE HORIZONTAL DIVIDER) -->
        <div class="split-divider" id="splitbar" title="Tarik ke atas / bawah untuk mengubah ukuran tampilan">
            <div class="split-handle"></div>
        </div>

        <!-- PANE BOTTOM: TAB DETAIL DOKUMEN (BARANG, DOKUMEN, STATUS, RESPON) -->
        <div class="split-pane-bottom" id="bottomPane">
            <div class="ant-card" style="height:100%; display:flex; flex-direction:column; margin-bottom:0;">
                <!-- Card Header: Active Document Title & Tab Switcher -->
                <div class="ant-card-head">
                    <div class="ant-card-head-title">
                        <span style="font-size:10px; color:var(--text-muted); font-weight:400;">Dokumen Terpilih:</span>
                        <span id="detailAjuTitle" style="color:var(--primary); font-weight:700;">-</span>
                    </div>
                    <div class="tab-bar">
                        <button type="button" class="tab-btn active" data-tab="barang" onclick="switchDetailTab('barang')">
                            <span>&#128230; Data Barang</span>
                            <span class="ant-tag" id="cnt-barang" style="margin-left:2px;">0</span>
                        </button>
                        <button type="button" class="tab-btn" data-tab="dokumen" onclick="switchDetailTab('dokumen')">
                            <span>&#128196; Dokumen</span>
                            <span class="ant-tag" id="cnt-dokumen" style="margin-left:2px;">0</span>
                        </button>
                        <button type="button" class="tab-btn" data-tab="status" onclick="switchDetailTab('status')">
                            <span>&#8505;&#65039; Status</span>
                            <span class="ant-tag" id="cnt-status" style="margin-left:2px;">0</span>
                        </button>
                        <button type="button" class="tab-btn" data-tab="respon" onclick="switchDetailTab('respon')">
                            <span>&#128172; Respon</span>
                            <span class="ant-tag" id="cnt-respon" style="margin-left:2px;">0</span>
                        </button>
                    </div>
                </div>

                <!-- Card Body: Tab Content Panels -->
                <div class="ant-card-body" style="flex:1; display:flex; flex-direction:column; min-height:0; overflow:hidden;">
                    <!-- 1. TAB PANEL: BARANG -->
                    <div class="tab-panel active" id="panel-barang">
                        <div class="ant-table-wrapper" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                            <div class="detail-scroll">
                                <table class="ant-table excel-grid" id="dtlTableBarang" style="table-layout: fixed !important; width: 100%; min-width: 950px;">
                                    <colgroup>
                                        <col style="width: 35px;">
                                        <col style="width: 120px;">
                                        <col style="width: 250px;">
                                        <col style="width: 90px;">
                                        <col style="width: 55px;">
                                        <col style="width: 110px;">
                                        <col style="width: 110px;">
                                        <col style="width: 90px;">
                                        <col style="width: 90px;">
                                    </colgroup>
                                    <thead class="ant-table-thead">
                                        <tr>
                                            <th style="width: 35px; text-align:center;">#</th>
                                            <th style="width: 120px;">Kode Barang</th>
                                            <th style="width: 250px;">Uraian Barang</th>
                                            <th style="width: 90px; text-align:right;">Jumlah</th>
                                            <th style="width: 55px; text-align:center;">Satuan</th>
                                            <th style="width: 110px;">Klasifikasi</th>
                                            <th style="width: 110px; text-align:right;">Harga Penyerahan</th>
                                            <th style="width: 90px; text-align:right;">CIF</th>
                                            <th style="width: 90px; text-align:right;">Netto (kg)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dtlTbodyBarang">
                                        <tr><td colspan="9" style="text-align:center; padding: 16px; color:var(--text-muted);">Pilih salah satu dokumen di atas untuk melihat detail barang.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="summary-strip" id="summaryBarang">
                                <div class="summary-item"><span class="summary-label">Total Jumlah:</span> <span class="summary-val" id="sumJml">0.00</span></div>
                                <div class="summary-item"><span class="summary-label">Total Penyerahan:</span> <span class="summary-val" id="sumPenyerahan">0.00</span></div>
                                <div class="summary-item"><span class="summary-label">Total CIF:</span> <span class="summary-val" id="sumCif">0.00</span></div>
                                <div class="summary-item"><span class="summary-label">Total Netto:</span> <span class="summary-val" id="sumNetto">0.0000 kg</span></div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. TAB PANEL: DOKUMEN -->
                    <div class="tab-panel" id="panel-dokumen">
                        <div class="ant-table-wrapper" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                            <div class="detail-scroll">
                                <table class="ant-table excel-grid" id="dtlTableDokumen" style="table-layout: fixed !important; width: 100%; min-width: 650px;">
                                    <colgroup>
                                        <col style="width: 35px;">
                                        <col style="width: 100px;">
                                        <col style="width: 220px;">
                                        <col style="width: 180px;">
                                        <col style="width: 115px;">
                                    </colgroup>
                                    <thead class="ant-table-thead">
                                        <tr>
                                            <th style="width: 35px; text-align:center;">#</th>
                                            <th style="width: 100px;">Kode Dokumen</th>
                                            <th style="width: 220px;">Nama Dokumen</th>
                                            <th style="width: 180px;">Nomor Dokumen</th>
                                            <th style="width: 115px; text-align:center;">Tanggal Dokumen</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dtlTbodyDokumen">
                                        <tr><td colspan="5" style="text-align:center; padding: 16px; color:var(--text-muted);">-</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 3. TAB PANEL: STATUS -->
                    <div class="tab-panel" id="panel-status">
                        <div class="ant-table-wrapper" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                            <div class="detail-scroll">
                                <table class="ant-table excel-grid" id="dtlTableStatus" style="table-layout: fixed !important; width: 100%; min-width: 890px;">
                                    <colgroup>
                                        <col style="width: 70px;">
                                        <col style="width: 220px;">
                                        <col style="width: 130px;">
                                        <col style="width: 90px;">
                                        <col style="width: 130px;">
                                        <col style="width: 250px;">
                                    </colgroup>
                                    <thead class="ant-table-thead">
                                        <tr>
                                            <th style="width: 70px; text-align:center;">Kode BC</th>
                                            <th style="width: 220px;">Nomor Pengajuan (AJU)</th>
                                            <th style="width: 130px; text-align:center;">No. / Tgl Daftar</th>
                                            <th style="width: 90px; text-align:center;">Kode Proses</th>
                                            <th style="width: 130px; text-align:center;">Waktu Status</th>
                                            <th style="width: 250px;">Keterangan Status Proses</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dtlTbodyStatus">
                                        <tr><td colspan="6" style="text-align:center; padding: 16px; color:var(--text-muted);">-</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 4. TAB PANEL: RESPON -->
                    <div class="tab-panel" id="panel-respon">
                        <div class="ant-table-wrapper" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                            <div class="detail-scroll">
                                <table class="ant-table excel-grid" id="dtlTableRespon" style="table-layout: fixed !important; width: 100%; min-width: 970px;">
                                    <colgroup>
                                        <col style="width: 220px;">
                                        <col style="width: 130px;">
                                        <col style="width: 90px;">
                                        <col style="width: 150px;">
                                        <col style="width: 130px;">
                                        <col style="width: 250px;">
                                    </colgroup>
                                    <thead class="ant-table-thead">
                                        <tr>
                                            <th style="width: 220px;">Nomor Pengajuan (AJU)</th>
                                            <th style="width: 130px; text-align:center;">No. / Tgl Daftar</th>
                                            <th style="width: 90px; text-align:center;">Kode Respon</th>
                                            <th style="width: 150px;">Nomor Respon</th>
                                            <th style="width: 130px; text-align:center;">Tanggal Respon</th>
                                            <th style="width: 250px;">Pesan / Respon Bea Cukai</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dtlTbodyRespon">
                                        <tr><td colspan="6" style="text-align:center; padding: 16px; color:var(--text-muted);">-</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- MODAL 1: COPY AJU (DEDICATED) -->
    <div class="modal-overlay" id="modalCopyAju">
        <div class="modal" style="max-width: 480px;">
            <div class="modal-header">
                <h3>&#128203; Copy Dokumen Pabean (Salin AJU)</h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalCopyAju')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <div style="background:var(--bg-filter); padding:8px; border-radius:3px; border:1px solid var(--border-card);">
                        <span style="color:var(--text-muted); font-size:9.5px;">Fitur ini akan menyalin seluruh data master header dan seluruh tabel rincian (barang, entitas, dokumen, kemasan, kontainer, pungutan, jaminan, dll) ke nomor AJU baru.</span>
                    </div>
                    <div>
                        <label style="font-weight:600; font-size:9.5px; color:var(--text-muted);">NOMOR AJU ASAL:</label>
                        <input type="text" id="copyOldAju" class="filter-control" style="width:100%; font-weight:700;" readonly>
                    </div>
                    <div>
                        <label style="font-weight:600; font-size:9.5px; color:var(--text-muted);">NOMOR AJU BARU (26 Digit):</label>
                        <input type="text" id="copyNewAju" class="filter-control" style="width:100%; font-weight:700;" maxlength="26" placeholder="Contoh: 00002000000120261015000001">
                    </div>
                    <div>
                        <label style="font-weight:600; font-size:9.5px; color:var(--text-muted);">TANGGAL PERNYATAAN BARU:</label>
                        <input type="date" id="copyNewTgl" class="filter-control" style="width:100%;" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalCopyAju')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitCopyAju()">&#128203; Proses Copy AJU</button>
            </div>
        </div>
    </div>

    <!-- MODAL 2: RENAME AJU (DEDICATED) -->
    <div class="modal-overlay" id="modalRenameAju">
        <div class="modal" style="max-width: 480px;">
            <div class="modal-header">
                <h3>&#9999;&#65039; Rename Nomor AJU (Ganti Nomor)</h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalRenameAju')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <div style="background:var(--bg-filter); padding:8px; border-radius:3px; border:1px solid var(--border-card);">
                        <span style="color:var(--text-muted); font-size:9.5px;">Fitur ini akan mengubah nomor pengajuan (AJU) di seluruh 18 tabel terkait dokumen ini (header, barang, dokumen, log, respons, posting, dll).</span>
                    </div>
                    <div>
                        <label style="font-weight:600; font-size:9.5px; color:var(--text-muted);">NOMOR AJU LAMA:</label>
                        <input type="text" id="renameOldAju" class="filter-control" style="width:100%; font-weight:700;" readonly>
                    </div>
                    <div>
                        <label style="font-weight:600; font-size:9.5px; color:var(--text-muted);">NOMOR AJU BARU (26 Digit):</label>
                        <input type="text" id="renameNewAju" class="filter-control" style="width:100%; font-weight:700;" maxlength="26" placeholder="Masukkan 26 digit nomor aju baru...">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalRenameAju')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitRenameAju()">&#9999;&#65039; Proses Rename AJU</button>
            </div>
        </div>
    </div>

    <!-- MODAL 3: PREVIEW JSON -->
    <div class="modal-overlay" id="modalPreviewJson">
        <div class="modal" style="max-width: 760px;">
            <div class="modal-header">
                <h3>&#128065;&#65039; Preview JSON Payload CEISA 4.0 (<span id="previewAjuTitle">-</span>)</h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalPreviewJson')">&times;</button>
            </div>
            <div class="modal-body">
                <pre class="code-view" id="jsonCodeView">Memuat data JSON...</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="copyJsonToClipboard()">Salin JSON</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="closeModal('modalPreviewJson')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- MODAL 4: HASIL TRANSFER -->
    <div class="modal-overlay" id="modalTransferResult">
        <div class="modal" style="max-width: 600px;">
            <div class="modal-header">
                <h3>&#128640; Hasil Transfer ke Portal CEISA 4.0</h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalTransferResult')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="transferResultContent" style="font-size:10.5px; line-height:1.5;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn ant-btn-primary" onclick="closeModal('modalTransferResult'); loadList();">Selesai</button>
            </div>
        </div>
    </div>

    <!-- MODAL 5: EDIT MANUAL RESPON (NOMOR DAFTAR, TANGGAL DAFTAR, SPPB) -->
    <div class="modal-overlay" id="modalManualRespon" style="z-index: 1050;">
        <div class="modal" style="max-width: 520px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background:#fafafa; border-bottom:1px solid #d9d9d9;">
                <h3 style="font-size:12px; font-weight:700; color:#1f1f1f; display:flex; align-items:center; gap:6px;">
                    <span>📝</span> Edit Manual Respon
                </h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalManualRespon')">&times;</button>
            </div>
            <div class="modal-body" style="padding:16px 20px;">
                <!-- Kandidat Auto-Detect dari CEISA Data -->
                <div id="boxCandidateCeisa" style="display:none; background:#f6ffed; border:1px solid #b7eb8f; border-radius:4px; padding:10px 12px; margin-bottom:14px; font-size:11px;">
                    <div style="font-weight:600; color:#389e0d; display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                        <span><i class="fa-solid fa-circle-check"></i> Data Terdeteksi di Sinkronisasi CEISA:</span>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="applyCandidateResponData()" style="height:22px; font-size:10px; padding:0 8px;">
                            ⚡ Gunakan Data Ini
                        </button>
                    </div>
                    <div id="txtCandidateCeisa" style="color:#274916; font-size:10.5px; line-height:1.4;"></div>
                </div>

                <div style="display:flex; flex-direction:column; gap:10px;">
                    <div style="display:grid; grid-template-columns: 120px 1fr; align-items:center; gap:8px;">
                        <label style="font-weight:600; font-size:11px; color:#595959;">Nomor AJU :</label>
                        <input type="text" id="manualResponAju" class="filter-control" style="background:#fffbe6; color:#874d00; font-weight:700; border-color:#ffe58f; cursor:not-allowed;" readonly>
                    </div>
                    <div style="display:grid; grid-template-columns: 120px 1fr; align-items:center; gap:8px;">
                        <label style="font-weight:600; font-size:11px; color:#595959;">Kode Dokumen :</label>
                        <input type="text" id="manualResponKodeBc" class="filter-control" style="background:#fffbe6; color:#874d00; font-weight:700; border-color:#ffe58f; width:100px; cursor:not-allowed;" readonly>
                    </div>
                    <div style="display:grid; grid-template-columns: 120px 1fr; align-items:center; gap:8px;">
                        <label style="font-weight:600; font-size:11px; color:#1f1f1f;">Nomor Daftar :</label>
                        <input type="text" id="manualResponNoDaftar" class="filter-control" placeholder="Contoh: 008487" style="font-weight:600;">
                    </div>
                    <div style="display:grid; grid-template-columns: 120px 1fr; align-items:center; gap:8px;">
                        <label style="font-weight:600; font-size:11px; color:#1f1f1f;">Tanggal Daftar :</label>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <input type="date" id="manualResponTglDaftar" class="filter-control" style="font-weight:600; flex:1;">
                            <button type="button" class="ant-btn ant-btn-sm" onclick="document.getElementById('manualResponTglDaftar').value = new Date().toISOString().substring(0,10)" title="Set Tanggal Hari Ini">Hari Ini</button>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns: 120px 1fr; align-items:center; gap:8px;">
                        <label style="font-weight:600; font-size:11px; color:#1f1f1f;">SPPB :</label>
                        <input type="text" id="manualResponSppb" class="filter-control" placeholder="Nomor Respon SPPB...">
                    </div>
                </div>

                <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #e8e8e8; display:flex; align-items:center; justify-content:space-between;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="fetchCeisaCandidateData(true)" style="font-size:10px;">
                        <span>🔄</span> Periksa Ulang dari BC_CEISA_DATA
                    </button>
                    <span style="font-size:9.5px; color:#8c8c8c;">*Nomor & Tanggal Daftar wajib diisi untuk IT Inventory</span>
                </div>
            </div>
            <div class="modal-footer" style="background:#fafafa; border-top:1px solid #d9d9d9; display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitManualRespon()" style="font-weight:600; min-width:80px;">Simpan</button>
                <button type="button" class="ant-btn" onclick="closeModal('modalManualRespon')" style="min-width:70px;">Batal</button>
            </div>
        </div>
    </div>

    <!-- CONTEXT MENU (DATAWINDOW STYLE) -->
    <div id="rowContextMenu" class="context-menu">
        <div class="context-menu-item" onclick="contextAction('edit')"><i class="fa-solid fa-pen-to-square" style="color:#1890ff; width:16px;"></i> Edit Dokumen</div>
        <div class="context-menu-item" onclick="contextAction('delete')"><i class="fa-solid fa-trash-can" style="color:#ff4d4f; width:16px;"></i> Hapus AJU</div>
        <div class="context-menu-divider"></div>
        <div class="context-menu-item" onclick="contextAction('sync')"><i class="fa-solid fa-cloud-arrow-down" style="color:#52c41a; width:16px;"></i> Tarik / Sync Respon</div>
        <div class="context-menu-item" onclick="contextAction('manual_respon')" style="font-weight:600; color:#1890ff;"><i class="fa-solid fa-file-pen" style="color:#1890ff; width:16px;"></i> Set Manual Respon</div>
        <div class="context-menu-divider"></div>
        <div class="context-menu-item" onclick="contextAction('post_it')"><i class="fa-solid fa-box-archive" style="color:#faad14; width:16px;"></i> Posting IT Inventory</div>
        <div class="context-menu-item" onclick="contextAction('unpost_it')"><i class="fa-solid fa-rotate-left" style="color:#ff7a45; width:16px;"></i> Unposting IT Inventory</div>
        <div class="context-menu-divider"></div>
        <div class="context-menu-item" onclick="contextAction('view_json')"><i class="fa-solid fa-code" style="color:#722ed1; width:16px;"></i> View JSON</div>
        <div class="context-menu-item" onclick="contextAction('copy_aju')"><i class="fa-solid fa-copy" style="color:#13c2c2; width:16px;"></i> Copy AJU</div>
        <div class="context-menu-item" onclick="contextAction('rename_aju')"><i class="fa-solid fa-i-cursor" style="color:#fa8c16; width:16px;"></i> Rename AJU</div>
    </div>

    <script>
        const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
        let rawHeaderData = [];
        let activeRowIdx = -1;
        let activeAju = '';
        let activeBc = '';
        let activeTab = 'barang';

        // -------------------------------------------------------------
        // RESIZABLE SPLITBAR LOGIC
        // -------------------------------------------------------------
        (function initSplitbar() {
            let isDragging = false;
            const splitbar = document.getElementById('splitbar');
            const topPane = document.getElementById('topPane');
            const container = document.getElementById('splitContainer');

            // Restore saved height from localStorage
            const savedH = localStorage.getItem('ceisa_transfer_top_h');
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
                localStorage.setItem('ceisa_transfer_top_h', newH);
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

        // Sinkronisasi tema & interaksi parent
        window.addEventListener('message', function(e) {
            if (!e.data || typeof e.data !== 'object') return;
            if (e.data.type === 'SET_THEME') {
                const th = e.data.theme;
                document.documentElement.setAttribute('data-theme', th);
                document.documentElement.style.colorScheme = th;
                document.body.setAttribute('data-theme', th);
            }
            if (e.data.type === 'SELECT_AJU') {
                if (typeof Swal !== 'undefined' && typeof Swal.close === 'function') {
                    try { Swal.close(); } catch(err) {}
                }
                if (e.data.aju) {
                    selectRowByAju(e.data.aju);
                }
            }
        });

        function onPeriodChange(periodStr) {
            if (!periodStr) return;
            const parts = periodStr.split('-');
            if (parts.length === 2) {
                const y = parseInt(parts[0], 10);
                const m = parseInt(parts[1], 10);
                const lastDay = new Date(y, m, 0).getDate();
                const padM = String(m).padStart(2, '0');
                document.getElementById('filterTgl1').value = `${y}-${padM}-01`;
                document.getElementById('filterTgl2').value = `${y}-${padM}-${String(lastDay).padStart(2, '0')}`;
                loadList();
            }
        }

        function resetFilters() {
            hasShownItInvAlert = false;
            document.getElementById('filterBc').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterSearch').value = '';
            const now = new Date();
            const y = now.getFullYear();
            const m = String(now.getMonth() + 1).padStart(2, '0');
            document.getElementById('filterPeriode').value = `${y}-${m}`;
            onPeriodChange(`${y}-${m}`);
        }

        async function loadList() {
            const tbody = document.getElementById('hdrTbody');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding: 20px; color:var(--text-muted);"><span class="spinner"></span> Memuat data dokumen...</td></tr>';

            const bc = document.getElementById('filterBc').value;
            const status = document.getElementById('filterStatus').value;
            const q = document.getElementById('filterSearch').value.trim();
            const periode = document.getElementById('filterPeriode').value;
            const tgl1 = document.getElementById('filterTgl1').value;
            const tgl2 = document.getElementById('filterTgl2').value;
            const cmpId = document.getElementById('filterCmp')?.value || '';

            const params = new URLSearchParams({
                action: 'list',
                bc: bc,
                status: status,
                q: q,
                periode: periode,
                tgl_awal: tgl1,
                tgl_akhir: tgl2,
                cmp_id: cmpId
            });

            try {
                const res = await fetch(`transfer.php?${params.toString()}`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding: 20px; color:#f5222d;">Gagal memuat data: ${json.message || 'Error'}</td></tr>`;
                    return;
                }

                rawHeaderData = json.data || [];
                activeCompanyIsInv = (json.is_inv !== undefined) ? parseInt(json.is_inv, 10) : 1;
                activeCompanyAlertItInv = (json.alert_it_inv !== undefined) ? parseInt(json.alert_it_inv, 10) : 0;
                renderHeaderTable();
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding: 20px; color:#f5222d;">Gagal memuat data: ${err.message}</td></tr>`;
            }
        }

        let activeCompanyIsInv = 1;
        let activeCompanyAlertItInv = 0;
        let hasShownItInvAlert = false;

        function renderHeaderTable() {
            const tbody = document.getElementById('hdrTbody');
            tbody.innerHTML = '';

            let cntUnposted = 0;
            let cntPosted = 0;
            let cntBelumItInvWithNoDaftar = 0;
            const shouldApplyInvColors = activeCompanyIsInv === 1;

            if (rawHeaderData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding: 24px; color:var(--text-muted);">Tidak ada data dokumen yang cocok dengan filter.</td></tr>';
                document.getElementById('kpiTotal').innerText = '0';
                document.getElementById('kpiUnposted').innerText = '0';
                document.getElementById('kpiPosted').innerText = '0';
                const chipBelum = document.getElementById('chipBelumItInv');
                if (chipBelum) chipBelum.style.display = 'none';
                clearDetailView();
                return;
            }

            rawHeaderData.forEach((row, idx) => {
                const isPosted = parseInt(row.posted || 0, 10) > 0;
                const isSubmitted = isPosted || (row.posted_waktu && row.posted_waktu !== '') || (row.namaProses && row.namaProses !== '') || (row.bcd_namaRespon && row.bcd_namaRespon !== '');
                const isItInv = parseInt(row.cekit || 0, 10) > 0;
                const hasNoDaftar = row.nomor_daftar && String(row.nomor_daftar).trim() !== '' && String(row.nomor_daftar).trim() !== '-';
                const applyInvColors = (row.is_inv !== undefined ? parseInt(row.is_inv, 10) : activeCompanyIsInv) === 1;

                if (isPosted) cntPosted++; else cntUnposted++;

                const tr = document.createElement('tr');
                tr.dataset.idx = idx;
                tr.dataset.aju = row.nomor_aju;

                let numCellClass = '';
                let ajuCellClass = '';

                // Aturan pewarnaan PowerBuilder (Hanya berlaku jika IS_INV = 1)
                if (applyInvColors) {
                    // Aturan 1: Jika sudah submit ke Ceisa, warna latar jadi BIRU
                    if (isSubmitted) {
                        tr.classList.add('row-submitted-ceisa');
                    }

                    // Aturan 2: Jika sudah masuk IT Inventory, Kolom Nomor AJU & nomor urut jadi KUNING
                    if (isItInv) {
                        numCellClass = 'cell-it-inv';
                        ajuCellClass = 'cell-it-inv';
                    }

                    // Aturan 3: Jika sudah ada nomor daftar tapi belum masuk IT Inventory, nomor urut jadi warna MERAH
                    if (hasNoDaftar && !isItInv) {
                        numCellClass = 'cell-no-it-inv';
                        cntBelumItInvWithNoDaftar++;
                    }
                }

                // Kolom nomor (#)
                const numCell = `<td class="${numCellClass}" style="text-align:center;">${idx + 1}</td>`;
                const ajuCell = `<td class="${ajuCellClass}" style="font-weight:600;">${escapeHtml(row.nomor_aju)}</td>`;

                // SPPB Cell Value (HANYA menampilkan nomor SPPB)
                const sppbVal = (row.sppb && String(row.sppb).trim() !== '' && String(row.sppb).trim() !== '-') 
                    ? String(row.sppb).trim() 
                    : (row.bcd_nomorRespon && String(row.bcd_nomorRespon).trim() !== '' && (row.bcd_namaRespon || '').toUpperCase().includes('SPPB') ? String(row.bcd_nomorRespon).trim() : '');
                
                const sppbDisplay = sppbVal !== '' 
                    ? `<span style="font-family:inherit; font-weight:600;">${escapeHtml(sppbVal)}</span>` 
                    : `<span style="color:inherit; opacity:0.35;">-</span>`;

                // Status Cell Value (Badge status dokumen)
                let statusBadge = '';
                if (isSubmitted) {
                    if (row.bcd_namaRespon && String(row.bcd_namaRespon).trim() !== '') {
                        const respName = String(row.bcd_namaRespon).trim();
                        let tagCls = 'ant-tag-blue';
                        const upper = respName.toUpperCase();
                        if (upper.includes('REJECT') || upper.includes('TOLAK')) {
                            tagCls = 'ant-tag-red';
                        } else if (upper.includes('SPPB') || upper.includes('SELESAI')) {
                            tagCls = 'ant-tag-green';
                        } else if (upper.includes('BILLING') || upper.includes('BAYAR')) {
                            tagCls = 'ant-tag-gold';
                        }
                        statusBadge = `<span class="ant-tag ${tagCls}" title="${escapeHtml(respName)}">${escapeHtml(respName)}</span>`;
                    } else if (row.namaProses && String(row.namaProses).trim() !== '') {
                        statusBadge = `<span class="ant-tag ant-tag-blue" title="${escapeHtml(row.namaProses)}">${escapeHtml(row.namaProses)}</span>`;
                    } else {
                        statusBadge = `<span class="ant-tag ant-tag-green">Terkirim</span>`;
                    }
                } else {
                    statusBadge = `<span class="ant-tag ant-tag-gold">Belum Kirim</span>`;
                }

                const noDaftarStr = row.nomor_daftar ? escapeHtml(row.nomor_daftar) : '-';
                const tglDaftarStr = row.tanggal_daftar ? row.tanggal_daftar.substring(0, 10) : '-';
                const tglAjuStr = row.tanggal_pernyataan ? row.tanggal_pernyataan.substring(0, 10) : '-';

                tr.innerHTML = `
                    ${numCell}
                    <td style="text-align:center;"><span class="ant-tag ant-tag-blue">BC ${escapeHtml(row.bc)}</span></td>
                    ${ajuCell}
                    <td style="text-align:center;">${tglAjuStr}</td>
                    <td style="text-align:center;">${noDaftarStr}</td>
                    <td style="text-align:center;">${tglDaftarStr}</td>
                    <td title="${escapeHtml(row.bs || '')}">${escapeHtml(row.bs || '-')}</td>
                    <td title="${escapeHtml(row.invoice || '')}">${escapeHtml(row.invoice || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(row.kode_valuta || 'IDR')}</td>
                    <td style="text-align:right;">${parseInt(row.jml_barang || 0, 10)}</td>
                    <td style="text-align:right;">${parseFloat(row.bruto || 0).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 4})}</td>
                    <td title="${escapeHtml(sppbVal)}">${sppbDisplay}</td>
                    <td style="text-align:center;">${statusBadge}</td>
                `;

                tr.addEventListener('click', () => selectRow(idx));
                tr.addEventListener('dblclick', () => editActiveAju());
                tr.addEventListener('contextmenu', (e) => showRowContextMenu(e, idx));

                tbody.appendChild(tr);
            });

            document.getElementById('kpiTotal').innerText = rawHeaderData.length;
            document.getElementById('kpiUnposted').innerText = cntUnposted;
            document.getElementById('kpiPosted').innerText = cntPosted;

            const chipBelum = document.getElementById('chipBelumItInv');
            if (chipBelum) {
                if (shouldApplyInvColors && cntBelumItInvWithNoDaftar > 0) {
                    chipBelum.style.display = 'inline-flex';
                    document.getElementById('cntBelumItInv').innerText = cntBelumItInvWithNoDaftar;
                } else {
                    chipBelum.style.display = 'none';
                }
            }

            // Alert SweetAlert jika ada dokumen sudah ada nomor daftar tapi belum masuk IT Inventory
            // Default: OFF (hanya muncul jika diaktifkan di Pengaturan Perusahaan)
            if (activeCompanyAlertItInv === 1 && shouldApplyInvColors && cntBelumItInvWithNoDaftar > 0 && !hasShownItInvAlert) {
                hasShownItInvAlert = true;
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan IT Inventory',
                    html: `Terdapat <b style="color:#ff4d4f; font-size:16px;">${cntBelumItInvWithNoDaftar}</b> dokumen sudah memiliki <b>Nomor Daftar</b> tetapi <b>belum masuk IT Inventory</b>.<br><span style="font-size:12px; color:var(--text-muted);">Nomor urut dokumen ditandai dengan warna <b style="color:#ff4d4f;">MERAH</b>.</span>`,
                    confirmButtonText: 'Saring Dokumen Ini',
                    showCancelButton: true,
                    cancelButtonText: 'Tutup',
                    confirmButtonColor: '#ff4d4f'
                }).then((result) => {
                    if (result.isConfirmed) {
                        filterBelumItInv();
                    }
                });
            }

            // Otomatis pilih baris pertama jika ada
            if (rawHeaderData.length > 0) {
                selectRow(0);
            }
        }

        function filterBelumItInv() {
            const sel = document.getElementById('filterStatus');
            if (sel) {
                sel.value = 'not_it_inv';
                hasShownItInvAlert = true;
                loadList();
            }
        }

        function selectRow(idx) {
            if (idx < 0 || idx >= rawHeaderData.length) return;
            activeRowIdx = idx;
            const row = rawHeaderData[idx];
            activeAju = row.nomor_aju;
            activeBc = row.bc;

            // Highlight baris secara serentak
            document.querySelectorAll('#hdrTbody tr').forEach(r => r.classList.remove('selected-row'));
            const activeTr = document.querySelector(`#hdrTbody tr[data-idx="${idx}"]`);
            if (activeTr) {
                activeTr.classList.add('selected-row');
            }

            // Update title dokumen terpilih
            const itTag = parseInt(row.cekit || 0, 10) > 0 ? ' [&#128994; IT Inventory - Terkunci]' : '';
            document.getElementById('detailAjuTitle').innerHTML = `[BC ${row.bc}] ${row.nomor_aju} &mdash; ${escapeHtml(row.cmp_name || row.bs || '-')}${itTag}`;

            // Panggil detail tab aktif
            loadActiveDetailTab();
        }

        function clearDetailView() {
            activeRowIdx = -1;
            activeAju = '';
            activeBc = '';
            document.getElementById('detailAjuTitle').innerText = '-';
            document.getElementById('cnt-barang').innerText = '0';
            document.getElementById('cnt-dokumen').innerText = '0';
            document.getElementById('cnt-status').innerText = '0';
            document.getElementById('cnt-respon').innerText = '0';
            document.getElementById('dtlTbodyBarang').innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">-</td></tr>';
            document.getElementById('dtlTbodyDokumen').innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">-</td></tr>';
            document.getElementById('dtlTbodyStatus').innerHTML = '<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--text-muted);">-</td></tr>';
            document.getElementById('dtlTbodyRespon').innerHTML = '<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--text-muted);">-</td></tr>';
        }

        function switchDetailTab(tabName) {
            activeTab = tabName;
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(pnl => pnl.classList.remove('active'));

            const btn = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
            if (btn) btn.classList.add('active');

            const pnl = document.getElementById(`panel-${tabName}`);
            if (pnl) pnl.classList.add('active');

            if (activeAju) {
                loadActiveDetailTab();
            }
        }

        async function loadActiveDetailTab() {
            if (!activeAju) return;

            try {
                const res = await fetch(`transfer.php?action=detail_tab&nomor_aju=${encodeURIComponent(activeAju)}&tab=${encodeURIComponent(activeTab)}`);
                const json = await res.json();
                if (json.status !== 'OK') return;

                const countBadge = document.getElementById(`cnt-${activeTab}`);
                if (countBadge) countBadge.innerText = json.count || 0;

                if (activeTab === 'barang') {
                    renderDetailBarang(json.data || [], json.totals);
                } else if (activeTab === 'dokumen') {
                    renderDetailDokumen(json.data || []);
                } else if (activeTab === 'status') {
                    renderDetailStatus(json.data || []);
                } else if (activeTab === 'respon') {
                    renderDetailRespon(json.data || []);
                }
            } catch (err) {
                console.error("Gagal load detail tab:", err);
            }
        }

        function renderDetailBarang(data, totals) {
            const tbody = document.getElementById('dtlTbodyBarang');
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada data barang pada dokumen ini.</td></tr>';
            } else {
                data.forEach((r, idx) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="text-align:center; color:var(--text-muted);">${r.seri_barang || idx + 1}</td>
                        <td style="font-weight:600;" title="${escapeHtml(r.kode_barang || '')}">${escapeHtml(r.kode_barang || '-')}</td>
                        <td title="${escapeHtml(r.uraian || '')}">${escapeHtml(r.uraian || '-')}</td>
                        <td style="text-align:right; font-weight:600;">${parseFloat(r.jumlah_satuan || 0).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 4})}</td>
                        <td style="text-align:center;">${escapeHtml(r.kode_satuan || '-')}</td>
                        <td><span class="ant-tag">${escapeHtml(r.klas || 'Finished Goods')}</span></td>
                        <td style="text-align:right;">${parseFloat(r.harga_penyerahan || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align:right;">${parseFloat(r.cif || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td style="text-align:right;">${parseFloat(r.netto || 0).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 4})}</td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            if (totals) {
                document.getElementById('sumJml').innerText = parseFloat(totals.jumlah || 0).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 4});
                document.getElementById('sumPenyerahan').innerText = parseFloat(totals.harga_penyerahan || 0).toLocaleString('id-ID', {minimumFractionDigits: 2});
                document.getElementById('sumCif').innerText = parseFloat(totals.cif || 0).toLocaleString('id-ID', {minimumFractionDigits: 2});
                document.getElementById('sumNetto').innerText = parseFloat(totals.netto || 0).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 4}) + ' kg';
            }
        }

        function renderDetailDokumen(data) {
            const tbody = document.getElementById('dtlTbodyDokumen');
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);">Tidak ada dokumen pelengkap.</td></tr>';
                return;
            }
            data.forEach((r, idx) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="text-align:center; color:var(--text-muted);">${r.seri || idx + 1}</td>
                    <td style="text-align:center;"><span class="ant-tag ant-tag-blue">${escapeHtml(r.kode_dokumen || '-')}</span></td>
                    <td style="font-weight:600;" title="${escapeHtml(r.nama_dokumen || '')}">${escapeHtml(r.nama_dokumen || '-')}</td>
                    <td title="${escapeHtml(r.nomor_dokumen || '')}">${escapeHtml(r.nomor_dokumen || '-')}</td>
                    <td style="text-align:center;">${r.tanggal_dokumen ? r.tanggal_dokumen.substring(0, 10) : '-'}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function renderDetailStatus(data) {
            const tbody = document.getElementById('dtlTbodyStatus');
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--text-muted);">Belum ada catatan status pabean.</td></tr>';
                return;
            }
            data.forEach(r => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="text-align:center;"><span class="ant-tag">BC ${escapeHtml(r.kode_dokumen || '-')}</span></td>
                    <td title="${escapeHtml(r.nomor_aju || '')}">${escapeHtml(r.nomor_aju || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.nomor_daftar || '-')}</td>
                    <td style="text-align:center;"><span class="ant-tag ant-tag-gold">${escapeHtml(r.kode_proses || '-')}</span></td>
                    <td style="text-align:center;">${escapeHtml(r.waktu_status || '-')}</td>
                    <td title="${escapeHtml(r.keterangan || '')}">${escapeHtml(r.keterangan || '-')}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function renderDetailRespon(data) {
            const tbody = document.getElementById('dtlTbodyRespon');
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--text-muted);">Belum ada respon resmi CEISA.</td></tr>';
                return;
            }
            data.forEach(r => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td title="${escapeHtml(r.nomor_aju || '')}">${escapeHtml(r.nomor_aju || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.nomor_daftar || '-')}</td>
                    <td style="text-align:center;"><span class="ant-tag ant-tag-blue">${escapeHtml(r.kode_respon || '-')}</span></td>
                    <td style="font-weight:600;" title="${escapeHtml(r.nomor_respon || '')}">${escapeHtml(r.nomor_respon || '-')}</td>
                    <td style="text-align:center;">${escapeHtml(r.tanggal_respon || '-')}</td>
                    <td title="${escapeHtml(r.pesan || '')}">${escapeHtml(r.pesan || '-')}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        // Navigasi Keyboard Arrow Up & Down
        window.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (activeRowIdx < rawHeaderData.length - 1) {
                    selectRow(activeRowIdx + 1);
                    scrollActiveRowIntoView();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (activeRowIdx > 0) {
                    selectRow(activeRowIdx - 1);
                    scrollActiveRowIntoView();
                }
            }
        });

        function scrollActiveRowIntoView() {
            const tr = document.querySelector(`#hdrTbody tr[data-idx="${activeRowIdx}"]`);
            if (tr) {
                tr.scrollIntoView({ block: 'nearest' });
            }
        }

        function selectRowByAju(nomorAju) {
            if (!nomorAju || !Array.isArray(rawHeaderData)) return;
            const idx = rawHeaderData.findIndex(r => String(r.nomor_aju || '').trim() === String(nomorAju || '').trim());
            if (idx !== -1) {
                selectRow(idx);
                scrollActiveRowIntoView();
            }
        }

        // -------------------------------------------------------------
        // ACTION BUTTON HANDLERS
        // -------------------------------------------------------------
        function editActiveAju() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen terlebih dahulu.');
                return;
            }
            openEditorForm(activeAju);
        }

        function openEditorForm(nomorAju) {
            if (typeof Swal !== 'undefined' && typeof Swal.close === 'function') {
                try { Swal.close(); } catch(err) {}
            }
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('editor', nomorAju || '');
            } else {
                const url = nomorAju ? `/editor.php?nomor_aju=${encodeURIComponent(nomorAju)}` : '/editor.php';
                window.location.href = url;
            }
        }

        async function setH2HActiveAju() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen terlebih dahulu.');
                return;
            }
            const ok = await showConfirm(
                `Apakah Anda yakin ingin mengatur status dokumen AJU <strong>${escapeHtml(activeAju)}</strong> ke H2H (Siap Kirim)?`,
                'Konfirmasi Set H2H',
                'Ya, Set H2H',
                'Batal',
                'question'
            );
            if (!ok) return;

            const formData = new FormData();
            formData.append('action', 'set_h2h');
            formData.append('nomor_aju', activeAju);
            try {
                const res = await fetch('transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    loadList();
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Koneksi gagal: ' + err.message);
            }
        }

        async function postItInventoryActiveAju(targetAju) {
            const ajuToPost = targetAju || activeAju;
            if (!ajuToPost) {
                showWarning('Silakan pilih salah satu dokumen terlebih dahulu.');
                return;
            }

            // Validasi: Nomor Daftar dan Tanggal Daftar WAJIB TERISI!
            const activeItem = rawHeaderData.find(d => d.nomor_aju === ajuToPost);
            const noDaftar = activeItem ? (activeItem.nomor_daftar || '').trim() : '';
            const tglDaftar = activeItem ? (activeItem.tanggal_daftar || '').trim() : '';

            if (!noDaftar || noDaftar === '-' || !tglDaftar || tglDaftar === '-' || tglDaftar.startsWith('0000-00-00')) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Nomor / Tanggal Daftar Belum Terisi!',
                    html: `Dokumen AJU <strong>${escapeHtml(ajuToPost)}</strong> belum memiliki <b>Nomor Daftar</b> dan/atau <b>Tanggal Daftar</b>.<br><br>` +
                          `Sesuai ketentuan, Nomor Daftar dan Tanggal Daftar <b>WAJIB</b> terisi sebelum dapat diposting ke IT Inventory.<br><br>` +
                          `Apakah Anda ingin mengisi manual sekarang?`,
                    showCancelButton: true,
                    confirmButtonText: '📝 Ya, Buka Set Manual Respon',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#1890ff',
                    cancelButtonColor: '#8c8c8c'
                }).then((res) => {
                    if (res.isConfirmed) {
                        openManualResponModal(ajuToPost);
                    }
                });
                return;
            }

            const ok = await showConfirm(
                `Apakah Anda yakin ingin melakukan Posting dokumen AJU <strong>${escapeHtml(ajuToPost)}</strong> ke IT Inventory${isAdmin ? ' (bc_1ab2)' : ''}?<br><br><small style="color:var(--text-muted);">Setelah diposting, dokumen akan terkunci dari pengeditan (Read-Only).</small>`,
                'Konfirmasi Posting IT Inventory',
                'Ya, Posting Sekarang',
                'Batal',
                'question'
            );
            if (!ok) return;

            const formData = new FormData();
            formData.append('action', 'post_it');
            formData.append('nomor_aju', ajuToPost);
            try {
                const res = await fetch('transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message, 'Berhasil Diposting');
                    loadList();
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Koneksi gagal: ' + err.message);
            }
        }

        // -------------------------------------------------------------
        // CONTEXT MENU (DATAWINDOW / POWERBUILDER STYLE)
        // -------------------------------------------------------------
        let contextTargetIdx = -1;
        let contextTargetAju = '';

        function showRowContextMenu(e, idx) {
            e.preventDefault();
            selectRow(idx);
            contextTargetIdx = idx;
            contextTargetAju = rawHeaderData[idx] ? rawHeaderData[idx].nomor_aju : '';

            const menu = document.getElementById('rowContextMenu');
            if (!menu) return;

            menu.style.display = 'block';
            const menuRect = menu.getBoundingClientRect();
            let posX = e.clientX;
            let posY = e.clientY;

            if (posX + menuRect.width > window.innerWidth) {
                posX = window.innerWidth - menuRect.width - 10;
            }
            if (posY + menuRect.height > window.innerHeight) {
                posY = window.innerHeight - menuRect.height - 10;
            }

            menu.style.left = `${posX}px`;
            menu.style.top = `${posY}px`;
        }

        function closeRowContextMenu() {
            const menu = document.getElementById('rowContextMenu');
            if (menu) {
                menu.style.display = 'none';
            }
        }

        document.addEventListener('click', function(e) {
            const menu = document.getElementById('rowContextMenu');
            if (menu && menu.style.display === 'block') {
                if (!menu.contains(e.target)) {
                    closeRowContextMenu();
                }
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRowContextMenu();
            }
        });

        function contextAction(action) {
            closeRowContextMenu();
            const targetAju = contextTargetAju || activeAju;
            if (!targetAju) {
                showWarning('Silakan pilih salah satu dokumen terlebih dahulu.');
                return;
            }

            switch(action) {
                case 'edit':
                    openEditorForm(targetAju);
                    break;
                case 'delete':
                    deleteActiveAju();
                    break;
                case 'sync':
                    syncActiveAju(targetAju);
                    break;
                case 'manual_respon':
                    openManualResponModal(targetAju);
                    break;
                case 'post_it':
                    postItInventoryActiveAju(targetAju);
                    break;
                case 'unpost_it':
                    unpostItInventoryActiveAju();
                    break;
                case 'view_json':
                    viewJsonActiveAju();
                    break;
                case 'copy_aju':
                    openCopyModal();
                    break;
                case 'rename_aju':
                    openRenameModal();
                    break;
            }
        }

        async function syncActiveAju(targetAju) {
            const aju = targetAju || activeAju;
            if (!aju) {
                showWarning('Pilih dokumen terlebih dahulu.');
                return;
            }
            Swal.fire({
                title: 'Tarik / Sync Respon CEISA',
                html: `Menghubungi portal CEISA untuk memeriksa respon dokumen AJU:<br><b>${escapeHtml(aju)}</b>...`,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            try {
                const res = await fetch(`statusaju.php?aju=${encodeURIComponent(aju)}`);
                const text = await res.text();
                if (text.trim() === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Berhasil!',
                        text: 'Status dan respon dokumen berhasil diperbarui dari CEISA 4.0.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    loadList();
                    if (activeAju === aju) {
                        loadActiveDetailTab();
                    }
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'Hasil Respon CEISA',
                        html: `<div style="max-height:200px; overflow-y:auto; font-size:11px; text-align:left; background:#f5f5f5; padding:8px; border-radius:4px;">${escapeHtml(text)}</div>`
                    });
                    loadList();
                }
            } catch (err) {
                showError('Terjadi kesalahan jaringan: ' + err.message);
            }
        }

        // -------------------------------------------------------------
        // SET MANUAL RESPON & UPDATE DARI CEISA
        // -------------------------------------------------------------
        let currentResponCandidate = null;

        function openManualResponModal(targetAju) {
            const aju = targetAju || activeAju;
            if (!aju) {
                showWarning('Silakan pilih salah satu dokumen terlebih dahulu.');
                return;
            }

            // Reset modal values
            document.getElementById('manualResponAju').value = aju;
            document.getElementById('manualResponKodeBc').value = activeBc ? `BC ${activeBc}` : '-';
            document.getElementById('manualResponNoDaftar').value = '';
            document.getElementById('manualResponTglDaftar').value = '';
            document.getElementById('manualResponSppb').value = '';
            document.getElementById('boxCandidateCeisa').style.display = 'none';
            document.getElementById('txtCandidateCeisa').innerHTML = '';
            currentResponCandidate = null;

            document.getElementById('modalManualRespon').style.display = 'flex';
            fetchManualRespon(aju);
        }

        async function fetchManualRespon(aju, manualCheck = false) {
            try {
                const res = await fetch(`transfer.php?action=get_manual_respon&nomor_aju=${encodeURIComponent(aju)}`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    if (manualCheck) showError(json.message);
                    return;
                }

                const d = json.data;
                document.getElementById('manualResponAju').value = d.nomor_aju || aju;
                document.getElementById('manualResponKodeBc').value = d.kode_dokumen ? `BC ${d.kode_dokumen}` : '-';
                
                // Jika input masih kosong, isi dengan data saat ini
                if (!document.getElementById('manualResponNoDaftar').value) {
                    document.getElementById('manualResponNoDaftar').value = d.nomor_daftar || '';
                }
                if (!document.getElementById('manualResponTglDaftar').value) {
                    document.getElementById('manualResponTglDaftar').value = d.tanggal_daftar || '';
                }
                if (!document.getElementById('manualResponSppb').value) {
                    document.getElementById('manualResponSppb').value = d.sppb || '';
                }

                // Cek kandidat dari sync CEISA
                if (d.candidate && d.candidate.has_candidate) {
                    currentResponCandidate = d.candidate;
                    const c = d.candidate;
                    const box = document.getElementById('boxCandidateCeisa');
                    const txt = document.getElementById('txtCandidateCeisa');
                    
                    txt.innerHTML = `
                        No. Daftar: <b>${escapeHtml(c.nomor_daftar || '-')}</b> &nbsp;|&nbsp; 
                        Tgl Daftar: <b>${escapeHtml(c.tanggal_daftar || '-')}</b> &nbsp;|&nbsp; 
                        SPPB: <b>${escapeHtml(c.sppb || '-')}</b><br>
                        <span style="font-size:9.5px; opacity:0.8;">Sumber: ${escapeHtml(c.source || 'Sinkronisasi CEISA')}</span>
                    `;
                    box.style.display = 'block';

                    if (manualCheck) {
                        showSuccess('Data respon terdeteksi dari tabel CEISA!', 'Ditemukan');
                    }
                } else {
                    document.getElementById('boxCandidateCeisa').style.display = 'none';
                    if (manualCheck) {
                        showInfo('Tidak ada data respon tambahan di bc_ceisa_data atau riwayat SPPB untuk AJU ini.', 'Info');
                    }
                }
            } catch (err) {
                if (manualCheck) showError('Gagal mengambil data respon: ' + err.message);
            }
        }

        function fetchCeisaCandidateData(manualClick = false) {
            const aju = document.getElementById('manualResponAju').value.trim();
            if (aju) {
                fetchManualRespon(aju, manualClick);
            }
        }

        function applyCandidateResponData() {
            if (!currentResponCandidate) return;
            if (currentResponCandidate.nomor_daftar) {
                document.getElementById('manualResponNoDaftar').value = currentResponCandidate.nomor_daftar;
            }
            if (currentResponCandidate.tanggal_daftar) {
                document.getElementById('manualResponTglDaftar').value = currentResponCandidate.tanggal_daftar;
            }
            if (currentResponCandidate.sppb) {
                document.getElementById('manualResponSppb').value = currentResponCandidate.sppb;
            }
            showSuccess('Data respon CEISA telah dimasukkan ke formulir input. Silakan klik "Simpan".', 'Data Diterapkan');
        }

        async function submitManualRespon() {
            const nomor_aju = document.getElementById('manualResponAju').value.trim();
            const nomor_daftar = document.getElementById('manualResponNoDaftar').value.trim();
            const tanggal_daftar = document.getElementById('manualResponTglDaftar').value.trim();
            const sppb = document.getElementById('manualResponSppb').value.trim();

            if (!nomor_aju) {
                showWarning('Nomor AJU tidak valid.');
                return;
            }
            if (!nomor_daftar || nomor_daftar === '-') {
                showWarning('Nomor Daftar wajib diisi (tidak boleh kosong atau "-").');
                document.getElementById('manualResponNoDaftar').focus();
                return;
            }
            if (!tanggal_daftar || tanggal_daftar === '-' || tanggal_daftar.startsWith('0000-00-00')) {
                showWarning('Tanggal Daftar wajib diisi dengan tanggal yang valid.');
                document.getElementById('manualResponTglDaftar').focus();
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save_manual_respon');
            formData.append('nomor_aju', nomor_aju);
            formData.append('nomor_daftar', nomor_daftar);
            formData.append('tanggal_daftar', tanggal_daftar);
            formData.append('sppb', sppb);

            try {
                const res = await fetch('transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message, 'Manual Respon Disimpan');
                    closeModal('modalManualRespon');
                    loadList();
                    if (activeAju === nomor_aju) {
                        loadActiveDetailTab();
                    }
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Koneksi gagal: ' + err.message);
            }
        }

        async function unpostItInventoryActiveAju() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen terlebih dahulu.');
                return;
            }
            const ok = await showConfirm(
                `Lakukan Unposting dokumen AJU <strong>${escapeHtml(activeAju)}</strong> dari IT Inventory?<br><br><small style="color:var(--text-muted);">Data akan diarsipkan ke riwayat revisi${isAdmin ? ' (bc_1ab2_revisi)' : ''} dan kunci edit dokumen akan dibuka kembali.</small>`,
                'Konfirmasi Unposting IT Inventory',
                'Ya, Unposting Dokumen',
                'Batal',
                'warning'
            );
            if (!ok) return;

            const formData = new FormData();
            formData.append('action', 'unpost_it');
            formData.append('nomor_aju', activeAju);
            try {
                const res = await fetch('transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message, 'Berhasil Unposting');
                    loadList();
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Koneksi gagal: ' + err.message);
            }
        }

        async function viewJsonActiveAju() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen terlebih dahulu.');
                return;
            }
            document.getElementById('previewAjuTitle').innerText = activeAju;
            document.getElementById('jsonCodeView').innerText = 'Membuat JSON payload...';
            document.getElementById('modalPreviewJson').style.display = 'flex';

            const cmpId = document.getElementById('filterCmp')?.value || '';
            try {
                const res = await fetch(`transfer.php?action=preview_json&nomor_aju=${encodeURIComponent(activeAju)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                document.getElementById('jsonCodeView').innerText = JSON.stringify(json, null, 2);
            } catch (err) {
                document.getElementById('jsonCodeView').innerText = 'Gagal memuat JSON: ' + err.message;
            }
        }

        function copyJsonToClipboard() {
            const txt = document.getElementById('jsonCodeView').innerText;
            navigator.clipboard.writeText(txt).then(() => {
                showSuccess('JSON payload berhasil disalin ke clipboard!', 'Tersalin');
            }).catch(() => {
                showInfo('Salin teks JSON secara manual dari kotak preview.');
            });
        }

        // Modal Copy AJU (Dedicated)
        function openCopyModal() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen yang akan di-copy.');
                return;
            }
            document.getElementById('copyOldAju').value = activeAju;
            document.getElementById('copyNewAju').value = '';
            document.getElementById('modalCopyAju').style.display = 'flex';
        }

        async function submitCopyAju() {
            const oldAju = document.getElementById('copyOldAju').value;
            const newAju = document.getElementById('copyNewAju').value.trim();
            const newTgl = document.getElementById('copyNewTgl').value;

            if (!newAju) {
                showWarning('Nomor AJU baru harus diisi.');
                return;
            }
            if (newAju === oldAju) {
                showWarning('Nomor AJU baru harus berbeda dari nomor AJU asal.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'copy_aju');
            formData.append('old_aju', oldAju);
            formData.append('new_aju', newAju);
            formData.append('new_tgl', newTgl);

            try {
                const res = await fetch('transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    closeModal('modalCopyAju');
                    loadList();
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Terjadi kesalahan: ' + err.message);
            }
        }

        // Modal Rename AJU (Dedicated)
        function openRenameModal() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen yang akan di-rename.');
                return;
            }
            document.getElementById('renameOldAju').value = activeAju;
            document.getElementById('renameNewAju').value = '';
            document.getElementById('modalRenameAju').style.display = 'flex';
        }

        async function submitRenameAju() {
            const oldAju = document.getElementById('renameOldAju').value;
            const newAju = document.getElementById('renameNewAju').value.trim();

            if (!newAju) {
                showWarning('Nomor AJU baru harus diisi.');
                return;
            }
            if (newAju === oldAju) {
                showWarning('Nomor AJU baru harus berbeda dari nomor AJU lama.');
                return;
            }

            const ok = await showConfirm(
                `Yakin ingin mengubah Nomor AJU dari:<br><code>${escapeHtml(oldAju)}</code><br>menjadi:<br><code>${escapeHtml(newAju)}</code>?`,
                'Konfirmasi Rename AJU',
                'Ya, Ganti Nomor AJU',
                'Batal',
                'warning'
            );
            if (!ok) return;

            const formData = new FormData();
            formData.append('action', 'rename_aju');
            formData.append('old_aju', oldAju);
            formData.append('new_aju', newAju);

            try {
                const res = await fetch('transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    closeModal('modalRenameAju');
                    loadList();
                } else {
                    showError(json.message);
                }
            } catch (err) {
                showError('Terjadi kesalahan: ' + err.message);
            }
        }

        async function deleteActiveAju() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen AJU yang akan dihapus.');
                return;
            }

            // Validasi apakah dokumen sudah terkunci (masuk IT Inventory)
            const activeItem = rawHeaderData.find(d => d.nomor_aju === activeAju);
            if (activeItem && parseInt(activeItem.cekit || 0, 10) > 0) {
                showWarning(
                    `Dokumen AJU <strong>${escapeHtml(activeAju)}</strong> tidak dapat dihapus karena sudah dikunci (masuk ke IT Inventory).<br><br><small style="color:var(--text-muted);">Silakan lakukan <strong>Unposting IT Inventory</strong> terlebih dahulu untuk membuka kunci dokumen ini.</small>`,
                    'AJU Terkunci (IT Inventory)'
                );
                return;
            }

            Swal.fire({
                title: '⚠️ Konfirmasi Hapus Data AJU',
                html: `
                    <div style="font-size:13px; text-align:left; line-height:1.5;">
                        <p>Anda akan <strong>MENGHAPUS PERMANEN</strong> seluruh data staging dokumen ini:</p>
                        <div style="background:rgba(255, 77, 79, 0.08); padding:8px 12px; border-radius:6px; border:1px solid rgba(255, 77, 79, 0.2); margin:10px 0; font-family:monospace; font-size:13px; font-weight:700; color:#ff4d4f;">
                            AJU: ${escapeHtml(activeAju)}
                        </div>
                        <p style="color:var(--text-muted, #666); font-size:12px;">Data tabel Header, Barang, Entitas, Dokumen, Kemasan, Pengangkut, Kontainer, Pungutan, dan respon pabean terkait akan dibersihkan dari database.</p>
                        <div style="margin-top:12px; font-weight:600; color:#ff4d4f;">
                            Ketik <span style="background:#ff4d4f; color:#fff; padding:1px 6px; border-radius:3px; letter-spacing:1px;">YA</span> untuk mengonfirmasi:
                        </div>
                    </div>
                `,
                input: 'text',
                inputPlaceholder: 'Ketik YA di sini...',
                inputAttributes: {
                    autocapitalize: 'characters',
                    autocomplete: 'off',
                    spellcheck: 'false'
                },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🗑️ Hapus Data AJU',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                inputValidator: (val) => {
                    if (!val || val.trim().toUpperCase() !== 'YA') {
                        return 'Wajib mengetik "YA" dengan benar untuk konfirmasi penghapusan.';
                    }
                },
                preConfirm: (val) => {
                    const formData = new FormData();
                    formData.append('action', 'delete_aju');
                    formData.append('nomor_aju', activeAju);
                    formData.append('confirm', val.trim());

                    return fetch('transfer.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status !== 'OK') {
                                throw new Error(res.message || 'Gagal menghapus data AJU.');
                            }
                            return res;
                        })
                        .catch(err => {
                            Swal.showValidationMessage(`${err.message}`);
                        });
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        title: 'Berhasil Dihapus',
                        html: result.value.message || 'Data dokumen AJU berhasil dihapus.',
                        icon: 'success',
                        confirmButtonColor: '#1890ff'
                    });
                    activeAju = null;
                    const gridDetail = document.getElementById('gridDetail');
                    if (gridDetail) gridDetail.innerHTML = '<div class="empty-state">Pilih dokumen pabean untuk melihat rincian</div>';
                    const selBanner = document.getElementById('selectedDocBanner');
                    if (selBanner) selBanner.innerHTML = 'Dokumen Terpilih: <em>Belum ada yang dipilih</em>';
                    loadList();
                }
            });
        }

        async function transferActiveAju() {
            if (!activeAju) {
                showWarning('Silakan pilih salah satu dokumen yang akan ditransfer.');
                return;
            }

            const ok = await showConfirm(
                `Apakah Anda yakin ingin mentransfer dokumen AJU <strong>${escapeHtml(activeAju)}</strong> ke Portal CEISA 4.0?`,
                'Konfirmasi Transfer CEISA 4.0',
                'Ya, Transfer Sekarang',
                'Batal',
                'info'
            );
            if (!ok) return;

            const content = document.getElementById('transferResultContent');
            content.innerHTML = '<div style="text-align:center; padding:20px;"><span class="spinner"></span> Mengirim payload ke CEISA 4.0... Mohon tunggu...</div>';
            document.getElementById('modalTransferResult').style.display = 'flex';

            const cmpId = document.getElementById('filterCmp')?.value || '';
            const formData = new FormData();
            formData.append('action', 'transfer');
            formData.append('nomor_aju', activeAju);
            formData.append('cmp_id', cmpId);

            try {
                const res = await fetch('transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    content.innerHTML = `
                        <div style="color:#52c41a; font-weight:700; margin-bottom:8px;">&#9989; Berhasil Dikirim ke CEISA 4.0!</div>
                        <p><strong>Nomor AJU:</strong> ${escapeHtml(json.nomor_aju)}</p>
                        <pre class="code-view" style="max-height:260px;">${JSON.stringify(json.response, null, 2)}</pre>
                    `;
                } else {
                    content.innerHTML = `
                        <div style="color:#f5222d; font-weight:700; margin-bottom:8px;">&#9888;&#65039; Respon Transfer:</div>
                        <p><strong>Nomor AJU:</strong> ${escapeHtml(json.nomor_aju || activeAju)}</p>
                        <pre class="code-view" style="max-height:260px;">${escapeHtml(json.raw_response || json.message || JSON.stringify(json, null, 2))}</pre>
                    `;
                }
            } catch (err) {
                content.innerHTML = `<div style="color:#f5222d; font-weight:700;">&#10060; Terjadi Kesalahan Jaringan / Server:</div><p>${err.message}</p>`;
            }
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.onload = function() {
            loadList();
        };
    </script>
</body>
</html>

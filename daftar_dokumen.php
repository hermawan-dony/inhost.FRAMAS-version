<?php
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');
/**
 * CEISA 4.0 - Transfer Dokumen Pabean Dashboard (transfer.php)
 * Mengadopsi Layout, Warna, Font, dan Ant Design Style Resmi Portal CEISA 4.0 (portal.beacukai.go.id/dokumen-pabean/)
 * Format: C:\dp\g-ceisa\share\Portal Ceisa 4.0.html
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? '');

$appSettings = function_exists('getAppSettings') ? getAppSettings($koneksi, $sessionCmpId) : [];
$syncIntervalMin = max(15, (int)($appSettings['ceisa_sync_interval_minutes'] ?? 15));
$syncBatchSize   = max(10, min(1000, (int)($appSettings['ceisa_sync_batch_size'] ?? 50)));

// Ambil daftar profil perusahaan aktif untuk filter
$targetCompanyRoles = 'tpb';
$targetCompanyAllowedDocs = null;
$companies = [];
if (isset($koneksi) && $koneksi) {
    $resComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, IFNULL(company_roles, 'tpb') AS company_roles, allowed_docs FROM bc_ceisa_company ORDER BY cmp_name ASC");
    if ($resComp) {
        while ($r = $resComp->fetch_assoc()) {
            if ($r['cmp_id'] === $sessionCmpId) {
                $targetCompanyRoles = $r['company_roles'];
                $targetCompanyAllowedDocs = $r['allowed_docs'];
            }
            $companies[] = $r;
        }
    }
}

// Resolusi Dokumen BC yang diizinkan untuk perusahaan ini
$companyAllowedDocs = ceisa_resolve_allowed_docs($targetCompanyRoles, $targetCompanyAllowedDocs);

// Resolusi Dokumen BC yang berhak diakses pengguna (irisan izin pengguna & perusahaan)
$effectiveAllowedDocs = ceisa_get_effective_allowed_docs($companyAllowedDocs);

if (!function_exists('isDocPermitted')) {
    function isDocPermitted($d) {
        global $isAdmin, $sessionCmpId, $companyAllowedDocs, $effectiveAllowedDocs;
        // Jika Super Admin dan TIDAK sedang memfilter perusahaan tertentu, tampilkan semua dokumen
        if (!empty($isAdmin) && empty($sessionCmpId)) {
            return true;
        }
        // Jika Super Admin memilih perusahaan tertentu, batasi sesuai dokumen perusahaan
        if (!empty($isAdmin)) {
            return in_array((string)$d, (array)$companyAllowedDocs);
        }
        // Pengguna standar (operator/admin perusahaan): batasi ketat sesuai izin efektif
        return in_array((string)$d, (array)$effectiveAllowedDocs);
    }
}

// -------------------------------------------------------------
// AJAX API ENDPOINTS
// -------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';
if (PHP_SAPI === 'cli' && empty($action)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '=') !== false) {
            list($k, $v) = explode('=', $arg, 2);
            $_GET[$k] = $v;
            $_REQUEST[$k] = $v;
        }
    }
    $action = $_REQUEST['action'] ?? '';
}

if ($action === 'list') {
    @ini_set('memory_limit', '512M');
    @ini_set('max_execution_time', '300');
    if (!ob_start('ob_gzhandler')) {
        ob_start();
    }
    header('Content-Type: application/json; charset=utf-8');
    $filter_cmp  = trim($_GET['cmp_id'] ?? '');
    $filter_bc   = trim($_GET['bc'] ?? '');
    $filter_stat = trim($_GET['status'] ?? '');
    $search      = trim($_GET['q'] ?? '');

    // Keamanan: Pengguna biasa hanya boleh melihat dokumen perusahaannya sendiri
    if (!$isAdmin && !empty($sessionCmpId)) {
        $filter_cmp = $sessionCmpId;
    }

    $where = [];
    $cmpIsInv = 1;
    $cleanCmp = '';
    if (!empty($filter_cmp)) {
        $cleanCmp = $koneksi->real_escape_string($filter_cmp);
        $resComp = $koneksi->query("SELECT npwp, IFNULL(IS_INV, 1) AS IS_INV FROM bc_ceisa_company WHERE cmp_id = '$cleanCmp' LIMIT 1");
        if ($resComp && $cr = $resComp->fetch_assoc()) {
            $cmpIsInv = (int)$cr['IS_INV'];
        }
    }
    if (!empty($filter_bc)) {
        $where[] = "a.kodeDokumen = '" . $koneksi->real_escape_string($filter_bc) . "'";
    }

    // Penegakan Izin Jenis Dokumen Bea Cukai Pengguna
    if (!$isAdmin) {
        if (!empty($effectiveAllowedDocs) && !in_array('*', $effectiveAllowedDocs)) {
            $escapedDocs = array_map(function($d) use ($koneksi) { return "'" . $koneksi->real_escape_string($d) . "'"; }, $effectiveAllowedDocs);
            $where[] = "a.kodeDokumen IN (" . implode(',', $escapedDocs) . ")";
        } elseif (empty($effectiveAllowedDocs)) {
            $where[] = "1=0";
        }
    }

    if ($filter_stat === 'posted') {
        $where[] = "(LOWER(a.namaProses) LIKE '%selesai%' OR posted.jml > 0)";
    } elseif ($filter_stat === 'unposted') {
        $where[] = "(LOWER(a.namaProses) NOT LIKE '%selesai%' AND (posted.jml IS NULL OR posted.jml = 0))";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(a.nomorAju LIKE '%$s%' OR a.nomorDaftar LIKE '%$s%' OR a.namaPerusahaan LIKE '%$s%' OR a.namaEntitas LIKE '%$s%' OR a.namaRespon LIKE '%$s%' OR a.nomorRespon LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT
        a.kodeDokumen AS bc,
        a.nomorAju AS nomor_aju,
        COALESCE(NULLIF(a.nomorDaftar, ''), '-') AS nomor_daftar,
        IFNULL(DATE_FORMAT(a.tanggalDaftar, '%Y-%m-%d'), '') AS tanggal_daftar,
        COALESCE(NULLIF(a.namaEntitas, ''), NULLIF(a.namaPerusahaan, ''), '-') AS bs,
        COALESCE(NULLIF(a.kodeEntitas, ''), NULLIF(a.idPerusahaan, ''), '') AS id_bs,
        'IDR' AS kode_valuta,
        0 AS bruto,
        a.asalData AS user_id,
        COALESCE(DATE_FORMAT(a.tanggalRespon, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(a.tanggalDaftar, '%Y-%m-%d'), '') AS upd_time,
        IFNULL(DATE_FORMAT(a.tanggalDaftar, '%Y-%m-%d'), '') AS tanggal_pernyataan,
        COALESCE(hdr.cmp_id, '$cleanCmp', '') AS cmp_id,
        a.kodeKantor AS kode_kantor,
        COALESCE(NULLIF(a.namaPerusahaan, ''), cmp.cmp_name, '') AS cmp_name,
        COALESCE(NULLIF(a.idPerusahaan, ''), cmp.npwp, '') AS cmp_npwp,
        COALESCE(jml_barang.jml, a.totalCount, 0) AS jml_barang,
        CASE WHEN LOWER(a.namaProses) LIKE '%selesai%' OR posted.jml > 0 THEN 1 ELSE 0 END AS posted,
        COALESCE(DATE_FORMAT(posted.waktu, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(a.tanggalRespon, '%Y-%m-%d %H:%i:%s'), '') AS posted_waktu,
        COALESCE(NULLIF(sppb_tbl.nomorRespon, ''), NULLIF(resp_sppb.nomor_respon, ''), NULLIF(posted.sppb, ''), NULLIF(a.nomorRespon, ''), '') AS sppb,
        COALESCE(NULLIF(sppb_tbl.pdf, ''), NULLIF(resp_sppb.pdf, ''), NULLIF(a.pdf, ''), '') AS pdf,
        COALESCE(NULLIF(sppb_tbl.nomorRespon, ''), NULLIF(resp_sppb.nomor_respon, ''), '') AS sppb_nomor_respon,
        IFNULL(DATE_FORMAT(COALESCE(sppb_tbl.tanggalRespon, resp_sppb.tanggal_respon), '%Y-%m-%d %H:%i:%s'), '') AS sppb_tanggal_respon,
        COALESCE(NULLIF(sppb_tbl.pdf, ''), NULLIF(resp_sppb.pdf, ''), '') AS sppb_pdf,
        COALESCE(NULLIF(a.namaProses, ''), '') AS namaProses,
        COALESCE(NULLIF(a.namaRespon, ''), '') AS bcd_namaRespon,
        COALESCE(NULLIF(a.nomorRespon, ''), '') AS bcd_nomorRespon,
        IFNULL(DATE_FORMAT(a.tanggalRespon, '%Y-%m-%d %H:%i:%s'), '') AS bcd_tanggalRespon,
        COALESCE(NULLIF(a.kodeJalur, ''), 'H') AS bcd_kodeJalur,
        COALESCE(NULLIF(a.namaKantorPendek, ''), CONCAT('KPPBC ', a.kodeKantor)) AS bcd_namaKantorPendek,
        COALESCE(NULLIF(a.namaPerusahaan, ''), '') AS bcd_namaPerusahaan,
        COALESCE(NULLIF(a.idPerusahaan, ''), '') AS bcd_idPerusahaan,
        COALESCE(cmp.IS_INV, $cmpIsInv, 1) AS is_inv,
        IFNULL(it_inv.jml, 0) AS in_it_inv
    FROM bc_ceisa_data a
    LEFT JOIN (
        SELECT nomor_aju, MAX(cmp_id) AS cmp_id
        FROM bc_ceisa_header
        GROUP BY nomor_aju
    ) hdr ON a.nomorAju = hdr.nomor_aju
    LEFT JOIN bc_ceisa_company cmp ON hdr.cmp_id = cmp.cmp_id
    LEFT JOIN (
        SELECT DISTINCT nomor_aju, 1 AS jml
        FROM bc_1ab2
        WHERE nomor_aju IS NOT NULL AND nomor_aju != ''
    ) it_inv ON a.nomorAju = it_inv.nomor_aju
    LEFT JOIN (
        SELECT nomorAju, MIN(nomorRespon) AS nomorRespon, MAX(tanggalRespon) AS tanggalRespon, MAX(pdf) AS pdf
        FROM bc_ceisa_sppb
        GROUP BY nomorAju
    ) sppb_tbl ON a.nomorAju = sppb_tbl.nomorAju
    LEFT JOIN (
        SELECT nomor_aju, MIN(nomor_respon) AS nomor_respon, MAX(tanggal_respon) AS tanggal_respon, MAX(pdf) AS pdf
        FROM bc_ceisa_respons
        WHERE pdf IS NOT NULL AND pdf != '' AND (keterangan LIKE '%SPPB%' OR kode_respon LIKE '%03%' OR kode_respon = '860')
        GROUP BY nomor_aju
    ) resp_sppb ON a.nomorAju = resp_sppb.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, COUNT(1) AS jml
        FROM bc_ceisa_barang
        GROUP BY nomor_aju
    ) jml_barang ON a.nomorAju = jml_barang.nomor_aju
    LEFT JOIN (
        SELECT nomor_aju, COUNT(1) AS jml, MAX(waktu) AS waktu, MAX(sppb) AS sppb
        FROM bc_ceisa_aju_posted
        GROUP BY nomor_aju
    ) posted ON a.nomorAju = posted.nomor_aju
    $whereSql
    ORDER BY a.tanggalRespon DESC, a.nomorAju DESC";

    try {
        $res = $koneksi->query($sql);
        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode(['status' => 'OK', 'count' => count($data), 'data' => $data]);
    } catch (\Throwable $e) {
        error_log("daftar_dokumen.php action=list error: " . $e->getMessage());
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage(), 'data' => []]);
    }
    exit;
}

if ($action === 'get_timeline' || $action === 'tracking_history') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_GET['nomor_aju'] ?? ($_GET['aju'] ?? ($_POST['nomor_aju'] ?? ($_POST['aju'] ?? ''))));
    $cmp_id    = trim($_GET['cmp_id'] ?? ($_POST['cmp_id'] ?? ''));

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tidak boleh kosong.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomor_aju);
    $escCmp = $koneksi->real_escape_string($cmp_id);

    // 1. Status Proses Pabean
    $sqlStatus = "SELECT kode_dokumen, nomor_aju, nomor_daftar, tanggal_daftar, kode_proses, waktu_status, keterangan 
                  FROM bc_ceisa_status 
                  WHERE nomor_aju = '$escAju' 
                  ORDER BY waktu_status ASC, id ASC";
    $resStatus = $koneksi->query($sqlStatus);
    $listStatus = [];
    if ($resStatus) {
        while ($s = $resStatus->fetch_assoc()) {
            $listStatus[] = $s;
        }
    }

    // 2. Respon Resmi Bea Cukai
    $sqlResp = "SELECT kode_dokumen, nomor_aju, kode_respon, nomor_daftar, tanggal_daftar, nomor_respon, tanggal_respon, keterangan, pdf 
                FROM bc_ceisa_respons 
                WHERE nomor_aju = '$escAju' 
                ORDER BY id ASC";
    $resResp = $koneksi->query($sqlResp);
    $listResp = [];
    if ($resResp) {
        while ($rp = $resResp->fetch_assoc()) {
            $listResp[] = $rp;
        }
    }

    // 3. Header info dari bc_ceisa_header
    $sqlHdr = "SELECT kode_dokumen, nomor_daftar, tanggal_daftar, nomor_aju, nama_pernyataan, tanggal_pernyataan 
               FROM bc_ceisa_header 
               WHERE nomor_aju = '$escAju' LIMIT 1";
    $resHdr = $koneksi->query($sqlHdr);
    $header = $resHdr ? $resHdr->fetch_assoc() : null;

    // 4. Data dari bc_ceisa_data (fallback jika header kosong atau butuh info kantor/perusahaan)
    $sqlBcd = "SELECT kodeDokumen AS kode_dokumen, nomorAju AS nomor_aju, nomorDaftar AS nomor_daftar, 
                      tanggalDaftar AS tanggal_daftar, namaPerusahaan AS nama_perusahaan, idPerusahaan AS id_perusahaan, 
                      kodeKantor AS kode_kantor, namaKantorPendek AS nama_kantor, namaProses AS nama_proses, 
                      namaRespon AS nama_respon, nomorRespon AS nomor_respon, tanggalRespon AS tanggal_respon, 
                      kodeJalur AS kode_jalur, pdf, totalCount AS total_count, '' AS cmp_id
               FROM bc_ceisa_data 
               WHERE nomorAju = '$escAju' LIMIT 1";
    $resBcd = $koneksi->query($sqlBcd);
    $rowBcd = $resBcd ? $resBcd->fetch_assoc() : null;

    if (!$header && $rowBcd) {
        $header = [
            'kode_dokumen' => $rowBcd['kode_dokumen'],
            'nomor_daftar' => $rowBcd['nomor_daftar'],
            'tanggal_daftar' => $rowBcd['tanggal_daftar'],
            'nomor_aju' => $rowBcd['nomor_aju'],
            'nama_pernyataan' => $rowBcd['nama_perusahaan'],
            'tanggal_pernyataan' => $rowBcd['tanggal_daftar']
        ];
    }

    // 5. Raw log respon
    $sqlLog = "SELECT respon FROM bc_ceisa_respon_logs 
               WHERE respon LIKE '%$escAju%' 
               ORDER BY id DESC LIMIT 1";
    $resLog = $koneksi->query($sqlLog);
    $rawLog = $resLog ? $resLog->fetch_assoc() : null;

    // 6. Hitung Stepper Tahapan 1 - 5
    $stages = [
        1 => ['title' => 'Perekaman Dokumen', 'desc' => 'Draft & perekaman awal dokumen', 'done' => true, 'time' => $header['tanggal_pernyataan'] ?? ($rowBcd['tanggal_daftar'] ?? '')],
        2 => ['title' => 'Pengiriman LNSW', 'desc' => 'Dokumen terkirim ke INSW', 'done' => false, 'time' => ''],
        3 => ['title' => 'Validasi Pabean', 'desc' => 'Validasi konten & No. Daftar terbit', 'done' => false, 'time' => ''],
        4 => ['title' => 'Penjaluran / Billing', 'desc' => 'Penetapan jalur & kode billing', 'done' => false, 'time' => ''],
        5 => ['title' => 'Keputusan Akhir (SPPB/NPE)', 'desc' => 'Dokumen selesai diproses', 'done' => false, 'time' => '']
    ];

    foreach ($listStatus as $st) {
        $kp = (string)($st['kode_proses'] ?? '');
        $kt = (string)($st['keterangan'] ?? '');
        $tm = (string)($st['waktu_status'] ?? '');

        if ($kp === '104' || $kp === '105' || $kp === '106' || stripos($kt, 'LNSW') !== false || stripos($kt, 'Kirim') !== false) {
            $stages[2]['done'] = true;
            if (empty($stages[2]['time'])) $stages[2]['time'] = $tm;
        }
        if ($kp === '110' || $kp === '200' || !empty($st['nomor_daftar'])) {
            $stages[3]['done'] = true;
            if (empty($stages[3]['time'])) $stages[3]['time'] = $tm;
        }
        if ($kp === '230' || $kp === '240' || $kp === '501' || $kp === '510' || stripos($kt, 'Jalur') !== false) {
            $stages[4]['done'] = true;
            if (empty($stages[4]['time'])) $stages[4]['time'] = $tm;
        }
        if ($kp === '511' || $kp === '800' || stripos($kt, 'Selesai') !== false) {
            $stages[5]['done'] = true;
            if (empty($stages[5]['time'])) $stages[5]['time'] = $tm;
        }
    }

    foreach ($listResp as $rp) {
        $kr = (string)($rp['kode_respon'] ?? '');
        $kt = (string)($rp['keterangan'] ?? '');
        $tm = (string)($rp['tanggal_respon'] ?? '');

        if (in_array($kr, ['2003', '2303', '2503', '2703', '3003', '4003', '4103', '61103', '61303']) || stripos($kt, 'SPPB') !== false || stripos($kt, 'NPE') !== false) {
            $stages[5]['done'] = true;
            if (empty($stages[5]['time'])) $stages[5]['time'] = $tm;
        }
        if (substr($kr, -2) === '08' || stripos($kt, 'Billing') !== false) {
            $stages[4]['done'] = true;
            if (empty($stages[4]['time'])) $stages[4]['time'] = $tm;
        }
    }

    // Jika di bc_ceisa_data ada nomor daftar
    if (!empty($rowBcd['nomor_daftar']) && $rowBcd['nomor_daftar'] !== '-') {
        $stages[2]['done'] = true;
        $stages[3]['done'] = true;
        if (empty($stages[3]['time'])) $stages[3]['time'] = $rowBcd['tanggal_daftar'];
    }
    // Jika di bc_ceisa_data nama_respon mengindikasikan SPPB / NPE / Selesai
    $namaResponVal = $rowBcd['nama_respon'] ?? '';
    $namaProsesVal = $rowBcd['nama_proses'] ?? '';
    if (!empty($namaResponVal) && (stripos($namaResponVal, 'SPPB') !== false || stripos($namaResponVal, 'NPE') !== false || stripos($namaProsesVal, 'Selesai') !== false)) {
        $stages[2]['done'] = true;
        $stages[3]['done'] = true;
        $stages[4]['done'] = true;
        $stages[5]['done'] = true;
        if (empty($stages[5]['time']) && !empty($rowBcd['tanggal_respon'])) {
            $stages[5]['time'] = $rowBcd['tanggal_respon'];
        }
    }

    // Fallback: Jika listStatus masih kosong tetapi ada nama_proses di bc_ceisa_data
    if (empty($listStatus) && !empty($namaProsesVal) && $namaProsesVal !== '-') {
        $listStatus[] = [
            'kode_dokumen'   => $rowBcd['kode_dokumen'] ?? '',
            'nomor_aju'      => $nomor_aju,
            'nomor_daftar'   => $rowBcd['nomor_daftar'] ?? '',
            'tanggal_daftar' => $rowBcd['tanggal_daftar'] ?? '',
            'kode_proses'    => $namaProsesVal,
            'waktu_status'   => $rowBcd['tanggal_respon'] ?: ($rowBcd['tanggal_daftar'] ?? '-'),
            'keterangan'     => $namaProsesVal
        ];
    }

    // Fallback: Jika listResp masih kosong tetapi ada respon (SPPB/NPE) di bc_ceisa_data
    if (empty($listResp) && !empty($namaResponVal) && $namaResponVal !== '-') {
        $listResp[] = [
            'kode_dokumen'   => $rowBcd['kode_dokumen'] ?? '',
            'nomor_aju'      => $nomor_aju,
            'kode_respon'    => $namaResponVal,
            'nomor_daftar'   => $rowBcd['nomor_daftar'] ?? '',
            'tanggal_daftar' => $rowBcd['tanggal_daftar'] ?? '',
            'nomor_respon'   => $rowBcd['nomor_respon'] ?? '',
            'tanggal_respon' => $rowBcd['tanggal_respon'] ?? '',
            'keterangan'     => $namaResponVal,
            'pdf'            => $rowBcd['pdf'] ?? ''
        ];
    }

    echo json_encode([
        'status'      => 'OK',
        'nomor_aju'   => $nomor_aju,
        'header'      => $header,
        'data_row'    => $rowBcd,
        'stages'      => $stages,
        'data_status' => $listStatus,
        'data_respon' => $listResp,
        'raw_log'     => $rawLog
    ]);
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
    <title>Dashboard Ceisa 4.0 - Interface Host to Host Ceisa 4.0</title>
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-body: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #e8e8e8;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --alert-bg: #e6f7ff;
            --alert-border: #91d5ff;
            --alert-text: #003a8c;
            --alert-link: #096dd9;
            --kpi-bg: #ffffff;
            --kpi-border: #e8e8e8;
            color-scheme: light;
        }

        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --border-card: #303030;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            --alert-bg: #111d2c;
            --alert-border: #15395b;
            --alert-text: #bae7ff;
            --alert-link: #69c0ff;
            --kpi-bg: #1f1f1f;
            --kpi-border: #303030;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 10.5px;
            line-height: 1.45;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* -------------------------------------------------------------
           ANT DESIGN CARD (PORTAL CEISA 4.0 FORMAT)
           ------------------------------------------------------------- */
        .ant-card {
            box-sizing: border-box;
            background: var(--bg-card);
            border-radius: 3px;
            position: relative;
            transition: all .3s;
        }
        .ant-card-bordered {
            border: 1px solid var(--border-card);
        }
        .ant-card-head {
            min-height: 36px;
            margin-bottom: -1px;
            padding: 0 10px;
            color: var(--text-heading);
            font-weight: 600;
            font-size: 11.5px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px;
        }
        .ant-card-head-title {
            display: inline-flex;
            align-items: center;
        }
        .ant-card-head-title h3 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-heading);
            margin: 0;
            letter-spacing: -0.2px;
        }
        .ant-card-head-actions {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }

        .ant-card-body {
            padding: 6px 10px;
        }


        /* -------------------------------------------------------------
           ANT DESIGN BUTTONS (.ant-btn)
           ------------------------------------------------------------- */
        .ant-btn {
            line-height: 1.4;
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 400;
            white-space: nowrap;
            text-align: center;
            border: 1px solid #d9d9d9;
            box-shadow: 0 1px 0 rgba(0,0,0,0.015);
            cursor: pointer;
            transition: all .2s cubic-bezier(.645, .045, .355, 1);
            user-select: none;
            height: 22px;
            padding: 0 7px;
            font-size: 10px;
            border-radius: 2px;
            color: rgba(0,0,0,0.65);
            background-color: #fff;
            font-family: inherit;
            text-decoration: none;
        }
        .ant-btn:hover {
            color: #1890ff;
            background-color: #fff;
            border-color: #1890ff;
        }
        .ant-btn-primary {
            color: #fff;
            background-color: #1890ff;
            border-color: #1890ff;
            text-shadow: 0 -1px 0 rgba(0,0,0,0.12);
            box-shadow: 0 1px 0 rgba(0,0,0,0.045);
            font-weight: 500;
        }
        .ant-btn-primary:hover {
            color: #fff;
            background-color: #40a9ff;
            border-color: #40a9ff;
        }
        .ant-btn-success {
            color: #fff;
            background-color: #52c41a;
            border-color: #52c41a;
            font-weight: 600;
        }
        .ant-btn-success:hover {
            background-color: #49ad16;
            border-color: #49ad16;
            color: #fff;
        }
        .ant-btn-sm {
            height: 18px;
            padding: 0 5px;
            font-size: 9px;
            border-radius: 2px;
        }

        /* -------------------------------------------------------------
           ANT DESIGN ALERT (.ant-alert .ant-alert-info)
           ------------------------------------------------------------- */
        .ant-alert {
            box-sizing: border-box;
            margin: 0 0 6px;
            padding: 4px 8px;
            color: var(--alert-text);
            background-color: var(--alert-bg);
            border: 1px solid var(--alert-border);
            font-size: 10.5px;
            line-height: 1.35;
            border-radius: 3px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ant-alert-icon {
            font-size: 12px;
            color: var(--primary);
            flex-shrink: 0;
        }
        .ant-alert-message p {
            margin: 0;
            font-size: 10.5px;
            color: var(--alert-text);
        }
        .ant-alert-message a {
            border-bottom: 1px solid var(--alert-link);
            color: var(--alert-link);
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
        }

        /* -------------------------------------------------------------
           KPI METRICS STRIP (.kpi-strip & .kpi-chip)
           ------------------------------------------------------------- */
        .kpi-strip {
            display: flex;
            gap: 6px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }
        .kpi-chip {
            background: var(--kpi-bg);
            border: 1px solid var(--kpi-border);
            border-radius: 2px;
            padding: 2px 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 9.5px;
            cursor: pointer;
            user-select: none;
            transition: all 0.2s cubic-bezier(0.645, 0.045, 0.355, 1);
        }
        .kpi-chip:hover {
            border-color: var(--primary);
            background: rgba(24, 144, 255, 0.04);
            transform: translateY(-1px);
        }
        .kpi-chip.active {
            border-color: var(--primary);
            background: #e6f7ff;
            box-shadow: 0 0 0 1px var(--primary);
        }
        body[data-theme="dark"] .kpi-chip.active {
            background: #111d2c;
            border-color: #177ddc;
            box-shadow: 0 0 0 1px #177ddc;
        }
        .kpi-chip.active#kpiChipUnposted {
            border-color: #fa8c16;
            background: #fff7e6;
            box-shadow: 0 0 0 1px #fa8c16;
        }
        body[data-theme="dark"] .kpi-chip.active#kpiChipUnposted {
            background: #2b1d11;
            border-color: #d46b08;
            box-shadow: 0 0 0 1px #d46b08;
        }
        .kpi-chip.active#kpiChipPosted {
            border-color: #52c41a;
            background: #f6ffed;
            box-shadow: 0 0 0 1px #52c41a;
        }
        body[data-theme="dark"] .kpi-chip.active#kpiChipPosted {
            background: #112a15;
            border-color: #389e0d;
            box-shadow: 0 0 0 1px #389e0d;
        }
        .kpi-chip.active#kpiChipNotInv {
            border-color: #ff4d4f;
            background: #fff1f0;
            box-shadow: 0 0 0 1px #ff4d4f;
        }
        body[data-theme="dark"] .kpi-chip.active#kpiChipNotInv {
            background: #2a1215;
            border-color: #a61d24;
            box-shadow: 0 0 0 1px #a61d24;
        }
        .kpi-label {
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 9px;
        }
        .kpi-val {
            font-size: 11px;
            font-weight: 700;
        }
        .kpi-val-default { color: var(--text-heading); }
        .kpi-val-warning { color: #fa8c16; }
        .kpi-val-success { color: #52c41a; }
        .kpi-val-primary { color: var(--primary); }
        .kpi-val-danger { color: #ff4d4f; }
        .kpi-label-danger { color: #ff4d4f; }

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
            border-radius: 2px;
            flex-wrap: wrap;
        }
        .filter-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .filter-col label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .filter-control {
            padding: 1px 6px;
            height: 22px;
            font-size: 9.5px;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background-color: var(--bg-input);
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .filter-control:focus {
            border-color: var(--primary);
        }

        /* Explicit Light Theme dropdown option colors */
        html[data-theme="light"] select.filter-control,
        html[data-theme="light"] select.filter-control option,
        html[data-theme="light"] select.filter-control optgroup,
        body[data-theme="light"] select.filter-control,
        body[data-theme="light"] select.filter-control option,
        body[data-theme="light"] select.filter-control optgroup,
        body[data-theme="light"] select,
        body[data-theme="light"] select option,
        select.filter-control,
        select.filter-control option,
        select.filter-control optgroup {
            background-color: #ffffff !important;
            color: #262626 !important;
            color-scheme: light !important;
        }

        /* Explicit Dark Theme dropdown option colors & fixes for Windows/Chromium */
        html[data-theme="dark"] select.filter-control,
        html[data-theme="dark"] select.filter-control option,
        html[data-theme="dark"] select.filter-control optgroup,
        body[data-theme="dark"] select.filter-control,
        body[data-theme="dark"] select.filter-control option,
        body[data-theme="dark"] select.filter-control optgroup,
        body[data-theme="dark"] select,
        body[data-theme="dark"] select option,
        body[data-theme="dark"] select optgroup,
        body[data-theme="dark"] .filter-control,
        body[data-theme="dark"] input.filter-control {
            background-color: #1e1e2d !important;
            color: #ffffff !important;
            border-color: #3b3b54 !important;
            color-scheme: dark !important;
        }

        body[data-theme="light"] select.filter-control,
        body[data-theme="light"] select.filter-control option,
        body[data-theme="light"] select.filter-control optgroup,
        body[data-theme="light"] select,
        body[data-theme="light"] select option,
        body[data-theme="light"] select optgroup,
        body[data-theme="light"] .filter-control,
        body[data-theme="light"] input.filter-control {
            background-color: #ffffff !important;
            color: #262626 !important;
            border-color: #d9d9d9 !important;
            color-scheme: light !important;
        }

        body[data-theme="dark"] select option:checked,
        body[data-theme="dark"] select option:hover {
            background-color: #177ddc !important;
            color: #ffffff !important;
        }
        .filter-btn-reset {
            height: 22px;
            padding: 0 8px;
            font-size: 9.5px;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-muted);
            cursor: pointer;
            font-family: inherit;
        }
        .filter-btn-reset:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        /* -------------------------------------------------------------
           ANT DESIGN TABLE (.ant-table-wrapper & .ant-table)
           ------------------------------------------------------------- */
        .ant-table-wrapper {
            width: 100%;
            border: 1px solid rgba(0, 0, 0, 0.075);
            border-radius: 3px;
            background: #fff;
            overflow: hidden;
        }
        body[data-theme="dark"] .ant-table-wrapper {
            border-color: #2b2b40;
            background: #1e1e2d;
        }
        .ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            text-align: left;
        }
        .ant-table-thead > tr > th {
            color: rgba(0, 0, 0, 0.85);
            font-weight: 600;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-table);
            padding: 4px 6px;
            font-size: 9.5px;
            white-space: nowrap;
        }
        body[data-theme="dark"] .ant-table-thead > tr > th {
            color: #ffffff;
        }

        .ant-table-tbody > tr > td {
            border-bottom: 1px solid #e8e8e8;
            padding: 3px 6px;
            font-size: 10px;
            vertical-align: middle;
            color: #374151;
            white-space: nowrap;
        }
        body[data-theme="dark"] .ant-table-tbody > tr > td {
            border-bottom-color: #2b2b40;
            color: #e1e3ea;
        }
        .ant-table-tbody > tr:hover > td {
            background: #f0f7ff !important;
        }
        body[data-theme="dark"] .ant-table-tbody > tr:hover > td {
            background: #262638 !important;
        }
        .ant-table-tbody > tr.selected-row > td {
            background: #e6f7ff !important;
        }

        /* Inline Filter Row Inputs */
        .ant-input-affix-wrapper {
            display: inline-flex;
            align-items: center;
            border: 1px solid #d9d9d9;
            border-radius: 2px;
            padding: 0 4px;
            background: #fff;
            height: 20px;
            width: 100%;
        }
        body[data-theme="dark"] .ant-input-affix-wrapper {
            background: #1b1b28;
            border-color: #2b2b40;
        }
        .ant-input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 9.5px;
            width: 100%;
            color: var(--text-main);
            font-family: inherit;
        }
        .ant-input-suffix {
            color: #bfbfbf;
            display: flex;
            align-items: center;
        }

        .nomor-aju-link {
            color: #000000 !important;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .nomor-aju-link:hover {
            color: var(--primary, #1890ff) !important;
            text-decoration: underline;
        }
        body[data-theme="dark"] .nomor-aju-link {
            color: #ffffff !important;
        }
        body[data-theme="dark"] .nomor-aju-link:hover {
            color: #40a9ff !important;
        }

        /* -------------------------------------------------------------
           ANT DESIGN TAGS (.ant-tag)
           ------------------------------------------------------------- */
        .ant-tag {
            box-sizing: border-box;
            margin: 0;
            padding: 0 4px;
            color: rgba(0,0,0,0.65);
            font-size: 8.5px;
            line-height: 14px;
            height: 15px;
            display: inline-block;
            border: 1px solid #d9d9d9;
            border-radius: 2px;
            background: #fafafa;
            white-space: nowrap;
            font-weight: 500;
        }
        .ant-tag-gold {
            color: #fa8c16;
            background: #fff7e6;
            border-color: #ffd591;
        }
        .ant-tag-green {
            color: #52c41a;
            background: #f6ffed;
            border-color: #b7eb8f;
            font-weight: 700;
        }
        .ant-tag-red {
            color: #f5222d;
            background: #fff1f0;
            border-color: #ffa39e;
        }
        .ant-tag-blue {
            color: #1890ff;
            background: #e6f7ff;
            border-color: #91d5ff;
        }

        /* -------------------------------------------------------------
           ANT DESIGN PAGINATION (.ant-pagination)
           ------------------------------------------------------------- */
        .ant-pagination {
            box-sizing: border-box;
            margin: 6px 0 0;
            padding: 0;
            font-size: 10px;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
        }
        .ant-pagination-total-text {
            color: #8c8c8c;
            margin-right: 4px;
            font-size: 9.5px;
        }
        .ant-pagination-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            border: 1px solid #d9d9d9;
            border-radius: 2px;
            cursor: pointer;
            user-select: none;
            font-size: 9.5px;
        }
        .ant-pagination-item a {
            color: var(--text-main);
            text-decoration: none;
            padding: 0 4px;
        }
        .ant-pagination-item-active {
            font-weight: 600;
            color: #fff !important;
            background-color: #5d78ff !important;
            border-color: #5d78ff !important;
        }
        .ant-pagination-item-active a {
            color: #fff !important;
        }
        .ant-pagination-prev, .ant-pagination-next {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            border: 1px solid #d9d9d9;
            border-radius: 2px;
            cursor: pointer;
            user-select: none;
            font-size: 11px;
            line-height: 1;
            padding: 0 4px;
        }
        .ant-pagination-prev a, .ant-pagination-next a {
            color: var(--text-main);
            text-decoration: none;
        }
        .ant-pagination-disabled {
            cursor: not-allowed !important;
            opacity: 0.4;
            border-color: #d9d9d9 !important;
        }
        .ant-pagination-disabled a {
            cursor: not-allowed !important;
            pointer-events: none;
            color: #bfbfbf !important;
        }

        /* Filter Panel (Collapsible) */
        .filter-panel {
            background: #fafbfc;
            border: 1px solid #e8e8e8;
            border-radius: 3px;
            padding: 6px 8px;
            margin-bottom: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-end;
        }
        body[data-theme="dark"] .filter-panel {
            background: #1a1a27;
            border-color: #2b2b40;
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
            border: 1px solid #e8e8e8;
            border-radius: 3px;
            width: 100%;
            max-width: 760px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid #e8e8e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }
        .modal-header h3 { font-size: 12px; color: var(--text-heading); font-weight: 600; }
        .modal-body {
            padding: 10px 12px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 8px 12px;
            border-top: 1px solid #e8e8e8;
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: #fafafa;
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
        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(24, 144, 255, 0.2);
            border-radius: 50%;
            border-top-color: #1890ff;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Visual Stepper / Progress Bar (CEISA 4.0 Tracking) */
        .stepper-container {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            position: relative;
            margin: 14px 10px 22px;
            padding: 0 10px;
        }
        .stepper-progress-bar {
            position: absolute;
            top: 15px;
            left: 45px;
            right: 45px;
            height: 2px;
            background: var(--border-card);
            z-index: 1;
        }
        .stepper-progress-active {
            position: absolute;
            top: 15px;
            left: 45px;
            height: 2px;
            background: #52c41a;
            z-index: 2;
            transition: width 0.35s ease;
        }
        .step-item {
            position: relative;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 120px;
        }
        .step-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--bg-card);
            border: 2px solid var(--border-card);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            transition: all 0.3s;
        }
        .step-item.done .step-icon {
            border-color: #52c41a;
            background: #52c41a;
            color: #ffffff;
        }
        .step-item.active .step-icon {
            border-color: var(--primary);
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.25);
        }
        .step-title {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-heading);
            margin-top: 6px;
            line-height: 1.3;
        }
        .step-desc {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .step-time {
            font-size: 9px;
            color: var(--primary);
            margin-top: 3px;
            font-family: monospace;
            font-weight: 600;
        }

        /* Modal Tabs */
        .modal-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-card);
            margin-bottom: 12px;
            gap: 16px;
        }
        .modal-tab-btn {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            padding: 6px 4px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .modal-tab-btn:hover {
            color: var(--primary);
        }
        .modal-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        /* Dark Theme Support for Modal */
        body[data-theme="dark"] .modal {
            background: #1f1f1f;
            border-color: #303030;
        }
        body[data-theme="dark"] .modal-header,
        body[data-theme="dark"] .modal-footer {
            background: #191919;
            border-color: #303030;
        }

        /* Silent CEISA Sync Indicator */
        .sync-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            padding: 2px 9px;
            border-radius: 12px;
            transition: all 0.3s ease;
            user-select: none;
            vertical-align: middle;
            font-weight: 500;
        }
        .sync-active {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }
        .sync-success {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
            font-weight: 600;
        }
        .sync-warning {
            background: #fffbe6;
            color: #faad14;
            border: 1px solid #ffe58f;
        }
        body[data-theme="dark"] .sync-active {
            background: rgba(24, 144, 255, 0.15);
            color: #40a9ff;
            border-color: #177ddc;
        }
        body[data-theme="dark"] .sync-success {
            background: rgba(82, 196, 26, 0.15);
            color: #73d13d;
            border-color: #389e0d;
        }
        body[data-theme="dark"] .sync-warning {
            background: rgba(250, 173, 20, 0.15);
            color: #ffc53d;
            border-color: #d48806;
        }

        /* Clickable PDF Respon Badge */
        .pdf-btn-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
            padding: 2px 7px;
            background: #fff1f0;
            border: 1px solid #ffa39e;
            border-radius: 4px;
            text-decoration: none !important;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(255, 77, 79, 0.08);
            line-height: 1.2;
        }
        .pdf-btn-badge:hover {
            background: #ff4d4f;
            border-color: #ff4d4f;
        }
        .pdf-btn-badge:hover span {
            color: #ffffff !important;
        }
        .pdf-btn-badge:hover svg {
            fill: #ffffff !important;
        }
        body[data-theme="dark"] .pdf-btn-badge {
            background: rgba(255, 77, 79, 0.15);
            border-color: rgba(255, 77, 79, 0.35);
        }
        body[data-theme="dark"] .pdf-btn-badge span {
            color: #ff7875 !important;
        }
        body[data-theme="dark"] .pdf-btn-badge:hover {
            background: #ff4d4f;
        }
        body[data-theme="dark"] .pdf-btn-badge:hover span {
            color: #ffffff !important;
        }
        .btn-loading {
            opacity: 0.65;
            pointer-events: none;
        }
        @keyframes rekapViewerSpin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($themeParam) ?>">

    <!-- MAIN CONTAINER CARD (.ant-card .ant-card-bordered) -->
    <div class="ant-card ant-card-bordered">
        <!-- 1. CARD HEADER -->
        <div class="ant-card-head">
            <div class="ant-card-head-title" style="display:flex; align-items:center; gap:10px;">
                <h3>Dashboard Ceisa 4.0</h3>
                <!-- Silent Sync Indicator (Progress Tidak Terlihat / Elegan) -->
                <div id="silentSyncIndicator" class="sync-indicator sync-active" style="display:none;" title="Status Sinkronisasi Background CEISA 4.0">
                    <span class="spinner" style="width:10px; height:10px; border-width:2px;"></span>
                    <span id="silentSyncText">Menyinkronkan data CEISA...</span>
                </div>
            </div>
            <div class="ant-card-head-actions">
                <!-- Utilitas -->
                <button type="button" class="ant-btn ant-dropdown-trigger" onclick="openUtilitasModal()">
                    <svg viewBox="64 64 896 896" width="13" height="13" fill="currentColor">
                        <path d="M904 160H120c-4.4 0-8 3.6-8 8v64c0 4.4 3.6 8 8 8h784c4.4 0 8-3.6 8-8v-64c0-4.4-3.6-8-8-8zm0 624H120c-4.4 0-8 3.6-8 8v64c0 4.4 3.6 8 8 8h784c4.4 0 8-3.6 8-8v-64c0-4.4-3.6-8-8-8zm0-312H120c-4.4 0-8 3.6-8 8v64c0 4.4 3.6 8 8 8h784c4.4 0 8-3.6 8-8v-64c0-4.4-3.6-8-8-8z"></path>
                    </svg>
                    <span>Utilitas</span>
                </button>

                <!-- Rekap Dokumen -->
                <button type="button" class="ant-btn" onclick="openRekapTool('index.php', '📊 Rekap Dokumen CEISA 4.0')" title="Buka Tampilan Matriks Rekapitulasi Dokumen Pabean Masuk & Keluar">
                    <svg viewBox="64 64 896 896" width="12" height="12" fill="currentColor">
                        <path d="M888 792H200V168c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v688c0 4.4 3.6 8 8 8h752c4.4 0 8-3.6 8-8v-56c0-4.4-3.6-8-8-8zM304 672h56c4.4 0 8-3.6 8-8V472c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v192c0 4.4 3.6 8 8 8zm160 0h56c4.4 0 8-3.6 8-8V328c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v336c0 4.4 3.6 8 8 8zm160 0h56c4.4 0 8-3.6 8-8V552c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v112c0 4.4 3.6 8 8 8zm160 0h56c4.4 0 8-3.6 8-8V384c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v280c0 4.4 3.6 8 8 8z"></path>
                    </svg>
                    <span>Rekap Dokumen</span>
                </button>

                <!-- Filter -->
                <button type="button" class="ant-btn" onclick="toggleFilterPanel()">
                    <svg viewBox="64 64 896 896" width="12" height="12" fill="currentColor">
                        <path d="M880.1 154H143.9c-24.5 0-39.8 26.7-27.5 48L349 597.4V838c0 17.7 14.2 32 31.8 32h262.4c17.6 0 31.8-14.3 31.8-32V597.4L907.7 202c12.2-21.3-3.1-48-27.6-48zM603.4 798H420.6V642h182.9v156zm9.6-236.6l-9.5 16.6h-183l-9.5-16.6L212.7 226h598.6L613 561.4z"></path>
                    </svg>
                    <span>Filter</span>
                </button>

                <!-- Muat Ulang -->
                <button type="button" class="ant-btn" onclick="loadList()" title="Muat Ulang Tampilan Tabel">
                    <svg viewBox="64 64 896 896" width="12" height="12" fill="currentColor">
                        <path d="M909.1 209.3l-56.4 44.1C775.8 155.1 656.2 92 521.9 92 290 92 102.3 279.5 102 511.5 101.7 743.7 289.8 932 521.9 932c181.3 0 335.8-115 394.6-276.1 1.5-4.2-.7-8.9-4.9-10.3l-56.7-19.5a8 8 0 0 0-10.1 4.8c-1.8 5-3.8 10-5.9 14.9-17.3 41-42.1 77.8-73.7 109.4A344.77 344.77 0 0 1 655.9 829c-42.3 17.9-87.4 27-133.8 27-46.5 0-91.5-9.1-133.8-27A341.5 341.5 0 0 1 279 755.2a342.16 342.16 0 0 1-73.7-109.4c-17.9-42.4-27-87.4-27-133.9s9.1-91.5 27-133.9c17.3-41 42.1-77.8 73.7-109.4 31.6-31.6 68.4-56.4 109.3-73.8 42.3-17.9 87.4-27 133.8-27 46.5 0 91.5 9.1 133.8 27a341.5 341.5 0 0 1 109.3 73.8c9.9 9.9 19.2 20.4 27.8 31.4l-60.2 47a8 8 0 0 0 3 14.1l175.6 43c5 1.2 9.9-2.6 9.9-7.7l.8-180.9c-.1-6.6-7.8-10.3-13-6.2z"></path>
                    </svg>
                    <span>Muat Ulang</span>
                </button>

                <!-- Sinkron CEISA 4.0 -->
                <button type="button" class="ant-btn" id="btnSyncCeisa" onclick="triggerManualSync()" title="Sinkronisasi status dan respon terbaru dari portal CEISA 4.0">
                    <span style="font-size:11px;">🔄</span>
                    <span>Sinkron CEISA</span>
                </button>
            </div>
        </div>

        <!-- 2. CARD BODY -->
        <div class="ant-card-body">
            <!-- Compact Metrics Strip -->
            <div class="kpi-strip">
                <div class="kpi-chip active" id="kpiChipTotal" onclick="filterByCard('all')" title="Klik untuk menampilkan semua dokumen AJU">
                    <span class="kpi-label">Total Dokumen AJU</span>
                    <strong class="kpi-val kpi-val-default" id="kpiTotal">0</strong>
                </div>
                <div class="kpi-chip" id="kpiChipUnposted" onclick="filterByCard('unposted')" title="Klik untuk memfilter hanya dokumen yang belum selesai">
                    <span class="kpi-label kpi-label-warning">Belum Selesai</span>
                    <strong class="kpi-val kpi-val-warning" id="kpiUnposted">0</strong>
                </div>
                <div class="kpi-chip" id="kpiChipPosted" onclick="filterByCard('posted')" title="Klik untuk memfilter hanya dokumen yang selesai proses">
                    <span class="kpi-label kpi-label-success">Selesai Proses</span>
                    <strong class="kpi-val kpi-val-success" id="kpiPosted">0</strong>
                </div>
                <div class="kpi-chip" id="kpiChipNotInv" onclick="filterByCard('not_inv')" title="Klik untuk memfilter hanya dokumen yang belum masuk IT Inventory (bc_1ab2)" style="display:none;">
                    <span class="kpi-label kpi-label-danger">Belum Masuk IT Inv</span>
                    <strong class="kpi-val kpi-val-danger" id="kpiNotInv">0</strong>
                </div>
            </div>

            <!-- Filter Panel (Compact) -->
            <div class="filter-panel" id="filterPanel">
                <input type="hidden" id="filterCmp" value="<?= htmlspecialchars($sessionCmpId) ?>">
                <div class="filter-col">
                    <label>DOKUMEN BC</label>
                    <select id="filterBc" onchange="loadList()" class="filter-control">
                        <option value="">Semua Dokumen</option>
                        <?php if (isDocPermitted('611')): ?><option value="611">PPKEK 6.1.1 (Pemasukan LDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('613')): ?><option value="613">PPKEK 6.1.3 (Pemasukan TLDDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('631')): ?><option value="631">PPKEK 6.3.1 (Pengeluaran ke LDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('25')): ?><option value="25">BC 2.5 (Pengeluaran TPB)</option><?php endif; ?>
                        <?php if (isDocPermitted('23')): ?><option value="23">BC 2.3 (Impor TPB)</option><?php endif; ?>
                        <?php if (isDocPermitted('40')): ?><option value="40">BC 4.0 (Pemasukan TLDDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('27')): ?><option value="27">BC 2.7 (TPB ke TPB)</option><?php endif; ?>
                        <?php if (isDocPermitted('41')): ?><option value="41">BC 4.1 (Pengeluaran TLDDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('261')): ?><option value="261">BC 2.6.1 (Subkon Luar)</option><?php endif; ?>
                        <?php if (isDocPermitted('262')): ?><option value="262">BC 2.6.2 (Subkon Masuk)</option><?php endif; ?>
                        <?php if (isDocPermitted('16')): ?><option value="16">BC 1.6 (Pemasukan PLB)</option><?php endif; ?>
                        <?php if (isDocPermitted('28')): ?><option value="28">BC 2.8 (Impor PLB)</option><?php endif; ?>
                        <?php if (isDocPermitted('33')): ?><option value="33">BC 3.3 (Ekspor PLB)</option><?php endif; ?>
                        <?php if (isDocPermitted('331')): ?><option value="331">BC 3.3.1 (P3BET)</option><?php endif; ?>
                        <?php if (isDocPermitted('20')): ?><option value="20">BC 2.0 (Impor PIB)</option><?php endif; ?>
                        <?php if (isDocPermitted('30')): ?><option value="30">BC 3.0 (Ekspor)</option><?php endif; ?>
                        <?php if (empty($companyAllowedDocs) && !empty($sessionCmpId)): ?>
                            <option value="" disabled>(Tidak ada dokumen pabean aktif)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="filter-col">
                    <label>STATUS PROSES</label>
                    <select id="filterStatus" onchange="loadList()" class="filter-control">
                        <option value="" selected>Semua Status</option>
                        <option value="unposted">Belum Selesai Proses</option>
                        <option value="posted">Selesai Proses</option>
                    </select>
                </div>
                <div class="filter-col" style="flex:1; min-width:180px;">
                    <label>PENCARIAN GLOBAL</label>
                    <input type="text" id="filterSearch" placeholder="Cari No. AJU, Perusahaan, No. Daftar..." onkeyup="debounceSearch()" class="filter-control">
                </div>
                <div>
                    <button type="button" class="filter-btn-reset" onclick="resetFilters()">Reset Filter</button>
                </div>
            </div>

            <!-- High-Density Data Table (.ant-table-wrapper) -->
            <div class="ant-table-wrapper">
                <div class="ant-table">
                    <table style="width: 100%;">
                        <thead class="ant-table-thead">
                            <!-- Header Row 1: Titles -->
                            <tr>
                                <th style="width: 55px;">Nomor</th>
                                <th style="width: 110px;">Kode Dokumen</th>
                                <th style="width: 260px;">Nomor Pengajuan</th>
                                <th style="width: 170px;">Nomor / Tanggal Pendaftaran</th>
                                <th style="width: 170px;">Nama Respon</th>
                                <th style="width: 70px; text-align: center;">PDF</th>
                                <th style="width: 155px;">Tanggal Respon</th>
                                <th style="width: 65px; text-align: center;">Jalur</th>
                                <th style="width: 250px;">Nama Perusahaan</th>
                                <th style="width: 180px;">Kantor Pabean</th>
                            </tr>
                            <!-- Header Row 2: Inline Input Filters -->
                            <tr>
                                <th></th>
                                <th>
                                    <span class="ant-input-affix-wrapper">
                                        <input type="text" class="ant-input" id="tblFilterBc" placeholder="" oninput="debouncedFilter()">
                                        <span class="ant-input-suffix">
                                            <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path></svg>
                                        </span>
                                    </span>
                                </th>
                                <th>
                                    <span class="ant-input-affix-wrapper">
                                        <input type="text" class="ant-input" id="tblFilterAju" placeholder="" oninput="debouncedFilter()">
                                        <span class="ant-input-suffix">
                                            <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path></svg>
                                        </span>
                                    </span>
                                </th>
                                <th>
                                    <span class="ant-input-affix-wrapper">
                                        <input type="text" class="ant-input" id="tblFilterDaftar" placeholder="" oninput="debouncedFilter()">
                                        <span class="ant-input-suffix">
                                            <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path></svg>
                                        </span>
                                    </span>
                                </th>
                                <th>
                                    <span class="ant-input-affix-wrapper">
                                        <input type="text" class="ant-input" id="tblFilterRespon" placeholder="" oninput="debouncedFilter()">
                                        <span class="ant-input-suffix">
                                            <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path></svg>
                                        </span>
                                    </span>
                                </th>
                                <th></th>
                                <th></th>
                                <th>
                                    <span class="ant-input-affix-wrapper">
                                        <input type="text" class="ant-input" id="tblFilterJalur" placeholder="" oninput="debouncedFilter()" style="text-align: center;">
                                        <span class="ant-input-suffix">
                                            <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path></svg>
                                        </span>
                                    </span>
                                </th>
                                <th>
                                    <span class="ant-input-affix-wrapper">
                                        <input type="text" class="ant-input" id="tblFilterPerusahaan" placeholder="" oninput="debouncedFilter()">
                                        <span class="ant-input-suffix">
                                            <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path></svg>
                                        </span>
                                    </span>
                                </th>
                                <th>
                                    <span class="ant-input-affix-wrapper">
                                        <input type="text" class="ant-input" id="tblFilterKantor" placeholder="" oninput="debouncedFilter()">
                                        <span class="ant-input-suffix">
                                            <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path></svg>
                                        </span>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="ant-table-tbody" id="ajuTableBody">
                            <tr>
                                <td colspan="10" style="text-align:center; padding:28px; color:#8c8c8c;">
                                    <span class="spinner"></span> Memuat data dokumen...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Matching CEISA 4.0 -->
                <ul class="ant-pagination ant-table-pagination" id="tablePagination">
                    <li class="ant-pagination-total-text">Total <span id="pagTotal">0</span> item</li>
                    <li class="ant-pagination-item ant-pagination-item-1 ant-pagination-item-active">
                        <a>1</a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- MODAL: RIWAYAT TRACKING PERUBAHAN STATUS DOKUMEN CEISA 4.0 -->
    <div class="modal-overlay" id="modalTrackingHistory" style="display:none;">
        <div class="modal" style="max-width:880px; width:95%; max-height:92vh; display:flex; flex-direction:column; padding:0; border-radius:6px; overflow:hidden;">
            <!-- Header Modal -->
            <div class="modal-header" style="padding:12px 18px; border-bottom:1px solid var(--border-card); background:var(--bg-filter); display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:28px; height:28px; border-radius:6px; background:rgba(24,144,255,0.12); display:flex; align-items:center; justify-content:center; color:var(--primary);">
                        <svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor">
                            <path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm0 820c-205.4 0-372-166.6-372-372s166.6-372 372-372 372 166.6 372 372-166.6 372-372 372z"></path>
                            <path d="M686.7 638.6L544.1 535.5V288c0-4.4-3.6-8-8-8h-48c-4.4 0-8 3.6-8 8v275.9c0 2.7 1.4 5.3 3.7 6.8l164.7 119.5a8 8 0 0 0 11.4-1.9l28.6-39.7a8 8 0 0 0-1.8-10z"></path>
                        </svg>
                    </div>
                    <div>
                        <div style="font-size:13px; font-weight:700; color:var(--text-heading); display:flex; align-items:center; gap:8px;">
                            <span>Riwayat Tracking Perubahan Status</span>
                            <span id="modalTrackDokBadge" class="ant-tag ant-tag-blue" style="font-size:10.5px; padding:1px 6px;">BC -</span>
                        </div>
                        <div style="font-size:10.5px; color:var(--text-muted); margin-top:2px; font-family:monospace;" id="modalTrackAjuText">-</div>
                    </div>
                </div>
                <button type="button" class="ant-btn ant-btn-sm" style="border:none; background:transparent; font-size:15px; cursor:pointer;" onclick="closeModal('modalTrackingHistory')">✕</button>
            </div>

            <!-- Body Modal -->
            <div class="modal-body" style="padding:16px 20px; overflow-y:auto; flex:1;">
                <!-- Ringkasan Info Cepat (Pendaftaran & Sync) -->
                <div style="background:var(--bg-filter); border:1px solid var(--border-card); border-radius:4px; padding:10px 14px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; gap:20px; flex-wrap:wrap;">
                        <div>
                            <div style="font-size:9.5px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Nomor & Tanggal Pendaftaran</div>
                            <div style="font-size:11.5px; font-weight:600; color:var(--text-heading); margin-top:2px;" id="modalTrackDaftarText">-</div>
                        </div>
                        <div>
                            <div style="font-size:9.5px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Perusahaan / Pemilik</div>
                            <div style="font-size:11.5px; font-weight:600; color:var(--text-heading); margin-top:2px;" id="modalTrackPerusahaanText">-</div>
                        </div>
                        <div>
                            <div style="font-size:9.5px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Kantor Pabean</div>
                            <div style="font-size:11.5px; font-weight:600; color:var(--text-heading); margin-top:2px;" id="modalTrackKantorText">-</div>
                        </div>
                    </div>
                    <div>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" id="btnModalSyncLive" onclick="syncTrackingModalAju()" style="display:inline-flex; align-items:center; gap:5px;">
                            <svg viewBox="64 64 896 896" width="12" height="12" fill="currentColor">
                                <path d="M756.1 487.6l23.5-23.5c3.1-3.1 3.1-8.2 0-11.3l-90.5-90.5c-3.1-3.1-8.2-3.1-11.3 0l-90.5 90.5c-3.1 3.1-3.1 8.2 0 11.3l23.5 23.5c3.1 3.1 8.2 3.1 11.3 0l38.2-38.2c1.9 44.4-14.7 89.1-49.8 124.2-65.7 65.7-167.3 69.8-237.7 12.3-4.5-3.7-11.1-3.1-14.9 1.4l-25.1 30.6c-3.7 4.5-3.1 11.1 1.4 14.9 98 80.1 239.5 74.4 331-17.1 49.3-49.3 72.8-112.5 70.4-175.6l38.2 38.2c3.1 3.1 8.2 3.1 11.3 0zM267.9 536.4l-23.5 23.5c-3.1 3.1-3.1 8.2 0 11.3l90.5 90.5c3.1 3.1 8.2 3.1 11.3 0l90.5-90.5c3.1-3.1 3.1-8.2 0-11.3l-23.5-23.5c-3.1-3.1-8.2-3.1-11.3 0l-38.2 38.2c-1.9-44.4 14.7-89.1 49.8-124.2 65.7-65.7 167.3-69.8 237.7-12.3 4.5 3.7 11.1 3.1 14.9-1.4l25.1-30.6c3.7-4.5 3.1-11.1-1.4-14.9-98-80.1-239.5-74.4-331 17.1-49.3 49.3-72.8 112.5-70.4 175.6l-38.2-38.2c-3.1-3.1-8.2-3.1-11.3 0z"></path>
                            </svg>
                            <span>Sync Ulang Live</span>
                        </button>
                    </div>
                </div>

                <!-- Visual Stepper Progress Bar -->
                <div class="stepper-container" id="modalVisualStepper">
                    <div class="stepper-progress-bar"></div>
                    <div class="stepper-progress-active" id="modalStepperActiveBar" style="width:0%;"></div>
                </div>

                <!-- Tabs: Status Proses, Respon Resmi, Log Mentah -->
                <div class="modal-tabs">
                    <button type="button" class="modal-tab-btn active" id="tabBtnStatus" onclick="switchTrackingTab('status')">
                        Riwayat Status Proses (<span id="modalTrackCountStatus">0</span>)
                    </button>
                    <button type="button" class="modal-tab-btn" id="tabBtnRespon" onclick="switchTrackingTab('respon')">
                        Respon Resmi Bea Cukai (<span id="modalTrackCountRespon">0</span>)
                    </button>
                    <button type="button" class="modal-tab-btn" id="tabBtnRaw" onclick="switchTrackingTab('raw')">
                        Log Respon Mentah (JSON)
                    </button>
                </div>

                <!-- Tab Pane 1: Status Proses -->
                <div class="tab-pane active" id="paneTrackStatus">
                    <div style="border:1px solid var(--border-card); border-radius:3px; overflow:hidden;">
                        <table class="ant-table" style="width:100%;">
                            <thead class="ant-table-thead">
                                <tr>
                                    <th style="width:35px; text-align:center;">#</th>
                                    <th style="width:145px;">Waktu Status</th>
                                    <th style="width:90px;">Kode Proses</th>
                                    <th>Keterangan Proses</th>
                                    <th style="width:110px;">No. Daftar</th>
                                </tr>
                            </thead>
                            <tbody class="ant-table-tbody" id="modalTrackStatusTbody">
                                <tr><td colspan="5" style="text-align:center; padding:16px; color:var(--text-muted);"><span class="spinner"></span> Memuat status...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab Pane 2: Respon Resmi -->
                <div class="tab-pane" id="paneTrackRespon">
                    <div style="border:1px solid var(--border-card); border-radius:3px; overflow:hidden;">
                        <table class="ant-table" style="width:100%;">
                            <thead class="ant-table-thead">
                                <tr>
                                    <th style="width:35px; text-align:center;">#</th>
                                    <th style="width:95px;">Kode Respon</th>
                                    <th style="width:150px;">Nomor Respon</th>
                                    <th style="width:145px;">Tanggal Respon</th>
                                    <th>Keterangan Respon</th>
                                    <th style="width:85px; text-align:center;">Dokumen PDF</th>
                                </tr>
                            </thead>
                            <tbody class="ant-table-tbody" id="modalTrackResponTbody">
                                <tr><td colspan="6" style="text-align:center; padding:16px; color:var(--text-muted);"><span class="spinner"></span> Memuat respon...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab Pane 3: Raw JSON Log -->
                <div class="tab-pane" id="paneTrackRaw">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span style="color:var(--text-muted); font-size:10px;">Respon mentah terakhir dari CEISA 4.0:</span>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="copyTrackRawJson()">📋 Salin JSON</button>
                    </div>
                    <pre class="code-view" id="modalTrackRawBox" style="max-height:280px; overflow:auto;">// Menunggu data...</pre>
                </div>
            </div>

            <!-- Footer Modal -->
            <div class="modal-footer" style="padding:10px 18px; border-top:1px solid var(--border-card); background:var(--bg-filter); display:flex; justify-content:flex-end;">
                <button type="button" class="ant-btn" onclick="closeModal('modalTrackingHistory')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- MODAL 3: UTILITAS MENU -->
    <div class="modal-overlay" id="modalUtilitas">
        <div class="modal" style="max-width:560px; width:100%; border-radius:6px; box-shadow:0 12px 32px rgba(0,0,0,0.2);">
            <div class="modal-header" style="background:#fafafa; border-bottom:1px solid #f0f0f0;">
                <h3 style="font-size:13px; font-weight:600; margin:0;">📑 Menu Utilitas & Rekap Portal CEISA 4.0</h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalUtilitas')">✕</button>
            </div>
            <div class="modal-body" style="display:flex; flex-direction:column; gap:10px; max-height:80vh; overflow-y:auto; overflow-x:hidden; padding:16px;">
                <!-- Kategori 1: Rekap & Sinkronisasi Portal CEISA 4.0 -->
                <div style="font-size:11px; font-weight:700; color:#8c8c8c; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #f0f0f0; padding-bottom:4px;">
                    📊 Modul Rekap & Sinkronisasi Portal CEISA
                </div>

                <!-- 1. Sync ALL DATA -->
                <button type="button" class="ant-btn ant-btn-primary" onclick="openRekapTool('sync_all.php', '⚡ Sinkronisasi Seluruh Dokumen')" style="padding:10px 14px; height:auto; justify-content:flex-start; text-align:left; background:#1890ff; border-color:#1890ff; width:100%; box-sizing:border-box; white-space:normal;">
                    <div style="width:100%;">
                        <div style="font-weight:600; font-size:12px;">⚡ Sinkronisasi Seluruh Dokumen (Sync All)</div>
                        <div style="font-size:10.5px; opacity:0.9; margin-top:2px; line-height:1.4;">Menarik seluruh data dokumen pabean dari portal CEISA secara bertahap dan teratur dengan indikator progres</div>
                    </div>
                </button>

                <!-- 2. Lihat Tampilan Rekap -->
                <button type="button" class="ant-btn" onclick="openRekapTool('index.php', '📊 Rekap Dokumen CEISA 4.0')" style="padding:10px 14px; height:auto; justify-content:flex-start; text-align:left; border-color:#91d5ff; background:#e6f7ff; color:#0050b3; width:100%; box-sizing:border-box; white-space:normal;">
                    <div style="width:100%;">
                        <div style="font-weight:600; font-size:12px;">📊 Tampilan Rekap Dokumen (Selesai & Belum Selesai)</div>
                        <div style="font-size:10.5px; color:#595959; margin-top:2px; line-height:1.4;">Matriks rekapitulasi dokumen pabean masuk dan keluar per periode (klik angka untuk melihat rincian dokumen)</div>
                    </div>
                </button>

                <!-- 3. Refresh Data Belum Selesai -->
                <button type="button" class="ant-btn" onclick="openRekapTool('refresh.php', '🔄 Perbarui Status Dokumen Belum Selesai')" style="padding:10px 14px; height:auto; justify-content:flex-start; text-align:left; border-color:#ffa39e; background:#fff1f0; color:#cf1322; width:100%; box-sizing:border-box; white-space:normal;">
                    <div style="width:100%;">
                        <div style="font-weight:600; font-size:12px;">🔄 Perbarui Status Dokumen Belum Selesai</div>
                        <div style="font-size:10.5px; color:#595959; margin-top:2px; line-height:1.4;">Memeriksa dan memperbarui status nomor pengajuan yang masih dalam proses secara otomatis</div>
                    </div>
                </button>

                <!-- 4. Croscek / Compare dengan IT Inventory -->
                <button type="button" class="ant-btn" onclick="openRekapTool('compare.php', '🔍 Crosscheck & Compare IT Inventory')" style="padding:10px 14px; height:auto; justify-content:flex-start; text-align:left; border-color:#b7eb8f; background:#f6ffed; color:#237804; width:100%; box-sizing:border-box; white-space:normal;">
                    <div style="width:100%;">
                        <div style="font-weight:600; font-size:12px;">🔍 Crosscheck & Compare IT Inventory</div>
                        <div style="font-size:10.5px; color:#595959; margin-top:2px; line-height:1.4;">Melihat nomor pengajuan CEISA mana saja yang belum masuk atau terdapat selisih dengan catatan IT Inventory</div>
                    </div>
                </button>

                <!-- 5. Tarik BC 27 IN -->
                <button type="button" class="ant-btn" onclick="openRekapTool('bc27in.php', '📥 Tarik Dokumen BC 2.7 IN ke IT Inventory')" style="padding:10px 14px; height:auto; justify-content:flex-start; text-align:left; border-color:#d3adf7; background:#f9f0ff; color:#531dab; width:100%; box-sizing:border-box; white-space:normal;">
                    <div style="width:100%;">
                        <div style="font-weight:600; font-size:12px;">📥 Tarik BC 2.7 IN & Olah ke IT Inventory</div>
                        <div style="font-size:10.5px; color:#595959; margin-top:2px; line-height:1.4;">Menarik dokumen pemasukan BC 2.7, validasi kesesuaian master barang dan klasifikasi, lalu posting ke IT Inventory</div>
                    </div>
                </button>

                <!-- Kategori 2: Alat Pendukung & Portal -->
                <div style="font-size:11px; font-weight:700; color:#8c8c8c; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid #f0f0f0; padding-bottom:4px; margin-top:6px;">
                    🔧 Alat Pendukung & Autentikasi
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <button type="button" class="ant-btn" onclick="openMfaWindow()" style="padding:7px 12px; height:auto; justify-content:center;">
                        🔐 Generate MFA Token
                    </button>
                    <a href="https://portal.beacukai.go.id/portal" target="_blank" rel="noopener noreferrer" class="ant-btn" style="padding:7px 12px; height:auto; justify-content:center;">
                        🌐 Portal Resmi ↗
                    </a>
                </div>
            </div>
            <div class="modal-footer" style="background:#fafafa; border-top:1px solid #f0f0f0;">
                <button type="button" class="ant-btn" onclick="closeModal('modalUtilitas')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- MODAL 4: IN-PAGE VIEWER UNTUK UTILITAS / REKAP -->
    <div class="modal-overlay" id="modalRekapViewer" style="display:none; z-index:9999;">
        <div class="modal" style="max-width:98vw; width:98vw; height:95vh; max-height:95vh; padding:0; display:flex; flex-direction:column; overflow:hidden; border-radius:6px; box-shadow:0 10px 30px rgba(0,0,0,0.25);">
            <div class="modal-header" style="padding:8px 16px; background:#fafafa; border-bottom:1px solid #e8e8e8; display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <h3 id="rekapViewerTitle" style="margin:0; font-size:13px; font-weight:600; color:#262626;">Utilitas CEISA 4.0</h3>
                    <span id="rekapViewerBadge" class="ant-tag ant-tag-blue" style="font-size:10px;">Portal Tools</span>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="openRekapViewerInNewTab()" title="Buka di Tab Baru / Halaman Penuh">
                        <svg viewBox="64 64 896 896" width="11" height="11" fill="currentColor"><path d="M868 732h-72c-4.4 0-8 3.6-8 8v84H168V168h84c4.4 0 8-3.6 8-8v-72c0-4.4-3.6-8-8-8H128c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V740c0-4.4-3.6-8-8-8zM892.4 131.6l-212.7.2a8 8 0 0 0-8 8v72c0 4.4 3.6 8 8 8l105.7-.1-399.1 399.1a8 8 0 0 0 0 11.3l50.9 50.9a8 8 0 0 0 11.3 0l399.1-399.1-.1 105.7c0 4.4 3.6 8 8 8h72c4.4 0 8-3.6 8-8l.2-212.7c.1-6.6-5.3-12-12-12.3z"></path></svg>
                        <span>Tab Baru</span>
                    </button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="closeRekapViewer()">✕</button>
                </div>
            </div>
            <div style="flex:1; width:100%; height:100%; position:relative; background:#f0f2f5;">
                <!-- LOADER MODAL REKAP VIEWER -->
                <div id="rekapViewerLoader" style="position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.92); backdrop-filter:blur(3px); display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; z-index:20;">
                    <div style="width:40px; height:40px; border:3.5px solid #e6f7ff; border-top:3.5px solid #1890ff; border-radius:50%; animation:rekapViewerSpin 0.75s linear infinite;"></div>
                    <div style="font-size:12.5px; font-weight:600; color:#1890ff; letter-spacing:0.3px;">Memuat Utilitas CEISA 4.0...</div>
                    <div style="font-size:11px; color:#8c8c8c;">Menyiapkan matriks dokumen & data real-time...</div>
                </div>
                <iframe id="rekapViewerFrame" src="" style="width:100%; height:100%; border:none;"></iframe>
            </div>
        </div>
    </div>

    <script>
        let ajuData = [];
        let filteredData = [];
        let selectedAju = new Set();
        let activePreviewAju = null;
        let searchTimer = null;

        // Auto sync theme from parent portal
        function applyCurrentTheme(th) {
            if (!th) {
                var urlParams = new URLSearchParams(window.location.search);
                th = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            }
            if (th !== 'light' && th !== 'dark') th = 'light';
            document.body.setAttribute('data-theme', th);
            document.documentElement.setAttribute('data-theme', th);
            document.documentElement.style.colorScheme = th;
        }
        applyCurrentTheme();

        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                applyCurrentTheme(e.data.theme);
            }
        });

        const urlParams = new URLSearchParams(window.location.search);
        const initialCmp = urlParams.get('cmp_id');
        if (initialCmp) {
            const cmpSelect = document.getElementById('filterCmp');
            if (cmpSelect) cmpSelect.value = initialCmp;
        }

        function toggleFilterPanel() {
            const p = document.getElementById('filterPanel');
            p.style.display = (p.style.display === 'none') ? 'flex' : 'none';
        }

        function openUtilitasModal() {
            document.getElementById('modalUtilitas').style.display = 'flex';
        }

        let activeRekapUrl = '';
        function openRekapTool(path, title) {
            closeModal('modalUtilitas');
            const cmpId = document.getElementById('filterCmp')?.value || initialCmp || '<?= htmlspecialchars($sessionCmpId) ?>';
            activeRekapUrl = `rekap/${path}?cmp_id=${encodeURIComponent(cmpId)}`;
            const rvt = document.getElementById('rekapViewerTitle');
            if (rvt) rvt.innerText = title || 'Utilitas CEISA 4.0';
            
            // Tampilkan loader modal rekap viewer saat iframe mulai memuat
            const ldr = document.getElementById('rekapViewerLoader');
            if (ldr) ldr.style.display = 'flex';

            const rvf = document.getElementById('rekapViewerFrame');
            if (rvf) {
                rvf.onload = function() {
                    if (ldr) ldr.style.display = 'none';
                };
                rvf.src = activeRekapUrl;
            }
            const mrv = document.getElementById('modalRekapViewer');
            if (mrv) mrv.style.display = 'flex';
        }

        function closeRekapViewer() {
            document.getElementById('modalRekapViewer').style.display = 'none';
            const rvf = document.getElementById('rekapViewerFrame');
            if (rvf) {
                rvf.onload = null;
                rvf.src = '';
            }
            const ldr = document.getElementById('rekapViewerLoader');
            if (ldr) ldr.style.display = 'none';
            loadList();
        }

        function openRekapViewerInNewTab() {
            if (activeRekapUrl) {
                window.open(activeRekapUrl, '_blank');
            }
        }

        function openMfaWindow() {
            const cmpId = document.getElementById('filterCmp')?.value || initialCmp || '<?= htmlspecialchars($sessionCmpId) ?>';
            const adminParam = <?= $isAdmin ? "'&is_admin=1'" : "''" ?>;
            const w = window.open(`mfa.php?cmp_id=${encodeURIComponent(cmpId)}${adminParam}`, 'mfa_token', 'width=380,height=370,resizable=yes');
            if (w) w.focus();
            closeModal('modalUtilitas');
        }

        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadList, 300);
        }

        async function loadList(isSilent = false) {
            const cmpSelect = document.getElementById('filterCmp');
            const cmp = cmpSelect ? cmpSelect.value : (initialCmp || '<?= htmlspecialchars($sessionCmpId) ?>');
            const bc = document.getElementById('filterBc')?.value || '';
            const status = document.getElementById('filterStatus')?.value || '';
            const q = document.getElementById('filterSearch')?.value || '';

            const tbody = document.getElementById('ajuTableBody');
            if (!isSilent) {
                tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:24px; color:#8c8c8c;"><span class="spinner"></span> Memuat data dokumen...</td></tr>`;
            }

            try {
                const res = await fetch(`daftar_dokumen.php?action=list&cmp_id=${encodeURIComponent(cmp)}&bc=${encodeURIComponent(bc)}&status=${encodeURIComponent(status)}&q=${encodeURIComponent(q)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    ajuData = json.data;
                    filterClientTable();
                } else if (!isSilent) {
                    tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; color:#f5222d; padding:24px;">
                        <div style="font-weight:600; margin-bottom:4px;">Gagal memuat dokumen dari server</div>
                        <div style="font-size:11.5px; color:#8c8c8c; margin-bottom:12px;">${escapeHtml(json.message || 'Error tidak diketahui')}</div>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="loadList()">Coba Muat Ulang</button>
                    </td></tr>`;
                }
            } catch (err) {
                if (!isSilent) {
                    tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; color:#f5222d; padding:24px;">
                        <div style="font-weight:600; margin-bottom:4px;">Gagal mengambil data dari server</div>
                        <div style="font-size:11.5px; color:#8c8c8c; margin-bottom:12px;">${escapeHtml(err.message || 'Periksa koneksi jaringan Anda')}</div>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="loadList()">Coba Muat Ulang</button>
                    </td></tr>`;
                }
            }
        }

        let isCeisaSyncing = false;

        async function triggerSilentCeisaSync(isManual = false) {
            if (isCeisaSyncing) return;

            const cmpSelect = document.getElementById('filterCmp');
            const cmp = cmpSelect ? cmpSelect.value : (initialCmp || '<?= htmlspecialchars($sessionCmpId) ?>');

            // Cooldown check (minimal 15 menit / 900.000 ms jika otomatis)
            const syncIntervalMs = <?= $syncIntervalMin ?> * 60 * 1000;
            const cacheKey = 'ceisa_last_silent_sync_' + (cmp || 'default');
            if (!isManual) {
                const lastSync = parseInt(localStorage.getItem(cacheKey) || '0');
                const now = Date.now();
                if (now - lastSync < syncIntervalMs) {
                    return;
                }
            }

            isCeisaSyncing = true;
            const indicator = document.getElementById('silentSyncIndicator');
            const indicatorText = document.getElementById('silentSyncText');
            const btnSync = document.getElementById('btnSyncCeisa');

            if (indicator) {
                indicator.style.display = 'inline-flex';
                indicator.className = 'sync-indicator sync-active';
                if (indicatorText) indicatorText.innerText = 'Menyinkronkan data CEISA...';
            }
            if (btnSync) {
                btnSync.classList.add('btn-loading');
                btnSync.disabled = true;
            }

            try {
                const forceParam = isManual ? '&force=1' : '';
                const fetchSize = <?= (int)($syncBatchSize ?: 50) ?>;
                const res = await fetch(`ceisa_sync_service.php?cmp_id=${encodeURIComponent(cmp)}&size=${fetchSize}${forceParam}`);
                const json = await res.json();

                localStorage.setItem(cacheKey, Date.now().toString());

                if (json.status === 'OK') {
                    if (indicator && indicatorText) {
                        indicator.className = 'sync-indicator sync-success';
                        indicatorText.innerText = `✓ CEISA 4.0 Terupdate (${json.inserted} dokumen)`;
                        setTimeout(() => {
                            if (indicator) indicator.style.display = 'none';
                        }, 3500);
                    }

                    // Jika ada data baru atau respon terupdate, segarkan tabel tanpa kedip
                    if (json.has_changes) {
                        loadList(true);
                    }
                } else if (json.status === 'RATE_LIMITED') {
                    if (indicator && indicatorText) {
                        indicator.className = 'sync-indicator sync-warning';
                        indicatorText.innerText = json.message || 'Antrean padat: Sinkronisasi dijeda sementara';
                        setTimeout(() => {
                            if (indicator) indicator.style.display = 'none';
                        }, 5000);
                    }
                } else if (json.status === 'CACHED') {
                    if (indicator) indicator.style.display = 'none';
                } else {
                    if (indicator && indicatorText && isManual) {
                        indicator.className = 'sync-indicator sync-warning';
                        indicatorText.innerText = json.message || 'Sinkronisasi dilewati';
                        setTimeout(() => {
                            if (indicator) indicator.style.display = 'none';
                        }, 4000);
                    } else if (indicator) {
                        indicator.style.display = 'none';
                    }
                }
            } catch (err) {
                console.warn('Silent CEISA sync notice:', err.message);
                if (indicator) indicator.style.display = 'none';
            } finally {
                isCeisaSyncing = false;
                if (btnSync) {
                    btnSync.classList.remove('btn-loading');
                    btnSync.disabled = false;
                }
            }
        }

        function triggerManualSync() {
            triggerSilentCeisaSync(true);
        }

        let currentPage = 1;
        let pageSize = 50;
        let filterDebounceTimer = null;

        function debouncedFilter() {
            clearTimeout(filterDebounceTimer);
            filterDebounceTimer = setTimeout(() => {
                filterClientTable();
            }, 250);
        }

        function setPageSize(val) {
            pageSize = parseInt(val) || 50;
            currentPage = 1;
            renderTable();
        }

        function goToPage(page) {
            const total = filteredData ? filteredData.length : 0;
            const totalPages = Math.ceil(total / pageSize) || 1;
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            renderTable();
            const tblCont = document.querySelector('.table-container');
            if (tblCont) tblCont.scrollTop = 0;
        }

        let currentCardFilter = 'all'; // 'all', 'unposted', 'posted', 'not_inv'

        function filterByCard(type) {
            if (document.getElementById('filterStatus')) {
                document.getElementById('filterStatus').value = '';
            }
            if (currentCardFilter === type) {
                if (type !== 'all') {
                    currentCardFilter = 'all';
                }
            } else {
                currentCardFilter = type;
            }
            updateCardFilterUI();
            currentPage = 1;
            filterClientTable();
        }

        function updateCardFilterUI() {
            const chips = {
                'all': 'kpiChipTotal',
                'unposted': 'kpiChipUnposted',
                'posted': 'kpiChipPosted',
                'not_inv': 'kpiChipNotInv'
            };
            Object.values(chips).forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.remove('active');
            });
            const activeId = chips[currentCardFilter] || 'kpiChipTotal';
            const activeEl = document.getElementById(activeId);
            if (activeEl) activeEl.classList.add('active');
        }

        function filterClientTable() {
            const fBc = (document.getElementById('tblFilterBc')?.value || '').toLowerCase().trim();
            const fAju = (document.getElementById('tblFilterAju')?.value || '').toLowerCase().trim();
            const fDaftar = (document.getElementById('tblFilterDaftar')?.value || '').toLowerCase().trim();
            const fRespon = (document.getElementById('tblFilterRespon')?.value || '').toLowerCase().trim();
            const fJalur = (document.getElementById('tblFilterJalur')?.value || '').toLowerCase().trim();
            const fPerusahaan = (document.getElementById('tblFilterPerusahaan')?.value || '').toLowerCase().trim();
            const fKantor = (document.getElementById('tblFilterKantor')?.value || '').toLowerCase().trim();

            filteredData = ajuData.filter(r => {
                // Card Filter (Total, Belum Selesai, Selesai Proses, Belum Masuk IT Inv)
                if (currentCardFilter === 'unposted') {
                    const np = (r.namaProses || '').toLowerCase();
                    const isSelesai = np.includes('selesai') || parseInt(r.posted) > 0;
                    if (isSelesai) return false;
                } else if (currentCardFilter === 'posted') {
                    const np = (r.namaProses || '').toLowerCase();
                    const isSelesai = np.includes('selesai') || parseInt(r.posted) > 0;
                    if (!isSelesai) return false;
                } else if (currentCardFilter === 'not_inv') {
                    const isInv = parseInt(r.is_inv !== undefined ? r.is_inv : 1, 10);
                    const inItInv = parseInt(r.in_it_inv || 0, 10) > 0;
                    if (isInv !== 1 || inItInv) return false;
                }

                if (fBc && !('bc ' + r.bc).toLowerCase().includes(fBc)) return false;
                if (fAju && !r.nomor_aju.toLowerCase().includes(fAju)) return false;
                if (fDaftar && !(r.nomor_daftar || '').toLowerCase().includes(fDaftar)) return false;
                if (fRespon && !(r.bcd_namaRespon || (parseInt(r.posted) > 0 ? 'sppb' : '')).toLowerCase().includes(fRespon)) return false;
                if (fJalur && !(r.bcd_kodeJalur || 'h').toLowerCase().includes(fJalur)) return false;
                if (fPerusahaan && !(r.cmp_name || r.bs || '').toLowerCase().includes(fPerusahaan)) return false;
                if (fKantor && !(r.bcd_namaKantorPendek || r.kode_kantor || '').toLowerCase().includes(fKantor)) return false;
                return true;
            });

            // Update KPI metrics (selalu dihitung dari seluruh data ajuData)
            let unposted = 0, posted = 0, notInv = 0;
            let isInvEnabled = false;
            ajuData.forEach(r => {
                const np = (r.namaProses || '').toLowerCase();
                if (np.includes('selesai') || parseInt(r.posted) > 0) posted++; else unposted++;
                const isInv = parseInt(r.is_inv !== undefined ? r.is_inv : 1, 10);
                if (isInv === 1) {
                    isInvEnabled = true;
                    if (parseInt(r.in_it_inv || 0, 10) === 0) {
                        notInv++;
                    }
                }
            });

            if (document.getElementById('kpiTotal')) document.getElementById('kpiTotal').innerText = ajuData.length;
            if (document.getElementById('kpiUnposted')) document.getElementById('kpiUnposted').innerText = unposted;
            if (document.getElementById('kpiPosted')) document.getElementById('kpiPosted').innerText = posted;

            const chipNotInv = document.getElementById('kpiChipNotInv');
            if (chipNotInv) {
                if (isInvEnabled) {
                    chipNotInv.style.display = 'inline-flex';
                    if (document.getElementById('kpiNotInv')) document.getElementById('kpiNotInv').innerText = notInv;
                } else {
                    chipNotInv.style.display = 'none';
                    if (currentCardFilter === 'not_inv') {
                        currentCardFilter = 'all';
                        updateCardFilterUI();
                    }
                }
            }

            currentPage = 1;
            renderTable();
        }

        function resetFilters() {
            currentCardFilter = 'all';
            updateCardFilterUI();
            if (document.getElementById('filterBc')) document.getElementById('filterBc').value = '';
            if (document.getElementById('filterStatus')) document.getElementById('filterStatus').value = '';
            if (document.getElementById('filterSearch')) document.getElementById('filterSearch').value = '';
            if (document.getElementById('tblFilterBc')) document.getElementById('tblFilterBc').value = '';
            if (document.getElementById('tblFilterAju')) document.getElementById('tblFilterAju').value = '';
            if (document.getElementById('tblFilterDaftar')) document.getElementById('tblFilterDaftar').value = '';
            if (document.getElementById('tblFilterRespon')) document.getElementById('tblFilterRespon').value = '';
            if (document.getElementById('tblFilterJalur')) document.getElementById('tblFilterJalur').value = '';
            if (document.getElementById('tblFilterPerusahaan')) document.getElementById('tblFilterPerusahaan').value = '';
            if (document.getElementById('tblFilterKantor')) document.getElementById('tblFilterKantor').value = '';
            loadList();
        }

        function renderTable() {
            const tbody = document.getElementById('ajuTableBody');
            if (!filteredData || filteredData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:28px; color:#8c8c8c;">Tidak ada dokumen AJU yang sesuai dengan filter.</td></tr>`;
                selectedAju.clear();
                updateSelectedCount();
                renderPagination(0, 1, pageSize);
                return;
            }

            const total = filteredData.length;
            const totalPages = Math.ceil(total / pageSize) || 1;
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const startIdx = (currentPage - 1) * pageSize;
            const endIdx = Math.min(startIdx + pageSize, total);
            const pageItems = filteredData.slice(startIdx, endIdx);

            let html = '';
            pageItems.forEach((r, idx) => {
                const itemNum = startIdx + idx + 1;
                const isSelected = selectedAju.has(r.nomor_aju);
                const isPosted = parseInt(r.posted) > 0;

                // Status Chip (Gate In TPB/KEK / Stuffing / Selesai Proses / Proses Pabean)
                let tagClass = 'ant-tag-blue';
                let tagText = 'Proses Pabean';
                if (r.namaProses) {
                    tagText = r.namaProses;
                    const np = tagText.toLowerCase();
                    if (np.includes('selesai')) tagClass = 'ant-tag-green';
                    else if (np.includes('gate')) tagClass = 'ant-tag-gold';
                    else if (np.includes('stuffing')) tagClass = 'ant-tag-gold';
                    else if (np.includes('reject') || np.includes('tolak')) tagClass = 'ant-tag-red';
                } else if (isPosted) {
                    tagClass = 'ant-tag-blue';
                    tagText = `Terkirim (${(r.posted_waktu || '').substring(0, 10)})`;
                }

                // Jalur (H / M / K)
                let jalurChar = (r.bcd_kodeJalur || '').toUpperCase();
                if (!jalurChar) jalurChar = 'H';
                let jalurTag = 'ant-tag-green';
                if (jalurChar === 'M') jalurTag = 'ant-tag-red';
                if (jalurChar === 'K') jalurTag = 'ant-tag-gold';

                // Nama Perusahaan & NPWP
                const companyName = r.cmp_name || r.bs || '-';
                const companyId = r.cmp_npwp || r.id_bs || r.cmp_id || '-';

                // Kantor Pabean
                const kantorName = r.bcd_namaKantorPendek || (r.kode_kantor ? 'KPPBC ' + r.kode_kantor : 'KPPBC TMP A BEKASI');

                // Respon
                const namaRespon = r.bcd_namaRespon || (r.sppb_nomor_respon ? 'SPPB' : (isPosted ? 'SPPB' : '-'));
                const noRespon = r.bcd_nomorRespon || r.sppb_nomor_respon || r.sppb || '';

                let responBadgeExtra = '';
                if (r.bcd_namaRespon && !r.bcd_namaRespon.toUpperCase().includes('SPPB') && r.sppb_nomor_respon) {
                    responBadgeExtra = ` <span class="ant-tag ant-tag-green" style="font-size:9px; padding:0 3px; margin-left:2px;" title="Telah Terbit SPPB: ${escapeHtml(r.sppb_nomor_respon)}">✓ SPPB</span>`;
                }

                // PDF Respon (SPPB / NPE / Respon Resmi)
                const pdfFile = r.pdf || r.sppb_pdf || '';
                let pdfHtml = '<span style="color:#d9d9d9;">-</span>';
                if (pdfFile) {
                    const pdfUrl = `/pdf.php?aju=${encodeURIComponent(r.nomor_aju)}&pdf=${encodeURIComponent(pdfFile)}&cmp_id=${encodeURIComponent(r.cmp_id || '')}&view=1`;
                    pdfHtml = `
                        <a href="${pdfUrl}" target="_blank" class="pdf-btn-badge" title="Klik untuk membuka dokumen PDF resmi (${escapeHtml(pdfFile)})">
                            <svg viewBox="64 64 896 896" width="13" height="13" fill="#ff4d4f" style="vertical-align:-1px;">
                                <path d="M532 224h260c4.4 0 8 3.6 8 8v632c0 4.4-3.6 8-8 8H232c-4.4 0-8-3.6-8-8V160c0-4.4 3.6-8 8-8h300v72zm-72 328h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm-144 0h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm320 0h-88c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c4.4 0 8-3.6 8-8v-48h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8h-48v-32h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8z"></path>
                            </svg>
                            <span style="font-size:10px; font-weight:600; color:#cf1322; margin-left:2px;">PDF</span>
                        </a>
                    `;
                }

                // Cek status IT Inventory jika IS_INV = 1
                const isInv = parseInt(r.is_inv !== undefined ? r.is_inv : 1, 10);
                const inItInv = parseInt(r.in_it_inv || 0, 10) > 0;
                let itInvIconHtml = '';
                if (isInv === 1 && !inItInv) {
                    itInvIconHtml = `
                        <span style="cursor:pointer; margin-left:6px; display:inline-flex; align-items:center; color:#ff4d4f;" title="Dokumen belum masuk IT Inventory (bc_1ab2)" onclick="openTrackingHistory('${r.nomor_aju}', '${r.cmp_id || ''}')">
                            <svg viewBox="64 64 896 896" width="13" height="13" fill="currentColor">
                                <path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm165.4 618.2l-66-.3L512 563.1l-99.3 118.8-66 .3c-4.4 0-8-3.6-8-8 0-2.1.8-4.1 2.3-5.6l120-143.7-120.1-143.8a8 8 0 0 1-2.2-5.7c0-4.4 3.6-8 8-8l66 .3L512 485.9l99.4-118.8 66-.3c4.4 0 8 3.6 8 8 0 2.1-.8 4.2-2.3 5.7L563.1 524.3l120.1 143.7c1.5 1.5 2.2 3.5 2.2 5.6 0 4.4-3.6 8.4-8 8.4z"></path>
                            </svg>
                        </span>
                    `;
                }

                html += `
                    <tr class="ant-table-row ant-table-row-level-0 ${isSelected ? 'selected-row' : ''}" id="row-${r.nomor_aju}">
                        <td style="color:#8c8c8c; font-family:monospace;">${itemNum}</td>
                        <td style="font-weight:500;">BC ${r.bc}</td>
                        <td>
                            <div style="display:flex; align-items:center;">
                                <a class="nomor-aju-link" onclick="openTrackingHistory('${r.nomor_aju}', '${r.cmp_id || ''}')" title="Klik untuk melihat riwayat tracking status">${r.nomor_aju}</a>
                                ${itInvIconHtml}
                            </div>
                            <p style="margin:4px 0px;"><span class="ant-tag ${tagClass}">${escapeHtml(tagText)}</span></p>
                        </td>
                        <td>
                            <div>${r.nomor_daftar || '<span style="color:#bfbfbf;">-</span>'}</div>
                            <p style="margin:0; font-size:11px; color:#8c8c8c;">${r.tanggal_daftar || ''}</p>
                        </td>
                        <td>
                            <div>${escapeHtml(namaRespon)}${responBadgeExtra}</div>
                            ${noRespon ? `<p style="cursor:pointer; text-decoration:underline; color:rgb(3, 157, 252); margin:0; font-size:10px;" onclick="openTrackingHistory('${r.nomor_aju}', '${r.cmp_id || ''}')" title="Lihat Riwayat Tracking Status">${escapeHtml(noRespon)}</p>` : ''}
                        </td>
                        <td style="text-align:center;">
                            ${pdfHtml}
                        </td>
                        <td style="font-size:10.5px; color:#595959;">${r.bcd_tanggalRespon || r.sppb_tanggal_respon || r.posted_waktu || r.tanggal_pernyataan || '-'}</td>
                        <td style="text-align:center;">
                            <span class="ant-tag ${jalurTag}">${jalurChar}</span>
                        </td>
                        <td style="max-width:240px; overflow:hidden; text-overflow:ellipsis;">
                            <div style="display:flex; font-size:10px; color:#8c8c8c; font-family:monospace;">${escapeHtml(companyId)}</div>
                            <p style="margin:0; font-weight:500; font-size:11px;">${escapeHtml(companyName)}</p>
                        </td>
                        <td style="font-size:10.5px; color:#595959;">${escapeHtml(kantorName)}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            updateSelectedCount();
            renderPagination(total, currentPage, pageSize);
        }

        function renderPagination(total, page, size) {
            const pag = document.getElementById('tablePagination');
            if (!pag) return;
            if (total === 0) {
                pag.innerHTML = '<li class="ant-pagination-total-text">Total 0 item</li>';
                return;
            }

            const totalPages = Math.ceil(total / size) || 1;
            const startItem = (page - 1) * size + 1;
            const endItem = Math.min(page * size, total);

            let html = `<li class="ant-pagination-total-text">Menampilkan ${startItem}-${endItem} dari ${total} item</li>`;

            // Prev button
            const prevDisabled = page <= 1 ? 'ant-pagination-disabled' : '';
            html += `<li class="ant-pagination-prev ${prevDisabled}" onclick="${page > 1 ? `goToPage(${page - 1})` : ''}" title="Halaman Sebelumnya"><a>‹</a></li>`;

            // Page numbers
            let startP = Math.max(1, page - 2);
            let endP = Math.min(totalPages, page + 2);

            if (startP > 1) {
                html += `<li class="ant-pagination-item ${page === 1 ? 'ant-pagination-item-active' : ''}" onclick="goToPage(1)"><a>1</a></li>`;
                if (startP > 2) {
                    html += `<li class="ant-pagination-item ant-pagination-disabled"><a>...</a></li>`;
                }
            }

            for (let p = startP; p <= endP; p++) {
                const active = p === page ? 'ant-pagination-item-active' : '';
                html += `<li class="ant-pagination-item ${active}" onclick="goToPage(${p})"><a>${p}</a></li>`;
            }

            if (endP < totalPages) {
                if (endP < totalPages - 1) {
                    html += `<li class="ant-pagination-item ant-pagination-disabled"><a>...</a></li>`;
                }
                html += `<li class="ant-pagination-item ${page === totalPages ? 'ant-pagination-item-active' : ''}" onclick="goToPage(${totalPages})"><a>${totalPages}</a></li>`;
            }

            // Next button
            const nextDisabled = page >= totalPages ? 'ant-pagination-disabled' : '';
            html += `<li class="ant-pagination-next ${nextDisabled}" onclick="${page < totalPages ? `goToPage(${page + 1})` : ''}" title="Halaman Berikutnya"><a>›</a></li>`;

            // Page size dropdown
            html += `
                <li style="margin-left:6px;">
                    <select onchange="setPageSize(this.value)" style="height:20px; font-size:10px; border:1px solid #d9d9d9; border-radius:2px; background:transparent; color:var(--text-main); cursor:pointer;">
                        <option value="25" ${size === 25 ? 'selected' : ''}>25 / hal</option>
                        <option value="50" ${size === 50 ? 'selected' : ''}>50 / hal</option>
                        <option value="100" ${size === 100 ? 'selected' : ''}>100 / hal</option>
                        <option value="200" ${size === 200 ? 'selected' : ''}>200 / hal</option>
                    </select>
                </li>
            `;

            pag.innerHTML = html;
        }

        function openEditorForm(nomorAju) {
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('editor', nomorAju || '');
            } else {
                const url = nomorAju ? `/editor.php?nomor_aju=${encodeURIComponent(nomorAju)}` : '/editor.php';
                window.location.href = url;
            }
        }

        function toggleSelect(nomorAju, isChecked) {
            if (isChecked) {
                selectedAju.add(nomorAju);
                document.getElementById(`row-${nomorAju}`)?.classList.add('selected-row');
            } else {
                selectedAju.delete(nomorAju);
                document.getElementById(`row-${nomorAju}`)?.classList.remove('selected-row');
            }
            updateSelectedCount();
        }

        function toggleSelectAll(masterCheckbox) {
            const isChecked = masterCheckbox.checked;
            filteredData.forEach(r => {
                if (isChecked) {
                    selectedAju.add(r.nomor_aju);
                } else {
                    selectedAju.delete(r.nomor_aju);
                }
                const row = document.getElementById(`row-${r.nomor_aju}`);
                if (row) {
                    row.classList.toggle('selected-row', isChecked);
                    const cb = row.querySelector('input[type="checkbox"]');
                    if (cb) cb.checked = isChecked;
                }
            });
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const count = (typeof selectedAju !== 'undefined' && selectedAju) ? selectedAju.size : 0;
            const el = document.getElementById('selectedCount');
            if (el) el.innerText = count;
        }

        // =========================================================================
        // HISTORY TRACKING PERUBAHAN STATUS CEISA 4.0
        // =========================================================================
        let activeTrackingAju = null;

        async function openTrackingHistory(nomorAju, cmpId) {
            activeTrackingAju = { nomorAju, cmpId };

            const modal = document.getElementById('modalTrackingHistory');
            if (!modal) return;
            modal.style.display = 'flex';

            const txtAju = document.getElementById('modalTrackAjuText');
            if (txtAju) txtAju.textContent = nomorAju;
            const dokBadge = document.getElementById('modalTrackDokBadge');
            if (dokBadge) dokBadge.textContent = 'BC -';
            const txtDaftar = document.getElementById('modalTrackDaftarText');
            if (txtDaftar) txtDaftar.textContent = 'Memuat...';
            const txtPerusahaan = document.getElementById('modalTrackPerusahaanText');
            if (txtPerusahaan) txtPerusahaan.textContent = '-';
            const txtKantor = document.getElementById('modalTrackKantorText');
            if (txtKantor) txtKantor.textContent = '-';

            const tbStatus = document.getElementById('modalTrackStatusTbody');
            if (tbStatus) tbStatus.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><span class="spinner"></span> Memuat riwayat status proses...</td></tr>`;
            const tbRespon = document.getElementById('modalTrackResponTbody');
            if (tbRespon) tbRespon.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);"><span class="spinner"></span> Memuat respon resmi Bea Cukai...</td></tr>`;
            const rawBox = document.getElementById('modalTrackRawBox');
            if (rawBox) rawBox.textContent = '// Menghubungi database...';

            switchTrackingTab('status');

            try {
                const res = await fetch(`daftar_dokumen.php?action=get_timeline&nomor_aju=${encodeURIComponent(nomorAju)}&cmp_id=${encodeURIComponent(cmpId || '')}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    renderTrackingDetails(result);
                } else {
                    if (tbStatus) tbStatus.innerHTML = `<tr><td colspan="5" style="color:#ff4d4f; text-align:center; padding:16px;">${escapeHtml(result.message || 'Gagal memuat status dokumen')}</td></tr>`;
                }
            } catch (err) {
                if (tbStatus) tbStatus.innerHTML = `<tr><td colspan="5" style="color:#ff4d4f; text-align:center; padding:16px;">Gagal memuat riwayat: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderTrackingDetails(data) {
            const hdr = data.header || {};
            const dRow = data.data_row || {};
            const kdDok = hdr.kode_dokumen || dRow.kode_dokumen || (data.data_status && data.data_status[0]?.kode_dokumen) || '-';
            const noDaftar = hdr.nomor_daftar || dRow.nomor_daftar || (data.data_status && data.data_status[0]?.nomor_daftar) || '-';
            const tglDaftar = hdr.tanggal_daftar || dRow.tanggal_daftar || (data.data_status && data.data_status[0]?.tanggal_daftar) || '-';
            const compName = hdr.nama_pernyataan || dRow.nama_perusahaan || '-';
            const kantor = dRow.nama_kantor || (dRow.kode_kantor ? `KPPBC ${dRow.kode_kantor}` : '-');

            const dokBadge = document.getElementById('modalTrackDokBadge');
            if (dokBadge) dokBadge.textContent = kdDok !== '-' ? `BC ${kdDok}` : 'BC';
            const txtDaftar = document.getElementById('modalTrackDaftarText');
            if (txtDaftar) txtDaftar.textContent = (noDaftar !== '-' && noDaftar) ? `${noDaftar} (Tgl: ${tglDaftar})` : 'Belum Terdaftar';
            const txtPerusahaan = document.getElementById('modalTrackPerusahaanText');
            if (txtPerusahaan) txtPerusahaan.textContent = compName;
            const txtKantor = document.getElementById('modalTrackKantorText');
            if (txtKantor) txtKantor.textContent = kantor;

            // 1. Visual Stepper Tahapan 1 - 5
            const stages = data.stages || {};
            let stepperHtml = `
                <div class="stepper-progress-bar"></div>
                <div class="stepper-progress-active" id="modalStepperActiveBar" style="width:0%;"></div>
            `;
            let doneCount = 0;
            const stageKeys = [1, 2, 3, 4, 5];
            stageKeys.forEach(k => {
                const st = stages[k] || { title: '', desc: '', done: false, time: '' };
                if (st.done) doneCount++;
                const isCurrentActive = st.done && (k === 5 || !stages[k + 1]?.done);
                const stepClass = st.done ? (isCurrentActive ? 'done active' : 'done') : '';
                const icon = st.done ? '✓' : k;

                stepperHtml += `
                    <div class="step-item ${stepClass}">
                        <div class="step-icon">${icon}</div>
                        <div class="step-title">${escapeHtml(st.title)}</div>
                        <div class="step-desc">${escapeHtml(st.desc)}</div>
                        <div class="step-time">${escapeHtml(st.time ? st.time.substring(0, 16) : '')}</div>
                    </div>
                `;
            });
            const vStepper = document.getElementById('modalVisualStepper');
            if (vStepper) vStepper.innerHTML = stepperHtml;
            const activePercent = Math.max(0, Math.min(100, (doneCount - 1) * 25));
            setTimeout(() => {
                const bar = document.getElementById('modalStepperActiveBar');
                if (bar) bar.style.width = activePercent + '%';
            }, 50);

            // 2. Tab 1: Status Proses
            const statusList = data.data_status || [];
            const cntStatus = document.getElementById('modalTrackCountStatus');
            if (cntStatus) cntStatus.textContent = statusList.length;
            const tbStatus = document.getElementById('modalTrackStatusTbody');
            if (tbStatus) {
                if (statusList.length === 0) {
                    tbStatus.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada riwayat status proses pabean untuk dokumen ini.</td></tr>`;
                } else {
                    let sHtml = '';
                    statusList.forEach((s, idx) => {
                        sHtml += `
                            <tr>
                                <td style="text-align:center; color:var(--text-muted); font-size:10px;">${idx + 1}</td>
                                <td><code style="font-size:10px;">${escapeHtml(s.waktu_status || '-')}</code></td>
                                <td><span class="ant-tag ant-tag-blue" style="font-size:9.5px;">${escapeHtml(s.kode_proses || '-')}</span></td>
                                <td><strong>${escapeHtml(s.keterangan || '-')}</strong></td>
                                <td>${escapeHtml(s.nomor_daftar || '-')}</td>
                            </tr>
                        `;
                    });
                    tbStatus.innerHTML = sHtml;
                }
            }

            // 3. Tab 2: Respon Resmi
            const respList = data.data_respon || [];
            const cntRespon = document.getElementById('modalTrackCountRespon');
            if (cntRespon) cntRespon.textContent = respList.length;
            const tbRespon = document.getElementById('modalTrackResponTbody');
            if (tbRespon) {
                if (respList.length === 0) {
                    tbRespon.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada respon resmi yang diterbitkan oleh Bea Cukai.</td></tr>`;
                } else {
                    let rHtml = '';
                    respList.forEach((rp, idx) => {
                        let pdfBtn = '<span style="color:var(--text-muted);">-</span>';
                        if (rp.pdf) {
                            pdfBtn = `<a href="pdf.php?aju=${encodeURIComponent(data.nomor_aju)}&pdf=${encodeURIComponent(rp.pdf)}" target="_blank" class="pdf-btn-badge" style="display:inline-flex; align-items:center; gap:2px; font-weight:600; text-decoration:none;">
                                <svg viewBox="64 64 896 896" width="12" height="12" fill="#ff4d4f"><path d="M532 224h260c4.4 0 8 3.6 8 8v632c0 4.4-3.6 8-8 8H232c-4.4 0-8-3.6-8-8V160c0-4.4 3.6-8 8-8h300v72zm-72 328h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm-144 0h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm320 0h-88c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c4.4 0 8-3.6 8-8v-48h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8h-48v-32h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8z"></path></svg>
                                <span>PDF</span>
                            </a>`;
                        }
                        rHtml += `
                            <tr>
                                <td style="text-align:center; color:var(--text-muted); font-size:10px;">${idx + 1}</td>
                                <td><span class="ant-tag ant-tag-blue" style="font-size:9.5px;">${escapeHtml(rp.kode_respon || '-')}</span></td>
                                <td><strong>${escapeHtml(rp.nomor_respon || '-')}</strong></td>
                                <td style="font-size:10px;">${escapeHtml(rp.tanggal_respon || '-')}</td>
                                <td>${escapeHtml(rp.keterangan || '-')}</td>
                                <td style="text-align:center;">${pdfBtn}</td>
                            </tr>
                        `;
                    });
                    tbRespon.innerHTML = rHtml;
                }
            }

            // 4. Tab 3: Log Mentah (JSON)
            const rawBox = document.getElementById('modalTrackRawBox');
            if (rawBox) {
                if (data.raw_log && data.raw_log.respon) {
                    try {
                        const parsed = JSON.parse(data.raw_log.respon);
                        rawBox.textContent = JSON.stringify(parsed, null, 2);
                    } catch (e) {
                        rawBox.textContent = data.raw_log.respon;
                    }
                } else {
                    rawBox.textContent = '// Belum ada log respon mentah yang tersimpan di bc_ceisa_respon_logs untuk nomor AJU ini.';
                }
            }
        }

        function switchTrackingTab(tab) {
            document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));

            if (tab === 'status') {
                const b = document.getElementById('tabBtnStatus');
                if (b) b.classList.add('active');
                const p = document.getElementById('paneTrackStatus');
                if (p) p.classList.add('active');
            } else if (tab === 'respon') {
                const b = document.getElementById('tabBtnRespon');
                if (b) b.classList.add('active');
                const p = document.getElementById('paneTrackRespon');
                if (p) p.classList.add('active');
            } else if (tab === 'raw') {
                const b = document.getElementById('tabBtnRaw');
                if (b) b.classList.add('active');
                const p = document.getElementById('paneTrackRaw');
                if (p) p.classList.add('active');
            }
        }

        function copyTrackRawJson() {
            const el = document.getElementById('modalTrackRawBox');
            const text = el ? el.innerText : '';
            navigator.clipboard.writeText(text).then(() => {
                showSuccess('JSON respon mentah berhasil disalin ke clipboard!');
            }).catch(err => {
                showError('Gagal menyalin: ' + err);
            });
        }

        async function syncTrackingModalAju() {
            if (!activeTrackingAju) return;
            const btn = document.getElementById('btnModalSyncLive');
            if (btn) btn.disabled = true;

            Swal.fire({
                title: 'Tarik / Sync Respon CEISA',
                html: `Menghubungi portal Bea Cukai untuk memeriksa respon dokumen AJU:<br><b style="font-family:monospace; color:var(--primary);">${escapeHtml(activeTrackingAju.nomorAju)}</b>...`,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch(`statusaju.php?action=sync_single&aju=${encodeURIComponent(activeTrackingAju.nomorAju)}&cmp_id=${encodeURIComponent(activeTrackingAju.cmpId || '')}`);
                const result = await res.json();

                if (result.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sinkronisasi Berhasil!',
                        text: result.message || 'Status dan respon dokumen berhasil diperbarui.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    openTrackingHistory(activeTrackingAju.nomorAju, activeTrackingAju.cmpId);
                    loadList();
                } else {
                    const isNotFound = result.is_not_found || (result.message && result.message.toLowerCase().includes('tidak ditemukan'));
                    if (isNotFound) {
                        Swal.fire({
                            icon: 'warning',
                            title: '<span style="font-size:18px; font-weight:700; color:#d97706;">Data Tidak Ditemukan di CEISA 4.0</span>',
                            html: `
                                <div style="text-align:center; margin-bottom:14px;">
                                    <div style="display:inline-block; padding:5px 14px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; font-family:monospace; font-size:13px; font-weight:700; color:#1e293b; letter-spacing:0.5px;">
                                        ${escapeHtml(activeTrackingAju.nomorAju)}
                                    </div>
                                </div>
                                <div style="font-size:13px; color:#475569; line-height:1.6; margin-bottom:14px; text-align:left;">
                                    Portal CEISA 4.0 menyatakan dokumen pengajuan ini <b>tidak ditemukan</b> dalam database Bea Cukai.
                                </div>
                                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-left:4px solid #3b82f6; border-radius:6px; padding:12px 14px; text-align:left; font-size:12px; color:#1e40af; margin-bottom:14px; line-height:1.5;">
                                    <div style="font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                        <i class="fas fa-undo-alt" style="color:#2563eb;"></i> Status Pengiriman Direset
                                    </div>
                                    Tanda status pengiriman (<code>bc_ceisa_aju_posted</code>) telah dibersihkan sehingga status kembali menjadi <b>Belum Kirim (Putih)</b>.
                                </div>
                            `,
                            confirmButtonText: 'Mengerti',
                            confirmButtonColor: '#3b82f6'
                        });
                        loadList();
                    } else {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Respon CEISA 4.0',
                            text: result.message || 'Tidak ada pembaruan status baru dari CEISA 4.0.'
                        });
                    }
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Sinkronisasi',
                    text: err.message
                });
            } finally {
                if (btn) btn.disabled = false;
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
            // Otomatis sinkronisasi latar belakang saat halaman dibuka (progress tidak terlihat)
            setTimeout(() => {
                triggerSilentCeisaSync(false);
            }, 300);
        };
    </script>
</body>
</html>


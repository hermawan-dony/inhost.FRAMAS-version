<?php
/**
 * CEISA 4.0 - Transfer Dokumen Pabean Dashboard (transfer.php)
 * Mengadopsi Layout, Warna, Font, dan Ant Design Style Resmi Portal CEISA 4.0 (portal.beacukai.go.id/dokumen-pabean/)
 * Format: C:\dp\g-ceisa\share\Portal Ceisa 4.0.html
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';
require_once __DIR__ . '/datalogin.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? '');

// Multi-tenant Subdomain Fallback: Jika session kosong, deteksi otomatis dari subdomain Host
if (empty($sessionCmpId) && isset($koneksi) && $koneksi) {
    $httpHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $parts = explode('.', $httpHost);
    if (count($parts) > 2) {
        $subCandidate = preg_replace('/[^a-zA-Z0-9_-]/', '', $parts[0]);
        if (!in_array($subCandidate, ['www', 'mail', 'cpanel', 'webmail', 'inhost'])) {
            $qSub = $koneksi->query("SELECT cmp_id FROM bc_ceisa_company WHERE LOWER(subdomain) = '{$koneksi->real_escape_string($subCandidate)}' AND is_active = 1 LIMIT 1");
            if ($qSub && $rSub = $qSub->fetch_assoc()) {
                $sessionCmpId = $rSub['cmp_id'];
                $_SESSION['company_cmp_id'] = $sessionCmpId;
            }
        }
    }
}

$appSettings = function_exists('getAppSettings') ? getAppSettings($koneksi, $sessionCmpId) : [];
$syncIntervalMin = max(15, (int)($appSettings['ceisa_sync_interval_minutes'] ?? 60));
$syncBatchSize   = max(10, min(1000, (int)($appSettings['ceisa_sync_batch_size'] ?? 50)));

// Ambil daftar profil perusahaan aktif untuk filter
$targetCompanyRoles = 'tpb';
$targetCompanyAllowedDocs = null;
$targetCompanyShowUnimportedH2h = 0;
$companies = [];
if (isset($koneksi) && $koneksi) {
    $resComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, IFNULL(company_roles, 'tpb') AS company_roles, allowed_docs, id_user_ceisa, IFNULL(show_unimported_h2h, 0) AS show_unimported_h2h FROM bc_ceisa_company ORDER BY cmp_name ASC");
    if ($resComp) {
        while ($r = $resComp->fetch_assoc()) {
            if ($r['cmp_id'] === $sessionCmpId) {
                $targetCompanyRoles = $r['company_roles'];
                $targetCompanyAllowedDocs = $r['allowed_docs'];
                $targetCompanyShowUnimportedH2h = (int)($r['show_unimported_h2h'] ?? 0);
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
        $targetAllowed = !empty($isAdmin) ? (array)$companyAllowedDocs : (array)$effectiveAllowedDocs;
        if ($d === '27IN') {
            return in_array('27', $targetAllowed) || in_array('27IN', $targetAllowed);
        }
        return in_array((string)$d, $targetAllowed);
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
    header('Content-Type: application/json; charset=utf-8');
    $filter_cmp  = trim($_GET['cmp_id'] ?? '');
    $filter_bc   = trim($_GET['bc'] ?? '');
    $filter_stat = trim($_GET['status'] ?? '');
    $search      = trim($_GET['q'] ?? '');

    // Multi-Tenant Isolation & Keamanan:
    // 1. Jika filter_cmp tidak dispesifikasikan di URL, gunakan sessionCmpId perusahaan aktif
    if (empty($filter_cmp) && !empty($sessionCmpId) && $sessionCmpId !== 'SYSTEM') {
        $filter_cmp = $sessionCmpId;
    }
    // 2. Pengguna biasa (non-admin) WAJIB terkunci secara mutlak pada perusahaannya sendiri
    if (!$isAdmin && !empty($sessionCmpId)) {
        $filter_cmp = $sessionCmpId;
    }

    $where = [];
    $cmpIsInv = 1;
    if (!empty($filter_cmp) && $filter_cmp !== 'ALL' && $filter_cmp !== '*') {
        $cleanCmp = $koneksi->real_escape_string($filter_cmp);
        $cmpNpwp = '';
        $resComp = $koneksi->query("SELECT npwp, IFNULL(IS_INV, 1) AS IS_INV FROM bc_ceisa_company WHERE cmp_id = '$cleanCmp' LIMIT 1");
        if ($resComp && $cr = $resComp->fetch_assoc()) {
            $cmpNpwp = preg_replace('/[^0-9]/', '', $cr['npwp']);
            $cmpIsInv = (int)$cr['IS_INV'];
        }
        if (!empty($cmpNpwp)) {
            $where[] = "(a.cmp_id = '$cleanCmp' OR a.idPerusahaan LIKE '%$cmpNpwp%')";
        } else {
            $where[] = "a.cmp_id = '$cleanCmp'";
        }
    }
    if (!empty($filter_bc)) {
        if ($filter_bc === '27IN') {
            $where[] = "(a.kodeDokumen = '27IN' OR (a.kodeDokumen = '27' AND (a.namaProses LIKE '%MASUK%' OR a.namaDokumen LIKE '%MASUK%')))";
        } else {
            $where[] = "a.kodeDokumen = '" . $koneksi->real_escape_string($filter_bc) . "'";
        }
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
    } elseif ($filter_stat === 'not_h2h') {
        $where[] = "(staging.jml IS NULL OR staging.jml = 0)";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(a.nomorAju LIKE '%$s%' OR a.nomorDaftar LIKE '%$s%' OR a.namaPerusahaan LIKE '%$s%' OR a.namaEntitas LIKE '%$s%' OR a.namaRespon LIKE '%$s%' OR a.nomorRespon LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT
        COALESCE(NULLIF(a.idHeader, ''), '') AS id_header,
        COALESCE(NULLIF(a.kodeProses, ''), '') AS kode_proses,
        a.kodeDokumen AS bc,
        a.kodeDokumen AS kode_dokumen,
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
        COALESCE(NULLIF(a.cmp_id, ''), cmp.cmp_id, '') AS cmp_id,
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
        COALESCE(NULLIF(cmp.id_user_ceisa, ''), '') AS id_user_ceisa,
        COALESCE(cmp.IS_INV, $cmpIsInv, 1) AS is_inv,
        IFNULL(it_inv.jml, 0) AS in_it_inv,
        IFNULL(staging.jml, 0) AS in_staging,
        COALESCE(cmp.show_unimported_h2h, 0) AS show_unimported_h2h,
        COALESCE(DATE_FORMAT(a.tanggalRespon, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(COALESCE(sppb_tbl.tanggalRespon, resp_sppb.tanggal_respon), '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(posted.waktu, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(a.tanggalDaftar, '%Y-%m-%d 00:00:00'), '') AS effective_tanggal_respon
    FROM bc_ceisa_data a
    LEFT JOIN bc_ceisa_company cmp ON a.cmp_id = cmp.cmp_id
    LEFT JOIN (
        SELECT DISTINCT nomor_aju, 1 AS jml
        FROM bc_1ab2
        WHERE nomor_aju IS NOT NULL AND nomor_aju != ''
    ) it_inv ON a.nomorAju = it_inv.nomor_aju
    LEFT JOIN (
        SELECT DISTINCT nomor_aju, 1 AS jml
        FROM bc_ceisa_header
        WHERE nomor_aju IS NOT NULL AND nomor_aju != ''
    ) staging ON a.nomorAju = staging.nomor_aju
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
    ORDER BY effective_tanggal_respon DESC, a.nomorAju DESC";

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
    $sqlBcd = "SELECT idHeader AS id_header, kodeDokumen AS kode_dokumen, nomorAju AS nomor_aju, nomorDaftar AS nomor_daftar, 
                      tanggalDaftar AS tanggal_daftar, namaPerusahaan AS nama_perusahaan, idPerusahaan AS id_perusahaan, 
                      kodeKantor AS kode_kantor, namaKantorPendek AS nama_kantor, namaProses AS nama_proses, 
                      namaRespon AS nama_respon, nomorRespon AS nomor_respon, tanggalRespon AS tanggal_respon, 
                      kodeJalur AS kode_jalur, pdf, totalCount AS total_count, cmp_id
               FROM bc_ceisa_data 
               WHERE nomorAju = '$escAju' LIMIT 1";
    $resBcd = $koneksi->query($sqlBcd);
    $rowBcd = $resBcd ? $resBcd->fetch_assoc() : null;

    // Ambil live riwayat status dari CEISA 4.0 via getRiwayatStatus/$idHeader jika tersedia dan ID User CEISA telah diatur
    $liveIdHeader = trim($rowBcd['id_header'] ?? '');
    $liveCmp = $cmp_id ?: ($rowBcd['cmp_id'] ?? '');
    $liveComp = ceisa_get_company($liveCmp ?: $escAju);
    if (!empty($liveIdHeader) && !empty($liveComp['id_user_ceisa'])) {
        $cmp_id = $liveCmp;
        $_GET['cmp_id'] = $liveCmp;
        require_once __DIR__ . '/initmfa.php';

        $liveApiKey = !empty($liveComp['api_key_ceisa']) ? trim($liveComp['api_key_ceisa']) : '6222a75e-1dbb-493e-9461-27f721097e9c';

        if (!empty($activeToken)) {
            $ch = curl_init();
            $statusHeaders = [
                'Authorization: Bearer ' . $activeToken,
                'beacukai-api-key: ' . $liveApiKey,
                'Accept: application/json, text/plain, */*',
                'Origin: https://portal.beacukai.go.id',
                'Referer: https://portal.beacukai.go.id/'
            ];
            $statusUrl = "https://apis-gw.beacukai.go.id/v2/parser/v1/proses/getRiwayatStatus/" . urlencode($liveIdHeader);
            if (function_exists('ceisa_prepare_curl')) {
                ceisa_prepare_curl($ch, $statusUrl, $statusHeaders, 20);
            } else {
                curl_setopt_array($ch, [
                    CURLOPT_URL => $statusUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => $statusHeaders
                ]);
            }
            $liveStatusJson = curl_exec($ch);
            curl_close($ch);
            $liveStatusArr = @json_decode($liveStatusJson, true);
            if (!empty($liveStatusArr['data']) && is_array($liveStatusArr['data'])) {
                $liveList = [];
                foreach ($liveStatusArr['data'] as $st) {
                    $liveList[] = [
                        'kode_dokumen'   => $rowBcd['kode_dokumen'] ?? '',
                        'nomor_aju'      => $nomor_aju,
                        'nomor_daftar'   => $rowBcd['nomor_daftar'] ?? '',
                        'tanggal_daftar' => $rowBcd['tanggal_daftar'] ?? '',
                        'kode_proses'    => $st['kodeProses'] ?? '',
                        'waktu_status'   => $st['waktuMulai'] ?? ($st['waktuStatus'] ?? ($st['waktuSelesai'] ?? '')),
                        'keterangan'     => $st['catatan'] ?? ($st['namaProses'] ?? ('Proses ' . ($st['kodeProses'] ?? '')))
                    ];
                }
                if (!empty($liveList)) {
                    $listStatus = $liveList;
                }
            }
        }
    }

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

// =============================================================================
// CEISA 4.0 OFFICIAL GATEWAY ACTIONS (BERBASIS idHeader, kodeDokumen & initMFA)
// =============================================================================

// 0. LIHAT JSON LNSW: https://apis-gw.beacukai.go.id/v2/report-parser/v1/lnsw/get-data-doc?idHeader=$idHeader
if ($action === 'view_json_lnsw') {
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? ($_REQUEST['aju'] ?? ''));
    $idHeader  = trim($_REQUEST['id_header'] ?? '');
    $targetCmp = trim($_REQUEST['cmp_id'] ?? '');
    $isRaw     = !empty($_REQUEST['raw']);

    if (empty($idHeader) && !empty($nomor_aju)) {
        $cleanAju = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomor_aju);
        $escAju = $koneksi->real_escape_string($cleanAju);
        $sqlDoc = "SELECT idHeader, cmp_id FROM bc_ceisa_data WHERE nomorAju = '{$escAju}' LIMIT 1";
        $resDoc = $koneksi->query($sqlDoc);
        if ($resDoc && $rowDoc = $resDoc->fetch_assoc()) {
            $idHeader = trim($rowDoc['idHeader'] ?? '');
            if (empty($targetCmp)) $targetCmp = $rowDoc['cmp_id'] ?? '';
        }
    }

    if (empty($idHeader)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'idHeader tidak ditemukan untuk dokumen ini.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $comp = ceisa_get_company(!empty($targetCmp) ? $targetCmp : $nomor_aju);
    if (empty($comp['id_user_ceisa'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'ID User CEISA belum diatur di menu Kelola Perusahaan (Tab Akun CEISA & TOTP MFA).'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $cmp_id = !empty($comp['cmp_id']) ? $comp['cmp_id'] : $targetCmp;
    $_GET['cmp_id'] = $cmp_id;
    require_once __DIR__ . '/initmfa.php';

    if (empty($activeToken)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mendapatkan token otentikasi MFA dari Bea Cukai.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Endpoint resmi CEISA 4.0 Report Parser LNSW (/td-header)
    $url = "https://apis-gw.beacukai.go.id/v2/report-parser/v1/lnsw/get-data-doc/td-header?idHeader=" . urlencode($idHeader);
    $ch = curl_init();
    $tdHeaders = [
        'Authorization: Bearer ' . $activeToken,
        'beacukai-api-key: 6222a75e-1dbb-493e-9461-27f721097e9c',
        'Accept: application/json, text/plain, */*',
        'Origin: https://portal.beacukai.go.id',
        'Referer: https://portal.beacukai.go.id/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'
    ];
    curl_setopt($ch, CURLOPT_ENCODING, "");
    if (function_exists('ceisa_prepare_curl')) {
        ceisa_prepare_curl($ch, $url, $tdHeaders, 45);
    } else {
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $tdHeaders
        ]);
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    header('Content-Type: application/json; charset=utf-8');

    if ($isRaw) {
        $decoded = json_decode($resp, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo $resp ?: json_encode(['status' => 'ERROR', 'http_code' => $httpCode, 'error' => $curlErr], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    $decoded = json_decode($resp, true);
    echo json_encode([
        'status'    => ($httpCode === 200) ? 'OK' : 'ERROR',
        'http_code' => $httpCode,
        'nomor_aju' => $nomor_aju,
        'id_header' => $idHeader,
        'cmp_id'    => $cmp_id,
        'raw_json'  => (json_last_error() === JSON_ERROR_NONE) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $resp,
        'data'      => $decoded
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 1. CETAK DOKUMEN: https://apis-gw.beacukai.go.id/v2/report-service/formulir/$kodeDokumen/$idHeader
if ($action === 'cetak_formulir') {
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? ($_REQUEST['aju'] ?? ''));
    $targetCmp = trim($_REQUEST['cmp_id'] ?? '');

    if (empty($nomor_aju)) {
        http_response_code(400);
        die("Nomor pengajuan tidak boleh kosong.");
    }

    $cleanAju = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomor_aju);
    $escAju = $koneksi->real_escape_string($cleanAju);

    // Ambil idHeader, kodeDokumen, cmp_id, dan pdf dari bc_ceisa_data
    $sqlDoc = "SELECT idHeader, kodeDokumen, cmp_id, pdf FROM bc_ceisa_data WHERE nomorAju = '{$escAju}' LIMIT 1";
    $resDoc = $koneksi->query($sqlDoc);
    $rowDoc = $resDoc ? $resDoc->fetch_assoc() : null;

    $idHeader = trim($rowDoc['idHeader'] ?? '');
    $kodeDokumen = trim($rowDoc['kodeDokumen'] ?? '');
    if (empty($targetCmp) && !empty($rowDoc['cmp_id'])) {
        $targetCmp = $rowDoc['cmp_id'];
    } elseif (empty($targetCmp) || empty($kodeDokumen)) {
        $qHdr = $koneksi->query("SELECT cmp_id, kode_dokumen FROM bc_ceisa_header WHERE nomor_aju = '{$escAju}' LIMIT 1");
        if ($qHdr && $rHdr = $qHdr->fetch_assoc()) {
            if (empty($targetCmp)) $targetCmp = trim($rHdr['cmp_id'] ?? '');
            if (empty($kodeDokumen)) $kodeDokumen = trim($rHdr['kode_dokumen'] ?? '');
        }
    }

    $comp = ceisa_get_company(!empty($targetCmp) ? $targetCmp : $cleanAju);
    if (empty($comp['id_user_ceisa'])) {
        http_response_code(400);
        die('<div style="font-family:sans-serif; padding:30px; text-align:center; color:#cf1322;">
            <h3>⚠️ ID User CEISA Belum Diatur</h3>
            <p>Fitur <b>Cetak Dokumen</b> memerlukan <b>ID User CEISA</b> aktif.<br>Silakan lengkapi konfigurasi perusahaan di menu <b>Kelola Perusahaan &rarr; Akun CEISA &amp; TOTP MFA</b>.</p>
        </div>');
    }

    // Jika idHeader belum ada di bc_ceisa_data, coba cari dari browse dokumen CEISA
    if (empty($idHeader) && !empty($kodeDokumen)) {
        $cmp_id = $targetCmp;
        require_once __DIR__ . '/init.php';
        $nitku = str_pad($npwp ?? '', 22, '0', STR_PAD_RIGHT);
        $hostprd = "https://apis-gw.beacukai.go.id/v2/browse-service/v1/browse/dokumen-pabean-portal?" . http_build_query([
            'nomorIdentitas' => $nitku,
            'kodeDokumen'    => $kodeDokumen,
            'nomorAju'       => $cleanAju,
            'page'           => '1',
            'size'           => 10,
            'idUser'         => $idUser ?? ($comp['id_user_ceisa'] ?? '')
        ]);
        $bearer = $_SESSION["token"] ?? '';
        if (!empty($bearer)) {
            $chB = curl_init();
            $bHeaders = ["Authorization: Bearer $bearer", "Content-Type: application/json"];
            if (function_exists('ceisa_prepare_curl')) {
                ceisa_prepare_curl($chB, $hostprd, $bHeaders, 20);
            } else {
                curl_setopt_array($chB, [
                    CURLOPT_URL => $hostprd,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => $bHeaders
                ]);
            }
            $bResp = curl_exec($chB);
            curl_close($chB);
            if ($bResp) {
                $bData = json_decode($bResp, true);
                if (!empty($bData['data'][0]['idHeader'])) {
                    $idHeader = trim($bData['data'][0]['idHeader']);
                    $escIdH = $koneksi->real_escape_string($idHeader);
                    $escKdD = $koneksi->real_escape_string($kodeDokumen);
                    $escCmp = $koneksi->real_escape_string($targetCmp);
                    $nmProses = $koneksi->real_escape_string($bData['data'][0]['namaProses'] ?? '');
                    $nmRespon = $koneksi->real_escape_string($bData['data'][0]['namaRespon'] ?? '');
                    $koneksi->query("INSERT INTO bc_ceisa_data (cmp_id, idHeader, nomorAju, kodeDokumen, namaProses, namaRespon) VALUES ('$escCmp', '$escIdH', '$escAju', '$escKdD', '$nmProses', '$nmRespon') ON DUPLICATE KEY UPDATE idHeader = '$escIdH', kodeDokumen = '$escKdD', namaProses = IF('$nmProses' != '', '$nmProses', namaProses)");
                }
            }
        }
    }

    $pdfContent = null;

    // Panggil report-service formulir resmi CEISA 4.0 jika idHeader dan kodeDokumen ada
    if (!empty($idHeader) && !empty($kodeDokumen)) {
        $cmp_id = $targetCmp;
        $_GET['cmp_id'] = $targetCmp;
        require_once __DIR__ . '/initmfa.php';

        $apiKey = !empty($comp['api_key_ceisa']) ? trim($comp['api_key_ceisa']) : '6222a75e-1dbb-493e-9461-27f721097e9c';

        if (!empty($activeToken)) {
            $url = "https://apis-gw.beacukai.go.id/v2/report-service/formulir/" . urlencode($kodeDokumen) . "/" . urlencode($idHeader);
            $ch = curl_init();
            $formHeaders = [
                'Authorization: Bearer ' . $activeToken,
                'beacukai-api-key: ' . $apiKey,
                'Accept: application/pdf, application/json, */*',
                'Origin: https://portal.beacukai.go.id',
                'Referer: https://portal.beacukai.go.id/'
            ];
            if (function_exists('ceisa_prepare_curl')) {
                ceisa_prepare_curl($ch, $url, $formHeaders, 45);
            } else {
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 45,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => $formHeaders
                ]);
            }
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && substr($resp, 0, 4) === '%PDF') {
                $pdfContent = $resp;
            }
        }
    }

    // Jika berhasil mendapatkan PDF formulir resmi dari report-service
    if ($pdfContent !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Formulir_BC' . $kodeDokumen . '_' . $cleanAju . '.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: public, max-age=86400');
        echo $pdfContent;
        exit;
    }

    // Fallback: Jika formulir live belum dapat diakses, buka PDF respon resmi jika ada di database
    $pdfFile = $rowDoc['pdf'] ?? '';
    if (!empty($pdfFile)) {
        header("Location: /pdf.php?aju=" . urlencode($cleanAju) . "&pdf=" . urlencode($pdfFile) . "&cmp_id=" . urlencode($targetCmp) . "&view=1");
        exit;
    }

    http_response_code(404);
    echo '<div style="font-family:sans-serif; text-align:center; padding:40px; color:#cf1322;">
        <h3>⚠️ Berkas Formulir Belum Tersedia</h3>
        <p>Formulir resmi atau PDF respon belum dapat diakses dari CEISA 4.0 untuk pengajuan <b>' . htmlspecialchars($cleanAju) . '</b>.</p>
    </div>';
    exit;
}

// 2. UNDUH EXCEL: Panggil service resmi download_ceisa_excel.php (Endpoint Resmi excel-service)
if ($action === 'unduh_excel') {
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? ($_REQUEST['aju'] ?? ''));
    $targetCmp = trim($_REQUEST['cmp_id'] ?? '');

    if (empty($nomor_aju)) {
        http_response_code(400);
        die("Nomor pengajuan tidak boleh kosong.");
    }

    $cleanAju = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomor_aju);
    $escAju = $koneksi->real_escape_string($cleanAju);

    // Ambil idHeader, kodeDokumen, dan cmp_id dari bc_ceisa_data
    $sqlDoc = "SELECT idHeader, kodeDokumen, cmp_id FROM bc_ceisa_data WHERE nomorAju = '{$escAju}' LIMIT 1";
    $resDoc = $koneksi->query($sqlDoc);
    $rowDoc = $resDoc ? $resDoc->fetch_assoc() : null;

    $kodeDokumen = trim($_REQUEST['kode_dokumen'] ?? ($rowDoc['kodeDokumen'] ?? ''));
    if (empty($targetCmp) && !empty($rowDoc['cmp_id'])) {
        $targetCmp = $rowDoc['cmp_id'];
    } elseif (empty($targetCmp)) {
        $comp = ceisa_get_company($cleanAju);
        if ($comp && !empty($comp['cmp_id'])) {
            $targetCmp = $comp['cmp_id'];
        } elseif (!empty($_SESSION['company_cmp_id'])) {
            $targetCmp = $_SESSION['company_cmp_id'];
        }
    }

    // Validasi ID User CEISA sebelum menjalankan InitMFA
    $comp = ceisa_get_company(!empty($targetCmp) ? $targetCmp : $cleanAju);
    if (empty($comp['id_user_ceisa'])) {
        http_response_code(400);
        die('<div style="font-family:sans-serif; padding:30px; text-align:center; color:#cf1322;">
            <h3>⚠️ ID User CEISA Belum Diatur</h3>
            <p>Fitur <b>Unduh Excel</b> memerlukan <b>ID User CEISA</b> aktif.<br>Silakan lengkapi konfigurasi perusahaan di menu <b>Kelola Perusahaan &rarr; Akun CEISA &amp; TOTP MFA</b>.</p>
        </div>');
    }

    // Arahkan langsung ke service download_ceisa_excel.php yang terintegrasi langsung ke excel-service
    $redirUrl = "download_ceisa_excel.php?nomor_aju=" . urlencode($cleanAju) . "&cmp_id=" . urlencode($targetCmp);
    if (!empty($kodeDokumen)) {
        $redirUrl .= "&kode_dokumen=" . urlencode($kodeDokumen);
    }
    header("Location: " . $redirUrl);
    exit;
}

// 3. IMPORT KE H2H: https://apis-gw.beacukai.go.id/v2/report-parser/v1/lnsw/get-data-doc?idHeader=$idHeader
if ($action === 'import_to_h2h') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_REQUEST['nomor_aju'] ?? ($_REQUEST['aju'] ?? ''));
    $targetCmp = trim($_REQUEST['cmp_id'] ?? '');

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Pengajuan (AJU) tidak boleh kosong.']);
        exit;
    }

    $cleanAju = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomor_aju);
    $escAju = $koneksi->real_escape_string($cleanAju);

    // Ambil idHeader, kodeDokumen, dan cmp_id dari bc_ceisa_data
    $sqlDoc = "SELECT idHeader, kodeDokumen, cmp_id FROM bc_ceisa_data WHERE nomorAju = '{$escAju}' LIMIT 1";
    $resDoc = $koneksi->query($sqlDoc);
    $rowDoc = $resDoc ? $resDoc->fetch_assoc() : null;

    $idHeader = trim($rowDoc['idHeader'] ?? '');
    $kodeDokumen = trim($rowDoc['kodeDokumen'] ?? '');
    if (empty($targetCmp) && !empty($rowDoc['cmp_id'])) {
        $targetCmp = $rowDoc['cmp_id'];
    } elseif (empty($targetCmp)) {
        $comp = ceisa_get_company($cleanAju);
        if ($comp && !empty($comp['cmp_id'])) {
            $targetCmp = $comp['cmp_id'];
        } elseif (!empty($_SESSION['company_cmp_id'])) {
            $targetCmp = $_SESSION['company_cmp_id'];
        }
    }

    $cacheDir = __DIR__ . '/excel_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $excelPath = $cacheDir . '/' . $cleanAju . '.xlsx';

    // Validasi ID User CEISA sebelum menjalankan InitMFA / report-parser
    $comp = ceisa_get_company(!empty($targetCmp) ? $targetCmp : $cleanAju);
    if (empty($comp['id_user_ceisa'])) {
        echo json_encode([
            'status'  => 'ERROR',
            'code'    => 'MISSING_ID_USER',
            'message' => 'ID User CEISA belum diset pada profil perusahaan ini di menu Kelola Perusahaan (Tab Akun CEISA & TOTP MFA).'
        ]);
        exit;
    }

    $importMode = strtolower(trim($_REQUEST['mode'] ?? 'json')); // 'json' (cepat, disarankan) atau 'excel' (lengkap)

    // JALUR 1: Jika mode JSON dipilih (Prioritas Cepat & Ringan)
    if ($importMode === 'json' && !empty($idHeader)) {
        try {
            require_once __DIR__ . '/CeisaJsonDocParser.php';
            $docArr = CeisaJsonDocParser::fetchLnswDoc($idHeader, $targetCmp);
            if (!empty($docArr['data'])) {
                $importResult = CeisaJsonDocParser::importToStaging($koneksi, $docArr, $targetCmp, true);
                echo json_encode([
                    'status'    => 'OK',
                    'nomor_aju' => $cleanAju,
                    'cmp_id'    => $targetCmp,
                    'source'    => 'LNSW_JSON',
                    'message'   => "Dokumen {$cleanAju} berhasil diimpor dari API LNSW ke Staging Host to Host!",
                    'details'   => $importResult
                ]);
                exit;
            }
        } catch (\Throwable $eLnsw) {
            error_log("import_to_h2h via JSON failed, fallback to Excel: " . $eLnsw->getMessage());
        }
    }

    // JALUR 2: Impor Berkas Excel Resmi CEISA 4.0 (excel-service)
    // (Jalan jika mode === 'excel' ATAU jika mode JSON gagal/tidak ada idHeader)
    if (!file_exists($excelPath) || filesize($excelPath) < 2000) {
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $dlUrl = $proto . $host . "/download_ceisa_excel.php?nomor_aju=" . urlencode($cleanAju) . "&cmp_id=" . urlencode($targetCmp) . "&format=json";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $dlUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $resCurl = curl_exec($ch);
        curl_close($ch);
    }

    if (file_exists($excelPath) && filesize($excelPath) > 2000) {
        try {
            require_once __DIR__ . '/CeisaXlsxImporter.php';
            $importer = new CeisaXlsxImporter($koneksi, $targetCmp);
            $result = $importer->import($excelPath, ['overwrite' => true]);

            echo json_encode([
                'status'    => 'OK',
                'nomor_aju' => $cleanAju,
                'cmp_id'    => $targetCmp,
                'source'    => 'OFFICIAL_EXCEL',
                'message'   => "Dokumen {$cleanAju} berhasil diimpor dari Berkas Excel Resmi CEISA 4.0 ke Staging Host to Host!",
                'details'   => $result
            ]);
            exit;
        } catch (\Throwable $eEx) {
            error_log("import_to_h2h via official Excel failed: " . $eEx->getMessage());
        }
    }

    // JALUR 3: Fallback ke LNSW JSON jika mode Excel gagal
    if ($importMode !== 'json' && !empty($idHeader)) {
        try {
            require_once __DIR__ . '/CeisaJsonDocParser.php';
            $docArr = CeisaJsonDocParser::fetchLnswDoc($idHeader, $targetCmp);
            if (!empty($docArr['data'])) {
                $importResult = CeisaJsonDocParser::importToStaging($koneksi, $docArr, $targetCmp, true);
                echo json_encode([
                    'status'    => 'OK',
                    'nomor_aju' => $cleanAju,
                    'cmp_id'    => $targetCmp,
                    'source'    => 'LNSW_JSON',
                    'message'   => "Dokumen {$cleanAju} berhasil diimpor dari API LNSW ke Staging Host to Host!",
                    'details'   => $importResult
                ]);
                exit;
            }
        } catch (\Throwable $eLnsw) {
            error_log("import_to_h2h fallback to LNSW failed: " . $eLnsw->getMessage());
        }
    }

    echo json_encode([
        'status' => 'ERROR',
        'message' => "Dokumen pengajuan {$cleanAju} tidak dapat diakses dari API LNSW maupun Excel. Pastikan kredensial login dan OTP MFA perusahaan telah aktif."
    ]);
    exit;
}

// 4. HAPUS DOKUMEN DARI TABEL REKAP (bc_ceisa_data)
if ($action === 'hapus_dokumen') {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Metode request tidak valid. Harus POST.']);
        exit;
    }

    $nomor_aju = trim($_POST['nomor_aju'] ?? ($_REQUEST['nomor_aju'] ?? ''));

    if (empty($nomor_aju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Pengajuan (nomorAju) tidak boleh kosong.']);
        exit;
    }

    $cleanAju = preg_replace('/[^a-zA-Z0-9_-]/', '', $nomor_aju);
    $escAju   = $koneksi->real_escape_string($cleanAju);

    // Hapus dari tabel rekap lokal bc_ceisa_data
    $deleted = false;
    $koneksi->query("DELETE FROM bc_ceisa_data WHERE nomorAju = '{$escAju}'");
    if ($koneksi->affected_rows > 0) {
        $deleted = true;
    }

    // Bersihkan cache status/respon/json jika ada
    $koneksi->query("DELETE FROM bc_ceisa_json WHERE nomor_aju = '{$escAju}'");
    $koneksi->query("DELETE FROM bc_ceisa_status WHERE nomor_aju = '{$escAju}'");
    $koneksi->query("DELETE FROM bc_ceisa_respons WHERE nomor_aju = '{$escAju}'");

    echo json_encode([
        'status'    => 'OK',
        'nomor_aju' => $cleanAju,
        'message'   => "Data dokumen pengajuan {$cleanAju} telah berhasil dihapus dari tabel rekap lokal."
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
        .kpi-chip.active#kpiChipNotH2H {
            border-color: #fa8c16;
            background: #fff7e6;
            box-shadow: 0 0 0 1px #fa8c16;
        }
        body[data-theme="dark"] .kpi-chip.active#kpiChipNotH2H {
            background: #2b1d11;
            border-color: #d46b08;
            box-shadow: 0 0 0 1px #d46b08;
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
           REKAP BELUM MASUK IT INVENTORY PER BULAN (FLOATING WIDGET)
           ------------------------------------------------------------- */
        .btn-rekap-pill {
            background: linear-gradient(135deg, #ff4d4f 0%, #f5222d 100%);
            color: #ffffff;
            border: none;
            border-radius: 4px;
            padding: 1px 7px;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            margin-left: 6px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            box-shadow: 0 1px 3px rgba(245, 34, 45, 0.35);
            transition: all 0.15s ease-in-out;
            vertical-align: middle;
            line-height: 1.5;
        }
        .btn-rekap-pill:hover {
            background: linear-gradient(135deg, #ff7875 0%, #ff4d4f 100%);
            box-shadow: 0 2px 6px rgba(245, 34, 45, 0.5);
            transform: translateY(-1px);
        }
        .btn-rekap-pill:active {
            transform: translateY(0);
        }
        .rekap-notinv-widget {
            position: fixed;
            z-index: 1060;
            width: 375px;
            background: var(--bg-card, #ffffff);
            border: 1px solid #ffa39e;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(207, 19, 34, 0.16), 0 2px 8px rgba(0, 0, 0, 0.08);
            display: none;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            user-select: none;
        }
        body[data-theme="dark"] .rekap-notinv-widget {
            background: #1f1f1f;
            border-color: #5c2223;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
        }
        .rekap-notinv-header {
            background: linear-gradient(135deg, #fff1f0 0%, #ffe8e6 100%);
            padding: 8px 12px;
            border-bottom: 1px solid #ffa39e;
            border-top-left-radius: 7px;
            border-top-right-radius: 7px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
        }
        body[data-theme="dark"] .rekap-notinv-header {
            background: #2a1215;
            border-color: #5c2223;
        }
        .rekap-notinv-title {
            font-size: 13px;
            font-weight: 700;
            color: #cf1322;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        body[data-theme="dark"] .rekap-notinv-title {
            color: #ff7875;
        }
        .rekap-notinv-close {
            background: transparent;
            border: none;
            color: #8c8c8c;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
        }
        .rekap-notinv-close:hover {
            color: #cf1322;
        }
        .rekap-notinv-toolbar {
            padding: 6px 12px;
            background: var(--bg-base, #fafafa);
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        body[data-theme="dark"] .rekap-notinv-toolbar {
            background: #141414;
            border-color: #303030;
        }
        .rekap-notinv-select {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 6px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: var(--bg-input, #ffffff);
            color: var(--text-main, #262626);
            outline: none;
            cursor: pointer;
        }
        body[data-theme="dark"] .rekap-notinv-select {
            border-color: #434343;
            background: #1f1f1f;
            color: #d9d9d9;
        }
        .btn-rekap-all {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #ffffff;
            color: #595959;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-rekap-all:hover {
            border-color: #ff4d4f;
            color: #cf1322;
        }
        body[data-theme="dark"] .btn-rekap-all {
            background: #1f1f1f;
            border-color: #434343;
            color: #bfbfbf;
        }
        .badge-rekap-total {
            background: #fff1f0;
            color: #cf1322;
            border: 1px solid #ffa39e;
            font-weight: 700;
            font-size: 12px;
            padding: 1px 7px;
            border-radius: 4px;
        }
        body[data-theme="dark"] .badge-rekap-total {
            background: #2a1215;
            color: #ff7875;
            border-color: #5c2223;
        }
        .rekap-notinv-body {
            max-height: 330px;
            overflow-y: auto;
        }
        .rekap-notinv-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }
        .rekap-notinv-table th {
            background: var(--bg-base, #fafafa);
            padding: 5px 8px;
            color: #595959;
            font-weight: 600;
            border-bottom: 1px solid #f0f0f0;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        body[data-theme="dark"] .rekap-notinv-table th {
            background: #141414;
            border-color: #303030;
            color: #8c8c8c;
        }
        .rekap-notinv-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #f5f5f5;
            color: var(--text-main, #262626);
        }
        body[data-theme="dark"] .rekap-notinv-table td {
            border-color: #262626;
            color: #d9d9d9;
        }
        .rekap-notinv-row {
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .rekap-notinv-row:hover {
            background: #fff1f0;
        }
        body[data-theme="dark"] .rekap-notinv-row:hover {
            background: #2a1215;
        }
        .rekap-notinv-row.active-month {
            background: #ffe8e6 !important;
            font-weight: 700;
        }
        body[data-theme="dark"] .rekap-notinv-row.active-month {
            background: #44161a !important;
        }
        .badge-count-notinv {
            display: inline-block;
            min-width: 32px;
            padding: 1px 6px;
            font-size: 11px;
            font-weight: 700;
            color: #cf1322;
            background: #fff1f0;
            border: 1px solid #ffa39e;
            border-radius: 3px;
            text-align: center;
        }
        body[data-theme="dark"] .badge-count-notinv {
            background: #2a1215;
            color: #ff7875;
            border-color: #5c2223;
        }
        .rekap-notinv-footer {
            background: var(--bg-base, #fafafa);
            border-top: 1px solid #f0f0f0;
            padding: 5px 10px;
            font-size: 10.5px;
            color: #8c8c8c;
            text-align: center;
            border-bottom-left-radius: 7px;
            border-bottom-right-radius: 7px;
        }
        body[data-theme="dark"] .rekap-notinv-footer {
            background: #141414;
            border-color: #303030;
            color: #595959;
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
            border-radius: 4px;
            background: #fff;
            overflow-x: auto;
            overflow-y: hidden;
            position: relative;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        body[data-theme="dark"] .ant-table-wrapper {
            border-color: #2b2b40;
            background: #1e1e2d;
        }
        /* Custom horizontal scrollbar matching user design */
        .ant-table-wrapper::-webkit-scrollbar {
            height: 10px;
        }
        .ant-table-wrapper::-webkit-scrollbar-track {
            background: #f0f2f5;
            border-radius: 5px;
        }
        .ant-table-wrapper::-webkit-scrollbar-thumb {
            background: #b0b8c4;
            border-radius: 5px;
            border: 2px solid #f0f2f5;
        }
        .ant-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #1890ff;
        }
        body[data-theme="dark"] .ant-table-wrapper::-webkit-scrollbar-track {
            background: #151521;
        }
        body[data-theme="dark"] .ant-table-wrapper::-webkit-scrollbar-thumb {
            background: #3f4254;
            border-color: #151521;
        }
        body[data-theme="dark"] .ant-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #52c41a;
        }
        .ant-table {
            width: 100%;
            min-width: 1480px;
            border-collapse: collapse;
            font-size: 10px;
            text-align: left;
        }
        .ant-table table {
            width: 100%;
            min-width: 1480px;
            border-collapse: collapse;
        }
        /* Sticky Column Aksi */
        .th-col-aksi, .td-col-aksi {
            position: sticky;
            right: 0;
            background: #ffffff;
            z-index: 5;
            box-shadow: -3px 0 6px -2px rgba(0, 0, 0, 0.08);
        }
        .ant-table-thead > tr > th.th-col-aksi {
            background: var(--table-head-bg, #fafafa);
            z-index: 6;
        }
        body[data-theme="dark"] .th-col-aksi,
        body[data-theme="dark"] .td-col-aksi {
            background: #1e1e2d;
            box-shadow: -3px 0 6px -2px rgba(0, 0, 0, 0.35);
        }
        body[data-theme="dark"] .ant-table-thead > tr > th.th-col-aksi {
            background: #151521;
        }
        .ant-table-tbody > tr:hover > td.td-col-aksi {
            background: #f0f7ff !important;
        }
        body[data-theme="dark"] .ant-table-tbody > tr:hover > td.td-col-aksi {
            background: #262638 !important;
        }
        .ant-table-tbody > tr.selected-row > td.td-col-aksi {
            background: #e6f7ff !important;
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
        .ant-table-thead > tr > th.th-sortable {
            cursor: pointer;
            user-select: none;
            transition: background 0.15s, color 0.15s;
        }
        .ant-table-thead > tr > th.th-sortable:hover {
            background: rgba(24, 144, 255, 0.08);
            color: var(--primary, #1890ff);
        }
        .ant-table-thead > tr > th.th-sorted {
            background: rgba(24, 144, 255, 0.09) !important;
            color: var(--primary, #1890ff) !important;
        }
        .th-sort-icon {
            display: inline-block;
            margin-left: 4px;
            font-size: 9px;
            vertical-align: middle;
            opacity: 0.35;
            transition: opacity 0.15s;
        }
        .ant-table-thead > tr > th.th-sorted .th-sort-icon {
            opacity: 1 !important;
            color: var(--primary, #1890ff);
        }
        body[data-theme="dark"] .ant-table-thead > tr > th {
            color: #ffffff;
        }
        body[data-theme="dark"] .ant-table-thead > tr > th.th-sorted {
            background: rgba(24, 144, 255, 0.2) !important;
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

        /* Badge Unduh Excel CEISA 4.0 */
        .excel-btn-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
            padding: 2px 7px;
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            border-radius: 4px;
            text-decoration: none !important;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(82, 196, 26, 0.08);
            line-height: 1.2;
        }
        .excel-btn-badge:hover {
            background: #52c41a;
            border-color: #52c41a;
        }
        .excel-btn-badge:hover span {
            color: #ffffff !important;
        }
        .excel-btn-badge:hover svg {
            fill: #ffffff !important;
        }
        body[data-theme="dark"] .excel-btn-badge {
            background: rgba(82, 196, 26, 0.15);
            border-color: rgba(82, 196, 26, 0.35);
        }
        body[data-theme="dark"] .excel-btn-badge span {
            color: #95de64 !important;
        }
        body[data-theme="dark"] .excel-btn-badge:hover {
            background: #52c41a;
        }
        body[data-theme="dark"] .excel-btn-badge:hover span {
            color: #ffffff !important;
        }

        /* Mode Select & Checkbox Kolom */
        .col-select-mode {
            display: none !important;
            width: 38px;
            text-align: center;
        }
        body.mode-select-active .col-select-mode {
            display: table-cell !important;
        }
        body.mode-select-active tr.selected-row > td {
            background: #e6f7ff !important;
        }
        body[data-theme="dark"].mode-select-active tr.selected-row > td {
            background: rgba(24, 144, 255, 0.18) !important;
        }

        /* Floating Batch Action Bar */
        .batch-action-bar {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1050;
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 8px;
            padding: 8px 18px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.22);
            display: flex;
            align-items: center;
            gap: 16px;
            animation: batchSlideUp 0.25s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        }
        @keyframes batchSlideUp {
            from { transform: translate(-50%, 40px); opacity: 0; }
            to { transform: translate(-50%, 0); opacity: 1; }
        }
        .batch-bar-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-heading);
            white-space: nowrap;
        }
        .batch-bar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Context Menu */
        .dd-context-menu {
            position: fixed;
            z-index: 9999;
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            padding: 4px 0;
            min-width: 190px;
            display: none;
        }
        .dd-context-item {
            padding: 7px 14px;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: var(--text-main);
            transition: background 0.15s;
        }
        .dd-context-item:hover {
            background: rgba(24, 144, 255, 0.12);
            color: var(--primary);
        }
        .dd-context-divider {
            height: 1px;
            background: var(--border-card);
            margin: 4px 0;
        }

        /* -------------------------------------------------------------
           TOMBOL AKSI PORTAL CEISA & DROPDOWN MENU AKSI BARIS TABEL
           ------------------------------------------------------------- */
        .action-btn-ceisa {
            background: #ffffff;
            border: 1px solid #1890ff;
            color: #1890ff;
            padding: 2px 14px;
            font-size: 11.5px;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            outline: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            line-height: 1.5;
            transition: all 0.2s cubic-bezier(0.645, 0.045, 0.355, 1);
            user-select: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .action-btn-ceisa:hover, .action-btn-ceisa.active {
            background: #e6f7ff;
            border-color: #40a9ff;
            color: #096dd9;
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.2);
        }
        body[data-theme="dark"] .action-btn-ceisa {
            background: transparent;
            border-color: #177ddc;
            color: #177ddc;
        }
        body[data-theme="dark"] .action-btn-ceisa:hover, 
        body[data-theme="dark"] .action-btn-ceisa.active {
            background: #111d2c;
            border-color: #40a9ff;
            color: #40a9ff;
        }

        .row-action-menu {
            position: fixed;
            z-index: 10000;
            background: #ffffff;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            padding: 4px 0;
            min-width: 155px;
            display: none;
            animation: fadeInRowMenu 0.15s ease-out;
        }
        @keyframes fadeInRowMenu {
            from { opacity: 0; transform: translateY(-3px); }
            to { opacity: 1; transform: translateY(0); }
        }
        body[data-theme="dark"] .row-action-menu {
            background: #1f1f1f;
            border-color: #303030;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.45);
        }
        .row-action-item {
            padding: 7px 14px;
            font-size: 11.5px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #262626;
            transition: background 0.15s, color 0.15s;
            user-select: none;
        }
        body[data-theme="dark"] .row-action-item {
            color: #d9d9d9;
        }
        .row-action-item:hover {
            background: #e6f7ff;
            color: #1890ff;
        }
        body[data-theme="dark"] .row-action-item:hover {
            background: #111d2c;
            color: #177ddc;
        }
        .row-action-item-danger {
            color: #ff4d4f !important;
        }
        .row-action-item-danger:hover {
            background: #fff1f0 !important;
            color: #cf1322 !important;
        }
        body[data-theme="dark"] .row-action-item-danger {
            color: #ff7875 !important;
        }
        body[data-theme="dark"] .row-action-item-danger:hover {
            background: rgba(255, 77, 79, 0.15) !important;
            color: #ff4d4f !important;
        }
        .row-action-divider {
            height: 1px;
            background: #f0f0f0;
            margin: 3px 0;
        }
        body[data-theme="dark"] .row-action-divider {
            background: #303030;
        }
        .dd-context-item.is-disabled, .row-action-item.is-disabled {
            opacity: 0.45 !important;
            cursor: not-allowed !important;
            filter: grayscale(85%);
        }
        .dd-context-item.is-disabled:hover, .row-action-item.is-disabled:hover {
            background: transparent !important;
            color: var(--text-muted) !important;
        }
        .row-action-item svg {
            flex-shrink: 0;
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

                <!-- Mode Select (Pilih Massal Dokumen & Unduh Excel) -->
                <button type="button" class="ant-btn" id="btnModeSelect" onclick="toggleModeSelect()" title="Aktifkan Mode Centang / Pilih Massal Dokumen untuk Unduh Excel">
                    <span id="iconModeSelect">☑️</span>
                    <span id="lblModeSelect">Mode Select</span>
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
                <div class="kpi-chip" id="kpiChipNotH2H" onclick="filterByCard('not_h2h')" title="Klik untuk memfilter hanya dokumen yang belum diimpor ke Data Host to Host (bc_ceisa_header)" style="display:none; align-items:center;">
                    <span class="kpi-label" style="color:#d46b08; font-weight:600;">Belum Import H2H</span>
                    <strong class="kpi-val" style="color:#d46b08;" id="kpiNotH2H">0</strong>
                    <button type="button" class="btn-rekap-pill" id="btnQuickSelectNotH2H" onclick="event.stopPropagation(); toggleSelectAllNotH2H();" title="Pilih / Batal Pilih Semua Dokumen yang Belum Masuk H2H" style="background:#fff7e6; color:#d46b08; border-color:#ffd591; margin-left:6px; font-weight:600;">
                        ⚡ Select All
                    </button>
                    <button type="button" class="btn-rekap-pill" id="btnRekapNotH2H" onclick="toggleRekapNotH2H(event)" title="Lihat Rekap Dokumen Belum Import H2H per Bulan" style="background:#fff7e6; color:#d46b08; border-color:#ffd591; margin-left:4px; font-weight:600;">
                        📊 Rekap
                    </button>
                    <span id="badgeActiveMonthH2H" style="display:none; font-size:9.5px; font-weight:700; background:#fff7e6; color:#d46b08; border:1px solid #ffd591; padding:1px 5px; border-radius:3px; margin-left:4px; cursor:pointer;" onclick="filterByRekapMonthH2H(event, '')" title="Klik untuk reset filter periode bulan"></span>
                </div>
                <div class="kpi-chip" id="kpiChipNotInv" onclick="filterByCard('not_inv')" title="Klik untuk memfilter hanya dokumen yang belum masuk IT Inventory (bc_1ab2)" style="display:none; align-items:center;">
                    <span class="kpi-label kpi-label-danger">Belum Masuk IT Inv</span>
                    <strong class="kpi-val kpi-val-danger" id="kpiNotInv">0</strong>
                    <button type="button" class="btn-rekap-pill" id="btnRekapNotInv" onclick="toggleRekapNotInv(event)" title="Lihat Rekap Dokumen Belum Masuk IT Inventory per Bulan">
                        📊 Rekap
                    </button>
                    <span id="badgeActiveMonth" style="display:none; font-size:9.5px; font-weight:700; background:#fff1f0; color:#cf1322; border:1px solid #ffa39e; padding:1px 5px; border-radius:3px; margin-left:4px; cursor:pointer;" onclick="filterByRekapMonth(event, '')" title="Klik untuk reset filter periode bulan"></span>
                </div>
            </div>

            <!-- FLOATING WIDGET: REKAP BELUM IMPORT H2H PER BULAN -->
            <div class="rekap-notinv-widget" id="rekapNotH2HWidget" style="display:none; border-color:rgba(212, 107, 8, 0.4); box-shadow:0 8px 24px rgba(212, 107, 8, 0.15);">
                <div class="rekap-notinv-header" id="rekapNotH2HHeader" style="background:linear-gradient(135deg, #fff7e6 0%, #ffe7ba 100%);">
                    <span class="rekap-notinv-title" style="color:#d46b08;">
                        <span>📅</span>
                        <span>Rekap Belum Import H2H per Bulan</span>
                    </span>
                    <button type="button" class="rekap-notinv-close" onclick="closeRekapNotH2H(event)" title="Tutup Rekap">✕</button>
                </div>
                <div class="rekap-notinv-toolbar">
                    <select id="sortRekapNotH2H" class="rekap-notinv-select" onchange="renderRekapNotH2HTable()">
                        <option value="month_desc">Urut: Bulan Terbaru ↓</option>
                        <option value="month_asc">Urut: Bulan Terlama ↑</option>
                        <option value="count_desc">Urut: Belum H2H Terbanyak ↓</option>
                        <option value="count_asc">Urut: Belum H2H Tersedikit ↑</option>
                    </select>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <button type="button" class="btn-rekap-all" onclick="filterByRekapMonthH2H(event, '')" title="Tampilkan Semua Periode" style="color:#d46b08; border-color:#ffd591;">Semua</button>
                        <span class="badge-rekap-total" id="badgeRekapTotalH2H" style="background:#d46b08;">0</span>
                    </div>
                </div>
                <div class="rekap-notinv-body">
                    <table class="rekap-notinv-table">
                        <thead>
                            <tr>
                                <th style="width:32px; text-align:center;">#</th>
                                <th>Bulan / Periode</th>
                                <th style="text-align:center; width:100px;">Belum H2H</th>
                                <th style="text-align:right; width:75px; padding-right:12px;">% Ratio</th>
                            </tr>
                        </thead>
                        <tbody id="rekapNotH2HTbody">
                            <tr><td colspan="4" style="text-align:center; padding:16px; color:#8c8c8c;">Memuat rekap...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="rekap-notinv-footer" style="background:#fffbe6; color:#874d00;">
                    💡 Klik baris bulan untuk memfilter dokumen belum import H2H pada tabel utama
                </div>
            </div>

            <!-- FLOATING WIDGET: REKAP BELUM MASUK IT INVENTORY PER BULAN -->
            <div class="rekap-notinv-widget" id="rekapNotInvWidget" style="display:none;">
                <div class="rekap-notinv-header" id="rekapNotInvHeader">
                    <span class="rekap-notinv-title">
                        <span>📅</span>
                        <span>Rekap Belum IT Inv per Bulan</span>
                    </span>
                    <button type="button" class="rekap-notinv-close" onclick="closeRekapNotInv(event)" title="Tutup Rekap">✕</button>
                </div>
                <div class="rekap-notinv-toolbar">
                    <select id="sortRekapNotInv" class="rekap-notinv-select" onchange="renderRekapNotInvTable()">
                        <option value="month_desc">Urut: Bulan Terbaru ↓</option>
                        <option value="month_asc">Urut: Bulan Terlama ↑</option>
                        <option value="count_desc">Urut: Belum IT Inv Terbanyak ↓</option>
                        <option value="count_asc">Urut: Belum IT Inv Tersedikit ↑</option>
                    </select>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <button type="button" class="btn-rekap-all" onclick="filterByRekapMonth(event, '')" title="Tampilkan Semua Periode">Semua</button>
                        <span class="badge-rekap-total" id="badgeRekapTotal">0</span>
                    </div>
                </div>
                <div class="rekap-notinv-body">
                    <table class="rekap-notinv-table">
                        <thead>
                            <tr>
                                <th style="width:32px; text-align:center;">#</th>
                                <th>Bulan / Periode</th>
                                <th style="text-align:center; width:95px;">Belum IT Inv</th>
                                <th style="text-align:right; width:75px; padding-right:12px;">% Ratio</th>
                            </tr>
                        </thead>
                        <tbody id="rekapNotInvTbody">
                            <tr><td colspan="4" style="text-align:center; padding:16px; color:#8c8c8c;">Memuat rekap...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="rekap-notinv-footer">
                    💡 Klik baris bulan untuk memfilter dokumen pada tabel utama
                </div>
            </div>

            <!-- Filter Panel (Compact) -->
            <div class="filter-panel" id="filterPanel">
                <input type="hidden" id="filterCmp" value="<?= htmlspecialchars($sessionCmpId) ?>">
                <div class="filter-col">
                    <label>DOKUMEN BC</label>
                    <select id="filterBc" onchange="loadList()" class="filter-control">
                        <option value="">Semua Dokumen</option>
                        <optgroup label="── PEMASUKAN (INBOUND) ──">
                            <?php if (isDocPermitted('16')): ?><option value="16">BC 1.6 — Pemasukan ke PLB</option><?php endif; ?>
                            <?php if (isDocPermitted('20')): ?><option value="20">BC 2.0 — Impor Non-Fasilitas (PIB)</option><?php endif; ?>
                            <?php if (isDocPermitted('23')): ?><option value="23">BC 2.3 — Impor ke Kawasan Berikat (TPB)</option><?php endif; ?>
                            <?php if (isDocPermitted('262')): ?><option value="262">BC 2.6.2 — Pemasukan Subcon Kembali</option><?php endif; ?>
                            <?php if (isDocPermitted('27IN')): ?><option value="27IN">BC 2.7 IN — Pemasukan dari TPB Lain</option><?php endif; ?>
                            <?php if (isDocPermitted('28')): ?><option value="28">BC 2.8 — Impor dari PLB</option><?php endif; ?>
                            <?php if (isDocPermitted('40')): ?><option value="40">BC 4.0 — Pemasukan dari Lokal ke TPB</option><?php endif; ?>
                            <?php if (isDocPermitted('611')): ?><option value="611">PPKEK 6.1.1 — Pemasukan ke KEK dari Luar Negeri</option><?php endif; ?>
                            <?php if (isDocPermitted('613')): ?><option value="613">PPKEK 6.1.3 — Pemasukan ke KEK dari Lokal</option><?php endif; ?>
                        </optgroup>
                        <optgroup label="── PENGELUARAN (OUTBOUND) ──">
                            <?php if (isDocPermitted('25')): ?><option value="25">BC 2.5 — Pengeluaran ke Lokal (Asal Impor / BC 2.3)</option><?php endif; ?>
                            <?php if (isDocPermitted('261')): ?><option value="261">BC 2.6.1 — Pengeluaran Subcon</option><?php endif; ?>
                            <?php if (isDocPermitted('27')): ?><option value="27">BC 2.7 — Pengeluaran ke TPB Lain</option><?php endif; ?>
                            <?php if (isDocPermitted('30')): ?><option value="30">BC 3.0 — Ekspor Barang (PEB)</option><?php endif; ?>
                            <?php if (isDocPermitted('33')): ?><option value="33">BC 3.3 — Ekspor dari PLB</option><?php endif; ?>
                            <?php if (isDocPermitted('331')): ?><option value="331">BC 3.3.1 — P3BET PLB</option><?php endif; ?>
                            <?php if (isDocPermitted('41')): ?><option value="41">BC 4.1 — Pengeluaran ke Lokal (Asal Lokal / BC 4.0)</option><?php endif; ?>
                            <?php if (isDocPermitted('631')): ?><option value="631">PPKEK 6.3.1 — Pengeluaran dari KEK ke Luar Negeri</option><?php endif; ?>
                        </optgroup>
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
                        <option value="not_h2h">Belum Import ke H2H / Staging</option>
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
                                <th style="width: 38px; text-align: center;" class="col-select-mode">
                                    <input type="checkbox" id="masterSelectCb" onchange="toggleSelectAllPage(this)" title="Pilih Semua Dokumen di Halaman Ini" style="cursor:pointer;">
                                </th>
                                <th style="width: 55px;">Nomor</th>
                                <th style="width: 110px;" class="th-sortable" data-sort-col="bc" onclick="sortTable('bc')" title="Klik untuk mengurutkan Kode Dokumen">Kode Dokumen <span class="th-sort-icon">⇅</span></th>
                                <th style="width: 250px;" class="th-sortable" data-sort-col="nomor_aju" onclick="sortTable('nomor_aju')" title="Klik untuk mengurutkan Nomor Pengajuan">Nomor Pengajuan <span class="th-sort-icon">⇅</span></th>
                                <th style="width: 170px;" class="th-sortable" data-sort-col="nomor_daftar" onclick="sortTable('nomor_daftar')" title="Klik untuk mengurutkan Nomor / Tanggal Pendaftaran">Nomor / Tanggal Pendaftaran <span class="th-sort-icon">⇅</span></th>
                                <th style="width: 170px;" class="th-sortable" data-sort-col="nama_respon" onclick="sortTable('nama_respon')" title="Klik untuk mengurutkan Nama Respon">Nama Respon <span class="th-sort-icon">⇅</span></th>
                                <th style="width: 75px; text-align: center;">PDF</th>
                                <th style="width: 155px;" class="th-sortable th-sorted" data-sort-col="tanggal_respon" onclick="sortTable('tanggal_respon')" title="Klik untuk mengurutkan Tanggal Respon (Default: Terakhir / Terbaru)">Tanggal Respon <span class="th-sort-icon">▼</span></th>
                                <th style="width: 65px; text-align: center;" class="th-sortable" data-sort-col="jalur" onclick="sortTable('jalur')" title="Klik untuk mengurutkan Jalur">Jalur <span class="th-sort-icon">⇅</span></th>
                                <th style="width: 250px;" class="th-sortable" data-sort-col="perusahaan" onclick="sortTable('perusahaan')" title="Klik untuk mengurutkan Nama Perusahaan">Nama Perusahaan <span class="th-sort-icon">⇅</span></th>
                                <th style="width: 180px;" class="th-sortable" data-sort-col="kantor" onclick="sortTable('kantor')" title="Klik untuk mengurutkan Kantor Pabean">Kantor Pabean <span class="th-sort-icon">⇅</span></th>
                                <th style="width: 90px; text-align: center;" class="th-col-aksi">Aksi</th>
                            </tr>
                            <!-- Header Row 2: Inline Input Filters -->
                            <tr>
                                <th class="col-select-mode"></th>
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
                                <th class="th-col-aksi"></th>
                            </tr>
                        </thead>
                        <tbody class="ant-table-tbody" id="ajuTableBody">
                            <tr>
                                <td colspan="12" style="text-align:center; padding:28px; color:#8c8c8c;">
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

    <!-- 1. FLOATING BATCH ACTION BAR -->
    <div id="batchActionBar" class="batch-action-bar" style="display:none;">
        <div class="batch-bar-info">
            <span style="font-size:14px;">☑️</span>
            <span>Terpilih: <strong id="batchSelectedCount" style="color:var(--primary); font-size:13px;">0</strong> dokumen</span>
        </div>
        <div class="batch-bar-actions">
            <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="startBatchDownloadExcel()" id="btnBatchDownload" style="font-weight:600;">
                📥 Unduh Excel Terpilih (<span id="batchBtnCount">0</span>)
            </button>
            <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="startBatchImportH2H()" id="btnBatchImportH2H" style="font-weight:600; background:#1890ff; border-color:#1890ff;">
                ⚡ Import ke H2H Terpilih (<span id="batchBtnCountH2H">0</span>)
            </button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="selectAllOnCurrentPage()" title="Pilih semua dokumen di halaman ini">Pilih Halaman Ini</button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="selectAllFilteredData()" title="Pilih seluruh dokumen hasil filter">Pilih Semua Filter</button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="clearAllSelection()" title="Batalkan seluruh centang">Batal Pilih</button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="toggleModeSelect(false)" title="Tutup mode select">✕ Keluar Mode</button>
        </div>
    </div>

    <!-- 2. CONTEXT MENU KLIK KANAN BARIS TABEL -->
    <div id="ddContextMenu" class="dd-context-menu">
        <div class="dd-context-item" id="ctxItemDownloadExcel">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M854.6 288.6L639.4 73.4c-6-6-14.1-9.4-22.6-9.4H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V311.3c0-8.5-3.4-16.7-9.4-22.7zM790.2 326H602V137.8L790.2 326zm1.8 562H232V136h302v216a42 42 0 0 0 42 42h216v494z"/>
                <path d="M514.1 639.9l61.7-89c3.1-4.5 4.7-9.9 4.7-15.3 0-14.7-11.9-26.6-26.6-26.6h-34.9c-7.3 0-14.1 3.5-18.4 9.4l-40.3 58-40.3-58c-4.3-5.9-11.1-9.4-18.4-9.4h-34.9c-14.7 0-26.6 11.9-26.6 26.6 0 5.4 1.6 10.8 4.7 15.3l61.7 89-63.5 91.6c-3.1 4.5-4.7 9.9-4.7 15.3 0 14.7 11.9 26.6 26.6 26.6h35.5c7.3 0 14.1-3.5 18.4-9.4l42-60.5 42 60.5c4.3 5.9 11.1 9.4 18.4 9.4h35.5c14.7 0 26.6-11.9 26.6-26.6 0-5.4-1.6-10.8-4.7-15.3l-63.5-91.6z"/>
            </svg>
            <span>Unduh Excel</span>
        </div>
        <div class="dd-context-item" id="ctxItemImportH2H">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M880 112H144c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V144c0-17.7-14.3-32-32-32zm-40 728H184V184h656v656z"/>
                <path d="M512 304c-8.8 0-16 7.2-16 16v208h-80c-7.1 0-10.7 8.6-5.7 13.6l101.7 101.7c3.1 3.1 8.2 3.1 11.3 0l101.7-101.7c5-5 1.4-13.6-5.7-13.6h-80V320c0-8.8-7.2-16-16-16z"/>
            </svg>
            <span>Import ke H2H</span>
        </div>
        <div class="dd-context-item" id="ctxItemCetakDokumen">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M854.6 288.6L639.4 73.4c-6-6-14.1-9.4-22.6-9.4H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V311.3c0-8.5-3.4-16.7-9.4-22.7zM790.2 326H602V137.8L790.2 326zm1.8 562H232V136h302v216a42 42 0 0 0 42 42h216v494z"/>
                <path d="M336 496h352v64H336zm0 144h352v64H336z"/>
            </svg>
            <span>Cetak Dokumen</span>
        </div>
        <div class="dd-context-item" id="ctxItemViewJson">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#722ed1">
                <path d="M780.8 520.1L646.6 385.9a8.03 8.03 0 0 0-11.3 0l-45.3 45.3a8.03 8.03 0 0 0 0 11.3L698.8 551l-108.8 108.8a8.03 8.03 0 0 0 0 11.3l45.3 45.3c3.1 3.1 8.2 3.1 11.3 0l134.2-134.2c6.2-6.2 6.2-16.4 0-22.1zM243.2 503.9l134.2-134.2c3.1-3.1 3.1-8.2 0-11.3l-45.3-45.3a8.03 8.03 0 0 0-11.3 0L186.6 447.3c-6.2 6.2-6.2 16.4 0 22.1l134.2 134.2c3.1 3.1 8.2 3.1 11.3 0l45.3-45.3c3.1-3.1 3.1-8.2 0-11.3L268.6 438.2l-25.4 65.7z"/>
            </svg>
            <span>Lihat JSON LNSW</span>
        </div>
        <div class="dd-context-item" id="ctxItemViewPdf">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#ff4d4f">
                <path d="M532 224h260c4.4 0 8 3.6 8 8v632c0 4.4-3.6 8-8 8H232c-4.4 0-8-3.6-8-8V160c0-4.4 3.6-8 8-8h300v72zm-72 328h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm-144 0h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm320 0h-88c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c4.4 0 8-3.6 8-8v-48h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8h-48v-32h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8z"></path>
            </svg>
            <span>Buka Dokumen PDF</span>
        </div>
        <div class="dd-context-item" id="ctxItemTracking">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm0 820c-205.4 0-372-166.6-372-372s166.6-372 372-372 372 166.6 372 372-166.6 372-372 372z"></path>
                <path d="M686.7 638.6L544.1 535.5V288c0-4.4-3.6-8-8-8h-48c-4.4 0-8 3.6-8 8v275.9c0 2.7 1.4 5.3 3.7 6.8l164.7 119.5a8 8 0 0 0 11.4-1.9l28.6-39.7a8 8 0 0 0-1.8-10z"></path>
            </svg>
            <span>Riwayat Status Tracking</span>
        </div>
        <div class="dd-context-divider" id="ctxDividerDelete" style="display:none;"></div>
        <div class="dd-context-item row-action-item-danger" id="ctxItemDeleteDoc" style="display:none; color:#ff4d4f;" title="Hapus data dari tabel rekap ini">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#ff4d4f">
                <path d="M864 256H736V160c0-35.3-28.7-64-64-64H352c-35.3 0-64 28.7-64 64v96H160c-17.7 0-32 14.3-32 32v16c0 4.4 3.6 8 8 8h56.4l41.6 572.2c2.6 35.7 32.4 63.8 68.2 63.8h419.6c35.8 0 65.6-28.1 68.2-63.8L831.6 312H888c4.4 0 8-3.6 8-8v-16c0-17.7-14.3-32-32-32zM368 176h288v80H368v-80zm386.1 704H269.9l-39.6-544h533.4l-39.6 544z"/>
                <path d="M432 400h48v336h-48zm112 0h48v336h-48z"/>
            </svg>
            <span style="font-weight:600;">Hapus Dokumen</span>
        </div>
        <div class="dd-context-divider"></div>
        <div class="dd-context-item" id="ctxItemSelectToggle">
            <span>☑️</span>
            <span id="ctxTextSelect">Pilih Dokumen Ini</span>
        </div>
    </div>

    <!-- 2.1 DROPDOWN MENU AKSI TOMBOL KOLOM PALING KANAN -->
    <div id="rowActionMenu" class="row-action-menu">
        <div class="row-action-item" id="rowActionExcel" onclick="triggerRowAction('excel')">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M854.6 288.6L639.4 73.4c-6-6-14.1-9.4-22.6-9.4H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V311.3c0-8.5-3.4-16.7-9.4-22.7zM790.2 326H602V137.8L790.2 326zm1.8 562H232V136h302v216a42 42 0 0 0 42 42h216v494z"/>
                <path d="M514.1 639.9l61.7-89c3.1-4.5 4.7-9.9 4.7-15.3 0-14.7-11.9-26.6-26.6-26.6h-34.9c-7.3 0-14.1 3.5-18.4 9.4l-40.3 58-40.3-58c-4.3-5.9-11.1-9.4-18.4-9.4h-34.9c-14.7 0-26.6 11.9-26.6 26.6 0 5.4 1.6 10.8 4.7 15.3l61.7 89-63.5 91.6c-3.1 4.5-4.7 9.9-4.7 15.3 0 14.7 11.9 26.6 26.6 26.6h35.5c7.3 0 14.1-3.5 18.4-9.4l42-60.5 42 60.5c4.3 5.9 11.1 9.4 18.4 9.4h35.5c14.7 0 26.6-11.9 26.6-26.6 0-5.4-1.6-10.8-4.7-15.3l-63.5-91.6z"/>
            </svg>
            <span>Unduh Excel</span>
        </div>
        <div class="row-action-item" id="rowActionImportH2H" onclick="triggerRowAction('import_h2h')">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M880 112H144c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V144c0-17.7-14.3-32-32-32zm-40 728H184V184h656v656z"/>
                <path d="M512 304c-8.8 0-16 7.2-16 16v208h-80c-7.1 0-10.7 8.6-5.7 13.6l101.7 101.7c3.1 3.1 8.2 3.1 11.3 0l101.7-101.7c5-5 1.4-13.6-5.7-13.6h-80V320c0-8.8-7.2-16-16-16z"/>
            </svg>
            <span>Import ke H2H</span>
        </div>
        <div class="row-action-item" id="rowActionCetak" onclick="triggerRowAction('cetak')">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M854.6 288.6L639.4 73.4c-6-6-14.1-9.4-22.6-9.4H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V311.3c0-8.5-3.4-16.7-9.4-22.7zM790.2 326H602V137.8L790.2 326zm1.8 562H232V136h302v216a42 42 0 0 0 42 42h216v494z"/>
                <path d="M336 496h352v64H336zm0 144h352v64H336z"/>
            </svg>
            <span>Cetak Dokumen</span>
        </div>
        <div class="row-action-item" id="rowActionViewJson" onclick="triggerRowAction('json')">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#722ed1">
                <path d="M780.8 520.1L646.6 385.9a8.03 8.03 0 0 0-11.3 0l-45.3 45.3a8.03 8.03 0 0 0 0 11.3L698.8 551l-108.8 108.8a8.03 8.03 0 0 0 0 11.3l45.3 45.3c3.1 3.1 8.2 3.1 11.3 0l134.2-134.2c6.2-6.2 6.2-16.4 0-22.1zM243.2 503.9l134.2-134.2c3.1-3.1 3.1-8.2 0-11.3l-45.3-45.3a8.03 8.03 0 0 0-11.3 0L186.6 447.3c-6.2 6.2-6.2 16.4 0 22.1l134.2 134.2c3.1 3.1 8.2 3.1 11.3 0l45.3-45.3c3.1-3.1 3.1-8.2 0-11.3L268.6 438.2l-25.4 65.7z"/>
            </svg>
            <span>Lihat JSON LNSW</span>
        </div>
        <div class="row-action-item" id="rowActionStatus" onclick="triggerRowAction('status')">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#1890ff">
                <path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm0 820c-205.4 0-372-166.6-372-372s166.6-372 372-372 372 166.6 372 372-166.6 372-372 372z"></path>
                <path d="M686.7 638.6L544.1 535.5V288c0-4.4-3.6-8-8-8h-48c-4.4 0-8 3.6-8 8v275.9c0 2.7 1.4 5.3 3.7 6.8l164.7 119.5a8 8 0 0 0 11.4-1.9l28.6-39.7a8 8 0 0 0-1.8-10z"></path>
            </svg>
            <span>Status Dokumen</span>
        </div>
        <div class="row-action-divider" id="rowActionDeleteDivider" style="display:none;"></div>
        <div class="row-action-item row-action-item-danger" id="rowActionDelete" onclick="triggerRowAction('hapus')" style="display:none;" title="Hapus data dari tabel rekap ini">
            <svg viewBox="64 64 896 896" width="13" height="13" fill="#ff4d4f">
                <path d="M864 256H736V160c0-35.3-28.7-64-64-64H352c-35.3 0-64 28.7-64 64v96H160c-17.7 0-32 14.3-32 32v16c0 4.4 3.6 8 8 8h56.4l41.6 572.2c2.6 35.7 32.4 63.8 68.2 63.8h419.6c35.8 0 65.6-28.1 68.2-63.8L831.6 312H888c4.4 0 8-3.6 8-8v-16c0-17.7-14.3-32-32-32zM368 176h288v80H368v-80zm386.1 704H269.9l-39.6-544h533.4l-39.6 544z"/>
                <path d="M432 400h48v336h-48zm112 0h48v336h-48z"/>
            </svg>
            <span style="font-weight:600;">Hapus Dokumen</span>
        </div>
    </div>

    <!-- 3. MODAL: BATCH DOWNLOAD EXCEL PROGRESS & ANTI-WAF ENGINE -->
    <div class="modal-overlay" id="modalBatchProgress" style="display:none;">
        <div class="modal" style="max-width:620px; width:95%; border-radius:8px; box-shadow:0 16px 40px rgba(0,0,0,0.25); overflow:hidden;">
            <div class="modal-header" style="background:var(--bg-filter); border-bottom:1px solid var(--border-card); padding:12px 18px; display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:16px;">📥</span>
                    <div>
                        <h3 id="batchModalTitle" style="font-size:13px; font-weight:700; margin:0; color:var(--text-heading);">Unduh Excel Massal CEISA 4.0</h3>
                        <div id="batchModalSubtitle" style="font-size:10px; color:#1890ff; font-weight:600; margin-top:2px;">Proses Sekuensial Otomatis</div>
                    </div>
                </div>
                <button type="button" class="ant-btn ant-btn-sm" onclick="cancelBatchDownload()">✕</button>
            </div>
            <div class="modal-body" style="padding:16px 18px; display:flex; flex-direction:column; gap:12px; max-height:75vh; overflow-y:auto;">
                <!-- Overall Progress -->
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; font-size:11px;">
                        <span id="batchModalStatusText" style="font-weight:600; color:var(--text-heading);">Menyiapkan pengunduhan...</span>
                        <span id="batchModalPercent" style="font-weight:700; color:#52c41a; font-family:monospace;">0%</span>
                    </div>
                    <div style="height:8px; background:var(--border-card); border-radius:4px; overflow:hidden;">
                        <div id="batchModalProgressBar" style="width:0%; height:100%; background:#52c41a; transition:width 0.3s ease;"></div>
                    </div>
                </div>

                <!-- Live Safety Countdown Box (WAF Evasion Indicator) -->
                <div id="batchModalSafetyBox" style="background:rgba(82,196,26,0.08); border:1px solid #b7eb8f; border-radius:6px; padding:8px 12px; font-size:10.5px; color:var(--text-main); display:flex; align-items:center; gap:8px;">
                    <span class="spinner" id="batchSafetySpinner" style="border-top-color:#52c41a; width:12px; height:12px;"></span>
                    <span id="batchModalSafetyTimer">Memulai proses pengunduhan berurutan...</span>
                </div>

                <!-- Log List of Processed Items -->
                <div style="border:1px solid var(--border-card); border-radius:6px; background:var(--bg-input); max-height:180px; overflow-y:auto; padding:6px 10px; font-size:10.5px; font-family:monospace;" id="batchModalLogList">
                    <!-- Dynamic log rows -->
                </div>
            </div>
            <div class="modal-footer" style="padding:10px 18px; border-top:1px solid var(--border-card); background:var(--bg-filter); display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:11px; color:var(--text-muted);">
                    Berhasil: <b id="batchCountSuccess" style="color:#52c41a;">0</b> | Gagal: <b id="batchCountFailed" style="color:#ff4d4f;">0</b>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="ant-btn ant-btn-sm" id="btnBatchPauseResume" onclick="togglePauseBatchDownload()">⏸️ Jeda</button>
                    <button type="button" class="ant-btn ant-btn-sm" id="btnBatchCancel" onclick="cancelBatchDownload()">✕ Batalkan</button>
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-success" id="btnBatchDownloadZip" onclick="triggerZipDownload()" style="display:none; font-weight:600;">📦 Unduh Arsip ZIP</button>
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" id="btnBatchClose" onclick="closeBatchModal()" style="display:none;">✓ Selesai</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. MODAL: LIHAT JSON LNSW (report-parser/v1/lnsw/get-data-doc) -->
    <div class="modal-overlay" id="modalJsonViewer" style="display:none;">
        <div class="modal" style="max-width:860px; width:95%; border-radius:8px; box-shadow:0 16px 40px rgba(0,0,0,0.25); overflow:hidden; display:flex; flex-direction:column; max-height:88vh;">
            <div class="modal-header" style="background:var(--bg-filter); border-bottom:1px solid var(--border-card); padding:12px 18px; display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:16px;">🔍</span>
                    <div>
                        <h3 id="jsonModalTitle" style="font-size:13px; font-weight:700; margin:0; color:var(--text-heading);">Data Dokumen CEISA 4.0 (JSON)</h3>
                        <div id="jsonModalSubtitle" style="font-size:10.5px; color:#1890ff; font-family:monospace; margin-top:2px;">-</div>
                    </div>
                </div>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeJsonModal()">✕</button>
            </div>
            <div class="modal-body" style="padding:14px 18px; flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:10px;">
                <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; color:var(--text-muted); background:var(--bg-input); padding:6px 12px; border-radius:6px; border:1px solid var(--border-card);">
                    <div style="font-weight:600; color:var(--text-main); font-size:11px;">Struktur Data Dokumen Pabean</div>
                    <div id="jsonModalStatusBadge"><span class="ant-tag ant-tag-blue">HTTP 200 OK</span></div>
                </div>
                <div style="position:relative; flex:1; min-height:300px;">
                    <pre id="jsonModalContent" style="margin:0; padding:12px; background:#1e1e1e; color:#d4d4d4; font-family:Consolas, 'Courier New', monospace; font-size:11px; line-height:1.5; border-radius:6px; height:100%; min-height:300px; max-height:55vh; overflow:auto; white-space:pre-wrap; word-break:break-all;"></pre>
                </div>
            </div>
            <div class="modal-footer" style="padding:10px 18px; border-top:1px solid var(--border-card); background:var(--bg-filter); display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:11px; color:var(--text-muted);">
                    <span id="jsonModalSizeInfo">-</span>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="ant-btn ant-btn-sm" id="btnCopyJson" onclick="copyJsonToClipboard()">📋 Salin JSON</button>
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" id="btnOpenJsonTab" onclick="openJsonInNewTab()">↗️ Buka di Tab Baru</button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="closeJsonModal()">Tutup</button>
                </div>
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

        // Peta ID User CEISA per perusahaan untuk memblokir menu InitMFA jika belum diset
        const companyIdUserMap = <?= json_encode(array_column($companies, 'id_user_ceisa', 'cmp_id')) ?>;
        // Peta Pengaturan Tampil Kartu Belum Import H2H per Perusahaan
        const companyShowUnimportedMap = <?= json_encode(array_column($companies, 'show_unimported_h2h', 'cmp_id')) ?>;
        let currentShowUnimportedH2H = <?= (int)$targetCompanyShowUnimportedH2h ?>;

        function hasIdUserCeisa(cmpId, aju) {
            const cId = cmpId || document.getElementById('filterCmp')?.value || initialCmp || '<?= htmlspecialchars($sessionCmpId) ?>';
            if (cId && companyIdUserMap && typeof companyIdUserMap[cId] !== 'undefined') {
                return !!(companyIdUserMap[cId] && companyIdUserMap[cId].trim());
            }
            if (aju && typeof ajuData !== 'undefined' && Array.isArray(ajuData)) {
                const item = ajuData.find(d => d.nomor_aju === aju);
                if (item && typeof item.id_user_ceisa !== 'undefined') {
                    return !!(item.id_user_ceisa && item.id_user_ceisa.trim());
                }
            }
            return false;
        }

        function checkIdUserCeisaOrAlert(cmpId, actionName, aju) {
            if (!hasIdUserCeisa(cmpId, aju)) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: '<span style="font-size:15px; font-weight:700;">ID User CEISA Belum Diatur</span>',
                        html: `
                            <div style="text-align:left; font-size:12px; line-height:1.6;">
                                Fitur <b>${escapeHtml(actionName || 'ini')}</b> memerlukan integrasi <b>InitMFA</b> dengan akun pabean CEISA 4.0.<br><br>
                                Saat ini <b>ID User CEISA</b> pada profil perusahaan ini belum dikonfigurasi.<br><br>
                                <div style="background:rgba(250,173,20,0.1); border-left:3px solid #faad14; padding:8px 12px; border-radius:4px; margin:8px 0; font-size:11.5px;">
                                    📌 <b>Petunjuk Pengaturan:</b><br>
                                    Buka menu <b>Kelola Perusahaan</b> &rarr; Tab <b>Akun CEISA &amp; TOTP MFA</b> &rarr; Isi kolom <b>ID User CEISA</b>.
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'Mengerti',
                        confirmButtonColor: '#1890ff'
                    });
                } else {
                    alert(`Fitur ${actionName || 'ini'} memerlukan ID User CEISA. Silakan lengkapi terlebih dahulu di menu Kelola Perusahaan (Tab Akun CEISA & TOTP MFA).`);
                }
                return false;
            }
            return true;
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

            if (bc) {
                try {
                    if (cmp) localStorage.setItem('ceisa_last_bc_' + cmp, bc);
                    localStorage.setItem('ceisa_last_bc', bc);
                    if (cmp) document.cookie = `ceisa_last_bc_${cmp}=${encodeURIComponent(bc)}; path=/; max-age=31536000; SameSite=Lax`;
                    document.cookie = `ceisa_last_bc=${encodeURIComponent(bc)}; path=/; max-age=31536000; SameSite=Lax`;
                } catch(e) {}
            }

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

            // Lewati jika ID User CEISA belum diset pada profil perusahaan
            if (!hasIdUserCeisa(cmp)) {
                if (isManual) {
                    checkIdUserCeisaOrAlert(cmp, 'Sinkron CEISA');
                }
                return;
            }

            isCeisaSyncing = true;
            const indicator = document.getElementById('silentSyncIndicator');
            const indicatorText = document.getElementById('silentSyncText');
            const btnSync = document.getElementById('btnSyncCeisa');

            if (indicator) {
                if (isManual) {
                    indicator.style.display = 'inline-flex';
                    indicator.className = 'sync-indicator sync-active';
                    if (indicatorText) indicatorText.innerText = 'Menyinkronkan data CEISA...';
                } else {
                    indicator.style.display = 'none';
                }
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
                    const hasRealChanges = !!json.has_changes;
                    const changeTotal = (json.new_docs || 0) + (json.changed_responses || 0) + (json.updated_headers || 0);

                    if (hasRealChanges) {
                        // Tampilkan hanya jika ada perubahan riil
                        if (indicator && indicatorText) {
                            indicator.style.display = 'inline-flex';
                            indicator.className = 'sync-indicator sync-success';
                            indicatorText.innerText = `✓ CEISA 4.0 Terupdate (${changeTotal > 0 ? changeTotal + ' dokumen' : 'pembaruan data'})`;
                            setTimeout(() => {
                                if (indicator) indicator.style.display = 'none';
                            }, 3500);
                        }
                        loadList(true);
                    } else {
                        // Tidak ada perubahan / tidak ada yang sync
                        if (isManual && indicator && indicatorText) {
                            indicator.style.display = 'inline-flex';
                            indicator.className = 'sync-indicator sync-success';
                            indicatorText.innerText = '✓ Data CEISA sudah mutakhir (tidak ada dokumen baru)';
                            setTimeout(() => {
                                if (indicator) indicator.style.display = 'none';
                            }, 3000);
                        } else if (indicator) {
                            indicator.style.display = 'none';
                        }
                    }
                } else if (json.status === 'RATE_LIMITED') {
                    if (indicator && indicatorText && isManual) {
                        indicator.style.display = 'inline-flex';
                        indicator.className = 'sync-indicator sync-warning';
                        indicatorText.innerText = json.message || 'Antrean padat: Sinkronisasi dijeda sementara';
                        setTimeout(() => {
                            if (indicator) indicator.style.display = 'none';
                        }, 5000);
                    } else if (indicator) {
                        indicator.style.display = 'none';
                    }
                } else if (json.status === 'CACHED') {
                    if (isManual && indicator && indicatorText) {
                        indicator.style.display = 'inline-flex';
                        indicator.className = 'sync-indicator sync-success';
                        indicatorText.innerText = '✓ Data CEISA masih segar';
                        setTimeout(() => {
                            if (indicator) indicator.style.display = 'none';
                        }, 2500);
                    } else if (indicator) {
                        indicator.style.display = 'none';
                    }
                } else {
                    if (indicator && indicatorText && isManual) {
                        indicator.style.display = 'inline-flex';
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

        let currentCardFilter = 'all'; // 'all', 'unposted', 'posted', 'not_h2h', 'not_inv'
        let currentMonthFilter = '';    // e.g. '2026-10' untuk not_inv
        let currentMonthFilterH2H = ''; // e.g. '2026-10' untuk not_h2h

        function filterByCard(type, autoSelectAll = false) {
            if (document.getElementById('filterStatus')) {
                document.getElementById('filterStatus').value = '';
            }
            if (currentCardFilter === type && !autoSelectAll) {
                if (type !== 'all') {
                    currentCardFilter = 'all';
                }
            } else {
                currentCardFilter = type;
            }

            // Reset filter bulan jika keluar dari kartu terkait
            if (currentCardFilter !== 'not_inv') {
                currentMonthFilter = '';
                const badge = document.getElementById('badgeActiveMonth');
                if (badge) badge.style.display = 'none';
            }
            if (currentCardFilter !== 'not_h2h') {
                currentMonthFilterH2H = '';
                const badgeH2H = document.getElementById('badgeActiveMonthH2H');
                if (badgeH2H) badgeH2H.style.display = 'none';
            }

            updateCardFilterUI();
            currentPage = 1;
            filterClientTable();
            updateBtnQuickSelectNotH2H();

            // Select all HANYA jika autoSelectAll parameter explicitly true (misal dipanggil dari tombol khusus)
            if (autoSelectAll) {
                toggleModeSelect(true);
                selectAllFilteredData();
                updateBtnQuickSelectNotH2H();
            }
        }

        function toggleSelectAllNotH2H() {
            // Pastikan kita sedang berada di mode filter not_h2h
            if (currentCardFilter !== 'not_h2h') {
                currentCardFilter = 'not_h2h';
                updateCardFilterUI();
                currentPage = 1;
                filterClientTable();
            }

            if (!filteredData || filteredData.length === 0) {
                if (typeof Swal !== 'undefined') {
                    Swal.mixin({
                        toast: true, position: 'top-end', showConfirmButton: false, timer: 2000, timerProgressBar: true
                    }).fire({
                        icon: 'info',
                        title: 'Tidak ada dokumen yang belum import H2H'
                    });
                }
                return;
            }

            // Cek apakah seluruh baris di filteredData saat ini sudah ada di selectedAju
            const isAllSelected = filteredData.length > 0 && filteredData.every(r => selectedAju.has(r.nomor_aju));
            const btn = document.getElementById('btnQuickSelectNotH2H');

            if (isAllSelected) {
                // KLIK KE-2: UNSELECT ALL (Batalkan seluruh pilihan)
                filteredData.forEach(r => selectedAju.delete(r.nomor_aju));
                const masterCb = document.getElementById('masterSelectCb');
                if (masterCb) masterCb.checked = false;
                renderTable();
                updateSelectedCount();
                if (btn) btn.innerHTML = '⚡ Select All';
                if (typeof Swal !== 'undefined') {
                    Swal.mixin({
                        toast: true, position: 'top-end', showConfirmButton: false, timer: 2000, timerProgressBar: true
                    }).fire({
                        icon: 'info',
                        title: 'Semua pilihan dokumen Belum Import H2H dibatalkan'
                    });
                }
            } else {
                // KLIK KE-1: SELECT ALL (Pilih semua dokumen yang terfilter)
                toggleModeSelect(true);
                filteredData.forEach(r => selectedAju.add(r.nomor_aju));
                const masterCb = document.getElementById('masterSelectCb');
                if (masterCb) masterCb.checked = true;
                renderTable();
                updateSelectedCount();
                if (btn) btn.innerHTML = '✕ Unselect All';
                if (typeof Swal !== 'undefined') {
                    Swal.mixin({
                        toast: true, position: 'top-end', showConfirmButton: false, timer: 2500, timerProgressBar: true
                    }).fire({
                        icon: 'success',
                        title: `⚡ ${filteredData.length} dokumen Belum Import H2H berhasil dipilih!`
                    });
                }
            }
        }

        function updateBtnQuickSelectNotH2H() {
            const btn = document.getElementById('btnQuickSelectNotH2H');
            if (!btn) return;
            if (currentCardFilter === 'not_h2h' && filteredData && filteredData.length > 0 && filteredData.every(r => selectedAju.has(r.nomor_aju))) {
                btn.innerHTML = '✕ Unselect All';
            } else {
                btn.innerHTML = '⚡ Select All';
            }
        }

        function updateCardFilterUI() {
            const chips = {
                'all': 'kpiChipTotal',
                'unposted': 'kpiChipUnposted',
                'posted': 'kpiChipPosted',
                'not_h2h': 'kpiChipNotH2H',
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

        let currentSortCol = 'tanggal_respon';
        let currentSortDir = 'desc';

        function sortTable(col) {
            if (currentSortCol === col) {
                currentSortDir = (currentSortDir === 'desc') ? 'asc' : 'desc';
            } else {
                currentSortCol = col;
                currentSortDir = (col === 'tanggal_respon' || col === 'nomor_daftar') ? 'desc' : 'asc';
            }
            updateSortIcons();
            currentPage = 1;
            filterClientTable();
        }

        function updateSortIcons() {
            document.querySelectorAll('.th-sortable').forEach(th => {
                const c = th.dataset.sortCol;
                const icon = th.querySelector('.th-sort-icon');
                if (c === currentSortCol) {
                    th.classList.add('th-sorted');
                    if (icon) {
                        icon.innerHTML = currentSortDir === 'desc' ? '▼' : '▲';
                        icon.style.opacity = '1';
                    }
                } else {
                    th.classList.remove('th-sorted');
                    if (icon) {
                        icon.innerHTML = '⇅';
                        icon.style.opacity = '0.35';
                    }
                }
            });
        }

        function isDocPerekaman(r) {
            if (!r) return false;
            const np = (r.namaProses || '').toLowerCase();
            const kp = String(r.kode_proses || r.kodeProses || '');
            return np.includes('perekaman') || np.includes('draft') || kp === '001' || kp === '100';
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
                } else if (currentCardFilter === 'not_h2h') {
                    const inStaging = parseInt(r.in_staging || 0, 10) > 0;
                    if (inStaging) return false;
                } else if (currentCardFilter === 'not_inv') {
                    const isInv = parseInt(r.is_inv !== undefined ? r.is_inv : 1, 10);
                    const inItInv = parseInt(r.in_it_inv || 0, 10) > 0;
                    if (isInv !== 1 || inItInv) return false;
                }

                // Filter Bulan / Periode dari Rekap Not Inv
                if (currentMonthFilter) {
                    const m = getDocMonth(r);
                    if (m !== currentMonthFilter) return false;
                }

                // Filter Bulan / Periode dari Rekap Belum Import H2H
                if (currentMonthFilterH2H) {
                    const m = getDocMonth(r);
                    if (m !== currentMonthFilterH2H) return false;
                }

                const isPerekaman = isDocPerekaman(r);

                if (fBc && !('bc ' + r.bc).toLowerCase().includes(fBc)) return false;
                if (fAju && !r.nomor_aju.toLowerCase().includes(fAju)) return false;
                if (fDaftar) {
                    if (isPerekaman) return false;
                    if (!(r.nomor_daftar || '').toLowerCase().includes(fDaftar)) return false;
                }
                if (fRespon) {
                    if (isPerekaman) return false;
                    const rName = (r.bcd_namaRespon || (r.sppb_nomor_respon ? 'sppb' : '')).toLowerCase();
                    if (!rName.includes(fRespon)) return false;
                }
                if (fJalur) {
                    if (isPerekaman) return false;
                    const jChar = (r.bcd_kodeJalur || '').toLowerCase();
                    if (!jChar || !jChar.includes(fJalur)) return false;
                }
                if (fPerusahaan && !(r.cmp_name || r.bs || '').toLowerCase().includes(fPerusahaan)) return false;
                if (fKantor && !(r.bcd_namaKantorPendek || r.kode_kantor || '').toLowerCase().includes(fKantor)) return false;
                return true;
            });

            // Urutkan data berdasarkan kolom aktif (Default: Tanggal Respon Descending)
            filteredData.sort((a, b) => {
                let valA = '';
                let valB = '';
                if (currentSortCol === 'tanggal_respon') {
                    valA = a.effective_tanggal_respon || a.bcd_tanggalRespon || a.sppb_tanggal_respon || a.posted_waktu || a.tanggal_pernyataan || '';
                    valB = b.effective_tanggal_respon || b.bcd_tanggalRespon || b.sppb_tanggal_respon || b.posted_waktu || b.tanggal_pernyataan || '';
                } else if (currentSortCol === 'bc') {
                    valA = a.bc || ''; valB = b.bc || '';
                } else if (currentSortCol === 'nomor_aju') {
                    valA = a.nomor_aju || ''; valB = b.nomor_aju || '';
                } else if (currentSortCol === 'nomor_daftar') {
                    valA = isDocPerekaman(a) ? '' : ((a.nomor_daftar || '') + ' ' + (a.tanggal_daftar || ''));
                    valB = isDocPerekaman(b) ? '' : ((b.nomor_daftar || '') + ' ' + (b.tanggal_daftar || ''));
                } else if (currentSortCol === 'nama_respon') {
                    valA = isDocPerekaman(a) ? '' : (a.bcd_namaRespon || (a.sppb_nomor_respon ? 'SPPB' : '') || '');
                    valB = isDocPerekaman(b) ? '' : (b.bcd_namaRespon || (b.sppb_nomor_respon ? 'SPPB' : '') || '');
                } else if (currentSortCol === 'jalur') {
                    valA = isDocPerekaman(a) ? '' : (a.bcd_kodeJalur || '');
                    valB = isDocPerekaman(b) ? '' : (b.bcd_kodeJalur || '');
                } else if (currentSortCol === 'perusahaan') {
                    valA = a.cmp_name || a.bs || ''; valB = b.cmp_name || b.bs || '';
                } else if (currentSortCol === 'kantor') {
                    valA = a.bcd_namaKantorPendek || a.kode_kantor || '';
                    valB = b.bcd_namaKantorPendek || b.kode_kantor || '';
                }

                if (valA === valB) {
                    return (b.nomor_aju || '').localeCompare(a.nomor_aju || '');
                }
                const cmp = valA.localeCompare(valB);
                return currentSortDir === 'desc' ? -cmp : cmp;
            });

            // Update KPI metrics (selalu dihitung dari seluruh data ajuData)
            let unposted = 0, posted = 0, notH2H = 0, notInv = 0;
            let isInvEnabled = false;
            let isH2HVisible = (currentShowUnimportedH2H === 1);

            ajuData.forEach(r => {
                const np = (r.namaProses || '').toLowerCase();
                if (np.includes('selesai') || parseInt(r.posted) > 0) posted++; else unposted++;

                // Belum Import ke H2H (staging bc_ceisa_header)
                if (parseInt(r.in_staging || 0, 10) === 0) {
                    notH2H++;
                }

                // Cek flag show_unimported_h2h jika ada di record
                if (r.show_unimported_h2h !== undefined && parseInt(r.show_unimported_h2h, 10) === 1) {
                    isH2HVisible = true;
                }

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

            // Card Belum Import H2H
            const chipNotH2H = document.getElementById('kpiChipNotH2H');
            if (chipNotH2H) {
                const selectedCmp = document.getElementById('filterCmp')?.value || initialCmp || '<?= htmlspecialchars($sessionCmpId) ?>';
                if (companyShowUnimportedMap && typeof companyShowUnimportedMap[selectedCmp] !== 'undefined') {
                    isH2HVisible = (parseInt(companyShowUnimportedMap[selectedCmp], 10) === 1);
                }
                if (isH2HVisible) {
                    chipNotH2H.style.display = 'inline-flex';
                    if (document.getElementById('kpiNotH2H')) document.getElementById('kpiNotH2H').innerText = notH2H;
                } else {
                    chipNotH2H.style.display = 'none';
                    if (currentCardFilter === 'not_h2h') {
                        currentCardFilter = 'all';
                        updateCardFilterUI();
                    }
                }
            }

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

            // Segarkan tabel rekap jika widget sedang terbuka
            const rekapWidget = document.getElementById('rekapNotInvWidget');
            if (rekapWidget && rekapWidget.style.display !== 'none') {
                renderRekapNotInvTable();
            }
            const rekapH2HWidget = document.getElementById('rekapNotH2HWidget');
            if (rekapH2HWidget && rekapH2HWidget.style.display !== 'none') {
                renderRekapNotH2HTable();
            }

            currentPage = 1;
            renderTable();
        }

        function resetFilters() {
            currentCardFilter = 'all';
            currentMonthFilter = '';
            currentMonthFilterH2H = '';
            currentSortCol = 'tanggal_respon';
            currentSortDir = 'desc';
            updateCardFilterUI();
            updateRekapMonthHighlight();
            updateRekapMonthHighlightH2H();
            updateBtnQuickSelectNotH2H();
            updateSortIcons();
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
                tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:28px; color:#8c8c8c;">Tidak ada dokumen AJU yang sesuai dengan filter.</td></tr>`;
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

                // Cek apakah masih dalam tahap perekaman dokumen / draft
                const isPerekaman = isDocPerekaman(r) || tagText.toLowerCase().includes('perekaman') || tagText.toLowerCase().includes('draft');

                // Jalur (H / M / K) - Kosongkan jika masih Perekaman Dokumen atau belum ada jalur
                let jalurHtml = '<span style="color:#bfbfbf;">-</span>';
                if (!isPerekaman && r.bcd_kodeJalur) {
                    const jalurChar = r.bcd_kodeJalur.toUpperCase();
                    let jalurTag = 'ant-tag-green';
                    if (jalurChar === 'M') jalurTag = 'ant-tag-red';
                    else if (jalurChar === 'K') jalurTag = 'ant-tag-gold';
                    jalurHtml = `<span class="ant-tag ${jalurTag}">${escapeHtml(jalurChar)}</span>`;
                }

                // Nama Perusahaan & NPWP
                const companyName = r.cmp_name || r.bs || '-';
                const companyId = r.cmp_npwp || r.id_bs || r.cmp_id || '-';

                // Kantor Pabean
                const kantorName = r.bcd_namaKantorPendek || (r.kode_kantor ? 'KPPBC ' + r.kode_kantor : 'KPPBC TMP A BEKASI');

                // Nomor & Tanggal Pendaftaran - Kosongkan jika masih Perekaman Dokumen
                let nomorDaftarHtml = '<span style="color:#bfbfbf;">-</span>';
                let tanggalDaftarHtml = '';
                if (!isPerekaman && r.nomor_daftar && r.nomor_daftar !== '-') {
                    nomorDaftarHtml = escapeHtml(r.nomor_daftar);
                    if (r.tanggal_daftar) {
                        tanggalDaftarHtml = `<p style="margin:0; font-size:11px; color:#8c8c8c;">${escapeHtml(r.tanggal_daftar)}</p>`;
                    }
                }

                // Respon - Kosongkan jika masih Perekaman Dokumen
                let namaRespon = '-';
                let noRespon = '';
                let responBadgeExtra = '';

                if (!isPerekaman) {
                    namaRespon = r.bcd_namaRespon || (r.sppb_nomor_respon ? 'SPPB' : '-');
                    noRespon = r.bcd_nomorRespon || r.sppb_nomor_respon || '';
                    if (r.bcd_namaRespon && !r.bcd_namaRespon.toUpperCase().includes('SPPB') && r.sppb_nomor_respon) {
                        responBadgeExtra = ` <span class="ant-tag ant-tag-green" style="font-size:9px; padding:0 3px; margin-left:2px;" title="Telah Terbit SPPB: ${escapeHtml(r.sppb_nomor_respon)}">✓ SPPB</span>`;
                    }
                }

                // Tanggal Respon - Kosongkan jika masih Perekaman Dokumen atau belum ada respon
                let tanggalResponDisplay = '-';
                if (!isPerekaman && namaRespon !== '-') {
                    tanggalResponDisplay = r.bcd_tanggalRespon || r.sppb_tanggal_respon || (r.effective_tanggal_respon !== r.posted_waktu ? r.effective_tanggal_respon : '-') || '-';
                }

                // PDF Respon (SPPB / NPE / Respon Resmi) - Kosongkan jika masih Perekaman Dokumen
                const pdfFile = isPerekaman ? '' : (r.pdf || r.sppb_pdf || '');
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

                // Excel Resmi CEISA 4.0
                const excelUrl = `/download_ceisa_excel.php?nomor_aju=${encodeURIComponent(r.nomor_aju)}&cmp_id=${encodeURIComponent(r.cmp_id || '')}&kode_dokumen=${encodeURIComponent(r.bc || r.kode_dokumen || '')}`;
                const excelHtml = `
                    <a href="${excelUrl}" target="_blank" class="excel-btn-badge" title="Unduh Berkas Excel (.xlsx) Resmi dari Portal CEISA 4.0 (${escapeHtml(r.nomor_aju)})">
                        <svg viewBox="64 64 896 896" width="12" height="12" fill="#52c41a" style="vertical-align:-1px;">
                            <path d="M532 224h260c4.4 0 8 3.6 8 8v632c0 4.4-3.6 8-8 8H232c-4.4 0-8-3.6-8-8V160c0-4.4 3.6-8 8-8h300v72zm-72 328h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm-144 0h-32c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c22.1 0 40-17.9 40-40v-96c0-22.1-17.9-40-40-40zm320 0h-88c-4.4 0-8 3.6-8 8v160c0 4.4 3.6 8 8 8h32c4.4 0 8-3.6 8-8v-48h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8h-48v-32h48c4.4 0 8-3.6 8-8v-32c0-4.4-3.6-8-8-8z"></path>
                        </svg>
                        <span style="font-size:9.5px; font-weight:600; color:#389e0d; margin-left:2px;">XLSX</span>
                    </a>
                `;

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
                    <tr class="ant-table-row ant-table-row-level-0 ${isSelected ? 'selected-row' : ''}" id="row-${r.nomor_aju}" oncontextmenu="openRowContextMenu(event, '${r.nomor_aju}', '${r.cmp_id || ''}', '${pdfFile ? encodeURIComponent(pdfFile) : ''}', '${r.id_header || ''}', '${r.kode_dokumen || r.bc || ''}', '${r.kode_proses || ''}', '${r.namaProses ? encodeURIComponent(r.namaProses) : ''}', '${r.nomor_daftar || ''}')">
                        <td style="text-align:center;" class="col-select-mode">
                            <input type="checkbox" class="row-select-cb" data-aju="${r.nomor_aju}" ${isSelected ? 'checked' : ''} onchange="toggleSelectRow('${r.nomor_aju}', this.checked)" style="cursor:pointer;">
                        </td>
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
                            <div>${nomorDaftarHtml}</div>
                            ${tanggalDaftarHtml}
                        </td>
                        <td>
                            <div>${escapeHtml(namaRespon)}${responBadgeExtra}</div>
                            ${noRespon ? `<p style="cursor:pointer; text-decoration:underline; color:rgb(3, 157, 252); margin:0; font-size:10px;" onclick="openTrackingHistory('${r.nomor_aju}', '${r.cmp_id || ''}')" title="Lihat Riwayat Tracking Status">${escapeHtml(noRespon)}</p>` : ''}
                        </td>
                        <td style="text-align:center; white-space:nowrap;">
                            ${pdfHtml}
                        </td>
                        <td style="font-size:10.5px; color:#595959;">${tanggalResponDisplay}</td>
                        <td style="text-align:center;">
                            ${jalurHtml}
                        </td>
                        <td style="max-width:240px; overflow:hidden; text-overflow:ellipsis;">
                            <div style="display:flex; font-size:10px; color:#8c8c8c; font-family:monospace;">${escapeHtml(companyId)}</div>
                            <p style="margin:0; font-weight:500; font-size:11px;">${escapeHtml(companyName)}</p>
                        </td>
                        <td style="text-align:center; white-space:nowrap;" class="td-col-aksi">
                            <button type="button" class="action-btn-ceisa" onclick="openRowActionMenu(event, '${r.nomor_aju}', '${r.cmp_id || ''}', '${pdfFile ? encodeURIComponent(pdfFile) : ''}', '${r.id_header || ''}', '${r.kode_dokumen || r.bc || ''}', '${r.kode_proses || ''}', '${r.namaProses ? encodeURIComponent(r.namaProses) : ''}', '${r.nomor_daftar || ''}')" title="Pilihan Aksi Dokumen ${escapeHtml(r.nomor_aju)}">
                                <span>Aksi</span>
                                <span style="font-size:8px; opacity:0.75; transform:scale(0.85); display:inline-block;">▼</span>
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;

            // Sinkronkan master checkbox halaman ini
            const masterCb = document.getElementById('masterSelectCb');
            if (masterCb) {
                const allPageSelected = pageItems.length > 0 && pageItems.every(item => selectedAju.has(item.nomor_aju));
                masterCb.checked = allPageSelected;
            }

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
                if (!nomorAju) {
                    try {
                        const edIfrm = window.parent.tabIframes && window.parent.tabIframes['editor'];
                        if (edIfrm && edIfrm.contentWindow) {
                            if (typeof edIfrm.contentWindow.openNewAjuSetup === 'function') {
                                edIfrm.contentWindow.openNewAjuSetup();
                            } else if (typeof edIfrm.contentWindow.toggleEntryPanel === 'function') {
                                edIfrm.contentWindow.toggleEntryPanel(true);
                            }
                            edIfrm.contentWindow.postMessage({ type: 'OPEN_NEW_AJU_SETUP' }, '*');
                        }
                    } catch(err) {}
                }
            } else {
                const url = nomorAju ? `/editor.php?nomor_aju=${encodeURIComponent(nomorAju)}` : '/editor.php';
                window.location.href = url;
            }
        }

        // =========================================================================
        // MODE SELECT & BATCH DOWNLOAD EXCEL RESMI CEISA 4.0
        // =========================================================================
        let isModeSelectActive = false;
        let batchDownloadCancelled = false;
        let batchDownloadPaused = false;
        let batchSuccessfulAjus = [];

        function toggleModeSelect(forceState) {
            if (forceState !== undefined) {
                isModeSelectActive = !!forceState;
            } else {
                isModeSelectActive = !isModeSelectActive;
            }

            const btn = document.getElementById('btnModeSelect');
            const lbl = document.getElementById('lblModeSelect');
            const ico = document.getElementById('iconModeSelect');
            const bar = document.getElementById('batchActionBar');

            if (isModeSelectActive) {
                document.body.classList.add('mode-select-active');
                if (btn) {
                    btn.classList.add('ant-btn-primary');
                    btn.style.background = '#1890ff';
                    btn.style.borderColor = '#1890ff';
                }
                if (lbl) lbl.textContent = '✕ Tutup Mode Select';
                if (ico) ico.textContent = '☑️';
                if (bar) bar.style.display = 'flex';
            } else {
                document.body.classList.remove('mode-select-active');
                if (btn) {
                    btn.classList.remove('ant-btn-primary');
                    btn.style.background = '';
                    btn.style.borderColor = '';
                }
                if (lbl) lbl.textContent = 'Mode Select';
                if (ico) ico.textContent = '☑️';
                if (bar) bar.style.display = 'none';
            }

            updateSelectedCount();
        }

        function toggleSelectRow(nomorAju, isChecked) {
            if (isChecked) {
                selectedAju.add(nomorAju);
                document.getElementById(`row-${nomorAju}`)?.classList.add('selected-row');
            } else {
                selectedAju.delete(nomorAju);
                document.getElementById(`row-${nomorAju}`)?.classList.remove('selected-row');
            }
            updateSelectedCount();
        }

        function toggleSelectAllPage(masterCheckbox) {
            const isChecked = masterCheckbox.checked;
            const startIdx = (currentPage - 1) * pageSize;
            const endIdx = Math.min(startIdx + pageSize, filteredData.length);
            const pageItems = filteredData.slice(startIdx, endIdx);

            pageItems.forEach(r => {
                if (isChecked) {
                    selectedAju.add(r.nomor_aju);
                } else {
                    selectedAju.delete(r.nomor_aju);
                }
                const row = document.getElementById(`row-${r.nomor_aju}`);
                if (row) {
                    row.classList.toggle('selected-row', isChecked);
                    const cb = row.querySelector('.row-select-cb');
                    if (cb) cb.checked = isChecked;
                }
            });
            updateSelectedCount();
        }

        function selectAllOnCurrentPage() {
            toggleModeSelect(true);
            const masterCb = document.getElementById('masterSelectCb');
            if (masterCb) masterCb.checked = true;
            toggleSelectAllPage({ checked: true });
        }

        function selectAllFilteredData() {
            toggleModeSelect(true);
            filteredData.forEach(r => {
                selectedAju.add(r.nomor_aju);
            });
            renderTable();
            updateSelectedCount();
        }

        function clearAllSelection() {
            selectedAju.clear();
            const masterCb = document.getElementById('masterSelectCb');
            if (masterCb) masterCb.checked = false;
            document.querySelectorAll('.row-select-cb').forEach(cb => cb.checked = false);
            document.querySelectorAll('.ant-table-row').forEach(tr => tr.classList.remove('selected-row'));
            updateSelectedCount();
            updateBtnQuickSelectNotH2H();
        }

        function updateSelectedCount() {
            const count = (typeof selectedAju !== 'undefined' && selectedAju) ? selectedAju.size : 0;
            const elSelected = document.getElementById('batchSelectedCount');
            const elBtn = document.getElementById('batchBtnCount');
            const elBtnH2H = document.getElementById('batchBtnCountH2H');
            if (elSelected) elSelected.innerText = count;
            if (elBtn) elBtn.innerText = count;
            if (elBtnH2H) elBtnH2H.innerText = count;

            const bar = document.getElementById('batchActionBar');
            if (bar && isModeSelectActive) {
                bar.style.display = 'flex';
            }
            updateBtnQuickSelectNotH2H();
        }

        function downloadSingleCeisaExcel(nomorAju, cmpId) {
            if (!nomorAju) return;
            const activeCmp = cmpId || document.getElementById('filterCmp')?.value || '';
            if (!checkIdUserCeisaOrAlert(activeCmp, 'Unduh Excel', nomorAju)) return;
            const url = `download_ceisa_excel.php?nomor_aju=${encodeURIComponent(nomorAju)}&cmp_id=${encodeURIComponent(activeCmp)}`;
            window.open(url, '_blank');
        }

        // Context Menu Handler (Klik Kanan Baris Tabel)
        let activeContextMenuAju = '';
        let activeContextMenuCmp = '';
        let activeContextMenuPdf = '';
        let activeContextMenuIdHeader = '';
        let activeContextMenuKodeDok = '';
        let activeContextMenuKodeProses = '';
        let activeContextMenuNamaProses = '';
        let activeContextMenuNomorDaftar = '';

        function openRowContextMenu(e, nomorAju, cmpId, pdfFile, idHeader, kodeDokumen, kodeProses, namaProses, nomorDaftar) {
            e.preventDefault();
            activeContextMenuAju = nomorAju;
            activeContextMenuCmp = cmpId;
            activeContextMenuPdf = pdfFile ? decodeURIComponent(pdfFile) : '';
            activeContextMenuIdHeader = idHeader || '';
            activeContextMenuKodeDok = kodeDokumen || '';
            activeContextMenuKodeProses = kodeProses || '';
            activeContextMenuNamaProses = namaProses ? decodeURIComponent(namaProses) : '';
            activeContextMenuNomorDaftar = nomorDaftar || '';

            const menu = document.getElementById('ddContextMenu');
            if (!menu) return;

            const isSel = selectedAju.has(nomorAju);
            const txtSel = document.getElementById('ctxTextSelect');
            if (txtSel) txtSel.textContent = isSel ? 'Batal Pilih Dokumen Ini' : 'Pilih / Centang Dokumen Ini';

            const itemPdf = document.getElementById('ctxItemViewPdf');
            if (itemPdf) {
                itemPdf.style.display = activeContextMenuPdf ? 'flex' : 'none';
            }

            // Opsi Hapus Dokumen: menghapus data dari tabel rekap lokal
            const itemDel = document.getElementById('ctxItemDeleteDoc');
            const divDel = document.getElementById('ctxDividerDelete');
            if (itemDel) itemDel.style.display = 'flex';
            if (divDel) divDel.style.display = 'block';

            // Tampilkan seluruh menu klik kanan, beri status is-disabled jika ID User CEISA belum diset
            const idUserAvailable = hasIdUserCeisa(activeContextMenuCmp, activeContextMenuAju);

            ['ctxItemDownloadExcel', 'ctxItemImportH2H', 'ctxItemCetakDokumen', 'ctxItemViewJson', 'ctxItemTracking'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    if (idUserAvailable) {
                        el.classList.remove('is-disabled');
                        el.title = '';
                    } else {
                        el.classList.add('is-disabled');
                        el.title = 'Fitur ini memerlukan ID User CEISA aktif di Kelola Perusahaan';
                    }
                }
            });
            if (itemDel) {
                itemDel.classList.remove('is-disabled');
                itemDel.title = 'Hapus data dari tabel rekap ini';
            }

            // Atur posisi menu agar tidak keluar layar
            const menuHeight = 275;
            const x = Math.min(e.clientX, window.innerWidth - 210);
            const y = Math.min(e.clientY, window.innerHeight - menuHeight);

            menu.style.left = `${x}px`;
            menu.style.top = `${y}px`;
            menu.style.display = 'block';
        }

        function closeContextMenu() {
            const menu = document.getElementById('ddContextMenu');
            if (menu) menu.style.display = 'none';
        }

        document.addEventListener('click', closeContextMenu);
        window.addEventListener('resize', closeContextMenu);

        document.getElementById('ctxItemDownloadExcel')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju) {
                if (!checkIdUserCeisaOrAlert(activeContextMenuCmp, 'Unduh Excel', activeContextMenuAju)) return;
                const url = `daftar_dokumen.php?action=unduh_excel&nomor_aju=${encodeURIComponent(activeContextMenuAju)}&cmp_id=${encodeURIComponent(activeContextMenuCmp || '')}&kode_dokumen=${encodeURIComponent(activeContextMenuKodeDok || '')}`;
                window.open(url, '_blank');
            }
        });

        document.getElementById('ctxItemImportH2H')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju) {
                if (!checkIdUserCeisaOrAlert(activeContextMenuCmp, 'Import ke H2H', activeContextMenuAju)) return;
                executeImportToH2H(activeContextMenuAju, activeContextMenuCmp);
            }
        });

        document.getElementById('ctxItemCetakDokumen')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju) {
                if (!checkIdUserCeisaOrAlert(activeContextMenuCmp, 'Cetak Dokumen', activeContextMenuAju)) return;
                const url = `daftar_dokumen.php?action=cetak_formulir&nomor_aju=${encodeURIComponent(activeContextMenuAju)}&cmp_id=${encodeURIComponent(activeContextMenuCmp || '')}`;
                window.open(url, '_blank');
            }
        });

        document.getElementById('ctxItemViewJson')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju) {
                openJsonViewer(activeContextMenuAju, activeContextMenuCmp, activeContextMenuIdHeader);
            }
        });

        document.getElementById('ctxItemViewPdf')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju && activeContextMenuPdf) {
                const pdfUrl = `/pdf.php?aju=${encodeURIComponent(activeContextMenuAju)}&pdf=${encodeURIComponent(activeContextMenuPdf)}&cmp_id=${encodeURIComponent(activeContextMenuCmp || '')}&view=1`;
                window.open(pdfUrl, '_blank');
            }
        });

        document.getElementById('ctxItemTracking')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju) {
                if (!checkIdUserCeisaOrAlert(activeContextMenuCmp, 'Riwayat Status Tracking', activeContextMenuAju)) return;
                openTrackingHistory(activeContextMenuAju, activeContextMenuCmp);
            }
        });

        document.getElementById('ctxItemDeleteDoc')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju) {
                executeDeleteDocument(activeContextMenuAju, activeContextMenuCmp, activeContextMenuIdHeader, activeContextMenuKodeDok, activeContextMenuKodeProses);
            }
        });

        document.getElementById('ctxItemSelectToggle')?.addEventListener('click', function(e) {
            e.stopPropagation();
            closeContextMenu();
            if (activeContextMenuAju) {
                toggleModeSelect(true);
                const isChecked = !selectedAju.has(activeContextMenuAju);
                toggleSelectRow(activeContextMenuAju, isChecked);
                const row = document.getElementById(`row-${activeContextMenuAju}`);
                if (row) {
                    const cb = row.querySelector('.row-select-cb');
                    if (cb) cb.checked = isChecked;
                }
            }
        });

        // =========================================================================
        // DROPDOWN MENU AKSI (KOLOM PALING KANAN)
        // =========================================================================
        let activeActionAju = '';
        let activeActionCmp = '';
        let activeActionPdf = '';
        let activeActionIdHeader = '';
        let activeActionKodeDok = '';
        let activeActionKodeProses = '';
        let activeActionNamaProses = '';
        let activeActionNomorDaftar = '';
        let activeActionTriggerBtn = null;

        function openRowActionMenu(event, nomorAju, cmpId, pdfFile, idHeader, kodeDokumen, kodeProses, namaProses, nomorDaftar) {
            if (event) {
                if (typeof event.stopPropagation === 'function') event.stopPropagation();
            }

            const menu = document.getElementById('rowActionMenu');
            if (menu && menu.style.display === 'block' && activeActionAju === nomorAju) {
                closeRowActionMenu();
                return;
            }

            activeActionAju = nomorAju;
            activeActionCmp = cmpId;
            activeActionPdf = pdfFile ? decodeURIComponent(pdfFile) : '';
            activeActionIdHeader = idHeader || '';
            activeActionKodeDok = kodeDokumen || '';
            activeActionKodeProses = kodeProses || '';
            activeActionNamaProses = namaProses ? decodeURIComponent(namaProses) : '';
            activeActionNomorDaftar = nomorDaftar || '';

            if (activeActionTriggerBtn) {
                activeActionTriggerBtn.classList.remove('active');
            }
            activeActionTriggerBtn = event ? (event.currentTarget || event.target.closest('.action-btn-ceisa')) : null;
            if (activeActionTriggerBtn) {
                activeActionTriggerBtn.classList.add('active');
            }

            // Opsi Hapus Dokumen: menghapus data dari tabel rekap lokal
            const delBtn = document.getElementById('rowActionDelete');
            const delDiv = document.getElementById('rowActionDeleteDivider');

            if (delBtn) delBtn.style.display = 'flex';
            if (delDiv) delDiv.style.display = 'block';

            // Tampilkan seluruh aksi, beri status is-disabled jika ID User CEISA belum diset
            const idUserAvailable = hasIdUserCeisa(activeActionCmp, activeActionAju);

            ['rowActionExcel', 'rowActionImportH2H', 'rowActionCetak', 'rowActionViewJson', 'rowActionStatus'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    if (idUserAvailable) {
                        el.classList.remove('is-disabled');
                        el.title = '';
                    } else {
                        el.classList.add('is-disabled');
                        el.title = 'Fitur ini memerlukan ID User CEISA aktif di Kelola Perusahaan';
                    }
                }
            });
            if (delBtn) {
                delBtn.classList.remove('is-disabled');
                delBtn.title = 'Hapus data dari tabel rekap ini';
            }

            const rect = activeActionTriggerBtn ? activeActionTriggerBtn.getBoundingClientRect() : { right: window.innerWidth - 100, bottom: 200, left: 100, top: 100 };
            const menuWidth = 165;
            const menuHeight = 225;

            let left = rect.right - menuWidth;
            if (left < 10) left = Math.max(10, rect.left);
            if (left + menuWidth > window.innerWidth - 10) {
                left = window.innerWidth - menuWidth - 10;
            }

            let top = rect.bottom + 4;
            if (top + menuHeight > window.innerHeight - 10) {
                top = Math.max(10, rect.top - menuHeight - 4);
            }

            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
            menu.style.display = 'block';
        }

        function closeRowActionMenu() {
            const menu = document.getElementById('rowActionMenu');
            if (menu) menu.style.display = 'none';
            if (activeActionTriggerBtn) {
                activeActionTriggerBtn.classList.remove('active');
                activeActionTriggerBtn = null;
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#rowActionMenu') && !e.target.closest('.action-btn-ceisa')) {
                closeRowActionMenu();
            }
        });
        window.addEventListener('resize', closeRowActionMenu);

        async function triggerRowAction(type) {
            const aju = activeActionAju;
            const cmp = activeActionCmp;
            const pdf = activeActionPdf;
            const idHeader = activeActionIdHeader;
            const kodeDok = activeActionKodeDok;
            const kodeProses = activeActionKodeProses;
            closeRowActionMenu();

            if (!aju) return;

            if (type === 'excel') {
                if (!checkIdUserCeisaOrAlert(cmp, 'Unduh Excel', aju)) return;
                // Unduh Excel Resmi CEISA 4.0 via backend action
                const url = `daftar_dokumen.php?action=unduh_excel&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmp || '')}&kode_dokumen=${encodeURIComponent(kodeDok || '')}`;
                window.open(url, '_blank');
            } else if (type === 'import_h2h') {
                if (!checkIdUserCeisaOrAlert(cmp, 'Import ke H2H', aju)) return;
                await executeImportToH2H(aju, cmp);
            } else if (type === 'cetak') {
                if (!checkIdUserCeisaOrAlert(cmp, 'Cetak Dokumen', aju)) return;
                // Cetak Dokumen via report-service formulir resmi CEISA 4.0
                const url = `daftar_dokumen.php?action=cetak_formulir&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmp || '')}`;
                window.open(url, '_blank');
            } else if (type === 'json') {
                openJsonViewer(aju, cmp, idHeader);
            } else if (type === 'status') {
                if (!checkIdUserCeisaOrAlert(cmp, 'Riwayat Status Tracking', aju)) return;
                openTrackingHistory(aju, cmp);
            } else if (type === 'hapus') {
                await executeDeleteDocument(aju, cmp, idHeader, kodeDok, kodeProses);
            }
        }

        async function executeDeleteDocument(aju, cmp, idHeader, kodeDok, kodeProses) {
            if (!aju) return;

            const activeCmp = cmp || document.getElementById('filterCmp')?.value || '';

            if (typeof Swal !== 'undefined') {
                const res = await Swal.fire({
                    title: 'Hapus dari Tabel Rekap?',
                    html: `
                        <div style="text-align:left; font-size:12.5px; line-height:1.5;">
                            <p>Apakah Anda yakin ingin menghapus data dokumen ini dari tabel rekap lokal?</p>
                            <div style="background:rgba(255, 77, 79, 0.08); padding:8px 12px; border-radius:6px; border:1px solid rgba(255, 77, 79, 0.2); margin:10px 0; font-family:monospace; font-size:13px; font-weight:700; color:#cf1322;">
                                AJU: ${escapeHtml(aju)}
                            </div>
                            <div style="background:#fffbe6; border:1px solid #ffe58f; border-radius:6px; padding:10px; margin-top:12px; font-size:12px; color:#874d00; line-height:1.4;">
                                <strong>⚠️ Informasi Penting:</strong><br>
                                Tindakan ini <u>hanya menghapus data dari tabel rekap</u> ini, <strong>BUKAN</strong> menghapus dari portal CEISA 4.0.<br>
                                Untuk menghapus dokumen resmi, silakan lakukan langsung dari portal CEISA.
                            </div>
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ff4d4f',
                    cancelButtonColor: '#8c8c8c',
                    confirmButtonText: '🗑️ Ya, Hapus dari Rekap',
                    cancelButtonText: 'Batal',
                    reverseButtons: true
                });
                if (!res.isConfirmed) return;

                Swal.fire({
                    title: 'Menghapus Data Rekap...',
                    html: 'Menghapus data dokumen dari tabel rekap lokal...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
            } else {
                if (!confirm(`Hapus dokumen ${aju} dari tabel rekap ini?\n\nPerhatian: Ini hanya menghapus data dari tabel rekap, bukan dari portal CEISA 4.0. Untuk menghapus resmi, silakan lakukan langsung dari portal CEISA.`)) return;
            }

            try {
                const formData = new FormData();
                formData.append('nomor_aju', aju);
                formData.append('cmp_id', activeCmp);

                const response = await fetch('daftar_dokumen.php?action=hapus_dokumen', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.status === 'OK') {
                    if (typeof Swal !== 'undefined') {
                        await Swal.fire({
                            title: 'Berhasil Dihapus dari Rekap',
                            html: `
                                <div style="text-align:left; font-size:12.5px; line-height:1.5;">
                                    <p>Data dokumen <strong>${escapeHtml(aju)}</strong> berhasil dihapus dari tabel rekap lokal.</p>
                                    <div style="background:#f6ffed; border:1px solid #b7eb8f; border-radius:6px; padding:8px 12px; font-size:11.5px; color:#389e0d;">
                                        Dokumen di portal CEISA 4.0 tidak terpengaruh. Untuk menghapus secara resmi, silakan lakukan langsung melalui portal CEISA.
                                    </div>
                                </div>
                            `,
                            icon: 'success',
                            confirmButtonColor: '#52c41a'
                        });
                    } else {
                        alert(result.message || 'Dokumen berhasil dihapus dari rekap.');
                    }

                    // Hapus baris dari array lokal dan update tampilan tabel
                    ajuData = ajuData.filter(item => item.nomor_aju !== aju);
                    filteredData = filteredData.filter(item => item.nomor_aju !== aju);
                    selectedAju.delete(aju);
                    
                    const rowElem = document.getElementById(`row-${aju}`);
                    if (rowElem) {
                        rowElem.remove();
                    }
                    applyFilterAndSort();
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Gagal Menghapus Data Rekap',
                            html: `<div style="text-align:left; font-size:12px; color:#cf1322;">${escapeHtml(result.message || 'Gagal menghapus data dari tabel rekap.')}</div>`,
                            icon: 'error',
                            confirmButtonColor: '#1890ff'
                        });
                    } else {
                        alert('Gagal: ' + (result.message || 'Gagal menghapus'));
                    }
                }
            } catch (err) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Koneksi Gagal',
                        text: 'Terjadi kegagalan jaringan saat menghubungi backend: ' + err.message,
                        icon: 'error'
                    });
                } else {
                    alert('Koneksi Gagal: ' + err.message);
                }
            }
        }

        async function executeImportToH2H(aju, cmp) {
            const activeCmp = cmp || document.getElementById('filterCmp')?.value || '';
            if (!checkIdUserCeisaOrAlert(activeCmp, 'Import ke H2H', aju)) return;

            let confirmed = true;
            if (typeof Swal !== 'undefined') {
                const prompt = await Swal.fire({
                    title: 'Import ke Data H2H?',
                    html: `Dokumen <b>${escapeHtml(aju)}</b> beserta rincian kontainer, kemasan, dokumen lampiran, dan barang akan diimpor ke Data Host to Host.`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#1890ff',
                    cancelButtonColor: '#8c8c8c',
                    confirmButtonText: 'Ya, Import Sekarang',
                    cancelButtonText: 'Batal'
                });
                confirmed = prompt.isConfirmed;
            }
            if (!confirmed) return;

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Mengimpor ke H2H...',
                    html: `Sedang memproses dan mengimpor seluruh data dokumen <b>${escapeHtml(aju)}</b>...`,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            }

            try {
                const res = await fetch(`daftar_dokumen.php?action=import_to_h2h&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(activeCmp)}`);
                const json = await res.json();

                if (json.status === 'OK') {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil Diimpor!',
                            html: `Dokumen <b>${escapeHtml(aju)}</b> berhasil diimpor ke sistem Host to Host.<br><small style="color:#8c8c8c;">Silakan periksa dokumen ini di menu <b>Data Host to Host</b> atau <b>Form Editor</b>.</small>`,
                            confirmButtonColor: '#52c41a',
                            confirmButtonText: 'Selesai'
                        });
                    } else {
                        alert(`Dokumen ${aju} berhasil diimpor ke Data Host to Host!`);
                    }
                } else {
                    throw new Error(json.message || 'Gagal melakukan impor dokumen.');
                }
            } catch (err) {
                console.error('Import H2H error:', err);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Impor ke H2H',
                        text: err.message || 'Terjadi kesalahan sistem saat mengimpor dokumen.',
                        confirmButtonColor: '#ff4d4f'
                    });
                } else {
                    alert('Gagal impor: ' + (err.message || 'Error'));
                }
            }
        }

        // =========================================================================
        // ENGINE VIEWER JSON LNSW (report-parser/v1/lnsw/get-data-doc)
        // =========================================================================
        let activeJsonAju = '';
        let activeJsonIdHeader = '';
        let activeJsonCmp = '';
        let activeJsonRawText = '';

        function openJsonViewer(nomorAju, cmpId, idHeader) {
            const aju = nomorAju || activeActionAju || activeContextMenuAju;
            const cmp = cmpId || activeActionCmp || activeContextMenuCmp || document.getElementById('filterCmp')?.value || '';
            const idH = idHeader || activeActionIdHeader || activeContextMenuIdHeader;

            if (!checkIdUserCeisaOrAlert(cmp, 'Lihat JSON LNSW', aju)) return;

            activeJsonAju = aju;
            activeJsonCmp = cmp;
            activeJsonIdHeader = idH || '';
            activeJsonRawText = '';

            const modal = document.getElementById('modalJsonViewer');
            const pre = document.getElementById('jsonModalContent');
            const sub = document.getElementById('jsonModalSubtitle');
            const badge = document.getElementById('jsonModalStatusBadge');
            const sizeInfo = document.getElementById('jsonModalSizeInfo');

            if (modal) modal.style.display = 'flex';
            if (sub) sub.innerText = `No. AJU: ${aju || '-'} | idHeader: ${idH || '-'}`;
            if (badge) badge.innerHTML = `<span class="ant-tag ant-tag-orange">Mengambil data...</span>`;
            if (sizeInfo) sizeInfo.innerText = 'Memuat data dokumen...';
            if (pre) pre.textContent = '// Memuat data dokumen CEISA 4.0...\n// Mohon tunggu...';

            fetch(`daftar_dokumen.php?action=view_json_lnsw&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmp)}&id_header=${encodeURIComponent(idH || '')}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        activeJsonIdHeader = res.id_header || activeJsonIdHeader;
                        activeJsonRawText = res.raw_json || JSON.stringify(res.data, null, 2);
                        if (sub) sub.innerText = `No. AJU: ${aju} | idHeader: ${activeJsonIdHeader}`;
                        if (badge) badge.innerHTML = `<span class="ant-tag ant-tag-green">HTTP ${res.http_code || 200} OK</span>`;
                        if (sizeInfo) sizeInfo.innerText = `Ukuran: ${(new Blob([activeJsonRawText]).size / 1024).toFixed(2)} KB`;
                        if (pre) pre.textContent = activeJsonRawText;
                    } else {
                        activeJsonRawText = JSON.stringify(res, null, 2);
                        if (badge) badge.innerHTML = `<span class="ant-tag ant-tag-red">HTTP ${res.http_code || 400} ERROR</span>`;
                        if (sizeInfo) sizeInfo.innerText = res.message || 'Gagal memuat JSON';
                        if (pre) pre.textContent = activeJsonRawText;
                    }
                })
                .catch(err => {
                    activeJsonRawText = `// Error: ${err.message}`;
                    if (badge) badge.innerHTML = `<span class="ant-tag ant-tag-red">GAGAL KONEKSI</span>`;
                    if (sizeInfo) sizeInfo.innerText = err.message;
                    if (pre) pre.textContent = activeJsonRawText;
                });
        }

        function closeJsonModal() {
            const modal = document.getElementById('modalJsonViewer');
            if (modal) modal.style.display = 'none';
        }

        function copyJsonToClipboard() {
            if (!activeJsonRawText) return;
            navigator.clipboard.writeText(activeJsonRawText).then(() => {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'JSON disalin ke clipboard!',
                        showConfirmButton: false,
                        timer: 2000
                    });
                } else {
                    alert('JSON berhasil disalin ke clipboard!');
                }
            }).catch(err => {
                alert('Gagal menyalin: ' + err.message);
            });
        }

        function openJsonInNewTab() {
            if (!activeJsonAju && !activeJsonIdHeader) return;
            const url = `daftar_dokumen.php?action=view_json_lnsw&raw=1&nomor_aju=${encodeURIComponent(activeJsonAju)}&cmp_id=${encodeURIComponent(activeJsonCmp)}&id_header=${encodeURIComponent(activeJsonIdHeader)}`;
            window.open(url, '_blank');
        }

        // =========================================================================
        // ENGINE BATCH DOWNLOAD DENGAN PROTEKSI WAF & JEDA ACAK
        // =========================================================================
        async function startBatchDownloadExcel() {
            if (!selectedAju || selectedAju.size === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pilih Dokumen',
                    text: 'Silakan pilih / centang minimal 1 dokumen untuk diunduh.',
                    confirmButtonColor: '#1890ff'
                });
                return;
            }

            const ajus = Array.from(selectedAju);
            const total = ajus.length;

            const activeCmp = document.getElementById('filterCmp')?.value || initialCmp || '';
            if (!checkIdUserCeisaOrAlert(activeCmp, 'Unduh Excel Massal', ajus[0])) return;

            const confirmRes = await Swal.fire({
                icon: 'question',
                title: '<span style="font-size:15px; font-weight:700;">Unduh Excel Massal CEISA 4.0</span>',
                html: `
                    <div style="text-align:left; font-size:11.5px; line-height:1.5;">
                        <p>Anda akan mengunduh <b>${total} berkas Excel resmi</b> langsung dari server portal CEISA 4.0.</p>
                        <div style="background:rgba(24,144,255,0.08); border-left:3px solid #1890ff; padding:8px 12px; border-radius:4px; margin:10px 0; font-size:11px;">
                            ℹ️ <b>Proses Berurutan Otomatis:</b><br>
                            Sistem akan mengunduh berkas secara berurutan satu per satu dengan jeda aman antar dokumen untuk menjaga kestabilan koneksi dan kelancaran unduhan.
                        </div>
                        <p style="margin:0;">Mulai proses pengunduhan sekarang?</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '🚀 Mulai Unduh',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                reverseButtons: true
            });

            if (!confirmRes.isConfirmed) return;

            batchDownloadCancelled = false;
            batchDownloadPaused = false;
            batchSuccessfulAjus = [];

            // Buka Modal Progress Batch
            const modal = document.getElementById('modalBatchProgress');
            if (modal) modal.style.display = 'flex';

            const mTitle = document.getElementById('batchModalTitle');
            if (mTitle) mTitle.innerHTML = '📥 Unduh Excel Massal CEISA 4.0';
            const mSubtitle = document.getElementById('batchModalSubtitle');
            if (mSubtitle) mSubtitle.innerHTML = 'Proses Sekuensial Otomatis (Jeda Aman Antar Dokumen)';

            document.getElementById('batchModalProgressBar').style.width = '0%';
            document.getElementById('batchModalPercent').innerText = '0%';
            document.getElementById('batchCountSuccess').innerText = '0';
            document.getElementById('batchCountFailed').innerText = '0';
            document.getElementById('batchModalLogList').innerHTML = '';
            document.getElementById('btnBatchPauseResume').style.display = 'inline-block';
            document.getElementById('btnBatchPauseResume').innerHTML = '⏸️ Jeda';
            document.getElementById('btnBatchCancel').style.display = 'inline-block';
            document.getElementById('btnBatchDownloadZip').style.display = 'none';
            document.getElementById('btnBatchClose').style.display = 'none';
            document.getElementById('batchSafetySpinner').style.display = 'inline-block';

            let successCount = 0;
            let failedCount = 0;
            const failedAjus = [];

            for (let i = 0; i < ajus.length; i++) {
                if (batchDownloadCancelled) {
                    document.getElementById('batchModalStatusText').innerHTML = '<span style="color:#cf1322;">⚠️ Proses dibatalkan oleh pengguna.</span>';
                    document.getElementById('batchModalSafetyTimer').innerText = 'Proses dihentikan.';
                    document.getElementById('batchSafetySpinner').style.display = 'none';
                    break;
                }

                while (batchDownloadPaused && !batchDownloadCancelled) {
                    document.getElementById('batchModalStatusText').innerText = `⏸️ Pengunduhan dijeda (${i}/${total})...`;
                    document.getElementById('batchModalSafetyTimer').innerText = 'Klik "▶️ Lanjutkan" untuk melanjutkan.';
                    document.getElementById('batchSafetySpinner').style.display = 'none';
                    await new Promise(r => setTimeout(r, 400));
                }

                const aju = ajus[i];
                const num = i + 1;
                const pct = Math.round(((num - 1) / total) * 100);

                document.getElementById('batchModalProgressBar').style.width = `${pct}%`;
                document.getElementById('batchModalPercent').innerText = `${pct}%`;
                document.getElementById('batchModalStatusText').innerHTML = `[${num}/${total}] Mengunduh: <b style="color:var(--primary); font-family:monospace;">${aju}</b>...`;
                document.getElementById('batchSafetySpinner').style.display = 'inline-block';
                document.getElementById('batchModalSafetyTimer').innerText = `Menghubungi API excel-service CEISA 4.0...`;

                try {
                    const cmpId = document.getElementById('filterCmp')?.value || '';
                    const res = await fetch(`download_ceisa_excel.php?nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmpId)}&format=json`);
                    const json = await res.json();

                    if (json.status === 'OK') {
                        successCount++;
                        batchSuccessfulAjus.push(aju);
                        document.getElementById('batchCountSuccess').innerText = successCount;
                        appendBatchLog(num, aju, true, json.cached ? 'Tersedia di Cache (0ms)' : `${(json.size/1024).toFixed(1)} KB`);
                    } else {
                        failedCount++;
                        failedAjus.push({ aju, message: json.message || 'Gagal' });
                        document.getElementById('batchCountFailed').innerText = failedCount;
                        appendBatchLog(num, aju, false, json.message || 'Gagal');
                    }
                } catch (err) {
                    failedCount++;
                    failedAjus.push({ aju, message: err.message });
                    document.getElementById('batchCountFailed').innerText = failedCount;
                    appendBatchLog(num, aju, false, err.message);
                }

                const currentPct = Math.round((num / total) * 100);
                document.getElementById('batchModalProgressBar').style.width = `${currentPct}%`;
                document.getElementById('batchModalPercent').innerText = `${currentPct}%`;

                // Jika bukan item terakhir dan belum dibatalkan, lakukan jeda acak WAF Evasion
                if (i < ajus.length - 1 && !batchDownloadCancelled) {
                    if (num % 50 === 0) {
                        const coolingMs = Math.floor(30000 + Math.random() * 30000); // 30s - 60s acak
                        await countdownWafSafety(coolingMs, true);
                    } else {
                        const delayMs = Math.floor(2500 + Math.random() * 2500); // 2.5s - 5.0s
                        await countdownWafSafety(delayMs);
                    }
                }
            }

            // Selesai loop
            document.getElementById('batchSafetySpinner').style.display = 'none';
            document.getElementById('btnBatchPauseResume').style.display = 'none';
            document.getElementById('btnBatchCancel').style.display = 'none';
            document.getElementById('btnBatchClose').style.display = 'inline-block';

            if (!batchDownloadCancelled) {
                document.getElementById('batchModalProgressBar').style.width = '100%';
                document.getElementById('batchModalPercent').innerText = '100%';
                document.getElementById('batchModalStatusText').innerHTML = `<span style="color:#52c41a; font-weight:700;">✓ Pengunduhan selesai! (${successCount} sukses, ${failedCount} gagal)</span>`;
                document.getElementById('batchModalSafetyTimer').innerText = `Semua berkas telah selesai diunduh.`;

                if (batchSuccessfulAjus.length > 1) {
                    const btnZip = document.getElementById('btnBatchDownloadZip');
                    if (btnZip) {
                        btnZip.style.display = 'inline-block';
                        btnZip.innerHTML = `📦 Unduh Arsip ZIP (${batchSuccessfulAjus.length})`;
                    }
                    // Otomatis download bundle ZIP
                    triggerZipDownload();
                } else if (batchSuccessfulAjus.length === 1) {
                    downloadSingleCeisaExcel(batchSuccessfulAjus[0]);
                }
            }
        }

        async function countdownWafSafety(delayMs, isCooling = false) {
            const startTime = Date.now();
            const endTime = startTime + delayMs;
            const timerEl = document.getElementById('batchModalSafetyTimer');

            while (Date.now() < endTime && !batchDownloadCancelled) {
                if (batchDownloadPaused) {
                    await new Promise(r => setTimeout(r, 200));
                    continue;
                }
                const remaining = Math.max(0, ((endTime - Date.now()) / 1000).toFixed(1));
                if (timerEl) {
                    if (isCooling) {
                        timerEl.innerHTML = `❄️ <b style="color:#1890ff;">WAF Cooling Break:</b> Istirahat sejenak <b>${remaining} detik</b> agar IP aman dari blokir WAF Bea Cukai...`;
                    } else {
                        timerEl.innerHTML = `⏳ Jeda aman WAF: Mempersiapkan dokumen berikutnya dalam <b>${remaining} detik</b>...`;
                    }
                }
                await new Promise(r => setTimeout(r, 100));
            }
        }

        function appendBatchLog(num, aju, isSuccess, info) {
            const logList = document.getElementById('batchModalLogList');
            if (!logList) return;
            const icon = isSuccess ? '✓' : '✕';
            const color = isSuccess ? '#52c41a' : '#ff4d4f';
            const item = document.createElement('div');
            item.style.padding = '3px 0';
            item.style.borderBottom = '1px solid rgba(255,255,255,0.04)';
            item.style.display = 'flex';
            item.style.justifyContent = 'space-between';
            item.innerHTML = `
                <span><span style="color:${color}; font-weight:bold;">${icon}</span> [${num}] <b>${escapeHtml(aju)}</b></span>
                <span style="color:var(--text-muted); font-size:9.5px;">${escapeHtml(info)}</span>
            `;
            logList.appendChild(item);
            logList.scrollTop = logList.scrollHeight;
        }

        function togglePauseBatchDownload() {
            batchDownloadPaused = !batchDownloadPaused;
            const btn = document.getElementById('btnBatchPauseResume');
            if (btn) {
                btn.innerHTML = batchDownloadPaused ? '▶️ Lanjutkan' : '⏸️ Jeda';
            }
        }

        function cancelBatchDownload() {
            if (batchDownloadCancelled) {
                closeBatchModal();
                return;
            }
            batchDownloadCancelled = true;
            batchDownloadPaused = false;
        }

        function closeBatchModal() {
            const modal = document.getElementById('modalBatchProgress');
            if (modal) modal.style.display = 'none';
        }

        function triggerZipDownload() {
            if (!batchSuccessfulAjus || batchSuccessfulAjus.length === 0) return;
            const url = `download_ceisa_excel.php?action=bundle_zip&nomor_ajus=${encodeURIComponent(batchSuccessfulAjus.join(','))}`;
            window.location.href = url;
        }

        // =========================================================================
        // ENGINE BATCH IMPORT KE DATA H2H DENGAN PROTEKSI WAF & JEDA AMAN
        // =========================================================================
        async function startBatchImportH2H() {
            if (!selectedAju || selectedAju.size === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pilih Dokumen',
                    text: 'Silakan pilih / centang minimal 1 dokumen untuk diimpor ke Data Host to Host.',
                    confirmButtonColor: '#1890ff'
                });
                return;
            }

            const ajus = Array.from(selectedAju);
            const total = ajus.length;

            const activeCmp = document.getElementById('filterCmp')?.value || initialCmp || '';
            if (!checkIdUserCeisaOrAlert(activeCmp, 'Import Massal ke H2H', ajus[0])) return;

            let warningNotice = '';
            if (total > 200) {
                warningNotice = `
                    <div style="background:#fffbe6; border:1px solid #ffe58f; border-radius:4px; padding:8px 12px; margin:8px 0; font-size:11px; color:#ad6800; line-height:1.4;">
                        ⚠️ <b>Perhatian Volume Sangat Besar (${total} Dokumen):</b><br>
                        Sistem telah dilengkapi <b>WAF Cooling Break otomatis</b> (istirahat acak 30 detik s/d 1 menit setiap 50 dokumen). Namun untuk keamanan optimal, tetap disarankan mengimpor bertahap per bulan / per batch.
                    </div>
                `;
            }

            const confirmRes = await Swal.fire({
                icon: total > 200 ? 'warning' : 'question',
                title: '<span style="font-size:15px; font-weight:700;">Import Massal ke Host to Host</span>',
                html: `
                    <div style="text-align:left; font-size:11.5px; line-height:1.5;">
                        <p>Anda akan mengimpor <b>${total} dokumen CEISA 4.0</b> ke tabel Staging Host to Host.</p>
                        ${warningNotice}
                        <div style="background:var(--bg-card); border:1px solid var(--border-card); border-radius:6px; padding:10px 12px; margin:12px 0;">
                            <div style="font-weight:700; margin-bottom:8px; color:var(--text-heading); font-size:11.5px; text-transform:uppercase;">Pilih Jalur &amp; Kecepatan Import:</div>
                            <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; margin-bottom:8px; background:rgba(24,144,255,0.06); padding:8px 10px; border-radius:4px; border:1px solid rgba(24,144,255,0.25);">
                                <input type="radio" name="swal_import_mode" value="json" checked style="margin-top:2px;">
                                <div>
                                    <strong style="color:#1890ff; font-size:12px;">⚡ Versi CEPAT (JSON LNSW) — Sangat Disarankan</strong>
                                    <div style="font-size:10.5px; color:var(--text-muted); line-height:1.35; margin-top:2px;">Respon instan (~0.5s/dokumen), beban gateway Bea Cukai sangat ringan, transfer data teks murni anti-timeout.</div>
                                </div>
                            </label>
                            <label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer; background:rgba(82,196,26,0.06); padding:8px 10px; border-radius:4px; border:1px solid rgba(82,196,26,0.25);">
                                <input type="radio" name="swal_import_mode" value="excel" style="margin-top:2px;">
                                <div>
                                    <strong style="color:#389e0d; font-size:12px;">📑 Versi LENGKAP (Official Excel 21 Sheet)</strong>
                                    <div style="font-size:10.5px; color:var(--text-muted); line-height:1.35; margin-top:2px;">Mengunduh file Excel resmi 21 sheet (.xlsx), pemrosesan lebih detail (~2-3s/dokumen). Lebih cocok untuk batch kecil.</div>
                                </div>
                            </label>
                        </div>
                        <div style="background:rgba(24,144,255,0.08); border-left:3px solid #1890ff; padding:8px 12px; border-radius:4px; margin:10px 0; font-size:11px;">
                            ❄️ <b>Proteksi Anti-WAF:</b> Otomatis istirahat (Cooling Break) acak 30 detik s/d 1 menit setiap 50 dokumen dan Circuit Breaker auto-pause jika terjadi kegagalan beruntun.
                        </div>
                        <p style="margin:0;">Mulai proses impor massal sekarang?</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '⚡ Mulai Import',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                reverseButtons: true,
                preConfirm: () => {
                    const sel = document.querySelector('input[name="swal_import_mode"]:checked');
                    return sel ? sel.value : 'json';
                }
            });

            if (!confirmRes.isConfirmed) return;
            const selectedMode = confirmRes.value || 'json';

            batchDownloadCancelled = false;
            batchDownloadPaused = false;
            batchSuccessfulAjus = [];

            // Buka Modal Progress Batch dan sesuaikan judulnya
            const modal = document.getElementById('modalBatchProgress');
            if (modal) modal.style.display = 'flex';

            const modeTitle = (selectedMode === 'json') ? '⚡ Import Massal H2H (Versi Cepat JSON)' : '📑 Import Massal H2H (Versi Lengkap Excel)';
            const mTitle = document.getElementById('batchModalTitle');
            if (mTitle) mTitle.innerHTML = modeTitle;
            const mSubtitle = document.getElementById('batchModalSubtitle');
            if (mSubtitle) mSubtitle.innerHTML = `Mode: ${selectedMode === 'json' ? 'JSON LNSW (Cepat)' : 'Official Excel 21 Sheet'} • Cooling Break Acak 30s-1m Tiap 50 Dokumen Aktif`;

            document.getElementById('batchModalProgressBar').style.width = '0%';
            document.getElementById('batchModalPercent').innerText = '0%';
            document.getElementById('batchCountSuccess').innerText = '0';
            document.getElementById('batchCountFailed').innerText = '0';
            document.getElementById('batchModalLogList').innerHTML = '';
            document.getElementById('btnBatchPauseResume').style.display = 'inline-block';
            document.getElementById('btnBatchPauseResume').innerHTML = '⏸️ Jeda';
            document.getElementById('btnBatchCancel').style.display = 'inline-block';
            document.getElementById('btnBatchDownloadZip').style.display = 'none';
            document.getElementById('btnBatchClose').style.display = 'none';
            document.getElementById('batchSafetySpinner').style.display = 'inline-block';

            let successCount = 0;
            let failedCount = 0;
            let consecutiveFailures = 0;
            const failedAjus = [];

            for (let i = 0; i < ajus.length; i++) {
                if (batchDownloadCancelled) {
                    document.getElementById('batchModalStatusText').innerHTML = '<span style="color:#cf1322;">⚠️ Proses dibatalkan oleh pengguna.</span>';
                    document.getElementById('batchModalSafetyTimer').innerText = 'Proses impor dihentikan.';
                    document.getElementById('batchSafetySpinner').style.display = 'none';
                    break;
                }

                while (batchDownloadPaused && !batchDownloadCancelled) {
                    document.getElementById('batchModalStatusText').innerText = `⏸️ Proses impor dijeda (${i}/${total})...`;
                    document.getElementById('batchModalSafetyTimer').innerText = 'Klik "▶️ Lanjutkan" untuk melanjutkan.';
                    document.getElementById('batchSafetySpinner').style.display = 'none';
                    await new Promise(r => setTimeout(r, 400));
                }

                const aju = ajus[i];
                const num = i + 1;
                const pct = Math.round(((num - 1) / total) * 100);

                document.getElementById('batchModalProgressBar').style.width = `${pct}%`;
                document.getElementById('batchModalPercent').innerText = `${pct}%`;
                document.getElementById('batchModalStatusText').innerHTML = `[${num}/${total}] Mengimpor (${selectedMode.toUpperCase()}): <b style="color:var(--primary); font-family:monospace;">${aju}</b>...`;
                document.getElementById('batchSafetySpinner').style.display = 'inline-block';
                document.getElementById('batchModalSafetyTimer').innerText = `Mengambil data dari gateway LNSW CEISA 4.0 & staging H2H...`;

                try {
                    const cmpId = document.getElementById('filterCmp')?.value || '';
                    const res = await fetch(`daftar_dokumen.php?action=import_to_h2h&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(cmpId)}&mode=${encodeURIComponent(selectedMode)}`);
                    const json = await res.json();

                    if (json.status === 'OK') {
                        successCount++;
                        consecutiveFailures = 0;
                        batchSuccessfulAjus.push(aju);
                        document.getElementById('batchCountSuccess').innerText = successCount;
                        const infoMsg = json.source ? `Berhasil (${json.source})` : 'Berhasil Masuk H2H';
                        appendBatchLog(num, aju, true, infoMsg);
                    } else {
                        failedCount++;
                        consecutiveFailures++;
                        failedAjus.push({ aju, message: json.message || 'Gagal' });
                        document.getElementById('batchCountFailed').innerText = failedCount;
                        appendBatchLog(num, aju, false, json.message || 'Gagal');
                    }
                } catch (err) {
                    failedCount++;
                    consecutiveFailures++;
                    failedAjus.push({ aju, message: err.message });
                    document.getElementById('batchCountFailed').innerText = failedCount;
                    appendBatchLog(num, aju, false, err.message);
                }

                // Proteksi Circuit Breaker: Jika gagal 5 kali berturut-turut, otomatis jeda
                if (consecutiveFailures >= 5 && !batchDownloadCancelled) {
                    batchDownloadPaused = true;
                    document.getElementById('btnBatchPauseResume').innerHTML = '▶️ Lanjutkan';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'warning',
                            title: '🛡️ Proteksi WAF Diaktifkan (Auto-Pause)',
                            html: 'Terdeteksi <b>5 kegagalan beruntun</b>. Sistem otomatis menjeda proses untuk mencegah pemblokiran IP oleh WAF Bea Cukai atau token MFA yang expired.<br><br>Periksa token MFA / jaringan Anda, lalu klik "▶️ Lanjutkan" jika sudah siap.',
                            confirmButtonColor: '#1890ff'
                        });
                    }
                }

                const currentPct = Math.round((num / total) * 100);
                document.getElementById('batchModalProgressBar').style.width = `${currentPct}%`;
                document.getElementById('batchModalPercent').innerText = `${currentPct}%`;

                // Jika bukan item terakhir dan belum dibatalkan:
                if (i < ajus.length - 1 && !batchDownloadCancelled) {
                    // Setiap 50 dokumen, lakukan Cooling Break acak antara 30 detik s/d 60 detik (30s - 1 menit)
                    if (num % 50 === 0) {
                        const coolingMs = Math.floor(30000 + Math.random() * 30000); // 30s s/d 60s acak
                        await countdownWafSafety(coolingMs, true);
                    } else {
                        // Jeda acak: mode JSON (1.8s - 3.5s), mode Excel (3.0s - 5.5s)
                        const minDelay = (selectedMode === 'json') ? 1800 : 3000;
                        const maxRandom = (selectedMode === 'json') ? 1700 : 2500;
                        const delayMs = Math.floor(minDelay + Math.random() * maxRandom);
                        await countdownWafSafety(delayMs, false);
                    }
                }
            }

            // Selesai loop
            document.getElementById('batchSafetySpinner').style.display = 'none';
            document.getElementById('btnBatchPauseResume').style.display = 'none';
            document.getElementById('btnBatchCancel').style.display = 'none';
            document.getElementById('btnBatchClose').style.display = 'inline-block';

            if (!batchDownloadCancelled) {
                document.getElementById('batchModalProgressBar').style.width = '100%';
                document.getElementById('batchModalPercent').innerText = '100%';
                document.getElementById('batchModalStatusText').innerHTML = `<span style="color:#52c41a; font-weight:700;">✓ Impor Massal Selesai! (${successCount} sukses, ${failedCount} gagal)</span>`;
                document.getElementById('batchModalSafetyTimer').innerText = `Semua dokumen terpilih telah diproses dan disimpan ke tabel Staging Host to Host.`;
            }
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

        // =========================================================================
        // REKAP DOKUMEN BELUM MASUK IT INVENTORY PER BULAN
        // =========================================================================
        function getDocMonth(r) {
            // 1. Tanggal pendaftaran resmi (YYYY-MM-DD atau DD-MM-YYYY)
            if (r.tanggal_daftar && r.tanggal_daftar !== '-') {
                const s = String(r.tanggal_daftar).trim();
                const mYmd = s.match(/^(\d{4})[-/](\d{2})/);
                if (mYmd) return mYmd[1] + '-' + mYmd[2];
                const mDmy = s.match(/^\d{2}[-/](\d{2})[-/](\d{4})/);
                if (mDmy) return mDmy[2] + '-' + mDmy[1];
            }
            // 2. Tanggal respon dokumen / status resmi
            if (r.bcd_tanggalRespon && r.bcd_tanggalRespon !== '-') {
                const s = String(r.bcd_tanggalRespon).trim();
                const mYmd = s.match(/^(\d{4})[-/](\d{2})/);
                if (mYmd) return mYmd[1] + '-' + mYmd[2];
                const mDmy = s.match(/^\d{2}[-/](\d{2})[-/](\d{4})/);
                if (mDmy) return mDmy[2] + '-' + mDmy[1];
            }
            // 3. Waktu update / log
            if (r.upd_time && r.upd_time !== '-') {
                const s = String(r.upd_time).trim();
                const mYmd = s.match(/^(\d{4})[-/](\d{2})/);
                if (mYmd) return mYmd[1] + '-' + mYmd[2];
                const mDmy = s.match(/^\d{2}[-/](\d{2})[-/](\d{4})/);
                if (mDmy) return mDmy[2] + '-' + mDmy[1];
            }
            // 4. Ekstrak tanggal dari Nomor AJU jika metadata tanggal di atas kosong
            if (r.nomor_aju) {
                const clean = String(r.nomor_aju).replace(/[^A-Za-z0-9]/g, '');
                if (clean.length >= 20) {
                    // Pola 1: Standar Dokumen BC (posisi indeks 12..20 adalah YYYYMMDD)
                    const subBc = clean.substring(12, 20);
                    const mBc = subBc.match(/^(20\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])$/);
                    if (mBc) {
                        return mBc[1] + '-' + mBc[2];
                    }
                    // Pola 2: Dokumen PPKEK / INSW (posisi 26 char: digit ke-14..21 adalah DDMMYYYY)
                    if (clean.length === 26) {
                        const subPpkek = clean.slice(-13, -5);
                        const mPpkek = subPpkek.match(/^(0[1-9]|[12]\d|3[01])(0[1-9]|1[0-2])(20\d{2})$/);
                        if (mPpkek) {
                            return mPpkek[3] + '-' + mPpkek[2];
                        }
                    }
                    // Pola 3: Pencarian fleksibel YYYYMMDD di seluruh string AJU
                    const mAnyYmd = clean.match(/(20\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])/);
                    if (mAnyYmd) {
                        return mAnyYmd[1] + '-' + mAnyYmd[2];
                    }
                    // Pola 4: Pencarian fleksibel DDMMYYYY di seluruh string AJU
                    const mAnyDmy = clean.match(/(0[1-9]|[12]\d|3[01])(0[1-9]|1[0-2])(20\d{2})/);
                    if (mAnyDmy) {
                        return mAnyDmy[3] + '-' + mAnyDmy[2];
                    }
                }
            }
            return 'Lainnya';
        }

        function computeRekapNotInv() {
            const monthMap = {};
            let totalNotInvAll = 0;

            ajuData.forEach(r => {
                const isInv = parseInt(r.is_inv !== undefined ? r.is_inv : 1, 10);
                if (isInv !== 1) return;

                const m = getDocMonth(r);
                if (!monthMap[m]) {
                    monthMap[m] = { month: m, notInv: 0, total: 0 };
                }
                monthMap[m].total++;

                const inItInv = parseInt(r.in_it_inv || 0, 10) > 0;
                if (!inItInv) {
                    monthMap[m].notInv++;
                    totalNotInvAll++;
                }
            });

            const list = Object.values(monthMap).filter(item => item.notInv > 0);
            return { list, totalNotInvAll };
        }

        function toggleRekapNotInv(event) {
            if (event) {
                event.stopPropagation();
                event.preventDefault();
            }
            const widget = document.getElementById('rekapNotInvWidget');
            if (!widget) return;

            if (widget.style.display === 'none' || widget.style.display === '') {
                const chip = document.getElementById('kpiChipNotInv');
                if (chip && !widget.dataset.dragged) {
                    const rect = chip.getBoundingClientRect();
                    widget.style.top = (rect.bottom + 6) + 'px';
                    const maxLeft = window.innerWidth - 390;
                    const leftPos = Math.max(10, Math.min(maxLeft, rect.left));
                    widget.style.left = leftPos + 'px';
                }
                widget.style.display = 'block';
                renderRekapNotInvTable();
            } else {
                widget.style.display = 'none';
            }
        }

        function closeRekapNotInv(event) {
            if (event) event.stopPropagation();
            const widget = document.getElementById('rekapNotInvWidget');
            if (widget) widget.style.display = 'none';
        }

        function renderRekapNotInvTable() {
            const tbody = document.getElementById('rekapNotInvTbody');
            const totalBadge = document.getElementById('badgeRekapTotal');
            if (!tbody) return;

            const { list, totalNotInvAll } = computeRekapNotInv();

            if (totalBadge) {
                totalBadge.innerText = totalNotInvAll.toLocaleString('id-ID');
            }

            const sortType = document.getElementById('sortRekapNotInv')?.value || 'month_desc';
            list.sort((a, b) => {
                if (sortType === 'month_desc') return b.month.localeCompare(a.month);
                if (sortType === 'month_asc') return a.month.localeCompare(b.month);
                if (sortType === 'count_desc') return b.notInv - a.notInv;
                if (sortType === 'count_asc') return a.notInv - b.notInv;
                return 0;
            });

            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:#8c8c8c; font-size:11px;">✓ Semua dokumen sudah masuk IT Inventory!</td></tr>`;
                updateRekapMonthHighlight();
                return;
            }

            let html = '';
            list.forEach((item, idx) => {
                const isActive = currentMonthFilter === item.month;
                const ratio = item.total > 0 ? ((item.notInv / item.total) * 100).toFixed(1) + '%' : '0.0%';
                const tooltip = `${item.notInv.toLocaleString('id-ID')} dari ${item.total.toLocaleString('id-ID')} dokumen pada periode ${item.month} belum masuk IT Inventory`;
                html += `
                    <tr class="rekap-notinv-row ${isActive ? 'active-month' : ''}" data-month="${escapeHtml(item.month)}" onclick="filterByRekapMonth(event, '${escapeHtml(item.month)}')" title="${tooltip}">
                        <td style="text-align:center; color:#8c8c8c; font-size:10.5px;">${idx + 1}</td>
                        <td style="font-weight:700;">${escapeHtml(item.month)}</td>
                        <td style="text-align:center;">
                            <span class="badge-count-notinv">${item.notInv.toLocaleString('id-ID')}</span>
                        </td>
                        <td style="text-align:right; color:#8c8c8c; font-size:11px; padding-right:12px;">${ratio}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            updateRekapMonthHighlight();
        }

        function filterByRekapMonth(event, month) {
            if (event) event.stopPropagation();

            if (currentMonthFilter === month && month !== '') {
                currentMonthFilter = '';
            } else {
                currentMonthFilter = month;
                if (month !== '') {
                    currentCardFilter = 'not_inv';
                    updateCardFilterUI();
                }
            }

            updateRekapMonthHighlight();
            currentPage = 1;
            filterClientTable();
        }

        function updateRekapMonthHighlight() {
            const rows = document.querySelectorAll('.rekap-notinv-row');
            rows.forEach(tr => {
                if (currentMonthFilter && tr.dataset.month === currentMonthFilter) {
                    tr.classList.add('active-month');
                } else {
                    tr.classList.remove('active-month');
                }
            });

            const allBtn = document.querySelector('.btn-rekap-all');
            if (allBtn) {
                if (!currentMonthFilter) {
                    allBtn.style.borderColor = '#ff4d4f';
                    allBtn.style.color = '#cf1322';
                    allBtn.style.fontWeight = '700';
                } else {
                    allBtn.style.borderColor = '#d9d9d9';
                    allBtn.style.color = '#595959';
                    allBtn.style.fontWeight = '600';
                }
            }

            const badgeActive = document.getElementById('badgeActiveMonth');
            if (badgeActive) {
                if (currentMonthFilter) {
                    badgeActive.style.display = 'inline-block';
                    badgeActive.innerText = currentMonthFilter + ' ✕';
                } else {
                    badgeActive.style.display = 'none';
                    badgeActive.innerText = '';
                }
            }
        }

        function initRekapNotInvDraggable() {
            const widget = document.getElementById('rekapNotInvWidget');
            const header = document.getElementById('rekapNotInvHeader');
            if (!widget || !header) return;

            let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
            header.onmousedown = function(e) {
                if (e.target.closest('button') || e.target.closest('select')) return;
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;
                document.onmouseup = closeDragElement;
                document.onmousemove = elementDrag;
            };

            function elementDrag(e) {
                e.preventDefault();
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;
                widget.style.top = (widget.offsetTop - pos2) + "px";
                widget.style.left = (widget.offsetLeft - pos1) + "px";
                widget.dataset.dragged = '1';
            }

            function closeDragElement() {
                document.onmouseup = null;
                document.onmousemove = null;
            }

            // Menutup widget saat klik di luar
            document.addEventListener('click', function(e) {
                if (widget && widget.style.display !== 'none') {
                    const btn = document.getElementById('btnRekapNotInv');
                    if (!widget.contains(e.target) && (!btn || !btn.contains(e.target))) {
                        widget.style.display = 'none';
                    }
                }
            });
        }

        // =========================================================================
        // REKAP DOKUMEN BELUM IMPORT KE H2H PER BULAN
        // =========================================================================
        function computeRekapNotH2H() {
            const monthMap = {};
            let totalNotH2HAll = 0;

            ajuData.forEach(r => {
                const m = getDocMonth(r);
                if (!monthMap[m]) {
                    monthMap[m] = { month: m, notH2H: 0, total: 0 };
                }
                monthMap[m].total++;

                const inStaging = parseInt(r.in_staging || 0, 10) > 0;
                if (!inStaging) {
                    monthMap[m].notH2H++;
                    totalNotH2HAll++;
                }
            });

            const list = Object.values(monthMap).filter(item => item.notH2H > 0);
            return { list, totalNotH2HAll };
        }

        function toggleRekapNotH2H(event) {
            if (event) {
                event.stopPropagation();
                event.preventDefault();
            }
            const widget = document.getElementById('rekapNotH2HWidget');
            if (!widget) return;

            if (widget.style.display === 'none' || widget.style.display === '') {
                const chip = document.getElementById('kpiChipNotH2H');
                if (chip && !widget.dataset.dragged) {
                    const rect = chip.getBoundingClientRect();
                    widget.style.top = (rect.bottom + 6) + 'px';
                    const maxLeft = window.innerWidth - 390;
                    const leftPos = Math.max(10, Math.min(maxLeft, rect.left));
                    widget.style.left = leftPos + 'px';
                }
                widget.style.display = 'block';
                renderRekapNotH2HTable();
            } else {
                widget.style.display = 'none';
            }
        }

        function closeRekapNotH2H(event) {
            if (event) event.stopPropagation();
            const widget = document.getElementById('rekapNotH2HWidget');
            if (widget) widget.style.display = 'none';
        }

        function renderRekapNotH2HTable() {
            const tbody = document.getElementById('rekapNotH2HTbody');
            const totalBadge = document.getElementById('badgeRekapTotalH2H');
            if (!tbody) return;

            const { list, totalNotH2HAll } = computeRekapNotH2H();

            if (totalBadge) {
                totalBadge.innerText = totalNotH2HAll.toLocaleString('id-ID');
            }

            const sortType = document.getElementById('sortRekapNotH2H')?.value || 'month_desc';
            list.sort((a, b) => {
                if (sortType === 'month_desc') return b.month.localeCompare(a.month);
                if (sortType === 'month_asc') return a.month.localeCompare(b.month);
                if (sortType === 'count_desc') return b.notH2H - a.notH2H;
                if (sortType === 'count_asc') return a.notH2H - b.notH2H;
                return 0;
            });

            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:#8c8c8c; font-size:11px;">✓ Semua dokumen sudah diimport ke H2H!</td></tr>`;
                updateRekapMonthHighlightH2H();
                return;
            }

            let html = '';
            list.forEach((item, idx) => {
                const isActive = currentMonthFilterH2H === item.month;
                const ratio = item.total > 0 ? ((item.notH2H / item.total) * 100).toFixed(1) + '%' : '0.0%';
                const tooltip = `${item.notH2H.toLocaleString('id-ID')} dari ${item.total.toLocaleString('id-ID')} dokumen pada periode ${item.month} belum diimport H2H`;
                html += `
                    <tr class="rekap-notinv-row ${isActive ? 'active-month' : ''}" data-month="${escapeHtml(item.month)}" onclick="filterByRekapMonthH2H(event, '${escapeHtml(item.month)}')" title="${tooltip}">
                        <td style="text-align:center; color:#8c8c8c; font-size:10.5px;">${idx + 1}</td>
                        <td style="font-weight:700;">${escapeHtml(item.month)}</td>
                        <td style="text-align:center;">
                            <span class="badge-count-notinv" style="background:#fff7e6; color:#d46b08; border-color:#ffd591;">${item.notH2H.toLocaleString('id-ID')}</span>
                        </td>
                        <td style="text-align:right; color:#8c8c8c; font-size:11px; padding-right:12px;">${ratio}</td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            updateRekapMonthHighlightH2H();
        }

        function filterByRekapMonthH2H(event, month) {
            if (event) event.stopPropagation();

            if (currentMonthFilterH2H === month && month !== '') {
                currentMonthFilterH2H = '';
            } else {
                currentMonthFilterH2H = month;
                if (month !== '') {
                    currentCardFilter = 'not_h2h';
                    updateCardFilterUI();
                }
            }

            updateRekapMonthHighlightH2H();
            currentPage = 1;
            filterClientTable();
            updateBtnQuickSelectNotH2H();
        }

        function updateRekapMonthHighlightH2H() {
            const tbody = document.getElementById('rekapNotH2HTbody');
            if (tbody) {
                const rows = tbody.querySelectorAll('.rekap-notinv-row');
                rows.forEach(tr => {
                    if (currentMonthFilterH2H && tr.dataset.month === currentMonthFilterH2H) {
                        tr.classList.add('active-month');
                    } else {
                        tr.classList.remove('active-month');
                    }
                });
            }

            const allBtn = document.querySelector('#rekapNotH2HWidget .btn-rekap-all');
            if (allBtn) {
                if (!currentMonthFilterH2H) {
                    allBtn.style.borderColor = '#fa8c16';
                    allBtn.style.color = '#d46b08';
                    allBtn.style.fontWeight = '700';
                } else {
                    allBtn.style.borderColor = '#d9d9d9';
                    allBtn.style.color = '#595959';
                    allBtn.style.fontWeight = '600';
                }
            }

            const badgeActive = document.getElementById('badgeActiveMonthH2H');
            if (badgeActive) {
                if (currentMonthFilterH2H) {
                    badgeActive.style.display = 'inline-block';
                    badgeActive.innerText = currentMonthFilterH2H + ' ✕';
                } else {
                    badgeActive.style.display = 'none';
                    badgeActive.innerText = '';
                }
            }
        }

        function initRekapNotH2HDraggable() {
            const widget = document.getElementById('rekapNotH2HWidget');
            const header = document.getElementById('rekapNotH2HHeader');
            if (!widget || !header) return;

            let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
            header.onmousedown = function(e) {
                if (e.target.closest('button') || e.target.closest('select')) return;
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;
                document.onmouseup = closeDragElement;
                document.onmousemove = elementDrag;
            };

            function elementDrag(e) {
                e.preventDefault();
                pos1 = pos3 - e.clientX;
                pos2 = pos4 - e.clientY;
                pos3 = e.clientX;
                pos4 = e.clientY;
                widget.style.top = (widget.offsetTop - pos2) + "px";
                widget.style.left = (widget.offsetLeft - pos1) + "px";
                widget.dataset.dragged = '1';
            }

            function closeDragElement() {
                document.onmouseup = null;
                document.onmousemove = null;
            }

            // Menutup widget saat klik di luar
            document.addEventListener('click', function(e) {
                if (widget && widget.style.display !== 'none') {
                    const btn = document.getElementById('btnRekapNotH2H');
                    if (!widget.contains(e.target) && (!btn || !btn.contains(e.target))) {
                        widget.style.display = 'none';
                    }
                }
            });
        }

        window.onload = function() {
            initRekapNotInvDraggable();
            initRekapNotH2HDraggable();
            loadList();
            // Otomatis sinkronisasi latar belakang saat halaman dibuka (progress tidak terlihat)
            setTimeout(() => {
                triggerSilentCeisaSync(false);
            }, 300);
        };
    </script>
</body>
</html>


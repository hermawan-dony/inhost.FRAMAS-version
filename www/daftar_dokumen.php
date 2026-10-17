<?php
/**
 * CEISA 4.0 - Transfer Dokumen Pabean Dashboard (transfer.php)
 * Mengadopsi Layout, Warna, Font, dan Ant Design Style Resmi Portal CEISA 4.0 (portal.beacukai.go.id/dokumen-pabean/)
 * Format: C:\dp\g-ceisa\share\Portal Ceisa 4.0.html
 */
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/CeisaJsonBuilder.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_GET['cmp_id'] ?? '');

// Ambil daftar profil perusahaan aktif untuk filter
$companies = [];
if (isset($koneksi) && $koneksi) {
    $resComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp FROM bc_ceisa_company ORDER BY cmp_name ASC");
    if ($resComp) {
        while ($r = $resComp->fetch_assoc()) {
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

if ($action === 'list') {
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
    if (!empty($filter_cmp)) {
        $cleanCmp = $koneksi->real_escape_string($filter_cmp);
        $cmpNpwp = '';
        $resComp = $koneksi->query("SELECT npwp FROM bc_ceisa_company WHERE cmp_id = '$cleanCmp' LIMIT 1");
        if ($resComp && $cr = $resComp->fetch_assoc()) {
            $cmpNpwp = preg_replace('/[^0-9]/', '', $cr['npwp']);
        }
        if (!empty($cmpNpwp)) {
            $where[] = "(a.cmp_id = '$cleanCmp' OR a.idPerusahaan LIKE '%$cmpNpwp%')";
        } else {
            $where[] = "a.cmp_id = '$cleanCmp'";
        }
    }
    if (!empty($filter_bc)) {
        $where[] = "a.kodeDokumen = '" . $koneksi->real_escape_string($filter_bc) . "'";
    }

    // Penegakan Izin Jenis Dokumen Bea Cukai Pengguna
    if (!$isAdmin && !in_array('*', $userAllowedDocs) && !in_array('ALL', $userAllowedDocs)) {
        if (!empty($userAllowedDocs)) {
            $escapedDocs = array_map(function($d) use ($koneksi) { return "'" . $koneksi->real_escape_string($d) . "'"; }, $userAllowedDocs);
            $where[] = "a.kodeDokumen IN (" . implode(',', $escapedDocs) . ")";
        } else {
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
        COALESCE(NULLIF(a.cmp_id, ''), cmp.cmp_id, '') AS cmp_id,
        a.kodeKantor AS kode_kantor,
        COALESCE(NULLIF(a.namaPerusahaan, ''), cmp.cmp_name, '') AS cmp_name,
        COALESCE(NULLIF(a.idPerusahaan, ''), cmp.npwp, '') AS cmp_npwp,
        COALESCE(jml_barang.jml, a.totalCount, 0) AS jml_barang,
        CASE WHEN LOWER(a.namaProses) LIKE '%selesai%' OR posted.jml > 0 THEN 1 ELSE 0 END AS posted,
        COALESCE(DATE_FORMAT(posted.waktu, '%Y-%m-%d %H:%i:%s'), DATE_FORMAT(a.tanggalRespon, '%Y-%m-%d %H:%i:%s'), '') AS posted_waktu,
        COALESCE(NULLIF(sppb_tbl.nomorRespon, ''), NULLIF(posted.sppb, ''), NULLIF(a.nomorRespon, ''), '') AS sppb,
        COALESCE(NULLIF(a.pdf, ''), NULLIF(sppb_tbl.pdf, ''), '') AS pdf,
        COALESCE(NULLIF(sppb_tbl.nomorRespon, ''), '') AS sppb_nomor_respon,
        IFNULL(DATE_FORMAT(sppb_tbl.tanggalRespon, '%Y-%m-%d %H:%i:%s'), '') AS sppb_tanggal_respon,
        COALESCE(NULLIF(sppb_tbl.pdf, ''), '') AS sppb_pdf,
        COALESCE(NULLIF(a.namaProses, ''), '') AS namaProses,
        COALESCE(NULLIF(a.namaRespon, ''), '') AS bcd_namaRespon,
        COALESCE(NULLIF(a.nomorRespon, ''), '') AS bcd_nomorRespon,
        IFNULL(DATE_FORMAT(a.tanggalRespon, '%Y-%m-%d %H:%i:%s'), '') AS bcd_tanggalRespon,
        COALESCE(NULLIF(a.kodeJalur, ''), 'H') AS bcd_kodeJalur,
        COALESCE(NULLIF(a.namaKantorPendek, ''), CONCAT('KPPBC ', a.kodeKantor)) AS bcd_namaKantorPendek,
        COALESCE(NULLIF(a.namaPerusahaan, ''), '') AS bcd_namaPerusahaan,
        COALESCE(NULLIF(a.idPerusahaan, ''), '') AS bcd_idPerusahaan
    FROM bc_ceisa_data a
    LEFT JOIN bc_ceisa_company cmp ON a.cmp_id = cmp.cmp_id
    LEFT JOIN (
        SELECT nomorAju, MIN(nomorRespon) AS nomorRespon, MAX(tanggalRespon) AS tanggalRespon, MAX(pdf) AS pdf
        FROM bc_ceisa_sppb
        GROUP BY nomorAju
    ) sppb_tbl ON a.nomorAju = sppb_tbl.nomorAju
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
    ORDER BY a.tanggalDaftar DESC, a.nomorAju DESC";

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

if ($action === 'preview_json') {
    header('Content-Type: application/json; charset=utf-8');
    $nomor_aju = trim($_GET['nomor_aju'] ?? '');
    $cmp_id    = trim($_GET['cmp_id'] ?? '');

    $result = CeisaJsonBuilder::generate($nomor_aju, $cmp_id);
    if (!isset($result['status']) || $result['status'] !== 'OK') {
        // Fallback: tampilkan data dari bc_ceisa_data
        $stmtBcd = $koneksi->prepare("SELECT * FROM bc_ceisa_data WHERE nomorAju = ? LIMIT 1");
        if ($stmtBcd) {
            $stmtBcd->bind_param("s", $nomor_aju);
            $stmtBcd->execute();
            $resBcd = $stmtBcd->get_result();
            if ($rowBcd = $resBcd->fetch_assoc()) {
                $result = [
                    'status' => 'OK',
                    'nomor_aju' => $nomor_aju,
                    'kode_dokumen' => $rowBcd['kodeDokumen'],
                    'json' => json_encode($rowBcd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    'data' => $rowBcd,
                    'source' => 'bc_ceisa_data'
                ];
            }
            $stmtBcd->close();
        }
    }
    echo json_encode($result);
    exit;
}

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

                <!-- Monitoring ▾ -->
                <button type="button" class="ant-btn" onclick="loadList()">
                    <span>Monitoring </span>
                    <svg viewBox="64 64 896 896" width="10" height="10" fill="currentColor">
                        <path d="M884 256h-75c-5.1 0-9.9 2.5-12.9 6.6L512 654.2 227.9 262.6c-3-4.1-7.8-6.6-12.9-6.6h-75c-6.5 0-10.3 7.4-6.5 12.7l352.6 486.1c12.8 17.6 39 17.6 51.7 0l352.6-486.1c3.9-5.3.1-12.7-6.4-12.7z"></path>
                    </svg>
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
                <div class="kpi-chip">
                    <span class="kpi-label">Total Dokumen AJU</span>
                    <strong class="kpi-val kpi-val-default" id="kpiTotal">0</strong>
                </div>
                <div class="kpi-chip">
                    <span class="kpi-label kpi-label-warning">Belum Selesai</span>
                    <strong class="kpi-val kpi-val-warning" id="kpiUnposted">0</strong>
                </div>
                <div class="kpi-chip">
                    <span class="kpi-label kpi-label-success">Selesai Proses</span>
                    <strong class="kpi-val kpi-val-success" id="kpiPosted">0</strong>
                </div>
                <div class="kpi-chip">
                    <span class="kpi-label kpi-label-primary">Total Item Barang</span>
                    <strong class="kpi-val kpi-val-primary" id="kpiBarang">0</strong>
                </div>
            </div>

            <!-- Filter Panel (Compact) -->
            <div class="filter-panel" id="filterPanel">
                <input type="hidden" id="filterCmp" value="<?= htmlspecialchars($sessionCmpId) ?>">
                <div class="filter-col">
                    <label>DOKUMEN BC</label>
                    <select id="filterBc" onchange="loadList()" class="filter-control">
                        <option value="">Semua Dokumen</option>
                        <?php if (isDocPermitted('16')): ?><option value="16">BC 1.6 (Pemasukan PLB)</option><?php endif; ?>
                        <?php if (isDocPermitted('20')): ?><option value="20">BC 2.0 (Impor PIB)</option><?php endif; ?>
                        <?php if (isDocPermitted('23')): ?><option value="23">BC 2.3 (Impor TPB)</option><?php endif; ?>
                        <?php if (isDocPermitted('25')): ?><option value="25">BC 2.5 (Pengeluaran TPB)</option><?php endif; ?>
                        <?php if (isDocPermitted('261')): ?><option value="261">BC 2.6.1 (Subkon Luar)</option><?php endif; ?>
                        <?php if (isDocPermitted('262')): ?><option value="262">BC 2.6.2 (Subkon Masuk)</option><?php endif; ?>
                        <?php if (isDocPermitted('27')): ?><option value="27">BC 2.7 (TPB ke TPB)</option><?php endif; ?>
                        <?php if (isDocPermitted('28')): ?><option value="28">BC 2.8 (Impor PLB)</option><?php endif; ?>
                        <?php if (isDocPermitted('30')): ?><option value="30">BC 3.0 (Ekspor)</option><?php endif; ?>
                        <?php if (isDocPermitted('33')): ?><option value="33">BC 3.3 (Ekspor PLB)</option><?php endif; ?>
                        <?php if (isDocPermitted('331')): ?><option value="331">BC 3.3.1 (P3BET)</option><?php endif; ?>
                        <?php if (isDocPermitted('40')): ?><option value="40">BC 4.0 (Pemasukan TLDDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('41')): ?><option value="41">BC 4.1 (Pengeluaran TLDDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('611')): ?><option value="611">PPKEK 6.1.1 (Pemasukan LDP)</option><?php endif; ?>
                        <?php if (isDocPermitted('613')): ?><option value="613">PPKEK 6.1.3 (Pemasukan TLDDP)</option><?php endif; ?>
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

    <!-- MODAL 1: PREVIEW JSON PAYLOAD -->
    <div class="modal-overlay" id="modalPreview">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalPreviewTitle">Preview JSON Payload</h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalPreview')">✕</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; font-size:11.5px; color:#8c8c8c;">
                    <span id="modalJsonMeta">Ukuran: -</span>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="copyJsonText()">📋 Salin JSON</button>
                </div>
                <pre class="code-view" id="modalJsonContent"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalPreview')">Tutup</button>
                <button type="button" class="ant-btn ant-btn-success" id="btnTransferFromModal" onclick="transferFromModal()">🚀 Kirim AJU Ini ke CEISA 4.0</button>
            </div>
        </div>
    </div>

    <!-- MODAL 2: HASIL TRANSFER CEISA 4.0 -->
    <div class="modal-overlay" id="modalResult">
        <div class="modal" style="max-width:580px;">
            <div class="modal-header">
                <h3 id="modalResultTitle">Hasil Transfer CEISA 4.0</h3>
                <button type="button" class="ant-btn ant-btn-sm" onclick="closeModal('modalResult')">✕</button>
            </div>
            <div class="modal-body" id="modalResultBody">
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn ant-btn-primary" onclick="closeModal('modalResult'); loadList();">OK, Selesai</button>
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
            const rvf = document.getElementById('rekapViewerFrame');
            if (rvf) rvf.src = activeRekapUrl;
            const mrv = document.getElementById('modalRekapViewer');
            if (mrv) mrv.style.display = 'flex';
        }

        function closeRekapViewer() {
            document.getElementById('modalRekapViewer').style.display = 'none';
            document.getElementById('rekapViewerFrame').src = '';
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

            // Cooldown check (180 detik / 3 menit) jika otomatis, agar aman dari pemblokiran IP
            const cacheKey = 'ceisa_last_silent_sync_' + (cmp || 'default');
            if (!isManual) {
                const lastSync = parseInt(localStorage.getItem(cacheKey) || '0');
                const now = Date.now();
                if (now - lastSync < 180000) {
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
                const res = await fetch(`ceisa_sync_service.php?cmp_id=${encodeURIComponent(cmp)}${forceParam}`);
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

        function filterClientTable() {
            const fBc = (document.getElementById('tblFilterBc')?.value || '').toLowerCase().trim();
            const fAju = (document.getElementById('tblFilterAju')?.value || '').toLowerCase().trim();
            const fDaftar = (document.getElementById('tblFilterDaftar')?.value || '').toLowerCase().trim();
            const fRespon = (document.getElementById('tblFilterRespon')?.value || '').toLowerCase().trim();
            const fJalur = (document.getElementById('tblFilterJalur')?.value || '').toLowerCase().trim();
            const fPerusahaan = (document.getElementById('tblFilterPerusahaan')?.value || '').toLowerCase().trim();
            const fKantor = (document.getElementById('tblFilterKantor')?.value || '').toLowerCase().trim();

            filteredData = ajuData.filter(r => {
                if (fBc && !('bc ' + r.bc).toLowerCase().includes(fBc)) return false;
                if (fAju && !r.nomor_aju.toLowerCase().includes(fAju)) return false;
                if (fDaftar && !(r.nomor_daftar || '').toLowerCase().includes(fDaftar)) return false;
                if (fRespon && !(r.bcd_namaRespon || (parseInt(r.posted) > 0 ? 'sppb' : '')).toLowerCase().includes(fRespon)) return false;
                if (fJalur && !(r.bcd_kodeJalur || 'h').toLowerCase().includes(fJalur)) return false;
                if (fPerusahaan && !(r.cmp_name || r.bs || '').toLowerCase().includes(fPerusahaan)) return false;
                if (fKantor && !(r.bcd_namaKantorPendek || r.kode_kantor || '').toLowerCase().includes(fKantor)) return false;
                return true;
            });

            // Update KPI metrics
            let unposted = 0, posted = 0, barang = 0;
            ajuData.forEach(r => {
                const np = (r.namaProses || '').toLowerCase();
                if (np.includes('selesai') || parseInt(r.posted) > 0) posted++; else unposted++;
                barang += parseInt(r.jml_barang || r.totalCount || 0);
            });
            if (document.getElementById('kpiTotal')) document.getElementById('kpiTotal').innerText = ajuData.length;
            if (document.getElementById('kpiUnposted')) document.getElementById('kpiUnposted').innerText = unposted;
            if (document.getElementById('kpiPosted')) document.getElementById('kpiPosted').innerText = posted;
            if (document.getElementById('kpiBarang')) document.getElementById('kpiBarang').innerText = barang;

            currentPage = 1;
            renderTable();
        }

        function resetFilters() {
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

                // Status Chip (Gate In TPB/KEK / Stuffing / Selesai Proses / Siap Kirim)
                let tagClass = 'ant-tag-gold';
                let tagText = 'Siap Kirim';
                if (r.namaProses) {
                    tagText = r.namaProses;
                    const np = tagText.toLowerCase();
                    if (np.includes('selesai')) tagClass = 'ant-tag-green';
                    else if (np.includes('gate')) tagClass = 'ant-tag-gold';
                    else if (np.includes('stuffing')) tagClass = 'ant-tag-gold';
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

                html += `
                    <tr class="ant-table-row ant-table-row-level-0 ${isSelected ? 'selected-row' : ''}" id="row-${r.nomor_aju}">
                        <td style="color:#8c8c8c; font-family:monospace;">${itemNum}</td>
                        <td style="font-weight:500;">BC ${r.bc}</td>
                        <td>
                            <div style="display:flex; align-items:center;">
                                <b>${r.nomor_aju}</b>
                                <i class="anticon anticon-info-circle ml-2" style="cursor:pointer; margin-left:6px;" onclick="previewJson('${r.nomor_aju}', '${r.cmp_id || ''}')" title="Preview JSON">
                                    <svg viewBox="64 64 896 896" width="13" height="13" fill="currentColor">
                                        <path fill="#1890ff" d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm0 820c-205.4 0-372-166.6-372-372s166.6-372 372-372 372 166.6 372 372-166.6 372-372 372z"></path>
                                        <path fill="#e6f7ff" d="M512 140c-205.4 0-372 166.6-372 372s166.6 372 372 372 372-166.6 372-372-166.6-372-372-372zm32 588c0 4.4-3.6 8-8 8h-48c-4.4 0-8-3.6-8-8V456c0-4.4 3.6-8 8-8h48c4.4 0 8 3.6 8 8v272zm-32-344a48.01 48.01 0 0 1 0-96 48.01 48.01 0 0 1 0 96z"></path>
                                        <path fill="#1890ff" d="M464 336a48 48 0 1 0 96 0 48 48 0 1 0-96 0zm72 112h-48c-4.4 0-8 3.6-8 8v272c0 4.4 3.6 8 8 8h48c4.4 0 8-3.6 8-8V456c0-4.4-3.6-8-8-8z"></path>
                                    </svg>
                                </i>
                            </div>
                            <p style="margin:4px 0px;"><span class="ant-tag ${tagClass}">${escapeHtml(tagText)}</span></p>
                        </td>
                        <td>
                            <div>${r.nomor_daftar || '<span style="color:#bfbfbf;">-</span>'}</div>
                            <p style="margin:0; font-size:11px; color:#8c8c8c;">${r.tanggal_daftar || ''}</p>
                        </td>
                        <td>
                            <div>${escapeHtml(namaRespon)}${responBadgeExtra}</div>
                            ${noRespon ? `<p style="cursor:pointer; text-decoration:underline; color:rgb(3, 157, 252); margin:0; font-size:10px;" onclick="previewJson('${r.nomor_aju}', '${r.cmp_id || ''}')">${escapeHtml(noRespon)}</p>` : ''}
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
            const btn = document.getElementById('btnMainTransfer');
            if (btn) btn.disabled = (count === 0);
        }

        async function previewJson(nomorAju, cmpId) {
            activePreviewAju = { nomorAju, cmpId };
            const pt = document.getElementById('modalPreviewTitle');
            if (pt) pt.innerText = `Preview JSON Payload: ${nomorAju}`;
            const jm = document.getElementById('modalJsonMeta');
            if (jm) jm.innerText = 'Membuat payload JSON...';
            const jc = document.getElementById('modalJsonContent');
            if (jc) jc.innerText = 'Loading JSON dari builder...';
            const mp = document.getElementById('modalPreview');
            if (mp) mp.style.display = 'flex';

            try {
                const res = await fetch(`daftar_dokumen.php?action=preview_json&nomor_aju=${encodeURIComponent(nomorAju)}&cmp_id=${encodeURIComponent(cmpId)}`);
                const json = await res.json();
                const formatted = JSON.stringify(json, null, 2);
                if (jc) jc.innerText = formatted;
                if (jm) jm.innerText = `Ukuran: ${formatted.length} bytes | Format: CEISA 4.0 Standard`;
            } catch (err) {
                if (jc) jc.innerText = 'Gagal memuat JSON: ' + err.message;
            }
        }

        function copyJsonText() {
            const el = document.getElementById('modalJsonContent');
            const text = el ? el.innerText : '';
            navigator.clipboard.writeText(text).then(() => {
                showSuccess('JSON berhasil disalin ke clipboard!');
            }).catch(err => {
                showError('Gagal menyalin: ' + err);
            });
        }

        async function transferSingle(nomorAju, cmpId) {
            const ok = await showConfirm(`Kirim Dokumen AJU [${nomorAju}] langsung ke Portal CEISA 4.0?`, 'Konfirmasi Transfer CEISA 4.0', 'Ya, Kirim Dokumen', 'Batal', 'info');
            if (!ok) return;

            showResultModal(`Mengirim AJU ${nomorAju}...`, `<div style="text-align:center; padding:20px;"><span class="spinner"></span> Menghubungkan ke Gateway Bea Cukai CEISA 4.0...</div>`);

            try {
                const fd = new FormData();
                fd.append('action', 'transfer');
                fd.append('nomor_aju', nomorAju);
                fd.append('cmp_id', cmpId);

                const res = await fetch('transfer.php', { method: 'POST', body: fd });
                const json = await res.json();
                renderTransferResponse(json);
            } catch (err) {
                renderTransferResponse({ status: 'ERROR', message: 'Koneksi error: ' + err.message });
            }
        }

        async function transferFromModal() {
            if (!activePreviewAju) return;
            closeModal('modalPreview');
            await transferSingle(activePreviewAju.nomorAju, activePreviewAju.cmpId);
        }

        async function transferSelected() {
            const count = selectedAju.size;
            if (count === 0) return;
            const ok = await showConfirm(`Apakah Anda yakin ingin mentransfer ${count} dokumen terpilih ke CEISA 4.0?`, 'Konfirmasi Transfer Batch', 'Ya, Transfer Sekarang', 'Batal', 'info');
            if (!ok) return;

            const ajuList = Array.from(selectedAju);
            showResultModal(`Memproses ${count} Dokumen...`, `<div id="batchProgress">Memulai transfer batch...</div>`);

            let successCount = 0;
            let failCount = 0;
            let logHtml = '';

            for (let i = 0; i < ajuList.length; i++) {
                const noAju = ajuList[i];
                const matched = ajuData.find(x => x.nomor_aju === noAju);
                const cmpId = matched ? matched.cmp_id : '';

                const bp = document.getElementById('batchProgress');
                if (bp) {
                    bp.innerHTML = `
                        <div style="margin-bottom:12px; font-weight:600;">Mengirim (${i + 1}/${count}): <code style="color:#1890ff;">${noAju}</code></div>
                        <div style="background:#f0f0f0; height:8px; border-radius:4px; overflow:hidden;">
                            <div style="background:#1890ff; width:${((i+1)/count)*100}%; height:100%; transition:width 0.3s;"></div>
                        </div>
                    `;
                }

                try {
                    const fd = new FormData();
                    fd.append('action', 'transfer');
                    fd.append('nomor_aju', noAju);
                    fd.append('cmp_id', cmpId);

                    const res = await fetch('transfer.php', { method: 'POST', body: fd });
                    const json = await res.json();

                    if (json.status === 'OK') {
                        successCount++;
                        logHtml += `<div style="color:#52c41a; font-size:11.5px; margin-bottom:4px;">✓ [${noAju}] Berhasil ditransfer</div>`;
                    } else {
                        failCount++;
                        logHtml += `<div style="color:#f5222d; font-size:11.5px; margin-bottom:4px;">✕ [${noAju}] ${json.message || 'Gagal'}</div>`;
                    }
                } catch (err) {
                    failCount++;
                    logHtml += `<div style="color:#f5222d; font-size:11.5px; margin-bottom:4px;">✕ [${noAju}] Error jaringan</div>`;
                }
            }

            const mrt = document.getElementById('modalResultTitle');
            if (mrt) mrt.innerText = 'Hasil Transfer Batch CEISA 4.0';
            const mrb = document.getElementById('modalResultBody');
            if (mrb) {
                mrb.innerHTML = `
                    <div style="margin-bottom:12px; font-weight:600; font-size:13px;">
                        Transfer Selesai: <span style="color:#52c41a;">${successCount} Sukses</span>, <span style="color:#f5222d;">${failCount} Gagal</span>
                    </div>
                    <div style="max-height:260px; overflow-y:auto; border:1px solid #d9d9d9; padding:10px; border-radius:4px; background:#fafafa;">
                        ${logHtml}
                    </div>
                `;
            }
        }

        function showResultModal(title, bodyHtml) {
            const mrt = document.getElementById('modalResultTitle');
            if (mrt) mrt.innerText = title;
            const mrb = document.getElementById('modalResultBody');
            if (mrb) mrb.innerHTML = bodyHtml;
            const mr = document.getElementById('modalResult');
            if (mr) mr.style.display = 'flex';
        }

        function renderTransferResponse(json) {
            let html = '';
            if (json.status === 'OK') {
                html = `
                    <div style="background:#e6f7ff; border:1px solid #91d5ff; border-radius:4px; padding:12px; margin-bottom:12px;">
                        <h4 style="color:#1890ff; margin-bottom:4px; font-size:13px;">✓ Dokumen Berhasil Diposting ke CEISA 4.0</h4>
                        <p style="font-size:11.5px; color:#595959;">Nomor AJU: <strong style="font-family:monospace;">${escapeHtml(json.nomor_aju)}</strong></p>
                    </div>
                    <pre class="code-view">${escapeHtml(JSON.stringify(json.response, null, 2))}</pre>
                `;
            } else {
                html = `
                    <div style="background:#fff1f0; border:1px solid #ffa39e; border-radius:4px; padding:12px; margin-bottom:12px;">
                        <h4 style="color:#f5222d; margin-bottom:4px; font-size:13px;">✕ Respon Transfer CEISA 4.0</h4>
                        <p style="font-size:11.5px; color:#595959;">${escapeHtml(json.message || 'Gagal memproses dokumen')}</p>
                    </div>
                    ${json.raw_response ? `<pre class="code-view">${escapeHtml(json.raw_response)}</pre>` : ''}
                `;
            }
            document.getElementById('modalResultBody').innerHTML = html;
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


<?php
/**
 * CEISA 4.0 - Browse Data NVOCC (Dashboard Ceisa 4.0 - Manifes)
 * Mereplikasi 100% Tampilan & Fungsi Resmi Portal CEISA 4.0 (portal.beacukai.go.id/manifes)
 * Menu: SINGLE CORE > Manifes Online > NVOCC > Browse Data NVOCC
 */
session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$isAdmin = !empty($_SESSION['is_admin']) || (!empty($_GET['is_admin']) && $_GET['is_admin'] == '1');
$sessionCmpId = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : (!empty($cmp_id) ? trim($cmp_id) : 'TVL')));
if (empty($sessionCmpId) || $sessionCmpId === 'default' || ($sessionCmpId === '080167772504400' && empty($_REQUEST['cmp_id']) && (empty($_SESSION['company_cmp_id']) || $_SESSION['company_cmp_id'] !== '080167772504400'))) {
    $sessionCmpId = 'TVL';
}
$rawNpwp = preg_replace('/\D/', '', $npwp ?: ($_SESSION['company_npwp'] ?? ''));
$sessionNpwp = $rawNpwp;
if (strlen($sessionNpwp) === 16 && substr($sessionNpwp, 0, 1) === '0') {
    $sessionNpwp = substr($sessionNpwp, 1);
} elseif (strlen($sessionNpwp) > 15) {
    $sessionNpwp = substr($sessionNpwp, 0, 15);
}
$sessionKodeKantor = $kode_kantor ?: ($_SESSION['company_kode_kantor'] ?? '070100');

if (!function_exists('isJwtExpired')) {
    function isJwtExpired($jwt) {
        if (empty($jwt) || !is_string($jwt)) return true;
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return false;
        $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
        if (!empty($payload['exp']) && is_numeric($payload['exp'])) {
            return (time() >= ($payload['exp'] - 30));
        }
        return false;
    }
}

if (!function_exists('isTokenValidForCompany')) {
    function isTokenValidForCompany($jwt, $targetCmpRow) {
        if (empty($jwt) || !is_string($jwt) || empty($targetCmpRow)) return false;
        if (isJwtExpired($jwt)) return false;

        $parts = explode('.', $jwt);
        if (count($parts) < 2) return false;
        $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
        if (!is_array($payload)) return false;

        // 1. Cek preferred_username
        $expectedUser = strtolower(trim($targetCmpRow['username_ceisa'] ?? ''));
        $tokenUser = strtolower(trim($payload['preferred_username'] ?? ''));
        if (!empty($expectedUser) && !empty($tokenUser) && $expectedUser !== $tokenUser) {
            return false;
        }

        // 2. Cek identitas (NPWP 15)
        $rawCmpNpwp = preg_replace('/\D/', '', $targetCmpRow['npwp'] ?? '');
        $tokenIdentitas = preg_replace('/\D/', '', $payload['identitas'] ?? '');
        if (!empty($rawCmpNpwp) && !empty($tokenIdentitas)) {
            $cmpNpwp15 = (strlen($rawCmpNpwp) === 16 && substr($rawCmpNpwp, 0, 1) === '0') ? substr($rawCmpNpwp, 1) : substr($rawCmpNpwp, 0, 15);
            if ($tokenIdentitas !== $cmpNpwp15 && $tokenIdentitas !== $rawCmpNpwp) {
                return false;
            }
        }
        return true;
    }
}

// Ambil profil resmi perusahaan untuk validasi kepemilikan token
$currentCmpRow = null;
$qCmp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, username_ceisa, token_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($sessionCmpId) . "' LIMIT 1");
if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
    $currentCmpRow = $rCmp;
}

// Ambil token MFA aktif TERISOLASI (hanya yang sah milik perusahaan $sessionCmpId)
$cachedToken = $_SESSION["mfatoken_" . $sessionCmpId] ?? ($_SESSION["portal_token_" . $sessionCmpId] ?? '');
if (!empty($cachedToken) && !isTokenValidForCompany($cachedToken, $currentCmpRow)) {
    $cachedToken = '';
}
if (empty($cachedToken)) {
    $mfaCacheFile = sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionCmpId) . '.json';
    if (file_exists($mfaCacheFile)) {
        $cTok = @json_decode(@file_get_contents($mfaCacheFile), true);
        if (!empty($cTok['token']) && isTokenValidForCompany($cTok['token'], $currentCmpRow)) {
            $cachedToken = $cTok['token'];
        }
    }
}
if (empty($cachedToken) && !empty($currentCmpRow['token_ceisa'])) {
    if (isTokenValidForCompany($currentCmpRow['token_ceisa'], $currentCmpRow)) {
        $cachedToken = $currentCmpRow['token_ceisa'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manifest Ceisa 4.0 - Browse Data NVOCC</title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
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
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border: #e8e8e8;
            --border-card: #e8e8e8;
            --border-table: #e8e8e8;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-heading: #262626;
            --text-main: #333333;
            --text-muted: #8c8c8c;
            --table-head-bg: #fafafa;
            --table-row-hover: #f5f9ff;
            --dropdown-bg: #ffffff;
            --dropdown-shadow: 0 4px 14px rgba(0,0,0,0.12);
            color-scheme: light;
        }

        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --border: #303030;
            --border-card: #303030;
            --border-table: #2a2a2a;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-heading: rgba(255, 255, 255, 0.95);
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --table-head-bg: #1a1a1a;
            --table-row-hover: #262626;
            --dropdown-bg: #1f1f1f;
            --dropdown-shadow: 0 4px 16px rgba(0,0,0,0.45);
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            padding: 10px 14px;
            font-size: 10px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Breadcrumb */
        .portal-breadcrumb {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
            font-weight: 500;
        }

        /* Portal Main Container */
        .ceisa-container {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            padding: 12px 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            min-height: calc(100vh - 65px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Header Row: Title & Action Button */
        .portal-header-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .portal-title-block {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .portal-folder-icon {
            font-size: 15px;
            color: #1890ff;
        }
        .portal-title-text {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-heading);
            letter-spacing: -0.2px;
        }

        /* Official CEISA Ant Design Blue Button */
        .ant-btn-ceisa-primary {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #1890ff;
            color: #ffffff;
            border: 1px solid #1890ff;
            height: 24px;
            padding: 0 10px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 3px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(24, 144, 255, 0.2);
            transition: all 0.2s;
            font-family: inherit;
        }
        .ant-btn-ceisa-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }

        /* Mini Dashboard Rekap Status (Area Kotak Merah Pengguna) */
        .mini-status-dashboard {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--bg-base);
            padding: 3px 6px;
            border-radius: 5px;
            border: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .mini-card {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3px 8px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
            min-width: 52px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            user-select: none;
        }
        .mini-card:hover {
            transform: translateY(-1px);
            border-color: #1890ff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.06);
        }
        .mini-card.active {
            border-color: #1890ff;
            background: rgba(24, 144, 255, 0.08);
            box-shadow: 0 0 0 1px #1890ff;
        }
        .mini-card-label {
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.3px;
            line-height: 1;
            margin-bottom: 2px;
        }
        .mini-card-val {
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
        }
        .mini-card-total .mini-card-label { color: #1890ff; }
        .mini-card-total .mini-card-val { color: #1890ff; }
        .mini-card-draft .mini-card-label { color: #8c8c8c; }
        .mini-card-draft .mini-card-val { color: #595959; }
        .mini-card-ready .mini-card-label { color: #52c41a; }
        .mini-card-ready .mini-card-val { color: #389e0d; }
        .mini-card-terkirim .mini-card-label { color: #1890ff; }
        .mini-card-terkirim .mini-card-val { color: #096dd9; }
        .mini-card-rekon .mini-card-label { color: #fa8c16; }
        .mini-card-rekon .mini-card-val { color: #d46b08; }
        .mini-card-selesai .mini-card-label { color: #237804; }
        .mini-card-selesai .mini-card-val { color: #135200; }
        .mini-card-reject .mini-card-label { color: #cf1322; }
        .mini-card-reject .mini-card-val { color: #a8071a; }

        /* Clickable Vessel Link */
        .vessel-link {
            color: #1890ff;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 1px 5px;
            border-radius: 3px;
            transition: all 0.15s;
        }
        .vessel-link:hover {
            color: #40a9ff;
            background: rgba(24, 144, 255, 0.08);
            text-decoration: underline;
        }
        .badge-kantor {
            background: rgba(0,0,0,0.04);
            color: var(--text-muted);
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: 600;
        }
        .badge-kade {
            background: #fffbe6;
            color: #d46b08;
            border: 1px solid #ffe58f;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: 600;
        }

        /* Actions Toolbar Under Title */
        .portal-toolbar {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .portal-tool-btn {
            background: transparent;
            border: none;
            color: #1890ff;
            font-size: 10px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 4px;
            border-radius: 2px;
            transition: all 0.15s;
            font-family: inherit;
        }
        .portal-tool-btn:hover {
            color: #40a9ff;
            background: rgba(24, 144, 255, 0.06);
        }
        .portal-tool-btn.live-sync {
            color: #52c41a;
            font-weight: 600;
        }
        .portal-tool-btn.live-sync:hover {
            color: #73d13d;
            background: rgba(82, 196, 26, 0.08);
        }

        /* Search & Filter Inline Row */
        .filter-drawer {
            display: none;
            background: var(--bg-base);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 8px 10px;
            margin-bottom: 10px;
            gap: 8px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-drawer.open {
            display: flex;
        }
        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .filter-item label {
            font-size: 8.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .filter-input {
            height: 22px;
            padding: 1px 6px;
            font-size: 9.5px;
            border: 1px solid var(--border);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .filter-input:focus {
            border-color: #1890ff;
        }

        /* Table Styling matching Official CEISA 4.0 */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border-table);
            border-radius: 3px;
        }
        .ceisa-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            text-align: left;
            background: var(--bg-card);
        }
        .ceisa-table thead tr th {
            background: var(--table-head-bg);
            color: var(--text-heading);
            font-weight: 600;
            padding: 6px 8px;
            border-bottom: 1px solid var(--border-table);
            white-space: nowrap;
            user-select: none;
        }
        .th-content {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .sort-icon {
            color: #bfbfbf;
            font-size: 8px;
            cursor: pointer;
        }
        .search-icon-btn {
            color: #8c8c8c;
            cursor: pointer;
            font-size: 9px;
            transition: color 0.15s;
        }
        .search-icon-btn:hover {
            color: #1890ff;
        }
        .ceisa-table tbody tr {
            border-bottom: 1px solid var(--border-table);
            transition: background 0.15s;
        }
        .ceisa-table tbody tr:hover {
            background: var(--table-row-hover);
        }
        .ceisa-table tbody tr td {
            padding: 5px 8px;
            white-space: nowrap;
            vertical-align: middle;
            color: var(--text-main);
        }

        /* Nomor Pengajuan Link */
        .aju-link {
            color: var(--text-heading);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: color 0.15s;
        }
        .aju-link:hover {
            color: #1890ff;
            text-decoration: underline;
        }

        /* Status Badges (Replicating Official CEISA 4.0 Screenshot) */
        .status-badge {
            display: inline-block;
            padding: 1px 7px;
            font-size: 8.5px;
            font-weight: 700;
            border-radius: 2px;
            text-align: center;
            letter-spacing: 0.3px;
            line-height: 14px;
            text-transform: uppercase;
        }
        .status-draft {
            background: #d9d9d9;
            color: #595959;
            border: 1px solid #bfbfbf;
        }
        .status-ready {
            background: #52c41a;
            color: #ffffff;
            border: 1px solid #389e0d;
        }
        .status-penerimaan {
            background: #237804;
            color: #ffffff;
            border: 1px solid #135200;
        }
        .status-rekon, .status-rikon {
            background: #fa8c16;
            color: #ffffff;
            border: 1px solid #d46b08;
        }
        .status-persetujuan, .status-persetujuan_perbaikan {
            background: #5b8c00;
            color: #ffffff;
            border: 1px solid #3f6600;
        }
        .status-reject, .status-ditolak {
            background: #cf1322;
            color: #ffffff;
            border: 1px solid #a8071a;
        }
        .status-terkirim, .status-kirim {
            background: #1890ff;
            color: #ffffff;
            border: 1px solid #096dd9;
        }

        /* Action Dropdown Menu Button */
        .action-menu-trigger {
            color: #1890ff;
            background: transparent;
            border: none;
            font-size: 9.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 1px 4px;
            border-radius: 2px;
            transition: all 0.15s;
            font-family: inherit;
        }
        .action-menu-trigger:hover {
            color: #40a9ff;
            background: rgba(24, 144, 255, 0.08);
        }

        /* Floating Dropdown Menu */
        .dropdown-menu {
            position: fixed;
            background: var(--dropdown-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            box-shadow: var(--dropdown-shadow);
            z-index: 9999;
            display: none;
            min-width: 170px;
            padding: 4px 0;
        }
        .dropdown-item {
            padding: 5px 12px;
            font-size: 9.5px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .dropdown-item:hover {
            background: rgba(24, 144, 255, 0.08);
            color: #1890ff;
        }
        .dropdown-item.danger:hover {
            background: rgba(255, 77, 79, 0.1);
            color: #ff4d4f;
        }
        .dropdown-divider {
            height: 1px;
            background: var(--border);
            margin: 3px 0;
        }

        /* Pagination */
        .portal-pagination-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            font-size: 9.5px;
        }
        .page-btn {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-main);
            min-width: 20px;
            height: 20px;
            padding: 0 4px;
            border-radius: 2px;
            font-size: 9.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .page-btn.active {
            background: #1890ff;
            color: #fff;
            border-color: #1890ff;
            font-weight: 600;
        }
        .page-btn:hover:not(.active) {
            border-color: #1890ff;
            color: #1890ff;
        }

        /* Spinner & Empty State */
        .table-loading {
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
        }
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(24, 144, 255, 0.2);
            border-top-color: #1890ff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        /* Detail View Styles (Replicating Dokumen Header & Master BL from CEISA 4.0 Portal) */
        .detail-view-container {
            display: none;
            width: 100%;
        }
        .detail-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }
        .detail-title-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-back-browse {
            background: none;
            border: none;
            font-size: 15px;
            color: var(--primary);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            transition: background 0.15s;
        }
        .btn-back-browse:hover {
            background: rgba(24, 144, 255, 0.1);
        }
        .detail-title-main {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .detail-title-sub {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .btn-cek-rekon {
            background: #ff4d4f;
            color: #ffffff;
            border: none;
            border-radius: 3px;
            padding: 5px 16px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s;
        }
        .btn-cek-rekon:hover {
            background: #ff7875;
        }
        
        /* Grid Table for Dokumen Header */
        .detail-grid-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            font-size: 10px;
        }
        .detail-grid-table td {
            border: 1px solid var(--border-table);
            padding: 7px 10px;
        }
        .detail-grid-label {
            background: var(--table-head-bg);
            color: var(--text-muted);
            font-weight: 500;
            width: 135px;
        }
        .detail-grid-val {
            color: var(--text-heading);
            font-weight: 500;
        }
        .detail-form-row {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            gap: 12px;
        }
        .detail-form-label {
            width: 160px;
            font-size: 10px;
            color: var(--text-main);
            font-weight: 400;
        }
        .detail-input-readonly {
            background: var(--table-head-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 4px 8px;
            font-size: 10px;
            color: var(--text-main);
            width: 250px;
            outline: none;
        }

        /* Data Master BL Card */
        .master-bl-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            margin-top: 14px;
            overflow: hidden;
        }
        .master-bl-card-header {
            background: var(--table-head-bg);
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }
        .btn-card-add {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-main);
            border-radius: 2px;
            padding: 1px 7px;
            font-size: 11px;
            cursor: pointer;
        }
        .btn-card-add:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
    </style>
</head>
<body data-theme="light">

    <!-- Top Breadcrumb -->
    <div class="portal-breadcrumb" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <div>
            <span>CEISA 4.0</span> <span style="margin:0 4px; color:var(--text-muted);">/</span>
            <span>Manifest Ceisa 4.0</span> <span style="margin:0 4px; color:var(--text-muted);">/</span>
            <span style="color:var(--text-heading); font-weight:600;">Browse Data NVOCC</span>
        </div>
        <div style="font-size:9px; color:var(--text-muted);">
            Sumber: <code style="background:rgba(24,144,255,0.08); color:#1890ff; padding:1px 6px; border-radius:3px; font-weight:500;">prosesNPWP-NVOCC/<?= htmlspecialchars($sessionNpwp) ?></code>
        </div>
    </div>

    <!-- Main White Card Container -->
    <div class="ceisa-container">
        <!-- Section 1: Browse Data NVOCC -->
        <div id="viewBrowseSection">
            <!-- Header Row: Title, Actions & Mini Dashboard Rekap Status -->
            <div class="portal-header-row" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <div>
                    <div class="portal-title-block" style="display:flex; align-items:center; gap:8px;">
                        <span class="portal-folder-icon">📁</span>
                        <span class="portal-title-text">Browse Data NVOCC</span>
                        <button type="button" class="ant-btn-ceisa-primary" style="margin-left:8px;" onclick="openHostToHostEditor()">
                            <span>➕</span>
                            <span>Pembuatan Dokumen Baru</span>
                        </button>
                    </div>

                    <!-- Actions Toolbar -->
                    <div class="portal-toolbar" style="margin-bottom:0; padding-bottom:0; border-bottom:none; margin-top:8px;">
                        <button type="button" class="portal-tool-btn" onclick="openRestoreDataModal()">
                            <span>📥</span>
                            <span>Restore Data</span>
                        </button>
                        <button type="button" class="portal-tool-btn" onclick="toggleFilterDrawer()">
                            <span>⚡</span>
                            <span>Filter Data</span>
                        </button>
                        <button type="button" class="portal-tool-btn" onclick="loadPortalData(true)">
                            <span>🔄</span>
                            <span>Perbaharui Data</span>
                        </button>
                        <button type="button" class="portal-tool-btn live-sync" onclick="openSyncPortalModal()" title="Tarik langsung seluruh dokumen Manifes NVOCC dari Gateway Portal CEISA 4.0">
                            <span>🌐</span>
                            <span>Tarik dari CEISA 4.0 (Live API)</span>
                        </button>
                    </div>
                </div>

                <!-- MINI DASHBOARD STATUS REKAP (Di Area Kotak Merah Pengguna) -->
                <div class="mini-status-dashboard" id="miniStatusDashboard" title="Klik salah satu kartu untuk mem-filter dokumen berdasarkan status">
                    <div class="mini-card mini-card-total active" onclick="filterByStatus('')" title="Semua Dokumen">
                        <span class="mini-card-label">TOTAL</span>
                        <span class="mini-card-val" id="miniStatTotal">0</span>
                    </div>
                    <div class="mini-card mini-card-draft" onclick="filterByStatus('Draft')" title="Filter Status DRAFT">
                        <span class="mini-card-label">DRAFT</span>
                        <span class="mini-card-val" id="miniStatDraft">0</span>
                    </div>
                    <div class="mini-card mini-card-ready" onclick="filterByStatus('Ready')" title="Filter Status READY">
                        <span class="mini-card-label">READY</span>
                        <span class="mini-card-val" id="miniStatReady">0</span>
                    </div>
                    <div class="mini-card mini-card-terkirim" onclick="filterByStatus('Terkirim')" title="Filter Status TERKIRIM">
                        <span class="mini-card-label">TERKIRIM</span>
                        <span class="mini-card-val" id="miniStatTerkirim">0</span>
                    </div>
                    <div class="mini-card mini-card-rekon" onclick="filterByStatus('Rekon')" title="Filter Status REKON">
                        <span class="mini-card-label">REKON</span>
                        <span class="mini-card-val" id="miniStatRekon">0</span>
                    </div>
                    <div class="mini-card mini-card-selesai" onclick="filterByStatus('Selesai')" title="Filter Status SELESAI / PENERIMAAN">
                        <span class="mini-card-label">SELESAI</span>
                        <span class="mini-card-val" id="miniStatSelesai">0</span>
                    </div>
                    <div class="mini-card mini-card-reject" onclick="filterByStatus('Reject')" title="Filter Status REJECT / DITOLAK">
                        <span class="mini-card-label">DITOLAK</span>
                        <span class="mini-card-val" id="miniStatReject">0</span>
                    </div>
                </div>
            </div>

            <!-- Filter Drawer Panel -->
            <div class="filter-drawer" id="filterDrawer">
                <div class="filter-item">
                    <label>Pencarian Bebas</label>
                    <input type="text" id="filterSearch" class="filter-input" placeholder="Cari No Aju / Voyage / Kapal..." onkeyup="if(event.key==='Enter') loadPortalData();" style="width:190px;">
                </div>
                <div class="filter-item">
                    <label>Jenis Dokumen</label>
                    <select id="filterJenis" class="filter-input" onchange="loadPortalData()">
                        <option value="">Semua (Inward & Outward)</option>
                        <option value="12">Outward (BC 1.1)</option>
                        <option value="11">Inward (BC 1.1)</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Status</label>
                    <select id="filterStatus" class="filter-input" onchange="loadPortalData()">
                        <option value="">Semua Status</option>
                        <option value="Draft">DRAFT</option>
                        <option value="Ready">READY</option>
                        <option value="Penerimaan">PENERIMAAN</option>
                        <option value="Rekon">REKON</option>
                        <option value="Persetujuan Perbaikan">PERSETUJUAN PERBAIKAN</option>
                        <option value="Reject">REJECT / DITOLAK</option>
                    </select>
                </div>
                <button type="button" class="ant-btn-ceisa-primary" style="height:22px;" onclick="loadPortalData()">
                    <span>Terapkan</span>
                </button>
                <button type="button" class="portal-tool-btn" style="color:var(--text-muted);" onclick="resetFilterDrawer()">
                    <span>Reset</span>
                </button>
            </div>

            <!-- Data Table (10 Columns exact match) -->
            <div class="table-responsive">
                <table class="ceisa-table" id="portalTable">
                    <thead>
                        <tr>
                            <th onclick="toggleSort('nomor_aju')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Nomor Pengajuan</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('kode_kantor')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Kode Kantor</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('nomor_voyage')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Nomor Voyage / Flight</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('jenis_manifes')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Jenis Dokumen</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('nomor_bc11')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Nomor Daftar</span>
                                    <span class="sort-icon">⇅</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('tanggal_bc11')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Tanggal Daftar</span>
                                    <span class="sort-icon">⇅</span>
                                    <span>📅</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('status')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Status</span>
                                    <span class="sort-icon">⇅</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('nama_sarana_pengangkut')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Sarana Angkut</span>
                                    <span class="sort-icon">⇅</span>
                                    <span class="search-icon-btn" onclick="event.stopPropagation(); toggleFilterDrawer();">🔍</span>
                                </div>
                            </th>
                            <th onclick="toggleSort('tanggal_berangkat')" style="cursor:pointer;">
                                <div class="th-content">
                                    <span>Tanggal</span>
                                    <span class="sort-icon">⇅</span>
                                    <span>📅</span>
                                </div>
                            </th>
                            <th style="text-align:center; width:65px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="portalTableBody">
                        <tr>
                            <td colspan="10" class="table-loading">
                                <span class="spinner"></span> Memuat data manifes NVOCC...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="portal-pagination-bar" id="paginationBar">
                <span id="pageInfo" style="color:var(--text-muted); margin-right:8px;">Menampilkan 0 data</span>
                <button type="button" class="page-btn" id="btnPrevPage" onclick="changePage(currentPage - 1)">‹</button>
                <div id="pageNumbers" style="display:inline-flex; gap:3px;"></div>
                <button type="button" class="page-btn" id="btnNextPage" onclick="changePage(currentPage + 1)">›</button>
                <select id="pageSizeSelect" class="filter-input" style="height:20px; font-size:9px;" onchange="changePageSize(this.value)">
                    <option value="10">10 / hal</option>
                    <option value="25" selected>25 / hal</option>
                    <option value="50">50 / hal</option>
                </select>
            </div>
        </div>

        <!-- Section 2: Detail View Dokumen Header & Data Master BL (100% Identik Portal CEISA 4.0) -->
        <div id="viewDetailSection" class="detail-view-container">
            <!-- Top Bar: Back & Cek Rekon -->
            <div class="detail-header-bar">
                <div class="detail-title-group">
                    <button type="button" class="btn-back-browse" onclick="closeDetailDoc()" title="Kembali ke Browse Data NVOCC">
                        <span>←</span>
                    </button>
                    <span class="detail-title-main">Dokumen Header</span>
                    <span class="detail-title-sub" id="detSubTitle">Outward (BC 1.1) - LAUT NVOCC</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button type="button" class="btn-cek-rekon" onclick="doCekRekon()" title="Periksa status rekonsiliasi manifes di CEISA 4.0">
                        <span>Cek Rekon</span>
                    </button>
                    <button type="button" class="ant-btn-ceisa-primary" style="height:24px; font-size:9.5px;" onclick="openHostToHostEditor(currentDetailAju)">
                        <span>✏️ Buka di Host to Host</span>
                    </button>
                </div>
            </div>

            <!-- Card Grid Dokumen Header -->
            <table class="detail-grid-table">
                <tr>
                    <td class="detail-grid-label">Nama Perusahaan</td>
                    <td class="detail-grid-val" id="detNamaPerusahaan" style="width:35%;">—</td>
                    <td class="detail-grid-label">Alamat Perusahaan</td>
                    <td class="detail-grid-val" id="detAlamatPerusahaan">—</td>
                </tr>
                <tr>
                    <td class="detail-grid-label">Nomor Pengajuan</td>
                    <td class="detail-grid-val" id="detNomorPengajuan" style="font-weight:600; color:#1890ff;">—</td>
                    <td class="detail-grid-label">Nomor Daftar</td>
                    <td class="detail-grid-val">
                        <span id="detNomorDaftar">—</span>
                        <span style="margin:0 12px; color:var(--text-muted);">Tanggal Daftar:</span>
                        <span id="detTanggalDaftar" style="font-weight:500;">—</span>
                    </td>
                </tr>
                <tr>
                    <td class="detail-grid-label">Kantor Pelayanan</td>
                    <td class="detail-grid-val" colspan="3">
                        <div style="background:var(--bg-input); border:1px solid var(--border); border-radius:3px; padding:4px 10px; font-size:10px; display:flex; justify-content:space-between; align-items:center; max-width:550px;">
                            <span id="detKantorPelayanan">—</span>
                            <span style="color:var(--text-muted); font-size:9px;">⌵</span>
                        </div>
                    </td>
                </tr>
            </table>

            <!-- Detail Form Inputs -->
            <div style="margin-bottom:16px; padding:0 4px;">
                <div class="detail-form-row">
                    <div class="detail-form-label">Nama Sarana Pengangkut</div>
                    <input type="text" id="detSarana" class="detail-input-readonly" readonly value="—">
                </div>
                <div class="detail-form-row">
                    <div class="detail-form-label">Nomor Voyage</div>
                    <input type="text" id="detVoyage" class="detail-input-readonly" readonly value="—">
                </div>
                <div class="detail-form-row">
                    <div class="detail-form-label">Nomor IMO / Registrasi</div>
                    <input type="text" id="detImo" class="detail-input-readonly" readonly value="—">
                </div>
                <div class="detail-form-row">
                    <div class="detail-form-label" id="detLabelTglDoc">Tanggal Berangkat</div>
                    <div style="position:relative; width:250px;">
                        <input type="text" id="detTanggalDoc" class="detail-input-readonly" style="width:100%; padding-right:24px;" readonly value="—">
                        <span style="position:absolute; right:8px; top:5px; color:var(--text-muted); font-size:11px;">📅</span>
                    </div>
                </div>
            </div>

            <!-- Card: Data Master BL -->
            <div class="master-bl-card">
                <div class="master-bl-card-header">
                    <span>Data Master BL</span>
                    <button type="button" class="btn-card-add" onclick="openHostToHostEditor(currentDetailAju)" title="Tambah / Edit Master BL di Host to Host">＋</button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="portal-table" style="margin:0;">
                        <thead>
                            <tr>
                                <th style="min-width:200px;">Kel. Pos</th>
                                <th style="min-width:120px;">
                                    <div class="th-content">
                                        <span>No Master</span>
                                        <span>🔍</span>
                                    </div>
                                </th>
                                <th style="min-width:100px;">
                                    <div class="th-content">
                                        <span>Tgl. Master</span>
                                        <span>📅</span>
                                    </div>
                                </th>
                                <th style="min-width:80px;">
                                    <div class="th-content">
                                        <span>Jml Pos</span>
                                        <span>🔍</span>
                                    </div>
                                </th>
                                <th style="min-width:85px;">POS BC11</th>
                                <th style="min-width:90px;">Respon</th>
                                <th style="min-width:70px;">STATUS</th>
                                <th style="min-width:85px;">Pos Status</th>
                                <th style="text-align:center; width:65px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="detMasterBlBody">
                            <tr>
                                <td colspan="9" style="text-align:center; padding:15px; color:var(--text-muted);">
                                    Memuat Data Master BL...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div style="padding:6px 12px; display:flex; justify-content:flex-end; border-top:1px solid var(--border); font-size:9.5px; color:var(--text-muted);">
                    <div style="display:inline-flex; align-items:center; gap:4px;">
                        <button type="button" class="page-btn" disabled>‹</button>
                        <button type="button" class="page-btn active">1</button>
                        <button type="button" class="page-btn" disabled>›</button>
                    </div>
                </div>
            </div>

            <!-- Bottom Action Navigation -->
            <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <button type="button" class="portal-tool-btn" onclick="closeDetailDoc()">
                    <span>← Kembali ke Browse Data NVOCC</span>
                </button>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="portal-tool-btn" onclick="refreshDetailDoc()">
                        <span>🔄 Refresh Data Dokumen</span>
                    </button>
                    <button type="button" class="ant-btn-ceisa-primary" onclick="openHostToHostEditor(currentDetailAju)">
                        <span>✏️ Buka di Editor Host to Host</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Dropdown Menu (Dinamis Sesuai Status Dokumen Portal CEISA 4.0) -->
    <div id="rowDropdownMenu" class="dropdown-menu"></div>

    <script>
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        const activeCmpId = <?= json_encode($sessionCmpId) ?>;
        const activeCmpNpwp = <?= json_encode($sessionNpwp) ?>;
        let cachedPortalToken = <?= json_encode($cachedToken) ?>;

        let tableData = [];
        let currentPage = 1;
        let pageSize = 25;
        let totalRecords = 0;
        let sortField = 'updated_at';
        let sortAsc = false;

        let activeTargetAju = '';
        let activeTargetRow = null;

        // Inisialisasi Tema
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                document.documentElement.setAttribute('data-theme', e.data.theme);
                document.body.setAttribute('data-theme', e.data.theme);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadPortalData();

            // Tutup dropdown menu saat klik di luar
            document.addEventListener('click', function(e) {
                const drop = document.getElementById('rowDropdownMenu');
                if (drop && !drop.contains(e.target) && !e.target.classList.contains('action-menu-trigger')) {
                    drop.style.display = 'none';
                }
            });
        });

        // 1. Load Data Table
        let hasAutoSynced = false;
        function loadPortalData(forceLiveSync = false) {
            const tbody = document.getElementById('portalTableBody');
            tbody.innerHTML = `<tr><td colspan="10" class="table-loading"><span class="spinner"></span> Memuat data dokumen NVOCC (Manifest Ceisa 4.0)...</td></tr>`;

            const search = document.getElementById('filterSearch').value.trim();
            const jenis = document.getElementById('filterJenis').value;
            const status = document.getElementById('filterStatus').value;

            // Jika force live sync atau load pertama kali tanpa filter
            if ((forceLiveSync || (!hasAutoSynced && cachedPortalToken)) && !search && !jenis && !status) {
                hasAutoSynced = true;
                const fd = new FormData();
                if (cachedPortalToken) {
                    fd.append('portal_token', cachedPortalToken);
                }
                fetch(`manifest_api.php?action=sync_ceisa_portal&cmp_id=${encodeURIComponent(activeCmpId)}`, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        fetchLocalList(search, jenis, status);
                    })
                    .catch(() => {
                        fetchLocalList(search, jenis, status);
                    });
                return;
            }

            fetchLocalList(search, jenis, status);
        }

        function fetchLocalList(search, jenis, status) {
            const tbody = document.getElementById('portalTableBody');
            const url = `manifest_api.php?action=get_list&source=portal&cmp_id=${encodeURIComponent(activeCmpId)}&page=${currentPage}&limit=${pageSize}&search=${encodeURIComponent(search || '')}&jenis_manifes=${encodeURIComponent(jenis || '')}&status=${encodeURIComponent(status || '')}&_t=${Date.now()}`;

            fetch(url)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        tableData = res.data || [];
                        totalRecords = (res.pagination && res.pagination.total) ? res.pagination.total : tableData.length;
                        renderTable();
                        renderPagination();
                        if (res.stats) {
                            renderMiniDashboard(res.stats);
                        }
                    } else {
                        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:#cf1322;">⚠️ ${res.message || 'Gagal memuat data.'}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:#cf1322;">⚠️ Terjadi kesalahan koneksi server: ${err.message}</td></tr>`;
                });
        }

        // 2. Render Table Rows
        function renderTable() {
            const tbody = document.getElementById('portalTableBody');
            if (tableData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:35px 20px; color:var(--text-muted);">
                    <div style="font-size:24px; margin-bottom:6px;">📭</div>
                    <div style="font-size:11px; font-weight:600; color:var(--text-heading);">Belum ada data dokumen NVOCC yang tersinkronisasi.</div>
                    <div style="font-size:9.5px; margin-top:4px;">Endpoint Sumber: <code>https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataProses/prosesNPWP-NVOCC/${activeCmpNpwp || ''}</code></div>
                    <div style="margin-top:12px; display:flex; gap:8px; justify-content:center;">
                        <button type="button" class="ant-btn-ceisa-primary" onclick="openSyncPortalModal()">
                            <span>🌐 Tarik dari CEISA 4.0 (Live API)</span>
                        </button>
                        <button type="button" class="ant-btn-ceisa-primary" style="background:#52c41a; border-color:#52c41a;" onclick="openHostToHostEditor()">
                            <span>➕ Pembuatan Dokumen Baru</span>
                        </button>
                    </div>
                </td></tr>`;
                return;
            }

            let html = '';
            tableData.forEach((row, idx) => {
                const aju = row.nomor_aju || '-';
                const ktr = row.kode_kantor || '-';
                const voy = row.nomor_voyage || '-';
                
                // Format Jenis Dokumen
                let jnsLabel = 'Inward (BC 1.1)';
                if (row.jenis_manifes === '12' || (row.jenis_manifes && String(row.jenis_manifes).toLowerCase().includes('outward'))) {
                    jnsLabel = 'Outward (BC 1.1)';
                }

                const noDaftar = row.nomor_bc11 || '';
                let tglDaftar = '';
                if (row.tanggal_bc11 && row.tanggal_bc11 !== '0000-00-00') {
                    const d = new Date(row.tanggal_bc11);
                    tglDaftar = String(d.getDate()).padStart(2, '0') + '-' + String(d.getMonth()+1).padStart(2, '0') + '-' + d.getFullYear();
                }

                // Format Tanggal Berangkat / Tiba
                let tglDoc = '';
                const rawTgl = row.tanggal_berangkat || row.tanggal_tiba || '';
                if (rawTgl && rawTgl !== '0000-00-00 00:00:00') {
                    const dt = new Date(rawTgl);
                    tglDoc = String(dt.getDate()).padStart(2, '0') + '-' + String(dt.getMonth()+1).padStart(2, '0') + '-' + dt.getFullYear();
                }

                const sarana = row.nama_sarana_pengangkut || '-';
                const status = (row.status || 'Draft').trim();
                const badgeClass = getStatusBadgeClass(status);

                html += `<tr>
                    <td><a class="aju-link" onclick="openDetailDocByAju('${aju}')" title="Klik untuk melihat Dokumen Header (CEISA 4.0)">${aju}</a></td>
                    <td>${ktr}</td>
                    <td><strong>${voy}</strong></td>
                    <td>${jnsLabel}</td>
                    <td>${noDaftar}</td>
                    <td>${tglDaftar}</td>
                    <td><span class="status-badge ${badgeClass}">${status}</span></td>
                    <td>
                        <a href="javascript:void(0)" class="vessel-link" onclick="openVesselManifestModal('${escapeHtml(sarana)}', '${escapeHtml(voy)}', '${escapeHtml(noDaftar)}', '${escapeHtml(tglDaftar)}', '${escapeHtml(aju)}', '${escapeHtml(ktr)}', '${escapeHtml(status)}')" title="Klik untuk menampilkan Jadwal Kedatangan & Informasi Manifes BC 1.1">
                            <span>🚢</span>
                            <span>${sarana}</span>
                        </a>
                    </td>
                    <td>${tglDoc}</td>
                    <td style="text-align:center;">
                        <button type="button" class="action-menu-trigger" onclick="openRowMenu(event, ${idx})">
                            <span>Menu</span>
                            <span style="font-size:8px;">▾</span>
                        </button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        // 3. Status Badge Mapper
        function getStatusBadgeClass(status) {
            const s = status.toLowerCase();
            if (s.includes('selesai') || s.includes('ready')) return 'status-ready';
            if (s.includes('penerimaan')) return 'status-penerimaan';
            if (s.includes('rekon') || s.includes('rikon')) return 'status-rekon';
            if (s.includes('persetujuan') || s.includes('perbaikan')) return 'status-persetujuan';
            if (s.includes('reject') || s.includes('tolak')) return 'status-reject';
            if (s.includes('kirim') || s.includes('terkirim')) return 'status-terkirim';
            return 'status-draft';
        }

        // 3.1 Mini Status Dashboard Handlers
        function renderMiniDashboard(stats) {
            if (!stats) return;
            const elTotal = document.getElementById('miniStatTotal');
            const elDraft = document.getElementById('miniStatDraft');
            const elReady = document.getElementById('miniStatReady');
            const elKirim = document.getElementById('miniStatTerkirim');
            const elRekon = document.getElementById('miniStatRekon');
            const elSelesai = document.getElementById('miniStatSelesai');
            const elReject = document.getElementById('miniStatReject');

            if (elTotal) elTotal.innerText = stats.total || 0;
            if (elDraft) elDraft.innerText = stats.draft || 0;
            if (elReady) elReady.innerText = stats.ready || 0;
            if (elKirim) elKirim.innerText = stats.terkirim || 0;
            if (elRekon) elRekon.innerText = stats.rekon || 0;
            if (elSelesai) elSelesai.innerText = stats.selesai || 0;
            if (elReject) elReject.innerText = stats.reject || 0;
        }

        let activeFilterStatus = '';
        function filterByStatus(st) {
            activeFilterStatus = st;
            const sel = document.getElementById('filterStatus');
            if (sel) sel.value = st;
            currentPage = 1;
            updateMiniCardsActive(st);
            fetchLocalList(document.getElementById('filterSearch').value.trim(), document.getElementById('filterJenis').value, st);
        }

        function updateMiniCardsActive(st) {
            const cards = document.querySelectorAll('.mini-card');
            cards.forEach(c => c.classList.remove('active'));
            if (!st) {
                document.querySelector('.mini-card-total')?.classList.add('active');
            } else {
                const sLow = st.toLowerCase();
                if (sLow.includes('draft')) document.querySelector('.mini-card-draft')?.classList.add('active');
                else if (sLow.includes('ready')) document.querySelector('.mini-card-ready')?.classList.add('active');
                else if (sLow.includes('terkirim') || sLow.includes('kirim')) document.querySelector('.mini-card-terkirim')?.classList.add('active');
                else if (sLow.includes('rekon')) document.querySelector('.mini-card-rekon')?.classList.add('active');
                else if (sLow.includes('selesai') || sLow.includes('penerimaan')) document.querySelector('.mini-card-selesai')?.classList.add('active');
                else if (sLow.includes('reject') || sLow.includes('tolak')) document.querySelector('.mini-card-reject')?.classList.add('active');
            }
        }

        // 3.2 Modal Sarana Angkut & Informasi Manifes BC 1.1 Handlers
        let activeVesselModalData = {
            sarana: '',
            voy: '',
            noDaftar: '',
            tglDaftar: '',
            aju: '',
            ktr: '',
            status: '',
            schedules: []
        };

        async function openVesselManifestModal(sarana, voy, noDaftar, tglDaftar, aju, ktr, status) {
            activeVesselModalData = { sarana, voy, noDaftar, tglDaftar, aju, ktr, status, schedules: [] };
            const modal = document.getElementById('modalVesselScheduleManifest');
            if (!modal) return;
            modal.style.display = 'flex';

            // 1. Tampilkan Informasi Dokumen Terpilih
            document.getElementById('lblModalVesselTitle').innerText = `Daftar Jadwal & Manifes: ${sarana}`;
            document.getElementById('lblModalAju').innerText = aju || '-';
            document.getElementById('lblModalSarana').innerText = sarana || '-';
            document.getElementById('lblModalVoyage').innerText = voy || '-';
            document.getElementById('lblModalKantor').innerText = ktr ? (ktr + ' - TMP TANJUNG PERAK') : '-';
            
            const badgeBc11 = document.getElementById('lblModalBc11No');
            const badgeTgl  = document.getElementById('lblModalBc11Tgl');
            const badgeSt   = document.getElementById('lblModalDocStatus');

            if (noDaftar && noDaftar !== '-' && noDaftar !== '') {
                badgeBc11.innerHTML = `<span style="background:#ecfdf5; color:#047857; padding:2px 8px; border-radius:4px; border:1px solid #a7f3d0; font-size:13px; font-weight:800;">${noDaftar}</span>`;
                badgeTgl.innerText = tglDaftar || '-';
            } else {
                badgeBc11.innerHTML = `<span style="color:#d97706; font-size:11px; font-weight:600;">⚠️ Belum Tercatat di Database</span>`;
                badgeTgl.innerText = '—';
            }

            badgeSt.className = 'status-badge ' + getStatusBadgeClass(status);
            badgeSt.innerText = status || 'DRAFT';

            // 2. Fetch Seluruh Jadwal Kedatangan Kapal via MapController
            const tbody = document.getElementById('tbodyModalVesselVoyages');
            const lblFound = document.getElementById('lblModalVesselFound');
            tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:25px; color:var(--text-muted);"><span class="spinner"></span> Menghubungi Gateway CEISA 4.0 MapController...</td></tr>`;
            lblFound.innerText = 'Mencari...';

            try {
                const res = await fetch(`getbl.php?action=search_vessel&vessel=${encodeURIComponent(sarana)}&cmp_id=${encodeURIComponent(activeCmpId)}`);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data) && json.data.length > 0) {
                    activeVesselModalData.schedules = json.data;
                    lblFound.innerText = `${json.data.length} Jadwal Ditemukan`;
                    renderModalVesselTable(json.data, voy, aju);
                } else {
                    activeVesselModalData.schedules = [];
                    lblFound.innerText = '0 Jadwal';
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; color:var(--text-muted); padding:25px;">${json.message || 'Tidak ada data jadwal kedatangan kapal dari Bea Cukai.'}</td></tr>`;
                }
            } catch (err) {
                lblFound.innerText = 'Error';
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; color:#cf1322; padding:25px;">Gagal memuat jadwal kapal: ${err.message}</td></tr>`;
            }
        }

        function renderModalVesselTable(list, targetVoyage, targetAju) {
            const tbody = document.getElementById('tbodyModalVesselVoyages');
            tbody.innerHTML = '';

            list.forEach((r, idx) => {
                const tr = document.createElement('tr');
                const voyMatch = targetVoyage && r.nomorVoyage && (r.nomorVoyage.trim().toUpperCase() === targetVoyage.trim().toUpperCase());
                
                if (voyMatch) {
                    tr.style.background = 'rgba(16, 185, 129, 0.12)';
                    tr.style.fontWeight = '600';
                }

                const asal = r.namaPelabuhanAsal || r.kodePelabuhanAsal || '-';
                const bongkar = r.namaPelabuhanBongkar || r.kodePelabuhanBongkar || '-';
                const kade = r.kade || '-';
                const tglDoc = r.tanggalTiba || r.tanggalBerangkat || '-';
                const isOutward = String(r.jenisManifes || '').toLowerCase().includes('outward') || (!r.tanggalTiba && r.tanggalBerangkat);
                const jBadge = isOutward ? '<span class="status-badge status-rekon" style="font-size:8px;">Outward</span>' : '<span class="status-badge status-terkirim" style="font-size:8px;">Inward</span>';

                const matchTag = voyMatch ? `<div style="font-size:8.5px; color:#059669; font-weight:700; margin-top:2px;">★ COCOK DENGAN AJU INI</div>` : '';

                tr.innerHTML = `
                    <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                    <td>
                        <strong style="color:var(--text-heading); font-size:10px;">${escapeHtml(r.nomorVoyage || '-')}</strong>
                        ${matchTag}
                    </td>
                    <td>
                        <strong style="color:#1890ff; font-size:11px;">${escapeHtml(r.nomorDaftar || '-')}</strong>
                    </td>
                    <td><span style="color:#059669; font-weight:600;">${escapeHtml(tglDoc)}</span></td>
                    <td><span class="badge-kantor">${escapeHtml(r.kodeKantor || '-')}</span></td>
                    <td>${jBadge}</td>
                    <td>${escapeHtml(r.tanggalDaftar || '-')}</td>
                    <td>
                        <div style="font-size:9.5px;">🚢 ${escapeHtml(asal)}</div>
                        <div style="font-size:9.5px; color:#1890ff;">⚓ ${escapeHtml(bongkar)}</div>
                    </td>
                    <td><span class="badge-kade">${escapeHtml(kade)}</span></td>
                    <td>
                        <div><strong>${r.jumlahPos || 0}</strong> Pos</div>
                        <div style="font-size:9px; color:var(--text-muted);">${r.jumlahContainer || 0} Cont</div>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <button type="button" class="ant-btn-ceisa-primary" style="height:22px; font-size:9px; padding:0 8px; ${voyMatch ? 'background:#059669; border-color:#047857;' : ''}" onclick="applyBc11FromSchedule('${escapeHtml(targetAju)}', '${escapeHtml(r.nomorDaftar || '')}', '${escapeHtml(r.tanggalDaftar || '')}', '${escapeHtml(r.nomorVoyage || '')}')" title="Terapkan nomor BC 1.1 dan tanggal ini ke AJU ${targetAju}">
                            <span>⚡ Terapkan</span>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        async function applyBc11FromSchedule(nomorAju, noBc11, tglBc11, voyage) {
            if (!noBc11) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Nomor Daftar BC 1.1 belum tersedia pada jadwal ini.' });
                return;
            }

            const confirm = await Swal.fire({
                title: 'Terapkan Nomor BC 1.1?',
                html: `Apakah Anda yakin ingin menerapkan <strong>Nomor BC 1.1: ${noBc11}</strong> (Tanggal: ${tglBc11}) ke Dokumen AJU <code>${nomorAju}</code>?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Terapkan Sekarang',
                cancelButtonText: 'Batal'
            });

            if (!confirm.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'sync_to_manifest');
            fd.append('nomor_aju', nomorAju);
            fd.append('nomor_bc11', noBc11);
            fd.append('tanggal_bc11', tglBc11);

            try {
                const res = await fetch('getbl.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Diterapkan!',
                        text: `Nomor BC 1.1 ${noBc11} berhasil disimpan ke dokumen AJU ${nomorAju}.`,
                        timer: 1800,
                        showConfirmButton: false
                    });
                    // Update tampilan modal
                    document.getElementById('lblModalBc11No').innerHTML = `<span style="background:#ecfdf5; color:#047857; padding:2px 8px; border-radius:4px; border:1px solid #a7f3d0; font-size:13px; font-weight:800;">${noBc11}</span>`;
                    document.getElementById('lblModalBc11Tgl').innerText = tglBc11;
                    // Reload table di background
                    fetchLocalList(document.getElementById('filterSearch').value.trim(), document.getElementById('filterJenis').value, document.getElementById('filterStatus').value);
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message || 'Terjadi kesalahan saat menyimpan.' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        function closeVesselScheduleModal() {
            const modal = document.getElementById('modalVesselScheduleManifest');
            if (modal) modal.style.display = 'none';
        }

        function openFullMonitoringForActiveVessel() {
            const sarana = activeVesselModalData.sarana || '';
            const voy = activeVesselModalData.voy || '';
            const url = `bc11_monitoring.php?tab=vessel&vessel=${encodeURIComponent(sarana)}&cmp_id=${encodeURIComponent(activeCmpId)}`;
            window.open(url, '_blank');
        }

        function copyModalVesselTable() {
            const list = activeVesselModalData.schedules || [];
            if (list.length === 0) {
                Swal.fire({ icon: 'info', title: 'Info', text: 'Tidak ada data jadwal kapal untuk disalin.' });
                return;
            }
            let tsv = "No\tNomor Voyage\tNomor Daftar (BC 1.1)\tTanggal Tiba Berangkat\tKode Kantor\tJenis Manifes\tTanggal Daftar\tPel Asal\tPel Bongkar\tKade\tJumlah Pos\tJumlah Container\tBerat Bruto\n";
            list.forEach((r, idx) => {
                const isOutward = String(r.jenisManifes || '').toLowerCase().includes('outward') || (!r.tanggalTiba && r.tanggalBerangkat);
                const jMan = isOutward ? 'Outward (BC 1.1)' : 'Inward (BC 1.1)';
                tsv += `${idx+1}\t${r.nomorVoyage||''}\t${r.nomorDaftar||''}\t${r.tanggalTiba||r.tanggalBerangkat||''}\t${r.kodeKantor||''}\t${jMan}\t${r.tanggalDaftar||''}\t${r.namaPelabuhanAsal||r.kodePelabuhanAsal||''}\t${r.namaPelabuhanBongkar||r.kodePelabuhanBongkar||''}\t${r.kade||''}\t${r.jumlahPos||0}\t${r.jumlahContainer||0}\t${r.berat||0}\n`;
            });
            navigator.clipboard.writeText(tsv).then(() => {
                Swal.fire({ icon: 'success', title: 'Disalin!', text: `${list.length} baris jadwal kapal disalin dalam format TSV (Siap di Excel).`, timer: 1500, showConfirmButton: false });
            });
        }

        // 4. Open Action Dropdown Menu (Dinamis 100% Mengikuti Portal Resmi CEISA 4.0)
        function openRowMenu(e, idx) {
            e.stopPropagation();
            const row = tableData[idx];
            if (!row) return;

            activeTargetRow = row;
            activeTargetAju = row.nomor_aju || '';

            const status = (row.status || '').trim().toUpperCase();
            const menu = document.getElementById('rowDropdownMenu');

            let itemsHtml = '';

            // 1. Opsi Standar CEISA 4.0: Riwayat
            itemsHtml += `
                <div class="dropdown-item" onclick="handleMenuAction('riwayat')">
                    <span style="font-size:11px;">🔄</span>
                    <span>Riwayat</span>
                </div>
            `;

            // Hanya tampilkan Cetak (*.pdf) jika BUKAN status Draft dan sudah memiliki ID NVOCC resmi
            if (!status.toUpperCase().includes('DRAFT') && activeTargetRow && activeTargetRow.id_nvocc_header) {
                itemsHtml += `
                    <div class="dropdown-item" onclick="handleMenuAction('cetak')">
                        <span style="font-size:11px;">🖨️</span>
                        <span>Cetak (*.pdf)</span>
                    </div>
                `;
            }

            // 2. Jika status DRAFT / READY: muncul Periksa Kelengkapan
            if (status.includes('DRAFT') || status.includes('READY')) {
                itemsHtml += `
                    <div class="dropdown-item" onclick="handleMenuAction('cek_lengkap')">
                        <span style="font-size:11px;">📋</span>
                        <span>Periksa Kelengkapan</span>
                    </div>
                `;
            }

            // 2b. Jika status READY: muncul Kirim Data
            if (status.includes('READY')) {
                itemsHtml += `
                    <div class="dropdown-item" onclick="handleMenuAction('kirim_data')" style="color:#52c41a; font-weight:600;">
                        <span style="font-size:11px;">🚀</span>
                        <span>Kirim Data</span>
                    </div>
                `;
            }

            // 3. Back-Up (Unduh backup JSON resmi dokumen)
            itemsHtml += `
                <div class="dropdown-item" onclick="handleMenuAction('backup')">
                    <span style="font-size:11px;">📥</span>
                    <span>Back-Up</span>
                </div>
            `;

            // 4. Lihat (Membuka Dokumen Header & Master BL)
            itemsHtml += `
                <div class="dropdown-item" onclick="handleMenuAction('lihat')" style="color:#1890ff; font-weight:600;">
                    <span style="font-size:11px;">👁️</span>
                    <span>Lihat</span>
                </div>
            `;

            // 5. Jika status PENERIMAAN atau PERSETUJUAN PERBAIKAN: Muncul Ajukan Redress
            if (status.includes('PENERIMAAN') || status.includes('PERBAIKAN') || status.includes('PERSETUJUAN')) {
                itemsHtml += `
                    <div class="dropdown-item" onclick="handleMenuAction('redress')">
                        <span style="font-size:11px;">📝</span>
                        <span>Ajukan Redress</span>
                    </div>
                `;
            }

            // 6. Buka di Host to Host (Opsi bantuan untuk melihat/mengedit di modul Host to Host)
            itemsHtml += `
                <div class="dropdown-divider"></div>
                <div class="dropdown-item" onclick="handleMenuAction('edit')" title="Buka data dokumen ini di modul Manifest Host to Host">
                    <span style="font-size:11px;">✏️</span>
                    <span>Buka di Host to Host</span>
                </div>
            `;

            menu.innerHTML = itemsHtml;
            menu.style.display = 'block';

            const rect = e.currentTarget.getBoundingClientRect();
            let top = rect.bottom + window.scrollY;
            let left = rect.left + window.scrollX - 110;

            if (left + 170 > window.innerWidth) {
                left = window.innerWidth - 180;
            }
            if (top + 220 > window.innerHeight) {
                top = rect.top + window.scrollY - 220;
            }

            menu.style.top = `${top}px`;
            menu.style.left = `${left}px`;
        }

        // 5. Handle Menu Actions
        function handleMenuAction(action) {
            const menu = document.getElementById('rowDropdownMenu');
            if (menu) menu.style.display = 'none';

            const aju = activeTargetAju;
            if (!aju) return;

            if (action === 'lihat') {
                openDetailDoc(activeTargetRow);
            } else if (action === 'riwayat') {
                showRiwayatStatus(activeTargetRow);
            } else if (action === 'cetak') {
                const idNvocc = activeTargetRow ? (activeTargetRow.id_nvocc_header || '') : '';
                cetakPdf(aju, idNvocc);
            } else if (action === 'backup') {
                backupJson(aju);
            } else if (action === 'cek_lengkap') {
                const idNvocc = activeTargetRow ? (activeTargetRow.id_nvocc_header || '') : '';
                checkKelengkapan(aju, idNvocc);
            } else if (action === 'kirim_data') {
                kirimDataPortal(activeTargetRow);
            } else if (action === 'redress') {
                ajukanRedress(activeTargetRow);
            } else if (action === 'edit') {
                openHostToHostEditor(aju);
            }
        }

        async function kirimDataPortal(row) {
            const aju = row ? row.nomor_aju : activeTargetAju;
            const resConfirm = await Swal.fire({
                title: 'Kirim Manifes ke Bea Cukai',
                html: `Kirim dokumen manifes <strong>${aju}</strong> ke sistem CEISA 4.0 Bea Cukai?<br><br><span style="color:var(--text-muted); font-size:11px;">Pastikan data telah diverifikasi lengkap (READY) sebelum dikirim.</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🚀 Ya, Kirim Sekarang',
                cancelButtonText: 'Batal'
            });
            if (!resConfirm.isConfirmed) return;

            Swal.fire({
                title: 'Mengirim Manifes ke Bea Cukai...',
                html: `Sedang memproses pengiriman AJU: <strong>${aju}</strong>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const fData = new FormData();
                fData.append('action', 'api_kirim');
                fData.append('nomor_aju', aju);
                fData.append('cmp_id', activeCmpId);

                const res = await fetch('manifest_api.php', { method: 'POST', body: fData });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        title: 'Berhasil Dikirim!',
                        html: json.message || 'Manifes berhasil dikirim ke Bea Cukai.',
                        icon: 'success',
                        confirmButtonColor: '#52c41a'
                    });
                    loadPortalData();
                } else {
                    Swal.fire({
                        title: 'Gagal Kirim Manifes',
                        html: json.message || 'Terjadi kesalahan saat mengirim dokumen.',
                        icon: 'error',
                        confirmButtonColor: '#ff4d4f'
                    });
                }
            } catch (err) {
                Swal.fire('Error', err.message, 'error');
            }
        }

        function ajukanRedress(row) {
            const aju = row ? row.nomor_aju : activeTargetAju;
            Swal.fire({
                title: 'Ajukan Redress Manifes',
                html: `Pengajuan perbaikan (Redress) dilakukan melalui modul <strong>Manifest Host to Host</strong>.<br><br>Nomor AJU: <strong>${aju}</strong><br><br>Apakah Anda ingin membuka dokumen ini di Host to Host sekarang?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '📝 Ya, Buka Host to Host',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    openHostToHostEditor(aju);
                }
            });
        }

        // Detail Document State & Handlers (Dokumen Header & Master BL)
        let currentDetailAju = '';
        let currentDetailIdNvocc = '';
        let currentDetailData = null;

        function openDetailDocByAju(aju) {
            const row = tableData.find(r => r.nomor_aju === aju) || { nomor_aju: aju };
            openDetailDoc(row);
        }

        function openDetailDoc(row) {
            if (!row) return;
            currentDetailAju = row.nomor_aju || '';
            currentDetailIdNvocc = row.id_nvocc_header || '';
            currentDetailData = row;

            // Update Header labels from row cache
            let jnsLabel = (row.jenis_manifes === '12' || (row.jenis_manifes && String(row.jenis_manifes).toLowerCase().includes('outward'))) ? 'Outward' : 'Inward';
            document.getElementById('detSubTitle').innerText = `${jnsLabel} (BC 1.1) - LAUT NVOCC`;
            document.getElementById('detNamaPerusahaan').innerText = row.nama_perusahaan || 'TVL GLOBAL LOGISTIK';
            document.getElementById('detAlamatPerusahaan').innerText = row.alamat_perusahaan || '—';
            document.getElementById('detNomorPengajuan').innerText = row.nomor_aju || '—';
            document.getElementById('detNomorDaftar').innerText = row.nomor_bc11 || '—';
            
            let tglDaftar = '—';
            if (row.tanggal_bc11 && row.tanggal_bc11 !== '0000-00-00') {
                const d = new Date(row.tanggal_bc11);
                tglDaftar = String(d.getDate()).padStart(2, '0') + '-' + String(d.getMonth()+1).padStart(2, '0') + '-' + d.getFullYear();
            }
            document.getElementById('detTanggalDaftar').innerText = tglDaftar;
            document.getElementById('detKantorPelayanan').innerText = row.kode_kantor ? (row.kode_kantor + ' - KPPBC TMP TANJUNG PERAK') : '—';

            document.getElementById('detSarana').value = row.nama_sarana_pengangkut || '—';
            document.getElementById('detVoyage').value = row.nomor_voyage || '—';
            document.getElementById('detImo').value = row.imo_number || '—';
            
            let tglDoc = '—';
            const rawTgl = row.tanggal_berangkat || row.tanggal_tiba || '';
            if (rawTgl && rawTgl !== '0000-00-00 00:00:00') {
                tglDoc = rawTgl;
            }
            document.getElementById('detTanggalDoc').value = tglDoc;

            // Switch view
            document.getElementById('viewBrowseSection').style.display = 'none';
            document.getElementById('viewDetailSection').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Fetch Live / Complete Detail from API GetOneNVOCCHeader
            refreshDetailDoc();
        }

        function closeDetailDoc() {
            document.getElementById('viewDetailSection').style.display = 'none';
            document.getElementById('viewBrowseSection').style.display = 'block';
        }

        function refreshDetailDoc() {
            const tbody = document.getElementById('detMasterBlBody');
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);"><span class="spinner"></span> Menghubungi Gateway CEISA 4.0 untuk detail dokumen & Master BL...</td></tr>`;

            const url = `manifest_api.php?action=get_detail_nvocc&id_nvocc_header=${encodeURIComponent(currentDetailIdNvocc)}&nomor_aju=${encodeURIComponent(currentDetailAju)}&cmp_id=${encodeURIComponent(activeCmpId)}&_t=${Date.now()}`;
            
            fetch(url)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK' && res.data) {
                        const d = res.data;
                        currentDetailData = d;
                        if (d.idNvoccHeader) currentDetailIdNvocc = d.idNvoccHeader;

                        // Perbarui data form
                        if (d.namaPerusahaan) document.getElementById('detNamaPerusahaan').innerText = d.namaPerusahaan;
                        if (d.alamatPerusahaan) document.getElementById('detAlamatPerusahaan').innerText = d.alamatPerusahaan;
                        if (d.nomorAju) document.getElementById('detNomorPengajuan').innerText = d.nomorAju;
                        if (d.nomorDaftar) document.getElementById('detNomorDaftar').innerText = d.nomorDaftar;
                        if (d.tanggalDaftar) document.getElementById('detTanggalDaftar').innerText = d.tanggalDaftar;
                        if (d.uraianKantor) document.getElementById('detKantorPelayanan').innerText = d.uraianKantor;
                        if (d.namaSaranaPengangkut) document.getElementById('detSarana').value = d.namaSaranaPengangkut;
                        if (d.nomorVoyage) document.getElementById('detVoyage').value = d.nomorVoyage;
                        if (d.imoNumber) document.getElementById('detImo').value = d.imoNumber;
                        if (d.tanggalBerangkat) document.getElementById('detTanggalDoc').value = d.tanggalBerangkat;
                        else if (d.tanggalTiba) document.getElementById('detTanggalDoc').value = d.tanggalTiba;

                        // Render Master BL List
                        const mbls = d.tdNVOCCMasters || [];
                        if (mbls.length === 0) {
                            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:18px; color:var(--text-muted);">Tidak ada data Master BL untuk dokumen ini.</td></tr>`;
                            return;
                        }

                        let mblHtml = '';
                        mbls.forEach((m, idx) => {
                            const uraianKel = m.uraianKelompokPos || (m.kelompokPos ? `Kelompok Pos ${m.kelompokPos}` : '-');
                            const mblNo = m.masterBl || '-';
                            const tglBl = m.tanggalBl || '-';
                            const jmlPos = m.jumlahPos ?? '-';
                            const posBc11 = m.posBc11 || '-';
                            const respon = m.respon || 'REKON';
                            const status = m.status || '—';
                            const posStatus = m.posStatus || '-';

                            mblHtml += `<tr>
                                <td>${uraianKel}</td>
                                <td><strong>${mblNo}</strong></td>
                                <td>${tglBl}</td>
                                <td>${jmlPos}</td>
                                <td><strong>${posBc11}</strong></td>
                                <td><span class="status-badge status-rekon">${respon}</span></td>
                                <td>${status}</td>
                                <td>${posStatus}</td>
                                <td style="text-align:center;">
                                    <button type="button" class="action-menu-trigger" onclick="openMasterBlAction(${idx})">
                                        <span>Aksi</span>
                                        <span style="font-size:8px;">▾</span>
                                    </button>
                                </td>
                            </tr>`;
                        });
                        tbody.innerHTML = mblHtml;
                    } else {
                        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:18px; color:#cf1322;">⚠️ ${res.message || 'Gagal memuat detail dari CEISA 4.0.'}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:18px; color:#cf1322;">⚠️ Terjadi kesalahan koneksi server: ${err.message}</td></tr>`;
                });
        }

        function doCekRekon() {
            Swal.fire({
                title: 'Mengecek Rekonsiliasi CEISA 4.0...',
                html: `Menghubungi endpoint Gateway Bea Cukai untuk memeriksa status Rekon AJU:<br><strong>${currentDetailAju}</strong>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch(`manifest_api.php?action=cek_rekon&id_nvocc_header=${encodeURIComponent(currentDetailIdNvocc)}&nomor_aju=${encodeURIComponent(currentDetailAju)}&cmp_id=${encodeURIComponent(activeCmpId)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        Swal.fire({
                            title: 'Status Rekon Terkonfirmasi',
                            html: `<div style="text-align:center; font-size:11px; line-height:1.6;">
                                <div style="font-size:32px; margin-bottom:8px;">🔄</div>
                                <strong>${res.message}</strong><br>
                                <span style="color:var(--text-muted); font-size:9.5px;">Waktu Respon: ${res.waktu_rekon || new Date().toLocaleString()}</span>
                            </div>`,
                            icon: 'success',
                            confirmButtonColor: '#1890ff'
                        });
                        refreshDetailDoc();
                    } else {
                        Swal.fire({
                            title: 'Informasi Rekonsiliasi',
                            html: res.message || 'Belum ada update respon rekon terbaru.',
                            icon: 'info',
                            confirmButtonColor: '#1890ff'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({ title: 'Error Koneksi', text: err.message, icon: 'error' });
                });
        }

        function openMasterBlAction(idx) {
            if (!currentDetailData || !currentDetailData.tdNVOCCMasters || !currentDetailData.tdNVOCCMasters[idx]) return;
            const m = currentDetailData.tdNVOCCMasters[idx];
            Swal.fire({
                title: `Master B/L: ${m.masterBl}`,
                html: `
                    <div style="text-align:left; font-size:10.5px; line-height:1.7; background:var(--bg-input); padding:12px; border-radius:4px; border:1px solid var(--border);">
                        <strong>Kelompok Pos:</strong> ${m.uraianKelompokPos || m.kelompokPos}<br>
                        <strong>Tanggal B/L:</strong> ${m.tanggalBl || '-'}<br>
                        <strong>Jumlah Pos:</strong> ${m.jumlahPos || 1}<br>
                        <strong>Jumlah Kontainer:</strong> ${m.jumlahContainer || 0}<br>
                        <strong>POS BC 1.1:</strong> ${m.posBc11 || '-'}<br>
                        <strong>Respon CEISA:</strong> <span class="status-badge status-rekon">${m.respon || 'REKON'}</span><br>
                        <strong>Waktu Rekon:</strong> ${m.waktuRekon || '-'}<br>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: '✏️ Kelola di Host to Host',
                cancelButtonText: 'Tutup'
            }).then(r => {
                if (r.isConfirmed) {
                    openHostToHostEditor(currentDetailAju);
                }
            });
        }

        async function showRiwayatStatus(row) {
            const aju = row ? row.nomor_aju : activeTargetAju;
            if (!aju) return;

            Swal.fire({
                title: 'Riwayat Dokumen',
                html: '<div style="padding:20px 0; text-align:center;"><div class="spinner-border text-primary" role="status"></div><div style="font-size:11px; margin-top:8px; color:var(--text-muted);">Memuat riwayat dari CEISA 4.0...</div></div>',
                showConfirmButton: false,
                allowOutsideClick: false,
                width: 580
            });

            try {
                const res = await fetch(`manifest_api.php?action=get_portal_history&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(activeCmpId)}`);
                const json = await res.json();

                if (json.status !== 'OK' || !json.timeline || json.timeline.length === 0) {
                    Swal.fire({
                        title: 'Riwayat Dokumen',
                        html: `
                            <div style="text-align:left; font-size:11px; padding:10px 0;">
                                <strong>Nomor AJU:</strong> ${escapeHtml(aju)}<br>
                                <div style="margin-top:12px; color:var(--text-muted); text-align:center;">
                                    Belum ada catatan riwayat respon untuk dokumen ini di server CEISA 4.0.
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'Tutup',
                        confirmButtonColor: '#1890ff',
                        width: 500
                    });
                    return;
                }

                // Render Timeline Visual persis seperti Portal CEISA 4.0
                let tlHtml = `
                    <div style="text-align:left; max-height:420px; overflow-y:auto; padding:10px 15px 5px; font-family:'Segoe UI',sans-serif;">
                        <div style="font-size:10px; color:#8c8c8c; margin-bottom:14px; padding-bottom:6px; border-bottom:1px solid #f0f0f0;">
                            NOMOR AJU: <strong style="color:#262626;">${escapeHtml(aju)}</strong>
                        </div>
                        <div style="position:relative; padding-left:26px; border-left:2px solid #e8e8e8; margin-left:14px;">
                `;

                json.timeline.forEach((item, idx) => {
                    const isLast = (idx === json.timeline.length - 1);
                    const color = item.color || '#1890ff';
                    const icon = item.icon || 'fa-check-circle';

                    tlHtml += `
                        <div style="position:relative; margin-bottom:20px;">
                            <div style="position:absolute; left:-34px; top:0; width:18px; height:18px; border-radius:50%; background:#fff; border:2px solid ${color}; display:flex; align-items:center; justify-content:center; font-size:9px; color:${color};">
                                <i class="fas ${icon}"></i>
                            </div>
                            <div style="font-size:10px; color:#8c8c8c; margin-bottom:2px;">
                                ${escapeHtml(item.time)}
                            </div>
                            <div style="font-size:11.5px; font-weight:600; color:#262626;">
                                ${escapeHtml(item.title)}
                            </div>
                        </div>
                    `;
                });

                tlHtml += `
                        </div>
                    </div>
                `;

                Swal.fire({
                    title: 'Riwayat Dokumen',
                    html: tlHtml,
                    width: 580,
                    showCancelButton: true,
                    cancelButtonText: 'Cancel',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#1890ff',
                    cancelButtonColor: '#d9d9d9'
                });
            } catch (err) {
                Swal.fire({
                    title: 'Error',
                    text: 'Gagal memuat riwayat: ' + err.message,
                    icon: 'error'
                });
            }
        }

        function cetakPdf(aju, idNvocc = '') {
            const url = `pdf_manifest.php?nomor_aju=${encodeURIComponent(aju || '')}&id_nvocc_header=${encodeURIComponent(idNvocc || '')}&cmp_id=${encodeURIComponent(activeCmpId)}`;
            window.open(url, '_blank');
        }

        function backupJson(aju) {
            window.location.href = `manifest_api.php?action=generate_json&download=1&nomor_aju=${encodeURIComponent(aju)}&cmp_id=${encodeURIComponent(activeCmpId)}`;
        }

        // 6. Navigation to Host to Host Editor
        function openHostToHostEditor(nomorAju) {
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('manifest', nomorAju || '');
            } else {
                window.location.href = `manifest.php?cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju || '')}`;
            }
        }

        // 7. Cek Status CEISA
        function checkStatusCeisa(nomorAju) {
            Swal.fire({
                title: 'Mengecek Status CEISA...',
                html: `Menghubungi server Bea Cukai untuk Nomor AJU:<br><strong>${nomorAju}</strong>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch(`manifest_api.php?action=api_status&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        const d = res.data;
                        let detailHtml = `<div style="text-align:left; font-size:11px; line-height:1.6; background:var(--bg-input); padding:10px; border-radius:4px; border:1px solid var(--border);">`;
                        detailHtml += `<strong>Nomor AJU:</strong> ${nomorAju}<br>`;
                        if (typeof d === 'object') {
                            for (const [k, v] of Object.entries(d)) {
                                if (typeof v !== 'object') {
                                    detailHtml += `<strong>${k}:</strong> ${v}<br>`;
                                }
                            }
                        } else {
                            detailHtml += d;
                        }
                        detailHtml += `</div>`;

                        Swal.fire({
                            title: 'Status Respon CEISA 4.0',
                            html: detailHtml,
                            icon: 'info',
                            confirmButtonColor: '#1890ff'
                        });
                        loadPortalData();
                    } else if (res.status === 'WARNING') {
                        Swal.fire({
                            title: 'Informasi Status',
                            html: res.message,
                            icon: 'warning',
                            confirmButtonColor: '#1890ff'
                        });
                    } else {
                        Swal.fire({
                            title: 'Respon Cek Status',
                            html: res.message || 'Gagal memeriksa status respon CEISA.',
                            icon: 'info',
                            confirmButtonColor: '#1890ff'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({
                        title: 'Error Koneksi',
                        text: err.message,
                        icon: 'error',
                        confirmButtonColor: '#ff4d4f'
                    });
                });
        }

        // 8. Cek Kelengkapan
        function checkKelengkapan(nomorAju, idNvocc = '') {
            if (!idNvocc && activeTargetRow && activeTargetRow.id_nvocc_header) {
                idNvocc = activeTargetRow.id_nvocc_header;
            }
            Swal.fire({
                title: 'Memvalidasi Kelengkapan Dokumen...',
                html: `Memeriksa status kelengkapan manifes di Gateway CEISA 4.0:<br><strong>${escapeHtml(nomorAju)}</strong>`,
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'api_cek_lengkap');
            fd.append('cmp_id', activeCmpId);
            fd.append('nomor_aju', nomorAju);
            if (idNvocc) {
                fd.append('id_nvocc_header', idNvocc);
            }

            fetch(`manifest_api.php`, {
                method: 'POST',
                body: fd
            })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        Swal.fire({
                            title: 'Status Lengkap (READY)',
                            html: res.message,
                            icon: 'success',
                            confirmButtonColor: '#52c41a'
                        });
                        loadPortalData();
                    } else {
                        Swal.fire({
                            title: 'Kendala Kelengkapan Data',
                            html: res.message || 'Data manifes belum lengkap.',
                            icon: 'warning',
                            confirmButtonColor: '#fa8c16'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({ title: 'Error', text: err.message, icon: 'error' });
                });
        }

        // 9. Kirim ke Bea Cukai
        function kirimCeisa(nomorAju) {
            Swal.fire({
                title: 'Kirim Manifes ke Bea Cukai?',
                html: `Anda akan mengirim data Manifes NVOCC dengan Nomor AJU:<br><strong>${nomorAju}</strong><br><br><small style="color:var(--text-muted);">Pastikan data telah berstatus READY dan telah dicek kelengkapannya.</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🚀 Ya, Kirim Sekarang',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    Swal.fire({
                        title: 'Mengirim Manifes ke CEISA 4.0...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    fetch(`manifest_api.php?action=api_kirim&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`, { method: 'POST' })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({
                                    title: 'Berhasil Terkirim!',
                                    html: res.message,
                                    icon: 'success',
                                    confirmButtonColor: '#52c41a'
                                });
                                loadPortalData();
                            } else {
                                Swal.fire({
                                    title: 'Gagal Kirim',
                                    html: res.message,
                                    icon: 'error',
                                    confirmButtonColor: '#ff4d4f'
                                });
                            }
                        })
                        .catch(err => Swal.fire({ title: 'Error', text: err.message, icon: 'error' }));
                }
            });
        }

        // 10. Rekon Manifes
        function rekonCeisa(nomorAju) {
            Swal.fire({
                title: 'Proses Rekonsiliasi (Pecah Pos)?',
                html: `Kirim perintah rekonsiliasi pemecahan pos Manifes NVOCC untuk AJU:<br><strong>${nomorAju}</strong>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#fa8c16',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🔄 Rekon Sekarang',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    Swal.fire({ title: 'Memproses Rekonsiliasi...', didOpen: () => Swal.showLoading() });
                    fetch(`manifest_api.php?action=api_rekon&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`, { method: 'POST' })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({ title: 'Rekon Berhasil', html: res.message, icon: 'success' });
                                loadPortalData();
                            } else {
                                Swal.fire({ title: 'Gagal Rekon', html: res.message, icon: 'error' });
                            }
                        });
                }
            });
        }

        // 11. View JSON Payload
        function viewJsonPayload(nomorAju) {
            Swal.fire({ title: 'Menyiapkan JSON Payload...', didOpen: () => Swal.showLoading() });
            fetch(`manifest_api.php?action=generate_json&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        const jsonPretty = JSON.stringify(res.payload, null, 2);
                        Swal.fire({
                            title: `Payload JSON - ${nomorAju}`,
                            html: `
                                <div style="text-align:right; margin-bottom:6px;">
                                    <button class="ant-btn-ceisa-primary" style="height:20px; font-size:9px;" onclick="navigator.clipboard.writeText(document.getElementById('txtJsonPayload').value); Swal.showValidationMessage('✅ JSON berhasil disalin!');">📋 Salin JSON</button>
                                </div>
                                <textarea id="txtJsonPayload" readonly style="width:100%; height:280px; font-family:monospace; font-size:10px; background:var(--bg-input); color:var(--text-main); border:1px solid var(--border); padding:8px; border-radius:3px; outline:none; resize:vertical;">${jsonPretty}</textarea>
                            `,
                            width: 680,
                            confirmButtonColor: '#1890ff',
                            confirmButtonText: 'Tutup'
                        });
                    } else {
                        Swal.fire({ title: 'Gagal', html: res.message, icon: 'error' });
                    }
                });
        }

        // 12. Copy AJU
        function copyAju(nomorAju) {
            Swal.fire({
                title: 'Duplikasi / Copy AJU Manifes',
                html: `Duplikasi dokumen AJU: <strong>${nomorAju}</strong> beserta seluruh House B/L, Petikemas, dan HS barang ke AJU baru.<br><br>
                <div style="text-align:left; font-size:10px; margin-bottom:4px; font-weight:600; color:var(--text-muted);">NOMOR AJU BARU (Biarkan kosong untuk generate otomatis 26 digit):</div>
                <input type="text" id="swalTargetAju" class="filter-input" placeholder="Generate otomatis..." style="width:100%; height:26px;">`,
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: '📑 Duplikasi Sekarang',
                cancelButtonText: 'Batal',
                preConfirm: () => {
                    return document.getElementById('swalTargetAju').value.trim();
                }
            }).then(r => {
                if (r.isConfirmed) {
                    const targetAju = r.value || '';
                    Swal.fire({ title: 'Menduplikasi Manifes...', didOpen: () => Swal.showLoading() });

                    const fd = new FormData();
                    fd.append('source_nomor_aju', nomorAju);
                    fd.append('target_nomor_aju', targetAju);
                    fd.append('target_cmp_id', activeCmpId);

                    fetch(`manifest_api.php?action=copy_aju&cmp_id=${encodeURIComponent(activeCmpId)}`, { method: 'POST', body: fd })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({ title: 'Duplikasi Berhasil!', html: res.message, icon: 'success' });
                                loadPortalData();
                            } else {
                                Swal.fire({ title: 'Gagal Duplikasi', html: res.message, icon: 'error' });
                            }
                        });
                }
            });
        }

        // 13. Hapus Dokumen
        function deleteDokumen(nomorAju) {
            Swal.fire({
                title: 'Hapus Dokumen Manifes?',
                html: `Apakah Anda yakin ingin menghapus dokumen Manifes AJU:<br><strong>${nomorAju}</strong>?<br><br><span style="color:#cf1322;">Seluruh data House B/L, kemasan, dan petikemas terkait akan dihapus secara permanen.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#cf1322',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🗑️ Ya, Hapus',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    Swal.fire({ title: 'Menghapus Manifes...', didOpen: () => Swal.showLoading() });
                    fetch(`manifest_api.php?action=delete_manifes&cmp_id=${encodeURIComponent(activeCmpId)}&nomor_aju=${encodeURIComponent(nomorAju)}`, { method: 'POST' })
                        .then(res => res.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire({ title: 'Terhapus', text: res.message, icon: 'success' });
                                loadPortalData();
                            } else {
                                Swal.fire({ title: 'Gagal', html: res.message, icon: 'error' });
                            }
                        });
                }
            });
        }

        // 14. Live Tarik Data dari Portal CEISA 4.0
        function openSyncPortalModal(manualToken = null) {
            Swal.fire({
                title: 'Menyinkronkan dengan CEISA 4.0...',
                html: '<div style="font-size:11px; margin-top:8px; color:var(--text-muted);">Menghubungi endpoint Gateway Bea Cukai...</div>',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            if (manualToken) {
                fd.append('portal_token', manualToken);
            }

            fetch(`manifest_api.php?action=sync_ceisa_portal&cmp_id=${encodeURIComponent(activeCmpId)}`, {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'OK') {
                    Swal.fire({
                        title: 'Sinkronisasi Selesai!',
                        html: res.message,
                        icon: 'success',
                        confirmButtonColor: '#52c41a'
                    });
                    loadPortalData();
                } else if (res.status === 'NEED_TOKEN') {
                    Swal.fire({
                        title: 'Token MFA Belum Siap',
                        html: `<div style="font-size:11px; text-align:left; color:#f87171;">${res.message || 'Gagal menghasilkan token MFA otomatis.'}</div><div style="font-size:10px; margin-top:8px; color:var(--text-muted);">Pastikan data login CEISA & Secret MFA tersimpan pada profil perusahaan aktif.</div>`,
                        icon: 'warning',
                        confirmButtonColor: '#faad14'
                    });
                } else {
                    Swal.fire({
                        title: 'Gagal Sinkronisasi',
                        html: res.message || 'Terjadi kesalahan saat menghubungi API Bea Cukai.',
                        icon: 'error',
                        confirmButtonColor: '#ff4d4f'
                    });
                }
            })
            .catch(err => {
                Swal.fire({ title: 'Error', text: err.message, icon: 'error' });
            });
        }

        // 15. Restore Data Modal
        function openRestoreDataModal() {
            Swal.fire({
                title: '📥 Restore Data Manifes',
                html: `
                    <div style="text-align:left; font-size:10.5px; line-height:1.5;">
                        Pilih file Excel Backup Manifes (.xlsx) atau file JSON Dokumen untuk mengembalikan (restore) data lengkap termasuk House B/L, HS, dan petikemas ke sistem.
                    </div>
                    <div style="margin-top:14px; text-align:left;">
                        <input type="file" id="restoreFile" accept=".xlsx,.xls,.json" style="font-size:10px;">
                    </div>
                `,
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: 'Unggah & Restore',
                cancelButtonText: 'Batal'
            }).then(r => {
                if (r.isConfirmed) {
                    const fileInput = document.getElementById('restoreFile');
                    if (!fileInput.files || fileInput.files.length === 0) {
                        Swal.fire({ title: 'Peringatan', text: 'Silakan pilih file terlebih dahulu.', icon: 'warning' });
                        return;
                    }
                    // Buka import di Host to Host
                    openHostToHostEditor();
                }
            });
        }

        // 16. Filter Drawer Toggle
        function toggleFilterDrawer() {
            const drw = document.getElementById('filterDrawer');
            drw.classList.toggle('open');
        }

        function resetFilterDrawer() {
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterJenis').value = '';
            document.getElementById('filterStatus').value = '';
            loadPortalData();
        }

        // 17. Pagination Handlers
        function changePage(p) {
            const maxPage = Math.max(1, Math.ceil(totalRecords / pageSize));
            if (p < 1 || p > maxPage) return;
            currentPage = p;
            loadPortalData();
        }

        function changePageSize(sz) {
            pageSize = parseInt(sz, 10);
            currentPage = 1;
            loadPortalData();
        }

        function renderPagination() {
            const maxPage = Math.max(1, Math.ceil(totalRecords / pageSize));
            document.getElementById('pageInfo').innerText = `Menampilkan ${tableData.length} dari total ${totalRecords} data`;
            document.getElementById('btnPrevPage').disabled = (currentPage <= 1);
            document.getElementById('btnNextPage').disabled = (currentPage >= maxPage);

            const numContainer = document.getElementById('pageNumbers');
            numContainer.innerHTML = '';

            for (let i = 1; i <= maxPage; i++) {
                if (i === 1 || i === maxPage || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `page-btn ${i === currentPage ? 'active' : ''}`;
                    btn.innerText = i;
                    btn.onclick = () => changePage(i);
                    numContainer.appendChild(btn);
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    const dots = document.createElement('span');
                    dots.innerText = '...';
                    dots.style.padding = '0 2px';
                    dots.style.color = 'var(--text-muted)';
                    numContainer.appendChild(dots);
                }
            }
        }

        function toggleSort(field) {
            if (sortField === field) {
                sortAsc = !sortAsc;
            } else {
                sortField = field;
                sortAsc = true;
            }
            tableData.sort((a, b) => {
                let vA = (a[field] || '').toString().toLowerCase();
                let vB = (b[field] || '').toString().toLowerCase();
                return sortAsc ? vA.localeCompare(vB) : vB.localeCompare(vA);
            });
            renderTable();
        }
    </script>

    <!-- Modal: Jadwal & Manifes Kedatangan Sarana Pengangkut (MapController) -->
    <div id="modalVesselScheduleManifest" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.65); backdrop-filter:blur(3px); z-index:10000; align-items:center; justify-content:center; padding:16px;" onclick="if(event.target===this) closeVesselScheduleModal()">
        <div class="modal-box" style="background:var(--bg-card); border-radius:10px; max-width:880px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 10px 25px rgba(0,0,0,0.25); border:1px solid var(--border); overflow:hidden;">
            <div class="modal-header" style="padding:12px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; background:var(--table-head-bg);">
                <div>
                    <div style="font-size:13.5px; font-weight:700; color:var(--text-heading); display:flex; align-items:center; gap:8px;">
                        <span style="font-size:16px;">🚢</span>
                        <span id="lblModalVesselTitle">Daftar Jadwal & Manifes: -</span>
                    </div>
                    <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                        Integrasi Layanan OpenAPI CEISA 4.0 MapController & Informasi Manifes BC 1.1 Bea Cukai
                    </div>
                </div>
                <button type="button" class="btn-card-add" style="padding:3px 8px; font-size:13px; font-weight:700;" onclick="closeVesselScheduleModal()">✕</button>
            </div>
            
            <div class="modal-body" style="padding:14px 18px; overflow-y:auto; flex:1;">
                <!-- 1. Informasi Manifes BC 1.1 Dokumen Terpilih Ini -->
                <div style="background:rgba(24,144,255,0.04); border:1px solid rgba(24,144,255,0.25); border-radius:6px; padding:12px 16px; margin-bottom:14px;">
                    <div style="font-size:11px; font-weight:700; color:#1890ff; display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                        <span><span style="font-size:12px;">📋</span> INFORMASI MANIFES BC 1.1 DOKUMEN INI (BEA CUKAI)</span>
                        <span id="lblModalDocStatus" class="status-badge status-ready">-</span>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; font-size:10.5px;">
                        <div>
                            <span style="color:var(--text-muted); font-size:9.5px; text-transform:uppercase;">Nomor Pengajuan (AJU):</span><br>
                            <strong id="lblModalAju" style="color:var(--text-heading); font-family:Consolas, monospace;">-</strong>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); font-size:9.5px; text-transform:uppercase;">Nomor Daftar (BC 1.1):</span><br>
                            <span id="lblModalBc11No"><strong style="color:#059669; font-size:13px;">-</strong></span>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); font-size:9.5px; text-transform:uppercase;">Tanggal Daftar (BC 1.1):</span><br>
                            <strong id="lblModalBc11Tgl" style="color:var(--text-heading);">-</strong>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); font-size:9.5px; text-transform:uppercase;">Nomor Voyage / Flight:</span><br>
                            <strong id="lblModalVoyage" style="color:var(--text-heading);">-</strong>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); font-size:9.5px; text-transform:uppercase;">Kantor Pelayanan:</span><br>
                            <span id="lblModalKantor" class="badge-kantor">-</span>
                        </div>
                        <div>
                            <span style="color:var(--text-muted); font-size:9.5px; text-transform:uppercase;">Sarana Pengangkut:</span><br>
                            <strong id="lblModalSarana" style="color:#0284c7; font-size:11.5px;">-</strong>
                        </div>
                    </div>
                </div>

                <!-- 2. Daftar Jadwal & Manifes Kedatangan Kapal (MapController) -->
                <div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-heading); display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                        <span><span style="font-size:12px;">🚢</span> DAFTAR JADWAL & MANIFES KEDATANGAN KAPAL INI (CEISA 4.0 MAPCONTROLLER)</span>
                        <span id="lblModalVesselFound" style="font-size:10px; color:var(--text-muted);">0 Jadwal</span>
                    </div>
                    
                    <div class="table-responsive" style="max-height:280px; overflow-y:auto;">
                        <table class="ceisa-table" id="tableModalVesselVoyages">
                            <thead>
                                <tr>
                                    <th style="width:25px; text-align:center;">No</th>
                                    <th>Nomor Voyage</th>
                                    <th>Nomor Daftar (BC 1.1)</th>
                                    <th>Tanggal Tiba / Berangkat</th>
                                    <th>Kantor</th>
                                    <th>Jenis Manifes</th>
                                    <th>Tanggal Daftar</th>
                                    <th>Pel. Asal & Bongkar</th>
                                    <th>Kade / TPS</th>
                                    <th>Pos / Cont</th>
                                    <th style="text-align:right; width:100px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyModalVesselVoyages">
                                <tr><td colspan="11" style="text-align:center; padding:25px; color:var(--text-muted);"><span class="spinner"></span> Menghubungi Gateway CEISA 4.0 MapController...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="padding:10px 18px; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; background:var(--table-head-bg);">
                <button type="button" class="portal-tool-btn" onclick="openFullMonitoringForActiveVessel()" style="color:#1890ff; font-weight:600;">
                    <span>🌐 Buka di Full Monitoring BC 1.1</span>
                </button>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="portal-tool-btn" onclick="copyModalVesselTable()" style="color:var(--text-main);">
                        <span>📋 Salin Jadwal (TSV)</span>
                    </button>
                    <button type="button" class="ant-btn-ceisa-primary" style="height:26px; padding:0 14px;" onclick="closeVesselScheduleModal()">
                        <span>Tutup</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

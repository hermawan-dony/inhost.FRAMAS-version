<?php
/**
 * CEISA 4.0 - MFA TOTP Token Generator API (api_mfa.php)
 * Menghasilkan kode OTP 6-digit menggunakan GoogleAuthenticator.php
 * Mendukung sinkronisasi waktu dan hitung mundur detik.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/GoogleAuthenticator.php';

$action = $_REQUEST['action'] ?? 'get_otp';
$isAdmin = !empty($_SESSION['is_admin']) || (isset($_REQUEST['is_admin']) && $_REQUEST['is_admin'] === '1');
$cmp_id = trim($_REQUEST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '')));

// Keamanan: Jika bukan admin dan ada session company, paksa cmp_id ke session perusahaan
if (!$isAdmin && !empty($_SESSION['company_cmp_id'])) {
    $cmp_id = $_SESSION['company_cmp_id'];
} elseif (!$isAdmin && !empty($_SESSION['cmp_id'])) {
    $cmp_id = $_SESSION['cmp_id'];
}

// Fallback jika belum ada cmp_id di session
if (empty($cmp_id)) {
    $cRes = $koneksi->query("SELECT cmp_id FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_name ASC LIMIT 1");
    if ($cRes && $cr = $cRes->fetch_assoc()) {
        $cmp_id = $cr['cmp_id'];
    }
}

// -------------------------------------------------------------
// 1. GET LIST COMPANIES WITH MFA (Untuk Switcher Admin di Pop-up MFA)
// -------------------------------------------------------------
if ($action === 'list_companies') {
    $list = [];
    $whereSql = $isAdmin ? "WHERE is_active = 1" : "WHERE cmp_id = '" . $koneksi->real_escape_string($cmp_id) . "'";
    $res = $koneksi->query("SELECT cmp_id, cmp_name, npwp, username_ceisa, IF(secret_mfa IS NOT NULL AND secret_mfa != '', 1, 0) as has_secret FROM bc_ceisa_company $whereSql ORDER BY cmp_name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'cmp_id' => $r['cmp_id'],
                'cmp_name' => $r['cmp_name'],
                'npwp' => $r['npwp'],
                'username' => $r['username_ceisa'] ?: 'user',
                'has_secret' => (bool)$r['has_secret']
            ];
        }
    }
    echo json_encode(['status' => 'OK', 'companies' => $list]);
    exit;
}

// -----------------------------------------------------------------
// 2. GET CURRENT OTP CODE
// -----------------------------------------------------------------
$stmt = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, username_ceisa, secret_mfa FROM bc_ceisa_company WHERE cmp_id = ? OR npwp = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $koneksi->error]);
    exit;
}

$stmt->bind_param("ss", $cmp_id, $cmp_id);
$stmt->execute();
$res = $stmt->get_result();
$company = $res->fetch_assoc();
$stmt->close();

if (!$company) {
    echo json_encode(['status' => 'ERROR', 'message' => 'Data perusahaan tidak ditemukan.']);
    exit;
}

$secret = trim($company['secret_mfa'] ?? '');
if (empty($secret)) {
    echo json_encode([
        'status' => 'EMPTY_SECRET',
        'cmp_id' => $company['cmp_id'],
        'cmp_name' => $company['cmp_name'],
        'username' => $company['username_ceisa'] ?: 'user',
        'issuer' => 'portal-beacukai',
        'message' => 'Secret MFA belum dikonfigurasi untuk perusahaan ini di tabel bc_ceisa_company.'
    ]);
    exit;
}

try {
    $ga = new PHPGangsta_GoogleAuthenticator();
    
    // Hitung waktu dan detik tersisa dalam window 30 detik
    $now = time();
    $timeSlice = floor($now / 30);
    $remaining = 30 - ($now % 30);
    
    $otp = $ga->getCode($secret, $timeSlice);

    echo json_encode([
        'status' => 'OK',
        'otp' => (string)$otp,
        'issuer' => 'portal-beacukai',
        'username' => $company['username_ceisa'] ?: 'maikelwawoh',
        'cmp_id' => $company['cmp_id'],
        'cmp_name' => $company['cmp_name'],
        'remaining_seconds' => $remaining,
        'server_time' => $now
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'ERROR',
        'message' => 'Gagal menghitung TOTP: ' . $e->getMessage()
    ]);
}

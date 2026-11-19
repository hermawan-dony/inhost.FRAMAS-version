<?php
/**
 * CEISA 4.0 - MFA TOTP Token Generator API (api_mfa.php)
 * Menghasilkan kode OTP 6-digit menggunakan GoogleAuthenticator.php
 * Mendukung CEISA 4.0 dan Portal INSW / LNSW (NSWId).
 * Mendukung sinkronisasi waktu dan hitung mundur detik.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/GoogleAuthenticator.php';

// Auto check / add column secret_mfa_insw jika belum ada di database
$chkColMfaInsw = $koneksi->query("SHOW COLUMNS FROM bc_ceisa_company LIKE 'secret_mfa_insw'");
if ($chkColMfaInsw && $chkColMfaInsw->num_rows === 0) {
    @$koneksi->query("ALTER TABLE bc_ceisa_company ADD COLUMN secret_mfa_insw VARCHAR(64) NULL AFTER secret_mfa");
}

$chkColInsw = $koneksi->query("SHOW COLUMNS FROM bc_ceisa_company LIKE 'insw_client_id'");
if ($chkColInsw && $chkColInsw->num_rows === 0) {
    @$koneksi->query("ALTER TABLE bc_ceisa_company 
        ADD COLUMN insw_client_id VARCHAR(100) NULL AFTER api_key_ceisa,
        ADD COLUMN insw_client_secret VARCHAR(100) NULL AFTER insw_client_id,
        ADD COLUMN insw_token TEXT NULL AFTER insw_client_secret,
        ADD COLUMN insw_api_url VARCHAR(255) NULL DEFAULT 'https://api.insw.go.id/document' AFTER insw_token");
}

$action = $_REQUEST['action'] ?? 'get_otp';
$type = strtolower(trim($_REQUEST['type'] ?? 'ceisa')); // 'ceisa' atau 'insw'
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
    
    // Validasi ketat: Jangan fallback antar sistem pabean yang berbeda!
    $hasSecretExpr = ($type === 'insw') 
        ? "IF(secret_mfa_insw IS NOT NULL AND secret_mfa_insw != '', 1, 0)" 
        : "IF(secret_mfa IS NOT NULL AND secret_mfa != '', 1, 0)";

    $res = $koneksi->query("SELECT cmp_id, cmp_name, npwp, username_ceisa, insw_client_id, $hasSecretExpr as has_secret FROM bc_ceisa_company $whereSql ORDER BY cmp_name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $userDisplay = ($type === 'insw')
                ? (!empty($r['insw_client_id']) ? $r['insw_client_id'] : ($r['username_ceisa'] ?: 'user_insw'))
                : ($r['username_ceisa'] ?: 'user');

            $list[] = [
                'cmp_id' => $r['cmp_id'],
                'cmp_name' => $r['cmp_name'],
                'npwp' => $r['npwp'],
                'username' => $userDisplay,
                'has_secret' => (bool)$r['has_secret']
            ];
        }
    }
    echo json_encode(['status' => 'OK', 'type' => $type, 'companies' => $list]);
    exit;
}

// -----------------------------------------------------------------
// 2. GET CURRENT OTP CODE
// -----------------------------------------------------------------
$stmt = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, username_ceisa, secret_mfa, secret_mfa_insw, insw_client_id FROM bc_ceisa_company WHERE cmp_id = ? OR npwp = ? LIMIT 1");
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

if ($type === 'insw') {
    // Secret khusus akun INSW / NSWId
    $secret = trim($company['secret_mfa_insw'] ?? '');
    $issuer = 'NSWId';
    $username = !empty($company['insw_client_id']) ? $company['insw_client_id'] : (!empty($company['username_ceisa']) ? $company['username_ceisa'] : 'user_insw');
    $emptyMsg = 'Secret Key TOTP MFA INSW (NSWId) belum diisi pada menu Kelola Perusahaan.';
} else {
    // Secret khusus akun CEISA 4.0 Beacukai
    $secret = trim($company['secret_mfa'] ?? '');
    $issuer = 'portal-beacukai';
    $username = $company['username_ceisa'] ?: 'user';
    $emptyMsg = 'Secret MFA CEISA belum dikonfigurasi untuk perusahaan ini di tabel bc_ceisa_company.';
}

if (empty($secret)) {
    echo json_encode([
        'status' => 'EMPTY_SECRET',
        'type' => $type,
        'cmp_id' => $company['cmp_id'],
        'cmp_name' => $company['cmp_name'],
        'username' => $username,
        'issuer' => $issuer,
        'message' => $emptyMsg
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
        'type' => $type,
        'otp' => (string)$otp,
        'issuer' => $issuer,
        'username' => $username,
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

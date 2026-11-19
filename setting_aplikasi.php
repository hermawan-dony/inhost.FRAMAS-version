<?php
/**
 * CEISA 4.0 - Setting & Konfigurasi Aplikasi (setting_aplikasi.php)
 * Mengelola Identitas & Branding, Wallpaper & Background Login, Integrasi CEISA 4.0, dan Preferensi Portal
 * Mendukung Multi-Tenant: Pengaturan Global Sistem & Pengaturan Khusus Per Perusahaan
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';
require_once __DIR__ . '/wa_helper.php';

// Validasi Keamanan: Super Administrator atau User Perusahaan yang terotentikasi
$isSuperAdmin = !empty($_SESSION['is_super_admin']) || (!empty($_SESSION['is_admin']) && empty($_SESSION['is_company_admin']));
$isSuperAdminDp = (!empty($_SESSION['is_super_admin']) || (isset($_SESSION['user_id']) && strtolower($_SESSION['user_id']) === 'dp'));
$isAdmin = $isSuperAdmin;
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '');
if (empty($sessionCmpId) && !empty($_REQUEST['cmp_id'])) {
    $sessionCmpId = trim($_REQUEST['cmp_id']);
}
$isLoggedInCompany = !empty($sessionCmpId) || !empty($_SESSION['user_id']) || !empty($_SESSION['user_name']);

if (!$isAdmin && !$isLoggedInCompany) {
    if (!empty($_REQUEST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'status' => 'AUTH_EXPIRED',
            'message' => 'Sesi login Anda telah berakhir. Silakan login kembali untuk melanjutkan.'
        ]);
        exit;
    }

    http_response_code(403);
    echo '<!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden - Akses Ditolak</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
        <style>
            body { font-family: "Poppins", sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; background:#f0f2f5; color:#262626; }
            .box { text-align:center; background:#fff; padding:40px; border-radius:10px; box-shadow:0 4px 16px rgba(0,0,0,0.08); max-width:440px; }
            .icon { font-size:48px; margin-bottom:12px; }
            h2 { color:#ff4d4f; margin:0 0 10px 0; font-size:20px; font-weight:600; }
            p { color:#8c8c8c; font-size:12.5px; line-height:1.6; margin:0 0 20px 0; }
            .btn { display:inline-block; padding:8px 18px; background:#1890ff; color:#fff; text-decoration:none; border-radius:6px; font-size:12px; font-weight:500; transition:background 0.2s; }
            .btn:hover { background:#40a9ff; }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="icon">🔒</div>
            <h2>403 - Akses Ditolak</h2>
            <p>Silakan login terlebih dahulu untuk mengakses menu Setting Aplikasi.</p>
            <a href="/index.php" class="btn">Kembali ke Portal Utama</a>
        </div>
    </body>
    </html>';
    exit;
}

if ($isAdmin && empty($_SESSION['is_admin'])) {
    $_SESSION['is_admin'] = true;
}

$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? ($isAdmin ? 'Administrator' : 'Perusahaan'));
$action = $_REQUEST['action'] ?? '';
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// Ambil daftar seluruh perusahaan aktif untuk switcher lingkup (khusus Admin)
$companies = [];
if ($koneksi) {
    $cQuery = $koneksi->query("SELECT cmp_id, cmp_name, logo, subdomain FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_name ASC");
    if ($cQuery) {
        while ($cRow = $cQuery->fetch_assoc()) {
            $companies[] = $cRow;
        }
    }
}

// Tentukan Lingkup Pengaturan ($targetCmpId):
// - Admin dapat memilih Global ('') atau Perusahaan Tertentu ($cmp_id)
// - Non-admin terkunci pada perusahaan miliknya sendiri
$targetCmpId = '';
if ($isAdmin) {
    $targetCmpId = trim($_REQUEST['cmp_id'] ?? '');
} else {
    $targetCmpId = $sessionCmpId;
}

// -------------------------------------------------------------
// 1. AJAX API: GET SETTINGS (Berdasarkan Scope)
// -------------------------------------------------------------
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    $scopeCmp = trim($_REQUEST['cmp_id'] ?? '');
    if (!$isAdmin) $scopeCmp = $sessionCmpId;
    
    $settings = getAppSettings($koneksi, $scopeCmp);
    $globalSettings = getAppSettings($koneksi, '');
    
    echo json_encode([
        'status'          => 'OK',
        'scope'           => $scopeCmp ? 'company' : 'global',
        'cmp_id'          => $scopeCmp,
        'is_custom'       => !empty($settings['_is_company_custom']),
        'settings'        => $settings,
        'global_settings' => $globalSettings
    ]);
    exit;
}

// -------------------------------------------------------------
// 1.B AJAX API: TEST WHATSAPP GATEWAY (Khusus Super Administrator 'dp')
// -------------------------------------------------------------
if ($action === 'test_wa') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isSuperAdminDp) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Super Administrator (dp) yang berhak melakukan tes pengiriman WhatsApp.']);
        exit;
    }
    
    $targetPhone = trim($_POST['target_phone'] ?? '');
    $customKey   = trim($_POST['api_key'] ?? '');
    $message     = trim($_POST['message'] ?? 'Tes Notifikasi WhatsApp Gateway dari Portal CEISA 4.0 & ERP Hub');

    if (empty($targetPhone)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor tujuan WhatsApp wajib diisi (format: 628xxx)!']);
        exit;
    }

    $res = sendWaMessage($targetPhone, $message, $koneksi, !empty($customKey) ? $customKey : null);
    echo json_encode($res);
    exit;
}

// -------------------------------------------------------------
// 1.C AJAX API: TEST WHATSAPP MEDIA (Khusus Super Administrator 'dp')
// -------------------------------------------------------------
if ($action === 'test_wa_media') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isSuperAdminDp) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Super Administrator (dp) yang berhak melakukan tes pengiriman media WhatsApp.']);
        exit;
    }

    $targetPhone = trim($_POST['target_phone'] ?? '');
    $mediaType   = trim($_POST['media_type'] ?? 'image');
    $mediaUrl    = trim($_POST['media_url'] ?? '');
    $caption     = trim($_POST['caption'] ?? '');
    $customKey   = trim($_POST['api_key'] ?? '');

    if (empty($targetPhone)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor tujuan WhatsApp wajib diisi (format: 628xxx)!']);
        exit;
    }
    if (empty($mediaUrl)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'URL berkas media wajib diisi!']);
        exit;
    }

    $res = sendWaMedia($targetPhone, $mediaType, $mediaUrl, $caption, $koneksi, !empty($customKey) ? $customKey : null);
    echo json_encode($res);
    exit;
}

// -------------------------------------------------------------
// 1.D AJAX API: GET WHATSAPP INBOX (Khusus Super Administrator 'dp')
// -------------------------------------------------------------
if ($action === 'get_wa_inbox') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isSuperAdminDp) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Super Administrator (dp) yang berhak membaca kotak masuk WhatsApp.']);
        exit;
    }

    $customKey = trim($_POST['api_key'] ?? ($_GET['api_key'] ?? ''));
    $res = getWaInbox($koneksi, !empty($customKey) ? $customKey : null);
    echo json_encode($res);
    exit;
}

// -------------------------------------------------------------
// 1.E AJAX API: GET WHATSAPP CONTACTS (Khusus Super Administrator 'dp')
// -------------------------------------------------------------
if ($action === 'get_wa_contacts') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isSuperAdminDp) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Super Administrator (dp) yang berhak membaca kontak WhatsApp.']);
        exit;
    }

    $customKey = trim($_POST['api_key'] ?? ($_GET['api_key'] ?? ''));
    $res = getWaContacts($koneksi, !empty($customKey) ? $customKey : null);
    echo json_encode($res);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: SAVE SETTINGS
// -------------------------------------------------------------
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    
    $scopeCmp = trim($_POST['cmp_id'] ?? '');
    if (!$isAdmin) {
        $scopeCmp = $sessionCmpId;
    }

    $appName          = trim($_POST['app_name'] ?? '');
    $appSubtitle      = trim($_POST['app_subtitle'] ?? '');
    $brandFontSize    = trim($_POST['sidebar_brand_font_size'] ?? '12px');
    if (!preg_match('/^[0-9]+(\.[0-9]+)?(px|rem|em|pt)$/', $brandFontSize)) {
        $brandFontSize = '12px';
    }
    $brandSubFontSize = trim($_POST['sidebar_brand_sub_font_size'] ?? '8px');
    if (!preg_match('/^[0-9]+(\.[0-9]+)?(px|rem|em|pt)$/', $brandSubFontSize)) {
        $brandSubFontSize = '8px';
    }
    $appLogo          = trim($_POST['app_logo'] ?? '');
    $appFavicon   = trim($_POST['app_favicon'] ?? '');
    $appFooter    = trim($_POST['app_footer'] ?? '');
    $loginBadge   = trim($_POST['login_badge'] ?? '');

    // Wallpaper & Background Login
    $loginBgType     = trim($_POST['login_bg_type'] ?? 'image');
    $loginBgGradient = trim($_POST['login_bg_gradient'] ?? 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)');
    $loginBgImage    = trim($_POST['login_bg_image'] ?? '');
    if ($loginBgType === 'image' && empty($loginBgImage)) {
        $loginBgImage = '/assets/wallpaper-batik-default.png';
    }
    $loginBgDimmer   = trim($_POST['login_bg_dimmer'] ?? '0');

    // CEISA 4.0 Integration & Environment (hanya admin yang dapat mengedit global)
    $ceisaEnv       = trim($_POST['ceisa_env'] ?? 'production');
    $ceisaApiUrl    = trim($_POST['ceisa_api_url'] ?? 'https://apis-gw.beacukai.go.id');
    $ceisaSyncBatch = isset($_POST['ceisa_sync_batch_size']) ? (int)$_POST['ceisa_sync_batch_size'] : 50;
    if ($ceisaSyncBatch < 10) $ceisaSyncBatch = 10;
    if ($ceisaSyncBatch > 1000) $ceisaSyncBatch = 1000;
    $ceisaSyncBatch = (string)$ceisaSyncBatch;

    $ceisaSyncInterval = isset($_POST['ceisa_sync_interval_minutes']) ? (int)$_POST['ceisa_sync_interval_minutes'] : 60;
    if ($ceisaSyncInterval < 15) $ceisaSyncInterval = 60;
    $ceisaSyncInterval = (string)$ceisaSyncInterval;

    $trackingRef    = trim($_POST['tracking_refresh'] ?? '0');
    $apiTimeout     = trim($_POST['api_timeout'] ?? '30');

    // Modul IT Inventory
    $prefixSpp    = trim($_POST['prefix_spp'] ?? 'SPP-');
    $prefixTrf    = trim($_POST['prefix_transfer'] ?? 'TRF-');
    $lockInvAju   = !empty($_POST['lock_inventory_aju']) ? '1' : '0';
    $requireYa    = !empty($_POST['require_confirm_ya']) ? '1' : '0';
    $tahunBuku    = trim($_POST['default_tahun_buku'] ?? 'auto');

    // Tampilan & UI
    $appFont      = trim($_POST['app_font'] ?? 'Poppins');
    $allowedFonts = ['Poppins', 'Inter', 'Source Sans Pro', 'Roboto', 'Segoe UI'];
    if (!in_array($appFont, $allowedFonts, true)) {
        $appFont = 'Poppins';
    }
    $appFontSize      = trim($_POST['app_font_size'] ?? '9.5px');
    $allowedFontSizes = ['9px', '9.5px', '10px', '10.5px', '11px', '12px'];
    if (!in_array($appFontSize, $allowedFontSizes, true)) {
        $appFontSize = '9.5px';
    }
    $defaultTheme = trim($_POST['default_theme'] ?? 'light');
    $defaultSide  = trim($_POST['default_sidebar'] ?? 'pinned');
    $sidebarIcon  = trim($_POST['sidebar_icon_style'] ?? 'colored');
    $pageSize     = trim($_POST['default_page_size'] ?? '25');
    $toastDur          = trim($_POST['toast_duration'] ?? '2600');
    $showCompCard      = trim($_POST['show_company_card'] ?? 'auto');
    $showLoginCmpTitle = trim($_POST['show_login_company_title'] ?? '0');
    if ($showLoginCmpTitle !== '1') $showLoginCmpTitle = '0';
    $showMenuTracking  = trim($_POST['show_menu_tracking'] ?? '0');
    if ($showMenuTracking !== '1') $showMenuTracking = '0';
    $enableVisitorMenuLog = isset($_POST['enable_visitor_menu_log']) ? trim($_POST['enable_visitor_menu_log']) : '1';
    if ($enableVisitorMenuLog !== '0') $enableVisitorMenuLog = '1';

    // Mode Modul, Workspace Tab & Lisensi Billing (HANYA BISA DIAKSES & DIUBAH OLEH 'dp' SUPER ADMINISTRATOR)
    $currentSetForAuth = getAppSettings($koneksi, $scopeCmp);
    if ($isSuperAdminDp) {
        $appMode = trim($_POST['app_mode'] ?? ($currentSetForAuth['app_mode'] ?? 'standard'));
        if ($appMode !== 'enterprise') $appMode = 'standard';

        $enableWorkspaceTabs = trim($_POST['enable_workspace_tabs'] ?? ($currentSetForAuth['enable_workspace_tabs'] ?? '1'));
        if ($enableWorkspaceTabs !== '0') $enableWorkspaceTabs = '1';

        $licenseModel = trim($_POST['license_model'] ?? ($currentSetForAuth['license_model'] ?? 'unlimited'));
        if ($licenseModel !== 'subscription') $licenseModel = 'unlimited';
    } else {
        $appMode = $currentSetForAuth['app_mode'] ?? 'standard';
        $enableWorkspaceTabs = $currentSetForAuth['enable_workspace_tabs'] ?? '1';
        $licenseModel = $currentSetForAuth['license_model'] ?? 'unlimited';
    }

    // 1. Upload File Logo Kustom jika ada
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        $fileInfo = pathinfo($_FILES['logo_file']['name']);
        $ext = strtolower($fileInfo['extension'] ?? '');

        if (in_array($ext, $allowedExt)) {
            $logosDir = __DIR__ . '/uploads/logos';
            if (!is_dir($logosDir)) {
                @mkdir($logosDir, 0777, true);
            }
            $prefixName = !empty($scopeCmp) ? 'logo_cmp_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $scopeCmp) : 'logo_global';
            $targetName = $prefixName . '_' . time() . '.' . $ext;
            $targetPath = $logosDir . '/' . $targetName;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
                $appLogo = '/uploads/logos/' . $targetName;
                $appFavicon = $appLogo;
            }
        }
    }

    // 2. Upload File Wallpaper Background Login jika ada
    if (isset($_FILES['wallpaper_file']) && $_FILES['wallpaper_file']['error'] === UPLOAD_ERR_OK) {
        $allowedExtWp = ['png', 'jpg', 'jpeg', 'webp'];
        $fileInfoWp = pathinfo($_FILES['wallpaper_file']['name']);
        $extWp = strtolower($fileInfoWp['extension'] ?? '');

        if (in_array($extWp, $allowedExtWp)) {
            $wallpapersDir = __DIR__ . '/uploads/wallpapers';
            if (!is_dir($wallpapersDir)) {
                @mkdir($wallpapersDir, 0777, true);
            }
            $prefixWp = !empty($scopeCmp) ? 'wp_cmp_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $scopeCmp) : 'wp_global';
            $targetNameWp = $prefixWp . '_' . time() . '.' . $extWp;
            $targetPathWp = $wallpapersDir . '/' . $targetNameWp;
            if (move_uploaded_file($_FILES['wallpaper_file']['tmp_name'], $targetPathWp)) {
                $loginBgImage = '/uploads/wallpapers/' . $targetNameWp;
                $loginBgType  = 'image'; // Otomatis aktifkan mode gambar wallpaper
            }
        }
    }

    // Susun payload
    $payload = [
        'app_name'                 => $appName,
        'app_subtitle'             => $appSubtitle,
        'sidebar_brand_font_size'  => $brandFontSize,
        'sidebar_brand_sub_font_size' => $brandSubFontSize,
        'app_logo'                 => $appLogo,
        'app_favicon'        => $appFavicon,
        'app_footer'         => $appFooter,
        'login_badge'        => $loginBadge,
        'login_bg_type'      => $loginBgType,
        'login_bg_gradient'  => $loginBgGradient,
        'login_bg_image'     => $loginBgImage,
        'login_bg_dimmer'    => $loginBgDimmer,
        'prefix_spp'         => $prefixSpp,
        'prefix_transfer'    => $prefixTrf,
        'lock_inventory_aju' => $lockInvAju,
        'require_confirm_ya' => $requireYa,
        'default_tahun_buku' => $tahunBuku,
        'app_font'           => $appFont,
        'app_font_size'      => $appFontSize,
        'default_theme'      => $defaultTheme,
        'default_sidebar'    => $defaultSide,
        'sidebar_icon_style'       => $sidebarIcon,
        'show_company_card'        => $showCompCard,
        'show_login_company_title' => $showLoginCmpTitle,
        'show_menu_tracking'       => $showMenuTracking,
        'default_page_size'        => $pageSize,
        'toast_duration'           => $toastDur,
        'enable_visitor_menu_log'  => $enableVisitorMenuLog,
        'app_mode'                 => $appMode,
        'enable_workspace_tabs'    => $enableWorkspaceTabs,
        'license_model'            => $licenseModel
    ];

    // Hanya simpan CEISA Gateway jika mengedit Global (atau jika Admin)
    if ($isAdmin && empty($scopeCmp)) {
        $payload['ceisa_env']                   = $ceisaEnv;
        $payload['ceisa_api_url']               = $ceisaApiUrl;
        $payload['ceisa_sync_interval_minutes'] = $ceisaSyncInterval;
        $payload['ceisa_sync_batch_size']       = $ceisaSyncBatch;
        $payload['tracking_refresh']            = $trackingRef;
        $payload['api_timeout']                 = $apiTimeout;
    }

    // Konfigurasi WhatsApp Gateway (HANYA BISA DISIMPAN OLEH 'dp' SUPER ADMINISTRATOR)
    if ($isSuperAdminDp) {
        if (isset($_POST['wa_api_key'])) {
            $payload['wa_api_key'] = trim($_POST['wa_api_key']);
        }
        if (isset($_POST['wa_api_url'])) {
            $payload['wa_api_url'] = trim($_POST['wa_api_url']);
        }
        if (isset($_POST['wa_admin_number'])) {
            $payload['wa_admin_number'] = formatWaNumber($_POST['wa_admin_number']);
        }
        if (isset($_POST['wa_notif_enabled'])) {
            $payload['wa_notif_enabled'] = trim($_POST['wa_notif_enabled']) === '1' ? '1' : '0';
        }
    }

    $updated = saveAppSettings($payload, $koneksi, $sessionUser, $scopeCmp);

    // Sinkronkan ke session aktif jika scope adalah perusahaan aktif atau global
    if (empty($scopeCmp) || $scopeCmp === $sessionCmpId) {
        $_SESSION['use_tabs'] = ($enableWorkspaceTabs === '0') ? 0 : 1;
        $_SESSION['app_mode'] = ($appMode === 'enterprise') ? 'enterprise' : 'standar';
    }

    $msg = empty($scopeCmp) 
        ? 'Pengaturan aplikasi Global berhasil diperbarui!' 
        : 'Pengaturan khusus untuk perusahaan ini berhasil disimpan!';

    echo json_encode([
        'status'    => 'OK',
        'scope'     => $scopeCmp ? 'company' : 'global',
        'cmp_id'    => $scopeCmp,
        'message'   => $msg,
        'settings'  => $updated
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: RESET PERUSAHAAN KE GLOBAL
// -------------------------------------------------------------
if ($action === 'reset_company') {
    header('Content-Type: application/json; charset=utf-8');
    $scopeCmp = trim($_POST['cmp_id'] ?? '');
    if (!$isAdmin) $scopeCmp = $sessionCmpId;

    if (empty($scopeCmp)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih perusahaan yang ingin dikembalikan ke settingan global.']);
        exit;
    }

    $updated = resetAppSettings($koneksi, 'reset_' . $sessionUser, $scopeCmp);
    echo json_encode([
        'status'   => 'OK',
        'scope'    => 'company',
        'cmp_id'   => $scopeCmp,
        'message'  => 'Pengaturan perusahaan berhasil dikembalikan ke settingan Global!',
        'settings' => $updated
    ]);
    exit;
}

// -------------------------------------------------------------
// 4. AJAX API: RESET GLOBAL KE DEFAULT AWAL
// -------------------------------------------------------------
if ($action === 'reset') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Administrator yang dapat mereset konfigurasi global sistem.']);
        exit;
    }

    $updated = resetAppSettings($koneksi, 'reset_' . $sessionUser, '');
    echo json_encode([
        'status'   => 'OK',
        'scope'    => 'global',
        'message'  => 'Semua konfigurasi Global berhasil dikembalikan ke standar awal sistem!',
        'settings' => $updated
    ]);
    exit;
}

// Ambil konfigurasi saat ini sesuai scope terpilih
$currentSettings = getAppSettings($koneksi, $targetCmpId);
$globalSettings  = getAppSettings($koneksi, '');
$isCustomScope   = !empty($targetCmpId);
$isCustomActive  = !empty($currentSettings['_is_company_custom']);

// Cari nama perusahaan terpilih
$targetCmpName = '';
if ($isCustomScope) {
    foreach ($companies as $c) {
        if ($c['cmp_id'] === $targetCmpId) {
            $targetCmpName = $c['cmp_name'];
            break;
        }
    }
    if (empty($targetCmpName)) {
        $targetCmpName = $_SESSION['company_name'] ?? 'Perusahaan Terpilih';
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($themeParam) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setting Aplikasi - <?= $isCustomScope ? htmlspecialchars($targetCmpName) : 'Global Portal' ?></title>
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var th = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || '<?= htmlspecialchars($themeParam) ?>' || 'light';
            document.documentElement.setAttribute('data-theme', th);
            var savedFont = '<?= htmlspecialchars($currentSettings['app_font'] ?? 'Poppins') ?>';
            document.documentElement.style.setProperty('--app-font', "'" + savedFont + "', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif");
            var savedFontSize = '<?= htmlspecialchars($currentSettings['app_font_size'] ?? '9.5px') ?>';
            document.documentElement.style.setProperty('--app-font-size', savedFontSize);
            document.documentElement.style.setProperty('--form-font-size', savedFontSize);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-header: #fafafa;
            --border-color: #ebedf2;
            --border-light: #f0f0f0;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-light: #e6f7ff;
            --accent: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --text-main: #262626;
            --text-secondary: #595959;
            --text-muted: #8c8c8c;
            --shadow-card: 0 2px 8px rgba(0, 0, 0, 0.06);
            color-scheme: light;
        }

        html[data-theme="dark"], body[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-header: #262626;
            --border-color: #303030;
            --border-light: #262626;
            --primary: #3699ff;
            --primary-hover: #187de4;
            --primary-light: rgba(54, 153, 255, 0.15);
            --accent: #1bc5bd;
            --warning: #ffa800;
            --danger: #f64e60;
            --text-main: #e1e3ea;
            --text-secondary: #b5b5c3;
            --text-muted: #92929f;
            --shadow-card: 0 4px 16px rgba(0, 0, 0, 0.25);
            color-scheme: dark !important;
        }

        html[data-theme="dark"] select,
        html[data-theme="dark"] option {
            background-color: #1f1f1f !important;
            color: #e1e3ea !important;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--app-font);
            font-size: 10.5px;
            background: var(--bg-base);
            color: var(--text-main);
            padding: 6px 12px 24px;
            min-height: 100vh;
        }

        input, select, textarea, button {
            font-family: var(--app-font);
        }

        /* Header Page */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 8px;
            box-shadow: var(--shadow-card);
        }
        .page-title-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .page-title-icon {
            font-size: 18px;
            line-height: 1;
            color: var(--primary);
        }
        .page-title {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
        }
        .page-subtitle {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Scope Selector Bar */
        .scope-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: var(--shadow-card);
            flex-wrap: wrap;
        }
        .scope-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 280px;
        }
        .scope-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .scope-select {
            height: 28px;
            padding: 0 10px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--bg-base);
            color: var(--text-main);
            outline: none;
            cursor: pointer;
            min-width: 280px;
            transition: all 0.2s;
        }
        .scope-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-light);
        }
        .scope-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Status Pills */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 9.5px;
            font-weight: 600;
        }
        .status-pill.custom {
            background: rgba(82, 196, 26, 0.12);
            color: #52c41a;
            border: 1px solid rgba(82, 196, 26, 0.3);
        }
        .status-pill.global {
            background: rgba(24, 144, 255, 0.1);
            color: #1890ff;
            border: 1px solid rgba(24, 144, 255, 0.25);
        }
        .status-pill.system {
            background: rgba(114, 46, 209, 0.1);
            color: #722ed1;
            border: 1px solid rgba(114, 46, 209, 0.25);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            height: 24px;
            padding: 0 10px;
            font-size: 9.5px;
            font-weight: 600;
            border-radius: 4px;
            cursor: pointer;
            border: 1px solid transparent;
            font-family: inherit;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        .btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        .btn-default {
            background: var(--bg-card);
            color: var(--text-main);
            border-color: var(--border-color);
        }
        .btn-default:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: var(--primary);
        }
        .btn-warning {
            background: rgba(250, 173, 20, 0.1);
            color: #d48806;
            border-color: rgba(250, 173, 20, 0.4);
        }
        .btn-warning:hover {
            background: #faad14;
            color: #fff;
            border-color: #faad14;
        }
        .btn-danger {
            background: transparent;
            color: var(--danger);
            border-color: var(--danger);
        }
        .btn-danger:hover {
            background: rgba(255, 77, 79, 0.1);
        }

        /* Layout Grid */
        .settings-layout {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 10px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .settings-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Tabs Nav */
        .settings-tabs-nav {
            display: flex;
            gap: 4px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px 6px 0 0;
            padding: 4px 8px 0;
            border-bottom: 2px solid var(--border-color);
            overflow-x: auto;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-secondary);
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            white-space: nowrap;
        }
        .tab-btn:hover {
            color: var(--primary);
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: var(--primary-light);
            border-radius: 5px 5px 0 0;
        }

        /* Tab Content Card */
        .tab-pane {
            display: none;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 6px 6px;
            padding: 14px 16px;
            box-shadow: var(--shadow-card);
        }
        .tab-pane.active {
            display: block;
        }

        /* Form Groups */
        .form-section-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        .form-row.single {
            grid-template-columns: 1fr;
        }
        .form-group {
            margin-bottom: 10px;
        }
        .form-group label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 4px;
        }
        .form-group .hint {
            font-size: 8.5px;
            color: var(--text-muted);
            font-weight: normal;
        }
        .form-control {
            width: 100%;
            height: 26px;
            padding: 2px 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-base);
            border-radius: 4px;
            font-size: 10px;
            color: var(--text-main);
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
        }
        .form-control:focus {
            background: var(--bg-card);
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-light);
        }

        /* Logo Presets Grid */
        .logo-presets-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-top: 6px;
            margin-bottom: 8px;
        }
        .preset-logo-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 6px 4px;
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .preset-logo-item:hover, .preset-logo-item.active {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .preset-logo-item img {
            width: 22px;
            height: 22px;
            object-fit: contain;
            margin-bottom: 3px;
        }
        .preset-logo-item span {
            font-size: 8px;
            color: var(--text-main);
            font-weight: 600;
        }

        /* Wallpaper Types Grid */
        .wp-type-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }
        .wp-type-card {
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 10px;
            cursor: pointer;
            background: var(--bg-base);
            transition: all 0.2s;
            text-align: center;
        }
        .wp-type-card:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .wp-type-card.active {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 0 0 2px var(--primary-light);
        }
        .wp-type-card .icon {
            font-size: 16px;
            margin-bottom: 4px;
            color: var(--primary);
        }
        .wp-type-card .title {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-main);
        }
        .wp-type-card .desc {
            font-size: 8px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Wallpaper Gradients Presets Grid */
        .gradient-presets-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 10px;
        }
        .gradient-item {
            height: 48px;
            border-radius: 6px;
            border: 2px solid transparent;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: flex-end;
            padding: 4px 6px;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .gradient-item:hover, .gradient-item.active {
            border-color: #1890ff;
            transform: scale(1.02);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .gradient-item span {
            font-size: 8px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0,0,0,0.8);
        }

        /* File Upload */
        .file-upload-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 5px;
        }
        .file-upload-wrap input[type="file"] {
            display: none;
        }

        /* Live Preview Sticky Card */
        .preview-sticky-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px;
            box-shadow: var(--shadow-card);
            position: sticky;
            top: 8px;
        }
        .preview-header-title {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .live-preview-box {
            background: var(--bg-base);
            border: 1px dashed var(--primary);
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 10px;
        }
        .preview-sidebar-brand {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .preview-brand-logo {
            width: 30px;
            height: 30px;
            object-fit: contain;
            border-radius: 5px;
            background: #fff;
            padding: 2px;
            border: 1px solid var(--border-light);
            flex-shrink: 0;
        }
        .preview-brand-text {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            line-height: 1.15;
        }
        .preview-brand-name {
            font-size: 13px;
            font-weight: 800;
            color: #1890ff;
            letter-spacing: 0.25px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .preview-brand-sub {
            font-size: 8px;
            font-weight: 600;
            color: #8c8c8c;
            letter-spacing: 0.15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Live Login Wallpaper Simulation */
        .live-login-simulation {
            width: 100%;
            height: 190px;
            border-radius: 6px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #ffffff;
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }
        .live-login-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.3);
            pointer-events: none;
            transition: all 0.3s;
        }
        .live-login-mini-card {
            position: relative;
            z-index: 2;
            width: 190px;
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 6px;
            padding: 10px 12px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.25);
            text-align: center;
            color: #262626;
        }
        .live-login-mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 3px 6px;
            margin-bottom: 6px;
        }
        .live-login-mini-logo {
            width: 16px;
            height: 16px;
            object-fit: contain;
        }
        .live-login-mini-title {
            font-size: 8.5px;
            font-weight: 800;
            color: #1890ff;
            line-height: 1;
        }
        .live-login-mini-sub {
            font-size: 5px;
            font-weight: 600;
            color: #8c8c8c;
            text-transform: uppercase;
        }
        .live-login-mini-input {
            width: 100%;
            height: 16px;
            border: 1px solid #d9d9d9;
            border-radius: 3px;
            margin-bottom: 4px;
            font-size: 7px;
            padding: 0 4px;
            background: #fff;
            color: #595959;
        }
        .live-login-mini-btn {
            width: 100%;
            height: 18px;
            background: #1890ff;
            color: #fff;
            border: none;
            border-radius: 3px;
            font-size: 7.5px;
            font-weight: 700;
        }
    </style>
</head>
<body>

    <!-- Header Page -->
    <div class="page-header">
        <div class="page-title-wrap">
            <span class="page-title-icon"><i class="fa-solid fa-sliders"></i></span>
            <div>
                <h1 class="page-title">Setting &amp; Konfigurasi Aplikasi</h1>
                <p class="page-subtitle">Kelola nama aplikasi, logo branding, wallpaper login, integrasi CEISA 4.0, dan preferensi portal</p>
            </div>
        </div>
        <div class="header-actions">
            <?php if ($isCustomScope): ?>
                <?php if ($isCustomActive): ?>
                    <button type="button" class="btn btn-warning" onclick="confirmResetCompany()">
                        <i class="fa-solid fa-rotate-left"></i> Kembalikan ke Settingan Global
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($isAdmin): ?>
                    <button type="button" class="btn btn-danger" onclick="confirmResetGlobal()">
                        <i class="fa-solid fa-rotate-left"></i> Reset Global ke Default
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" onclick="submitSaveSettings()">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
            </button>
        </div>
    </div>

    <!-- Lingkup Pengaturan (Scope Bar) -->
    <div class="scope-card">
        <div class="scope-left">
            <div class="scope-label">
                <i class="fa-solid fa-layer-group" style="color:var(--primary);"></i> Lingkup Pengaturan:
            </div>
            <?php if ($isAdmin): ?>
                <select id="scopeCmpSelect" class="scope-select" onchange="switchScope(this.value)">
                    <option value="" <?= empty($targetCmpId) ? 'selected' : '' ?>>🌐 Global (Default Seluruh Sistem / Portal)</option>
                    <optgroup label="Pengaturan Khusus Perusahaan">
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= htmlspecialchars($c['cmp_id']) ?>" <?= ($targetCmpId === $c['cmp_id']) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($c['cmp_id']) ?>] <?= htmlspecialchars($c['cmp_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            <?php else: ?>
                <div style="font-size:11px; font-weight:700; color:var(--primary);">
                    🏢 <?= htmlspecialchars($targetCmpName) ?> <span style="font-family:monospace; font-weight:normal; opacity:0.8;">(<?= htmlspecialchars($targetCmpId) ?>)</span>
                </div>
                <input type="hidden" id="scopeCmpSelect" value="<?= htmlspecialchars($targetCmpId) ?>">
            <?php endif; ?>
        </div>
        <div class="scope-right">
            <?php if ($isCustomScope): ?>
                <?php if ($isCustomActive): ?>
                    <span class="status-pill custom" title="Perusahaan ini memiliki konfigurasi nama, logo, atau wallpaper sendiri"><i class="fa-solid fa-sliders"></i> Setting Kustom Perusahaan Aktif</span>
                <?php else: ?>
                    <span class="status-pill global" title="Perusahaan ini mengikuti nama, logo, dan wallpaper bawaan Global"><i class="fa-solid fa-globe"></i> Mengikuti Settingan Global</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="status-pill system" title="Pengaturan ini berlaku untuk semua perusahaan yang belum memiliki setting kustom"><i class="fa-solid fa-globe"></i> Pengaturan Global Portal</span>
            <?php endif; ?>
        </div>
    </div>

    <form id="formSettings" onsubmit="event.preventDefault(); submitSaveSettings();">
        <input type="hidden" name="cmp_id" id="hidden_cmp_id" value="<?= htmlspecialchars($targetCmpId) ?>">
        <input type="hidden" name="login_bg_type" id="inp_login_bg_type" value="<?= htmlspecialchars($currentSettings['login_bg_type'] ?? 'image') ?>">

        <div class="settings-layout">
            
            <!-- Left Column: Settings Tabs -->
            <div class="settings-main-wrap">
                <div class="settings-tabs-nav">
                    <button type="button" class="tab-btn active" onclick="switchSettingsTab('tab-brand', this)">
                        <i class="fa-solid fa-award"></i> Identitas &amp; Branding
                    </button>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-wallpaper', this)">
                        <i class="fa-solid fa-image"></i> Wallpaper &amp; Latar Login
                    </button>
                    <?php if ($isAdmin && empty($targetCmpId)): ?>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-ceisa', this)">
                        <i class="fa-solid fa-network-wired"></i> Integrasi CEISA 4.0
                    </button>
                    <?php endif; ?>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-inventory', this)">
                        <i class="fa-solid fa-boxes-stacked"></i> Modul IT Inventory
                    </button>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-ui', this)">
                        <i class="fa-solid fa-palette"></i> Tampilan &amp; Preferensi
                    </button>
                    <?php if ($isSuperAdminDp): ?>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-wa', this)">
                        <i class="fa-brands fa-whatsapp" style="color:#25d366;"></i> WhatsApp Gateway (dp)
                    </button>
                    <?php endif; ?>
                </div>

                <!-- 1. TAB: IDENTITAS & BRANDING -->
                <div class="tab-pane active" id="tab-brand">
                    <div class="form-section-title">
                        <i class="fa-solid fa-tag"></i> Informasi Brand Header Sidebar &amp; Kartu Login
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Nama Aplikasi (App Name)*</span>
                                <span class="hint">Ditampilkan di sudut kiri atas &amp; badge login</span>
                            </label>
                            <input type="text" class="form-control" id="inp_app_name" name="app_name" 
                                   value="<?= htmlspecialchars($currentSettings['app_name']) ?>" 
                                   oninput="updateLivePreview()" placeholder="Contoh: INHOST atau RMB Portal" required>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Subtitle / Tagline Aplikasi*</span>
                                <span class="hint">Deskripsi singkat di bawah judul</span>
                            </label>
                            <input type="text" class="form-control" id="inp_app_subtitle" name="app_subtitle" 
                                   value="<?= htmlspecialchars($currentSettings['app_subtitle']) ?>" 
                                   oninput="updateLivePreview()" placeholder="Contoh: Interface Host to Host Ceisa 4.0" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Ukuran Font Nama Brand Sidebar (Font Size)</span>
                                <span class="hint">Kecilkan jika nama perusahaan terlalu panjang agar muat dan tidak terpotong</span>
                            </label>
                            <select class="form-control" id="inp_sidebar_brand_font_size" name="sidebar_brand_font_size" onchange="previewSidebarFontSizeLive()">
                                <option value="9px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '9px') ? 'selected' : '' ?>>9px - Paling Kecil</option>
                                <option value="10px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '10px') ? 'selected' : '' ?>>10px - Ekstra Kecil</option>
                                <option value="11px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '11px') ? 'selected' : '' ?>>11px - Kecil (Kompak)</option>
                                <option value="11.5px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '11.5px') ? 'selected' : '' ?>>11.5px - Menengah (Default)</option>
                                <option value="12px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '12px') ? 'selected' : '' ?>>12px - Standar</option>
                                <option value="13px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '13px') ? 'selected' : '' ?>>13px - Cukup Besar</option>
                                <option value="14px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '14px') ? 'selected' : '' ?>>14px - Besar</option>
                                <option value="15px" <?= (($currentSettings['sidebar_brand_font_size'] ?? '11.5px') === '15px') ? 'selected' : '' ?>>15px - Ekstra Besar</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Ukuran Font Subtitle Sidebar</span>
                                <span class="hint">Ukuran font keterangan tagline di bawah nama brand</span>
                            </label>
                            <select class="form-control" id="inp_sidebar_brand_sub_font_size" name="sidebar_brand_sub_font_size" onchange="previewSidebarFontSizeLive()">
                                <option value="7px" <?= (($currentSettings['sidebar_brand_sub_font_size'] ?? '7.5px') === '7px') ? 'selected' : '' ?>>7px - Ekstra Kecil</option>
                                <option value="7.5px" <?= (($currentSettings['sidebar_brand_sub_font_size'] ?? '7.5px') === '7.5px') ? 'selected' : '' ?>>7.5px - Kecil (Default)</option>
                                <option value="8px" <?= (($currentSettings['sidebar_brand_sub_font_size'] ?? '7.5px') === '8px') ? 'selected' : '' ?>>8px - Standar</option>
                                <option value="8.5px" <?= (($currentSettings['sidebar_brand_sub_font_size'] ?? '7.5px') === '8.5px') ? 'selected' : '' ?>>8.5px - Menengah</option>
                                <option value="9px" <?= (($currentSettings['sidebar_brand_sub_font_size'] ?? '7.5px') === '9px') ? 'selected' : '' ?>>9px - Besar</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            <span>Pilih Preset Icon / Logo Aplikasi</span>
                            <span class="hint">Klik preset atau gunakan upload file sendiri</span>
                        </label>
                        <div class="logo-presets-grid">
                            <div class="preset-logo-item" onclick="selectLogoPreset('/assets/logo.png', this)">
                                <img src="/assets/logo.png" alt="INHOST Cyan">
                                <span>INHOST Cyan</span>
                            </div>
                            <div class="preset-logo-item" onclick="selectLogoPreset('/assets/logo.png', this, 'hue-rotate(190deg)')">
                                <img src="/assets/logo.png" style="filter: hue-rotate(190deg);" alt="Custom Blue">
                                <span>Royal Blue</span>
                            </div>
                            <div class="preset-logo-item" onclick="selectLogoPreset('/assets/logo.png', this, 'saturate(2) hue-rotate(90deg)')">
                                <img src="/assets/logo.png" style="filter: saturate(2) hue-rotate(90deg);" alt="Vibrant">
                                <span>Emerald Green</span>
                            </div>
                            <div class="preset-logo-item" onclick="selectLogoPreset('/assets/logo.png', this, 'grayscale(1)')">
                                <img src="/assets/logo.png" style="filter: grayscale(1);" alt="Monochrome">
                                <span>Monochrome</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Path URL Logo</span>
                                <span class="hint">Alamat relatif atau URL absolut file logo</span>
                            </label>
                            <input type="text" class="form-control" id="inp_app_logo" name="app_logo" 
                                   value="<?= htmlspecialchars($currentSettings['app_logo']) ?>" 
                                   oninput="updateLivePreview()" placeholder="/assets/logo.png">
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Unggah Logo Kustom (.PNG, .SVG, .WEBP)</span>
                                <span class="hint">Rekomendasi rasio 1:1 (min. 64x64px)</span>
                            </label>
                            <div class="file-upload-wrap">
                                <input type="file" id="fileLogoUpload" name="logo_file" accept="image/png, image/jpeg, image/svg+xml, image/webp" onchange="handleLogoFileSelect(event)">
                                <button type="button" class="btn btn-default" onclick="document.getElementById('fileLogoUpload').click()">
                                    <i class="fa-solid fa-folder-open"></i> Pilih File Logo...
                                </button>
                                <span id="fileNameLabel" style="font-size:9px; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Belum ada file dipilih</span>
                            </div>
                        </div>
                    </div>

                    <div style="height: 1px; background: var(--border-color); margin: 16px 0;"></div>

                    <div class="form-section-title">
                        <i class="fa-solid fa-quote-left"></i> Teks Footer &amp; Layar Login
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Teks Badge Halaman Login</span>
                                <span class="hint">Tampil di footer / badge layar login</span>
                            </label>
                            <input type="text" class="form-control" id="inp_login_badge" name="login_badge" 
                                   value="<?= htmlspecialchars($currentSettings['login_badge']) ?>" 
                                   placeholder="Contoh: Single Core System">
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Teks Footer / Hak Cipta Portal</span>
                                <span class="hint">Tampil di bagian bawah login &amp; laporan</span>
                            </label>
                            <input type="text" class="form-control" id="inp_app_footer" name="app_footer" 
                                   value="<?= htmlspecialchars($currentSettings['app_footer']) ?>" 
                                   placeholder="Contoh: Interface Host to Host (INHOST) Ceisa 4.0">
                        </div>
                    </div>

                    <div style="height: 1px; background: var(--border-color); margin: 16px 0;"></div>

                    <div class="form-section-title">
                        <i class="fa-solid fa-id-card"></i> Visibilitas Kartu Perusahaan di Sidebar (Company Card)
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>
                                <span>Tampilkan / Sembunyikan Kotak Perusahaan</span>
                                <span class="hint">Kotak abu-abu nama/logo perusahaan di bawah brand header sidebar menu</span>
                            </label>
                            <select class="form-control" id="inp_show_company_card_brand" onchange="syncCompanyCardSetting(this.value)">
                                <option value="auto" <?= (($currentSettings['show_company_card'] ?? 'auto') === 'auto') ? 'selected' : '' ?>>⚙️ Otomatis (Default: Sembunyikan untuk User Perusahaan, Tampilkan untuk Super Admin)</option>
                                <option value="0" <?= (($currentSettings['show_company_card'] ?? 'auto') === '0') ? 'selected' : '' ?>>👁️‍🗨️ Sembunyikan (HIDE - Sidebar lebih bersih &amp; modern)</option>
                                <option value="1" <?= (($currentSettings['show_company_card'] ?? 'auto') === '1') ? 'selected' : '' ?>>👁️ Selalu Tampilkan (SHOW)</option>
                            </select>
                        </div>
                    </div>

                    <div style="height: 1px; background: var(--border-color); margin: 16px 0;"></div>

                    <div class="form-section-title">
                        <i class="fa-solid fa-heading"></i> Visibilitas Teks Nama Perusahaan di Kartu Login
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>
                                <span>Teks Nama Perusahaan &amp; Subtitle di Bawah Badge Kartu Login</span>
                                <span class="hint">Default Sembunyikan (HIDE) agar tampilan kartu login ringkas &amp; tidak dobel/redundant dengan badge logo</span>
                            </label>
                            <select class="form-control" id="inp_show_login_company_title" name="show_login_company_title">
                                <option value="0" <?= (($currentSettings['show_login_company_title'] ?? '0') === '0') ? 'selected' : '' ?>>👁️‍🗨️ Sembunyikan (HIDE - Default: Bersih, Rapi &amp; Ringkas)</option>
                                <option value="1" <?= (($currentSettings['show_login_company_title'] ?? '0') === '1') ? 'selected' : '' ?>>👁️ Tampilkan (SHOW - Tampilkan teks Nama Perusahaan &amp; Portal Akses)</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>
                                <span>Menu Tracking Respon Pabean</span>
                                <span class="hint">Visibilitas menu Tracking Respon Pabean di sidebar</span>
                            </label>
                            <select class="form-control" id="inp_show_menu_tracking" name="show_menu_tracking">
                                <option value="0" <?= (($currentSettings['show_menu_tracking'] ?? '0') === '0') ? 'selected' : '' ?>>👁️‍🗨️ Sembunyikan (HIDE - Bawaan / Default Framas)</option>
                                <option value="1" <?= (($currentSettings['show_menu_tracking'] ?? '0') === '1') ? 'selected' : '' ?>>👁️ Tampilkan (SHOW)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 2. TAB: WALLPAPER & LATAR LOGIN (BARU) -->
                <div class="tab-pane" id="tab-wallpaper">
                    <div class="form-section-title">
                        <i class="fa-solid fa-image"></i> Pengaturan Wallpaper &amp; Background Layar Login
                    </div>

                    <div class="wp-type-cards">
                        <div class="wp-type-card <?= (($currentSettings['login_bg_type'] ?? 'image') === 'image') ? 'active' : '' ?>" id="card_type_image" onclick="selectWallpaperType('image')">
                            <div class="icon"><i class="fa-solid fa-panorama"></i></div>
                            <div class="title">Wallpaper Gambar (Batik / Foto)</div>
                            <div class="desc">Wallpaper default Batik Nusantara atau upload foto/gambar sendiri</div>
                        </div>
                        <div class="wp-type-card <?= (($currentSettings['login_bg_type'] ?? '') === 'default') ? 'active' : '' ?>" id="card_type_default" onclick="selectWallpaperType('default')">
                            <div class="icon"><i class="fa-solid fa-shield-halved"></i></div>
                            <div class="title">Latar Halus Ambient</div>
                            <div class="desc">Latar halus modern slate-blue ambient tanpa wallpaper gambar</div>
                        </div>
                        <div class="wp-type-card <?= (($currentSettings['login_bg_type'] ?? '') === 'gradient') ? 'active' : '' ?>" id="card_type_gradient" onclick="selectWallpaperType('gradient')">
                            <div class="icon"><i class="fa-solid fa-palette"></i></div>
                            <div class="title">Gradien Warna Presets</div>
                            <div class="desc">Pilihan gradien tema korporat (Deep Navy, Indigo, Emerald, dll)</div>
                        </div>
                    </div>

                    <!-- Panel Opsi Gradien Presets -->
                    <div id="section_wp_gradient" style="<?= (($currentSettings['login_bg_type'] ?? '') === 'gradient') ? 'display:block;' : 'display:none;' ?>">
                        <div class="form-group">
                            <label>
                                <span>Pilih Preset Gradien Korporat</span>
                                <span class="hint">Klik salah satu gradien di bawah untuk menerapkan</span>
                            </label>
                            <div class="gradient-presets-grid">
                                <div class="gradient-item" style="background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%);" onclick="selectGradientPreset('linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)', this)">
                                    <span>Deep Space</span>
                                </div>
                                <div class="gradient-item" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);" onclick="selectGradientPreset('linear-gradient(135deg, #1e3c72 0%, #2a5298 100%)', this)">
                                    <span>Royal Blue</span>
                                </div>
                                <div class="gradient-item" style="background: linear-gradient(135deg, #134e5e 0%, #71b280 100%);" onclick="selectGradientPreset('linear-gradient(135deg, #134e5e 0%, #71b280 100%)', this)">
                                    <span>Emerald Slate</span>
                                </div>
                                <div class="gradient-item" style="background: linear-gradient(135deg, #141e30 0%, #243b55 100%);" onclick="selectGradientPreset('linear-gradient(135deg, #141e30 0%, #243b55 100%)', this)">
                                    <span>Midnight Indigo</span>
                                </div>
                                <div class="gradient-item" style="background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);" onclick="selectGradientPreset('linear-gradient(135deg, #2c3e50 0%, #3498db 100%)', this)">
                                    <span>Executive Sunset</span>
                                </div>
                                <div class="gradient-item" style="background: linear-gradient(135deg, #232526 0%, #414345 100%);" onclick="selectGradientPreset('linear-gradient(135deg, #232526 0%, #414345 100%)', this)">
                                    <span>Modern Charcoal</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>
                                <span>Formula Gradien CSS Kustom</span>
                                <span class="hint">Format: linear-gradient(...)</span>
                            </label>
                            <input type="text" class="form-control" id="inp_login_bg_gradient" name="login_bg_gradient" 
                                   value="<?= htmlspecialchars($currentSettings['login_bg_gradient'] ?? 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)') ?>" 
                                   oninput="updateWallpaperSimulation()" placeholder="linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)">
                        </div>
                    </div>

                    <!-- Panel Opsi Gambar Wallpaper Kustom -->
                    <div id="section_wp_image" style="<?= (($currentSettings['login_bg_type'] ?? '') === 'image') ? 'display:block;' : 'display:none;' ?>">
                        <!-- Preset Rekomendasi Wallpaper Default -->
                        <div style="margin-bottom:14px; padding:10px 14px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:8px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <img src="/assets/wallpaper-batik-default.png" alt="Batik Default" style="width:52px; height:38px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color); background:#fff; box-shadow:0 2px 6px rgba(0,0,0,0.06);">
                                <div>
                                    <div style="font-size:11px; font-weight:600; color:var(--text-main);"><i class="fa-solid fa-gem" style="color:#1890ff; margin-right:4px;"></i> Wallpaper Default Sistem (Motif Batik Nusantara)</div>
                                    <div style="font-size:9.5px; color:var(--text-muted); margin-top:1px;">Motif batik floral &amp; Mega Mendung proporsional full screen otomatis</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-default" onclick="useDefaultBatikWallpaper()" style="font-size:10.5px; white-space:nowrap; padding:5px 12px; border-color:#1890ff; color:#1890ff;">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Pasang Wallpaper Ini
                            </button>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <span>Unggah Gambar Wallpaper dari Komputer</span>
                                    <span class="hint">Format JPG, PNG, WEBP (disarankan min. 1920x1080px)</span>
                                </label>
                                <div class="file-upload-wrap">
                                    <input type="file" id="fileWallpaperUpload" name="wallpaper_file" accept="image/jpeg, image/png, image/webp" onchange="handleWallpaperFileSelect(event)">
                                    <button type="button" class="btn btn-default" onclick="document.getElementById('fileWallpaperUpload').click()">
                                        <i class="fa-solid fa-upload"></i> Pilih Gambar Wallpaper...
                                    </button>
                                    <span id="wpFileNameLabel" style="font-size:9px; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Belum ada gambar baru</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>
                                    <span>Atau Masukkan URL Gambar Wallpaper</span>
                                    <span class="hint">Path relatif atau link online eksternal</span>
                                </label>
                                <input type="text" class="form-control" id="inp_login_bg_image" name="login_bg_image" 
                                       value="<?= htmlspecialchars($currentSettings['login_bg_image'] ?? '/assets/wallpaper-batik-default.png') ?>" 
                                       oninput="updateWallpaperSimulation()" placeholder="/assets/wallpaper-batik-default.png">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>
                                <span>Tingkat Kegelapan Overlay (Dimmer)</span>
                                <span class="hint" id="dimmerValueLabel"><?= (int)((floatval($currentSettings['login_bg_dimmer'] ?? 0)) * 100) ?>%</span>
                            </label>
                            <input type="range" id="inp_login_bg_dimmer" name="login_bg_dimmer" 
                                   min="0" max="0.8" step="0.05" 
                                   value="<?= htmlspecialchars($currentSettings['login_bg_dimmer'] ?? '0') ?>" 
                                   style="width:100%; cursor:pointer;" 
                                   oninput="updateDimmerValue(this.value)">
                            <div style="font-size:8px; color:var(--text-muted); margin-top:2px;">
                                Menggelapkan latar wallpaper secara halus jika diperlukan agar kartu form login tetap terbaca kontras.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. TAB: INTEGRASI CEISA 4.0 -->
                <?php if ($isAdmin && empty($targetCmpId)): ?>
                <div class="tab-pane" id="tab-ceisa">
                    <div class="form-section-title">
                        <i class="fa-solid fa-globe"></i> Konfigurasi Environment &amp; Gateway API CEISA 4.0
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Mode Environment CEISA 4.0*</span>
                                <span class="hint">Target server koneksi pabean</span>
                            </label>
                            <select class="form-control" id="inp_ceisa_env" name="ceisa_env">
                                <option value="production" <?= ($currentSettings['ceisa_env'] === 'production') ? 'selected' : '' ?>>Production (Live Bea Cukai)</option>
                                <option value="staging" <?= ($currentSettings['ceisa_env'] === 'staging') ? 'selected' : '' ?>>Staging / Sandbox / Uji Coba</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Base Gateway API URL*</span>
                                <span class="hint">Endpoint resmi REST API CEISA</span>
                            </label>
                            <input type="url" class="form-control" id="inp_ceisa_api_url" name="ceisa_api_url" 
                                   value="<?= htmlspecialchars($currentSettings['ceisa_api_url']) ?>" 
                                   placeholder="https://apis-gw.beacukai.go.id" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Interval Auto-Refresh Tracking Respon</span>
                                <span class="hint">Disarankan "Non-Aktif" untuk menjaga efisiensi dan kestabilan request portal</span>
                            </label>
                            <select class="form-control" id="inp_tracking_refresh" name="tracking_refresh">
                                <option value="0" <?= ($currentSettings['tracking_refresh'] === '0') ? 'selected' : '' ?>>Non-Aktif (Manual Saja - Direkomendasikan)</option>
                                <option value="60" <?= ($currentSettings['tracking_refresh'] === '60') ? 'selected' : '' ?>>Setiap 1 Menit</option>
                                <option value="120" <?= ($currentSettings['tracking_refresh'] === '120') ? 'selected' : '' ?>>Setiap 2 Menit</option>
                                <option value="300" <?= ($currentSettings['tracking_refresh'] === '300') ? 'selected' : '' ?>>Setiap 5 Menit</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>HTTP Request Timeout (Detik)</span>
                                <span class="hint">Batas toleransi koneksi ke CEISA</span>
                            </label>
                            <input type="number" class="form-control" id="inp_api_timeout" name="api_timeout" 
                                   value="<?= htmlspecialchars($currentSettings['api_timeout']) ?>" min="5" max="180">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Interval Sinkronisasi Latar Belakang (Menit)</span>
                                <span class="hint">Minimal 15 menit sekali agar penarikan tidak terlalu cepat</span>
                            </label>
                            <select class="form-control" id="inp_ceisa_sync_interval_minutes" name="ceisa_sync_interval_minutes">
                                <option value="60" <?= ((int)($currentSettings['ceisa_sync_interval_minutes'] ?? 60) === 60) ? 'selected' : '' ?>>60 Menit (1 Jam Sekali - Default &amp; Rekomendasi)</option>
                                <option value="15" <?= ((int)($currentSettings['ceisa_sync_interval_minutes'] ?? 60) === 15) ? 'selected' : '' ?>>15 Menit Sekali</option>
                                <option value="30" <?= ((int)($currentSettings['ceisa_sync_interval_minutes'] ?? 60) === 30) ? 'selected' : '' ?>>30 Menit Sekali</option>
                                <option value="120" <?= ((int)($currentSettings['ceisa_sync_interval_minutes'] ?? 60) === 120) ? 'selected' : '' ?>>120 Menit (2 Jam Sekali)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Jumlah Dokumen Tarik per Sinkronisasi (Batch Size)</span>
                                <span class="hint">Default 50 baris dokumen agar penarikan cepat &amp; ringan</span>
                            </label>
                            <select class="form-control" id="inp_ceisa_sync_batch_size" name="ceisa_sync_batch_size">
                                <option value="50" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 50) === 50) ? 'selected' : '' ?>>50 Baris (Default - Ringan &amp; Cepat)</option>
                                <option value="100" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 50) === 100) ? 'selected' : '' ?>>100 Baris</option>
                                <option value="200" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 50) === 200) ? 'selected' : '' ?>>200 Baris</option>
                                <option value="500" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 50) === 500) ? 'selected' : '' ?>>500 Baris</option>
                                <option value="1000" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 50) === 1000) ? 'selected' : '' ?>>1.000 Baris (Maksimal CEISA 4.0)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 4. TAB: MODUL IT INVENTORY -->
                <div class="tab-pane" id="tab-inventory">
                    <div class="form-section-title">
                        <i class="fa-solid fa-boxes-stacked"></i> Aturan Bisnis &amp; Penomoran Dokumen IT Inventory
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Prefiks Otomatis Dokumen SPP</span>
                                <span class="hint">Awalan nomor adjustment selisih stok opname</span>
                            </label>
                            <input type="text" class="form-control" id="inp_prefix_spp" name="prefix_spp" 
                                   value="<?= htmlspecialchars($currentSettings['prefix_spp']) ?>" placeholder="SPP-">
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Prefiks Dokumen Mutasi Produksi</span>
                                <span class="hint">Awalan bukti mutasi / transfer produksi internal</span>
                            </label>
                            <input type="text" class="form-control" id="inp_prefix_transfer" name="prefix_transfer" 
                                   value="<?= htmlspecialchars($currentSettings['prefix_transfer']) ?>" placeholder="TRF-">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Kunci Otomatis Dokumen AJU di IT Inventory</span>
                                <span class="hint">Dokumen yang sudah diposting terkunci otomatis</span>
                            </label>
                            <select class="form-control" name="lock_inventory_aju" id="inp_lock_inventory_aju">
                                <option value="1" <?= ($currentSettings['lock_inventory_aju'] === '1') ? 'selected' : '' ?>>Aktif (Terkunci Otomatis - Direkomendasikan)</option>
                                <option value="0" <?= ($currentSettings['lock_inventory_aju'] === '0') ? 'selected' : '' ?>>Non-Aktif (Bebas Diedit / Dihapus)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Konfirmasi Hapus Data AJU</span>
                                <span class="hint">Mencegah penghapusan tidak sengaja</span>
                            </label>
                            <select class="form-control" name="require_confirm_ya" id="inp_require_confirm_ya">
                                <option value="1" <?= ($currentSettings['require_confirm_ya'] === '1') ? 'selected' : '' ?>>Wajib Ketik "YA" Saat Hapus</option>
                                <option value="0" <?= ($currentSettings['require_confirm_ya'] === '0') ? 'selected' : '' ?>>Konfirmasi Biasa</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 6px;">
                        <label>
                            <span>Tahun Buku Default</span>
                            <span class="hint">Otomatis mengikuti tahun berjalan sistem atau kunci tahun tertentu</span>
                        </label>
                        <select class="form-control" id="inp_default_tahun_buku" name="default_tahun_buku">
                            <option value="auto" <?= ($currentSettings['default_tahun_buku'] === 'auto') ? 'selected' : '' ?>>Otomatis (Tahun Berjalan Saat Ini)</option>
                            <option value="2026" <?= ($currentSettings['default_tahun_buku'] === '2026') ? 'selected' : '' ?>>Tahun Buku 2026</option>
                            <option value="2025" <?= ($currentSettings['default_tahun_buku'] === '2025') ? 'selected' : '' ?>>Tahun Buku 2025</option>
                        </select>
                    </div>
                </div>

                <!-- 5. TAB: TAMPILAN & PREFERENSI -->
                <div class="tab-pane" id="tab-ui">
                    <?php if ($isSuperAdminDp): ?>
                    <div class="form-section-title">
                        <i class="fa-solid fa-layer-group"></i> Mode Modul Sistem &amp; Arsitektur Navigasi Portal
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Mode Modul &amp; Tampilan Sidebar</span>
                                <span class="hint">Pilihan antara tampilan standar klasik atau enterprise multi-modul</span>
                            </label>
                            <select class="form-control" id="inp_app_mode" name="app_mode">
                                <option value="standard" <?= (($currentSettings['app_mode'] ?? 'standard') === 'standard') ? 'selected' : '' ?>>📋 Mode Standar (Sidebar Tunggal Klasik - Kepabeanan &amp; IT Inventory)</option>
                                <option value="enterprise" <?= (($currentSettings['app_mode'] ?? 'standard') === 'enterprise') ? 'selected' : '' ?>>🏢 Mode Enterprise (Dual-Rail Outlook Sidebar - Modul ERP Purchasing, Sales &amp; Finance)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Fitur Tab Workspace (Multi-Document Tabs)</span>
                                <span class="hint">Kemampuan membuka banyak menu sekaligus dalam tab-tab di atas</span>
                            </label>
                            <select class="form-control" id="inp_enable_workspace_tabs" name="enable_workspace_tabs">
                                <option value="1" <?= (($currentSettings['enable_workspace_tabs'] ?? '1') === '1') ? 'selected' : '' ?>>🗂️ Aktif (Multi-Tab Workspace - Tanpa Reload Halaman)</option>
                                <option value="0" <?= (($currentSettings['enable_workspace_tabs'] ?? '1') === '0') ? 'selected' : '' ?>>📄 Non-Aktif (Mode Single Page Klasik / Tanpa Bar Tab)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title" style="margin-top: 15px;">
                        <i class="fa-solid fa-credit-card"></i> Model Lisensi &amp; Langganan (Subscription Billing)
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Model Lisensi Penggunaan</span>
                                <span class="hint">Pengaturan masa aktif dan sistem penagihan otomatis</span>
                            </label>
                            <select class="form-control" id="inp_license_model" name="license_model">
                                <option value="unlimited" <?= (($currentSettings['license_model'] ?? 'unlimited') === 'unlimited') ? 'selected' : '' ?>>♾️ Mode Unlimited (Bebas Masa Aktif / Lifetime)</option>
                                <option value="subscription" <?= (($currentSettings['license_model'] ?? 'unlimited') === 'subscription') ? 'selected' : '' ?>>💳 Mode Berbayar Langganan (Subscription Based - Billing QRIS)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Status Persiapan Integrasi QRIS Payment Gateway</span>
                                <span class="hint">Arsitektur penagihan otomatis invoice per perusahaan</span>
                            </label>
                            <div style="padding: 7px 10px; background: var(--bg-base); border: 1px solid var(--border-color); border-radius: 6px; font-size: 11px; color: var(--text-secondary); line-height: 1.4;">
                                <i class="fa-solid fa-circle-check" style="color: #52c41a;"></i> Skema database <code>subscription_type</code> &amp; <code>expired_date</code> telah siap aktif. Integrasi checkout QRIS siap dikembangkan pada fase berikutnya.
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-section-title" <?= $isSuperAdminDp ? 'style="margin-top: 15px;"' : '' ?>>
                        <i class="fa-solid fa-palette"></i> Preferensi Tampilan Portal &amp; Grid Tabel
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Tipe Font Utama Aplikasi (Typography Family)</span>
                                <span class="hint">Standarisasi tipografi seragam untuk menu navigasi portal, formulir input, dan tabel data ERP</span>
                            </label>
                            <select class="form-control" id="inp_app_font" name="app_font" onchange="previewAppFont(this.value)">
                                <option value="Poppins" <?= (($currentSettings['app_font'] ?? 'Poppins') === 'Poppins') ? 'selected' : '' ?>>✨ Poppins (Bawaan / Standar Rekomendasi)</option>
                                <option value="Inter" <?= (($currentSettings['app_font'] ?? 'Poppins') === 'Inter') ? 'selected' : '' ?>>🏢 Inter (Enterprise &amp; Modern Clean Sans)</option>
                                <option value="Source Sans Pro" <?= (($currentSettings['app_font'] ?? 'Poppins') === 'Source Sans Pro') ? 'selected' : '' ?>>📊 Source Sans Pro (Klasik ERP &amp; DataGrid)</option>
                                <option value="Roboto" <?= (($currentSettings['app_font'] ?? 'Poppins') === 'Roboto') ? 'selected' : '' ?>>📱 Roboto (Clean Material Design)</option>
                                <option value="Segoe UI" <?= (($currentSettings['app_font'] ?? 'Poppins') === 'Segoe UI') ? 'selected' : '' ?>>🪟 Segoe UI (Windows Desktop Native)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Ukuran Font Kontrol &amp; Form (Base Font Size)</span>
                                <span class="hint">Skala ukuran teks formulir input, label, dropdown, grid data, dan side menu</span>
                            </label>
                            <select class="form-control" id="inp_app_font_size" name="app_font_size" onchange="previewAppFontSize(this.value)">
                                <option value="9.5px" <?= (($currentSettings['app_font_size'] ?? '9.5px') === '9.5px') ? 'selected' : '' ?>>✨ 9.5px (Kompak Standar PB / Seragam Side Menu - Rekomendasi)</option>
                                <option value="9px" <?= (($currentSettings['app_font_size'] ?? '9.5px') === '9px') ? 'selected' : '' ?>>📐 9.0px (Sangat Kompak / Ultra-dense)</option>
                                <option value="10px" <?= (($currentSettings['app_font_size'] ?? '9.5px') === '10px') ? 'selected' : '' ?>>📏 10.0px (Kompak Sedang)</option>
                                <option value="10.5px" <?= (($currentSettings['app_font_size'] ?? '9.5px') === '10.5px') ? 'selected' : '' ?>>📄 10.5px (Standar Normal)</option>
                                <option value="11px" <?= (($currentSettings['app_font_size'] ?? '9.5px') === '11px') ? 'selected' : '' ?>>🔍 11.0px (Besar &amp; Nyaman)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row single" style="margin-top:-6px;">
                        <div class="form-group">
                            <div id="fontPreviewBox" style="padding: 7px 12px; background: var(--bg-base); border: 1px solid var(--border-color); border-radius: 6px; font-size: <?= htmlspecialchars($currentSettings['app_font_size'] ?? '9.5px') ?>; font-family: '<?= htmlspecialchars($currentSettings['app_font'] ?? 'Poppins') ?>', sans-serif;">
                                <span style="font-size:9px; color:var(--text-muted); display:block; margin-bottom:2px; font-weight:600; text-transform:uppercase;">Pratinjau Karakter &amp; Ukuran (Live Preview):</span>
                                <b>PO-2026-001</b> • PT. INHOST TEKNOLOGI • Alamat Supplier: Jl. Industri No. 8 • ABCDEFGHIJKLMNOPQRSTUVWXYZ abcdefghijklmnopqrstuvwxyz 1234567890 • Rp 125.000.000
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Tema Default Portal</span>
                                <span class="hint">Preferensi warna latar default akun baru</span>
                            </label>
                            <select class="form-control" id="inp_default_theme" name="default_theme">
                                <option value="light" <?= ($currentSettings['default_theme'] === 'light') ? 'selected' : '' ?>>☀️ Mode Terang (Light Theme)</option>
                                <option value="dark" <?= ($currentSettings['default_theme'] === 'dark') ? 'selected' : '' ?>>🌙 Mode Gelap (Dark Theme)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Gaya Navigasi Sidebar Default</span>
                                <span class="hint">Posisi sidebar menu utama saat login</span>
                            </label>
                            <select class="form-control" id="inp_default_sidebar" name="default_sidebar">
                                <option value="pinned" <?= ($currentSettings['default_sidebar'] === 'pinned') ? 'selected' : '' ?>>📌 Pinned (Sidebar Terbuka Permanen)</option>
                                <option value="unpinned" <?= ($currentSettings['default_sidebar'] === 'unpinned') ? 'selected' : '' ?>>↔️ Autohide / Minimalis</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Gaya Ikon Sidebar Menu</span>
                                <span class="hint">Pilihan ikon navigasi utama</span>
                            </label>
                            <select class="form-control" id="inp_sidebar_icon_style" name="sidebar_icon_style">
                                <option value="colored" <?= ($currentSettings['sidebar_icon_style'] === 'colored') ? 'selected' : '' ?>>🎨 Ikon Berwarna / Emoji</option>
                                <option value="monochrome" <?= ($currentSettings['sidebar_icon_style'] === 'monochrome') ? 'selected' : '' ?>>◼️ Monokrom Minimalis</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Jumlah Baris Tabel per Halaman (Page Size)</span>
                                <span class="hint">Bawaan pagination grid IT Inventory &amp; Dokumen</span>
                            </label>
                            <select class="form-control" id="inp_default_page_size" name="default_page_size">
                                <option value="10" <?= ($currentSettings['default_page_size'] === '10') ? 'selected' : '' ?>>10 Baris per Halaman</option>
                                <option value="25" <?= ($currentSettings['default_page_size'] === '25') ? 'selected' : '' ?>>25 Baris (Standar Direkomendasikan)</option>
                                <option value="50" <?= ($currentSettings['default_page_size'] === '50') ? 'selected' : '' ?>>50 Baris</option>
                                <option value="100" <?= ($currentSettings['default_page_size'] === '100') ? 'selected' : '' ?>>100 Baris</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Kartu Perusahaan di Sidebar (Company Card)</span>
                                <span class="hint">Kotak info perusahaan di bawah header sidebar</span>
                            </label>
                            <select class="form-control" id="inp_show_company_card" name="show_company_card" onchange="syncCompanyCardSetting(this.value)">
                                <option value="auto" <?= (($currentSettings['show_company_card'] ?? 'auto') === 'auto') ? 'selected' : '' ?>>⚙️ Otomatis (Default: Sembunyikan untuk User Perusahaan, Tampilkan untuk Super Admin)</option>
                                <option value="0" <?= (($currentSettings['show_company_card'] ?? 'auto') === '0') ? 'selected' : '' ?>>👁️‍🗨️ Sembunyikan (HIDE - Bersih &amp; Rapi)</option>
                                <option value="1" <?= (($currentSettings['show_company_card'] ?? 'auto') === '1') ? 'selected' : '' ?>>👁️ Selalu Tampilkan (SHOW)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Durasi Notifikasi Toast</span>
                                <span class="hint">Durasi pop-up pesan informasi sistem</span>
                            </label>
                            <select class="form-control" id="inp_toast_duration" name="toast_duration">
                                <option value="1800" <?= (($currentSettings['toast_duration'] ?? '2600') === '1800') ? 'selected' : '' ?>>1.8 Detik (Cepat)</option>
                                <option value="2600" <?= (($currentSettings['toast_duration'] ?? '2600') === '2600') ? 'selected' : '' ?>>2.6 Detik (Standar)</option>
                                <option value="4000" <?= (($currentSettings['toast_duration'] ?? '2600') === '4000') ? 'selected' : '' ?>>4.0 Detik (Santai)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title" style="margin-top: 15px;">
                        <i class="fa-solid fa-chart-line"></i> Audit &amp; Pemantauan Pengunjung (Visitor &amp; Menu Analytics)
                    </div>
                    <div class="form-row single">
                        <div class="form-group">
                            <label>
                                <span>Pencatatan Riwayat Menu &amp; IP Pengunjung (Audit Log)</span>
                                <span class="hint">Memantau menu yang dibuka dan IP pengunjung di URL /visitor</span>
                            </label>
                            <select class="form-control" id="inp_enable_visitor_menu_log" name="enable_visitor_menu_log">
                                <option value="1" <?= (($currentSettings['enable_visitor_menu_log'] ?? '1') === '1') ? 'selected' : '' ?>>✅ Aktif (Catat Riwayat Menu &amp; IP Pengunjung dengan Jam WIB)</option>
                                <option value="0" <?= (($currentSettings['enable_visitor_menu_log'] ?? '1') === '0') ? 'selected' : '' ?>>⛔ Non-Aktif (Hentikan Pencatatan Menu)</option>
                            </select>
                            <div style="font-size:8.5px; color:var(--text-muted); margin-top:3px;">
                                Menggunakan mekanisme background beacon berbobot sangat ringan (0 ms latensi antarmuka). Waktu pencatatan terstandarisasi Waktu Indonesia Barat (WIB / Asia/Jakarta).
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isSuperAdminDp): ?>
                <!-- 6. TAB: WHATSAPP GATEWAY (KHUSUS DP) -->
                <div class="tab-pane" id="tab-wa">
                    <div class="form-section-title">
                        <i class="fa-brands fa-whatsapp" style="color:#25d366;"></i> Konfigurasi Notifikasi WhatsApp (WA Gateway)
                    </div>
                    
                    <div style="margin-bottom:12px; padding:10px 14px; background:rgba(37,211,102,0.08); border:1px solid rgba(37,211,102,0.3); border-radius:6px; font-size:11px; color:var(--text-main); line-height:1.5;">
                        <i class="fa-solid fa-shield-halved" style="color:#25d366; margin-right:4px;"></i> <strong>Khusus Super Administrator (dp):</strong> Pengaturan API Key WhatsApp digunakan untuk seluruh notifikasi otomatis sistem (notifikasi tiket baru dan penyelesaian kendala ke nomor WA pengguna &amp; admin).
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex:2;">
                            <label>
                                <span>API Key WhatsApp Gateway *</span>
                                <span class="hint">Bearer Token API Gateway WhatsApp (https://my.vpsmurah.co.id)</span>
                            </label>
                            <div style="position:relative;">
                                <input type="password" class="form-control" id="inp_wa_api_key" name="wa_api_key" 
                                       value="<?= htmlspecialchars($currentSettings['wa_api_key'] ?? 'sk-api-5adnzcm2j7vbeyl8y25xuew3598f24tbzlm4l8b1') ?>" 
                                       style="padding-right:32px;">
                                <button type="button" onclick="toggleWaKeyVisibility()" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); border:none; background:none; cursor:pointer; color:var(--text-muted);">
                                    <i class="fa-solid fa-eye" id="iconWaEye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>
                                <span>Status Notifikasi WA</span>
                                <span class="hint">Aktifkan atau nonaktifkan pengiriman otomatis</span>
                            </label>
                            <select class="form-control" id="inp_wa_notif_enabled" name="wa_notif_enabled">
                                <option value="1" <?= (($currentSettings['wa_notif_enabled'] ?? '1') === '1') ? 'selected' : '' ?>>✅ Aktif (Kirim Otomatis)</option>
                                <option value="0" <?= (($currentSettings['wa_notif_enabled'] ?? '1') === '0') ? 'selected' : '' ?>>⛔ Non-Aktif</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="flex:1;">
                            <label>
                                <span>Nomor WA Admin Mas Wawan / dp (Format 628xxx)</span>
                                <span class="hint">Menerima alert pesan setiap kali ada pengguna membuat Tiket baru</span>
                            </label>
                            <input type="text" class="form-control" id="inp_wa_admin_number" name="wa_admin_number" 
                                   value="<?= htmlspecialchars($currentSettings['wa_admin_number'] ?? '') ?>" 
                                   placeholder="Contoh: 6281234567890" onblur="if(this.value) this.value = formatWaJs(this.value)">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>
                                <span>Endpoint API URL</span>
                                <span class="hint">REST API Endpoint Pengiriman Pesan</span>
                            </label>
                            <input type="text" class="form-control" id="inp_wa_api_url" name="wa_api_url" 
                                   value="<?= htmlspecialchars($currentSettings['wa_api_url'] ?? 'https://my.vpsmurah.co.id/api/svc/v1/wa-api/messages') ?>" readonly style="background:var(--bg-base); opacity:0.85;">
                        </div>
                    </div>

                    <div class="form-section-title" style="margin-top:16px; display:flex; align-items:center; justify-content:space-between;">
                        <span><i class="fa-solid fa-network-wired" style="color:#25d366;"></i> Operasional WhatsApp Gateway (Inbox, Kontak, Media &amp; Pesan)</span>
                    </div>

                    <!-- Subtabs WhatsApp Gateway -->
                    <div style="display:flex; gap:6px; margin-bottom:12px; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
                        <button type="button" class="ant-btn ant-btn-sm wa-subtab-btn active" id="btnWaSub_inbox" onclick="switchWaSubTab('inbox')" style="height:24px; font-size:9.5px; padding:0 10px;">
                            <i class="fa-solid fa-inbox" style="color:#1890ff;"></i> Kotak Masuk (Inbox)
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm wa-subtab-btn" id="btnWaSub_contacts" onclick="switchWaSubTab('contacts')" style="height:24px; font-size:9.5px; padding:0 10px;">
                            <i class="fa-solid fa-address-book" style="color:#722ed1;"></i> Buku Kontak
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm wa-subtab-btn" id="btnWaSub_media" onclick="switchWaSubTab('media')" style="height:24px; font-size:9.5px; padding:0 10px;">
                            <i class="fa-solid fa-photo-film" style="color:#fa8c16;"></i> Kirim Media (Foto/File)
                        </button>
                        <button type="button" class="ant-btn ant-btn-sm wa-subtab-btn" id="btnWaSub_text" onclick="switchWaSubTab('text')" style="height:24px; font-size:9.5px; padding:0 10px;">
                            <i class="fa-solid fa-comment-dots" style="color:#25d366;"></i> Kirim Pesan Teks
                        </button>
                    </div>

                    <!-- 1. SUBPANEL: INBOX -->
                    <div id="waSubPanel_inbox" class="wa-subpanel" style="background:var(--bg-base); border:1px solid var(--border-color); border-radius:6px; padding:12px 14px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <div>
                                <strong style="font-size:11px;"><i class="fa-solid fa-inbox" style="color:#1890ff;"></i> Kotak Masuk Pesan WhatsApp</strong>
                                <div style="font-size:9px; color:var(--text-muted);">Membaca pesan WhatsApp yang masuk ke nomor gateway via <code>GET /api/svc/v1/wa-api/inbox</code></div>
                            </div>
                            <button type="button" class="ant-btn ant-btn-sm" onclick="loadWaInbox()" id="btnRefreshInbox" style="height:24px; font-size:9.5px;">
                                <i class="fa-solid fa-rotate"></i> Muat Ulang Inbox
                            </button>
                        </div>
                        <div id="waInboxContent">
                            <div style="text-align:center; padding:18px; color:var(--text-muted); font-size:10px;">
                                <i class="fa-solid fa-inbox" style="font-size:24px; display:block; margin-bottom:6px; opacity:0.4;"></i>
                                Klik tombol <strong>"Muat Ulang Inbox"</strong> untuk memeriksa pesan WhatsApp terbaru.
                            </div>
                        </div>
                    </div>

                    <!-- 2. SUBPANEL: KONTAK -->
                    <div id="waSubPanel_contacts" class="wa-subpanel" style="display:none; background:var(--bg-base); border:1px solid var(--border-color); border-radius:6px; padding:12px 14px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px;">
                            <div>
                                <strong style="font-size:11px;"><i class="fa-solid fa-address-book" style="color:#722ed1;"></i> Daftar Kontak WhatsApp Gateway</strong>
                                <div style="font-size:9px; color:var(--text-muted);">Membaca kontak yang tersimpan di nomor gateway via <code>GET /api/svc/v1/wa-api/contacts</code></div>
                            </div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="text" id="filterWaContacts" class="form-control" placeholder="Cari nama / nomor..." oninput="filterContactsList()" style="width:160px; height:24px; font-size:9.5px;">
                                <button type="button" class="ant-btn ant-btn-sm" onclick="loadWaContacts()" id="btnRefreshContacts" style="height:24px; font-size:9.5px;">
                                    <i class="fa-solid fa-rotate"></i> Muat Kontak
                                </button>
                            </div>
                        </div>
                        <div id="waContactsContent">
                            <div style="text-align:center; padding:18px; color:var(--text-muted); font-size:10px;">
                                <i class="fa-solid fa-address-book" style="font-size:24px; display:block; margin-bottom:6px; opacity:0.4;"></i>
                                Klik tombol <strong>"Muat Kontak"</strong> untuk mengambil daftar kontak dari gateway.
                            </div>
                        </div>
                    </div>

                    <!-- 3. SUBPANEL: KIRIM MEDIA -->
                    <div id="waSubPanel_media" class="wa-subpanel" style="display:none; background:var(--bg-base); border:1px solid var(--border-color); border-radius:6px; padding:12px 14px;">
                        <div style="margin-bottom:10px;">
                            <strong style="font-size:11px;"><i class="fa-solid fa-photo-film" style="color:#fa8c16;"></i> Kirim Berkas Media (Gambar, Dokumen, Video, Audio)</strong>
                            <div style="font-size:9px; color:var(--text-muted);">Mengirimkan berkas via REST API <code>POST /api/svc/v1/wa-api/media</code></div>
                        </div>
                        <div class="form-row" style="margin-bottom:8px;">
                            <div class="form-group" style="flex:1;">
                                <label>Nomor Tujuan (628xxx): *</label>
                                <input type="text" class="form-control" id="media_wa_phone" placeholder="Contoh: 6281234567890" onblur="if(this.value) this.value = formatWaJs(this.value)">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Tipe Media: *</label>
                                <select class="form-control" id="media_wa_type">
                                    <option value="image">🖼️ Image / Foto (JPG/PNG)</option>
                                    <option value="document">📄 Document / Berkas (PDF/XLS/DOC)</option>
                                    <option value="video">🎥 Video (MP4)</option>
                                    <option value="audio">🎵 Audio (MP3/OGG)</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row" style="margin-bottom:8px;">
                            <div class="form-group" style="flex:2;">
                                <label>URL Langsung Berkas Media: *</label>
                                <input type="text" class="form-control" id="media_wa_url" placeholder="https://domain.com/path/berkas.jpg">
                            </div>
                            <div class="form-group" style="flex:2;">
                                <label>Keterangan / Caption Pesan (Opsional):</label>
                                <input type="text" class="form-control" id="media_wa_caption" placeholder="Contoh: Lampiran Dokumen Invoice / Pemberitahuan">
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <button type="button" class="btn btn-default" onclick="sendTestWaMedia()" id="btnSendMedia" style="border-color:#fa8c16; color:#fa8c16;">
                                <i class="fa-solid fa-paper-plane"></i> Kirim Media Sekarang
                            </button>
                            <span id="testWaMediaResult" style="font-size:10.5px; font-weight:600;"></span>
                        </div>
                    </div>

                    <!-- 4. SUBPANEL: KIRIM PESAN TEKS -->
                    <div id="waSubPanel_text" class="wa-subpanel" style="display:none; background:var(--bg-base); border:1px solid var(--border-color); border-radius:6px; padding:12px 14px;">
                        <div style="margin-bottom:10px;">
                            <strong style="font-size:11px;"><i class="fa-solid fa-comment-dots" style="color:#25d366;"></i> Uji Coba Pengiriman Pesan Teks</strong>
                            <div style="font-size:9px; color:var(--text-muted);">Mengirimkan pesan teks via REST API <code>POST /api/svc/v1/wa-api/messages</code></div>
                        </div>
                        <div class="form-row" style="margin-bottom:8px;">
                            <div class="form-group" style="flex:1;">
                                <label>Nomor HP Tujuan Tes (628xxx):</label>
                                <input type="text" class="form-control" id="test_wa_phone" placeholder="Contoh: 6281234567890" onblur="if(this.value) this.value = formatWaJs(this.value)">
                            </div>
                            <div class="form-group" style="flex:2;">
                                <label>Isi Pesan Uji Coba:</label>
                                <input type="text" class="form-control" id="test_wa_message" value="Halo Mas Wawan! Tes pengiriman WhatsApp Gateway CEISA 4.0 berhasil terhubung.">
                            </div>
                        </div>
                        <div style="display:flex; align-items:center;">
                            <button type="button" class="btn btn-default" onclick="sendTestWaMessage()" id="btnTestWa" style="border-color:#25d366; color:#25d366;">
                                <i class="fa-brands fa-whatsapp"></i> Kirim Tes Pesan Sekarang
                            </button>
                            <span id="testWaResult" style="margin-left:14px; font-size:10.5px; font-weight:600;"></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Right Column: Live Sticky Previews -->
            <div class="settings-sidebar-wrap">
                <div class="preview-sticky-card">
                    <div class="preview-header-title">
                        <i class="fa-solid fa-eye" style="color:var(--primary);"></i> Pratinjau Langsung (Live Preview)
                    </div>

                    <!-- 1. Header Sidebar Preview -->
                    <div style="font-size:9px; font-weight:700; color:var(--text-secondary); margin-bottom:4px;">
                        Header Brand Sidebar:
                    </div>
                    <div class="live-preview-box">
                        <div class="preview-sidebar-brand">
                            <img src="<?= htmlspecialchars($currentSettings['app_logo']) ?>" class="preview-brand-logo" id="previewBrandLogo" alt="Logo">
                            <div class="preview-brand-text">
                                <span class="preview-brand-name" id="previewBrandName"><?= htmlspecialchars($currentSettings['app_name']) ?></span>
                                <span class="preview-brand-sub" id="previewBrandSub"><?= htmlspecialchars($currentSettings['app_subtitle']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Login Badge Preview -->
                    <div style="font-size:9px; font-weight:700; color:var(--text-secondary); margin-bottom:4px;">
                        Badge di Atas Kartu Login:
                    </div>
                    <div class="live-preview-box" style="text-align:center;">
                        <div style="display:inline-flex; align-items:center; gap:10px; background:var(--bg-card); border:1px solid var(--border-color); padding:6px 12px; border-radius:8px;">
                            <img src="<?= htmlspecialchars($currentSettings['app_logo']) ?>" id="previewLoginBadgeLogo" style="width:32px; height:32px; object-fit:contain; border-radius:5px; background:#fff; padding:2px; border:1px solid var(--border-light);">
                            <div style="text-align:left;">
                                <div style="font-size:12px; font-weight:800; color:#1890ff; line-height:1.15;" id="previewLoginBadgeName"><?= htmlspecialchars($currentSettings['app_name']) ?></div>
                                <div style="font-size:6.5px; font-weight:700; color:#8c8c8c; text-transform:uppercase; margin-top:2px;" id="previewLoginBadgeSub"><?= htmlspecialchars($currentSettings['app_subtitle']) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Simulasi Layar Login di Atas Wallpaper -->
                    <div style="font-size:9px; font-weight:700; color:var(--text-secondary); margin-bottom:4px;">
                        Simulasi Layar Login &amp; Wallpaper:
                    </div>
                    <div class="live-login-simulation" id="liveLoginSim">
                        <div class="live-login-overlay" id="liveLoginOverlay"></div>
                        <div class="live-login-mini-card">
                            <div class="live-login-mini-badge">
                                <img src="<?= htmlspecialchars($currentSettings['app_logo']) ?>" class="live-login-mini-logo" id="simMiniLogo">
                                <div style="text-align:left;">
                                    <div class="live-login-mini-title" id="simMiniTitle"><?= htmlspecialchars($currentSettings['app_name']) ?></div>
                                    <div class="live-login-mini-sub" id="simMiniSub"><?= htmlspecialchars($currentSettings['app_subtitle']) ?></div>
                                </div>
                            </div>
                            <div style="font-size:8px; font-weight:700; margin-bottom:2px;"><?= $isCustomScope ? htmlspecialchars($targetCmpName) : 'PORTAL CEISA 4.0' ?></div>
                            <div style="font-size:6.5px; color:#8c8c8c; margin-bottom:6px;">Portal Akses Interface Host to Host</div>
                            <input type="text" class="live-login-mini-input" value="9120109470315" readonly>
                            <input type="password" class="live-login-mini-input" value="••••" readonly>
                            <button type="button" class="live-login-mini-btn">MASUK KE SISTEM</button>
                        </div>
                    </div>

                    <div style="font-size:8px; color:var(--text-muted); text-align:center; margin-top:8px;">
                        Perubahan akan langsung terlihat saat Anda mengklik tombol <strong>Simpan Perubahan</strong>.
                    </div>
                </div>
            </div>

        </div>
    </form>

    <script>
        function switchSettingsTab(tabId, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            if (btn) btn.classList.add('active');
            const target = document.getElementById(tabId);
            if (target) target.classList.add('active');
        }

        function syncCompanyCardSetting(val) {
            const selBrand = document.getElementById('inp_show_company_card_brand');
            const selUi = document.getElementById('inp_show_company_card');
            if (selBrand && selBrand.value !== val) selBrand.value = val;
            if (selUi && selUi.value !== val) selUi.value = val;
        }

        function switchScope(cmpId) {
            const url = new URL(window.location.href);
            if (cmpId) {
                url.searchParams.set('cmp_id', cmpId);
            } else {
                url.searchParams.delete('cmp_id');
            }
            window.location.href = url.toString();
        }

        function selectLogoPreset(logoPath, element, customFilter) {
            document.querySelectorAll('.preset-logo-item').forEach(el => el.classList.remove('active'));
            if (element) element.classList.add('active');
            const inp = document.getElementById('inp_app_logo');
            if (inp) {
                inp.value = logoPath;
                updateLivePreview();
            }
            if (customFilter) {
                const previewImg = document.getElementById('previewBrandLogo');
                if (previewImg) previewImg.style.filter = customFilter;
                const badgeImg = document.getElementById('previewLoginBadgeLogo');
                if (badgeImg) badgeImg.style.filter = customFilter;
                const simImg = document.getElementById('simMiniLogo');
                if (simImg) simImg.style.filter = customFilter;
            } else {
                const previewImg = document.getElementById('previewBrandLogo');
                if (previewImg) previewImg.style.filter = 'none';
                const badgeImg = document.getElementById('previewLoginBadgeLogo');
                if (badgeImg) badgeImg.style.filter = 'none';
                const simImg = document.getElementById('simMiniLogo');
                if (simImg) simImg.style.filter = 'none';
            }
        }

        function handleLogoFileSelect(e) {
            const file = e.target.files[0];
            const label = document.getElementById('fileNameLabel');
            if (file) {
                if (label) label.innerText = file.name;
                const reader = new FileReader();
                reader.onload = function(evt) {
                    const dataUrl = evt.target.result;
                    const previewImg = document.getElementById('previewBrandLogo');
                    if (previewImg) previewImg.src = dataUrl;
                    const badgeImg = document.getElementById('previewLoginBadgeLogo');
                    if (badgeImg) badgeImg.src = dataUrl;
                    const simImg = document.getElementById('simMiniLogo');
                    if (simImg) simImg.src = dataUrl;
                };
                reader.readAsDataURL(file);
            } else {
                if (label) label.innerText = 'Belum ada file dipilih';
            }
        }

        function useDefaultBatikWallpaper() {
            const inpImg = document.getElementById('inp_login_bg_image');
            const inpDim = document.getElementById('inp_login_bg_dimmer');
            if (inpImg) inpImg.value = '/assets/wallpaper-batik-default.png';
            if (inpDim) {
                inpDim.value = '0';
                updateDimmerValue('0');
            }
            selectWallpaperType('image');
        }

        function selectWallpaperType(type) {
            document.querySelectorAll('.wp-type-card').forEach(c => c.classList.remove('active'));
            const card = document.getElementById('card_type_' + type);
            if (card) card.classList.add('active');
            
            const inpType = document.getElementById('inp_login_bg_type');
            if (inpType) inpType.value = type;

            const secGrad = document.getElementById('section_wp_gradient');
            const secImg  = document.getElementById('section_wp_image');

            if (type === 'gradient') {
                if (secGrad) secGrad.style.display = 'block';
                if (secImg)  secImg.style.display = 'none';
            } else if (type === 'image') {
                if (secGrad) secGrad.style.display = 'none';
                if (secImg)  secImg.style.display = 'block';
                const inpImg = document.getElementById('inp_login_bg_image');
                if (inpImg && !inpImg.value.trim()) {
                    inpImg.value = '/assets/wallpaper-batik-default.png';
                }
            } else {
                if (secGrad) secGrad.style.display = 'none';
                if (secImg)  secImg.style.display = 'none';
            }

            updateWallpaperSimulation();
        }

        function selectGradientPreset(gradientValue, el) {
            document.querySelectorAll('.gradient-item').forEach(g => g.classList.remove('active'));
            if (el) el.classList.add('active');
            const inp = document.getElementById('inp_login_bg_gradient');
            if (inp) {
                inp.value = gradientValue;
                updateWallpaperSimulation();
            }
        }

        function handleWallpaperFileSelect(e) {
            const file = e.target.files[0];
            const label = document.getElementById('wpFileNameLabel');
            if (file) {
                if (label) label.innerText = file.name;
                const reader = new FileReader();
                reader.onload = function(evt) {
                    const dataUrl = evt.target.result;
                    const sim = document.getElementById('liveLoginSim');
                    if (sim) {
                        sim.style.backgroundImage = `url("${dataUrl}")`;
                        sim.style.backgroundSize = 'cover';
                        sim.style.backgroundPosition = 'center';
                    }
                };
                reader.readAsDataURL(file);
            } else {
                if (label) label.innerText = 'Belum ada gambar baru';
            }
        }

        function updateDimmerValue(val) {
            const pct = Math.round(parseFloat(val) * 100);
            const lbl = document.getElementById('dimmerValueLabel');
            if (lbl) lbl.innerText = pct + '%';
            const overlay = document.getElementById('liveLoginOverlay');
            if (overlay) {
                overlay.style.backgroundColor = `rgba(0, 0, 0, ${val})`;
            }
        }

        function updateWallpaperSimulation() {
            const type = document.getElementById('inp_login_bg_type')?.value || 'default';
            const sim = document.getElementById('liveLoginSim');
            const overlay = document.getElementById('liveLoginOverlay');
            if (!sim) return;

            const dimmer = document.getElementById('inp_login_bg_dimmer')?.value || '0';
            if (overlay) {
                overlay.style.backgroundColor = `rgba(0, 0, 0, ${dimmer})`;
                overlay.style.display = (parseFloat(dimmer) > 0.01) ? 'block' : 'none';
            }

            if (type === 'image') {
                const imgUrl = document.getElementById('inp_login_bg_image')?.value.trim() || '/assets/wallpaper-batik-default.png';
                const fileInput = document.getElementById('fileWallpaperUpload');
                if (fileInput && fileInput.files && fileInput.files[0]) {
                    // Handled by FileReader in handleWallpaperFileSelect
                } else if (imgUrl) {
                    sim.style.backgroundImage = `url("${imgUrl}")`;
                    sim.style.backgroundColor = '#ffffff';
                    sim.style.backgroundSize = 'cover';
                    sim.style.backgroundPosition = 'center center';
                    sim.style.backgroundRepeat = 'no-repeat';
                } else {
                    sim.style.backgroundImage = 'none';
                    sim.style.background = '#ffffff';
                }
                if (overlay) overlay.style.display = (parseFloat(dimmer) > 0.01) ? 'block' : 'none';
            } else if (type === 'gradient') {
                const grad = document.getElementById('inp_login_bg_gradient')?.value.trim() || 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)';
                sim.style.backgroundImage = 'none';
                sim.style.background = grad;
                if (overlay) overlay.style.display = 'none';
            } else {
                // Default Sistem
                sim.style.backgroundImage = 'none';
                sim.style.background = 'radial-gradient(at 0% 0%, rgba(24, 144, 255, 0.15) 0px, transparent 50%), radial-gradient(at 100% 100%, rgba(14, 165, 233, 0.12) 0px, transparent 50%), #f1f5f9';
                if (overlay) overlay.style.display = 'none';
            }
        }

        function updateLivePreview() {
            const appName = document.getElementById('inp_app_name')?.value || 'INHOST';
            const appSub  = document.getElementById('inp_app_subtitle')?.value || 'Interface Host to Host Ceisa 4.0';
            const appLogo = document.getElementById('inp_app_logo')?.value || '/assets/logo.png';

            const nameEl = document.getElementById('previewBrandName');
            if (nameEl) {
                nameEl.innerText = appName;
                const fontSize = document.getElementById('inp_sidebar_brand_font_size')?.value;
                if (fontSize) nameEl.style.fontSize = fontSize;
            }
            const subEl = document.getElementById('previewBrandSub');
            if (subEl) {
                subEl.innerText = appSub;
                const subFontSize = document.getElementById('inp_sidebar_brand_sub_font_size')?.value;
                if (subFontSize) subEl.style.fontSize = subFontSize;
            }
            const logoEl = document.getElementById('previewBrandLogo');
            if (logoEl) logoEl.src = appLogo;

            const badgeName = document.getElementById('previewLoginBadgeName');
            if (badgeName) badgeName.innerText = appName;
            const badgeSub = document.getElementById('previewLoginBadgeSub');
            if (badgeSub) badgeSub.innerText = appSub;
            const badgeLogo = document.getElementById('previewLoginBadgeLogo');
            if (badgeLogo) badgeLogo.src = appLogo;

            const simTitle = document.getElementById('simMiniTitle');
            if (simTitle) simTitle.innerText = appName;
            const simSub = document.getElementById('simMiniSub');
            if (simSub) simSub.innerText = appSub;
            const simLogo = document.getElementById('simMiniLogo');
            if (simLogo) simLogo.src = appLogo;
        }

        function previewAppFont(fontName) {
            const fontBox = document.getElementById('fontPreviewBox');
            if (fontBox) {
                const fontStack = "'" + fontName + "', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
                fontBox.style.fontFamily = fontStack;
            }
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'LIVE_FONT_CHANGE',
                        font: fontName
                    }, '*');
                }
            } catch(e) {}
        }

        function previewAppFontSize(size) {
            const fontBox = document.getElementById('fontPreviewBox');
            if (fontBox) {
                fontBox.style.fontSize = size;
            }
            document.documentElement.style.setProperty('--app-font-size', size);
            document.documentElement.style.setProperty('--form-font-size', size);
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'LIVE_FONT_SIZE_CHANGE',
                        fontSize: size
                    }, '*');
                }
            } catch(e) {}
        }

        function previewSidebarFontSizeLive() {
            updateLivePreview();
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'LIVE_SIDEBAR_FONT_CHANGE',
                        titleFontSize: document.getElementById('inp_sidebar_brand_font_size')?.value || '11.5px',
                        subFontSize: document.getElementById('inp_sidebar_brand_sub_font_size')?.value || '7.5px'
                    }, '*');
                }
            } catch(err) {}
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateLivePreview();
            updateWallpaperSimulation();
        });

        async function submitSaveSettings() {
            const form = document.getElementById('formSettings');
            const fd = new FormData(form);
            fd.append('action', 'save');
            fd.append('is_admin', '<?= $isAdmin ? '1' : '0' ?>');

            Swal.fire({
                title: 'Menyimpan Pengaturan...',
                text: 'Menerapkan konfigurasi identitas, font, dan preferensi portal',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch('setting_aplikasi.php', {
                    method: 'POST',
                    body: fd
                });

                if (res.status === 401 || res.status === 403) {
                    Swal.fire({ icon: 'warning', title: 'Sesi Berakhir', text: 'Silakan login kembali.' });
                    return;
                }

                const json = await res.json();
                if (json.status === 'OK') {
                    if (json.settings && json.settings.app_font) {
                        try {
                            localStorage.setItem('ceisa_app_font', json.settings.app_font);
                        } catch(e) {}
                    }
                    if (json.settings && json.settings.app_font_size) {
                        try {
                            localStorage.setItem('ceisa_app_font_size', json.settings.app_font_size);
                        } catch(e) {}
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Disimpan!',
                        text: json.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        if (window.parent && window.parent !== window) {
                            try {
                                window.parent.location.reload();
                                return;
                            } catch(e) {}
                        }
                        window.location.reload();
                    });

                    // Notifikasi parent window index.php
                    try {
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'APP_SETTINGS_UPDATED',
                                settings: json.settings
                            }, '*');
                            if (json.settings && json.settings.app_font) {
                                window.parent.postMessage({
                                    type: 'CHANGE_FONT',
                                    font: json.settings.app_font
                                }, '*');
                            }
                            if (json.settings && json.settings.app_font_size) {
                                window.parent.postMessage({
                                    type: 'CHANGE_FONT_SIZE',
                                    fontSize: json.settings.app_font_size
                                }, '*');
                            }
                        }
                    } catch(e) {}

                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menyimpan',
                        text: json.message || 'Terjadi kesalahan sistem.'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: err.message
                });
            }
        }

        function confirmResetCompany() {
            const cmpId = document.getElementById('hidden_cmp_id')?.value;
            Swal.fire({
                title: 'Kembalikan ke Settingan Global?',
                text: 'Nama aplikasi, logo, dan wallpaper login perusahaan ini akan dihapus dan otomatis mengikuti pengaturan Global sistem.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#faad14',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Kembalikan ke Global',
                cancelButtonText: 'Batal'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Mereset ke Settingan Global...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    try {
                        const fd = new FormData();
                        fd.append('action', 'reset_company');
                        fd.append('cmp_id', cmpId);
                        fd.append('is_admin', '<?= $isAdmin ? '1' : '0' ?>');

                        const res = await fetch('setting_aplikasi.php', { method: 'POST', body: fd });
                        const json = await res.json();

                        if (json.status === 'OK') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil!',
                                text: json.message,
                                timer: 1800,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                                try {
                                    if (window.parent && window.parent !== window) {
                                        window.parent.postMessage({
                                            type: 'APP_SETTINGS_UPDATED',
                                            settings: json.settings
                                        }, '*');
                                    }
                                } catch(e) {}
                            });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                        }
                    } catch(err) {
                        Swal.fire({ icon: 'error', title: 'Kesalahan', text: err.message });
                    }
                }
            });
        }

        function confirmResetGlobal() {
            Swal.fire({
                title: 'Reset Konfigurasi Global?',
                text: 'Semua pengaturan global akan direset ke standar awal bawaan sistem (INHOST default).',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Reset Global',
                cancelButtonText: 'Batal'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Mereset Pengaturan Global...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    try {
                        const fd = new FormData();
                        fd.append('action', 'reset');
                        fd.append('is_admin', '1');

                        const res = await fetch('setting_aplikasi.php', { method: 'POST', body: fd });
                        const json = await res.json();

                        if (json.status === 'OK') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reset Berhasil!',
                                text: json.message,
                                timer: 1800,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                                try {
                                    if (window.parent && window.parent !== window) {
                                        window.parent.postMessage({
                                            type: 'APP_SETTINGS_UPDATED',
                                            settings: json.settings
                                        }, '*');
                                    }
                                } catch(e) {}
                            });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                        }
                    } catch(err) {
                        Swal.fire({ icon: 'error', title: 'Kesalahan', text: err.message });
                    }
                }
            });
        }
        function toggleWaKeyVisibility() {
            const inp = document.getElementById('inp_wa_api_key');
            const icon = document.getElementById('iconWaEye');
            if (!inp) return;
            if (inp.type === 'password') {
                inp.type = 'text';
                if (icon) icon.className = 'fa-solid fa-eye-slash';
            } else {
                inp.type = 'password';
                if (icon) icon.className = 'fa-solid fa-eye';
            }
        }

        function formatWaJs(val) {
            if (!val) return '';
            let clean = String(val).replace(/[^0-9]/g, '');
            if (clean.startsWith('08')) {
                clean = '62' + clean.slice(1);
            } else if (clean.startsWith('8')) {
                clean = '62' + clean;
            }
            return clean;
        }

        async function sendTestWaMessage() {
            const phoneEl = document.getElementById('test_wa_phone');
            const msgEl = document.getElementById('test_wa_message');
            const apiKeyEl = document.getElementById('inp_wa_api_key');
            const btn = document.getElementById('btnTestWa');
            const resBox = document.getElementById('testWaResult');

            const phone = phoneEl ? phoneEl.value.trim() : '';
            const msg = msgEl ? msgEl.value.trim() : '';
            const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';

            if (!phone) {
                Swal.fire('Peringatan', 'Harap isi nomor HP tujuan tes (format 628xxx)!', 'warning');
                return;
            }

            const normPhone = formatWaJs(phone);
            if (!normPhone.startsWith('628') || normPhone.length < 10) {
                Swal.fire('Peringatan', 'Format nomor tidak valid! Gunakan format 628xxx (contoh: 6281234567890).', 'warning');
                return;
            }

            if (phoneEl) phoneEl.value = normPhone;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengirimkan...';
            resBox.innerHTML = '';

            const fd = new FormData();
            fd.append('action', 'test_wa');
            fd.append('target_phone', normPhone);
            fd.append('message', msg || 'Tes Notifikasi WA CEISA 4.0');
            fd.append('api_key', apiKey);

            try {
                const resp = await fetch('setting_aplikasi.php', { method: 'POST', body: fd });
                const json = await resp.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-brands fa-whatsapp"></i> Kirim Tes Pesan Sekarang';

                if (json.status === 'OK') {
                    resBox.innerHTML = '<span style="color:#52c41a;"><i class="fa-solid fa-check"></i> Pesan terkirim ke ' + normPhone + '!</span>';
                    Swal.fire('Berhasil Terkirim', 'Pesan WhatsApp berhasil dikirimkan ke ' + normPhone + '.', 'success');
                } else {
                    resBox.innerHTML = '<span style="color:#ff4d4f;"><i class="fa-solid fa-xmark"></i> ' + (json.message || 'Gagal mengirim') + '</span>';
                    Swal.fire('Gagal Kirim', json.message || 'Terjadi kesalahan pada gateway WA.', 'error');
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-brands fa-whatsapp"></i> Kirim Tes Pesan Sekarang';
                resBox.innerHTML = '<span style="color:#ff4d4f;"><i class="fa-solid fa-xmark"></i> Koneksi error: ' + e.message + '</span>';
                Swal.fire('Error', 'Koneksi ke server gagal: ' + e.message, 'error');
            }
        }

        function switchWaSubTab(tab) {
            document.querySelectorAll('.wa-subtab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.wa-subpanel').forEach(p => p.style.display = 'none');
            
            const btn = document.getElementById('btnWaSub_' + tab);
            const panel = document.getElementById('waSubPanel_' + tab);
            if (btn) btn.classList.add('active');
            if (panel) panel.style.display = 'block';

            if (tab === 'inbox') {
                loadWaInbox();
            } else if (tab === 'contacts') {
                loadWaContacts();
            }
        }

        async function sendTestWaMedia() {
            const phoneEl = document.getElementById('media_wa_phone');
            const typeEl = document.getElementById('media_wa_type');
            const urlEl = document.getElementById('media_wa_url');
            const capEl = document.getElementById('media_wa_caption');
            const apiKeyEl = document.getElementById('inp_wa_api_key');
            const btn = document.getElementById('btnSendMedia');
            const resBox = document.getElementById('testWaMediaResult');

            const phone = phoneEl ? phoneEl.value.trim() : '';
            const type = typeEl ? typeEl.value : 'image';
            const url = urlEl ? urlEl.value.trim() : '';
            const caption = capEl ? capEl.value.trim() : '';
            const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';

            if (!phone) {
                Swal.fire('Peringatan', 'Harap isi nomor HP tujuan (format 628xxx)!', 'warning');
                return;
            }
            if (!url) {
                Swal.fire('Peringatan', 'Harap isi URL berkas media!', 'warning');
                return;
            }

            const normPhone = formatWaJs(phone);
            if (!normPhone.startsWith('628') || normPhone.length < 10) {
                Swal.fire('Peringatan', 'Format nomor tidak valid! Gunakan format 628xxx (contoh: 6281234567890).', 'warning');
                return;
            }
            if (phoneEl) phoneEl.value = normPhone;

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengirimkan Media...';
            resBox.innerHTML = '';

            const fd = new FormData();
            fd.append('action', 'test_wa_media');
            fd.append('target_phone', normPhone);
            fd.append('media_type', type);
            fd.append('media_url', url);
            fd.append('caption', caption);
            fd.append('api_key', apiKey);

            try {
                const resp = await fetch('setting_aplikasi.php', { method: 'POST', body: fd });
                const json = await resp.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Kirim Media Sekarang';

                if (json.status === 'OK') {
                    resBox.innerHTML = '<span style="color:#52c41a;"><i class="fa-solid fa-check"></i> Media terkirim ke ' + normPhone + '!</span>';
                    Swal.fire('Media Berhasil Terkirim', 'Berkas media WhatsApp berhasil dikirimkan ke ' + normPhone + '.', 'success');
                } else {
                    resBox.innerHTML = '<span style="color:#ff4d4f;"><i class="fa-solid fa-xmark"></i> ' + (json.message || 'Gagal mengirim') + '</span>';
                    Swal.fire('Gagal Kirim Media', json.message || 'Terjadi kesalahan pada gateway WA.', 'error');
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Kirim Media Sekarang';
                resBox.innerHTML = '<span style="color:#ff4d4f;"><i class="fa-solid fa-xmark"></i> Koneksi error: ' + e.message + '</span>';
                Swal.fire('Error', 'Koneksi ke server gagal: ' + e.message, 'error');
            }
        }

        async function loadWaInbox() {
            const container = document.getElementById('waInboxContent');
            const apiKeyEl = document.getElementById('inp_wa_api_key');
            const btn = document.getElementById('btnRefreshInbox');
            const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memuat...';
            container.innerHTML = '<div style="text-align:center; padding:18px; color:var(--text-muted); font-size:10px;"><i class="fa-solid fa-spinner fa-spin"></i> Menghubungi API Gateway WhatsApp (GET /inbox)...</div>';

            const fd = new FormData();
            fd.append('action', 'get_wa_inbox');
            fd.append('api_key', apiKey);

            try {
                const resp = await fetch('setting_aplikasi.php', { method: 'POST', body: fd });
                const json = await resp.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Muat Ulang Inbox';

                if (json.status === 'OK' && Array.isArray(json.data) && json.data.length > 0) {
                    let rows = json.data.map((m, idx) => {
                        const sender = escapeHtml(m.from || m.sender || m.number || '-');
                        const name = escapeHtml(m.name || m.pushName || sender);
                        const text = escapeHtml(m.text || m.message || m.body || '-');
                        const time = escapeHtml(m.timestamp || m.date || m.time || '-');
                        return `
                            <tr>
                                <td style="text-align:center;">${idx + 1}</td>
                                <td><strong>${name}</strong><br><span style="font-size:9px; color:var(--text-muted); font-family:monospace;">${sender}</span></td>
                                <td style="word-break:break-word;">${text}</td>
                                <td style="font-size:9px; color:var(--text-muted); white-space:nowrap;">${time}</td>
                                <td style="text-align:center;">
                                    <button type="button" class="ant-btn ant-btn-sm" style="height:20px; font-size:9px; padding:0 6px;" onclick="quickReplyWa('${sender}')" title="Balas Pesan">
                                        <i class="fa-solid fa-reply"></i> Balas
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('');

                    container.innerHTML = `
                        <div style="overflow-x:auto;">
                            <table class="table-setting" style="width:100%; font-size:9.5px; border-collapse:collapse;">
                                <thead>
                                    <tr style="background:var(--bg-card); text-align:left;">
                                        <th style="width:30px; text-align:center; padding:5px 6px;">No</th>
                                        <th style="width:150px; padding:5px 6px;">Pengirim</th>
                                        <th style="padding:5px 6px;">Isi Pesan</th>
                                        <th style="width:120px; padding:5px 6px;">Waktu</th>
                                        <th style="width:60px; text-align:center; padding:5px 6px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                    `;
                } else if (json.message && (json.message.includes('belum tersedia') || json.message.includes('inbox'))) {
                    container.innerHTML = `
                        <div style="padding:14px; background:rgba(250,140,22,0.08); border:1px solid rgba(250,140,22,0.3); border-radius:6px; font-size:10px; color:var(--text-main); line-height:1.6;">
                            <div style="font-weight:700; color:#fa8c16; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                <i class="fa-solid fa-triangle-exclamation"></i> Respons Gateway WhatsApp Upstream:
                            </div>
                            <div style="font-family:monospace; font-size:9.5px; background:var(--bg-card); padding:6px 8px; border-radius:4px; border:1px solid var(--border-color); margin-bottom:8px;">
                                ${escapeHtml(json.message)}
                            </div>
                            <span style="color:var(--text-secondary);">
                                <strong>Status Fitur:</strong> Modul baca Inbox di antarmuka CEISA 4.0 sudah <strong>100% siap dan terintegrasi</strong> dengan endpoint <code>GET /api/svc/v1/wa-api/inbox</code>. Begitu endpoint inbox diaktifkan pada server gateway (vpsmurah.co.id), seluruh pesan masuk akan langsung tampil otomatis pada tabel di atas.
                            </span>
                        </div>
                    `;
                } else {
                    container.innerHTML = `
                        <div style="text-align:center; padding:18px; color:var(--text-muted); font-size:10px;">
                            <i class="fa-solid fa-inbox" style="font-size:24px; display:block; margin-bottom:6px; opacity:0.4;"></i>
                            ${escapeHtml(json.message || 'Kotak masuk kosong. Belum ada pesan WhatsApp yang diterima.')}
                        </div>
                    `;
                }
            } catch(e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Muat Ulang Inbox';
                container.innerHTML = '<div style="padding:12px; color:#ff4d4f; font-size:10px;"><i class="fa-solid fa-xmark"></i> Gagal memuat inbox: ' + escapeHtml(e.message) + '</div>';
            }
        }

        let rawWaContacts = [];
        async function loadWaContacts() {
            const container = document.getElementById('waContactsContent');
            const apiKeyEl = document.getElementById('inp_wa_api_key');
            const btn = document.getElementById('btnRefreshContacts');
            const apiKey = apiKeyEl ? apiKeyEl.value.trim() : '';

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memuat...';
            container.innerHTML = '<div style="text-align:center; padding:18px; color:var(--text-muted); font-size:10px;"><i class="fa-solid fa-spinner fa-spin"></i> Menghubungi API Gateway WhatsApp (GET /contacts)...</div>';

            const fd = new FormData();
            fd.append('action', 'get_wa_contacts');
            fd.append('api_key', apiKey);

            try {
                const resp = await fetch('setting_aplikasi.php', { method: 'POST', body: fd });
                const json = await resp.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Muat Kontak';

                if (json.status === 'OK' && Array.isArray(json.data) && json.data.length > 0) {
                    rawWaContacts = json.data;
                    renderContactsTable(rawWaContacts);
                } else if (json.message && (json.message.includes('belum tersedia') || json.message.includes('contacts'))) {
                    container.innerHTML = `
                        <div style="padding:14px; background:rgba(250,140,22,0.08); border:1px solid rgba(250,140,22,0.3); border-radius:6px; font-size:10px; color:var(--text-main); line-height:1.6;">
                            <div style="font-weight:700; color:#fa8c16; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                <i class="fa-solid fa-triangle-exclamation"></i> Respons Gateway WhatsApp Upstream:
                            </div>
                            <div style="font-family:monospace; font-size:9.5px; background:var(--bg-card); padding:6px 8px; border-radius:4px; border:1px solid var(--border-color); margin-bottom:8px;">
                                ${escapeHtml(json.message)}
                            </div>
                            <span style="color:var(--text-secondary);">
                                <strong>Status Fitur:</strong> Modul baca Kontak di antarmuka CEISA 4.0 sudah <strong>100% siap dan terintegrasi</strong> dengan endpoint <code>GET /api/svc/v1/wa-api/contacts</code>. Begitu endpoint kontak diaktifkan pada server gateway (vpsmurah.co.id), seluruh kontak akan langsung tampil otomatis pada tabel di atas.
                            </span>
                        </div>
                    `;
                } else {
                    container.innerHTML = `
                        <div style="text-align:center; padding:18px; color:var(--text-muted); font-size:10px;">
                            <i class="fa-solid fa-address-book" style="font-size:24px; display:block; margin-bottom:6px; opacity:0.4;"></i>
                            ${escapeHtml(json.message || 'Tidak ada kontak ditemukan pada gateway WhatsApp.')}
                        </div>
                    `;
                }
            } catch(e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Muat Kontak';
                container.innerHTML = '<div style="padding:12px; color:#ff4d4f; font-size:10px;"><i class="fa-solid fa-xmark"></i> Gagal memuat kontak: ' + escapeHtml(e.message) + '</div>';
            }
        }

        function renderContactsTable(list) {
            const container = document.getElementById('waContactsContent');
            if (!list || list.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:14px; color:var(--text-muted); font-size:10px;">Tidak ada kontak yang cocok dengan filter pencarian.</div>';
                return;
            }
            let rows = list.map((c, idx) => {
                const name = escapeHtml(c.name || c.notify || c.pushName || '-');
                const phone = escapeHtml(c.id || c.number || c.jid || '').replace(/@s\.whatsapp\.net/, '');
                return `
                    <tr>
                        <td style="text-align:center;">${idx + 1}</td>
                        <td><strong>${name}</strong></td>
                        <td><code style="color:var(--primary);">${phone}</code></td>
                        <td style="text-align:center;">
                            <button type="button" class="ant-btn ant-btn-sm" style="height:20px; font-size:9px; padding:0 6px;" onclick="quickReplyWa('${phone}')" title="Kirim Pesan">
                                <i class="fa-solid fa-paper-plane"></i> Chat
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');

            container.innerHTML = `
                <div style="max-height:260px; overflow-y:auto;">
                    <table class="table-setting" style="width:100%; font-size:9.5px; border-collapse:collapse;">
                        <thead>
                            <tr style="background:var(--bg-card); text-align:left;">
                                <th style="width:30px; text-align:center; padding:5px 6px;">No</th>
                                <th style="padding:5px 6px;">Nama Kontak</th>
                                <th style="padding:5px 6px;">Nomor WA</th>
                                <th style="width:70px; text-align:center; padding:5px 6px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `;
        }

        function filterContactsList() {
            const q = (document.getElementById('filterWaContacts')?.value || '').toLowerCase().trim();
            if (!q) {
                renderContactsTable(rawWaContacts);
                return;
            }
            const filtered = rawWaContacts.filter(c => {
                const name = (c.name || c.notify || c.pushName || '').toLowerCase();
                const phone = (c.id || c.number || c.jid || '').toLowerCase();
                return name.includes(q) || phone.includes(q);
            });
            renderContactsTable(filtered);
        }

        function quickReplyWa(phone) {
            const clean = formatWaJs(phone);
            switchWaSubTab('text');
            const phoneEl = document.getElementById('test_wa_phone');
            if (phoneEl) {
                phoneEl.value = clean;
                phoneEl.focus();
            }
        }
    </script>
</body>
</html>

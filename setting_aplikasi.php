<?php
/**
 * CEISA 4.0 - Setting & Konfigurasi Aplikasi (setting_aplikasi.php)
 * Khusus Administrator
 * Mengatur Identitas Portal (Nama Aplikasi, Subtitle, Logo), Integrasi CEISA,
 * Preferensi IT Inventory, dan Tampilan Sistem
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';

// Validasi Keamanan: Khusus Administrator
$isAdmin = !empty($_SESSION['is_admin']) || (isset($_REQUEST['is_admin']) && (string)$_REQUEST['is_admin'] === '1');

if (!$isAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden - Khusus Administrator</title>
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
            <p>Halaman Setting Aplikasi hanya dapat diakses oleh akun <strong>Administrator</strong>.</p>
            <a href="/index.php" class="btn">Kembali ke Portal Utama</a>
        </div>
    </body>
    </html>';
    exit;
}

$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'Administrator');
$action = $_REQUEST['action'] ?? '';
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// -------------------------------------------------------------
// 1. AJAX API: GET SETTINGS
// -------------------------------------------------------------
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    $settings = getAppSettings($koneksi);
    echo json_encode(['status' => 'OK', 'settings' => $settings]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: SAVE SETTINGS
// -------------------------------------------------------------
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    
    $appName      = trim($_POST['app_name'] ?? 'INHOST');
    $appSubtitle  = trim($_POST['app_subtitle'] ?? 'Interface Host to Host Ceisa 4.0');
    $appLogo      = trim($_POST['app_logo'] ?? '/assets/logo.png');
    $appFavicon   = trim($_POST['app_favicon'] ?? '/assets/logo.png');
    $appFooter    = trim($_POST['app_footer'] ?? 'Interface Host to Host (INHOST) Ceisa 4.0 • Single Core System');
    $loginBadge   = trim($_POST['login_badge'] ?? 'Single Core System');

    $ceisaEnv       = trim($_POST['ceisa_env'] ?? 'production');
    $ceisaApiUrl    = trim($_POST['ceisa_api_url'] ?? 'https://apis-gw.beacukai.go.id');
    $ceisaSyncBatch = isset($_POST['ceisa_sync_batch_size']) ? (int)$_POST['ceisa_sync_batch_size'] : 1000;
    if ($ceisaSyncBatch < 10) $ceisaSyncBatch = 10;
    if ($ceisaSyncBatch > 1000) $ceisaSyncBatch = 1000;
    $ceisaSyncBatch = (string)$ceisaSyncBatch;
    $trackingRef    = trim($_POST['tracking_refresh'] ?? '60');
    $apiTimeout     = trim($_POST['api_timeout'] ?? '30');

    $prefixSpp    = trim($_POST['prefix_spp'] ?? 'SPP-');
    $prefixTrf    = trim($_POST['prefix_transfer'] ?? 'TRF-');
    $lockInvAju   = !empty($_POST['lock_inventory_aju']) ? '1' : '0';
    $requireYa    = !empty($_POST['require_confirm_ya']) ? '1' : '0';
    $tahunBuku    = trim($_POST['default_tahun_buku'] ?? 'auto');

    $defaultTheme = trim($_POST['default_theme'] ?? 'light');
    $defaultSide  = trim($_POST['default_sidebar'] ?? 'pinned');
    $pageSize     = trim($_POST['default_page_size'] ?? '25');
    $toastDur     = trim($_POST['toast_duration'] ?? '2600');

    // Proses upload file logo kustom jika ada
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        $fileInfo = pathinfo($_FILES['logo_file']['name']);
        $ext = strtolower($fileInfo['extension'] ?? '');

        if (in_array($ext, $allowedExt)) {
            $assetsDir = __DIR__ . '/assets';
            if (!is_dir($assetsDir)) {
                @mkdir($assetsDir, 0777, true);
            }
            $targetName = 'custom_logo_' . time() . '.' . $ext;
            $targetPath = $assetsDir . '/' . $targetName;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
                $appLogo = '/assets/' . $targetName;
                $appFavicon = $appLogo;
            }
        }
    }

    if (empty($appName)) {
        $appName = 'INHOST';
    }

    $payload = [
        'app_name'           => $appName,
        'app_subtitle'       => $appSubtitle,
        'app_logo'           => $appLogo,
        'app_favicon'        => $appFavicon,
        'app_footer'         => $appFooter,
        'login_badge'        => $loginBadge,
        'ceisa_env'             => $ceisaEnv,
        'ceisa_api_url'         => $ceisaApiUrl,
        'ceisa_sync_batch_size' => $ceisaSyncBatch,
        'tracking_refresh'      => $trackingRef,
        'api_timeout'        => $apiTimeout,
        'prefix_spp'         => $prefixSpp,
        'prefix_transfer'    => $prefixTrf,
        'lock_inventory_aju' => $lockInvAju,
        'require_confirm_ya' => $requireYa,
        'default_tahun_buku' => $tahunBuku,
        'default_theme'      => $defaultTheme,
        'default_sidebar'    => $defaultSide,
        'default_page_size'  => $pageSize,
        'toast_duration'     => $toastDur
    ];

    $updated = saveAppSettings($payload, $koneksi, $sessionUser);

    echo json_encode([
        'status' => 'OK',
        'message' => 'Pengaturan aplikasi dan identitas portal berhasil diperbarui!',
        'settings' => $updated
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: RESET SETTINGS TO DEFAULT
// -------------------------------------------------------------
if ($action === 'reset') {
    header('Content-Type: application/json; charset=utf-8');
    $defaults = getDefaultAppSettings();
    $updated = saveAppSettings($defaults, $koneksi, 'reset_' . $sessionUser);
    echo json_encode([
        'status' => 'OK',
        'message' => 'Semua konfigurasi berhasil dikembalikan ke standar awal!',
        'settings' => $updated
    ]);
    exit;
}

// Ambil konfigurasi saat ini untuk rendering halaman
$currentSettings = getAppSettings($koneksi);
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($themeParam) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setting Aplikasi - Administrator</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root {
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
            --bg-base: #151521;
            --bg-card: #1e1e2d;
            --bg-header: #232334;
            --border-color: #2b2b40;
            --border-light: #232334;
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
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 11px;
            background: var(--bg-base);
            color: var(--text-main);
            padding: 16px 20px 40px;
            min-height: 100vh;
        }

        /* Header Page */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 14px 20px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-card);
        }
        .page-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-title-icon {
            font-size: 26px;
            line-height: 1;
        }
        .page-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
        }
        .page-subtitle {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 32px;
            padding: 0 14px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            text-decoration: none;
            font-family: inherit;
        }
        .btn-primary {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .btn-primary:hover {
            background: var(--primary-hover);
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.25);
        }
        .btn-default {
            background: var(--bg-card);
            color: var(--text-secondary);
            border-color: var(--border-color);
        }
        .btn-default:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: var(--primary);
        }
        .btn-danger {
            background: transparent;
            color: var(--danger);
            border-color: var(--danger);
        }
        .btn-danger:hover {
            background: rgba(255, 77, 79, 0.1);
        }

        /* Layout Grid: Content + Sticky Sidebar Preview */
        .settings-layout {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 16px;
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
            border-radius: 8px 8px 0 0;
            padding: 8px 12px 0;
            border-bottom: 2px solid var(--border-color);
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-secondary);
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }
        .tab-btn:hover {
            color: var(--primary);
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: var(--primary-light);
            border-radius: 6px 6px 0 0;
        }

        /* Tab Content Card */
        .tab-pane {
            display: none;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 8px 8px;
            padding: 20px;
            box-shadow: var(--shadow-card);
        }
        .tab-pane.active {
            display: block;
        }

        /* Form Groups */
        .form-section-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .form-row.single {
            grid-template-columns: 1fr;
        }
        .form-group {
            margin-bottom: 14px;
        }
        .form-group label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 5px;
        }
        .form-group .hint {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: normal;
        }
        .form-control {
            width: 100%;
            height: 32px;
            padding: 4px 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-base);
            border-radius: 6px;
            font-size: 11px;
            color: var(--text-main);
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
        }
        .form-control:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-light);
        }
        html[data-theme="dark"] .form-control:focus {
            background: #1e1e2d;
        }
        textarea.form-control {
            height: auto;
            min-height: 60px;
            resize: vertical;
        }

        /* Toggle Switch */
        .switch-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .switch-label-wrap {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .switch-label-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-main);
        }
        .switch-label-desc {
            font-size: 9px;
            color: var(--text-muted);
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 20px;
            flex-shrink: 0;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 20px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: var(--primary);
        }
        input:checked + .slider:before {
            transform: translateX(18px);
        }

        /* Preview Sidebar Box */
        .preview-sticky-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            box-shadow: var(--shadow-card);
            position: sticky;
            top: 16px;
        }
        .preview-header-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .live-preview-box {
            background: var(--bg-base);
            border: 1px dashed var(--primary);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 14px;
        }
        .preview-sidebar-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .preview-brand-logo {
            width: 26px;
            height: 26px;
            object-fit: contain;
            border-radius: 4px;
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
            font-weight: 700;
            color: #1890ff;
            letter-spacing: 0.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .preview-brand-sub {
            font-size: 7.5px;
            font-weight: 600;
            color: #8c8c8c;
            letter-spacing: 0.1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .preview-tab-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            padding: 6px 10px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 9px;
            color: var(--text-muted);
        }

        .logo-presets-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-top: 8px;
            margin-bottom: 12px;
        }
        .preset-logo-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 6px;
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .preset-logo-item:hover, .preset-logo-item.active {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .preset-logo-item img {
            width: 24px;
            height: 24px;
            object-fit: contain;
            margin-bottom: 4px;
        }
        .preset-logo-item span {
            font-size: 8px;
            color: var(--text-main);
            font-weight: 600;
        }

        .file-upload-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
        }
        .file-upload-wrap input[type="file"] {
            display: none;
        }
    </style>
</head>
<body>

    <!-- Header Page -->
    <div class="page-header">
        <div class="page-title-wrap">
            <span class="page-title-icon">⚙️</span>
            <div>
                <h1 class="page-title">Setting & Konfigurasi Aplikasi</h1>
                <p class="page-subtitle">Kelola nama aplikasi, subtitle/deskripsi, logo branding, integrasi CEISA 4.0, dan preferensi portal</p>
            </div>
        </div>
        <div class="header-actions">
            <button type="button" class="btn btn-danger" onclick="confirmResetSettings()">
                <span>🔄</span> Reset ke Default
            </button>
            <button type="button" class="btn btn-primary" onclick="submitSaveSettings()">
                <span>💾</span> Simpan Perubahan
            </button>
        </div>
    </div>

    <form id="formSettings" onsubmit="event.preventDefault(); submitSaveSettings();">
        <div class="settings-layout">
            
            <!-- Left Column: Settings Tabs -->
            <div class="settings-main-wrap">
                <div class="settings-tabs-nav">
                    <button type="button" class="tab-btn active" onclick="switchSettingsTab('tab-brand', this)">
                        <span>🏷️</span> Identitas & Branding
                    </button>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-ceisa', this)">
                        <span>🌐</span> Integrasi CEISA 4.0
                    </button>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-inventory', this)">
                        <span>📦</span> Modul IT Inventory
                    </button>
                    <button type="button" class="tab-btn" onclick="switchSettingsTab('tab-ui', this)">
                        <span>🎨</span> Tampilan & Preferensi
                    </button>
                </div>

                <!-- 1. TAB: IDENTITAS & BRANDING -->
                <div class="tab-pane active" id="tab-brand">
                    <div class="form-section-title">
                        <span>🏷️</span> Informasi Brand Header Sidebar & Login
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Nama Aplikasi (App Name)*</span>
                                <span class="hint">Ditampilkan di sudut kiri atas</span>
                            </label>
                            <input type="text" class="form-control" id="inp_app_name" name="app_name" 
                                   value="<?= htmlspecialchars($currentSettings['app_name']) ?>" 
                                   oninput="updateLivePreview()" placeholder="Contoh: INHOST" required>
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

                    <div class="form-group">
                        <label>
                            <span>Pilih Preset Icon / Logo Aplikasi</span>
                            <span class="hint">Klik preset atau gunakan upload file sendiri</span>
                        </label>
                        <div class="logo-presets-grid">
                            <div class="preset-logo-item active" onclick="selectLogoPreset('/assets/logo.png', this)">
                                <img src="/assets/logo.png" alt="INHOST Cyan">
                                <span>INHOST Cyan</span>
                            </div>
                            <div class="preset-logo-item" onclick="selectLogoPreset('/assets/bc_garuda.png', this)">
                                <img src="/assets/logo.png" style="filter: hue-rotate(190deg);" alt="Bea Cukai">
                                <span>Custom Blue</span>
                            </div>
                            <div class="preset-logo-item" onclick="selectLogoPreset('/assets/cloud_icon.png', this)">
                                <img src="/assets/logo.png" style="filter: saturate(1.8) brightness(1.1);" alt="Vibrant">
                                <span>Vibrant Neon</span>
                            </div>
                            <div class="preset-logo-item" onclick="selectLogoPreset('/assets/logo.png', this)">
                                <img src="/assets/logo.png" style="filter: grayscale(1);" alt="Minimal Monochrome">
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
                                    <span>📁</span> Pilih File Logo...
                                </button>
                                <span id="fileNameLabel" style="font-size:9.5px; color:var(--text-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Belum ada file dipilih</span>
                            </div>
                        </div>
                    </div>

                    <div style="height: 1px; background: var(--border-color); margin: 16px 0;"></div>

                    <div class="form-section-title">
                        <span>📝</span> Teks Footer & Layar Login
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Teks Badge Halaman Login</span>
                                <span class="hint">Tampil di atas kotak login</span>
                            </label>
                            <input type="text" class="form-control" id="inp_login_badge" name="login_badge" 
                                   value="<?= htmlspecialchars($currentSettings['login_badge']) ?>" 
                                   placeholder="Contoh: Single Core System">
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Teks Footer / Hak Cipta Portal</span>
                                <span class="hint">Tampil di bagian bawah login & report</span>
                            </label>
                            <input type="text" class="form-control" id="inp_app_footer" name="app_footer" 
                                   value="<?= htmlspecialchars($currentSettings['app_footer']) ?>" 
                                   placeholder="Contoh: Interface Host to Host (INHOST) Ceisa 4.0 • Single Core System">
                        </div>
                    </div>
                </div>

                <!-- 2. TAB: INTEGRASI CEISA 4.0 -->
                <div class="tab-pane" id="tab-ceisa">
                    <div class="form-section-title">
                        <span>🌐</span> Konfigurasi Environment & Gateway API CEISA 4.0
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Mode Environment CEISA 4.0*</span>
                                <span class="hint">Target server koneksi pabean</span>
                            </label>
                            <select class="form-control" id="inp_ceisa_env" name="ceisa_env" onchange="handleEnvPresetChange(this.value)">
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
                                <span class="hint">Disarankan "Non-Aktif" agar terhindar dari pemblokiran rate-limit IP</span>
                            </label>
                            <select class="form-control" id="inp_tracking_refresh" name="tracking_refresh">
                                <option value="0" <?= ($currentSettings['tracking_refresh'] === '0') ? 'selected' : '' ?>>Non-Aktif (Manual Saja - Direkomendasikan / Paling Aman)</option>
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
                                <span>Jumlah Dokumen Tarik per Sinkronisasi (Batch Size)</span>
                                <span class="hint">Limit maksimal OpenAPI CEISA 4.0 adalah 1.000 dokumen/halaman</span>
                            </label>
                            <select class="form-control" id="inp_ceisa_sync_batch_size" name="ceisa_sync_batch_size">
                                <option value="100" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 1000) === 100) ? 'selected' : '' ?>>100 Dokumen (Cepat & Ringan)</option>
                                <option value="200" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 1000) === 200) ? 'selected' : '' ?>>200 Dokumen (Standar)</option>
                                <option value="500" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 1000) === 500) ? 'selected' : '' ?>>500 Dokumen (Menengah)</option>
                                <option value="1000" <?= ((int)($currentSettings['ceisa_sync_batch_size'] ?? 1000) === 1000) ? 'selected' : '' ?>>1.000 Dokumen (Maksimal CEISA 4.0 - Rekomendasi)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Format Identitas Penarikan CEISA 4.0</span>
                                <span class="hint">Target nomor identitas perusahaan</span>
                            </label>
                            <input type="text" class="form-control" value="Auto Format 22 Digit (0 + NPWP + 000000)" readonly style="background:var(--bg-card); opacity:0.85; cursor:not-allowed;">
                        </div>
                    </div>
                </div>

                <!-- 3. TAB: MODUL IT INVENTORY -->
                <div class="tab-pane" id="tab-inventory">
                    <div class="form-section-title">
                        <span>📦</span> Aturan Bisnis & Penomoran Dokumen IT Inventory
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
                                <span>Prefiks Dokumen Stock Transfer</span>
                                <span class="hint">Awalan bukti transfer produksi internal</span>
                            </label>
                            <input type="text" class="form-control" id="inp_prefix_transfer" name="prefix_transfer" 
                                   value="<?= htmlspecialchars($currentSettings['prefix_transfer']) ?>" placeholder="TRF-">
                        </div>
                    </div>

                    <div class="switch-wrap">
                        <div class="switch-label-wrap">
                            <div class="switch-label-title">Kunci Otomatis Dokumen AJU di IT Inventory</div>
                            <div class="switch-label-desc">Dokumen AJU yang sudah diposting ke IT Inventory terkunci otomatis dan tidak dapat dihapus sembarangan.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="lock_inventory_aju" id="inp_lock_inventory_aju" value="1" <?= ($currentSettings['lock_inventory_aju'] === '1') ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="switch-wrap">
                        <div class="switch-label-wrap">
                            <div class="switch-label-title">Wajib Konfirmasi Ketik "YA" Saat Hapus AJU</div>
                            <div class="switch-label-desc">Mencegah penghapusan tidak sengaja pada data Host to Host & Form Editor.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="require_confirm_ya" id="inp_require_confirm_ya" value="1" <?= ($currentSettings['require_confirm_ya'] === '1') ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-top: 14px;">
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

                <!-- 4. TAB: TAMPILAN & PREFERENSI -->
                <div class="tab-pane" id="tab-ui">
                    <div class="form-section-title">
                        <span>🎨</span> Preferensi Tampilan Portal & Grid Tabel
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Tema Default Pengguna Baru</span>
                                <span class="hint">Tema visual saat pertama kali membuka portal</span>
                            </label>
                            <select class="form-control" id="inp_default_theme" name="default_theme">
                                <option value="light" <?= ($currentSettings['default_theme'] === 'light') ? 'selected' : '' ?>>☀️ Light Theme (Terang / Standar)</option>
                                <option value="dark" <?= ($currentSettings['default_theme'] === 'dark') ? 'selected' : '' ?>>🌙 Dark Theme (Gelap Modern)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Status Sidebar Default</span>
                                <span class="hint">Kondisi bilah navigasi kiri saat dimuat</span>
                            </label>
                            <select class="form-control" id="inp_default_sidebar" name="default_sidebar">
                                <option value="pinned" <?= ($currentSettings['default_sidebar'] === 'pinned') ? 'selected' : '' ?>>Terkunci (Lebar 240px)</option>
                                <option value="unpinned" <?= ($currentSettings['default_sidebar'] === 'unpinned') ? 'selected' : '' ?>>Autohide (Ringkas 52px)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <span>Default Jumlah Baris Tabel (Pagination)</span>
                                <span class="hint">Jumlah item default yang tampil di setiap tabel</span>
                            </label>
                            <select class="form-control" id="inp_default_page_size" name="default_page_size">
                                <option value="10" <?= ($currentSettings['default_page_size'] === '10') ? 'selected' : '' ?>>10 baris per halaman</option>
                                <option value="25" <?= ($currentSettings['default_page_size'] === '25') ? 'selected' : '' ?>>25 baris per halaman (Rekomendasi)</option>
                                <option value="50" <?= ($currentSettings['default_page_size'] === '50') ? 'selected' : '' ?>>50 baris per halaman</option>
                                <option value="100" <?= ($currentSettings['default_page_size'] === '100') ? 'selected' : '' ?>>100 baris per halaman</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>
                                <span>Durasi Popup Toast (Milidetik)</span>
                                <span class="hint">Lama tampilan notifikasi pojok layar</span>
                            </label>
                            <input type="number" class="form-control" id="inp_toast_duration" name="toast_duration" 
                                   value="<?= htmlspecialchars($currentSettings['toast_duration']) ?>" min="1000" max="10000" step="100">
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column: Sticky Live Preview Box -->
            <div class="settings-preview-col">
                <div class="preview-sticky-card">
                    <div class="preview-header-title">
                        <span>👁️</span> Pratinjau Langsung (Live Preview)
                    </div>
                    <p style="font-size:9.5px; color:var(--text-muted); margin-bottom:12px;">
                        Tampilan brand header sidebar yang akan dilihat oleh seluruh pengguna:
                    </p>

                    <div class="live-preview-box">
                        <div class="preview-sidebar-brand" id="liveBrandPreview">
                            <img src="<?= htmlspecialchars($currentSettings['app_logo']) ?>" 
                                 id="previewLogoImg" 
                                 class="preview-brand-logo" 
                                 alt="Logo">
                            <div class="preview-brand-text">
                                <span class="preview-brand-name" id="previewBrandName">
                                    <?= htmlspecialchars($currentSettings['app_name']) ?>
                                </span>
                                <span class="preview-brand-sub" id="previewBrandSub">
                                    <?= htmlspecialchars($currentSettings['app_subtitle']) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="preview-tab-pill">
                        <span>🌐</span>
                        <span id="previewTabTitle">
                            <?= htmlspecialchars($currentSettings['app_name']) ?> - <?= htmlspecialchars($currentSettings['app_subtitle']) ?>
                        </span>
                    </div>

                    <div style="margin-top: 16px; padding: 12px; background: var(--bg-base); border-radius: 6px; font-size: 9.5px; color: var(--text-muted); line-height: 1.5;">
                        <div style="font-weight: 700; color: var(--text-main); margin-bottom: 4px;">💡 Informasi Administrator</div>
                        Pengaturan identitas ini langsung tersimpan ke database dan file konfigurasi portal. Logo dan nama aplikasi di sidebar serta judul tab akan langsung terupdate secara real-time.
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

        function updateLivePreview() {
            const name = document.getElementById('inp_app_name').value.trim() || 'INHOST';
            const sub  = document.getElementById('inp_app_subtitle').value.trim() || 'Interface Host to Host Ceisa 4.0';
            const logo = document.getElementById('inp_app_logo').value.trim() || '/assets/logo.png';

            document.getElementById('previewBrandName').innerText = name;
            document.getElementById('previewBrandSub').innerText = sub;
            document.getElementById('previewLogoImg').src = logo;
            document.getElementById('previewTabTitle').innerText = name + ' - ' + sub;
        }

        function selectLogoPreset(url, el) {
            document.querySelectorAll('.preset-logo-item').forEach(i => i.classList.remove('active'));
            if (el) el.classList.add('active');
            document.getElementById('inp_app_logo').value = url;
            updateLivePreview();
        }

        function handleLogoFileSelect(event) {
            const file = event.target.files[0];
            if (!file) return;

            document.getElementById('fileNameLabel').innerText = file.name;
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewLogoImg').src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        function handleEnvPresetChange(env) {
            const urlInput = document.getElementById('inp_ceisa_api_url');
            if (env === 'staging') {
                urlInput.value = 'https://apis-gw-dev.beacukai.go.id';
            } else {
                urlInput.value = 'https://apis-gw.beacukai.go.id';
            }
        }

        async function submitSaveSettings() {
            const form = document.getElementById('formSettings');
            const fd = new FormData(form);
            fd.append('action', 'save');

            Swal.fire({
                title: 'Menyimpan Pengaturan...',
                text: 'Menerapkan konfigurasi aplikasi dan identitas portal',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch('setting_aplikasi.php', {
                    method: 'POST',
                    body: fd
                });
                const json = await res.json();

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Disimpan!',
                        text: json.message,
                        timer: 2000,
                        showConfirmButton: false
                    });

                    // Kirim sinyal ke window utama (index.php) untuk update brand header & judul secara instan
                    try {
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'APP_SETTINGS_UPDATED',
                                settings: json.settings
                            }, '*');
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
                    title: 'Kesalahan Jaringan',
                    text: err.message
                });
            }
        }

        function confirmResetSettings() {
            Swal.fire({
                title: 'Kembalikan ke Standar Default?',
                text: 'Nama aplikasi, logo, dan seluruh preferensi akan direset ke pengaturan awal bawaan sistem.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Reset Sekarang',
                cancelButtonText: 'Batal'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Mereset Pengaturan...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    try {
                        const fd = new FormData();
                        fd.append('action', 'reset');
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
                    } catch(e) {
                        Swal.fire({ icon: 'error', title: 'Error', text: e.message });
                    }
                }
            });
        }

        // Listener sync theme dari parent window
        window.addEventListener('message', function(e) {
            if (!e.data || typeof e.data !== 'object') return;
            if (e.data.type === 'SET_THEME' && e.data.theme) {
                document.documentElement.setAttribute('data-theme', e.data.theme);
                document.body.setAttribute('data-theme', e.data.theme);
            }
        });
    </script>
</body>
</html>

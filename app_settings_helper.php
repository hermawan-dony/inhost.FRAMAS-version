<?php
/**
 * CEISA 4.0 - Application Settings Helper (app_settings_helper.php)
 * Mengelola konfigurasi umum, branding/identitas portal, integrasi CEISA, dan preferensi modul
 * Mendukung Multi-Tenant: Pengaturan Global (cmp_id = '') dan Pengaturan Khusus Per Perusahaan (cmp_id = ?)
 * Terhubung ke tabel database `bc_ceisa_setting` dengan fallback cache `app_settings.json`
 */

if (!function_exists('getDefaultAppSettings')) {
    function getDefaultAppSettings() {
        return [
            // 1. Identitas & Branding Aplikasi (Dapat diedit oleh Admin atau Perusahaan)
            'app_name'                    => 'INHOST',
            'app_subtitle'                => 'Interface Host to Host Ceisa 4.0',
            'sidebar_brand_font_size'     => '11.5px', // Ukuran font judul brand sidebar (9px - 15px)
            'sidebar_brand_sub_font_size' => '7.5px',  // Ukuran font subtitle brand sidebar (7px - 9px)
            'app_logo'                    => '/assets/logo.png',
            'app_favicon'                 => '/assets/logo.png',
            'app_footer'                  => 'Interface Host to Host (INHOST) Ceisa 4.0 • Single Core System',
            'login_badge'                 => 'Single Core System',
            
            // 2. Wallpaper & Background Layar Login (Global & Per Perusahaan)
            'login_bg_type'         => 'image', // 'default' | 'gradient' | 'image'
            'login_bg_gradient'     => 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)',
            'login_bg_image'        => '/assets/wallpaper-batik-default.png',
            'login_bg_dimmer'       => '0', // 0.0 s/d 0.85
            
            // 3. CEISA 4.0 Integration & Environment (Khusus Admin Global)
            'ceisa_env'                   => 'production', // 'production' | 'staging'
            'ceisa_api_url'               => 'https://apis-gw.beacukai.go.id',
            'ceisa_sync_interval_minutes' => '15', // Interval sinkronisasi background (minimal 15 menit)
            'ceisa_sync_batch_size'       => '50', // Jumlah dokumen ditarik per sync (default 50 baris)
            'tracking_refresh'            => '0', // detik (0 = Non-Aktif / Manual Saja)
            'api_timeout'                 => '30', // detik
            
            // 4. Modul IT Inventory & Aturan Bisnis
            'prefix_spp'            => 'SPP-',
            'prefix_transfer'       => 'TRF-',
            'lock_inventory_aju'    => '1', // 1: AJU terkunci bila sudah diposting ke IT Inventory
            'require_confirm_ya'    => '1', // 1: Wajib ketik "YA" untuk hapus data AJU
            'default_tahun_buku'    => 'auto', // 'auto' | format YYYY
            
            // 5. UI / UX Portal & Tampilan
            'default_theme'         => 'light', // 'light' | 'dark'
            'default_sidebar'       => 'pinned', // 'pinned' | 'unpinned'
            'sidebar_icon_style'    => 'colored', // 'colored' | 'monochrome'
            'show_company_card'        => 'auto', // 'auto' (Sembunyikan untuk User Perusahaan, Tampilkan untuk Admin) | '0' (Hide) | '1' (Show)
            'show_login_company_title' => '0', // '0' (Sembunyikan teks nama perush di login card - Default) | '1' (Tampilkan)
            'default_page_size'        => '25', // '10' | '25' | '50' | '100'
            'toast_duration'        => '2600' // milidetik
        ];
    }
}

if (!function_exists('ensureAppSettingsTable')) {
    function ensureAppSettingsTable($dbConn) {
        if (!$dbConn) return;

        $chk = @$dbConn->query("SHOW TABLES LIKE 'bc_ceisa_setting'");
        if (!$chk || $chk->num_rows === 0) {
            @$dbConn->query("CREATE TABLE IF NOT EXISTS bc_ceisa_setting (
                cmp_id VARCHAR(50) NOT NULL DEFAULT '',
                setting_key VARCHAR(100) NOT NULL,
                setting_value LONGTEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by VARCHAR(100) DEFAULT 'admin',
                PRIMARY KEY (cmp_id, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $defaults = getDefaultAppSettings();
            foreach ($defaults as $k => $v) {
                $escK = $dbConn->real_escape_string($k);
                $escV = $dbConn->real_escape_string($v);
                @$dbConn->query("INSERT IGNORE INTO bc_ceisa_setting (cmp_id, setting_key, setting_value, updated_by) VALUES ('', '$escK', '$escV', 'system')");
            }
        } else {
            // Periksa apakah kolom cmp_id sudah tersedia untuk multi-tenant
            $chkCol = @$dbConn->query("SHOW COLUMNS FROM bc_ceisa_setting LIKE 'cmp_id'");
            if ($chkCol && $chkCol->num_rows === 0) {
                @$dbConn->query("ALTER TABLE bc_ceisa_setting ADD COLUMN cmp_id VARCHAR(50) NOT NULL DEFAULT '' FIRST");
                @$dbConn->query("ALTER TABLE bc_ceisa_setting DROP PRIMARY KEY, ADD PRIMARY KEY (cmp_id, setting_key)");
            }

            // Migrasi nilai legacy jika batch size masih 1000 (disetel default baru: 50 baris)
            @$dbConn->query("UPDATE bc_ceisa_setting SET setting_value = '50' WHERE setting_key = 'ceisa_sync_batch_size' AND setting_value = '1000'");
        }
    }
}

if (!function_exists('getAppSettings')) {
    function getAppSettings($dbConn = null, $cmpId = '') {
        $defaults = getDefaultAppSettings();
        $globalSettings = [];
        $companySettings = [];
        $cmpId = trim((string)$cmpId);

        if (!$dbConn && isset($GLOBALS['koneksi']) && $GLOBALS['koneksi']) {
            $dbConn = $GLOBALS['koneksi'];
        }

        if ($dbConn) {
            ensureAppSettingsTable($dbConn);

            // 1. Ambil setting global (cmp_id = '')
            $resG = @$dbConn->query("SELECT setting_key, setting_value FROM bc_ceisa_setting WHERE cmp_id = ''");
            if ($resG) {
                while ($row = $resG->fetch_assoc()) {
                    $globalSettings[$row['setting_key']] = $row['setting_value'];
                }
            }

            // 2. Jika cmpId diisi, ambil setting kustom khusus perusahaan bersangkutan
            if (!empty($cmpId)) {
                $stmtC = $dbConn->prepare("SELECT setting_key, setting_value FROM bc_ceisa_setting WHERE cmp_id = ?");
                if ($stmtC) {
                    $stmtC->bind_param("s", $cmpId);
                    $stmtC->execute();
                    $resC = $stmtC->get_result();
                    if ($resC) {
                        while ($row = $resC->fetch_assoc()) {
                            $companySettings[$row['setting_key']] = $row['setting_value'];
                        }
                    }
                    $stmtC->close();
                }
            }
        }

        // Fallback global ke app_settings.json jika pembacaan database kosong
        $jsonFile = __DIR__ . '/app_settings.json';
        if (empty($globalSettings) && file_exists($jsonFile)) {
            $jsonContent = @file_get_contents($jsonFile);
            if ($jsonContent) {
                $cached = @json_decode($jsonContent, true);
                if (is_array($cached)) {
                    $globalSettings = $cached;
                }
            }
        }

        // Gabungkan defaults dengan global
        $mergedGlobal = array_merge($defaults, $globalSettings);

        // Fallback default wallpaper jika mode image dipilih tapi path kosong
        if (($mergedGlobal['login_bg_type'] ?? '') === 'image' && empty($mergedGlobal['login_bg_image'])) {
            $mergedGlobal['login_bg_image'] = '/assets/wallpaper-batik-default.png';
        }

        // Jika hanya minta global (cmpId kosong)
        if (empty($cmpId)) {
            if (!file_exists($jsonFile) || empty($globalSettings)) {
                @file_put_contents($jsonFile, json_encode($mergedGlobal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            $mergedGlobal['_is_company_custom'] = false;
            $mergedGlobal['_company_overrides'] = [];
            return $mergedGlobal;
        }

        // Jika minta setting untuk perusahaan spesifik:
        // Prioritas: Defaults -> Global Settings -> Company Custom Overrides
        $final = $mergedGlobal;
        $hasCustom = false;
        foreach ($companySettings as $k => $v) {
            if ($v !== null && $v !== '') {
                $final[$k] = $v;
                $hasCustom = true;
            }
        }

        // Fallback default wallpaper untuk perusahaan jika mode image aktif tapi gambar kosong
        if (($final['login_bg_type'] ?? '') === 'image' && empty($final['login_bg_image'])) {
            $final['login_bg_image'] = !empty($mergedGlobal['login_bg_image']) ? $mergedGlobal['login_bg_image'] : '/assets/wallpaper-batik-default.png';
        }

        $final['_is_company_custom'] = $hasCustom;
        $final['_company_overrides'] = $companySettings;
        return $final;
    }
}

if (!function_exists('saveAppSettings')) {
    function saveAppSettings($newSettings, $dbConn = null, $user = 'admin', $cmpId = '') {
        if (!$dbConn && isset($GLOBALS['koneksi']) && $GLOBALS['koneksi']) {
            $dbConn = $GLOBALS['koneksi'];
        }

        $cmpId = trim((string)$cmpId);

        if ($dbConn) {
            ensureAppSettingsTable($dbConn);

            $stmt = $dbConn->prepare("INSERT INTO bc_ceisa_setting (cmp_id, setting_key, setting_value, updated_by) 
                                      VALUES (?, ?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW(), updated_by = VALUES(updated_by)");
            if ($stmt) {
                foreach ($newSettings as $k => $v) {
                    if (str_starts_with($k, '_')) continue;
                    $vStr = (string)$v;
                    $stmt->bind_param("ssss", $cmpId, $k, $vStr, $user);
                    $stmt->execute();
                }
                $stmt->close();
            }

            // Jika mengedit logo perusahaan, sinkronkan juga ke tabel bc_ceisa_company jika ada
            if (!empty($cmpId) && isset($newSettings['app_logo']) && !empty($newSettings['app_logo'])) {
                $syncLogo = $dbConn->prepare("UPDATE bc_ceisa_company SET logo = ? WHERE cmp_id = ?");
                if ($syncLogo) {
                    $logoVal = (string)$newSettings['app_logo'];
                    $syncLogo->bind_param("ss", $logoVal, $cmpId);
                    $syncLogo->execute();
                    $syncLogo->close();
                }
            }
        }

        // Simpan cache JSON jika menyimpan pengaturan Global
        if (empty($cmpId)) {
            $global = getAppSettings($dbConn, '');
            $jsonFile = __DIR__ . '/app_settings.json';
            @file_put_contents($jsonFile, json_encode($global, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return getAppSettings($dbConn, $cmpId);
    }
}

if (!function_exists('resetAppSettings')) {
    function resetAppSettings($dbConn = null, $user = 'admin', $cmpId = '') {
        if (!$dbConn && isset($GLOBALS['koneksi']) && $GLOBALS['koneksi']) {
            $dbConn = $GLOBALS['koneksi'];
        }

        $cmpId = trim((string)$cmpId);

        if ($dbConn) {
            ensureAppSettingsTable($dbConn);

            if (!empty($cmpId)) {
                // Hapus override perusahaan sehingga otomatis kembali mengikuti setting Global
                $stmt = $dbConn->prepare("DELETE FROM bc_ceisa_setting WHERE cmp_id = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $cmpId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                // Reset global ke defaults
                $defaults = getDefaultAppSettings();
                return saveAppSettings($defaults, $dbConn, 'reset_' . $user, '');
            }
        }

        return getAppSettings($dbConn, $cmpId);
    }
}

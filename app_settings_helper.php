<?php
/**
 * CEISA 4.0 - Application Settings Helper (app_settings_helper.php)
 * Mengelola konfigurasi umum, branding/identitas portal, integrasi CEISA, dan preferensi modul
 * Terhubung ke tabel database `bc_ceisa_setting` dengan fallback cache `app_settings.json`
 */

if (!function_exists('getDefaultAppSettings')) {
    function getDefaultAppSettings() {
        return [
            // 1. Identitas & Branding Aplikasi (Dapat diedit oleh Admin)
            'app_name'           => 'INHOST',
            'app_subtitle'       => 'Interface Host to Host Ceisa 4.0',
            'app_logo'           => '/assets/logo.png',
            'app_favicon'        => '/assets/logo.png',
            'app_footer'         => 'Interface Host to Host (INHOST) Ceisa 4.0 • Single Core System',
            'login_badge'        => 'Single Core System',
            
            // 2. CEISA 4.0 Integration & Environment
            'ceisa_env'             => 'production', // 'production' | 'staging'
            'ceisa_api_url'         => 'https://apis-gw.beacukai.go.id',
            'ceisa_sync_batch_size' => '1000', // Jumlah dokumen ditarik per sync (10 s/d 1000)
            'tracking_refresh'      => '0', // detik (0 = Non-Aktif / Manual Saja, aman dari rate-limit)
            'api_timeout'           => '30', // detik
            
            // 3. Modul IT Inventory & Aturan Bisnis
            'prefix_spp'         => 'SPP-',
            'prefix_transfer'    => 'TRF-',
            'lock_inventory_aju' => '1', // 1: AJU terkunci bila sudah diposting ke IT Inventory
            'require_confirm_ya' => '1', // 1: Wajib ketik "YA" untuk hapus data AJU
            'default_tahun_buku' => 'auto', // 'auto' | format YYYY
            
            // 4. UI / UX Portal & Tampilan
            'default_theme'      => 'light', // 'light' | 'dark'
            'default_sidebar'    => 'pinned', // 'pinned' | 'unpinned'
            'sidebar_icon_style' => 'colored', // 'colored' (Default Berwarna/Emoji) | 'monochrome' (Ant Design SVG)
            'default_page_size'  => '25', // '10' | '25' | '50' | '100'
            'toast_duration'     => '2600' // milidetik
        ];
    }
}

if (!function_exists('getAppSettings')) {
    function getAppSettings($dbConn = null) {
        $defaults = getDefaultAppSettings();
        $settings = [];

        // Coba baca dari database terlebih dahulu
        if (!$dbConn && isset($GLOBALS['koneksi']) && $GLOBALS['koneksi']) {
            $dbConn = $GLOBALS['koneksi'];
        }

        if ($dbConn) {
            // Pastikan tabel ada
            $chk = @$dbConn->query("SHOW TABLES LIKE 'bc_ceisa_setting'");
            if ($chk && $chk->num_rows > 0) {
                $res = @$dbConn->query("SELECT setting_key, setting_value FROM bc_ceisa_setting");
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $settings[$row['setting_key']] = $row['setting_value'];
                    }
                }
            } else {
                // Buat tabel jika belum ada
                @$dbConn->query("CREATE TABLE IF NOT EXISTS bc_ceisa_setting (
                    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                    setting_value LONGTEXT,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by VARCHAR(100) DEFAULT 'admin'
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Inisialisasi data default ke database
                foreach ($defaults as $k => $v) {
                    $escK = $dbConn->real_escape_string($k);
                    $escV = $dbConn->real_escape_string($v);
                    @$dbConn->query("INSERT IGNORE INTO bc_ceisa_setting (setting_key, setting_value, updated_by) VALUES ('$escK', '$escV', 'system')");
                }
            }
        }

        // Jika pembacaan database kosong, coba ambil dari file JSON cache
        $jsonFile = __DIR__ . '/app_settings.json';
        if (empty($settings) && file_exists($jsonFile)) {
            $jsonContent = @file_get_contents($jsonFile);
            if ($jsonContent) {
                $cached = @json_decode($jsonContent, true);
                if (is_array($cached)) {
                    $settings = $cached;
                }
            }
        }

        // Gabungkan dengan default agar semua key selalu terdefinisi
        $final = array_merge($defaults, $settings);

        // Jika file JSON belum ada atau berbeda, simpan cache lokal
        if (!file_exists($jsonFile) || empty($settings)) {
            @file_put_contents($jsonFile, json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $final;
    }
}

if (!function_exists('saveAppSettings')) {
    function saveAppSettings($newSettings, $dbConn = null, $user = 'admin') {
        if (!$dbConn && isset($GLOBALS['koneksi']) && $GLOBALS['koneksi']) {
            $dbConn = $GLOBALS['koneksi'];
        }

        $current = getAppSettings($dbConn);
        $updated = array_merge($current, $newSettings);

        if ($dbConn) {
            // Pastikan tabel ada
            @$dbConn->query("CREATE TABLE IF NOT EXISTS bc_ceisa_setting (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value LONGTEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by VARCHAR(100) DEFAULT 'admin'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $dbConn->prepare("INSERT INTO bc_ceisa_setting (setting_key, setting_value, updated_by) 
                                      VALUES (?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW(), updated_by = VALUES(updated_by)");
            if ($stmt) {
                foreach ($updated as $k => $v) {
                    $vStr = (string)$v;
                    $stmt->bind_param("sss", $k, $vStr, $user);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }

        // Simpan ke cache JSON
        $jsonFile = __DIR__ . '/app_settings.json';
        @file_put_contents($jsonFile, json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $updated;
    }
}

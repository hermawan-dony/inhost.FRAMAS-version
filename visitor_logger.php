<?php
/**
 * CEISA 4.0 - Visitor & Login Activity Logger (visitor_logger.php)
 * Mencatat IP Unik, Lokasi (Kota/Negara/ISP), Subdomain Terakses, 
 * Frekuensi Kunjungan (Hits), dan Akun Login.
 */

if (!function_exists('getVisitorClientIp')) {
    function getVisitorClientIp() {
        $ipSources = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',        // Nginx reverse proxy
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',  // Load balancer / proxy list
            'REMOTE_ADDR'
        ];
        foreach ($ipSources as $source) {
            if (!empty($_SERVER[$source])) {
                $rawList = explode(',', $_SERVER[$source]);
                foreach ($rawList as $candidate) {
                    $candidate = trim($candidate);
                    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $candidate;
                    }
                }
            }
        }
        // Fallback ke REMOTE_ADDR jika berada di jaringan lokal / dev
        $fallback = trim($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        if (filter_var($fallback, FILTER_VALIDATE_IP)) {
            return $fallback;
        }
        return '127.0.0.1';
    }
}

if (!function_exists('isVisitorPrivateIp')) {
    function isVisitorPrivateIp($ip) {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}

if (!function_exists('getVisitorLocation')) {
    function getVisitorLocation($koneksi, $ip) {
        $defaultLoc = [
            'country_code' => 'ID',
            'country_name' => 'Indonesia',
            'region_name'  => 'Jawa Barat',
            'city'         => 'Bekasi / Jakarta',
            'isp'          => 'Internet Service Provider',
            'latitude'     => -6.2383,
            'longitude'    => 106.9756
        ];

        if (isVisitorPrivateIp($ip)) {
            $defaultLoc['country_name'] = 'Localhost / LAN';
            $defaultLoc['region_name']  = 'Internal Network';
            $defaultLoc['city']         = 'Local Server';
            $defaultLoc['isp']          = 'Loopback / Private LAN';
            return $defaultLoc;
        }

        if (!$koneksi || $koneksi->connect_error) {
            return $defaultLoc;
        }

        // 1. Cek dari Cache Database Lokal
        $escIp = $koneksi->real_escape_string($ip);
        $res = $koneksi->query("SELECT country_code, country_name, region_name, city, isp, latitude, longitude 
                                FROM bc_visitor_geo_cache 
                                WHERE ip_address = '{$escIp}' LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            if (!empty($row['country_name'])) {
                return $row;
            }
        }

        // 2. Query GeoIP API Eksternal (ip-api.com) dengan timeout cepat (1.5 detik)
        $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,regionName,city,isp,lat,lon";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$err && !empty($response)) {
            $data = @json_decode($response, true);
            if (!empty($data) && isset($data['status']) && $data['status'] === 'success') {
                $loc = [
                    'country_code' => $data['countryCode'] ?? 'ID',
                    'country_name' => $data['country'] ?? 'Indonesia',
                    'region_name'  => $data['regionName'] ?? '',
                    'city'         => $data['city'] ?? '',
                    'isp'          => $data['isp'] ?? '',
                    'latitude'     => $data['lat'] ?? 0.0,
                    'longitude'    => $data['lon'] ?? 0.0
                ];

                // Simpan ke Cache
                $stmt = $koneksi->prepare("INSERT INTO bc_visitor_geo_cache 
                    (ip_address, country_code, country_name, region_name, city, isp, latitude, longitude) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    country_code = VALUES(country_code),
                    country_name = VALUES(country_name),
                    region_name = VALUES(region_name),
                    city = VALUES(city),
                    isp = VALUES(isp),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude)");
                if ($stmt) {
                    $stmt->bind_param("ssssssdd", 
                        $ip, $loc['country_code'], $loc['country_name'], 
                        $loc['region_name'], $loc['city'], $loc['isp'], 
                        $loc['latitude'], $loc['longitude']
                    );
                    $stmt->execute();
                    $stmt->close();
                }
                return $loc;
            }
        }

        return $defaultLoc;
    }
}

if (!function_exists('parseVisitorUserAgent')) {
    function parseVisitorUserAgent($ua) {
        $browser = 'Unknown';
        $os = 'Unknown';
        $device = 'Desktop';

        // Deteksi Device
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile|mobile)/i', $ua)) {
            $device = 'Mobile';
        }

        // Deteksi OS
        if (preg_match('/windows nt 10/i', $ua)) $os = 'Windows 10/11';
        elseif (preg_match('/windows nt 6.3/i', $ua)) $os = 'Windows 8.1';
        elseif (preg_match('/windows nt 6.1/i', $ua)) $os = 'Windows 7';
        elseif (preg_match('/windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/iphone/i', $ua)) $os = 'iOS (iPhone)';
        elseif (preg_match('/ipad/i', $ua)) $os = 'iPadOS';
        elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
        elseif (preg_match('/android/i', $ua)) $os = 'Android';
        elseif (preg_match('/linux/i', $ua)) $os = 'Linux';

        // Deteksi Browser
        if (preg_match('/edg/i', $ua)) $browser = 'Microsoft Edge';
        elseif (preg_match('/opr|opera/i', $ua)) $browser = 'Opera';
        elseif (preg_match('/chrome/i', $ua)) $browser = 'Google Chrome';
        elseif (preg_match('/firefox/i', $ua)) $browser = 'Mozilla Firefox';
        elseif (preg_match('/safari/i', $ua)) $browser = 'Apple Safari';
        elseif (preg_match('/msie|trident/i', $ua)) $browser = 'Internet Explorer';

        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }
}

if (!function_exists('detectVisitorSubdomain')) {
    function detectVisitorSubdomain() {
        if (!empty($_GET['subdomain'])) {
            $s = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['subdomain'])));
            if (!in_array($s, ['none', 'root', 'all', 'portal', 'www', 'localhost', ''])) {
                return $s;
            }
        }
        $rawHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $cleanHost = explode(':', $rawHost)[0];
        $parts = explode('.', $cleanHost);
        if (count($parts) >= 3) {
            $firstPart = strtolower($parts[0]);
            if (!in_array($firstPart, ['www', 'root', 'portal', 'api', 'app', 'mail', 'cpanel', 'localhost'])) {
                return $firstPart;
            }
        }
        return 'root';
    }
}

if (!function_exists('recordVisitorAccess')) {
    function recordVisitorAccess($koneksi, $options = []) {
        if (!$koneksi || $koneksi->connect_error) {
            return false;
        }

        $event    = $options['event'] ?? 'visit'; // 'visit' atau 'login'
        $userId   = $options['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $userName = $options['user_name'] ?? ($_SESSION['user_name'] ?? null);
        $cmpId    = $options['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? null));

        $ip = getVisitorClientIp();
        $subdomain = detectVisitorSubdomain();
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $pageUrl = $_SERVER['REQUEST_URI'] ?? '/';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Hindari logging berulang di session yang sama jika interval < 30 detik (kecuali saat event login)
        $now = time();
        $sessKey = 'vis_' . md5($ip . '_' . $subdomain);
        $lastLogTime = $_SESSION[$sessKey] ?? 0;
        
        $shouldUpdateCount = ($event === 'login') || (($now - $lastLogTime) >= 30);
        $_SESSION[$sessKey] = $now;

        $clientInfo = parseVisitorUserAgent($ua);
        $loc = getVisitorLocation($koneksi, $ip);

        // 1. Catat ke bc_visitor_log (Agregat per IP & Subdomain)
        $stmt = $koneksi->prepare("INSERT INTO bc_visitor_log (
            ip_address, subdomain, host, user_id, user_name, cmp_id,
            country_code, country_name, region_name, city, isp,
            user_agent, browser, os, device_type, hit_count, first_visit, last_visit
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, 1, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            host = VALUES(host),
            user_id = IF(VALUES(user_id) IS NOT NULL AND VALUES(user_id) != '', VALUES(user_id), user_id),
            user_name = IF(VALUES(user_name) IS NOT NULL AND VALUES(user_name) != '', VALUES(user_name), user_name),
            cmp_id = IF(VALUES(cmp_id) IS NOT NULL AND VALUES(cmp_id) != '', VALUES(cmp_id), cmp_id),
            country_name = IF(VALUES(country_name) != '', VALUES(country_name), country_name),
            city = IF(VALUES(city) != '', VALUES(city), city),
            isp = IF(VALUES(isp) != '', VALUES(isp), isp),
            user_agent = VALUES(user_agent),
            browser = VALUES(browser),
            os = VALUES(os),
            device_type = VALUES(device_type),
            hit_count = IF(?, hit_count + 1, hit_count),
            last_visit = NOW()");

        if ($stmt) {
            $isInc = $shouldUpdateCount ? 1 : 0;
            $stmt->bind_param("sssssssssssssssi",
                $ip, $subdomain, $host, $userId, $userName, $cmpId,
                $loc['country_code'], $loc['country_name'], $loc['region_name'], $loc['city'], $loc['isp'],
                $ua, $clientInfo['browser'], $clientInfo['os'], $clientInfo['device'],
                $isInc
            );
            $stmt->execute();
            $stmt->close();
        }

        // 2. Catat ke stream bc_visitor_hits jika event login atau interval 5 menit
        $hitSessKey = 'hit_' . md5($ip . '_' . $subdomain);
        $lastHitTime = $_SESSION[$hitSessKey] ?? 0;
        if ($event === 'login' || ($now - $lastHitTime) >= 300) {
            $_SESSION[$hitSessKey] = $now;
            $stmtH = $koneksi->prepare("INSERT INTO bc_visitor_hits (
                ip_address, subdomain, user_id, cmp_id, action_event, page_url, referer, city, country_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmtH) {
                $stmtH->bind_param("sssssssss",
                    $ip, $subdomain, $userId, $cmpId, $event, $pageUrl, $referer, $loc['city'], $loc['country_name']
                );
                $stmtH->execute();
                $stmtH->close();
            }
        }

        return true;
    }
}

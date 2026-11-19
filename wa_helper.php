<?php
/**
 * CEISA 4.0 & ERP Hub - WhatsApp Notification Service Helper (wa_helper.php)
 * Mengelola pengiriman notifikasi WhatsApp otomatis melalui WA Gateway API (vpsmurah.co.id).
 * Digunakan untuk:
 * - Notifikasi tiket baru ke pemohon dan admin (dp)
 * - Notifikasi saat tiket diselesaikan (solved) atau mendapat tanggapan diskusi
 * - Format nomor wajib dinormalisasi ke format internasional Indonesia: 628xxx
 */

if (!defined('DEFAULT_WA_API_KEY')) {
    define('DEFAULT_WA_API_KEY', 'sk-api-5adnzcm2j7vbeyl8y25xuew3598f24tbzlm4l8b1');
}
if (!defined('DEFAULT_WA_API_URL')) {
    define('DEFAULT_WA_API_URL', 'https://my.vpsmurah.co.id/api/svc/v1/wa-api/messages');
}

/**
 * Normalisasi nomor WhatsApp ke standar format 628xxx.
 * Mendukung input: 0812..., +62812..., 62812..., 812...
 * 
 * @param string|null $phone
 * @return string Nomor dalam format 628xxx atau string kosong jika tidak valid
 */
function formatWaNumber($phone) {
    if (empty($phone)) return '';
    
    // Hapus seluruh karakter selain angka
    $clean = preg_replace('/[^0-9]/', '', (string)$phone);
    if (empty($clean)) return '';

    // Jika diawali 08 (misal: 081234567890) -> ganti 0 dengan 62 -> 6281234567890
    if (strpos($clean, '08') === 0) {
        $clean = '62' . substr($clean, 1);
    }
    // Jika diawali 8 (misal: 81234567890) -> tambahkan 62 -> 6281234567890
    elseif (strpos($clean, '8') === 0) {
        $clean = '62' . $clean;
    }
    // Jika diawali 00628 -> 628
    elseif (strpos($clean, '00628') === 0) {
        $clean = substr($clean, 2);
    }

    // Validasi panjang dan pola: 628 + 8 s/d 13 digit angka
    if (preg_match('/^628[0-9]{8,13}$/', $clean)) {
        return $clean;
    }

    // Jika masih diawali 62 dan panjangnya wajar (10-15 digit), tetap izinkan
    if (strpos($clean, '62') === 0 && strlen($clean) >= 10 && strlen($clean) <= 16) {
        return $clean;
    }

    return '';
}

/**
 * Mengambil konfigurasi WhatsApp Gateway dari database `bc_ceisa_setting`
 * 
 * @param mysqli|null $dbConn
 * @return array ['api_key' => '...', 'api_url' => '...', 'notif_enabled' => bool, 'admin_number' => '...']
 */
function getWaConfig($dbConn = null) {
    if (!$dbConn && isset($GLOBALS['koneksi']) && $GLOBALS['koneksi']) {
        $dbConn = $GLOBALS['koneksi'];
    }

    $config = [
        'api_key'       => DEFAULT_WA_API_KEY,
        'api_url'       => DEFAULT_WA_API_URL,
        'notif_enabled' => true,
        'admin_number'  => ''
    ];

    if ($dbConn) {
        $res = @$dbConn->query("SELECT setting_key, setting_value FROM bc_ceisa_setting 
                                WHERE setting_key IN ('wa_api_key', 'wa_api_url', 'wa_notif_enabled', 'wa_admin_number') 
                                ORDER BY (cmp_id = '') ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $val = trim((string)$row['setting_value']);
                if ($row['setting_key'] === 'wa_api_key' && !empty($val)) {
                    $config['api_key'] = $val;
                } elseif ($row['setting_key'] === 'wa_api_url' && !empty($val)) {
                    $config['api_url'] = $val;
                } elseif ($row['setting_key'] === 'wa_notif_enabled') {
                    $config['notif_enabled'] = ($val === '1' || strtolower($val) === 'true');
                } elseif ($row['setting_key'] === 'wa_admin_number' && !empty($val)) {
                    $config['admin_number'] = formatWaNumber($val);
                }
            }
        }
    }

    return $config;
}

/**
 * Mengirim pesan WhatsApp via REST API gateway
 * 
 * @param string $to Nomor tujuan (akan otomatis dinormalisasi ke 628xxx)
 * @param string $message Isi pesan teks
 * @param mysqli|null $dbConn Koneksi database
 * @param string|null $customApiKey Override API key (misal untuk testing form)
 * @return array ['status' => 'OK'|'ERROR'|'DISABLED', 'message' => '...', 'http_code' => int, 'response' => string]
 */
function sendWaMessage($to, $message, $dbConn = null, $customApiKey = null) {
    $normalizedTo = formatWaNumber($to);
    if (empty($normalizedTo)) {
        return [
            'status'  => 'ERROR',
            'message' => 'Nomor WhatsApp tidak valid. Harap gunakan format 628xxx (contoh: 6281234567890).',
            'http_code' => 400
        ];
    }

    $config = getWaConfig($dbConn);
    
    // Jika tidak sedang custom test, periksa status enabled
    if ($customApiKey === null && !$config['notif_enabled']) {
        return [
            'status'    => 'DISABLED',
            'message'   => 'Notifikasi WhatsApp sedang dinonaktifkan pada Pengaturan Sistem.',
            'http_code' => 200
        ];
    }

    $apiKey = !empty($customApiKey) ? trim($customApiKey) : $config['api_key'];
    $apiUrl = !empty($config['api_url']) ? $config['api_url'] : DEFAULT_WA_API_URL;

    $payload = json_encode([
        'to'   => $normalizedTo,
        'text' => (string)$message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'status'    => 'ERROR',
            'message'   => 'Koneksi ke gateway WA gagal: ' . $curlErr,
            'http_code' => 0,
            'response'  => ''
        ];
    }

    $decoded = json_decode($response, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    return [
        'status'    => $isSuccess ? 'OK' : 'ERROR',
        'message'   => $isSuccess ? 'Pesan WhatsApp berhasil dikirim.' : 'Gateway WA mengembalikan status error (' . $httpCode . ')',
        'http_code' => $httpCode,
        'response'  => $response,
        'decoded'   => $decoded
    ];
}

/**
 * Mendapatkan base URL gateway WhatsApp (menghapus sub-path seperti /messages)
 * 
 * @param string|null $apiUrl
 * @return string Base URL (contoh: https://my.vpsmurah.co.id/api/svc/v1/wa-api)
 */
function getWaBaseApiUrl($apiUrl = null) {
    $url = !empty($apiUrl) ? trim($apiUrl) : DEFAULT_WA_API_URL;
    return preg_replace('#/(messages|media|inbox|contacts)/?$#i', '', rtrim($url, '/'));
}

/**
 * Mengirim pesan media WhatsApp (Gambar, Dokumen, Video, Audio) via Gateway REST API
 * 
 * curl -X POST "https://my.vpsmurah.co.id/api/svc/v1/wa-api/media" \
 *   -H "Authorization: Bearer sk-api-xxxxx" \
 *   -H "Content-Type: application/json" \
 *   -d '{"to":"6281234567890","type":"image","url":"https://.../foto.jpg","caption":"Promo"}'
 * 
 * @param string $to Nomor tujuan (format 628xxx)
 * @param string $type Tipe media ('image', 'document', 'video', 'audio')
 * @param string $url URL langsung berkas media
 * @param string $caption Keterangan media opsional
 * @param mysqli|null $dbConn
 * @param string|null $customApiKey
 * @return array
 */
function sendWaMedia($to, $type, $url, $caption = '', $dbConn = null, $customApiKey = null) {
    $normalizedTo = formatWaNumber($to);
    if (empty($normalizedTo)) {
        return [
            'status'    => 'ERROR',
            'message'   => 'Nomor WhatsApp tidak valid. Harap gunakan format 628xxx (contoh: 6281234567890).',
            'http_code' => 400
        ];
    }
    if (empty($url)) {
        return [
            'status'    => 'ERROR',
            'message'   => 'URL media wajib diisi.',
            'http_code' => 400
        ];
    }

    $config = getWaConfig($dbConn);
    if ($customApiKey === null && !$config['notif_enabled']) {
        return [
            'status'    => 'DISABLED',
            'message'   => 'Notifikasi WhatsApp sedang dinonaktifkan pada Pengaturan Sistem.',
            'http_code' => 200
        ];
    }

    $apiKey = !empty($customApiKey) ? trim($customApiKey) : $config['api_key'];
    $baseUrl = getWaBaseApiUrl($config['api_url'] ?? DEFAULT_WA_API_URL);
    $mediaEndpoint = $baseUrl . '/media';

    $cleanType = strtolower(trim($type));
    if (!in_array($cleanType, ['image', 'document', 'video', 'audio'])) {
        $cleanType = 'image';
    }

    $payload = json_encode([
        'to'      => $normalizedTo,
        'type'    => $cleanType,
        'url'     => trim($url),
        'caption' => (string)$caption
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($mediaEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'status'    => 'ERROR',
            'message'   => 'Koneksi ke gateway WA gagal: ' . $curlErr,
            'http_code' => 0,
            'response'  => ''
        ];
    }

    $decoded = json_decode($response, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    return [
        'status'    => $isSuccess ? 'OK' : 'ERROR',
        'message'   => $isSuccess ? ($decoded['message'] ?? 'Media WhatsApp berhasil dikirim.') : ($decoded['message'] ?? $decoded['error'] ?? 'Gateway WA mengembalikan error (' . $httpCode . ')'),
        'http_code' => $httpCode,
        'response'  => $response,
        'decoded'   => $decoded
    ];
}

/**
 * Membaca Kotak Masuk (Inbox) WhatsApp dari Gateway REST API
 * 
 * curl -X GET "https://my.vpsmurah.co.id/api/svc/v1/wa-api/inbox" \
 *   -H "Authorization: Bearer sk-api-xxxxx"
 * 
 * @param mysqli|null $dbConn
 * @param string|null $customApiKey
 * @return array
 */
function getWaInbox($dbConn = null, $customApiKey = null) {
    $config = getWaConfig($dbConn);
    $apiKey = !empty($customApiKey) ? trim($customApiKey) : $config['api_key'];
    $baseUrl = getWaBaseApiUrl($config['api_url'] ?? DEFAULT_WA_API_URL);
    $inboxEndpoint = $baseUrl . '/inbox';

    $ch = curl_init($inboxEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'status'    => 'ERROR',
            'message'   => 'Koneksi ke gateway WA gagal: ' . $curlErr,
            'http_code' => 0,
            'response'  => '',
            'data'      => []
        ];
    }

    $decoded = json_decode($response, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    // Ambil array data pesan masuk jika ada
    $inboxData = [];
    if (is_array($decoded)) {
        if (!empty($decoded['data']) && is_array($decoded['data'])) {
            $inboxData = $decoded['data'];
        } elseif (!empty($decoded['messages']) && is_array($decoded['messages'])) {
            $inboxData = $decoded['messages'];
        } elseif (!empty($decoded['inbox']) && is_array($decoded['inbox'])) {
            $inboxData = $decoded['inbox'];
        } elseif ($isSuccess && !isset($decoded['error']) && array_is_list($decoded)) {
            $inboxData = $decoded;
        }
    }

    return [
        'status'    => $isSuccess ? 'OK' : 'ERROR',
        'message'   => $isSuccess ? 'Inbox berhasil dimuat.' : ($decoded['error'] ?? $decoded['message'] ?? 'Gateway WA mengembalikan error (' . $httpCode . ')'),
        'http_code' => $httpCode,
        'response'  => $response,
        'decoded'   => $decoded,
        'data'      => $inboxData
    ];
}

/**
 * Membaca Daftar Kontak WhatsApp dari Gateway REST API
 * 
 * curl -X GET "https://my.vpsmurah.co.id/api/svc/v1/wa-api/contacts" \
 *   -H "Authorization: Bearer sk-api-xxxxx"
 * 
 * @param mysqli|null $dbConn
 * @param string|null $customApiKey
 * @return array
 */
function getWaContacts($dbConn = null, $customApiKey = null) {
    $config = getWaConfig($dbConn);
    $apiKey = !empty($customApiKey) ? trim($customApiKey) : $config['api_key'];
    $baseUrl = getWaBaseApiUrl($config['api_url'] ?? DEFAULT_WA_API_URL);
    $contactsEndpoint = $baseUrl . '/contacts';

    $ch = curl_init($contactsEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'status'    => 'ERROR',
            'message'   => 'Koneksi ke gateway WA gagal: ' . $curlErr,
            'http_code' => 0,
            'response'  => '',
            'data'      => []
        ];
    }

    $decoded = json_decode($response, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    $contactsData = [];
    if (is_array($decoded)) {
        if (!empty($decoded['data']) && is_array($decoded['data'])) {
            $contactsData = $decoded['data'];
        } elseif (!empty($decoded['contacts']) && is_array($decoded['contacts'])) {
            $contactsData = $decoded['contacts'];
        } elseif ($isSuccess && !isset($decoded['error']) && array_is_list($decoded)) {
            $contactsData = $decoded;
        }
    }

    return [
        'status'    => $isSuccess ? 'OK' : 'ERROR',
        'message'   => $isSuccess ? 'Daftar kontak berhasil dimuat.' : ($decoded['error'] ?? $decoded['message'] ?? 'Gateway WA mengembalikan error (' . $httpCode . ')'),
        'http_code' => $httpCode,
        'response'  => $response,
        'decoded'   => $decoded,
        'data'      => $contactsData
    ];
}

/**
 * Auto-heal kolom database & konfigurasi awal
 */
function ensureWaTablesAndColumns($dbConn) {
    if (!$dbConn) return;

    // 1. Kolom no_wa di bc_ceisa_login
    $chk1 = @$dbConn->query("SHOW COLUMNS FROM bc_ceisa_login LIKE 'no_wa'");
    if (!$chk1 || $chk1->num_rows === 0) {
        @$dbConn->query("ALTER TABLE bc_ceisa_login ADD COLUMN no_wa VARCHAR(30) DEFAULT '' AFTER nama_lengkap");
    }

    // 2. Kolom no_wa di bc_system_tickets
    $chk2 = @$dbConn->query("SHOW COLUMNS FROM bc_system_tickets LIKE 'no_wa'");
    if (!$chk2 || $chk2->num_rows === 0) {
        @$dbConn->query("ALTER TABLE bc_system_tickets ADD COLUMN no_wa VARCHAR(30) DEFAULT '' AFTER created_by_name");
    }

    // 3. Setting default di bc_ceisa_setting
    $defaults = [
        'wa_api_key'       => DEFAULT_WA_API_KEY,
        'wa_api_url'       => DEFAULT_WA_API_URL,
        'wa_notif_enabled' => '1',
        'wa_admin_number'  => ''
    ];
    foreach ($defaults as $k => $v) {
        $escK = $dbConn->real_escape_string($k);
        $escV = $dbConn->real_escape_string($v);
        @$dbConn->query("INSERT IGNORE INTO bc_ceisa_setting (cmp_id, setting_key, setting_value, updated_by) VALUES ('', '$escK', '$escV', 'system')");
    }
}

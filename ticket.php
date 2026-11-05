<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Pusat Tiket & Request Sistem (Bug Tracker, Modification & Feature Request)
 * Modul Terintegrasi Asisten AI (Adek / Dd)
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrik Tiket Real-Time (Total Tiket, Tiket Menunggu/Open, Dalam Pengerjaan, Selesai/Solved)
 * - Tab 1: 📋 Daftar & Monitoring Tiket (Filter Kategori, Status, Prioritas, Resizable Grid, Detail Modal, Diskusi)
 * - Tab 2: ➕ Buat Tiket Baru (Pilihan Kategori: Bug, Modifikasi/Improve, Fitur Baru)
 * - Dropdown Pintar Daftar Menu/Halaman Aplikasi yang Lengkap
 * - Dukungan Paste Clipboard Screenshot (Ctrl+V) & Unggah File/Gambar
 * - Integrasi 1-Klik ke Asisten AI (Adek) untuk Penyelesaian Otomatis
 * - Notifikasi Real-Time status penyelesaian tiket untuk pengguna
 */

session_start();
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/wa_helper.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($_GET['cmp_id'] ?? ($cmp_id ?? '')));
if (empty($sessionCmpId)) {
    $sessionCmpId = 'SNU';
}

// Resolusi resmi Perusahaan & Subdomain dari Database (Multi-tenant)
$qCmp = $koneksi->query("SELECT cmp_id, cmp_name, subdomain FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($sessionCmpId) . "' LIMIT 1");
$dbSubdomain = '';
$dbCmpName   = '';
if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
    $dbSubdomain = $rCmp['subdomain'] ?? '';
    $dbCmpName   = $rCmp['cmp_name'] ?? '';
}

$sessionSubdomain = !empty($dbSubdomain) ? $dbSubdomain : ($_SESSION['company_subdomain'] ?? '');
if (empty($sessionSubdomain)) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $parts = explode('.', $host);
    if (count($parts) > 2 && !in_array($parts[0], ['www', 'mail', 'cpanel', 'webmail'])) {
        $sessionSubdomain = strtolower($parts[0]);
    }
}
if (empty($sessionSubdomain)) {
    $sessionSubdomain = strtolower($sessionCmpId);
}

$sessionCmpName = !empty($dbCmpName) ? $dbCmpName : ($_SESSION['company_name'] ?? ($namaperusahaan ?? $sessionCmpId));
$userId         = $_SESSION['user_id'] ?? 'GUEST';
$userName       = $_SESSION['user_name'] ?? ($_SESSION['nama_lengkap'] ?? ($_SESSION['name'] ?? $userId));

// Resolusi Nama Lengkap Pengguna jika masih ID
if (($userName === $userId || empty($userName)) && $userId !== 'GUEST') {
    $qUser = $koneksi->query("SELECT nama_lengkap FROM bc_ceisa_login WHERE user_id = '" . $koneksi->real_escape_string($userId) . "' LIMIT 1");
    if ($qUser && $ru = $qUser->fetch_assoc()) {
        if (!empty($ru['nama_lengkap'])) {
            $userName = $ru['nama_lengkap'];
        }
    }
}

// Resolusi Nomor WhatsApp Pengguna Login
$userWaNumber = '';
if ($userId !== 'GUEST' && !empty($koneksi)) {
    try {
        $qWa = $koneksi->query("SELECT no_wa FROM bc_ceisa_login WHERE user_id = '" . $koneksi->real_escape_string($userId) . "' LIMIT 1");
        if ($qWa && $rwa = $qWa->fetch_assoc()) {
            $userWaNumber = $rwa['no_wa'] ?? '';
        }
    } catch (\Throwable $t) {
        $userWaNumber = '';
    }
}

$userRole       = $_SESSION['user_role'] ?? 'operator';
$isSuperAdmin   = (!empty($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) || in_array(strtolower($userId), ['dp', 'admin']);
$isAdmin        = !empty($_SESSION['is_admin']) || $isSuperAdmin;
$appMode        = $_SESSION['app_mode'] ?? 'standar';
$isLocalAi      = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

$isUserLoggedIn = !empty($_SESSION['authenticated']) || !empty($_SESSION['user_id']) || $isAdmin;
if (!$isUserLoggedIn && !$isLocalAi) {
    die("<div style='padding:20px; font-family:sans-serif;'>Silakan login ke portal sistem untuk mengakses menu Pusat Tiket.</div>");
}
if (!empty($_SESSION['is_guest']) && !$isLocalAi) {
    die("<div style='padding:25px; font-family:sans-serif; text-align:center;'><h3>Akses Terbatas</h3><p style='color:#666;'>Menu Pusat Tiket &amp; Request tidak tersedia untuk akun Tamu.</p></div>");
}

session_write_close();

// Pastikan tabel bc_system_tickets dan bc_system_ticket_comments ada di master database
$masterDb = defined('CEISA_MASTER_DB') ? CEISA_MASTER_DB : 'bc2faktur_inhost';

function ensureTicketTables($koneksi, $masterDb) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `{$masterDb}`.`bc_system_tickets` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `ticket_no` varchar(30) NOT NULL,
      `category` enum('bug','improve','feature') NOT NULL DEFAULT 'bug',
      `menu_target` varchar(100) DEFAULT NULL,
      `menu_name` varchar(150) DEFAULT NULL,
      `title` varchar(255) NOT NULL,
      `description` text NOT NULL,
      `priority` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
      `attachment_path` text DEFAULT NULL,
      `attachment_name` text DEFAULT NULL,
      `status` enum('open','analyzing','in_progress','solved','closed','rejected') NOT NULL DEFAULT 'open',
      `created_by_id` varchar(50) NOT NULL,
      `created_by_name` varchar(100) NOT NULL,
      `cmp_id` varchar(30) NOT NULL,
      `cmp_name` varchar(100) DEFAULT NULL,
      `subdomain` varchar(50) DEFAULT '',
      `created_at` datetime NOT NULL,
      `updated_at` datetime NOT NULL,
      `solved_at` datetime DEFAULT NULL,
      `solved_by` varchar(100) DEFAULT NULL,
      `solution_notes` text DEFAULT NULL,
      `ai_status` enum('pending','in_review','solving','resolved') NOT NULL DEFAULT 'pending',
      `ai_prompt` text DEFAULT NULL,
      `notif_read` tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_ticket_no` (`ticket_no`),
      KEY `idx_cmp_id` (`cmp_id`),
      KEY `idx_created_by` (`created_by_id`),
      KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $koneksi->query("CREATE TABLE IF NOT EXISTS `{$masterDb}`.`bc_system_ticket_comments` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `ticket_id` int(11) NOT NULL,
      `sender_type` enum('user','ai','admin') NOT NULL DEFAULT 'user',
      `sender_id` varchar(50) DEFAULT NULL,
      `sender_name` varchar(100) NOT NULL,
      `comment` text NOT NULL,
      `attachment_path` varchar(255) DEFAULT NULL,
      `created_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_ticket_id` (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $chkSub = $koneksi->query("SHOW COLUMNS FROM `{$masterDb}`.`bc_system_tickets` LIKE 'subdomain'");
    if ($chkSub && $chkSub->num_rows === 0) {
        @$koneksi->query("ALTER TABLE `{$masterDb}`.`bc_system_tickets` ADD COLUMN `subdomain` varchar(50) DEFAULT '' AFTER `cmp_name`");
    }

    $chkWa = $koneksi->query("SHOW COLUMNS FROM `{$masterDb}`.`bc_system_tickets` LIKE 'no_wa'");
    if ($chkWa && $chkWa->num_rows === 0) {
        @$koneksi->query("ALTER TABLE `{$masterDb}`.`bc_system_tickets` ADD COLUMN `no_wa` varchar(30) DEFAULT '' AFTER `created_by_name`");
    }
}
ensureTicketTables($koneksi, $masterDb);

$action = $_REQUEST['action'] ?? '';

// =========================================================================
// BACKEND AJAX API: KPI Metrik Tiket
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $escUser = $koneksi->real_escape_string($userId);
    $escCmp  = $koneksi->real_escape_string($sessionCmpId);

    // Filter lingkup akses: Admin/Super Admin melihat seluruh tiket perusahaan / sistem, user biasa melihat miliknya atau perusahaannya
    $whereScope = $isSuperAdmin ? "1=1" : ($isAdmin ? "cmp_id = '$escCmp'" : "(created_by_id = '$escUser' OR cmp_id = '$escCmp')");

    $sql = "SELECT 
        COUNT(*) as total_tickets,
        COALESCE(SUM(CASE WHEN status IN ('open', 'analyzing') THEN 1 ELSE 0 END), 0) as open_tickets,
        COALESCE(SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END), 0) as in_progress_tickets,
        COALESCE(SUM(CASE WHEN status = 'solved' THEN 1 ELSE 0 END), 0) as solved_tickets,
        COALESCE(SUM(CASE WHEN priority IN ('high', 'critical') AND status != 'solved' THEN 1 ELSE 0 END), 0) as urgent_tickets,
        COALESCE(SUM(CASE WHEN category = 'bug' THEN 1 ELSE 0 END), 0) as bug_tickets,
        COALESCE(SUM(CASE WHEN category = 'improve' THEN 1 ELSE 0 END), 0) as improve_tickets,
        COALESCE(SUM(CASE WHEN category = 'feature' THEN 1 ELSE 0 END), 0) as feature_tickets
        FROM `{$masterDb}`.`bc_system_tickets` WHERE $whereScope";
    
    $res = $koneksi->query($sql);
    $row = $res ? $res->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'kpi'    => $row
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Daftar Tiket (Browse / Filter)
// =========================================================================
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $escUser = $koneksi->real_escape_string($userId);
    $escCmp  = $koneksi->real_escape_string($sessionCmpId);

    $where = [];
    if (!$isSuperAdmin) {
        if ($isAdmin) {
            $where[] = "cmp_id = '$escCmp'";
        } else {
            $where[] = "(created_by_id = '$escUser' OR cmp_id = '$escCmp')";
        }
    }

    $qCat = trim($_GET['category'] ?? '');
    if (!empty($qCat) && in_array($qCat, ['bug', 'improve', 'feature'])) {
        $where[] = "category = '" . $koneksi->real_escape_string($qCat) . "'";
    }

    $qStatus = trim($_GET['status'] ?? '');
    if (!empty($qStatus) && in_array($qStatus, ['open', 'analyzing', 'in_progress', 'solved', 'closed', 'rejected'])) {
        $where[] = "status = '" . $koneksi->real_escape_string($qStatus) . "'";
    }

    $qPrio = trim($_GET['priority'] ?? '');
    if (!empty($qPrio) && in_array($qPrio, ['low', 'medium', 'high', 'critical'])) {
        $where[] = "priority = '" . $koneksi->real_escape_string($qPrio) . "'";
    }

    $qSearch = trim($_GET['search'] ?? '');
    if (!empty($qSearch)) {
        $escS = $koneksi->real_escape_string($qSearch);
        $where[] = "(ticket_no LIKE '%$escS%' OR title LIKE '%$escS%' OR description LIKE '%$escS%' OR menu_name LIKE '%$escS%' OR created_by_name LIKE '%$escS%' OR cmp_name LIKE '%$escS%')";
    }

    $whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";
    $sql = "SELECT id, ticket_no, category, menu_target, menu_name, title, priority, status, 
                   created_by_id, created_by_name, no_wa, cmp_id, cmp_name, subdomain, 
                   created_at, updated_at, solved_at, solved_by, notif_read,
                   (CASE WHEN attachment_path IS NOT NULL AND attachment_path != '' AND attachment_path != '[]' THEN 1 ELSE 0 END) as has_attachment
            FROM `{$masterDb}`.`bc_system_tickets`
            $whereClause
            ORDER BY (status = 'open' OR status = 'in_progress') DESC, updated_at DESC, id DESC 
            LIMIT 200";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Detail Tiket & Komentar
// =========================================================================
if ($action === 'detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Tiket tidak valid.']);
        exit;
    }

    $res = $koneksi->query("SELECT * FROM `{$masterDb}`.`bc_system_tickets` WHERE id = $id LIMIT 1");
    if (!$res || !($ticket = $res->fetch_assoc())) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tiket tidak ditemukan.']);
        exit;
    }

    // Jika pengguna yang login adalah pemohon dan tiket berstatus solved, tandai notifikasi sudah dibaca
    if ($ticket['created_by_id'] === $userId && $ticket['status'] === 'solved' && $ticket['notif_read'] == 0) {
        $koneksi->query("UPDATE `{$masterDb}`.`bc_system_tickets` SET notif_read = 1 WHERE id = $id");
        $ticket['notif_read'] = 1;
    }

    // Ambil komentar/diskusi
    $comRes = $koneksi->query("SELECT * FROM `{$masterDb}`.`bc_system_ticket_comments` WHERE ticket_id = $id ORDER BY created_at ASC");
    $comments = [];
    if ($comRes) {
        while ($c = $comRes->fetch_assoc()) {
            $comments[] = $c;
        }
    }

    echo json_encode([
        'status'   => 'OK',
        'ticket'   => $ticket,
        'comments' => $comments,
        'is_admin' => $isAdmin
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Buat Tiket Baru (Create Ticket)
// =========================================================================
if ($action === 'create') {
    header('Content-Type: application/json; charset=utf-8');

    $category   = trim($_POST['category'] ?? 'bug');
    if (!in_array($category, ['bug', 'improve', 'feature'])) {
        $category = 'bug';
    }

    $menuTarget = trim($_POST['menu_target'] ?? '');
    $menuName   = trim($_POST['menu_name'] ?? '');
    $title      = trim($_POST['title'] ?? '');
    $description= trim($_POST['description'] ?? '');
    $priority   = trim($_POST['priority'] ?? 'medium');
    if (!in_array($priority, ['low', 'medium', 'high', 'critical'])) {
        $priority = 'medium';
    }

    if (empty($title)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Judul masalah atau permintaan wajib diisi.']);
        exit;
    }
    if (empty($description)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Penjelasan kendala / rincian permintaan wajib diisi.']);
        exit;
    }

    // Format Nomor Tiket Unik: TKT-YYYYMM-XXXX
    $ymPrefix = 'TKT-' . date('Ym') . '-';
    $seqRes = $koneksi->query("SELECT MAX(ticket_no) as max_no FROM `{$masterDb}`.`bc_system_tickets` WHERE ticket_no LIKE '{$ymPrefix}%'");
    $seq = 1;
    if ($seqRes && $seqRow = $seqRes->fetch_assoc()) {
        if (!empty($seqRow['max_no'])) {
            $curSeq = (int)substr($seqRow['max_no'], -4);
            $seq = $curSeq + 1;
        }
    }
    $ticketNo = $ymPrefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // Handle Upload Berkas & Gambar (Termasuk Paste Clipboard Base64)
    $uploadDir = __DIR__ . '/uploads/tickets/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $savedAttachments = [];
    $savedNames = [];

    // 1. Dari Clipboard Base64 (Array JSON)
    $clipboardImages = $_POST['clipboard_images'] ?? '';
    if (!empty($clipboardImages)) {
        $decoded = json_decode($clipboardImages, true);
        if (is_array($decoded)) {
            foreach ($decoded as $idx => $b64Item) {
                if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $b64Item, $matches)) {
                    $ext = strtolower($matches[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';
                    $data = base64_decode($matches[2]);
                    if ($data !== false) {
                        $fname = 'ss_' . $ticketNo . '_' . date('His') . '_' . ($idx + 1) . '.' . $ext;
                        $targetPath = $uploadDir . $fname;
                        if (file_put_contents($targetPath, $data)) {
                            $savedAttachments[] = '/uploads/tickets/' . $fname;
                            $savedNames[] = 'Screenshot_' . ($idx + 1) . '.' . $ext;
                        }
                    }
                }
            }
        }
    }

    // 2. Dari File Upload Reguler
    if (!empty($_FILES['files']['name'])) {
        $fileCount = is_array($_FILES['files']['name']) ? count($_FILES['files']['name']) : 1;
        for ($i = 0; $i < $fileCount; $i++) {
            $origName = is_array($_FILES['files']['name']) ? $_FILES['files']['name'][$i] : $_FILES['files']['name'];
            $tmpName  = is_array($_FILES['files']['tmp_name']) ? $_FILES['files']['tmp_name'][$i] : $_FILES['files']['tmp_name'];
            $errCode  = is_array($_FILES['files']['error']) ? $_FILES['files']['error'][$i] : $_FILES['files']['error'];

            if ($errCode === UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowed = ['png', 'jpg', 'jpeg', 'gif', 'pdf', 'xlsx', 'xls', 'zip', 'txt'];
                if (in_array($ext, $allowed)) {
                    $fname = 'file_' . $ticketNo . '_' . date('His') . '_' . ($i + 1) . '.' . $ext;
                    $targetPath = $uploadDir . $fname;
                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $savedAttachments[] = '/uploads/tickets/' . $fname;
                        $savedNames[] = $origName;
                    }
                }
            }
        }
    }

    $jsonAttachments = !empty($savedAttachments) ? json_encode($savedAttachments) : null;
    $jsonNames       = !empty($savedNames) ? json_encode($savedNames) : null;

    $escTicketNo   = $koneksi->real_escape_string($ticketNo);
    $escCategory   = $koneksi->real_escape_string($category);
    $escMenuTarget = $koneksi->real_escape_string($menuTarget);
    $escMenuName   = $koneksi->real_escape_string($menuName);
    $escTitle      = $koneksi->real_escape_string($title);
    $escDesc       = $koneksi->real_escape_string($description);
    $escPrio       = $koneksi->real_escape_string($priority);
    $noWa          = formatWaNumber($_POST['no_wa'] ?? $userWaNumber);
    $escNoWa       = $koneksi->real_escape_string($noWa);
    $escAttPath    = $jsonAttachments ? "'" . $koneksi->real_escape_string($jsonAttachments) . "'" : "NULL";
    $escAttName    = $jsonNames ? "'" . $koneksi->real_escape_string($jsonNames) . "'" : "NULL";
    $escUserId     = $koneksi->real_escape_string($userId);
    $escUserName   = $koneksi->real_escape_string($userName);
    $escCmpId      = $koneksi->real_escape_string($sessionCmpId);
    $escCmpName    = $koneksi->real_escape_string($sessionCmpName);
    $escSubdomain  = $koneksi->real_escape_string($sessionSubdomain);
    $now           = date('Y-m-d H:i:s');

    $sql = "INSERT INTO `{$masterDb}`.`bc_system_tickets` 
            (ticket_no, category, menu_target, menu_name, title, description, priority, 
             attachment_path, attachment_name, status, created_by_id, created_by_name, no_wa, 
             cmp_id, cmp_name, subdomain, created_at, updated_at, ai_status)
            VALUES 
            ('$escTicketNo', '$escCategory', '$escMenuTarget', '$escMenuName', '$escTitle', '$escDesc', '$escPrio',
             $escAttPath, $escAttName, 'open', '$escUserId', '$escUserName', '$escNoWa', 
             '$escCmpId', '$escCmpName', '$escSubdomain', '$now', '$now', 'pending')";

    if ($koneksi->query($sql)) {
        $insertId = $koneksi->insert_id;

        // Auto-update no_wa di master user jika sebelumnya kosong
        if (!empty($noWa) && $userId !== 'GUEST') {
            @$koneksi->query("UPDATE bc_ceisa_login SET no_wa = '$escNoWa' WHERE user_id = '$escUserId' AND (no_wa IS NULL OR no_wa = '')");
        }

        // Kirim Notifikasi WhatsApp ke Pemohon Tiket
        if (!empty($noWa)) {
            $msgUser = "Halo Bapak/Ibu *$userName*,\n\nTiket request Anda telah berhasil didaftarkan di sistem:\n\n"
                     . "🎫 *No Tiket*: #$ticketNo\n"
                     . "📌 *Kategori*: " . strtoupper($category) . "\n"
                     . "📋 *Menu*: $menuName\n"
                     . "📝 *Judul*: $title\n"
                     . "⚡ *Prioritas*: " . strtoupper($priority) . "\n"
                     . "🏢 *Perusahaan*: $sessionCmpName\n\n"
                     . "Tim pengembang & Asisten AI sedang menganalisa tiket Anda. Anda akan menerima notifikasi otomatis saat tiket selesai diproses.\n\nTerima kasih,\n*Tim CEISA 4.0 ERP Hub*";
            sendWaMessage($noWa, $msgUser, $sessionCmpId);
        }

        // Kirim Notifikasi WhatsApp ke Super Admin jika nomor dikonfigurasi
        $adminWa = getAppSetting($koneksi, 'wa_admin_number', '', $sessionCmpId);
        if (!empty($adminWa)) {
            $msgAdmin = "🚨 *TIKET BARU MASUK* 🚨\n\n"
                      . "🎫 *No Tiket*: #$ticketNo\n"
                      . "🏢 *Perusahaan*: $sessionCmpName ($sessionSubdomain)\n"
                      . "👤 *Pemohon*: $userName ($userId)\n"
                      . "📱 *No WA*: " . (!empty($noWa) ? $noWa : '-') . "\n"
                      . "📌 *Kategori*: " . strtoupper($category) . "\n"
                      . "📋 *Menu*: $menuName\n"
                      . "📝 *Judul*: $title\n"
                      . "⚡ *Prioritas*: " . strtoupper($priority) . "\n\n"
                      . "Silakan periksa dan tindak lanjuti pada menu Pusat Tiket.";
            sendWaMessage($adminWa, $msgAdmin, $sessionCmpId);
        }

        echo json_encode([
            'status'    => 'OK',
            'message'   => "Tiket #$ticketNo berhasil dikirimkan ke sistem.",
            'ticket_id' => $insertId,
            'ticket_no' => $ticketNo
        ]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan tiket: ' . $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tambah Komentar / Tindak Lanjut Tiket
// =========================================================================
if ($action === 'add_comment') {
    header('Content-Type: application/json; charset=utf-8');
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $comment  = trim($_POST['comment'] ?? '');

    if ($ticketId <= 0 || empty($comment)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Komentar tidak boleh kosong.']);
        exit;
    }

    $senderType = $isAdmin ? 'admin' : 'user';
    $escComm    = $koneksi->real_escape_string($comment);
    $escSender  = $koneksi->real_escape_string($userName);
    $escSenderId= $koneksi->real_escape_string($userId);
    $now        = date('Y-m-d H:i:s');

    $sql = "INSERT INTO `{$masterDb}`.`bc_system_ticket_comments` 
            (ticket_id, sender_type, sender_id, sender_name, comment, created_at)
            VALUES ($ticketId, '$senderType', '$escSenderId', '$escSender', '$escComm', '$now')";

    if ($koneksi->query($sql)) {
        $koneksi->query("UPDATE `{$masterDb}`.`bc_system_tickets` SET updated_at = '$now' WHERE id = $ticketId");

        // Jika komentar dari admin/support, kirim WA ke pemohon
        if ($senderType === 'admin') {
            $qTk = $koneksi->query("SELECT ticket_no, title, created_by_name, no_wa, cmp_id FROM `{$masterDb}`.`bc_system_tickets` WHERE id = $ticketId LIMIT 1");
            if ($qTk && $tkRow = $qTk->fetch_assoc()) {
                $tkWa = $tkRow['no_wa'];
                if (!empty($tkWa)) {
                    $msgCom = "💬 *TANGGAPAN TIKET #" . $tkRow['ticket_no'] . "* 💬\n\n"
                            . "Halo *" . $tkRow['created_by_name'] . "*,\n\n"
                            . "Ada tanggapan baru dari *$userName* terkait tiket Anda:\n\n"
                            . "\"" . $comment . "\"\n\n"
                            . "Silakan cek detail tiket pada menu Pusat Tiket.";
                    sendWaMessage($tkWa, $msgCom, $tkRow['cmp_id'] ?? $sessionCmpId);
                }
            }
        }

        echo json_encode(['status' => 'OK', 'message' => 'Komentar berhasil ditambahkan.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menambahkan komentar: ' . $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tandai Tiket Selesai (Solve Ticket)
// =========================================================================
if ($action === 'solve_ticket') {
    header('Content-Type: application/json; charset=utf-8');
    $isLocalAi = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
    if (!$isAdmin && !$isLocalAi) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Administrator / Asisten AI yang berhak menandai tiket selesai.']);
        exit;
    }

    $ticketId      = (int)($_POST['ticket_id'] ?? 0);
    $solutionNotes = trim($_POST['solution_notes'] ?? '');
    $solvedBy      = trim($_POST['solved_by'] ?? ($isSuperAdmin ? 'Asisten AI (Adek)' : $userName));

    if ($ticketId <= 0 || empty($solutionNotes)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Catatan penyelesaian masalah wajib diisi.']);
        exit;
    }

    $escNotes    = $koneksi->real_escape_string($solutionNotes);
    $escSolvedBy = $koneksi->real_escape_string($solvedBy);
    $now         = date('Y-m-d H:i:s');

    $sql = "UPDATE `{$masterDb}`.`bc_system_tickets` 
            SET status = 'solved', 
                ai_status = 'resolved',
                solution_notes = '$escNotes', 
                solved_by = '$escSolvedBy', 
                solved_at = '$now', 
                updated_at = '$now',
                notif_read = 0 
            WHERE id = $ticketId";

    if ($koneksi->query($sql)) {
        // Kirim Notifikasi WhatsApp ke Pemohon Tiket Selesai
        $qTk = $koneksi->query("SELECT ticket_no, title, created_by_name, no_wa, cmp_id FROM `{$masterDb}`.`bc_system_tickets` WHERE id = $ticketId LIMIT 1");
        if ($qTk && $tkRow = $qTk->fetch_assoc()) {
            $tkWa = $tkRow['no_wa'];
            if (!empty($tkWa)) {
                $msgSolved = "✅ *TIKET ANDA TELAH DISELESAIKAN* ✅\n\n"
                           . "Halo Bapak/Ibu *" . $tkRow['created_by_name'] . "*,\n\n"
                           . "Tiket Anda telah selesai diproses oleh *$solvedBy*:\n\n"
                           . "🎫 *No Tiket*: #" . $tkRow['ticket_no'] . "\n"
                           . "📝 *Judul*: " . $tkRow['title'] . "\n"
                           . "💡 *Catatan Solusi*:\n" . $solutionNotes . "\n\n"
                           . "Silakan muat ulang (refresh) halaman aplikasi Anda untuk melihat pembaruan.\n\nTerima kasih,\n*Tim CEISA 4.0 ERP Hub*";
                sendWaMessage($tkWa, $msgSolved, $tkRow['cmp_id'] ?? $sessionCmpId);
            }
        }

        // Tambahkan komentar penutup otomatis dari solver
        $autoComm = "[TIKET DISELESAIKAN oleh $solvedBy]\n" . $solutionNotes;
        $escAutoComm = $koneksi->real_escape_string($autoComm);
        $koneksi->query("INSERT INTO `{$masterDb}`.`bc_system_ticket_comments` 
                         (ticket_id, sender_type, sender_id, sender_name, comment, created_at)
                         VALUES ($ticketId, 'ai', '$userId', '$escSolvedBy', '$escAutoComm', '$now')");

        echo json_encode(['status' => 'OK', 'message' => 'Tiket berhasil diselesaikan dan pengguna akan menerima notifikasi otomatis.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyelesaikan tiket: ' . $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tandai Notifikasi Tiket Dibaca
// =========================================================================
if ($action === 'mark_read') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['ticket_id'] ?? 0);
    if ($id > 0) {
        $koneksi->query("UPDATE `{$masterDb}`.`bc_system_tickets` SET notif_read = 1 WHERE id = $id");
    }
    echo json_encode(['status' => 'OK']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pusat Tiket &amp; Request Sistem - CEISA 4.0 &amp; ERP Hub</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>
    
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="/assets/ant_grid.js"></script>

    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --app-font-size: 9.5px;
            --form-font-size: 9.5px;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --danger: #f5222d;
            --warning: #faad14;
            --info: #0ea5e9;
            --bg-body: #f4f6f9;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --border-light: #d9d9d9;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
        }
        
        body.theme-dark {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --text-main: #d9d9d9;
            --text-muted: #8c8c8c;
            --border-light: #434343;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --row-hover: #262638;
            --row-selected: #111d2c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--app-font);
            font-size: var(--app-font-size);
            background: var(--bg-body);
            color: var(--text-main);
            overflow-x: hidden;
            padding: 8px 12px;
        }

        /* 4 Top KPI Cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 8px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .kpi-icon {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .kpi-icon.blue { background: #e6f7ff; color: #1890ff; }
        .kpi-icon.amber { background: #fffbe6; color: #faad14; }
        .kpi-icon.green { background: #f6ffed; color: #52c41a; }
        .kpi-icon.red { background: #fff1f0; color: #f5222d; }
        
        .kpi-data {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .kpi-label {
            font-size: 8.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 500;
        }
        .kpi-val {
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* Card Container */
        .card-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            height: calc(100vh - 86px);
        }
        .card-header-tabs {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
        }
        .tabs-nav {
            display: flex;
            gap: 4px;
        }
        .tab-btn {
            background: transparent;
            border: 1px solid transparent;
            border-bottom: none;
            padding: 4px 12px;
            font-family: inherit;
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 3px 3px 0 0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            font-weight: 600;
            background: var(--bg-card);
            border-color: var(--border-light);
            border-bottom: 2px solid var(--primary);
        }

        /* Toolbar */
        .toolbar {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-table);
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--table-head-bg);
            flex-wrap: wrap;
        }
        .ant-input {
            height: 21px;
            padding: 0 6px;
            font-family: inherit;
            font-size: 9.5px;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .ant-btn {
            height: 21px;
            padding: 0 8px;
            font-family: inherit;
            font-size: 9.5px;
            font-weight: 500;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: #fff;
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff !important;
        }
        .ant-btn-success {
            background: var(--success);
            border-color: var(--success);
            color: #fff !important;
        }

        /* Grid Table */
        .grid-wrapper {
            flex: 1;
            overflow: auto;
            position: relative;
            min-height: 0;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.ant-table th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border: 1px solid var(--border-table);
            padding: 3px 6px;
            text-align: left;
            font-weight: 600;
            color: var(--text-main);
            z-index: 2;
            user-select: none;
        }
        table.ant-table td {
            border: 1px solid var(--border-table);
            padding: 2px 6px;
            height: 22px;
            vertical-align: middle;
            color: var(--text-main);
        }
        table.ant-table tbody tr:hover { background-color: var(--row-hover); }

        /* Badges */
        .badge-cat {
            padding: 1px 6px;
            border-radius: 2px;
            font-size: 8.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .badge-bug { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }
        .badge-improve { background: #fffbe6; color: #d46b08; border: 1px solid #ffd591; }
        .badge-feature { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }

        .badge-status {
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-open { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .status-in_progress { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .status-solved { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .status-closed { background: #f5f5f5; color: #8c8c8c; border: 1px solid #d9d9d9; }

        /* Priority Dot */
        .prio-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; margin-right: 4px; }
        .prio-critical { background: #f5222d; box-shadow: 0 0 4px #f5222d; }
        .prio-high { background: #fa8c16; }
        .prio-medium { background: #1890ff; }
        .prio-low { background: #8c8c8c; }

        /* Form Entry */
        .form-container {
            padding: 14px 20px;
            max-width: 820px;
            margin: 0 auto;
            width: 100%;
            overflow-y: auto;
        }
        .category-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 12px;
        }
        .cat-card {
            border: 1px solid var(--border-light);
            border-radius: 4px;
            padding: 8px 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            background: var(--bg-card);
        }
        .cat-card:hover { border-color: var(--primary); }
        .cat-card.selected {
            border-color: var(--primary);
            background: #e6f7ff;
            color: var(--primary);
            font-weight: 600;
        }
        body.theme-dark .cat-card.selected { background: #111d2c; }

        .fld-row {
            margin-bottom: 10px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .fld-row label {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-main);
        }
        .fld-row select, .fld-row input[type="text"], .fld-row textarea {
            width: 100%;
            padding: 4px 8px;
            font-family: inherit;
            font-size: 9.5px;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .fld-row textarea {
            min-height: 90px;
            resize: vertical;
            line-height: 1.4;
        }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15);
        }

        /* Sidebar Menu Picker Styles */
        #menu-picker-display {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            min-height: 28px;
            padding: 4px 10px;
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            user-select: none;
        }
        #menu-picker-display:hover {
            border-color: var(--primary);
            background: var(--row-hover);
        }
        #menu-picker-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-top: 6px;
            transition: all 0.2s ease;
        }
        .picker-rail-tabs {
            display: flex;
            gap: 4px;
            padding: 5px 8px;
            background: var(--bg-body);
            border-bottom: 1px solid var(--border-table);
            overflow-x: auto;
        }
        .picker-rail-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 3px;
            padding: 3px 8px;
            font-size: 8.5px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
            user-select: none;
        }
        .picker-rail-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--row-hover);
        }
        .picker-rail-btn.active {
            background: var(--primary);
            color: #fff !important;
            border-color: var(--primary);
            box-shadow: 0 1px 3px rgba(24, 144, 255, 0.3);
        }
        .picker-module-banner {
            padding: 4px 10px;
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid var(--border-table);
        }
        .picker-folder-hdr {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            font-size: 9.5px;
            font-weight: 700;
            color: var(--text-main);
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-table);
            cursor: pointer;
            user-select: none;
            transition: background 0.15s ease;
        }
        .picker-folder-hdr:hover {
            background: var(--row-hover);
            color: var(--primary);
        }
        .picker-folder-caret {
            margin-left: auto;
            font-size: 8px;
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }
        .picker-folder-hdr.collapsed .picker-folder-caret {
            transform: rotate(-90deg);
        }
        .picker-folder-items {
            overflow: hidden;
            transition: max-height 0.2s ease;
        }
        .picker-folder-items.collapsed {
            display: none !important;
        }
        .picker-menu-item {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 4px 10px 4px 24px;
            font-size: 9.5px;
            color: var(--text-main);
            cursor: pointer;
            border-bottom: 1px solid rgba(0,0,0,0.03);
            transition: all 0.15s ease;
            user-select: none;
        }
        .picker-menu-item:hover {
            background: var(--row-hover);
            color: var(--primary);
        }
        .picker-menu-item.active {
            background: var(--row-selected) !important;
            color: var(--primary) !important;
            font-weight: 700;
        }
        .picker-menu-item.active .erp-item-bullet {
            color: var(--primary);
            opacity: 1;
            transform: translateX(2px);
        }
        .picker-item-badge {
            margin-left: auto;
            font-size: 7.5px;
            padding: 1px 5px;
            border-radius: 3px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.2px;
        }

        /* Dropzone / Paste Zone */
        .dropzone-box {
            border: 1.5px dashed var(--border-light);
            border-radius: 4px;
            padding: 12px 16px;
            text-align: center;
            background: var(--table-head-bg);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .dropzone-box:hover, .dropzone-box.dragover {
            border-color: var(--primary);
            background: var(--row-hover);
        }
        .attachment-previews {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        .att-item {
            position: relative;
            width: 70px;
            height: 70px;
            border: 1px solid var(--border-light);
            border-radius: 3px;
            overflow: hidden;
            background: var(--bg-card);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .att-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .att-item .remove-btn {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 16px;
            height: 16px;
            background: rgba(0,0,0,0.6);
            color: #fff;
            border: none;
            border-radius: 50%;
            font-size: 9px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .att-item .remove-btn:hover { background: #f5222d; }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }
        .modal-box {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            width: 800px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .modal-head {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-table);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--table-head-bg);
        }
        .modal-title { font-size: 11px; font-weight: 700; color: var(--text-main); }
        .modal-body {
            padding: 12px 16px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-foot {
            padding: 6px 12px;
            border-top: 1px solid var(--border-table);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--table-head-bg);
        }

        /* Discussion / Comment Box */
        .comment-item {
            padding: 6px 10px;
            border-radius: 3px;
            margin-bottom: 6px;
            background: var(--table-head-bg);
            border-left: 3px solid var(--border-light);
        }
        .comment-item.ai {
            background: #f6ffed;
            border-left-color: #52c41a;
        }
        body.theme-dark .comment-item.ai { background: #112a14; }
        .comment-item.admin {
            background: #e6f7ff;
            border-left-color: #1890ff;
        }

        /* SweetAlert Compact Desktop */
        .compact-swal {
            width: 320px !important;
            padding: 12px 14px !important;
            font-size: 9.5px !important;
            border-radius: 4px !important;
        }
        .compact-swal .swal2-title {
            font-size: 13px !important;
            margin-bottom: 6px !important;
            font-weight: 600 !important;
        }
        .compact-swal .swal2-html-container {
            font-size: 9.5px !important;
            margin: 6px 0 !important;
            line-height: 1.4 !important;
        }
        .compact-swal .swal2-actions {
            margin-top: 10px !important;
            gap: 6px !important;
        }
        .compact-swal .swal2-styled {
            font-size: 9.5px !important;
            height: 22px !important;
            padding: 0 12px !important;
            line-height: 20px !important;
            border-radius: 2px !important;
            margin: 0 !important;
        }
        .compact-swal .swal2-icon {
            width: 42px !important;
            height: 42px !important;
            margin: 4px auto 8px auto !important;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 4 Top KPI Cards -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fa-solid fa-ticket"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Tiket Sistem</span>
                    <span class="kpi-val" id="kpi-total">0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fa-solid fa-clock"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Menunggu / Open</span>
                    <span class="kpi-val" id="kpi-open" style="color:var(--warning);">0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Selesai (Solved by AI)</span>
                    <span class="kpi-val" id="kpi-solved" style="color:var(--success);">0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Prioritas Tinggi / Mendesak</span>
                    <span class="kpi-val" id="kpi-urgent" style="color:var(--danger);">0</span>
                </div>
            </div>
        </div>

        <!-- Main Card Panel with Tabs -->
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-browse" onclick="switchTicketTab('browse')">
                        <i class="fa-solid fa-list-check"></i> 1. Daftar Tiket &amp; Request
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-create" onclick="switchTicketTab('create')">
                        <i class="fa-solid fa-circle-plus" style="color:var(--success);"></i> 2. + Buat Tiket Baru
                    </button>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="ant-btn" onclick="loadTicketsList()">
                        <i class="fa-solid fa-rotate"></i> Refresh
                    </button>
                    <?php if (strtolower($userId) === 'dp'): ?>
                    <button type="button" class="ant-btn ant-btn-primary" onclick="openAiChatWithTicket(null)">
                        <i class="fa-solid fa-robot"></i> Buka Asisten AI
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 1: BROWSE DAFTAR TIKET -->
            <div id="panel-browse" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label style="font-weight:600;">Cari:</label>
                    <input type="text" id="fil-search" class="ant-input" placeholder="No. Tiket / Judul / Menu..." style="width:160px;" onkeyup="if(event.key==='Enter') loadTicketsList()">

                    <label style="font-weight:600; margin-left:6px;">Kategori:</label>
                    <select id="fil-category" class="ant-input" onchange="loadTicketsList()">
                        <option value="">-- Semua Kategori --</option>
                        <option value="bug">🐛 Perbaikan Bug</option>
                        <option value="improve">⚡ Modifikasi / Improve</option>
                        <option value="feature">✨ Request Fitur Baru</option>
                    </select>

                    <label style="font-weight:600; margin-left:6px;">Status:</label>
                    <select id="fil-status" class="ant-input" onchange="loadTicketsList()">
                        <option value="">-- Semua Status --</option>
                        <option value="open">Open (Menunggu)</option>
                        <option value="in_progress">In Progress</option>
                        <option value="solved">Solved (Selesai)</option>
                        <option value="closed">Closed</option>
                    </select>

                    <label style="font-weight:600; margin-left:6px;">Prioritas:</label>
                    <select id="fil-priority" class="ant-input" onchange="loadTicketsList()">
                        <option value="">-- Semua --</option>
                        <option value="critical">Critical (Mendesak)</option>
                        <option value="high">High (Tinggi)</option>
                        <option value="medium">Medium (Normal)</option>
                        <option value="low">Low (Rendah)</option>
                    </select>

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadTicketsList()">
                        <i class="fa-solid fa-filter"></i> Filter
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-tickets">
                        <thead>
                            <tr>
                                <th style="width: 105px;">No. Tiket</th>
                                <th style="width: 100px;">Kategori</th>
                                <th style="width: 160px;">Menu / Halaman Terkait</th>
                                <th style="min-width: 220px;">Judul Masalah / Permintaan</th>
                                <th style="width: 150px;">Pemohon &amp; Perusahaan</th>
                                <th style="width: 75px;">Prioritas</th>
                                <th style="width: 85px;">Status</th>
                                <th style="width: 60px; text-align:center;">Lampiran</th>
                                <th style="width: 110px;">Tgl Dibuat</th>
                                <th style="width: 110px;">Tgl Selesai</th>
                                <th style="width: 110px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-tickets">
                            <tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat daftar tiket...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: BUAT TIKET BARU -->
            <div id="panel-create" style="display:none; flex-direction:column; flex:1; min-height:0; overflow-y:auto;">
                <div class="form-container">
                    <form id="formNewTicket" onsubmit="submitNewTicket(event)">
                        <!-- Segmented Category Selection -->
                        <div class="category-selector">
                            <div class="cat-card selected" id="cat-card-bug" onclick="selectCategory('bug')">
                                <span style="font-size:16px;">🐛</span>
                                <div>
                                    <div style="font-weight:600; font-size:10px;">Perbaikan Bug</div>
                                    <div style="font-size:8.5px; color:var(--text-muted);">Ada error, hitungan keliru, tombol macet</div>
                                </div>
                            </div>
                            <div class="cat-card" id="cat-card-improve" onclick="selectCategory('improve')">
                                <span style="font-size:16px;">⚡</span>
                                <div>
                                    <div style="font-weight:600; font-size:10px;">Modifikasi / Improve</div>
                                    <div style="font-size:8.5px; color:var(--text-muted);">Tampilan, penambahan kolom, filter baru</div>
                                </div>
                            </div>
                            <div class="cat-card" id="cat-card-feature" onclick="selectCategory('feature')">
                                <span style="font-size:16px;">✨</span>
                                <div>
                                    <div style="font-weight:600; font-size:10px;">Request Fitur Baru</div>
                                    <div style="font-size:8.5px; color:var(--text-muted);">Permintaan modul / laporan / alur baru</div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="category" id="inp-category" value="bug">

                        <!-- Field Menu Terkait (Untuk Bug & Modifikasi: Format Struktur Sidebar Menu) -->
                        <div class="fld-row" id="group-menu-select">
                            <label style="display:flex; justify-content:space-between; align-items:center;">
                                <span><i class="fa-solid fa-list-check" style="color:var(--primary); margin-right:4px;"></i> Nama Menu / Halaman yang Akan Diedit / Bermasalah: <span style="color:var(--danger)">*</span></span>
                                <span style="font-size:8.5px; color:var(--text-muted); font-weight:normal;">Pilihan modul &amp; menu sama seperti tampilan sidebar</span>
                            </label>

                            <!-- Trigger Box / Penampil Menu Terpilih -->
                            <div id="menu-picker-display" onclick="toggleMenuPicker()">
                                <div id="menu-picker-selected-content">
                                    <span style="color:var(--text-muted); display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fa-solid fa-bars-staggered" style="color:var(--primary);"></i>
                                        <span>Klik di sini untuk memilih menu dari struktur navigasi sidebar...</span>
                                    </span>
                                </div>
                                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                    <span id="btn-change-menu-tag" style="display:none; font-size:8px; background:var(--primary); color:#fff; padding:1px 6px; border-radius:3px; font-weight:600;">Ubah Menu</span>
                                    <i class="fa-solid fa-chevron-down" id="menu-picker-caret" style="color:var(--text-muted); font-size:9.5px; transition:transform 0.2s;"></i>
                                </div>
                            </div>

                            <!-- Hidden inputs untuk form submit -->
                            <input type="hidden" name="menu_target" id="inp-menu-target" required>
                            <input type="hidden" name="menu_name" id="inp-menu-name">
                            <input type="hidden" name="menu_module" id="inp-menu-module">

                            <!-- Sidebar-Style Menu Tree Selector Panel -->
                            <div id="menu-picker-panel" style="display:none;">
                                <!-- Live Search Bar -->
                                <div style="padding:6px 10px; background:var(--table-head-bg); border-bottom:1px solid var(--border-table); display:flex; align-items:center; gap:8px;">
                                    <div style="position:relative; flex:1;">
                                        <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:9.5px;"></i>
                                        <input type="text" id="menu-search-input" placeholder="Ketik cepat cari menu... (misal: PO, Faktur, Coretax, Jurnal, BC 2.7, 1A)" class="ant-input" style="padding-left:24px; width:100%; height:23px; font-size:9.5px;" onkeyup="filterSidebarMenuPicker(this.value)">
                                    </div>
                                    <button type="button" class="ant-btn ant-btn-sm" onclick="expandAllPickerFolders(true)" title="Buka Semua Folder" style="height:23px; font-size:8.5px; padding:0 6px;">
                                        <i class="fa-solid fa-folder-open"></i> Buka Semua
                                    </button>
                                    <button type="button" class="ant-btn ant-btn-sm" onclick="expandAllPickerFolders(false)" title="Tutup Semua Folder" style="height:23px; font-size:8.5px; padding:0 6px;">
                                        <i class="fa-solid fa-folder-closed"></i> Tutup
                                    </button>
                                </div>

                                <!-- Rail Module Buttons (Persis Rail Samping Portal) -->
                                <div class="picker-rail-tabs">
                                    <button type="button" class="picker-rail-btn active" data-rail="all" onclick="selectPickerRail('all')">
                                        <i class="fa-solid fa-globe"></i> SEMUA MODUL
                                    </button>
                                    <?php if ($appMode === 'enterprise'): ?>
                                    <button type="button" class="picker-rail-btn" data-rail="basic" onclick="selectPickerRail('basic')">
                                        <span style="color:#8b5cf6;">&#x1F3E2;</span> BASIC
                                    </button>
                                    <button type="button" class="picker-rail-btn" data-rail="scm" onclick="selectPickerRail('scm')">
                                        <span style="color:#0284c7;">&#x1F517;</span> SCM
                                    </button>
                                    <button type="button" class="picker-rail-btn" data-rail="customs" onclick="selectPickerRail('customs')">
                                        <span style="color:#10b981;">&#x1F6C3;</span> BC / EXIM
                                    </button>
                                    <button type="button" class="picker-rail-btn" data-rail="accounting" onclick="selectPickerRail('accounting')">
                                        <span style="color:#f59e0b;">&#x1F4B0;</span> ACCOUNT
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="picker-rail-btn" data-rail="customs" onclick="selectPickerRail('customs')">
                                        <span style="color:#10b981;">&#x1F6C3;</span> BC / EXIM
                                    </button>
                                    <button type="button" class="picker-rail-btn" data-rail="inventory" onclick="selectPickerRail('inventory')">
                                        <span style="color:#0284c7;">&#x1F4E6;</span> INVENTORY
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="picker-rail-btn" data-rail="settings" onclick="selectPickerRail('settings')">
                                        <span style="color:#64748b;">&#x2699;&#xFE0F;</span> SISTEM
                                    </button>
                                </div>
                                
                                <!-- Scrollable Tree List -->
                                <div id="picker-tree-scroll" style="max-height:280px; overflow-y:auto; padding:4px 0;">
                                    <!-- Rendered dynamically by initSidebarMenuPicker() -->
                                </div>
                            </div>
                        </div>

                        <!-- Field Nama Fitur Baru (Hanya untuk Request Fitur Baru) -->
                        <div class="fld-row" id="group-new-feature" style="display:none;">
                            <label><i class="fa-solid fa-sparkles" style="color:var(--success); margin-right:4px;"></i> Nama Fitur / Modul Baru yang Diinginkan: <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="feature_name" id="inp-feature-name" placeholder="Misal: Laporan Rekapitulasi Pemakaian Bahan per Work Order...">
                        </div>

                        <!-- Judul Ringkas -->
                        <div class="fld-row">
                            <label>Judul Tiket (Ringkasan Isu / Permintaan): <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="title" id="inp-title" placeholder="Contoh: Error Unknown column 'kode' saat klik Proses Neraca Lajur..." required>
                        </div>

                        <!-- Prioritas & No WhatsApp -->
                        <div style="display:flex; gap:12px; align-items:flex-start; margin-bottom:12px;">
                            <div class="fld-row" style="width:180px; margin-bottom:0;">
                                <label>Tingkat Prioritas:</label>
                                <select name="priority" id="inp-priority">
                                    <option value="medium" selected>Normal (Medium)</option>
                                    <option value="low">Rendah (Low)</option>
                                    <option value="high">Tinggi (High)</option>
                                    <option value="critical">Mendesak / Kritis (Critical)</option>
                                </select>
                            </div>
                            <div class="fld-row" style="flex:1; margin-bottom:0;">
                                <label><i class="fa-brands fa-whatsapp" style="color:#25d366; font-size:12px; margin-right:4px;"></i> No. WhatsApp Pemohon (Notifikasi Status Tiket):</label>
                                <input type="text" name="no_wa" id="inp-no-wa" value="<?= htmlspecialchars($userWaNumber) ?>" placeholder="Format: 6281234567890 (awali 62)">
                            </div>
                        </div>

                        <!-- Deskripsi Detail -->
                        <div class="fld-row">
                            <label>Penjelasan Lengkap Kendala / Permintaan Modifikasi: <span style="color:var(--danger)">*</span></label>
                            <textarea name="description" id="inp-description" placeholder="Jelaskan secara terperinci kendala yang dialami, pesan error bila ada, langkah mereproduksi masalah, atau format yang diinginkan..." required></textarea>
                        </div>

                        <!-- Zona Paste Screenshot & Lampiran File -->
                        <div class="fld-row">
                            <label><i class="fa-solid fa-paperclip" style="color:var(--primary); margin-right:4px;"></i> Lampiran Tangkapan Layar (Screenshot) / Dokumen Pendukung:</label>
                            <div class="dropzone-box" id="dropzoneBox" onclick="document.getElementById('fileInput').click()" tabindex="0">
                                <i class="fa-solid fa-cloud-arrow-up" style="font-size:22px; color:var(--primary); margin-bottom:4px;"></i>
                                <div style="font-weight:600; font-size:10px;">
                                    Tekan <kbd style="background:#e6f7ff; color:var(--primary); padding:1px 5px; border-radius:3px; border:1px solid #91d5ff;">Ctrl+V</kbd> di sini untuk Paste Screenshot Clipboard
                                </div>
                                <div style="font-size:8.5px; color:var(--text-muted); margin-top:2px;">
                                    Atau klik untuk memilih berkas dari komputer (PNG, JPG, PDF, XLSX, ZIP)
                                </div>
                                <input type="file" id="fileInput" name="files[]" multiple style="display:none;" onchange="handleFileSelect(this)" accept="image/*,.pdf,.xlsx,.xls,.zip,.txt">
                            </div>

                            <!-- Pratinjau Lampiran -->
                            <div class="attachment-previews" id="previewContainer"></div>
                        </div>

                        <div style="margin-top:16px; display:flex; gap:8px;">
                            <button type="submit" class="ant-btn ant-btn-primary" id="btnSubmitTicket" style="height:26px; padding:0 14px; font-weight:600;">
                                <i class="fa-solid fa-paper-plane"></i> Kirimkan Tiket ke Sistem
                            </button>
                            <button type="button" class="ant-btn" onclick="resetTicketForm()">
                                <i class="fa-solid fa-rotate-left"></i> Reset Form
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DETAIL TIKET -->
    <div class="modal-overlay" id="modalDetailTicket">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-title" id="mdl-ticket-no">Detail Tiket #TKT-0000</div>
                <button type="button" class="ant-btn" style="border:none; padding:0 4px;" onclick="closeModal('modalDetailTicket')">
                    <i class="fa-solid fa-xmark" style="font-size:13px;"></i>
                </button>
            </div>
            <div class="modal-body" id="mdl-ticket-content">
                <!-- Diisi via JS -->
            </div>
            <div class="modal-foot" id="mdl-ticket-actions">
                <button type="button" class="ant-btn" onclick="closeModal('modalDetailTicket')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- MODAL SOLVE TIKET (KHUSUS ADMIN / ASISTEN AI) -->
    <div class="modal-overlay" id="modalSolveTicket">
        <div class="modal-box" style="width:500px;">
            <div class="modal-head">
                <div class="modal-title"><i class="fa-solid fa-circle-check" style="color:var(--success); margin-right:4px;"></i> Tandai Tiket Selesai (Solve Ticket)</div>
                <button type="button" class="ant-btn" style="border:none;" onclick="closeModal('modalSolveTicket')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="solve-ticket-id">
                <div class="fld-row">
                    <label>Catatan Penyelesaian &amp; File yang Diperbaiki: <span style="color:var(--danger)">*</span></label>
                    <textarea id="solve-notes" style="min-height:100px; width:100%; font-family:inherit; font-size:9.5px; border:1px solid var(--border-light); padding:6px;" placeholder="Tuliskan ringkasan perbaikan atau solusi yang telah diterapkan..."></textarea>
                </div>
                <div class="fld-row">
                    <label>Diselesaikan Oleh:</label>
                    <input type="text" id="solve-by" class="ant-input" value="<?= htmlspecialchars($isSuperAdmin ? 'Asisten AI (Adek)' : $userName) ?>">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="ant-btn" onclick="closeModal('modalSolveTicket')">Batal</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="confirmSolveTicket()">
                    <i class="fa-solid fa-check"></i> Simpan &amp; Notifikasi Pengguna
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'browse';
        let clipboardImagesList = [];
        let selectedFilesList = [];
        let activeDetailTicket = null;

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            initSidebarMenuPicker();
            loadTicketsKPI();
            loadTicketsList();

            // Setup Global Paste Listener untuk Menangkap Screenshot Clipboard (Ctrl+V)
            window.addEventListener('paste', handleGlobalPaste);
        });

        function initTheme() {
            const savedTheme = localStorage.getItem('ceisa_theme');
            if (savedTheme === 'dark') document.body.classList.add('theme-dark');
            window.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'THEME_CHANGE') {
                    if (e.data.theme === 'dark') document.body.classList.add('theme-dark');
                    else document.body.classList.remove('theme-dark');
                }
            });
        }

        function switchTicketTab(tab) {
            currentTab = tab;
            document.getElementById('tab-btn-browse').classList.toggle('active', tab === 'browse');
            document.getElementById('tab-btn-create').classList.toggle('active', tab === 'create');
            document.getElementById('panel-browse').style.display = tab === 'browse' ? 'flex' : 'none';
            document.getElementById('panel-create').style.display = tab === 'create' ? 'flex' : 'none';

            if (tab === 'browse') {
                loadTicketsKPI();
                loadTicketsList();
                closeMenuPicker();
            } else if (tab === 'create') {
                if (!document.getElementById('inp-menu-target').value) {
                    openMenuPicker();
                }
            }
        }

        // Pilihan Kategori di Form
        function selectCategory(cat) {
            document.getElementById('inp-category').value = cat;
            document.querySelectorAll('.cat-card').forEach(el => el.classList.remove('selected'));
            document.getElementById('cat-card-' + cat).classList.add('selected');

            const grpMenu = document.getElementById('group-menu-select');
            const grpFeature = document.getElementById('group-new-feature');

            if (cat === 'feature') {
                grpMenu.style.display = 'none';
                grpFeature.style.display = 'flex';
                document.getElementById('inp-menu-target').removeAttribute('required');
                document.getElementById('inp-feature-name').setAttribute('required', 'required');
            } else {
                grpMenu.style.display = 'flex';
                grpFeature.style.display = 'none';
                document.getElementById('inp-menu-target').setAttribute('required', 'required');
                document.getElementById('inp-feature-name').removeAttribute('required');
                if (!document.getElementById('inp-menu-target').value) {
                    openMenuPicker();
                }
            }
        }

        // =========================================================================
        // STRUKTUR SIDEBAR MENU PICKER (IDENTIK DENGAN NAVIGASI SIDEBAR CEISA 4.0 & ERP)
        // =========================================================================
        let currentPickerRail = 'all';

        const SIDEBAR_MENU_STRUCTURE = [

            <?php if ($appMode === 'enterprise'): ?>
            // 1. BASIC
            {
                module: 'basic',
                moduleTitle: 'BASIC INFO & DATA MASTER',
                moduleIcon: '🏢',
                moduleColor: '#8b5cf6',
                folders: [
                    {
                        name: 'Data Master',
                        icon: 'fa-solid fa-boxes-stacked',
                        iconColor: '#0ea5e9',
                        items: [
                            { id: 'inv_material', name: 'Master Data Barang & Material', badge: 'Mitra', badgeColor: '#1890ff' },
                            { id: 'inv_koreksi_klas', name: 'Koreksi Klasifikasi IT Inv (KLAS)', badge: 'KLAS', badgeColor: '#fa8c16' },
                            { id: 'inv_bom', name: 'Master Bill of Material (BOM)', badge: 'BOM', badgeColor: '#10b981' },
                            { id: 'inv_jaminan', name: 'Master Jaminan Kepabeanan', badge: 'JMN', badgeColor: '#722ed1' },
                            { id: 'erp_buyer_supplier', name: 'Buyer & Supplier (Mitra Rekanan)', badge: 'Mitra', badgeColor: '#1890ff' },
                            { id: 'inv_stok_awal', name: 'Set Saldo Awal Tahun', badge: 'Saldo', badgeColor: '#52c41a' }
                        ]
                    },
                    {
                        name: 'Referensi',
                        icon: 'fa-solid fa-book-bookmark',
                        iconColor: '#8b5cf6',
                        items: [
                            { id: 'reff', name: 'Master Data Referensi Kepabeanan', badge: 'REFF', badgeColor: '#8b5cf6' },
                            { id: 'inv_tarif', name: 'Master Tarif BM dari Kode HS', badge: 'HS', badgeColor: '#0ea5e9' }
                        ]
                    },
                    {
                        name: 'Daftar Lain',
                        icon: 'fa-solid fa-rectangle-list',
                        iconColor: '#64748b',
                        items: [
                            { id: 'erp_dept', name: 'Departemen Perusahaan' },
                            { id: 'erp_satuan', name: 'Satuan dan Kemasan' },
                            { id: 'erp_kurs', name: 'Mata Uang & Kurs' },
                            { id: 'erp_term', name: 'Termin Pembayaran' },
                            { id: 'erp_jasakirim', name: 'Jasa Pengiriman / Ekspedisi' },
                            { id: 'erp_grup_mat', name: 'Grup Material' },
                            { id: 'erp_grup_scrap', name: 'Grup Scrap' }
                        ]
                    },
                    {
                        name: 'Rate Pajak & BI',
                        icon: 'fa-solid fa-money-bill-trend-up',
                        iconColor: '#10b981',
                        items: [
                            { id: 'erp_tax_rate', name: 'Rate Pajak (KMK USD & Valas)', badge: 'KMK', badgeColor: '#10b981' },
                            { id: 'erp_bi_rate', name: 'Rate Bank Indonesia (BI)', badge: 'BI', badgeColor: '#0284c7' }
                        ]
                    }
                ]
            },

            // 2. SCM
            {
                module: 'scm',
                moduleTitle: 'SUPPLY CHAIN MANAGEMENT (SCM)',
                moduleIcon: '🔗',
                moduleColor: '#0284c7',
                folders: [
                    {
                        name: 'Purchase',
                        icon: 'fa-solid fa-cart-shopping',
                        iconColor: '#0284c7',
                        items: [
                            { id: 'pur_req', name: 'Purchase Requisition', badge: 'PR', badgeColor: '#f59e0b' },
                            { id: 'pur_po', name: 'Purchase Order', badge: 'PO', badgeColor: '#10b981' },
                            { id: 'pur_grn', name: 'Receive Item (GRN)', badge: 'RI', badgeColor: '#0ea5e9' },
                            { id: 'pur_pi', name: 'Purchase Invoice', badge: 'PI', badgeColor: '#8b5cf6' },
                            { id: 'pur_pr', name: 'Purchase Return', badge: 'PRT', badgeColor: '#ef4444' },
                            { id: 'pur_rekap_ap', name: 'Rekap PO & Hutang', badge: 'AP', badgeColor: '#f59e0b' }
                        ]
                    },
                    {
                        name: 'Sales',
                        icon: 'fa-solid fa-file-invoice-dollar',
                        iconColor: '#10b981',
                        items: [
                            { id: 'sale_so_import', name: 'Import SO dari XLS', badge: 'XLS', badgeColor: '#10b981' },
                            { id: 'sale_so', name: 'Sales Order', badge: 'SO', badgeColor: '#10b981' },
                            { id: 'sale_do', name: 'Delivery Order (Surat Jalan)', badge: 'DO', badgeColor: '#0ea5e9' },
                            { id: 'sale_si', name: 'Sales Invoice', badge: 'SI', badgeColor: '#8b5cf6' },
                            { id: 'sale_sr', name: 'Sales Return', badge: 'SR', badgeColor: '#f59e0b' }
                        ]
                    },
                    {
                        name: 'Warehouse',
                        icon: 'fa-solid fa-warehouse',
                        iconColor: '#f59e0b',
                        items: [
                            { id: 'inv_warehouse_in', name: 'Incoming Warehouse', badge: 'IN', badgeColor: '#0ea5e9' },
                            { id: 'inv_warehouse_delivery', name: 'Delivery Warehouse', badge: 'OUT', badgeColor: '#10b981' },
                            { id: 'inv_transfer_bc27', name: 'Transfer BC 2.7 Internal', badge: 'BC27', badgeColor: '#6366f1' },
                            { id: 'inv_scan27', name: 'Import BC 2.7 IN & Post IT Inv', badge: 'POST', badgeColor: '#059669' }
                        ]
                    },
                    {
                        name: 'Marketing/Engineer',
                        icon: 'fa-solid fa-compass-drafting',
                        iconColor: '#6366f1',
                        items: [
                            { id: 'inv_bom', name: 'Bill of Material (BOM)', badge: 'BOM', badgeColor: '#10b981' },
                            { id: 'scm_wo', name: 'Work Order Produksi', badge: 'WO', badgeColor: '#6366f1' }
                        ]
                    },
                    {
                        name: 'Mutasi & Produksi',
                        icon: 'fa-solid fa-industry',
                        iconColor: '#ec4899',
                        items: [
                            { id: 'scm_trace_bb', name: 'Traceability Bahan Baku' },
                            { id: 'scm_issue_bom', name: 'Issue Produksi (BOM)' },
                            { id: 'scm_issue_fgwip', name: 'Issue Produksi (FG/WIP)' },
                            { id: 'scm_scrap', name: 'Entri Scrap Produksi' },
                            { id: 'inv_stock_transfer', name: 'Mutasi Produksi & Antar Gudang' },
                            { id: 'scm_disposal', name: 'Disposal / Pemusnahan' }
                        ]
                    },
                    {
                        name: 'Stok Opname & Adjust',
                        icon: 'fa-solid fa-clipboard-check',
                        iconColor: '#14b8a6',
                        items: [
                            { id: 'inv_stok_opname', name: 'Stock Opname Fisik' },
                            { id: 'scm_rekap_so', name: 'Rekap Stok Opname' }
                        ]
                    },
                    {
                        name: 'Admin Inventory',
                        icon: 'fa-solid fa-screwdriver-wrench',
                        iconColor: '#f97316',
                        items: [
                            { id: 'inv_unposting_appv', name: 'Approval Revisi / UnPosting' },
                            { id: 'inv_stok_awal', name: 'Set Stok Awal Tahun' },
                            { id: 'inv_adjust_spp', name: 'Adjust SPP' }
                        ]
                    }
                ]
            },

            // 3. BC / EXIM
            {
                module: 'customs',
                moduleTitle: 'BEA CUKAI & IT INVENTORY',
                moduleIcon: '👮',
                moduleColor: '#10b981',
                folders: [
                    {
                        name: 'Dokumen Pabean (CEISA 4.0)',
                        icon: 'fa-solid fa-file-shield',
                        iconColor: '#0ea5e9',
                        items: [
                            { id: 'daftar_dokumen', name: 'Dashboard CEISA 4.0 (Daftar Dokumen)', badge: 'CEISA', badgeColor: '#0284c7' },
                            { id: 'transfer', name: 'Data Host to Host (Posting CEISA)', badge: 'H2H', badgeColor: '#10b981' },
                            { id: 'editor', name: 'Form Editor Dokumen Pabean (BC 4.0/2.3/2.7/dll)', badge: 'EDIT', badgeColor: '#8b5cf6' },
                            { id: 'import', name: 'Import Excel Dokumen Pabean', badge: 'XLS', badgeColor: '#10b981' },
                            { id: 'tracking', name: 'Tracking Status Respon CEISA', badge: 'TRACK', badgeColor: '#f59e0b' },
                            { id: 'dokap', name: 'Upload Dokumen Pelengkap (Dokap)', badge: 'DOKAP', badgeColor: '#6366f1' },
                            { id: 'bc40', name: 'Dokumen BC 4.0 (Pemasukan TLDPP)', badge: 'BC 4.0', badgeColor: '#0ea5e9' },
                            { id: 'bc23', name: 'Dokumen BC 2.3 (Impor ke TPB)', badge: 'BC 2.3', badgeColor: '#0284c7' },
                            { id: 'bc27', name: 'Dokumen BC 2.7 (Transfer Antar TPB)', badge: 'BC 2.7', badgeColor: '#10b981' },
                            { id: 'bc41', name: 'Dokumen BC 4.1 (Pengeluaran TLDPP)', badge: 'BC 4.1', badgeColor: '#f59e0b' },
                            { id: 'bc25', name: 'Dokumen BC 2.5 (Pengeluaran Lokal/DPP)', badge: 'BC 2.5', badgeColor: '#ef4444' },
                            { id: 'bc261', name: 'Dokumen BC 2.6.1 & 2.6.2 (Subkontrak)', badge: 'BC 2.6', badgeColor: '#8b5cf6' },
                            { id: 'bc30', name: 'Dokumen BC 3.0 (Ekspor TPB)', badge: 'BC 3.0', badgeColor: '#059669' },
                            { id: 'bc16', name: 'Dokumen PLB (BC 1.6 / 2.8 / 3.3)', badge: 'PLB', badgeColor: '#722ed1' }
                        ]
                    },
                    {
                        name: 'Manifes Online (BC 1.1)',
                        icon: 'fa-solid fa-ship',
                        iconColor: '#0284c7',
                        items: [
                            { id: 'manifest', name: 'Manifes Kedatangan BC 1.1', badge: 'BC 1.1', badgeColor: '#0284c7' },
                            { id: 'manifest_ceisa', name: 'Manifes CEISA 4.0 Integrasi', badge: 'API', badgeColor: '#10b981' }
                        ]
                    },
                    {
                        name: 'IT Inventory',
                        icon: 'fa-solid fa-chart-column',
                        iconColor: '#3b82f6',
                        items: [
                            { id: 'it_inventory_1a', name: 'Laporan Pemasukan Barang', badge: '1A', badgeColor: '#0ea5e9' },
                            { id: 'it_inventory_1b', name: 'Laporan Pengeluaran Barang', badge: '1B', badgeColor: '#10b981' },
                            { id: 'it_inventory_1c', name: 'Posisi WIP (Barang Dalam Proses)', badge: '1C', badgeColor: '#f59e0b' },
                            { id: 'it_inventory_1d', name: 'Mutasi Barang (Bahan Baku / Jadi / Scrap)', badge: '1D', badgeColor: '#8b5cf6' },
                            { id: 'it_inventory_log', name: 'Log Perubahan Data IT Inventory' },
                            { id: 'kek_integration', name: 'Integrasi KEK (LNSW)', badge: 'KEK', badgeColor: '#722ed1' }
                        ]
                    }
                ]
            },

            // 4. ACCOUNT
            {
                module: 'accounting',
                moduleTitle: 'ACCOUNTING & KEUANGAN',
                moduleIcon: '💰',
                moduleColor: '#f59e0b',
                folders: [
                    {
                        name: 'Basic',
                        icon: 'fa-solid fa-layer-group',
                        iconColor: '#64748b',
                        items: [
                            { id: 'act_akun', name: 'Daftar Akun (COA)', badge: 'COA', badgeColor: '#0284c7' },
                            { id: 'act_kodebantu', name: 'Kode Pembantu (Subsidiary Ledger)', badge: 'SUB', badgeColor: '#8b5cf6' },
                            { id: 'act_konfig', name: 'Konfigurasi Akun Default' },
                            { id: 'act_fa', name: 'Fixed Assets & Depresiasi Asset' }
                        ]
                    },
                    {
                        name: 'Transaksi',
                        icon: 'fa-solid fa-money-check-dollar',
                        iconColor: '#10b981',
                        items: [
                            { id: 'act_pay', name: 'Bayar Hutang (AP)', badge: 'AP', badgeColor: '#ef4444' },
                            { id: 'act_rcv', name: 'Terima Piutang (AR)', badge: 'AR', badgeColor: '#10b981' },
                            { id: 'fin_cash_bank', name: 'Entri Voucher Kas / Bank', badge: 'VOUCHER', badgeColor: '#0ea5e9' }
                        ]
                    },
                    {
                        name: 'Jurnal',
                        icon: 'fa-solid fa-book-journal-whills',
                        iconColor: '#8b5cf6',
                        items: [
                            { id: 'act_jurnal_beli', name: 'Jurnal Pembelian', badge: 'BELI', badgeColor: '#f59e0b' },
                            { id: 'act_jurnal_jual', name: 'Jurnal Penjualan', badge: 'JUAL', badgeColor: '#10b981' },
                            { id: 'act_jurnal_umum', name: 'Jurnal Umum / Memo', badge: 'UMUM', badgeColor: '#8b5cf6' },
                            { id: 'act_jurnal_kas_bank', name: 'Jurnal Umum 2 (Kas / Bank)', badge: 'KAS', badgeColor: '#0ea5e9' },
                            { id: 'act_jurnal_mutasi', name: 'Jurnal Mutasi Stok IT Inv / SCM', badge: 'MUTASI', badgeColor: '#ec4899' }
                        ]
                    },
                    {
                        name: 'Laporan Keuangan',
                        icon: 'fa-solid fa-chart-pie',
                        iconColor: '#0ea5e9',
                        items: [
                            { id: 'fin_ap_ar', name: 'Kartu Hutang & Piutang', badge: 'AP/AR', badgeColor: '#f59e0b' },
                            { id: 'act_lap_keu', name: 'HPP / Laba Rugi / Neraca / Neraca Lajur', badge: 'LAP', badgeColor: '#0ea5e9' },
                            { id: 'act_lap_multi', name: 'Laba Rugi & Neraca Multi Periode', badge: 'MULTI', badgeColor: '#8b5cf6' },
                            { id: 'act_depresiasi', name: 'Laporan Depresiasi Asset' }
                        ]
                    },
                    {
                        name: 'e-Faktur',
                        icon: 'fa-solid fa-file-invoice',
                        iconColor: '#f59e0b',
                        items: [
                            { id: 'fin_faktur', name: 'Generate XML Coretax', badge: 'XML', badgeColor: '#059669' },
                            { id: 'fin_vat_out', name: 'Import VAT Out', badge: 'OUT', badgeColor: '#0284c7' },
                            { id: 'fin_vat_in', name: 'Import VAT In', badge: 'IN', badgeColor: '#f59e0b' },
                            { id: 'fin_extract_pdf', name: 'Extract PDF Faktur', badge: 'PDF', badgeColor: '#ef4444' }
                        ]
                    }
                ]
            },
            <?php else: ?>
            // 1. BC / EXIM (Mode Standar)
            {
                module: 'customs',
                moduleTitle: 'DOKUMEN PABEAN & MANIFES',
                moduleIcon: '&#x1F6C3;',
                moduleColor: '#10b981',
                folders: [
                    {
                        name: 'Dokumen Pabean (CEISA 4.0)',
                        icon: 'fa-solid fa-file-shield',
                        iconColor: '#0ea5e9',
                        items: [
                            { id: 'daftar_dokumen', name: 'Dashboard CEISA 4.0 (Daftar Dokumen)', badge: 'CEISA', badgeColor: '#0284c7' },
                            { id: 'transfer', name: 'Data Host to Host (Posting CEISA)', badge: 'H2H', badgeColor: '#10b981' },
                            { id: 'editor', name: 'Form Editor Dokumen Pabean (BC 4.0/2.3/2.7/dll)', badge: 'EDIT', badgeColor: '#8b5cf6' },
                            { id: 'import', name: 'Import Excel Dokumen Pabean', badge: 'XLS', badgeColor: '#10b981' },
                            { id: 'tracking', name: 'Tracking Status Respon CEISA', badge: 'TRACK', badgeColor: '#f59e0b' },
                            { id: 'dokap', name: 'Upload Dokumen Pelengkap (Dokap)', badge: 'DOKAP', badgeColor: '#6366f1' },
                            { id: 'bc40', name: 'Dokumen BC 4.0 (Pemasukan TLDPP)', badge: 'BC 4.0', badgeColor: '#0ea5e9' },
                            { id: 'bc23', name: 'Dokumen BC 2.3 (Impor ke TPB)', badge: 'BC 2.3', badgeColor: '#0284c7' },
                            { id: 'bc27', name: 'Dokumen BC 2.7 (Transfer Antar TPB)', badge: 'BC 2.7', badgeColor: '#10b981' },
                            { id: 'bc41', name: 'Dokumen BC 4.1 (Pengeluaran TLDPP)', badge: 'BC 4.1', badgeColor: '#f59e0b' },
                            { id: 'bc25', name: 'Dokumen BC 2.5 (Pengeluaran Lokal/DPP)', badge: 'BC 2.5', badgeColor: '#ef4444' },
                            { id: 'bc261', name: 'Dokumen BC 2.6.1 & 2.6.2 (Subkontrak)', badge: 'BC 2.6', badgeColor: '#8b5cf6' },
                            { id: 'bc30', name: 'Dokumen BC 3.0 (Ekspor TPB)', badge: 'BC 3.0', badgeColor: '#059669' },
                            { id: 'bc16', name: 'Dokumen PLB (BC 1.6 / 2.8 / 3.3)', badge: 'PLB', badgeColor: '#722ed1' }
                        ]
                    },
                    {
                        name: 'Manifes Online (BC 1.1)',
                        icon: 'fa-solid fa-ship',
                        iconColor: '#0284c7',
                        items: [
                            { id: 'manifest', name: 'Manifes Kedatangan BC 1.1', badge: 'BC 1.1', badgeColor: '#0284c7' },
                            { id: 'manifest_ceisa', name: 'Manifes CEISA 4.0 Integrasi', badge: 'API', badgeColor: '#10b981' }
                        ]
                    }
                ]
            },

            // 2. IT INVENTORY & REFERENSI (Mode Standar)
            {
                module: 'inventory',
                moduleTitle: 'IT INVENTORY & REFERENSI',
                moduleIcon: '&#x1F4E6;',
                moduleColor: '#0284c7',
                folders: [
                    {
                        name: 'Laporan IT Inventory',
                        icon: 'fa-solid fa-chart-column',
                        iconColor: '#3b82f6',
                        items: [
                            { id: 'it_inventory_1a', name: 'Laporan Pemasukan Barang', badge: '1A', badgeColor: '#0ea5e9' },
                            { id: 'it_inventory_1b', name: 'Laporan Pengeluaran Barang', badge: '1B', badgeColor: '#10b981' },
                            { id: 'it_inventory_1c', name: 'Posisi WIP (Barang Dalam Proses)', badge: '1C', badgeColor: '#f59e0b' },
                            { id: 'it_inventory_1d', name: 'Mutasi Barang (Bahan Baku / Jadi / Scrap)', badge: '1D', badgeColor: '#8b5cf6' },
                            { id: 'it_inventory_log', name: 'Log Perubahan Data IT Inventory' },
                            { id: 'kek_integration', name: 'Integrasi KEK (LNSW)', badge: 'KEK', badgeColor: '#722ed1' }
                        ]
                    },
                    {
                        name: 'Master Data & Referensi',
                        icon: 'fa-solid fa-book-bookmark',
                        iconColor: '#8b5cf6',
                        items: [
                            { id: 'reff', name: 'Master Data Referensi Kepabeanan', badge: 'REFF', badgeColor: '#8b5cf6' },
                            { id: 'inv_tarif', name: 'Master Tarif BM dari Kode HS', badge: 'HS', badgeColor: '#0ea5e9' }
                        ]
                    }
                ]
            },
            <?php endif; ?>
            // 5. SISTEM
            {
                module: 'settings',
                moduleTitle: 'PENGATURAN & SISTEM',
                moduleIcon: '⚙️',
                moduleColor: '#475569',
                folders: [
                    {
                        name: 'User Management',
                        icon: 'fa-solid fa-users-gear',
                        iconColor: '#0ea5e9',
                        items: [
                            { id: 'user_management', name: 'Kelola User Perusahaan' },
                            { id: 'app_settings', name: 'Setting Aplikasi & Tampilan' },
                            { id: 'system_tickets', name: 'Pusat Tiket & Request Sistem', badge: 'TIKET', badgeColor: '#0ea5e9' }
                        ]
                    },
                    <?php if (strtolower($userId) === 'dp'): ?>
                    {
                        name: 'Administrator',
                        icon: 'fa-solid fa-shield-halved',
                        iconColor: '#ef4444',
                        items: [
                            { id: 'company', name: 'Kelola Profil Perusahaan' },
                            { id: 'docs', name: 'Dokumentasi REST API' },
                            { id: 'db', name: 'Database Browser' },
                            { id: 'version_log', name: 'Log Update Versi Sistem' },
                            { id: 'server_monitor', name: 'Cek Kondisi Server' },
                            { id: 'ai_developer', name: 'Asisten AI' }
                        ]
                    },
                    <?php endif; ?>
                    {
                        name: 'Umum & Global',
                        icon: 'fa-solid fa-globe',
                        iconColor: '#64748b',
                        items: [
                            { id: 'global', name: 'Lainnya / Global Sistem' }
                        ]
                    }
                ]
            }
        ];

        function initSidebarMenuPicker() {
            renderMenuPickerTree('all', '');
        }

        function renderMenuPickerTree(railFilter = 'all', searchQuery = '') {
            const container = document.getElementById('picker-tree-scroll');
            if (!container) return;

            const q = searchQuery.toLowerCase().trim();
            const currentSelected = document.getElementById('inp-menu-target').value;
            let html = '';

            SIDEBAR_MENU_STRUCTURE.forEach(m => {
                if (railFilter !== 'all' && m.module !== railFilter) return;

                let moduleHtml = '';
                let moduleHasMatch = false;

                m.folders.forEach(f => {
                    let folderItemsHtml = '';
                    let folderHasMatch = false;

                    f.items.forEach(item => {
                        const matchSearch = !q || 
                            item.name.toLowerCase().includes(q) || 
                            item.id.toLowerCase().includes(q) || 
                            (item.badge && item.badge.toLowerCase().includes(q)) ||
                            f.name.toLowerCase().includes(q) ||
                            m.moduleTitle.toLowerCase().includes(q);

                        if (matchSearch) {
                            folderHasMatch = true;
                            moduleHasMatch = true;

                            const isActive = (item.id === currentSelected);
                            const badgeHtml = item.badge 
                                ? `<span class="picker-item-badge" style="background:${item.badgeColor || '#0ea5e9'};">${escapeHtml(item.badge)}</span>` 
                                : '';

                            folderItemsHtml += `
                            <div class="picker-menu-item ${isActive ? 'active' : ''}" data-target="${escapeHtml(item.id)}" onclick="selectMenuItem('${escapeHtml(item.id)}', '${escapeHtml(item.name).replace(/'/g, "\\'")}', '${escapeHtml(m.moduleTitle)}', '${escapeHtml(f.name).replace(/'/g, "\\'")}')">
                                <span class="erp-item-bullet" style="color:${isActive ? 'var(--primary)' : 'var(--text-muted)'};">▶</span>
                                <span style="font-size:9.5px;">${escapeHtml(item.name)}</span>
                                ${badgeHtml}
                                ${isActive ? '<i class="fa-solid fa-check" style="margin-left:6px; color:var(--primary); font-size:9px;"></i>' : ''}
                            </div>`;
                        }
                    });

                    if (folderHasMatch || (!q && f.items.length > 0)) {
                        moduleHtml += `
                        <div class="picker-folder-hdr" onclick="togglePickerFolder(this)">
                            <i class="${f.icon}" style="color:${f.iconColor}; font-size:11px; width:14px; text-align:center;"></i>
                            <span>${escapeHtml(f.name)}</span>
                            <span class="picker-folder-caret">▼</span>
                        </div>
                        <div class="picker-folder-items">
                            ${folderItemsHtml}
                        </div>`;
                    }
                });

                if (moduleHasMatch || (!q && moduleHtml)) {
                    html += `
                    <div class="picker-module-block" data-module="${m.module}" style="margin-bottom:6px;">
                        <div class="picker-module-banner" style="background:rgba(0,0,0,0.02); color:${m.moduleColor};">
                            <span>${m.moduleIcon}</span>
                            <span>MODUL ${escapeHtml(m.moduleTitle)}</span>
                        </div>
                        ${moduleHtml}
                    </div>`;
                }
            });

            if (!html) {
                html = `<div style="text-align:center; padding:25px 15px; color:var(--text-muted); font-size:9.5px;">
                    <i class="fa-solid fa-magnifying-glass" style="font-size:18px; margin-bottom:6px; display:block; opacity:0.5;"></i>
                    Tidak ada menu yang sesuai dengan pencarian "<strong>${escapeHtml(searchQuery)}</strong>".
                </div>`;
            }

            container.innerHTML = html;
        }

        function toggleMenuPicker() {
            const panel = document.getElementById('menu-picker-panel');
            const caret = document.getElementById('menu-picker-caret');
            if (panel.style.display === 'none' || !panel.style.display) {
                panel.style.display = 'block';
                if (caret) caret.style.transform = 'rotate(180deg)';
                const searchInp = document.getElementById('menu-search-input');
                if (searchInp) {
                    searchInp.focus();
                }
            } else {
                panel.style.display = 'none';
                if (caret) caret.style.transform = 'rotate(0deg)';
            }
        }

        function openMenuPicker() {
            const panel = document.getElementById('menu-picker-panel');
            const caret = document.getElementById('menu-picker-caret');
            if (panel) panel.style.display = 'block';
            if (caret) caret.style.transform = 'rotate(180deg)';
        }

        function closeMenuPicker() {
            const panel = document.getElementById('menu-picker-panel');
            const caret = document.getElementById('menu-picker-caret');
            if (panel) panel.style.display = 'none';
            if (caret) caret.style.transform = 'rotate(0deg)';
        }

        function selectPickerRail(rail) {
            currentPickerRail = rail;
            document.querySelectorAll('.picker-rail-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-rail') === rail);
            });
            const q = document.getElementById('menu-search-input') ? document.getElementById('menu-search-input').value : '';
            renderMenuPickerTree(rail, q);
        }

        function filterSidebarMenuPicker(val) {
            renderMenuPickerTree(currentPickerRail, val);
        }

        function togglePickerFolder(hdrEl) {
            hdrEl.classList.toggle('collapsed');
            const items = hdrEl.nextElementSibling;
            if (items && items.classList.contains('picker-folder-items')) {
                items.classList.toggle('collapsed');
            }
        }

        function expandAllPickerFolders(expand) {
            document.querySelectorAll('.picker-folder-hdr').forEach(hdr => {
                hdr.classList.toggle('collapsed', !expand);
            });
            document.querySelectorAll('.picker-folder-items').forEach(items => {
                items.classList.toggle('collapsed', !expand);
            });
        }

        function selectMenuItem(targetId, menuName, moduleName, folderName) {
            document.getElementById('inp-menu-target').value = targetId;
            document.getElementById('inp-menu-name').value = menuName;
            document.getElementById('inp-menu-module').value = moduleName;

            // Highlight active item
            document.querySelectorAll('.picker-menu-item').forEach(el => el.classList.remove('active'));
            const clicked = document.querySelector(`.picker-menu-item[data-target="${targetId}"]`);
            if (clicked) clicked.classList.add('active');

            // Resolusi warna modul
            let modColor = '#0284c7';
            if (moduleName.includes('BASIC')) modColor = '#8b5cf6';
            else if (moduleName.includes('SCM')) modColor = '#0284c7';
            else if (moduleName.includes('BEA CUKAI') || moduleName.includes('BC')) modColor = '#10b981';
            else if (moduleName.includes('ACCOUNT')) modColor = '#f59e0b';
            else if (moduleName.includes('SISTEM')) modColor = '#475569';

            document.getElementById('menu-picker-selected-content').innerHTML = `
                <span style="background:${modColor}; color:#fff; font-size:8px; font-weight:700; padding:1px 5px; border-radius:3px;">${escapeHtml(moduleName.replace('MODUL ', ''))}</span>
                <span style="color:var(--text-muted); font-size:9px;">${escapeHtml(folderName)} &gt;</span>
                <strong style="color:var(--text-main); font-size:10px;">${escapeHtml(menuName)}</strong>
                <i class="fa-solid fa-circle-check" style="color:#10b981; margin-left:4px;"></i>
            `;
            document.getElementById('btn-change-menu-tag').style.display = 'inline-block';

            closeMenuPicker();

            // Auto-fill template title jika masih kosong
            const titleInput = document.getElementById('inp-title');
            if (!titleInput.value.trim()) {
                const cat = document.getElementById('inp-category').value;
                if (cat === 'bug') {
                    titleInput.value = `Kendala pada menu ${menuName}: `;
                } else if (cat === 'improve') {
                    titleInput.value = `Permintaan modifikasi / improve menu ${menuName}: `;
                }
                titleInput.focus();
            }
        }

        function resetMenuPicker() {
            document.getElementById('inp-menu-target').value = '';
            document.getElementById('inp-menu-name').value = '';
            document.getElementById('inp-menu-module').value = '';
            document.getElementById('menu-picker-selected-content').innerHTML = `
                <span style="color:var(--text-muted); display:inline-flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-bars-staggered" style="color:var(--primary);"></i>
                    <span>Klik di sini untuk memilih menu dari struktur navigasi sidebar...</span>
                </span>
            `;
            document.getElementById('btn-change-menu-tag').style.display = 'none';
            document.querySelectorAll('.picker-menu-item').forEach(el => el.classList.remove('active'));
        }

        function syncMenuName(selectEl) {
            const selectedOpt = selectEl.options[selectEl.selectedIndex];
            document.getElementById('inp-menu-name').value = selectedOpt ? selectedOpt.text : '';
        }

        // =========================================================================
        // CLIPBOARD PASTE (Ctrl+V) & FILE UPLOAD ENGINE
        // =========================================================================
        function handleGlobalPaste(e) {
            // Hanya tangkap paste bila sedang di Tab Create atau Dropzone aktif
            if (currentTab !== 'create') return;

            const items = (e.clipboardData || e.originalEvent.clipboardData).items;
            let foundImage = false;

            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    const blob = items[i].getAsFile();
                    const reader = new FileReader();
                    reader.onload = function(evt) {
                        const b64 = evt.target.result;
                        clipboardImagesList.push(b64);
                        renderAttachmentPreviews();
                        showToast('Screenshot clipboard berhasil ditempel (Ctrl+V)!', 'success');
                    };
                    reader.readAsDataURL(blob);
                    foundImage = true;
                }
            }

            if (foundImage) {
                e.preventDefault();
            }
        }

        function handleFileSelect(input) {
            if (input.files && input.files.length > 0) {
                for (let i = 0; i < input.files.length; i++) {
                    const file = input.files[i];
                    selectedFilesList.push(file);

                    // Jika gambar, buat preview
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(evt) {
                            file._previewUrl = evt.target.result;
                            renderAttachmentPreviews();
                        };
                        reader.readAsDataURL(file);
                    } else {
                        renderAttachmentPreviews();
                    }
                }
            }
        }

        function renderAttachmentPreviews() {
            const container = document.getElementById('previewContainer');
            let html = '';

            // 1. Gambar dari Clipboard
            clipboardImagesList.forEach((b64, idx) => {
                html += `
                <div class="att-item" title="Screenshot Clipboard #${idx + 1}">
                    <img src="${b64}">
                    <button type="button" class="remove-btn" onclick="removeClipboardImage(${idx})">×</button>
                </div>`;
            });

            // 2. Berkas dari File Input
            selectedFilesList.forEach((f, idx) => {
                if (f.type.startsWith('image/') && f._previewUrl) {
                    html += `
                    <div class="att-item" title="${escapeHtml(f.name)}">
                        <img src="${f._previewUrl}">
                        <button type="button" class="remove-btn" onclick="removeSelectedFile(${idx})">×</button>
                    </div>`;
                } else {
                    html += `
                    <div class="att-item" title="${escapeHtml(f.name)}" style="background:#fafafa; text-align:center; padding:4px;">
                        <i class="fa-solid fa-file" style="font-size:20px; color:var(--primary);"></i>
                        <div style="font-size:7.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:60px;">${escapeHtml(f.name)}</div>
                        <button type="button" class="remove-btn" onclick="removeSelectedFile(${idx})">×</button>
                    </div>`;
                }
            });

            container.innerHTML = html;
        }

        function removeClipboardImage(idx) {
            clipboardImagesList.splice(idx, 1);
            renderAttachmentPreviews();
        }

        function removeSelectedFile(idx) {
            selectedFilesList.splice(idx, 1);
            renderAttachmentPreviews();
        }

        // =========================================================================
        // AJAX API CALLS: Load KPI & List
        // =========================================================================
        async function loadTicketsKPI() {
            try {
                const res = await fetch('ticket.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-total').textContent = json.kpi.total_tickets || 0;
                    document.getElementById('kpi-open').textContent = json.kpi.open_tickets || 0;
                    document.getElementById('kpi-solved').textContent = json.kpi.solved_tickets || 0;
                    document.getElementById('kpi-urgent').textContent = json.kpi.urgent_tickets || 0;
                }
            } catch(e) {}
        }

        async function loadTicketsList() {
            const s = document.getElementById('fil-search').value;
            const c = document.getElementById('fil-category').value;
            const st = document.getElementById('fil-status').value;
            const p = document.getElementById('fil-priority').value;

            const tbody = document.getElementById('tbody-tickets');
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data tiket...</td></tr>';

            try {
                const url = `ticket.php?action=list&search=${encodeURIComponent(s)}&category=${encodeURIComponent(c)}&status=${encodeURIComponent(st)}&priority=${encodeURIComponent(p)}`;
                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK') {
                    renderTicketsTable(json.data || []);
                } else {
                    tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">${escapeHtml(json.message || 'Gagal memuat tiket')}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--danger);">${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function renderTicketsTable(data) {
            const tbody = document.getElementById('tbody-tickets');
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:25px; color:var(--text-muted);"><i class="fa-solid fa-inbox" style="font-size:24px; margin-bottom:6px; display:block;"></i>Tidak ada tiket yang sesuai dengan filter.</td></tr>';
                return;
            }

            let html = '';
            data.forEach(t => {
                let catBadge = '';
                if (t.category === 'bug') catBadge = '<span class="badge-cat badge-bug"><i class="fa-solid fa-bug"></i> Bug</span>';
                else if (t.category === 'improve') catBadge = '<span class="badge-cat badge-improve"><i class="fa-solid fa-bolt"></i> Improve</span>';
                else catBadge = '<span class="badge-cat badge-feature"><i class="fa-solid fa-sparkles"></i> Fitur Baru</span>';

                let statusBadge = '';
                if (t.status === 'open') statusBadge = '<span class="badge-status status-open">Open</span>';
                else if (t.status === 'in_progress') statusBadge = '<span class="badge-status status-in_progress">In Progress</span>';
                else if (t.status === 'solved') statusBadge = '<span class="badge-status status-solved"><i class="fa-solid fa-circle-check"></i> Solved</span>';
                else statusBadge = `<span class="badge-status status-closed">${escapeHtml(t.status)}</span>`;

                let prioHtml = '';
                if (t.priority === 'critical') prioHtml = '<span class="prio-dot prio-critical"></span><span style="color:var(--danger); font-weight:700;">Critical</span>';
                else if (t.priority === 'high') prioHtml = '<span class="prio-dot prio-high"></span><span style="color:#fa8c16; font-weight:600;">High</span>';
                else if (t.priority === 'low') prioHtml = '<span class="prio-dot prio-low"></span><span style="color:var(--text-muted);">Low</span>';
                else prioHtml = '<span class="prio-dot prio-medium"></span><span>Medium</span>';

                const attIcon = t.has_attachment == 1 
                    ? '<i class="fa-solid fa-paperclip" style="color:var(--primary);" title="Ada lampiran / screenshot"></i>' 
                    : '<span style="color:var(--text-muted);">-</span>';

                html += `
                <tr ondblclick="openTicketDetail(${t.id})" style="cursor:pointer;">
                    <td><strong style="color:var(--primary);">${escapeHtml(t.ticket_no)}</strong></td>
                    <td>${catBadge}</td>
                    <td>${escapeHtml(t.menu_name || t.menu_target || '-')}</td>
                    <td><strong style="color:var(--text-main);">${escapeHtml(t.title)}</strong></td>
                    <td>
                        <div style="font-weight:600; color:var(--text-main);">${escapeHtml(t.created_by_name || t.created_by_id || '-')} <small style="color:var(--text-muted); font-weight:normal;">(${escapeHtml(t.created_by_id || '')})</small></div>
                        <div style="font-size:8.5px; color:var(--text-muted); margin-top:1px;">
                            ${t.subdomain ? `<span style="color:#0284c7; font-weight:600;">${escapeHtml(t.subdomain)}.inhost.id</span> &bull; ` : ''}<span>${escapeHtml(t.cmp_name || t.cmp_id)}</span>
                        </div>
                        ${t.no_wa ? `<div style="margin-top:2px;"><a href="https://wa.me/${encodeURIComponent(t.no_wa)}" target="_blank" class="ant-tag ant-tag-success" style="font-size:8px; padding:0 4px; text-decoration:none; display:inline-flex; align-items:center; gap:3px; height:18px;" title="Kirim Pesan WhatsApp Langsung"><i class="fa-brands fa-whatsapp" style="color:#25d366; font-size:10px;"></i> ${escapeHtml(t.no_wa)}</a></div>` : ''}
                    </td>
                    <td>${prioHtml}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align:center;">${attIcon}</td>
                    <td>${escapeHtml(t.created_at || '-')}</td>
                    <td>${escapeHtml(t.solved_at || '-')}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="openTicketDetail(${t.id})" title="Lihat Rincian &amp; Diskusi">
                            <i class="fa-solid fa-eye" style="color:var(--primary);"></i>
                        </button>
                        <?php if ($isAdmin): ?>
                        ${t.status !== 'solved' ? `
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="openSolveModal(${t.id}, '${escapeHtml(t.ticket_no)}')" title="Tandai Selesai">
                            <i class="fa-solid fa-check"></i>
                        </button>` : ''}
                        <?php endif; ?>
                    </td>
                </tr>`;
            });

            tbody.innerHTML = html;
        }

        // =========================================================================
        // SUBMIT TIKET BARU
        // =========================================================================
        async function submitNewTicket(e) {
            e.preventDefault();

            const btn = document.getElementById('btnSubmitTicket');
            const cat = document.getElementById('inp-category').value;
            const title = document.getElementById('inp-title').value.trim();
            const desc = document.getElementById('inp-description').value.trim();

            if (!title || !desc) {
                Swal.fire({ icon: 'warning', title: 'Data Belum Lengkap', text: 'Judul dan penjelasan masalah wajib diisi.', customClass: { popup: 'compact-swal' } });
                return;
            }

            if (cat === 'feature') {
                const featName = document.getElementById('inp-feature-name').value.trim();
                if (!featName) {
                    Swal.fire({ icon: 'warning', title: 'Data Belum Lengkap', text: 'Nama fitur baru wajib diisi.', customClass: { popup: 'compact-swal' } });
                    return;
                }
                document.getElementById('inp-menu-name').value = featName;
                document.getElementById('inp-menu-target').value = 'new_feature';
            } else {
                const target = document.getElementById('inp-menu-target').value;
                if (!target) {
                    Swal.fire({ icon: 'warning', title: 'Pilih Menu', text: 'Silakan pilih menu / halaman yang bermasalah dari navigasi sidebar.', customClass: { popup: 'compact-swal' } });
                    openMenuPicker();
                    return;
                }
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Mengirimkan Tiket...';

            const fd = new FormData(document.getElementById('formNewTicket'));
            fd.append('action', 'create');

            // Lampirkan clipboard images (JSON)
            if (clipboardImagesList.length > 0) {
                fd.append('clipboard_images', JSON.stringify(clipboardImagesList));
            }

            // Lampirkan file upload reguler yang dipilih
            for (let i = 0; i < selectedFilesList.length; i++) {
                fd.append('files[]', selectedFilesList[i]);
            }

            try {
                const res = await fetch('ticket.php', { method: 'POST', body: fd });
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Kirimkan Tiket ke Sistem';

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Tiket Berhasil Terkirim!',
                        html: `Nomor Tiket Anda: <strong>${escapeHtml(json.ticket_no)}</strong><br><small style="color:var(--text-muted)">Tiket telah diteruskan ke antrian Asisten AI untuk dianalisis dan diselesaikan.</small>`,
                        customClass: { popup: 'compact-swal' }
                    });
                    resetTicketForm();
                    switchTicketTab('browse');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Mengirim Tiket',
                        text: json.message || 'Terjadi kesalahan sistem.',
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Kirimkan Tiket ke Sistem';
                Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: err.message, customClass: { popup: 'compact-swal' } });
            }
        }

        function resetTicketForm() {
            document.getElementById('formNewTicket').reset();
            clipboardImagesList = [];
            selectedFilesList = [];
            document.getElementById('previewContainer').innerHTML = '';
            resetMenuPicker();
            selectCategory('bug');
        }

        // =========================================================================
        // DETAIL MODAL & DISKUSI
        // =========================================================================
        async function openTicketDetail(id) {
            const modal = document.getElementById('modalDetailTicket');
            const content = document.getElementById('mdl-ticket-content');
            const actions = document.getElementById('mdl-ticket-actions');
            modal.style.display = 'flex';
            content.innerHTML = '<div style="text-align:center; padding:30px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat detail tiket...</div>';

            try {
                const res = await fetch(`ticket.php?action=detail&id=${id}`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    content.innerHTML = `<div style="color:var(--danger);">${escapeHtml(json.message)}</div>`;
                    return;
                }

                const t = json.ticket;
                activeDetailTicket = t;
                document.getElementById('mdl-ticket-no').textContent = `Detail Tiket #${t.ticket_no} - ${t.title}`;

                // Parsir lampiran jika ada
                let attHtml = '';
                if (t.attachment_path) {
                    try {
                        const paths = JSON.parse(t.attachment_path);
                        const names = t.attachment_name ? JSON.parse(t.attachment_name) : [];
                        if (Array.isArray(paths) && paths.length > 0) {
                            attHtml += '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:6px;">';
                            paths.forEach((p, idx) => {
                                const n = names[idx] || `Lampiran ${idx + 1}`;
                                if (p.match(/\.(png|jpg|jpeg|gif)$/i)) {
                                    attHtml += `
                                    <a href="${p}" target="_blank" title="Klik untuk memperbesar gambar">
                                        <img src="${p}" style="width:90px; height:90px; object-fit:cover; border-radius:3px; border:1px solid var(--border-light);">
                                    </a>`;
                                } else {
                                    attHtml += `
                                    <a href="${p}" target="_blank" class="ant-btn" style="text-decoration:none;">
                                        <i class="fa-solid fa-file-arrow-down" style="color:var(--primary);"></i> ${escapeHtml(n)}
                                    </a>`;
                                }
                            });
                            attHtml += '</div>';
                        }
                    } catch(e) {}
                }

                // Bagian Solusi AI jika sudah selesai
                let solutionHtml = '';
                if (t.status === 'solved') {
                    solutionHtml = `
                    <div style="background:#f6ffed; border:1px solid #b7eb8f; border-radius:4px; padding:10px 14px; margin:12px 0;">
                        <div style="font-weight:700; color:#389e0d; font-size:10.5px; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                            <img src="/assets/asisten_adek.jpg" alt="Adek" style="width:20px; height:20px; border-radius:50%; object-fit:cover;">
                            <span>SOLUSI DARI ASISTEN AI (${escapeHtml(t.solved_by || 'Adek')})</span>
                            <span style="font-size:8.5px; font-weight:normal; color:#8c8c8c; margin-left:auto;">${escapeHtml(t.solved_at || '')}</span>
                        </div>
                        <div style="font-size:9.5px; line-height:1.45; white-space:pre-wrap; color:var(--text-main);">${escapeHtml(t.solution_notes || '')}</div>
                    </div>`;
                }

                // Komentar / Diskusi
                let commentsHtml = '';
                if (json.comments && json.comments.length > 0) {
                    json.comments.forEach(c => {
                        const isAi = c.sender_type === 'ai';
                        const isAdmin = c.sender_type === 'admin';
                        const cls = isAi ? 'comment-item ai' : (isAdmin ? 'comment-item admin' : 'comment-item');
                        const icon = isAi ? '<i class="fa-solid fa-robot" style="color:#52c41a;"></i>' : '<i class="fa-solid fa-user"></i>';

                        commentsHtml += `
                        <div class="${cls}">
                            <div style="display:flex; justify-content:space-between; font-weight:600; font-size:9px; margin-bottom:2px;">
                                <span>${icon} ${escapeHtml(c.sender_name)}</span>
                                <span style="font-size:8px; color:var(--text-muted); font-weight:normal;">${escapeHtml(c.created_at)}</span>
                            </div>
                            <div style="white-space:pre-wrap; font-size:9.5px;">${escapeHtml(c.comment)}</div>
                        </div>`;
                    });
                } else {
                    commentsHtml = '<div style="color:var(--text-muted); font-style:italic; font-size:9px;">Belum ada catatan diskusi tambahan.</div>';
                }

                content.innerHTML = `
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:12px; margin-bottom:12px; border-bottom:1px solid var(--border-table); padding-bottom:10px;">
                    <div>
                        <div style="font-size:12px; font-weight:700; color:var(--text-main); margin-bottom:4px;">${escapeHtml(t.title)}</div>
                        <div style="font-size:9px; color:var(--text-muted);">
                            Menu: <strong>${escapeHtml(t.menu_name || t.menu_target || '-')}</strong> | 
                            Kategori: <strong>${escapeHtml(t.category.toUpperCase())}</strong>
                        </div>
                    </div>
                    <div style="text-align:right; font-size:9px;">
                        <div>Pemohon: <strong>${escapeHtml(t.created_by_name || t.created_by_id)}</strong> <span style="color:var(--text-muted);">(${escapeHtml(t.created_by_id)})</span></div>
                        ${t.no_wa ? `<div style="margin-top:2px;">WhatsApp: <a href="https://wa.me/${encodeURIComponent(t.no_wa)}" target="_blank" style="color:#16a34a; font-weight:700; text-decoration:none;"><i class="fa-brands fa-whatsapp"></i> ${escapeHtml(t.no_wa)}</a></div>` : ''}
                        <div style="margin-top:2px;">Perusahaan: <strong>${escapeHtml(t.cmp_name || t.cmp_id)}</strong> <span style="background:rgba(14,165,233,0.12); color:#0284c7; padding:1px 5px; border-radius:3px; font-weight:600;">${escapeHtml(t.subdomain || t.cmp_id)}.inhost.id</span></div>
                        <div style="margin-top:2px;">Tgl Tiket: <strong>${escapeHtml(t.created_at)}</strong></div>
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <div style="font-weight:600; font-size:9.5px; margin-bottom:4px; color:var(--text-main);">Rincian Kendala / Permintaan:</div>
                    <div style="background:var(--table-head-bg); border:1px solid var(--border-table); border-radius:3px; padding:8px 10px; font-size:9.5px; line-height:1.45; white-space:pre-wrap;">${escapeHtml(t.description)}</div>
                </div>

                ${attHtml ? `<div style="margin-bottom:12px;"><div style="font-weight:600; font-size:9.5px; color:var(--text-main);">Lampiran &amp; Tangkapan Layar:</div>${attHtml}</div>` : ''}

                ${solutionHtml}

                <div style="margin-top:14px;">
                    <div style="font-weight:600; font-size:9.5px; margin-bottom:6px; color:var(--text-main);"><i class="fa-solid fa-comments"></i> Catatan Tindak Lanjut &amp; Diskusi:</div>
                    <div style="max-height:160px; overflow-y:auto; margin-bottom:8px;">${commentsHtml}</div>
                    
                    <div style="display:flex; gap:6px;">
                        <input type="text" id="inp-new-comment" class="ant-input" placeholder="Tuliskan catatan tindak lanjut..." style="flex:1;" onkeyup="if(event.key==='Enter') submitComment(${t.id})">
                        <button type="button" class="ant-btn ant-btn-primary" onclick="submitComment(${t.id})">
                            <i class="fa-solid fa-reply"></i> Kirim
                        </button>
                    </div>
                </div>`;

                // Footer Actions
                let footBtns = '<button type="button" class="ant-btn" onclick="closeModal(\'modalDetailTicket\')">Tutup</button>';
                <?php if ($isAdmin): ?>
                if (t.status !== 'solved') {
                    footBtns = `
                    <button type="button" class="ant-btn ant-btn-primary" onclick="openAiChatWithTicket(${t.id})">
                        <i class="fa-solid fa-robot"></i> Selesaikan dengan Asisten AI
                    </button>
                    <button type="button" class="ant-btn ant-btn-success" onclick="openSolveModal(${t.id}, '${escapeHtml(t.ticket_no)}')">
                        <i class="fa-solid fa-check"></i> Tandai Selesai (Solved)
                    </button>
                    ` + footBtns;
                }
                <?php endif; ?>
                actions.innerHTML = footBtns;

            } catch (err) {
                content.innerHTML = `<div style="color:var(--danger);">${escapeHtml(err.message)}</div>`;
            }
        }

        async function submitComment(ticketId) {
            const inp = document.getElementById('inp-new-comment');
            const comm = inp.value.trim();
            if (!comm) return;

            inp.disabled = true;
            try {
                const fd = new FormData();
                fd.append('action', 'add_comment');
                fd.append('ticket_id', ticketId);
                fd.append('comment', comm);

                const res = await fetch('ticket.php', { method: 'POST', body: fd });
                const json = await res.json();
                inp.disabled = false;
                if (json.status === 'OK') {
                    inp.value = '';
                    openTicketDetail(ticketId);
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message, customClass: { popup: 'compact-swal' } });
                }
            } catch(e) {
                inp.disabled = false;
            }
        }

        // =========================================================================
        // SOLVE MODAL
        // =========================================================================
        function openSolveModal(id, ticketNo) {
            document.getElementById('solve-ticket-id').value = id;
            document.getElementById('solve-notes').value = '';
            document.getElementById('modalSolveTicket').style.display = 'flex';
        }

        async function confirmSolveTicket() {
            const id = document.getElementById('solve-ticket-id').value;
            const notes = document.getElementById('solve-notes').value.trim();
            const by = document.getElementById('solve-by').value.trim();

            if (!notes) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Catatan penyelesaian wajib diisi.', customClass: { popup: 'compact-swal' } });
                return;
            }

            try {
                const fd = new FormData();
                fd.append('action', 'solve_ticket');
                fd.append('ticket_id', id);
                fd.append('solution_notes', notes);
                fd.append('solved_by', by);

                const res = await fetch('ticket.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (json.status === 'OK') {
                    closeModal('modalSolveTicket');
                    closeModal('modalDetailTicket');
                    Swal.fire({ icon: 'success', title: 'Tiket Selesai', text: json.message, customClass: { popup: 'compact-swal' } });
                    loadTicketsKPI();
                    loadTicketsList();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message, customClass: { popup: 'compact-swal' } });
                }
            } catch(e) {
                Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message, customClass: { popup: 'compact-swal' } });
            }
        }

        // Buka Asisten AI dan kirim tiket
        function openAiChatWithTicket(ticketId) {
            let promptText = '';
            if (activeDetailTicket) {
                const t = activeDetailTicket;
                promptText = `Mas Wawan butuh bantuan untuk menyelesaikan Tiket #${t.ticket_no}:\n- Kategori: ${t.category.toUpperCase()}\n- Menu: ${t.menu_name || t.menu_target}\n- Judul: ${t.title}\n- Subdomain: ${t.subdomain || t.cmp_id}.inhost.id\n- Perusahaan: ${t.cmp_name || t.cmp_id} [${t.cmp_id}]\n- Pemohon: ${t.created_by_name || t.created_by_id} (${t.created_by_id})\n- Penjelasan Kendala: ${t.description}`;
            }

            // Jika ada iframe parent (portal CEISA)
            if (window.parent && window.parent.switchMainTab) {
                window.parent.switchMainTab('ai_developer', promptText);
            } else {
                window.open('ai_developer.php', '_blank');
            }
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function showToast(msg, type = 'info') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            Toast.fire({ icon: type, title: msg });
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

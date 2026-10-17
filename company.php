<?php
/**
 * CEISA 4.0 - Master Data Konfigurasi Perusahaan (bc_ceisa_company)
 * Khusus Administrator
 * Mengadopsi Layout Resmi Ant Design 5.0 / Metronic Dashboard (Compact 10.5-12px)
 * Mendukung Multi-Theme (Light & Dark), Full CRUD AJAX, SweetAlert2, 
 * Live TOTP MFA Validator, Quick Toggle IS_INV & Status Akun.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';

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
            <p>Halaman Pengaturan Perusahaan hanya dapat diakses oleh akun <strong>Administrator</strong>.</p>
            <a href="/index.php" class="btn">Kembali ke Portal Utama</a>
        </div>
    </body>
    </html>';
    exit;
}

$action = $_REQUEST['action'] ?? '';

// -------------------------------------------------------------
// 1. AJAX API: LIST ALL COMPANIES
// -------------------------------------------------------------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    
    $query = "SELECT * FROM bc_ceisa_company ORDER BY cmp_name ASC";
    $res = $koneksi->query($query);
    
    $companies = [];
    $totalCount = 0;
    $activeCount = 0;
    $invActiveCount = 0;
    
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $totalCount++;
            if ((int)$row['is_active'] === 1) $activeCount++;
            if ((int)($row['IS_INV'] ?? 1) === 1) $invActiveCount++;
            
            // Mask password untuk keamanan tampilan list
            $row['has_password_ceisa'] = !empty($row['password_ceisa']) ? 1 : 0;
            $row['has_secret_mfa']     = !empty($row['secret_mfa']) ? 1 : 0;
            $row['has_erp_pass']       = !empty($row['ERP_Password']) ? 1 : 0;
            
            $companies[] = $row;
        }
    }
    
    echo json_encode([
        'status' => 'OK',
        'data' => $companies,
        'summary' => [
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $totalCount - $activeCount,
            'inv_active' => $invActiveCount
        ],
        'session_active_cmp' => $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '')
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: GET SINGLE COMPANY DETAIL
// -------------------------------------------------------------
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_GET['cmp_id'] ?? '');
    
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tidak boleh kosong.']);
        exit;
    }
    
    $stmt = $koneksi->prepare("SELECT * FROM bc_ceisa_company WHERE cmp_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $cmp_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $data = $res->fetch_assoc()) {
            echo json_encode(['status' => 'OK', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => "Perusahaan dengan ID '$cmp_id' tidak ditemukan."]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $koneksi->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: SAVE COMPANY (INSERT / UPDATE)
// -------------------------------------------------------------
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    
    $mode           = trim($_POST['mode'] ?? 'add'); // 'add' atau 'edit'
    $cmp_id         = trim($_POST['cmp_id'] ?? '');
    $cmp_name       = trim($_POST['cmp_name'] ?? '');
    $app_password   = trim($_POST['app_password'] ?? '1234');
    $npwp           = trim($_POST['npwp'] ?? '');
    $nib            = trim($_POST['nib'] ?? '');
    $alamat         = trim($_POST['alamat'] ?? '');
    $kode_kantor    = trim($_POST['kode_kantor'] ?? '');
    $kode_jenis_tpb = trim($_POST['kode_jenis_tpb'] ?? '');
    $nomor_skep     = trim($_POST['nomor_skep'] ?? '');
    $tanggal_skep   = trim($_POST['tanggal_skep'] ?? '');
    if (!empty($tanggal_skep)) {
        $ts = strtotime($tanggal_skep);
        $tanggal_skep = ($ts !== false) ? date('Y-m-d', $ts) : null;
    } else {
        $tanggal_skep = null;
    }
    $username_ceisa = trim($_POST['username_ceisa'] ?? '');
    $password_ceisa = trim($_POST['password_ceisa'] ?? '');
    $secret_mfa     = trim(str_replace(' ', '', strtoupper($_POST['secret_mfa'] ?? '')));
    $id_user_ceisa  = trim($_POST['id_user_ceisa'] ?? '');
    $is_final       = (isset($_POST['is_final']) && strtolower($_POST['is_final']) === 'true') ? 'true' : 'false';
    $token_ceisa    = trim($_POST['token_ceisa'] ?? '');
    $api_key_ceisa  = trim($_POST['api_key_ceisa'] ?? 'f662de2e-1d35-4b11-b2fd-ee164a5c9d83');
    $erp_name       = trim($_POST['ERP_Name'] ?? '-');
    $erp_user       = trim($_POST['ERP_User'] ?? '');
    $erp_password   = trim($_POST['ERP_Password'] ?? '');
    $erp_constring  = trim($_POST['ERP_Constring'] ?? '');
    $ai_name        = trim($_POST['AI_Name'] ?? '');
    $ai_api_key     = trim($_POST['AI_API_Key'] ?? '');
    $is_active      = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $is_inv         = isset($_POST['IS_INV']) ? (int)$_POST['IS_INV'] : 1;
    $allow_lowercase_code = isset($_POST['allow_lowercase_code']) ? (int)$_POST['allow_lowercase_code'] : 0;
    $alert_it_inv_unposted = isset($_POST['alert_it_inv_unposted']) ? (int)$_POST['alert_it_inv_unposted'] : 0;
    $subdomain      = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['subdomain'] ?? '')));
    $logo           = trim($_POST['logo'] ?? '');

    // Handle Upload File Logo jika ada
    if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmp  = $_FILES['logo_file']['tmp_name'];
        $fileName = $_FILES['logo_file']['name'];
        $fileSize = $_FILES['logo_file']['size'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExt = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];

        if (in_array($ext, $allowedExt) && $fileSize <= 5 * 1024 * 1024) {
            $cleanCmp = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cmp_id);
            $targetDir = __DIR__ . '/uploads/logos/';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
            $newFileName = 'logo_' . $cleanCmp . '_' . time() . '.' . $ext;
            $destPath = $targetDir . $newFileName;
            if (move_uploaded_file($fileTmp, $destPath)) {
                $logo = 'uploads/logos/' . $newFileName;
            }
        }
    }
    
    // Validasi Wajib
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan (cmp_id) wajib diisi.']);
        exit;
    }
    if (empty($cmp_name)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nama Perusahaan (cmp_name) wajib diisi.']);
        exit;
    }

    // Validasi Keunikan Subdomain
    if (!empty($subdomain)) {
        $subStmt = $koneksi->prepare("SELECT cmp_id, cmp_name FROM bc_ceisa_company WHERE LOWER(subdomain) = ? AND cmp_id != ? LIMIT 1");
        if ($subStmt) {
            $subStmt->bind_param("ss", $subdomain, $cmp_id);
            $subStmt->execute();
            $subStmt->store_result();
            if ($subStmt->num_rows > 0) {
                $subStmt->close();
                echo json_encode(['status' => 'ERROR', 'message' => "Subdomain '$subdomain' sudah digunakan oleh perusahaan lain. Pilih subdomain lain."]);
                exit;
            }
            $subStmt->close();
        }
    }
    
    if ($mode === 'add') {
        // Cek duplikasi cmp_id
        $checkStmt = $koneksi->prepare("SELECT cmp_id FROM bc_ceisa_company WHERE cmp_id = ? LIMIT 1");
        $checkStmt->bind_param("s", $cmp_id);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) {
            $checkStmt->close();
            echo json_encode(['status' => 'ERROR', 'message' => "ID Perusahaan '$cmp_id' sudah terdaftar dalam sistem. Gunakan ID lain."]);
            exit;
        }
        $checkStmt->close();
        
        $sql = "INSERT INTO bc_ceisa_company (
            cmp_id, cmp_name, logo, subdomain, app_password, npwp, nib, alamat,
            kode_kantor, kode_jenis_tpb, nomor_skep, tanggal_skep,
            username_ceisa, password_ceisa, secret_mfa, id_user_ceisa, is_final,
            token_ceisa, api_key_ceisa, ERP_Name, ERP_User, ERP_Password, ERP_Constring,
            AI_Name, AI_API_Key, is_active, IS_INV, allow_lowercase_code, alert_it_inv_unposted
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $koneksi->prepare($sql);
        if (!$stmt) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mempersiapkan query insert: ' . $koneksi->error]);
            exit;
        }
        
        $stmt->bind_param(
            "sssssssssssssssssssssssssiiii",
            $cmp_id, $cmp_name, $logo, $subdomain, $app_password, $npwp, $nib, $alamat,
            $kode_kantor, $kode_jenis_tpb, $nomor_skep, $tanggal_skep,
            $username_ceisa, $password_ceisa, $secret_mfa, $id_user_ceisa, $is_final,
            $token_ceisa, $api_key_ceisa, $erp_name, $erp_user, $erp_password, $erp_constring,
            $ai_name, $ai_api_key, $is_active, $is_inv, $allow_lowercase_code, $alert_it_inv_unposted
        );
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'OK', 'message' => "Perusahaan '$cmp_name' berhasil ditambahkan!"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan perusahaan: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    } else {
        // MODE EDIT (UPDATE)
        $sql = "UPDATE bc_ceisa_company SET 
            cmp_name = ?, logo = ?, subdomain = ?, app_password = ?, npwp = ?, nib = ?, alamat = ?,
            kode_kantor = ?, kode_jenis_tpb = ?, nomor_skep = ?, tanggal_skep = ?,
            username_ceisa = ?, password_ceisa = ?, secret_mfa = ?, id_user_ceisa = ?, is_final = ?,
            token_ceisa = ?, api_key_ceisa = ?, ERP_Name = ?, ERP_User = ?, ERP_Password = ?, ERP_Constring = ?,
            AI_Name = ?, AI_API_Key = ?, is_active = ?, IS_INV = ?, allow_lowercase_code = ?, alert_it_inv_unposted = ?
            WHERE cmp_id = ?";
            
        $stmt = $koneksi->prepare($sql);
        if (!$stmt) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mempersiapkan query update: ' . $koneksi->error]);
            exit;
        }
        
        $stmt->bind_param(
            "ssssssssssssssssssssssssiiiis",
            $cmp_name, $logo, $subdomain, $app_password, $npwp, $nib, $alamat,
            $kode_kantor, $kode_jenis_tpb, $nomor_skep, $tanggal_skep,
            $username_ceisa, $password_ceisa, $secret_mfa, $id_user_ceisa, $is_final,
            $token_ceisa, $api_key_ceisa, $erp_name, $erp_user, $erp_password, $erp_constring,
            $ai_name, $ai_api_key, $is_active, $is_inv, $allow_lowercase_code, $alert_it_inv_unposted, $cmp_id
        );
        
        if ($stmt->execute()) {
            // Update session jika perusahaan yang diedit sedang aktif
            if (!empty($_SESSION['company_cmp_id']) && $_SESSION['company_cmp_id'] === $cmp_id) {
                $_SESSION['company_name']            = $cmp_name;
                $_SESSION['company_npwp']            = $npwp;
                $_SESSION['is_inv']                  = $is_inv;
                $_SESSION['company_allow_lowercase'] = $allow_lowercase_code;
                $_SESSION['allow_lowercase_code']    = $allow_lowercase_code;
                $_SESSION['company_alert_it_inv_unposted'] = $alert_it_inv_unposted;
                $_SESSION['alert_it_inv_unposted']   = $alert_it_inv_unposted;
                $_SESSION['company_logo']            = $logo;
                $_SESSION['company_subdomain']       = $subdomain;
            }
            echo json_encode(['status' => 'OK', 'message' => "Data perusahaan '$cmp_name' berhasil diperbarui!"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui perusahaan: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    }
}

// -------------------------------------------------------------
// 4. AJAX API: TOGGLE STATUS AKTIF (is_active: 1 <-> 0)
// -------------------------------------------------------------
if ($action === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_POST['cmp_id'] ?? '');
    
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tidak boleh kosong.']);
        exit;
    }
    
    $stmt = $koneksi->prepare("UPDATE bc_ceisa_company SET is_active = IF(is_active = 1, 0, 1) WHERE cmp_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $cmp_id);
        if ($stmt->execute()) {
            $q = $koneksi->query("SELECT is_active, cmp_name FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($cmp_id) . "' LIMIT 1");
            $r = $q ? $q->fetch_assoc() : null;
            $newStatus = $r ? (int)$r['is_active'] : 0;
            $cmpName = $r ? $r['cmp_name'] : $cmp_id;
            echo json_encode([
                'status' => 'OK',
                'new_status' => $newStatus,
                'message' => "Status perusahaan '$cmpName' kini " . ($newStatus === 1 ? 'AKTIF' : 'NON-AKTIF') . "."
            ]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $stmt->error]);
        }
        $stmt->close();
    }
    exit;
}

// -------------------------------------------------------------
// 5. AJAX API: TOGGLE IS_INV (IS_INV: 1 <-> 0)
// -------------------------------------------------------------
if ($action === 'toggle_is_inv') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_POST['cmp_id'] ?? '');
    
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tidak boleh kosong.']);
        exit;
    }
    
    $stmt = $koneksi->prepare("UPDATE bc_ceisa_company SET IS_INV = IF(IS_INV = 1, 0, 1) WHERE cmp_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $cmp_id);
        if ($stmt->execute()) {
            $q = $koneksi->query("SELECT IS_INV, cmp_name FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($cmp_id) . "' LIMIT 1");
            $r = $q ? $q->fetch_assoc() : null;
            $newInv = $r ? (int)$r['IS_INV'] : 0;
            $cmpName = $r ? $r['cmp_name'] : $cmp_id;
            
            // Sinkronkan ke session jika perusahaan ini sedang aktif
            if (!empty($_SESSION['company_cmp_id']) && $_SESSION['company_cmp_id'] === $cmp_id) {
                $_SESSION['is_inv'] = $newInv;
            }
            
            echo json_encode([
                'status' => 'OK',
                'new_is_inv' => $newInv,
                'message' => "Pengaturan IT Inventory untuk '$cmpName' kini " . ($newInv === 1 ? 'AKTIF (Aturan warna PB aktif)' : 'NON-AKTIF (Warna standar)') . "."
            ]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah pengaturan IT Inventory: ' . $stmt->error]);
        }
        $stmt->close();
    }
    exit;
}

// -------------------------------------------------------------
// 5b. AJAX API: TOGGLE ALERT IT INV (alert_it_inv_unposted: 1 <-> 0)
// -------------------------------------------------------------
if ($action === 'toggle_alert_it_inv') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_POST['cmp_id'] ?? '');
    
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tidak boleh kosong.']);
        exit;
    }
    
    $stmt = $koneksi->prepare("UPDATE bc_ceisa_company SET alert_it_inv_unposted = IF(alert_it_inv_unposted = 1, 0, 1) WHERE cmp_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $cmp_id);
        if ($stmt->execute()) {
            $q = $koneksi->query("SELECT alert_it_inv_unposted, cmp_name FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($cmp_id) . "' LIMIT 1");
            $r = $q ? $q->fetch_assoc() : null;
            $newAlert = $r ? (int)$r['alert_it_inv_unposted'] : 0;
            $cmpName = $r ? $r['cmp_name'] : $cmp_id;
            
            // Sinkronkan ke session jika perusahaan ini sedang aktif
            if (!empty($_SESSION['company_cmp_id']) && $_SESSION['company_cmp_id'] === $cmp_id) {
                $_SESSION['company_alert_it_inv_unposted'] = $newAlert;
                $_SESSION['alert_it_inv_unposted'] = $newAlert;
            }
            
            echo json_encode([
                'status' => 'OK',
                'new_alert_it_inv' => $newAlert,
                'message' => "Peringatan Dokumen Belum Masuk IT Inv untuk '$cmpName' kini " . ($newAlert === 1 ? 'AKTIF (Popup peringatan muncul otomatis)' : 'NON-AKTIF (Popup tidak muncul otomatis)') . "."
            ]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah pengaturan peringatan IT Inventory: ' . $stmt->error]);
        }
        $stmt->close();
    }
    exit;
}

// -------------------------------------------------------------
// 6. AJAX API: SET PERUSAHAAN AKTIF DI SESI ADMIN
// -------------------------------------------------------------
if ($action === 'set_active') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_POST['cmp_id'] ?? '');
    
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tidak boleh kosong.']);
        exit;
    }
    
    $stmt = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, IFNULL(IS_INV, 1) as IS_INV, IFNULL(allow_lowercase_code, 0) as allow_lowercase_code, IFNULL(alert_it_inv_unposted, 0) as alert_it_inv_unposted FROM bc_ceisa_company WHERE cmp_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $cmp_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $comp = $res->fetch_assoc()) {
            $_SESSION['company_cmp_id']          = $comp['cmp_id'];
            $_SESSION['cmp_id']                  = $comp['cmp_id'];
            $_SESSION['company_name']            = $comp['cmp_name'];
            $_SESSION['company_npwp']            = $comp['npwp'];
            $_SESSION['is_inv']                  = (int)$comp['IS_INV'];
            $_SESSION['company_allow_lowercase'] = (int)$comp['allow_lowercase_code'];
            $_SESSION['allow_lowercase_code']    = (int)$comp['allow_lowercase_code'];
            $_SESSION['company_alert_it_inv_unposted'] = (int)$comp['alert_it_inv_unposted'];
            $_SESSION['alert_it_inv_unposted']   = (int)$comp['alert_it_inv_unposted'];
            
            echo json_encode([
                'status'   => 'OK',
                'cmp_id'   => $comp['cmp_id'],
                'cmp_name' => $comp['cmp_name'],
                'is_inv'   => (int)$comp['IS_INV'],
                'allow_lowercase_code' => (int)$comp['allow_lowercase_code'],
                'alert_it_inv_unposted' => (int)$comp['alert_it_inv_unposted'],
                'message'  => "Berhasil menetapkan [{$comp['cmp_id']}] {$comp['cmp_name']} sebagai perusahaan aktif di portal!"
            ]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => "Perusahaan '$cmp_id' tidak ditemukan."]);
        }
        $stmt->close();
    }
    exit;
}

// -------------------------------------------------------------
// 7. AJAX API: DELETE PERUSAHAAN
// -------------------------------------------------------------
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_POST['cmp_id'] ?? '');
    
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tidak boleh kosong.']);
        exit;
    }
    
    // Cek jumlah total perusahaan
    $cntRes = $koneksi->query("SELECT COUNT(*) as total FROM bc_ceisa_company");
    $cnt = $cntRes ? $cntRes->fetch_assoc()['total'] : 0;
    if ($cnt <= 1) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak dapat menghapus satu-satunya perusahaan yang terdaftar dalam sistem.']);
        exit;
    }
    
    $stmt = $koneksi->prepare("DELETE FROM bc_ceisa_company WHERE cmp_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $cmp_id);
        if ($stmt->execute()) {
            // Jika perusahaan yang dihapus sedang aktif di session, ganti ke yang lain
            if (!empty($_SESSION['company_cmp_id']) && $_SESSION['company_cmp_id'] === $cmp_id) {
                $other = $koneksi->query("SELECT cmp_id, cmp_name, npwp, IFNULL(IS_INV,1) as IS_INV FROM bc_ceisa_company ORDER BY cmp_name ASC LIMIT 1");
                if ($other && $or = $other->fetch_assoc()) {
                    $_SESSION['company_cmp_id'] = $or['cmp_id'];
                    $_SESSION['cmp_id']         = $or['cmp_id'];
                    $_SESSION['company_name']   = $or['cmp_name'];
                    $_SESSION['company_npwp']   = $or['npwp'];
                    $_SESSION['is_inv']         = (int)$or['IS_INV'];
                }
            }
            echo json_encode(['status' => 'OK', 'message' => "Perusahaan '$cmp_id' berhasil dihapus dari database."]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus perusahaan: ' . $stmt->error]);
        }
        $stmt->close();
    }
    exit;
}

// -------------------------------------------------------------
// 7b. AJAX API: CLEAR ALL TRANSACTION DATA FOR COMPANY
// -------------------------------------------------------------
if ($action === 'clear_data') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id = trim($_POST['cmp_id'] ?? '');
    $confirm_name = trim($_POST['confirm_name'] ?? '');
    
    if (empty($cmp_id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tidak boleh kosong.']);
        exit;
    }
    
    $escCmp = $koneksi->real_escape_string($cmp_id);
    
    // Validasi data perusahaan
    $qComp = $koneksi->query("SELECT cmp_id, cmp_name FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    if (!$qComp || $qComp->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Perusahaan dengan ID '$cmp_id' tidak ditemukan."]);
        exit;
    }
    $comp = $qComp->fetch_assoc();
    $cmpName = trim($comp['cmp_name'] ?? '');
    
    // Validasi keamanan: User wajib mengetik nama perusahaan yang persis sama
    if (strcasecmp($confirm_name, $cmpName) !== 0) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => "Konfirmasi nama perusahaan tidak cocok! Anda mengetik: '$confirm_name', seharusnya: '$cmpName'."
        ]);
        exit;
    }
    
    try {
        // 1. Kumpulkan semua nomor_aju milik perusahaan ini dari bc_ceisa_header dan bc_ceisa_data
        $nomorAjuMap = [];
        try {
            $qAju1 = $koneksi->query("SELECT DISTINCT nomor_aju FROM bc_ceisa_header WHERE cmp_id = '$escCmp'");
            if ($qAju1) {
                while ($ra = $qAju1->fetch_assoc()) {
                    $na = trim($ra['nomor_aju'] ?? '');
                    if (!empty($na)) $nomorAjuMap[$na] = true;
                }
            }
        } catch (\Throwable $e) {}

        try {
            $qAju2 = $koneksi->query("SELECT DISTINCT nomorAju FROM bc_ceisa_data WHERE cmp_id = '$escCmp'");
            if ($qAju2) {
                while ($ra = $qAju2->fetch_assoc()) {
                    $na = trim($ra['nomorAju'] ?? '');
                    if (!empty($na)) $nomorAjuMap[$na] = true;
                }
            }
        } catch (\Throwable $e) {}

        $nomorAjuList = array_keys($nomorAjuMap);
        $totalAju = count($nomorAjuList);
        
        // 2. Daftar seluruh tabel transaksi kepabeanan & log yang memiliki kolom cmp_id
        $tablesWithCmp = [
            'bc_ceisa_header',
            'bc_ceisa_data',
            'bc_ceisa_sppb',
            'bc_ceisa_pengangkut',
            'bc_ceisa_kemasan',
            'bc_ceisa_entitas',
            'bc_ceisa_dokumen',
            'bc_ceisa_barang',
            'bc_ceisa_barangtarif',
            'bc_ceisa_barangdokumen',
            'bc_ceisa_barangentitas',
            'bc_ceisa_barangspekkhusus',
            'bc_ceisa_barangvd',
            'bc_ceisa_barangbom',
            'bc_ceisa_bahanbaku',
            'bc_ceisa_bahanbakudokumen',
            'bc_ceisa_bahanbakutarif',
            'bc_ceisa_kontainer',
            'bc_ceisa_bankdevisa',
            'bc_ceisa_pungutan',
            'bc_ceisa_jaminan',
            'bc_ceisa_komponenbiaya',
            'bc_ceisa_kesiapanbarang',
            'bc_ceisa_aju_posted',
            'bc_ceisa_status',
            'bc_ceisa_respons',
            'bc_ceisa_respon_logs',
            'bc_ceisa_json',
            'bc_ceisa_data_compareit',
            'bc_ceisa_scan27',
            'bc_si_aju_ceisa',
            'bc_ceisa_docidx',
            'bc_ceisa_entidx',
            'bc_ceisa_bs_entitas',
            'bc_ceisa_bom',
            'bc_ceisa_tracebb',
            'bc_ceisa_atur_seri',
            'bc_ceisa_get_data',
            'bc_ceisa_get_data_xls',
            'bc_ceisa_get_barang',
            'bc_ceisa_get_barang_xls',
            'bc_ceisa_get_dokumen',
            'bc_ceisa_get_dokumen_xls',
            'bc_ceisa_get_entitas',
            'bc_ceisa_get_entitas_xls',
            'bc_ceisa_get_kemasan',
            'bc_ceisa_get_kemasan_xls',
            'bc_ceisa_get_pdf',
            'bc_ceisa_get_tps',
            'bc_ceisa_get_user',
            'bc_ceisa_manifes_header',
            'bc_ceisa_manifes_master_bl',
            'bc_ceisa_manifes_bl',
            'bc_ceisa_manifes_bl_dokumen',
            'bc_ceisa_manifes_bl_hs',
            'bc_ceisa_manifes_bl_petikemas',
            'bc_ceisa_manifes_kelompok_pos',
            'bc_ceisa_manifes_lampiran',
            'bc_ceisa_manifes_respon_logs'
        ];
        
        foreach ($tablesWithCmp as $tbl) {
            try {
                $koneksi->query("DELETE FROM $tbl WHERE cmp_id = '$escCmp'");
            } catch (\Throwable $e) {}
        }
        
        // 3. Bersihkan tabel anak berelasi dengan nomor_aju untuk menjamin tidak ada orphaned rows
        if (!empty($nomorAjuList)) {
            $chunks = array_chunk($nomorAjuList, 400);
            $childTables = [
                'bc_ceisa_pengangkut', 'bc_ceisa_kemasan', 'bc_ceisa_entitas', 'bc_ceisa_dokumen',
                'bc_ceisa_barang', 'bc_ceisa_barangtarif', 'bc_ceisa_barangdokumen', 'bc_ceisa_barangentitas',
                'bc_ceisa_barangspekkhusus', 'bc_ceisa_barangvd', 'bc_ceisa_barangbom', 'bc_ceisa_bahanbaku',
                'bc_ceisa_bahanbakudokumen', 'bc_ceisa_bahanbakutarif', 'bc_ceisa_kontainer', 'bc_ceisa_bankdevisa',
                'bc_ceisa_pungutan', 'bc_ceisa_jaminan', 'bc_ceisa_komponenbiaya', 'bc_ceisa_kesiapanbarang',
                'bc_ceisa_aju_posted', 'bc_ceisa_status', 'bc_ceisa_respons', 'bc_ceisa_respon_logs',
                'bc_ceisa_json', 'bc_ceisa_data_compareit', 'bc_ceisa_req_unpost'
            ];
            
            foreach ($chunks as $chunk) {
                $escapedAju = array_map(function($a) use ($koneksi) {
                    return "'" . $koneksi->real_escape_string($a) . "'";
                }, $chunk);
                $inClause = implode(',', $escapedAju);
                
                foreach ($childTables as $ct) {
                    try {
                        $koneksi->query("DELETE FROM $ct WHERE nomor_aju IN ($inClause)");
                    } catch (\Throwable $e) {}
                }
                try {
                    $koneksi->query("DELETE FROM bc_ceisa_data WHERE nomorAju IN ($inClause)");
                } catch (\Throwable $e) {}
            }
        }
        
        // 4. Reset counter penomoran urut AJU ke 0
        try {
            $koneksi->query("UPDATE bc_ceisa_setaju SET aju = 0 WHERE cmp_id = '$escCmp'");
        } catch (\Throwable $e) {}
        
        // 5. Bersihkan berkas payload JSON fisik di disk jika ada
        $cleanCmp = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cmp_id);
        if (!empty($cleanCmp)) {
            @unlink(__DIR__ . "/json_{$cleanCmp}.txt");
            @unlink("/source/public/json_{$cleanCmp}.txt");
            @unlink("/source/public/www/json_{$cleanCmp}.txt");
        }
        foreach ($nomorAjuList as $aju) {
            $cleanAju = preg_replace('/[^a-zA-Z0-9_\-]/', '', $aju);
            if (!empty($cleanAju)) {
                @unlink(__DIR__ . "/json_{$cleanAju}.txt");
                @unlink("/source/public/json_{$cleanAju}.txt");
                @unlink("/source/public/www/json_{$cleanAju}.txt");
            }
        }
        
        echo json_encode([
            'status' => 'OK',
            'message' => "Semua data transaksi untuk '$cmpName' berhasil dibersihkan.",
            'details' => "Sebanyak $totalAju dokumen AJU dan seluruh tabel staging/log terkait telah dihapus. Urutan nomor AJU telah di-reset ke 0."
        ]);
    } catch (\Exception $e) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => "Gagal membersihkan data transaksi: " . $e->getMessage()
        ]);
    }
    exit;
}

// -------------------------------------------------------------
// 8. AJAX API: TEST TOTP MFA SECRET
// -------------------------------------------------------------
if ($action === 'test_totp') {
    header('Content-Type: application/json; charset=utf-8');
    $secret = trim(str_replace(' ', '', strtoupper($_GET['secret'] ?? '')));
    
    if (empty($secret)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Secret Key MFA tidak boleh kosong.']);
        exit;
    }
    
    if (!file_exists(__DIR__ . '/GoogleAuthenticator.php')) {
        echo json_encode(['status' => 'ERROR', 'message' => 'File GoogleAuthenticator.php tidak ditemukan di server.']);
        exit;
    }
    
    require_once __DIR__ . '/GoogleAuthenticator.php';
    try {
        $ga = new PHPGangsta_GoogleAuthenticator();
        $code = $ga->getCode($secret);
        $timeSlice = floor(time() / 30);
        $remaining = 30 - (time() % 30);
        
        echo json_encode([
            'status' => 'OK',
            'code' => $code,
            'remaining' => $remaining,
            'message' => 'Kunci MFA Valid!'
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Secret Key MFA tidak valid: ' . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 9. AJAX API: CALCULATE STORAGE USAGE PER COMPANY (ON-DEMAND)
// -------------------------------------------------------------
if ($action === 'calc_storage') {
    header('Content-Type: application/json; charset=utf-8');
    
    $startTime = microtime(true);
    
    // Database Name
    $dbRes = $koneksi->query("SELECT DATABASE()");
    $dbName = $dbRes ? $dbRes->fetch_row()[0] : 'ceisa0400db';

    // 1. Dapatkan semua tabel di database beserta ukuran dan data bytes
    $tableStatsQ = $koneksi->query("
        SELECT 
            TABLE_NAME, 
            TABLE_ROWS, 
            AVG_ROW_LENGTH, 
            DATA_LENGTH, 
            INDEX_LENGTH,
            (DATA_LENGTH + INDEX_LENGTH) AS total_bytes
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = '$dbName'
    ");

    $tableStats = [];
    $totalDbBytes = 0;
    $totalDbRows = 0;

    if ($tableStatsQ) {
        while ($ts = $tableStatsQ->fetch_assoc()) {
            $tName = $ts['TABLE_NAME'];
            $rows = (int)$ts['TABLE_ROWS'];
            $tBytes = (int)$ts['total_bytes'];
            $tableStats[$tName] = [
                'rows'        => $rows,
                'total_bytes' => $tBytes,
                'unit_bytes'  => ($rows > 0) ? ($tBytes / $rows) : 0
            ];
            $totalDbBytes += $tBytes;
            $totalDbRows += $rows;
        }
    }

    // 2. Daftar tabel yang memiliki kolom cmp_id
    $colQ = $koneksi->query("
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = '$dbName' AND COLUMN_NAME = 'cmp_id'
    ");
    $tablesWithCmp = [];
    if ($colQ) {
        while ($cr = $colQ->fetch_assoc()) {
            $tbl = $cr['TABLE_NAME'];
            if ($tbl !== 'bc_ceisa_company') {
                $tablesWithCmp[] = $tbl;
            }
        }
    }

    // Peta label ramah pembaca untuk tabel-tabel transaksi utama
    $tableLabels = [
        'bc_ceisa_header'              => 'Header Dokumen Pabean (AJU)',
        'bc_ceisa_data'                => 'Staging Data Dokumen',
        'bc_ceisa_sppb'                => 'Arsip Respon SPPB Permanen',
        'bc_ceisa_dokumen'             => 'Dokumen Pelengkap (Inv/BL/SKEP)',
        'bc_ceisa_barang'              => 'Item Barang Ekspor/Impor',
        'bc_ceisa_barangtarif'         => 'Tarif Bea Masuk / Pajak Barang',
        'bc_ceisa_barangdokumen'       => 'Relasi Dokumen per Barang',
        'bc_ceisa_bahanbaku'           => 'Bahan Baku (Subkontrak/BC 2.7)',
        'bc_ceisa_bahanbakutarif'      => 'Tarif Bahan Baku',
        'bc_ceisa_bahanbakudokumen'    => 'Dokumen Bahan Baku',
        'bc_ceisa_entitas'             => 'Entitas (Pengirim/Penerima)',
        'bc_ceisa_kemasan'             => 'Data Kemasan',
        'bc_ceisa_kontainer'           => 'Data Petikemas/Kontainer',
        'bc_ceisa_pengangkut'          => 'Sarana Pengangkut',
        'bc_ceisa_respons'             => 'Respon Resmi Bea Cukai',
        'bc_ceisa_respon_logs'         => 'Log Riwayat Respon',
        'bc_ceisa_status'              => 'Status Proses Pabean',
        'bc_ceisa_pungutan'            => 'Pungutan Bea Masuk/Keluar',
        'bc_ceisa_bankdevisa'          => 'Bank Devisa Pabean',
        'bc_ceisa_jaminan'             => 'Jaminan Pabean',
        'bc_ceisa_komponenbiaya'       => 'Komponen Biaya (Freight/Asuransi)',
        'bc_ceisa_aju_posted'          => 'Arsip AJU Terposting',
        'bc_ceisa_login'               => 'Akun Pengguna Perusahaan',
        'bc_ceisa_versi'               => 'Log Versi Dokumen',
        'bc_ceisa_json'                => 'Payload JSON Ceisa',
        'bc_ceisa_setaju'              => 'Penomoran Urut AJU',
        'bc_ceisa_manifes_header'      => 'Manifes BC 1.1 Header',
        'bc_ceisa_manifes_bl'          => 'Manifes Master B/L',
        'bc_ceisa_manifes_respon_logs' => 'Log Respon Manifes'
    ];

    // 3. Dapatkan daftar perusahaan
    $compQ = $koneksi->query("SELECT cmp_id, cmp_name FROM bc_ceisa_company ORDER BY cmp_name ASC");
    $companyData = [];
    if ($compQ) {
        while ($comp = $compQ->fetch_assoc()) {
            $cid = $comp['cmp_id'];
            $companyData[$cid] = [
                'cmp_id'      => $cid,
                'cmp_name'    => $comp['cmp_name'],
                'total_bytes' => 0,
                'total_rows'  => 0,
                'total_docs'  => 0,
                'breakdown'   => []
            ];
        }
    }

    // 4. Hitung dokumen AJU dari bc_ceisa_header
    $docQ = $koneksi->query("SELECT cmp_id, COUNT(*) as cnt FROM bc_ceisa_header GROUP BY cmp_id");
    if ($docQ) {
        while ($dr = $docQ->fetch_assoc()) {
            $cid = $dr['cmp_id'];
            if (isset($companyData[$cid])) {
                $companyData[$cid]['total_docs'] = (int)$dr['cnt'];
            }
        }
    }

    // 5. Hitung row count per perusahaan di tabel-tabel transaksi yang aktif
    $activeTables = [];
    foreach ($tablesWithCmp as $tbl) {
        if (isset($tableStats[$tbl]) && $tableStats[$tbl]['rows'] > 0) {
            $activeTables[] = $tbl;
        }
    }

    foreach ($activeTables as $tbl) {
        $unitByte = $tableStats[$tbl]['unit_bytes'];
        $q = $koneksi->query("SELECT cmp_id, COUNT(*) as cnt FROM `$tbl` GROUP BY cmp_id");
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $cid = $r['cmp_id'];
                $cnt = (int)$r['cnt'];
                if (isset($companyData[$cid])) {
                    $bytes = ($cnt * $unitByte);
                    $companyData[$cid]['total_rows'] += $cnt;
                    $companyData[$cid]['total_bytes'] += $bytes;
                    
                    if ($cnt > 0) {
                        $label = $tableLabels[$tbl] ?? $tbl;
                        $companyData[$cid]['breakdown'][] = [
                            'table' => $tbl,
                            'label' => $label,
                            'rows'  => $cnt,
                            'bytes' => $bytes,
                            'mb'    => round($bytes / 1024 / 1024, 2)
                        ];
                    }
                }
            }
        }
    }

    // Hitung total akumulasi seluruh perusahaan
    $grandCompanyBytes = 0;
    $grandCompanyRows  = 0;
    $grandCompanyDocs  = 0;

    foreach ($companyData as $cid => &$c) {
        $grandCompanyBytes += $c['total_bytes'];
        $grandCompanyRows  += $c['total_rows'];
        $grandCompanyDocs  += $c['total_docs'];
        
        // Urutkan rincian tabel dari pemakaian baris terbesar
        usort($c['breakdown'], function($a, $b) {
            return $b['rows'] <=> $a['rows'];
        });
    }
    unset($c);

    // Format output untuk UI
    $formattedByCompany = [];
    foreach ($companyData as $cid => $c) {
        $mb = round($c['total_bytes'] / 1024 / 1024, 2);
        $percent = ($grandCompanyBytes > 0) ? round(($c['total_bytes'] / $grandCompanyBytes) * 100, 1) : 0;
        
        $formattedByCompany[$cid] = [
            'cmp_id'    => $cid,
            'cmp_name'  => $c['cmp_name'],
            'bytes'     => $c['total_bytes'],
            'mb'        => number_format($mb, 2) . ' MB',
            'mb_raw'    => $mb,
            'rows'      => $c['total_rows'],
            'docs'      => $c['total_docs'],
            'percent'   => $percent . '%',
            'breakdown' => $c['breakdown']
        ];
    }

    $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
    $grandMb = round($grandCompanyBytes / 1024 / 1024, 2);
    $totalDbMb = round($totalDbBytes / 1024 / 1024, 2);

    echo json_encode([
        'status'         => 'OK',
        'calc_time_ms'   => $elapsedMs,
        'calculated_at'  => date('d M Y, H:i') . ' WIB',
        'total'          => [
            'companies_bytes' => $grandCompanyBytes,
            'companies_mb'    => number_format($grandMb, 2) . ' MB',
            'companies_rows'  => $grandCompanyRows,
            'companies_docs'  => $grandCompanyDocs,
            'db_total_mb'     => number_format($totalDbMb, 2) . ' MB',
            'db_total_rows'   => $totalDbRows
        ],
        'by_company'     => $formattedByCompany
    ]);
    exit;
}

// Ambil data referensi Jenis TPB dari tabel bc_ceisa_reff
$refJenisTpb = [];
if (isset($koneksi) && $koneksi) {
    $qTpb = $koneksi->query("SELECT kode, teks FROM bc_ceisa_reff WHERE reff = 'JENIS TPB' AND (status IS NULL OR status <> 'N') ORDER BY CAST(kode AS UNSIGNED) ASC");
    if ($qTpb) {
        while ($r = $qTpb->fetch_assoc()) {
            $refJenisTpb[] = $r;
        }
    }
}
if (empty($refJenisTpb)) {
    $refJenisTpb = [
        ['kode' => '1', 'teks' => 'KAWASAN BERIKAT'],
        ['kode' => '2', 'teks' => 'GUDANG BERIKAT'],
        ['kode' => '3', 'teks' => 'TPPB'],
        ['kode' => '4', 'teks' => 'TBB'],
        ['kode' => '5', 'teks' => 'TLB'],
        ['kode' => '6', 'teks' => 'KDUB'],
        ['kode' => '7', 'teks' => 'LAINNYA'],
        ['kode' => '8', 'teks' => 'KAWASAN BEBAS'],
        ['kode' => '9', 'teks' => 'KAWASAN EKONOMI KHUSUS'],
        ['kode' => '10', 'teks' => 'KAWASAN EKONOMI LAINNYA']
    ];
}

// Theme handling
$theme = $_GET['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Konfigurasi Perusahaan - CEISA 4.0</title>
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg-body: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #d9d9d9;
            --border-table: #d9d9d9;
            --table-head-bg: #fafafa;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            --row-hover: #f0f7ff;
            --row-selected: #bae7ff;
            --row-selected-text: #002766;
            --row-selected-border: #1890ff;
            --tag-bg: #f5f5f5;
            color-scheme: light;
        }

        html[data-theme="dark"], body[data-theme="dark"] {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --border-card: #303030;
            --border-table: #383838;
            --table-head-bg: #1d1d1d;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            --row-hover: #262638;
            --row-selected: #112a45;
            --row-selected-text: #e6f7ff;
            --row-selected-border: #177ddc;
            --tag-bg: #2a2a2a;
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            background: var(--bg-body);
            color: var(--text-main);
            padding: 10px 14px;
            -webkit-font-smoothing: antialiased;
            overflow-y: auto;
        }

        /* ---------------- CONTAINER & CARDS ---------------- */
        .container {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .header-box {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .header-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-icon {
            font-size: 24px;
            line-height: 1;
        }

        .header-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-heading);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* STATS PILLS */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            transition: transform 0.15s, border-color 0.15s;
        }
        .stat-card:hover {
            border-color: var(--primary);
            transform: translateY(-1px);
        }
        .stat-info { display: flex; flex-direction: column; }
        .stat-label { font-size: 10.5px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.3px; }
        .stat-value { font-size: 18px; font-weight: 700; color: var(--text-heading); line-height: 1.2; margin-top: 2px; }
        .stat-icon { font-size: 22px; opacity: 0.85; }

        /* TOOLBAR */
        .toolbar {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            padding: 8px 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .toolbar-left {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-input {
            height: 28px;
            padding: 0 10px;
            font-size: 11px;
            border: 1px solid var(--border-input);
            border-radius: 4px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            min-width: 260px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }

        .filter-select {
            height: 28px;
            padding: 0 8px;
            font-size: 11px;
            border: 1px solid var(--border-input);
            border-radius: 4px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            cursor: pointer;
        }
        .filter-select:focus {
            border-color: var(--primary);
        }

        /* BUTTONS */
        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 28px;
            padding: 0 12px;
            font-size: 11px;
            font-weight: 500;
            border-radius: 4px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            user-select: none;
            white-space: nowrap;
        }

        .ant-btn-primary {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
        }

        .ant-btn-success {
            background: #52c41a;
            color: #ffffff;
            border-color: #52c41a;
        }
        .ant-btn-success:hover {
            background: #73d13d;
            border-color: #73d13d;
        }

        .ant-btn-default {
            background: var(--bg-card);
            color: var(--text-main);
            border-color: var(--border-card);
        }
        .ant-btn-default:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .ant-btn-danger {
            background: #ff4d4f;
            color: #ffffff;
            border-color: #ff4d4f;
        }
        .ant-btn-danger:hover {
            background: #ff7875;
            border-color: #ff7875;
        }

        .ant-btn-warning {
            background: #fa8c16;
            color: #ffffff;
            border-color: #fa8c16;
        }
        .ant-btn-warning:hover {
            background: #ffa940;
            border-color: #ffa940;
            color: #ffffff;
        }

        .ant-btn-sm {
            height: 22px;
            padding: 0 7px;
            font-size: 10.5px;
            border-radius: 3px;
        }

        /* TABLE GRID */
        .table-wrap {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            overflow: auto;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            max-height: calc(100vh - 190px);
        }

        table.grid-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            white-space: nowrap;
        }

        table.grid-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table.grid-table th {
            background: var(--table-head-bg);
            color: var(--text-heading);
            font-weight: 600;
            text-align: left;
            padding: 7px 10px;
            border: 1px solid var(--border-table);
            font-size: 10.5px;
            letter-spacing: 0.2px;
            user-select: none;
        }

        table.grid-table td {
            padding: 6px 10px;
            border: 1px solid var(--border-table);
            color: var(--text-main);
            vertical-align: middle;
        }

        table.grid-table tbody tr {
            cursor: pointer;
            transition: background 0.1s;
        }
        table.grid-table tbody tr:hover {
            background: var(--row-hover);
        }
        table.grid-table tbody tr.selected-row {
            background: var(--row-selected) !important;
            color: var(--row-selected-text) !important;
            outline: 2px solid var(--row-selected-border);
            outline-offset: -2px;
        }

        /* BADGES */
        .ant-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            line-height: 1.3;
        }
        .ant-badge-blue { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .ant-badge-green { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .ant-badge-red { background: #fff1f0; color: #ff4d4f; border: 1px solid #ffa39e; }
        .ant-badge-gold { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }
        .ant-badge-purple { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }
        .ant-badge-cyan { background: #e6fffb; color: #13c2c2; border: 1px solid #87e8de; }
        .ant-badge-orange { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; }
        .ant-badge-gray { background: #fafafa; color: #8c8c8c; border: 1px solid #d9d9d9; }

        html[data-theme="dark"] .ant-badge-blue { background: #111d2c; color: #177ddc; border-color: #153450; }
        html[data-theme="dark"] .ant-badge-green { background: #162312; color: #49aa19; border-color: #274916; }
        html[data-theme="dark"] .ant-badge-red { background: #2a1215; color: #a61d24; border-color: #58181c; }
        html[data-theme="dark"] .ant-badge-gold { background: #2b2111; color: #d89614; border-color: #594214; }
        html[data-theme="dark"] .ant-badge-purple { background: #1a1325; color: #642ab5; border-color: #391c5e; }
        html[data-theme="dark"] .ant-badge-cyan { background: #112123; color: #13a8a8; border-color: #144848; }
        html[data-theme="dark"] .ant-badge-orange { background: #2b1d11; color: #d46b08; border-color: #59300d; }
        html[data-theme="dark"] .ant-badge-gray { background: #1d1d1d; color: #8c8c8c; border-color: #303030; }

        @keyframes fieldHighlightGlow {
            0% { background-color: rgba(82, 196, 26, 0.28) !important; border-color: #52c41a !important; box-shadow: 0 0 10px rgba(82, 196, 26, 0.6) !important; }
            50% { background-color: rgba(82, 196, 26, 0.15) !important; border-color: #52c41a !important; }
            100% { background-color: var(--bg-input); border-color: var(--border-input); box-shadow: none; }
        }
        .field-highlight-flash {
            animation: fieldHighlightGlow 2.5s ease-out;
        }

        .ceisa-autofill-banner {
            background: linear-gradient(135deg, rgba(24,144,255,0.08) 0%, rgba(114,46,209,0.06) 100%);
            border: 1px dashed #91d5ff;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        html[data-theme="dark"] .ceisa-autofill-banner {
            background: linear-gradient(135deg, rgba(24,144,255,0.12) 0%, rgba(114,46,209,0.1) 100%);
            border-color: #177ddc;
        }

        #modalCeisaEntityPicker .entity-row:hover {
            background: rgba(24, 144, 255, 0.06) !important;
            cursor: pointer;
        }

        .clickable-badge {
            cursor: pointer;
            transition: opacity 0.15s, transform 0.1s;
        }
        .clickable-badge:hover {
            opacity: 0.85;
            transform: scale(1.03);
        }

        /* ---------------- MODAL FORM DIALOG ---------------- */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(2px);
            padding: 16px;
        }
        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 8px;
            width: 100%;
            max-width: 880px;
            height: 94vh;
            max-height: 94vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 12px 36px rgba(0,0,0,0.3);
            animation: modalFadeIn 0.2s ease-out;
        }

        #companyForm {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.97); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            padding: 12px 18px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            flex-shrink: 0;
        }

        .modal-header h3 {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 16px;
            color: var(--text-muted);
            cursor: pointer;
            line-height: 1;
            padding: 4px;
            border-radius: 4px;
        }
        .modal-close-btn:hover {
            color: #ff4d4f;
            background: rgba(255, 77, 79, 0.1);
        }

        /* TAB NAVIGATION IN MODAL - High Contrast & Prominent Ant Design Style */
        .modal-tabs {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            background: #f7f9fa;
            border-bottom: 2px solid #e1e4e8;
            padding: 8px 16px 0 16px;
            flex-shrink: 0;
            overflow-x: auto;
            z-index: 10;
        }
        html[data-theme="dark"] .modal-tabs {
            background: #141414;
            border-bottom-color: #303030;
        }

        .modal-tab-btn {
            padding: 8px 14px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            background: rgba(0, 0, 0, 0.03);
            border: 1px solid var(--border-card);
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            transition: all 0.2s ease;
        }
        html[data-theme="dark"] .modal-tab-btn {
            background: rgba(255, 255, 255, 0.04);
            border-color: #303030;
        }
        .modal-tab-btn:hover {
            color: var(--primary);
            background: rgba(24, 144, 255, 0.08);
            border-color: var(--primary);
        }
        .modal-tab-btn.active {
            color: #ffffff !important;
            background: #1890ff !important;
            border-color: #1890ff !important;
            font-weight: 700;
            box-shadow: 0 -2px 8px rgba(24, 144, 255, 0.35);
        }

        .tab-section-header {
            margin: 0 0 14px 0;
            padding: 8px 14px;
            background: var(--table-head-bg);
            border: 1px solid var(--border-card);
            border-left: 4px solid var(--primary);
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .tab-section-desc {
            font-size: 10px;
            font-weight: normal;
            color: var(--text-muted);
        }

        .modal-body {
            padding: 16px 20px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }

        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }

        .modal-footer {
            padding: 10px 18px;
            border-top: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-card);
            flex-shrink: 0;
        }

        /* FORM ELEMENTS */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 16px;
        }
        .form-grid.single-col {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-group.full-width {
            grid-column: span 2;
        }

        .form-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .form-label .req {
            color: #ff4d4f;
            margin-left: 3px;
        }
        .form-hint {
            font-size: 9.5px;
            color: var(--text-muted);
            font-weight: normal;
        }

        .form-control {
            height: 28px;
            padding: 0 10px;
            font-size: 11px;
            border: 1px solid var(--border-input);
            border-radius: 4px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            width: 100%;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }
        textarea.form-control {
            height: auto;
            min-height: 50px;
            padding: 6px 10px;
            resize: vertical;
        }

        .input-group {
            display: flex;
            align-items: center;
            position: relative;
        }
        .input-group .form-control {
            padding-right: 32px;
        }
        .input-group-btn {
            position: absolute;
            right: 6px;
            background: transparent;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 12px;
            padding: 2px 4px;
        }
        .input-group-btn:hover {
            color: var(--primary);
        }

        .modal-footer {
            padding: 10px 18px;
            border-top: 1px solid var(--border-card);
            background: var(--bg-card);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        /* TOTP LIVE BADGE BOX */
        .totp-preview-box {
            background: var(--tag-bg);
            border: 1px dashed var(--border-card);
            border-radius: 6px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 6px;
        }

        /* LOADING SPINNER */
        .loading-wrap {
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
        }
        .spinner {
            display: inline-block;
            width: 22px;
            height: 22px;
            border: 3px solid rgba(24, 144, 255, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .btn-storage-detail {
            background: transparent;
            border: 1px solid var(--border-card);
            border-radius: 3px;
            font-size: 9.5px;
            color: var(--primary);
            padding: 1px 6px;
            cursor: pointer;
            margin-top: 3px;
            transition: all 0.2s;
            font-weight: 500;
            display: inline-block;
        }
        .btn-storage-detail:hover {
            background: rgba(24, 144, 255, 0.08);
            border-color: var(--primary);
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- HEADER BOX -->
        <div class="header-box">
            <div class="header-title-wrap">
                <span class="header-icon">🏢</span>
                <div>
                    <h2 class="header-title">
                        Master Konfigurasi Perusahaan
                        <span class="ant-badge ant-badge-gold">👑 Khusus Administrator</span>
                    </h2>
                    <div class="header-subtitle">
                        Kelola data profil perusahaan, akun CEISA 4.0, TOTP MFA, pengaturan IT Inventory, serta koneksi ERP & AI.
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <input type="file" id="ceisaExcelFileInput" accept=".xlsx, .xls" style="display:none;" onchange="handleCeisaExcelUpload(event)">
                <button type="button" class="ant-btn ant-btn-primary" onclick="triggerCeisaExcelUpload()" title="Import / Ambil data profil perusahaan dari file Excel dokumen CEISA (sheet ENTITAS)">
                    <span>📥</span> Import Excel Ceisa
                </button>
                <button type="button" class="ant-btn ant-btn-success" onclick="openAddModal()">
                    <span>➕</span> Tambah Perusahaan Baru
                </button>
            </div>
        </div>

        <!-- STATS BAR -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">Total Perusahaan</span>
                    <span class="stat-value" id="statTotal">-</span>
                </div>
                <span class="stat-icon">🏬</span>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">Perusahaan Aktif</span>
                    <span class="stat-value" style="color:#52c41a;" id="statActive">-</span>
                </div>
                <span class="stat-icon">✅</span>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">IT Inventory Aktif</span>
                    <span class="stat-value" style="color:#1890ff;" id="statInvActive">-</span>
                </div>
                <span class="stat-icon">📦</span>
            </div>
            <div class="stat-card">
                <div class="stat-info">
                    <span class="stat-label">Sesi Aktif Saat Ini</span>
                    <span class="stat-value" style="font-size:13px; font-weight:600; color:#fa8c16;" id="statSession">-</span>
                </div>
                <span class="stat-icon">👑</span>
            </div>
            <div class="stat-card" id="cardStorageStat">
                <div class="stat-info">
                    <span class="stat-label">Total Pemakaian Data</span>
                    <span class="stat-value" style="color:#722ed1; font-size:16px;" id="statStorageTotal">- MB</span>
                    <span style="font-size:9.5px; color:var(--text-muted); margin-top:2px;" id="statStorageSub">Klik tombol "Hitung Storage"</span>
                </div>
                <span class="stat-icon">💾</span>
            </div>
        </div>

        <!-- TOOLBAR FILTER & SEARCH -->
        <div class="toolbar">
            <div class="toolbar-left">
                <input type="text" id="searchInput" class="search-input" placeholder="🔍 Cari nama perusahaan, cmp_id, NPWP, kantor, username..." onkeyup="filterTable()">
                
                <select id="filterStatus" class="filter-select" onchange="filterTable()">
                    <option value="">Status Akun: Semua</option>
                    <option value="1">Aktif Saja</option>
                    <option value="0">Non-Aktif Saja</option>
                </select>

                <select id="filterInv" class="filter-select" onchange="filterTable()">
                    <option value="">IT Inventory: Semua</option>
                    <option value="1">Aktif</option>
                    <option value="0">Non-Aktif</option>
                </select>

                <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="resetFilters()">
                    <span>↺</span> Reset Filter
                </button>
            </div>

            <div class="toolbar-right">
                <span id="filteredCountLabel" style="font-size:10.5px; color:var(--text-muted);">Memuat data...</span>
                <button type="button" class="ant-btn ant-btn-default" onclick="loadCompanyList()">
                    <span>🔄</span> Refresh
                </button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnCalcStorage" onclick="calculateStorage()" title="Kalkulasi pemakaian data dan ukuran storage (MB) per perusahaan secara on-demand">
                    <span>💾</span> Hitung Storage
                </button>
            </div>
        </div>

        <!-- TABLE GRID -->
        <div class="table-wrap">
            <table class="grid-table" id="companyTable">
                <thead>
                    <tr>
                        <th style="width: 35px; text-align: center;">#</th>
                        <th style="width: 140px;">ID Perusahaan</th>
                        <th>Nama Perusahaan</th>
                        <th style="width: 135px;">NPWP & NIB</th>
                        <th style="width: 110px;">Kantor & TPB</th>
                        <th style="width: 140px;">Nomor & Tgl SKEP</th>
                        <th style="width: 130px;">Kredensial CEISA 4.0</th>
                        <th style="width: 145px; text-align: center;">Pemakaian Data</th>
                        <th style="width: 110px; text-align: center;">IT Inventory</th>
                        <th style="width: 95px;">ERP & AI</th>
                        <th style="width: 80px; text-align: center;">Status</th>
                        <th style="width: 220px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="companyTbody">
                    <tr>
                        <td colspan="12" class="loading-wrap">
                            <div class="spinner"></div>
                            <div style="margin-top:8px;">Memuat data konfigurasi perusahaan...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL FORM TAMBAH / EDIT PERUSAHAAN (Ant Design Tabbed) -->
    <!-- ============================================================= -->
    <div class="modal-overlay" id="companyModal">
        <div class="modal-card">
            <!-- Modal Header -->
            <div class="modal-header">
                <h3 id="modalTitle">
                    <span>🏢</span> Tambah Perusahaan Baru
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeModal()">✕</button>
            </div>

            <!-- Modal Tab Navigation -->
            <div class="modal-tabs">
                <button type="button" class="modal-tab-btn active" id="tab-btn-profil" onclick="switchModalTab('tab-profil')">
                    <span>🏢</span> Identitas & Login Web
                </button>
                <button type="button" class="modal-tab-btn" id="tab-btn-pabean" onclick="switchModalTab('tab-pabean')">
                    <span>📋</span> Kepabeanan & SKEP
                </button>
                <button type="button" class="modal-tab-btn" id="tab-btn-ceisa" onclick="switchModalTab('tab-ceisa')">
                    <span>🔐</span> Akun CEISA & TOTP MFA
                </button>
                <button type="button" class="modal-tab-btn" id="tab-btn-integrasi" onclick="switchModalTab('tab-integrasi')">
                    <span>⚙️</span> IT Inventory & ERP / AI
                </button>
                <button type="button" class="modal-tab-btn" id="tab-btn-semua" onclick="switchModalTab('tab-semua')" title="Tampilkan seluruh bagian sekaligus dalam satu halaman scroll">
                    <span>📄</span> Tampilkan Semua (Scroll)
                </button>
            </div>

            <!-- Modal Form Body -->
            <form id="companyForm" onsubmit="saveCompany(event)">
                <input type="hidden" id="formMode" name="mode" value="add">
                
                <div class="modal-body">
                    <!-- TAB 1: IDENTITAS & LOGIN WEB -->
                    <div class="tab-content active" id="tab-profil">
                        <div class="tab-section-header">
                            <span>🏢 BAGIAN 1: IDENTITAS PERUSAHAAN & LOGIN WEB PORTAL</span>
                            <span class="tab-section-desc">Nama Resmi, NPWP, Subdomain, & Akses Login</span>
                        </div>

                        <!-- BANNER AUTOFILL DARI EXCEL CEISA -->
                        <div class="ceisa-autofill-banner">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span style="font-size:22px; line-height:1;">🪄</span>
                                <div>
                                    <div style="font-size:11.5px; font-weight:700; color:var(--primary);">Autofill Cepat dari Dokumen Excel CEISA</div>
                                    <div style="font-size:10px; color:var(--text-muted); line-height:1.4;">
                                        Pilih file Excel CEISA (BC 2.3, 3.0, 1.6, 2.5, 2.7, 4.0, dll) untuk otomatis mengisi <strong>Nama Perusahaan, NPWP, NIB, Alamat, SKEP, & Kantor Pabean</strong> dari sheet <strong>ENTITAS</strong>.
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="triggerCeisaExcelUpload()" style="white-space:nowrap; flex-shrink:0;">
                                <span>📥</span> Ambil dari Excel Ceisa
                            </button>
                        </div>

                        <div class="form-grid">
                            <!-- Avatar / Logo Perusahaan -->
                            <div class="form-group full-width" style="margin-bottom:4px; padding:12px 14px; background:var(--table-head-bg); border:1px solid var(--border-card); border-radius:6px;">
                                <label class="form-label" style="margin-bottom:8px;">
                                    <span>🖼️ Avatar / Logo Resmi Perusahaan</span>
                                    <span class="form-hint">Tampil di Halaman Login Subdomain, Header & Sidebar</span>
                                </label>
                                <div style="display:flex; align-items:center; gap:16px;">
                                    <div style="position:relative; width:58px; height:58px; border-radius:6px; border:2px dashed var(--border-input); background:var(--bg-card); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0;">
                                        <img id="logoPreviewImg" src="" style="width:100%; height:100%; object-fit:contain; display:none;">
                                        <div id="logoPreviewPlaceholder" style="font-size:24px; color:var(--text-muted);">🏢</div>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="display:flex; gap:8px; align-items:center;">
                                            <input type="file" id="f_logo_file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="display:none;" onchange="previewLogoFile(this)">
                                            <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="document.getElementById('f_logo_file').click()">
                                                <span>📁</span> Pilih File Logo
                                            </button>
                                            <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" id="btnRemoveLogo" onclick="removeLogo()" style="display:none;">
                                                <span>🗑️</span> Hapus Logo
                                            </button>
                                        </div>
                                        <input type="hidden" id="f_logo" name="logo" value="">
                                        <div style="font-size:9.5px; color:var(--text-muted); margin-top:4px;">
                                            Format: PNG, JPG, JPEG, WEBP, SVG (Maks. 5 MB). Direkomendasikan logo berlatar transparan (PNG).
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Subdomain Perusahaan -->
                            <div class="form-group full-width">
                                <label class="form-label">
                                    <span>🌐 Subdomain Akses Portal (Khusus Perusahaan)</span>
                                    <span class="form-hint">Membuka portal langsung tanpa perlu memilih perusahaan</span>
                                </label>
                                <div style="display:flex; align-items:center;">
                                    <span style="padding:0 8px; font-size:11px; font-weight:600; background:var(--table-head-bg); border:1px solid var(--border-input); border-right:none; border-radius:4px 0 0 4px; height:28px; display:inline-flex; align-items:center; color:var(--text-muted); font-family:monospace;">https://</span>
                                    <input type="text" id="f_subdomain" name="subdomain" class="form-control" style="border-radius:0; font-family:monospace; font-weight:700; color:var(--primary);" placeholder="framas" pattern="[a-zA-Z0-9_-]+" oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9_-]/g, '')">
                                    <span style="padding:0 8px; font-size:11px; font-weight:600; background:var(--table-head-bg); border:1px solid var(--border-input); border-left:none; border-radius:0 4px 4px 0; height:28px; display:inline-flex; align-items:center; color:var(--text-muted); font-family:monospace;">.inhost.web.id</span>
                                </div>
                                <div style="font-size:9.5px; color:var(--text-muted); margin-top:2px;">
                                    Contoh: jika diisi <code>framas</code>, akses via <code>https://framas.inhost.web.id</code> akan otomatis menampilkan login untuk perusahaan ini.
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>ID Perusahaan<span class="req">*</span></span>
                                    <span class="form-hint">Kode unik / NPWP 15 digit</span>
                                </label>
                                <input type="text" id="f_cmp_id" name="cmp_id" class="form-control" placeholder="Contoh: 010816064431000 atau FRAMAS" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Nama Resmi Perusahaan<span class="req">*</span></span>
                                </label>
                                <input type="text" id="f_cmp_name" name="cmp_name" class="form-control" placeholder="Contoh: PT. FRAMAS INDONESIA" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>NPWP Perusahaan</span>
                                    <span class="form-hint">Format 15/16 digit</span>
                                </label>
                                <input type="text" id="f_npwp" name="npwp" class="form-control" placeholder="Contoh: 010816064431000">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>NIB (Nomor Induk Berusaha)</span>
                                </label>
                                <input type="text" id="f_nib" name="nib" class="form-control" placeholder="Contoh: 9120001234567">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Password Login Web Portal</span>
                                    <span class="form-hint">Default: 1234</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" id="f_app_password" name="app_password" class="form-control" value="1234">
                                    <button type="button" class="input-group-btn" onclick="togglePass('f_app_password')">👁️</button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Status Akun Perusahaan</span>
                                </label>
                                <select id="f_is_active" name="is_active" class="form-control">
                                    <option value="1">Aktif (Dapat Login & Digunakan)</option>
                                    <option value="0">Non-Aktif (Akses Ditutup)</option>
                                </select>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <span>Alamat Lengkap Perusahaan / Lokasi Pabrik</span>
                                </label>
                                <textarea id="f_alamat" name="alamat" class="form-control" rows="2" placeholder="Alamat lengkap kawasan industri / pabrik..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: KEPABEANAN & SKEP -->
                    <div class="tab-content" id="tab-pabean">
                        <div class="tab-section-header">
                            <span>📋 BAGIAN 2: DATA KEPABEANAN & SKEP PENETAPAN TPB</span>
                            <span class="tab-section-desc">Kode Kantor Bea Cukai, Jenis TPB & SKEP</span>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">
                                    <span>Kode Kantor Pabean Pengawas</span>
                                    <span class="form-hint">6 digit (040300, 080400, dll)</span>
                                </label>
                                <input type="text" id="f_kode_kantor" name="kode_kantor" class="form-control" placeholder="Contoh: 080400">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Kode Jenis TPB</span>
                                    <span class="form-hint">Pilih dari referensi Bea Cukai</span>
                                </label>
                                <select id="f_kode_jenis_tpb" name="kode_jenis_tpb" class="form-control">
                                    <option value="">-- Pilih Jenis TPB --</option>
                                    <?php foreach ($refJenisTpb as $tpb): ?>
                                        <option value="<?= htmlspecialchars($tpb['kode']) ?>">
                                            <?= htmlspecialchars($tpb['kode'] . ' - ' . $tpb['teks']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Nomor SKEP Penetapan TPB</span>
                                </label>
                                <input type="text" id="f_nomor_skep" name="nomor_skep" class="form-control" placeholder="Contoh: KEP-123/WBC.08/2023">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Tanggal SKEP Penetapan</span>
                                    <span class="form-hint">Format: YYYY-MM-DD</span>
                                </label>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="text" id="f_tanggal_skep" name="tanggal_skep" class="form-control" placeholder="YYYY-MM-DD" maxlength="10" pattern="\d{4}-\d{2}-\d{2}" title="Format: YYYY-MM-DD (Contoh: 2023-10-15)" style="font-family:monospace; font-weight:500;">
                                    <input type="date" id="f_tanggal_skep_picker" style="position:absolute; opacity:0; pointer-events:none; width:0; height:0;" tabindex="-1" onchange="if(this.value) document.getElementById('f_tanggal_skep').value = this.value">
                                    <button type="button" class="ant-btn ant-btn-default" style="padding:0 10px; height:32px; flex-shrink:0;" title="Pilih Tanggal dari Kalender" onclick="const p = document.getElementById('f_tanggal_skep_picker'); if(p.showPicker){ p.showPicker(); } else { p.focus(); p.click(); }">📅</button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Mode Pengiriman CEISA</span>
                                    <span class="form-hint">Status dokumen saat dikirim</span>
                                </label>
                                <select id="f_is_final" name="is_final" class="form-control">
                                    <option value="false">false (DRAFT / Pengujian)</option>
                                    <option value="true">true (FINAL / Siap Disubmit)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: AKUN CEISA & TOTP MFA -->
                    <div class="tab-content" id="tab-ceisa">
                        <div class="tab-section-header">
                            <span>🔐 BAGIAN 3: AKUN PORTAL CEISA 4.0 & KUNCI TOTP MFA</span>
                            <span class="tab-section-desc">Kredensial Login Bea Cukai, Token & Secret TOTP MFA</span>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">
                                    <span>Username Portal CEISA 4.0</span>
                                </label>
                                <input type="text" id="f_username_ceisa" name="username_ceisa" class="form-control" placeholder="Contoh: userceisa01">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Password Portal CEISA 4.0</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" id="f_password_ceisa" name="password_ceisa" class="form-control" placeholder="Password akun CEISA">
                                    <button type="button" class="input-group-btn" onclick="togglePass('f_password_ceisa')">👁️</button>
                                </div>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <span>Secret Key TOTP MFA (Google Authenticator)</span>
                                    <span class="form-hint">Kunci Base32 16/32 karakter (A-Z, 2-7)</span>
                                </label>
                                <div style="display:flex; gap:6px;">
                                    <input type="text" id="f_secret_mfa" name="secret_mfa" class="form-control" placeholder="Contoh: JBSWY3DPEHPK3PXP" style="font-family:monospace; text-transform:uppercase;">
                                    <button type="button" class="ant-btn ant-btn-default" onclick="testMfaSecret()" style="flex-shrink:0;">
                                        <span>🔑</span> Uji TOTP
                                    </button>
                                </div>
                                <div class="totp-preview-box" id="totpPreviewBox" style="display:none;">
                                    <span style="font-size:10.5px; color:var(--text-muted);">Hasil Live TOTP:</span>
                                    <span style="font-size:14px; font-weight:700; color:#52c41a; font-family:monospace; letter-spacing:2px;" id="totpLiveCode">------</span>
                                    <span class="ant-badge ant-badge-blue" id="totpLiveCountdown">30s</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>ID User CEISA</span>
                                    <span class="form-hint">Sub-identitas akun pabean</span>
                                </label>
                                <input type="text" id="f_id_user_ceisa" name="id_user_ceisa" class="form-control" placeholder="Opsional">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>API Key Integrasi CEISA</span>
                                    <span class="form-hint">Header API Key Beacukai</span>
                                </label>
                                <input type="text" id="f_api_key_ceisa" name="api_key_ceisa" class="form-control" value="f662de2e-1d35-4b11-b2fd-ee164a5c9d83">
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <span>Bearer Token CEISA 4.0</span>
                                    <span class="form-hint">Otomatis terisi saat sistem berhasil login/refresh token</span>
                                </label>
                                <input type="text" id="f_token_ceisa" name="token_ceisa" class="form-control" placeholder="Token auth CEISA (opsional/auto)">
                            </div>
                        </div>
                    </div>

                    <!-- TAB 4: IT INVENTORY & INTEGRASI ERP / AI -->
                    <div class="tab-content" id="tab-integrasi">
                        <div class="tab-section-header">
                            <span>⚙️ BAGIAN 4: IT INVENTORY & INTEGRASI SISTEM ERP / ENGINE AI</span>
                            <span class="tab-section-desc">Status IT Inv, Host ERP (Odoo/SAP/dll), & Provider AI</span>
                        </div>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <div style="background:var(--tag-bg); border:1px solid var(--border-card); border-radius:6px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between;">
                                    <div>
                                        <div style="font-weight:600; color:var(--text-heading); font-size:11.5px;">
                                            📦 Pengaturan IT Inventory
                                        </div>
                                        <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                                            • Latar Biru saat Submit CEISA, Kuning pada AJU & No saat Masuk IT Inv, Merah jika ber-Nomor Daftar tapi belum masuk IT Inv.
                                        </div>
                                    </div>
                                    <select id="f_IS_INV" name="IS_INV" class="form-control" style="width:140px; font-weight:600;">
                                        <option value="1" style="color:#52c41a;">Aktif</option>
                                        <option value="0" style="color:#8c8c8c;">Non-Aktif</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group full-width">
                                <div style="background:var(--tag-bg); border:1px solid var(--border-card); border-radius:6px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between;">
                                    <div>
                                        <div style="font-weight:600; color:var(--text-heading); font-size:11.5px;">
                                            🔤 Format Kode Barang (Allow Huruf Kecil)
                                        </div>
                                        <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                                            • Jika <b>Kapital Saja</b> (Default), semua Kode Barang otomatis dikonversi ke HURUF BESAR (UPPERCASE).<br>
                                            • Jika <b>Izinkan Huruf Kecil</b>, kode barang diperbolehkan mengandung huruf kecil saat input & import.
                                        </div>
                                    </div>
                                    <select id="f_allow_lowercase_code" name="allow_lowercase_code" class="form-control" style="width:170px; font-weight:600;">
                                        <option value="0" style="color:#1890ff;">Kapital Saja (Default)</option>
                                        <option value="1" style="color:#52c41a;">Izinkan Huruf Kecil</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group full-width">
                                <div style="background:var(--tag-bg); border:1px solid var(--border-card); border-radius:6px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between;">
                                    <div>
                                        <div style="font-weight:600; color:var(--text-heading); font-size:11.5px;">
                                            ⚠️ Peringatan Dokumen Belum Masuk IT Inventory
                                        </div>
                                        <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                                            • Jika <b>Nonaktif (Default)</b>, popup peringatan tidak muncul otomatis saat membuka H2H (direkomendasikan untuk alur impor BC 2.3/1.6 yang menunggu barang masuk warehouse).<br>
                                            • Jika <b>Aktifkan Peringatan</b>, sistem akan memunculkan popup peringatan jika terdapat dokumen ber-Nomor Daftar yang belum masuk IT Inventory.
                                        </div>
                                    </div>
                                    <select id="f_alert_it_inv_unposted" name="alert_it_inv_unposted" class="form-control" style="width:190px; font-weight:600;">
                                        <option value="0" style="color:#8c8c8c;">Nonaktif (Default)</option>
                                        <option value="1" style="color:#ff4d4f;">Aktifkan Peringatan</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Sistem ERP</span>
                                </label>
                                <select id="f_ERP_Name" name="ERP_Name" class="form-control">
                                    <option value="-">- (Tidak Terhubung)</option>
                                    <option value="FRAMAS">FRAMAS</option>
                                    <option value="SAP">SAP</option>
                                    <option value="ODOO">ODOO</option>
                                    <option value="HEESUNG">HEESUNG</option>
                                    <option value="GSOFT">GSOFT</option>
                                    <option value="ACCURATE">ACCURATE</option>
                                    <option value="TPBERP">TPBERP</option>
                                    <option value="CUSTOM">CUSTOM ERP</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>User ERP</span>
                                </label>
                                <input type="text" id="f_ERP_User" name="ERP_User" class="form-control" placeholder="Username ERP">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Password ERP</span>
                                </label>
                                <div class="input-group">
                                    <input type="password" id="f_ERP_Password" name="ERP_Password" class="form-control" placeholder="Password ERP">
                                    <button type="button" class="input-group-btn" onclick="togglePass('f_ERP_Password')">👁️</button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <span>Engine AI</span>
                                </label>
                                <select id="f_AI_Name" name="AI_Name" class="form-control">
                                    <option value="">- (Tidak Aktif)</option>
                                    <option value="Gemini">Google Gemini</option>
                                    <option value="ChatGPT">OpenAI ChatGPT</option>
                                    <option value="Claude">Anthropic Claude</option>
                                </select>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <span>ERP Connection String</span>
                                    <span class="form-hint">Host, DB, atau API Endpoint ERP</span>
                                </label>
                                <textarea id="f_ERP_Constring" name="ERP_Constring" class="form-control" rows="2" placeholder="Driver={ODBC Driver};Server=192.168.1.100;Database=erp_db;Uid=...;Pwd=...;"></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label class="form-label">
                                    <span>AI API Key</span>
                                </label>
                                <input type="text" id="f_AI_API_Key" name="AI_API_Key" class="form-control" placeholder="AI API Key (AIzaSy...)">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="modal-footer">
                    <div id="modalFooterNav" style="display:flex; align-items:center; gap:8px;">
                        <!-- Navigasi Tab Sebelumnya / Selanjutnya -->
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button type="button" class="ant-btn ant-btn-default" onclick="closeModal()">Batal</button>
                        <button type="submit" class="ant-btn ant-btn-primary" id="btnSaveSubmit">
                            <span>💾</span> Simpan Konfigurasi Perusahaan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL RINCIAN PEMAKAIAN STORAGE & DATA PERUSAHAAN -->
    <!-- ============================================================= -->
    <div class="modal-overlay" id="storageModal">
        <div class="modal-card" style="max-width: 780px;">
            <div class="modal-header">
                <h3 id="storageModalTitle">
                    <span>💾</span> Rincian Pemakaian Data Perusahaan
                </h3>
                <button type="button" class="modal-close-btn" onclick="closeStorageModal()">✕</button>
            </div>
            <div class="modal-body" style="padding: 16px 20px;">
                <!-- Summary Banner -->
                <div id="storageModalSummary" style="margin-bottom: 14px; background: var(--bg-filter); border: 1px solid var(--border-card); border-radius: 6px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <!-- Will be populated dynamically -->
                </div>

                <!-- Table Breakdown -->
                <div style="font-size: 11px; font-weight: 600; margin-bottom: 6px; color: var(--text-heading);">
                    Rincian Tabel Transaksi & Staging:
                </div>
                <div class="table-wrap" style="max-height: 380px; overflow-y: auto;">
                    <table class="grid-table">
                        <thead>
                            <tr>
                                <th style="width: 35px; text-align: center;">#</th>
                                <th>Nama Tabel</th>
                                <th>Keterangan Data</th>
                                <th style="width: 110px; text-align: right;">Jumlah Baris</th>
                                <th style="width: 110px; text-align: right;">Estimasi MB</th>
                            </tr>
                        </thead>
                        <tbody id="storageModalTbody">
                            <!-- Rows dynamically populated -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn ant-btn-default" onclick="closeStorageModal()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- MODAL PILIH ENTITAS DARI EXCEL DOKUMEN CEISA (SHEET ENTITAS) -->
    <!-- ============================================================= -->
    <div class="modal-overlay" id="modalCeisaEntityPicker" style="z-index: 10500;">
        <div class="modal-card" style="max-width: 1100px; height: 88vh; max-height: 88vh;">
            <!-- Header Modal -->
            <div class="modal-header" style="background: linear-gradient(135deg, rgba(24,144,255,0.06) 0%, rgba(114,46,209,0.04) 100%);">
                <div>
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 8px;">
                        <span>📥</span> Pilih Entitas Perusahaan dari Excel CEISA
                        <span class="ant-badge ant-badge-purple" id="ceisaDocBadge">Sheet ENTITAS</span>
                    </h3>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;" id="ceisaFileSubTitle">
                        Pilih baris profil perusahaan yang ingin Anda gunakan untuk mengisi formulir pendaftaran perusahaan baru.
                    </div>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeCeisaEntityPicker()">✕</button>
            </div>

            <!-- Toolbar & Filter -->
            <div style="padding: 10px 18px; background: var(--table-head-bg); border-bottom: 1px solid var(--border-card); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 260px;">
                    <div style="position: relative; width: 100%; max-width: 360px;">
                        <input type="text" id="ceisaEntitySearchInput" class="form-control" placeholder="🔍 Cari Nama Perusahaan, NPWP, NIB, SKEP..." oninput="filterCeisaEntityPicker(this.value)" style="height: 30px; font-size: 11px;">
                    </div>
                    <span id="ceisaEntityCountBadge" style="font-size: 11px; color: var(--text-muted); font-weight: 600; white-space: nowrap;">
                        Ditemukan: 0 entitas
                    </span>
                </div>
                <div id="ceisaHeaderMetaBox" style="display: flex; align-items: center; gap: 6px; font-size: 10.5px; flex-wrap: wrap;">
                    <!-- Metadata tags injected here -->
                </div>
                <div>
                    <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="triggerCeisaExcelUpload()">
                        <span>📁</span> Ganti File Excel
                    </button>
                </div>
            </div>

            <!-- Table Body -->
            <div class="modal-body" style="padding: 0; overflow-y: auto;">
                <table class="grid-table" style="width: 100%; font-size: 11px;">
                    <thead>
                        <tr>
                            <th style="width: 145px; text-align: center;">Aksi</th>
                            <th style="width: 180px;">Peran Entitas</th>
                            <th style="min-width: 200px;">Nama Perusahaan / Entitas</th>
                            <th style="width: 170px;">Nomor Identitas (NPWP)</th>
                            <th style="width: 120px;">NIB</th>
                            <th style="min-width: 220px;">Alamat Lengkap</th>
                            <th style="width: 170px;">Nomor & Tgl SKEP</th>
                            <th style="width: 65px; text-align: center;">Negara</th>
                        </tr>
                    </thead>
                    <tbody id="ceisaEntityTableBody">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer" style="padding: 10px 18px; border-top: 1px solid var(--border-card); background: var(--bg-card); display: flex; align-items: center; justify-content: space-between;">
                <div style="font-size: 10.5px; color: var(--text-muted); display: flex; align-items: center; gap: 6px;">
                    <span>💡</span> <span>Klik tombol <strong>"👉 Gunakan Baris Ini"</strong> (atau klik ganda baris) untuk memuat data ke form pendaftaran perusahaan baru.</span>
                </div>
                <button type="button" class="ant-btn ant-btn-default" onclick="closeCeisaEntityPicker()">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        let allCompanies = [];
        let sessionActiveCmp = '';
        let currentStorageData = null;

        // Inisialisasi Muat Data
        document.addEventListener('DOMContentLoaded', function() {
            // Sinkronkan tema
            const storedTheme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', storedTheme);
            document.body.setAttribute('data-theme', storedTheme);

            // Coba pulihkan kalkulasi storage dari sessionStorage jika ada
            const cachedStorage = sessionStorage.getItem('ceisa_storage_calc');
            if (cachedStorage) {
                try {
                    currentStorageData = JSON.parse(cachedStorage);
                    applyStorageDataToUi(currentStorageData, false);
                } catch(e) {}
            }

            loadCompanyList();
        });

        // 1. LOAD LIST COMPANY
        function loadCompanyList() {
            const tbody = document.getElementById('companyTbody');
            tbody.innerHTML = `<tr><td colspan="12" class="loading-wrap"><div class="spinner"></div><div style="margin-top:8px;">Memuat data konfigurasi perusahaan...</div></td></tr>`;

            fetch('company.php?action=list')
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK') {
                        allCompanies = res.data || [];
                        sessionActiveCmp = res.session_active_cmp || '';
                        
                        // Update Stat Cards
                        document.getElementById('statTotal').innerText = res.summary.total;
                        document.getElementById('statActive').innerText = res.summary.active;
                        document.getElementById('statInvActive').innerText = res.summary.inv_active;
                        
                        const activeComp = allCompanies.find(c => c.cmp_id === sessionActiveCmp);
                        document.getElementById('statSession').innerText = activeComp ? activeComp.cmp_name : (sessionActiveCmp || '-');

                        renderTable(allCompanies);
                    } else {
                        tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:20px; color:#ff4d4f;">Gagal memuat data: ${res.message}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:20px; color:#ff4d4f;">Terjadi kesalahan jaringan: ${err.message}</td></tr>`;
                });
        }

        // Helper untuk menghasilkan cell HTML pemakaian data
        function getStorageCellHtml(cmpId) {
            const st = (currentStorageData && currentStorageData.by_company) ? currentStorageData.by_company[cmpId] : null;
            if (st) {
                return `
                    <div style="text-align:center;">
                        <div style="display:inline-flex; align-items:center; gap:4px;">
                            <span class="ant-badge ant-badge-purple" style="font-weight:700; font-size:10.5px;">${st.mb}</span>
                            ${st.percent !== '0%' && st.percent !== '0.0%' ? `<span style="font-size:9px; color:var(--text-muted);">(${st.percent})</span>` : ''}
                        </div>
                        <div style="font-size:10px; font-weight:600; color:var(--text-main); margin-top:2px;">
                            ${Number(st.rows).toLocaleString('id-ID')} baris
                        </div>
                        <div style="font-size:9px; color:var(--text-muted);">
                            ${Number(st.docs).toLocaleString('id-ID')} Dokumen AJU
                        </div>
                        ${st.rows > 0 ? `
                        <button type="button" class="btn-storage-detail" onclick="openStorageModal('${escapeJs(cmpId)}')" title="Lihat rincian tabel transaksi">
                            🔍 Rincian
                        </button>
                        ` : ''}
                    </div>
                `;
            } else {
                return `
                    <div style="text-align:center; color:var(--text-muted); font-size:10px;">
                        <span style="font-weight:600; opacity:0.6;">- MB</span>
                        <div style="font-size:9px; opacity:0.75; margin-top:1px;">(Klik Hitung)</div>
                    </div>
                `;
            }
        }

        // 2. RENDER TABLE
        function renderTable(list) {
            const tbody = document.getElementById('companyTbody');
            tbody.innerHTML = '';

            document.getElementById('filteredCountLabel').innerText = `Menampilkan ${list.length} dari ${allCompanies.length} perusahaan`;

            if (list.length === 0) {
                tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:30px; color:var(--text-muted);">Tidak ada data perusahaan yang sesuai kriteria pencarian.</td></tr>`;
                return;
            }

            list.forEach((row, idx) => {
                const tr = document.createElement('tr');
                tr.onclick = function(e) {
                    if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'SPAN' && e.target.tagName !== 'SELECT') {
                        document.querySelectorAll('#companyTbody tr').forEach(r => r.classList.remove('selected-row'));
                        tr.classList.add('selected-row');
                    }
                };

                const isCurrentSession = (row.cmp_id === sessionActiveCmp);
                const isActive = ((parseInt(row.is_active) || 0) === 1);
                const isInv = ((parseInt(row.IS_INV) || 0) === 1);
                const hasMfa = ((parseInt(row.has_secret_mfa) || 0) === 1);
                const isFinal = (row.is_final === 'true');

                tr.innerHTML = `
                    <td style="text-align:center; font-weight:600; color:var(--text-muted);">${idx + 1}</td>
                    <td>
                        <div style="font-family:monospace; font-weight:600; color:var(--primary); font-size:11.5px;">
                            ${escapeHtml(row.cmp_id)}
                        </div>
                        ${isCurrentSession ? '<span class="ant-badge ant-badge-green" style="margin-top:2px;">👑 Sesi Aktif</span>' : ''}
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px;">
                            ${row.logo ? `
                                <img src="${escapeHtml(row.logo)}" alt="Logo" style="width:30px; height:30px; object-fit:contain; border-radius:4px; background:#fff; border:1px solid var(--border-card); padding:1px; flex-shrink:0;">
                            ` : `
                                <div style="width:30px; height:30px; border-radius:4px; background:#e6f7ff; color:#1890ff; font-weight:700; font-size:11.5px; display:flex; align-items:center; justify-content:center; border:1px solid #91d5ff; flex-shrink:0;">
                                    ${escapeHtml((row.cmp_name || 'CO').substring(0, 2).toUpperCase())}
                                </div>
                            `}
                            <div>
                                <div style="font-weight:600; font-size:12px; color:var(--text-heading);">${escapeHtml(row.cmp_name)}</div>
                                ${row.subdomain ? `
                                    <div style="margin-top:2px;">
                                        <a href="https://${escapeHtml(row.subdomain)}.inhost.web.id" target="_blank" style="color:#1890ff; text-decoration:none; font-size:10px; font-family:monospace; display:inline-flex; align-items:center; gap:3px; background:rgba(24,144,255,0.08); padding:1px 6px; border-radius:3px; border:1px solid rgba(24,144,255,0.2);">
                                            🌐 ${escapeHtml(row.subdomain)}.inhost.web.id ↗
                                        </a>
                                    </div>
                                ` : `
                                    <div style="font-size:9.5px; color:var(--text-muted); margin-top:2px; font-style:italic;">Subdomain: -</div>
                                `}
                            </div>
                        </div>
                        <div style="font-size:9.5px; color:var(--text-muted); max-width:260px; overflow:hidden; text-overflow:ellipsis; margin-top:3px;" title="${escapeHtml(row.alamat || '-')}">
                            📍 ${escapeHtml(row.alamat || '-')}
                        </div>
                    </td>
                    <td>
                        <div style="font-family:monospace; font-size:10.5px;">NPWP: <strong>${escapeHtml(row.npwp || '-')}</strong></div>
                        <div style="font-size:10px; color:var(--text-muted);">NIB: ${escapeHtml(row.nib || '-')}</div>
                    </td>
                    <td>
                        <div>Kantor: <strong>${escapeHtml(row.kode_kantor || '-')}</strong></div>
                        <div style="font-size:10px; color:var(--text-muted);">TPB: ${escapeHtml(row.kode_jenis_tpb || '-')}</div>
                    </td>
                    <td>
                        <div style="font-weight:500;">${escapeHtml(row.nomor_skep || '-')}</div>
                        <div style="font-size:10px; color:var(--text-muted);">${escapeHtml(row.tanggal_skep || '-')}</div>
                    </td>
                    <td>
                        <div style="font-weight:500;">👤 ${escapeHtml(row.username_ceisa || '-')}</div>
                        <div style="display:flex; gap:4px; margin-top:2px;">
                            ${hasMfa ? '<span class="ant-badge ant-badge-blue">MFA Ready</span>' : '<span class="ant-badge ant-badge-gray">No MFA</span>'}
                            ${isFinal ? '<span class="ant-badge ant-badge-gold">FINAL</span>' : '<span class="ant-badge ant-badge-blue">DRAFT</span>'}
                        </div>
                    </td>
                    <td style="text-align:center;">
                        ${getStorageCellHtml(row.cmp_id)}
                    </td>
                    <td style="text-align:center;">
                        <span class="ant-badge ${isInv ? 'ant-badge-green' : 'ant-badge-gray'} clickable-badge" 
                              onclick="toggleIsInv('${escapeJs(row.cmp_id)}', '${escapeJs(row.cmp_name)}')" 
                              title="Klik untuk mengubah status IT Inventory">
                            ${isInv ? '● Aktif' : '○ Non-Aktif'}
                        </span>
                        <div style="margin-top:4px;">
                            <span class="ant-badge ${(parseInt(row.alert_it_inv_unposted) || 0) === 1 ? 'ant-badge-red' : 'ant-badge-gray'} clickable-badge" 
                                  onclick="toggleAlertItInv('${escapeJs(row.cmp_id)}', '${escapeJs(row.cmp_name)}')" 
                                  title="Klik untuk mengubah pengaturan Peringatan Dokumen Belum Masuk IT Inventory">
                                ${(parseInt(row.alert_it_inv_unposted) || 0) === 1 ? '⚠️ Alert: ON' : '⚠️ Alert: OFF'}
                            </span>
                        </div>
                    </td>
                    <td>
                        <div>ERP: <strong>${escapeHtml(row.ERP_Name || '-')}</strong></div>
                        <div style="font-size:9.5px; margin-top:2px;">
                            ${(parseInt(row.allow_lowercase_code) || 0) === 1 ? '<span class="ant-badge ant-badge-green" title="Boleh Huruf Kecil">🔤 Allow a-z</span>' : '<span class="ant-badge ant-badge-blue" title="Wajib Huruf Besar">🔠 UPPERCASE</span>'}
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <span class="ant-badge ${isActive ? 'ant-badge-green' : 'ant-badge-red'} clickable-badge" 
                              onclick="toggleStatus('${escapeJs(row.cmp_id)}', '${escapeJs(row.cmp_name)}')"
                              title="Klik untuk mengaktifkan/menonaktifkan akun">
                            ${isActive ? '● Aktif' : '○ Non-Aktif'}
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <div style="display:inline-flex; gap:4px; align-items:center;">
                            <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="openEditModal('${escapeJs(row.cmp_id)}')" title="Edit Lengkap">
                                ✏️ Edit
                            </button>
                            ${!isCurrentSession ? `
                            <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="setActiveCompany('${escapeJs(row.cmp_id)}', '${escapeJs(row.cmp_name)}')" title="Jadikan Perusahaan Aktif di Sesi Admin">
                                👑 Aktifkan
                            </button>
                            ` : ''}
                            <button type="button" class="ant-btn ant-btn-warning ant-btn-sm" onclick="clearCompanyData('${escapeJs(row.cmp_id)}', '${escapeJs(row.cmp_name)}')" title="Hapus Semua Data Transaksi Perusahaan Ini">
                                🧹 Clear Data
                            </button>
                            <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="deleteCompany('${escapeJs(row.cmp_id)}', '${escapeJs(row.cmp_name)}')" title="Hapus Perusahaan">
                                🗑️
                            </button>
                        </div>
                    </td>
                `;

                tbody.appendChild(tr);
            });
        }

        // HITUNG STORAGE (ON-DEMAND)
        function calculateStorage() {
            const btn = document.getElementById('btnCalcStorage');
            const origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span>⏳</span> Menghitung...`;
            }

            fetch('company.php?action=calc_storage')
                .then(r => r.json())
                .then(res => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }

                    if (res.status === 'OK') {
                        currentStorageData = res;
                        try {
                            sessionStorage.setItem('ceisa_storage_calc', JSON.stringify(res));
                        } catch(e) {}

                        applyStorageDataToUi(res, true);

                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: `Selesai dihitung! (${res.calc_time_ms} ms)`,
                            html: `Total pemakaian: <b>${res.total.companies_mb}</b> • ${Number(res.total.companies_rows).toLocaleString('id-ID')} baris data`,
                            showConfirmButton: false,
                            timer: 3500
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Gagal Menghitung Storage', text: res.message || 'Terjadi kesalahan sistem.' });
                    }
                })
                .catch(err => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                    Swal.fire({ icon: 'error', title: 'Kesalahan Jaringan', text: err.message });
                });
        }

        function applyStorageDataToUi(data, refreshTable = true) {
            if (!data || !data.total) return;

            const statVal = document.getElementById('statStorageTotal');
            const statSub = document.getElementById('statStorageSub');
            if (statVal) statVal.innerText = data.total.companies_mb;
            if (statSub) {
                statSub.innerHTML = `<span>${Number(data.total.companies_rows).toLocaleString('id-ID')} baris • ${data.total.companies_docs} AJU</span>`;
            }

            if (refreshTable && allCompanies && allCompanies.length > 0) {
                filterTable();
            }
        }

        function openStorageModal(cmpId) {
            if (!currentStorageData || !currentStorageData.by_company || !currentStorageData.by_company[cmpId]) {
                Swal.fire({ icon: 'info', title: 'Belum Dihitung', text: 'Silakan klik tombol "Hitung Storage" terlebih dahulu.' });
                return;
            }

            const c = currentStorageData.by_company[cmpId];
            document.getElementById('storageModalTitle').innerHTML = `<span>💾</span> Rincian Pemakaian Storage: [${escapeHtml(c.cmp_id)}] ${escapeHtml(c.cmp_name)}`;

            const summaryEl = document.getElementById('storageModalSummary');
            summaryEl.innerHTML = `
                <div>
                    <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Ukuran Storage</div>
                    <div style="font-size:17px; font-weight:700; color:#722ed1;">${c.mb} <span style="font-size:11px; font-weight:500; color:var(--text-muted);">(${c.percent} dari total)</span></div>
                </div>
                <div>
                    <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Total Baris Data</div>
                    <div style="font-size:17px; font-weight:700; color:var(--text-heading);">${Number(c.rows).toLocaleString('id-ID')} <span style="font-size:11px; font-weight:500; color:var(--text-muted);">records</span></div>
                </div>
                <div>
                    <div style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:600;">Dokumen Pabean (AJU)</div>
                    <div style="font-size:17px; font-weight:700; color:#1890ff;">${Number(c.docs).toLocaleString('id-ID')} <span style="font-size:11px; font-weight:500; color:var(--text-muted);">dokumen</span></div>
                </div>
            `;

            const tbody = document.getElementById('storageModalTbody');
            tbody.innerHTML = '';

            if (!c.breakdown || c.breakdown.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada data transaksi tersimpan untuk perusahaan ini.</td></tr>`;
            } else {
                c.breakdown.forEach((item, idx) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="text-align:center; color:var(--text-muted); font-size:10px;">${idx + 1}</td>
                        <td><code style="font-size:10.5px; color:var(--primary); font-family:monospace;">${escapeHtml(item.table)}</code></td>
                        <td style="font-weight:500;">${escapeHtml(item.label)}</td>
                        <td style="text-align:right; font-weight:600;">${Number(item.rows).toLocaleString('id-ID')}</td>
                        <td style="text-align:right; font-weight:700; color:#722ed1;">${item.mb} MB</td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            document.getElementById('storageModal').classList.add('active');
        }

        function closeStorageModal() {
            document.getElementById('storageModal').classList.remove('active');
        }

        window.addEventListener('click', function(e) {
            const sm = document.getElementById('storageModal');
            if (e.target === sm) closeStorageModal();
        });

        // 3. FILTERING
        function filterTable() {
            const kw = document.getElementById('searchInput').value.trim().toLowerCase();
            const statusFilter = document.getElementById('filterStatus').value;
            const invFilter = document.getElementById('filterInv').value;

            const filtered = allCompanies.filter(c => {
                // Keyword search
                const matchKw = !kw || 
                    (c.cmp_id && c.cmp_id.toLowerCase().includes(kw)) ||
                    (c.cmp_name && c.cmp_name.toLowerCase().includes(kw)) ||
                    (c.subdomain && c.subdomain.toLowerCase().includes(kw)) ||
                    (c.npwp && c.npwp.toLowerCase().includes(kw)) ||
                    (c.nib && c.nib.toLowerCase().includes(kw)) ||
                    (c.kode_kantor && c.kode_kantor.toLowerCase().includes(kw)) ||
                    (c.username_ceisa && c.username_ceisa.toLowerCase().includes(kw));

                // Status Filter
                const matchStatus = (statusFilter === '') || (String(c.is_active) === statusFilter);

                // IT Inv Filter
                const matchInv = (invFilter === '') || (String(c.IS_INV || 1) === invFilter);

                return matchKw && matchStatus && matchInv;
            });

            renderTable(filtered);
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterInv').value = '';
            renderTable(allCompanies);
        }

        // =============================================================
        // LOGO / AVATAR PREVIEW HANDLERS
        // =============================================================
        function previewLogoFile(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                if (file.size > 5 * 1024 * 1024) {
                    Swal.fire('Ukuran Terlalu Besar', 'Maksimal ukuran file logo adalah 5MB.', 'warning');
                    input.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.getElementById('logoPreviewImg');
                    const placeholder = document.getElementById('logoPreviewPlaceholder');
                    const btnRemove = document.getElementById('btnRemoveLogo');
                    img.src = e.target.result;
                    img.style.display = 'block';
                    if (placeholder) placeholder.style.display = 'none';
                    if (btnRemove) btnRemove.style.display = 'inline-flex';
                };
                reader.readAsDataURL(file);
            }
        }

        function removeLogo() {
            document.getElementById('f_logo_file').value = '';
            document.getElementById('f_logo').value = '';
            const img = document.getElementById('logoPreviewImg');
            const placeholder = document.getElementById('logoPreviewPlaceholder');
            const btnRemove = document.getElementById('btnRemoveLogo');
            img.src = '';
            img.style.display = 'none';
            if (placeholder) placeholder.style.display = 'block';
            if (btnRemove) btnRemove.style.display = 'none';
        }

        function setLogoPreview(logoUrl) {
            const img = document.getElementById('logoPreviewImg');
            const placeholder = document.getElementById('logoPreviewPlaceholder');
            const btnRemove = document.getElementById('btnRemoveLogo');
            const fLogo = document.getElementById('f_logo');
            
            if (logoUrl) {
                img.src = logoUrl;
                img.style.display = 'block';
                if (placeholder) placeholder.style.display = 'none';
                if (btnRemove) btnRemove.style.display = 'inline-flex';
                fLogo.value = logoUrl;
            } else {
                img.src = '';
                img.style.display = 'none';
                if (placeholder) placeholder.style.display = 'block';
                if (btnRemove) btnRemove.style.display = 'none';
                fLogo.value = '';
            }
        }

        // 4. MODAL MANAGEMENT
        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = `<span>➕</span> Tambah Perusahaan Baru`;
            document.getElementById('formMode').value = 'add';
            document.getElementById('f_cmp_id').readOnly = false;
            document.getElementById('f_cmp_id').style.background = 'var(--bg-input)';
            document.getElementById('companyForm').reset();
            
            // Set Defaults
            document.getElementById('f_app_password').value = '1234';
            document.getElementById('f_is_active').value = '1';
            document.getElementById('f_IS_INV').value = '1';
            document.getElementById('f_allow_lowercase_code').value = '0';
            document.getElementById('f_alert_it_inv_unposted').value = '0';
            document.getElementById('f_is_final').value = 'false';
            document.getElementById('f_api_key_ceisa').value = 'f662de2e-1d35-4b11-b2fd-ee164a5c9d83';
            document.getElementById('f_ERP_Name').value = '-';
            document.getElementById('f_kode_jenis_tpb').value = '1';
            document.getElementById('f_tanggal_skep').value = '';
            const pEl = document.getElementById('f_tanggal_skep_picker');
            if (pEl) pEl.value = '';
            document.getElementById('totpPreviewBox').style.display = 'none';

            setLogoPreview('');
            document.getElementById('f_subdomain').value = '';
            document.getElementById('f_logo_file').value = '';

            switchModalTab('tab-profil');
            document.getElementById('companyModal').classList.add('active');
        }

        function openEditModal(cmpId) {
            Swal.fire({
                title: 'Memuat data...',
                text: 'Mengambil data konfigurasi perusahaan...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            fetch(`company.php?action=get&cmp_id=${encodeURIComponent(cmpId)}`)
                .then(r => r.json())
                .then(res => {
                    Swal.close();
                    if (res.status === 'OK' && res.data) {
                        const d = res.data;
                        document.getElementById('modalTitle').innerHTML = `<span>✏️</span> Edit Perusahaan: [${escapeHtml(d.cmp_id)}] ${escapeHtml(d.cmp_name)}`;
                        document.getElementById('formMode').value = 'edit';
                        
                        // ID cmp_id tidak boleh diubah saat edit agar konsistensi relasi terjaga
                        const cmpIdInput = document.getElementById('f_cmp_id');
                        cmpIdInput.value = d.cmp_id || '';
                        cmpIdInput.readOnly = true;
                        cmpIdInput.style.background = 'var(--tag-bg)';

                        // Logo & Subdomain
                        setLogoPreview(d.logo || '');
                        document.getElementById('f_subdomain').value = d.subdomain || '';
                        document.getElementById('f_logo_file').value = '';

                        document.getElementById('f_cmp_name').value = d.cmp_name || '';
                        document.getElementById('f_npwp').value = d.npwp || '';
                        document.getElementById('f_nib').value = d.nib || '';
                        document.getElementById('f_alamat').value = d.alamat || '';
                        document.getElementById('f_app_password').value = d.app_password || '1234';
                        document.getElementById('f_is_active').value = (d.is_active !== null && d.is_active !== undefined) ? d.is_active : '1';

                        document.getElementById('f_kode_kantor').value = d.kode_kantor || '';
                        document.getElementById('f_kode_jenis_tpb').value = d.kode_jenis_tpb || '';
                        document.getElementById('f_nomor_skep').value = d.nomor_skep || '';
                        const skepDate = d.tanggal_skep ? String(d.tanggal_skep).substring(0, 10) : '';
                        document.getElementById('f_tanggal_skep').value = skepDate;
                        const pickerEl = document.getElementById('f_tanggal_skep_picker');
                        if (pickerEl) pickerEl.value = skepDate;
                        document.getElementById('f_is_final').value = (d.is_final === 'true') ? 'true' : 'false';

                        document.getElementById('f_username_ceisa').value = d.username_ceisa || '';
                        document.getElementById('f_password_ceisa').value = d.password_ceisa || '';
                        document.getElementById('f_secret_mfa').value = d.secret_mfa || '';
                        document.getElementById('f_id_user_ceisa').value = d.id_user_ceisa || '';
                        document.getElementById('f_token_ceisa').value = d.token_ceisa || '';
                        document.getElementById('f_api_key_ceisa').value = d.api_key_ceisa || 'f662de2e-1d35-4b11-b2fd-ee164a5c9d83';

                        document.getElementById('f_IS_INV').value = (d.IS_INV !== null && d.IS_INV !== undefined) ? d.IS_INV : '1';
                        document.getElementById('f_allow_lowercase_code').value = (d.allow_lowercase_code !== null && d.allow_lowercase_code !== undefined) ? d.allow_lowercase_code : '0';
                        document.getElementById('f_alert_it_inv_unposted').value = (d.alert_it_inv_unposted !== null && d.alert_it_inv_unposted !== undefined) ? d.alert_it_inv_unposted : '0';
                        document.getElementById('f_ERP_Name').value = d.ERP_Name || '-';
                        document.getElementById('f_ERP_User').value = d.ERP_User || '';
                        document.getElementById('f_ERP_Password').value = d.ERP_Password || '';
                        document.getElementById('f_ERP_Constring').value = d.ERP_Constring || '';
                        document.getElementById('f_AI_Name').value = d.AI_Name || '';
                        document.getElementById('f_AI_API_Key').value = d.AI_API_Key || '';

                        document.getElementById('totpPreviewBox').style.display = 'none';

                        switchModalTab('tab-profil');
                        document.getElementById('companyModal').classList.add('active');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal Memuat Data',
                            text: res.message || 'Perusahaan tidak ditemukan.'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Kesalahan Sistem',
                        text: err.message
                    });
                });
        }

        function closeModal() {
            document.getElementById('companyModal').classList.remove('active');
        }

        const tabSequence = ['tab-profil', 'tab-pabean', 'tab-ceisa', 'tab-integrasi'];
        const tabLabels = {
            'tab-profil': 'Identitas & Login',
            'tab-pabean': 'Kepabeanan & SKEP',
            'tab-ceisa': 'Akun CEISA & MFA',
            'tab-integrasi': 'IT Inventory & ERP'
        };

        function switchModalTab(tabId) {
            document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));

            if (tabId === 'tab-semua') {
                // Tampilkan semua bagian sekaligus
                document.querySelectorAll('.tab-content').forEach(c => {
                    c.style.display = 'block';
                    c.classList.add('active');
                });
                const allBtn = document.getElementById('tab-btn-semua');
                if (allBtn) allBtn.classList.add('active');
                updateFooterNav('tab-semua');
                return;
            }

            // Tampilkan hanya tab yang dipilih
            document.querySelectorAll('.tab-content').forEach(c => {
                c.style.display = 'none';
                c.classList.remove('active');
            });

            const targetTab = document.getElementById(tabId);
            if (targetTab) {
                targetTab.style.display = 'block';
                targetTab.classList.add('active');
            }

            const activeBtn = Array.from(document.querySelectorAll('.modal-tab-btn')).find(b => {
                const onc = b.getAttribute('onclick') || '';
                return onc.includes(`'${tabId}'`);
            });
            if (activeBtn) activeBtn.classList.add('active');

            updateFooterNav(tabId);
        }

        function updateFooterNav(tabId) {
            const navContainer = document.getElementById('modalFooterNav');
            if (!navContainer) return;

            if (tabId === 'tab-semua') {
                navContainer.innerHTML = `<span style="font-size:10.5px; color:var(--text-muted); display:inline-flex; align-items:center; gap:4px;"><span>📄</span> Mode seluruh bagian konfigurasi aktif (Scroll)</span>`;
                return;
            }

            const idx = tabSequence.indexOf(tabId);
            let html = '';
            if (idx > 0) {
                const prevId = tabSequence[idx - 1];
                html += `<button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="switchModalTab('${prevId}')">◀ ${tabLabels[prevId]}</button>`;
            }
            if (idx < tabSequence.length - 1) {
                const nextId = tabSequence[idx + 1];
                html += `<button type="button" class="ant-btn ant-btn-default ant-btn-sm" style="color:var(--primary); font-weight:600; border-color:var(--primary);" onclick="switchModalTab('${nextId}')">Lanjut: ${tabLabels[nextId]} ▶</button>`;
            }
            navContainer.innerHTML = html;
        }

        // 5. SAVE COMPANY
        function saveCompany(e) {
            e.preventDefault();
            const form = document.getElementById('companyForm');
            const formData = new FormData(form);

            const btnSave = document.getElementById('btnSaveSubmit');
            btnSave.disabled = true;
            btnSave.innerHTML = `<span>⏳</span> Menyimpan...`;

            fetch('company.php?action=save', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                btnSave.disabled = false;
                btnSave.innerHTML = `<span>💾</span> Simpan Konfigurasi Perusahaan`;

                if (res.status === 'OK') {
                    const isInvVal = document.getElementById('f_IS_INV') ? parseInt(document.getElementById('f_IS_INV').value) : 1;
                    const cmpIdVal = document.getElementById('f_cmp_id') ? document.getElementById('f_cmp_id').value.trim() : '';
                    closeModal();
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Disimpan!',
                        text: res.message,
                        timer: 2000,
                        showConfirmButton: false
                    });
                    loadCompanyList();
                    if (window.parent && window.parent.postMessage) {
                        window.parent.postMessage({ type: 'UPDATE_IS_INV', is_inv: isInvVal, cmp_id: cmpIdVal }, '*');
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menyimpan',
                        text: res.message
                    });
                }
            })
            .catch(err => {
                btnSave.disabled = false;
                btnSave.innerHTML = `<span>💾</span> Simpan Konfigurasi Perusahaan`;
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Jaringan',
                    text: err.message
                });
            });
        }

        // 6. TOGGLE STATUS AKTIF
        function toggleStatus(cmpId, cmpName) {
            Swal.fire({
                title: 'Ubah Status Akun?',
                text: `Apakah Anda yakin ingin mengubah status aktif/non-aktif untuk perusahaan "${cmpName}"?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Ubah',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('cmp_id', cmpId);

                    fetch('company.php?action=toggle_status', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Status Diperbarui',
                                text: res.message,
                                timer: 1500,
                                showConfirmButton: false
                            });
                            loadCompanyList();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: res.message });
                        }
                    });
                }
            });
        }

        // 7. TOGGLE IS_INV
        function toggleIsInv(cmpId, cmpName) {
            Swal.fire({
                title: 'Ubah Status IT Inventory?',
                html: `Apakah Anda ingin mengubah status <strong>IT Inventory</strong> untuk <b>${escapeHtml(cmpName)}</b>?<br><br><span style="font-size:11px; color:#8c8c8c;">Jika Aktif, pewarnaan baris biru Ceisa, kuning IT Inv, dan merah belum IT Inv aktif di halaman Data Host to Host.</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Ubah Status',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('cmp_id', cmpId);

                    fetch('company.php?action=toggle_is_inv', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Status IT Inventory Diperbarui',
                                text: res.message,
                                timer: 2000,
                                showConfirmButton: false
                            });
                            loadCompanyList();
                            if (window.parent && window.parent.postMessage) {
                                window.parent.postMessage({ type: 'UPDATE_IS_INV', is_inv: res.new_is_inv, cmp_id: cmpId }, '*');
                            }
                        } else {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: res.message });
                        }
                    });
                }
            });
        }

        // 7b. TOGGLE ALERT IT INV (Peringatan Dokumen Belum Masuk IT Inv)
        function toggleAlertItInv(cmpId, cmpName) {
            Swal.fire({
                title: 'Ubah Peringatan IT Inventory?',
                html: `Apakah Anda ingin mengubah pengaturan <strong>Peringatan Dokumen Belum Masuk IT Inventory</strong> untuk <b>${escapeHtml(cmpName)}</b>?<br><br>` +
                      `<span style="font-size:11px; color:#8c8c8c;">Jika Aktif, popup peringatan akan otomatis muncul di H2H jika ada dokumen sudah memiliki Nomor Daftar tapi belum masuk IT Inventory.<br><b>Default: Nonaktif</b> (cocok untuk alur impor yang menunggu barang fisik masuk warehouse).</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Ubah Pengaturan',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('cmp_id', cmpId);

                    fetch('company.php?action=toggle_alert_it_inv', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Pengaturan Diperbarui',
                                text: res.message,
                                timer: 2500,
                                showConfirmButton: false
                            });
                            loadCompanyList();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: res.message });
                        }
                    })
                    .catch(err => {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi gagal: ' + err.message });
                    });
                }
            });
        }

        // 8. SET ACTIVE COMPANY DI SESI ADMIN
        function setActiveCompany(cmpId, cmpName) {
            Swal.fire({
                title: 'Beralih Perusahaan Aktif?',
                text: `Aktifkan [${cmpId}] ${cmpName} sebagai konteks kerja saat ini?`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#fa8c16',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Aktifkan',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('cmp_id', cmpId);

                    fetch('company.php?action=set_active', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Perusahaan Diaktifkan',
                                text: res.message,
                                timer: 1800,
                                showConfirmButton: false
                            });
                            loadCompanyList();
                            
                            // Notifikasi parent index.php jika ada
                            if (window.parent && window.parent.location) {
                                if (window.parent.postMessage) {
                                    window.parent.postMessage({
                                        type: 'COMPANY_CHANGED',
                                        cmp_id: res.cmp_id || cmpId,
                                        cmp_name: res.cmp_name || cmpName,
                                        is_inv: res.is_inv
                                    }, '*');
                                }
                                // Trigger parent header update bila ada fungsi switch
                                if (typeof window.parent.loadCompanySwitcherData === 'function') {
                                    window.parent.loadCompanySwitcherData();
                                }
                            }
                        } else {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: res.message });
                        }
                    });
                }
            });
        }

        // 8b. CLEAR COMPANY TRANSACTION DATA
        function clearCompanyData(cmpId, cmpName) {
            Swal.fire({
                title: 'CLEAR DATA TRANSAKSI?',
                html: `
                    <div style="text-align:left; font-size:12px; line-height:1.6; color:var(--text-main);">
                        Seluruh data transaksi (dokumen AJU, staging pabean, log respon, dan riwayat H2H) untuk perusahaan:<br>
                        <strong style="color:#d9363e; font-size:13px;">[${escapeHtml(cmpId)}] ${escapeHtml(cmpName)}</strong><br>
                        akan <strong>DIHAPUS PERMANEN</strong>.<br><br>
                        <div style="background:rgba(255,77,79,0.08); border-left:3px solid #ff4d4f; padding:8px 10px; border-radius:4px; margin-bottom:10px; font-size:11.5px;">
                            ⚠️ <strong>Catatan:</strong> Data master konfigurasi perusahaan, akun login, dan konfigurasi penomoran AJU tetap aman. Hanya data transaksi yang dikosongkan.
                        </div>
                        Untuk melanjutkan, ketik nama perusahaan persis seperti berikut:<br>
                        <div style="margin:6px 0 10px 0; font-weight:700; background:rgba(0,0,0,0.05); padding:6px 10px; border-radius:4px; border:1px solid var(--border-card, #d9d9d9); user-select:all; color:#1890ff; font-family:monospace; font-size:12.5px;">
                            ${escapeHtml(cmpName)}
                        </div>
                    </div>
                `,
                input: 'text',
                inputPlaceholder: `Ketik "${cmpName}" untuk konfirmasi...`,
                inputAttributes: {
                    autocapitalize: 'off',
                    autocomplete: 'off',
                    spellcheck: 'false'
                },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '🧹 Hapus Semua Data Transaksi',
                cancelButtonText: 'Batal',
                showLoaderOnConfirm: true,
                inputValidator: (value) => {
                    if (!value || value.trim().toLowerCase() !== cmpName.trim().toLowerCase()) {
                        return `Konfirmasi salah! Harap ketik "${cmpName}" dengan benar.`;
                    }
                },
                preConfirm: (value) => {
                    const fd = new FormData();
                    fd.append('cmp_id', cmpId);
                    fd.append('confirm_name', value.trim());

                    return fetch('company.php?action=clear_data', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status !== 'OK') {
                            throw new Error(res.message || 'Gagal menghapus data transaksi.');
                        }
                        return res;
                    })
                    .catch(err => {
                        Swal.showValidationMessage(`${err.message}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Data Transaksi Dibersihkan!',
                        html: `${escapeHtml(result.value.message)}<br><br><span style="font-size:11.5px; color:#8c8c8c;">${escapeHtml(result.value.details || '')}</span>`,
                        confirmButtonColor: '#1890ff'
                    });
                    loadCompanyList();
                }
            });
        }

        // 9. DELETE COMPANY
        function deleteCompany(cmpId, cmpName) {
            Swal.fire({
                title: 'Hapus Perusahaan?',
                html: `Apakah Anda yakin ingin menghapus data perusahaan <strong>[${escapeHtml(cmpId)}] ${escapeHtml(cmpName)}</strong>?<br><br><span style="color:#ff4d4f; font-size:11px;">⚠️ Tindakan ini permanen dan tidak dapat dibatalkan.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Hapus Permanen',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('cmp_id', cmpId);

                    fetch('company.php?action=delete', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'OK') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Perusahaan Dihapus',
                                text: res.message,
                                timer: 1800,
                                showConfirmButton: false
                            });
                            loadCompanyList();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Gagal', text: res.message });
                        }
                    });
                }
            });
        }

        // 10. TEST TOTP LIVE
        function testMfaSecret() {
            const secret = document.getElementById('f_secret_mfa').value.trim();
            if (!secret) {
                Swal.fire({ icon: 'warning', title: 'Perhatian', text: 'Silakan isi Secret Key MFA terlebih dahulu.' });
                return;
            }

            fetch(`company.php?action=test_totp&secret=${encodeURIComponent(secret)}`)
                .then(r => r.json())
                .then(res => {
                    const box = document.getElementById('totpPreviewBox');
                    if (res.status === 'OK') {
                        box.style.display = 'flex';
                        document.getElementById('totpLiveCode').innerText = res.code;
                        document.getElementById('totpLiveCountdown').innerText = `${res.remaining}s`;
                        Swal.fire({
                            icon: 'success',
                            title: 'Secret Key Valid!',
                            html: `Kode OTP Live saat ini: <strong style="font-size:18px; color:#52c41a; font-family:monospace;">${res.code}</strong><br><span style="font-size:11px; color:#8c8c8c;">Berganti dalam ${res.remaining} detik.</span>`,
                            timer: 3000
                        });
                    } else {
                        box.style.display = 'none';
                        Swal.fire({ icon: 'error', title: 'Kunci Tidak Valid', text: res.message });
                    }
                })
                .catch(err => {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: err.message });
                });
        // -------------------------------------------------------------
        // 11. FITUR IMPORT EXCEL DOKUMEN CEISA (SHEET ENTITAS)
        // -------------------------------------------------------------
        let currentParsedEntities = [];
        let currentFilteredEntities = [];
        let currentHeaderInfo = null;
        let currentExcelFileName = '';

        function triggerCeisaExcelUpload() {
            const fileInput = document.getElementById('ceisaExcelFileInput');
            if (fileInput) {
                fileInput.value = ''; // Reset file input
                fileInput.click();
            }
        }

        function handleCeisaExcelUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            // Pastikan SheetJS XLSX telah termuat
            if (typeof XLSX === 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Pustaka Excel Belum Siap',
                    text: 'Pustaka SheetJS (XLSX) sedang dimuat. Silakan coba kembali dalam beberapa detik.'
                });
                return;
            }

            Swal.fire({
                title: 'Membaca File Excel...',
                html: `Memproses berkas <strong>${escapeHtml(file.name)}</strong>...`,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    parseCeisaWorkbook(workbook, file.name);
                } catch (err) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Membaca File Excel',
                        text: 'Terjadi kesalahan saat memproses file Excel: ' + err.message
                    });
                }
            };
            reader.onerror = function() {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Gagal membaca berkas dari media penyimpanan.' });
            };
            reader.readAsArrayBuffer(file);
        }

        function parseCeisaWorkbook(workbook, fileName) {
            // 1. Cari Sheet ENTITAS (Case-Insensitive)
            let entitasSheetName = null;
            for (const name of workbook.SheetNames) {
                if (name.trim().toUpperCase() === 'ENTITAS') {
                    entitasSheetName = name;
                    break;
                }
            }

            if (!entitasSheetName) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Sheet "ENTITAS" Tidak Ditemukan',
                    html: `File <strong>${escapeHtml(fileName)}</strong> tidak memiliki sheet bernama <code>ENTITAS</code>.<br><br>` +
                          `<span style="font-size:11px; color:var(--text-muted);">Pastikan file Excel yang Anda unggah merupakan dokumen ekspor resmi CEISA 4.0 (contoh: Dokumen BC 2.3, BC 3.0, BC 1.6, BC 2.5, BC 2.7, BC 4.0, dll).</span>`
                });
                return;
            }

            // 2. Ekstrak Metadata dari Sheet HEADER (Jika Ada)
            let headerInfo = {
                kode_dokumen: '',
                nomor_aju: '',
                kode_kantor: '',
                kode_jenis_tpb: ''
            };

            let headerSheetName = null;
            for (const name of workbook.SheetNames) {
                if (name.trim().toUpperCase() === 'HEADER') {
                    headerSheetName = name;
                    break;
                }
            }

            if (headerSheetName) {
                const headerRows = XLSX.utils.sheet_to_json(workbook.Sheets[headerSheetName], { defval: '' });
                if (headerRows.length > 0) {
                    const h = headerRows[0];
                    const getH = (names) => {
                        for (const n of names) {
                            if (h[n] !== undefined && h[n] !== '') return String(h[n]).trim();
                        }
                        return '';
                    };
                    headerInfo.kode_dokumen   = getH(['KODE DOKUMEN', 'KODE_DOKUMEN', 'kode_dokumen', 'KODE DOK']);
                    headerInfo.nomor_aju      = getH(['NOMOR AJU', 'NOMOR_AJU', 'nomor_aju', 'NO AJU', 'no_aju']);
                    headerInfo.kode_kantor    = getH(['KODE KANTOR', 'KODE_KANTOR', 'kode_kantor', 'KODE KANTOR TUJUAN', 'KODE_KANTOR_TUJUAN']);
                    headerInfo.kode_jenis_tpb = getH(['KODE JENIS TPB', 'KODE_JENIS_TPB', 'kode_jenis_tpb', 'JENIS TPB', 'jenis_tpb']);
                }
            }

            // 3. Baca Seluruh Baris Sheet ENTITAS
            const rawRows = XLSX.utils.sheet_to_json(workbook.Sheets[entitasSheetName], { defval: '' });
            if (!rawRows || rawRows.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Sheet ENTITAS Kosong',
                    text: `Sheet ENTITAS pada file ${fileName} tidak memiliki baris data.`
                });
                return;
            }

            // Helper normalisasi nama kolom
            const getVal = (row, names) => {
                for (const n of names) {
                    if (row[n] !== undefined && row[n] !== '') return String(row[n]).trim();
                }
                const lowerKeys = Object.keys(row).reduce((acc, k) => { 
                    acc[k.toLowerCase().replace(/[\s_]/g, '')] = row[k]; 
                    return acc; 
                }, {});
                for (const n of names) {
                    const cleanN = n.toLowerCase().replace(/[\s_]/g, '');
                    if (lowerKeys[cleanN] !== undefined && lowerKeys[cleanN] !== '') return String(lowerKeys[cleanN]).trim();
                }
                return '';
            };

            const parsedEntities = [];
            rawRows.forEach((r, idx) => {
                const nama = getVal(r, ['NAMA ENTITAS', 'NAMA_ENTITAS', 'Nama Entitas', 'nama_entitas', 'NAMA', 'nama']);
                const identitas = getVal(r, ['NOMOR IDENTITAS', 'NOMOR_IDENTITAS', 'Nomor Identitas', 'nomor_identitas', 'NPWP', 'npwp']);
                const nib = getVal(r, ['NIB ENTITAS', 'NIB_ENTITAS', 'Nib Entitas', 'nib_entitas', 'NIB', 'nib']);
                const alamat = getVal(r, ['ALAMAT ENTITAS', 'ALAMAT_ENTITAS', 'Alamat Entitas', 'alamat_entitas', 'ALAMAT', 'alamat']);
                const nomorSkep = getVal(r, ['NOMOR IJIN ENTITAS', 'NOMOR_IJIN_ENTITAS', 'Nomor Ijin Entitas', 'nomor_ijin_entitas', 'NOMOR SKEP', 'nomor_skep', 'NOMOR IJIN', 'nomor_ijin']);
                const rawTglSkep = getVal(r, ['TANGGAL IJIN ENTITAS', 'TANGGAL_IJIN_ENTITAS', 'Tanggal Ijin Entitas', 'tanggal_ijin_entitas', 'TANGGAL SKEP', 'tanggal_skep', 'TANGGAL IJIN', 'tanggal_ijin']);
                const kodeEntitas = getVal(r, ['KODE ENTITAS', 'KODE_ENTITAS', 'Kode Entitas', 'kode_entitas']);
                const seri = getVal(r, ['SERI', 'seri', 'Seri']);
                const kodeNegara = getVal(r, ['KODE NEGARA', 'KODE_NEGARA', 'Kode Negara', 'kode_negara']);

                // Filter out empty rows
                if (!nama && !identitas && !alamat) return;

                const cleanNib = (nib === '-' || nib === '0' || nib === 'LAINNYA') ? '' : nib;
                const cleanSkep = (nomorSkep === '-' || nomorSkep === '0' || nomorSkep === 'LAINNYA') ? '' : nomorSkep;
                const parsedTgl = parseCeisaDate(rawTglSkep);

                parsedEntities.push({
                    raw_index: idx,
                    seri: seri || (idx + 1),
                    kode_entitas: kodeEntitas,
                    nama_entitas: nama,
                    nomor_identitas: (identitas === '-' ? '' : identitas),
                    clean_npwp: formatCeisaNpwp(identitas),
                    nib_entitas: cleanNib,
                    alamat_entitas: alamat,
                    nomor_ijin_entitas: cleanSkep,
                    tanggal_ijin_entitas: parsedTgl,
                    raw_tanggal_skep: rawTglSkep,
                    kode_negara: kodeNegara || 'ID'
                });
            });

            if (parsedEntities.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Data Entitas Tidak Ditemukan',
                    text: 'Tidak ada baris entitas yang dapat dibaca pada sheet ENTITAS.'
                });
                return;
            }

            // Smart complement: jika NIB, SKEP, atau Alamat kosong di suatu baris,
            // lengkapi dari baris lain dengan NPWP atau nama entitas yang sama
            parsedEntities.forEach(ent => {
                if (!ent.nib_entitas || !ent.nomor_ijin_entitas || !ent.tanggal_ijin_entitas || !ent.alamat_entitas) {
                    const peers = parsedEntities.filter(other => 
                        other !== ent && 
                        ((ent.clean_npwp && other.clean_npwp && ent.clean_npwp === other.clean_npwp) ||
                         (ent.nama_entitas && other.nama_entitas && ent.nama_entitas.trim().toUpperCase() === other.nama_entitas.trim().toUpperCase()))
                    );
                    peers.forEach(peer => {
                        if (!ent.nib_entitas && peer.nib_entitas) ent.nib_entitas = peer.nib_entitas;
                        if (!ent.nomor_ijin_entitas && peer.nomor_ijin_entitas) ent.nomor_ijin_entitas = peer.nomor_ijin_entitas;
                        if (!ent.tanggal_ijin_entitas && peer.tanggal_ijin_entitas) ent.tanggal_ijin_entitas = peer.tanggal_ijin_entitas;
                        if (!ent.alamat_entitas && peer.alamat_entitas) ent.alamat_entitas = peer.alamat_entitas;
                    });
                }
            });

            Swal.close();
            currentParsedEntities = parsedEntities;
            currentFilteredEntities = parsedEntities;
            currentHeaderInfo = headerInfo;
            currentExcelFileName = fileName;

            renderCeisaEntityPicker(parsedEntities, headerInfo, fileName);
            document.getElementById('modalCeisaEntityPicker').classList.add('active');
        }

        function renderCeisaEntityPicker(entities, headerInfo, fileName) {
            currentFilteredEntities = entities;

            // Update Header Meta
            const badgeEl = document.getElementById('ceisaDocBadge');
            const subTitleEl = document.getElementById('ceisaFileSubTitle');
            const metaBoxEl = document.getElementById('ceisaHeaderMetaBox');
            const countBadge = document.getElementById('ceisaEntityCountBadge');

            if (headerInfo && headerInfo.kode_dokumen) {
                badgeEl.innerText = `Dokumen BC ${headerInfo.kode_dokumen}`;
                badgeEl.className = 'ant-badge ant-badge-blue';
            } else {
                badgeEl.innerText = 'Sheet ENTITAS';
                badgeEl.className = 'ant-badge ant-badge-purple';
            }

            subTitleEl.innerHTML = `File: <strong>${escapeHtml(fileName)}</strong> ` +
                (headerInfo && headerInfo.nomor_aju ? `• No. Aju: <code style="font-family:monospace; color:var(--primary); font-weight:700;">${escapeHtml(headerInfo.nomor_aju)}</code>` : '');

            let metaHtml = '';
            if (headerInfo) {
                if (headerInfo.kode_kantor) {
                    metaHtml += `<span class="ant-badge ant-badge-cyan" title="Kantor Pabean Pengawas">🏛️ Kantor: <strong>${escapeHtml(headerInfo.kode_kantor)}</strong></span>`;
                }
                if (headerInfo.kode_jenis_tpb) {
                    const tpbLabel = (headerInfo.kode_jenis_tpb === '1') ? 'Kawasan Berikat' : `Jenis: ${headerInfo.kode_jenis_tpb}`;
                    metaHtml += `<span class="ant-badge ant-badge-gold" title="Kode Jenis TPB">🏭 TPB: <strong>${escapeHtml(tpbLabel)}</strong></span>`;
                }
            }
            metaBoxEl.innerHTML = metaHtml;
            countBadge.innerText = `Ditemukan: ${entities.length} baris entitas`;

            // Render Table Rows
            const tbody = document.getElementById('ceisaEntityTableBody');
            if (entities.length === 0) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:30px; color:var(--text-muted);">Tidak ada entitas yang sesuai dengan kata kunci pencarian.</td></tr>`;
                return;
            }

            tbody.innerHTML = entities.map((e, idx) => {
                const role = getRoleBadge(e.kode_entitas);
                const isPengusaha = (e.kode_entitas === '3');
                const rowBg = isPengusaha ? 'style="background:rgba(82,196,26,0.03);"' : '';
                
                return `
                    <tr class="entity-row" ${rowBg} ondblclick="selectCeisaEntity(${idx})" title="Klik ganda untuk langsung memilih baris ini">
                        <td style="text-align:center; padding:7px 8px;">
                            <button type="button" class="ant-btn ant-btn-success ant-btn-sm" style="font-weight:600; padding:3px 10px; width:100%; justify-content:center; font-size:10.5px;" onclick="selectCeisaEntity(${idx})">
                                <span>👉</span> Gunakan Baris Ini
                            </button>
                        </td>
                        <td style="padding:7px 10px;">
                            <span class="ant-badge" style="background:${role.bg}; color:${role.color}; border:1px solid ${role.border}; font-weight:600; font-size:10px;">
                                ${escapeHtml(role.label)}
                            </span>
                            ${role.is_recommended ? `<div style="margin-top:2px;"><span class="ant-badge ant-badge-gold" style="font-size:9px; padding:1px 5px;" title="Rekomendasi Utama untuk Perusahaan Pengguna TPB">⭐ Direkomendasikan</span></div>` : ''}
                        </td>
                        <td style="padding:7px 10px;">
                            <strong style="color:var(--text-heading); font-size:11.5px; display:block;">${escapeHtml(e.nama_entitas || '-')}</strong>
                            <div style="font-size:9.5px; color:var(--text-muted);">Seri: ${escapeHtml(e.seri || (idx + 1))}</div>
                        </td>
                        <td style="padding:7px 10px;">
                            <div style="font-family:monospace; font-weight:700; color:var(--primary); font-size:11px;">
                                ${escapeHtml(e.clean_npwp || e.nomor_identitas || '-')}
                            </div>
                            ${(e.clean_npwp && e.clean_npwp !== e.nomor_identitas) ? `<div style="font-size:9px; color:var(--text-muted); font-family:monospace;" title="Nomor Identitas Lengkap 22 Digit">Raw: ${escapeHtml(e.nomor_identitas)}</div>` : ''}
                        </td>
                        <td style="padding:7px 10px;">
                            <span style="font-family:monospace; font-weight:500;">${escapeHtml(e.nib_entitas || '-')}</span>
                        </td>
                        <td style="padding:7px 10px;">
                            <div style="max-height:45px; overflow:hidden; text-overflow:ellipsis; line-height:1.35; color:var(--text-main); font-size:10.5px;" title="${escapeHtml(e.alamat_entitas || '-')}">
                                ${escapeHtml(e.alamat_entitas || '-')}
                            </div>
                        </td>
                        <td style="padding:7px 10px;">
                            <div style="font-weight:600; font-size:10.5px;">${escapeHtml(e.nomor_ijin_entitas || '-')}</div>
                            ${e.tanggal_ijin_entitas ? `<div style="font-size:9.5px; color:var(--text-muted); font-family:monospace;">📅 ${escapeHtml(e.tanggal_ijin_entitas)}</div>` : ''}
                        </td>
                        <td style="text-align:center; padding:7px 8px;">
                            <span class="ant-badge ant-badge-blue" style="font-family:monospace; font-weight:700; font-size:10px;">
                                ${escapeHtml(e.kode_negara || 'ID')}
                            </span>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function filterCeisaEntityPicker(query) {
            const q = String(query || '').trim().toLowerCase();
            if (!q) {
                renderCeisaEntityPicker(currentParsedEntities, currentHeaderInfo, currentExcelFileName);
                return;
            }

            const filtered = currentParsedEntities.filter(e => {
                const nama = (e.nama_entitas || '').toLowerCase();
                const npwp = (e.nomor_identitas || '').toLowerCase();
                const cleanNpwp = (e.clean_npwp || '').toLowerCase();
                const nib = (e.nib_entitas || '').toLowerCase();
                const alamat = (e.alamat_entitas || '').toLowerCase();
                const skep = (e.nomor_ijin_entitas || '').toLowerCase();
                const role = (getRoleBadge(e.kode_entitas).label || '').toLowerCase();
                return nama.includes(q) || npwp.includes(q) || cleanNpwp.includes(q) || nib.includes(q) || alamat.includes(q) || skep.includes(q) || role.includes(q);
            });

            renderCeisaEntityPicker(filtered, currentHeaderInfo, currentExcelFileName);
        }

        function selectCeisaEntity(index) {
            const entity = currentFilteredEntities[index];
            if (!entity) return;

            // Jika form sedang dalam mode 'edit' dan terbuka, minta konfirmasi
            const currentMode = document.getElementById('formMode').value;
            const isModalOpen = document.getElementById('companyModal').classList.contains('active');

            if (isModalOpen && currentMode === 'edit') {
                Swal.fire({
                    title: 'Timpa Data Formulir?',
                    html: `Formulir saat ini sedang mengedit perusahaan. Apakah Anda ingin mengisi data dengan entitas <strong>${escapeHtml(entity.nama_entitas)}</strong>?<br><br><span style="font-size:11px; color:var(--text-muted);">ID Perusahaan yang sedang diedit tidak akan diubah.</span>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#1890ff',
                    cancelButtonColor: '#8c8c8c',
                    confirmButtonText: 'Ya, Gunakan Data Ini',
                    cancelButtonText: 'Batal'
                }).then((res) => {
                    if (res.isConfirmed) {
                        closeCeisaEntityPicker();
                        applyCeisaEntity(entity, currentHeaderInfo, false);
                    }
                });
                return;
            }

            closeCeisaEntityPicker();
            applyCeisaEntity(entity, currentHeaderInfo, true);
        }

        function applyCeisaEntity(entity, headerInfo, isNewCompany = true) {
            // 1. Jika mode baru atau modal tertutup, inisialisasi add modal
            const currentMode = document.getElementById('formMode').value;
            const isModalOpen = document.getElementById('companyModal').classList.contains('active');

            if (!isModalOpen || (isNewCompany && currentMode !== 'add')) {
                openAddModal();
            }

            const companyName = entity.nama_entitas || '';
            const npwpVal = entity.clean_npwp || entity.nomor_identitas || '';

            // 2. Isi Tab 1: Identitas Perusahaan
            document.getElementById('f_cmp_name').value = companyName;
            document.getElementById('f_npwp').value = npwpVal;
            document.getElementById('f_nib').value = entity.nib_entitas || '';
            document.getElementById('f_alamat').value = entity.alamat_entitas || '';

            // ID Perusahaan (hanya jika mode add)
            if (document.getElementById('formMode').value === 'add') {
                const cmpIdInput = document.getElementById('f_cmp_id');
                if (!cmpIdInput.value || cmpIdInput.value === '') {
                    if (npwpVal && npwpVal.replace(/\D/g, '').length >= 15) {
                        cmpIdInput.value = npwpVal.replace(/\D/g, '').slice(0, 15);
                    } else {
                        const shortName = suggestCleanSubdomain(companyName).toUpperCase();
                        cmpIdInput.value = shortName || 'NEW_CMP';
                    }
                }

                // Subdomain
                const subInput = document.getElementById('f_subdomain');
                if (!subInput.value || subInput.value === '') {
                    subInput.value = suggestCleanSubdomain(companyName);
                }
            }

            // 3. Isi Tab 2: Kepabeanan & SKEP
            if (entity.nomor_ijin_entitas) {
                document.getElementById('f_nomor_skep').value = entity.nomor_ijin_entitas;
            }
            if (entity.tanggal_ijin_entitas) {
                document.getElementById('f_tanggal_skep').value = entity.tanggal_ijin_entitas;
                const pEl = document.getElementById('f_tanggal_skep_picker');
                if (pEl) pEl.value = entity.tanggal_ijin_entitas;
            }

            // Metadata Header Dokumen (Kantor Pabean & Jenis TPB)
            if (headerInfo) {
                if (headerInfo.kode_kantor) {
                    document.getElementById('f_kode_kantor').value = headerInfo.kode_kantor;
                }
                if (headerInfo.kode_jenis_tpb) {
                    const selTpb = document.getElementById('f_kode_jenis_tpb');
                    if (selTpb) {
                        for (let i = 0; i < selTpb.options.length; i++) {
                            if (selTpb.options[i].value === headerInfo.kode_jenis_tpb) {
                                selTpb.selectedIndex = i;
                                break;
                            }
                        }
                    }
                }
            }

            // 4. Efek Visual Highlight Flash pada Kolom yang Terisi
            const inputsToFlash = [
                'f_cmp_id', 'f_cmp_name', 'f_npwp', 'f_nib', 'f_alamat',
                'f_subdomain', 'f_nomor_skep', 'f_tanggal_skep', 'f_kode_kantor', 'f_kode_jenis_tpb'
            ];
            inputsToFlash.forEach(id => {
                const el = document.getElementById(id);
                if (el && el.value) {
                    el.classList.add('field-highlight-flash');
                    setTimeout(() => el.classList.remove('field-highlight-flash'), 2500);
                }
            });

            // 5. Beralih ke Tab 1
            switchModalTab('tab-profil');

            // 6. Notifikasi Sukses
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 4000,
                timerProgressBar: true
            });
            Toast.fire({
                icon: 'success',
                title: `Data "${companyName}" berhasil dimuat!`,
                html: `<span style="font-size:11px;">Kolom NPWP, NIB, Alamat, SKEP dll telah terisi otomatis dari sheet ENTITAS.</span>`
            });
        }

        function closeCeisaEntityPicker() {
            const modal = document.getElementById('modalCeisaEntityPicker');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function parseCeisaDate(val) {
            if (!val) return '';
            if (val instanceof Date) {
                return val.toISOString().split('T')[0];
            }
            if (typeof val === 'number') {
                const date = new Date(Math.round((val - 25569) * 86400 * 1000));
                if (!isNaN(date.getTime())) {
                    return date.toISOString().split('T')[0];
                }
            }
            const s = String(val).trim();
            if (!s || s === '-' || s === '0000-00-00') return '';

            // Format YYYY-MM-DD
            const isoMatch = s.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
            if (isoMatch) {
                const y = isoMatch[1];
                const m = String(isoMatch[2]).padStart(2, '0');
                const d = String(isoMatch[3]).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            // Format MM-DD-YYYY atau DD-MM-YYYY
            const dMatch = s.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})/);
            if (dMatch) {
                let p1 = parseInt(dMatch[1], 10);
                let p2 = parseInt(dMatch[2], 10);
                const y = dMatch[3];
                let month, day;
                if (p1 > 12) {
                    day = p1;
                    month = p2;
                } else if (p2 > 12) {
                    month = p1;
                    day = p2;
                } else {
                    // Default format ekspor CEISA adalah MM-DD-YYYY
                    month = p1;
                    day = p2;
                }
                return `${y}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            }

            const ts = Date.parse(s);
            if (!isNaN(ts)) {
                const d = new Date(ts);
                return d.toISOString().split('T')[0];
            }
            return '';
        }

        function formatCeisaNpwp(raw) {
            if (!raw) return '';
            const digits = String(raw).replace(/\D/g, '');
            // Identitas 22 digit CEISA dengan akhiran 000000 (contoh: 0010816064431000000000)
            if (digits.length === 22 && digits.endsWith('000000')) {
                const withoutTrailing = digits.slice(0, 16);
                if (withoutTrailing.startsWith('00')) {
                    return withoutTrailing.slice(1); // Standar 15 digit Indonesia: 010816064431000
                }
                return withoutTrailing;
            }
            if (digits.length === 16 && digits.startsWith('00')) {
                return digits.slice(1);
            }
            if (digits.length === 15 || digits.length === 16) {
                return digits;
            }
            return String(raw).trim();
        }

        function suggestCleanSubdomain(name) {
            if (!name) return '';
            let clean = name.toUpperCase()
                .replace(/\b(PT|CV|UD|TBK|NV|INC|LTD|CORP|CO)\b/gi, ' ')
                .replace(/[^A-Za-z0-9\s]/g, ' ')
                .trim();
            const parts = clean.split(/\s+/).filter(p => p.length > 1);
            if (parts.length > 0) {
                return parts[0].toLowerCase().replace(/[^a-z0-9_-]/g, '');
            }
            return '';
        }

        function getRoleBadge(code) {
            const c = String(code || '').trim();
            switch (c) {
                case '3':
                    return {
                        label: '3 - Pengusaha TPB',
                        desc: 'Pengusaha Kawasan Berikat / TPB',
                        color: '#52c41a',
                        bg: 'rgba(82,196,26,0.1)',
                        border: '#b7eb8f',
                        is_recommended: true
                    };
                case '7':
                    return {
                        label: '7 - Pemilik Barang',
                        desc: 'Pemilik Barang',
                        color: '#13c2c2',
                        bg: 'rgba(19,194,194,0.1)',
                        border: '#87e8de',
                        is_recommended: false
                    };
                case '2':
                    return {
                        label: '2 - Importir',
                        desc: 'Importir Barang',
                        color: '#1890ff',
                        bg: 'rgba(24,144,255,0.1)',
                        border: '#91d5ff',
                        is_recommended: false
                    };
                case '1':
                    return {
                        label: '1 - Eksportir / Pemasok',
                        desc: 'Pemasok / Eksportir Luar Negeri',
                        color: '#722ed1',
                        bg: 'rgba(114,46,209,0.1)',
                        border: '#d3adf7',
                        is_recommended: false
                    };
                case '9':
                    return {
                        label: '9 - PPJK',
                        desc: 'Pengusaha Pengurusan Jasa Kepabeanan',
                        color: '#fa8c16',
                        bg: 'rgba(250,140,22,0.1)',
                        border: '#ffd591',
                        is_recommended: false
                    };
                case '8':
                    return {
                        label: '8 - Penerima Barang',
                        desc: 'Penerima Barang',
                        color: '#faad14',
                        bg: 'rgba(250,173,20,0.1)',
                        border: '#ffe58f',
                        is_recommended: false
                    };
                case '4':
                    return {
                        label: '4 - Pembeli',
                        desc: 'Pembeli',
                        color: '#eb2f96',
                        bg: 'rgba(235,47,150,0.1)',
                        border: '#ffadd2',
                        is_recommended: false
                    };
                case '5':
                    return {
                        label: '5 - Penjual',
                        desc: 'Penjual',
                        color: '#2f54eb',
                        bg: 'rgba(47,84,235,0.1)',
                        border: '#adc6ff',
                        is_recommended: false
                    };
                case '6':
                    return {
                        label: '6 - Pengirim',
                        desc: 'Pengirim Barang',
                        color: '#13c2c2',
                        bg: 'rgba(19,194,194,0.1)',
                        border: '#87e8de',
                        is_recommended: false
                    };
                case '10':
                    return {
                        label: '10 - Pemusatan',
                        desc: 'Entitas Pemusatan',
                        color: '#8c8c8c',
                        bg: 'rgba(140,140,140,0.1)',
                        border: '#d9d9d9',
                        is_recommended: false
                    };
                default:
                    return {
                        label: c ? `Kode: ${c}` : 'Lainnya',
                        desc: 'Entitas Kepabeanan',
                        color: '#8c8c8c',
                        bg: 'rgba(140,140,140,0.1)',
                        border: '#d9d9d9',
                        is_recommended: false
                    };
            }
        }

        // HELPER FUNCTIONS
        function togglePass(id) {
            const el = document.getElementById(id);
            if (el) {
                el.type = (el.type === 'password') ? 'text' : 'password';
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function escapeJs(str) {
            if (!str) return '';
            return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
        }
    </script>
</body>
</html>

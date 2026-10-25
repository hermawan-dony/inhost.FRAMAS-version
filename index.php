<?php
/**
 * CEISA 4.0 - Standalone Dedicated Customs Portal & Dashboard (index.php)
 * Mengadopsi Layout, Warna, Font, dan Style Resmi Portal CEISA 4.0 (portal.beacukai.go.id)
 * Multi-Company Authentication, Role Admin vs Company User,
 * Left Sidebar Navigation (Autohide & Pinned Modes),
 * Dynamic Company Switcher with Live Search for Admin (Without Re-login),
 * Self-Service Password Manager & Multi-Theme Engine.
 */
session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_settings_helper.php';
require_once __DIR__ . '/visitor_logger.php';
$appSettings = getAppSettings($koneksi);

// Route URL /visitor atau parameter tab=visitor
$reqPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($reqPath === 'visitor' || (isset($_GET['tab']) && $_GET['tab'] === 'visitor') || isset($_GET['visitor'])) {
    require_once __DIR__ . '/visitor.php';
    exit;
}

// Catat log kunjungan pengguna jika sudah login
if (!empty($_SESSION['user_id'])) {
    recordVisitorAccess($koneksi, ['event' => 'visit']);
}

$action = $_REQUEST['action'] ?? '';

// -------------------------------------------------------------
// 0. AJAX / BEACON API: CATAT AKSES MENU PENGGUNA
// -------------------------------------------------------------
if ($action === 'log_menu') {
    header('Content-Type: application/json; charset=utf-8');
    if (!empty($_SESSION['user_id'])) {
        $cmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '');
        if (isVisitorMenuLogEnabled($koneksi, $cmpId)) {
            $mId    = trim($_REQUEST['menu_id'] ?? '');
            $mTitle = trim($_REQUEST['menu_title'] ?? '');
            if (!empty($mId)) {
                recordMenuAccess($koneksi, $mId, $mTitle);
            }
        }
    }
    echo json_encode(['status' => 'OK']);
    exit;
}

// -------------------------------------------------------------
// 1. AJAX API: LOGIN
// -------------------------------------------------------------
if ($action === 'login') {
    header('Content-Type: application/json; charset=utf-8');
    $cmp_id   = trim($_POST['cmp_id'] ?? '');
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (empty($password)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kata Sandi / Password wajib diisi.']);
        exit;
    }

    // A. Login Khusus SUPER ADMINISTRATOR (Username 'dp' ATAU 'admin' tanpa company ATAU cmp_id 'admin' tanpa username)
    $isSuperAttempt = ($username === 'dp' || (empty($username) && strtolower($cmp_id) === 'admin'));
    if ($isSuperAttempt) {
        $checkAdmin = $koneksi->query("SELECT user_id, nama_lengkap, password, role, last_cmp_id FROM bc_ceisa_login 
                                       WHERE user_id = 'dp' AND (role = 'superadmin' OR cmp_id = 'SYSTEM') LIMIT 1");
        $validPass = '';
        $adminUserRow = null;
        if ($checkAdmin && $ar = $checkAdmin->fetch_assoc()) {
            $adminUserRow = $ar;
            $validPass = $ar['password'] ?? '';
        }

        if (!empty($validPass) && $password === $validPass) {
            $loggedUserId = $adminUserRow['user_id'] ?? 'dp';
            $loggedName   = $adminUserRow['nama_lengkap'] ?? 'Super Administrator (DP)';

            $_SESSION['is_super_admin']   = true;
            $_SESSION['is_admin']         = true;
            $_SESSION['is_company_admin'] = false;
            $_SESSION['can_switch_company'] = true;
            $_SESSION['user_id']          = $loggedUserId;
            $_SESSION['user_name']        = $loggedName;
            $_SESSION['user_role']        = 'superadmin';
            $_SESSION['allowed_menus']    = ['*'];
            $_SESSION['allowed_docs']     = ['*'];

            // Tentukan Perusahaan Aktif Berdasarkan Pilihan Terakhir Pengguna
            $prefCmp = !empty($adminUserRow['last_cmp_id']) ? $adminUserRow['last_cmp_id'] : ($_COOKIE['ceisa_last_cmp_' . $loggedUserId] ?? ($_COOKIE['ceisa_last_cmp'] ?? 'FRM-1'));
            $cStmt = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(IS_INV, 1) as IS_INV, IFNULL(company_roles, 'tpb') as company_roles, allowed_docs, IFNULL(ERP_Name, '-') as ERP_Name FROM bc_ceisa_company WHERE cmp_id = ? OR subdomain = ? LIMIT 1");
            $cStmt->bind_param("ss", $prefCmp, $prefCmp);
            $cStmt->execute();
            $cRes = $cStmt->get_result();
            if ($cRes && $cr = $cRes->fetch_assoc()) {
                $comp = $cr;
            } else {
                $fb = $koneksi->query("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(IS_INV, 1) as IS_INV, IFNULL(company_roles, 'tpb') as company_roles, allowed_docs, IFNULL(ERP_Name, '-') as ERP_Name FROM bc_ceisa_company ORDER BY cmp_name ASC LIMIT 1");
                $comp = $fb ? $fb->fetch_assoc() : null;
            }
            $cStmt->close();

            if ($comp) {
                $_SESSION['company_cmp_id']     = $comp['cmp_id'];
                $_SESSION['company_name']       = $comp['cmp_name'];
                $_SESSION['company_npwp']       = $comp['npwp'];
                $_SESSION['is_inv']             = (int)$comp['IS_INV'];
                $_SESSION['company_roles']      = $comp['company_roles'] ?: 'tpb';
                $_SESSION['allowed_docs']       = ceisa_resolve_allowed_docs($comp['company_roles'], $comp['allowed_docs'] ?? null);
                $_SESSION['company_erp_name']   = $comp['ERP_Name'] ?: '-';
                $_SESSION['company_logo']       = $comp['logo'] ?? '';
                $_SESSION['company_subdomain']  = $comp['subdomain'] ?? '';
                $_SESSION['company_card_color'] = $comp['card_color'] ?? '';
            }

            // Catat log pengunjung login
            recordVisitorAccess($koneksi, [
                'event'     => 'login',
                'user_id'   => $loggedUserId,
                'user_name' => $loggedName,
                'cmp_id'    => $comp['cmp_id'] ?? 'SYSTEM'
            ]);

            echo json_encode([
                'status'         => 'OK',
                'is_admin'       => true,
                'is_super_admin' => true,
                'user_role'      => 'superadmin',
                'cmp_id'         => $comp['cmp_id'] ?? 'FRM-1',
                'cmp_name'       => $comp['cmp_name'] ?? 'FRAMAS - 1',
                'message'        => 'Login Super Administrator berhasil!'
            ]);
            exit;
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Username atau Password Administrator salah!']);
            exit;
        }
    }

    // B. Login PENGGUNA (Semua User: EXIM, WAREHOUSE, PRODUKSI, SUPERVISOR, OPERATOR, ADMIN)
    if (empty($username)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nama Pengguna (Username) wajib diisi.']);
        exit;
    }

    // Cari akun pengguna di tabel bc_ceisa_login berdasarkan user_id
    $uStmt = $koneksi->prepare("SELECT cmp_id, user_id, nama_lengkap, password, lvl, role, allowed_menus, allowed_docs, is_active, oto, last_cmp_id 
                                FROM bc_ceisa_login 
                                WHERE user_id = ? 
                                ORDER BY (cmp_id != '*') DESC LIMIT 1");
    $uStmt->bind_param("s", $username);
    $uStmt->execute();
    $uRes = $uStmt->get_result();

    if ($uRes && $u = $uRes->fetch_assoc()) {
        if ((int)$u['is_active'] === 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Akun pengguna '$username' sedang dinonaktifkan. Hubungi Administrator."]);
            $uStmt->close();
            exit;
        }

        if ($u['password'] !== $password) {
            echo json_encode(['status' => 'ERROR', 'message' => "Password pengguna '$username' salah!"]);
            $uStmt->close();
            exit;
        }

        $userRole     = strtolower($u['role'] ?: 'operator');
        $isSuper      = ($u['user_id'] === 'dp' || $userRole === 'superadmin');
        $isCompAdmin  = ($userRole === 'admin' && !$isSuper);

        // Tentukan Perusahaan AKTIF Sesuai Pilihan Sebelumnya Masing-Masing User
        $prefCmp = !empty($u['last_cmp_id']) ? $u['last_cmp_id'] : ($_COOKIE['ceisa_last_cmp_' . $u['user_id']] ?? ($_COOKIE['ceisa_last_cmp'] ?? ''));
        if (empty($prefCmp) || $prefCmp === '*') {
            // Jika ada cmp_id yang dikirim dari form dan spesifik (bukan 'framas' atau 'admin')
            if (!empty($cmp_id) && !in_array($cmp_id, ['framas', 'admin', 'auto'])) {
                $prefCmp = $cmp_id;
            } elseif ($u['cmp_id'] !== '*' && !empty($u['cmp_id'])) {
                $prefCmp = $u['cmp_id'];
            } else {
                $prefCmp = 'FRM-1';
            }
        }

        // Ambil data perusahaan aktif dari bc_ceisa_company
        $compStmt = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(IS_INV, 1) as IS_INV, IFNULL(company_roles, 'tpb') as company_roles, allowed_docs, IFNULL(ERP_Name, '-') as ERP_Name FROM bc_ceisa_company WHERE cmp_id = ? OR subdomain = ? LIMIT 1");
        $compStmt->bind_param("ss", $prefCmp, $prefCmp);
        $compStmt->execute();
        $compRes = $compStmt->get_result();
        if ($compRes && $cRow = $compRes->fetch_assoc()) {
            $comp = $cRow;
        } else {
            $fb = $koneksi->query("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(IS_INV, 1) as IS_INV, IFNULL(company_roles, 'tpb') as company_roles, allowed_docs, IFNULL(ERP_Name, '-') as ERP_Name FROM bc_ceisa_company WHERE cmp_id = 'FRM-1' OR subdomain = 'frm-1' LIMIT 1");
            $comp = ($fb && $fbRow = $fb->fetch_assoc()) ? $fbRow : null;
        }
        $compStmt->close();

        if (!$comp) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Profil perusahaan tidak ditemukan. Hubungi Administrator.']);
            exit;
        }

        // Simpan preferensi perusahaan aktif ke database dan cookie
        $escTarget = $koneksi->real_escape_string($comp['cmp_id']);
        $koneksi->query("UPDATE bc_ceisa_login SET last_cmp_id = '{$escTarget}' WHERE user_id = '{$u['user_id']}'");
        @setcookie('ceisa_last_cmp_' . $u['user_id'], $comp['cmp_id'], time() + (365 * 86400), '/');
        @setcookie('ceisa_last_cmp', $comp['cmp_id'], time() + (365 * 86400), '/');

        // Hak Switch Perusahaan (Aktif untuk seluruh user Framas)
        $canSwitch = $isSuper 
            || $u['cmp_id'] === '*' 
            || strtoupper($u['oto'] ?? '') === 'ALL'
            || in_array($userRole, ['exim', 'warehouse', 'produksi', 'supervisor'])
            || in_array($comp['cmp_id'], ['FRM-1', 'FRM-2', 'FRM-3', 'PDPLB'])
            || in_array(strtolower($u['user_id']), ['agret', 'ayu', 'jois', 'shafira', 'fahd', 'suryani', 'maikel']);

        $_SESSION['is_super_admin']   = $isSuper;
        $_SESSION['is_company_admin'] = $isCompAdmin;
        $_SESSION['is_admin']         = $isSuper;
        $_SESSION['can_switch_company'] = $canSwitch;
        $_SESSION['user_id']          = $u['user_id'];
        $_SESSION['user_name']        = !empty($u['nama_lengkap']) ? $u['nama_lengkap'] : $u['user_id'];
        $_SESSION['user_role']        = $userRole;
        $_SESSION['company_cmp_id']   = $comp['cmp_id'];
        $_SESSION['company_name']     = $comp['cmp_name'];
        $_SESSION['company_npwp']     = $comp['npwp'];
        $_SESSION['is_inv']           = (int)$comp['IS_INV'];
        $_SESSION['company_roles']    = $comp['company_roles'] ?: 'tpb';
        $_SESSION['company_erp_name'] = $comp['ERP_Name'] ?: '-';
        $_SESSION['company_logo']     = $comp['logo'] ?? '';
        $_SESSION['company_subdomain']= $comp['subdomain'] ?? '';
        $_SESSION['company_card_color']= $comp['card_color'] ?? '';

        if ($userRole === 'bc') {
            $_SESSION['allowed_menus'] = ['it_inventory'];
        } else {
            $m = $u['allowed_menus'];
            $_SESSION['allowed_menus'] = ($m === '*' || empty($m)) ? ['*'] : (json_decode($m, true) ?: [$m]);
        }

        $compAllowed = ceisa_resolve_allowed_docs($comp['company_roles'] ?: 'tpb', $comp['allowed_docs'] ?? null);
        $_SESSION['company_allowed_docs'] = $compAllowed;

        $d = $u['allowed_docs'];
        $uDocs = ceisa_parse_docs($d);
        $_SESSION['user_allowed_docs'] = $uDocs;

        if ($isSuper || in_array('*', $uDocs)) {
            $_SESSION['allowed_docs'] = $isSuper ? ['*'] : $compAllowed;
        } else {
            $_SESSION['allowed_docs'] = array_values(array_intersect($uDocs, $compAllowed));
        }

        // Catat log pengunjung login
        recordVisitorAccess($koneksi, [
            'event'     => 'login',
            'user_id'   => $u['user_id'],
            'user_name' => $u['nama_lengkap'] ?: $u['user_id'],
            'cmp_id'    => $comp['cmp_id']
        ]);

        echo json_encode([
            'status'           => 'OK',
            'is_admin'         => $isSuper,
            'is_super_admin'   => $isSuper,
            'is_company_admin' => $isCompAdmin,
            'user_role'        => $userRole,
            'cmp_id'           => $comp['cmp_id'],
            'cmp_name'         => $comp['cmp_name'],
            'message'          => "Login berhasil sebagai {$u['user_id']}! Perusahaan aktif: [{$comp['cmp_id']}] {$comp['cmp_name']}"
        ]);
        $uStmt->close();
        exit;
    }

    // Fallback: jika user adalah 'admin' perusahaan
    if (strtolower($username) === 'admin' && !empty($cmp_id)) {
        $cStmt = $koneksi->prepare("SELECT * FROM bc_ceisa_company WHERE cmp_id = ? OR subdomain = ? LIMIT 1");
        $cStmt->bind_param("ss", $cmp_id, $cmp_id);
        $cStmt->execute();
        $cRes = $cStmt->get_result();
        if ($cRes && $comp = $cRes->fetch_assoc()) {
            if ($comp['app_password'] === $password) {
                $_SESSION['is_super_admin']   = false;
                $_SESSION['is_company_admin'] = true;
                $_SESSION['is_admin']         = false;
                $_SESSION['can_switch_company'] = true;
                $_SESSION['user_id']          = 'admin';
                $_SESSION['user_name']        = 'Admin ' . $comp['cmp_name'];
                $_SESSION['user_role']        = 'admin';
                $_SESSION['company_cmp_id']   = $comp['cmp_id'];
                $_SESSION['company_name']     = $comp['cmp_name'];
                $_SESSION['company_npwp']     = $comp['npwp'];
                $_SESSION['is_inv']           = (int)$comp['IS_INV'];
                $_SESSION['company_roles']    = $comp['company_roles'] ?: 'tpb';
                $_SESSION['company_erp_name'] = $comp['ERP_Name'] ?: '-';
                $_SESSION['company_logo']     = $comp['logo'] ?? '';
                $_SESSION['company_subdomain']= $comp['subdomain'] ?? '';
                $_SESSION['company_card_color']= $comp['card_color'] ?? '';
                $_SESSION['allowed_menus']    = ['*'];
                $_SESSION['allowed_docs']     = ceisa_resolve_allowed_docs($comp['company_roles'], $comp['allowed_docs'] ?? null);

                echo json_encode([
                    'status'           => 'OK',
                    'is_admin'         => false,
                    'is_super_admin'   => false,
                    'is_company_admin' => true,
                    'user_role'        => 'admin',
                    'cmp_id'           => $comp['cmp_id'],
                    'cmp_name'         => $comp['cmp_name'],
                    'message'          => "Login berhasil sebagai Administrator Perusahaan!"
                ]);
                $cStmt->close();
                exit;
            }
        }
        $cStmt->close();
    }

    echo json_encode(['status' => 'ERROR', 'message' => "Pengguna (Username) '$username' tidak ditemukan!"]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: GANTI PERUSAHAAN AKTIF (KHUSUS ADMIN, TANPA RE-LOGIN)
// -------------------------------------------------------------
if ($action === 'switch_company') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['is_admin']) && empty($_SESSION['can_switch_company'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya pengguna berwenang yang memiliki hak akses untuk berpindah perusahaan.']);
        exit;
    }

    $targetCmp = trim($_POST['cmp_id'] ?? '');
    if (empty($targetCmp)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Perusahaan tujuan tidak boleh kosong.']);
        exit;
    }

    $stmt = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(IS_INV, 1) as IS_INV, IFNULL(company_roles, 'tpb') as company_roles, allowed_docs, IFNULL(ERP_Name, '-') as ERP_Name FROM bc_ceisa_company WHERE cmp_id = ? OR npwp = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ss", $targetCmp, $targetCmp);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $comp = $res->fetch_assoc()) {
            $_SESSION['cmp_id']             = $comp['cmp_id'];
            $_SESSION['company_cmp_id']     = $comp['cmp_id'];
            $_SESSION['company_name']       = $comp['cmp_name'];
            $_SESSION['company_npwp']       = $comp['npwp'];
            $_SESSION['is_inv']             = (int)$comp['IS_INV'];
            $_SESSION['company_roles']      = $comp['company_roles'] ?: 'tpb';
            $_SESSION['company_allowed_docs'] = ceisa_resolve_allowed_docs($comp['company_roles'], $comp['allowed_docs'] ?? null);
            if (!empty($_SESSION['is_super_admin'])) {
                $_SESSION['user_allowed_docs'] = ['*'];
                $_SESSION['allowed_docs']      = ['*'];
            } else {
                $uDocs = ceisa_parse_docs($_SESSION['user_allowed_docs'] ?? '*');
                $_SESSION['allowed_docs'] = (in_array('*', $uDocs) || in_array('ALL', $uDocs)) ? $_SESSION['company_allowed_docs'] : array_values(array_intersect($uDocs, $_SESSION['company_allowed_docs']));
            }
            $_SESSION['company_erp_name']   = $comp['ERP_Name'] ?: '-';
            $_SESSION['company_logo']       = $comp['logo'] ?? '';
            $_SESSION['company_subdomain']  = $comp['subdomain'] ?? '';
            $_SESSION['company_card_color'] = $comp['card_color'] ?? '';

            // Simpan preferensi perusahaan aktif ke database untuk user saat ini agar persist saat login berikutnya
            $currentUserId = $_SESSION['user_id'] ?? '';
            if (!empty($currentUserId)) {
                $escUid = $koneksi->real_escape_string($currentUserId);
                $escCmp = $koneksi->real_escape_string($comp['cmp_id']);
                $koneksi->query("UPDATE bc_ceisa_login SET last_cmp_id = '{$escCmp}' WHERE user_id = '{$escUid}'");
                @setcookie('ceisa_last_cmp_' . $currentUserId, $comp['cmp_id'], time() + (365 * 86400), '/');
            }
            @setcookie('ceisa_last_cmp', $comp['cmp_id'], time() + (365 * 86400), '/');

            echo json_encode([
                'status'        => 'OK',
                'cmp_id'        => $comp['cmp_id'],
                'cmp_name'      => $comp['cmp_name'],
                'npwp'          => $comp['npwp'],
                'logo'          => $comp['logo'] ?? '',
                'card_color'    => $comp['card_color'] ?? '',
                'subdomain'     => $comp['subdomain'] ?? '',
                'is_inv'        => (int)$comp['IS_INV'],
                'company_roles' => $comp['company_roles'] ?: 'tpb',
                'erp_name'      => $comp['ERP_Name'] ?: '-',
                'message'       => "Berhasil beralih ke perusahaan [{$comp['cmp_id']}] {$comp['cmp_name']}!"
            ]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => "Perusahaan '$targetCmp' tidak ditemukan."]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Database error: ' . $koneksi->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: AMBIL DAFTAR PERUSAHAAN (UNTUK MODAL SEARCH)
// -------------------------------------------------------------
if ($action === 'get_companies') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['is_admin'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Akses terbatas untuk Administrator.']);
        exit;
    }
    $res = $koneksi->query("SELECT cmp_id, cmp_name, npwp, logo, IFNULL(card_color, '') as card_color FROM bc_ceisa_company ORDER BY cmp_name ASC");
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = $r;
        }
    }
    echo json_encode([
        'status' => 'OK',
        'companies' => $list,
        'active_cmp_id' => $_SESSION['company_cmp_id'] ?? ''
    ]);
    exit;
}

// -------------------------------------------------------------
// 4. AJAX API: GANTI PASSWORD
// -------------------------------------------------------------
if ($action === 'change_password') {
    header('Content-Type: application/json; charset=utf-8');
    $oldPass = trim($_POST['old_password'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');

    if (empty($oldPass) || empty($newPass)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Password lama dan password baru wajib diisi.']);
        exit;
    }
    if (strlen($newPass) < 4) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Password baru minimal 4 karakter.']);
        exit;
    }

    if (!empty($_SESSION['is_admin'])) {
        $sessUser = $_SESSION['user_id'] ?? 'dp';
        $uEsc = $koneksi->real_escape_string($sessUser);
        $chk = $koneksi->query("SELECT password FROM bc_ceisa_login WHERE (user_id = '{$uEsc}' OR user_id = 'dp') AND (role = 'superadmin' OR cmp_id = 'SYSTEM') ORDER BY (user_id = '{$uEsc}') DESC LIMIT 1");
        $curr = '';
        if ($chk && $cr = $chk->fetch_assoc()) {
            $curr = $cr['password'] ?? '';
        }
        if (empty($curr) || $oldPass !== $curr) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Password lama Administrator salah!']);
            exit;
        }
        $upd = $koneksi->prepare("UPDATE bc_ceisa_login SET password = ? WHERE user_id IN ('dp', 'admin') AND (role = 'superadmin' OR cmp_id = 'SYSTEM')");
        if ($upd) {
            $upd->bind_param("s", $newPass);
            $upd->execute();
            $upd->close();
        }
        echo json_encode(['status' => 'OK', 'message' => 'Password Administrator berhasil diperbarui!']);
        exit;
    } else {
        $currentCmp = trim($_POST['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
        if (empty($currentCmp)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Sesi login telah berakhir atau ID perusahaan tidak disertakan.']);
            exit;
        }
        $stmt = $koneksi->prepare("SELECT IFNULL(app_password, '1234') as app_password FROM bc_ceisa_company WHERE cmp_id = ? LIMIT 1");
        $stmt->bind_param("s", $currentCmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $comp = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$comp || $comp['app_password'] !== $oldPass) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Password lama salah!']);
            exit;
        }

        $updateStmt = $koneksi->prepare("UPDATE bc_ceisa_company SET app_password = ? WHERE cmp_id = ?");
        $updateStmt->bind_param("ss", $newPass, $currentCmp);
        if ($updateStmt->execute()) {
            $sync = $koneksi->prepare("UPDATE bc_ceisa_login SET password = ? WHERE cmp_id = ?");
            if ($sync) {
                $sync->bind_param("ss", $newPass, $currentCmp);
                $sync->execute();
                $sync->close();
            }
            echo json_encode(['status' => 'OK', 'message' => 'Password perusahaan berhasil diperbarui!']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui password: ' . $updateStmt->error]);
        }
        $updateStmt->close();
        exit;
    }
}

// -------------------------------------------------------------
// 5. AJAX API: GET ALERT NOTIFICATIONS
// -------------------------------------------------------------
if ($action === 'get_alert_notifications') {
    header('Content-Type: application/json; charset=utf-8');
    
    $activeCmpId   = trim($_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ''));
    $activeCmpNpwp = trim($_SESSION['company_npwp'] ?? '');
    $cleanCmp      = $koneksi->real_escape_string($activeCmpId);
    $cmpNpwpDigits = preg_replace('/[^0-9]/', '', $activeCmpNpwp);

    $isInv = isset($_SESSION['is_inv']) ? (int)$_SESSION['is_inv'] : 1;
    $cmpName = $_SESSION['company_name'] ?? 'Perusahaan';

    if (!empty($cleanCmp)) {
        $qCmp = $koneksi->query("SELECT IFNULL(IS_INV, 1) AS is_inv, npwp, cmp_name FROM bc_ceisa_company WHERE cmp_id = '$cleanCmp' LIMIT 1");
        if ($qCmp && $rc = $qCmp->fetch_assoc()) {
            $isInv = (int)$rc['is_inv'];
            if (empty($cmpNpwpDigits)) $cmpNpwpDigits = preg_replace('/[^0-9]/', '', $rc['npwp']);
            if (!empty($rc['cmp_name'])) $cmpName = $rc['cmp_name'];
        }
    }

    // 1. Jumlah Dokumen yang Belum Selesai (CEISA 4.0)
    $whereCmpData = [];
    if (!empty($cleanCmp) && !empty($cmpNpwpDigits)) {
        $whereCmpData[] = "a.idPerusahaan LIKE '%$cmpNpwpDigits%'";
    }
    $whereCmpDataSql = !empty($whereCmpData) ? ' AND ' . implode(' AND ', $whereCmpData) : '';

    $sqlBelumSelesai = "SELECT COUNT(1) 
        FROM bc_ceisa_data a
        LEFT JOIN (
            SELECT nomor_aju, COUNT(1) AS jml 
            FROM bc_ceisa_aju_posted 
            GROUP BY nomor_aju
        ) posted ON a.nomorAju = posted.nomor_aju
        WHERE (LOWER(a.namaProses) NOT LIKE '%selesai%' AND (posted.jml IS NULL OR posted.jml = 0))
        $whereCmpDataSql";
    $cntBelumSelesai = (int)($koneksi->query($sqlBelumSelesai)->fetch_row()[0] ?? 0);

    // 2. Jumlah Dokumen yang Belum Masuk IT Inv (Hanya jika konfigurasi IS_INV = 1)
    // Diambil khusus dari tabel staging (bc_ceisa_header) saja yang sudah terbit Nomor Pendaftaran
    $cntBelumItInv = 0;
    if ($isInv === 1) {
        $whereCmpHdr = !empty($cleanCmp) ? "AND (h.cmp_id = '$cleanCmp' OR '$cleanCmp' = '')" : "";
        $sqlBelumItInv = "SELECT COUNT(DISTINCT h.nomor_aju)
            FROM bc_ceisa_header h
            LEFT JOIN (
                SELECT DISTINCT nomor_aju FROM bc_1ab2 WHERE nomor_aju IS NOT NULL AND nomor_aju != ''
            ) it ON h.nomor_aju = it.nomor_aju
            WHERE h.nomor_daftar IS NOT NULL 
              AND TRIM(h.nomor_daftar) != '' 
              AND TRIM(h.nomor_daftar) != '-'
              AND (h.user_id != 'CEISA_PORTAL' OR h.user_id IS NULL OR h.user_id = '')
              $whereCmpHdr
              AND it.nomor_aju IS NULL";
        $cntBelumItInv = (int)($koneksi->query($sqlBelumItInv)->fetch_row()[0] ?? 0);
    }

    // 3. Info Alert Jika Ada QTY AKTUAL Berbeda dengan QTY di Tabel bc_1ab2 (Warehouse Input)
    $whereCmp1ab2 = !empty($cleanCmp) ? "AND (cmp_id = '$cleanCmp' OR cmp_id = '')" : "";
    $sqlCountQtyDiff = "SELECT COUNT(1) 
        FROM bc_1ab2 
        WHERE (ABS(QTY_AKTUAL - QTY) > 0.0001 OR (SESUAI = 'N' AND QTY_AKTUAL != 0))
        $whereCmp1ab2";
    $cntQtyDiff = (int)($koneksi->query($sqlCountQtyDiff)->fetch_row()[0] ?? 0);

    $itemsQtyDiff = [];
    if ($cntQtyDiff > 0) {
        $sqlItemsQtyDiff = "SELECT 
                NOMOR_AJU, 
                BC, 
                NOMOR_DAFTAR, 
                TANGGAL_DAFTAR, 
                NO_BUKTI, 
                KODE_BARANG, 
                URAIAN, 
                QTY, 
                QTY_AKTUAL, 
                (QTY_AKTUAL - QTY) AS SELISIH, 
                UNIT, 
                MEMO, 
                SESUAI
            FROM bc_1ab2
            WHERE (ABS(QTY_AKTUAL - QTY) > 0.0001 OR (SESUAI = 'N' AND QTY_AKTUAL != 0))
            $whereCmp1ab2
            ORDER BY TANGGAL_DAFTAR DESC, NOMOR_AJU DESC
            LIMIT 10";
        $resDiff = $koneksi->query($sqlItemsQtyDiff);
        if ($resDiff) {
            while ($rd = $resDiff->fetch_assoc()) {
                $itemsQtyDiff[] = $rd;
            }
        }
    }

    echo json_encode([
        'status'         => 'OK',
        'cmp_id'         => $activeCmpId,
        'cmp_name'       => $cmpName,
        'is_inv'         => $isInv,
        'belum_selesai'  => $cntBelumSelesai,
        'belum_it_inv'   => $cntBelumItInv,
        'selisih_qty'    => $cntQtyDiff,
        'items_qty_diff' => $itemsQtyDiff,
        'total_alerts'   => ($cntBelumSelesai > 0 ? 1 : 0) + ($isInv === 1 && $cntBelumItInv > 0 ? 1 : 0) + ($cntQtyDiff > 0 ? 1 : 0)
    ]);
    exit;
}

// -------------------------------------------------------------
// 6. LOGOUT
// -------------------------------------------------------------
if ($action === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

$companies = [];
$res = $koneksi->query("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(IS_INV, 1) as IS_INV FROM bc_ceisa_company ORDER BY cmp_name ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $companies[] = $r;
    }
}

// Deteksi Subdomain Perusahaan (misal: https://inhost.framas.web.id atau index.php?subdomain=framas)
$subdomainCompany = null;
$detectedSubdomain = '';
$isSubdomainUnregistered = false;
$isAdminOnlyLogin = false;

$rawHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
$cleanHost = explode(':', $rawHost)[0];
$parts = explode('.', $cleanHost);
$subCandidate = '';

$servicePrefixes = ['www', 'root', 'portal', 'api', 'app', 'mail', 'cpanel', 'localhost', 'inhost', 'ceisa', 'cloud'];

// Cek apakah host merupakan domain Framas Portal (1 subdomain melayani 4 perusahaan Framas: FRM-1, FRM-2, FRM-3, PDPLB)
$isFramasPortal = (strpos($cleanHost, 'framas') !== false || in_array('framas', $parts) || (stripos($appSettings['app_name'] ?? '', 'framas') !== false));

if (isset($_GET['subdomain'])) {
    $paramSub = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['subdomain'])));
    if ($paramSub === 'admin') {
        $isAdminOnlyLogin = true;
    } elseif (!in_array($paramSub, array_merge($servicePrefixes, ['none', 'all', '']))) {
        $subCandidate = $paramSub;
    }
} else {
    if (count($parts) >= 3) {
        // Jika parts[0] merupakan prefix umum (misal 'inhost', 'www'), periksa bagian kedua (misal inhost.framas.web.id)
        $pIdx = 0;
        if (in_array($parts[0], $servicePrefixes) && isset($parts[1]) && !in_array($parts[1], ['web', 'com', 'net', 'id', 'org', 'co'])) {
            $pIdx = 1;
        }
        $candidate = strtolower($parts[$pIdx]);
        if ($candidate === 'admin') {
            $isAdminOnlyLogin = true;
        } elseif (!in_array($candidate, $servicePrefixes) && !in_array($candidate, ['web', 'com', 'net', 'id', 'org', 'co'])) {
            $subCandidate = $candidate;
        }
    }
}

// Jika subCandidate adalah 'framas', ini adalah portal bersama Framas, bukan single company
if ($subCandidate === 'framas') {
    $isFramasPortal = true;
    $subCandidate = '';
}

if (!empty($subCandidate) && isset($koneksi) && $koneksi) {
    $subStmt = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(IS_INV, 1) as IS_INV FROM bc_ceisa_company WHERE LOWER(subdomain) = ? AND is_active = 1 LIMIT 1");
    if ($subStmt) {
        $subStmt->bind_param("s", $subCandidate);
        $subStmt->execute();
        $subRes = $subStmt->get_result();
        if ($subRes && $subRes->num_rows > 0) {
            $subdomainCompany = $subRes->fetch_assoc();
            $detectedSubdomain = $subCandidate;
        } else {
            // Jika bukan Framas Portal dan subdomain tidak terdaftar, baru tandai khusus admin
            if (!$isFramasPortal) {
                $isSubdomainUnregistered = true;
                $isAdminOnlyLogin = true;
                $detectedSubdomain = $subCandidate;
            }
        }
        $subStmt->close();
    }
}

// Jika Framas Portal terdeteksi, pastikan BUKAN Admin-only login (semua user bisa login)
if ($isFramasPortal) {
    $isAdminOnlyLogin = false;
    $isSubdomainUnregistered = false;
}

// Deteksi URL Query Parameter Khusus Perusahaan (misal: ?cmp=RMB atau ?cmp=TVL atau ?company=rmb)
// Otomatis membuka portal langsung khusus untuk user perusahaan bersangkutan (user RMB saja, is_admin = false)
$urlCmpParam = trim($_GET['cmp'] ?? $_GET['company'] ?? '');
if (!empty($urlCmpParam) && isset($koneksi) && $koneksi) {
    $cmpLookup = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(IS_INV, 1) as IS_INV, IFNULL(company_roles, 'tpb') as company_roles, IFNULL(ERP_Name, '-') as ERP_Name FROM bc_ceisa_company WHERE (cmp_id = ? OR LOWER(cmp_id) = LOWER(?) OR LOWER(subdomain) = LOWER(?) OR npwp = ?) AND is_active = 1 LIMIT 1");
    if ($cmpLookup) {
        $cmpLookup->bind_param("ssss", $urlCmpParam, $urlCmpParam, $urlCmpParam, $urlCmpParam);
        $cmpLookup->execute();
        $resComp = $cmpLookup->get_result();
        if ($resComp && $cRow = $resComp->fetch_assoc()) {
            $_SESSION['is_admin']          = false; // Non-admin: akun khusus per user perusahaan
            $_SESSION['user_id']           = $cRow['subdomain'] ?: $cRow['cmp_id'];
            $_SESSION['user_name']         = $cRow['cmp_name'];
            $_SESSION['user_role']         = 'User ' . $cRow['cmp_name'];
            $_SESSION['company_cmp_id']    = $cRow['cmp_id'];
            $_SESSION['company_name']      = $cRow['cmp_name'];
            $_SESSION['company_npwp']      = $cRow['npwp'];
            $_SESSION['is_inv']            = (int)$cRow['IS_INV'];
            $_SESSION['company_roles']     = $cRow['company_roles'] ?: 'tpb';
            $_SESSION['company_erp_name']  = $cRow['ERP_Name'] ?: '-';
            $_SESSION['company_logo']      = $cRow['logo'] ?? '';
            $_SESSION['company_subdomain'] = $cRow['subdomain'] ?? '';
            $_SESSION['company_card_color']= $cRow['card_color'] ?? '';
            $_SESSION['allowed_menus']     = ['*'];
            $_SESSION['allowed_docs']      = ['*'];

            // Jika ada parameter &user=... khusus, periksa hak akses profil pengguna spesifik
            if (!empty($_GET['user'])) {
                $specificUser = trim($_GET['user']);
                $uStmt = $koneksi->prepare("SELECT user_id, nama_lengkap, role, allowed_menus, allowed_docs FROM bc_ceisa_login WHERE user_id = ? AND cmp_id = ? AND is_active = 1 LIMIT 1");
                if ($uStmt) {
                    $uStmt->bind_param("ss", $specificUser, $cRow['cmp_id']);
                    $uStmt->execute();
                    $uRes = $uStmt->get_result();
                    if ($uRes && $uData = $uRes->fetch_assoc()) {
                        $_SESSION['user_id']   = $uData['user_id'];
                        $_SESSION['user_name'] = !empty($uData['nama_lengkap']) ? $uData['nama_lengkap'] : $uData['user_id'];
                        $userRole              = $uData['role'] ?: 'operator';
                        $_SESSION['user_role'] = $userRole;
                        $isSuper               = ($uData['user_id'] === 'dp' || $userRole === 'superadmin');
                        $_SESSION['is_super_admin']   = $isSuper;
                        $_SESSION['is_company_admin'] = ($userRole === 'admin' && !$isSuper);
                        $_SESSION['is_admin']         = $isSuper;
                        if ($userRole === 'bc') {
                            $_SESSION['allowed_menus'] = ['it_inventory'];
                        } else {
                            $m = $uData['allowed_menus'];
                            $_SESSION['allowed_menus'] = ($m === '*' || empty($m)) ? ['*'] : (json_decode($m, true) ?: [$m]);
                        }
                        $d = $uData['allowed_docs'];
                        $_SESSION['allowed_docs']  = ($d === '*' || empty($d)) ? ['*'] : (json_decode($d, true) ?: [$d]);
                    }
                    $uStmt->close();
                }
            }
        }
        $cmpLookup->close();
    }
}

$isLoggedIn       = !empty($_SESSION['company_cmp_id']);
$isSuperAdmin     = !empty($_SESSION['is_super_admin']);
$isCompanyAdmin   = !empty($_SESSION['is_company_admin']);
$isAdmin          = $isSuperAdmin; // Strict: $isAdmin merujuk khusus pada Super Administrator
$sessionUserId    = $_SESSION['user_id'] ?? '';
$sessionUserName  = $_SESSION['user_name'] ?? ($sessionUserId ?: 'User');
$sessionCmpId     = $_SESSION['company_cmp_id'] ?? '';
$sessionCmpName   = $_SESSION['company_name'] ?? '';
$sessionNpwp      = $_SESSION['company_npwp'] ?? '';
$sessionLogo      = $_SESSION['company_logo'] ?? '';
$sessionSubdomain = $_SESSION['company_subdomain'] ?? '';
$sessionCardColor = $_SESSION['company_card_color'] ?? '';

// Ambil status IS_INV, logo, card_color, company_roles & ERP_Name langsung dari database untuk perusahaan aktif
$sessionIsInv = 1;
$sessionCompanyRoles = 'tpb';
$sessionErpName = '-';
if (!empty($sessionCmpId) && isset($koneksi) && $koneksi) {
    $qInv = $koneksi->query("SELECT IFNULL(IS_INV, 1) as IS_INV, logo, subdomain, IFNULL(card_color, '') as card_color, IFNULL(company_roles, 'tpb') as company_roles, allowed_docs, IFNULL(ERP_Name, '-') as ERP_Name FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($sessionCmpId) . "' LIMIT 1");
    if ($qInv && $rInv = $qInv->fetch_assoc()) {
        $sessionIsInv = (int)$rInv['IS_INV'];
        $_SESSION['is_inv'] = $sessionIsInv;
        $sessionLogo = $rInv['logo'] ?? '';
        $sessionSubdomain = $rInv['subdomain'] ?? '';
        $sessionCardColor = $rInv['card_color'] ?? '';
        $sessionCompanyRoles = $rInv['company_roles'] ?: 'tpb';
        $sessionErpName = $rInv['ERP_Name'] ?: '-';
        $_SESSION['company_logo'] = $sessionLogo;
        $_SESSION['company_subdomain'] = $sessionSubdomain;
        $_SESSION['company_card_color'] = $sessionCardColor;
        $_SESSION['company_roles'] = $sessionCompanyRoles;
        $_SESSION['company_allowed_docs'] = ceisa_resolve_allowed_docs($sessionCompanyRoles, $rInv['allowed_docs'] ?? null);
        $_SESSION['company_erp_name'] = $sessionErpName;
    } elseif (isset($_SESSION['is_inv'])) {
        $sessionIsInv = (int)$_SESSION['is_inv'];
        $sessionCompanyRoles = $_SESSION['company_roles'] ?? 'tpb';
        $sessionErpName = $_SESSION['company_erp_name'] ?? '-';
        if (!isset($_SESSION['company_allowed_docs'])) {
            $_SESSION['company_allowed_docs'] = ceisa_resolve_allowed_docs($sessionCompanyRoles, null);
        }
    }
} else {
    $sessionCompanyRoles = $_SESSION['company_roles'] ?? 'tpb';
    $sessionErpName = $_SESSION['company_erp_name'] ?? '-';
    if (!isset($_SESSION['company_allowed_docs'])) {
        $_SESSION['company_allowed_docs'] = ceisa_resolve_allowed_docs($sessionCompanyRoles, null);
    }
}

// Sinkronisasi Hak Akses Dokumen Pengguna yang Sedang Login
if (!empty($_SESSION['is_super_admin'])) {
    $_SESSION['user_allowed_docs'] = ['*'];
    $_SESSION['allowed_docs'] = ['*'];
} else {
    if (!empty($sessionUserId) && !empty($sessionCmpId) && isset($koneksi) && $koneksi) {
        $uStmt = $koneksi->prepare("SELECT allowed_docs, role FROM bc_ceisa_login WHERE user_id = ? AND cmp_id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param("ss", $sessionUserId, $sessionCmpId);
            $uStmt->execute();
            $uRes = $uStmt->get_result();
            if ($uRes && $uRow = $uRes->fetch_assoc()) {
                $_SESSION['user_allowed_docs'] = ceisa_parse_docs($uRow['allowed_docs']);
            }
            $uStmt->close();
        }
    }
    $uDocs = ceisa_parse_docs($_SESSION['user_allowed_docs'] ?? '*');
    $cDocs = $_SESSION['company_allowed_docs'] ?? ceisa_resolve_allowed_docs($sessionCompanyRoles, null);
    if (in_array('*', $uDocs) || in_array('ALL', $uDocs)) {
        $_SESSION['allowed_docs'] = $cDocs;
    } else {
        $_SESSION['allowed_docs'] = array_values(array_intersect($uDocs, (array)$cDocs));
    }
}

// Resolusi App Settings (Multi-tenant: Prioritas Company Subdomain / Active Session -> Fallback Global)
// Catatan: Khusus Super Administrator (dp), SELALU gunakan App Settings Global sistem
$activeAppCmpId = '';
if (!$isSuperAdmin) {
    if ($isLoggedIn && !empty($sessionCmpId)) {
        $activeAppCmpId = $sessionCmpId;
    } elseif (!empty($subdomainCompany['cmp_id'])) {
        $activeAppCmpId = $subdomainCompany['cmp_id'];
    }
}
$appSettings = getAppSettings($koneksi, $activeAppCmpId);
$appUpdateVersion = getAppUpdateVersion();

// Resolusi Peran & Hak Akses Dokumen Perusahaan
$activeRolesList = array_filter(array_map('trim', explode(',', strtolower($sessionCompanyRoles))));
if (empty($activeRolesList)) {
    $activeRolesList = ['tpb'];
}
$hasRoleManifest = in_array('manifest', $activeRolesList);
$hasRolePib      = in_array('pib', $activeRolesList);
$hasRolePeb      = in_array('peb', $activeRolesList);
$hasRoleTpb      = in_array('tpb', $activeRolesList);
$hasRolePpkek    = in_array('ppkek', $activeRolesList);
$hasRolePlb      = in_array('plb', $activeRolesList);

// Perusahaan HANYA berperan Manifest (tanpa dokumen BC kepabeanan lainnya)
$isManifestOnlyCompany = ($hasRoleManifest && !$hasRolePib && !$hasRolePeb && !$hasRoleTpb && !$hasRolePpkek && !$hasRolePlb);

// Dokumen pabean standar ada jika memiliki salah satu dari PIB, PEB, TPB, PPKEK, atau PLB
$hasStandardCustomsDocs = ($hasRolePib || $hasRolePeb || $hasRoleTpb || $hasRolePpkek || $hasRolePlb);

// Apakah ERP yang dipakai adalah Odoo
$isOdooErpCompany = (strtoupper(trim($sessionErpName)) === 'ODOO');

// Rule User: yang perlu Modul Inventory HANYA TPB, PPKEK & PLB, selain itu HIDE
$isInventoryModuleAllowed = ($hasRoleTpb || $hasRolePpkek || $hasRolePlb) && ($sessionIsInv === 1 || $userRole === 'bc');

/**
 * Helper menghitung style inline dan variabel CSS untuk card perusahaan di sidebar
 */
function getSidebarCompanyCardStyle($color) {
    if (empty($color) || $color === 'default') {
        return '';
    }
    $color = trim($color);
    $isDark = true;
    if (strpos($color, 'gradient') === false && preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $color)) {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b);
        $isDark = ($lum < 165);
    }
    
    if ($isDark) {
        return "background: {$color} !important; border-color: rgba(255,255,255,0.2) !important; color: #ffffff !important; --card-title-color: #ffffff; --card-sub-color: rgba(255,255,255,0.75); --card-btn-bg: rgba(255,255,255,0.2); --card-btn-color: #ffffff; --card-btn-border: rgba(255,255,255,0.35);";
    } else {
        return "background: {$color} !important; border-color: rgba(0,0,0,0.12) !important; color: #1f1f1f !important; --card-title-color: #1f1f1f; --card-sub-color: #595959; --card-btn-bg: rgba(0,0,0,0.06); --card-btn-color: #1890ff; --card-btn-border: rgba(0,0,0,0.1);";
    }
}

$userRole = $_SESSION['user_role'] ?? 'operator';

// Generate Avatar Initials
$userInitials = 'MW';
if ($isSuperAdmin) {
    $userInitials = 'DP';
} elseif ($isCompanyAdmin) {
    $userInitials = 'AP'; // Admin Perusahaan
} elseif ($userRole === 'bc') {
    $userInitials = 'BC';
} else {
    $displayName = $_SESSION['user_name'] ?? $sessionCmpName;
    $words = preg_split('/\s+/', trim($displayName));
    if (count($words) >= 2) {
        $userInitials = strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
    } elseif (!empty($words[0])) {
        $userInitials = strtoupper(substr($words[0], 0, 2));
    }
}

// Helper untuk Cek Hak Akses Menu Pengguna
if (!function_exists('isMenuAllowed')) {
    function isMenuAllowed($menuKey) {
        $role = strtolower($_SESSION['user_role'] ?? 'operator');
        if ($role === 'bc') {
            return ($menuKey === 'it_inventory');
        }
        if (!empty($_SESSION['is_super_admin']) || !empty($_SESSION['is_admin'])) return true;
        if (!empty($_SESSION['is_company_admin']) && in_array($menuKey, ['user_management', 'app_settings'])) return true;

        // Menu Administrator & User Management mutlak hanya untuk Super Administrator / Admin Perusahaan
        if (in_array($menuKey, ['company', 'app_settings', 'docs', 'db', 'version_log', 'user_management'])) {
            return false;
        }

        // Role PRODUKSI: mutasi produksi, it inventory
        if ($role === 'produksi') {
            return in_array($menuKey, ['inventory_modul', 'inv_stock_transfer', 'it_inventory']);
        }

        // Role WAREHOUSE: menu warehouse & modul inventory, bisa akses dashboard ceisa 4.0
        if ($role === 'warehouse') {
            return in_array($menuKey, [
                'daftar_dokumen',
                'inventory_modul',
                'inv_material',
                'inv_bom',
                'inv_jaminan',
                'inv_stock_transfer',
                'inv_warehouse_in',
                'inv_warehouse_delivery',
                'inv_transfer_bc27',
                'inv_scan27',
                'inv_stok_opname',
                'inv_unposting_appv',
                'inv_stok_awal',
                'inv_adjust_spp',
                'it_inventory'
            ]);
        }

        // Role EXIM: semua menu operasional (kecuali menu administrator yang sudah diblok di atas)
        if ($role === 'exim') {
            return true;
        }

        $allowed = $_SESSION['allowed_menus'] ?? ['*'];
        if (empty($allowed) || in_array('*', $allowed) || in_array('ALL', $allowed)) return true;
        return in_array($menuKey, $allowed);
    }
}

// Inisialisasi Tab Awal & Parameter AJU (Mendukung deep linking & Form Editor)
$initialTab = $_GET['tab'] ?? $_GET['menu'] ?? '';
if (empty($initialTab)) {
    if ($userRole === 'bc') {
        $initialTab = 'it_inventory_home';
    } elseif ($userRole === 'produksi') {
        $initialTab = 'inv_stock_transfer';
    } elseif ($isManifestOnlyCompany) {
        $initialTab = 'manifest_ceisa';
    } elseif (!$hasStandardCustomsDocs) {
        $initialTab = $hasRoleManifest ? 'manifest_ceisa' : 'daftar_dokumen';
    } elseif (!isMenuAllowed('daftar_dokumen')) {
        $initialTab = $isInventoryModuleAllowed ? 'it_inventory_1a' : ($hasRoleManifest ? 'manifest_ceisa' : 'transfer');
    } else {
        $initialTab = 'daftar_dokumen';
    }
}
if ($userRole === 'bc') {
    // Role Pejabat Bea Cukai hanya boleh mengakses tab Laporan IT Inventory
    if (strpos($initialTab, 'it_inventory') !== 0) {
        $initialTab = 'it_inventory_home';
    }
} elseif ($isManifestOnlyCompany) {
    // Jika hanya role manifest, tab pabean standar atau inventory diarahkan ke manifest_ceisa
    if (in_array($initialTab, ['daftar_dokumen', 'transfer', 'editor', 'import', 'load_erp_odoo', 'dokap', 'tracking', 'winline_sales_invoice', 'winline_dn_transfer', 'winline_purchase_order']) || strpos($initialTab, 'it_inventory') === 0 || strpos($initialTab, 'inv_') === 0 || strpos($initialTab, 'winline_') === 0) {
        $initialTab = 'manifest_ceisa';
    }
} elseif (!$isInventoryModuleAllowed && (strpos($initialTab, 'it_inventory') === 0 || strpos($initialTab, 'inv_') === 0)) {
    $initialTab = $hasStandardCustomsDocs ? 'daftar_dokumen' : 'manifest_ceisa';
} elseif ($initialTab === 'load_erp_odoo' && (!$hasStandardCustomsDocs || !$isOdooErpCompany)) {
    $initialTab = 'daftar_dokumen';
} elseif ($initialTab === 'tracking' && (($appSettings['show_menu_tracking'] ?? '0') !== '1')) {
    $initialTab = 'daftar_dokumen';
}
$initialAju = trim($_GET['nomor_aju'] ?? '');

// Hitung jumlah pengajuan kode barang pending untuk badge menu Master Barang
$cntPendingApproval = 0;
try {
    $escIndexCmp = !empty($sessionCmpId) ? $koneksi->real_escape_string($sessionCmpId) : '';
    $cmpFilterIndex = !empty($escIndexCmp) ? "AND (cmp_id = '$escIndexCmp' OR cmp_id = '')" : "";
    $qApprIndex = $koneksi->query("SELECT COUNT(1) FROM bc_material_approval WHERE status = 'PENDING' $cmpFilterIndex");
    if ($qApprIndex && $rAI = $qApprIndex->fetch_row()) {
        $cntPendingApproval = (int)$rAI[0];
    }
} catch (\Throwable $e) { $cntPendingApproval = 0; }

$breadcrumbTitlesPhp = [
    'daftar_dokumen'  => 'Dashboard Ceisa 4.0',
    'transfer'        => 'Data Host to Host',
    'editor'          => 'Form Editor Dokumen',
    'import'          => 'Import Excel Dokumen',
    'manifest_ceisa'  => 'Manifes Ceisa 4.0',
    'manifest'        => 'Manifes Host to Host',
    'bc11_monitoring' => 'BC 1.1 Monitoring',
    'dokap'           => 'Upload Dokumen Pelengkap',
    'load_erp_odoo'   => 'Load ERP Data - Odoo to CEISA 4.0',
    'winline_sales_invoice'  => 'Winline ERP — Sales Invoice (BC 2.7, BC 3.0 Customer)',
    'winline_dn_transfer'    => 'Winline ERP — DN Transfer (BC 2.7 Antar Framas)',
    'winline_purchase_order' => 'Winline ERP — Purchase Order (BC 2.3, BC 4.0, BC 1.6)',
    'tracking'        => 'Tracking Respon Pabean',
    'it_inventory_home' => 'Bea Cukai - Home Dashboard',
    'it_inventory_1a' => 'IT Inventory - 1A Pemasukan Barang',
    'it_inventory_1b' => 'IT Inventory - 1B Pengeluaran Barang',
    'it_inventory_1c' => 'IT Inventory - 1C Posisi WIP',
    'it_inventory_1d' => 'IT Inventory - 1D Mutasi Barang',
    'it_inventory_1d_rm' => 'IT Inventory - 1D Mutasi Bahan Baku',
    'it_inventory_1d_fg' => 'IT Inventory - 1D Mutasi Barang Jadi',
    'it_inventory_1d_cg' => 'IT Inventory - 1D Mutasi Mesin & Alat',
    'it_inventory_1d_w' => 'IT Inventory - 1D Mutasi Reject & Scrap',
    'it_inventory_log'=> 'IT Inventory - Log Perubahan Data',
    'reff'            => 'Master Data Referensi',
    'company'         => 'Kelola Perusahaan',
    'app_settings'    => 'Setting Aplikasi',
    'user_management' => 'Kelola User Perusahaan',
    'docs'            => 'Dokumentasi REST API',
    'db'              => 'Database Browser',
    'version_log'     => 'Log Update Versi Sistem',
    'inv_material'    => 'Master Data Barang',
    'inv_koreksi_klas'=> 'Koreksi KLAS IT Inventory',
    'inv_bom'         => 'Master BOM',
    'inv_tarif'       => 'Master Tarif BM dari Kode HS',
    'inv_jaminan'     => 'Master Jaminan',
    'inv_stock_transfer' => 'Mutasi Produksi',
    'inv_warehouse_in'=> 'Warehouse - Incoming',
    'inv_warehouse_delivery' => 'Warehouse - Delivery',
    'inv_transfer_bc27' => 'Warehouse - Transfer BC27 OUT-->IN',
    'inv_stok_opname' => 'Stok Opname',
    'inv_scan27'      => 'Import BC 2.7 IN dan Post ke IT Inv',
    'inv_unposting_appv' => 'Approval Revisi / UnPosting',
    'inv_stok_awal'   => 'Set Stok Awal Tahun',
    'inv_adjust_spp'  => 'Adjust SPP'
];
$initBreadcrumbText = $breadcrumbTitlesPhp[$initialTab] ?? 'Dashboard Ceisa 4.0';

// Catat log menu awal jika user telah login & fitur audit diaktifkan
if (!empty($sessionUserId) && isVisitorMenuLogEnabled($koneksi, $sessionCmpId)) {
    recordMenuAccess($koneksi, $initialTab, $initBreadcrumbText, [
        'user_id'   => $sessionUserId,
        'user_name' => $sessionUserName,
        'cmp_id'    => $sessionCmpId
    ]);
}

$adminParam = $isAdmin ? '&is_admin=1' : '&is_admin=0';
if ($initialTab === 'editor') {
    $ajuParam = !empty($initialAju) ? '&nomor_aju=' . urlencode($initialAju) : '';
    $initSrc = "/editor.php?cmp_id=" . urlencode($sessionCmpId) . "{$ajuParam}&embedded=1{$adminParam}";
} elseif ($initialTab === 'transfer') {
    $initSrc = "/transfer.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'company' && $isAdmin) {
    $initSrc = "/company.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}&_t=" . time();
} elseif ($initialTab === 'app_settings') {
    $initSrc = "/setting_aplikasi.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'user_management') {
    $initSrc = "/user_management.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'import') {
    $initSrc = "/import_excel.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'manifest_ceisa') {
    $initSrc = "/manifest_ceisa.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}&_t=" . time();
} elseif ($initialTab === 'manifest') {
    $initSrc = "/manifest.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}&_t=" . time();
} elseif ($initialTab === 'bc11_monitoring') {
    $ajuParam = !empty($initialAju) ? '&nomor_aju=' . urlencode($initialAju) : '';
    $initSrc = "/bc11_monitoring.php?cmp_id=" . urlencode($sessionCmpId) . "{$ajuParam}&embedded=1{$adminParam}&_t=" . time();
} elseif ($initialTab === 'dokap') {
    $initSrc = "/upload_dokap.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'tracking') {
    $initSrc = "/statusaju.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif (str_starts_with($initialTab, 'it_inventory')) {
    if ($initialTab === 'it_inventory_home') {
        $initSrc = "/it_inventory.php?tab=home&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
    } elseif ($initialTab === 'it_inventory_1d_rm') {
        $initSrc = "/it_inventory.php?tab=1d&klas=Raw+Materials&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
    } elseif ($initialTab === 'it_inventory_1d_fg') {
        $initSrc = "/it_inventory.php?tab=1d&klas=Finished+Goods&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
    } elseif ($initialTab === 'it_inventory_1d_cg') {
        $initSrc = "/it_inventory.php?tab=1d&klas=Capital+Goods&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
    } elseif ($initialTab === 'it_inventory_1d_w') {
        $initSrc = "/it_inventory.php?tab=1d&klas=Wastes&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
    } else {
        $subtab = str_replace('it_inventory_', '', $initialTab);
        $initSrc = "/it_inventory.php?tab=" . urlencode($subtab) . "&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
    }
} elseif ($initialTab === 'reff') {
    $initSrc = "/reff.php?embedded=1{$adminParam}";
} elseif ($initialTab === 'docs') {
    $initSrc = "/docs.php?embedded=1{$adminParam}";
} elseif ($initialTab === 'db' && $isAdmin) {
    $initSrc = "/db.php?embedded=1{$adminParam}";
} elseif ($initialTab === 'version_log') {
    $initSrc = "/version_log.php?embedded=1{$adminParam}&_t=" . time();
} elseif ($initialTab === 'inv_material') {
    $initSrc = "/material.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_koreksi_klas') {
    $initSrc = "/klas_koreksi.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_bom') {
    $initSrc = "/bom.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_tarif') {
    $initSrc = "/tarif.php?embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_jaminan') {
    $initSrc = "/jaminan.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_stock_transfer') {
    $initSrc = "/stock_transfer.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_warehouse_in') {
    $initSrc = "/warehouse_incoming.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_warehouse_delivery') {
    $initSrc = "/warehouse_delivery.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_transfer_bc27') {
    $initSrc = "/transfer_bc27.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_stok_opname') {
    $initSrc = "/stok_opname.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_scan27') {
    $initSrc = "/scan27.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_unposting_appv') {
    $initSrc = "/unposting_approval.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_stok_awal') {
    $initSrc = "/stok_awal_tahun.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'inv_adjust_spp') {
    $initSrc = "/adjust_spp.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'load_erp_odoo') {
    $initSrc = "/load_erp_odoo.php?cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}";
} elseif ($initialTab === 'winline_sales_invoice') {
    $initSrc = "/winline_erp.php?type=sales_invoice&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}&_t=" . time();
} elseif ($initialTab === 'winline_dn_transfer') {
    $initSrc = "/winline_erp.php?type=dn_transfer&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}&_t=" . time();
} elseif ($initialTab === 'winline_purchase_order') {
    $initSrc = "/winline_erp.php?type=purchase_order&cmp_id=" . urlencode($sessionCmpId) . "&embedded=1{$adminParam}&_t=" . time();
} else {
    $initSrc = "/daftar_dokumen.php?embedded=1{$adminParam}&_t=" . time();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appSettings['app_name']) ?> - <?= htmlspecialchars($appSettings['app_subtitle']) ?></title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || '<?= htmlspecialchars($appSettings['default_theme'] ?? 'light') ?>';
            document.documentElement.setAttribute('data-theme', theme);
            var iconStyle = localStorage.getItem('ceisa_icon_style') || '<?= htmlspecialchars($appSettings['sidebar_icon_style'] ?? 'colored') ?>';
            document.documentElement.setAttribute('data-icon-style', iconStyle);
        })();
    </script>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($appSettings['app_favicon']) ?>">
    <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($appSettings['app_favicon']) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-sidebar: #ffffff;
            --bg-topbar: #ffffff;
            --border-color: #ebedf2;
            --border-light: #f0f0f0;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-light: #e6f7ff;
            --accent: #0abb87;
            --warning: #ffb822;
            --danger: #fd3995;
            --text-main: #262626;
            --text-secondary: #595959;
            --text-muted: #8c8c8c;
            color-scheme: light;
        }

        body[data-theme="dark"] {
            --bg-base: #151521;
            --bg-card: #1e1e2d;
            --bg-sidebar: #1a1a27;
            --bg-topbar: #1e1e2d;
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
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 10px;
            line-height: 1.35;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }

        /* -------------------------------------------------------------
           IFRAME VIEW LOADER & PROGRESS BAR
           ------------------------------------------------------------- */
        .iframe-loader-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--bg-base);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 999;
            opacity: 1;
            transition: opacity 0.25s ease, visibility 0.25s ease;
            pointer-events: none;
        }

        .iframe-top-progress {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #1890ff 0%, #40a9ff 50%, #52c41a 100%);
            background-size: 200% 100%;
            animation: iframeProgressAnim 1.4s ease-in-out infinite;
            z-index: 1000;
        }

        @keyframes iframeProgressAnim {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .iframe-spinner {
            width: 38px;
            height: 38px;
            border: 3.5px solid rgba(24, 144, 255, 0.16);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: iframeSpinAnim 0.75s linear infinite;
        }

        @keyframes iframeSpinAnim {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .iframe-loader-title {
            margin-top: 15px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            letter-spacing: 0.2px;
        }

        .iframe-loader-subtitle {
            font-size: 10.5px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* -------------------------------------------------------------
           APP LAYOUT (METRONIC / PORTAL CEISA 4.0 FRAMEWORK)
           ------------------------------------------------------------- */
        .app-layout {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* LEFT SIDEBAR (#kt_aside) */
        .kt-aside {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            height: 100vh;
            z-index: 1000;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            user-select: none;
        }

        /* Pinned Mode: 240px */
        body.sidebar-pinned .kt-aside {
            width: 240px;
        }
        body.sidebar-pinned .kt-wrapper {
            margin-left: 240px;
        }

        /* Unpinned / Autohide Mode: Collapsed 52px, Hover: 240px */
        body.sidebar-unpinned .kt-aside {
            width: 52px;
        }
        body.sidebar-unpinned .kt-wrapper {
            margin-left: 52px;
        }
        body.sidebar-unpinned .kt-aside:hover {
            width: 240px;
            box-shadow: 4px 0 16px rgba(0, 0, 0, 0.08);
        }

        /* Brand Header (Height: 48px) */
        .kt-aside__brand {
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 8px 0 10px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-sidebar);
            flex-shrink: 0;
            gap: 4px;
        }
        .kt-aside__brand-logo {
            display: flex;
            align-items: center;
            overflow: hidden;
            white-space: nowrap;
            flex: 1;
            min-width: 0;
        }
        .kt-aside__brand-logo a {
            display: flex;
            align-items: center;
            text-decoration: none;
            gap: 7px;
            width: 100%;
            min-width: 0;
            overflow: hidden;
        }
        .kt-aside__brand-logo img {
            height: 30px;
            width: 30px;
            max-height: 30px;
            max-width: 30px;
            object-fit: contain;
            border-radius: 5px;
            flex-shrink: 0;
        }
        .logo-text-wrap {
            display: flex;
            flex-direction: column;
            justify-content: center;
            white-space: nowrap;
            overflow: hidden;
            min-width: 0;
            flex: 1;
        }

        .kt-aside__brand-aside-toggler {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5d78ff;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .kt-aside__brand-aside-toggler:hover {
            background: #f0f3ff;
        }
        .kt-svg-icon {
            width: 16px;
            height: 16px;
        }

        /* Hide elements when sidebar is collapsed */
        body.sidebar-unpinned .kt-aside:not(:hover) .logo-text-wrap,
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-aside__brand-tools,
        body.sidebar-unpinned .kt-aside:not(:hover) .sidebar-company-card,
        body.sidebar-unpinned .kt-aside:not(:hover) .Menu_searchWrapper__1Kj_Z,
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__section-text,
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__link-text,
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__ver-arrow,
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-aside__footer {
            display: none !important;
        }
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-aside__brand {
            justify-content: center;
            padding: 0 2px;
        }
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-aside__brand-logo {
            justify-content: center;
            width: 100%;
        }
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-aside__brand-logo a {
            justify-content: center;
            gap: 0 !important;
        }

        /* Sidebar Company Card (NAMA PERUSAHAAN) */
        .sidebar-company-card {
            margin: 8px 12px 2px;
            padding: 6px 10px;
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            user-select: none;
            flex-shrink: 0;
        }
        .sidebar-company-card.is-admin-clickable {
            cursor: pointer;
        }
        .sidebar-company-card:not([data-custom-color="true"]).is-admin-clickable:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.12);
        }
        .sidebar-company-card[data-custom-color="true"].is-admin-clickable:hover {
            filter: brightness(1.08);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.22);
        }
        .sidebar-company-icon {
            font-size: 14px;
            line-height: 1;
            flex-shrink: 0;
        }
        .sidebar-company-info {
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .sidebar-company-name {
            font-size: 11px;
            font-weight: 700;
            color: var(--card-title-color, var(--text-main));
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.25;
        }
        .sidebar-company-card:not([data-custom-color="true"]).is-admin-clickable:hover .sidebar-company-name {
            color: var(--primary);
        }
        .sidebar-company-sub {
            font-size: 9px;
            font-family: monospace;
            color: var(--card-sub-color, var(--text-muted));
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
            margin-top: 1px;
        }
        .sidebar-company-action {
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }
        .sidebar-company-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 6px;
            border-radius: 4px;
            background: var(--card-btn-bg, var(--primary-light));
            color: var(--card-btn-color, var(--primary));
            border: 1px solid var(--card-btn-border, transparent);
            font-size: 9px;
            font-weight: 600;
            transition: all 0.2s;
            gap: 2px;
        }
        .sidebar-company-card:not([data-custom-color="true"]).is-admin-clickable:hover .sidebar-company-btn {
            background: var(--primary);
            color: #fff;
        }
        .sidebar-company-card[data-custom-color="true"].is-admin-clickable:hover .sidebar-company-btn {
            filter: brightness(1.15);
        }

        /* Sidebar Search Box (Matches CEISA 4.0 .Menu_searchWrapper__1Kj_Z) */
        .Menu_searchWrapper__1Kj_Z {
            position: relative;
            padding: 6px 12px 4px;
            margin-top: 2px;
            margin-bottom: 0;
            background-color: var(--bg-sidebar);
            flex-shrink: 0;
        }
        .Menu_searchInput__1HOa_ {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #d9d9d9 !important;
            border-radius: 6px;
            padding: 4px 10px;
            background-color: var(--bg-base) !important;
            min-height: 32px;
            transition: background-color .2s ease, border-color .2s ease;
        }
        .Menu_searchInput__1HOa_:focus-within {
            background-color: #fff !important;
            border-color: #1890ff !important;
        }
        .Menu_searchIcon__22bzz {
            color: #8c8c8c;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }
        .Menu_searchNativeInput__3mq3t {
            flex: 1 1 auto;
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            height: 22px;
            font-size: 9.5px;
            color: var(--text-main);
            font-family: inherit;
        }

        /* Sidebar Menu Nav */
        .kt-aside-menu {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 4px 0 14px;
        }
        .kt-aside-menu::-webkit-scrollbar { width: 4px; }
        .kt-aside-menu::-webkit-scrollbar-thumb { background: #d9d9d9; border-radius: 4px; }

        /* Sidebar Footer (Versi Update) */
        .kt-aside__footer {
            padding: 5px 8px;
            font-size: 8.5px;
            font-weight: 500;
            color: var(--text-muted);
            opacity: 0.85;
            border-top: 1px solid var(--border-color);
            background: var(--bg-sidebar);
            flex-shrink: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: center;
            letter-spacing: 0.2px;
            user-select: none;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .kt-aside__footer:hover {
            color: #1890ff;
            opacity: 1;
            background: rgba(24, 144, 255, 0.08);
            box-shadow: 0 -2px 10px rgba(24, 144, 255, 0.25);
        }
        .kt-aside__footer:hover #sidebarAppVersion {
            color: #1890ff;
            text-shadow: 0 0 6px rgba(24, 144, 255, 0.5);
        }

        .kt-menu__section {
            margin: 6px 0 2px;
        }
        .kt-menu__section-text {
            font-size: 8.5px;
            font-weight: 700;
            color: #8c8c8c;
            letter-spacing: 0.5px;
            padding: 6px 18px 3px;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .kt-menu__item {
            display: block;
        }
        .kt-menu__link {
            display: flex;
            align-items: center;
            padding: 7px 18px;
            color: var(--text-secondary);
            font-size: 9.5px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
            border-left: 3px solid transparent;
        }
        .kt-menu__link:hover {
            color: #1890ff;
            background: #f0f7ff;
        }
        .kt-menu__item.active .kt-menu__link {
            color: #1890ff;
            background: #e6f7ff;
            font-weight: 600;
            border-left-color: #1890ff;
        }
        html[data-theme="dark"] .kt-menu__link,
        body[data-theme="dark"] .kt-menu__link {
            color: rgba(255, 255, 255, 0.7);
        }
        html[data-theme="dark"] .kt-menu__link:hover,
        body[data-theme="dark"] .kt-menu__link:hover {
            color: #177ddc !important;
            background: rgba(23, 125, 220, 0.12) !important;
        }
        html[data-theme="dark"] .kt-menu__item.active .kt-menu__link,
        body[data-theme="dark"] .kt-menu__item.active .kt-menu__link {
            color: #177ddc !important;
            background: #111d2c !important;
            border-left-color: #177ddc !important;
        }
        html[data-theme="dark"] .kt-menu__link-icon,
        body[data-theme="dark"] .kt-menu__link-icon {
            color: rgba(255, 255, 255, 0.55);
        }
        html[data-theme="dark"] .kt-menu__link:hover .kt-menu__link-icon,
        body[data-theme="dark"] .kt-menu__link:hover .kt-menu__link-icon {
            color: #177ddc !important;
        }
        html[data-theme="dark"] .kt-menu__item.active .kt-menu__link-icon,
        body[data-theme="dark"] .kt-menu__item.active .kt-menu__link-icon {
            color: #177ddc !important;
        }
        .kt-menu__link-icon {
            width: 16px;
            height: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-right: 8px;
            flex-shrink: 0;
            color: #595959;
            transition: color 0.2s;
        }
        .kt-menu__link-icon svg {
            width: 16px;
            height: 16px;
            display: block;
            flex-shrink: 0;
        }
        .kt-menu__link-icon .icon-colored {
            font-size: 13.5px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .kt-menu__link-icon .icon-monochrome {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Gaya Ikon Default: Berwarna (Emoji / Color Icons) */
        html:not([data-icon-style="monochrome"]) .kt-menu__link-icon .icon-monochrome,
        body:not([data-icon-style="monochrome"]) .kt-menu__link-icon .icon-monochrome,
        html[data-icon-style="colored"] .kt-menu__link-icon .icon-monochrome,
        body[data-icon-style="colored"] .kt-menu__link-icon .icon-monochrome {
            display: none !important;
        }
        html:not([data-icon-style="monochrome"]) .kt-menu__link-icon .icon-colored,
        body:not([data-icon-style="monochrome"]) .kt-menu__link-icon .icon-colored,
        html[data-icon-style="colored"] .kt-menu__link-icon .icon-colored,
        body[data-icon-style="colored"] .kt-menu__link-icon .icon-colored {
            display: inline-flex !important;
        }

        /* Gaya Ikon Opsi: Monokrom (Ant Design Vector Minimalis) */
        html[data-icon-style="monochrome"] .kt-menu__link-icon .icon-colored,
        body[data-icon-style="monochrome"] .kt-menu__link-icon .icon-colored {
            display: none !important;
        }
        html[data-icon-style="monochrome"] .kt-menu__link-icon .icon-monochrome,
        body[data-icon-style="monochrome"] .kt-menu__link-icon .icon-monochrome {
            display: inline-flex !important;
        }
        .kt-menu__link:hover .kt-menu__link-icon {
            color: #1890ff;
        }
        .kt-menu__item.active .kt-menu__link-icon {
            color: #1890ff;
        }
        .kt-menu__link-text {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 9.5px;
        }
        .kt-menu__ver-arrow {
            font-size: 8.5px;
            color: #8c8c8c;
            margin-left: auto;
        }

        /* Expandable Submenu in Sidebar */
        .kt-menu__item--submenu {
            position: relative;
        }
        .kt-menu__item--submenu > .kt-menu__link {
            cursor: pointer;
            user-select: none;
        }
        .kt-menu__arrow {
            font-size: 8px;
            color: var(--text-muted);
            margin-left: auto;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .kt-menu__item--submenu.kt-menu__item--open > .kt-menu__link .kt-menu__arrow {
            transform: rotate(90deg);
            color: var(--primary);
        }
        .kt-menu__item--submenu.kt-menu__item--open > .kt-menu__link {
            color: var(--primary);
        }
        .kt-menu__sub {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: rgba(0, 0, 0, 0.02);
            border-left: 2px solid var(--border-color);
            margin-left: 22px;
            border-radius: 0 0 6px 0;
        }
        html[data-theme="dark"] .kt-menu__sub,
        body[data-theme="dark"] .kt-menu__sub {
            background: rgba(255, 255, 255, 0.02);
            border-left-color: rgba(255, 255, 255, 0.1);
        }
        .kt-menu__item--submenu.kt-menu__item--open > .kt-menu__sub {
            max-height: 400px;
        }
        .kt-menu__sub .kt-menu__link {
            padding: 6px 14px 6px 12px;
            font-size: 9px;
            border-left: 2px solid transparent;
        }
        .kt-menu__sub .kt-menu__item.active .kt-menu__link {
            border-left-color: var(--primary);
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 600;
        }
        .kt-menu__badge-sub {
            font-size: 8px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 4px;
            background: var(--primary-light);
            color: var(--primary);
            margin-left: auto;
            letter-spacing: 0.3px;
        }

        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__arrow,
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__badge-sub,
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__sub {
            display: none !important;
        }

        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__link {
            justify-content: center;
            padding: 10px 0;
            border-left: none;
        }
        body.sidebar-unpinned .kt-aside:not(:hover) .kt-menu__link-icon {
            margin-right: 0;
        }

        /* -------------------------------------------------------------
           MAIN CONTENT WRAPPER (#kt_wrapper)
           ------------------------------------------------------------- */
        .kt-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            background: var(--bg-base);
            transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* TOPBAR HEADER (#kt_header - Height: 38px) */
        .kt-header {
            height: 38px;
            background: var(--bg-topbar);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;
            position: sticky;
            top: 0;
            z-index: 900;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
            white-space: nowrap;
        }

        .kt-header-left {
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .kt-header-title {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .kt-header-breadcrumb {
            color: #1890ff;
            font-weight: 500;
            font-size: 10.5px;
        }

        /* Company Switcher Pill for Admin */
        .btn-company-switcher {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            padding: 1px 6px;
            height: 22px;
            border-radius: 3px;
            cursor: pointer;
            color: var(--text-main);
            font-size: 9.5px;
            font-family: inherit;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-company-switcher:hover {
            background: #bae7ff;
            border-color: #1890ff;
        }
        body[data-theme="dark"] .btn-company-switcher,
        html[data-theme="dark"] .btn-company-switcher {
            background: #111d2c;
            border-color: #15395b;
            color: #177ddc;
        }
        body[data-theme="dark"] .btn-company-switcher:hover,
        html[data-theme="dark"] .btn-company-switcher:hover {
            background: #15395b;
            border-color: #177ddc;
            color: #69c0ff;
        }
        .badge-role-admin {
            background: #faad14;
            color: #fff;
            font-weight: 700;
            font-size: 7.5px;
            padding: 1px 3px;
            border-radius: 2px;
        }
        .badge-cmp-name {
            font-weight: 600;
            font-size: 9.5px;
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .badge-switch-action {
            color: #1890ff;
            font-size: 9px;
            font-weight: 600;
        }

        /* Topbar Right Side (Token Expired, Notif, User Greeting, MW avatar) */
        .kt-header__topbar {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        /* Token Expired */
        .kt-header__topbar-user {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .kt-header__topbar-welcome {
            font-size: 9.5px;
            color: var(--text-muted);
        }
        .ant-statistic-content-value {
            font-size: 10.5px;
            color: rgb(63, 134, 0);
            font-weight: 700;
            font-family: monospace;
        }

        /* Notification Bell */
        .notif-bell {
            position: relative;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .notif-bell:hover {
            background: rgba(24, 144, 255, 0.12);
        }
        .notif-header-badge {
            background: #ff5757;
            height: 5px;
            width: 5px;
            border-radius: 50%;
            position: absolute;
            top: 2px;
            right: 2px;
            border: 1px solid #fff;
        }

        /* User Profile Badge */
        .kt-header__topbar-username {
            font-weight: 600;
            color: var(--text-main);
            font-size: 10px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .kt-badge--unified-success {
            background: rgba(10, 187, 135, 0.1);
            color: #0abb87;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 8.5px;
            border-radius: 3px;
            letter-spacing: 0.2px;
        }

        /* Logout button style from CEISA 4.0 */
        .btn-logout {
            background: #f0f3ff;
            color: #5d78ff;
            border: 1px solid #d9dffa;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            line-height: 18px;
        }
        .btn-logout:hover {
            background: #5d78ff;
            color: #ffffff;
        }

        /* Theme Toggle */
        .theme-select {
            display: inline-flex;
            gap: 2px;
            background: var(--bg-base);
            padding: 1px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
        }
        .theme-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 9px;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .theme-btn.active {
            background: #ffffff;
            color: #1890ff;
            font-weight: 600;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Icon Style Selector */
        .icon-style-select {
            display: inline-flex;
            gap: 2px;
            background: var(--bg-base);
            padding: 1px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
        }
        .icon-style-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 9.5px;
            line-height: 1.2;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .icon-style-btn.active {
            background: #ffffff;
            color: #1890ff;
            font-weight: 600;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        html[data-theme="dark"] .icon-style-btn.active,
        body[data-theme="dark"] .icon-style-btn.active {
            background: #177ddc;
            color: #ffffff;
        }

        /* MFA Topbar Button & Modal Styles */
        .btn-topbar-mfa {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            height: 22px;
            transition: all 0.2s;
        }
        .btn-topbar-mfa:hover {
            background: #1890ff;
            color: #ffffff;
            border-color: #1890ff;
            box-shadow: 0 1px 4px rgba(24, 144, 255, 0.25);
        }
        .btn-topbar-portal {
            background: #fafafa;
            color: #595959;
            border: 1px solid #d9d9d9;
            padding: 1px 7px;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            height: 22px;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-topbar-portal:hover {
            background: #e6f7ff;
            color: #1890ff;
            border-color: #91d5ff;
        }
        body[data-theme="dark"] .btn-topbar-portal {
            background: #2b2b40;
            color: #e1e3ea;
            border-color: #3f4254;
        }
        body[data-theme="dark"] .btn-topbar-portal:hover {
            background: #1890ff;
            color: #ffffff;
            border-color: #1890ff;
        }
        .totp-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 12px 16px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
            user-select: none;
        }
        .totp-card:hover {
            border-color: #1890ff;
            box-shadow: 0 3px 10px rgba(24, 144, 255, 0.12);
            transform: translateY(-1px);
        }
        .totp-issuer {
            font-size: 11.5px;
            font-weight: 500;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .totp-badge-auto {
            font-size: 8.5px;
            font-weight: 600;
            background: #e6f7ff;
            color: #1890ff;
            padding: 1px 5px;
            border-radius: 2px;
            border: 1px solid #91d5ff;
        }
        .totp-code-wrap {
            margin: 6px 0;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
        }
        .totp-code {
            font-family: 'Roboto Mono', 'Poppins', monospace;
            font-size: 32px;
            font-weight: 700;
            color: #0b82df;
            letter-spacing: 2px;
            line-height: 1.1;
        }
        [data-theme="dark"] .totp-code {
            color: #177ddc;
        }
        .totp-copy-icon {
            font-size: 10px;
            color: var(--text-muted);
            opacity: 0.7;
            transition: all 0.2s;
        }
        .totp-card:hover .totp-copy-icon {
            color: #1890ff;
            opacity: 1;
            transform: scale(1.05);
        }
        .totp-username {
            font-size: 11.5px;
            font-weight: 500;
            color: var(--text-main);
        }
        .timer-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed var(--border-color);
            font-size: 9.5px;
            color: var(--text-muted);
        }
        .progress-bar-bg {
            flex: 1;
            height: 3px;
            background: var(--border-color);
            border-radius: 2px;
            margin: 0 6px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: #1890ff;
            width: 100%;
            transition: width 1s linear, background-color 0.3s;
        }
        .progress-bar-fill.warning { background: #faad14; }
        .progress-bar-fill.danger { background: #f5222d; }

        /* Iframe Container (Full viewport height below 38px header) */
        .iframe-container {
            flex: 1;
            width: 100%;
            height: calc(100vh - 38px);
            border: none;
            background: var(--bg-base);
        }
        .kt-footer__copyright {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .kt-footer__menu {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .kt-footer__menu a {
            color: #595959;
            text-decoration: none;
        }
        .kt-footer__menu a:hover {
            color: #1890ff;
        }

        /* =============================================================
           MODERN HIGH-END LOGIN SCREEN (INHOST CEISA 4.0)
           ============================================================= */
        .login-bg-layer {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            height: 100vh;
            min-height: 100dvh;
            z-index: 0;
            pointer-events: none;
            background-size: cover !important;
            background-position: center center !important;
            background-repeat: no-repeat !important;
            background-attachment: fixed !important;
            background-color: #ffffff;
            transition: background 0.3s ease;
        }
        .login-bg-layer.bg-type-default {
            background-color: #f1f5f9;
            background-image: 
                radial-gradient(at 0% 0%, rgba(24, 144, 255, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(14, 165, 233, 0.06) 0px, transparent 50%);
        }
        body[data-theme="dark"] .login-bg-layer.bg-type-default {
            background-color: #0b1120;
            background-image: 
                radial-gradient(at 0% 0%, rgba(24, 144, 255, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(30, 41, 59, 0.8) 0px, transparent 50%);
        }

        .login-bg-overlay {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100vh;
            min-height: 100dvh;
            background: rgba(0, 0, 0, var(--wp-dimmer, 0));
            z-index: 1;
            pointer-events: none;
            transition: background 0.2s ease;
        }

        .login-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 16px;
            min-height: 100vh;
            min-height: 100dvh;
            width: 100%;
            position: relative;
            z-index: 2;
            overflow-x: hidden;
            background: transparent;
        }

        .login-wrap .login-card,
        .login-wrap .login-theme-box,
        .login-wrap .login-page-footer {
            position: relative;
            z-index: 3;
        }

        .login-theme-box {
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            background: rgba(255, 255, 255, 0.85) !important;
            border: 1px solid rgba(0, 0, 0, 0.08) !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06) !important;
            padding: 3px !important;
            border-radius: 20px !important;
            transition: all 0.2s ease;
        }
        body[data-theme="dark"] .login-theme-box {
            background: rgba(20, 27, 45, 0.88) !important;
            border-color: rgba(255, 255, 255, 0.12) !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4) !important;
        }

        .login-card {
            position: relative;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            width: 100%;
            max-width: 420px;
            padding: 36px 32px 30px;
            box-shadow: 
                0 10px 25px -5px rgba(15, 23, 42, 0.08),
                0 8px 10px -6px rgba(15, 23, 42, 0.04);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 2;
            overflow: hidden;
            animation: loginCardFadeIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes loginCardFadeIn {
            from {
                opacity: 0;
                transform: translateY(12px) scale(0.99);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        body[data-theme="dark"] .login-card {
            background: #111827;
            border: 1px solid #1f2937;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5);
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #1890ff 0%, #096dd9 100%);
        }

        .login-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .login-brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            padding: 8px 18px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 16px;
            transition: all 0.2s;
        }
        body[data-theme="dark"] .login-brand-badge {
            background: #151d2f;
            border-color: #27354f;
        }
        .login-brand-logo {
            width: 54px;
            height: 54px;
            max-width: 72px;
            max-height: 54px;
            object-fit: contain;
            border-radius: 9px;
            background: #ffffff;
            padding: 3px;
            border: 1px solid rgba(0, 0, 0, 0.06);
            filter: drop-shadow(0 2px 5px rgba(24, 144, 255, 0.16));
            flex-shrink: 0;
        }
        body[data-theme="dark"] .login-brand-logo {
            background: #1e293b;
            border-color: rgba(255, 255, 255, 0.08);
        }
        .login-brand-title {
            font-size: 21px;
            font-weight: 800;
            letter-spacing: 0.5px;
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.15;
        }
        .login-brand-sub {
            font-size: 8.5px;
            font-weight: 700;
            color: #8c8c8c;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            line-height: 1.25;
            margin-top: 2px;
        }
        .login-header h2 {
            font-size: 16.5px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0 0 5px 0;
            letter-spacing: -0.2px;
        }
        .login-header p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
            line-height: 1.45;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }
        .form-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-secondary);
            letter-spacing: 0.1px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .form-group input, .form-group select {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 0 12px;
            height: 36px;
            border-radius: 8px;
            font-size: 12px;
            font-family: inherit;
            outline: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
            width: 100%;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: #1890ff;
            box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.18);
        }
        body[data-theme="dark"] .form-group input,
        body[data-theme="dark"] .form-group select {
            background: #111827;
            border-color: #27354f;
            color: rgba(255, 255, 255, 0.88);
        }
        body[data-theme="dark"] .form-group input:focus,
        body[data-theme="dark"] .form-group select:focus {
            border-color: #177ddc;
            box-shadow: 0 0 0 3px rgba(23, 125, 220, 0.28);
        }

        .label-badge-hint {
            font-size: 10px;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: 4px;
            background: rgba(24, 144, 255, 0.08);
            color: #1890ff;
            border: 1px solid rgba(24, 144, 255, 0.2);
            font-family: inherit;
        }
        body[data-theme="dark"] .label-badge-hint {
            background: rgba(23, 125, 220, 0.15);
            border-color: rgba(23, 125, 220, 0.35);
            color: #40a9ff;
        }

        /* Override browser autofill box-shadow & text color */
        .login-card input:-webkit-autofill,
        .login-card input:-webkit-autofill:hover, 
        .login-card input:-webkit-autofill:focus, 
        .login-card input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 40px #ffffff inset !important;
            -webkit-text-fill-color: #262626 !important;
            transition: background-color 5000s ease-in-out 0s;
        }
        body[data-theme="dark"] .login-card input:-webkit-autofill,
        body[data-theme="dark"] .login-card input:-webkit-autofill:hover, 
        body[data-theme="dark"] .login-card input:-webkit-autofill:focus, 
        body[data-theme="dark"] .login-card input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 40px #111827 inset !important;
            -webkit-text-fill-color: #f1f5f9 !important;
        }

        .corporate-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .corporate-input-wrap .input-icon {
            position: absolute;
            left: 12px;
            color: #8c8c8c;
            font-size: 13px;
            pointer-events: none;
            transition: color 0.2s;
            z-index: 1;
        }
        .corporate-input-wrap input {
            padding-left: 36px !important;
        }
        .corporate-input-wrap:focus-within .input-icon {
            color: #1890ff;
        }
        body[data-theme="dark"] .corporate-input-wrap .input-icon {
            color: #64748b;
        }
        body[data-theme="dark"] .corporate-input-wrap:focus-within .input-icon {
            color: #40a9ff;
        }

        .input-password-wrap {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .input-password-wrap input {
            width: 100%;
            padding-left: 36px !important;
            padding-right: 38px !important;
        }
        .input-password-wrap .input-icon {
            position: absolute;
            left: 12px;
            color: #8c8c8c;
            font-size: 13px;
            pointer-events: none;
            transition: color 0.2s;
            z-index: 1;
        }
        .input-password-wrap:focus-within .input-icon {
            color: #1890ff;
        }
        body[data-theme="dark"] .input-password-wrap .input-icon {
            color: #64748b;
        }
        body[data-theme="dark"] .input-password-wrap:focus-within .input-icon {
            color: #40a9ff;
        }
        .btn-toggle-pass {
            position: absolute;
            right: 8px;
            background: transparent;
            border: none;
            color: #8c8c8c;
            cursor: pointer;
            padding: 6px 8px;
            font-size: 13px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            user-select: none;
            z-index: 2;
        }
        .btn-toggle-pass:hover {
            color: #1890ff;
            background: rgba(24, 144, 255, 0.08);
        }
        body[data-theme="dark"] .btn-toggle-pass:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #40a9ff;
        }

        .btn-login-submit {
            width: 100%;
            height: 42px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            letter-spacing: 0.6px !important;
            border-radius: 8px !important;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%) !important;
            box-shadow: 0 4px 14px rgba(24, 144, 255, 0.32) !important;
            border: none !important;
            color: #ffffff !important;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
            cursor: pointer;
        }
        .btn-login-submit:hover {
            transform: translateY(-1.5px) !important;
            box-shadow: 0 6px 20px rgba(24, 144, 255, 0.45) !important;
            background: linear-gradient(135deg, #40a9ff 0%, #1890ff 100%) !important;
        }
        .btn-login-submit:active {
            transform: translateY(0) !important;
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.25) !important;
        }

        .login-divider {
            position: relative;
            text-align: center;
            margin: 22px 0 16px 0;
        }
        .login-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--border-color);
        }
        .login-divider span {
            position: relative;
            background: #ffffff;
            padding: 0 12px;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        body[data-theme="dark"] .login-divider span {
            background: #141b2d;
        }
        .portal-link-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            background: rgba(24, 144, 255, 0.06);
            border: 1px solid rgba(24, 144, 255, 0.25);
            border-radius: 20px;
            color: #1890ff;
            font-size: 11.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .portal-link-pill:hover {
            background: #1890ff;
            color: #ffffff;
            border-color: #1890ff;
            box-shadow: 0 4px 12px rgba(24, 144, 255, 0.3);
            transform: translateY(-1px);
        }
        body[data-theme="dark"] .portal-link-pill {
            background: rgba(23, 125, 220, 0.12);
            border-color: rgba(23, 125, 220, 0.35);
            color: #40a9ff;
        }
        body[data-theme="dark"] .portal-link-pill:hover {
            background: #177ddc;
            color: #ffffff;
        }
        .login-security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .login-page-footer {
            margin-top: 22px;
            font-size: 11px;
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            z-index: 3;
            padding: 6px 18px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
        }
        body[data-theme="dark"] .login-page-footer {
            background: rgba(20, 27, 45, 0.9);
            border-color: rgba(255, 255, 255, 0.12);
            color: #94a3b8;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        .login-page-footer .footer-dot {
            opacity: 0.4;
        }
        #loginMsg {
            background: rgba(255, 77, 79, 0.08);
            border: 1px solid rgba(255, 77, 79, 0.25);
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 11.5px;
            font-weight: 500;
            color: #ff4d4f;
            margin-bottom: 14px;
            display: none;
            animation: modalFadeIn 0.2s ease;
        }

        /* -------------------------------------------------------------
           MODERN ANT DESIGN & CEISA 4.0 BUTTON SYSTEM (.ant-btn)
           ------------------------------------------------------------- */
        .ant-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 30px;
            padding: 0 14px;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 11px;
            font-weight: 500;
            line-height: 1.4;
            white-space: nowrap;
            text-align: center;
            color: var(--text-main);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
            text-decoration: none;
            outline: none;
        }
        .ant-btn:hover {
            color: #1890ff;
            background: var(--bg-card);
            border-color: #1890ff;
            box-shadow: 0 2px 8px rgba(24, 144, 255, 0.15);
            transform: translateY(-1px);
        }
        .ant-btn:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        .ant-btn:focus-visible {
            box-shadow: 0 0 0 3px rgba(24, 144, 255, 0.25);
            border-color: #1890ff;
        }

        /* Primary Button */
        .ant-btn-primary {
            color: #ffffff !important;
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%) !important;
            border: 1px solid #1890ff !important;
            box-shadow: 0 2px 6px rgba(24, 144, 255, 0.35) !important;
            font-weight: 600;
            text-shadow: 0 -1px 0 rgba(0, 0, 0, 0.12);
        }
        .ant-btn-primary:hover {
            color: #ffffff !important;
            background: linear-gradient(135deg, #40a9ff 0%, #1890ff 100%) !important;
            border-color: #40a9ff !important;
            box-shadow: 0 4px 14px rgba(24, 144, 255, 0.45) !important;
            transform: translateY(-1px);
        }
        .ant-btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 1px 4px rgba(24, 144, 255, 0.3) !important;
        }

        /* Success Button */
        .ant-btn-success {
            color: #ffffff !important;
            background: linear-gradient(135deg, #52c41a 0%, #389e0d 100%) !important;
            border: 1px solid #52c41a !important;
            box-shadow: 0 2px 6px rgba(82, 196, 26, 0.3) !important;
            font-weight: 600;
        }
        .ant-btn-success:hover {
            background: linear-gradient(135deg, #73d13d 0%, #52c41a 100%) !important;
            box-shadow: 0 4px 12px rgba(82, 196, 26, 0.4) !important;
            transform: translateY(-1px);
        }

        /* Secondary / Default Button */
        .ant-btn-default {
            color: var(--text-main);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }
        .ant-btn-default:hover {
            color: #1890ff;
            background: rgba(24, 144, 255, 0.04);
            border-color: #1890ff;
        }

        /* Dark Mode Button Overrides */
        body[data-theme="dark"] .ant-btn {
            background: #1f1f1f;
            border-color: #303030;
            color: rgba(255, 255, 255, 0.85);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
        }
        body[data-theme="dark"] .ant-btn:hover {
            border-color: #177ddc;
            color: #177ddc;
            background: #262626;
            box-shadow: 0 2px 8px rgba(23, 125, 220, 0.25);
        }
        body[data-theme="dark"] .ant-btn-default:hover {
            background: rgba(23, 125, 220, 0.12);
            border-color: #177ddc;
            color: #177ddc;
        }
        body[data-theme="dark"] .ant-btn-primary {
            background: linear-gradient(135deg, #177ddc 0%, #165996 100%) !important;
            border-color: #177ddc !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(23, 125, 220, 0.4) !important;
        }
        body[data-theme="dark"] .ant-btn-primary:hover {
            background: linear-gradient(135deg, #3c9ae8 0%, #177ddc 100%) !important;
            border-color: #3c9ae8 !important;
            box-shadow: 0 4px 14px rgba(23, 125, 220, 0.5) !important;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            padding: 20px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            width: 100%;
            max-width: 540px;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.28), 0 0 1px 1px rgba(0, 0, 0, 0.05);
            animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96) translateY(4px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            padding: 12px 18px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--table-head-bg, #fafafa);
        }
        body[data-theme="dark"] .modal-header {
            background: #141414;
        }
        .modal-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .modal-close-btn {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal-close-btn:hover {
            background: rgba(0, 0, 0, 0.06);
            color: var(--text-main);
        }
        body[data-theme="dark"] .modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .modal-body {
            padding: 16px 20px;
        }
        .modal-footer {
            padding: 10px 18px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            background: var(--table-head-bg, #fafafa);
        }
        body[data-theme="dark"] .modal-footer {
            background: #141414;
        }

        .company-card-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .company-card-item:hover {
            border-color: #1890ff;
            background: #e6f7ff;
            transform: translateX(2px);
        }
        body[data-theme="dark"] .company-card-item:hover {
            background: #111d2c;
            border-color: #177ddc;
        }
        .company-card-item.is-active-company {
            border-color: #1890ff;
            background: #e6f7ff;
        }
        body[data-theme="dark"] .company-card-item.is-active-company {
            border-color: #177ddc;
            background: #111d2c;
        }

        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: linear-gradient(135deg, #52c41a 0%, #389e0d 100%);
            color: #fff;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 500;
            display: none;
            box-shadow: 0 6px 18px rgba(82, 196, 26, 0.35);
            z-index: 3000;
            animation: modalFadeIn 0.2s ease;
        }
    </style>
</head>
<body data-theme="light" class="sidebar-pinned">

    <?php if (!$isLoggedIn): ?>
    <?php
    // Resolusi Wallpaper & Background Layar Login
    $loginBgType = $appSettings['login_bg_type'] ?? 'image';
    $loginBgImg  = !empty($appSettings['login_bg_image']) ? $appSettings['login_bg_image'] : '/assets/wallpaper-batik-default.png';
    $loginBgGrad = $appSettings['login_bg_gradient'] ?? 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)';
    $loginBgDim  = floatval($appSettings['login_bg_dimmer'] ?? 0);
    if ($loginBgDim < 0) $loginBgDim = 0;
    if ($loginBgDim > 0.85) $loginBgDim = 0.85;

    $loginBgStyle = '';
    if ($loginBgType === 'image') {
        $loginBgStyle = "background-image: url('" . htmlspecialchars($loginBgImg) . "'); background-color: #ffffff;";
    } elseif ($loginBgType === 'gradient' && !empty($loginBgGrad)) {
        $loginBgStyle = "background: " . htmlspecialchars($loginBgGrad) . ";";
    }
    ?>
    <!-- FULLSCREEN RESPONSIVE BACKGROUND & OVERLAY -->
    <div class="login-bg-layer <?= ($loginBgType === 'default') ? 'bg-type-default' : '' ?>" style="<?= $loginBgStyle ?>"></div>
    <?php if ($loginBgType === 'image' && $loginBgDim > 0.01): ?>
    <div class="login-bg-overlay" style="--wp-dimmer: <?= $loginBgDim ?>;"></div>
    <?php endif; ?>

    <!-- 1. STANDALONE LOGIN SCREEN -->
    <div style="position:fixed; top:20px; right:24px; z-index:100;">
        <div class="theme-select login-theme-box">
            <button class="theme-btn active" data-theme="light" onclick="setTheme('light')" style="border-radius:16px;"><i class="fa-solid fa-sun" style="font-size:10.5px; margin-right:3px;"></i> Light</button>
            <button class="theme-btn" data-theme="dark" onclick="setTheme('dark')" style="border-radius:16px;"><i class="fa-solid fa-moon" style="font-size:10.5px; margin-right:3px;"></i> Dark</button>
        </div>
    </div>

    <div class="login-wrap">
        <div class="login-card">
            <div class="login-header">
                <!-- Brand Badge (Logo, App Name, Subtitle) -->
                <div style="display:flex; justify-content:center; align-items:center; gap:10px; margin-bottom:14px;">
                    <div class="login-brand-badge">
                        <img src="<?= htmlspecialchars($appSettings['app_logo']) ?>" alt="<?= htmlspecialchars($appSettings['app_name']) ?>" class="login-brand-logo" onerror="if(this.src.indexOf('inhost.web.id') === -1 && this.getAttribute('src') && this.getAttribute('src').indexOf('/uploads/') === 0) { this.src = 'https://inhost.web.id' + this.getAttribute('src'); } else { this.src = '/assets/logo.png'; }">
                        <div style="text-align:left;">
                            <div class="login-brand-title"><?= htmlspecialchars($appSettings['app_name']) ?></div>
                            <div class="login-brand-sub"><?= htmlspecialchars($appSettings['app_subtitle']) ?></div>
                        </div>
                    </div>
                </div>
                <?php 
                $showLoginCompanyTitle = ($appSettings['show_login_company_title'] ?? '0') === '1';
                ?>
                <?php if ($isAdminOnlyLogin): ?>
                    <div style="margin-top:2px; margin-bottom:14px;">
                        <h2 style="font-size:18px; margin-bottom:4px; font-weight:700; color:var(--text-main); letter-spacing:-0.2px;">Login Administrator</h2>
                        <p style="font-size:12px; color:var(--text-muted); margin:0;">Silakan masukkan akun Administrator</p>
                    </div>
                <?php elseif ($isFramasPortal): ?>
                    <div style="margin-top:2px; margin-bottom:14px;">
                        <h2 style="font-size:18px; margin-bottom:4px; font-weight:700; color:var(--text-main); letter-spacing:-0.2px;">Masuk ke Sistem</h2>
                        <p style="font-size:12px; color:var(--text-muted); margin:0;">Portal Akses CEISA 4.0 &bull; FRAMAS Group</p>
                    </div>
                <?php elseif ($subdomainCompany): ?>
                    <?php if ($showLoginCompanyTitle): ?>
                        <div id="loginCompanyTitleWrap" style="margin-top:2px; margin-bottom:8px;">
                            <h2 style="font-size:18px; margin-bottom:4px; font-weight:700; color:var(--text-main); letter-spacing:-0.2px;"><?= htmlspecialchars($subdomainCompany['cmp_name']) ?></h2>
                            <p style="margin:2px 0 0 0; font-size:12px; color:var(--text-muted); font-weight:500;">
                                Portal Akses Interface Host to Host
                            </p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <h2 style="font-size:18px; margin-bottom:4px; font-weight:700; color:var(--text-main);"><?= htmlspecialchars($appSettings['app_subtitle']) ?></h2>
                    <p style="font-size:12px; color:var(--text-muted);">Silakan pilih profil Administrator atau Perusahaan Terdaftar</p>
                <?php endif; ?>
            </div>
            <form id="formLogin" onsubmit="submitLogin(event)" autocomplete="off">
                <!-- Anti-autofill dummy hidden traps (mencegah browser Chrome/Edge mengisi kredensial otomatis) -->
                <div style="opacity:0; position:absolute; top:-9999px; left:-9999px; height:0; width:0; overflow:hidden; z-index:-1;" aria-hidden="true">
                    <input type="text" name="fake_user_remember" tabindex="-1" autocomplete="off">
                    <input type="password" name="fake_pass_remember" tabindex="-1" autocomplete="new-password">
                </div>
                <?php if ($isAdminOnlyLogin): ?>
                    <!-- Akses Khusus Administrator -->
                    <input type="hidden" id="loginCmp" value="admin">
                <?php elseif ($isFramasPortal): ?>
                    <!-- Portal Bersama Seluruh Entitas Framas (FRM-1, FRM-2, FRM-3, PDPLB) -->
                    <input type="hidden" id="loginCmp" value="framas">
                <?php elseif ($subdomainCompany): ?>
                    <!-- Perusahaan Otomatis dari Subdomain -->
                    <input type="hidden" id="loginCmp" value="<?= htmlspecialchars($subdomainCompany['cmp_id']) ?>">
                <?php else: ?>
                    <div class="form-group">
                        <label for="loginCmp">
                            <span><i class="fa-solid fa-building" style="color:var(--primary); margin-right:6px; font-size:12px;"></i>Pilih Akses / Perusahaan</span>
                        </label>
                        <select id="loginCmp" required style="width:100%;" onchange="onLoginCmpChange(this.value)">
                            <option value="">-- Silakan Pilih Akses Profil --</option>
                            <option value="admin" style="font-weight:700; color:#1890ff;">[ADMIN] Super Administrator (dp)</option>
                            <optgroup label="Akun Perusahaan Terdaftar">
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= htmlspecialchars($c['cmp_id']) ?>">
                                        [<?= htmlspecialchars($c['cmp_id']) ?>] <?= htmlspecialchars($c['cmp_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="form-group" id="groupLoginUsername" style="<?= ($isAdminOnlyLogin || $isFramasPortal || $subdomainCompany) ? 'display:block;' : '' ?>">
                    <label for="loginUsername">
                        <span><i class="fa-solid <?= $isAdminOnlyLogin ? 'fa-user-shield' : 'fa-user' ?>" style="color:var(--primary); margin-right:6px; font-size:12px;"></i>Nama Pengguna (Username)<span style="color:#ff4d4f; margin-left:3px;">*</span></span>
                    </label>
                    <div class="corporate-input-wrap">
                        <i class="fa-solid <?= $isAdminOnlyLogin ? 'fa-user-shield' : 'fa-user' ?> input-icon"></i>
                        <input type="text" id="loginUsername" name="login_user_<?= date('Ymd') ?>" value="" <?= ($isAdminOnlyLogin || $isFramasPortal || $subdomainCompany) ? 'required' : '' ?> placeholder="<?= $isAdminOnlyLogin ? 'Masukkan username administrator...' : 'Masukkan username pengguna...' ?>" autocomplete="off" readonly onfocus="this.removeAttribute('readonly');">
                    </div>
                </div>
                <div class="form-group">
                    <label for="loginPassword">
                        <span><i class="fa-solid fa-lock" style="color:var(--primary); margin-right:6px; font-size:12px;"></i>Kata Sandi / Password<span style="color:#ff4d4f; margin-left:3px;">*</span></span>
                    </label>
                    <div class="input-password-wrap">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password" id="loginPassword" name="login_pwd_<?= date('Ymd') ?>" value="" required placeholder="Masukkan kata sandi..." autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');">
                        <button type="button" class="btn-toggle-pass" onclick="togglePassVisibility('loginPassword', this)" title="Lihat/Sembunyikan Kata Sandi">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div id="loginMsg"></div>
                <button type="submit" class="btn-login-submit" id="btnLogin">
                    <i class="fa-solid fa-arrow-right-to-bracket" style="font-size:13px;"></i>
                    <span><?= $isAdminOnlyLogin ? 'MASUK SEBAGAI ADMINISTRATOR' : 'MASUK KE SISTEM' ?></span>
                </button>
            </form>
            <script>
                (function() {
                    var u = document.getElementById('loginUsername');
                    var p = document.getElementById('loginPassword');
                    if (u) { u.value = ''; }
                    if (p) { p.value = ''; }
                })();
            </script>
        </div>

        <div class="login-page-footer">
            <span><?= htmlspecialchars(str_replace(' • Single Core System', '', $appSettings['app_footer'])) ?></span>
            <span class="footer-dot">•</span>
            <span><?= htmlspecialchars($appSettings['login_badge']) ?></span>
        </div>
    </div>

    <?php else: ?>
    <!-- 2. APPLICATION LAYOUT (PORTAL CEISA 4.0 OFFICIAL FORMAT) -->
    <div class="app-layout">

        <!-- LEFT SIDEBAR (#kt_aside) -->
        <aside class="kt-aside" id="kt_aside">
            <!-- Brand Header -->
            <?php
            $sidebarBrandFontSize = $appSettings['sidebar_brand_font_size'] ?? '11.5px';
            if (!preg_match('/^[0-9]+(\.[0-9]+)?(px|rem|em|pt)$/', $sidebarBrandFontSize)) {
                $sidebarBrandFontSize = '11.5px';
            }
            $sidebarBrandSubFontSize = $appSettings['sidebar_brand_sub_font_size'] ?? '7.5px';
            if (!preg_match('/^[0-9]+(\.[0-9]+)?(px|rem|em|pt)$/', $sidebarBrandSubFontSize)) {
                $sidebarBrandSubFontSize = '7.5px';
            }
            ?>
            <div class="kt-aside__brand" id="kt_aside_brand">
                <div class="kt-aside__brand-logo">
                    <a href="index.php" style="display:flex; align-items:center; text-decoration:none; gap:7px; width:100%; min-width:0; overflow:hidden;">
                        <img alt="<?= htmlspecialchars($appSettings['app_name']) ?>" src="<?= htmlspecialchars($appSettings['app_logo']) ?>" id="sidebarBrandLogo" style="height:30px; width:30px; max-height:30px; max-width:30px; object-fit:contain; border-radius:5px; flex-shrink:0;" onerror="if(this.src.indexOf('inhost.web.id') === -1 && this.getAttribute('src') && this.getAttribute('src').indexOf('/uploads/') === 0) { this.src = 'https://inhost.web.id' + this.getAttribute('src'); } else { this.src = '/assets/logo.png'; }">
                        <div class="logo-text-wrap" style="display:flex; flex-direction:column; justify-content:center; white-space:nowrap; overflow:hidden; min-width:0; flex:1;">
                            <span style="font-size:<?= htmlspecialchars($sidebarBrandFontSize) ?>; font-weight:800; color:#1890ff; line-height:1.15; letter-spacing:normal; text-overflow:ellipsis; overflow:hidden; white-space:nowrap; display:block; width:100%;" id="sidebarBrandName" title="<?= htmlspecialchars($appSettings['app_name']) ?>"><?= htmlspecialchars($appSettings['app_name']) ?></span>
                            <span style="font-size:<?= htmlspecialchars($sidebarBrandSubFontSize) ?>; font-weight:600; color:#8c8c8c; letter-spacing:normal; line-height:1.25; text-overflow:ellipsis; overflow:hidden; white-space:nowrap; display:block; width:100%;" id="sidebarBrandSub" title="<?= htmlspecialchars($appSettings['app_subtitle']) ?>"><?= htmlspecialchars($appSettings['app_subtitle']) ?></span>
                        </div>
                    </a>
                </div>
                <div class="kt-aside__brand-tools">
                    <button class="kt-aside__brand-aside-toggler" id="kt_aside_toggler" onclick="toggleSidebarPin()" title="Pin / Autohide Sidebar">
                        <svg class="kt-svg-icon" viewBox="0 0 24 24" fill="none">
                            <path d="M12.29 6.71a1 1 0 0 1 0 1.41L8.41 12l3.88 3.88a1 1 0 1 1-1.41 1.41l-4.59-4.59a1 1 0 0 1 0-1.41l4.59-4.58a1 1 0 0 1 1.41 0z" fill="currentColor"/>
                            <path d="M18.29 6.71a1 1 0 0 1 0 1.41L14.41 12l3.88 3.88a1 1 0 1 1-1.41 1.41l-4.59-4.59a1 1 0 0 1 0-1.41l4.59-4.58a1 1 0 0 1 1.41 0z" fill="currentColor" opacity="0.35"/>
                        </svg>
                    </button>
                </div>
            </div>

            <?php
            // Resolusi Visibilitas Kartu Perusahaan di Sidebar
            $canSwitchCompany = !empty($isAdmin) || !empty($_SESSION['can_switch_company']);
            $cfgShowCompanyCard = $appSettings['show_company_card'] ?? 'auto';
            $isCompanyCardVisible = true;
            if ($cfgShowCompanyCard === '0') {
                $isCompanyCardVisible = false;
            } elseif ($cfgShowCompanyCard === '1') {
                $isCompanyCardVisible = true;
            } else {
                // Mode 'auto' (Default): Tampilkan untuk Admin dan User dengan hak switch perusahaan
                $isCompanyCardVisible = $canSwitchCompany;
            }
            $companyCardDisplay = $isCompanyCardVisible ? '' : 'display: none !important;';
            ?>
            <!-- Active Company Card (Admin & Authorized User bisa ganti perusahaan / bisa di-hide via setting) -->
            <div class="sidebar-company-card <?= $canSwitchCompany ? 'is-admin-clickable' : '' ?>" id="sidebarCompanyCard" <?= $canSwitchCompany ? 'onclick="openCompanyModal()"' : '' ?> title="<?= $canSwitchCompany ? 'Klik untuk ganti perusahaan aktif' : 'Perusahaan Aktif' ?>" style="<?= htmlspecialchars(getSidebarCompanyCardStyle($sessionCardColor)) ?> <?= $companyCardDisplay ?>" <?= !empty($sessionCardColor) ? 'data-custom-color="true"' : '' ?>>
                <div class="sidebar-company-icon" id="sidebarCompanyLogoBox">
                    <?php if (!empty($sessionLogo)): ?>
                        <img src="<?= htmlspecialchars($sessionLogo) ?>" alt="Logo" style="width:22px; height:22px; object-fit:contain; border-radius:4px; background:#fff; padding:1px; box-shadow:0 1px 3px rgba(0,0,0,0.15);" onerror="if(this.src.indexOf('inhost.web.id') === -1 && this.getAttribute('src') && this.getAttribute('src').indexOf('/uploads/') === 0) { this.src = 'https://inhost.web.id' + this.getAttribute('src'); } else { this.src = '/assets/logo.png'; }">
                    <?php else: ?>
                        🏢
                    <?php endif; ?>
                </div>
                <div class="sidebar-company-info">
                    <div class="sidebar-company-name" id="sidebarCompanyName" title="<?= htmlspecialchars($sessionCmpName) ?>">
                        <?= htmlspecialchars($sessionCmpName) ?>
                    </div>
                    <div class="sidebar-company-sub" id="sidebarCompanySub">
                        [<?= htmlspecialchars($sessionCmpId) ?>]
                    </div>
                </div>
                <?php if ($canSwitchCompany): ?>
                <div class="sidebar-company-action">
                    <span class="sidebar-company-btn" title="Ganti Perusahaan">
                        <svg viewBox="64 64 896 896" width="10" height="10" fill="currentColor">
                            <path d="M759.2 383c-4.5-5-10.7-7.9-17.2-7.9H164.8c-13.3 0-24 10.7-24 24v48c0 13.3 10.7 24 24 24h476.3L527.4 584.7c-9.4 9.4-9.4 24.6 0 33.9l33.9 33.9c9.4 9.4 24.6 9.4 33.9 0l221.7-221.7c4.6-4.6 7.1-10.8 7.1-17.3s-2.5-12.7-7.1-17.3l-57.7-53.2z"></path>
                        </svg>
                        <span>Ganti</span>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($userRole !== 'bc'): ?>
            <!-- Search Menu Input (.Menu_searchWrapper__1Kj_Z) -->
            <div class="Menu_searchWrapper__1Kj_Z">
                <div class="Menu_searchInput__1HOa_">
                    <span class="Menu_searchIcon__22bzz">
                        <svg viewBox="64 64 896 896" width="13" height="13" fill="currentColor">
                            <path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"></path>
                        </svg>
                    </span>
                    <input class="Menu_searchNativeInput__3mq3t" id="menuSearchInput" type="text" placeholder="Cari menu" onkeyup="filterSidebarMenu()">
                </div>
            </div>
            <?php endif; ?>

            <!-- Sidebar Nav List -->
            <div class="kt-aside-menu" id="kt_aside_menu">
                <?php if ($userRole === 'bc'): ?>
                <!-- Group: BEA CUKAI PORTAL -->
                <div class="menu-group">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">BEA CUKAI PORTAL</h4>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_home') ? 'active' : '' ?>" id="nav-item-it_inventory_home" onclick="switchMainTab('it_inventory_home')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🏠</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M946.5 505L534.6 93.4a31.93 31.93 0 0 0-45.2 0L77.5 505c-12 12-18.8 28.3-18.8 45.3 0 35.3 28.7 64 64 64h43.4V908c0 17.7 14.3 32 32 32H348c17.7 0 32-14.3 32-32V644h264v264c0 17.7 14.3 32 32 32h149.9c17.7 0 32-14.3 32-32V614.3h43.4c17 0 33.3-6.7 45.3-18.8 24.9-25 24.9-65.5-.1-90.5z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Home Dashboard</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                </div>

                <!-- Group: IT INVENTORY REPORT -->
                <div class="menu-group">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">IT INVENTORY REPORT</h4>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1a') ? 'active' : '' ?>" id="nav-item-it_inventory_1a" onclick="switchMainTab('it_inventory_1a')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📥</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M862 736l-44.4-44.4c-3.1-3.1-8.2-3.1-11.3 0L544 953.5V104c0-4.4-3.6-8-8-8h-64c-4.4 0-8 3.6-8 8v849.5L201.7 691.6c-3.1-3.1-8.2-3.1-11.3 0L146 736c-3.1 3.1-3.1 8.2 0 11.3l360.3 360.3c3.1 3.1 8.2 3.1 11.3 0L862 747.3c3.1-3.1 3.1-8.2 0-11.3z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">1A - Pemasukan Barang</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1b') ? 'active' : '' ?>" id="nav-item-it_inventory_1b" onclick="switchMainTab('it_inventory_1b')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📤</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M862 288L501.7 72.3c-3.1-3.1-8.2-3.1-11.3 0L130.1 288c-3.1 3.1-3.1 8.2 0 11.3l44.4 44.4c3.1 3.1 8.2 3.1 11.3 0L464 74.5V920c0 4.4 3.6 8 8 8h64c4.4 0 8-3.6 8-8V74.5l278.2 269.2c3.1 3.1 8.2 3.1 11.3 0l44.4-44.4c3.1-3.1 3.1-8.2 0-11.3z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">1B - Pengeluaran Barang</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1c') ? 'active' : '' ?>" id="nav-item-it_inventory_1c" onclick="switchMainTab('it_inventory_1c')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">⏳</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M742 224h-44v-80c0-4.4-3.6-8-8-8H334c-4.4 0-8 3.6-8 8v80h-44c-17.7 0-32 14.3-32 32v120c0 102.2 68.2 189.7 162.7 218.4C318.2 622.7 250 710.2 250 812.4V932c0 17.7 14.3 32 32 32h444c17.7 0 32-14.3 32-32V812.4c0-102.2-68.2-189.7-162.7-218.4C673.8 565.3 742 477.8 742 376V256c0-17.7-14.3-32-32-32zm-76 152c0 86-70 156-156 156s-156-70-156-156V280h312v96zm0 436.4c0 86-70 156-156 156s-156-70-156-156V716c0-86 70-156 156-156s156 70 156 156v96.4z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">1C - Posisi WIP</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1d' || $initialTab === 'it_inventory_1d_rm') ? 'active' : '' ?>" id="nav-item-it_inventory_1d_rm" onclick="switchMainTab('it_inventory_1d_rm')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🧱</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M885.2 446.3l-57.8-313.2C823 108.4 801.4 90 776 90H248c-25.4 0-47 18.4-51.4 43.1L138.8 446.3c-2.4 13.1 1.7 26.5 11 35.8 9.4 9.4 22.2 14.7 35.6 14.7h136.2c13.3 0 24 10.7 24 24v24c0 39.8 32.2 72 72 72h188.8c39.8 0 72-32.2 72-72v-24c0-13.3 10.7-24 24-24h136.2c13.4 0 26.2-5.3 35.6-14.7 9.3-9.3 13.4-22.7 11-35.8zM245.5 162h533l44.3 240H695.4c-22.1 0-41.6 12.3-51.7 31.4-12.7 24.1-37.7 39.4-65.7 39.4H446c-28 0-53-15.3-65.7-39.4-10.1-19.1-29.6-31.4-51.7-31.4H199.2l46.3-240z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">1D - Mutasi Bahan Baku</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1d_fg') ? 'active' : '' ?>" id="nav-item-it_inventory_1d_fg" onclick="switchMainTab('it_inventory_1d_fg')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📦</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M483.2 790.3L861.4 412c1.7-1.7 2.5-4 2.3-6.4l-14.4-142c-.4-4.1-3.7-7.4-7.8-7.8l-142-14.4c-2.4-.2-4.7.6-6.4 2.3L314.9 621.9c-3.1 3.1-3.1 8.2 0 11.3l157 157.1c3.1 3.1 8.2 3.1 11.3 0zm207-483.5a32 32 0 1 0 64 0 32 32 0 0 0-64 0zM375.4 797.7L184.2 606.5c-3.1-3.1-3.1-8.2 0-11.3L482.4 297c1.7-1.7 4-2.5 6.4-2.3l108.6 11-44.5 44.5-84.3-8.6c-4.1-.4-7.4 2.9-7.8 7l-8.6 84.3-242 242 145.4 145.4 20-20.6c3.1-3.1 8.2-3.1 11.3 0l21.2 21.2c3.1 3.1 3.1 8.2 0 11.3l-32.9 33.9c-3.1 3.1-8.2 3.1-11.3 0z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">1D - Mutasi Barang Jadi</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1d_cg') ? 'active' : '' ?>" id="nav-item-it_inventory_1d_cg" onclick="switchMainTab('it_inventory_1d_cg')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">⚙️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 500H688V208c0-17.7-14.3-32-32-32H368c-17.7 0-32 14.3-32 32v292H144c-17.7 0-32 14.3-32 32v280c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V532c0-17.7-14.3-32-32-32zM408 248h208v252H408V248zm400 528H184V572h624v204z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">1D - Mutasi Mesin & Alat</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1d_w') ? 'active' : '' ?>" id="nav-item-it_inventory_1d_w" onclick="switchMainTab('it_inventory_1d_w')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🗑️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M720 184h-80v-40c0-4.4-3.6-8-8-8H392c-4.4 0-8 3.6-8 8v40h-80c-17.7 0-32 14.3-32 32v48c0 4.4 3.6 8 8 8h568c4.4 0 8-3.6 8-8v-48c0-17.7-14.3-32-32-32zM280 848c0 17.7 14.3 32 32 32h392c17.7 0 32-14.3 32-32V320H280v528z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">1D - Mutasi Reject & Scrap</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'it_inventory_log') ? 'active' : '' ?>" id="nav-item-it_inventory_log" onclick="switchMainTab('it_inventory_log')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📜</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M854.6 288.6L657.4 91.4c-9-9-21.2-14.1-33.9-14.1H224c-26.5 0-48 21.5-48 48v768c0 26.5 21.5 48 48 48h576c26.5 0 48-21.5 48-48V322.5c0-12.7-5.1-24.9-14.1-33.9zM648 160.8l119.2 119.2H648V160.8zm136 695.2H240V144h344v168c0 17.7 14.3 32 32 32h168v512zm-464-168h352c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H320c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8zm0-136h352c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H320c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Log Perubahan Data</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                </div>

                <!-- Group: PENGATURAN -->
                <div class="menu-group">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">PENGATURAN</h4>
                    </div>
                    <div class="kt-menu__item" onclick="openModalPass()">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🔑</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M632 144c-145.8 0-264 118.2-264 264 0 54.1 16.3 104.4 44.3 146.4l-275.6 275.5a8.03 8.03 0 0 0-2.3 5.7V880c0 4.4 3.6 8 8 8h128c4.4 0 8-3.6 8-8v-64h64c4.4 0 8-3.6 8-8v-64h64c4.4 0 8-3.6 8-8v-64h64c2.1 0 4.2-.8 5.7-2.3l49.9-49.9C579.6 657.7 605.9 672 632 672c145.8 0 264-118.2 264-264S777.8 144 632 144zm0 456c-106 0-192-86-192-192s86-192 192-192 192 86 192 192-86 192-192 192zm56-256a48 48 0 1 0 96 0 48 48 0 1 0-96 0z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Ganti Password</span>
                        </a>
                    </div>
                    <div class="kt-menu__item">
                        <a href="index.php?action=logout" class="kt-menu__link" style="color:#fd3995;">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🚪</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M868 732h-70.3c-4.8 0-9.3 2.1-12.3 5.8-7 8.5-14.5 16.7-22.4 24.5a353.84 353.84 0 0 1-112.7 75.9A352.8 352.8 0 0 1 512.4 866c-47.9 0-94.3-9.4-137.9-27.8a353.84 353.84 0 0 1-112.7-75.9 353.28 353.28 0 0 1-76-112.5C167.3 606.2 158 559.9 158 512s9.4-94.2 27.8-137.8c17.8-42.1 43.4-80 76-112.5s70.5-58.2 112.7-75.9C418.1 167.4 464.5 158 512.4 158c47.9 0 94.3 9.4 137.9 27.8A353.84 353.84 0 0 1 763 261.7c7.9 7.8 15.4 16 22.4 24.5 3 3.7 7.6 5.8 12.3 5.8H868c6.3 0 10.2-7 6.7-12.3C798 160.8 663.8 80 512 80 273.4 80 80 273.4 80 512s193.4 432 432 432c151.8 0 286-80.8 362.7-199.7 3.5-5.3-.4-12.3-6.7-12.3zm82.4-230.7L826.8 377.7a8 8 0 0 0-11.3 0l-33.9 33.9a8 8 0 0 0 0 11.3l60.5 60.5H412c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8h440.1l-60.5 60.5a8 8 0 0 0 0 11.3l33.9 33.9a8 8 0 0 0 11.3 0l123.6-123.6a8 8 0 0 0 0-11.3z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Log Out</span>
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <!-- Group 1: DOKUMEN PABEAN -->
                <div class="menu-group" id="menu-group-dokumen-pabean" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">DOKUMEN PABEAN</h4>
                    </div>
                    <?php if (isMenuAllowed('daftar_dokumen')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'daftar_dokumen') ? 'active' : '' ?>" id="nav-item-daftar_dokumen" onclick="switchMainTab('daftar_dokumen')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🖥️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M928 140H96c-17.7 0-32 14.3-32 32v496c0 17.7 14.3 32 32 32h380v112H304c-8.8 0-16 7.2-16 16v48c0 4.4 3.6 8 8 8h432c4.4 0 8-3.6 8-8v-48c0-8.8-7.2-16-16-16H548V700h380c17.7 0 32-14.3 32-32V172c0-17.7-14.3-32-32-32zm-40 488H136V212h752v416z"></path></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Dashboard Ceisa 4.0</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('transfer')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'transfer') ? 'active' : '' ?>" id="nav-item-transfer" onclick="switchMainTab('transfer')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">⊞</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 112H144c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V144c0-17.7-14.3-32-32-32zm-40 376H520V184h320v304zm-360-304v304H184V184h320zm-320 376h320v304H184V488zm360 304V488h320v304H544z"></path></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Data Host to Host</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('editor')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'editor') ? 'active' : '' ?>" id="nav-item-editor" onclick="switchMainTab('editor')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">✏️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M257.7 752c2 0 4-.2 6-.5L431.9 722c2-.4 3.9-1.3 5.3-2.8l423.9-423.9a9.96 9.96 0 0 0 0-14.1L694.9 114.9c-1.9-1.9-4.4-2.9-7.1-2.9s-5.2 1-7.1 2.9L256.8 538.8c-1.5 1.5-2.4 3.3-2.8 5.3l-29.5 168.2a33.5 33.5 0 0 0 9.4 29.8c6.6 6.4 14.9 9.9 23.8 9.9zm67.4-148.6L687.8 240.7l65.5 65.5L390.6 669l-75.1 13.1 9.6-78.7zM880 836H144c-4.4 0-8 3.6-8 8v60c0 4.4 3.6 8 8 8h736c4.4 0 8-3.6 8-8v-60c0-4.4-3.6-8-8-8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Form Editor Dokumen</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('import')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'import') ? 'active' : '' ?>" id="nav-item-import" onclick="switchMainTab('import')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📊</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M854.6 288.6L657.4 91.4c-9-9-21.2-14.1-33.9-14.1H224c-26.5 0-48 21.5-48 48v768c0 26.5 21.5 48 48 48h576c26.5 0 48-21.5 48-48V322.5c0-12.7-5.1-24.9-14.1-33.9zM648 160.8l119.2 119.2H648V160.8zm136 695.2H240V144h344v168c0 17.7 14.3 32 32 32h168v512zM362.4 448l67.2 108.9L497 448h65.8L477 588.6l90.2 147.4h-67.4L428.8 625l-71 111H292l90.2-147.4L296.6 448h65.8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Import Excel Dokumen</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('load_erp_odoo')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'load_erp_odoo') ? 'active' : '' ?>" id="nav-item-load_erp_odoo" onclick="switchMainTab('load_erp_odoo')" style="display: <?= ($hasStandardCustomsDocs && $isOdooErpCompany) ? '' : 'none' ?>;">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🔌</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M917.7 508.4L793.5 384.2a8.03 8.03 0 0 0-11.3 0L670.8 495.6c-4.4 4.4-1.2 12 5 12.7 28.5 3.3 53.6 17.5 70.9 38.6 15.7 19.2 24.8 43.1 25.9 68.6.6 13.9 6.2 26.9 15.8 36.5l119.3 119.3c3.1 3.1 8.2 3.1 11.3 0l70.1-70.1c3.1-3.2 3.1-8.2 0-11.3l-59.4-59.4 69.4-69.4c3.2-3.1 3.2-8.2 0-11.4l-.2-.3zM348 513.3c6.2-.7 9.4-8.3 5-12.7L241.6 389.2a8.03 8.03 0 0 0-11.3 0l-70.1 70.1c-3.1 3.1-3.1 8.2 0 11.3l69.4 69.4-59.4 59.4c-3.1 3.1-3.1 8.2 0 11.3l119.3 119.3c9.6 9.6 22.6 15.2 36.5 15.8 25.5 1.1 49.4-8 68.6-23.7 21.1-17.3 35.3-42.4 38.6-70.9.7-6.2-6.9-9.4-11.3-5L348 513.3zM678.4 207.6c-3.1-3.1-8.2-3.1-11.3 0L547.8 326.9c-9.6 9.6-15.2 22.6-15.8 36.5-1.1 25.5 8 49.4 23.7 68.6 17.3 21.1 42.4 35.3 70.9 38.6 6.2.7 9.4-6.9 5-11.3L520.2 347.9a8.03 8.03 0 0 1 0-11.3L639.5 217.3a8.03 8.03 0 0 1 11.3 0l27.6 27.6c3.1 3.1 8.2 3.1 11.3 0l58.8-58.8c3.1-3.1 3.1-8.2 0-11.3l-70.1-27.2zM476.2 686.1c-17.3-21.1-42.4-35.3-70.9-38.6-6.2-.7-9.4 6.9-5 11.3l111.4 111.4c3.1 3.1 3.1 8.2 0 11.3L392.4 900.5a8.03 8.03 0 0 1-11.3 0l-27.6-27.6c-3.1-3.1-8.2-3.1-11.3 0l-58.8 58.8c-3.1 3.1-3.1 8.2 0 11.3l70.1 70.1c3.1 3.1 8.2 3.1 11.3 0l119.3-119.3c9.6-9.6 15.2-22.6 15.8-36.5 1.1-25.5-8-49.4-23.7-68.6z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Load ERP (Odoo)</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('dokap')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'dokap') ? 'active' : '' ?>" id="nav-item-dokap" onclick="switchMainTab('dokap')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📎</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M779.3 196.6c-61.6-61.6-161.4-61.6-223 0l-374 374c-42.8 42.8-42.8 112.2 0 155 42.8 42.8 112.2 42.8 155 0l359.8-359.8c23.9-23.9 23.9-62.6 0-86.5s-62.6-23.9-86.5 0L275.5 614.4c-3.1 3.1-3.1 8.2 0 11.3l42.4 42.4c3.1 3.1 8.2 3.1 11.3 0l335.1-335.1c4.7-4.7 12.3-4.7 17 0 4.7 4.7 4.7 12.3 0 17L321.5 709.8c-24 24-62.9 24-86.9 0s-24-62.9 0-86.9l374-374c42.8-42.8 112.2-42.8 155 0 42.8 42.8 42.8 112.2 0 155l-330 330c-3.1 3.1-3.1 8.2 0 11.3l42.4 42.4c3.1 3.1 8.2 3.1 11.3 0l330-330c61.6-61.6 61.6-161.4 0-223z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Upload Dokap Pabean</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php 
                    $showMenuTracking = ($appSettings['show_menu_tracking'] ?? '0') === '1';
                    if ($showMenuTracking && isMenuAllowed('tracking')): 
                    ?>
                    <div class="kt-menu__item <?= ($initialTab === 'tracking') ? 'active' : '' ?>" id="nav-item-tracking" onclick="switchMainTab('tracking')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🔍</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M909.6 854.5L649.9 594.8C690.2 542.7 712 479 712 412c0-80.2-31.3-155.4-87.9-212.1-56.6-56.7-132-87.9-212.1-87.9s-155.5 31.3-212.1 87.9C143.2 256.5 112 331.8 112 412c0 80.1 31.3 155.5 87.9 212.1C256.5 680.8 331.8 712 412 712c67 0 130.6-21.8 182.7-62l259.7 259.6a8.2 8.2 0 0 0 11.6 0l43.6-43.5a8.2 8.2 0 0 0 0-11.6zM570.4 570.4C528 612.7 471.8 636 412 636s-116-23.3-158.4-65.6C211.3 528 188 471.8 188 412s23.3-116.1 65.6-158.4C296 211.3 352.2 188 412 188s116.1 23.2 158.4 65.6S636 352.2 636 412s-23.3 116.1-65.6 158.4z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Tracking Respon Pabean</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Group: WINLINE ERP -->
                <div class="menu-group" id="menu-group-winline-erp" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">WINLINE ERP</h4>
                    </div>
                    <?php if (isMenuAllowed('winline_sales_invoice')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'winline_sales_invoice') ? 'active' : '' ?>" id="nav-item-winline_sales_invoice" onclick="switchMainTab('winline_sales_invoice')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link" title="Sales Invoice (Integrasi Dokumen H2H BC 2.7, BC 3.0 ke Customer)">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📑</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M854.6 288.6L657.4 91.4c-9-9-21.2-14.1-33.9-14.1H224c-26.5 0-48 21.5-48 48v768c0 26.5 21.5 48 48 48h576c26.5 0 48-21.5 48-48V322.5c0-12.7-5.1-24.9-14.1-33.9zM648 160.8l119.2 119.2H648V160.8zm136 695.2H240V144h344v168c0 17.7 14.3 32 32 32h168v512zM312 400h400c4.4 0 8 3.6 8 8v16c0 4.4-3.6 8-8 8H312c-4.4 0-8-3.6-8-8v-16c0-4.4 3.6-8 8-8zm0 144h400c4.4 0 8 3.6 8 8v16c0 4.4-3.6 8-8 8H312c-4.4 0-8-3.6-8-8v-16c0-4.4 3.6-8 8-8zm0 144h240c4.4 0 8 3.6 8 8v16c0 4.4-3.6 8-8 8H312c-4.4 0-8-3.6-8-8v-16c0-4.4 3.6-8 8-8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Sales Invoice</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('winline_dn_transfer')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'winline_dn_transfer') ? 'active' : '' ?>" id="nav-item-winline_dn_transfer" onclick="switchMainTab('winline_dn_transfer')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link" title="DN Transfer (Integrasi Dokumen H2H BC 2.7 Antar Framas)">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🚚</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M892 562l-77.4-154.8A64.08 64.08 0 0 0 757.4 374H640V240c0-17.7-14.3-32-32-32H112c-17.7 0-32 14.3-32 32v464c0 17.7 14.3 32 32 32h52.8c12.2 47.7 55.6 82 107.2 82s95-34.3 107.2-82h229.6c12.2 47.7 55.6 82 107.2 82s95-34.3 107.2-82H896c17.7 0 32-14.3 32-32v-92.4c0-23.7-9.4-46.4-26-63.6zM760 446l54 108H640V446h120zM272 782c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48zm444 0c-26.5 0-48-21.5-48-48s21.5-48 48-48 48 21.5 48 48-21.5 48-48 48z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">DN Transfer</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('winline_purchase_order')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'winline_purchase_order') ? 'active' : '' ?>" id="nav-item-winline_purchase_order" onclick="switchMainTab('winline_purchase_order')" style="display: <?= $hasStandardCustomsDocs ? '' : 'none' ?>;">
                        <a class="kt-menu__link" title="Purchase Order (Integrasi Dokumen H2H BC 2.3, BC 4.0, BC 1.6)">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🛒</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M832 312H696v-16c0-101.6-82.4-184-184-184s-184 82.4-184 184v16H192c-17.7 0-32 14.3-32 32v536c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V344c0-17.7-14.3-32-32-32zm-436-16c0-68.5 55.5-124 124-124s124 55.5 124 124v16H396v-16zm376 568H232V384h540v480z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Purchase Order</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Group 1B: MANIFES ONLINE (BC 1.1) -->
                <div class="menu-group" id="menu-group-manifes" style="display: <?= $hasRoleManifest ? '' : 'none' ?>;">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">MANIFES ONLINE (BC 1.1)</h4>
                    </div>
                    <?php if (isMenuAllowed('manifest')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'manifest_ceisa') ? 'active' : '' ?>" id="nav-item-manifest_ceisa" onclick="switchMainTab('manifest_ceisa')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🚢</span>
                                <span class="icon-monochrome"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M20.2 15.6l-1.4 5.1c-.2.7-.8 1.3-1.6 1.3H6.8c-.8 0-1.4-.6-1.6-1.3L3.8 15.6c-.2-.8.4-1.6 1.2-1.6H19c.8 0 1.4.8 1.2 1.6zM4.5 13h15l-.8-3.2c-.1-.5-.6-.8-1.1-.8H6.4c-.5 0-1 .3-1.1.8L4.5 13zM11 2h2v4h-2V2zm-4 4h10v2H7V6z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Manifes Ceisa 4.0</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'manifest') ? 'active' : '' ?>" id="nav-item-manifest" onclick="switchMainTab('manifest')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📦</span>
                                <span class="icon-monochrome"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M2 6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6zm3 1v10h2V7H5zm4 0v10h2V7H9zm4 0v10h2V7h-2zm4 0v10h2V7h-2z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Manifes Host to Host</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'bc11_monitoring') ? 'active' : '' ?>" id="nav-item-bc11_monitoring" onclick="switchMainTab('bc11_monitoring')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📋</span>
                                <span class="icon-monochrome"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 2.06A8 8 0 0 1 19.94 11H13V4.06zM4.06 13A8 8 0 0 1 11 4.06V11H4.06zm0 2H11v6.94A8 8 0 0 1 4.06 15zm8.94 6.94V13h6.94A8 8 0 0 1 13 21.94z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">BC 1.1 Monitoring</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Group: Modul Inventory (HANYA TPB & PPKEK) -->
                <?php if (isMenuAllowed('inventory_modul') || isMenuAllowed('inv_material') || isMenuAllowed('inv_bom') || isMenuAllowed('inv_stock_transfer') || isMenuAllowed('inv_warehouse_in') || isMenuAllowed('inv_scan27') || isMenuAllowed('it_inventory') || isMenuAllowed('inv_unposting_appv') || isMenuAllowed('inv_stok_awal') || isMenuAllowed('inv_adjust_spp')): ?>
                <div class="menu-group" id="menu-group-inventory-modul" style="display: <?= $isInventoryModuleAllowed ? '' : 'none' ?>;">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">MODUL INVENTORY</h4>
                    </div>

                    <!-- Sub Modul: Data Master (Expandable) -->
                    <?php 
                    $isMasterAllowed = isMenuAllowed('inv_material') || isMenuAllowed('inv_koreksi_klas') || isMenuAllowed('inv_bom') || isMenuAllowed('inv_jaminan');
                    $isMasterActive = in_array($initialTab, ['inv_material', 'inv_koreksi_klas', 'inv_bom', 'inv_jaminan']);
                    ?>
                    <?php if ($isMasterAllowed): ?>
                    <div class="kt-menu__item kt-menu__item--submenu <?= $isMasterActive ? 'kt-menu__item--open' : '' ?>" id="nav-item-master_parent">
                        <a class="kt-menu__link" onclick="toggleSubmenu('nav-item-master_parent')" title="Klik untuk membuka/menutup Data Master">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📁</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 298.4H521L403.7 186.2a8.15 8.15 0 0 0-5.5-2.2H144c-17.7 0-32 14.3-32 32v592c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V330.4c0-17.7-14.3-32-32-32zM840 768H184V256h188.5l119.6 114.1 4.7 4.5 6.4.7L840 376.7V768z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text" style="font-weight: 600;">Data Master</span>
                            <span class="kt-menu__arrow">▶</span>
                        </a>
                        <div class="kt-menu__sub" id="master_submenu">
                            <?php if (isMenuAllowed('inv_material')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_material') ? 'active' : '' ?>" id="nav-item-inv_material" onclick="switchMainTab('inv_material')">
                                <a class="kt-menu__link" style="display:flex; justify-content:space-between; align-items:center;">
                                    <span style="display:inline-flex; align-items:center;">
                                        <span class="kt-menu__link-icon">
                                            <span class="icon-colored">🏷️</span>
                                            <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M483.2 790.3L861.4 412c1.7-1.7 2.5-4 2.3-6.4l-14.4-142c-.4-4.1-3.7-7.4-7.8-7.8l-142-14.4c-2.4-.2-4.7.6-6.4 2.3L314.9 621.9c-3.1 3.1-3.1 8.2 0 11.3l157 157.1c3.1 3.1 8.2 3.1 11.3 0zm207-483.5a32 32 0 1 0 64 0 32 32 0 0 0-64 0zM375.4 797.7L184.2 606.5c-3.1-3.1-3.1-8.2 0-11.3L482.4 297c1.7-1.7 4-2.5 6.4-2.3l108.6 11-44.5 44.5-84.3-8.6c-4.1-.4-7.4 2.9-7.8 7l-8.6 84.3-242 242 145.4 145.4 20-20.6c3.1-3.1 8.2-3.1 11.3 0l21.2 21.2c3.1 3.1 3.1 8.2 0 11.3l-32.9 33.9c-3.1 3.1-8.2 3.1-11.3 0z"/></svg></span>
                                        </span>
                                        <span class="kt-menu__link-text">Master Data Barang</span>
                                    </span>
                                    <?php if ($cntPendingApproval > 0): ?>
                                        <span class="badge badge-warning" style="font-size:9px; padding:1px 5px; border-radius:10px; background:#faad14; color:#fff; font-weight:700;" title="<?= $cntPendingApproval ?> pengajuan kode menunggu approval"><?= $cntPendingApproval ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (isMenuAllowed('inv_koreksi_klas') || isMenuAllowed('inv_material')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_koreksi_klas') ? 'active' : '' ?>" id="nav-item-inv_koreksi_klas" onclick="switchMainTab('inv_koreksi_klas')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">⚠️</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M464 720a48 48 0 1 0 96 0 48 48 0 1 0-96 0zm16-480c-17.7 0-32 14.3-32 32v240c0 17.7 14.3 32 32 32s32-14.3 32-32V272c0-17.7-14.3-32-32-32zm472 496L566 160c-24-41.6-84-41.6-108 0L72 736c-24 41.6 6 96 54 96h768c48 0 78-54.4 54-96zM814 768H210L512 244l302 524z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Koreksi Klasifikasi IT Inv<?= $isAdmin ? ' (KLAS)' : '' ?></span>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (isMenuAllowed('inv_bom')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_bom') ? 'active' : '' ?>" id="nav-item-inv_bom" onclick="switchMainTab('inv_bom')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">🧩</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M888 792H200V168c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v688c0 4.4 3.6 8 8 8h752c4.4 0 8-3.6 8-8v-56c0-4.4-3.6-8-8-8zm-620-80h104c4.4 0 8-3.6 8-8V464c0-4.4-3.6-8-8-8H268c-4.4 0-8 3.6-8 8v240c0 4.4 3.6 8 8 8zm184 0h104c4.4 0 8-3.6 8-8V280c0-4.4-3.6-8-8-8H452c-4.4 0-8 3.6-8 8v424c0 4.4 3.6 8 8 8zm184 0h104c4.4 0 8-3.6 8-8V360c0-4.4-3.6-8-8-8H636c-4.4 0-8 3.6-8 8v344c0 4.4 3.6 8 8 8z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Master BOM</span>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (isMenuAllowed('inv_jaminan')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_jaminan') ? 'active' : '' ?>" id="nav-item-inv_jaminan" onclick="switchMainTab('inv_jaminan')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">🛡️</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm0 820c-205.4 0-372-166.6-372-372s166.6-372 372-372 372 166.6 372 372-166.6 372-372 372zm-48-268l240-240c6.2-6.2 6.2-16.4 0-22.6l-45.3-45.3c-6.2-6.2-16.4-6.2-22.6 0L452.7 542.8l-85.4-85.4c-6.2-6.2-16.4-6.2-22.6 0l-45.3 45.3c-6.2 6.2-6.2 16.4 0 22.6l141.4 141.4c6.2 6.3 16.4 6.3 22.6 0z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Master Jaminan</span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('inv_stock_transfer')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'inv_stock_transfer') ? 'active' : '' ?>" id="nav-item-inv_stock_transfer" onclick="switchMainTab('inv_stock_transfer')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🏭</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 500H688V208c0-17.7-14.3-32-32-32H368c-17.7 0-32 14.3-32 32v292H144c-17.7 0-32 14.3-32 32v280c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V532c0-17.7-14.3-32-32-32zM408 248h208v252H408V248zm400 528H184V572h624v204z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Mutasi Produksi</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Sub Modul: Transaksi Warehouse (Expandable) -->
                    <?php 
                    $isWarehouseAllowed = isMenuAllowed('inv_warehouse_in') || isMenuAllowed('inv_warehouse_delivery') || isMenuAllowed('inv_transfer_bc27');
                    $isWarehouseActive = in_array($initialTab, ['inv_warehouse_in', 'inv_warehouse_delivery', 'inv_transfer_bc27']);
                    ?>
                    <?php if ($isWarehouseAllowed): ?>
                    <div class="kt-menu__item kt-menu__item--submenu <?= $isWarehouseActive ? 'kt-menu__item--open' : '' ?>" id="nav-item-warehouse_parent">
                        <a class="kt-menu__link" onclick="toggleSubmenu('nav-item-warehouse_parent')" title="Klik untuk membuka/menutup Transaksi Warehouse">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🏬</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 312H144c-17.7 0-32 14.3-32 32v496c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V344c0-17.7-14.3-32-32-32zm-40 488H184V384h656v416zM512 120a176 176 0 0 0-176 176c0 4.4 3.6 8 8 8h336c4.4 0 8-3.6 8-8a176 176 0 0 0-176-176z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text" style="font-weight: 600;">Transaksi Warehouse</span>
                            <span class="kt-menu__arrow">▶</span>
                        </a>
                        <div class="kt-menu__sub" id="warehouse_submenu">
                            <?php if (isMenuAllowed('inv_warehouse_in')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_warehouse_in') ? 'active' : '' ?>" id="nav-item-inv_warehouse_in" onclick="switchMainTab('inv_warehouse_in')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">📥</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M885.2 446.3l-57.8-313.2C823 108.4 801.4 90 776 90H248c-25.4 0-47 18.4-51.4 43.1L138.8 446.3c-2.4 13.1 1.7 26.5 11 35.8 9.4 9.4 22.2 14.7 35.6 14.7h136.2c13.3 0 24 10.7 24 24v24c0 39.8 32.2 72 72 72h188.8c39.8 0 72-32.2 72-72v-24c0-13.3 10.7-24 24-24h136.2c13.4 0 26.2-5.3 35.6-14.7 9.3-9.3 13.4-22.7 11-35.8zM245.5 162h533l44.3 240H695.4c-22.1 0-41.6 12.3-51.7 31.4-12.7 24.1-37.7 39.4-65.7 39.4H446c-28 0-53-15.3-65.7-39.4-10.1-19.1-29.6-31.4-51.7-31.4H199.2l46.3-240z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Warehouse - Incoming</span>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (isMenuAllowed('inv_warehouse_delivery')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_warehouse_delivery') ? 'active' : '' ?>" id="nav-item-inv_warehouse_delivery" onclick="switchMainTab('inv_warehouse_delivery')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">🚚</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 600h-48V376c0-4.4-3.6-8-8-8h-96c-4.4 0-8 3.6-8 8v224H504V280c0-4.4-3.6-8-8-8H152c-4.4 0-8 3.6-8 8v320h-48c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8h48.8c7.4 40.6 42.9 72 85.2 72s77.8-31.4 85.2-72h309.6c7.4 40.6 42.9 72 85.2 72s77.8-31.4 85.2-72H880c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8zM236 704a40 40 0 1 1 0-80 40 40 0 0 1 0 80zm528 0a40 40 0 1 1 0-80 40 40 0 0 1 0 80zm4-168h-48V440h48v96z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Warehouse - Delivery</span>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (isMenuAllowed('inv_transfer_bc27')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_transfer_bc27') ? 'active' : '' ?>" id="nav-item-inv_transfer_bc27" onclick="switchMainTab('inv_transfer_bc27')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">🔄</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M847.9 592H152c-4.4 0-8 3.6-8 8v60c0 4.4 3.6 8 8 8h605.2L612 813.1c-3.1 3.1-3.1 8.2 0 11.3l42.4 42.4c3.1 3.1 8.2 3.1 11.3 0l229.4-229.4c3.1-3.1 3.1-8.2 0-11.3L665.7 396.7c-3.1-3.1-8.2-3.1-11.3 0l-42.4 42.4c-3.1 3.1-3.1 8.2 0 11.3L757.2 592zM176.1 432H872c4.4 0 8-3.6 8-8v-60c0-4.4-3.6-8-8-8H266.8l145.2-145.1c3.1-3.1 3.1-8.2 0-11.3l-42.4-42.4c-3.1-3.1-8.2-3.1-11.3 0L128.9 384.6c-3.1 3.1-3.1 8.2 0 11.3L358.3 625.3c3.1 3.1 8.2 3.1 11.3 0l42.4-42.4c3.1-3.1 3.1-8.2 0-11.3L266.8 432z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Warehouse - Transfer BC27 OUT-->IN</span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (isMenuAllowed('inv_stok_opname')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'inv_stok_opname') ? 'active' : '' ?>" id="nav-item-inv_stok_opname" onclick="switchMainTab('inv_stok_opname')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📋</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M688 264c0-4.4-3.6-8-8-8H344c-4.4 0-8 3.6-8 8v56c0 4.4 3.6 8 8 8h336c4.4 0 8-3.6 8-8v-56zm-176 408c-4.4 0-8 3.6-8 8v56c0 4.4 3.6 8 8 8h176c4.4 0 8-3.6 8-8v-56c0-4.4-3.6-8-8-8H512zm176-208c0-4.4-3.6-8-8-8H344c-4.4 0-8 3.6-8 8v56c0 4.4 3.6 8 8 8h336c4.4 0 8-3.6 8-8v-56zM880 112H144c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V144c0-17.7-14.3-32-32-32zm-40 728H184V184h656v656z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Stok Opname</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Divider 3 -->
                    <div style="height: 1px; background: var(--border-color); margin: 6px 14px; opacity: 0.6;"></div>

                    <?php if (isMenuAllowed('inv_scan27')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'inv_scan27') ? 'active' : '' ?>" id="nav-item-inv_scan27" onclick="switchMainTab('inv_scan27')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📲</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M144 288h72c4.4 0 8-3.6 8-8V176c0-4.4-3.6-8-8-8h-72c-4.4 0-8 3.6-8 8v104c0 4.4 3.6 8 8 8zm680-120h-72c-4.4 0-8 3.6-8 8v104c0 4.4 3.6 8 8 8h72c4.4 0 8-3.6 8-8V176c0-4.4-3.6-8-8-8zM224 848c4.4 0 8-3.6 8-8v-72c0-4.4-3.6-8-8-8h-72c-4.4 0-8 3.6-8 8v72c0 4.4 3.6 8 8 8h72zm600-88c0-4.4-3.6-8-8-8h-72c-4.4 0-8 3.6-8 8v72c0 4.4 3.6 8 8 8h72c4.4 0 8-3.6 8-8v-72zm56-616H144c-44.2 0-80 35.8-80 80v640c0 44.2 35.8 80 80 80h736c44.2 0 80-35.8 80-80V224c0-44.2-35.8-80-80-80zm8 720c0 4.4-3.6 8-8 8H144c-4.4 0-8-3.6-8-8V224c0-4.4 3.6-8 8-8h736c4.4 0 8 3.6 8 8v640z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Import BC 2.7 IN dan Post ke IT Inv</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Sub Modul: Admin Inventory (Expandable) -->
                    <div style="height: 1px; background: var(--border-color); margin: 6px 14px; opacity: 0.6;"></div>
                    <?php 
                    $isAdminInvAllowed = isMenuAllowed('inv_unposting_appv') || isMenuAllowed('inv_stok_awal') || isMenuAllowed('inv_adjust_spp');
                    $isAdminInvActive = in_array($initialTab, ['inv_unposting_appv', 'inv_stok_awal', 'inv_adjust_spp']);
                    ?>
                    <?php if ($isAdminInvAllowed): ?>
                    <div class="kt-menu__item kt-menu__item--submenu <?= $isAdminInvActive ? 'kt-menu__item--open' : '' ?>" id="nav-item-admin_inv_parent">
                        <a class="kt-menu__link" onclick="toggleSubmenu('nav-item-admin_inv_parent')" title="Klik untuk membuka/menutup Admin Inventory">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🛠️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M924.8 625.7l-65.5-56c3.1-19 4.7-38.4 4.7-57.7s-1.6-38.7-4.7-57.7l65.5-56a32.03 32.03 0 0 0 7.7-35.2l-78-188.2a32.05 32.05 0 0 0-26-19.7l-85.7-10.7c-28.3-24.8-60.6-43.7-96-56.1l-18.7-84.3A32.05 32.05 0 0 0 595 16h-174a32.05 32.05 0 0 0-31.3 25.1l-18.7 84.3c-35.4 12.4-67.7 31.3-96 56.1l-85.7 10.7a32.05 32.05 0 0 0-26 19.7l-78 188.2a32.03 32.03 0 0 0 7.7 35.2l65.5 56c-3.1 19-4.7 38.4-4.7 57.7s1.6 38.7 4.7 57.7l-65.5 56a32.03 32.03 0 0 0-7.7 35.2l78 188.2c6.4 15.5 20.4 25.6 36.3 25.6.8 0 1.6 0 2.4-.1l85.7-10.7c28.3 24.8 60.6 43.7 96 56.1l18.7 84.3c3.5 15.7 17.5 27.1 33.6 27.1h174c16.1 0 30.1-11.4 33.6-27.1l18.7-84.3c35.4-12.4 67.7-31.3 96-56.1l85.7 10.7c.8.1 1.6.1 2.4.1 15.9 0 29.9-10.1 36.3-25.6l78-188.2c4.1-11.2 1.4-23.9-7.7-35.2zM512 660c-81.7 0-148-66.3-148-148s66.3-148 148-148 148 66.3 148 148-66.3 148-148 148z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text" style="font-weight: 600;">Admin Inventory</span>
                            <span class="kt-menu__arrow">▶</span>
                        </a>
                        <div class="kt-menu__sub" id="admin_inv_submenu">
                            <?php if (isMenuAllowed('inv_unposting_appv')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_unposting_appv') ? 'active' : '' ?>" id="nav-item-inv_unposting_appv" onclick="switchMainTab('inv_unposting_appv')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">✔️</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm193.5 301.7l-210.6 292a31.8 31.8 0 0 1-51.7 0L318.5 484.9c-3.8-5.3-1.1-12.7 5.4-14.3l44.3-11.1c4.1-1 8.3 1.2 10.3 4.9l80.3 144.3c1.7 3.1 6.1 3.1 7.8 0l165.7-229.8c2.4-3.3 6.9-4.7 10.8-3.4l43.2 14.4c6.3 2.1 8.4 9.8 4.2 14.8z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Approval Revisi / UnPosting</span>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (isMenuAllowed('inv_stok_awal')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_stok_awal') ? 'active' : '' ?>" id="nav-item-inv_stok_awal" onclick="switchMainTab('inv_stok_awal')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">📖</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 184H712v-40c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v40H384v-40c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v40H144c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V216c0-17.7-14.3-32-32-32zm-40 704H184V384h656v504z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Set Stok Awal Tahun</span>
                                </a>
                            </div>
                            <?php endif; ?>

                            <?php if (isMenuAllowed('inv_adjust_spp')): ?>
                            <div class="kt-menu__item <?= ($initialTab === 'inv_adjust_spp') ? 'active' : '' ?>" id="nav-item-inv_adjust_spp" onclick="switchMainTab('inv_adjust_spp')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">⚡</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M848 356H584l72-264c2-7.5-3.6-15-11.4-15H368c-5.2 0-9.8 3.3-11.4 8.2L209 527.2c-2.8 8.4 3.5 16.8 12.3 16.8h234.3L376 932c-2 7.5 3.6 15 11.4 15h184.2c6.3 0 11.5-4.8 12.2-11.1l80.5-388.9h183.7c8.8 0 15.1-8.4 12.3-16.8L848 356z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Adjust SPP</span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Sub Modul: IT Inventory (Expandable) -->
                    <?php if (isMenuAllowed('it_inventory')): ?>
                    <div style="height: 1px; background: var(--border-color); margin: 6px 14px; opacity: 0.6;"></div>

                    <?php 
                    $isItInvActive = in_array($initialTab, [
                        'it_inventory', 'it_inventory_home', 'it_inventory_1a', 'it_inventory_1b', 
                        'it_inventory_1c', 'it_inventory_1d', 'it_inventory_1d_rm', 'it_inventory_1d_fg', 
                        'it_inventory_1d_cg', 'it_inventory_1d_w', 'it_inventory_log'
                    ]) || ($userRole === 'bc');
                    ?>
                    <div class="kt-menu__item kt-menu__item--submenu <?= $isItInvActive ? 'kt-menu__item--open' : '' ?>" id="nav-item-it_inventory_parent">
                        <a class="kt-menu__link" onclick="toggleSubmenu('nav-item-it_inventory_parent')" title="Klik untuk membuka/menutup sub modul IT Inventory">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📊</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M888 792H200V168c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8v688c0 4.4 3.6 8 8 8h752c4.4 0 8-3.6 8-8v-56c0-4.4-3.6-8-8-8zm-616-80h80c4.4 0 8-3.6 8-8V472c0-4.4-3.6-8-8-8h-80c-4.4 0-8 3.6-8 8v232c0 4.4 3.6 8 8 8zm160 0h80c4.4 0 8-3.6 8-8V328c0-4.4-3.6-8-8-8h-80c-4.4 0-8 3.6-8 8v376c0 4.4 3.6 8 8 8zm160 0h80c4.4 0 8-3.6 8-8V200c0-4.4-3.6-8-8-8h-80c-4.4 0-8 3.6-8 8v504c0 4.4 3.6 8 8 8zm160 0h80c4.4 0 8-3.6 8-8V528c0-4.4-3.6-8-8-8h-80c-4.4 0-8 3.6-8 8v176c0 4.4 3.6 8 8 8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text" style="font-weight: 600;">IT Inventory</span>
                            <span class="kt-menu__arrow">▶</span>
                        </a>
                        <div class="kt-menu__sub" id="it_inventory_submenu">
                            <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1a') ? 'active' : '' ?>" id="nav-item-it_inventory_1a" onclick="switchMainTab('it_inventory_1a')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">📥</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M862 736l-44.4-44.4c-3.1-3.1-8.2-3.1-11.3 0L544 953.5V104c0-4.4-3.6-8-8-8h-64c-4.4 0-8 3.6-8 8v849.5L201.7 691.6c-3.1-3.1-8.2-3.1-11.3 0L146 736c-3.1 3.1-3.1 8.2 0 11.3l360.3 360.3c3.1 3.1 8.2 3.1 11.3 0L862 747.3c3.1-3.1 3.1-8.2 0-11.3z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Laporan Pemasukan</span>
                                    <span class="kt-menu__badge-sub">1A</span>
                                </a>
                            </div>
                            <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1b') ? 'active' : '' ?>" id="nav-item-it_inventory_1b" onclick="switchMainTab('it_inventory_1b')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">📤</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M862 288L501.7 72.3c-3.1-3.1-8.2-3.1-11.3 0L130.1 288c-3.1 3.1-3.1 8.2 0 11.3l44.4 44.4c3.1 3.1 8.2 3.1 11.3 0L464 74.5V920c0 4.4 3.6 8 8 8h64c4.4 0 8-3.6 8-8V74.5l278.2 269.2c3.1 3.1 8.2 3.1 11.3 0l44.4-44.4c3.1-3.1 3.1-8.2 0-11.3z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Laporan Pengeluaran</span>
                                    <span class="kt-menu__badge-sub">1B</span>
                                </a>
                            </div>
                            <div class="kt-menu__item <?= ($initialTab === 'it_inventory_1c') ? 'active' : '' ?>" id="nav-item-it_inventory_1c" onclick="switchMainTab('it_inventory_1c')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">⏳</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M742 224h-44v-80c0-4.4-3.6-8-8-8H334c-4.4 0-8 3.6-8 8v80h-44c-17.7 0-32 14.3-32 32v120c0 102.2 68.2 189.7 162.7 218.4C318.2 622.7 250 710.2 250 812.4V932c0 17.7 14.3 32 32 32h444c17.7 0 32-14.3 32-32V812.4c0-102.2-68.2-189.7-162.7-218.4C673.8 565.3 742 477.8 742 376V256c0-17.7-14.3-32-32-32zm-76 152c0 86-70 156-156 156s-156-70-156-156V280h312v96zm0 436.4c0 86-70 156-156 156s-156-70-156-156V716c0-86 70-156 156-156s156 70 156 156v96.4z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Posisi WIP</span>
                                    <span class="kt-menu__badge-sub">1C</span>
                                </a>
                            </div>
                            <div class="kt-menu__item <?= (in_array($initialTab, ['it_inventory_1d', 'it_inventory_1d_rm', 'it_inventory_1d_fg', 'it_inventory_1d_cg', 'it_inventory_1d_w'])) ? 'active' : '' ?>" id="nav-item-it_inventory_1d" onclick="switchMainTab('it_inventory_1d')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">🏭</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M536.1 273H488c-4.4 0-8 3.6-8 8v294.9c0 2.6 1.2 5 3.3 6.5l170.8 122.8c3.6 2.6 8.6 1.8 11.2-1.8l28.6-39.7c2.6-3.6 1.8-8.6-1.8-11.2L544.1 537.4V281c0-4.4-3.6-8-8-8zm219.8-75.9A399.4 399.4 0 0 0 512 80C273.4 80 80 273.4 80 512s193.4 432 432 432c208.6 0 382.4-147.8 422.3-343.8 1.1-5.3-2.6-10.2-8-10.2h-62.8c-4 0-7.5 2.9-8.2 6.9-34.1 161.4-177.3 283.1-343.3 283.1-194.4 0-352-157.6-352-352S297.6 176 492 176c98.6 0 187.7 40.8 251.4 106.6l-89.9 89.9c-3.1 3.1-3.1 8.2 0 11.3l42.4 42.4c3.1 3.1 8.2 3.1 11.3 0l169.7-169.7c3.1-3.1 3.1-8.2 0-11.3L755.9 197.1z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Mutasi Barang</span>
                                    <span class="kt-menu__badge-sub">1D</span>
                                </a>
                            </div>
                            <div class="kt-menu__item <?= ($initialTab === 'it_inventory_log') ? 'active' : '' ?>" id="nav-item-it_inventory_log" onclick="switchMainTab('it_inventory_log')">
                                <a class="kt-menu__link">
                                    <span class="kt-menu__link-icon">
                                        <span class="icon-colored">📜</span>
                                        <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M854.6 288.6L657.4 91.4c-9-9-21.2-14.1-33.9-14.1H224c-26.5 0-48 21.5-48 48v768c0 26.5 21.5 48 48 48h576c26.5 0 48-21.5 48-48V322.5c0-12.7-5.1-24.9-14.1-33.9zM648 160.8l119.2 119.2H648V160.8zm136 695.2H240V144h344v168c0 17.7 14.3 32 32 32h168v512zm-464-168h352c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H320c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8zm0-136h352c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H320c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8z"/></svg></span>
                                    </span>
                                    <span class="kt-menu__link-text">Log Perubahan Data</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Group: REFERENSI -->
                <?php if (isMenuAllowed('referensi') || isMenuAllowed('inv_tarif')): ?>
                <div class="menu-group" id="menu-group-referensi" style="display: <?= $isManifestOnlyCompany ? 'none' : '' ?>;">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">REFERENSI</h4>
                    </div>
                    <?php if (isMenuAllowed('referensi')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'reff') ? 'active' : '' ?>" id="nav-item-reff" onclick="switchMainTab('reff')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📚</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M832 64H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V96c0-17.7-14.3-32-32-32zm-40 824H232V136h560v752zm-440-520h320c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H352c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8zm0 128h320c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H352c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Master Data Referensi</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (isMenuAllowed('inv_tarif')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'inv_tarif') ? 'active' : '' ?>" id="nav-item-inv_tarif" onclick="switchMainTab('inv_tarif')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📑</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M304 384h416c4.4 0 8-3.6 8-8v-96c0-4.4-3.6-8-8-8H304c-4.4 0-8 3.6-8 8v96c0 4.4 3.6 8 8 8zm-8 440h128c4.4 0 8-3.6 8-8v-96c0-4.4-3.6-8-8-8H296c-4.4 0-8 3.6-8 8v96c0 4.4 3.6 8 8 8zm304 0h128c4.4 0 8-3.6 8-8v-96c0-4.4-3.6-8-8-8H600c-4.4 0-8 3.6-8 8v96c0 4.4 3.6 8 8 8zm-152 0h128c4.4 0 8-3.6 8-8v-96c0-4.4-3.6-8-8-8H448c-4.4 0-8 3.6-8 8v96c0 4.4 3.6 8 8 8zm-152-192h128c4.4 0 8-3.6 8-8v-96c0-4.4-3.6-8-8-8H296c-4.4 0-8 3.6-8 8v96c0 4.4 3.6 8 8 8zm152 0h128c4.4 0 8-3.6 8-8v-96c0-4.4-3.6-8-8-8H448c-4.4 0-8 3.6-8 8v96c0 4.4 3.6 8 8 8zm152 0h128c4.4 0 8-3.6 8-8v-96c0-4.4-3.6-8-8-8H600c-4.4 0-8 3.6-8 8v96c0 4.4 3.6 8 8 8zm280-488H144c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V176c0-17.7-14.3-32-32-32zm-40 728H184V216h656v688z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Master Tarif BM dari Kode HS</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Group: ADMINISTRATOR (Khusus Admin) -->
                <?php if ($isAdmin): ?>
                <div class="menu-group">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">ADMINISTRATOR</h4>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'company') ? 'active' : '' ?>" id="nav-item-company" onclick="switchMainTab('company')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🏢</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M899.2 226.3L518.3 46.1a16.2 16.2 0 0 0-12.6 0L124.8 226.3c-4.9 2.3-8 7.3-8 12.7v49c0 7.7 6.3 14 14 14h762.4c7.7 0 14-6.3 14-14v-49c0-5.4-3.1-10.4-8-12.7zM240 760h80V372h-80v388zm192 0h80V372h-80v388zm192 0h80V372h-80v388zm264 48H136c-4.4 0-8 3.6-8 8v56c0 4.4 3.6 8 8 8h752c4.4 0 8-3.6 8-8v-56c0-4.4-3.6-8-8-8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Kelola Perusahaan</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'app_settings') ? 'active' : '' ?>" id="nav-item-app_settings" onclick="switchMainTab('app_settings')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">⚙️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M924.8 625.7l-65.5-56c3.1-19 4.7-38.4 4.7-57.7s-1.6-38.7-4.7-57.7l65.5-56a32.03 32.03 0 0 0 7.7-35.2l-78-188.2a32.05 32.05 0 0 0-26-19.7l-85.7-10.7c-28.3-24.8-60.6-43.7-96-56.1l-18.7-84.3A32.05 32.05 0 0 0 595 16h-174a32.05 32.05 0 0 0-31.3 25.1l-18.7 84.3c-35.4 12.4-67.7 31.3-96 56.1l-85.7 10.7a32.05 32.05 0 0 0-26 19.7l-78 188.2a32.03 32.03 0 0 0 7.7 35.2l65.5 56c-3.1 19-4.7 38.4-4.7 57.7s1.6 38.7 4.7 57.7l-65.5 56a32.03 32.03 0 0 0-7.7 35.2l78 188.2c6.4 15.5 20.4 25.6 36.3 25.6.8 0 1.6 0 2.4-.1l85.7-10.7c28.3 24.8 60.6 43.7 96 56.1l18.7 84.3c3.5 15.7 17.5 27.1 33.6 27.1h174c16.1 0 30.1-11.4 33.6-27.1l18.7-84.3c35.4-12.4 67.7-31.3 96-56.1l85.7 10.7c.8.1 1.6.1 2.4.1 15.9 0 29.9-10.1 36.3-25.6l78-188.2c4.1-11.2 1.4-23.9-7.7-35.2zM512 660c-81.7 0-148-66.3-148-148s66.3-148 148-148 148 66.3 148 148-66.3 148-148 148z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Setting Aplikasi</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'docs') ? 'active' : '' ?>" id="nav-item-docs" onclick="switchMainTab('docs')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📖</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M928 160H96c-17.7 0-32 14.3-32 32v640c0 17.7 14.3 32 32 32h832c17.7 0 32-14.3 32-32V192c0-17.7-14.3-32-32-32zm-40 632H136V232h752v560zM421.2 444.9L326.6 512l94.6 67.1c3.6 2.6 4.4 7.6 1.8 11.2l-27.7 39.1c-2.6 3.6-7.6 4.4-11.2 1.8l-135.5-96c-4.8-3.4-7.6-9-7.6-14.9s2.8-11.5 7.6-14.9l135.5-96c3.6-2.6 8.6-1.8 11.2 1.8l27.7 39.1c2.6 3.6 1.8 8.6-1.8 11.2zm181.6 0c-3.6-2.6-4.4-7.6-1.8-11.2l27.7-39.1c2.6-3.6 7.6-4.4 11.2-1.8l135.5 96c4.8 3.4 7.6 9 7.6 14.9s-2.8 11.5-7.6 14.9l-135.5 96c-3.6 2.6-8.6 1.8-11.2-1.8l-27.7-39.1c-2.6-3.6-1.8-8.6 1.8-11.2L700.4 512l-97.6-67.1z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Dokumentasi REST API</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'db') ? 'active' : '' ?>" id="nav-item-db" onclick="switchMainTab('db')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🗄️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M832 64H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V96c0-17.7-14.3-32-32-32zm-40 824H232V744h560v144zm0-216H232V528h560v144zm0-216H232V312h560v144zm0-216H232V136h560v72z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Database Browser</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <div class="kt-menu__item <?= ($initialTab === 'version_log') ? 'active' : '' ?>" id="nav-item-version_log" onclick="switchMainTab('version_log')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">📜</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M880 112H144c-17.7 0-32 14.3-32 32v736c0 17.7 14.3 32 32 32h736c17.7 0 32-14.3 32-32V144c0-17.7-14.3-32-32-32zm-40 728H184V184h656v656zM256 312h400c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H256c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8zm0 160h400c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H256c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8zm0 160h240c4.4 0 8-3.6 8-8v-48c0-4.4-3.6-8-8-8H256c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Log Update Versi</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Group: USER MANAGEMENT -->
                <div class="menu-group">
                    <div class="kt-menu__section">
                        <h4 class="kt-menu__section-text">USER MANAGEMENT</h4>
                    </div>
                    <?php if (isMenuAllowed('user_management')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'user_management') ? 'active' : '' ?>" id="nav-item-user_management" onclick="switchMainTab('user_management')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">👥</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M824.2 699.9a301.55 301.55 0 0 0-86.4-60.4C783.1 602.8 812 546.8 812 484c0-110.5-89.5-200-200-200s-200 89.5-200 200c0 62.8 28.9 118.8 74.2 155.5-31.9 17.5-60.8 37.9-86.4 60.4C349.5 744.1 320 801.3 320 864h64c0-123.7 100.3-224 228-224s228 100.3 228 224h64c0-62.7-29.5-119.9-79.8-164.1zM612 620c-75 0-136-61-136-136s61-136 136-136 136 61 136 136-61 136-136 136zm-364-16c0-4.4-3.6-8-8-8h-56c-4.4 0-8 3.6-8 8 0 79.5 41.5 150.3 105.7 192.1 3.7 2.4 8.7 1.4 11.1-2.3l33.1-50.6c2.4-3.7 1.4-8.7-2.3-11.1C281.3 700.3 248 654.5 248 604zm-8-364c-70.7 0-128 57.3-128 128s57.3 128 128 128 128-57.3 128-128-57.3-128-128-128zm0 192c-35.3 0-64-28.7-64-64s28.7-64 64-64 64 28.7 64 64-28.7 64-64 64z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Kelola User Perusahaan</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($isSuperAdmin): ?>
                    <div class="kt-menu__item" onclick="window.open('/visitor', '_blank')">
                        <a class="kt-menu__link" title="Buka Dashboard Visitor & IP Analytics Log">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🌐</span>
                                <span class="icon-monochrome"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Visitor Analytics Log</span>
                            <i class="kt-menu__ver-arrow">↗</i>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isAdmin && isMenuAllowed('app_settings')): ?>
                    <div class="kt-menu__item <?= ($initialTab === 'app_settings') ? 'active' : '' ?>" id="nav-item-app_settings" onclick="switchMainTab('app_settings')">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">⚙️</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M924.8 625.7l-65.5-56c3.1-19 4.7-38.4 4.7-57.7s-1.6-38.7-4.7-57.7l65.5-56a32.03 32.03 0 0 0 7.7-35.2l-78-188.2a32.05 32.05 0 0 0-26-19.7l-85.7-10.7c-28.3-24.8-60.6-43.7-96-56.1l-18.7-84.3A32.05 32.05 0 0 0 595 16h-174a32.05 32.05 0 0 0-31.3 25.1l-18.7 84.3c-35.4 12.4-67.7 31.3-96 56.1l-85.7 10.7a32.05 32.05 0 0 0-26 19.7l-78 188.2a32.03 32.03 0 0 0 7.7 35.2l65.5 56c-3.1 19-4.7 38.4-4.7 57.7s1.6 38.7 4.7 57.7l-65.5 56a32.03 32.03 0 0 0-7.7 35.2l78 188.2c6.4 15.5 20.4 25.6 36.3 25.6.8 0 1.6 0 2.4-.1l85.7-10.7c28.3 24.8 60.6 43.7 96 56.1l18.7 84.3c3.5 15.7 17.5 27.1 33.6 27.1h174c16.1 0 30.1-11.4 33.6-27.1l18.7-84.3c35.4-12.4 67.7-31.3 96-56.1l85.7 10.7c.8.1 1.6.1 2.4.1 15.9 0 29.9-10.1 36.3-25.6l78-188.2c4.1-11.2 1.4-23.9-7.7-35.2zM512 660c-81.7 0-148-66.3-148-148s66.3-148 148-148 148 66.3 148 148-66.3 148-148 148z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Setting Aplikasi</span>
                            <i class="kt-menu__ver-arrow">›</i>
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="kt-menu__item" onclick="openModalPass()">
                        <a class="kt-menu__link">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🔑</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M632 144c-145.8 0-264 118.2-264 264 0 54.1 16.3 104.4 44.3 146.4l-275.6 275.5a8.03 8.03 0 0 0-2.3 5.7V880c0 4.4 3.6 8 8 8h128c4.4 0 8-3.6 8-8v-64h64c4.4 0 8-3.6 8-8v-64h64c4.4 0 8-3.6 8-8v-64h64c2.1 0 4.2-.8 5.7-2.3l49.9-49.9C579.6 657.7 605.9 672 632 672c145.8 0 264-118.2 264-264S777.8 144 632 144zm0 456c-106 0-192-86-192-192s86-192 192-192 192 86 192 192-86 192-192 192zm56-256a48 48 0 1 0 96 0 48 48 0 1 0-96 0z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Ganti Password</span>
                        </a>
                    </div>
                    <div class="kt-menu__item">
                        <a href="index.php?action=logout" class="kt-menu__link" style="color:#fd3995;">
                            <span class="kt-menu__link-icon">
                                <span class="icon-colored">🚪</span>
                                <span class="icon-monochrome"><svg viewBox="64 64 896 896" width="16" height="16" fill="currentColor"><path d="M868 732h-70.3c-4.8 0-9.3 2.1-12.3 5.8-7 8.5-14.5 16.7-22.4 24.5a353.84 353.84 0 0 1-112.7 75.9A352.8 352.8 0 0 1 512.4 866c-47.9 0-94.3-9.4-137.9-27.8a353.84 353.84 0 0 1-112.7-75.9 353.28 353.28 0 0 1-76-112.5C167.3 606.2 158 559.9 158 512s9.4-94.2 27.8-137.8c17.8-42.1 43.4-80 76-112.5s70.5-58.2 112.7-75.9C418.1 167.4 464.5 158 512.4 158c47.9 0 94.3 9.4 137.9 27.8A353.84 353.84 0 0 1 763 261.7c7.9 7.8 15.4 16 22.4 24.5 3 3.7 7.6 5.8 12.3 5.8H868c6.3 0 10.2-7 6.7-12.3C798 160.8 663.8 80 512 80 273.4 80 80 273.4 80 512s193.4 432 432 432c151.8 0 286-80.8 362.7-199.7 3.5-5.3-.4-12.3-6.7-12.3zm82.4-230.7L826.8 377.7a8 8 0 0 0-11.3 0l-33.9 33.9a8 8 0 0 0 0 11.3l60.5 60.5H412c-4.4 0-8 3.6-8 8v48c0 4.4 3.6 8 8 8h440.1l-60.5 60.5a8 8 0 0 0 0 11.3l33.9 33.9a8 8 0 0 0 11.3 0l123.6-123.6a8 8 0 0 0 0-11.3z"/></svg></span>
                            </span>
                            <span class="kt-menu__link-text">Log Out</span>
                        </a>
                    </div>
                </div>
                <?php endif; // End of if ($userRole === 'bc') else ?>
            </div>

            <!-- SIDEBAR FOOTER (Versi Update) -->
            <div class="kt-aside__footer" title="Klik untuk melihat riwayat log update versi sistem" onclick="switchMainTab('version_log')" style="cursor:pointer;">
                Versi Update : <span id="sidebarAppVersion" style="text-decoration:underline; font-weight:600;"><?= htmlspecialchars($appUpdateVersion) ?></span>
            </div>
        </aside>

        <!-- MAIN VIEW WRAPPER (#kt_wrapper) -->
        <div class="kt-wrapper" id="kt_wrapper">
            <!-- TOPBAR HEADER (#kt_header) -->
            <header class="kt-header" id="kt_header">
                <div class="kt-header-left">
                    <button class="kt-aside__brand-aside-toggler" onclick="toggleSidebarPin()" title="Toggle Sidebar">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
                        </svg>
                    </button>
                    <div class="kt-header-title">
                        <span class="kt-header-breadcrumb" id="topbarBreadcrumb" style="font-size:12.5px; font-weight:600; color:var(--text-main);"><?= htmlspecialchars($initBreadcrumbText) ?></span>
                    </div>
                </div>

                <div class="kt-header__topbar">
                    <?php if ($userRole !== 'bc'): ?>
                    <!-- Link Resmi Portal CEISA 4.0 -->
                    <a href="https://portal.beacukai.go.id/portal" target="_blank" rel="noopener noreferrer" class="btn-topbar-portal" title="Buka Portal Resmi CEISA 4.0 Bea Cukai (portal.beacukai.go.id)">
                        <svg viewBox="64 64 896 896" width="12" height="12" fill="currentColor">
                            <path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm252.6 576.4c-38.3 75.9-100.7 136.4-178.6 172.5 48.7-65.7 82.2-143.6 97.4-226.5h86.7c-1.8 18.2-3.6 36.1-5.5 54zm-208.4 207c-27.1 27.6-59.4 48.8-95.2 62.3-35.8-13.5-68.1-34.7-95.2-62.3-25.1-25.6-45.6-55.7-60.7-89h311.8c-15.1 33.3-35.6 63.4-60.7 89zm-388.2-80.5c-77.9-36.1-140.3-96.6-178.6-172.5-1.9-17.9-3.7-35.8-5.5-54h86.7c15.2 82.9 48.7 160.8 97.4 226.5zM122.3 480c0-16.1 1.3-32.2 3.7-48h112.5c-2.4 15.8-3.7 31.9-3.7 48s1.3 32.2 3.7 48H126c-2.4-15.8-3.7-31.9-3.7-48zm178-48h423.4c2.4 15.8 3.7 31.9 3.7 48s-1.3 32.2-3.7 48H300.3c-2.4-15.8-3.7-31.9-3.7-48s1.3-32.2 3.7-48zm501.4 0h112.5c2.4 15.8 3.7 31.9 3.7 48s-1.3 32.2-3.7 48H801.7c2.4-15.8 3.7-31.9 3.7-48s-1.3-32.2-3.7-48zM764.6 209.6c77.9 36.1 140.3 96.6 178.6 172.5 1.9 17.9 3.7 35.8 5.5 54h-86.7c-15.2-82.9-48.7-160.8-97.4-226.5zM512 84.7c35.8 13.5 68.1 34.7 95.2 62.3 25.1 25.6 45.6 55.7 60.7 89H296.1c15.1-33.3 35.6-63.4 60.7-89 27.1-27.6 59.4-48.8 95.2-62.3zm-278.6 125c-48.7 65.7-82.2 143.6-97.4 226.5h-86.7c1.8-18.2 3.6-36.1 5.5-54 38.3-75.9 100.7-136.4 178.6-172.5z"></path>
                        </svg>
                        <span>Portal CEISA</span>
                        <span style="font-size:9px;">↗</span>
                    </a>

                    <!-- MFA Token Generator Button -->
                    <button type="button" class="btn-topbar-mfa" onclick="openMfaModal()" title="Generate MFA Token Ceisa 4.0">
                        <svg viewBox="64 64 896 896" width="12" height="12" fill="currentColor">
                            <path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm128 400h-32v-96c0-52.9-43.1-96-96-96s-96 43.1-96 96v96h-32c-17.7 0-32 14.3-32 32v192c0 17.7 14.3 32 32 32h256c17.7 0 32-14.3 32-32V516c0-17.7-14.3-32-32-32zm-160-96c0-17.6 14.4-32 32-32s32 14.4 32 32v96h-64v-96zm128 256H384V548h256v196z"></path>
                        </svg>
                        <span>MFA Token</span>
                    </button>

                    <!-- Token Expired Counter -->
                    <div class="kt-header__topbar-user">
                        <span class="kt-header__topbar-welcome">Token Expired : </span>
                        <span class="ant-statistic-content-value" id="tokenTimer">01:54:14</span>
                    </div>

                    <!-- Notification Bell -->
                    <div class="notif-bell" title="Pusat Peringatan & Notifikasi Sistem" onclick="showNotificationAlerts()">
                        <svg viewBox="64 64 896 896" width="20" height="20" fill="rgb(24, 144, 255)">
                            <path d="M816 768h-24V428c0-141.1-104.3-257.7-240-277.1V112c0-22.1-17.9-40-40-40s-40 17.9-40 40v38.9c-135.7 19.4-240 136-240 277.1v340h-24c-17.7 0-32 14.3-32 32v32c0 4.4 3.6 8 8 8h216c0 61.8 50.2 112 112 112s112-50.2 112-112h216c4.4 0 8-3.6 8-8v-32c0-17.7-14.3-32-32-32zM512 888c-26.5 0-48-21.5-48-48h96c0 26.5-21.5 48-48 48zM304 768V428c0-55.6 21.6-107.8 60.9-147.1S456.4 220 512 220c55.6 0 107.8 21.6 147.1 60.9S720 372.4 720 428v340H304z"></path>
                        </svg>
                        <span class="notif-header-badge" id="notifBadge"></span>
                    </div>
                    <?php endif; ?>

                    <!-- User Greeting -->
                    <div class="kt-header__topbar-user">
                        <?php if ($userRole !== 'bc'): ?>
                        <span class="kt-header__topbar-welcome">Hi,</span>
                        <span class="kt-header__topbar-username" title="User: <?= htmlspecialchars($sessionUserName) ?><?= !empty($sessionUserId) ? ' (' . htmlspecialchars($sessionUserId) . ')' : '' ?>">
                            <?= htmlspecialchars($sessionUserName) ?>
                        </span>
                        <?php endif; ?>
                        <span id="topbarUserBadge" style="display:none !important;"></span>
                    </div>

                    <!-- Theme Selector -->
                    <div class="theme-select">
                        <button class="theme-btn active" data-theme="light" onclick="setTheme('light')">☀️</button>
                        <button class="theme-btn" data-theme="dark" onclick="setTheme('dark')">🌙</button>
                    </div>

                    <!-- Log Out -->
                    <a href="index.php?action=logout" class="btn-logout" title="Log Out">Log Out</a>
                </div>
            </header>

            <!-- MAIN IFRAME VIEW -->
            <div id="iframeWrapper" style="flex:1; width:100%; height:calc(100vh - 38px); position:relative; overflow:hidden;">
                <!-- LOADER OVERLAY -->
                <div id="iframeLoader" class="iframe-loader-overlay">
                    <div class="iframe-top-progress"></div>
                    <div class="iframe-spinner"></div>
                    <div id="iframeLoaderTitle" class="iframe-loader-title">Memuat <?= htmlspecialchars($breadcrumbTitlesPhp[$initialTab] ?? 'Dashboard Ceisa 4.0') ?>...</div>
                    <div class="iframe-loader-subtitle">Menghubungkan ke server...</div>
                </div>

                <iframe id="mainFrame" class="iframe-container" data-tab="<?= htmlspecialchars($initialTab) ?>" src="<?= htmlspecialchars($initSrc) ?>" style="width:100%; height:100%; border:none; display:block;"></iframe>
            </div>
            <script>
                (function() {
                    var th = localStorage.getItem('ceisa_theme') || 'light';
                    var iframe = document.getElementById('mainFrame');
                    if (iframe && th !== 'light') {
                        iframe.src = iframe.src + '&theme=' + encodeURIComponent(th);
                    }
                })();
            </script>
        </div>

    </div>

    <?php if ($isAdmin || !empty($_SESSION['can_switch_company'])): ?>
    <!-- 3. MODAL GANTI PERUSAHAAN (ADMIN & MULTI-COMPANY USER) -->
    <div class="modal" id="modalCompanySwitcher">
        <div class="modal-card" style="max-width: 600px; max-height: 85vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title" style="color:#1890ff;">
                        <span>🏢</span> Pilih Perusahaan Aktif <?= $isAdmin ? '(Mode Administrator)' : '' ?>
                    </h3>
                    <p style="font-size:10.5px; color:var(--text-muted); margin:3px 0 0 0;">
                        Pilih perusahaan untuk langsung beralih konteks data tanpa login ulang.
                    </p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeCompanyModal()" title="Tutup">✕</button>
            </div>

            <div style="padding: 12px 20px; border-bottom: 1px solid var(--border-color); background:var(--bg-card);">
                <input type="text" id="companySearchInput" placeholder="🔍 Cari nama perusahaan, kode cmp_id, atau NPWP..." 
                       onkeyup="filterCompanySearch()" 
                       style="width:100%; padding: 7px 12px; font-size:11.5px; border-radius:6px; border:1px solid var(--border-color); outline:none; background:var(--bg-card); color:var(--text-main);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px; font-size:10.5px; color:var(--text-muted);">
                    <span id="companyCountLabel">Memuat daftar perusahaan...</span>
                    <span>💡 Klik baris untuk langsung beralih</span>
                </div>
            </div>

            <div style="padding: 12px 20px; overflow-y:auto; flex:1; max-height:360px;" id="companyListContainer">
            </div>

            <div class="modal-footer">
                <button type="button" class="ant-btn ant-btn-default" onclick="closeCompanyModal()">Tutup</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL MFA TOKEN (GOOGLE AUTHENTICATOR) -->
    <div class="modal" id="modalMfa">
        <div class="modal-card" style="max-width: 380px; padding: 0; overflow: hidden; border-radius: 8px;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:16px;">🔐</span>
                    <h3 class="modal-title" style="margin:0;">MFA Token Ceisa 4.0</h3>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeMfaModal()" title="Tutup">✕</button>
            </div>

            <div class="modal-body" style="padding: 16px 20px;">
                <!-- Company Switcher inside MFA Modal (HANYA UNTUK ADMIN) -->
                <?php if ($isAdmin): ?>
                <div style="margin-bottom: 12px;">
                    <label style="font-size:10.5px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.3px;">PILIH PROFIL AKUN PERUSAHAAN</label>
                    <select id="mfaModalCompany" onchange="changeMfaModalCompany(this.value)" style="width:100%; height:32px; font-size:11.5px; border-radius:6px; border:1px solid var(--border-color); padding:0 10px; margin-top:4px; outline:none; background:var(--bg-card); color:var(--text-main); transition:border-color 0.2s;">
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= htmlspecialchars($c['cmp_id']) ?>" <?= ($c['cmp_id'] === $sessionCmpId) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($c['cmp_id']) ?>] <?= htmlspecialchars($c['cmp_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div style="margin-bottom: 10px; padding: 6px 10px; background: var(--bg-base); border-radius: 6px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: var(--text-main);">
                        <span>🏢</span>
                        <span><?= htmlspecialchars($sessionCmpName) ?></span>
                    </div>
                    <span style="font-size: 9.5px; color: var(--text-muted); font-family: monospace;">[<?= htmlspecialchars($sessionCmpId) ?>]</span>
                </div>
                <?php endif; ?>

                <!-- TOTP Card Exact Match to User Screenshot with Modern Flare -->
                <div class="totp-card" id="mfaModalCard" onclick="copyMfaModalOtp()" title="Klik untuk menyalin kode OTP">
                    <!-- Baris 1: Issuer -->
                    <div class="totp-issuer">
                        <span id="mfaModalIssuer">portal-beacukai</span>
                        <span class="totp-badge-auto">Live TOTP</span>
                    </div>

                    <!-- Baris 2: Code 6 Digit Besar -->
                    <div class="totp-code-wrap">
                        <span class="totp-code" id="mfaModalCode">------</span>
                        <span class="totp-copy-icon">📋 Salin</span>
                    </div>

                    <!-- Baris 3: Username -->
                    <div class="totp-username" id="mfaModalUsername">Memuat...</div>

                    <!-- Baris 4: Countdown & Progress Bar -->
                    <div class="timer-strip">
                        <span>Berubah dalam</span>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" id="mfaModalProgress"></div>
                        </div>
                        <span style="font-family:monospace; font-weight:700; width:24px; text-align:right;" id="mfaModalSeconds">30s</span>
                    </div>
                </div>

                <div style="display:flex; gap:8px; margin-top:14px;">
                    <button type="button" class="ant-btn ant-btn-primary" onclick="copyMfaModalOtp()" style="flex:1; height:34px; font-size:11.5px; font-weight:600; justify-content:center;">
                        📋 Salin Kode
                    </button>
                    <button type="button" class="ant-btn ant-btn-default" onclick="openMfaPopup()" style="height:34px; font-size:11.5px; justify-content:center; padding:0 14px;" title="Buka dalam jendela popup tersendiri">
                        ↗ Pop-up
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL GANTI PASSWORD -->
    <div class="modal" id="modalPassword">
        <div class="modal-card" style="max-width: 440px; padding: 0; overflow: hidden; border-radius: 8px;">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">
                        <span>🔑</span> Ganti Password <?= $isSuperAdmin ? 'Super Administrator' : ($isCompanyAdmin ? 'Admin Perusahaan' : 'Pengguna') ?>
                    </h3>
                    <p style="font-size: 10.5px; color: var(--text-muted); margin: 3px 0 0 0;">
                        Perbarui kata sandi akun untuk menjaga keamanan akses portal.
                    </p>
                </div>
                <button type="button" class="modal-close-btn" onclick="closeModalPass()" title="Tutup">✕</button>
            </div>
            <form id="formChangePass" onsubmit="submitChangePass(event)">
                <div class="modal-body" style="padding: 16px 20px;">
                    <div class="form-group">
                        <label>Password Lama</label>
                        <div class="input-password-wrap">
                            <input type="password" id="passOld" required placeholder="Masukkan password saat ini...">
                            <button type="button" class="btn-toggle-pass" onclick="togglePassVisibility('passOld', this)" title="Tampilkan/Sembunyikan password"><i class="fa-regular fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Password Baru (Minimal 4 Karakter)</label>
                        <div class="input-password-wrap">
                            <input type="password" id="passNew" required placeholder="Masukkan password baru...">
                            <button type="button" class="btn-toggle-pass" onclick="togglePassVisibility('passNew', this)" title="Tampilkan/Sembunyikan password"><i class="fa-regular fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 4px;">
                        <label>Konfirmasi Password Baru</label>
                        <div class="input-password-wrap">
                            <input type="password" id="passConfirm" required placeholder="Ketik ulang password baru...">
                            <button type="button" class="btn-toggle-pass" onclick="togglePassVisibility('passConfirm', this)" title="Tampilkan/Sembunyikan password"><i class="fa-regular fa-eye"></i></button>
                        </div>
                    </div>
                    <div id="passMsg" style="display:none; font-size:11px; margin-top:10px; padding:8px 12px; border-radius:6px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ant-btn ant-btn-default" onclick="closeModalPass()">Batal</button>
                    <button type="submit" class="ant-btn ant-btn-primary" id="btnSavePass">
                        <span>💾</span> Simpan Password
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div id="toast">✓ Berhasil dialihkan!</div>

    <script>
        let currentTab = '<?= htmlspecialchars($initialTab) ?>';
        let currentAjuParam = '<?= htmlspecialchars($initialAju) ?>';
        let currentCmpId = '<?= htmlspecialchars($sessionCmpId) ?>';
        let allCompanies = <?= json_encode($companies) ?>;
        const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
        const canSwitchCompany = <?= (!empty($isAdmin) || !empty($_SESSION['can_switch_company'])) ? 'true' : 'false' ?>;
        const isSuperAdmin = <?= $isSuperAdmin ? 'true' : 'false' ?>;
        const isCompanyAdmin = <?= $isCompanyAdmin ? 'true' : 'false' ?>;
        const isBcRole = <?= ($userRole === 'bc') ? 'true' : 'false' ?>;
        let currentIsInv = <?= (int)$sessionIsInv ?>;
        let showMenuTracking = <?= (($appSettings['show_menu_tracking'] ?? '0') === '1') ? 'true' : 'false' ?>;

        window.tabIframes = {};
        const initMainIframe = document.getElementById('mainFrame');
        if (initMainIframe) {
            window.tabIframes[currentTab] = initMainIframe;
        }

        function syncIframeState(ifrm) {
            if (!ifrm || !ifrm.contentWindow) return;
            const currentTheme = localStorage.getItem('ceisa_theme') || 'light';
            try {
                ifrm.contentWindow.postMessage({ type: 'SET_THEME', theme: currentTheme }, '*');
                ifrm.contentWindow.postMessage({ type: 'SET_USER_ROLE', isAdmin: isAdmin }, '*');
            } catch(e) {}
        }

        let loaderTimeout = null;
        function showIframeLoader(title) {
            const loader = document.getElementById('iframeLoader');
            const titleEl = document.getElementById('iframeLoaderTitle');
            if (!loader) return;
            if (titleEl) {
                titleEl.innerText = title ? `Memuat ${title}...` : 'Memuat data...';
            }
            loader.style.display = 'flex';
            requestAnimationFrame(() => {
                loader.style.opacity = '1';
            });
            if (loaderTimeout) clearTimeout(loaderTimeout);
            loaderTimeout = setTimeout(hideIframeLoader, 12000);
        }

        function hideIframeLoader() {
            const loader = document.getElementById('iframeLoader');
            if (!loader) return;
            if (loaderTimeout) {
                clearTimeout(loaderTimeout);
                loaderTimeout = null;
            }
            loader.style.opacity = '0';
            setTimeout(() => {
                if (loader.style.opacity === '0') {
                    loader.style.display = 'none';
                }
            }, 260);
        }

        function attachIframeSync(ifrm) {
            if (!ifrm) return;
            ifrm.addEventListener('load', function() {
                syncIframeState(ifrm);
                hideIframeLoader();
            });
            ifrm.addEventListener('error', function() {
                hideIframeLoader();
            });
        }
        if (initMainIframe) {
            attachIframeSync(initMainIframe);
            try {
                if (initMainIframe.contentDocument && initMainIframe.contentDocument.readyState === 'complete') {
                    hideIframeLoader();
                }
            } catch(e) {}
        }

        let currentCompanyRoles = <?= json_encode($sessionCompanyRoles) ?>;
        let currentErpName = <?= json_encode($sessionErpName) ?>;

        function applyRoleMenuVisibility(rolesStr, erpName, isInv) {
            if (rolesStr !== undefined && rolesStr !== null) currentCompanyRoles = rolesStr;
            if (erpName !== undefined && erpName !== null) currentErpName = erpName;
            if (isInv !== undefined && isInv !== null) currentIsInv = parseInt(isInv);

            if (isBcRole) return; // Role Pejabat Bea Cukai khusus laporan IT Inventory

            const roles = (currentCompanyRoles || 'tpb').toLowerCase().split(',').map(s => s.trim());
            const hasManifest = roles.includes('manifest');
            const hasPib = roles.includes('pib');
            const hasPeb = roles.includes('peb');
            const hasTpb = roles.includes('tpb');
            const hasPpkek = roles.includes('ppkek');
            const isManifestOnly = (hasManifest && !hasPib && !hasPeb && !hasTpb && !hasPpkek);
            const hasCustoms = (hasPib || hasPeb || hasTpb || hasPpkek);
            const isOdoo = ((currentErpName || '').toUpperCase() === 'ODOO');
            const canInventory = ((hasTpb || hasPpkek) && (currentIsInv === 1));

            // Section Dokumen Pabean
            const grpPabean = document.getElementById('menu-group-dokumen-pabean');
            if (grpPabean) grpPabean.style.display = hasCustoms ? '' : 'none';

            // Section Winline ERP
            const grpWinline = document.getElementById('menu-group-winline-erp');
            if (grpWinline) grpWinline.style.display = hasCustoms ? '' : 'none';

            // Section Manifes Online
            const grpMan = document.getElementById('menu-group-manifes');
            if (grpMan) grpMan.style.display = hasManifest ? '' : 'none';

            // Item Dokumen Pabean
            const elDash = document.getElementById('nav-item-daftar_dokumen');
            if (elDash) elDash.style.display = hasCustoms ? '' : 'none';

            const elTrans = document.getElementById('nav-item-transfer');
            if (elTrans) elTrans.style.display = hasCustoms ? '' : 'none';

            const elEdit = document.getElementById('nav-item-editor');
            if (elEdit) elEdit.style.display = hasCustoms ? '' : 'none';

            const elImp = document.getElementById('nav-item-import');
            if (elImp) elImp.style.display = hasCustoms ? '' : 'none';

            const elOdoo = document.getElementById('nav-item-load_erp_odoo');
            if (elOdoo) elOdoo.style.display = (hasCustoms && isOdoo) ? '' : 'none';

            const elDokap = document.getElementById('nav-item-dokap');
            if (elDokap) elDokap.style.display = hasCustoms ? '' : 'none';

            const elTrack = document.getElementById('nav-item-tracking');
            if (elTrack) elTrack.style.display = (hasCustoms && showMenuTracking) ? '' : 'none';

            // Item Manifes Online
            const elManCeisa = document.getElementById('nav-item-manifest_ceisa');
            if (elManCeisa) elManCeisa.style.display = hasManifest ? '' : 'none';

            const elMan = document.getElementById('nav-item-manifest');
            if (elMan) elMan.style.display = hasManifest ? '' : 'none';

            // Section Modul Inventory (HANYA TPB & PPKEK)
            const grpInv = document.getElementById('menu-group-inventory-modul');
            if (grpInv) grpInv.style.display = canInventory ? '' : 'none';

            // Section Referensi (Sembunyikan untuk Manifest-Only)
            const grpRef = document.getElementById('menu-group-referensi');
            if (grpRef) grpRef.style.display = isManifestOnly ? 'none' : '';

            // Fallback tab bila tab aktif saat ini disembunyikan
            if (isManifestOnly) {
                if (currentTab !== 'manifest_ceisa' && currentTab !== 'manifest' && currentTab !== 'company' && currentTab !== 'app_settings' && currentTab !== 'docs' && currentTab !== 'db' && currentTab !== 'user_management') {
                    switchMainTab('manifest_ceisa');
                }
            } else if (!canInventory && (currentTab.startsWith('it_inventory') || currentTab.startsWith('inv_'))) {
                switchMainTab(hasCustoms ? 'daftar_dokumen' : 'manifest_ceisa');
            } else if (currentTab === 'load_erp_odoo' && (!hasCustoms || !isOdoo)) {
                switchMainTab('daftar_dokumen');
            } else if ((currentTab === 'manifest' || currentTab === 'manifest_ceisa') && !hasManifest) {
                switchMainTab(hasCustoms ? 'daftar_dokumen' : 'company');
            } else if (currentTab === 'tracking' && !showMenuTracking) {
                switchMainTab('daftar_dokumen');
            }
        }

        function setItInventoryVisibility(isInv) {
            applyRoleMenuVisibility(undefined, undefined, isInv);
        }

        const breadcrumbTitles = {
            'daftar_dokumen': 'Dashboard Ceisa 4.0',
            'transfer': 'Data Host to Host',
            'editor': 'Form Editor Dokumen',
            'import': 'Import Excel Dokumen',
            'manifest_ceisa': 'Manifes Ceisa 4.0',
            'manifest': 'Manifes Host to Host',
            'bc11_monitoring': 'BC 1.1 Monitoring',
            'dokap': 'Upload Dokumen Pelengkap',
            'load_erp_odoo': 'Load ERP Data - Odoo to CEISA 4.0',
            'winline_sales_invoice': 'Winline ERP — Sales Invoice (BC 2.7, BC 3.0 Customer)',
            'winline_dn_transfer': 'Winline ERP — DN Transfer (BC 2.7 Antar Framas)',
            'winline_purchase_order': 'Winline ERP — Purchase Order (BC 2.3, BC 4.0, BC 1.6)',
            'tracking': 'Tracking Respon Pabean',
            'it_inventory_home': 'Bea Cukai - Home Dashboard',
            'it_inventory_1a': 'IT Inventory - 1A Pemasukan Barang',
            'it_inventory_1b': 'IT Inventory - 1B Pengeluaran Barang',
            'it_inventory_1c': 'IT Inventory - 1C Posisi WIP',
            'it_inventory_1d': 'IT Inventory - 1D Mutasi Barang',
            'it_inventory_1d_rm': 'IT Inventory - 1D Mutasi Bahan Baku',
            'it_inventory_1d_fg': 'IT Inventory - 1D Mutasi Barang Jadi',
            'it_inventory_1d_cg': 'IT Inventory - 1D Mutasi Mesin & Alat',
            'it_inventory_1d_w': 'IT Inventory - 1D Mutasi Reject & Scrap',
            'it_inventory_log': 'IT Inventory - Log Perubahan Data',
            'reff': 'Master Data Referensi',
            'company': 'Kelola Perusahaan',
            'app_settings': 'Setting Aplikasi',
            'user_management': 'Kelola User Perusahaan',
            'docs': 'Dokumentasi REST API',
            'db': 'Database Browser',
            'version_log': 'Log Update Versi Sistem',
            'inv_material': 'Master Data Barang',
            'inv_koreksi_klas': 'Koreksi Klasifikasi IT Inventory' + (isAdmin ? ' (KLAS)' : ''),
            'inv_bom': 'Master BOM',
            'inv_tarif': 'Master Tarif BM dari Kode HS',
            'inv_jaminan': 'Master Jaminan',
            'inv_stock_transfer': 'Mutasi Produksi',
            'inv_warehouse_in': 'Warehouse - Incoming',
            'inv_warehouse_delivery': 'Warehouse - Delivery',
            'inv_transfer_bc27': 'Warehouse - Transfer BC27 OUT-->IN',
            'inv_stok_opname': 'Stok Opname',
            'inv_scan27': 'Import BC 2.7 IN dan Post ke IT Inv',
            'inv_unposting_appv': 'Approval Revisi / UnPosting',
            'inv_stok_awal': 'Set Stok Awal Tahun',
            'inv_adjust_spp': 'Adjust SPP'
        };

        function getTabUrl(tabId, cmpId, extraParam) {
            const adminParam = isAdmin ? '&is_admin=1' : '&is_admin=0';
            const curTheme = document.body.getAttribute('data-theme') || localStorage.getItem('ceisa_theme') || 'light';
            const themeParam = `&theme=${encodeURIComponent(curTheme)}`;
            if (tabId === 'daftar_dokumen') return `/daftar_dokumen.php?embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'transfer') return `/transfer.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'editor') {
                const effectiveAju = (extraParam !== undefined && extraParam !== null) ? extraParam : currentAjuParam;
                const ajuParam = (effectiveAju && String(effectiveAju).trim() !== '') 
                    ? `&nomor_aju=${encodeURIComponent(String(effectiveAju).trim())}` 
                    : '';
                return `/editor.php?cmp_id=${encodeURIComponent(cmpId)}${ajuParam}&embedded=1${adminParam}${themeParam}`;
            }
            if (tabId === 'import') return `/import_excel.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'load_erp_odoo') return `/load_erp_odoo.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'winline_sales_invoice') return `/winline_erp.php?type=sales_invoice&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'winline_dn_transfer') return `/winline_erp.php?type=dn_transfer&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'winline_purchase_order') return `/winline_erp.php?type=purchase_order&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'manifest_ceisa') return `/manifest_ceisa.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'manifest') {
                const effectiveAju = (extraParam !== undefined && extraParam !== null) ? extraParam : currentAjuParam;
                const ajuParam = (effectiveAju && String(effectiveAju).trim() !== '') 
                    ? `&nomor_aju=${encodeURIComponent(String(effectiveAju).trim())}` 
                    : '';
                return `/manifest.php?cmp_id=${encodeURIComponent(cmpId)}${ajuParam}&embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            }
            if (tabId === 'bc11_monitoring') {
                const effectiveAju = (extraParam !== undefined && extraParam !== null) ? extraParam : currentAjuParam;
                const ajuParam = (effectiveAju && String(effectiveAju).trim() !== '') 
                    ? `&nomor_aju=${encodeURIComponent(String(effectiveAju).trim())}` 
                    : '';
                return `/bc11_monitoring.php?cmp_id=${encodeURIComponent(cmpId)}${ajuParam}&embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            }
            if (tabId === 'dokap') return `/upload_dokap.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'tracking') return `/statusaju.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'user_management') return `/user_management.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId.startsWith('it_inventory')) {
                if (currentIsInv === 0 && !isBcRole) return `/daftar_dokumen.php?embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
                if (tabId === 'it_inventory_home') {
                    return `/it_inventory.php?tab=home&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
                }
                if (tabId === 'it_inventory_1d_rm') {
                    return `/it_inventory.php?tab=1d&klas=Raw+Materials&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
                }
                if (tabId === 'it_inventory_1d_fg') {
                    return `/it_inventory.php?tab=1d&klas=Finished+Goods&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
                }
                if (tabId === 'it_inventory_1d_cg') {
                    return `/it_inventory.php?tab=1d&klas=Capital+Goods&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
                }
                if (tabId === 'it_inventory_1d_w') {
                    return `/it_inventory.php?tab=1d&klas=Wastes&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
                }
                const subtab = tabId.replace('it_inventory_', '');
                return `/it_inventory.php?tab=${encodeURIComponent(subtab)}&cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            }
            if (tabId === 'reff') return `/reff.php?embedded=1${adminParam}${themeParam}`;
            if (tabId === 'company') return isAdmin ? `/company.php?embedded=1${adminParam}${themeParam}&_t=${Date.now()}` : `/daftar_dokumen.php?embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'app_settings') return `/setting_aplikasi.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'docs') return `/docs.php?embedded=1${adminParam}${themeParam}`;
            if (tabId === 'db') return isAdmin ? `/db.php?embedded=1${adminParam}${themeParam}` : `/daftar_dokumen.php?embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            if (tabId === 'version_log') return `/version_log.php?embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
            
            // Inventory Modul URLs
            if (tabId === 'inv_material') return `/material.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_koreksi_klas') return `/klas_koreksi.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_bom') return `/bom.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_tarif') return `/tarif.php?embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_jaminan') return `/jaminan.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_stock_transfer') return `/stock_transfer.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_warehouse_in') return `/warehouse_incoming.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_warehouse_delivery') return `/warehouse_delivery.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_transfer_bc27') return `/transfer_bc27.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_stok_opname') return `/stok_opname.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_scan27') return `/scan27.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_unposting_appv') return `/unposting_approval.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_stok_awal') return `/stok_awal_tahun.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;
            if (tabId === 'inv_adjust_spp') return `/adjust_spp.php?cmp_id=${encodeURIComponent(cmpId)}&embedded=1${adminParam}${themeParam}`;

            return `/daftar_dokumen.php?embedded=1${adminParam}${themeParam}&_t=${Date.now()}`;
        }

        function switchMainTab(tabId, extraParam) {
            if (isBcRole && !tabId.startsWith('it_inventory')) {
                showToast('⚠️ Hak akses Anda dibatasi khusus untuk Laporan IT Inventory.');
                tabId = 'it_inventory_1a';
            }
            if (tabId.startsWith('it_inventory') && currentIsInv === 0 && !isBcRole) {
                showToast('⚠️ Modul IT Inventory sedang Non-Aktif untuk perusahaan ini.');
                return;
            }
            currentTab = tabId;
            if (extraParam !== undefined && extraParam !== null && String(extraParam).trim() !== '') {
                currentAjuParam = String(extraParam).trim();
            } else {
                currentAjuParam = '';
            }
            document.querySelectorAll('.kt-menu__item:not(.kt-menu__item--submenu)').forEach(b => b.classList.remove('active'));
            const activeNav = document.getElementById('nav-item-' + tabId);
            if (activeNav) activeNav.classList.add('active');

            // Pastikan sub modul IT Inventory terbuka jika tab IT Inventory aktif
            const itInvParent = document.getElementById('nav-item-it_inventory_parent');
            if (itInvParent && tabId.startsWith('it_inventory')) {
                itInvParent.classList.add('kt-menu__item--open');
            }

            // Pastikan sub modul Transaksi Warehouse terbuka jika tab Warehouse aktif
            const whParent = document.getElementById('nav-item-warehouse_parent');
            if (whParent && (tabId === 'inv_warehouse_in' || tabId === 'inv_warehouse_delivery' || tabId === 'inv_transfer_bc27')) {
                whParent.classList.add('kt-menu__item--open');
            }

            // Pastikan sub modul Data Master terbuka jika tab Data Master aktif
            const masterParent = document.getElementById('nav-item-master_parent');
            if (masterParent && (tabId === 'inv_material' || tabId === 'inv_koreksi_klas' || tabId === 'inv_bom' || tabId === 'inv_jaminan')) {
                masterParent.classList.add('kt-menu__item--open');
            }

            // Pastikan sub modul Admin Inventory terbuka jika tab Admin Inventory aktif
            const adminInvParent = document.getElementById('nav-item-admin_inv_parent');
            if (adminInvParent && (tabId === 'inv_unposting_appv' || tabId === 'inv_stok_awal' || tabId === 'inv_adjust_spp')) {
                adminInvParent.classList.add('kt-menu__item--open');
            }

            const bcEl = document.getElementById('topbarBreadcrumb');
            if (bcEl && breadcrumbTitles[tabId]) {
                bcEl.innerText = breadcrumbTitles[tabId];
            }

            // Background Beacon: Catat riwayat menu yang dibuka (non-blocking 0 ms)
            try {
                if (window.lastLoggedMenuTab !== tabId) {
                    window.lastLoggedMenuTab = tabId;
                    const menuTitle = breadcrumbTitles[tabId] || tabId;
                    if (navigator.sendBeacon) {
                        const fd = new FormData();
                        fd.append('action', 'log_menu');
                        fd.append('menu_id', tabId);
                        fd.append('menu_title', menuTitle);
                        navigator.sendBeacon('/index.php', fd);
                    } else {
                        fetch('/index.php?action=log_menu', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=log_menu&menu_id=${encodeURIComponent(tabId)}&menu_title=${encodeURIComponent(menuTitle)}`,
                            keepalive: true
                        }).catch(() => {});
                    }
                }
            } catch(e) {}

            const wrapper = document.getElementById('iframeWrapper');
            if (!wrapper) return;

            // Sembunyikan semua iframe tab yang aktif
            Object.values(window.tabIframes || {}).forEach(ifrm => {
                if (ifrm && ifrm.style) ifrm.style.display = 'none';
            });

            if (tabId === 'editor') {
                if (window.tabIframes['editor']) {
                    const targetUrl = getTabUrl('editor', currentCmpId, currentAjuParam);
                    if (window.tabIframes['editor'].getAttribute('data-aju') !== currentAjuParam) {
                        showIframeLoader(breadcrumbTitles['editor'] || 'Form Editor Dokumen');
                        window.tabIframes['editor'].src = targetUrl;
                        window.tabIframes['editor'].setAttribute('data-aju', currentAjuParam);
                    }
                    window.tabIframes['editor'].style.display = 'block';
                    syncIframeState(window.tabIframes['editor']);
                } else {
                    showIframeLoader(breadcrumbTitles['editor'] || 'Form Editor Dokumen');
                    const ifrm = document.createElement('iframe');
                    ifrm.className = 'iframe-container';
                    ifrm.style.width = '100%';
                    ifrm.style.height = '100%';
                    ifrm.style.border = 'none';
                    ifrm.style.display = 'block';
                    ifrm.setAttribute('data-tab', 'editor');
                    ifrm.setAttribute('data-aju', currentAjuParam);
                    ifrm.src = getTabUrl('editor', currentCmpId, currentAjuParam);
                    attachIframeSync(ifrm);
                    wrapper.appendChild(ifrm);
                    window.tabIframes['editor'] = ifrm;
                }
            } else if (tabId === 'transfer') {
                if (window.tabIframes['transfer']) {
                    window.tabIframes['transfer'].style.display = 'block';
                    syncIframeState(window.tabIframes['transfer']);
                    try {
                        window.tabIframes['transfer'].contentWindow.postMessage({
                            type: 'SELECT_AJU',
                            aju: currentAjuParam || ''
                        }, '*');
                    } catch(e) {}
                } else {
                    showIframeLoader(breadcrumbTitles['transfer'] || 'Data Host to Host');
                    const ifrm = document.createElement('iframe');
                    ifrm.className = 'iframe-container';
                    ifrm.style.width = '100%';
                    ifrm.style.height = '100%';
                    ifrm.style.border = 'none';
                    ifrm.style.display = 'block';
                    ifrm.setAttribute('data-tab', 'transfer');
                    ifrm.src = getTabUrl('transfer', currentCmpId, currentAjuParam);
                    attachIframeSync(ifrm);
                    wrapper.appendChild(ifrm);
                    window.tabIframes['transfer'] = ifrm;
                }
            } else if (tabId === 'manifest') {
                if (window.tabIframes['manifest']) {
                    const targetUrl = getTabUrl('manifest', currentCmpId, currentAjuParam);
                    if (currentAjuParam && window.tabIframes['manifest'].getAttribute('data-aju') !== currentAjuParam) {
                        showIframeLoader(breadcrumbTitles['manifest'] || 'Manifest Dokumen');
                        window.tabIframes['manifest'].src = targetUrl;
                        window.tabIframes['manifest'].setAttribute('data-aju', currentAjuParam);
                    }
                    window.tabIframes['manifest'].style.display = 'block';
                    syncIframeState(window.tabIframes['manifest']);
                    try {
                        if (currentAjuParam && window.tabIframes['manifest'].contentWindow && typeof window.tabIframes['manifest'].contentWindow.editManifest === 'function') {
                            window.tabIframes['manifest'].contentWindow.editManifest(currentAjuParam);
                        } else if (window.tabIframes['manifest'].contentWindow && typeof window.tabIframes['manifest'].contentWindow.loadManifestList === 'function') {
                            window.tabIframes['manifest'].contentWindow.loadManifestList();
                        }
                    } catch(e) {}
                } else {
                    showIframeLoader(breadcrumbTitles['manifest'] || 'Manifest Dokumen');
                    const ifrm = document.createElement('iframe');
                    ifrm.className = 'iframe-container';
                    ifrm.style.width = '100%';
                    ifrm.style.height = '100%';
                    ifrm.style.border = 'none';
                    ifrm.style.display = 'block';
                    ifrm.setAttribute('data-tab', 'manifest');
                    ifrm.setAttribute('data-aju', currentAjuParam);
                    ifrm.src = getTabUrl('manifest', currentCmpId, currentAjuParam);
                    attachIframeSync(ifrm);
                    wrapper.appendChild(ifrm);
                    window.tabIframes['manifest'] = ifrm;
                }
            } else if (tabId === 'manifest_ceisa') {
                if (window.tabIframes['manifest_ceisa']) {
                    window.tabIframes['manifest_ceisa'].style.display = 'block';
                    syncIframeState(window.tabIframes['manifest_ceisa']);
                    try {
                        if (window.tabIframes['manifest_ceisa'].contentWindow && typeof window.tabIframes['manifest_ceisa'].contentWindow.loadPortalData === 'function') {
                            window.tabIframes['manifest_ceisa'].contentWindow.loadPortalData();
                        }
                    } catch(e) {}
                } else {
                    showIframeLoader(breadcrumbTitles['manifest_ceisa'] || 'Manifest CEISA 4.0');
                    const ifrm = document.createElement('iframe');
                    ifrm.className = 'iframe-container';
                    ifrm.style.width = '100%';
                    ifrm.style.height = '100%';
                    ifrm.style.border = 'none';
                    ifrm.style.display = 'block';
                    ifrm.setAttribute('data-tab', 'manifest_ceisa');
                    ifrm.src = getTabUrl('manifest_ceisa', currentCmpId, currentAjuParam);
                    attachIframeSync(ifrm);
                    wrapper.appendChild(ifrm);
                    window.tabIframes['manifest_ceisa'] = ifrm;
                }
            } else {
                if (window.tabIframes[tabId]) {
                    window.tabIframes[tabId].style.display = 'block';
                    syncIframeState(window.tabIframes[tabId]);
                    try {
                        if (window.tabIframes[tabId].contentWindow && typeof window.tabIframes[tabId].contentWindow.loadData === 'function') {
                            window.tabIframes[tabId].contentWindow.loadData();
                        }
                    } catch(e) {}
                } else {
                    showIframeLoader(breadcrumbTitles[tabId] || 'Modul');
                    const ifrm = document.createElement('iframe');
                    ifrm.className = 'iframe-container';
                    ifrm.style.width = '100%';
                    ifrm.style.height = '100%';
                    ifrm.style.border = 'none';
                    ifrm.style.display = 'block';
                    ifrm.setAttribute('data-tab', tabId);
                    ifrm.src = getTabUrl(tabId, currentCmpId, currentAjuParam);
                    attachIframeSync(ifrm);
                    wrapper.appendChild(ifrm);
                    window.tabIframes[tabId] = ifrm;
                }
            }

            // Sinkronkan URL browser agar mencerminkan tab aktif & mempertahankan parameter cmp
            if (window.history && window.history.replaceState) {
                try {
                    const u = new URL(window.location.href);
                    u.searchParams.set('tab', tabId);
                    if (currentAjuParam) {
                        u.searchParams.set('nomor_aju', currentAjuParam);
                    } else {
                        u.searchParams.delete('nomor_aju');
                    }
                    window.history.replaceState({}, '', u.toString());
                } catch(e) {}
            }
        }

        function toggleSubmenu(parentId) {
            const el = document.getElementById(parentId);
            if (!el) return;
            el.classList.toggle('kt-menu__item--open');
        }

        function toggleSidebarPin() {
            const body = document.body;
            const isCurrentlyPinned = body.classList.contains('sidebar-pinned');

            if (isCurrentlyPinned) {
                body.classList.remove('sidebar-pinned');
                body.classList.add('sidebar-unpinned');
                localStorage.setItem('ceisa_sidebar_pinned', 'false');
                showToast('Sidebar diatur ke mode Autohide');
            } else {
                body.classList.remove('sidebar-unpinned');
                body.classList.add('sidebar-pinned');
                localStorage.setItem('ceisa_sidebar_pinned', 'true');
                showToast('Sidebar terkunci (Pinned)');
            }
        }

        function loadSidebarPinState() {
            const savedPin = localStorage.getItem('ceisa_sidebar_pinned');
            const body = document.body;
            if (savedPin === 'false') {
                body.classList.remove('sidebar-pinned');
                body.classList.add('sidebar-unpinned');
            } else {
                body.classList.remove('sidebar-unpinned');
                body.classList.add('sidebar-pinned');
            }
        }

        function filterSidebarMenu() {
            const searchEl = document.getElementById('menuSearchInput');
            if (!searchEl) return;
            const q = searchEl.value.toLowerCase().trim();
            const groups = document.querySelectorAll('.kt-aside-menu .menu-group');

            groups.forEach(grp => {
                if (grp.id === 'menu-group-inventory-modul' && currentIsInv === 0 && !isBcRole) {
                    grp.style.display = 'none';
                    return;
                }
                if (grp.id === 'menu-group-referensi') {
                    const roles = (currentCompanyRoles || 'tpb').toLowerCase().split(',').map(s => s.trim());
                    const isManOnly = (roles.includes('manifest') && !roles.includes('pib') && !roles.includes('peb') && !roles.includes('tpb') && !roles.includes('ppkek'));
                    if (isManOnly) {
                        grp.style.display = 'none';
                        return;
                    }
                }
                let anyVisibleInGroup = false;
                const items = grp.querySelectorAll('.kt-menu__item');
                items.forEach(item => {
                    if (item.classList.contains('kt-menu__item--submenu')) return;
                    const label = (item.querySelector('.kt-menu__link-text')?.innerText || '').toLowerCase();
                    if (!q || label.includes(q)) {
                        item.style.display = 'block';
                        anyVisibleInGroup = true;
                    } else {
                        item.style.display = 'none';
                    }
                });

                // Cek submenu (IT Inventory)
                const submenus = grp.querySelectorAll('.kt-menu__item--submenu');
                submenus.forEach(sub => {
                    const subLabel = (sub.querySelector(':scope > .kt-menu__link .kt-menu__link-text')?.innerText || '').toLowerCase();
                    const subItems = sub.querySelectorAll('.kt-menu__sub .kt-menu__item');
                    let anySubVisible = false;

                    if (q && subLabel.includes(q)) {
                        anySubVisible = true;
                        sub.style.display = 'block';
                        sub.classList.add('kt-menu__item--open');
                        subItems.forEach(si => si.style.display = 'block');
                        anyVisibleInGroup = true;
                    } else {
                        subItems.forEach(si => {
                            if (si.style.display === 'block') anySubVisible = true;
                        });
                        if (anySubVisible) {
                            sub.style.display = 'block';
                            if (q) sub.classList.add('kt-menu__item--open');
                            anyVisibleInGroup = true;
                        } else {
                            if (q) {
                                sub.style.display = 'none';
                            } else {
                                sub.style.display = 'block';
                            }
                        }
                    }
                });

                const grpTitle = grp.querySelector('.kt-menu__section');
                if (grpTitle) {
                    grpTitle.style.display = anyVisibleInGroup ? 'block' : 'none';
                }
                grp.style.display = anyVisibleInGroup ? 'block' : 'none';
            });
        }

        function setTheme(theme) {
            if (theme !== 'light' && theme !== 'dark') theme = 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.body.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
            document.body.style.colorScheme = theme;
            localStorage.setItem('ceisa_theme', theme);
            document.querySelectorAll('.theme-btn').forEach(b => {
                b.classList.toggle('active', b.getAttribute('data-theme') === theme);
            });

            Object.values(window.tabIframes || {}).forEach(ifrm => {
                if (ifrm && ifrm.contentWindow) {
                    try {
                        ifrm.contentWindow.postMessage({ type: 'SET_THEME', theme: theme }, '*');
                    } catch(e) {}
                }
            });
        }

        function toggleTheme() {
            const cur = document.body.getAttribute('data-theme') || 'light';
            setTheme(cur === 'light' ? 'dark' : 'light');
        }

        function setIconStyle(style) {
            const valid = (style === 'monochrome') ? 'monochrome' : 'colored';
            document.documentElement.setAttribute('data-icon-style', valid);
            if (document.body) document.body.setAttribute('data-icon-style', valid);
            localStorage.setItem('ceisa_icon_style', valid);
            document.querySelectorAll('.icon-style-btn').forEach(b => {
                b.classList.toggle('active', b.getAttribute('data-icon-style') === valid);
            });
        }

        function loadSavedIconStyle() {
            let saved = localStorage.getItem('ceisa_icon_style') || '<?= htmlspecialchars($appSettings['sidebar_icon_style'] ?? 'colored') ?>';
            setIconStyle(saved);
        }

        function loadSavedTheme() {
            let saved = localStorage.getItem('ceisa_theme');
            if (saved !== 'light' && saved !== 'dark') saved = 'light';
            setTheme(saved);
        }

        // Live countdown timer (01:54:14)
        let tokenSeconds = 6854;
        function updateTokenTimer() {
            if (tokenSeconds <= 0) tokenSeconds = 7200;
            tokenSeconds--;
            const hrs = String(Math.floor(tokenSeconds / 3600)).padStart(2, '0');
            const mins = String(Math.floor((tokenSeconds % 3600) / 60)).padStart(2, '0');
            const secs = String(tokenSeconds % 60).padStart(2, '0');
            const timerEl = document.getElementById('tokenTimer');
            if (timerEl) timerEl.innerText = `${hrs}:${mins}:${secs}`;
        }
        setInterval(updateTokenTimer, 1000);

        function initLoginFormProtection() {
            let userInteracted = false;
            const u = document.getElementById('loginUsername');
            const p = document.getElementById('loginPassword');
            if (!u && !p) return;

            function wipeInputs() {
                if (userInteracted) return;
                if (u && !userInteracted) {
                    u.value = '';
                    u.defaultValue = '';
                }
                if (p && !userInteracted) {
                    p.value = '';
                    p.defaultValue = '';
                }
            }

            // Eksekusi segera dan berkala untuk membersihkan injeksi autofill otomatis browser
            wipeInputs();
            setTimeout(wipeInputs, 40);
            setTimeout(wipeInputs, 100);
            setTimeout(wipeInputs, 200);
            setTimeout(wipeInputs, 400);
            setTimeout(wipeInputs, 800);
            setTimeout(wipeInputs, 1200);

            // Lepaskan readonly setelah jeda inisialisasi agar input responsif untuk diketik manual
            setTimeout(function() {
                if (u) {
                    u.removeAttribute('readonly');
                    if (!userInteracted) u.value = '';
                }
                if (p) {
                    p.removeAttribute('readonly');
                    if (!userInteracted) p.value = '';
                }
            }, 120);

            [u, p].forEach(function(el) {
                if (!el) return;
                el.addEventListener('keydown', function() { userInteracted = true; });
                el.addEventListener('mousedown', function() { el.removeAttribute('readonly'); });
                el.addEventListener('focus', function() { el.removeAttribute('readonly'); });
                el.addEventListener('input', function() {
                    // Jika terisi otomatis oleh browser sebelum interaksi keyboard pengguna, kosongkan kembali
                    if (!userInteracted && performance.now() < 1200) {
                        el.value = '';
                    }
                });
            });
        }
        document.addEventListener('DOMContentLoaded', initLoginFormProtection);

        function onLoginCmpChange(val) {
            const grp = document.getElementById('groupLoginUsername');
            const inp = document.getElementById('loginUsername');
            if (!grp) return;
            if (val === 'admin' || !val) {
                grp.style.display = 'none';
                if (inp) {
                    inp.value = '';
                    inp.required = false;
                }
            } else {
                grp.style.display = 'block';
                if (inp) {
                    inp.required = true;
                    inp.focus();
                }
            }
        }

        async function submitLogin(e) {
            e.preventDefault();
            const cmp = document.getElementById('loginCmp').value;
            const username = document.getElementById('loginUsername')?.value.trim() || '';
            const pass = document.getElementById('loginPassword').value;
            const msg = document.getElementById('loginMsg');
            const btn = document.getElementById('btnLogin');

            if (!cmp || !pass) return;

            // Validasi Username Wajib untuk Login Akun Perusahaan ATAU Login Administrator
            const grpUser = document.getElementById('groupLoginUsername');
            const isUserRequired = grpUser && grpUser.style.display !== 'none';
            if (isUserRequired && !username) {
                msg.style.display = 'block';
                msg.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i> ' + ((cmp.toLowerCase() === 'admin') ? 'Nama Pengguna (Username) Administrator wajib diisi.' : 'Nama Pengguna (Username) wajib diisi.');
                document.getElementById('loginUsername')?.focus();
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <span>Memverifikasi...</span>';
            msg.style.display = 'none';

            const fd = new FormData();
            fd.append('action', 'login');
            fd.append('cmp_id', cmp);
            fd.append('username', username);
            fd.append('password', pass);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> <span>Berhasil! Memuat Portal...</span>';
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket" style="font-size:13px;"></i> <span>MASUK KE SISTEM</span>';
                    msg.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i> ' + escapeHtml(json.message);
                    msg.style.display = 'block';
                }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket" style="font-size:13px;"></i> <span>MASUK KE SISTEM</span>';
                msg.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i> Koneksi gagal: ' + escapeHtml(err.message);
                msg.style.display = 'block';
            }
        }

        function openCompanyModal() {
            if (!isAdmin && !canSwitchCompany) return;
            const modal = document.getElementById('modalCompanySwitcher');
            if (!modal) return;
            modal.style.display = 'flex';
            const searchInp = document.getElementById('companySearchInput');
            if (searchInp) {
                searchInp.value = '';
                setTimeout(() => searchInp.focus(), 100);
            }
            renderCompanyList(allCompanies);
        }

        function closeCompanyModal() {
            const modal = document.getElementById('modalCompanySwitcher');
            if (modal) modal.style.display = 'none';
        }

        function renderCompanyList(list) {
            const container = document.getElementById('companyListContainer');
            if (!container) return;
            container.innerHTML = '';

            const countLabel = document.getElementById('companyCountLabel');
            if (countLabel) {
                countLabel.innerText = `Menampilkan ${list.length} dari ${allCompanies.length} perusahaan terdaftar`;
            }

            if (list.length === 0) {
                container.innerHTML = `<div style="text-align:center; padding:24px; color:#8c8c8c; font-size:12px;">Tidak ada perusahaan yang cocok dengan pencarian.</div>`;
                return;
            }

            list.forEach(c => {
                const isActive = c.cmp_id === currentCmpId;
                const div = document.createElement('div');
                div.className = 'company-card-item' + (isActive ? ' is-active-company' : '');
                div.onclick = function() { selectCompany(c.cmp_id, c.cmp_name); };

                div.innerHTML = `
                    <div style="display:flex; flex-direction:column; gap:2px;">
                        <div style="font-weight:600; font-size:12px; color:${isActive ? '#1890ff' : 'var(--text-main)'}; display:flex; align-items:center; gap:6px;">
                            <span>🏢</span>
                            <span>${escapeHtml(c.cmp_name)}</span>
                            ${c.card_color ? `
                                <span style="display:inline-block; width:10px; height:10px; border-radius:3px; background:${escapeHtml(c.card_color)}; border:1px solid rgba(0,0,0,0.25); flex-shrink:0;" title="Warna Card: ${escapeHtml(c.card_color)}"></span>
                            ` : ''}
                            ${isActive ? '<span style="font-size:9.5px; font-weight:700; color:#1890ff; background:#fff; border:1px solid #1890ff; padding:1px 5px; border-radius:3px;">✓ AKTIF</span>' : ''}
                        </div>
                        <div style="font-size:10.5px; color:#8c8c8c; display:flex; gap:10px;">
                            <span>ID: <code style="font-family:monospace;">${escapeHtml(c.cmp_id)}</code></span>
                            <span>NPWP: <code style="font-family:monospace;">${escapeHtml(c.npwp || '-')}</code></span>
                        </div>
                    </div>
                    <div>
                        ${isActive 
                            ? '<button class="ant-btn ant-btn-primary" disabled style="opacity:0.85; padding:0 8px; height:24px; font-size:11px;">Sedang Aktif</button>' 
                            : '<button class="ant-btn" style="padding:0 8px; height:24px; font-size:11px;">Pilih ➔</button>'}
                    </div>
                `;
                container.appendChild(div);
            });
        }

        function applySidebarCompanyCardColor(color) {
            const card = document.getElementById('sidebarCompanyCard');
            if (!card) return;

            const clean = (color || '').trim();
            if (!clean || clean === 'default') {
                card.removeAttribute('style');
                card.removeAttribute('data-custom-color');
                card.style.removeProperty('--card-title-color');
                card.style.removeProperty('--card-sub-color');
                card.style.removeProperty('--card-btn-bg');
                card.style.removeProperty('--card-btn-color');
                card.style.removeProperty('--card-btn-border');
                return;
            }

            let isDark = true;
            if (!clean.includes('gradient') && clean.startsWith('#')) {
                let hex = clean.slice(1);
                if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
                if (hex.length === 6) {
                    const r = parseInt(hex.substring(0, 2), 16);
                    const g = parseInt(hex.substring(2, 4), 16);
                    const b = parseInt(hex.substring(4, 6), 16);
                    const lum = 0.299 * r + 0.587 * g + 0.114 * b;
                    isDark = (lum < 165);
                }
            }

            card.setAttribute('data-custom-color', 'true');
            card.style.setProperty('background', clean, 'important');
            if (isDark) {
                card.style.setProperty('border-color', 'rgba(255, 255, 255, 0.2)', 'important');
                card.style.setProperty('color', '#ffffff', 'important');
                card.style.setProperty('--card-title-color', '#ffffff');
                card.style.setProperty('--card-sub-color', 'rgba(255, 255, 255, 0.75)');
                card.style.setProperty('--card-btn-bg', 'rgba(255, 255, 255, 0.2)');
                card.style.setProperty('--card-btn-color', '#ffffff');
                card.style.setProperty('--card-btn-border', 'rgba(255, 255, 255, 0.35)');
            } else {
                card.style.setProperty('border-color', 'rgba(0, 0, 0, 0.12)', 'important');
                card.style.setProperty('color', '#1f1f1f', 'important');
                card.style.setProperty('--card-title-color', '#1f1f1f');
                card.style.setProperty('--card-sub-color', '#595959');
                card.style.setProperty('--card-btn-bg', 'rgba(0, 0, 0, 0.06)');
                card.style.setProperty('--card-btn-color', '#1890ff');
                card.style.setProperty('--card-btn-border', 'rgba(0, 0, 0, 0.1)');
            }
        }

        function filterCompanySearch() {
            const query = document.getElementById('companySearchInput').value.toLowerCase().trim();
            if (!query) {
                renderCompanyList(allCompanies);
                return;
            }

            const filtered = allCompanies.filter(c => {
                const nameMatch = (c.cmp_name || '').toLowerCase().includes(query);
                const idMatch   = (c.cmp_id || '').toLowerCase().includes(query);
                const npwpMatch = (c.npwp || '').toLowerCase().includes(query);
                return nameMatch || idMatch || npwpMatch;
            });

            renderCompanyList(filtered);
        }

        async function selectCompany(cmpId, cmpName) {
            if (cmpId === currentCmpId) {
                closeCompanyModal();
                return;
            }

            const fd = new FormData();
            fd.append('action', 'switch_company');
            fd.append('cmp_id', cmpId);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    currentCmpId = json.cmp_id;
                    applyRoleMenuVisibility(json.company_roles, json.erp_name, json.is_inv);
                    if (json.card_color !== undefined) {
                        applySidebarCompanyCardColor(json.card_color);
                    }

                    const sbName = document.getElementById('sidebarCompanyName');
                    if (sbName) {
                        sbName.innerText = json.cmp_name;
                        sbName.title = json.cmp_name;
                    }
                    const sbSub = document.getElementById('sidebarCompanySub');
                    if (sbSub) {
                        sbSub.innerText = '[' + json.cmp_id + ']';
                    }

                    const sbLogoBox = document.getElementById('sidebarCompanyLogoBox');
                    if (sbLogoBox) {
                        if (json.logo) {
                            sbLogoBox.innerHTML = `<img src="${escapeHtml(json.logo)}" alt="Logo" style="width:22px; height:22px; object-fit:contain; border-radius:4px; background:#fff; padding:1px; box-shadow:0 1px 3px rgba(0,0,0,0.15);">`;
                        } else {
                            sbLogoBox.innerHTML = `🏢`;
                        }
                    }
                    const topBadge = document.getElementById('topbarUserBadge');
                    if (topBadge && !isBcRole && json.logo) {
                        topBadge.innerHTML = `<img src="${escapeHtml(json.logo)}" alt="Avatar" style="width:100%; height:100%; object-fit:contain; border-radius:50%; background:#fff;">`;
                    }

                    const wrapper = document.getElementById('iframeWrapper');
                    if (wrapper) {
                        wrapper.innerHTML = `<iframe id="mainFrame" class="iframe-container" data-tab="${escapeHtml(currentTab)}" src="${getTabUrl(currentTab, currentCmpId)}" style="width:100%; height:100%; border:none; display:block;"></iframe>`;
                        const newMain = document.getElementById('mainFrame');
                        attachIframeSync(newMain);
                        window.tabIframes = { [currentTab]: newMain };
                    }

                    closeCompanyModal();
                    showToast(`✓ Beralih ke: [${json.cmp_id}] ${json.cmp_name}`);
                    checkAlertNotificationBadge();
                } else {
                    showError('Gagal beralih perusahaan: ' + json.message);
                }
            } catch (err) {
                showError('Koneksi gagal: ' + err.message);
            }
        }

        function togglePassVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const isPass = input.type === 'password';
            input.type = isPass ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = isPass ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
            } else {
                btn.innerText = isPass ? '🙈' : '👁️';
            }
        }

        function openModalPass() {
            document.getElementById('modalPassword').style.display = 'flex';
            const msg = document.getElementById('passMsg');
            if (msg) {
                msg.style.display = 'none';
                msg.innerText = '';
            }
            document.getElementById('passOld').value = '';
            document.getElementById('passNew').value = '';
            document.getElementById('passConfirm').value = '';
            document.querySelectorAll('#modalPassword .btn-toggle-pass').forEach(b => b.innerText = '👁️');
            document.querySelectorAll('#modalPassword input[type="text"]').forEach(i => i.type = 'password');
        }

        function closeModalPass() {
            document.getElementById('modalPassword').style.display = 'none';
        }

        async function submitChangePass(e) {
            e.preventDefault();
            const oldP = document.getElementById('passOld').value;
            const newP = document.getElementById('passNew').value;
            const confP = document.getElementById('passConfirm').value;
            const msg = document.getElementById('passMsg');
            const btn = document.getElementById('btnSavePass');

            if (newP !== confP) {
                msg.style.color = '#ff4d4f';
                msg.style.background = 'rgba(255, 77, 79, 0.08)';
                msg.style.border = '1px solid rgba(255, 77, 79, 0.2)';
                msg.innerText = '⚠️ Password baru dan konfirmasi tidak cocok!';
                msg.style.display = 'block';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '⏳ Menyimpan...';

            const fd = new FormData();
            fd.append('action', 'change_password');
            fd.append('old_password', oldP);
            fd.append('new_password', newP);

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = '<span>💾</span> Simpan Password';

                if (json.status === 'OK') {
                    showToast('✓ ' + json.message);
                    closeModalPass();
                } else {
                    msg.style.color = '#ff4d4f';
                    msg.style.background = 'rgba(255, 77, 79, 0.08)';
                    msg.style.border = '1px solid rgba(255, 77, 79, 0.2)';
                    msg.innerText = '⚠️ ' + json.message;
                    msg.style.display = 'block';
                }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = '<span>💾</span> Simpan Password';
                msg.style.color = '#ff4d4f';
                msg.style.background = 'rgba(255, 77, 79, 0.08)';
                msg.style.border = '1px solid rgba(255, 77, 79, 0.2)';
                msg.innerText = '⚠️ Error: ' + err.message;
                msg.style.display = 'block';
            }
        }

        // =============================================================
        // MFA TOKEN GENERATOR (GOOGLE AUTHENTICATOR)
        // =============================================================
        let mfaModalInterval = null;
        let mfaRemainingSeconds = 30;
        let isMfaFetching = false;
        let activeMfaCmpId = currentCmpId;

        function openMfaModal() {
            activeMfaCmpId = currentCmpId;
            const selectEl = document.getElementById('mfaModalCompany');
            if (selectEl) selectEl.value = activeMfaCmpId;

            document.getElementById('modalMfa').style.display = 'flex';
            fetchMfaModalOtp(true);
            startMfaModalTimer();
        }

        function closeMfaModal() {
            document.getElementById('modalMfa').style.display = 'none';
            if (mfaModalInterval) {
                clearInterval(mfaModalInterval);
                mfaModalInterval = null;
            }
        }

        function changeMfaModalCompany(cmpId) {
            activeMfaCmpId = cmpId;
            fetchMfaModalOtp(true);
        }

        async function fetchMfaModalOtp(manual = false) {
            if (isMfaFetching) return;
            isMfaFetching = true;

            const codeEl = document.getElementById('mfaModalCode');
            if (manual && codeEl) codeEl.style.opacity = '0.4';

            try {
                const res = await fetch(`api_mfa.php?cmp_id=${encodeURIComponent(activeMfaCmpId)}&t=${Date.now()}`);
                const data = await res.json();

                if (codeEl) codeEl.style.opacity = '1';

                if (data.status === 'OK') {
                    if (codeEl) {
                        codeEl.innerText = data.otp;
                        codeEl.style.fontSize = '38px';
                        codeEl.style.color = '#0b82df';
                    }
                    const issuerEl = document.getElementById('mfaModalIssuer');
                    if (issuerEl) issuerEl.innerText = data.issuer || 'portal-beacukai';
                    const userEl = document.getElementById('mfaModalUsername');
                    if (userEl) userEl.innerText = data.username || 'maikelwawoh';

                    mfaRemainingSeconds = parseInt(data.remaining_seconds, 10) || 30;
                    updateMfaModalTimerDisplay();
                } else if (data.status === 'EMPTY_SECRET') {
                    if (codeEl) {
                        codeEl.innerText = 'NO SECRET';
                        codeEl.style.fontSize = '22px';
                        codeEl.style.color = '#faad14';
                    }
                    const userEl = document.getElementById('mfaModalUsername');
                    if (userEl) userEl.innerText = data.username || 'user';
                } else {
                    if (codeEl) {
                        codeEl.innerText = 'ERROR';
                        codeEl.style.fontSize = '22px';
                        codeEl.style.color = '#f5222d';
                    }
                }
            } catch (err) {
                if (codeEl) codeEl.style.opacity = '1';
                console.error('MFA fetch error:', err);
            } finally {
                isMfaFetching = false;
            }
        }

        function updateMfaModalTimerDisplay() {
            const secLabel = document.getElementById('mfaModalSeconds');
            const progress = document.getElementById('mfaModalProgress');
            if (!secLabel || !progress) return;

            secLabel.innerText = `${mfaRemainingSeconds}s`;
            const pct = (mfaRemainingSeconds / 30) * 100;
            progress.style.width = `${pct}%`;

            progress.classList.remove('warning', 'danger');
            if (mfaRemainingSeconds <= 5) {
                progress.classList.add('danger');
            } else if (mfaRemainingSeconds <= 10) {
                progress.classList.add('warning');
            }
        }

        function startMfaModalTimer() {
            if (mfaModalInterval) clearInterval(mfaModalInterval);

            mfaModalInterval = setInterval(() => {
                mfaRemainingSeconds--;
                if (mfaRemainingSeconds <= 0) {
                    fetchMfaModalOtp();
                } else {
                    updateMfaModalTimerDisplay();
                }
            }, 1000);
        }

        function copyMfaModalOtp() {
            const codeEl = document.getElementById('mfaModalCode');
            const otp = (codeEl?.innerText || '').trim();
            if (!otp || otp === '------' || otp === 'NO SECRET' || otp === 'ERROR') return;

            const updateBtn = () => {
                const btn = document.querySelector('#modalMfa .ant-btn-primary');
                if (btn) {
                    const orig = btn.innerHTML;
                    btn.innerHTML = '✓ Tersalin!';
                    setTimeout(() => { btn.innerHTML = orig; }, 1500);
                }
            };

            navigator.clipboard.writeText(otp).then(() => {
                showToast(`✓ Kode OTP ${otp} berhasil disalin!`);
                updateBtn();
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = otp;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                showToast(`✓ Kode OTP ${otp} berhasil disalin!`);
                updateBtn();
            });
        }

        function openMfaPopup() {
            const cmp = activeMfaCmpId || currentCmpId;
            const adminParam = isAdmin ? '&is_admin=1' : '&is_admin=0';
            const w = window.open(`mfa.php?cmp_id=${encodeURIComponent(cmp)}${adminParam}`, 'mfa_popup', 'width=380,height=370,resizable=yes');
            if (w) w.focus();
            closeMfaModal();
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            if (t) {
                t.innerText = msg;
                t.style.display = 'block';
                setTimeout(() => { t.style.display = 'none'; }, 2600);
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.addEventListener('click', function(e) {
            const modalCmp = document.getElementById('modalCompanySwitcher');
            if (e.target === modalCmp) closeCompanyModal();
            const modalPass = document.getElementById('modalPassword');
            if (e.target === modalPass) closeModalPass();
            const modalMfa = document.getElementById('modalMfa');
            if (e.target === modalMfa) closeMfaModal();
        });

        window.addEventListener('message', function(e) {
            if (!e.data || typeof e.data !== 'object') return;
            if (e.data.type === 'UPDATE_IS_INV') {
                if (e.data.is_inv !== undefined) {
                    setItInventoryVisibility(e.data.is_inv);
                }
            } else if (e.data.type === 'COMPANY_CHANGED') {
                if (e.data.cmp_id) currentCmpId = e.data.cmp_id;
                applyRoleMenuVisibility(e.data.company_roles, e.data.erp_name, e.data.is_inv);
                if (e.data.cmp_name) {
                    const sbName = document.getElementById('sidebarCompanyName');
                    if (sbName) {
                        sbName.innerText = e.data.cmp_name;
                        sbName.title = e.data.cmp_name;
                    }
                    const sbSub = document.getElementById('sidebarCompanySub');
                    if (sbSub && e.data.cmp_id) {
                        sbSub.innerText = '[' + e.data.cmp_id + ']';
                    }
                }
                if (e.data.logo !== undefined) {
                    const sbLogoBox = document.getElementById('sidebarCompanyLogoBox');
                    if (sbLogoBox) {
                        if (e.data.logo) {
                            sbLogoBox.innerHTML = `<img src="${escapeHtml(e.data.logo)}" alt="Logo" style="width:22px; height:22px; object-fit:contain; border-radius:4px; background:#fff; padding:1px; box-shadow:0 1px 3px rgba(0,0,0,0.15);">`;
                        } else {
                            sbLogoBox.innerHTML = `🏢`;
                        }
                    }
                    const topBadge = document.getElementById('topbarUserBadge');
                    if (topBadge && !isBcRole && e.data.logo) {
                        topBadge.innerHTML = `<img src="${escapeHtml(e.data.logo)}" alt="Avatar" style="width:100%; height:100%; object-fit:contain; border-radius:50%; background:#fff;">`;
                    }
                }
                if (e.data.card_color !== undefined) {
                    applySidebarCompanyCardColor(e.data.card_color);
                }
            } else if (e.data.type === 'CEISA_CHANGE_ICON_STYLE' || e.data.type === 'CHANGE_ICON_STYLE') {
                if (e.data.style) {
                    setIconStyle(e.data.style);
                }
            } else if (e.data.type === 'APP_SETTINGS_UPDATED') {
                const s = e.data.settings;
                if (s) {
                    if (s.sidebar_icon_style) {
                        setIconStyle(s.sidebar_icon_style);
                    }
                    // Untuk Super Admin, brand header sidebar hanya diperbarui jika setting yang diubah adalah Global
                    if (!isSuperAdmin || e.data.scope === 'global') {
                        const nameEl = document.getElementById('sidebarBrandName');
                        if (nameEl) {
                            if (s.app_name) {
                                nameEl.innerText = s.app_name;
                                nameEl.title = s.app_name;
                            }
                            if (s.sidebar_brand_font_size) {
                                nameEl.style.fontSize = s.sidebar_brand_font_size;
                            }
                        }
                        const subEl = document.getElementById('sidebarBrandSub');
                        if (subEl) {
                            if (s.app_subtitle) {
                                subEl.innerText = s.app_subtitle;
                                subEl.title = s.app_subtitle;
                            }
                            if (s.sidebar_brand_sub_font_size) {
                                subEl.style.fontSize = s.sidebar_brand_sub_font_size;
                            }
                        }
                        const logoEl = document.getElementById('sidebarBrandLogo');
                        if (logoEl && s.app_logo) logoEl.src = s.app_logo;
                        if (s.app_name && s.app_subtitle) {
                            document.title = s.app_name + ' - ' + s.app_subtitle;
                        }
                    }
                    if (s.show_company_card !== undefined) {
                        const card = document.getElementById('sidebarCompanyCard');
                        if (card) {
                            let isVis = true;
                            if (s.show_company_card === '0') isVis = false;
                            else if (s.show_company_card === '1') isVis = true;
                            else isVis = isSuperAdmin;
                            card.style.display = isVis ? '' : 'none';
                        }
                    }
                    if (s.show_menu_tracking !== undefined) {
                        showMenuTracking = (s.show_menu_tracking === '1');
                        const elTrack = document.getElementById('nav-item-tracking');
                        if (elTrack) {
                            elTrack.style.display = showMenuTracking ? '' : 'none';
                        }
                        if (!showMenuTracking && currentTab === 'tracking') {
                            switchMainTab('daftar_dokumen');
                        }
                    }
                }
            } else if (e.data.type === 'LIVE_SIDEBAR_FONT_CHANGE') {
                if (!isSuperAdmin || e.data.scope === 'global') {
                    const nameEl = document.getElementById('sidebarBrandName');
                    if (nameEl && e.data.titleFontSize) {
                        nameEl.style.fontSize = e.data.titleFontSize;
                    }
                    const subEl = document.getElementById('sidebarBrandSub');
                    if (subEl && e.data.subFontSize) {
                        subEl.style.fontSize = e.data.subFontSize;
                    }
                }
            }
        });

        async function checkAlertNotificationBadge() {
            try {
                const res = await fetch(`index.php?action=get_alert_notifications&cmp_id=${encodeURIComponent(currentCmpId)}`);
                const json = await res.json();
                const bell = document.querySelector('.notif-bell');
                if (bell && json.status === 'OK') {
                    if (json.total_alerts > 0) {
                        bell.title = `Pusat Peringatan & Status Sistem (${json.total_alerts} Peringatan Aktif)`;
                    } else {
                        bell.title = 'Pusat Peringatan & Status Sistem (Semua Normal)';
                    }
                }
            } catch(e) {}
        }

        async function showNotificationAlerts() {
            Swal.fire({
                title: 'Memeriksa Status & Peringatan...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch(`index.php?action=get_alert_notifications&cmp_id=${encodeURIComponent(currentCmpId)}`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message || 'Terjadi kesalahan sistem.' });
                    return;
                }

                let alertCardsHtml = '';

                // 1. Dokumen Belum Selesai (CEISA 4.0) - HANYA jika > 0
                const cntBelumSelesai = Number(json.belum_selesai || 0);
                if (cntBelumSelesai > 0) {
                    const badgeBelumSelesai = `<span style="background:#fff7e6; color:#d46b08; border:1px solid #ffd591; padding:2px 8px; border-radius:12px; font-weight:700; font-size:10px;">${cntBelumSelesai.toLocaleString('id-ID')} Dokumen</span>`;
                    alertCardsHtml += `
                    <div style="background:var(--bg-base, #f9fafb); border:1px solid var(--border-color, #e5e7eb); border-radius:6px; padding:10px 12px; margin-bottom:10px; text-align:left;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                            <div style="font-weight:700; font-size:11px; color:var(--text-main, #262626); display:flex; align-items:center; gap:6px;">
                                <span>⏳</span> Dokumen Belum Selesai (CEISA 4.0)
                            </div>
                            ${badgeBelumSelesai}
                        </div>
                        <div style="font-size:9.5px; color:var(--text-muted, #8c8c8c); margin-bottom:8px; line-height:1.4;">
                            Terdapat <strong>${cntBelumSelesai.toLocaleString('id-ID')}</strong> dokumen pengajuan yang masih dalam proses pelayanan pabean (belum selesai / belum SPPB/NPE).
                        </div>
                        <button type="button" class="ant-btn ant-btn-sm" style="height:22px; font-size:9.5px; padding:0 8px; background:var(--primary); color:#fff; border-color:var(--primary);" onclick="Swal.close(); switchMainTab('daftar_dokumen');">
                            <span>Buka Dashboard & Daftar Dokumen →</span>
                        </button>
                    </div>`;
                }

                // 2. Dokumen Belum Masuk IT Inv (Hanya jika IS_INV = 1 DAN cntBelumItInv > 0)
                const isInv = parseInt(json.is_inv || 0, 10);
                const cntBelumItInv = Number(json.belum_it_inv || 0);
                if (isInv === 1 && cntBelumItInv > 0) {
                    const badgeBelumItInv = `<span style="background:#fff1f0; color:#cf1322; border:1px solid #ffa39e; padding:2px 8px; border-radius:12px; font-weight:700; font-size:10px;">${cntBelumItInv.toLocaleString('id-ID')} Dokumen</span>`;
                    alertCardsHtml += `
                    <div style="background:var(--bg-base, #f9fafb); border:1px solid var(--border-color, #e5e7eb); border-radius:6px; padding:10px 12px; margin-bottom:10px; text-align:left;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                            <div style="font-weight:700; font-size:11px; color:var(--text-main, #262626); display:flex; align-items:center; gap:6px;">
                                <span>📦</span> Dokumen Belum Masuk IT Inventory (bc_1ab2)
                            </div>
                            ${badgeBelumItInv}
                        </div>
                        <div style="font-size:9.5px; color:var(--text-muted, #8c8c8c); margin-bottom:8px; line-height:1.4;">
                            Terdapat <strong>${cntBelumItInv.toLocaleString('id-ID')}</strong> dokumen pabean staging yang telah memiliki Nomor Pendaftaran tetapi belum diposting ke tabel <code>bc_1ab2</code>.
                        </div>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" style="height:22px; font-size:9.5px; padding:0 8px;" onclick="Swal.close(); openTransferNotItInv();">
                            <span>Buka Data Host to Host (Posting IT Inv) →</span>
                        </button>
                    </div>`;
                }

                // 3. Selisih QTY Fisik Warehouse vs Dokumen (bc_1ab2) - HANYA jika > 0
                const cntQtyDiff = Number(json.selisih_qty || 0);
                if (cntQtyDiff > 0) {
                    let qtyDiffRows = '';
                    if (Array.isArray(json.items_qty_diff)) {
                        qtyDiffRows = json.items_qty_diff.map(item => `
                            <tr style="border-bottom:1px solid var(--border-color, #f0f0f0);">
                                <td style="padding:4px 6px; font-family:monospace; font-size:9px; color:var(--primary, #1890ff);">${escapeHtml(item.NOMOR_AJU || '-')}</td>
                                <td style="padding:4px 6px; font-weight:600;">${escapeHtml(item.KODE_BARANG || '-')}</td>
                                <td style="padding:4px 6px; text-align:right;">${Number(item.QTY || 0).toLocaleString('id-ID')}</td>
                                <td style="padding:4px 6px; text-align:right; font-weight:700; color:#d46b08;">${Number(item.QTY_AKTUAL || 0).toLocaleString('id-ID')}</td>
                                <td style="padding:4px 6px; text-align:right; font-weight:700; color:${parseFloat(item.SELISIH) < 0 ? '#ff4d4f' : '#52c41a'};">
                                    ${parseFloat(item.SELISIH) > 0 ? '+' : ''}${Number(item.SELISIH || 0).toLocaleString('id-ID')} ${escapeHtml(item.UNIT || '')}
                                </td>
                                <td style="padding:4px 6px; color:var(--text-muted, #8c8c8c); font-size:8.5px;">${escapeHtml(item.MEMO || '-')}</td>
                            </tr>
                        `).join('');
                    }

                    const badgeQtyDiff = `<span style="background:#fff1f0; color:#cf1322; border:1px solid #ffa39e; padding:2px 8px; border-radius:12px; font-weight:700; font-size:10px;">⚠️ ${cntQtyDiff.toLocaleString('id-ID')} Item Ada Selisih</span>`;

                    alertCardsHtml += `
                    <div style="background:var(--bg-base, #f9fafb); border:1px solid var(--border-color, #e5e7eb); border-radius:6px; padding:10px 12px; margin-bottom:6px; text-align:left;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                            <div style="font-weight:700; font-size:11px; color:var(--text-main, #262626); display:flex; align-items:center; gap:6px;">
                                <span>⚖️</span> Kesesuaian QTY Aktual Warehouse (bc_1ab2)
                            </div>
                            ${badgeQtyDiff}
                        </div>
                        <div style="font-size:9.5px; color:var(--text-muted, #8c8c8c); margin-bottom:6px; line-height:1.4;">
                            Ditemukan <strong>${cntQtyDiff.toLocaleString('id-ID')}</strong> baris barang di tabel <code>bc_1ab2</code> dengan perbedaan antara QTY dokumen pabean dan QTY fisik yang diinput oleh tim Warehouse.
                        </div>
                        <div style="max-height:150px; overflow-y:auto; border:1px solid var(--border-color, #ebedf0); border-radius:4px; margin-bottom:8px; background:var(--bg-card, #fff);">
                            <table style="width:100%; border-collapse:collapse; font-size:9px; text-align:left;">
                                <thead>
                                    <tr style="background:var(--bg-base, #f5f5f5); border-bottom:1px solid var(--border-color, #e8e8e8); color:var(--text-muted, #595959);">
                                        <th style="padding:4px 6px;">Nomor AJU</th>
                                        <th style="padding:4px 6px;">Kode Barang</th>
                                        <th style="padding:4px 6px; text-align:right;">QTY Dok</th>
                                        <th style="padding:4px 6px; text-align:right;">QTY Aktual</th>
                                        <th style="padding:4px 6px; text-align:right;">Selisih</th>
                                        <th style="padding:4px 6px;">Memo/Ket</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${qtyDiffRows}
                                </tbody>
                            </table>
                        </div>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="ant-btn ant-btn-sm" style="height:22px; font-size:9.5px; padding:0 8px; background:#faad14; color:#fff; border-color:#faad14;" onclick="Swal.close(); switchMainTab('wh_incoming');">
                                <span>Periksa Warehouse Incoming →</span>
                            </button>
                            <button type="button" class="ant-btn ant-btn-sm" style="height:22px; font-size:9.5px; padding:0 8px;" onclick="Swal.close(); switchMainTab('it_inventory_1a');">
                                <span>Lihat Laporan 1A IT Inv →</span>
                            </button>
                        </div>
                    </div>`;
                }

                if (!alertCardsHtml) {
                    alertCardsHtml = `
                    <div style="padding:28px 16px; text-align:center; color:var(--text-muted, #8c8c8c);">
                        <div style="font-size:38px; margin-bottom:8px;">🎉</div>
                        <div style="font-size:12px; font-weight:700; color:var(--text-main, #262626); margin-bottom:4px;">Semua Proses Berjalan Normal</div>
                        <div style="font-size:10px; line-height:1.5;">Tidak ada peringatan aktif. Seluruh dokumen telah selesai diproses, data IT Inventory lengkap, dan tidak ada selisih QTY fisik.</div>
                    </div>`;
                }

                const contentHtml = `
                <div style="max-height:75vh; overflow-y:auto; padding:2px 4px; font-family:'Poppins', sans-serif;">
                    ${alertCardsHtml}
                </div>
                `;

                Swal.fire({
                    title: `<div style="font-size:13px; font-weight:700; color:var(--text-main, #262626); display:flex; align-items:center; gap:8px;">
                                <span>🔔</span> Pusat Peringatan & Status Sistem
                            </div>
                            <div style="font-size:10px; font-weight:500; color:var(--text-muted, #8c8c8c); margin-top:2px;">
                                Perusahaan Aktif: <strong>${escapeHtml(json.cmp_name || 'Perusahaan')}</strong> [${escapeHtml(json.cmp_id || '')}]
                            </div>`,
                    html: contentHtml,
                    width: '620px',
                    showConfirmButton: true,
                    confirmButtonText: 'Tutup',
                    confirmButtonColor: '#1890ff'
                });

            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Kesalahan Jaringan', text: err.message });
            }
        }

        function openTransferNotItInv() {
            switchMainTab('transfer');
            const sendFilter = () => {
                try {
                    const ifrm = window.tabIframes && window.tabIframes['transfer'];
                    if (ifrm && ifrm.contentWindow) {
                        ifrm.contentWindow.postMessage({
                            type: 'FILTER_STATUS',
                            status: 'not_it_inv'
                        }, '*');
                    }
                } catch(e) {}
            };
            sendFilter();
            setTimeout(sendFilter, 300);
            setTimeout(sendFilter, 800);
        }

        window.onload = function() {
            initLoginFormProtection();
            loadSavedTheme();
            loadSavedIconStyle();
            loadSidebarPinState();
            applyRoleMenuVisibility();

            const iframe = document.getElementById('mainFrame');
            if (iframe) {
                syncIframeState(iframe);
            }
            checkAlertNotificationBadge();
        };
    </script>
</body>
</html>

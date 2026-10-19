<?php
/**
 * CEISA 4.0 Multi-Company Dynamic Configuration
 * Menggantikan konfigurasi hardcode lama dengan data dinamis dari tabel bc_ceisa_company.
 * Satu instalasi / folder dapat melayani banyak perusahaan (MULTI COMPANY).
 */
date_default_timezone_set("Asia/Jakarta");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sertakan koneksi database RDS MySQL
require_once __DIR__ . '/koneksi.php';

/**
 * Fungsi untuk mengambil data perusahaan dari tabel bc_ceisa_company
 * Mendukung pencarian via cmp_id, npwp, atau nomor_aju.
 */
if (!function_exists('ceisa_get_company')) {
    function ceisa_get_company($identifier = null) {
        global $koneksi;
        
        if (!$koneksi) {
            return null;
        }
        
        // 1. Cek langsung berdasarkan cmp_id atau npwp
        if (!empty($identifier)) {
            $clean_id = trim($identifier);
            
            $stmt = $koneksi->prepare("SELECT * FROM bc_ceisa_company WHERE (cmp_id = ? OR npwp = ?) AND is_active = 1 LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ss", $clean_id, $clean_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $stmt->close();
                    return $row;
                }
                $stmt->close();
            }
            
            // 2. Jika identifier adalah nomor_aju, cari cmp_id dari bc_ceisa_header
            $stmt = $koneksi->prepare("SELECT cmp_id FROM bc_ceisa_header WHERE nomor_aju = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $clean_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $stmt->close();
                    if (!empty($row['cmp_id'])) {
                        return ceisa_get_company($row['cmp_id']);
                    }
                }
                $stmt->close();
            }

            // 2b. Jika identifier adalah nomor_aju manifes, cari dari bc_ceisa_manifes_header
            $stmtM = $koneksi->prepare("SELECT cmp_id FROM bc_ceisa_manifes_header WHERE nomor_aju = ? LIMIT 1");
            if ($stmtM) {
                $stmtM->bind_param("s", $clean_id);
                $stmtM->execute();
                $resM = $stmtM->get_result();
                if ($rowM = $resM->fetch_assoc()) {
                    $stmtM->close();
                    if (!empty($rowM['cmp_id'])) {
                        return ceisa_get_company($rowM['cmp_id']);
                    }
                }
                $stmtM->close();
            }
        }
        
        // 3. Fallback: Deteksi konteks modul Manifes NVOCC (Prioritaskan TVL)
        $scriptName = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
        if (strpos($scriptName, 'manifest') !== false) {
            $resM = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE (cmp_id = 'TVL' OR company_roles LIKE '%nvocc%') AND is_active = 1 LIMIT 1");
            if ($resM && $rowM = $resM->fetch_assoc()) {
                return $rowM;
            }
        }

        // Ambil perusahaan aktif pertama dari database
        $res = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_id ASC LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }
        
        return null;
    }
}

// Deteksi Company Identifier dari berbagai kemungkinan parameter input (Prioritaskan Request dan Sesi Pengguna)
$target_cmp = null;

if (!empty($_REQUEST['cmp_id'])) {
    $target_cmp = trim($_REQUEST['cmp_id']);
} elseif (!empty($_SESSION['company_cmp_id'])) {
    $target_cmp = trim($_SESSION['company_cmp_id']);
} elseif (!empty($_SESSION['cmp_id'])) {
    $target_cmp = trim($_SESSION['cmp_id']);
} elseif (!empty($_SERVER['HTTP_X_COMPANY_ID'])) {
    $target_cmp = trim($_SERVER['HTTP_X_COMPANY_ID']);
} elseif (!empty($cmp_id) && $cmp_id !== 'default') {
    $target_cmp = trim($cmp_id);
} elseif (!empty($_REQUEST['npwp'])) {
    $target_cmp = trim($_REQUEST['npwp']);
} elseif (!empty($_REQUEST['nomor_aju'])) {
    $target_cmp = trim($_REQUEST['nomor_aju']);
} elseif (!empty($_REQUEST['aju'])) {
    $target_cmp = trim($_REQUEST['aju']);
}

// Ambil data konfigurasi perusahaan yang aktif
$active_company = ceisa_get_company($target_cmp);

if ($active_company) {
    $cmp_id         = $active_company['cmp_id'];
    $namaperusahaan = $active_company['cmp_name'];
    $npwp           = $active_company['npwp'];
    $nib            = $active_company['nib'];
    $alamat         = $active_company['alamat'];
    $kode_kantor    = $active_company['kode_kantor'];
    $kode_jenis_tpb = $active_company['kode_jenis_tpb'];
    $nomor_skep     = $active_company['nomor_skep'];
    $tanggal_skep   = $active_company['tanggal_skep'];
    
    $username       = $active_company['username_ceisa'];
    $password       = $active_company['password_ceisa'];
    $secretmfa      = $active_company['secret_mfa'] ?? '';
    $user           = $active_company['id_user_ceisa'] ?? '';
    $idUser         = $user;
    $isfinal        = (!empty($active_company['is_final']) && strtolower($active_company['is_final']) === 'true') ? 'true' : 'false';
    $token          = $active_company['token_ceisa'] ?? '';
    $apikey         = $active_company['api_key_ceisa'] ?? '';

    // Integrasi ERP & AI
    $erp_name       = $active_company['ERP_Name'] ?? '-';
    $erp_user       = $active_company['ERP_User'] ?? '';
    $erp_password   = $active_company['ERP_Password'] ?? '';
    $erp_constring  = $active_company['ERP_Constring'] ?? '';
    $ai_name        = $active_company['AI_Name'] ?? '';
    $ai_api_key     = $active_company['AI_API_Key'] ?? '';
    $is_inv         = isset($active_company['IS_INV']) ? (int)$active_company['IS_INV'] : 1;
    
    // Simpan ke sesi agar request berikutnya otomatis mengenali perusahaan ini
    $_SESSION['cmp_id'] = $cmp_id;
    $_SESSION['is_inv'] = $is_inv;
} else {
    // Default fallback kosongan bila belum ada data perusahaan sama sekali
    $cmp_id         = '';
    $namaperusahaan = '';
    $npwp           = '';
    $nib            = '';
    $alamat         = '';
    $kode_kantor    = '';
    $kode_jenis_tpb = '';
    $nomor_skep     = '';
    $tanggal_skep   = '';
    $username       = '';
    $password       = '';
    $secretmfa      = '';
    $user           = '';
    $idUser         = '';
    $isfinal        = 'false';
    $token          = '';
    $apikey         = '';
    
    $erp_name       = '-';
    $erp_user       = '';
    $erp_password   = '';
    $erp_constring  = '';
    $ai_name        = '';
    $ai_api_key     = '';
    $is_inv         = 1;
}

$judulwebbc27 = 'Web Portal - BC 2.7 IN';
?>
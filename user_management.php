<?php
/**
 * CEISA 4.0 - Manajemen Pengguna Perusahaan (user_management.php)
 * Mengelola akun pengguna tingkat perusahaan, hak akses menu portal, dan izin jenis dokumen kepabeanan.
 */

session_start();
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

$isSuperAdmin   = !empty($_SESSION['is_super_admin']);
$isCompanyAdmin = !empty($_SESSION['is_company_admin']);
$isAdmin        = $isSuperAdmin;

if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'bc') {
    die('<div style="padding:40px; font-family:sans-serif; text-align:center;">
        <h3 style="color:#d46b08;">⛔ Akses Ditolak</h3>
        <p>Akun dengan peran <b>Pejabat Bea Cukai (BC)</b> hanya berwenang mengakses modul <b>Laporan IT Inventory</b>.</p>
    </div>');
}
$activeCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : (!empty($cmp_id) ? trim($cmp_id) : '')));

// Non-superadmin WAJIB terkunci pada perusahaannya sendiri
if (!$isSuperAdmin && !empty($_SESSION['company_cmp_id'])) {
    $activeCmp = $_SESSION['company_cmp_id'];
}

$activeCmpName = $namaperusahaan ?: ($_SESSION['company_name'] ?? $activeCmp);
$activeNpwp = $npwp ?: ($_SESSION['company_npwp'] ?? '');
$themeParam = $_GET['theme'] ?? 'light';
if ($themeParam !== 'dark' && $themeParam !== 'light') $themeParam = 'light';

// Ambil daftar seluruh perusahaan dari database (Super Admin melihat semua, Admin Perusahaan hanya melihat perusahaannya)
$companiesList = [];
if (isset($koneksi) && $koneksi) {
    if ($isSuperAdmin) {
        $qComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp FROM bc_ceisa_company ORDER BY cmp_name ASC");
    } else {
        $qComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "'");
    }
    if ($qComp) {
        while ($rC = $qComp->fetch_assoc()) {
            $companiesList[] = $rC;
        }
    }
}
if (empty($activeCmp) && !empty($companiesList)) {
    $activeCmp = $companiesList[0]['cmp_id'];
    $activeCmpName = $companiesList[0]['cmp_name'];
}

// Definisi Referensi Menu Portal
$availableMenus = [
    'daftar_dokumen'  => ['title' => 'Dashboard Ceisa 4.0', 'icon' => '🖥️', 'group' => 'Dokumen Pabean'],
    'transfer'        => ['title' => 'Data Host to Host', 'icon' => '⊞', 'group' => 'Dokumen Pabean'],
    'editor'          => ['title' => 'Form Editor Dokumen', 'icon' => '✏️', 'group' => 'Dokumen Pabean'],
    'import'          => ['title' => 'Import Excel Dokumen', 'icon' => '📊', 'group' => 'Dokumen Pabean'],
    'load_erp_odoo'   => ['title' => 'Load ERP (Odoo)', 'icon' => '🔌', 'group' => 'Dokumen Pabean'],
    'manifest'        => ['title' => 'Manifes Online (BC 1.1)', 'icon' => '🚢', 'group' => 'Dokumen Pabean'],
    'dokap'           => ['title' => 'Upload Dokap Pabean', 'icon' => '📎', 'group' => 'Dokumen Pabean'],
    'tracking'        => ['title' => 'Tracking Respon Pabean', 'icon' => '🔍', 'group' => 'Dokumen Pabean'],
    'referensi'       => ['title' => 'Master Data Referensi', 'icon' => '📚', 'group' => 'Referensi'],
    'it_inventory'    => ['title' => 'IT Inventory (Laporan)', 'icon' => '📊', 'group' => 'Modul Inventory'],
    'inventory_modul' => ['title' => 'Akses Inventory Modul', 'icon' => '🏭', 'group' => 'Modul Inventory'],
    'inv_material'    => ['title' => 'Master Data Barang', 'icon' => '🏷️', 'group' => 'Data Master'],
    'inv_bom'         => ['title' => 'Master BOM', 'icon' => '🧩', 'group' => 'Data Master'],
    'inv_tarif'       => ['title' => 'Master Tarif BM dari Kode HS', 'icon' => '📑', 'group' => 'Referensi'],
    'inv_jaminan'     => ['title' => 'Master Jaminan', 'icon' => '🛡️', 'group' => 'Data Master'],
    'inv_stock_transfer' => ['title' => 'Mutasi Produksi', 'icon' => '🏭', 'group' => 'Modul Inventory'],
    'inv_warehouse_in'=> ['title' => 'Warehouse - Incoming', 'icon' => '📥', 'group' => 'Transaksi Warehouse'],
    'inv_warehouse_delivery' => ['title' => 'Warehouse - Delivery', 'icon' => '🚚', 'group' => 'Transaksi Warehouse'],
    'inv_transfer_bc27' => ['title' => 'Warehouse - Transfer BC27 OUT-->IN', 'icon' => '🔄', 'group' => 'Transaksi Warehouse'],
    'inv_stok_opname' => ['title' => 'Stok Opname', 'icon' => '📋', 'group' => 'Modul Inventory'],
    'inv_scan27'      => ['title' => 'Import BC 2.7 IN dan Post ke IT Inv', 'icon' => '📲', 'group' => 'Modul Inventory'],
    'inv_unposting_appv' => ['title' => 'Approval Revisi / UnPosting', 'icon' => '✔️', 'group' => 'Admin Inventory'],
    'inv_stok_awal'      => ['title' => 'Set Stok Awal Tahun', 'icon' => '📖', 'group' => 'Admin Inventory'],
    'inv_adjust_spp'     => ['title' => 'Adjust SPP', 'icon' => '⚡', 'group' => 'Admin Inventory'],
    'app_settings'       => ['title' => 'Setting Aplikasi', 'icon' => '⚙️', 'group' => 'Administrator'],
    'user_management'    => ['title' => 'Kelola User Perusahaan', 'icon' => '👥', 'group' => 'User Management']
];

// Definisi Referensi Dokumen Bea Cukai
$availableDocs = [
    '20'  => ['title' => 'BC 2.0 (Impor PIB)', 'group' => 'Impor & TPB'],
    '23'  => ['title' => 'BC 2.3 (Impor TPB)', 'group' => 'Impor & TPB'],
    '25'  => ['title' => 'BC 2.5 (Pengeluaran TPB ke TLDDP)', 'group' => 'Impor & TPB'],
    '261' => ['title' => 'BC 2.6.1 (Subkon Keluar TPB)', 'group' => 'Impor & TPB'],
    '262' => ['title' => 'BC 2.6.2 (Subkon Masuk TPB)', 'group' => 'Impor & TPB'],
    '27'  => ['title' => 'BC 2.7 (Transfer TPB ke TPB)', 'group' => 'Impor & TPB'],
    '16'  => ['title' => 'BC 1.6 (Pemasukan PLB)', 'group' => 'PLB & Fasilitas'],
    '28'  => ['title' => 'BC 2.8 (Impor PLB)', 'group' => 'PLB & Fasilitas'],
    '33'  => ['title' => 'BC 3.3 (Ekspor PLB)', 'group' => 'PLB & Fasilitas'],
    '331' => ['title' => 'BC 3.3.1 (P3BET)', 'group' => 'PLB & Fasilitas'],
    '30'  => ['title' => 'BC 3.0 (Ekspor PEB)', 'group' => 'Ekspor & Lokal'],
    '40'  => ['title' => 'BC 4.0 (Pemasukan TLDDP)', 'group' => 'Ekspor & Lokal'],
    '41'  => ['title' => 'BC 4.1 (Pengeluaran TLDDP)', 'group' => 'Ekspor & Lokal'],
    '611' => ['title' => 'PPKEK 6.1.1 (Pemasukan LDP)', 'group' => 'Kawasan Ekonomi Khusus (PPKEK)'],
    '613' => ['title' => 'PPKEK 6.1.3 (Pemasukan TLDDP)', 'group' => 'Kawasan Ekonomi Khusus (PPKEK)']
];

// -------------------------------------------------------------
// AJAX API ENDPOINTS
// -------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($action)) {
    if ($action === 'list') {
        header('Content-Type: application/json; charset=utf-8');
        $targetCmp = $isSuperAdmin ? trim($_REQUEST['cmp_id'] ?? $activeCmp) : $activeCmp;
        $qCmp = $koneksi->real_escape_string($targetCmp);
        $res = $koneksi->query("SELECT l.cmp_id, IFNULL(c.cmp_name, l.cmp_id) AS cmp_name, l.user_id, l.nama_lengkap, l.lvl, l.role, l.allowed_menus, l.allowed_docs, l.is_active, l.created_at, l.updated_at 
                                FROM bc_ceisa_login l 
                                LEFT JOIN bc_ceisa_company c ON l.cmp_id = c.cmp_id
                                WHERE l.cmp_id = '$qCmp' 
                                ORDER BY l.role ASC, l.user_id ASC");
        $users = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $menus = $r['allowed_menus'];
                $docs = $r['allowed_docs'];
                $r['allowed_menus_arr'] = ($menus === '*' || empty($menus)) ? ['*'] : (json_decode($menus, true) ?: [$menus]);
                $r['allowed_docs_arr'] = ($docs === '*' || empty($docs)) ? ['*'] : (json_decode($docs, true) ?: [$docs]);
                $users[] = $r;
            }
        }
        echo json_encode(['status' => 'OK', 'users' => $users, 'cmp_id' => $targetCmp, 'cmp_name' => $activeCmpName]);
        exit;
    }

    if ($action === 'get') {
        header('Content-Type: application/json; charset=utf-8');
        $uid = trim($_REQUEST['user_id'] ?? '');
        $targetCmp = $isSuperAdmin ? trim($_REQUEST['cmp_id'] ?? $activeCmp) : $activeCmp;
        $stmt = $koneksi->prepare("SELECT l.cmp_id, IFNULL(c.cmp_name, l.cmp_id) AS cmp_name, l.user_id, l.nama_lengkap, l.lvl, l.role, l.allowed_menus, l.allowed_docs, l.is_active 
                                   FROM bc_ceisa_login l 
                                   LEFT JOIN bc_ceisa_company c ON l.cmp_id = c.cmp_id
                                   WHERE l.cmp_id = ? AND l.user_id = ? LIMIT 1");
        $stmt->bind_param("ss", $targetCmp, $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $user = $res->fetch_assoc()) {
            $user['allowed_menus_arr'] = ($user['allowed_menus'] === '*' || empty($user['allowed_menus'])) ? ['*'] : (json_decode($user['allowed_menus'], true) ?: []);
            $user['allowed_docs_arr'] = ($user['allowed_docs'] === '*' || empty($user['allowed_docs'])) ? ['*'] : (json_decode($user['allowed_docs'], true) ?: []);
            echo json_encode(['status' => 'OK', 'user' => $user]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => "Pengguna '$uid' tidak ditemukan pada perusahaan '$targetCmp'."]);
        }
        $stmt->close();
        exit;
    }

    if ($action === 'save') {
        header('Content-Type: application/json; charset=utf-8');
        $isEdit    = !empty($_POST['is_edit']);
        $userId    = strtolower(trim($_POST['user_id'] ?? ''));
        $targetCmp = $isSuperAdmin ? trim($_POST['cmp_id'] ?? $activeCmp) : $activeCmp;
        $nama      = trim($_POST['nama_lengkap'] ?? '');
        $password  = trim($_POST['password'] ?? '');
        $role      = trim($_POST['role'] ?? 'operator');
        if (!$isSuperAdmin && $role === 'superadmin') {
            $role = 'admin'; // Company admin cannot create superadmin
        }
        $isActive  = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if (empty($targetCmp)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Perusahaan (cmp_id) wajib dipilih!']);
            exit;
        }
        if (empty($userId)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Username wajib diisi!']);
            exit;
        }
        if (preg_match('/[^a-zA-Z0-9_\-\.]/', $userId)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Username hanya boleh berisi huruf, angka, garis bawah (_), atau titik!']);
            exit;
        }

        if ($role === 'bc') {
            // Role Pejabat Bea Cukai HANYA berwenang mengakses modul Laporan IT Inventory
            $allMenus = json_encode(['it_inventory']);
            $allDocs  = !empty($_POST['all_docs']) ? '*' : (isset($_POST['docs']) ? json_encode(array_values((array)$_POST['docs'])) : '*');
            $lvl      = '4';
        } else {
            $allMenus  = !empty($_POST['all_menus']) ? '*' : (isset($_POST['menus']) ? json_encode(array_values((array)$_POST['menus'])) : '[]');
            $allDocs   = !empty($_POST['all_docs']) ? '*' : (isset($_POST['docs']) ? json_encode(array_values((array)$_POST['docs'])) : '[]');
            $lvl = ($role === 'admin') ? '1' : (($role === 'supervisor') ? '2' : '3');
        }

        if ($isEdit) {
            // Update
            if (!empty($password)) {
                $stmt = $koneksi->prepare("UPDATE bc_ceisa_login SET nama_lengkap = ?, password = ?, role = ?, lvl = ?, allowed_menus = ?, allowed_docs = ?, is_active = ? WHERE cmp_id = ? AND user_id = ?");
                $stmt->bind_param("ssssssiss", $nama, $password, $role, $lvl, $allMenus, $allDocs, $isActive, $targetCmp, $userId);
            } else {
                $stmt = $koneksi->prepare("UPDATE bc_ceisa_login SET nama_lengkap = ?, role = ?, lvl = ?, allowed_menus = ?, allowed_docs = ?, is_active = ? WHERE cmp_id = ? AND user_id = ?");
                $stmt->bind_param("sssssiss", $nama, $role, $lvl, $allMenus, $allDocs, $isActive, $targetCmp, $userId);
            }

            if ($stmt && $stmt->execute()) {
                echo json_encode(['status' => 'OK', 'message' => "Pengguna '$userId' berhasil diperbarui untuk perusahaan '$targetCmp'!"]);
            } else {
                echo json_encode(['status' => 'ERROR', 'message' => "Gagal menyimpan perubahan: " . $koneksi->error]);
            }
            if ($stmt) $stmt->close();
        } else {
            // Tambah Baru
            if (empty($password)) {
                echo json_encode(['status' => 'ERROR', 'message' => 'Password wajib diisi untuk pengguna baru!']);
                exit;
            }

            // Cek duplikasi pada cmp_id terpilih
            $chk = $koneksi->prepare("SELECT user_id FROM bc_ceisa_login WHERE cmp_id = ? AND user_id = ? LIMIT 1");
            $chk->bind_param("ss", $targetCmp, $userId);
            $chk->execute();
            $cRes = $chk->get_result();
            if ($cRes && $cRes->num_rows > 0) {
                echo json_encode(['status' => 'ERROR', 'message' => "Username '$userId' sudah terdaftar pada perusahaan '$targetCmp'!"]);
                $chk->close();
                exit;
            }
            $chk->close();

            $stmt = $koneksi->prepare("INSERT INTO bc_ceisa_login (cmp_id, user_id, nama_lengkap, password, lvl, role, allowed_menus, allowed_docs, is_active, oto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ALL')");
            $stmt->bind_param("ssssssssi", $targetCmp, $userId, $nama, $password, $lvl, $role, $allMenus, $allDocs, $isActive);
            if ($stmt && $stmt->execute()) {
                echo json_encode(['status' => 'OK', 'message' => "Pengguna baru '$userId' berhasil ditambahkan untuk perusahaan '$targetCmp'!"]);
            } else {
                echo json_encode(['status' => 'ERROR', 'message' => "Gagal menambahkan pengguna: " . $koneksi->error]);
            }
            if ($stmt) $stmt->close();
        }
        exit;
    }

    if ($action === 'delete') {
        header('Content-Type: application/json; charset=utf-8');
        $userId    = trim($_POST['user_id'] ?? '');
        $targetCmp = $isSuperAdmin ? trim($_POST['cmp_id'] ?? $activeCmp) : $activeCmp;
        if ($userId === 'dp' || ($userId === 'admin' && empty($_SESSION['is_super_admin']))) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Akun utama Administrator tidak dapat dihapus!']);
            exit;
        }

        $stmt = $koneksi->prepare("DELETE FROM bc_ceisa_login WHERE cmp_id = ? AND user_id = ?");
        $stmt->bind_param("ss", $targetCmp, $userId);
        if ($stmt && $stmt->execute()) {
            echo json_encode(['status' => 'OK', 'message' => "Pengguna '$userId' berhasil dihapus."]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => "Gagal menghapus pengguna: " . $koneksi->error]);
        }
        if ($stmt) $stmt->close();
        exit;
    }

    if ($action === 'toggle_status') {
        header('Content-Type: application/json; charset=utf-8');
        $userId    = trim($_POST['user_id'] ?? '');
        $targetCmp = $isSuperAdmin ? trim($_POST['cmp_id'] ?? $activeCmp) : $activeCmp;
        $status    = (int)($_POST['status'] ?? 1);

        if ($userId === 'dp') {
            echo json_encode(['status' => 'ERROR', 'message' => 'Status akun Super Administrator (dp) tidak dapat diubah!']);
            exit;
        }

        $stmt = $koneksi->prepare("UPDATE bc_ceisa_login SET is_active = ? WHERE cmp_id = ? AND user_id = ?");
        $stmt->bind_param("iss", $status, $targetCmp, $userId);
        if ($stmt && $stmt->execute()) {
            $label = $status ? 'diaktifkan' : 'dinonaktifkan';
            echo json_encode(['status' => 'OK', 'message' => "Status pengguna '$userId' berhasil $label!"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => "Gagal memperbarui status: " . $koneksi->error]);
        }
        if ($stmt) $stmt->close();
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Pengguna - CEISA 4.0</title>
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || '<?= $themeParam ?>' || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        })();
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border-card: #e8e8e8;
            --border-input: #d9d9d9;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --text-main: #262626;
            --text-heading: #1f1f1f;
            --text-muted: #8c8c8c;
            --bg-filter: #fafafa;
            --table-header: #fafafa;
            --table-row-hover: #f5f5f5;
            color-scheme: light;
        }
        body[data-theme="dark"], html[data-theme="dark"] body {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --border-card: #303030;
            --border-input: #434343;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-muted: rgba(255, 255, 255, 0.45);
            --bg-filter: #1a1a27;
            --table-header: #1f1f1f;
            --table-row-hover: #262626;
            color-scheme: dark !important;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            padding: 6px 10px;
            font-size: 10.5px;
            line-height: 1.45;
        }

        .ant-card {
            background: var(--bg-card);
            border-radius: 4px;
            position: relative;
            width: 100%;
            border: 1px solid var(--border-card);
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .ant-card-head {
            min-height: 40px;
            padding: 0 12px;
            color: var(--text-heading);
            font-weight: 600;
            font-size: 11.5px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .ant-card-head-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ant-card-body {
            padding: 12px;
        }

        /* Top Action Bar */
        .action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .search-input-wrap {
            position: relative;
            flex: 1;
            max-width: 320px;
        }
        .search-input {
            width: 100%;
            height: 28px;
            padding: 4px 10px 4px 28px;
            background: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: 4px;
            font-size: 10.5px;
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.18);
        }
        .search-icon {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 12px;
        }

        /* Table Design */
        .table-responsive {
            overflow-x: auto;
            border: 1px solid var(--border-card);
            border-radius: 4px;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            text-align: left;
        }
        table.ant-table th {
            background: var(--table-header);
            color: var(--text-muted);
            font-weight: 600;
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-card);
            white-space: nowrap;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.3px;
        }
        table.ant-table td {
            padding: 7px 10px;
            border-bottom: 1px solid var(--border-card);
            color: var(--text-main);
            vertical-align: middle;
        }
        table.ant-table tr:hover td {
            background: var(--table-row-hover);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: 500;
            white-space: nowrap;
        }
        .badge-admin {
            background: rgba(250, 140, 22, 0.12);
            color: #d46b08;
            border: 1px solid rgba(250, 140, 22, 0.3);
        }
        .badge-supervisor {
            background: rgba(114, 46, 209, 0.12);
            color: #722ed1;
            border: 1px solid rgba(114, 46, 209, 0.3);
        }
        .badge-operator {
            background: rgba(24, 144, 255, 0.12);
            color: #1890ff;
            border: 1px solid rgba(24, 144, 255, 0.3);
        }
        .badge-bc {
            background: rgba(19, 194, 194, 0.14);
            color: #08979c;
            border: 1px solid rgba(19, 194, 194, 0.4);
        }
        .badge-active {
            background: rgba(82, 196, 26, 0.12);
            color: #52c41a;
            border: 1px solid rgba(82, 196, 26, 0.3);
        }
        .badge-inactive {
            background: rgba(0, 0, 0, 0.06);
            color: var(--text-muted);
            border: 1px solid var(--border-input);
        }
        .pill-tag {
            display: inline-block;
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 9px;
            margin: 1px 2px;
            color: var(--text-main);
        }

        /* Buttons */
        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            height: 26px;
            padding: 0 10px;
            font-family: inherit;
            font-size: 10.5px;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            text-decoration: none;
        }
        .ant-btn-primary {
            color: #ffffff;
            background: linear-gradient(135deg, #1890ff 0%, #096dd9 100%);
            box-shadow: 0 2px 4px rgba(24, 144, 255, 0.25);
        }
        .ant-btn-primary:hover {
            background: linear-gradient(135deg, #40a9ff 0%, #1890ff 100%);
            transform: translateY(-1px);
        }
        .ant-btn-default {
            background: var(--bg-card);
            border: 1px solid var(--border-input);
            color: var(--text-main);
        }
        .ant-btn-default:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .ant-btn-danger {
            background: rgba(255, 77, 79, 0.1);
            border: 1px solid rgba(255, 77, 79, 0.3);
            color: #ff4d4f;
        }
        .ant-btn-danger:hover {
            background: #ff4d4f;
            color: #fff;
        }
        .ant-btn-sm {
            height: 22px;
            padding: 0 6px;
            font-size: 9.5px;
        }

        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 6px;
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.28);
            animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.97) translateY(-6px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .modal-body {
            padding: 14px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-footer {
            padding: 8px 14px;
            border-top: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            background: var(--bg-filter);
            border-bottom-left-radius: 6px;
            border-bottom-right-radius: 6px;
        }

        /* Form Controls in Modal */
        .form-row {
            display: flex;
            gap: 12px;
            margin-bottom: 10px;
        }
        .form-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-col label {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .form-control {
            height: 28px;
            background: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: 3px;
            padding: 3px 8px;
            color: var(--text-main);
            font-size: 10.5px;
            outline: none;
            font-family: inherit;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.18);
        }

        /* Checkbox Grid Cards */
        .permission-section {
            background: var(--bg-filter);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 12px;
        }
        .permission-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid var(--border-card);
        }
        .permission-header span {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .btn-link-action {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 9.5px;
            cursor: pointer;
            padding: 0 4px;
            text-decoration: underline;
        }
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 6px 10px;
        }
        .check-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            cursor: pointer;
            user-select: none;
        }
        .check-item input[type="checkbox"] {
            cursor: pointer;
            accent-color: var(--primary);
        }

        .avatar-circle {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 10px;
            margin-right: 6px;
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($themeParam) ?>">
    <div class="ant-card">
        <!-- CARD HEADER -->
        <div class="ant-card-head">
            <div class="ant-card-head-title">
                <span style="font-size: 15px;">👥</span>
                <span>Manajemen Pengguna Perusahaan</span>
            </div>
            <div style="font-size: 10px; color: var(--text-muted); display:flex; align-items:center; gap:8px;">
                <span style="font-weight:600; text-transform:uppercase;">🏢 Perusahaan:</span>
                <?php if ($isSuperAdmin): ?>
                <select id="mainFilterCmp" class="form-control" style="height:26px; font-size:10.5px; font-weight:700; color:var(--text-heading); min-width:240px; cursor:pointer;" onchange="changeMainCompany()">
                    <?php foreach ($companiesList as $c): ?>
                        <option value="<?= htmlspecialchars($c['cmp_id']) ?>" <?= ($c['cmp_id'] === $activeCmp) ? 'selected' : '' ?>>
                            🏢 <?= htmlspecialchars($c['cmp_name']) ?> [<?= htmlspecialchars($c['cmp_id']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <span style="font-size:11px; font-weight:700; color:var(--primary); background:rgba(24,144,255,0.08); padding:3px 10px; border-radius:4px; border:1px solid rgba(24,144,255,0.2);">
                    🏢 <?= htmlspecialchars($activeCmpName) ?> [<?= htmlspecialchars($activeCmp) ?>]
                </span>
                <input type="hidden" id="mainFilterCmp" value="<?= htmlspecialchars($activeCmp) ?>">
                <?php endif; ?>
            </div>
        </div>

        <div class="ant-card-body">
            <!-- ACTION BAR -->
            <div class="action-bar">
                <div class="search-input-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="tableSearch" class="search-input" placeholder="Cari nama pengguna atau username..." onkeyup="filterUsers()">
                </div>
                <div>
                    <button type="button" class="ant-btn ant-btn-primary" onclick="openUserModal(false)">
                        <span>➕</span>
                        <span>Tambah Pengguna Baru</span>
                    </button>
                    <button type="button" class="ant-btn ant-btn-default" onclick="loadUsers()" title="Segarkan Data">
                        <span>🔄</span>
                    </button>
                </div>
            </div>

            <!-- TABLE OF USERS -->
            <div class="table-responsive">
                <table class="ant-table">
                    <thead>
                        <tr>
                            <th style="width:35px; text-align:center;">#</th>
                            <th>Pengguna</th>
                            <th style="width:110px;">Role</th>
                            <th>Hak Akses Menu</th>
                            <th>Izin Dokumen BC</th>
                            <th style="width:85px; text-align:center;">Status</th>
                            <th style="width:120px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="userTableBody">
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 20px; color:var(--text-muted);">
                                Memuat data pengguna...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL TAMBAH / EDIT USER -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-card">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <div class="modal-title" id="modalUserTitle">
                    <span>➕</span>
                    <span>Tambah Pengguna Baru</span>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <!-- NAMA PERUSAHAAN (MERUJUK KE CMP_ID) -->
                    <div style="display:inline-flex; align-items:center; gap:6px; background:rgba(24, 144, 255, 0.08); border:1px solid rgba(24, 144, 255, 0.35); border-radius:4px; padding:3px 10px;">
                        <span style="font-size:12px;">🏢</span>
                        <label for="modalUserCmpId" style="font-size:9.5px; font-weight:700; color:var(--primary); margin:0; text-transform:uppercase; white-space:nowrap;">Perusahaan:</label>
                        <?php if ($isSuperAdmin): ?>
                        <select id="modalUserCmpId" class="form-control" style="height:26px; font-size:11px; font-weight:700; color:var(--text-heading); border:1px solid var(--border-input); background:var(--bg-card); padding:2px 8px; border-radius:3px; max-width:320px; cursor:pointer;">
                            <?php foreach ($companiesList as $c): ?>
                                <option value="<?= htmlspecialchars($c['cmp_id']) ?>" <?= ($c['cmp_id'] === $activeCmp) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['cmp_name']) ?> [<?= htmlspecialchars($c['cmp_id']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span style="font-size:11px; font-weight:700; color:var(--text-heading);"><?= htmlspecialchars($activeCmpName) ?> [<?= htmlspecialchars($activeCmp) ?>]</span>
                        <input type="hidden" id="modalUserCmpId" value="<?= htmlspecialchars($activeCmp) ?>">
                        <?php endif; ?>
                    </div>
                    <button type="button" onclick="closeUserModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:16px; padding:2px 6px;">✕</button>
                </div>
            </div>
            <div class="modal-body">
                <form id="formUser" onsubmit="submitUser(event)">
                    <input type="hidden" id="isEdit" value="0">

                    <!-- IDENTITAS AKUN -->
                    <div class="form-row">
                        <div class="form-col">
                            <label>Username (ID Login) *</label>
                            <input type="text" id="userId" class="form-control" required placeholder="Contoh: budi_ops" autocomplete="off">
                            <span style="font-size:8.5px; color:var(--text-muted);">Huruf kecil, angka, tanda _ atau - tanpa spasi.</span>
                        </div>
                        <div class="form-col">
                            <label>Nama Lengkap</label>
                            <input type="text" id="namaLengkap" class="form-control" placeholder="Contoh: Budi Santoso">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-col">
                            <label id="lblPassword">Kata Sandi (Password) *</label>
                            <input type="password" id="userPassword" class="form-control" placeholder="Minimal 4 karakter">
                            <span id="passHint" style="font-size:8.5px; color:var(--text-muted); display:none;">Kosongkan jika tidak ingin mengubah password.</span>
                        </div>
                        <div class="form-col">
                            <label>Peran / Role</label>
                            <select id="userRole" class="form-control" onchange="handleRoleChange(this.value)">
                                <option value="operator">Operator / Staff (Akses Standar)</option>
                                <option value="supervisor">Supervisor (Pemeriksa / Verifikator)</option>
                                <option value="admin">Admin Perusahaan (Akses Penuh Perusahaan)</option>
                                <option value="bc">Pejabat Bea Cukai (BC - Khusus Laporan IT Inventory)</option>
                            </select>
                        </div>
                        <div class="form-col" style="max-width: 120px;">
                            <label>Status</label>
                            <select id="userStatus" class="form-control">
                                <option value="1">Aktif</option>
                                <option value="0">Nonaktif</option>
                            </select>
                        </div>
                    </div>

                    <!-- 1. HAK AKSES MENU PORTAL -->
                    <div class="permission-section">
                        <div class="permission-header">
                            <span>🖥️ Hak Akses Menu Portal</span>
                            <div>
                                <label style="font-size:9.5px; margin-right:8px; cursor:pointer;">
                                    <input type="checkbox" id="checkAllMenus" onchange="toggleAllMenus(this.checked)"> <strong>Semua Menu</strong>
                                </label>
                                <button type="button" class="btn-link-action" onclick="checkAllMenuCheckboxes(true)">Pilih Semua</button>
                                <button type="button" class="btn-link-action" onclick="checkAllMenuCheckboxes(false)">Hapus Semua</button>
                            </div>
                        </div>
                        <div class="permission-grid" id="menuCheckboxGrid">
                            <?php foreach ($availableMenus as $mKey => $mInfo): ?>
                            <label class="check-item">
                                <input type="checkbox" name="menus[]" value="<?= htmlspecialchars($mKey) ?>" class="chk-menu">
                                <span><?= $mInfo['icon'] ?> <?= htmlspecialchars($mInfo['title']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 2. HAK AKSES JENIS DOKUMEN BEA CUKAI -->
                    <div class="permission-section">
                        <div class="permission-header">
                            <span>📑 Hak Akses Dokumen Bea Cukai</span>
                            <div>
                                <label style="font-size:9.5px; margin-right:8px; cursor:pointer;">
                                    <input type="checkbox" id="checkAllDocs" onchange="toggleAllDocs(this.checked)"> <strong>Semua Dokumen</strong>
                                </label>
                                <button type="button" class="btn-link-action" onclick="checkAllDocCheckboxes(true)">Pilih Semua</button>
                                <button type="button" class="btn-link-action" onclick="checkAllDocCheckboxes(false)">Hapus Semua</button>
                            </div>
                        </div>
                        <div class="permission-grid" id="docCheckboxGrid">
                            <?php foreach ($availableDocs as $dKey => $dInfo): ?>
                            <label class="check-item">
                                <input type="checkbox" name="docs[]" value="<?= htmlspecialchars($dKey) ?>" class="chk-doc">
                                <span><strong><?= htmlspecialchars($dKey) ?></strong> - <?= htmlspecialchars($dInfo['title']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn ant-btn-default" onclick="closeUserModal()">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="document.getElementById('formUser').requestSubmit()">
                    <span>💾</span> <span>Simpan Pengguna</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentCmpId = '<?= htmlspecialchars($activeCmp) ?>';
        let usersData = [];
        const availableMenusMap = <?= json_encode($availableMenus) ?>;
        const availableDocsMap = <?= json_encode($availableDocs) ?>;

        async function loadUsers() {
            const tbody = document.getElementById('userTableBody');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 20px; color:var(--text-muted);"><span class="spinner"></span> Memuat daftar pengguna...</td></tr>';

            try {
                const res = await fetch(`user_management.php?action=list&cmp_id=${encodeURIComponent(currentCmpId)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    usersData = json.users || [];
                    renderUsersTable(usersData);
                } else {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 20px; color:#ff4d4f;">Gagal memuat pengguna: ${json.message}</td></tr>`;
                }
            } catch(e) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 20px; color:#ff4d4f;">Error koneksi: ${e.message}</td></tr>`;
            }
        }

        function changeMainCompany() {
            const sel = document.getElementById('mainFilterCmp');
            if (sel) {
                currentCmpId = sel.value;
                const modalSel = document.getElementById('modalUserCmpId');
                if (modalSel) modalSel.value = currentCmpId;
                loadUsers();
            }
        }

        function renderUsersTable(list) {
            const tbody = document.getElementById('userTableBody');
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 24px; color:var(--text-muted);">Belum ada data pengguna untuk perusahaan ini. Klik tombol <b>Tambah Pengguna Baru</b> di atas untuk membuat akun baru.</td></tr>';
                return;
            }

            let html = '';
            list.forEach((u, idx) => {
                const initials = (u.nama_lengkap || u.user_id).substring(0, 2).toUpperCase();
                
                // Role Badge
                let roleBadge = '<span class="badge badge-operator">👤 Operator</span>';
                if (u.role === 'admin') roleBadge = '<span class="badge badge-admin">👑 Admin Perusahaan</span>';
                else if (u.role === 'supervisor') roleBadge = '<span class="badge badge-supervisor">🛡️ Supervisor</span>';
                else if (u.role === 'bc') roleBadge = '<span class="badge badge-bc">👮 Pejabat Bea Cukai (BC)</span>';

                // Menus Display
                let menusHtml = '';
                if (u.allowed_menus_arr.includes('*') || u.allowed_menus_arr.includes('ALL')) {
                    menusHtml = '<span class="badge" style="background:rgba(82,196,26,0.12); color:#52c41a; border:1px solid rgba(82,196,26,0.3);">🌐 Semua Menu</span>';
                } else {
                    const mCount = u.allowed_menus_arr.length;
                    const previews = u.allowed_menus_arr.slice(0, 3).map(k => `<span class="pill-tag">${availableMenusMap[k]?.title || k}</span>`).join('');
                    menusHtml = previews + (mCount > 3 ? `<span class="badge badge-inactive">+${mCount - 3} lagi</span>` : '');
                }

                // Docs Display
                let docsHtml = '';
                if (u.allowed_docs_arr.includes('*') || u.allowed_docs_arr.includes('ALL')) {
                    docsHtml = '<span class="badge" style="background:rgba(24,144,255,0.12); color:#1890ff; border:1px solid rgba(24,144,255,0.3);">📑 Semua Dokumen BC</span>';
                } else {
                    const dCount = u.allowed_docs_arr.length;
                    const previews = u.allowed_docs_arr.slice(0, 4).map(k => `<span class="pill-tag" style="font-weight:600;">BC ${k}</span>`).join('');
                    docsHtml = previews + (dCount > 4 ? `<span class="badge badge-inactive">+${dCount - 4} lagi</span>` : '');
                }

                // Status Badge
                const isActive = parseInt(u.is_active) === 1;
                const statusBadge = isActive 
                    ? '<span class="badge badge-active" style="cursor:pointer;" onclick="toggleStatus(\'' + u.user_id + '\', 0, \'' + (u.cmp_id || currentCmpId) + '\')" title="Klik untuk nonaktifkan">● Aktif</span>' 
                    : '<span class="badge badge-inactive" style="cursor:pointer;" onclick="toggleStatus(\'' + u.user_id + '\', 1, \'' + (u.cmp_id || currentCmpId) + '\')" title="Klik untuk aktifkan">○ Nonaktif</span>';

                html += `
                    <tr>
                        <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                        <td>
                            <div style="display:flex; align-items:center;">
                                <div class="avatar-circle">${initials}</div>
                                <div>
                                    <div style="font-weight:600; color:var(--text-heading); font-size:11px;">${escapeHtml(u.user_id)}</div>
                                    <div style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(u.nama_lengkap || '-')}</div>
                                    <div style="font-size:8.5px; color:var(--primary); font-weight:600; margin-top:2px;">🏢 ${escapeHtml(u.cmp_name || u.cmp_id)}</div>
                                </div>
                            </div>
                        </td>
                        <td>${roleBadge}</td>
                        <td>${menusHtml}</td>
                        <td>${docsHtml}</td>
                        <td style="text-align:center;">${statusBadge}</td>
                        <td style="text-align:center; white-space:nowrap;">
                            <button type="button" class="ant-btn ant-btn-default ant-btn-sm" onclick="editUser('${escapeHtml(u.user_id)}', '${escapeHtml(u.cmp_id)}')">✏️ Edit</button>
                            <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="deleteUser('${escapeHtml(u.user_id)}', '${escapeHtml(u.cmp_id)}')">🗑️</button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function filterUsers() {
            const q = document.getElementById('tableSearch').value.toLowerCase();
            const filtered = usersData.filter(u => 
                u.user_id.toLowerCase().includes(q) || 
                (u.nama_lengkap && u.nama_lengkap.toLowerCase().includes(q)) ||
                (u.role && u.role.toLowerCase().includes(q))
            );
            renderUsersTable(filtered);
        }

        function openUserModal(isEdit, user = null) {
            document.getElementById('isEdit').value = isEdit ? '1' : '0';
            const titleEl = document.getElementById('modalUserTitle');
            const uidInp = document.getElementById('userId');
            const passHint = document.getElementById('passHint');
            const passReq = document.getElementById('lblPassword');
            const cmpSelect = document.getElementById('modalUserCmpId');

            if (isEdit && user) {
                titleEl.innerHTML = `<span>✏️</span> <span>Edit Pengguna: <b>${escapeHtml(user.user_id)}</b></span>`;
                if (cmpSelect) {
                    cmpSelect.value = user.cmp_id || currentCmpId;
                    cmpSelect.disabled = true;
                }
                uidInp.value = user.user_id;
                uidInp.readOnly = true;
                uidInp.style.background = 'var(--bg-filter)';
                document.getElementById('namaLengkap').value = user.nama_lengkap || '';
                document.getElementById('userPassword').value = '';
                document.getElementById('userPassword').required = false;
                passHint.style.display = 'block';
                passReq.innerText = 'Kata Sandi (Opsional)';
                document.getElementById('userRole').value = user.role || 'operator';
                document.getElementById('userStatus').value = user.is_active;

                // Check Menus
                const isAllMenus = user.allowed_menus_arr.includes('*') || user.allowed_menus_arr.includes('ALL');
                document.getElementById('checkAllMenus').checked = isAllMenus;
                document.querySelectorAll('.chk-menu').forEach(cb => {
                    cb.checked = isAllMenus || user.allowed_menus_arr.includes(cb.value);
                });

                // Check Docs
                const isAllDocs = user.allowed_docs_arr.includes('*') || user.allowed_docs_arr.includes('ALL');
                document.getElementById('checkAllDocs').checked = isAllDocs;
                document.querySelectorAll('.chk-doc').forEach(cb => {
                    cb.checked = isAllDocs || user.allowed_docs_arr.includes(cb.value);
                });
            } else {
                titleEl.innerHTML = `<span>➕</span> <span>Tambah Pengguna Baru</span>`;
                if (cmpSelect) {
                    cmpSelect.value = currentCmpId;
                    cmpSelect.disabled = false;
                }
                uidInp.value = '';
                uidInp.readOnly = false;
                uidInp.style.background = 'var(--bg-input)';
                document.getElementById('namaLengkap').value = '';
                document.getElementById('userPassword').value = '';
                document.getElementById('userPassword').required = true;
                passHint.style.display = 'none';
                passReq.innerText = 'Kata Sandi (Password) *';
                document.getElementById('userRole').value = 'operator';
                document.getElementById('userStatus').value = '1';

                // Default: pilih semua menu & semua dokumen
                document.getElementById('checkAllMenus').checked = true;
                document.querySelectorAll('.chk-menu').forEach(cb => cb.checked = true);
                document.getElementById('checkAllDocs').checked = true;
                document.querySelectorAll('.chk-doc').forEach(cb => cb.checked = true);
            }

            document.getElementById('userModal').style.display = 'flex';
        }

        function closeUserModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        function checkAllMenuCheckboxes(checked) {
            document.querySelectorAll('.chk-menu').forEach(cb => cb.checked = checked);
            document.getElementById('checkAllMenus').checked = checked;
        }

        function toggleAllMenus(checked) {
            document.querySelectorAll('.chk-menu').forEach(cb => cb.checked = checked);
        }

        function checkAllDocCheckboxes(checked) {
            document.querySelectorAll('.chk-doc').forEach(cb => cb.checked = checked);
            document.getElementById('checkAllDocs').checked = checked;
        }

        function toggleAllDocs(checked) {
            document.querySelectorAll('.chk-doc').forEach(cb => cb.checked = checked);
        }

        function handleRoleChange(role) {
            if (role === 'bc') {
                // Otomatis batasi menu HANYA ke IT Inventory
                document.getElementById('checkAllMenus').checked = false;
                document.querySelectorAll('.chk-menu').forEach(cb => {
                    cb.checked = (cb.value === 'it_inventory');
                });
                // Dokumen pabean tetap bisa diakses untuk laporan read-only
                document.getElementById('checkAllDocs').checked = true;
                document.querySelectorAll('.chk-doc').forEach(cb => cb.checked = true);
            }
        }

        async function editUser(userId, cmpId) {
            try {
                const targetCmp = cmpId || currentCmpId;
                const res = await fetch(`user_management.php?action=get&user_id=${encodeURIComponent(userId)}&cmp_id=${encodeURIComponent(targetCmp)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    openUserModal(true, json.user);
                } else {
                    showError(json.message);
                }
            } catch(e) {
                showError('Gagal memuat data pengguna: ' + e.message);
            }
        }

        async function submitUser(e) {
            e.preventDefault();
            const isEdit = document.getElementById('isEdit').value === '1';
            const userId = document.getElementById('userId').value.trim();
            const nama = document.getElementById('namaLengkap').value.trim();
            const password = document.getElementById('userPassword').value;
            const role = document.getElementById('userRole').value;
            const status = document.getElementById('userStatus').value;
            const modalCmp = document.getElementById('modalUserCmpId');
            const cmpId = (modalCmp && modalCmp.value) ? modalCmp.value : currentCmpId;

            const isAllMenus = document.getElementById('checkAllMenus').checked;
            const selectedMenus = Array.from(document.querySelectorAll('.chk-menu:checked')).map(cb => cb.value);

            const isAllDocs = document.getElementById('checkAllDocs').checked;
            const selectedDocs = Array.from(document.querySelectorAll('.chk-doc:checked')).map(cb => cb.value);

            if (!isAllMenus && selectedMenus.length === 0) {
                showWarning('Harap pilih minimal 1 menu atau centang "Semua Menu"!');
                return;
            }
            if (!isAllDocs && selectedDocs.length === 0) {
                showWarning('Harap pilih minimal 1 jenis dokumen atau centang "Semua Dokumen"!');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('is_edit', isEdit ? '1' : '0');
            fd.append('cmp_id', cmpId);
            fd.append('user_id', userId);
            fd.append('nama_lengkap', nama);
            fd.append('password', password);
            fd.append('role', role);
            fd.append('is_active', status);
            if (isAllMenus) {
                fd.append('all_menus', '1');
            } else {
                selectedMenus.forEach(m => fd.append('menus[]', m));
            }
            if (isAllDocs) {
                fd.append('all_docs', '1');
            } else {
                selectedDocs.forEach(d => fd.append('docs[]', d));
            }

            try {
                const res = await fetch('user_management.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    closeUserModal();
                    // Jika user baru dibuat untuk perusahaan lain, switch view ke perusahaan tsb
                    if (cmpId !== currentCmpId) {
                        currentCmpId = cmpId;
                        const mainSel = document.getElementById('mainFilterCmp');
                        if (mainSel) mainSel.value = cmpId;
                    }
                    loadUsers();
                } else {
                    showError(json.message);
                }
            } catch(e) {
                showError('Error menyimpan pengguna: ' + e.message);
            }
        }

        async function deleteUser(userId, cmpId) {
            const confirmed = await showConfirm(`Apakah Anda yakin ingin menghapus pengguna '${userId}'?`, 'Konfirmasi Hapus');
            if (!confirmed) return;

            const targetCmp = cmpId || currentCmpId;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('user_id', userId);
            fd.append('cmp_id', targetCmp);

            try {
                const res = await fetch('user_management.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    loadUsers();
                } else {
                    showError(json.message);
                }
            } catch(e) {
                showError('Error menghapus pengguna: ' + e.message);
            }
        }

        async function toggleStatus(userId, newStatus, cmpId) {
            const targetCmp = cmpId || currentCmpId;
            const fd = new FormData();
            fd.append('action', 'toggle_status');
            fd.append('user_id', userId);
            fd.append('status', newStatus);
            fd.append('cmp_id', targetCmp);

            try {
                const res = await fetch('user_management.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    loadUsers();
                } else {
                    showError(json.message);
                }
            } catch(e) {
                showError('Error: ' + e.message);
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        function applyCurrentTheme(th) {
            if (!th) {
                var urlParams = new URLSearchParams(window.location.search);
                th = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            }
            if (th !== 'light' && th !== 'dark') th = 'light';
            document.documentElement.setAttribute('data-theme', th);
            document.documentElement.style.colorScheme = th;
            document.body.setAttribute('data-theme', th);
        }
        applyCurrentTheme();

        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                applyCurrentTheme(e.data.theme);
            }
        });

        window.onload = function() {
            loadUsers();
        };
    </script>
</body>
</html>

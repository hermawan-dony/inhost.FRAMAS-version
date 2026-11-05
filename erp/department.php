<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Master Departemen (bc_dept) & Otoritas Approval PO/PR (bc_po_user)
 * Desain, Font, dan Styling 100% Identik dengan Halaman Purchase Order (PO) & PowerBuilder Desktop (TPB-ERP)
 * Dilengkapi Mini Dashboard KPI, Matriks Persetujuan Bertingkat (Pembuat, Level 1, 2, 3, Final BOD),
 * Plafon Budget, Delegasi Kuasa BOD, dan Studio Tanda Tangan Digital.
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (!empty($_GET['cmp_id'])) {
    $sessionCmpId = $_GET['cmp_id'];
    $_SESSION['company_cmp_id'] = $sessionCmpId;
    $_SESSION['cmp_id'] = $sessionCmpId;
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']) || !empty($_GET['is_admin']);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'user');

$activeCmpName = $_SESSION['company_name'] ?? '';
if (empty($activeCmpName) && !empty($sessionCmpId)) {
    $qCmp = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    if ($qCmp && $rCmp = $qCmp->fetch_assoc()) {
        $activeCmpName = $rCmp['cmp_name'];
    }
}
if (empty($activeCmpName)) $activeCmpName = 'TPB ERP Enterprise';

// Auto-Heal: Pastikan struktur tabel bc_dept dan bc_po_user tersedia lengkap
function ensureTables($koneksi, $escCmp) {
    // 1. Table bc_dept
    $check = $koneksi->query("SHOW TABLES LIKE 'bc_dept'");
    if (!$check || $check->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_dept` (
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `kode` varchar(30) NOT NULL DEFAULT '',
            `nama_dept` varchar(100) NOT NULL DEFAULT '',
            `pic` varchar(100) DEFAULT '',
            `gudang` char(1) DEFAULT 'N',
            `prod` char(1) DEFAULT 'N',
            `wip` char(1) DEFAULT 'N',
            `fg` char(1) DEFAULT 'N',
            `cons` char(1) DEFAULT 'N',
            `scrap` char(1) DEFAULT 'N',
            `aktif` char(1) DEFAULT 'Y',
            `setby` varchar(50) DEFAULT '',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`cmp_id`, `kode`),
            KEY `idx_dept_nama` (`cmp_id`, `nama_dept`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $cols = [];
        $res = $koneksi->query("SHOW COLUMNS FROM bc_dept");
        if ($res) while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
        
        if (!isset($cols['cmp_id'])) {
            @$koneksi->query("ALTER TABLE bc_dept ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' FIRST");
            @$koneksi->query("ALTER TABLE bc_dept ADD INDEX `idx_cmp_id` (`cmp_id`)");
        }
        if (!isset($cols['aktif'])) {
            @$koneksi->query("ALTER TABLE bc_dept ADD COLUMN `aktif` char(1) DEFAULT 'Y'");
        }
        @$koneksi->query("ALTER TABLE bc_dept MODIFY COLUMN `kode` varchar(30) NOT NULL");
        @$koneksi->query("ALTER TABLE bc_dept MODIFY COLUMN `nama_dept` varchar(100) NOT NULL");
    }

    // 2. Table bc_po_user (Matriks Approval Bertingkat)
    $checkPoUser = $koneksi->query("SHOW TABLES LIKE 'bc_po_user'");
    if (!$checkPoUser || $checkPoUser->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_po_user` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `doc_type` varchar(10) NOT NULL DEFAULT 'PO',
            `dept_kode` varchar(30) NOT NULL DEFAULT '*',
            `user_id` varchar(50) NOT NULL DEFAULT '',
            `nama_lengkap` varchar(100) NOT NULL DEFAULT '',
            `jabatan` varchar(100) NOT NULL DEFAULT '',
            `level_approval` varchar(20) NOT NULL DEFAULT 'LEVEL_1',
            `urutan` int(2) NOT NULL DEFAULT 1,
            `limit_min` decimal(18,2) NOT NULL DEFAULT 0.00,
            `limit_max` decimal(18,2) NOT NULL DEFAULT 0.00,
            `is_bod_delegate` char(1) NOT NULL DEFAULT 'N',
            `ttd_digital` longtext DEFAULT NULL,
            `aktif` char(1) NOT NULL DEFAULT 'Y',
            `setby` varchar(50) DEFAULT '',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_cmp_doc_dept_user_lvl` (`cmp_id`, `doc_type`, `dept_kode`, `user_id`, `level_approval`),
            KEY `idx_dept` (`dept_kode`),
            KEY `idx_level` (`level_approval`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
ensureTables($koneksi, $escCmp);

// =========================================================================
// AJAX BACKEND HANDLERS
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. LIST DEPARTEMEN (PAGINATED & SEARCHABLE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(d.cmp_id = '$escCmp' OR d.cmp_id = '' OR d.cmp_id IS NULL)";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(d.kode LIKE '%$s%' OR d.nama_dept LIKE '%$s%' OR d.pic LIKE '%$s%')";
    }
    if ($status === 'Y') {
        $where[] = "(d.aktif = 'Y' OR d.aktif = '' OR d.aktif IS NULL)";
    } elseif ($status === 'N') {
        $where[] = "d.aktif = 'N'";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countSql = "SELECT count(*) FROM bc_dept d $whereSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    // Ambil data departemen beserta jumlah user approval terdaftar
    $sql = "SELECT d.*, 
            (SELECT COUNT(*) FROM bc_po_user u WHERE (u.dept_kode = d.kode OR u.dept_kode = '*') AND u.aktif = 'Y' AND (u.cmp_id = d.cmp_id OR u.cmp_id = '' OR u.cmp_id IS NULL)) as approver_count
            FROM bc_dept d 
            $whereSql 
            ORDER BY (d.kode IN ('PRODUKSI', 'PPIC', 'MAINTENANCE', 'GA', 'IT', 'QC')) DESC, d.kode ASC 
            LIMIT $offset, $limit";
    
    $data = [];
    $res = $koneksi->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // Hitung KPI
    $cmpFilter = !empty($sessionCmpId) ? "WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";
    $kpiTotal = $koneksi->query("SELECT count(*) FROM bc_dept $cmpFilter")->fetch_row()[0] ?? 0;
    $kpiGudang = $koneksi->query("SELECT count(*) FROM bc_dept $cmpFilter " . (empty($cmpFilter) ? "WHERE" : "AND") . " gudang = 'Y'")->fetch_row()[0] ?? 0;
    $kpiProd = $koneksi->query("SELECT count(*) FROM bc_dept $cmpFilter " . (empty($cmpFilter) ? "WHERE" : "AND") . " prod = 'Y'")->fetch_row()[0] ?? 0;
    $kpiAktif = $koneksi->query("SELECT count(*) FROM bc_dept $cmpFilter " . (empty($cmpFilter) ? "WHERE" : "AND") . " (aktif = 'Y' OR aktif = '' OR aktif IS NULL)")->fetch_row()[0] ?? 0;
    $kpiApprovers = $koneksi->query("SELECT count(*) FROM bc_po_user $cmpFilter " . (empty($cmpFilter) ? "WHERE" : "AND") . " aktif = 'Y'")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'data'   => $data,
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        'kpi'    => [
            'total_all'      => $kpiTotal,
            'total_gudang'   => $kpiGudang,
            'total_prod'     => $kpiProd,
            'total_aktif'    => $kpiAktif,
            'total_approvers'=> $kpiApprovers
        ]
    ]);
    exit;
}

// 2. GET SINGLE DEPARTEMEN
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim($_GET['kode'] ?? '');
    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Departemen tidak valid']);
        exit;
    }
    $escKode = $koneksi->real_escape_string($kode);
    $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '')" : "";
    $q = $koneksi->query("SELECT * FROM bc_dept WHERE kode = '$escKode' $cmpFilter LIMIT 1");
    if ($q && $row = $q->fetch_assoc()) {
        echo json_encode(['status' => 'OK', 'data' => $row]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Departemen tidak ditemukan']);
    }
    exit;
}

// 3. SIMPAN DEPARTEMEN (INSERT / UPDATE)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $kode      = strtoupper(trim($_POST['kode'] ?? ''));
    $oldKode   = strtoupper(trim($_POST['old_kode'] ?? ''));
    $namaDept  = trim($_POST['nama_dept'] ?? '');
    $pic       = trim($_POST['pic'] ?? '');
    $gudang    = ($_POST['gudang'] ?? '') === 'Y' ? 'Y' : 'N';
    $prod      = ($_POST['prod'] ?? '') === 'Y' ? 'Y' : 'N';
    $wip       = ($_POST['wip'] ?? '') === 'Y' ? 'Y' : 'N';
    $fg        = ($_POST['fg'] ?? '') === 'Y' ? 'Y' : 'N';
    $cons      = ($_POST['cons'] ?? '') === 'Y' ? 'Y' : 'N';
    $scrap     = ($_POST['scrap'] ?? '') === 'Y' ? 'Y' : 'N';
    $aktif     = ($_POST['aktif'] ?? '') === 'N' ? 'N' : 'Y';
    $setby     = $currentUser . ' - ' . date('Ymd H:i');

    if (empty($kode) || empty($namaDept)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode dan Nama Departemen wajib diisi!']);
        exit;
    }

    $escKode     = $koneksi->real_escape_string($kode);
    $escOldKode  = $koneksi->real_escape_string($oldKode);
    $escNama     = $koneksi->real_escape_string($namaDept);
    $escPic      = $koneksi->real_escape_string($pic);
    $escSetby    = $koneksi->real_escape_string($setby);

    if (!empty($oldKode)) {
        // Mode Update
        $upd = $koneksi->query("UPDATE bc_dept SET 
            kode = '$escKode',
            nama_dept = '$escNama',
            pic = '$escPic',
            gudang = '$gudang',
            prod = '$prod',
            wip = '$wip',
            fg = '$fg',
            cons = '$cons',
            scrap = '$scrap',
            aktif = '$aktif',
            setby = '$escSetby'
            WHERE kode = '$escOldKode' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        
        if ($upd) {
            // Update juga referensi kode jika berubah
            if ($oldKode !== $kode) {
                @$koneksi->query("UPDATE bc_po_user SET dept_kode = '$escKode' WHERE dept_kode = '$escOldKode' AND (cmp_id = '$escCmp' OR cmp_id = '')");
            }
            echo json_encode(['status' => 'OK', 'message' => "Data Departemen '$kode' berhasil diperbarui"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui departemen: ' . $koneksi->error]);
        }
    } else {
        // Mode Insert Baru
        $chk = $koneksi->query("SELECT kode FROM bc_dept WHERE kode = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Kode Departemen '$kode' sudah terdaftar! Gunakan kode lain."]);
            exit;
        }

        $ins = $koneksi->query("INSERT INTO bc_dept 
            (cmp_id, kode, nama_dept, pic, gudang, prod, wip, fg, cons, scrap, aktif, setby)
            VALUES 
            ('$escCmp', '$escKode', '$escNama', '$escPic', '$gudang', '$prod', '$wip', '$fg', '$cons', '$scrap', '$aktif', '$escSetby')");

        if ($ins) {
            echo json_encode(['status' => 'OK', 'message' => 'Data Departemen berhasil disimpan']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan departemen: ' . $koneksi->error]);
        }
    }
    exit;
}

// 4. HAPUS DEPARTEMEN
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = strtoupper(trim($_POST['kode'] ?? ''));
    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Departemen tidak valid']);
        exit;
    }
    $escKode = $koneksi->real_escape_string($kode);
    $del = $koneksi->query("DELETE FROM bc_dept WHERE kode = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '')");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => "Departemen '$kode' berhasil dihapus"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 5. LIST USER OTORITAS APPROVAL (bc_po_user)
if ($action === 'list_approval_users') {
    header('Content-Type: application/json; charset=utf-8');
    $deptFilter = trim($_GET['dept_filter'] ?? '');
    $docFilter  = trim($_GET['doc_filter'] ?? '');
    $search     = trim($_GET['search'] ?? '');

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(u.cmp_id = '$escCmp' OR u.cmp_id = '' OR u.cmp_id IS NULL)";
    }
    if (!empty($deptFilter)) {
        $escD = $koneksi->real_escape_string($deptFilter);
        $where[] = "(u.dept_kode = '$escD' OR u.dept_kode = '*')";
    }
    if (!empty($docFilter)) {
        $escDoc = $koneksi->real_escape_string($docFilter);
        $where[] = "(u.doc_type = '$escDoc' OR u.doc_type = 'ALL')";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(u.user_id LIKE '%$s%' OR u.nama_lengkap LIKE '%$s%' OR u.jabatan LIKE '%$s%' OR u.dept_kode LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT u.*, IFNULL(d.nama_dept, 'Semua Departemen (BOD / Global)') as dept_name
            FROM bc_po_user u
            LEFT JOIN bc_dept d ON u.dept_kode = d.kode AND (d.cmp_id = u.cmp_id OR d.cmp_id = '')
            $whereSql
            ORDER BY u.doc_type ASC, u.dept_kode ASC, u.urutan ASC, FIELD(u.level_approval, 'PEMBUAT', 'LEVEL_1', 'LEVEL_2', 'LEVEL_3', 'FINAL'), u.id ASC";

    $list = [];
    $res = $koneksi->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $list]);
    exit;
}

// 6. GET SINGLE APPROVAL USER
if ($action === 'get_approval_user') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $cmpFilter = !empty($sessionCmpId) ? "AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)" : "";
    $q = $koneksi->query("SELECT * FROM bc_po_user WHERE id = $id $cmpFilter LIMIT 1");
    if ($q && $row = $q->fetch_assoc()) {
        echo json_encode(['status' => 'OK', 'data' => $row]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data otorisasi approval tidak ditemukan']);
    }
    exit;
}

// 7. SIMPAN USER OTORITAS APPROVAL (bc_po_user)
if ($action === 'save_approval_user') {
    header('Content-Type: application/json; charset=utf-8');
    $id            = (int)($_POST['id'] ?? 0);
    $docType       = trim($_POST['doc_type'] ?? 'PO');
    $deptKode      = strtoupper(trim($_POST['dept_kode'] ?? '*'));
    $userId        = trim($_POST['user_id'] ?? '');
    $namaLengkap   = trim($_POST['nama_lengkap'] ?? '');
    $jabatan       = trim($_POST['jabatan'] ?? '');
    $levelApproval = trim($_POST['level_approval'] ?? 'LEVEL_1');
    $urutan        = max(1, (int)($_POST['urutan'] ?? 1));
    $limitMin      = floatval($_POST['limit_min'] ?? 0);
    $limitMax      = floatval($_POST['limit_max'] ?? 0);
    $isBodDelegate = ($_POST['is_bod_delegate'] ?? '') === 'Y' ? 'Y' : 'N';
    $aktif         = ($_POST['aktif'] ?? '') === 'N' ? 'N' : 'Y';
    $ttdDigital    = trim($_POST['ttd_digital'] ?? '');
    $setby         = $currentUser . ' - ' . date('Ymd H:i');

    if (empty($userId) || empty($namaLengkap)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'User ID dan Nama Pejabat wajib diisi!']);
        exit;
    }

    $escDoc   = $koneksi->real_escape_string($docType);
    $escDept  = $koneksi->real_escape_string($deptKode);
    $escUser  = $koneksi->real_escape_string($userId);
    $escNama  = $koneksi->real_escape_string($namaLengkap);
    $escJab   = $koneksi->real_escape_string($jabatan);
    $escLvl   = $koneksi->real_escape_string($levelApproval);
    $escTtd   = $koneksi->real_escape_string($ttdDigital);
    $escSetby = $koneksi->real_escape_string($setby);

    if ($id > 0) {
        $ttdClause = !empty($ttdDigital) ? ", ttd_digital = '$escTtd'" : "";
        $upd = $koneksi->query("UPDATE bc_po_user SET 
            doc_type = '$escDoc',
            dept_kode = '$escDept',
            user_id = '$escUser',
            nama_lengkap = '$escNama',
            jabatan = '$escJab',
            level_approval = '$escLvl',
            urutan = $urutan,
            limit_min = $limitMin,
            limit_max = $limitMax,
            is_bod_delegate = '$isBodDelegate',
            aktif = '$aktif',
            setby = '$escSetby'
            $ttdClause
            WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
        if ($upd) {
            echo json_encode(['status' => 'OK', 'message' => 'Otoritas approval berhasil diperbarui']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui: ' . $koneksi->error]);
        }
    } else {
        $ins = $koneksi->query("INSERT INTO bc_po_user 
            (cmp_id, doc_type, dept_kode, user_id, nama_lengkap, jabatan, level_approval, urutan, limit_min, limit_max, is_bod_delegate, ttd_digital, aktif, setby)
            VALUES 
            ('$escCmp', '$escDoc', '$escDept', '$escUser', '$escNama', '$escJab', '$escLvl', $urutan, $limitMin, $limitMax, '$isBodDelegate', '$escTtd', '$aktif', '$escSetby')
            ON DUPLICATE KEY UPDATE 
            nama_lengkap = '$escNama', jabatan = '$escJab', level_approval = '$escLvl', urutan = $urutan, limit_min = $limitMin, limit_max = $limitMax, is_bod_delegate = '$isBodDelegate', aktif = '$aktif', setby = '$escSetby'");
        if ($ins) {
            echo json_encode(['status' => 'OK', 'message' => 'Otoritas approval berhasil ditambahkan']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menambahkan: ' . $koneksi->error]);
        }
    }
    exit;
}

// 8. HAPUS USER OTORITAS APPROVAL
if ($action === 'delete_approval_user') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID otorisasi tidak valid']);
        exit;
    }
    $del = $koneksi->query("DELETE FROM bc_po_user WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Otoritas approval berhasil dihapus']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 9. SIMPAN TANDA TANGAN DIGITAL SAJA
if ($action === 'save_signature') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    $signature = trim($_POST['signature_data'] ?? '');
    if ($id <= 0 || empty($signature)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID atau data tanda tangan tidak valid']);
        exit;
    }
    $escSig = $koneksi->real_escape_string($signature);
    $upd = $koneksi->query("UPDATE bc_po_user SET ttd_digital = '$escSig' WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($upd) {
        echo json_encode(['status' => 'OK', 'message' => 'Tanda tangan digital berhasil disimpan']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan tanda tangan: ' . $koneksi->error]);
    }
    exit;
}

// 10. LIST AKUN LOGIN DARI bc_ceisa_login (UNTUK DROPDOWN)
if ($action === 'get_login_users') {
    header('Content-Type: application/json; charset=utf-8');
    $q = $koneksi->query("SELECT user_id, nama_lengkap, role FROM bc_ceisa_login WHERE is_active = 1 AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id = 'SYSTEM') ORDER BY user_id ASC LIMIT 100");
    $users = [];
    if ($q) while ($r = $q->fetch_assoc()) $users[] = $r;
    echo json_encode(['status' => 'OK', 'data' => $users]);
    exit;
}

// Data Departemen untuk Dropdown di Modal
$allDepts = [];
$deptQ = $koneksi->query("SELECT kode, nama_dept FROM bc_dept WHERE aktif = 'Y' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY kode ASC");
if ($deptQ) while ($d = $deptQ->fetch_assoc()) $allDepts[] = $d;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Departemen &amp; Otoritas Approval - <?= htmlspecialchars($activeCmpName) ?></title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme');
            if (theme === 'dark' || (!theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="/assets/ant_grid.js"></script>
    <style>
        :root {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --text-main: #1f1f1f;
            --text-muted: #595959;
            --border-color: #d9d9d9;
            --border-light: #e8e8e8;
            --border-input: #d9d9d9;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-head-bg: #fafafa;
            --table-hover: #e6f7ff;
            --row-selected: #bae7ff;
            --row-selected-text: #002766;
        }

        html.dark-mode, body.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --text-main: #e6e6e6;
            --text-muted: #8c8c8c;
            --border-color: #434343;
            --border-light: #303030;
            --border-input: #434343;
            --primary: #177ddc;
            --primary-hover: #3c9ae8;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --table-head-bg: #1d1d1d;
            --table-hover: #262626;
            --row-selected: #113559;
            --row-selected-text: #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; background: var(--bg-base); color: var(--text-main); font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 10.5px; -webkit-font-smoothing: antialiased; }
        input, select, textarea, button { font-family: inherit; }

        .ant-container { height: 100%; display: flex; flex-direction: column; padding: 6px; gap: 0; }
        .ant-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .ant-card-head { min-height: 34px; padding: 4px 10px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; background: var(--bg-card); }
        .ant-card-title { font-size: 12px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }

        .ant-badge { font-size: 9px; padding: 1px 6px; border-radius: 10px; font-weight: 600; line-height: 1.2; text-transform: uppercase; display: inline-flex; align-items: center; gap: 3px; }
        .ant-badge-company { background: rgba(24, 144, 255, 0.12); color: var(--primary); border: 1px solid rgba(24, 144, 255, 0.3); }
        .ant-badge-success { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.3); }
        .ant-badge-warning { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.3); }
        .ant-badge-danger { background: rgba(255, 77, 79, 0.12); color: var(--danger); border: 1px solid rgba(255, 77, 79, 0.3); }
        .ant-badge-bod { background: #fffbe6; color: #d48806; border: 1px solid #ffe58f; font-weight: 700; }
        body.dark-mode .ant-badge-bod { background: #2b2111; color: #ffc069; border-color: #594214; }

        /* KPI Dashboard Bar */
        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 6px; padding: 6px 10px; background: var(--table-head-bg); border-bottom: 1px solid var(--border-color); }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; padding: 6px 8px; display: flex; align-items: center; gap: 8px; }
        .kpi-icon { width: 28px; height: 28px; border-radius: 3px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
        .kpi-num { font-size: 14px; font-weight: 700; line-height: 1.1; color: var(--text-main); }
        .kpi-label { font-size: 9.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px; }

        /* Tabs */
        .ant-tabs-nav { display: flex; gap: 2px; padding: 0 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .ant-tab-btn { border: none; border-bottom: 2px solid transparent; background: transparent; color: var(--text-muted); padding: 6px 14px; cursor: pointer; font-size: 10.5px; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }

        /* Toolbar */
        .ant-toolbar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 6px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); }
        .ant-btn { font-family: inherit; font-size: 10px; height: 22px; padding: 0 8px; border-radius: 2px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; line-height: 1.4; transition: all 0.15s; }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color: #fff; }
        .ant-btn-success { background: var(--success); border-color: var(--success); color: #fff; }
        .ant-btn-danger { color: var(--danger); border-color: var(--danger); }
        .ant-btn-danger:hover { background: var(--danger); color: #fff; }
        .ant-btn-sm { height: 20px; padding: 0 6px; font-size: 9.5px; }

        /* Table */
        .grid-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        table.dw { width: 100%; border-collapse: collapse; font-size: 10.5px; table-layout: auto; border: 1px solid var(--border-color); }
        table.dw thead { position: sticky; top: 0; z-index: 10; }
        table.dw th { background: var(--table-head-bg); border: 1px solid var(--border-color); padding: 5px 8px; text-align: left; font-weight: 600; font-size: 10px; color: var(--text-muted); white-space: nowrap; }
        table.dw td { border: 1px solid var(--border-light); padding: 4px 8px; vertical-align: middle; }
        table.dw tbody tr:hover td { background: var(--table-hover); }

        .th-highlight { background: #e6f7ff !important; color: var(--primary) !important; border-bottom: 2px solid var(--primary) !important; font-weight: 700 !important; }
        body.dark-mode .th-highlight { background: #111d2c !important; color: #40a9ff !important; border-bottom: 2px solid #177ddc !important; }

        /* Form & Modals */
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 10px; }
        .modal.active { display: flex; }
        .modal-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; width: 100%; max-width: 520px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); display: flex; flex-direction: column; overflow: hidden; max-height: 90vh; }
        .modal-card.wide { max-width: 680px; }
        .modal-h { padding: 8px 12px; border-bottom: 1px solid var(--border-color); font-weight: 600; font-size: 12px; display: flex; align-items: center; justify-content: space-between; background: var(--table-head-bg); }
        .modal-b { padding: 12px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 8px; }
        .modal-f { padding: 8px 12px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 6px; background: var(--table-head-bg); }

        .fld-row { display: grid; grid-template-columns: 110px 1fr; gap: 6px; align-items: center; }
        .fld-row label { text-align: right; color: var(--text-muted); font-size: 10.5px; font-weight: 500; }
        .fld-row input, .fld-row select, .fld-row textarea { width: 100%; border: 1px solid var(--border-input); background: var(--bg-input); color: var(--text-main); padding: 3px 6px; height: 24px; border-radius: 2px; outline: none; font-size: 10.5px; }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15); }
        .inline-box { display: flex; gap: 6px; align-items: center; }

        .op-badge { font-size: 9px; padding: 1px 4px; border-radius: 2px; font-weight: 600; margin-right: 2px; }
        .op-yes { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .op-no { background: transparent; color: #bfbfbf; }

        /* Canvas Signature */
        .sig-canvas-wrap { border: 1px solid var(--border-color); background: #ffffff; border-radius: 3px; position: relative; width: 100%; height: 140px; }
        .sig-canvas { width: 100%; height: 100%; cursor: crosshair; }
        .sig-thumb { max-height: 28px; max-width: 70px; object-fit: contain; vertical-align: middle; border: 1px solid #eee; border-radius: 2px; background: #fff; padding: 1px; }

        /* Footer Status */
        .ant-card-footer { padding: 4px 10px; font-size: 10px; color: var(--text-muted); border-top: 1px solid var(--border-color); background: var(--table-head-bg); display: flex; justify-content: space-between; align-items: center; }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Card Header -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-sitemap" style="color:var(--primary);"></i>
                <span>Master Departemen &amp; Otoritas Approval PR / PO</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div class="inline-box">
                <span id="syncStatusText" style="font-size:10px; color:var(--text-muted);"><i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Master Departemen &amp; Approval</span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Mini Dashboard KPI -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);"><i class="fa-solid fa-building-user"></i></div>
                <div>
                    <div class="kpi-num" id="kpiTotal">0</div>
                    <div class="kpi-label">Total Bagian / Dept</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-warehouse"></i></div>
                <div>
                    <div class="kpi-num" id="kpiGudang">0</div>
                    <div class="kpi-label">Bagian Gudang</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);"><i class="fa-solid fa-industry"></i></div>
                <div>
                    <div class="kpi-num" id="kpiProd">0</div>
                    <div class="kpi-label">Dept Produksi</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;"><i class="fa-solid fa-signature"></i></div>
                <div>
                    <div class="kpi-num" id="kpiApprovers">0</div>
                    <div class="kpi-label">Otoritas Approval PR/PO</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="kpi-num" id="kpiAktif">0</div>
                    <div class="kpi-label">Departemen Aktif</div>
                </div>
            </div>
        </div>

        <!-- Tabs Navigasi -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" id="tabBtnDept" onclick="switchTab('dept')"><i class="fa-solid fa-list-check"></i> 1. Master Departemen &amp; Bagian</button>
            <button type="button" class="ant-tab-btn" id="tabBtnApproval" onclick="switchTab('approval')"><i class="fa-solid fa-user-shield"></i> 2. Matriks Otoritas Approval &amp; TTD Digital</button>
        </div>

        <!-- TAB 1: MASTER DEPARTEMEN -->
        <div id="viewDept" style="display:flex; flex-direction:column; flex:1; min-height:0;">
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddDeptModal()"><i class="fa-solid fa-plus"></i> Tambah Departemen</button>
                <div style="height:14px; width:1px; background:var(--border-color); margin:0 4px;"></div>
                <label>Filter:</label>
                <select id="filterStatus" style="width:105px; height:22px; font-size:10px;" onchange="loadDeptList(1)">
                    <option value="">Semua Status</option>
                    <option value="Y" selected>Hanya Aktif</option>
                    <option value="N">Non-Aktif</option>
                </select>
                <input type="text" id="searchDept" placeholder="Cari Kode, Nama Dept, PIC..." style="width:180px; height:22px; font-size:10.5px;" onkeydown="if(event.key==='Enter') loadDeptList(1)">
                <button type="button" class="ant-btn" onclick="loadDeptList(1)"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
                <div style="margin-left:auto; display:flex; gap:4px;">
                    <button type="button" class="ant-btn" onclick="exportDeptExcel()"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel</button>
                    <button type="button" class="ant-btn" onclick="loadDeptList(currentPage)"><i class="fa-solid fa-rotate"></i> Refresh</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="dw" id="tblDept">
                    <thead>
                        <tr>
                            <th style="width:34px; text-align:center;">#</th>
                            <th class="th-highlight" style="width:130px;">Kode Departemen</th>
                            <th>Nama Departemen / Divisi</th>
                            <th style="width:130px;">Penanggung Jawab (PIC)</th>
                            <th style="width:85px; text-align:center;">Gudang</th>
                            <th style="width:170px; text-align:center;">Cakupan Operasional</th>
                            <th style="width:130px; text-align:center;">Tim Approval</th>
                            <th style="width:75px; text-align:center;">Status</th>
                            <th style="width:130px;">Audit Trail</th>
                            <th style="width:145px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyDept">
                        <tr><td colspan="10" class="muted" style="padding:16px; text-align:center;">Memuat data departemen...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="ant-card-footer">
                <span id="pageInfoDept">Menampilkan 0 data</span>
                <div class="inline-box" id="pageBtnsDept"></div>
            </div>
        </div>

        <!-- TAB 2: MATRIKS OTORITAS APPROVAL (bc_po_user) -->
        <div id="viewApproval" style="display:none; flex-direction:column; flex:1; min-height:0;">
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddApprovalModal()"><i class="fa-solid fa-user-plus"></i> Tambah Otoritas Approval</button>
                <div style="height:14px; width:1px; background:var(--border-color); margin:0 4px;"></div>
                <label>Dokumen:</label>
                <select id="filterApprDoc" style="width:85px; height:22px; font-size:10px;" onchange="loadApprovalList()">
                    <option value="">Semua</option>
                    <option value="PO" selected>PO</option>
                    <option value="PR">PR</option>
                </select>
                <label>Dept:</label>
                <select id="filterApprDept" style="width:130px; height:22px; font-size:10px;" onchange="loadApprovalList()">
                    <option value="">Semua Bagian</option>
                    <?php foreach ($allDepts as $ad): ?>
                    <option value="<?= htmlspecialchars($ad['kode']) ?>"><?= htmlspecialchars($ad['kode']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="searchAppr" placeholder="Cari User, Nama, Jabatan..." style="width:160px; height:22px; font-size:10.5px;" onkeydown="if(event.key==='Enter') loadApprovalList()">
                <button type="button" class="ant-btn" onclick="loadApprovalList()"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <div style="margin-left:auto; display:flex; gap:4px;">
                    <button type="button" class="ant-btn" onclick="exportApprovalExcel()"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel</button>
                    <button type="button" class="ant-btn" onclick="loadApprovalList()"><i class="fa-solid fa-rotate"></i> Refresh</button>
                </div>
            </div>
            <div class="grid-wrap">
                <table class="dw" id="tblApproval">
                    <thead>
                        <tr>
                            <th style="width:34px; text-align:center;">#</th>
                            <th style="width:65px; text-align:center;">Dokumen</th>
                            <th class="th-highlight" style="width:120px;">Departemen</th>
                            <th style="width:110px;">User ID</th>
                            <th>Nama Pejabat &amp; Jabatan</th>
                            <th style="width:145px; text-align:center;">Level Approval</th>
                            <th style="width:120px; text-align:right;">Plafon Nominal</th>
                            <th style="width:110px; text-align:center;">Wewenang BOD</th>
                            <th style="width:90px; text-align:center;">TTD Digital</th>
                            <th style="width:65px; text-align:center;">Status</th>
                            <th style="width:140px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyApproval">
                        <tr><td colspan="11" class="muted" style="padding:16px; text-align:center;">Memuat matriks persetujuan...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="ant-card-footer">
                <span id="pageInfoAppr">Menampilkan 0 data otoritas</span>
                <span style="font-size:9.5px; color:var(--text-muted);">Alur: Pembuat -> Spv (Lvl 1) -> Manager (Lvl 2) -> GM/Fin (Lvl 3) -> Direktur/BOD (Final)</span>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ENTRI / EDIT DEPARTEMEN -->
<div class="modal" id="modalDept">
    <div class="modal-card">
        <div class="modal-h">
            <span id="modalDeptTitle"><i class="fa-solid fa-building"></i> Tambah Departemen Baru</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeDeptModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <input type="hidden" id="deptOldKode">
            <div class="fld-row">
                <label>Kode Bagian:</label>
                <input type="text" id="deptKode" maxlength="30" placeholder="Contoh: PRODUKSI, GA, QC, IT" style="text-transform:uppercase; font-weight:600;">
            </div>
            <div class="fld-row">
                <label>Nama Departemen:</label>
                <input type="text" id="deptNama" maxlength="100" placeholder="Nama lengkap divisi atau bagian...">
            </div>
            <div class="fld-row">
                <label>Penanggung Jawab:</label>
                <input type="text" id="deptPic" maxlength="100" placeholder="Nama PIC / Kepala Bagian">
            </div>
            <div class="fld-row">
                <label>Status Gudang:</label>
                <label style="display:flex; gap:6px; align-items:center; cursor:pointer;">
                    <input type="checkbox" id="deptGudang" value="Y" style="width:auto; height:auto;"> Merupakan Bagian / Lokasi Gudang
                </label>
            </div>
            <div class="fld-row" style="align-items:flex-start;">
                <label style="margin-top:4px;">Cakupan Operasi:</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:4px; font-size:10px;">
                    <label style="display:flex; gap:4px; align-items:center; cursor:pointer;"><input type="checkbox" id="deptProd" value="Y" style="width:auto; height:auto;"> Produksi (PROD)</label>
                    <label style="display:flex; gap:4px; align-items:center; cursor:pointer;"><input type="checkbox" id="deptWip" value="Y" style="width:auto; height:auto;"> Barang Proses (WIP)</label>
                    <label style="display:flex; gap:4px; align-items:center; cursor:pointer;"><input type="checkbox" id="deptFg" value="Y" style="width:auto; height:auto;"> Barang Jadi (FG)</label>
                    <label style="display:flex; gap:4px; align-items:center; cursor:pointer;"><input type="checkbox" id="deptCons" value="Y" style="width:auto; height:auto;"> Habis Pakai (CONS)</label>
                    <label style="display:flex; gap:4px; align-items:center; cursor:pointer;"><input type="checkbox" id="deptScrap" value="Y" style="width:auto; height:auto;"> Limbah / Sisa (SCRAP)</label>
                </div>
            </div>
            <div class="fld-row">
                <label>Status Aktif:</label>
                <select id="deptAktif">
                    <option value="Y">AKTIF</option>
                    <option value="N">NON-AKTIF</option>
                </select>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitDept()"><i class="fa-solid fa-floppy-disk"></i> Simpan Data</button>
            <button type="button" class="ant-btn" onclick="closeDeptModal()">Batal</button>
        </div>
    </div>
</div>

<!-- MODAL: ENTRI / EDIT OTORITAS APPROVAL (bc_po_user) -->
<div class="modal" id="modalApproval">
    <div class="modal-card wide">
        <div class="modal-h">
            <span id="modalApprTitle"><i class="fa-solid fa-user-shield"></i> Tambah Otoritas Approval</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeApprModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <input type="hidden" id="apprId">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Dokumen:</label>
                    <select id="apprDocType">
                        <option value="PO">Purchase Order (PO)</option>
                        <option value="PR">Purchase Requisition (PR)</option>
                        <option value="ALL">Keduanya (PO &amp; PR)</option>
                    </select>
                </div>
                <div class="fld-row">
                    <label>Departemen:</label>
                    <select id="apprDeptKode">
                        <option value="*">* (Semua Bagian / Direksi BOD)</option>
                        <?php foreach ($allDepts as $ad): ?>
                        <option value="<?= htmlspecialchars($ad['kode']) ?>"><?= htmlspecialchars($ad['kode'] . ' - ' . $ad['nama_dept']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>User ID Akun:</label>
                    <div class="inline-box">
                        <input type="text" id="apprUserId" placeholder="User ID login..." style="flex:1;">
                        <select id="selLoginUser" onchange="onLoginUserSelected(this)" style="width:110px;">
                            <option value="">Pilih Akun...</option>
                        </select>
                    </div>
                </div>
                <div class="fld-row">
                    <label>Nama Pejabat:</label>
                    <input type="text" id="apprNamaLengkap" placeholder="Nama lengkap pejabat...">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Jabatan:</label>
                    <input type="text" id="apprJabatan" placeholder="Contoh: Manager Produksi, Direktur Utama">
                </div>
                <div class="fld-row">
                    <label>Level Approval:</label>
                    <select id="apprLevel" onchange="onApprLevelChange(this.value)">
                        <option value="PEMBUAT">PEMBUAT (Staff / Requester)</option>
                        <option value="LEVEL_1">LEVEL 1 (Supervisor / Section Head)</option>
                        <option value="LEVEL_2" selected>LEVEL 2 (Manager Departemen / Purchasing)</option>
                        <option value="LEVEL_3">LEVEL 3 (General Manager / Finance)</option>
                        <option value="FINAL">FINAL (Direktur / Board of Directors)</option>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Urutan Approval:</label>
                    <input type="number" id="apprUrutan" min="1" max="9" value="2" style="width:70px;">
                </div>
                <div class="fld-row">
                    <label>Status:</label>
                    <select id="apprAktif">
                        <option value="Y">AKTIF</option>
                        <option value="N">NON-AKTIF</option>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Plafon Min (Rp):</label>
                    <input type="number" id="apprLimitMin" value="0" placeholder="0">
                </div>
                <div class="fld-row">
                    <label>Plafon Max (Rp):</label>
                    <input type="number" id="apprLimitMax" value="0" placeholder="0 = Unlimited">
                </div>
            </div>

            <div class="fld-row">
                <label>Kuasa BOD:</label>
                <label style="display:flex; gap:6px; align-items:center; cursor:pointer;">
                    <input type="checkbox" id="apprBodDelegate" value="Y" style="width:auto; height:auto;">
                    <span>Diberi wewenang khusus / Kuasa Direksi untuk bertindak atas nama Board of Directors (BOD)</span>
                </label>
            </div>

            <div class="fld-row" style="align-items:flex-start;">
                <label style="margin-top:6px;">Tanda Tangan:</label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <div id="apprSigPreviewBox" style="width:100px; height:50px; border:1px solid var(--border-color); border-radius:3px; display:flex; align-items:center; justify-content:center; background:#fff;">
                        <span class="muted" style="font-size:9px;">(Kosong)</span>
                    </div>
                    <div>
                        <button type="button" class="ant-btn ant-btn-sm" onclick="openSignatureModalFromAppr()"><i class="fa-solid fa-pen-nib"></i> Buka Pad TTD Digital</button>
                        <input type="hidden" id="apprTtdDigital">
                        <div style="font-size:9px; color:var(--text-muted); margin-top:2px;">Tanda tangan ini akan otomatis tertera di formulir cetak PO/PR resmi.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitApproval()"><i class="fa-solid fa-floppy-disk"></i> Simpan Otoritas</button>
            <button type="button" class="ant-btn" onclick="closeApprModal()">Batal</button>
        </div>
    </div>
</div>

<!-- MODAL: PAD TANDA TANGAN DIGITAL (STUDIO TTD) -->
<div class="modal" id="modalSignature">
    <div class="modal-card" style="max-width:480px;">
        <div class="modal-h">
            <span><i class="fa-solid fa-signature"></i> Studio Tanda Tangan Digital</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeSignatureModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="align-items:center; text-align:center;">
            <p style="font-size:10.5px; color:var(--text-muted); margin-bottom:4px;">Goreskan tanda tangan Anda pada bidang kanvas di bawah menggunakan mouse, touchpad, atau stylus:</p>
            <div class="sig-canvas-wrap">
                <canvas id="sigCanvas" class="sig-canvas"></canvas>
            </div>
            <div style="display:flex; justify-content:space-between; width:100%; margin-top:6px; align-items:center;">
                <div class="inline-box">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="clearSigCanvas()"><i class="fa-solid fa-eraser"></i> Hapus Kanvas</button>
                    <label class="ant-btn ant-btn-sm" style="cursor:pointer;" title="Upload File Gambar PNG">
                        <i class="fa-solid fa-upload"></i> Upload PNG
                        <input type="file" accept="image/png, image/jpeg" style="display:none;" onchange="handleSigUpload(event)">
                    </label>
                </div>
                <span style="font-size:9.5px; color:#52c41a;"><i class="fa-solid fa-shield-halved"></i> Audit CEISA 4.0 Verified</span>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn ant-btn-primary" onclick="applySignature()"><i class="fa-solid fa-check"></i> Terapkan Tanda Tangan</button>
            <button type="button" class="ant-btn" onclick="closeSignatureModal()">Batal</button>
        </div>
    </div>
</div>

<!-- MODAL: PANDUAN LENGKAP MODUL -->
<div class="modal" id="modalModuleGuide">
    <div class="modal-card" style="max-width:820px; max-height:88vh;">
        <div class="modal-h" style="background:linear-gradient(135deg, rgba(24,144,255,0.08), rgba(24,144,255,0.02));">
            <span style="font-weight:700; color:var(--primary); font-size:13px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-book-open"></i> Panduan Lengkap: Master Departemen &amp; Otoritas Approval PR / PO
            </span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="padding:16px 20px; overflow-y:auto; line-height:1.55; font-size:11px;">
            <div style="background:rgba(24,144,255,0.06); border-left:3px solid var(--primary); padding:10px 14px; border-radius:4px; margin-bottom:16px;">
                <strong style="color:var(--primary); font-size:11.5px;">SOP &amp; Tata Kelola Departemen &amp; Approval Pengadaan:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main);">Modul ini mengatur struktur bagian internal perusahaan serta menetapkan matriks wewenang persetujuan (approval hierarchy) berjenjang, plafon nominal, dan tanda tangan digital untuk Purchase Requisition (PR) dan Purchase Order (PO).</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#1890ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-building"></i> 1. Master Departemen &amp; Bagian
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Kode Bagian:</strong> Tentukan kode unik kapital (contoh: <code>PRODUKSI</code>, <code>MAINTENANCE</code>, <code>PPIC</code>, <code>GA</code>, <code>IT</code>, <code>QC</code>).</li>
                        <li><strong>Status Gudang:</strong> Centang jika bagian ini mengelola persediaan fisik yang wajib tercatat di IT Inventory.</li>
                        <li><strong>Cakupan Operasi:</strong>
                            <div style="font-size:10px; color:var(--text-muted); margin-top:3px;">
                                • <strong>PROD:</strong> Unit lini mesin / proses produksi.<br>
                                • <strong>WIP:</strong> Lokasi transit barang dalam proses.<br>
                                • <strong>FG:</strong> Gudang barang jadi siap kirim.<br>
                                • <strong>CONS:</strong> Pemakaian bahan habis pakai pabrik.<br>
                                • <strong>SCRAP:</strong> Lokasi pengumpulan limbah/sisa.
                            </div>
                        </li>
                    </ul>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#52c41a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-user-shield"></i> 2. Matriks Otoritas Persetujuan
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Hirarki 5 Tingkat:</strong>
                            <div style="font-size:10px; color:var(--text-muted); margin-top:3px;">
                                1. <code>PEMBUAT</code> : Staf/Admin entri PR / PO.<br>
                                2. <code>LEVEL_1</code> : Supervisor / Team Leader.<br>
                                3. <code>LEVEL_2</code> : Manager Departemen Pengaju.<br>
                                4. <code>LEVEL_3</code> : General Manager / Finance.<br>
                                5. <code>FINAL</code> : Direktur / Board of Directors (BOD).
                            </div>
                        </li>
                        <li><strong>Plafon Anggaran:</strong> Batas nominal minimum s.d. maksimum yang diizinkan untuk level pejabat terkait.</li>
                        <li><strong>Kuasa BOD:</strong> Otoritas pengganti tanda tangan Direksi atas departemen tertentu.</li>
                    </ul>
                </div>
            </div>

            <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card); margin-bottom:14px;">
                <div style="font-weight:700; color:#722ed1; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-signature"></i> 3. Studio Tanda Tangan Digital &amp; Dokumen Cetak
                </div>
                <p style="margin:0 0 6px 0; color:var(--text-main);">Setiap pejabat approval dapat memiliki spesimen tanda tangan digital yang sah secara internal:</p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:10px; color:var(--text-muted);">
                    <div style="background:var(--bg-base); padding:8px; border-radius:4px;">
                        <strong style="color:var(--text-main);">A. Gambar Langsung (Canvas):</strong><br>
                        Tandatangani langsung menggunakan mouse atau layar sentuh pada modal Studio Tanda Tangan, lalu klik "Terapkan Tanda Tangan".
                    </div>
                    <div style="background:var(--bg-base); padding:8px; border-radius:4px;">
                        <strong style="color:var(--text-main);">B. Unggah Berkas Gambar (PNG/JPG):</strong><br>
                        Gunakan tombol "Upload File Gambar" untuk mengunggah pindaian tanda tangan berlatar belakang transparan atau putih.
                    </div>
                </div>
                <div style="margin-top:8px; font-size:10px; color:#52c41a;">
                    <i class="fa-solid fa-circle-check"></i> Tanda tangan otomatis disematkan pada kolom persetujuan saat formulir PO &amp; PR dicetak.
                </div>
            </div>

            <div style="background:rgba(250,173,20,0.08); border:1px solid rgba(250,173,20,0.3); border-radius:6px; padding:10px 14px;">
                <strong style="color:#d48806;"><i class="fa-solid fa-triangle-exclamation"></i> Kepatuhan Audit CEISA 4.0 &amp; IT Inventory:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main); font-size:10.5px;">Departemen yang berstatus Gudang wajib konsisten dengan kode lokasi di modul Mutasi Bahan Baku/Barang Jadi. Perubahan atau penghapusan departemen yang sudah memiliki histori transaksi PR/PO akan mencatat jejak audit pengguna dan riwayat transaksi sistem.</p>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()"><i class="fa-solid fa-check"></i> Mengerti &amp; Tutup</button>
        </div>
    </div>
</div>

<script src="/assets/sweetalert2.all.min.js"></script>
<script src="/assets/sweetalert-theme.js?v=20261025"></script>
<script>
const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
let activeTab = 'dept';
let currentPage = 1;
let deptCache = [];
let approvalCache = [];
let sigTargetId = null;

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function showSuccess(msg) {
    if (window.Swal) Swal.fire({ icon: 'success', title: 'Berhasil', text: msg, timer: 1800, showConfirmButton: false });
    else alert(msg);
}

function showError(msg) {
    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: msg });
    else alert('Error: ' + msg);
}

function showWarning(msg) {
    if (window.Swal) Swal.fire({ icon: 'warning', title: 'Peringatan', text: msg });
    else alert(msg);
}

async function showConfirm(text, title = 'Konfirmasi', confirmBtn = 'Ya, Lanjutkan') {
    if (!window.Swal) return confirm(text);
    const r = await Swal.fire({
        title: title,
        text: text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: confirmBtn,
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ff4d4f'
    });
    return r.isConfirmed;
}

// Sinkronisasi Tema Otomatis dari Portal Utama (kt-header)
window.addEventListener('storage', function(e) {
    if (e.key === 'ceisa_theme') {
        const isDark = (e.newValue === 'dark');
        document.documentElement.classList.toggle('dark-mode', isDark);
        document.body.classList.toggle('dark-mode', isDark);
    }
});
window.addEventListener('message', function(ev) {
    if (ev.data && (ev.data.type === 'SET_THEME' || ev.data.action === 'SET_THEME')) {
        const isDark = (ev.data.theme === 'dark');
        document.documentElement.classList.toggle('dark-mode', isDark);
        document.body.classList.toggle('dark-mode', isDark);
    }
});

function openModuleGuide() {
    const modal = document.getElementById('modalModuleGuide');
    if (modal) modal.classList.add('active');
}

function closeModuleGuide() {
    const modal = document.getElementById('modalModuleGuide');
    if (modal) modal.classList.remove('active');
}

// Navigasi Tab
function switchTab(t) {
    activeTab = t;
    document.getElementById('tabBtnDept').classList.toggle('active', t === 'dept');
    document.getElementById('tabBtnApproval').classList.toggle('active', t === 'approval');
    document.getElementById('viewDept').style.display = (t === 'dept') ? 'flex' : 'none';
    document.getElementById('viewApproval').style.display = (t === 'approval') ? 'flex' : 'none';

    if (t === 'dept') loadDeptList(currentPage);
    else loadApprovalList();
}

// =========================================================================
// 1. LOGIKA TAB MASTER DEPARTEMEN
// =========================================================================
function loadDeptList(page = 1) {
    currentPage = page;
    const search = document.getElementById('searchDept').value.trim();
    const status = document.getElementById('filterStatus').value;

    const q = new URLSearchParams({
        action: 'list',
        page: String(page),
        limit: '50',
        search: search,
        status: status
    });
    if (CURRENT_CMP_ID) q.set('cmp_id', CURRENT_CMP_ID);

    fetch('department.php?' + q.toString())
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                showError(res.message || 'Gagal memuat data departemen');
                return;
            }
            deptCache = res.data || [];
            renderDeptTable(deptCache);
            renderDeptKpi(res.kpi || {});
            document.getElementById('pageInfoDept').textContent = `Menampilkan ${deptCache.length} dari total ${res.total} departemen`;
        })
        .catch(() => showError('Gagal menghubungi server'));
}

function renderDeptKpi(kpi) {
    document.getElementById('kpiTotal').textContent = kpi.total_all || '0';
    document.getElementById('kpiGudang').textContent = kpi.total_gudang || '0';
    document.getElementById('kpiProd').textContent = kpi.total_prod || '0';
    document.getElementById('kpiAktif').textContent = kpi.total_aktif || '0';
    document.getElementById('kpiApprovers').textContent = kpi.total_approvers || '0';
}

function renderDeptTable(rows) {
    const tbody = document.getElementById('tbodyDept');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="10" class="muted" style="padding:16px; text-align:center;">Tidak ada data departemen yang cocok dengan filter ini.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map((r, i) => {
        const isAktif = (r.aktif === 'Y' || !r.aktif);
        const opBadges = [
            r.prod === 'Y' ? '<span class="op-badge op-yes">PROD</span>' : '<span class="op-badge op-no">PROD</span>',
            r.wip === 'Y' ? '<span class="op-badge op-yes">WIP</span>' : '<span class="op-badge op-no">WIP</span>',
            r.fg === 'Y' ? '<span class="op-badge op-yes">FG</span>' : '<span class="op-badge op-no">FG</span>',
            r.cons === 'Y' ? '<span class="op-badge op-yes">CONS</span>' : '<span class="op-badge op-no">CONS</span>',
            r.scrap === 'Y' ? '<span class="op-badge op-yes">SCRAP</span>' : '<span class="op-badge op-no">SCRAP</span>'
        ].join(' ');

        const apprCount = parseInt(r.approver_count || 0);
        let apprBadge = `<span class="ant-badge ${apprCount > 0 ? 'ant-badge-company' : 'ant-badge-warning'}" style="cursor:pointer;" onclick="viewDeptApproval('${escapeHtml(r.kode)}')"><i class="fa-solid fa-users"></i> ${apprCount} Approver</span>`;

        return `
            <tr>
                <td style="text-align:center;">${i + 1}</td>
                <td><strong>${escapeHtml(r.kode)}</strong></td>
                <td>${escapeHtml(r.nama_dept)}</td>
                <td>${escapeHtml(r.pic || '-')}</td>
                <td style="text-align:center;">${r.gudang === 'Y' ? '<span class="ant-badge ant-badge-success">GUDANG</span>' : '<span class="muted">-</span>'}</td>
                <td style="text-align:center;">${opBadges}</td>
                <td style="text-align:center;">${apprBadge}</td>
                <td style="text-align:center;">${isAktif ? '<span class="ant-badge ant-badge-success">AKTIF</span>' : '<span class="ant-badge ant-badge-danger">NON-AKTIF</span>'}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.setby || '-')}</td>
                <td style="text-align:center;">
                    <div class="inline-box" style="justify-content:center;">
                        <button type="button" class="ant-btn ant-btn-sm" title="Kelola Approver Dept Ini" onclick="viewDeptApproval('${escapeHtml(r.kode)}')"><i class="fa-solid fa-user-shield" style="color:var(--primary);"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm" title="Edit Departemen" onclick="editDept('${escapeHtml(r.kode)}')"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" title="Hapus Departemen" onclick="deleteDept('${escapeHtml(r.kode)}')"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    if (window.deptGrid) {
        window.deptGrid.refresh();
    } else if (typeof AntEnterpriseGrid !== 'undefined') {
        window.deptGrid = new AntEnterpriseGrid('#tblDept', {
            gridId: 'erp_dept_list',
            enableSort: true,
            enableResize: true,
            enableContextMenu: true,
            enablePagination: false,
            skipCols: [0, 9],
            exportTitle: 'Master_Departemen',
            onEdit: (tr) => {
                const kode = tr.cells[1] ? tr.cells[1].innerText.trim() : '';
                if (kode) editDept(kode);
            }
        });
    }
}

function openAddDeptModal() {
    document.getElementById('modalDeptTitle').innerHTML = '<i class="fa-solid fa-building"></i> Tambah Departemen Baru';
    document.getElementById('deptOldKode').value = '';
    document.getElementById('deptKode').value = '';
    document.getElementById('deptKode').readOnly = false;
    document.getElementById('deptNama').value = '';
    document.getElementById('deptPic').value = '';
    document.getElementById('deptGudang').checked = false;
    document.getElementById('deptProd').checked = false;
    document.getElementById('deptWip').checked = false;
    document.getElementById('deptFg').checked = false;
    document.getElementById('deptCons').checked = false;
    document.getElementById('deptScrap').checked = false;
    document.getElementById('deptAktif').value = 'Y';
    document.getElementById('modalDept').classList.add('active');
    document.getElementById('deptKode').focus();
}

function editDept(kode) {
    const d = deptCache.find(x => x.kode === kode);
    if (!d) return;
    document.getElementById('modalDeptTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Departemen : ' + escapeHtml(d.kode);
    document.getElementById('deptOldKode').value = d.kode;
    document.getElementById('deptKode').value = d.kode;
    document.getElementById('deptKode').readOnly = true;
    document.getElementById('deptNama').value = d.nama_dept || '';
    document.getElementById('deptPic').value = d.pic || '';
    document.getElementById('deptGudang').checked = (d.gudang === 'Y');
    document.getElementById('deptProd').checked = (d.prod === 'Y');
    document.getElementById('deptWip').checked = (d.wip === 'Y');
    document.getElementById('deptFg').checked = (d.fg === 'Y');
    document.getElementById('deptCons').checked = (d.cons === 'Y');
    document.getElementById('deptScrap').checked = (d.scrap === 'Y');
    document.getElementById('deptAktif').value = (d.aktif === 'N') ? 'N' : 'Y';
    document.getElementById('modalDept').classList.add('active');
    document.getElementById('deptNama').focus();
}

function closeDeptModal() {
    document.getElementById('modalDept').classList.remove('active');
}

function submitDept() {
    const kode = document.getElementById('deptKode').value.trim();
    const nama = document.getElementById('deptNama').value.trim();
    if (!kode || !nama) {
        showWarning('Kode dan Nama Departemen wajib diisi!');
        return;
    }

    const fd = new FormData();
    fd.append('old_kode', document.getElementById('deptOldKode').value);
    fd.append('kode', kode);
    fd.append('nama_dept', nama);
    fd.append('pic', document.getElementById('deptPic').value.trim());
    fd.append('gudang', document.getElementById('deptGudang').checked ? 'Y' : 'N');
    fd.append('prod', document.getElementById('deptProd').checked ? 'Y' : 'N');
    fd.append('wip', document.getElementById('deptWip').checked ? 'Y' : 'N');
    fd.append('fg', document.getElementById('deptFg').checked ? 'Y' : 'N');
    fd.append('cons', document.getElementById('deptCons').checked ? 'Y' : 'N');
    fd.append('scrap', document.getElementById('deptScrap').checked ? 'Y' : 'N');
    fd.append('aktif', document.getElementById('deptAktif').value);
    if (CURRENT_CMP_ID) fd.append('cmp_id', CURRENT_CMP_ID);

    fetch('department.php?action=save' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            closeDeptModal();
            showSuccess(res.message);
            loadDeptList(currentPage);
        } else {
            showError(res.message || 'Gagal menyimpan data');
        }
    })
    .catch(() => showError('Gagal menghubungi server'));
}

async function deleteDept(kode) {
    const ok = await showConfirm(`Hapus Departemen "${kode}"?\nPastikan tidak ada dokumen transaksi aktif yang terikat.`, 'Konfirmasi Hapus');
    if (!ok) return;

    const fd = new FormData();
    fd.append('kode', kode);
    if (CURRENT_CMP_ID) fd.append('cmp_id', CURRENT_CMP_ID);

    fetch('department.php?action=delete' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            showSuccess(res.message);
            loadDeptList(currentPage);
        } else {
            showError(res.message || 'Gagal menghapus');
        }
    })
    .catch(() => showError('Gagal menghubungi server'));
}

function exportDeptExcel() {
    if (!deptCache.length) {
        showWarning('Tidak ada data departemen untuk diekspor!');
        return;
    }
    const exportData = deptCache.map((d, i) => ({
        "No": i + 1,
        "Kode Departemen": d.kode,
        "Nama Departemen": d.nama_dept,
        "PIC / Penanggung Jawab": d.pic || '',
        "Bagian Gudang": d.gudang === 'Y' ? 'YA' : 'TIDAK',
        "Operasi PROD": d.prod === 'Y' ? 'YA' : 'TIDAK',
        "Operasi WIP": d.wip === 'Y' ? 'YA' : 'TIDAK',
        "Operasi FG": d.fg === 'Y' ? 'YA' : 'TIDAK',
        "Operasi CONS": d.cons === 'Y' ? 'YA' : 'TIDAK',
        "Operasi SCRAP": d.scrap === 'Y' ? 'YA' : 'TIDAK',
        "Status": d.aktif === 'N' ? 'NON-AKTIF' : 'AKTIF',
        "Audit Trail": d.setby || ''
    }));

    const ws = XLSX.utils.json_to_sheet(exportData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Master Departemen");
    XLSX.writeFile(wb, `Master_Departemen_${new Date().toISOString().slice(0,10)}.xlsx`);
}

// Shortcut melihat approval dari baris departemen
function viewDeptApproval(kode) {
    document.getElementById('filterApprDept').value = kode;
    switchTab('approval');
}

// =========================================================================
// 2. LOGIKA TAB MATRIKS OTORITAS APPROVAL (bc_po_user)
// =========================================================================
function loadApprovalList() {
    const doc = document.getElementById('filterApprDoc').value;
    const dept = document.getElementById('filterApprDept').value;
    const search = document.getElementById('searchAppr').value.trim();

    const q = new URLSearchParams({
        action: 'list_approval_users',
        doc_filter: doc,
        dept_filter: dept,
        search: search
    });
    if (CURRENT_CMP_ID) q.set('cmp_id', CURRENT_CMP_ID);

    fetch('department.php?' + q.toString())
        .then(r => r.json())
        .then(res => {
            if (res.status !== 'OK') {
                showError(res.message || 'Gagal memuat matriks approval');
                return;
            }
            approvalCache = res.data || [];
            renderApprovalTable(approvalCache);
            document.getElementById('pageInfoAppr').textContent = `Menampilkan ${approvalCache.length} data otoritas approval`;
        })
        .catch(() => showError('Gagal menghubungi server'));
}

function renderApprovalTable(rows) {
    const tbody = document.getElementById('tbodyApproval');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="11" class="muted" style="padding:16px; text-align:center;">Belum ada otoritas approval terdaftar pada kriteria filter ini.</td></tr>';
        return;
    }

    const levelBadgeMap = {
        'PEMBUAT': '<span class="ant-badge" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff;">PEMBUAT (Staff)</span>',
        'LEVEL_1': '<span class="ant-badge" style="background:#f9f0ff; color:#722ed1; border:1px solid #d3adf7;">LEVEL 1 (Spv)</span>',
        'LEVEL_2': '<span class="ant-badge" style="background:#f6ffed; color:#52c41a; border:1px solid #b7eb8f;">LEVEL 2 (Manager)</span>',
        'LEVEL_3': '<span class="ant-badge" style="background:#fffbe6; color:#faad14; border:1px solid #ffe58f;">LEVEL 3 (GM/Fin)</span>',
        'FINAL':   '<span class="ant-badge ant-badge-bod"><i class="fa-solid fa-crown"></i> FINAL (BOD/Direktur)</span>'
    };

    tbody.innerHTML = rows.map((r, i) => {
        const isAktif = (r.aktif === 'Y' || !r.aktif);
        const docBadge = r.doc_type === 'PO' ? '<span class="ant-badge ant-badge-company">PO</span>' : (r.doc_type === 'PR' ? '<span class="ant-badge" style="background:#f9f0ff; color:#722ed1; border:1px solid #d3adf7;">PR</span>' : '<span class="ant-badge ant-badge-success">PO &amp; PR</span>');
        const deptLabel = r.dept_kode === '*' ? '<span class="ant-badge ant-badge-bod">SEMUA DEPT (BOD)</span>' : `<strong>${escapeHtml(r.dept_kode)}</strong>`;
        
        let limitLabel = 'Unlimited';
        const min = parseFloat(r.limit_min || 0);
        const max = parseFloat(r.limit_max || 0);
        if (max > 0) limitLabel = `s/d Rp ${(max / 1000000).toLocaleString()} Jt`;
        else if (min > 0) limitLabel = `> Rp ${(min / 1000000).toLocaleString()} Jt`;

        const bodDelegate = (r.is_bod_delegate === 'Y') 
            ? '<span class="ant-badge ant-badge-bod"><i class="fa-solid fa-star"></i> Kuasa BOD</span>' 
            : '<span class="muted">-</span>';

        let ttdPreview = '<span class="muted" style="font-size:9px;">Belum Ada</span>';
        if (r.ttd_digital) {
            ttdPreview = `<img src="${r.ttd_digital}" class="sig-thumb" title="Tanda Tangan Digital Terverifikasi">`;
        }

        return `
            <tr>
                <td style="text-align:center;">${i + 1}</td>
                <td style="text-align:center;">${docBadge}</td>
                <td>${deptLabel}</td>
                <td><code>${escapeHtml(r.user_id)}</code></td>
                <td>
                    <strong>${escapeHtml(r.nama_lengkap)}</strong><br>
                    <span style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.jabatan || 'Pejabat Otoritas')}</span>
                </td>
                <td style="text-align:center;">${levelBadgeMap[r.level_approval] || r.level_approval}</td>
                <td style="text-align:right; font-weight:600;">${limitLabel}</td>
                <td style="text-align:center;">${bodDelegate}</td>
                <td style="text-align:center;">${ttdPreview}</td>
                <td style="text-align:center;">${isAktif ? '<span class="ant-badge ant-badge-success">AKTIF</span>' : '<span class="ant-badge ant-badge-danger">NON-AKTIF</span>'}</td>
                <td style="text-align:center;">
                    <div class="inline-box" style="justify-content:center;">
                        <button type="button" class="ant-btn ant-btn-sm" title="Edit Tanda Tangan" onclick="openSignatureModalForId(${r.id}, '${escapeHtml(r.nama_lengkap)}')"><i class="fa-solid fa-signature" style="color:var(--primary);"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm" title="Edit Data Approval" onclick="editApproval(${r.id})"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" title="Hapus Otoritas" onclick="deleteApproval(${r.id}, '${escapeHtml(r.nama_lengkap)}')"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    if (window.approvalGrid) {
        window.approvalGrid.refresh();
    } else if (typeof AntEnterpriseGrid !== 'undefined') {
        window.approvalGrid = new AntEnterpriseGrid('#tblApproval', {
            gridId: 'erp_approval_list',
            enableSort: true,
            enableResize: true,
            enableContextMenu: true,
            enablePagination: false,
            skipCols: [0, 10],
            exportTitle: 'Otoritas_Approval_PR_PO',
            onEdit: (tr) => {
                const btn = tr.querySelector('button[onclick*="editApproval"]');
                if (btn) btn.click();
            }
        });
    }
}

function openAddApprovalModal() {
    document.getElementById('modalApprTitle').innerHTML = '<i class="fa-solid fa-user-shield"></i> Tambah Otoritas Approval';
    document.getElementById('apprId').value = '';
    document.getElementById('apprDocType').value = 'PO';
    document.getElementById('apprDeptKode').value = '*';
    document.getElementById('apprUserId').value = '';
    document.getElementById('apprNamaLengkap').value = '';
    document.getElementById('apprJabatan').value = '';
    document.getElementById('apprLevel').value = 'LEVEL_2';
    document.getElementById('apprUrutan').value = '2';
    document.getElementById('apprLimitMin').value = '0';
    document.getElementById('apprLimitMax').value = '0';
    document.getElementById('apprBodDelegate').checked = false;
    document.getElementById('apprAktif').value = 'Y';
    document.getElementById('apprTtdDigital').value = '';
    document.getElementById('apprSigPreviewBox').innerHTML = '<span class="muted" style="font-size:9px;">(Kosong)</span>';

    loadLoginUsers();
    document.getElementById('modalApproval').classList.add('active');
    document.getElementById('apprUserId').focus();
}

function editApproval(id) {
    const a = approvalCache.find(x => parseInt(x.id) === parseInt(id));
    if (!a) return;
    document.getElementById('modalApprTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Otoritas Approval : ' + escapeHtml(a.nama_lengkap);
    document.getElementById('apprId').value = a.id;
    document.getElementById('apprDocType').value = a.doc_type || 'PO';
    document.getElementById('apprDeptKode').value = a.dept_kode || '*';
    document.getElementById('apprUserId').value = a.user_id || '';
    document.getElementById('apprNamaLengkap').value = a.nama_lengkap || '';
    document.getElementById('apprJabatan').value = a.jabatan || '';
    document.getElementById('apprLevel').value = a.level_approval || 'LEVEL_2';
    document.getElementById('apprUrutan').value = a.urutan || 2;
    document.getElementById('apprLimitMin').value = parseFloat(a.limit_min || 0);
    document.getElementById('apprLimitMax').value = parseFloat(a.limit_max || 0);
    document.getElementById('apprBodDelegate').checked = (a.is_bod_delegate === 'Y');
    document.getElementById('apprAktif').value = (a.aktif === 'N') ? 'N' : 'Y';
    document.getElementById('apprTtdDigital').value = a.ttd_digital || '';
    
    if (a.ttd_digital) {
        document.getElementById('apprSigPreviewBox').innerHTML = `<img src="${a.ttd_digital}" style="max-height:46px; max-width:90px; object-fit:contain;">`;
    } else {
        document.getElementById('apprSigPreviewBox').innerHTML = '<span class="muted" style="font-size:9px;">(Kosong)</span>';
    }

    loadLoginUsers();
    document.getElementById('modalApproval').classList.add('active');
}

function closeApprModal() {
    document.getElementById('modalApproval').classList.remove('active');
}

function onApprLevelChange(lvl) {
    const urutanInp = document.getElementById('apprUrutan');
    if (lvl === 'PEMBUAT') urutanInp.value = 1;
    else if (lvl === 'LEVEL_1') urutanInp.value = 1;
    else if (lvl === 'LEVEL_2') urutanInp.value = 2;
    else if (lvl === 'LEVEL_3') urutanInp.value = 3;
    else if (lvl === 'FINAL') {
        urutanInp.value = 4;
        document.getElementById('apprDeptKode').value = '*';
    }
}

function loadLoginUsers() {
    const sel = document.getElementById('selLoginUser');
    sel.innerHTML = '<option value="">Memuat...</option>';
    fetch('department.php?action=get_login_users' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''))
        .then(r => r.json())
        .then(res => {
            sel.innerHTML = '<option value="">-- Pilih Akun Login --</option>';
            if (res.status === 'OK' && res.data) {
                res.data.forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = u.user_id;
                    opt.textContent = `${u.user_id} (${u.nama_lengkap || u.role})`;
                    opt.dataset.nama = u.nama_lengkap || u.user_id;
                    opt.dataset.role = u.role || 'Staff';
                    sel.appendChild(opt);
                });
            }
        });
}

function onLoginUserSelected(sel) {
    const opt = sel.selectedOptions[0];
    if (opt && opt.value) {
        document.getElementById('apprUserId').value = opt.value;
        if (!document.getElementById('apprNamaLengkap').value) {
            document.getElementById('apprNamaLengkap').value = opt.dataset.nama || opt.value;
        }
        if (!document.getElementById('apprJabatan').value) {
            document.getElementById('apprJabatan').value = opt.dataset.role || 'Staff';
        }
    }
}

function submitApproval() {
    const userId = document.getElementById('apprUserId').value.trim();
    const nama = document.getElementById('apprNamaLengkap').value.trim();
    if (!userId || !nama) {
        showWarning('User ID dan Nama Pejabat wajib diisi!');
        return;
    }

    const fd = new FormData();
    fd.append('id', document.getElementById('apprId').value);
    fd.append('doc_type', document.getElementById('apprDocType').value);
    fd.append('dept_kode', document.getElementById('apprDeptKode').value);
    fd.append('user_id', userId);
    fd.append('nama_lengkap', nama);
    fd.append('jabatan', document.getElementById('apprJabatan').value.trim());
    fd.append('level_approval', document.getElementById('apprLevel').value);
    fd.append('urutan', document.getElementById('apprUrutan').value);
    fd.append('limit_min', document.getElementById('apprLimitMin').value);
    fd.append('limit_max', document.getElementById('apprLimitMax').value);
    fd.append('is_bod_delegate', document.getElementById('apprBodDelegate').checked ? 'Y' : 'N');
    fd.append('aktif', document.getElementById('apprAktif').value);
    fd.append('ttd_digital', document.getElementById('apprTtdDigital').value);
    if (CURRENT_CMP_ID) fd.append('cmp_id', CURRENT_CMP_ID);

    fetch('department.php?action=save_approval_user' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            closeApprModal();
            showSuccess(res.message);
            loadApprovalList();
        } else {
            showError(res.message || 'Gagal menyimpan otoritas');
        }
    })
    .catch(() => showError('Gagal menghubungi server'));
}

async function deleteApproval(id, nama) {
    const ok = await showConfirm(`Hapus otoritas approval untuk "${nama}"?`, 'Konfirmasi Hapus');
    if (!ok) return;

    const fd = new FormData();
    fd.append('id', id);
    if (CURRENT_CMP_ID) fd.append('cmp_id', CURRENT_CMP_ID);

    fetch('department.php?action=delete_approval_user' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'OK') {
            showSuccess(res.message);
            loadApprovalList();
        } else {
            showError(res.message || 'Gagal menghapus');
        }
    })
    .catch(() => showError('Gagal menghubungi server'));
}

function exportApprovalExcel() {
    if (!approvalCache.length) {
        showWarning('Tidak ada data otoritas approval untuk diekspor!');
        return;
    }
    const exportData = approvalCache.map((a, i) => ({
        "No": i + 1,
        "Dokumen": a.doc_type,
        "Departemen": a.dept_kode,
        "User ID": a.user_id,
        "Nama Pejabat": a.nama_lengkap,
        "Jabatan": a.jabatan,
        "Level Approval": a.level_approval,
        "Urutan": a.urutan,
        "Plafon Min": a.limit_min,
        "Plafon Max": a.limit_max,
        "Kuasa BOD": a.is_bod_delegate === 'Y' ? 'YA' : 'TIDAK',
        "Ada TTD Digital": a.ttd_digital ? 'ADA' : 'BELUM',
        "Status": a.aktif === 'N' ? 'NON-AKTIF' : 'AKTIF'
    }));

    const ws = XLSX.utils.json_to_sheet(exportData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Otoritas Approval");
    XLSX.writeFile(wb, `Otoritas_Approval_PO_PR_${new Date().toISOString().slice(0,10)}.xlsx`);
}

// =========================================================================
// 3. STUDIO TANDA TANGAN DIGITAL (CANVAS SIGNATURE)
// =========================================================================
let canvas, ctx;
let drawing = false;

function initSignatureCanvas() {
    canvas = document.getElementById('sigCanvas');
    if (!canvas) return;
    ctx = canvas.getContext('2d');

    // Resize canvas resolusi tinggi
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * 2;
    canvas.height = rect.height * 2;
    ctx.scale(2, 2);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#002766';

    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseleave', stopDrawing);

    // Touch support (Tablet / Smartphone)
    canvas.addEventListener('touchstart', (e) => {
        e.preventDefault();
        const touch = e.touches[0];
        const rect = canvas.getBoundingClientRect();
        startDrawing({ clientX: touch.clientX, clientY: touch.clientY });
    }, { passive: false });

    canvas.addEventListener('touchmove', (e) => {
        e.preventDefault();
        const touch = e.touches[0];
        draw({ clientX: touch.clientX, clientY: touch.clientY });
    }, { passive: false });

    canvas.addEventListener('touchend', stopDrawing);
}

function startDrawing(e) {
    drawing = true;
    const rect = canvas.getBoundingClientRect();
    ctx.beginPath();
    ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
}

function draw(e) {
    if (!drawing) return;
    const rect = canvas.getBoundingClientRect();
    ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    ctx.stroke();
}

function stopDrawing() {
    drawing = false;
}

function clearSigCanvas() {
    if (!ctx || !canvas) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function handleSigUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(evt) {
        const img = new Image();
        img.onload = function() {
            clearSigCanvas();
            const rect = canvas.getBoundingClientRect();
            ctx.drawImage(img, 10, 10, rect.width - 20, rect.height - 20);
        };
        img.src = evt.target.result;
    };
    reader.readAsDataURL(file);
}

function openSignatureModalFromAppr() {
    sigTargetId = null;
    document.getElementById('modalSignature').classList.add('active');
    setTimeout(initSignatureCanvas, 150);
}

function openSignatureModalForId(id, nama) {
    sigTargetId = id;
    document.getElementById('modalSignature').classList.add('active');
    setTimeout(initSignatureCanvas, 150);
}

function closeSignatureModal() {
    document.getElementById('modalSignature').classList.remove('active');
}

function applySignature() {
    if (!canvas) return;
    const dataUrl = canvas.toDataURL('image/png');

    if (sigTargetId) {
        // Simpan langsung ke server untuk ID approval yang dipilih
        const fd = new FormData();
        fd.append('id', sigTargetId);
        fd.append('signature_data', dataUrl);
        if (CURRENT_CMP_ID) fd.append('cmp_id', CURRENT_CMP_ID);

        fetch('department.php?action=save_signature' + (CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''), {
            method: 'POST',
            body: fd
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'OK') {
                closeSignatureModal();
                showSuccess(res.message);
                loadApprovalList();
            } else {
                showError(res.message || 'Gagal menyimpan tanda tangan');
            }
        })
        .catch(() => showError('Gagal menghubungi server'));
    } else {
        // Terapkan ke field modal form approval yang sedang terbuka
        document.getElementById('apprTtdDigital').value = dataUrl;
        document.getElementById('apprSigPreviewBox').innerHTML = `<img src="${dataUrl}" style="max-height:46px; max-width:90px; object-fit:contain;">`;
        closeSignatureModal();
    }
}

// Inisialisasi Halaman
window.addEventListener('DOMContentLoaded', () => {
    loadDeptList(1);
});
</script>
</body>
</html>
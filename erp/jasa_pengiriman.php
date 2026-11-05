<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Master Jasa Pengiriman (Shipping / Carrier)
 * ============================================================================
 * Acuan Asli PowerBuilder: w_ref_jasakirim.srw, d_ref_jasakirim.srd, ddw_ref_jasakirim.srd
 * Manajemen Vendor Ekspedisi, Freight Forwarder, EMKL (Ekspedisi Muatan Kapal Laut),
 * Armada Truk Logistik Darat, dan Kurir Pengiriman Dokumen/Paket Sampel Kepabeanan,
 * serta Standardisasi Ant Design Compact Desktop 11px Identik Master Departemen.
 * ============================================================================
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (empty($sessionCmpId) && !empty($_GET['cmp_id'])) {
    $sessionCmpId = trim($_GET['cmp_id']);
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

$activeCmpName = 'TPB ERP Enterprise';
if (!empty($sessionCmpId)) {
    $cmpQ = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    if ($cmpQ && $cRow = $cmpQ->fetch_assoc()) {
        $activeCmpName = $cRow['cmp_name'];
    }
}

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_ref_jasakirim Tersedia Lengkap Multi-Tenant
// -------------------------------------------------------------------------
function ensureJasaKirimTable($koneksi, $escCmp) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_ref_jasakirim'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ref_jasakirim` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `kode` varchar(20) NOT NULL DEFAULT '',
                `nama` varchar(100) NOT NULL DEFAULT '',
                `jenis_layanan` varchar(50) DEFAULT 'Darat',
                `pic_kontak` varchar(100) DEFAULT '',
                `alamat` varchar(200) DEFAULT '',
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_cmp_kirim_kode` (`cmp_id`, `kode`),
                KEY `idx_kirim_kode` (`kode`),
                KEY `idx_cmp_id` (`cmp_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Auto-heal kolom jika tabel sudah ada dari PB lama
            $cols = [];
            $resCols = $koneksi->query("SHOW COLUMNS FROM bc_ref_jasakirim");
            if ($resCols) while ($r = $resCols->fetch_assoc()) $cols[strtolower($r['Field'])] = true;

            if (!isset($cols['id'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN id INT AUTO_INCREMENT UNIQUE FIRST");
            }
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT ''");
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD INDEX idx_cmp_id (cmp_id)");
            }
            if (!isset($cols['jenis_layanan'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN jenis_layanan VARCHAR(50) DEFAULT 'Darat'");
            }
            if (!isset($cols['pic_kontak'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN pic_kontak VARCHAR(100) DEFAULT ''");
            }
            if (!isset($cols['alamat'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN alamat VARCHAR(200) DEFAULT ''");
            }
            if (!isset($cols['status'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!isset($cols['set_by'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN set_by VARCHAR(100) DEFAULT ''");
            }
            if (!isset($cols['created_at'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            if (!isset($cols['updated_at'])) {
                @$koneksi->query("ALTER TABLE bc_ref_jasakirim ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
            @$koneksi->query("ALTER TABLE bc_ref_jasakirim MODIFY COLUMN kode VARCHAR(20) NOT NULL");
            @$koneksi->query("ALTER TABLE bc_ref_jasakirim MODIFY COLUMN nama VARCHAR(100) NOT NULL");
        }

        // Seed data armada standar jika masih kosong
        $cnt = $koneksi->query("SELECT COUNT(*) FROM bc_ref_jasakirim WHERE cmp_id = '$escCmp'")->fetch_row()[0] ?? 0;
        if ((int)$cnt === 0) {
            $defaultCarriers = [
                ['TRUCK',      'ARMADA TRUK / LOGISTIK DARAT',        'Darat',          'Internal / Ekspedisi Truk', 'Pool Armada Darat', 'SYSTEM - Auto Init'],
                ['KONTAINER',  'KONTAINER 20FT / 40FT (EMKL & PELAYARAN)', 'Laut',      'EMKL & Pelayaran Niaga',    'Pelabuhan Tanjung Priok / Perak', 'SYSTEM - Auto Init'],
                ['INTERNAL',   'ARMADA KENDARAAN OPERASIONAL SENDIRI', 'Armada Sendiri', 'Divisi Transport & Driver', 'Pool Pabrik', 'SYSTEM - Auto Init'],
                ['JNE',        'JNE EXPRESS (JALUR NUGRAHA EKAKURIR)','Kurir',          'Customer Care JNE',         'Cabang Utama Logistik', 'SYSTEM - Auto Init'],
                ['TIKI',       'TIKI (TITIPAN KILAT)',                'Kurir',          'Customer Service TIKI',     'Hub Logistik Kurir', 'SYSTEM - Auto Init'],
                ['DHL',        'DHL GLOBAL FORWARDING & EXPRESS',     'Udara',          'DHL Gateway Station',       'Bandara Soekarno Hatta / Juanda', 'SYSTEM - Auto Init'],
                ['FEDEX',      'FEDEX EXPRESS & TNT LOGISTICS',       'Udara',          'FedEx Call Center',         'Airport Hub Express', 'SYSTEM - Auto Init'],
                ['SIBA',       'SIBA SURYA LOGISTICS',                'Darat',          'Dispatch Siba Surya',       'Pool Truk Tronton & Trailer', 'SYSTEM - Auto Init']
            ];
            foreach ($defaultCarriers as $c) {
                $koneksi->query("INSERT IGNORE INTO bc_ref_jasakirim (cmp_id, kode, nama, jenis_layanan, pic_kontak, alamat, status, set_by)
                                 VALUES ('$escCmp', '{$c[0]}', '{$c[1]}', '{$c[2]}', '{$c[3]}', '{$c[4]}', 1, '{$c[5]}')");
            }
        }
    } catch (\Throwable $ex) {}
}
ensureJasaKirimTable($koneksi, $escCmp);

// =========================================================================
// AJAX BACKEND HANDLERS
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. GET MINI DASHBOARD KPI
if ($action === 'get_kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $w = "(cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)";
    $total   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_jasakirim WHERE $w")->fetch_row()[0] ?? 0);
    $darat   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_jasakirim WHERE $w AND (jenis_layanan LIKE '%Darat%' OR jenis_layanan LIKE '%Sendiri%')")->fetch_row()[0] ?? 0);
    $laut    = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_jasakirim WHERE $w AND (jenis_layanan LIKE '%Laut%' OR nama LIKE '%Kontainer%')")->fetch_row()[0] ?? 0);
    $udara   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_jasakirim WHERE $w AND (jenis_layanan LIKE '%Udara%' OR jenis_layanan LIKE '%Kurir%')")->fetch_row()[0] ?? 0);
    $aktif   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_jasakirim WHERE $w AND (status = 1 OR status IS NULL)")->fetch_row()[0] ?? 0);

    echo json_encode([
        'status' => 'OK',
        'total'  => $total,
        'darat'  => $darat,
        'laut'   => $laut,
        'udara'  => $udara,
        'aktif'  => $aktif
    ]);
    exit;
}

// 2. LIST DATA (PAGINATED & SEARCHABLE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? 'ALL');
    $layanan= trim($_GET['layanan'] ?? 'ALL');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = ["(cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)"];
    if ($status === '1') {
        $where[] = "(status = 1 OR status IS NULL)";
    } elseif ($status === '0') {
        $where[] = "status = 0";
    }

    if ($layanan !== 'ALL' && !empty($layanan)) {
        $lEsc = $koneksi->real_escape_string($layanan);
        $where[] = "jenis_layanan = '$lEsc'";
    }

    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(kode LIKE '%$sEsc%' OR nama LIKE '%$sEsc%' OR pic_kontak LIKE '%$sEsc%' OR alamat LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM bc_ref_jasakirim WHERE $wSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    $sql = "SELECT id, cmp_id, kode, nama, jenis_layanan, pic_kontak, alamat, 
                   COALESCE(status, 1) as status, set_by, updated_at
            FROM bc_ref_jasakirim 
            WHERE $wSql 
            ORDER BY status DESC, nama ASC 
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'            => (int)$r['id'],
                'kode'          => $r['kode'],
                'nama'          => $r['nama'],
                'jenis_layanan' => $r['jenis_layanan'] ?: 'Darat',
                'pic_kontak'    => $r['pic_kontak'] ?: '-',
                'alamat'        => $r['alamat'] ?: '-',
                'status'        => (int)$r['status'],
                'set_by'        => $r['set_by'] ?: 'SYSTEM',
                'updated_at'    => $r['updated_at'] ? date('d M Y H:i', strtotime($r['updated_at'])) : '-'
            ];
        }
    }

    echo json_encode([
        'status'      => 'OK',
        'data'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => ceil($total / $limit)
    ]);
    exit;
}

// 3. SIMPAN MANUAL (TAMBAH / EDIT)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $id      = (int)($_POST['id'] ?? 0);
    $kode    = strtoupper(trim($_POST['kode'] ?? ''));
    $nama    = strtoupper(trim($_POST['nama'] ?? ''));
    $layanan = trim($_POST['jenis_layanan'] ?? 'Darat');
    $pic     = trim($_POST['pic_kontak'] ?? '');
    $alamat  = trim($_POST['alamat'] ?? '');
    $status  = ($_POST['status'] ?? '1') === '0' ? 0 : 1;
    $setby   = $currentUser . ' - ' . date('Y-m-d H:i');

    if (empty($kode) || empty($nama)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode dan Nama Jasa Pengiriman wajib diisi!']);
        exit;
    }

    $escKode    = $koneksi->real_escape_string($kode);
    $escNama    = $koneksi->real_escape_string($nama);
    $escLayanan = $koneksi->real_escape_string($layanan);
    $escPic     = $koneksi->real_escape_string($pic);
    $escAlamat  = $koneksi->real_escape_string($alamat);
    $escSetby   = $koneksi->real_escape_string($setby);

    if ($id > 0) {
        $sql = "UPDATE bc_ref_jasakirim SET 
                kode = '$escKode',
                nama = '$escNama',
                jenis_layanan = '$escLayanan',
                pic_kontak = '$escPic',
                alamat = '$escAlamat',
                status = $status,
                set_by = '$escSetby'
                WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)";
        $res = $koneksi->query($sql);
        if ($res) {
            echo json_encode(['status' => 'OK', 'message' => "Jasa Pengiriman $nama berhasil diperbarui"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui: ' . $koneksi->error]);
        }
    } else {
        $sql = "INSERT INTO bc_ref_jasakirim 
                (cmp_id, kode, nama, jenis_layanan, pic_kontak, alamat, status, set_by)
                VALUES 
                ('$escCmp', '$escKode', '$escNama', '$escLayanan', '$escPic', '$escAlamat', $status, '$escSetby')
                ON DUPLICATE KEY UPDATE 
                nama = '$escNama', jenis_layanan = '$escLayanan', pic_kontak = '$escPic', alamat = '$escAlamat', status = $status, set_by = '$escSetby'";
        $res = $koneksi->query($sql);
        if ($res) {
            echo json_encode(['status' => 'OK', 'message' => "Jasa Pengiriman $nama berhasil ditambahkan"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menambahkan: ' . $koneksi->error]);
        }
    }
    exit;
}

// 4. TOGGLE STATUS
if ($action === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID jasa pengiriman tidak valid']);
        exit;
    }
    $upd = $koneksi->query("UPDATE bc_ref_jasakirim SET status = IF(status=1, 0, 1), set_by = '$currentUser - " . date('Y-m-d H:i') . "' 
                            WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($upd) {
        echo json_encode(['status' => 'OK', 'message' => 'Status jasa pengiriman berhasil diubah']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $koneksi->error]);
    }
    exit;
}

// 5. HAPUS DATA
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID jasa pengiriman tidak valid']);
        exit;
    }
    $del = $koneksi->query("DELETE FROM bc_ref_jasakirim WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Jasa pengiriman berhasil dihapus dari sistem']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 6. EXPORT CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Jasa_Pengiriman_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'Kode', 'Nama Ekspedisi / Armada', 'Moda / Layanan', 'PIC & Telepon', 'Alamat Pool / Kantor', 'Status', 'Pencatat', 'Terakhir Diperbarui']);
    $q = $koneksi->query("SELECT * FROM bc_ref_jasakirim WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY nama ASC");
    $no = 1;
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($output, [
                $no++,
                $r['kode'],
                $r['nama'],
                $r['jenis_layanan'],
                $r['pic_kontak'],
                $r['alamat'],
                ($r['status'] == 1 ? 'Aktif' : 'Non-Aktif'),
                $r['set_by'],
                $r['updated_at']
            ]);
        }
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
    <script src="/assets/ant_grid.js"></script>
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-active: #096dd9;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --border-color: #f0f0f0;
            --border-dark: #d9d9d9;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --row-hover: #fafafa;
        }
        body.dark-mode {
            --bg: #141414;
            --card-bg: #1f1f1f;
            --border-color: #303030;
            --border-dark: #434343;
            --text-main: #d9d9d9;
            --text-muted: #8c8c8c;
            --row-hover: #262626;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 10.5px;
            background: var(--bg);
            color: var(--text-main);
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }
        input, select, textarea, button { font-family: inherit; }
        .ant-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 8px 12px;
            gap: 8px;
        }
        .ant-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .ant-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--card-bg);
            min-height: 36px;
        }
        .ant-card-title {
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ant-badge-company {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
            padding: 1px 6px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: 500;
        }
        body.dark-mode .ant-badge-company {
            background: #111d2c;
            color: #177ddc;
            border-color: #15395b;
        }

        /* KPI Strip */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            padding: 8px 12px;
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
        }
        .kpi-card {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
        }
        .kpi-icon {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }
        .kpi-num {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.1;
        }
        .kpi-label {
            font-size: 9.5px;
            color: var(--text-muted);
        }

        /* Toolbar */
        .ant-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-bottom: 1px solid var(--border-color);
            background: var(--card-bg);
            flex-wrap: wrap;
        }
        .ant-btn {
            height: 24px;
            padding: 0 8px;
            font-size: 11px;
            border-radius: 3px;
            border: 1px solid var(--border-dark);
            background: var(--card-bg);
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            outline: none;
            white-space: nowrap;
        }
        .ant-btn:hover {
            color: var(--primary);
            border-color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #fff;
        }
        .ant-btn-sm {
            height: 22px;
            padding: 0 6px;
            font-size: 10px;
        }
        .ant-btn-danger {
            color: var(--danger);
            border-color: #ffa39e;
            background: #fff1f0;
        }
        .ant-btn-danger:hover {
            background: var(--danger);
            color: #fff;
        }
        body.dark-mode .ant-btn-danger {
            background: #2a1215;
            border-color: #5c2223;
        }

        /* Grid DataWindow Style */
        .grid-wrap {
            flex: 1;
            overflow: auto;
            position: relative;
            background: var(--card-bg);
        }
        table.dw {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            table-layout: fixed;
        }
        table.dw th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #fafafa;
            color: var(--text-main);
            font-weight: 600;
            font-size: 9.5px;
            padding: 4px 6px;
            border-bottom: 1px solid var(--border-dark);
            border-right: 1px solid var(--border-color);
            text-align: left;
            white-space: nowrap;
        }
        body.dark-mode table.dw th {
            background: #1d1d1d;
        }
        table.dw td {
            padding: 3px 6px;
            font-size: 10px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
        table.dw tr:hover td {
            background: var(--row-hover);
        }
        .th-highlight {
            background: #e6f7ff !important;
            color: #0050b3 !important;
        }
        body.dark-mode .th-highlight {
            background: #111d2c !important;
            color: #177ddc !important;
        }

        /* Badges */
        .badge-code {
            display: inline-block;
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 10.5px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 3px;
            background: #f5f5f5;
            color: #333;
            border: 1px solid #d9d9d9;
        }
        body.dark-mode .badge-code {
            background: #262626;
            color: #d9d9d9;
            border-color: #434343;
        }
        .tag-layanan {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 500;
        }
        .layanan-darat { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; }
        .layanan-laut { background: #e6fffb; color: #08979c; border: 1px solid #87e8de; }
        .layanan-udara { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }
        .layanan-kurir { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .layanan-sendiri { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }

        /* Switch Toggle */
        .ant-switch {
            position: relative;
            display: inline-block;
            width: 28px;
            height: 16px;
            vertical-align: middle;
            cursor: pointer;
        }
        .ant-switch input { opacity: 0; width: 0; height: 0; }
        .ant-slider {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #bfbfbf;
            transition: .2s;
            border-radius: 16px;
        }
        .ant-slider:before {
            position: absolute;
            content: "";
            height: 12px;
            width: 12px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
        }
        input:checked + .ant-slider { background-color: var(--primary); }
        input:checked + .ant-slider:before { transform: translateX(12px); }

        /* Card Footer */
        .ant-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 12px;
            border-top: 1px solid var(--border-color);
            background: var(--card-bg);
            font-size: 10px;
            color: var(--text-muted);
            min-height: 30px;
        }
        .inline-box {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Modal Dialog */
        .ant-modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .ant-modal-backdrop.active {
            display: flex;
        }
        .ant-modal {
            background: var(--card-bg);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            width: 92%;
            max-width: 520px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .ant-modal-header {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 600;
            background: var(--card-bg);
        }
        .ant-modal-body {
            padding: 12px;
            overflow-y: auto;
            flex: 1;
        }
        .ant-modal-footer {
            padding: 8px 12px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--card-bg);
        }
        .form-group {
            margin-bottom: 9px;
        }
        .form-group label {
            display: block;
            font-size: 10.5px;
            font-weight: 500;
            margin-bottom: 3px;
            color: var(--text-main);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .ant-input, .ant-select {
            width: 100%;
            height: 24px;
            padding: 0 6px;
            font-size: 11px;
            border: 1px solid var(--border-dark);
            border-radius: 3px;
            background: var(--card-bg);
            color: var(--text-main);
            outline: none;
        }
        .ant-input:focus, .ant-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Card Header -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-truck-fast" style="color:var(--primary);"></i>
                <span>Master Jasa Pengiriman &amp; Ekspedisi (Carrier)</span>
                <span class="ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div class="inline-box">
                <span style="font-size:10px; color:var(--text-muted);"><i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Acuan Asli PB: w_ref_jasakirim</span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Mini Dashboard KPI Strip -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);"><i class="fa-solid fa-truck"></i></div>
                <div>
                    <div class="kpi-num" id="kpiTotal">0</div>
                    <div class="kpi-label">Total Jasa Pengiriman</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-truck-moving"></i></div>
                <div>
                    <div class="kpi-num" id="kpiDarat">0</div>
                    <div class="kpi-label">Armada Darat / Truk</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(8,151,156,0.12); color:#08979c;"><i class="fa-solid fa-ship"></i></div>
                <div>
                    <div class="kpi-num" id="kpiLaut">0</div>
                    <div class="kpi-label">Kontainer / EMKL Laut</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;"><i class="fa-solid fa-plane-departure"></i></div>
                <div>
                    <div class="kpi-num" id="kpiUdara">0</div>
                    <div class="kpi-label">Kurir &amp; Kargo Udara</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="kpi-num" id="kpiAktif">0</div>
                    <div class="kpi-label">Ekspedisi Aktif</div>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Tambah Jasa Kirim</button>
            <div style="height:14px; width:1px; background:var(--border-color); margin:0 4px;"></div>
            <label style="font-size:10.5px; color:var(--text-muted);">Moda:</label>
            <select id="filterLayanan" class="ant-select" style="width:125px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua Moda Layanan</option>
                <option value="Darat">Darat (Truk / Tronton)</option>
                <option value="Laut">Laut (Kontainer / EMKL)</option>
                <option value="Udara">Udara (Air Cargo)</option>
                <option value="Kurir">Kurir Kilat (JNE/TIKI/dll)</option>
                <option value="Armada Sendiri">Armada Sendiri</option>
            </select>
            <label style="font-size:10.5px; color:var(--text-muted); margin-left:4px;">Status:</label>
            <select id="filterStatus" class="ant-select" style="width:95px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua</option>
                <option value="1" selected>Hanya Aktif</option>
                <option value="0">Non-Aktif</option>
            </select>
            <input type="text" id="searchKeyword" class="ant-input" placeholder="Cari Kode, Nama Ekspedisi, PIC..." style="width:200px;" onkeydown="if(event.key==='Enter') loadList(1)">
            <button type="button" class="ant-btn" onclick="loadList(1)"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            <div style="margin-left:auto; display:flex; gap:4px;">
                <button type="button" class="ant-btn" onclick="exportExcel()"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel</button>
                <button type="button" class="ant-btn" onclick="exportCsv()"><i class="fa-solid fa-file-csv"></i> CSV</button>
                <button type="button" class="ant-btn" onclick="loadList(currentPage)"><i class="fa-solid fa-rotate"></i> Refresh</button>
            </div>
        </div>

        <!-- Table Grid -->
        <div class="grid-wrap">
            <table class="dw" id="tblKirim">
                <thead>
                    <tr>
                        <th style="width:34px; text-align:center;">#</th>
                        <th class="th-highlight" style="width:100px;">Kode Vendor</th>
                        <th style="width:220px;">Nama Ekspedisi / Armada Pengiriman</th>
                        <th style="width:140px; text-align:center;">Moda / Jenis Layanan</th>
                        <th style="width:160px;">PIC &amp; Kontak Operasional</th>
                        <th>Alamat Pool / Kantor Cabang</th>
                        <th style="width:75px; text-align:center;">Status</th>
                        <th style="width:140px;">Audit Trail</th>
                        <th style="width:105px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tbodyKirim">
                    <tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">Memuat data jasa pengiriman...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Card Footer -->
        <div class="ant-card-footer">
            <span id="pageInfo">Menampilkan 0 data</span>
            <div class="inline-box" id="pageBtns"></div>
        </div>
    </div>
</div>

<!-- Modal Form Tambah / Edit -->
<div class="ant-modal-backdrop" id="modalForm">
    <div class="ant-modal">
        <div class="ant-modal-header">
            <span id="modalFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah Jasa Pengiriman</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModal()"></i>
        </div>
        <div class="ant-modal-body">
            <input type="hidden" id="formId">
            <div class="form-row">
                <div class="form-group">
                    <label>Kode Ekspedisi: <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="formKode" class="ant-input" placeholder="Contoh: TRUCK, JNE..." maxlength="20" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label>Moda Layanan Transportasi:</label>
                    <select id="formLayanan" class="ant-select">
                        <option value="Darat">Darat (Truk / Trailer / Pick-Up)</option>
                        <option value="Laut">Laut (Kontainer FCL/LCL - EMKL)</option>
                        <option value="Udara">Udara (Air Cargo Ekspor-Impor)</option>
                        <option value="Kurir">Kurir Kilat (Dokumen &amp; Sampel)</option>
                        <option value="Armada Sendiri">Armada Operasional Sendiri</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Nama Jasa Pengiriman / Perusahaan Ekspedisi: <span style="color:var(--danger)">*</span></label>
                <input type="text" id="formNama" class="ant-input" placeholder="Nama lengkap vendor atau armada..." maxlength="100" style="text-transform:uppercase;">
            </div>
            <div class="form-group">
                <label>PIC &amp; Nomor Kontak (Telepon / WA):</label>
                <input type="text" id="formPic" class="ant-input" placeholder="Nama petugas & nomor telepon kontak..." maxlength="100">
            </div>
            <div class="form-group">
                <label>Alamat Pool Armada / Kantor Depo Logistik:</label>
                <input type="text" id="formAlamat" class="ant-input" placeholder="Lokasi pool atau kantor perwakilan..." maxlength="200">
            </div>
            <div class="form-group">
                <label>Status Operasional:</label>
                <select id="formStatus" class="ant-select">
                    <option value="1">Aktif (Tampil di Dropdown Surat Jalan / DO / BC)</option>
                    <option value="0">Non-Aktif (Disembunyikan)</option>
                </select>
            </div>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn" onclick="closeModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Data</button>
        </div>
    </div>
</div>

<!-- Modal Panduan SOP Modul -->
<div class="ant-modal-backdrop" id="modalModuleGuide">
    <div class="ant-modal" style="max-width:620px;">
        <div class="ant-modal-header">
            <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Modul &amp; SOP: Jasa Pengiriman</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModuleGuide()"></i>
        </div>
        <div class="ant-modal-body" style="font-size:11px; line-height:1.6;">
            <h4 style="font-size:12px; margin-bottom:6px; color:var(--primary);"><i class="fa-solid fa-truck-ramp-box"></i> Standar Operasional Prosedur (SOP) Transportasi &amp; Pengiriman</h4>
            <p style="margin-bottom:8px;">Modul Jasa Pengiriman berfungsi mencatat data penyedia transportasi logistik untuk pengiriman bahan baku masuk (Inbound) dan produk jadi keluar (Outbound):</p>
            <ul style="padding-left:18px; margin-bottom:10px;">
                <li><b>Surat Jalan &amp; Delivery Order (DO):</b> Setiap pengeluaran barang wajib menyertakan identitas jasa pengiriman dan nomor polisi kendaraan pengangkut untuk validasi gerbang satpam dan audit kepabeanan.</li>
                <li><b>Dokumen Pabean (CEISA 4.0):</b> Dalam dokumen BC 2.3, BC 2.7, BC 4.0, maupun BC 4.1, sarana pengangkut darat/laut wajib teridentifikasi jelas beserta nama perusahaan pengangkut resmi.</li>
                <li><b>Armada Sendiri:</b> Jika pengiriman menggunakan kendaraan operasional perusahaan, pilih moda <code>Armada Sendiri</code> guna pembebasan biaya ekspedisi pihak ketiga.</li>
            </ul>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Tutup Panduan</button>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let kirimCache = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function loadKpi() {
    try {
        const res = await fetch('jasa_pengiriman.php?action=get_kpi');
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('kpiTotal').innerText = json.total;
            document.getElementById('kpiDarat').innerText = json.darat;
            document.getElementById('kpiLaut').innerText = json.laut;
            document.getElementById('kpiUdara').innerText = json.udara;
            document.getElementById('kpiAktif').innerText = json.aktif;
        }
    } catch (e) {
        console.error('KPI error', e);
    }
}

async function loadList(page = 1) {
    currentPage = page;
    const search  = document.getElementById('searchKeyword').value.trim();
    const status  = document.getElementById('filterStatus').value;
    const layanan = document.getElementById('filterLayanan').value;

    const tbody = document.getElementById('tbodyKirim');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>';

    try {
        const url = `jasa_pengiriman.php?action=list&page=${page}&limit=25&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&layanan=${encodeURIComponent(layanan)}`;
        const res = await fetch(url);
        const json = await res.json();

        if (json.status === 'OK') {
            kirimCache = json.data;
            renderTable(json.data);
            renderPagination(json.total, json.page, json.limit, json.total_pages);
        } else {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:12px;">${escapeHtml(json.message)}</td></tr>`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:12px;">Kesalahan: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function renderTable(data) {
    const tbody = document.getElementById('tbodyKirim');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:18px; color:var(--text-muted);">Tidak ada data jasa pengiriman yang sesuai filter.</td></tr>';
        return;
    }

    let html = '';
    const startNo = (currentPage - 1) * 25 + 1;

    data.forEach((r, idx) => {
        let layananCls = 'layanan-darat';
        let layananIcon = 'fa-truck';
        if (r.jenis_layanan === 'Laut') { layananCls = 'layanan-laut'; layananIcon = 'fa-ship'; }
        else if (r.jenis_layanan === 'Udara') { layananCls = 'layanan-udara'; layananIcon = 'fa-plane'; }
        else if (r.jenis_layanan === 'Kurir') { layananCls = 'layanan-kurir'; layananIcon = 'fa-box-open'; }
        else if (r.jenis_layanan === 'Armada Sendiri') { layananCls = 'layanan-sendiri'; layananIcon = 'fa-van-shuttle'; }

        const switchChecked = (r.status === 1) ? 'checked' : '';

        html += `<tr>
            <td style="text-align:center; color:var(--text-muted);">${startNo + idx}</td>
            <td><span class="badge-code">${escapeHtml(r.kode)}</span></td>
            <td><b>${escapeHtml(r.nama)}</b></td>
            <td style="text-align:center;"><span class="tag-layanan ${layananCls}"><i class="fa-solid ${layananIcon}"></i> ${escapeHtml(r.jenis_layanan)}</span></td>
            <td>${escapeHtml(r.pic_kontak)}</td>
            <td>${escapeHtml(r.alamat)}</td>
            <td style="text-align:center;">
                <label class="ant-switch">
                    <input type="checkbox" ${switchChecked} onchange="toggleStatus(${r.id})">
                    <span class="ant-slider"></span>
                </label>
            </td>
            <td style="font-size:10px; color:var(--text-muted);">${escapeHtml(r.set_by)}<br><span style="font-size:9px;">${escapeHtml(r.updated_at)}</span></td>
            <td style="text-align:center;">
                <div class="inline-box">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="editItem(${r.id})" title="Edit Data"><i class="fa-solid fa-pen" style="color:var(--primary);"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteItem(${r.id}, '${escapeHtml(r.nama)}')" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;

    if (window.kirimGrid) {
        window.kirimGrid.refresh();
    } else if (typeof AntEnterpriseGrid !== 'undefined') {
        window.kirimGrid = new AntEnterpriseGrid('#tblKirim', {
            gridId: 'erp_jasa_pengiriman',
            enableSort: true,
            enableResize: true,
            enableContextMenu: true,
            enablePagination: false,
            skipCols: [0, 8],
            exportTitle: 'Jasa_Pengiriman',
            onEdit: (tr) => {
                const btn = tr.querySelector('button[onclick*="editItem"]');
                if (btn) btn.click();
            }
        });
    }
}

function renderPagination(total, page, limit, totalPages) {
    document.getElementById('pageInfo').innerText = `Menampilkan ${total ? ((page - 1) * limit + 1) : 0} - ${Math.min(page * limit, total)} dari ${total} jasa pengiriman`;
    const btns = document.getElementById('pageBtns');
    if (totalPages <= 1) {
        btns.innerHTML = '';
        return;
    }
    let html = '';
    html += `<button type="button" class="ant-btn ant-btn-sm" ${page <= 1 ? 'disabled style="opacity:0.5;"' : ''} onclick="loadList(${page - 1})"><i class="fa-solid fa-angle-left"></i> Prev</button>`;
    html += `<span style="padding:0 6px; font-weight:600;">${page} / ${totalPages}</span>`;
    html += `<button type="button" class="ant-btn ant-btn-sm" ${page >= totalPages ? 'disabled style="opacity:0.5;"' : ''} onclick="loadList(${page + 1})">Next <i class="fa-solid fa-angle-right"></i></button>`;
    btns.innerHTML = html;
}

function openAddModal() {
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Tambah Jasa Pengiriman';
    document.getElementById('formId').value = '';
    document.getElementById('formKode').value = '';
    document.getElementById('formKode').readOnly = false;
    document.getElementById('formNama').value = '';
    document.getElementById('formLayanan').value = 'Darat';
    document.getElementById('formPic').value = '';
    document.getElementById('formAlamat').value = '';
    document.getElementById('formStatus').value = '1';
    document.getElementById('modalForm').classList.add('active');
}

function editItem(id) {
    const item = kirimCache.find(x => x.id === id);
    if (!item) return;

    document.getElementById('modalFormTitle').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit: ${escapeHtml(item.nama)}`;
    document.getElementById('formId').value = item.id;
    document.getElementById('formKode').value = item.kode;
    document.getElementById('formKode').readOnly = true;
    document.getElementById('formNama').value = item.nama;
    document.getElementById('formLayanan').value = item.jenis_layanan;
    document.getElementById('formPic').value = (item.pic_kontak === '-') ? '' : item.pic_kontak;
    document.getElementById('formAlamat').value = (item.alamat === '-') ? '' : item.alamat;
    document.getElementById('formStatus').value = item.status;
    document.getElementById('modalForm').classList.add('active');
}

function closeModal() {
    document.getElementById('modalForm').classList.remove('active');
}

async function submitForm() {
    const id      = document.getElementById('formId').value;
    const kode    = document.getElementById('formKode').value.trim();
    const nama    = document.getElementById('formNama').value.trim();
    const layanan = document.getElementById('formLayanan').value;
    const pic     = document.getElementById('formPic').value.trim();
    const alamat  = document.getElementById('formAlamat').value.trim();
    const status  = document.getElementById('formStatus').value;

    if (!kode || !nama) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Kode dan Nama Jasa Pengiriman wajib diisi!' });
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('kode', kode);
    fd.append('nama', nama);
    fd.append('jenis_layanan', layanan);
    fd.append('pic_kontak', pic);
    fd.append('alamat', alamat);
    fd.append('status', status);

    try {
        const res = await fetch('jasa_pengiriman.php?action=save', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.status === 'OK') {
            closeModal();
            Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
            loadList(currentPage);
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

async function toggleStatus(id) {
    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('jasa_pengiriman.php?action=toggle_status', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.status === 'OK') {
            loadList(currentPage);
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

async function deleteItem(id, nama) {
    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus jasa pengiriman ${nama}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ff4d4f'
    });

    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('jasa_pengiriman.php?action=delete', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1200, showConfirmButton: false });
            loadList(currentPage);
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

function exportCsv() {
    window.location.href = 'jasa_pengiriman.php?action=export_csv';
}

function exportExcel() {
    if (!kirimCache || kirimCache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }
    const rows = kirimCache.map((r, i) => ({
        'No': i + 1,
        'Kode': r.kode,
        'Nama Ekspedisi / Armada': r.nama,
        'Moda / Layanan': r.jenis_layanan,
        'PIC & Telepon': r.pic_kontak,
        'Alamat Pool / Kantor': r.alamat,
        'Status': (r.status === 1 ? 'Aktif' : 'Non-Aktif'),
        'Pencatat': r.set_by,
        'Terakhir Diperbarui': r.updated_at
    }));

    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Jasa Pengiriman");
    XLSX.writeFile(wb, `Jasa_Pengiriman_${new Date().toISOString().slice(0,10)}.xlsx`);
}

function openModuleGuide() {
    document.getElementById('modalModuleGuide').classList.add('active');
}
function closeModuleGuide() {
    document.getElementById('modalModuleGuide').classList.remove('active');
}

// Inisialisasi
window.addEventListener('DOMContentLoaded', () => {
    loadKpi();
    loadList(1);
});
</script>
</body>
</html>

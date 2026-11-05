<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Master Grup Scrap (Waste & Scrap Grouping)
 * ============================================================================
 * Acuan Asli PowerBuilder: w_grup_scrap.srw, d_grup_scrap.srd, ddw_grup_scrap.srd
 * Manajemen Klasifikasi & Pengelompokan Limbah / Sisa Produksi (Scrap / Waste),
 * Limbah Logam (Besi, Aluminium, Tembaga), Non-Logam (Plastik, Kertas, Aval Kain),
 * Korelasi Master Scrap (bc_scrap), serta Standardisasi Ant Design Compact Desktop 11px
 * Identik Master Departemen.
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
// AUTO-HEAL: Pastikan Struktur Tabel bc_ref_scrap Tersedia Lengkap Multi-Tenant
// -------------------------------------------------------------------------
function ensureGrupScrapTable($koneksi, $escCmp) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_ref_scrap'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ref_scrap` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `material` varchar(100) NOT NULL DEFAULT '',
                `kode_scrap` varchar(20) DEFAULT '',
                `jenis_limbah` varchar(50) DEFAULT 'Limbah Logam Non-B3',
                `satuan_standar` varchar(10) DEFAULT 'KGM',
                `keterangan` varchar(200) DEFAULT '',
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_cmp_scrap` (`cmp_id`, `material`),
                KEY `idx_scrap_nama` (`material`),
                KEY `idx_cmp_id` (`cmp_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Auto-heal kolom jika tabel sudah ada dari PB lama
            $cols = [];
            $resCols = $koneksi->query("SHOW COLUMNS FROM bc_ref_scrap");
            if ($resCols) while ($r = $resCols->fetch_assoc()) $cols[strtolower($r['Field'])] = true;

            if (!isset($cols['id'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN id INT AUTO_INCREMENT UNIQUE FIRST");
            }
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT ''");
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD INDEX idx_cmp_id (cmp_id)");
            }
            if (!isset($cols['kode_scrap'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN kode_scrap VARCHAR(20) DEFAULT ''");
            }
            if (!isset($cols['jenis_limbah'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN jenis_limbah VARCHAR(50) DEFAULT 'Limbah Non-B3'");
            }
            if (!isset($cols['satuan_standar'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN satuan_standar VARCHAR(10) DEFAULT 'KGM'");
            }
            if (!isset($cols['keterangan'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN keterangan VARCHAR(200) DEFAULT ''");
            }
            if (!isset($cols['status'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!isset($cols['set_by'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN set_by VARCHAR(100) DEFAULT ''");
            }
            if (!isset($cols['created_at'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            if (!isset($cols['updated_at'])) {
                @$koneksi->query("ALTER TABLE bc_ref_scrap ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
            @$koneksi->query("ALTER TABLE bc_ref_scrap MODIFY COLUMN material VARCHAR(100) NOT NULL");
        }

        // Seed data grup scrap industri jika masih kosong
        $cnt = $koneksi->query("SELECT COUNT(*) FROM bc_ref_scrap WHERE cmp_id = '$escCmp'")->fetch_row()[0] ?? 0;
        if ((int)$cnt === 0) {
            $defaultScraps = [
                ['BESI & BAJA',         'SCR-FE',  'Limbah Logam Non-B3', 'KGM', 'Potongan plat besi, gram bubut, dan besi reject', 'SYSTEM - Auto Init'],
                ['ALUMINIUM',           'SCR-AL',  'Limbah Logam Non-B3', 'KGM', 'Sisa potongan profil aluminium dan dross', 'SYSTEM - Auto Init'],
                ['TEMBAGA & KUNINGAN',  'SCR-CU',  'Limbah Logam Non-B3', 'KGM', 'Kabel bekas tembaga, pipa tembaga, dan kuningan', 'SYSTEM - Auto Init'],
                ['PLASTIK & POLYMER',   'SCR-PLS', 'Limbah Non-Logam',    'KGM', 'Afval plastik injection, runner, dan karung rusak', 'SYSTEM - Auto Init'],
                ['KERTAS & KARTON',     'SCR-PPR', 'Limbah Non-Logam',    'KGM', 'Karton bekas packing, dus sisa, dan kertas cacah', 'SYSTEM - Auto Init'],
                ['BENANG & SISA KAIN',  'SCR-TXT', 'Avalan Produksi',     'KGM', 'Sisa perca potongan kain, gulungan benang aval', 'SYSTEM - Auto Init'],
                ['KAYU & PALLET BEKAS', 'SCR-WD',  'Limbah Kayu',         'PCE', 'Pallet kayu rusak dan serpihan peti kemas', 'SYSTEM - Auto Init']
            ];
            foreach ($defaultScraps as $s) {
                $koneksi->query("INSERT IGNORE INTO bc_ref_scrap (cmp_id, material, kode_scrap, jenis_limbah, satuan_standar, keterangan, status, set_by)
                                 VALUES ('$escCmp', '{$s[0]}', '{$s[1]}', '{$s[2]}', '{$s[3]}', '{$s[4]}', 1, '{$s[5]}')");
            }
        }
    } catch (\Throwable $ex) {}
}
ensureGrupScrapTable($koneksi, $escCmp);

// =========================================================================
// AJAX BACKEND HANDLERS
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. GET MINI DASHBOARD KPI
if ($action === 'get_kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $w = "(cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)";
    $total    = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_scrap WHERE $w")->fetch_row()[0] ?? 0);
    $logam    = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_scrap WHERE $w AND jenis_limbah LIKE '%Logam%'")->fetch_row()[0] ?? 0);
    $nonLogam = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_scrap WHERE $w AND (jenis_limbah LIKE '%Non-Logam%' OR jenis_limbah LIKE '%Avalan%' OR jenis_limbah LIKE '%Kayu%')")->fetch_row()[0] ?? 0);
    $aktif    = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_scrap WHERE $w AND (status = 1 OR status IS NULL)")->fetch_row()[0] ?? 0);

    // Hitung korelasi total item scrap di master bc_scrap
    $cntScrap = 0;
    try {
        $chkScrap = $koneksi->query("SHOW TABLES LIKE 'bc_scrap'");
        if ($chkScrap && $chkScrap->num_rows > 0) {
            $cntScrap = (int)($koneksi->query("SELECT COUNT(*) FROM bc_scrap WHERE cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL")->fetch_row()[0] ?? 0);
        }
    } catch (\Throwable $e) {}

    echo json_encode([
        'status'      => 'OK',
        'total'       => $total,
        'logam'       => $logam,
        'non_logam'   => $nonLogam,
        'total_scrap' => $cntScrap,
        'aktif'       => $aktif
    ]);
    exit;
}

// 2. LIST DATA (PAGINATED & SEARCHABLE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? 'ALL');
    $jenis  = trim($_GET['jenis'] ?? 'ALL');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = ["(g.cmp_id = '$escCmp' OR g.cmp_id = '' OR g.cmp_id IS NULL)"];
    if ($status === '1') {
        $where[] = "(g.status = 1 OR g.status IS NULL)";
    } elseif ($status === '0') {
        $where[] = "g.status = 0";
    }

    if ($jenis !== 'ALL' && !empty($jenis)) {
        $jEsc = $koneksi->real_escape_string($jenis);
        $where[] = "g.jenis_limbah = '$jEsc'";
    }

    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(g.material LIKE '%$sEsc%' OR g.kode_scrap LIKE '%$sEsc%' OR g.keterangan LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM bc_ref_scrap g WHERE $wSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    // Kueri ambil data beserta hitungan item bc_scrap
    $hasScrapTbl = false;
    $chk = $koneksi->query("SHOW TABLES LIKE 'bc_scrap'");
    if ($chk && $chk->num_rows > 0) $hasScrapTbl = true;

    if ($hasScrapTbl) {
        $sql = "SELECT g.id, g.cmp_id, g.material, g.kode_scrap, g.jenis_limbah, g.satuan_standar, g.keterangan, 
                       COALESCE(g.status, 1) as status, g.set_by, g.updated_at,
                       (SELECT COUNT(*) FROM bc_scrap s WHERE (s.nama LIKE CONCAT('%', g.material, '%') OR s.kode LIKE CONCAT('%', g.kode_scrap, '%')) AND (s.cmp_id = g.cmp_id OR s.cmp_id = '' OR s.cmp_id IS NULL)) as item_count
                FROM bc_ref_scrap g 
                WHERE $wSql 
                ORDER BY g.status DESC, g.jenis_limbah ASC, g.material ASC 
                LIMIT $offset, $limit";
    } else {
        $sql = "SELECT g.id, g.cmp_id, g.material, g.kode_scrap, g.jenis_limbah, g.satuan_standar, g.keterangan, 
                       COALESCE(g.status, 1) as status, g.set_by, g.updated_at,
                       0 as item_count
                FROM bc_ref_scrap g 
                WHERE $wSql 
                ORDER BY g.status DESC, g.jenis_limbah ASC, g.material ASC 
                LIMIT $offset, $limit";
    }

    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'             => (int)$r['id'],
                'material'       => $r['material'],
                'kode_scrap'     => $r['kode_scrap'] ?: '-',
                'jenis_limbah'   => $r['jenis_limbah'] ?: 'Limbah Non-B3',
                'satuan_standar' => $r['satuan_standar'] ?: 'KGM',
                'keterangan'     => $r['keterangan'] ?: '-',
                'item_count'     => (int)$r['item_count'],
                'status'         => (int)$r['status'],
                'set_by'         => $r['set_by'] ?: 'SYSTEM',
                'updated_at'     => $r['updated_at'] ? date('d M Y H:i', strtotime($r['updated_at'])) : '-'
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
    $id        = (int)($_POST['id'] ?? 0);
    $material  = strtoupper(trim($_POST['material'] ?? ''));
    $kodeScrap = strtoupper(trim($_POST['kode_scrap'] ?? ''));
    $jenis     = trim($_POST['jenis_limbah'] ?? 'Limbah Logam Non-B3');
    $satuan    = strtoupper(trim($_POST['satuan_standar'] ?? 'KGM'));
    $ket       = trim($_POST['keterangan'] ?? '');
    $status    = ($_POST['status'] ?? '1') === '0' ? 0 : 1;
    $setby     = $currentUser . ' - ' . date('Y-m-d H:i');

    if (empty($material)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nama Grup Scrap wajib diisi!']);
        exit;
    }

    $escMat       = $koneksi->real_escape_string($material);
    $escKodeScrap = $koneksi->real_escape_string($kodeScrap);
    $escJenis     = $koneksi->real_escape_string($jenis);
    $escSat       = $koneksi->real_escape_string($satuan);
    $escKet       = $koneksi->real_escape_string($ket);
    $escSetby     = $koneksi->real_escape_string($setby);

    if ($id > 0) {
        $sql = "UPDATE bc_ref_scrap SET 
                material = '$escMat',
                kode_scrap = '$escKodeScrap',
                jenis_limbah = '$escJenis',
                satuan_standar = '$escSat',
                keterangan = '$escKet',
                status = $status,
                set_by = '$escSetby'
                WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)";
        $res = $koneksi->query($sql);
        if ($res) {
            echo json_encode(['status' => 'OK', 'message' => "Grup Scrap $material berhasil diperbarui"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui: ' . $koneksi->error]);
        }
    } else {
        $sql = "INSERT INTO bc_ref_scrap 
                (cmp_id, material, kode_scrap, jenis_limbah, satuan_standar, keterangan, status, set_by)
                VALUES 
                ('$escCmp', '$escMat', '$escKodeScrap', '$escJenis', '$escSat', '$escKet', $status, '$escSetby')
                ON DUPLICATE KEY UPDATE 
                kode_scrap = '$escKodeScrap', jenis_limbah = '$escJenis', satuan_standar = '$escSat', keterangan = '$escKet', status = $status, set_by = '$escSetby'";
        $res = $koneksi->query($sql);
        if ($res) {
            echo json_encode(['status' => 'OK', 'message' => "Grup Scrap $material berhasil ditambahkan"]);
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
        echo json_encode(['status' => 'ERROR', 'message' => 'ID tidak valid']);
        exit;
    }
    $upd = $koneksi->query("UPDATE bc_ref_scrap SET status = IF(status=1, 0, 1), set_by = '$currentUser - " . date('Y-m-d H:i') . "' 
                            WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($upd) {
        echo json_encode(['status' => 'OK', 'message' => 'Status grup scrap berhasil diubah']);
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
        echo json_encode(['status' => 'ERROR', 'message' => 'ID tidak valid']);
        exit;
    }
    $del = $koneksi->query("DELETE FROM bc_ref_scrap WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Grup scrap berhasil dihapus dari sistem']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 6. SYNC DARI RIWAYAT MASTER SCRAP (bc_scrap)
if ($action === 'sync_from_scrap') {
    header('Content-Type: application/json; charset=utf-8');
    $synced = 0;
    try {
        $chk = $koneksi->query("SHOW TABLES LIKE 'bc_scrap'");
        if ($chk && $chk->num_rows > 0) {
            $colNama = $koneksi->query("SHOW COLUMNS FROM bc_scrap LIKE 'nama'");
            if ($colNama && $colNama->num_rows > 0) {
                $q = $koneksi->query("SELECT DISTINCT TRIM(UPPER(nama)) as sc_name FROM bc_scrap WHERE nama IS NOT NULL AND TRIM(nama) != '' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
                if ($q) {
                    while ($r = $q->fetch_assoc()) {
                        $sName = $r['sc_name'];
                        if (!empty($sName)) {
                            $escS = $koneksi->real_escape_string($sName);
                            $ins = $koneksi->query("INSERT IGNORE INTO bc_ref_scrap (cmp_id, material, kode_scrap, jenis_limbah, satuan_standar, keterangan, status, set_by)
                                                    VALUES ('$escCmp', '$escS', 'SYNC', 'Avalan Produksi', 'KGM', 'Otomatis ditarik dari Master Scrap', 1, '$currentUser - Sync Scrap')");
                            if ($ins && $koneksi->affected_rows > 0) $synced++;
                        }
                    }
                }
            }
        }
    } catch (\Throwable $ex) {}

    echo json_encode([
        'status'  => 'OK',
        'synced'  => $synced,
        'message' => $synced > 0 ? "Berhasil menyelaraskan $synced grup scrap baru dari Master Scrap." : "Semua grup scrap dari Master Scrap sudah tersinkronisasi."
    ]);
    exit;
}

// 7. EXPORT CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Grup_Scrap_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'Kode Scrap', 'Nama Grup Scrap', 'Klasifikasi Limbah', 'Satuan Standar', 'Keterangan', 'Status', 'Pencatat', 'Terakhir Diperbarui']);
    $q = $koneksi->query("SELECT * FROM bc_ref_scrap WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY jenis_limbah ASC, material ASC");
    $no = 1;
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($output, [
                $no++,
                $r['kode_scrap'],
                $r['material'],
                $r['jenis_limbah'],
                $r['satuan_standar'],
                $r['keterangan'],
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
        .ant-btn-success {
            background: #52c41a;
            border-color: #52c41a;
            color: #fff;
        }
        .ant-btn-success:hover {
            background: #73d13d;
            border-color: #73d13d;
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
        .tag-limbah {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 500;
        }
        .limbah-logam { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; }
        .limbah-nonlogam { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .limbah-avalan { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .limbah-kayu { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }

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
                <i class="fa-solid fa-recycle" style="color:var(--primary);"></i>
                <span>Master Grup Scrap &amp; Limbah (Waste / Scrap)</span>
                <span class="ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div class="inline-box">
                <span style="font-size:10px; color:var(--text-muted);"><i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Acuan Asli PB: w_grup_scrap</span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Mini Dashboard KPI Strip -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);"><i class="fa-solid fa-dumpster"></i></div>
                <div>
                    <div class="kpi-num" id="kpiTotal">0</div>
                    <div class="kpi-label">Total Grup Scrap</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                <div>
                    <div class="kpi-num" id="kpiLogam">0</div>
                    <div class="kpi-label">Scrap Logam (Besi/Alu)</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);"><i class="fa-solid fa-box-archive"></i></div>
                <div>
                    <div class="kpi-num" id="kpiNonLogam">0</div>
                    <div class="kpi-label">Non-Logam &amp; Avalan</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;"><i class="fa-solid fa-scale-balanced"></i></div>
                <div>
                    <div class="kpi-num" id="kpiTotalScrap">0</div>
                    <div class="kpi-label">Master Scrap Terhubung</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="kpi-num" id="kpiAktif">0</div>
                    <div class="kpi-label">Grup Scrap Aktif</div>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Tambah Grup Scrap</button>
            <button type="button" class="ant-btn ant-btn-success" onclick="syncFromScrap()" title="Tarik nama barang yang sudah pernah tercatat pada Master Scrap"><i class="fa-solid fa-arrows-rotate"></i> Ambil dari Riwayat Scrap</button>
            <div style="height:14px; width:1px; background:var(--border-color); margin:0 4px;"></div>
            <label style="font-size:10.5px; color:var(--text-muted);">Klasifikasi:</label>
            <select id="filterJenis" class="ant-select" style="width:130px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua Klasifikasi</option>
                <option value="Limbah Logam Non-B3">Limbah Logam Non-B3</option>
                <option value="Limbah Non-Logam">Limbah Non-Logam</option>
                <option value="Avalan Produksi">Avalan Produksi</option>
                <option value="Limbah Kayu">Limbah Kayu / Pallet</option>
            </select>
            <label style="font-size:10.5px; color:var(--text-muted); margin-left:4px;">Status:</label>
            <select id="filterStatus" class="ant-select" style="width:95px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua</option>
                <option value="1" selected>Hanya Aktif</option>
                <option value="0">Non-Aktif</option>
            </select>
            <input type="text" id="searchKeyword" class="ant-input" placeholder="Cari Kode atau Nama Scrap..." style="width:190px;" onkeydown="if(event.key==='Enter') loadList(1)">
            <button type="button" class="ant-btn" onclick="loadList(1)"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            <div style="margin-left:auto; display:flex; gap:4px;">
                <button type="button" class="ant-btn" onclick="exportExcel()"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel</button>
                <button type="button" class="ant-btn" onclick="exportCsv()"><i class="fa-solid fa-file-csv"></i> CSV</button>
                <button type="button" class="ant-btn" onclick="loadList(currentPage)"><i class="fa-solid fa-rotate"></i> Refresh</button>
            </div>
        </div>

        <!-- Table Grid -->
        <div class="grid-wrap">
            <table class="dw" id="tblScrap">
                <thead>
                    <tr>
                        <th style="width:34px; text-align:center;">#</th>
                        <th class="th-highlight" style="width:110px;">Kode Scrap</th>
                        <th style="width:230px;">Nama Grup Scrap / Limbah</th>
                        <th style="width:150px; text-align:center;">Klasifikasi Limbah</th>
                        <th style="width:90px; text-align:center;">Satuan</th>
                        <th style="width:115px; text-align:center;">Item Terhubung</th>
                        <th>Keterangan / Sumber Limbah</th>
                        <th style="width:75px; text-align:center;">Status</th>
                        <th style="width:140px;">Audit Trail</th>
                        <th style="width:105px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tbodyScrap">
                    <tr><td colspan="10" style="text-align:center; padding:16px; color:var(--text-muted);">Memuat data grup scrap...</td></tr>
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
            <span id="modalFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah Grup Scrap</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModal()"></i>
        </div>
        <div class="ant-modal-body">
            <input type="hidden" id="formId">
            <div class="form-row">
                <div class="form-group">
                    <label>Kode Grup Scrap:</label>
                    <input type="text" id="formKodeScrap" class="ant-input" placeholder="Contoh: SCR-FE, SCR-PLS..." maxlength="20" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label>Satuan Standar:</label>
                    <input type="text" id="formSatuan" class="ant-input" value="KGM" placeholder="KGM / TNE / PCE..." maxlength="10" style="text-transform:uppercase;">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Nama Grup Scrap: <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="formMaterial" class="ant-input" placeholder="Contoh: BESI & BAJA, PLASTIK..." maxlength="100" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label>Klasifikasi Limbah: <span style="color:var(--danger)">*</span></label>
                    <select id="formJenis" class="ant-select">
                        <option value="Limbah Logam Non-B3">Limbah Logam Non-B3</option>
                        <option value="Limbah Non-Logam">Limbah Non-Logam</option>
                        <option value="Avalan Produksi">Avalan Produksi</option>
                        <option value="Limbah Kayu">Limbah Kayu / Pallet</option>
                        <option value="Residu / Abu">Residu / Abu Proses</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Keterangan / Sumber Limbah Pabrik:</label>
                <input type="text" id="formKeterangan" class="ant-input" placeholder="Deskripsi sumber sisa potongan, avalan, atau reject..." maxlength="200">
            </div>
            <div class="form-group">
                <label>Status Operasional:</label>
                <select id="formStatus" class="ant-select">
                    <option value="1">Aktif (Tersedia di Menu Pencatatan Scrap &amp; IT Inventory)</option>
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
            <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Modul &amp; SOP: Grup Scrap / Limbah</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModuleGuide()"></i>
        </div>
        <div class="ant-modal-body" style="font-size:11px; line-height:1.6;">
            <h4 style="font-size:12px; margin-bottom:6px; color:var(--primary);"><i class="fa-solid fa-recycle"></i> Standar Tata Kelola Scrap &amp; Limbah (Waste)</h4>
            <p style="margin-bottom:8px;">Modul Grup Scrap mengontrol tata kelola sisa hasil proses produksi, reject, dan limbah industri untuk keperluan pelaporan kepabeanan CEISA 4.0 &amp; IT Inventory:</p>
            <ul style="padding-left:18px; margin-bottom:10px;">
                <li><b>Laporan Mutasi Barang Sisa / Scrap (IT Inventory):</b> Bea Cukai mewajibkan perusahaan Kawasan Berikat / TPB untuk mempertanggungjawabkan bahan baku yang berubah menjadi sisa produksi (scrap/waste) secara akurat.</li>
                <li><b>Pengeluaran Scrap (BC 2.4):</b> Penjualan limbah/scrap ke pasar lokal wajib menggunakan dokumen BC 2.4 dengan pelunasan Bea Masuk dan Pajak Dalam Rangka Impor (PDRI) yang berlaku.</li>
                <li><b>Pemusnahan Limbah (BC 2.7 / Berita Acara):</b> Limbah yang tidak memiliki nilai ekonomis dapat dimusnahkan dengan pengawasan Petugas Bea dan Cukai serta tercatat pada grup yang sesuai.</li>
            </ul>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Tutup Panduan</button>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let scrapCache = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function loadKpi() {
    try {
        const res = await fetch('grup_scrap.php?action=get_kpi');
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('kpiTotal').innerText = json.total;
            document.getElementById('kpiLogam').innerText = json.logam;
            document.getElementById('kpiNonLogam').innerText = json.non_logam;
            document.getElementById('kpiTotalScrap').innerText = json.total_scrap;
            document.getElementById('kpiAktif').innerText = json.aktif;
        }
    } catch (e) {
        console.error('KPI error', e);
    }
}

async function loadList(page = 1) {
    currentPage = page;
    const search = document.getElementById('searchKeyword').value.trim();
    const status = document.getElementById('filterStatus').value;
    const jenis  = document.getElementById('filterJenis').value;

    const tbody = document.getElementById('tbodyScrap');
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>';

    try {
        const url = `grup_scrap.php?action=list&page=${page}&limit=25&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&jenis=${encodeURIComponent(jenis)}`;
        const res = await fetch(url);
        const json = await res.json();

        if (json.status === 'OK') {
            scrapCache = json.data;
            renderTable(json.data);
            renderPagination(json.total, json.page, json.limit, json.total_pages);
        } else {
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:12px;">${escapeHtml(json.message)}</td></tr>`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:12px;">Kesalahan: ${escapeHtml(e.message)}</td></tr>`;
    }
}

function renderTable(data) {
    const tbody = document.getElementById('tbodyScrap');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:18px; color:var(--text-muted);">Tidak ada data grup scrap yang sesuai filter.</td></tr>';
        return;
    }

    let html = '';
    const startNo = (currentPage - 1) * 25 + 1;

    data.forEach((r, idx) => {
        let limbahCls = 'limbah-nonlogam';
        if (r.jenis_limbah.includes('Logam')) limbahCls = 'limbah-logam';
        else if (r.jenis_limbah.includes('Avalan')) limbahCls = 'limbah-avalan';
        else if (r.jenis_limbah.includes('Kayu')) limbahCls = 'limbah-kayu';

        const switchChecked = (r.status === 1) ? 'checked' : '';

        html += `<tr>
            <td style="text-align:center; color:var(--text-muted);">${startNo + idx}</td>
            <td><span class="badge-code">${escapeHtml(r.kode_scrap)}</span></td>
            <td><b>${escapeHtml(r.material)}</b></td>
            <td style="text-align:center;"><span class="tag-limbah ${limbahCls}">${escapeHtml(r.jenis_limbah)}</span></td>
            <td style="text-align:center; font-weight:600;">${escapeHtml(r.satuan_standar)}</td>
            <td style="text-align:center; font-weight:600; color:var(--primary);">${r.item_count} Item</td>
            <td>${escapeHtml(r.keterangan)}</td>
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
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteItem(${r.id}, '${escapeHtml(r.material)}')" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;

    if (window.scrapGrid) {
        window.scrapGrid.refresh();
    } else if (typeof AntEnterpriseGrid !== 'undefined') {
        window.scrapGrid = new AntEnterpriseGrid('#tblScrap', {
            gridId: 'erp_grup_scrap',
            enableSort: true,
            enableResize: true,
            enableContextMenu: true,
            enablePagination: false,
            skipCols: [0, 9],
            exportTitle: 'Grup_Scrap',
            onEdit: (tr) => {
                const btn = tr.querySelector('button[onclick*="editItem"]');
                if (btn) btn.click();
            }
        });
    }
}

function renderPagination(total, page, limit, totalPages) {
    document.getElementById('pageInfo').innerText = `Menampilkan ${total ? ((page - 1) * limit + 1) : 0} - ${Math.min(page * limit, total)} dari ${total} grup scrap`;
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
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Tambah Grup Scrap';
    document.getElementById('formId').value = '';
    document.getElementById('formKodeScrap').value = '';
    document.getElementById('formMaterial').value = '';
    document.getElementById('formJenis').value = 'Limbah Logam Non-B3';
    document.getElementById('formSatuan').value = 'KGM';
    document.getElementById('formKeterangan').value = '';
    document.getElementById('formStatus').value = '1';
    document.getElementById('modalForm').classList.add('active');
}

function editItem(id) {
    const item = scrapCache.find(x => x.id === id);
    if (!item) return;

    document.getElementById('modalFormTitle').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit: ${escapeHtml(item.material)}`;
    document.getElementById('formId').value = item.id;
    document.getElementById('formKodeScrap').value = (item.kode_scrap === '-') ? '' : item.kode_scrap;
    document.getElementById('formMaterial').value = item.material;
    document.getElementById('formJenis').value = item.jenis_limbah;
    document.getElementById('formSatuan').value = item.satuan_standar;
    document.getElementById('formKeterangan').value = (item.keterangan === '-') ? '' : item.keterangan;
    document.getElementById('formStatus').value = item.status;
    document.getElementById('modalForm').classList.add('active');
}

function closeModal() {
    document.getElementById('modalForm').classList.remove('active');
}

async function submitForm() {
    const id        = document.getElementById('formId').value;
    const kodeScrap = document.getElementById('formKodeScrap').value.trim();
    const material  = document.getElementById('formMaterial').value.trim();
    const jenis     = document.getElementById('formJenis').value;
    const satuan    = document.getElementById('formSatuan').value.trim();
    const ket       = document.getElementById('formKeterangan').value.trim();
    const status    = document.getElementById('formStatus').value;

    if (!material) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Nama Grup Scrap wajib diisi!' });
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('kode_scrap', kodeScrap);
    fd.append('material', material);
    fd.append('jenis_limbah', jenis);
    fd.append('satuan_standar', satuan);
    fd.append('keterangan', ket);
    fd.append('status', status);

    try {
        const res = await fetch('grup_scrap.php?action=save', { method: 'POST', body: fd });
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
        const res = await fetch('grup_scrap.php?action=toggle_status', { method: 'POST', body: fd });
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
        text: `Hapus grup scrap ${nama}?`,
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
        const res = await fetch('grup_scrap.php?action=delete', { method: 'POST', body: fd });
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

async function syncFromScrap() {
    Swal.fire({
        title: 'Menyelaraskan...',
        text: 'Memindai barang dari Master Scrap...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const res = await fetch('grup_scrap.php?action=sync_from_scrap', { method: 'POST' });
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Selesai', text: json.message, timer: 2000, showConfirmButton: true });
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
    window.location.href = 'grup_scrap.php?action=export_csv';
}

function exportExcel() {
    if (!scrapCache || scrapCache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }
    const rows = scrapCache.map((r, i) => ({
        'No': i + 1,
        'Kode Scrap': r.kode_scrap,
        'Nama Grup Scrap': r.material,
        'Klasifikasi Limbah': r.jenis_limbah,
        'Satuan': r.satuan_standar,
        'Item Terhubung': r.item_count,
        'Keterangan': r.keterangan,
        'Status': (r.status === 1 ? 'Aktif' : 'Non-Aktif'),
        'Pencatat': r.set_by,
        'Terakhir Diperbarui': r.updated_at
    }));

    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Grup Scrap");
    XLSX.writeFile(wb, `Grup_Scrap_${new Date().toISOString().slice(0,10)}.xlsx`);
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

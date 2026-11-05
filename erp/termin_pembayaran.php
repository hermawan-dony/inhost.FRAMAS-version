<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Master Termin Pembayaran (Payment Terms)
 * ============================================================================
 * Acuan Asli PowerBuilder: w_ref_term.srw, d_ref_term.srd, ddw_ref_term.srd
 * Pengelolaan Syarat & Ketentuan Pembayaran (Term of Payment) Transaksi Bisnis,
 * Jatuh Tempo (Maturity Days), Diskon Pelunasan Awal (Early Discount),
 * Cash On Delivery / CBD, Integrasi Purchasing & Sales, serta Standardisasi
 * Desain Ant Design Compact Desktop 11px Identik Master Departemen.
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
// AUTO-HEAL: Pastikan Struktur Tabel bc_ref_term Tersedia Lengkap Multi-Tenant
// -------------------------------------------------------------------------
function ensureTerminTable($koneksi, $escCmp) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_ref_term'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ref_term` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `kode` varchar(30) NOT NULL DEFAULT '',
                `disc_range` int(11) DEFAULT 0,
                `disc` decimal(5,2) DEFAULT 0.00,
                `jatuhtempo` int(11) NOT NULL DEFAULT 30,
                `note` varchar(150) DEFAULT '',
                `iscod` varchar(1) NOT NULL DEFAULT 'N',
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_cmp_term_kode` (`cmp_id`, `kode`),
                KEY `idx_term_kode` (`kode`),
                KEY `idx_cmp_id` (`cmp_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Auto-heal kolom jika tabel sudah ada dari import sebelumnya
            $cols = [];
            $resCols = $koneksi->query("SHOW COLUMNS FROM bc_ref_term");
            if ($resCols) while ($r = $resCols->fetch_assoc()) $cols[strtolower($r['Field'])] = true;

            if (!isset($cols['id'])) {
                @$koneksi->query("ALTER TABLE bc_ref_term ADD COLUMN id BIGINT AUTO_INCREMENT UNIQUE FIRST");
            }
            if (!isset($cols['cmp_id'])) {
                @$koneksi->query("ALTER TABLE bc_ref_term ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT ''");
                @$koneksi->query("ALTER TABLE bc_ref_term ADD INDEX idx_cmp_id (cmp_id)");
            }
            if (!isset($cols['status'])) {
                @$koneksi->query("ALTER TABLE bc_ref_term ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!isset($cols['set_by'])) {
                @$koneksi->query("ALTER TABLE bc_ref_term ADD COLUMN set_by VARCHAR(100) DEFAULT ''");
            }
            if (!isset($cols['created_at'])) {
                @$koneksi->query("ALTER TABLE bc_ref_term ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            if (!isset($cols['updated_at'])) {
                @$koneksi->query("ALTER TABLE bc_ref_term ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
            @$koneksi->query("ALTER TABLE bc_ref_term MODIFY COLUMN kode VARCHAR(30) NOT NULL");
            @$koneksi->query("ALTER TABLE bc_ref_term MODIFY COLUMN note VARCHAR(150) DEFAULT ''");
        }

        // Seed data standar industri jika masih kosong
        $cnt = $koneksi->query("SELECT COUNT(*) FROM bc_ref_term WHERE cmp_id = '$escCmp'")->fetch_row()[0] ?? 0;
        if ((int)$cnt === 0) {
            $defaultTerms = [
                ['NET 30',      0,  0.00, 30, 'Pembayaran penuh dalam 30 hari kalender', 'N', 'SYSTEM - Auto Init'],
                ['NET 14',      0,  0.00, 14, 'Pembayaran penuh dalam 14 hari kalender', 'N', 'SYSTEM - Auto Init'],
                ['NET 45',      0,  0.00, 45, 'Pembayaran penuh dalam 45 hari kalender', 'N', 'SYSTEM - Auto Init'],
                ['NET 60',      0,  0.00, 60, 'Pembayaran penuh dalam 60 hari kalender', 'N', 'SYSTEM - Auto Init'],
                ['NET 90',      0,  0.00, 90, 'Pembayaran penuh dalam 90 hari kalender', 'N', 'SYSTEM - Auto Init'],
                ['CBD',         0,  0.00,  0, 'Cash Before Delivery (Pembayaran tunai di muka sebelum barang dikirim)', 'Y', 'SYSTEM - Auto Init'],
                ['COD',         0,  0.00,  0, 'Cash On Delivery (Pembayaran tunai saat barang diterima di lokasi)', 'Y', 'SYSTEM - Auto Init'],
                ['2/10 NET 30', 10, 2.00, 30, 'Diskon 2% jika lunas dalam 10 hari, jatuh tempo 30 hari', 'N', 'SYSTEM - Auto Init'],
                ['5/10 NET 60', 10, 5.00, 60, 'Diskon 5% jika lunas dalam 10 hari, jatuh tempo 60 hari', 'N', 'SYSTEM - Auto Init'],
                ['EOM',         0,  0.00, 30, 'End Of Month (Pelunasan pada akhir bulan berjalan)', 'N', 'SYSTEM - Auto Init']
            ];
            foreach ($defaultTerms as $t) {
                $koneksi->query("INSERT IGNORE INTO bc_ref_term (cmp_id, kode, disc_range, disc, jatuhtempo, note, iscod, status, set_by)
                                 VALUES ('$escCmp', '{$t[0]}', {$t[1]}, {$t[2]}, {$t[3]}, '{$t[4]}', '{$t[5]}', 1, '{$t[6]}')");
            }
        }
    } catch (\Throwable $ex) {}
}
ensureTerminTable($koneksi, $escCmp);

// =========================================================================
// AJAX BACKEND HANDLERS
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. GET MINI DASHBOARD KPI
if ($action === 'get_kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $w = "(cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)";
    $total    = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_term WHERE $w")->fetch_row()[0] ?? 0);
    $kredit   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_term WHERE $w AND iscod != 'Y' AND jatuhtempo > 0")->fetch_row()[0] ?? 0);
    $tunai    = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_term WHERE $w AND (iscod = 'Y' OR jatuhtempo = 0)")->fetch_row()[0] ?? 0);
    $diskon   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_term WHERE $w AND disc > 0")->fetch_row()[0] ?? 0);
    $aktif    = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_term WHERE $w AND (status = 1 OR status IS NULL)")->fetch_row()[0] ?? 0);

    echo json_encode([
        'status'       => 'OK',
        'total'        => $total,
        'kredit'       => $kredit,
        'tunai'        => $tunai,
        'diskon'       => $diskon,
        'aktif'        => $aktif
    ]);
    exit;
}

// 2. LIST DATA (PAGINATED & SEARCHABLE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? 'ALL');
    $tipe   = trim($_GET['tipe'] ?? 'ALL');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = ["(cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)"];
    if ($status === '1') {
        $where[] = "(status = 1 OR status IS NULL)";
    } elseif ($status === '0') {
        $where[] = "status = 0";
    }

    if ($tipe === 'TUNAI') {
        $where[] = "(iscod = 'Y' OR jatuhtempo = 0)";
    } elseif ($tipe === 'KREDIT') {
        $where[] = "(iscod != 'Y' AND jatuhtempo > 0)";
    } elseif ($tipe === 'DISKON') {
        $where[] = "disc > 0";
    }

    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(kode LIKE '%$sEsc%' OR note LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM bc_ref_term WHERE $wSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    $sql = "SELECT id, cmp_id, kode, disc_range, disc, jatuhtempo, note, iscod, 
                   COALESCE(status, 1) as status, set_by, updated_at
            FROM bc_ref_term 
            WHERE $wSql 
            ORDER BY status DESC, jatuhtempo ASC, kode ASC 
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'         => (int)$r['id'],
                'kode'       => $r['kode'],
                'jatuhtempo' => (int)$r['jatuhtempo'],
                'disc_range' => (int)$r['disc_range'],
                'disc'       => (float)$r['disc'],
                'iscod'      => ($r['iscod'] === 'Y') ? 'Y' : 'N',
                'note'       => $r['note'] ?: '-',
                'status'     => (int)$r['status'],
                'set_by'     => $r['set_by'] ?: 'SYSTEM',
                'updated_at' => $r['updated_at'] ? date('d M Y H:i', strtotime($r['updated_at'])) : '-'
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
    $id         = (int)($_POST['id'] ?? 0);
    $kode       = strtoupper(trim($_POST['kode'] ?? ''));
    $jatuhtempo = max(0, (int)($_POST['jatuhtempo'] ?? 0));
    $discRange  = max(0, (int)($_POST['disc_range'] ?? 0));
    $disc       = max(0.00, floatval($_POST['disc'] ?? 0));
    $iscod      = ($_POST['iscod'] ?? '') === 'Y' ? 'Y' : 'N';
    $note       = trim($_POST['note'] ?? '');
    $status     = ($_POST['status'] ?? '1') === '0' ? 0 : 1;
    $setby      = $currentUser . ' - ' . date('Y-m-d H:i');

    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Termin Pembayaran wajib diisi!']);
        exit;
    }

    $escKode  = $koneksi->real_escape_string($kode);
    $escNote  = $koneksi->real_escape_string($note);
    $escSetby = $koneksi->real_escape_string($setby);

    if ($id > 0) {
        $sql = "UPDATE bc_ref_term SET 
                kode = '$escKode',
                jatuhtempo = $jatuhtempo,
                disc_range = $discRange,
                disc = $disc,
                iscod = '$iscod',
                note = '$escNote',
                status = $status,
                set_by = '$escSetby'
                WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)";
        $res = $koneksi->query($sql);
        if ($res) {
            echo json_encode(['status' => 'OK', 'message' => "Termin $kode berhasil diperbarui"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui: ' . $koneksi->error]);
        }
    } else {
        $sql = "INSERT INTO bc_ref_term 
                (cmp_id, kode, jatuhtempo, disc_range, disc, iscod, note, status, set_by)
                VALUES 
                ('$escCmp', '$escKode', $jatuhtempo, $discRange, $disc, '$iscod', '$escNote', $status, '$escSetby')
                ON DUPLICATE KEY UPDATE 
                jatuhtempo = $jatuhtempo, disc_range = $discRange, disc = $disc, iscod = '$iscod', note = '$escNote', status = $status, set_by = '$escSetby'";
        $res = $koneksi->query($sql);
        if ($res) {
            echo json_encode(['status' => 'OK', 'message' => "Termin $kode berhasil ditambahkan"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menambahkan: ' . $koneksi->error]);
        }
    }
    exit;
}

// 4. TOGGLE STATUS AKTIF / NON-AKTIF
if ($action === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID termin tidak valid']);
        exit;
    }
    $upd = $koneksi->query("UPDATE bc_ref_term SET status = IF(status=1, 0, 1), set_by = '$currentUser - " . date('Y-m-d H:i') . "' 
                            WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($upd) {
        echo json_encode(['status' => 'OK', 'message' => 'Status termin pembayaran berhasil diubah']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $koneksi->error]);
    }
    exit;
}

// 5. HAPUS TERMIN
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID termin tidak valid']);
        exit;
    }
    $del = $koneksi->query("DELETE FROM bc_ref_term WHERE id = $id AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL)");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Termin pembayaran berhasil dihapus dari sistem']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 6. EXPORT CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Termin_Pembayaran_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'Kode Termin', 'Jatuh Tempo (Hari)', 'Periode Diskon (Hari)', 'Diskon (%)', 'Tipe Tunai (COD/CBD)', 'Keterangan / Klausul', 'Status', 'Pencatat', 'Terakhir Diperbarui']);
    $q = $koneksi->query("SELECT * FROM bc_ref_term WHERE (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY jatuhtempo ASC, kode ASC");
    $no = 1;
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($output, [
                $no++,
                $r['kode'],
                $r['jatuhtempo'],
                $r['disc_range'],
                $r['disc'],
                ($r['iscod'] === 'Y' ? 'Ya (Tunai/COD)' : 'Tidak (Kredit)'),
                $r['note'],
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

        /* Badges & Tags */
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
        .tag-cod {
            background: #fff7e6;
            color: #d46b08;
            border: 1px solid #ffd591;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .tag-credit {
            background: #e6f7ff;
            color: #096dd9;
            border: 1px solid #91d5ff;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
        }
        .tag-disc {
            background: #f6ffed;
            color: #389e0d;
            border: 1px solid #b7eb8f;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 10px;
        }

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
                <i class="fa-solid fa-clock-rotate-left" style="color:var(--primary);"></i>
                <span>Master Termin Pembayaran (Payment Terms)</span>
                <span class="ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div class="inline-box">
                <span style="font-size:10px; color:var(--text-muted);"><i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Acuan Asli PB: w_ref_term</span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Mini Dashboard KPI Strip -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div>
                    <div class="kpi-num" id="kpiTotal">0</div>
                    <div class="kpi-label">Total Termin Terdaftar</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-calendar-check"></i></div>
                <div>
                    <div class="kpi-num" id="kpiKredit">0</div>
                    <div class="kpi-label">Termin Kredit (Net)</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <div>
                    <div class="kpi-num" id="kpiTunai">0</div>
                    <div class="kpi-label">Tunai / COD / CBD</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;"><i class="fa-solid fa-percent"></i></div>
                <div>
                    <div class="kpi-num" id="kpiDiskon">0</div>
                    <div class="kpi-label">Diskon Pelunasan Cepat</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="kpi-num" id="kpiAktif">0</div>
                    <div class="kpi-label">Termin Aktif Operasional</div>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Tambah Termin</button>
            <div style="height:14px; width:1px; background:var(--border-color); margin:0 4px;"></div>
            <label style="font-size:10.5px; color:var(--text-muted);">Tipe:</label>
            <select id="filterTipe" class="ant-select" style="width:115px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua Tipe</option>
                <option value="KREDIT">Kredit (Tempo)</option>
                <option value="TUNAI">Tunai / COD / CBD</option>
                <option value="DISKON">Berdiskon</option>
            </select>
            <label style="font-size:10.5px; color:var(--text-muted); margin-left:4px;">Status:</label>
            <select id="filterStatus" class="ant-select" style="width:95px; height:24px;" onchange="loadList(1)">
                <option value="ALL">Semua</option>
                <option value="1" selected>Hanya Aktif</option>
                <option value="0">Non-Aktif</option>
            </select>
            <input type="text" id="searchKeyword" class="ant-input" placeholder="Cari Kode atau Klausul Keterangan..." style="width:190px;" onkeydown="if(event.key==='Enter') loadList(1)">
            <button type="button" class="ant-btn" onclick="loadList(1)"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            <div style="margin-left:auto; display:flex; gap:4px;">
                <button type="button" class="ant-btn" onclick="exportExcel()"><i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel</button>
                <button type="button" class="ant-btn" onclick="exportCsv()"><i class="fa-solid fa-file-csv"></i> CSV</button>
                <button type="button" class="ant-btn" onclick="loadList(currentPage)"><i class="fa-solid fa-rotate"></i> Refresh</button>
            </div>
        </div>

        <!-- Table Grid -->
        <div class="grid-wrap">
            <table class="dw" id="tblTerm">
                <thead>
                    <tr>
                        <th style="width:34px; text-align:center;">#</th>
                        <th class="th-highlight" style="width:130px;">Kode Termin</th>
                        <th style="width:110px; text-align:center;">Tipe Pembayaran</th>
                        <th style="width:115px; text-align:center;">Jatuh Tempo (Hari)</th>
                        <th style="width:140px; text-align:center;">Diskon Pelunasan</th>
                        <th>Keterangan / Klausul Faktur</th>
                        <th style="width:75px; text-align:center;">Status</th>
                        <th style="width:140px;">Audit Trail</th>
                        <th style="width:110px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tbodyTerm">
                    <tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);">Memuat data termin pembayaran...</td></tr>
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
            <span id="modalFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah Termin Pembayaran</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModal()"></i>
        </div>
        <div class="ant-modal-body">
            <input type="hidden" id="formId">
            <div class="form-group">
                <label>Kode Termin (Contoh: NET 30, COD, CBD, 2/10 NET 30): <span style="color:var(--danger)">*</span></label>
                <input type="text" id="formKode" class="ant-input" placeholder="Wajib diisi..." maxlength="30" style="text-transform:uppercase;">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Jatuh Tempo (Jumlah Hari):</label>
                    <input type="number" id="formJatuhtempo" class="ant-input" value="30" min="0" max="365">
                </div>
                <div class="form-group">
                    <label>Metode Tunai / COD:</label>
                    <select id="formIscod" class="ant-select" onchange="toggleCodFields()">
                        <option value="N">Tidak (Kredit / Tempo)</option>
                        <option value="Y">Ya (Tunai / COD / CBD)</option>
                    </select>
                </div>
            </div>
            <div class="form-row" id="groupDiskon">
                <div class="form-group">
                    <label>Batas Periode Diskon (Hari):</label>
                    <input type="number" id="formDiscRange" class="ant-input" value="0" min="0" max="60" placeholder="0 jika tidak ada diskon">
                </div>
                <div class="form-group">
                    <label>Besaran Diskon Cepat (%):</label>
                    <input type="number" id="formDisc" class="ant-input" value="0" min="0" max="100" step="0.1" placeholder="Contoh: 2 untuk 2%">
                </div>
            </div>
            <div class="form-group">
                <label>Keterangan / Klausul Faktur:</label>
                <input type="text" id="formNote" class="ant-input" placeholder="Penjelasan syarat pembayaran..." maxlength="150">
            </div>
            <div class="form-group">
                <label>Status Operasional:</label>
                <select id="formStatus" class="ant-select">
                    <option value="1">Aktif (Tampil di Dropdown PO/Invoice)</option>
                    <option value="0">Non-Aktif (Disembunyikan)</option>
                </select>
            </div>
            <div style="background:var(--row-hover); border:1px dashed var(--border-dark); border-radius:4px; padding:6px 8px; margin-top:6px; font-size:10px; color:var(--text-muted);">
                <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i> <b>Simulasi Klausul:</b> <span id="lblSimulasi">Pembayaran jatuh tempo dalam 30 hari kalender.</span>
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
            <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Modul &amp; SOP: Termin Pembayaran</span>
            <i class="fa-solid fa-xmark" style="cursor:pointer;" onclick="closeModuleGuide()"></i>
        </div>
        <div class="ant-modal-body" style="font-size:11px; line-height:1.6;">
            <h4 style="font-size:12px; margin-bottom:6px; color:var(--primary);"><i class="fa-solid fa-handshake"></i> Standar Operasional Prosedur (SOP) Syarat Pembayaran</h4>
            <p style="margin-bottom:8px;">Modul Termin Pembayaran berfungsi mengontrol syarat pembayaran transaksi pembelian (Purchase Order/Invoice) dan penjualan (Sales Order/Commercial Invoice):</p>
            <ul style="padding-left:18px; margin-bottom:10px;">
                <li><b>NET Days (Kredit):</b> Pelanggan atau perusahaan wajib melunasi faktur dalam kurun waktu jumlah hari kalender yang ditentukan sejak tanggal faktur (misal NET 30, NET 45, NET 60).</li>
                <li><b>Diskon Pelunasan Awal (Cash Discount):</b> Format <code>2/10 NET 30</code> artinya rekanan berhak atas potongan harga sebesar 2% apabila pembayaran dilunasi dalam waktu 10 hari, dengan batas toleransi maksimal pelunasan 30 hari.</li>
                <li><b>CBD &amp; COD (Tunai):</b> Digunakan untuk transaksi tunai tanpa tempo kredit, di mana <code>CBD</code> mensyaratkan pelunasan sebelum pengiriman, dan <code>COD</code> mensyaratkan pelunasan saat serah terima barang di gudang.</li>
            </ul>
            <h4 style="font-size:12px; margin-bottom:6px; color:var(--primary);"><i class="fa-solid fa-link"></i> Integrasi Otomatis ERP</h4>
            <p>Setiap termin pembayaran yang berstatus <b>Aktif</b> akan otomatis terhubung ke dropdown transaksi Modul Pengadaan (PO &amp; PI) dan Penjualan (SO &amp; SI) serta memvalidasi tanggal jatuh tempo pada kartu hutang/piutang akuntansi.</p>
        </div>
        <div class="ant-modal-footer">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Tutup Panduan</button>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let termCache = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function loadKpi() {
    try {
        const res = await fetch('termin_pembayaran.php?action=get_kpi');
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('kpiTotal').innerText = json.total;
            document.getElementById('kpiKredit').innerText = json.kredit;
            document.getElementById('kpiTunai').innerText = json.tunai;
            document.getElementById('kpiDiskon').innerText = json.diskon;
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
    const tipe   = document.getElementById('filterTipe').value;

    const tbody = document.getElementById('tbodyTerm');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>';

    try {
        const url = `termin_pembayaran.php?action=list&page=${page}&limit=25&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&tipe=${encodeURIComponent(tipe)}`;
        const res = await fetch(url);
        const json = await res.json();

        if (json.status === 'OK') {
            termCache = json.data;
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
    const tbody = document.getElementById('tbodyTerm');
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:18px; color:var(--text-muted);">Tidak ada data termin pembayaran yang sesuai filter.</td></tr>';
        return;
    }

    let html = '';
    const startNo = (currentPage - 1) * 25 + 1;

    data.forEach((r, idx) => {
        const isCod = (r.iscod === 'Y' || r.jatuhtempo === 0);
        const tipeTag = isCod 
            ? '<span class="tag-cod"><i class="fa-solid fa-money-bill-wave"></i> Tunai/COD</span>' 
            : '<span class="tag-credit"><i class="fa-solid fa-calendar-days"></i> Kredit</span>';

        const discTag = (r.disc > 0)
            ? `<span class="tag-disc"><i class="fa-solid fa-tag"></i> Diskon ${r.disc}% (${r.disc_range} hr)</span>`
            : '<span style="color:var(--text-muted);">-</span>';

        const switchChecked = (r.status === 1) ? 'checked' : '';

        html += `<tr>
            <td style="text-align:center; color:var(--text-muted);">${startNo + idx}</td>
            <td><span class="badge-code">${escapeHtml(r.kode)}</span></td>
            <td style="text-align:center;">${tipeTag}</td>
            <td style="text-align:center; font-weight:600;">${r.jatuhtempo} Hari</td>
            <td style="text-align:center;">${discTag}</td>
            <td>${escapeHtml(r.note)}</td>
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
                    <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="deleteItem(${r.id}, '${escapeHtml(r.kode)}')" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                </div>
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;

    if (window.termGrid) {
        window.termGrid.refresh();
    } else if (typeof AntEnterpriseGrid !== 'undefined') {
        window.termGrid = new AntEnterpriseGrid('#tblTerm', {
            gridId: 'erp_termin_pembayaran',
            enableSort: true,
            enableResize: true,
            enableContextMenu: true,
            enablePagination: false,
            skipCols: [0, 8],
            exportTitle: 'Termin_Pembayaran',
            onEdit: (tr) => {
                const btn = tr.querySelector('button[onclick*="editItem"]');
                if (btn) btn.click();
            }
        });
    }
}

function renderPagination(total, page, limit, totalPages) {
    document.getElementById('pageInfo').innerText = `Menampilkan ${total ? ((page - 1) * limit + 1) : 0} - ${Math.min(page * limit, total)} dari ${total} termin pembayaran`;
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
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Tambah Termin Pembayaran';
    document.getElementById('formId').value = '';
    document.getElementById('formKode').value = '';
    document.getElementById('formKode').readOnly = false;
    document.getElementById('formJatuhtempo').value = '30';
    document.getElementById('formDiscRange').value = '0';
    document.getElementById('formDisc').value = '0';
    document.getElementById('formIscod').value = 'N';
    document.getElementById('formNote').value = '';
    document.getElementById('formStatus').value = '1';
    updateSimulasi();
    document.getElementById('modalForm').classList.add('active');
}

function editItem(id) {
    const item = termCache.find(x => x.id === id);
    if (!item) return;

    document.getElementById('modalFormTitle').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit Termin: ${escapeHtml(item.kode)}`;
    document.getElementById('formId').value = item.id;
    document.getElementById('formKode').value = item.kode;
    document.getElementById('formKode').readOnly = true;
    document.getElementById('formJatuhtempo').value = item.jatuhtempo;
    document.getElementById('formDiscRange').value = item.disc_range;
    document.getElementById('formDisc').value = item.disc;
    document.getElementById('formIscod').value = item.iscod;
    document.getElementById('formNote').value = (item.note === '-') ? '' : item.note;
    document.getElementById('formStatus').value = item.status;
    updateSimulasi();
    document.getElementById('modalForm').classList.add('active');
}

function closeModal() {
    document.getElementById('modalForm').classList.remove('active');
}

function toggleCodFields() {
    const isCod = document.getElementById('formIscod').value === 'Y';
    if (isCod) {
        document.getElementById('formJatuhtempo').value = 0;
        document.getElementById('formDiscRange').value = 0;
        document.getElementById('formDisc').value = 0;
    }
    updateSimulasi();
}

function updateSimulasi() {
    const kode = document.getElementById('formKode').value.trim();
    const tempo = parseInt(document.getElementById('formJatuhtempo').value) || 0;
    const isCod = document.getElementById('formIscod').value === 'Y';
    const discRange = parseInt(document.getElementById('formDiscRange').value) || 0;
    const disc = parseFloat(document.getElementById('formDisc').value) || 0;

    let text = '';
    if (isCod || tempo === 0) {
        text = 'Transaksi tunai: Pelunasan dilakukan seketika saat penyerahan dokumen/barang (COD/CBD).';
    } else {
        text = `Pembayaran jatuh tempo dalam ${tempo} hari kalender.`;
        if (disc > 0 && discRange > 0) {
            text += ` Berhak atas diskon ${disc}% jika dilunasi dalam kurun ${discRange} hari pertama.`;
        }
    }
    document.getElementById('lblSimulasi').innerText = text;
}

document.getElementById('formJatuhtempo').addEventListener('input', updateSimulasi);
document.getElementById('formDiscRange').addEventListener('input', updateSimulasi);
document.getElementById('formDisc').addEventListener('input', updateSimulasi);
document.getElementById('formKode').addEventListener('input', updateSimulasi);

async function submitForm() {
    const id = document.getElementById('formId').value;
    const kode = document.getElementById('formKode').value.trim();
    const jatuhtempo = document.getElementById('formJatuhtempo').value;
    const disc_range = document.getElementById('formDiscRange').value;
    const disc = document.getElementById('formDisc').value;
    const iscod = document.getElementById('formIscod').value;
    const note = document.getElementById('formNote').value.trim();
    const status = document.getElementById('formStatus').value;

    if (!kode) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Kode termin pembayaran wajib diisi!' });
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('kode', kode);
    fd.append('jatuhtempo', jatuhtempo);
    fd.append('disc_range', disc_range);
    fd.append('disc', disc);
    fd.append('iscod', iscod);
    fd.append('note', note);
    fd.append('status', status);

    try {
        const res = await fetch('termin_pembayaran.php?action=save', { method: 'POST', body: fd });
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
        const res = await fetch('termin_pembayaran.php?action=toggle_status', { method: 'POST', body: fd });
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

async function deleteItem(id, kode) {
    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus termin pembayaran ${kode}? Pastikan termin ini tidak sedang digunakan pada transaksi berjalan.`,
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
        const res = await fetch('termin_pembayaran.php?action=delete', { method: 'POST', body: fd });
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
    window.location.href = 'termin_pembayaran.php?action=export_csv';
}

function exportExcel() {
    if (!termCache || termCache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }
    const rows = termCache.map((r, i) => ({
        'No': i + 1,
        'Kode Termin': r.kode,
        'Tipe': (r.iscod === 'Y' || r.jatuhtempo === 0 ? 'Tunai / COD' : 'Kredit (Tempo)'),
        'Jatuh Tempo (Hari)': r.jatuhtempo,
        'Periode Diskon (Hari)': r.disc_range,
        'Diskon (%)': r.disc,
        'Klausul / Keterangan': r.note,
        'Status': (r.status === 1 ? 'Aktif' : 'Non-Aktif'),
        'Pencatat': r.set_by,
        'Terakhir Diperbarui': r.updated_at
    }));

    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Termin Pembayaran");
    XLSX.writeFile(wb, `Termin_Pembayaran_${new Date().toISOString().slice(0,10)}.xlsx`);
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

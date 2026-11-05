<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Work Order (Surat Perintah Kerja Produksi / SPK)
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_wo.srw / bc_wo_hdr / bc_wo_dtl)
 * Terhubung ke tabel bc_wo_hdr & bc_wo_dtl dengan integrasi Bill of Material (BOM) bc_bom,
 * Sales Order bc_so_hdr, Customer bc_bs, dan Tracking Realisasi Produksi (qty_taken).
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);

// Auto-Heal: Pastikan tabel dan kolom bc_wo_hdr dan bc_wo_dtl tersedia
function ensureWoTables($koneksi) {
    $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_wo_hdr'");
    if (!$checkHdr || $checkHdr->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_wo_hdr` (
            `id` varchar(50) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `wo_no` varchar(30) NOT NULL DEFAULT '',
            `wo_type` enum('PRODUKSI','INTERNAL') DEFAULT 'PRODUKSI',
            `wo_dt` date DEFAULT NULL,
            `exp_dt` date DEFAULT NULL,
            `bs` varchar(30) DEFAULT '',
            `dept` varchar(50) DEFAULT '',
            `req_no` varchar(50) DEFAULT '',
            `note` varchar(255) DEFAULT '',
            `set_by` varchar(40) DEFAULT '',
            `status` varchar(1) DEFAULT '0',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cmp_wo` (`cmp_id`, `wo_no`),
            KEY `idx_wo_dt` (`wo_dt`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $cType = $koneksi->query("SHOW COLUMNS FROM bc_wo_hdr LIKE 'wo_type'");
        if (!$cType || $cType->num_rows === 0) {
            $koneksi->query("ALTER TABLE bc_wo_hdr ADD COLUMN `wo_type` ENUM('PRODUKSI', 'INTERNAL') DEFAULT 'PRODUKSI' AFTER `wo_no`");
        }
        $cDept = $koneksi->query("SHOW COLUMNS FROM bc_wo_hdr LIKE 'dept'");
        if (!$cDept || $cDept->num_rows === 0) {
            $koneksi->query("ALTER TABLE bc_wo_hdr ADD COLUMN `dept` VARCHAR(50) DEFAULT '' AFTER `bs`");
        }
        $cReq = $koneksi->query("SHOW COLUMNS FROM bc_wo_hdr LIKE 'req_no'");
        if (!$cReq || $cReq->num_rows === 0) {
            $koneksi->query("ALTER TABLE bc_wo_hdr ADD COLUMN `req_no` VARCHAR(50) DEFAULT '' AFTER `dept`");
        }
    }

    $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_wo_dtl'");
    if (!$checkDtl || $checkDtl->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_wo_dtl` (
            `id` varchar(50) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `id_hdr` varchar(50) NOT NULL DEFAULT '',
            `seq` int(11) DEFAULT '1',
            `kode_barang` varchar(50) DEFAULT '',
            `bom` varchar(50) DEFAULT '',
            `qty` decimal(15,4) DEFAULT '0.0000',
            `sat` varchar(10) DEFAULT '',
            `so_no` varchar(50) DEFAULT '',
            `status` varchar(1) DEFAULT '0',
            `set_by` varchar(40) DEFAULT '',
            `qty_taken` decimal(15,4) DEFAULT '0.0000',
            PRIMARY KEY (`id`),
            KEY `idx_cmp_hdr` (`cmp_id`, `id_hdr`),
            KEY `idx_kode_barang` (`kode_barang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
ensureWoTables($koneksi);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    
    $status = trim($_GET['status'] ?? '');
    $woType = strtoupper(trim($_GET['wo_type'] ?? ''));
    $search = trim($_GET['search'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(a.cmp_id = '$escCmp' OR a.cmp_id = '')";
    }
    if ($status !== '') {
        $stEsc = $koneksi->real_escape_string($status);
        $where[] = "a.status = '$stEsc'";
    }
    if ($woType !== '' && in_array($woType, ['PRODUKSI', 'INTERNAL'])) {
        $wtEsc = $koneksi->real_escape_string($woType);
        $where[] = "a.wo_type = '$wtEsc'";
    }
    if (!empty($search)) {
        $scEsc = $koneksi->real_escape_string($search);
        $where[] = "(a.wo_no LIKE '%$scEsc%' OR a.bs LIKE '%$scEsc%' OR b.nama LIKE '%$scEsc%' OR a.dept LIKE '%$scEsc%' OR a.req_no LIKE '%$scEsc%' OR a.note LIKE '%$scEsc%')";
    }
    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $countSql = "SELECT COUNT(*) as cnt FROM bc_wo_hdr a LEFT JOIN bc_bs b ON a.bs = b.kode $whereSql";
    $cRes = $koneksi->query($countSql);
    $total = $cRes ? (int)$cRes->fetch_assoc()['cnt'] : 0;

    $sql = "SELECT a.*, IFNULL(b.nama, a.bs) as customer_name,
            (SELECT COUNT(*) FROM bc_wo_dtl d WHERE d.id_hdr = a.id) as item_count,
            (SELECT SUM(IFNULL(qty, 0)) FROM bc_wo_dtl d WHERE d.id_hdr = a.id) as total_target,
            (SELECT SUM(IFNULL(qty_taken, 0)) FROM bc_wo_dtl d WHERE d.id_hdr = a.id) as total_taken
            FROM bc_wo_hdr a
            LEFT JOIN bc_bs b ON a.bs = b.kode
            $whereSql
            ORDER BY a.wo_dt DESC, a.wo_no DESC
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

    // KPI Summary
    $kpiSql = "SELECT 
                COUNT(*) as total_wo,
                SUM(CASE WHEN a.status = '1' THEN 1 ELSE 0 END) as in_prod,
                SUM(CASE WHEN a.status = '2' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN a.status = '0' THEN 1 ELSE 0 END) as draft
               FROM bc_wo_hdr a $whereSql";
    $kpiRes = $koneksi->query($kpiSql);
    $kpi = $kpiRes ? $kpiRes->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'total'  => $total,
        'page'   => $page,
        'limit'  => $limit,
        'kpi'    => $kpi
    ]);
    exit;
}

if ($action === 'get_wo') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Work Order tidak valid']);
        exit;
    }
    $escId = $koneksi->real_escape_string($id);

    $hdrRes = $koneksi->query("SELECT a.*, IFNULL(b.nama, a.bs) as customer_name 
                               FROM bc_wo_hdr a 
                               LEFT JOIN bc_bs b ON a.bs = b.kode 
                               WHERE a.id = '$escId' AND (a.cmp_id = '$escCmp' OR a.cmp_id = '') LIMIT 1");
    if (!$hdrRes || $hdrRes->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data Work Order tidak ditemukan']);
        exit;
    }
    $header = $hdrRes->fetch_assoc();

    $dtlRes = $koneksi->query("SELECT d.*, IFNULL(m.URAIAN, d.kode_barang) as nama_barang 
                               FROM bc_wo_dtl d 
                               LEFT JOIN bc_material m ON d.kode_barang = m.KODE_BARANG 
                               WHERE d.id_hdr = '$escId' ORDER BY d.seq ASC");
    $items = [];
    if ($dtlRes) while ($d = $dtlRes->fetch_assoc()) $items[] = $d;

    echo json_encode(['status' => 'OK', 'header' => $header, 'items' => $items]);
    exit;
}

if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data input JSON tidak valid']);
        exit;
    }

    $id             = trim($input['id'] ?? '');
    $wo_no          = trim($input['wo_no'] ?? '');
    $wo_type        = strtoupper(trim($input['wo_type'] ?? 'PRODUKSI'));
    if (!in_array($wo_type, ['PRODUKSI', 'INTERNAL'])) $wo_type = 'PRODUKSI';
    $wo_dt          = trim($input['wo_dt'] ?? date('Y-m-d'));
    $exp_dt         = trim($input['exp_dt'] ?? '') ?: null;
    $bs             = trim($input['bs'] ?? '');
    $dept           = trim($input['dept'] ?? '');
    $req_no         = trim($input['req_no'] ?? '');
    $note           = trim($input['note'] ?? '');
    $status         = trim($input['status'] ?? '0');
    $items          = $input['items'] ?? [];

    if (empty($wo_no)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Work Order (SPK) wajib diisi']);
        exit;
    }

    $isNew = empty($id);
    if ($isNew) {
        $id = 'WO-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $escId   = $koneksi->real_escape_string($id);
    $userSet = $_SESSION['user_name'] ?? 'SYSTEM';

    $koneksi->begin_transaction();
    try {
        if ($isNew) {
            $stmt = $koneksi->prepare("INSERT INTO bc_wo_hdr (
                id, cmp_id, wo_no, wo_type, wo_dt, exp_dt, bs, dept, req_no, note, set_by, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssssss",
                $id, $sessionCmpId, $wo_no, $wo_type, $wo_dt, $exp_dt, $bs, $dept, $req_no, $note, $userSet, $status
            );
            $stmt->execute();
        } else {
            $stmt = $koneksi->prepare("UPDATE bc_wo_hdr SET 
                wo_no = ?, wo_type = ?, wo_dt = ?, exp_dt = ?, bs = ?, dept = ?, req_no = ?, note = ?, status = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '')");
            $stmt->bind_param("sssssssssss",
                $wo_no, $wo_type, $wo_dt, $exp_dt, $bs, $dept, $req_no, $note, $status,
                $id, $sessionCmpId
            );
            $stmt->execute();

            $koneksi->query("DELETE FROM bc_wo_dtl WHERE id_hdr = '$escId'");
        }

        // Insert Detail Items
        $dStmt = $koneksi->prepare("INSERT INTO bc_wo_dtl (
            id, cmp_id, id_hdr, seq, kode_barang, bom, qty, sat, so_no, status, set_by, qty_taken
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        foreach ($items as $it) {
            $dtlId    = 'WOD-' . date('YmdHis') . '-' . $seq . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $kd       = trim($it['kode_barang'] ?? '');
            $bom      = trim($it['bom'] ?? '');
            $qty      = (float)($it['qty'] ?? 0);
            $sat      = trim($it['sat'] ?? '');
            $so_no    = trim($it['so_no'] ?? '');
            $dtlSt    = trim($it['status'] ?? '0');
            $qtyTaken = (float)($it['qty_taken'] ?? 0);

            if (empty($kd)) continue;

            $dStmt->bind_param("sssisdsssssd",
                $dtlId, $sessionCmpId, $id, $seq, $kd, $bom, $qty, $sat, $so_no, $dtlSt, $userSet, $qtyTaken
            );
            $dStmt->execute();
            $seq++;
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => 'Work Order berhasil disimpan', 'id' => $id]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Work Order tidak valid']);
        exit;
    }
    $escId = $koneksi->real_escape_string($id);

    $koneksi->begin_transaction();
    try {
        $koneksi->query("DELETE FROM bc_wo_dtl WHERE id_hdr = '$escId'");
        $koneksi->query("DELETE FROM bc_wo_hdr WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => 'Work Order berhasil dihapus']);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'lookup_bom') {
    header('Content-Type: application/json; charset=utf-8');
    $kd = trim($_GET['kode_barang'] ?? '');
    $escKd = $koneksi->real_escape_string($kd);
    $res = $koneksi->query("SELECT DISTINCT kode_bom, nama_bom FROM bc_bom WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND (kode_barang = '$escKd' OR kode_bom LIKE '%$escKd%') LIMIT 20");
    $list = [];
    if ($res) while ($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(['status' => 'OK', 'data' => $list]);
    exit;
}

if ($action === 'lookup_material_fg') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $escQ = $koneksi->real_escape_string($q);
    $res = $koneksi->query("SELECT kode_barang, nama_barang, satuan, klasifikasi FROM bc_material WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND (kode_barang LIKE '%$escQ%' OR nama_barang LIKE '%$escQ%') AND (klasifikasi IN ('Finished Goods', 'WIP', 'FG', 'SUB-ASSEMBLY') OR klasifikasi IS NULL OR klasifikasi = '') LIMIT 30");
    $list = [];
    if ($res) while ($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(['status' => 'OK', 'data' => $list]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Order (SPK Produksi) - CEISA 4.0 & Enterprise Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="/assets/sweetalert2.all.min.js">        window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <script src="/assets/ant_grid.js">        window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <script>
        (function() {
            try {
                var urlParams = new URLSearchParams(window.location.search);
                var t = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
                if (t === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark-mode');
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch(e) {}
        })();
            window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-sub: #8c8c8c;
            --border: #d9d9d9;
            --border-light: #e8e8e8;
            --table-head-bg: #fafafa;
        }
        body[data-theme="dark"], html.dark-mode, body.dark-mode {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-sub: rgba(255, 255, 255, 0.45);
            --border: #303030;
            --border-light: #424242;
            --table-head-bg: #1d1d1d;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 9.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
        }

        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 6px; margin-bottom: 6px; }
        .kpi-card { background: var(--bg-card); border-radius: 3px; padding: 6px 10px; border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.02); display: flex; align-items: center; justify-content: space-between; }
        .kpi-title { font-size: 9px; font-weight: 600; color: var(--text-sub); text-transform: uppercase; margin-bottom: 2px; }
        .kpi-val { font-size: 13px; font-weight: 700; color: var(--text-main); }
        .kpi-icon { width: 26px; height: 26px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 12px; }

        .card { background: var(--bg-card); border-radius: 3px; border: 1px solid var(--border); box-shadow: 0 1px 2px rgba(0,0,0,0.02); overflow: hidden; }
        .card-header { padding: 6px 10px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
        .card-title { font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .card-title i { color: var(--purple); }

        .toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .input-text, .select-box { padding: 1px 6px; font-size: 9.5px; height: 21px; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-card); color: var(--text-main); outline: none; }
        .input-text:focus, .select-box:focus { border-color: var(--primary); }
        .btn { padding: 0 8px; font-size: 9.5px; height: 21px; font-weight: 500; border-radius: 2px; border: 1px solid var(--border); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all .15s; background: var(--bg-card); color: var(--text-main); }
        .btn-purple { background: var(--purple); color: #fff; border-color: var(--purple); }
        .btn-purple:hover { background: #7c3aed; color: #fff; }
        .btn-default { background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border); }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        .btn-danger { background: var(--danger); color: #fff; border-color: var(--danger); }

        .table-responsive { width: 100%; overflow-x: auto; }
        table.data-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 9.5px; }
        table.data-table th { background: var(--table-head-bg); padding: 4px 6px; font-size: 9.5px; font-weight: 600; color: var(--text-sub); border: 1px solid var(--border); white-space: nowrap; }
        table.data-table td { padding: 2px 6px; font-size: 9.5px; border: 1px solid var(--border-light); vertical-align: middle; white-space: nowrap; }
        table.data-table tbody tr:hover { background: rgba(139, 92, 246, 0.04); }

        .badge { display: inline-block; padding: 1px 5px; font-size: 9px; font-weight: 600; border-radius: 2px; }
        .badge-draft { background: #f5f5f5; color: #595959; border: 1px solid #d9d9d9; }
        .badge-prod { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; }
        .badge-done { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .badge-cancel { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }

        .progress-bar-bg { width: 70px; height: 5px; background: #e5e7eb; border-radius: 2px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 4px; }
        .progress-bar-fill { height: 100%; background: var(--purple); }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 12px; backdrop-filter: blur(2px); }
        .modal-overlay.active { display: flex; }
        .modal-dialog { background: var(--bg-card); border-radius: 4px; width: 100%; max-width: 950px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border: 1px solid var(--border); }
        .modal-header { padding: 6px 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; font-weight: 600; font-size: 12px; }
        .modal-body { padding: 10px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 6px 12px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 6px; background: var(--bg-base); }

        .form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 8px; }
        .form-group { display: flex; flex-direction: column; gap: 2px; }
        .form-group label { font-size: 9.5px; font-weight: 500; color: var(--text-sub); }
        .col-2 { grid-column: span 2; }
        .col-4 { grid-column: span 4; }

        .items-table { width: 100%; border-collapse: collapse; margin-top: 6px; border: 1px solid var(--border); font-size: 9.5px; }
        .items-table th { background: var(--table-head-bg); padding: 4px 6px; font-size: 9.5px; font-weight: 600; border: 1px solid var(--border); text-align: left; }
        .items-table td { padding: 2px 6px; border: 1px solid var(--border-light); font-size: 9.5px; }
        .items-table input { width: 100%; padding: 1px 6px; font-size: 9.5px; height: 21px; border: 1px solid var(--border); border-radius: 2px; }
    </style>
</head>
<body>

    <!-- KPI Widgets -->
    <div class="kpi-row">
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Total Work Order (SPK)</div>
                <div class="kpi-val" id="kpiTotalWo">0</div>
            </div>
            <div class="kpi-icon" style="background:#ede9fe; color:#8b5cf6;"><i class="fa-solid fa-industry"></i></div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Dalam Proses Produksi</div>
                <div class="kpi-val" id="kpiInProd" style="color:var(--primary);">0</div>
            </div>
            <div class="kpi-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-gears"></i></div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Selesai Produksi</div>
                <div class="kpi-val" id="kpiCompleted" style="color:var(--success);">0</div>
            </div>
            <div class="kpi-icon" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Draft / Rencana</div>
                <div class="kpi-val" id="kpiDraft">0</div>
            </div>
            <div class="kpi-icon" style="background:#f3f4f6; color:#6b7280;"><i class="fa-solid fa-file-pen"></i></div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid fa-clipboard-list"></i>
                <span>Work Order (Surat Perintah Kerja Produksi / SPK)</span>
            </div>
            <div class="toolbar">
                <select id="filterWoType" class="select-box" onchange="loadData(1)" style="font-weight:600;">
                    <option value="">Semua Tipe WO</option>
                    <option value="PRODUKSI">WO Produksi (Sales / Komersial)</option>
                    <option value="INTERNAL">WO Internal (Departemen / Proyek)</option>
                </select>
                <input type="text" id="filterSearch" class="input-text" placeholder="Cari No WO / Dept / Catatan..." style="width:190px;" onkeyup="if(event.key==='Enter') loadData(1)">
                <select id="filterStatus" class="select-box" onchange="loadData(1)">
                    <option value="">Semua Status</option>
                    <option value="0">Draft</option>
                    <option value="1">Dalam Proses (In Production)</option>
                    <option value="2">Selesai (Completed)</option>
                    <option value="9">Batal (Canceled)</option>
                </select>
                <button class="btn btn-default" onclick="loadData(1)"><i class="fa-solid fa-rotate"></i> Muat</button>
                <button type="button" class="btn btn-default" onclick="openModuleGuide()" title="Panduan Modul & SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
                <button class="btn btn-purple" onclick="openModal()"><i class="fa-solid fa-plus"></i> SPK Baru</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table ant-grid-table" id="woTable">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>No WO (SPK)</th>
                        <th style="width:130px;">Tipe WO</th>
                        <th>Tgl Mulai</th>
                        <th>Target Selesai</th>
                        <th>Customer / Departemen</th>
                        <th>Jml Item</th>
                        <th style="text-align:right;">Target Output</th>
                        <th style="text-align:right;">Realisasi</th>
                        <th>Progress (%)</th>
                        <th>Status</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-sub);">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>

        <div style="padding:8px 14px; display:flex; align-items:center; justify-content:space-between; border-top:1px solid var(--border); font-size:11px;">
            <span id="pageInfo">Menampilkan 0 data</span>
            <div style="display:flex; gap:4px;">
                <button class="btn btn-default" id="btnPrev" onclick="changePage(-1)"><i class="fa-solid fa-chevron-left"></i></button>
                <button class="btn btn-default" id="btnNext" onclick="changePage(1)"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <!-- Modal Form WO -->
    <div class="modal-overlay" id="modalForm">
        <div class="modal-dialog">
            <div class="modal-header">
                <span id="modalTitle"><i class="fa-solid fa-clipboard-list"></i> Entri Perintah Kerja (Work Order)</span>
                <button style="border:none; background:none; font-size:16px; cursor:pointer;" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="formId">
                <div class="form-grid">
                    <div class="form-group col-2" style="background:var(--bg-sub, #f8fafc); padding:8px 12px; border-radius:6px; border:1px solid var(--border);">
                        <label style="font-weight:700; color:var(--text); margin-bottom:5px; display:block;">Klasifikasi / Tipe Work Order *</label>
                        <div style="display:flex; gap:20px; align-items:center;">
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600; color:var(--primary);">
                                <input type="radio" name="formWoType" value="PRODUKSI" checked onchange="toggleWoTypeUI()">
                                <i class="fa-solid fa-cart-shopping"></i> WO Produksi (Pesanan Sales / Komersial)
                            </label>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600; color:#7e22ce;">
                                <input type="radio" name="formWoType" value="INTERNAL" onchange="toggleWoTypeUI()">
                                <i class="fa-solid fa-building-user"></i> WO Internal (Departemen: Maintenance, Moldshop, R&amp;D)
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>No WO (SPK No) *</label>
                        <input type="text" id="formWoNo" class="input-text" placeholder="WO/2026/0001">
                    </div>
                    <div class="form-group">
                        <label>Tgl WO *</label>
                        <input type="date" id="formWoDt" class="input-text" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Target Selesai (Due Date)</label>
                        <input type="date" id="formExpDt" class="input-text">
                    </div>
                    <div class="form-group">
                        <label>Status Produksi</label>
                        <select id="formStatus" class="select-box">
                            <option value="0">Draft</option>
                            <option value="1">Dalam Proses (In Production)</option>
                            <option value="2">Selesai (Completed)</option>
                            <option value="9">Batal (Canceled)</option>
                        </select>
                    </div>

                    <div class="form-group col-2" id="grpCustomer">
                        <label>Customer / Pemesan (Sales Order)</label>
                        <div style="display:flex; gap:4px;">
                            <input type="text" id="formBs" class="input-text" placeholder="Kode Customer" style="width:100px;">
                            <input type="text" id="formBsName" class="input-text" placeholder="Nama Customer / Proyek..." style="flex:1;">
                        </div>
                    </div>
                    <div class="form-group" id="grpDept" style="display:none;">
                        <label>Departemen Pemohon *</label>
                        <select id="formDept" class="select-box">
                            <option value="">-- Pilih Departemen --</option>
                            <option value="MAINTENANCE">Maintenance &amp; Bengkel</option>
                            <option value="MOLDSHOP">Moldshop &amp; Tooling</option>
                            <option value="RND">R&amp;D / Trial Sample</option>
                            <option value="GENERAL">Fasilitas Umum &amp; Pabrik</option>
                            <option value="INTERNAL_USE">Pemakaian Sendiri</option>
                        </select>
                    </div>
                    <div class="form-group" id="grpReqNo" style="display:none;">
                        <label>No Memo / Dokumen Permintaan</label>
                        <input type="text" id="formReqNo" class="input-text" placeholder="Contoh: REQ/MNT/2026/014">
                    </div>
                    <div class="form-group col-2">
                        <label>Catatan / Instruksi Kerja</label>
                        <input type="text" id="formNote" class="input-text" placeholder="Keterangan spesifikasi / target produksi...">
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                    <span style="font-size:11px; font-weight:700;"><i class="fa-solid fa-dolly"></i> Produk yang Akan Diproduksi (FG / WIP)</span>
                    <button type="button" class="btn btn-default" onclick="addItemRow()"><i class="fa-solid fa-plus"></i> Tambah Item Produksi</button>
                </div>
                <div class="table-responsive" style="max-height:240px; overflow-y:auto; margin-top:5px;">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th style="width:25px;">#</th>
                                <th style="width:160px;">Kode Barang Jadi/WIP</th>
                                <th style="width:120px;">Formula BOM</th>
                                <th style="width:90px;">Qty Target</th>
                                <th style="width:60px;">Satuan</th>
                                <th style="width:130px;">Ref Sales Order (SO)</th>
                                <th style="width:90px;">Qty Selesai</th>
                                <th style="width:30px;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeModal()">Batal</button>
                <button type="button" class="btn btn-purple" onclick="saveData()"><i class="fa-solid fa-save"></i> Simpan Work Order</button>
            </div>
        </div>
    </div>

    <!-- Modal Panduan SOP Work Order -->
    <div class="modal-overlay" id="modalGuide">
        <div class="modal-dialog" style="max-width:760px;">
            <div class="modal-header">
                <span><i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan Bisnis &amp; SOP Work Order (Dual System)</span>
                <button style="border:none; background:none; font-size:16px; cursor:pointer;" onclick="closeModuleGuide()">&times;</button>
            </div>
            <div class="modal-body" style="font-size:11px; line-height:1.6; max-height:480px; overflow-y:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px;">
                    <div>
                        <strong style="color:#1d4ed8; font-size:12px;"><i class="fa-solid fa-book"></i> Dokumentasi Arsitektur Resmi</strong>
                        <div style="color:#3b82f6; font-size:10px;">Petunjuk Teknis Dual Work Order, Konsumsi Material, &amp; Traceability CEISA 4.0</div>
                    </div>
                    <a href="/docs/PANDUAN_ARSITEKTUR_WO_TRACEABILITY_CEISA4.docx" class="btn btn-purple" download style="text-decoration:none;">
                        <i class="fa-solid fa-file-word"></i> Unduh Dokumen Resmi (.docx)
                    </a>
                </div>

                <h4 style="font-size:12px; margin:8px 0 4px 0; color:var(--text);"><i class="fa-solid fa-arrows-split-up-and-left"></i> 1. Konsep Dual Work Order</h4>
                <p>Modul Work Order membagi perintah kerja ke dalam dua klasifikasi tegas untuk menjaga kepatuhan pelaporan Kawasan Berikat / KEK &amp; IT Inventory:</p>
                <ul style="padding-left:18px; margin:4px 0 10px 0;">
                    <li><strong>WO Produksi (Pesanan Sales / Komersial):</strong> Dibuat berdasarkan pesanan pelanggan (Sales Order). Menghasilkan Barang Jadi (Finished Goods) yang nantinya dikirim via Surat Jalan (DO), ditagihkan via Sales Invoice, dan bahan baku impor/fasilitasnya otomatis ter-trace untuk dokumen Bea Cukai (Eks-BC).</li>
                    <li><strong>WO Internal (Departemen / Proyek Non-Komersial):</strong> Dibuat atas permintaan divisi internal (Maintenance, Moldshop, R&amp;D/Trial, Fasilitas Umum). Pemakaian bahan baku dicatat sebagai pengeluaran operasional pabrik.</li>
                </ul>

                <h4 style="font-size:12px; margin:8px 0 4px 0; color:var(--text);"><i class="fa-solid fa-calculator"></i> 2. Alur Traceability &amp; Pengeluaran Parsial</h4>
                <p>Jika Sales Invoice atau Surat Jalan dikirim secara bertahap (parsial):</p>
                <ul style="padding-left:18px; margin:4px 0 10px 0;">
                    <li>Sistem mengalokasikan bahan baku secara proporsional sesuai rasio pengiriman terhadap target Work Order.</li>
                    <li>Histori pemotongan bahan baku tersimpan permanen sehingga tidak terjadi klaim ganda atas nomor pendaftaran dokumen pemasukan (AJU BC).</li>
                    <li>Data invoice terdahulu yang telah memiliki riwayat penelusuran tetap terjaga 100% tanpa perubahan (backward compatible).</li>
                </ul>

                <h4 style="font-size:12px; margin:8px 0 4px 0; color:var(--text);"><i class="fa-solid fa-shield-halved"></i> 3. Kepatuhan IT Inventory &amp; CEISA 4.0</h4>
                <p>Setiap bahan baku yang dikeluarkan untuk WO wajib merujuk pada stok penerimaan fisik gudang yang bersumber dari Dokumen Pemasukan Pabean resmi, menjamin kesesuaian antara pencatatan pembukuan pabrik dengan data Host-to-Host Direktorat Jenderal Bea dan Cukai.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeModuleGuide()">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        let curPage = 1;
        const curLimit = 25;
        let itemRows = [];

        function escapeHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num || 0);
        }

        function openModuleGuide() {
            document.getElementById('modalGuide').classList.add('active');
        }

        function closeModuleGuide() {
            document.getElementById('modalGuide').classList.remove('active');
        }

        function toggleWoTypeUI() {
            const type = document.querySelector('input[name="formWoType"]:checked')?.value || 'PRODUKSI';
            const grpCust = document.getElementById('grpCustomer');
            const grpDept = document.getElementById('grpDept');
            const grpReq = document.getElementById('grpReqNo');
            if (type === 'INTERNAL') {
                if (grpCust) grpCust.style.display = 'none';
                if (grpDept) grpDept.style.display = 'block';
                if (grpReq) grpReq.style.display = 'block';
            } else {
                if (grpCust) grpCust.style.display = 'block';
                if (grpDept) grpDept.style.display = 'none';
                if (grpReq) grpReq.style.display = 'none';
            }
        }

        function loadData(page = 1) {
            curPage = page;
            const search = document.getElementById('filterSearch').value;
            const status = document.getElementById('filterStatus').value;
            const woType = document.getElementById('filterWoType') ? document.getElementById('filterWoType').value : '';

            document.getElementById('tableBody').innerHTML = '<tr><td colspan="12" style="text-align:center; padding:15px;">Memuat data...</td></tr>';

            fetch(`work_order.php?action=list&page=${page}&limit=${curLimit}&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&wo_type=${encodeURIComponent(woType)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'OK') {
                        document.getElementById('tableBody').innerHTML = `<tr><td colspan="12" style="text-align:center; color:red;">${res.message || 'Gagal memuat data'}</td></tr>`;
                        return;
                    }
                    renderTable(res.data, (page - 1) * curLimit);
                    renderKpi(res.kpi);
                    document.getElementById('pageInfo').innerText = `Menampilkan ${res.data.length} dari ${res.total} data (Halaman ${res.page})`;
                })
                .catch(err => {
                    document.getElementById('tableBody').innerHTML = `<tr><td colspan="12" style="text-align:center; color:red;">Koneksi error: ${err.message}</td></tr>`;
                });
        }

        function renderKpi(kpi) {
            if (!kpi) return;
            document.getElementById('kpiTotalWo').innerText = formatNumber(kpi.total_wo).replace(',00', '');
            document.getElementById('kpiInProd').innerText = formatNumber(kpi.in_prod).replace(',00', '');
            document.getElementById('kpiCompleted').innerText = formatNumber(kpi.completed).replace(',00', '');
            document.getElementById('kpiDraft').innerText = formatNumber(kpi.draft).replace(',00', '');
        }

        function renderTable(data, offset) {
            const tb = document.getElementById('tableBody');
            if (!data || data.length === 0) {
                tb.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-sub);">Tidak ada data Work Order ditemukan.</td></tr>';
                return;
            }
            let html = '';
            data.forEach((r, idx) => {
                let stBadge = '<span class="badge badge-draft">Draft</span>';
                if (r.status === '1') stBadge = '<span class="badge badge-prod">In Production</span>';
                else if (r.status === '2') stBadge = '<span class="badge badge-done">Completed</span>';
                else if (r.status === '9') stBadge = '<span class="badge badge-cancel">Canceled</span>';

                const target = Number(r.total_target) || 0;
                const taken = Number(r.total_taken) || 0;
                const pct = target > 0 ? Math.min(100, Math.round((taken / target) * 100)) : 0;

                const isInternal = (r.wo_type || '').toUpperCase() === 'INTERNAL';
                const typeBadge = isInternal
                    ? `<span class="badge" style="background:#f3e8ff; color:#7e22ce; border:1px solid #d8b4fe;"><i class="fa-solid fa-building-user"></i> INTERNAL</span>`
                    : `<span class="badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;"><i class="fa-solid fa-cart-shopping"></i> PRODUKSI (SO)</span>`;

                const partyDisplay = isInternal
                    ? `<strong>${escapeHtml(r.dept || 'Internal')}</strong>${r.req_no ? ' <span style="color:var(--text-sub); font-size:9px;">(Req: ' + escapeHtml(r.req_no) + ')</span>' : ''}`
                    : `${escapeHtml(r.customer_name || r.bs || '-')}`;

                html += `<tr>
                    <td>${offset + idx + 1}</td>
                    <td><strong>${escapeHtml(r.wo_no)}</strong></td>
                    <td>${typeBadge}</td>
                    <td>${r.wo_dt || '-'}</td>
                    <td>${r.exp_dt || '-'}</td>
                    <td>${partyDisplay}</td>
                    <td style="text-align:center;">${r.item_count || 0} item</td>
                    <td style="text-align:right; font-weight:700;">${formatNumber(target)}</td>
                    <td style="text-align:right; color:var(--success); font-weight:700;">${formatNumber(taken)}</td>
                    <td>
                        <div class="progress-bar-bg"><div class="progress-bar-fill" style="width:${pct}%;"></div></div>
                        <span style="font-size:10px; font-weight:700;">${pct}%</span>
                    </td>
                    <td>${stBadge}</td>
                    <td style="text-align:center; white-space:nowrap;">
                        <button class="btn btn-default" style="padding:2px 6px;" title="Edit" onclick="editData('${r.id}')"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button class="btn btn-danger" style="padding:2px 6px;" title="Hapus" onclick="deleteData('${r.id}', '${r.wo_no}')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            });
            tb.innerHTML = html;
            if (window.AntEnterpriseGrid) {
                if (!window.woGrid) {
                    window.woGrid = new AntEnterpriseGrid('#woTable', { gridId: 'grid_work_order', enablePagination: false });
                } else {
                    window.woGrid.refresh();
                }
            }
        }

        function changePage(delta) {
            const target = curPage + delta;
            if (target >= 1) loadData(target);
        }

        function openModal() {
            document.getElementById('formId').value = '';
            document.getElementById('formWoNo').value = 'WO/' + new Date().getFullYear() + '/' + String(Math.floor(Math.random()*9000)+1000);
            document.getElementById('formWoDt').value = new Date().toISOString().split('T')[0];
            document.getElementById('formExpDt').value = '';
            document.getElementById('formBs').value = '';
            document.getElementById('formBsName').value = '';
            document.getElementById('formDept').value = '';
            document.getElementById('formReqNo').value = '';
            document.getElementById('formNote').value = '';
            document.getElementById('formStatus').value = '1';

            const rProd = document.querySelector('input[name="formWoType"][value="PRODUKSI"]');
            if (rProd) rProd.checked = true;
            toggleWoTypeUI();

            itemRows = [];
            addItemRow();
            document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-plus"></i> Buat Work Order (SPK) Baru';
            document.getElementById('modalForm').classList.add('active');
        }

        function closeModal() {
            document.getElementById('modalForm').classList.remove('active');
        }

        function addItemRow(it = null) {
            const obj = it || { kode_barang: '', bom: '', qty: 100, sat: 'PCE', so_no: '', qty_taken: 0, status: '0' };
            itemRows.push(obj);
            renderItemRows();
        }

        function removeItemRow(idx) {
            itemRows.splice(idx, 1);
            if (itemRows.length === 0) addItemRow();
            renderItemRows();
        }

        function renderItemRows() {
            const tb = document.getElementById('itemsBody');
            let html = '';
            itemRows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center;">${idx + 1}</td>
                    <td><input type="text" value="${r.kode_barang || ''}" onchange="itemRows[${idx}].kode_barang=this.value" placeholder="Kode Barang Jadi..."></td>
                    <td><input type="text" value="${r.bom || ''}" onchange="itemRows[${idx}].bom=this.value" placeholder="Resep BOM..."></td>
                    <td><input type="number" step="0.01" value="${r.qty || 0}" onchange="itemRows[${idx}].qty=this.value;"></td>
                    <td><input type="text" value="${r.sat || ''}" onchange="itemRows[${idx}].sat=this.value" style="width:50px;"></td>
                    <td><input type="text" value="${r.so_no || ''}" onchange="itemRows[${idx}].so_no=this.value" placeholder="Ref SO..."></td>
                    <td><input type="number" step="0.01" value="${r.qty_taken || 0}" onchange="itemRows[${idx}].qty_taken=this.value;"></td>
                    <td style="text-align:center;"><button type="button" style="border:none; background:none; color:red; cursor:pointer;" onclick="removeItemRow(${idx})"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>`;
            });
            tb.innerHTML = html;
            if (window.AntEnterpriseGrid) {
                if (!window.woGrid) {
                    window.woGrid = new AntEnterpriseGrid('#woTable', { gridId: 'grid_work_order', enablePagination: false });
                } else {
                    window.woGrid.refresh();
                }
            }
        }

        function editData(id) {
            fetch(`work_order.php?action=get_wo&id=${encodeURIComponent(id)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'OK') {
                        Swal.fire('Error', res.message, 'error');
                        return;
                    }
                    const h = res.header;
                    document.getElementById('formId').value = h.id;
                    document.getElementById('formWoNo').value = h.wo_no || '';
                    document.getElementById('formWoDt').value = h.wo_dt || '';
                    document.getElementById('formExpDt').value = h.exp_dt || '';
                    document.getElementById('formBs').value = h.bs || '';
                    document.getElementById('formBsName').value = h.customer_name || h.bs || '';
                    document.getElementById('formDept').value = h.dept || '';
                    document.getElementById('formReqNo').value = h.req_no || '';
                    document.getElementById('formNote').value = h.note || '';
                    document.getElementById('formStatus').value = h.status || '1';

                    const woType = (h.wo_type || 'PRODUKSI').toUpperCase();
                    const rTarget = document.querySelector(`input[name="formWoType"][value="${woType}"]`);
                    if (rTarget) rTarget.checked = true;
                    toggleWoTypeUI();

                    itemRows = res.items && res.items.length > 0 ? res.items : [{ kode_barang: '', bom: '', qty: 100, sat: 'PCE', so_no: '', qty_taken: 0 }];
                    renderItemRows();

                    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Work Order #' + h.wo_no;
                    document.getElementById('modalForm').classList.add('active');
                });
        }

        function saveData() {
            const wo_no = document.getElementById('formWoNo').value.trim();
            if (!wo_no) {
                Swal.fire('Peringatan', 'Nomor Work Order (SPK) wajib diisi!', 'warning');
                return;
            }

            const wo_type = document.querySelector('input[name="formWoType"]:checked')?.value || 'PRODUKSI';
            const dept = document.getElementById('formDept') ? document.getElementById('formDept').value.trim() : '';
            const req_no = document.getElementById('formReqNo') ? document.getElementById('formReqNo').value.trim() : '';

            if (wo_type === 'INTERNAL' && !dept) {
                Swal.fire('Peringatan', 'Untuk Work Order Internal, Departemen Pemohon wajib dipilih!', 'warning');
                return;
            }

            const payload = {
                id: document.getElementById('formId').value,
                wo_no: wo_no,
                wo_type: wo_type,
                wo_dt: document.getElementById('formWoDt').value,
                exp_dt: document.getElementById('formExpDt').value,
                bs: document.getElementById('formBs').value,
                dept: dept,
                req_no: req_no,
                note: document.getElementById('formNote').value,
                status: document.getElementById('formStatus').value,
                items: itemRows
            };

            fetch('work_order.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'OK') {
                    Swal.fire('Berhasil', res.message, 'success');
                    closeModal();
                    loadData(curPage);
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            })
            .catch(err => {
                Swal.fire('Error', err.message, 'error');
            });
        }

        function deleteData(id, no) {
            Swal.fire({
                title: 'Hapus Work Order?',
                text: `Apakah Anda yakin ingin menghapus SPK ${no}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus!'
            }).then(result => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', id);
                    fetch('work_order.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status === 'OK') {
                                Swal.fire('Terhapus', res.message, 'success');
                                loadData(curPage);
                            } else {
                                Swal.fire('Gagal', res.message, 'error');
                            }
                        });
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadData(1);
        });
            window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
</body>
</html>

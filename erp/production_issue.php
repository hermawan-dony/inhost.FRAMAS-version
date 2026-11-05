<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Production Issue (Pengeluaran Bahan Baku & Hasil Produksi)
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_issue.srw & w_issue2.srw)
 * Terhubung ke tabel bc_issue_hdr & bc_issue_dtl dengan integrasi Work Order bc_wo_hdr,
 * Bill of Material (BOM), dan IT Inventory 1C/1D.
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);

// Auto-Heal: Pastikan tabel bc_issue_hdr dan bc_issue_dtl tersedia
function ensureIssueTables($koneksi) {
    $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_issue_hdr'");
    if (!$checkHdr || $checkHdr->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_issue_hdr` (
            `id` varchar(50) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `tgl` date DEFAULT NULL,
            `no_bukti` varchar(50) DEFAULT NULL,
            `dept` varchar(50) DEFAULT '',
            `AB` varchar(1) DEFAULT 'A',
            `klas` varchar(30) DEFAULT '',
            `pic` varchar(30) DEFAULT '',
            `setby` varchar(30) DEFAULT '',
            `iscancel` varchar(1) DEFAULT '0',
            `wo_no` varchar(30) DEFAULT '',
            `fg` varchar(50) DEFAULT '',
            `wo_id` varchar(50) DEFAULT '',
            `bppb_no` varchar(40) DEFAULT '',
            `note` varchar(255) DEFAULT '',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cmp_issue` (`cmp_id`, `no_bukti`),
            KEY `idx_tgl` (`tgl`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_issue_dtl'");
    if (!$checkDtl || $checkDtl->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_issue_dtl` (
            `id` varchar(50) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `id_hdr` varchar(50) NOT NULL DEFAULT '',
            `seq` int(11) DEFAULT '1',
            `kode_barang` varchar(50) DEFAULT '',
            `nama_barang` varchar(255) DEFAULT '',
            `qty` decimal(18,4) DEFAULT '0.0000',
            `sat` varchar(10) DEFAULT '',
            `note` varchar(255) DEFAULT '',
            `wo` varchar(30) DEFAULT '',
            `setby` varchar(30) DEFAULT '',
            `harga` decimal(18,2) DEFAULT '0.00',
            PRIMARY KEY (`id`),
            KEY `idx_cmp_hdr` (`cmp_id`, `id_hdr`),
            KEY `idx_kode_barang` (`kode_barang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
ensureIssueTables($koneksi);

// Mode: A = Pengeluaran Bahan Baku (BOM), B = Penerimaan Hasil Produksi (FG/WIP)
$mode = strtoupper(trim($_GET['mode'] ?? 'A'));
if ($mode !== 'B') $mode = 'A';

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    
    $reqMode = strtoupper(trim($_GET['mode'] ?? 'A'));
    $search  = trim($_GET['search'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset  = ($page - 1) * $limit;

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(a.cmp_id = '$escCmp' OR a.cmp_id = '')";
    }
    $where[] = "a.AB = '$reqMode'";
    if (!empty($search)) {
        $scEsc = $koneksi->real_escape_string($search);
        $where[] = "(a.no_bukti LIKE '%$scEsc%' OR a.wo_no LIKE '%$scEsc%' OR a.dept LIKE '%$scEsc%' OR a.pic LIKE '%$scEsc%' OR a.note LIKE '%$scEsc%')";
    }
    $whereSql = "WHERE " . implode(" AND ", $where);

    $countSql = "SELECT COUNT(*) as cnt FROM bc_issue_hdr a $whereSql";
    $cRes = $koneksi->query($countSql);
    $total = $cRes ? (int)$cRes->fetch_assoc()['cnt'] : 0;

    $sql = "SELECT a.*,
            (SELECT COUNT(*) FROM bc_issue_dtl d WHERE d.id_hdr = a.id) as item_count,
            (SELECT SUM(IFNULL(qty, 0)) FROM bc_issue_dtl d WHERE d.id_hdr = a.id) as total_qty
            FROM bc_issue_hdr a
            $whereSql
            ORDER BY a.tgl DESC, a.no_bukti DESC
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

    // KPI Summary
    $kpiSql = "SELECT 
                COUNT(*) as total_trans,
                SUM(CASE WHEN a.iscancel = '0' OR a.iscancel IS NULL THEN 1 ELSE 0 END) as total_active,
                SUM(CASE WHEN a.iscancel = '1' THEN 1 ELSE 0 END) as total_cancel
               FROM bc_issue_hdr a $whereSql";
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

if ($action === 'get_issue') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Transaksi tidak valid']);
        exit;
    }
    $escId = $koneksi->real_escape_string($id);

    $hdrRes = $koneksi->query("SELECT * FROM bc_issue_hdr WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
    if (!$hdrRes || $hdrRes->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data Bukti Issue tidak ditemukan']);
        exit;
    }
    $header = $hdrRes->fetch_assoc();

    $dtlRes = $koneksi->query("SELECT * FROM bc_issue_dtl WHERE id_hdr = '$escId' ORDER BY seq ASC");
    $items = [];
    if ($dtlRes) while ($d = $dtlRes->fetch_assoc()) $items[] = $d;

    echo json_encode(['status' => 'OK', 'header' => $header, 'items' => $items]);
    exit;
}

// 4. SCAN LOT QR / BARCODE (AUTO RESOLVE KEPABEANAN & NOMOR AJU)
if ($action === 'scan_lot') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/lot_helper.php';
    $scanText = trim($_POST['scan_text'] ?? $_GET['scan_text'] ?? '');
    if (empty($scanText)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Barcode / QR tidak boleh kosong']);
        exit;
    }
    $lot = parseAndResolveLot($koneksi, $sessionCmpId, $scanText);
    if ($lot) {
        echo json_encode(['status' => 'OK', 'lot' => $lot]);
    } else {
        echo json_encode(['status' => 'NOT_FOUND', 'message' => "Lot atau Barcode '$scanText' tidak ditemukan di stok gudang."]);
    }
    exit;
}

// 5. GET ACTIVE LOTS UNTUK MODAL PICKER
if ($action === 'get_active_lots') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/lot_helper.php';
    $search = trim($_GET['search'] ?? '');
    $lots = getActiveLots($koneksi, $sessionCmpId, $search, 100);
    echo json_encode(['status' => 'OK', 'lots' => $lots]);
    exit;
}

// 6. GET APPROVED MATERIAL REQUESTS (BPPB) UNTUK TARIK KE ISSUE
if ($action === 'get_approved_mr') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $escCmp = $koneksi->real_escape_string($sessionCmpId);
    $where = "WHERE (cmp_id = '{$escCmp}' OR cmp_id = '') AND status IN ('APPROVED', 'PARTIAL')";
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where .= " AND (req_no LIKE '%{$escS}%' OR dept_pemohon LIKE '%{$escS}%' OR wo_no LIKE '%{$escS}%' OR pic LIKE '%{$escS}%')";
    }
    $resHdr = $koneksi->query("SELECT * FROM bc_issue_dept_req_hdr {$where} ORDER BY req_date DESC, id DESC LIMIT 50");
    $list = [];
    if ($resHdr) {
        while ($h = $resHdr->fetch_assoc()) {
            $hdrId = $h['id'];
            $qDtl = $koneksi->query("SELECT * FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$hdrId}' AND qty_appr > qty_issued ORDER BY seq ASC");
            $items = [];
            if ($qDtl) while ($d = $qDtl->fetch_assoc()) $items[] = $d;
            if (count($items) > 0) {
                $h['items'] = $items;
                $list[] = $h;
            }
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $list]);
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
    $no_bukti       = trim($input['no_bukti'] ?? '');
    $tgl            = trim($input['tgl'] ?? date('Y-m-d'));
    $AB             = trim($input['AB'] ?? 'A');
    $dept           = trim($input['dept'] ?? 'PRODUKSI');
    $pic            = trim($input['pic'] ?? '');
    $wo_no          = trim($input['wo_no'] ?? '');
    $fg             = trim($input['fg'] ?? '');
    $bppb_no        = trim($input['bppb_no'] ?? '');
    $note           = trim($input['note'] ?? '');
    $items          = $input['items'] ?? [];

    if (empty($no_bukti)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Bukti Transaksi wajib diisi']);
        exit;
    }

    $isNew = empty($id);
    if ($isNew) {
        $id = 'ISS-' . $AB . '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $escId   = $koneksi->real_escape_string($id);
    $userSet = $_SESSION['user_name'] ?? 'SYSTEM';

    $koneksi->begin_transaction();
    try {
        if ($isNew) {
            $stmt = $koneksi->prepare("INSERT INTO bc_issue_hdr (
                id, cmp_id, no_bukti, tgl, AB, dept, pic, wo_no, fg, bppb_no, note, setby, iscancel
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')");
            $stmt->bind_param("ssssssssssss",
                $id, $sessionCmpId, $no_bukti, $tgl, $AB, $dept, $pic, $wo_no, $fg, $bppb_no, $note, $userSet
            );
            $stmt->execute();
        } else {
            // Revert sisa stok lot lama sebelum dihapus
            $oldDtls = $koneksi->query("SELECT bc_wh_lot_id, qty FROM bc_issue_dtl WHERE id_hdr = '$escId' AND bc_wh_lot_id > 0");
            if ($oldDtls) {
                while ($od = $oldDtls->fetch_assoc()) {
                    $lotId = intval($od['bc_wh_lot_id']);
                    $revQty = floatval($od['qty']);
                    $koneksi->query("UPDATE bc_wh_lot SET qty_remaining = qty_remaining + {$revQty}, status = 'ACTIVE' WHERE id = {$lotId}");
                }
            }

            $stmt = $koneksi->prepare("UPDATE bc_issue_hdr SET 
                no_bukti = ?, tgl = ?, dept = ?, pic = ?, wo_no = ?, fg = ?, bppb_no = ?, note = ?
                WHERE id = ? AND (cmp_id = ? OR cmp_id = '')");
            $stmt->bind_param("ssssssssss",
                $no_bukti, $tgl, $dept, $pic, $wo_no, $fg, $bppb_no, $note,
                $id, $sessionCmpId
            );
            $stmt->execute();

            $koneksi->query("DELETE FROM bc_issue_dtl WHERE id_hdr = '$escId'");
        }

        // Insert Detail Items dengan dukungan Lot Warehouse & Nomor AJU Pabean
        $dStmt = $koneksi->prepare("INSERT INTO bc_issue_dtl (
            id, cmp_id, id_hdr, seq, kode_barang, nama_barang, qty, sat, note, wo, setby, harga,
            lot_no, no_aju, no_bc, tgl_bc, bc_wh_lot_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $seq = 1;
        foreach ($items as $it) {
            $dtlId   = 'ISD-' . date('YmdHis') . '-' . $seq . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $kd      = trim($it['kode_barang'] ?? '');
            $nm      = trim($it['nama_barang'] ?? '');
            $qty     = (float)($it['qty'] ?? 0);
            $sat     = trim($it['sat'] ?? '');
            $dtlNote = trim($it['note'] ?? '');
            $woRef   = trim($it['wo'] ?? $wo_no);
            $harga   = (float)($it['harga'] ?? 0);

            $lotNo   = trim($it['lot_no'] ?? '');
            $noAju   = trim($it['no_aju'] ?? '');
            $noBc    = trim($it['no_bc'] ?? '');
            $tglBc   = !empty($it['tgl_bc']) ? trim($it['tgl_bc']) : null;
            $lotId   = intval($it['bc_wh_lot_id'] ?? 0);

            if (empty($kd)) continue;

            $dStmt->bind_param("sssisdsssssdssssi",
                $dtlId, $sessionCmpId, $id, $seq, $kd, $nm, $qty, $sat, $dtlNote, $woRef, $userSet, $harga,
                $lotNo, $noAju, $noBc, $tglBc, $lotId
            );
            $dStmt->execute();

            // Potong sisa stok fisik di bc_wh_lot
            if ($lotId > 0 && $qty > 0) {
                $koneksi->query("UPDATE bc_wh_lot SET qty_remaining = GREATEST(0, qty_remaining - {$qty}), status = IF(qty_remaining - {$qty} <= 0, 'DEPLETED', 'ACTIVE') WHERE id = {$lotId}");
            } elseif (!empty($lotNo) && $qty > 0) {
                $escLotNo = $koneksi->real_escape_string($lotNo);
                $koneksi->query("UPDATE bc_wh_lot SET qty_remaining = GREATEST(0, qty_remaining - {$qty}), status = IF(qty_remaining - {$qty} <= 0, 'DEPLETED', 'ACTIVE') WHERE cmp_id = '{$sessionCmpId}' AND lot_no = '{$escLotNo}' LIMIT 1");
            }

            $seq++;
        }

        // Sinkronisasi pemenuhan permintaan barang (BPPB) jika terhubung
        if (!empty($bppb_no)) {
            $escBppb = $koneksi->real_escape_string($bppb_no);
            $mrHdr = $koneksi->query("SELECT id FROM bc_issue_dept_req_hdr WHERE req_no = '{$escBppb}' AND (cmp_id = '{$sessionCmpId}' OR cmp_id = '') LIMIT 1")->fetch_assoc();
            if ($mrHdr) {
                $mrId = $mrHdr['id'];
                foreach ($items as $it) {
                    $kd = trim($it['kode_barang'] ?? '');
                    $qty = floatval($it['qty'] ?? 0);
                    if (!empty($kd) && $qty > 0) {
                        $escKd = $koneksi->real_escape_string($kd);
                        $koneksi->query("UPDATE bc_issue_dept_req_dtl SET qty_issued = qty_issued + {$qty} WHERE id_hdr = '{$mrId}' AND kode_barang = '{$escKd}'");
                    }
                }
                $chkUnfulfilled = $koneksi->query("SELECT 1 FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$mrId}' AND qty_issued < qty_appr LIMIT 1");
                $newMrStatus = ($chkUnfulfilled && $chkUnfulfilled->num_rows > 0) ? 'PARTIAL' : 'COMPLETED';
                $koneksi->query("UPDATE bc_issue_dept_req_hdr SET status = '{$newMrStatus}' WHERE id = '{$mrId}'");
            }
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => 'Bukti Transaksi Issue Produksi berhasil disimpan', 'id' => $id]);
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
        echo json_encode(['status' => 'ERROR', 'message' => 'ID tidak valid']);
        exit;
    }
    $escId = $koneksi->real_escape_string($id);

    $koneksi->begin_transaction();
    try {
        // Revert sisa stok lot
        $oldDtls = $koneksi->query("SELECT bc_wh_lot_id, qty FROM bc_issue_dtl WHERE id_hdr = '$escId' AND bc_wh_lot_id > 0");
        if ($oldDtls) {
            while ($od = $oldDtls->fetch_assoc()) {
                $lotId = intval($od['bc_wh_lot_id']);
                $revQty = floatval($od['qty']);
                $koneksi->query("UPDATE bc_wh_lot SET qty_remaining = qty_remaining + {$revQty}, status = 'ACTIVE' WHERE id = {$lotId}");
            }
        }

        $koneksi->query("DELETE FROM bc_issue_dtl WHERE id_hdr = '$escId'");
        $koneksi->query("DELETE FROM bc_issue_hdr WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => 'Transaksi Issue berhasil dihapus']);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $mode === 'A' ? 'Issue Bahan Baku (BOM)' : 'Bukti Hasil Produksi (FG/WIP)' ?> - CEISA 4.0 & Enterprise Hub</title>
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
            --cyan: #06b6d4;
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
        .card-title i { color: var(--cyan); }

        .toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .input-text, .select-box { padding: 1px 6px; font-size: 9.5px; height: 21px; border: 1px solid var(--border); border-radius: 2px; background: var(--bg-card); color: var(--text-main); outline: none; }
        .input-text:focus, .select-box:focus { border-color: var(--primary); }
        .btn { padding: 0 8px; font-size: 9.5px; height: 21px; font-weight: 500; border-radius: 2px; border: 1px solid var(--border); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all .15s; background: var(--bg-card); color: var(--text-main); }
        .btn-cyan { background: var(--cyan); color: #fff; border-color: var(--cyan); }
        .btn-cyan:hover { background: #0891b2; color: #fff; }
        .btn-default { background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border); }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        .btn-danger { background: var(--danger); color: #fff; border-color: var(--danger); }

        .table-responsive { width: 100%; overflow-x: auto; }
        table.data-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 9.5px; }
        table.data-table th { background: var(--table-head-bg); padding: 4px 6px; font-size: 9.5px; font-weight: 600; color: var(--text-sub); border: 1px solid var(--border); white-space: nowrap; }
        table.data-table td { padding: 2px 6px; font-size: 9.5px; border: 1px solid var(--border-light); vertical-align: middle; white-space: nowrap; }
        table.data-table tbody tr:hover { background: rgba(6, 182, 212, 0.04); }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 12px; backdrop-filter: blur(2px); }
        .modal-overlay.active { display: flex; }
        .modal-dialog { background: var(--bg-card); border-radius: 4px; width: 100%; max-width: 1180px; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border: 1px solid var(--border); }
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

        .badge-lot { display: inline-flex; align-items: center; gap: 3px; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-weight: 600; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-family: monospace; }
        .badge-aju { display: inline-block; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-family: monospace; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .badge-stock { display: inline-block; padding: 1px 5px; border-radius: 2px; font-size: 9px; font-weight: 700; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    </style>
</head>
<body>

    <!-- KPI Row -->
    <div class="kpi-row">
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Total Transaksi</div>
                <div class="kpi-val" id="kpiTotalTrans">0</div>
            </div>
            <div class="kpi-icon" style="background:#cffafe; color:#0891b2;"><i class="fa-solid fa-boxes-packing"></i></div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Transaksi Aktif</div>
                <div class="kpi-val" id="kpiActive" style="color:var(--success);">0</div>
            </div>
            <div class="kpi-icon" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-check"></i></div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Dibatalkan (Canceled)</div>
                <div class="kpi-val" id="kpiCancel" style="color:var(--danger);">0</div>
            </div>
            <div class="kpi-icon" style="background:#fee2e2; color:#b91c1c;"><i class="fa-solid fa-ban"></i></div>
        </div>
        <div class="kpi-card">
            <div>
                <div class="kpi-title">Tipe Modul</div>
                <div class="kpi-val" style="font-size:12px; font-weight:700; color:var(--cyan);"><?= $mode === 'A' ? 'Pengeluaran Bahan Baku' : 'Hasil Produksi FG/WIP' ?></div>
            </div>
            <div class="kpi-icon" style="background:#ede9fe; color:#8b5cf6;"><i class="fa-solid fa-layer-group"></i></div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid <?= $mode === 'A' ? 'fa-arrow-up-right-from-square' : 'fa-arrow-down-left-and-up-right-to-center' ?>"></i>
                <span><?= $mode === 'A' ? 'Issue Produksi (BOM - Pengeluaran Bahan Baku)' : 'Issue Produksi (FG/WIP - Bukti Penerimaan Hasil Produksi)' ?></span>
            </div>
            <div class="toolbar">
                <button type="button" class="btn btn-default" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
                <input type="text" id="filterSearch" class="input-text" placeholder="Cari No Bukti / No WO / PIC..." style="width:200px;" onkeyup="if(event.key==='Enter') loadData(1)">
                <button class="btn btn-default" onclick="loadData(1)"><i class="fa-solid fa-rotate"></i> Muat</button>
                <button class="btn btn-cyan" onclick="openModal()"><i class="fa-solid fa-plus"></i> Transaksi Baru</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table ant-grid-table" id="issueTable">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>No Bukti</th>
                        <th>Tanggal</th>
                        <th>Departemen</th>
                        <th>Ref Work Order (WO)</th>
                        <th>Barang Jadi (FG)</th>
                        <th>PIC</th>
                        <th>Jml Item</th>
                        <th style="text-align:right;">Total Qty</th>
                        <th>Catatan</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-sub);">Memuat data...</td></tr>
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

    <!-- Modal Form -->
    <div class="modal-overlay" id="modalForm">
        <div class="modal-dialog">
            <div class="modal-header">
                <span id="modalTitle"><i class="fa-solid fa-boxes-packing"></i> Entri Transaksi Issue Produksi</span>
                <button style="border:none; background:none; font-size:16px; cursor:pointer;" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="formId">
                <input type="hidden" id="formAB" value="<?= $mode ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>No Bukti Transaksi *</label>
                        <input type="text" id="formNoBukti" class="input-text" placeholder="BUKTI/2026/0001">
                    </div>
                    <div class="form-group">
                        <label>Tanggal *</label>
                        <input type="date" id="formTgl" class="input-text" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Departemen</label>
                        <input type="text" id="formDept" class="input-text" value="PRODUKSI">
                    </div>
                    <div class="form-group">
                        <label>PIC / Petugas</label>
                        <input type="text" id="formPic" class="input-text" placeholder="Nama Petugas...">
                    </div>

                    <div class="form-group">
                        <label>Nomor Work Order (Ref SPK)</label>
                        <input type="text" id="formWoNo" class="input-text" placeholder="WO/2026/...">
                    </div>
                    <div class="form-group">
                        <label>Ref Permintaan (BPPB / MR)</label>
                        <input type="text" id="formBppbNo" class="input-text" placeholder="MR/2026/... (Opsional)">
                    </div>
                    <div class="form-group col-2">
                        <label>Barang Jadi / Target Produksi</label>
                        <input type="text" id="formFg" class="input-text" placeholder="Nama / Kode Produk Jadi...">
                    </div>

                    <div class="form-group col-4">
                        <label>Catatan / Keterangan Transaksi</label>
                        <input type="text" id="formNote" class="input-text" placeholder="Keterangan alokasi produksi...">
                    </div>
                </div>

                <!-- Box Barcode / QR Scanner & Quick Actions -->
                <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:6px; padding:8px 12px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-weight:700; color:#15803d; font-size:11px;"><i class="fa-solid fa-qrcode"></i> Scan QR Pemasukan / AJU:</span>
                        <input type="text" id="scanQrInput" class="input-text" placeholder="Arahkan scanner ke QR / ketik Nomor AJU / Bukti & tekan Enter..." style="width:360px; font-weight:600; border-color:#16a34a; background:#fff;" onkeydown="if(event.key==='Enter') { event.preventDefault(); processScanInput(); }">
                        <button type="button" class="btn btn-default" onclick="processScanInput()" style="color:#15803d; font-weight:600;"><i class="fa-solid fa-magnifying-glass"></i> Cari Data</button>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-default" onclick="openLotPickerModal()" style="color:#0284c7; font-weight:600;" title="Pilih dari stok pemasukan gudang yang tersedia"><i class="fa-solid fa-boxes-stacked"></i> Pilih Stok Pemasukan (AJU)</button>
                        <button type="button" class="btn btn-default" onclick="openMrPickerModal()" style="color:#d97706; font-weight:600;" title="Tarik dari daftar Permintaan Barang (BPPB) yang sudah disetujui"><i class="fa-solid fa-file-signature"></i> Tarik dari Permintaan (BPPB)</button>
                        <button type="button" class="btn btn-default" onclick="addItemRow()" title="Tambah baris manual"><i class="fa-solid fa-plus"></i> Tambah Baris</button>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:5px; margin-bottom:4px;">
                    <span style="font-size:11px; font-weight:700;"><i class="fa-solid fa-list-check"></i> Rincian Pengeluaran Bahan Baku &amp; Traceability Dokumen Pabean (AJU)</span>
                    <span id="itemsSummary" style="font-size:10.5px; color:var(--text-sub);">0 item terdaftar</span>
                </div>
                <div class="table-responsive" style="max-height:280px; overflow-y:auto; border:1px solid var(--border); border-radius:4px;">
                    <table class="items-table" style="margin-top:0; border:none;">
                        <thead>
                            <tr>
                                <th style="width:25px; text-align:center;">#</th>
                                <th style="width:140px;">Bukti Masuk (RI/PI)</th>
                                <th style="width:120px;">Kode Barang</th>
                                <th>Nama Barang / Deskripsi</th>
                                <th style="width:150px;">Dokumen Pabean (AJU)</th>
                                <th style="width:75px; text-align:right;">Sisa Stok</th>
                                <th style="width:85px; text-align:right;">Qty Issue *</th>
                                <th style="width:55px;">Satuan</th>
                                <th style="width:85px;">Harga</th>
                                <th style="width:100px;">Catatan</th>
                                <th style="width:30px; text-align:center;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeModal()">Batal</button>
                <button type="button" class="btn btn-cyan" onclick="saveData()"><i class="fa-solid fa-save"></i> Simpan Transaksi</button>
            </div>
        </div>
    </div>

    <!-- Modal Picker: Stok Lot Gudang (bc_wh_lot) -->
    <div class="modal-overlay" id="modalLotPicker">
        <div class="modal-dialog" style="max-width:980px;">
            <div class="modal-header">
                <span><i class="fa-solid fa-boxes-stacked" style="color:#0284c7;"></i> Pilih Stok Pemasukan Gudang Tersedia (Traceability AJU)</span>
                <button style="border:none; background:none; font-size:16px; cursor:pointer;" onclick="closeLotPickerModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px;">
                    <input type="text" id="lotSearchInput" class="input-text" placeholder="Cari No Bukti / Kode Barang / Nama / No AJU..." style="width:340px;" onkeyup="if(event.key==='Enter') loadActiveLots()">
                    <button class="btn btn-default" onclick="loadActiveLots()"><i class="fa-solid fa-search"></i> Cari</button>
                </div>
                <div class="table-responsive" style="max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:4px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sumber Bukti</th>
                                <th>Dokumen BC &amp; No Daftar</th>
                                <th>Nomor AJU (26 Digit)</th>
                                <th>Kode Barang</th>
                                <th>Nama Barang</th>
                                <th style="text-align:right;">Sisa Stok</th>
                                <th>Satuan</th>
                                <th>Lokasi Rak</th>
                                <th style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="lotPickerBody">
                            <tr><td colspan="9" style="text-align:center; padding:15px;">Memuat data stok pemasukan...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeLotPickerModal()">Tutup</button>
            </div>
        </div>
    </div>

    <!-- Modal Picker: Permintaan Barang ke Gudang (BPPB / MR Approved) -->
    <div class="modal-overlay" id="modalMrPicker">
        <div class="modal-dialog" style="max-width:950px;">
            <div class="modal-header">
                <span><i class="fa-solid fa-file-signature" style="color:#d97706;"></i> Tarik dari Permintaan Barang (BPPB Disetujui)</span>
                <button style="border:none; background:none; font-size:16px; cursor:pointer;" onclick="closeMrPickerModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px;">
                    <input type="text" id="mrSearchInput" class="input-text" placeholder="Cari No BPPB / Dept / Pemohon / WO..." style="width:320px;" onkeyup="if(event.key==='Enter') loadApprovedMrs()">
                    <button class="btn btn-default" onclick="loadApprovedMrs()"><i class="fa-solid fa-search"></i> Cari</button>
                </div>
                <div class="table-responsive" style="max-height:360px; overflow-y:auto; border:1px solid var(--border); border-radius:4px;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No Permintaan (BPPB)</th>
                                <th>Tanggal</th>
                                <th>Dept Pemohon</th>
                                <th>Ref WO</th>
                                <th>PIC</th>
                                <th>Rincian Barang &amp; Qty Disetujui</th>
                                <th style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="mrPickerBody">
                            <tr><td colspan="7" style="text-align:center; padding:15px;">Memuat data permintaan barang...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" onclick="closeMrPickerModal()">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        const curMode = '<?= $mode ?>';
        let curPage = 1;
        const curLimit = 25;
        let itemRows = [];

        function formatNumber(num) {
            return new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num || 0);
        }

        function loadData(page = 1) {
            curPage = page;
            const search = document.getElementById('filterSearch').value;

            document.getElementById('tableBody').innerHTML = '<tr><td colspan="11" style="text-align:center; padding:15px;">Memuat data...</td></tr>';

            fetch(`production_issue.php?action=list&mode=${curMode}&page=${page}&limit=${curLimit}&search=${encodeURIComponent(search)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'OK') {
                        document.getElementById('tableBody').innerHTML = `<tr><td colspan="11" style="text-align:center; color:red;">${res.message || 'Gagal memuat data'}</td></tr>`;
                        return;
                    }
                    renderTable(res.data, (page - 1) * curLimit);
                    renderKpi(res.kpi);
                    document.getElementById('pageInfo').innerText = `Menampilkan ${res.data.length} dari ${res.total} data (Halaman ${res.page})`;
                })
                .catch(err => {
                    document.getElementById('tableBody').innerHTML = `<tr><td colspan="11" style="text-align:center; color:red;">Koneksi error: ${err.message}</td></tr>`;
                });
        }

        function renderKpi(kpi) {
            if (!kpi) return;
            document.getElementById('kpiTotalTrans').innerText = formatNumber(kpi.total_trans).replace(',00', '');
            document.getElementById('kpiActive').innerText = formatNumber(kpi.total_active).replace(',00', '');
            document.getElementById('kpiCancel').innerText = formatNumber(kpi.total_cancel).replace(',00', '');
        }

        function renderTable(data, offset) {
            const tb = document.getElementById('tableBody');
            if (!data || data.length === 0) {
                tb.innerHTML = '<tr><td colspan="11" style="text-align:center; padding:20px; color:var(--text-sub);">Tidak ada data transaksi issue produksi ditemukan.</td></tr>';
                return;
            }
            let html = '';
            data.forEach((r, idx) => {
                html += `<tr>
                    <td>${offset + idx + 1}</td>
                    <td><strong>${r.no_bukti}</strong></td>
                    <td>${r.tgl || '-'}</td>
                    <td>${r.dept || '-'}</td>
                    <td>${r.wo_no || '-'}</td>
                    <td>${r.fg || '-'}</td>
                    <td>${r.pic || '-'}</td>
                    <td style="text-align:center;">${r.item_count || 0}</td>
                    <td style="text-align:right; font-weight:700;">${formatNumber(r.total_qty)}</td>
                    <td>${r.note || '-'}</td>
                    <td style="text-align:center; white-space:nowrap;">
                        <button class="btn btn-default" style="padding:2px 6px;" title="Edit" onclick="editData('${r.id}')"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button class="btn btn-danger" style="padding:2px 6px;" title="Hapus" onclick="deleteData('${r.id}', '${r.no_bukti}')"><i class="fa-solid fa-trash"></i></button>
                    </td>
                </tr>`;
            });
            tb.innerHTML = html;
            if (window.AntEnterpriseGrid) {
                if (!window.issueGrid) {
                    window.issueGrid = new AntEnterpriseGrid('#issueTable', { gridId: 'grid_production_issue_' + curMode, enablePagination: false });
                } else {
                    window.issueGrid.refresh();
                }
            }
        }

        function changePage(delta) {
            const target = curPage + delta;
            if (target >= 1) loadData(target);
        }

        function openModal() {
            document.getElementById('formId').value = '';
            const prefix = curMode === 'A' ? 'ISS' : 'FGP';
            document.getElementById('formNoBukti').value = prefix + '/' + new Date().getFullYear() + '/' + String(Math.floor(Math.random()*9000)+1000);
            document.getElementById('formTgl').value = new Date().toISOString().split('T')[0];
            document.getElementById('formDept').value = 'PRODUKSI';
            document.getElementById('formPic').value = '';
            document.getElementById('formWoNo').value = '';
            document.getElementById('formBppbNo').value = '';
            document.getElementById('formFg').value = '';
            document.getElementById('formNote').value = '';
            document.getElementById('scanQrInput').value = '';

            itemRows = [];
            addItemRow();
            document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-plus"></i> Buat Bukti Issue Produksi Baru';
            document.getElementById('modalForm').classList.add('active');
            setTimeout(() => { document.getElementById('scanQrInput')?.focus(); }, 300);
        }

        function closeModal() {
            document.getElementById('modalForm').classList.remove('active');
        }

        function addItemRow(it = null) {
            const obj = {
                bc_wh_lot_id: it?.bc_wh_lot_id || it?.id || 0,
                lot_no: it?.lot_no || '',
                ref_no: it?.ref_no || '',
                nomor_aju: it?.nomor_aju || it?.no_aju || '',
                no_bc: it?.no_bc || '',
                tgl_bc: it?.tgl_bc || '',
                kode_barang: it?.kode_barang || '',
                nama_barang: it?.nama_barang || '',
                qty_remaining: it?.qty_remaining !== undefined ? parseFloat(it.qty_remaining) : null,
                qty: it?.qty ? parseFloat(it.qty) : 1,
                sat: it?.sat || 'PCE',
                harga: it?.harga ? parseFloat(it.harga) : 0,
                note: it?.note || ''
            };
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
            let totalQty = 0;

            itemRows.forEach((r, idx) => {
                const qtyVal = parseFloat(r.qty) || 0;
                totalQty += qtyVal;

                const lotBadge = r.ref_no 
                    ? `<span class="badge-lot" style="background:#e0f2fe; color:#0369a1; border-color:#bae6fd;"><i class="fa-solid fa-receipt"></i> ${escapeHtml(r.ref_no)}</span>`
                    : (r.lot_no ? `<span class="badge-lot">${escapeHtml(r.lot_no)}</span>` : `<span style="color:var(--text-sub); font-size:10px; font-style:italic;">Manual / Tanpa Dokumen</span>`);

                const ajuBadge = r.nomor_aju 
                    ? `<span class="badge-aju" title="${escapeHtml(r.nomor_aju)}">${escapeHtml(r.no_bc ? (r.no_bc + ' • ') : '')}${escapeHtml(r.nomor_aju)}</span>`
                    : `<span style="color:var(--text-sub); font-size:10px;">-</span>`;

                const stockBadge = (r.qty_remaining !== null && r.qty_remaining !== undefined)
                    ? `<span class="badge-stock" title="Sisa stok saat ini">${formatNumber(r.qty_remaining)}</span>`
                    : `<span style="color:var(--text-sub); font-size:10px;">-</span>`;

                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td>${lotBadge}</td>
                    <td><input type="text" value="${escapeHtml(r.kode_barang || '')}" onchange="itemRows[${idx}].kode_barang=this.value" placeholder="Kode..." style="font-weight:600;"></td>
                    <td><input type="text" value="${escapeHtml(r.nama_barang || '')}" onchange="itemRows[${idx}].nama_barang=this.value" placeholder="Nama Barang..."></td>
                    <td>${ajuBadge}</td>
                    <td style="text-align:right;">${stockBadge}</td>
                    <td><input type="number" step="0.01" min="0.01" value="${r.qty || 0}" onchange="updateRowQty(${idx}, this.value)" style="text-align:right; font-weight:700; color:var(--cyan);"></td>
                    <td><input type="text" value="${escapeHtml(r.sat || '')}" onchange="itemRows[${idx}].sat=this.value" style="width:50px; text-align:center;"></td>
                    <td><input type="number" step="0.01" value="${r.harga || 0}" onchange="itemRows[${idx}].harga=parseFloat(this.value)||0;" style="text-align:right;"></td>
                    <td><input type="text" value="${escapeHtml(r.note || '')}" onchange="itemRows[${idx}].note=this.value" placeholder="Ket..."></td>
                    <td style="text-align:center;"><button type="button" style="border:none; background:none; color:red; cursor:pointer;" onclick="removeItemRow(${idx})"><i class="fa-solid fa-xmark"></i></button></td>
                </tr>`;
            });

            tb.innerHTML = html;
            if (window.AntEnterpriseGrid) {
                if (!window.issueGrid) {
                    window.issueGrid = new AntEnterpriseGrid('#issueTable', { gridId: 'grid_production_issue_' + curMode, enablePagination: false });
                } else {
                    window.issueGrid.refresh();
                }
            }
            const summaryEl = document.getElementById('itemsSummary');
            if (summaryEl) {
                summaryEl.innerHTML = `<b>${itemRows.length}</b> baris | Total Qty: <b>${formatNumber(totalQty)}</b>`;
            }
        }

        function updateRowQty(idx, val) {
            const num = parseFloat(val) || 0;
            const row = itemRows[idx];
            if (row && row.qty_remaining !== null && num > row.qty_remaining) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan Sisa Stok Lot',
                    text: `Qty issue (${num}) melebihi sisa stok fisik pada lot ${row.lot_no} (${row.qty_remaining}). Pastikan ketersediaan barang aktual.`
                });
            }
            itemRows[idx].qty = num;
            renderItemRows();
        }

        function playScanBeep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime); // 880 Hz
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.12);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.12);
            } catch(e) {}
        }

        function processScanInput() {
            const input = document.getElementById('scanQrInput');
            const scanText = (input?.value || '').trim();
            if (!scanText) {
                input?.focus();
                return;
            }

            fetch(`production_issue.php?action=scan_lot&scan_text=${encodeURIComponent(scanText)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'OK' && res.lot) {
                        playScanBeep();
                        const lot = res.lot;
                        // Cek apakah baris pertama kosong, jika kosong timpa
                        if (itemRows.length === 1 && !itemRows[0].kode_barang && !itemRows[0].lot_no) {
                            itemRows = [];
                        }

                        // Cek apakah lot sudah pernah discan
                        const existingIdx = itemRows.findIndex(it => it.bc_wh_lot_id === parseInt(lot.id) || (it.lot_no && it.lot_no === lot.lot_no));
                        if (existingIdx !== -1) {
                            itemRows[existingIdx].qty = (parseFloat(itemRows[existingIdx].qty) || 0) + 1;
                            renderItemRows();
                        } else {
                            addItemRow({
                                bc_wh_lot_id: parseInt(lot.id),
                                lot_no: lot.lot_no,
                                ref_no: lot.ref_no,
                                nomor_aju: lot.nomor_aju,
                                no_bc: lot.no_bc,
                                tgl_bc: lot.tgl_bc,
                                kode_barang: lot.kode_barang,
                                nama_barang: lot.nama_barang,
                                qty_remaining: parseFloat(lot.qty_remaining) || 0,
                                qty: 1,
                                sat: lot.sat || 'PCE',
                                harga: 0,
                                note: `Scan Ref ${lot.ref_no || lot.nomor_aju}`
                            });
                        }

                        // Flash input green & clear
                        input.value = '';
                        input.style.backgroundColor = '#dcfce7';
                        setTimeout(() => { input.style.backgroundColor = '#fff'; input.focus(); }, 200);

                        Swal.mixin({
                            toast: true, position: 'top-end', showConfirmButton: false, timer: 1800, timerProgressBar: true
                        }).fire({
                            icon: 'success',
                            title: `Barang ${lot.kode_barang} (${lot.ref_no}) berhasil discan!`
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Data Tidak Ditemukan',
                            text: res.message || 'QR / Barcode tidak cocok dengan stok pemasukan gudang.'
                        });
                        input.select();
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Gagal memproses scan: ' + err.message, 'error');
                });
        }

        // -------------------------------------------------------------
        // LOT PICKER MODAL
        // -------------------------------------------------------------
        function openLotPickerModal() {
            document.getElementById('lotSearchInput').value = '';
            document.getElementById('modalLotPicker').classList.add('active');
            loadActiveLots();
        }

        function closeLotPickerModal() {
            document.getElementById('modalLotPicker').classList.remove('active');
        }

        function loadActiveLots() {
            const q = document.getElementById('lotSearchInput').value.trim();
            const tb = document.getElementById('lotPickerBody');
            tb.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:15px;">Memuat data stok pemasukan...</td></tr>';

            fetch(`production_issue.php?action=get_active_lots&search=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'OK' || !res.lots || res.lots.length === 0) {
                        tb.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-sub);">Tidak ada data stok pemasukan yang sesuai filter.</td></tr>';
                        return;
                    }
                    let html = '';
                    res.lots.forEach(lot => {
                        html += `<tr>
                            <td><strong style="color:var(--cyan);">${escapeHtml(lot.ref_type)}: ${escapeHtml(lot.ref_no)}</strong></td>
                            <td><small>${escapeHtml(lot.bc ? ('BC ' + lot.bc + ' • ') : '')}${escapeHtml(lot.no_bc || '-')}</small></td>
                            <td><span class="badge-aju" title="${escapeHtml(lot.nomor_aju)}">${escapeHtml(lot.nomor_aju || '-')}</span></td>
                            <td><strong>${escapeHtml(lot.kode_barang)}</strong></td>
                            <td>${escapeHtml(lot.nama_barang)}</td>
                            <td style="text-align:right; font-weight:700; color:#15803d;">${formatNumber(lot.qty_remaining)}</td>
                            <td>${escapeHtml(lot.sat || '')}</td>
                            <td><small>${escapeHtml(lot.lokasi_rak || '-')}</small></td>
                            <td style="text-align:center;">
                                <button type="button" class="btn btn-cyan" style="padding:2px 8px; font-size:10px;" onclick="pickLot(${JSON.stringify(lot).replace(/"/g, '&quot;')})"><i class="fa-solid fa-plus"></i> Pakai</button>
                            </td>
                        </tr>`;
                    });
                    tb.innerHTML = html;
            if (window.AntEnterpriseGrid) {
                if (!window.issueGrid) {
                    window.issueGrid = new AntEnterpriseGrid('#issueTable', { gridId: 'grid_production_issue_' + curMode, enablePagination: false });
                } else {
                    window.issueGrid.refresh();
                }
            }
                })
                .catch(err => {
                    tb.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red;">Gagal memuat: ${err.message}</td></tr>`;
                });
        }

        function pickLot(lot) {
            if (itemRows.length === 1 && !itemRows[0].kode_barang && !itemRows[0].lot_no) {
                itemRows = [];
            }
            addItemRow({
                bc_wh_lot_id: parseInt(lot.id),
                lot_no: lot.lot_no,
                ref_no: lot.ref_no,
                nomor_aju: lot.nomor_aju,
                no_bc: lot.no_bc,
                tgl_bc: lot.tgl_bc,
                kode_barang: lot.kode_barang,
                nama_barang: lot.nama_barang,
                qty_remaining: parseFloat(lot.qty_remaining) || 0,
                qty: parseFloat(lot.qty_remaining) > 0 ? 1 : 0,
                sat: lot.sat || 'PCE',
                harga: 0,
                note: `Ref ${lot.ref_no || lot.nomor_aju}`
            });
            closeLotPickerModal();
            Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 1500
            }).fire({ icon: 'success', title: `${lot.kode_barang} (${lot.ref_no}) ditambahkan ke daftar issue.` });
        }

        // -------------------------------------------------------------
        // MATERIAL REQUEST (BPPB) PICKER MODAL
        // -------------------------------------------------------------
        function openMrPickerModal() {
            document.getElementById('mrSearchInput').value = '';
            document.getElementById('modalMrPicker').classList.add('active');
            loadApprovedMrs();
        }

        function closeMrPickerModal() {
            document.getElementById('modalMrPicker').classList.remove('active');
        }

        function loadApprovedMrs() {
            const q = document.getElementById('mrSearchInput').value.trim();
            const tb = document.getElementById('mrPickerBody');
            tb.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:15px;">Memuat data permintaan barang...</td></tr>';

            fetch(`production_issue.php?action=get_approved_mr&search=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'OK' || !res.data || res.data.length === 0) {
                        tb.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-sub);">Tidak ada permintaan barang (BPPB) berstatus disetujui (Approved).</td></tr>';
                        return;
                    }
                    let html = '';
                    res.data.forEach(mr => {
                        let itemDetails = '';
                        if (mr.items && mr.items.length > 0) {
                            itemDetails = mr.items.map(it => {
                                const sisaReq = (parseFloat(it.qty_appr) || 0) - (parseFloat(it.qty_issued) || 0);
                                return `<div style="font-size:10px; margin-bottom:2px;">• <b>${escapeHtml(it.kode_barang)}</b>: ${escapeHtml(it.nama_barang)} (Sisa minta: <b>${formatNumber(sisaReq)} ${escapeHtml(it.sat)}</b>)</div>`;
                            }).join('');
                        }

                        html += `<tr>
                            <td><strong style="color:#d97706;">${escapeHtml(mr.req_no)}</strong></td>
                            <td>${escapeHtml(mr.req_date || '-')}</td>
                            <td>${escapeHtml(mr.dept_pemohon || '-')}</td>
                            <td><small>${escapeHtml(mr.wo_no || '-')}</small></td>
                            <td>${escapeHtml(mr.pic || '-')}</td>
                            <td>${itemDetails}</td>
                            <td style="text-align:center;">
                                <button type="button" class="btn btn-cyan" style="padding:3px 8px; font-size:10.5px;" onclick="pullFromMr(${JSON.stringify(mr).replace(/"/g, '&quot;')})"><i class="fa-solid fa-cloud-arrow-down"></i> Tarik</button>
                            </td>
                        </tr>`;
                    });
                    tb.innerHTML = html;
            if (window.AntEnterpriseGrid) {
                if (!window.issueGrid) {
                    window.issueGrid = new AntEnterpriseGrid('#issueTable', { gridId: 'grid_production_issue_' + curMode, enablePagination: false });
                } else {
                    window.issueGrid.refresh();
                }
            }
                })
                .catch(err => {
                    tb.innerHTML = `<tr><td colspan="7" style="text-align:center; color:red;">Gagal memuat: ${err.message}</td></tr>`;
                });
        }

        function pullFromMr(mr) {
            document.getElementById('formBppbNo').value = mr.req_no;
            if (mr.dept_pemohon) document.getElementById('formDept').value = mr.dept_pemohon;
            if (mr.wo_no) document.getElementById('formWoNo').value = mr.wo_no;
            if (mr.pic) document.getElementById('formPic').value = mr.pic;

            itemRows = [];
            if (mr.items && mr.items.length > 0) {
                mr.items.forEach(it => {
                    const sisaMinta = Math.max(0, (parseFloat(it.qty_appr) || 0) - (parseFloat(it.qty_issued) || 0));
                    if (sisaMinta > 0) {
                        addItemRow({
                            kode_barang: it.kode_barang,
                            nama_barang: it.nama_barang,
                            qty: sisaMinta,
                            sat: it.sat || 'PCE',
                            harga: 0,
                            note: `Ref BPPB #${mr.req_no}`
                        });
                    }
                });
            }

            closeMrPickerModal();
            Swal.fire({
                icon: 'success',
                title: 'BPPB Ditarik',
                text: `Data permintaan barang ${mr.req_no} berhasil dimasukkan. Silakan lengkapi / scan Lot aktual yang dikeluarkan dari gudang.`
            });
        }

        function editData(id) {
            fetch(`production_issue.php?action=get_issue&id=${encodeURIComponent(id)}`)
                .then(r => r.json())
                .then(res => {
                    if (res.status !== 'OK') {
                        Swal.fire('Error', res.message, 'error');
                        return;
                    }
                    const h = res.header;
                    document.getElementById('formId').value = h.id;
                    document.getElementById('formNoBukti').value = h.no_bukti || '';
                    document.getElementById('formTgl').value = h.tgl || '';
                    document.getElementById('formDept').value = h.dept || 'PRODUKSI';
                    document.getElementById('formPic').value = h.pic || '';
                    document.getElementById('formWoNo').value = h.wo_no || '';
                    document.getElementById('formBppbNo').value = h.bppb_no || '';
                    document.getElementById('formFg').value = h.fg || '';
                    document.getElementById('formNote').value = h.note || '';

                    itemRows = res.items && res.items.length > 0 ? res.items.map(it => ({
                        bc_wh_lot_id: parseInt(it.bc_wh_lot_id) || 0,
                        lot_no: it.lot_no || '',
                        nomor_aju: it.no_aju || '',
                        no_bc: it.no_bc || '',
                        tgl_bc: it.tgl_bc || '',
                        kode_barang: it.kode_barang || '',
                        nama_barang: it.nama_barang || '',
                        qty: parseFloat(it.qty) || 0,
                        sat: it.sat || 'PCE',
                        harga: parseFloat(it.harga) || 0,
                        note: it.note || ''
                    })) : [{ kode_barang: '', nama_barang: '', qty: 1, sat: 'PCE', harga: 0, note: '' }];
                    renderItemRows();

                    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Transaksi #' + h.no_bukti;
                    document.getElementById('modalForm').classList.add('active');
                });
        }

        function saveData() {
            const no_bukti = document.getElementById('formNoBukti').value.trim();
            if (!no_bukti) {
                Swal.fire('Peringatan', 'Nomor Bukti wajib diisi!', 'warning');
                return;
            }

            const validItems = itemRows.filter(r => (r.kode_barang || '').trim() !== '');
            if (validItems.length === 0) {
                Swal.fire('Peringatan', 'Minimal harus ada 1 barang dengan kode barang valid!', 'warning');
                return;
            }

            const payload = {
                id: document.getElementById('formId').value,
                no_bukti: no_bukti,
                tgl: document.getElementById('formTgl').value,
                AB: curMode,
                dept: document.getElementById('formDept').value,
                pic: document.getElementById('formPic').value,
                wo_no: document.getElementById('formWoNo').value,
                bppb_no: document.getElementById('formBppbNo').value,
                fg: document.getElementById('formFg').value,
                note: document.getElementById('formNote').value,
                items: validItems
            };

            fetch('production_issue.php?action=save', {
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

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        }

        function deleteData(id, no) {
            Swal.fire({
                title: 'Hapus Transaksi?',
                text: `Apakah Anda yakin ingin menghapus Bukti ${no}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus!'
            }).then(result => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', id);
                    fetch('production_issue.php', { method: 'POST', body: fd })
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

        function openModuleGuide() {
            Swal.fire({
                title: 'Panduan Modul & SOP: Issue Produksi (BOM & Finished Goods)',
                width: 780,
                html: `
                    <div style="text-align:left; font-size:11px; line-height:1.6; max-height:65vh; overflow-y:auto; padding-right:10px;">
                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                            <div>
                                <strong style="color:#1d4ed8; font-size:11.5px;"><i class="fa-solid fa-file-word"></i> Dokumen Resmi Arsitektur &amp; SOP Sistem</strong>
                                <div style="font-size:10px; color:#4b5563;">Tersedia dokumen panduan teknis &amp; SOP lengkap format Microsoft Word (.docx).</div>
                            </div>
                            <a href="/docs/PANDUAN_ARSITEKTUR_WO_TRACEABILITY_CEISA4.docx" target="_blank" download class="btn" style="background:#2563eb; color:#fff; padding:5px 12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; border:none; border-radius:3px;">
                                <i class="fa-solid fa-download"></i> Unduh Word (.docx)
                            </a>
                        </div>

                        <h4 style="color:#0891b2; margin-top:0;"><i class="fa-solid fa-industry"></i> 1. Konsep Pengeluaran Bahan Baku &amp; Hasil Produksi</h4>
                        <p>Modul Issue Produksi mengelola mutasi bahan baku dari gudang ke proses produksi (BOM Out) dan penerimaan hasil jadi barang (Finished Goods / WIP In) dengan integrasi penuh ke Work Order.</p>

                        <h4 style="color:#0891b2;"><i class="fa-solid fa-arrows-split-up-and-left"></i> 2. Penanganan Dual Work Order:</h4>
                        <ul style="padding-left:18px;">
                            <li><strong>WO Produksi (Komersial / Pesanan Sales):</strong> Mengonsumsi bahan baku sesuai Bill of Material (BOM) atas Sales Order tertentu. Jejak nomor penerimaan bahan pabean (Nomor AJU 26 Digit) otomatis tersimpan untuk pelaporan Eks-BC saat barang jadi diterbitkan Faktur Penjualan (SI).</li>
                            <li><strong>WO Internal (Departemen / Pemeliharaan):</strong> Pengeluaran part/material untuk kebutuhan bengkel, moldshop, atau perbaikan mesin tanpa Sales Order. Tercatat sebagai biaya operasional departemen pada laporan IT Inventory 1B (Kategori Pemakaian Sendiri).</li>
                        </ul>

                        <h4 style="color:#0891b2;"><i class="fa-solid fa-qrcode"></i> 3. Scan QR Label Fisik Bahan Baku:</h4>
                        <p>Petugas di lantai produksi dapat melakukan pemindaian QR Code pada kemasan fisik material (yang dicetak dari Pusat Cetak Label QR). Sistem secara otomatis mengidentifikasi Nomor AJU, Seri Barang, dan Dokumen Pabean asal barang tersebut.</p>

                        <h4 style="color:#0891b2;"><i class="fa-solid fa-shield-halved"></i> 4. Kepatuhan IT Inventory &amp; Audit Kepabeanan:</h4>
                        <p>Setiap mutasi pengeluaran dan pemasukan langsung terintegrasi dengan mutasi IT Inventory 1C (Posisi Barang Dalam Proses / WIP) dan 1D (Mutasi Bahan Baku &amp; Mutasi Barang Jadi) sesuai PER-02/BC/2019 juncto PER-6/BC/2026.</p>
                    </div>
                `,
                confirmButtonText: 'Tutup Panduan',
                confirmButtonColor: '#0891b2'
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

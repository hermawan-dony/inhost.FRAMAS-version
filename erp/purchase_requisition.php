<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Purchase Requisition (PR) / Permintaan Pembelian Barang (FPPB)
 * Styling 100% Identik dengan Modul Purchase Order (PO) & PowerBuilder Desktop (TPB-ERP)
 * Alur Procure-to-Pay (P2P): Requester -> Approval Dept Head -> Purchasing (Tarik PR ke PO) -> Gudang (RI) -> Finance (PI)
 * Tabel Database: bc_poreq_hdr & bc_poreq_dtl
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
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'user');

// Nama Perusahaan Aktif
$activeCmpName = 'TPB ERP Enterprise';
if (!empty($sessionCmpId)) {
    $cmpQ = $koneksi->query("SELECT cmp_name FROM bc_ceisa_company WHERE cmp_id = '$escCmp' LIMIT 1");
    if ($cmpQ && $cmpR = $cmpQ->fetch_assoc()) $activeCmpName = $cmpR['cmp_name'];
}

// Auto-Heal Tabel bc_poreq_hdr & bc_poreq_dtl
function ensurePoReqTables($koneksi) {
    $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_poreq_hdr'");
    if (!$checkHdr || $checkHdr->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_poreq_hdr` (
            `id` varchar(40) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `pr_no` varchar(50) NOT NULL DEFAULT '',
            `pr_dt` date DEFAULT NULL,
            `required_dt` date DEFAULT NULL,
            `dept` varchar(50) NOT NULL DEFAULT 'PRODUKSI',
            `requester` varchar(100) NOT NULL DEFAULT '',
            `priority` varchar(20) DEFAULT 'NORMAL',
            `purpose` text DEFAULT NULL,
            `status` char(1) DEFAULT '0',
            `approved_by` varchar(50) DEFAULT '',
            `approved_dt` datetime DEFAULT NULL,
            `rejected_note` text DEFAULT NULL,
            `set_by` varchar(50) DEFAULT '',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_cmp_pr_no` (`cmp_id`, `pr_no`),
            KEY `idx_pr_dt` (`pr_dt`),
            KEY `idx_dept` (`dept`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_poreq_dtl'");
    if (!$checkDtl || $checkDtl->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_poreq_dtl` (
            `id` varchar(40) NOT NULL DEFAULT '',
            `id_hdr` varchar(40) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `pr_no` varchar(50) NOT NULL DEFAULT '',
            `seq` int(11) NOT NULL DEFAULT '1',
            `kode_barang` varchar(50) NOT NULL DEFAULT '',
            `uraian` varchar(255) NOT NULL DEFAULT '',
            `spesifikasi` text DEFAULT NULL,
            `qty` decimal(18,4) DEFAULT '0.0000',
            `sat` varchar(20) DEFAULT 'PCE',
            `est_price` decimal(18,4) DEFAULT '0.0000',
            `est_amount` decimal(18,4) DEFAULT '0.0000',
            `qty_po` decimal(18,4) DEFAULT '0.0000',
            `sisa_qty` decimal(18,4) DEFAULT '0.0000',
            `po_no` varchar(200) DEFAULT '',
            `note` text DEFAULT NULL,
            `status` char(1) DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_hdr` (`id_hdr`),
            KEY `idx_cmp_pr` (`cmp_id`, `pr_no`),
            KEY `idx_kode_barang` (`kode_barang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
ensurePoReqTables($koneksi);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. LIST DATA PR (BROWSE TABLE)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $status    = trim($_GET['status'] ?? '');
    $dept      = trim($_GET['dept'] ?? '');
    $priority  = trim($_GET['priority'] ?? '');
    $search    = trim($_GET['search'] ?? '');
    $tglAwal   = trim($_GET['tgl_awal'] ?? '');
    $tglAkhir  = trim($_GET['tgl_akhir'] ?? '');
    $page      = max(1, (int)($_GET['page'] ?? 1));
    $limit     = max(10, min(100, (int)($_GET['limit'] ?? 25)));
    $offset    = ($page - 1) * $limit;

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(h.cmp_id = '{$escCmp}' OR h.cmp_id = '')";
    }
    if ($status !== '') {
        $stEsc = $koneksi->real_escape_string($status);
        $where[] = "h.status = '{$stEsc}'";
    }
    if (!empty($dept)) {
        $dEsc = $koneksi->real_escape_string($dept);
        $where[] = "h.dept = '{$dEsc}'";
    }
    if (!empty($priority)) {
        $pEsc = $koneksi->real_escape_string($priority);
        $where[] = "h.priority = '{$pEsc}'";
    }
    if (!empty($tglAwal)) {
        $taEsc = $koneksi->real_escape_string($tglAwal);
        $where[] = "h.pr_dt >= '{$taEsc}'";
    }
    if (!empty($tglAkhir)) {
        $tkEsc = $koneksi->real_escape_string($tglAkhir);
        $where[] = "h.pr_dt <= '{$tkEsc}'";
    }
    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(h.pr_no LIKE '%{$sEsc}%' OR h.requester LIKE '%{$sEsc}%' OR h.purpose LIKE '%{$sEsc}%')";
    }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(1) as total FROM bc_poreq_hdr h {$whereSql}";
    $total = 0;
    $cQ = $koneksi->query($countSql);
    if ($cQ && $cRow = $cQ->fetch_assoc()) $total = (int)$cRow['total'];

    $sql = "SELECT h.*,
                   (SELECT COUNT(1) FROM bc_poreq_dtl d WHERE d.id_hdr = h.id) AS total_item,
                   (SELECT IFNULL(SUM(d.qty), 0) FROM bc_poreq_dtl d WHERE d.id_hdr = h.id) AS total_qty_req,
                   (SELECT IFNULL(SUM(d.qty_po), 0) FROM bc_poreq_dtl d WHERE d.id_hdr = h.id) AS total_qty_po,
                   (SELECT IFNULL(SUM(d.sisa_qty), 0) FROM bc_poreq_dtl d WHERE d.id_hdr = h.id) AS total_sisa_qty,
                   (SELECT IFNULL(SUM(d.est_amount), 0) FROM bc_poreq_dtl d WHERE d.id_hdr = h.id) AS total_est_amount,
                   (SELECT GROUP_CONCAT(DISTINCT d.po_no SEPARATOR ', ') FROM bc_poreq_dtl d WHERE d.id_hdr = h.id AND d.po_no <> '') AS po_nos
            FROM bc_poreq_hdr h
            {$whereSql}
            ORDER BY h.pr_dt DESC, h.pr_no DESC
            LIMIT {$offset}, {$limit}";

    $data = [];
    $res = $koneksi->query($sql);
    $todayTs = strtotime(date('Y-m-d'));
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $qReq = (float)($r['total_qty_req'] ?? 0);
            $qPo  = (float)($r['total_qty_po'] ?? 0);
            $sisa = (float)($r['total_sisa_qty'] ?? 0);
            $pct  = ($qReq > 0) ? min(100, round(($qPo / $qReq) * 100)) : 0;
            $reqTs = !empty($r['required_dt']) ? strtotime($r['required_dt']) : 0;
            $isOverdue = ($reqTs > 0 && $reqTs < $todayTs && $sisa > 0 && $r['status'] !== '4' && $r['status'] !== '9');

            $r['pct'] = $pct;
            $r['is_overdue'] = $isOverdue;
            $data[] = $r;
        }
    }

    // KPI Summary
    $kpiWhere = !empty($sessionCmpId) ? "WHERE (cmp_id = '{$escCmp}' OR cmp_id = '')" : "";
    $kpiQ = $koneksi->query("SELECT 
        COUNT(1) AS total_pr,
        SUM(CASE WHEN status = '0' THEN 1 ELSE 0 END) AS pr_draft,
        SUM(CASE WHEN status = '1' THEN 1 ELSE 0 END) AS pr_submitted,
        SUM(CASE WHEN status = '2' THEN 1 ELSE 0 END) AS pr_approved,
        SUM(CASE WHEN status IN ('3', '4') THEN 1 ELSE 0 END) AS pr_processed,
        SUM(CASE WHEN status = '9' THEN 1 ELSE 0 END) AS pr_rejected
        FROM bc_poreq_hdr {$kpiWhere}");
    $kpi = [
        'total_pr'      => 0,
        'pr_draft'      => 0,
        'pr_submitted'  => 0,
        'pr_approved'   => 0,
        'pr_processed'  => 0,
        'pr_rejected'   => 0
    ];
    if ($kpiQ && $kRow = $kpiQ->fetch_assoc()) {
        $kpi = [
            'total_pr'     => (int)($kRow['total_pr'] ?? 0),
            'pr_draft'     => (int)($kRow['pr_draft'] ?? 0),
            'pr_submitted' => (int)($kRow['pr_submitted'] ?? 0),
            'pr_approved'  => (int)($kRow['pr_approved'] ?? 0),
            'pr_processed' => (int)($kRow['pr_processed'] ?? 0),
            'pr_rejected'  => (int)($kRow['pr_rejected'] ?? 0)
        ];
    }

    echo json_encode(['status' => 'OK', 'data' => $data, 'total' => $total, 'page' => $page, 'limit' => $limit, 'kpi' => $kpi]);
    exit;
}

// 2. GET DETAIL PR
if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrRes = $koneksi->query("SELECT * FROM bc_poreq_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    if (!$hdrRes || $hdrRes->num_rows === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'Data Permintaan Pembelian (PR) tidak ditemukan']);
        exit;
    }
    $header = $hdrRes->fetch_assoc();

    $dtlRes = $koneksi->query("SELECT * FROM bc_poreq_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $details = [];
    if ($dtlRes) {
        while ($r = $dtlRes->fetch_assoc()) {
            $details[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'header' => $header, 'details' => $details]);
    exit;
}

// 3. SAVE PR
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $post = json_decode($raw, true) ?: $_POST;

    $id = trim($post['id'] ?? '');
    $isEdit = !empty($id);
    if (!$isEdit) {
        $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    $escId = $koneksi->real_escape_string($id);

    $prNo = trim($post['pr_no'] ?? '');
    if (empty($prNo)) {
        $ym = date('ym');
        $prefix = !empty($sessionCmpId) ? ($sessionCmpId . '-PR' . $ym) : ('PR-' . date('Ym'));
        $numRes = $koneksi->query("SELECT MAX(CAST(SUBSTRING_INDEX(pr_no, '-', -1) AS UNSIGNED)) as max_num FROM bc_poreq_hdr WHERE cmp_id = '{$escCmp}' AND pr_no LIKE '{$prefix}-%'");
        $next = ($numRes && ($row = $numRes->fetch_assoc()) && $row['max_num']) ? ((int)$row['max_num'] + 1) : 1;
        $prNo = sprintf('%s-%04d', $prefix, $next);
    }
    $escPrNo = $koneksi->real_escape_string($prNo);

    $prDt = !empty($post['pr_dt']) ? $koneksi->real_escape_string($post['pr_dt']) : date('Y-m-d');
    $requiredDt = !empty($post['required_dt']) ? "'" . $koneksi->real_escape_string($post['required_dt']) . "'" : "NULL";
    $dept = $koneksi->real_escape_string($post['dept'] ?? 'PRODUKSI');
    $requester = $koneksi->real_escape_string($post['requester'] ?? $currentUser);
    $priority = $koneksi->real_escape_string($post['priority'] ?? 'NORMAL');
    $purpose = $koneksi->real_escape_string($post['purpose'] ?? '');
    $status = $koneksi->real_escape_string($post['status'] ?? '0');
    $setBy = $currentUser;

    if (empty($dept)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Departemen pemohon wajib dipilih!']);
        exit;
    }
    if (empty($requester)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Nama pemohon wajib diisi!']);
        exit;
    }

    if ($isEdit) {
        $sqlHdr = "UPDATE bc_poreq_hdr SET
                    pr_no = '{$escPrNo}',
                    pr_dt = '{$prDt}',
                    required_dt = {$requiredDt},
                    dept = '{$dept}',
                    requester = '{$requester}',
                    priority = '{$priority}',
                    purpose = '{$purpose}',
                    status = '{$status}',
                    set_by = '{$setBy}'
                   WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')";
    } else {
        $sqlHdr = "INSERT INTO bc_poreq_hdr (id, cmp_id, pr_no, pr_dt, required_dt, dept, requester, priority, purpose, status, set_by)
                   VALUES ('{$escId}', '{$escCmp}', '{$escPrNo}', '{$prDt}', {$requiredDt}, '{$dept}', '{$requester}', '{$priority}', '{$purpose}', '{$status}', '{$setBy}')";
    }

    if (!$koneksi->query($sqlHdr)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Gagal menyimpan header PR: ' . $koneksi->error]);
        exit;
    }

    // Details handling
    $koneksi->query("DELETE FROM bc_poreq_dtl WHERE id_hdr = '{$escId}'");
    $items = $post['items'] ?? [];
    if (is_array($items) && count($items) > 0) {
        $seq = 1;
        foreach ($items as $item) {
            $dtlId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $kodeBarang  = $koneksi->real_escape_string($item['kode_barang'] ?? '');
            $uraian      = $koneksi->real_escape_string($item['uraian'] ?? ($item['nama_barang'] ?? ''));
            $spesifikasi = $koneksi->real_escape_string($item['spesifikasi'] ?? '');
            $qty         = (float)($item['qty'] ?? 0);
            $sat         = $koneksi->real_escape_string($item['sat'] ?? ($item['satuan'] ?? 'PCE'));
            $estPrice    = (float)($item['est_price'] ?? ($item['harga'] ?? 0));
            $estAmount   = (float)($item['est_amount'] ?? ($qty * $estPrice));
            $qtyPo       = (float)($item['qty_po'] ?? 0);
            $sisaQty     = max(0, $qty - $qtyPo);
            $poNo        = $koneksi->real_escape_string($item['po_no'] ?? '');
            $note        = $koneksi->real_escape_string($item['note'] ?? '');

            if (!empty($kodeBarang) || !empty($uraian)) {
                $sqlDtl = "INSERT INTO bc_poreq_dtl (id, id_hdr, cmp_id, pr_no, seq, kode_barang, uraian, spesifikasi, qty, sat, est_price, est_amount, qty_po, sisa_qty, po_no, note)
                           VALUES ('{$dtlId}', '{$escId}', '{$escCmp}', '{$escPrNo}', {$seq}, '{$kodeBarang}', '{$uraian}', '{$spesifikasi}', {$qty}, '{$sat}', {$estPrice}, {$estAmount}, {$qtyPo}, {$sisaQty}, '{$poNo}', '{$note}')";
                $koneksi->query($sqlDtl);
                $seq++;
            }
        }
    }

    echo json_encode(['status' => 'OK', 'id' => $id, 'pr_no' => $prNo, 'message' => 'Permintaan Pembelian (PR) berhasil disimpan']);
    exit;
}

// 4. UPDATE STATUS PR
if ($action === 'update_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $rejectedNote = trim($_POST['rejected_note'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $escSt = $koneksi->real_escape_string($status);

    $apprClause = "";
    if ($status === '2') { // Approved
        $apprClause = ", approved_by = '{$currentUser}', approved_dt = NOW(), rejected_note = NULL";
    } elseif ($status === '9') { // Rejected
        $escRej = $koneksi->real_escape_string($rejectedNote);
        $apprClause = ", rejected_note = '{$escRej}', approved_by = '{$currentUser}', approved_dt = NOW()";
    } elseif ($status === '0') { // Reopen to draft
        $apprClause = ", approved_by = '', approved_dt = NULL, rejected_note = NULL";
    }

    $sql = "UPDATE bc_poreq_hdr SET status = '{$escSt}' {$apprClause} WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')";
    if ($koneksi->query($sql)) {
        $labels = [
            '0' => 'Draft',
            '1' => 'Menunggu Approval',
            '2' => 'Approved (Disetujui)',
            '3' => 'In PO (Diproses)',
            '4' => 'Closed (Selesai)',
            '9' => 'Ditolak (Rejected)'
        ];
        echo json_encode(['status' => 'OK', 'new_status' => $status, 'new_status_lbl' => $labels[$status] ?? $status, 'message' => 'Status PR berhasil diperbarui']);
    } else {
        echo json_encode(['status' => 'ERR', 'message' => 'Gagal mengubah status: ' . $koneksi->error]);
    }
    exit;
}

// 5. DELETE PR
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $chk = $koneksi->query("SELECT pr_no, status FROM bc_poreq_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    if ($chk && $r = $chk->fetch_assoc()) {
        if ($r['status'] !== '0' && !$isAdmin) {
            echo json_encode(['status' => 'ERR', 'message' => 'PR tidak dapat dihapus karena status sudah bukan Draft!']);
            exit;
        }
    }

    $koneksi->query("DELETE FROM bc_poreq_dtl WHERE id_hdr = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
    $koneksi->query("DELETE FROM bc_poreq_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
    echo json_encode(['status' => 'OK', 'message' => 'Permintaan Pembelian (PR) berhasil dihapus']);
    exit;
}

// 6. SEARCH MASTER MATERIAL / BARANG (bc_material + bc_1ab2)
if ($action === 'search_materials' || $action === 'lookup_materials') {
    header('Content-Type: application/json; charset=utf-8');
    $klas = trim($_GET['klas'] ?? 'ALL');
    $q    = trim($_GET['q'] ?? '');
    $escQ = $koneksi->real_escape_string($q);

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "(cmp_id = '{$escCmp}' OR cmp_id = '')";
    }

    $klasMap = [
        'RM' => 'Raw Materials',
        'FG' => 'Finished Goods',
        'WI' => 'Work In Progress',
        'CG' => 'Capital Goods',
        'W'  => 'Wastes / Scrap'
    ];
    if (!empty($klas) && $klas !== 'ALL') {
        $targetKlas = $klasMap[$klas] ?? $klas;
        $escKlas = $koneksi->real_escape_string($targetKlas);
        $where[] = "(klas = '{$escKlas}' OR klas = '{$klas}')";
    }

    if (!empty($escQ)) {
        $where[] = "(kode_barang LIKE '%{$escQ}%' OR uraian LIKE '%{$escQ}%' OR spf_lain LIKE '%{$escQ}%')";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT KODE_BARANG as kode_barang, URAIAN as uraian, IFNULL(KODE_SATUAN, 'PCE') as sat, IFNULL(UNIT_PRICE, 0) as price, IFNULL(SPF_LAIN, '') as spesifikasi, KLAS as klas
            FROM bc_material
            {$whereSql}
            ORDER BY KODE_BARANG ASC
            LIMIT 60";

    $data = [];
    $res = $koneksi->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // Jika hasil sedikit atau pencarian teks spesifik, cari juga di bc_1ab2 (dokumen pemasukan pabean)
    if (count($data) < 20) {
        $where2 = ["(cmp_id = '{$escCmp}' OR cmp_id = '')"];
        if (!empty($escQ)) {
            $where2[] = "(KODE_BARANG LIKE '%{$escQ}%' OR URAIAN LIKE '%{$escQ}%' OR SPESIFIKASI_LAIN LIKE '%{$escQ}%')";
        }
        if (!empty($klas) && $klas !== 'ALL') {
            $where2[] = "KLAS = '{$escKlas}'";
        }
        $res2 = $koneksi->query("SELECT KODE_BARANG as kode_barang, URAIAN as uraian, IFNULL(SATUAN, 'PCE') as sat, IFNULL(HARGA_PENYERAHAN, 0) as price, IFNULL(SPESIFIKASI_LAIN, '') as spesifikasi, KLAS as klas FROM bc_1ab2 WHERE " . implode(' AND ', $where2) . " ORDER BY KODE_BARANG ASC LIMIT 40");
        if ($res2) {
            $existing = array_column($data, 'kode_barang');
            while ($r2 = $res2->fetch_assoc()) {
                if (!in_array($r2['kode_barang'], $existing)) {
                    $data[] = $r2;
                    $existing[] = $r2['kode_barang'];
                }
            }
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// 7. LOOKUP OPEN PR (UNTUK PENARIKAN PO)
if ($action === 'lookup_open_pr') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $dept   = trim($_GET['dept'] ?? '');
    $st     = trim($_GET['status'] ?? '');
    $escS   = $koneksi->real_escape_string($search);
    $where  = ["(h.cmp_id = '{$escCmp}' OR h.cmp_id = '')", "d.sisa_qty > 0"];
    if (!empty($st)) {
        $escSt = $koneksi->real_escape_string($st);
        $where[] = "h.status = '{$escSt}'";
    } else {
        $where[] = "h.status IN ('0', '1', '2', '3')";
    }
    if (!empty($escS)) {
        $where[] = "(h.pr_no LIKE '%{$escS}%' OR d.kode_barang LIKE '%{$escS}%' OR d.uraian LIKE '%{$escS}%' OR h.dept LIKE '%{$escS}%' OR h.requester LIKE '%{$escS}%')";
    }
    if (!empty($dept)) {
        $escD = $koneksi->real_escape_string($dept);
        $where[] = "h.dept = '{$escD}'";
    }
    $whereSql = implode(' AND ', $where);
    $sql = "SELECT d.*, h.pr_dt, h.required_dt, h.dept, h.requester, h.priority, h.purpose, h.status as pr_status
            FROM bc_poreq_dtl d
            INNER JOIN bc_poreq_hdr h ON d.id_hdr = h.id
            WHERE {$whereSql}
            ORDER BY h.required_dt ASC, h.pr_no DESC, d.seq ASC
            LIMIT 150";
    $res = $koneksi->query($sql);
    $data = [];
    if ($res) while ($r = $res->fetch_assoc()) $data[] = $r;
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// 8. TRACE PR (PO & RI REALISASI)
if ($action === 'trace') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hQ = $koneksi->query("SELECT * FROM bc_poreq_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR cmp_id = '') LIMIT 1");
    if (!$hQ || $hQ->num_rows === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'PR tidak ditemukan']);
        exit;
    }
    $hdr = $hQ->fetch_assoc();
    $prNo = $hdr['pr_no'];
    $escPrNo = $koneksi->real_escape_string($prNo);

    $linesQ = $koneksi->query("SELECT * FROM bc_poreq_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $lines = [];
    if ($linesQ) while ($l = $linesQ->fetch_assoc()) $lines[] = $l;

    // PO yang terbentuk dari PR ini
    $poQ = $koneksi->query("SELECT DISTINCT p.*, b.NAMA as vendor_nama 
                            FROM bc_po_hdr p
                            LEFT JOIN bc_bs b ON (p.vendor = b.KODE AND (b.cmp_id = p.cmp_id OR b.cmp_id = ''))
                            INNER JOIN bc_po_dtl d ON (p.id = d.id_hdr OR p.po_no = d.po_no)
                            WHERE d.pr_no = '{$escPrNo}' AND (p.cmp_id = '{$escCmp}' OR p.cmp_id = '')");
    $poList = [];
    if ($poQ) while ($p = $poQ->fetch_assoc()) $poList[] = $p;

    // RI yang terkait dengan PO tersebut
    $riList = [];
    if (count($poList) > 0) {
        $poNos = array_map(function($p) use ($koneksi) { return "'" . $koneksi->real_escape_string($p['po_no']) . "'"; }, $poList);
        $poNosStr = implode(',', $poNos);
        $riQ = $koneksi->query("SELECT r.* FROM bc_ri_hdr r WHERE r.po_no IN ({$poNosStr}) AND (r.cmp_id = '{$escCmp}' OR r.cmp_id = '')");
        if ($riQ) while ($r = $riQ->fetch_assoc()) $riList[] = $r;
    }

    echo json_encode(['status' => 'OK', 'header' => $hdr, 'lines' => $lines, 'po' => $poList, 'ri' => $riList]);
    exit;
}

// 9. PRINT FORM FPPB
if ($action === 'print') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $hRes = $koneksi->query("SELECT * FROM bc_poreq_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    $hdr = $hRes ? $hRes->fetch_assoc() : null;
    if (!$hdr) die("Dokumen PR tidak ditemukan");

    $cmpRes = $koneksi->query("SELECT * FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    $company = $cmpRes ? $cmpRes->fetch_assoc() : ['cmp_name' => $activeCmpName, 'alamat' => 'Kawasan Berikat'];
    $cmpLogo = trim($company['logo'] ?? '');
    if (!empty($cmpLogo) && !preg_match('#^(https?://|/)#i', $cmpLogo)) {
        $cmpLogo = '/' . $cmpLogo;
    }
    if (empty($cmpLogo)) {
        $cmpLogo = '/assets/logo.png';
    }

    $dRes = $koneksi->query("SELECT * FROM bc_poreq_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $details = [];
    $totalEst = 0;
    if ($dRes) while ($r = $dRes->fetch_assoc()) { $details[] = $r; $totalEst += (float)$r['est_amount']; }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>FPPB - <?= htmlspecialchars($hdr['pr_no']) ?></title>
        <style>
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 11px; color: #111; margin: 20px; line-height: 1.3; }
            .po-box { max-width: 800px; margin: 0 auto; border: 1px solid #333; padding: 16px; }
            .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .hdr-table td { vertical-align: top; }
            .title { font-size: 16px; font-weight: bold; text-align: right; text-transform: uppercase; color: #1890ff; }
            .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
            .info-grid td { padding: 4px 6px; border: 1px solid #ccc; font-size: 10.5px; }
            .info-title { background: #f0f0f0; font-weight: bold; }
            table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            table.data th, table.data td { border: 1px solid #444; padding: 4px 6px; font-size: 10.5px; }
            table.data th { background: #f4f4f4; text-align: center; }
            .num { text-align: right; }
            .sig-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 24px; text-align: center; font-size: 10.5px; }
            .sig-box { height: 60px; }
            @media print {
                body { margin: 0; }
                .po-box { border: none; padding: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom:12px; text-align:right;">
            <button onclick="window.print()" style="padding:6px 16px; font-size:12px; font-weight:bold; background:#1890ff; color:#fff; border:none; border-radius:3px; cursor:pointer;">Cetak Sekarang</button>
        </div>
        <div class="po-box">
            <table class="hdr-table">
                <tr>
                    <td style="width:62%; vertical-align:middle;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if (!empty($cmpLogo)): ?>
                            <img src="<?= htmlspecialchars($cmpLogo) ?>" alt="Logo Perusahaan" style="max-height:55px; max-width:140px; object-fit:contain;">
                            <?php endif; ?>
                            <div>
                                <strong style="font-size:14px; text-transform:uppercase; color:#111; letter-spacing:0.3px;"><?= htmlspecialchars($company['cmp_name'] ?? $activeCmpName) ?></strong><br>
                                <span style="font-size:9.5px; color:#444; line-height:1.3; display:inline-block; margin-top:2px;"><?= nl2br(htmlspecialchars($company['alamat'] ?? '')) ?></span><br>
                                <span style="font-size:9.5px; color:#555;">NPWP: <strong><?= htmlspecialchars($company['npwp'] ?? '-') ?></strong><?= !empty($company['nib']) ? ' | NIB: ' . htmlspecialchars($company['nib']) : '' ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="title" style="width:38%; vertical-align:middle; text-align:right;">
                        <div style="font-size:16px; font-weight:800; color:#1890ff; letter-spacing:0.5px; text-transform:uppercase;">PURCHASE REQUISITION</div>
                        <div style="font-size:11px; font-weight:700; color:#222; margin-top:2px;">No: <?= htmlspecialchars($hdr['pr_no']) ?></div>
                        <div style="font-size:10px; color:#666;">Form Permintaan Pembelian Barang (FPPB)</div>
                    </td>
                </tr>
            </table>

            <table class="info-grid">
                <tr>
                    <td class="info-title" style="width:50%;">INFORMASI PEMOHON (REQUESTER):</td>
                    <td class="info-title" style="width:50%;">TARGET &amp; TINGKAT URGENSI:</td>
                </tr>
                <tr>
                    <td>
                        <strong>Departemen:</strong> <?= htmlspecialchars($hdr['dept']) ?><br>
                        <strong>Nama Pemohon:</strong> <?= htmlspecialchars($hdr['requester']) ?><br>
                        <strong>Tanggal Pengajuan:</strong> <?= htmlspecialchars($hdr['pr_dt']) ?>
                    </td>
                    <td>
                        <strong>Tgl Target Butuh:</strong> <span style="color:#d9363e; font-weight:bold;"><?= htmlspecialchars($hdr['required_dt'] ?: '-') ?></span><br>
                        <strong>Tingkat Urgensi:</strong> <strong><?= htmlspecialchars($hdr['priority']) ?></strong><br>
                        <strong>Status Dokumen:</strong> <?= htmlspecialchars($hdr['status'] === '2' ? 'Approved' : ($hdr['status'] === '1' ? 'Menunggu Approval' : 'Draft')) ?>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <strong>Keperluan / Alasan Pengadaan:</strong><br>
                        <?= nl2br(htmlspecialchars($hdr['purpose'] ?: '-')) ?>
                    </td>
                </tr>
            </table>

            <table class="data">
                <thead>
                    <tr>
                        <th style="width:30px;">No</th>
                        <th style="width:130px;">Kode Barang</th>
                        <th>Nama &amp; Uraian Barang</th>
                        <th style="width:160px;">Spesifikasi / Merk Khusus</th>
                        <th style="width:65px; text-align:right;">Qty</th>
                        <th style="width:50px; text-align:center;">Sat</th>
                        <th style="width:110px; text-align:right;">Est. Harga</th>
                        <th style="width:120px; text-align:right;">Est. Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($details) === 0): ?>
                    <tr><td colspan="8" style="text-align:center;">Tidak ada rincian item barang</td></tr>
                    <?php else: foreach ($details as $idx => $d): ?>
                    <tr>
                        <td style="text-align:center;"><?= $idx + 1 ?></td>
                        <td style="font-family:monospace; font-weight:bold;"><?= htmlspecialchars($d['kode_barang']) ?></td>
                        <td><?= htmlspecialchars($d['uraian']) ?></td>
                        <td><?= htmlspecialchars($d['spesifikasi'] ?: '-') ?></td>
                        <td style="text-align:right; font-weight:bold;"><?= number_format((float)$d['qty'], 2) ?></td>
                        <td style="text-align:center;"><?= htmlspecialchars($d['sat']) ?></td>
                        <td style="text-align:right;"><?= number_format((float)$d['est_price'], 2) ?></td>
                        <td style="text-align:right; font-weight:bold;"><?= number_format((float)$d['est_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold; background:#fafafa;">
                        <td colspan="7" style="text-align:right;">TOTAL ESTIMASI KEBUTUHAN:</td>
                        <td style="text-align:right; color:#1890ff;">IDR <?= number_format($totalEst, 2) ?></td>
                    </tr>
                </tfoot>
            </table>

            <div class="sig-grid">
                <div>
                    Pemohon (Requester):<br>
                    <div class="sig-box"></div>
                    ( <?= htmlspecialchars($hdr['requester']) ?> )<br>
                    Dept: <?= htmlspecialchars($hdr['dept']) ?>
                </div>
                <div>
                    Menyetujui (Dept Head):<br>
                    <div class="sig-box"></div>
                    ( <?= htmlspecialchars($hdr['approved_by'] ?: '_____________________') ?> )<br>
                    Tgl: <?= htmlspecialchars($hdr['approved_dt'] ?: '.....................') ?>
                </div>
                <div>
                    Diterima (Purchasing):<br>
                    <div class="sig-box"></div>
                    ( _____________________ )<br>
                    Bagian Pengadaan
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Data awal untuk form Entri
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// Ambil daftar Departemen langsung dari tabel bc_dept
$departments = [];
$deptQ = $koneksi->query("SELECT kode, nama_dept FROM bc_dept WHERE aktif = 'Y' AND (cmp_id = '$escCmp' OR cmp_id = '' OR cmp_id IS NULL) ORDER BY kode ASC");
if ($deptQ && $deptQ->num_rows > 0) {
    while ($dr = $deptQ->fetch_assoc()) {
        $departments[] = [
            'kode' => $dr['kode'],
            'nama' => !empty($dr['nama_dept']) ? $dr['nama_dept'] : $dr['kode']
        ];
    }
}
if (empty($departments)) {
    // Fallback default jika tabel bc_dept belum terisi
    $fallbackDepts = ['PRODUKSI', 'PPIC', 'MAINTENANCE', 'GA', 'IT', 'QC'];
    foreach ($fallbackDepts as $fd) {
        $departments[] = ['kode' => $fd, 'nama' => $fd];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requisition (PR) - <?= htmlspecialchars($activeCmpName) ?></title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme');
            if (theme === 'dark' || (!theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark-mode');
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
        html, body { height: 100%; overflow: hidden; background: var(--bg-base); color: var(--text-main); font-family: var(--app-font, 'Poppins', sans-serif); font-size: 10px; }
        input, select, textarea, button, table, .ant-container, .ant-card, .ant-toolbar, .fld-row input, .fld-row select, table.dw {
            font-family: var(--app-font, 'Poppins', sans-serif);
        }

        input[type="number"] { -moz-appearance: textfield; }
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        .ant-container { height: 100%; display: flex; flex-direction: column; padding: 6px; gap: 0; }
        .ant-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 3px; display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .ant-card-head { min-height: 34px; padding: 4px 10px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; background: var(--bg-card); }
        .ant-card-title { font-size: 12px; font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 6px; }
        .ant-badge { font-size: 9px; padding: 1px 6px; border-radius: 10px; font-weight: 600; line-height: 1.2; text-transform: uppercase; }
        .ant-badge-company { background: rgba(24, 144, 255, 0.12); color: var(--primary); border: 1px solid rgba(24, 144, 255, 0.3); }
        .ant-badge-lunas { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82, 196, 26, 0.3); }
        .ant-badge-belum { background: rgba(250, 173, 20, 0.12); color: var(--warning); border: 1px solid rgba(250, 173, 20, 0.3); }
        .ant-badge-lock { background: rgba(255, 77, 79, 0.12); color: var(--danger); border: 1px solid rgba(255, 77, 79, 0.3); }

        /* Tabs */
        .ant-tabs-nav { display: flex; gap: 2px; padding: 0 10px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .ant-tab-btn { border: none; border-bottom: 2px solid transparent; background: transparent; color: var(--text-muted); padding: 5px 12px; cursor: pointer; font-size: 10px; font-weight: 500; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }

        /* Toolbar Buttons */
        .ant-toolbar { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; padding: 6px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); }
        .ant-btn { font-family: inherit; font-size: 9.5px; height: 21px; padding: 0 8px; border-radius: 2px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; line-height: 1.4; transition: all 0.15s; }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color:#fff; }
        .ant-btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .ant-btn-success:hover { background: #49ad16; border-color: #49ad16; color:#fff; }
        .ant-btn-warning { background: var(--warning); color: #fff; border-color: var(--warning); }
        .ant-btn-warning:hover { background: #d48806; border-color: #d48806; color:#fff; }
        .ant-btn-danger { color: var(--danger); border-color: #ffccc7; background: #fff; }
        body.dark-mode .ant-btn-danger, html.dark-mode .ant-btn-danger { background: transparent; border-color: #5c2c2c; }
        .ant-btn-danger:hover { color: #fff; background: var(--danger); border-color: var(--danger); }
        .ant-btn:disabled { opacity: 0.45; cursor: not-allowed; }

        /* Tab Content Pages */
        .ant-page { display: none; min-height: 0; flex-direction: column; flex: 1; overflow: hidden; }
        .ant-page.active { display: flex; }

        /* Splitpane layout */
        .split-col { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        .split-pane { min-height: 48px; min-width: 0; overflow: auto; display: flex; flex-direction: column; }
        .split-pane.fill { flex: 1 1 auto; overflow: hidden; }
        #paneHdr { flex: 0 0 138px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        #paneFtr { flex: 0 0 auto; min-height: 100px; background: var(--bg-card); border-top: 1px solid var(--border-color); overflow: hidden; }

        /* Resizable Splitbar */
        .split-divider, .splitbar {
            height: 8px;
            background: var(--bg-base);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            cursor: row-resize;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            user-select: none;
            transition: background 0.15s;
            flex-shrink: 0;
            z-index: 20;
        }
        .split-divider:hover, .split-divider.dragging, .splitbar:hover, .splitbar.dragging {
            background: var(--primary) !important;
        }
        .split-divider .split-handle, .splitbar .split-handle, .split-grip {
            width: 40px;
            height: 3px;
            border-top: 1px solid var(--text-muted);
            border-bottom: 1px solid var(--text-muted);
            opacity: 0.7;
            pointer-events: none;
        }
        .split-divider:hover .split-handle, .split-divider.dragging .split-handle,
        .splitbar:hover .split-handle, .splitbar.dragging .split-handle {
            border-color: #ffffff !important;
            opacity: 1;
        }
        .split-divider .split-btn-collapse, .splitbar .split-btn-collapse, .split-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 8px;
            line-height: 1;
            padding: 1px 5px;
            height: 14px;
            border-radius: 2px;
            cursor: pointer;
            margin: 0 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            z-index: 25;
        }
        .split-divider .split-btn-collapse:hover, .splitbar .split-btn-collapse:hover, .split-btn:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        /* Form Controls */
        .hdr-grid { display: grid; grid-template-columns: 1.15fr 1fr; gap: 2px 12px; padding: 6px 10px; }
        .fld-row { display: grid; grid-template-columns: 82px 1fr; gap: 4px; align-items: center; margin-bottom: 2px; }
        .fld-row label { text-align: right; color: var(--text-muted); font-size: 9.5px; font-weight: 500; }
        .fld-row input, .fld-row select, .fld-row textarea, .browse-filter input, .browse-filter select { width: 100%; border: 1px solid var(--border-input); background: var(--bg-input); color: var(--text-main); padding: 1px 6px; height: 21px; border-radius: 2px; outline: none; font-size: 9.5px; }
        .fld-row input:focus, .fld-row select:focus, .fld-row textarea:focus, .browse-filter input:focus, .browse-filter select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.15); }
        .inline-box { display: flex; gap: 4px; align-items: center; }
        .pair-box { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 8px; }

        /* Excel-like Data Table */
        .grid-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        table.dw { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 9.5px; table-layout: auto; border: 1px solid var(--border-color); }
        table.dw thead { position: sticky; top: 0; z-index: 10; }
        table.dw th { position: relative; background: var(--table-head-bg); border: 1px solid var(--border-color); padding: 4px 6px; text-align: left; font-weight: 600; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.3px; color: var(--text-muted); white-space: nowrap; user-select: none; }
        table.dw td { border: 1px solid var(--border-light); padding: 2px 6px; font-size: 9.5px; line-height: 1.4; vertical-align: middle; }
        table.dw input { width: 100%; border: 1px solid transparent; background: transparent; color: var(--text-main); padding: 1px 4px; height: 21px; border-radius: 2px; font-size: 9.5px; font-family: inherit; }
        table.dw input:hover { border-color: var(--border-input); }
        table.dw input:focus { border-color: var(--primary); background: var(--bg-input); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.12); outline: none; }
        table.dw input.num { text-align: right; }
        table.dw tbody tr:hover td { background: var(--table-hover); }
        table.dw tr.sel td { background: var(--row-selected) !important; color: var(--row-selected-text) !important; }

        .highlight-inv-no {
            background: #fffbe6 !important;
            border: 1px solid #ffe58f !important;
            color: #d46b08 !important;
            font-weight: 700 !important;
            box-shadow: 0 0 0 1px rgba(250, 173, 20, 0.2);
        }
        body.dark-mode .highlight-inv-no, html.dark-mode .highlight-inv-no {
            background: #2b2111 !important;
            border-color: #594214 !important;
            color: #ffc069 !important;
        }

        /* Column Resizer Handle */
        .col-resizer { position: absolute; top: 0; right: 0; width: 7px; height: 100%; cursor: col-resize; user-select: none; z-index: 15; background: transparent; }
        .col-resizer:hover, .col-resizer.resizing { background-color: var(--primary) !important; }
        body.grid-resizing { cursor: col-resize !important; user-select: none !important; }

        /* Footer summary & Terbilang callout */
        .ftr-grid { display: grid; grid-template-columns: 1.25fr 360px; gap: 4px 12px; padding: 4px 10px; align-items: start; }
        .sum-box { display: grid; grid-template-columns: 1fr 1fr; gap: 0 8px; align-content: start; }
        .sum-box .fld-row { grid-template-columns: 82px 1fr; margin-bottom: 1px; }
        .sum-box .grand { font-weight: 700; color: var(--primary); }

        .terbilang-callout {
            margin-top: 4px;
            padding: 4px 8px;
            background: var(--table-head-bg);
            border-left: 3px solid var(--primary);
            border-radius: 2px;
            font-size: 10px;
            line-height: 1.35;
            color: var(--text-main);
            display: flex;
            gap: 4px;
            align-items: flex-start;
        }
        .terbilang-callout .lbl-say { font-weight: 700; color: var(--primary); white-space: nowrap; }
        .terbilang-callout .txt-say { font-style: italic; word-break: break-word; }

        /* Status Line */
        .statusline { padding: 4px 10px; border-top: 1px solid var(--border-color); color: var(--text-muted); min-height: 22px; background: var(--table-head-bg); display: flex; align-items: center; justify-content: space-between; font-size: 10px; }

        /* Browse Data Filter */
        .browse-filter { display: flex; flex-wrap: nowrap; gap: 6px; align-items: center; padding: 4px 10px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); white-space: nowrap; overflow-x: auto; }
        .browse-filter label { color: var(--text-muted); font-size: 10px; flex-shrink: 0; }
        .pager { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 10px; background: var(--bg-card); }
        .detail-strip { display: none; overflow: auto; border-top: 1px solid var(--border-color); background: var(--bg-card); }
        .detail-strip.show { display: block; flex: 0 0 180px; }

        /* Trace Boxes */
        .trace-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; background: var(--table-head-bg); border-bottom: 1px solid var(--border-color); }
        .trace-cap { background: var(--table-head-bg); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 4px 10px; font-weight: 600; font-size: 10px; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; }
        .trace-box { flex: 1; min-height: 0; overflow: auto; }

        /* Progress Bar */
        .progress-box { width: 100%; background: #e2e8f0; border-radius: 3px; height: 13px; overflow: hidden; position: relative; }
        body.dark-mode .progress-box { background: #303030; }
        .progress-bar { height: 100%; background: var(--success); transition: width 0.3s; }
        .progress-text { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 8.5px; font-weight: bold; color: #111; }
        body.dark-mode .progress-text { color: #fff; }

        /* Modals */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.45); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal.active { display: flex; }
        .modal-card { background: var(--bg-card); border-radius: 4px; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12); width: min(920px, 100%); max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--border-color); }
        .modal-card.wide { width: min(1080px, 100%); }
        .modal-h { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--border-color); font-weight: 600; font-size: 11px; background: var(--table-head-bg); }
        .modal-b { padding: 10px 12px; overflow: auto; flex: 1; min-height: 0; }
        .modal-f { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 12px; border-top: 1px solid var(--border-color); background: var(--table-head-bg); }
        .klas-row { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
        .klas-btn.active { color: #fff; background: var(--primary); border-color: var(--primary); font-weight: 600; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--text-muted); }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Header Bar -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-file-signature" style="color:var(--primary);"></i>
                <span>Purchase Requisition (Permintaan Pembelian)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
                <span class="ant-badge ant-badge-belum" id="statusBadgeHeader">Draft</span>
                <span id="statusActionButtons" style="display:inline-flex; gap:4px; margin-left:6px;"></span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="resetForm()"><i class="fa-solid fa-plus"></i> Baru</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="savePr()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                <button type="button" class="ant-btn" id="btnCopyPr" onclick="copyCurrentPr()" title="Duplikat seluruh rincian barang ke PR baru"><i class="fa-solid fa-copy"></i> Duplikat PR</button>
                <button type="button" class="ant-btn ant-btn-danger" id="btnDelete" onclick="deleteCurrent()"><i class="fa-solid fa-trash"></i> Hapus</button>
                <button type="button" class="ant-btn ant-btn-success" onclick="printPr()"><i class="fa-solid fa-print"></i> Cetak FPPB</button>
                <button type="button" class="ant-btn ant-btn-primary" id="btnCreatePo" style="display:none;" onclick="createPoFromCurrentPr()"><i class="fa-solid fa-cart-plus"></i> Terbitkan PO</button>
                <button type="button" class="ant-btn" id="btnAuditLog" onclick="showDocAuditLog(document.getElementById('prNo') ? document.getElementById('prNo').value : '', 'PR')" title="Lihat Catatan Riwayat Perubahan Data"><i class="fa-solid fa-clock-rotate-left" style="color:#1890ff;"></i> Log Perubahan</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i></button>
            </div>
        </div>

        <!-- Navigation Tabs (3 Tab Utama Sesuai PO) -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="entri"><i class="fa-solid fa-pen-to-square"></i> Entri (Purchase Requisition)</button>
            <button type="button" class="ant-tab-btn" data-tab="browse"><i class="fa-solid fa-table-list"></i> Browse Data</button>
            <button type="button" class="ant-tab-btn" data-tab="trace"><i class="fa-solid fa-route"></i> Trace Realisasi PR (PO &amp; RI)</button>
        </div>

        <!-- Tab 1: Entri (Purchase Requisition) -->
        <section class="ant-page active" id="pageEntri">
            <div class="split-col" id="splitEntri">
                <!-- Pane Header -->
                <div class="split-pane" id="paneHdr">
                    <div class="hdr-grid">
                        <!-- Sisi Kiri: Departemen, Pemohon, Prioritas, Keperluan -->
                        <div>
                            <input type="hidden" id="prId">
                            <input type="hidden" id="prStatus" value="0">
                            <div class="fld-row">
                                <label>Departemen:</label>
                                <div class="inline-box">
                                    <select id="prDept" style="flex:1;">
                                        <?php foreach ($departments as $d): ?>
                                        <option value="<?= htmlspecialchars($d['kode']) ?>"><?= htmlspecialchars($d['kode'] . ($d['nama'] !== $d['kode'] ? ' - ' . $d['nama'] : '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="prPriority" style="width:110px;">
                                        <option value="NORMAL">NORMAL</option>
                                        <option value="URGENT">URGENT (SEGERA)</option>
                                        <option value="LOW">LOW (RUTIN)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="fld-row">
                                <label>Pemohon:</label>
                                <input type="text" id="prRequester" value="<?= htmlspecialchars($currentUser) ?>" placeholder="Nama Personil Pemohon">
                            </div>
                            <div class="fld-row">
                                <label>Keperluan:</label>
                                <input type="text" id="prPurpose" placeholder="Alasan / tujuan pengadaan barang">
                            </div>
                        </div>

                        <!-- Sisi Kanan: No PR, Tanggal PR, Target Butuh -->
                        <div>
                            <div class="pair-box">
                                <div class="fld-row"><label style="font-weight:700; color:var(--primary);">No. PR:</label>
                                    <div class="inline-box">
                                        <input type="text" id="prNo" class="highlight-inv-no" placeholder="Otomatis jika kosong">
                                        <button type="button" class="ant-btn" onclick="generateAutoPrNo()" title="Generate Nomor Urut"><i class="fa-solid fa-rotate"></i></button>
                                    </div>
                                </div>
                                <div class="fld-row"><label>Target Butuh:</label><input type="date" id="requiredDt" value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></div>
                            </div>
                            <div class="pair-box">
                                <div class="fld-row"><label>Tgl PR:</label><input type="date" id="prDt" value="<?= $today ?>"></div>
                                <div class="fld-row"><label>Approval:</label><input type="text" id="prApprovedInfo" readonly placeholder="Belum disetujui" class="muted"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resizable Splitbar Hdr/Grid -->
                <div class="split-divider" id="splitHdr" data-pane="paneHdr" title="Geser untuk mengubah tinggi Header Form PR">
                    <button type="button" class="split-btn-collapse" onclick="togglePrPane('hdr')" title="Tutup / Buka Panel Header"><i class="fa-solid fa-chevron-up"></i></button>
                    <div class="split-handle"></div>
                </div>

                <!-- Pane Grid -->
                <div class="split-pane fill" id="paneGrid">
                    <div class="grid-wrap">
                        <table class="dw" id="tableItems">
                            <thead>
                                <tr>
                                    <th style="width:30px; text-align:center;">#</th>
                                    <th style="width:140px;">Kode Barang</th>
                                    <th style="min-width:240px;">Nama &amp; Uraian Barang</th>
                                    <th style="width:180px;">Spesifikasi / Merk Khusus</th>
                                    <th style="width:85px; text-align:right;">Qty Minta</th>
                                    <th style="width:60px;">Satuan</th>
                                    <th style="width:115px; text-align:right;">Est. Harga</th>
                                    <th style="width:125px; text-align:right;">Est. Total</th>
                                    <th style="width:80px; text-align:right;">Qty PO</th>
                                    <th style="width:80px; text-align:right;">Sisa Qty</th>
                                    <th style="width:130px;">No. PO Acuan</th>
                                    <th style="width:140px;">Catatan</th>
                                    <th style="width:35px; text-align:center;">&times;</th>
                                </tr>
                            </thead>
                            <tbody id="itemsContainer"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Resizable Splitbar Grid/Ftr -->
                <div class="split-divider" id="splitFtr" data-pane="paneFtr" data-edge="next" title="Geser untuk mengubah tinggi Footer Ringkasan">
                    <button type="button" class="split-btn-collapse" onclick="togglePrPane('ftr')" title="Tutup / Buka Panel Footer"><i class="fa-solid fa-chevron-down"></i></button>
                    <div class="split-handle"></div>
                </div>

                <!-- Pane Footer -->
                <div class="split-pane" id="paneFtr">
                    <div class="ftr-grid">
                        <div>
                            <div class="fld-row" style="grid-template-columns: 82px 1fr; margin-bottom: 2px;">
                                <label>Catatan Tambahan:</label>
                                <textarea id="prNote" rows="2" placeholder="Catatan internal pengadaan / instruksi pembelian..."></textarea>
                            </div>
                            <div class="terbilang-callout" id="terbilangBox">
                                <span class="lbl-say"><i class="fa-solid fa-comment-dots"></i> Terbilang:</span>
                                <span class="txt-say" id="txtTerbilang">-</span>
                            </div>
                        </div>

                        <!-- Summary Box -->
                        <div class="sum-box">
                            <div class="fld-row"><label>Total Item:</label><input type="text" id="prTotalItem" readonly class="num" value="0"></div>
                            <div class="fld-row"><label>Total Qty:</label><input type="text" id="prTotalQty" readonly class="num" value="0"></div>
                            <div class="fld-row grand"><label>Est. Nilai:</label><input type="text" id="prEstAmount" readonly class="num highlight-inv-no" value="0.00"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Toolbar Grid Footer -->
            <div class="ant-toolbar" style="border-top:1px solid var(--border-color); border-bottom:none; justify-content:space-between;">
                <div style="display:flex; gap:4px; align-items:center;">
                    <button type="button" class="ant-btn" onclick="addItemRow()"><i class="fa-solid fa-plus"></i> Tambah Baris</button>
                    <button type="button" class="ant-btn" onclick="openLookupMaterial()"><i class="fa-solid fa-boxes-stacked"></i> Master Barang</button>
                    <button type="button" class="ant-btn ant-btn-danger" onclick="removeSelectedRow()"><i class="fa-solid fa-trash"></i> Hapus Baris</button>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <span class="muted" style="font-size:10px;">Presisi Desimal:</span>
                    <select id="numDecimals" style="height:20px; font-size:10px; width:65px;" onchange="changeDecimalPrecision(this.value)">
                        <option value="0">0 desimal</option>
                        <option value="2">2 desimal</option>
                        <option value="3">3 desimal</option>
                        <option value="4">4 desimal</option>
                    </select>
                </div>
            </div>

            <!-- Status Line -->
            <div class="statusline">
                <span id="statusMessage">Siap.</span>
                <span id="statusInfoRight" class="muted">CEISA 4.0 ERP Hub</span>
            </div>
        </section>

        <!-- Tab 2: Browse Data -->
        <section class="ant-page" id="pageBrowse">
            <div class="browse-filter">
                <label>Cari:</label>
                <input type="text" id="browseSearch" placeholder="No PR, Pemohon, Keperluan..." style="width:190px;" oninput="onBrowseFilterChange()">
                <label>Dept:</label>
                <select id="browseDept" style="width:130px;" onchange="loadPrList(1)">
                    <option value="">Semua Dept</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= htmlspecialchars($d['kode']) ?>"><?= htmlspecialchars($d['kode']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Status:</label>
                <select id="browseStatus" style="width:140px;" onchange="loadPrList(1)">
                    <option value="">Semua Status</option>
                    <option value="0">Draft</option>
                    <option value="1">Menunggu Approval</option>
                    <option value="2">Approved (Siap PO)</option>
                    <option value="3">In PO (Diproses)</option>
                    <option value="4">Closed (Selesai)</option>
                    <option value="9">Ditolak (Rejected)</option>
                </select>
                <label>Tgl:</label>
                <input type="date" id="browseTglAwal" style="width:115px;" onchange="loadPrList(1)">
                <span class="muted">-</span>
                <input type="date" id="browseTglAkhir" style="width:115px;" onchange="loadPrList(1)">
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadPrList(1)"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <button type="button" class="ant-btn" onclick="resetBrowseFilter()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
            </div>

            <div class="grid-wrap" style="flex:1;">
                <table class="dw" id="prTable">
                    <thead>
                        <tr>
                            <th style="width:30px; text-align:center;">#</th>
                            <th style="width:130px;">No. PR</th>
                            <th style="width:85px;">Tgl PR</th>
                            <th style="width:90px;">Target Butuh</th>
                            <th style="width:105px;">Dept</th>
                            <th style="width:120px;">Pemohon</th>
                            <th style="width:85px;">Prioritas</th>
                            <th style="width:115px;">Status</th>
                            <th style="width:145px;">Realisasi PO &amp; Sisa</th>
                            <th style="width:115px; text-align:right;">Est. Nilai</th>
                            <th style="width:140px; text-align:center;" class="col-action">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="prTableBody"></tbody>
                </table>
            </div>

            <div class="pager">
                <span id="pageInfo">Menampilkan 0 data</span>
                <div style="display:flex; gap:4px; align-items:center;">
                    <button type="button" class="ant-btn" id="btnPrevPage" onclick="prevPage()">&laquo; Prev</button>
                    <span id="pageCurrent" style="padding:0 6px; font-weight:600;">1</span>
                    <button type="button" class="ant-btn" id="btnNextPage" onclick="nextPage()">Next &raquo;</button>
                </div>
            </div>

            <!-- Detail Strip Preview -->
            <div class="detail-strip" id="prDetailStrip">
                <div class="trace-cap">
                    <span><i class="fa-solid fa-list-check" style="color:var(--primary);"></i> Rincian Barang Permintaan Pembelian: <strong id="stripDocNo">-</strong></span>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="closeDetailStrip()">&times; Tutup</button>
                </div>
                <div class="grid-wrap" style="height:140px;">
                    <table class="dw">
                        <thead>
                            <tr>
                                <th style="width:30px; text-align:center;">#</th>
                                <th style="width:140px;">Kode Barang</th>
                                <th>Nama / Uraian Barang</th>
                                <th style="width:160px;">Spesifikasi Khusus</th>
                                <th style="width:80px; text-align:right;">Qty Minta</th>
                                <th style="width:80px; text-align:right;">Qty PO</th>
                                <th style="width:80px; text-align:right;">Sisa Qty</th>
                                <th style="width:55px;">Sat</th>
                                <th style="width:110px; text-align:right;">Est. Harga</th>
                                <th style="width:120px; text-align:right;">Est. Total</th>
                            </tr>
                        </thead>
                        <tbody id="stripItemsBody"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Tab 3: Trace Realisasi PR (PO & RI) -->
        <section class="ant-page" id="pageTrace">
            <div class="trace-toolbar">
                <div style="display:flex; gap:8px; align-items:center;">
                    <label>Pilih Dokumen PR:</label>
                    <input type="text" id="tracePrInput" placeholder="Ketik No PR..." style="width:180px; height:22px; font-size:11px;" onkeydown="if(event.key==='Enter') loadPrTrace()">
                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadPrTrace()"><i class="fa-solid fa-magnifying-glass"></i> Lacak</button>
                </div>
                <span class="muted" id="traceHeaderStatus"></span>
            </div>

            <div class="split-col" style="flex:1;">
                <div class="trace-box" style="flex:1; border-bottom:1px solid var(--border-color);">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-cart-shopping" style="color:var(--primary);"></i> 1. Purchase Order (PO) yang Diterbitkan dari PR Ini</span>
                    </div>
                    <div class="grid-wrap">
                        <table class="dw">
                            <thead>
                                <tr>
                                    <th style="width:130px;">No. PO</th>
                                    <th style="width:85px;">Tgl PO</th>
                                    <th>Supplier / Vendor</th>
                                    <th style="width:60px;">Valuta</th>
                                    <th style="width:110px; text-align:right;">Total Order</th>
                                    <th style="width:90px; text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="tracePoBody">
                                <tr><td colspan="6" class="muted" style="text-align:center; padding:15px;">Belum ada data PO.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="trace-box" style="flex:1;">
                    <div class="trace-cap">
                        <span><i class="fa-solid fa-truck-ramp-box" style="color:var(--success);"></i> 2. Receive Item (RI / Penerimaan Gudang) Terkait</span>
                    </div>
                    <div class="grid-wrap">
                        <table class="dw">
                            <thead>
                                <tr>
                                    <th style="width:130px;">No. RI</th>
                                    <th style="width:85px;">Tgl RI</th>
                                    <th style="width:130px;">No. PO</th>
                                    <th style="width:90px;">Dokumen Pabean</th>
                                    <th style="width:90px;">Gudang</th>
                                    <th style="width:110px; text-align:right;">Total Nilai</th>
                                </tr>
                            </thead>
                            <tbody id="traceRiBody">
                                <tr><td colspan="6" class="muted" style="text-align:center; padding:15px;">Belum ada data Penerimaan Barang.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- Modal: Pilih Material / Master Barang -->
<div class="modal" id="modalMaterial">
    <div class="modal-card wide">
        <div class="modal-h">
            <span id="materialTitle"><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Pilih Master Barang : Semua Klasifikasi</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeMaterialPicker()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div class="klas-row">
                <button type="button" class="ant-btn klas-btn active" data-klas="ALL" onclick="setKlas('ALL')">Semua Klasifikasi</button>
                <button type="button" class="ant-btn klas-btn" data-klas="RM" onclick="setKlas('RM')">Raw Materials (RM)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="FG" onclick="setKlas('FG')">Finished Goods (FG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="WI" onclick="setKlas('WI')">Work In Progress (WIP)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="CG" onclick="setKlas('CG')">Capital Goods (CG)</button>
                <button type="button" class="ant-btn klas-btn" data-klas="W" onclick="setKlas('W')">Wastes / Scrap</button>
            </div>
            <div class="inline-box" style="margin-bottom:8px;">
                <input type="text" id="materialSearch" style="flex:1;" placeholder="Ketik kode barang atau uraian..." oninput="queueMaterialSearch()" onkeydown="if(event.key==='Enter'){event.preventDefault(); searchMaterials();}">
                <button type="button" class="ant-btn ant-btn-primary" onclick="searchMaterials()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
            </div>
            <div class="grid-wrap" style="height:360px; min-height:360px; border:1px solid var(--border-color); border-radius:3px;">
                <table class="dw" style="min-width:0;">
                    <thead>
                        <tr>
                            <th style="width:140px;">Kode Barang</th>
                            <th>Uraian Barang</th>
                            <th style="width:160px;">Spesifikasi / Part No</th>
                            <th style="width:65px; text-align:center;">Satuan</th>
                            <th style="width:105px; text-align:right;">Harga Satuan</th>
                            <th style="width:110px;">Klasifikasi</th>
                        </tr>
                    </thead>
                    <tbody id="materialBody">
                        <tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Ketik pencarian atau klik Cari. Dobel-klik baris untuk memilih.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-f">
            <span class="muted" style="font-size:9.5px;">Gunakan tombol panah / dobel klik baris untuk memilih barang master.</span>
            <div class="inline-box">
                <input type="text" id="materialChosen" readonly placeholder="Kode terpilih" style="width:200px;">
                <button type="button" class="ant-btn ant-btn-primary" onclick="confirmMaterial()"><i class="fa-solid fa-check"></i> PILIH BARANG</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: PANDUAN LENGKAP PURCHASE REQUISITION (PR) -->
<div class="modal" id="modalModuleGuide">
    <div class="modal-card" style="max-width:820px; max-height:88vh;">
        <div class="modal-h" style="background:linear-gradient(135deg, rgba(24,144,255,0.08), rgba(24,144,255,0.02));">
            <span style="font-weight:700; color:var(--primary); font-size:13px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-book-open"></i> Panduan Lengkap: Purchase Requisition (FPPB) &amp; SOP Pengadaan
            </span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="padding:16px 20px; overflow-y:auto; line-height:1.55; font-size:11px;">
            <div style="background:rgba(24,144,255,0.06); border-left:3px solid var(--primary); padding:10px 14px; border-radius:4px; margin-bottom:16px;">
                <strong style="color:var(--primary); font-size:11.5px;">SOP Pengajuan Form Permintaan Pembelian Barang (FPPB / PR):</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main);">Purchase Requisition merupakan dokumen permohonan internal resmi dari departemen operasional (Produksi, PPIC, Maintenance, GA, IT, QC) kepada divisi Purchasing untuk pengadaan barang atau jasa perusahaan.</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#1890ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-list-check"></i> 1. Langkah Entri Permintaan (PR)
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Departemen:</strong> Pilih bagian atau departemen internal yang mengajukan pengadaan.</li>
                        <li><strong>Requester:</strong> Nama lengkap staf atau penanggung jawab pemohon barang.</li>
                        <li><strong>Target Tgl Butuh:</strong> Tanggal batas waktu barang harus tiba di lokasi pabrik/kantor.</li>
                        <li><strong>Tingkat Urgensi:</strong>
                            <div style="font-size:10px; color:var(--text-muted); margin-top:2px;">
                                • <code>NORMAL</code>: Pengadaan terjadwal rutin.<br>
                                • <code>CEPAT</code>: Stok pengaman menipis.<br>
                                • <code>URGENT</code>: Kritis / kendala mesin / stop line.
                            </div>
                        </li>
                    </ul>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#52c41a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-arrows-turn-to-dots"></i> 2. Alur Approval &amp; Realisasi ke PO
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Status Draft:</strong> PR baru diinput dan masih dapat diedit oleh pembuat.</li>
                        <li><strong>Status Approval:</strong> Disetujui oleh Kepala Departemen / Manager bagian (berdasarkan matriks otoritas persetujuan).</li>
                        <li><strong>Tarik Konsolidasi PO:</strong> Bagian Purchasing dapat memilih 1 atau <strong>banyak baris PR sekaligus</strong> untuk diterbitkan menjadi 1 PO pengadaan ke Supplier.</li>
                    </ul>
                </div>
            </div>

            <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card); margin-bottom:14px;">
                <div style="font-weight:700; color:#faad14; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-boxes-packing"></i> 3. Kode Barang &amp; Standarisasi Material
                </div>
                <p style="margin:0 0 6px 0; color:var(--text-main);">Pada tahap PR, pemohon diperbolehkan memilih kode barang yang sudah ada di master barang maupun mengetik deskripsi barang umum / jasa. Saat PR ditarik ke Purchase Order (PO), sistem akan memvalidasi apakah kode barang telah terdaftar di master pabean demi ketertiban kepabeanan.</p>
            </div>

            <div style="background:rgba(82,196,26,0.08); border:1px solid rgba(82,196,26,0.3); border-radius:6px; padding:10px 14px;">
                <strong style="color:#389e0d;"><i class="fa-solid fa-print"></i> Dokumen Cetak Resmi &amp; Logo Perusahaan:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main); font-size:10.5px;">Klik tombol <strong>Cetak FPPB</strong> untuk mencetak formulir Purchase Requisition resmi lengkap dengan Logo Perusahaan, rincian estimasi biaya, kolom verifikasi pemohon, persetujuan kepala departemen, dan tanda terima bagian Purchasing.</p>
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
let currentPage = 1;
let selectedBrowseId = '';
let currentPrecision = parseInt(localStorage.getItem('ceisa_po_decimals') || '2', 10);
if (isNaN(currentPrecision) || currentPrecision < 0 || currentPrecision > 4) currentPrecision = 2;
let curMaterialKlas = 'ALL';
let materialTimer = null;
let materialCache = [];
let pickerRow = null;
let pickerSelected = -1;
const klasLabel = {
    'ALL': 'Semua Klasifikasi',
    'RM': 'Raw Materials (RM)',
    'FG': 'Finished Goods (FG)',
    'WI': 'Work In Progress (WIP)',
    'CG': 'Capital Goods (CG)',
    'W':  'Wastes / Scrap'
};

// Sinkronisasi Tema Gelap (Dark Mode)
function applyTheme(theme) {
    if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        document.body.classList.add('dark-mode');
    } else {
        document.documentElement.classList.remove('dark-mode');
        document.body.classList.remove('dark-mode');
    }
}
window.addEventListener('message', (ev) => {
    if (ev.data && (ev.data.type === 'SET_THEME' || ev.data.action === 'SET_THEME')) {
        applyTheme(ev.data.theme);
    }
});
window.addEventListener('storage', (ev) => {
    if (ev.key === 'ceisa_theme') {
        applyTheme(ev.newValue);
    }
});

function openModuleGuide() {
    const m = document.getElementById('modalModuleGuide');
    if (m) m.classList.add('active');
}

function closeModuleGuide() {
    const m = document.getElementById('modalModuleGuide');
    if (m) m.classList.remove('active');
}

document.addEventListener('DOMContentLoaded', () => {
    try {
        const initialTheme = localStorage.getItem('ceisa_theme');
        if (initialTheme) applyTheme(initialTheme);
    } catch (e) {}

    initSplitbars();
    setupGridColumnResize('#tableItems', 'pr_items');
    setupGridColumnResize('#prTable', 'pr_browse');
    try {
        const selDec = document.getElementById('numDecimals');
        if (selDec) selDec.value = String(currentPrecision);
    } catch(e) {}
    resetForm();
    loadPrList(1);
});

// Tab Switcher
document.querySelectorAll('.ant-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.ant-page').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const tabKey = btn.dataset.tab;
        if (tabKey === 'entri') document.getElementById('pageEntri').classList.add('active');
        else if (tabKey === 'browse') {
            document.getElementById('pageBrowse').classList.add('active');
            loadPrList(currentPage);
        } else if (tabKey === 'trace') {
            document.getElementById('pageTrace').classList.add('active');
            const curNo = document.getElementById('prNo').value;
            if (curNo) {
                document.getElementById('tracePrInput').value = curNo;
                loadPrTrace();
            }
        }
    });
});

// Resizer Engine
function initSplitbars() {
    document.querySelectorAll('.splitbar, .split-divider').forEach(bar => {
        const pane = document.getElementById(bar.dataset.pane || '');
        if (!pane) return;
        const key = 'pr-split-v1-' + (bar.id || bar.dataset.pane);
        const saved = sessionStorage.getItem(key);
        if (saved && parseInt(saved, 10) >= 60) pane.style.flex = '0 0 ' + parseInt(saved, 10) + 'px';
        bar.addEventListener('pointerdown', (e) => {
            if (e.target.closest('.split-btn-collapse')) return;
            e.preventDefault();
            const startY = e.clientY;
            const startH = pane.getBoundingClientRect().height;
            const parentH = bar.parentElement.getBoundingClientRect().height;
            bar.classList.add('dragging');
            const move = (ev) => {
                const dy = ev.clientY - startY;
                let h = bar.dataset.edge === 'next' ? startH - dy : startH + dy;
                h = Math.max(60, Math.min(h, parentH - 100));
                pane.style.flex = '0 0 ' + h + 'px';
            };
            const up = () => {
                bar.classList.remove('dragging');
                sessionStorage.setItem(key, String(Math.round(pane.getBoundingClientRect().height)));
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        });
    });
}

function togglePrPane(which) {
    const pane = document.getElementById(which === 'hdr' ? 'paneHdr' : 'paneFtr');
    if (!pane) return;
    const curH = pane.getBoundingClientRect().height;
    if (curH > 20) {
        pane.dataset.prevH = curH;
        pane.style.flex = '0 0 0px';
        pane.style.display = 'none';
    } else {
        const h = pane.dataset.prevH || (which === 'hdr' ? 138 : 100);
        pane.style.display = 'flex';
        pane.style.flex = '0 0 ' + h + 'px';
    }
}

// Format Angka & Desimal
function parseRawNumber(val) {
    if (val === null || val === undefined || val === '') return 0;
    if (typeof val === 'number') return isNaN(val) ? 0 : val;
    let s = String(val).trim();
    if (s.indexOf('.') !== -1 && s.indexOf(',') !== -1) {
        if (s.lastIndexOf(',') > s.lastIndexOf('.')) {
            s = s.replace(/\./g, '').replace(/,/g, '.');
        } else {
            s = s.replace(/,/g, '');
        }
    } else if (s.indexOf(',') !== -1) {
        if (s.length - s.lastIndexOf(',') <= 3) s = s.replace(/,/g, '.');
        else s = s.replace(/,/g, '');
    }
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}

function formatMoney(val, decimals) {
    if (decimals === undefined || decimals === null) decimals = currentPrecision;
    const n = parseRawNumber(val);
    return n.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function changeDecimalPrecision(val) {
    currentPrecision = parseInt(val, 10);
    if (isNaN(currentPrecision) || currentPrecision < 0 || currentPrecision > 4) currentPrecision = 2;
    localStorage.setItem('ceisa_po_decimals', currentPrecision);
    
    document.querySelectorAll('#itemsContainer tr').forEach(row => {
        const qtyInp = row.querySelector('.it-qty');
        const priceInp = row.querySelector('.it-price');
        const amtInp = row.querySelector('.it-amount');
        if (qtyInp) {
            const raw = parseRawNumber(qtyInp.dataset.raw !== undefined ? qtyInp.dataset.raw : qtyInp.value);
            qtyInp.dataset.raw = raw;
            qtyInp.value = formatMoney(raw, Math.min(currentPrecision, 2));
        }
        if (priceInp) {
            const raw = parseRawNumber(priceInp.dataset.raw !== undefined ? priceInp.dataset.raw : priceInp.value);
            priceInp.dataset.raw = raw;
            priceInp.value = formatMoney(raw, currentPrecision);
        }
        if (amtInp) {
            const raw = parseRawNumber(amtInp.dataset.raw !== undefined ? amtInp.dataset.raw : amtInp.value);
            amtInp.dataset.raw = raw;
            amtInp.value = formatMoney(raw, currentPrecision);
        }
    });
    calcTotals();
}

function onNumFocus(el) {
    const raw = (el.dataset.raw !== undefined && el.dataset.raw !== '') ? el.dataset.raw : parseRawNumber(el.value);
    el.value = raw > 0 ? String(raw) : (raw === 0 ? '0' : '');
    setTimeout(() => el.select(), 10);
}

function onQtyBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, Math.min(currentPrecision, 2));
    calcItemRow(el);
}

function onPriceBlur(el) {
    const raw = parseRawNumber(el.value);
    el.dataset.raw = raw;
    el.value = formatMoney(raw, currentPrecision);
    calcItemRow(el);
}

function calcItemRow(input) {
    const row = input.closest('tr');
    if (!row) return;
    const qtyInp = row.querySelector('.it-qty');
    const priceInp = row.querySelector('.it-price');
    const amtInp = row.querySelector('.it-amount');

    const qty = parseRawNumber(qtyInp.dataset.raw !== undefined ? qtyInp.dataset.raw : qtyInp.value);
    const price = parseRawNumber(priceInp.dataset.raw !== undefined ? priceInp.dataset.raw : priceInp.value);
    const amount = qty * price;

    if (amtInp) {
        amtInp.dataset.raw = amount;
        amtInp.value = formatMoney(amount, currentPrecision);
    }
    calcTotals();
}

function calcTotals() {
    let totalItems = 0;
    let totalQty = 0;
    let totalAmount = 0;

    document.querySelectorAll('#itemsContainer tr').forEach(row => {
        const kd = (row.querySelector('.it-kode')?.value || '').trim();
        const nm = (row.querySelector('.it-nama')?.value || '').trim();
        const qtyInp = row.querySelector('.it-qty');
        const amtInp = row.querySelector('.it-amount');
        if (kd || nm) {
            totalItems++;
            const q = parseRawNumber(qtyInp ? (qtyInp.dataset.raw !== undefined ? qtyInp.dataset.raw : qtyInp.value) : 0);
            const a = parseRawNumber(amtInp ? (amtInp.dataset.raw !== undefined ? amtInp.dataset.raw : amtInp.value) : 0);
            totalQty += q;
            totalAmount += a;
        }
    });

    document.getElementById('prTotalItem').value = totalItems;
    document.getElementById('prTotalQty').value = formatMoney(totalQty, 2);
    document.getElementById('prEstAmount').value = formatMoney(totalAmount, currentPrecision);
    document.getElementById('txtTerbilang').innerText = terbilang(totalAmount) + ' Rupiah';
}

function terbilang(angka) {
    const bilangan = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
    angka = Math.floor(Math.abs(angka));
    if (angka < 12) return bilangan[angka];
    if (angka < 20) return terbilang(angka - 10) + ' Belas';
    if (angka < 100) return terbilang(Math.floor(angka / 10)) + ' Puluh ' + terbilang(angka % 10);
    if (angka < 200) return 'Seratus ' + terbilang(angka - 100);
    if (angka < 1000) return terbilang(Math.floor(angka / 100)) + ' Ratus ' + terbilang(angka % 100);
    if (angka < 2000) return 'Seribu ' + terbilang(angka - 1000);
    if (angka < 1000000) return terbilang(Math.floor(angka / 1000)) + ' Ribu ' + terbilang(angka % 1000);
    if (angka < 1000000000) return terbilang(Math.floor(angka / 1000000)) + ' Juta ' + terbilang(angka % 1000000);
    return terbilang(Math.floor(angka / 1000000000)) + ' Miliar ' + terbilang(angka % 1000000000);
}

// Reset & Form State
function resetForm() {
    document.getElementById('prId').value = '';
    document.getElementById('prNo').value = '';
    document.getElementById('prDt').value = new Date().toISOString().split('T')[0];
    const nextWeek = new Date();
    nextWeek.setDate(nextWeek.getDate() + 7);
    document.getElementById('requiredDt').value = nextWeek.toISOString().split('T')[0];
    document.getElementById('prDept').value = <?= json_encode(!empty($departments[0]['kode']) ? $departments[0]['kode'] : 'PRODUKSI') ?>;
    document.getElementById('prRequester').value = <?= json_encode($currentUser) ?>;
    document.getElementById('prPriority').value = 'NORMAL';
    document.getElementById('prPurpose').value = '';
    document.getElementById('prNote').value = '';
    document.getElementById('prStatus').value = '0';
    document.getElementById('prApprovedInfo').value = 'Draft (Belum diajukan)';
    document.getElementById('itemsContainer').innerHTML = '';

    addItemRow();
    updateStatusHeader('0');
    setStatus('Form baru siap diinput.');
}

function generateAutoPrNo() {
    const ym = new Date().toISOString().slice(2, 7).replace('-', '');
    const prefix = CURRENT_CMP_ID ? (CURRENT_CMP_ID + '-PR' + ym) : ('PR-' + ym);
    const rand = Math.floor(Math.random() * 900) + 100;
    document.getElementById('prNo').value = `${prefix}-${rand}`;
}

function updateStatusHeader(status, approvedBy = '', approvedDt = '', rejectedNote = '') {
    const b = document.getElementById('statusBadgeHeader');
    const actBox = document.getElementById('statusActionButtons');
    const btnDel = document.getElementById('btnDelete');
    const btnPo = document.getElementById('btnCreatePo');
    const id = document.getElementById('prId').value;
    const prNo = document.getElementById('prNo').value || '(Draft)';

    actBox.innerHTML = '';
    btnPo.style.display = 'none';
    if (btnDel) btnDel.style.display = (status === '0' || <?= json_encode($isAdmin) ?>) ? 'inline-flex' : 'none';

    if (status === '0') {
        b.className = 'ant-badge ant-badge-belum';
        b.innerText = 'Draft';
        if (id) {
            actBox.innerHTML = `<button type="button" class="ant-btn ant-btn-sm ant-btn-warning" onclick="changeStatus('1')"><i class="fa-solid fa-paper-plane"></i> Ajukan Approval</button>`;
        }
    } else if (status === '1') {
        b.className = 'ant-badge ant-badge-belum';
        b.style.background = '#faad1420';
        b.innerText = 'Menunggu Approval';
        actBox.innerHTML = `
            <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="changeStatus('2')"><i class="fa-solid fa-check"></i> Approve PR</button>
            <button type="button" class="ant-btn ant-btn-sm ant-btn-danger" onclick="promptReject()"><i class="fa-solid fa-ban"></i> Tolak</button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="changeStatus('0')"><i class="fa-solid fa-rotate-left"></i> Draft</button>
        `;
    } else if (status === '2') {
        b.className = 'ant-badge ant-badge-lunas';
        b.innerText = 'Approved';
        btnPo.style.display = 'inline-flex';
        actBox.innerHTML = `
            <button type="button" class="ant-btn ant-btn-sm ant-btn-warning" onclick="changeStatus('0')"><i class="fa-solid fa-rotate-left"></i> Buka ke Draft</button>
        `;
    } else if (status === '3') {
        b.className = 'ant-badge ant-badge-company';
        b.innerText = 'In PO';
        btnPo.style.display = 'inline-flex';
    } else if (status === '4') {
        b.className = 'ant-badge ant-badge-lunas';
        b.innerText = 'Closed';
    } else if (status === '9') {
        b.className = 'ant-badge ant-badge-lock';
        b.innerText = 'Ditolak';
        actBox.innerHTML = `
            <button type="button" class="ant-btn ant-btn-sm ant-btn-warning" onclick="changeStatus('0')"><i class="fa-solid fa-rotate-left"></i> Aktifkan ke Draft</button>
        `;
    }

    if (approvedBy) {
        document.getElementById('prApprovedInfo').value = `Approved: ${approvedBy} (${approvedDt})`;
    } else if (rejectedNote) {
        document.getElementById('prApprovedInfo').value = `Ditolak: ${rejectedNote}`;
    }
}

// Add & Remove Item Rows
function addItemRow(it = {}) {
    const tbody = document.getElementById('itemsContainer');
    const tr = document.createElement('tr');
    tr.onclick = () => selectItemRow(tr);

    const qty = parseFloat(it.qty) || 1;
    const price = parseFloat(it.est_price) || 0;
    const amount = qty * price;
    const qtyPo = parseFloat(it.qty_po) || 0;
    const sisaQty = parseFloat(it.sisa_qty) > 0 ? parseFloat(it.sisa_qty) : (qty - qtyPo);

    tr.innerHTML = `
        <td style="text-align:center;" class="seq-cell">${tbody.children.length + 1}</td>
        <td>
            <div class="inline-box">
                <input type="text" class="it-kode" value="${escapeHtml(it.kode_barang || '')}" placeholder="Pilih / ketik barang..." ondblclick="openMaterialPicker(this)">
                <button type="button" class="ant-btn ant-btn-sm" title="Buka katalog master barang" onclick="openMaterialPicker(this)"><i class="fa-solid fa-magnifying-glass"></i></button>
            </div>
        </td>
        <td><input type="text" class="it-nama" value="${escapeHtml(it.uraian || '')}" placeholder="Nama / Uraian Barang" ondblclick="openMaterialPicker(this)"></td>
        <td><input type="text" class="it-spek" value="${escapeHtml(it.spesifikasi || '')}" placeholder="Merk / Part No / Spesifikasi"></td>
        <td><input type="text" class="it-qty num" value="${formatMoney(qty, Math.min(currentPrecision, 2))}" data-raw="${qty}" onfocus="onNumFocus(this)" onblur="onQtyBlur(this)"></td>
        <td><input type="text" class="it-sat" value="${escapeHtml(it.sat || 'PCE')}" placeholder="PCE" style="text-align:center;"></td>
        <td><input type="text" class="it-price num" value="${formatMoney(price, currentPrecision)}" data-raw="${price}" onfocus="onNumFocus(this)" onblur="onPriceBlur(this)"></td>
        <td><input type="text" class="it-amount num" value="${formatMoney(amount, currentPrecision)}" data-raw="${amount}" readonly></td>
        <td><input type="text" class="it-qty-po num" value="${formatMoney(qtyPo, 2)}" readonly class="muted"></td>
        <td><input type="text" class="it-sisa num" value="${formatMoney(sisaQty, 2)}" readonly class="muted"></td>
        <td><input type="text" class="it-po-no" value="${escapeHtml(it.po_no || '')}" readonly placeholder="-" class="muted"></td>
        <td><input type="text" class="it-note" value="${escapeHtml(it.note || '')}" placeholder="Catatan"></td>
        <td style="text-align:center;"><button type="button" class="ant-btn ant-btn-sm ant-btn-danger" style="padding:0 4px; height:18px;" onclick="removeThisRow(this)">&times;</button></td>
    `;
    tbody.appendChild(tr);
    calcTotals();
    return tr;
}

function removeThisRow(btn) {
    const tr = btn.closest('tr');
    tr.remove();
    renumberRows();
    calcTotals();
}

function removeSelectedRow() {
    const sel = document.querySelector('#itemsContainer tr.sel');
    if (sel) {
        sel.remove();
        renumberRows();
        calcTotals();
    } else {
        const last = document.querySelector('#itemsContainer tr:last-child');
        if (last) {
            last.remove();
            calcTotals();
        }
    }
}

function renumberRows() {
    document.querySelectorAll('#itemsContainer tr').forEach((tr, idx) => {
        const cell = tr.querySelector('.seq-cell');
        if (cell) cell.innerText = idx + 1;
    });
}

function selectItemRow(tr) {
    document.querySelectorAll('#itemsContainer tr').forEach(r => r.classList.remove('sel'));
    tr.classList.add('sel');
}

// Master Barang Lookup & Picker (Identik dengan PO)
function openMaterialPicker(el) {
    const row = el ? el.closest('tr') : (document.querySelector('#itemsContainer tr.sel') || document.querySelector('#itemsContainer tr:last-child'));
    if (!row) {
        pickerRow = addItemRow();
    } else {
        pickerRow = row;
    }
    pickerSelected = -1;
    const curKode = pickerRow.querySelector('.it-kode')?.value || '';
    document.getElementById('materialChosen').value = curKode;
    document.getElementById('modalMaterial').classList.add('active');
    const box = document.getElementById('materialSearch');
    box.value = curKode;
    box.focus();
    searchMaterials();
}
function openLookupMaterial() {
    openMaterialPicker();
}
function closeMaterialPicker() {
    document.getElementById('modalMaterial').classList.remove('active');
    pickerRow = null;
}
function closeModalMaterial() {
    closeMaterialPicker();
}

function setKlas(k) {
    curMaterialKlas = k;
    document.querySelectorAll('#modalMaterial .klas-btn').forEach(b => b.classList.toggle('active', b.dataset.klas === k));
    const title = document.getElementById('materialTitle');
    if (title) title.innerHTML = '<i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Pilih Master Barang : ' + (klasLabel[k] || k);
    searchMaterials();
}
function setMaterialKlas(klas, btn) {
    setKlas(klas);
}

function queueMaterialSearch() {
    clearTimeout(materialTimer);
    materialTimer = setTimeout(searchMaterials, 250);
}

function searchMaterials() {
    const q = (document.getElementById('materialSearch')?.value || '').trim();
    const klas = curMaterialKlas || 'ALL';
    const body = document.getElementById('materialBody');
    if (!body) return;
    body.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Mencari data barang...</td></tr>';
    fetch(`purchase_requisition.php?action=search_materials&klas=${encodeURIComponent(klas)}&q=${encodeURIComponent(q)}${CURRENT_CMP_ID ? '&cmp_id=' + encodeURIComponent(CURRENT_CMP_ID) : ''}`)
        .then(r => r.json())
        .then(res => {
            materialCache = res.data || [];
            const chosen = document.getElementById('materialChosen')?.value || '';
            const found = materialCache.findIndex(m => m.kode_barang === chosen);
            pickerSelected = found >= 0 ? found : (materialCache.length ? 0 : -1);
            if (pickerSelected >= 0 && materialCache[pickerSelected]) {
                document.getElementById('materialChosen').value = materialCache[pickerSelected].kode_barang;
            }
            renderMaterials();
        })
        .catch(() => {
            body.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center; color:var(--danger);">Gagal membaca master barang.</td></tr>';
        });
}

function renderMaterials() {
    const body = document.getElementById('materialBody');
    if (!body) return;
    if (!materialCache.length) {
        body.innerHTML = '<tr><td colspan="6" class="muted" style="padding:12px; text-align:center;">Tidak ada barang yang cocok dengan kriteria pencarian ini.</td></tr>';
        return;
    }
    body.innerHTML = materialCache.map((m, i) => `
        <tr class="${i === pickerSelected ? 'sel' : ''}" onclick="pickMaterialRow(${i})" ondblclick="pickMaterialRow(${i}); confirmMaterial();" style="cursor:pointer;">
            <td><strong style="font-family:monospace; color:var(--primary);">${escapeHtml(m.kode_barang || '')}</strong></td>
            <td>${escapeHtml(m.uraian || '')}</td>
            <td>${escapeHtml(m.spesifikasi || '-')}</td>
            <td style="text-align:center;">${escapeHtml(m.sat || 'PCE')}</td>
            <td class="num" style="text-align:right;">${formatMoney(m.price, currentPrecision)}</td>
            <td>${escapeHtml(m.klas || '')}</td>
        </tr>`).join('');
}

function pickMaterialRow(i) {
    pickerSelected = i;
    const m = materialCache[i];
    if (m && document.getElementById('materialChosen')) {
        document.getElementById('materialChosen').value = m.kode_barang || '';
    }
    renderMaterials();
}

function confirmMaterial() {
    if (pickerSelected < 0 || !materialCache[pickerSelected] || !pickerRow) return;
    const m = materialCache[pickerSelected];
    const row = pickerRow;
    row.querySelector('.it-kode').value = m.kode_barang || '';
    row.querySelector('.it-nama').value = m.uraian || '';
    if (m.spesifikasi && (!row.querySelector('.it-spek').value || row.querySelector('.it-spek').value === '-')) {
        row.querySelector('.it-spek').value = m.spesifikasi;
    }
    row.querySelector('.it-sat').value = m.sat || 'PCE';
    const harga = parseFloat(m.price) || 0;
    if (harga > 0) {
        const prInp = row.querySelector('.it-price');
        prInp.value = formatMoney(harga, currentPrecision);
        prInp.dataset.raw = harga;
    }
    calcItemRow(row.querySelector('.it-qty'));
    calcTotals();
    closeMaterialPicker();
    const qtyInp = row.querySelector('.it-qty');
    if (qtyInp) {
        qtyInp.focus();
        qtyInp.select();
    }
    setStatus(`Barang ${m.kode_barang} (${m.uraian}) berhasil dipilih.`);
}

// Save PR
function savePr(onSuccess) {
    const items = [];
    document.querySelectorAll('#itemsContainer tr').forEach(tr => {
        const kd = (tr.querySelector('.it-kode')?.value || '').trim();
        const nm = (tr.querySelector('.it-nama')?.value || '').trim();
        const spek = (tr.querySelector('.it-spek')?.value || '').trim();
        const qtyInp = tr.querySelector('.it-qty');
        const priceInp = tr.querySelector('.it-price');
        const sat = (tr.querySelector('.it-sat')?.value || 'PCE').trim();
        const note = (tr.querySelector('.it-note')?.value || '').trim();

        if (kd || nm) {
            const qty = parseRawNumber(qtyInp ? (qtyInp.dataset.raw !== undefined ? qtyInp.dataset.raw : qtyInp.value) : 0);
            const price = parseRawNumber(priceInp ? (priceInp.dataset.raw !== undefined ? priceInp.dataset.raw : priceInp.value) : 0);
            items.push({
                kode_barang: kd,
                uraian: nm,
                spesifikasi: spek,
                qty: qty,
                sat: sat,
                est_price: price,
                est_amount: qty * price,
                note: note
            });
        }
    });

    if (items.length === 0) {
        alert('Harap masukkan minimal 1 baris barang!');
        return;
    }

    const payload = {
        id: document.getElementById('prId').value,
        pr_no: document.getElementById('prNo').value,
        pr_dt: document.getElementById('prDt').value,
        required_dt: document.getElementById('requiredDt').value,
        dept: document.getElementById('prDept').value,
        requester: document.getElementById('prRequester').value,
        priority: document.getElementById('prPriority').value,
        purpose: document.getElementById('prPurpose').value,
        status: document.getElementById('prStatus').value || '0',
        items: items
    };

    fetch('purchase_requisition.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(res => {
        if (res.status === 'OK') {
            document.getElementById('prId').value = res.id;
            document.getElementById('prNo').value = res.pr_no;
            setStatus(res.message);
            alert(res.message);
            if (typeof onSuccess === 'function') onSuccess();
        } else {
            alert('Gagal: ' + (res.message || 'Kesalahan sistem'));
        }
    });
}

function changeStatus(newStatus) {
    const id = document.getElementById('prId').value;
    if (!id) {
        alert('Simpan PR terlebih dahulu!');
        return;
    }
    const fd = new FormData();
    fd.append('id', id);
    fd.append('status', newStatus);

    fetch('purchase_requisition.php?action=update_status', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK') {
                document.getElementById('prStatus').value = newStatus;
                updateStatusHeader(newStatus, <?= json_encode($currentUser) ?>, new Date().toLocaleString());
                alert(res.message);
            } else {
                alert('Gagal: ' + res.message);
            }
        });
}

function promptReject() {
    const id = document.getElementById('prId').value;
    if (!id) return;
    const reason = prompt('Masukkan alasan penolakan PR:');
    if (!reason) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('status', '9');
    fd.append('rejected_note', reason);

    fetch('purchase_requisition.php?action=update_status', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK') {
                document.getElementById('prStatus').value = '9';
                updateStatusHeader('9', '', '', reason);
                alert(res.message);
            } else {
                alert('Gagal: ' + res.message);
            }
        });
}

function copyCurrentPr() {
    document.getElementById('prId').value = '';
    document.getElementById('prNo').value = '';
    document.getElementById('prDt').value = new Date().toISOString().split('T')[0];
    document.getElementById('prStatus').value = '0';
    generateAutoPrNo();
    updateStatusHeader('0');
    setStatus('Rincian disalin ke PR baru (Draft). Silakan simpan.');
}

function deleteCurrent() {
    const id = document.getElementById('prId').value;
    const prNo = document.getElementById('prNo').value || '(Draft)';
    if (!id) return;
    if (!confirm(`Apakah Anda yakin ingin menghapus PR "${prNo}"?`)) return;

    const fd = new FormData();
    fd.append('id', id);
    fetch('purchase_requisition.php?action=delete', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK') {
                alert(res.message);
                resetForm();
            } else {
                alert('Gagal: ' + res.message);
            }
        });
}

function printPr() {
    const id = document.getElementById('prId').value;
    if (!id) {
        alert('Simpan PR terlebih dahulu!');
        return;
    }
    window.open(`purchase_requisition.php?action=print&id=${encodeURIComponent(id)}`, '_blank');
}

function createPoFromCurrentPr() {
    const prNo = document.getElementById('prNo').value;
    if (!prNo) return;
    if (window.parent && typeof window.parent.openTab === 'function') {
        window.parent.openTab('pur_po', `purchase_order.php?pr_no=${encodeURIComponent(prNo)}`);
    } else {
        window.open(`purchase_order.php?pr_no=${encodeURIComponent(prNo)}`, '_blank');
    }
}

// Browse PR
function loadPrList(page = 1) {
    currentPage = page;
    const q = document.getElementById('browseSearch').value;
    const dept = document.getElementById('browseDept').value;
    const st = document.getElementById('browseStatus').value;
    const t1 = document.getElementById('browseTglAwal').value;
    const t2 = document.getElementById('browseTglAkhir').value;

    const tbody = document.getElementById('prTableBody');
    tbody.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data PR...</td></tr>';

    fetch(`purchase_requisition.php?action=list&page=${page}&limit=25&search=${encodeURIComponent(q)}&dept=${encodeURIComponent(dept)}&status=${encodeURIComponent(st)}&tgl_awal=${encodeURIComponent(t1)}&tgl_akhir=${encodeURIComponent(t2)}`)
        .then(res => res.json())
        .then(res => {
            if (res.status !== 'OK' || !res.data || res.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="muted" style="text-align:center; padding:20px;">Tidak ada data Permintaan Pembelian (PR).</td></tr>';
                document.getElementById('pageInfo').innerText = 'Menampilkan 0 data';
                return;
            }

            document.getElementById('pageInfo').innerText = `Menampilkan ${(res.page-1)*res.limit + 1} - ${Math.min(res.total, res.page*res.limit)} dari ${res.total} data`;
            document.getElementById('pageCurrent').innerText = res.page;

            tbody.innerHTML = res.data.map((r, idx) => {
                let stBadge = '<span class="ant-badge ant-badge-belum">Draft</span>';
                if (r.status === '1') stBadge = '<span class="ant-badge ant-badge-belum" style="background:#faad1420;">Submitted</span>';
                else if (r.status === '2') stBadge = '<span class="ant-badge ant-badge-lunas">Approved</span>';
                else if (r.status === '3') stBadge = '<span class="ant-badge ant-badge-company">In PO</span>';
                else if (r.status === '4') stBadge = '<span class="ant-badge ant-badge-lunas">Closed</span>';
                else if (r.status === '9') stBadge = '<span class="ant-badge ant-badge-lock">Ditolak</span>';

                let prioBadge = `<span class="muted">${escapeHtml(r.priority)}</span>`;
                if (r.priority === 'URGENT') prioBadge = '<span style="color:var(--danger); font-weight:bold;"><i class="fa-solid fa-fire"></i> URGENT</span>';

                const pct = r.pct || 0;
                const sisa = parseFloat(r.total_sisa_qty) || 0;
                const late = r.is_overdue ? '<span class="ant-badge ant-badge-lock" style="margin-left:4px;">LATE</span>' : '';

                return `
                    <tr onclick="onBrowseRowClick('${r.id}', '${escapeHtml(r.pr_no)}')">
                        <td style="text-align:center;">${(res.page-1)*res.limit + idx + 1}</td>
                        <td><strong style="color:var(--primary); font-family:monospace;">${escapeHtml(r.pr_no)}</strong></td>
                        <td>${escapeHtml(r.pr_dt || '-')}</td>
                        <td>${escapeHtml(r.required_dt || '-')} ${late}</td>
                        <td><strong>${escapeHtml(r.dept)}</strong></td>
                        <td>${escapeHtml(r.requester)}</td>
                        <td>${prioBadge}</td>
                        <td>${stBadge}</td>
                        <td>
                            <div class="progress-box">
                                <div class="progress-bar" style="width:${pct}%;"></div>
                                <div class="progress-text">${pct}% (Sisa: ${formatMoney(sisa, 0)})</div>
                            </div>
                        </td>
                        <td style="text-align:right;"><strong>${formatMoney(r.total_est_amount, 2)}</strong></td>
                        <td style="text-align:center;" class="col-action">
                            <button type="button" class="ant-btn ant-btn-sm" onclick="event.stopPropagation(); editFromBrowse('${r.id}')" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                            <a href="purchase_requisition.php?action=print&id=${r.id}" target="_blank" class="ant-btn ant-btn-sm" onclick="event.stopPropagation()" title="Cetak"><i class="fa-solid fa-print"></i></a>
                            ${r.status === '2' ? `<button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="event.stopPropagation(); createPoFromBrowse('${escapeHtml(r.pr_no)}')" title="Terbitkan PO"><i class="fa-solid fa-cart-plus"></i></button>` : ''}
                        </td>
                    </tr>
                `;
            }).join('');
        });
}

function onBrowseRowClick(id, prNo) {
    selectedBrowseId = id;
    document.querySelectorAll('#prTableBody tr').forEach(r => r.classList.remove('sel'));
    event.currentTarget.classList.add('sel');

    // Show Detail Strip
    const strip = document.getElementById('prDetailStrip');
    document.getElementById('stripDocNo').innerText = prNo;
    const sBody = document.getElementById('stripItemsBody');
    sBody.innerHTML = '<tr><td colspan="10" class="muted" style="text-align:center;">Memuat detail...</td></tr>';
    strip.classList.add('show');

    fetch(`purchase_requisition.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(res => {
            if (res.status === 'OK' && res.details && res.details.length > 0) {
                sBody.innerHTML = res.details.map((d, idx) => `
                    <tr>
                        <td style="text-align:center;">${idx + 1}</td>
                        <td><strong style="font-family:monospace; color:var(--primary);">${escapeHtml(d.kode_barang)}</strong></td>
                        <td>${escapeHtml(d.uraian)}</td>
                        <td>${escapeHtml(d.spesifikasi || '-')}</td>
                        <td style="text-align:right; font-weight:bold;">${formatMoney(d.qty, 2)}</td>
                        <td style="text-align:right;">${formatMoney(d.qty_po, 2)}</td>
                        <td style="text-align:right; color:var(--danger); font-weight:bold;">${formatMoney(d.sisa_qty, 2)}</td>
                        <td>${escapeHtml(d.sat)}</td>
                        <td style="text-align:right;">${formatMoney(d.est_price, 2)}</td>
                        <td style="text-align:right; font-weight:bold;">${formatMoney(d.est_amount, 2)}</td>
                    </tr>
                `).join('');
            } else {
                sBody.innerHTML = '<tr><td colspan="10" class="muted" style="text-align:center;">Tidak ada rincian item.</td></tr>';
            }
        });
}

function closeDetailStrip() {
    document.getElementById('prDetailStrip').classList.remove('show');
}

function editFromBrowse(id) {
    fetch(`purchase_requisition.php?action=get_detail&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(res => {
            if (res.status !== 'OK') {
                alert(res.message);
                return;
            }
            const h = res.header;
            document.getElementById('prId').value = h.id;
            document.getElementById('prNo').value = h.pr_no;
            document.getElementById('prDt').value = h.pr_dt || '';
            document.getElementById('requiredDt').value = h.required_dt || '';
            document.getElementById('prDept').value = h.dept || 'PRODUKSI';
            document.getElementById('prRequester').value = h.requester || '';
            document.getElementById('prPriority').value = h.priority || 'NORMAL';
            document.getElementById('prPurpose').value = h.purpose || '';
            document.getElementById('prStatus').value = h.status || '0';

            const tbody = document.getElementById('itemsContainer');
            tbody.innerHTML = '';
            if (res.details && res.details.length > 0) {
                res.details.forEach(it => addItemRow(it));
            } else {
                addItemRow();
            }
            updateStatusHeader(h.status, h.approved_by, h.approved_dt, h.rejected_note);
            document.querySelector('.ant-tab-btn[data-tab="entri"]').click();
            setStatus(`PR ${h.pr_no} dimuat ke form Entri.`);
        });
}

function createPoFromBrowse(prNo) {
    if (window.parent && typeof window.parent.openTab === 'function') {
        window.parent.openTab('pur_po', `purchase_order.php?pr_no=${encodeURIComponent(prNo)}`);
    } else {
        window.open(`purchase_order.php?pr_no=${encodeURIComponent(prNo)}`, '_blank');
    }
}

function prevPage() {
    if (currentPage > 1) loadPrList(currentPage - 1);
}
function nextPage() {
    loadPrList(currentPage + 1);
}
function onBrowseFilterChange() {
    loadPrList(1);
}
function resetBrowseFilter() {
    document.getElementById('browseSearch').value = '';
    document.getElementById('browseDept').value = '';
    document.getElementById('browseStatus').value = '';
    document.getElementById('browseTglAwal').value = '';
    document.getElementById('browseTglAkhir').value = '';
    loadPrList(1);
}

// Trace PR
function loadPrTrace() {
    const prNo = document.getElementById('tracePrInput').value.trim();
    if (!prNo) return;
    const poBody = document.getElementById('tracePoBody');
    const riBody = document.getElementById('traceRiBody');
    poBody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Melacak PO...</td></tr>';
    riBody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:15px;"><i class="fa-solid fa-spinner fa-spin"></i> Melacak RI...</td></tr>';

    fetch(`purchase_requisition.php?action=trace&id=${encodeURIComponent(prNo)}`)
        .then(res => res.json())
        .then(res => {
            if (res.status !== 'OK') {
                // Coba search by pr_no jika bukan id
                poBody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:15px;">PR tidak ditemukan atau belum ada transaksi lanjutan.</td></tr>';
                riBody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:15px;">-</td></tr>';
                return;
            }

            // Render PO
            if (res.po && res.po.length > 0) {
                poBody.innerHTML = res.po.map(p => `
                    <tr>
                        <td><strong style="color:var(--primary); font-family:monospace;">${escapeHtml(p.po_no)}</strong></td>
                        <td>${escapeHtml(p.po_dt)}</td>
                        <td>${escapeHtml(p.vendor_nama || p.vendor)}</td>
                        <td style="text-align:center;">${escapeHtml(p.kurs)}</td>
                        <td style="text-align:right; font-weight:bold;">${formatMoney(p.total_order, 2)}</td>
                        <td style="text-align:center;"><span class="ant-badge ant-badge-lunas">${p.status === '1' ? 'Approved' : (p.status === '2' ? 'Closed' : 'Draft')}</span></td>
                    </tr>
                `).join('');
            } else {
                poBody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:15px;">Belum ada Purchase Order (PO) yang diterbitkan dari PR ini.</td></tr>';
            }

            // Render RI
            if (res.ri && res.ri.length > 0) {
                riBody.innerHTML = res.ri.map(r => `
                    <tr>
                        <td><strong style="color:var(--success); font-family:monospace;">${escapeHtml(r.ri_no)}</strong></td>
                        <td>${escapeHtml(r.ri_dt)}</td>
                        <td><span style="font-family:monospace;">${escapeHtml(r.po_no)}</span></td>
                        <td>${escapeHtml(r.bc ? ('BC ' + r.bc) : 'Non-BC')}</td>
                        <td>${escapeHtml(r.gudang || 'UTAMA')}</td>
                        <td style="text-align:right; font-weight:bold;">${formatMoney(r.total_order, 2)}</td>
                    </tr>
                `).join('');
            } else {
                riBody.innerHTML = '<tr><td colspan="6" class="muted" style="text-align:center; padding:15px;">Belum ada Penerimaan Barang (RI) untuk pesanan terkait.</td></tr>';
            }
        });
}

function setStatus(msg) {
    document.getElementById('statusMessage').innerText = msg;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Resizable Table Columns Helper
function setupGridColumnResize(tableSelector, tableKey) {
    const table = document.querySelector(tableSelector);
    if (!table) return;
    const ths = table.querySelectorAll('thead tr th');
    ths.forEach(th => {
        if (th.classList.contains('col-action')) return;
        const resizer = document.createElement('div');
        resizer.className = 'col-resizer';
        th.appendChild(resizer);
        resizer.addEventListener('pointerdown', e => {
            e.preventDefault();
            const startX = e.clientX;
            const startW = th.getBoundingClientRect().width;
            document.body.classList.add('grid-resizing');
            const onMove = ev => {
                const nw = Math.max(30, startW + (ev.clientX - startX));
                th.style.width = nw + 'px';
            };
            const onUp = () => {
                document.body.classList.remove('grid-resizing');
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
        });
    });
}
</script>
</body>
</html>
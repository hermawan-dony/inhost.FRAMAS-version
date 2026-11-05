<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Permintaan Barang (Material Request / BPPB)
 * ============================================================================
 * Porting Lengkap dari PowerBuilder TPB-ERP:
 * - w_issue_dept_req.srw (Issue Out Request / Permintaan Pengeluaran Barang Antar Dept)
 * - bc_issue_dept_req_hdr.srd (Header Permintaan Barang)
 * - bc_issue_dept_req_dtl.srd (Rincian Item Permintaan Barang)
 * - d_issue_bppb.srd (Bukti Permintaan dan Pengeluaran Barang)
 * Mengadopsi 100% Ant Design Compact Desktop 9.5px PowerBuilder-Style
 * ============================================================================
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

// -------------------------------------------------------------------------
// SESSION & MULTI-TENANT CONTEXT
// -------------------------------------------------------------------------
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? '');
if (!empty($_GET['cmp_id'])) {
    $sessionCmpId = trim($_GET['cmp_id']);
    $_SESSION['company_cmp_id'] = $sessionCmpId;
}
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$currentUser = $_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');
$currentRole = $_SESSION['user_role'] ?? 'operator';

// Ambil Profil Perusahaan & Logo
$activeCmpName = $_SESSION['company_name'] ?? 'PT. SN UNGGUL';
$companyLogo = '/assets/logo.png';
$qCmp = $koneksi->query("SELECT cmp_name, logo, npwp FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
if ($qCmp && $rC = $qCmp->fetch_assoc()) {
    if (!empty($rC['cmp_name'])) $activeCmpName = $rC['cmp_name'];
    if (!empty($rC['logo'])) $companyLogo = $rC['logo'];
}

// Ambil Konfigurasi Approval Perusahaan
$mrApprovalMode = '1'; // Default: 1 Level
$mrApproverRoles = 'admin,superadmin,warehouse,production,supervisor';
$qSet = $koneksi->query("SELECT setting_key, setting_value FROM bc_ceisa_setting WHERE cmp_id = '{$escCmp}' AND setting_key IN ('mr_approval_mode', 'mr_approver_roles')");
if ($qSet) {
    while ($rs = $qSet->fetch_assoc()) {
        if ($rs['setting_key'] === 'mr_approval_mode') $mrApprovalMode = $rs['setting_value'];
        if ($rs['setting_key'] === 'mr_approver_roles') $mrApproverRoles = $rs['setting_value'];
    }
}
$allowedApproverRoles = array_map('trim', explode(',', strtolower($mrApproverRoles)));
$canApprove = $isAdmin || in_array(strtolower($currentRole), $allowedApproverRoles);

// -------------------------------------------------------------------------
// BACKEND AJAX API
// -------------------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $status = strtoupper(trim($_GET['status'] ?? ''));
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = [];
    if (!empty($sessionCmpId)) {
        $where[] = "cmp_id = '{$escCmp}'";
    }
    if (!empty($status)) {
        $stEsc = $koneksi->real_escape_string($status);
        $where[] = "status = '{$stEsc}'";
    }
    if (!empty($search)) {
        $sc = $koneksi->real_escape_string($search);
        $where[] = "(req_no LIKE '%{$sc}%' OR dept_pemohon LIKE '%{$sc}%' OR wo_no LIKE '%{$sc}%' OR pic LIKE '%{$sc}%' OR note LIKE '%{$sc}%')";
    }

    $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $countSql = "SELECT COUNT(*) as cnt,
                 SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as cnt_pending,
                 SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) as cnt_approved,
                 SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as cnt_completed
                 FROM bc_issue_dept_req_hdr {$whereSql}";
    $cRes = $koneksi->query($countSql);
    $cRow = $cRes ? $cRes->fetch_assoc() : ['cnt' => 0, 'cnt_pending' => 0, 'cnt_approved' => 0, 'cnt_completed' => 0];

    $sql = "SELECT h.*, 
            (SELECT COUNT(*) FROM bc_issue_dept_req_dtl d WHERE d.id_hdr = h.id) as item_count,
            (SELECT SUM(qty_req) FROM bc_issue_dept_req_dtl d WHERE d.id_hdr = h.id) as total_qty
            FROM bc_issue_dept_req_hdr h
            {$whereSql}
            ORDER BY h.req_date DESC, h.id DESC
            LIMIT {$offset}, {$limit}";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'total' => (int)($cRow['cnt'] ?? 0),
        'pending' => (int)($cRow['cnt_pending'] ?? 0),
        'approved' => (int)($cRow['cnt_approved'] ?? 0),
        'completed' => (int)($cRow['cnt_completed'] ?? 0),
        'data' => $rows
    ]);
    exit;
}

if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $qH = $koneksi->query("SELECT * FROM bc_issue_dept_req_hdr WHERE id = '{$escId}' AND cmp_id = '{$escCmp}' LIMIT 1");
    if (!$qH || $qH->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data permintaan barang tidak ditemukan.']);
        exit;
    }
    $header = $qH->fetch_assoc();

    $qD = $koneksi->query("SELECT * FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    $items = [];
    if ($qD) {
        while ($r = $qD->fetch_assoc()) {
            $items[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'header' => $header, 'items' => $items]);
    exit;
}

if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $post = json_decode($raw, true) ?: $_POST;

    $id = trim($post['id'] ?? '');
    $reqDate = trim($post['req_date'] ?? date('Y-m-d'));
    $needDate = !empty($post['need_date']) ? trim($post['need_date']) : null;
    $deptPemohon = trim($post['dept_pemohon'] ?? 'PRODUKSI');
    $deptTujuan = trim($post['dept_tujuan'] ?? 'GUDANG UTAMA');
    $woNo = trim($post['wo_no'] ?? '');
    $pic = trim($post['pic'] ?? $currentUser);
    $klas = trim($post['klas'] ?? 'Raw Materials');
    $note = trim($post['note'] ?? '');
    $submitNow = !empty($post['submit_now']);
    $items = $post['items'] ?? [];

    if (empty($items) || !is_array($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Rincian barang permintaan wajib diisi minimal 1 item.']);
        exit;
    }

    $isNew = empty($id);
    if ($isNew) {
        $ym = date('Ym', strtotime($reqDate));
        $prefix = "MR-{$ym}-";
        $qNo = $koneksi->query("SELECT req_no FROM bc_issue_dept_req_hdr WHERE cmp_id = '{$escCmp}' AND req_no LIKE '{$prefix}%' ORDER BY req_no DESC LIMIT 1");
        $nextNum = 1;
        if ($qNo && $rNo = $qNo->fetch_assoc()) {
            $nextNum = intval(substr($rNo['req_no'], strlen($prefix))) + 1;
        }
        $reqNo = $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
        $id = bin2hex(random_bytes(16));
    } else {
        $qOld = $koneksi->query("SELECT req_no, status FROM bc_issue_dept_req_hdr WHERE id = '" . $koneksi->real_escape_string($id) . "' LIMIT 1");
        $oldRow = $qOld ? $qOld->fetch_assoc() : null;
        $reqNo = $oldRow['req_no'] ?? '';
        if ($oldRow && in_array($oldRow['status'], ['APPROVED', 'COMPLETED', 'PARTIAL'])) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Dokumen permintaan yang sudah disetujui / diproses tidak dapat diubah lagi.']);
            exit;
        }
    }

    // Status: Jika mrApprovalMode = '0' (Non-aktif), langsung APPROVED!
    $initStatus = 'DRAFT';
    if ($submitNow) {
        $initStatus = ($mrApprovalMode === '0') ? 'APPROVED' : 'PENDING';
    }

    $escId = $koneksi->real_escape_string($id);
    $escReqNo = $koneksi->real_escape_string($reqNo);
    $escDeptFr = $koneksi->real_escape_string($deptPemohon);
    $escDeptTo = $koneksi->real_escape_string($deptTujuan);
    $escWo = $koneksi->real_escape_string($woNo);
    $escPic = $koneksi->real_escape_string($pic);
    $escKlas = $koneksi->real_escape_string($klas);
    $escNote = $koneksi->real_escape_string($note);
    $escUser = $koneksi->real_escape_string($currentUser);
    $needDtSql = $needDate ? "'{$needDate}'" : "NULL";

    $koneksi->begin_transaction();
    try {
        if ($isNew) {
            $sqlHdr = "INSERT INTO bc_issue_dept_req_hdr 
                (id, cmp_id, req_no, req_date, need_date, dept_pemohon, dept_tujuan, wo_no, pic, klas, note, status, created_by)
                VALUES 
                ('{$escId}', '{$escCmp}', '{$escReqNo}', '{$reqDate}', {$needDtSql}, '{$escDeptFr}', '{$escDeptTo}', '{$escWo}', '{$escPic}', '{$escKlas}', '{$escNote}', '{$initStatus}', '{$escUser}')";
            if (!$koneksi->query($sqlHdr)) throw new Exception('Gagal menyimpan header permintaan: ' . $koneksi->error);
        } else {
            $sqlHdr = "UPDATE bc_issue_dept_req_hdr SET 
                req_date = '{$reqDate}', need_date = {$needDtSql}, dept_pemohon = '{$escDeptFr}', dept_tujuan = '{$escDeptTo}',
                wo_no = '{$escWo}', pic = '{$escPic}', klas = '{$escKlas}', note = '{$escNote}', status = '{$initStatus}'
                WHERE id = '{$escId}' AND cmp_id = '{$escCmp}'";
            if (!$koneksi->query($sqlHdr)) throw new Exception('Gagal memperbarui header permintaan: ' . $koneksi->error);
            $koneksi->query("DELETE FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$escId}'");
        }

        $seq = 1;
        $stmtDtl = $koneksi->prepare("INSERT INTO bc_issue_dept_req_dtl 
            (id, id_hdr, cmp_id, req_no, seq, kode_barang, nama_barang, sat, qty_req, qty_appr, qty_issued, note, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");

        foreach ($items as $it) {
            $dtlId = bin2hex(random_bytes(16));
            $kd = trim($it['kode_barang'] ?? '');
            $nm = trim($it['nama_barang'] ?? '');
            $sat = trim($it['sat'] ?? 'PCE');
            $qtyReq = (float)($it['qty_req'] ?? 0);
            $qtyAppr = ($initStatus === 'APPROVED') ? $qtyReq : 0;
            $dNote = trim($it['note'] ?? '');
            $dStatus = $initStatus;

            if (empty($kd) || $qtyReq <= 0) continue;

            $stmtDtl->bind_param("ssssisssddss",
                $dtlId, $id, $sessionCmpId, $reqNo, $seq,
                $kd, $nm, $sat, $qtyReq, $qtyAppr, $dNote, $dStatus
            );
            $stmtDtl->execute();
            $seq++;
        }
        $stmtDtl->close();

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK',
            'id' => $id,
            'req_no' => $reqNo,
            'message' => "Permintaan barang '$reqNo' berhasil disimpan (" . ($initStatus === 'PENDING' ? 'Menunggu Approval' : ($initStatus === 'APPROVED' ? 'Disetujui Otomatis' : 'Draft')) . ")."
        ]);
    } catch (\Throwable $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'approval_action') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canApprove) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Anda tidak memiliki hak otorisasi untuk menyetujui / menolak permintaan barang.']);
        exit;
    }

    $id = trim($_POST['id'] ?? '');
    $type = strtoupper(trim($_POST['type'] ?? '')); // 'APPROVE' atau 'REJECT'
    $reason = trim($_POST['reason'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $escUser = $koneksi->real_escape_string($currentUser);
    $escReason = $koneksi->real_escape_string($reason);

    $qH = $koneksi->query("SELECT * FROM bc_issue_dept_req_hdr WHERE id = '{$escId}' AND cmp_id = '{$escCmp}' LIMIT 1");
    if (!$qH || $qH->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data permintaan tidak ditemukan.']);
        exit;
    }
    $h = $qH->fetch_assoc();

    if ($type === 'REJECT') {
        if (empty($reason)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Alasan penolakan wajib diisi.']);
            exit;
        }
        $sql = "UPDATE bc_issue_dept_req_hdr SET 
            status = 'REJECTED', rejected_by = '{$escUser}', rejected_at = NOW(), reject_reason = '{$escReason}'
            WHERE id = '{$escId}' AND cmp_id = '{$escCmp}'";
        $koneksi->query($sql);
        $koneksi->query("UPDATE bc_issue_dept_req_dtl SET status = 'REJECTED' WHERE id_hdr = '{$escId}'");
        echo json_encode(['status' => 'OK', 'message' => 'Permintaan barang berhasil ditolak.']);
        exit;
    } elseif ($type === 'APPROVE') {
        $sql = "UPDATE bc_issue_dept_req_hdr SET 
            status = 'APPROVED', approved_by_1 = '{$escUser}', approved_at_1 = NOW()
            WHERE id = '{$escId}' AND cmp_id = '{$escCmp}'";
        $koneksi->query($sql);
        // Set qty_appr = qty_req jika belum diatur
        $koneksi->query("UPDATE bc_issue_dept_req_dtl SET status = 'APPROVED', qty_appr = qty_req WHERE id_hdr = '{$escId}'");
        echo json_encode(['status' => 'OK', 'message' => 'Permintaan barang berhasil disetujui (Approved) & siap dikeluarkan di gudang.']);
        exit;
    }
}

if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $q = $koneksi->query("SELECT status, req_no FROM bc_issue_dept_req_hdr WHERE id = '{$escId}' AND cmp_id = '{$escCmp}' LIMIT 1");
    if (!$q || $r = $q->fetch_assoc()) {
        if (in_array($r['status'], ['APPROVED', 'COMPLETED', 'PARTIAL'])) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Dokumen permintaan yang sudah disetujui / diproses tidak boleh dihapus.']);
            exit;
        }
    }
    $koneksi->query("DELETE FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$escId}'");
    $koneksi->query("DELETE FROM bc_issue_dept_req_hdr WHERE id = '{$escId}' AND cmp_id = '{$escCmp}'");
    echo json_encode(['status' => 'OK', 'message' => 'Dokumen permintaan berhasil dihapus.']);
    exit;
}

if ($action === 'save_setting') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$isAdmin) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Hanya Administrator yang berwenang mengubah konfigurasi approval.']);
        exit;
    }
    $mode = trim($_POST['mode'] ?? '1');
    $roles = trim($_POST['roles'] ?? 'admin,warehouse,supervisor');

    $escMode = $koneksi->real_escape_string($mode);
    $escRoles = $koneksi->real_escape_string($roles);

    $koneksi->query("INSERT INTO bc_ceisa_setting (cmp_id, setting_key, setting_value) VALUES ('{$escCmp}', 'mr_approval_mode', '{$escMode}') ON DUPLICATE KEY UPDATE setting_value = '{$escMode}'");
    $koneksi->query("INSERT INTO bc_ceisa_setting (cmp_id, setting_key, setting_value) VALUES ('{$escCmp}', 'mr_approver_roles', '{$escRoles}') ON DUPLICATE KEY UPDATE setting_value = '{$escRoles}'");
    echo json_encode(['status' => 'OK', 'message' => 'Pengaturan approval berhasil disimpan.']);
    exit;
}

// -------------------------------------------------------------------------
// PRINT BPPB VIEW (Bukti Permintaan dan Pengeluaran Barang)
// -------------------------------------------------------------------------
if ($action === 'print_bppb') {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $qH = $koneksi->query("SELECT * FROM bc_issue_dept_req_hdr WHERE id = '{$escId}' AND cmp_id = '{$escCmp}' LIMIT 1");
    if (!$qH || $qH->num_rows === 0) die("Dokumen tidak ditemukan.");
    $hdr = $qH->fetch_assoc();
    $qD = $koneksi->query("SELECT * FROM bc_issue_dept_req_dtl WHERE id_hdr = '{$escId}' ORDER BY seq ASC");
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>BPPB - <?= htmlspecialchars($hdr['req_no']) ?></title>
        <style>
            body { font-family: 'Poppins', Arial, sans-serif; font-size: 10pt; margin: 15mm; color: #000; }
            .header-table { width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
            .logo { height: 45px; object-fit: contain; }
            .doc-title { font-size: 14pt; font-weight: 800; text-align: center; margin: 10px 0 4px 0; text-transform: uppercase; }
            .doc-sub { font-size: 9pt; text-align: center; margin-bottom: 15px; color: #444; }
            .meta-table { width: 100%; font-size: 9pt; margin-bottom: 12px; border-collapse: collapse; }
            .meta-table td { padding: 3px 6px; }
            .items-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 20px; }
            .items-table th, .items-table td { border: 1px solid #000; padding: 4px 6px; }
            .items-table th { background: #f0f0f0; text-align: center; }
            .sign-table { width: 100%; text-align: center; font-size: 9pt; margin-top: 30px; page-break-inside: avoid; }
            .sign-table td { width: 33.3%; vertical-align: top; }
            .sign-space { height: 60px; }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px;">
            <button onclick="window.print()" style="padding: 6px 14px; font-weight: 700; background: #1890ff; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Cetak BPPB (Ctrl + P)</button>
        </div>
        <table class="header-table">
            <tr>
                <td style="width: 70px;"><img src="<?= htmlspecialchars($companyLogo) ?>" class="logo" alt="Logo" onerror="this.src='/assets/logo.png'"></td>
                <td>
                    <div style="font-size: 13pt; font-weight: 800;"><?= htmlspecialchars($activeCmpName) ?></div>
                    <div style="font-size: 8.5pt; color: #444;">Kawasan Berikat CEISA 4.0 - IT Inventory Terintegrasi</div>
                </td>
                <td style="text-align: right; vertical-align: bottom;">
                    <div style="font-size: 8.5pt; font-family: monospace;">Form: BPPB-WHS-01</div>
                </td>
            </tr>
        </table>

        <div class="doc-title">BUKTI PERMINTAAN &amp; PENGELUARAN BARANG (BPPB)</div>
        <div class="doc-sub">Nomor Permintaan: <strong><?= htmlspecialchars($hdr['req_no']) ?></strong> | Status: <strong><?= htmlspecialchars($hdr['status']) ?></strong></div>

        <table class="meta-table">
            <tr>
                <td style="width: 18%; font-weight: 600;">Tanggal Request</td>
                <td style="width: 32%;">: <?= htmlspecialchars($hdr['req_date']) ?></td>
                <td style="width: 18%; font-weight: 600;">Departemen Pemohon</td>
                <td style="width: 32%;">: <?= htmlspecialchars($hdr['dept_pemohon']) ?></td>
            </tr>
            <tr>
                <td style="font-weight: 600;">Tanggal Dibutuhkan</td>
                <td>: <?= htmlspecialchars($hdr['need_date'] ?: '-') ?></td>
                <td style="font-weight: 600;">Departemen Tujuan</td>
                <td>: <?= htmlspecialchars($hdr['dept_tujuan']) ?></td>
            </tr>
            <tr>
                <td style="font-weight: 600;">No. Work Order (WO)</td>
                <td>: <?= htmlspecialchars($hdr['wo_no'] ?: '-') ?></td>
                <td style="font-weight: 600;">PIC Pemohon</td>
                <td>: <?= htmlspecialchars($hdr['pic'] ?: '-') ?></td>
            </tr>
            <tr>
                <td style="font-weight: 600;">Catatan / Keperluan</td>
                <td colspan="3">: <?= htmlspecialchars($hdr['note'] ?: '-') ?></td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 30px;">No</th>
                    <th style="width: 120px;">Kode Barang</th>
                    <th>Nama &amp; Spesifikasi Barang</th>
                    <th style="width: 50px;">Satuan</th>
                    <th style="width: 80px; text-align: right;">Qty Diminta</th>
                    <th style="width: 80px; text-align: right;">Qty Disetujui</th>
                    <th style="width: 80px; text-align: right;">Qty Dikeluarkan</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                if ($qD) while ($d = $qD->fetch_assoc()):
                ?>
                <tr>
                    <td style="text-align: center;"><?= $no++ ?></td>
                    <td style="font-family: monospace; font-weight: 700;"><?= htmlspecialchars($d['kode_barang']) ?></td>
                    <td><?= htmlspecialchars($d['nama_barang']) ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($d['sat']) ?></td>
                    <td style="text-align: right; font-weight: 600;"><?= number_format($d['qty_req'], 2, ',', '.') ?></td>
                    <td style="text-align: right; font-weight: 600; color: #0284c7;"><?= number_format($d['qty_appr'], 2, ',', '.') ?></td>
                    <td style="text-align: right; font-weight: 600; color: #16a34a;"><?= number_format($d['qty_issued'], 2, ',', '.') ?></td>
                    <td><?= htmlspecialchars($d['note'] ?: '-') ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <table class="sign-table">
            <tr>
                <td>
                    <div>Yang Meminta,</div>
                    <div class="sign-space"></div>
                    <div>( <strong><?= htmlspecialchars($hdr['pic'] ?: 'Pemohon') ?></strong> )</div>
                    <div style="font-size: 8pt; color: #555;">Dept: <?= htmlspecialchars($hdr['dept_pemohon']) ?></div>
                </td>
                <td>
                    <div>Mengetahui / Disetujui,</div>
                    <div class="sign-space"></div>
                    <div>( <strong><?= htmlspecialchars($hdr['approved_by_1'] ?: 'Kepala Bagian') ?></strong> )</div>
                    <div style="font-size: 8pt; color: #555;"><?= !empty($hdr['approved_at_1']) ? 'Tgl: ' . $hdr['approved_at_1'] : 'Supervisor / Manager' ?></div>
                </td>
                <td>
                    <div>Yang Menyerahkan,</div>
                    <div class="sign-space"></div>
                    <div>( <strong>Petugas Gudang</strong> )</div>
                    <div style="font-size: 8pt; color: #555;">Warehouse Staff</div>
                </td>
            </tr>
        </table>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permintaan Barang ke Gudang (Material Request / BPPB)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="/assets/ant_grid.js"></script>
    <script src="/assets/sweetalert2.all.min.js"></script>
    <style>
        :root {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --bg-page: #f0f2f5;
            --bg-card: #ffffff;
            --border-color: #d9d9d9;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --table-head-bg: #fafafa;
        }
        [data-theme="dark"] {
            --primary: #177ddc;
            --bg-page: #141414;
            --bg-card: #1f1f1f;
            --border-color: #303030;
            --text-main: #d9d9d9;
            --text-muted: #737373;
            --table-head-bg: #1d1d1d;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 9.5px;
            background: var(--bg-page);
            color: var(--text-main);
            margin: 0;
            padding: 8px 12px;
            line-height: 1.4;
        }
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            background: var(--bg-card);
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }
        .page-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tab-nav {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 8px;
            background: var(--bg-card);
            padding: 4px 8px 0 8px;
            border-radius: 6px 6px 0 0;
        }
        .tab-btn {
            padding: 5px 12px;
            font-size: 10px;
            font-weight: 600;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            background: transparent;
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .tab-btn.active {
            background: var(--bg-page);
            color: var(--primary);
            border-color: var(--border-color);
            border-bottom: 2px solid var(--primary);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 8px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-label { font-size: 9px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; }
        .stat-val { font-size: 14px; font-weight: 700; color: var(--text-main); }

        .toolbar-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .form-control-compact {
            height: 22px;
            font-size: 9.5px;
            padding: 0 6px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .btn-compact {
            height: 22px;
            padding: 0 10px;
            font-size: 9.5px;
            font-weight: 600;
            border-radius: 4px;
            border: 1px solid transparent;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-default { background: var(--bg-card); border-color: var(--border-color); color: var(--text-main); }

        .table-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }
        table.mr-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }
        table.mr-table th {
            background: var(--table-head-bg);
            padding: 4px 6px;
            border: 1px solid var(--border-color);
            font-weight: 600;
            text-align: left;
            white-space: nowrap;
        }
        table.mr-table td {
            padding: 3px 6px;
            border: 1px solid var(--border-color);
            white-space: nowrap;
        }
        table.mr-table tr:hover { background: rgba(24, 144, 255, 0.05); }

        .badge-status {
            display: inline-block;
            padding: 1px 6px;
            font-size: 8.5px;
            font-weight: 700;
            border-radius: 3px;
        }
        .badge-draft { background: #f5f5f5; color: #595959; border: 1px solid #d9d9d9; }
        .badge-pending { background: #fffbe6; color: #d46b08; border: 1px solid #ffe58f; }
        .badge-approved { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; }
        .badge-rejected { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }
        .badge-completed { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px 12px;
            background: var(--bg-card);
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 8px;
        }
        .form-row { display: flex; flex-direction: column; gap: 3px; }
        .form-row label { font-size: 9px; font-weight: 600; color: var(--text-muted); }
    </style>
</head>
<body>

    <!-- 1. PAGE HEADER -->
    <div class="page-header">
        <div class="page-title">
            <i class="fa-solid fa-clipboard-check" style="font-size:16px;"></i>
            <span>Permintaan Barang ke Gudang (Material Request / BPPB)</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px;">
            <button type="button" class="btn-compact btn-default" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP">
                <i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan SOP
            </button>
            <button type="button" class="btn-compact btn-primary" onclick="loadMrData()">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>
    </div>

    <!-- 2. TABS NAV -->
    <div class="tab-nav">
        <button type="button" class="tab-btn active" id="tabBtn1" onclick="switchTab(1)">
            <i class="fa-solid fa-list-ul"></i> 1. Monitoring &amp; Approval Permintaan
        </button>
        <button type="button" class="tab-btn" id="tabBtn2" onclick="switchTab(2)">
            <i class="fa-solid fa-plus"></i> 2. + Form Entri Permintaan Baru
        </button>
        <?php if ($isAdmin): ?>
        <button type="button" class="tab-btn" id="tabBtn3" onclick="switchTab(3)">
            <i class="fa-solid fa-sliders"></i> 3. Konfigurasi Approval Perusahaan
        </button>
        <?php endif; ?>
    </div>

    <!-- TAB 1: DAFTAR PERMINTAAN & MONITORING -->
    <div class="tab-content active" id="tabContent1">
        <div class="stats-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-label">Total Permintaan</div>
                    <div class="stat-val" id="statTotal">0</div>
                </div>
                <i class="fa-solid fa-clipboard-list" style="font-size:18px; color:#0ea5e9;"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Menunggu Approval</div>
                    <div class="stat-val" id="statPending" style="color:#d46b08;">0</div>
                </div>
                <i class="fa-solid fa-hourglass-half" style="font-size:18px; color:#f59e0b;"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Disetujui (Ready Issue)</div>
                    <div class="stat-val" id="statApproved" style="color:#096dd9;">0</div>
                </div>
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#10b981;"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Selesai (Issued)</div>
                    <div class="stat-val" id="statCompleted" style="color:#389e0d;">0</div>
                </div>
                <i class="fa-solid fa-boxes-packing" style="font-size:18px; color:#8b5cf6;"></i>
            </div>
        </div>

        <div class="toolbar-card">
            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                <input type="text" id="searchInput" class="form-control-compact" style="width:230px;" placeholder="Cari No MR / WO / Pemohon / Note..." onkeydown="if(event.key==='Enter') loadMrData()">
                <select id="filterStatus" class="form-control-compact" onchange="loadMrData()">
                    <option value="">-- Semua Status --</option>
                    <option value="PENDING" selected>Menunggu Approval</option>
                    <option value="APPROVED">Disetujui (Approved)</option>
                    <option value="REJECTED">Ditolak (Rejected)</option>
                    <option value="COMPLETED">Selesai Dikeluarkan</option>
                    <option value="DRAFT">Draft</option>
                </select>
                <button type="button" class="btn-compact btn-primary" onclick="loadMrData()">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
            </div>
            <div>
                <button type="button" class="btn-compact btn-success" onclick="newRequestForm()">
                    <i class="fa-solid fa-plus"></i> Buat Permintaan Baru
                </button>
            </div>
        </div>

        <div class="table-container">
            <table class="mr-table ant-grid-table" id="mrTable">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">No</th>
                        <th>No. Permintaan (BPPB)</th>
                        <th>Tgl Request</th>
                        <th>Tgl Butuh</th>
                        <th>Dept Pemohon</th>
                        <th>Dept Tujuan</th>
                        <th>No. WO</th>
                        <th>PIC Pemohon</th>
                        <th style="text-align:center;">Jml Item</th>
                        <th style="text-align:right;">Total Qty</th>
                        <th style="text-align:center;">Status</th>
                        <th>Jejak Otorisasi / Alasan</th>
                        <th style="width:140px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="mrTbody">
                    <tr><td colspan="13" style="text-align:center; padding:15px; color:var(--text-muted);">Memuat data permintaan barang...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 2: FORM ENTRI REQUEST -->
    <div class="tab-content" id="tabContent2">
        <input type="hidden" id="formId" value="">
        <div class="form-grid">
            <div class="form-row">
                <label>Nomor Permintaan (Auto):</label>
                <input type="text" id="formReqNo" class="form-control-compact" style="font-weight:700; background:#f5f5f5;" readonly placeholder="[Otomatis saat simpan]">
            </div>
            <div class="form-row">
                <label>Tanggal Request:</label>
                <input type="date" id="formReqDate" class="form-control-compact" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-row">
                <label>Tanggal Dibutuhkan:</label>
                <input type="date" id="formNeedDate" class="form-control-compact" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-row">
                <label>Departemen Pemohon:</label>
                <input type="text" id="formDeptFr" class="form-control-compact" value="PRODUKSI" placeholder="Misal: PRODUKSI / CUTTING">
            </div>
            <div class="form-row">
                <label>Departemen Tujuan (Warehouse):</label>
                <input type="text" id="formDeptTo" class="form-control-compact" value="GUDANG UTAMA" placeholder="Misal: GUDANG BAHAN BAKU">
            </div>
            <div class="form-row">
                <label>Nomor Work Order (WO):</label>
                <input type="text" id="formWoNo" class="form-control-compact" placeholder="Opsional jika terkait WO">
            </div>
            <div class="form-row">
                <label>PIC Pemohon:</label>
                <input type="text" id="formPic" class="form-control-compact" value="<?= htmlspecialchars($currentUser) ?>">
            </div>
            <div class="form-row">
                <label>Klasifikasi:</label>
                <select id="formKlas" class="form-control-compact">
                    <option value="Raw Materials" selected>Raw Materials (Bahan Baku)</option>
                    <option value="Finished Goods">Finished Goods (Barang Jadi)</option>
                    <option value="Capital Goods">Capital Goods (Mesin &amp; Alat)</option>
                    <option value="Wastes">Reject / Scrap / Wastes</option>
                </select>
            </div>
            <div class="form-row">
                <label>Catatan / Keperluan Permintaan:</label>
                <input type="text" id="formNote" class="form-control-compact" placeholder="Catatan peruntukan barang...">
            </div>
        </div>

        <!-- ITEMS TABLE -->
        <div class="table-container" style="margin-bottom:8px;">
            <div style="background:#fafafa; padding:6px 12px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:700;"><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Rincian Barang yang Diminta</span>
                <button type="button" class="btn-compact btn-primary" onclick="addRowItem()">
                    <i class="fa-solid fa-plus"></i> Tambah Baris Barang
                </button>
            </div>
            <table class="mr-table" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">#</th>
                        <th style="width:180px;">Kode Barang</th>
                        <th>Uraian Nama Barang &amp; Spesifikasi</th>
                        <th style="width:70px;">Satuan</th>
                        <th style="width:90px; text-align:right;">Qty Diminta</th>
                        <th style="width:160px;">Catatan Baris</th>
                        <th style="width:40px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="itemsTbody">
                    <!-- Dynamic rows -->
                </tbody>
            </table>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn-compact btn-default" onclick="switchTab(1)">
                <i class="fa-solid fa-arrow-left"></i> Batal &amp; Kembali
            </button>
            <button type="button" class="btn-compact btn-default" onclick="saveRequest(false)">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Draft Saja
            </button>
            <button type="button" class="btn-compact btn-success" onclick="saveRequest(true)">
                <i class="fa-solid fa-paper-plane"></i> Ajukan Permintaan (Submit for Approval)
            </button>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- TAB 3: SETTING APPROVAL PERUSAHAAN -->
    <div class="tab-content" id="tabContent3">
        <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:6px; padding:15px; max-width:650px;">
            <h4 style="margin-top:0; color:var(--primary); font-size:12px;"><i class="fa-solid fa-shield-halved"></i> Konfigurasi Otoritas Approval Permintaan Barang</h4>
            <p style="color:var(--text-muted); font-size:9px; margin-bottom:12px;">Atur tingkat persetujuan dan role pengguna yang berwenang menyetujui pengeluaran barang di gudang.</p>

            <div class="form-row" style="margin-bottom:10px;">
                <label style="font-weight:700;">Mode Approval Permintaan Barang:</label>
                <select id="cfgApprMode" class="form-control-compact" style="width:100%;">
                    <option value="0" <?= ($mrApprovalMode === '0') ? 'selected' : '' ?>>Non-Aktif (Otomatis Approved / Langsung Siap Dikeluarkan)</option>
                    <option value="1" <?= ($mrApprovalMode === '1') ? 'selected' : '' ?>>1 Level Approval (Wajib Disetujui Kepala Dept / Spv Gudang)</option>
                    <option value="2" <?= ($mrApprovalMode === '2') ? 'selected' : '' ?>>2 Level Approval (Level 1 Dept Pemohon -> Level 2 Ka. Gudang)</option>
                </select>
            </div>

            <div class="form-row" style="margin-bottom:15px;">
                <label style="font-weight:700;">Role yang Berwenang Melakukan Approval (Pisahkan koma):</label>
                <input type="text" id="cfgApprRoles" class="form-control-compact" value="<?= htmlspecialchars($mrApproverRoles) ?>" style="width:100%;">
                <span style="font-size:8pt; color:var(--text-muted);">Contoh: admin, superadmin, warehouse, production, supervisor</span>
            </div>

            <button type="button" class="btn-compact btn-primary" onclick="saveApprovalConfig()">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Konfigurasi Approval
            </button>
        </div>
    </div>
    <?php endif; ?>

    <script>
        let mrList = [];
        const isUserApprover = <?= $canApprove ? 'true' : 'false' ?>;

        document.addEventListener('DOMContentLoaded', () => {
            loadMrData();
        });

        function switchTab(num) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(`tabBtn${num}`).classList.add('active');
            document.getElementById(`tabContent${num}`).classList.add('active');
            if (num === 1) loadMrData();
            if (num === 2 && document.getElementById('itemsTbody').children.length === 0) {
                addRowItem();
            }
        }

        async function loadMrData() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('filterStatus').value;
            const tbody = document.getElementById('mrTbody');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:15px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data...</td></tr>';

            try {
                const res = await fetch(`scm_material_request.php?action=list&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&limit=100`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:var(--danger); padding:15px;">Gagal memuat: ${json.message}</td></tr>`;
                    return;
                }

                mrList = json.data || [];
                document.getElementById('statTotal').innerText = json.total || 0;
                document.getElementById('statPending').innerText = json.pending || 0;
                document.getElementById('statApproved').innerText = json.approved || 0;
                document.getElementById('statCompleted').innerText = json.completed || 0;

                if (mrList.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada data permintaan barang.</td></tr>';
                    return;
                }

                let html = '';
                mrList.forEach((r, idx) => {
                    let badgeCls = 'badge-pending';
                    let statusLabel = r.status;
                    if (r.status === 'DRAFT') { badgeCls = 'badge-draft'; statusLabel = 'Draft'; }
                    else if (r.status === 'PENDING') { badgeCls = 'badge-pending'; statusLabel = 'Menunggu Approval'; }
                    else if (r.status === 'APPROVED') { badgeCls = 'badge-approved'; statusLabel = 'Disetujui (Ready Issue)'; }
                    else if (r.status === 'REJECTED') { badgeCls = 'badge-rejected'; statusLabel = 'Ditolak'; }
                    else if (r.status === 'COMPLETED') { badgeCls = 'badge-completed'; statusLabel = 'Selesai Dikeluarkan'; }

                    let approvalAudit = '-';
                    if (r.status === 'APPROVED' && r.approved_by_1) {
                        approvalAudit = `<span style="color:#096dd9;"><i class="fa-solid fa-check"></i> Disetujui oleh: <strong>${escapeHtml(r.approved_by_1)}</strong> (${escapeHtml(r.approved_at_1 || '-')})</span>`;
                    } else if (r.status === 'REJECTED') {
                        approvalAudit = `<span style="color:#cf1322;"><i class="fa-solid fa-ban"></i> Ditolak: "${escapeHtml(r.reject_reason || '-')}" oleh ${escapeHtml(r.rejected_by || '-')}</span>`;
                    }

                    html += `
                        <tr>
                            <td style="text-align:center;">${idx + 1}</td>
                            <td><strong style="color:var(--primary); font-family:monospace;">${escapeHtml(r.req_no)}</strong></td>
                            <td>${escapeHtml(r.req_date)}</td>
                            <td>${escapeHtml(r.need_date || '-')}</td>
                            <td>${escapeHtml(r.dept_pemohon)}</td>
                            <td>${escapeHtml(r.dept_tujuan)}</td>
                            <td><span style="font-family:monospace; font-weight:700;">${escapeHtml(r.wo_no || '-')}</span></td>
                            <td>${escapeHtml(r.pic || '-')}</td>
                            <td style="text-align:center;"><strong>${r.item_count || 0}</strong></td>
                            <td style="text-align:right;">${parseFloat(r.total_qty || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                            <td style="text-align:center;"><span class="badge-status ${badgeCls}">${statusLabel}</span></td>
                            <td><div style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(r.reject_reason || '')}">${approvalAudit}</div></td>
                            <td style="text-align:center;">
                                <button type="button" class="btn-compact btn-default" onclick="printBppb('${r.id}')" title="Cetak Formulir Resmi BPPB"><i class="fa-solid fa-print"></i></button>
                                ${r.status === 'PENDING' && isUserApprover ? `
                                    <button type="button" class="btn-compact btn-success" onclick="approveRequest('${r.id}')" title="Setujui Permintaan Ini"><i class="fa-solid fa-check"></i></button>
                                    <button type="button" class="btn-compact btn-danger" onclick="rejectRequest('${r.id}')" title="Tolak Permintaan Ini"><i class="fa-solid fa-xmark"></i></button>
                                ` : ''}
                                ${r.status === 'DRAFT' || r.status === 'PENDING' ? `
                                    <button type="button" class="btn-compact btn-default" onclick="editRequest('${r.id}')" title="Edit Permintaan"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" class="btn-compact btn-danger" onclick="deleteRequest('${r.id}')" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                                ` : ''}
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
                if (window.AntEnterpriseGrid) {
                    if (!window.mrGrid) {
                        window.mrGrid = new AntEnterpriseGrid('#mrTable', { gridId: 'grid_material_request', enablePagination: false });
                    } else {
                        window.mrGrid.refresh();
                    }
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:var(--danger); padding:15px;">Terjadi kesalahan: ${err.message}</td></tr>`;
            }
        }

        function newRequestForm() {
            document.getElementById('formId').value = '';
            document.getElementById('formReqNo').value = '';
            document.getElementById('formReqDate').value = new Date().toISOString().substring(0, 10);
            document.getElementById('formNeedDate').value = new Date().toISOString().substring(0, 10);
            document.getElementById('formDeptFr').value = 'PRODUKSI';
            document.getElementById('formDeptTo').value = 'GUDANG UTAMA';
            document.getElementById('formWoNo').value = '';
            document.getElementById('formNote').value = '';
            document.getElementById('itemsTbody').innerHTML = '';
            addRowItem();
            switchTab(2);
        }

        function addRowItem(it = {}) {
            const tbody = document.getElementById('itemsTbody');
            const rowCount = tbody.children.length + 1;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="text-align:center;">${rowCount}</td>
                <td><input type="text" class="form-control-compact item-kd" style="width:100%; font-family:monospace; font-weight:700;" value="${escapeHtml(it.kode_barang || '')}" placeholder="Ketik kode barang..."></td>
                <td><input type="text" class="form-control-compact item-nm" style="width:100%;" value="${escapeHtml(it.nama_barang || '')}" placeholder="Uraian barang..."></td>
                <td><input type="text" class="form-control-compact item-sat" style="width:100%; text-align:center;" value="${escapeHtml(it.sat || 'PCE')}"></td>
                <td><input type="number" class="form-control-compact item-qty" style="width:100%; text-align:right;" step="0.01" min="0.01" value="${it.qty_req || 1}"></td>
                <td><input type="text" class="form-control-compact item-note" style="width:100%;" value="${escapeHtml(it.note || '')}" placeholder="Catatan..."></td>
                <td style="text-align:center;"><button type="button" class="btn-compact btn-danger" onclick="this.closest('tr').remove()" title="Hapus"><i class="fa-solid fa-trash"></i></button></td>
            `;
            tbody.appendChild(tr);
        }

        async function editRequest(id) {
            try {
                const res = await fetch(`scm_material_request.php?action=get_detail&id=${encodeURIComponent(id)}`);
                const json = await res.json();
                if (json.status !== 'OK') {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                    return;
                }
                const h = json.header;
                document.getElementById('formId').value = h.id;
                document.getElementById('formReqNo').value = h.req_no;
                document.getElementById('formReqDate').value = h.req_date;
                document.getElementById('formNeedDate').value = h.need_date || h.req_date;
                document.getElementById('formDeptFr').value = h.dept_pemohon;
                document.getElementById('formDeptTo').value = h.dept_tujuan;
                document.getElementById('formWoNo').value = h.wo_no || '';
                document.getElementById('formPic').value = h.pic || '';
                document.getElementById('formKlas').value = h.klas || 'Raw Materials';
                document.getElementById('formNote').value = h.note || '';

                const tbody = document.getElementById('itemsTbody');
                tbody.innerHTML = '';
                (json.items || []).forEach(it => addRowItem(it));
                switchTab(2);
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
            }
        }

        async function saveRequest(submitNow = false) {
            const rows = document.querySelectorAll('#itemsTbody tr');
            const items = [];
            rows.forEach(tr => {
                const kd = tr.querySelector('.item-kd').value.trim();
                const nm = tr.querySelector('.item-nm').value.trim();
                const sat = tr.querySelector('.item-sat').value.trim();
                const qty = parseFloat(tr.querySelector('.item-qty').value) || 0;
                const note = tr.querySelector('.item-note').value.trim();
                if (kd && qty > 0) {
                    items.push({ kode_barang: kd, nama_barang: nm, sat: sat, qty_req: qty, note: note });
                }
            });

            if (items.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Rincian barang permintaan wajib diisi minimal 1 baris dengan qty > 0.' });
                return;
            }

            const payload = {
                id: document.getElementById('formId').value,
                req_date: document.getElementById('formReqDate').value,
                need_date: document.getElementById('formNeedDate').value,
                dept_pemohon: document.getElementById('formDeptFr').value,
                dept_tujuan: document.getElementById('formDeptTo').value,
                wo_no: document.getElementById('formWoNo').value,
                pic: document.getElementById('formPic').value,
                klas: document.getElementById('formKlas').value,
                note: document.getElementById('formNote').value,
                submit_now: submitNow,
                items: items
            };

            try {
                const res = await fetch('scm_material_request.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message });
                    switchTab(1);
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Kesalahan Server', text: e.message });
            }
        }

        async function approveRequest(id) {
            const confirm = await Swal.fire({
                title: 'Setujui Permintaan Barang?',
                text: 'Setelah disetujui, gudang dapat langsung menarik data ini saat mengeluarkan bahan baku.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                confirmButtonText: 'Ya, Setujui'
            });
            if (!confirm.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'approval_action');
            fd.append('id', id);
            fd.append('type', 'APPROVE');

            const res = await fetch('scm_material_request.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire({ icon: 'success', title: 'Disetujui', text: json.message });
                loadMrData();
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            }
        }

        async function rejectRequest(id) {
            const { value: reason } = await Swal.fire({
                title: 'Tolak Permintaan Barang',
                input: 'textarea',
                inputLabel: 'Masukkan alasan penolakan (Wajib):',
                inputPlaceholder: 'Misal: Stok di gudang tidak mencukupi, kuota WO melebihi standar...',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                confirmButtonText: 'Tolak Permintaan',
                inputValidator: (val) => {
                    if (!val) return 'Alasan penolakan wajib diisi!';
                }
            });
            if (!reason) return;

            const fd = new FormData();
            fd.append('action', 'approval_action');
            fd.append('id', id);
            fd.append('type', 'REJECT');
            fd.append('reason', reason);

            const res = await fetch('scm_material_request.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire({ icon: 'info', title: 'Ditolak', text: json.message });
                loadMrData();
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            }
        }

        function printBppb(id) {
            window.open(`scm_material_request.php?action=print_bppb&id=${encodeURIComponent(id)}`, '_blank');
        }

        async function deleteRequest(id) {
            const confirm = await Swal.fire({
                title: 'Hapus Permintaan Barang?',
                text: 'Data permintaan yang dihapus tidak dapat dikembalikan.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                confirmButtonText: 'Ya, Hapus'
            });
            if (!confirm.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            const res = await fetch('scm_material_request.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message });
                loadMrData();
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            }
        }

        async function saveApprovalConfig() {
            const mode = document.getElementById('cfgApprMode').value;
            const roles = document.getElementById('cfgApprRoles').value;
            const fd = new FormData();
            fd.append('action', 'save_setting');
            fd.append('mode', mode);
            fd.append('roles', roles);

            const res = await fetch('scm_material_request.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message });
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            })[m]);
        }

        function openModuleGuide() {
            Swal.fire({
                title: 'Panduan Modul: Permintaan Barang (Material Request / BPPB)',
                width: 750,
                html: `
                    <div style="text-align:left; font-size:11px; line-height:1.6; max-height:65vh; overflow-y:auto; padding-right:10px;">
                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                            <div>
                                <strong style="color:#1d4ed8; font-size:11.5px;"><i class="fa-solid fa-file-word"></i> Dokumen Resmi Arsitektur &amp; SOP Sistem</strong>
                                <div style="font-size:10px; color:#4b5563;">Tersedia dokumen panduan teknis &amp; SOP lengkap format Microsoft Word (.docx).</div>
                            </div>
                            <a href="/docs/PANDUAN_ARSITEKTUR_WO_TRACEABILITY_CEISA4.docx" target="_blank" download class="btn-compact btn-primary" style="padding:5px 12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                <i class="fa-solid fa-download"></i> Unduh Word (.docx)
                            </a>
                        </div>
                        <h4 style="color:#1890ff; margin-top:0;"><i class="fa-solid fa-clipboard-check"></i> Tujuan &amp; Fungsi Modul BPPB</h4>
                        <p>Modul ini memastikan setiap pengeluaran bahan baku dari gudang memiliki <strong>dasar permohonan resmi dan otorisasi yang sah</strong>, mencegah pengeluaran liar, serta memvalidasi kesesuaian konsumsi material dengan Surat Perintah Kerja (Work Order).</p>

                        <h4 style="color:#1890ff;"><i class="fa-solid fa-diagram-project"></i> Alur Standar Operasional (SOP):</h4>
                        <ol style="padding-left:18px;">
                            <li><strong>Pembuatan Permintaan:</strong> Bagian pemohon (Produksi / Cutting / Maintenance) menginput item barang dan Qty yang dibutuhkan, mencantumkan No. WO jika relevan.</li>
                            <li><strong>Pengajuan Otorisasi:</strong> Klik <em>Ajukan Permintaan</em>. Status dokumen berubah menjadi <strong>Menunggu Approval</strong>.</li>
                            <li><strong>Review &amp; Persetujuan:</strong> Kepala Departemen / Supervisor mereview ketersediaan stok &amp; kuota. Dapat memilih <strong>Setujui (Approve)</strong> atau <strong>Tolak (Reject)</strong> disertai alasan tertulis.</li>
                            <li><strong>Realisasi di Gudang (Issue):</strong> Petugas gudang membuka menu <em>Issue Bahan Baku</em> dan menarik nomor MR yang telah disetujui, lalu melakukan scan label QR fisik barang yang diambil.</li>
                            <li><strong>Dokumen Cetak BPPB:</strong> Cetak formulir resmi 3 rangkap (Pemohon, Supervisor, Gudang) untuk arsip audit kepabeanan.</li>
                        </ol>

                        <h4 style="color:#1890ff;"><i class="fa-solid fa-gears"></i> Fleksibilitas Konfigurasi:</h4>
                        <p>Perusahaan dapat mengatur mode approval pada Tab 3: apakah tanpa approval (langsung approved otomatis untuk perusahaan ramping), atau 1-2 level approval ketat untuk perusahaan skala besar.</p>
                    </div>
                `,
                confirmButtonText: 'Tutup Panduan',
                confirmButtonColor: '#1890ff'
            });
        }
    </script>
</body>
</html>

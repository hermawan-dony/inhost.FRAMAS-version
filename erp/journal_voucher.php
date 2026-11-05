<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Journal Voucher / Kas & Bank Management
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_act_kas_bank.srw / bc_act_other_hdr.srd / bc_act_other_dtl.srd)
 * Terhubung ke tabel bc_act_other_hdr & bc_act_other_dtl terintegrasi langsung dengan Chart of Accounts (bc_act_akun),
 * Multi-Valuta, Live Balance Check (Debet = Kredit), dan Cetak Bukti Voucher Kas/Bank Resmi.
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);

// Auto-Heal: Pastikan tabel dan kolom bc_act_other_hdr dan bc_act_other_dtl tersedia
function ensureJournalTables($koneksi) {
    // 1. Table bc_act_other_hdr
    $checkHdr = $koneksi->query("SHOW TABLES LIKE 'bc_act_other_hdr'");
    if (!$checkHdr || $checkHdr->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_other_hdr` (
            `id` varchar(50) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `akun` varchar(30) NOT NULL DEFAULT '',
            `v_no` varchar(30) NOT NULL DEFAULT '',
            `v_dt` date DEFAULT NULL,
            `rate` decimal(18,4) DEFAULT '1.0000',
            `cek_no` varchar(50) DEFAULT '',
            `note` text DEFAULT NULL,
            `amt` decimal(18,4) DEFAULT '0.0000',
            `kurs` varchar(10) DEFAULT 'IDR',
            `set_by` varchar(50) DEFAULT '',
            `tipe` char(1) DEFAULT 'B',
            `status` char(1) DEFAULT '0',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cmp_vno` (`cmp_id`, `v_no`),
            KEY `idx_v_dt` (`v_dt`),
            KEY `idx_akun` (`akun`),
            KEY `idx_tipe` (`tipe`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $cols = [];
        $res = $koneksi->query("SHOW COLUMNS FROM bc_act_other_hdr");
        if ($res) while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
        if (!isset($cols['cmp_id'])) {
            @$koneksi->query("ALTER TABLE bc_act_other_hdr ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' FIRST");
            @$koneksi->query("ALTER TABLE bc_act_other_hdr ADD INDEX `idx_cmp_id` (`cmp_id`)");
        }
    }

    // 2. Table bc_act_other_dtl
    $checkDtl = $koneksi->query("SHOW TABLES LIKE 'bc_act_other_dtl'");
    if (!$checkDtl || $checkDtl->num_rows === 0) {
        $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_other_dtl` (
            `id` varchar(40) NOT NULL DEFAULT '',
            `id_hdr` varchar(50) NOT NULL DEFAULT '',
            `cmp_id` varchar(30) NOT NULL DEFAULT '',
            `v_no` varchar(30) NOT NULL DEFAULT '',
            `seq` int(11) NOT NULL DEFAULT '1',
            `akun` varchar(50) NOT NULL DEFAULT '',
            `kode_pembantu` varchar(50) DEFAULT '',
            `amt` decimal(18,4) DEFAULT '0.0000',
            `uraian` varchar(255) DEFAULT '',
            `kurs` varchar(10) DEFAULT 'IDR',
            `rate` decimal(18,4) DEFAULT '1.0000',
            `amt_prime` decimal(18,4) DEFAULT '0.0000',
            `posisi` char(1) DEFAULT 'K',
            `setby` varchar(50) DEFAULT '',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_hdr` (`id_hdr`),
            KEY `idx_cmp_v_dtl` (`cmp_id`, `v_no`),
            KEY `idx_akun_dtl` (`akun`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        $colsD = [];
        $resD = $koneksi->query("SHOW COLUMNS FROM bc_act_other_dtl");
        if ($resD) while ($r = $resD->fetch_assoc()) $colsD[strtolower($r['Field'])] = true;
        if (!isset($colsD['cmp_id'])) {
            @$koneksi->query("ALTER TABLE bc_act_other_dtl ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' AFTER `id_hdr`");
            @$koneksi->query("ALTER TABLE bc_act_other_dtl ADD INDEX `idx_cmp_id` (`cmp_id`)");
        }
        if (!isset($colsD['v_no'])) {
            @$koneksi->query("ALTER TABLE bc_act_other_dtl ADD COLUMN `v_no` varchar(30) NOT NULL DEFAULT '' AFTER `cmp_id`");
        }
        if (!isset($colsD['posisi'])) {
            @$koneksi->query("ALTER TABLE bc_act_other_dtl ADD COLUMN `posisi` char(1) DEFAULT 'K' AFTER `amt_prime`");
        }
    }
}

ensureJournalTables($koneksi);

// ==========================================
// AJAX API ENDPOINTS
// ==========================================
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $tipeFilter = trim($_GET['tipe'] ?? '');
    $tglAwal = trim($_GET['tgl_awal'] ?? '');
    $tglAkhir = trim($_GET['tgl_akhir'] ?? '');

    $where = ["(h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')"];
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(h.v_no LIKE '%{$escS}%' OR h.akun LIKE '%{$escS}%' OR a.nama_akun LIKE '%{$escS}%' OR h.cek_no LIKE '%{$escS}%' OR h.note LIKE '%{$escS}%')";
    }
    if (!empty($tipeFilter)) {
        $escT = $koneksi->real_escape_string($tipeFilter);
        $where[] = "h.tipe = '{$escT}'";
    }
    if (!empty($tglAwal)) {
        $escAwal = $koneksi->real_escape_string($tglAwal);
        $where[] = "h.v_dt >= '{$escAwal}'";
    }
    if (!empty($tglAkhir)) {
        $escAkhir = $koneksi->real_escape_string($tglAkhir);
        $where[] = "h.v_dt <= '{$escAkhir}'";
    }

    $whereStr = implode(' AND ', $where);

    $cntRes = $koneksi->query("SELECT COUNT(*) as total FROM bc_act_other_hdr h LEFT JOIN bc_act_akun a ON (h.akun = a.kode AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = '')) WHERE {$whereStr}");
    $total = $cntRes ? (int)$cntRes->fetch_assoc()['total'] : 0;

    $sql = "SELECT h.*, COALESCE(a.nama_akun, h.akun) as akun_nama,
                   (SELECT COUNT(*) FROM bc_act_other_dtl d WHERE d.id_hdr = h.id) as item_count
            FROM bc_act_other_hdr h
            LEFT JOIN bc_act_akun a ON (h.akun = a.kode AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = ''))
            WHERE {$whereStr}
            ORDER BY h.v_dt DESC, h.v_no DESC
            LIMIT {$offset}, {$limit}";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }

    echo json_encode(['status' => 'OK', 'total' => $total, 'page' => $page, 'limit' => $limit, 'data' => $data]);
    exit;
}

if ($action === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $hdrRes = $koneksi->query("SELECT h.*, COALESCE(a.nama_akun, h.akun) as akun_nama
                               FROM bc_act_other_hdr h
                               LEFT JOIN bc_act_akun a ON (h.akun = a.kode AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = ''))
                               WHERE h.id = '{$escId}' AND (h.cmp_id = '{$escCmp}' OR '{$escCmp}' = '') LIMIT 1");
    if (!$hdrRes || $hdrRes->num_rows === 0) {
        echo json_encode(['status' => 'ERR', 'message' => 'Voucher tidak ditemukan']);
        exit;
    }
    $header = $hdrRes->fetch_assoc();

    $dtlRes = $koneksi->query("SELECT d.*, COALESCE(a.nama_akun, d.akun) as nama_akun
                               FROM bc_act_other_dtl d
                               LEFT JOIN bc_act_akun a ON (d.akun = a.kode AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = ''))
                               WHERE d.id_hdr = '{$escId}' ORDER BY d.seq ASC");
    $details = [];
    if ($dtlRes) {
        while ($r = $dtlRes->fetch_assoc()) {
            $details[] = $r;
        }
    }

    echo json_encode(['status' => 'OK', 'header' => $header, 'details' => $details]);
    exit;
}

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

    $tipe = trim($post['tipe'] ?? 'B'); // B = Kas Masuk, K = Kas Keluar, M = Memorial / Jurnal Umum
    $vNo = trim($post['v_no'] ?? '');
    if (empty($vNo)) {
        // Auto number VCH-YYYYMM-XXXX (atau BKM / BKK / BM)
        $prefix = ($tipe === 'K') ? 'BKK' : (($tipe === 'M') ? 'BM' : 'BKM');
        $ym = date('Ym');
        $numRes = $koneksi->query("SELECT MAX(CAST(SUBSTRING(v_no, 11) AS UNSIGNED)) as max_num FROM bc_act_other_hdr WHERE cmp_id = '{$escCmp}' AND v_no LIKE '{$prefix}-{$ym}-%'");
        $next = ($numRes && ($row = $numRes->fetch_assoc()) && $row['max_num']) ? ((int)$row['max_num'] + 1) : 1;
        $vNo = sprintf('%s-%s-%04d', $prefix, $ym, $next);
    }
    $escVNo = $koneksi->real_escape_string($vNo);

    $vDt = !empty($post['v_dt']) ? $koneksi->real_escape_string($post['v_dt']) : date('Y-m-d');
    $akun = $koneksi->real_escape_string($post['akun'] ?? '');
    $cekNo = $koneksi->real_escape_string($post['cek_no'] ?? '');
    $note = $koneksi->real_escape_string($post['note'] ?? '');
    $kurs = $koneksi->real_escape_string($post['kurs'] ?? 'IDR');
    $rate = (float)($post['rate'] ?? 1);
    $amt = (float)($post['amt'] ?? 0);
    $status = $koneksi->real_escape_string($post['status'] ?? '0');
    $setBy = $_SESSION['user_id'] ?? ($_SESSION['username'] ?? 'admin');

    if (empty($akun)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Akun Kas/Bank utama wajib dipilih!']);
        exit;
    }

    if ($isEdit) {
        $sqlHdr = "UPDATE bc_act_other_hdr SET
                    v_no = '{$escVNo}',
                    v_dt = '{$vDt}',
                    tipe = '{$tipe}',
                    akun = '{$akun}',
                    cek_no = '{$cekNo}',
                    note = '{$note}',
                    kurs = '{$kurs}',
                    rate = {$rate},
                    amt = {$amt},
                    status = '{$status}',
                    set_by = '{$setBy}'
                   WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')";
    } else {
        $sqlHdr = "INSERT INTO bc_act_other_hdr (id, cmp_id, v_no, v_dt, tipe, akun, cek_no, note, kurs, rate, amt, status, set_by)
                   VALUES ('{$escId}', '{$escCmp}', '{$escVNo}', '{$vDt}', '{$tipe}', '{$akun}', '{$cekNo}', '{$note}', '{$kurs}', {$rate}, {$amt}, '{$status}', '{$setBy}')";
    }

    if (!$koneksi->query($sqlHdr)) {
        echo json_encode(['status' => 'ERR', 'message' => 'Gagal menyimpan header voucher: ' . $koneksi->error]);
        exit;
    }

    // Detail rows
    $koneksi->query("DELETE FROM bc_act_other_dtl WHERE id_hdr = '{$escId}'");
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
            $dtlAkun = $koneksi->real_escape_string($item['akun'] ?? '');
            $kodeBantu = $koneksi->real_escape_string($item['kode_pembantu'] ?? '');
            $uraian = $koneksi->real_escape_string($item['uraian'] ?? '');
            $dtlAmt = (float)($item['amt'] ?? 0);
            $amtPrime = (float)($item['amt_prime'] ?? ($dtlAmt * $rate));
            $posisi = $koneksi->real_escape_string($item['posisi'] ?? (($tipe === 'B') ? 'K' : 'D'));

            if (!empty($dtlAkun) && $dtlAmt > 0) {
                $sqlDtl = "INSERT INTO bc_act_other_dtl (id, id_hdr, cmp_id, v_no, seq, akun, kode_pembantu, uraian, amt, kurs, rate, amt_prime, posisi, setby)
                           VALUES ('{$dtlId}', '{$escId}', '{$escCmp}', '{$escVNo}', {$seq}, '{$dtlAkun}', '{$kodeBantu}', '{$uraian}', {$dtlAmt}, '{$kurs}', {$rate}, {$amtPrime}, '{$posisi}', '{$setBy}')";
                $koneksi->query($sqlDtl);
                $seq++;
            }
        }
    }

    echo json_encode(['status' => 'OK', 'id' => $id, 'v_no' => $vNo, 'message' => 'Voucher Kas/Bank berhasil disimpan']);
    exit;
}

if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $koneksi->query("DELETE FROM bc_act_other_dtl WHERE id_hdr = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
    $del = $koneksi->query("DELETE FROM bc_act_other_hdr WHERE id = '{$escId}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");

    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Voucher berhasil dihapus']);
    } else {
        echo json_encode(['status' => 'ERR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// ==========================================
// RENDER PRINT VIEW (BUKTI VOUCHER)
// ==========================================
$isPrint = ($action === 'print');
if ($isPrint) {
    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);
    $hdrRes = $koneksi->query("SELECT h.*, COALESCE(a.nama_akun, h.akun) as akun_nama
                               FROM bc_act_other_hdr h
                               LEFT JOIN bc_act_akun a ON (h.akun = a.kode AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = ''))
                               WHERE h.id = '{$escId}' LIMIT 1");
    $hdr = $hdrRes ? $hdrRes->fetch_assoc() : null;
    $dtlRes = $koneksi->query("SELECT d.*, COALESCE(a.nama_akun, d.akun) as nama_akun
                               FROM bc_act_other_dtl d
                               LEFT JOIN bc_act_akun a ON (d.akun = a.kode AND (a.cmp_id = '{$escCmp}' OR a.cmp_id = ''))
                               WHERE d.id_hdr = '{$escId}' ORDER BY d.seq ASC");
    $items = [];
    if ($dtlRes) while ($r = $dtlRes->fetch_assoc()) $items[] = $r;

    // Perusahaan
    $cmpRes = $koneksi->query("SELECT * FROM company WHERE cmp_id = '{$escCmp}' LIMIT 1");
    $cmp = $cmpRes ? $cmpRes->fetch_assoc() : null;

    $titleVoucher = ($hdr['tipe'] === 'K') ? 'BUKTI KAS / BANK KELUAR' : (($hdr['tipe'] === 'M') ? 'MEMORIAL JOURNAL VOUCHER' : 'BUKTI KAS / BANK MASUK');
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title><?= $titleVoucher ?> - <?= htmlspecialchars($hdr['v_no'] ?? '') ?></title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; color: #111; margin: 20px; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; border-bottom: 2px solid #1e293b; padding-bottom: 10px; }
            .info-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            .info-table td { padding: 4px 6px; font-size: 10pt; vertical-align: top; }
            .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
            .items-table th, .items-table td { border: 1px solid #444; padding: 6px 8px; font-size: 9.5pt; }
            .items-table th { background: #f2f2f2; text-align: center; font-weight: 600; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .sign-table { width: 100%; border-collapse: collapse; margin-top: 25px; page-break-inside: avoid; }
            .sign-table td { border: 1px solid #444; padding: 6px; text-align: center; font-size: 9pt; width: 25%; }
            .sign-space { height: 60px; }
            @media print {
                .no-print { display: none !important; }
                body { margin: 10mm; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px;">
            <button onclick="window.print()" style="padding: 8px 16px; background:#10b981; color:#fff; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">🖨️ Cetak Voucher</button>
            <button onclick="window.close()" style="padding: 8px 16px; background:#64748b; color:#fff; border:none; border-radius:4px; cursor:pointer; margin-left:8px;">Tutup</button>
        </div>

        <table class="header-table">
            <tr>
                <td style="width: 60%;">
                    <strong style="font-size: 13pt;"><?= htmlspecialchars($cmp['nama'] ?? 'PT. PERUSAHAAN') ?></strong><br>
                    <span style="font-size: 9pt; color:#444;"><?= nl2br(htmlspecialchars($cmp['alamat'] ?? '')) ?></span>
                </td>
                <td style="width: 40%; text-align: right; vertical-align: top;">
                    <div style="font-size: 15pt; font-weight: bold; color: #1e293b;"><?= $titleVoucher ?></div>
                    <div style="font-size: 10pt; color: #64748b; font-weight: bold;">NO: <?= htmlspecialchars($hdr['v_no'] ?? '') ?></div>
                </td>
            </tr>
        </table>

        <table class="info-table">
            <tr>
                <td style="width: 15%;"><strong>Akun Kas/Bank</strong></td>
                <td style="width: 35%;">: <strong><?= htmlspecialchars($hdr['akun']) ?> - <?= htmlspecialchars($hdr['akun_nama']) ?></strong></td>
                <td style="width: 15%;"><strong>Tanggal Voucher</strong></td>
                <td style="width: 35%;">: <strong><?= htmlspecialchars($hdr['v_dt'] ?? '') ?></strong></td>
            </tr>
            <tr>
                <td><strong>No. Cek / Giro</strong></td>
                <td>: <?= htmlspecialchars($hdr['cek_no'] ?: '-') ?></td>
                <td><strong>Mata Uang & Kurs</strong></td>
                <td>: <?= htmlspecialchars($hdr['kurs']) ?> (Rate: <?= number_format((float)$hdr['rate'], 4, ',', '.') ?>)</td>
            </tr>
            <tr>
                <td><strong>Keterangan</strong></td>
                <td colspan="3">: <?= nl2br(htmlspecialchars($hdr['note'] ?: '-')) ?></td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 18%;">Kode Akun</th>
                    <th>Nama Akun & Uraian</th>
                    <th style="width: 12%;">Kode Bantu</th>
                    <th style="width: 15%; text-align:right;">Debet</th>
                    <th style="width: 15%; text-align:right;">Kredit</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                $totD = 0;
                $totK = 0;

                // Main account entry
                $mainAmt = (float)$hdr['amt'];
                if ($hdr['tipe'] === 'B') {
                    $totD += $mainAmt;
                    echo "<tr>
                        <td class='text-center'>1</td>
                        <td><strong>" . htmlspecialchars($hdr['akun']) . "</strong></td>
                        <td>" . htmlspecialchars($hdr['akun_nama']) . " <small style='color:#666;'>(Kas/Bank Penerima)</small></td>
                        <td class='text-center'>-</td>
                        <td class='text-right'><strong>" . number_format($mainAmt, 2, ',', '.') . "</strong></td>
                        <td class='text-right'>-</td>
                    </tr>";
                    $no = 2;
                } elseif ($hdr['tipe'] === 'K') {
                    $totK += $mainAmt;
                }

                foreach ($items as $it):
                    $amt = (float)$it['amt'];
                    $isD = ($it['posisi'] === 'D');
                    if ($isD) $totD += $amt; else $totK += $amt;
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><strong><?= htmlspecialchars($it['akun']) ?></strong></td>
                    <td><?= htmlspecialchars($it['nama_akun']) ?><br><small style="color:#555;"><?= htmlspecialchars($it['uraian']) ?></small></td>
                    <td class="text-center"><?= htmlspecialchars($it['kode_pembantu'] ?: '-') ?></td>
                    <td class="text-right"><?= $isD ? number_format($amt, 2, ',', '.') : '-' ?></td>
                    <td class="text-right"><?= !$isD ? number_format($amt, 2, ',', '.') : '-' ?></td>
                </tr>
                <?php endforeach;

                if ($hdr['tipe'] === 'K') {
                    echo "<tr>
                        <td class='text-center'>{$no}</td>
                        <td><strong>" . htmlspecialchars($hdr['akun']) . "</strong></td>
                        <td>" . htmlspecialchars($hdr['akun_nama']) . " <small style='color:#666;'>(Kas/Bank Pengeluaran)</small></td>
                        <td class='text-center'>-</td>
                        <td class='text-right'>-</td>
                        <td class='text-right'><strong>" . number_format($mainAmt, 2, ',', '.') . "</strong></td>
                    </tr>";
                }
                ?>
                <tr style="font-weight: bold; background: #fafafa;">
                    <td colspan="4" class="text-right">TOTAL (<?= htmlspecialchars($hdr['kurs']) ?>):</td>
                    <td class="text-right"><?= number_format($totD, 2, ',', '.') ?></td>
                    <td class="text-right"><?= number_format($totK, 2, ',', '.') ?></td>
                </tr>
            </tbody>
        </table>

        <table class="sign-table">
            <tr>
                <td>Dibuat Oleh</td>
                <td>Diperiksa Oleh</td>
                <td>Disetujui Oleh</td>
                <td>Diterima / Dibayar Oleh</td>
            </tr>
            <tr>
                <td class="sign-space"></td>
                <td class="sign-space"></td>
                <td class="sign-space"></td>
                <td class="sign-space"></td>
            </tr>
            <tr>
                <td>( .................................... )<br><span style="font-size: 8pt; color:#666;">Kasir / Pembukuan</span></td>
                <td>( .................................... )<br><span style="font-size: 8pt; color:#666;">Supervisor Accounting</span></td>
                <td>( .................................... )<br><span style="font-size: 8pt; color:#666;">Manager Keuangan</span></td>
                <td>( .................................... )<br><span style="font-size: 8pt; color:#666;">Penerima / Penyetor</span></td>
            </tr>
        </table>
    </body>
    </html>
    <?php
    exit;
}

// Data COA untuk Dropdown
$accounts = [];
$accRes = $koneksi->query("SELECT kode, nama_akun, tipe FROM bc_act_akun WHERE (cmp_id = '{$escCmp}' OR cmp_id = '') ORDER BY kode ASC");
if ($accRes) while ($r = $accRes->fetch_assoc()) $accounts[] = $r;

$currencies = ['IDR', 'USD', 'EUR', 'JPY', 'SGD', 'CNY', 'GBP'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kas, Bank & Jurnal Voucher - CEISA 4.0 & ERP Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-primary: #f8fafc;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --accent: #10b981;
            --accent-hover: #059669;
            --primary: #3b82f6;
            --danger: #ef4444;
        }
        body.dark-mode {
            --bg-primary: #0f172a;
            --bg-card: #1e293b;
            --border-color: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent: #10b981;
            --accent-hover: #059669;
            --primary: #60a5fa;
            --danger: #f87171;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: var(--bg-primary); color: var(--text-primary); font-size: 13px; line-height: 1.5; padding: 15px; }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: var(--bg-card); padding: 12px 18px; border-radius: 8px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .top-bar h2 { font-size: 16px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .btn-group { display: flex; gap: 8px; }
        .btn { padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-success { background: var(--accent); color: #fff; }
        .btn-secondary { background: var(--bg-primary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .btn-sm { padding: 4px 8px; font-size: 11px; }

        .filter-panel { background: var(--bg-card); padding: 12px 18px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .form-control { padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); font-size: 12px; outline: none; }
        .form-control:focus { border-color: var(--primary); }

        .table-container { background: var(--bg-card); border-radius: 8px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        table.data-table { width: 100%; border-collapse: collapse; text-align: left; }
        table.data-table th { background: rgba(0,0,0,0.02); padding: 10px 12px; font-weight: 600; color: var(--text-secondary); border-bottom: 1px solid var(--border-color); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.5px; }
        table.data-table td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); font-size: 12px; }
        table.data-table tr:hover { background: rgba(0,0,0,0.015); }
        .badge { padding: 2px 7px; border-radius: 4px; font-size: 10.5px; font-weight: 600; display: inline-block; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-blue { background: #dbeafe; color: #1e40af; }

        .pagination { display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; border-top: 1px solid var(--border-color); font-size: 12px; color: var(--text-secondary); }

        /* MODAL STYLES */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; justify-content: center; align-items: center; padding: 20px; }
        .modal.active { display: flex; }
        .modal-content { background: var(--bg-card); width: 100%; max-width: 980px; max-height: 90vh; border-radius: 8px; border: 1px solid var(--border-color); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-header { padding: 12px 18px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 15px; font-weight: 700; color: var(--text-primary); }
        .modal-body { padding: 18px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 12px 18px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: rgba(0,0,0,0.01); }

        .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; }

        .items-header { display: flex; justify-content: space-between; align-items: center; margin: 15px 0 8px 0; padding-bottom: 4px; border-bottom: 2px solid var(--primary); }
        .items-header h4 { font-size: 13px; font-weight: 700; color: var(--text-primary); }
        .item-row { display: grid; grid-template-columns: 2.5fr 3fr 1.2fr 1fr 1.3fr 35px; gap: 6px; align-items: center; margin-bottom: 6px; }
        .item-row input, .item-row select { width: 100%; }

        .total-box { margin-top: 15px; display: flex; justify-content: space-between; align-items: center; }
        .balance-status { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
        .total-card { width: 340px; background: rgba(0,0,0,0.02); padding: 12px; border-radius: 6px; border: 1px solid var(--border-color); }
        .total-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px; }
        .total-row.grand { font-size: 14px; font-weight: 700; color: var(--primary); border-top: 1px dashed var(--border-color); padding-top: 6px; margin-top: 6px; }
    </style>
</head>
<body class="<?= (!empty($_GET['theme']) && $_GET['theme'] === 'dark') ? 'dark-mode' : '' ?>">

    <div class="top-bar">
        <h2><i class="fa-solid fa-receipt" style="color:var(--primary);"></i> Entri Voucher Kas, Bank & Jurnal Umum</h2>
        <div class="btn-group">
            <button class="btn btn-secondary" onclick="loadVoucherList()"><i class="fa-solid fa-rotate"></i> Muat Ulang</button>
            <button class="btn btn-success" onclick="openVoucherModal('B')"><i class="fa-solid fa-plus"></i> Kas/Bank Masuk</button>
            <button class="btn btn-primary" onclick="openVoucherModal('K')"><i class="fa-solid fa-minus"></i> Kas/Bank Keluar</button>
            <button class="btn btn-secondary" onclick="openVoucherModal('M')"><i class="fa-solid fa-book"></i> Jurnal Umum</button>
        </div>
    </div>

    <div class="filter-panel">
        <input type="text" id="filterSearch" class="form-control" style="width: 250px;" placeholder="Cari No Voucher, Akun, Cek, Keterangan..." onkeyup="if(event.key==='Enter') loadVoucherList()">
        <select id="filterTipe" class="form-control" onchange="loadVoucherList()">
            <option value="">-- Semua Tipe Voucher --</option>
            <option value="B">Kas/Bank Masuk (BKM)</option>
            <option value="K">Kas/Bank Keluar (BKK)</option>
            <option value="M">Jurnal Umum / Memorial (BM)</option>
        </select>
        <input type="date" id="filterTglAwal" class="form-control" onchange="loadVoucherList()">
        <span style="color:var(--text-secondary);">s/d</span>
        <input type="date" id="filterTglAkhir" class="form-control" onchange="loadVoucherList()">
        <button class="btn btn-primary" onclick="loadVoucherList()"><i class="fa-solid fa-filter"></i> Filter</button>
    </div>

    <div class="table-container">
        <table class="data-table" id="vTable">
            <thead>
                <tr>
                    <th style="width: 13%;">No. Voucher</th>
                    <th style="width: 9%;">Tanggal</th>
                    <th style="width: 11%;">Tipe</th>
                    <th style="width: 22%;">Akun Kas/Bank</th>
                    <th style="width: 18%;">Keterangan</th>
                    <th style="width: 14%; text-align:right;">Nominal (IDR)</th>
                    <th style="width: 13%; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="vTableBody">
                <tr><td colspan="7" style="text-align:center; padding:20px; color:var(--text-secondary);">Memuat data...</td></tr>
            </tbody>
        </table>
        <div class="pagination">
            <span id="pageInfo">Menampilkan 0 data</span>
            <div class="btn-group" id="pageBtns"></div>
        </div>
    </div>

    <!-- MODAL FORM VOUCHER -->
    <div class="modal" id="modalVoucher">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalVTitle"><i class="fa-solid fa-pen-to-square"></i> Entri Voucher</h3>
                <button style="background:none; border:none; font-size:18px; color:var(--text-secondary); cursor:pointer;" onclick="closeVoucherModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="vId">
                <input type="hidden" id="vTipe" value="B">
                <div class="form-grid">
                    <div class="form-group">
                        <label>No. Voucher <small>(Otomatis jika kosong)</small></label>
                        <input type="text" id="vNo" class="form-control" placeholder="Contoh: BKM-202610-0001">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Transaksi *</label>
                        <input type="date" id="vDt" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Tipe Voucher</label>
                        <select id="selTipe" class="form-control" onchange="changeTipe(this.value)">
                            <option value="B">Kas/Bank Masuk (BKM)</option>
                            <option value="K">Kas/Bank Keluar (BKK)</option>
                            <option value="M">Jurnal Umum / Memorial (BM)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Akun Kas / Bank Utama *</label>
                        <select id="vAkun" class="form-control">
                            <option value="">-- Pilih Akun Kas/Bank --</option>
                            <?php foreach ($accounts as $a): ?>
                            <option value="<?= htmlspecialchars($a['kode']) ?>"><?= htmlspecialchars($a['kode']) ?> - <?= htmlspecialchars($a['nama_akun']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>No. Cek / Bilyet Giro</label>
                        <input type="text" id="vCekNo" class="form-control" placeholder="No Cek jika via Giro/Bank">
                    </div>
                    <div class="form-group">
                        <label>Mata Uang & Kurs</label>
                        <div style="display:flex; gap:4px;">
                            <select id="vKurs" class="form-control" style="width: 90px;">
                                <?php foreach ($currencies as $c): ?>
                                <option value="<?= $c ?>"><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" id="vRate" class="form-control" value="1.0000" step="0.0001">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:15px;">
                    <label>Catatan / Uraian Transaksi Utama</label>
                    <textarea id="vNote" class="form-control" rows="2" placeholder="Keterangan transaksi penerimaan/pengeluaran kas..."></textarea>
                </div>

                <div class="items-header">
                    <h4><i class="fa-solid fa-list-ol"></i> Lawan Akun & Rincian Transaksi</h4>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addItemRow()"><i class="fa-solid fa-plus"></i> Tambah Lawan Akun</button>
                </div>

                <div style="font-size:11px; font-weight:600; color:var(--text-secondary); display:grid; grid-template-columns: 2.5fr 3fr 1.2fr 1fr 1.3fr 35px; gap:6px; margin-bottom:4px; padding:0 4px;">
                    <div>LAWAN AKUN (COA)</div>
                    <div>URAIAN SPESIFIK</div>
                    <div>KODE BANTU</div>
                    <div>POSISI</div>
                    <div>NOMINAL</div>
                    <div></div>
                </div>

                <div id="itemsContainer"></div>

                <div class="total-box">
                    <div class="balance-status" id="balanceStatus">
                        <i class="fa-solid fa-circle-check" style="color:var(--accent);"></i> Live Balance OK
                    </div>
                    <div class="total-card">
                        <div class="total-row">
                            <span>Total Lawan Akun:</span>
                            <strong id="dispSubtotal">0,00</strong>
                        </div>
                        <div class="total-row grand">
                            <span>Total Nilai Voucher:</span>
                            <strong id="dispGrandTotal">0,00</strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeVoucherModal()">Batal</button>
                <button type="button" class="btn btn-primary" onclick="saveVoucher()"><i class="fa-solid fa-floppy-disk"></i> Simpan Voucher</button>
            </div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        const allAccounts = <?= json_encode($accounts) ?>;

        document.addEventListener('DOMContentLoaded', () => {
            loadVoucherList();
        });

        function loadVoucherList(page = 1) {
            currentPage = page;
            const search = document.getElementById('filterSearch').value;
            const tipe = document.getElementById('filterTipe').value;
            const tglAwal = document.getElementById('filterTglAwal').value;
            const tglAkhir = document.getElementById('filterTglAkhir').value;

            const url = `journal_voucher.php?action=list&page=${page}&limit=20&search=${encodeURIComponent(search)}&tipe=${encodeURIComponent(tipe)}&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}`;

            fetch(url)
                .then(res => res.json())
                .then(res => {
                    const tbody = document.getElementById('vTableBody');
                    if (res.status !== 'OK' || !res.data || res.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:25px; color:var(--text-secondary);"><i class="fa-solid fa-receipt" style="font-size:24px; margin-bottom:8px; display:block;"></i>Belum ada transaksi voucher.</td></tr>';
                        document.getElementById('pageInfo').innerText = 'Menampilkan 0 data';
                        document.getElementById('pageBtns').innerHTML = '';
                        return;
                    }

                    let html = '';
                    res.data.forEach(r => {
                        let tipeBadge = '<span class="badge badge-green">Kas Masuk (BKM)</span>';
                        if (r.tipe === 'K') tipeBadge = '<span class="badge badge-red">Kas Keluar (BKK)</span>';
                        else if (r.tipe === 'M') tipeBadge = '<span class="badge badge-blue">Memorial (BM)</span>';

                        html += `
                            <tr>
                                <td><strong style="color:var(--primary); font-family:monospace; font-size:12.5px;">${escapeHtml(r.v_no)}</strong></td>
                                <td>${escapeHtml(r.v_dt || '-')}</td>
                                <td>${tipeBadge}</td>
                                <td><strong>${escapeHtml(r.akun)}</strong> - ${escapeHtml(r.akun_nama)}</td>
                                <td>${escapeHtml(r.note || '-')}</td>
                                <td style="text-align:right;"><strong>${escapeHtml(r.kurs)} ${formatRupiah(r.amt)}</strong></td>
                                <td style="text-align:center;">
                                    <div class="btn-group" style="justify-content:center;">
                                        <button class="btn btn-secondary btn-sm" onclick="editVoucher('${r.id}')" title="Edit"><i class="fa-solid fa-pencil"></i></button>
                                        <a href="journal_voucher.php?action=print&id=${r.id}" target="_blank" class="btn btn-secondary btn-sm" title="Cetak Bukti Voucher"><i class="fa-solid fa-print"></i></a>
                                        <button class="btn btn-secondary btn-sm" style="color:var(--danger);" onclick="deleteVoucher('${r.id}', '${escapeHtml(r.v_no)}')" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;

                    const totalPages = Math.ceil(res.total / res.limit);
                    document.getElementById('pageInfo').innerText = `Menampilkan ${(res.page-1)*res.limit + 1} - ${Math.min(res.total, res.page*res.limit)} dari ${res.total} data`;
                    let btns = '';
                    if (res.page > 1) btns += `<button class="btn btn-secondary btn-sm" onclick="loadVoucherList(${res.page - 1})">Prev</button>`;
                    btns += `<span style="padding:4px 8px; font-weight:bold;">${res.page} / ${totalPages || 1}</span>`;
                    if (res.page < totalPages) btns += `<button class="btn btn-secondary btn-sm" onclick="loadVoucherList(${res.page + 1})">Next</button>`;
                    document.getElementById('pageBtns').innerHTML = btns;
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('vTableBody').innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat data.</td></tr>';
                });
        }

        function openVoucherModal(tipe = 'B') {
            document.getElementById('vId').value = '';
            document.getElementById('vNo').value = '';
            document.getElementById('vDt').value = new Date().toISOString().split('T')[0];
            document.getElementById('vTipe').value = tipe;
            document.getElementById('selTipe').value = tipe;
            document.getElementById('vAkun').value = '';
            document.getElementById('vCekNo').value = '';
            document.getElementById('vNote').value = '';
            document.getElementById('vKurs').value = 'IDR';
            document.getElementById('vRate').value = '1.0000';
            document.getElementById('itemsContainer').innerHTML = '';
            changeTipe(tipe);
            addItemRow();
            calcTotals();
            document.getElementById('modalVoucher').classList.add('active');
        }

        function changeTipe(tipe) {
            document.getElementById('vTipe').value = tipe;
            const t = (tipe === 'K') ? 'Kas Keluar (BKK)' : ((tipe === 'M') ? 'Jurnal Umum (BM)' : 'Kas Masuk (BKM)');
            document.getElementById('modalVTitle').innerHTML = '<i class="fa-solid fa-plus-circle"></i> Entri Voucher - ' + t;
        }

        function closeVoucherModal() {
            document.getElementById('modalVoucher').classList.remove('active');
        }

        function addItemRow(item = {}) {
            const container = document.getElementById('itemsContainer');
            const row = document.createElement('div');
            row.className = 'item-row';

            let accOptions = '<option value="">-- Pilih Akun --</option>';
            allAccounts.forEach(a => {
                const sel = (item.akun === a.kode) ? 'selected' : '';
                accOptions += `<option value="${escapeHtml(a.kode)}" ${sel}>${escapeHtml(a.kode)} - ${escapeHtml(a.nama_akun)}</option>`;
            });

            const tipe = document.getElementById('vTipe').value;
            const defaultPos = (tipe === 'B') ? 'K' : 'D';
            const pos = item.posisi || defaultPos;

            row.innerHTML = `
                <select class="form-control it-akun" required>${accOptions}</select>
                <input type="text" class="form-control it-uraian" value="${escapeHtml(item.uraian || '')}" placeholder="Uraian rincian..." required>
                <input type="text" class="form-control it-bantu" value="${escapeHtml(item.kode_pembantu || '')}" placeholder="Customer/Supplier">
                <select class="form-control it-posisi" style="width:100%;" onchange="calcTotals()">
                    <option value="D" ${pos==='D'?'selected':''}>Debet (D)</option>
                    <option value="K" ${pos==='K'?'selected':''}>Kredit (K)</option>
                </select>
                <input type="number" class="form-control it-amt" value="${item.amt || 0}" step="0.0001" oninput="calcTotals()" style="text-align:right;">
                <button type="button" class="btn btn-secondary btn-sm" style="color:var(--danger); padding:4px;" onclick="this.parentElement.remove(); calcTotals();">&times;</button>
            `;
            container.appendChild(row);
        }

        function calcTotals() {
            let total = 0;
            document.querySelectorAll('#itemsContainer .it-amt').forEach(inp => {
                total += parseFloat(inp.value) || 0;
            });
            document.getElementById('dispSubtotal').innerText = formatRupiah(total);
            document.getElementById('dispGrandTotal').innerText = formatRupiah(total);
        }

        function editVoucher(id) {
            fetch(`journal_voucher.php?action=get_detail&id=${encodeURIComponent(id)}`)
                .then(res => res.json())
                .then(res => {
                    if (res.status !== 'OK') {
                        alert(res.message || 'Gagal memuat detail voucher');
                        return;
                    }
                    const h = res.header;
                    document.getElementById('vId').value = h.id;
                    document.getElementById('vNo').value = h.v_no;
                    document.getElementById('vDt').value = h.v_dt || '';
                    document.getElementById('vTipe').value = h.tipe || 'B';
                    document.getElementById('selTipe').value = h.tipe || 'B';
                    document.getElementById('vAkun').value = h.akun || '';
                    document.getElementById('vCekNo').value = h.cek_no || '';
                    document.getElementById('vNote').value = h.note || '';
                    document.getElementById('vKurs').value = h.kurs || 'IDR';
                    document.getElementById('vRate').value = h.rate || '1.0000';
                    changeTipe(h.tipe || 'B');

                    const cont = document.getElementById('itemsContainer');
                    cont.innerHTML = '';
                    if (res.details && res.details.length > 0) {
                        res.details.forEach(it => addItemRow(it));
                    }
                    calcTotals();
                    document.getElementById('modalVoucher').classList.add('active');
                })
                .catch(err => {
                    console.error(err);
                    alert('Terjadi kesalahan saat memuat detail voucher');
                });
        }

        function saveVoucher() {
            const akun = document.getElementById('vAkun').value;
            if (!akun) {
                alert('Silakan pilih Akun Kas/Bank Utama terlebih dahulu!');
                return;
            }

            const items = [];
            let totalAmt = 0;
            document.querySelectorAll('#itemsContainer .item-row').forEach(row => {
                const itAkun = row.querySelector('.it-akun').value.trim();
                const uraian = row.querySelector('.it-uraian').value.trim();
                const bantu = row.querySelector('.it-bantu').value.trim();
                const posisi = row.querySelector('.it-posisi').value;
                const amt = parseFloat(row.querySelector('.it-amt').value) || 0;

                if (itAkun && amt > 0) {
                    items.push({
                        akun: itAkun,
                        uraian: uraian,
                        kode_pembantu: bantu,
                        posisi: posisi,
                        amt: amt
                    });
                    totalAmt += amt;
                }
            });

            if (items.length === 0) {
                alert('Mohon masukkan minimal 1 baris rincian lawan akun!');
                return;
            }

            const payload = {
                id: document.getElementById('vId').value,
                v_no: document.getElementById('vNo').value,
                v_dt: document.getElementById('vDt').value,
                tipe: document.getElementById('vTipe').value,
                akun: akun,
                cek_no: document.getElementById('vCekNo').value,
                note: document.getElementById('vNote').value,
                kurs: document.getElementById('vKurs').value,
                rate: parseFloat(document.getElementById('vRate').value) || 1,
                amt: totalAmt,
                items: items
            };

            fetch('journal_voucher.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'OK') {
                    alert('Sukses: ' + res.message);
                    closeVoucherModal();
                    loadVoucherList(currentPage);
                } else {
                    alert('Gagal: ' + (res.message || 'Terjadi kesalahan sistem'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Gagal menghubungi server');
            });
        }

        function deleteVoucher(id, vNo) {
            if (!confirm(`Apakah Anda yakin ingin menghapus Voucher ${vNo}?`)) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('journal_voucher.php?action=delete', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'OK') {
                    loadVoucherList(currentPage);
                } else {
                    alert(res.message || 'Gagal menghapus');
                }
            });
        }

        function formatRupiah(num) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    </script>
</body>
</html>

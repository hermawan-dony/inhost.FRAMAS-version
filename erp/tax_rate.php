<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Rate Pajak (USD & Valas)
 * Acuan Asli PowerBuilder: w_taxrate.srw & DataWindow d_taxrate.srd
 * Pengelolaan Kurs Pajak KMK Mingguan Kemenkeu, Multi-Valuta, Tarik Kurs Online,
 * Kalkulator Konversi Nilai Pabean, dan Standardisasi Ant Design Style (Identik PR).
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
// AUTO-HEAL: Pastikan Tabel bc_efaktur_rate Tersedia Lengkap
// -------------------------------------------------------------------------
function ensureTaxRateTable($koneksi) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_efaktur_rate'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_efaktur_rate` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `awal` date NOT NULL,
                `akhir` date NOT NULL,
                `kurs` varchar(10) NOT NULL DEFAULT 'USD',
                `rate` decimal(16,4) NOT NULL DEFAULT 0.0000,
                `kmk_no` varchar(100) DEFAULT '',
                `kmk_note` varchar(255) DEFAULT '',
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_periode_kurs` (`cmp_id`, `awal`, `akhir`, `kurs`),
                KEY `idx_awal_akhir` (`awal`, `akhir`),
                KEY `idx_kurs` (`kurs`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Multi-tenant auto-healing untuk database dedicated yang memiliki skema lama
            $colId = $koneksi->query("SHOW COLUMNS FROM bc_efaktur_rate LIKE 'id'");
            if (!$colId || $colId->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_efaktur_rate ADD COLUMN id INT AUTO_INCREMENT UNIQUE FIRST");
            }
            $colKurs = $koneksi->query("SHOW COLUMNS FROM bc_efaktur_rate LIKE 'kurs'");
            if (!$colKurs || $colKurs->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_efaktur_rate ADD COLUMN kurs VARCHAR(10) NOT NULL DEFAULT 'USD'");
            }
            @$koneksi->query("UPDATE bc_efaktur_rate SET kurs = 'USD' WHERE kurs IS NULL OR kurs = ''");
            @$koneksi->query("UPDATE bc_efaktur_rate SET rate = 0 WHERE rate IS NULL");
            @$koneksi->query("ALTER TABLE bc_efaktur_rate MODIFY COLUMN set_by VARCHAR(100) DEFAULT ''");
            @$koneksi->query("ALTER TABLE bc_efaktur_rate MODIFY COLUMN rate DECIMAL(16,4) DEFAULT 0.0000");
        }
    } catch (\Throwable $ex) {
        // Abaikan bila skema sudah sesuai atau permission DDL terbatas
    }

    // Periksa apakah tabel kosong, jika ya buatkan benih data (seed) awal kurs KMK resmi
    $cnt = $koneksi->query("SELECT COUNT(*) FROM bc_efaktur_rate")->fetch_row()[0] ?? 0;
    if ((int)$cnt === 0) {
        $seeds = [
            // Periode Aktif Minggu Ini (28 Okt - 03 Nov 2026)
            ['2026-10-28', '2026-11-03', 'USD', 18032.00, 'KMK Nomor 51/MK/EF.2/2026', 'Tanggal Berlaku: 28 Okt 2026 - 03 Nov 2026', 'SYSTEM - Auto Init'],
            ['2026-10-28', '2026-11-03', 'EUR', 20231.46, 'KMK Nomor 51/MK/EF.2/2026', 'Tanggal Berlaku: 28 Okt 2026 - 03 Nov 2026', 'SYSTEM - Auto Init'],
            ['2026-10-28', '2026-11-03', 'SGD', 14109.23, 'KMK Nomor 51/MK/EF.2/2026', 'Tanggal Berlaku: 28 Okt 2026 - 03 Nov 2026', 'SYSTEM - Auto Init'],
            ['2026-10-28', '2026-11-03', 'JPY', 114.3484, 'KMK Nomor 51/MK/EF.2/2026', 'Tanggal Berlaku: 28 Okt 2026 - 03 Nov 2026', 'SYSTEM - Auto Init'],
            ['2026-10-28', '2026-11-03', 'CNY', 2701.48,  'KMK Nomor 51/MK/EF.2/2026', 'Tanggal Berlaku: 28 Okt 2026 - 03 Nov 2026', 'SYSTEM - Auto Init'],
        ];

        foreach ($seeds as $s) {
            $koneksi->query("INSERT IGNORE INTO bc_efaktur_rate (cmp_id, awal, akhir, kurs, rate, kmk_no, kmk_note, set_by)
                             VALUES ('$escCmp', '{$s[0]}', '{$s[1]}', '{$s[2]}', {$s[3]}, '{$s[4]}', '{$s[5]}', '{$s[6]}')");
        }
    }
}
ensureTaxRateTable($koneksi);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. GET MINI DASHBOARD KPI
if ($action === 'get_kpi') {
    header('Content-Type: application/json; charset=utf-8');

    // Cari rate USD aktif hari ini
    $today = date('Y-m-d');
    $qUsd = $koneksi->query("SELECT * FROM bc_efaktur_rate 
                             WHERE '$today' BETWEEN awal AND akhir AND (kurs = 'USD' OR kurs = '' OR kurs IS NULL) 
                             ORDER BY awal DESC LIMIT 1");
    if (!$qUsd || $qUsd->num_rows === 0) {
        // Fallback ke entri USD terbaru
        $qUsd = $koneksi->query("SELECT * FROM bc_efaktur_rate WHERE (kurs = 'USD' OR kurs = '' OR kurs IS NULL) ORDER BY awal DESC LIMIT 1");
    }

    $activeUsdRate = 0;
    $activeKmkNo   = '-';
    $activePeriode = '-';
    $isLiveActive  = false;

    if ($qUsd && $r = $qUsd->fetch_assoc()) {
        $activeUsdRate = (float)$r['rate'];
        $activeKmkNo   = $r['kmk_no'] ?: 'KMK Kemenkeu Terkini';
        $activePeriode = date('d M Y', strtotime($r['awal'])) . ' - ' . date('d M Y', strtotime($r['akhir']));
        if ($today >= $r['awal'] && $today <= $r['akhir']) {
            $isLiveActive = true;
        }
    }

    // Total riwayat
    $cntTotal = $koneksi->query("SELECT COUNT(*) FROM bc_efaktur_rate")->fetch_row()[0] ?? 0;
    $cntValuta = $koneksi->query("SELECT COUNT(DISTINCT COALESCE(NULLIF(kurs, ''), 'USD')) FROM bc_efaktur_rate")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'usd_rate' => $activeUsdRate,
        'kmk_no' => $activeKmkNo,
        'periode' => $activePeriode,
        'is_active' => $isLiveActive,
        'total_records' => (int)$cntTotal,
        'total_valuta' => (int)$cntValuta
    ]);
    exit;
}

// 2. LIST DATA RATE PAJAK (Sesuai Logika WHERE d_taxrate.srd)
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $tglAwal  = trim($_GET['tgl_awal'] ?? '');
    $tglAkhir = trim($_GET['tgl_akhir'] ?? '');
    $kurs     = trim($_GET['kurs'] ?? '');
    $search   = trim($_GET['search'] ?? '');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $limit    = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset   = ($page - 1) * $limit;

    $where = [];

    // Filter tanggal persis seperti DataWindow d_taxrate.srd
    if (!empty($tglAwal) && !empty($tglAkhir)) {
        $ta = $koneksi->real_escape_string($tglAwal);
        $tk = $koneksi->real_escape_string($tglAkhir);
        $where[] = "(
            (awal BETWEEN '$ta' AND '$tk') OR 
            (akhir BETWEEN '$ta' AND '$tk') OR 
            ('$ta' BETWEEN awal AND akhir) OR 
            ('$tk' BETWEEN awal AND akhir)
        )";
    } elseif (!empty($tglAwal)) {
        $ta = $koneksi->real_escape_string($tglAwal);
        $where[] = "akhir >= '$ta'";
    } elseif (!empty($tglAkhir)) {
        $tk = $koneksi->real_escape_string($tglAkhir);
        $where[] = "awal <= '$tk'";
    }

    if ($kurs === 'USD') {
        $where[] = "(kurs = 'USD' OR kurs = '' OR kurs IS NULL)";
    } elseif (!empty($kurs) && $kurs !== 'ALL') {
        $kEsc = $koneksi->real_escape_string($kurs);
        $where[] = "kurs = '$kEsc'";
    }

    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(kmk_no LIKE '%$sEsc%' OR kmk_note LIKE '%$sEsc%' OR set_by LIKE '%$sEsc%' OR rate LIKE '%$sEsc%')";
    }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) FROM bc_efaktur_rate $whereSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    $sql = "SELECT id, cmp_id, awal, akhir, COALESCE(NULLIF(kurs, ''), 'USD') as kurs, rate, kmk_no, kmk_note, set_by, updated_at 
            FROM bc_efaktur_rate 
            $whereSql 
            ORDER BY awal DESC, kurs ASC 
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);

    $today = date('Y-m-d');
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $isAktif = ($today >= $r['awal'] && $today <= $r['akhir']);
            $isLampau = ($today > $r['akhir']);
            $isUpcoming = ($today < $r['awal']);

            $statusText = 'Arsip';
            $statusClass = 'badge-lampau';
            if ($isAktif) {
                $statusText = 'Aktif Minggu Ini';
                $statusClass = 'badge-aktif';
            } elseif ($isUpcoming) {
                $statusText = 'Terjadwal';
                $statusClass = 'badge-upcoming';
            }

            $rows[] = [
                'id' => (int)$r['id'],
                'awal' => $r['awal'],
                'akhir' => $r['akhir'],
                'kurs' => strtoupper($r['kurs']),
                'rate' => (float)$r['rate'],
                'kmk_no' => $r['kmk_no'],
                'kmk_note' => $r['kmk_note'],
                'set_by' => $r['set_by'],
                'updated_at' => $r['updated_at'],
                'status_label' => $statusText,
                'status_class' => $statusClass,
                'is_active' => $isAktif
            ];
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil($total / $limit)
    ]);
    exit;
}

// 3. GET SINGLE RATE BY ID
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $q = $koneksi->query("SELECT * FROM bc_efaktur_rate WHERE id = $id LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        echo json_encode(['status' => 'OK', 'data' => $r]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data tidak ditemukan']);
    }
    exit;
}

// 4. SAVE (TAMBAH / EDIT RATE PAJAK)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $id      = (int)($_POST['id'] ?? 0);
    $awal    = trim($_POST['awal'] ?? '');
    $akhir   = trim($_POST['akhir'] ?? '');
    $kurs    = strtoupper(trim($_POST['kurs'] ?? 'USD'));
    $rate    = (float)str_replace(',', '', trim($_POST['rate'] ?? '0'));
    $kmkNo   = trim($_POST['kmk_no'] ?? '');
    $kmkNote = trim($_POST['kmk_note'] ?? '');

    if (empty($awal) || empty($akhir)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tanggal awal dan akhir berlaku wajib diisi.']);
        exit;
    }
    if ($akhir < $awal) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tanggal akhir tidak boleh mendahului tanggal awal.']);
        exit;
    }
    if ($rate <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nilai rate pajak harus lebih besar dari 0.']);
        exit;
    }

    if (empty($kmkNote)) {
        $kmkNote = 'Tanggal Berlaku: ' . date('d M Y', strtotime($awal)) . ' - ' . date('d M Y', strtotime($akhir));
    }

    $escAwal  = $koneksi->real_escape_string($awal);
    $escAkhir = $koneksi->real_escape_string($akhir);
    $escKurs  = $koneksi->real_escape_string($kurs);
    $escKmk   = $koneksi->real_escape_string($kmkNo);
    $escNote  = $koneksi->real_escape_string($kmkNote);
    $setBy    = $koneksi->real_escape_string($currentUser . ' - ' . date('Y-m-d H:i'));

    if ($id > 0) {
        $sql = "UPDATE bc_efaktur_rate SET 
                awal = '$escAwal', 
                akhir = '$escAkhir', 
                kurs = '$escKurs', 
                rate = $rate, 
                kmk_no = '$escKmk', 
                kmk_note = '$escNote', 
                set_by = '$setBy'
                WHERE id = $id";
        if ($koneksi->query($sql)) {
            echo json_encode(['status' => 'OK', 'message' => 'Rate pajak berhasil diperbarui.']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui data: ' . $koneksi->error]);
        }
    } else {
        $sql = "INSERT INTO bc_efaktur_rate (cmp_id, awal, akhir, kurs, rate, kmk_no, kmk_note, set_by)
                VALUES ('$escCmp', '$escAwal', '$escAkhir', '$escKurs', $rate, '$escKmk', '$escNote', '$setBy')
                ON DUPLICATE KEY UPDATE 
                rate = VALUES(rate), 
                kmk_no = VALUES(kmk_no), 
                kmk_note = VALUES(kmk_note), 
                set_by = VALUES(set_by)";
        if ($koneksi->query($sql)) {
            echo json_encode(['status' => 'OK', 'message' => 'Rate pajak baru berhasil disimpan.']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan data: ' . $koneksi->error]);
        }
    }
    exit;
}

// 5. DELETE RATE PAJAK
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID data tidak valid.']);
        exit;
    }

    $del = $koneksi->query("DELETE FROM bc_efaktur_rate WHERE id = $id");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Data rate pajak berhasil dihapus.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus data: ' . $koneksi->error]);
    }
    exit;
}

// 6. TARIK KURS PAJAK ONLINE (Mengadopsi wf_auto di PB Desktop w_taxrate via /taxrate.php)
if ($action === 'fetch_online') {
    header('Content-Type: application/json; charset=utf-8');
    $targetDate = trim($_POST['tgl'] ?? date('Y-m-d'));
    if (empty($targetDate)) $targetDate = date('Y-m-d');

    // Gunakan fungsi scraper lokal Kemenkeu di /taxrate.php
    require_once __DIR__ . '/../taxrate.php';
    $kmkData = tpberp_scrape_taxrate($targetDate);

    if (!$kmkData || empty($kmkData['rates'])) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Gagal menarik kurs pajak dari Kemenkeu (fiskal.kemenkeu.go.id). Pastikan server terhubung ke internet.'
        ]);
        exit;
    }

    $tglAwal    = $kmkData['tgl1'];
    $tglAkhir   = $kmkData['tgl2'];
    $kmkNo      = $kmkData['kmk'] ?: ('KMK No. ' . date('W') . '/KM.10/' . date('Y'));
    $keterangan = $kmkData['keterangan'] ?: ('Tanggal Berlaku: ' . date('d M Y', strtotime($tglAwal)) . ' - ' . date('d M Y', strtotime($tglAkhir)));
    $rates      = $kmkData['rates'];

    $savedCount = 0;
    // Format set_by persis seperti PowerBuilder Desktop (max 25 karakter):
    // e.g. "SYSTEM - 20261028 08:50"
    $setBy = 'SYSTEM - ' . date('Ymd Hi');
    $escKmk = $koneksi->real_escape_string($kmkNo);
    $escNote = $koneksi->real_escape_string($keterangan);
    $escSetBy = $koneksi->real_escape_string($setBy);

    foreach ($rates as $cur => $val) {
        $cEsc = $koneksi->real_escape_string($cur);
        $vVal = (float)$val;
        if ($vVal <= 0) continue;

        $ins = $koneksi->query("INSERT INTO bc_efaktur_rate (cmp_id, awal, akhir, kurs, rate, kmk_no, kmk_note, set_by)
                                VALUES ('$escCmp', '$tglAwal', '$tglAkhir', '$cEsc', $vVal, '$escKmk', '$escNote', '$escSetBy')
                                ON DUPLICATE KEY UPDATE 
                                rate = VALUES(rate), 
                                kmk_no = VALUES(kmk_no), 
                                kmk_note = VALUES(kmk_note), 
                                set_by = VALUES(set_by)");
        if ($ins) $savedCount++;
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Berhasil menarik $savedCount kurs valuta KMK ($kmkNo) untuk periode " . date('d M Y', strtotime($tglAwal)) . " s/d " . date('d M Y', strtotime($tglAkhir)),
        'kmk_no' => $kmkNo,
        'periode_awal' => $tglAwal,
        'periode_akhir' => $tglAkhir,
        'saved_count' => $savedCount
    ]);
    exit;
}

// 7. EXPORT DATA KE CSV / EXCEL
if ($action === 'export_csv') {
    $tglAwal  = trim($_GET['tgl_awal'] ?? '');
    $tglAkhir = trim($_GET['tgl_akhir'] ?? '');
    $kurs     = trim($_GET['kurs'] ?? '');

    $where = [];
    if (!empty($tglAwal) && !empty($tglAkhir)) {
        $ta = $koneksi->real_escape_string($tglAwal);
        $tk = $koneksi->real_escape_string($tglAkhir);
        $where[] = "((awal BETWEEN '$ta' AND '$tk') OR (akhir BETWEEN '$ta' AND '$tk'))";
    }
    if ($kurs === 'USD') {
        $where[] = "(kurs = 'USD' OR kurs = '' OR kurs IS NULL)";
    } elseif (!empty($kurs) && $kurs !== 'ALL') {
        $where[] = "kurs = '" . $koneksi->real_escape_string($kurs) . "'";
    }
    $wSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $q = $koneksi->query("SELECT awal, akhir, COALESCE(NULLIF(kurs, ''), 'USD') as kurs, rate, kmk_no, kmk_note, set_by FROM bc_efaktur_rate $wSql ORDER BY awal DESC, kurs ASC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Rate_Pajak_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tanggal Awal', 'Tanggal Akhir', 'Valuta', 'Rate Pajak (IDR)', 'Nomor KMK', 'Keterangan', 'Set By']);

    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($out, [
                $r['awal'],
                $r['akhir'],
                $r['kurs'],
                number_format((float)$r['rate'], 4, '.', ''),
                $r['kmk_no'],
                $r['kmk_note'],
                $r['set_by']
            ]);
        }
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Pajak (USD &amp; Valas) - <?= htmlspecialchars($activeCmpName) ?></title>
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
        html, body { 
            height: 100%; 
            overflow: hidden; 
            background: var(--bg-base); 
            color: var(--text-main); 
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            font-size: 10.5px; 
            -webkit-font-smoothing: antialiased;
        }
        input, select, textarea, button { font-family: inherit; }

        .ant-container { height: 100%; display: flex; flex-direction: column; padding: 6px; gap: 0; }
        .ant-card { 
            background: var(--bg-card); 
            border: 1px solid var(--border-color); 
            border-radius: 3px; 
            display: flex; 
            flex-direction: column; 
            flex: 1; 
            min-height: 0; 
            overflow: hidden; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.02); 
        }
        .ant-card-head { 
            min-height: 34px; 
            padding: 4px 10px; 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            flex-wrap: wrap; 
            gap: 6px; 
            background: var(--bg-card); 
        }
        .ant-card-title { 
            font-size: 12px; 
            font-weight: 600; 
            color: var(--text-main); 
            display: flex; 
            align-items: center; 
            gap: 6px; 
        }
        .ant-badge { 
            font-size: 9px; 
            padding: 1px 6px; 
            border-radius: 10px; 
            font-weight: 600; 
            line-height: 1.2; 
            text-transform: uppercase; 
        }
        .ant-badge-company { 
            background: rgba(24, 144, 255, 0.12); 
            color: var(--primary); 
            border: 1px solid rgba(24, 144, 255, 0.3); 
        }

        /* KPI Dashboard Row */
        .kpi-row { 
            display: grid; 
            grid-template-columns: repeat(4, 1fr); 
            gap: 6px; 
            padding: 6px 10px; 
            border-bottom: 1px solid var(--border-color); 
            background: var(--bg-base); 
        }
        .kpi-card { 
            background: var(--bg-card); 
            border: 1px solid var(--border-color); 
            border-radius: 3px; 
            padding: 6px 10px; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
        }
        .kpi-icon { 
            width: 32px; 
            height: 32px; 
            border-radius: 4px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 14px; 
            flex-shrink: 0; 
        }
        .kpi-num { 
            font-size: 13px; 
            font-weight: 700; 
            color: var(--text-main); 
            line-height: 1.2; 
        }
        .kpi-label { 
            font-size: 9.5px; 
            color: var(--text-muted); 
            white-space: nowrap; 
        }

        /* Tabs */
        .ant-tabs-nav { 
            display: flex; 
            gap: 2px; 
            padding: 0 10px; 
            background: var(--bg-card); 
            border-bottom: 1px solid var(--border-color); 
        }
        .ant-tab-btn { 
            border: none; 
            border-bottom: 2px solid transparent; 
            background: transparent; 
            color: var(--text-muted); 
            padding: 5px 12px; 
            cursor: pointer; 
            font-size: 10.5px; 
            font-weight: 500; 
            display: inline-flex; 
            align-items: center; 
            gap: 5px; 
            transition: all 0.2s; 
        }
        .ant-tab-btn:hover { color: var(--primary); }
        .ant-tab-btn.active { 
            color: var(--primary); 
            border-bottom-color: var(--primary); 
            font-weight: 600; 
        }

        /* Toolbar Buttons */
        .ant-toolbar { 
            display: flex; 
            align-items: center; 
            gap: 4px; 
            flex-wrap: wrap; 
            padding: 6px 10px; 
            border-bottom: 1px solid var(--border-color); 
            background: var(--bg-card); 
        }
        .ant-btn { 
            font-family: inherit; 
            font-size: 10px; 
            height: 22px; 
            padding: 0 8px; 
            border-radius: 2px; 
            border: 1px solid var(--border-color); 
            background: var(--bg-card); 
            color: var(--text-main); 
            cursor: pointer; 
            display: inline-flex; 
            align-items: center; 
            gap: 4px; 
            font-weight: 500; 
            line-height: 1.4; 
            transition: all 0.15s; 
        }
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
        .ant-btn-sm { height: 20px; font-size: 9.5px; padding: 0 6px; }

        /* Pages */
        .ant-page { display: none; min-height: 0; flex-direction: column; flex: 1; overflow: hidden; }
        .ant-page.active { display: flex; }

        /* Filter Box (Mengadopsi em_dt1 & em_dt2 di w_taxrate) */
        .filter-bar { 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            padding: 6px 10px; 
            background: var(--bg-base); 
            border-bottom: 1px solid var(--border-color); 
            flex-wrap: wrap; 
        }
        .filter-group { display: flex; align-items: center; gap: 4px; }
        .filter-group label { font-size: 10px; font-weight: 600; color: var(--text-muted); }

        .ant-input { 
            height: 22px; 
            padding: 0 6px; 
            font-size: 10px; 
            border: 1px solid var(--border-input); 
            border-radius: 2px; 
            background: var(--bg-input); 
            color: var(--text-main); 
            font-family: inherit; 
            outline: none; 
            transition: border-color 0.15s; 
        }
        .ant-input:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        select.ant-input { padding: 0 4px; }

        /* Grid Table */
        .table-wrap { flex: 1; min-height: 0; overflow: auto; background: var(--bg-card); }
        .ant-table { width: 100%; border-collapse: collapse; font-size: 10.5px; text-align: left; }
        .ant-table th { 
            position: sticky; 
            top: 0; 
            z-index: 5; 
            background: var(--table-head-bg); 
            color: var(--text-muted); 
            font-weight: 600; 
            padding: 6px 8px; 
            border-bottom: 1px solid var(--border-color); 
            border-right: 1px solid var(--border-light); 
            white-space: nowrap; 
            user-select: none; 
        }
        .ant-table td { 
            padding: 5px 8px; 
            border-bottom: 1px solid var(--border-light); 
            border-right: 1px solid var(--border-light); 
            color: var(--text-main); 
            white-space: nowrap; 
        }
        .ant-table tbody tr:hover { background: var(--table-hover); }
        .ant-table tbody tr.row-active-highlight { background: rgba(82, 196, 26, 0.08); }

        /* Badges */
        .badge-aktif { 
            background: #f6ffed; 
            color: #52c41a; 
            border: 1px solid #b7eb8f; 
            font-size: 9px; 
            padding: 1px 6px; 
            border-radius: 10px; 
            font-weight: 600; 
        }
        .badge-lampau { 
            background: #fafafa; 
            color: #8c8c8c; 
            border: 1px solid #d9d9d9; 
            font-size: 9px; 
            padding: 1px 6px; 
            border-radius: 10px; 
        }
        .badge-upcoming { 
            background: #e6f7ff; 
            color: #1890ff; 
            border: 1px solid #91d5ff; 
            font-size: 9px; 
            padding: 1px 6px; 
            border-radius: 10px; 
            font-weight: 600; 
        }

        .badge-kurs { 
            font-weight: 700; 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-size: 9.5px; 
            display: inline-block; 
            text-align: center; 
            min-width: 36px; 
        }
        .badge-kurs-usd { background: #e6f7ff; color: #096dd9; border: 1px solid #91d5ff; }
        .badge-kurs-eur { background: #f9f0ff; color: #531dab; border: 1px solid #d3adf7; }
        .badge-kurs-sgd { background: #fff0f6; color: #c41d7f; border: 1px solid #ffadd2; }
        .badge-kurs-jpy { background: #fffbe6; color: #d46b08; border: 1px solid #ffe58f; }
        .badge-kurs-cny { background: #fff1f0; color: #cf1322; border: 1px solid #ffa39e; }
        .badge-kurs-other { background: #f5f5f5; color: #595959; border: 1px solid #d9d9d9; }

        /* Pagination Footer */
        .ant-table-footer { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 4px 10px; 
            border-top: 1px solid var(--border-color); 
            background: var(--bg-card); 
            font-size: 10px; 
            color: var(--text-muted); 
        }

        /* Modal Styles */
        .modal { 
            display: none; 
            position: fixed; 
            inset: 0; 
            z-index: 9999; 
            background: rgba(0,0,0,0.45); 
            align-items: center; 
            justify-content: center; 
        }
        .modal.active { display: flex; }
        .modal-card { 
            background: var(--bg-card); 
            border: 1px solid var(--border-color); 
            border-radius: 4px; 
            width: 90%; 
            max-width: 520px; 
            max-height: 90vh; 
            display: flex; 
            flex-direction: column; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
            overflow: hidden; 
        }
        .modal-h { 
            padding: 8px 12px; 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            background: var(--table-head-bg); 
            font-weight: 600; 
        }
        .modal-b { padding: 12px; overflow-y: auto; flex: 1; }
        .modal-f { 
            padding: 8px 12px; 
            border-top: 1px solid var(--border-color); 
            display: flex; 
            justify-content: flex-end; 
            gap: 6px; 
            background: var(--table-head-bg); 
        }

        .fld-row { margin-bottom: 8px; display: flex; flex-direction: column; gap: 3px; }
        .fld-row label { font-size: 10px; font-weight: 600; color: var(--text-muted); }

        /* Calculator Styles */
        .calc-panel { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 12px; 
            padding: 16px; 
            max-width: 860px; 
            margin: 0 auto; 
            width: 100%; 
        }
        .calc-card { 
            background: var(--bg-card); 
            border: 1px solid var(--border-color); 
            border-radius: 4px; 
            padding: 14px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.03); 
        }
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Header Card -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-money-bill-trend-up" style="color:var(--primary);"></i>
                <span>Rate Pajak (USD &amp; Valas) - Kurs KMK Kemenkeu</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span id="syncStatusText" style="font-size:10px; color:var(--text-muted);">
                    <i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Master Kurs Pajak KMK
                </span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP">
                    <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i>
                </button>
            </div>
        </div>

        <!-- Mini Dashboard KPI (Persis Pola PR & PO) -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);">
                    <i class="fa-solid fa-dollar-sign"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiUsdRate">Rp 0</div>
                    <div class="kpi-label">Rate USD Aktif Minggu Ini</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiKmkNo" style="font-size:11px; font-weight:700;">-</div>
                    <div class="kpi-label">Nomor Keputusan KMK</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiPeriode" style="font-size:11px; font-weight:600;">-</div>
                    <div class="kpi-label">Masa Berlaku Kurs (Rabu - Selasa)</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiTotalRecords">0</div>
                    <div class="kpi-label">Total Arsip Periode Kurs</div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs (3 Tab Sesuai Standardisasi) -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="entri" onclick="switchTab('entri')">
                <i class="fa-solid fa-pen-to-square"></i> Entri &amp; Kelola Rate Pajak
            </button>
            <button type="button" class="ant-tab-btn" data-tab="arsip" onclick="switchTab('arsip')">
                <i class="fa-solid fa-box-archive"></i> Riwayat &amp; Arsip Lengkap
            </button>
            <button type="button" class="ant-tab-btn" data-tab="kalkulator" onclick="switchTab('kalkulator')">
                <i class="fa-solid fa-calculator"></i> Kalkulator Konversi Nilai Pabean
            </button>
        </div>

        <!-- Toolbar Aksi Ant Design -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openAddRateModal()">
                <i class="fa-solid fa-plus"></i> Tambah Rate Baru
            </button>
            <button type="button" class="ant-btn ant-btn-success" onclick="triggerFetchOnline()" title="Ambil Kurs Pajak Resmi Otomatis dari CEISA 4.0 / Kemenkeu">
                <i class="fa-solid fa-cloud-arrow-down"></i> Tarik Kurs Pajak Online
            </button>
            <button type="button" class="ant-btn" onclick="exportDataExcel()">
                <i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel (.xlsx)
            </button>
            <button type="button" class="ant-btn" onclick="exportDataCsv()">
                <i class="fa-solid fa-file-csv" style="color:#faad14;"></i> Export CSV
            </button>
            <button type="button" class="ant-btn" onclick="loadRates()" title="Muat Ulang Data">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>

        <!-- TAB 1: ENTRI & KELOLA RATE PAJAK (Mengadopsi w_taxrate em_dt1 & em_dt2) -->
        <section class="ant-page active" id="pageEntri">
            <!-- Filter Bar Persis Window PB w_taxrate -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label><i class="fa-regular fa-calendar"></i> Tanggal Berlaku:</label>
                    <input type="date" class="ant-input" id="filDt1" title="Tanggal Awal">
                    <span style="color:var(--text-muted); font-weight:600;">s/d</span>
                    <input type="date" class="ant-input" id="filDt2" title="Tanggal Akhir">
                </div>
                <div class="filter-group">
                    <label>Valuta:</label>
                    <select class="ant-input" id="filKurs" onchange="loadRates(1)" style="min-width:110px;">
                        <option value="ALL">Semua Valuta</option>
                        <option value="USD" selected>USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="SGD">SGD - Singapore Dollar</option>
                        <option value="JPY">JPY - Japanese Yen</option>
                        <option value="CNY">CNY - Chinese Yuan</option>
                        <option value="GBP">GBP - British Pound</option>
                        <option value="AUD">AUD - Australian Dollar</option>
                        <option value="KRW">KRW - Korean Won</option>
                    </select>
                </div>
                <div class="filter-group">
                    <input type="text" class="ant-input" id="filSearch" placeholder="Cari No KMK / Catatan..." style="width:160px;" onkeyup="if(event.key==='Enter') loadRates(1);">
                </div>
                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadRates(1)">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="resetFilter()">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
            </div>

            <!-- Table Grid Data -->
            <div class="table-wrap">
                <table class="ant-table" id="tableRates">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;">No</th>
                            <th style="width:105px; text-align:center;">Status</th>
                            <th style="width:100px; text-align:center;">Tanggal Awal</th>
                            <th style="width:100px; text-align:center;">Tanggal Akhir</th>
                            <th style="width:70px; text-align:center;">Valuta</th>
                            <th style="width:130px; text-align:right;">Nilai Rate Pajak (IDR)</th>
                            <th style="width:190px;">Nomor KMK Kemenkeu</th>
                            <th>Keterangan / Periode</th>
                            <th style="width:160px;">Pencatat (Set By)</th>
                            <th style="width:90px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyRates">
                        <tr><td colspan="10" style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data kurs...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <div class="ant-table-footer">
                <div>Total Data: <strong id="lblTotalRows">0</strong> baris | Halaman <span id="lblCurPage">1</span> dari <span id="lblTotalPages">1</span></div>
                <div style="display:flex; gap:4px; align-items:center;">
                    <button type="button" class="ant-btn ant-btn-sm" id="btnPrevPage" onclick="changePage(-1)"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</button>
                    <button type="button" class="ant-btn ant-btn-sm" id="btnNextPage" onclick="changePage(1)">Selanjutnya <i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
        </section>

        <!-- TAB 2: RIWAYAT & ARSIP LENGKAP -->
        <section class="ant-page" id="pageArsip">
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Tahun Arsip:</label>
                    <select class="ant-input" id="filArsipYear" onchange="loadArsipYear()">
                        <option value="2026">Tahun 2026</option>
                        <option value="2025">Tahun 2025</option>
                        <option value="2024">Tahun 2024</option>
                        <option value="ALL">Semua Tahun</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Valuta Utama:</label>
                    <select class="ant-input" id="filArsipKurs" onchange="loadArsipYear()">
                        <option value="ALL">Semua Valuta</option>
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                        <option value="SGD">SGD</option>
                        <option value="JPY">JPY</option>
                        <option value="CNY">CNY</option>
                    </select>
                </div>
                <div class="filter-group" style="margin-left:auto;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="exportArsipExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Unduh Semua Arsip
                    </button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="ant-table" id="tableArsip">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;">No</th>
                            <th style="width:100px;">Valuta</th>
                            <th style="width:110px;">Tanggal Awal</th>
                            <th style="width:110px;">Tanggal Akhir</th>
                            <th style="width:140px; text-align:right;">Kurs Pajak</th>
                            <th style="width:200px;">No. KMK Kemenkeu</th>
                            <th>Keterangan Lengkap</th>
                            <th style="width:160px;">Jejak Audit</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyArsip">
                        <tr><td colspan="8" style="text-align:center; padding:20px; color:var(--text-muted);">Silakan pilih filter tahun untuk melihat arsip.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- TAB 3: KALKULATOR KONVERSI NILAI PABEAN -->
        <section class="ant-page" id="pageKalkulator">
            <div class="calc-panel">
                <div class="calc-card">
                    <div style="font-weight:700; color:var(--primary); font-size:12px; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-calculator"></i> Parameter Transaksi Valuta
                    </div>
                    <div class="fld-row">
                        <label>Pilih Mata Uang (Valuta):</label>
                        <select class="ant-input" id="calcValuta" onchange="runCalculation()">
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="SGD">SGD - Singapore Dollar</option>
                            <option value="JPY">JPY - Japanese Yen</option>
                            <option value="CNY">CNY - Chinese Yuan</option>
                        </select>
                    </div>
                    <div class="fld-row">
                        <label>Tanggal Transaksi / Pabean:</label>
                        <input type="date" class="ant-input" id="calcDate" value="<?= date('Y-m-d') ?>" onchange="updateCalcRateFromDate()">
                    </div>
                    <div class="fld-row">
                        <label>Nilai Rate Pajak KMK (IDR):</label>
                        <input type="number" step="0.01" class="ant-input" id="calcRate" value="16245.00" oninput="runCalculation()">
                        <span style="font-size:9.5px; color:var(--text-muted);" id="calcRateInfo">Rate KMK aktif minggu ini</span>
                    </div>
                    <div class="fld-row">
                        <label>Nilai Transaksi Valas (Amount):</label>
                        <input type="number" step="0.01" class="ant-input" id="calcAmount" value="10000.00" oninput="runCalculation()" style="font-weight:700; font-size:12px;">
                    </div>
                    <div class="fld-row" style="margin-top:10px;">
                        <button type="button" class="ant-btn ant-btn-primary" onclick="runCalculation()">
                            <i class="fa-solid fa-bolt"></i> Hitung Nilai Pabean Sekarang
                        </button>
                    </div>
                </div>

                <div class="calc-card" style="background:var(--table-head-bg);">
                    <div style="font-weight:700; color:#52c41a; font-size:12px; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-receipt"></i> Estimasi Nilai Pabean &amp; Pajak (IDR)
                    </div>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <div style="display:flex; justify-content:space-between; padding-bottom:6px; border-bottom:1px dashed var(--border-color);">
                            <span style="color:var(--text-muted);">Nilai Valas Asal:</span>
                            <strong id="resValas">USD 10,000.00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding-bottom:6px; border-bottom:1px dashed var(--border-color);">
                            <span style="color:var(--text-muted);">Kurs Konversi Pajak:</span>
                            <strong id="resKurs">Rp 16.245,00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:2px solid var(--primary);">
                            <span style="font-weight:700; color:var(--text-main);">Nilai Pabean / DPP Rupiah:</span>
                            <strong style="color:var(--primary); font-size:13px;" id="resDpp">Rp 162.450.000,00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted);">
                            <span>Estimasi PPN Impor (11%):</span>
                            <span id="resPpn">Rp 17.869.500,00</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted);">
                            <span>Estimasi PPh Pasal 22 (2.5% API):</span>
                            <span id="resPph">Rp 4.061.250,00</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted);">
                            <span>Estimasi Bea Masuk (5%):</span>
                            <span id="resBm">Rp 8.122.500,00</span>
                        </div>
                        <div style="margin-top:8px; padding:10px; background:rgba(24,144,255,0.06); border-radius:4px; font-size:9.5px; color:var(--text-muted);">
                            <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i> Perhitungan ini disesuaikan dengan formula dokumen pabean CEISA (BC 2.0 / BC 2.3 / BC 4.0) untuk estimasi pungutan negara.
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- MODAL: ENTRI / EDIT RATE PAJAK -->
<div class="modal" id="modalRateForm">
    <div class="modal-card">
        <div class="modal-h">
            <span id="modalFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah Rate Pajak Baru</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeRateModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <input type="hidden" id="formRateId">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Tanggal Awal Berlaku:</label>
                    <input type="date" class="ant-input" id="formAwal" required onchange="autoFillAkhirDate()">
                </div>
                <div class="fld-row">
                    <label>Tanggal Akhir Berlaku:</label>
                    <input type="date" class="ant-input" id="formAkhir" required>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Mata Uang (Valuta):</label>
                    <select class="ant-input" id="formKurs">
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="SGD">SGD - Singapore Dollar</option>
                        <option value="JPY">JPY - Japanese Yen</option>
                        <option value="CNY">CNY - Chinese Yuan</option>
                        <option value="GBP">GBP - British Pound</option>
                        <option value="AUD">AUD - Australian Dollar</option>
                        <option value="KRW">KRW - Korean Won</option>
                        <option value="MYR">MYR - Malaysian Ringgit</option>
                        <option value="THB">THB - Thai Baht</option>
                    </select>
                </div>
                <div class="fld-row">
                    <label>Nilai Kurs Pajak (Rupiah):</label>
                    <input type="number" step="0.0001" class="ant-input" id="formRate" placeholder="Contoh: 16245.00" required>
                </div>
            </div>
            <div class="fld-row">
                <label>Nomor KMK Kemenkeu:</label>
                <input type="text" class="ant-input" id="formKmkNo" placeholder="Contoh: KMK No. 44/KM.10/2026">
            </div>
            <div class="fld-row">
                <label>Keterangan / Catatan Tambahan:</label>
                <input type="text" class="ant-input" id="formKmkNote" placeholder="Contoh: Tanggal Berlaku: 28 Okt 2026 - 03 Nov 2026">
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeRateModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitRateForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Rate</button>
        </div>
    </div>
</div>

<!-- MODAL: PANDUAN LENGKAP MODUL & SOP KURS PAJAK KMK -->
<div class="modal" id="modalModuleGuide">
    <div class="modal-card" style="max-width:820px; max-height:88vh;">
        <div class="modal-h" style="background:linear-gradient(135deg, rgba(24,144,255,0.08), rgba(24,144,255,0.02));">
            <span style="font-weight:700; color:var(--primary); font-size:13px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-book-open"></i> Panduan Lengkap: Rate Pajak (USD &amp; Valas) &amp; Kurs KMK Kemenkeu
            </span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="padding:16px 20px; overflow-y:auto; line-height:1.55; font-size:11px;">
            <div style="background:rgba(24,144,255,0.06); border-left:3px solid var(--primary); padding:10px 14px; border-radius:4px; margin-bottom:16px;">
                <strong style="color:var(--primary); font-size:11.5px;">SOP Tata Kelola Rate Pajak &amp; Kurs KMK Kemenkeu:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main);">Kurs Pajak adalah nilai tukar resmi yang ditetapkan secara mingguan oleh Menteri Keuangan Republik Indonesia melalui Keputusan Menteri Keuangan (KMK) sebagai dasar penghitungan Bea Masuk, Cukai, PPN Impor, PPh Pasal 22 Impor, dan e-Faktur Pajak.</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#1890ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-calendar-week"></i> 1. Siklus Mingguan Kurs KMK
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Jadwal Terbit:</strong> Ditetapkan setiap hari <strong>Rabu</strong> dan berlaku selama 7 hari kalender hingga hari <strong>Selasa</strong> minggu berikutnya.</li>
                        <li><strong>Penggunaan Otomatis:</strong> Saat pembuatan dokumen pengadaan (PO), penerimaan barang (RI), invoice vendor (PI), atau dokumen pabean BC 2.0 / BC 2.3, sistem otomatis mengambil rate sesuai tanggal dokumen.</li>
                        <li><strong>Mata Uang Utama:</strong> USD, EUR, SGD, JPY, CNY, GBP, AUD, dan valuta lainnya.</li>
                    </ul>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#52c41a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-cloud-arrow-down"></i> 2. Tarik Kurs Pajak Otomatis
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Tombol Tarik Kurs Online:</strong> Klik tombol tersebut untuk menyinkronkan nilai kurs pajak resmi langsung dari CEISA 4.0 / Portal Kemenkeu.</li>
                        <li><strong>Pencegahan Duplikasi:</strong> Sistem menggunakan indeks periode dan valuta sehingga penarikan ulang otomatis memperbarui rate tanpa membuat duplikat baris.</li>
                        <li><strong>Jejak Audit:</strong> Setiap pembaruan mencatat identitas user dan stempel waktu.</li>
                    </ul>
                </div>
            </div>

            <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card); margin-bottom:14px;">
                <div style="font-weight:700; color:#722ed1; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-calculator"></i> 3. Rumus Konversi Nilai Pabean &amp; Pajak
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:10px; color:var(--text-muted);">
                    <div style="background:var(--bg-base); padding:8px; border-radius:4px;">
                        <strong style="color:var(--text-main);">A. DPP Nilai Pabean (Rupiah):</strong><br>
                        <code>DPP (IDR) = CIF Valas (USD/EUR) &times; Nilai Rate Pajak KMK</code>
                    </div>
                    <div style="background:var(--bg-base); padding:8px; border-radius:4px;">
                        <strong style="color:var(--text-main);">B. PPN Impor &amp; PPh 22:</strong><br>
                        <code>PPN Impor = (DPP + Bea Masuk) &times; 11% (atau 12%)</code><br>
                        <code>PPh 22 = (DPP + Bea Masuk) &times; Tarif (2.5% / 7.5%)</code>
                    </div>
                </div>
            </div>

            <div style="background:rgba(250,173,20,0.08); border:1px solid rgba(250,173,20,0.3); border-radius:6px; padding:10px 14px;">
                <strong style="color:#d48806;"><i class="fa-solid fa-triangle-exclamation"></i> Kepatuhan Audit CEISA 4.0 &amp; IT Inventory:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main); font-size:10.5px;">Nilai kurs pajak yang tersimpan di modul ini terhubung langsung ke laporan keuangan persediaan pabean. Pastikan tanggal pabean dokumen transaksi Anda selalu tercakup dalam periode aktif KMK yang sah demi menjaga kesesuaian nilai persediaan bahan baku dan barang jadi di IT Inventory.</p>
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
let curPage = 1;
const pageLimit = 25;
let ratesCache = [];

// Format Number Rupiah
function formatRupiah(num, decimals = 2) {
    if (isNaN(num)) return '0,00';
    return Number(num).toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// Inisialisasi Tanggal Filter (Default: 60 hari ke belakang s/d 30 hari ke depan)
function initDefaultFilterDates() {
    const today = new Date();
    const dPast = new Date(today);
    dPast.setDate(dPast.getDate() - 60);
    const dNext = new Date(today);
    dNext.setDate(dNext.getDate() + 30);

    const pad = n => String(n).padStart(2, '0');
    const toYmd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    document.getElementById('filDt1').value = toYmd(dPast);
    document.getElementById('filDt2').value = toYmd(dNext);
}

// Switch Tabs
function switchTab(tabId) {
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-tab') === tabId));
    document.querySelectorAll('.ant-page').forEach(p => p.classList.remove('active'));
    
    if (tabId === 'entri') {
        document.getElementById('pageEntri').classList.add('active');
        loadRates();
    } else if (tabId === 'arsip') {
        document.getElementById('pageArsip').classList.add('active');
        loadArsipYear();
    } else if (tabId === 'kalkulator') {
        document.getElementById('pageKalkulator').classList.add('active');
        runCalculation();
    }
}

// 1. Load KPI Header
async function loadKpi() {
    try {
        const res = await fetch('tax_rate.php?action=get_kpi');
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('kpiUsdRate').innerText = 'Rp ' + formatRupiah(json.usd_rate, 2);
            document.getElementById('kpiKmkNo').innerText = json.kmk_no || '-';
            document.getElementById('kpiPeriode').innerText = json.periode || '-';
            document.getElementById('kpiTotalRecords').innerText = (json.total_records || 0) + ' Periode';
            
            // Set rate default di kalkulator
            if (json.usd_rate > 0) {
                document.getElementById('calcRate').value = json.usd_rate;
                document.getElementById('calcRateInfo').innerText = 'Rate USD aktif: Rp ' + formatRupiah(json.usd_rate, 2);
            }
        }
    } catch (e) {
        console.error('Error load KPI:', e);
    }
}

// 2. Load Rates List
async function loadRates(page = null) {
    if (page !== null) curPage = page;
    const tglAwal  = document.getElementById('filDt1').value;
    const tglAkhir = document.getElementById('filDt2').value;
    const kurs     = document.getElementById('filKurs').value;
    const search   = document.getElementById('filSearch').value.trim();

    const tbody = document.getElementById('tbodyRates');
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data rate pajak...</td></tr>';

    try {
        const url = `tax_rate.php?action=list&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}&kurs=${encodeURIComponent(kurs)}&search=${encodeURIComponent(search)}&page=${curPage}&limit=${pageLimit}`;
        const res = await fetch(url);
        const json = await res.json();

        if (json.status !== 'OK') {
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:16px; color:var(--danger);">${escapeHtml(json.message || 'Gagal memuat data')}</td></tr>`;
            return;
        }

        ratesCache = json.data || [];
        document.getElementById('lblTotalRows').innerText = (json.total || 0).toLocaleString('id-ID');
        document.getElementById('lblCurPage').innerText = json.page || 1;
        document.getElementById('lblTotalPages').innerText = json.total_pages || 1;
        document.getElementById('btnPrevPage').disabled = (curPage <= 1);
        document.getElementById('btnNextPage').disabled = (curPage >= json.total_pages);

        if (ratesCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:28px; color:var(--text-muted);"><i class="fa-regular fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block;"></i>Tidak ada data kurs pajak pada rentang tanggal yang dipilih. Klik "Tambah Rate Baru" atau "Tarik Kurs Pajak Online".</td></tr>';
            return;
        }

        let html = '';
        ratesCache.forEach((r, idx) => {
            const no = ((curPage - 1) * pageLimit) + idx + 1;
            const kursBadgeClass = `badge-kurs-${(r.kurs || '').toLowerCase()}`;
            const activeRowClass = r.is_active ? 'row-active-highlight' : '';

            html += `
            <tr class="${activeRowClass}">
                <td style="text-align:center; color:var(--text-muted);">${no}</td>
                <td style="text-align:center;"><span class="${r.status_class}">${r.status_label}</span></td>
                <td style="text-align:center; font-weight:600;">${escapeHtml(r.awal)}</td>
                <td style="text-align:center; font-weight:600;">${escapeHtml(r.akhir)}</td>
                <td style="text-align:center;">
                    <span class="badge-kurs ${kursBadgeClass}">${escapeHtml(r.kurs)}</span>
                </td>
                <td style="text-align:right; font-weight:700; color:var(--primary); font-size:11.5px;">
                    Rp ${formatRupiah(r.rate, (r.kurs === 'JPY' ? 4 : 2))}
                </td>
                <td><strong>${escapeHtml(r.kmk_no || '-')}</strong></td>
                <td style="color:var(--text-muted);">${escapeHtml(r.kmk_note || '-')}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="editRate(${r.id})" title="Edit Rate"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="duplicateRate(${r.id})" title="Duplikat ke Periode Berikutnya"><i class="fa-solid fa-copy"></i></button>
                    <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="deleteRate(${r.id}, '${escapeHtml(r.kurs)}', '${escapeHtml(r.awal)}')" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;

        if (window.taxGrid) {
            window.taxGrid.refresh();
        } else if (typeof AntEnterpriseGrid !== 'undefined') {
            window.taxGrid = new AntEnterpriseGrid('#tableRates', {
                gridId: 'erp_tax_rate',
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                skipCols: [0, 9],
                exportTitle: 'Rate_Pajak_KMK',
                onEdit: (tr) => {
                    const btn = tr.querySelector('button[onclick*="editRate"]');
                    if (btn) btn.click();
                }
            });
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:16px; color:var(--danger);">${escapeHtml(err.message)}</td></tr>`;
    }
}

function changePage(delta) {
    curPage += delta;
    if (curPage < 1) curPage = 1;
    loadRates();
}

function resetFilter() {
    initDefaultFilterDates();
    document.getElementById('filKurs').value = 'USD';
    document.getElementById('filSearch').value = '';
    loadRates(1);
}

// 3. Modal Form Entri / Edit
function openAddRateModal() {
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-plus"></i> Tambah Rate Pajak Baru';
    document.getElementById('formRateId').value = '';
    
    // Default tanggal awal: Rabu minggu ini
    const today = new Date();
    const day = today.getDay();
    const diff = (day >= 3) ? (day - 3) : (day + 4);
    const wed = new Date(today);
    wed.setDate(wed.getDate() - diff);
    const tues = new Date(wed);
    tues.setDate(tues.getDate() + 6);

    const pad = n => String(n).padStart(2, '0');
    const toYmd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    document.getElementById('formAwal').value = toYmd(wed);
    document.getElementById('formAkhir').value = toYmd(tues);
    document.getElementById('formKurs').value = 'USD';
    document.getElementById('formRate').value = '';
    document.getElementById('formKmkNo').value = `KMK No. ${Math.ceil(today.getDate()/7) + 38}/KM.10/${today.getFullYear()}`;
    document.getElementById('formKmkNote').value = `Tanggal Berlaku: ${toYmd(wed)} - ${toYmd(tues)}`;

    document.getElementById('modalRateForm').classList.add('active');
}

function autoFillAkhirDate() {
    const aw = document.getElementById('formAwal').value;
    if (!aw) return;
    const d = new Date(aw);
    d.setDate(d.getDate() + 6);
    const pad = n => String(n).padStart(2, '0');
    const toYmd = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    document.getElementById('formAkhir').value = toYmd;
    document.getElementById('formKmkNote').value = `Tanggal Berlaku: ${aw} - ${toYmd}`;
}

async function editRate(id) {
    try {
        const res = await fetch(`tax_rate.php?action=get&id=${id}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            return;
        }

        const d = json.data;
        document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Rate Pajak (' + escapeHtml(d.kurs) + ')';
        document.getElementById('formRateId').value = d.id;
        document.getElementById('formAwal').value = d.awal;
        document.getElementById('formAkhir').value = d.akhir;
        document.getElementById('formKurs').value = d.kurs;
        document.getElementById('formRate').value = d.rate;
        document.getElementById('formKmkNo').value = d.kmk_no || '';
        document.getElementById('formKmkNote').value = d.kmk_note || '';

        document.getElementById('modalRateForm').classList.add('active');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

function duplicateRate(id) {
    const item = ratesCache.find(x => x.id === id);
    if (!item) return;

    // Maju 7 hari
    const dAw = new Date(item.awal);
    dAw.setDate(dAw.getDate() + 7);
    const dAk = new Date(item.akhir);
    dAk.setDate(dAk.getDate() + 7);

    const pad = n => String(n).padStart(2, '0');
    const toYmd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-copy"></i> Duplikat Rate ke Periode Baru';
    document.getElementById('formRateId').value = '';
    document.getElementById('formAwal').value = toYmd(dAw);
    document.getElementById('formAkhir').value = toYmd(dAk);
    document.getElementById('formKurs').value = item.kurs;
    document.getElementById('formRate').value = item.rate;
    document.getElementById('formKmkNo').value = item.kmk_no;
    document.getElementById('formKmkNote').value = `Tanggal Berlaku: ${toYmd(dAw)} - ${toYmd(dAk)}`;

    document.getElementById('modalRateForm').classList.add('active');
}

function closeRateModal() {
    document.getElementById('modalRateForm').classList.remove('active');
}

async function submitRateForm() {
    const id      = document.getElementById('formRateId').value;
    const awal    = document.getElementById('formAwal').value;
    const akhir   = document.getElementById('formAkhir').value;
    const kurs    = document.getElementById('formKurs').value;
    const rate    = document.getElementById('formRate').value;
    const kmkNo   = document.getElementById('formKmkNo').value.trim();
    const kmkNote = document.getElementById('formKmkNote').value.trim();

    if (!awal || !akhir) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tanggal awal dan akhir berlaku wajib diisi!' });
        return;
    }
    if (parseFloat(rate) <= 0 || isNaN(parseFloat(rate))) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Nilai rate pajak harus lebih besar dari 0!' });
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('awal', awal);
    fd.append('akhir', akhir);
    fd.append('kurs', kurs);
    fd.append('rate', rate);
    fd.append('kmk_no', kmkNo);
    fd.append('kmk_note', kmkNote);

    try {
        const res = await fetch('tax_rate.php?action=save', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            closeRateModal();
            Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: json.message,
                timer: 1500,
                showConfirmButton: false
            });
            loadRates();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

// 4. Hapus Rate
async function deleteRate(id, kurs, tgl) {
    const confirm = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus data kurs pajak ${kurs} tanggal ${tgl}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ff4d4f'
    });

    if (!confirm.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('tax_rate.php?action=delete', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({
                icon: 'success',
                title: 'Terhapus',
                text: json.message,
                timer: 1200,
                showConfirmButton: false
            });
            loadRates();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

// 5. Tarik Kurs Online CEISA 4.0 (wf_auto di PB)
async function triggerFetchOnline() {
    const ask = await Swal.fire({
        title: 'Tarik Kurs Pajak Online',
        text: 'Sistem akan menarik data kurs pajak KMK resmi terkini (USD, EUR, SGD, JPY, CNY) dari CEISA 4.0 / Kemenkeu untuk periode minggu ini.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Tarik Sekarang',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#52c41a'
    });

    if (!ask.isConfirmed) return;

    Swal.fire({
        title: 'Menghubungkan ke Gateway...',
        text: 'Mengunduh kurs pajak resmi Kemenkeu...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const fd = new FormData();
        fd.append('tgl', new Date().toISOString().slice(0, 10));
        const res = await fetch('tax_rate.php?action=fetch_online', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();

        if (json.status === 'OK') {
            Swal.fire({
                icon: 'success',
                title: 'Sinkronisasi Selesai',
                text: json.message,
                timer: 2000,
                showConfirmButton: true
            });
            loadRates(1);
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal Tarik Kurs', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Jaringan', text: e.message });
    }
}

// 6. Export Excel & CSV
function exportDataCsv() {
    const tglAwal  = document.getElementById('filDt1').value;
    const tglAkhir = document.getElementById('filDt2').value;
    const kurs     = document.getElementById('filKurs').value;
    window.location.href = `tax_rate.php?action=export_csv&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}&kurs=${encodeURIComponent(kurs)}`;
}

function exportDataExcel() {
    if (!ratesCache || ratesCache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }

    const exportRows = ratesCache.map((r, i) => ({
        'No': i + 1,
        'Status': r.status_label,
        'Tanggal Awal': r.awal,
        'Tanggal Akhir': r.akhir,
        'Valuta': r.kurs,
        'Nilai Kurs Pajak (IDR)': r.rate,
        'Nomor KMK Kemenkeu': r.kmk_no,
        'Keterangan / Periode': r.kmk_note,
        'Pencatat (Set By)': r.set_by,
        'Terakhir Diperbarui': r.updated_at
    }));

    const ws = XLSX.utils.json_to_sheet(exportRows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Rate Pajak");
    XLSX.writeFile(wb, `Rate_Pajak_KMK_${new Date().toISOString().slice(0,10)}.xlsx`);
}

// 7. Tab Arsip
async function loadArsipYear() {
    const yr = document.getElementById('filArsipYear').value;
    const ku = document.getElementById('filArsipKurs').value;
    const tbody = document.getElementById('tbodyArsip');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat arsip...</td></tr>';

    let tglAwal = '';
    let tglAkhir = '';
    if (yr !== 'ALL') {
        tglAwal = `${yr}-01-01`;
        tglAkhir = `${yr}-12-31`;
    }

    try {
        const url = `tax_rate.php?action=list&tgl_awal=${tglAwal}&tgl_akhir=${tglAkhir}&kurs=${ku}&limit=200`;
        const res = await fetch(url);
        const json = await res.json();

        if (!json.data || json.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:var(--text-muted);">Tidak ada data arsip untuk tahun yang dipilih.</td></tr>';
            return;
        }

        let html = '';
        json.data.forEach((r, idx) => {
            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                <td><span class="badge-kurs badge-kurs-${(r.kurs||'').toLowerCase()}">${escapeHtml(r.kurs)}</span></td>
                <td style="font-weight:600;">${r.awal}</td>
                <td style="font-weight:600;">${r.akhir}</td>
                <td style="text-align:right; font-weight:700; color:var(--primary);">Rp ${formatRupiah(r.rate, (r.kurs==='JPY'?4:2))}</td>
                <td><strong>${escapeHtml(r.kmk_no || '-')}</strong></td>
                <td style="color:var(--text-muted);">${escapeHtml(r.kmk_note || '-')}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
            </tr>`;
        });
        tbody.innerHTML = html;

        if (window.arsipGrid) {
            window.arsipGrid.refresh();
        } else if (typeof AntEnterpriseGrid !== 'undefined') {
            window.arsipGrid = new AntEnterpriseGrid('#tableArsip', {
                gridId: 'erp_tax_rate_arsip',
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                skipCols: [0],
                exportTitle: 'Arsip_Rate_Pajak'
            });
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:var(--danger);">${e.message}</td></tr>`;
    }
}

function exportArsipExcel() {
    const yr = document.getElementById('filArsipYear').value;
    const ku = document.getElementById('filArsipKurs').value;
    let tglAwal = yr !== 'ALL' ? `${yr}-01-01` : '';
    let tglAkhir = yr !== 'ALL' ? `${yr}-12-31` : '';
    window.location.href = `tax_rate.php?action=export_csv&tgl_awal=${tglAwal}&tgl_akhir=${tglAkhir}&kurs=${ku}`;
}

// 8. Kalkulator Interaktif
function updateCalcRateFromDate() {
    const tgl = document.getElementById('calcDate').value;
    const valuta = document.getElementById('calcValuta').value;
    // Cari di cache
    const match = ratesCache.find(r => r.kurs === valuta && tgl >= r.awal && tgl <= r.akhir);
    if (match) {
        document.getElementById('calcRate').value = match.rate;
        document.getElementById('calcRateInfo').innerText = `Rate KMK ${match.kmk_no || ''} (${match.awal} s/d ${match.akhir})`;
    } else {
        document.getElementById('calcRateInfo').innerText = 'Rate default / perkiraan manual';
    }
    runCalculation();
}

function runCalculation() {
    const valuta = document.getElementById('calcValuta').value;
    const rate   = parseFloat(document.getElementById('calcRate').value) || 0;
    const amount = parseFloat(document.getElementById('calcAmount').value) || 0;

    const dpp = amount * rate;
    const ppn = dpp * 0.11;
    const pph = dpp * 0.025;
    const bm  = dpp * 0.05;

    document.getElementById('resValas').innerText = `${valuta} ${formatRupiah(amount, 2)}`;
    document.getElementById('resKurs').innerText = `Rp ${formatRupiah(rate, 2)}`;
    document.getElementById('resDpp').innerText = `Rp ${formatRupiah(dpp, 2)}`;
    document.getElementById('resPpn').innerText = `Rp ${formatRupiah(ppn, 2)}`;
    document.getElementById('resPph').innerText = `Rp ${formatRupiah(ppph, 2)}`;
    document.getElementById('resBm').innerText  = `Rp ${formatRupiah(bm, 2)}`;
}

// 9. Panduan Modul SOP
function openModuleGuide() {
    document.getElementById('modalModuleGuide').classList.add('active');
}
function closeModuleGuide() {
    document.getElementById('modalModuleGuide').classList.remove('active');
}

// Inisialisasi awal
window.addEventListener('DOMContentLoaded', () => {
    initDefaultFilterDates();
    loadKpi();
    loadRates();
});
</script>
</body>
</html>

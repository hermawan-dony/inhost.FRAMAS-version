<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Rate Bank Indonesia (BI)
 * ============================================================================
 * Acuan Asli PowerBuilder: w_birate.srw, w_birate_tmp.srw, d_act_birate.srd
 * Pengelolaan Kurs Transaksi Bank Indonesia Harian (Kurs Jual, Beli, Tengah),
 * Multi-Valuta (26 Valuta Resmi BI), Tarik Kurs Online dari Portal BI (bi.go.id),
 * Kalkulator Konversi Valas Interaktif, dan Standardisasi Ant Design Style (Identik Tax Rate).
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
// AUTO-HEAL: Pastikan Tabel bc_act_birate Tersedia Lengkap
// -------------------------------------------------------------------------
function ensureBiRateTable($koneksi, $escCmp) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_act_birate'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_birate` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `tanggal` date NOT NULL,
                `kurs` varchar(10) NOT NULL DEFAULT 'USD',
                `matauang` varchar(100) DEFAULT '',
                `satuan` decimal(10,2) DEFAULT 1.00,
                `jual` decimal(15,2) DEFAULT 0.00,
                `beli` decimal(15,2) DEFAULT 0.00,
                `tengah` decimal(15,2) DEFAULT 0.00,
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_tgl_kurs` (`tanggal`, `kurs`),
                KEY `idx_cmp_id` (`cmp_id`),
                KEY `idx_tanggal` (`tanggal`),
                KEY `idx_kurs` (`kurs`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Auto-heal kolom jika sudah ada dari versi lama
            $colId = $koneksi->query("SHOW COLUMNS FROM bc_act_birate LIKE 'id'");
            if (!$colId || $colId->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_act_birate ADD COLUMN id INT AUTO_INCREMENT UNIQUE FIRST");
            }
            $colCmp = $koneksi->query("SHOW COLUMNS FROM bc_act_birate LIKE 'cmp_id'");
            if (!$colCmp || $colCmp->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_act_birate ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT ''");
            }
            $colCreated = $koneksi->query("SHOW COLUMNS FROM bc_act_birate LIKE 'created_at'");
            if (!$colCreated || $colCreated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_act_birate ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            $colUpdated = $koneksi->query("SHOW COLUMNS FROM bc_act_birate LIKE 'updated_at'");
            if (!$colUpdated || $colUpdated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_act_birate ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
            @$koneksi->query("ALTER TABLE bc_act_birate MODIFY COLUMN set_by VARCHAR(100) DEFAULT ''");
            @$koneksi->query("ALTER TABLE bc_act_birate MODIFY COLUMN matauang VARCHAR(100) DEFAULT ''");
            @$koneksi->query("ALTER TABLE bc_act_birate MODIFY COLUMN satuan DECIMAL(10,2) DEFAULT 1.00");
            @$koneksi->query("ALTER TABLE bc_act_birate MODIFY COLUMN jual DECIMAL(15,2) DEFAULT 0.00");
            @$koneksi->query("ALTER TABLE bc_act_birate MODIFY COLUMN beli DECIMAL(15,2) DEFAULT 0.00");
            @$koneksi->query("ALTER TABLE bc_act_birate MODIFY COLUMN tengah DECIMAL(15,2) DEFAULT 0.00");
        }
    } catch (\Throwable $ex) {
        // Abaikan
    }

    // Buatkan seed awal jika data masih kosong
    $cnt = $koneksi->query("SELECT COUNT(*) FROM bc_act_birate")->fetch_row()[0] ?? 0;
    if ((int)$cnt === 0) {
        $seeds = [
            ['2026-10-30', 'USD', 'United States Dollar', 1, 18218.64, 18037.36, 18128.00, 'SYSTEM - Auto Init'],
            ['2026-10-30', 'EUR', 'Euro', 1, 20304.67, 20099.03, 20201.85, 'SYSTEM - Auto Init'],
            ['2026-10-30', 'SGD', 'Singapore Dollar', 1, 14227.75, 14078.49, 14153.12, 'SYSTEM - Auto Init'],
            ['2026-10-30', 'JPY', 'Japanese Yen', 100, 11543.94, 11427.62, 11485.78, 'SYSTEM - Auto Init'],
            ['2026-10-30', 'CNY', 'China Yuan', 1, 2735.12, 2707.62, 2721.37, 'SYSTEM - Auto Init'],
            ['2026-10-30', 'GBP', 'British Pound', 1, 24157.07, 23912.44, 24034.76, 'SYSTEM - Auto Init'],
            ['2026-10-30', 'AUD', 'Australian Dollar', 1, 12833.21, 12703.71, 12768.46, 'SYSTEM - Auto Init'],
            ['2026-10-30', 'KRW', 'South Korean Won', 1, 13.56, 13.41, 13.49, 'SYSTEM - Auto Init'],
            ['2026-10-12', 'USD', 'United States Dollar', 1, 17973.42, 17794.58, 17884.00, 'SYSTEM - Auto Init'],
            ['2026-10-12', 'EUR', 'Euro', 1, 20187.75, 19985.09, 20086.42, 'SYSTEM - Auto Init'],
            ['2026-10-12', 'SGD', 'Singapore Dollar', 1, 14047.22, 13899.84, 13973.53, 'SYSTEM - Auto Init'],
            ['2026-10-12', 'JPY', 'Japanese Yen', 100, 11351.87, 11238.21, 11295.04, 'SYSTEM - Auto Init'],
            ['2026-10-12', 'CNY', 'China Yuan', 1, 2685.37, 2658.53, 2671.95, 'SYSTEM - Auto Init']
        ];

        foreach ($seeds as $s) {
            $koneksi->query("INSERT IGNORE INTO bc_act_birate (cmp_id, tanggal, kurs, matauang, satuan, jual, beli, tengah, set_by)
                             VALUES ('$escCmp', '{$s[0]}', '{$s[1]}', '{$s[2]}', {$s[3]}, {$s[4]}, {$s[5]}, {$s[6]}, '{$s[7]}')");
        }
    }
}
ensureBiRateTable($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. GET MINI DASHBOARD KPI
if ($action === 'get_kpi') {
    header('Content-Type: application/json; charset=utf-8');

    // Cari rate USD BI terbaru
    $qUsd = $koneksi->query("SELECT * FROM bc_act_birate WHERE kurs = 'USD' ORDER BY tanggal DESC LIMIT 1");
    
    $activeUsdMid  = 0;
    $activeUsdJual = 0;
    $activeUsdBeli = 0;
    $activeDate    = '-';

    if ($qUsd && $r = $qUsd->fetch_assoc()) {
        $activeUsdMid  = (float)$r['tengah'];
        $activeUsdJual = (float)$r['jual'];
        $activeUsdBeli = (float)$r['beli'];
        $activeDate    = $r['tanggal'];
    }

    $cntTotal  = $koneksi->query("SELECT COUNT(*) FROM bc_act_birate")->fetch_row()[0] ?? 0;
    $cntValuta = $koneksi->query("SELECT COUNT(DISTINCT kurs) FROM bc_act_birate")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status'          => 'OK',
        'usd_mid'         => $activeUsdMid,
        'usd_jual'        => $activeUsdJual,
        'usd_beli'        => $activeUsdBeli,
        'active_date'     => $activeDate,
        'active_date_fmt' => ($activeDate !== '-' ? date('d M Y', strtotime($activeDate)) : '-'),
        'total_records'   => (int)$cntTotal,
        'total_valuta'    => (int)$cntValuta
    ]);
    exit;
}

// 2. LIST DATA KURS BI (FILTERING & PAGINATION)
if ($action === 'list' || $action === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');

    $tglAwal  = trim($_GET['tgl_awal'] ?? ($_GET['start_date'] ?? ''));
    $tglAkhir = trim($_GET['tgl_akhir'] ?? ($_GET['end_date'] ?? ''));
    $kurs     = trim($_GET['kurs'] ?? '');
    $search   = trim($_GET['search'] ?? '');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $limit    = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset   = ($page - 1) * $limit;

    $where = [];

    if (!empty($tglAwal) && !empty($tglAkhir)) {
        $ta = $koneksi->real_escape_string($tglAwal);
        $tk = $koneksi->real_escape_string($tglAkhir);
        $where[] = "tanggal BETWEEN '$ta' AND '$tk'";
    } elseif (!empty($tglAwal)) {
        $ta = $koneksi->real_escape_string($tglAwal);
        $where[] = "tanggal >= '$ta'";
    } elseif (!empty($tglAkhir)) {
        $tk = $koneksi->real_escape_string($tglAkhir);
        $where[] = "tanggal <= '$tk'";
    }

    if (!empty($kurs) && $kurs !== 'ALL') {
        $kEsc = $koneksi->real_escape_string($kurs);
        $where[] = "kurs = '$kEsc'";
    }

    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(kurs LIKE '%$sEsc%' OR matauang LIKE '%$sEsc%' OR set_by LIKE '%$sEsc%')";
    }

    $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) FROM bc_act_birate $whereSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    $sql = "SELECT id, cmp_id, tanggal, kurs, matauang, satuan, jual, beli, tengah, set_by, updated_at 
            FROM bc_act_birate 
            $whereSql 
            ORDER BY tanggal DESC, kurs ASC 
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);

    $today = date('Y-m-d');
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $isToday = ($r['tanggal'] === $today);
            $rows[] = [
                'id'         => (int)$r['id'],
                'tanggal'    => $r['tanggal'],
                'kurs'       => strtoupper($r['kurs']),
                'matauang'   => $r['matauang'] ?: $r['kurs'],
                'satuan'     => (float)$r['satuan'],
                'jual'       => (float)$r['jual'],
                'beli'       => (float)$r['beli'],
                'tengah'     => (float)$r['tengah'],
                'set_by'     => $r['set_by'] ?: 'SYSTEM',
                'updated_at' => $r['updated_at'],
                'is_today'   => $isToday
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

// 3. GET SINGLE RATE BY ID
if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $q = $koneksi->query("SELECT * FROM bc_act_birate WHERE id = $id LIMIT 1");
    if ($q && $r = $q->fetch_assoc()) {
        echo json_encode(['status' => 'OK', 'data' => $r]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data tidak ditemukan']);
    }
    exit;
}

// 4. SAVE (TAMBAH / EDIT KURS BI MANUAL)
if ($action === 'save' || $action === 'save_entry') {
    header('Content-Type: application/json; charset=utf-8');
    $id       = (int)($_POST['id'] ?? 0);
    $tanggal  = trim($_POST['tanggal'] ?? '');
    $kurs     = strtoupper(trim($_POST['kurs'] ?? 'USD'));
    $matauang = trim($_POST['matauang'] ?? '');
    $satuan   = max(1, (float)str_replace(',', '', trim($_POST['satuan'] ?? '1')));
    $jual     = (float)str_replace(',', '', trim($_POST['jual'] ?? '0'));
    $beli     = (float)str_replace(',', '', trim($_POST['beli'] ?? '0'));
    $tengah   = (float)str_replace(',', '', trim($_POST['tengah'] ?? '0'));

    if (empty($tanggal) || empty($kurs)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tanggal dan Kode Valuta wajib diisi.']);
        exit;
    }

    if ($tengah <= 0 && ($jual > 0 || $beli > 0)) {
        $tengah = round(($jual + $beli) / 2, 2);
    }

    if ($tengah <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nilai Kurs Tengah BI harus lebih besar dari 0.']);
        exit;
    }

    $tglEsc  = $koneksi->real_escape_string($tanggal);
    $kursEsc = $koneksi->real_escape_string($kurs);
    $namaEsc = $koneksi->real_escape_string($matauang ?: $kurs);
    $setBy   = $koneksi->real_escape_string($currentUser . ' - ' . date('Y-m-d H:i'));

    if ($id > 0) {
        $sql = "UPDATE bc_act_birate SET 
                tanggal  = '$tglEsc', 
                kurs     = '$kursEsc', 
                matauang = '$namaEsc', 
                satuan   = $satuan, 
                jual     = $jual, 
                beli     = $beli, 
                tengah   = $tengah, 
                set_by   = '$setBy'
                WHERE id = $id";
        if ($koneksi->query($sql)) {
            echo json_encode(['status' => 'OK', 'message' => 'Kurs Bank Indonesia berhasil diperbarui.']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal memperbarui data: ' . $koneksi->error]);
        }
    } else {
        $sql = "INSERT INTO bc_act_birate (cmp_id, tanggal, kurs, matauang, satuan, jual, beli, tengah, set_by)
                VALUES ('$escCmp', '$tglEsc', '$kursEsc', '$namaEsc', $satuan, $jual, $beli, $tengah, '$setBy')
                ON DUPLICATE KEY UPDATE 
                matauang = VALUES(matauang), 
                satuan   = VALUES(satuan), 
                jual     = VALUES(jual), 
                beli     = VALUES(beli), 
                tengah   = VALUES(tengah), 
                set_by   = VALUES(set_by)";
        if ($koneksi->query($sql)) {
            echo json_encode(['status' => 'OK', 'message' => 'Kurs Bank Indonesia baru berhasil disimpan.']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan data: ' . $koneksi->error]);
        }
    }
    exit;
}

// 5. DELETE KURS BI
if ($action === 'delete' || $action === 'delete_entry') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0 && !empty($_POST['ids'])) {
        $ids = is_array($_POST['ids']) ? $_POST['ids'] : [$_POST['ids']];
        $idList = implode(',', array_filter(array_map('intval', $ids)));
        if (!empty($idList)) {
            $del = $koneksi->query("DELETE FROM bc_act_birate WHERE id IN ($idList)");
            if ($del) {
                echo json_encode(['status' => 'OK', 'message' => 'Data Kurs BI terpilih berhasil dihapus.']);
            } else {
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
            }
            exit;
        }
    }

    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID data tidak valid.']);
        exit;
    }

    $del = $koneksi->query("DELETE FROM bc_act_birate WHERE id = $id");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => 'Data Kurs BI berhasil dihapus.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus data: ' . $koneksi->error]);
    }
    exit;
}

// 6. TARIK KURS ONLINE LANGSUNG DARI BANK INDONESIA (Scraper Portal bi.go.id)
if ($action === 'fetch_online' || $action === 'scrape_bi') {
    header('Content-Type: application/json; charset=utf-8');
    $targetDate = trim($_POST['tgl'] ?? ($_POST['target_date'] ?? date('Y-m-d')));
    if (empty($targetDate)) $targetDate = date('Y-m-d');

    // Gunakan fungsi scraper portal Bank Indonesia di /birate.php
    require_once __DIR__ . '/../birate.php';
    $scrapeRes = tpberp_scrape_bi_rate($targetDate);

    if (empty($scrapeRes['success']) || empty($scrapeRes['data'])) {
        echo json_encode([
            'status'  => 'ERROR',
            'message' => $scrapeRes['error'] ?? 'Gagal menarik kurs transaksi dari Bank Indonesia (bi.go.id). Pastikan server terhubung ke internet dan tanggal yang dipilih hari bursa aktif.'
        ]);
        exit;
    }

    $savedCount = 0;
    $setBy = 'SYSTEM (Portal BI) - ' . date('Ymd Hi');
    $escSetBy = $koneksi->real_escape_string($setBy);

    foreach ($scrapeRes['data'] as $item) {
        $tglEsc  = $koneksi->real_escape_string($item['tanggal']);
        $kursEsc = $koneksi->real_escape_string($item['kurs']);
        $namaEsc = $koneksi->real_escape_string($item['matauang']);
        $satuan  = (float)$item['satuan'];
        $jual    = (float)$item['jual'];
        $beli    = (float)$item['beli'];
        $tengah  = (float)$item['tengah'];

        $sql = "INSERT INTO bc_act_birate (cmp_id, tanggal, kurs, matauang, satuan, jual, beli, tengah, set_by)
                VALUES ('$escCmp', '$tglEsc', '$kursEsc', '$namaEsc', $satuan, $jual, $beli, $tengah, '$escSetBy')
                ON DUPLICATE KEY UPDATE 
                matauang = VALUES(matauang), 
                satuan   = VALUES(satuan), 
                jual     = VALUES(jual), 
                beli     = VALUES(beli), 
                tengah   = VALUES(tengah), 
                set_by   = VALUES(set_by)";
        if ($koneksi->query($sql)) {
            $savedCount++;
        }
    }

    echo json_encode([
        'status'         => 'OK',
        'message'        => "Berhasil menarik $savedCount kurs valuta transaksi Bank Indonesia untuk tanggal {$scrapeRes['tanggal']}.",
        'tanggal'        => $scrapeRes['tanggal'],
        'tanggal_bi_fmt' => $scrapeRes['tanggal_bi_fmt'] ?? '',
        'saved_count'    => $savedCount
    ]);
    exit;
}

// 7. EXPORT DATA KE CSV
if ($action === 'export_csv') {
    $tglAwal  = trim($_GET['tgl_awal'] ?? '');
    $tglAkhir = trim($_GET['tgl_akhir'] ?? '');
    $kurs     = trim($_GET['kurs'] ?? '');

    $where = [];
    if (!empty($tglAwal) && !empty($tglAkhir)) {
        $ta = $koneksi->real_escape_string($tglAwal);
        $tk = $koneksi->real_escape_string($tglAkhir);
        $where[] = "tanggal BETWEEN '$ta' AND '$tk'";
    }
    if (!empty($kurs) && $kurs !== 'ALL') {
        $where[] = "kurs = '" . $koneksi->real_escape_string($kurs) . "'";
    }
    $wSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $q = $koneksi->query("SELECT tanggal, kurs, matauang, satuan, jual, beli, tengah, set_by FROM bc_act_birate $wSql ORDER BY tanggal DESC, kurs ASC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Kurs_Bank_Indonesia_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tanggal', 'Kode Valuta', 'Nama Mata Uang', 'Satuan', 'Kurs Jual (IDR)', 'Kurs Beli (IDR)', 'Kurs Tengah (IDR)', 'Diinput Oleh']);

    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($out, [
                $r['tanggal'],
                $r['kurs'],
                $r['matauang'],
                $r['satuan'],
                number_format((float)$r['jual'], 2, '.', ''),
                number_format((float)$r['beli'], 2, '.', ''),
                number_format((float)$r['tengah'], 2, '.', ''),
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
    <title>Rate Bank Indonesia (BI) - <?= htmlspecialchars($activeCmpName) ?></title>
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

        /* Filter Box */
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
        .badge-kurs-gbp { background: #e6fffb; color: #08979c; border: 1px solid #87e8de; }
        .badge-kurs-aud { background: #fcffe6; color: #7cb305; border: 1px solid #eaff8f; }
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
                <i class="fa-solid fa-building-columns" style="color:var(--primary);"></i>
                <span>Rate Bank Indonesia (BI) - Kurs Transaksi Resmi</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span id="syncStatusText" style="font-size:10px; color:var(--text-muted);">
                    <i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Master Kurs Transaksi BI
                </span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP">
                    <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i>
                </button>
            </div>
        </div>

        <!-- Mini Dashboard KPI (Persis Pola Tax Rate & Purchasing) -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);">
                    <i class="fa-solid fa-dollar-sign"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiUsdMid">Rp 0</div>
                    <div class="kpi-label">Kurs Tengah USD BI Terkini</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);">
                    <i class="fa-solid fa-scale-balanced"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiUsdSpread" style="font-size:11px; font-weight:700;">J: Rp 0 | B: Rp 0</div>
                    <div class="kpi-label">Kurs Jual &amp; Beli USD BI</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);">
                    <i class="fa-regular fa-calendar-check"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiActiveDate" style="font-size:11px; font-weight:600;">-</div>
                    <div class="kpi-label">Tanggal Rilis Kurs Bank Indonesia</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;">
                    <i class="fa-solid fa-vault"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiTotalRecords">0 Catatan</div>
                    <div class="kpi-label">Total Arsip Kurs (26 Valuta)</div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs (3 Tab Sesuai Standardisasi) -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="entri" onclick="switchTab('entri')">
                <i class="fa-solid fa-pen-to-square"></i> Entri &amp; Kelola Kurs BI
            </button>
            <button type="button" class="ant-tab-btn" data-tab="arsip" onclick="switchTab('arsip')">
                <i class="fa-solid fa-box-archive"></i> Riwayat &amp; Arsip Lengkap
            </button>
            <button type="button" class="ant-tab-btn" data-tab="kalkulator" onclick="switchTab('kalkulator')">
                <i class="fa-solid fa-calculator"></i> Kalkulator Konversi Kurs BI
            </button>
        </div>

        <!-- Toolbar Aksi Ant Design -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openAddRateModal()">
                <i class="fa-solid fa-plus"></i> Tambah Kurs Baru
            </button>
            <button type="button" class="ant-btn ant-btn-success" onclick="openFetchOnlineModal()" title="Ambil Kurs Transaksi Resmi Otomatis dari Portal Bank Indonesia">
                <i class="fa-solid fa-cloud-arrow-down"></i> Tarik Kurs BI Online
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

        <!-- TAB 1: ENTRI & KELOLA KURS BI -->
        <section class="ant-page active" id="pageEntri">
            <!-- Filter Bar Persis Tax Rate -->
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
                        <option value="ALL">Semua Valuta (26)</option>
                        <option value="USD" selected>USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="SGD">SGD - Singapore Dollar</option>
                        <option value="JPY">JPY - Japanese Yen</option>
                        <option value="CNY">CNY - Chinese Yuan</option>
                        <option value="GBP">GBP - British Pound</option>
                        <option value="AUD">AUD - Australian Dollar</option>
                        <option value="KRW">KRW - Korean Won</option>
                        <option value="MYR">MYR - Malaysian Ringgit</option>
                        <option value="THB">THB - Thai Baht</option>
                        <option value="HKD">HKD - Hong Kong Dollar</option>
                        <option value="CHF">CHF - Swiss Franc</option>
                        <option value="CAD">CAD - Canadian Dollar</option>
                        <option value="NZD">NZD - New Zealand Dollar</option>
                        <option value="SAR">SAR - Saudi Arabian Riyal</option>
                    </select>
                </div>
                <div class="filter-group">
                    <input type="text" class="ant-input" id="filSearch" placeholder="Cari Kode / Nama Mata Uang..." style="width:160px;" onkeyup="if(event.key==='Enter') loadRates(1);">
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
                            <th style="width:100px; text-align:center;">Tanggal</th>
                            <th style="width:75px; text-align:center;">Valuta</th>
                            <th style="width:180px;">Nama Mata Uang</th>
                            <th style="width:70px; text-align:center;">Satuan</th>
                            <th style="width:130px; text-align:right;">Kurs Jual (IDR)</th>
                            <th style="width:130px; text-align:right;">Kurs Beli (IDR)</th>
                            <th style="width:140px; text-align:right;">Kurs Tengah (IDR)</th>
                            <th style="width:170px;">Pencatat (Set By)</th>
                            <th style="width:90px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyRates">
                        <tr><td colspan="10" style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data Kurs Bank Indonesia...</td></tr>
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
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="SGD">SGD - Singapore Dollar</option>
                        <option value="JPY">JPY - Japanese Yen</option>
                        <option value="CNY">CNY - Chinese Yuan</option>
                        <option value="GBP">GBP - British Pound</option>
                        <option value="AUD">AUD - Australian Dollar</option>
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
                            <th style="width:100px; text-align:center;">Tanggal</th>
                            <th style="width:80px; text-align:center;">Valuta</th>
                            <th style="width:200px;">Nama Mata Uang</th>
                            <th style="width:70px; text-align:center;">Satuan</th>
                            <th style="width:130px; text-align:right;">Kurs Jual (IDR)</th>
                            <th style="width:130px; text-align:right;">Kurs Beli (IDR)</th>
                            <th style="width:140px; text-align:right;">Kurs Tengah (IDR)</th>
                            <th style="width:180px;">Jejak Audit</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyArsip">
                        <tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);">Silakan pilih filter tahun untuk melihat arsip kurs BI.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- TAB 3: KALKULATOR KONVERSI KURS BI -->
        <section class="ant-page" id="pageKalkulator">
            <div class="calc-panel">
                <div class="calc-card">
                    <div style="font-weight:700; color:var(--primary); font-size:12px; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-calculator"></i> Parameter Transaksi Valuta Bank Indonesia
                    </div>
                    <div class="fld-row">
                        <label>Pilih Mata Uang (Valuta):</label>
                        <select class="ant-input" id="calcValuta" onchange="updateCalcRateFromDate()">
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="SGD">SGD - Singapore Dollar</option>
                            <option value="JPY">JPY - Japanese Yen (100 JPY)</option>
                            <option value="CNY">CNY - Chinese Yuan</option>
                            <option value="GBP">GBP - British Pound</option>
                            <option value="AUD">AUD - Australian Dollar</option>
                            <option value="KRW">KRW - Korean Won</option>
                        </select>
                    </div>
                    <div class="fld-row">
                        <label>Tanggal Transaksi BI:</label>
                        <input type="date" class="ant-input" id="calcDate" value="<?= date('Y-m-d') ?>" onchange="updateCalcRateFromDate()">
                    </div>
                    <div class="fld-row">
                        <label>Tipe Kurs Bank Indonesia yang Digunakan:</label>
                        <select class="ant-input" id="calcRateType" onchange="runCalculation()">
                            <option value="tengah" selected>Kurs Tengah (Rata-rata Jual &amp; Beli)</option>
                            <option value="jual">Kurs Jual Bank (Beli Valas dari Bank)</option>
                            <option value="beli">Kurs Beli Bank (Jual Valas ke Bank)</option>
                        </select>
                    </div>
                    <div class="fld-row">
                        <label>Nilai Kurs Terpilih (IDR):</label>
                        <input type="number" step="0.01" class="ant-input" id="calcRate" value="18128.00" oninput="runCalculation()">
                        <span style="font-size:9.5px; color:var(--text-muted);" id="calcRateInfo">Kurs Tengah BI terkini</span>
                    </div>
                    <div class="fld-row">
                        <label>Nilai Transaksi Valas (Amount):</label>
                        <input type="number" step="0.01" class="ant-input" id="calcAmount" value="10000.00" oninput="runCalculation()" style="font-weight:700; font-size:12px;">
                    </div>
                    <div class="fld-row" style="margin-top:10px;">
                        <button type="button" class="ant-btn ant-btn-primary" onclick="runCalculation()">
                            <i class="fa-solid fa-bolt"></i> Hitung Konversi Kurs Sekarang
                        </button>
                    </div>
                </div>

                <div class="calc-card" style="background:var(--table-head-bg);">
                    <div style="font-weight:700; color:#52c41a; font-size:12px; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-receipt"></i> Hasil Konversi Transaksi Bank Indonesia (IDR)
                    </div>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <div style="display:flex; justify-content:space-between; padding-bottom:6px; border-bottom:1px dashed var(--border-color);">
                            <span style="color:var(--text-muted);">Nilai Valas Transaksi:</span>
                            <strong id="resValas">USD 10.000,00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding-bottom:6px; border-bottom:1px dashed var(--border-color);">
                            <span style="color:var(--text-muted);">Kurs Konversi Digunakan:</span>
                            <strong id="resKurs">Rp 18.128,00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:2px solid var(--primary);">
                            <span style="font-weight:700; color:var(--text-main);">Total Nilai Rupiah (IDR):</span>
                            <strong style="color:var(--primary); font-size:13px;" id="resTotalIdr">Rp 181.280.000,00</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted);">
                            <span>Jika Menggunakan Kurs Jual Bank:</span>
                            <span id="resAltJual">Rp 182.186.400,00</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted);">
                            <span>Jika Menggunakan Kurs Beli Bank:</span>
                            <span id="resAltBeli">Rp 180.373.600,00</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted);">
                            <span>Selisih Kurs Jual vs Beli (Spread):</span>
                            <span id="resSpread" style="color:var(--warning); font-weight:600;">Rp 1.812.800,00</span>
                        </div>
                        <div style="margin-top:8px; padding:10px; background:rgba(24,144,255,0.06); border-radius:4px; font-size:9.5px; color:var(--text-muted);">
                            <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i> Kurs Transaksi Bank Indonesia digunakan sebagai referensi pembukuan akuntansi, penyesuaian revaluasi kas valas akhir bulan, pembayaran invoice hutang/piutang (AP/AR), dan laporan keuangan perusahaan.
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- MODAL: ENTRI / EDIT KURS BI MANUAL -->
<div class="modal" id="modalRateForm">
    <div class="modal-card">
        <div class="modal-h">
            <span id="modalFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah Kurs Bank Indonesia</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeRateModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <input type="hidden" id="formRateId">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Tanggal Kurs BI:</label>
                    <input type="date" class="ant-input" id="formTanggal" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="fld-row">
                    <label>Kode Mata Uang (Valuta):</label>
                    <select class="ant-input" id="formKurs" onchange="autoFillCurrencyName()">
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
                        <option value="HKD">HKD - Hong Kong Dollar</option>
                        <option value="CHF">CHF - Swiss Franc</option>
                        <option value="CAD">CAD - Canadian Dollar</option>
                        <option value="NZD">NZD - New Zealand Dollar</option>
                        <option value="SAR">SAR - Saudi Arabian Riyal</option>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:2fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Nama Lengkap Mata Uang:</label>
                    <input type="text" class="ant-input" id="formMatauang" placeholder="Contoh: United States Dollar">
                </div>
                <div class="fld-row">
                    <label>Satuan (Unit):</label>
                    <input type="number" step="1" class="ant-input" id="formSatuan" value="1">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                <div class="fld-row">
                    <label>Kurs Jual (IDR):</label>
                    <input type="number" step="0.01" class="ant-input" id="formJual" placeholder="0.00" oninput="calcFormTengah()">
                </div>
                <div class="fld-row">
                    <label>Kurs Beli (IDR):</label>
                    <input type="number" step="0.01" class="ant-input" id="formBeli" placeholder="0.00" oninput="calcFormTengah()">
                </div>
                <div class="fld-row">
                    <label>Kurs Tengah (IDR):</label>
                    <input type="number" step="0.01" class="ant-input" id="formTengah" placeholder="0.00" required>
                </div>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeRateModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitRateForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Kurs BI</button>
        </div>
    </div>
</div>

<!-- MODAL: TARIK KURS BI ONLINE DARI PORTAL BI -->
<div class="modal" id="modalFetchOnline">
    <div class="modal-card" style="max-width:440px;">
        <div class="modal-h">
            <span><i class="fa-solid fa-cloud-arrow-down" style="color:var(--success);"></i> Tarik Kurs Transaksi BI Online</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeFetchOnlineModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <div style="margin-bottom:12px; font-size:10.5px; color:var(--text-main); line-height:1.45;">
                Pilih tanggal rilis transaksi Bank Indonesia. Sistem akan langsung menghubungkan portal resmi Bank Indonesia (<code>bi.go.id</code>) dan mengunduh seluruh <strong>26 valuta asing</strong> (Jual, Beli, Tengah, Satuan) secara otomatis.
            </div>
            <div class="fld-row">
                <label><i class="fa-regular fa-calendar"></i> Tanggal Transaksi BI yang Ditarik:</label>
                <input type="date" class="ant-input" id="fetchTargetDate" value="<?= date('Y-m-d') ?>" style="font-weight:600; font-size:11px;">
                <span style="font-size:9px; color:var(--text-muted); margin-top:2px;">*Jika tanggal yang dipilih adalah hari libur atau akhir pekan, portal BI otomatis memberikan kurs bursa hari kerja aktif terakhir.</span>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeFetchOnlineModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-success" onclick="executeFetchOnline()"><i class="fa-solid fa-cloud-arrow-down"></i> Tarik dari Bank Indonesia</button>
        </div>
    </div>
</div>

<!-- MODAL: PANDUAN LENGKAP MODUL & SOP KURS BANK INDONESIA -->
<div class="modal" id="modalModuleGuide">
    <div class="modal-card" style="max-width:820px; max-height:88vh;">
        <div class="modal-h" style="background:linear-gradient(135deg, rgba(24,144,255,0.08), rgba(24,144,255,0.02));">
            <span style="font-weight:700; color:var(--primary); font-size:13px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-book-open"></i> Panduan Lengkap: Rate Bank Indonesia (BI) &amp; Kurs Transaksi Valas
            </span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="padding:16px 20px; overflow-y:auto; line-height:1.55; font-size:11px;">
            <div style="background:rgba(24,144,255,0.06); border-left:3px solid var(--primary); padding:10px 14px; border-radius:4px; margin-bottom:16px;">
                <strong style="color:var(--primary); font-size:11.5px;">SOP Tata Kelola Kurs Transaksi Bank Indonesia (BI):</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main);">Kurs Transaksi Bank Indonesia adalah nilai tukar mata uang asing terhadap Rupiah yang dipublikasikan setiap hari kerja oleh Bank Indonesia. Kurs ini terdiri atas <strong>Kurs Jual</strong>, <strong>Kurs Beli</strong>, dan <strong>Kurs Tengah</strong> sebagai standar referensi transaksi perbankan dan pencatatan akuntansi ERP.</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#1890ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-scale-balanced"></i> 1. Struktur Kurs Transaksi BI
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Kurs Jual:</strong> Nilai kurs yang digunakan saat entitas menukarkan Rupiah untuk membeli valuta asing dari bank.</li>
                        <li><strong>Kurs Beli:</strong> Nilai kurs yang digunakan saat entitas menukarkan valuta asing untuk memperoleh Rupiah dari bank.</li>
                        <li><strong>Kurs Tengah:</strong> Nilai rata-rata dari penjumlahan Kurs Jual dan Kurs Beli: <code>(Kurs Jual + Kurs Beli) / 2</code>, digunakan umum untuk pembukuan ERP dan revaluasi kas valas.</li>
                    </ul>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#52c41a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-cloud-arrow-down"></i> 2. Penarikan Online Terpadu Portal BI
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li><strong>Tombol Tarik Kurs BI Online:</strong> Mengambil langsung 26 valuta resmi dari portal resmi <code>bi.go.id</code> (USD, EUR, SGD, JPY, CNY, GBP, AUD, KRW, MYR, THB, HKD, CHF, CAD, NZD, SAR, dll).</li>
                        <li><strong>Pencegahan Duplikasi:</strong> Kombinasi tanggal dan kode valuta bersifat unik, penarikan ulang otomatis memperbarui rate tanpa membuat duplikat baris.</li>
                        <li><strong>Jejak Audit:</strong> Setiap perubahan mencatat identitas akun pencatat dan stempel waktu pembaruan.</li>
                    </ul>
                </div>
            </div>

            <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card); margin-bottom:14px;">
                <div style="font-weight:700; color:#722ed1; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                    <i class="fa-solid fa-arrows-split-up-and-left"></i> 3. Perbedaan Kurs BI vs Kurs Pajak KMK
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:10px; color:var(--text-muted);">
                    <div style="background:var(--bg-base); padding:8px; border-radius:4px;">
                        <strong style="color:var(--text-main);">A. Kurs Pajak (KMK Kemenkeu):</strong><br>
                        Berlaku mingguan (Rabu - Selasa). Khusus dipakai untuk penghitungan Bea Masuk, PPN Impor, PPh 22, dan Dokumen Pabean CEISA (BC 2.0 / BC 2.3 / BC 4.0 / e-Faktur).
                    </div>
                    <div style="background:var(--bg-base); padding:8px; border-radius:4px;">
                        <strong style="color:var(--text-main);">B. Kurs Bank Indonesia (BI Rate):</strong><br>
                        Berlaku harian pada hari kerja bursa. Dipakai untuk transaksi komersial perbankan, realisasi pelunasan hutang piutang valas (AP/AR), dan pembukuan neraca laba rugi selisih kurs.
                    </div>
                </div>
            </div>

            <div style="background:rgba(250,173,20,0.08); border:1px solid rgba(250,173,20,0.3); border-radius:6px; padding:10px 14px;">
                <strong style="color:#d48806;"><i class="fa-solid fa-triangle-exclamation"></i> Kepatuhan Akuntansi &amp; IT Inventory:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main); font-size:10.5px;">Sistem ERP CEISA 4.0 memisahkan secara tegas antara Kurs Pajak (untuk kewajiban pabean kepatuhan Bea Cukai) dan Kurs BI (untuk pengakuan nilai persediaan komersial dan selisih kurs). Pastikan kurs tanggal transaksi selalu terisi lengkap sebelum menutup periode akuntansi bulanan.</p>
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

// Mapping Nama Valuta Resmi
const currencyNames = {
    'USD': 'United States Dollar',
    'EUR': 'Euro',
    'SGD': 'Singapore Dollar',
    'JPY': 'Japanese Yen',
    'CNY': 'China Yuan',
    'GBP': 'British Pound',
    'AUD': 'Australian Dollar',
    'KRW': 'South Korean Won',
    'MYR': 'Malaysian Ringgit',
    'THB': 'Thai Baht',
    'HKD': 'Hong Kong Dollar',
    'CHF': 'Swiss Franc',
    'CAD': 'Canadian Dollar',
    'NZD': 'New Zealand Dollar',
    'SAR': 'Saudi Arabian Riyal',
    'SEK': 'Swedish Krona',
    'DKK': 'Danish Krone',
    'NOK': 'Norwegian Krone',
    'INR': 'Indian Rupee',
    'PHP': 'Philippine Peso',
    'BND': 'Brunei Dollar'
};

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

// Inisialisasi Tanggal Filter (Default: 30 hari ke belakang s/d hari ini)
function initDefaultFilterDates() {
    const today = new Date();
    const dPast = new Date(today);
    dPast.setDate(dPast.getDate() - 30);

    const pad = n => String(n).padStart(2, '0');
    const toYmd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    document.getElementById('filDt1').value = toYmd(dPast);
    document.getElementById('filDt2').value = toYmd(today);
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
        updateCalcRateFromDate();
    }
}

// 1. Load KPI Header
async function loadKpi() {
    try {
        const res = await fetch('bi_rate.php?action=get_kpi');
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('kpiUsdMid').innerText = 'Rp ' + formatRupiah(json.usd_mid, 2);
            document.getElementById('kpiUsdSpread').innerText = 'J: Rp ' + formatRupiah(json.usd_jual, 2) + ' | B: Rp ' + formatRupiah(json.usd_beli, 2);
            document.getElementById('kpiActiveDate').innerText = json.active_date_fmt || json.active_date || '-';
            document.getElementById('kpiTotalRecords').innerText = (json.total_records || 0) + ' Catatan (' + (json.total_valuta || 26) + ' Valuta)';
            
            // Set rate default di kalkulator
            if (json.usd_mid > 0) {
                document.getElementById('calcRate').value = json.usd_mid;
                document.getElementById('calcRateInfo').innerText = 'Kurs Tengah USD BI (' + (json.active_date || '') + '): Rp ' + formatRupiah(json.usd_mid, 2);
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
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data Kurs Bank Indonesia...</td></tr>';

    try {
        const url = `bi_rate.php?action=list&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}&kurs=${encodeURIComponent(kurs)}&search=${encodeURIComponent(search)}&page=${curPage}&limit=${pageLimit}`;
        const res = await fetch(url);
        if (!res.ok) {
            const errTxt = await res.text();
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:16px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Respon Server (${res.status}): ${escapeHtml(errTxt.slice(0, 200))}</td></tr>`;
            return;
        }
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
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:28px; color:var(--text-muted);"><i class="fa-regular fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block;"></i>Tidak ada data kurs BI pada rentang tanggal yang dipilih. Klik "Tambah Kurs Baru" atau "Tarik Kurs BI Online".</td></tr>';
            return;
        }

        let html = '';
        ratesCache.forEach((r, idx) => {
            const no = ((curPage - 1) * pageLimit) + idx + 1;
            const curLower = (r.kurs || '').toLowerCase();
            const kursBadgeClass = ['usd', 'eur', 'sgd', 'jpy', 'cny', 'gbp', 'aud'].includes(curLower) ? `badge-kurs-${curLower}` : 'badge-kurs-other';
            const activeRowClass = r.is_today ? 'row-active-highlight' : '';

            html += `
            <tr class="${activeRowClass}">
                <td style="text-align:center; color:var(--text-muted);">${no}</td>
                <td style="text-align:center; font-weight:600;">${escapeHtml(r.tanggal)}</td>
                <td style="text-align:center;">
                    <span class="badge-kurs ${kursBadgeClass}">${escapeHtml(r.kurs)}</span>
                </td>
                <td><strong>${escapeHtml(r.matauang)}</strong></td>
                <td style="text-align:center; font-weight:600; color:var(--text-muted);">${Number(r.satuan).toLocaleString('id-ID')}</td>
                <td style="text-align:right; font-weight:600; color:var(--text-main);">
                    Rp ${formatRupiah(r.jual, (r.kurs === 'KRW' || r.kurs === 'JPY' ? 2 : 2))}
                </td>
                <td style="text-align:right; font-weight:600; color:var(--text-main);">
                    Rp ${formatRupiah(r.beli, 2)}
                </td>
                <td style="text-align:right; font-weight:700; color:var(--primary); font-size:11.5px;">
                    Rp ${formatRupiah(r.tengah, 2)}
                </td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="editRate(${r.id})" title="Edit Kurs"><i class="fa-solid fa-pen-to-square"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="duplicateRate(${r.id})" title="Duplikat ke Tanggal Lain"><i class="fa-solid fa-copy"></i></button>
                    <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="deleteRate(${r.id}, '${escapeHtml(r.kurs)}', '${escapeHtml(r.tanggal)}')" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;

        if (window.biGrid) {
            window.biGrid.refresh();
        } else if (typeof AntEnterpriseGrid !== 'undefined') {
            window.biGrid = new AntEnterpriseGrid('#tableRates', {
                gridId: 'erp_bi_rate',
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                skipCols: [0, 9],
                exportTitle: 'Kurs_Bank_Indonesia',
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

// 3. Modal Form Entri / Edit Kurs BI
function openAddRateModal() {
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-plus"></i> Tambah Kurs Bank Indonesia';
    document.getElementById('formRateId').value = '';
    document.getElementById('formTanggal').value = new Date().toISOString().slice(0, 10);
    document.getElementById('formKurs').value = 'USD';
    document.getElementById('formMatauang').value = 'United States Dollar';
    document.getElementById('formSatuan').value = '1';
    document.getElementById('formJual').value = '';
    document.getElementById('formBeli').value = '';
    document.getElementById('formTengah').value = '';

    document.getElementById('modalRateForm').classList.add('active');
}

function autoFillCurrencyName() {
    const k = document.getElementById('formKurs').value;
    document.getElementById('formMatauang').value = currencyNames[k] || k;
    if (k === 'JPY') {
        document.getElementById('formSatuan').value = '100';
    } else {
        document.getElementById('formSatuan').value = '1';
    }
}

function calcFormTengah() {
    const j = parseFloat(document.getElementById('formJual').value) || 0;
    const b = parseFloat(document.getElementById('formBeli').value) || 0;
    if (j > 0 && b > 0) {
        document.getElementById('formTengah').value = ((j + b) / 2).toFixed(2);
    }
}

async function editRate(id) {
    try {
        const res = await fetch(`bi_rate.php?action=get&id=${id}`);
        const json = await res.json();
        if (json.status !== 'OK') {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
            return;
        }

        const d = json.data;
        document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Kurs BI (' + escapeHtml(d.kurs) + ' - ' + escapeHtml(d.tanggal) + ')';
        document.getElementById('formRateId').value = d.id;
        document.getElementById('formTanggal').value = d.tanggal;
        document.getElementById('formKurs').value = d.kurs;
        document.getElementById('formMatauang').value = d.matauang || d.kurs;
        document.getElementById('formSatuan').value = d.satuan || 1;
        document.getElementById('formJual').value = d.jual;
        document.getElementById('formBeli').value = d.beli;
        document.getElementById('formTengah').value = d.tengah;

        document.getElementById('modalRateForm').classList.add('active');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

function duplicateRate(id) {
    const item = ratesCache.find(x => x.id === id);
    if (!item) return;

    document.getElementById('modalFormTitle').innerHTML = '<i class="fa-solid fa-copy"></i> Duplikat Kurs BI (' + escapeHtml(item.kurs) + ')';
    document.getElementById('formRateId').value = '';
    document.getElementById('formTanggal').value = new Date().toISOString().slice(0, 10);
    document.getElementById('formKurs').value = item.kurs;
    document.getElementById('formMatauang').value = item.matauang;
    document.getElementById('formSatuan').value = item.satuan;
    document.getElementById('formJual').value = item.jual;
    document.getElementById('formBeli').value = item.beli;
    document.getElementById('formTengah').value = item.tengah;

    document.getElementById('modalRateForm').classList.add('active');
}

function closeRateModal() {
    document.getElementById('modalRateForm').classList.remove('active');
}

async function submitRateForm() {
    const id       = document.getElementById('formRateId').value;
    const tanggal  = document.getElementById('formTanggal').value;
    const kurs     = document.getElementById('formKurs').value;
    const matauang = document.getElementById('formMatauang').value.trim();
    const satuan   = document.getElementById('formSatuan').value;
    const jual     = document.getElementById('formJual').value;
    const beli     = document.getElementById('formBeli').value;
    const tengah   = document.getElementById('formTengah').value;

    if (!tanggal || !kurs) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tanggal dan Kode Valuta wajib diisi!' });
        return;
    }
    if (parseFloat(tengah) <= 0 || isNaN(parseFloat(tengah))) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Nilai Kurs Tengah BI harus lebih besar dari 0!' });
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('tanggal', tanggal);
    fd.append('kurs', kurs);
    fd.append('matauang', matauang);
    fd.append('satuan', satuan);
    fd.append('jual', jual);
    fd.append('beli', beli);
    fd.append('tengah', tengah);

    try {
        const res = await fetch('bi_rate.php?action=save', {
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

// 4. Hapus Rate BI
async function deleteRate(id, kurs, tgl) {
    const confirm = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus data kurs Bank Indonesia ${kurs} tanggal ${tgl}?`,
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
        const res = await fetch('bi_rate.php?action=delete', {
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

// 5. Modal & Eksekusi Tarik Kurs Online BI
function openFetchOnlineModal() {
    document.getElementById('fetchTargetDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('modalFetchOnline').classList.add('active');
}

function closeFetchOnlineModal() {
    document.getElementById('modalFetchOnline').classList.remove('active');
}

async function executeFetchOnline() {
    const targetDate = document.getElementById('fetchTargetDate').value;
    if (!targetDate) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Pilih tanggal kurs terlebih dahulu.' });
        return;
    }

    closeFetchOnlineModal();

    Swal.fire({
        title: 'Menghubungkan ke Portal Bank Indonesia...',
        text: 'Mengunduh 26 valuta resmi dari portal bi.go.id...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const fd = new FormData();
        fd.append('tgl', targetDate);
        const res = await fetch('bi_rate.php?action=fetch_online', {
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
            // Sesuaikan tanggal filter agar mencakup tanggal yang baru ditarik
            document.getElementById('filDt2').value = targetDate;
            loadRates(1);
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal Tarik Kurs BI', text: json.message });
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
    window.location.href = `bi_rate.php?action=export_csv&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}&kurs=${encodeURIComponent(kurs)}`;
}

function exportDataExcel() {
    if (!ratesCache || ratesCache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }

    const exportRows = ratesCache.map((r, i) => ({
        'No': i + 1,
        'Tanggal': r.tanggal,
        'Valuta': r.kurs,
        'Nama Mata Uang': r.matauang,
        'Satuan': r.satuan,
        'Kurs Jual (IDR)': r.jual,
        'Kurs Beli (IDR)': r.beli,
        'Kurs Tengah (IDR)': r.tengah,
        'Pencatat (Set By)': r.set_by,
        'Terakhir Diperbarui': r.updated_at
    }));

    const ws = XLSX.utils.json_to_sheet(exportRows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Kurs Bank Indonesia");
    XLSX.writeFile(wb, `Kurs_Bank_Indonesia_${new Date().toISOString().slice(0,10)}.xlsx`);
}

// 7. Tab Arsip
async function loadArsipYear() {
    const yr = document.getElementById('filArsipYear').value;
    const ku = document.getElementById('filArsipKurs').value;
    const tbody = document.getElementById('tbodyArsip');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat arsip kurs BI...</td></tr>';

    let tglAwal = '';
    let tglAkhir = '';
    if (yr !== 'ALL') {
        tglAwal = `${yr}-01-01`;
        tglAkhir = `${yr}-12-31`;
    }

    try {
        const url = `bi_rate.php?action=list&tgl_awal=${tglAwal}&tgl_akhir=${tglAkhir}&kurs=${ku}&limit=200`;
        const res = await fetch(url);
        if (!res.ok) {
            const errTxt = await res.text();
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Respon Server (${res.status}): ${escapeHtml(errTxt.slice(0, 200))}</td></tr>`;
            return;
        }
        const json = await res.json();

        if (!json.data || json.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:24px; color:var(--text-muted);">Tidak ada data arsip kurs BI untuk tahun yang dipilih.</td></tr>';
            return;
        }

        let html = '';
        json.data.forEach((r, idx) => {
            const curLower = (r.kurs || '').toLowerCase();
            const kursBadgeClass = ['usd', 'eur', 'sgd', 'jpy', 'cny', 'gbp', 'aud'].includes(curLower) ? `badge-kurs-${curLower}` : 'badge-kurs-other';
            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                <td style="text-align:center; font-weight:600;">${r.tanggal}</td>
                <td style="text-align:center;"><span class="badge-kurs ${kursBadgeClass}">${escapeHtml(r.kurs)}</span></td>
                <td><strong>${escapeHtml(r.matauang)}</strong></td>
                <td style="text-align:center;">${Number(r.satuan).toLocaleString('id-ID')}</td>
                <td style="text-align:right;">Rp ${formatRupiah(r.jual, 2)}</td>
                <td style="text-align:right;">Rp ${formatRupiah(r.beli, 2)}</td>
                <td style="text-align:right; font-weight:700; color:var(--primary);">Rp ${formatRupiah(r.tengah, 2)}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
            </tr>`;
        });
        tbody.innerHTML = html;

        if (window.biArsipGrid) {
            window.biArsipGrid.refresh();
        } else if (typeof AntEnterpriseGrid !== 'undefined') {
            window.biArsipGrid = new AntEnterpriseGrid('#tableArsip', {
                gridId: 'erp_bi_rate_arsip',
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                skipCols: [0],
                exportTitle: 'Arsip_Kurs_Bank_Indonesia'
            });
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger);">${e.message}</td></tr>`;
    }
}

function exportArsipExcel() {
    const yr = document.getElementById('filArsipYear').value;
    const ku = document.getElementById('filArsipKurs').value;
    let tglAwal = yr !== 'ALL' ? `${yr}-01-01` : '';
    let tglAkhir = yr !== 'ALL' ? `${yr}-12-31` : '';
    window.location.href = `bi_rate.php?action=export_csv&tgl_awal=${tglAwal}&tgl_akhir=${tglAkhir}&kurs=${ku}`;
}

// 8. Kalkulator Interaktif BI
let calcCurrentItem = null;

function updateCalcRateFromDate() {
    const tgl = document.getElementById('calcDate').value;
    const valuta = document.getElementById('calcValuta').value;
    
    // Cari di cache atau cari yang terbaru
    let match = ratesCache.find(r => r.kurs === valuta && r.tanggal === tgl);
    if (!match) {
        match = ratesCache.find(r => r.kurs === valuta);
    }

    if (match) {
        calcCurrentItem = match;
        const type = document.getElementById('calcRateType').value;
        const rateVal = match[type] || match.tengah;
        document.getElementById('calcRate').value = rateVal;
        document.getElementById('calcRateInfo').innerText = `Kurs BI ${match.kurs} (${match.tanggal}) - Satuan: ${match.satuan}`;
    } else {
        calcCurrentItem = null;
        document.getElementById('calcRateInfo').innerText = 'Nilai perkiraan / manual';
    }
    runCalculation();
}

function runCalculation() {
    const valuta   = document.getElementById('calcValuta').value;
    const type     = document.getElementById('calcRateType').value;
    const rate     = parseFloat(document.getElementById('calcRate').value) || 0;
    const amount   = parseFloat(document.getElementById('calcAmount').value) || 0;

    const satuan = (valuta === 'JPY') ? 100 : 1;
    const totalIdr = (amount / satuan) * rate;

    // Hitung alternatif Jual & Beli
    let rateJual = rate;
    let rateBeli = rate;
    if (calcCurrentItem) {
        rateJual = calcCurrentItem.jual || rate;
        rateBeli = calcCurrentItem.beli || rate;
    }

    const totalJual = (amount / satuan) * rateJual;
    const totalBeli = (amount / satuan) * rateBeli;
    const spread    = Math.abs(totalJual - totalBeli);

    document.getElementById('resValas').innerText = `${valuta} ${formatRupiah(amount, 2)}`;
    document.getElementById('resKurs').innerText = `Rp ${formatRupiah(rate, 2)} (${type.toUpperCase()})`;
    document.getElementById('resTotalIdr').innerText = `Rp ${formatRupiah(totalIdr, 2)}`;
    document.getElementById('resAltJual').innerText = `Rp ${formatRupiah(totalJual, 2)}`;
    document.getElementById('resAltBeli').innerText = `Rp ${formatRupiah(totalBeli, 2)}`;
    document.getElementById('resSpread').innerText = `Rp ${formatRupiah(spread, 2)}`;
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

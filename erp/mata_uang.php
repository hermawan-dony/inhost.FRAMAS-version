<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Mata Uang (Valuta) Terpilih
 * ============================================================================
 * Acuan Asli PowerBuilder: bc_ref_kurs.srd, bc_act_kurs.srd, d_kurs.srd
 * Pengelolaan Valuta Asing & Mata Uang Aktif Operasional ERP Perusahaan,
 * Bersumber dari Referensi Resmi CEISA 4.0 (204 Valuta Dunia),
 * Pemetaan Akun GL Akuntansi, Penentuan Valuta Dasar (Default),
 * Monitoring Kurs Pajak & BI, serta Standardisasi Ant Design Style (Identik Tax Rate).
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
// AUTO-HEAL: Pastikan Tabel bc_ref_kurs Tersedia Lengkap
// -------------------------------------------------------------------------
function ensureRefKursTable($koneksi, $escCmp) {
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_ref_kurs'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ref_kurs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `nama` varchar(10) NOT NULL DEFAULT 'USD',
                `negara` varchar(100) DEFAULT '',
                `simbol` varchar(10) DEFAULT '',
                `nilaitukar` decimal(15,2) DEFAULT 1.00,
                `koma` int(11) DEFAULT 2,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `is_default` tinyint(1) NOT NULL DEFAULT 0,
                `akun_hutang` varchar(50) DEFAULT '',
                `akun_piutang` varchar(50) DEFAULT '',
                `akun_pembelian_lokal` varchar(50) DEFAULT '',
                `akun_pembelian_import` varchar(50) DEFAULT '',
                `akun_jual_lokal` varchar(50) DEFAULT '',
                `akun_jual_eksport` varchar(50) DEFAULT '',
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_cmp_valuta` (`cmp_id`, `nama`),
                KEY `idx_valuta_nama` (`nama`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Auto-heal kolom pada tabel yang sudah ada dari PowerBuilder
            $colId = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'id'");
            if (!$colId || $colId->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs ADD COLUMN id INT AUTO_INCREMENT UNIQUE FIRST");
            }
            $colCmp = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'cmp_id'");
            if (!$colCmp || $colCmp->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT ''");
            }
            $colStatus = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'status'");
            if (!$colStatus || $colStatus->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1");
            }
            $colDef = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'is_default'");
            if (!$colDef || $colDef->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0");
            }
            $colNegara = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'negara'");
            if ($colNegara) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs MODIFY COLUMN negara VARCHAR(100) DEFAULT ''");
            }
            $colSetBy = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'set_by'");
            if (!$colSetBy || $colSetBy->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs ADD COLUMN set_by VARCHAR(100) DEFAULT ''");
            }
            $colCreated = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'created_at'");
            if (!$colCreated || $colCreated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            $colUpdated = $koneksi->query("SHOW COLUMNS FROM bc_ref_kurs LIKE 'updated_at'");
            if (!$colUpdated || $colUpdated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kurs ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    } catch (\Throwable $ex) {}

    // Seed Awal Valuta jika masih kosong
    $cnt = $koneksi->query("SELECT COUNT(*) FROM bc_ref_kurs WHERE cmp_id = '$escCmp'")->fetch_row()[0] ?? 0;
    if ((int)$cnt === 0) {
        $seeds = [
            ['IDR', 'INDONESIAN RUPIAH', 'Rp', 1, 1, 'SYSTEM - Auto Init'],
            ['USD', 'UNITED STATES DOLLAR', '$', 1, 0, 'SYSTEM - Auto Init'],
            ['EUR', 'EURO', '€', 1, 0, 'SYSTEM - Auto Init'],
            ['SGD', 'SINGAPORE DOLLAR', 'S$', 1, 0, 'SYSTEM - Auto Init'],
            ['JPY', 'JAPANESE YEN', '¥', 1, 0, 'SYSTEM - Auto Init'],
            ['CNY', 'CHINA YUAN', '¥', 1, 0, 'SYSTEM - Auto Init'],
            ['GBP', 'BRITISH POUND', '£', 1, 0, 'SYSTEM - Auto Init'],
            ['AUD', 'AUSTRALIAN DOLLAR', 'A$', 1, 0, 'SYSTEM - Auto Init'],
            ['KRW', 'SOUTH KOREAN WON', '₩', 1, 0, 'SYSTEM - Auto Init']
        ];
        foreach ($seeds as $s) {
            $koneksi->query("INSERT IGNORE INTO bc_ref_kurs (cmp_id, nama, negara, simbol, status, is_default, set_by)
                             VALUES ('$escCmp', '{$s[0]}', '{$s[1]}', '{$s[2]}', {$s[3]}, {$s[4]}, '{$s[5]}')");
        }
    }
}
ensureRefKursTable($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. GET MINI DASHBOARD KPI
if ($action === 'get_kpi') {
    header('Content-Type: application/json; charset=utf-8');

    $cntAktif   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_kurs WHERE cmp_id = '$escCmp' AND (status = 1 OR status IS NULL)")->fetch_row()[0] ?? 0);
    $cntTotal   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_kurs WHERE cmp_id = '$escCmp'")->fetch_row()[0] ?? 0);
    $cntReffAll = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ceisa_reff WHERE reff = 'VALUTA'")->fetch_row()[0] ?? 204);

    $defaultValuta = 'IDR';
    $qDef = $koneksi->query("SELECT nama FROM bc_ref_kurs WHERE cmp_id = '$escCmp' AND is_default = 1 LIMIT 1");
    if ($qDef && $r = $qDef->fetch_assoc()) {
        $defaultValuta = $r['nama'];
    }

    echo json_encode([
        'status'         => 'OK',
        'valuta_aktif'   => $cntAktif,
        'valuta_total'   => $cntTotal,
        'default_valuta' => $defaultValuta,
        'katalog_dunia'  => $cntReffAll
    ]);
    exit;
}

// 2. LIST MATA UANG AKTIF PERUSAHAAN
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? 'ALL');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(200, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = ["(a.cmp_id = '$escCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)"];
    if ($status === '1') {
        $where[] = "(a.status = 1 OR a.status IS NULL)";
    } elseif ($status === '0') {
        $where[] = "a.status = 0";
    }

    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(a.nama LIKE '%$sEsc%' OR a.negara LIKE '%$sEsc%' OR a.simbol LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM bc_ref_kurs a WHERE $wSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    $sql = "SELECT a.id, a.nama as kode, 
                   COALESCE(NULLIF(a.negara, ''), r.teks, a.nama) as nama_valuta,
                   COALESCE(NULLIF(a.simbol, ''), '') as simbol,
                   COALESCE(a.is_default, 0) as is_default,
                   COALESCE(a.status, 1) as status,
                   a.akun_hutang, a.akun_piutang, a.set_by, a.updated_at
            FROM bc_ref_kurs a
            LEFT JOIN bc_ceisa_reff r ON (r.reff = 'VALUTA' AND r.kode = a.nama)
            WHERE $wSql
            ORDER BY a.is_default DESC, a.status DESC, a.nama ASC
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'          => (int)$r['id'],
                'kode'        => strtoupper(trim($r['kode'])),
                'nama_valuta' => $r['nama_valuta'],
                'simbol'      => $r['simbol'],
                'is_default'  => (int)$r['is_default'],
                'status'      => (int)$r['status'],
                'akun_hutang' => $r['akun_hutang'] ?: '-',
                'akun_piutang'=> $r['akun_piutang'] ?: '-',
                'set_by'      => $r['set_by'] ?: 'SYSTEM',
                'updated_at'  => $r['updated_at']
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

// 3. KATALOG MASTER 204 VALUTA DUNIA (Untuk Modal Picker)
if ($action === 'get_reff_catalog') {
    header('Content-Type: application/json; charset=utf-8');
    $search = trim($_GET['search'] ?? '');
    $limit  = max(20, min(500, (int)($_GET['limit'] ?? 250)));

    $where = ["r.reff = 'VALUTA'"];
    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(r.kode LIKE '%$sEsc%' OR r.teks LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $sql = "SELECT r.kode, r.teks, 
                   IF(u.nama IS NOT NULL, 1, 0) as is_used
            FROM bc_ceisa_reff r
            LEFT JOIN bc_ref_kurs u ON (u.nama = r.kode AND (u.cmp_id = '$escCmp' OR u.cmp_id = ''))
            WHERE $wSql
            ORDER BY is_used DESC, r.kode ASC
            LIMIT $limit";
    $res = $koneksi->query($sql);
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'kode'    => strtoupper(trim($r['kode'])),
                'teks'    => $r['teks'],
                'is_used' => (int)$r['is_used']
            ];
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $items]);
    exit;
}

// 4. AKTIFKAN DARI REFERENSI DUNIA (Batch Picker)
if ($action === 'activate_from_reff') {
    header('Content-Type: application/json; charset=utf-8');
    $codes = $_POST['codes'] ?? [];
    if (!is_array($codes)) $codes = [$codes];
    $codes = array_filter(array_map('trim', $codes));

    if (empty($codes)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih setidaknya satu valuta untuk diaktifkan.']);
        exit;
    }

    $setBy = $koneksi->real_escape_string($currentUser . ' (Katalog CEISA)');
    $saved = 0;

    // Simbol mapping otomatis
    $symbolMap = [
        'USD' => '$', 'IDR' => 'Rp', 'EUR' => '€', 'GBP' => '£',
        'JPY' => '¥', 'CNY' => '¥', 'SGD' => 'S$', 'AUD' => 'A$',
        'KRW' => '₩', 'MYR' => 'RM', 'THB' => '฿', 'SAR' => 'SR',
        'CHF' => 'CHF', 'CAD' => 'C$', 'HKD' => 'HK$'
    ];

    foreach ($codes as $c) {
        $cEsc = $koneksi->real_escape_string(strtoupper($c));
        $qRef = $koneksi->query("SELECT teks FROM bc_ceisa_reff WHERE reff = 'VALUTA' AND kode = '$cEsc' LIMIT 1");
        $uraian = ($qRef && $r = $qRef->fetch_assoc()) ? $r['teks'] : $cEsc;
        $uEsc = $koneksi->real_escape_string($uraian);
        $simbol = $symbolMap[$cEsc] ?? '';

        $sql = "INSERT INTO bc_ref_kurs (cmp_id, nama, negara, simbol, status, is_default, set_by)
                VALUES ('$escCmp', '$cEsc', '$uEsc', '$simbol', 1, 0, '$setBy')
                ON DUPLICATE KEY UPDATE 
                negara = VALUES(negara), 
                status = 1, 
                set_by = VALUES(set_by)";
        if ($koneksi->query($sql)) {
            $saved++;
        }
    }

    echo json_encode(['status' => 'OK', 'message' => "Berhasil mengaktifkan $saved valuta mata uang untuk operasional perusahaan."]);
    exit;
}

// 5. TOGGLE STATUS AKTIF / NON-AKTIF
if ($action === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID data tidak valid.']);
        exit;
    }

    $setBy = $koneksi->real_escape_string($currentUser . ' - ' . date('Y-m-d H:i'));
    $sql = "UPDATE bc_ref_kurs SET status = IF(status = 1, 0, 1), set_by = '$setBy' WHERE id = $id";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Status valuta berhasil diubah.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $koneksi->error]);
    }
    exit;
}

// 6. JADIKAN VALUTA DEFAULT (BASE CURRENCY)
if ($action === 'set_default') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID data tidak valid.']);
        exit;
    }

    $koneksi->query("UPDATE bc_ref_kurs SET is_default = 0 WHERE cmp_id = '$escCmp'");
    $sql = "UPDATE bc_ref_kurs SET is_default = 1, status = 1 WHERE id = $id AND cmp_id = '$escCmp'";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Mata uang default operasional perusahaan berhasil diperbarui.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menetapkan default: ' . $koneksi->error]);
    }
    exit;
}

// 7. SIMPAN / EDIT MANUAL
if ($action === 'save_manual') {
    header('Content-Type: application/json; charset=utf-8');
    $id          = (int)($_POST['id'] ?? 0);
    $kode        = strtoupper(trim($_POST['kode'] ?? ''));
    $negara      = trim($_POST['negara'] ?? '');
    $simbol      = trim($_POST['simbol'] ?? '');
    $akunHutang  = trim($_POST['akun_hutang'] ?? '');
    $akunPiutang = trim($_POST['akun_piutang'] ?? '');

    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Valuta wajib diisi!']);
        exit;
    }

    $cEsc = $koneksi->real_escape_string($kode);
    $nEsc = $koneksi->real_escape_string($negara ?: $kode);
    $sEsc = $koneksi->real_escape_string($simbol);
    $hEsc = $koneksi->real_escape_string($akunHutang);
    $pEsc = $koneksi->real_escape_string($akunPiutang);
    $setBy = $koneksi->real_escape_string($currentUser . ' - ' . date('Y-m-d H:i'));

    if ($id > 0) {
        $sql = "UPDATE bc_ref_kurs SET 
                nama = '$cEsc', negara = '$nEsc', simbol = '$sEsc', 
                akun_hutang = '$hEsc', akun_piutang = '$pEsc', set_by = '$setBy'
                WHERE id = $id";
    } else {
        $sql = "INSERT INTO bc_ref_kurs (cmp_id, nama, negara, simbol, akun_hutang, akun_piutang, status, is_default, set_by)
                VALUES ('$escCmp', '$cEsc', '$nEsc', '$sEsc', '$hEsc', '$pEsc', 1, 0, '$setBy')
                ON DUPLICATE KEY UPDATE 
                negara = VALUES(negara), simbol = VALUES(simbol), 
                akun_hutang = VALUES(akun_hutang), akun_piutang = VALUES(akun_piutang), status = 1, set_by = VALUES(set_by)";
    }

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Data mata uang berhasil disimpan.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $koneksi->error]);
    }
    exit;
}

// 8. HAPUS VALUTA TERPILIH
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID data tidak valid.']);
        exit;
    }

    // Cek apakah valuta default
    $qCheck = $koneksi->query("SELECT is_default, nama FROM bc_ref_kurs WHERE id = $id LIMIT 1");
    if ($qCheck && $r = $qCheck->fetch_assoc()) {
        if ((int)$r['is_default'] === 1) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Valuta default operasional tidak boleh dihapus. Tetapkan valuta lain sebagai default terlebih dahulu.']);
            exit;
        }
    }

    if ($koneksi->query("DELETE FROM bc_ref_kurs WHERE id = $id")) {
        echo json_encode(['status' => 'OK', 'message' => 'Mata uang berhasil dihapus dari daftar operasional perusahaan.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 9. MONITORING KURS TERKINI (TAB 3)
if ($action === 'get_rates_monitoring') {
    header('Content-Type: application/json; charset=utf-8');

    $qVal = $koneksi->query("SELECT nama, negara, simbol, is_default FROM bc_ref_kurs WHERE cmp_id = '$escCmp' AND (status = 1 OR status IS NULL) ORDER BY is_default DESC, nama ASC");
    $list = [];
    $today = date('Y-m-d');

    if ($qVal) {
        while ($v = $qVal->fetch_assoc()) {
            $cur = strtoupper(trim($v['nama']));

            // A. Kurs Pajak KMK Terkini
            $kmkRate = 0;
            $kmkNo   = '-';
            $kmkDate = '-';
            if ($cur === 'IDR') {
                $kmkRate = 1.00;
                $kmkNo   = 'Base Currency';
            } else {
                $qKmk = $koneksi->query("SELECT rate, kmk_no, awal, akhir FROM bc_efaktur_rate 
                                         WHERE kurs = '$cur' 
                                         ORDER BY awal DESC LIMIT 1");
                if ($qKmk && $kRow = $qKmk->fetch_assoc()) {
                    $kmkRate = (float)$kRow['rate'];
                    $kmkNo   = $kRow['kmk_no'];
                    $kmkDate = date('d M Y', strtotime($kRow['awal'])) . ' - ' . date('d M Y', strtotime($kRow['akhir']));
                }
            }

            // B. Kurs Bank Indonesia Terkini
            $biMid  = 0;
            $biJual = 0;
            $biBeli = 0;
            $biDate = '-';
            if ($cur === 'IDR') {
                $biMid = 1.00; $biJual = 1.00; $biBeli = 1.00;
            } else {
                $qBi = $koneksi->query("SELECT tengah, jual, beli, tanggal FROM bc_act_birate 
                                        WHERE kurs = '$cur' 
                                        ORDER BY tanggal DESC LIMIT 1");
                if ($qBi && $bRow = $qBi->fetch_assoc()) {
                    $biMid  = (float)$bRow['tengah'];
                    $biJual = (float)$bRow['jual'];
                    $biBeli = (float)$bRow['beli'];
                    $biDate = date('d M Y', strtotime($bRow['tanggal']));
                }
            }

            $list[] = [
                'kode'       => $cur,
                'nama'       => $v['negara'] ?: $cur,
                'simbol'     => $v['simbol'],
                'is_default' => (int)$v['is_default'],
                'kmk_rate'   => $kmkRate,
                'kmk_no'     => $kmkNo,
                'kmk_date'   => $kmkDate,
                'bi_mid'     => $biMid,
                'bi_jual'    => $biJual,
                'bi_beli'    => $biBeli,
                'bi_date'    => $biDate
            ];
        }
    }

    echo json_encode(['status' => 'OK', 'data' => $list]);
    exit;
}

// 10. EXPORT CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Mata_Uang_Terpilih_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kode Valuta', 'Nama Mata Uang', 'Simbol', 'Status', 'Default Base', 'Akun Hutang', 'Akun Piutang', 'Diinput Oleh', 'Terakhir Diperbarui']);

    $q = $koneksi->query("SELECT nama, negara, simbol, status, is_default, akun_hutang, akun_piutang, set_by, updated_at FROM bc_ref_kurs WHERE cmp_id = '$escCmp' ORDER BY is_default DESC, nama ASC");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($out, [
                $r['nama'],
                $r['negara'],
                $r['simbol'],
                ((int)$r['status'] === 1 ? 'Aktif' : 'Non-Aktif'),
                ((int)$r['is_default'] === 1 ? 'YA (Base)' : 'TIDAK'),
                $r['akun_hutang'],
                $r['akun_piutang'],
                $r['set_by'],
                $r['updated_at']
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
    <title>Mata Uang (Valuta) Terpilih - <?= htmlspecialchars($activeCmpName) ?></title>
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
        .badge-nonaktif { 
            background: #fafafa; 
            color: #8c8c8c; 
            border: 1px solid #d9d9d9; 
            font-size: 9px; 
            padding: 1px 6px; 
            border-radius: 10px; 
        }
        .badge-default {
            background: #e6f7ff;
            color: #096dd9;
            border: 1px solid #91d5ff;
            font-size: 9px;
            padding: 1px 6px;
            border-radius: 10px;
            font-weight: 700;
        }

        .badge-kurs { 
            font-weight: 700; 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-size: 9.5px; 
            display: inline-block; 
            text-align: center; 
            min-width: 40px; 
        }
        .badge-kurs-idr { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
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
            max-width: 580px; 
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
    </style>
</head>
<body>
<div class="ant-container">
    <div class="ant-card">
        <!-- Header Card -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-coins" style="color:var(--primary);"></i>
                <span>Mata Uang (Valuta) Terpilih - Standar Transaksi ERP</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($activeCmpName) ?></span>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:10px; color:var(--text-muted);">
                    <i class="fa-solid fa-circle-check" style="color:#52c41a;"></i> Filter Dropdown Aktif
                </span>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul &amp; SOP">
                    <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i>
                </button>
            </div>
        </div>

        <!-- Mini Dashboard KPI -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);">
                    <i class="fa-solid fa-money-bill-1-wave"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiDefaultValuta">IDR (Rupiah)</div>
                    <div class="kpi-label">Mata Uang Dasar Pembukuan</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);">
                    <i class="fa-solid fa-globe"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiValutaAktif">0 Valuta</div>
                    <div class="kpi-label">Mata Uang Aktif Perusahaan</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);">
                    <i class="fa-solid fa-earth-americas"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiKatalogDunia">204 Valuta</div>
                    <div class="kpi-label">Total Referensi Valuta Dunia CEISA</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;">
                    <i class="fa-solid fa-filter-circle-dollar"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiDropdownEfektif">0 Pilihan</div>
                    <div class="kpi-label">Dropdown Transaksi Ringkas</div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="valuta" onclick="switchTab('valuta')">
                <i class="fa-solid fa-money-check-dollar"></i> 1. Mata Uang Aktif Perusahaan
            </button>
            <button type="button" class="ant-tab-btn" data-tab="katalog" onclick="switchTab('katalog')">
                <i class="fa-solid fa-earth-americas"></i> 2. Katalog 204 Valuta Dunia CEISA 4.0
            </button>
            <button type="button" class="ant-tab-btn" data-tab="monitoring" onclick="switchTab('monitoring')">
                <i class="fa-solid fa-chart-line"></i> 3. Monitoring Nilai Kurs Terkini (Pajak &amp; BI)
            </button>
        </div>

        <!-- Toolbar Aksi Ant Design -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openPickerModal()">
                <i class="fa-solid fa-plus-circle"></i> Pilih dari 204 Valuta Dunia CEISA 4.0
            </button>
            <button type="button" class="ant-btn" onclick="openManualFormModal()">
                <i class="fa-solid fa-pen-to-square"></i> Tambah / Custom Valuta
            </button>
            <button type="button" class="ant-btn" onclick="exportExcel()">
                <i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Export Excel (.xlsx)
            </button>
            <button type="button" class="ant-btn" onclick="exportCsv()">
                <i class="fa-solid fa-file-csv" style="color:#faad14;"></i> Export CSV
            </button>
            <button type="button" class="ant-btn" onclick="reloadCurrentTab()" title="Muat Ulang Data">
                <i class="fa-solid fa-rotate"></i> Refresh
            </button>
        </div>

        <!-- TAB 1: MATA UANG AKTIF PERUSAHAAN -->
        <section class="ant-page active" id="pageValuta">
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Status:</label>
                    <select class="ant-input" id="filStatus" onchange="loadValuta(1)">
                        <option value="ALL">Semua Status</option>
                        <option value="1" selected>Aktif Saja (Dipakai)</option>
                        <option value="0">Non-Aktif (Disembunyikan)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <input type="text" class="ant-input" id="filSearch" placeholder="Cari Kode Valuta / Nama Mata Uang..." style="width:220px;" onkeyup="if(event.key==='Enter') loadValuta(1);">
                </div>
                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadValuta(1)">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="resetFilter()">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
            </div>
            <div class="table-wrap">
                <table class="ant-table" id="tblValuta">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;">No</th>
                            <th style="width:90px; text-align:center;">Status</th>
                            <th style="width:75px; text-align:center;">Kode</th>
                            <th style="width:220px;">Nama Mata Uang (Negara)</th>
                            <th style="width:65px; text-align:center;">Simbol</th>
                            <th style="width:110px; text-align:center;">Valuta Dasar</th>
                            <th style="width:130px;">Akun GL Hutang</th>
                            <th style="width:130px;">Akun GL Piutang</th>
                            <th>Pencatat</th>
                            <th style="width:140px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyValuta">
                        <tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data mata uang...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="ant-table-footer">
                <div>Total Valuta: <strong id="lblTotalValuta">0</strong> baris | Halaman <span id="lblCurPageValuta">1</span> dari <span id="lblTotalPagesValuta">1</span></div>
                <div style="display:flex; gap:4px; align-items:center;">
                    <button type="button" class="ant-btn ant-btn-sm" id="btnPrevValuta" onclick="changePageValuta(-1)"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</button>
                    <button type="button" class="ant-btn ant-btn-sm" id="btnNextValuta" onclick="changePageValuta(1)">Selanjutnya <i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
        </section>

        <!-- TAB 2: KATALOG 204 VALUTA DUNIA CEISA 4.0 -->
        <section class="ant-page" id="pageKatalog">
            <div class="filter-bar">
                <div class="filter-group">
                    <input type="text" class="ant-input" id="filKatalogSearch" placeholder="Cari di seluruh 204 valuta dunia..." style="width:260px;" onkeyup="if(event.key==='Enter') loadKatalog();">
                </div>
                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadKatalog()">
                    <i class="fa-solid fa-magnifying-glass"></i> Cari
                </button>
            </div>
            <div class="table-wrap">
                <table class="ant-table">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;">No</th>
                            <th style="width:90px; text-align:center;">Kode Valuta</th>
                            <th style="width:360px;">Uraian Resmi CEISA 4.0 / Negara</th>
                            <th style="width:120px; text-align:center;">Status di ERP</th>
                            <th style="width:130px; text-align:center;">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyKatalog">
                        <tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat katalog 204 valuta dunia...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- TAB 3: MONITORING NILAI KURS TERKINI (PAJAK KMK & BI) -->
        <section class="ant-page" id="pageMonitoring">
            <div class="filter-bar">
                <div style="font-size:10px; color:var(--text-muted);">
                    <i class="fa-solid fa-circle-info" style="color:var(--primary);"></i> Menampilkan perbandingan nilai tukar resmi terkini antara <strong>Kurs Pajak KMK Kemenkeu</strong> dan <strong>Kurs Transaksi Bank Indonesia</strong> khusus untuk mata uang yang aktif di perusahaan.
                </div>
                <button type="button" class="ant-btn ant-btn-sm" style="margin-left:auto;" onclick="loadMonitoring()">
                    <i class="fa-solid fa-rotate"></i> Muat Ulang Rate
                </button>
            </div>
            <div class="table-wrap">
                <table class="ant-table">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;">No</th>
                            <th style="width:80px; text-align:center;">Valuta</th>
                            <th style="width:200px;">Nama Mata Uang</th>
                            <th style="width:130px; text-align:right;">Kurs Pajak KMK (IDR)</th>
                            <th style="width:170px;">Nomor KMK Kemenkeu</th>
                            <th style="width:130px; text-align:right;">Kurs Tengah BI (IDR)</th>
                            <th style="width:130px; text-align:right;">Kurs Jual BI (IDR)</th>
                            <th style="width:130px; text-align:right;">Kurs Beli BI (IDR)</th>
                            <th style="width:110px; text-align:center;">Tanggal BI</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyMonitoring">
                        <tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mengambil kurs terkini...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<!-- MODAL: PEMILIH CEPAT MULTI-SELECT DARI 204 VALUTA DUNIA -->
<div class="modal" id="modalPicker">
    <div class="modal-card">
        <div class="modal-h">
            <span><i class="fa-solid fa-earth-americas" style="color:var(--primary);"></i> Pilih Valuta dari 204 Mata Uang Dunia CEISA 4.0</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closePickerModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="filter-bar" style="border-bottom:1px solid var(--border-color); background:var(--bg-card);">
            <input type="text" class="ant-input" id="pickerSearch" placeholder="Cari kode atau nama valuta dunia..." style="flex:1;" oninput="filterPickerList()">
            <button type="button" class="ant-btn ant-btn-sm" onclick="toggleSelectAllPicker(true)">Pilih Semua</button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="toggleSelectAllPicker(false)">Batal Pilih</button>
        </div>
        <div class="modal-b" style="padding:0; max-height:360px;">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:35px; text-align:center;">Pilih</th>
                        <th style="width:80px; text-align:center;">Kode</th>
                        <th>Uraian Valuta Resmi</th>
                        <th style="width:80px; text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody id="tbodyPickerList">
                    <tr><td colspan="4" style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat valuta...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="modal-f">
            <span id="lblPickerSelectedCount" style="margin-right:auto; font-size:10px; color:var(--text-muted); align-self:center;">0 dipilih</span>
            <button type="button" class="ant-btn" onclick="closePickerModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitPickerSelection()"><i class="fa-solid fa-check"></i> Aktifkan Terpilih</button>
        </div>
    </div>
</div>

<!-- MODAL: FORM ENTRI / EDIT VALUTA MANUAL & AKUN GL -->
<div class="modal" id="modalManualForm">
    <div class="modal-card" style="max-width:500px;">
        <div class="modal-h">
            <span id="manualFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah / Edit Mata Uang</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeManualFormModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <input type="hidden" id="formId">
            <div style="display:grid; grid-template-columns:1fr 2fr; gap:8px;">
                <div class="fld-row">
                    <label>Kode Valuta:</label>
                    <input type="text" class="ant-input" id="formKode" placeholder="Contoh: USD" required style="text-transform:uppercase; font-weight:700;">
                </div>
                <div class="fld-row">
                    <label>Nama Mata Uang (Negara):</label>
                    <input type="text" class="ant-input" id="formNegara" placeholder="Contoh: United States Dollar" required>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 2fr; gap:8px;">
                <div class="fld-row">
                    <label>Simbol Valuta:</label>
                    <input type="text" class="ant-input" id="formSimbol" placeholder="Contoh: $ atau Rp">
                </div>
                <div class="fld-row">
                    <label>Akun GL Hutang (AP):</label>
                    <input type="text" class="ant-input" id="formAkunHutang" placeholder="Contoh: 211-01-001">
                </div>
            </div>
            <div class="fld-row">
                <label>Akun GL Piutang (AR):</label>
                <input type="text" class="ant-input" id="formAkunPiutang" placeholder="Contoh: 113-01-001">
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeManualFormModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitManualForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Valuta</button>
        </div>
    </div>
</div>

<!-- MODAL: PANDUAN MODUL & SOP MATA UANG -->
<div class="modal" id="modalModuleGuide">
    <div class="modal-card" style="max-width:780px; max-height:88vh;">
        <div class="modal-h" style="background:linear-gradient(135deg, rgba(24,144,255,0.08), rgba(24,144,255,0.02));">
            <span style="font-weight:700; color:var(--primary); font-size:13px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-book-open"></i> Panduan Lengkap: Mata Uang (Valuta) Terpilih
            </span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="padding:16px 20px; overflow-y:auto; line-height:1.55; font-size:11px;">
            <div style="background:rgba(24,144,255,0.06); border-left:3px solid var(--primary); padding:10px 14px; border-radius:4px; margin-bottom:16px;">
                <strong style="color:var(--primary); font-size:11.5px;">Tata Kelola Mata Uang &amp; Valas ERP Enterprise:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main);">Master referensi pabean CEISA 4.0 memuat <strong>204 Valuta Dunia</strong>. Mengaktifkan hanya valuta yang relevan dengan transaksi perusahaan (misal: IDR, USD, EUR, SGD, JPY, CNY) membuat dropdown transaksi pengadaan, penjualan, dan kepabeanan menjadi sangat rapi dan bebas dari salah input.</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#1890ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-star"></i> 1. Valuta Dasar (Base Currency)
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li>Secara default operasional di Indonesia, <strong>IDR (Indonesian Rupiah)</strong> ditetapkan sebagai valuta dasar pembukuan (Base Currency).</li>
                        <li>Nilai tukar valuta dasar selalu <code>1.00</code> dan digunakan sebagai pembanding konversi seluruh valas lainnya.</li>
                    </ul>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#52c41a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-link"></i> 2. Keterhubungan ke Kurs Pajak &amp; BI
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li>Setiap valuta aktif di modul ini secara otomatis terhubung ke modul <strong>Rate Pajak KMK</strong> dan <strong>Rate BI</strong>.</li>
                        <li>Tab 3 (Monitoring) menampilkan nilai kurs terkini dari masing-masing otoritas untuk memudahkan crosscheck akuntansi.</li>
                    </ul>
                </div>
            </div>

            <div style="background:rgba(250,173,20,0.08); border:1px solid rgba(250,173,20,0.3); border-radius:6px; padding:10px 14px;">
                <strong style="color:#d48806;"><i class="fa-solid fa-triangle-exclamation"></i> Kepatuhan Akuntansi &amp; IT Inventory:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main); font-size:10.5px;">Pastikan akun GL Hutang dan Piutang untuk masing-masing valas telah terkonfigurasi dengan benar agar jurnal transaksi pembelian dan penjualan valas tercatat otomatis di buku besar akuntansi.</p>
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
let currentTab = 'valuta';
let curPage = 1;
const pageLimit = 25;
let valutaCache = [];
let pickerCatalogCache = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatRupiah(num, decimals = 2) {
    if (isNaN(num)) return '0,00';
    return Number(num).toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// Switch Tabs
function switchTab(tabId) {
    currentTab = tabId;
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-tab') === tabId));
    document.querySelectorAll('.ant-page').forEach(p => p.classList.remove('active'));

    if (tabId === 'valuta') {
        document.getElementById('pageValuta').classList.add('active');
        loadValuta();
    } else if (tabId === 'katalog') {
        document.getElementById('pageKatalog').classList.add('active');
        loadKatalog();
    } else if (tabId === 'monitoring') {
        document.getElementById('pageMonitoring').classList.add('active');
        loadMonitoring();
    }
}

function reloadCurrentTab() {
    loadKpi();
    if (currentTab === 'valuta') loadValuta();
    else if (currentTab === 'katalog') loadKatalog();
    else if (currentTab === 'monitoring') loadMonitoring();
}

// 1. KPI
async function loadKpi() {
    try {
        const res = await fetch('mata_uang.php?action=get_kpi');
        if (!res.ok) return;
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('kpiDefaultValuta').innerText = (json.default_valuta || 'IDR') + ' (Utama)';
            document.getElementById('kpiValutaAktif').innerText = (json.valuta_aktif || 0) + ' Valuta';
            document.getElementById('kpiKatalogDunia').innerText = (json.katalog_dunia || 204) + ' Valuta';
            document.getElementById('kpiDropdownEfektif').innerText = (json.valuta_aktif || 0) + ' Pilihan';
        }
    } catch (e) {
        console.error('Error KPI:', e);
    }
}

// 2. Load Valuta
async function loadValuta(page = null) {
    if (page !== null) curPage = page;
    const search = document.getElementById('filSearch').value.trim();
    const status = document.getElementById('filStatus').value;
    const tbody  = document.getElementById('tbodyValuta');
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data mata uang...</td></tr>';

    try {
        const url = `mata_uang.php?action=list&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&page=${curPage}&limit=${pageLimit}`;
        const res = await fetch(url);
        if (!res.ok) {
            const errTxt = await res.text();
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:16px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Error (${res.status}): ${escapeHtml(errTxt.slice(0, 150))}</td></tr>`;
            return;
        }
        const json = await res.json();
        valutaCache = json.data || [];

        document.getElementById('lblTotalValuta').innerText = (json.total || 0).toLocaleString('id-ID');
        document.getElementById('lblCurPageValuta').innerText = json.page || 1;
        document.getElementById('lblTotalPagesValuta').innerText = json.total_pages || 1;
        document.getElementById('btnPrevValuta').disabled = (curPage <= 1);
        document.getElementById('btnNextValuta').disabled = (curPage >= json.total_pages);

        if (valutaCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fa-regular fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block;"></i>Belum ada mata uang yang dipilih. Klik "Pilih dari 204 Valuta Dunia CEISA 4.0".</td></tr>';
            return;
        }

        let html = '';
        valutaCache.forEach((r, idx) => {
            const no = ((curPage - 1) * pageLimit) + idx + 1;
            const isAktif = (r.status === 1);
            const isDef = (r.is_default === 1);

            const statusBadge = isAktif 
                ? '<span class="badge-aktif"><i class="fa-solid fa-check"></i> Aktif</span>' 
                : '<span class="badge-nonaktif"><i class="fa-solid fa-ban"></i> Non-Aktif</span>';
            const defaultBadge = isDef 
                ? '<span class="badge-default"><i class="fa-solid fa-star"></i> Base IDR</span>' 
                : `<button type="button" class="ant-btn ant-btn-sm" onclick="setDefaultValuta(${r.id}, '${escapeHtml(r.kode)}')"><i class="fa-regular fa-star"></i> Set Base</button>`;

            const curLower = (r.kode || '').toLowerCase();
            const badgeCurClass = ['idr', 'usd', 'eur', 'sgd', 'jpy', 'cny', 'gbp', 'aud'].includes(curLower) ? `badge-kurs-${curLower}` : 'badge-kurs-other';

            const toggleIcon = isAktif ? 'fa-toggle-on' : 'fa-toggle-off';
            const toggleColor = isAktif ? 'color:#52c41a;' : 'color:#8c8c8c;';

            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${no}</td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;"><span class="badge-kurs ${badgeCurClass}">${escapeHtml(r.kode)}</span></td>
                <td><strong>${escapeHtml(r.nama_valuta)}</strong></td>
                <td style="text-align:center; font-weight:700;">${escapeHtml(r.simbol || '-')}</td>
                <td style="text-align:center;">${defaultBadge}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.akun_hutang)}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.akun_piutang)}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="toggleStatus(${r.id})" title="Ubah Status Aktif/Nonaktif">
                        <i class="fa-solid ${toggleIcon}" style="${toggleColor} font-size:13px;"></i>
                    </button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="editManual(${r.id}, '${escapeHtml(r.kode)}', '${escapeHtml(r.nama_valuta)}', '${escapeHtml(r.simbol)}', '${escapeHtml(r.akun_hutang)}', '${escapeHtml(r.akun_piutang)}')" title="Edit Valuta & Akun GL">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="deleteItem(${r.id}, '${escapeHtml(r.kode)}')" title="Hapus dari Daftar" ${isDef ? 'disabled style="opacity:0.4;"' : ''}>
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;

        if (window.valutaGrid) {
            window.valutaGrid.refresh();
        } else if (typeof AntEnterpriseGrid !== 'undefined') {
            window.valutaGrid = new AntEnterpriseGrid('#tblValuta', {
                gridId: 'erp_valuta',
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                skipCols: [0, 9],
                exportTitle: 'Daftar_Mata_Uang_Valuta',
                onEdit: (tr) => {
                    const btn = tr.querySelector('button[onclick*="editManual"]');
                    if (btn) btn.click();
                }
            });
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:16px; color:var(--danger);">${escapeHtml(e.message)}</td></tr>`;
    }
}

function changePageValuta(delta) {
    curPage += delta;
    if (curPage < 1) curPage = 1;
    loadValuta();
}

function resetFilter() {
    document.getElementById('filSearch').value = '';
    document.getElementById('filStatus').value = 'ALL';
    loadValuta(1);
}

// 3. Load Tab 2: Katalog 204 Valuta Dunia
async function loadKatalog() {
    const search = document.getElementById('filKatalogSearch').value.trim();
    const tbody = document.getElementById('tbodyKatalog');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat katalog 204 valuta dunia...</td></tr>';

    try {
        const res = await fetch(`mata_uang.php?action=get_reff_catalog&search=${encodeURIComponent(search)}&limit=250`);
        if (!res.ok) return;
        const json = await res.json();
        const items = json.data || [];

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ditemukan valuta yang cocok.</td></tr>';
            return;
        }

        let html = '';
        items.forEach((r, idx) => {
            const isUsed = (r.is_used === 1);
            const statusLabel = isUsed ? '<span class="badge-aktif">Dipakai</span>' : '<span class="badge-nonaktif">Belum Aktif</span>';
            const actionBtn = isUsed 
                ? '<button type="button" class="ant-btn ant-btn-sm" disabled style="opacity:0.6;"><i class="fa-solid fa-check"></i> Sudah Aktif</button>'
                : `<button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="quickActivate('${escapeHtml(r.kode)}')"><i class="fa-solid fa-plus"></i> Aktifkan</button>`;

            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                <td style="text-align:center;"><span class="badge-kurs badge-kurs-other">${escapeHtml(r.kode)}</span></td>
                <td><strong>${escapeHtml(r.teks)}</strong></td>
                <td style="text-align:center;">${statusLabel}</td>
                <td style="text-align:center;">${actionBtn}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:16px; color:var(--danger);">${escapeHtml(e.message)}</td></tr>`;
    }
}

// 4. Quick Activate Valuta
async function quickActivate(kode) {
    try {
        const fd = new FormData();
        fd.append('codes[]', kode);
        const res = await fetch('mata_uang.php?action=activate_from_reff', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
            loadKatalog();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

// 5. Load Tab 3: Monitoring Kurs Terkini (Pajak & BI)
async function loadMonitoring() {
    const tbody = document.getElementById('tbodyMonitoring');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Mengambil data kurs terkini Pajak KMK dan BI...</td></tr>';

    try {
        const res = await fetch('mata_uang.php?action=get_rates_monitoring');
        if (!res.ok) return;
        const json = await res.json();
        const items = json.data || [];

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada valuta aktif yang dapat dimonitor.</td></tr>';
            return;
        }

        let html = '';
        items.forEach((r, idx) => {
            const curLower = (r.kode || '').toLowerCase();
            const badgeCurClass = ['idr', 'usd', 'eur', 'sgd', 'jpy', 'cny', 'gbp', 'aud'].includes(curLower) ? `badge-kurs-${curLower}` : 'badge-kurs-other';
            const kmkFormatted = (r.kode === 'IDR') ? 'Rp 1,00' : (r.kmk_rate > 0 ? ('Rp ' + formatRupiah(r.kmk_rate, 2)) : '-');
            const biMidFormatted = (r.kode === 'IDR') ? 'Rp 1,00' : (r.bi_mid > 0 ? ('Rp ' + formatRupiah(r.bi_mid, 2)) : '-');
            const biJualFormatted = (r.kode === 'IDR') ? 'Rp 1,00' : (r.bi_jual > 0 ? ('Rp ' + formatRupiah(r.bi_jual, 2)) : '-');
            const biBeliFormatted = (r.kode === 'IDR') ? 'Rp 1,00' : (r.bi_beli > 0 ? ('Rp ' + formatRupiah(r.bi_beli, 2)) : '-');

            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                <td style="text-align:center;"><span class="badge-kurs ${badgeCurClass}">${escapeHtml(r.kode)}</span></td>
                <td><strong>${escapeHtml(r.nama)}</strong></td>
                <td style="text-align:right; font-weight:700; color:#52c41a;">${kmkFormatted}</td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.kmk_no)}</td>
                <td style="text-align:right; font-weight:700; color:var(--primary);">${biMidFormatted}</td>
                <td style="text-align:right;">${biJualFormatted}</td>
                <td style="text-align:right;">${biBeliFormatted}</td>
                <td style="text-align:center; font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.bi_date)}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:16px; color:var(--danger);">${escapeHtml(e.message)}</td></tr>`;
    }
}

// 6. Modal Picker Multi-Select
async function openPickerModal() {
    document.getElementById('pickerSearch').value = '';
    document.getElementById('lblPickerSelectedCount').innerText = '0 dipilih';
    document.getElementById('modalPicker').classList.add('active');

    const tbody = document.getElementById('tbodyPickerList');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat 204 valuta dunia...</td></tr>';

    try {
        const res = await fetch('mata_uang.php?action=get_reff_catalog&limit=250');
        const json = await res.json();
        pickerCatalogCache = json.data || [];
        renderPickerTable();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:var(--danger);">${escapeHtml(e.message)}</td></tr>`;
    }
}

function renderPickerTable(filter = '') {
    const tbody = document.getElementById('tbodyPickerList');
    const f = filter.toLowerCase();
    const filtered = pickerCatalogCache.filter(item => 
        item.kode.toLowerCase().includes(f) || item.teks.toLowerCase().includes(f)
    );

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada valuta yang cocok.</td></tr>';
        return;
    }

    let html = '';
    filtered.forEach(item => {
        const isUsed = (item.is_used === 1);
        const disabledAttr = isUsed ? 'disabled checked' : '';
        const rowStyle = isUsed ? 'opacity:0.6; background:rgba(0,0,0,0.02);' : '';
        const badge = isUsed ? '<span class="badge-aktif" style="font-size:8.5px;">Aktif</span>' : '<span style="font-size:8.5px; color:var(--text-muted);">Tersedia</span>';

        html += `
        <tr style="${rowStyle}">
            <td style="text-align:center;">
                <input type="checkbox" class="picker-chk" value="${escapeHtml(item.kode)}" ${disabledAttr} onchange="updatePickerCount()">
            </td>
            <td style="text-align:center; font-weight:700;">${escapeHtml(item.kode)}</td>
            <td>${escapeHtml(item.teks)}</td>
            <td style="text-align:center;">${badge}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
    updatePickerCount();
}

function filterPickerList() {
    const q = document.getElementById('pickerSearch').value;
    renderPickerTable(q);
}

function toggleSelectAllPicker(checked) {
    document.querySelectorAll('.picker-chk:not(:disabled)').forEach(chk => {
        chk.checked = checked;
    });
    updatePickerCount();
}

function updatePickerCount() {
    const checked = document.querySelectorAll('.picker-chk:checked:not(:disabled)').length;
    document.getElementById('lblPickerSelectedCount').innerText = `${checked} dipilih`;
}

function closePickerModal() {
    document.getElementById('modalPicker').classList.remove('active');
}

async function submitPickerSelection() {
    const checkedNodes = document.querySelectorAll('.picker-chk:checked:not(:disabled)');
    const codes = Array.from(checkedNodes).map(c => c.value);

    if (codes.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Pilih setidaknya satu valuta yang belum aktif.' });
        return;
    }

    const fd = new FormData();
    codes.forEach(c => fd.append('codes[]', c));

    try {
        const res = await fetch('mata_uang.php?action=activate_from_reff', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            closePickerModal();
            Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1500, showConfirmButton: false });
            reloadCurrentTab();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

// 7. Modal Form Manual
function openManualFormModal() {
    document.getElementById('manualFormTitle').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Tambah Valuta Baru';
    document.getElementById('formId').value = '';
    document.getElementById('formKode').value = '';
    document.getElementById('formKode').readOnly = false;
    document.getElementById('formNegara').value = '';
    document.getElementById('formSimbol').value = '';
    document.getElementById('formAkunHutang').value = '';
    document.getElementById('formAkunPiutang').value = '';
    document.getElementById('modalManualForm').classList.add('active');
}

function editManual(id, kode, negara, simbol, akunHutang, akunPiutang) {
    document.getElementById('manualFormTitle').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit Valuta (${escapeHtml(kode)})`;
    document.getElementById('formId').value = id;
    document.getElementById('formKode').value = kode;
    document.getElementById('formKode').readOnly = true;
    document.getElementById('formNegara').value = negara;
    document.getElementById('formSimbol').value = simbol;
    document.getElementById('formAkunHutang').value = (akunHutang === '-' ? '' : akunHutang);
    document.getElementById('formAkunPiutang').value = (akunPiutang === '-' ? '' : akunPiutang);
    document.getElementById('modalManualForm').classList.add('active');
}

function closeManualFormModal() {
    document.getElementById('modalManualForm').classList.remove('active');
}

async function submitManualForm() {
    const id          = document.getElementById('formId').value;
    const kode        = document.getElementById('formKode').value.trim();
    const negara      = document.getElementById('formNegara').value.trim();
    const simbol      = document.getElementById('formSimbol').value.trim();
    const akunHutang  = document.getElementById('formAkunHutang').value.trim();
    const akunPiutang = document.getElementById('formAkunPiutang').value.trim();

    if (!kode) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Kode Valuta wajib diisi!' });
        return;
    }

    const fd = new FormData();
    fd.append('id', id);
    fd.append('kode', kode);
    fd.append('negara', negara);
    fd.append('simbol', simbol);
    fd.append('akun_hutang', akunHutang);
    fd.append('akun_piutang', akunPiutang);

    try {
        const res = await fetch('mata_uang.php?action=save_manual', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            closeManualFormModal();
            Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
            reloadCurrentTab();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

// 8. Set Default Valuta (Base IDR)
async function setDefaultValuta(id, kode) {
    const ask = await Swal.fire({
        title: 'Tetapkan Valuta Dasar',
        text: `Jadikan ${kode} sebagai mata uang dasar operasional (Base Currency)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, Tetapkan',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#1890ff'
    });

    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('mata_uang.php?action=set_default', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
            reloadCurrentTab();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

// 9. Toggle Status
async function toggleStatus(id) {
    try {
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('mata_uang.php?action=toggle_status', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            reloadCurrentTab();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

// 10. Hapus Item
async function deleteItem(id, kode) {
    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus valuta ${kode} dari daftar operasional perusahaan? Valuta ini tidak akan muncul lagi di dropdown pilihan.`,
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
        const res = await fetch('mata_uang.php?action=delete', {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1200, showConfirmButton: false });
            reloadCurrentTab();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan', text: e.message });
    }
}

// 11. Export Excel & CSV
function exportCsv() {
    window.location.href = 'mata_uang.php?action=export_csv';
}

function exportExcel() {
    if (!valutaCache || valutaCache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }

    const exportRows = valutaCache.map((r, i) => ({
        'No': i + 1,
        'Kode Valuta': r.kode,
        'Nama Mata Uang (Negara)': r.nama_valuta,
        'Simbol': r.simbol,
        'Status': (r.status === 1 ? 'Aktif' : 'Non-Aktif'),
        'Valuta Dasar': (r.is_default === 1 ? 'YA (Base)' : 'TIDAK'),
        'Akun GL Hutang': r.akun_hutang,
        'Akun GL Piutang': r.akun_piutang,
        'Pencatat (Set By)': r.set_by,
        'Terakhir Diperbarui': r.updated_at
    }));

    const ws = XLSX.utils.json_to_sheet(exportRows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, "Mata Uang Terpilih");
    XLSX.writeFile(wb, `Mata_Uang_Terpilih_${new Date().toISOString().slice(0,10)}.xlsx`);
}

// 12. Panduan SOP
function openModuleGuide() {
    document.getElementById('modalModuleGuide').classList.add('active');
}
function closeModuleGuide() {
    document.getElementById('modalModuleGuide').classList.remove('active');
}

// Inisialisasi
window.addEventListener('DOMContentLoaded', () => {
    loadKpi();
    loadValuta();
});
</script>
</body>
</html>

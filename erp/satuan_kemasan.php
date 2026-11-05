<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Satuan dan Kemasan Terpilih
 * ============================================================================
 * Acuan Asli PowerBuilder: w_satuan.srw, bc_satuan.srd, bc_kemasan.srd
 * Manajemen Satuan Barang & Jenis Kemasan Aktif Operasional Perusahaan,
 * Bersumber dari Referensi Resmi CEISA 4.0 (1.548 Satuan & 382 Kemasan),
 * Penarikan Otomatis Riwayat Transaksi Barang (History Sync),
 * dan Standardisasi Ant Design Style (Identik Tax Rate).
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
// AUTO-HEAL: Pastikan Tabel bc_ref_satuan & bc_ref_kemasan Tersedia Lengkap
// -------------------------------------------------------------------------
function ensureSatuanKemasanTables($koneksi, $escCmp) {
    // 1. Tabel bc_ref_satuan
    try {
        $check = $koneksi->query("SHOW TABLES LIKE 'bc_ref_satuan'");
        if (!$check || $check->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ref_satuan` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `KODE_SATUAN` varchar(10) NOT NULL DEFAULT '',
                `uraian_satuan` varchar(100) DEFAULT '',
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_cmp_satuan` (`cmp_id`, `KODE_SATUAN`),
                KEY `idx_kode_satuan` (`KODE_SATUAN`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // Auto-heal kolom jika sudah ada dari versi PowerBuilder
            $colId = $koneksi->query("SHOW COLUMNS FROM bc_ref_satuan LIKE 'id'");
            if (!$colId || $colId->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_satuan ADD COLUMN id INT AUTO_INCREMENT UNIQUE FIRST");
            }
            $colCmp = $koneksi->query("SHOW COLUMNS FROM bc_ref_satuan LIKE 'cmp_id'");
            if (!$colCmp || $colCmp->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_satuan ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT ''");
            }
            $colUraian = $koneksi->query("SHOW COLUMNS FROM bc_ref_satuan LIKE 'uraian_satuan'");
            if (!$colUraian || $colUraian->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_satuan ADD COLUMN uraian_satuan VARCHAR(100) DEFAULT ''");
            }
            $colStatus = $koneksi->query("SHOW COLUMNS FROM bc_ref_satuan LIKE 'status'");
            if (!$colStatus || $colStatus->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_satuan ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1");
            }
            $colSetBy = $koneksi->query("SHOW COLUMNS FROM bc_ref_satuan LIKE 'set_by'");
            if (!$colSetBy || $colSetBy->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_satuan ADD COLUMN set_by VARCHAR(100) DEFAULT ''");
            }
            $colCreated = $koneksi->query("SHOW COLUMNS FROM bc_ref_satuan LIKE 'created_at'");
            if (!$colCreated || $colCreated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_satuan ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            $colUpdated = $koneksi->query("SHOW COLUMNS FROM bc_ref_satuan LIKE 'updated_at'");
            if (!$colUpdated || $colUpdated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_satuan ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    } catch (\Throwable $ex) {}

    // 2. Tabel bc_ref_kemasan
    try {
        $checkK = $koneksi->query("SHOW TABLES LIKE 'bc_ref_kemasan'");
        if (!$checkK || $checkK->num_rows === 0) {
            $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_ref_kemasan` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cmp_id` varchar(30) NOT NULL DEFAULT '',
                `KODE_KEMASAN` varchar(10) NOT NULL DEFAULT '',
                `uraian_kemasan` varchar(100) DEFAULT '',
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `set_by` varchar(100) DEFAULT '',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_cmp_kemasan` (`cmp_id`, `KODE_KEMASAN`),
                KEY `idx_kode_kemasan` (`KODE_KEMASAN`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $colId = $koneksi->query("SHOW COLUMNS FROM bc_ref_kemasan LIKE 'id'");
            if (!$colId || $colId->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kemasan ADD COLUMN id INT AUTO_INCREMENT UNIQUE FIRST");
            }
            $colCmp = $koneksi->query("SHOW COLUMNS FROM bc_ref_kemasan LIKE 'cmp_id'");
            if (!$colCmp || $colCmp->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kemasan ADD COLUMN cmp_id VARCHAR(30) NOT NULL DEFAULT ''");
            }
            $colUraian = $koneksi->query("SHOW COLUMNS FROM bc_ref_kemasan LIKE 'uraian_kemasan'");
            if (!$colUraian || $colUraian->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kemasan ADD COLUMN uraian_kemasan VARCHAR(100) DEFAULT ''");
            }
            $colStatus = $koneksi->query("SHOW COLUMNS FROM bc_ref_kemasan LIKE 'status'");
            if (!$colStatus || $colStatus->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kemasan ADD COLUMN status TINYINT(1) NOT NULL DEFAULT 1");
            }
            $colSetBy = $koneksi->query("SHOW COLUMNS FROM bc_ref_kemasan LIKE 'set_by'");
            if (!$colSetBy || $colSetBy->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kemasan ADD COLUMN set_by VARCHAR(100) DEFAULT ''");
            }
            $colCreated = $koneksi->query("SHOW COLUMNS FROM bc_ref_kemasan LIKE 'created_at'");
            if (!$colCreated || $colCreated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kemasan ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
            }
            $colUpdated = $koneksi->query("SHOW COLUMNS FROM bc_ref_kemasan LIKE 'updated_at'");
            if (!$colUpdated || $colUpdated->num_rows === 0) {
                @$koneksi->query("ALTER TABLE bc_ref_kemasan ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
        }
    } catch (\Throwable $ex) {}

    // 3. Seed Awal Satuan jika masih kosong
    $cntS = $koneksi->query("SELECT COUNT(*) FROM bc_ref_satuan WHERE cmp_id = '$escCmp'")->fetch_row()[0] ?? 0;
    if ((int)$cntS === 0) {
        $defaultSatuan = [
            ['PCE', 'PIECE (BUAH/PCS)', 'SYSTEM - Auto Init'],
            ['KGM', 'KILOGRAM', 'SYSTEM - Auto Init'],
            ['MTR', 'METER', 'SYSTEM - Auto Init'],
            ['SET', 'SET (PAKET)', 'SYSTEM - Auto Init'],
            ['RO',  'ROLL', 'SYSTEM - Auto Init'],
            ['BOX', 'BOX (KOTAK)', 'SYSTEM - Auto Init'],
            ['LTR', 'LITER', 'SYSTEM - Auto Init'],
            ['GRM', 'GRAM', 'SYSTEM - Auto Init'],
            ['YRD', 'YARD', 'SYSTEM - Auto Init'],
            ['SHT', 'SHEET (LEMBAR)', 'SYSTEM - Auto Init'],
            ['PR',  'PAIR (PASANG)', 'SYSTEM - Auto Init'],
            ['DZN', 'DOZEN (LUSIN)', 'SYSTEM - Auto Init'],
            ['TNE', 'TON (METRIC TON)', 'SYSTEM - Auto Init']
        ];
        foreach ($defaultSatuan as $s) {
            $koneksi->query("INSERT IGNORE INTO bc_ref_satuan (cmp_id, KODE_SATUAN, uraian_satuan, status, set_by)
                             VALUES ('$escCmp', '{$s[0]}', '{$s[1]}', 1, '{$s[2]}')");
        }
    }

    // 4. Seed Awal Kemasan jika masih kosong
    $cntK = $koneksi->query("SELECT COUNT(*) FROM bc_ref_kemasan WHERE cmp_id = '$escCmp'")->fetch_row()[0] ?? 0;
    if ((int)$cntK === 0) {
        $defaultKemasan = [
            ['CT', 'CARTON (KARTON)', 'SYSTEM - Auto Init'],
            ['PK', 'PACKAGE (PAKET/BUNGKUS)', 'SYSTEM - Auto Init'],
            ['BX', 'BOX (KOTAK)', 'SYSTEM - Auto Init'],
            ['BG', 'BAG (KANTONG/KARUNG)', 'SYSTEM - Auto Init'],
            ['PL', 'PALLET', 'SYSTEM - Auto Init'],
            ['DR', 'DRUM', 'SYSTEM - Auto Init'],
            ['CS', 'CASE (PETI)', 'SYSTEM - Auto Init'],
            ['RO', 'ROLL', 'SYSTEM - Auto Init'],
            ['NE', 'UNPACKED OR UNPACKAGED', 'SYSTEM - Auto Init'],
            ['PX', 'PALLET (WOODEN/PLASTIC)', 'SYSTEM - Auto Init']
        ];
        foreach ($defaultKemasan as $k) {
            $koneksi->query("INSERT IGNORE INTO bc_ref_kemasan (cmp_id, KODE_KEMASAN, uraian_kemasan, status, set_by)
                             VALUES ('$escCmp', '{$k[0]}', '{$k[1]}', 1, '{$k[2]}')");
        }
    }
}
ensureSatuanKemasanTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API
// =========================================================================
$action = $_REQUEST['action'] ?? '';

// 1. GET MINI DASHBOARD KPI
if ($action === 'get_kpi') {
    header('Content-Type: application/json; charset=utf-8');

    $cntSatuanAktif  = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_satuan WHERE cmp_id = '$escCmp' AND (status = 1 OR status IS NULL)")->fetch_row()[0] ?? 0);
    $cntKemasanAktif = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ref_kemasan WHERE cmp_id = '$escCmp' AND (status = 1 OR status IS NULL)")->fetch_row()[0] ?? 0);
    $cntReffSatuan   = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ceisa_reff WHERE reff = 'SATUAN BARANG'")->fetch_row()[0] ?? 1548);
    $cntReffKemasan  = (int)($koneksi->query("SELECT COUNT(*) FROM bc_ceisa_reff WHERE reff = 'JENIS KEMASAN'")->fetch_row()[0] ?? 382);

    echo json_encode([
        'status'            => 'OK',
        'satuan_aktif'      => $cntSatuanAktif,
        'kemasan_aktif'     => $cntKemasanAktif,
        'total_reff_satuan' => $cntReffSatuan,
        'total_reff_kemasan'=> $cntReffKemasan,
        'total_terpilih'    => ($cntSatuanAktif + $cntKemasanAktif)
    ]);
    exit;
}

// 2. LIST SATUAN TERPILIH
if ($action === 'list_satuan') {
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
        $where[] = "(a.KODE_SATUAN LIKE '%$sEsc%' OR a.uraian_satuan LIKE '%$sEsc%' OR r.teks LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM bc_ref_satuan a 
                 LEFT JOIN bc_ceisa_reff r ON (r.reff = 'SATUAN BARANG' AND r.kode = a.KODE_SATUAN)
                 WHERE $wSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    $sql = "SELECT a.id, a.KODE_SATUAN as kode, 
                   COALESCE(NULLIF(a.uraian_satuan, ''), r.teks, a.KODE_SATUAN) as uraian,
                   COALESCE(a.status, 1) as status, 
                   a.set_by, a.updated_at
            FROM bc_ref_satuan a
            LEFT JOIN bc_ceisa_reff r ON (r.reff = 'SATUAN BARANG' AND r.kode = a.KODE_SATUAN)
            WHERE $wSql
            ORDER BY a.status DESC, a.KODE_SATUAN ASC
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'         => (int)$r['id'],
                'kode'       => strtoupper(trim($r['kode'])),
                'uraian'     => $r['uraian'],
                'status'     => (int)$r['status'],
                'set_by'     => $r['set_by'] ?: 'SYSTEM',
                'updated_at' => $r['updated_at']
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

// 3. LIST KEMASAN TERPILIH
if ($action === 'list_kemasan') {
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
        $where[] = "(a.KODE_KEMASAN LIKE '%$sEsc%' OR a.uraian_kemasan LIKE '%$sEsc%' OR r.teks LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM bc_ref_kemasan a 
                 LEFT JOIN bc_ceisa_reff r ON (r.reff = 'JENIS KEMASAN' AND r.kode = a.KODE_KEMASAN)
                 WHERE $wSql";
    $total = (int)($koneksi->query($countSql)->fetch_row()[0] ?? 0);

    $sql = "SELECT a.id, a.KODE_KEMASAN as kode, 
                   COALESCE(NULLIF(a.uraian_kemasan, ''), r.teks, a.KODE_KEMASAN) as uraian,
                   COALESCE(a.status, 1) as status, 
                   a.set_by, a.updated_at
            FROM bc_ref_kemasan a
            LEFT JOIN bc_ceisa_reff r ON (r.reff = 'JENIS KEMASAN' AND r.kode = a.KODE_KEMASAN)
            WHERE $wSql
            ORDER BY a.status DESC, a.KODE_KEMASAN ASC
            LIMIT $offset, $limit";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'         => (int)$r['id'],
                'kode'       => strtoupper(trim($r['kode'])),
                'uraian'     => $r['uraian'],
                'status'     => (int)$r['status'],
                'set_by'     => $r['set_by'] ?: 'SYSTEM',
                'updated_at' => $r['updated_at']
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

// 4. KATALOG MASTER REFERENSI CEISA 4.0 (Untuk Modal Picker)
if ($action === 'get_reff_catalog') {
    header('Content-Type: application/json; charset=utf-8');
    $type   = trim($_GET['type'] ?? 'satuan'); // 'satuan' or 'kemasan'
    $search = trim($_GET['search'] ?? '');
    $limit  = max(20, min(500, (int)($_GET['limit'] ?? 100)));

    $reffName = ($type === 'kemasan') ? 'JENIS KEMASAN' : 'SATUAN BARANG';
    $joinTbl  = ($type === 'kemasan') ? 'bc_ref_kemasan' : 'bc_ref_satuan';
    $colKode  = ($type === 'kemasan') ? 'KODE_KEMASAN' : 'KODE_SATUAN';

    $where = ["r.reff = '$reffName'"];
    if (!empty($search)) {
        $sEsc = $koneksi->real_escape_string($search);
        $where[] = "(r.kode LIKE '%$sEsc%' OR r.teks LIKE '%$sEsc%')";
    }
    $wSql = implode(' AND ', $where);

    $sql = "SELECT r.kode, r.teks, 
                   IF(u.$colKode IS NOT NULL, 1, 0) as is_used
            FROM bc_ceisa_reff r
            LEFT JOIN $joinTbl u ON (u.$colKode = r.kode AND (u.cmp_id = '$escCmp' OR u.cmp_id = ''))
            WHERE $wSql
            ORDER BY is_used DESC, r.kode ASC
            LIMIT $limit";
    $res = $koneksi->query($sql);
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'kode'    => $r['kode'],
                'teks'    => $r['teks'],
                'is_used' => (int)$r['is_used']
            ];
        }
    }

    echo json_encode(['status' => 'OK', 'type' => $type, 'data' => $items]);
    exit;
}

// 5. AKTIFKAN ITEM DARI REFERENSI (Single or Batch)
if ($action === 'activate_from_reff') {
    header('Content-Type: application/json; charset=utf-8');
    $type  = trim($_POST['type'] ?? 'satuan');
    $codes = $_POST['codes'] ?? [];
    if (!is_array($codes)) {
        $codes = [$codes];
    }
    $codes = array_filter(array_map('trim', $codes));

    if (empty($codes)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih setidaknya satu kode untuk diaktifkan.']);
        exit;
    }

    $reffName = ($type === 'kemasan') ? 'JENIS KEMASAN' : 'SATUAN BARANG';
    $targetTbl = ($type === 'kemasan') ? 'bc_ref_kemasan' : 'bc_ref_satuan';
    $colKode   = ($type === 'kemasan') ? 'KODE_KEMASAN' : 'KODE_SATUAN';
    $colUraian = ($type === 'kemasan') ? 'uraian_kemasan' : 'uraian_satuan';
    $setBy     = $koneksi->real_escape_string($currentUser . ' (Katalog CEISA)');

    $saved = 0;
    foreach ($codes as $c) {
        $cEsc = $koneksi->real_escape_string(strtoupper($c));
        // Ambil uraian dari bc_ceisa_reff
        $qRef = $koneksi->query("SELECT teks FROM bc_ceisa_reff WHERE reff = '$reffName' AND kode = '$cEsc' LIMIT 1");
        $uraian = ($qRef && $rRow = $qRef->fetch_assoc()) ? $rRow['teks'] : $cEsc;
        $uEsc = $koneksi->real_escape_string($uraian);

        $sql = "INSERT INTO $targetTbl (cmp_id, $colKode, $colUraian, status, set_by)
                VALUES ('$escCmp', '$cEsc', '$uEsc', 1, '$setBy')
                ON DUPLICATE KEY UPDATE 
                $colUraian = VALUES($colUraian),
                status = 1,
                set_by = VALUES(set_by)";
        if ($koneksi->query($sql)) {
            $saved++;
        }
    }

    echo json_encode([
        'status'  => 'OK',
        'message' => "Berhasil mengaktifkan $saved " . ($type === 'kemasan' ? 'kemasan' : 'satuan') . " untuk operasional perusahaan."
    ]);
    exit;
}

// 6. TOGGLE STATUS AKTIF / NON-AKTIF
if ($action === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    $type = trim($_POST['type'] ?? 'satuan');
    $id   = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID data tidak valid.']);
        exit;
    }

    $tbl = ($type === 'kemasan') ? 'bc_ref_kemasan' : 'bc_ref_satuan';
    $setBy = $koneksi->real_escape_string($currentUser . ' - ' . date('Y-m-d H:i'));

    $sql = "UPDATE $tbl SET status = IF(status = 1, 0, 1), set_by = '$setBy' WHERE id = $id";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Status berhasil diubah.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah status: ' . $koneksi->error]);
    }
    exit;
}

// 7. SIMPAN / EDIT MANUAL
if ($action === 'save_manual') {
    header('Content-Type: application/json; charset=utf-8');
    $type   = trim($_POST['type'] ?? 'satuan');
    $id     = (int)($_POST['id'] ?? 0);
    $kode   = strtoupper(trim($_POST['kode'] ?? ''));
    $uraian = trim($_POST['uraian'] ?? '');

    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode wajib diisi.']);
        exit;
    }

    $tbl       = ($type === 'kemasan') ? 'bc_ref_kemasan' : 'bc_ref_satuan';
    $colKode   = ($type === 'kemasan') ? 'KODE_KEMASAN' : 'KODE_SATUAN';
    $colUraian = ($type === 'kemasan') ? 'uraian_kemasan' : 'uraian_satuan';

    $cEsc = $koneksi->real_escape_string($kode);
    $uEsc = $koneksi->real_escape_string($uraian ?: $kode);
    $setBy = $koneksi->real_escape_string($currentUser . ' - ' . date('Y-m-d H:i'));

    if ($id > 0) {
        $sql = "UPDATE $tbl SET $colKode = '$cEsc', $colUraian = '$uEsc', set_by = '$setBy' WHERE id = $id";
    } else {
        $sql = "INSERT INTO $tbl (cmp_id, $colKode, $colUraian, status, set_by)
                VALUES ('$escCmp', '$cEsc', '$uEsc', 1, '$setBy')
                ON DUPLICATE KEY UPDATE $colUraian = VALUES($colUraian), status = 1, set_by = VALUES(set_by)";
    }

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Data berhasil disimpan.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan: ' . $koneksi->error]);
    }
    exit;
}

// 8. HAPUS ITEM TERPILIH
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $type = trim($_POST['type'] ?? 'satuan');
    $id   = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID data tidak valid.']);
        exit;
    }

    $tbl = ($type === 'kemasan') ? 'bc_ref_kemasan' : 'bc_ref_satuan';
    if ($koneksi->query("DELETE FROM $tbl WHERE id = $id")) {
        echo json_encode(['status' => 'OK', 'message' => 'Data berhasil dihapus dari daftar operasional perusahaan.']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus: ' . $koneksi->error]);
    }
    exit;
}

// 9. AMBIL DARI RIWAYAT TRANSAKSI / MASTER BARANG (Mengadopsi cb_1 di w_satuan.srw PB)
if ($action === 'sync_from_history') {
    header('Content-Type: application/json; charset=utf-8');
    $setBy = $koneksi->real_escape_string($currentUser . ' (Auto Sync Riwayat)');

    $satuanAdded = 0;
    $kemasanAdded = 0;

    // A. Sinkronisasi Satuan dari bc_material / tpb_barang
    $qMat = $koneksi->query("SELECT DISTINCT kode_satuan FROM bc_material WHERE kode_satuan IS NOT NULL AND kode_satuan != ''");
    if ($qMat) {
        while ($r = $qMat->fetch_assoc()) {
            $ks = strtoupper(trim($r['kode_satuan']));
            if (empty($ks)) continue;
            $qRef = $koneksi->query("SELECT teks FROM bc_ceisa_reff WHERE reff = 'SATUAN BARANG' AND kode = '$ks' LIMIT 1");
            $ur = ($qRef && $rRef = $qRef->fetch_assoc()) ? $rRef['teks'] : $ks;
            $urEsc = $koneksi->real_escape_string($ur);

            $ins = $koneksi->query("INSERT INTO bc_ref_satuan (cmp_id, KODE_SATUAN, uraian_satuan, status, set_by)
                                    VALUES ('$escCmp', '$ks', '$urEsc', 1, '$setBy')
                                    ON DUPLICATE KEY UPDATE status = 1");
            if ($ins && $koneksi->affected_rows > 0) $satuanAdded++;
        }
    }

    // B. Sinkronisasi Kemasan dari bc_ceisa_kemasan / tpb_barang
    $qKem = $koneksi->query("SELECT DISTINCT kodeKemasan FROM bc_ceisa_kemasan WHERE kodeKemasan IS NOT NULL AND kodeKemasan != ''");
    if ($qKem) {
        while ($r = $qKem->fetch_assoc()) {
            $kk = strtoupper(trim($r['kodeKemasan']));
            if (empty($kk)) continue;
            $qRef = $koneksi->query("SELECT teks FROM bc_ceisa_reff WHERE reff = 'JENIS KEMASAN' AND kode = '$kk' LIMIT 1");
            $ur = ($qRef && $rRef = $qRef->fetch_assoc()) ? $rRef['teks'] : $kk;
            $urEsc = $koneksi->real_escape_string($ur);

            $ins = $koneksi->query("INSERT INTO bc_ref_kemasan (cmp_id, KODE_KEMASAN, uraian_kemasan, status, set_by)
                                    VALUES ('$escCmp', '$kk', '$urEsc', 1, '$setBy')
                                    ON DUPLICATE KEY UPDATE status = 1");
            if ($ins && $koneksi->affected_rows > 0) $kemasanAdded++;
        }
    }

    echo json_encode([
        'status'  => 'OK',
        'message' => "Penyelarasan selesai! Ditemukan & disinkronkan satuan dan kemasan aktif dari riwayat transaksi material.",
        'satuan_added'  => $satuanAdded,
        'kemasan_added' => $kemasanAdded
    ]);
    exit;
}

// 10. EXPORT DATA KE CSV
if ($action === 'export_csv') {
    $type = trim($_GET['type'] ?? 'satuan');
    $tbl = ($type === 'kemasan') ? 'bc_ref_kemasan' : 'bc_ref_satuan';
    $colKode = ($type === 'kemasan') ? 'KODE_KEMASAN' : 'KODE_SATUAN';
    $colUraian = ($type === 'kemasan') ? 'uraian_kemasan' : 'uraian_satuan';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Daftar_' . ucfirst($type) . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Kode ' . ucfirst($type), 'Nama / Uraian Lengkap', 'Status', 'Pencatat', 'Terakhir Diperbarui']);

    $q = $koneksi->query("SELECT $colKode as kode, $colUraian as uraian, status, set_by, updated_at FROM $tbl WHERE cmp_id = '$escCmp' ORDER BY status DESC, $colKode ASC");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            fputcsv($out, [
                $r['kode'],
                $r['uraian'],
                ((int)$r['status'] === 1 ? 'Aktif' : 'Nonaktif'),
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
    <title>Satuan dan Kemasan Terpilih - <?= htmlspecialchars($activeCmpName) ?></title>
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
        .badge-code { 
            font-weight: 700; 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-size: 10px; 
            display: inline-block; 
            text-align: center; 
            min-width: 44px; 
            background: rgba(24, 144, 255, 0.1); 
            color: var(--primary); 
            border: 1px solid rgba(24, 144, 255, 0.3); 
        }

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
                <i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i>
                <span>Satuan dan Kemasan Terpilih - Referensi Operasional ERP</span>
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
                <div class="kpi-icon" style="background:rgba(24,144,255,0.12); color:var(--primary);">
                    <i class="fa-solid fa-scale-unbalanced"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiSatuanAktif">0 Satuan</div>
                    <div class="kpi-label">Satuan Aktif Perusahaan</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(82,196,26,0.12); color:var(--success);">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiKemasanAktif">0 Kemasan</div>
                    <div class="kpi-label">Jenis Kemasan Aktif Perusahaan</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(250,173,20,0.12); color:var(--warning);">
                    <i class="fa-solid fa-database"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiKatalogReff">1.548 S / 382 K</div>
                    <div class="kpi-label">Total Referensi Pabean CEISA 4.0</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(114,46,209,0.12); color:#722ed1;">
                    <i class="fa-solid fa-filter-circle-dollar"></i>
                </div>
                <div>
                    <div class="kpi-num" id="kpiTotalAktif">0 Terpilih</div>
                    <div class="kpi-label">Pilihan Efektif di Dropdown</div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="ant-tabs-nav">
            <button type="button" class="ant-tab-btn active" data-tab="satuan" onclick="switchTab('satuan')">
                <i class="fa-solid fa-scale-unbalanced"></i> 1. Satuan Barang Terpilih
            </button>
            <button type="button" class="ant-tab-btn" data-tab="kemasan" onclick="switchTab('kemasan')">
                <i class="fa-solid fa-box-open"></i> 2. Jenis Kemasan Terpilih
            </button>
            <button type="button" class="ant-tab-btn" data-tab="katalog" onclick="switchTab('katalog')">
                <i class="fa-solid fa-book-atlas"></i> 3. Katalog Referensi Pabean CEISA 4.0
            </button>
        </div>

        <!-- Toolbar Aksi Ant Design -->
        <div class="ant-toolbar">
            <button type="button" class="ant-btn ant-btn-primary" onclick="openPickerModal()">
                <i class="fa-solid fa-plus-circle"></i> Pilih dari Referensi CEISA 4.0
            </button>
            <button type="button" class="ant-btn" onclick="openManualFormModal()">
                <i class="fa-solid fa-pen-to-square"></i> Tambah Manual
            </button>
            <button type="button" class="ant-btn ant-btn-success" onclick="syncFromHistory()" title="Pindai Satuan &amp; Kemasan yang sudah terdaftar di Master Barang &amp; Transaksi (Fitur Asli PowerBuilder)">
                <i class="fa-solid fa-arrows-rotate"></i> Ambil dari Riwayat Transaksi (Material)
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

        <!-- TAB 1: SATUAN BARANG TERPILIH -->
        <section class="ant-page active" id="pageSatuan">
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Status:</label>
                    <select class="ant-input" id="filStatusSatuan" onchange="loadSatuan(1)">
                        <option value="ALL">Semua Status</option>
                        <option value="1" selected>Aktif Saja (Dipakai)</option>
                        <option value="0">Non-Aktif (Disembunyikan)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <input type="text" class="ant-input" id="filSearchSatuan" placeholder="Cari Kode / Nama Satuan..." style="width:200px;" onkeyup="if(event.key==='Enter') loadSatuan(1);">
                </div>
                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadSatuan(1)">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="resetFilterSatuan()">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
            </div>
            <div class="table-wrap">
                <table class="ant-table" id="tblSatuan">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;">No</th>
                            <th style="width:90px; text-align:center;">Status</th>
                            <th style="width:90px; text-align:center;">Kode Satuan</th>
                            <th style="width:280px;">Nama / Uraian Lengkap</th>
                            <th>Jejak Audit (Pencatat)</th>
                            <th style="width:140px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodySatuan">
                        <tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data satuan...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="ant-table-footer">
                <div>Total Satuan: <strong id="lblTotalSatuan">0</strong> baris | Halaman <span id="lblCurPageSatuan">1</span> dari <span id="lblTotalPagesSatuan">1</span></div>
                <div style="display:flex; gap:4px; align-items:center;">
                    <button type="button" class="ant-btn ant-btn-sm" id="btnPrevSatuan" onclick="changePageSatuan(-1)"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</button>
                    <button type="button" class="ant-btn ant-btn-sm" id="btnNextSatuan" onclick="changePageSatuan(1)">Selanjutnya <i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
        </section>

        <!-- TAB 2: JENIS KEMASAN TERPILIH -->
        <section class="ant-page" id="pageKemasan">
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Status:</label>
                    <select class="ant-input" id="filStatusKemasan" onchange="loadKemasan(1)">
                        <option value="ALL">Semua Status</option>
                        <option value="1" selected>Aktif Saja (Dipakai)</option>
                        <option value="0">Non-Aktif (Disembunyikan)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <input type="text" class="ant-input" id="filSearchKemasan" placeholder="Cari Kode / Nama Kemasan..." style="width:200px;" onkeyup="if(event.key==='Enter') loadKemasan(1);">
                </div>
                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadKemasan(1)">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="resetFilterKemasan()">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
            </div>
            <div class="table-wrap">
                <table class="ant-table" id="tblKemasan">
                    <thead>
                        <tr>
                            <th style="width:40px; text-align:center;">No</th>
                            <th style="width:90px; text-align:center;">Status</th>
                            <th style="width:90px; text-align:center;">Kode Kemasan</th>
                            <th style="width:280px;">Nama / Uraian Kemasan</th>
                            <th>Jejak Audit (Pencatat)</th>
                            <th style="width:140px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyKemasan">
                        <tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data kemasan...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="ant-table-footer">
                <div>Total Kemasan: <strong id="lblTotalKemasan">0</strong> baris | Halaman <span id="lblCurPageKemasan">1</span> dari <span id="lblTotalPagesKemasan">1</span></div>
                <div style="display:flex; gap:4px; align-items:center;">
                    <button type="button" class="ant-btn ant-btn-sm" id="btnPrevKemasan" onclick="changePageKemasan(-1)"><i class="fa-solid fa-chevron-left"></i> Sebelumnya</button>
                    <button type="button" class="ant-btn ant-btn-sm" id="btnNextKemasan" onclick="changePageKemasan(1)">Selanjutnya <i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
        </section>

        <!-- TAB 3: KATALOG MASTER REFERENSI CEISA 4.0 -->
        <section class="ant-page" id="pageKatalog">
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Kategori Referensi:</label>
                    <select class="ant-input" id="filKatalogType" onchange="loadKatalog()">
                        <option value="satuan" selected>Satuan Barang (1.548 Satuan)</option>
                        <option value="kemasan">Jenis Kemasan (382 Kemasan)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <input type="text" class="ant-input" id="filKatalogSearch" placeholder="Cari di seluruh katalog pabean..." style="width:240px;" onkeyup="if(event.key==='Enter') loadKatalog();">
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
                            <th style="width:100px; text-align:center;">Kode Pabean</th>
                            <th style="width:340px;">Uraian Referensi Resmi CEISA 4.0</th>
                            <th style="width:110px; text-align:center;">Status di ERP</th>
                            <th style="width:120px; text-align:center;">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyKatalog">
                        <tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat katalog referensi...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<!-- MODAL: PEMILIH CEPAT MULTI-SELECT DARI REFERENSI PABEAN -->
<div class="modal" id="modalPicker">
    <div class="modal-card">
        <div class="modal-h">
            <span id="pickerTitle"><i class="fa-solid fa-plus-circle"></i> Pilih Satuan dari Referensi CEISA 4.0</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closePickerModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="filter-bar" style="border-bottom:1px solid var(--border-color); background:var(--bg-card);">
            <input type="text" class="ant-input" id="pickerSearch" placeholder="Cari kode atau nama..." style="flex:1;" oninput="filterPickerList()">
            <button type="button" class="ant-btn ant-btn-sm" onclick="toggleSelectAllPicker(true)">Pilih Semua</button>
            <button type="button" class="ant-btn ant-btn-sm" onclick="toggleSelectAllPicker(false)">Batal Pilih</button>
        </div>
        <div class="modal-b" style="padding:0; max-height:360px;">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:35px; text-align:center;">Pilih</th>
                        <th style="width:80px; text-align:center;">Kode</th>
                        <th>Uraian Referensi</th>
                        <th style="width:80px; text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody id="tbodyPickerList">
                    <tr><td colspan="4" style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat referensi...</td></tr>
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

<!-- MODAL: FORM ENTRI / EDIT MANUAL -->
<div class="modal" id="modalManualForm">
    <div class="modal-card" style="max-width:440px;">
        <div class="modal-h">
            <span id="manualFormTitle"><i class="fa-solid fa-pen-to-square"></i> Tambah Baru</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeManualFormModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b">
            <input type="hidden" id="formId">
            <div class="fld-row">
                <label id="lblFormKode">Kode Satuan:</label>
                <input type="text" class="ant-input" id="formKode" placeholder="Contoh: PCE" required style="text-transform:uppercase; font-weight:700;">
            </div>
            <div class="fld-row">
                <label id="lblFormUraian">Nama / Uraian Lengkap:</label>
                <input type="text" class="ant-input" id="formUraian" placeholder="Contoh: Piece (Buah/PCS)" required>
            </div>
        </div>
        <div class="modal-f">
            <button type="button" class="ant-btn" onclick="closeManualFormModal()">Batal</button>
            <button type="button" class="ant-btn ant-btn-primary" onclick="submitManualForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan</button>
        </div>
    </div>
</div>

<!-- MODAL: PANDUAN MODUL & SOP SATUAN DAN KEMASAN -->
<div class="modal" id="modalModuleGuide">
    <div class="modal-card" style="max-width:780px; max-height:88vh;">
        <div class="modal-h" style="background:linear-gradient(135deg, rgba(24,144,255,0.08), rgba(24,144,255,0.02));">
            <span style="font-weight:700; color:var(--primary); font-size:13px; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-book-open"></i> Panduan Lengkap: Satuan dan Kemasan Terpilih
            </span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-b" style="padding:16px 20px; overflow-y:auto; line-height:1.55; font-size:11px;">
            <div style="background:rgba(24,144,255,0.06); border-left:3px solid var(--primary); padding:10px 14px; border-radius:4px; margin-bottom:16px;">
                <strong style="color:var(--primary); font-size:11.5px;">Tujuan &amp; Tata Kelola Referensi Terpilih:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main);">Master referensi pabean CEISA 4.0 memuat <strong>1.548 Satuan Barang</strong> dan <strong>382 Jenis Kemasan</strong>. Modul ini memungkinkan perusahaan mengaktifkan hanya satuan dan kemasan yang relevan dengan jenis usaha dan operasional pabrik, sehingga saat entri transaksi tidak dibebani ribuan pilihan.</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#1890ff; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-circle-check"></i> 1. Penggunaan di Dropdown Transaksi
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li>Dropdown di menu <strong>Master Barang</strong>, <strong>Purchasing (PR, PO, RI, PI)</strong>, dan <strong>Dokumen Pabean</strong> hanya menampilkan satuan &amp; kemasan yang berstatus <strong>Aktif</strong>.</li>
                        <li>Satuan yang dinonaktifkan tidak akan muncul di dropdown baru, namun transaksi lama tetap aman dan valid.</li>
                    </ul>
                </div>

                <div style="border:1px solid var(--border-color); border-radius:6px; padding:12px; background:var(--bg-card);">
                    <div style="font-weight:700; color:#52c41a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-arrows-rotate"></i> 2. Ambil dari Riwayat Transaksi
                    </div>
                    <ul style="margin:0; padding-left:18px; color:var(--text-main);">
                        <li>Gunakan tombol <strong>"Ambil dari Riwayat Transaksi (Material)"</strong> untuk memindai otomatis seluruh satuan dan kemasan yang sudah pernah digunakan di master barang.</li>
                        <li>Sistem otomatis mengaktifkan kode terkait tanpa perlu entri manual satu per satu.</li>
                    </ul>
                </div>
            </div>

            <div style="background:rgba(250,173,20,0.08); border:1px solid rgba(250,173,20,0.3); border-radius:6px; padding:10px 14px;">
                <strong style="color:#d48806;"><i class="fa-solid fa-triangle-exclamation"></i> Kepatuhan Standar CEISA 4.0:</strong>
                <p style="margin:4px 0 0 0; color:var(--text-main); font-size:10.5px;">Pastikan kode satuan dan kemasan yang digunakan selalu sesuai dengan kode resmi Bea Cukai (misal: <code>KGM</code> untuk Kilogram, <code>PCE</code> untuk Pieces, <code>CT</code> untuk Carton) agar tidak terjadi penolakan (reject) saat transmisi dokumen kepabeanan.</p>
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
let currentTab = 'satuan';
let curPageSatuan = 1;
let curPageKemasan = 1;
const pageLimit = 25;
let satuanCache = [];
let kemasanCache = [];
let pickerCatalogCache = [];

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Switch Tabs
function switchTab(tabId) {
    currentTab = tabId;
    document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-tab') === tabId));
    document.querySelectorAll('.ant-page').forEach(p => p.classList.remove('active'));

    if (tabId === 'satuan') {
        document.getElementById('pageSatuan').classList.add('active');
        loadSatuan();
    } else if (tabId === 'kemasan') {
        document.getElementById('pageKemasan').classList.add('active');
        loadKemasan();
    } else if (tabId === 'katalog') {
        document.getElementById('pageKatalog').classList.add('active');
        loadKatalog();
    }
}

function reloadCurrentTab() {
    loadKpi();
    if (currentTab === 'satuan') loadSatuan();
    else if (currentTab === 'kemasan') loadKemasan();
    else if (currentTab === 'katalog') loadKatalog();
}

// 1. KPI Loading
async function loadKpi() {
    try {
        const res = await fetch('satuan_kemasan.php?action=get_kpi');
        if (!res.ok) return;
        const json = await res.json();
        if (json.status === 'OK') {
            document.getElementById('kpiSatuanAktif').innerText = (json.satuan_aktif || 0) + ' Satuan';
            document.getElementById('kpiKemasanAktif').innerText = (json.kemasan_aktif || 0) + ' Kemasan';
            document.getElementById('kpiKatalogReff').innerText = (json.total_reff_satuan || 1548) + ' S / ' + (json.total_reff_kemasan || 382) + ' K';
            document.getElementById('kpiTotalAktif').innerText = (json.total_terpilih || 0) + ' Terpilih';
        }
    } catch (e) {
        console.error('Error KPI:', e);
    }
}

// 2. Load Satuan
async function loadSatuan(page = null) {
    if (page !== null) curPageSatuan = page;
    const search = document.getElementById('filSearchSatuan').value.trim();
    const status = document.getElementById('filStatusSatuan').value;
    const tbody  = document.getElementById('tbodySatuan');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data satuan...</td></tr>';

    try {
        const url = `satuan_kemasan.php?action=list_satuan&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&page=${curPageSatuan}&limit=${pageLimit}`;
        const res = await fetch(url);
        if (!res.ok) {
            const errTxt = await res.text();
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Error (${res.status}): ${escapeHtml(errTxt.slice(0, 150))}</td></tr>`;
            return;
        }
        const json = await res.json();
        satuanCache = json.data || [];

        document.getElementById('lblTotalSatuan').innerText = (json.total || 0).toLocaleString('id-ID');
        document.getElementById('lblCurPageSatuan').innerText = json.page || 1;
        document.getElementById('lblTotalPagesSatuan').innerText = json.total_pages || 1;
        document.getElementById('btnPrevSatuan').disabled = (curPageSatuan <= 1);
        document.getElementById('btnNextSatuan').disabled = (curPageSatuan >= json.total_pages);

        if (satuanCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fa-regular fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block;"></i>Belum ada satuan yang dipilih. Klik "Pilih dari Referensi CEISA 4.0" atau "Ambil dari Riwayat Transaksi".</td></tr>';
            return;
        }

        let html = '';
        satuanCache.forEach((r, idx) => {
            const no = ((curPageSatuan - 1) * pageLimit) + idx + 1;
            const isAktif = (r.status === 1);
            const statusBadge = isAktif 
                ? '<span class="badge-aktif"><i class="fa-solid fa-check"></i> Aktif</span>' 
                : '<span class="badge-nonaktif"><i class="fa-solid fa-ban"></i> Non-Aktif</span>';
            const toggleIcon = isAktif ? 'fa-toggle-on' : 'fa-toggle-off';
            const toggleColor = isAktif ? 'color:#52c41a;' : 'color:#8c8c8c;';

            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${no}</td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;"><span class="badge-code">${escapeHtml(r.kode)}</span></td>
                <td><strong>${escapeHtml(r.uraian)}</strong></td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="toggleStatus('satuan', ${r.id})" title="Ubah Status Aktif/Nonaktif">
                        <i class="fa-solid ${toggleIcon}" style="${toggleColor} font-size:13px;"></i>
                    </button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="editManual('satuan', ${r.id}, '${escapeHtml(r.kode)}', '${escapeHtml(r.uraian)}')" title="Edit Uraian">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="deleteItem('satuan', ${r.id}, '${escapeHtml(r.kode)}')" title="Hapus dari Daftar">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;

        if (window.satuanGrid) {
            window.satuanGrid.refresh();
        } else if (typeof AntEnterpriseGrid !== 'undefined') {
            window.satuanGrid = new AntEnterpriseGrid('#tblSatuan', {
                gridId: 'erp_satuan',
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                skipCols: [0, 5],
                exportTitle: 'Daftar_Satuan_Barang',
                onEdit: (tr) => {
                    const btn = tr.querySelector('button[onclick*="editManual"]');
                    if (btn) btn.click();
                }
            });
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--danger);">${escapeHtml(e.message)}</td></tr>`;
    }
}

function changePageSatuan(delta) {
    curPageSatuan += delta;
    if (curPageSatuan < 1) curPageSatuan = 1;
    loadSatuan();
}

function resetFilterSatuan() {
    document.getElementById('filSearchSatuan').value = '';
    document.getElementById('filStatusSatuan').value = 'ALL';
    loadSatuan(1);
}

// 3. Load Kemasan
async function loadKemasan(page = null) {
    if (page !== null) curPageKemasan = page;
    const search = document.getElementById('filSearchKemasan').value.trim();
    const status = document.getElementById('filStatusKemasan').value;
    const tbody  = document.getElementById('tbodyKemasan');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data kemasan...</td></tr>';

    try {
        const url = `satuan_kemasan.php?action=list_kemasan&search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&page=${curPageKemasan}&limit=${pageLimit}`;
        const res = await fetch(url);
        if (!res.ok) {
            const errTxt = await res.text();
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Error (${res.status}): ${escapeHtml(errTxt.slice(0, 150))}</td></tr>`;
            return;
        }
        const json = await res.json();
        kemasanCache = json.data || [];

        document.getElementById('lblTotalKemasan').innerText = (json.total || 0).toLocaleString('id-ID');
        document.getElementById('lblCurPageKemasan').innerText = json.page || 1;
        document.getElementById('lblTotalPagesKemasan').innerText = json.total_pages || 1;
        document.getElementById('btnPrevKemasan').disabled = (curPageKemasan <= 1);
        document.getElementById('btnNextKemasan').disabled = (curPageKemasan >= json.total_pages);

        if (kemasanCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fa-regular fa-folder-open" style="font-size:24px; margin-bottom:8px; display:block;"></i>Belum ada jenis kemasan yang dipilih. Klik "Pilih dari Referensi CEISA 4.0" atau "Ambil dari Riwayat Transaksi".</td></tr>';
            return;
        }

        let html = '';
        kemasanCache.forEach((r, idx) => {
            const no = ((curPageKemasan - 1) * pageLimit) + idx + 1;
            const isAktif = (r.status === 1);
            const statusBadge = isAktif 
                ? '<span class="badge-aktif"><i class="fa-solid fa-check"></i> Aktif</span>' 
                : '<span class="badge-nonaktif"><i class="fa-solid fa-ban"></i> Non-Aktif</span>';
            const toggleIcon = isAktif ? 'fa-toggle-on' : 'fa-toggle-off';
            const toggleColor = isAktif ? 'color:#52c41a;' : 'color:#8c8c8c;';

            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${no}</td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;"><span class="badge-code">${escapeHtml(r.kode)}</span></td>
                <td><strong>${escapeHtml(r.uraian)}</strong></td>
                <td style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.set_by || '-')}</td>
                <td style="text-align:center;">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="toggleStatus('kemasan', ${r.id})" title="Ubah Status Aktif/Nonaktif">
                        <i class="fa-solid ${toggleIcon}" style="${toggleColor} font-size:13px;"></i>
                    </button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="editManual('kemasan', ${r.id}, '${escapeHtml(r.kode)}', '${escapeHtml(r.uraian)}')" title="Edit Uraian">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                    <button type="button" class="ant-btn ant-btn-danger ant-btn-sm" onclick="deleteItem('kemasan', ${r.id}, '${escapeHtml(r.kode)}')" title="Hapus dari Daftar">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;

        if (window.kemasanGrid) {
            window.kemasanGrid.refresh();
        } else if (typeof AntEnterpriseGrid !== 'undefined') {
            window.kemasanGrid = new AntEnterpriseGrid('#tblKemasan', {
                gridId: 'erp_kemasan',
                enableSort: true,
                enableResize: true,
                enableContextMenu: true,
                enablePagination: false,
                skipCols: [0, 5],
                exportTitle: 'Daftar_Jenis_Kemasan',
                onEdit: (tr) => {
                    const btn = tr.querySelector('button[onclick*="editManual"]');
                    if (btn) btn.click();
                }
            });
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--danger);">${escapeHtml(e.message)}</td></tr>`;
    }
}

function changePageKemasan(delta) {
    curPageKemasan += delta;
    if (curPageKemasan < 1) curPageKemasan = 1;
    loadKemasan();
}

function resetFilterKemasan() {
    document.getElementById('filSearchKemasan').value = '';
    document.getElementById('filStatusKemasan').value = 'ALL';
    loadKemasan(1);
}

// 4. Load Tab 3: Katalog Pabean
async function loadKatalog() {
    const type = document.getElementById('filKatalogType').value;
    const search = document.getElementById('filKatalogSearch').value.trim();
    const tbody = document.getElementById('tbodyKatalog');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat katalog referensi...</td></tr>';

    try {
        const res = await fetch(`satuan_kemasan.php?action=get_reff_catalog&type=${type}&search=${encodeURIComponent(search)}&limit=150`);
        if (!res.ok) return;
        const json = await res.json();
        const items = json.data || [];

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ditemukan referensi yang cocok.</td></tr>';
            return;
        }

        let html = '';
        items.forEach((r, idx) => {
            const isUsed = (r.is_used === 1);
            const statusLabel = isUsed ? '<span class="badge-aktif">Dipakai</span>' : '<span class="badge-nonaktif">Belum Aktif</span>';
            const actionBtn = isUsed 
                ? '<button type="button" class="ant-btn ant-btn-sm" disabled style="opacity:0.6;"><i class="fa-solid fa-check"></i> Sudah Aktif</button>'
                : `<button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="quickActivate('${type}', '${escapeHtml(r.kode)}')"><i class="fa-solid fa-plus"></i> Aktifkan</button>`;

            html += `
            <tr>
                <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                <td style="text-align:center;"><span class="badge-code">${escapeHtml(r.kode)}</span></td>
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

// 5. Quick Activate dari Katalog
async function quickActivate(type, kode) {
    try {
        const fd = new FormData();
        fd.append('type', type);
        fd.append('codes[]', kode);
        const res = await fetch('satuan_kemasan.php?action=activate_from_reff', {
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

// 6. Modal Picker Multi-Select
async function openPickerModal() {
    const type = (currentTab === 'kemasan') ? 'kemasan' : 'satuan';
    document.getElementById('pickerTitle').innerHTML = (type === 'kemasan') 
        ? '<i class="fa-solid fa-box-open" style="color:var(--primary);"></i> Pilih Jenis Kemasan dari Referensi CEISA 4.0'
        : '<i class="fa-solid fa-scale-unbalanced" style="color:var(--primary);"></i> Pilih Satuan Barang dari Referensi CEISA 4.0';

    document.getElementById('pickerSearch').value = '';
    document.getElementById('lblPickerSelectedCount').innerText = '0 dipilih';
    document.getElementById('modalPicker').classList.add('active');

    const tbody = document.getElementById('tbodyPickerList');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat daftar referensi...</td></tr>';

    try {
        const res = await fetch(`satuan_kemasan.php?action=get_reff_catalog&type=${type}&limit=400`);
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
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada referensi yang cocok.</td></tr>';
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
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Pilih setidaknya satu kode yang belum aktif.' });
        return;
    }

    const type = (currentTab === 'kemasan') ? 'kemasan' : 'satuan';
    const fd = new FormData();
    fd.append('type', type);
    codes.forEach(c => fd.append('codes[]', c));

    try {
        const res = await fetch('satuan_kemasan.php?action=activate_from_reff', {
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
    const type = (currentTab === 'kemasan') ? 'kemasan' : 'satuan';
    document.getElementById('manualFormTitle').innerHTML = (type === 'kemasan') 
        ? '<i class="fa-solid fa-pen-to-square"></i> Tambah Kemasan Manual'
        : '<i class="fa-solid fa-pen-to-square"></i> Tambah Satuan Manual';
    document.getElementById('lblFormKode').innerText = (type === 'kemasan') ? 'Kode Kemasan (Maks 5 Karakter):' : 'Kode Satuan (Maks 5 Karakter):';
    document.getElementById('formId').value = '';
    document.getElementById('formKode').value = '';
    document.getElementById('formKode').readOnly = false;
    document.getElementById('formUraian').value = '';
    document.getElementById('modalManualForm').classList.add('active');
}

function editManual(type, id, kode, uraian) {
    document.getElementById('manualFormTitle').innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit ${type === 'kemasan' ? 'Kemasan' : 'Satuan'} (${escapeHtml(kode)})`;
    document.getElementById('formId').value = id;
    document.getElementById('formKode').value = kode;
    document.getElementById('formKode').readOnly = true;
    document.getElementById('formUraian').value = uraian;
    document.getElementById('modalManualForm').classList.add('active');
}

function closeManualFormModal() {
    document.getElementById('modalManualForm').classList.remove('active');
}

async function submitManualForm() {
    const type = (currentTab === 'kemasan') ? 'kemasan' : 'satuan';
    const id = document.getElementById('formId').value;
    const kode = document.getElementById('formKode').value.trim();
    const uraian = document.getElementById('formUraian').value.trim();

    if (!kode) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Kode wajib diisi!' });
        return;
    }

    const fd = new FormData();
    fd.append('type', type);
    fd.append('id', id);
    fd.append('kode', kode);
    fd.append('uraian', uraian);

    try {
        const res = await fetch('satuan_kemasan.php?action=save_manual', {
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

// 8. Toggle Status
async function toggleStatus(type, id) {
    try {
        const fd = new FormData();
        fd.append('type', type);
        fd.append('id', id);
        const res = await fetch('satuan_kemasan.php?action=toggle_status', {
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

// 9. Hapus Item Terpilih
async function deleteItem(type, id, kode) {
    const ask = await Swal.fire({
        title: 'Konfirmasi Hapus',
        text: `Hapus kode ${kode} dari daftar operasional perusahaan? Kode ini tidak akan muncul lagi di dropdown pilihan.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Ya, Hapus',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ff4d4f'
    });

    if (!ask.isConfirmed) return;

    try {
        const fd = new FormData();
        fd.append('type', type);
        fd.append('id', id);
        const res = await fetch('satuan_kemasan.php?action=delete', {
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

// 10. Ambil dari Riwayat Transaksi (Fitur PowerBuilder Asli)
async function syncFromHistory() {
    const ask = await Swal.fire({
        title: 'Ambil dari Riwayat Transaksi',
        text: 'Sistem akan memindai seluruh data Master Barang & Transaksi Material, kemudian otomatis mengaktifkan seluruh satuan dan kemasan yang pernah digunakan.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Mulai Penyelarasan',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#52c41a'
    });

    if (!ask.isConfirmed) return;

    Swal.fire({
        title: 'Memindai Riwayat Transaksi...',
        text: 'Menyelaraskan master satuan dan kemasan...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const res = await fetch('satuan_kemasan.php?action=sync_from_history', { method: 'POST' });
        const json = await res.json();
        if (json.status === 'OK') {
            Swal.fire({
                icon: 'success',
                title: 'Sinkronisasi Selesai',
                text: json.message,
                timer: 2000,
                showConfirmButton: true
            });
            reloadCurrentTab();
            loadKpi();
        } else {
            Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: e.message });
    }
}

// 11. Export Excel & CSV
function exportCsv() {
    const type = (currentTab === 'kemasan') ? 'kemasan' : 'satuan';
    window.location.href = `satuan_kemasan.php?action=export_csv&type=${type}`;
}

function exportExcel() {
    const type = (currentTab === 'kemasan') ? 'kemasan' : 'satuan';
    const cache = (type === 'kemasan') ? kemasanCache : satuanCache;

    if (!cache || cache.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Peringatan', text: 'Tidak ada data untuk diexport!' });
        return;
    }

    const exportRows = cache.map((r, i) => ({
        'No': i + 1,
        'Kode': r.kode,
        'Nama / Uraian Lengkap': r.uraian,
        'Status': (r.status === 1 ? 'Aktif' : 'Non-Aktif'),
        'Pencatat (Set By)': r.set_by,
        'Terakhir Diperbarui': r.updated_at
    }));

    const ws = XLSX.utils.json_to_sheet(exportRows);
    const wb = XLSX.utils.book_new();
    const sheetName = (type === 'kemasan') ? "Daftar Kemasan" : "Daftar Satuan";
    XLSX.utils.book_append_sheet(wb, ws, sheetName);
    XLSX.writeFile(wb, `${sheetName}_Terpilih_${new Date().toISOString().slice(0,10)}.xlsx`);
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
    loadSatuan();
});
</script>
</body>
</html>

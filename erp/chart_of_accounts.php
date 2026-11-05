<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Chart of Accounts (COA) / Daftar Akun
 * Porting & Modernisasi Resmi dari PowerBuilder TPB-ERP:
 * - w_act_akun.srw (Window Master Akun & Saldo Awal)
 * - d_act_akun2.srd / d_act_akunsaldo.srd
 *
 * Mengadopsi 100% Ant Design Compact Desktop 9.5px Identik Sales Invoice (SI) / PO
 * Fitur Utama:
 * 1. Visualisasi Hierarki Akun Berjenjang (Level 1 Header s/d Akun Detail)
 * 2. Pos Saldo Normal (Debet / Kredit) & Pos Laporan (Neraca / Laba Rugi)
 * 3. Tab 1: Bagan Akun & Mutasi Saldo
 * 4. Tab 2: Entri Saldo Awal Buku Besar per Periode (bc_act_akunsaldo) dengan Balance Indicator
 * 5. Import Standar COA Manufaktur Kawasan Berikat SAK Indonesia (1-Klik)
 * 6. Enterprise DataGrid: Sorting, Column Resizing (.col-resizer), Context Menu Klik Kanan
 */
session_start();
require_once __DIR__ . '/../datalogin.php';

if (!$koneksi) {
    die("Koneksi database terputus. Silakan muat ulang halaman.");
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($cmp_id ?? ''));
if (empty($sessionCmpId)) {
    $sessionCmpId = 'SNU';
}
$escCmp  = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$userId  = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'ADMIN');

// -------------------------------------------------------------------------
// AUTO-HEAL: Pastikan Struktur Tabel bc_act_akun & bc_act_akunsaldo Sesuai
// -------------------------------------------------------------------------
function ensureCoaTables($koneksi, $cmpId) {
    // 1. Tabel bc_act_akun
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_akun` (
        `kode` varchar(20) NOT NULL,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        `nama_akun` varchar(100) NOT NULL,
        `tipe` varchar(50) DEFAULT 'Lainnya',
        `kurs` varchar(5) DEFAULT 'IDR',
        `pos_saldo` char(1) DEFAULT 'D',
        `header` char(1) DEFAULT 'N',
        `pos_laporan` varchar(5) DEFAULT 'NR',
        `kategori` varchar(50) DEFAULT 'AKTIVA',
        `level` int(11) DEFAULT '1',
        `parent_kode` varchar(20) DEFAULT '',
        `saldo_awal` decimal(18,2) DEFAULT '0.00',
        `saldo_berjalan` decimal(18,2) DEFAULT '0.00',
        `set_by` varchar(50) DEFAULT '',
        `idx` int(11) DEFAULT '0',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`kode`, `cmp_id`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_pos` (`pos_laporan`, `pos_saldo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pastikan kolom-kolom baru tersedia jika tabel sudah ada dari schema lama
    $cols = [];
    $res = $koneksi->query("SHOW COLUMNS FROM bc_act_akun");
    if ($res) while ($r = $res->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
    
    if (!isset($cols['cmp_id'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `cmp_id` varchar(30) NOT NULL DEFAULT '' AFTER `kode`");
        @$koneksi->query("ALTER TABLE bc_act_akun ADD INDEX `idx_cmp_id` (`cmp_id`)");
    }
    if (!isset($cols['tipe'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `tipe` varchar(50) DEFAULT 'Lainnya' AFTER `nama_akun`");
    }
    if (!isset($cols['kurs'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `kurs` varchar(5) DEFAULT 'IDR' AFTER `tipe`");
    }
    if (!isset($cols['header'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `header` char(1) DEFAULT 'N' AFTER `pos_saldo`");
    }
    if (!isset($cols['kategori'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `kategori` varchar(50) DEFAULT 'AKTIVA' AFTER `pos_laporan`");
    }
    if (!isset($cols['level'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `level` int(11) DEFAULT '1' AFTER `kategori`");
    }
    if (!isset($cols['parent_kode'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `parent_kode` varchar(20) DEFAULT '' AFTER `level`");
    }
    if (!isset($cols['saldo_awal'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `saldo_awal` decimal(18,2) DEFAULT '0.00' AFTER `parent_kode`");
    }
    if (!isset($cols['saldo_berjalan'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `saldo_berjalan` decimal(18,2) DEFAULT '0.00' AFTER `saldo_awal`");
    }
    if (!isset($cols['idx'])) {
        @$koneksi->query("ALTER TABLE bc_act_akun ADD COLUMN `idx` int(11) DEFAULT '0' AFTER `set_by`");
    }

    // 2. Tabel bc_act_akunsaldo (Saldo Awal per Periode)
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_akunsaldo` (
        `periode` varchar(7) NOT NULL,
        `kode_akun` varchar(25) NOT NULL,
        `saldoawal_db` decimal(18,2) DEFAULT '0.00',
        `saldoawal_kr` decimal(18,2) DEFAULT '0.00',
        `set_by` varchar(50) DEFAULT '',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`periode`, `kode_akun`, `cmp_id`),
        KEY `idx_cmp_per` (`cmp_id`, `periode`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Auto-seed default standard accounts jika tabel masih kosong sama sekali
    $escC = $koneksi->real_escape_string($cmpId);
    $cnt = $koneksi->query("SELECT COUNT(*) as c FROM bc_act_akun WHERE (cmp_id = '{$escC}' OR cmp_id = '')");
    if ($cnt && (int)$cnt->fetch_assoc()['c'] === 0) {
        seedStandardCoa($koneksi, $cmpId);
    }
}

function seedStandardCoa($koneksi, $cmpId) {
    $escC = $koneksi->real_escape_string($cmpId);
    $defaultAccounts = [
        // [kode, nama, tipe, kurs, pos_saldo, header, pos_laporan, kategori, level, parent, saldo_awal]
        ['1000', 'AKTIVA LANCAR', 'Aktiva Lancar', 'IDR', 'D', 'Y', 'NR', 'AKTIVA', 1, '', 0],
        ['1100', 'KAS DAN SETARA KAS', 'Kas & Bank', 'IDR', 'D', 'Y', 'NR', 'AKTIVA', 2, '1000', 0],
        ['1101', 'Kas Operasional Kantor (IDR)', 'Kas & Bank', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1100', 0],
        ['1102', 'Kas Kecil / Petty Cash (IDR)', 'Kas & Bank', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1100', 0],
        ['1110', 'Bank BCA - Rekening Utama IDR', 'Kas & Bank', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1100', 0],
        ['1111', 'Bank Mandiri - Rekening IDR', 'Kas & Bank', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1100', 0],
        ['1120', 'Bank BCA - Rekening Valas (USD)', 'Kas & Bank', 'USD', 'D', 'N', 'NR', 'AKTIVA', 3, '1100', 0],
        ['1200', 'PIUTANG USAHA', 'Piutang', 'IDR', 'D', 'Y', 'NR', 'AKTIVA', 2, '1000', 0],
        ['1201', 'Piutang Dagang Buyer Lokal', 'Piutang', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1200', 0],
        ['1202', 'Piutang Dagang Ekspor (Valas USD)', 'Piutang', 'USD', 'D', 'N', 'NR', 'AKTIVA', 3, '1200', 0],
        ['1209', 'Cadangan Kerugian Penurunan Nilai Piutang', 'Piutang', 'IDR', 'K', 'N', 'NR', 'AKTIVA', 3, '1200', 0],
        ['1300', 'PERSEDIAAN BARANG (IT INVENTORY)', 'Persediaan', 'IDR', 'D', 'Y', 'NR', 'AKTIVA', 2, '1000', 0],
        ['1301', 'Persediaan Bahan Baku (Raw Material)', 'Persediaan', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1300', 0],
        ['1302', 'Persediaan Barang Dalam Proses (WIP)', 'Persediaan', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1300', 0],
        ['1303', 'Persediaan Barang Jadi (Finished Goods)', 'Persediaan', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1300', 0],
        ['1304', 'Persediaan Bahan Penolong & Packaging', 'Persediaan', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1300', 0],
        ['1305', 'Persediaan Mesin & Sparepart (Capital Goods)', 'Persediaan', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1300', 0],
        ['1306', 'Persediaan Scrap & Sisa Potongan', 'Persediaan', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 3, '1300', 0],
        ['1400', 'Uang Muka Pembelian (DP Vendor)', 'Uang Muka', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 2, '1000', 0],
        ['1500', 'Pajak Dibayar Dimuka (PPN Masukan)', 'Pajak Dimuka', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 2, '1000', 0],
        ['1600', 'Beban Dibayar Dimuka / Biaya Asuransi', 'Beban Dimuka', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 2, '1000', 0],
        ['1800', 'AKTIVA TETAP (FIXED ASSETS)', 'Aktiva Tetap', 'IDR', 'D', 'Y', 'NR', 'AKTIVA', 1, '', 0],
        ['1801', 'Tanah & Bangunan Pabrik', 'Aktiva Tetap', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 2, '1800', 0],
        ['1802', 'Mesin & Peralatan Produksi KB', 'Aktiva Tetap', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 2, '1800', 0],
        ['1803', 'Kendaraan Operasional & Angkut', 'Aktiva Tetap', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 2, '1800', 0],
        ['1804', 'Inventaris & Peralatan Kantor / IT', 'Aktiva Tetap', 'IDR', 'D', 'N', 'NR', 'AKTIVA', 2, '1800', 0],
        ['1899', 'Akumulasi Penyusutan Aktiva Tetap', 'Akumulasi Penyusutan', 'IDR', 'K', 'N', 'NR', 'AKTIVA', 2, '1800', 0],
        ['2000', 'KEWAJIBAN LANCAR (HUTANG JANGKA PENDEK)', 'Kewajiban', 'IDR', 'K', 'Y', 'NR', 'KEWAJIBAN', 1, '', 0],
        ['2100', 'Hutang Dagang Vendor Lokal (AP)', 'Hutang Usaha', 'IDR', 'K', 'N', 'NR', 'KEWAJIBAN', 2, '2000', 0],
        ['2101', 'Hutang Dagang Impor Supplier Luar Negeri', 'Hutang Usaha', 'USD', 'K', 'N', 'NR', 'KEWAJIBAN', 2, '2000', 0],
        ['2200', 'Hutang Pajak PPN Keluaran', 'Hutang Pajak', 'IDR', 'K', 'N', 'NR', 'KEWAJIBAN', 2, '2000', 0],
        ['2201', 'Hutang PPh 21 / PPh 23 / PPh Final', 'Hutang Pajak', 'IDR', 'K', 'N', 'NR', 'KEWAJIBAN', 2, '2000', 0],
        ['2300', 'Biaya Yang Masih Harus Dibayar (Accrued)', 'Hutang Biaya', 'IDR', 'K', 'N', 'NR', 'KEWAJIBAN', 2, '2000', 0],
        ['2400', 'Uang Muka Penjualan dari Buyer (DP)', 'Uang Muka Penjualan', 'IDR', 'K', 'N', 'NR', 'KEWAJIBAN', 2, '2000', 0],
        ['2500', 'KEWAJIBAN JANGKA PANJANG', 'Kewajiban', 'IDR', 'K', 'Y', 'NR', 'KEWAJIBAN', 1, '', 0],
        ['2501', 'Hutang Bank Jangka Panjang', 'Hutang Bank', 'IDR', 'K', 'N', 'NR', 'KEWAJIBAN', 2, '2500', 0],
        ['3000', 'EKUITAS & MODAL', 'Ekuitas', 'IDR', 'K', 'Y', 'NR', 'EKUITAS', 1, '', 0],
        ['3001', 'Modal Disetor Pemilik Saham', 'Modal', 'IDR', 'K', 'N', 'NR', 'EKUITAS', 2, '3000', 0],
        ['3100', 'Laba Ditahan (Retained Earnings)', 'Laba Ditahan', 'IDR', 'K', 'N', 'NR', 'EKUITAS', 2, '3000', 0],
        ['3200', 'Laba / Rugi Tahun Berjalan', 'Laba Periode Berjalan', 'IDR', 'K', 'N', 'NR', 'EKUITAS', 2, '3000', 0],
        ['4000', 'PENDAPATAN USAHA (SALES REVENUE)', 'Pendapatan', 'IDR', 'K', 'Y', 'LR', 'PENDAPATAN', 1, '', 0],
        ['4100', 'Penjualan Barang Jadi Lokal', 'Pendapatan', 'IDR', 'K', 'N', 'LR', 'PENDAPATAN', 2, '4000', 0],
        ['4200', 'Penjualan Ekspor Barang Jadi (Valas)', 'Pendapatan', 'USD', 'K', 'N', 'LR', 'PENDAPATAN', 2, '4000', 0],
        ['4300', 'Penjualan Sisa Bahan Baku / Scrap', 'Pendapatan', 'IDR', 'K', 'N', 'LR', 'PENDAPATAN', 2, '4000', 0],
        ['4400', 'Retur & Potongan Penjualan', 'Retur Penjualan', 'IDR', 'D', 'N', 'LR', 'PENDAPATAN', 2, '4000', 0],
        ['5000', 'HARGA POKOK PENJUALAN (HPP / COGS)', 'Harga Pokok', 'IDR', 'D', 'Y', 'LR', 'HPP', 1, '', 0],
        ['5100', 'Biaya Bahan Baku Terpakai', 'Biaya Bahan', 'IDR', 'D', 'N', 'LR', 'HPP', 2, '5000', 0],
        ['5200', 'Biaya Tenaga Kerja Langsung (BTKL)', 'Biaya Tenaga Kerja', 'IDR', 'D', 'N', 'LR', 'HPP', 2, '5000', 0],
        ['5300', 'Biaya Overhead Pabrik (BOP Listrik, Air, Subkon)', 'BOP', 'IDR', 'D', 'N', 'LR', 'HPP', 2, '5000', 0],
        ['5400', 'Biaya Penyusutan Mesin Pabrik', 'BOP Penyusutan', 'IDR', 'D', 'N', 'LR', 'HPP', 2, '5000', 0],
        ['6000', 'BEBAN OPERASIONAL & UMUM', 'Beban Operasional', 'IDR', 'D', 'Y', 'LR', 'BIAYA', 1, '', 0],
        ['6100', 'Gaji & Kesejahteraan Karyawan Kantor', 'Beban Gaji', 'IDR', 'D', 'N', 'LR', 'BIAYA', 2, '6000', 0],
        ['6200', 'Beban Sewa, Listrik & Utilitas Kantor', 'Beban Utilitas', 'IDR', 'D', 'N', 'LR', 'BIAYA', 2, '6000', 0],
        ['6300', 'Beban Pemasaran, Ekspedisi & Promosi', 'Beban Pemasaran', 'IDR', 'D', 'N', 'LR', 'BIAYA', 2, '6000', 0],
        ['6400', 'Beban Penyusutan Peralatan Kantor & Kendaraan', 'Beban Penyusutan', 'IDR', 'D', 'N', 'LR', 'BIAYA', 2, '6000', 0],
        ['7000', 'PENDAPATAN & (BEBAN) LAIN-LAIN', 'Lain-lain', 'IDR', 'K', 'Y', 'LR', 'LAINNYA', 1, '', 0],
        ['7100', 'Pendapatan Bunga Bank & Jasa Giro', 'Pendapatan Lain', 'IDR', 'K', 'N', 'LR', 'LAINNYA', 2, '7000', 0],
        ['7200', 'Keuntungan / (Kerugian) Selisih Kurs Valas', 'Laba Rugi Kurs', 'IDR', 'K', 'N', 'LR', 'LAINNYA', 2, '7000', 0],
        ['7300', 'Biaya Administrasi Bank & Pajak Bunga', 'Biaya Bank', 'IDR', 'D', 'N', 'LR', 'LAINNYA', 2, '7000', 0]
    ];

    $stmt = $koneksi->prepare("INSERT IGNORE INTO bc_act_akun 
        (kode, cmp_id, nama_akun, tipe, kurs, pos_saldo, header, pos_laporan, kategori, level, parent_kode, saldo_awal, set_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SYSTEM')");
    if ($stmt) {
        foreach ($defaultAccounts as $a) {
            $stmt->bind_param("sssssssssisd", $a[0], $cmpId, $a[1], $a[2], $a[3], $a[4], $a[5], $a[6], $a[7], $a[8], $a[9], $a[10]);
            $stmt->execute();
        }
        $stmt->close();
    }
}
ensureCoaTables($koneksi, $sessionCmpId);

// -------------------------------------------------------------------------
// BACKEND AJAX API CONTROLLER
// -------------------------------------------------------------------------
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. Ambil Semua Daftar Akun (Hierarki COA)
if ($action === 'get_accounts') {
    header('Content-Type: application/json; charset=utf-8');
    $kategori = trim($_GET['kategori'] ?? '');
    $posLap   = trim($_GET['pos_laporan'] ?? '');
    $posSal   = trim($_GET['pos_saldo'] ?? '');
    $search   = trim($_GET['search'] ?? '');
    $onlyHdr  = trim($_GET['only_header'] ?? '');

    $where = ["(a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)"];
    if ($kategori !== '' && $kategori !== 'ALL') {
        $where[] = "a.kategori = '{$koneksi->real_escape_string($kategori)}'";
    }
    if ($posLap !== '' && $posLap !== 'ALL') {
        $where[] = "a.pos_laporan = '{$koneksi->real_escape_string($posLap)}'";
    }
    if ($posSal !== '' && $posSal !== 'ALL') {
        $where[] = "a.pos_saldo = '{$koneksi->real_escape_string($posSal)}'";
    }
    if ($onlyHdr === '1') {
        $where[] = "a.header = 'Y'";
    }
    if ($search !== '') {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(a.kode LIKE '%{$escS}%' OR a.nama_akun LIKE '%{$escS}%' OR a.tipe LIKE '%{$escS}%')";
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT a.* FROM bc_act_akun a WHERE {$whereSql} ORDER BY a.kode ASC";
    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['saldo_awal']     = (float)($r['saldo_awal'] ?? 0);
            $r['saldo_berjalan'] = (float)($r['saldo_berjalan'] ?? 0);
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data, 'total' => count($data)]);
    exit;
}

// 2. Simpan / Update Akun
if ($action === 'save_account') {
    header('Content-Type: application/json; charset=utf-8');
    $isEdit      = !empty($_POST['is_edit']);
    $kode        = trim($_POST['kode'] ?? '');
    $namaAkun    = trim($_POST['nama_akun'] ?? '');
    $tipe        = trim($_POST['tipe'] ?? 'Lainnya');
    $kurs        = trim($_POST['kurs'] ?? 'IDR');
    $posSaldo    = trim($_POST['pos_saldo'] ?? 'D');
    $header      = trim($_POST['header'] ?? 'N');
    $posLaporan  = trim($_POST['pos_laporan'] ?? 'NR');
    $kategori    = trim($_POST['kategori'] ?? 'AKTIVA');
    $level       = max(1, (int)($_POST['level'] ?? 1));
    $parentKode  = trim($_POST['parent_kode'] ?? '');
    $saldoAwal   = (float)($_POST['saldo_awal'] ?? 0);
    $setBy       = $userId;

    if ($kode === '' || $namaAkun === '') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Akun dan Nama Akun wajib diisi.']);
        exit;
    }

    if ($isEdit) {
        $stmt = $koneksi->prepare("UPDATE bc_act_akun SET 
            nama_akun = ?, tipe = ?, kurs = ?, pos_saldo = ?, header = ?, pos_laporan = ?, 
            kategori = ?, level = ?, parent_kode = ?, saldo_awal = ?, set_by = ? 
            WHERE kode = ? AND (cmp_id = ? OR cmp_id = '')");
        if ($stmt) {
            $stmt->bind_param("sssssssisdsss", $namaAkun, $tipe, $kurs, $posSaldo, $header, $posLaporan, $kategori, $level, $parentKode, $saldoAwal, $setBy, $kode, $sessionCmpId);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'OK', 'message' => "Akun '{$kode} - {$namaAkun}' berhasil diperbarui!"]);
            } else {
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal update akun: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Prepare failed: ' . $koneksi->error]);
        }
    } else {
        $escK = $koneksi->real_escape_string($kode);
        $chk = $koneksi->query("SELECT kode FROM bc_act_akun WHERE kode = '{$escK}' AND (cmp_id = '{$escCmp}' OR cmp_id = '') LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Kode akun '{$kode}' sudah terdaftar pada sistem!"]);
            exit;
        }

        $stmt = $koneksi->prepare("INSERT INTO bc_act_akun 
            (kode, cmp_id, nama_akun, tipe, kurs, pos_saldo, header, pos_laporan, kategori, level, parent_kode, saldo_awal, set_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssssssisds", $kode, $sessionCmpId, $namaAkun, $tipe, $kurs, $posSaldo, $header, $posLaporan, $kategori, $level, $parentKode, $saldoAwal, $setBy);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'OK', 'message' => "Akun baru '{$kode} - {$namaAkun}' berhasil disimpan!"]);
            } else {
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menambah akun: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Prepare failed: ' . $koneksi->error]);
        }
    }
    exit;
}

// 3. Hapus Akun
if ($action === 'delete_account') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim($_POST['kode'] ?? '');
    if ($kode === '') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode akun tidak valid.']);
        exit;
    }
    $escK = $koneksi->real_escape_string($kode);

    // Cek apakah akun ini memiliki sub-akun (child)
    $chkChild = $koneksi->query("SELECT kode FROM bc_act_akun WHERE parent_kode = '{$escK}' AND (cmp_id = '{$escCmp}' OR cmp_id = '') LIMIT 1");
    if ($chkChild && $chkChild->num_rows > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Tidak dapat menghapus akun '{$kode}' karena memiliki sub-akun bawahan. Hapus sub-akun terlebih dahulu."]);
        exit;
    }

    $del = $koneksi->query("DELETE FROM bc_act_akun WHERE kode = '{$escK}' AND (cmp_id = '{$escCmp}' OR cmp_id = '')");
    if ($del) {
        echo json_encode(['status' => 'OK', 'message' => "Akun '{$kode}' berhasil dihapus!"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menghapus akun: ' . $koneksi->error]);
    }
    exit;
}

// 4. Reset & Terapkan Standar COA Manufaktur SAK
if ($action === 'seed_default_coa') {
    header('Content-Type: application/json; charset=utf-8');
    seedStandardCoa($koneksi, $sessionCmpId);
    echo json_encode(['status' => 'OK', 'message' => 'Bagan Akun Standar SAK Manufaktur Kawasan Berikat berhasil disinkronkan!']);
    exit;
}

// 5. Ambil Saldo Awal per Periode (bc_act_akunsaldo)
if ($action === 'get_period_saldo') {
    header('Content-Type: application/json; charset=utf-8');
    $periode = trim($_GET['periode'] ?? date('Y/01'));
    $escP    = $koneksi->real_escape_string($periode);

    // Join bc_act_akun dengan bc_act_akunsaldo
    $sql = "SELECT a.kode, a.nama_akun, a.pos_saldo, a.pos_laporan, a.kategori, a.header,
                   COALESCE(s.saldoawal_db, 0.00) as saldoawal_db,
                   COALESCE(s.saldoawal_kr, 0.00) as saldoawal_kr
            FROM bc_act_akun a
            LEFT JOIN bc_act_akunsaldo s ON (a.kode = s.kode_akun AND s.periode = '{$escP}' AND (s.cmp_id = '{$escCmp}' OR s.cmp_id = ''))
            WHERE (a.cmp_id = '{$escCmp}' OR a.cmp_id = '' OR a.cmp_id IS NULL)
            ORDER BY a.kode ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    $totDb = 0;
    $totKr = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['saldoawal_db'] = (float)$r['saldoawal_db'];
            $r['saldoawal_kr'] = (float)$r['saldoawal_kr'];
            $totDb += $r['saldoawal_db'];
            $totKr += $r['saldoawal_kr'];
            $rows[] = $r;
        }
    }
    $diff = round($totDb - $totKr, 2);
    echo json_encode([
        'status' => 'OK', 
        'data' => $rows, 
        'total_db' => $totDb, 
        'total_kr' => $totKr, 
        'diff' => $diff, 
        'is_balanced' => ($diff == 0.0)
    ]);
    exit;
}

// 6. Simpan Saldo Awal per Periode (Bulk Save)
if ($action === 'save_period_saldo') {
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) $rawInput = file_get_contents('php://stdin');
    $input = json_decode($rawInput, true);

    $periode = trim($input['periode'] ?? date('Y/01'));
    $items   = $input['items'] ?? [];

    if (empty($periode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Periode akuntansi tidak valid.']);
        exit;
    }

    $escP = $koneksi->real_escape_string($periode);

    $stmt = $koneksi->prepare("INSERT INTO bc_act_akunsaldo 
        (periode, kode_akun, saldoawal_db, saldoawal_kr, set_by, cmp_id) 
        VALUES (?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        saldoawal_db = VALUES(saldoawal_db), 
        saldoawal_kr = VALUES(saldoawal_kr), 
        set_by = VALUES(set_by)");
    
    if (!$stmt) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Prepare statement error: ' . $koneksi->error]);
        exit;
    }

    $saved = 0;
    foreach ($items as $it) {
        $kAcc = trim($it['kode_akun'] ?? '');
        $sDb  = (float)($it['saldoawal_db'] ?? 0);
        $sKr  = (float)($it['saldoawal_kr'] ?? 0);
        if ($kAcc !== '') {
            $stmt->bind_param("ssddss", $periode, $kAcc, $sDb, $sKr, $userId, $sessionCmpId);
            $stmt->execute();
            $saved++;
        }
    }
    $stmt->close();

    echo json_encode(['status' => 'OK', 'message' => "Berhasil menyimpan saldo awal untuk {$saved} akun pada periode {$periode}!"]);
    exit;
}

// Profil Perusahaan Aktif
$cmpName = $_SESSION['company_name'] ?? 'PT. SN UNGGUL';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chart of Accounts (COA) / Daftar Akun - <?= htmlspecialchars($cmpName) ?></title>

    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome 6 Pro / Solid -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>

    <!-- SheetJS Excel Export -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        :root {
            --primary: #4338ca;
            --primary-hover: #3730a3;
            --primary-light: #e0e7ff;
            --primary-focus: rgba(67, 56, 202, 0.25);
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --info: #0284c7;
            --bg-app: #f4f6f9;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border-color: #d1d5db;
            --border-input: #cbd5e1;
            --border-light: #e5e7eb;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --table-head-bg: #f8fafc;
            --table-hover: #f1f5f9;
            --table-selected: #e0e7ff;
            --form-font-size: 9.5px;
        }

        body.dark-theme, [data-theme='dark'] {
            --primary: #6366f1;
            --primary-hover: #818cf8;
            --primary-light: rgba(99, 102, 241, 0.15);
            --bg-app: #0f172a;
            --bg-card: #1e293b;
            --bg-input: #0f172a;
            --border-color: #334155;
            --border-input: #475569;
            --border-light: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --table-head-bg: #1e293b;
            --table-hover: #27354f;
            --table-selected: #2e3a59;
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: var(--form-font-size, 9.5px);
            margin: 0;
            padding: 0;
            background: var(--bg-app);
            color: var(--text-main);
            height: 100vh;
            overflow: hidden;
        }

        .ant-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 4px 6px;
        }

        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .ant-card-head {
            padding: 4px 8px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--table-head-bg);
            flex-shrink: 0;
            min-height: 32px;
        }

        .ant-card-head-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-main);
        }

        .ant-badge {
            font-size: 8.5px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 3px;
            text-transform: uppercase;
        }
        .ant-badge-company { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        .ant-toolbar {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .ant-btn {
            height: 21px;
            padding: 0 8px;
            font-size: 9.5px;
            font-weight: 500;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-card);
            color: var(--text-main);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .ant-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }
        .ant-btn-primary:hover {
            background: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }
        .ant-btn-sm {
            padding: 0 6px;
            height: 21px;
        }

        /* 2 TABS BAR */
        .ant-tabs-bar {
            display: flex;
            align-items: center;
            background: var(--bg-app);
            border-bottom: 1px solid var(--border-color);
            padding: 0 4px;
            gap: 2px;
            flex-shrink: 0;
        }
        .ant-tab-btn {
            height: 24px;
            padding: 0 12px;
            font-size: 9.5px;
            font-weight: 500;
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-bottom: 2px solid transparent;
            transition: all 0.15s;
        }
        .ant-tab-btn:hover {
            color: var(--primary);
        }
        .ant-tab-btn.active {
            color: var(--primary);
            font-weight: 600;
            border-bottom-color: var(--primary);
            background: var(--bg-card);
        }

        .tab-content {
            display: none;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .tab-content.active {
            display: flex;
        }

        /* 4 KPI STRIP CARDS */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            padding: 6px;
            background: var(--bg-app);
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 3px;
            padding: 5px 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .kpi-icon {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }
        .kpi-val {
            font-size: 11px;
            font-weight: 700;
            line-height: 1.2;
            color: var(--text-main);
        }
        .kpi-lbl {
            font-size: 8.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 500;
        }

        /* FILTER TOOLBAR */
        .filter-toolbar {
            padding: 4px 6px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            background: var(--bg-card);
            flex-shrink: 0;
        }
        .filter-toolbar label {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .filter-toolbar select, .filter-toolbar input {
            height: 21px;
            font-size: 9.5px;
            padding: 0 6px;
            border: 1px solid var(--border-input);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
        }
        .filter-toolbar select:focus, .filter-toolbar input:focus {
            border-color: var(--primary);
        }

        /* DATAGRID STYLING (table.dw) */
        .grid-wrap {
            flex: 1;
            min-height: 0;
            overflow: auto;
            position: relative;
            background: var(--bg-card);
        }
        table.dw {
            width: 100%;
            min-width: 1250px;
            border-collapse: collapse;
            font-size: 9.5px;
            table-layout: fixed;
            white-space: nowrap;
        }
        table.dw thead {
            position: sticky;
            top: 0;
            z-index: 5;
            background: var(--table-head-bg);
        }
        table.dw th {
            padding: 3px 6px;
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
            border: 1px solid var(--border-light);
            text-align: left;
            position: relative;
            user-select: none;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        table.dw th:hover { background: var(--table-hover); }
        table.dw td {
            padding: 2.5px 6px;
            font-size: 9.5px;
            border: 1px solid var(--border-light);
            vertical-align: middle;
            line-height: 1.35;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        table.dw tr:hover { background: var(--table-hover); }
        table.dw tr.row-selected {
            background: var(--table-selected) !important;
            outline: 1px dashed var(--primary);
        }
        table.dw tr.is-header-row {
            background: #fafafa;
            font-weight: 600;
        }
        body.dark-theme table.dw tr.is-header-row {
            background: #172033;
        }
        table.dw td.text-right, table.dw th.text-right { text-align: right; }
        table.dw td.text-center, table.dw th.text-center { text-align: center; }

        /* Tree Indent */
        .tree-indent-1 { padding-left: 6px !important; }
        .tree-indent-2 { padding-left: 20px !important; }
        .tree-indent-3 { padding-left: 36px !important; }
        .tree-indent-4 { padding-left: 52px !important; }
        .tree-indent-5 { padding-left: 68px !important; }

        .col-resizer {
            position: absolute;
            top: 0;
            right: 0;
            width: 7px;
            height: 100%;
            cursor: col-resize !important;
            user-select: none;
            z-index: 10;
            background: transparent;
            transition: background-color 0.15s;
        }
        .col-resizer:hover, .col-resizer.resizing {
            background-color: var(--primary) !important;
            box-shadow: 0 0 6px rgba(67, 56, 202, 0.6) !important;
        }
        body.grid-resizing {
            cursor: col-resize !important;
            user-select: none;
        }

        /* BADGES */
        .badge-pos {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 8.5px;
            font-weight: 700;
            text-align: center;
        }
        .badge-debet { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        .badge-kredit { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-nr { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-lr { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge-hdr { background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; font-size: 8px; }
        .badge-dtl { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 8px; }

        /* CONTEXT MENU */
        .context-menu {
            position: fixed;
            z-index: 999999;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.18);
            min-width: 220px;
            padding: 4px 0;
            display: none;
            font-size: 9.5px;
        }
        .ctx-item {
            padding: 4px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-main);
            cursor: pointer;
        }
        .ctx-item:hover {
            background: var(--table-hover);
            color: var(--primary);
        }
        .ctx-sep {
            height: 1px;
            background: var(--border-color);
            margin: 3px 0;
        }

        /* MODAL DIALOG */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }
        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            width: 520px;
            max-width: 95vw;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.15s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-head {
            padding: 6px 10px;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-body {
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 75vh;
            overflow-y: auto;
        }
        .modal-foot {
            padding: 6px 10px;
            background: var(--table-head-bg);
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .form-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-row label {
            width: 130px;
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .form-row .form-ctrl {
            flex: 1;
            height: 22px;
            font-size: 9.5px;
            padding: 0 6px;
            border: 1px solid var(--border-input);
            border-radius: 2px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
        }
        .form-row .form-ctrl:focus {
            border-color: var(--primary);
        }

        /* COMPACT SWEETALERT2 STYLING */
        .swal2-container { z-index: 9999999 !important; }
        .swal2-popup {
            font-family: 'Poppins', sans-serif !important;
            font-size: 10px !important;
            width: 330px !important;
            padding: 12px 14px !important;
            border-radius: 6px !important;
            background: var(--bg-card) !important;
            color: var(--text-main) !important;
            border: 1px solid var(--border-color) !important;
        }
        .swal2-title { font-size: 12.5px !important; font-weight: 600 !important; color: var(--text-main) !important; padding: 0 0 6px 0 !important; }
        .swal2-html-container { font-size: 9.5px !important; color: var(--text-muted) !important; margin: 6px 0 !important; }
        .swal2-icon { width: 38px !important; height: 38px !important; margin: 4px auto 8px !important; transform: scale(0.7) !important; }
        .swal2-actions { margin-top: 10px !important; gap: 6px !important; }
        .swal2-styled { font-size: 9.5px !important; height: 22px !important; padding: 0 12px !important; border-radius: 3px !important; }
        .swal2-styled.swal2-confirm { background-color: var(--primary) !important; }
    </style>
</head>
<body>

<div class="ant-container">
    <div class="ant-card">
        <!-- CARD HEADER & TOOLBAR -->
        <div class="ant-card-head">
            <div class="ant-card-head-title">
                <i class="fa-solid fa-book-journal-whills" style="color:var(--primary); font-size:13px;"></i>
                <span>Bagan Akun (Chart of Accounts / COA)</span>
                <span class="ant-badge ant-badge-company"><?= htmlspecialchars($cmpName) ?></span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAccountModal(false)"><i class="fa-solid fa-plus"></i> Tambah Akun Baru</button>
                <button type="button" class="ant-btn" onclick="seedDefaultCoa()"><i class="fa-solid fa-wand-magic-sparkles" style="color:#f59e0b;"></i> Standar SAK KB</button>
                <button type="button" class="ant-btn" onclick="loadAccounts()"><i class="fa-solid fa-arrows-rotate"></i> Refresh (F2)</button>
                <button type="button" class="ant-btn" onclick="exportCoaExcel()"><i class="fa-solid fa-file-excel" style="color:#10b981;"></i> Excel</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan COA &amp; SOP Akuntansi"><i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:12px;"></i></button>
            </div>
        </div>

        <!-- 2 TABS BAR -->
        <div class="ant-tabs-bar">
            <button type="button" class="ant-tab-btn active" data-tab="tabCoa" onclick="switchTab('tabCoa')">
                <i class="fa-solid fa-sitemap"></i> <span>Bagan Akun &amp; Hierarki (COA)</span>
            </button>
            <button type="button" class="ant-tab-btn" data-tab="tabSaldo" onclick="switchTab('tabSaldo')">
                <i class="fa-solid fa-scale-balanced"></i> <span>Entri Saldo Awal Buku Besar (bc_act_akunsaldo)</span>
            </button>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 1: BAGAN AKUN & HIERARKI                                      -->
        <!-- ================================================================= -->
        <div class="tab-content active" id="tabCoa">
            <!-- 4 KPI STRIP CARDS -->
            <div class="kpi-strip">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#e0e7ff; color:#4338ca;"><i class="fa-solid fa-list-ol"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiTotalAkun">0</div>
                        <div class="kpi-lbl">Total Akun Aktif</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-building-columns"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiAktiva">Rp 0</div>
                        <div class="kpi-lbl">Total Nilai Aset / Aktiva</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#dcfce7; color:#15803d;"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiPasiva">Rp 0</div>
                        <div class="kpi-lbl">Total Kewajiban &amp; Ekuitas</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fef3c7; color:#b45309;"><i class="fa-solid fa-chart-line"></i></div>
                    <div>
                        <div class="kpi-val" id="kpiLabaRugi">Rp 0</div>
                        <div class="kpi-lbl">Net Laba / Rugi Berjalan</div>
                    </div>
                </div>
            </div>

            <!-- FILTER TOOLBAR -->
            <div class="filter-toolbar">
                <label>Kategori:</label>
                <select id="filterKategori" onchange="loadAccounts()" style="width:130px;">
                    <option value="ALL">-- Semua Kategori --</option>
                    <option value="AKTIVA">AKTIVA (Aset Lancar &amp; Tetap)</option>
                    <option value="KEWAJIBAN">KEWAJIBAN (Hutang)</option>
                    <option value="EKUITAS">EKUITAS (Modal &amp; Laba)</option>
                    <option value="PENDAPATAN">PENDAPATAN (Penjualan)</option>
                    <option value="HPP">HARGA POKOK (HPP)</option>
                    <option value="BIAYA">BIAYA OPERASIONAL</option>
                    <option value="LAINNYA">LAIN-LAIN</option>
                </select>

                <label style="margin-left:4px;">Pos Laporan:</label>
                <select id="filterPosLap" onchange="loadAccounts()" style="width:110px;">
                    <option value="ALL">-- Semua Pos --</option>
                    <option value="NR">Neraca (NR)</option>
                    <option value="LR">Laba Rugi (LR)</option>
                </select>

                <label style="margin-left:4px;">Pos Saldo:</label>
                <select id="filterPosSal" onchange="loadAccounts()" style="width:100px;">
                    <option value="ALL">-- Semua D/K --</option>
                    <option value="D">Debet (D)</option>
                    <option value="K">Kredit (K)</option>
                </select>

                <input type="text" id="filterSearch" placeholder="Cari Kode, Nama Akun..." style="width:170px;" onkeydown="if(event.key==='Enter') loadAccounts()">
                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="loadAccounts()"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <button type="button" class="ant-btn ant-btn-sm" onclick="resetCoaFilter()"><i class="fa-solid fa-rotate-left"></i> Reset</button>

                <div style="flex:1;"></div>
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;">
                    <input type="checkbox" id="chkOnlyHeader" onchange="loadAccounts()">
                    <span style="font-size:9px; color:var(--text-muted);">Hanya Akun Induk (Header)</span>
                </label>
            </div>

            <!-- DATAGRID COA -->
            <div class="grid-wrap">
                <table class="dw" id="tblCoa">
                    <thead>
                        <tr>
                            <th data-col="no" style="width:30px; text-align:center;">#</th>
                            <th data-col="kode" style="width:105px;">Kode Akun</th>
                            <th data-col="nama_akun" style="width:300px;">Nama Akun / Bagan Perkiraan</th>
                            <th data-col="tipe" style="width:140px;">Klasifikasi / Tipe</th>
                            <th data-col="pos_lap" style="width:75px; text-align:center;">Pos Lap</th>
                            <th data-col="pos_sal" style="width:65px; text-align:center;">D / K</th>
                            <th data-col="kurs" style="width:55px; text-align:center;">Kurs</th>
                            <th data-col="header" style="width:65px; text-align:center;">Sifat</th>
                            <th data-col="lvl" style="width:45px; text-align:center;">Lvl</th>
                            <th data-col="saldo_awal" style="width:125px;" class="text-right">Saldo Awal (Rp)</th>
                            <th data-col="saldo_berjalan" style="width:125px;" class="text-right">Saldo Berjalan</th>
                            <th data-col="aksi" style="width:70px; text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tblCoaBody">
                        <tr><td colspan="12" class="text-center" style="padding:25px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat bagan akun...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 2: ENTRI SALDO AWAL BUKU BESAR (bc_act_akunsaldo)             -->
        <!-- ================================================================= -->
        <div class="tab-content" id="tabSaldo">
            <!-- TOOLBAR ENTRI SALDO AWAL -->
            <div class="filter-toolbar" style="background:var(--table-head-bg);">
                <label>Periode Akuntansi (YYYY/MM):</label>
                <input type="text" id="saldoPeriode" value="<?= date('Y') ?>/01" style="width:80px; text-align:center; font-weight:600;" placeholder="YYYY/MM">
                <button type="button" class="ant-btn ant-btn-sm" onclick="loadPeriodSaldo()"><i class="fa-solid fa-arrows-rotate"></i> Muat Saldo Periode</button>

                <div style="flex:1;"></div>

                <!-- BALANCE AUDIT STRIP -->
                <div style="display:flex; align-items:center; gap:8px; background:var(--bg-card); padding:2px 8px; border:1px solid var(--border-color); border-radius:3px;">
                    <span style="font-size:9px; color:var(--text-muted);">Total Debet: <strong id="lblTotDb" style="color:#0284c7;">Rp 0.00</strong></span>
                    <span style="color:var(--border-color);">|</span>
                    <span style="font-size:9px; color:var(--text-muted);">Total Kredit: <strong id="lblTotKr" style="color:#15803d;">Rp 0.00</strong></span>
                    <span style="color:var(--border-color);">|</span>
                    <span style="font-size:9px; font-weight:700;" id="lblBalanceStatus"><i class="fa-solid fa-check"></i> SEIMBANG (BALANCE)</span>
                </div>

                <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="savePeriodSaldo()"><i class="fa-solid fa-floppy-disk"></i> Simpan Saldo Awal</button>
            </div>

            <!-- GRID ENTRI SALDO AWAL -->
            <div class="grid-wrap">
                <table class="dw" id="tblSaldoAwal" style="min-width:980px;">
                    <thead>
                        <tr>
                            <th data-col="s_no" style="width:32px; text-align:center;">#</th>
                            <th data-col="s_kode" style="width:110px;">Kode Akun</th>
                            <th data-col="s_nama" style="width:320px;">Nama Akun / Bagan Perkiraan</th>
                            <th data-col="s_pos" style="width:70px; text-align:center;">Pos Saldo</th>
                            <th data-col="s_lap" style="width:75px; text-align:center;">Pos Lap</th>
                            <th data-col="s_db" style="width:170px;" class="text-right">Saldo Awal Debet (Rp)</th>
                            <th data-col="s_kr" style="width:170px;" class="text-right">Saldo Awal Kredit (Rp)</th>
                        </tr>
                    </thead>
                    <tbody id="tblSaldoAwalBody">
                        <tr><td colspan="7" class="text-center" style="padding:25px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat saldo awal periode...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL TAMBAH / EDIT AKUN                                                  -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalAccount" onclick="if(event.target===this) closeAccountModal()">
    <div class="modal-card">
        <div class="modal-head">
            <span style="font-weight:600; font-size:11px;" id="modalAccountTitle"><i class="fa-solid fa-pen-to-square" style="color:var(--primary);"></i> Form Data Bagan Akun (COA)</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeAccountModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="formAccount" onsubmit="saveAccountForm(event)">
            <input type="hidden" id="accIsEdit" value="0">
            <div class="modal-body">
                <div class="form-row">
                    <label>Kode Akun *</label>
                    <input type="text" id="accKode" class="form-ctrl" placeholder="Contoh: 1101 atau 1101.01" required>
                </div>
                <div class="form-row">
                    <label>Nama Akun *</label>
                    <input type="text" id="accNama" class="form-ctrl" placeholder="Contoh: Bank Mandiri Rekening IDR" required>
                </div>
                <div class="form-row">
                    <label>Kategori Akun *</label>
                    <select id="accKategori" class="form-ctrl" onchange="autoSuggestPos(this.value)">
                        <option value="AKTIVA">AKTIVA (Aset)</option>
                        <option value="KEWAJIBAN">KEWAJIBAN (Hutang)</option>
                        <option value="EKUITAS">EKUITAS (Modal)</option>
                        <option value="PENDAPATAN">PENDAPATAN (Penjualan)</option>
                        <option value="HPP">HARGA POKOK PENJUALAN (HPP)</option>
                        <option value="BIAYA">BIAYA OPERASIONAL &amp; UMUM</option>
                        <option value="LAINNYA">LAIN-LAIN</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Tipe / Klasifikasi</label>
                    <input type="text" id="accTipe" class="form-ctrl" placeholder="Contoh: Kas &amp; Bank, Piutang, Persediaan, Hutang Usaha, dll">
                </div>
                <div class="form-row">
                    <label>Pos Laporan *</label>
                    <select id="accPosLaporan" class="form-ctrl">
                        <option value="NR">Neraca (NR)</option>
                        <option value="LR">Laba Rugi (LR)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Pos Saldo Normal *</label>
                    <select id="accPosSaldo" class="form-ctrl">
                        <option value="D">Debet (D)</option>
                        <option value="K">Kredit (K)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Sifat Akun *</label>
                    <select id="accHeader" class="form-ctrl">
                        <option value="N">DETAIL (Akun Transaksi)</option>
                        <option value="Y">HEADER (Akun Induk / Judul Grup)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Akun Induk (Parent)</label>
                    <select id="accParentKode" class="form-ctrl">
                        <option value="">-- Tidak Ada (Tingkat Paling Atas) --</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Tingkat / Level</label>
                    <select id="accLevel" class="form-ctrl">
                        <option value="1">Level 1 (Grup Utama)</option>
                        <option value="2">Level 2 (Sub-Grup)</option>
                        <option value="3" selected>Level 3 (Akun Standar)</option>
                        <option value="4">Level 4 (Sub-Akun Rinci)</option>
                        <option value="5">Level 5 (Akun Spesifik)</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Mata Uang (Kurs)</label>
                    <select id="accKurs" class="form-ctrl">
                        <option value="IDR">IDR - Rupiah Indonesia</option>
                        <option value="USD">USD - Dolar Amerika</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="JPY">JPY - Yen Jepang</option>
                        <option value="SGD">SGD - Dolar Singapura</option>
                        <option value="CNY">CNY - Yuan China</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Saldo Awal Bawaan</label>
                    <input type="number" id="accSaldoAwal" class="form-ctrl text-right" step="0.01" value="0.00">
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="ant-btn" onclick="closeAccountModal()">Batal</button>
                <button type="submit" class="ant-btn ant-btn-primary"><i class="fa-solid fa-floppy-disk"></i> Simpan Akun</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- ENTERPRISE CONTEXT MENU (KLIK KANAN)                                      -->
<!-- ========================================================================= -->
<div id="gridContextMenu" class="context-menu" onclick="event.stopPropagation()">
    <div class="ctx-item" onclick="ctxAddChildAccount()"><i class="fa-solid fa-code-branch" style="color:var(--primary);"></i> <span>Tambah Sub-Akun Bawahannya</span></div>
    <div class="ctx-item" onclick="ctxEditAccount()"><i class="fa-solid fa-pen" style="color:#0284c7;"></i> <span>Edit Akun Ini</span></div>
    <div class="ctx-item" onclick="ctxDeleteAccount()"><i class="fa-solid fa-trash-can" style="color:var(--danger);"></i> <span>Hapus Akun</span></div>
    <div class="ctx-sep"></div>
    <div class="ctx-item" onclick="ctxCopyTsv()"><i class="fa-regular fa-copy" style="color:#64748b;"></i> <span>Salin Baris (TSV Excel)</span></div>
    <div class="ctx-item" onclick="exportCoaExcel()"><i class="fa-solid fa-file-excel" style="color:#10b981;"></i> <span>Ekspor Semua ke Excel (.xlsx)</span></div>
</div>

<!-- ========================================================================= -->
<!-- MODAL PANDUAN COA & SOP                                                   -->
<!-- ========================================================================= -->
<div class="modal-overlay" id="modalGuide" onclick="if(event.target===this) closeModuleGuide()">
    <div class="modal-card" style="width:600px;">
        <div class="modal-head">
            <span style="font-weight:600; font-size:11px;"><i class="fa-solid fa-book" style="color:var(--primary);"></i> Panduan Bagan Akun (COA) &amp; SOP Akuntansi</span>
            <button type="button" class="ant-btn ant-btn-sm" onclick="closeModuleGuide()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="font-size:9.5px; line-height:1.5;">
            <div style="font-weight:600; color:var(--primary); margin-bottom:4px;">1. Struktur Standar Akun Manufaktur Kawasan Berikat</div>
            <p style="margin:0 0 8px;">Bagan akun mengadopsi standar PSAK / SAK Indonesia dengan nomor akun berjenjang:
                <br>• <strong>1xxx</strong> : Aset / Aktiva (Kas/Bank, Piutang, Persediaan IT Inventory, Aset Tetap)
                <br>• <strong>2xxx</strong> : Kewajiban / Hutang (Hutang Dagang AP, Hutang Pajak PPN/PPh)
                <br>• <strong>3xxx</strong> : Ekuitas / Modal (Modal Saham, Laba Ditahan, Laba Tahun Berjalan)
                <br>• <strong>4xxx</strong> : Pendapatan Usaha (Penjualan Lokal, Ekspor, Penjualan Scrap)
                <br>• <strong>5xxx</strong> : Harga Pokok Penjualan / HPP (Bahan Baku, BTKL, BOP)
                <br>• <strong>6xxx</strong> : Beban Operasional &amp; Administrasi Umum
                <br>• <strong>7xxx</strong> : Pendapatan &amp; Beban Lain-lain (Selisih Kurs, Jasa Giro, Biaya Bank)
            </p>
            <div style="font-weight:600; color:var(--primary); margin-bottom:4px;">2. Akun Induk (Header) vs Akun Detail (Transaksi)</div>
            <p style="margin:0 0 8px;">Akun Induk (Header = Y) berfungsi sebagai pengelompokan ringkasan dan tidak boleh digunakan langsung untuk penjurnalan voucher transaksi. Transaksi voucher hanya boleh dijurnal ke akun bertipe Detail (Header = N).</p>
            <div style="font-weight:600; color:var(--primary); margin-bottom:4px;">3. Saldo Awal Buku Besar &amp; Keseimbangan Neraca</div>
            <p style="margin:0;">Pada tab Entri Saldo Awal, total saldo Debet harus sama persis dengan total saldo Kredit (Balance). Jika terdapat selisih, sistem akan menandainya dengan indikator peringatan merah.</p>
        </div>
        <div class="modal-foot">
            <button type="button" class="ant-btn ant-btn-primary" onclick="closeModuleGuide()">Tutup Panduan</button>
        </div>
    </div>
</div>

<script>
    const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
    let coaAccounts = [];
    let selectedCoaRow = null;
    let contextCoaData = null;

    document.addEventListener('DOMContentLoaded', () => {
        initCoaContextMenu();
        setupKeyboardShortcuts();
        initTableColumnResizing('tblCoa', 'coa_grid');
        initTableColumnResizing('tblSaldoAwal', 'coa_saldo_grid');

        // Theme switch listener
        const savedTheme = localStorage.getItem('ceisa_theme');
        if (savedTheme === 'dark') document.body.classList.add('dark-theme');
        window.addEventListener('storage', (e) => {
            if (e.key === 'ceisa_theme') {
                if (e.newValue === 'dark') document.body.classList.add('dark-theme');
                else document.body.classList.remove('dark-theme');
            }
        });

        loadAccounts();
    });

    // -------------------------------------------------------------------------
    // 1. TABS NAVIGATION
    // -------------------------------------------------------------------------
    function switchTab(tabId) {
        document.querySelectorAll('.ant-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tabId);
        });
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.toggle('active', content.id === tabId);
        });

        if (tabId === 'tabSaldo') {
            loadPeriodSaldo();
        }
    }

    // -------------------------------------------------------------------------
    // 2. COLUMN RESIZING WITH LOCALSTORAGE PERSISTENCE
    // -------------------------------------------------------------------------
    let _isGridResizing = false;

    function loadSavedColumnWidths(tableKey) {
        try {
            const raw = localStorage.getItem('col_widths_' + tableKey);
            return raw ? JSON.parse(raw) : {};
        } catch(e) { return {}; }
    }

    function saveColumnWidth(tableKey, colKey, width) {
        try {
            const widths = loadSavedColumnWidths(tableKey);
            widths[colKey] = width;
            localStorage.setItem('col_widths_' + tableKey, JSON.stringify(widths));
        } catch(e) {}
    }

    function removeSavedColumnWidth(tableKey, colKey) {
        try {
            const widths = loadSavedColumnWidths(tableKey);
            delete widths[colKey];
            localStorage.setItem('col_widths_' + tableKey, JSON.stringify(widths));
        } catch(e) {}
    }

    function initTableColumnResizing(tableId, tableKey) {
        const table = document.getElementById(tableId);
        if (!table) return;

        const savedWidths = loadSavedColumnWidths(tableKey);
        const ths = table.querySelectorAll('thead th');

        ths.forEach((th, idx) => {
            let colKey = th.dataset.col || ('col_' + idx);
            if (!th.dataset.col) th.dataset.col = colKey;

            if (savedWidths && savedWidths[colKey]) {
                const sw = savedWidths[colKey];
                th.style.width = sw;
                th.style.minWidth = sw;
                th.style.maxWidth = sw;
            }

            let resizer = th.querySelector('.col-resizer');
            if (!resizer) {
                resizer = document.createElement('div');
                resizer.className = 'col-resizer';
                resizer.title = 'Tarik untuk ubah lebar kolom (Klik 2x reset)';
                th.appendChild(resizer);

                resizer.addEventListener('dblclick', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    th.style.width = '';
                    th.style.minWidth = '';
                    th.style.maxWidth = '';
                    removeSavedColumnWidth(tableKey, colKey);
                });

                resizer.addEventListener('mousedown', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    _isGridResizing = true;

                    const startX = e.pageX;
                    const startWidth = th.offsetWidth;
                    resizer.classList.add('resizing');
                    document.body.classList.add('grid-resizing');

                    function onMouseMove(moveEvent) {
                        const diff = moveEvent.pageX - startX;
                        const newWidth = Math.max(26, startWidth + diff);
                        const wStr = newWidth + 'px';
                        th.style.width = wStr;
                        th.style.minWidth = wStr;
                        th.style.maxWidth = wStr;
                    }

                    function onMouseUp() {
                        document.removeEventListener('mousemove', onMouseMove);
                        document.removeEventListener('mouseup', onMouseUp);
                        resizer.classList.remove('resizing');
                        document.body.classList.remove('grid-resizing');

                        if (th.style.width && tableKey && colKey) {
                            saveColumnWidth(tableKey, colKey, th.style.width);
                        }

                        setTimeout(() => { _isGridResizing = false; }, 120);
                    }

                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });
            }
        });
    }

    // -------------------------------------------------------------------------
    // 3. LOAD COA ACCOUNTS
    // -------------------------------------------------------------------------
    async function loadAccounts() {
        const tbody = document.getElementById('tblCoaBody');
        tbody.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:25px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat bagan akun...</td></tr>`;

        const kat     = document.getElementById('filterKategori').value;
        const posLap  = document.getElementById('filterPosLap').value;
        const posSal  = document.getElementById('filterPosSal').value;
        const search  = document.getElementById('filterSearch').value.trim();
        const onlyHdr = document.getElementById('chkOnlyHeader').checked ? '1' : '';

        try {
            const url = `chart_of_accounts.php?action=get_accounts&kategori=${encodeURIComponent(kat)}&pos_laporan=${encodeURIComponent(posLap)}&pos_saldo=${encodeURIComponent(posSal)}&search=${encodeURIComponent(search)}&only_header=${onlyHdr}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`;
            const res = await fetch(url);
            if (!res.ok) throw new Error(`HTTP error ${res.status}`);
            const json = await res.json();

            coaAccounts = json.data || [];
            renderCoaTable();
            populateParentDropdown();
            updateKpiMetrics();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:25px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Gagal memuat akun: ${escapeHtml(e.message)}</td></tr>`;
        }
    }

    function renderCoaTable() {
        const tbody = document.getElementById('tblCoaBody');
        tbody.innerHTML = '';

        if (coaAccounts.length === 0) {
            tbody.innerHTML = `<tr><td colspan="12" class="text-center" style="padding:30px; color:var(--text-muted);">Tidak ada data akun yang memenuhi filter ini.</td></tr>`;
            return;
        }

        coaAccounts.forEach((r, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.idx = idx;

            const isHeader = (r.header === 'Y');
            if (isHeader) tr.classList.add('is-header-row');

            const lvl = parseInt(r.level, 10) || 1;
            const indentCls = 'tree-indent-' + Math.min(5, Math.max(1, lvl));

            const posSalBadge = r.pos_saldo === 'D' 
                ? '<span class="badge-pos badge-debet">DEBET</span>' 
                : '<span class="badge-pos badge-kredit">KREDIT</span>';

            const posLapBadge = r.pos_laporan === 'NR' 
                ? '<span class="badge-pos badge-nr">NERACA</span>' 
                : '<span class="badge-pos badge-lr">L/R</span>';

            const hdrBadge = isHeader 
                ? '<span class="badge-pos badge-hdr"><i class="fa-solid fa-folder"></i> INDUK</span>' 
                : '<span class="badge-pos badge-dtl">DETAIL</span>';

            const levelIcon = isHeader 
                ? '<i class="fa-solid fa-folder-tree" style="color:#f59e0b; margin-right:4px;"></i>' 
                : '<i class="fa-regular fa-file-lines" style="color:var(--text-muted); margin-right:4px;"></i>';

            tr.innerHTML = `
                <td class="text-center">${idx + 1}</td>
                <td style="font-weight:700; font-family:monospace; color:${isHeader ? 'var(--primary)' : 'inherit'};">${escapeHtml(r.kode)}</td>
                <td class="${indentCls}" style="${isHeader ? 'font-weight:700;' : ''}">${levelIcon} ${escapeHtml(r.nama_akun)}</td>
                <td>${escapeHtml(r.tipe || r.kategori || '-')}</td>
                <td class="text-center">${posLapBadge}</td>
                <td class="text-center">${posSalBadge}</td>
                <td class="text-center"><span style="font-family:monospace; font-weight:600;">${escapeHtml(r.kurs || 'IDR')}</span></td>
                <td class="text-center">${hdrBadge}</td>
                <td class="text-center"><span style="font-size:8.5px; font-weight:600;">L${lvl}</span></td>
                <td class="text-right" style="font-family:monospace;">${r.saldo_awal !== 0 ? numberFormat(r.saldo_awal) : '-'}</td>
                <td class="text-right" style="font-family:monospace; font-weight:600; color:${r.saldo_berjalan < 0 ? 'var(--danger)' : 'inherit'};">${r.saldo_berjalan !== 0 ? numberFormat(r.saldo_berjalan) : '-'}</td>
                <td class="text-center">
                    <button type="button" class="ant-btn ant-btn-sm" onclick="event.stopPropagation(); editAccountByIdx(${idx})" title="Edit Akun"><i class="fa-solid fa-pen"></i></button>
                    <button type="button" class="ant-btn ant-btn-sm" style="color:var(--danger);" onclick="event.stopPropagation(); deleteAccount('${escapeHtml(r.kode)}')" title="Hapus Akun"><i class="fa-solid fa-trash-can"></i></button>
                </td>
            `;

            tr.addEventListener('click', () => {
                document.querySelectorAll('#tblCoaBody tr').forEach(row => row.classList.remove('row-selected'));
                tr.classList.add('row-selected');
                selectedCoaRow = r;
            });

            tr.addEventListener('dblclick', () => {
                editAccountByIdx(idx);
            });

            tbody.appendChild(tr);
        });

        initTableColumnResizing('tblCoa', 'coa_grid');
    }

    function populateParentDropdown() {
        const sel = document.getElementById('accParentKode');
        if (!sel) return;
        const curVal = sel.value;
        sel.innerHTML = '<option value="">-- Tidak Ada (Tingkat Paling Atas) --</option>';
        coaAccounts.filter(a => a.header === 'Y').forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.kode;
            opt.textContent = `${a.kode} - ${a.nama_akun} (Level ${a.level})`;
            sel.appendChild(opt);
        });
        sel.value = curVal;
    }

    function updateKpiMetrics() {
        let totAkun = coaAccounts.length;
        let aktiva = 0;
        let pasiva = 0;
        let labaRugi = 0;

        coaAccounts.forEach(a => {
            const val = (a.saldo_berjalan || a.saldo_awal || 0);
            if (a.kategori === 'AKTIVA') aktiva += val;
            else if (a.kategori === 'KEWAJIBAN' || a.kategori === 'EKUITAS') pasiva += val;
            else if (a.pos_laporan === 'LR') {
                if (a.pos_saldo === 'K') labaRugi += val;
                else labaRugi -= val;
            }
        });

        document.getElementById('kpiTotalAkun').innerText = `${totAkun} Akun`;
        document.getElementById('kpiAktiva').innerText    = 'Rp ' + numberFormat(aktiva);
        document.getElementById('kpiPasiva').innerText    = 'Rp ' + numberFormat(pasiva);
        document.getElementById('kpiLabaRugi').innerText  = 'Rp ' + numberFormat(labaRugi);
    }

    // -------------------------------------------------------------------------
    // 4. MODAL EDIT / TAMBAH AKUN
    // -------------------------------------------------------------------------
    function openAccountModal(isEdit, defaultParent = null) {
        document.getElementById('accIsEdit').value = isEdit ? '1' : '0';
        document.getElementById('modalAccountTitle').innerHTML = isEdit 
            ? '<i class="fa-solid fa-pen-to-square" style="color:var(--primary);"></i> Edit Data Bagan Akun' 
            : '<i class="fa-solid fa-folder-plus" style="color:var(--primary);"></i> Tambah Bagan Akun Baru';

        const kodeInp = document.getElementById('accKode');
        kodeInp.readOnly = isEdit;

        populateParentDropdown();

        if (isEdit && selectedCoaRow) {
            kodeInp.value                     = selectedCoaRow.kode;
            document.getElementById('accNama').value        = selectedCoaRow.nama_akun;
            document.getElementById('accKategori').value    = selectedCoaRow.kategori || 'AKTIVA';
            document.getElementById('accTipe').value        = selectedCoaRow.tipe || '';
            document.getElementById('accPosLaporan').value  = selectedCoaRow.pos_laporan || 'NR';
            document.getElementById('accPosSaldo').value    = selectedCoaRow.pos_saldo || 'D';
            document.getElementById('accHeader').value      = selectedCoaRow.header || 'N';
            document.getElementById('accParentKode').value  = selectedCoaRow.parent_kode || '';
            document.getElementById('accLevel').value       = selectedCoaRow.level || 3;
            document.getElementById('accKurs').value        = selectedCoaRow.kurs || 'IDR';
            document.getElementById('accSaldoAwal').value   = selectedCoaRow.saldo_awal || '0.00';
        } else {
            document.getElementById('formAccount').reset();
            document.getElementById('accSaldoAwal').value = '0.00';
            if (defaultParent) {
                document.getElementById('accParentKode').value = defaultParent.kode;
                document.getElementById('accKategori').value   = defaultParent.kategori || 'AKTIVA';
                document.getElementById('accPosLaporan').value = defaultParent.pos_laporan || 'NR';
                document.getElementById('accPosSaldo').value   = defaultParent.pos_saldo || 'D';
                document.getElementById('accLevel').value      = Math.min(5, (parseInt(defaultParent.level, 10) || 1) + 1);
                document.getElementById('accHeader').value     = 'N';
                document.getElementById('accKode').value       = defaultParent.kode + '.';
                document.getElementById('accKode').focus();
            }
        }

        document.getElementById('modalAccount').style.display = 'flex';
    }

    function closeAccountModal() {
        document.getElementById('modalAccount').style.display = 'none';
    }

    function editAccountByIdx(idx) {
        if (coaAccounts[idx]) {
            selectedCoaRow = coaAccounts[idx];
            openAccountModal(true);
        }
    }

    function autoSuggestPos(kategori) {
        const posLap = document.getElementById('accPosLaporan');
        const posSal = document.getElementById('accPosSaldo');
        if (kategori === 'AKTIVA') {
            posLap.value = 'NR'; posSal.value = 'D';
        } else if (kategori === 'KEWAJIBAN' || kategori === 'EKUITAS') {
            posLap.value = 'NR'; posSal.value = 'K';
        } else if (kategori === 'PENDAPATAN') {
            posLap.value = 'LR'; posSal.value = 'K';
        } else if (kategori === 'HPP' || kategori === 'BIAYA') {
            posLap.value = 'LR'; posSal.value = 'D';
        }
    }

    async function saveAccountForm(e) {
        e.preventDefault();
        const isEdit = document.getElementById('accIsEdit').value === '1';

        const fd = new FormData();
        fd.append('action', 'save_account');
        fd.append('is_edit', isEdit ? '1' : '0');
        fd.append('kode', document.getElementById('accKode').value.trim());
        fd.append('nama_akun', document.getElementById('accNama').value.trim());
        fd.append('kategori', document.getElementById('accKategori').value);
        fd.append('tipe', document.getElementById('accTipe').value.trim());
        fd.append('pos_laporan', document.getElementById('accPosLaporan').value);
        fd.append('pos_saldo', document.getElementById('accPosSaldo').value);
        fd.append('header', document.getElementById('accHeader').value);
        fd.append('parent_kode', document.getElementById('accParentKode').value);
        fd.append('level', document.getElementById('accLevel').value);
        fd.append('kurs', document.getElementById('accKurs').value);
        fd.append('saldo_awal', document.getElementById('accSaldoAwal').value);
        fd.append('cmp_id', CURRENT_CMP_ID);

        try {
            const res = await fetch(`chart_of_accounts.php?cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
                method: 'POST',
                body: fd
            });
            const json = await res.json();
            if (json.status === 'OK') {
                closeAccountModal();
                Swal.fire({ icon: 'success', title: 'Berhasil Disimpan', text: json.message, timer: 1800, showConfirmButton: false });
                loadAccounts();
            } else {
                Swal.fire('Gagal Menyimpan', json.message, 'error');
            }
        } catch (err) {
            Swal.fire('Error', err.message, 'error');
        }
    }

    async function deleteAccount(kode) {
        const confirm = await Swal.fire({
            title: `Hapus Akun ${kode}?`,
            text: 'Apakah Anda yakin ingin menghapus akun ini? Data jurnal terkait mungkin terpengaruh.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Ya, Hapus'
        });

        if (!confirm.isConfirmed) return;

        try {
            const fd = new FormData();
            fd.append('action', 'delete_account');
            fd.append('kode', kode);
            fd.append('cmp_id', CURRENT_CMP_ID);

            const res = await fetch(`chart_of_accounts.php?cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
                method: 'POST',
                body: fd
            });
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire({ icon: 'success', title: 'Terhapus', text: json.message, timer: 1500, showConfirmButton: false });
                loadAccounts();
            } else {
                Swal.fire('Gagal', json.message, 'error');
            }
        } catch (err) {
            Swal.fire('Error', err.message, 'error');
        }
    }

    async function seedDefaultCoa() {
        const confirm = await Swal.fire({
            title: 'Terapkan Standar SAK KB?',
            html: 'Apakah Anda ingin mengimpor bagan akun standar SAK Manufaktur Kawasan Berikat? Akun standar yang belum ada akan otomatis dibuat.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#4338ca',
            confirmButtonText: 'Ya, Sinkronkan Standar'
        });

        if (!confirm.isConfirmed) return;

        try {
            const res = await fetch(`chart_of_accounts.php?action=seed_default_coa&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire('Berhasil', json.message, 'success');
                loadAccounts();
            } else {
                Swal.fire('Gagal', json.message, 'error');
            }
        } catch (e) {
            Swal.fire('Error', e.message, 'error');
        }
    }

    function resetCoaFilter() {
        document.getElementById('filterKategori').value = 'ALL';
        document.getElementById('filterPosLap').value   = 'ALL';
        document.getElementById('filterPosSal').value   = 'ALL';
        document.getElementById('filterSearch').value   = '';
        document.getElementById('chkOnlyHeader').checked = false;
        loadAccounts();
    }

    // -------------------------------------------------------------------------
    // 5. TAB 2: SALDO AWAL BUKU BESAR (bc_act_akunsaldo)
    // -------------------------------------------------------------------------
    let periodSaldoRows = [];

    async function loadPeriodSaldo() {
        const tbody = document.getElementById('tblSaldoAwalBody');
        tbody.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:25px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat saldo awal periode...</td></tr>`;

        const per = document.getElementById('saldoPeriode').value.trim() || '<?= date('Y') ?>/01';

        try {
            const res = await fetch(`chart_of_accounts.php?action=get_period_saldo&periode=${encodeURIComponent(per)}&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`);
            const json = await res.json();
            periodSaldoRows = json.data || [];

            renderPeriodSaldoTable(json);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:25px; color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Gagal memuat saldo awal: ${escapeHtml(e.message)}</td></tr>`;
        }
    }

    function renderPeriodSaldoTable(meta) {
        const tbody = document.getElementById('tblSaldoAwalBody');
        tbody.innerHTML = '';

        if (periodSaldoRows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:25px; color:var(--text-muted);">Tidak ada akun yang ditemukan untuk diset saldo awal.</td></tr>`;
            return;
        }

        periodSaldoRows.forEach((r, idx) => {
            const tr = document.createElement('tr');
            const isHeader = (r.header === 'Y');
            if (isHeader) tr.classList.add('is-header-row');

            tr.innerHTML = `
                <td class="text-center">${idx + 1}</td>
                <td style="font-family:monospace; font-weight:700;">${escapeHtml(r.kode)}</td>
                <td style="${isHeader ? 'font-weight:700;' : ''}">${escapeHtml(r.nama_akun)}</td>
                <td class="text-center"><span class="badge-pos ${r.pos_saldo === 'D' ? 'badge-debet' : 'badge-kredit'}">${r.pos_saldo === 'D' ? 'DEBET' : 'KREDIT'}</span></td>
                <td class="text-center"><span class="badge-pos ${r.pos_laporan === 'NR' ? 'badge-nr' : 'badge-lr'}">${r.pos_laporan}</span></td>
                <td class="text-right">
                    ${isHeader ? '-' : `<input type="number" step="0.01" class="form-ctrl text-right saldo-db-inp" data-idx="${idx}" value="${r.saldoawal_db}" style="height:20px; font-size:9.5px; width:100%; border:1px solid var(--border-input); border-radius:2px;" onchange="recalculateSaldoBalance()">`}
                </td>
                <td class="text-right">
                    ${isHeader ? '-' : `<input type="number" step="0.01" class="form-ctrl text-right saldo-kr-inp" data-idx="${idx}" value="${r.saldoawal_kr}" style="height:20px; font-size:9.5px; width:100%; border:1px solid var(--border-input); border-radius:2px;" onchange="recalculateSaldoBalance()">`}
                </td>
            `;
            tbody.appendChild(tr);
        });

        updateSaldoAuditStrip(meta.total_db, meta.total_kr, meta.diff, meta.is_balanced);
        initTableColumnResizing('tblSaldoAwal', 'coa_saldo_grid');
    }

    function recalculateSaldoBalance() {
        let totDb = 0;
        let totKr = 0;

        document.querySelectorAll('.saldo-db-inp').forEach(inp => {
            const val = parseFloat(inp.value) || 0;
            const idx = parseInt(inp.dataset.idx, 10);
            if (periodSaldoRows[idx]) periodSaldoRows[idx].saldoawal_db = val;
            totDb += val;
        });

        document.querySelectorAll('.saldo-kr-inp').forEach(inp => {
            const val = parseFloat(inp.value) || 0;
            const idx = parseInt(inp.dataset.idx, 10);
            if (periodSaldoRows[idx]) periodSaldoRows[idx].saldoawal_kr = val;
            totKr += val;
        });

        const diff = Math.round((totDb - totKr) * 100) / 100;
        updateSaldoAuditStrip(totDb, totKr, diff, diff === 0);
    }

    function updateSaldoAuditStrip(totDb, totKr, diff, isBalanced) {
        document.getElementById('lblTotDb').innerText = 'Rp ' + numberFormat(totDb);
        document.getElementById('lblTotKr').innerText = 'Rp ' + numberFormat(totKr);

        const st = document.getElementById('lblBalanceStatus');
        if (isBalanced) {
            st.style.color = 'var(--success)';
            st.innerHTML = '<i class="fa-solid fa-circle-check"></i> SEIMBANG (BALANCE)';
        } else {
            st.style.color = 'var(--danger)';
            st.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> SELISIH Rp ${numberFormat(Math.abs(diff))}`;
        }
    }

    async function savePeriodSaldo() {
        const per = document.getElementById('saldoPeriode').value.trim();
        if (!per) {
            Swal.fire('Peringatan', 'Isi periode akuntansi terlebih dahulu.', 'warning');
            return;
        }

        const items = [];
        periodSaldoRows.forEach(r => {
            if (r.header !== 'Y') {
                items.push({
                    kode_akun: r.kode,
                    saldoawal_db: r.saldoawal_db || 0,
                    saldoawal_kr: r.saldoawal_kr || 0
                });
            }
        });

        Swal.fire({ title: 'Menyimpan Saldo Awal...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            const res = await fetch(`chart_of_accounts.php?action=save_period_saldo&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ periode: per, items: items })
            });
            const json = await res.json();
            if (json.status === 'OK') {
                Swal.fire('Berhasil Disimpan!', json.message, 'success');
            } else {
                Swal.fire('Gagal', json.message, 'error');
            }
        } catch (e) {
            Swal.fire('Error', e.message, 'error');
        }
    }

    // -------------------------------------------------------------------------
    // 6. CONTEXT MENU & SHORTCUTS
    // -------------------------------------------------------------------------
    function initCoaContextMenu() {
        const table = document.getElementById('tblCoa');
        const menu  = document.getElementById('gridContextMenu');
        if (!table || !menu) return;

        table.addEventListener('contextmenu', (e) => {
            const row = e.target.closest('#tblCoaBody tr');
            if (!row || !row.dataset.idx) return;
            e.preventDefault();
            e.stopPropagation();

            const idx = parseInt(row.dataset.idx, 10);
            contextCoaData = coaAccounts[idx];
            selectedCoaRow = contextCoaData;

            table.querySelectorAll('tr').forEach(r => r.classList.remove('row-selected'));
            row.classList.add('row-selected');

            menu.style.display = 'block';
            let left = e.clientX;
            let top  = e.clientY;
            if (left + 220 > window.innerWidth) left = window.innerWidth - 230;
            if (top + 180 > window.innerHeight) top = window.innerHeight - 190;
            menu.style.left = left + 'px';
            menu.style.top  = top + 'px';
        });

        document.addEventListener('click', () => {
            if (menu) menu.style.display = 'none';
        });
    }

    function ctxAddChildAccount() {
        document.getElementById('gridContextMenu').style.display = 'none';
        if (contextCoaData) {
            openAccountModal(false, contextCoaData);
        }
    }

    function ctxEditAccount() {
        document.getElementById('gridContextMenu').style.display = 'none';
        if (contextCoaData) {
            selectedCoaRow = contextCoaData;
            openAccountModal(true);
        }
    }

    function ctxDeleteAccount() {
        document.getElementById('gridContextMenu').style.display = 'none';
        if (contextCoaData) {
            deleteAccount(contextCoaData.kode);
        }
    }

    function ctxCopyTsv() {
        document.getElementById('gridContextMenu').style.display = 'none';
        if (!contextCoaData) return;
        const rowText = [
            contextCoaData.kode,
            contextCoaData.nama_akun,
            contextCoaData.tipe,
            contextCoaData.pos_laporan,
            contextCoaData.pos_saldo,
            contextCoaData.kurs,
            contextCoaData.saldo_awal,
            contextCoaData.saldo_berjalan
        ].join('\t');

        navigator.clipboard.writeText(rowText).then(() => {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Data akun disalin ke clipboard', showConfirmButton: false, timer: 1500 });
        });
    }

    function exportCoaExcel() {
        const tbl = document.getElementById('tblCoa');
        if (!tbl) return;
        try {
            const wb = XLSX.utils.table_to_book(tbl, { sheet: "COA_Accounts" });
            XLSX.writeFile(wb, `Chart_Of_Accounts_${CURRENT_CMP_ID}_${Date.now()}.xlsx`);
        } catch (e) {
            Swal.fire('Error Export', e.message, 'error');
        }
    }

    function setupKeyboardShortcuts() {
        window.addEventListener('keydown', (e) => {
            if (e.key === 'F2') {
                e.preventDefault();
                loadAccounts();
            }
        });
    }

    function openModuleGuide() {
        document.getElementById('modalGuide').style.display = 'flex';
    }

    function closeModuleGuide() {
        document.getElementById('modalGuide').style.display = 'none';
    }

    function numberFormat(val, decimals = 2) {
        const num = parseFloat(val) || 0;
        return num.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }
</script>

</body>
</html>

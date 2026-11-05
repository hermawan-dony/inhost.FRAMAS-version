<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Konfigurasi Akun & Pemetaan Jurnal Otomatis
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_act_konfig.srw)
 * DataWindows: bc_act_grup1.srd, bc_act_grup2.srd, bc_act_kurs.srd, bc_act_tax.srd, bc_konfig_bs.srd, bc_act_bs.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Akun Pajak, Klasifikasi Barang/HPP, Valas Kurs, Rekanan GL Mapping)
 * - 5 Tabs Transaksi Akuntansi ERP:
 *   1. Pajak (PPN & PPh) - bc_act_tax
 *   2. Tipe Persediaan, Pembelian & HPP - bc_act_tipe
 *   3. Valas & Akun Kurs Transaksi - bc_ref_kurs
 *   4. Kebijakan Rekanan & Subledger - bc_act_config & bc_bs
 *   5. Pos Laporan Keuangan (HPP, Laba Rugi, Ekuitas) - bc_act_rptgrup
 * - Modal COA Picker Cerdas (Pilih akun dari daftar Chart of Accounts dengan pencarian instan)
 * - DataGrid Enterprise Resizable (.col-resizer) & Right-Click Context Menu
 * - SweetAlert2 Compact Desktop (330px width, font 9.5px, button 21px)
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
$escCmp = $koneksi->real_escape_string($sessionCmpId);
$isAdmin = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_super_admin']);
$userId  = $_SESSION['user_name'] ?? ($_SESSION['user_id'] ?? 'ADMIN');

session_write_close();

$action = $_REQUEST['action'] ?? '';

// Ensure defaults
function ensureConfigDefaults($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS bc_act_config (
        bs_pakai_akun varchar(1) DEFAULT 'Y',
        rekap_level varchar(1) DEFAULT 'N',
        cmp_id varchar(30) NOT NULL,
        PRIMARY KEY (cmp_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $chk = $koneksi->query("SELECT COUNT(*) as c FROM bc_act_config WHERE cmp_id = '$escCmp'");
    $cnt = $chk ? intval($chk->fetch_assoc()['c']) : 0;
    if ($cnt === 0) {
        $koneksi->query("INSERT IGNORE INTO bc_act_config (bs_pakai_akun, rekap_level, cmp_id) VALUES ('Y', 'N', '$escCmp')");
    }

    $chkTax = $koneksi->query("SELECT COUNT(*) as c FROM bc_act_tax WHERE cmp_id = '$escCmp'");
    $cntTax = $chkTax ? intval($chkTax->fetch_assoc()['c']) : 0;
    if ($cntTax === 0) {
        $koneksi->query("INSERT IGNORE INTO bc_act_tax (seq, label, akun, cmp_id) VALUES 
            (1, 'PPN Pembelian / Masukan', '114-01-001', '$escCmp'),
            (2, 'PPN Penjualan / Keluaran', '114-02-004', '$escCmp'),
            (3, 'Hutang PPH 23', '214-01-002', '$escCmp'),
            (4, 'Piutang PPH 23', '114-03-001', '$escCmp')");
    }
}
ensureConfigDefaults($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";

    $resTax = $koneksi->query("SELECT COUNT(*) as c FROM bc_act_tax WHERE $cmpClause");
    $cntTax = $resTax ? intval($resTax->fetch_assoc()['c']) : 0;

    $resTipe = $koneksi->query("SELECT COUNT(*) as c FROM bc_act_tipe WHERE $cmpClause");
    $cntTipe = $resTipe ? intval($resTipe->fetch_assoc()['c']) : 0;

    $resKurs = $koneksi->query("SELECT COUNT(*) as c FROM bc_ref_kurs WHERE $cmpClause");
    $cntKurs = $resKurs ? intval($resKurs->fetch_assoc()['c']) : 0;

    $resBs = $koneksi->query("SELECT COUNT(*) as total, 
                              SUM(CASE WHEN IFNULL(KODE_BANTU,'') = '' THEN 1 ELSE 0 END) as blm_bantu,
                              SUM(CASE WHEN IFNULL(AKUN,'') = '' THEN 1 ELSE 0 END) as blm_akun
                              FROM bc_bs WHERE $cmpClause");
    $rowBs = $resBs ? $resBs->fetch_assoc() : ['total' => 0, 'blm_bantu' => 0, 'blm_akun' => 0];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'tax_count'       => $cntTax,
            'tipe_count'      => $cntTipe,
            'kurs_count'      => $cntKurs,
            'total_rekanan'   => (int)$rowBs['total'],
            'blm_kode_bantu'  => (int)$rowBs['blm_bantu'],
            'blm_akun'        => (int)$rowBs['blm_akun']
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Chart of Accounts (COA) Dropdown Reference
// =========================================================================
if ($action === 'get_coa_ref') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $sql = "SELECT kode, nama_akun as nama, tipe FROM bc_act_akun WHERE $cmpClause ORDER BY kode ASC";
    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tab 1 - Pajak (bc_act_tax)
// =========================================================================
if ($action === 'get_tax') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $sql = "SELECT a.seq, a.label, a.akun, IFNULL(acc.nama_akun, '') as nama_akun 
            FROM bc_act_tax a
            LEFT JOIN bc_act_akun acc ON acc.kode = a.akun AND (acc.cmp_id = '$escCmp' OR acc.cmp_id = '')
            WHERE $cmpClause
            ORDER BY a.seq ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

if ($action === 'save_tax') {
    header('Content-Type: application/json; charset=utf-8');
    $seq   = intval($_POST['seq'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $akun  = trim($_POST['akun'] ?? '');

    if ($seq <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Parameter seq tidak valid']);
        exit;
    }

    $escL = $koneksi->real_escape_string($label);
    $escA = $koneksi->real_escape_string($akun);

    $sql = "INSERT INTO bc_act_tax (seq, label, akun, cmp_id) VALUES ($seq, '$escL', '$escA', '$escCmp')
            ON DUPLICATE KEY UPDATE label = '$escL', akun = '$escA'";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Pemetaan Akun Pajak berhasil diperbarui']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tab 2 - Tipe Persediaan & HPP (bc_act_tipe)
// =========================================================================
if ($action === 'get_tipe') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(t.cmp_id = '$escCmp' OR t.cmp_id = '')" : "1=1";
    $sql = "SELECT t.tipe, t.grp, t.idx, 
            t.akun_aktiva, t.akun_saldo_awal, t.akun_pembelian, t.akun_pembelian_import, 
            t.akun_saldo_akhir, t.akun_depre,
            a1.nama_akun as nama_aktiva,
            a2.nama_akun as nama_pembelian
            FROM bc_act_tipe t
            LEFT JOIN bc_act_akun a1 ON a1.kode = t.akun_aktiva AND (a1.cmp_id = '$escCmp' OR a1.cmp_id = '')
            LEFT JOIN bc_act_akun a2 ON a2.kode = t.akun_pembelian AND (a2.cmp_id = '$escCmp' OR a2.cmp_id = '')
            WHERE $cmpClause AND t.grp NOT IN ('%', 'PS')
            ORDER BY COALESCE(t.idx, 99) ASC, t.tipe ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

if ($action === 'save_tipe') {
    header('Content-Type: application/json; charset=utf-8');
    $tipe      = trim($_POST['tipe'] ?? '');
    $grp       = trim($_POST['grp'] ?? '');
    $idx       = intval($_POST['idx'] ?? 99);
    $aktiva    = trim($_POST['akun_aktiva'] ?? '');
    $saldoAwal = trim($_POST['akun_saldo_awal'] ?? '');
    $beli      = trim($_POST['akun_pembelian'] ?? '');
    $beliImp   = trim($_POST['akun_pembelian_import'] ?? '');
    $saldoAkh  = trim($_POST['akun_saldo_akhir'] ?? '');
    $depre     = trim($_POST['akun_depre'] ?? '');

    if (empty($tipe)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tipe klasifikasi wajib diisi']);
        exit;
    }

    $escTipe     = $koneksi->real_escape_string($tipe);
    $escGrp      = $koneksi->real_escape_string($grp);
    $escAktiva   = $koneksi->real_escape_string($aktiva);
    $escSaldoAwal= $koneksi->real_escape_string($saldoAwal);
    $escBeli     = $koneksi->real_escape_string($beli);
    $escBeliImp  = $koneksi->real_escape_string($beliImp);
    $escSaldoAkh = $koneksi->real_escape_string($saldoAkh);
    $escDepre    = $koneksi->real_escape_string($depre);

    $sql = "INSERT INTO bc_act_tipe (tipe, grp, idx, akun_aktiva, akun_saldo_awal, akun_pembelian, akun_pembelian_import, akun_saldo_akhir, akun_depre, cmp_id)
            VALUES ('$escTipe', '$escGrp', $idx, '$escAktiva', '$escSaldoAwal', '$escBeli', '$escBeliImp', '$escSaldoAkh', '$escDepre', '$escCmp')
            ON DUPLICATE KEY UPDATE 
                grp = '$escGrp', idx = $idx, akun_aktiva = '$escAktiva', akun_saldo_awal = '$escSaldoAwal',
                akun_pembelian = '$escBeli', akun_pembelian_import = '$escBeliImp', akun_saldo_akhir = '$escSaldoAkh', akun_depre = '$escDepre'";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Konfigurasi Akun Tipe Barang berhasil disimpan']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tab 3 - Valas & Akun Kurs (bc_ref_kurs)
// =========================================================================
if ($action === 'get_kurs') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(k.cmp_id = '$escCmp' OR k.cmp_id = '')" : "1=1";
    $sql = "SELECT k.*, 
            ah.nama_akun as nama_hutang, 
            ap.nama_akun as nama_piutang
            FROM bc_ref_kurs k
            LEFT JOIN bc_act_akun ah ON ah.kode = k.akun_hutang AND (ah.cmp_id = '$escCmp' OR ah.cmp_id = '')
            LEFT JOIN bc_act_akun ap ON ap.kode = k.akun_piutang AND (ap.cmp_id = '$escCmp' OR ap.cmp_id = '')
            WHERE $cmpClause
            ORDER BY k.is_default DESC, k.nama ASC";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

if ($action === 'save_kurs') {
    header('Content-Type: application/json; charset=utf-8');
    $nama      = strtoupper(trim($_POST['nama'] ?? ''));
    $nilai     = floatval($_POST['nilaitukar'] ?? 1);
    $negara    = trim($_POST['negara'] ?? '');
    $simbol    = trim($_POST['simbol'] ?? '');
    $hutang    = trim($_POST['akun_hutang'] ?? '');
    $piutang   = trim($_POST['akun_piutang'] ?? '');
    $jualEpte  = trim($_POST['akun_jual_epte'] ?? '');
    $jualNon   = trim($_POST['akun_jual_non'] ?? '');
    $diskonJual= trim($_POST['akun_diskon_jual'] ?? '');
    $diskonBeli= trim($_POST['akun_diskon_beli'] ?? '');
    $retur     = trim($_POST['akun_retur'] ?? '');
    $jasa      = trim($_POST['akun_jasa'] ?? '');

    if (empty($nama)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode Valuta wajib diisi']);
        exit;
    }

    $escNama     = $koneksi->real_escape_string($nama);
    $escNegara   = $koneksi->real_escape_string($negara);
    $escSimbol   = $koneksi->real_escape_string($simbol);
    $escHutang   = $koneksi->real_escape_string($hutang);
    $escPiutang  = $koneksi->real_escape_string($piutang);
    $escJualEpte = $koneksi->real_escape_string($jualEpte);
    $escJualNon  = $koneksi->real_escape_string($jualNon);
    $escDiskJ    = $koneksi->real_escape_string($diskonJual);
    $escDiskB    = $koneksi->real_escape_string($diskonBeli);
    $escRetur    = $koneksi->real_escape_string($retur);
    $escJasa     = $koneksi->real_escape_string($jasa);

    $sql = "UPDATE bc_ref_kurs SET 
            nilaitukar = $nilai, negara = '$escNegara', simbol = '$escSimbol',
            akun_hutang = '$escHutang', akun_piutang = '$escPiutang',
            akun_jual_epte = '$escJualEpte', akun_jual_non = '$escJualNon',
            akun_diskon_jual = '$escDiskJ', akun_diskon_beli = '$escDiskB',
            akun_retur = '$escRetur', akun_jasa = '$escJasa'
            WHERE nama = '$escNama' AND (cmp_id = '$escCmp' OR cmp_id = '')";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Konfigurasi Akun Kurs Valas berhasil disimpan']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tab 4 - Kebijakan Rekanan & Subledger (bc_act_config & bc_bs)
// =========================================================================
if ($action === 'get_policy') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "cmp_id = '$escCmp'" : "1=1";
    $cfg = $koneksi->query("SELECT bs_pakai_akun, rekap_level FROM bc_act_config WHERE $cmpClause LIMIT 1");
    $configData = $cfg ? $cfg->fetch_assoc() : ['bs_pakai_akun' => 'Y', 'rekap_level' => 'N'];

    $whereBs = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $sqlBs = "SELECT KODE as kode, TIPE as tipe, NAMA as nama, AKUN as akun, KODE_BANTU as kode_bantu,
              (SELECT nama_akun FROM bc_act_akun WHERE kode = bc_bs.AKUN AND (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1) as nama_akun_gl
              FROM bc_bs
              WHERE $whereBs AND KODE IS NOT NULL AND TRIM(KODE) != ''
              ORDER BY TIPE ASC, KODE ASC LIMIT 500";
    $resBs = $koneksi->query($sqlBs);
    $bsList = [];
    if ($resBs) {
        while ($r = $resBs->fetch_assoc()) $bsList[] = $r;
    }

    echo json_encode(['status' => 'OK', 'config' => $configData, 'rekanan' => $bsList]);
    exit;
}

if ($action === 'save_policy_setting') {
    header('Content-Type: application/json; charset=utf-8');
    $bsPakaiAkun = trim($_POST['bs_pakai_akun'] ?? 'Y');
    $rekapLevel  = trim($_POST['rekap_level'] ?? 'N');

    $escP = $koneksi->real_escape_string($bsPakaiAkun);
    $escR = $koneksi->real_escape_string($rekapLevel);

    $sql = "INSERT INTO bc_act_config (bs_pakai_akun, rekap_level, cmp_id) VALUES ('$escP', '$escR', '$escCmp')
            ON DUPLICATE KEY UPDATE bs_pakai_akun = '$escP', rekap_level = '$escR'";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Kebijakan Akun Rekanan berhasil disimpan']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

if ($action === 'save_rekanan_akun') {
    header('Content-Type: application/json; charset=utf-8');
    $kode      = trim($_POST['kode'] ?? '');
    $akun      = trim($_POST['akun'] ?? '');
    $kodeBantu = trim($_POST['kode_bantu'] ?? '');

    if (empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode rekanan tidak valid']);
        exit;
    }

    $escKode  = $koneksi->real_escape_string($kode);
    $escAkun  = $koneksi->real_escape_string($akun);
    $escBantu = $koneksi->real_escape_string($kodeBantu);

    $sql = "UPDATE bc_bs SET AKUN = '$escAkun', KODE_BANTU = '$escBantu' WHERE KODE = '$escKode' AND (cmp_id = '$escCmp' OR cmp_id = '')";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => "Pemetaan akun rekanan $kode berhasil disimpan"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

if ($action === 'batch_set_kode_bantu') {
    header('Content-Type: application/json; charset=utf-8');
    $sql = "UPDATE bc_bs SET KODE_BANTU = KODE WHERE (KODE_BANTU IS NULL OR TRIM(KODE_BANTU) = '') AND (cmp_id = '$escCmp' OR cmp_id = '')";
    if ($koneksi->query($sql)) {
        $aff = $koneksi->affected_rows;
        echo json_encode(['status' => 'OK', 'message' => "Berhasil menetapkan Kode Bantu = Kode Mitra untuk $aff rekanan"]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Tab 5 - Pos Laporan Keuangan (bc_act_rptgrup)
// =========================================================================
if ($action === 'get_rpt') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $sql = "SELECT a.*, 
            CASE WHEN rpt = 'HPP' THEN 1 ELSE CASE WHEN rpt = 'LR' THEN 2 ELSE CASE WHEN rpt = 'EQ' THEN 3 ELSE 4 END END END idx	
            FROM bc_act_rptgrup a  
            WHERE $cmpClause
            ORDER BY idx, seq";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    echo json_encode(['status' => 'OK', 'data' => $rows]);
    exit;
}

if ($action === 'save_rpt') {
    header('Content-Type: application/json; charset=utf-8');
    $rpt  = trim($_POST['rpt'] ?? '');
    $seq  = intval($_POST['seq'] ?? 0);
    $pos  = trim($_POST['pos'] ?? '');
    $akun = trim($_POST['akun'] ?? '');
    $klas = trim($_POST['klas'] ?? '');

    if (empty($rpt) || $seq <= 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Laporan dan nomor urut seq wajib diisi']);
        exit;
    }

    $escRpt  = $koneksi->real_escape_string($rpt);
    $escPos  = $koneksi->real_escape_string($pos);
    $escAkun = $koneksi->real_escape_string($akun);
    $escKlas = $koneksi->real_escape_string($klas);

    $sql = "INSERT INTO bc_act_rptgrup (rpt, seq, pos, akun, klas, cmp_id) 
            VALUES ('$escRpt', $seq, '$escPos', '$escAkun', '$escKlas', '$escCmp')
            ON DUPLICATE KEY UPDATE pos = '$escPos', akun = '$escAkun', klas = '$escKlas'";
    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => 'Pos Laporan Keuangan berhasil diperbarui']);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => $koneksi->error]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfigurasi Akun & Jurnal Otomatis - CEISA 4.0 & Enterprise Hub</title>

    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SweetAlert2 -->
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>

    <!-- SheetJS -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --primary-active: #096dd9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #1f2937;
            --text-sub: #6b7280;
            --border-color: #d9d9d9;
            --border-light: #e5e7eb;
            --border-table: #d9d9d9;
            --table-head-bg: #fafafa;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
            --form-font-size: 9.5px;
        }

        body[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: #e6e6e6;
            --text-sub: #8c8c8c;
            --border-color: #303030;
            --border-light: #262626;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --row-hover: #262638;
            --row-selected: #111d2c;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: var(--app-font);
        }

        body {
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 9.5px;
            line-height: 1.4;
            padding: 8px 10px;
            overflow-x: hidden;
        }

        /* Top Bar */
        .portal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 4px 8px;
            margin-bottom: 6px;
        }
        .portal-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-main);
        }
        .portal-title i {
            color: var(--primary);
            font-size: 12px;
        }
        .portal-badge {
            font-size: 8.5px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 3px;
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }

        /* KPI Cards Grid */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-bottom: 6px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 5px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 44px;
        }
        .kpi-info h5 {
            font-size: 8.5px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-sub);
            letter-spacing: 0.3px;
        }
        .kpi-info h3 {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
        }
        .kpi-icon {
            width: 26px;
            height: 26px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
        }
        .icon-blue   { background: #e6f7ff; color: #1890ff; }
        .icon-green  { background: #f6ffed; color: #52c41a; }
        .icon-amber  { background: #fffbe6; color: #faad14; }
        .icon-purple { background: #f9f0ff; color: #722ed1; }

        /* Navigation Tabs */
        .ant-tabs-nav {
            display: flex;
            gap: 2px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 6px;
        }
        .ant-tab-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            padding: 3px 10px;
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-sub);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            height: 24px;
            transition: all 0.2s;
        }
        .ant-tab-btn:hover {
            color: var(--primary);
        }
        .ant-tab-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 600;
        }

        /* Toolbar Container */
        .ant-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 4px 8px;
            margin-bottom: 6px;
            gap: 6px;
            flex-wrap: wrap;
        }
        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        /* Compact 21px Inputs & Buttons */
        .ant-input, .ant-select {
            font-size: 9.5px;
            height: 21px;
            padding: 0 6px;
            border: 1px solid var(--border-color);
            border-radius: 3px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .ant-input:focus, .ant-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.15);
        }

        .ant-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 9.5px;
            font-weight: 500;
            height: 21px;
            padding: 0 8px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
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
        .ant-btn-success {
            background: var(--success);
            border-color: var(--success);
            color: #ffffff;
        }
        .ant-btn-success:hover {
            opacity: 0.9;
            color: #ffffff;
        }

        /* DataGrid Container */
        .grid-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .grid-scroll {
            max-height: calc(100vh - 185px);
            overflow: auto;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
            table-layout: fixed;
        }
        table.ant-table th {
            background: var(--table-head-bg);
            color: var(--text-main);
            font-size: 9.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 3px 6px;
            border-right: 1px solid var(--border-table);
            border-bottom: 1px solid var(--border-table);
            position: sticky;
            top: 0;
            z-index: 5;
            user-select: none;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        table.ant-table td {
            padding: 2px 6px;
            border-right: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        table.ant-table tr:hover td {
            background: var(--row-hover);
        }
        table.ant-table tr.selected td {
            background: var(--row-selected);
        }

        /* Resizer handle */
        .col-resizer {
            position: absolute;
            top: 0;
            right: 0;
            width: 5px;
            cursor: col-resize;
            user-select: none;
            height: 100%;
            z-index: 6;
        }
        .col-resizer:hover, .col-resizer.resizing {
            background: var(--primary);
        }

        /* Right-Click Context Menu */
        .ctx-menu {
            position: fixed;
            z-index: 9999;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            min-width: 170px;
            padding: 3px 0;
            display: none;
        }
        .ctx-item {
            padding: 4px 10px;
            font-size: 9.5px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .ctx-item:hover {
            background: var(--row-hover);
            color: var(--primary);
        }
        .ctx-item i {
            width: 13px;
            font-size: 10px;
            text-align: center;
        }
        .ctx-divider {
            height: 1px;
            background: var(--border-light);
            margin: 2px 0;
        }

        /* Modal Dialog */
        .ant-modal-mask {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .ant-modal {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            width: 480px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            animation: modalFade 0.2s ease;
        }
        @keyframes modalFade {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .ant-modal-header {
            padding: 6px 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10.5px;
            font-weight: 600;
        }
        .ant-modal-body {
            padding: 10px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .ant-modal-footer {
            padding: 6px 10px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            background: var(--table-head-bg);
        }

        .form-row {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
            gap: 6px;
        }
        .form-row label {
            width: 130px;
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-sub);
        }
        .form-row input, .form-row select {
            flex: 1;
        }

        /* Compact SweetAlert2 Theme */
        .swal2-popup.swal-compact {
            width: 330px !important;
            padding: 12px 14px !important;
            border-radius: 4px !important;
            font-family: var(--app-font) !important;
            font-size: 9.5px !important;
            border: 1px solid var(--border-color) !important;
        }
        .swal2-popup.swal-compact .swal2-title {
            font-size: 11.5px !important;
            font-weight: 600 !important;
            margin-bottom: 6px !important;
            padding: 0 !important;
        }
        .swal2-popup.swal-compact .swal2-html-container {
            font-size: 9.5px !important;
            margin: 6px 0 !important;
            line-height: 1.4 !important;
        }
        .swal2-popup.swal-compact .swal2-actions {
            margin-top: 10px !important;
            gap: 6px !important;
        }
        .swal2-popup.swal-compact button.swal2-styled {
            font-size: 9.5px !important;
            height: 21px !important;
            padding: 0 10px !important;
            border-radius: 3px !important;
            line-height: 21px !important;
        }
    </style>
</head>
<body>

    <!-- Portal Header -->
    <div class="portal-header">
        <div class="portal-title">
            <i class="fa-solid fa-sliders"></i>
            <span>Konfigurasi Akun & Pemetaan Jurnal Otomatis</span>
            <span class="portal-badge">Tenant: <?= htmlspecialchars($sessionCmpId) ?></span>
            <span style="font-size: 8.5px; color: var(--text-sub); font-weight: 400; margin-left: 4px;">
                Porting PB w_act_konfig.srw (Pajak, Klasifikasi Barang, Valas Kurs, Rekanan, Laporan Keuangan)
            </span>
        </div>
        <div class="toolbar-group">
            <button type="button" class="ant-btn ant-btn-sm" onclick="refreshCurrentTab()" title="Muat Ulang Data">
                <i class="fa-solid fa-rotate"></i> Muat Ulang
            </button>
        </div>
    </div>

    <!-- KPI Summary Row -->
    <div class="kpi-row">
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Akun Pajak (PPN/PPh)</h5>
                <h3 id="kpi-tax">-</h3>
            </div>
            <div class="kpi-icon icon-blue"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Klasifikasi Persediaan / HPP</h5>
                <h3 id="kpi-tipe">-</h3>
            </div>
            <div class="kpi-icon icon-green"><i class="fa-solid fa-boxes-stacked"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Valuta Asing (Kurs)</h5>
                <h3 id="kpi-kurs">-</h3>
            </div>
            <div class="kpi-icon icon-amber"><i class="fa-solid fa-money-bill-transfer"></i></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <h5>Rekanan Belum Kode Bantu</h5>
                <h3 id="kpi-blm-bantu">-</h3>
            </div>
            <div class="kpi-icon icon-purple"><i class="fa-solid fa-users-gear"></i></div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="ant-tabs-nav">
        <button type="button" class="ant-tab-btn active" id="tab-btn-tax" onclick="switchTab('tax')">
            <i class="fa-solid fa-receipt"></i> 1. Pajak (PPN & PPh)
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-tipe" onclick="switchTab('tipe')">
            <i class="fa-solid fa-boxes-stacked"></i> 2. Tipe Persediaan & HPP
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-kurs" onclick="switchTab('kurs')">
            <i class="fa-solid fa-money-bill-transfer"></i> 3. Valas & Akun Kurs
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-policy" onclick="switchTab('policy')">
            <i class="fa-solid fa-address-book"></i> 4. Rekanan & Subledger
        </button>
        <button type="button" class="ant-tab-btn" id="tab-btn-rpt" onclick="switchTab('rpt')">
            <i class="fa-solid fa-file-invoice-dollar"></i> 5. Pos Laporan Keuangan
        </button>
    </div>

    <!-- TAB 1: PAJAK (bc_act_tax) -->
    <div id="tab-content-tax">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <span style="font-size:9.5px; font-weight:600; color:var(--text-sub);">
                    Pemetaan Akun Pajak Otomatis (Digunakan pada Faktur Pajak, SI, PI, dan Dokumen Pabean)
                </span>
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn" onclick="exportGridExcel('tblTax', 'Akun_Pajak')">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
            </div>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblTax">
                    <thead>
                        <tr>
                            <th style="width: 45px; text-align: center;">Seq</th>
                            <th style="width: 220px;" data-col="label">Jenis Pajak / Deskripsi</th>
                            <th style="width: 140px;" data-col="akun">Kode Akun COA</th>
                            <th style="width: 280px;" data-col="nama_akun">Nama Akun COA Terhubung</th>
                            <th style="width: 75px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyTax">
                        <tr><td colspan="5" style="text-align: center; padding: 16px;">Memuat data akun pajak...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: TIPE PERSEDIAAN & HPP (bc_act_tipe) -->
    <div id="tab-content-tipe" style="display:none;">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <span style="font-size:9.5px; font-weight:600; color:var(--text-sub);">
                    Pemetaan Akun Jurnal per Klasifikasi Barang (Material, FG, WIP, Capital Goods, Tools, dll)
                </span>
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn" onclick="exportGridExcel('tblTipe', 'Akun_Tipe_Barang')">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
            </div>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblTipe">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 130px;" data-col="tipe">Klasifikasi / Tipe</th>
                            <th style="width: 45px; text-align: center;" data-col="grp">Grup</th>
                            <th style="width: 110px;" data-col="akun_aktiva">Akun Persediaan</th>
                            <th style="width: 110px;" data-col="akun_pembelian">Akun Pembelian</th>
                            <th style="width: 110px;" data-col="akun_pembelian_import">Beli Impor</th>
                            <th style="width: 110px;" data-col="akun_saldo_awal">Saldo Awal</th>
                            <th style="width: 110px;" data-col="akun_saldo_akhir">Saldo Akhir</th>
                            <th style="width: 110px;" data-col="akun_depre">Akun Depresiasi</th>
                            <th style="width: 70px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyTipe">
                        <tr><td colspan="10" style="text-align: center; padding: 16px;">Memuat data klasifikasi barang...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 3: VALAS & AKUN KURS (bc_ref_kurs) -->
    <div id="tab-content-kurs" style="display:none;">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <span style="font-size:9.5px; font-weight:600; color:var(--text-sub);">
                    Pemetaan Akun Transaksi Mata Uang Asing (Hutang AP, Piutang AR, Penjualan Kawasan Berikat & Lokal)
                </span>
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn" onclick="exportGridExcel('tblKurs', 'Akun_Valas')">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
            </div>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblKurs">
                    <thead>
                        <tr>
                            <th style="width: 60px; text-align: center;">Valuta</th>
                            <th style="width: 120px;">Negara</th>
                            <th style="width: 110px;">Akun Hutang (AP)</th>
                            <th style="width: 110px;">Akun Piutang (AR)</th>
                            <th style="width: 110px;">Jual KB (EPTE)</th>
                            <th style="width: 110px;">Jual Non-Fasilitas</th>
                            <th style="width: 100px;">Diskon Jual</th>
                            <th style="width: 100px;">Diskon Beli</th>
                            <th style="width: 100px;">Retur Jual</th>
                            <th style="width: 70px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyKurs">
                        <tr><td colspan="10" style="text-align: center; padding: 16px;">Memuat data valuta asing...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 4: REKANAN & SUBLEDGER (bc_act_config & bc_bs) -->
    <div id="tab-content-policy" style="display:none;">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <label style="font-size:9.5px; font-weight:600; color:var(--text-sub);">Gunakan Akun GL Spesifik Mitra:</label>
                <select id="sel-bs-pakai-akun" class="ant-select" style="width:75px;" onchange="savePolicyConfig()">
                    <option value="Y">Ya (Y)</option>
                    <option value="N">Tidak (N)</option>
                </select>

                <input type="text" id="search-rekanan-cfg" class="ant-input" placeholder="Cari nama atau kode rekanan..." style="width:190px; margin-left:10px;" oninput="filterLocalRekanan()">
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn ant-btn-success" onclick="batchSetAllKodeBantu()">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Set Semua Kode Bantu = Kode Mitra
                </button>
                <button type="button" class="ant-btn" onclick="exportGridExcel('tblRekananCfg', 'Mapping_Rekanan_GL')">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
            </div>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblRekananCfg">
                    <thead>
                        <tr>
                            <th style="width: 35px; text-align: center;">#</th>
                            <th style="width: 85px;" data-col="kode">Kode Mitra</th>
                            <th style="width: 75px; text-align: center;" data-col="tipe">Tipe</th>
                            <th style="width: 260px;" data-col="nama">Nama Rekanan (Buyer / Supplier)</th>
                            <th style="width: 120px;" data-col="akun">Akun Khusus GL</th>
                            <th style="width: 120px;" data-col="kode_bantu">Kode Rekanan Pembantu</th>
                            <th style="width: 70px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyRekananCfg">
                        <tr><td colspan="7" style="text-align: center; padding: 16px;">Memuat data rekanan...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 5: POS LAPORAN KEUANGAN (bc_act_rptgrup) -->
    <div id="tab-content-rpt" style="display:none;">
        <div class="ant-toolbar">
            <div class="toolbar-group">
                <span style="font-size:9.5px; font-weight:600; color:var(--text-sub);">
                    Struktur & Pemetaan Pos Laporan Keuangan (HPP, Laba Rugi, Ekuitas/Neraca)
                </span>
            </div>
            <div class="toolbar-group">
                <button type="button" class="ant-btn" onclick="exportGridExcel('tblRpt', 'Pos_Laporan_Keuangan')">
                    <i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Simpan Excel
                </button>
            </div>
        </div>
        <div class="grid-container">
            <div class="grid-scroll">
                <table class="ant-table" id="tblRpt">
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align: center;">Seq</th>
                            <th style="width: 80px; text-align: center;" data-col="rpt">Laporan</th>
                            <th style="width: 220px;" data-col="pos">Nama Pos Keuangan</th>
                            <th style="width: 240px;" data-col="akun">Daftar Akun COA Terkait</th>
                            <th style="width: 100px;" data-col="klas">Klasifikasi</th>
                            <th style="width: 70px; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyRpt">
                        <tr><td colspan="6" style="text-align: center; padding: 16px;">Memuat pos laporan keuangan...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT TIPE BARANG -->
    <div class="ant-modal-mask" id="modalTipe">
        <div class="ant-modal" style="width: 520px;">
            <div class="ant-modal-header">
                <span><i class="fa-solid fa-boxes-stacked" style="color:var(--primary);"></i> Edit Konfigurasi Klasifikasi Barang</span>
                <span style="cursor:pointer;" onclick="closeModal('modalTipe')"><i class="fa-solid fa-xmark"></i></span>
            </div>
            <div class="ant-modal-body">
                <form id="formTipe" onsubmit="submitFormTipe(event)">
                    <div class="form-row">
                        <label>Klasifikasi / Tipe</label>
                        <input type="text" id="ftipe-tipe" class="ant-input" readonly style="font-weight:600; background:var(--table-head-bg);">
                    </div>
                    <div class="form-row">
                        <label>Grup PB / Kategori</label>
                        <input type="text" id="ftipe-grp" class="ant-input">
                    </div>
                    <div class="form-row">
                        <label>Akun Persediaan (Aktiva)</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="ftipe-aktiva" class="ant-input" placeholder="Kode Akun Persediaan">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('ftipe-aktiva')" title="Pilih dari Daftar COA"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Akun Pembelian Lokal</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="ftipe-beli" class="ant-input" placeholder="Kode Akun Pembelian">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('ftipe-beli')" title="Pilih dari Daftar COA"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Akun Pembelian Impor</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="ftipe-beli-imp" class="ant-input" placeholder="Kode Akun Beli Impor">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('ftipe-beli-imp')" title="Pilih dari Daftar COA"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Akun Saldo Awal</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="ftipe-saldo-awal" class="ant-input" placeholder="Kode Akun Saldo Awal">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('ftipe-saldo-awal')" title="Pilih dari Daftar COA"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Akun Saldo Akhir</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="ftipe-saldo-akhir" class="ant-input" placeholder="Kode Akun Saldo Akhir">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('ftipe-saldo-akhir')" title="Pilih dari Daftar COA"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Akun Depresiasi / Amortisasi</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="ftipe-depre" class="ant-input" placeholder="Kode Akun Depresiasi">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('ftipe-depre')" title="Pilih dari Daftar COA"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="ant-modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalTipe')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitFormTipe(event)"><i class="fa-solid fa-check"></i> Simpan</button>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT KURS VALAS -->
    <div class="ant-modal-mask" id="modalKurs">
        <div class="ant-modal" style="width: 500px;">
            <div class="ant-modal-header">
                <span><i class="fa-solid fa-money-bill-transfer" style="color:var(--primary);"></i> Edit Akun Kurs Valuta Asing</span>
                <span style="cursor:pointer;" onclick="closeModal('modalKurs')"><i class="fa-solid fa-xmark"></i></span>
            </div>
            <div class="ant-modal-body">
                <form id="formKurs" onsubmit="submitFormKurs(event)">
                    <div class="form-row">
                        <label>Kode Valuta</label>
                        <input type="text" id="fkurs-nama" class="ant-input" readonly style="font-weight:600; width:80px; background:var(--table-head-bg);">
                        <label style="width:60px; text-align:right;">Simbol:</label>
                        <input type="text" id="fkurs-simbol" class="ant-input" style="width:60px;">
                    </div>
                    <div class="form-row">
                        <label>Negara / Keterangan</label>
                        <input type="text" id="fkurs-negara" class="ant-input">
                    </div>
                    <div class="form-row">
                        <label>Akun Hutang Usaha (AP)</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="fkurs-hutang" class="ant-input">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('fkurs-hutang')"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Akun Piutang Usaha (AR)</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="fkurs-piutang" class="ant-input">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('fkurs-piutang')"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Penjualan KB (EPTE)</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="fkurs-jual-epte" class="ant-input">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('fkurs-jual-epte')"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Penjualan Non-Fasilitas</label>
                        <div style="display:flex; gap:4px; flex:1;">
                            <input type="text" id="fkurs-jual-non" class="ant-input">
                            <button type="button" class="ant-btn" onclick="openCoaPicker('fkurs-jual-non')"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="ant-modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalKurs')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitFormKurs(event)"><i class="fa-solid fa-check"></i> Simpan</button>
            </div>
        </div>
    </div>

    <!-- MODAL COA PICKER POPUP -->
    <div class="ant-modal-mask" id="modalCoaPicker">
        <div class="ant-modal" style="width: 440px;">
            <div class="ant-modal-header">
                <span><i class="fa-solid fa-list-ol" style="color:var(--primary);"></i> Pilih Akun Chart of Accounts (COA)</span>
                <span style="cursor:pointer;" onclick="closeModal('modalCoaPicker')"><i class="fa-solid fa-xmark"></i></span>
            </div>
            <div class="ant-modal-body">
                <input type="text" id="search-picker-coa" class="ant-input" placeholder="Ketik kode atau nama akun..." style="width:100%; margin-bottom:8px;" oninput="filterCoaPicker()">
                <div style="max-height: 250px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 3px;">
                    <table class="ant-table" id="tblPickerCoa">
                        <thead>
                            <tr>
                                <th style="width: 100px;">Kode Akun</th>
                                <th>Nama Akun</th>
                                <th style="width: 70px; text-align:center;">Tipe</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyPickerCoa">
                            <tr><td colspan="3" style="text-align:center; padding:12px;">Memuat daftar akun...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="ant-modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalCoaPicker')">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        let currentTab = 'tax';
        let rawCoaList = [];
        let rawTaxData = [];
        let rawTipeData = [];
        let rawKursData = [];
        let rawRekananData = [];
        let rawRptData = [];
        let pickerTargetInputId = null;

        const SwalCompact = Swal.mixin({
            customClass: {
                popup: 'swal-compact',
                confirmButton: 'ant-btn ant-btn-primary',
                cancelButton: 'ant-btn'
            },
            buttonsStyling: false
        });

        document.addEventListener('DOMContentLoaded', () => {
            initResizableGrid('tblTax', 'grid_cfg_tax_widths');
            initResizableGrid('tblTipe', 'grid_cfg_tipe_widths');
            initResizableGrid('tblKurs', 'grid_cfg_kurs_widths');
            initResizableGrid('tblRekananCfg', 'grid_cfg_rekanan_widths');
            initResizableGrid('tblRpt', 'grid_cfg_rpt_widths');
            loadKPI();
            loadCoaReference();
            loadTaxData();
        });

        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.ant-tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(`tab-btn-${tab}`).classList.add('active');

            ['tax', 'tipe', 'kurs', 'policy', 'rpt'].forEach(t => {
                document.getElementById(`tab-content-${t}`).style.display = (t === tab) ? 'block' : 'none';
            });

            if (tab === 'tax') loadTaxData();
            else if (tab === 'tipe') loadTipeData();
            else if (tab === 'kurs') loadKursData();
            else if (tab === 'policy') loadPolicyData();
            else if (tab === 'rpt') loadRptData();
        }

        function refreshCurrentTab() {
            loadKPI();
            switchTab(currentTab);
        }

        async function loadKPI() {
            try {
                const res = await fetch('account_config.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-tax').textContent = (json.kpi.tax_count || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-tipe').textContent = (json.kpi.tipe_count || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-kurs').textContent = (json.kpi.kurs_count || 0).toLocaleString('id-ID');
                    document.getElementById('kpi-blm-bantu').textContent = (json.kpi.blm_kode_bantu || 0).toLocaleString('id-ID');
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function loadCoaReference() {
            try {
                const res = await fetch('account_config.php?action=get_coa_ref');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawCoaList = json.data || [];
                }
            } catch (e) {
                console.error(e);
            }
        }

        // TAB 1: PAJAK
        async function loadTaxData() {
            const tbody = document.getElementById('tbodyTax');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px;">Memuat data akun pajak...</td></tr>';
            try {
                const res = await fetch('account_config.php?action=get_tax');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawTaxData = json.data || [];
                    renderTaxTable(rawTaxData);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderTaxTable(rows) {
            const tbody = document.getElementById('tbodyTax');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px;">Tidak ada akun pajak.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${r.seq}</td>
                    <td style="font-weight:600;">${escapeHtml(r.label)}</td>
                    <td style="padding:1px 4px;">
                        <div style="display:flex; gap:3px;">
                            <input type="text" id="tax-akun-${r.seq}" class="ant-input" value="${escapeHtml(r.akun)}" style="font-family:monospace; font-weight:600; color:var(--primary); width:100%;">
                            <button type="button" class="ant-btn ant-btn-sm" onclick="openCoaPicker('tax-akun-${r.seq}')"><i class="fa-solid fa-ellipsis"></i></button>
                        </div>
                    </td>
                    <td><span id="tax-nama-${r.seq}">${escapeHtml(r.nama_akun || '-')}</span></td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="saveTaxRow(${r.seq}, '${escapeHtml(r.label)}')">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan
                        </button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        async function saveTaxRow(seq, label) {
            const akunInput = document.getElementById(`tax-akun-${seq}`);
            const akun = akunInput ? akunInput.value.trim() : '';

            const fd = new FormData();
            fd.append('seq', seq);
            fd.append('label', label);
            fd.append('akun', akun);

            try {
                const res = await fetch('account_config.php?action=save_tax', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
                    loadTaxData();
                    loadKPI();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        // TAB 2: TIPE BARANG
        async function loadTipeData() {
            const tbody = document.getElementById('tbodyTipe');
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:16px;">Memuat data klasifikasi barang...</td></tr>';
            try {
                const res = await fetch('account_config.php?action=get_tipe');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawTipeData = json.data || [];
                    renderTipeTable(rawTipeData);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderTipeTable(rows) {
            const tbody = document.getElementById('tbodyTipe');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:16px;">Tidak ada data tipe barang.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr ondblclick="editTipeByIdx(${idx})">
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; color:var(--primary);">${escapeHtml(r.tipe)}</td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:700; padding:1px 4px; border-radius:3px; background:#f0f0f0;">${escapeHtml(r.grp || '-')}</span></td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_aktiva || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_pembelian || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_pembelian_import || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_saldo_awal || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_saldo_akhir || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_depre || '-')}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="editTipeByIdx(${idx})"><i class="fa-solid fa-pen-to-square"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function editTipeByIdx(idx) {
            const item = rawTipeData[idx];
            if (!item) return;

            document.getElementById('ftipe-tipe').value = item.tipe;
            document.getElementById('ftipe-grp').value = item.grp || '';
            document.getElementById('ftipe-aktiva').value = item.akun_aktiva || '';
            document.getElementById('ftipe-beli').value = item.akun_pembelian || '';
            document.getElementById('ftipe-beli-imp').value = item.akun_pembelian_import || '';
            document.getElementById('ftipe-saldo-awal').value = item.akun_saldo_awal || '';
            document.getElementById('ftipe-saldo-akhir').value = item.akun_saldo_akhir || '';
            document.getElementById('ftipe-depre').value = item.akun_depre || '';

            document.getElementById('modalTipe').style.display = 'flex';
        }

        async function submitFormTipe(e) {
            if (e) e.preventDefault();
            const fd = new FormData();
            fd.append('tipe', document.getElementById('ftipe-tipe').value.trim());
            fd.append('grp', document.getElementById('ftipe-grp').value.trim());
            fd.append('akun_aktiva', document.getElementById('ftipe-aktiva').value.trim());
            fd.append('akun_pembelian', document.getElementById('ftipe-beli').value.trim());
            fd.append('akun_pembelian_import', document.getElementById('ftipe-beli-imp').value.trim());
            fd.append('akun_saldo_awal', document.getElementById('ftipe-saldo-awal').value.trim());
            fd.append('akun_saldo_akhir', document.getElementById('ftipe-saldo-akhir').value.trim());
            fd.append('akun_depre', document.getElementById('ftipe-depre').value.trim());

            try {
                const res = await fetch('account_config.php?action=save_tipe', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalTipe');
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
                    loadTipeData();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        // TAB 3: VALAS KURS
        async function loadKursData() {
            const tbody = document.getElementById('tbodyKurs');
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:16px;">Memuat data valas...</td></tr>';
            try {
                const res = await fetch('account_config.php?action=get_kurs');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawKursData = json.data || [];
                    renderKursTable(rawKursData);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderKursTable(rows) {
            const tbody = document.getElementById('tbodyKurs');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:16px;">Tidak ada mata uang valas.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr ondblclick="editKursByIdx(${idx})">
                    <td style="text-align:center; font-weight:700; color:var(--primary);">${escapeHtml(r.nama)}</td>
                    <td>${escapeHtml(r.negara || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_hutang || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_piutang || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_jual_epte || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_jual_non || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_diskon_jual || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_diskon_beli || '-')}</td>
                    <td style="font-family:monospace;">${escapeHtml(r.akun_retur || '-')}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="editKursByIdx(${idx})"><i class="fa-solid fa-pen-to-square"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function editKursByIdx(idx) {
            const item = rawKursData[idx];
            if (!item) return;

            document.getElementById('fkurs-nama').value = item.nama;
            document.getElementById('fkurs-simbol').value = item.simbol || '';
            document.getElementById('fkurs-negara').value = item.negara || '';
            document.getElementById('fkurs-hutang').value = item.akun_hutang || '';
            document.getElementById('fkurs-piutang').value = item.akun_piutang || '';
            document.getElementById('fkurs-jual-epte').value = item.akun_jual_epte || '';
            document.getElementById('fkurs-jual-non').value = item.akun_jual_non || '';

            document.getElementById('modalKurs').style.display = 'flex';
        }

        async function submitFormKurs(e) {
            if (e) e.preventDefault();
            const fd = new FormData();
            fd.append('nama', document.getElementById('fkurs-nama').value.trim());
            fd.append('simbol', document.getElementById('fkurs-simbol').value.trim());
            fd.append('negara', document.getElementById('fkurs-negara').value.trim());
            fd.append('akun_hutang', document.getElementById('fkurs-hutang').value.trim());
            fd.append('akun_piutang', document.getElementById('fkurs-piutang').value.trim());
            fd.append('akun_jual_epte', document.getElementById('fkurs-jual-epte').value.trim());
            fd.append('akun_jual_non', document.getElementById('fkurs-jual-non').value.trim());

            try {
                const res = await fetch('account_config.php?action=save_kurs', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalKurs');
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message, timer: 1200, showConfirmButton: false });
                    loadKursData();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (err) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: err.message });
            }
        }

        // TAB 4: REKANAN & POLICY
        async function loadPolicyData() {
            const tbody = document.getElementById('tbodyRekananCfg');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:16px;">Memuat data rekanan...</td></tr>';
            try {
                const res = await fetch('account_config.php?action=get_policy');
                const json = await res.json();
                if (json.status === 'OK') {
                    if (json.config) {
                        document.getElementById('sel-bs-pakai-akun').value = json.config.bs_pakai_akun || 'Y';
                    }
                    rawRekananData = json.rekanan || [];
                    renderRekananTable(rawRekananData);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function filterLocalRekanan() {
            const q = document.getElementById('search-rekanan-cfg').value.toLowerCase().trim();
            if (!q) {
                renderRekananTable(rawRekananData);
                return;
            }
            const filtered = rawRekananData.filter(r => 
                (r.kode || '').toLowerCase().includes(q) || 
                (r.nama || '').toLowerCase().includes(q)
            );
            renderRekananTable(filtered);
        }

        function renderRekananTable(rows) {
            const tbody = document.getElementById('tbodyRekananCfg');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:16px;">Tidak ada rekanan.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${idx + 1}</td>
                    <td style="font-weight:600; font-family:monospace; color:var(--primary);">${escapeHtml(r.kode)}</td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:700; padding:1px 4px; border-radius:3px; background:#e6f7ff; color:#096dd9;">${escapeHtml(r.tipe || '-')}</span></td>
                    <td>${escapeHtml(r.nama)}</td>
                    <td style="padding:1px 4px;">
                        <input type="text" id="bs-akun-${r.kode}" class="ant-input" value="${escapeHtml(r.akun || '')}" placeholder="Akun GL" style="font-family:monospace; width:100%;">
                    </td>
                    <td style="padding:1px 4px;">
                        <input type="text" id="bs-bantu-${r.kode}" class="ant-input" value="${escapeHtml(r.kode_bantu || '')}" placeholder="Kode Bantu" style="font-family:monospace; width:100%;">
                    </td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-primary ant-btn-sm" onclick="saveRekananRow('${escapeHtml(r.kode)}')">
                            <i class="fa-solid fa-floppy-disk"></i>
                        </button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        async function savePolicyConfig() {
            const val = document.getElementById('sel-bs-pakai-akun').value;
            const fd = new FormData();
            fd.append('bs_pakai_akun', val);

            try {
                const res = await fetch('account_config.php?action=save_policy_setting', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Tersimpan', text: json.message, timer: 1000, showConfirmButton: false });
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function saveRekananRow(kode) {
            const akunInput = document.getElementById(`bs-akun-${kode}`);
            const bantuInput = document.getElementById(`bs-bantu-${kode}`);

            const fd = new FormData();
            fd.append('kode', kode);
            fd.append('akun', akunInput ? akunInput.value.trim() : '');
            fd.append('kode_bantu', bantuInput ? bantuInput.value.trim() : '');

            try {
                const res = await fetch('account_config.php?action=save_rekanan_akun', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Tersimpan', text: json.message, timer: 1000, showConfirmButton: false });
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        async function batchSetAllKodeBantu() {
            const result = await SwalCompact.fire({
                icon: 'question',
                title: 'Set Kode Bantu',
                text: 'Terapkan Kode Bantu = Kode Mitra secara otomatis untuk semua rekanan yang belum memiliki kode bantu?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Terapkan'
            });
            if (!result.isConfirmed) return;

            try {
                const res = await fetch('account_config.php?action=batch_set_kode_bantu');
                const json = await res.json();
                if (json.status === 'OK') {
                    SwalCompact.fire({ icon: 'success', title: 'Berhasil', text: json.message });
                    loadPolicyData();
                    loadKPI();
                } else {
                    SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (e) {
                SwalCompact.fire({ icon: 'error', title: 'Error', text: e.message });
            }
        }

        // TAB 5: POS LAPORAN KEUANGAN
        async function loadRptData() {
            const tbody = document.getElementById('tbodyRpt');
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:16px;">Memuat pos laporan keuangan...</td></tr>';
            try {
                const res = await fetch('account_config.php?action=get_rpt');
                const json = await res.json();
                if (json.status === 'OK') {
                    rawRptData = json.data || [];
                    renderRptTable(rawRptData);
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:var(--danger); padding:16px;">Error: ${e.message}</td></tr>`;
            }
        }

        function renderRptTable(rows) {
            const tbody = document.getElementById('tbodyRpt');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:16px;">Tidak ada pos laporan keuangan.</td></tr>';
                return;
            }
            let html = '';
            rows.forEach((r, idx) => {
                html += `<tr>
                    <td style="text-align:center; color:var(--text-sub);">${r.seq}</td>
                    <td style="text-align:center; font-weight:700;"><span style="padding:1px 5px; border-radius:3px; background:#fafafa; border:1px solid #d9d9d9;">${escapeHtml(r.rpt)}</span></td>
                    <td style="font-weight:600;">${escapeHtml(r.pos)}</td>
                    <td style="font-family:monospace; font-size:9px;">${escapeHtml(r.akun || '-')}</td>
                    <td>${escapeHtml(r.klas || '-')}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-sm" onclick="editRptRow('${escapeHtml(r.rpt)}', ${r.seq})"><i class="fa-solid fa-pen-to-square"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function editRptRow(rpt, seq) {
            const item = rawRptData.find(x => x.rpt === rpt && parseInt(x.seq) === seq);
            if (!item) return;
            SwalCompact.fire({
                title: `Edit Akun Pos: ${escapeHtml(item.pos)}`,
                input: 'textarea',
                inputValue: item.akun || '',
                inputPlaceholder: 'Daftar kode akun dipisahkan koma...',
                showCancelButton: true,
                confirmButtonText: 'Simpan'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const fd = new FormData();
                    fd.append('rpt', item.rpt);
                    fd.append('seq', item.seq);
                    fd.append('pos', item.pos);
                    fd.append('akun', result.value || '');
                    fd.append('klas', item.klas || '');

                    const res = await fetch('account_config.php?action=save_rpt', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.status === 'OK') {
                        SwalCompact.fire({ icon: 'success', title: 'Tersimpan', text: json.message, timer: 1200, showConfirmButton: false });
                        loadRptData();
                    } else {
                        SwalCompact.fire({ icon: 'error', title: 'Gagal', text: json.message });
                    }
                }
            });
        }

        // COA PICKER MODAL
        function openCoaPicker(targetInputId) {
            pickerTargetInputId = targetInputId;
            document.getElementById('search-picker-coa').value = '';
            renderCoaPickerList(rawCoaList);
            document.getElementById('modalCoaPicker').style.display = 'flex';
            setTimeout(() => document.getElementById('search-picker-coa').focus(), 100);
        }

        function filterCoaPicker() {
            const q = document.getElementById('search-picker-coa').value.toLowerCase().trim();
            if (!q) {
                renderCoaPickerList(rawCoaList);
                return;
            }
            const filtered = rawCoaList.filter(a => 
                (a.kode || '').toLowerCase().includes(q) || 
                (a.nama || '').toLowerCase().includes(q)
            );
            renderCoaPickerList(filtered);
        }

        function renderCoaPickerList(list) {
            const tbody = document.getElementById('tbodyPickerCoa');
            if (!list || list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding:12px;">Akun tidak ditemukan.</td></tr>';
                return;
            }
            let html = '';
            list.slice(0, 80).forEach(a => {
                html += `<tr style="cursor:pointer;" onclick="selectCoa('${escapeHtml(a.kode)}')">
                    <td style="font-family:monospace; font-weight:600; color:var(--primary);">${escapeHtml(a.kode)}</td>
                    <td>${escapeHtml(a.nama)}</td>
                    <td style="text-align:center; font-size:8.5px; color:var(--text-sub);">${escapeHtml(a.tipe || '')}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function selectCoa(kode) {
            if (pickerTargetInputId) {
                const inp = document.getElementById(pickerTargetInputId);
                if (inp) {
                    inp.value = kode;
                    inp.dispatchEvent(new Event('change'));
                }
            }
            closeModal('modalCoaPicker');
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.style.display = 'none';
        }

        // RESIZABLE COLUMNS
        function initResizableGrid(tableId, storageKey) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const ths = table.querySelectorAll('thead th');
            const savedWidths = JSON.parse(localStorage.getItem(storageKey) || '{}');

            ths.forEach((th, i) => {
                const colKey = th.getAttribute('data-col') || `col_${i}`;
                if (savedWidths[colKey]) {
                    th.style.width = savedWidths[colKey] + 'px';
                }

                const resizer = document.createElement('div');
                resizer.className = 'col-resizer';
                th.style.position = 'relative';
                th.appendChild(resizer);

                let startX, startWidth;
                resizer.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    startX = e.pageX;
                    startWidth = th.offsetWidth;
                    resizer.classList.add('resizing');

                    function onMouseMove(e) {
                        const newWidth = Math.max(30, startWidth + (e.pageX - startX));
                        th.style.width = newWidth + 'px';
                    }

                    function onMouseUp() {
                        resizer.classList.remove('resizing');
                        document.removeEventListener('mousemove', onMouseMove);
                        document.removeEventListener('mouseup', onMouseUp);

                        const widths = JSON.parse(localStorage.getItem(storageKey) || '{}');
                        widths[colKey] = th.offsetWidth;
                        localStorage.setItem(storageKey, JSON.stringify(widths));
                    }

                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });
            });
        }

        function exportGridExcel(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const wb = XLSX.utils.table_to_book(table, { sheet: "Sheet1" });
            XLSX.writeFile(wb, `${filename}_${Date.now()}.xlsx`);
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

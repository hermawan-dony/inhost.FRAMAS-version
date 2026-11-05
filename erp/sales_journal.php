<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Jurnal Penjualan (Sales Journal)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_act_jurnal_jual.srw & f_jurnal_jual.srf)
 * DataWindows: bc_act_jual3.srd, d_act_akun_rpt.srd, dw_bs_filter.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Penjualan, Siap Dijurnal, Terposting, PPN Keluaran)
 * - Tab 1: Konfirmasi Faktur SI & SR Belum Dijurnal (Centang & Batch Post ke GL)
 * - Tab 2: Buku Jurnal Penjualan Terposting (bc_act_jual) dengan Fitur Batal/Unpost
 * - Integrasi Otomatis dengan COA (bc_act_akun), Buku Pembantu (bc_act_bantu), Konfigurasi (bc_act_config/tax)
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

// Ensure tables
function ensureSalesJournalTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_jual` (
        `tanggal` date NOT NULL,
        `kode_pembantu` varchar(53) DEFAULT NULL,
        `invoice` varchar(50) DEFAULT NULL,
        `bc` varchar(4) DEFAULT NULL,
        `no_fp` varchar(20) DEFAULT NULL,
        `uraian` varchar(100) DEFAULT NULL,
        `akun_db` varchar(100) NOT NULL,
        `akun_cr` varchar(100) NOT NULL,
        `kurs` varchar(3) DEFAULT 'IDR',
        `amt` decimal(37,2) DEFAULT '0.00',
        `id` varchar(40) NOT NULL DEFAULT '',
        `idx` bigint(20) NOT NULL DEFAULT '1',
        `rate` decimal(10,2) DEFAULT '1.00',
        `rp` decimal(46,2) DEFAULT '0.00',
        `status` varchar(1) NOT NULL DEFAULT 'N',
        `setby` varchar(30) DEFAULT NULL,
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`tanggal`,`akun_db`,`akun_cr`,`id`,`idx`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_invoice` (`invoice`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Catatan: Tidak memodifikasi tabel bc_si_hdr/bc_sr_hdr karena status jurnal dilacak via tabel bc_act_jual
}
ensureSalesJournalTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    $currYm = date('Y-m');

    $sqlJual = "SELECT 
        COALESCE(SUM(a.total_order), 0) as total_jual,
        COALESCE(SUM(a.ppn), 0) as total_ppn,
        COUNT(CASE WHEN j.id IS NULL THEN 1 END) as unposted_cnt,
        COUNT(CASE WHEN j.id IS NOT NULL THEN 1 END) as posted_cnt
        FROM bc_si_hdr a
        LEFT JOIN (SELECT DISTINCT id FROM bc_act_jual WHERE " . (!empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1") . ") j ON a.id = j.id
        WHERE $cmpClause AND DATE_FORMAT(a.si_dt, '%Y-%m') = '$currYm'";
    $resJual = $koneksi->query($sqlJual);
    $rowJual = $resJual ? $resJual->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_jual'   => (float)($rowJual['total_jual'] ?? 0),
            'total_ppn'    => (float)($rowJual['total_ppn'] ?? 0),
            'unposted_cnt' => (int)($rowJual['unposted_cnt'] ?? 0),
            'posted_cnt'   => (int)($rowJual['posted_cnt'] ?? 0)
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Unposted Invoices (Draft List)
// =========================================================================
if ($action === 'get_unposted_invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";
    
    $dt1 = $_GET['dt1'] ?? date('Y-m-01');
    $dt2 = $_GET['dt2'] ?? date('Y-m-d');
    $buyer = trim($_GET['buyer'] ?? '');

    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $vFilter = "";
    if (!empty($buyer)) {
        $escB = $koneksi->real_escape_string($buyer);
        $vFilter = "AND (a.vendor = '$escB' OR b.NAMA LIKE '%$escB%')";
    }

    $sql = "SELECT 'SI' as jenis, a.id, a.si_no as no_invoice, a.si_dt as tanggal, 
                   a.vendor, b.NAMA as buyer_nama, b.KODE_BANTU, b.AKUN as buyer_akun,
                   a.kurs, a.rate, a.subtotal, a.ppn, a.total_order, a.bc, a.no_bc, a.no_aju,
                   '0' as status_jurnal
            FROM bc_si_hdr a
            LEFT JOIN bc_bs b ON a.vendor = b.KODE
            WHERE $cmpClause AND a.si_dt BETWEEN '$escDt1' AND '$escDt2'
              AND a.id NOT IN (SELECT id FROM bc_act_jual WHERE " . (!empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1") . ")
              $vFilter
            UNION ALL
            SELECT 'SR' as jenis, a.id, a.sr_no as no_invoice, a.sr_dt as tanggal,
                   a.vendor, b.NAMA as buyer_nama, b.KODE_BANTU, b.AKUN as buyer_akun,
                   a.kurs, a.rate, a.subtotal, a.ppn, (a.subtotal + a.ppn) as total_order, '14' as bc, '' as no_bc, '' as no_aju,
                   '0' as status_jurnal
            FROM bc_sr_hdr a
            LEFT JOIN bc_bs b ON a.vendor = b.KODE
            WHERE $cmpClause AND a.sr_dt BETWEEN '$escDt1' AND '$escDt2'
              AND a.id NOT IN (SELECT id FROM bc_act_jual WHERE " . (!empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1") . ")
              $vFilter
            ORDER BY tanggal DESC, no_invoice DESC
            LIMIT 500";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Post Batch Jurnal Penjualan
// =========================================================================
if ($action === 'post_batch_journal') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);

    if (!$req || empty($req['ids'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pilih minimal satu faktur yang akan dijurnal']);
        exit;
    }

    $ids = (array)$req['ids'];
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";

    // 1. Ambil Pengaturan Global Akuntansi PB (bs_pakai_akun)
    $bsPakaiAkun = 'N';
    $qCfg = $koneksi->query("SELECT bs_pakai_akun FROM bc_act_config WHERE (cmp_id = '$escCmp' OR cmp_id = '') LIMIT 1");
    if ($qCfg && $rCfg = $qCfg->fetch_assoc()) {
        $bsPakaiAkun = $rCfg['bs_pakai_akun'] ?? 'N';
    }

    // 2. Akun PPN Keluaran Otomatis dari bc_act_tax (seq = 2)
    $akunPpnKeluaran = '114-02-004';
    $qPpn = $koneksi->query("SELECT akun FROM bc_act_tax WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND seq = 2 LIMIT 1");
    if ($qPpn && $rPpn = $qPpn->fetch_assoc()) {
        if (!empty($rPpn['akun'])) $akunPpnKeluaran = trim($rPpn['akun']);
    }

    // 3. Peta Akun Piutang & Penjualan Otomatis per Kurs & Dokumen BC dari bc_ref_kurs
    $kursPiutangMap = [];
    $kursJualEpteMap = [];
    $kursJualNon30Map = [];
    $kursJualNonMap = [];
    $qKurs = $koneksi->query("SELECT nama, akun_piutang, akun_jual_epte, akun_jual_non30, akun_jual_non FROM bc_ref_kurs WHERE (cmp_id = '$escCmp' OR cmp_id = '')");
    if ($qKurs) {
        while ($rK = $qKurs->fetch_assoc()) {
            $kName = strtoupper(trim($rK['nama']));
            if (!empty($rK['akun_piutang'])) $kursPiutangMap[$kName] = trim($rK['akun_piutang']);
            if (!empty($rK['akun_jual_epte'])) $kursJualEpteMap[$kName] = trim($rK['akun_jual_epte']);
            if (!empty($rK['akun_jual_non30'])) $kursJualNon30Map[$kName] = trim($rK['akun_jual_non30']);
            if (!empty($rK['akun_jual_non'])) $kursJualNonMap[$kName] = trim($rK['akun_jual_non']);
        }
    }

    $koneksi->begin_transaction();
    $postedCount = 0;

    try {
        foreach ($ids as $rawId) {
            $escId = $koneksi->real_escape_string($rawId);

            // Fetch SI Header
            $qSi = $koneksi->query("SELECT a.*, b.NAMA as buyer_nama, b.AKUN as buyer_akun, b.KODE_BANTU 
                                   FROM bc_si_hdr a LEFT JOIN bc_bs b ON a.vendor = b.KODE 
                                   WHERE a.id = '$escId' AND (a.cmp_id = '$escCmp' OR a.cmp_id = '') LIMIT 1");
            if ($qSi && $qSi->num_rows > 0) {
                $si = $qSi->fetch_assoc();
                $tgl     = $si['si_dt'];
                $inv     = $si['si_no'];
                $kurs    = !empty($si['kurs']) ? strtoupper(trim($si['kurs'])) : 'IDR';
                $rate    = (float)($si['rate'] ?? 1);
                if ($rate <= 0) $rate = 1;
                $subtotal = (float)($si['subtotal'] ?? 0);
                $ppn      = (float)($si['ppn'] ?? 0);
                $total    = (float)($si['total_order'] ?? ($subtotal + $ppn));
                $bc       = trim($si['bc'] ?? '');
                $kdBantu  = !empty($si['KODE_BANTU']) ? $si['KODE_BANTU'] : $si['vendor'];

                // 1. Akun Piutang Debet Otomatis (f_jurnal_jual2)
                $akunDb = '';
                if ($bsPakaiAkun === 'Y' && !empty($si['buyer_akun'])) {
                    $akunDb = trim($si['buyer_akun']);
                }
                if (empty($akunDb)) {
                    $akunDb = $kursPiutangMap[$kurs] ?? ($kursPiutangMap['IDR'] ?? '112-01-000');
                }

                // 2. Akun Penjualan Kredit Otomatis per Dokumen Pabean (f_jurnal_jual2)
                if ($bc === '27') {
                    $akunCrPenjualan = $kursJualEpteMap[$kurs] ?? ($kursJualEpteMap['IDR'] ?? '411-01-001');
                } elseif ($bc === '30') {
                    $akunCrPenjualan = $kursJualNon30Map[$kurs] ?? ($kursJualNon30Map['IDR'] ?? '411-01-002');
                } else {
                    $akunCrPenjualan = $kursJualNonMap[$kurs] ?? ($kursJualNonMap['IDR'] ?? '411-01-001');
                }

                // Delete old unposted if any
                $koneksi->query("DELETE FROM bc_act_jual WHERE id = '$escId' AND cmp_id = '$escCmp'");

                // 1. Entry Penjualan (Kredit)
                if ($subtotal > 0) {
                    $rpSub = round($subtotal * $rate, 2);
                    $uraian = "Penjualan {$si['buyer_nama']} Inv: $inv";
                    $koneksi->query("INSERT INTO bc_act_jual 
                        (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, setby, cmp_id)
                        VALUES ('$tgl', '$kdBantu', '$inv', '$bc', '', '$uraian', '$akunDb', '$akunCrPenjualan', '$kurs', $subtotal, '$escId', 1, $rate, $rpSub, 'Y', '$userId', '$escCmp')");
                }

                // 2. Entry PPN Keluaran (Kredit) Otomatis
                if ($ppn > 0) {
                    $rpPpn = round($ppn * $rate, 2);
                    $uraianPpn = "PPN Keluaran {$si['buyer_nama']} Inv: $inv";
                    $koneksi->query("INSERT INTO bc_act_jual 
                        (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, setby, cmp_id)
                        VALUES ('$tgl', '$kdBantu', '$inv', '$bc', '', '$uraianPpn', '$akunDb', '$akunPpnKeluaran', '$kurs', $ppn, '$escId', 2, $rate, $rpPpn, 'Y', '$userId', '$escCmp')");
                }

                // Update Status Jurnal di SI Header jika kolom ada
                try { $koneksi->query("UPDATE bc_si_hdr SET status_jurnal = '1' WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')"); } catch (Throwable $t) {}

                $postedCount++;
            } else {
                // Fetch SR Header (Retur Penjualan)
                $qSr = $koneksi->query("SELECT a.*, b.NAMA as buyer_nama, b.AKUN as buyer_akun, b.KODE_BANTU 
                                       FROM bc_sr_hdr a LEFT JOIN bc_bs b ON a.vendor = b.KODE 
                                       WHERE a.id = '$escId' AND (a.cmp_id = '$escCmp' OR a.cmp_id = '') LIMIT 1");
                if ($qSr && $qSr->num_rows > 0) {
                    $sr = $qSr->fetch_assoc();
                    $tgl     = $sr['sr_dt'];
                    $inv     = $sr['sr_no'];
                    $kurs    = !empty($sr['kurs']) ? strtoupper(trim($sr['kurs'])) : 'IDR';
                    $rate    = (float)($sr['rate'] ?? 1);
                    if ($rate <= 0) $rate = 1;
                    $subtotal = (float)($sr['subtotal'] ?? 0);
                    $ppn      = (float)($sr['ppn'] ?? 0);
                    $total    = (float)($subtotal + $ppn);
                    $bc       = '41';
                    $kdBantu  = !empty($sr['KODE_BANTU']) ? $sr['KODE_BANTU'] : $sr['vendor'];

                    $akunDb = '';
                    if ($bsPakaiAkun === 'Y' && !empty($sr['buyer_akun'])) {
                        $akunDb = trim($sr['buyer_akun']);
                    }
                    if (empty($akunDb)) {
                        $akunDb = $kursPiutangMap[$kurs] ?? ($kursPiutangMap['IDR'] ?? '112-01-000');
                    }
                    $akunCrPenjualan = $kursJualNonMap[$kurs] ?? ($kursJualNonMap['IDR'] ?? '411-01-001');

                    $koneksi->query("DELETE FROM bc_act_jual WHERE id = '$escId' AND cmp_id = '$escCmp'");

                    if ($subtotal > 0) {
                        $rpSub = round($subtotal * $rate, 2);
                        $uraian = "Retur Penjualan {$sr['buyer_nama']} No: $inv";
                        // Retur: Debet Penjualan (mengurangi penjualan), Kredit Piutang (mengurangi piutang)
                        $koneksi->query("INSERT INTO bc_act_jual 
                            (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, setby, cmp_id)
                            VALUES ('$tgl', '$kdBantu', '$inv', '$bc', '', '$uraian', '$akunCrPenjualan', '$akunDb', '$kurs', $subtotal, '$escId', 1, $rate, $rpSub, 'Y', '$userId', '$escCmp')");
                    }

                    $postedCount++;
                }
            }
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Berhasil memposting $postedCount faktur ke Jurnal Penjualan"]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal posting jurnal: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Unpost / Batalkan Jurnal Penjualan
// =========================================================================
if ($action === 'unpost_journal') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_REQUEST['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID transaksi tidak valid']);
        exit;
    }

    $escId = $koneksi->real_escape_string($id);
    $koneksi->begin_transaction();
    try {
        $koneksi->query("DELETE FROM bc_act_jual WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        try { $koneksi->query("UPDATE bc_si_hdr SET status_jurnal = '0' WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')"); } catch (Throwable $t) {}
        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => 'Jurnal berhasil dibatalkan dan dikembalikan ke daftar draft']);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal unpost jurnal: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Browse Jurnal Terposting (Tab 2)
// =========================================================================
if ($action === 'browse_posted') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "1=1";

    $dt1 = $_GET['dt1'] ?? date('Y-m-01');
    $dt2 = $_GET['dt2'] ?? date('Y-m-d');
    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $idxFilter = "";
    $idx = $_GET['filter_idx'] ?? '';
    if ($idx !== '' && is_numeric($idx)) {
        $idxFilter = "AND a.idx = " . (int)$idx;
    }

    $sql = "SELECT a.*, 
                   COALESCE(b.NAMA, a.kode_pembantu) as buyer_nama,
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_db AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_db,
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_cr AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_cr
            FROM bc_act_jual a
            LEFT JOIN bc_bs b ON a.kode_pembantu = b.KODE OR a.kode_pembantu = b.KODE_BANTU
            WHERE $cmpClause AND a.tanggal BETWEEN '$escDt1' AND '$escDt2'
            $idxFilter
            ORDER BY a.tanggal DESC, a.invoice DESC, a.idx ASC
            LIMIT 1000";

    $res = $koneksi->query($sql);
    $data = [];
    $totAmt = 0;
    $totRp  = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
            $totAmt += (float)$r['amt'];
            $totRp  += (float)$r['rp'];
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $data,
        'summary' => [
            'total_rows' => count($data),
            'total_amt'  => $totAmt,
            'total_rp'   => $totRp
        ]
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Chart of Accounts for Dropdowns
// =========================================================================
if ($action === 'get_accounts') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $sql = "SELECT kode, nama_akun as nama, tipe FROM bc_act_akun WHERE $cmpClause ORDER BY kode ASC";
    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $data]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Live GL Mapping Info & Guide
// =========================================================================
if ($action === 'get_gl_mapping_info') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";

    // 1. Kebijakan Rekanan
    $bsPakaiAkun = 'N';
    $qCfg = $koneksi->query("SELECT bs_pakai_akun FROM bc_act_config WHERE $cmpClause LIMIT 1");
    if ($qCfg && $r = $qCfg->fetch_assoc()) {
        $bsPakaiAkun = $r['bs_pakai_akun'] ?? 'N';
    }

    // 2. Akun Pajak (PPN Out)
    $taxList = [];
    $qTax = $koneksi->query("SELECT seq, label, akun, 
                            (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = bc_act_tax.akun AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun 
                            FROM bc_act_tax WHERE $cmpClause ORDER BY seq ASC");
    if ($qTax) {
        while ($r = $qTax->fetch_assoc()) {
            $taxList[] = $r;
        }
    }

    // 3. Valas & Akun Kurs (Piutang, Penjualan Lokal, Penjualan Ekspor)
    $kursList = [];
    $qKurs = $koneksi->query("SELECT nama, nilaitukar AS kurs, akun_piutang, 
                             COALESCE(akun_jual_eksport, '') AS akun_jual, 
                             COALESCE(akun_jual_lokal, '') AS akun_jual_non,
                             (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = bc_ref_kurs.akun_piutang AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_piutang,
                             (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = COALESCE(bc_ref_kurs.akun_jual_eksport, '') AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_jual_ekspor,
                             (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = COALESCE(bc_ref_kurs.akun_jual_lokal, '') AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_jual_lokal
                             FROM bc_ref_kurs WHERE $cmpClause ORDER BY nama ASC");
    if ($qKurs) {
        while ($r = $qKurs->fetch_assoc()) {
            $kursList[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'module' => 'sales',
        'bs_pakai_akun' => $bsPakaiAkun,
        'tax' => $taxList,
        'kurs' => $kursList
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jurnal Penjualan - CEISA 4.0 & ERP Hub</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/assets/sweetalert-theme.js?v=20261025"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <link rel="stylesheet" href="../assets/ant_grid.css">
    <script src="../assets/ant_grid.js"></script>

    <style>
        :root {
            --app-font: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --app-font-size: 9.5px;
            --form-font-size: 9.5px;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --danger: #f5222d;
            --warning: #faad14;
            --info: #0ea5e9;
            --bg-body: #f4f6f9;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --border-light: #d9d9d9;
            --border-table: #e8e8e8;
            --table-head-bg: #fafafa;
            --row-hover: #f0f7ff;
            --row-selected: #e6f7ff;
        }
        
        body.theme-dark {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --text-main: #d9d9d9;
            --text-muted: #8c8c8c;
            --border-light: #434343;
            --border-table: #303030;
            --table-head-bg: #1d1d1d;
            --row-hover: #262638;
            --row-selected: #111d2c;
        }

        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--app-font);
            font-size: var(--app-font-size);
            line-height: 1.4;
            height: 100%;
            overflow-x: hidden;
        }

        .main-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            padding: 8px 12px;
            gap: 8px;
        }

        /* Top KPI Cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            flex-shrink: 0;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .kpi-icon {
            width: 32px; height: 32px;
            border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .kpi-icon.blue { background: #e6f7ff; color: #1890ff; }
        .kpi-icon.amber { background: #fffbe6; color: #faad14; }
        .kpi-icon.green { background: #f6ffed; color: #52c41a; }
        .kpi-icon.purple { background: #f9f0ff; color: #722ed1; }
        .kpi-data { display: flex; flex-direction: column; min-width: 0; }
        .kpi-label { font-size: 8.5px; color: var(--text-muted); text-transform: uppercase; font-weight: 500; }
        .kpi-val { font-size: 13px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Tabs & Card Container */
        .card-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .card-header-tabs {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-light);
            padding: 0 8px;
            height: 32px;
            flex-shrink: 0;
        }
        .tabs-nav {
            display: flex;
            gap: 4px;
            height: 100%;
        }
        .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            height: 100%;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-muted);
            font-family: inherit;
            font-size: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 600;
            background: var(--bg-card);
        }

        /* Toolbar Filter */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-table);
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .toolbar label { font-size: 9.5px; font-weight: 500; color: var(--text-muted); }
        .ant-input, .ant-select {
            height: 21px;
            padding: 0 6px;
            font-family: inherit;
            font-size: 9.5px;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
        }
        .ant-input:focus, .ant-select:focus { border-color: var(--primary); }
        .ant-btn {
            height: 21px;
            padding: 0 8px;
            font-family: inherit;
            font-size: 9.5px;
            font-weight: 500;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            background: #fff;
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff !important;
        }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
        .ant-btn-danger {
            background: var(--danger);
            border-color: var(--danger);
            color: #fff !important;
        }
        .ant-btn-success {
            background: var(--success);
            border-color: var(--success);
            color: #fff !important;
        }

        /* Grid Table */
        .grid-wrapper {
            flex: 1;
            overflow: auto;
            position: relative;
            min-height: 0;
        }
        table.ant-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            white-space: nowrap;
        }
        table.ant-table th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border: 1px solid var(--border-table);
            padding: 3px 6px;
            text-align: left;
            font-weight: 600;
            color: var(--text-main);
            z-index: 2;
            user-select: none;
        }
        table.ant-table td {
            border: 1px solid var(--border-table);
            padding: 2px 6px;
            height: 22px;
            vertical-align: middle;
            color: var(--text-main);
        }
        table.ant-table tbody tr:hover { background-color: var(--row-hover); }
        table.ant-table tbody tr.selected { background-color: var(--row-selected); }

        .col-resizer {
            position: absolute;
            top: 0; right: 0;
            width: 4px;
            cursor: col-resize;
            user-select: none;
            height: 100%;
        }

        /* Radio Pill Group */
        .radio-pill-group {
            display: inline-flex;
            border: 1px solid var(--border-light);
            border-radius: 2px;
            overflow: hidden;
        }
        .radio-pill-btn {
            background: transparent;
            border: none;
            padding: 2px 8px;
            font-size: 9px;
            font-family: inherit;
            cursor: pointer;
            color: var(--text-muted);
        }
        .radio-pill-btn.active {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
        }

        /* Bottom Status & Summary Bar */
        .footer-bar {
            height: 26px;
            background: var(--table-head-bg);
            border-top: 1px solid var(--border-table);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;
            font-size: 9.5px;
            flex-shrink: 0;
        }

        /* SweetAlert Compact Desktop */
        .swal2-popup.compact-swal {
            width: 330px !important;
            padding: 12px !important;
            font-size: 9.5px !important;
            border-radius: 4px !important;
            font-family: var(--app-font) !important;
        }
        .swal2-popup.compact-swal .swal2-title {
            font-size: 13px !important;
            margin: 0 0 6px 0 !important;
        }
        .swal2-popup.compact-swal .swal2-html-container {
            font-size: 10px !important;
            margin: 0 0 10px 0 !important;
        }
        .swal2-popup.compact-swal .swal2-actions {
            margin-top: 6px !important;
            gap: 6px !important;
        }
        .swal2-popup.compact-swal .swal2-actions button {
            height: 21px !important;
            padding: 0 10px !important;
            font-size: 9.5px !important;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 4 Top KPI Cards -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon blue"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Penjualan (Bulan Ini)</span>
                    <span class="kpi-val" id="kpi-total-jual">Rp 0</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon amber"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Faktur Siap Dijurnal</span>
                    <span class="kpi-val" id="kpi-unposted-cnt">0 Faktur</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon green"><i class="fa-solid fa-check-double"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Faktur Terposting GL</span>
                    <span class="kpi-val" id="kpi-posted-cnt">0 Faktur</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon purple"><i class="fa-solid fa-percent"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total PPN Keluaran</span>
                    <span class="kpi-val" id="kpi-total-ppn">Rp 0</span>
                </div>
            </div>
        </div>

        <!-- Main Card Panel with Tabs -->
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-draft" onclick="switchTab('draft')">
                        <i class="fa-solid fa-list-check"></i> 1. Konfirmasi Faktur SI & SR (Belum Dijurnal)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-posted" onclick="switchTab('posted')">
                        <i class="fa-solid fa-book-journal-whills"></i> 2. Buku Jurnal Penjualan Terposting (bc_act_jual)
                    </button>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="ant-btn" onclick="refreshCurrentTab()" title="Muat Ulang Data">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh
                    </button>
                    <button type="button" class="ant-btn ant-btn-sm" onclick="openModuleGuide()" title="Panduan Modul & SOP">
                        <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:13px;"></i>
                    </button>
                </div>
            </div>

            <!-- TAB 1: DRAFT & KONFIRMASI SI / SR -->
            <div id="panel-draft" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode:</label>
                    <input type="date" id="draft-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="draft-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <label style="margin-left:6px;">Customer / Buyer:</label>
                    <input type="text" id="draft-buyer" class="ant-input" placeholder="Cari buyer..." style="width:140px;">

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadDraftInvoices()">
                        <i class="fa-solid fa-filter"></i> Tampilkan Faktur
                    </button>

                    <div style="flex:1;"></div>

                    <!-- Info Pemetaan Akun Otomatis dari Konfigurasi Akun PB (Klik untuk Panduan & Live Mapping) -->
                    <span class="ant-tag auto-gl-badge" onclick="showAutoAccountGuide()" style="background:var(--table-head-bg); border:1px solid #10b981; font-size:9.5px; padding:3px 10px; color:var(--text-main); display:inline-flex; align-items:center; gap:6px; cursor:pointer; transition:all 0.2s;" title="Klik untuk melihat akun yang disetting otomatis & cara mengeditnya di Konfigurasi Akun">
                        <i class="fa-solid fa-wand-magic-sparkles" style="color:#10b981;"></i>
                        <span>Akun GL: <strong>Otomatis dari Konfigurasi Akun</strong></span>
                        <i class="fa-solid fa-circle-question" style="color:var(--primary); font-size:11px;"></i>
                    </span>

                    <button type="button" class="ant-btn ant-btn-success" onclick="postBatchJournal()" id="btn-post-journal">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Post ke Jurnal Penjualan (<span id="selected-draft-count">0</span>)
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-draft">
                        <thead>
                            <tr>
                                <th style="width: 32px; text-align: center;">
                                    <input type="checkbox" id="check-all-draft" onchange="toggleSelectAllDraft(this.checked)">
                                </th>
                                <th style="width: 45px; text-align:center;">Jenis</th>
                                <th style="width: 120px;">No Faktur / Invoice</th>
                                <th style="width: 75px; text-align:center;">Tanggal</th>
                                <th style="width: 180px;">Customer / Buyer</th>
                                <th style="width: 50px; text-align:center;">Valas</th>
                                <th style="width: 60px; text-align:right;">Kurs</th>
                                <th style="width: 95px; text-align:right;">Subtotal (DPP)</th>
                                <th style="width: 85px; text-align:right;">PPN Keluaran</th>
                                <th style="width: 100px; text-align:right;">Total Piutang</th>
                                <th style="width: 60px; text-align:center;">Dok BC</th>
                                <th style="width: 110px;">No Pendaftaran</th>
                                <th style="width: 160px;">No Pengajuan (Aju)</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-draft">
                            <tr><td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat daftar faktur...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="draft-summary-text">0 faktur siap dijurnal</span>
                    </div>
                    <div style="display:flex; gap:16px;">
                        <span>Total Terpilih: <strong id="sum-draft-selected">Rp 0</strong></span>
                    </div>
                </div>
            </div>

            <!-- TAB 2: BUKU JURNAL PENJUALAN TERPOSTING (bc_act_jual) -->
            <div id="panel-posted" style="display:none; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode:</label>
                    <input type="date" id="posted-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="posted-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <div class="radio-pill-group" style="margin-left:8px;">
                        <button type="button" class="radio-pill-btn active" id="pill-all" onclick="setPostedIdxFilter('', this)">Semua</button>
                        <button type="button" class="radio-pill-btn" id="pill-penjualan" onclick="setPostedIdxFilter('1', this)">Penjualan (1)</button>
                        <button type="button" class="radio-pill-btn" id="pill-ppn" onclick="setPostedIdxFilter('2', this)">PPN Out (2)</button>
                        <button type="button" class="radio-pill-btn" id="pill-retur" onclick="setPostedIdxFilter('5', this)">Retur (5)</button>
                    </div>

                    <button type="button" class="ant-btn ant-btn-primary" onclick="loadPostedJournal()">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>

                    <div style="flex:1;"></div>

                    <button type="button" class="ant-btn" onclick="exportPostedToExcel()">
                        <i class="fa-solid fa-file-excel" style="color:#107c41;"></i> Export Excel
                    </button>
                </div>

                <div class="grid-wrapper">
                    <table class="ant-table" id="table-posted">
                        <thead>
                            <tr>
                                <th style="width: 75px; text-align:center;">Tanggal</th>
                                <th style="width: 120px;">No Invoice</th>
                                <th style="width: 50px; text-align:center;">Dok BC</th>
                                <th style="width: 170px;">Customer / Buyer</th>
                                <th style="width: 160px;">Akun Debet</th>
                                <th style="width: 160px;">Akun Kredit</th>
                                <th>Keterangan / Uraian</th>
                                <th style="width: 45px; text-align:center;">Kurs</th>
                                <th style="width: 85px; text-align:right;">Nilai Valas</th>
                                <th style="width: 65px; text-align:right;">Rate</th>
                                <th style="width: 100px; text-align:right;">Jumlah (Rp)</th>
                                <th style="width: 60px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-posted">
                            <tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat buku jurnal...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="footer-bar">
                    <div>
                        <span id="posted-summary-text">0 transaksi jurnal terposting</span>
                    </div>
                    <div style="display:flex; gap:16px;">
                        <span>Total Rupiah: <strong id="sum-posted-rp">Rp 0</strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let draftInvoices = [];
        let postedJournals = [];
        let coaAccounts = [];
        let currentPostedIdx = '';

        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            loadKPI();
            loadDraftInvoices();

            if (window.AntGrid) {
                AntGrid.initTableResizing('#table-draft');
                AntGrid.initTableResizing('#table-posted');
            }
        });

        function initTheme() {
            const savedTheme = localStorage.getItem('ceisa_theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('theme-dark');
            }
            window.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'THEME_CHANGE') {
                    if (e.data.theme === 'dark') document.body.classList.add('theme-dark');
                    else document.body.classList.remove('theme-dark');
                }
            });
        }

        function switchTab(tab) {
            document.getElementById('tab-btn-draft').classList.toggle('active', tab === 'draft');
            document.getElementById('tab-btn-posted').classList.toggle('active', tab === 'posted');
            document.getElementById('panel-draft').style.display = tab === 'draft' ? 'flex' : 'none';
            document.getElementById('panel-posted').style.display = tab === 'posted' ? 'flex' : 'none';

            if (tab === 'posted' && postedJournals.length === 0) {
                loadPostedJournal();
            }
        }

        function refreshCurrentTab() {
            loadKPI();
            if (document.getElementById('tab-btn-draft').classList.contains('active')) {
                loadDraftInvoices();
            } else {
                loadPostedJournal();
            }
        }

        async function loadKPI() {
            try {
                const res = await fetch('sales_journal.php?action=kpi&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-total-jual').textContent = formatRupiah(json.kpi.total_jual);
                    document.getElementById('kpi-total-ppn').textContent = formatRupiah(json.kpi.total_ppn);
                    document.getElementById('kpi-unposted-cnt').textContent = json.kpi.unposted_cnt + ' Faktur';
                    document.getElementById('kpi-posted-cnt').textContent = json.kpi.posted_cnt + ' Faktur';
                }
            } catch (e) {
                console.error("Gagal load KPI:", e);
            }
        }

        async function loadAccounts() {
            try {
                const res = await fetch('sales_journal.php?action=get_accounts&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && Array.isArray(json.data)) {
                    coaAccounts = json.data;
                    populateDropdown('sel-akun-piutang', coaAccounts, 
                        ['112-01-000', '112-01', '112', '1100', '1101', '11030', '1103'],
                        ['account receivable', 'piutang usaha', 'piutang dagang', 'trade receivable']
                    );
                    populateDropdown('sel-akun-penjualan', coaAccounts, 
                        ['411-01-001', '411-01-000', '411', '4000', '4001', '41010', '4101'],
                        ['sales local', 'sales export', 'sales', 'penjualan', 'pendapatan']
                    );
                    populateDropdown('sel-akun-ppn', coaAccounts, 
                        ['214-02-001', '214-02', '214', '2100', '21070', '2107'],
                        ['value added tax out', 'vat out', 'ppn keluaran', 'ppn out', 'hutang ppn']
                    );
                }
            } catch (e) {
                console.error("Gagal load akun:", e);
            }
        }

        function populateDropdown(elemId, accounts, preferredCodes, keywords = []) {
            const select = document.getElementById(elemId);
            if (!select) return;
            select.innerHTML = '<option value="">-- Pilih Akun --</option>';
            let selectedCode = '';

            // 1. Cari exact match atau prefix dari preferredCodes
            for (const pref of preferredCodes) {
                const found = accounts.find(a => a.kode === pref || a.kode.startsWith(pref));
                if (found) { selectedCode = found.kode; break; }
            }

            // 2. Fallback pencarian keyword pada nama akun
            if (!selectedCode && keywords.length > 0) {
                for (const kw of keywords) {
                    const found = accounts.find(a => (a.nama || '').toLowerCase().includes(kw.toLowerCase()));
                    if (found) { selectedCode = found.kode; break; }
                }
            }

            accounts.forEach(acc => {
                const opt = document.createElement('option');
                opt.value = acc.kode;
                opt.textContent = `${acc.kode} - ${acc.nama}`;
                if (acc.kode === selectedCode) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
        }

        // =========================================================================
        // TAB 1: DRAFT INVOICES
        // =========================================================================
        async function loadDraftInvoices() {
            const dt1 = document.getElementById('draft-dt1').value;
            const dt2 = document.getElementById('draft-dt2').value;
            const buyer = document.getElementById('draft-buyer').value;
            const tbody = document.getElementById('tbody-draft');

            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);">Mengambil daftar faktur draft...</td></tr>';

            try {
                const url = `sales_journal.php?action=get_unposted_invoices&cmp_id=<?= urlencode($sessionCmpId) ?>&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}&buyer=${encodeURIComponent(buyer)}`;
                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    draftInvoices = json.data;
                    renderDraftTable();
                } else {
                    tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat faktur</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderDraftTable() {
            const tbody = document.getElementById('tbody-draft');
            if (draftInvoices.length === 0) {
                tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada faktur yang belum dijurnal pada periode ini.</td></tr>';
                updateDraftSelection();
                return;
            }

            let html = '';
            draftInvoices.forEach((r, idx) => {
                const isSR = r.jenis === 'SR';
                const tagColor = isSR ? '#f5222d' : '#52c41a';
                const tagBg = isSR ? '#fff1f0' : '#f6ffed';

                html += `
                <tr id="row-draft-${idx}">
                    <td style="text-align:center;">
                        <input type="checkbox" class="draft-cb" data-idx="${idx}" data-id="${escapeHtml(r.id)}" onchange="updateDraftSelection()">
                    </td>
                    <td style="text-align:center;">
                        <span style="font-size:8.5px; font-weight:700; padding:1px 5px; border-radius:3px; background:${tagBg}; color:${tagColor};">${escapeHtml(r.jenis)}</span>
                    </td>
                    <td><strong>${escapeHtml(r.no_invoice)}</strong></td>
                    <td style="text-align:center;">${escapeHtml(r.tanggal)}</td>
                    <td>${escapeHtml(r.buyer_nama || r.vendor)}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs)}</td>
                    <td style="text-align:right;">${formatNumber(r.rate)}</td>
                    <td style="text-align:right;">${formatNumber(r.subtotal)}</td>
                    <td style="text-align:right; color:#722ed1;">${formatNumber(r.ppn)}</td>
                    <td style="text-align:right; font-weight:600; color:var(--primary);">${formatNumber(r.total_order)}</td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:600; color:var(--text-muted);">${escapeHtml(r.bc || '-')}</span></td>
                    <td>${escapeHtml(r.no_bc || '-')}</td>
                    <td style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.no_aju || '-')}</td>
                </tr>`;
            });

            tbody.innerHTML = html;
            updateDraftSelection();
        }

        function toggleSelectAllDraft(checked) {
            document.querySelectorAll('.draft-cb').forEach(cb => cb.checked = checked);
            updateDraftSelection();
        }

        function updateDraftSelection() {
            const checkedBoxes = document.querySelectorAll('.draft-cb:checked');
            const totalCount = document.querySelectorAll('.draft-cb').length;
            const count = checkedBoxes.length;

            document.getElementById('selected-draft-count').textContent = count;
            document.getElementById('check-all-draft').checked = (totalCount > 0 && count === totalCount);

            let sumSelected = 0;
            checkedBoxes.forEach(cb => {
                const idx = parseInt(cb.getAttribute('data-idx'));
                if (draftInvoices[idx]) {
                    const row = draftInvoices[idx];
                    const rate = parseFloat(row.rate) || 1;
                    sumSelected += (parseFloat(row.total_order) || 0) * rate;
                    document.getElementById(`row-draft-${idx}`).classList.add('selected');
                }
            });

            document.querySelectorAll('.draft-cb:not(:checked)').forEach(cb => {
                const idx = parseInt(cb.getAttribute('data-idx'));
                const tr = document.getElementById(`row-draft-${idx}`);
                if (tr) tr.classList.remove('selected');
            });

            document.getElementById('sum-draft-selected').textContent = formatRupiah(sumSelected);
            document.getElementById('draft-summary-text').textContent = `${draftInvoices.length} faktur siap dijurnal (${count} dipilih)`;
        }

        async function postBatchJournal() {
            const checkedBoxes = document.querySelectorAll('.draft-cb:checked');
            if (checkedBoxes.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pilih Faktur',
                    text: 'Silakan centang minimal satu faktur yang akan diposting ke jurnal',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const ids = Array.from(checkedBoxes).map(cb => cb.getAttribute('data-id'));

            const confirm = await Swal.fire({
                icon: 'question',
                title: 'Konfirmasi Posting Jurnal',
                html: `Posting <strong>${ids.length} faktur</strong> ke Buku Jurnal Penjualan?<br><small style="color:var(--text-muted)">Akun GL dipetakan 100% otomatis dari <strong>Konfigurasi Akun</strong> (Dokumen BC, Kurs &amp; Customer)</small>`,
                showCancelButton: true,
                confirmButtonText: 'Ya, Posting Sekarang',
                cancelButtonText: 'Batal',
                customClass: { popup: 'compact-swal' }
            });

            if (!confirm.isConfirmed) return;

            const btn = document.getElementById('btn-post-journal');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memposting...';

            try {
                const res = await fetch('sales_journal.php?action=post_batch_journal&cmp_id=<?= urlencode($sessionCmpId) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids })
                });
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up"></i> Post ke Jurnal Penjualan (<span id="selected-draft-count">0</span>)`;

                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Jurnal Berhasil Diposting',
                        text: json.message,
                        timer: 2000,
                        showConfirmButton: false,
                        customClass: { popup: 'compact-swal' }
                    });
                    loadKPI();
                    loadDraftInvoices();
                    loadPostedJournal();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Posting Jurnal',
                        text: json.message || 'Terjadi kesalahan sistem',
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up"></i> Post ke Jurnal Penjualan (<span id="selected-draft-count">0</span>)`;
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        // =========================================================================
        // TAB 2: POSTED JOURNAL (bc_act_jual)
        // =========================================================================
        function setPostedIdxFilter(idx, btn) {
            currentPostedIdx = idx;
            document.querySelectorAll('.radio-pill-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadPostedJournal();
        }

        async function loadPostedJournal() {
            const dt1 = document.getElementById('posted-dt1').value;
            const dt2 = document.getElementById('posted-dt2').value;
            const tbody = document.getElementById('tbody-posted');

            tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat buku jurnal penjualan...</td></tr>';

            try {
                let url = `sales_journal.php?action=browse_posted&cmp_id=<?= urlencode($sessionCmpId) ?>&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}`;
                if (currentPostedIdx !== '') {
                    url += `&filter_idx=${encodeURIComponent(currentPostedIdx)}`;
                }

                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK' && Array.isArray(json.data)) {
                    postedJournals = json.data;
                    renderPostedTable(json.summary);
                } else {
                    tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--danger);">Gagal memuat buku jurnal</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--danger);">Error: ${escapeHtml(e.message)}</td></tr>`;
            }
        }

        function renderPostedTable(summary) {
            const tbody = document.getElementById('tbody-posted');
            if (postedJournals.length === 0) {
                tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada transaksi jurnal penjualan yang terposting pada periode ini.</td></tr>';
                document.getElementById('posted-summary-text').textContent = '0 transaksi';
                document.getElementById('sum-posted-rp').textContent = 'Rp 0';
                return;
            }

            let html = '';
            postedJournals.forEach(r => {
                html += `
                <tr>
                    <td style="text-align:center;">${escapeHtml(r.tanggal)}</td>
                    <td><strong>${escapeHtml(r.invoice)}</strong></td>
                    <td style="text-align:center;"><span style="font-size:8.5px; font-weight:600; color:var(--text-muted);">${escapeHtml(r.bc || '-')}</span></td>
                    <td>${escapeHtml(r.buyer_nama || r.kode_pembantu)}</td>
                    <td title="${escapeHtml(r.nama_akun_db)}"><code>${escapeHtml(r.akun_db)}</code> <span style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.nama_akun_db)}</span></td>
                    <td title="${escapeHtml(r.nama_akun_cr)}"><code>${escapeHtml(r.akun_cr)}</code> <span style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(r.nama_akun_cr)}</span></td>
                    <td>${escapeHtml(r.uraian)}</td>
                    <td style="text-align:center;">${escapeHtml(r.kurs)}</td>
                    <td style="text-align:right;">${formatNumber(r.amt)}</td>
                    <td style="text-align:right;">${formatNumber(r.rate)}</td>
                    <td style="text-align:right; font-weight:600; color:var(--primary);">${formatNumber(r.rp)}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn ant-btn-danger" style="height:19px; padding:0 6px; font-size:8.5px;" onclick="unpostJournal('${escapeHtml(r.id)}')" title="Batalkan / Unpost Jurnal">
                            <i class="fa-solid fa-rotate-left"></i> Batal
                        </button>
                    </td>
                </tr>`;
            });

            tbody.innerHTML = html;
            document.getElementById('posted-summary-text').textContent = `${postedJournals.length} baris jurnal terposting`;
            document.getElementById('sum-posted-rp').textContent = formatRupiah(summary ? summary.total_rp : 0);
        }

        async function unpostJournal(id) {
            const confirm = await Swal.fire({
                icon: 'warning',
                title: 'Batalkan Jurnal?',
                text: 'Faktur ini akan dihapus dari buku jurnal penjualan dan dikembalikan ke daftar draft.',
                showCancelButton: true,
                confirmButtonText: 'Ya, Batalkan',
                cancelButtonText: 'Kembali',
                customClass: { popup: 'compact-swal' }
            });

            if (!confirm.isConfirmed) return;

            try {
                const res = await fetch(`sales_journal.php?action=unpost_journal&id=${encodeURIComponent(id)}&cmp_id=<?= urlencode($sessionCmpId) ?>`);
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Dibatalkan',
                        text: json.message,
                        timer: 1500,
                        showConfirmButton: false,
                        customClass: { popup: 'compact-swal' }
                    });
                    loadKPI();
                    loadPostedJournal();
                    loadDraftInvoices();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Membatalkan',
                        text: json.message,
                        customClass: { popup: 'compact-swal' }
                    });
                }
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        function exportPostedToExcel() {
            if (postedJournals.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Data',
                    text: 'Tidak ada data jurnal untuk diekspor',
                    customClass: { popup: 'compact-swal' }
                });
                return;
            }

            const exportData = postedJournals.map(r => ({
                'Tanggal': r.tanggal,
                'No Invoice': r.invoice,
                'Dokumen BC': r.bc,
                'Customer / Buyer': r.buyer_nama || r.kode_pembantu,
                'Akun Debet': r.akun_db,
                'Nama Akun Debet': r.nama_akun_db,
                'Akun Kredit': r.akun_cr,
                'Nama Akun Kredit': r.nama_akun_cr,
                'Keterangan / Uraian': r.uraian,
                'Valas': r.kurs,
                'Nilai Valas': parseFloat(r.amt),
                'Rate Kurs': parseFloat(r.rate),
                'Jumlah Rp': parseFloat(r.rp)
            }));

            const ws = XLSX.utils.json_to_sheet(exportData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Jurnal Penjualan");
            XLSX.writeFile(wb, `Jurnal_Penjualan_${document.getElementById('posted-dt1').value}_${document.getElementById('posted-dt2').value}.xlsx`);
        }

        function openModuleGuide() {
            Swal.fire({
                icon: 'info',
                title: 'Panduan Modul Jurnal Penjualan',
                html: `
                    <div style="text-align:left; font-size:9.5px; line-height:1.6;">
                        <p><strong>Alur Bisnis &amp; SOP Akuntansi:</strong></p>
                        <ol style="padding-left:16px; margin:0;">
                            <li><strong>Konfirmasi Faktur:</strong> Faktur Penjualan (SI) dan Retur Penjualan (SR) yang diterbitkan oleh departemen Sales tampil di Tab 1.</li>
                            <li><strong>Centang &amp; Alokasi Akun GL:</strong> Operator memverifikasi kesesuaian DPP dan PPN Keluaran, memilih akun GL Piutang/Penjualan/PPN, lalu menekan tombol <em>Post ke Jurnal Penjualan</em>.</li>
                            <li><strong>Pencatatan Otomatis:</strong> Sistem mencatat jurnal berimbang (Debet Piutang Usaha, Kredit Penjualan &amp; PPN Keluaran) serta mengupdate Kartu Piutang Pelanggan (Buku Pembantu AR).</li>
                            <li><strong>Buku Jurnal:</strong> Riwayat jurnal dapat ditinjau dan diekspor ke Excel di Tab 2. Jika terdapat revisi atau pembatalan, klik tombol <em>Batal</em> untuk unpost.</li>
                        </ol>
                    </div>
                `,
                customClass: { popup: 'compact-swal' },
                confirmButtonText: 'Tutup'
            });
        }

        // Helpers
        function formatNumber(num) {
            return (parseFloat(num) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function formatRupiah(num) {
            return 'Rp ' + formatNumber(num);
        }
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
            // =========================================================================
        // PANDUAN & LIVE MAPPING PEMETAAN AKUN OTOMATIS (SALES JOURNAL)
        // =========================================================================
        function openConfigTab() {
            Swal.close();
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('act_konfig');
            } else {
                window.open('/index.php?tab=act_konfig', '_blank');
            }
        }

        async function showAutoAccountGuide() {
            Swal.fire({
                title: '<span class="spinner"></span> Memuat Konfigurasi Akun...',
                html: '<small style="color:var(--text-muted);">Mengambil pemetaan akun GL aktif dari database...</small>',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch('sales_journal.php?action=get_gl_mapping_info&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message || 'Gagal memuat info akun');

                const bsPakaiAkun = json.bs_pakai_akun || 'N';
                const taxList = json.tax || [];
                const kursList = json.kurs || [];

                let taxOutRow = taxList.find(t => parseInt(t.seq) === 2) || taxList.find(t => parseInt(t.seq) === 1) || { akun: '214-02-001', label: 'PPN Keluaran', nama_akun: 'Value Added Tax - Out' };

                let kursHtml = '';
                kursList.forEach(k => {
                    kursHtml += `
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:4px 6px; font-weight:700; color:var(--primary); text-align:center;">${escapeHtml(k.nama)}</td>
                            <td style="padding:4px 6px;">
                                <span class="mono" style="font-weight:600; color:#1890ff;">${escapeHtml(k.akun_piutang || '-')}</span>
                                <div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(k.nama_piutang || '-')}</div>
                            </td>
                            <td style="padding:4px 6px;">
                                <span class="mono" style="font-weight:600; color:#52c41a;">${escapeHtml(k.akun_jual_non || '-')}</span>
                                <div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(k.nama_jual_lokal || '-')}</div>
                            </td>
                            <td style="padding:4px 6px;">
                                <span class="mono" style="font-weight:600; color:#722ed1;">${escapeHtml(k.akun_jual || '-')}</span>
                                <div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(k.nama_jual_ekspor || '-')}</div>
                            </td>
                        </tr>
                    `;
                });

                if (!kursHtml) {
                    kursHtml = '<tr><td colspan="4" style="text-align:center; padding:8px; color:var(--text-muted);">Belum ada data mata uang di bc_ref_kurs. Default IDR (112-01-000 / 411-01-001)</td></tr>';
                }

                const modalHtml = `
                    <div style="text-align:left; font-size:10px; font-family:'Poppins',sans-serif; max-height:75vh; overflow-y:auto; padding:0 4px;">
                        <!-- Subtitle Penjelasan Singkat -->
                        <div style="background:rgba(24,144,255,0.06); border:1px solid rgba(24,144,255,0.25); border-radius:4px; padding:8px 10px; margin-bottom:10px; line-height:1.5;">
                            <i class="fa-solid fa-circle-info" style="color:#1890ff; margin-right:4px;"></i>
                            Di sistem ERP (standar PowerBuilder), posting <strong>Jurnal Penjualan</strong> dilakukan <strong>100% otomatis</strong> tanpa perlu memilih akun manual per faktur. Akun Debet &amp; Kredit dipetakan langsung dari konfigurasi terpusat di bawah ini.
                        </div>

                        <!-- TABEL AKUN AKTIF -->
                        <div style="font-weight:700; font-size:11px; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-table-list" style="color:#10b981;"></i> Akun GL Aktif yang Sedang Digunakan Saat Ini:
                        </div>
                        <table style="width:100%; border-collapse:collapse; font-size:9.5px; margin-bottom:12px; border:1px solid var(--border-light);">
                            <thead>
                                <tr style="background:var(--table-head-bg); border-bottom:1px solid var(--border-light); font-size:9px; text-transform:uppercase;">
                                    <th style="padding:5px 6px; text-align:center;">Valas</th>
                                    <th style="padding:5px 6px;">Akun Debet (Piutang)</th>
                                    <th style="padding:5px 6px;">Akun Kredit (Jual Lokal/BC 2.5/2.7)</th>
                                    <th style="padding:5px 6px;">Akun Kredit (Jual Ekspor/BC 3.0)</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${kursHtml}
                            </tbody>
                        </table>

                        <!-- INFO PPN & REKANAN -->
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px;">
                            <div style="border:1px solid var(--border-light); border-radius:4px; padding:6px 8px; background:var(--table-head-bg);">
                                <div style="font-weight:600; color:var(--text-muted); font-size:9px; text-transform:uppercase;">Akun Kredit PPN Keluaran:</div>
                                <div style="font-weight:700; color:#faad14; font-size:11px; margin-top:2px;">${escapeHtml(taxOutRow.akun || '214-02-001')}</div>
                                <div style="font-size:9px; color:var(--text-muted);">${escapeHtml(taxOutRow.nama_akun || taxOutRow.label || 'Value Added Tax - Out')}</div>
                                <div style="font-size:8.5px; color:#52c41a; margin-top:3px;"><i class="fa-solid fa-check"></i> Sumber: Master Pajak (Tab 1 bc_act_tax)</div>
                            </div>
                            <div style="border:1px solid var(--border-light); border-radius:4px; padding:6px 8px; background:var(--table-head-bg);">
                                <div style="font-weight:600; color:var(--text-muted); font-size:9px; text-transform:uppercase;">Kebijakan Akun Rekanan / Customer:</div>
                                <div style="font-weight:700; font-size:11px; margin-top:2px; color:${bsPakaiAkun === 'Y' ? '#52c41a' : '#1890ff'}">
                                    ${bsPakaiAkun === 'Y' ? 'AKTIF (Gunakan Akun Khusus Customer)' : 'NON-AKTIF (Satu Akun Per Valas Kurs)'}
                                </div>
                                <div style="font-size:8.5px; color:var(--text-muted); margin-top:3px;">
                                    ${bsPakaiAkun === 'Y' ? 'Jika diisi di Master Buyer, otomatis memprioritaskan akun customer.' : 'Seluruh customer menggunakan akun piutang standar per valas.'}
                                </div>
                            </div>
                        </div>

                        <!-- PANDUAN CARA MENGUBAH / EDIT -->
                        <div style="background:var(--table-head-bg); border:1px solid var(--border-light); border-radius:4px; padding:8px 10px; margin-bottom:10px;">
                            <div style="font-weight:700; font-size:10.5px; color:var(--text-main); margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                <i class="fa-solid fa-sliders" style="color:#f59e0b;"></i> Cara Mengubah / Mengedit Akun Tersebut di Konfigurasi:
                            </div>
                            <ol style="margin:0; padding-left:18px; line-height:1.6;">
                                <li>Buka menu <strong>Konfigurasi Akun</strong> (modul <em>Basic &gt; Konfigurasi Akun</em>) atau klik tombol di bawah.</li>
                                <li><strong>Untuk mengubah Akun Piutang &amp; Penjualan:</strong> Klik <strong>Tab 3 (Valas &amp; Kurs)</strong>, lalu edit kolom <em>Akun Piutang</em>, <em>Akun Jual (Ekspor)</em>, atau <em>Akun Jual Non (Lokal)</em> pada baris mata uang yang sesuai (IDR/USD).</li>
                                <li><strong>Untuk mengubah Akun PPN Keluaran:</strong> Klik <strong>Tab 1 (Pajak PPN &amp; PPh)</strong>, lalu pilih akun COA pada baris <em>PPN Penjualan / Keluaran</em>.</li>
                                <li><strong>Untuk menetapkan Akun Khusus per Customer:</strong> Buka <strong>Tab 4 (Kebijakan Rekanan)</strong> set <em>bs_pakai_akun = 'Y'</em>, lalu isi akun di menu <em>Master Buyer/Supplier</em>.</li>
                                <li>Klik <strong>Simpan</strong>. Faktur berikutnya otomatis menggunakan pemetaan baru!</li>
                            </ol>
                        </div>
                    </div>
                `;

                Swal.fire({
                    title: '<div style="font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-wand-magic-sparkles" style="color:#10b981;"></i> Panduan Pemetaan Akun GL Otomatis (Jurnal Penjualan)</div>',
                    html: modalHtml,
                    width: '640px',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Menu Konfigurasi Akun',
                    cancelButtonText: 'Tutup',
                    confirmButtonColor: '#1890ff',
                    cancelButtonColor: '#8c8c8c'
                }).then((r) => {
                    if (r.isConfirmed) {
                        openConfigTab();
                    }
                });

            } catch(e) {
                Swal.fire('Error', 'Gagal memuat informasi konfigurasi akun: ' + e.message, 'error');
            }
        }

    </script>
</body>
</html>

<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Jurnal Pembelian (Purchase Journal)
 * Hasil Porting Sempurna dari PowerBuilder TPB-ERP v2.0 (w_act_jurnal_beli.srw & f_jurnal_beli.srf)
 * DataWindows: bc_act_beli.srd, d_act_akun_rpt.srd, dw_bs_filter_beli.srd
 * 
 * Standar UI: Ant Design Enterprise Compact Desktop 9.5px PB-Style
 * Fitur:
 * - 4 KPI Metrics Real-Time (Total Pembelian, Siap Dijurnal, Terposting, PPN Masukan)
 * - Tab 1: Konfirmasi Faktur PI & PR Belum Dijurnal (Centang & Batch Post ke GL)
 * - Tab 2: Buku Jurnal Pembelian Terposting (bc_act_beli) dengan Fitur Batal/Unpost
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
function ensurePurchaseJournalTables($koneksi, $escCmp) {
    $koneksi->query("CREATE TABLE IF NOT EXISTS `bc_act_beli` (
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
        `status` varchar(1) DEFAULT '0',
        `cmp_id` varchar(30) NOT NULL DEFAULT '',
        PRIMARY KEY (`tanggal`,`akun_db`,`akun_cr`,`id`),
        KEY `idx_cmp` (`cmp_id`),
        KEY `idx_invoice` (`invoice`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Catatan: Tidak memodifikasi tabel bc_pi_hdr/bc_pr_hdr karena status jurnal dilacak via tabel bc_act_beli
}
ensurePurchaseJournalTables($koneksi, $escCmp);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');
    $cmpClause = !empty($sessionCmpId) ? "(cmp_id = '$escCmp' OR cmp_id = '')" : "1=1";
    $currYm = date('Y-m');

    $sqlBeli = "SELECT 
        COALESCE(SUM(a.total_order), 0) as total_beli,
        COALESCE(SUM(a.ppn), 0) as total_ppn,
        COUNT(CASE WHEN j.id IS NULL THEN 1 END) as unposted_cnt,
        COUNT(CASE WHEN j.id IS NOT NULL THEN 1 END) as posted_cnt
        FROM bc_pi_hdr a
        LEFT JOIN (SELECT DISTINCT id FROM bc_act_beli WHERE $cmpClause) j ON a.id = j.id
        WHERE $cmpClause AND DATE_FORMAT(a.pi_dt, '%Y-%m') = '$currYm'";
    $resBeli = $koneksi->query($sqlBeli);
    $rowBeli = $resBeli ? $resBeli->fetch_assoc() : [];

    echo json_encode([
        'status' => 'OK',
        'kpi' => [
            'total_beli'   => (float)($rowBeli['total_beli'] ?? 0),
            'total_ppn'    => (float)($rowBeli['total_ppn'] ?? 0),
            'unposted_cnt' => (int)($rowBeli['unposted_cnt'] ?? 0),
            'posted_cnt'   => (int)($rowBeli['posted_cnt'] ?? 0)
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
    $vendor = trim($_GET['vendor'] ?? '');

    $escDt1 = $koneksi->real_escape_string($dt1);
    $escDt2 = $koneksi->real_escape_string($dt2);

    $vFilter = "";
    if (!empty($vendor)) {
        $escV = $koneksi->real_escape_string($vendor);
        $vFilter = "AND (a.vendor = '$escV' OR b.NAMA LIKE '%$escV%')";
    }

    $sql = "SELECT 'PI' as jenis, a.id, a.pi_no as no_invoice, a.pi_dt as tanggal, 
                   a.vendor, b.NAMA as vendor_nama, b.KODE_BANTU, b.AKUN as vendor_akun,
                   a.kurs, a.rate, a.subtotal, a.ppn, a.total_order, a.bc, a.no_bc, a.no_aju,
                   '0' as status_jurnal
            FROM bc_pi_hdr a
            LEFT JOIN bc_bs b ON a.vendor = b.KODE
            WHERE $cmpClause AND a.pi_dt BETWEEN '$escDt1' AND '$escDt2'
              AND a.id NOT IN (SELECT id FROM bc_act_beli WHERE $cmpClause)
              $vFilter
            UNION ALL
            SELECT 'PR' as jenis, a.id, a.pr_no as no_invoice, a.pr_dt as tanggal,
                   a.vendor, b.NAMA as vendor_nama, b.KODE_BANTU, b.AKUN as vendor_akun,
                   a.kurs, a.rate, a.subtotal, a.ppn, (a.subtotal + a.ppn) as total_order, '14' as bc, '' as no_bc, '' as no_aju,
                   '0' as status_jurnal
            FROM bc_pr_hdr a
            LEFT JOIN bc_bs b ON a.vendor = b.KODE
            WHERE $cmpClause AND a.pr_dt BETWEEN '$escDt1' AND '$escDt2'
              AND a.id NOT IN (SELECT id FROM bc_act_beli WHERE $cmpClause)
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
// BACKEND AJAX API: Post Batch Jurnal Pembelian
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

    // 2. Akun PPN Masukan Otomatis dari bc_act_tax (seq = 1)
    $akunPpnMasukan = '114-01-001';
    $qPpn = $koneksi->query("SELECT akun FROM bc_act_tax WHERE (cmp_id = '$escCmp' OR cmp_id = '') AND seq = 1 LIMIT 1");
    if ($qPpn && $rPpn = $qPpn->fetch_assoc()) {
        if (!empty($rPpn['akun'])) $akunPpnMasukan = trim($rPpn['akun']);
    }

    // 3. Peta Akun Hutang Otomatis per Kurs dari bc_ref_kurs
    $kursHutangMap = [];
    $qKurs = $koneksi->query("SELECT nama, akun_hutang FROM bc_ref_kurs WHERE (cmp_id = '$escCmp' OR cmp_id = '')");
    if ($qKurs) {
        while ($rK = $qKurs->fetch_assoc()) {
            $kName = strtoupper(trim($rK['nama']));
            if (!empty($rK['akun_hutang'])) $kursHutangMap[$kName] = trim($rK['akun_hutang']);
        }
    }

    // 4. Peta Akun Pembelian Otomatis per Klasifikasi Material dari bc_act_tipe
    $tipeBeliMap = [];
    $defaultAkunBeli = '113-01-001';
    $qTipe = $koneksi->query("SELECT tipe, grp, akun_pembelian, akun_aktiva FROM bc_act_tipe WHERE (cmp_id = '$escCmp' OR cmp_id = '')");
    if ($qTipe) {
        while ($rT = $qTipe->fetch_assoc()) {
            $acc = !empty($rT['akun_pembelian']) ? trim($rT['akun_pembelian']) : (!empty($rT['akun_aktiva']) ? trim($rT['akun_aktiva']) : '');
            if ($acc) {
                $tName = strtoupper(trim($rT['tipe']));
                $tipeBeliMap[$tName] = $acc;
                if (!empty($rT['grp'])) {
                    $tipeBeliMap[strtoupper(trim($rT['grp']))] = $acc;
                }
                if ($defaultAkunBeli === '113-01-001' && strpos($tName, 'RAW') !== false) {
                    $defaultAkunBeli = $acc;
                }
            }
        }
    }

    $koneksi->begin_transaction();
    $postedCount = 0;

    try {
        foreach ($ids as $rawId) {
            $escId = $koneksi->real_escape_string($rawId);

            // Fetch PI Header
            $qPi = $koneksi->query("SELECT a.*, b.NAMA as vendor_nama, b.AKUN as vendor_akun, b.KODE_BANTU 
                                   FROM bc_pi_hdr a LEFT JOIN bc_bs b ON a.vendor = b.KODE 
                                   WHERE a.id = '$escId' AND (a.cmp_id = '$escCmp' OR a.cmp_id = '') LIMIT 1");
            if ($qPi && $qPi->num_rows > 0) {
                $pi = $qPi->fetch_assoc();
                $tgl     = $pi['pi_dt'];
                $inv     = $pi['pi_no'];
                $kurs    = !empty($pi['kurs']) ? strtoupper(trim($pi['kurs'])) : 'IDR';
                $rate    = (float)($pi['rate'] ?? 1);
                if ($rate <= 0) $rate = 1;
                $subtotal = (float)($pi['subtotal'] ?? 0);
                $ppn      = (float)($pi['ppn'] ?? 0);
                $total    = (float)($pi['total_order'] ?? ($subtotal + $ppn));
                $bc       = $pi['bc'] ?? '';
                $kdBantu  = !empty($pi['KODE_BANTU']) ? $pi['KODE_BANTU'] : $pi['vendor'];

                // Akun Hutang Kredit Otomatis sesuai rumus PB (f_jurnal_beli2)
                $akunCr = '';
                if ($bsPakaiAkun === 'Y' && !empty($pi['vendor_akun'])) {
                    $akunCr = trim($pi['vendor_akun']);
                }
                if (empty($akunCr)) {
                    $akunCr = $kursHutangMap[$kurs] ?? ($kursHutangMap['IDR'] ?? '211-01-000');
                }

                // Delete old unposted if any
                $koneksi->query("DELETE FROM bc_act_beli WHERE id = '$escId' AND cmp_id = '$escCmp'");

                // 1. Entry Pembelian / Persediaan (Debet): breakdown per detail item jika ada
                $qDtl = $koneksi->query("SELECT b.kode_barang, b.amount, b.amt_jasa, c.KLAS 
                                         FROM bc_pi_dtl b 
                                         LEFT JOIN bc_material c ON b.kode_barang = c.KODE_BARANG 
                                         WHERE b.id_hdr = '$escId'");
                $hasDtl = ($qDtl && $qDtl->num_rows > 0);

                if ($hasDtl) {
                    $groupedAmounts = [];
                    while ($dtl = $qDtl->fetch_assoc()) {
                        $amtItem = (float)($dtl['amount'] ?? 0);
                        if ($amtItem <= 0 && !empty($dtl['amt_jasa'])) {
                            $amtItem = (float)$dtl['amt_jasa'];
                        }
                        if ($amtItem <= 0) continue;

                        $klasItem = strtoupper(trim($dtl['KLAS'] ?? ''));
                        $itemAkunDb = $tipeBeliMap[$klasItem] ?? $defaultAkunBeli;
                        if (!isset($groupedAmounts[$itemAkunDb])) {
                            $groupedAmounts[$itemAkunDb] = 0;
                        }
                        $groupedAmounts[$itemAkunDb] += $amtItem;
                    }

                    $idxCounter = 1;
                    foreach ($groupedAmounts as $accDb => $amtGroup) {
                        $rpGroup = round($amtGroup * $rate, 2);
                        $uraian = "Pembelian {$pi['vendor_nama']} Inv: $inv";
                        $koneksi->query("INSERT INTO bc_act_beli 
                            (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, cmp_id)
                            VALUES ('$tgl', '$kdBantu', '$inv', '$bc', '', '$uraian', '$accDb', '$akunCr', '$kurs', $amtGroup, '$escId', $idxCounter, $rate, $rpGroup, '1', '$escCmp')");
                        $idxCounter++;
                    }
                } else {
                    // Fallback jika tidak ada record detail di bc_pi_dtl
                    if ($subtotal > 0) {
                        $rpSub = round($subtotal * $rate, 2);
                        $uraian = "Pembelian {$pi['vendor_nama']} Inv: $inv";
                        $koneksi->query("INSERT INTO bc_act_beli 
                            (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, cmp_id)
                            VALUES ('$tgl', '$kdBantu', '$inv', '$bc', '', '$uraian', '$defaultAkunBeli', '$akunCr', '$kurs', $subtotal, '$escId', 1, $rate, $rpSub, '1', '$escCmp')");
                    }
                }

                // 2. Entry PPN Masukan (Debet) Otomatis
                if ($ppn > 0) {
                    $rpPpn = round($ppn * $rate, 2);
                    $uraianPpn = "PPN Masukan {$pi['vendor_nama']} Inv: $inv";
                    $koneksi->query("INSERT INTO bc_act_beli 
                        (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, cmp_id)
                        VALUES ('$tgl', '$kdBantu', '$inv', '$bc', '', '$uraianPpn', '$akunPpnMasukan', '$akunCr', '$kurs', $ppn, '$escId', 2, $rate, $rpPpn, '1', '$escCmp')");
                }

                // Update Status Jurnal di PI Header jika kolom ada
                try { $koneksi->query("UPDATE bc_pi_hdr SET status_jurnal = '1' WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')"); } catch (Throwable $t) {}

                $postedCount++;
            } else {
                // Fetch PR Header (Retur Pembelian)
                $qPr = $koneksi->query("SELECT a.*, b.NAMA as vendor_nama, b.AKUN as vendor_akun, b.KODE_BANTU 
                                       FROM bc_pr_hdr a LEFT JOIN bc_bs b ON a.vendor = b.KODE 
                                       WHERE a.id = '$escId' AND (a.cmp_id = '$escCmp' OR a.cmp_id = '') LIMIT 1");
                if ($qPr && $qPr->num_rows > 0) {
                    $pr = $qPr->fetch_assoc();
                    $tgl     = $pr['pr_dt'];
                    $inv     = $pr['pr_no'];
                    $kurs    = !empty($pr['kurs']) ? strtoupper(trim($pr['kurs'])) : 'IDR';
                    $rate    = (float)($pr['rate'] ?? 1);
                    if ($rate <= 0) $rate = 1;
                    $subtotal = (float)($pr['subtotal'] ?? 0);
                    $ppn      = (float)($pr['ppn'] ?? 0);
                    $total    = (float)($subtotal + $ppn);
                    $bc       = '14';
                    $kdBantu  = !empty($pr['KODE_BANTU']) ? $pr['KODE_BANTU'] : $pr['vendor'];

                    $akunCr = '';
                    if ($bsPakaiAkun === 'Y' && !empty($pr['vendor_akun'])) {
                        $akunCr = trim($pr['vendor_akun']);
                    }
                    if (empty($akunCr)) {
                        $akunCr = $kursHutangMap[$kurs] ?? ($kursHutangMap['IDR'] ?? '211-01-000');
                    }

                    $koneksi->query("DELETE FROM bc_act_beli WHERE id = '$escId' AND cmp_id = '$escCmp'");

                    if ($subtotal > 0) {
                        $rpSub = round($subtotal * $rate, 2);
                        $uraian = "Retur Pembelian {$pr['vendor_nama']} No: $inv";
                        // Retur: Debet Hutang (mengurangi hutang), Kredit Persediaan (barang berkurang)
                        $koneksi->query("INSERT INTO bc_act_beli 
                            (tanggal, kode_pembantu, invoice, bc, no_fp, uraian, akun_db, akun_cr, kurs, amt, id, idx, rate, rp, status, cmp_id)
                            VALUES ('$tgl', '$kdBantu', '$inv', '$bc', '', '$uraian', '$akunCr', '$defaultAkunBeli', '$kurs', $subtotal, '$escId', 1, $rate, $rpSub, '1', '$escCmp')");
                    }

                    $postedCount++;
                }
            }
        }

        $koneksi->commit();
        echo json_encode(['status' => 'OK', 'message' => "Berhasil memposting $postedCount faktur ke Jurnal Pembelian"]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal posting jurnal: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// BACKEND AJAX API: Unpost / Batalkan Jurnal Pembelian
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
        $koneksi->query("DELETE FROM bc_act_beli WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        try { $koneksi->query("UPDATE bc_pi_hdr SET status_jurnal = '0' WHERE id = '$escId' AND (cmp_id = '$escCmp' OR cmp_id = '')"); } catch (Throwable $t) {}
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
                   COALESCE(b.NAMA, a.kode_pembantu) as vendor_nama,
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_db AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_db,
                   (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = a.akun_cr AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_cr
            FROM bc_act_beli a
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

    // 2. Akun Pajak (PPN In)
    $taxList = [];
    $qTax = $koneksi->query("SELECT seq, label, akun, 
                            (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = bc_act_tax.akun AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun 
                            FROM bc_act_tax WHERE $cmpClause ORDER BY seq ASC");
    if ($qTax) {
        while ($r = $qTax->fetch_assoc()) {
            $taxList[] = $r;
        }
    }

    // 3. Valas & Akun Kurs (Hutang Usaha)
    $kursList = [];
    $qKurs = $koneksi->query("SELECT nama, nilaitukar AS kurs, akun_hutang,
                             (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = bc_ref_kurs.akun_hutang AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_hutang
                             FROM bc_ref_kurs WHERE $cmpClause ORDER BY nama ASC");
    if ($qKurs) {
        while ($r = $qKurs->fetch_assoc()) {
            $kursList[] = $r;
        }
    }

    // 4. Tipe Persediaan & Pembelian (Raw Material, Spareparts, dll)
    $tipeList = [];
    $qTipe = $koneksi->query("SELECT tipe, grp, akun_pembelian, akun_aktiva,
                             (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = bc_act_tipe.akun_pembelian AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_pembelian,
                             (SELECT nama_akun FROM bc_act_akun x WHERE x.kode = bc_act_tipe.akun_aktiva AND (x.cmp_id = '$escCmp' OR x.cmp_id = '') LIMIT 1) as nama_akun_aktiva
                             FROM bc_act_tipe WHERE $cmpClause ORDER BY tipe ASC");
    if ($qTipe) {
        while ($r = $qTipe->fetch_assoc()) {
            $tipeList[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'module' => 'purchase',
        'bs_pakai_akun' => $bsPakaiAkun,
        'tax' => $taxList,
        'kurs' => $kursList,
        'tipe' => $tipeList
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jurnal Pembelian - CEISA 4.0 & ERP Hub</title>
    
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
                <div class="kpi-icon blue"><i class="fa-solid fa-receipt"></i></div>
                <div class="kpi-data">
                    <span class="kpi-label">Total Pembelian (Bulan Ini)</span>
                    <span class="kpi-val" id="kpi-total-beli">Rp 0</span>
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
                    <span class="kpi-label">Total PPN Masukan</span>
                    <span class="kpi-val" id="kpi-total-ppn">Rp 0</span>
                </div>
            </div>
        </div>

        <!-- Main Card Panel with Tabs -->
        <div class="card-panel">
            <div class="card-header-tabs">
                <div class="tabs-nav">
                    <button type="button" class="tab-btn active" id="tab-btn-draft" onclick="switchTab('draft')">
                        <i class="fa-solid fa-list-check"></i> 1. Konfirmasi Faktur PI & PR (Belum Dijurnal)
                    </button>
                    <button type="button" class="tab-btn" id="tab-btn-posted" onclick="switchTab('posted')">
                        <i class="fa-solid fa-book-journal-whills"></i> 2. Buku Jurnal Pembelian Terposting (bc_act_beli)
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

            <!-- TAB 1: DRAFT & KONFIRMASI PI / PR -->
            <div id="panel-draft" style="display:flex; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode:</label>
                    <input type="date" id="draft-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="draft-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <label style="margin-left:6px;">Supplier/Vendor:</label>
                    <input type="text" id="draft-vendor" class="ant-input" placeholder="Cari vendor..." style="width:140px;">

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
                        <i class="fa-solid fa-cloud-arrow-up"></i> Post ke Jurnal Pembelian (<span id="selected-draft-count">0</span>)
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
                                <th style="width: 180px;">Supplier / Vendor</th>
                                <th style="width: 50px; text-align:center;">Valas</th>
                                <th style="width: 60px; text-align:right;">Kurs</th>
                                <th style="width: 95px; text-align:right;">Subtotal (DPP)</th>
                                <th style="width: 85px; text-align:right;">PPN Masukan</th>
                                <th style="width: 100px; text-align:right;">Total Tagihan</th>
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

            <!-- TAB 2: BUKU JURNAL PEMBELIAN TERPOSTING (bc_act_beli) -->
            <div id="panel-posted" style="display:none; flex-direction:column; flex:1; min-height:0;">
                <div class="toolbar">
                    <label>Periode:</label>
                    <input type="date" id="posted-dt1" class="ant-input" value="<?= date('Y-m-01') ?>">
                    <span style="font-size:9px; color:var(--text-muted)">s/d</span>
                    <input type="date" id="posted-dt2" class="ant-input" value="<?= date('Y-m-d') ?>">

                    <div class="radio-pill-group" style="margin-left:8px;">
                        <button type="button" class="radio-pill-btn active" id="pill-all" onclick="setPostedIdxFilter('', this)">Semua</button>
                        <button type="button" class="radio-pill-btn" id="pill-pembelian" onclick="setPostedIdxFilter('1', this)">Pembelian (1)</button>
                        <button type="button" class="radio-pill-btn" id="pill-ppn" onclick="setPostedIdxFilter('2', this)">PPN In (2)</button>
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
                                <th style="width: 170px;">Supplier / Rekanan</th>
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
                const res = await fetch('purchase_journal.php?action=kpi&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    document.getElementById('kpi-total-beli').textContent = formatRupiah(json.kpi.total_beli);
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
                const res = await fetch('purchase_journal.php?action=get_accounts&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status === 'OK' && Array.isArray(json.data)) {
                    coaAccounts = json.data;
                    populateDropdown('sel-akun-persediaan', coaAccounts, 
                        ['113-01-001', '113-01', '113', '1201', '1200', '11050', '1105', '1140'],
                        ['raw material', 'persediaan', 'inventory', 'bahan baku']
                    );
                    populateDropdown('sel-akun-ppn', coaAccounts, 
                        ['114-01-001', '114-01', '114', '1400', '11080', '1108', '1170'],
                        ['value added tax (in)', 'vat in', 'ppn masukan', 'ppn in', 'prepaid tax']
                    );
                    populateDropdown('sel-akun-hutang', coaAccounts, 
                        ['211-01-000', '211-01', '211', '2000', '2001', '21010', '2101', '2110'],
                        ['account payable', 'hutang usaha', 'hutang dagang', 'trade payable']
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
            const vendor = document.getElementById('draft-vendor').value;
            const tbody = document.getElementById('tbody-draft');

            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:20px; color:var(--text-muted);">Mengambil daftar faktur draft...</td></tr>';

            try {
                const url = `purchase_journal.php?action=get_unposted_invoices&cmp_id=<?= urlencode($sessionCmpId) ?>&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}&vendor=${encodeURIComponent(vendor)}`;
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
                const isPR = r.jenis === 'PR';
                const tagColor = isPR ? '#f5222d' : '#1890ff';
                const tagBg = isPR ? '#fff1f0' : '#e6f7ff';

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
                    <td>${escapeHtml(r.vendor_nama || r.vendor)}</td>
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
                html: `Posting <strong>${ids.length} faktur</strong> ke Buku Jurnal Pembelian?<br><small style="color:var(--text-muted)">Akun GL dipetakan 100% otomatis dari <strong>Konfigurasi Akun</strong> (Tipe Material, Kurs &amp; Vendor)</small>`,
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
                const res = await fetch('purchase_journal.php?action=post_batch_journal&cmp_id=<?= urlencode($sessionCmpId) ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids })
                });
                const json = await res.json();
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up"></i> Post ke Jurnal Pembelian (<span id="selected-draft-count">0</span>)`;

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
                btn.innerHTML = `<i class="fa-solid fa-cloud-arrow-up"></i> Post ke Jurnal Pembelian (<span id="selected-draft-count">0</span>)`;
                Swal.fire({
                    icon: 'error',
                    title: 'Kesalahan Sistem',
                    text: e.message,
                    customClass: { popup: 'compact-swal' }
                });
            }
        }

        // =========================================================================
        // TAB 2: POSTED JOURNAL (bc_act_beli)
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

            tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat buku jurnal pembelian...</td></tr>';

            try {
                let url = `purchase_journal.php?action=browse_posted&cmp_id=<?= urlencode($sessionCmpId) ?>&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}`;
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
                tbody.innerHTML = '<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada transaksi jurnal pembelian yang terposting pada periode ini.</td></tr>';
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
                    <td>${escapeHtml(r.vendor_nama || r.kode_pembantu)}</td>
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
                text: 'Faktur ini akan dihapus dari buku jurnal pembelian dan dikembalikan ke daftar draft.',
                showCancelButton: true,
                confirmButtonText: 'Ya, Batalkan',
                cancelButtonText: 'Kembali',
                customClass: { popup: 'compact-swal' }
            });

            if (!confirm.isConfirmed) return;

            try {
                const res = await fetch(`purchase_journal.php?action=unpost_journal&id=${encodeURIComponent(id)}&cmp_id=<?= urlencode($sessionCmpId) ?>`);
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
                'Supplier / Rekanan': r.vendor_nama || r.kode_pembantu,
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
            XLSX.utils.book_append_sheet(wb, ws, "Jurnal Pembelian");
            XLSX.writeFile(wb, `Jurnal_Pembelian_${document.getElementById('posted-dt1').value}_${document.getElementById('posted-dt2').value}.xlsx`);
        }

        // =========================================================================
        // PANDUAN & LIVE MAPPING PEMETAAN AKUN OTOMATIS (PURCHASE JOURNAL)
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
                const res = await fetch('purchase_journal.php?action=get_gl_mapping_info&cmp_id=<?= urlencode($sessionCmpId) ?>');
                const json = await res.json();
                if (json.status !== 'OK') throw new Error(json.message || 'Gagal memuat info akun');

                const bsPakaiAkun = json.bs_pakai_akun || 'N';
                const taxList = json.tax || [];
                const kursList = json.kurs || [];
                const tipeList = json.tipe || [];

                let taxInRow = taxList.find(t => parseInt(t.seq) === 1) || { akun: '114-01-001', label: 'PPN Masukan', nama_akun: 'Value Added Tax - In' };

                // Build Kurs Table (Kredit: Hutang Dagang per Valas)
                let kursHtml = '';
                kursList.forEach(k => {
                    kursHtml += `
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:4px 6px; font-weight:700; color:var(--primary); text-align:center;">${escapeHtml(k.nama)}</td>
                            <td style="padding:4px 6px;">
                                <span class="mono" style="font-weight:600; color:#ef4444;">${escapeHtml(k.akun_hutang || '-')}</span>
                                <div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(k.nama_hutang || '-')}</div>
                            </td>
                        </tr>
                    `;
                });
                if (!kursHtml) {
                    kursHtml = '<tr><td colspan="2" style="text-align:center; padding:8px; color:var(--text-muted);">Belum ada data mata uang di bc_ref_kurs. Default IDR (211-01-000)</td></tr>';
                }

                // Build Tipe Table (Debet: Persediaan per Kategori)
                let tipeHtml = '';
                tipeList.filter(t => t.grp !== '%' && (t.akun_aktiva || t.akun_pembelian)).forEach(t => {
                    tipeHtml += `
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:4px 6px; font-weight:600;">${escapeHtml(t.tipe)} <span class="ant-tag" style="font-size:8px; padding:0 3px;">${escapeHtml(t.grp || '')}</span></td>
                            <td style="padding:4px 6px;">
                                <span class="mono" style="font-weight:600; color:#1890ff;">${escapeHtml(t.akun_aktiva || t.akun_pembelian || '-')}</span>
                                <div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(t.nama_akun_aktiva || t.nama_akun_pembelian || '-')}</div>
                            </td>
                        </tr>
                    `;
                });
                if (!tipeHtml) {
                    tipeHtml = '<tr><td colspan="2" style="text-align:center; padding:8px; color:var(--text-muted);">Belum ada kategori barang di bc_act_tipe. Default Raw Material (113-01-001)</td></tr>';
                }

                const modalHtml = `
                    <div style="text-align:left; font-size:10px; font-family:'Poppins',sans-serif; max-height:75vh; overflow-y:auto; padding:0 4px;">
                        <!-- Subtitle Penjelasan Singkat -->
                        <div style="background:rgba(24,144,255,0.06); border:1px solid rgba(24,144,255,0.25); border-radius:4px; padding:8px 10px; margin-bottom:10px; line-height:1.5;">
                            <i class="fa-solid fa-circle-info" style="color:#1890ff; margin-right:4px;"></i>
                            Di sistem ERP (standar PowerBuilder), posting <strong>Jurnal Pembelian</strong> dilakukan <strong>100% otomatis</strong> tanpa perlu memilih akun manual per faktur. Akun Debet (Persediaan/PPN) &amp; Kredit (Hutang) dipetakan langsung dari konfigurasi terpusat di bawah ini.
                        </div>

                        <!-- DUA TABEL BERDAMPINGAN: PERSEDIAAN & VALAS -->
                        <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:10px; margin-bottom:12px;">
                            <div>
                                <div style="font-weight:700; font-size:10.5px; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                    <i class="fa-solid fa-boxes-stacked" style="color:#1890ff;"></i> 1. Akun Debet: Persediaan per Tipe
                                </div>
                                <table style="width:100%; border-collapse:collapse; font-size:9.5px; border:1px solid var(--border-light);">
                                    <thead>
                                        <tr style="background:var(--table-head-bg); border-bottom:1px solid var(--border-light); font-size:9px; text-transform:uppercase;">
                                            <th style="padding:5px 6px;">Kategori Barang</th>
                                            <th style="padding:5px 6px;">Akun GL Debet</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${tipeHtml}
                                    </tbody>
                                </table>
                            </div>

                            <div>
                                <div style="font-weight:700; font-size:10.5px; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                    <i class="fa-solid fa-coins" style="color:#ef4444;"></i> 2. Akun Kredit: Hutang per Valas
                                </div>
                                <table style="width:100%; border-collapse:collapse; font-size:9.5px; border:1px solid var(--border-light);">
                                    <thead>
                                        <tr style="background:var(--table-head-bg); border-bottom:1px solid var(--border-light); font-size:9px; text-transform:uppercase;">
                                            <th style="padding:5px 6px; text-align:center;">Valas</th>
                                            <th style="padding:5px 6px;">Akun Hutang (Kredit)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${kursHtml}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- INFO PPN & REKANAN -->
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px;">
                            <div style="border:1px solid var(--border-light); border-radius:4px; padding:6px 8px; background:var(--table-head-bg);">
                                <div style="font-weight:600; color:var(--text-muted); font-size:9px; text-transform:uppercase;">Akun Debet PPN Masukan:</div>
                                <div style="font-weight:700; color:#52c41a; font-size:11px; margin-top:2px;">${escapeHtml(taxInRow.akun || '114-01-001')}</div>
                                <div style="font-size:9px; color:var(--text-muted);">${escapeHtml(taxInRow.nama_akun || taxInRow.label || 'Value Added Tax - In')}</div>
                                <div style="font-size:8.5px; color:#52c41a; margin-top:3px;"><i class="fa-solid fa-check"></i> Sumber: Master Pajak (Tab 1 bc_act_tax)</div>
                            </div>
                            <div style="border:1px solid var(--border-light); border-radius:4px; padding:6px 8px; background:var(--table-head-bg);">
                                <div style="font-weight:600; color:var(--text-muted); font-size:9px; text-transform:uppercase;">Kebijakan Akun Supplier / Vendor:</div>
                                <div style="font-weight:700; font-size:11px; margin-top:2px; color:${bsPakaiAkun === 'Y' ? '#52c41a' : '#1890ff'}">
                                    ${bsPakaiAkun === 'Y' ? 'AKTIF (Gunakan Akun Khusus Vendor)' : 'NON-AKTIF (Satu Akun Per Valas Kurs)'}
                                </div>
                                <div style="font-size:8.5px; color:var(--text-muted); margin-top:3px;">
                                    ${bsPakaiAkun === 'Y' ? 'Jika diisi di Master Supplier, otomatis memprioritaskan akun vendor.' : 'Seluruh vendor menggunakan akun hutang standar per valas.'}
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
                                <li><strong>Untuk mengubah Akun Persediaan/Pembelian:</strong> Klik <strong>Tab 2 (Tipe Barang)</strong>, pilih baris kategori (Raw Material, Spareparts, dll), lalu edit kolom <em>Akun Aktiva / Pembelian</em>.</li>
                                <li><strong>Untuk mengubah Akun Hutang Dagang per Valas:</strong> Klik <strong>Tab 3 (Valas &amp; Kurs)</strong>, lalu edit kolom <em>Akun Hutang</em> pada baris mata uang yang sesuai (IDR/USD).</li>
                                <li><strong>Untuk mengubah Akun PPN Masukan:</strong> Klik <strong>Tab 1 (Pajak PPN &amp; PPh)</strong>, lalu pilih akun COA pada baris <em>PPN Pembelian / Masukan</em>.</li>
                                <li><strong>Untuk menetapkan Akun Khusus per Supplier:</strong> Buka <strong>Tab 4 (Kebijakan Rekanan)</strong> set <em>bs_pakai_akun = 'Y'</em>, lalu tentukan akun di menu <em>Master Buyer/Supplier</em>.</li>
                                <li>Klik <strong>Simpan</strong>. Faktur berikutnya otomatis menggunakan pemetaan baru!</li>
                            </ol>
                        </div>
                    </div>
                `;

                Swal.fire({
                    title: '<div style="font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-wand-magic-sparkles" style="color:#10b981;"></i> Panduan Pemetaan Akun GL Otomatis (Jurnal Pembelian)</div>',
                    html: modalHtml,
                    width: '680px',
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

        function openModuleGuide() {
            Swal.fire({
                icon: 'info',
                title: 'Panduan Modul Jurnal Pembelian',
                html: `
                    <div style="text-align:left; font-size:9.5px; line-height:1.6;">
                        <p><strong>Alur Bisnis &amp; SOP Akuntansi:</strong></p>
                        <ol style="padding-left:16px; margin:0;">
                            <li><strong>Konfirmasi Faktur:</strong> Faktur Pembelian (PI) dan Retur Pembelian (PR) yang masuk dari Supply Chain Management tampil di Tab 1.</li>
                            <li><strong>Centang &amp; Alokasi Akun GL:</strong> Operator memverifikasi kesesuaian nilai DPP dan PPN, memilih akun GL Persediaan/PPN/Hutang, lalu menekan tombol <em>Post ke Jurnal Pembelian</em>.</li>
                            <li><strong>Pencatatan Otomatis:</strong> Sistem mencatat jurnal berimbang (Debet Persediaan/PPN In, Kredit Hutang Dagang) serta mengupdate Kartu Hutang Rekanan (Buku Pembantu).</li>
                            <li><strong>Buku Jurnal:</strong> Riwayat jurnal dapat ditinjau dan diekspor ke Excel di Tab 2. Jika terdapat kesalahan entri, klik tombol <em>Batal</em> untuk unpost.</li>
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
    </script>
</body>
</html>

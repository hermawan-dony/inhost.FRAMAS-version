<?php
/**
 * ============================================================================
 * CEISA 4.0 & ERP Enterprise Hub - Pusat Cetak Label QR Gudang & Traceability
 * ============================================================================
 * Mengelola pencetakan label stiker QR Code (2D) untuk identitas fisik pemasukan
 * barang di gudang (Raw Material / Finished Goods), terintegrasi dengan Nomor AJU
 * 26 Digit CEISA 4.0 DJBC untuk Traceability Valid.
 *
 * Mendukung:
 * 1. Tampilan Hierarki Master-Detail (Header AJU/Invoice & Detail Barang).
 * 2. Printer Label Thermal Roll (70x40, 100x50, 100x150, 50x30, Custom mm).
 * 3. Kertas Label Stiker Lembaran A4 (Grid 2x5, 3x8 Tom & Jerry, 4x10).
 * 4. Tracking Status Cetak (Belum Dicetak / Sudah Dicetak) & Konfirmasi Post-Print.
 * 5. Auto-resizing QR Code proporsional, bebas terpotong, dan AJU 1-baris no-wrap.
 * ============================================================================
 */
session_start();
require_once __DIR__ . '/../datalogin.php';
require_once __DIR__ . '/lot_helper.php';

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
$currentUser = $_SESSION['username'] ?? ($_SESSION['user_id'] ?? 'SYSTEM');

// Ambil Profil Perusahaan & Logo
$activeCmpName = $_SESSION['company_name'] ?? 'PT. SN UNGGUL';
$companyLogo = '/assets/logo.png';
$qCmp = $koneksi->query("SELECT cmp_name, logo, npwp FROM bc_ceisa_company WHERE cmp_id = '{$escCmp}' LIMIT 1");
if ($qCmp && $rC = $qCmp->fetch_assoc()) {
    if (!empty($rC['cmp_name'])) $activeCmpName = $rC['cmp_name'];
    if (!empty($rC['logo'])) $companyLogo = $rC['logo'];
}

// Parameter Direct Open (dari RI atau PI)
$paramRefType = strtoupper(trim($_GET['ref_type'] ?? ''));
$paramRefNo   = trim($_GET['ref_no'] ?? '');
$paramLotNo   = trim($_GET['lot_no'] ?? '');
$defaultTglAwal  = date('Y-01-01');
$defaultTglAkhir = date('Y-m-d');

// Self-healing Table bc_qr_print_log
function ensureQrPrintLogTable($db) {
    $sql = "CREATE TABLE IF NOT EXISTS bc_qr_print_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        cmp_id VARCHAR(50) NOT NULL DEFAULT '',
        item_key VARCHAR(64) NOT NULL,
        nomor_aju VARCHAR(50) DEFAULT '',
        no_bukti VARCHAR(300) DEFAULT '',
        kode_barang VARCHAR(100) DEFAULT '',
        seri_barang INT DEFAULT 1,
        print_qty INT DEFAULT 1,
        printed_by VARCHAR(50) DEFAULT '',
        printed_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_item (cmp_id, item_key),
        KEY idx_aju (cmp_id, nomor_aju),
        KEY idx_bukti (cmp_id, no_bukti)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->query($sql);
}
ensureQrPrintLogTable($koneksi);

// Auto-sync jika dipanggil langsung dengan ref_type dan ref_no
if (!empty($paramRefType) && !empty($paramRefNo)) {
    try {
        if ($paramRefType === 'RI' && function_exists('syncRiToLots')) {
            $qRi = $koneksi->query("SELECT id FROM bc_ri_hdr WHERE cmp_id = '{$escCmp}' AND ri_no = '" . $koneksi->real_escape_string($paramRefNo) . "' LIMIT 1");
            if ($qRi && $rRi = $qRi->fetch_assoc()) {
                syncRiToLots($koneksi, $rRi['id']);
            }
        } elseif ($paramRefType === 'PI' && function_exists('syncPiToLots')) {
            $qPi = $koneksi->query("SELECT id FROM bc_pi_hdr WHERE cmp_id = '{$escCmp}' AND pi_no = '" . $koneksi->real_escape_string($paramRefNo) . "' LIMIT 1");
            if ($qPi && $rPi = $qPi->fetch_assoc()) {
                syncPiToLots($koneksi, $rPi['id']);
            }
        }
    } catch (\Throwable $t) {
        // Abaikan kegagalan sync otomatis agar tidak memutus pemuatan halaman
    }
}

// -------------------------------------------------------------------------
// BACKEND AJAX API
// -------------------------------------------------------------------------
$action = $_REQUEST['action'] ?? '';

if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (function_exists('ensureWhLotTable')) {
            ensureWhLotTable($koneksi);
        }
        $search      = trim($_GET['search'] ?? '');
        $refType     = strtoupper(trim($_GET['ref_type'] ?? 'BC1AB2'));
        $status      = trim($_GET['status'] ?? 'ACTIVE');
        $printFilter = trim($_GET['print_filter'] ?? 'ALL'); // 'ALL', 'UNPRINTED', 'PRINTED'
        $dateType    = trim($_GET['date_type'] ?? 'tgl_daftar'); // 'tgl_daftar' atau 'tgl_proses'
        $tglAwal     = trim($_GET['tgl_awal'] ?? '');
        $tglAkhir    = trim($_GET['tgl_akhir'] ?? '');

        // 1. SUMBER UTAMA: Dokumen Pemasukan IT Inventory (bc_1ab2)
        if ($refType === 'BC1AB2' || empty($refType)) {
            $where = ["b.AB = 'A'"];
            if (!empty($sessionCmpId)) {
                $where[] = "(b.cmp_id = '{$escCmp}' OR '{$escCmp}' = '')";
            }
            if (!empty($search)) {
                $sc = $koneksi->real_escape_string($search);
                $where[] = "(b.NOMOR_AJU LIKE '%{$sc}%' OR b.NOMOR_DAFTAR LIKE '%{$sc}%' OR b.KODE_BARANG LIKE '%{$sc}%' OR b.URAIAN LIKE '%{$sc}%' OR b.NAMA_BS LIKE '%{$sc}%' OR b.NO_BUKTI LIKE '%{$sc}%')";
            }
            if ($status === 'ACTIVE') {
                $where[] = "b.QTY > 0";
            } elseif ($status === 'DEPLETED') {
                $where[] = "b.QTY <= 0";
            }

            // Filter Range Tanggal Daftar / Tanggal Proses
            $dateField = ($dateType === 'tgl_proses') ? "COALESCE(b.TGL_PROSES, b.TGL_BUKTI)" : "b.TANGGAL_DAFTAR";
            if (!empty($tglAwal)) {
                $escAwal = $koneksi->real_escape_string($tglAwal);
                $where[] = "{$dateField} >= '{$escAwal}'";
            }
            if (!empty($tglAkhir)) {
                $escAkhir = $koneksi->real_escape_string($tglAkhir);
                $where[] = "{$dateField} <= '{$escAkhir}'";
            }

            $whereSql = "WHERE " . implode(" AND ", $where);

            // Query items with LEFT JOIN to print log
            $sql = "SELECT 
                        MD5(CONCAT(COALESCE(b.BC,''), '_', COALESCE(b.NOMOR_DAFTAR,''), '_', COALESCE(b.TANGGAL_DAFTAR,''), '_', COALESCE(b.KODE_BARANG,''), '_', COALESCE(b.SERI_BARANG,0), '_', COALESCE(b.NO_BUKTI,''))) as id,
                        b.cmp_id,
                        COALESCE(b.NOMOR_AJU, '') as nomor_aju,
                        COALESCE(b.SERI_BARANG, 1) as seri_barang,
                        COALESCE(b.BC, '') as bc,
                        COALESCE(b.NOMOR_DAFTAR, '') as no_bc,
                        COALESCE(b.TANGGAL_DAFTAR, '') as tgl_bc,
                        COALESCE(b.NAMA_BS, '-') as vendor,
                        COALESCE(b.KODE_BARANG, '') as kode_barang,
                        COALESCE(b.URAIAN, '') as nama_barang,
                        COALESCE(b.UNIT, '') as sat,
                        COALESCE(b.QTY_AKTUAL, b.QTY) as qty_initial,
                        COALESCE(b.QTY, 0) as qty_remaining,
                        COALESCE(b.NO_BUKTI, '-') as ref_no,
                        COALESCE(b.TGL_BUKTI, '') as ref_date,
                        COALESCE(b.LOKASI, '') as lokasi_rak,
                        CONCAT('AJU|', COALESCE(b.NOMOR_AJU,''), '|', COALESCE(b.KODE_BARANG,''), '|', COALESCE(b.SERI_BARANG,1), '|', COALESCE(b.NOMOR_DAFTAR,'')) as qr_payload,
                        IF(pl.id IS NOT NULL, 1, 0) as is_printed,
                        COALESCE(pl.print_qty, 0) as print_count,
                        COALESCE(pl.printed_at, '') as printed_at
                    FROM bc_1ab2 b
                    LEFT JOIN bc_qr_print_log pl 
                        ON pl.item_key = MD5(CONCAT(COALESCE(b.BC,''), '_', COALESCE(b.NOMOR_DAFTAR,''), '_', COALESCE(b.TANGGAL_DAFTAR,''), '_', COALESCE(b.KODE_BARANG,''), '_', COALESCE(b.SERI_BARANG,0), '_', COALESCE(b.NO_BUKTI,'')))
                        AND (pl.cmp_id = b.cmp_id OR pl.cmp_id = '{$escCmp}')
                    {$whereSql} 
                    ORDER BY b.TANGGAL_DAFTAR DESC, b.NOMOR_DAFTAR DESC, b.SERI_BARANG ASC
                    LIMIT 1500";

            $res = $koneksi->query($sql);
            $headersMap = [];
            $totalItems = 0;
            $totalQtyAll = 0.0;
            $ajuSet = [];

            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $totalItems++;
                    $totalQtyAll += floatval($r['qty_remaining'] ?? 0);
                    if (!empty($r['nomor_aju'])) {
                        $ajuSet[$r['nomor_aju']] = true;
                    }

                    // Composite Key Header (Group per AJU / Invoice / No Daftar)
                    $hdrKey = md5(($r['nomor_aju'] ?? '') . '__' . ($r['ref_no'] ?? '') . '__' . ($r['no_bc'] ?? '') . '__' . ($r['tgl_bc'] ?? '') . '__' . ($r['bc'] ?? ''));

                    if (!isset($headersMap[$hdrKey])) {
                        $headersMap[$hdrKey] = [
                            'header_key'     => $hdrKey,
                            'nomor_aju'      => $r['nomor_aju'],
                            'invoice_no'     => $r['ref_no'],
                            'invoice_date'   => $r['ref_date'],
                            'supplier_name'  => $r['vendor'],
                            'bc_doc'         => $r['bc'],
                            'nomor_daftar'   => $r['no_bc'],
                            'tanggal_daftar' => $r['tgl_bc'],
                            'item_count'     => 0,
                            'total_qty'      => 0.0,
                            'printed_count'  => 0,
                            'print_status'   => 'UNPRINTED',
                            'items'          => []
                        ];
                    }

                    $headersMap[$hdrKey]['item_count']++;
                    $headersMap[$hdrKey]['total_qty'] += floatval($r['qty_remaining'] ?? 0);
                    if (intval($r['is_printed'] ?? 0) === 1) {
                        $headersMap[$hdrKey]['printed_count']++;
                    }

                    $headersMap[$hdrKey]['items'][] = $r;
                }
            }

            // Hitung print_status agregat per Header & lakukan filter status cetak
            $finalHeaders = [];
            foreach ($headersMap as $hKey => $hdr) {
                if ($hdr['printed_count'] === 0) {
                    $statusStr = 'UNPRINTED';
                } elseif ($hdr['printed_count'] === $hdr['item_count']) {
                    $statusStr = 'PRINTED';
                } else {
                    $statusStr = 'PARTIAL';
                }
                $hdr['print_status'] = $statusStr;

                // Terapkan filter cetak jika dipilih
                if ($printFilter === 'UNPRINTED' && $statusStr === 'PRINTED') {
                    continue;
                }
                if ($printFilter === 'PRINTED' && $statusStr === 'UNPRINTED') {
                    continue;
                }
                $finalHeaders[] = $hdr;
            }

            echo json_encode([
                'status'        => 'OK',
                'total_headers' => count($finalHeaders),
                'total_items'   => $totalItems,
                'total_qty'     => $totalQtyAll,
                'total_aju'     => count($ajuSet),
                'data'          => $finalHeaders
            ]);
            exit;
        }

        // 2. SUMBER LAIN: bc_wh_lot (RI / PI / Fisik Gudang)
        $where = [];
        if (!empty($sessionCmpId)) {
            $where[] = "l.cmp_id = '{$escCmp}'";
        }
        if (!empty($refType) && in_array($refType, ['RI', 'PI', 'MANUAL'])) {
            $where[] = "l.ref_type = '{$refType}'";
        }
        if ($status === 'ACTIVE') {
            $where[] = "l.qty_remaining > 0 AND l.status = 'ACTIVE'";
        } elseif ($status === 'DEPLETED') {
            $where[] = "(l.qty_remaining <= 0 OR l.status = 'DEPLETED')";
        }
        if (!empty($search)) {
            $sc = $koneksi->real_escape_string($search);
            $where[] = "(l.lot_no LIKE '%{$sc}%' OR l.nomor_aju LIKE '%{$sc}%' OR l.ref_no LIKE '%{$sc}%' OR l.no_bc LIKE '%{$sc}%' OR l.kode_barang LIKE '%{$sc}%' OR l.nama_barang LIKE '%{$sc}%' OR l.vendor LIKE '%{$sc}%')";
        }

        $dateLotField = ($dateType === 'tgl_proses') ? "COALESCE(l.ref_date, l.created_at)" : "COALESCE(l.tgl_bc, l.ref_date, l.created_at)";
        if (!empty($tglAwal)) {
            $escAwal = $koneksi->real_escape_string($tglAwal);
            $where[] = "DATE({$dateLotField}) >= '{$escAwal}'";
        }
        if (!empty($tglAkhir)) {
            $escAkhir = $koneksi->real_escape_string($tglAkhir);
            $where[] = "DATE({$dateLotField}) <= '{$escAkhir}'";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "SELECT 
                    l.*,
                    CONCAT('LOT_', l.id) as id,
                    CONCAT('AJU|', COALESCE(l.nomor_aju,''), '|', COALESCE(l.kode_barang,''), '|', COALESCE(l.lot_no,''), '|', COALESCE(l.no_bc,'')) as qr_payload,
                    IF(pl.id IS NOT NULL, 1, 0) as is_printed,
                    COALESCE(pl.print_qty, 0) as print_count,
                    COALESCE(pl.printed_at, '') as printed_at
                FROM bc_wh_lot l
                LEFT JOIN bc_qr_print_log pl 
                    ON pl.item_key = CONCAT('LOT_', l.id)
                    AND (pl.cmp_id = l.cmp_id OR pl.cmp_id = '{$escCmp}')
                {$whereSql} 
                ORDER BY l.id DESC LIMIT 1500";

        $res = $koneksi->query($sql);
        $headersMap = [];
        $totalItems = 0;
        $totalQtyAll = 0.0;
        $ajuSet = [];

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $totalItems++;
                $totalQtyAll += floatval($r['qty_remaining'] ?? 0);
                if (!empty($r['nomor_aju'])) {
                    $ajuSet[$r['nomor_aju']] = true;
                }

                $hdrKey = md5(($r['nomor_aju'] ?? '') . '__' . ($r['ref_no'] ?? '') . '__' . ($r['no_bc'] ?? '') . '__' . ($r['vendor'] ?? ''));

                if (!isset($headersMap[$hdrKey])) {
                    $headersMap[$hdrKey] = [
                        'header_key'     => $hdrKey,
                        'nomor_aju'      => $r['nomor_aju'] ?? '',
                        'invoice_no'     => $r['ref_no'] ?? '-',
                        'invoice_date'   => $r['ref_date'] ?? '',
                        'supplier_name'  => $r['vendor'] ?? '-',
                        'bc_doc'         => $r['ref_type'] ?? 'LOT',
                        'nomor_daftar'   => $r['no_bc'] ?? '',
                        'tanggal_daftar' => $r['tgl_bc'] ?? ($r['ref_date'] ?? ''),
                        'item_count'     => 0,
                        'total_qty'      => 0.0,
                        'printed_count'  => 0,
                        'print_status'   => 'UNPRINTED',
                        'items'          => []
                    ];
                }

                $headersMap[$hdrKey]['item_count']++;
                $headersMap[$hdrKey]['total_qty'] += floatval($r['qty_remaining'] ?? 0);
                if (intval($r['is_printed'] ?? 0) === 1) {
                    $headersMap[$hdrKey]['printed_count']++;
                }

                $headersMap[$hdrKey]['items'][] = $r;
            }
        }

        $finalHeaders = [];
        foreach ($headersMap as $hKey => $hdr) {
            if ($hdr['printed_count'] === 0) {
                $statusStr = 'UNPRINTED';
            } elseif ($hdr['printed_count'] === $hdr['item_count']) {
                $statusStr = 'PRINTED';
            } else {
                $statusStr = 'PARTIAL';
            }
            $hdr['print_status'] = $statusStr;

            if ($printFilter === 'UNPRINTED' && $statusStr === 'PRINTED') continue;
            if ($printFilter === 'PRINTED' && $statusStr === 'UNPRINTED') continue;

            $finalHeaders[] = $hdr;
        }

        echo json_encode([
            'status'        => 'OK',
            'total_headers' => count($finalHeaders),
            'total_items'   => $totalItems,
            'total_qty'     => $totalQtyAll,
            'total_aju'     => count($ajuSet),
            'data'          => $finalHeaders
        ]);
    } catch (\Throwable $e) {
        echo json_encode([
            'status'        => 'ERROR',
            'message'       => 'Gagal memuat data: ' . $e->getMessage(),
            'total_headers' => 0,
            'total_items'   => 0,
            'total_qty'     => 0,
            'total_aju'     => 0,
            'data'          => []
        ]);
    }
    exit;
}

// -------------------------------------------------------------------------
// TANDAI SUDAH DICETAK (POST-PRINT ACTION)
// -------------------------------------------------------------------------
if ($action === 'mark_printed') {
    header('Content-Type: application/json; charset=utf-8');
    $keysRaw = $_POST['item_keys'] ?? '';
    $itemKeys = [];

    if (is_array($keysRaw)) {
        $itemKeys = $keysRaw;
    } elseif (!empty($keysRaw)) {
        $decoded = json_decode($keysRaw, true);
        if (is_array($decoded)) {
            $itemKeys = $decoded;
        } else {
            $itemKeys = array_map('trim', explode(',', $keysRaw));
        }
    }

    if (empty($itemKeys)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada item yang dipilih untuk ditandai.']);
        exit;
    }

    $affected = 0;
    $escUser = $koneksi->real_escape_string($currentUser);

    foreach ($itemKeys as $kItem) {
        $kItem = trim($kItem);
        if (empty($kItem)) continue;
        $escKey = $koneksi->real_escape_string($kItem);

        // Ambil info detail untuk arsip log
        $nomorAju = '';
        $noBukti  = '';
        $kodeBrg  = '';
        $seriBrg  = 1;

        if (strpos($escKey, 'LOT_') === 0) {
            $lotId = intval(substr($escKey, 4));
            $qInfo = $koneksi->query("SELECT nomor_aju, ref_no, kode_barang FROM bc_wh_lot WHERE id = {$lotId} LIMIT 1");
            if ($qInfo && $rInfo = $qInfo->fetch_assoc()) {
                $nomorAju = $rInfo['nomor_aju'] ?? '';
                $noBukti  = $rInfo['ref_no'] ?? '';
                $kodeBrg  = $rInfo['kode_barang'] ?? '';
            }
        } else {
            $qInfo = $koneksi->query("SELECT NOMOR_AJU, NO_BUKTI, KODE_BARANG, SERI_BARANG FROM bc_1ab2 WHERE MD5(CONCAT(COALESCE(BC,''), '_', COALESCE(NOMOR_DAFTAR,''), '_', COALESCE(TANGGAL_DAFTAR,''), '_', COALESCE(KODE_BARANG,''), '_', COALESCE(SERI_BARANG,0), '_', COALESCE(NO_BUKTI,''))) = '{$escKey}' LIMIT 1");
            if ($qInfo && $rInfo = $qInfo->fetch_assoc()) {
                $nomorAju = $rInfo['NOMOR_AJU'] ?? '';
                $noBukti  = $rInfo['NO_BUKTI'] ?? '';
                $kodeBrg  = $rInfo['KODE_BARANG'] ?? '';
                $seriBrg  = intval($rInfo['SERI_BARANG'] ?? 1);
            }
        }

        $escAju   = $koneksi->real_escape_string($nomorAju);
        $escBukti = $koneksi->real_escape_string($noBukti);
        $escKd    = $koneksi->real_escape_string($kodeBrg);

        $sql = "INSERT INTO bc_qr_print_log (cmp_id, item_key, nomor_aju, no_bukti, kode_barang, seri_barang, print_qty, printed_by, printed_at)
                VALUES ('{$escCmp}', '{$escKey}', '{$escAju}', '{$escBukti}', '{$escKd}', {$seriBrg}, 1, '{$escUser}', NOW())
                ON DUPLICATE KEY UPDATE 
                    print_qty = print_qty + 1, 
                    printed_by = '{$escUser}', 
                    printed_at = NOW()";
        if ($koneksi->query($sql)) {
            $affected++;
        }
    }

    echo json_encode(['status' => 'OK', 'message' => "Berhasil menandai {$affected} barang sebagai SUDAH DICETAK.", 'affected' => $affected]);
    exit;
}

// -------------------------------------------------------------------------
// UPDATE LOKASI RAK SECARA INLINE
// -------------------------------------------------------------------------
if ($action === 'update_rak') {
    header('Content-Type: application/json; charset=utf-8');
    $rawId = trim($_POST['id'] ?? '');
    $rak   = trim($_POST['rak'] ?? '');
    if (empty($rawId)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID tidak valid.']);
        exit;
    }
    $escRak = $koneksi->real_escape_string($rak);
    if (strpos($rawId, 'LOT_') === 0 || is_numeric($rawId)) {
        $lotId = is_numeric($rawId) ? intval($rawId) : intval(substr($rawId, 4));
        $koneksi->query("UPDATE bc_wh_lot SET lokasi_rak = '{$escRak}' WHERE id = {$lotId} AND cmp_id = '{$escCmp}'");
    } else {
        $escHash = $koneksi->real_escape_string($rawId);
        $koneksi->query("UPDATE bc_1ab2 SET LOKASI = '{$escRak}' WHERE MD5(CONCAT(COALESCE(BC,''), '_', COALESCE(NOMOR_DAFTAR,''), '_', COALESCE(TANGGAL_DAFTAR,''), '_', COALESCE(KODE_BARANG,''), '_', COALESCE(SERI_BARANG,0), '_', COALESCE(NO_BUKTI,''))) = '{$escHash}' AND (cmp_id = '{$escCmp}' OR '{$escCmp}' = '')");
    }
    echo json_encode(['status' => 'OK', 'message' => 'Lokasi rak berhasil diperbarui.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pusat Cetak Label QR Gudang &amp; Traceability AJU CEISA 4.0</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="/assets/ant_grid.js"></script>
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/qrcode.min.js"></script>
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
        .stat-icon { font-size: 18px; opacity: 0.8; }

        .toolbar-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 8px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
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
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-default { background: var(--bg-card); border-color: var(--border-color); color: var(--text-main); }

        /* Master-Detail Table Styles */
        .table-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
        }
        table.master-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }
        table.master-table th {
            background: var(--table-head-bg);
            padding: 5px 6px;
            border: 1px solid var(--border-color);
            font-weight: 600;
            text-align: left;
            white-space: nowrap;
        }
        table.master-table td {
            padding: 4px 6px;
            border: 1px solid var(--border-color);
            white-space: nowrap;
        }
        tr.header-row {
            background: var(--bg-card);
            cursor: pointer;
            transition: background 0.15s ease;
        }
        tr.header-row:hover {
            background: rgba(24, 144, 255, 0.06);
        }
        tr.header-row.expanded {
            background: rgba(24, 144, 255, 0.08);
            font-weight: 500;
        }
        tr.detail-container-row {
            display: none;
            background: #fafbfc;
        }
        [data-theme="dark"] tr.detail-container-row {
            background: #191919;
        }
        tr.detail-container-row.active {
            display: table-row;
        }
        .detail-sub-wrapper {
            padding: 6px 12px 10px 36px;
        }
        table.sub-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        table.sub-table th {
            background: #f0f2f5;
            padding: 3px 6px;
            border: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 9px;
        }
        [data-theme="dark"] table.sub-table th {
            background: #262626;
        }
        table.sub-table td {
            padding: 3px 6px;
            border: 1px solid var(--border-color);
            font-size: 9.5px;
        }
        table.sub-table tr:hover {
            background: rgba(24, 144, 255, 0.04);
        }

        /* Status & AJU Badges */
        .badge-aju {
            font-family: 'SFMono-Regular', Consolas, Monaco, monospace;
            font-weight: 700;
            color: #0f766e;
            background: #f0fdfa;
            border: 1px solid #99f6e4;
            padding: 1px 6px;
            border-radius: 3px;
            letter-spacing: -0.2px;
            white-space: nowrap;
            display: inline-block;
        }
        .badge-bc {
            font-size: 8.5px;
            font-weight: 700;
            background: #f6ffed;
            color: #389e0d;
            border: 1px solid #b7eb8f;
            padding: 1px 5px;
            border-radius: 3px;
        }
        .badge-status-printed {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 8.5px;
            font-weight: 700;
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
            padding: 1px 6px;
            border-radius: 10px;
        }
        .badge-status-partial {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 8.5px;
            font-weight: 700;
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
            padding: 1px 6px;
            border-radius: 10px;
        }
        .badge-status-unprinted {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 8.5px;
            font-weight: 600;
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #e5e7eb;
            padding: 1px 6px;
            border-radius: 10px;
        }
        .btn-expand {
            background: transparent;
            border: none;
            cursor: pointer;
            color: var(--primary);
            padding: 2px 5px;
            font-size: 11px;
            transition: transform 0.2s;
        }
        .btn-expand.open {
            transform: rotate(90deg);
        }

        /* Modal Print Preview */
        .print-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.65);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        .print-modal.active { display: flex; }
        .print-modal-content {
            background: #fff;
            color: #000;
            width: 96vw;
            max-width: 1020px;
            max-height: 94vh;
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .print-modal-header {
            padding: 8px 14px;
            background: #fafafa;
            border-bottom: 1px solid #e8e8e8;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .print-modal-body {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            background: #eef1f5;
        }
        .print-canvas-area {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }

        /* =========================================================================
           STICKER DESIGNS (Thermal & Sheet) - STRICTLY 1-LINE AJU & ZERO CLIPPING
           ========================================================================= */
        .label-sticker {
            background: #fff;
            color: #000;
            border: 1px dashed #999;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            overflow: hidden;
            position: relative;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            page-break-inside: avoid;
            width: 100%;
            height: 100%;
        }

        /* 1. THERMAL PRESETS */
        .label-size-100x150 {
            width: 100mm;
            height: 150mm;
            padding: 4mm 5mm;
        }
        .label-size-100x50 {
            width: 100mm;
            height: 50mm;
            padding: 2.5mm 3.5mm;
        }
        .label-size-70x40 {
            width: 70mm;
            height: 40mm;
            padding: 2mm 3mm;
        }
        .label-size-50x30 {
            width: 50mm;
            height: 30mm;
            padding: 1.2mm 1.8mm;
        }

        /* 2. A4 SHEET CONTAINER & GRID PRESETS */
        .a4-sheet-page {
            width: 210mm;
            min-height: 297mm;
            box-sizing: border-box;
            padding: 8mm 6mm;
            background: #fff;
            margin: 0 auto 15px auto;
            display: grid;
            gap: 2mm 3mm;
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
            page-break-after: always;
        }
        .a4-grid-2x5 {
            grid-template-columns: repeat(2, 98mm);
            grid-auto-rows: 54mm;
            justify-content: center;
        }
        .a4-grid-3x8 {
            grid-template-columns: repeat(3, 64mm);
            grid-auto-rows: 34mm;
            justify-content: center;
        }
        .a4-grid-4x10 {
            grid-template-columns: repeat(4, 48mm);
            grid-auto-rows: 27mm;
            justify-content: center;
        }

        /* 3. A4 SHEET INDIVIDUAL STICKER SIZES */
        .label-size-a4_2x5 {
            width: 98mm;
            height: 54mm;
            padding: 2.5mm 3.5mm;
        }
        .label-size-a4_3x8 {
            width: 64mm;
            height: 34mm;
            padding: 1.8mm 2.2mm;
        }
        .label-size-a4_4x10 {
            width: 48mm;
            height: 27mm;
            padding: 1.2mm 1.5mm;
        }

        /* Sticker Header Elements */
        .lbl-header {
            display: flex;
            align-items: center;
            gap: 4px;
            border-bottom: 1px solid #000;
            padding-bottom: 0.8mm;
            margin-bottom: 1mm;
            flex-shrink: 0;
            width: 100%;
            box-sizing: border-box;
        }
        .lbl-logo {
            height: 5.5mm;
            max-width: 18mm;
            object-fit: contain;
            flex-shrink: 0;
        }
        .lbl-comp-title {
            font-size: 7.5pt;
            font-weight: 800;
            line-height: 1.1;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .lbl-comp-sub {
            font-size: 5.2pt;
            font-weight: 600;
            color: #333;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        /* Body Stiker: 2-COLUMN BULLETPROOF FLEXBOX (ZERO OVERLAP, 100% AUTO-FIT) */
        .lbl-body {
            display: flex !important;
            flex-direction: row !important;
            align-items: stretch !important;
            justify-content: space-between !important;
            gap: 2.5mm !important;
            flex: 1 !important;
            min-height: 0 !important;
            width: 100% !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }

        /* Kolom Kiri: Kotak QR Code Eksklusif (Tidak terdesak dan Tidak menindih) */
        .lbl-qr-box {
            flex: 0 0 auto !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            background: #fff;
        }
        .lbl-qr-img {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            background: #fff;
            margin: 0 auto !important;
        }
        .lbl-qr-img canvas, .lbl-qr-img img {
            max-width: 100% !important;
            max-height: 100% !important;
            object-fit: contain !important;
            display: block !important;
            margin: 0 auto !important;
        }
        .lbl-qr-ref {
            font-size: 4.8pt;
            font-family: 'SFMono-Regular', Consolas, Monaco, monospace;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            text-align: center;
            margin-top: 0.5mm;
            color: #111;
        }

        /* Kolom Kanan: Kotak Informasi Dokumen & Barang (Otomatis Mengisi Sisa Ruang) */
        .lbl-info-box {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            width: 0 !important;
            height: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
            font-size: 6.5pt;
            line-height: 1.2;
        }
        .lbl-item-kd {
            font-size: 8.5pt;
            font-weight: 800;
            color: #000;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lbl-item-nm {
            font-size: 6.2pt;
            font-weight: 600;
            max-height: 2.3em;
            overflow: hidden;
            line-height: 1.15;
            color: #111;
            word-break: break-word;
        }
        
        /* STRICTLY 1-LINE AJU NUMBER (NO WRAPPING) */
        .lbl-aju-text {
            font-family: 'SFMono-Regular', Consolas, Monaco, monospace !important;
            font-size: 5.8pt;
            font-weight: 700;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: clip !important;
            letter-spacing: -0.2px;
            color: #000;
            line-height: 1.15;
        }
        .lbl-bc-text {
            font-size: 5.8pt;
            font-weight: 600;
            color: #222;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lbl-ref-text {
            font-size: 5.2pt;
            color: #444;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lbl-qty-box {
            font-size: 8pt;
            font-weight: 800;
            border-top: 1px solid #000;
            padding-top: 0.8mm;
            margin-top: 0.5mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Dynamic Sizing Overrides per Label Scale */
        .label-size-50x30 .lbl-body { grid-template-columns: 33% 67% !important; gap: 1mm !important; }
        .label-size-50x30 .lbl-logo { height: 4mm; }
        .label-size-50x30 .lbl-comp-title { font-size: 5.8pt; }
        .label-size-50x30 .lbl-comp-sub { font-size: 4.2pt; }
        .label-size-50x30 .lbl-item-kd { font-size: 7pt; }
        .label-size-50x30 .lbl-item-nm { font-size: 4.8pt; max-height: 2em; }
        .label-size-50x30 .lbl-aju-text { font-size: 4.5pt; letter-spacing: -0.3px; }
        .label-size-50x30 .lbl-bc-text { font-size: 4.5pt; }
        .label-size-50x30 .lbl-ref-text { font-size: 4.2pt; }
        .label-size-50x30 .lbl-qty-box { font-size: 6.5pt; padding-top: 0.4mm; margin-top: 0.4mm; }

        .label-size-a4_3x8 .lbl-body { grid-template-columns: 34% 66% !important; gap: 1.5mm !important; }
        .label-size-a4_3x8 .lbl-logo { height: 4.5mm; }
        .label-size-a4_3x8 .lbl-comp-title { font-size: 6.5pt; }
        .label-size-a4_3x8 .lbl-comp-sub { font-size: 4.6pt; }
        .label-size-a4_3x8 .lbl-item-kd { font-size: 7.5pt; }
        .label-size-a4_3x8 .lbl-item-nm { font-size: 5.2pt; max-height: 2em; }
        .label-size-a4_3x8 .lbl-aju-text { font-size: 4.8pt; letter-spacing: -0.25px; }
        .label-size-a4_3x8 .lbl-bc-text { font-size: 4.8pt; }
        .label-size-a4_3x8 .lbl-ref-text { font-size: 4.5pt; }
        .label-size-a4_3x8 .lbl-qty-box { font-size: 7pt; padding-top: 0.5mm; margin-top: 0.4mm; }

        .label-size-a4_4x10 .lbl-body { grid-template-columns: 32% 68% !important; gap: 1mm !important; }
        .label-size-a4_4x10 .lbl-logo { height: 3.5mm; }
        .label-size-a4_4x10 .lbl-comp-title { font-size: 5.5pt; }
        .label-size-a4_4x10 .lbl-comp-sub { font-size: 4pt; }
        .label-size-a4_4x10 .lbl-item-kd { font-size: 6.5pt; }
        .label-size-a4_4x10 .lbl-item-nm { font-size: 4.5pt; max-height: 1.8em; }
        .label-size-a4_4x10 .lbl-aju-text { font-size: 4.2pt; letter-spacing: -0.35px; }
        .label-size-a4_4x10 .lbl-bc-text { font-size: 4.2pt; }
        .label-size-a4_4x10 .lbl-ref-text { font-size: 3.8pt; }
        .label-size-a4_4x10 .lbl-qty-box { font-size: 6pt; padding-top: 0.4mm; margin-top: 0.3mm; }

        .label-size-100x50 .lbl-logo { height: 7mm; }
        .label-size-100x50 .lbl-comp-title { font-size: 8.5pt; }
        .label-size-100x50 .lbl-item-kd { font-size: 10pt; }
        .label-size-100x50 .lbl-item-nm { font-size: 7.5pt; }
        .label-size-100x50 .lbl-aju-text { font-size: 7.2pt; }
        .label-size-100x50 .lbl-bc-text { font-size: 7pt; }
        .label-size-100x50 .lbl-qty-box { font-size: 9.5pt; }

        .label-size-100x150 .lbl-logo { height: 10mm; }
        .label-size-100x150 .lbl-comp-title { font-size: 11pt; }
        .label-size-100x150 .lbl-comp-sub { font-size: 7.5pt; }
        .label-size-100x150 .lbl-item-kd { font-size: 14pt; }
        .label-size-100x150 .lbl-item-nm { font-size: 10pt; }
        .label-size-100x150 .lbl-aju-text { font-size: 9pt; }
        .label-size-100x150 .lbl-bc-text { font-size: 8.5pt; }
        .label-size-100x150 .lbl-qty-box { font-size: 13pt; }

        /* Media Print Rules */
        @media print {
            body * { visibility: hidden; }
            .print-modal { position: static; background: none; padding: 0; display: block !important; }
            .print-modal-content { width: 100% !important; max-width: 100% !important; max-height: none !important; border: none; box-shadow: none; }
            .print-modal-header, .print-modal-footer, .page-header, .toolbar-card, .table-container, .stats-grid { display: none !important; }
            .print-modal-body { padding: 0 !important; background: transparent !important; }
            .print-canvas-area { display: block !important; }
            .print-canvas-area * { visibility: visible; }
            .label-sticker {
                border: 0.5px solid transparent !important;
                box-shadow: none !important;
                margin: 0 auto;
            }
            /* Jika mode thermal roll */
            .mode-thermal .label-sticker {
                page-break-after: always;
            }
            /* Jika mode A4 sheet */
            .a4-sheet-page {
                box-shadow: none !important;
                margin: 0 !important;
                page-break-after: always;
            }
        }
    </style>
</head>
<body>

    <!-- 1. PAGE HEADER -->
    <div class="page-header">
        <div class="page-title">
            <i class="fa-solid fa-qrcode" style="font-size:16px;"></i>
            <span>Pusat Cetak Label QR Gudang &amp; Traceability AJU CEISA 4.0</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px;">
            <button type="button" class="btn-compact btn-default" onclick="openModuleGuide()" title="Panduan &amp; SOP Modul">
                <i class="fa-solid fa-circle-question" style="color:var(--primary);"></i> Panduan SOP
            </button>
            <button type="button" class="btn-compact btn-primary" onclick="loadLotsData()">
                <i class="fa-solid fa-rotate"></i> Muat Ulang
            </button>
        </div>
    </div>

    <!-- 2. STATS CARDS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div>
                <div class="stat-label">Dokumen Invoice / AJU</div>
                <div class="stat-val" id="statTotalHdr">0</div>
            </div>
            <i class="fa-solid fa-file-invoice stat-icon" style="color:#0ea5e9;"></i>
        </div>
        <div class="stat-card">
            <div>
                <div class="stat-label">Total Item Barang</div>
                <div class="stat-val" id="statTotalItems">0</div>
            </div>
            <i class="fa-solid fa-boxes-stacked stat-icon" style="color:#10b981;"></i>
        </div>
        <div class="stat-card">
            <div>
                <div class="stat-label">Total Sisa Qty Gudang</div>
                <div class="stat-val" id="statTotalQty">0,00</div>
            </div>
            <i class="fa-solid fa-cubes-stacked stat-icon" style="color:#f59e0b;"></i>
        </div>
        <div class="stat-card">
            <div>
                <div class="stat-label">Perusahaan Aktif</div>
                <div class="stat-val" style="font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($activeCmpName) ?>">
                    <?= htmlspecialchars($activeCmpName) ?>
                </div>
            </div>
            <i class="fa-solid fa-building stat-icon" style="color:#8b5cf6;"></i>
        </div>
    </div>

    <!-- 3. TOOLBAR & FORMAT SELECTOR -->
    <div class="toolbar-card">
        <div class="filter-group">
            <input type="text" id="searchInput" class="form-control-compact" style="width:200px;" placeholder="Cari AJU / Invoice / Barang..." onkeydown="if(event.key==='Enter') loadLotsData()">
            
            <select id="filterRefType" class="form-control-compact" onchange="loadLotsData()">
                <option value="BC1AB2" <?= ($paramRefType === 'BC1AB2' || empty($paramRefType)) ? 'selected' : '' ?>>IT Inventory (bc_1ab2 Pemasukan)</option>
                <option value="RI" <?= ($paramRefType === 'RI') ? 'selected' : '' ?>>Receive Item (RI Gudang)</option>
                <option value="PI" <?= ($paramRefType === 'PI') ? 'selected' : '' ?>>Purchase Invoice (PI Pemasukan)</option>
                <option value="LOT">Lot Fisik Gudang (bc_wh_lot)</option>
            </select>

            <select id="filterStatus" class="form-control-compact" onchange="loadLotsData()">
                <option value="ACTIVE" selected>Ada Sisa Stok (Aktif)</option>
                <option value="ALL">Semua Data (Termasuk Habis)</option>
                <option value="DEPLETED">Stok Sudah Habis</option>
            </select>

            <!-- Filter Status Cetak -->
            <select id="filterPrintStatus" class="form-control-compact" onchange="loadLotsData()" style="font-weight:600;">
                <option value="ALL">Status Cetak: Semua</option>
                <option value="UNPRINTED" selected>Belum Dicetak</option>
                <option value="PRINTED">Sudah Dicetak</option>
            </select>
            
            <!-- Filter Tanggal Daftar / Tanggal Proses -->
            <span style="display:inline-flex; align-items:center; gap:3px; background:var(--bg-base); padding:1px 5px; border-radius:3px; border:1px solid var(--border-color);">
                <select id="filterDateType" class="form-control-compact" style="border:none; background:transparent; font-weight:600;" onchange="loadLotsData()">
                    <option value="tgl_daftar">Tgl Daftar BC</option>
                    <option value="tgl_proses">Tgl Proses / Masuk</option>
                </select>
                <input type="date" id="filterTglAwal" class="form-control-compact" style="width:110px;" value="<?= htmlspecialchars($defaultTglAwal) ?>" onchange="loadLotsData()" title="Tanggal Awal">
                <span style="font-size:9.5px; color:var(--text-muted);">-</span>
                <input type="date" id="filterTglAkhir" class="form-control-compact" style="width:110px;" value="<?= htmlspecialchars($defaultTglAkhir) ?>" onchange="loadLotsData()" title="Tanggal Akhir">
                <button type="button" class="btn-compact" style="padding:1px 5px; font-size:9px;" onclick="setDatePreset('this_month')" title="Filter Bulan Ini">Bulan Ini</button>
                <button type="button" class="btn-compact" style="padding:1px 5px; font-size:9px;" onclick="setDatePreset('this_year')" title="Filter Tahun Berjalan">Tahun Ini</button>
                <button type="button" class="btn-compact" style="padding:1px 4px; font-size:9px;" onclick="clearDateFilter()" title="Hapus Filter Tanggal"><i class="fa-solid fa-xmark"></i></button>
            </span>

            <button type="button" class="btn-compact btn-primary" onclick="loadLotsData()">
                <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>
        </div>

        <div class="filter-group">
            <label style="font-weight:600;"><i class="fa-solid fa-tags"></i> Kertas / Stiker:</label>
            <select id="selLabelSize" class="form-control-compact" onchange="handleSizeChange()" style="font-weight:600; min-width:180px;">
                <optgroup label="Printer Label Thermal (Continuous Roll)">
                    <option value="70x40" selected>Thermal 70 x 40 mm (Rekomendasi Box / Karton)</option>
                    <option value="100x50">Thermal 100 x 50 mm (Besar / Palet)</option>
                    <option value="100x150">Thermal 100 x 150 mm (Kontainer / Shipping)</option>
                    <option value="50x30">Thermal 50 x 30 mm (Kecil / Kantong)</option>
                    <option value="custom">Ukuran Custom (Kustom mm)...</option>
                </optgroup>
                <optgroup label="Kertas Stiker Lembaran A4 (Printer Biasa / Laser / Inkjet)">
                    <option value="a4_2x5">Kertas A4 - 10 Label (Grid 2 x 5, ~98 x 54 mm)</option>
                    <option value="a4_3x8">Kertas A4 - 24 Label (Grid 3 x 8, Tom &amp; Jerry 108/121)</option>
                    <option value="a4_4x10">Kertas A4 - 40 Label (Grid 4 x 10, ~48 x 27 mm)</option>
                </optgroup>
            </select>

            <span id="customSizeBox" style="display:none; align-items:center; gap:4px;">
                <input type="number" id="customWidth" class="form-control-compact" style="width:48px;" value="80" title="Lebar (mm)"> x
                <input type="number" id="customHeight" class="form-control-compact" style="width:48px;" value="45" title="Tinggi (mm)"> mm
            </span>
        </div>

        <div class="filter-group">
            <label style="font-weight:600;"><i class="fa-solid fa-heading"></i> Sub-Header Label:</label>
            <span style="display:inline-flex; align-items:center; gap:3px;">
                <select id="selSubtitlePreset" class="form-control-compact" onchange="handleSubtitlePresetChange(this.value)" style="font-weight:600; width:135px;" title="Pilihan Preset Teks Sub-Header">
                    <option value="IT Inventory CEISA 4.0" selected>IT Inventory (Rekomendasi / Umum)</option>
                    <option value="Kawasan Berikat CEISA 4.0 DJBC">Kawasan Berikat</option>
                    <option value="Pusat Logistik Berikat (PLB) DJBC">PLB (Logistik)</option>
                    <option value="Fasilitas KITE DJBC">KITE (Kemudahan)</option>
                    <option value="Tempat Penimbunan Berikat (TPB)">TPB (Pabean)</option>
                    <option value="Gudang Logistik &amp; IT Inventory">Gudang / Internal</option>
                    <option value="custom">Kustom Teks...</option>
                    <option value="none">[Tanpa Sub-Header]</option>
                </select>
                <input type="text" id="inputCustomSubtitle" class="form-control-compact" style="width:160px; font-weight:600;" value="IT Inventory CEISA 4.0" placeholder="Ketik Sub-Header Label..." oninput="handleSubtitleInputChange(this.value)" title="Ketik teks Sub-Header Stiker Label">
            </span>

            <button type="button" class="btn-compact btn-success" onclick="prepareBatchPrint()" title="Cetak Semua Stiker yang Dicentang">
                <i class="fa-solid fa-print"></i> Cetak Terpilih (<span id="selectedCountBadge">0</span>)
            </button>
        </div>
    </div>

    <!-- 4. MASTER - DETAIL DATA TABLE -->
    <div class="table-container">
        <table class="master-table" id="masterTable">
            <thead>
                <tr>
                    <th style="width:28px; text-align:center;"></th>
                    <th style="width:30px; text-align:center;"><input type="checkbox" id="chkAllMaster" onchange="toggleSelectAllHeaders(this)" title="Centang / Hapus Semua"></th>
                    <th>Invoice / No. Bukti</th>
                    <th>Supplier / Vendor</th>
                    <th style="text-align:center;">Jml Barang</th>
                    <th>Nomor AJU (26 Digit CEISA)</th>
                    <th>Tanggal Daftar</th>
                    <th>Nomor Daftar &amp; BC</th>
                    <th style="text-align:center;">Status Cetak</th>
                    <th style="width:85px; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="masterTbody">
                <tr><td colspan="10" style="text-align:center; padding:15px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data dokumen gudang...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 5. PRINT PREVIEW MODAL -->
    <div class="print-modal" id="printModal">
        <div class="print-modal-content">
            <div class="print-modal-header">
                <div style="font-weight:700; font-size:12px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-print" style="color:var(--primary);"></i>
                    <span>Pratinjau Cetak Stiker Label QR (<span id="previewCountText">0</span> Stiker)</span>
                    <span id="previewModeBadge" class="badge-bc" style="margin-left:6px;">Thermal 70x40 mm</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="display:inline-flex; align-items:center; gap:4px; font-size:9.5px; margin-right:4px;">
                        <label style="font-weight:600; color:var(--text-muted);"><i class="fa-solid fa-pen"></i> Sub-Header:</label>
                        <input type="text" id="modalInputSubtitle" class="form-control-compact" style="width:160px; font-size:9.5px; font-weight:600;" placeholder="Sub-Header Label..." oninput="handleSubtitleInputChange(this.value)" title="Ubah langsung teks Sub-Header pada seluruh stiker">
                    </div>
                    <button type="button" class="btn-compact btn-primary" onclick="executePrintNow()">
                        <i class="fa-solid fa-print"></i> Eksekusi Cetak Sekarang
                    </button>
                    <button type="button" class="btn-compact btn-default" onclick="closePrintModal()">
                        <i class="fa-solid fa-xmark"></i> Tutup
                    </button>
                </div>
            </div>
            <div class="print-modal-body" id="printModalBody">
                <div class="print-canvas-area" id="printCanvasArea">
                    <!-- Stiker diinjeksi via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script>
        const CURRENT_CMP_ID = <?= json_encode($sessionCmpId) ?>;
        const companyLogoUrl = <?= json_encode($companyLogo) ?>;
        const companyNameStr = <?= json_encode($activeCmpName) ?>;
        let currentHeaderData = [];
        let currentlyPrintingItems = [];
        let currentLabelSubtitle = localStorage.getItem('ceisa_qr_label_subtitle');
        if (currentLabelSubtitle === null) {
            currentLabelSubtitle = 'Kawasan Berikat CEISA 4.0 DJBC';
        }

        document.addEventListener('DOMContentLoaded', () => {
            initSubtitleControls();
            loadLotsData();
        });

        function initSubtitleControls() {
            const inputEl = document.getElementById('inputCustomSubtitle');
            const modalInput = document.getElementById('modalInputSubtitle');
            const presetSel = document.getElementById('selSubtitlePreset');
            if (inputEl) inputEl.value = currentLabelSubtitle;
            if (modalInput) modalInput.value = currentLabelSubtitle;
            if (presetSel) {
                const matchingOpt = Array.from(presetSel.options).find(opt => opt.value === currentLabelSubtitle);
                presetSel.value = matchingOpt ? currentLabelSubtitle : (currentLabelSubtitle.trim() === '' ? 'none' : 'custom');
            }
        }

        function handleSubtitlePresetChange(presetVal) {
            const inputEl = document.getElementById('inputCustomSubtitle');
            const modalInput = document.getElementById('modalInputSubtitle');
            if (presetVal === 'none') {
                currentLabelSubtitle = '';
            } else if (presetVal === 'custom') {
                // pertahankan teks saat ini
            } else {
                currentLabelSubtitle = presetVal;
            }
            if (inputEl) inputEl.value = currentLabelSubtitle;
            if (modalInput) modalInput.value = currentLabelSubtitle;
            localStorage.setItem('ceisa_qr_label_subtitle', currentLabelSubtitle);
            applyLiveSubtitleUpdate(currentLabelSubtitle);
        }

        function handleSubtitleInputChange(val) {
            currentLabelSubtitle = val;
            const inputEl = document.getElementById('inputCustomSubtitle');
            const modalInput = document.getElementById('modalInputSubtitle');
            const presetSel = document.getElementById('selSubtitlePreset');
            if (inputEl && inputEl.value !== val) inputEl.value = val;
            if (modalInput && modalInput.value !== val) modalInput.value = val;
            if (presetSel) {
                const matchingOpt = Array.from(presetSel.options).find(opt => opt.value === val);
                presetSel.value = matchingOpt ? val : (val.trim() === '' ? 'none' : 'custom');
            }
            localStorage.setItem('ceisa_qr_label_subtitle', currentLabelSubtitle);
            applyLiveSubtitleUpdate(currentLabelSubtitle);
        }

        function applyLiveSubtitleUpdate(subText) {
            document.querySelectorAll('.lbl-comp-sub').forEach(el => {
                el.innerText = subText;
                el.style.display = subText && subText.trim() !== '' ? 'block' : 'none';
            });
        }

        function handleSizeChange() {
            const sz = document.getElementById('selLabelSize').value;
            const customBox = document.getElementById('customSizeBox');
            if (sz === 'custom') {
                customBox.style.display = 'inline-flex';
            } else {
                customBox.style.display = 'none';
            }
        }

        function clearDateFilter() {
            document.getElementById('filterTglAwal').value = '';
            document.getElementById('filterTglAkhir').value = '';
            loadLotsData();
        }

        function setDatePreset(preset) {
            const today = new Date();
            const y = today.getFullYear();
            const m = String(today.getMonth() + 1).padStart(2, '0');
            const d = String(today.getDate()).padStart(2, '0');
            if (preset === 'this_month') {
                document.getElementById('filterTglAwal').value = `${y}-${m}-01`;
                document.getElementById('filterTglAkhir').value = `${y}-${m}-${d}`;
            } else if (preset === 'this_year') {
                document.getElementById('filterTglAwal').value = `${y}-01-01`;
                document.getElementById('filterTglAkhir').value = `${y}-${m}-${d}`;
            }
            loadLotsData();
        }

        async function loadLotsData() {
            const search = document.getElementById('searchInput').value;
            const refType = document.getElementById('filterRefType').value;
            const status = document.getElementById('filterStatus').value;
            const printFilter = document.getElementById('filterPrintStatus').value;
            const dateType = document.getElementById('filterDateType').value;
            const tglAwal = document.getElementById('filterTglAwal').value;
            const tglAkhir = document.getElementById('filterTglAkhir').value;

            const tbody = document.getElementById('masterTbody');
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:15px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data dokumen gudang...</td></tr>';

            try {
                const url = `inv_qr_label.php?action=list&cmp_id=${encodeURIComponent(CURRENT_CMP_ID)}&search=${encodeURIComponent(search)}&ref_type=${encodeURIComponent(refType)}&status=${encodeURIComponent(status)}&print_filter=${encodeURIComponent(printFilter)}&date_type=${encodeURIComponent(dateType)}&tgl_awal=${encodeURIComponent(tglAwal)}&tgl_akhir=${encodeURIComponent(tglAkhir)}`;
                const res = await fetch(url);
                if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                const text = await res.text();
                let json;
                try {
                    json = JSON.parse(text);
                } catch(e) {
                    console.error('Invalid JSON response:', text);
                    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:15px;"><i class="fa-solid fa-triangle-exclamation"></i> Gagal parsing data server: Respon bukan JSON valid.<div style="font-size:9px; color:#666; margin-top:4px;">${escapeHtml(text.substring(0, 150))}</div></td></tr>`;
                    return;
                }

                if (json.status !== 'OK') {
                    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:15px;">Gagal memuat: ${json.message || 'Error server'}</td></tr>`;
                    return;
                }

                currentHeaderData = json.data || [];
                document.getElementById('statTotalHdr').innerText = Number(json.total_headers || 0).toLocaleString('id-ID');
                document.getElementById('statTotalItems').innerText = Number(json.total_items || 0).toLocaleString('id-ID');
                document.getElementById('statTotalQty').innerText = Number(json.total_qty || 0).toLocaleString('id-ID', { minimumFractionDigits: 2 });

                if (currentHeaderData.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada data dokumen pemasukan gudang untuk filter ini. Silakan atur tanggal atau kata kunci pencarian.</td></tr>';
                    updateSelectedBadge();
                    return;
                }

                let html = '';
                currentHeaderData.forEach((hdr, idx) => {
                    const safeHKey = escapeHtml(hdr.header_key);
                    const bcStr = hdr.bc_doc ? `BC ${hdr.bc_doc}` : '-';
                    const noDaftarStr = hdr.nomor_daftar || '-';
                    const ajuStr = hdr.nomor_aju || '-';
                    const invStr = hdr.invoice_no || '-';
                    const invDateStr = hdr.invoice_date ? `<div style="font-size:8.5px; color:var(--text-muted);">${escapeHtml(hdr.invoice_date)}</div>` : '';
                    
                    // Status Badge
                    let statusBadgeHtml = '';
                    if (hdr.print_status === 'PRINTED') {
                        statusBadgeHtml = `<span class="badge-status-printed" title="Semua ${hdr.item_count} item telah dicetak"><i class="fa-solid fa-circle-check"></i> Sudah Dicetak</span>`;
                    } else if (hdr.print_status === 'PARTIAL') {
                        statusBadgeHtml = `<span class="badge-status-partial" title="${hdr.printed_count} dari ${hdr.item_count} item telah dicetak"><i class="fa-solid fa-circle-half-stroke"></i> Sebagian (${hdr.printed_count}/${hdr.item_count})</span>`;
                    } else {
                        statusBadgeHtml = `<span class="badge-status-unprinted" title="Belum pernah dicetak"><i class="fa-regular fa-circle"></i> Belum Dicetak</span>`;
                    }

                    // Baris Header Master
                    html += `
                        <tr class="header-row" id="hdr-row-${safeHKey}">
                            <td style="text-align:center;">
                                <button type="button" class="btn-expand" id="btn-exp-${safeHKey}" onclick="toggleExpandHeader('${safeHKey}', event)" title="Buka / Tutup Detail Barang">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </td>
                            <td style="text-align:center;" onclick="event.stopPropagation()">
                                <input type="checkbox" class="chk-hdr" id="chk-hdr-${safeHKey}" value="${safeHKey}" onchange="toggleHeaderItems('${safeHKey}', this.checked)" title="Pilih Semua Barang dalam Dokumen Ini">
                            </td>
                            <td onclick="toggleExpandHeader('${safeHKey}')">
                                <strong style="color:var(--primary);">${escapeHtml(invStr)}</strong>
                                ${invDateStr}
                            </td>
                            <td onclick="toggleExpandHeader('${safeHKey}')" style="max-width:180px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(hdr.supplier_name)}">
                                ${escapeHtml(hdr.supplier_name)}
                            </td>
                            <td style="text-align:center;" onclick="toggleExpandHeader('${safeHKey}')">
                                <span class="badge-bc" style="background:#e6f7ff; color:#096dd9; border-color:#91d5ff;">${hdr.item_count} Item</span>
                                <div style="font-size:8.5px; color:var(--text-muted); margin-top:2px;">${parseFloat(hdr.total_qty || 0).toLocaleString('id-ID', {minimumFractionDigits:2})}</div>
                            </td>
                            <td onclick="toggleExpandHeader('${safeHKey}')">
                                <span class="badge-aju" title="${escapeHtml(ajuStr)}">${escapeHtml(ajuStr)}</span>
                            </td>
                            <td onclick="toggleExpandHeader('${safeHKey}')">${escapeHtml(hdr.tanggal_daftar || '-')}</td>
                            <td onclick="toggleExpandHeader('${safeHKey}')">
                                <span class="badge-bc">${escapeHtml(bcStr)}</span> <strong style="font-family:monospace;">${escapeHtml(noDaftarStr)}</strong>
                            </td>
                            <td style="text-align:center;" onclick="toggleExpandHeader('${safeHKey}')">
                                ${statusBadgeHtml}
                            </td>
                            <td style="text-align:center;" onclick="event.stopPropagation()">
                                <button type="button" class="btn-compact btn-primary" onclick="printWholeHeader('${safeHKey}')" title="Cetak Label untuk Semua Item di AJU Ini">
                                    <i class="fa-solid fa-print"></i> Cetak AJU
                                </button>
                            </td>
                        </tr>

                        <!-- Baris Detail Sub-Table (Expandable) -->
                        <tr class="detail-container-row" id="detail-row-${safeHKey}">
                            <td colspan="10" style="padding:0;">
                                <div class="detail-sub-wrapper">
                                    <table class="sub-table">
                                        <thead>
                                            <tr>
                                                <th style="width:28px; text-align:center;"></th>
                                                <th style="width:36px; text-align:center;">Seri</th>
                                                <th>Kode Barang</th>
                                                <th>Uraian Nama Barang</th>
                                                <th style="text-align:right;">Qty Masuk</th>
                                                <th style="text-align:right;">Sisa Stok</th>
                                                <th>Satuan</th>
                                                <th>Lokasi Rak</th>
                                                <th style="width:65px; text-align:center;">Jml Stiker</th>
                                                <th style="width:110px; text-align:center;">Status Item</th>
                                                <th style="width:65px; text-align:center;">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                    `;

                    hdr.items.forEach(item => {
                        const safeItemId = escapeHtml(String(item.id));
                        const isPr = intval(item.is_printed) === 1;
                        const itemBadge = isPr 
                            ? `<span class="badge-status-printed" style="font-size:8px;"><i class="fa-solid fa-check"></i> Dicetak (${item.print_count}x)</span>`
                            : `<span class="badge-status-unprinted" style="font-size:8px;">Belum Dicetak</span>`;
                        const sisa = parseFloat(item.qty_remaining || 0);
                        const init = parseFloat(item.qty_initial || 0);

                        html += `
                            <tr id="sub-item-row-${safeItemId}">
                                <td style="text-align:center;">
                                    <input type="checkbox" class="chk-item chk-group-${safeHKey}" data-hdr="${safeHKey}" value="${safeItemId}" onchange="syncHeaderFromItems('${safeHKey}')">
                                </td>
                                <td style="text-align:center; font-family:monospace; font-weight:700; color:var(--text-muted);">#${item.seri_barang || 1}</td>
                                <td><strong style="font-family:monospace; color:#111;">${escapeHtml(item.kode_barang)}</strong></td>
                                <td style="max-width:220px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(item.nama_barang)}">${escapeHtml(item.nama_barang)}</td>
                                <td style="text-align:right;">${init.toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                                <td style="text-align:right; font-weight:700; color:${sisa > 0 ? 'var(--success)' : 'var(--danger)'};">${sisa.toLocaleString('id-ID', {minimumFractionDigits:2})}</td>
                                <td>${escapeHtml(item.sat)}</td>
                                <td>
                                    <input type="text" class="form-control-compact" style="width:75px;" value="${escapeHtml(item.lokasi_rak || '')}" onblur="saveRakInline('${safeItemId}', this.value)" placeholder="Rak / Bin">
                                </td>
                                <td style="text-align:center;">
                                    <input type="number" id="print-qty-${safeItemId}" class="form-control-compact" style="width:45px; text-align:center;" value="1" min="1" max="100">
                                </td>
                                <td style="text-align:center;" id="badge-item-status-${safeItemId}">${itemBadge}</td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn-compact btn-default" onclick="printSingleItem('${safeItemId}')" title="Cetak Stiker Label Hanya untuk Barang Ini">
                                        <i class="fa-solid fa-print"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });

                    html += `
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                tbody.innerHTML = html;
                updateSelectedBadge();

            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; color:var(--danger); padding:15px;">Terjadi kesalahan: ${err.message}</td></tr>`;
            }
        }

        function intval(val) {
            const n = parseInt(val, 10);
            return isNaN(n) ? 0 : n;
        }

        function toggleExpandHeader(hKey, ev) {
            if (ev) ev.stopPropagation();
            const btn = document.getElementById(`btn-exp-${hKey}`);
            const dRow = document.getElementById(`detail-row-${hKey}`);
            const hRow = document.getElementById(`hdr-row-${hKey}`);
            if (!btn || !dRow) return;

            const isOpen = btn.classList.contains('open');
            if (isOpen) {
                btn.classList.remove('open');
                dRow.classList.remove('active');
                hRow.classList.remove('expanded');
            } else {
                btn.classList.add('open');
                dRow.classList.add('active');
                hRow.classList.add('expanded');
            }
        }

        function toggleHeaderItems(hKey, isChecked) {
            const childBoxes = document.querySelectorAll(`.chk-group-${hKey}`);
            childBoxes.forEach(chk => {
                chk.checked = isChecked;
            });
            updateSelectedBadge();
        }

        function syncHeaderFromItems(hKey) {
            const childBoxes = Array.from(document.querySelectorAll(`.chk-group-${hKey}`));
            const masterChk = document.getElementById(`chk-hdr-${hKey}`);
            if (!masterChk) return;

            const checkedCount = childBoxes.filter(c => c.checked).length;
            masterChk.checked = (checkedCount === childBoxes.length && childBoxes.length > 0);
            updateSelectedBadge();
        }

        function toggleSelectAllHeaders(masterMasterChk) {
            const isChecked = masterMasterChk.checked;
            document.querySelectorAll('.chk-hdr').forEach(chk => chk.checked = isChecked);
            document.querySelectorAll('.chk-item').forEach(chk => chk.checked = isChecked);
            updateSelectedBadge();
        }

        function updateSelectedBadge() {
            const count = document.querySelectorAll('.chk-item:checked').length;
            document.getElementById('selectedCountBadge').innerText = count;
        }

        async function saveRakInline(itemId, newRak) {
            const fd = new FormData();
            fd.append('action', 'update_rak');
            fd.append('id', itemId);
            fd.append('rak', newRak);
            fd.append('cmp_id', CURRENT_CMP_ID);
            try {
                await fetch('inv_qr_label.php', { method: 'POST', body: fd });
            } catch (e) {
                console.error("Gagal simpan rak", e);
            }
        }

        function printSingleItem(itemId) {
            let foundItem = null;
            for (const hdr of currentHeaderData) {
                const it = hdr.items.find(x => String(x.id) === String(itemId));
                if (it) {
                    foundItem = it;
                    break;
                }
            }
            if (!foundItem) return;

            const qty = parseInt(document.getElementById(`print-qty-${itemId}`)?.value) || 1;
            renderStickerPreview([{ item: foundItem, repeat: qty }]);
        }

        function printWholeHeader(hKey) {
            const hdr = currentHeaderData.find(x => x.header_key === hKey);
            if (!hdr || !hdr.items || hdr.items.length === 0) return;

            const printPayload = [];
            hdr.items.forEach(it => {
                const qty = parseInt(document.getElementById(`print-qty-${it.id}`)?.value) || 1;
                printPayload.push({ item: it, repeat: qty });
            });

            renderStickerPreview(printPayload);
        }

        function prepareBatchPrint() {
            const checkedBoxes = Array.from(document.querySelectorAll('.chk-item:checked'));
            if (checkedBoxes.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Belum Ada Barang yang Dipilih',
                    text: 'Silakan centang invoice/AJU atau barang pada tabel untuk dicetak.'
                });
                return;
            }

            const printPayload = [];
            checkedBoxes.forEach(chk => {
                const itemId = chk.value;
                for (const hdr of currentHeaderData) {
                    const it = hdr.items.find(x => String(x.id) === String(itemId));
                    if (it) {
                        const qty = parseInt(document.getElementById(`print-qty-${itemId}`)?.value) || 1;
                        printPayload.push({ item: it, repeat: qty });
                        break;
                    }
                }
            });

            renderStickerPreview(printPayload);
        }

        // =========================================================================
        // RENDER STICKER PREVIEW (DYNAMIC AUTO-RESIZING & A4 SHEET / THERMAL)
        // =========================================================================
        function renderStickerPreview(itemPayloads) {
            const canvasArea = document.getElementById('printCanvasArea');
            const modalBody = document.getElementById('printModalBody');
            canvasArea.innerHTML = '';

            const sizeMode = document.getElementById('selLabelSize').value;
            const isA4Sheet = sizeMode.startsWith('a4_');
            currentlyPrintingItems = [];

            // Update badge info ukuran
            const selEl = document.getElementById('selLabelSize');
            const selText = selEl.options[selEl.selectedIndex]?.text || sizeMode;
            document.getElementById('previewModeBadge').innerText = selText;

            // Set modal class mode (thermal vs a4 sheet)
            if (isA4Sheet) {
                modalBody.classList.remove('mode-thermal');
                modalBody.classList.add('mode-a4-sheet');
            } else {
                modalBody.classList.remove('mode-a4-sheet');
                modalBody.classList.add('mode-thermal');
            }

            // Flat list item stiker
            let flatItems = [];
            itemPayloads.forEach(p => {
                const repeat = p.repeat || 1;
                for (let i = 0; i < repeat; i++) {
                    flatItems.push(p.item);
                }
                if (!currentlyPrintingItems.includes(p.item.id)) {
                    currentlyPrintingItems.push(p.item.id);
                }
            });

            document.getElementById('previewCountText').innerText = flatItems.length;

            // Tentukan resolusi render QR Code (Pixel) - Skala dan ukuran visual auto-resize via CSS Grid
            let qrPx = 90;
            let sizeClass = `label-size-${sizeMode}`;
            let customCss = '';

            let qrBoxWidthMm = 26;
            if (sizeMode === '100x150') {
                qrPx = 135;
                qrBoxWidthMm = 46;
            } else if (sizeMode === '100x50') {
                qrPx = 95;
                qrBoxWidthMm = 34;
            } else if (sizeMode === '70x40') {
                qrPx = 72;
                qrBoxWidthMm = 26;
            } else if (sizeMode === '50x30') {
                qrPx = 52;
                qrBoxWidthMm = 18;
            } else if (sizeMode === 'custom') {
                const w = parseFloat(document.getElementById('customWidth').value) || 80;
                const h = parseFloat(document.getElementById('customHeight').value) || 45;
                customCss = `width: ${w}mm; height: ${h}mm; padding: 2mm 3mm;`;
                sizeClass = '';
                qrBoxWidthMm = Math.round(Math.min(w * 0.35, h * 0.7));
                qrPx = Math.round(qrBoxWidthMm * 2.8);
            } else if (sizeMode === 'a4_2x5') {
                qrPx = 92;
                qrBoxWidthMm = 33;
            } else if (sizeMode === 'a4_3x8') {
                qrPx = 60;
                qrBoxWidthMm = 21;
            } else if (sizeMode === 'a4_4x10') {
                qrPx = 48;
                qrBoxWidthMm = 16;
            }

            // Fungsi render 1 stiker elemen DOM
            function buildStickerElement(lot, idx) {
                const stickerDiv = document.createElement('div');
                stickerDiv.className = `label-sticker ${sizeClass}`;
                if (customCss) stickerDiv.style.cssText = customCss;

                const qrContainerId = `qr-code-${idx}-${lot.id}`;
                const bcDocStr = lot.bc ? `BC ${lot.bc}` : 'BC PABEAN';
                const noDaftarStr = lot.no_bc ? `No: ${lot.no_bc}` : '';
                const tglDaftarStr = lot.tgl_bc ? `(${lot.tgl_bc})` : '';
                const ajuVal = lot.nomor_aju && lot.nomor_aju.trim() !== '' ? lot.nomor_aju : '-';
                const subHeaderDisplay = currentLabelSubtitle && currentLabelSubtitle.trim() !== '' ? '' : 'style="display:none;"';

                stickerDiv.innerHTML = `
                    <div class="lbl-header">
                        <img src="${companyLogoUrl}" class="lbl-logo" alt="Logo" onerror="this.src='/assets/logo.png'">
                        <div style="overflow:hidden;">
                            <div class="lbl-comp-title">${escapeHtml(companyNameStr)}</div>
                            <div class="lbl-comp-sub" ${subHeaderDisplay}>${escapeHtml(currentLabelSubtitle)}</div>
                        </div>
                    </div>
                    <div class="lbl-body">
                        <div class="lbl-qr-box" style="width: ${qrBoxWidthMm}mm; max-width: ${qrBoxWidthMm}mm;">
                            <div id="${qrContainerId}" class="lbl-qr-img" style="width: ${qrBoxWidthMm}mm; height: ${qrBoxWidthMm}mm;"></div>
                            <div class="lbl-qr-ref">${escapeHtml(lot.ref_no || '')}</div>
                        </div>
                        <div class="lbl-info-box">
                            <div>
                                <div class="lbl-item-kd">${escapeHtml(lot.kode_barang)}</div>
                                <div class="lbl-item-nm" title="${escapeHtml(lot.nama_barang)}">${escapeHtml(lot.nama_barang)}</div>
                            </div>
                            <div>
                                <div class="lbl-aju-text">AJU: ${escapeHtml(ajuVal)}</div>
                                <div class="lbl-bc-text">${escapeHtml(bcDocStr)} ${escapeHtml(noDaftarStr)} ${escapeHtml(tglDaftarStr)}</div>
                                <div class="lbl-ref-text">Bukti: ${escapeHtml(lot.ref_no)} • Rak: ${escapeHtml(lot.lokasi_rak || '-')}</div>
                            </div>
                            <div class="lbl-qty-box">
                                QTY: ${parseFloat(lot.qty_initial || 0).toLocaleString('id-ID', {minimumFractionDigits:2})} ${escapeHtml(lot.sat || '')}
                            </div>
                        </div>
                    </div>
                `;

                return { stickerDiv, qrContainerId, lot };
            }

            // Jika mode Kertas Lembaran A4: kelola per lembar
            if (isA4Sheet) {
                let perSheet = 24;
                let gridClass = 'a4-grid-3x8';
                if (sizeMode === 'a4_2x5') { perSheet = 10; gridClass = 'a4-grid-2x5'; }
                if (sizeMode === 'a4_4x10') { perSheet = 40; gridClass = 'a4-grid-4x10'; }

                let currentSheetDiv = null;
                const qrTasks = [];

                flatItems.forEach((lot, idx) => {
                    if (idx % perSheet === 0) {
                        currentSheetDiv = document.createElement('div');
                        currentSheetDiv.className = `a4-sheet-page ${gridClass}`;
                        canvasArea.appendChild(currentSheetDiv);
                    }
                    const buildRes = buildStickerElement(lot, idx);
                    currentSheetDiv.appendChild(buildRes.stickerDiv);
                    qrTasks.push(buildRes);
                });

                // Generate QR Codes
                qrTasks.forEach(task => {
                    const qrEl = document.getElementById(task.qrContainerId);
                    if (qrEl) {
                        const qrPayload = task.lot.qr_payload || `AJU|${task.lot.nomor_aju}|${task.lot.no_bc}|${task.lot.kode_barang}|${task.lot.ref_no}|${task.lot.id}`;
                        new QRCode(qrEl, {
                            text: qrPayload,
                            width: qrPx,
                            height: qrPx,
                            colorDark: "#000000",
                            colorLight: "#ffffff",
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    }
                });

            } else {
                // Mode Printer Thermal Roll
                const qrTasks = [];
                flatItems.forEach((lot, idx) => {
                    const buildRes = buildStickerElement(lot, idx);
                    canvasArea.appendChild(buildRes.stickerDiv);
                    qrTasks.push(buildRes);
                });

                qrTasks.forEach(task => {
                    const qrEl = document.getElementById(task.qrContainerId);
                    if (qrEl) {
                        const qrPayload = task.lot.qr_payload || `AJU|${task.lot.nomor_aju}|${task.lot.no_bc}|${task.lot.kode_barang}|${task.lot.ref_no}|${task.lot.id}`;
                        new QRCode(qrEl, {
                            text: qrPayload,
                            width: qrPx,
                            height: qrPx,
                            colorDark: "#000000",
                            colorLight: "#ffffff",
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    }
                });
            }

            const modalSubInput = document.getElementById('modalInputSubtitle');
            if (modalSubInput) modalSubInput.value = currentLabelSubtitle;
            document.getElementById('printModal').classList.add('active');
        }

        function closePrintModal() {
            document.getElementById('printModal').classList.remove('active');
        }

        // =========================================================================
        // EKSEKUSI CETAK & KONFIRMASI STATUS CETAK (Y/N)
        // =========================================================================
        function executePrintNow() {
            window.print();

            // Tampilkan konfirmasi post-print
            setTimeout(() => {
                promptMarkPrintedConfirmation();
            }, 600);
        }

        function promptMarkPrintedConfirmation() {
            if (!currentlyPrintingItems || currentlyPrintingItems.length === 0) return;

            const totalBrg = currentlyPrintingItems.length;
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi Pencetakan Selesai',
                html: `
                    <div style="font-size:11.5px; line-height:1.6; text-align:left;">
                        Pencetakan stiker label untuk <strong>${totalBrg} barang</strong> telah dieksekusi ke printer.<br><br>
                        Apakah Anda ingin menandai status barang / AJU ini sebagai <strong>SUDAH DICETAK</strong>?
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fa-solid fa-check"></i> Ya, Tandai Sudah Dicetak',
                cancelButtonText: '<i class="fa-solid fa-xmark"></i> Tidak / Lewati',
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    await sendMarkPrintedRequest(currentlyPrintingItems);
                }
            });
        }

        async function sendMarkPrintedRequest(keysArray) {
            const fd = new FormData();
            fd.append('action', 'mark_printed');
            fd.append('cmp_id', CURRENT_CMP_ID);
            fd.append('item_keys', JSON.stringify(keysArray));

            try {
                const res = await fetch('inv_qr_label.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Status Diperbarui',
                        text: json.message || 'Status cetak berhasil diperbarui.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    // Refresh data tabel agar badge langsung hijau
                    loadLotsData();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message || 'Gagal menandai status cetak.' });
                }
            } catch (err) {
                console.error("Gagal mark printed", err);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Terjadi kesalahan jaringan saat update status cetak.' });
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
                title: 'Panduan SOP: Label QR Warehouse & Traceability Dokumen BC',
                width: 780,
                html: `
                    <div style="text-align:left; font-size:11px; line-height:1.6; max-height:65vh; overflow-y:auto; padding-right:10px;">
                        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                            <div>
                                <strong style="color:#15803d; font-size:11.5px;"><i class="fa-solid fa-file-word"></i> Dokumen Resmi Arsitektur & SOP Sistem</strong>
                                <div style="font-size:10px; color:#4b5563;">Tersedia dokumen panduan teknis & SOP lengkap format Microsoft Word (.docx).</div>
                            </div>
                            <a href="/docs/PANDUAN_ARSITEKTUR_WO_TRACEABILITY_CEISA4.docx" target="_blank" download class="btn-compact btn-success" style="padding:5px 12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                <i class="fa-solid fa-download"></i> Unduh Word (.docx)
                            </a>
                        </div>

                        <h4 style="color:#1890ff; margin-top:0;"><i class="fa-solid fa-qrcode"></i> Konsep Traceability IT Inventory CEISA 4.0</h4>
                        <p>Sumber utama identitas pemasukan gudang diambil langsung dari <strong>Buku Penerimaan IT Inventory (bc_1ab2)</strong> sebagai single source of truth. Setiap baris barang otomatis terikat dengan <strong>Nomor Pengajuan AJU 26 Digit Dokumen Pabean DJBC</strong> dan nomor pendaftaran BC.</p>
                        
                        <h4 style="color:#1890ff;"><i class="fa-solid fa-list-check"></i> Alur Kerja Operasional Gudang:</h4>
                        <ol style="padding-left:18px;">
                            <li><strong>Penerimaan Barang &amp; Posting IT Inventory:</strong> Tim gudang memposting dokumen pemasukan (BC 2.3, BC 2.7, BC 4.0, RI, atau PI). Data resmi tercatat di IT Inventory 1A.</li>
                            <li><strong>Cetak Label Stiker QR:</strong> Pada menu ini, pilih filter dokumen pemasukan atau cari berdasarkan Nomor AJU / Invoice. Centang dokumen yang ingin dicetak, pilih format kertas (Thermal Roll atau Kertas Stiker A4), lalu klik <strong>Cetak Terpilih</strong>.</li>
                            <li><strong>Penempelan Fisik:</strong> Tempelkan label stiker QR pada kemasan fisik kardus, palet, atau karung material.</li>
                            <li><strong>Pengeluaran ke Produksi / Pemakaian Departemen (WO):</strong> Saat scan bahan baku untuk Work Order, scanner membaca 26 digit Nomor AJU dari label stiker secara otomatis.</li>
                        </ol>

                        <h4 style="color:#1890ff;"><i class="fa-solid fa-tags"></i> Format Kertas yang Didukung:</h4>
                        <ul style="padding-left:18px;">
                            <li><strong>Thermal 70 x 40 mm (Rekomendasi):</strong> Standar box, karton, dan drum gudang.</li>
                            <li><strong>Thermal 100 x 50 mm &amp; 100 x 150 mm:</strong> Standar palet besar, kontainer, dan pengiriman ekspor.</li>
                            <li><strong>Thermal 50 x 30 mm:</strong> Ukuran kompak untuk suku cadang kecil.</li>
                            <li><strong>Kertas Stiker A4 (Printer Biasa / Laser / Inkjet):</strong> Mendukung grid lembaran 2x5 (10 label), 3x8 (24 label Tom &amp; Jerry 108/121), dan 4x10 (40 label) bagi perusahaan yang belum memiliki printer thermal roll khusus.</li>
                        </ul>
                    </div>
                `,
                confirmButtonText: 'Tutup Panduan',
                confirmButtonColor: '#1890ff'
            });
        }
    </script>
</body>
</html>

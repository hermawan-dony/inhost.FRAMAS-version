<?php
/**
 * CEISA 4.0 - Modul Integrasi IT Inventory Kawasan Ekonomi Khusus (KEK)
 * Lembaga National Single Window (LNSW Gen2)
 *
 * Mendukung KEK Manufaktur dan KEK Logistik
 * Menghubungkan data mutasi persediaan internal dengan API Gateway INSW (System-to-System)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/kek_service.php';

// Validasi Autentikasi Pengguna
$isLoggedIn = !empty($_SESSION['authenticated']) && !empty($_SESSION['company_cmp_id']);
if (!$isLoggedIn) {
    header("Location: index.php");
    exit;
}

$sessionCmpId = $_SESSION['company_cmp_id'] ?? '';
$isSuperAdmin = !empty($_SESSION['is_super_admin']);
$isAdmin      = !empty($_SESSION['is_admin']);

// Fallback deteksi subdomain jika cmp_id belum ter-set
if (empty($sessionCmpId) && isset($koneksi) && $koneksi) {
    $rawHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $cleanHost = explode(':', $rawHost)[0];
    $parts = explode('.', $cleanHost);
    if (count($parts) >= 3 && !filter_var($cleanHost, FILTER_VALIDATE_IP)) {
        $firstPart = strtolower($parts[0]);
        if (!in_array($firstPart, ['www', 'root', 'portal', 'api', 'app', 'mail', 'cpanel', 'localhost', 'admin'])) {
            $stmtSub = $koneksi->prepare("SELECT cmp_id, cmp_name, npwp FROM bc_ceisa_company WHERE LOWER(subdomain) = ? AND is_active = 1 LIMIT 1");
            if ($stmtSub) {
                $stmtSub->bind_param("s", $firstPart);
                $stmtSub->execute();
                $resSub = $stmtSub->get_result();
                if ($resSub && $rSub = $resSub->fetch_assoc()) {
                    $sessionCmpId = $rSub['cmp_id'];
                    $_SESSION['company_cmp_id'] = $rSub['cmp_id'];
                    $_SESSION['company_name']   = $rSub['cmp_name'];
                    $_SESSION['company_npwp']   = $rSub['npwp'];
                }
                $stmtSub->close();
            }
        }
    }
}

// Inisialisasi Service KEK
$kekService = new KekSyncService($koneksi, $sessionCmpId);
$company    = $kekService->getCompany();
$setting    = $kekService->getSetting();

// =====================================================================
// AJAX ENDPOINTS HANDLER
// =====================================================================
$action = $_REQUEST['action'] ?? '';

if (!empty($action)) {
    header('Content-Type: application/json; charset=utf-8');

    // 1. Ambil Ringkasan Status & Statistik KEK
    if ($action === 'summary') {
        $cmpEsc = $koneksi->real_escape_string($sessionCmpId);

        $cntIn   = (int)($koneksi->query("SELECT COUNT(1) FROM bc_kek_transaksi_log WHERE cmp_id = '$cmpEsc' AND kd_kegiatan = '30'")->fetch_row()[0] ?? 0);
        $cntOut  = (int)($koneksi->query("SELECT COUNT(1) FROM bc_kek_transaksi_log WHERE cmp_id = '$cmpEsc' AND kd_kegiatan = '31'")->fetch_row()[0] ?? 0);
        $cntSto  = (int)($koneksi->query("SELECT COUNT(1) FROM bc_kek_transaksi_log WHERE cmp_id = '$cmpEsc' AND kd_kegiatan = '32'")->fetch_row()[0] ?? 0);
        $cntAdj  = (int)($koneksi->query("SELECT COUNT(1) FROM bc_kek_transaksi_log WHERE cmp_id = '$cmpEsc' AND kd_kegiatan = '33'")->fetch_row()[0] ?? 0);
        $cntFail = (int)($koneksi->query("SELECT COUNT(1) FROM bc_kek_transaksi_log WHERE cmp_id = '$cmpEsc' AND status_kirim = 'FAILED'")->fetch_row()[0] ?? 0);

        echo json_encode([
            'status'         => 'OK',
            'kek_active'     => (int)($setting['is_kek_active'] ?? 0),
            'kek_mode'       => $setting['kek_mode'] ?? 'dummy',
            'business_type'  => $setting['business_type'] ?? 'manufaktur',
            'saldo_final'    => (int)($setting['saldo_awal_final'] ?? 0),
            'last_push_at'   => $setting['last_push_at'],
            'total_in'       => $cntIn,
            'total_out'      => $cntOut,
            'total_sto'      => $cntSto,
            'total_adj'      => $cntAdj,
            'total_failed'   => $cntFail
        ]);
        exit;
    }

    // 2. Kirim Data Terpilih dari IT Inventory (bc_1ab2) ke INSW
    if ($action === 'push_transactions') {
        try {
            $ids = $_POST['ids'] ?? [];
            $kdKegiatan = trim($_POST['kd_kegiatan'] ?? '30');

            if (empty($ids) || !is_array($ids)) {
                echo json_encode(['status' => 'ERROR', 'message' => 'Pilih minimal satu data transaksi untuk dikirim ke INSW.']);
                exit;
            }

            $res = $kekService->pushRecordsFrom1ab2($ids, $kdKegiatan);
            echo json_encode($res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal kirim transaksi: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. Kunci Saldo Awal (Finalize Saldo Awal)
    if ($action === 'finalize_saldo_awal') {
        try {
            $res = $kekService->finalizeSaldoAwal();
            echo json_encode($res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal kunci saldo awal: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3b. Uji Coba Kirim Saldo Awal Trial (Endpoint /temp/saldoAwal)
    if ($action === 'push_trial_saldo_awal') {
        try {
            $noKegiatan  = 'SA-TRIAL-' . date('Ymd-His');
            $tglKegiatan = date('Y-m-d');

            $barangList = [];
            $cmpEsc = $koneksi->real_escape_string($sessionCmpId);

            // 1. Cek dari bc_so0 (Master Saldo Awal) JOIN bc_material
            $soQuery = $koneksi->query("SELECT s.kode_barang, 
                                               COALESCE(m.URAIAN, s.kode_barang) as nama_barang, 
                                               COALESCE(s.unit, m.KODE_SATUAN, 'PCE') as satuan, 
                                               COALESCE(m.UNIT_PRICE, 10000) as harga, 
                                               s.stok_awal as saldo_awal, 
                                               m.KLAS as klasifikasi
                                        FROM bc_so0 s
                                        LEFT JOIN bc_material m ON m.cmp_id = s.cmp_id AND m.KODE_BARANG = s.kode_barang
                                        WHERE s.cmp_id = '$cmpEsc' AND s.stok_awal > 0
                                        ORDER BY s.tahun DESC, s.stok_awal DESC 
                                        LIMIT 5");
            if ($soQuery && $soQuery->num_rows > 0) {
                while ($s = $soQuery->fetch_assoc()) {
                    $barangList[] = [
                        'kd_kategori_barang' => $kekService->mapKategoriBarang($s['klasifikasi'] ?? '', $setting['business_type'] ?? 'manufaktur'),
                        'kd_barang'          => $s['kode_barang'],
                        'uraian_barang'      => $s['nama_barang'],
                        'jumlah'             => (float)$s['saldo_awal'],
                        'satuan'             => strtoupper(trim($s['satuan'] ?: 'PCE')),
                        'nilai'              => round((float)($s['harga'] > 0 ? $s['harga'] : 10000) * (float)$s['saldo_awal'], 4),
                        'tanggal_declare'    => $tglKegiatan
                    ];
                }
            } else {
                // 2. Fallback ke bc_material master
                $matQuery = $koneksi->query("SELECT m.KODE_BARANG as kode_barang, 
                                                    m.URAIAN as nama_barang, 
                                                    COALESCE(m.KODE_SATUAN, 'PCE') as satuan, 
                                                    COALESCE(m.UNIT_PRICE, 10000) as harga, 
                                                    1 as saldo_awal, 
                                                    m.KLAS as klasifikasi 
                                             FROM bc_material m 
                                             WHERE m.cmp_id = '$cmpEsc' AND (m.AKTIF = 'Y' OR m.AKTIF = '1' OR m.AKTIF IS NULL)
                                             LIMIT 3");
                if ($matQuery && $matQuery->num_rows > 0) {
                    while ($m = $matQuery->fetch_assoc()) {
                        $barangList[] = [
                            'kd_kategori_barang' => $kekService->mapKategoriBarang($m['klasifikasi'] ?? '', $setting['business_type'] ?? 'manufaktur'),
                            'kd_barang'          => $m['kode_barang'],
                            'uraian_barang'      => $m['nama_barang'],
                            'jumlah'             => 1,
                            'satuan'             => strtoupper(trim($m['satuan'] ?: 'PCE')),
                            'nilai'              => round((float)($m['harga'] > 0 ? $m['harga'] : 10000), 4),
                            'tanggal_declare'    => $tglKegiatan
                        ];
                    }
                }
            }
            if (empty($barangList)) {
                $barangList[] = [
                    'kd_kategori_barang' => '1',
                    'kd_barang'          => 'TRIAL01',
                    'uraian_barang'      => 'SAMPLE BAHAN BAKU TRIAL KEK',
                    'jumlah'             => 1,
                    'satuan'             => 'KGM',
                    'nilai'              => 10000,
                    'tanggal_declare'    => $tglKegiatan
                ];
            }

            $res = $kekService->pushSaldoAwal($noKegiatan, $tglKegiatan, $barangList);
            echo json_encode($res);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal kirim trial saldo awal: ' . $e->getMessage()]);
        }
        exit;
    }

    // 4. Cleansing Data Percobaan (Testing Cleanup)
    if ($action === 'clean_dummy') {
        if (($setting['kek_mode'] ?? 'dummy') !== 'dummy') {
            echo json_encode(['status' => 'ERROR', 'message' => 'Pembersihan data hanya diizinkan pada Mode Percobaan (Dummy).']);
            exit;
        }
        $res = $kekService->cleansingDataPercobaan();
        // Bersihkan seluruh data log pengujian lokal
        $cmpEsc = $koneksi->real_escape_string($sessionCmpId);
        @$koneksi->query("DELETE d FROM bc_kek_transaksi_detail d JOIN bc_kek_transaksi_log l ON d.id_log = l.id WHERE l.cmp_id = '$cmpEsc'");
        @$koneksi->query("DELETE FROM bc_kek_transaksi_detail WHERE cmp_id = '$cmpEsc'");
        @$koneksi->query("DELETE FROM bc_kek_transaksi_log WHERE cmp_id = '$cmpEsc'");
        $res['success'] = true;
        $res['status']  = true;
        $res['message'] = 'Data uji coba di server INSW dan seluruh log transaksi lokal berhasil dibersihkan.';
        echo json_encode($res);
        exit;
    }

    // 5. Update Setting KEK Cepat
    if ($action === 'save_setting') {
        $isActive       = isset($_POST['is_kek_active']) ? 1 : 0;
        $mode           = in_array($_POST['kek_mode'] ?? '', ['dummy', 'real']) ? $_POST['kek_mode'] : 'dummy';
        $inswKey        = trim($_POST['insw_key'] ?? '');
        $uniqueKeyReal  = trim($_POST['unique_key_real'] ?? $_POST['unique_key'] ?? '');
        $uniqueKeyDummy = trim($_POST['unique_key_dummy'] ?? 'e655669b83fe553f0bd756966311ecb694df907991293e5f0961d7e8157641e7');
        $uniqueKey      = ($mode === 'real') ? $uniqueKeyReal : $uniqueKeyDummy;
        $bizType        = in_array($_POST['business_type'] ?? '', ['manufaktur', 'logistik']) ? $_POST['business_type'] : 'manufaktur';

        $stmt = $koneksi->prepare("INSERT INTO bc_kek_setting 
            (cmp_id, is_kek_active, kek_mode, insw_key, unique_key, unique_key_real, unique_key_dummy, business_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            is_kek_active = VALUES(is_kek_active),
            kek_mode = VALUES(kek_mode),
            insw_key = VALUES(insw_key),
            unique_key = VALUES(unique_key),
            unique_key_real = VALUES(unique_key_real),
            unique_key_dummy = VALUES(unique_key_dummy),
            business_type = VALUES(business_type)");
        if ($stmt) {
            $stmt->bind_param("sissssss", $sessionCmpId, $isActive, $mode, $inswKey, $uniqueKey, $uniqueKeyReal, $uniqueKeyDummy, $bizType);
            $stmt->execute();
            $stmt->close();

            // Selaraskan juga business_type ke master perusahaan
            $cmpEsc = $koneksi->real_escape_string($sessionCmpId);
            $koneksi->query("UPDATE bc_ceisa_company SET business_type = '$bizType' WHERE cmp_id = '$cmpEsc'");

            echo json_encode(['status' => 'OK', 'message' => 'Pengaturan KEK berhasil disimpan!']);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyimpan pengaturan: ' . $koneksi->error]);
        }
        exit;
    }

    // 6. Ambil Log Riwayat Pengiriman
    if ($action === 'get_logs') {
        $cmpEsc = $koneksi->real_escape_string($sessionCmpId);
        $limit  = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        $status = trim($_GET['status'] ?? '');
        $keg    = trim($_GET['kegiatan'] ?? '');

        $where = ["cmp_id = '$cmpEsc'"];
        if (!empty($status) && $status !== 'ALL') {
            $where[] = "status_kirim = '" . $koneksi->real_escape_string($status) . "'";
        }
        if (!empty($keg) && $keg !== 'ALL') {
            $where[] = "kd_kegiatan = '" . $koneksi->real_escape_string($keg) . "'";
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT id, kd_kegiatan, nomor_dok_kegiatan, tanggal_kegiatan, nama_entitas, insw_id_transaksi, 
                       status_kirim, http_code, response_code, response_message, total_item, total_nilai, 
                       request_payload, response_payload, pushed_at
                FROM bc_kek_transaksi_log 
                WHERE $whereSql 
                ORDER BY pushed_at DESC, id DESC 
                LIMIT $offset, $limit";

        $res = $koneksi->query($sql);
        $logs = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $logs[] = $r;
            }
        }

        $cntTotal = (int)($koneksi->query("SELECT COUNT(1) FROM bc_kek_transaksi_log WHERE $whereSql")->fetch_row()[0] ?? 0);

        echo json_encode([
            'status' => 'OK',
            'data'   => $logs,
            'total'  => $cntTotal
        ]);
        exit;
    }

    // 7. Ambil Data Mutasi bc_1ab2 Siap Kirim
    if ($action === 'get_pending_transactions') {
        if (empty($sessionCmpId)) {
            echo json_encode(['status' => 'OK', 'data' => []]);
            exit;
        }

        $cmpEsc   = $koneksi->real_escape_string($sessionCmpId);
        $cmpCandidates = [$cmpEsc];
        $resCmp = $koneksi->query("SELECT cmp_id, npwp, subdomain FROM bc_ceisa_company WHERE cmp_id = '$cmpEsc' OR npwp = '$cmpEsc' OR subdomain = '$cmpEsc' LIMIT 1");
        if ($resCmp && $cRow = $resCmp->fetch_assoc()) {
            if (!empty($cRow['cmp_id'])) $cmpCandidates[] = $cRow['cmp_id'];
            if (!empty($cRow['npwp'])) {
                $cmpCandidates[] = $cRow['npwp'];
                $cmpCandidates[] = preg_replace('/\D/', '', $cRow['npwp']);
            }
            if (!empty($cRow['subdomain'])) $cmpCandidates[] = $cRow['subdomain'];
        }
        $cmpCandidates = array_unique(array_filter($cmpCandidates));
        $escapedCmpList = "'" . implode("','", array_map([$koneksi, 'real_escape_string'], $cmpCandidates)) . "'";

        $jenis    = trim($_GET['jenis'] ?? '30'); // 30: Masuk, 31: Keluar
        $abFilter = ($jenis === '31') ? 'B' : 'A';
        $tglAwal  = trim($_GET['tgl_awal'] ?? date('Y-m-01'));
        $tglAkhir = trim($_GET['tgl_akhir'] ?? date('Y-m-d'));

        // Query mutasi dari bc_1ab2
        // Masuk jika jenis 30 (AB = 'A'), Keluar jika jenis 31 (AB = 'B')
        $whereTgl = "AND (
            (a.TGL_BUKTI IS NOT NULL AND a.TGL_BUKTI != '' AND a.TGL_BUKTI BETWEEN '$tglAwal' AND '$tglAkhir')
            OR (a.TANGGAL_DAFTAR IS NOT NULL AND a.TANGGAL_DAFTAR != '' AND a.TANGGAL_DAFTAR BETWEEN '$tglAwal' AND '$tglAkhir')
            OR (a.TGL_PROSES IS NOT NULL AND a.TGL_PROSES BETWEEN '$tglAwal' AND '$tglAkhir')
        )";
        
        $sql = "SELECT 
                    CONCAT_WS(':::|:::', a.NO_BUKTI, a.KODE_BARANG, a.SERI_BARANG, a.BC, a.NOMOR_DAFTAR, a.AB) AS row_uid,
                    a.NOMOR_AJU, a.BC, a.NOMOR_DAFTAR, a.TANGGAL_DAFTAR, a.NO_BUKTI, a.TGL_BUKTI, 
                    a.SERI_BARANG,
                    a.KODE_BARANG, a.URAIAN, COALESCE(a.QTY_AKTUAL, a.QTY, 0) AS QTY, a.UNIT, 
                    COALESCE(a.NILAI, a.CIF, a.HARGA_PENYERAHAN, 0) AS NILAI, 
                    COALESCE(a.CIF, a.NILAI, 0) AS NILAI_PABEAN,
                    a.NAMA_BS AS PENGIRIM_PENERIMA, 
                    a.KLAS AS KLASIFIKASI, a.AB,
                    (SELECT l.status_kirim FROM bc_kek_transaksi_log l JOIN bc_kek_transaksi_detail d ON d.id_log = l.id WHERE l.cmp_id IN ($escapedCmpList) AND l.nomor_dok_kegiatan = a.NO_BUKTI AND d.kd_barang = a.KODE_BARANG AND ((d.nomor_aju IS NOT NULL AND d.nomor_aju != '''' AND d.nomor_aju = a.NOMOR_AJU AND d.seri_barang = a.SERI_BARANG) OR (d.seri_barang = a.SERI_BARANG) OR (d.seri_barang IS NULL AND ROUND(d.jumlah, 2) = ROUND(COALESCE(a.QTY_AKTUAL, a.QTY, 0), 2))) AND l.status_kirim = 'SUCCESS' LIMIT 1) as kek_status,
                    (SELECT l.pushed_at FROM bc_kek_transaksi_log l JOIN bc_kek_transaksi_detail d ON d.id_log = l.id WHERE l.cmp_id IN ($escapedCmpList) AND l.nomor_dok_kegiatan = a.NO_BUKTI AND d.kd_barang = a.KODE_BARANG AND ((d.nomor_aju IS NOT NULL AND d.nomor_aju != '''' AND d.nomor_aju = a.NOMOR_AJU AND d.seri_barang = a.SERI_BARANG) OR (d.seri_barang = a.SERI_BARANG) OR (d.seri_barang IS NULL AND ROUND(d.jumlah, 2) = ROUND(COALESCE(a.QTY_AKTUAL, a.QTY, 0), 2))) AND l.status_kirim = 'SUCCESS' LIMIT 1) as kek_pushed_at
                FROM bc_1ab2 a
                WHERE a.cmp_id IN ($escapedCmpList)
                  AND a.AB = '$abFilter'
                  $whereTgl
                ORDER BY a.TGL_BUKTI DESC, a.SERI_BARANG ASC 
                LIMIT 100";

        try {
            $res = $koneksi->query($sql);
            $rows = [];
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
            echo json_encode(['status' => 'OK', 'data' => $rows]);
        } catch (\Throwable $e) {
            echo json_encode(['status' => 'OK', 'data' => [], 'query_notice' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'ERROR', 'message' => "Aksi '$action' tidak dikenali."]);
    exit;
}

$businessType = $setting['business_type'] ?? ($company['business_type'] ?? 'manufaktur');
$kekMode      = $setting['kek_mode'] ?? 'dummy';
$isKekActive  = (int)($setting['is_kek_active'] ?? 0);
$isProduction = ($kekMode === 'real');
$portalInswUrl = $isProduction ? 'https://itinventory.insw.go.id/inventory' : 'https://itinventory.insw.go.id/tryout';
$curTheme = htmlspecialchars($_GET['theme'] ?? 'light');
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= $curTheme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrasi IT Inventory KEK - LNSW Gen2</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, html[data-theme='light'], body[data-theme='light'] {
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --kek-purple: #722ed1;
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #e8e8e8;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color-scheme: light;
        }

        [data-theme='dark'], html[data-theme='dark'], body[data-theme='dark'] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --border-card: #303030;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --kek-purple: #9254de;
            color-scheme: dark !important;
        }

        [data-theme='dark'] select, [data-theme='dark'] input {
            background-color: #141414 !important;
            color: #fff !important;
            border-color: #434343 !important;
        }

        [data-theme='dark'] select option {
            background: #1f1f1f !important;
            color: #fff !important;
        }

        [data-theme='dark'] .kek-table th {
            background: #1d1d1d !important;
            color: rgba(255, 255, 255, 0.7) !important;
            border-bottom-color: #303030 !important;
        }

        [data-theme='dark'] .kek-table td {
            border-bottom-color: #303030 !important;
        }

        [data-theme='dark'] .kek-table tr:hover {
            background: #262638 !important;
        }

        [data-theme='dark'] .btn-secondary {
            background: #1f1f1f !important;
            color: rgba(255, 255, 255, 0.85) !important;
            border-color: #434343 !important;
        }

        [data-theme='dark'] .modal-card {
            background: #1f1f1f !important;
            color: #fff !important;
            border: 1px solid #303030 !important;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 10.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .kek-header-card {
            background: linear-gradient(135deg, #1890ff 0%, #722ed1 100%);
            color: #ffffff;
            border-radius: 4px;
            padding: 6px 12px;
            margin-bottom: 6px;
            box-shadow: 0 2px 8px rgba(114, 46, 209, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .kek-title-box h1 {
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .kek-title-box p {
            font-size: 10px;
            opacity: 0.9;
            margin-top: 1px;
        }

        .kek-badges {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .badge-tag {
            padding: 2px 7px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-tag-live { background: #52c41a; color: #fff; }
        .badge-tag-dummy { background: #faad14; color: #fff; }
        .badge-tag-biz { background: rgba(255,255,255,0.25); color: #fff; border: 1px solid rgba(255,255,255,0.4); }

        .kek-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            font-size: 9.5px;
            font-weight: 600;
            border-radius: 3px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            line-height: 1.3;
            border: 1px solid rgba(255, 255, 255, 0.45);
            background: rgba(255, 255, 255, 0.95);
            color: #722ed1;
        }

        .kek-action-btn:hover {
            background: #ffffff;
            color: #531dab;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }

        .kek-action-btn-mfa {
            background: #ffffff;
            color: #1890ff;
            border-color: rgba(255, 255, 255, 0.5);
        }

        .kek-action-btn-mfa:hover {
            background: #f0f5ff;
            color: #096dd9;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 6px;
            margin-bottom: 6px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 2px;
            padding: 4px 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-info .stat-label {
            font-size: 9px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-info .stat-val {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-main);
            margin-top: 1px;
        }

        .stat-icon {
            font-size: 13px;
            opacity: 0.85;
        }

        .nav-tabs {
            display: flex;
            gap: 2px;
            border-bottom: 1px solid var(--border-card);
            margin-bottom: 6px;
            overflow-x: auto;
        }

        .nav-tab-item {
            padding: 4px 10px;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .nav-tab-item:hover { color: var(--primary); }
        .nav-tab-item.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: rgba(24, 144, 255, 0.05);
            border-top-left-radius: 3px;
            border-top-right-radius: 3px;
        }

        .content-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 3px;
            padding: 6px 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            margin-bottom: 6px;
        }

        .toolbar-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-size: 9.5px;
        }
        .filter-group label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .form-control-compact {
            padding: 1px 6px;
            border: 1px solid var(--border-card);
            border-radius: 2px;
            font-size: 9.5px;
            font-family: inherit;
            background: #fff;
            height: 22px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0 8px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 2px;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
            height: 22px;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-secondary { background: #fff; color: var(--text-main); border-color: var(--border-card); }
        .btn-secondary:hover { border-color: var(--primary); color: var(--primary); }
        .btn-purple { background: var(--kek-purple); color: #fff; }

        .table-responsive {
            overflow-x: auto;
            width: 100%;
        }

        .kek-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .kek-table th {
            background: #fafafa;
            color: rgba(0, 0, 0, 0.85);
            font-weight: 600;
            padding: 4px 6px;
            border-bottom: 1px solid var(--border-card);
            text-align: left;
            font-size: 9.5px;
            white-space: nowrap;
        }

        .kek-table td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border-card);
            vertical-align: middle;
            font-size: 9.5px;
        }

        .kek-table tr:hover { background: #f0f7ff; }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 6px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
        }
        .status-badge-success { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .status-badge-failed { background: #fff1f0; color: #f5222d; border: 1px solid #ffa39e; }
        .status-badge-pending { background: #f5f5f5; color: #8c8c8c; border: 1px solid #d9d9d9; }

        /* Modal Inspector */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.45);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: #fff;
            width: 90%;
            max-width: 680px;
            border-radius: 6px;
            padding: 10px 14px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        .modal-body-scroll {
            overflow-y: auto;
            flex: 1;
            margin: 8px 0;
        }
        .code-box {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 8px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 9.5px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 280px;
            overflow-y: auto;
        }
    </style>
</head>
<body>

    <!-- Header Banner KEK -->
    <div class="kek-header-card">
        <div class="kek-title-box">
            <h1><i class="fa-solid fa-cloud-arrow-up"></i> Modul Integrasi IT Inventory KEK (LNSW Gen2)</h1>
            <p>Sinkronisasi System-to-System Persediaan Kawasan Ekonomi Khusus ke Gateway Resmi Lembaga National Single Window</p>
        </div>
        <div class="kek-badges" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
            <span class="badge-tag badge-tag-biz">
                <?= $businessType === 'logistik' ? '📦 KEK Logistik' : '🏭 KEK Manufaktur' ?>
            </span>
            <span class="badge-tag <?= $kekMode === 'real' ? 'badge-tag-live' : 'badge-tag-dummy' ?>">
                <?= $kekMode === 'real' ? '🟢 LIVE PRODUCTION' : '🟡 DUMMY / TESTING' ?>
            </span>
            <?php if (!empty($company['cmp_name'])): ?>
                <span class="badge-tag badge-tag-biz">
                    🏢 <?= htmlspecialchars($company['cmp_name']) ?>
                </span>
            <?php endif; ?>
            <!-- Link Button Portal INSW & Tombol MFA Token INSW -->
            <a href="<?= $portalInswUrl ?>" target="_blank" rel="noopener noreferrer" class="kek-action-btn" title="Buka Portal Resmi IT Inventory INSW (<?= $isProduction ? 'Live Production' : 'Tryout / Sandbox' ?>)">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Portal INSW (<?= $isProduction ? 'Live' : 'Tryout' ?>)
            </a>
            <button type="button" class="kek-action-btn kek-action-btn-mfa" onclick="openMfaInswPopup()" title="Buka Google Authenticator TOTP Token Akun INSW">
                <i class="fa-solid fa-shield-halved"></i> MFA Token INSW
            </button>
        </div>
    </div>

    <!-- Statistik Transaksi KEK -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Pemasukan (30)</div>
                <div class="stat-val" id="statIn">-</div>
            </div>
            <div class="stat-icon" style="color:#52c41a;"><i class="fa-solid fa-arrow-down-long"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Pengeluaran (31)</div>
                <div class="stat-val" id="statOut">-</div>
            </div>
            <div class="stat-icon" style="color:#1890ff;"><i class="fa-solid fa-arrow-up-long"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Stock Opname (32)</div>
                <div class="stat-val" id="statSto">-</div>
            </div>
            <div class="stat-icon" style="color:#722ed1;"><i class="fa-solid fa-boxes-stacked"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Adjustment (33)</div>
                <div class="stat-val" id="statAdj">-</div>
            </div>
            <div class="stat-icon" style="color:#faad14;"><i class="fa-solid fa-sliders"></i></div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-label">Gagal Terkirim</div>
                <div class="stat-val" id="statFailed" style="color:#ff4d4f;">-</div>
            </div>
            <div class="stat-icon" style="color:#ff4d4f;"><i class="fa-solid fa-circle-exclamation"></i></div>
        </div>
    </div>

    <!-- Tab Navigasi Kegiatan -->
    <div class="nav-tabs">
        <div class="nav-tab-item active" onclick="switchKekTab('30', this)">
            <i class="fa-solid fa-dolly"></i> Pemasukan Barang (30)
        </div>
        <div class="nav-tab-item" onclick="switchKekTab('31', this)">
            <i class="fa-solid fa-truck-fast"></i> Pengeluaran Barang (31)
        </div>
        <div class="nav-tab-item" onclick="switchKekTab('log', this)">
            <i class="fa-solid fa-clock-rotate-left"></i> Log Histori Pengiriman
        </div>
        <div class="nav-tab-item" onclick="switchKekTab('settings', this)">
            <i class="fa-solid fa-gears"></i> Konfigurasi KEK
        </div>
    </div>

    <!-- Content Card: Transaksi Siap Kirim (Tab 30 & 31) -->
    <div class="content-card" id="tabTransactions">
        <div class="toolbar-box">
            <div class="filter-group">
                <label style="font-weight:600;">Periode:</label>
                <input type="date" id="filterTglAwal" class="form-control-compact" value="<?= date('Y-m-01') ?>">
                <span>s/d</span>
                <input type="date" id="filterTglAkhir" class="form-control-compact" value="<?= date('Y-m-d') ?>">
                <button type="button" class="btn btn-secondary" onclick="loadTransactions()">
                    <i class="fa-solid fa-filter"></i> Saring
                </button>
                <span id="lblTrxCount" style="font-size:9.5px; color:var(--text-muted); margin-left:6px; font-weight:500;"></span>
            </div>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <button type="button" class="btn btn-warning" onclick="triggerTrialSaldoAwal()" style="color:#fff; background:#fa8c16; border-color:#fa8c16;" title="Uji Coba Pengiriman Saldo Awal ke Endpoint /temp/ INSW">
                    <i class="fa-solid fa-flask"></i> Uji Kirim Saldo Awal (Trial)
                </button>
                <a href="/xls/saldo_awal_rmb_insw.xlsx" class="btn btn-secondary" download style="display:inline-flex; align-items:center; gap:5px; text-decoration:none; color:inherit;" title="Unduh berkas stok awal dalam template resmi Excel INSW untuk diunggah di Step 2">
                    <i class="fa-solid fa-file-excel" style="color:#52c41a;"></i> Unduh Excel Saldo Awal (Format INSW)
                </a>
                <button type="button" class="btn btn-primary" onclick="pushSelectedTransactions()">
                    <i class="fa-solid fa-paper-plane"></i> Kirim Terpilih ke INSW
                </button>
                <?php if ($kekMode === 'dummy'): ?>
                <button type="button" class="btn btn-danger" onclick="triggerCleansingDummy()" title="Hapus semua data uji coba di endpoint /temp/ LNSW">
                    <i class="fa-solid fa-trash-can"></i> Cleansing Data Dummy
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="kek-table" id="tableTransactions">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">
                            <input type="checkbox" id="chkAllTrx" onchange="toggleSelectAllTrx(this.checked)">
                        </th>
                        <th>No Bukti Transaksi</th>
                        <th>Tanggal Bukti</th>
                        <th>Kode Barang</th>
                        <th>Uraian Barang</th>
                        <th>Kategori KEK</th>
                        <th>Qty / Unit</th>
                        <th>Nilai (IDR)</th>
                        <th>Dokumen Pabean</th>
                        <th>Status INSW</th>
                    </tr>
                </thead>
                <tbody id="tbodyTransactions">
                    <tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat data mutasi persediaan...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Content Card: Log Riwayat Pengiriman (Tab log) -->
    <div class="content-card" id="tabLogs" style="display:none;">
        <div class="toolbar-box">
            <div class="filter-group">
                <label style="font-weight:600;">Status:</label>
                <select id="filterLogStatus" class="form-control-compact" onchange="loadLogs()">
                    <option value="ALL">Semua Status</option>
                    <option value="SUCCESS">Success (01)</option>
                    <option value="FAILED">Gagal</option>
                </select>
                <label style="font-weight:600; margin-left:10px;">Kegiatan:</label>
                <select id="filterLogKegiatan" class="form-control-compact" onchange="loadLogs()">
                    <option value="ALL">Semua Kegiatan</option>
                    <option value="30">Pemasukan (30)</option>
                    <option value="31">Pengeluaran (31)</option>
                    <option value="32">Stock Opname (32)</option>
                    <option value="33">Adjustment (33)</option>
                    <option value="00">Saldo Awal</option>
                </select>
                <button type="button" class="btn btn-secondary" onclick="loadLogs()">
                    <i class="fa-solid fa-rotate"></i> Muat Ulang
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="kek-table" id="tableLogs">
                <thead>
                    <tr>
                        <th>Waktu Kirim</th>
                        <th>Kegiatan</th>
                        <th>No Dokumen Transaksi</th>
                        <th>Tgl Transaksi</th>
                        <th>Total Item</th>
                        <th>Nilai Total</th>
                        <th>Status Kirim</th>
                        <th>Kode / Pesan INSW</th>
                        <th>ID Transaksi INSW</th>
                        <th style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tbodyLogs">
                    <tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Memuat riwayat log pengiriman...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Content Card: Konfigurasi KEK (Tab settings) -->
    <div class="content-card" id="tabSettings" style="display:none;">
        <h3 style="font-size:12px; font-weight:600; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-sliders" style="color:var(--kek-purple);"></i> Pengaturan Integrasi KEK LNSW Gen2
        </h3>
        <form id="formKekSettings" onsubmit="saveKekSettings(event)">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:12px;">
                <div>
                    <label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:9.5px; cursor:pointer;">
                        <input type="checkbox" id="set_is_kek_active" <?= $isKekActive ? 'checked' : '' ?>>
                        <span>Aktifkan Integrasi IT Inventory KEK LNSW</span>
                    </label>

                    <div style="margin-top:10px;">
                        <label class="stat-label" style="display:block; margin-bottom:3px;">Klasifikasi Tipe Usaha:</label>
                        <select id="set_business_type" class="form-control-compact" style="width:100%; height:22px; font-size:9.5px;">
                            <option value="manufaktur" <?= $businessType === 'manufaktur' ? 'selected' : '' ?>>🏭 KEK Manufaktur (Industri Pengolahan - Bahan Baku, WIP, Jadi)</option>
                            <option value="logistik" <?= $businessType === 'logistik' ? 'selected' : '' ?>>📦 KEK Logistik (Gudang Berikat / PLB - HANYA Barang Dagangan)</option>
                        </select>
                    </div>

                    <div style="margin-top:8px;">
                        <label class="stat-label" style="display:block; margin-bottom:3px;">Lingkungan API (Gateway INSW):</label>
                        <select id="set_kek_mode" class="form-control-compact" style="width:100%; height:22px; font-size:9.5px;" onchange="updateTabKekBadges()">
                            <option value="dummy" <?= $kekMode === 'dummy' ? 'selected' : '' ?>>🟡 Data Percobaan (Testing) - https://api.insw.go.id/.../temp/...</option>
                            <option value="real" <?= $kekMode === 'real' ? 'selected' : '' ?>>🟢 Data Nyata (Live Production) - https://api.insw.go.id/...</option>
                        </select>
                    </div>
                </div>

                <div>
                    <div>
                        <label class="stat-label" style="display:block; margin-bottom:3px;">Header x-insw-key:</label>
                        <input type="text" id="set_insw_key" class="form-control-compact" style="width:100%; height:22px; font-size:9.5px; font-family:monospace;" value="<?= htmlspecialchars($setting['insw_key'] ?? 'pZ66hobzPpXBn2bMHVPTz0wG1pxuWQdo') ?>">
                    </div>

                    <div style="margin-top:8px;">
                        <label class="stat-label" style="display:block; margin-bottom:3px;">
                            Header x-unique-key (Mode Produksi / Live):
                            <span id="tabBadgeKeyReal" style="float:right; font-weight:600; color:#52c41a;">Live Production</span>
                        </label>
                        <input type="text" id="set_unique_key_real" class="form-control-compact" style="width:100%; height:22px; font-size:9.5px; font-family:monospace;" placeholder="Masukkan unique-key resmi dari portal INSW (e655...)" value="<?= htmlspecialchars($setting['unique_key_real'] ?? ($kekMode === 'real' ? $setting['unique_key'] : '') ?? '') ?>">
                    </div>

                    <div style="margin-top:8px;">
                        <label class="stat-label" style="display:block; margin-bottom:3px;">
                            Header x-unique-key (Mode Uji Coba / Percobaan):
                            <span id="tabBadgeKeyDummy" style="float:right; font-weight:600; color:#fa8c16;">Sandbox Dummy</span>
                        </label>
                        <input type="text" id="set_unique_key_dummy" class="form-control-compact" style="width:100%; height:22px; font-size:9.5px; font-family:monospace;" placeholder="e655669b83fe553f0bd756966311ecb694df907991293e5f0961d7e8157641e7" value="<?= htmlspecialchars(!empty($setting['unique_key_dummy']) ? $setting['unique_key_dummy'] : 'e655669b83fe553f0bd756966311ecb694df907991293e5f0961d7e8157641e7') ?>">
                    </div>
                    <input type="hidden" id="set_unique_key" value="<?= htmlspecialchars($setting['unique_key'] ?? '') ?>">

                    <div style="margin-top:12px; display:flex; gap:6px;">
                        <button type="submit" class="btn btn-primary" id="btnSaveSetting">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan Konfigurasi
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Modal Inspector Payload & Respons JSON -->
    <div class="modal-overlay" id="modalInspector">
        <div class="modal-card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="font-size:12px; font-weight:600;"><i class="fa-solid fa-code"></i> Inspeksi Data Pertukaran JSON INSW</h3>
                <button type="button" class="btn btn-secondary" onclick="closeInspector()">✕</button>
            </div>
            <div class="modal-body-scroll">
                <div style="font-weight:600; font-size:9.5px; margin-bottom:3px; color:#1890ff;">Request Payload (Dikirimkan):</div>
                <div class="code-box" id="inspectRequest"></div>

                <div style="font-weight:600; font-size:9.5px; margin-top:8px; margin-bottom:3px; color:#52c41a;">Response Payload (Jawaban Gateway INSW):</div>
                <div class="code-box" id="inspectResponse"></div>
            </div>
            <div style="text-align:right;">
                <button type="button" class="btn btn-secondary" onclick="closeInspector()">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        let currentTab = '30';
        let rawLogsData = [];

        // Auto sync tema tampilan (light/dark)
        const urlParams = new URLSearchParams(window.location.search);
        const curTheme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
        document.documentElement.setAttribute('data-theme', curTheme);
        document.body.setAttribute('data-theme', curTheme);
        window.addEventListener('message', function(e) {
            if (e.data && e.data.theme) {
                document.documentElement.setAttribute('data-theme', e.data.theme);
                document.body.setAttribute('data-theme', e.data.theme);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadSummary();
            loadTransactions();
            updateTabKekBadges();
        });

        async function loadSummary() {
            try {
                const res = await fetch('kek_integration.php?action=summary');
                const json = await res.json();
                if (json.status === 'OK') {
                    document.getElementById('statIn').innerText = Number(json.total_in).toLocaleString('id-ID');
                    document.getElementById('statOut').innerText = Number(json.total_out).toLocaleString('id-ID');
                    document.getElementById('statSto').innerText = Number(json.total_sto).toLocaleString('id-ID');
                    document.getElementById('statAdj').innerText = Number(json.total_adj).toLocaleString('id-ID');
                    document.getElementById('statFailed').innerText = Number(json.total_failed).toLocaleString('id-ID');
                }
            } catch (err) {
                console.error(err);
            }
        }

        function switchKekTab(tabKey, el) {
            currentTab = tabKey;
            document.querySelectorAll('.nav-tab-item').forEach(t => t.classList.remove('active'));
            if (el) el.classList.add('active');

            document.getElementById('tabTransactions').style.display = (tabKey === '30' || tabKey === '31') ? 'block' : 'none';
            document.getElementById('tabLogs').style.display = (tabKey === 'log') ? 'block' : 'none';
            document.getElementById('tabSettings').style.display = (tabKey === 'settings') ? 'block' : 'none';

            if (tabKey === '30' || tabKey === '31') {
                loadTransactions();
            } else if (tabKey === 'log') {
                loadLogs();
            }
        }

        async function loadTransactions() {
            const tbody = document.getElementById('tbodyTransactions');
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat transaksi...</td></tr>`;

            const tglAwal = document.getElementById('filterTglAwal').value;
            const tglAkhir = document.getElementById('filterTglAkhir').value;

            try {
                const url = `kek_integration.php?action=get_pending_transactions&jenis=${currentTab}&tgl_awal=${tglAwal}&tgl_akhir=${tglAkhir}`;
                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK') {
                    const data = json.data || [];
                    const lbl = document.getElementById('lblTrxCount');
                    if (data.length === 0) {
                        if (lbl) lbl.innerText = '';
                        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Tidak ada transaksi persediaan pada rentang tanggal ini.</td></tr>`;
                        return;
                    }

                    const uniqueDocs = new Set(data.map(d => d.NO_BUKTI)).size;
                    if (lbl) {
                        lbl.innerHTML = `(<b style="color:var(--text-main);">${data.length}</b> item dari <b style="color:var(--text-main);">${uniqueDocs}</b> dokumen mutasi)`;
                    }

                    tbody.innerHTML = data.map(r => {
                        const statusBadge = (r.kek_status === 'SUCCESS')
                            ? `<span class="status-badge status-badge-success"><i class="fa-solid fa-check"></i> Terkirim INSW</span>`
                            : `<span class="status-badge status-badge-pending">Pending</span>`;

                        const dokPabean = (r.NOMOR_DAFTAR && r.NOMOR_DAFTAR !== '-') 
                            ? `<span style="font-weight:600; color:#1890ff;">BC ${r.BC || ''}</span> No: ${r.NOMOR_DAFTAR}` 
                            : `<span style="color:var(--text-muted);">-</span>`;

                        return `
                            <tr>
                                <td style="text-align:center;">
                                    <input type="checkbox" class="chkTrxItem" value="${escapeHtml(r.row_uid || '')}">
                                </td>
                                <td style="font-weight:600; font-family:monospace;">
                                    ${escapeHtml(r.NO_BUKTI || '-')}
                                    <span style="font-size:8.5px; font-weight:normal; color:var(--text-muted); display:block;">Item #${escapeHtml(r.SERI_BARANG || '1')}</span>
                                </td>
                                <td>${r.TGL_BUKTI || '-'}</td>
                                <td><code style="color:var(--primary);">${escapeHtml(r.KODE_BARANG || '-')}</code></td>
                                <td>${escapeHtml(r.URAIAN || '-')}</td>
                                <td><span style="font-weight:600;">${escapeHtml(r.KLASIFIKASI || '-')}</span></td>
                                <td style="font-weight:600;">${Number(r.QTY).toLocaleString('id-ID')} ${escapeHtml(r.UNIT || '')}</td>
                                <td>Rp ${Number(r.NILAI || r.NILAI_PABEAN || 0).toLocaleString('id-ID')}</td>
                                <td>${dokPabean}</td>
                                <td>${statusBadge}</td>
                            </tr>
                        `;
                    }).join('');
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:#ff4d4f;">Gagal memuat: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function toggleSelectAllTrx(checked) {
            document.querySelectorAll('.chkTrxItem').forEach(cb => cb.checked = checked);
        }

        async function pushSelectedTransactions() {
            const selected = Array.from(document.querySelectorAll('.chkTrxItem:checked')).map(cb => cb.value);
            if (selected.length === 0) {
                Swal.fire({ icon: 'warning', title: 'Pilih Transaksi', text: 'Centang minimal satu transaksi yang ingin dikirim ke INSW.' });
                return;
            }

            const confirm = await Swal.fire({
                title: 'Kirim ke Gateway INSW?',
                text: `Sebanyak ${selected.length} transaksi akan dikirimkan ke LNSW Gen2.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1890ff',
                confirmButtonText: 'Ya, Kirim Sekarang'
            });

            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Mengirim Transaksi...',
                text: 'Menghubungkan ke gateway INSW...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const fd = new FormData();
                fd.append('action', 'push_transactions');
                fd.append('kd_kegiatan', currentTab);
                selected.forEach(id => fd.append('ids[]', id));

                const res = await fetch('kek_integration.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (json.success || json.status === true || json.code === '01') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Dikirim!',
                        text: json.message || 'Data transaksi telah berhasil diterima gateway INSW.'
                    });
                    loadSummary();
                    loadTransactions();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Pengiriman Gagal',
                        text: json.message || 'Gateway INSW menolak request transaksi.'
                    });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: err.message });
            }
        }

                async function triggerTrialSaldoAwal() {
            const confirm = await Swal.fire({
                title: 'Uji Kirim Saldo Awal (Trial)?',
                html: `<div style="text-align:left; font-size:11px; line-height:1.6;">
                    Data Saldo Awal contoh akan dikirimkan ke Endpoint Percobaan LNSW:<br>
                    &bull; <b>URL</b>: <code>/inventory/temp/saldoAwal</code><br>
                    &bull; <b>Kunci Sandbox</b>: <code>b63157f... (Akun Dummy LNSW)</code><br>
                    &bull; <b>Data</b>: Sampel material aktif internal<br><br>
                    Hasil respon transaksi akan diverifikasi langsung dari Gateway INSW.
                </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#fa8c16',
                confirmButtonText: 'Ya, Uji Coba Sekarang',
                cancelButtonText: 'Batal'
            });

            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Mengirim Saldo Awal Trial...',
                html: '<span class="spinner"></span> Menghubungi API Gateway INSW...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const fd = new FormData();
                fd.append('action', 'push_trial_saldo_awal');

                const res = await fetch('kek_integration.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (json.success || json.status === true || json.code === '01') {
                    const postHdr = json.json?.data?.postHeader || {};
                    const items   = json.json?.data?.postBarang || [];
                    Swal.fire({
                        icon: 'success',
                        title: 'Uji Coba Sukses (200 OK)!',
                        html: `<div style="text-align:left; font-size:11px; line-height:1.6;">
                            <b>Respon INSW Berhasil Diterima:</b><br>
                            &bull; Status: <b>${escapeHtml(json.message || 'Success')}</b> (Code: 01)<br>
                            &bull; ID Header: <code>${escapeHtml(postHdr.idHeader || '-')}</code><br>
                            &bull; Perusahaan: <b>${escapeHtml(postHdr.namaPerusahaan || 'admin_insw_kek')}</b><br>
                            &bull; Status Dokumen: <span class="badge badge-warning">${escapeHtml(postHdr.urStatus || 'Belum Final')}</span><br>
                            &bull; Jumlah Barang: <b>${items.length} item</b> terkirim<br>
                            &bull; Unique Key Sandbox: <code>${escapeHtml(postHdr.uniqueKey || '-')}</code><br><br>
                            <span style="color:#52c41a; font-weight:600;">Data berhasil tersimpan di log dan siap diverifikasi di portal IT Inventory!</span>
                        </div>`
                    });
                    loadSummary();
                    loadTransactions();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Uji Coba Gagal',
                        text: json.message || 'Gateway INSW menolak request saldo awal.'
                    });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Kesalahan Sistem', text: err.message });
            }
        }

        async function triggerCleansingDummy() {
            const confirm = await Swal.fire({
                title: 'Cleansing Data Dummy?',
                text: 'Seluruh data pengujian pada endpoint /temp/ di server INSW akan dihapus bersih.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                confirmButtonText: 'Ya, Hapus Data Percobaan'
            });

            if (!confirm.isConfirmed) return;

            try {
                const fd = new FormData();
                fd.append('action', 'clean_dummy');
                const res = await fetch('kek_integration.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (json.success || json.status === true || json.code === '01') {
                    Swal.fire({ icon: 'success', title: 'Cleansing Sukses', text: json.message || 'Data uji coba di INSW telah dibersihkan.' });
                    loadTransactions();
                    loadSummary();
                    if (typeof loadLogs === 'function') loadLogs();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal Cleansing', text: json.message || 'Gagal menghapus data di INSW.' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Kesalahan Jaringan', text: err.message });
            }
        }

        async function loadLogs() {
            const tbody = document.getElementById('tbodyLogs');
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat log...</td></tr>`;

            const status = document.getElementById('filterLogStatus').value;
            const kegiatan = document.getElementById('filterLogKegiatan').value;

            try {
                const url = `kek_integration.php?action=get_logs&status=${status}&kegiatan=${kegiatan}&limit=50`;
                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK') {
                    rawLogsData = json.data || [];
                    if (rawLogsData.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:var(--text-muted);">Belum ada log pengiriman transaksi.</td></tr>`;
                        return;
                    }

                    const kegMap = {
                        '30': '<span style="color:#52c41a; font-weight:600;">30 - Pemasukan</span>',
                        '31': '<span style="color:#1890ff; font-weight:600;">31 - Pengeluaran</span>',
                        '32': '<span style="color:#722ed1; font-weight:600;">32 - Stock Opname</span>',
                        '33': '<span style="color:#faad14; font-weight:600;">33 - Adjustment</span>',
                        '00': '<span style="color:#13c2c2; font-weight:600;">00 - Saldo Awal</span>'
                    };

                    tbody.innerHTML = rawLogsData.map((r, idx) => {
                        const statusBadge = (r.status_kirim === 'SUCCESS')
                            ? `<span class="status-badge status-badge-success"><i class="fa-solid fa-circle-check"></i> SUCCESS</span>`
                            : `<span class="status-badge status-badge-failed"><i class="fa-solid fa-circle-xmark"></i> FAILED (${r.http_code})</span>`;

                        return `
                            <tr>
                                <td>${r.pushed_at || '-'}</td>
                                <td>${kegMap[r.kd_kegiatan] || r.kd_kegiatan}</td>
                                <td style="font-weight:600; font-family:monospace;">${escapeHtml(r.nomor_dok_kegiatan || '-')}</td>
                                <td>${r.tanggal_kegiatan || '-'}</td>
                                <td style="text-align:center; font-weight:600;">${r.total_item || 0}</td>
                                <td>Rp ${Number(r.total_nilai || 0).toLocaleString('id-ID')}</td>
                                <td>${statusBadge}</td>
                                <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(r.response_message || '')}">
                                    ${escapeHtml(r.response_message || '-')}
                                </td>
                                <td><code style="font-size:9.5px; color:#722ed1;">${r.insw_id_transaksi || '-'}</code></td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-secondary btn-xs" onclick="openInspector(${idx})" title="Inspeksi JSON Request & Response">
                                        <i class="fa-solid fa-code"></i> JSON
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:20px; color:#ff4d4f;">Gagal memuat log: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function openInspector(idx) {
            const row = rawLogsData[idx];
            if (!row) return;

            let reqFormatted = row.request_payload || 'No request payload recorded';
            try { reqFormatted = JSON.stringify(JSON.parse(reqFormatted), null, 2); } catch(e) {}

            let respFormatted = row.response_payload || 'No response payload recorded';
            try { respFormatted = JSON.stringify(JSON.parse(respFormatted), null, 2); } catch(e) {}

            document.getElementById('inspectRequest').innerText = reqFormatted;
            document.getElementById('inspectResponse').innerText = respFormatted;
            document.getElementById('modalInspector').classList.add('active');
        }

        function closeInspector() {
            document.getElementById('modalInspector').classList.remove('active');
        }

        async function saveKekSettings(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSaveSetting');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';

            const fd = new FormData();
            fd.append('action', 'save_setting');
            if (document.getElementById('set_is_kek_active').checked) {
                fd.append('is_kek_active', '1');
            }
            fd.append('business_type', document.getElementById('set_business_type').value);
            const kMode = document.getElementById('set_kek_mode').value;
            fd.append('kek_mode', kMode);
            fd.append('insw_key', document.getElementById('set_insw_key').value);
            const uKeyReal = document.getElementById('set_unique_key_real') ? document.getElementById('set_unique_key_real').value : '';
            const uKeyDummy = document.getElementById('set_unique_key_dummy') ? document.getElementById('set_unique_key_dummy').value : '';
            fd.append('unique_key_real', uKeyReal);
            fd.append('unique_key_dummy', uKeyDummy);
            fd.append('unique_key', (kMode === 'real') ? uKeyReal : uKeyDummy);

            try {
                const res = await fetch('kek_integration.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({ icon: 'success', title: 'Tersimpan', text: json.message });
                    loadSummary();
                    updateTabKekBadges();
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: json.message });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Kesalahan', text: err.message });
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Konfigurasi';
            }
        }

        function updateTabKekBadges() {
            const mode = document.getElementById('set_kek_mode') ? document.getElementById('set_kek_mode').value : 'dummy';
            const badgeReal = document.getElementById('tabBadgeKeyReal');
            const badgeDummy = document.getElementById('tabBadgeKeyDummy');
            const inputReal = document.getElementById('set_unique_key_real');
            const inputDummy = document.getElementById('set_unique_key_dummy');

            if (mode === 'real') {
                if (badgeReal) { badgeReal.innerHTML = '🟢 Sedang Digunakan (Live)'; badgeReal.style.color = '#52c41a'; }
                if (badgeDummy) { badgeDummy.innerHTML = '⚪ Tersimpan (Tidak Aktif)'; badgeDummy.style.color = '#8c8c8c'; }
                if (inputReal) inputReal.style.borderColor = '#52c41a';
                if (inputDummy) inputDummy.style.borderColor = '';
            } else {
                if (badgeDummy) { badgeDummy.innerHTML = '🟠 Sedang Digunakan (Sandbox)'; badgeDummy.style.color = '#fa8c16'; }
                if (badgeReal) { badgeReal.innerHTML = '⚪ Tersimpan (Tidak Aktif)'; badgeReal.style.color = '#8c8c8c'; }
                if (inputDummy) inputDummy.style.borderColor = '#fa8c16';
                if (inputReal) inputReal.style.borderColor = '';
            }
        }

        function openMfaInswPopup() {
            const cmpId = '<?= htmlspecialchars($company['cmp_id'] ?? $sessionCmpId) ?>';
            const adminParam = <?= $isAdmin ? "'&is_admin=1'" : "''" ?>;
            const w = window.open(`mfa.php?type=insw&cmp_id=${encodeURIComponent(cmpId)}${adminParam}`, 'mfa_insw_window', 'width=380,height=390,resizable=yes,scrollbars=no,status=no');
            if (w) {
                w.focus();
            } else {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pop-up Diblokir',
                    html: 'Browser memblokir jendela popup MFA. Silakan izinkan pop-up atau klik <a href="mfa.php?type=insw&cmp_id=' + encodeURIComponent(cmpId) + '" target="_blank" style="color:#722ed1; font-weight:600;">di sini</a>.'
                });
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>
</html>

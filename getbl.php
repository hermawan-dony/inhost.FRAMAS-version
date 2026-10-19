<?php
/**
 * CEISA 4.0 Manifest BC 1.1 Service (getbl.php)
 * Mengambil data pos/subpos manifes kedatangan sarana pengangkut (BC 1.1) dari API Gateway Bea Cukai.
 * Mendukung format JSON dan TSV (kompatibel 1:1 dengan PowerBuilder w_bc_ceisa_getbc11.srw).
 */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';

// Fungsi bantuan ambil nilai dari array dengan aman
if (!function_exists('get_safe_value')) {
    function get_safe_value($array, $key, $default = '') {
        if (is_array($array) && array_key_exists($key, $array)) {
            return $array[$key];
        }
        return $default;
    }
}

$a = trim($_GET["a"] ?? ($_POST["a"] ?? ''));
if ($a === '') {
    $action = trim($_GET["action"] ?? ($_POST["action"] ?? ''));
    if ($action === 'list_manifest_bl') {
        // Ambil daftar Host BL dari Manifest Host to Host lokal untuk quick-linking
        header('Content-Type: application/json; charset=utf-8');
        $targetCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($cmp_id) ? $cmp_id : '');
        $limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
        $search = trim($_GET['q'] ?? '');

        $where = "WHERE 1=1";
        $params = [];
        $types = "";

        if (!empty($targetCmp)) {
            $where .= " AND (b.cmp_id = ? OR b.cmp_id = '' OR b.cmp_id IS NULL)";
            $params[] = $targetCmp;
            $types .= "s";
        }

        if (!empty($search)) {
            $where .= " AND (b.nomor_host_bl LIKE ? OR b.nomor_aju LIKE ? OR b.nomor_bl LIKE ? OR h.nama_sarana_pengangkut LIKE ?)";
            $sTerm = "%{$search}%";
            $params = array_merge($params, [$sTerm, $sTerm, $sTerm, $sTerm]);
            $types .= "ssss";
        }

        $sql = "SELECT b.seri_bl, b.nomor_aju, b.nomor_host_bl, b.tanggal_host_bl, b.nomor_bl, b.tanggal_bl, 
                       h.kode_kantor, b.nama_pengirim, b.nama_penerima, b.berat, b.jumlah_kemasan, b.jenis_kemasan,
                       h.nama_sarana_pengangkut, h.nomor_voyage, h.nomor_bc11, h.tanggal_bc11, h.nomor_pos_bc11, h.status
                FROM bc_ceisa_manifes_bl b
                LEFT JOIN bc_ceisa_manifes_header h ON h.nomor_aju = b.nomor_aju AND (h.cmp_id = b.cmp_id OR h.cmp_id = '')
                {$where}
                ORDER BY b.seri_bl ASC
                LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $items = [];
            while ($r = $res->fetch_assoc()) {
                $items[] = $r;
            }
            $stmt->close();
            echo json_encode(['status' => 'OK', 'count' => count($items), 'data' => $items]);
            exit;
        }
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengambil daftar BL']);
        exit;
    }

    if ($action === 'sync_to_manifest') {
        // Sinkronisasi data BC 1.1 yang didapat ke tabel Manifest Host to Host
        header('Content-Type: application/json; charset=utf-8');
        $nomorAju = trim($_POST['nomor_aju'] ?? '');
        $noHostBl = trim($_POST['nomor_host_bl'] ?? '');
        $noBc11   = trim($_POST['nomor_bc11'] ?? '');
        $tglBc11  = trim($_POST['tanggal_bc11'] ?? '');
        $posBc11  = trim($_POST['nomor_pos'] ?? '');

        if (empty($nomorAju) && empty($noHostBl)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU atau Nomor Host BL wajib diisi']);
            exit;
        }

        $updated = false;
        if (!empty($nomorAju)) {
            $stmt = $koneksi->prepare("UPDATE bc_ceisa_manifes_header SET nomor_bc11 = ?, tanggal_bc11 = ?, nomor_pos_bc11 = ?, updated_at = NOW() WHERE nomor_aju = ?");
            if ($stmt) {
                $stmt->bind_param("ssss", $noBc11, $tglBc11, $posBc11, $nomorAju);
                $stmt->execute();
                $updated = ($stmt->affected_rows > 0);
                $stmt->close();
            }
        }

        if (!empty($noHostBl)) {
            $stmtBl = $koneksi->prepare("UPDATE bc_ceisa_manifes_bl SET nomor_pos_bc11 = ? WHERE nomor_host_bl = ?");
            if ($stmtBl) {
                $stmtBl->bind_param("ss", $posBc11, $noHostBl);
                $stmtBl->execute();
                $stmtBl->close();
            }
        }

        echo json_encode(['status' => 'OK', 'message' => 'Data BC 1.1 berhasil disinkronkan ke Manifest Host to Host']);
        exit;
    }

    if ($action === 'manifest_detail' || !empty($_GET['id_header'])) {
        $idHeader = trim($_GET['id_header'] ?? ($_POST['id_header'] ?? ''));
        if (empty($idHeader)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'ERROR', 'message' => 'id_header wajib diisi']);
            exit;
        }

        $targetCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : (!empty($cmp_id) ? $cmp_id : 'TVL')));
        if (empty($targetCmp) || $targetCmp === 'default') $targetCmp = 'TVL';

        require_once __DIR__ . '/manifest_api.php';
        $bearer = getActivePortalMfaToken($targetCmp);

        $urlDetail = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/DatamanifesHeader/" . urlencode($idHeader);
        $ch = curl_init($urlDetail);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$bearer}",
                "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                "Accept: application/json, text/plain, */*",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/"
            ]
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($resp, true);
        $wantsJson = (!isset($_REQUEST['format']) || $_REQUEST['format'] === 'json' || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if ($code === 200 && !empty($json)) {
                echo json_encode(['status' => 'OK', 'id_header' => $idHeader, 'data' => $json['data'] ?? $json]);
            } else {
                echo json_encode(['status' => 'ERROR', 'code' => $code, 'message' => 'Gagal mengambil detail manifes dari Bea Cukai', 'raw' => $json ?: $resp]);
            }
            exit;
        }

        // TSV format untuk PowerBuilder
        header('Content-Type: text/plain; charset=utf-8');
        $d = $json['data'] ?? ($json ?? []);
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                if (!is_array($v)) echo "{$k}\t{$v}\n";
            }
        }
        exit;
    }

    if ($action === 'search_container' || !empty($_GET['nomor_container'])) {
        $noCont = trim($_GET['nomor_container'] ?? ($_GET['container'] ?? ($_POST['nomor_container'] ?? '')));
        $targetCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : (!empty($cmp_id) ? $cmp_id : 'TVL')));
        if (empty($targetCmp) || $targetCmp === 'default') $targetCmp = 'TVL';

        $where = "WHERE 1=1";
        $params = [];
        $types = "";

        if (!empty($targetCmp)) {
            $where .= " AND (p.cmp_id = ? OR p.cmp_id = '' OR p.cmp_id IS NULL)";
            $params[] = $targetCmp;
            $types .= "s";
        }

        if (!empty($noCont)) {
            $where .= " AND (p.nomor_container LIKE ? OR b.nomor_host_bl LIKE ? OR h.nomor_bc11 LIKE ?)";
            $sTerm = "%{$noCont}%";
            $params = array_merge($params, [$sTerm, $sTerm, $sTerm]);
            $types .= "sss";
        }

        $sql = "SELECT p.nomor_container, p.ukuran_container, p.jenis_container, p.type_container, p.nomor_segel,
                       b.nomor_host_bl, b.tanggal_host_bl, b.nomor_bl, b.tanggal_bl, b.nama_pengirim, b.alamat_pengirim,
                       b.nama_penerima, b.alamat_penerima, b.berat, b.jumlah_kemasan, b.jenis_kemasan,
                       h.nomor_aju, h.nomor_bc11, h.tanggal_bc11, h.nomor_pos_bc11, h.kode_kantor, h.jenis_manifes,
                       h.nama_sarana_pengangkut, h.nomor_voyage, h.tanggal_tiba, h.tanggal_berangkat
                FROM bc_ceisa_manifes_bl_petikemas p
                LEFT JOIN bc_ceisa_manifes_bl b ON b.nomor_host_bl = p.nomor_host_bl AND b.nomor_aju = p.nomor_aju AND (b.cmp_id = p.cmp_id OR b.cmp_id = '')
                LEFT JOIN bc_ceisa_manifes_header h ON h.nomor_aju = p.nomor_aju AND (h.cmp_id = p.cmp_id OR h.cmp_id = '')
                {$where}
                ORDER BY p.seri_container ASC
                LIMIT 200";

        $stmt = $koneksi->prepare($sql);
        $containers = [];
        if ($stmt) {
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $containers[] = $r;
            }
            $stmt->close();
        }

        $wantsTsv = (isset($_REQUEST['format']) && $_REQUEST['format'] === 'tsv');
        if ($wantsTsv) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "No\tNomor Container\tUkuran\tJenis\tNomor Host BL\tNomor BC 1.1\tTanggal BC 1.1\tJenis Manifes\tTanggal Host BL\tNama Pengirim\tAlamat Pengirim\tNama Penerima\tAlamat Penerima\tSarana Pengangkut\tNomor Voyage\n";
            foreach ($containers as $i => $c) {
                $no = $i + 1;
                $jManifes = ($c['jenis_manifes'] === '12') ? 'Outward (BC 1.1)' : 'Inward (BC 1.1)';
                echo "{$no}\t{$c['nomor_container']}\t{$c['ukuran_container']}\t{$c['jenis_container']}\t{$c['nomor_host_bl']}\t{$c['nomor_bc11']}\t{$c['tanggal_bc11']}\t{$jManifes}\t{$c['tanggal_host_bl']}\t{$c['nama_pengirim']}\t{$c['alamat_pengirim']}\t{$c['nama_penerima']}\t{$c['alamat_penerima']}\t{$c['nama_sarana_pengangkut']}\t{$c['nomor_voyage']}\n";
            }
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'OK',
            'count'  => count($containers),
            'query'  => $noCont,
            'data'   => $containers
        ]);
        exit;
    }

    if ($action === 'search_vessel' || !empty($_GET['vessel'])) {
        $vessel = trim($_GET['vessel'] ?? ($_GET['nama_kapal'] ?? ''));
        if (empty($vessel)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'ERROR', 'message' => 'Nama Sarana Pengangkut / Kapal tidak boleh kosong']);
            exit;
        }

        $targetCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : (!empty($cmp_id) ? $cmp_id : 'TVL')));
        if (empty($targetCmp) || $targetCmp === 'default') {
            $targetCmp = 'TVL';
        }

        require_once __DIR__ . '/manifest_api.php';
        $bearer = getActivePortalMfaToken($targetCmp);

        $urlVessel = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/MapController/" . rawurlencode($vessel);
        $ch = curl_init($urlVessel);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$bearer}",
                "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                "Accept: application/json, text/plain, */*",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/"
            ]
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($resp, true);
        $voyages = ($code === 200 && !empty($json['data'])) ? $json['data'] : [];

        $wantsTsv = (isset($_REQUEST['format']) && $_REQUEST['format'] === 'tsv');
        if ($wantsTsv) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "No\tNama Sarana Pengangkut\tIMO\tCall Sign\tTanggal Tiba Berangkat\tKode Kantor\tNomor Voyage\tNomor Daftar BC11\tJenis Manifes\tTanggal Daftar\tPel Asal\tPel Bongkar\tKade\tJumlah Pos\tJumlah Container\tBerat Bruto\n";
            foreach ($voyages as $i => $r) {
                $no = $i + 1;
                $tglDoc = $r['tanggalTiba'] ?? ($r['tanggalBerangkat'] ?? '');
                $isOutward = (stripos($r['jenisManifes'] ?? '', 'outward') !== false) || (empty($r['tanggalTiba']) && !empty($r['tanggalBerangkat']));
                $jMan = $isOutward ? 'Outward (BC 1.1)' : 'Inward (BC 1.1)';
                $asal = $r['namaPelabuhanAsal'] ?? ($r['kodePelabuhanAsal'] ?? '');
                $bongkar = $r['namaPelabuhanBongkar'] ?? ($r['kodePelabuhanBongkar'] ?? '');
                echo "{$no}\t{$r['namaSaranaPengangkut']}\t{$r['imoNumber']}\t{$r['callSign']}\t{$tglDoc}\t{$r['kodeKantor']}\t{$r['nomorVoyage']}\t{$r['nomorDaftar']}\t{$jMan}\t{$r['tanggalDaftar']}\t{$asal}\t{$bongkar}\t{$r['kade']}\t{$r['jumlahPos']}\t{$r['jumlahContainer']}\t{$r['berat']}\n";
            }
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        if ($code === 200 && !empty($json['data'])) {
            echo json_encode([
                'status' => 'OK',
                'vessel' => $vessel,
                'count'  => count($json['data']),
                'data'   => $json['data']
            ]);
            exit;
        }

        echo json_encode([
            'status'  => 'ERROR',
            'message' => 'Data Manifes Kedatangan tidak ditemukan untuk Sarana Pengangkut "' . $vessel . '".',
            'raw'     => $json ?: $resp,
            'code'    => $code
        ]);
        exit;
    }

    echo "Nomor BL / Host BL tidak boleh kosong.";
    exit;
}

// ----------------------------------------------------
// RESOLUSI TOKEN MFA CEISA 4.0 (Multi-Company Scoped)
// ----------------------------------------------------
// RESOLUSI TOKEN MFA CEISA 4.0 (Multi-Company Scoped)
// ----------------------------------------------------
$targetCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : (!empty($cmp_id) ? $cmp_id : 'TVL')));
if (empty($targetCmp) || $targetCmp === 'default') {
    $targetCmp = 'TVL';
}

require_once __DIR__ . '/manifest_api.php';
$bearer = getActivePortalMfaToken($targetCmp);

// ----------------------------------------------------
// 1. QUERY MANIFEST HEADER (getByNoHostBlTanggalAndKodeKantor)
// ----------------------------------------------------
$hostprd = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/getByNoHostBlTanggalAndKodeKantor?noHostBl=" . urlencode($a);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$bearer}",
        "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
        "Accept: application/json, text/plain, */*",
        "Origin: https://portal.beacukai.go.id",
        "Referer: https://portal.beacukai.go.id/"
    ],
]);
$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$err = curl_error($curl);
curl_close($curl);

// Jika HTTP 401 Unauthorized, refresh MFA token sekali lagi dan ulangi
if ($httpCode === 401 || (empty($err) && strpos($response, 'Unauthorized') !== false)) {
    if (file_exists(__DIR__ . '/initmfa.php')) {
        unset($_SESSION["mfatoken_" . $targetCmp]);
        unset($_SESSION["mfatoken"]);
        $tokenCacheFile = sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $targetCmp) . '.json';
        if (file_exists($tokenCacheFile)) @unlink($tokenCacheFile);
        
        require __DIR__ . '/initmfa.php';
        $bearer = $_SESSION["mfatoken_" . $targetCmp] ?? ($_SESSION["mfatoken"] ?? ($activeToken ?? ''));

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $hostprd,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$bearer}",
                "Content-Type: application/json",
                "Accept: application/json, text/plain, */*"
            ],
        ]);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
    }
}

$idHeader = '';
$resultData = [];

if ($err) {
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'cURL Error: ' . $err]);
        exit;
    }
    echo "cURL Error #:" . $err;
    exit;
}

$data = json_decode($response, true);
$data_header = get_safe_value($data, 'data');
$data_item = get_safe_value($data_header, 0, []);

$kodeKantor          = get_safe_value($data_item, 'kodeKantor');
$nomorDaftar         = get_safe_value($data_item, 'nomorDaftar');
$tanggalDaftar       = get_safe_value($data_item, 'tanggalDaftar');
$nomorPos            = get_safe_value($data_item, 'nomorPos');
$nomorBl             = get_safe_value($data_item, 'nomorBl');
$tanggalBl           = get_safe_value($data_item, 'tanggalBl');
$nomorHostBl         = get_safe_value($data_item, 'nomorHostBl');
$tanggalHostBl       = get_safe_value($data_item, 'tanggalHostBl');
$namaPengirim        = get_safe_value($data_item, 'namaPengirim');
$alamatPengirimRaw   = get_safe_value($data_item, 'alamatPengirim');
$alamatPengirim      = str_replace(["\r", "\n"], ' ', $alamatPengirimRaw);
$berat               = get_safe_value($data_item, 'berat');
$jenisKemasan        = get_safe_value($data_item, 'jenisKemasan');
$jumlahKemasan       = get_safe_value($data_item, 'jumlahKemasan');
$jumlahContainer     = get_safe_value($data_item, 'jumlahContainer');
$motherVessel        = get_safe_value($data_item, 'motherVessel');
$kodePelabuhanAsal   = get_safe_value($data_item, 'kodePelabuhanAsal');
$kodePelabuhanBongkar= get_safe_value($data_item, 'kodePelabuhanBongkar');
$kodePelabuhanTransit= get_safe_value($data_item, 'kodePelabuhanTransit');
$kodePelabuhanAkhir  = get_safe_value($data_item, 'kodePelabuhanAkhir');
$nomorVoyageHeader   = get_safe_value($data_item, 'nomorVoyage');
$namaPelabuhanAsal   = get_safe_value($data_item, 'namaPelabuhanAsal');
$namaPelabuhanBongkar= get_safe_value($data_item, 'namaPelabuhanBongkar');
$namaPelabuhanTransit= get_safe_value($data_item, 'namaPelabuhanTransit');
$namaPelabuhanAkhir  = get_safe_value($data_item, 'namaPelabuhanAkhir');
$idHeader            = get_safe_value($data_item, 'idHeader');

$tanggalTiba         = '';
$nomorVoyage_detail  = '';

// ----------------------------------------------------
// 2. QUERY MANIFEST DETAIL JIKA idHeader TERSEDIA
// ----------------------------------------------------
if (!empty($idHeader)) {
    $hostDetail = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/DatamanifesHeader/{$idHeader}";
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $hostDetail,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$bearer}",
            "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
            "Accept: application/json, text/plain, */*",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/"
        ],
    ]);
    $resDetail = curl_exec($curl);
    curl_close($curl);

    if ($resDetail) {
        $dataDet = json_decode($resDetail, true);
        $detItem = get_safe_value($dataDet, 'data', []);
        $nomorVoyage_detail = get_safe_value($detItem, 'nomorVoyage');
        $tanggalTiba        = get_safe_value($detItem, 'tanggalTiba');
    }
}

// ----------------------------------------------------
// 3. CARI DATA DI MANIFEST HOST TO HOST LOKAL (LINKING)
// ----------------------------------------------------
$localManifest = null;
if (!empty($nomorHostBl) || !empty($a)) {
    $searchBl = !empty($nomorHostBl) ? $nomorHostBl : $a;
    $stmtLoc = $koneksi->prepare("SELECT b.nomor_aju, b.nomor_host_bl, b.tanggal_host_bl, h.kode_kantor, 
                                         h.nama_sarana_pengangkut, h.nomor_voyage, h.nomor_bc11, h.tanggal_bc11, h.nomor_pos_bc11, h.status
                                  FROM bc_ceisa_manifes_bl b
                                  LEFT JOIN bc_ceisa_manifes_header h ON h.nomor_aju = b.nomor_aju AND (h.cmp_id = b.cmp_id OR h.cmp_id = '')
                                  WHERE b.nomor_host_bl = ? OR b.nomor_bl = ?
                                  ORDER BY b.seri_bl ASC LIMIT 1");
    if ($stmtLoc) {
        $stmtLoc->bind_param("ss", $searchBl, $searchBl);
        $stmtLoc->execute();
        $rLoc = $stmtLoc->get_result()->fetch_assoc();
        if ($rLoc) {
            $localManifest = $rLoc;
        }
        $stmtLoc->close();
    }
}

// ----------------------------------------------------
// 4. OUTPUT RESPONSE: JSON ATAU TSV (POWERBUILDER 1:1)
// ----------------------------------------------------
$wantsJson = (isset($_REQUEST['format']) && $_REQUEST['format'] === 'json') ||
             (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($nomorDaftar) && empty($nomorPos) && empty($idHeader)) {
        echo json_encode([
            'status' => 'EMPTY',
            'message' => 'Data Manifest BC 1.1 tidak ditemukan untuk nomor: ' . htmlspecialchars($a),
            'raw' => $data
        ]);
        exit;
    }

    $items = [];
    $rawList = is_array($data_header) ? $data_header : (!empty($data_item) ? [$data_item] : []);
    foreach ($rawList as $idx => $it) {
        $nPos = get_safe_value($it, 'nomorPos');
        $items[] = [
            'no'                   => $idx + 1,
            'nomorDaftar'          => get_safe_value($it, 'nomorDaftar'),
            'tanggalDaftar'        => get_safe_value($it, 'tanggalDaftar'),
            'kodeKantor'           => get_safe_value($it, 'kodeKantor'),
            'nomorPos'             => $nPos,
            'nomorPosHeader'       => strlen($nPos) >= 4 ? substr($nPos, 0, 4) : $nPos,
            'nomorSubPos'          => strlen($nPos) > 4 ? substr($nPos, 4) : '',
            'kelompokPos'          => get_safe_value($it, 'kelompokPos', 'POS 1'),
            'nomorBl'              => get_safe_value($it, 'nomorBl'),
            'tanggalBl'            => get_safe_value($it, 'tanggalBl'),
            'nomorHostBl'          => get_safe_value($it, 'nomorHostBl'),
            'tanggalHostBl'        => get_safe_value($it, 'tanggalHostBl'),
            'namaPengirim'         => get_safe_value($it, 'namaPengirim'),
            'alamatPengirim'       => str_replace(["\r", "\n"], ' ', get_safe_value($it, 'alamatPengirim')),
            'namaPenerima'         => get_safe_value($it, 'namaPenerima', '-'),
            'alamatPenerima'       => str_replace(["\r", "\n"], ' ', get_safe_value($it, 'alamatPenerima', '-')),
            'berat'                => get_safe_value($it, 'berat'),
            'jenisKemasan'         => get_safe_value($it, 'jenisKemasan'),
            'jumlahKemasan'        => get_safe_value($it, 'jumlahKemasan'),
            'jumlahContainer'      => get_safe_value($it, 'jumlahContainer'),
            'motherVessel'         => get_safe_value($it, 'motherVessel'),
            'nomorVoyage'          => get_safe_value($it, 'nomorVoyage', $nomorVoyage_detail),
            'kodePelabuhanAsal'    => get_safe_value($it, 'kodePelabuhanAsal'),
            'kodePelabuhanBongkar' => get_safe_value($it, 'kodePelabuhanBongkar'),
            'kodePelabuhanTransit' => get_safe_value($it, 'kodePelabuhanTransit'),
            'kodePelabuhanAkhir'   => get_safe_value($it, 'kodePelabuhanAkhir'),
            'idHeader'             => get_safe_value($it, 'idHeader'),
            'jenisManifes'         => get_safe_value($it, 'jenisManifes', 'Inward (BC 1.1)')
        ];
    }

    echo json_encode([
        'status' => 'OK',
        'count'  => count($items),
        'items'  => $items,
        'data' => [
            'nomorDaftar'          => $nomorDaftar,
            'tanggalDaftar'        => $tanggalDaftar,
            'kodeKantor'           => $kodeKantor,
            'nomorPos'             => $nomorPos,
            'nomorPosHeader'       => strlen($nomorPos) >= 4 ? substr($nomorPos, 0, 4) : $nomorPos,
            'nomorSubPos'          => strlen($nomorPos) > 4 ? substr($nomorPos, 4) : '',
            'nomorBl'              => $nomorBl,
            'tanggalBl'            => $tanggalBl,
            'nomorHostBl'          => $nomorHostBl,
            'tanggalHostBl'        => $tanggalHostBl,
            'namaPengirim'         => $namaPengirim,
            'alamatPengirim'       => $alamatPengirim,
            'berat'                => $berat,
            'jenisKemasan'         => $jenisKemasan,
            'jumlahKemasan'        => $jumlahKemasan,
            'jumlahContainer'      => $jumlahContainer,
            'motherVessel'         => $motherVessel,
            'kodePelabuhanAsal'    => $kodePelabuhanAsal,
            'kodePelabuhanBongkar' => $kodePelabuhanBongkar,
            'kodePelabuhanTransit' => $kodePelabuhanTransit,
            'kodePelabuhanAkhir'   => $kodePelabuhanAkhir,
            'nomorVoyage'          => $nomorVoyageHeader,
            'namaPelabuhanAsal'    => $namaPelabuhanAsal,
            'namaPelabuhanBongkar' => $namaPelabuhanBongkar,
            'namaPelabuhanTransit' => $namaPelabuhanTransit,
            'namaPelabuhanAkhir'   => $namaPelabuhanAkhir,
            'tanggalTiba'          => $tanggalTiba,
            'nomorVoyage_detail'   => $nomorVoyage_detail,
            'idHeader'             => $idHeader,
            'local_manifest'       => $localManifest
        ]
    ]);
    exit;
}

// FORMAT TSV BAWAAN (POWERBUILDER w_bc_ceisa_getbc11.srw)
echo "nomorDaftar\t{$nomorDaftar}\n";
echo "tanggalDaftar\t{$tanggalDaftar}\n";
echo "kodeKantor\t{$kodeKantor}\n";
echo "nomorPos\t{$nomorPos}\n";
echo "nomorBl\t{$nomorBl}\n";
echo "tanggalBl\t{$tanggalBl}\n";
echo "nomorHostBl\t{$nomorHostBl}\n";
echo "tanggalHostBl\t{$tanggalHostBl}\n";
echo "namaPengirim\t{$namaPengirim}\n";
echo "alamatPengirim\t{$alamatPengirim}\n";
echo "berat\t{$berat}\n";
echo "jenisKemasan\t{$jenisKemasan}\n";
echo "jumlahKemasan\t{$jumlahKemasan}\n";
echo "jumlahContainer\t{$jumlahContainer}\n";
echo "motherVessel\t{$motherVessel}\n";
echo "kodePelabuhanAsal\t{$kodePelabuhanAsal}\n";
echo "kodePelabuhanBongkar\t{$kodePelabuhanBongkar}\n";
echo "kodePelabuhanTransit\t{$kodePelabuhanTransit}\n";
echo "kodePelabuhanAkhir\t{$kodePelabuhanAkhir}\n";
echo "nomorVoyage\t{$nomorVoyageHeader}\n";
echo "namaPelabuhanAsal\t{$namaPelabuhanAsal}\n";
echo "namaPelabuhanBongkar\t{$namaPelabuhanBongkar}\n";
echo "namaPelabuhanTransit\t{$namaPelabuhanTransit}\n";
echo "namaPelabuhanAkhir\t{$namaPelabuhanAkhir}\n";
echo "tanggalTiba\t{$tanggalTiba}\n";
echo "nomorVoyage_detail\t{$nomorVoyage_detail}\n";

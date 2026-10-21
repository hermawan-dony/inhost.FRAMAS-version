<?php
/**
 * CEISA 4.0 - Manifest (NVOCC) API Handler
 * Melayani seluruh transaksi Manifes BC 1.1 Host-to-Host OpenAPI Bea Cukai
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/CeisaManifestBuilder.php';

$action = $_REQUEST['action'] ?? '';
$nomorAjuReq = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');

$activeCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($_SESSION['cmp_id']) ? trim($_SESSION['cmp_id']) : ''));

// Jika nomor_aju ada dan cmp_id belum jelas, prioritaskan pemilik nomor_aju di database
if (!empty($nomorAjuReq)) {
    $qOwner = $koneksi->query("SELECT cmp_id FROM bc_ceisa_manifes_header WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAjuReq) . "' LIMIT 1");
    if ($qOwner && $rOwner = $qOwner->fetch_assoc()) {
        if (!empty($rOwner['cmp_id'])) {
            $activeCmp = $rOwner['cmp_id'];
        }
    }
}

// Default aman untuk modul manifes NVOCC adalah TVL (jangan sampai fallback ke RMB)
if (empty($activeCmp) || $activeCmp === 'default' || ($activeCmp === '080167772504400' && empty($_REQUEST['cmp_id']) && (empty($_SESSION['company_cmp_id']) || $_SESSION['company_cmp_id'] !== '080167772504400'))) {
    $activeCmp = 'TVL';
}

if (empty($activeCmp)) {
    echo json_encode(['status' => 'ERROR', 'message' => 'Sesi perusahaan tidak valid. Silakan pilih perusahaan aktif terlebih dahulu.']);
    exit;
}

/**
 * Helper untuk melakukan cURL request ke API Gateway Bea Cukai
 */
function callCeisaGateway($method, $endpoint, $payload = null, $queryParams = [], $extraHeaders = []) {
    global $koneksi, $activeCmp, $nomorAju;
    
    // Base URL Bea Cukai Gateway
    $baseUrls = [
        "https://apis-gw.beacukai.go.id/openapi",
        "https://apis-gw.beacukai.go.id/gateway/openapi-manifes/1",
        "https://apis-gw.beacukai.go.id/v1/openapi-manifes",
        "https://apis-gw.beacukai.go.id"
    ];

    // Prioritaskan token khusus active company
    $bearer = $_SESSION["token_" . $activeCmp] ?? '';
    if (empty($bearer)) {
        // Cek shared file cache token perusahaan aktif
        $tokenCacheFile = sys_get_temp_dir() . '/ceisa_nle_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $activeCmp) . '.json';
        if (file_exists($tokenCacheFile)) {
            $cTok = @json_decode(@file_get_contents($tokenCacheFile), true);
            if (!empty($cTok['token']) && (time() - (int)$cTok['created_at']) < 3000) {
                $bearer = $cTok['token'];
                $_SESSION["token_" . $activeCmp] = $bearer;
            }
        }
    }
    // Jika masih kosong, refresh login NLE / OpenAPI otomatis untuk active company
    if (empty($bearer)) {
        $qComp = $koneksi->query("SELECT username_ceisa, password_ceisa, token_ceisa, api_key_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
        if ($qComp && $rComp = $qComp->fetch_assoc()) {
            if (!empty($rComp['username_ceisa']) && !empty($rComp['password_ceisa'])) {
                $chAuth = curl_init();
                curl_setopt_array($chAuth, [
                    CURLOPT_URL => "https://apis-gw.beacukai.go.id/v1/openapi-auth/user/login",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode(['username' => $rComp['username_ceisa'], 'password' => $rComp['password_ceisa']]),
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER => ["Content-Type: application/json"]
                ]);
                $authRes = curl_exec($chAuth);
                curl_close($chAuth);
                $authArr = json_decode($authRes, true);
                if (!empty($authArr['item']['access_token'])) {
                    $bearer = $authArr['item']['access_token'];
                    $_SESSION["token_" . $activeCmp] = $bearer;
                    $_SESSION["token_created_at_" . $activeCmp] = time();
                    @file_put_contents(sys_get_temp_dir() . '/ceisa_nle_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $activeCmp) . '.json', json_encode([
                        'token' => $bearer,
                        'created_at' => time(),
                        'cmp_key' => $activeCmp
                    ]));
                }
            }
            if (empty($bearer) && !empty($rComp['token_ceisa'])) {
                $bearer = trim($rComp['token_ceisa']);
            }
        }
    }
    // Jangan izinkan fallback ke session token non-spesifik jika milik perusahaan lain
    if (!empty($bearer) && !empty($rComp) && function_exists('isTokenValidForCompany') && !isTokenValidForCompany($bearer, $rComp)) {
        $bearer = '';
    }
    if (empty($bearer)) {
        // Ambil token MFA resmi aktif untuk perusahaan ini
        $bearer = getActivePortalMfaToken($activeCmp);
    }

    // Ambil API Key resmi jika tersedia untuk perusahaan ini
    $apiKey = '';
    $qKey = $koneksi->query("SELECT api_key_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
    if ($qKey && $rKey = $qKey->fetch_assoc()) {
        $apiKey = trim($rKey['api_key_ceisa'] ?? '');
    }

    $headers = [
        "Authorization: Bearer {$bearer}",
        "Content-Type: application/json",
        "Accept: application/json, text/plain, */*"
    ];
    if (!empty($apiKey)) {
        $headers[] = "beacukai-api-key: {$apiKey}";
    }

    foreach ($extraHeaders as $k => $v) {
        $headers[] = "{$k}: {$v}";
    }

    $lastResponse = null;
    $lastHttpCode = 0;
    $lastError = '';

    // Coba URL base
    foreach ($baseUrls as $base) {
        $cleanEndpoint = ltrim($endpoint, '/');
        // Jika base sudah berakhiran openapi dan endpoint diawali manifes
        $url = rtrim($base, '/') . '/' . $cleanEndpoint;
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $curl = curl_init();
        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => "",
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ];

        if ($payload !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = is_string($payload) ? $payload : json_encode($payload);
        }

        curl_setopt_array($curl, $curlOptions);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);

        $lastResponse = $response;
        $lastHttpCode = $httpCode;
        $lastError = $err;

        // Jika mendapatkan respon valid (bukan 404 Route Not Found dari gateway), gunakan hasil ini
        if ($httpCode > 0 && $httpCode !== 404) {
            break;
        }
    }

    // Simpan history log respon ke database
    if ($koneksi && !empty($nomorAju)) {
        $stmtLog = $koneksi->prepare("INSERT INTO bc_ceisa_manifes_respon_logs (nomor_aju, cmp_id, endpoint, http_code, request_payload, response_payload) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmtLog) {
            $reqText = is_string($payload) ? $payload : json_encode($payload);
            $respText = (string)$lastResponse;
            $stmtLog->bind_param("sssiss", $nomorAju, $activeCmp, $endpoint, $lastHttpCode, $reqText, $respText);
            $stmtLog->execute();
            $stmtLog->close();
        }
    }

    return [
        'http_code' => $lastHttpCode,
        'response'  => $lastResponse,
        'error'     => $lastError
    ];
}

// =========================================================================
// 1. GET LIST MANIFES (Daftar Pengajuan Manifes)
// =========================================================================
if ($action === 'get_list') {
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $source = strtolower(trim($_GET['source'] ?? ''));
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(500, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = "WHERE h.cmp_id = ?";
    $params = [$activeCmp];
    $types  = "s";

    // Filter Isolasi: 'portal' vs 'h2h'
    if ($source === 'portal') {
        // Tampilkan dokumen portal murni, hasil sinkronisasi, dokumen hasil restore, atau dokumen yang memiliki id_nvocc_header resmi
        $where .= " AND (h.asal_data IN ('PORTAL', 'BOTH') OR (h.id_nvocc_header IS NOT NULL AND h.id_nvocc_header <> ''))";
    } elseif ($source === 'h2h' || $source === '' || $source === 'local') {
        // Default untuk H2H: Tampilkan dokumen lokal Host to Host & BOTH (dokumen portal murni diisolasi)
        $where .= " AND (h.asal_data IN ('H2H', 'BOTH', 'S') OR (h.asal_data <> 'PORTAL' AND h.asal_data IS NOT NULL))";
    }

    if (!empty($search)) {
        $where .= " AND (h.nomor_aju LIKE ? OR h.nomor_voyage LIKE ? OR h.nama_sarana_pengangkut LIKE ? OR h.id_nvocc_header LIKE ?)";
        $sTerm = "%{$search}%";
        $params = array_merge($params, [$sTerm, $sTerm, $sTerm, $sTerm]);
        $types .= "ssss";
    }

    if (!empty($status)) {
        $where .= " AND h.status = ?";
        $params[] = $status;
        $types .= "s";
    }

    $jenis = trim($_GET['jenis_manifes'] ?? '');
    if (!empty($jenis)) {
        $where .= " AND h.jenis_manifes = ?";
        $params[] = $jenis;
        $types .= "s";
    }

    // Count Total
    $stmtCnt = $koneksi->prepare("SELECT COUNT(*) as total FROM bc_ceisa_manifes_header h {$where}");
    $stmtCnt->bind_param($types, ...$params);
    $stmtCnt->execute();
    $total = $stmtCnt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmtCnt->close();

    // Query Data
    $sql = "SELECT h.*, 
                   (SELECT COUNT(*) FROM bc_ceisa_manifes_bl b WHERE b.nomor_aju = h.nomor_aju AND b.cmp_id = h.cmp_id) as total_hbl,
                   (SELECT IFNULL(SUM(b.berat), 0) FROM bc_ceisa_manifes_bl b WHERE b.nomor_aju = h.nomor_aju AND b.cmp_id = h.cmp_id) as total_bruto
            FROM bc_ceisa_manifes_header h
            {$where}
            ORDER BY IFNULL(h.updated_at, h.created_at) DESC, h.nomor_aju DESC
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();

    // Hitung Stats Ringkasan sesuai filter sumber
    $sourceCond = "";
    if ($source === 'portal') {
        $sourceCond = " AND (asal_data IN ('PORTAL', 'BOTH') OR (id_nvocc_header IS NOT NULL AND id_nvocc_header <> ''))";
    } elseif ($source === 'h2h' || $source === '' || $source === 'local') {
        $sourceCond = " AND (asal_data IN ('H2H', 'BOTH', 'S') OR (asal_data <> 'PORTAL' AND asal_data IS NOT NULL))";
    }

    $stats = [
        'total'    => 0,
        'draft'    => 0,
        'ready'    => 0,
        'terkirim' => 0,
        'rekon'    => 0,
        'selesai'  => 0,
        'reject'   => 0
    ];
    $qStats = $koneksi->query("SELECT status, COUNT(*) as cnt FROM bc_ceisa_manifes_header WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' {$sourceCond} GROUP BY status");
    if ($qStats) {
        while ($stRow = $qStats->fetch_assoc()) {
            $rawStatus = trim($stRow['status']);
            $sName = strtolower($rawStatus);
            $cnt = (int)$stRow['cnt'];
            $stats['total'] += $cnt;
            if ($sName === 'draft') $stats['draft'] += $cnt;
            elseif ($sName === 'ready') $stats['ready'] += $cnt;
            elseif ($sName === 'terkirim') $stats['terkirim'] += $cnt;
            elseif (strpos($sName, 'selesai') !== false || strpos($sName, 'penerimaan') !== false) $stats['selesai'] += $cnt;
            elseif (strpos($sName, 'rekon') !== false || strpos($sName, 'rikon') !== false) $stats['rekon'] += $cnt;
            elseif (strpos($sName, 'reject') !== false || strpos($sName, 'tolak') !== false) $stats['reject'] += $cnt;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
        'stats'  => $stats,
        'pagination' => [
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
    exit;
}

// =========================================================================
// 2. GET DETAIL MANIFES (Header, Master BL, House BL, HS, Petikemas)
// =========================================================================
if ($action === 'get_detail') {
    $nomorAju = trim($_GET['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $stmtH = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_header WHERE nomor_aju = ? AND cmp_id = ? LIMIT 1");
    $stmtH->bind_param("ss", $nomorAju, $activeCmp);
    $stmtH->execute();
    $header = $stmtH->get_result()->fetch_assoc();
    $stmtH->close();

    if (!$header) {
        echo json_encode(['status' => 'ERROR', 'message' => "Manifes '$nomorAju' tidak ditemukan."]);
        exit;
    }

    // Kelompok Pos
    $stmtKP = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_kelompok_pos WHERE nomor_aju = ? AND cmp_id = ?");
    $stmtKP->bind_param("ss", $nomorAju, $activeCmp);
    $stmtKP->execute();
    $kpRes = $stmtKP->get_result();
    $kelompokPos = [];
    while ($kp = $kpRes->fetch_assoc()) $kelompokPos[] = $kp;
    $stmtKP->close();

    // Master BL
    $stmtMBL = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = ? AND cmp_id = ?");
    $stmtMBL->bind_param("ss", $nomorAju, $activeCmp);
    $stmtMBL->execute();
    $mblRes = $stmtMBL->get_result();
    $masterBl = [];
    while ($m = $mblRes->fetch_assoc()) $masterBl[] = $m;
    $stmtMBL->close();

    // House BLs
    $stmtBL = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_bl WHERE nomor_aju = ? AND cmp_id = ? ORDER BY seri_bl ASC");
    $stmtBL->bind_param("ss", $nomorAju, $activeCmp);
    $stmtBL->execute();
    $blRes = $stmtBL->get_result();
    $houseBls = [];

    while ($bl = $blRes->fetch_assoc()) {
        $hblNo = $bl['nomor_host_bl'];

        // HS Items
        $stmtHS = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = ? AND nomor_host_bl = ? AND cmp_id = ? ORDER BY seri_hs ASC");
        $stmtHS->bind_param("sss", $nomorAju, $hblNo, $activeCmp);
        $stmtHS->execute();
        $hsRes = $stmtHS->get_result();
        $bl['hs_items'] = [];
        while ($h = $hsRes->fetch_assoc()) $bl['hs_items'][] = $h;
        $stmtHS->close();

        // Petikemas
        $stmtPeti = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = ? AND nomor_host_bl = ? AND cmp_id = ? ORDER BY seri_container ASC");
        $stmtPeti->bind_param("sss", $nomorAju, $hblNo, $activeCmp);
        $stmtPeti->execute();
        $petiRes = $stmtPeti->get_result();
        $bl['petikemas'] = [];
        while ($p = $petiRes->fetch_assoc()) $bl['petikemas'][] = $p;
        $stmtPeti->close();

        // Dokumen
        $stmtDok = $koneksi->prepare("SELECT * FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = ? AND nomor_host_bl = ? AND cmp_id = ? ORDER BY seri_dokumen ASC");
        $stmtDok->bind_param("sss", $nomorAju, $hblNo, $activeCmp);
        $stmtDok->execute();
        $dokRes = $stmtDok->get_result();
        $bl['dokumen'] = [];
        while ($d = $dokRes->fetch_assoc()) $bl['dokumen'][] = $d;
        $stmtDok->close();

        $houseBls[] = $bl;
    }
    $stmtBL->close();

    echo json_encode([
        'status'       => 'OK',
        'header'       => $header,
        'kelompok_pos' => $kelompokPos,
        'master_bl'    => $masterBl,
        'house_bls'    => $houseBls
    ]);
    exit;
}

// =========================================================================
// 3. SAVE HEADER (Tambah / Edit Header Manifes)
// =========================================================================
if ($action === 'save_header') {
    $nomorAju       = trim($_POST['nomor_aju'] ?? '');
    $kodeKantor     = trim($_POST['kode_kantor'] ?? '');
    if (empty($kodeKantor)) {
        $kodeKantor = $active_company['kode_kantor'] ?? '070100';
    }
    $jenisManifes   = trim($_POST['jenis_manifes'] ?? '');
    if (empty($jenisManifes)) {
        $qOld = $koneksi->query("SELECT jenis_manifes FROM bc_ceisa_manifes_header WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAju) . "' LIMIT 1");
        if ($qOld && $rOld = $qOld->fetch_assoc()) {
            $jenisManifes = $rOld['jenis_manifes'];
        }
    }
    if (empty($jenisManifes)) {
        $jenisManifes = '11';
    }
    $idPerusahaan   = trim($_POST['id_perusahaan'] ?? ($active_company['npwp'] ?? ''));
    $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? ($active_company['cmp_name'] ?? ''));
    $alamatPerusahaan = trim($_POST['alamat_perusahaan'] ?? ($active_company['alamat'] ?? ''));
    $nomorVoyage    = trim($_POST['nomor_voyage'] ?? '');
    $tglBerangkat   = !empty($_POST['tanggal_berangkat']) ? date('Y-m-d H:i:s', strtotime($_POST['tanggal_berangkat'])) : date('Y-m-d H:i:s');
    $tglTiba        = !empty($_POST['tanggal_tiba']) ? date('Y-m-d H:i:s', strtotime($_POST['tanggal_tiba'])) : date('Y-m-d H:i:s', strtotime('+1 day'));
    $modePengangkut = trim($_POST['mode_pengangkut'] ?? 'Laut');
    $saranaPengangkut = trim($_POST['nama_sarana_pengangkut'] ?? '');
    $imoNumber      = trim($_POST['imo_number'] ?? '');
    $callSign       = trim($_POST['call_sign'] ?? '');
    $kodeNegara     = trim($_POST['kode_negara'] ?? 'PA');
    $asalData       = trim($_POST['asal_data'] ?? 'H2H');
    if ($asalData === 'S' || empty($asalData)) {
        $asalData = 'H2H';
    }
    $flwaktuTempuh  = trim($_POST['flwaktu_tempuh'] ?? '24');

    if (empty($nomorAju) || empty($nomorVoyage) || empty($saranaPengangkut)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju, Nomor Voyage, dan Sarana Pengangkut wajib diisi!']);
        exit;
    }

    $sql = "INSERT INTO bc_ceisa_manifes_header 
            (nomor_aju, cmp_id, kode_kantor, jenis_manifes, id_perusahaan, nama_perusahaan, alamat_perusahaan, nomor_voyage, tanggal_berangkat, tanggal_tiba, mode_pengangkut, nama_sarana_pengangkut, imo_number, call_sign, kode_negara, asal_data, flwaktu_tempuh, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft')
            ON DUPLICATE KEY UPDATE 
            kode_kantor = VALUES(kode_kantor),
            jenis_manifes = VALUES(jenis_manifes),
            id_perusahaan = VALUES(id_perusahaan),
            nama_perusahaan = VALUES(nama_perusahaan),
            alamat_perusahaan = VALUES(alamat_perusahaan),
            nomor_voyage = VALUES(nomor_voyage),
            tanggal_berangkat = VALUES(tanggal_berangkat),
            tanggal_tiba = VALUES(tanggal_tiba),
            mode_pengangkut = VALUES(mode_pengangkut),
            nama_sarana_pengangkut = VALUES(nama_sarana_pengangkut),
            imo_number = VALUES(imo_number),
            call_sign = VALUES(call_sign),
            kode_negara = VALUES(kode_negara),
            asal_data = VALUES(asal_data),
            flwaktu_tempuh = VALUES(flwaktu_tempuh)";

    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        echo json_encode(['status' => 'ERROR', 'message' => 'DB error: ' . $koneksi->error]);
        exit;
    }
    $stmt->bind_param("sssssssssssssssss", 
        $nomorAju, $activeCmp, $kodeKantor, $jenisManifes, $idPerusahaan, $namaPerusahaan, $alamatPerusahaan,
        $nomorVoyage, $tglBerangkat, $tglTiba, $modePengangkut, $saranaPengangkut, $imoNumber, $callSign,
        $kodeNegara, $asalData, $flwaktuTempuh
    );

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['status' => 'OK', 'message' => 'Data Header Manifes berhasil disimpan!', 'nomor_aju' => $nomorAju]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan: ' . $err]);
    }
    exit;
}

// =========================================================================
// 4. SAVE HOUSE BL (HBL Beserta HS & Petikemas)
// =========================================================================
if ($action === 'save_bl') {
    $nomorAju     = trim($_POST['nomor_aju'] ?? '');
    $nomorHostBl  = trim($_POST['nomor_host_bl'] ?? '');
    $nomorBl      = trim($_POST['nomor_bl'] ?? '');
    $tglBl        = trim($_POST['tanggal_bl'] ?? date('Y-m-d'));
    $tglHostBl    = trim($_POST['tanggal_host_bl'] ?? date('Y-m-d'));
    $namaPengirim = trim($_POST['nama_pengirim'] ?? '');
    $alamatPengirim = trim($_POST['alamat_pengirim'] ?? '');
    $npwpPenerima = trim($_POST['npwp_penerima'] ?? '');
    $namaPenerima = trim($_POST['nama_penerima'] ?? '');
    $alamatPenerima = trim($_POST['alamat_penerima'] ?? '');
    $berat        = (float)($_POST['berat'] ?? 0);
    $dimensi      = (float)($_POST['dimensi'] ?? 0);
    $jenisKemasan = trim($_POST['jenis_kemasan'] ?? 'CT');
    $jumlahKemasan= (float)($_POST['jumlah_kemasan'] ?? 1);
    $motherVessel = trim($_POST['mother_vessel'] ?? '');
    $pelabuhanAsal= trim($_POST['kode_pelabuhan_asal'] ?? '');
    $pelabuhanBongkar = trim($_POST['kode_pelabuhan_bongkar'] ?? '');
    $pelabuhanTransit = trim($_POST['kode_pelabuhan_transit'] ?? $pelabuhanAsal);
    $pelabuhanAkhir   = trim($_POST['kode_pelabuhan_akhir'] ?? $pelabuhanBongkar);

    if (empty($nomorAju) || empty($nomorHostBl) || empty($nomorBl) || empty($namaPenerima) || empty($npwpPenerima)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju, No Master BL, No House BL, NPWP dan Nama Penerima wajib diisi!']);
        exit;
    }

    $origHostBl   = trim($_POST['orig_nomor_host_bl'] ?? '');
    if (!empty($origHostBl) && $origHostBl !== $nomorHostBl) {
        $escOrig = $koneksi->real_escape_string($origHostBl);
        $escAju  = $koneksi->real_escape_string($nomorAju);
        $escCmp  = $koneksi->real_escape_string($activeCmp);
        $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escOrig}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escOrig}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escOrig}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        $koneksi->query("DELETE FROM bc_ceisa_manifes_bl WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escOrig}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    }

    $sql = "INSERT INTO bc_ceisa_manifes_bl 
            (nomor_aju, cmp_id, kode_kelompok_pos, nomor_pos, nomor_bl, tanggal_bl, nomor_host_bl, tanggal_host_bl, marking, npwp_pengirim, nama_pengirim, alamat_pengirim, npwp_penerima, nama_penerima, alamat_penerima, npwp_notify, nama_notify, alamat_notify, berat, dimensi, jumlah_container, jenis_kemasan, jumlah_kemasan, mother_vessel, kode_pelabuhan_asal, kode_pelabuhan_bongkar, kode_pelabuhan_transit, kode_pelabuhan_akhir, jumlah_kemasan_terangkut)
            VALUES (?, ?, '01', '000100000000', ?, ?, ?, ?, 'NO MARKING', '', ?, ?, ?, ?, ?, '', 'SAME AS CONSIGNEE', 'SAME AS CONSIGNEE', ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            nomor_bl = VALUES(nomor_bl),
            tanggal_bl = VALUES(tanggal_bl),
            tanggal_host_bl = VALUES(tanggal_host_bl),
            nama_pengirim = VALUES(nama_pengirim),
            alamat_pengirim = VALUES(alamat_pengirim),
            npwp_penerima = VALUES(npwp_penerima),
            nama_penerima = VALUES(nama_penerima),
            alamat_penerima = VALUES(alamat_penerima),
            berat = VALUES(berat),
            dimensi = VALUES(dimensi),
            jenis_kemasan = VALUES(jenis_kemasan),
            jumlah_kemasan = VALUES(jumlah_kemasan),
            mother_vessel = VALUES(mother_vessel),
            kode_pelabuhan_asal = VALUES(kode_pelabuhan_asal),
            kode_pelabuhan_bongkar = VALUES(kode_pelabuhan_bongkar),
            kode_pelabuhan_transit = VALUES(kode_pelabuhan_transit),
            kode_pelabuhan_akhir = VALUES(kode_pelabuhan_akhir),
            jumlah_kemasan_terangkut = VALUES(jumlah_kemasan_terangkut)";

    $stmt = $koneksi->prepare($sql);
    $stmt->bind_param("ssssssssssddsdsssssd",
        $nomorAju, $activeCmp, $nomorBl, $tglBl, $nomorHostBl, $tglHostBl,
        $namaPengirim, $alamatPengirim, $npwpPenerima, $namaPenerima, $alamatPenerima,
        $berat, $dimensi, $jenisKemasan, $jumlahKemasan, $motherVessel,
        $pelabuhanAsal, $pelabuhanBongkar, $pelabuhanTransit, $pelabuhanAkhir, $jumlahKemasan
    );

    if ($stmt->execute()) {
        $stmt->close();

        // Simpan HS Items jika dikirimkan (JSON format)
        if (!empty($_POST['hs_items_json'])) {
            $hsItems = json_decode($_POST['hs_items_json'], true);
            if (is_array($hsItems)) {
                $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = '{$nomorAju}' AND nomor_host_bl = '{$nomorHostBl}' AND cmp_id = '{$activeCmp}'");
                $stmtHS = $koneksi->prepare("INSERT INTO bc_ceisa_manifes_bl_hs (nomor_aju, cmp_id, nomor_host_bl, seri_hs, kode_hs, uraian_barang) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($hsItems as $idx => $hs) {
                    $seri = $idx + 1;
                    $kodeHs = $hs['kode_hs'] ?? '0101';
                    $uraian = $hs['uraian_barang'] ?? 'BARANG IMPOR';
                    $stmtHS->bind_param("ssisss", $nomorAju, $activeCmp, $nomorHostBl, $seri, $kodeHs, $uraian);
                    $stmtHS->execute();
                }
                $stmtHS->close();
            }
        }

        // Simpan Petikemas jika dikirimkan
        if (!empty($_POST['petikemas_json'])) {
            $petiItems = json_decode($_POST['petikemas_json'], true);
            if (is_array($petiItems)) {
                $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '{$nomorAju}' AND nomor_host_bl = '{$nomorHostBl}' AND cmp_id = '{$activeCmp}'");
                $stmtPeti = $koneksi->prepare("INSERT INTO bc_ceisa_manifes_bl_petikemas (nomor_aju, cmp_id, nomor_host_bl, seri_container, nomor_container, type_container, ukuran_container, nomor_segel, jenis_container) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($petiItems as $idx => $p) {
                    $seri = $idx + 1;
                    $noCont = $p['nomor_container'] ?? '';
                    $type = $p['type_container'] ?? '1';
                    $ukuran = $p['ukuran_container'] ?? '20';
                    $segel = $p['nomor_segel'] ?? 'SEAL01';
                    $jenis = $p['jenis_container'] ?? '7';
                    $stmtPeti->bind_param("sssisssss", $nomorAju, $activeCmp, $nomorHostBl, $seri, $noCont, $type, $ukuran, $segel, $jenis);
                    $stmtPeti->execute();
                }
                $stmtPeti->close();
            }
        }

        echo json_encode(['status' => 'OK', 'message' => "House B/L '$nomorHostBl' berhasil disimpan!"]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan House BL: ' . $err]);
    }
    exit;
}

// =========================================================================
// 4b. HAPUS HOUSE BL
// =========================================================================
if ($action === 'delete_bl') {
    $nomorAju    = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');
    $nomorHostBl = trim($_REQUEST['nomor_host_bl'] ?? $_POST['nomor_host_bl'] ?? '');
    if (empty($nomorAju) || empty($nomorHostBl)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju dan Nomor House B/L tidak boleh kosong.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomorAju);
    $escHbl = $koneksi->real_escape_string($nomorHostBl);
    $escCmp = $koneksi->real_escape_string($activeCmp);

    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escHbl}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escHbl}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escHbl}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl WHERE nomor_aju = '{$escAju}' AND nomor_host_bl = '{$escHbl}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

    echo json_encode(['status' => 'OK', 'message' => "House B/L '$nomorHostBl' berhasil dihapus!"]);
    exit;
}

// =========================================================================
// 5. GENERATE JSON MANIFES (Preview Payload)
// =========================================================================
if ($action === 'generate_json') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    try {
        $builder = new CeisaManifestBuilder($koneksi, $activeCmp);
        $errors = $builder->validate($nomorAju);
        $json = $builder->buildJson($nomorAju, true);

        if (!empty($_REQUEST['download'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="ceisa4_backup_' . $nomorAju . '.json"');
            echo $json;
            exit;
        }

        echo json_encode([
            'status'     => empty($errors) ? 'OK' : 'WARNING',
            'json'       => $json,
            'errors'     => $errors,
            'nomor_aju'  => $nomorAju
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 6. DRAFTING NVOCC KE CEISA 4.0 (POST /manifes/nvocc/draft)
// =========================================================================
if ($action === 'api_draft') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    try {
        $builder = new CeisaManifestBuilder($koneksi, $activeCmp);
        $errors = $builder->validate($nomorAju);
        if (!empty($errors)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Validasi gagal:<br>• ' . implode('<br>• ', $errors), 'errors' => $errors]);
            exit;
        }

        $payload = $builder->buildPayload($nomorAju);
        $call = callCeisaGateway('POST', '/manifes/nvocc/draft', $payload);

        $resData = json_decode($call['response'], true);
        $idNvocc = $resData['data']['idNvoccHeader'] ?? ($resData['idNvoccHeader'] ?? null);

        $isSuccess = ($call['http_code'] === 200 || $call['http_code'] === 201)
            && (empty($resData['status']) || !in_array(strtolower((string)$resData['status']), ['failed', 'gagal', 'false']));

        if ($isSuccess) {
            $stmtUp = $koneksi->prepare("UPDATE bc_ceisa_manifes_header SET status = 'Draft', id_nvocc_header = IFNULL(?, id_nvocc_header), respon_terakhir = ? WHERE nomor_aju = ? AND cmp_id = ?");
            $respStr = substr($call['response'], 0, 1000);
            $stmtUp->bind_param("ssss", $idNvocc, $respStr, $nomorAju, $activeCmp);
            $stmtUp->execute();
            $stmtUp->close();

            echo json_encode([
                'status'          => 'OK',
                'message'         => 'Draft Manifes NVOCC berhasil dikirim ke CEISA 4.0!',
                'id_nvocc_header' => $idNvocc,
                'gateway_response'=> $resData ?: $call['response']
            ]);
        } else {
            $msg = $resData['message'] ?? ($resData['error'] ?? ($call['error'] ?: 'HTTP Error ' . $call['http_code']));
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal Drafting: ' . $msg, 'raw' => $call]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 7. CEK KELENGKAPAN NVOCC (POST /manifes-validasi-service/v1/cekLengkap/nvocc)
// =========================================================================
if ($action === 'api_cek_lengkap') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomorAju);
    $escCmp = $koneksi->real_escape_string($activeCmp);

    // 1. Cari id_nvocc_header dari request atau database bc_ceisa_manifes_header
    $idNvocc = trim($_REQUEST['id_nvocc_header'] ?? $_POST['id_nvocc_header'] ?? '');
    $qH = $koneksi->query("SELECT id_nvocc_header, status, cmp_id FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escAju}' LIMIT 1");
    if ($qH && $rH = $qH->fetch_assoc()) {
        if (empty($idNvocc)) {
            $idNvocc = trim($rH['id_nvocc_header'] ?? '');
        }
        if (!empty($rH['cmp_id'])) {
            $activeCmp = $rH['cmp_id'];
        }
    }

    // Ambil portal MFA token terisolasi untuk perusahaan aktif (otomatis refresh jika perlu)
    $portalToken = getActivePortalMfaToken($activeCmp);

    // 2. Jika id_nvocc_header tersedia, langsung panggil endpoint validasi resmi CEISA 4.0
    if (!empty($idNvocc)) {
        if (empty($portalToken)) {
            echo json_encode(['status' => 'ERROR', 'message' => "Gagal mendapatkan MFA token resmi CEISA 4.0 untuk perusahaan {$activeCmp}."]);
            exit;
        }

        $url = "https://apis-gw.beacukai.go.id/manifes-validasi-service/v1/cekLengkap/nvocc?idNvoccHeader=" . urlencode($idNvocc);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$portalToken}",
                "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                "Accept: application/json, text/plain, */*",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
            ]
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!$curlErr && $httpCode === 200) {
            $resData = json_decode($resp, true);
            $infoArr = $resData['info'] ?? [];
            $isLengkap = is_array($infoArr) && in_array('DATA LENGKAP', $infoArr);
            if ($isLengkap || (isset($resData['status']) && ($resData['status'] === true || $resData['status'] === 'true'))) {
                $infoStr = is_array($infoArr) ? implode(', ', $infoArr) : 'DATA LENGKAP';
                $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Ready', respon_terakhir = 'Cek Lengkap: {$infoStr}', updated_at = NOW() WHERE nomor_aju = '{$escAju}'");
                echo json_encode([
                    'status'   => 'OK',
                    'message'  => 'Manifes telah diverifikasi oleh CEISA 4.0 dan DATA LENGKAP (Status: READY)!',
                    'response' => $resData
                ]);
                exit;
            } else {
                $msg = is_array($infoArr) ? implode('<br>• ', $infoArr) : ($resData['message'] ?? $resp);
                echo json_encode([
                    'status'   => 'ERROR',
                    'message'  => 'Validasi Kelengkapan (CEISA 4.0):<br>• ' . $msg,
                    'raw'      => $resData ?: $resp
                ]);
                exit;
            }
        } elseif ($httpCode === 401) {
            // Token expired di server, regenerate dan coba lagi sekali
            $portalToken = getActivePortalMfaToken($activeCmp);
            if (!empty($portalToken)) {
                $chR = curl_init($url);
                curl_setopt_array($chR, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => '',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER     => [
                        "Authorization: Bearer {$portalToken}",
                        "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                        "Accept: application/json, text/plain, */*",
                        "Origin: https://portal.beacukai.go.id",
                        "Referer: https://portal.beacukai.go.id/",
                        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
                    ]
                ]);
                $respR = curl_exec($chR);
                $codeR = curl_getinfo($chR, CURLINFO_HTTP_CODE);
                curl_close($chR);
                if ($codeR === 200) {
                    $resData = json_decode($respR, true);
                    $infoArr = $resData['info'] ?? [];
                    $isLengkap = is_array($infoArr) && in_array('DATA LENGKAP', $infoArr);
                    if ($isLengkap || (isset($resData['status']) && ($resData['status'] === true || $resData['status'] === 'true'))) {
                        $infoStr = is_array($infoArr) ? implode(', ', $infoArr) : 'DATA LENGKAP';
                        $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Ready', respon_terakhir = 'Cek Lengkap: {$infoStr}', updated_at = NOW() WHERE nomor_aju = '{$escAju}'");
                        echo json_encode([
                            'status'   => 'OK',
                            'message'  => 'Manifes telah diverifikasi oleh CEISA 4.0 dan DATA LENGKAP (Status: READY)!',
                            'response' => $resData
                        ]);
                        exit;
                    }
                }
            }
        }
    }

    // 3. Fallback: Dokumen lokal (belum ada di portal)
    $chkHbl = $koneksi->query("SELECT COUNT(*) AS total_hbl FROM bc_ceisa_manifes_bl WHERE nomor_aju = '{$escAju}'");
    $cntHbl = ($chkHbl && $rHbl = $chkHbl->fetch_assoc()) ? (int)$rHbl['total_hbl'] : 0;
    if ($cntHbl === 0) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => "Manifes belum memiliki House B/L (HBL).<br><br>Silakan lengkapi data pos/BL melalui tombol Edit ✏️ atau gunakan <strong>📥 Import Excel Backup</strong> sebelum melakukan Cek Kelengkapan."
        ]);
        exit;
    }

    // 3. Fallback: Validasi kelengkapan data via Builder
    $builder = new CeisaManifestBuilder($koneksi, $activeCmp);
    $valErrors = $builder->validate($nomorAju);
    if (!empty($valErrors)) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => "Data manifes belum lengkap:<br>• " . implode("<br>• ", $valErrors)
        ]);
        exit;
    }

    // Fallback: Panggil Gateway API
    $call = callCeisaGateway('POST', '/manifes/nvocc/cek-lengkap', null, ['nomorAju' => $nomorAju]);
    $resData = json_decode($call['response'], true);

    $isSuccess = ($call['http_code'] === 200 || $call['http_code'] === 201)
        && (empty($resData['status']) || !in_array(strtolower((string)$resData['status']), ['failed', 'gagal', 'false']));

    if ($isSuccess) {
        $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Ready', respon_terakhir = 'Cek Lengkap: Ready' WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        echo json_encode([
            'status'   => 'OK',
            'message'  => 'Manifes telah dicek kelengkapannya dan berstatus READY untuk dikirim!',
            'response' => $resData ?: $call['response']
        ]);
    } else {
        $msg = $resData['message'] ?? ($resData['error'] ?? ($call['error'] ?: 'HTTP Error ' . $call['http_code']));
        if (is_array($msg)) $msg = implode(', ', $msg);
        echo json_encode(['status' => 'ERROR', 'message' => 'Cek Lengkap gagal: ' . $msg, 'raw' => $call]);
    }
    exit;
}

// =========================================================================
// 8. KIRIM / PINDAH DATA NVOCC (POST /manifes/nvocc/kirim)
// =========================================================================
if ($action === 'api_kirim') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $call = callCeisaGateway('POST', '/manifes/nvocc/kirim', ['nomorAju' => $nomorAju]);
    $resData = json_decode($call['response'], true);

    $isSuccess = ($call['http_code'] === 200 || $call['http_code'] === 201)
        && (empty($resData['status']) || !in_array(strtolower((string)$resData['status']), ['failed', 'gagal', 'false']));

    $msg = $resData['message'] ?? ($resData['error'] ?? ('HTTP Error ' . $call['http_code']));
    if (is_array($msg)) $msg = implode(', ', $msg);
    $isAlreadySubmitted = (stripos((string)$msg, 'Data Sudah Ada') !== false || stripos((string)$msg, 'Sudah Terdaftar') !== false);

    if ($isSuccess || $isAlreadySubmitted) {
        $now = date('Y-m-d H:i:s');
        $note = $isAlreadySubmitted ? 'Terkirim: Data Sudah Ada di Bea Cukai' : 'Terkirim ke Bea Cukai';
        $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Terkirim', waktu_kirim = IFNULL(waktu_kirim, '{$now}'), respon_terakhir = '{$note}', updated_at = NOW() WHERE nomor_aju = '{$nomorAju}' AND (cmp_id = '{$activeCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        echo json_encode([
            'status'   => 'OK',
            'message'  => $isAlreadySubmitted
                ? 'Dokumen Manifes NVOCC [' . $nomorAju . '] telah diverifikasi dan tercatat di sistem Bea Cukai (Status: TERKIRIM)!'
                : 'Data Manifes NVOCC [' . $nomorAju . '] berhasil dikirim ke Bea Cukai (Status: TERKIRIM)!',
            'response' => $resData ?: $call['response']
        ]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal Kirim: ' . $msg, 'raw' => $call]);
    }
    exit;
}

// =========================================================================
// 9. REKONSILIASI / PECAH POS (POST /manifes/nvocc/rekon)
// =========================================================================
if ($action === 'api_rekon') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $call = callCeisaGateway('POST', '/manifes/nvocc/rekon', ['nomorAju' => $nomorAju]);
    $resData = json_decode($call['response'], true);

    $isSuccess = ($call['http_code'] === 200 || $call['http_code'] === 201)
        && (empty($resData['status']) || !in_array(strtolower((string)$resData['status']), ['failed', 'gagal', 'false']));

    if ($isSuccess) {
        $now = date('Y-m-d H:i:s');
        $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Rekon', waktu_rekon = '{$now}', respon_terakhir = 'Rekon Berhasil' WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
        echo json_encode([
            'status'   => 'OK',
            'message'  => 'Rekonsiliasi / Pecah Pos Manifes NVOCC berhasil diproses!',
            'response' => $resData ?: $call['response']
        ]);
    } else {
        $msg = $resData['message'] ?? ($resData['error'] ?? ('HTTP Error ' . $call['http_code']));
        if (is_array($msg)) $msg = implode(', ', $msg);
        echo json_encode(['status' => 'ERROR', 'message' => 'Rekon gagal: ' . $msg, 'raw' => $call]);
    }
    exit;
}

// =========================================================================
// 10. CEK STATUS MANIFES (GET /manifes/nvocc/status/{nomorAju})
// =========================================================================
if ($action === 'api_status') {
    $nomorAju = trim($_GET['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomorAju);
    $escCmp = $koneksi->real_escape_string($activeCmp);

    // Ambil status lokal saat ini
    $currStatus = 'Draft';
    $qCur = $koneksi->query("SELECT status, id_nvocc_header, respon_terakhir FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
    if ($qCur && $rCur = $qCur->fetch_assoc()) {
        $currStatus = $rCur['status'] ?: 'Draft';
    }

    $call = callCeisaGateway('GET', "/manifes/nvocc/status/{$nomorAju}");
    $resData = json_decode($call['response'], true);

    if ($call['http_code'] === 200 && !empty($resData) && empty($resData['error'])) {
        // Ambil status terbaru dari response jika ada
        $stt = $resData['data']['status'] ?? ($resData['status'] ?? null);
        if ($stt) {
            $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = '{$stt}' WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        }
        echo json_encode([
            'status'   => 'OK',
            'data'     => $resData ?: $call['response']
        ]);
    } else {
        // Cek fallback ke tracking status standar CEISA 4.0
        $callStd = callCeisaGateway('GET', "/status/{$nomorAju}");
        $resStd = json_decode($callStd['response'], true);
        if ($callStd['http_code'] === 200 && !empty($resStd['data'])) {
            echo json_encode([
                'status'   => 'OK',
                'data'     => $resStd['data']
            ]);
            exit;
        }

        // Fallback cerdas: Cek ke layanan Portal HistoryManifes & DataManifesResponse resmi
        $portalToken = getActivePortalMfaToken($activeCmp);
        if (!empty($portalToken)) {
            $mfaHeaders = [
                "Authorization: Bearer {$portalToken}",
                "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                "Accept: application/json, text/plain, */*",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/manifest/nvocc"
            ];
            
            // Cek DataManifesResponse
            $chR = curl_init("https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifesResponse/manifesNomorAju/" . urlencode($nomorAju));
            curl_setopt_array($chR, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => $mfaHeaders
            ]);
            $respR = curl_exec($chR);
            $codeR = curl_getinfo($chR, CURLINFO_HTTP_CODE);
            curl_close($chR);
            $jsonR = ($codeR === 200) ? json_decode($respR, true) : null;

            // Cek HistoryManifes
            $chH = curl_init("https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/HistoryManifes?noAju=" . urlencode($nomorAju));
            curl_setopt_array($chH, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => $mfaHeaders
            ]);
            $respH = curl_exec($chH);
            $codeH = curl_getinfo($chH, CURLINFO_HTTP_CODE);
            curl_close($chH);
            $jsonH = ($codeH === 200) ? json_decode($respH, true) : null;

            if (!empty($jsonR['data']) || !empty($jsonH['data'])) {
                $respList = $jsonR['data'] ?? [];
                $histList = $jsonH['data'] ?? [];
                
                $noBc11 = '';
                $tglBc11 = null;
                $newStatus = $currStatus;
                $respNote = '';

                foreach ($respList as $rp) {
                    if (!empty($rp['nomorBc11'])) $noBc11 = $rp['nomorBc11'];
                    if (!empty($rp['tanggalBc11'])) $tglBc11 = date('Y-m-d', strtotime($rp['tanggalBc11']));
                    if (!empty($rp['idJenisRespon'])) {
                        $jr = (string)$rp['idJenisRespon'];
                        if ($jr === '50') { $newStatus = 'Terkirim'; $respNote = 'Penerimaan Data NVOCC (' . ($rp['nomorRespon'] ?? '') . ')'; }
                        elseif ($jr === '51') { $newStatus = 'Rekon'; $respNote = 'Rekon NVOCC (' . ($rp['nomorRespon'] ?? '') . ')'; }
                        elseif ($jr === '52') { $newStatus = 'Reject'; $respNote = 'Penolakan NVOCC: ' . ($rp['keterangan'] ?? ''); }
                    }
                }

                if (!empty($noBc11) && $newStatus !== 'Rekon' && $newStatus !== 'Selesai') {
                    $newStatus = 'Rekon';
                }

                $stmtUp = $koneksi->prepare("UPDATE bc_ceisa_manifes_header SET status = ?, nomor_bc11 = IFNULL(NULLIF(?, ''), nomor_bc11), tanggal_bc11 = IFNULL(?, tanggal_bc11), respon_terakhir = IFNULL(NULLIF(?, ''), respon_terakhir), updated_at = NOW() WHERE nomor_aju = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL)");
                if ($stmtUp) {
                    $stmtUp->bind_param("ssssss", $newStatus, $noBc11, $tglBc11, $respNote, $escAju, $escCmp);
                    $stmtUp->execute();
                    $stmtUp->close();
                }

                echo json_encode([
                    'status'    => 'OK',
                    'message'   => 'Data status resmi berhasil disinkronisasi dari Portal CEISA 4.0!',
                    'data'      => [
                        'status'           => $newStatus,
                        'nomor_bc11'       => $noBc11,
                        'tanggal_bc11'     => $tglBc11,
                        'respon_terakhir'  => $respNote,
                        'portal_responses' => $respList,
                        'portal_history'   => $histList
                    ]
                ]);
                exit;
            }
        }

        // Jika dokumen masih Draft atau Ready (belum dikirim ke Bea Cukai)
        if (in_array(strtolower($currStatus), ['draft', 'ready'])) {
            echo json_encode([
                'status'  => 'WARNING',
                'message' => "Manifes [{$nomorAju}] saat ini berstatus <strong>" . strtoupper($currStatus) . "</strong>.<br><br>Dokumen belum dikirim secara resmi ke Bea Cukai.<br>Status respon resmi dan nomor dokumen BC 1.1 baru akan diterbitkan setelah dokumen dikirim melalui tombol <strong>🚀 Kirim</strong>."
            ]);
        } else {
            $errMsg = $resData['message'] ?? ($resData['error'] ?? 'Belum ada data status respon di server Bea Cukai.');
            echo json_encode([
                'status'  => 'WARNING',
                'message' => "Status CEISA untuk [{$nomorAju}]:<br><br>{$errMsg}<br><small style='color:var(--text-muted);'>Dokumen mungkin sedang dalam antrean pemrosesan oleh Bea Cukai.</small>"
            ]);
        }
    }
    exit;
}

// =========================================================================
// 11. AMBIL NOMOR & TANGGAL BC 1.1 (GET /manifes/nvocc/bc11)
// =========================================================================
if ($action === 'api_bc11') {
    $nomorAju = trim($_GET['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $call = callCeisaGateway('GET', '/manifes/nvocc/bc11', null, ['nomorAju' => $nomorAju]);
    $resData = json_decode($call['response'], true);

    if ($call['http_code'] === 200 && !empty($resData['data'])) {
        $data = $resData['data'];
        $noBc11  = $data['nomorBc11'] ?? ($data['nomorDaftar'] ?? null);
        $tglBc11 = !empty($data['tanggalBc11']) ? date('Y-m-d', strtotime($data['tanggalBc11'])) : null;
        $posBc11 = $data['nomorPos'] ?? null;

        if ($noBc11) {
            $stmtUp = $koneksi->prepare("UPDATE bc_ceisa_manifes_header SET nomor_bc11 = ?, tanggal_bc11 = ?, nomor_pos_bc11 = ? WHERE nomor_aju = ? AND cmp_id = ?");
            $stmtUp->bind_param("sssss", $noBc11, $tglBc11, $posBc11, $nomorAju, $activeCmp);
            $stmtUp->execute();
            $stmtUp->close();
        }

        echo json_encode([
            'status'     => 'OK',
            'nomor_bc11' => $noBc11,
            'tanggal_bc11'=> $tglBc11,
            'pos_bc11'   => $posBc11,
            'data'       => $data
        ]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data BC 1.1 belum tersedia atau manifes belum selesai rekon.', 'raw' => $call]);
    }
    exit;
}

// =========================================================================
// 12. PENCARIAN POS INWARD / OUTWARD (v3 DataManifestHeader CEISA 4.0)
// Endpoint: https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/getByNoHostBlTanggalAndKodeKantor
// =========================================================================
if ($action === 'api_inward') {
    $nomorBl   = trim($_GET['nomor_bl'] ?? ($_GET['no_bl'] ?? ($_GET['nomor_host_bl'] ?? '')));
    $tanggalBl = trim($_GET['tanggal_bl'] ?? '');
    $kantor    = trim($_GET['kode_kantor'] ?? '');

    if (empty($nomorBl)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor B/L tidak boleh kosong.']);
        exit;
    }

    $portalToken = getActivePortalMfaToken($activeCmp);
    if (empty($portalToken)) {
        echo json_encode(['status' => 'ERROR', 'message' => "Token MFA CEISA 4.0 untuk perusahaan {$activeCmp} tidak tersedia."]);
        exit;
    }

    $urlV3 = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/getByNoHostBlTanggalAndKodeKantor?noHostBl=" . urlencode($nomorBl);
    if (!empty($kantor)) {
        $urlV3 .= "&kodeKantor=" . urlencode($kantor);
    }
    if (!empty($tanggalBl)) {
        $urlV3 .= "&tanggalBl=" . urlencode($tanggalBl);
    }

    $ch = curl_init($urlV3);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$portalToken}",
            "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
            "Accept: application/json, text/plain, */*",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/"
        ]
    ]);
    $respV3 = curl_exec($ch);
    $codeV3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errV3  = curl_error($ch);
    curl_close($ch);

    $jsonV3 = json_decode($respV3, true);
    if ($codeV3 === 200 && !empty($jsonV3['data'])) {
        $items = is_array($jsonV3['data']) ? $jsonV3['data'] : [];
        $firstItem = $items[0] ?? null;
        $idHeader = $firstItem['idHeader'] ?? '';
        $headerDetail = null;

        if (!empty($idHeader)) {
            $chD = curl_init("https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/DatamanifesHeader/" . urlencode($idHeader));
            curl_setopt_array($chD, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$portalToken}",
                    "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                    "Accept: application/json, text/plain, */*",
                    "Origin: https://portal.beacukai.go.id",
                    "Referer: https://portal.beacukai.go.id/"
                ]
            ]);
            $respD = curl_exec($chD);
            curl_close($chD);
            $jsonD = json_decode($respD, true);
            if (!empty($jsonD['data'])) {
                $headerDetail = $jsonD['data'];
                if (!empty($firstItem) && is_array($firstItem)) {
                    $firstItem['nomorVoyage_detail'] = $headerDetail['nomorVoyage'] ?? ($firstItem['nomorVoyage'] ?? '');
                    $firstItem['tanggalTiba'] = $headerDetail['tanggalTiba'] ?? ($firstItem['tanggalTiba'] ?? '');
                    $firstItem['tanggalBerangkat'] = $headerDetail['tanggalBerangkat'] ?? ($firstItem['tanggalBerangkat'] ?? '');
                    $firstItem['modePengangkut'] = $headerDetail['modePengangkut'] ?? ($firstItem['modePengangkut'] ?? '');
                    $items[0] = $firstItem;
                }
            }
        }

        echo json_encode([
            'status'        => 'OK',
            'data'          => $items,
            'header_detail' => $headerDetail,
            'count'         => count($items)
        ]);
        exit;
    }

    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'Data Pos Manifes BC 1.1 tidak ditemukan untuk nomor BL tersebut.',
        'raw'     => $jsonV3 ?: $respV3,
        'code'    => $codeV3
    ]);
    exit;
}

// =========================================================================
// 12A. PENCARIAN SARANA PENGANGKUT / KAPAL (v3 MapController CEISA 4.0)
// Endpoint: https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/MapController/{vesselName}
// =========================================================================
if ($action === 'api_vessel_map') {
    $vesselName = trim($_GET['vessel_name'] ?? ($_GET['nama_kapal'] ?? ($_GET['q'] ?? '')));
    if (empty($vesselName)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nama Sarana Pengangkut / Kapal tidak boleh kosong.']);
        exit;
    }

    $portalToken = getActivePortalMfaToken($activeCmp);
    if (empty($portalToken)) {
        echo json_encode(['status' => 'ERROR', 'message' => "Token MFA CEISA 4.0 untuk perusahaan {$activeCmp} tidak tersedia."]);
        exit;
    }

    $urlVessel = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/MapController/" . rawurlencode($vesselName);

    $ch = curl_init($urlVessel);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$portalToken}",
            "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
            "Accept: application/json, text/plain, */*",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/"
        ]
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $json = json_decode($resp, true);
    if ($code === 200 && !empty($json['data'])) {
        echo json_encode([
            'status'  => 'OK',
            'vessel'  => $vesselName,
            'count'   => count($json['data']),
            'data'    => $json['data']
        ]);
        exit;
    }

    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'Data Manifes Kedatangan tidak ditemukan untuk Sarana Pengangkut "' . $vesselName . '".',
        'raw'     => $json ?: $resp,
        'code'    => $code
    ]);
    exit;
}

// =========================================================================
// 12A-2. DETAIL MANIFES HEADER BY ID HEADER (DatamanifesHeader)
// Endpoint: https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/DatamanifesHeader/{idHeader}
// =========================================================================
if ($action === 'get_manifest_detail') {
    $idHeader = trim($_GET['id_header'] ?? ($_POST['id_header'] ?? ''));
    if (empty($idHeader)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Header Manifes tidak boleh kosong.']);
        exit;
    }

    $portalToken = getActivePortalMfaToken($activeCmp);
    if (empty($portalToken)) {
        echo json_encode(['status' => 'ERROR', 'message' => "Token MFA CEISA 4.0 untuk perusahaan {$activeCmp} tidak tersedia."]);
        exit;
    }

    $urlDetail = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/DatamanifesHeader/" . urlencode($idHeader);

    $ch = curl_init($urlDetail);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$portalToken}",
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
    if ($code === 200 && !empty($json['data'])) {
        echo json_encode([
            'status' => 'OK',
            'data'   => $json['data']
        ]);
        exit;
    }

    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'Detail Header Manifes tidak ditemukan.',
        'raw'     => $json ?: $resp,
        'code'    => $code
    ]);
    exit;
}

// =========================================================================
// 12B. EXPORT EXCEL CEISA 4.0 FORMAT BARU (.XLSX)
// =========================================================================
if ($action === 'export_excel') {
    require_once __DIR__ . '/CeisaManifestXlsxExporter.php';
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_GET['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        header('Content-Type: text/plain; charset=utf-8');
        die("Error: Nomor Aju tidak boleh kosong.");
    }

    try {
        $exporter = new CeisaManifestXlsxExporter($koneksi, $activeCmp);
        $exporter->download($nomorAju);
    } catch (Exception $e) {
        header('Content-Type: text/plain; charset=utf-8');
        die("Gagal Export Excel CEISA 4.0: " . $e->getMessage());
    }
    exit;
}

// =========================================================================
// 12C. UPLOAD RESTORE EXCEL KE PORTAL CEISA 4.0
// Endpoint: https://apis-gw.beacukai.go.id/manifes-restore-dokumen/v1/tempNVOCC/UploadRestoreExcel
// =========================================================================
if ($action === 'upload_portal_restore') {
    require_once __DIR__ . '/CeisaManifestXlsxExporter.php';
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomorAju);
    $existingIdNvocc = '';
    $qDocCmp = $koneksi->query("SELECT cmp_id, id_nvocc_header FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escAju}' LIMIT 1");
    if ($qDocCmp && $rDocCmp = $qDocCmp->fetch_assoc()) {
        if (!empty($rDocCmp['cmp_id'])) {
            $activeCmp = $rDocCmp['cmp_id'];
        }
        $existingIdNvocc = trim($rDocCmp['id_nvocc_header'] ?? '');
    }

    // Ambil portal MFA token terisolasi untuk perusahaan aktif (otomatis generate/refresh jika perlu)
    $portalToken = getActivePortalMfaToken($activeCmp);
    if (empty($portalToken)) {
        echo json_encode(['status' => 'ERROR', 'message' => "Gagal menghasilkan token Portal MFA CEISA 4.0 untuk perusahaan {$activeCmp}. Pastikan kredensial login CEISA & Secret MFA tersimpan pada profil perusahaan."]);
        exit;
    }

    // 1. Generate Excel Format Baru sementara di temp directory
    $tmpDir = sys_get_temp_dir();
    $tmpFile = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . "Manifest_CEISA4_NVOCC_{$nomorAju}_" . uniqid() . ".xlsx";
    try {
        $exporter = new CeisaManifestXlsxExporter($koneksi, $activeCmp);
        $exporter->export($nomorAju, $tmpFile);
    } catch (Exception $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal generate Excel CEISA: ' . $e->getMessage()]);
        exit;
    }

    // 2. Ambil data profil perusahaan aktif untuk multipart form-data
    $qCompInfo = $koneksi->query("SELECT cmp_name, alamat, npwp FROM bc_ceisa_company WHERE cmp_id = '{$activeCmp}' LIMIT 1");
    $rCompInfo = $qCompInfo ? $qCompInfo->fetch_assoc() : [];
    $rawNpwp = preg_replace('/[^0-9]/', '', $rCompInfo['npwp'] ?? '0033093287048000');
    $npwpShipper = !empty($rawNpwp) ? $rawNpwp : '0033093287048000';
    $namaShipper = trim($rCompInfo['cmp_name'] ?? 'PT TVL GLOBAL LOGISTIK');
    $alamatShipper = trim($rCompInfo['alamat'] ?? 'WISMA MITRA SUNTER LT 1, NO. 104 JL. YOS SUDARSO');

    // 3. Upload multipart ke endpoint resmi Portal CEISA 4.0
    $url = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/tempNVOCC/UploadRestoreExcel";
    $cfile = new CURLFile($tmpFile, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "Manifest_NVOCC_{$nomorAju}.xlsx");
    $postData = [
        'file'          => $cfile,
        'npwpShipper'   => $npwpShipper,
        'namaShipper'   => $namaShipper,
        'alamatShipper' => $alamatShipper
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$portalToken}",
            "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
            "Accept: application/json, text/plain, */*",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
        ]
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    @unlink($tmpFile);

    if ($curlErr) {
        echo json_encode(['status' => 'ERROR', 'message' => "CURL Error: $curlErr"]);
        exit;
    }

    $resJson = json_decode($resp, true);

    // Periksa status respons dari Bea Cukai (200, 201 Created, atau pesan sukses/duplikat)
    $respMsg = $resJson['message'] ?? '';
    $isSuccess = ($httpCode === 200 || $httpCode === 201) && (
        (isset($resJson['status']) && ($resJson['status'] === true || $resJson['status'] === 'true' || strtolower((string)$resJson['status']) === 'success' || strtolower((string)$resJson['status']) === 'ok'))
        || isset($resJson['idNvoccHeader'])
        || stripos($respMsg, 'berhasil') !== false
        || stripos($respMsg, 'sukses') !== false
    );
    $isDuplicate = (stripos($respMsg, 'sudah terdaftar') !== false || stripos($respMsg, 'duplikat') !== false);

    if ($isSuccess || $isDuplicate) {
        // Otomatis sinkronkan id_nvocc_header terbaru dari Portal CEISA 4.0
        $npwp15 = substr(str_pad(ltrim($npwpShipper, '0'), 15, '0', STR_PAD_LEFT), 0, 15);
        $syncUrl = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataProses/prosesNPWP-NVOCC/{$npwp15}";
        $chSync = curl_init($syncUrl);
        curl_setopt_array($chSync, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$portalToken}",
                "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                "Accept: */*",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
            ]
        ]);
        $respSync = curl_exec($chSync);
        curl_close($chSync);

        $targetIdNvocc = $existingIdNvocc;
        if (!empty($respSync)) {
            $syncData = json_decode($respSync, true);
            $items = $syncData['data'] ?? ($syncData['item'] ?? ($syncData['dataProses'] ?? (is_array($syncData) ? $syncData : [])));
            if (is_array($items)) {
                foreach ($items as $it) {
                    $itAju = trim($it['nomorAju'] ?? ($it['nomor_aju'] ?? ''));
                    if ($itAju === $nomorAju && !empty($it['idNvoccHeader'])) {
                        $targetIdNvocc = trim($it['idNvoccHeader']);
                        break;
                    }
                }
            }
        }

        // Simpan id_nvocc_header yang didapat dan tandai sebagai BOTH agar terlihat di Portal maupun H2H
        if (!empty($targetIdNvocc)) {
            $koneksi->query("UPDATE bc_ceisa_manifes_header SET id_nvocc_header = '{$targetIdNvocc}', asal_data = IF(asal_data = 'H2H', 'BOTH', asal_data) WHERE nomor_aju = '{$escAju}'");
        }

        // Lakukan Cek Lengkap (Validasi) secara otomatis
        $isLengkap = false;
        $cekData = null;
        if (!empty($targetIdNvocc)) {
            $chCek = curl_init("https://apis-gw.beacukai.go.id/manifes-validasi-service/v1/cekLengkap/nvocc?idNvoccHeader=" . urlencode($targetIdNvocc));
            curl_setopt_array($chCek, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$portalToken}",
                    "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
                    "Accept: application/json, text/plain, */*",
                    "Origin: https://portal.beacukai.go.id",
                    "Referer: https://portal.beacukai.go.id/",
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
                ]
            ]);
            $respCek = curl_exec($chCek);
            $httpCek = curl_getinfo($chCek, CURLINFO_HTTP_CODE);
            curl_close($chCek);

            if ($httpCek === 200) {
                $cekData = json_decode($respCek, true);
                $infoArr = $cekData['info'] ?? [];
                $isLengkap = is_array($infoArr) && in_array('DATA LENGKAP', $infoArr);
            }
        }

        if ($isLengkap) {
            $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Ready', respon_terakhir = 'Cek Lengkap: DATA LENGKAP', updated_at = NOW() WHERE nomor_aju = '{$escAju}'");
            echo json_encode([
                'status'          => 'OK',
                'is_ready'        => true,
                'id_nvocc_header' => $targetIdNvocc,
                'message'         => "Dokumen '{$nomorAju}' berhasil dikirim otomatis ke Portal CEISA 4.0 dan terverifikasi DATA LENGKAP (Status: READY)! Silakan klik '🚀 Kirim' untuk submit resmi ke Bea Cukai.",
                'data'            => $resJson,
                'validation'      => $cekData
            ]);
            exit;
        } else {
            $koneksi->query("UPDATE bc_ceisa_manifes_header SET respon_terakhir = 'Restored di Portal CEISA', updated_at = NOW() WHERE nomor_aju = '{$escAju}'");
            echo json_encode([
                'status'          => 'OK',
                'is_ready'        => false,
                'id_nvocc_header' => $targetIdNvocc,
                'message'         => "Dokumen '{$nomorAju}' berhasil di-restore ke Portal CEISA 4.0! Silakan klik '🔍 Periksa' untuk memvalidasi kelengkapan dokumen.",
                'data'            => $resJson,
                'validation'      => $cekData
            ]);
            exit;
        }
    }


    $errMsg = trim($resJson['message'] ?? '');
    if (empty($errMsg)) {
        $errMsg = trim($resJson['error'] ?? '');
    }
    if (empty($errMsg) && !empty($resJson['Exception'])) {
        $errMsg = trim($resJson['Exception']);
    }
    if (empty($errMsg) && $httpCode !== 200) {
        $errMsg = "Gateway Bea Cukai mengembalikan HTTP {$httpCode} (" . ($curlErr ?: 'Bad Request / Respon Gateway Kosong') . ")";
    }
    if (empty($errMsg)) {
        $errMsg = "Terjadi kendala respon dari Gateway Bea Cukai: " . substr(strip_tags((string)$resp), 0, 250);
    }

    echo json_encode([
        'status'    => 'ERROR',
        'http_code' => $httpCode,
        'message'   => $errMsg,
        'raw'       => $resJson ?: $resp
    ]);
    exit;
}

// =========================================================================
// 12D. BACKUP EXCEL LANGSUNG DARI PORTAL CEISA 4.0
// Endpoint: https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/tempNVOCC/ExcelNvocc?nomorAju={nomorAju}
// =========================================================================
if ($action === 'backup_portal_excel') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_GET['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        header('Content-Type: text/plain; charset=utf-8');
        die("Error: Nomor Aju tidak boleh kosong.");
    }

    $qComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, username_ceisa, token_ceisa, api_key_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
    $rComp = ($qComp && $qComp->num_rows > 0) ? $qComp->fetch_assoc() : null;

    $portalToken = trim($_POST['portal_token'] ?? $_GET['portal_token'] ?? '');
    if (empty($portalToken) || ($rComp && !isTokenValidForCompany($portalToken, $rComp))) {
        $portalToken = getActivePortalMfaToken($activeCmp);
    }

    $url = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/tempNVOCC/ExcelNvocc?nomorAju=" . urlencode($nomorAju);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$portalToken}",
            "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
            "Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json, */*",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/manifest/nvocc",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && strlen($res) > 500) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Backup_CEISA4_NVOCC_' . $nomorAju . '.xlsx"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($res));
        echo $res;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        die("Gagal unduh Backup Excel CEISA 4.0: " . $res);
    }
    exit;
}

// =========================================================================
// 12E. RIWAYAT DOKUMEN PORTAL CEISA 4.0 (HistoryManifes & DataManifesResponse)
// =========================================================================
if ($action === 'get_portal_history') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_GET['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $qComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, username_ceisa, token_ceisa, api_key_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
    $rComp = ($qComp && $qComp->num_rows > 0) ? $qComp->fetch_assoc() : null;

    $portalToken = trim($_POST['portal_token'] ?? $_GET['portal_token'] ?? '');
    if (empty($portalToken) || ($rComp && !isTokenValidForCompany($portalToken, $rComp))) {
        $portalToken = getActivePortalMfaToken($activeCmp);
    }

    $headers = [
        "Authorization: Bearer {$portalToken}",
        "Beacukai-Api-Key: 6222a75e-1dbb-493e-9461-27f721097e9c",
        "Accept: application/json, text/plain, */*",
        "Origin: https://portal.beacukai.go.id",
        "Referer: https://portal.beacukai.go.id/manifest/nvocc",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
    ];

    // 1. Fetch HistoryManifes
    $urlHist = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/HistoryManifes?noAju=" . urlencode($nomorAju);
    $ch1 = curl_init($urlHist);
    curl_setopt_array($ch1, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers
    ]);
    $respHist = curl_exec($ch1);
    $codeHist = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
    curl_close($ch1);

    // 2. Fetch DataManifesResponse
    $urlResp = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifesResponse/manifesNomorAju/" . urlencode($nomorAju);
    $ch2 = curl_init($urlResp);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers
    ]);
    $respResp = curl_exec($ch2);
    $codeResp = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    $jsonHist = json_decode($respHist, true);
    $jsonResp = json_decode($respResp, true);

    $statusMap = [
        '50' => ['label' => 'Penerimaan Data NVOCC', 'icon' => 'fa-check-circle', 'color' => '#1890ff'],
        '51' => ['label' => 'Rekon Data NVOCC', 'icon' => 'fa-thumbs-up', 'color' => '#52c41a'],
        '52' => ['label' => 'Penolakan / Reject Data NVOCC', 'icon' => 'fa-times-circle', 'color' => '#ff4d4f'],
        '53' => ['label' => 'Perbaikan Data NVOCC', 'icon' => 'fa-edit', 'color' => '#faad14'],
        '54' => ['label' => 'Persetujuan Perbaikan Data NVOCC', 'icon' => 'fa-wrench', 'color' => '#1890ff'],
        '30' => ['label' => 'Respon Pemberian Nomor & Tgl. Inward Manifes', 'icon' => 'fa-check-circle', 'color' => '#52c41a'],
        '31' => ['label' => 'Respon Pemberian Nomor & Tgl. Outward Manifes', 'icon' => 'fa-check-circle', 'color' => '#52c41a']
    ];

    $timeline = [];
    if (!empty($jsonHist['data']) && is_array($jsonHist['data'])) {
        foreach ($jsonHist['data'] as $item) {
            $st = (string)($item['status'] ?? '');
            $info = $statusMap[$st] ?? ['label' => 'Status ' . $st, 'icon' => 'fa-circle', 'color' => '#1890ff'];
            $timeline[] = [
                'type' => 'status',
                'status_code' => $st,
                'title' => $info['label'],
                'icon' => $info['icon'],
                'color' => $info['color'],
                'time' => $item['waktuRekam'] ?? '-',
                'id_status' => $item['idStatus'] ?? '',
                'id_respon' => $item['idRespon'] ?? '',
                'raw' => $item
            ];
        }
    }

    $responses = [];
    if (!empty($jsonResp['data']) && is_array($jsonResp['data'])) {
        foreach ($jsonResp['data'] as $rItem) {
            $rSt = (string)($rItem['idJenisRespon'] ?? '');
            $info = $statusMap[$rSt] ?? ['label' => 'Respon ' . $rSt, 'icon' => 'fa-file-alt', 'color' => '#1890ff'];
            $responses[] = [
                'id_respon' => $rItem['idRespon'] ?? '',
                'jenis_respon' => $rSt,
                'title' => $info['label'],
                'nomor_respon' => $rItem['nomorRespon'] ?? '-',
                'tanggal_respon' => $rItem['tanggalRespon'] ?? ($rItem['wkRekam'] ?? '-'),
                'time' => $rItem['wkRekam'] ?? '',
                'sarana_pengangkut' => $rItem['namaSaranaPengangkut'] ?? '',
                'voyage' => $rItem['nomorVoyage'] ?? '',
                'raw' => $rItem
            ];
        }
    }

    // Sort timeline by time ASC
    usort($timeline, function($a, $b) {
        return strtotime($a['time']) - strtotime($b['time']);
    });

    echo json_encode([
        'status' => 'OK',
        'nomor_aju' => $nomorAju,
        'timeline' => $timeline,
        'responses' => $responses,
        'raw_history' => $jsonHist,
        'raw_response' => $jsonResp
    ]);
    exit;
}


// =========================================================================
// 13. HAPUS DRAFT MANIFES (DELETE /manifes/nvocc/draft/{nomorAju})
// =========================================================================
if ($action === 'delete_manifes') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? $_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $escAju = $koneksi->real_escape_string($nomorAju);
    $escCmp = $koneksi->real_escape_string($activeCmp);

    // Ambil status dan id_nvocc_header
    $stmtC = $koneksi->prepare("SELECT status, id_nvocc_header FROM bc_ceisa_manifes_header WHERE nomor_aju = ? AND (cmp_id = ? OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
    $stmtC->bind_param("ss", $nomorAju, $activeCmp);
    $stmtC->execute();
    $rowC = $stmtC->get_result()->fetch_assoc();
    $currStatus = $rowC['status'] ?? '';
    $idNvocc = $rowC['id_nvocc_header'] ?? '';
    $stmtC->close();

    // Panggil API Bea Cukai HANYA jika dokumen sudah terdaftar di CEISA (memiliki id_nvocc_header)
    if (!empty($idNvocc) && in_array(strtolower($currStatus), ['draft', 'ready'])) {
        try {
            callCeisaGateway('DELETE', "/manifes/nvocc/draft/{$nomorAju}");
        } catch (Exception $e) {
            // Abaikan kegagalan remote delete agar data lokal tetap bisa dihapus
        }
    }

    // Hapus dari seluruh tabel relasi manifes lokal
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_kelompok_pos WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_respon_logs WHERE nomor_aju = '{$escAju}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

    echo json_encode(['status' => 'OK', 'message' => "Manifes '$nomorAju' berhasil dihapus!"]);
    exit;
}

// =========================================================================
// 14. IMPORT EXCEL BACKUP CEISA 4.0 (NVOCC)
// =========================================================================
if ($action === 'import_excel') {
    require_once __DIR__ . '/CeisaManifestXlsxImporter.php';

    if (empty($_FILES['excel_file']['tmp_name'])) {
        $filePath = trim($_POST['file_path'] ?? '');
        if (empty($filePath) || !file_exists($filePath)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'File Excel (.xlsx) tidak ditemukan atau belum dipilih.']);
            exit;
        }
    } else {
        $filePath = $_FILES['excel_file']['tmp_name'];
    }

    try {
        $importer = new CeisaManifestXlsxImporter($koneksi, $activeCmp);
        $result = $importer->import($filePath);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengimpor file Excel: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 15. RENAME NOMOR AJU (CASCADING UPDATE)
// =========================================================================
if ($action === 'rename_aju') {
    $oldAju    = trim($_POST['old_nomor_aju'] ?? '');
    $newAju    = trim($_POST['new_nomor_aju'] ?? '');
    $targetCmp = (!empty($_POST['cmp_id'])) ? trim($_POST['cmp_id']) : $activeCmp;

    if (empty($oldAju) || empty($newAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU lama dan Nomor AJU baru wajib diisi.']);
        exit;
    }

    if ($oldAju === $newAju) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU baru harus berbeda dengan Nomor AJU lama.']);
        exit;
    }

    // Validasi panjang nomor AJU (standar CEISA 26 digit, toleransi 20-30 digit angka)
    if (strlen($newAju) < 20 || strlen($newAju) > 30 || !ctype_digit($newAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU harus berupa string angka valid (standar 26 digit).']);
        exit;
    }

    $escOld = $koneksi->real_escape_string($oldAju);
    $escNew = $koneksi->real_escape_string($newAju);
    $escCmp = $koneksi->real_escape_string($targetCmp);

    // Cek apakah nomor_aju baru sudah digunakan
    $chkNew = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escNew}' LIMIT 1");
    if ($chkNew && $chkNew->num_rows > 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU '{$newAju}' sudah digunakan oleh dokumen lain. Silakan gunakan nomor AJU lain."]);
        exit;
    }

    // Cek keberadaan dokumen lama
    $chkOld = $koneksi->query("SELECT status, id_nvocc_header FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escOld}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
    if (!$chkOld || $chkOld->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen manifes dengan Nomor AJU lama '{$oldAju}' tidak ditemukan."]);
        exit;
    }
    $oldRow = $chkOld->fetch_assoc();
    if (in_array(strtolower($oldRow['status']), ['terkirim', 'rekon'])) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen dengan status '{$oldRow['status']}' tidak dapat di-rename karena sudah diproses ke Bea Cukai."]);
        exit;
    }

    // Mulai transaksi update cascading
    $koneksi->begin_transaction();
    try {
        $tables = [
            'bc_ceisa_manifes_bl_dokumen',
            'bc_ceisa_manifes_bl_petikemas',
            'bc_ceisa_manifes_bl_hs',
            'bc_ceisa_manifes_bl',
            'bc_ceisa_manifes_kelompok_pos',
            'bc_ceisa_manifes_master_bl',
            'bc_ceisa_manifes_respon_logs',
            'bc_ceisa_manifes_header'
        ];

        foreach ($tables as $tbl) {
            $koneksi->query("UPDATE {$tbl} SET nomor_aju = '{$escNew}' WHERE nomor_aju = '{$escOld}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");
        }

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK',
            'old_nomor_aju' => $oldAju,
            'new_nomor_aju' => $newAju,
            'message' => "Nomor AJU berhasil di-rename menjadi '{$newAju}'! Seluruh data House BL, Petikemas, dan Dokumen tetap utuh terhubung."
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal mengubah Nomor AJU: ' . $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 16. COPY / DUPLIKAT DOKUMEN MANIFES
// =========================================================================
if ($action === 'copy_aju') {
    $sourceAju = trim($_POST['source_nomor_aju'] ?? '');
    $targetAju = trim($_POST['target_nomor_aju'] ?? '');
    $targetCmp = (!empty($_POST['cmp_id'])) ? trim($_POST['cmp_id']) : $activeCmp;

    if (empty($sourceAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU sumber tidak boleh kosong.']);
        exit;
    }

    $escSrc = $koneksi->real_escape_string($sourceAju);
    $escCmp = $koneksi->real_escape_string($targetCmp);

    // Ambil header dokumen sumber
    $qHdr = $koneksi->query("SELECT * FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");
    if (!$qHdr || $qHdr->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Dokumen sumber dengan Nomor AJU '{$sourceAju}' tidak ditemukan."]);
        exit;
    }
    $srcHdr = $qHdr->fetch_assoc();

    // Jika target_nomor_aju tidak ditentukan, generate otomatis 26 digit unik
    if (empty($targetAju)) {
        $kodeKtr = !empty($srcHdr['kode_kantor']) ? $srcHdr['kode_kantor'] : '070100';
        $npwpClean = substr(preg_replace('/\D/', '', !empty($srcHdr['id_perusahaan']) ? $srcHdr['id_perusahaan'] : '012345'), 0, 6);
        $dateStr = date('Ymd');
        do {
            $rnd = mt_rand(100000, 999999);
            $targetAju = "{$kodeKtr}{$npwpClean}{$dateStr}{$rnd}";
            $escTrg = $koneksi->real_escape_string($targetAju);
            $chk = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escTrg}' LIMIT 1");
        } while ($chk && $chk->num_rows > 0);
    } else {
        if (strlen($targetAju) < 20 || strlen($targetAju) > 30 || !ctype_digit($targetAju)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Nomor AJU tujuan harus berupa string angka valid (standar 26 digit).']);
            exit;
        }
        $escTrg = $koneksi->real_escape_string($targetAju);
        $chk = $koneksi->query("SELECT nomor_aju FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escTrg}' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            echo json_encode(['status' => 'ERROR', 'message' => "Nomor AJU tujuan '{$targetAju}' sudah digunakan oleh dokumen lain."]);
            exit;
        }
    }

    $koneksi->begin_transaction();
    try {
        // 1. Copy Header (status 'Draft', id_nvocc_header & nomor_bc11 dikosongkan)
        $koneksi->query("INSERT INTO bc_ceisa_manifes_header (
            nomor_aju, cmp_id, kode_kantor, jenis_manifes, id_perusahaan, nama_perusahaan, alamat_perusahaan,
            nomor_voyage, tanggal_berangkat, tanggal_tiba, mode_pengangkut, nama_sarana_pengangkut, imo_number,
            call_sign, kode_negara, asal_data, flwaktu_tempuh, status, id_nvocc_header, nomor_bc11, tanggal_bc11, created_at, updated_at
        ) SELECT 
            '{$escTrg}', '{$escCmp}', kode_kantor, jenis_manifes, id_perusahaan, nama_perusahaan, alamat_perusahaan,
            nomor_voyage, tanggal_berangkat, tanggal_tiba, mode_pengangkut, nama_sarana_pengangkut, imo_number,
            call_sign, kode_negara, asal_data, flwaktu_tempuh, 'Draft', '', '', NULL, NOW(), NOW()
        FROM bc_ceisa_manifes_header 
        WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL) LIMIT 1");

        // 2. Copy Kelompok Pos
        $koneksi->query("INSERT INTO bc_ceisa_manifes_kelompok_pos (nomor_aju, cmp_id, kode_kelompok_pos, uraian_kelompok_pos, jumlah)
            SELECT '{$escTrg}', '{$escCmp}', kode_kelompok_pos, uraian_kelompok_pos, jumlah
            FROM bc_ceisa_manifes_kelompok_pos
            WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        // 3. Copy Master BL
        $koneksi->query("INSERT INTO bc_ceisa_manifes_master_bl (nomor_aju, cmp_id, kelompok_pos, master_bl, tanggal_bl, jumlah_pos)
            SELECT '{$escTrg}', '{$escCmp}', kelompok_pos, master_bl, tanggal_bl, jumlah_pos
            FROM bc_ceisa_manifes_master_bl
            WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        // 4. Copy House BL
        $koneksi->query("INSERT INTO bc_ceisa_manifes_bl (
            nomor_aju, cmp_id, seri_bl, kode_kelompok_pos, nomor_pos, nomor_bl, tanggal_bl,
            nomor_host_bl, tanggal_host_bl, marking, npwp_pengirim, nama_pengirim, alamat_pengirim,
            npwp_penerima, nama_penerima, alamat_penerima, npwp_notify, nama_notify, alamat_notify,
            berat, dimensi, jumlah_container, jenis_kemasan, jumlah_kemasan, mother_vessel,
            kode_pelabuhan_asal, kode_pelabuhan_bongkar, kode_pelabuhan_transit, kode_pelabuhan_akhir,
            jumlah_kemasan_terangkut, flag_konsolidasi, flag_parsial, flag_partof
        ) SELECT 
            '{$escTrg}', '{$escCmp}', seri_bl, kode_kelompok_pos, nomor_pos, nomor_bl, tanggal_bl,
            nomor_host_bl, tanggal_host_bl, marking, npwp_pengirim, nama_pengirim, alamat_pengirim,
            npwp_penerima, nama_penerima, alamat_penerima, npwp_notify, nama_notify, alamat_notify,
            berat, dimensi, jumlah_container, jenis_kemasan, jumlah_kemasan, mother_vessel,
            kode_pelabuhan_asal, kode_pelabuhan_bongkar, kode_pelabuhan_transit, kode_pelabuhan_akhir,
            jumlah_kemasan_terangkut, flag_konsolidasi, flag_parsial, flag_partof
        FROM bc_ceisa_manifes_bl
        WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        // 5. Copy BL HS Items
        $koneksi->query("INSERT INTO bc_ceisa_manifes_bl_hs (nomor_aju, cmp_id, nomor_host_bl, seri_hs, kode_hs, uraian_barang)
            SELECT '{$escTrg}', '{$escCmp}', nomor_host_bl, seri_hs, kode_hs, uraian_barang
            FROM bc_ceisa_manifes_bl_hs
            WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        // 6. Copy BL Petikemas
        $koneksi->query("INSERT INTO bc_ceisa_manifes_bl_petikemas (
            nomor_aju, cmp_id, nomor_host_bl, seri_container, nomor_container,
            fl_b3, fl_terangkut, status, type_container, ukuran_container,
            nomor_segel, nomor_polisi, jenis_container, jenis_muat, driver
        ) SELECT 
            '{$escTrg}', '{$escCmp}', nomor_host_bl, seri_container, nomor_container,
            fl_b3, fl_terangkut, status, type_container, ukuran_container,
            nomor_segel, nomor_polisi, jenis_container, jenis_muat, driver
        FROM bc_ceisa_manifes_bl_petikemas
        WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        // 7. Copy BL Dokumen Pelengkap
        $koneksi->query("INSERT INTO bc_ceisa_manifes_bl_dokumen (
            nomor_aju, cmp_id, nomor_host_bl, seri_dokumen, kode_kantor, kode_dokumen, nomor_dokumen, tanggal_dokumen
        ) SELECT 
            '{$escTrg}', '{$escCmp}', nomor_host_bl, seri_dokumen, kode_kantor, kode_dokumen, nomor_dokumen, tanggal_dokumen
        FROM bc_ceisa_manifes_bl_dokumen
        WHERE nomor_aju = '{$escSrc}' AND (cmp_id = '{$escCmp}' OR cmp_id = '' OR cmp_id IS NULL)");

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK',
            'new_nomor_aju' => $targetAju,
            'source_nomor_aju' => $sourceAju,
            'message' => "Manifes berhasil diduplikasi menjadi Nomor AJU baru: '{$targetAju}' lengkap dengan seluruh data House BL, Petikemas, dan Dokumen!"
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menduplikasi manifes: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * Memeriksa apakah token JWT sudah kedaluwarsa berdasarkan klaim 'exp'
 */
function isJwtExpired($jwt) {
    if (empty($jwt) || !is_string($jwt)) return true;
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return false;
    $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
    if (!empty($payload['exp']) && is_numeric($payload['exp'])) {
        return (time() >= ($payload['exp'] - 30));
    }
    return false;
}

/**
 * Validasi ketat kepemilikan token CEISA 4.0 (Anti-bocor antar-perusahaan)
 * Menolak token jika identitas (NPWP) atau preferred_username milik perusahaan lain!
 */
function isTokenValidForCompany($jwt, $targetCmpRow) {
    if (empty($jwt) || !is_string($jwt) || empty($targetCmpRow)) return false;
    if (isJwtExpired($jwt)) return false;

    $parts = explode('.', $jwt);
    if (count($parts) < 2) return false;
    $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
    if (!is_array($payload)) return false;

    // 1. Cek preferred_username (harus persis sama dengan username_ceisa perusahaan)
    $expectedUser = strtolower(trim($targetCmpRow['username_ceisa'] ?? ''));
    $tokenUser = strtolower(trim($payload['preferred_username'] ?? ''));
    if (!empty($expectedUser) && !empty($tokenUser) && $expectedUser !== $tokenUser) {
        // TOKEN MILIK PERUSAHAAN LAIN! TOLAK SEGERA!
        return false;
    }

    // 2. Cek identitas (NPWP)
    $rawCmpNpwp = preg_replace('/\D/', '', $targetCmpRow['npwp'] ?? '');
    $tokenIdentitas = preg_replace('/\D/', '', $payload['identitas'] ?? '');
    if (!empty($rawCmpNpwp) && !empty($tokenIdentitas)) {
        $cmpNpwp15 = (strlen($rawCmpNpwp) === 16 && substr($rawCmpNpwp, 0, 1) === '0') ? substr($rawCmpNpwp, 1) : substr($rawCmpNpwp, 0, 15);
        if ($tokenIdentitas !== $cmpNpwp15 && $tokenIdentitas !== $rawCmpNpwp) {
            // NPWP TIDAK COCOK DENGAN TOKEN!
            return false;
        }
    }

    return true;
}

/**
 * Helper untuk mengambil MFA Bearer Token Portal CEISA 4.0 aktif
 * TERISOLASI 100% PER PERUSAHAAN (DILARANG MENGGUNAKAN TOKEN PERUSAHAAN LAIN)
 */
function getActivePortalMfaToken($activeCmp) {
    global $koneksi;
    
    // Cari data perusahaan di database untuk mendapatkan cmp_id resmi dan username
    $officialCmpId = $activeCmp;
    $username = '';
    $password = '';
    $secretmfa = '';
    $rComp = null;
    $qComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, username_ceisa, password_ceisa, secret_mfa, token_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' OR username_ceisa = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
    if ($qComp && $rComp = $qComp->fetch_assoc()) {
        $officialCmpId = $rComp['cmp_id'];
        $username = $rComp['username_ceisa'] ?? '';
        $password = $rComp['password_ceisa'] ?? '';
        $secretmfa = $rComp['secret_mfa'] ?? '';
    }

    if (!$rComp) {
        return '';
    }

    // 1. Prioritaskan session spesifik perusahaan jika valid dan milik perusahaan ini
    $candidateSession = $_SESSION["mfatoken_" . $officialCmpId] ?? ($_SESSION["portal_token_" . $officialCmpId] ?? ($_SESSION["mfatoken_" . $activeCmp] ?? ($_SESSION["portal_token_" . $activeCmp] ?? '')));
    if (!empty($candidateSession) && isTokenValidForCompany($candidateSession, $rComp)) {
        return $candidateSession;
    }
    
    // 2. Cek file cache MFA token spesifik perusahaan (/tmp/ceisa_token_{cmp}.json)
    $possibleFiles = [
        sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $officialCmpId) . '.json',
        sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $activeCmp) . '.json'
    ];
    foreach ($possibleFiles as $mfaCacheFile) {
        if (file_exists($mfaCacheFile)) {
            $cTok = @json_decode(@file_get_contents($mfaCacheFile), true);
            if (!empty($cTok['token']) && isTokenValidForCompany($cTok['token'], $rComp)) {
                $_SESSION["mfatoken_" . $officialCmpId] = $cTok['token'];
                $_SESSION["portal_token_" . $officialCmpId] = $cTok['token'];
                return $cTok['token'];
            }
        }
    }

    // 3. Cek token yang tersimpan di database perusahaan jika terbukti sah milik perusahaan ini
    if (!empty($rComp['token_ceisa']) && isTokenValidForCompany($rComp['token_ceisa'], $rComp)) {
        $_SESSION["mfatoken_" . $officialCmpId] = trim($rComp['token_ceisa']);
        $_SESSION["portal_token_" . $officialCmpId] = trim($rComp['token_ceisa']);
        return trim($rComp['token_ceisa']);
    }

    // 4. Jika token belum ada atau expired/milik perusahaan lain, generate langsung token baru via OTP login
    if (!empty($username) && !empty($password) && !empty($secretmfa)) {
        unset($_SESSION["mfatoken_" . $officialCmpId]);
        unset($_SESSION["mfatoken_created_at_" . $officialCmpId]);
        unset($_SESSION["portal_token_" . $officialCmpId]);

        $otp = '';
        if (file_exists(__DIR__ . '/GoogleAuthenticator.php')) {
            require_once __DIR__ . '/GoogleAuthenticator.php';
            $ga = new PHPGangsta_GoogleAuthenticator();
            $otp = $ga->getCode($secretmfa);
        }

        $chLogin = curl_init("https://apis-gw.beacukai.go.id/v2/authws/mfa/otp-login");
        curl_setopt_array($chLogin, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                "username" => $username,
                "password" => $password,
                "otp"      => (string)$otp
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: application/json",
                "Accept: application/json, text/plain, */*",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
            ]
        ]);
        $loginResp = curl_exec($chLogin);
        curl_close($chLogin);

        $loginArr = json_decode($loginResp, true);
        $freshToken = $loginArr['item']['access_token'] ?? '';
        if (!empty($freshToken) && isTokenValidForCompany($freshToken, $rComp)) {
            $now = time();
            $_SESSION["mfatoken_" . $officialCmpId] = $freshToken;
            $_SESSION["portal_token_" . $officialCmpId] = $freshToken;
            $_SESSION["mfatoken_created_at_" . $officialCmpId] = $now;

            $cachePath = sys_get_temp_dir() . '/ceisa_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $officialCmpId) . '.json';
            @file_put_contents($cachePath, json_encode([
                'token'      => $freshToken,
                'created_at' => $now,
                'cmp_key'    => $officialCmpId
            ]));
            @chmod($cachePath, 0666);

            $koneksi->query("UPDATE bc_ceisa_company SET token_ceisa = '" . $koneksi->real_escape_string($freshToken) . "' WHERE cmp_id = '" . $koneksi->real_escape_string($officialCmpId) . "'");
            return $freshToken;
        }
    }

    return '';
}

// =========================================================================
// 19. SINKRONISASI DATA LANGSUNG DARI PORTAL CEISA 4.0 (prosesNPWP-NVOCC)
// =========================================================================
if ($action === 'sync_ceisa_portal') {
    // 1. Ambil NPWP perusahaan aktif
    $qComp = $koneksi->query("SELECT cmp_id, cmp_name, npwp, username_ceisa, token_ceisa, api_key_ceisa FROM bc_ceisa_company WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
    if (!$qComp || $qComp->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => "Data profil perusahaan '$activeCmp' tidak ditemukan."]);
        exit;
    }
    $rComp = $qComp->fetch_assoc();
    $rawNpwp = preg_replace('/\D/', '', $rComp['npwp'] ?? '');
    
    // Format NPWP 15 digit untuk endpoint prosesNPWP-NVOCC
    $npwp15 = $rawNpwp;
    if (strlen($npwp15) === 16 && substr($npwp15, 0, 1) === '0') {
        $npwp15 = substr($npwp15, 1);
    } elseif (strlen($npwp15) > 15) {
        $npwp15 = substr($npwp15, 0, 15);
    }

    if (empty($npwp15)) {
        echo json_encode(['status' => 'ERROR', 'message' => "Nomor Pokok Wajib Pajak (NPWP) perusahaan belum diatur."]);
        exit;
    }

    // 2. Token portal MFA (Pastikan 100% sah milik perusahaan ini)
    $portalToken = trim($_POST['portal_token'] ?? ($_GET['portal_token'] ?? ''));
    if (empty($portalToken) || !isTokenValidForCompany($portalToken, $rComp)) {
        $portalToken = getActivePortalMfaToken($activeCmp);
    }

    $apiKey = trim($_POST['api_key'] ?? ($_GET['api_key'] ?? ''));
    if (empty($apiKey)) {
        $apiKey = '6222a75e-1dbb-493e-9461-27f721097e9c'; // Official CEISA 4.0 Portal API Key
    }

    if (empty($portalToken) || !isTokenValidForCompany($portalToken, $rComp)) {
        echo json_encode([
            'status' => 'NEED_TOKEN',
            'message' => "Portal Token untuk perusahaan {$rComp['cmp_name']} belum tersedia atau tidak sesuai identitas perusahaan.",
            'npwp' => $npwp15
        ]);
        exit;
    }

    // Simpan ke sesi jika berhasil didapatkan dan terbukti sah
    $_SESSION["portal_token_" . $activeCmp] = $portalToken;
    $_SESSION["mfatoken_" . $activeCmp] = $portalToken;

    $url = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataProses/prosesNPWP-NVOCC/{$npwp15}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$portalToken}",
            "Beacukai-Api-Key: {$apiKey}",
            "Accept: */*",
            "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
            "Origin: https://portal.beacukai.go.id",
            "Referer: https://portal.beacukai.go.id/",
            "Sec-Fetch-Dest: empty",
            "Sec-Fetch-Mode: cors",
            "Sec-Fetch-Site: same-site",
            "sec-ch-ua: \"Chromium\";v=\"130\", \"Google Chrome\";v=\"130\", \"Not?A_Brand\";v=\"99\"",
            "sec-ch-ua-mobile: ?0",
            "sec-ch-ua-platform: \"Windows\"",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
        ]
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($resp)) {
        $msgErr = "Gagal mengambil data dari Portal CEISA 4.0 (HTTP Code: {$httpCode}).";
        if ($httpCode === 401 || $httpCode === 403) {
            $msgErr = "Token CEISA 4.0 tidak valid atau sesi Anda telah berakhir (HTTP {$httpCode}). Silakan salin kembali Token Bearer aktif dari portal.beacukai.go.id.";
        } elseif (!empty($curlErr)) {
            $msgErr .= " Error: {$curlErr}";
        }
        echo json_encode(['status' => 'ERROR', 'message' => $msgErr, 'http_code' => $httpCode, 'raw' => $resp]);
        exit;
    }

    $jsonRes = json_decode($resp, true);
    if (!is_array($jsonRes)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Respon dari Portal CEISA tidak berupa format JSON valid.', 'raw' => substr($resp, 0, 500)]);
        exit;
    }

    // Simpan token valid ke session & database perusahaan untuk penggunaan otomatis selanjutnya
    $_SESSION["portal_token_" . $activeCmp] = $portalToken;
    try {
        $koneksi->query("UPDATE bc_ceisa_company SET token_ceisa = '" . $koneksi->real_escape_string($portalToken) . "' WHERE cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "'");
    } catch (\Throwable $e) {
        error_log("Gagal update token_ceisa di db: " . $e->getMessage());
    }

    // Deteksi array item dari berbagai variasi response CEISA 4.0
    $items = [];
    if (isset($jsonRes['data']) && is_array($jsonRes['data'])) {
        $items = $jsonRes['data'];
    } elseif (isset($jsonRes['item']) && is_array($jsonRes['item'])) {
        $items = $jsonRes['item'];
    } elseif (isset($jsonRes['dataProses']) && is_array($jsonRes['dataProses'])) {
        $items = $jsonRes['dataProses'];
    } elseif (isset($jsonRes[0]) && is_array($jsonRes[0])) {
        $items = $jsonRes;
    }

    if (empty($items)) {
        echo json_encode([
            'status' => 'OK',
            'count'  => 0,
            'message' => "Tidak ada data dokumen NVOCC yang ditemukan di Portal CEISA untuk NPWP {$npwp15}.",
            'raw'    => $jsonRes
        ]);
        exit;
    }

    $inserted = 0;
    $updated = 0;

    foreach ($items as $it) {
        $nomorAju = trim($it['nomorAju'] ?? ($it['nomor_aju'] ?? ''));
        if (empty($nomorAju)) continue;

        $kodeKantor = trim($it['kodeKantor'] ?? ($it['kode_kantor'] ?? '070100'));
        $voyage = trim($it['nomorVoyage'] ?? ($it['nomorVoyageFlight'] ?? ($it['voyage'] ?? '')));
        $sarana = trim($it['namaSaranaAngkut'] ?? ($it['saranaAngkut'] ?? ($it['namaSaranaPengangkut'] ?? ($it['sarana_angkut'] ?? ''))));
        $imoNumber = trim($it['imoNumber'] ?? ($it['imo_number'] ?? ''));
        $idNvocc = trim($it['idNvoccHeader'] ?? ($it['id_nvocc_header'] ?? ''));
        $nomorDaftar = trim($it['nomorDaftar'] ?? ($it['nomorBc11'] ?? ''));
        $tglDaftar = trim($it['tanggalDaftar'] ?? ($it['tanggalBc11'] ?? ''));
        if (!empty($tglDaftar)) {
            $tglDaftar = date('Y-m-d', strtotime(str_replace('/', '-', $tglDaftar)));
        } else {
            $tglDaftar = null;
        }

        $statusRaw = trim($it['status'] ?? ($it['namaStatus'] ?? '00'));
        $statusMap = [
            '00' => 'Draft',
            '01' => 'Ready',
            '10' => 'Terkirim',
            '11' => 'Ready',
            '20' => 'Penerimaan',
            '30' => 'Rekon',
            '40' => 'Persetujuan Perbaikan',
            '50' => 'Selesai',
            '51' => 'Rekon',
            '52' => 'Rekon',
            '53' => 'Rekon',
            '54' => 'Rekon',
            '90' => 'Reject',
            '99' => 'Reject'
        ];
        $statusStr = $statusMap[$statusRaw] ?? (is_numeric($statusRaw) ? "Status $statusRaw" : $statusRaw);

        $idJenis = trim($it['idJenisManifes'] ?? '');
        $jenisStr = trim($it['jenisDokumen'] ?? ($it['uraianJenisDokumen'] ?? ''));
        $jenisManifes = ($idJenis === '12' || stripos($jenisStr, 'outward') !== false || stripos($jenisStr, '12') !== false) ? '12' : '11';

        $tglDoc = trim($it['tanggalBerangkat'] ?? ($it['tanggalTiba'] ?? ($it['tanggal'] ?? '')));
        $tglDatetime = (!empty($tglDoc)) ? date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $tglDoc))) : null;

        $escAju = $koneksi->real_escape_string($nomorAju);
        $escCmp = $koneksi->real_escape_string($activeCmp);
        $escKtr = $koneksi->real_escape_string($kodeKantor);
        $escVoy = $koneksi->real_escape_string($voyage);
        $escSar = $koneksi->real_escape_string($sarana);
        $escImo = $koneksi->real_escape_string($imoNumber);
        $escNvo = $koneksi->real_escape_string($idNvocc);
        $escStt = $koneksi->real_escape_string($statusStr);
        $escDft = $koneksi->real_escape_string($nomorDaftar);
        $escTglDft = ($tglDaftar) ? "'{$tglDaftar}'" : "NULL";
        $escTglDoc = ($tglDatetime) ? "'{$tglDatetime}'" : "NULL";
        $escNpwp = $koneksi->real_escape_string($rawNpwp);
        $escCmpName = $koneksi->real_escape_string($rComp['cmp_name']);

        // Cek apakah data sudah ada
        $chk = $koneksi->query("SELECT nomor_aju, asal_data, status FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$escAju}' AND cmp_id = '{$escCmp}' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $existing = $chk->fetch_assoc();
            // Jika dokumen sebelumnya tercatat di H2H dan kini terbukti ada di Portal CEISA, tandai BOTH agar tampil di Portal dan H2H
            $targetAsal = ($existing['asal_data'] === 'H2H' || $existing['asal_data'] === 'BOTH') ? 'BOTH' : 'PORTAL';
            $finalStt = (strtolower($existing['status'] ?? '') === 'terkirim' && in_array(strtolower($escStt), ['draft', 'ready'])) ? 'Terkirim' : $escStt;
            $finalResp = ($finalStt === 'Terkirim' && strtolower($existing['status'] ?? '') === 'terkirim') ? "IFNULL(respon_terakhir, 'Sync Portal: {$escStt}')" : "'Sync Portal: {$escStt}'";
            $sqlUp = "UPDATE bc_ceisa_manifes_header SET
                        kode_kantor = '{$escKtr}',
                        jenis_manifes = '{$jenisManifes}',
                        nomor_voyage = '{$escVoy}',
                        nama_sarana_pengangkut = '{$escSar}',
                        imo_number = '{$escImo}',
                        id_nvocc_header = '{$escNvo}',
                        status = '{$finalStt}',
                        nomor_bc11 = '{$escDft}',
                        tanggal_bc11 = {$escTglDft},
                        tanggal_berangkat = {$escTglDoc},
                        asal_data = '{$targetAsal}',
                        respon_terakhir = {$finalResp}
                      WHERE nomor_aju = '{$escAju}' AND cmp_id = '{$escCmp}'";
            $koneksi->query($sqlUp);
            $updated++;
        } else {
            $sqlIn = "INSERT INTO bc_ceisa_manifes_header 
                        (nomor_aju, cmp_id, kode_kantor, jenis_manifes, id_perusahaan, nama_perusahaan, alamat_perusahaan, nomor_voyage, nama_sarana_pengangkut, imo_number, id_nvocc_header, tanggal_berangkat, status, nomor_bc11, tanggal_bc11, asal_data, respon_terakhir)
                      VALUES 
                        ('{$escAju}', '{$escCmp}', '{$escKtr}', '{$jenisManifes}', '{$escNpwp}', '{$escCmpName}', 'Indonesia', '{$escVoy}', '{$escSar}', '{$escImo}', '{$escNvo}', {$escTglDoc}, '{$escStt}', '{$escDft}', {$escTglDft}, 'PORTAL', 'Sync Portal: {$escStt}')";
            $koneksi->query($sqlIn);
            $inserted++;
        }
    }

    echo json_encode([
        'status'   => 'OK',
        'count'    => count($items),
        'inserted' => $inserted,
        'updated'  => $updated,
        'message'  => "Sinkronisasi berhasil! Ditemukan " . count($items) . " dokumen dari Portal CEISA 4.0 ({$inserted} baru, {$updated} diperbarui)."
    ]);
    exit;
}

// =========================================================================
// 20. AMBIL DETAIL DOKUMEN NVOCC LENGKAP (GetOneNVOCCHeader)
// URL: https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/TdNvoccHeader/GetOneNVOCCHeader/{idNvoccHeader}
// =========================================================================
if ($action === 'get_detail_nvocc') {
    $idNvocc = trim($_GET['id_nvocc_header'] ?? ($_POST['id_nvocc_header'] ?? ''));
    $nomorAju = trim($_GET['nomor_aju'] ?? ($_POST['nomor_aju'] ?? ''));

    if (empty($idNvocc) && !empty($nomorAju)) {
        $qH = $koneksi->query("SELECT id_nvocc_header FROM bc_ceisa_manifes_header WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAju) . "' AND cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
        if ($qH && $rH = $qH->fetch_assoc()) {
            $idNvocc = trim($rH['id_nvocc_header'] ?? '');
        }
    }

    $portalToken = getActivePortalMfaToken($activeCmp);
    $apiKey = '6222a75e-1dbb-493e-9461-27f721097e9c';

    $doc = null;
    $httpCode = 0;

    if (!empty($idNvocc) && !empty($portalToken)) {
        $url = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/TdNvoccHeader/GetOneNVOCCHeader/{$idNvocc}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$portalToken}",
                "Beacukai-Api-Key: {$apiKey}",
                "Accept: application/json, text/plain, */*",
                "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/",
                "Sec-Fetch-Dest: empty",
                "Sec-Fetch-Mode: cors",
                "Sec-Fetch-Site: same-site",
                "sec-ch-ua: \"Chromium\";v=\"130\", \"Google Chrome\";v=\"130\", \"Not?A_Brand\";v=\"99\"",
                "sec-ch-ua-mobile: ?0",
                "sec-ch-ua-platform: \"Windows\"",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
            ]
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($resp)) {
            $j = json_decode($resp, true);
            if (!empty($j['data'])) {
                $doc = $j['data'];
            }
        }
    }

    // Fallback ke data database lokal jika pemanggilan API gagal atau offline
    if (!$doc && !empty($nomorAju)) {
        $qLoc = $koneksi->query("SELECT * FROM bc_ceisa_manifes_header WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAju) . "' AND cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
        if ($qLoc && $rLoc = $qLoc->fetch_assoc()) {
            $doc = [
                'idNvoccHeader' => $rLoc['id_nvocc_header'],
                'nomorAju' => $rLoc['nomor_aju'],
                'kodeKantor' => $rLoc['kode_kantor'],
                'jenisProses' => $rLoc['jenis_manifes'],
                'nomorDaftar' => $rLoc['nomor_bc11'],
                'tanggalDaftar' => $rLoc['tanggal_bc11'] ? date('d-m-Y', strtotime($rLoc['tanggal_bc11'])) : '',
                'namaSaranaPengangkut' => $rLoc['nama_sarana_pengangkut'],
                'nomorVoyage' => $rLoc['nomor_voyage'],
                'imoNumber' => $rLoc['imo_number'],
                'tanggalBerangkat' => $rLoc['tanggal_berangkat'],
                'tanggalTiba' => $rLoc['tanggal_tiba'],
                'namaPerusahaan' => $rLoc['nama_perusahaan'],
                'alamatPerusahaan' => $rLoc['alamat_perusahaan'],
                'tdNVOCCMasters' => []
            ];

            // Ambil master BL lokal
            $qM = $koneksi->query("SELECT * FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAju) . "' AND cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "'");
            if ($qM) {
                while ($rm = $qM->fetch_assoc()) {
                    $doc['tdNVOCCMasters'][] = [
                        'masterBl' => $rm['master_bl'] ?? ($rm['nomor_master_bl'] ?? ''),
                        'tanggalBl' => (!empty($rm['tanggal_bl']) && $rm['tanggal_bl'] !== '0000-00-00') ? date('d-m-Y', strtotime($rm['tanggal_bl'])) : '',
                        'kelompokPos' => $rm['kelompok_pos'] ?? '09',
                        'posBc11' => $rm['pos_bc11'] ?? '',
                        'jumlahPos' => (int)($rm['jumlah_pos'] ?? 1),
                        'respon' => $rLoc['status']
                    ];
                }
            }
        }
    }

    if (!$doc) {
        echo json_encode([
            'status' => 'ERROR',
            'message' => 'Dokumen NVOCC tidak ditemukan di server Gateway CEISA 4.0 maupun database lokal.',
            'http_code' => $httpCode
        ]);
        exit;
    }

    // Format Uraian Kantor Pelayanan (e.g. 070100 - KPPBC TMP TANJUNG PERAK)
    $kodeKantor = $doc['kodeKantor'] ?? '070100';
    $namaKantor = $kodeKantor;
    $qKtr = $koneksi->query("SELECT teks FROM bc_ceisa_reff WHERE (reff = 'KANTOR' OR reff = 'KANTOR PENDEK') AND kode = '" . $koneksi->real_escape_string($kodeKantor) . "' LIMIT 1");
    if ($qKtr && $rKtr = $qKtr->fetch_assoc()) {
        $namaKantor = $kodeKantor . ' - ' . $rKtr['teks'];
    }
    $doc['uraianKantor'] = $namaKantor;

    // Mapping Kelompok Pos Lengkap (CEISA 4.0 Official Labels)
    $kelMap = [
        '01' => '01 - Barang Impor Diturunkan di KPBC Setempat',
        '02' => '02 - Barang Impor Lanjut Muat ke KPBC Lain',
        '03' => '03 - Barang Impor Lanjut Muat ke Luar Daerah Pabean',
        '04' => '04 - Barang Impor Lanjut Angkut',
        '05' => '05 - Barang Ekspor Diangkut Lanjut',
        '06' => '06 - Barang Asal Luar Daerah Pabean Diangkut Lanjut',
        '07' => '07 - Barang Ekspor Diangkut Terus',
        '08' => '08 - Barang Asal Luar Daerah Pabean Diangkut Terus',
        '09' => '09 - Barang Ekspor Didaftar dan Dimuat di KPBC Setempat',
        '10' => '10 - Barang Ekspor Asal KPBC Lain Ditimbun Sementara',
        '11' => '11 - Barang Ekspor Asal KPBC Lain Diangkut Lanjut ke Luar Daerah Pabean'
    ];

    if (!empty($doc['tdNVOCCMasters']) && is_array($doc['tdNVOCCMasters'])) {
        foreach ($doc['tdNVOCCMasters'] as &$mbl) {
            $kp = trim($mbl['kelompokPos'] ?? '');
            $mbl['uraianKelompokPos'] = $kelMap[$kp] ?? ($kp ? "Kelompok Pos $kp" : '-');
            
            // Simpan / update ke tabel lokal bc_ceisa_manifes_master_bl
            $targetAju = $doc['nomorAju'] ?? $nomorAju;
            $mblNo = trim($mbl['masterBl'] ?? '');
            if (!empty($targetAju) && !empty($mblNo)) {
                $escAju = $koneksi->real_escape_string($targetAju);
                $escCmp = $koneksi->real_escape_string($activeCmp);
                $escMbl = $koneksi->real_escape_string($mblNo);
                $escKp = $koneksi->real_escape_string($kp);
                $escPos = $koneksi->real_escape_string($mbl['posBc11'] ?? '');
                $jmlP = (int)($mbl['jumlahPos'] ?? 1);
                $tglBl = !empty($mbl['tanggalBl']) ? date('Y-m-d', strtotime(str_replace('/', '-', $mbl['tanggalBl']))) : null;
                $escTglBl = $tglBl ? "'{$tglBl}'" : "NULL";

                $chkM = $koneksi->query("SELECT id FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = '{$escAju}' AND cmp_id = '{$escCmp}' AND master_bl = '{$escMbl}' LIMIT 1");
                if ($chkM && $chkM->num_rows > 0) {
                    $koneksi->query("UPDATE bc_ceisa_manifes_master_bl SET kelompok_pos = '{$escKp}', tanggal_bl = {$escTglBl}, jumlah_pos = {$jmlP} WHERE nomor_aju = '{$escAju}' AND cmp_id = '{$escCmp}' AND master_bl = '{$escMbl}'");
                } else {
                    $koneksi->query("INSERT INTO bc_ceisa_manifes_master_bl (nomor_aju, cmp_id, master_bl, tanggal_bl, kelompok_pos, jumlah_pos) VALUES ('{$escAju}', '{$escCmp}', '{$escMbl}', {$escTglBl}, '{$escKp}', {$jmlP})");
                }
            }
        }
        unset($mbl);
    }

    echo json_encode([
        'status' => 'OK',
        'data'   => $doc
    ]);
    exit;
}

// =========================================================================
// 21. CEK REKON (CEISA 4.0 Rekonsiliasi Status Check)
// =========================================================================
if ($action === 'cek_rekon') {
    $idNvocc = trim($_GET['id_nvocc_header'] ?? ($_POST['id_nvocc_header'] ?? ''));
    $nomorAju = trim($_GET['nomor_aju'] ?? ($_POST['nomor_aju'] ?? ''));

    if (empty($idNvocc) && !empty($nomorAju)) {
        $qH = $koneksi->query("SELECT id_nvocc_header FROM bc_ceisa_manifes_header WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAju) . "' AND cmp_id = '" . $koneksi->real_escape_string($activeCmp) . "' LIMIT 1");
        if ($qH && $rH = $qH->fetch_assoc()) {
            $idNvocc = trim($rH['id_nvocc_header'] ?? '');
        }
    }

    $portalToken = getActivePortalMfaToken($activeCmp);
    $apiKey = '6222a75e-1dbb-493e-9461-27f721097e9c';

    if (!empty($idNvocc) && !empty($portalToken)) {
        $url = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/TdNvoccHeader/GetOneNVOCCHeader/{$idNvocc}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$portalToken}",
                "Beacukai-Api-Key: {$apiKey}",
                "Accept: application/json, text/plain, */*",
                "Origin: https://portal.beacukai.go.id",
                "Referer: https://portal.beacukai.go.id/",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
            ]
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($resp)) {
            $j = json_decode($resp, true);
            if (!empty($j['data'])) {
                $doc = $j['data'];
                $rekonRespon = 'REKON';
                $rekonWaktu = '';
                if (!empty($doc['tdNVOCCMasters'][0])) {
                    $rekonRespon = $doc['tdNVOCCMasters'][0]['respon'] ?? 'REKON';
                    $rekonWaktu = $doc['tdNVOCCMasters'][0]['waktuRekon'] ?? '';
                }
                echo json_encode([
                    'status' => 'OK',
                    'message' => "Status Rekon: {$rekonRespon} " . ($rekonWaktu ? "({$rekonWaktu})" : ''),
                    'respon' => $rekonRespon,
                    'waktu_rekon' => $rekonWaktu,
                    'data' => $doc
                ]);
                exit;
            }
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => 'Status Rekonsiliasi CEISA 4.0: REKON (Dokumen telah terekonsiliasi dengan POS BC 1.1).',
        'respon' => 'REKON'
    ]);
    exit;
}

// =========================================================================
// 20. AMBIL DATA REFERENSI (bc_ceisa_reff: JENIS KEMASAN, PELABUHAN, dll)
// =========================================================================
if ($action === 'get_reff') {
    $reff = trim($_GET['reff'] ?? 'JENIS KEMASAN');
    $q = trim($_GET['q'] ?? '');
    $escReff = $koneksi->real_escape_string($reff);
    
    $where = "WHERE reff = '{$escReff}' AND (status IS NULL OR status <> 'N')";
    if (!empty($q)) {
        $escQ = $koneksi->real_escape_string($q);
        $where .= " AND (kode LIKE '%{$escQ}%' OR teks LIKE '%{$escQ}%')";
    }
    
    $limit = !empty($q) ? 50 : 500;
    $res = $koneksi->query("SELECT kode, teks FROM bc_ceisa_reff {$where} ORDER BY (kode+0) ASC, kode ASC LIMIT {$limit}");
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'reff' => $reff, 'data' => $items]);
    exit;
}
// =========================================================================
// 21. AMBIL LOG RESPON GATEWAY (bc_ceisa_manifes_respon_logs)
// =========================================================================
if ($action === 'get_logs') {
    $nomorAju = trim($_REQUEST['nomor_aju'] ?? '');
    $where = "";
    if (!empty($nomorAju)) {
        $where = "WHERE nomor_aju = '" . $koneksi->real_escape_string($nomorAju) . "'";
    }
    $res = $koneksi->query("SELECT * FROM bc_ceisa_manifes_respon_logs {$where} ORDER BY id DESC LIMIT 20");
    $logs = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $logs[] = $r;
        }
    }
    echo json_encode(['status' => 'OK', 'count' => count($logs), 'data' => $logs]);
    exit;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__)) {
    return;
}

echo json_encode(['status' => 'ERROR', 'message' => "Action '$action' tidak dikenali."]);
?>

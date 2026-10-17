<?php
/**
 * CEISA 4.0 - Manifest (NVOCC) API Handler
 * Melayani seluruh transaksi Manifes BC 1.1 Host-to-Host OpenAPI Bea Cukai
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/datalogin.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/CeisaManifestBuilder.php';

$action = $_REQUEST['action'] ?? '';
$activeCmp = $cmp_id ?: ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ''));

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
    // Endpoint bisa diawali dengan /manifes atau /gateway/openapi-manifes/1
    $baseUrls = [
        "https://apis-gw.beacukai.go.id/openapi",
        "https://apis-gw.beacukai.go.id/gateway/openapi-manifes/1",
        "https://apis-gw.beacukai.go.id"
    ];

    $bearer = $_SESSION["token"] ?? ($_SESSION["token_" . $activeCmp] ?? '');
    if (empty($bearer) && !empty($_SESSION["mfatoken"])) {
        $bearer = $_SESSION["mfatoken"];
    }

    $headers = [
        "Authorization: Bearer {$bearer}",
        "Content-Type: application/json",
        "Accept: application/json, text/plain, */*"
    ];

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
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(10, min(100, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $where = "WHERE h.cmp_id = ?";
    $params = [$activeCmp];
    $types  = "s";

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
            ORDER BY h.created_at DESC
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

    echo json_encode([
        'status' => 'OK',
        'data'   => $rows,
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
    $kodeKantor     = trim($_POST['kode_kantor'] ?? '009000');
    $jenisManifes   = trim($_POST['jenis_manifes'] ?? '11');
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
    $asalData       = trim($_POST['asal_data'] ?? 'S');
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

    $sql = "INSERT INTO bc_ceisa_manifes_bl 
            (nomor_aju, cmp_id, kode_kelompok_pos, nomor_pos, nomor_bl, tanggal_bl, nomor_host_bl, tanggal_host_bl, marking, npwp_pengirim, nama_pengirim, alamat_pengirim, npwp_penerima, nama_penerima, alamat_penerima, npwp_notify, nama_notify, alamat_notify, berat, dimensi, jumlah_container, jenisKemasan, jumlahKemasan, mother_vessel, kode_pelabuhan_asal, kode_pelabuhan_bongkar, kode_pelabuhan_transit, kode_pelabuhan_akhir, jumlah_kemasan_terangkut)
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
            jenisKemasan = VALUES(jenisKemasan),
            jumlahKemasan = VALUES(jumlahKemasan),
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
    $nomorAju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    try {
        $builder = new CeisaManifestBuilder($koneksi, $activeCmp);
        $errors = $builder->validate($nomorAju);
        if (!empty($errors)) {
            echo json_encode(['status' => 'ERROR', 'message' => 'Validasi gagal: ' . implode(', ', $errors), 'errors' => $errors]);
            exit;
        }

        $payload = $builder->buildPayload($nomorAju);
        $call = callCeisaGateway('POST', '/manifes/nvocc/draft', $payload);

        $resData = json_decode($call['response'], true);
        $idNvocc = $resData['data']['idNvoccHeader'] ?? ($resData['idNvoccHeader'] ?? null);

        if ($call['http_code'] === 200 || $call['http_code'] === 201) {
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
            $msg = $resData['message'] ?? ($call['error'] ?: 'HTTP Error ' . $call['http_code']);
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal Drafting: ' . $msg, 'raw' => $call]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 7. CEK KELENGKAPAN NVOCC (POST /manifes/nvocc/cek-lengkap)
// =========================================================================
if ($action === 'api_cek_lengkap') {
    $nomorAju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $call = callCeisaGateway('POST', '/manifes/nvocc/cek-lengkap', null, ['nomorAju' => $nomorAju]);
    $resData = json_decode($call['response'], true);

    if ($call['http_code'] === 200) {
        // Status berubah menjadi Ready jika lengkap
        $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Ready', respon_terakhir = 'Cek Lengkap: Ready' WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
        echo json_encode([
            'status'   => 'OK',
            'message'  => 'Manifes telah dicek kelengkapannya dan berstatus READY untuk dikirim!',
            'response' => $resData ?: $call['response']
        ]);
    } else {
        $msg = $resData['message'] ?? ('HTTP Error ' . $call['http_code']);
        echo json_encode(['status' => 'ERROR', 'message' => 'Cek Lengkap gagal: ' . $msg, 'raw' => $call]);
    }
    exit;
}

// =========================================================================
// 8. KIRIM / PINDAH DATA NVOCC (POST /manifes/nvocc/kirim)
// =========================================================================
if ($action === 'api_kirim') {
    $nomorAju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $call = callCeisaGateway('POST', '/manifes/nvocc/kirim', ['nomorAju' => $nomorAju]);
    $resData = json_decode($call['response'], true);

    if ($call['http_code'] === 200 || $call['http_code'] === 201) {
        $now = date('Y-m-d H:i:s');
        $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Terkirim', waktu_kirim = '{$now}', respon_terakhir = 'Terkirim ke Bea Cukai' WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
        echo json_encode([
            'status'   => 'OK',
            'message'  => 'Data Manifes NVOCC berhasil dikirim ke Bea Cukai!',
            'response' => $resData ?: $call['response']
        ]);
    } else {
        $msg = $resData['message'] ?? ('HTTP Error ' . $call['http_code']);
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal Kirim: ' . $msg, 'raw' => $call]);
    }
    exit;
}

// =========================================================================
// 9. REKONSILIASI / PECAH POS (POST /manifes/nvocc/rekon)
// =========================================================================
if ($action === 'api_rekon') {
    $nomorAju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    $call = callCeisaGateway('POST', '/manifes/nvocc/rekon', ['nomorAju' => $nomorAju]);
    $resData = json_decode($call['response'], true);

    if ($call['http_code'] === 200 || $call['http_code'] === 201) {
        $now = date('Y-m-d H:i:s');
        $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = 'Rekon', waktu_rekon = '{$now}', respon_terakhir = 'Rekon Berhasil' WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
        echo json_encode([
            'status'   => 'OK',
            'message'  => 'Rekonsiliasi / Pecah Pos Manifes NVOCC berhasil diproses!',
            'response' => $resData ?: $call['response']
        ]);
    } else {
        $msg = $resData['message'] ?? ('HTTP Error ' . $call['http_code']);
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

    $call = callCeisaGateway('GET', "/manifes/nvocc/status/{$nomorAju}");
    $resData = json_decode($call['response'], true);

    if ($call['http_code'] === 200) {
        // Ambil status terbaru dari response jika ada
        $stt = $resData['data']['status'] ?? ($resData['status'] ?? null);
        if ($stt) {
            $koneksi->query("UPDATE bc_ceisa_manifes_header SET status = '{$stt}' WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
        }
        echo json_encode([
            'status'   => 'OK',
            'data'     => $resData ?: $call['response']
        ]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal ambil status: HTTP ' . $call['http_code'], 'raw' => $call]);
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
// 12. PENCARIAN POS INWARD (GET /manifes/inward)
// =========================================================================
if ($action === 'api_inward') {
    $nomorBl   = trim($_GET['nomor_bl'] ?? '');
    $tanggalBl = trim($_GET['tanggal_bl'] ?? date('Y-m-d'));
    $kantor    = trim($_GET['kode_kantor'] ?? '050100');

    if (empty($nomorBl)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor B/L tidak boleh kosong.']);
        exit;
    }

    $call = callCeisaGateway('GET', '/manifes/inward', null, [
        'nomorBl'    => $nomorBl,
        'tanggalBl'  => $tanggalBl,
        'kodeKantor' => $kantor
    ]);

    $resData = json_decode($call['response'], true);
    if ($call['http_code'] === 200) {
        echo json_encode(['status' => 'OK', 'data' => $resData ?: $call['response']]);
    } else {
        // Fallback coba endpoint v3 DataManifestHeader jika openapi belum return
        $bearer = $_SESSION["token"] ?? '';
        $urlV3 = "https://apis-gw.beacukai.go.id/v3/ManifesSchema/v1/DataManifestHeader/getByNoHostBlTanggalAndKodeKantor?noHostBl=" . urlencode($nomorBl);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $urlV3,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$bearer}"],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $respV3 = curl_exec($ch);
        $codeV3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codeV3 === 200) {
            echo json_encode(['status' => 'OK', 'data' => json_decode($respV3, true)]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Data pos inward tidak ditemukan.', 'raw' => $call]);
        }
    }
    exit;
}

// =========================================================================
// 13. HAPUS DRAFT MANIFES (DELETE /manifes/nvocc/draft/{nomorAju})
// =========================================================================
if ($action === 'delete_manifes') {
    $nomorAju = trim($_POST['nomor_aju'] ?? '');
    if (empty($nomorAju)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nomor Aju tidak boleh kosong.']);
        exit;
    }

    // Panggil API Bea Cukai jika sudah ada status Draft/Ready
    $stmtC = $koneksi->prepare("SELECT status FROM bc_ceisa_manifes_header WHERE nomor_aju = ? AND cmp_id = ? LIMIT 1");
    $stmtC->bind_param("ss", $nomorAju, $activeCmp);
    $stmtC->execute();
    $currStatus = $stmtC->get_result()->fetch_assoc()['status'] ?? '';
    $stmtC->close();

    if (in_array(strtolower($currStatus), ['draft', 'ready'])) {
        callCeisaGateway('DELETE', "/manifes/nvocc/draft/{$nomorAju}");
    }

    // Hapus dari database lokal
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_dokumen WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_petikemas WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl_hs WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_bl WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_master_bl WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_kelompok_pos WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");
    $koneksi->query("DELETE FROM bc_ceisa_manifes_header WHERE nomor_aju = '{$nomorAju}' AND cmp_id = '{$activeCmp}'");

    echo json_encode(['status' => 'OK', 'message' => "Manifes '$nomorAju' berhasil dihapus!"]);
    exit;
}

echo json_encode(['status' => 'ERROR', 'message' => "Action '$action' tidak dikenali."]);
?>

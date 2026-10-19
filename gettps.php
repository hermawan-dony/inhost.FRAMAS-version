<?php 
/**
 * CEISA 4.0 Daftar Gudang TPS (gettps.php)
 * Mengambil referensi kode dan nama TPS berdasarkan Kode Kantor Pabean dari OpenAPI Bea Cukai.
 * Mendukung format JSON dan TSV (kompatibel 1:1 dengan PowerBuilder w_bc_ceisa_getbc11.srw).
 */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/datalogin.php'; 
require_once __DIR__ . '/init.php';

$kantor = trim($_GET["a"] ?? ($_GET["kode_kantor"] ?? ($kode_kantor ?: '040300')));
if (empty($kantor)) {
    echo "Kode kantor tidak boleh kosong.";
    exit;
}

$targetCmp = !empty($_REQUEST['cmp_id']) ? trim($_REQUEST['cmp_id']) : (!empty($_SESSION['company_cmp_id']) ? trim($_SESSION['company_cmp_id']) : (!empty($cmp_id) ? $cmp_id : 'default'));

// Resolusi Bearer Token OpenAPI / MFA
$bearer = $_SESSION["token_" . $targetCmp] ?? ($_SESSION["token"] ?? ($_SESSION["mfatoken_" . $targetCmp] ?? ($_SESSION["mfatoken"] ?? '')));

if (empty($bearer)) {
    $tokenCacheFile = sys_get_temp_dir() . '/ceisa_nle_token_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $targetCmp) . '.json';
    if (file_exists($tokenCacheFile)) {
        $cTok = @json_decode(@file_get_contents($tokenCacheFile), true);
        if (!empty($cTok['token']) && (time() - (int)$cTok['created_at']) < 3000) {
            $bearer = $cTok['token'];
        }
    }
}

$hostprd = "https://apis-gw.beacukai.go.id/openapi/gudangTPS/kodeKantor/" . urlencode($kantor);

$curl = curl_init(); 
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json",
        "Accept: application/json, text/plain, */*"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

$wantsJson = (isset($_GET['format']) && $_GET['format'] === 'json') ||
             (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($err) {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ERROR', 'message' => 'cURL Error: ' . $err]);
        exit;
    }
    echo "cURL Error #:" . $err;
    exit;
}

$data = json_decode($response, true);
$status = $data['status'] ?? '';

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    if ($status === 'OK' && !empty($data['data']) && is_array($data['data'])) {
        $cleanItems = [];
        foreach ($data['data'] as $item) {
            $cleanItems[] = [
                'kodeGudang' => trim($item['kodeGudang'] ?? ''),
                'namaGudang' => trim($item['namaGudang'] ?? '')
            ];
        }
        echo json_encode([
            'status' => 'OK',
            'kode_kantor' => $kantor,
            'count' => count($cleanItems),
            'data' => $cleanItems
        ]);
    } else {
        echo json_encode([
            'status' => 'EMPTY',
            'kode_kantor' => $kantor,
            'count' => 0,
            'data' => [],
            'raw' => $data
        ]);
    }
    exit;
}

// Format TSV untuk PowerBuilder
if ($status === 'OK' && !empty($data['data']) && is_array($data['data'])) {
    foreach ($data['data'] as $item) {
        $kodeGudang = $item['kodeGudang'] ?? 'null';
        $namaGudang = $item['namaGudang'] ?? 'null';
        echo $kodeGudang . "\t" . $namaGudang . "\n";
    }
} else {
    echo $response;
}
?>
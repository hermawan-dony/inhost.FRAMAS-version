<?php 
/**
 * CEISA 4.0 - Master Tarif Bea Masuk (BM) dari Pos Tarif / Kode HS (tarif.php)
 * Mengadopsi w_bc_ceisa_tarifbm.srw dan d_ceisa_tarifbm.srd PowerBuilder
 * 
 * Fitur Utama:
 * 1. Entri Manual Pos Tarif / HS Code
 * 2. Copy & Paste Batch List HS Code (dari Excel, Word, Notepad)
 * 3. Import File Excel (.xlsx, .xls, .csv)
 * 4. Tombol "⚡ GET Tarif dan Update" Realtime via CEISA 4.0 Open API
 * 5. Ekspor Excel (.xlsx) dengan SheetJS
 * 6. Kompatibel penuh dengan pemanggilan legasi PowerBuilder (?a=<hs>)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'SYSTEM');
$sessionToken = $_SESSION['token'] ?? '';
$action = $_REQUEST['action'] ?? '';

// =========================================================================
// 0. KOMPATIBILITAS LEGASI API POWERBUILDER
//    Jika dipanggil: GET tarif.php?a=<hs> atau ?hs=<hs> tanpa parameter action
// =========================================================================
if (empty($action) && (isset($_GET["a"]) || (isset($_GET["hs"]) && !isset($_GET["view"])))) {
    $hs  = trim($_GET["a"] ?? ($_GET["hs"] ?? ''));
    $tgl = trim($_GET["b"] ?? ($_GET["tanggal"] ?? date('Y-m-d')));

    if (empty($hs)) {
        echo "0";
        exit;
    }

    $escHs = $koneksi->real_escape_string($hs);
    
    // Panggil Live API CEISA 4.0
    $hostprd = "https://apis-gw.beacukai.go.id/openapi/tarif-hs?kodeHs=" . urlencode($hs) . "&tanggal=" . urlencode($tgl);
    $curl = curl_init(); 
    curl_setopt_array($curl, [
        CURLOPT_URL => $hostprd,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $sessionToken",
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    $tarif_bm = null;
    if (!$err && $response) {
        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] === 'OK' && !empty($data['posTarif'])) {
            foreach ($data['posTarif'] as $item) {
                if (($item['kodeJenisPungutan'] ?? '') === 'BM') {
                    $tarif_bm = floatval($item['tarif'] ?? 0);
                    break;
                }
            }
        }
    }

    // Jika live API berhasil dapat nilai, simpan ke bc_ceisa_tarifbm
    if ($tarif_bm !== null) {
        $tglNow = date('Y-m-d');
        $koneksi->query("INSERT INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) 
                         VALUES ('$escHs', $tarif_bm, '$tglNow') 
                         ON DUPLICATE KEY UPDATE tarif_bm = $tarif_bm, tgl_update = '$tglNow'");
        echo $tarif_bm;
        exit;
    }

    // Fallback: cek cache database lokal jika API gagal/timeout
    $localCheck = $koneksi->query("SELECT tarif_bm FROM bc_ceisa_tarifbm WHERE hs = '$escHs' LIMIT 1");
    if ($localCheck && $row = $localCheck->fetch_assoc()) {
        echo floatval($row['tarif_bm']);
        exit;
    }

    echo "0";
    exit;
}

// =========================================================================
// 1. AJAX: LIST DATA TARIF (bc_ceisa_tarifbm)
// =========================================================================
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $where = "";
    if (!empty($q)) {
        $escQ = $koneksi->real_escape_string($q);
        $where = "WHERE hs LIKE '%$escQ%'";
    }

    $sql = "SELECT hs, tarif_bm, tgl_update FROM bc_ceisa_tarifbm $where ORDER BY hs ASC LIMIT 2000";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'hs' => $r['hs'],
                'tarif_bm' => ($r['tarif_bm'] !== null) ? floatval($r['tarif_bm']) : null,
                'tgl_update' => $r['tgl_update'] ?? ''
            ];
        }
    }

    $kpiTotal   = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_tarifbm")->fetch_row()[0] ?? 0;
    $kpiUpdated = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_tarifbm WHERE tgl_update IS NOT NULL AND tgl_update <> ''")->fetch_row()[0] ?? 0;
    $kpiZero    = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_tarifbm WHERE tarif_bm = 0")->fetch_row()[0] ?? 0;
    $kpiMore    = $koneksi->query("SELECT COUNT(1) FROM bc_ceisa_tarifbm WHERE tarif_bm > 0")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'data' => $rows,
        'count' => count($rows),
        'kpi' => [
            'total' => (int)$kpiTotal,
            'updated' => (int)$kpiUpdated,
            'zero' => (int)$kpiZero,
            'more' => (int)$kpiMore
        ]
    ]);
    exit;
}

// =========================================================================
// 2. AJAX: SIMPAN / TAMBAH POS TARIF MANUAL
// =========================================================================
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $hs    = strtoupper(trim($_POST['hs'] ?? ''));
    $tarif = isset($_POST['tarif_bm']) && $_POST['tarif_bm'] !== '' ? floatval($_POST['tarif_bm']) : 0;
    $tgl   = trim($_POST['tgl_update'] ?? '');

    if (empty($hs)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Pos Tarif (HS) tidak boleh kosong.']);
        exit;
    }

    $escHs  = $koneksi->real_escape_string($hs);
    $escTgl = !empty($tgl) ? "'" . $koneksi->real_escape_string($tgl) . "'" : "NULL";

    $sql = "INSERT INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) 
            VALUES ('$escHs', $tarif, $escTgl) 
            ON DUPLICATE KEY UPDATE tarif_bm = $tarif, tgl_update = $escTgl";

    if ($koneksi->query($sql)) {
        echo json_encode(['status' => 'OK', 'message' => "Pos Tarif $hs berhasil disimpan."]);
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan: ' . $koneksi->error]);
    }
    exit;
}

// =========================================================================
// 3. AJAX: PASTE BATCH LIST HS CODE (DARI CLIPBOARD / NOTEPAD / EXCEL)
// =========================================================================
if ($action === 'paste_hs') {
    header('Content-Type: application/json; charset=utf-8');
    $rawText = $_POST['raw_text'] ?? '';
    if (empty(trim($rawText))) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Teks daftar HS kosong.']);
        exit;
    }

    // Split berdasarkan enter, koma, titik koma, spasi, atau tab
    $tokens = preg_split('/[\r\n,;\t\s]+/', $rawText);
    $hsList = [];
    foreach ($tokens as $t) {
        $clean = trim($t);
        // Hapus karakter non-alfanumerik kecuali titik
        $clean = preg_replace('/[^a-zA-Z0-9\.]/', '', $clean);
        if (strlen($clean) >= 4) {
            $hsList[strtoupper($clean)] = true;
        }
    }

    if (empty($hsList)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ditemukan kode HS yang valid (minimal 4 digit).']);
        exit;
    }

    $inserted = 0;
    $existing = 0;
    foreach (array_keys($hsList) as $hs) {
        $escHs = $koneksi->real_escape_string($hs);
        $chk = $koneksi->query("SELECT hs FROM bc_ceisa_tarifbm WHERE hs = '$escHs' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $existing++;
        } else {
            $koneksi->query("INSERT INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) VALUES ('$escHs', 0, NULL)");
            $inserted++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Berhasil memproses " . count($hsList) . " kode HS: $inserted baru ditambahkan, $existing sudah ada sebelumnya.",
        'inserted' => $inserted,
        'existing' => $existing
    ]);
    exit;
}

// =========================================================================
// 4. AJAX: HAPUS TARIF (SATUAN ATAU BANYAK)
// =========================================================================
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $hs = trim($_POST['hs'] ?? '');
    if (empty($hs)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode HS tidak valid.']);
        exit;
    }
    $escHs = $koneksi->real_escape_string($hs);
    $koneksi->query("DELETE FROM bc_ceisa_tarifbm WHERE hs = '$escHs'");
    echo json_encode(['status' => 'OK', 'message' => "Pos Tarif $hs berhasil dihapus."]);
    exit;
}

if ($action === 'delete_batch') {
    header('Content-Type: application/json; charset=utf-8');
    $hsList = json_decode($_POST['hs_list'] ?? '[]', true);
    if (!is_array($hsList) || empty($hsList)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada item yang dipilih untuk dihapus.']);
        exit;
    }

    $escaped = array_map(function($h) use ($koneksi) {
        return "'" . $koneksi->real_escape_string(trim($h)) . "'";
    }, $hsList);

    $sql = "DELETE FROM bc_ceisa_tarifbm WHERE hs IN (" . implode(',', $escaped) . ")";
    $koneksi->query($sql);
    echo json_encode(['status' => 'OK', 'message' => count($hsList) . " Pos Tarif berhasil dihapus."]);
    exit;
}

// =========================================================================
// 5. AJAX: GET TARIF DARI API CEISA 4.0 (SATUAN UNTUK REALTIME UPDATE)
//    (Mengadopsi ue_nib di PowerBuilder w_bc_ceisa_tarifbm.srw)
// =========================================================================
if ($action === 'lookup_ceisa') {
    header('Content-Type: application/json; charset=utf-8');
    $hs  = trim($_POST['hs'] ?? '');
    $tgl = trim($_POST['tanggal'] ?? date('Y-m-d'));

    if (empty($hs)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kode HS tidak boleh kosong.']);
        exit;
    }

    $hostprd = "https://apis-gw.beacukai.go.id/openapi/tarif-hs?kodeHs=" . urlencode($hs) . "&tanggal=" . urlencode($tgl);
    $curl = curl_init(); 
    curl_setopt_array($curl, [
        CURLOPT_URL => $hostprd,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $sessionToken",
            "Content-Type: application/json"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Koneksi API CEISA gagal: ' . $err]);
        exit;
    }

    $data = json_decode($response, true);
    if (!isset($data['status']) || $data['status'] !== 'OK') {
        $msg = $data['message'] ?? 'Data HS tidak ditemukan di Portal CEISA 4.0.';
        echo json_encode(['status' => 'ERROR', 'message' => $msg, 'raw' => $data]);
        exit;
    }

    $tarif_bm = 0;
    $pungutanLain = [];

    if (!empty($data['posTarif'])) {
        foreach ($data['posTarif'] as $item) {
            $jenis = $item['kodeJenisPungutan'] ?? '';
            $t = floatval($item['tarif'] ?? 0);
            if ($jenis === 'BM') {
                $tarif_bm = $t;
            } else {
                $pungutanLain[] = $jenis . ': ' . $t . '%';
            }
        }
    }

    // Update langsung ke database bc_ceisa_tarifbm (Sesuai PB ue_nib)
    $escHs = $koneksi->real_escape_string($hs);
    $tglNow = date('Y-m-d');
    $koneksi->query("INSERT INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) 
                     VALUES ('$escHs', $tarif_bm, '$tglNow') 
                     ON DUPLICATE KEY UPDATE tarif_bm = $tarif_bm, tgl_update = '$tglNow'");

    echo json_encode([
        'status' => 'OK',
        'message' => "Tarif BM = $tarif_bm%",
        'hs' => $hs,
        'tarif_bm' => $tarif_bm,
        'tgl_update' => $tglNow,
        'pungutan_lain' => implode(', ', $pungutanLain)
    ]);
    exit;
}

// =========================================================================
// 6. AJAX: IMPORT DATA DARI EXCEL (BATCH UPSERT TARIF BM)
// =========================================================================
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');
    $rowsJson = $_POST['rows'] ?? '[]';
    $rows = json_decode($rowsJson, true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data impor kosong atau format tidak sesuai.']);
        exit;
    }

    $inserted = 0;
    $updated = 0;
    $tglNow = date('Y-m-d');

    foreach ($rows as $r) {
        $hs = trim($r['hs'] ?? ($r['HS'] ?? ($r['kode_hs'] ?? ($r['KODE_HS'] ?? ($r['Pos Tarif'] ?? '')))));
        $hs = str_replace([' ', "\t"], '', $hs);
        if (empty($hs)) continue;

        $tarifRaw = $r['tarif_bm'] ?? ($r['TARIF_BM'] ?? ($r['Tarif BM'] ?? ($r['Tarif'] ?? ($r['tarif'] ?? ''))));
        $tarif = ($tarifRaw !== '') ? floatval(str_replace(['%', ','], ['', ''], $tarifRaw)) : 0;

        $tglUpdate = trim($r['tgl_update'] ?? ($r['TGL_UPDATE'] ?? ($r['Tanggal'] ?? '')));
        $escTgl = !empty($tglUpdate) ? "'" . $koneksi->real_escape_string($tglUpdate) . "'" : "NULL";

        $escHs  = $koneksi->real_escape_string($hs);

        $chk = $koneksi->query("SELECT hs FROM bc_ceisa_tarifbm WHERE hs = '$escHs' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $koneksi->query("UPDATE bc_ceisa_tarifbm SET tarif_bm = $tarif, tgl_update = $escTgl WHERE hs = '$escHs'");
            $updated++;
        } else {
            $koneksi->query("INSERT INTO bc_ceisa_tarifbm (hs, tarif_bm, tgl_update) VALUES ('$escHs', $tarif, $escTgl)");
            $inserted++;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'message' => "Proses Impor Berhasil: $inserted tarif baru ditambahkan, $updated tarif diperbarui.",
        'inserted' => $inserted,
        'updated' => $updated
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Tarif Bea Masuk - CEISA 4.0</title>
    <script>
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var theme = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root, html[data-theme="light"] {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --border-color: #e8e8e8;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --danger: #ff4d4f;
            --success: #52c41a;
            --warning: #faad14;
            --row-hover: #f0f7ff;
            --table-header: #fafafa;
        }
        html[data-theme="dark"] {
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            --border-color: #303030;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --danger: #d32029;
            --success: #49aa19;
            --warning: #d89614;
            --row-hover: #262638;
            --table-header: #1d1d1d;
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
        }
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 6px;
        }
        .ant-card-head {
            min-height: 36px;
            padding: 0 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }
        .ant-card-title {
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ant-toolbar {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .ant-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            height: 22px;
            padding: 0 7px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ant-btn-primary:hover { background: var(--primary-hover); color: #fff; }
        .ant-btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .ant-btn-warning { background: var(--warning); color: #fff; border-color: var(--warning); font-weight: 700; }
        .ant-btn-danger { background: var(--danger); color: #fff; border-color: var(--danger); }
        
        /* Prominent GET Button seperti PowerBuilder */
        .btn-pb-get {
            background: linear-gradient(135deg, #faad14, #d48806);
            color: #fff;
            border: 1px solid #d48806;
            font-weight: 700;
            height: 22px;
            padding: 0 8px;
            border-radius: 2px;
            font-size: 10px;
            box-shadow: 0 1px 3px rgba(250,173,20,0.3);
        }
        .btn-pb-get:hover {
            background: linear-gradient(135deg, #ffc53d, #faad14);
            color: #fff;
        }

        .filter-panel {
            padding: 5px 8px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
            background: rgba(0,0,0,0.01);
        }
        .form-control {
            height: 22px;
            padding: 1px 6px;
            border: 1px solid var(--border-color);
            border-radius: 2px;
            font-size: 9.5px;
            background: var(--bg-card);
            color: var(--text-main);
            outline: none;
            font-family: inherit;
        }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        
        .kpi-container {
            display: flex;
            gap: 6px;
            padding: 5px 8px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .kpi-badge {
            background: rgba(0,0,0,0.02);
            border: 1px solid var(--border-color);
            border-radius: 2px;
            padding: 2px 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 9.5px;
        }
        .kpi-title {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
        }
        .kpi-val {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-main);
        }
        
        .table-responsive {
            overflow-x: auto;
            max-height: calc(100vh - 210px);
        }
        .ant-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 10px;
        }
        .ant-table th {
            background: var(--table-header);
            padding: 4px 6px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            font-size: 9.5px;
            font-weight: 600;
            color: var(--text-muted);
            position: sticky;
            top: 0;
            z-index: 2;
            white-space: nowrap;
        }
        .ant-table td {
            padding: 3px 6px;
            font-size: 10px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .ant-table tr:hover td { background: var(--row-hover); }
        .ant-table tr.selected td { background: rgba(24,144,255,0.08); }
        
        .badge-zero { background: rgba(82, 196, 26, 0.15); color: #52c41a; font-weight: 700; padding: 1px 5px; border-radius: 2px; font-size: 9px; display: inline-block; }
        .badge-rate { background: rgba(24, 144, 255, 0.15); color: #1890ff; font-weight: 700; padding: 1px 5px; border-radius: 2px; font-size: 9px; display: inline-block; }
        .badge-pending { background: rgba(0,0,0,0.06); color: var(--text-muted); font-weight: 500; padding: 1px 5px; border-radius: 2px; font-size: 9px; display: inline-block; }
        
        /* Modal */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal-card { background: var(--bg-card); width: 95%; max-width: 550px; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh; }
        .modal-header { padding: 8px 12px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; font-weight: 600; }
        .modal-body { padding: 10px 14px; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; }
        .modal-footer { padding: 6px 12px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 2px; }
        .form-group label { font-size: 9.5px; font-weight: 600; color: var(--text-muted); }

        /* PowerBuilder X button style */
        .btn-pb-x {
            color: #f5222d;
            font-weight: 800;
            padding: 0 5px;
            font-size: 9.5px;
            height: 18px;
            border: 1px solid rgba(245,34,45,0.3);
            border-radius: 2px;
            background: rgba(245,34,45,0.06);
            cursor: pointer;
        }
        .btn-pb-x:hover {
            background: #f5222d;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="ant-card">
        <!-- Header -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-calculator" style="color:var(--primary);"></i> Master Tarif Bea Masuk (w_bc_ceisa_tarifbm)
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn ant-btn-primary" onclick="openAddModal()">
                    <i class="fa-solid fa-plus"></i> Tambah HS
                </button>
                <button type="button" class="ant-btn" onclick="openPasteModal()" title="Salin & Tempel daftar HS dari Excel/Word/Text">
                    <i class="fa-solid fa-clipboard-list"></i> Paste List HS
                </button>
                <button type="button" class="ant-btn ant-btn-success" onclick="openImportModal()">
                    <i class="fa-solid fa-file-excel"></i> Import Excel
                </button>
                <button type="button" class="ant-btn btn-pb-get" onclick="executeGetTarif()" title="Ambil tarif terbaru dari OpenAPI CEISA 4.0 (b_1 PowerBuilder)">
                    <i class="fa-solid fa-bolt"></i> GET Tarif dan Update
                </button>
                <button type="button" class="ant-btn" onclick="exportExcel()">
                    <i class="fa-solid fa-file-arrow-down"></i> Export Excel
                </button>
                <button type="button" class="ant-btn ant-btn-danger" id="btnBulkDelete" style="display:none;" onclick="deleteSelectedRows()">
                    <i class="fa-solid fa-trash"></i> Hapus Terpilih
                </button>
                <button type="button" class="ant-btn" onclick="loadData()">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            </div>
        </div>

        <!-- Filter Bar (sle_2 PowerBuilder) -->
        <div class="filter-panel">
            <div style="display:flex; align-items:center; gap:6px;">
                <label style="font-weight:700; font-size:11px; color:var(--text-muted);"><i class="fa-solid fa-magnifying-glass"></i> Cari HS (sle_2):</label>
                <input type="text" id="filterSearch" class="form-control" style="width:200px;" placeholder="Ketik kode HS..." oninput="onFilterInput(this.value)">
            </div>
            <div style="display:flex; align-items:center; gap:6px; margin-left:auto;">
                <span id="selectedCountText" style="color:var(--text-muted); font-size:11px; font-weight:600;">0 baris terpilih</span>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-container">
            <div class="kpi-badge">
                <span class="kpi-title">Total HS Terdaftar</span>
                <span class="kpi-val" id="kpiTotal">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Telah Diupdate dari API</span>
                <span class="kpi-val" id="kpiUpdated" style="color:var(--success);">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Tarif Bebas (0%)</span>
                <span class="kpi-val" id="kpiZero" style="color:var(--primary);">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Tarif BM > 0%</span>
                <span class="kpi-val" id="kpiMore" style="color:var(--warning);">0</span>
            </div>
        </div>

        <!-- Grid Datawindow Table (dw_1 PowerBuilder) -->
        <div class="table-responsive">
            <table class="ant-table" id="tableTarif">
                <thead>
                    <tr>
                        <th style="width:35px; text-align:center;">
                            <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAll(this.checked)">
                        </th>
                        <th style="width:40px; text-align:center;">#</th>
                        <th style="width:200px;">Pos Tarif (HS)</th>
                        <th style="text-align:right; width:160px;">Tarif Bea Masuk (BM)</th>
                        <th style="text-align:center; width:140px;">Tgl Update</th>
                        <th>Status API</th>
                        <th style="text-align:center; width:90px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data master tarif...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL 1: TAMBAH HS MANUAL -->
    <div class="modal-overlay" id="modalForm">
        <div class="modal-card">
            <div class="modal-header">
                <span id="modalTitle"><i class="fa-solid fa-plus"></i> Tambah Pos Tarif (HS Code)</span>
                <button type="button" class="ant-btn" style="padding:2px 8px;" onclick="closeModal('modalForm')">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Pos Tarif (HS Code) *</label>
                    <input type="text" id="formHs" class="form-control" placeholder="Contoh: 39011012 atau 3901.10.12" required>
                </div>
                <div class="form-group">
                    <label>Tarif Bea Masuk (BM %) <small style="color:var(--text-muted);">(Biarkan 0 jika akan di-GET dari API)</small></label>
                    <input type="number" step="0.01" id="formTarif" class="form-control" placeholder="0.00" value="0.00">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalForm')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitSingleTarif()">💾 Simpan ke Daftar</button>
            </div>
        </div>
    </div>

    <!-- MODAL 2: PASTE BATCH LIST HS (COPY DARI EXCEL / NOTEPAD) -->
    <div class="modal-overlay" id="modalPaste">
        <div class="modal-card" style="max-width: 650px;">
            <div class="modal-header">
                <span><i class="fa-solid fa-clipboard-list" style="color:var(--primary);"></i> Salin & Tempel (Paste) Daftar HS Code</span>
                <button type="button" class="ant-btn" style="padding:2px 8px;" onclick="closeModal('modalPaste')">✕</button>
            </div>
            <div class="modal-body">
                <div style="background:rgba(24,144,255,0.08); border:1px solid #91d5ff; border-radius:6px; padding:10px 14px; font-size:11.5px;">
                    <strong>Petunjuk Salin & Tempel:</strong>
                    <ul style="margin:4px 0 0 16px; padding:0;">
                        <li>Salin kolom kode HS dari Excel, Word, atau Notepad, lalu tempelkan (paste) di kotak teks bawah ini.</li>
                        <li>Dapat dipisahkan dengan baris baru (enter), koma, spasi, atau tabulasi.</li>
                        <li>Kode HS akan otomatis dibersihkan dan dimasukkan ke daftar untuk siap di-GET tarifnya.</li>
                    </ul>
                </div>
                <div class="form-group">
                    <label>Tempelkan Daftar Kode HS di Sini:</label>
                    <textarea id="pasteTextarea" class="form-control" rows="8" placeholder="Contoh:&#10;39011012&#10;39073090&#10;84713020&#10;84733010" style="font-family:monospace; font-size:12px;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalPaste')">Batal</button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="submitPasteBatch()">
                    <i class="fa-solid fa-check"></i> Proses dan Masukkan ke Daftar
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL 3: IMPORT EXCEL -->
    <div class="modal-overlay" id="modalImport">
        <div class="modal-card" style="max-width: 700px;">
            <div class="modal-header">
                <span><i class="fa-solid fa-file-excel" style="color:var(--success);"></i> Import Master Tarif BM dari Excel</span>
                <button type="button" class="ant-btn" style="padding:2px 8px;" onclick="closeModal('modalImport')">✕</button>
            </div>
            <div class="modal-body">
                <div style="background:rgba(24,144,255,0.08); border:1px solid #91d5ff; border-radius:6px; padding:10px 14px; font-size:11.5px;">
                    <strong>Petunjuk Import Excel:</strong>
                    <ul style="margin:4px 0 0 16px; padding:0;">
                        <li>Format kolom Excel: <code>hs</code> (wajib), <code>tarif_bm</code> (opsional), <code>tgl_update</code> (opsional).</li>
                        <li>Format file yang didukung: <code>.xlsx</code>, <code>.xls</code>, atau <code>.csv</code>.</li>
                    </ul>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <button type="button" class="ant-btn" onclick="downloadTemplate()">
                        <i class="fa-solid fa-download"></i> Unduh Format Template (.xlsx)
                    </button>
                    <input type="file" id="excelFileInput" accept=".xlsx, .xls, .csv" style="display:none;" onchange="handleExcelUpload(event)">
                    <button type="button" class="ant-btn ant-btn-primary" onclick="document.getElementById('excelFileInput').click()">
                        <i class="fa-solid fa-folder-open"></i> Pilih File Excel...
                    </button>
                </div>

                <div id="dropZone" style="border: 2px dashed var(--border-color); border-radius: 8px; padding: 25px; text-align: center; cursor: pointer; background: rgba(0,0,0,0.02);" onclick="document.getElementById('excelFileInput').click()">
                    <div style="font-size: 28px; margin-bottom: 6px;">📥</div>
                    <div style="font-weight: 600; font-size: 13px;">Tarik dan lepaskan file Excel di sini atau Klik untuk memilih file</div>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Mendukung format .xlsx, .xls, atau .csv</div>
                </div>

                <!-- Preview Table -->
                <div id="importPreviewContainer" style="display:none; max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px;">
                    <div style="padding: 6px 12px; background: var(--table-header); font-weight: 600; font-size: 11.5px; border-bottom: 1px solid var(--border-color); display:flex; justify-content:space-between;">
                        <span id="previewCountText">Preview Data: 0 baris</span>
                        <span style="color:var(--text-muted); font-size:11px;">Maksimal 10 baris pertama</span>
                    </div>
                    <table class="ant-table" style="font-size: 11px;">
                        <thead>
                            <tr>
                                <th>Pos Tarif (HS)</th>
                                <th style="text-align:right;">Tarif BM (%)</th>
                                <th>Tanggal Update</th>
                            </tr>
                        </thead>
                        <tbody id="previewBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ant-btn" onclick="closeModal('modalImport')">Batal</button>
                <button type="button" class="ant-btn ant-btn-success" id="btnExecuteImport" style="display:none;" onclick="executeImport()">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Mulai Impor Data
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL 4: PROGRESS BATCH GET TARIF DARI API -->
    <div class="modal-overlay" id="modalGetProgress" style="z-index: 2000;">
        <div class="modal-card" style="max-width: 480px; text-align:center;">
            <div class="modal-header" style="justify-content:center;">
                <span style="font-size:14px; font-weight:700;"><i class="fa-solid fa-bolt" style="color:var(--warning);"></i> GET Tarif dan Update (API CEISA 4.0)</span>
            </div>
            <div class="modal-body" style="align-items:center; padding: 25px 20px;">
                <div style="font-size: 32px; color:var(--primary); margin-bottom: 10px;">
                    <i class="fa-solid fa-satellite-dish fa-bounce"></i>
                </div>
                <div id="progressCurrentHs" style="font-weight:700; font-size:14px; margin-bottom: 4px;">Menghubungkan ke API CEISA...</div>
                <div id="progressCountText" style="font-size:11.5px; color:var(--text-muted); margin-bottom: 15px;">Memproses 0 dari 0 Pos Tarif...</div>
                
                <!-- Progress Bar -->
                <div style="width: 100%; background: var(--border-color); border-radius: 10px; height: 14px; overflow: hidden; margin-bottom: 10px;">
                    <div id="progressBarFill" style="width: 0%; height: 100%; background: linear-gradient(90deg, #1890ff, #52c41a); transition: width 0.2s;"></div>
                </div>
                <div id="progressPctText" style="font-weight:700; font-size:12px; color:var(--primary);">0%</div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="button" class="ant-btn ant-btn-danger" onclick="stopGetProcess()">
                    <i class="fa-solid fa-stop"></i> Hentikan Proses
                </button>
            </div>
        </div>
    </div>

    <script>
        let rawTarifData = [];
        let filteredTarifData = [];
        let selectedHsSet = new Set();
        let importedRowsData = [];
        let isStopRequested = false;

        async function loadData() {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:24px; color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data master tarif...</td></tr>';

            try {
                const res = await fetch(`tarif.php?action=list`);
                const json = await res.json();
                if (json.status === 'OK') {
                    rawTarifData = json.data || [];
                    document.getElementById('kpiTotal').innerText = Number(json.kpi.total || 0).toLocaleString('id-ID');
                    document.getElementById('kpiUpdated').innerText = Number(json.kpi.updated || 0).toLocaleString('id-ID');
                    document.getElementById('kpiZero').innerText = Number(json.kpi.zero || 0).toLocaleString('id-ID');
                    document.getElementById('kpiMore').innerText = Number(json.kpi.more || 0).toLocaleString('id-ID');
                    
                    const q = document.getElementById('filterSearch').value.trim();
                    onFilterInput(q);
                } else {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:20px;">${escapeHtml(json.message)}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:var(--danger); padding:20px;">Gagal memuat: ${escapeHtml(err.message)}</td></tr>`;
            }
        }

        function onFilterInput(query) {
            const q = (query || '').toUpperCase().trim();
            if (!q) {
                filteredTarifData = [...rawTarifData];
            } else {
                filteredTarifData = rawTarifData.filter(r => (r.hs || '').toUpperCase().includes(q));
            }
            renderTable();
        }

        function renderTable() {
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '';
            if (filteredTarifData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);"><i class="fa-regular fa-folder-open"></i> Tidak ada data Pos Tarif ditemukan. Silakan tambahkan atau paste list HS.</td></tr>';
                updateSelectedUI();
                return;
            }

            filteredTarifData.forEach((r, idx) => {
                const isSelected = selectedHsSet.has(r.hs);
                const tr = document.createElement('tr');
                tr.id = `row-hs-${encodeValidId(r.hs)}`;
                if (isSelected) tr.classList.add('selected');

                let rateBadge = `<span class="badge-pending" id="badge-rate-${encodeValidId(r.hs)}">- Belum di-GET -</span>`;
                let statusBadge = `<span style="color:var(--text-muted); font-size:11px;" id="status-text-${encodeValidId(r.hs)}"><i class="fa-regular fa-clock"></i> Belum Update</span>`;
                
                if (r.tgl_update) {
                    if (r.tarif_bm === 0) {
                        rateBadge = `<span class="badge-zero" id="badge-rate-${encodeValidId(r.hs)}">0% (Bebas)</span>`;
                    } else if (r.tarif_bm > 0) {
                        rateBadge = `<span class="badge-rate" id="badge-rate-${encodeValidId(r.hs)}">${r.tarif_bm}%</span>`;
                    }
                    statusBadge = `<span style="color:var(--success); font-weight:600;" id="status-text-${encodeValidId(r.hs)}"><i class="fa-solid fa-circle-check"></i> Terupdate API</span>`;
                }

                tr.innerHTML = `
                    <td style="text-align:center;">
                        <input type="checkbox" class="row-chk" data-hs="${escapeHtml(r.hs)}" ${isSelected ? 'checked' : ''} onchange="toggleRowSelect('${escapeHtml(r.hs)}', this.checked)">
                    </td>
                    <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                    <td>
                        <strong style="font-family:monospace; font-size:12.5px; color:var(--primary); cursor:pointer;" onclick="toggleRowSelect('${escapeHtml(r.hs)}')">${escapeHtml(r.hs)}</strong>
                    </td>
                    <td style="text-align:right;">${rateBadge}</td>
                    <td style="text-align:center;" id="tgl-val-${encodeValidId(r.hs)}">${escapeHtml(r.tgl_update || '-')}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align:center;">
                        <button type="button" class="ant-btn" style="padding:2px 7px; color:var(--warning);" title="GET Tarif API CEISA untuk baris ini" onclick="getSingleTarif('${escapeHtml(r.hs)}')">
                            <i class="fa-solid fa-bolt"></i>
                        </button>
                        <button type="button" class="btn-pb-x" title="Hapus Baris (X PowerBuilder)" onclick="deleteSingleTarif('${escapeHtml(r.hs)}')">
                            ✕
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });

            updateSelectedUI();
        }

        function encodeValidId(hs) {
            return String(hs).replace(/[^a-zA-Z0-9]/g, '_');
        }

        function toggleRowSelect(hs, forceVal) {
            if (forceVal !== undefined) {
                if (forceVal) selectedHsSet.add(hs);
                else selectedHsSet.delete(hs);
            } else {
                if (selectedHsSet.has(hs)) selectedHsSet.delete(hs);
                else selectedHsSet.add(hs);
            }
            const tr = document.getElementById(`row-hs-${encodeValidId(hs)}`);
            if (tr) {
                const chk = tr.querySelector('.row-chk');
                if (chk) chk.checked = selectedHsSet.has(hs);
                if (selectedHsSet.has(hs)) tr.classList.add('selected');
                else tr.classList.remove('selected');
            }
            updateSelectedUI();
        }

        function toggleSelectAll(checked) {
            filteredTarifData.forEach(r => {
                if (checked) selectedHsSet.add(r.hs);
                else selectedHsSet.delete(r.hs);
            });
            document.querySelectorAll('.row-chk').forEach(c => c.checked = checked);
            document.querySelectorAll('#tableBody tr').forEach(tr => {
                if (checked) tr.classList.add('selected');
                else tr.classList.remove('selected');
            });
            updateSelectedUI();
        }

        function updateSelectedUI() {
            const count = selectedHsSet.size;
            document.getElementById('selectedCountText').innerText = `${count} baris terpilih`;
            const btnBulk = document.getElementById('btnBulkDelete');
            if (count > 0) {
                btnBulk.style.display = 'inline-flex';
                btnBulk.innerHTML = `<i class="fa-solid fa-trash"></i> Hapus Terpilih (${count})`;
            } else {
                btnBulk.style.display = 'none';
            }

            const chkAll = document.getElementById('chkSelectAll');
            if (filteredTarifData.length > 0 && count === filteredTarifData.length) {
                chkAll.checked = true;
                chkAll.indeterminate = false;
            } else if (count > 0) {
                chkAll.checked = false;
                chkAll.indeterminate = true;
            } else {
                chkAll.checked = false;
                chkAll.indeterminate = false;
            }
        }

        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        // 1. TAMBAH MANUAL
        function openAddModal() {
            document.getElementById('formHs').value = '';
            document.getElementById('formTarif').value = '0.00';
            openModal('modalForm');
        }

        async function submitSingleTarif() {
            const hs = document.getElementById('formHs').value.trim();
            const tarif = document.getElementById('formTarif').value.trim();
            if (!hs) {
                Swal.fire('Validasi', 'Pos Tarif (HS) tidak boleh kosong.', 'warning');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('hs', hs);
            fd.append('tarif_bm', tarif || '0');

            try {
                const res = await fetch('tarif.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalForm');
                    loadData();
                    Swal.fire({
                        title: 'Tersimpan',
                        text: json.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Gagal', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        // 2. PASTE BATCH LIST HS
        function openPasteModal() {
            document.getElementById('pasteTextarea').value = '';
            openModal('modalPaste');
        }

        async function submitPasteBatch() {
            const text = document.getElementById('pasteTextarea').value.trim();
            if (!text) {
                Swal.fire('Validasi', 'Tempelkan daftar kode HS terlebih dahulu.', 'warning');
                return;
            }

            Swal.fire({
                title: 'Sedang Memproses...',
                text: 'Mengekstrak dan menyimpan daftar Pos Tarif...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'paste_hs');
            fd.append('raw_text', text);

            try {
                const res = await fetch('tarif.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalPaste');
                    loadData();
                    Swal.fire('Selesai', json.message, 'success');
                } else {
                    Swal.fire('Gagal', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        // 3. HAPUS SATUAN / BANYAK
        async function deleteSingleTarif(hs) {
            const ok = await Swal.fire({
                title: 'Hapus Pos Tarif',
                html: `Hapus Pos Tarif <strong>${escapeHtml(hs)}</strong> dari daftar?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('hs', hs);
            try {
                const res = await fetch('tarif.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    selectedHsSet.delete(hs);
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        async function deleteSelectedRows() {
            if (selectedHsSet.size === 0) return;
            const ok = await Swal.fire({
                title: 'Hapus Pos Tarif Terpilih',
                html: `Hapus <strong>${selectedHsSet.size}</strong> Pos Tarif yang dipilih?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Hapus Semua Terpilih',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete_batch');
            fd.append('hs_list', JSON.stringify(Array.from(selectedHsSet)));
            try {
                const res = await fetch('tarif.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    selectedHsSet.clear();
                    loadData();
                    Swal.fire('Terhapus', json.message, 'success');
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        // 4. GET TARIF SINGLE (SATUAN)
        async function getSingleTarif(hs) {
            const statusEl = document.getElementById(`status-text-${encodeValidId(hs)}`);
            if (statusEl) statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:var(--warning);"></i> Mengambil API...';

            const fd = new FormData();
            fd.append('action', 'lookup_ceisa');
            fd.append('hs', hs);
            fd.append('tanggal', new Date().toISOString().slice(0, 10));

            try {
                const res = await fetch('tarif.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    updateRowDisplay(hs, json.tarif_bm, json.tgl_update);
                    Swal.fire({
                        title: `HS ${hs} Diupdate`,
                        text: `Tarif Bea Masuk: ${json.tarif_bm}% (${json.tgl_update})`,
                        icon: 'success',
                        timer: 1800,
                        showConfirmButton: false
                    });
                } else {
                    if (statusEl) statusEl.innerHTML = `<span style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Gagal: ${escapeHtml(json.message)}</span>`;
                    Swal.fire('Gagal API', json.message, 'warning');
                }
            } catch (e) {
                if (statusEl) statusEl.innerHTML = '<span style="color:var(--danger);">Error Jaringan</span>';
                Swal.fire('Error', e.message, 'error');
            }
        }

        function updateRowDisplay(hs, tarif, tgl) {
            const safeId = encodeValidId(hs);
            const badgeEl = document.getElementById(`badge-rate-${safeId}`);
            if (badgeEl) {
                if (tarif === 0) {
                    badgeEl.className = 'badge-zero';
                    badgeEl.innerText = '0% (Bebas)';
                } else {
                    badgeEl.className = 'badge-rate';
                    badgeEl.innerText = `${tarif}%`;
                }
            }
            const tglEl = document.getElementById(`tgl-val-${safeId}`);
            if (tglEl) tglEl.innerText = tgl || '-';

            const statusEl = document.getElementById(`status-text-${safeId}`);
            if (statusEl) statusEl.innerHTML = '<span style="color:var(--success); font-weight:600;"><i class="fa-solid fa-circle-check"></i> Terupdate API</span>';

            // Update memori lokal
            const item = rawTarifData.find(x => x.hs === hs);
            if (item) {
                item.tarif_bm = tarif;
                item.tgl_update = tgl;
            }
        }

        // 5. TOMBOL UTAMA: "GET TARIF DAN UPDATE" (SEPERTI POWERBUILDER ue_nib)
        async function executeGetTarif() {
            let targetHsList = [];
            if (selectedHsSet.size > 0) {
                targetHsList = Array.from(selectedHsSet);
            } else {
                targetHsList = filteredTarifData.map(r => r.hs);
            }

            if (targetHsList.length === 0) {
                Swal.fire('Informasi', 'Daftar Pos Tarif kosong. Tambahkan atau paste kode HS terlebih dahulu.', 'info');
                return;
            }

            const confirmMsg = selectedHsSet.size > 0
                ? `Update tarif dari API CEISA 4.0 untuk <strong>${targetHsList.length}</strong> Pos Tarif yang dipilih?`
                : `Update tarif dari API CEISA 4.0 untuk seluruh <strong>${targetHsList.length}</strong> Pos Tarif terdaftar?`;

            const ok = await Swal.fire({
                title: '⚡ GET Tarif dan Update',
                html: confirmMsg,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#faad14',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: '<i class="fa-solid fa-bolt"></i> Ya, Mulai Update API',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            // Buka Modal Progress Realtime
            isStopRequested = false;
            openModal('modalGetProgress');
            
            const total = targetHsList.length;
            let successCount = 0;
            let failCount = 0;
            const tglNow = new Date().toISOString().slice(0, 10);

            for (let i = 0; i < total; i++) {
                if (isStopRequested) break;

                const hs = targetHsList[i];
                const pct = Math.round(((i + 1) / total) * 100);

                document.getElementById('progressCurrentHs').innerText = `Memeriksa HS: ${hs}`;
                document.getElementById('progressCountText').innerText = `Memproses ${i + 1} dari ${total} Pos Tarif...`;
                document.getElementById('progressBarFill').style.width = `${pct}%`;
                document.getElementById('progressPctText').innerText = `${pct}%`;

                // Highlight baris di tabel dan tampilkan spinner
                const statusEl = document.getElementById(`status-text-${encodeValidId(hs)}`);
                if (statusEl) statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:var(--warning);"></i> Memeriksa...';

                try {
                    const fd = new FormData();
                    fd.append('action', 'lookup_ceisa');
                    fd.append('hs', hs);
                    fd.append('tanggal', tglNow);

                    const res = await fetch('tarif.php', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.status === 'OK') {
                        updateRowDisplay(hs, json.tarif_bm, json.tgl_update);
                        successCount++;
                    } else {
                        if (statusEl) statusEl.innerHTML = `<span style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Gagal</span>`;
                        failCount++;
                    }
                } catch (e) {
                    if (statusEl) statusEl.innerHTML = '<span style="color:var(--danger);">Error</span>';
                    failCount++;
                }

                // Beri jeda kecil agar request tidak membebani network
                await new Promise(r => setTimeout(r, 120));
            }

            closeModal('modalGetProgress');

            const finishMsg = isStopRequested 
                ? `Proses dihentikan oleh pengguna.<br>Berhasil update: <strong>${successCount}</strong>, Gagal: <strong>${failCount}</strong>.`
                : `Proses GET Tarif Selesai!<br>Berhasil diupdate: <strong>${successCount}</strong>, Tidak ditemukan/gagal: <strong>${failCount}</strong>.`;

            Swal.fire({
                title: 'Selesai',
                html: finishMsg,
                icon: successCount > 0 ? 'success' : 'info'
            });

            loadData();
        }

        function stopGetProcess() {
            isStopRequested = true;
            document.getElementById('progressCurrentHs').innerText = 'Menghentikan proses...';
        }

        // 6. EXCEL IMPORT & EXPORT
        function openImportModal() {
            importedRowsData = [];
            document.getElementById('excelFileInput').value = '';
            document.getElementById('importPreviewContainer').style.display = 'none';
            document.getElementById('btnExecuteImport').style.display = 'none';
            openModal('modalImport');
        }

        function downloadTemplate() {
            const template = [
                { hs: "39011012", tarif_bm: 5, tgl_update: "<?= date('Y-m-d') ?>" },
                { hs: "39073090", tarif_bm: 0, tgl_update: "<?= date('Y-m-d') ?>" },
                { hs: "84713020", tarif_bm: 0, tgl_update: "<?= date('Y-m-d') ?>" }
            ];
            const ws = XLSX.utils.json_to_sheet(template);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Template_Tarif_BM");
            XLSX.writeFile(wb, "Template_Master_Tarif_BM.xlsx");
        }

        function handleExcelUpload(e) {
            const files = e.target.files;
            if (!files || files.length === 0) return;
            const file = files[0];
            const reader = new FileReader();
            reader.onload = function(evt) {
                try {
                    const data = new Uint8Array(evt.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const jsonData = XLSX.utils.sheet_to_json(firstSheet, { defval: "" });

                    if (!jsonData || jsonData.length === 0) {
                        Swal.fire('Format Salah', 'File Excel kosong atau tidak ada data.', 'warning');
                        return;
                    }

                    importedRowsData = jsonData;
                    renderImportPreview(jsonData);
                } catch (err) {
                    Swal.fire('Error Baca File', 'Gagal memproses file: ' + err.message, 'error');
                }
            };
            reader.readAsArrayBuffer(file);
        }

        function renderImportPreview(rows) {
            const tbody = document.getElementById('previewBody');
            tbody.innerHTML = '';
            document.getElementById('previewCountText').innerText = `Preview Data: ${rows.length} baris`;

            const max = Math.min(rows.length, 10);
            for (let i = 0; i < max; i++) {
                const r = rows[i];
                const tr = document.createElement('tr');
                const hsVal = r.hs || r.HS || r.kode_hs || r['Pos Tarif'] || '';
                const bmVal = r.tarif_bm !== undefined ? r.tarif_bm : (r.TARIF_BM !== undefined ? r.TARIF_BM : 0);
                const tglVal = r.tgl_update || r.TGL_UPDATE || r.Tanggal || '-';
                tr.innerHTML = `
                    <td><strong>${escapeHtml(hsVal)}</strong></td>
                    <td style="text-align:right;">${Number(bmVal)}%</td>
                    <td>${escapeHtml(tglVal)}</td>
                `;
                tbody.appendChild(tr);
            }

            document.getElementById('importPreviewContainer').style.display = 'block';
            document.getElementById('btnExecuteImport').style.display = 'inline-flex';
        }

        async function executeImport() {
            if (!importedRowsData || importedRowsData.length === 0) {
                Swal.fire('Error', 'Tidak ada data untuk diimpor.', 'warning');
                return;
            }

            const ok = await Swal.fire({
                title: 'Konfirmasi Impor',
                html: `Import <strong>${importedRowsData.length}</strong> Pos Tarif ke Master?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#52c41a',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Impor Sekarang',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            Swal.fire({
                title: 'Sedang Mengimpor Data...',
                text: 'Menyimpan Pos Tarif ke database...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const fd = new FormData();
            fd.append('action', 'import_excel');
            fd.append('rows', JSON.stringify(importedRowsData));

            try {
                const res = await fetch('tarif.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalImport');
                    loadData();
                    Swal.fire('Berhasil', json.message, 'success');
                } else {
                    Swal.fire('Gagal Impor', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        function exportExcel() {
            if (rawTarifData.length === 0) {
                Swal.fire('Informasi', 'Tidak ada data untuk diexport.', 'info');
                return;
            }
            const dataToExport = rawTarifData.map(r => ({
                'Pos Tarif (HS)': r.hs,
                'Tarif BM (%)': r.tarif_bm !== null ? r.tarif_bm : '',
                'Tanggal Update': r.tgl_update || ''
            }));
            const ws = XLSX.utils.json_to_sheet(dataToExport);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Master_Tarif_BM");
            XLSX.writeFile(wb, `Master_Tarif_BM_${new Date().toISOString().slice(0,10)}.xlsx`);
        }

        function escapeHtml(text) {
            if (!text && text !== 0) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.onload = loadData;
    </script>
</body>
</html>

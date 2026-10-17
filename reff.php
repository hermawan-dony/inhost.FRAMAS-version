<?php
/**
 * CEISA 4.0 - Master Data Referensi (bc_ceisa_reff)
 * Manajemen Referensi Kepabeanan, Dropdown Editor, Export/Import Excel & CSV
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$action = $_REQUEST['action'] ?? '';

// -------------------------------------------------------------
// 1. AJAX API: LIST KATEGORI REFF
// -------------------------------------------------------------
if ($action === 'get_categories') {
    header('Content-Type: application/json; charset=utf-8');
    $sql = "SELECT reff, COUNT(*) as cnt FROM bc_ceisa_reff GROUP BY reff ORDER BY reff ASC";
    $res = $koneksi->query($sql);
    $cats = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $cats[] = [
                'reff' => $r['reff'],
                'count' => (int)$r['cnt']
            ];
        }
    }
    echo json_encode(['status' => 'OK', 'categories' => $cats]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX API: LIST DATA REFERENSI (PAGINATED & SEARCHABLE)
// -------------------------------------------------------------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $reffCat = trim($_GET['reff'] ?? '');
    $search  = trim($_GET['search'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = max(10, min(200, (int)($_GET['limit'] ?? 50)));
    $offset  = ($page - 1) * $limit;

    $where = [];
    if (!empty($reffCat)) {
        $escCat = $koneksi->real_escape_string($reffCat);
        $where[] = "reff = '$escCat'";
    }
    if (!empty($search)) {
        $escSearch = $koneksi->real_escape_string($search);
        $where[] = "(kode LIKE '%$escSearch%' OR teks LIKE '%$escSearch%' OR grup LIKE '%$escSearch%')";
    }

    $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

    $countRes = $koneksi->query("SELECT COUNT(*) as total FROM bc_ceisa_reff $whereSql");
    $totalRows = $countRes ? (int)$countRes->fetch_assoc()['total'] : 0;

    $sql = "SELECT reff, grup, kode, teks, status FROM bc_ceisa_reff $whereSql ORDER BY reff ASC, kode ASC LIMIT $limit OFFSET $offset";
    $res = $koneksi->query($sql);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'total' => $totalRows,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($totalRows / $limit),
        'data' => $rows
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. AJAX API: GET OPTIONS BY REFF NAME (FOR DROPDOWNS)
// -------------------------------------------------------------
if ($action === 'get_options') {
    header('Content-Type: application/json; charset=utf-8');
    $reffName = trim($_GET['reff'] ?? '');
    if (empty($reffName)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Nama referensi wajib diisi.']);
        exit;
    }
    $esc = $koneksi->real_escape_string($reffName);
    $res = $koneksi->query("SELECT kode, teks, grup FROM bc_ceisa_reff WHERE reff = '$esc' ORDER BY kode ASC");
    $options = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $options[] = [
                'kode' => $r['kode'],
                'teks' => $r['teks'],
                'grup' => $r['grup']
            ];
        }
    }
    echo json_encode(['status' => 'OK', 'reff' => $reffName, 'options' => $options]);
    exit;
}

// -------------------------------------------------------------
// 4. AJAX API: SIMPAN / TAMBAH / EDIT REFERENSI
// -------------------------------------------------------------
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $reff      = trim($_POST['reff'] ?? '');
    $kode      = trim($_POST['kode'] ?? '');
    $teks      = trim($_POST['teks'] ?? '');
    $grup      = trim($_POST['grup'] ?? '-');
    $status    = trim($_POST['status'] ?? '');
    $isEdit    = !empty($_POST['is_edit']);
    $oldReff   = trim($_POST['old_reff'] ?? $reff);
    $oldKode   = trim($_POST['old_kode'] ?? $kode);

    if (empty($reff) || empty($kode) || empty($teks)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Kategori (reff), Kode, dan Teks wajib diisi.']);
        exit;
    }

    if ($isEdit) {
        $stmt = $koneksi->prepare("UPDATE bc_ceisa_reff SET reff = ?, grup = ?, kode = ?, teks = ?, status = ? WHERE reff = ? AND kode = ?");
        if ($stmt) {
            $stmt->bind_param("sssssss", $reff, $grup, $kode, $teks, $status, $oldReff, $oldKode);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'OK', 'message' => 'Data referensi berhasil diperbarui!']);
            } else {
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal update: ' . $stmt->error]);
            }
            $stmt->close();
        }
    } else {
        $stmt = $koneksi->prepare("INSERT INTO bc_ceisa_reff (reff, grup, kode, teks, status) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE grup = VALUES(grup), teks = VALUES(teks), status = VALUES(status)");
        if ($stmt) {
            $stmt->bind_param("sssss", $reff, $grup, $kode, $teks, $status);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'OK', 'message' => 'Data referensi berhasil ditambahkan!']);
            } else {
                echo json_encode(['status' => 'ERROR', 'message' => 'Gagal simpan: ' . $stmt->error]);
            }
            $stmt->close();
        }
    }
    exit;
}

// -------------------------------------------------------------
// 5. AJAX API: HAPUS REFERENSI
// -------------------------------------------------------------
if ($action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $reff = trim($_POST['reff'] ?? '');
    $kode = trim($_POST['kode'] ?? '');

    if (empty($reff) || empty($kode)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Reff dan Kode tidak valid.']);
        exit;
    }

    $stmt = $koneksi->prepare("DELETE FROM bc_ceisa_reff WHERE reff = ? AND kode = ?");
    if ($stmt) {
        $stmt->bind_param("ss", $reff, $kode);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'OK', 'message' => "Referensi [$reff] $kode berhasil dihapus!"]);
        } else {
            echo json_encode(['status' => 'ERROR', 'message' => 'Gagal hapus: ' . $stmt->error]);
        }
        $stmt->close();
    }
    exit;
}

// -------------------------------------------------------------
// 6. EXPORT TEMPLATE & DATA KE CSV / EXCEL
// -------------------------------------------------------------
if ($action === 'export') {
    $reffCat = trim($_GET['reff'] ?? '');
    $where = "";
    $filename = "bc_ceisa_reff_all.csv";
    if (!empty($reffCat)) {
        $esc = $koneksi->real_escape_string($reffCat);
        $where = "WHERE reff = '$esc'";
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reffCat);
        $filename = "bc_ceisa_reff_{$safeName}.csv";
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM untuk Microsoft Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header Kolom
    fputcsv($out, ['reff', 'grup', 'kode', 'teks', 'status']);

    $res = $koneksi->query("SELECT reff, grup, kode, teks, status FROM bc_ceisa_reff $where ORDER BY reff ASC, kode ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            fputcsv($out, [$r['reff'], $r['grup'], $r['kode'], $r['teks'], $r['status']]);
        }
    }
    fclose($out);
    exit;
}

// -------------------------------------------------------------
// 7. DOWNLOAD TEMPLATE BLANK UNTUK IMPOR
// -------------------------------------------------------------
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_reff.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['reff', 'grup', 'kode', 'teks', 'status']);
    fputcsv($out, ['CARA BAYAR', '-', '1', 'BIASA / TUNAI', '']);
    fputcsv($out, ['CARA BAYAR', '-', '2', 'BERKALA', '']);
    fputcsv($out, ['VALUTA', '-', 'USD', 'US DOLLAR', '']);
    fclose($out);
    exit;
}

// -------------------------------------------------------------
// 8. IMPORT CSV / EXCEL KE bc_ceisa_reff
// -------------------------------------------------------------
if ($action === 'import') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_FILES['file_reff']) || $_FILES['file_reff']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Berkas Excel/CSV gagal diunggah.']);
        exit;
    }

    $tmpPath = $_FILES['file_reff']['tmp_name'];
    $fileName = $_FILES['file_reff']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $rowsToImport = [];

    if ($ext === 'csv') {
        if (($handle = fopen($tmpPath, 'r')) !== false) {
            // Cek BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $header = fgetcsv($handle, 2000, ',');
            if (!$header || count($header) < 4) {
                rewind($handle);
                $header = fgetcsv($handle, 2000, ';'); // Support pemisah titik koma
            }

            // Normalisasi nama header kolom
            $colMap = [];
            foreach ($header as $i => $h) {
                $colMap[strtolower(trim($h))] = $i;
            }

            while (($row = fgetcsv($handle, 2000, ',')) !== false) {
                if (count($row) < 3) {
                    $row = explode(';', $row[0]);
                }
                $r = $row[$colMap['reff'] ?? 0] ?? '';
                $g = $row[$colMap['grup'] ?? 1] ?? '-';
                $k = $row[$colMap['kode'] ?? 2] ?? '';
                $t = $row[$colMap['teks'] ?? 3] ?? '';
                $s = $row[$colMap['status'] ?? 4] ?? '';
                if (!empty(trim($r)) && !empty(trim($k))) {
                    $rowsToImport[] = [
                        'reff' => trim($r),
                        'grup' => trim($g) ?: '-',
                        'kode' => trim($k),
                        'teks' => trim($t),
                        'status' => trim($s)
                    ];
                }
            }
            fclose($handle);
        }
    } elseif ($ext === 'xlsx') {
        // Gunakan native XML extractor dari ZIP file XLSX
        require_once __DIR__ . '/CeisaXlsxImporter.php';
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            // Ambil sharedStrings
            $sharedStrings = [];
            if (($xmlStr = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = simplexml_load_string($xmlStr);
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string)($si->t ?? $si->r->t ?? '');
                }
            }
            // Ambil sheet 1
            if (($sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
                $xml = simplexml_load_string($sheetXml);
                $isHeader = true;
                foreach ($xml->sheetData->row as $row) {
                    if ($isHeader) { $isHeader = false; continue; }
                    $rowCells = [];
                    foreach ($row->c as $c) {
                        $attr = $c->attributes();
                        $type = (string)($attr['t'] ?? '');
                        $val = (string)($c->v ?? '');
                        if ($type === 's' && isset($sharedStrings[(int)$val])) {
                            $val = $sharedStrings[(int)$val];
                        }
                        $rowCells[] = trim($val);
                    }
                    if (count($rowCells) >= 4 && !empty($rowCells[0]) && !empty($rowCells[2])) {
                        $rowsToImport[] = [
                            'reff'   => $rowCells[0],
                            'grup'   => $rowCells[1] ?: '-',
                            'kode'   => $rowCells[2],
                            'teks'   => $rowCells[3] ?? '',
                            'status' => $rowCells[4] ?? ''
                        ];
                    }
                }
            }
            $zip->close();
        }
    } else {
        echo json_encode(['status' => 'ERROR', 'message' => 'Format file harus .xlsx atau .csv']);
        exit;
    }

    if (empty($rowsToImport)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada baris referensi yang valid ditemukan pada berkas.']);
        exit;
    }

    $stmt = $koneksi->prepare("INSERT INTO bc_ceisa_reff (reff, grup, kode, teks, status) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE grup = VALUES(grup), teks = VALUES(teks), status = VALUES(status)");
    $success = 0;
    foreach ($rowsToImport as $r) {
        $stmt->bind_param("sssss", $r['reff'], $r['grup'], $r['kode'], $r['teks'], $r['status']);
        if ($stmt->execute()) {
            $success++;
        }
    }
    $stmt->close();

    echo json_encode([
        'status' => 'OK',
        'message' => "Berhasil mengimpor $success data referensi ke tabel bc_ceisa_reff!",
        'total' => count($rowsToImport),
        'imported' => $success
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Referensi Kepabeanan - CEISA 4.0</title>
    <script>
        (function() {
            var theme = localStorage.getItem('ceisa_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        })();
        const isEmbeddedMode = (window.self !== window.top) || (new URLSearchParams(window.location.search).get('embedded') === '1');
        if (isEmbeddedMode) {
            document.documentElement.classList.add('is-embedded');
        }
    </script>
    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <style>
        :root, body[data-theme="light"], html[data-theme="light"] body {
            /* Official CEISA 4.0 Light Theme (DEFAULT) */
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --bg-input: #ffffff;
            --border: #e8e8e8;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --accent: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            color-scheme: light;
        }

        body[data-theme="dark"], html[data-theme="dark"] body {
            /* CEISA Dark Theme Option */
            --bg-base: #141414;
            --bg-card: #1f1f1f;
            --bg-input: #141414;
            --border: #303030;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --accent: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: rgba(255, 255, 255, 0.45);
            color-scheme: dark;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            font-size: 10.5px;
            line-height: 1.45;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .topbar {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 4px 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .brand {
            font-weight: 700;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn {
            background: var(--primary);
            color: #0b1120;
            font-weight: 600;
            border: none;
            padding: 0 7px;
            height: 22px;
            border-radius: 2px;
            cursor: pointer;
            font-size: 10px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            text-decoration: none;
            font-family: inherit;
        }
        .btn:hover { opacity: 0.9; }
        .btn-secondary { background: var(--bg-input); color: var(--text-main); border: 1px solid var(--border); }
        .btn-success { background: var(--accent); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-sm { padding: 0 5px; height: 20px; font-size: 9px; }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 6px 10px;
            width: 100%;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* 2-Panel Master-Detail Layout */
        .layout-wrapper {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            width: 100%;
            flex: 1;
        }

        /* Category Sidebar */
        .cat-sidebar {
            width: 220px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            height: calc(100vh - 54px);
            position: sticky;
            top: 44px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        /* Embedded Mode (Saat dimuat dalam iframe Portal CEISA) */
        html.is-embedded .topbar,
        body.is-embedded .topbar {
            display: none !important;
        }
        html.is-embedded .container,
        body.is-embedded .container {
            padding: 4px 6px !important;
        }
        html.is-embedded .cat-sidebar,
        body.is-embedded .cat-sidebar {
            top: 6px !important;
            height: calc(100vh - 12px) !important;
        }

        .cat-sidebar-header {
            padding: 6px 8px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-input);
            border-radius: 3px 3px 0 0;
        }

        .cat-sidebar-title {
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            margin-bottom: 6px;
            color: var(--text-main);
        }

        .cat-badge-total {
            background: rgba(54, 153, 255, 0.15);
            color: var(--primary);
            padding: 1px 5px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 700;
        }

        .cat-search-box input {
            width: 100%;
            font-size: 9.5px;
            padding: 1px 6px;
            height: 22px;
            border-radius: 2px;
            background: var(--bg-base);
            border: 1px solid var(--border);
            color: var(--text-main);
            font-family: inherit;
        }

        .cat-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px;
        }

        .cat-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 8px;
            border-radius: 2px;
            cursor: pointer;
            font-size: 10px;
            margin-bottom: 2px;
            color: var(--text-main);
            transition: all 0.15s;
        }

        .cat-item:hover {
            background: rgba(54, 153, 255, 0.08);
            color: var(--primary);
        }

        .cat-item.active {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(54, 153, 255, 0.3);
        }

        .cat-item.active .cat-count-pill {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }

        .cat-count-pill {
            background: var(--bg-input);
            color: var(--text-muted);
            padding: 1px 5px;
            border-radius: 6px;
            font-size: 8.5px;
            font-weight: 600;
            font-family: monospace;
        }

        /* Main Panel */
        .main-panel {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .active-cat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .active-cat-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .active-cat-sub {
            font-size: 9.5px;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .active-cat-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .toolbar {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 6px 8px;
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        select, input {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 1px 6px;
            height: 22px;
            border-radius: 2px;
            font-size: 10px;
            outline: none;
            font-family: inherit;
        }
        select:focus, input:focus { border-color: var(--primary); }

        /* High-Contrast Calendar Picker Indicator */
        input[type="date"] {
            color-scheme: dark;
            position: relative;
        }
        body[data-theme="light"] input[type="date"] {
            color-scheme: light;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 0.85;
            padding: 2px;
            border-radius: 2px;
            transition: all 0.2s;
        }
        input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
            transform: scale(1.1);
            background: rgba(54, 153, 255, 0.2);
        }

        .table-wrap {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            text-align: left;
        }
        th, td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: var(--bg-input);
            font-weight: 600;
            color: var(--text-muted);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        tr:hover td {
            background: rgba(255,255,255,0.02);
        }

        .badge-cat {
            background: rgba(54, 153, 255, 0.12);
            color: var(--primary);
            padding: 1px 4px;
            border-radius: 2px;
            font-weight: 600;
            font-size: 8.5px;
            display: inline-block;
        }

        /* Group Header Row in Table */
        .cat-group-row td {
            background: rgba(54, 153, 255, 0.08) !important;
            border-top: 1.5px solid var(--primary);
            border-bottom: 1px solid var(--border);
            padding: 4px 6px !important;
        }

        .cat-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9.5px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 8px;
            background: var(--bg-input);
            border-top: 1px solid var(--border);
            font-size: 9.5px;
            color: var(--text-muted);
        }

        @media (max-width: 960px) {
            .layout-wrapper {
                flex-direction: column;
            }
            .cat-sidebar {
                width: 100%;
                position: static;
                height: auto;
                max-height: 200px;
            }
            .cat-list {
                max-height: 130px;
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }
        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 3px;
            width: 100%;
            max-width: 440px;
            padding: 12px 14px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .modal-header h3 { font-size: 12px; }
        .form-group {
            margin-bottom: 6px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .form-group label {
            font-size: 9px;
            font-weight: 600;
            color: var(--text-muted);
        }

        /* Theme Selector Pill */
        .theme-select {
            display: inline-flex;
            gap: 1px;
            background: var(--bg-input);
            padding: 1px;
            border-radius: 3px;
            border: 1px solid var(--border);
        }
        .theme-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 9px;
            color: var(--text-muted);
        }
        .theme-btn.active {
            background: var(--primary);
            color: #000;
            font-weight: 600;
        }
    </style>
</head>
<?php $isEmbedded = (!empty($_GET['embedded']) && $_GET['embedded'] == '1'); ?>
<body data-theme="light" class="<?= $isEmbedded ? 'is-embedded' : '' ?>">

    <div class="topbar">
        <div class="brand">
            <span>📚</span>
            <span>Master Data Referensi CEISA 4.0</span>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <div class="theme-select">
                <button class="theme-btn active" data-theme="light" onclick="setTheme('light')">☀️ Light</button>
                <button class="theme-btn" data-theme="dark" onclick="setTheme('dark')">🌙 Dark</button>
            </div>
            <a href="/editor.php" class="btn btn-secondary">✏️ Editor AJU</a>
            <a href="/transfer.php" class="btn btn-secondary">🚀 Transfer</a>
        </div>
    </div>

    <div class="container">
        <div class="layout-wrapper">
            <!-- SIDEBAR KATEGORI (LEFT PANE) -->
            <aside class="cat-sidebar">
                <div class="cat-sidebar-header">
                    <div class="cat-sidebar-title">
                        <span>📂 Kategori Referensi</span>
                        <span class="cat-badge-total" id="catTotalBadge">44 Kategori</span>
                    </div>
                    <div class="cat-search-box">
                        <input type="text" id="catSearchInput" placeholder="Cari kategori (misal: CARA, DOK)..." onkeyup="filterCategoryList()">
                    </div>
                </div>
                <div class="cat-list" id="catListContainer">
                    <div class="cat-item active" data-cat="" onclick="selectCategoryItem('')">
                        <span class="cat-name">📁 Semua Kategori</span>
                        <span class="cat-count-pill" id="allCountPill">...</span>
                    </div>
                    <!-- Daftar 44 kategori akan di-render otomatis di sini -->
                </div>
            </aside>

            <!-- MAIN PANEL (RIGHT PANE) -->
            <main class="main-panel">
                <!-- ACTIVE CATEGORY BANNER & ACTIONS -->
                <div class="active-cat-card">
                    <div class="active-cat-info">
                        <div class="active-cat-title" id="activeCatTitle">
                            <span>📁 Semua Kategori Referensi</span>
                        </div>
                        <div class="active-cat-sub" id="activeCatSub">
                            Menampilkan seluruh data referensi dari 44 kategori kepabeanan terdaftar
                        </div>
                    </div>
                    <div class="active-cat-actions">
                        <button class="btn btn-success" onclick="openAddModal()">➕ Tambah Data</button>
                        <button class="btn btn-secondary" onclick="exportData()">📤 Export Data</button>
                        <button class="btn btn-secondary" onclick="openImportModal()">📥 Import Excel</button>
                    </div>
                </div>

                <!-- TOOLBAR SEARCH & LIMIT -->
                <div class="toolbar">
                    <div style="flex:1; min-width:240px; position:relative;">
                        <input type="text" id="searchInput" placeholder="Cari kode atau uraian dalam kategori ini (tekan Enter)..." style="width:100%; padding-left:36px;" onkeydown="if(event.key==='Enter') loadData(1)">
                        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:14px; color:var(--text-muted); pointer-events:none;">🔍</span>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center;">
                        <button class="btn btn-primary" onclick="loadData(1)">Cari</button>
                        <button class="btn btn-secondary" onclick="resetSearch()">Reset</button>
                        <select id="selectLimit" onchange="changeLimit()" style="width:130px;">
                            <option value="25">25 baris/hal</option>
                            <option value="50" selected>50 baris/hal</option>
                            <option value="100">100 baris/hal</option>
                            <option value="200">200 baris/hal</option>
                        </select>
                    </div>
                </div>

                <!-- TABLE DATA -->
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr id="tableHeadRow">
                                <th style="width:50px; text-align:center;">#</th>
                                <th style="width:180px;" id="thKategori">Kategori (Reff)</th>
                                <th style="width:100px;">Grup</th>
                                <th style="width:140px;">Kode</th>
                                <th>Uraian / Teks Referensi</th>
                                <th style="width:80px; text-align:center;">Status</th>
                                <th style="width:110px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted);">Memuat data referensi...</td></tr>
                        </tbody>
                    </table>

                    <div class="pagination">
                        <span id="pageInfo">Menampilkan 0 dari 0 data</span>
                        <div style="display:flex; gap:6px;">
                            <button class="btn btn-secondary btn-sm" id="btnPrev" onclick="prevPage()">◀ Sebelumnya</button>
                            <button class="btn btn-secondary btn-sm" id="btnNext" onclick="nextPage()">Selanjutnya ▶</button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- MODAL ADD / EDIT -->
    <div class="modal" id="modalForm">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalFormTitle">Tambah Referensi Baru</h3>
                <button onclick="closeModal('modalForm')" style="background:transparent; border:none; color:var(--text-muted); font-size:18px; cursor:pointer;">✕</button>
            </div>
            <form id="formRef" onsubmit="saveRef(event)">
                <input type="hidden" id="refIsEdit" value="0">
                <input type="hidden" id="refOldReff" value="">
                <input type="hidden" id="refOldKode" value="">

                <div class="form-group">
                    <label>Kategori Referensi (Reff) *</label>
                    <input type="text" id="refReff" list="categoryDatalist" required placeholder="Pilih atau ketik kategori (misal: CARA BAYAR)">
                    <datalist id="categoryDatalist"></datalist>
                </div>
                <div class="form-group">
                    <label>Grup</label>
                    <input type="text" id="refGrup" value="-" placeholder="Grup (opsional, default '-')">
                </div>
                <div class="form-group">
                    <label>Kode *</label>
                    <input type="text" id="refKode" required placeholder="Contoh: 1, USD, IDCGK">
                </div>
                <div class="form-group">
                    <label>Uraian / Teks Lengkap *</label>
                    <input type="text" id="refTeks" required placeholder="Contoh: BIASA / TUNAI">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <input type="text" id="refStatus" placeholder="Status (opsional)">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalForm')">Batal</button>
                    <button type="submit" class="btn btn-success" id="btnSaveRef">💾 Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL IMPORT EXCEL -->
    <div class="modal" id="modalImport">
        <div class="modal-card">
            <div class="modal-header">
                <h3>📥 Import File Excel / CSV Referensi</h3>
                <button onclick="closeModal('modalImport')" style="background:transparent; border:none; color:var(--text-muted); font-size:18px; cursor:pointer;">✕</button>
            </div>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:14px;">
                Unggah file Excel (<code>.xlsx</code>) atau CSV (<code>.csv</code>) yang berisi kolom: <strong>reff, grup, kode, teks, status</strong>.
            </p>
            <div style="margin-bottom:14px;">
                <a href="reff.php?action=template" class="btn btn-secondary btn-sm">📋 Download Template CSV</a>
            </div>
            <form id="formImport" onsubmit="submitImport(event)">
                <div class="form-group">
                    <label>Pilih Berkas (.xlsx / .csv)</label>
                    <input type="file" id="fileRef" accept=".xlsx,.csv" required style="width:100%; padding:10px;">
                </div>
                <div id="importProgress" style="display:none; font-size:13px; color:var(--primary); margin:10px 0;">
                    Mengimpor data ke database, mohon tunggu...
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modalImport')">Tutup</button>
                    <button type="submit" class="btn btn-success" id="btnSubmitImport">🚀 Mulai Import</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let selectedCategory = '';
        let currentPage = 1;
        let currentLimit = 50;
        let totalPages = 1;
        let allCategories = [];

        async function init() {
            if (isEmbeddedMode) {
                document.body.classList.add('is-embedded');
                const tb = document.querySelector('.topbar');
                if (tb) tb.style.display = 'none';
            }
            loadSavedTheme();
            await loadCategories();
            await loadData(1);
        }

        function setTheme(theme) {
            if (theme !== 'light' && theme !== 'dark') theme = 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.body.setAttribute('data-theme', theme);
            localStorage.setItem('ceisa_theme', theme);
            document.querySelectorAll('.theme-btn').forEach(b => {
                b.classList.toggle('active', b.getAttribute('data-theme') === theme);
            });
        }

        function loadSavedTheme() {
            let saved = localStorage.getItem('ceisa_theme');
            if (saved !== 'light' && saved !== 'dark') saved = 'light';
            setTheme(saved);
        }
        loadSavedTheme();

        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SET_THEME') {
                setTheme(e.data.theme);
            }
        });

        async function loadCategories() {
            try {
                const res = await fetch('reff.php?action=get_categories');
                const json = await res.json();
                if (json.status === 'OK') {
                    allCategories = json.categories || [];
                    let totalItems = 0;
                    allCategories.forEach(c => totalItems += c.count);

                    document.getElementById('catTotalBadge').innerText = `${allCategories.length} Kategori`;
                    document.getElementById('allCountPill').innerText = totalItems.toLocaleString('id-ID');

                    // Render Category Sidebar List
                    renderCategorySidebar();

                    // Render Datalist for Modal Input
                    const dl = document.getElementById('categoryDatalist');
                    if (dl) {
                        dl.innerHTML = '';
                        allCategories.forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c.reff;
                            dl.appendChild(opt);
                        });
                    }
                }
            } catch (err) {
                console.error('Error load categories:', err);
            }
        }

        function renderCategorySidebar() {
            const container = document.getElementById('catListContainer');
            const firstItem = container.firstElementChild;
            container.innerHTML = '';
            container.appendChild(firstItem);

            allCategories.forEach(c => {
                const item = document.createElement('div');
                item.className = 'cat-item' + (selectedCategory === c.reff ? ' active' : '');
                item.setAttribute('data-cat', c.reff);
                item.onclick = () => selectCategoryItem(c.reff);
                item.innerHTML = `
                    <span class="cat-name">${escapeHtml(c.reff)}</span>
                    <span class="cat-count-pill">${c.count.toLocaleString('id-ID')}</span>
                `;
                container.appendChild(item);
            });
        }

        function filterCategoryList() {
            const q = document.getElementById('catSearchInput').value.toLowerCase().trim();
            const items = document.querySelectorAll('#catListContainer .cat-item');
            items.forEach((item, idx) => {
                if (idx === 0) return; // Keep "Semua Kategori"
                const cat = (item.getAttribute('data-cat') || '').toLowerCase();
                item.style.display = (cat.indexOf(q) > -1) ? 'flex' : 'none';
            });
        }

        function selectCategoryItem(catName) {
            selectedCategory = catName;

            // Highlight sidebar item
            document.querySelectorAll('#catListContainer .cat-item').forEach(item => {
                const itemCat = item.getAttribute('data-cat') || '';
                item.classList.toggle('active', itemCat === selectedCategory);
            });

            // Update Header Banner
            const titleEl = document.getElementById('activeCatTitle');
            const subEl = document.getElementById('activeCatSub');
            const thCat = document.getElementById('thKategori');

            if (selectedCategory) {
                const found = allCategories.find(c => c.reff === selectedCategory);
                const countText = found ? ` (${found.count.toLocaleString('id-ID')} data terdaftar)` : '';
                titleEl.innerHTML = `<span>📂 Kategori: <strong>${escapeHtml(selectedCategory)}</strong></span>`;
                subEl.innerText = `Menampilkan seluruh kode dan uraian referensi untuk kategori ${selectedCategory}${countText}`;
                if (thCat) thCat.style.display = 'none';
            } else {
                titleEl.innerHTML = `<span>📁 Semua Kategori Referensi</span>`;
                subEl.innerText = `Menampilkan seluruh data referensi dari 44 kategori kepabeanan terdaftar`;
                if (thCat) thCat.style.display = '';
            }

            // Reset search input and reload
            document.getElementById('searchInput').value = '';
            loadData(1);
        }

        async function loadData(page = 1) {
            currentPage = page;
            const search = document.getElementById('searchInput').value.trim();
            const tbody = document.getElementById('tableBody');
            const colSpan = selectedCategory ? 6 : 7;
            tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center; padding:30px; color:var(--text-muted);">Memuat data referensi...</td></tr>`;

            try {
                const url = `reff.php?action=list&reff=${encodeURIComponent(selectedCategory)}&search=${encodeURIComponent(search)}&page=${page}&limit=${currentLimit}`;
                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'OK') {
                    totalPages = json.pages || 1;
                    renderTable(json.data);
                    document.getElementById('pageInfo').innerText = `Menampilkan halaman ${json.page} dari ${totalPages} (Total ${json.total.toLocaleString('id-ID')} data)`;
                    document.getElementById('btnPrev').disabled = (currentPage <= 1);
                    document.getElementById('btnNext').disabled = (currentPage >= totalPages);
                } else {
                    tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center; color:var(--danger);">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center; color:var(--danger);">Koneksi gagal: ${err.message}</td></tr>`;
            }
        }

        function renderTable(rows) {
            const tbody = document.getElementById('tableBody');
            const thCat = document.getElementById('thKategori');
            tbody.innerHTML = '';

            const isSingleCat = (selectedCategory !== '');
            if (thCat) thCat.style.display = isSingleCat ? 'none' : '';
            const colSpan = isSingleCat ? 6 : 7;

            if (rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${colSpan}" style="text-align:center; padding:30px; color:var(--text-muted);">Tidak ada data referensi ditemukan.</td></tr>`;
                return;
            }

            let lastReff = null;
            let rowNum = (currentPage - 1) * currentLimit;

            rows.forEach(r => {
                rowNum++;

                // Saat mode Semua Kategori, beri Category Group Divider setiap ganti kategori
                if (!isSingleCat && r.reff !== lastReff) {
                    lastReff = r.reff;
                    const catObj = allCategories.find(c => c.reff === r.reff);
                    const catCnt = catObj ? `${catObj.count.toLocaleString('id-ID')} data` : '';

                    const groupTr = document.createElement('tr');
                    groupTr.className = 'cat-group-row';
                    groupTr.innerHTML = `
                        <td colspan="7">
                            <div class="cat-group-header">
                                <span>📁 Kategori: <strong>${escapeHtml(r.reff)}</strong></span>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span style="font-size:11px; color:var(--text-muted); font-weight:normal;">${catCnt}</span>
                                    <button class="btn btn-secondary btn-sm" onclick="selectCategoryItem('${escapeHtml(r.reff)}')" style="font-size:11px; padding:2px 8px;">Fokus Kategori Ini ➔</button>
                                </div>
                            </div>
                        </td>
                    `;
                    tbody.appendChild(groupTr);
                }

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="text-align:center; color:var(--text-muted); font-size:12px;">${rowNum}</td>
                    ${!isSingleCat ? `<td><span class="badge-cat" style="cursor:pointer;" onclick="selectCategoryItem('${escapeHtml(r.reff)}')">${escapeHtml(r.reff)}</span></td>` : ''}
                    <td style="color:var(--text-muted); font-size:12px;">${escapeHtml(r.grup || '-')}</td>
                    <td><strong style="color:var(--primary); font-family:monospace; font-size:13px;">${escapeHtml(r.kode)}</strong></td>
                    <td>${escapeHtml(r.teks)}</td>
                    <td style="text-align:center; font-size:12px; color:var(--text-muted);">${escapeHtml(r.status || '')}</td>
                    <td style="text-align:center;">
                        <button class="btn btn-secondary btn-sm" title="Edit" onclick='openEditModal(${JSON.stringify(r)})'>✏️</button>
                        <button class="btn btn-danger btn-sm" title="Hapus" onclick='deleteRef("${escapeHtml(r.reff)}", "${escapeHtml(r.kode)}")'>🗑️</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function resetSearch() {
            document.getElementById('searchInput').value = '';
            loadData(1);
        }

        function changeLimit() {
            currentLimit = parseInt(document.getElementById('selectLimit').value) || 50;
            loadData(1);
        }

        function prevPage() {
            if (currentPage > 1) loadData(currentPage - 1);
        }

        function nextPage() {
            if (currentPage < totalPages) loadData(currentPage + 1);
        }

        function openAddModal() {
            document.getElementById('modalFormTitle').innerText = selectedCategory ? `Tambah Referensi: ${selectedCategory}` : 'Tambah Data Referensi Baru';
            document.getElementById('refIsEdit').value = '0';
            document.getElementById('refOldReff').value = '';
            document.getElementById('refOldKode').value = '';
            document.getElementById('refReff').value = selectedCategory || '';
            document.getElementById('refGrup').value = '-';
            document.getElementById('refKode').value = '';
            document.getElementById('refTeks').value = '';
            document.getElementById('refStatus').value = '';
            document.getElementById('modalForm').style.display = 'flex';

            setTimeout(() => {
                if (selectedCategory) {
                    document.getElementById('refKode').focus();
                } else {
                    document.getElementById('refReff').focus();
                }
            }, 100);
        }

        function openEditModal(r) {
            document.getElementById('modalFormTitle').innerText = 'Edit Data Referensi';
            document.getElementById('refIsEdit').value = '1';
            document.getElementById('refOldReff').value = r.reff;
            document.getElementById('refOldKode').value = r.kode;
            document.getElementById('refReff').value = r.reff;
            document.getElementById('refGrup').value = r.grup || '-';
            document.getElementById('refKode').value = r.kode;
            document.getElementById('refTeks').value = r.teks;
            document.getElementById('refStatus').value = r.status || '';
            document.getElementById('modalForm').style.display = 'flex';
            setTimeout(() => document.getElementById('refTeks').focus(), 100);
        }

        function openImportModal() {
            document.getElementById('modalImport').style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        async function saveRef(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('is_edit', document.getElementById('refIsEdit').value);
            fd.append('old_reff', document.getElementById('refOldReff').value);
            fd.append('old_kode', document.getElementById('refOldKode').value);
            fd.append('reff', document.getElementById('refReff').value.trim());
            fd.append('grup', document.getElementById('refGrup').value.trim());
            fd.append('kode', document.getElementById('refKode').value.trim());
            fd.append('teks', document.getElementById('refTeks').value.trim());
            fd.append('status', document.getElementById('refStatus').value.trim());

            try {
                const res = await fetch('reff.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    closeModal('modalForm');
                    await loadCategories();
                    loadData(currentPage);
                    showSuccess(json.message);
                } else {
                    showError('Gagal simpan: ' + json.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
            }
        }

        async function deleteRef(reff, kode) {
            const ok = await showConfirm(
                `Hapus data referensi:<br><br><strong>Kategori:</strong> ${escapeHtml(reff)}<br><strong>Kode:</strong> <code>${escapeHtml(kode)}</code><br><br>Lanjutkan penghapusan data?`,
                'Konfirmasi Hapus Referensi',
                'Ya, Hapus Data',
                'Batal',
                'warning'
            );
            if (!ok) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('reff', reff);
            fd.append('kode', kode);

            try {
                const res = await fetch('reff.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    showSuccess(json.message);
                    await loadCategories();
                    loadData(currentPage);
                } else {
                    showError('Gagal hapus: ' + json.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
            }
        }

        function exportData() {
            window.location.href = `reff.php?action=export&reff=${encodeURIComponent(selectedCategory)}`;
        }

        async function submitImport(e) {
            e.preventDefault();
            const fileInp = document.getElementById('fileRef');
            if (!fileInp.files.length) return;

            const fd = new FormData();
            fd.append('action', 'import');
            fd.append('file_reff', fileInp.files[0]);

            document.getElementById('importProgress').style.display = 'block';
            document.getElementById('btnSubmitImport').disabled = true;

            try {
                const res = await fetch('reff.php', { method: 'POST', body: fd });
                const json = await res.json();
                document.getElementById('importProgress').style.display = 'none';
                document.getElementById('btnSubmitImport').disabled = false;

                if (json.status === 'OK') {
                    showSuccess(json.message);
                    closeModal('modalImport');
                    await loadCategories();
                    loadData(1);
                } else {
                    showError('Gagal import: ' + json.message);
                }
            } catch (err) {
                document.getElementById('importProgress').style.display = 'none';
                document.getElementById('btnSubmitImport').disabled = false;
                showError('Error import: ' + err.message);
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        window.onload = init;
    </script>
</body>
</html>

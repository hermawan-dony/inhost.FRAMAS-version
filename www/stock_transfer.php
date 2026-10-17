<?php
/**
 * CEISA 4.0 - Production: Stock Transfer (stock_transfer.php)
 * Mengadopsi w_issue.srw, bc_issue_hdr2.srd, bc_issue_dtl.srd
 * Mengelola Pengeluaran, Transfer, Retur, dan Pemasukan Hasil Produksi
 * Mengisi tabel bc_issue_hdr dan bc_issue_dtl dengan validasi klas dan stok sesuai PowerBuilder
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionUser = $_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'SYSTEM');
$action = $_REQUEST['action'] ?? '';

// Definisi Standar Modul Transaksi PowerBuilder (w_issue.srw)
$pbModes = [
    'BB-OUT' => [
        'title' => 'Pengeluaran Bahan Baku ke Produksi',
        'klas' => 'Raw Materials',
        'ab' => 'B',
        'desc' => 'Pengeluaran Raw Materials ke Line Produksi'
    ],
    'BB-RETUR' => [
        'title' => 'Retur Bahan Baku dari Produksi',
        'klas' => 'Raw Materials',
        'ab' => 'A',
        'desc' => 'Pengembalian Sisa Bahan Baku dari Produksi ke Gudang'
    ],
    'BJ-IN' => [
        'title' => 'Entri Hasil Produksi (Barang Jadi Masuk)',
        'klas' => 'Finished Goods',
        'ab' => 'A',
        'desc' => 'Penerimaan Hasil Produksi (Finished Goods) ke Gudang'
    ],
    'BJ-RETUR' => [
        'title' => 'Retur Barang Jadi ke Produksi',
        'klas' => 'Finished Goods',
        'ab' => 'B',
        'desc' => 'Pengembalian Barang Jadi ke Bagian Produksi'
    ],
    'WIP-IN' => [
        'title' => 'Pemasukan WIP dari Produksi',
        'klas' => 'Work In Progress',
        'ab' => 'A',
        'desc' => 'Pemasukan Barang Setengah Jadi (WIP) ke Buffer Stock'
    ],
    'WIP-OUT' => [
        'title' => 'Pengeluaran WIP ke Produksi',
        'klas' => 'Work In Progress',
        'ab' => 'B',
        'desc' => 'Pengeluaran Barang Setengah Jadi (WIP) ke Proses Selanjutnya'
    ],
    'SCRAP-IN' => [
        'title' => 'Tambah Scrap / Sisa Produksi',
        'klas' => 'Wastes',
        'ab' => 'A',
        'desc' => 'Pencatatan Limbah / Scrap / Waste Hasil Produksi'
    ],
    'SCRAP-OUT' => [
        'title' => 'Pengeluaran / Re-Use Scrap ke Produksi',
        'klas' => 'Wastes',
        'ab' => 'B',
        'desc' => 'Pengeluaran Limbah / Scrap untuk Didaur Ulang / Dijual'
    ],
    'SP-OUT' => [
        'title' => 'Issue Part Machine ke Departemen',
        'klas' => 'Part Machine',
        'ab' => 'B',
        'desc' => 'Pengeluaran Sparepart / Part Mesin ke Departemen'
    ],
    'SP-RETUR' => [
        'title' => 'Retur Part Machine',
        'klas' => 'Part Machine',
        'ab' => 'A',
        'desc' => 'Pengembalian Sparepart / Part Mesin ke Gudang'
    ],
    'TOOLS-OUT' => [
        'title' => 'Issue Tools ke Departemen',
        'klas' => 'Tools',
        'ab' => 'B',
        'desc' => 'Pengeluaran Perkakas / Tools ke Departemen'
    ],
    'TOOLS-RETUR' => [
        'title' => 'Retur Tools',
        'klas' => 'Tools',
        'ab' => 'A',
        'desc' => 'Pengembalian Perkakas / Tools ke Gudang'
    ],
    'CUSTOM' => [
        'title' => 'Transfer Kustom / Bebas',
        'klas' => '',
        'ab' => 'B',
        'desc' => 'Pilih Klasifikasi Bebas dari Master Tipe'
    ]
];

// Helper Hitung Stok Realtime Mengadopsi f_stok2.srf PowerBuilder
function get_stok_material($koneksi, $kode_barang, $tgl = null) {
    if (!$tgl) $tgl = date('Y-m-d');
    $yy = substr($tgl, 0, 4);
    $escKode = $koneksi->real_escape_string($kode_barang);
    $escTgl  = $koneksi->real_escape_string($tgl);

    $sql = "SELECT COALESCE(SUM(stok), 0) AS current_stok FROM (
        SELECT SUM(CASE WHEN ab = 'A' THEN QTY_AKTUAL ELSE QTY_AKTUAL * -1 END) AS stok
        FROM bc_1ab2
        WHERE SUBSTR(DATE_FORMAT(tgl_proses, '%Y-%m'), 1, 4) = '$yy' 
          AND kode_barang = '$escKode'
          AND tgl_proses <= '$escTgl'
        UNION ALL
        SELECT stok_awal AS stok
        FROM bc_so0
        WHERE tahun = '$yy' AND kode_barang = '$escKode'
        UNION ALL
        SELECT SUM(CASE WHEN ni.ab = 'A' THEN na.qty ELSE na.qty * -1 END) AS stok
        FROM bc_issue_hdr ni
        JOIN bc_issue_dtl na ON ni.id = na.id_hdr
        WHERE (ni.iscancel IS NULL OR ni.iscancel <> 'Y')
          AND SUBSTR(ni.tgl, 1, 4) = '$yy'
          AND na.kode_barang = '$escKode'
          AND ni.tgl <= '$escTgl'
    ) xx";

    $res = @$koneksi->query($sql);
    if ($res && $row = $res->fetch_assoc()) {
        return floatval($row['current_stok']);
    }
    return 0;
}

// 1. AJAX: LIST DATA HEADER DENGAN FILTER
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $dt1    = trim($_GET['dt1'] ?? date('Y-m-01'));
    $dt2    = trim($_GET['dt2'] ?? date('Y-m-d'));
    $search = trim($_GET['q'] ?? '');
    $dept   = trim($_GET['dept'] ?? '');
    $klas   = trim($_GET['klas'] ?? '');
    $ab     = trim($_GET['ab'] ?? '');

    $where = [];
    if (!empty($dt1) && !empty($dt2)) {
        $where[] = "h.tgl BETWEEN '" . $koneksi->real_escape_string($dt1) . "' AND '" . $koneksi->real_escape_string($dt2) . "'";
    }
    if (!empty($dept)) {
        $where[] = "h.dept = '" . $koneksi->real_escape_string($dept) . "'";
    }
    if (!empty($klas)) {
        $where[] = "h.klas = '" . $koneksi->real_escape_string($klas) . "'";
    }
    if (!empty($ab)) {
        $where[] = "h.AB = '" . $koneksi->real_escape_string($ab) . "'";
    }
    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(h.no_bukti LIKE '%$s%' OR h.wo_no LIKE '%$s%' OR h.fg LIKE '%$s%' OR h.bppb_no LIKE '%$s%' OR h.pic LIKE '%$s%' OR h.sj_no LIKE '%$s%')";
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    $sql = "SELECT 
        h.id, h.tgl, h.no_bukti, h.dept, h.AB, h.klas, h.pic, h.setby, h.iscancel, 
        h.wo_no, h.fg, h.wo_id, h.fg_id, h.bppb_no, h.sj_no, h.note,
        (SELECT COUNT(1) FROM bc_issue_dtl d WHERE d.id_hdr = h.id) AS total_items,
        (SELECT COALESCE(SUM(d.qty), 0) FROM bc_issue_dtl d WHERE d.id_hdr = h.id) AS total_qty
    FROM bc_issue_hdr h
    $whereSql
    ORDER BY h.tgl DESC, h.id DESC
    LIMIT 300";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    // KPI Counters
    $kpiTotal   = $koneksi->query("SELECT COUNT(1) FROM bc_issue_hdr")->fetch_row()[0] ?? 0;
    $kpiBlnIni  = $koneksi->query("SELECT COUNT(1) FROM bc_issue_hdr WHERE tgl >= '" . date('Y-m-01') . "'")->fetch_row()[0] ?? 0;
    $kpiKeluar  = $koneksi->query("SELECT COUNT(1) FROM bc_issue_hdr WHERE AB = 'B' AND (iscancel IS NULL OR iscancel <> 'Y')")->fetch_row()[0] ?? 0;
    $kpiMasuk   = $koneksi->query("SELECT COUNT(1) FROM bc_issue_hdr WHERE AB = 'A' AND (iscancel IS NULL OR iscancel <> 'Y')")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'data' => $data,
        'count' => count($data),
        'kpi' => [
            'total' => (int)$kpiTotal,
            'bulan_ini' => (int)$kpiBlnIni,
            'keluar' => (int)$kpiKeluar,
            'masuk' => (int)$kpiMasuk
        ]
    ]);
    exit;
}

// 2. AJAX: GET DETAIL ITEMS
if ($action === 'detail') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_GET['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Header kosong']);
        exit;
    }

    $escId = $koneksi->real_escape_string($id);
    $hdrRes = $koneksi->query("SELECT * FROM bc_issue_hdr WHERE id = '$escId' LIMIT 1");
    $hdr = $hdrRes ? $hdrRes->fetch_assoc() : null;

    $dtlRes = $koneksi->query("SELECT * FROM bc_issue_dtl WHERE id_hdr = '$escId' ORDER BY seq ASC, id ASC");
    $details = [];
    if ($dtlRes) {
        while ($r = $dtlRes->fetch_assoc()) {
            $details[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'header' => $hdr,
        'details' => $details
    ]);
    exit;
}

// 3. AJAX: CEK STOK REALTIME MATERIAL
if ($action === 'get_stock') {
    header('Content-Type: application/json; charset=utf-8');
    $kode = trim($_GET['kode_barang'] ?? '');
    $tgl  = trim($_GET['tgl'] ?? date('Y-m-d'));
    $stok = get_stok_material($koneksi, $kode, $tgl);
    echo json_encode(['status' => 'OK', 'kode_barang' => $kode, 'stok' => $stok]);
    exit;
}

// 4. AJAX: SIMPAN STOCK TRANSFER BARU (bc_issue_hdr & bc_issue_dtl)
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $no_bukti    = trim($_POST['no_bukti'] ?? '');
    $tgl         = trim($_POST['tgl'] ?? date('Y-m-d'));
    $dept        = trim($_POST['dept'] ?? 'PRODUKSI');
    $ab          = strtoupper(trim($_POST['ab'] ?? 'B')); // 'A' (Masuk/Retur) atau 'B' (Keluar/Transfer)
    $klas        = trim($_POST['klas'] ?? 'Raw Materials');
    $wo_no       = trim($_POST['wo_no'] ?? '');
    $fg          = trim($_POST['fg'] ?? '');
    $pic         = trim($_POST['pic'] ?? '');
    $bppb_no     = trim($_POST['bppb_no'] ?? '');
    $sj_no       = trim($_POST['sj_no'] ?? '');
    $note        = trim($_POST['note'] ?? '');
    $bypassStock = !empty($_POST['bypass_stock']) && $_POST['bypass_stock'] == '1';
    $itemsJson   = $_POST['items'] ?? '[]';

    if (empty($klas)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Klasifikasi (klas) tidak boleh kosong!']);
        exit;
    }

    if (empty($no_bukti)) {
        $prefix = ($ab === 'A') ? 'RCV-' : 'ISS-';
        $no_bukti = $prefix . date('ymd') . '-' . strtoupper(substr(uniqid(), -4));
    }

    $items = json_decode($itemsJson, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Daftar item transfer tidak boleh kosong.']);
        exit;
    }

    // Validasi Stok jika AB = 'B' (Pengeluaran) dan checkbox bypass_stock = false (Logika PB w_issue.srw baris 286-298)
    if ($ab === 'B' && !$bypassStock) {
        foreach ($items as $it) {
            $kdBrg = trim($it['kode_barang'] ?? '');
            $qty   = floatval($it['qty'] ?? 0);
            if (!empty($kdBrg) && $qty > 0) {
                $curStok = get_stok_material($koneksi, $kdBrg, $tgl);
                if ($qty > $curStok) {
                    echo json_encode([
                        'status' => 'ERROR',
                        'message' => "Validasi Stok Gagal: Qty keluar item '$kdBrg' ($qty) melebihi stok yang tersedia ($curStok)! Aktifkan 'Bypass Cek Stok' jika ingin memaksakan pengeluaran."
                    ]);
                    exit;
                }
            }
        }
    }

    $escNoBukti = $koneksi->real_escape_string($no_bukti);
    $escTgl     = $koneksi->real_escape_string($tgl);
    $escDept    = $koneksi->real_escape_string($dept);
    $escAb      = $koneksi->real_escape_string($ab);
    $escKlas    = $koneksi->real_escape_string($klas);
    $escWo      = $koneksi->real_escape_string($wo_no);
    $escFg      = $koneksi->real_escape_string($fg);
    $escPic     = $koneksi->real_escape_string($pic);
    $escBppb    = $koneksi->real_escape_string($bppb_no);
    $escSj      = $koneksi->real_escape_string($sj_no);
    $escNote    = $koneksi->real_escape_string($note);
    $escUser    = $koneksi->real_escape_string($sessionUser);

    $idHdr = 'ISS' . date('YmdHis') . rand(100, 999);

    $koneksi->begin_transaction();
    try {
        $insHdr = "INSERT INTO bc_issue_hdr 
            (id, tgl, no_bukti, dept, AB, klas, pic, setby, iscancel, wo_no, fg, bppb_no, sj_no, note)
            VALUES 
            ('$idHdr', '$escTgl', '$escNoBukti', '$escDept', '$escAb', '$escKlas', '$escPic', '$escUser', 'N', '$escWo', '$escFg', '$escBppb', '$escSj', '$escNote')";
        if (!$koneksi->query($insHdr)) {
            throw new Exception("Gagal insert bc_issue_hdr: " . $koneksi->error);
        }

        $seq = 1;
        foreach ($items as $it) {
            $kdBrg   = $koneksi->real_escape_string(trim($it['kode_barang'] ?? ''));
            $nmBrg   = $koneksi->real_escape_string(trim($it['nama_barang'] ?? ''));
            $qty     = floatval($it['qty'] ?? 0);
            $sat     = $koneksi->real_escape_string(trim($it['sat'] ?? 'PCE'));
            $itNote  = $koneksi->real_escape_string(trim($it['note'] ?? ''));
            $harga   = floatval($it['harga'] ?? 0);
            $idDtl   = 'ISD' . date('YmdHis') . rand(100, 999) . $seq;

            if (!empty($kdBrg) && $qty > 0) {
                $insDtl = "INSERT INTO bc_issue_dtl 
                    (id, id_hdr, kode_barang, nama_barang, qty, sat, note, wo, setby, seq, harga)
                    VALUES 
                    ('$idDtl', '$idHdr', '$kdBrg', '$nmBrg', $qty, '$sat', '$itNote', '$escWo', '$escUser', $seq, $harga)";
                if (!$koneksi->query($insDtl)) {
                    throw new Exception("Gagal insert bc_issue_dtl: " . $koneksi->error);
                }
                $seq++;
            }
        }

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK',
            'message' => "Transaksi $no_bukti ($klas - Arah: " . ($ab === 'A' ? 'Pemasukan/Retur' : 'Pengeluaran/Transfer') . ") berhasil disimpan.",
            'id' => $idHdr
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}

// 5. AJAX: BATALKAN / HAPUS TRANSAKSI
if ($action === 'cancel' || $action === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = trim($_POST['id'] ?? '');
    if (empty($id)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'ID Header kosong']);
        exit;
    }

    $escId = $koneksi->real_escape_string($id);
    if ($action === 'delete') {
        $koneksi->query("DELETE FROM bc_issue_dtl WHERE id_hdr = '$escId'");
        $koneksi->query("DELETE FROM bc_issue_hdr WHERE id = '$escId'");
        echo json_encode(['status' => 'OK', 'message' => 'Data transfer berhasil dihapus secara permanen.']);
    } else {
        $koneksi->query("UPDATE bc_issue_hdr SET iscancel = 'Y' WHERE id = '$escId'");
        echo json_encode(['status' => 'OK', 'message' => 'Status transaksi transfer berhasil dibatalkan (Canceled).']);
    }
    exit;
}

// 6. AJAX: SEARCH BARANG UNTUK AUTOCOMPLETE & CEK STOK
if ($action === 'search_material') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    $tgl = trim($_GET['tgl'] ?? date('Y-m-d'));
    $escQ = $koneksi->real_escape_string($q);
    $res = $koneksi->query("SELECT KODE_BARANG, URAIAN, KODE_SATUAN, UNIT_PRICE, KLAS FROM bc_material WHERE KODE_BARANG LIKE '%$escQ%' OR URAIAN LIKE '%$escQ%' LIMIT 25");
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $curStok = get_stok_material($koneksi, $r['KODE_BARANG'], $tgl);
            $items[] = [
                'kode_barang' => $r['KODE_BARANG'],
                'uraian' => $r['URAIAN'],
                'satuan' => $r['KODE_SATUAN'],
                'harga' => floatval($r['UNIT_PRICE'] ?? 0),
                'klas' => $r['KLAS'],
                'stok' => $curStok
            ];
        }
    }
    echo json_encode(['status' => 'OK', 'data' => $items]);
    exit;
}

// 7. QUERY KLASIFIKASI DARI bc_tipe (WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx)
$klasList = [];
$qKlas = $koneksi->query("SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') AND tipe <> '-- SEMUA KLAS --' ORDER BY idx");
if ($qKlas) {
    while ($rk = $qKlas->fetch_assoc()) {
        $klasList[] = $rk;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Production - Stock Transfer (CEISA 4.0 & IT Inventory)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/sweetalert2.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <script src="/assets/sweetalert-theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root, html[data-theme="light"], body[data-theme="light"] {
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --border: #e8e8e8;
            --text: rgba(0, 0, 0, 0.75);
            --text-heading: #262626;
            --text-secondary: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #f5222d;
            --row-hover: #f0f7ff;
            --table-head-bg: #fafafa;
            --bg-filter: #fafafa;
            --bg-input: #ffffff;
            --border-input: #d9d9d9;
            color-scheme: light;
        }
        [data-theme='dark'], html[data-theme="dark"], body[data-theme="dark"] {
            --bg: #141414;
            --card-bg: #1f1f1f;
            --border: #303030;
            --text: rgba(255, 255, 255, 0.85);
            --text-heading: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.45);
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --success: #49aa19;
            --warning: #d89614;
            --danger: #a61d24;
            --row-hover: #262638;
            --table-head-bg: #1d1d1d;
            --bg-filter: #191919;
            --bg-input: #141414;
            --border-input: #434343;
            color-scheme: dark;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', 'Source Sans Pro', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            font-size: 10.5px;
            line-height: 1.45;
            padding: 6px 10px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .kpi-container {
            display: flex;
            gap: 6px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }
        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 2px 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 24px;
        }
        .kpi-title { font-size: 9px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; }
        .kpi-value { font-size: 11px; font-weight: 700; color: var(--text-heading); }
        .kpi-icon { font-size: 11px; opacity: 0.8; }
        
        .toolbar-card {
            background: var(--bg-filter);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 5px 8px;
            margin-bottom: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .toolbar-left span { font-size: 9px; font-weight: 600; text-transform: uppercase; color: var(--text-secondary); }
        
        input, select, button {
            font-family: inherit;
            font-size: 9.5px;
            height: 22px;
            padding: 1px 6px;
            border-radius: 2px;
            border: 1px solid var(--border-input);
            background: var(--bg-input);
            color: var(--text);
            outline: none;
        }
        input:focus, select:focus { border-color: var(--primary); }
        select option {
            background-color: var(--card-bg);
            color: var(--text);
        }
        .btn-primary, .ant-btn-primary {
            background: var(--primary);
            color: #fff;
            border: 1px solid var(--primary);
            cursor: pointer;
            font-weight: 500;
            font-size: 10px;
            height: 22px;
            padding: 0 7px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            line-height: 1.4;
        }
        .btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color: #fff; }
        .btn-default, .ant-btn {
            background: var(--card-bg);
            color: var(--text);
            border: 1px solid var(--border-input);
            cursor: pointer;
            font-size: 10px;
            height: 22px;
            padding: 0 7px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            line-height: 1.4;
        }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }
        
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            overflow: auto;
            max-height: calc(100vh - 170px);
        }
        table { width: 100%; border-collapse: collapse; font-size: 10px; text-align: left; }
        th {
            position: sticky;
            top: 0;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border);
            padding: 4px 6px;
            font-size: 9.5px;
            text-align: left;
            font-weight: 600;
            color: var(--text-heading);
            white-space: nowrap;
            z-index: 2;
        }
        td {
            padding: 3px 6px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            vertical-align: middle;
            color: var(--text);
        }
        tr:hover td { background-color: var(--row-hover) !important; }
        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
        }
        .badge-active { background: rgba(82, 196, 26, 0.12); color: var(--success); }
        .badge-cancel { background: rgba(245, 34, 45, 0.12); color: var(--danger); }
        .badge-ab-b { background: rgba(245, 34, 45, 0.12); color: var(--danger); border: 1px solid rgba(245,34,45,0.25); }
        .badge-ab-a { background: rgba(82, 196, 26, 0.12); color: var(--success); border: 1px solid rgba(82,196,26,0.25); }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            width: 92%;
            max-width: 950px;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .modal-header {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-heading);
        }
        .modal-header h3 { font-size: 11.5px; font-weight: 600; margin: 0; }
        .modal-body { padding: 8px 12px; overflow-y: auto; flex: 1; font-size: 10px; }
        .modal-footer {
            padding: 6px 12px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 6px;
            margin-bottom: 8px;
        }
        .form-group label {
            display: block;
            margin-bottom: 2px;
            color: var(--text-secondary);
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .form-group input, .form-group select { width: 100%; }
        
        .mode-selector {
            background: rgba(24,144,255,0.06);
            border: 1px solid #91d5ff;
            border-radius: 3px;
            padding: 6px 10px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 10px;
        }
    </style>
</head>
<body>

    <!-- KPI Metric Cards -->
    <div class="kpi-container">
        <div class="kpi-card">
            <span class="kpi-title">Total Mutasi Produksi</span>
            <span class="kpi-value" id="kpi-total">0</span>
            <i class="fa-solid fa-boxes-stacked kpi-icon" style="color: #1890ff;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Mutasi Bulan Ini</span>
            <span class="kpi-value" id="kpi-bln">0</span>
            <i class="fa-solid fa-calendar-check kpi-icon" style="color: #52c41a;"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Pengeluaran / Transfer (AB=B)</span>
            <span class="kpi-value" id="kpi-keluar" style="color:var(--danger);">0</span>
            <i class="fa-solid fa-arrow-right-from-bracket kpi-icon" style="color: var(--danger);"></i>
        </div>
        <div class="kpi-card">
            <span class="kpi-title">Pemasukan / Retur (AB=A)</span>
            <span class="kpi-value" id="kpi-masuk" style="color:var(--success);">0</span>
            <i class="fa-solid fa-arrow-right-to-bracket kpi-icon" style="color: var(--success);"></i>
        </div>
    </div>

    <!-- Toolbar Filters -->
    <div class="toolbar-card">
        <div class="toolbar-left">
            <span style="font-weight:600; color:var(--text-secondary);"><i class="fa-solid fa-filter"></i> Periode:</span>
            <input type="date" id="filter-dt1" value="<?= date('Y-m-01') ?>">
            <span>s/d</span>
            <input type="date" id="filter-dt2" value="<?= date('Y-m-d') ?>">
            <select id="filter-dept">
                <option value="">Semua Departemen</option>
                <option value="PRODUKSI">Produksi</option>
                <option value="ASSEMBLY">Assembly</option>
                <option value="CUTTING">Cutting</option>
                <option value="STITCHING">Stitching</option>
                <option value="PACKING">Packing</option>
                <option value="SUBKONTRAK">Subkontrak</option>
            </select>
            <select id="filter-klas">
                <option value="">Semua Klasifikasi</option>
                <?php foreach ($klasList as $k): ?>
                    <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-ab">
                <option value="">Semua Arah</option>
                <option value="B">Keluar / Transfer (B)</option>
                <option value="A">Masuk / Retur (A)</option>
            </select>
            <input type="text" id="filter-search" placeholder="Cari No Bukti / WO / FG..." style="width: 180px;" onkeyup="if(event.key==='Enter') loadData()">
            <button class="btn-default" onclick="loadData()"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
        </div>
        <div class="toolbar-right">
            <button class="btn-primary" onclick="openNewModal()"><i class="fa-solid fa-plus"></i> Transaksi Transfer Baru</button>
            <button class="btn-default" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Ekspor Excel</button>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 35px; text-align: center;">#</th>
                    <th>No Bukti</th>
                    <th>Tanggal</th>
                    <th style="text-align: center;">Arah (AB)</th>
                    <th>Klasifikasi</th>
                    <th>Departemen</th>
                    <th>No WO / SPK</th>
                    <th>Finished Goods</th>
                    <th style="text-align: right;">Total Item</th>
                    <th style="text-align: right;">Total Qty</th>
                    <th>No BPPB / SJ</th>
                    <th>PIC / Set By</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: center; width: 110px;">Aksi</th>
                </tr>
            </thead>
            <tbody id="table-body">
                <tr><td colspan="14" style="text-align: center; padding: 20px;">Memuat data...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Modal Form Transaksi Baru -->
    <div class="modal" id="modal-form">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size: 13px; font-weight: 700;" id="formModalTitle"><i class="fa-solid fa-file-circle-plus"></i> Form Pengeluaran / Stock Transfer</h3>
                <span style="cursor: pointer; font-size: 16px;" onclick="closeModal('modal-form')">&times;</span>
            </div>
            <div class="modal-body">
                <!-- Mode Transaksi PowerBuilder (w_issue.srw) -->
                <div class="mode-selector">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-weight:700; color:var(--primary);"><i class="fa-solid fa-sliders"></i> Jenis Transaksi (PowerBuilder):</span>
                        <select id="form-mode" onchange="onModeChange()" style="font-weight:600;">
                            <?php foreach ($pbModes as $mKey => $mVal): ?>
                                <option value="<?= $mKey ?>" data-klas="<?= htmlspecialchars($mVal['klas']) ?>" data-ab="<?= $mVal['ab'] ?>" data-title="<?= htmlspecialchars($mVal['title']) ?>">
                                    <?= htmlspecialchars($mKey . ' - ' . $mVal['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="modeBadgeBox" style="display:flex; align-items:center; gap:8px;">
                        <span id="badgeArah" class="badge badge-ab-b">📤 PENGELUARAN (AB=B)</span>
                        <span id="badgeKlas" class="badge" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff;">Raw Materials</span>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>No Bukti Transfer</label>
                        <input type="text" id="form-no-bukti" placeholder="Otomatis dibuat jika kosong">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Transaksi *</label>
                        <input type="date" id="form-tgl" value="<?= date('Y-m-d') ?>" onchange="onDateChange()">
                    </div>
                    <div class="form-group">
                        <label>Arah Aliran (AB) *</label>
                        <select id="form-ab">
                            <option value="B">B - Pengeluaran / Transfer Keluar</option>
                            <option value="A">A - Pemasukan / Retur Masuk</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Klasifikasi Barang (klas) *</label>
                        <select id="form-klas">
                            <?php foreach ($klasList as $k): ?>
                                <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Departemen Tujuan / Asal *</label>
                        <select id="form-dept">
                            <option value="PRODUKSI">Produksi</option>
                            <option value="ASSEMBLY">Assembly</option>
                            <option value="CUTTING">Cutting</option>
                            <option value="STITCHING">Stitching</option>
                            <option value="PACKING">Packing</option>
                            <option value="SUBKONTRAK">Subkontrak</option>
                            <option value="MAINTENANCE">Maintenance</option>
                            <option value="WAREHOUSE">Warehouse</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>No Work Order / SPK (WO)</label>
                        <input type="text" id="form-wo" placeholder="Contoh: WO-2026-001">
                    </div>
                    <div class="form-group">
                        <label>Target Barang Jadi (FG)</label>
                        <input type="text" id="form-fg" placeholder="Nama / Kode Finished Goods">
                    </div>
                    <div class="form-group">
                        <label>No BPPB</label>
                        <input type="text" id="form-bppb" placeholder="Nomor Bukti Pengeluaran Barang">
                    </div>
                    <div class="form-group">
                        <label>No Surat Jalan (SJ)</label>
                        <input type="text" id="form-sj" placeholder="Nomor Surat Jalan jika ada">
                    </div>
                    <div class="form-group">
                        <label>PIC / Mandor Penerima</label>
                        <input type="text" id="form-pic" placeholder="Nama PIC penanggung jawab">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Catatan Transaksi</label>
                        <input type="text" id="form-note" placeholder="Keterangan tambahan mutasi transfer...">
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div style="font-weight:600; font-size:11.5px;"><i class="fa-solid fa-list-check"></i> Daftar Item Material yang Dimutasikan:</div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="btn-default" onclick="addItemRow()"><i class="fa-solid fa-plus"></i> Tambah Item</button>
                    </div>
                </div>

                <div style="border: 1px solid var(--border); border-radius: 4px; max-height: 250px; overflow-y: auto;">
                    <table style="width: 100%;">
                        <thead>
                            <tr style="background: var(--bg);">
                                <th style="width: 25px; text-align: center;">#</th>
                                <th style="width: 160px;">Kode Barang *</th>
                                <th>Nama / Uraian Barang</th>
                                <th style="width: 90px; text-align: right;">Stok Tersedia</th>
                                <th style="width: 90px; text-align: right;">Qty Transfer *</th>
                                <th style="width: 70px;">Satuan</th>
                                <th style="width: 100px; text-align: right;">Harga (IDR)</th>
                                <th>Keterangan Item</th>
                                <th style="width: 35px; text-align: center;">Hapus</th>
                            </tr>
                        </thead>
                        <tbody id="items-body"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <!-- Checkbox cbx_2 dari PowerBuilder w_issue.srw -->
                <label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:11.5px; cursor:pointer;" title="Mengadopsi cbx_2 w_issue.srw: Izinkan transfer jika stok minus/tidak cukup">
                    <input type="checkbox" id="form-bypass-stok" value="1">
                    <span>Abaikan / Bypass Cek Stok Minus (cbx_2)</span>
                </label>
                <div style="display:flex; gap:8px;">
                    <button class="btn-default" onclick="closeModal('modal-form')">Batal</button>
                    <button class="btn-primary" onclick="submitForm()"><i class="fa-solid fa-floppy-disk"></i> Simpan Transaksi</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal View Detail -->
    <div class="modal" id="modal-detail">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size: 13px; font-weight: 700;" id="detail-title"><i class="fa-solid fa-circle-info"></i> Detail Stock Transfer</h3>
                <span style="cursor: pointer; font-size: 16px;" onclick="closeModal('modal-detail')">&times;</span>
            </div>
            <div class="modal-body" id="detail-body">
                Memuat rincian item...
            </div>
            <div class="modal-footer">
                <div></div>
                <button class="btn-default" onclick="closeModal('modal-detail')">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        let currentData = [];

        function onModeChange() {
            const sel = document.getElementById('form-mode');
            const opt = sel.options[sel.selectedIndex];
            const klas = opt.getAttribute('data-klas');
            const ab = opt.getAttribute('data-ab');
            const title = opt.getAttribute('data-title');

            if (klas) {
                document.getElementById('form-klas').value = klas;
            }
            if (ab) {
                document.getElementById('form-ab').value = ab;
            }
            document.getElementById('formModalTitle').innerHTML = `<i class="fa-solid fa-file-circle-plus"></i> ${title}`;

            // Update badge tampilan
            const badgeArah = document.getElementById('badgeArah');
            if (ab === 'B') {
                badgeArah.className = 'badge badge-ab-b';
                badgeArah.innerText = '📤 PENGELUARAN (AB=B)';
            } else {
                badgeArah.className = 'badge badge-ab-a';
                badgeArah.innerText = '📥 PEMASUKAN / RETUR (AB=A)';
            }
            document.getElementById('badgeKlas').innerText = klas || document.getElementById('form-klas').value;
        }

        async function onDateChange() {
            // Perbarui stok untuk setiap item di tabel jika tanggal transaksi berubah
            const tgl = document.getElementById('form-tgl').value;
            const rows = document.querySelectorAll('#items-body tr');
            for (const tr of rows) {
                const kode = tr.querySelector('.item-kode')?.value.trim();
                if (kode) {
                    try {
                        const res = await fetch(`stock_transfer.php?action=get_stock&kode_barang=${encodeURIComponent(kode)}&tgl=${tgl}`);
                        const json = await res.json();
                        if (json.status === 'OK') {
                            tr.querySelector('.item-stok').value = json.stok;
                            tr.querySelector('.lbl-stok').innerText = Number(json.stok).toLocaleString('id-ID', {minimumFractionDigits: 2});
                        }
                    } catch (e) {}
                }
            }
        }

        async function loadData() {
            const dt1 = document.getElementById('filter-dt1').value;
            const dt2 = document.getElementById('filter-dt2').value;
            const dept = document.getElementById('filter-dept').value;
            const klas = document.getElementById('filter-klas').value;
            const ab = document.getElementById('filter-ab').value;
            const q = document.getElementById('filter-search').value;

            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 20px;"><i class="fa-solid fa-spinner fa-spin"></i> Memuat data transaksi...</td></tr>';

            try {
                const url = `stock_transfer.php?action=list&dt1=${dt1}&dt2=${dt2}&dept=${encodeURIComponent(dept)}&klas=${encodeURIComponent(klas)}&ab=${encodeURIComponent(ab)}&q=${encodeURIComponent(q)}`;
                const res = await fetch(url);
                const json = await res.json();
                if (json.status === 'OK') {
                    currentData = json.data;
                    document.getElementById('kpi-total').innerText = Number(json.kpi.total).toLocaleString('id-ID');
                    document.getElementById('kpi-bln').innerText = Number(json.kpi.bulan_ini).toLocaleString('id-ID');
                    document.getElementById('kpi-keluar').innerText = Number(json.kpi.keluar).toLocaleString('id-ID');
                    document.getElementById('kpi-masuk').innerText = Number(json.kpi.masuk).toLocaleString('id-ID');

                    renderTable(json.data);
                } else {
                    tbody.innerHTML = `<tr><td colspan="14" style="text-align: center; color: red;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="14" style="text-align: center; color: red;">Terjadi kesalahan: ${err.message}</td></tr>`;
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('table-body');
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 20px;">Tidak ada transaksi ditemukan untuk filter ini.</td></tr>';
                return;
            }

            tbody.innerHTML = data.map((r, i) => {
                const isBatal = r.iscancel === 'Y';
                const statusBadge = isBatal 
                    ? '<span class="badge badge-cancel">BATAL</span>' 
                    : '<span class="badge badge-active">AKTIF</span>';

                const abBadge = r.AB === 'B' 
                    ? '<span class="badge badge-ab-b">📤 Keluar (B)</span>' 
                    : '<span class="badge badge-ab-a">📥 Masuk (A)</span>';

                return `
                    <tr>
                        <td style="text-align: center; color: var(--text-secondary);">${i + 1}</td>
                        <td style="font-weight: 600; color: var(--primary); cursor: pointer;" onclick="viewDetail('${r.id}')">${r.no_bukti || '-'}</td>
                        <td>${r.tgl || '-'}</td>
                        <td style="text-align: center;">${abBadge}</td>
                        <td><span style="font-weight: 500;">${r.klas || '-'}</span></td>
                        <td>${r.dept || '-'}</td>
                        <td>${r.wo_no || '-'}</td>
                        <td>${r.fg || '-'}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_items || 0).toLocaleString('id-ID')}</td>
                        <td style="text-align: right; font-weight: 600;">${Number(r.total_qty || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                        <td>${r.bppb_no || r.sj_no || '-'}</td>
                        <td>${r.pic || r.setby || '-'}</td>
                        <td style="text-align: center;">${statusBadge}</td>
                        <td style="text-align: center;">
                            <button class="btn-default" style="padding: 2px 6px;" title="Lihat Rincian Item" onclick="viewDetail('${r.id}')"><i class="fa-solid fa-eye"></i></button>
                            ${!isBatal ? `<button class="btn-default" style="padding: 2px 6px; color: var(--danger);" title="Batalkan Transaksi" onclick="cancelTransfer('${r.id}')"><i class="fa-solid fa-ban"></i></button>` : ''}
                            <button class="btn-default" style="padding: 2px 6px; color: var(--text-secondary);" title="Hapus Data" onclick="deleteTransfer('${r.id}')"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function viewDetail(id) {
            document.getElementById('modal-detail').style.display = 'flex';
            const body = document.getElementById('detail-body');
            body.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memuat rincian transaksi...';

            try {
                const res = await fetch(`stock_transfer.php?action=detail&id=${encodeURIComponent(id)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    const h = json.header;
                    const abText = h.AB === 'B' ? '📤 PENGELUARAN (B)' : '📥 PEMASUKAN/RETUR (A)';
                    document.getElementById('detail-title').innerHTML = `<i class="fa-solid fa-circle-info"></i> Bukti: ${h.no_bukti} (${h.tgl}) - ${abText}`;
                    
                    let html = `
                        <div style="background: var(--bg); padding: 10px; border-radius: 4px; margin-bottom: 12px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                            <div><strong style="color:var(--text-secondary);">Klasifikasi:</strong> ${h.klas}</div>
                            <div><strong style="color:var(--text-secondary);">Departemen:</strong> ${h.dept}</div>
                            <div><strong style="color:var(--text-secondary);">Work Order:</strong> ${h.wo_no || '-'}</div>
                            <div><strong style="color:var(--text-secondary);">Barang Jadi:</strong> ${h.fg || '-'}</div>
                            <div><strong style="color:var(--text-secondary);">No BPPB:</strong> ${h.bppb_no || '-'}</div>
                            <div><strong style="color:var(--text-secondary);">No SJ:</strong> ${h.sj_no || '-'}</div>
                            <div><strong style="color:var(--text-secondary);">PIC / Petugas:</strong> ${h.pic || h.setby || '-'}</div>
                            <div><strong style="color:var(--text-secondary);">Catatan:</strong> ${h.note || '-'}</div>
                        </div>
                        <table style="width: 100%;">
                            <thead>
                                <tr style="background: var(--bg);">
                                    <th style="width: 30px;">#</th>
                                    <th>Kode Barang</th>
                                    <th>Nama Barang</th>
                                    <th style="text-align: right;">Qty Transfer</th>
                                    <th>Satuan</th>
                                    <th style="text-align: right;">Harga Satuan</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    if (json.details.length === 0) {
                        html += `<tr><td colspan="7" style="text-align: center; padding: 12px;">Tidak ada item detail.</td></tr>`;
                    } else {
                        html += json.details.map((d, i) => `
                            <tr>
                                <td style="text-align: center;">${i + 1}</td>
                                <td style="font-weight: 600;">${d.kode_barang}</td>
                                <td>${d.nama_barang || '-'}</td>
                                <td style="text-align: right; font-weight: 600;">${Number(d.qty || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                                <td>${d.sat || '-'}</td>
                                <td style="text-align: right;">${Number(d.harga || 0).toLocaleString('id-ID', {minimumFractionDigits: 2})}</td>
                                <td>${d.note || '-'}</td>
                            </tr>
                        `).join('');
                    }

                    html += `</tbody></table>`;
                    body.innerHTML = html;
                } else {
                    body.innerHTML = `<span style="color:red;">${json.message}</span>`;
                }
            } catch (err) {
                body.innerHTML = `<span style="color:red;">Error: ${err.message}</span>`;
            }
        }

        function openNewModal() {
            document.getElementById('form-mode').selectedIndex = 0;
            onModeChange();
            document.getElementById('form-no-bukti').value = '';
            document.getElementById('form-tgl').value = new Date().toISOString().slice(0, 10);
            document.getElementById('form-wo').value = '';
            document.getElementById('form-fg').value = '';
            document.getElementById('form-bppb').value = '';
            document.getElementById('form-sj').value = '';
            document.getElementById('form-pic').value = '';
            document.getElementById('form-note').value = '';
            document.getElementById('form-bypass-stok').checked = false;
            document.getElementById('items-body').innerHTML = '';
            addItemRow();
            document.getElementById('modal-form').style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function addItemRow() {
            const tbody = document.getElementById('items-body');
            const rowIdx = tbody.children.length;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="text-align: center;">${rowIdx + 1}</td>
                <td><input type="text" class="item-kode" placeholder="Ketik kode..." onchange="lookupItem(this)"></td>
                <td><input type="text" class="item-nama" placeholder="Nama barang"></td>
                <td style="text-align: right;">
                    <span class="lbl-stok" style="font-weight:600; color:var(--text-secondary);">0.00</span>
                    <input type="hidden" class="item-stok" value="0">
                </td>
                <td><input type="number" step="0.01" class="item-qty" value="1.00" style="text-align: right;" onchange="validateRowStock(this)"></td>
                <td><input type="text" class="item-sat" value="PCE" style="width: 60px;"></td>
                <td><input type="number" step="0.01" class="item-harga" value="0.00" style="text-align: right;"></td>
                <td><input type="text" class="item-note" placeholder="Note"></td>
                <td style="text-align: center;"><button type="button" class="btn-default" style="color:red; padding: 2px 5px;" onclick="this.closest('tr').remove()">&times;</button></td>
            `;
            tbody.appendChild(tr);
        }

        async function lookupItem(input) {
            const val = input.value.trim();
            if (!val) return;
            const tgl = document.getElementById('form-tgl').value;
            try {
                const res = await fetch(`stock_transfer.php?action=search_material&q=${encodeURIComponent(val)}&tgl=${tgl}`);
                const json = await res.json();
                if (json.status === 'OK' && json.data.length > 0) {
                    const row = input.closest('tr');
                    const item = json.data[0];
                    row.querySelector('.item-kode').value = item.kode_barang;
                    row.querySelector('.item-nama').value = item.uraian;
                    row.querySelector('.item-sat').value = item.satuan || 'PCE';
                    row.querySelector('.item-harga').value = item.harga || 0;
                    row.querySelector('.item-stok').value = item.stok || 0;
                    row.querySelector('.lbl-stok').innerText = Number(item.stok || 0).toLocaleString('id-ID', {minimumFractionDigits: 2});
                    validateRowStock(row.querySelector('.item-qty'));
                }
            } catch (e) {}
        }

        function validateRowStock(qtyInput) {
            const tr = qtyInput.closest('tr');
            const ab = document.getElementById('form-ab').value;
            const bypass = document.getElementById('form-bypass-stok').checked;
            const qty = parseFloat(qtyInput.value) || 0;
            const stok = parseFloat(tr.querySelector('.item-stok').value) || 0;

            if (ab === 'B' && !bypass && qty > stok) {
                qtyInput.style.borderColor = 'var(--danger)';
                qtyInput.style.background = 'rgba(245,34,45,0.08)';
                qtyInput.title = `Peringatan: Qty keluar (${qty}) melebihi stok yang tersedia (${stok})!`;
            } else {
                qtyInput.style.borderColor = '';
                qtyInput.style.background = '';
                qtyInput.title = '';
            }
        }

        async function submitForm() {
            const noBukti = document.getElementById('form-no-bukti').value.trim();
            const tgl     = document.getElementById('form-tgl').value;
            const ab      = document.getElementById('form-ab').value;
            const klas    = document.getElementById('form-klas').value;
            const dept    = document.getElementById('form-dept').value;
            const wo      = document.getElementById('form-wo').value.trim();
            const fg      = document.getElementById('form-fg').value.trim();
            const bppb    = document.getElementById('form-bppb').value.trim();
            const sj      = document.getElementById('form-sj').value.trim();
            const pic     = document.getElementById('form-pic').value.trim();
            const note    = document.getElementById('form-note').value.trim();
            const bypass  = document.getElementById('form-bypass-stok').checked ? '1' : '0';

            const rows = document.querySelectorAll('#items-body tr');
            const items = [];
            let stockExceededItem = null;

            rows.forEach(tr => {
                const kode = tr.querySelector('.item-kode')?.value.trim();
                const nama = tr.querySelector('.item-nama')?.value.trim();
                const qty  = parseFloat(tr.querySelector('.item-qty')?.value) || 0;
                const sat  = tr.querySelector('.item-sat')?.value.trim();
                const harga = parseFloat(tr.querySelector('.item-harga')?.value) || 0;
                const itNote = tr.querySelector('.item-note')?.value.trim();
                const stok  = parseFloat(tr.querySelector('.item-stok')?.value) || 0;

                if (kode && qty > 0) {
                    if (ab === 'B' && bypass !== '1' && qty > stok) {
                        stockExceededItem = { kode, qty, stok };
                    }
                    items.push({ kode_barang: kode, nama_barang: nama, qty, sat, harga, note: itNote });
                }
            });

            if (items.length === 0) {
                Swal.fire('Validasi', 'Silakan masukkan minimal 1 item material dengan Qty > 0.', 'warning');
                return;
            }

            if (stockExceededItem) {
                Swal.fire({
                    title: 'Validasi Stok Gagal!',
                    html: `Qty keluar untuk item <strong>${stockExceededItem.kode}</strong> (${stockExceededItem.qty}) melebihi stok yang tersedia (${stockExceededItem.stok})!<br><br>Aktifkan <strong>Bypass Cek Stok Minus</strong> jika tetap ingin memaksakan pengeluaran.`,
                    icon: 'error'
                });
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('no_bukti', noBukti);
            formData.append('tgl', tgl);
            formData.append('ab', ab);
            formData.append('klas', klas);
            formData.append('dept', dept);
            formData.append('wo_no', wo);
            formData.append('fg', fg);
            formData.append('bppb_no', bppb);
            formData.append('sj_no', sj);
            formData.append('pic', pic);
            formData.append('note', note);
            formData.append('bypass_stock', bypass);
            formData.append('items', JSON.stringify(items));

            try {
                const res = await fetch('stock_transfer.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil Disimpan', json.message, 'success');
                    closeModal('modal-form');
                    loadData();
                } else {
                    Swal.fire('Gagal Menyimpan', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Jaringan', err.message, 'error');
            }
        }

        async function cancelTransfer(id) {
            const ok = await Swal.fire({
                title: 'Konfirmasi Batal',
                text: 'Yakin ingin membatalkan (void) transaksi transfer ini?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Batalkan',
                cancelButtonText: 'Tutup'
            });
            if (!ok.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'cancel');
            fd.append('id', id);
            try {
                const res = await fetch('stock_transfer.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil', json.message, 'success');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        async function deleteTransfer(id) {
            const ok = await Swal.fire({
                title: 'Hapus Transaksi',
                html: '<span style="color:var(--danger); font-weight:600;">PERINGATAN:</span> Menghapus data akan menghapus seluruh detail item transaksi ini secara permanen dari tabel bc_issue_hdr dan bc_issue_dtl. Lanjutkan?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ff4d4f',
                cancelButtonColor: '#8c8c8c',
                confirmButtonText: 'Ya, Hapus Permanen',
                cancelButtonText: 'Batal'
            });
            if (!ok.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            try {
                const res = await fetch('stock_transfer.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire('Berhasil Dihapus', json.message, 'success');
                    loadData();
                } else {
                    Swal.fire('Error', json.message, 'error');
                }
            } catch (e) { Swal.fire('Error', e.message, 'error'); }
        }

        function exportExcel() {
            if (!currentData || currentData.length === 0) {
                Swal.fire('Informasi', 'Tidak ada data untuk diekspor.', 'info');
                return;
            }
            const dataToExport = currentData.map(r => ({
                'No Bukti': r.no_bukti,
                'Tanggal': r.tgl,
                'Arah (AB)': r.AB === 'B' ? 'Pengeluaran (B)' : 'Pemasukan (A)',
                'Klasifikasi': r.klas,
                'Departemen': r.dept,
                'No WO / SPK': r.wo_no || '',
                'Finished Goods': r.fg || '',
                'Total Item': Number(r.total_items || 0),
                'Total Qty': Number(r.total_qty || 0),
                'No BPPB': r.bppb_no || '',
                'No SJ': r.sj_no || '',
                'PIC / Set By': r.pic || r.setby || '',
                'Status': (r.iscancel === 'Y' ? 'BATAL' : 'AKTIF')
            }));
            const ws = XLSX.utils.json_to_sheet(dataToExport);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Mutasi_Produksi");
            XLSX.writeFile(wb, `Stock_Transfer_${new Date().toISOString().slice(0,10)}.xlsx`);
        }

        // Inisialisasi
        document.addEventListener('DOMContentLoaded', () => {
            loadData();
        });
    </script>
</body>
</html>

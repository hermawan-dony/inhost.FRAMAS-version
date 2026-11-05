<?php
/**
 * CEISA 4.0 & ERP Enterprise Hub - Pengeluaran & Pemusnahan Limbah/Mesin (Disposal)
 * Hasil Porting dari PowerBuilder TPB-ERP v2.0 (w_disposal.srw & d_mutasi_prod_data.srd)
 * Terhubung ke tabel bc_issue_hdr, bc_issue_dtl, bc_material, dan bc_ref_satuan.
 * 
 * Mengelola Berita Acara & Pengeluaran Barang:
 * - Wastes (Limbah & Sisa Produksi / Scrap)
 * - Machine (Pemusnahan / Pengeluaran Mesin Rusak / Ex-Aset)
 * - Factory Equipment, Part Machine, Tools, Mold
 * Terintegrasi dengan Dokumen Pabean Pengeluaran (BC 2.4 Penjualan Limbah & BC 4.1 Pemusnahan KB).
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

// Auto-heal check for bc_issue_dtl columns
function ensureIssueDtlSchema($koneksi) {
    $chk = $koneksi->query("SHOW COLUMNS FROM bc_issue_dtl LIKE 'cmp_id'");
    if ($chk && $chk->num_rows === 0) {
        $koneksi->query("ALTER TABLE bc_issue_dtl ADD COLUMN cmp_id VARCHAR(30) DEFAULT 'SNU' AFTER note");
    }
}
ensureIssueDtlSchema($koneksi);

// =========================================================================
// BACKEND AJAX API: KPI Metrics
// =========================================================================
if ($action === 'kpi') {
    header('Content-Type: application/json; charset=utf-8');

    $cmpClause = !empty($sessionCmpId) ? "(h.cmp_id = '$escCmp' OR h.cmp_id = '' OR h.cmp_id IS NULL)" : "1=1";
    $sql = "SELECT 
        COUNT(DISTINCT h.id) as total_dokumen,
        COUNT(DISTINCT h.klas) as total_klas,
        SUM(CASE WHEN h.klas = 'Wastes' THEN 1 ELSE 0 END) as cnt_wastes,
        SUM(CASE WHEN h.klas = 'Machine' THEN 1 ELSE 0 END) as cnt_machine,
        SUM(CASE WHEN h.klas = 'Part Machine' THEN 1 ELSE 0 END) as cnt_part_machine,
        COUNT(DISTINCT IFNULL(h.pic, 'UNASSIGNED')) as total_pic
        FROM bc_issue_hdr h 
        WHERE $cmpClause AND h.AB = 'B' AND h.klas IN ('Wastes', 'Machine', 'Factory Equipment', 'Part Machine', 'Tools', 'Mold')";
    $res = $koneksi->query($sql);
    $kpi = $res ? $res->fetch_assoc() : [
        'total_dokumen' => 0, 'total_klas' => 0, 'cnt_wastes' => 0, 'cnt_machine' => 0, 'cnt_part_machine' => 0, 'total_pic' => 0
    ];

    echo json_encode(['status' => 'OK', 'kpi' => $kpi]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: List Disposal History
// =========================================================================
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');

    $cmpClause = !empty($sessionCmpId) ? "(h.cmp_id = '$escCmp' OR h.cmp_id = '' OR h.cmp_id IS NULL)" : "1=1";
    $where = [
        $cmpClause,
        "h.AB = 'B'",
        "h.klas IN ('Wastes', 'Machine', 'Factory Equipment', 'Part Machine', 'Tools', 'Mold')"
    ];

    $klas = trim($_GET['klas'] ?? '');
    if (!empty($klas) && $klas !== 'ALL') {
        $escK = $koneksi->real_escape_string($klas);
        $where[] = "h.klas = '$escK'";
    }

    $dt1 = trim($_GET['dt1'] ?? '');
    $dt2 = trim($_GET['dt2'] ?? '');
    if (!empty($dt1) && !empty($dt2)) {
        $escDt1 = $koneksi->real_escape_string($dt1);
        $escDt2 = $koneksi->real_escape_string($dt2);
        $where[] = "h.tgl BETWEEN '$escDt1' AND '$escDt2'";
    }

    $search = trim($_GET['search'] ?? '');
    if (!empty($search)) {
        $escS = $koneksi->real_escape_string($search);
        $where[] = "(h.no_bukti LIKE '%$escS%' OR h.pic LIKE '%$escS%' OR h.note LIKE '%$escS%' OR h.sj_no LIKE '%$escS%')";
    }

    $whereSql = implode(' AND ', $where);

    $cntRes = $koneksi->query("SELECT COUNT(*) as c FROM bc_issue_hdr h WHERE $whereSql");
    $total = $cntRes ? intval($cntRes->fetch_assoc()['c']) : 0;

    $limit = intval($_GET['limit'] ?? 25);
    $page = max(1, intval($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT 
        h.id,
        h.no_bukti,
        h.tgl,
        h.klas,
        h.pic,
        h.sj_no,
        h.sj_dt,
        h.note,
        h.setby,
        (SELECT COUNT(*) FROM bc_issue_dtl d WHERE d.id_hdr = h.id) as item_count,
        (SELECT SUM(IFNULL(d.qty, 0)) FROM bc_issue_dtl d WHERE d.id_hdr = h.id) as total_qty
        FROM bc_issue_hdr h
        WHERE $whereSql
        ORDER BY h.tgl DESC, h.no_bukti DESC
        LIMIT $limit OFFSET $offset";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'data' => $data
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Get Disposal Document Detail
// =========================================================================
if ($action === 'detail') {
    header('Content-Type: application/json; charset=utf-8');

    $id = trim($_GET['id'] ?? '');
    $escId = $koneksi->real_escape_string($id);

    $cmpClause = !empty($sessionCmpId) ? "(h.cmp_id = '$escCmp' OR h.cmp_id = '' OR h.cmp_id IS NULL)" : "1=1";
    $hdrRes = $koneksi->query("SELECT * FROM bc_issue_hdr h WHERE h.id = '$escId' AND $cmpClause LIMIT 1");
    if (!$hdrRes || $hdrRes->num_rows === 0) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Dokumen disposal tidak ditemukan.']);
        exit;
    }
    $header = $hdrRes->fetch_assoc();

    $dtlSql = "SELECT d.id, d.seq, d.kode_barang, IFNULL(m.URAIAN, d.kode_barang) as uraian, d.qty, d.sat, d.note
               FROM bc_issue_dtl d 
               LEFT JOIN bc_material m ON m.KODE_BARANG = d.kode_barang AND (m.cmp_id = '$escCmp' OR m.cmp_id = '' OR m.cmp_id IS NULL)
               WHERE d.id_hdr = '$escId'
               ORDER BY d.seq ASC, d.id ASC";
    $dtlRes = $koneksi->query($dtlSql);
    $items = [];
    if ($dtlRes) {
        while ($it = $dtlRes->fetch_assoc()) {
            $items[] = $it;
        }
    }

    echo json_encode([
        'status' => 'OK',
        'header' => $header,
        'items' => $items
    ]);
    exit;
}

// =========================================================================
// BACKEND AJAX API: Material Lookup for Disposal
// =========================================================================
if ($action === 'lookup_material') {
    header('Content-Type: application/json; charset=utf-8');
    $klas = trim($_GET['klas'] ?? '');
    $q = trim($_GET['q'] ?? '');

    $where = ["(a.cmp_id = '$escCmp' OR a.cmp_id = '' OR a.cmp_id IS NULL)"];
    if (!empty($klas) && $klas !== 'ALL') {
        $escK = $koneksi->real_escape_string($klas);
        $where[] = "a.KLAS = '$escK'";
    }
    if (!empty($q)) {
        $escQ = $koneksi->real_escape_string($q);
        $where[] = "(a.KODE_BARANG LIKE '%$escQ%' OR a.URAIAN LIKE '%$escQ%')";
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT a.KODE_BARANG as kode_barang, a.URAIAN as uraian, IFNULL(a.KODE_SATUAN, 'UNIT') as satuan, a.KLAS as klas
            FROM bc_material a 
            WHERE $whereSql 
            ORDER BY a.URAIAN ASC 
            LIMIT 50";
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
// BACKEND AJAX API: Save New Disposal Document
// =========================================================================
if ($action === 'save') {
    header('Content-Type: application/json; charset=utf-8');

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Data payload kosong atau format tidak valid.']);
        exit;
    }

    $tgl = trim($payload['tgl'] ?? date('Y-m-d'));
    $klas = trim($payload['klas'] ?? 'Wastes');
    $pic = trim($payload['pic'] ?? '');
    $sjNo = trim($payload['sj_no'] ?? '');
    $sjDt = trim($payload['sj_dt'] ?? '');
    $note = trim($payload['note'] ?? '');
    $items = $payload['items'] ?? [];

    if (empty($items)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Daftar item barang disposal tidak boleh kosong.']);
        exit;
    }

    $noBukti = trim($payload['no_bukti'] ?? '');
    if (empty($noBukti)) {
        // Auto generate DSP-YYYYMM-XXXX
        $prefix = "DSP/" . date('Ym', strtotime($tgl)) . "/";
        $chkRes = $koneksi->query("SELECT MAX(no_bukti) as max_no FROM bc_issue_hdr WHERE no_bukti LIKE '$prefix%' AND (cmp_id = '$escCmp' OR cmp_id = '')");
        $maxNo = $chkRes ? $chkRes->fetch_assoc()['max_no'] : null;
        if ($maxNo) {
            $lastNum = intval(substr($maxNo, strlen($prefix)));
            $noBukti = $prefix . str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $noBukti = $prefix . "0001";
        }
    }

    $idHdr = md5(uniqid(rand(), true));
    $setby = "$userId - " . date('Ymd Hi');

    $escIdHdr = $koneksi->real_escape_string($idHdr);
    $escNoBukti = $koneksi->real_escape_string($noBukti);
    $escTgl = $koneksi->real_escape_string($tgl);
    $escKlas = $koneksi->real_escape_string($klas);
    $escPic = $koneksi->real_escape_string($pic);
    $escSjNo = $koneksi->real_escape_string($sjNo);
    $escSjDt = $koneksi->real_escape_string($sjDt);
    $escNote = $koneksi->real_escape_string($note);
    $escSetby = $koneksi->real_escape_string($setby);

    $koneksi->begin_transaction();
    try {
        $hdrSql = "INSERT INTO bc_issue_hdr (id, tgl, no_bukti, dept, AB, klas, pic, setby, sj_no, sj_dt, note, cmp_id)
                   VALUES ('$escIdHdr', '$escTgl', '$escNoBukti', 'DISPOSAL', 'B', '$escKlas', '$escPic', '$escSetby', '$escSjNo', '$escSjDt', '$escNote', '$escCmp')";
        if (!$koneksi->query($hdrSql)) {
            throw new Exception("Gagal insert header: " . $koneksi->error);
        }

        $seq = 1;
        foreach ($items as $it) {
            $kodeBrg = $koneksi->real_escape_string(trim($it['kode_barang'] ?? ''));
            $qty = floatval($it['qty'] ?? 0);
            $sat = $koneksi->real_escape_string(trim($it['sat'] ?? 'KGM'));
            $itNote = $koneksi->real_escape_string(trim($it['note'] ?? ''));
            $idDtl = md5(uniqid(rand(), true));

            if (empty($kodeBrg) || $qty <= 0) continue;

            $dtlSql = "INSERT INTO bc_issue_dtl (id, id_hdr, seq, kode_barang, qty, sat, note, cmp_id)
                       VALUES ('$idDtl', '$escIdHdr', $seq, '$kodeBrg', $qty, '$sat', '$itNote', '$escCmp')";
            if (!$koneksi->query($dtlSql)) {
                throw new Exception("Gagal insert detail baris $seq: " . $koneksi->error);
            }
            $seq++;
        }

        $koneksi->commit();
        echo json_encode([
            'status' => 'OK',
            'message' => "Bukti Disposal '$noBukti' berhasil dibuat.",
            'id' => $idHdr,
            'no_bukti' => $noBukti
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disposal Limbah & Mesin (Pemusnahan) - CEISA 4.0 ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="/assets/ant_grid.css">
    <script src="/assets/sweetalert2.all.min.js">        window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <script src="/assets/ant_grid.js">        window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <script>
        (function() {
            try {
                var urlParams = new URLSearchParams(window.location.search);
                var t = urlParams.get('theme') || localStorage.getItem('ceisa_theme') || 'light';
                if (t === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark-mode');
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch(e) {}
        })();
            window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
    <style>
        :root {
            --primary: #fa541c;
            --primary-hover: #ff7a45;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #f5222d;
            --bg-color: #f0f2f5;
            --card-bg: #ffffff;
            --text-color: #262626;
            --text-secondary: #8c8c8c;
            --border-color: #d9d9d9;
            --border-light: #e8e8e8;
            --table-head-bg: #fafafa;
            --font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body.dark-mode, html.dark-mode, [data-theme="dark"] {
            --primary: #d4380d;
            --bg-color: #141414;
            --card-bg: #1f1f1f;
            --text-color: rgba(255, 255, 255, 0.85);
            --text-secondary: rgba(255, 255, 255, 0.45);
            --border-color: #303030;
            --border-light: #424242;
            --table-head-bg: #1d1d1d;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font-family);
            background-color: var(--bg-color);
            color: var(--text-color);
            padding: 6px 10px;
            font-size: 9.5px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            background: var(--card-bg);
            padding: 6px 10px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            flex-wrap: wrap;
            gap: 6px;
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .header-title i {
            font-size: 16px;
            color: var(--primary);
        }
        .header-title h2 {
            font-size: 12px;
            font-weight: 600;
        }
        .header-subtitle {
            font-size: 9.5px;
            color: var(--text-secondary);
        }

        .kpi-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 6px;
            margin-bottom: 6px;
        }
        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 6px 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .kpi-icon {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .kpi-val {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-color);
            line-height: 1.2;
        }
        .kpi-lbl {
            font-size: 9px;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
        }

        /* Filter Pills matching PB w_disposal radio buttons */
        .filter-pills-bar {
            display: flex;
            gap: 4px;
            background: var(--card-bg);
            padding: 4px 8px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            margin-bottom: 6px;
            overflow-x: auto;
            align-items: center;
        }
        .pill-btn {
            background: transparent;
            border: 1px solid var(--border-color);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 9.5px;
            font-weight: 500;
            color: var(--text-color);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .pill-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .pill-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            font-weight: 600;
        }

        .main-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 6px 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        /* Toolbar */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }
        .toolbar-left {
            display: flex;
            gap: 4px;
            align-items: center;
            flex: 1;
            flex-wrap: wrap;
        }

        .form-control {
            padding: 1px 6px;
            border: 1px solid var(--border-color);
            border-radius: 2px;
            font-size: 9.5px;
            height: 21px;
            background: var(--card-bg);
            color: var(--text-color);
            outline: none;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(250,84,28,0.15);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0 8px;
            height: 21px;
            border-radius: 2px;
            font-size: 9.5px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid var(--border-color);
            transition: all 0.15s;
            text-decoration: none;
            white-space: nowrap;
            background: var(--card-bg);
            color: var(--text-color);
        }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-primary:hover { background: var(--primary-hover); color: #fff; }
        .btn-default { background: var(--card-bg); border-color: var(--border-color); color: var(--text-color); }
        .btn-default:hover { border-color: var(--primary); color: var(--primary); }

        table.erp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }
        table.erp-table th {
            background: var(--table-head-bg);
            color: var(--text-secondary);
            font-weight: 600;
            text-align: left;
            padding: 4px 6px;
            font-size: 9.5px;
            border: 1px solid var(--border-color);
            white-space: nowrap;
        }
        table.erp-table td {
            padding: 2px 6px;
            border: 1px solid var(--border-light);
            vertical-align: middle;
            font-size: 9.5px;
        }
        table.erp-table tr:hover td {
            background: rgba(250, 84, 28, 0.04);
        }

        .badge-tag {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 9px;
            font-weight: 600;
        }
        .badge-orange { background: #fff2e8; color: #fa541c; border: 1px solid #ffbb96; }
        .badge-green { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: var(--card-bg);
            border-radius: 4px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 12px;
            border: 1px solid var(--border-color);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 6px;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .form-group {
            margin-bottom: 6px;
        }
        .form-group label {
            display: block;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="page-header">
        <div class="header-title">
            <i class="fa-solid fa-dumpster-fire"></i>
            <div>
                <h2>Pengeluaran & Pemusnahan Limbah/Mesin (Disposal)</h2>
                <div class="header-subtitle">Porting PB w_disposal: Berita Acara Pemusnahan Wastes / Scrap, Mesin Rusak & Dokumen Pabean (Tenant: <?= htmlspecialchars($sessionCmpId) ?>)</div>
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-primary" onclick="openCreateModal()"><i class="fa-solid fa-plus"></i> Buat Dokumen Disposal</button>
            <button class="btn btn-default" onclick="loadAll()"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
    </div>

    <!-- KPI Summary -->
    <div class="kpi-container">
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#fff2e8; color:#fa541c;"><i class="fa-solid fa-file-shield"></i></div>
            <div>
                <div class="kpi-val" id="kpi-dokumen">-</div>
                <div class="kpi-lbl">Total Dokumen Disposal</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#f6ffed; color:#52c41a;"><i class="fa-solid fa-recycle"></i></div>
            <div>
                <div class="kpi-val" id="kpi-wastes">-</div>
                <div class="kpi-lbl">Disposal Wastes / Scrap</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#e6f7ff; color:#1890ff;"><i class="fa-solid fa-gears"></i></div>
            <div>
                <div class="kpi-val" id="kpi-machine">-</div>
                <div class="kpi-lbl">Disposal Mesin & Peralatan</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#fffbe6; color:#faad14;"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            <div>
                <div class="kpi-val" id="kpi-part">-</div>
                <div class="kpi-lbl">Disposal Part Machine</div>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon" style="background:#f9f0ff; color:#722ed1;"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <div class="kpi-val" id="kpi-pic">-</div>
                <div class="kpi-lbl">Petugas PIC Terlibat</div>
            </div>
        </div>
    </div>

    <!-- Filter Category Pills matching PB -->
    <div class="filter-pills-bar">
        <span style="font-size:11px; font-weight:700; color:var(--text-secondary); margin-right:4px;">KATEGORI:</span>
        <button class="pill-btn active" onclick="setKlasFilter('ALL')">ALL</button>
        <button class="pill-btn" onclick="setKlasFilter('Wastes')">Wastes (Limbah/Scrap)</button>
        <button class="pill-btn" onclick="setKlasFilter('Machine')">Machine</button>
        <button class="pill-btn" onclick="setKlasFilter('Factory Equipment')">Factory Equipment</button>
        <button class="pill-btn" onclick="setKlasFilter('Part Machine')">Part Machine</button>
        <button class="pill-btn" onclick="setKlasFilter('Tools')">Tools</button>
        <button class="pill-btn" onclick="setKlasFilter('Mold')">Mold</button>
    </div>

    <!-- Main Table Card -->
    <div class="main-card">
        <div class="toolbar">
            <div class="toolbar-left">
                <input type="text" class="form-control" id="search-input" placeholder="Cari No Bukti / PIC / No Surat Jalan..." style="width:240px;" onkeyup="if(event.key==='Enter') applyFilter()">
                <input type="date" class="form-control" id="filter-dt1">
                <span>s/d</span>
                <input type="date" class="form-control" id="filter-dt2">
                <button class="btn btn-default" onclick="applyFilter()"><i class="fa-solid fa-filter"></i> Filter</button>
            </div>
            <div id="pagination-info" style="font-size:12px; color:var(--text-secondary);">
                Memuat riwayat disposal...
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="erp-table ant-grid-table" id="disposalTable">
                <thead>
                    <tr>
                        <th style="width:140px;">No Bukti Disposal</th>
                        <th style="width:100px; text-align:center;">Tanggal</th>
                        <th style="width:140px;">Kategori</th>
                        <th style="text-align:center;">Jumlah Item</th>
                        <th style="text-align:right;">Total Qty</th>
                        <th>PIC Pelaksana</th>
                        <th>No Surat Jalan</th>
                        <th style="text-align:center;">Tgl SJ</th>
                        <th>Catatan / Keterangan</th>
                        <th style="width:100px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="disposal-table-body">
                    <tr><td colspan="10" style="text-align:center; padding:30px;">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px;">
            <div style="font-size:12px; color:var(--text-secondary);" id="page-summary">Halaman 1</div>
            <div style="display:flex; gap:6px;">
                <button class="btn btn-default" id="prev-page-btn" onclick="prevPage()"><i class="fa-solid fa-chevron-left"></i> Prev</button>
                <button class="btn btn-default" id="next-page-btn" onclick="nextPage()">Next <i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <!-- MODAL: CREATE DISPOSAL DOCUMENT -->
    <div class="modal-overlay" id="modal-create">
        <div class="modal-card">
            <div class="modal-header">
                <h3 style="font-size:15px; font-weight:700;"><i class="fa-solid fa-dumpster-fire" style="color:var(--primary);"></i> Form Pengeluaran Limbah / Disposal Baru</h3>
                <button class="btn btn-default" onclick="closeModal('modal-create')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="form-create" onsubmit="submitDisposal(event)">
                <div class="form-grid">
                    <div class="form-group">
                        <label>No Bukti (Opsional / Otomatis)</label>
                        <input type="text" class="form-control" id="c-no-bukti" placeholder="Otomatis (DSP/YYYYMM/XXXX)">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Pelaksanaan *</label>
                        <input type="date" class="form-control" id="c-tgl" required>
                    </div>
                    <div class="form-group">
                        <label>Kategori Klasifikasi *</label>
                        <select class="form-control" id="c-klas" onchange="onKlasChanged()">
                            <option value="Wastes">Wastes (Limbah / Scrap)</option>
                            <option value="Machine">Machine (Mesin)</option>
                            <option value="Factory Equipment">Factory Equipment</option>
                            <option value="Part Machine">Part Machine</option>
                            <option value="Tools">Tools</option>
                            <option value="Mold">Mold</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Petugas PIC Pelaksana</label>
                        <input type="text" class="form-control" id="c-pic" placeholder="Nama Staff / Operator">
                    </div>
                    <div class="form-group">
                        <label>No Surat Jalan (SJ)</label>
                        <input type="text" class="form-control" id="c-sj-no" placeholder="SJ-xxxx">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Surat Jalan</label>
                        <input type="date" class="form-control" id="c-sj-dt">
                    </div>
                </div>

                <div class="form-group">
                    <label>Catatan / Alasan Disposal / Dokumen Pabean (BC 2.4 / BC 4.1)</label>
                    <input type="text" class="form-control" id="c-note" placeholder="Misal: Pemusnahan Limbah B3 dengan Berita Acara Bea Cukai / Penjualan Scrap">
                </div>

                <!-- Grid Item Disposal -->
                <div style="margin-top:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <h4 style="font-size:13px; font-weight:700;"><i class="fa-solid fa-list-check"></i> Rincian Barang yang Didisposal</h4>
                        <button type="button" class="btn btn-default" onclick="addItemRow()"><i class="fa-solid fa-plus"></i> Tambah Baris</button>
                    </div>
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th style="width:220px;">Kode Barang</th>
                                <th>Uraian / Deskripsi</th>
                                <th style="width:110px; text-align:right;">Jumlah (Qty)</th>
                                <th style="width:90px;">Satuan</th>
                                <th>Keterangan / Posisi</th>
                                <th style="width:50px; text-align:center;">Hapus</th>
                            </tr>
                        </thead>
                        <tbody id="create-items-body"></tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                    <button type="button" class="btn btn-default" onclick="closeModal('modal-create')">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Simpan Dokumen Disposal</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: VIEW DETAIL & BERITA ACARA -->
    <div class="modal-overlay" id="modal-detail">
        <div class="modal-card">
            <div class="modal-header">
                <h3 style="font-size:15px; font-weight:700;" id="detail-title"><i class="fa-solid fa-file-lines" style="color:var(--primary);"></i> Rincian Bukti Disposal</h3>
                <button class="btn btn-default" onclick="closeModal('modal-detail')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="detail-header-info" style="background:#fafafa; border:1px solid var(--border-color); border-radius:6px; padding:12px; margin-bottom:14px; font-size:12px;"></div>
            <table class="erp-table">
                <thead>
                    <tr>
                        <th style="width:50px; text-align:center;">Seq</th>
                        <th style="width:140px;">Kode Barang</th>
                        <th>Uraian Barang</th>
                        <th style="width:100px; text-align:right;">Qty</th>
                        <th style="width:80px;">Satuan</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody id="detail-items-body"></tbody>
            </table>
            <div style="display:flex; justify-content:space-between; margin-top:16px;">
                <button class="btn btn-default" onclick="printBeritaAcara()"><i class="fa-solid fa-print"></i> Cetak Berita Acara Bea Cukai</button>
                <button class="btn btn-default" onclick="closeModal('modal-detail')">Tutup</button>
            </div>
        </div>
    </div>

    <!-- Interactive Logic -->
    <script>
        let currentKlas = 'ALL';
        let currentPage = 1;
        const pageLimit = 25;
        let activeDetailDoc = null;

        function setKlasFilter(klas) {
            currentKlas = klas;
            document.querySelectorAll('.pill-btn').forEach(b => {
                b.classList.toggle('active', b.textContent.trim().startsWith(klas));
            });
            currentPage = 1;
            loadList();
        }

        function applyFilter() {
            currentPage = 1;
            loadList();
        }

        async function loadAll() {
            loadKPI();
            loadList();
        }

        async function loadKPI() {
            try {
                const res = await fetch('?action=kpi');
                const json = await res.json();
                if (json.status === 'OK') {
                    document.getElementById('kpi-dokumen').textContent = Number(json.kpi.total_dokumen || 0).toLocaleString();
                    document.getElementById('kpi-wastes').textContent = Number(json.kpi.cnt_wastes || 0).toLocaleString();
                    document.getElementById('kpi-machine').textContent = Number(json.kpi.cnt_machine || 0).toLocaleString();
                    document.getElementById('kpi-part').textContent = Number(json.kpi.cnt_part_machine || 0).toLocaleString();
                    document.getElementById('kpi-pic').textContent = json.kpi.total_pic || 0;
                }
            } catch(e) { console.error('Gagal KPI:', e); }
        }

        async function loadList() {
            const tbody = document.getElementById('disposal-table-body');
            const search = document.getElementById('search-input').value.trim();
            const dt1 = document.getElementById('filter-dt1').value;
            const dt2 = document.getElementById('filter-dt2').value;

            try {
                const url = `?action=list&klas=${encodeURIComponent(currentKlas)}&search=${encodeURIComponent(search)}&dt1=${encodeURIComponent(dt1)}&dt2=${encodeURIComponent(dt2)}&page=${currentPage}&limit=${pageLimit}`;
                const res = await fetch(url);
                const json = await res.json();

                if (json.status === 'OK') {
                    if (json.data.length > 0) {
                        tbody.innerHTML = json.data.map(r => `
                            <tr>
                                <td><strong>${escapeHtml(r.no_bukti)}</strong></td>
                                <td style="text-align:center;">${escapeHtml(r.tgl)}</td>
                                <td><span class="badge-tag badge-orange">${escapeHtml(r.klas || '-')}</span></td>
                                <td style="text-align:center;">${r.item_count || 0} Item</td>
                                <td style="text-align:right; font-weight:700;">${Number(r.total_qty || 0).toLocaleString('id-ID')}</td>
                                <td>${escapeHtml(r.pic || '-')}</td>
                                <td>${escapeHtml(r.sj_no || '-')}</td>
                                <td style="text-align:center;">${escapeHtml(r.sj_dt || '-')}</td>
                                <td style="color:var(--text-secondary);">${escapeHtml(r.note || '-')}</td>
                                <td style="text-align:center;">
                                    <button class="btn btn-default" style="padding:3px 7px;" title="Lihat Rincian" onclick="viewDetail('${escapeHtml(r.id)}')"><i class="fa-solid fa-eye"></i></button>
                                </td>
                            </tr>
                        `).join('');
                    } else {
                        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:24px;">Tidak ada dokumen disposal yang sesuai filter.</td></tr>`;
                    }

                    if (window.AntEnterpriseGrid) {
                        if (!window.disposalGrid) {
                            window.disposalGrid = new AntEnterpriseGrid('#disposalTable', { gridId: 'grid_disposal', enablePagination: false });
                        } else {
                            window.disposalGrid.refresh();
                        }
                    }

                    const start = (currentPage - 1) * pageLimit + 1;
                    const end = Math.min(currentPage * pageLimit, json.total);
                    document.getElementById('pagination-info').textContent = `Menampilkan ${start} - ${end} dari total ${json.total} dokumen`;
                    document.getElementById('page-summary').textContent = `Halaman ${currentPage} / ${Math.max(1, Math.ceil(json.total / pageLimit))}`;
                    document.getElementById('prev-page-btn').disabled = (currentPage <= 1);
                    document.getElementById('next-page-btn').disabled = (end >= json.total);
                }
            } catch(e) {
                tbody.innerHTML = `<tr><td colspan="10" style="color:red; text-align:center;">Error: ${e.message}</td></tr>`;
            }
        }

        function prevPage() {
            if (currentPage > 1) {
                currentPage--;
                loadList();
            }
        }

        function nextPage() {
            currentPage++;
            loadList();
        }

        function openCreateModal() {
            document.getElementById('form-create').reset();
            document.getElementById('c-tgl').value = new Date().toISOString().split('T')[0];
            document.getElementById('create-items-body').innerHTML = '';
            addItemRow();
            document.getElementById('modal-create').classList.add('active');
        }

        function onKlasChanged() {
            // Optional hook when category changes
        }

        function addItemRow() {
            const tbody = document.getElementById('create-items-body');
            const rowIdx = tbody.children.length;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <input type="text" class="form-control" id="it-kode-${rowIdx}" placeholder="Ketik Kode / Cari..." onchange="lookupItemName(${rowIdx})" required>
                </td>
                <td>
                    <input type="text" class="form-control" id="it-uraian-${rowIdx}" placeholder="Deskripsi Barang">
                </td>
                <td>
                    <input type="number" step="0.0001" class="form-control" id="it-qty-${rowIdx}" value="1" min="0.0001" style="text-align:right;" required>
                </td>
                <td>
                    <input type="text" class="form-control" id="it-sat-${rowIdx}" value="KGM">
                </td>
                <td>
                    <input type="text" class="form-control" id="it-note-${rowIdx}" placeholder="Keterangan item">
                </td>
                <td style="text-align:center;">
                    <button type="button" class="btn btn-default" style="padding:3px 7px; color:red;" onclick="this.closest('tr').remove()"><i class="fa-solid fa-trash"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
        }

        async function lookupItemName(idx) {
            const kode = document.getElementById(`it-kode-${idx}`).value.trim();
            const klas = document.getElementById('c-klas').value;
            if (!kode) return;

            try {
                const res = await fetch(`?action=lookup_material&q=${encodeURIComponent(kode)}&klas=${encodeURIComponent(klas)}`);
                const json = await res.json();
                if (json.status === 'OK' && json.data.length > 0) {
                    const match = json.data.find(d => d.kode_barang.toLowerCase() === kode.toLowerCase()) || json.data[0];
                    document.getElementById(`it-kode-${idx}`).value = match.kode_barang;
                    document.getElementById(`it-uraian-${idx}`).value = match.uraian;
                    document.getElementById(`it-sat-${idx}`).value = match.satuan;
                }
            } catch(e) {}
        }

        async function submitDisposal(e) {
            e.preventDefault();
            const rows = document.querySelectorAll('#create-items-body tr');
            if (rows.length === 0) {
                alert('Silakan tambahkan minimal 1 baris item barang.');
                return;
            }

            const items = [];
            rows.forEach((tr, i) => {
                const kode = tr.querySelector(`[id^="it-kode-"]`)?.value.trim();
                const qty = parseFloat(tr.querySelector(`[id^="it-qty-"]`)?.value || 0);
                const sat = tr.querySelector(`[id^="it-sat-"]`)?.value.trim();
                const note = tr.querySelector(`[id^="it-note-"]`)?.value.trim();
                if (kode && qty > 0) {
                    items.push({ kode_barang: kode, qty: qty, sat: sat, note: note });
                }
            });

            if (items.length === 0) {
                alert('Item barang atau Qty tidak valid.');
                return;
            }

            const payload = {
                no_bukti: document.getElementById('c-no-bukti').value.trim(),
                tgl: document.getElementById('c-tgl').value,
                klas: document.getElementById('c-klas').value,
                pic: document.getElementById('c-pic').value.trim(),
                sj_no: document.getElementById('c-sj-no').value.trim(),
                sj_dt: document.getElementById('c-sj-dt').value,
                note: document.getElementById('c-note').value.trim(),
                items: items
            };

            const res = await fetch('?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            alert(json.message);
            if (json.status === 'OK') {
                closeModal('modal-create');
                loadAll();
            }
        }

        async function viewDetail(id) {
            const infoDiv = document.getElementById('detail-header-info');
            const tbody = document.getElementById('detail-items-body');
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:16px;">Memuat rincian item...</td></tr>`;
            document.getElementById('modal-detail').classList.add('active');

            try {
                const res = await fetch(`?action=detail&id=${encodeURIComponent(id)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    const h = json.header;
                    activeDetailDoc = json;
                    document.getElementById('detail-title').innerHTML = `<i class="fa-solid fa-file-lines" style="color:var(--primary);"></i> Bukti Disposal [${escapeHtml(h.no_bukti)}]`;
                    infoDiv.innerHTML = `
                        <strong>Nomor Bukti: ${escapeHtml(h.no_bukti)}</strong> | Tanggal: <strong>${escapeHtml(h.tgl)}</strong> | Kategori: <span class="badge-tag badge-orange">${escapeHtml(h.klas)}</span><br>
                        PIC: <strong>${escapeHtml(h.pic || '-')}</strong> | Surat Jalan: <strong>${escapeHtml(h.sj_no || '-')}</strong> (${escapeHtml(h.sj_dt || '-')})<br>
                        <span style="color:var(--text-secondary);">Keterangan: ${escapeHtml(h.note || '-')}</span>
                    `;

                    tbody.innerHTML = json.items.map((it, i) => `
                        <tr>
                            <td style="text-align:center;">${i + 1}</td>
                            <td><strong>${escapeHtml(it.kode_barang)}</strong></td>
                            <td>${escapeHtml(it.uraian)}</td>
                            <td style="text-align:right; font-weight:700;">${Number(it.qty).toLocaleString('id-ID')}</td>
                            <td><span class="badge-tag badge-green">${escapeHtml(it.sat)}</span></td>
                            <td style="color:var(--text-secondary);">${escapeHtml(it.note || '-')}</td>
                        </tr>
                    `).join('');
                }
            } catch(e) {
                tbody.innerHTML = `<tr><td colspan="6" style="color:red; text-align:center;">Error: ${e.message}</td></tr>`;
            }
        }

        function printBeritaAcara() {
            if (!activeDetailDoc) return;
            const h = activeDetailDoc.header;
            const items = activeDetailDoc.items;
            const printWin = window.open('', '_blank');
            printWin.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Berita Acara Disposal - ${escapeHtml(h.no_bukti)}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 30px; font-size: 13px; line-height: 1.5; }
                        h2, h3 { text-align: center; margin-bottom: 4px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                        th, td { border: 1px solid #000; padding: 6px 8px; font-size: 12px; }
                        th { background: #f0f0f0; }
                        .ttd { display: flex; justify-content: space-between; margin-top: 40px; text-align: center; }
                        .ttd div { width: 200px; }
                    </style>
                </head>
                <body>
                    <h2>BERITA ACARA PENGELUARAN / PEMUSNAHAN BARANG</h2>
                    <h3 style="font-weight:normal;">NOMOR: ${escapeHtml(h.no_bukti)}</h3>
                    <p style="margin-top:20px;">Pada hari ini, tanggal <strong>${escapeHtml(h.tgl)}</strong>, telah dilaksanakan pengeluaran / pemusnahan barang dalam kategori <strong>${escapeHtml(h.klas)}</strong> di kawasan pabrik PT. SN UNGGUL sebagai berikut:</p>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Kode Barang</th>
                                <th>Uraian / Spesifikasi</th>
                                <th>Jumlah (Qty)</th>
                                <th>Satuan</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${items.map((it, i) => `
                                <tr>
                                    <td style="text-align:center;">${i+1}</td>
                                    <td>${escapeHtml(it.kode_barang)}</td>
                                    <td>${escapeHtml(it.uraian)}</td>
                                    <td style="text-align:right;">${Number(it.qty).toLocaleString('id-ID')}</td>
                                    <td style="text-align:center;">${escapeHtml(it.sat)}</td>
                                    <td>${escapeHtml(it.note || '-')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    <p style="margin-top:20px;">Catatan: ${escapeHtml(h.note || '-')}</p>
                    <div class="ttd">
                        <div>
                            Petugas Pelaksana (PIC)<br><br><br><br>
                            ( <strong>${escapeHtml(h.pic || '...................')}</strong> )
                        </div>
                        <div>
                            Mengetahui Supervisor<br><br><br><br>
                            ( ............................... )
                        </div>
                        <div>
                            Saksi Bea dan Cukai<br><br><br><br>
                            ( ............................... )
                        </div>
                    </div>
                    <script>window.onload = function() { window.print(); }<\/script>
                </body>
                </html>
            `);
            printWin.document.close();
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        window.addEventListener('DOMContentLoaded', loadAll);
            window.addEventListener('message', function(e) {
            if (e.data && (e.data.type === 'CHANGE_FONT_SIZE' || e.data.type === 'LIVE_FONT_SIZE_CHANGE' || e.data.type === 'SET_FONT_SIZE')) {
                document.documentElement.style.setProperty('--form-font-size', e.data.size);
                document.body.style.fontSize = e.data.size;
            }
        });
</script>
</body>
</html>

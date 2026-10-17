<?php
/**
 * CEISA 4.0 - Koreksi Klasifikasi (KLAS) IT Inventory (bc_1ab2)
 * Halaman Khusus untuk Memperbaiki Data Transaksi di bc_1ab2 
 * yang KLAS-nya Kosong, Bernilai '-', atau Tidak Sesuai Referensi bc_tipe.
 * Mendukung Edit Per Baris dan Edit Massal (Bulk Update)
 */
session_start();
require_once __DIR__ . '/koneksi.php';

$isAdmin = !empty($_SESSION['is_admin']) || (isset($_GET['is_admin']) && $_GET['is_admin'] === '1');
$sessionCmpId = $_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? ($_GET['cmp_id'] ?? ''));
$escSessionCmp = $koneksi->real_escape_string($sessionCmpId);

// Ambil Referensi Klasifikasi dari bc_tipe
$klasList = [];
$validKlasMap = [];
$qTipe = $koneksi->query("SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx");
if ($qTipe) {
    while ($t = $qTipe->fetch_assoc()) {
        if ($t['tipe'] !== '-- SEMUA KLAS --') {
            $klasList[] = $t;
            $validKlasMap[$t['tipe']] = true;
        }
    }
}

$action = $_REQUEST['action'] ?? '';

// -------------------------------------------------------------
// 1. AJAX: LIST DATA bc_1ab2 DENGAN KLAS TIDAK VALID
// -------------------------------------------------------------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $search     = trim($_GET['q'] ?? '');
    $filterBc   = trim($_GET['bc'] ?? '');
    $filterType = trim($_GET['issue_type'] ?? 'all'); // 'all', 'minus', 'empty', 'not_in_tipe'

    $where = ["(KLAS IS NULL OR TRIM(KLAS) = '' OR TRIM(KLAS) = '-' OR KLAS NOT IN (SELECT tipe FROM bc_tipe))"];

    if (!empty($sessionCmpId) && $sessionCmpId !== 'ALL') {
        $where[] = "(cmp_id = '$escSessionCmp' OR cmp_id = '')";
    }

    if (!empty($filterBc)) {
        $escBc = $koneksi->real_escape_string($filterBc);
        $where[] = "BC = '$escBc'";
    }

    if ($filterType === 'minus') {
        $where[] = "TRIM(KLAS) = '-'";
    } elseif ($filterType === 'empty') {
        $where[] = "(KLAS IS NULL OR TRIM(KLAS) = '')";
    } elseif ($filterType === 'not_in_tipe') {
        $where[] = "(TRIM(KLAS) != '-' AND TRIM(KLAS) != '' AND KLAS IS NOT NULL AND KLAS NOT IN (SELECT tipe FROM bc_tipe))";
    }

    if (!empty($search)) {
        $s = $koneksi->real_escape_string($search);
        $where[] = "(KODE_BARANG LIKE '%$s%' OR URAIAN LIKE '%$s%' OR NOMOR_DAFTAR LIKE '%$s%' OR NO_BUKTI LIKE '%$s%')";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT 
        BC, NOMOR_DAFTAR, TANGGAL_DAFTAR, NO_BUKTI, TGL_BUKTI,
        SERI_BARANG, KODE_BARANG, URAIAN, POS_TARIF, UNIT, QTY, NILAI, KLAS,
        ID_HEADER, NOMOR_AJU
    FROM bc_1ab2
    $whereSql
    ORDER BY TANGGAL_DAFTAR DESC, NOMOR_DAFTAR DESC, SERI_BARANG ASC
    LIMIT 2000";

    $res = $koneksi->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rawKlas = trim($r['KLAS'] ?? '');
            if ($rawKlas === '-') {
                $issue = 'minus';
                $issueLabel = 'Bernilai "-"';
            } elseif ($rawKlas === '' || $r['KLAS'] === null) {
                $issue = 'empty';
                $issueLabel = 'Kosong / NULL';
            } else {
                $issue = 'not_registered';
                $issueLabel = 'Tidak Terdaftar';
            }

            // Tebak klasifikasi berdasarkan Uraian atau Kode
            $suggestedKlas = 'Raw Materials';
            $uUpper = strtoupper($r['URAIAN'] ?? '');
            if (preg_match('/(SAMPLE|CONTOH)/i', $uUpper)) {
                $suggestedKlas = 'Product Sample';
            } elseif (preg_match('/(WASTE|AFVAL|SCRAP|SISA)/i', $uUpper)) {
                $suggestedKlas = 'Wastes';
            } elseif (preg_match('/(MOLD|MOULD|CETAKAN)/i', $uUpper)) {
                $suggestedKlas = 'Mold';
            } elseif (preg_match('/(MESIN|MACHINE)/i', $uUpper)) {
                $suggestedKlas = 'Machine';
            } elseif (preg_match('/(SPAREPART|PART MACHINE)/i', $uUpper)) {
                $suggestedKlas = 'Part Machine';
            } elseif (preg_match('/(TOOL|ALAT)/i', $uUpper)) {
                $suggestedKlas = 'Tools';
            }

            $r['issue_type']      = $issue;
            $r['issue_label']     = $issueLabel;
            $r['suggested_klas']  = $suggestedKlas;
            $data[] = $r;
        }
    }

    // KPI Counters
    $cmpFilter = (!empty($sessionCmpId) && $sessionCmpId !== 'ALL') ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $kpiTotal = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE (KLAS IS NULL OR TRIM(KLAS) = '' OR TRIM(KLAS) = '-' OR KLAS NOT IN (SELECT tipe FROM bc_tipe))$cmpFilter")->fetch_row()[0] ?? 0;
    $kpiMinus = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE TRIM(KLAS) = '-'$cmpFilter")->fetch_row()[0] ?? 0;
    $kpiEmpty = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE (KLAS IS NULL OR TRIM(KLAS) = '')$cmpFilter")->fetch_row()[0] ?? 0;
    $kpiBc16  = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE BC = '16' AND (KLAS IS NULL OR TRIM(KLAS) = '' OR TRIM(KLAS) = '-' OR KLAS NOT IN (SELECT tipe FROM bc_tipe))$cmpFilter")->fetch_row()[0] ?? 0;
    $kpiBc30  = $koneksi->query("SELECT COUNT(1) FROM bc_1ab2 WHERE BC = '30' AND (KLAS IS NULL OR TRIM(KLAS) = '' OR TRIM(KLAS) = '-' OR KLAS NOT IN (SELECT tipe FROM bc_tipe))$cmpFilter")->fetch_row()[0] ?? 0;

    echo json_encode([
        'status' => 'OK',
        'count'  => count($data),
        'data'   => $data,
        'kpi'    => [
            'total' => (int)$kpiTotal,
            'minus' => (int)$kpiMinus,
            'empty' => (int)$kpiEmpty,
            'bc16'  => (int)$kpiBc16,
            'bc30'  => (int)$kpiBc30
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. AJAX: UPDATE KLAS TUNGGAL (PER BARIS)
// -------------------------------------------------------------
if ($action === 'update_single') {
    header('Content-Type: application/json; charset=utf-8');
    $bc      = trim($_POST['bc'] ?? '');
    $noDaft  = trim($_POST['nomor_daftar'] ?? '');
    $tglDaft = trim($_POST['tanggal_daftar'] ?? '');
    $noBukti = trim($_POST['no_bukti'] ?? '');
    $seri    = intval($_POST['seri_barang'] ?? 0);
    $kodeBrg = trim($_POST['kode_barang'] ?? '');
    $uraian  = trim($_POST['uraian'] ?? '');
    $newKlas = trim($_POST['klas'] ?? '');

    if (empty($newKlas) || $newKlas === '-') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Klasifikasi baru tidak boleh kosong atau bernilai "-".']);
        exit;
    }

    if (!isset($validKlasMap[$newKlas])) {
        echo json_encode(['status' => 'ERROR', 'message' => "Klasifikasi '$newKlas' tidak terdaftar dalam tabel referensi bc_tipe."]);
        exit;
    }

    $cmpWhere = (!empty($sessionCmpId) && $sessionCmpId !== 'ALL') ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
    $stmt = $koneksi->prepare("UPDATE bc_1ab2 SET KLAS = ? 
        WHERE BC = ? AND NOMOR_DAFTAR = ? AND TANGGAL_DAFTAR = ? AND NO_BUKTI = ? AND SERI_BARANG = ? AND KODE_BARANG = ? AND URAIAN = ?$cmpWhere");
    
    if (!$stmt) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Gagal menyiapkan query: ' . $koneksi->error]);
        exit;
    }

    $stmt->bind_param("sssssiss", $newKlas, $bc, $noDaft, $tglDaft, $noBukti, $seri, $kodeBrg, $uraian);

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode([
            'status'  => 'OK',
            'message' => "Klasifikasi untuk item '$kodeBrg' (Seri $seri) berhasil diperbarui menjadi '$newKlas'!"
        ]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['status' => 'ERROR', 'message' => "Gagal update: $err"]);
    }
    exit;
}

// -------------------------------------------------------------
// 3. AJAX: UPDATE KLAS MASSAL (BULK UPDATE)
// -------------------------------------------------------------
if ($action === 'update_bulk') {
    header('Content-Type: application/json; charset=utf-8');
    $rowsJson = $_POST['rows'] ?? '[]';
    $targetKlas = trim($_POST['target_klas'] ?? '');
    $rows = json_decode($rowsJson, true);

    if (empty($targetKlas) || $targetKlas === '-') {
        echo json_encode(['status' => 'ERROR', 'message' => 'Target Klasifikasi tidak boleh kosong atau bernilai "-".']);
        exit;
    }

    if (!isset($validKlasMap[$targetKlas])) {
        echo json_encode(['status' => 'ERROR', 'message' => "Klasifikasi target '$targetKlas' tidak valid."]);
        exit;
    }

    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Tidak ada baris yang dipilih untuk diupdate massal.']);
        exit;
    }

    $koneksi->begin_transaction();
    $updatedCount = 0;

    try {
        $cmpWhere = (!empty($sessionCmpId) && $sessionCmpId !== 'ALL') ? " AND (cmp_id = '$escSessionCmp' OR cmp_id = '')" : "";
        $stmt = $koneksi->prepare("UPDATE bc_1ab2 SET KLAS = ? 
            WHERE BC = ? AND NOMOR_DAFTAR = ? AND TANGGAL_DAFTAR = ? AND NO_BUKTI = ? AND SERI_BARANG = ? AND KODE_BARANG = ? AND URAIAN = ?$cmpWhere");

        if (!$stmt) {
            throw new Exception("Gagal menyiapkan statement: " . $koneksi->error);
        }

        foreach ($rows as $r) {
            $bc      = trim($r['bc'] ?? '');
            $noDaft  = trim($r['nomor_daftar'] ?? '');
            $tglDaft = trim($r['tanggal_daftar'] ?? '');
            $noBukti = trim($r['no_bukti'] ?? '');
            $seri    = intval($r['seri_barang'] ?? 0);
            $kodeBrg = trim($r['kode_barang'] ?? '');
            $uraian  = trim($r['uraian'] ?? '');

            $stmt->bind_param("sssssiss", $targetKlas, $bc, $noDaft, $tglDaft, $noBukti, $seri, $kodeBrg, $uraian);
            if ($stmt->execute()) {
                $updatedCount++;
            } else {
                throw new Exception("Gagal update baris [{$kodeBrg}]: " . $stmt->error);
            }
        }

        $stmt->close();
        $koneksi->commit();

        echo json_encode([
            'status'  => 'OK',
            'updated' => $updatedCount,
            'message' => "Update massal berhasil! Sebanyak $updatedCount baris data di bc_1ab2 telah diubah menjadi '$targetKlas'."
        ]);
    } catch (Exception $e) {
        $koneksi->rollback();
        echo json_encode([
            'status'  => 'ERROR',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Koreksi Klasifikasi IT Inventory<?= $isAdmin ? ' (bc_1ab2)' : '' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/assets/sweetalert2.all.min.js"></script>
    <style>
        :root {
            --bg-base: #f0f2f5;
            --bg-card: #ffffff;
            --border-color: #e8e8e8;
            --text-main: #262626;
            --text-muted: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --success: #52c41a;
            --warning: #faad14;
            --danger: #ff4d4f;
            --table-hover: #f0f7ff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            font-size: 10.5px;
            line-height: 1.45;
            padding: 8px 12px;
            -webkit-font-smoothing: antialiased;
        }
        .ant-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 8px 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .ant-card-head {
            min-height: 38px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .ant-card-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ant-toolbar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .ant-btn {
            font-family: inherit; font-size: 10px; height: 24px; padding: 0 9px; border-radius: 3px;
            border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main);
            cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 500;
            transition: all .2s;
        }
        .ant-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ant-btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ant-btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); color:#fff; }
        .ant-btn-success { background: var(--success); color: #fff; border-color: var(--success); }
        .ant-btn-success:hover { background: #49ad16; border-color: #49ad16; color:#fff; }
        .ant-btn-sm { height: 20px; padding: 0 6px; font-size: 9.5px; border-radius: 2px; }

        .form-control {
            font-family: inherit; font-size: 10px; height: 24px; padding: 1px 6px; border-radius: 3px;
            border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); outline: none;
        }
        .form-control:focus { border-color: var(--primary); }

        .kpi-container {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .kpi-badge {
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 3px 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
        }
        .kpi-title { font-size: 9.5px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
        .kpi-val { font-size: 11.5px; font-weight: 700; color: var(--text-main); }

        .filter-panel {
            background: var(--bg-base);
            border: 1px solid var(--border-color);
            border-radius: 3px;
            padding: 6px 10px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }

        .table-responsive {
            max-height: calc(100vh - 180px);
            overflow: auto;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }
        .ant-table { width: 100%; border-collapse: collapse; font-size: 10px; text-align: left; }
        .ant-table th {
            position: sticky; top: 0; background: var(--bg-base); border-bottom: 1px solid var(--border-color);
            padding: 5px 8px; font-size: 10px; font-weight: 600; white-space: nowrap; z-index: 2;
        }
        .ant-table td { padding: 4px 8px; border-bottom: 1px solid var(--border-color); white-space: nowrap; vertical-align: middle; }
        .ant-table tr:hover td { background-color: var(--table-hover); }

        .tag-badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 9.5px; font-weight: 600; }
        .tag-red { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }
        .tag-orange { background: #fffbe6; color: #faad14; border: 1px solid #ffe58f; }
        .tag-blue { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .tag-purple { background: #f9f0ff; color: #722ed1; border: 1px solid #d3adf7; }
    </style>
</head>
<body>
    <div class="ant-card">
        <!-- Header -->
        <div class="ant-card-head">
            <div class="ant-card-title">
                <i class="fa-solid fa-triangle-exclamation" style="color:#faad14;"></i>
                <span>Koreksi Klasifikasi IT Inventory<?= $isAdmin ? ' (<code>bc_1ab2</code>)' : '' ?></span>
            </div>
            <div class="ant-toolbar">
                <button type="button" class="ant-btn" onclick="openMasterBarang()">
                    <i class="fa-solid fa-boxes-stacked"></i> Buka Master Data Barang
                </button>
                <button type="button" class="ant-btn ant-btn-primary" onclick="loadData()">
                    <i class="fa-solid fa-rotate"></i> Muat Ulang Data
                </button>
            </div>
        </div>

        <!-- Description Alert -->
        <div style="background:#e6f7ff; border:1px solid #91d5ff; border-radius:3px; padding:6px 12px; margin-bottom:8px; font-size:10px; color:#0050b3; display:flex; align-items:center; justify-content:space-between;">
            <div>
                <i class="fa-solid fa-circle-info"></i> Halaman ini menampilkan seluruh baris transaksi di IT Inventory<?= $isAdmin ? ' (<code>bc_1ab2</code>)' : '' ?> yang memiliki Klasifikasi<?= $isAdmin ? ' (KLAS)' : '' ?> tidak valid (bernilai <code>'-'</code>, kosong/NULL, atau tidak terdaftar pada referensi Kategori<?= $isAdmin ? ' (<code>bc_tipe</code>)' : '' ?>). Anda dapat melakukan koreksi per baris maupun secara massal.
            </div>
        </div>

        <!-- KPI Mini -->
        <div class="kpi-container">
            <div class="kpi-badge">
                <span class="kpi-title">Total Perlu Koreksi:</span>
                <span class="kpi-val" id="kpiTotal" style="color:#ff4d4f;">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Klasifikasi '-' :</span>
                <span class="kpi-val" id="kpiMinus" style="color:#cf1322;">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Klasifikasi Kosong :</span>
                <span class="kpi-val" id="kpiEmpty" style="color:#faad14;">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Dokumen BC 16 :</span>
                <span class="kpi-val" id="kpiBc16" style="color:#722ed1;">0</span>
            </div>
            <div class="kpi-badge">
                <span class="kpi-title">Dokumen BC 30 :</span>
                <span class="kpi-val" id="kpiBc30" style="color:#13c2c2;">0</span>
            </div>
        </div>

        <!-- Filter & Bulk Actions -->
        <div class="filter-panel">
            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                <label style="font-weight:600; font-size:10px;"><i class="fa-solid fa-magnifying-glass"></i> Cari:</label>
                <input type="text" id="filterSearch" class="form-control" style="width:180px;" placeholder="Kode Barang, Uraian, No..." onkeyup="if(event.key==='Enter') loadData()">
                
                <label style="font-weight:600; font-size:10px; margin-left:4px;">Dokumen BC:</label>
                <select id="filterBc" class="form-control" style="width:100px;" onchange="loadData()">
                    <option value="">-- SEMUA BC --</option>
                    <option value="16">BC 1.6</option>
                    <option value="23">BC 2.3</option>
                    <option value="27">BC 2.7</option>
                    <option value="30">BC 3.0</option>
                    <option value="40">BC 4.0</option>
                </select>

                <label style="font-weight:600; font-size:10px; margin-left:4px;">Masalah:</label>
                <select id="filterType" class="form-control" style="width:150px;" onchange="loadData()">
                    <option value="all">Semua Masalah</option>
                    <option value="minus">Klasifikasi = '-'</option>
                    <option value="empty">Klasifikasi Kosong / NULL</option>
                    <option value="not_in_tipe">Tidak Terdaftar di Kategori Referensi<?= $isAdmin ? ' (bc_tipe)' : '' ?></option>
                </select>

                <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="loadData()">
                    <i class="fa-solid fa-filter"></i> Saring
                </button>
            </div>

            <!-- Bulk Action Toolbar -->
            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:#fff; padding:3px 8px; border-radius:3px; border:1px solid #d9d9d9;">
                <span style="font-weight:600; color:var(--text-muted); font-size:10px;">Aksi Massal:</span>
                <select id="bulkTargetKlas" class="form-control" style="width:170px; font-weight:600;">
                    <option value="">-- PILIH KLASIFIKASI TUJUAN --</option>
                    <?php foreach ($klasList as $k): ?>
                        <option value="<?= htmlspecialchars($k['tipe']) ?>"><?= htmlspecialchars($k['tipe']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="ant-btn ant-btn-sm ant-btn-success" onclick="executeBulkUpdate()">
                    <i class="fa-solid fa-bolt"></i> Terapkan & Simpan Massal (<span id="selectedCounter">0</span>)
                </button>
            </div>
        </div>

        <!-- Table Responsive -->
        <div class="table-responsive">
            <table class="ant-table">
                <thead>
                    <tr>
                        <th style="width:30px; text-align:center;">
                            <input type="checkbox" id="checkAll" onchange="toggleSelectAll(this.checked)">
                        </th>
                        <th style="width:35px; text-align:center;">#</th>
                        <th style="width:65px; text-align:center;">BC</th>
                        <th style="width:130px;">No & Tgl Daftar</th>
                        <th style="width:140px;">No Bukti</th>
                        <th style="width:45px; text-align:center;">Seri</th>
                        <th style="width:140px;">Kode Barang</th>
                        <th>Uraian Barang</th>
                        <th style="width:70px; text-align:center;">Satuan</th>
                        <th style="width:80px; text-align:right;">QTY</th>
                        <th style="width:125px; text-align:center;">Klasifikasi Saat Ini<?= $isAdmin ? ' (KLAS)' : '' ?></th>
                        <th style="width:190px;">Pilihan Klasifikasi Baru<?= $isAdmin ? ' (KLAS)' : '' ?></th>
                        <th style="width:75px; text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="13" style="text-align:center; padding:25px;">Memuat data transaksi invalid...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
        const sessionCmpId = '<?= htmlspecialchars($sessionCmpId) ?>';

        function openMasterBarang() {
            if (window.parent && typeof window.parent.switchMainTab === 'function') {
                window.parent.switchMainTab('inv_material');
            } else {
                window.location.href = 'material.php?cmp_id=' + encodeURIComponent(sessionCmpId);
            }
        }

        const KLAS_OPTIONS = <?= json_encode($klasList) ?>;
        let invalidRows = [];

        async function loadData() {
            const q = document.getElementById('filterSearch').value.trim();
            const bc = document.getElementById('filterBc').value;
            const issueType = document.getElementById('filterType').value;
            const tbody = document.getElementById('tableBody');
            tbody.innerHTML = '<tr><td colspan="13" style="text-align:center; padding:25px;"><i class="fa-solid fa-spinner fa-spin"></i> Mengambil data transaksi tidak valid...</td></tr>';

            try {
                const res = await fetch(`klas_koreksi.php?action=list&q=${encodeURIComponent(q)}&bc=${encodeURIComponent(bc)}&issue_type=${encodeURIComponent(issueType)}`);
                const json = await res.json();
                if (json.status === 'OK') {
                    invalidRows = json.data.map(r => ({ ...r, selected: false, new_klas: (r.suggested_klas || 'Raw Materials') }));
                    document.getElementById('kpiTotal').innerText = Number(json.kpi.total).toLocaleString('id-ID');
                    document.getElementById('kpiMinus').innerText = Number(json.kpi.minus).toLocaleString('id-ID');
                    document.getElementById('kpiEmpty').innerText = Number(json.kpi.empty).toLocaleString('id-ID');
                    document.getElementById('kpiBc16').innerText = Number(json.kpi.bc16).toLocaleString('id-ID');
                    document.getElementById('kpiBc30').innerText = Number(json.kpi.bc30).toLocaleString('id-ID');
                    renderTable();
                } else {
                    tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:red; padding:20px;">${json.message}</td></tr>`;
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; color:red; padding:20px;">Gagal memuat: ${err.message}</td></tr>`;
            }
        }

        function renderTable() {
            const tbody = document.getElementById('tableBody');
            if (invalidRows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="13" style="text-align:center; padding:35px; color:#52c41a;">
                    <i class="fa-solid fa-circle-check" style="font-size:24px; margin-bottom:8px;"></i><br>
                    <strong>Hebat! Tidak ditemukan data transaksi dengan Klasifikasi invalid.</strong><br>
                    <span style="color:var(--text-muted); font-size:10px;">Seluruh transaksi telah memiliki Klasifikasi yang valid.</span>
                </td></tr>`;
                updateSelectedCounter();
                return;
            }

            let html = '';
            invalidRows.forEach((r, idx) => {
                let badgeBc = `<span class="tag-badge tag-blue">BC ${escapeHtml(r.BC)}</span>`;
                if (r.BC === '16') badgeBc = `<span class="tag-badge tag-purple">BC 1.6</span>`;
                else if (r.BC === '30') badgeBc = `<span class="tag-badge" style="background:#e6fffb; color:#13c2c2; border:1px solid #87e8de;">BC 3.0</span>`;

                let badgeIssue = `<span class="tag-badge tag-red">⛔ ${escapeHtml(r.issue_label)}</span>`;
                if (r.issue_type === 'empty') badgeIssue = `<span class="tag-badge tag-orange">⚠️ ${escapeHtml(r.issue_label)}</span>`;

                let klasSelectHtml = `<select class="form-control" style="width:100%; font-size:10px; font-weight:600;" onchange="changeRowKlas(${idx}, this.value)">`;
                KLAS_OPTIONS.forEach(k => {
                    const sel = (k.tipe === r.new_klas) ? 'selected' : '';
                    klasSelectHtml += `<option value="${escapeHtml(k.tipe)}" ${sel}>${escapeHtml(k.tipe)}</option>`;
                });
                klasSelectHtml += `</select>`;

                html += `
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox" ${r.selected ? 'checked' : ''} onchange="toggleSelectRow(${idx}, this.checked)">
                        </td>
                        <td style="text-align:center; color:var(--text-muted);">${idx + 1}</td>
                        <td style="text-align:center;">${badgeBc}</td>
                        <td>
                            <div style="font-weight:600;">${escapeHtml(r.NOMOR_DAFTAR || '-')}</div>
                            <div style="font-size:9.5px; color:var(--text-muted);">${escapeHtml(r.TANGGAL_DAFTAR || '-')}</div>
                        </td>
                        <td>
                            <div style="font-size:10px; max-width:140px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(r.NO_BUKTI)}">
                                ${escapeHtml(r.NO_BUKTI || '-')}
                            </div>
                        </td>
                        <td style="text-align:center; font-weight:600;">${r.SERI_BARANG}</td>
                        <td>
                            <span style="font-family:monospace; font-weight:700; color:var(--primary); font-size:11px;">
                                ${escapeHtml(r.KODE_BARANG || '-')}
                            </span>
                        </td>
                        <td>
                            <div style="max-width:280px; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(r.URAIAN)}">
                                ${escapeHtml(r.URAIAN || '-')}
                            </div>
                        </td>
                        <td style="text-align:center; font-weight:600;">${escapeHtml(r.UNIT || '-')}</td>
                        <td style="text-align:right; font-family:monospace;">${Number(r.QTY).toLocaleString('id-ID', { minimumFractionDigits: 2 })}</td>
                        <td style="text-align:center;">${badgeIssue}</td>
                        <td>
                            ${klasSelectHtml}
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="ant-btn ant-btn-sm ant-btn-primary" onclick="saveSingleRow(${idx})">
                                <i class="fa-solid fa-floppy-disk"></i> Simpan
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            updateSelectedCounter();
        }

        function toggleSelectAll(checked) {
            invalidRows.forEach(r => r.selected = checked);
            renderTable();
        }

        function toggleSelectRow(idx, checked) {
            invalidRows[idx].selected = checked;
            updateSelectedCounter();
        }

        function changeRowKlas(idx, val) {
            invalidRows[idx].new_klas = val;
        }

        function updateSelectedCounter() {
            const count = invalidRows.filter(r => r.selected).length;
            document.getElementById('selectedCounter').innerText = count;
            const checkAll = document.getElementById('checkAll');
            if (checkAll) {
                checkAll.checked = (invalidRows.length > 0 && count === invalidRows.length);
            }
        }

        async function saveSingleRow(idx) {
            const row = invalidRows[idx];
            if (!row.new_klas || row.new_klas === '-') {
                Swal.fire('Validasi', 'Pilih klasifikasi yang valid!', 'warning');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'update_single');
            fd.append('bc', row.BC);
            fd.append('nomor_daftar', row.NOMOR_DAFTAR);
            fd.append('tanggal_daftar', row.TANGGAL_DAFTAR);
            fd.append('no_bukti', row.NO_BUKTI);
            fd.append('seri_barang', row.SERI_BARANG);
            fd.append('kode_barang', row.KODE_BARANG);
            fd.append('uraian', row.URAIAN);
            fd.append('klas', row.new_klas);

            try {
                const res = await fetch('klas_koreksi.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: json.message,
                        showConfirmButton: false,
                        timer: 2000
                    });
                    loadData();
                } else {
                    Swal.fire('Gagal Simpan', json.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error Server', err.message, 'error');
            }
        }

        async function executeBulkUpdate() {
            const targetKlas = document.getElementById('bulkTargetKlas').value;
            if (!targetKlas || targetKlas === '-') {
                Swal.fire('Pilih Klasifikasi Target', 'Silakan pilih klasifikasi tujuan pada dropdown aksi massal terlebih dahulu!', 'warning');
                return;
            }

            const selectedRows = invalidRows.filter(r => r.selected);
            if (selectedRows.length === 0) {
                Swal.fire('Pilih Baris', 'Centang setidaknya 1 baris transaksi yang ingin diupdate!', 'warning');
                return;
            }

            const confirm = await Swal.fire({
                title: 'Konfirmasi Update Massal?',
                html: `Anda akan mengubah Klasifikasi untuk <b>${selectedRows.length} baris transaksi</b> menjadi <b>'${targetKlas}'</b>${isAdmin ? ' di tabel <code>bc_1ab2</code>' : ''}.<br><br>Lanjutkan proses simpan?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: `Ya, Terapkan ke ${selectedRows.length} Baris`,
                cancelButtonText: 'Batal',
                confirmButtonColor: '#52c41a'
            });

            if (!confirm.isConfirmed) return;

            Swal.fire({
                title: 'Menyimpan perubahan...',
                text: `Memperbarui ${selectedRows.length} baris data transaksi...`,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const payloadRows = selectedRows.map(r => ({
                bc: r.BC,
                nomor_daftar: r.NOMOR_DAFTAR,
                tanggal_daftar: r.TANGGAL_DAFTAR,
                no_bukti: r.NO_BUKTI,
                seri_barang: r.SERI_BARANG,
                kode_barang: r.KODE_BARANG,
                uraian: r.URAIAN
            }));

            const fd = new FormData();
            fd.append('action', 'update_bulk');
            fd.append('target_klas', targetKlas);
            fd.append('rows', JSON.stringify(payloadRows));

            try {
                const res = await fetch('klas_koreksi.php', { method: 'POST', body: fd });
                const json = await res.json();
                Swal.close();

                if (json.status === 'OK') {
                    Swal.fire('Sukses Update Massal', json.message, 'success');
                    loadData();
                } else {
                    Swal.fire('Gagal Update Massal', json.message, 'error');
                }
            } catch (err) {
                Swal.close();
                Swal.fire('Error Server', err.message, 'error');
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        window.onload = loadData;
    </script>
</body>
</html>

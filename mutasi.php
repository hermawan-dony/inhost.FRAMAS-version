<?php
/**
 * CEISA 4.0 - IT Inventory Detail Mutasi Breakdown
 * Sesuai sumber: D:\xampp\htdocs\bc3\1d_bb\mutasi.php & app.framas.co.id:9990/bc3/1d_bb/mutasi.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/koneksi.php';

$cmp_id = $_GET['cmp_id'] ?? ($_SESSION['company_cmp_id'] ?? ($_SESSION['cmp_id'] ?? 'ALL'));
$cmp_name = $_SESSION['company_name'] ?? '';
if (!empty($cmp_id) && $cmp_id !== 'ALL') {
    ceisa_switch_company_db($cmp_id);
}

$escCmp = (!empty($cmp_id) && $cmp_id !== 'ALL' && isset($koneksi) && $koneksi) ? $koneksi->real_escape_string($cmp_id) : '';
$cmpDetailFilter = (!empty($escCmp)) ? " AND (a.cmp_id = '$escCmp' OR a.cmp_id = '')" : "";
$cmpIssueFilter  = (!empty($escCmp)) ? " AND (na.cmp_id = '$escCmp' OR na.cmp_id = '')" : "";

$kode  = trim($_GET["kode"] ?? '');
$awal  = trim($_GET["awal"] ?? date('Y-m-01'));
$akhir = trim($_GET["akhir"] ?? date('Y-m-d'));
$ab    = strtoupper(trim($_GET["ab"] ?? 'A'));
$klas  = trim($_GET["klas"] ?? '');

$bs = ($ab === 'A') ? 'Pengirim' : 'Penerima';
$m  = ($ab === 'A') ? 'MASUK' : 'KELUAR';

$escKode  = $koneksi->real_escape_string($kode);
$escAwal  = $koneksi->real_escape_string($awal);
$escAkhir = $koneksi->real_escape_string($akhir);
$escAb    = $koneksi->real_escape_string($ab);

// Query Breakdown Detail Mutasi (Eksternal bc_1ab2 + Internal bc_issue_hdr/dtl)
$sql = "
SELECT 'Eksternal' AS TIPE, a.KODE_BARANG, a.UNIT AS SATUAN, a.QTY_AKTUAL AS JUMLAH, a.TGL_BUKTI, a.NO_BUKTI, a.NAMA_BS, 
a.BC, a.NOMOR_DAFTAR AS NOMOR_DOK, a.TANGGAL_DAFTAR AS TGL_DOK, a.NOMOR_AJU, a.TGL_PROSES
FROM bc_1ab2 a
WHERE substr(date_format(a.tgl_proses, '%Y-%m'), 1, 4) = substr('$escAwal', 1, 4)
AND a.tgl_proses BETWEEN '$escAwal' AND '$escAkhir' AND a.ab = '$escAb' 
AND a.KODE_BARANG = '$escKode'
$cmpDetailFilter

UNION ALL

SELECT 'Issue' AS TIPE, na.kode_barang, na.sat AS SATUAN, na.qty AS JUMLAH, ni.tgl AS TGL_BUKTI, ni.no_bukti AS NO_BUKTI, '*** Issue Produksi ***' AS NAMA_BS, NULL AS BC, NULL AS NOMOR_DOK, NULL AS TGL_DOK, null AS NOMOR_AJU, null AS TGL_PROSES
FROM bc_issue_hdr ni, bc_issue_dtl na
WHERE ni.id = na.id_hdr and (ni.iscancel IS NULL OR ni.iscancel <> 'Y')
AND substr(ni.tgl, 1, 4) = substr('$escAwal', 1, 4)
AND ni.tgl BETWEEN '$escAwal' AND '$escAkhir' AND ni.ab = '$escAb' 
AND na.kode_barang = '$escKode' 
$cmpIssueFilter

ORDER BY TIPE, TGL_PROSES ASC, NO_BUKTI ASC
";

$hasil = $koneksi->query($sql);
$rows = [];
$sumjml = 0;
if ($hasil) {
    while ($r = $hasil->fetch_assoc()) {
        $sumjml += (float)$r['JUMLAH'];
        $rows[] = $r;
    }
}

// Ambil info nama barang dari master
$uraianBarang = '';
$qMat = $koneksi->query("SELECT URAIAN FROM bc_material WHERE KODE_BARANG = '$escKode' LIMIT 1");
if ($qMat && $rm = $qMat->fetch_assoc()) {
    $uraianBarang = $rm['URAIAN'];
}

$theme = $_GET['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detail Mutasi <?= htmlspecialchars($m) ?> - <?= htmlspecialchars($kode) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #f0f2f5;
            --bg-card: #ffffff;
            --border-card: #e8e8e8;
            --text-main: rgba(0, 0, 0, 0.85);
            --text-muted: #8c8c8c;
            --primary: #1890ff;
            --primary-hover: #40a9ff;
            --table-head-bg: #fafafa;
            --border-table: #f0f0f0;
            --row-hover: #f0f7ff;
            --yellow-subtotal: #ffe58f;
        }
        body[data-theme="dark"] {
            --bg-body: #141414;
            --bg-card: #1f1f1f;
            --border-card: #303030;
            --text-main: rgba(255, 255, 255, 0.85);
            --text-muted: #8c8c8c;
            --primary: #177ddc;
            --primary-hover: #1890ff;
            --table-head-bg: #1d1d1d;
            --border-table: #303030;
            --row-hover: #262638;
            --yellow-subtotal: #594214;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding: 10px;
            font-size: 9.5px;
        }
        .container {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 4px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 12px;
            background: var(--table-head-bg);
            border-bottom: 1px solid var(--border-card);
        }
        .header-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .header-sub {
            font-size: 9px;
            color: var(--text-muted);
            margin-top: 1px;
        }
        .btn-action {
            height: 22px;
            padding: 0 8px;
            font-size: 9.5px;
            font-weight: 600;
            border-radius: 3px;
            cursor: pointer;
            border: 1px solid var(--border-card);
            background: var(--bg-card);
            color: var(--text-main);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .btn-action:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .table-responsive {
            max-height: calc(100vh - 65px);
            overflow-y: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
        }
        th, td {
            padding: 3px 6px;
            border: 1px solid var(--border-table);
            white-space: nowrap;
        }
        th {
            background: var(--table-head-bg);
            font-weight: 600;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        tr:hover td {
            background: var(--row-hover);
        }
        .row-subtotal th, .row-subtotal td {
            background: var(--yellow-subtotal) !important;
            color: #000 !important;
            font-weight: 700;
            position: sticky;
            top: 25px;
            z-index: 1;
        }
        body[data-theme="dark"] .row-subtotal th, body[data-theme="dark"] .row-subtotal td {
            color: #fff !important;
        }
    </style>
</head>
<body data-theme="<?= htmlspecialchars($theme) ?>">
<div class="container">
    <div class="header-bar">
        <div>
            <div class="header-title">
                <i class="fa-solid <?= $ab === 'A' ? 'fa-arrow-down-long' : 'fa-arrow-up-long' ?>" style="color: <?= $ab === 'A' ? '#52c41a' : '#f5222d' ?>;"></i>
                Detail Mutasi <?= htmlspecialchars($m) ?> &bull; <?= htmlspecialchars($kode) ?> <?= $uraianBarang ? ' - ' . htmlspecialchars($uraianBarang) : '' ?>
            </div>
            <div class="header-sub">
                Periode: <?= htmlspecialchars($awal) ?> s/d <?= htmlspecialchars($akhir) ?> &bull; Total: <?= number_format($sumjml, 2) ?> &bull; <?= count($rows) ?> Transaksi
            </div>
        </div>
        <div style="display:flex; gap:6px;">
            <button type="button" class="btn-action" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Cetak
            </button>
            <button type="button" class="btn-action" onclick="window.close()">
                <i class="fa-solid fa-xmark"></i> Tutup
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width:30px;">No.</th>
                    <th>Kode Brg</th>
                    <th>Sat</th>
                    <th style="text-align:right;">Jumlah</th>
                    <th>Tgl Bukti</th>
                    <th>No. Bukti</th>
                    <th><?= htmlspecialchars($bs) ?></th>
                    <th>BC</th>
                    <th>Nomor Daftar</th>
                    <th>Tgl Daftar</th>
                    <th>Nomor AJU</th>
                    <th>Tgl Proses</th>
                </tr>
                <tr class="row-subtotal">
                    <th colspan="3" style="text-align:center;"><?= htmlspecialchars($m) ?></th>
                    <td style="text-align:right; font-weight:700;"><?= number_format($sumjml, 2) ?></td>
                    <th colspan="8"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="12" style="text-align:center; padding:16px; color:var(--text-muted);">
                            Tidak ada rincian mutasi <?= htmlspecialchars($m) ?> untuk kode barang ini pada periode terpilih.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td style="text-align:center; color:var(--text-muted);"><?= $no++ ?></td>
                            <td style="font-family:monospace; font-weight:600;"><?= htmlspecialchars($r['KODE_BARANG'] ?? '-') ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($r['SATUAN'] ?? '-') ?></td>
                            <td style="text-align:right; font-weight:600;"><?= number_format((float)($r['JUMLAH'] ?? 0), 2) ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($r['TGL_BUKTI'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['NO_BUKTI'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['NAMA_BS'] ?? '-') ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($r['BC'] ?? '-') ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($r['NOMOR_DOK'] ?? '-') ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($r['TGL_DOK'] ?? '-') ?></td>
                            <td style="text-align:center; font-family:monospace;"><?= htmlspecialchars($r['NOMOR_AJU'] ?? '-') ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($r['TGL_PROSES'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

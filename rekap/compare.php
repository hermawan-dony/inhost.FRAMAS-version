<?php
/**
 * Fitur: Croscek / Compare Data CEISA dengan IT Inventory (tabel bc_1ab2)
 */
require_once __DIR__ . '/../initmfa.php';
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../datalogin.php';

$sessionCmpId = $_SESSION['cmp_id'] ?? ($cmp_id ?? '');
$targetCmp = $_GET['cmp_id'] ?? $sessionCmpId;

if (!empty($targetCmp) && $targetCmp !== $cmp_id) {
    $cRow = ceisa_get_company($targetCmp);
    if ($cRow) {
        $namaperusahaan = $cRow['cmp_name'];
        $npwp           = $cRow['npwp'];
        $cmp_id         = $cRow['cmp_id'];
    }
}

$tahun_bulan = $_GET['periode'] ?? date('Y-m');
$dokumen_filter = $_GET['dokumen'] ?? 'all';
$cek_filter = $_GET['cek'] ?? '';
$search = trim($_GET['search'] ?? '');

$cleanCmpId = $koneksi->real_escape_string($cmp_id ?? '');
$whereCmp = !empty($cleanCmpId) ? "AND (a.cmp_id = '$cleanCmpId' OR a.cmp_id = '' OR a.cmp_id IS NULL)" : "";

$whereDoc = "";
if ($dokumen_filter !== 'all' && !empty($dokumen_filter)) {
    $whereDoc = "AND a.kodeDokumen = '" . $koneksi->real_escape_string($dokumen_filter) . "'";
}

$whereSearch = "";
if (!empty($search)) {
    $s = $koneksi->real_escape_string($search);
    $whereSearch = "AND (a.nomorAju LIKE '%$s%' OR a.nomorDaftar LIKE '%$s%' OR a.namaPerusahaan LIKE '%$s%')";
}

// Subquery cek ke tabel bc_1ab2
$sql = "
SELECT * FROM (
    SELECT dd.*,
           CASE WHEN ifnull(ab2_cnt, 0) > 0 THEN 'OK' ELSE '-' END AS cek
    FROM (
        SELECT 
            a.namaDokumen, 
            a.kodeDokumen, 
            a.nomorDaftar, 
            DATE_FORMAT(a.tanggalDaftar, '%Y-%m-%d') as tanggalDaftar, 
            a.nomorAju, 
            a.namaPerusahaan, 
            a.namaProses, 
            a.namaRespon, 
            a.pdf,
            (SELECT count(1) FROM bc_1ab2 x WHERE x.NOMOR_AJU = a.nomorAju) as ab2_cnt,
            (SELECT max(AB) FROM bc_1ab2 x WHERE x.NOMOR_AJU = a.nomorAju) as ab2_type
        FROM bc_ceisa_data a
        WHERE DATE_FORMAT(a.tanggalDaftar, '%Y-%m') = '" . $koneksi->real_escape_string($tahun_bulan) . "'
          $whereDoc
          $whereCmp
          $whereSearch
    ) dd
) final_tabel WHERE 1=1
";

if ($cek_filter === 'OK') {
    $sql .= " AND cek = 'OK'";
} elseif ($cek_filter === '-') {
    $sql .= " AND cek = '-'";
}
$sql .= " ORDER BY tanggalDaftar DESC, nomorAju DESC";

$result = $koneksi->query($sql);

$sama = 0;
$beda = 0;
$rows = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['cek'] === 'OK') {
            $sama++;
        } else {
            $beda++;
        }
        $rows[] = $row;
    }
}
$total = $sama + $beda;
$persenMatch = $total > 0 ? round(($sama / $total) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Compare IT Inventory - CEISA 4.0</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      font-size: 11.5px;
      background-color: #f0f2f5;
      color: #262626;
    }
    .navbar {
      background: #ffffff !important;
      padding: 6px 16px;
      box-shadow: 0 1px 4px rgba(0,21,41,0.08);
    }
    .navbar-brand {
      font-size: 13.5px;
      font-weight: 700;
      color: #1890ff !important;
    }
    .nav-link {
      font-size: 11.5px;
      font-weight: 500;
      padding: 6px 12px !important;
      color: #595959 !important;
    }
    .nav-item.active .nav-link {
      color: #1890ff !important;
      font-weight: 600;
      border-bottom: 2px solid #1890ff;
    }
    .container-fluid {
      padding: 14px 20px;
    }
    .card-ui {
      background: #ffffff;
      border: 1px solid #e8e8e8;
      border-radius: 4px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      padding: 14px 16px;
      margin-bottom: 14px;
    }
    table.table-custom {
      width: 100%;
      border-collapse: collapse;
      font-size: 11px;
    }
    table.table-custom th, table.table-custom td {
      border: 1px solid #e8e8e8;
      padding: 6px 8px;
      vertical-align: middle;
    }
    table.table-custom th {
      background-color: #fafafa;
      font-weight: 600;
      text-align: center;
    }
    .table-hover tbody tr:hover {
      background-color: #f5f5f5;
    }
    .btn-custom {
      font-size: 11.5px;
      padding: 3px 12px;
      height: 26px;
      line-height: 18px;
      border-radius: 3px;
    }
    .cell-ok {
      background-color: #f6ffed !important;
      color: #52c41a;
      font-weight: 700;
      text-align: center;
    }
    .cell-unmatch {
      background-color: #fff1f0 !important;
      color: #ff4d4f;
      font-weight: 700;
      text-align: center;
    }
    .kpi-box {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }
    .kpi-chip {
      background: #fafafa;
      border: 1px solid #d9d9d9;
      border-radius: 4px;
      padding: 4px 10px;
      font-size: 11px;
    }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light border-bottom">
  <a class="navbar-brand" href="index.php?cmp_id=<?= urlencode($cmp_id) ?>">
    📦 Rekap CEISA 4.0 <span style="font-size:11px; color:#8c8c8c; font-weight:normal;">| <?= htmlspecialchars($namaperusahaan) ?></span>
  </a>
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navRekap">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navRekap">
    <ul class="navbar-nav mr-auto">
      <li class="nav-item">
        <a class="nav-link" href="index.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-chart-pie mr-1"></i> Summary Rekap</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="sync_all.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-bolt mr-1"></i> Sync ALL DATA</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="refresh.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-sync-alt mr-1"></i> Refresh Belum Selesai</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="bc27in.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-file-import mr-1"></i> Tarik BC 2.7 IN</a>
      </li>
      <li class="nav-item active">
        <a class="nav-link" href="compare.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-check-double mr-1"></i> Compare IT Inventory</a>
      </li>
    </ul>
    <div>
      <a href="../daftar_dokumen.php?cmp_id=<?= urlencode($cmp_id) ?>" class="btn btn-outline-primary btn-custom">
        <i class="fas fa-arrow-left mr-1"></i> Dokumen CEISA
      </a>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="card-ui">
    <form method="GET" class="form-inline d-flex justify-content-between flex-wrap" style="gap:8px;">
      <input type="hidden" name="cmp_id" value="<?= htmlspecialchars($cmp_id) ?>">

      <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
        <label class="font-weight-bold mr-1">Periode:</label>
        <input type="month" class="form-control form-control-sm" name="periode" value="<?= htmlspecialchars($tahun_bulan) ?>" style="font-size:11px; height:26px;">

        <label class="font-weight-bold ml-2 mr-1">Dokumen:</label>
        <select name="dokumen" class="form-control form-control-sm" style="font-size:11px; height:26px;">
          <option value="all">Semua Dokumen</option>
          <option value="16" <?= $dokumen_filter === '16' ? 'selected' : '' ?>>BC 1.6</option>
          <option value="20" <?= $dokumen_filter === '20' ? 'selected' : '' ?>>BC 2.0</option>
          <option value="23" <?= $dokumen_filter === '23' ? 'selected' : '' ?>>BC 2.3</option>
          <option value="25" <?= $dokumen_filter === '25' ? 'selected' : '' ?>>BC 2.5</option>
          <option value="27" <?= $dokumen_filter === '27' ? 'selected' : '' ?>>BC 2.7</option>
          <option value="30" <?= $dokumen_filter === '30' ? 'selected' : '' ?>>BC 3.0</option>
          <option value="40" <?= $dokumen_filter === '40' ? 'selected' : '' ?>>BC 4.0</option>
          <option value="41" <?= $dokumen_filter === '41' ? 'selected' : '' ?>>BC 4.1</option>
          <option value="261" <?= $dokumen_filter === '261' ? 'selected' : '' ?>>BC 2.6.1</option>
          <option value="262" <?= $dokumen_filter === '262' ? 'selected' : '' ?>>BC 2.6.2</option>
        </select>

        <label class="font-weight-bold ml-2 mr-1">Status IT:</label>
        <select name="cek" class="form-control form-control-sm" style="font-size:11px; height:26px;">
          <option value="">Semua Status</option>
          <option value="OK" <?= $cek_filter === 'OK' ? 'selected' : '' ?>>Sudah Masuk (OK)</option>
          <option value="-" <?= $cek_filter === '-' ? 'selected' : '' ?>>Belum Masuk (-)</option>
        </select>

        <input type="text" class="form-control form-control-sm ml-2" name="search" placeholder="Cari AJU / Daftar" value="<?= htmlspecialchars($search) ?>" style="font-size:11px; height:26px; width:160px;">

        <button type="submit" class="btn btn-primary btn-custom"><i class="fas fa-search mr-1"></i> Filter</button>
      </div>

      <div class="d-flex align-items-center" style="gap:6px;">
        <button type="button" class="btn btn-success btn-custom font-weight-bold" onclick="exportCompareExcel()">
          <i class="fas fa-file-excel mr-1"></i> Export Excel
        </button>
      </div>
    </form>

    <!-- Metrics Bar -->
    <div class="kpi-box mt-3 pt-3 border-top">
      <div class="kpi-chip">
        Total Dokumen CEISA: <strong class="text-dark"><?= $total ?></strong>
      </div>
      <div class="kpi-chip" style="background:#f6ffed; border-color:#b7eb8f;">
        Sudah Masuk IT Inventory: <strong class="text-success"><?= $sama ?> (<?= $persenMatch ?>%)</strong>
      </div>
      <div class="kpi-chip" style="background:#fff1f0; border-color:#ffa39e;">
        Belum Masuk IT Inventory: <strong class="text-danger"><?= $beda ?> (<?= 100 - $persenMatch ?>%)</strong>
      </div>
    </div>
  </div>

  <div class="card-ui p-0" style="overflow:hidden;">
    <div class="table-responsive">
      <table class="table-custom" id="tableCompare">
        <thead>
          <tr>
            <th style="width:35px;">No</th>
            <th style="width:50px;">PDF</th>
            <th style="width:65px;">Jenis BC</th>
            <th style="width:85px;">No Daftar</th>
            <th style="width:80px;">Tgl Daftar</th>
            <th style="width:200px;">Nomor AJU</th>
            <th>Nama Perusahaan</th>
            <th>Proses CEISA</th>
            <th style="width:85px;">Status IT</th>
            <th style="width:60px;">Tipe IT</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) > 0): ?>
            <?php foreach ($rows as $idx => $r): ?>
              <tr>
                <td class="text-center font-weight-bold"><?= $idx + 1 ?></td>
                <td class="text-center">
                  <?php if (!empty($r['pdf'])): ?>
                    <a href="/pdf.php?aju=<?= urlencode($r['nomorAju']) ?>&pdf=<?= urlencode($r['pdf']) ?>&cmp_id=<?= urlencode($cmp_id) ?>&view=1" target="_blank" class="btn btn-sm btn-outline-danger" style="padding:1px 5px; font-size:10px;">PDF</a>
                  <?php else: ?>-<?php endif; ?>
                </td>
                <td class="text-center font-weight-bold">BC <?= htmlspecialchars($r['kodeDokumen']) ?></td>
                <td class="text-center font-weight-bold"><?= htmlspecialchars($r['nomorDaftar'] ?: '-') ?></td>
                <td class="text-center"><?= htmlspecialchars($r['tanggalDaftar'] ?: '-') ?></td>
                <td class="text-monospace font-weight-bold" style="color:#0050b3;"><?= htmlspecialchars($r['nomorAju']) ?></td>
                <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($r['namaPerusahaan']) ?>"><?= htmlspecialchars($r['namaPerusahaan']) ?></td>
                <td><?= htmlspecialchars($r['namaProses'] ?: '-') ?></td>
                <td class="<?= $r['cek'] === 'OK' ? 'cell-ok' : 'cell-unmatch' ?>">
                  <?= $r['cek'] === 'OK' ? '✓ OK' : '✗ Belum' ?>
                </td>
                <td class="text-center">
                  <?php if ($r['ab2_type'] === 'A'): ?>
                    <span class="badge badge-primary" style="font-size:10px;">IN</span>
                  <?php elseif ($r['ab2_type'] === 'B'): ?>
                    <span class="badge badge-warning" style="font-size:10px;">OUT</span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="10" class="text-center py-4 text-muted">
                Tidak ada data perbandingan dokumen yang sesuai dengan filter.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.4/xlsx.full.min.js"></script>
<script>
function exportCompareExcel() {
  const table = document.querySelector("#tableCompare");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tr")).map((tr, index) =>
    Array.from(tr.querySelectorAll("th, td")).map((td, colIndex) => {
      let text = td.innerText.trim();
      if (index > 0 && (colIndex === 3 || colIndex === 5)) {
        return { t: "s", v: text };
      }
      return text;
    })
  );

  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet(rows);
  XLSX.utils.book_append_sheet(wb, ws, "Sheet1");
  const fileName = `Compare_IT_Inventory_<?= htmlspecialchars($tahun_bulan) ?>.xlsx`;
  XLSX.writeFile(wb, fileName);
}
</script>
</body>
</html>

<?php
/**
 * Fitur: Refresh Data Belum Selesai CEISA 4.0
 * Menyinkronkan ulang status nomor AJU yang statusnya <> 'Selesai Proses'
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

$awal   = $_GET['awal'] ?? date('Y-m-01', strtotime('-1 month'));
$akhir  = $_GET['akhir'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

$cleanCmpId = $koneksi->real_escape_string($cmp_id ?? '');
$whereCmp = !empty($cleanCmpId) ? "AND (cmp_id = '$cleanCmpId' OR cmp_id = '' OR cmp_id IS NULL)" : "";

$query = "
    SELECT nomorDaftar, 
           DATE_FORMAT(tanggalDaftar, '%Y-%m-%d') as tanggalDaftar, 
           idPerusahaan, 
           nomorAju, 
           kodeDokumen,
           namaProses, 
           namaRespon, 
           nomorRespon,
           pdf
    FROM bc_ceisa_data 
    WHERE tanggalDaftar BETWEEN ? AND ? 
      AND namaProses <> 'Selesai Proses'
      $whereCmp
";

if (!empty($search)) {
    $query .= " AND (nomorAju LIKE ? OR nomorDaftar LIKE ?)";
}
$query .= " ORDER BY tanggalDaftar DESC, nomorAju DESC";

$stmt = $koneksi->prepare($query);
if (!empty($search)) {
    $sp = "%$search%";
    $stmt->bind_param('ssss', $awal, $akhir, $sp, $sp);
} else {
    $stmt->bind_param('ss', $awal, $akhir);
}
$stmt->execute();
$result = $stmt->get_result();

$data_rows = [];
while ($row = $result->fetch_assoc()) {
    $data_rows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Refresh Data Belum Selesai - CEISA 4.0</title>
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
    .status-badge {
      font-size: 10px;
      padding: 2px 6px;
    }
    .processing-row { background-color: #fffbe6 !important; }
    .success-row { background-color: #f6ffed !important; }
    .error-row { background-color: #fff1f0 !important; }
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
      <li class="nav-item active">
        <a class="nav-link" href="refresh.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-sync-alt mr-1"></i> Refresh Belum Selesai</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="bc27in.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-file-import mr-1"></i> Tarik BC 2.7 IN</a>
      </li>
      <li class="nav-item">
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
        <input type="date" class="form-control form-control-sm" name="awal" value="<?= htmlspecialchars($awal) ?>" style="font-size:11px; height:26px;">
        <span>s/d</span>
        <input type="date" class="form-control form-control-sm" name="akhir" value="<?= htmlspecialchars($akhir) ?>" style="font-size:11px; height:26px;">
        
        <input type="text" class="form-control form-control-sm ml-2" name="search" placeholder="Cari No AJU / No Daftar" value="<?= htmlspecialchars($search) ?>" style="font-size:11px; height:26px; width:180px;">
        <button type="submit" class="btn btn-primary btn-custom"><i class="fas fa-search mr-1"></i> Filter</button>
      </div>

      <div class="d-flex align-items-center" style="gap:8px;">
        <span class="badge badge-danger p-2" style="font-size:11px;">
          <i class="fas fa-clock mr-1"></i> Belum Selesai: <strong><?= count($data_rows) ?></strong> Dokumen
        </span>
        <button type="button" id="btnMasterRefresh" class="btn btn-danger btn-custom font-weight-bold" onclick="startMasterRefresh()" <?= count($data_rows) === 0 ? 'disabled' : '' ?>>
          <i class="fas fa-sync-alt mr-1"></i> SINKRONKAN SEMUA (<?= count($data_rows) ?>)
        </button>
      </div>
    </form>

    <!-- Progress Bar -->
    <div id="progressContainer" class="mt-3 pt-3 border-top" style="display:none;">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="font-weight-bold" id="progressTitle">Sedang Menyinkronkan Status ke Portal CEISA...</span>
        <span class="font-weight-bold text-primary" id="progressPercentage">0%</span>
      </div>
      <div class="progress" style="height: 18px;">
        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success font-weight-bold" style="width: 0%; font-size:10px;">0%</div>
      </div>
      <small class="text-muted d-block mt-1" id="progressDetail">Memproses: 0 / <?= count($data_rows) ?></small>
    </div>
  </div>

  <div class="card-ui p-0" style="overflow:hidden;">
    <div class="table-responsive">
      <table class="table-custom" id="tableCeisa">
        <thead>
          <tr>
            <th style="width:35px;">No</th>
            <th style="width:85px;">Status</th>
            <th style="width:80px;">Tgl Daftar</th>
            <th style="width:85px;">No Daftar</th>
            <th style="width:200px;">Nomor AJU</th>
            <th style="width:60px;">Dokumen</th>
            <th>Nama Proses</th>
            <th>Nama Respon</th>
            <th style="width:110px;">No Respon</th>
            <th style="width:55px;">PDF</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($data_rows) > 0): ?>
            <?php foreach ($data_rows as $idx => $r): ?>
              <tr id="row-<?= $idx ?>" data-npwp="<?= htmlspecialchars($r['idPerusahaan'] ?? $npwp) ?>" data-aju="<?= htmlspecialchars($r['nomorAju']) ?>">
                <td class="text-center font-weight-bold"><?= $idx + 1 ?></td>
                <td class="text-center" id="status-<?= $idx ?>">
                  <span class="badge badge-warning status-badge"><i class="fas fa-clock mr-1"></i> Pending</span>
                </td>
                <td class="text-center"><?= htmlspecialchars($r['tanggalDaftar']) ?></td>
                <td class="text-center font-weight-bold"><?= htmlspecialchars($r['nomorDaftar']) ?></td>
                <td class="text-monospace font-weight-bold" style="color:#0050b3;"><?= htmlspecialchars($r['nomorAju']) ?></td>
                <td class="text-center font-weight-bold">BC <?= htmlspecialchars($r['kodeDokumen']) ?></td>
                <td id="proses-<?= $idx ?>"><?= htmlspecialchars($r['namaProses']) ?></td>
                <td id="respon-<?= $idx ?>"><?= htmlspecialchars($r['namaRespon'] ?: '-') ?></td>
                <td class="text-center" id="norespon-<?= $idx ?>"><?= htmlspecialchars($r['nomorRespon'] ?: '-') ?></td>
                <td class="text-center">
                  <?php if (!empty($r['pdf'])): ?>
                    <a href="/pdf.php?aju=<?= urlencode($r['nomorAju']) ?>&pdf=<?= urlencode($r['pdf']) ?>&cmp_id=<?= urlencode($cmp_id) ?>&view=1" target="_blank" class="btn btn-sm btn-outline-danger" style="padding:1px 5px; font-size:10px;">PDF</a>
                  <?php else: ?>-<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="10" class="text-center py-4 text-muted">
                <i class="fas fa-check-circle text-success fa-2x mb-2 d-block"></i>
                Tidak ada dokumen yang belum selesai pada periode ini. Semua dokumen telah berstatus "Selesai Proses".
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script>
const totalData = <?= count($data_rows) ?>;
const activeCmpId = '<?= htmlspecialchars($cmp_id) ?>';

async function startMasterRefresh() {
  if (totalData === 0) return;
  if (!confirm(`Sistem akan menyinkronkan status untuk ${totalData} nomor AJU yang belum selesai. Lanjutkan?`)) return;

  $('#btnMasterRefresh').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sedang Menyinkronkan...');
  $('#progressContainer').slideDown();

  let successCount = 0;

  for (let i = 0; i < totalData; i++) {
    const rowEl = $(`#row-${i}`);
    const statusEl = $(`#status-${i}`);
    const npwp = rowEl.data('npwp');
    const aju = rowEl.data('aju');

    rowEl.removeClass('success-row error-row').addClass('processing-row');
    statusEl.html('<span class="badge badge-info status-badge"><i class="fas fa-sync-alt fa-spin mr-1"></i> Cek</span>');
    $('#progressDetail').text(`Memproses: ${i + 1} / ${totalData} (AJU: ${aju})`);

    try {
      const res = await fetch(`cekaju.php?cmp_id=${encodeURIComponent(activeCmpId)}&npwp=${encodeURIComponent(npwp)}&aju=${encodeURIComponent(aju)}`).then(r => r.json());
      if (res.status) {
        rowEl.removeClass('processing-row').addClass('success-row');
        if (res.namaProses === 'Selesai Proses') {
          statusEl.html('<span class="badge badge-success status-badge">✓ Selesai</span>');
        } else {
          statusEl.html('<span class="badge badge-primary status-badge">✓ Updated</span>');
        }
        $(`#proses-${i}`).text(res.namaProses || '-');
        $(`#respon-${i}`).text(res.namaRespon || '-');
        successCount++;
      } else {
        rowEl.removeClass('processing-row').addClass('error-row');
        statusEl.html('<span class="badge badge-danger status-badge">Gagal</span>');
      }
    } catch (e) {
      rowEl.removeClass('processing-row').addClass('error-row');
      statusEl.html('<span class="badge badge-danger status-badge">Error</span>');
    }

    const pct = Math.round(((i + 1) / totalData) * 100);
    $('#progressBar').css('width', `${pct}%`).text(`${pct}%`);
    $('#progressPercentage').text(`${pct}%`);

    // Anti-WAF Rate limiting delay 600ms
    await new Promise(r => setTimeout(r, 600));
  }

  $('#progressTitle').html('<span class="text-success font-weight-bold"><i class="fas fa-check-double mr-1"></i> Selesai Memperbarui Status!</span>');
  $('#btnMasterRefresh').prop('disabled', false).html('<i class="fas fa-check mr-1"></i> Selesai Sinkronisasi');

  setTimeout(() => {
    alert(`Sinkronisasi selesai! Berhasil memperbarui ${successCount} dari ${totalData} dokumen.`);
    location.reload();
  }, 1000);
}
</script>
</body>
</html>

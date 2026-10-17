<?php
/**
 * Rekap Dokumen CEISA 4.0 Dashboard
 * Berdasarkan referensi http://203.160.60.101/bc/rekap/
 * Terintegrasi Multi-Company & IT Inventory ceisa0400db
 */
require_once __DIR__ . '/../initmfa.php';
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/../datalogin.php';

$sessionCmpId = $_SESSION['cmp_id'] ?? ($cmp_id ?? '');
$targetCmp = $_GET['cmp_id'] ?? $sessionCmpId;

// Pastikan data login sesuai company yang dipilih
if (!empty($targetCmp) && $targetCmp !== $cmp_id) {
    $cRow = ceisa_get_company($targetCmp);
    if ($cRow) {
        $namaperusahaan = $cRow['cmp_name'];
        $npwp           = $cRow['npwp'];
        $cmp_id         = $cRow['cmp_id'];
    }
}

$belum_selesai = isset($_GET['belum_selesai']) && $_GET['belum_selesai'] == '1' ? 1 : 0;

// Escape nama perusahaan untuk pemisahan BC 2.7 IN vs OUT
$cleanNamaPrs = $koneksi->real_escape_string($namaperusahaan ?? 'FRAMAS');
$cleanCmpId   = $koneksi->real_escape_string($cmp_id ?? '');

// Filter company jika ada
$whereCmp = "";
if (!empty($cleanCmpId)) {
    $whereCmp = "WHERE (cmp_id = '$cleanCmpId' OR cmp_id = '' OR cmp_id IS NULL)";
}

$query = "
SELECT ym, 
    SUM(CASE WHEN kodeDokumen = '16' THEN 1 ELSE 0 END) bc16,
    SUM(CASE WHEN kodeDokumen = '16' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc16_unfin,
    
    SUM(CASE WHEN kodeDokumen = '20' THEN 1 ELSE 0 END) bc20,
    SUM(CASE WHEN kodeDokumen = '20' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc20_unfin,

    SUM(CASE WHEN kodeDokumen = '23' THEN 1 ELSE 0 END) bc23,
    SUM(CASE WHEN kodeDokumen = '23' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc23_unfin,
    
    SUM(CASE WHEN kodeDokumen = '40' THEN 1 ELSE 0 END) bc40,
    SUM(CASE WHEN kodeDokumen = '40' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc40_unfin,
    
    SUM(CASE WHEN kodeDokumen = '27IN' THEN 1 ELSE 0 END) bc27in,
    SUM(CASE WHEN kodeDokumen = '27IN' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc27in_unfin,
    
    SUM(CASE WHEN kodeDokumen = '262' THEN 1 ELSE 0 END) bc262,
    SUM(CASE WHEN kodeDokumen = '262' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc262_unfin,
    
    SUM(CASE WHEN kodeDokumen = '27' THEN 1 ELSE 0 END) bc27,
    SUM(CASE WHEN kodeDokumen = '27' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc27_unfin,
    
    SUM(CASE WHEN kodeDokumen = '25' THEN 1 ELSE 0 END) bc25,
    SUM(CASE WHEN kodeDokumen = '25' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc25_unfin,
    
    SUM(CASE WHEN kodeDokumen = '41' THEN 1 ELSE 0 END) bc41,
    SUM(CASE WHEN kodeDokumen = '41' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc41_unfin,
    
    SUM(CASE WHEN kodeDokumen = '261' THEN 1 ELSE 0 END) bc261,
    SUM(CASE WHEN kodeDokumen = '261' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc261_unfin,
    
    SUM(CASE WHEN kodeDokumen = '30' THEN 1 ELSE 0 END) bc30,
    SUM(CASE WHEN kodeDokumen = '30' AND namaProses <> 'Selesai Proses' THEN 1 ELSE 0 END) bc30_unfin
FROM 
(
    SELECT date_format(tanggalDaftar, '%Y-%m') ym, tanggalDaftar, namaProses, idPerusahaan, nomorAju,
    CASE WHEN kodeDokumen = '27' AND (NOT namaPerusahaan LIKE '%$cleanNamaPrs%') THEN '27IN' ELSE kodeDokumen END kodeDokumen
    FROM bc_ceisa_data
    $whereCmp
) xx
WHERE ym IS NOT NULL
GROUP BY ym
ORDER BY ym DESC
";

$result = $koneksi->query($query);
if (!$result) {
    die('Database Query Error: ' . $koneksi->error);
}

// Ambil daftar perusahaan untuk filter jika multi company
$companies = [];
$cRes = $koneksi->query("SELECT cmp_id, cmp_name FROM bc_ceisa_company WHERE is_active = 1 ORDER BY cmp_name ASC");
if ($cRes) {
    while ($cr = $cRes->fetch_assoc()) {
        $companies[] = $cr;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rekap Data CEISA 4.0 - Dashboard</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      font-size: 11.5px;
      background-color: #f0f2f5;
      color: #262626;
      margin: 0;
      padding: 0;
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
      padding: 12px 18px;
    }
    .card-rekap {
      background: #ffffff;
      border: 1px solid #e8e8e8;
      border-radius: 4px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      margin-bottom: 16px;
    }
    .card-rekap-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 14px;
      border-bottom: 1px solid #f0f0f0;
      background: #fafafa;
    }
    .card-rekap-title {
      font-size: 13px;
      font-weight: 600;
      color: #262626;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    table.table-rekap {
      width: 100%;
      border-collapse: collapse;
      font-size: 11px;
    }
    table.table-rekap th, table.table-rekap td {
      border: 1px solid #e8e8e8;
      padding: 6px 8px;
      text-align: center;
      vertical-align: middle;
    }
    table.table-rekap th {
      font-weight: 600;
    }
    .th-masuk {
      background-color: #e6f4ff !important;
      color: #0050b3;
    }
    .th-keluar {
      background-color: #fffbe6 !important;
      color: #ad6800;
    }
    .table-rekap tbody tr:hover {
      background-color: #f5f5f5;
    }
    .table-rekap a {
      color: #1890ff;
      font-weight: 600;
      text-decoration: none;
      display: block;
      padding: 2px 4px;
      border-radius: 2px;
    }
    .table-rekap a:hover {
      background-color: #e6f7ff;
      text-decoration: none;
    }
    .unfin-count {
      color: #ff4d4f;
      font-weight: 700;
      background: #fff1f0;
      padding: 1px 4px;
      border-radius: 3px;
      border: 1px solid #ffa39e;
      margin-left: 2px;
    }
    .modal-xl {
      max-width: 96% !important;
    }
    .modal-header {
      padding: 8px 14px;
      background: #fafafa;
      border-bottom: 1px solid #f0f0f0;
    }
    .modal-title {
      font-size: 13px;
      font-weight: 600;
    }
    .modal-body {
      padding: 12px;
      max-height: 72vh;
      overflow-y: auto;
      font-size: 11px;
    }
    .modal-footer {
      padding: 8px 14px;
      background: #fafafa;
      border-top: 1px solid #f0f0f0;
    }
    .btn-custom {
      font-size: 11px;
      padding: 3px 10px;
      height: 26px;
      line-height: 18px;
      border-radius: 3px;
    }
    .badge-jalur {
      padding: 2px 6px;
      font-weight: 600;
      border-radius: 2px;
      font-size: 10px;
    }
    .badge-jalur-h {
      background: #f6ffed;
      color: #52c41a;
      border: 1px solid #b7eb8f;
    }
    .badge-jalur-m {
      background: #fff1f0;
      color: #ff4d4f;
      border: 1px solid #ffa39e;
    }
    .badge-jalur-k {
      background: #fffbe6;
      color: #faad14;
      border: 1px solid #ffe58f;
    }
    .processing-row {
      background-color: #fffbe6 !important;
    }
    .success-row {
      background-color: #f6ffed !important;
    }
    .error-row {
      background-color: #fff1f0 !important;
    }
  </style>
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-light border-bottom">
  <a class="navbar-brand" href="index.php?cmp_id=<?= urlencode($cmp_id) ?>">
    📦 Rekap CEISA 4.0 <span style="font-size:11px; color:#8c8c8c; font-weight:normal;">| <?= htmlspecialchars($namaperusahaan) ?></span>
  </a>
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navRekap">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="navRekap">
    <ul class="navbar-nav mr-auto">
      <li class="nav-item active">
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
      <li class="nav-item">
        <a class="nav-link" href="compare.php?cmp_id=<?= urlencode($cmp_id) ?>"><i class="fas fa-check-double mr-1"></i> Compare IT Inventory</a>
      </li>
    </ul>
    <div class="d-flex align-items-center" style="gap:8px;">
      <?php if (count($companies) > 1): ?>
        <select class="form-control form-control-sm" style="width:160px; font-size:11px; height:26px;" onchange="location.href='index.php?cmp_id='+encodeURIComponent(this.value)+'&belum_selesai=<?= $belum_selesai ?>'">
          <?php foreach ($companies as $c): ?>
            <option value="<?= htmlspecialchars($c['cmp_id']) ?>" <?= ($c['cmp_id'] === $cmp_id) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['cmp_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <button class="btn btn-outline-secondary btn-custom" onclick="window.location.reload();">
        <i class="fas fa-redo-alt mr-1"></i> Muat Ulang
      </button>
      <a href="../daftar_dokumen.php?cmp_id=<?= urlencode($cmp_id) ?>" class="btn btn-outline-primary btn-custom">
        <i class="fas fa-arrow-left mr-1"></i> Dokumen CEISA
      </a>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="card-rekap">
    <div class="card-rekap-head">
      <div class="card-rekap-title">
        <span>📊 Rekap Matriks Dokumen Kepabeanan CEISA 4.0</span>
        <span class="badge badge-info" style="font-size:10px; font-weight:normal;"><?= htmlspecialchars($namaperusahaan) ?></span>
      </div>
      <div>
        <form method="GET" action="" id="filterForm" class="form-inline m-0">
          <input type="hidden" name="cmp_id" value="<?= htmlspecialchars($cmp_id) ?>">
          <div class="custom-control custom-checkbox mr-2">
            <input type="checkbox" class="custom-control-input" id="belum_selesai" name="belum_selesai" value="1" <?= $belum_selesai ? 'checked' : '' ?> onchange="document.getElementById('filterForm').submit();">
            <label class="custom-control-label" style="cursor:pointer; font-weight:600; color:#cf1322;" for="belum_selesai">
              ⚠️ Tampilkan Belum Selesai (<span style="color:#cf1322;">&lt;&gt; Selesai Proses</span>)
            </label>
          </div>
        </form>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table-rekap">
        <thead>
          <tr>
            <th rowspan="2" style="width:90px; background:#fafafa;">Periode</th>
            <th colspan="6" class="th-masuk">📥 DOKUMEN MASUK</th>
            <th colspan="5" class="th-keluar">📤 DOKUMEN KELUAR</th>
          </tr>
          <tr>
            <th class="th-masuk">BC 1.6</th>
            <th class="th-masuk">BC 2.0</th>
            <th class="th-masuk">BC 2.3</th>
            <th class="th-masuk">BC 4.0</th>
            <th class="th-masuk">BC 2.7 IN</th>
            <th class="th-masuk">BC 2.6.2</th>
            <th class="th-keluar">BC 2.7</th>
            <th class="th-keluar">BC 2.5</th>
            <th class="th-keluar">BC 4.1</th>
            <th class="th-keluar">BC 2.6.1</th>
            <th class="th-keluar">BC 3.0</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $hasRows = false;
          while ($row = $result->fetch_assoc()): 
            $hasRows = true;
          ?>
            <tr>
              <td style="font-weight:600; background:#fafafa; font-family:monospace;"><?= htmlspecialchars($row['ym']) ?></td>
              <?php
              $bc_types = [
                  '16' => 'bc16', '20' => 'bc20', '23' => 'bc23', '40' => 'bc40', '27IN' => 'bc27in', '262' => 'bc262',
                  '27' => 'bc27', '25' => 'bc25', '41' => 'bc41', '261' => 'bc261', '30' => 'bc30'
              ];
              foreach ($bc_types as $code => $field) {
                  $total = (int)$row[$field];
                  $unfin = (int)$row[$field . '_unfin'];
                  
                  if ($belum_selesai && $unfin > 0) {
                      $display_text = "<span>{$total}</span> / <span class='unfin-count'>{$unfin}</span>";
                  } else {
                      $display_text = "<span>{$total}</span>";
                  }

                  if ($total > 0) {
                      echo "<td><a href='javascript:void(0);' onclick=\"showDetails('{$row['ym']}', '{$code}')\">{$display_text}</a></td>";
                  } else {
                      echo "<td style='color:#bfbfbf;'>0</td>";
                  }
              }
              ?>
            </tr>
          <?php endwhile; ?>
          <?php if (!$hasRows): ?>
            <tr>
              <td colspan="12" style="padding:24px; color:#8c8c8c;">
                Belum ada data dokumen CEISA 4.0 yang tersimpan pada tabel `bc_ceisa_data`. Silakan lakukan Sync ALL DATA.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL DETAIL REKAP -->
<div id="modalDetail" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header d-flex justify-content-between align-items-center">
        <h5 class="modal-title m-0">
          📄 Detail Dokumen CEISA 4.0 
          <span id="modal-subtitle" class="text-primary ml-2 font-weight-bold" style="font-size:12px;"></span>
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <!-- Realtime Progress Bar for Refresh -->
      <div id="modal-progress-container" class="px-3 pt-2" style="display:none;">
        <div class="progress" style="height: 18px;">
          <div id="modal-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success font-weight-bold" style="width: 0%; font-size:10px;">0%</div>
        </div>
        <small id="modal-progress-text" class="text-muted d-block mt-1">Memproses sinkronisasi data...</small>
      </div>

      <div class="modal-body" id="modal-detail-body">
        <p class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i> Memuat detail...</p>
      </div>

      <div class="modal-footer d-flex justify-content-between align-items-center">
        <div>
          <button type="button" id="btn-modal-refresh" class="btn btn-danger btn-custom font-weight-bold" onclick="startModalBulkRefresh()">
            <i class="fas fa-sync-alt mr-1"></i> REFRESH DATA BELUM SELESAI
          </button>
        </div>
        <div style="display:flex; gap:6px;">
          <button type="button" class="btn btn-success btn-custom font-weight-bold" onclick="exportToExcel()">
            <i class="fas fa-file-excel mr-1"></i> Export Excel
          </button>
          <button type="button" class="btn btn-secondary btn-custom" data-dismiss="modal">Tutup</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.4/xlsx.full.min.js"></script>
<script>
let currentYm = '';
let currentKodeDokumen = '';
let currentModalData = [];
const activeCmpId = '<?= htmlspecialchars($cmp_id) ?>';

function showDetails(ym, kodeDokumen) {
  currentYm = ym;
  currentKodeDokumen = kodeDokumen;

  document.getElementById('modal-subtitle').innerText = `(BC ${kodeDokumen} • Periode ${ym})`;
  $('#modal-progress-container').hide();
  $('#btn-modal-refresh').prop('disabled', false).html('<i class="fas fa-sync-alt mr-1"></i> REFRESH DATA BELUM SELESAI');
  document.getElementById('modal-detail-body').innerHTML = '<p class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i> Memuat detail data dokumen...</p>';

  const urlParams = new URLSearchParams(window.location.search);
  const onlyUnfinished = urlParams.get('belum_selesai') ? '&unfin=1' : '';

  fetch(`get_details.php?ym=${encodeURIComponent(ym)}&kodeDokumen=${encodeURIComponent(kodeDokumen)}&cmp_id=${encodeURIComponent(activeCmpId)}${onlyUnfinished}`)
    .then(r => r.json())
    .then(data => {
      currentModalData = data;
      if (!data || data.length === 0) {
        document.getElementById('modal-detail-body').innerHTML = '<div class="alert alert-info text-center m-2">Tidak ada data pabean ditemukan untuk periode & dokumen ini.</div>';
        $('#btn-modal-refresh').prop('disabled', true);
        $('#modalDetail').modal('show');
        return;
      }

      // Urutkan unfinished di atas
      data.sort((a, b) => {
        let aSelesai = (a.namaProses === 'Selesai Proses') ? 1 : 0;
        let bSelesai = (b.namaProses === 'Selesai Proses') ? 1 : 0;
        return aSelesai - bSelesai;
      });

      let html = `
        <table class="table table-bordered table-striped table-hover m-0" id="modal-table-data" style="font-size:11px;">
          <thead class="thead-light text-center">
            <tr>
              <th style="width:35px;">No</th>
              <th style="width:70px;">Status</th>
              <th style="width:80px;">Tgl Daftar</th>
              <th style="width:85px;">No Daftar</th>
              <th style="width:200px;">Nomor AJU</th>
              <th>Nama Perusahaan</th>
              <th style="width:60px;">Dok</th>
              <th style="width:50px;">Jalur</th>
              <th>Nama Proses</th>
              <th>Nama Respon</th>
              <th>No Respon</th>
              <th style="width:60px;">Asal</th>
              <th style="width:55px;">PDF</th>
            </tr>
          </thead>
          <tbody>
      `;

      data.forEach((row, idx) => {
        const j = (row.kodeJalur || '').toUpperCase();
        let jalurClass = 'badge-jalur-h';
        if (j === 'M') jalurClass = 'badge-jalur-m';
        else if (j === 'K') jalurClass = 'badge-jalur-k';

        const isUnfin = row.namaProses !== 'Selesai Proses';
        const pdfButton = row.pdf ? `
          <a href="/pdf.php?aju=${encodeURIComponent(row.nomorAju)}&pdf=${encodeURIComponent(row.pdf)}&cmp_id=${encodeURIComponent(activeCmpId)}&view=1" target="_blank" class="btn btn-sm btn-outline-danger" style="padding:1px 5px; font-size:10px;" title="Buka PDF">
            📄 PDF
          </a>` : '<span class="text-muted">-</span>';

        html += `
          <tr id="m-row-${idx}" data-npwp="${row.idPerusahaan || ''}" data-aju="${row.nomorAju}" data-proses="${row.namaProses}">
            <td class="text-center font-weight-bold">${idx + 1}</td>
            <td id="m-status-${idx}" class="text-center">
              ${isUnfin ? '<span class="badge badge-warning" style="font-size:10px;">Pending</span>' : '<span class="badge badge-success" style="font-size:10px;">Selesai</span>'}
            </td>
            <td class="text-center">${row.tanggalDaftar || '-'}</td>
            <td class="text-center font-weight-bold">${row.nomorDaftar || '-'}</td>
            <td class="text-monospace font-weight-bold" style="color:#0050b3;">${row.nomorAju}</td>
            <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(row.namaPerusahaan)}">${escapeHtml(row.namaPerusahaan)}</td>
            <td class="text-center font-weight-bold">BC ${row.kodeDokumen}</td>
            <td class="text-center"><span class="badge-jalur ${jalurClass}">${j || '-'}</span></td>
            <td>${escapeHtml(row.namaProses || '-')}</td>
            <td>${escapeHtml(row.namaRespon || '-')}</td>
            <td class="text-center">${escapeHtml(row.nomorRespon || '-')}</td>
            <td class="text-center"><small class="text-muted">${escapeHtml(row.asalData || '-')}</small></td>
            <td class="text-center">${pdfButton}</td>
          </tr>
        `;
      });

      html += '</tbody></table>';
      document.getElementById('modal-detail-body').innerHTML = html;
      $('#modalDetail').modal('show');
    })
    .catch(err => {
      console.error(err);
      document.getElementById('modal-detail-body').innerHTML = '<div class="alert alert-danger m-2">Gagal mengambil detail dari server: ' + err.message + '</div>';
    });
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function startModalBulkRefresh() {
  const targetIndexes = [];
  currentModalData.forEach((row, idx) => {
    if (row.namaProses !== 'Selesai Proses') {
      targetIndexes.push(idx);
    }
  });

  const totalTargets = targetIndexes.length;
  if (totalTargets === 0) {
    alert("Seluruh dokumen pada tabel ini sudah berstatus 'Selesai Proses'.");
    return;
  }

  if (!confirm(`Sistem mendeteksi ada ${totalTargets} dokumen Belum Selesai. Lanjutkan sinkronisasi ulang status ke portal CEISA?`)) {
    return;
  }

  $('#btn-modal-refresh').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Menyinkronkan...');
  $('#modal-progress-container').slideDown();

  for (let k = 0; k < totalTargets; k++) {
    const i = targetIndexes[k];
    const rowEl = $(`#m-row-${i}`);
    const statusEl = $(`#m-status-${i}`);
    const npwp = rowEl.data('npwp');
    const aju = rowEl.data('aju');

    rowEl.addClass('processing-row');
    statusEl.html('<span class="badge badge-warning"><i class="fas fa-sync-alt fa-spin"></i> Cek</span>');
    $('#modal-progress-text').text(`Sedang menyinkronkan status ${k + 1} dari ${totalTargets} (AJU: ${aju})`);

    try {
      const res = await fetch(`cekaju.php?cmp_id=${encodeURIComponent(activeCmpId)}&npwp=${encodeURIComponent(npwp)}&aju=${encodeURIComponent(aju)}`);
      if (res.ok) {
        rowEl.removeClass('processing-row').addClass('success-row');
        statusEl.html('<span class="badge badge-success"><i class="fas fa-check"></i> OK</span>');
      } else {
        rowEl.removeClass('processing-row').addClass('error-row');
        statusEl.html('<span class="badge badge-danger">Gagal</span>');
      }
    } catch (e) {
      rowEl.removeClass('processing-row').addClass('error-row');
      statusEl.html('<span class="badge badge-danger">Err</span>');
    }

    const pct = Math.round(((k + 1) / totalTargets) * 100);
    $('#modal-progress-bar').css('width', `${pct}%`).text(`${pct}%`);

    // Anti-WAF delay 600ms
    await new Promise(r => setTimeout(r, 600));
  }

  $('#modal-progress-text').html('<span class="text-success font-weight-bold"><i class="fas fa-check-double"></i> Selesai Sinkronisasi! Memuat ulang data...</span>');
  setTimeout(() => {
    showDetails(currentYm, currentKodeDokumen);
  }, 1000);
}

function exportToExcel() {
  const table = document.querySelector("#modal-detail-body table");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tr")).map((tr, index) =>
    Array.from(tr.querySelectorAll("th, td")).map((td, colIndex) => {
      let text = td.innerText.trim();
      if (index > 0 && (colIndex === 3 || colIndex === 4)) {
        return { t: "s", v: text };
      }
      return text;
    })
  );

  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet(rows);
  XLSX.utils.book_append_sheet(wb, ws, "Sheet1");

  const safeYm = currentYm.replace(/[^0-9\-]/g, '');
  const fileName = `Rekap_${currentKodeDokumen}_${safeYm}.xlsx`;
  XLSX.writeFile(wb, fileName);
}
</script>
</body>
</html>

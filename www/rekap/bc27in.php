<?php
/**
 * Fitur: Tarik Dokumen BC 2.7 IN & Pengolahan ke IT Inventory (bc_1ab2)
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
$aju_filter  = trim($_GET['aju'] ?? '');
$tarik_filter = $_GET['tarik'] ?? '';
$it_filter   = $_GET['it'] ?? '';

$cleanNamaPrs = $koneksi->real_escape_string($namaperusahaan ?? 'FRAMAS');
$cleanCmpId   = $koneksi->real_escape_string($cmp_id ?? '');
$whereCmp     = !empty($cleanCmpId) ? "AND (a.cmp_id = '$cleanCmpId' OR a.cmp_id = '' OR a.cmp_id IS NULL)" : "";

$sql = "
SELECT 
    DATE_FORMAT(a.tanggalDaftar, '%Y-%m-%d') as tanggalDaftar,  
    a.nomorDaftar, 
    a.nomorAju, 
    a.kodeKantor,
    a.namaPerusahaan, 
    a.kodeDokumen,
    a.kodeJalur, 
    a.namaProses, 
    a.namaRespon, 
    a.nomorRespon, 
    a.asalData,
    a.pdf,
    (SELECT count(1) FROM bc_ceisa_get_data x WHERE x.nomor_aju = a.nomorAju) AS sudah_tarik,
    (SELECT count(1) FROM bc_ceisa_get_barang b WHERE b.nomor_aju = a.nomorAju) AS item_count,
    (SELECT count(1) FROM bc_1ab2 it WHERE it.NOMOR_AJU = a.nomorAju AND it.AB = 'A') AS sudah_masuk_it
FROM bc_ceisa_data a
WHERE a.kodeDokumen = '27'
  AND (NOT a.namaPerusahaan LIKE '%$cleanNamaPrs%')
  AND DATE_FORMAT(a.tanggalDaftar, '%Y-%m') = '" . $koneksi->real_escape_string($tahun_bulan) . "'
  $whereCmp
";

if (!empty($aju_filter)) {
    $sql .= " AND a.nomorAju LIKE '%" . $koneksi->real_escape_string($aju_filter) . "%'";
}

if ($tarik_filter === '0') {
    $sql .= " AND (SELECT count(1) FROM bc_ceisa_get_data x WHERE x.nomor_aju = a.nomorAju) = 0";
} elseif ($tarik_filter === '1') {
    $sql .= " AND (SELECT count(1) FROM bc_ceisa_get_data x WHERE x.nomor_aju = a.nomorAju) > 0";
}

if ($it_filter === '0') {
    $sql .= " AND (SELECT count(1) FROM bc_1ab2 it WHERE it.NOMOR_AJU = a.nomorAju AND it.AB = 'A') = 0";
} elseif ($it_filter === '1') {
    $sql .= " AND (SELECT count(1) FROM bc_1ab2 it WHERE it.NOMOR_AJU = a.nomorAju AND it.AB = 'A') > 0";
}

$sql .= " ORDER BY a.tanggalDaftar DESC, a.nomorAju DESC";

$result = $koneksi->query($sql);

$belumTarik = 0;
$sudahTarik = 0;
$sudahIt    = 0;
$rows       = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ((int)$row['sudah_tarik'] === 0) {
            $belumTarik++;
        } else {
            $sudahTarik++;
        }
        if ((int)$row['sudah_masuk_it'] > 0) {
            $sudahIt++;
        }
        $rows[] = $row;
    }
}
$total = $belumTarik + $sudahTarik;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tarik BC 2.7 IN & Olah ke IT Inventory - CEISA 4.0</title>
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
      font-size: 11px;
      padding: 2px 10px;
      height: 25px;
      line-height: 18px;
      border-radius: 3px;
    }
    .kpi-chip {
      background: #fafafa;
      border: 1px solid #d9d9d9;
      border-radius: 4px;
      padding: 4px 10px;
      font-size: 11px;
    }
    .badge-jalur {
      padding: 2px 6px;
      font-weight: 600;
      border-radius: 2px;
      font-size: 10px;
    }
    .badge-jalur-h { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
    .badge-jalur-m { background: #fff1f0; color: #ff4d4f; border: 1px solid #ffa39e; }
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
      <li class="nav-item active">
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
        <input type="month" class="form-control form-control-sm" name="periode" value="<?= htmlspecialchars($tahun_bulan) ?>" style="font-size:11px; height:26px;">

        <input type="text" class="form-control form-control-sm ml-2" name="aju" placeholder="No AJU" value="<?= htmlspecialchars($aju_filter) ?>" style="font-size:11px; height:26px; width:150px;">

        <select name="tarik" class="form-control form-control-sm ml-2" style="font-size:11px; height:26px;">
          <option value="">Status Tarik CEISA</option>
          <option value="0" <?= $tarik_filter === '0' ? 'selected' : '' ?>>Belum Tarik</option>
          <option value="1" <?= $tarik_filter === '1' ? 'selected' : '' ?>>Sudah Tarik</option>
        </select>

        <select name="it" class="form-control form-control-sm ml-2" style="font-size:11px; height:26px;">
          <option value="">Status IT Inventory</option>
          <option value="0" <?= $it_filter === '0' ? 'selected' : '' ?>>Belum Masuk IT</option>
          <option value="1" <?= $it_filter === '1' ? 'selected' : '' ?>>Sudah Masuk IT</option>
        </select>

        <button type="submit" class="btn btn-primary btn-custom"><i class="fas fa-search mr-1"></i> Tampilkan</button>
      </div>

      <div class="d-flex align-items-center" style="gap:6px;">
        <button type="button" id="btnTarikBatch" class="btn btn-danger btn-custom font-weight-bold" onclick="tarikSemua()" <?= $belumTarik === 0 ? 'disabled' : '' ?>>
          <i class="fas fa-bolt mr-1"></i> Tarik Semua Belum Tarik (<?= $belumTarik ?>)
        </button>
        <button type="button" class="btn btn-success btn-custom font-weight-bold" onclick="exportExcel()">
          <i class="fas fa-file-excel mr-1"></i> Export XLS
        </button>
      </div>
    </form>

    <!-- Metrics -->
    <div class="d-flex align-items-center flex-wrap mt-3 pt-3 border-top" style="gap:10px;">
      <div class="kpi-chip">
        Total BC 2.7 IN: <strong class="text-dark"><?= $total ?></strong> Dokumen
      </div>
      <div class="kpi-chip" style="background:#fff1f0; border-color:#ffa39e;">
        Belum Ditarik: <strong class="text-danger"><?= $belumTarik ?></strong>
      </div>
      <div class="kpi-chip" style="background:#e6f7ff; border-color:#91d5ff;">
        Sudah Ditarik: <strong class="text-primary"><?= $sudahTarik ?></strong>
      </div>
      <div class="kpi-chip" style="background:#f6ffed; border-color:#b7eb8f;">
        Sudah Masuk IT Inventory: <strong class="text-success"><?= $sudahIt ?></strong>
      </div>
    </div>

    <!-- Progress bar batch -->
    <div id="batchProgress" class="mt-3 pt-3 border-top" style="display:none;">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="font-weight-bold" id="batchTitle">Menjalankan Tarik Data Batch...</span>
        <span class="font-weight-bold" id="batchPercentage">0%</span>
      </div>
      <div class="progress" style="height: 18px;">
        <div id="batchProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success font-weight-bold" style="width: 0%; font-size:10px;">0%</div>
      </div>
      <small class="text-muted mt-1 d-block" id="batchStatus">Memproses...</small>
    </div>
  </div>

  <div class="card-ui p-0" style="overflow:hidden;">
    <div class="table-responsive">
      <table class="table-custom" id="tableBc27">
        <thead>
          <tr>
            <th style="width:35px;">No</th>
            <th style="width:75px;">Tgl Daftar</th>
            <th style="width:85px;">No Daftar</th>
            <th style="width:190px;">Nomor AJU</th>
            <th style="width:65px;">Kantor</th>
            <th>Perusahaan Pengirim</th>
            <th style="width:45px;">Jalur</th>
            <th>Nama Respon</th>
            <th style="width:95px;">Tarik CEISA</th>
            <th style="width:115px;">IT Inventory</th>
            <th style="width:140px;">Aksi</th>
            <th style="width:50px;">PDF</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) > 0): ?>
            <?php foreach ($rows as $idx => $r): 
              $isTarik = (int)$r['sudah_tarik'] > 0;
              $isIt = (int)$r['sudah_masuk_it'] > 0;
              $j = strtoupper($r['kodeJalur'] ?? '');
              $jClass = ($j === 'M') ? 'badge-jalur-m' : 'badge-jalur-h';
            ?>
              <tr id="row-<?= $idx ?>" data-aju="<?= htmlspecialchars($r['nomorAju']) ?>" data-kantor="<?= htmlspecialchars($r['kodeKantor']) ?>">
                <td class="text-center font-weight-bold"><?= $idx + 1 ?></td>
                <td class="text-center"><?= htmlspecialchars($r['tanggalDaftar']) ?></td>
                <td class="text-center font-weight-bold"><?= htmlspecialchars($r['nomorDaftar'] ?: '-') ?></td>
                <td class="text-monospace font-weight-bold" style="color:#0050b3;"><?= htmlspecialchars($r['nomorAju']) ?></td>
                <td class="text-center"><?= htmlspecialchars($r['kodeKantor']) ?></td>
                <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($r['namaPerusahaan']) ?>"><?= htmlspecialchars($r['namaPerusahaan']) ?></td>
                <td class="text-center"><span class="badge-jalur <?= $jClass ?>"><?= $j ?: '-' ?></span></td>
                <td><?= htmlspecialchars($r['namaRespon'] ?: '-') ?></td>
                <td class="text-center" id="status-tarik-<?= $idx ?>">
                  <?php if ($isTarik): ?>
                    <span class="badge badge-success" style="font-size:10px;">✓ Sudah (<?= $r['item_count'] ?> item)</span>
                  <?php else: ?>
                    <span class="badge badge-danger" style="font-size:10px;">✗ Belum Tarik</span>
                  <?php endif; ?>
                </td>
                <td class="text-center" id="status-it-<?= $idx ?>">
                  <?php if ($isIt): ?>
                    <span class="badge badge-success" style="font-size:10px;">✓ Masuk IT</span>
                  <?php else: ?>
                    <span class="badge badge-secondary" style="font-size:10px;">- Belum Masuk</span>
                  <?php endif; ?>
                </td>
                <td class="text-center" id="action-cell-<?= $idx ?>">
                  <div style="display:inline-flex; gap:4px;">
                    <?php if (!$isTarik): ?>
                      <button type="button" class="btn btn-outline-danger btn-custom" onclick="tarikSingle('<?= $r['nomorAju'] ?>', '<?= $r['kodeKantor'] ?>', <?= $idx ?>)">
                        🚀 Tarik
                      </button>
                    <?php endif; ?>

                    <?php if ($isTarik && !$isIt): ?>
                      <button type="button" class="btn btn-primary btn-custom" style="background:#52c41a; border-color:#52c41a;" onclick="postToIt('<?= $r['nomorAju'] ?>', <?= $idx ?>)">
                        📥 Olah ke IT
                      </button>
                    <?php elseif ($isIt): ?>
                      <button type="button" class="btn btn-outline-secondary btn-custom" title="Sudah Masuk IT Inventory" onclick="postToIt('<?= $r['nomorAju'] ?>', <?= $idx ?>, true)">
                        🔄 Re-Post
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="text-center">
                  <?php if (!empty($r['pdf'])): ?>
                    <a href="/pdf.php?aju=<?= urlencode($r['nomorAju']) ?>&pdf=<?= urlencode($r['pdf']) ?>&cmp_id=<?= urlencode($cmp_id) ?>&view=1" target="_blank" class="btn btn-sm btn-outline-danger" style="padding:1px 5px; font-size:10px;">PDF</a>
                  <?php else: ?>-<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="12" class="text-center py-4 text-muted">
                Tidak ada dokumen BC 2.7 IN ditemukan pada periode <?= htmlspecialchars($tahun_bulan) ?>.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Validasi / Error Log -->
<div id="modalNotice" class="modal fade" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title font-weight-bold" id="noticeTitle">Pemberitahuan Sistem</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body" id="noticeBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-custom" data-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.4/xlsx.full.min.js"></script>
<script>
const activeCmpId = '<?= htmlspecialchars($cmp_id) ?>';

async function tarikSingle(aju, kantor, idx) {
  const btnCell = $(`#action-cell-${idx}`);
  btnCell.html('<span class="spinner-border spinner-border-sm text-primary"></span>');

  try {
    const res = await fetch(`get27.php?cmp_id=${encodeURIComponent(activeCmpId)}&aju=${encodeURIComponent(aju)}&kantor=${encodeURIComponent(kantor)}`).then(r => r.json());
    if (res.status) {
      $(`#status-tarik-${idx}`).html(`<span class="badge badge-success">✓ Sudah (${res.barang_count || 1} item)</span>`);
      btnCell.html(`
        <button type="button" class="btn btn-primary btn-custom" style="background:#52c41a; border-color:#52c41a;" onclick="postToIt('${aju}', ${idx})">
          📥 Olah ke IT
        </button>
      `);
    } else {
      alert("Gagal tarik CEISA: " + res.message);
      btnCell.html(`
        <button type="button" class="btn btn-outline-danger btn-custom" onclick="tarikSingle('${aju}', '${kantor}', ${idx})">
          Coba Lagi
        </button>
      `);
    }
  } catch (err) {
    alert("Error koneksi: " + err.message);
  }
}

async function postToIt(aju, idx, isRepost = false) {
  if (isRepost && !confirm(`Dokumen AJU ${aju} sudah pernah masuk ke IT Inventory. Ingin memperbarui (re-post) data barangnya?`)) {
    return;
  }

  const btnCell = $(`#action-cell-${idx}`);
  const oldHtml = btnCell.html();
  btnCell.html('<span class="spinner-border spinner-border-sm text-success"></span> Validasi...');

  const fd = new FormData();
  fd.append('cmp_id', activeCmpId);
  fd.append('nomor_aju', aju);

  try {
    const res = await fetch('post_bc27in.php', { method: 'POST', body: fd }).then(r => r.json());
    if (res.status) {
      $(`#status-it-${idx}`).html('<span class="badge badge-success">✓ Masuk IT</span>');
      btnCell.html(`
        <button type="button" class="btn btn-outline-secondary btn-custom" title="Sudah Masuk IT Inventory" onclick="postToIt('${aju}', ${idx}, true)">
          🔄 Re-Post
        </button>
      `);
      alert("✅ " + res.message);
    } else {
      btnCell.html(oldHtml);
      // Tampilkan modal alasan jika ada invalid items
      if (res.invalid_items && res.invalid_items.length > 0) {
        let errHtml = `
          <div class="alert alert-danger">
            <strong>❌ Gagal Olah Masuk IT Inventory!</strong><br>
            ${res.message}<br><br>
            <em>Catatan: Sesuai ketentuan IT Inventory, seluruh kode barang harus telah terdaftar pada Master Data Barang dan kolom Klasifikasi sudah diset.</em>
          </div>
          <table class="table table-bordered table-sm" style="font-size:11px;">
            <thead class="thead-light">
              <tr>
                <th style="width:40px;">Seri</th>
                <th>Kode Barang Dokumen</th>
                <th>Uraian Barang</th>
                <th>Alasan / Masalah</th>
              </tr>
            </thead>
            <tbody>
        `;
        res.invalid_items.forEach(it => {
          errHtml += `
            <tr>
              <td class="text-center font-weight-bold">${it.seri}</td>
              <td class="font-weight-bold text-danger text-monospace">${escapeHtml(it.kode)}</td>
              <td>${escapeHtml(it.uraian)}</td>
              <td class="text-danger font-weight-bold">${escapeHtml(it.alasan)}</td>
            </tr>
          `;
        });
        errHtml += '</tbody></table>';

        $('#noticeTitle').html('⚠️ Validasi Master Barang Belum Lengkap');
        $('#noticeBody').html(errHtml);
        $('#modalNotice').modal('show');
      } else {
        alert("Gagal: " + res.message);
      }
    }
  } catch (err) {
    btnCell.html(oldHtml);
    alert("Koneksi gagal: " + err.message);
  }
}

async function tarikSemua() {
  const rowsToTarik = [];
  $('tr[id^="row-"]').each(function() {
    const idx = $(this).attr('id').replace('row-', '');
    if ($(`#status-tarik-${idx}`).text().includes('Belum')) {
      rowsToTarik.push({
        idx: idx,
        aju: $(this).data('aju'),
        kantor: $(this).data('kantor')
      });
    }
  });

  if (rowsToTarik.length === 0) {
    alert("Semua dokumen pada tabel ini sudah ditarik dari CEISA.");
    return;
  }

  if (!confirm(`Sistem akan menarik data untuk ${rowsToTarik.length} dokumen BC 2.7 IN. Lanjutkan?`)) return;

  $('#btnTarikBatch').prop('disabled', true);
  $('#batchProgress').slideDown();

  let success = 0;
  for (let k = 0; k < rowsToTarik.length; k++) {
    const item = rowsToTarik[k];
    $('#batchStatus').text(`Menarik ${k + 1} dari ${rowsToTarik.length} (AJU: ${item.aju})...`);

    try {
      const res = await fetch(`get27.php?cmp_id=${encodeURIComponent(activeCmpId)}&aju=${encodeURIComponent(item.aju)}&kantor=${encodeURIComponent(item.kantor)}`).then(r => r.json());
      if (res.status) {
        $(`#status-tarik-${item.idx}`).html(`<span class="badge badge-success">✓ Sudah (${res.barang_count || 1} item)</span>`);
        $(`#action-cell-${item.idx}`).html(`
          <button type="button" class="btn btn-primary btn-custom" style="background:#52c41a; border-color:#52c41a;" onclick="postToIt('${item.aju}', ${item.idx})">
            📥 Olah ke IT
          </button>
        `);
        success++;
      }
    } catch (e) {
      console.error(e);
    }

    const pct = Math.round(((k + 1) / rowsToTarik.length) * 100);
    $('#batchProgressBar').css('width', `${pct}%`).text(`${pct}%`);
    $('#batchPercentage').text(`${pct}%`);

    // Jeda anti-WAF
    await new Promise(r => setTimeout(r, 600));
  }

  $('#batchStatus').html(`<strong class="text-success">✓ Selesai! Berhasil menarik ${success} dari ${rowsToTarik.length} dokumen.</strong>`);
  $('#btnTarikBatch').prop('disabled', false);
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function exportExcel() {
  const table = document.querySelector("#tableBc27");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tr")).map((tr, index) =>
    Array.from(tr.querySelectorAll("th, td")).map((td, colIndex) => {
      let text = td.innerText.trim();
      if (index > 0 && (colIndex === 2 || colIndex === 3)) {
        return { t: "s", v: text };
      }
      return text;
    })
  );

  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet(rows);
  XLSX.utils.book_append_sheet(wb, ws, "Sheet1");
  const fileName = `BC27IN_<?= htmlspecialchars($tahun_bulan) ?>.xlsx`;
  XLSX.writeFile(wb, fileName);
}
</script>
</body>
</html>

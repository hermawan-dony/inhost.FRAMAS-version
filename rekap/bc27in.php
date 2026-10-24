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

// Referensi Klasifikasi dari bc_tipe untuk modal pengajuan kode baru
$klasList = [];
$qTipe = $koneksi->query("SELECT * FROM bc_tipe WHERE NOT grp IN ('FA', 'Consumables') ORDER BY idx");
if ($qTipe) {
    while ($t = $qTipe->fetch_assoc()) {
        if ($t['tipe'] !== '-- SEMUA KLAS --') {
            $klasList[] = $t['tipe'];
        }
    }
}

// Cek Pengajuan Approval Pending
$cntPendingApproval = 0;
$cleanCmpId   = $koneksi->real_escape_string($cmp_id ?? '');
try {
    $qAppr = $koneksi->query("SELECT COUNT(1) FROM bc_material_approval WHERE status = 'PENDING' AND (cmp_id = '$cleanCmpId' OR cmp_id = '')");
    if ($qAppr && $rA = $qAppr->fetch_row()) {
        $cntPendingApproval = (int)($rA[0] ?? 0);
    }
} catch (\Throwable $e) { $cntPendingApproval = 0; }

$cleanNamaPrs = $koneksi->real_escape_string($namaperusahaan ?? 'FRAMAS');
$cleanCmpId   = $koneksi->real_escape_string($cmp_id ?? '');
$whereCmp     = ""; // bc_ceisa_data bersifat shared lintas plant

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

<!-- Modal Validasi / Resolver Kode Barang -->
<div id="modalResolver" class="modal fade" tabindex="-1" role="dialog" data-backdrop="static">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header bg-light py-2">
        <h6 class="modal-title font-weight-bold text-dark mb-0">
          <i class="fas fa-boxes text-warning mr-1"></i> Penyelarasan Kode Barang Dokumen
          <span class="badge badge-primary ml-2 font-weight-normal text-monospace" id="resAjuBadge" style="font-size:12px;"></span>
        </h6>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning d-flex align-items-start mb-3 py-2 px-3" style="font-size:11px; gap:10px;">
          <i class="fas fa-exclamation-triangle fa-2x mt-1 text-warning"></i>
          <div>
            <strong>Validasi Master Barang Belum Sesuai!</strong><br>
            Dokumen ini memuat <strong id="resCountText" class="text-danger">0</strong> baris barang dari supplier yang kodenya belum terdaftar atau belum sesuai di Master Data Barang (<code>bc_material</code>).<br>
            <strong>Solusi yang tersedia:</strong>
            <ul class="mb-0 pl-3 mt-1">
              <li><strong>Hubungkan (Mapping) ke Master Existing:</strong> Jika barang ini sebenarnya ada di Master internal (hanya beda kode saat dikirim supplier). Kode supplier akan disimpan ke kolom <code>KODE_BARANG2</code> agar penarikan seterusnya otomatis berubah ke kode internal.</li>
              <li><strong>Ajukan Kode Barang Baru:</strong> Jika barang ini benar-benar item baru. Pengajuan akan dikirim ke antrian <strong>Approval Admin Inventory</strong> untuk diverifikasi sebelum barang dapat diolah.</li>
            </ul>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-sm" style="font-size:11px;">
            <thead class="thead-light">
              <tr>
                <th style="width:40px;" class="text-center">Seri</th>
                <th style="width:160px;">Kode Dokumen (Supplier)</th>
                <th>Uraian Barang</th>
                <th style="width:65px;" class="text-center">Satuan</th>
                <th style="width:85px;" class="text-center">HS Code</th>
                <th style="width:230px;">Status / Alasan</th>
                <th style="width:230px;" class="text-center">Tindakan Penyelarasan</th>
              </tr>
            </thead>
            <tbody id="resTableBody">
              <!-- Item rows populated dynamically -->
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer justify-content-between py-2">
        <div>
          <span class="text-muted" style="font-size:11px;">
            <i class="fas fa-info-circle mr-1"></i> Setelah seluruh item terpetakan atau disetujui, klik <b>Olah ke IT Inventory Sekarang</b>.
          </span>
        </div>
        <div class="d-flex" style="gap:6px;">
          <button type="button" class="btn btn-secondary btn-custom" data-dismiss="modal">Tutup</button>
          <button type="button" id="btnRetryPost" class="btn btn-success btn-custom font-weight-bold" onclick="retryPostCurrentDoc()">
            <i class="fas fa-check-circle mr-1"></i> Olah ke IT Inventory Sekarang
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Hubungkan Kode Supplier ke Master Barang Existing (Mapping) -->
<div id="modalMapSupplier" class="modal fade" tabindex="-1" role="dialog" data-backdrop="static">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white py-2">
        <h6 class="modal-title font-weight-bold mb-0">
          <i class="fas fa-link mr-1"></i> Pemetaan Kode Supplier ke Master Barang Existing
        </h6>
        <button type="button" class="close text-white" onclick="$('#modalMapSupplier').modal('hide')"><span>&times;</span></button>
      </div>
      <div class="modal-body" style="font-size:11px;">
        <input type="hidden" id="mapTargetSeri">
        <div class="row">
          <div class="col-md-6 form-group mb-2">
            <label class="font-weight-bold mb-1">Kode Barang Dokumen (Supplier):</label>
            <input type="text" id="mapSuppCode" class="form-control form-control-sm font-weight-bold text-danger text-monospace" readonly>
          </div>
          <div class="col-md-6 form-group mb-2">
            <label class="font-weight-bold mb-1">Uraian Dokumen (Supplier):</label>
            <input type="text" id="mapSuppUraian" class="form-control form-control-sm" readonly>
          </div>
        </div>

        <div class="form-group mb-2 pt-2 border-top">
          <label class="font-weight-bold mb-1 text-primary">
            <i class="fas fa-search mr-1"></i> Cari Master Barang Internal (bc_material):
          </label>
          <input type="text" id="mapSearchInput" class="form-control form-control-sm" placeholder="Ketik Kode Barang, Part No, atau Uraian Barang internal..." oninput="debounceSearchMaster(this.value)">
          <small class="text-muted">Pilih barang internal yang bersesuaian dari hasil pencarian di bawah:</small>
        </div>

        <!-- Hasil Pencarian List -->
        <div id="mapSearchResultsContainer" style="max-height: 180px; overflow-y: auto; border: 1px solid #ced4da; border-radius: 3px; background:#fafafa; margin-bottom:10px;">
          <div class="p-3 text-center text-muted" id="mapSearchEmpty">Ketik kata kunci untuk mencari master data barang...</div>
          <div id="mapSearchResultsList" class="list-group list-group-flush" style="display:none;"></div>
        </div>

        <!-- Box Konfirmasi Barang Terpilih -->
        <div id="mapSelectedBox" class="p-2 border rounded mb-2" style="display:none; background:#f6ffed; border-color:#b7eb8f !important;">
          <div class="font-weight-bold text-success mb-1"><i class="fas fa-check-circle mr-1"></i> Master Barang Internal Terpilih:</div>
          <div class="row">
            <div class="col-md-4">
              <strong>Kode Internal:</strong> <span id="mapSelectedKode" class="font-weight-bold text-monospace text-primary"></span>
            </div>
            <div class="col-md-4">
              <strong>Klasifikasi:</strong> <span id="mapSelectedKlas" class="badge badge-info"></span>
            </div>
            <div class="col-md-4">
              <strong>Satuan:</strong> <span id="mapSelectedSatuan" class="font-weight-bold"></span>
            </div>
            <div class="col-12 mt-1">
              <strong>Uraian:</strong> <span id="mapSelectedUraian"></span>
            </div>
          </div>
        </div>

        <div class="custom-control custom-checkbox mt-2">
          <input type="checkbox" class="custom-control-input" id="mapSaveK2" checked>
          <label class="custom-control-label font-weight-bold text-dark" for="mapSaveK2">
            Simpan kode supplier ini ke kolom Part No / Kode 2 (<code>KODE_BARANG2</code>) master barang.
          </label>
          <div class="text-muted ml-4" style="font-size:10px;">
            Centang ini agar seluruh dokumen pabean berikutnya dari supplier dengan kode ini otomatis terpetakan tanpa perlu konfirmasi ulang.
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-custom" onclick="$('#modalMapSupplier').modal('hide')">Batal</button>
        <button type="button" id="btnSubmitMapping" class="btn btn-primary btn-custom font-weight-bold" onclick="submitMapping()" disabled>
          <i class="fas fa-save mr-1"></i> Simpan Pemetaan & Terapkan
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Pengajuan Kode Barang Baru ke Admin Inventory -->
<div id="modalRequestCode" class="modal fade" tabindex="-1" role="dialog" data-backdrop="static">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark py-2">
        <h6 class="modal-title font-weight-bold mb-0">
          <i class="fas fa-plus-circle mr-1"></i> Pengajuan Kode Barang Baru ke Admin Inventory
        </h6>
        <button type="button" class="close" onclick="$('#modalRequestCode').modal('hide')"><span>&times;</span></button>
      </div>
      <div class="modal-body" style="font-size:11px;">
        <div class="alert alert-info py-2 px-3 mb-2" style="font-size:10.5px;">
          <i class="fas fa-info-circle mr-1"></i> Sesuai SOP, penambahan kode baru pada Master Data Barang wajib melalui validasi & persetujuan (Approval) Admin Inventory.
        </div>

        <input type="hidden" id="reqSeri">
        <div class="form-group mb-2">
          <label class="font-weight-bold mb-1">Nomor AJU Dokumen:</label>
          <input type="text" id="reqAju" class="form-control form-control-sm text-monospace" readonly>
        </div>
        <div class="form-group mb-2">
          <label class="font-weight-bold mb-1">Kode Barang Supplier (Dokumen):</label>
          <input type="text" id="reqSuppCode" class="form-control form-control-sm font-weight-bold text-danger text-monospace" readonly>
        </div>
        <div class="form-group mb-2">
          <label class="font-weight-bold mb-1 text-primary">Usulan Kode Barang Internal:*</label>
          <input type="text" id="reqIntCode" class="form-control form-control-sm font-weight-bold" placeholder="Contoh: RM-FABRIC-001 / KODE PERUSAHAAN">
          <small class="text-muted">Dapat diisi kode yang disarankan perusahaan, atau kode supplier jika disepakati sama.</small>
        </div>
        <div class="form-group mb-2">
          <label class="font-weight-bold mb-1">Uraian / Deskripsi Barang:*</label>
          <input type="text" id="reqUraian" class="form-control form-control-sm">
        </div>
        <div class="row">
          <div class="col-md-6 form-group mb-2">
            <label class="font-weight-bold mb-1">Klasifikasi Kategori:*</label>
            <select id="reqKlas" class="form-control form-control-sm">
              <!-- populated from window.klasList -->
            </select>
          </div>
          <div class="col-md-6 form-group mb-2">
            <label class="font-weight-bold mb-1">Satuan Utama:*</label>
            <input type="text" id="reqSatuan" class="form-control form-control-sm" value="PCE">
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 form-group mb-2">
            <label class="font-weight-bold mb-1">Pos Tarif / HS Code:</label>
            <input type="text" id="reqHs" class="form-control form-control-sm" placeholder="8 digit">
          </div>
          <div class="col-md-6 form-group mb-2">
            <label class="font-weight-bold mb-1">Alasan Pengajuan:</label>
            <input type="text" id="reqAlasan" class="form-control form-control-sm" placeholder="Contoh: Barang baru dari supplier">
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-custom" onclick="$('#modalRequestCode').modal('hide')">Batal</button>
        <button type="button" id="btnSubmitRequestCode" class="btn btn-warning btn-custom font-weight-bold" onclick="submitRequestNewCode()">
          <i class="fas fa-paper-plane mr-1"></i> Kirim Pengajuan ke Admin
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.4/xlsx.full.min.js"></script>
<script>
const activeCmpId = '<?= htmlspecialchars($cmp_id) ?>';
window.klasList = <?= json_encode($klasList) ?>;

// State resolver aktif
let currentResolverAju = '';
let currentResolverIdx = null;
let currentInvalidItems = [];
let selectedMasterItem = null;
let searchDebounceTimer = null;

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
      $('#modalResolver').modal('hide');
      alert("✅ " + res.message);
    } else {
      btnCell.html(oldHtml);
      // Jika ada item invalid, buka modal penyelarasan interaktif
      if (res.invalid_items && res.invalid_items.length > 0) {
        openResolverModal(aju, idx, res.invalid_items);
      } else {
        alert("Gagal: " + res.message);
      }
    }
  } catch (err) {
    btnCell.html(oldHtml);
    alert("Koneksi gagal: " + err.message);
  }
}

function openResolverModal(aju, idx, invalidItems) {
  currentResolverAju = aju;
  currentResolverIdx = idx;
  currentInvalidItems = invalidItems;

  $('#resAjuBadge').text(aju);
  $('#resCountText').text(invalidItems.length);

  renderResolverRows();
  $('#modalResolver').modal('show');
}

function renderResolverRows() {
  let html = '';
  currentInvalidItems.forEach((it, i) => {
    let statusBadge = '';
    let actionBtn = '';

    if (it.has_pending) {
      statusBadge = `
        <span class="badge badge-warning" style="font-size:10.5px; padding:3px 6px;">
          <i class="fas fa-clock mr-1"></i> Menunggu Approval Admin
        </span>
        <div style="font-size:9.5px; color:#fa8c16; margin-top:2px;">
          Usulan: <strong>${escapeHtml(it.pending_kode)}</strong> (ID #${it.pending_id})
        </div>
      `;
      actionBtn = `
        <a href="../material.php?cmp_id=${encodeURIComponent(activeCmpId)}" target="_blank" class="btn btn-outline-warning btn-sm py-0 px-2 font-weight-bold" style="font-size:10px;">
          <i class="fas fa-external-link-alt mr-1"></i> Buka Modul Admin
        </a>
      `;
    } else if (it.is_resolved) {
      statusBadge = `
        <span class="badge badge-success" style="font-size:10.5px; padding:3px 6px;">
          <i class="fas fa-check-circle mr-1"></i> Berhasil Dimapping
        </span>
        <div style="font-size:9.5px; color:#52c41a; margin-top:2px;">
          Internal: <strong>${escapeHtml(it.resolved_internal_code)}</strong>
        </div>
      `;
      actionBtn = `
        <span class="text-success font-weight-bold"><i class="fas fa-check"></i> Siap Diolah</span>
      `;
    } else {
      statusBadge = `
        <span class="badge badge-danger" style="font-size:10px; padding:3px 6px;">
          ${escapeHtml(it.alasan)}
        </span>
      `;
      actionBtn = `
        <div class="btn-group btn-group-sm">
          <button type="button" class="btn btn-primary btn-sm py-0 px-2 font-weight-bold" style="font-size:10px;" onclick="openMappingModal(${i})">
            <i class="fas fa-link mr-1"></i> Hubungkan
          </button>
          <button type="button" class="btn btn-warning btn-sm py-0 px-2 font-weight-bold text-dark" style="font-size:10px;" onclick="openNewCodeModal(${i})">
            <i class="fas fa-plus mr-1"></i> Ajukan Kode
          </button>
        </div>
      `;
    }

    html += `
      <tr id="res-row-${i}">
        <td class="text-center font-weight-bold text-muted">${it.seri}</td>
        <td class="font-weight-bold text-danger text-monospace">${escapeHtml(it.kode)}</td>
        <td>${escapeHtml(it.uraian)}</td>
        <td class="text-center">${escapeHtml(it.satuan)}</td>
        <td class="text-center">${escapeHtml(it.hs)}</td>
        <td>${statusBadge}</td>
        <td class="text-center">${actionBtn}</td>
      </tr>
    `;
  });

  $('#resTableBody').html(html);
}

// -------------------------------------------------------------
// LOGIKA PEMETAAN (MAPPING) SUPPLIER -> MASTER EXISTING
// -------------------------------------------------------------
function openMappingModal(itemIndex) {
  const it = currentInvalidItems[itemIndex];
  if (!it) return;

  $('#mapTargetSeri').val(itemIndex);
  $('#mapSuppCode').val(it.kode);
  $('#mapSuppUraian').val(it.uraian);
  $('#mapSearchInput').val('');
  $('#mapSearchResultsList').empty().hide();
  $('#mapSearchEmpty').text('Ketik kata kunci untuk mencari master data barang...').show();
  $('#mapSelectedBox').hide();
  $('#btnSubmitMapping').prop('disabled', true);
  selectedMasterItem = null;

  $('#modalMapSupplier').modal('show');
  setTimeout(() => $('#mapSearchInput').focus(), 400);
}

function debounceSearchMaster(keyword) {
  clearTimeout(searchDebounceTimer);
  searchDebounceTimer = setTimeout(() => searchMasterMaterials(keyword), 300);
}

async function searchMasterMaterials(query) {
  query = query.trim();
  if (query.length < 1) {
    $('#mapSearchResultsList').empty().hide();
    $('#mapSearchEmpty').text('Ketik kata kunci untuk mencari master data barang...').show();
    return;
  }

  $('#mapSearchEmpty').html('<span class="spinner-border spinner-border-sm text-primary"></span> Mencari data master barang...').show();
  $('#mapSearchResultsList').hide();

  try {
    const res = await fetch(`post_bc27in.php?action=search_material&cmp_id=${encodeURIComponent(activeCmpId)}&q=${encodeURIComponent(query)}`).then(r => r.json());
    if (res.status && res.items && res.items.length > 0) {
      let listHtml = '';
      res.items.forEach(m => {
        const p2 = m.kode2 ? ` | Part No: <span class="text-info">${escapeHtml(m.kode2)}</span>` : '';
        listHtml += `
          <button type="button" class="list-group-item list-group-item-action py-1 px-2" onclick="selectMasterForMapping('${escapeHtml(m.kode)}', '${escapeHtml(m.uraian)}', '${escapeHtml(m.klas)}', '${escapeHtml(m.satuan)}', '${escapeHtml(m.pos_tarif)}')">
            <div class="d-flex justify-content-between align-items-center">
              <strong class="text-monospace text-primary">${escapeHtml(m.kode)}</strong>
              <span class="badge badge-secondary">${escapeHtml(m.klas)}</span>
            </div>
            <div class="text-truncate" style="font-size:10.5px;">${escapeHtml(m.uraian)}</div>
            <div style="font-size:9.5px; color:#8c8c8c;">Satuan: ${escapeHtml(m.satuan)}${p2}</div>
          </button>
        `;
      });
      $('#mapSearchResultsList').html(listHtml).show();
      $('#mapSearchEmpty').hide();
    } else {
      $('#mapSearchResultsList').hide();
      $('#mapSearchEmpty').html(`<span class="text-danger">Tidak ditemukan master barang dengan kata kunci '${escapeHtml(query)}'.</span>`).show();
    }
  } catch (err) {
    $('#mapSearchEmpty').text("Gagal mencari data: " + err.message).show();
  }
}

function selectMasterForMapping(kode, uraian, klas, satuan, pos_tarif) {
  selectedMasterItem = { kode, uraian, klas, satuan, pos_tarif };

  $('#mapSelectedKode').text(kode);
  $('#mapSelectedUraian').text(uraian);
  $('#mapSelectedKlas').text(klas);
  $('#mapSelectedSatuan').text(satuan);
  $('#mapSelectedBox').slideDown();

  $('#btnSubmitMapping').prop('disabled', false);
}

async function submitMapping() {
  if (!selectedMasterItem) return;

  const idx = parseInt($('#mapTargetSeri').val());
  const suppCode = $('#mapSuppCode').val();
  const saveMapping = $('#mapSaveK2').is(':checked') ? 1 : 0;

  const btn = $('#btnSubmitMapping');
  const oldText = btn.html();
  btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Menyimpan...');

  const fd = new FormData();
  fd.append('action', 'map_supplier');
  fd.append('cmp_id', activeCmpId);
  fd.append('nomor_aju', currentResolverAju);
  fd.append('kode_supplier', suppCode);
  fd.append('kode_internal', selectedMasterItem.kode);
  fd.append('save_mapping', saveMapping);

  try {
    const res = await fetch('post_bc27in.php', { method: 'POST', body: fd }).then(r => r.json());
    if (res.status) {
      $('#modalMapSupplier').modal('hide');
      
      // Update item state pada list invalid
      if (currentInvalidItems[idx]) {
        currentInvalidItems[idx].is_resolved = true;
        currentInvalidItems[idx].resolved_internal_code = selectedMasterItem.kode;
      }
      renderResolverRows();

      alert("✅ " + res.message);
    } else {
      alert("Gagal: " + res.message);
    }
  } catch (e) {
    alert("Koneksi gagal: " + e.message);
  } finally {
    btn.prop('disabled', false).html(oldText);
  }
}

// -------------------------------------------------------------
// LOGIKA PENGAJUAN KODE BARU KE ADMIN INVENTORY
// -------------------------------------------------------------
function openNewCodeModal(itemIndex) {
  const it = currentInvalidItems[itemIndex];
  if (!it) return;

  $('#reqSeri').val(itemIndex);
  $('#reqAju').val(currentResolverAju);
  $('#reqSuppCode').val(it.kode);
  $('#reqIntCode').val(it.kode !== '(KOSONG)' ? it.kode : '');
  $('#reqUraian').val(it.uraian);
  $('#reqSatuan').val(it.satuan || 'PCE');
  $('#reqHs').val(it.hs || '');
  $('#reqAlasan').val('Barang baru dari supplier pada dokumen masuk AJU ' + currentResolverAju);

  // Populate Klasifikasi dropdown
  let optHtml = '';
  (window.klasList || []).forEach(k => {
    optHtml += `<option value="${escapeHtml(k)}">${escapeHtml(k)}</option>`;
  });
  $('#reqKlas').html(optHtml);

  $('#modalRequestCode').modal('show');
  setTimeout(() => $('#reqIntCode').focus(), 400);
}

async function submitRequestNewCode() {
  const idx      = parseInt($('#reqSeri').val());
  const suppCode = $('#reqSuppCode').val();
  const intCode  = $('#reqIntCode').val().trim();
  const uraian   = $('#reqUraian').val().trim();
  const klas     = $('#reqKlas').val();
  const satuan   = $('#reqSatuan').val().trim();
  const hs       = $('#reqHs').val().trim();
  const alasan   = $('#reqAlasan').val().trim();

  if (!intCode) {
    alert("Usulan Kode Barang Internal wajib diisi!");
    $('#reqIntCode').focus();
    return;
  }
  if (!uraian) {
    alert("Uraian Barang wajib diisi!");
    $('#reqUraian').focus();
    return;
  }

  const btn = $('#btnSubmitRequestCode');
  const oldText = btn.html();
  btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Mengirim...');

  const fd = new FormData();
  fd.append('action', 'request_new_code');
  fd.append('cmp_id', activeCmpId);
  fd.append('nomor_aju', currentResolverAju);
  fd.append('kode_supplier', suppCode);
  fd.append('kode_internal', intCode);
  fd.append('uraian', uraian);
  fd.append('klas', klas);
  fd.append('satuan', satuan);
  fd.append('pos_tarif', hs);
  fd.append('alasan', alasan);

  try {
    const res = await fetch('post_bc27in.php', { method: 'POST', body: fd }).then(r => r.json());
    if (res.status) {
      $('#modalRequestCode').modal('hide');

      if (currentInvalidItems[idx]) {
        currentInvalidItems[idx].has_pending = true;
        currentInvalidItems[idx].pending_kode = intCode;
        currentInvalidItems[idx].pending_id = res.approval_id || 0;
      }
      renderResolverRows();

      alert("✅ " + res.message);
    } else {
      alert("Gagal: " + res.message);
    }
  } catch (e) {
    alert("Koneksi gagal: " + e.message);
  } finally {
    btn.prop('disabled', false).html(oldText);
  }
}

function retryPostCurrentDoc() {
  if (!currentResolverAju || currentResolverIdx === null) return;
  postToIt(currentResolverAju, currentResolverIdx);
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


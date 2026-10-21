<?php
/**
 * Fitur: Sync ALL DATA dari Portal CEISA 4.0
 * Dilengkapi Anti-WAF Rate Limiting & Real-time Progress Bar
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

// Format variasi NPWP standar Bea Cukai
$cleanNpwp = preg_replace('/[^0-9]/', '', $npwp);
$npwp_variants = [];
if (!empty($cleanNpwp)) {
    if (strlen($cleanNpwp) === 15) {
        $npwp_variants[] = '0' . $cleanNpwp . '000000';
        $npwp_variants[] = '0' . $cleanNpwp;
        $npwp_variants[] = $cleanNpwp . '000000';
    } elseif (strlen($cleanNpwp) === 16) {
        $npwp_variants[] = $cleanNpwp . '000000';
        $npwp_variants[] = $cleanNpwp;
    } else {
        $npwp_variants[] = $cleanNpwp;
    }
}
$npwp_variants = array_unique($npwp_variants);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sync ALL DATA - CEISA 4.0</title>
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
      padding: 16px;
      margin-bottom: 14px;
    }
    .card-title-custom {
      font-size: 13px;
      font-weight: 600;
      border-bottom: 1px solid #f0f0f0;
      padding-bottom: 8px;
      margin-bottom: 12px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .waf-alert {
      background: #e6f7ff;
      border: 1px solid #91d5ff;
      border-radius: 4px;
      padding: 8px 12px;
      color: #0050b3;
      margin-bottom: 12px;
      font-size: 11px;
    }
    .progress-box {
      margin-top: 14px;
    }
    #logBox {
      background: #141414;
      color: #52c41a;
      font-family: 'Consolas', 'Courier New', monospace;
      font-size: 10.5px;
      padding: 10px;
      border-radius: 4px;
      max-height: 280px;
      overflow-y: auto;
      white-space: pre-wrap;
    }
    .btn-custom {
      font-size: 11.5px;
      padding: 4px 14px;
      border-radius: 3px;
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
      <li class="nav-item active">
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
    <div>
      <a href="../daftar_dokumen.php?cmp_id=<?= urlencode($cmp_id) ?>" class="btn btn-outline-primary btn-custom">
        <i class="fas fa-arrow-left mr-1"></i> Dokumen CEISA
      </a>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="waf-alert">
    <i class="fas fa-info-circle mr-1"></i>
    <strong>Sinkronisasi Bertahap Otomatis:</strong> 
    Sistem menjalankan penarikan data secara teratur dengan jeda adaptif dan pembagian batch halaman agar proses berjalan lancar dan stabil.
  </div>

  <div class="card-ui">
    <div class="card-title-custom">
      <span>⚡ Pengaturan Sinkronisasi Data Portal CEISA 4.0</span>
      <span class="badge badge-primary font-weight-normal"><?= htmlspecialchars($namaperusahaan) ?></span>
    </div>

    <form id="formSync">
      <input type="hidden" id="cmp_id" value="<?= htmlspecialchars($cmp_id) ?>">

      <div class="form-row">
        <!-- NPWP Format -->
        <div class="form-group col-md-4">
          <label class="font-weight-bold">Format NPWP / Identitas Portal</label>
          <div>
            <?php foreach ($npwp_variants as $idx => $val): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="npwp_target" id="npwp_<?= $idx ?>" value="<?= htmlspecialchars($val) ?>" <?= $idx === 0 ? 'checked' : '' ?>>
                <label class="form-check-label text-monospace" for="npwp_<?= $idx ?>"><?= htmlspecialchars($val) ?></label>
              </div>
            <?php endforeach; ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="npwp_target" id="npwp_all" value="ALL">
              <label class="form-check-label font-weight-bold" for="npwp_all">Semua Varian (ALL)</label>
            </div>
          </div>
        </div>

        <!-- Mode Sinkronisasi -->
        <div class="form-group col-md-4">
          <label class="font-weight-bold">Mode Pengambilan Data</label>
          <div class="form-check mb-1">
            <input class="form-check-input" type="radio" name="fetch_mode" id="mode_all" value="all" checked>
            <label class="form-check-label font-weight-bold" for="mode_all">Ambil Semua Data (Fetch ALL)</label>
          </div>
          <div class="form-check d-flex align-items-center mb-2">
            <input class="form-check-input" type="radio" name="fetch_mode" id="mode_latest" value="latest">
            <label class="form-check-label mr-2" for="mode_latest">Ambil</label>
            <input type="number" id="custom_latest_limit" class="form-control form-control-sm mr-2" style="width: 75px; height:24px; font-size:11px;" value="1000" min="50" step="50">
            <label class="form-check-label" for="mode_latest">Data Terakhir</label>
          </div>
        </div>

        <!-- Ukuran Batch -->
        <div class="form-group col-md-4">
          <label class="font-weight-bold">Ukuran Batch per Halaman</label>
          <div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="batch_size" id="size_500" value="500" checked>
              <label class="form-check-label" for="size_500">500 Data (Direkomendasikan)</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="batch_size" id="size_100" value="100">
              <label class="form-check-label" for="size_100">100 Data</label>
            </div>
          </div>

          <label class="font-weight-bold mt-2">Filter Jenis Dokumen (Opsional)</label>
          <select id="filter_bc" class="form-control form-control-sm" style="font-size:11px; height:26px;">
            <option value="">Semua Dokumen Bea Cukai</option>
            <option value="611">PPKEK 6.1.1 (Pemasukan LDP)</option>
            <option value="613">PPKEK 6.1.3 (Pengeluaran KEK)</option>
            <option value="631">PPKEK 6.3.1 (Pengeluaran ke LDP)</option>
            <option value="25">BC 2.5 (Pengeluaran TPB)</option>
            <option value="23">BC 2.3 (Impor TPB)</option>
            <option value="40">BC 4.0 (Pemasukan TLDDP)</option>
            <option value="27">BC 2.7 (TPB ke TPB)</option>
            <option value="41">BC 4.1 (Pengeluaran TLDDP)</option>
            <option value="261">BC 2.6.1 (Subkon Luar)</option>
            <option value="262">BC 2.6.2 (Subkon Masuk)</option>
            <option value="20">BC 2.0 (Impor PIB)</option>
            <option value="30">BC 3.0 (Ekspor PEB)</option>
            <option value="16">BC 1.6 (Pemasukan PLB)</option>
            <option value="28">BC 2.8 (Impor PLB)</option>
            <option value="33">BC 3.3 (Ekspor PLB)</option>
            <option value="331">BC 3.3.1 (P3BET PLB)</option>
          </select>
        </div>
      </div>

      <div class="mt-2 pt-2 border-top d-flex align-items-center" style="gap:10px;">
        <button type="button" id="btnStart" class="btn btn-primary btn-custom" onclick="startSync()">
          <i class="fas fa-play mr-1"></i> Mulai Sinkronisasi
        </button>
        <button type="button" id="btnStop" class="btn btn-danger btn-custom" onclick="stopSync()" style="display:none;">
          <i class="fas fa-stop mr-1"></i> Hentikan Proses
        </button>
        <span id="syncStatusBadge" class="badge badge-secondary" style="font-size:11px; font-weight:normal; display:none;">Siap</span>
      </div>
    </form>
  </div>

  <!-- Progress Bar Container -->
  <div class="card-ui progress-box">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <span class="font-weight-bold" id="progressTitle">Status Proses Sinkronisasi</span>
      <span class="font-weight-bold" id="progressPercentage">0%</span>
    </div>
    <div class="progress" style="height: 20px;">
      <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success font-weight-bold" role="progressbar" style="width: 0%; font-size:10px;">0%</div>
    </div>
    <div class="mt-2 text-muted" id="statusText">Menunggu proses dimulai...</div>

    <label class="font-weight-bold mt-3 mb-1">Log Eksekusi:</label>
    <div id="logBox">Sistem siap. Klik "Mulai Sinkronisasi" untuk mengambil data.</div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script>
let isRunning = false;
let stopRequested = false;
const npwpVariantsList = <?= json_encode($npwp_variants) ?>;

function appendLog(msg) {
  const box = document.getElementById("logBox");
  const d = new Date();
  const time = d.toTimeString().split(' ')[0];
  box.textContent += `[${time}] ${msg}\n`;
  box.scrollTop = box.scrollHeight;
}

function stopSync() {
  stopRequested = true;
  $('#btnStop').prop('disabled', true).text('Menghentikan...');
  appendLog('⚠️ Permintaan penghentian diterima. Menyelesaikan batch aktif...');
}

async function startSync() {
  if (isRunning) return;
  isRunning = true;
  stopRequested = false;

  const cmpId = document.getElementById('cmp_id').value;
  const selectedNpwp = document.querySelector('input[name="npwp_target"]:checked').value;
  const fetchMode = document.querySelector('input[name="fetch_mode"]:checked').value;
  const batchSize = parseInt(document.querySelector('input[name="batch_size"]:checked').value) || 500;
  const bcFilter = document.getElementById('filter_bc').value;

  let limitTotal = fetchMode === 'latest' ? parseInt(document.getElementById('custom_latest_limit').value) || 1000 : 999999;

  $('#btnStart').hide();
  $('#btnStop').show().prop('disabled', false).html('<i class="fas fa-stop mr-1"></i> Hentikan Proses');
  $('#syncStatusBadge').show().removeClass('badge-secondary badge-success').addClass('badge-warning').text('Sedang Berjalan');

  document.getElementById('progressBar').style.width = '0%';
  document.getElementById('progressBar').textContent = '0%';
  document.getElementById('progressPercentage').textContent = '0%';
  document.getElementById('logBox').textContent = '';

  appendLog(`🚀 Memulai sinkronisasi Portal CEISA (Perusahaan: ${cmpId})...`);

  const targets = (selectedNpwp === 'ALL') ? npwpVariantsList : [selectedNpwp];
  let grandTotalInserted = 0;

  for (let npwp of targets) {
    if (stopRequested) break;
    appendLog(`🔍 Memeriksa total data untuk NPWP: ${npwp}...`);

    let initRes = await fetch(`browse_handler.php?cmp_id=${encodeURIComponent(cmpId)}&npwp=${encodeURIComponent(npwp)}&bc=${encodeURIComponent(bcFilter)}&page=0`)
      .then(r => r.json())
      .catch(e => ({ status: false, message: e.message }));

    if (!initRes.status || typeof initRes.total === 'undefined') {
      appendLog(`✗ NPWP ${npwp} Gagal: ${initRes.message || 'Tidak ada respon dari server'}`);
      continue;
    }

    const totalRecords = Math.min(initRes.total, limitTotal);
    appendLog(`✓ NPWP ${npwp}: Ditemukan ${initRes.total} total dokumen di portal (Akan diambil: ${totalRecords})`);

    if (totalRecords === 0) continue;

    const totalPages = Math.ceil(totalRecords / batchSize);
    let npwpInserted = 0;

    for (let page = 1; page <= totalPages; page++) {
      if (stopRequested) break;

      document.getElementById('statusText').innerText = `NPWP ${npwp} | Memproses Halaman ${page} dari ${totalPages}...`;

      try {
        const res = await fetch(`browse_handler.php?cmp_id=${encodeURIComponent(cmpId)}&npwp=${encodeURIComponent(npwp)}&bc=${encodeURIComponent(bcFilter)}&page=${page}&size=${batchSize}`)
          .then(r => r.json());

        const insertedCount = res.inserted || 0;
        npwpInserted += insertedCount;
        grandTotalInserted += insertedCount;

        const pct = Math.floor((page / totalPages) * 100);
        document.getElementById('progressBar').style.width = pct + '%';
        document.getElementById('progressBar').textContent = pct + '%';
        document.getElementById('progressPercentage').textContent = pct + '%';

        appendLog(`  ✓ Hal ${page}/${totalPages}: +${insertedCount} baris tersimpan`);
      } catch (err) {
        appendLog(`  ✗ Hal ${page} Error: ${err.message}`);
      }

      // Jeda Anti-WAF 800ms
      await new Promise(r => setTimeout(r, 800));
    }

    appendLog(`✅ Selesai NPWP ${npwp}. Data tersimpan: ${npwpInserted}\n`);
  }

  isRunning = false;
  $('#btnStop').hide();
  $('#btnStart').show();
  $('#syncStatusBadge').removeClass('badge-warning').addClass('badge-success').text('Selesai');
  document.getElementById('statusText').innerHTML = `<strong class="text-success">🎉 Selesai! Total ${grandTotalInserted} baris dokumen berhasil disinkronkan ke database ceisa0400db.</strong>`;
  appendLog(`🎉 Selesai Sinkronisasi! Total ${grandTotalInserted} dokumen tersimpan.`);
}
</script>
</body>
</html>
